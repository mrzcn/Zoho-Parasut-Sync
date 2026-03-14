<?php
/**
 * PHPUnit Test Bootstrap
 * Sets up the testing environment without connecting to a real database.
 */

// Define test mode
define('TESTING', true);
define('APP_VERSION', '2.6-test');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Composer autoloader
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Fallback autoloader for classes
spl_autoload_register(function ($class) {
    $dirs = [
        __DIR__ . '/../controllers/',
        __DIR__ . '/../classes/',
        __DIR__ . '/../config/',
    ];
    foreach ($dirs as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Load helper functions needed for tests
$helpers = [
    __DIR__ . '/../config/helpers/turkish.php',
    __DIR__ . '/../config/helpers/security.php',
];

foreach ($helpers as $helper) {
    if (file_exists($helper)) {
        require_once $helper;
    }
}
