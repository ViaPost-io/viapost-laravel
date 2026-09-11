<?php

declare(strict_types=1);

const SNAPSHOT_SHA256 = 'd1f223342ad1ca326ba716af6e508c78594e1b108958cce2ec4a1efd31a9773a';

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

$source = $argv[1] ?? null;
if ($source !== null) {
    if (! is_file($source)) {
        fwrite(STDERR, "OpenAPI comparison source does not exist: {$source}\n");
        exit(1);
    }

    $sourceHash = hash_file('sha256', $source);
    if ($sourceHash !== $actual) {
        fwrite(STDERR, "OpenAPI contract drift detected: snapshot {$actual}, source {$sourceHash}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OpenAPI snapshot verified: {$actual}\n");
