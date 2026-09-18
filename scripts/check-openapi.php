<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

const SNAPSHOT_SHA256 = 'c5d5ae1d85e61b4e14e09351b14146465ce357075d2ed5fe4e034f6ff6693dc1';

$autoload = dirname(__DIR__).'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "Missing Composer dependencies; run composer install before checking OpenAPI.\n");
    exit(1);
}
require $autoload;

function parseOpenApi(string $path): object
{
    try {
        $document = Yaml::parseFile($path, Yaml::PARSE_OBJECT_FOR_MAP);
    } catch (ParseException $exception) {
        fwrite(STDERR, "Invalid OpenAPI YAML in {$path}: {$exception->getMessage()}\n");
        exit(1);
    }

    if (! is_object($document)) {
        fwrite(STDERR, "OpenAPI document must be a YAML mapping: {$path}\n");
        exit(1);
    }

    return $document;
}

function normalizeOpenApi(mixed $value): mixed
{
    if (is_object($value)) {
        $mapping = get_object_vars($value);
        ksort($mapping, SORT_STRING);
        foreach ($mapping as $key => $item) {
            $mapping[$key] = normalizeOpenApi($item);
        }

        return ['__yaml_mapping__' => $mapping];
    }

    if (! is_array($value)) {
        return $value;
    }

    return ['__yaml_sequence__' => array_map(normalizeOpenApi(...), $value)];
}

$snapshot = dirname(__DIR__).'/openapi.yaml';
if (! is_file($snapshot)) {
    fwrite(STDERR, "Missing OpenAPI snapshot: {$snapshot}\n");
    exit(1);
}

$actual = hash_file('sha256', $snapshot);
if ($actual !== SNAPSHOT_SHA256) {
    fwrite(STDERR, 'OpenAPI snapshot checksum mismatch: expected '.SNAPSHOT_SHA256.", got {$actual}\n");
    exit(1);
}

$snapshotDocument = normalizeOpenApi(parseOpenApi($snapshot));
$source = $argv[1] ?? null;
if ($source !== null) {
    if (! is_file($source)) {
        fwrite(STDERR, "OpenAPI comparison source does not exist: {$source}\n");
        exit(1);
    }

    $sourceDocument = normalizeOpenApi(parseOpenApi($source));
    if ($sourceDocument !== $snapshotDocument) {
        fwrite(STDERR, "OpenAPI semantic contract drift detected between snapshot and {$source}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "OpenAPI snapshot verified: {$actual}\n");
