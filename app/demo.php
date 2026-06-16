<?php
declare(strict_types=1);

function demo_status(array $config): array
{
    $now = time();

    return [
        '_cache' => ['state' => 'demo'],
        'database' => [
            'ok' => true,
            'summary' => [
                'event_count' => 128476,
                'latest_event_at' => gmdate('Y-m-d H:i:s', $now - 62),
                'latest_insert_at' => gmdate('Y-m-d H:i:s', $now - 58),
                'duplicate_seen_total' => 1843,
                'symbol_count' => 932,
            ],
            'kinds' => [
                ['kind' => 'options', 'count' => 71240],
                ['kind' => 'equity', 'count' => 36518],
                ['kind' => 'futures', 'count' => 14201],
                ['kind' => 'alerts', 'count' => 6517],
            ],
            'recent' => [
                ['seen_at' => gmdate('Y-m-d H:i:s', $now - 62), 'kind' => 'options', 'symbol' => 'NVDA', 'text' => 'Large call sweep detected above current ask.'],
                ['seen_at' => gmdate('Y-m-d H:i:s', $now - 148), 'kind' => 'equity', 'symbol' => 'SPY', 'text' => 'High-volume tape print routed to Discord.'],
                ['seen_at' => gmdate('Y-m-d H:i:s', $now - 311), 'kind' => 'alerts', 'symbol' => 'TSLA', 'text' => 'Momentum scanner alert acknowledged by bot.'],
            ],
            'error' => null,
        ],
        'endpoints' => [
            'discord' => [
                'ok' => true,
                'http_code' => 200,
                'data' => [
                    'ok' => true,
                    'service' => 'discord-signal-bot',
                    'seen_hashes' => 2000,
                    'poll_count' => 1936,
                    'publish_count' => 14382,
                    'last_poll_ok_at' => gmdate('c', $now - 42),
                    'last_event_at' => gmdate('c', $now - 62),
                    'last_api_capture_at' => gmdate('c', $now - 45),
                ],
                'error' => null,
            ],
            'api' => [
                'ok' => true,
                'http_code' => 200,
                'data' => [
                    'ok' => true,
                    'capture_count' => 68421,
                    'change_count' => 4589,
                    'last_capture_at' => gmdate('c', $now - 44),
                ],
                'error' => null,
            ],
        ],
        'machines' => demo_machines($now),
        'latency_map' => demo_latency_map(),
    ];
}

function demo_machines(int $now): array
{
    $base = [
        ['web', 'Web Server', 'Public dashboard', 'Los Angeles, CA', 7.5, 41.8, 0.0, 2147483648, 110, ['nginx' => 'active']],
        ['db', 'Database Server', 'Long-term archive', 'Chicago, IL', 11.9, 33.2, 0.2, 8589934592, 38, ['mysql' => 'active']],
        ['discord', 'Discord Bot Server', 'Bot runtime', 'Toronto, ON', 4.3, 49.5, 0.0, 2147483648, 64, ['discord-bot.service' => 'active']],
        ['api', 'Trading API Server', 'Market data capture', 'Toronto, ON', 29.2, 58.7, 0.1, 4294967296, 66, ['trading-api.service' => 'active']],
        ['heatmap', 'Heatmap Server', 'Options analytics', 'Toronto, ON', 24.4, 37.0, 0.0, 8589934592, 3, ['heatmap-worker.service' => 'active']],
        ['app', 'Application Server', 'Member portal', 'Montreal, QC', 8.7, 44.2, 0.0, 8589934592, 18, ['nginx' => 'active']],
    ];

    $latencies = [
        'web' => ['db' => 38, 'discord' => 72, 'api' => 74, 'heatmap' => 73, 'app' => 78],
        'db' => ['web' => 39, 'discord' => 12, 'api' => 13, 'heatmap' => 12, 'app' => 18],
        'discord' => ['web' => 71, 'db' => 12, 'api' => 2.2, 'heatmap' => 1.4, 'app' => 8],
        'api' => ['web' => 74, 'db' => 13, 'discord' => 2.1, 'heatmap' => 2.0, 'app' => 8.6],
        'heatmap' => ['web' => 73, 'db' => 12, 'discord' => 1.4, 'api' => 2.0, 'app' => 8.1],
        'app' => ['web' => 78, 'db' => 18, 'discord' => 8, 'api' => 8.5, 'heatmap' => 8.2],
    ];

    $machines = [];
    foreach ($base as $index => $row) {
        [$key, $label, $role, $location, $cpu, $memory, $iowait, $totalMem, $trafficMb, $services] = $row;
        $machineLatencies = [];
        foreach (($latencies[$key] ?? []) as $target => $ms) {
            $machineLatencies[$target] = ['ok' => true, 'latency_ms' => $ms, 'error' => null];
        }

        $machines[] = [
            'key' => $key,
            'label' => $label,
            'role' => $role,
            'location' => $location,
            'probe' => [
                'ok' => true,
                'cpu_pct' => $cpu,
                'iowait_pct' => $iowait,
                'memory' => ['total' => $totalMem, 'available' => $totalMem * (1 - $memory / 100), 'used_pct' => $memory],
                'cpu' => ['model' => $index % 2 === 0 ? 'AMD EPYC virtual CPU' : 'Intel Xeon virtual CPU', 'cores' => $index % 2 === 0 ? 2 : 4],
                'network' => ['iface' => 'private0', 'rx_bytes' => $trafficMb * 1024 * 1024, 'tx_bytes' => ($trafficMb + 18) * 1024 * 1024, 'total_bytes' => ($trafficMb * 2 + 18) * 1024 * 1024],
                'traffic' => ['today_bytes' => $trafficMb * 1024 * 1024, 'month_bytes' => $trafficMb * 1024 * 1024 * 18, 'avg_daily_bytes' => $trafficMb * 1024 * 1024, 'tracked_days' => 18],
                'latencies' => $machineLatencies,
                'disks' => [['path' => '/', 'total' => 64 * 1024 ** 3, 'free' => (64 - (12 + $index * 3)) * 1024 ** 3, 'used_pct' => 18.7 + $index * 4.1]],
                'services' => $services,
                'journal' => $key === 'discord'
                    ? ['ok' => true, 'window' => '24h', 'warning_count' => 0, 'error_count' => 0, 'latest_at' => null, 'latest' => []]
                    : ['ok' => null, 'window' => '24h', 'warning_count' => 0, 'error_count' => 0, 'latest_at' => null, 'latest' => []],
                'load' => [0.08 + $index / 10, 0.12 + $index / 12, 0.10 + $index / 15],
                'uptime_seconds' => 86400 * (8 + $index * 3) + 3600 * 5,
                'error' => null,
            ],
            'sla' => [
                'last_ok' => true,
                'last_latency_ms' => $key === 'web' ? 0.2 : (float)(20 + $index * 8),
                'today_pct' => 99.98 - $index * 0.06,
                'month_pct' => 99.94 - $index * 0.04,
                'checks_today' => 338,
                'error' => null,
            ],
        ];
    }

    return $machines;
}

function demo_latency_map(): array
{
    return [
        'enabled' => true,
        'nodes' => [
            'web' => ['label' => 'Web Server', 'location' => 'Los Angeles, CA', 'x' => 15, 'y' => 65],
            'db' => ['label' => 'Database Server', 'location' => 'Chicago, IL', 'x' => 58, 'y' => 45],
            'discord' => ['label' => 'Discord Bot Server', 'location' => 'Toronto, ON', 'x' => 80, 'y' => 47],
            'api' => ['label' => 'Trading API Server', 'location' => 'Toronto, ON', 'x' => 75, 'y' => 27],
            'heatmap' => ['label' => 'Heatmap Server', 'location' => 'Toronto, ON', 'x' => 84, 'y' => 50],
            'app' => ['label' => 'Application Server', 'location' => 'Montreal, QC', 'x' => 88, 'y' => 43],
        ],
        'links' => [
            ['from' => 'web', 'to' => 'db', 'label' => 'Web to Database', 'latency_ms' => 38, 'tone' => 'ok'],
            ['from' => 'web', 'to' => 'discord', 'label' => 'Web to Discord', 'latency_ms' => 72, 'tone' => 'warn'],
            ['from' => 'web', 'to' => 'api', 'label' => 'Web to Trading API', 'latency_ms' => 74, 'tone' => 'warn'],
            ['from' => 'web', 'to' => 'heatmap', 'label' => 'Web to Heatmap', 'latency_ms' => 73, 'tone' => 'warn'],
            ['from' => 'web', 'to' => 'app', 'label' => 'Web to Application', 'latency_ms' => 78, 'tone' => 'warn'],
            ['from' => 'db', 'to' => 'discord', 'label' => 'Database to Discord', 'latency_ms' => 12, 'tone' => 'ok'],
            ['from' => 'discord', 'to' => 'api', 'label' => 'Discord to Trading API', 'latency_ms' => 2.2, 'tone' => 'ok'],
            ['from' => 'discord', 'to' => 'heatmap', 'label' => 'Discord to Heatmap', 'latency_ms' => 1.4, 'tone' => 'ok'],
            ['from' => 'api', 'to' => 'heatmap', 'label' => 'Trading API to Heatmap', 'latency_ms' => 2.0, 'tone' => 'ok'],
            ['from' => 'app', 'to' => 'discord', 'label' => 'Application to Discord', 'latency_ms' => 8.0, 'tone' => 'ok'],
        ],
    ];
}
