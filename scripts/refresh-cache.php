<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$projectRoot = dirname(__DIR__);

require $projectRoot . '/app/bootstrap.php';

$config = load_signalops_config($projectRoot);
$cache = $config['cache'] ?? [];
$path = (string)($cache['path'] ?? '');
$lockPath = $path !== '' ? status_cache_lock_path($cache, $path) : '';
$useExistingLock = in_array('--use-existing-lock', $argv, true);

refresh_status_cache($config, $lockPath, $useExistingLock);
