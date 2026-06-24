<?php
declare(strict_types=1);

function default_signalops_cache_path(string $projectRoot): string
{
    $configured = getenv('SIGNALOPS_CACHE_PATH');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }

    if (DIRECTORY_SEPARATOR === '/' && is_dir('/dev/shm') && is_writable('/dev/shm')) {
        return '/dev/shm/signalops-status/status-cache.json';
    }

    return $projectRoot . '/var/cache/status-cache.json';
}

function default_signalops_config(): array
{
    $projectRoot = dirname(__DIR__);

    return [
        'app' => [
            'name' => 'SignalOps Status',
            'subtitle' => 'PHP Based status dashboard for Discord bots, trading services, and private server fleets.',
            'timezone' => getenv('SIGNALOPS_STATUS_TZ') ?: 'America/Toronto',
            'demo' => filter_var(getenv('SIGNALOPS_DEMO') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'theme' => [
                'accent' => '#20b981',
                'repository_url' => 'https://github.com/tuolaji996/SignalOps-Status',
                'owner_name' => 'SignalOps',
                'owner_url' => '',
            ],
        ],
        'cache' => [
            'enabled' => true,
            'seconds' => 15,
            'stale_seconds' => 300,
            'serve_expired_seconds' => 604800,
            'refresh_lock_seconds' => 20,
            'browser_max_age' => 5,
            'stale_while_revalidate' => 60,
            'php_cli' => getenv('SIGNALOPS_PHP_CLI') ?: '',
            'refresh_lock_path' => getenv('SIGNALOPS_CACHE_LOCK_PATH') ?: '',
            'cdn' => [
                'enabled' => getenv('SIGNALOPS_CDN_ENABLED') === '1',
                'edge_max_age' => 60,
                'stale_while_revalidate' => 300,
                'stale_if_error' => 604800,
            ],
            'path' => default_signalops_cache_path($projectRoot),
        ],
        'sla' => [
            'enabled' => true,
            'path' => getenv('SIGNALOPS_SLA_STATE_PATH') ?: $projectRoot . '/var/state/ping-sla.json',
            'ping_timeout_seconds' => 1,
        ],
        'traffic' => [
            'enabled' => true,
            'path' => getenv('SIGNALOPS_TRAFFIC_STATE_PATH') ?: $projectRoot . '/var/state/network-usage.json',
        ],
        'database' => [
            'enabled' => false,
            'label' => 'Database Server',
            'dsn' => getenv('SIGNALOPS_DB_DSN') ?: '',
            'user' => getenv('SIGNALOPS_DB_USER') ?: '',
            'password' => getenv('SIGNALOPS_DB_PASSWORD') ?: '',
            'summary_sql' => '',
            'recent_sql' => '',
        ],
        'endpoints' => [
            'discord' => [
                'label' => 'Discord Bot',
                'url' => getenv('SIGNALOPS_DISCORD_HEALTH_URL') ?: '',
                'kind' => 'discord',
            ],
            'api' => [
                'label' => 'Trading API',
                'url' => getenv('SIGNALOPS_API_HEALTH_URL') ?: '',
                'kind' => 'api',
            ],
        ],
        'machines' => [
            [
                'key' => 'web',
                'label' => 'Web Server',
                'role' => 'Status frontend',
                'location' => 'Los Angeles, CA',
                'kind' => 'local',
                'sla_host' => '127.0.0.1',
                'latency_targets' => [],
                'disks' => ['/'],
                'services' => [],
            ],
        ],
        'latency_map' => [
            'enabled' => true,
            'nodes' => [
                'web' => ['label' => 'Web Server', 'location' => 'Los Angeles, CA', 'x' => 15, 'y' => 65],
            ],
            'links' => [],
        ],
    ];
}

function load_signalops_config(string $projectRoot): array
{
    $config = default_signalops_config();
    $candidates = [
        getenv('SIGNALOPS_CONFIG') ?: '',
        '/etc/signalops-status/config.php',
        $projectRoot . '/config/signalops.php',
    ];

    foreach ($candidates as $path) {
        if ($path !== '' && is_readable($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = merge_config($config, $loaded);
            }
        }
    }

    if (!empty($config['app']['timezone'])) {
        date_default_timezone_set((string)$config['app']['timezone']);
    }

    $refresh = (int)($config['refresh_seconds'] ?? 30);
    $config['refresh_seconds'] = max(10, min(300, $refresh ?: 30));

    return $config;
}
