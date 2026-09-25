<?php

declare(strict_types=1);

namespace ViaPost\Laravel\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SupportPolicyTest extends TestCase
{
    public function test_only_patched_laravel_12_and_13_releases_are_supported(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($composer) || ! isset($composer['require']) || ! is_array($composer['require'])) {
            self::fail('composer.json must define package requirements.');
        }

        $requirements = $composer['require'];

        foreach (['illuminate/contracts', 'illuminate/http', 'illuminate/support'] as $package) {
            self::assertArrayHasKey($package, $requirements);
            self::assertSame('^12.61.1 || ^13.12.0', $requirements[$package]);
        }
    }

    public function test_ci_audits_every_supported_laravel_lane_as_a_gate(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

        self::assertStringNotContainsString('laravel: "11"', $workflow);
        self::assertStringNotContainsString('--no-blocking', $workflow);
        self::assertStringNotContainsString('continue-on-error', $workflow);
        self::assertSame(1, substr_count($workflow, 'composer audit --locked'));
        self::assertStringNotContainsString('if: matrix.laravel', $workflow);
    }
}
