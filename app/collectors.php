<?php
declare(strict_types=1);

function collect_status(array $config): array
{
    if (!empty($config['app']['demo'])) {
        return demo_status($config);
    }

    $cache = $config['cache'] ?? [];
    if (!empty($cache['enabled'])) {
        return collect_status_cached($config);
    }

    return collect_status_uncached($config);
}

function collect_status_cached(array $config): array
{
    $cache = $config['cache'] ?? [];
    $path = (string)($cache['path'] ?? '');
    $freshFor = max(1, (int)($cache['seconds'] ?? 15));
    $staleFor = max($freshFor, (int)($cache['stale_seconds'] ?? 300));
    $lockFor = max(1, (int)($cache['refresh_lock_seconds'] ?? 20));
    if ($path === '') {
        return collect_status_uncached($config);
    }

    $cached = read_json_file($path);
    $age = isset($cached['_meta']['created_at']) ? time() - (int)$cached['_meta']['created_at'] : null;
    if (is_array($cached) && $age !== null && $age <= $freshFor) {
        $cached['_cache'] = ['state' => 'fresh'];
        return $cached;
    }

    $lockPath = $path . '.lock';
    if (acquire_refresh_lock($lockPath, $lockFor)) {
        $status = collect_status_uncached($config);
        $status['_meta'] = ['created_at' => time()];
        write_json_file($path, $status);
        $status['_cache'] = ['state' => 'refreshed'];
        return $status;
    }

    if (is_array($cached) && $age !== null && $age <= $staleFor) {
        $cached['_cache'] = ['state' => 'stale', 'refresh_after_response' => true, 'refresh_lock_path' => $lockPath];
        return $cached;
    }

    return collect_status_uncached($config) + ['_cache' => ['state' => 'uncached']];
}

function refresh_status_cache(array $config, string $lockPath): void
{
    if ($lockPath !== '' && !acquire_refresh_lock($lockPath, (int)($config['cache']['refresh_lock_seconds'] ?? 20))) {
        return;
    }
    $status = collect_status_uncached($config);
    $status['_meta'] = ['created_at' => time()];
    write_json_file((string)($config['cache']['path'] ?? ''), $status);
}

function collect_status_uncached(array $config): array
{
    $endpoints = [];
    foreach (($config['endpoints'] ?? []) as $key => $endpoint) {
        $endpoints[$key] = fetch_json_endpoint((string)($endpoint['url'] ?? ''));
    }

    $machines = [];
    foreach (($config['machines'] ?? []) as $machine) {
        $kind = (string)($machine['kind'] ?? 'ssh');
        if ($kind === 'local') {
            $probe = local_probe($machine);
        } elseif ($kind === 'endpoint') {
            $probe = endpoint_machine_probe($endpoints[$machine['endpoint_key'] ?? ''] ?? null);
        } else {
            $probe = ssh_probe($machine);
        }
        $machines[] = [
            'key' => $machine['key'] ?? '',
            'label' => $machine['label'] ?? ($machine['key'] ?? 'Server'),
            'role' => $machine['role'] ?? '',
            'location' => $machine['location'] ?? '',
            'probe' => $probe,
        ];
    }

    if (!empty($config['sla']['enabled'])) {
        $machines = apply_ping_sla($machines, $config['machines'] ?? [], (string)($config['sla']['path'] ?? ''), (int)($config['sla']['ping_timeout_seconds'] ?? 1));
    }
    if (!empty($config['traffic']['enabled'])) {
        $machines = apply_traffic_usage($machines, (string)($config['traffic']['path'] ?? ''));
    }

    return [
        '_cache' => ['state' => 'uncached'],
        'database' => collect_database($config['database'] ?? []),
        'endpoints' => $endpoints,
        'machines' => $machines,
        'latency_map' => build_latency_map($config['latency_map'] ?? [], $machines),
    ];
}

function read_json_file(string $path): ?array
{
    if ($path === '' || !is_readable($path)) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function write_json_file(string $path, array $data): void
{
    if ($path === '') {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function acquire_refresh_lock(string $path, int $ttl): bool
{
    if ($path === '') {
        return true;
    }
    if (is_file($path) && time() - (int)@filemtime($path) < $ttl) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return @file_put_contents($path, (string)time(), LOCK_EX) !== false;
}

function collect_database(array $db): array
{
    if (empty($db['enabled'])) {
        return ['ok' => null, 'summary' => [], 'kinds' => [], 'recent' => [], 'error' => null];
    }
    if (empty($db['dsn']) || empty($db['user'])) {
        return ['ok' => null, 'summary' => [], 'kinds' => [], 'recent' => [], 'error' => 'Database credentials not configured'];
    }

    try {
        $pdo = new PDO((string)$db['dsn'], (string)$db['user'], (string)($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 4,
        ]);
        $summary = [];
        $recent = [];
        if (!empty($db['summary_sql'])) {
            $summary = $pdo->query((string)$db['summary_sql'])->fetch() ?: [];
        }
        if (!empty($db['recent_sql'])) {
            $recent = $pdo->query((string)$db['recent_sql'])->fetchAll() ?: [];
        }
        return ['ok' => true, 'summary' => $summary, 'kinds' => [], 'recent' => $recent, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'summary' => [], 'kinds' => [], 'recent' => [], 'error' => redact($e->getMessage())];
    }
}

function empty_probe($ok, string $error = null): array
{
    return [
        'ok' => $ok,
        'cpu_pct' => null,
        'iowait_pct' => null,
        'memory' => ['total' => null, 'available' => null, 'used_pct' => null],
        'cpu' => ['model' => null, 'cores' => null],
        'network' => ['iface' => 'private0', 'rx_bytes' => null, 'tx_bytes' => null, 'total_bytes' => null],
        'traffic' => ['today_bytes' => null, 'month_bytes' => null, 'avg_daily_bytes' => null, 'tracked_days' => 0],
        'latencies' => [],
        'disks' => [],
        'services' => [],
        'journal' => ['ok' => null, 'window' => '24h', 'warning_count' => 0, 'error_count' => 0, 'latest_at' => null, 'latest' => []],
        'load' => [],
        'uptime_seconds' => null,
        'error' => $error,
    ];
}

function local_probe(array $machine): array
{
    $a = read_cpu_stat();
    usleep(180000);
    $b = read_cpu_stat();
    $cpu = null;
    $iowait = null;
    if ($a && $b && ($b['total'] - $a['total']) > 0) {
        $delta = $b['total'] - $a['total'];
        $cpu = max(0, min(100, 100 * (1 - (($b['idle'] - $a['idle']) / $delta))));
        $iowait = max(0, min(100, 100 * (($b['iowait'] - $a['iowait']) / $delta)));
    }

    $memory = ['total' => null, 'available' => null, 'used_pct' => null];
    $parsed = [];
    foreach ((@file('/proc/meminfo') ?: []) as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $match)) {
            $parsed[$match[1]] = (int)$match[2] * 1024;
        }
    }
    if (isset($parsed['MemTotal'], $parsed['MemAvailable']) && $parsed['MemTotal'] > 0) {
        $memory = [
            'total' => $parsed['MemTotal'],
            'available' => $parsed['MemAvailable'],
            'used_pct' => 100 * (1 - $parsed['MemAvailable'] / $parsed['MemTotal']),
        ];
    }

    $disks = [];
    foreach (($machine['disks'] ?? ['/']) as $path) {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $disks[] = [
            'path' => $path,
            'total' => $total === false ? null : $total,
            'free' => $free === false ? null : $free,
            'used_pct' => ($total && $free !== false) ? 100 * (1 - $free / $total) : null,
        ];
    }

    return [
        'ok' => true,
        'cpu_pct' => $cpu,
        'iowait_pct' => $iowait,
        'memory' => $memory,
        'cpu' => read_cpu_info(),
        'network' => read_network_counters((string)($machine['network_iface'] ?? 'tailscale0')),
        'latencies' => collect_probe_latencies($machine['latency_targets'] ?? []),
        'disks' => $disks,
        'services' => read_services($machine['services'] ?? []),
        'journal' => journal_summary($machine['journal_units'] ?? []),
        'load' => function_exists('sys_getloadavg') ? array_slice(sys_getloadavg() ?: [], 0, 3) : [],
        'uptime_seconds' => read_uptime_seconds(),
        'error' => null,
    ];
}

function read_cpu_stat(): ?array
{
    $lines = @file('/proc/stat');
    $line = $lines[0] ?? null;
    if (!$line || strpos($line, 'cpu ') !== 0) {
        return null;
    }
    $parts = preg_split('/\s+/', trim($line));
    $values = array_map('intval', array_slice($parts ?: [], 1));
    if (count($values) < 5) {
        return null;
    }
    return ['total' => array_sum($values), 'idle' => ($values[3] ?? 0) + ($values[4] ?? 0), 'iowait' => $values[4] ?? 0];
}

function read_cpu_info(): array
{
    $model = null;
    $cores = 0;
    foreach ((@file('/proc/cpuinfo') ?: []) as $line) {
        if (stripos($line, 'model name') === 0 && $model === null) {
            $model = trim(explode(':', $line, 2)[1] ?? '');
        }
        if (stripos($line, 'processor') === 0) {
            $cores++;
        }
    }
    return ['model' => $model ?: null, 'cores' => $cores ?: null];
}

function read_network_counters(string $iface = 'tailscale0'): array
{
    $safe = preg_replace('/[^A-Za-z0-9_.:-]/', '', $iface) ?: 'private0';
    $base = '/sys/class/net/' . $safe . '/statistics';
    $rx = @file_get_contents($base . '/rx_bytes');
    $tx = @file_get_contents($base . '/tx_bytes');
    $rxBytes = is_string($rx) && trim($rx) !== '' ? (int)trim($rx) : null;
    $txBytes = is_string($tx) && trim($tx) !== '' ? (int)trim($tx) : null;
    return ['iface' => $safe, 'rx_bytes' => $rxBytes, 'tx_bytes' => $txBytes, 'total_bytes' => ($rxBytes !== null && $txBytes !== null) ? $rxBytes + $txBytes : null];
}

function read_services(array $services): array
{
    $states = [];
    if (!function_exists('shell_exec')) {
        return $states;
    }
    foreach ($services as $service) {
        $safe = preg_replace('/[^A-Za-z0-9@_.-]/', '', (string)$service);
        if ($safe === '') {
            continue;
        }
        $state = trim((string)@shell_exec('systemctl is-active ' . escapeshellarg($safe) . ' 2>/dev/null'));
        $states[$safe] = $state ?: 'unknown';
    }
    return $states;
}

function read_uptime_seconds(): ?int
{
    $raw = @file_get_contents('/proc/uptime');
    return $raw === false ? null : (int)floor((float)explode(' ', trim($raw))[0]);
}

function collect_probe_latencies(array $targets, int $timeoutSeconds = 1): array
{
    $latencies = [];
    foreach ($targets as $key => $host) {
        $latencies[(string)$key] = ping_target((string)$host, $timeoutSeconds);
    }
    return $latencies;
}

function ping_target(string $host, int $timeoutSeconds = 1): array
{
    if ($host === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $host) !== 1 || !function_exists('shell_exec')) {
        return ['ok' => null, 'latency_ms' => null, 'error' => 'Ping target not configured'];
    }
    $cmd = 'ping -n -c 1 -W ' . (int)$timeoutSeconds . ' ' . escapeshellarg($host) . ' 2>&1; echo SIGNALOPS_EXIT:$?';
    $raw = (string)@shell_exec($cmd);
    $exitCode = null;
    if (preg_match('/SIGNALOPS_EXIT:(\d+)/', $raw, $match)) {
        $exitCode = (int)$match[1];
    }
    $latency = preg_match('/time[=<]\s*([0-9.]+)/', $raw, $match) ? (float)$match[1] : null;
    return ['ok' => $exitCode === 0, 'latency_ms' => $latency, 'error' => $exitCode === 0 ? null : 'Ping failed'];
}

function journal_summary(array $units): array
{
    if (!$units || !function_exists('shell_exec')) {
        return ['ok' => null, 'window' => '24h', 'warning_count' => 0, 'error_count' => 0, 'latest_at' => null, 'latest' => []];
    }

    $cmd = 'journalctl --since ' . escapeshellarg('24 hours ago') . ' -p warning..alert -n 160 -o json --no-pager --quiet';
    foreach ($units as $unit) {
        $safe = preg_replace('/[^A-Za-z0-9@_.-]/', '', (string)$unit);
        if ($safe !== '') {
            $cmd .= ' -u ' . escapeshellarg($safe);
        }
    }
    $raw = (string)@shell_exec($cmd . ' 2>/dev/null');
    $entries = [];
    foreach (explode("\n", $raw) as $line) {
        $item = json_decode($line, true);
        if (!is_array($item)) {
            continue;
        }
        $message = redact($item['MESSAGE'] ?? '');
        if ($message === '') {
            continue;
        }
        $priority = (int)($item['PRIORITY'] ?? 4);
        $time = null;
        if (!empty($item['__REALTIME_TIMESTAMP'])) {
            $time = gmdate('c', (int)((int)$item['__REALTIME_TIMESTAMP'] / 1000000));
        }
        $entries[] = ['time' => $time, 'priority' => $priority, 'message' => $message];
    }
    $warningCount = count(array_filter($entries, fn($entry) => (int)$entry['priority'] === 4));
    $errorCount = count(array_filter($entries, fn($entry) => (int)$entry['priority'] <= 3));
    $latest = array_slice($entries, -8);
    return ['ok' => true, 'window' => '24h', 'warning_count' => $warningCount, 'error_count' => $errorCount, 'latest_at' => $latest ? $latest[count($latest) - 1]['time'] : null, 'latest' => $latest];
}

function ssh_probe(array $machine): array
{
    if (empty($machine['host']) || empty($machine['user']) || empty($machine['key_file']) || !function_exists('shell_exec')) {
        return empty_probe(null, 'SSH probe not configured');
    }

    $script = base64_encode(remote_probe_script());
    $disks = implode(':', $machine['disks'] ?? ['/']);
    $services = implode(',', $machine['services'] ?? []);
    $latencyTargets = base64_encode(json_encode($machine['latency_targets'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}');
    $journalUnits = base64_encode(json_encode($machine['journal_units'] ?? [], JSON_UNESCAPED_SLASHES) ?: '[]');
    $remote = 'PROBE_DISKS=' . escapeshellarg($disks)
        . ' PROBE_SERVICES=' . escapeshellarg($services)
        . ' PROBE_LATENCY_TARGETS_B64=' . escapeshellarg($latencyTargets)
        . ' PROBE_JOURNAL_UNITS_B64=' . escapeshellarg($journalUnits)
        . ' bash -lc ' . escapeshellarg('echo ' . $script . ' | base64 -d | bash');
    $target = $machine['user'] . '@' . $machine['host'];
    $cmd = 'timeout 8s ssh -i ' . escapeshellarg($machine['key_file'])
        . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/signalops-known-hosts -o ConnectTimeout=5 '
        . escapeshellarg($target) . ' ' . escapeshellarg($remote) . ' 2>/dev/null';
    $data = json_decode((string)@shell_exec($cmd), true);
    return is_array($data) ? $data : empty_probe(false, 'Remote probe failed');
}

function remote_probe_script(): string
{
    return <<<'BASH'
python3 - <<'PY'
import base64, datetime, json, os, re, shutil, subprocess, time

def clean_unit(value):
    return re.sub(r'[^A-Za-z0-9@_.-]', '', str(value))

def load_json_env(name, fallback):
    raw = os.environ.get(name, '')
    if not raw:
        return fallback
    try:
        return json.loads(base64.b64decode(raw).decode('utf-8'))
    except Exception:
        return fallback

def cpu_sample():
    with open('/proc/stat', 'r', encoding='utf-8') as f:
        vals = [int(x) for x in f.readline().split()[1:]]
    return {'total': sum(vals), 'idle': vals[3] + vals[4], 'iowait': vals[4]}

def cpu_info():
    model = None
    cores = 0
    try:
        with open('/proc/cpuinfo', 'r', encoding='utf-8') as f:
            for line in f:
                if line.startswith('model name') and model is None:
                    model = line.split(':', 1)[1].strip()
                if line.startswith('processor'):
                    cores += 1
    except Exception:
        pass
    return {'model': model, 'cores': cores or None}

def network_counters(iface='tailscale0'):
    safe = ''.join(ch for ch in iface if ch.isalnum() or ch in '_.:-')
    base = f'/sys/class/net/{safe}/statistics'
    try:
        with open(os.path.join(base, 'rx_bytes'), 'r', encoding='utf-8') as f:
            rx = int(f.read().strip())
        with open(os.path.join(base, 'tx_bytes'), 'r', encoding='utf-8') as f:
            tx = int(f.read().strip())
        return {'iface': safe, 'rx_bytes': rx, 'tx_bytes': tx, 'total_bytes': rx + tx}
    except Exception:
        return {'iface': safe, 'rx_bytes': None, 'tx_bytes': None, 'total_bytes': None}

def ping(host, timeout=1):
    if not host or not re.match(r'^[A-Za-z0-9_.:-]+$', host):
        return {'ok': None, 'latency_ms': None, 'error': 'Ping target not configured'}
    try:
        out = subprocess.run(['ping', '-n', '-c', '1', '-W', str(timeout), host], text=True, capture_output=True, timeout=timeout + 1)
        match = re.search(r'time[=<]\s*([0-9.]+)', (out.stdout or '') + (out.stderr or ''))
        return {'ok': out.returncode == 0, 'latency_ms': float(match.group(1)) if match else None, 'error': None if out.returncode == 0 else 'Ping failed'}
    except Exception:
        return {'ok': False, 'latency_ms': None, 'error': 'Ping failed'}

def redact_log(text):
    text = str(text or '').replace('\r', ' ').replace('\n', ' ')
    text = re.sub(r'https?://\S+', '[url]', text, flags=re.I)
    text = re.sub(r'\b\d{1,3}(?:\.\d{1,3}){3}\b', '[private-host]', text)
    text = re.sub(r'\b(Bearer|Bot)\s+[A-Za-z0-9._-]{12,}', r'\1 [redacted]', text, flags=re.I)
    text = re.sub(r'\b(password|passwd|token|secret|key|authorization|api[_-]?key)=([^\s&]+)', r'\1=[redacted]', text, flags=re.I)
    text = re.sub(r'\b\d{12,}\b', '[id]', text)
    text = re.sub(r'\b[A-Za-z0-9._-]{40,}\b', '[redacted]', text)
    return re.sub(r'\s+', ' ', text).strip()[:260]

def journal_summary(units):
    safe_units = [clean_unit(unit) for unit in units if clean_unit(unit)]
    if not safe_units:
        return {'ok': None, 'window': '24h', 'warning_count': 0, 'error_count': 0, 'latest_at': None, 'latest': []}
    cmd = ['journalctl', '--since', '24 hours ago', '-p', 'warning..alert', '-n', '160', '-o', 'json', '--no-pager', '--quiet']
    for unit in safe_units:
        cmd += ['-u', unit]
    try:
        out = subprocess.run(cmd, text=True, capture_output=True, timeout=5)
    except Exception:
        return {'ok': False, 'window': '24h', 'warning_count': 0, 'error_count': 0, 'latest_at': None, 'latest': [], 'error': 'Journal query failed'}
    entries = []
    for line in (out.stdout or '').splitlines():
        try:
            item = json.loads(line)
        except Exception:
            continue
        message = redact_log(item.get('MESSAGE'))
        if not message:
            continue
        priority = int(item.get('PRIORITY', 4))
        timestamp = None
        try:
            timestamp = datetime.datetime.fromtimestamp(int(item.get('__REALTIME_TIMESTAMP')) / 1000000, datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
        except Exception:
            pass
        entries.append({'time': timestamp, 'priority': priority, 'message': message})
    latest = entries[-8:]
    return {'ok': True, 'window': '24h', 'warning_count': sum(1 for e in entries if e['priority'] == 4), 'error_count': sum(1 for e in entries if e['priority'] <= 3), 'latest_at': latest[-1]['time'] if latest else None, 'latest': latest}

a = cpu_sample()
time.sleep(0.18)
b = cpu_sample()
dt = max(1, b['total'] - a['total'])

mem = {}
try:
    with open('/proc/meminfo', 'r', encoding='utf-8') as f:
        for line in f:
            if ':' in line:
                k, v = line.split(':', 1)
                mem[k] = int(v.strip().split()[0]) * 1024
except Exception:
    pass
memory = {'total': mem.get('MemTotal'), 'available': mem.get('MemAvailable'), 'used_pct': None}
if memory['total']:
    memory['used_pct'] = 100 * (1 - (memory['available'] or 0) / memory['total'])

disks = []
for path in [p for p in os.environ.get('PROBE_DISKS', '/').split(':') if p]:
    try:
        usage = shutil.disk_usage(path)
        disks.append({'path': path, 'total': usage.total, 'free': usage.free, 'used_pct': 100 * (1 - usage.free / usage.total)})
    except Exception:
        disks.append({'path': path, 'total': None, 'free': None, 'used_pct': None})

services = {}
for service in [clean_unit(s) for s in os.environ.get('PROBE_SERVICES', '').split(',') if clean_unit(s)]:
    try:
        out = subprocess.run(['systemctl', 'is-active', service], text=True, capture_output=True, timeout=3)
        services[service] = (out.stdout or out.stderr).strip() or 'unknown'
    except Exception:
        services[service] = 'unknown'

uptime = None
try:
    with open('/proc/uptime', 'r', encoding='utf-8') as f:
        uptime = int(float(f.read().split()[0]))
except Exception:
    pass

targets = load_json_env('PROBE_LATENCY_TARGETS_B64', {})
units = load_json_env('PROBE_JOURNAL_UNITS_B64', [])
print(json.dumps({
    'ok': True,
    'cpu_pct': max(0, min(100, 100 * (1 - ((b['idle'] - a['idle']) / dt)))),
    'iowait_pct': max(0, min(100, 100 * ((b['iowait'] - a['iowait']) / dt))),
    'memory': memory,
    'cpu': cpu_info(),
    'network': network_counters('tailscale0'),
    'latencies': {str(k): ping(str(v)) for k, v in targets.items()},
    'disks': disks,
    'services': services,
    'journal': journal_summary(units),
    'load': list(os.getloadavg())[:3] if hasattr(os, 'getloadavg') else [],
    'uptime_seconds': uptime,
    'error': None,
}))
PY
BASH;
}

function endpoint_machine_probe($endpoint): array
{
    if (!$endpoint) {
        return empty_probe(null, 'Endpoint not configured');
    }
    $data = $endpoint['data'] ?? [];
    if (isset($data['machine']) && is_array($data['machine'])) {
        return array_replace_recursive(empty_probe($endpoint['ok'] ?? null, $endpoint['error'] ?? null), $data['machine']);
    }
    return empty_probe(null, 'Machine probe not configured; showing endpoint health only');
}

function fetch_json_endpoint(string $url): array
{
    if ($url === '') {
        return ['ok' => null, 'http_code' => null, 'data' => null, 'error' => 'Endpoint not configured'];
    }
    $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $code = null;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $code = (int)$match[1];
            break;
        }
    }
    if ($raw === false) {
        return ['ok' => false, 'http_code' => $code, 'data' => null, 'error' => 'Request failed'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http_code' => $code, 'data' => null, 'error' => 'Invalid JSON response'];
    }
    return ['ok' => (bool)($data['ok'] ?? ($code && $code < 400)), 'http_code' => $code, 'data' => $data, 'error' => null];
}

function apply_ping_sla(array $machines, array $machineConfigs, string $path, int $timeoutSeconds): array
{
    $configs = [];
    foreach ($machineConfigs as $config) {
        $configs[(string)($config['key'] ?? '')] = $config;
    }
    $state = read_json_file($path) ?: ['machines' => []];
    $today = date('Y-m-d');
    $month = date('Y-m');
    foreach ($machines as $index => $machine) {
        $key = (string)($machine['key'] ?? '');
        $host = (string)($configs[$key]['sla_host'] ?? '');
        $result = ping_target($host, $timeoutSeconds);
        $entry = $state['machines'][$key] ?? ['day' => $today, 'month' => $month, 'day_ok' => 0, 'day_total' => 0, 'month_ok' => 0, 'month_total' => 0];
        if (($entry['day'] ?? '') !== $today) {
            $entry['day'] = $today;
            $entry['day_ok'] = 0;
            $entry['day_total'] = 0;
        }
        if (($entry['month'] ?? '') !== $month) {
            $entry['month'] = $month;
            $entry['month_ok'] = 0;
            $entry['month_total'] = 0;
        }
        if (($result['ok'] ?? null) !== null) {
            $entry['day_total']++;
            $entry['month_total']++;
            if ($result['ok'] === true) {
                $entry['day_ok']++;
                $entry['month_ok']++;
            }
        }
        $state['machines'][$key] = $entry;
        $machines[$index]['sla'] = [
            'last_ok' => $result['ok'],
            'last_latency_ms' => $result['latency_ms'],
            'today_pct' => $entry['day_total'] ? 100 * $entry['day_ok'] / $entry['day_total'] : null,
            'month_pct' => $entry['month_total'] ? 100 * $entry['month_ok'] / $entry['month_total'] : null,
            'checks_today' => $entry['day_total'],
            'error' => $result['error'],
        ];
    }
    write_json_file($path, $state);
    return $machines;
}

function apply_traffic_usage(array $machines, string $path): array
{
    $state = read_json_file($path) ?: ['machines' => []];
    $today = date('Y-m-d');
    $month = date('Y-m');
    foreach ($machines as $index => $machine) {
        $key = (string)($machine['key'] ?? '');
        $network = $machine['probe']['network'] ?? [];
        $total = $network['total_bytes'] ?? null;
        if ($total === null) {
            continue;
        }
        $entry = $state['machines'][$key] ?? ['baseline' => $total, 'day' => $today, 'day_baseline' => $total, 'month' => $month, 'month_baseline' => $total, 'tracked_days' => 1];
        if (($entry['day'] ?? '') !== $today) {
            $entry['day'] = $today;
            $entry['day_baseline'] = $total;
            $entry['tracked_days'] = max(1, (int)($entry['tracked_days'] ?? 0) + 1);
        }
        if (($entry['month'] ?? '') !== $month) {
            $entry['month'] = $month;
            $entry['month_baseline'] = $total;
            $entry['tracked_days'] = 1;
        }
        $todayBytes = max(0, $total - (int)$entry['day_baseline']);
        $monthBytes = max(0, $total - (int)$entry['month_baseline']);
        $trackedDays = max(1, (int)($entry['tracked_days'] ?? 1));
        $machines[$index]['probe']['traffic'] = ['today_bytes' => $todayBytes, 'month_bytes' => $monthBytes, 'avg_daily_bytes' => $monthBytes / $trackedDays, 'tracked_days' => $trackedDays];
        $state['machines'][$key] = $entry;
    }
    write_json_file($path, $state);
    return $machines;
}

function latency_tone($ok, $latencyMs): string
{
    if ($ok !== true || $latencyMs === null) {
        return 'warn';
    }
    $latency = (float)$latencyMs;
    if ($latency >= 120) {
        return 'bad';
    }
    if ($latency >= 70) {
        return 'warn';
    }
    return 'ok';
}

function build_latency_map(array $mapConfig, array $machines): array
{
    if (empty($mapConfig['enabled'])) {
        return ['enabled' => false, 'nodes' => [], 'links' => []];
    }
    $machineByKey = [];
    foreach ($machines as $machine) {
        $machineByKey[(string)($machine['key'] ?? '')] = $machine;
    }
    $links = [];
    foreach (($mapConfig['links'] ?? []) as $link) {
        $source = (string)($link['source'] ?? $link['from'] ?? '');
        $target = (string)($link['target'] ?? $link['to'] ?? '');
        $latency = $machineByKey[$source]['probe']['latencies'][$target] ?? null;
        if (!is_array($latency)) {
            $latency = ['ok' => null, 'latency_ms' => $link['latency_ms'] ?? null, 'error' => null];
        }
        $links[] = [
            'from' => $link['from'] ?? $source,
            'to' => $link['to'] ?? $target,
            'label' => $link['label'] ?? ($source . ' to ' . $target),
            'latency_ms' => $latency['latency_ms'] ?? null,
            'tone' => $link['tone'] ?? latency_tone($latency['ok'] ?? null, $latency['latency_ms'] ?? null),
        ];
    }
    return ['enabled' => true, 'nodes' => $mapConfig['nodes'] ?? [], 'links' => $links];
}
