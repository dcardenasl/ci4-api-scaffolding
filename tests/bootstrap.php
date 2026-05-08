<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the package's own test suite.
 *
 * The generators write to paths built off the CI4 framework constants
 * `APPPATH` and `ROOTPATH`. When tests exercise generators in isolation
 * (no CI4 host bootstrapped), we shim these constants to a temp scratch
 * directory so the generators produce inspectable paths and tests can
 * assert against them without writing real files.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('APPPATH')) {
    define('APPPATH', sys_get_temp_dir() . '/ci4-scaffolding-test-app/');
}

if (!defined('ROOTPATH')) {
    define('ROOTPATH', sys_get_temp_dir() . '/ci4-scaffolding-test-root/');
}
