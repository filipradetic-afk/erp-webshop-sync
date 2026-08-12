<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/TestCase.php';

/**
 * Zero-dependency test runner. Discovers every *Test.php in this directory,
 * instantiates the class, and runs every public method starting with "test".
 *
 * Run:
 *   php tests/run.php
 */

$files = glob(__DIR__ . '/*Test.php');
sort($files);

$total = 0;
$failed = 0;

foreach ($files as $file) {
    require_once $file;
    $class = 'ErpSync\\Tests\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }

    $instance = new $class();
    $methods = array_values(array_filter(get_class_methods($instance), static fn ($m) => str_starts_with($m, 'test')));
    sort($methods);

    foreach ($methods as $method) {
        $total++;
        $label = "{$class}::{$method}";
        try {
            $instance->{$method}();
            echo "  [PASS] {$label}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "  [FAIL] {$label}\n        " . $e->getMessage() . "\n";
        }
    }
}

echo str_repeat('-', 60) . "\n";
echo ($total - $failed) . "/{$total} tests passed\n";

exit($failed > 0 ? 1 : 0);
