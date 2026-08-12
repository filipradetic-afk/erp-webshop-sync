<?php

declare(strict_types=1);

/**
 * Zero-dependency bootstrap: no Composer, no vendor/ directory. A single
 * PSR-4-style autoloader maps the ErpSync\ namespace onto src/, which is
 * enough for a project this size and keeps "clone it and run it" true.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'ErpSync\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "The pdo_sqlite PHP extension is required but not loaded.\n");
    fwrite(STDERR, "It ships with PHP and is enabled by default on most Linux/macOS installs.\n");
    fwrite(STDERR, "On Windows (XAMPP/Laragon/etc.), enable it in php.ini:\n");
    fwrite(STDERR, "    extension=pdo_sqlite\n");
    fwrite(STDERR, "or load it for a single run without touching php.ini:\n");
    fwrite(STDERR, "    php -d extension=pdo_sqlite bin/sync.php ...\n");
    exit(1);
}
