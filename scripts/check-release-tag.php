<?php

declare(strict_types=1);

$tag = $argv[1] ?? '';
$client = (string) file_get_contents(dirname(__DIR__).'/src/Client.php');
if (preg_match("/public const VERSION = '([^']+)';/", $client, $matches) !== 1) {
    fwrite(STDERR, "Unable to read Client::VERSION.\n");
    exit(1);
}

$expected = 'v'.$matches[1];
if ($tag !== $expected) {
    fwrite(STDERR, "Release tag {$tag} does not match SDK version {$expected}.\n");
    exit(1);
}

fwrite(STDOUT, "Release tag verified: {$tag}\n");
