<?php
declare(strict_types=1);

date_default_timezone_set(getenv('SIGNALOPS_STATUS_TZ') ?: 'America/Toronto');

require __DIR__ . '/helpers.php';
require __DIR__ . '/config.php';
require __DIR__ . '/demo.php';
require __DIR__ . '/collectors.php';
