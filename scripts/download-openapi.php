<?php

declare(strict_types=1);

use ViaPost\Laravel\Support\OpenApiDownloader;

require_once dirname(__DIR__).'/vendor/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "usage: php scripts/download-openapi.php <https-url> <output-path>\n");
    exit(2);
}

try {
    $contract = OpenApiDownloader::download($argv[1]);
    if (file_put_contents($argv[2], $contract, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the downloaded OpenAPI contract.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
