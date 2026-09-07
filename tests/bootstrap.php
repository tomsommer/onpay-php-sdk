<?php

require __DIR__ . '/../vendor/autoload.php';

// Keep the SDK's error_log() fallback out of the test runner's output.
ini_set('error_log', sys_get_temp_dir() . '/onpay-php-sdk-tests.log');
