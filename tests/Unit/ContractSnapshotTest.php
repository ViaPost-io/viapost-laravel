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
            'd1f223342ad1ca326ba716af6e508c78594e1b108958cce2ec4a1efd31a9773a',
            hash_file('sha256', $snapshot),
        );
    }
}
