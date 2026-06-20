<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);

require $projectRoot . '/app/bootstrap.php';

$config = load_signalops_config($projectRoot);
$status = collect_status($config);
$cacheMeta = is_array($status['_cache'] ?? null) ? $status['_cache'] : [];
$cacheConfig = is_array($config['cache'] ?? null) ? $config['cache'] : [];
$browserMaxAge = max(0, min(60, (int)($cacheConfig['browser_max_age'] ?? 5)));
$staleWhileRevalidate = max(60, min(3600, (int)($cacheConfig['stale_while_revalidate'] ?? 60)));
$cdnConfig = is_array($cacheConfig['cdn'] ?? null) ? $cacheConfig['cdn'] : [];
$cdnEnabled = (bool)($cdnConfig['enabled'] ?? false);
$cdnEdgeMaxAge = max(0, min(3600, (int)($cdnConfig['edge_max_age'] ?? 60)));
$cdnStaleWhileRevalidate = max(0, min(86400, (int)($cdnConfig['stale_while_revalidate'] ?? $staleWhileRevalidate)));
$cdnStaleIfError = max(0, min(604800, (int)($cdnConfig['stale_if_error'] ?? 604800)));
$serverTimingState = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($cacheMeta['state'] ?? 'fresh')) ?: 'fresh';

if ($cdnEnabled) {
    $cdnDirectives = 'public, max-age=' . $cdnEdgeMaxAge
        . ', stale-while-revalidate=' . $cdnStaleWhileRevalidate
        . ', stale-if-error=' . $cdnStaleIfError;
    header('Cache-Control: public, max-age=' . $browserMaxAge . ', s-maxage=' . $cdnEdgeMaxAge . ', stale-while-revalidate=' . $staleWhileRevalidate . ', stale-if-error=' . $cdnStaleIfError);
    header('CDN-Cache-Control: ' . $cdnDirectives);
    header('Cloudflare-CDN-Cache-Control: ' . $cdnDirectives);
} else {
    header('Cache-Control: private, max-age=' . $browserMaxAge . ', stale-while-revalidate=' . $staleWhileRevalidate);
}
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
