<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContractSnapshotTest extends TestCase
{
    public function test_the_openapi_snapshot_matches_the_frozen_contract(): void
    {
        $snapshot = dirname(__DIR__, 2).'/openapi.yaml';

        self::assertFileExists($snapshot);
        self::assertSame(
            'f1b1fc0f198a2b0b36f0e893515dad191d6bb7d139fcf1e942c036bfa2f5169b',
            hash_file('sha256', $snapshot),
        );
    }

    public function test_the_contract_checker_accepts_equivalent_yaml_serialization_and_rejects_semantic_drift(): void
    {
        $root = dirname(__DIR__, 2);
        $snapshot = $root.'/openapi.yaml';
        $equivalent = tempnam(sys_get_temp_dir(), 'viapost-openapi-equivalent-');
        $drifted = tempnam(sys_get_temp_dir(), 'viapost-openapi-drifted-');
        $shapeDrifted = tempnam(sys_get_temp_dir(), 'viapost-openapi-shape-drifted-');
        self::assertNotFalse($equivalent);
        self::assertNotFalse($drifted);
        self::assertNotFalse($shapeDrifted);

        try {
            $contract = file_get_contents($snapshot);
            self::assertIsString($contract);
            file_put_contents($equivalent, "# deliberately different serialization\n\n".$contract);
            file_put_contents($drifted, str_replace(
                'title: ViaPost Integration API',
                'title: Semantically different',
                $contract,
            ));
            $shapeDrift = preg_replace(
                '/bearerApiKey: \[\]/',
                'bearerApiKey: {}',
                $contract,
                1,
            );
            self::assertIsString($shapeDrift);
            file_put_contents($shapeDrifted, $shapeDrift);

            exec('php '.escapeshellarg($root.'/scripts/check-openapi.php').' '.escapeshellarg($equivalent).' 2>&1', $equivalentOutput, $equivalentExit);
            exec('php '.escapeshellarg($root.'/scripts/check-openapi.php').' '.escapeshellarg($drifted).' 2>&1', $driftedOutput, $driftedExit);
            exec('php '.escapeshellarg($root.'/scripts/check-openapi.php').' '.escapeshellarg($shapeDrifted).' 2>&1', $shapeDriftedOutput, $shapeDriftedExit);

            self::assertSame(0, $equivalentExit, implode("\n", $equivalentOutput));
            self::assertSame(1, $driftedExit, implode("\n", $driftedOutput));
            self::assertStringContainsString('semantic contract drift', implode("\n", $driftedOutput));
            self::assertSame(1, $shapeDriftedExit, implode("\n", $shapeDriftedOutput));
            self::assertStringContainsString('semantic contract drift', implode("\n", $shapeDriftedOutput));
        } finally {
            @unlink($equivalent);
            @unlink($drifted);
            @unlink($shapeDrifted);
        }
    }

    public function test_the_scheduled_check_uses_the_public_documentation_endpoint(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/contract-drift.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString('https://docs.viapost.io/openapi/public.yaml', $workflow);
        self::assertStringNotContainsString('raw.githubusercontent.com/ViaPost-io/base-code', $workflow);
        self::assertStringContainsString('php scripts/download-openapi.php', $workflow);
        self::assertStringNotContainsString('curl --', $workflow);
    }

    public function test_the_release_workflow_attests_draft_assets_before_publishing(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release.yml');
        self::assertIsString($workflow);

        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString('tags: ["v*"]', $workflow);
        self::assertStringContainsString('attest-build-provenance', $workflow);
        self::assertStringContainsString('create-draft-release', $workflow);
        self::assertStringContainsString('publish-github-release', $workflow);
        self::assertStringContainsString('source_sha: ${{ steps.source.outputs.sha }}', $workflow);
        self::assertStringContainsString('needs: [verify-and-archive, attest-build-provenance]', $workflow);
        self::assertStringContainsString('needs: [verify-and-archive, create-draft-release]', $workflow);
        self::assertStringContainsString('publish-release.sh prepare', $workflow);
        self::assertStringContainsString('publish-release.sh publish', $workflow);
        self::assertStringNotContainsString('--clobber', $workflow);

        $publisher = file_get_contents(dirname(__DIR__, 2).'/scripts/publish-release.sh');
        self::assertIsString($publisher);
        self::assertStringContainsString('remote_tag_sha=', $publisher);
        self::assertStringContainsString('cmp --silent', $publisher);
        self::assertStringNotContainsString('--clobber', $publisher);
    }
}
