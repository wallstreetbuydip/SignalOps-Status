<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/app/bootstrap.php';

$config = load_signalops_config($projectRoot);
$status = collect_status($config);
$cacheMeta = is_array($status['_cache'] ?? null) ? $status['_cache'] : [];
$browserMaxAge = max(0, min(30, (int)($config['cache']['browser_max_age'] ?? 5)));
$serverTimingState = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($cacheMeta['state'] ?? 'fresh')) ?: 'fresh';

header('Cache-Control: private, max-age=' . $browserMaxAge . ', stale-while-revalidate=60');
header('Server-Timing: signalops_cache;desc="' . $serverTimingState . '"');
header('X-SignalOps-Status-Cache: ' . $serverTimingState);

require $projectRoot . '/app/views/dashboard.php';

if (!empty($cacheMeta['refresh_after_response'])) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }

    ignore_user_abort(true);
    @set_time_limit(30);
    refresh_status_cache($config, (string)($cacheMeta['refresh_lock_path'] ?? ''));
}
