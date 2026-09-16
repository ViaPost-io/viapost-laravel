#!/usr/bin/env bash
set -euo pipefail

mode=${1:-}
release_tag=${2:-}
source_sha=${3:-}
shift $(( $# >= 3 ? 3 : $# ))

if [[ "$mode" != "prepare" && "$mode" != "publish" ]]; then
  echo "usage: publish-release.sh <prepare|publish> <tag> <source-sha> <artifacts...>" >&2
  exit 2
fi
if [[ ! "$release_tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-.][A-Za-z0-9.-]+)?$ ]]; then
  echo "release tag must be a v-prefixed semantic version" >&2
  exit 2
fi
if [[ ! "$source_sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo "source SHA must be a full lowercase commit SHA" >&2
  exit 2
fi
if [[ $# -eq 0 ]]; then
  echo "at least one release artifact is required" >&2
  exit 2
fi
: "${GH_REPO:?GH_REPO is required}"
: "${GH_TOKEN:?GH_TOKEN is required}"

declare -A expected=()
for artifact in "$@"; do
  [[ -f "$artifact" ]] || { echo "artifact not found: $artifact" >&2; exit 1; }
  name=$(basename "$artifact")
  [[ "$name" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "unsafe artifact name: $name" >&2; exit 1; }
  [[ -z "${expected[$name]+x}" ]] || { echo "duplicate artifact name: $name" >&2; exit 1; }
  expected[$name]=$artifact
done

remote_tag_sha=$(gh api "repos/${GH_REPO}/commits/${release_tag}" --jq .sha)
if [[ "$remote_tag_sha" != "$source_sha" ]]; then
  echo "release tag does not resolve to the verified source SHA" >&2
  exit 1
fi

release_json=''
if release_json=$(gh release view "$release_tag" --json tagName,isDraft,isPrerelease 2>/dev/null); then
  [[ $(jq -r .tagName <<<"$release_json") == "$release_tag" ]] || { echo "release tag mismatch" >&2; exit 1; }
  [[ $(jq -r .isPrerelease <<<"$release_json") == false ]] || { echo "prerelease cannot be resumed" >&2; exit 1; }
elif [[ "$mode" == prepare ]]; then
  gh release create "$release_tag" --draft --verify-tag --target "$source_sha" --title "$release_tag" --generate-notes
  release_json=$(gh release view "$release_tag" --json tagName,isDraft,isPrerelease)
else
  echo "draft release does not exist" >&2
  exit 1
fi

is_draft=$(jq -r .isDraft <<<"$release_json")
mapfile -t remote_assets < <(gh release view "$release_tag" --json assets --jq '.assets[].name')
for name in "${remote_assets[@]}"; do
  [[ -n "${expected[$name]+x}" ]] || { echo "unexpected release asset: $name" >&2; exit 1; }
done

download_dir=$(mktemp -d)
trap 'rm -rf "$download_dir"' EXIT
for name in "${!expected[@]}"; do
  found=false
  for remote_name in "${remote_assets[@]}"; do
    if [[ "$remote_name" == "$name" ]]; then found=true; break; fi
  done
  if [[ "$found" == true ]]; then
    gh release download "$release_tag" --dir "$download_dir" --pattern "$name"
    cmp --silent "${expected[$name]}" "$download_dir/$name" || { echo "existing release asset differs: $name" >&2; exit 1; }
  elif [[ "$mode" == prepare && "$is_draft" == true ]]; then
    gh release upload "$release_tag" "${expected[$name]}"
  else
    echo "required release asset is missing: $name" >&2
    exit 1
  fi
done

mapfile -t final_assets < <(gh release view "$release_tag" --json assets --jq '.assets[].name')
if [[ ${#final_assets[@]} -ne ${#expected[@]} ]]; then
  echo "release asset set is incomplete" >&2
  exit 1
fi

if [[ "$mode" == publish && "$is_draft" == true ]]; then
  gh release edit "$release_tag" --draft=false
fi
if [[ "$mode" == publish && $(gh release view "$release_tag" --json isDraft --jq .isDraft) != false ]]; then
  echo "release was not published" >&2
  exit 1
fi
