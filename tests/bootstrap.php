<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
require BASE_PATH . '/vendor/autoload.php';

if (!function_exists('appUrl')) {
    function appUrl(): string { return 'https://crm.test'; }
}
