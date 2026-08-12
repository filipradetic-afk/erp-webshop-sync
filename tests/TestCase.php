<?php

declare(strict_types=1);

namespace ErpSync\Tests;

/**
 * Minimal, dependency-free test-case base class. No PHPUnit required: every
 * *Test.php under tests/ is discovered and run by run.php. A failing
 * assertion throws, so one failing test method is reported without stopping
 * the rest of the suite.
 */
abstract class TestCase
{
    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message !== '' ? $message : sprintf(
                'Failed asserting that %s is identical to %s.',
                var_export($actual, true),
                var_export($expected, true)
            ));
        }
    }

    public function assertTrue(mixed $condition, string $message = ''): void
    {
        $this->assertSame(true, $condition, $message ?: 'Failed asserting that condition is true.');
    }

    public function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message ?: 'Failed asserting that value is null.');
    }

    public function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new \RuntimeException($message ?: 'Failed asserting that value is not null.');
        }
    }

    public function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $count = count($actual);
        $this->assertSame($expected, $count, $message ?: "Failed asserting that count matches: expected {$expected}, got {$count}.");
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException($message ?: "Failed asserting that '{$haystack}' contains '{$needle}'.");
        }
    }
}
