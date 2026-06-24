<?php
declare(strict_types=1);

$app = $config['app'] ?? [];
$theme = $app['theme'] ?? [];
$database = $status['database'] ?? ['ok' => null, 'summary' => [], 'kinds' => [], 'recent' => []];
$summary = is_array($database['summary'] ?? null) ? $database['summary'] : [];
$endpoints = is_array($status['endpoints'] ?? null) ? $status['endpoints'] : [];
$discordHealth = $endpoints['discord'] ?? ['ok' => null, 'data' => []];
$apiHealth = $endpoints['api'] ?? ['ok' => null, 'data' => []];
$discord = is_array($discordHealth['data'] ?? null) ? $discordHealth['data'] : [];
$api = is_array($apiHealth['data'] ?? null) ? $apiHealth['data'] : [];
$discordDisplay = endpoint_display_state($discordHealth);
$apiDisplay = endpoint_display_state($apiHealth);
$machines = is_array($status['machines'] ?? null) ? $status['machines'] : [];
$latencyMap = $status['latency_map'] ?? ['enabled' => false, 'nodes' => [], 'links' => []];
$latencyNodes = is_array($latencyMap['nodes'] ?? null) ? $latencyMap['nodes'] : [];
$latencyLinks = is_array($latencyMap['links'] ?? null) ? $latencyMap['links'] : [];
$cacheMeta = is_array($status['_cache'] ?? null) ? $status['_cache'] : [];
$cacheState = strtoupper((string)($cacheMeta['state'] ?? 'live'));
$cacheCreatedAt = max(0, (int)($cacheMeta['created_at'] ?? time()));
$cacheCreatedIso = gmdate('c', $cacheCreatedAt);
$clockSeedIso = gmdate('c');

$machineByKey = [];
$onlineMachines = 0;
$trafficToday = 0.0;
$trafficMonth = 0.0;
$trafficAvg = 0.0;
$trafficSeen = false;
$slaValues = [];

foreach ($machines as $machineRow) {
    $machineKey = (string)($machineRow['key'] ?? '');
    if ($machineKey !== '') {
        $machineByKey[$machineKey] = $machineRow;
    }
    if (($machineRow['probe']['ok'] ?? null) === true) {
        $onlineMachines++;
    }
    $traffic = is_array($machineRow['probe']['traffic'] ?? null) ? $machineRow['probe']['traffic'] : [];
    foreach (['today_bytes' => 'trafficToday', 'month_bytes' => 'trafficMonth', 'avg_daily_bytes' => 'trafficAvg'] as $trafficKey => $targetVariable) {
        if (is_numeric($traffic[$trafficKey] ?? null)) {
            $$targetVariable += (float)$traffic[$trafficKey];
            $trafficSeen = true;
        }
    }
    if (is_numeric($machineRow['sla']['month_pct'] ?? null)) {
        $slaValues[] = (float)$machineRow['sla']['month_pct'];
    }
}

$discordProbe = is_array($machineByKey['discord']['probe'] ?? null) ? $machineByKey['discord']['probe'] : [];
$botJournal = is_array($discordProbe['journal'] ?? null) ? $discordProbe['journal'] : [];
$botJournalTone = journal_status_tone($botJournal);
$botJournalLabel = journal_status_label($botJournal);

$latencyValues = [];
foreach ($latencyLinks as $link) {
    if (is_numeric($link['latency_ms'] ?? null)) {
        $latencyValues[] = (float)$link['latency_ms'];
    }
}
sort($latencyValues);
$medianLatency = null;
if ($latencyValues) {
    $middle = intdiv(count($latencyValues), 2);
    $medianLatency = count($latencyValues) % 2 ? $latencyValues[$middle] : (($latencyValues[$middle - 1] + $latencyValues[$middle]) / 2);
}

$publicSla = $slaValues ? array_sum($slaValues) / count($slaValues) : null;
$dedupeValue = $summary['duplicate_seen_total'] ?? $discord['seen_hashes'] ?? null;
$overallTone = 'ok';
foreach ([$database['ok'] ?? null, $discordHealth['ok'] ?? null, $apiHealth['ok'] ?? null] as $check) {
    if ($check === false) {
        $overallTone = 'bad';
        break;
    }
    if ($check !== true && $overallTone !== 'bad') {
        $overallTone = 'warn';
    }
}
foreach ($machines as $machineRow) {
    if (($machineRow['probe']['ok'] ?? null) === false || ($machineRow['sla']['last_ok'] ?? null) === false) {
        $overallTone = 'bad';
        break;
    }
}
if ($overallTone !== 'bad' && $botJournalTone === 'warn') {
    $overallTone = 'warn';
}
if ($botJournalTone === 'bad') {
    $overallTone = 'bad';
}
$overallLabel = $overallTone === 'ok' ? 'All systems operational' : ($overallTone === 'bad' ? 'Incident detected' : 'Partial visibility');

$formatCount = static function ($value): string {
    return is_numeric($value) ? number_format((float)$value, 0) : 'n/a';
};

$relativeStrong = static function ($value): string {
    $text = age_text($value);
    $iso = iso_time_attr($value);
    return '<strong' . ($iso !== '' ? ' data-relative-time="' . h($iso) . '"' : '') . '>' . h($text) . '</strong>';
};
?>
<!doctype html>
<html lang="en" data-theme="dark" data-timezone="<?= h((string)($app['timezone'] ?? 'America/Toronto')) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="<?= h((string)($config['refresh_seconds'] ?? 30)) ?>">
  <title><?= h($app['name'] ?? 'SignalOps Status') ?></title>
  <meta name="description" content="PHP Based status dashboard for Discord bots, trading services, and private server fleets.">
  <script>
    (() => {
      try {
        document.documentElement.dataset.theme = localStorage.getItem('signalops-theme') || 'dark';
      } catch (error) {
        document.documentElement.dataset.theme = 'dark';
      }
    })();
  </script>
  <link rel="stylesheet" href="/assets/signalops.css?v=20260624-r1">
  <script src="/assets/signalops-ui.js?v=20260624-r1" defer></script>
</head>
<body style="--brand: <?= h($theme['accent'] ?? '#22df98') ?>">
<main class="page">
  <header class="topbar">
    <div class="brandline">
      <div class="brand-mark" aria-hidden="true"></div>
      <div>
        <h1><?= h($app['name'] ?? 'SignalOps Status') ?></h1>
        <p><?= h($app['subtitle'] ?? 'PHP Based status dashboard for Discord bots, trading services, and private server fleets.') ?></p>
      </div>
    </div>
    <div class="topbar-actions">
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle color theme" aria-pressed="false">
        <span data-theme-label>Dark</span>
      </button>
      <div class="status-pill <?= h($overallTone) ?>"><span class="status-dot"></span><?= h($overallLabel) ?></div>
      <div class="stamp"><span>Local time</span><strong data-live-clock="<?= h($clockSeedIso) ?>"><?= h(now_iso()) ?></strong></div>
    </div>
  </header>

  <section class="dashboard-shell">
    <article class="panel hero-card">
      <div>
        <span class="capsule"><span class="status-dot"></span> PHP Based</span>
        <h2>Live Snapshot</h2>
        <p>Bot health, latency, database, and server probes at a glance.</p>
      </div>
      <div class="hero-stats">
        <div><span>Snapshot updated</span><strong><?= h(date('H:i T', $cacheCreatedAt)) ?></strong><small data-local-time="<?= h($cacheCreatedIso) ?>"></small></div>
        <div><span>Cache mode</span><strong><?= h($cacheState) ?></strong></div>
        <div><span>SLA window</span><strong>30 days</strong></div>
        <div><span>Probe nodes</span><strong><?= h((string)$onlineMachines) ?> online</strong></div>
      </div>
    </article>

    <article class="panel overview-card">
      <div class="section-head bare">
        <h2>Service Overview</h2>
        <nav class="segmented" aria-label="Dashboard sections">
          <a class="active" href="#overview">Overview</a>
          <a href="#servers">Servers</a>
          <a href="#latency-map">Latency</a>
          <a href="#logs">Logs</a>
        </nav>
      </div>
      <div class="metrics-grid" id="overview">
        <article class="metric-card">
          <span>Archive</span>
          <h3><?= h((string)($config['database']['label'] ?? 'Database Server')) ?></h3>
          <p><?= h($formatCount($summary['event_count'] ?? null)) ?></p>
          <div class="probe-row"><em>Latest event</em><?= $relativeStrong($summary['latest_event_at'] ?? null) ?></div>
          <div class="probe-row"><em>Status</em><span class="badge <?= h(status_tone($database['ok'] ?? null)) ?>"><?= h(($database['ok'] ?? null) === true ? 'OK' : (($database['ok'] ?? null) === false ? 'Error' : 'Optional')) ?></span></div>
        </article>
        <article class="metric-card">
          <span>Ingestion</span>
          <h3>Deduplication</h3>
          <p><?= h($formatCount($dedupeValue)) ?></p>
          <div class="probe-row"><em>Seen hashes</em><strong><?= h($dedupeValue === null ? 'n/a' : 'stable') ?></strong></div>
          <div class="probe-row"><em>Latest import</em><?= $relativeStrong($summary['latest_insert_at'] ?? $summary['latest_event_at'] ?? null) ?></div>
        </article>
        <article class="metric-card">
          <span>Discord</span>
          <h3><?= h((string)($config['endpoints']['discord']['label'] ?? 'Discord Bot')) ?></h3>
          <p><?= h($formatCount($discord['seen_hashes'] ?? $discord['publish_count'] ?? null)) ?></p>
          <div class="probe-row"><em>Last poll</em><?= $relativeStrong($discord['last_poll_ok_at'] ?? null) ?></div>
          <div class="probe-row"><em>Status</em><span class="badge <?= h($discordDisplay['tone']) ?>"><?= h($discordDisplay['label']) ?></span></div>
        </article>
        <article class="metric-card">
          <span>Capture</span>
          <h3><?= h((string)($config['endpoints']['api']['label'] ?? 'Trading API')) ?></h3>
          <p><?= h($formatCount($api['capture_count'] ?? $api['change_count'] ?? null)) ?></p>
          <div class="probe-row"><em>Latest capture</em><?= $relativeStrong($api['last_capture_at'] ?? null) ?></div>
          <div class="probe-row"><em>Status</em><span class="badge <?= h($apiDisplay['tone']) ?>"><?= h($apiDisplay['label']) ?></span></div>
        </article>
      </div>
      <div class="overview-strip">
        <div><span>Ping SLA</span><strong><?= h(sla_percent($publicSla)) ?></strong></div>
        <div><span>Median Ping</span><strong><?= h(format_latency_ms($medianLatency)) ?></strong></div>
        <div><span>Bot Journal</span><strong><?= h($botJournalLabel) ?></strong></div>
        <div><span>Data Mode</span><strong><?= h(!empty($app['demo']) ? 'Demo' : 'Live') ?></strong></div>
      </div>
    </article>

    <section class="lower-grid">
      <article class="panel resources-panel" id="servers">
        <div class="section-head">
          <h2>Server Resource Monitor</h2>
          <span class="badge blue">Encrypted probes</span>
        </div>
        <div class="server-grid">
          <?php foreach ($machines as $machine): $probe = $machine['probe'] ?? empty_probe(null); ?>
            <?php
              $cpuInfo = is_array($probe['cpu'] ?? null) ? $probe['cpu'] : [];
              $memory = is_array($probe['memory'] ?? null) ? $probe['memory'] : [];
              $traffic = is_array($probe['traffic'] ?? null) ? $probe['traffic'] : [];
              $sla = is_array($machine['sla'] ?? null) ? $machine['sla'] : [];
              $memoryUsed = (is_numeric($memory['total'] ?? null) && is_numeric($memory['available'] ?? null)) ? (float)$memory['total'] - (float)$memory['available'] : null;
              $pingOk = $sla['last_ok'] ?? null;
              $pingLabel = $pingOk === true ? 'Reachable' : ($pingOk === false ? 'Packet loss' : 'Not measured');
            ?>
            <article class="server-card">
              <div class="server-top">
                <div>
                  <h3><?= h($machine['label'] ?? 'Server') ?></h3>
                  <small><?= h(trim((string)($machine['location'] ?? '') . (($machine['role'] ?? '') ? ' / ' . $machine['role'] : ''))) ?></small>
                </div>
                <span class="badge <?= h(status_tone($probe['ok'] ?? null)) ?>"><?= h(($probe['ok'] ?? null) === true ? 'Online' : (($probe['ok'] ?? null) === false ? 'Offline' : 'Limited')) ?></span>
              </div>
              <?= meter_bar('CPU', $probe['cpu_pct'] ?? null) ?>
              <?= meter_bar('MEM', $memory['used_pct'] ?? null) ?>
              <?= meter_bar('I/O', $probe['iowait_pct'] ?? null) ?>
              <div class="server-meta">
                <div><span>Ping SLA</span><strong class="<?= h(status_tone($pingOk)) ?>"><?= h($pingLabel) ?> / <?= h(format_latency_ms($sla['last_latency_ms'] ?? null)) ?></strong></div>
                <div><span>Total memory</span><strong><?= h(format_bytes($memory['total'] ?? null)) ?></strong></div>
                <div class="wide"><span>CPU model</span><strong><?= h(redact($cpuInfo['model'] ?? 'n/a')) ?></strong></div>
                <div><span>CPU cores</span><strong><?= h((string)($cpuInfo['cores'] ?? 'n/a')) ?></strong></div>
                <div><span>Traffic today</span><strong><?= h(format_bytes($traffic['today_bytes'] ?? null)) ?></strong></div>
                <?php if ($memoryUsed !== null): ?><div><span>Memory used</span><strong><?= h(format_bytes($memoryUsed)) ?></strong></div><?php endif; ?>
                <div><span>Month SLA</span><strong><?= h(sla_percent($sla['month_pct'] ?? null)) ?></strong></div>
              </div>
              <?php foreach (($probe['services'] ?? []) as $service => $state): ?>
                <div class="pill-row"><?= service_pill((string)$service, (string)$state) ?></div>
              <?php endforeach; ?>
              <?php foreach (($probe['disks'] ?? []) as $disk): $width = ($disk['used_pct'] ?? null) === null ? 0 : max(0, min(100, (float)$disk['used_pct'])); ?>
                <div class="disk-row"><span class="mono"><?= h($disk['path'] ?? '/') ?></span><strong><?= h(percent($disk['used_pct'] ?? null)) ?></strong></div>
                <div class="bar" title="<?= h(format_bytes($disk['free'] ?? null)) ?> free"><i style="--w: <?= h((string)$width) ?>%"></i></div>
              <?php endforeach; ?>
              <?php if (!empty($probe['error'])): ?><p class="muted"><?= h(redact($probe['error'])) ?></p><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </article>

      <div class="right-stack">
        <?php if (($latencyMap['enabled'] ?? false) && $latencyNodes && $latencyLinks): ?>
        <article class="panel mesh-panel" id="latency-map">
          <div class="section-head">
            <h2>Latency Mesh</h2>
            <span class="badge blue">Ctrl + wheel to zoom</span>
          </div>
          <div class="network-map" data-network-map aria-label="Interactive server latency map">
            <div class="map-toolbar" aria-label="Map controls">
              <button type="button" data-map-zoom-in aria-label="Zoom in">+</button>
              <button type="button" data-map-zoom-out aria-label="Zoom out">-</button>
              <button type="button" data-map-reset aria-label="Reset map">Reset</button>
            </div>
            <svg class="globe-svg" viewBox="0 0 1000 620" role="img" aria-label="North America private network map">
              <defs>
                <radialGradient id="ocean" cx="50%" cy="48%" r="64%">
                  <stop offset="0%" stop-color="#17313d"/><stop offset="100%" stop-color="#071017"/>
                </radialGradient>
                <pattern id="grid" width="38" height="38" patternUnits="userSpaceOnUse"><path d="M38 0H0V38" fill="none" stroke="#8fb4c2" stroke-opacity="0.10"/></pattern>
                <pattern id="landDots" width="8" height="8" patternUnits="userSpaceOnUse"><circle cx="1.8" cy="1.8" r="1" fill="#d7e3e3" fill-opacity="0.22"/><circle cx="5.9" cy="5.4" r="0.75" fill="#d7e3e3" fill-opacity="0.14"/></pattern>
                <filter id="mapGlow" x="-35%" y="-35%" width="170%" height="170%"><feGaussianBlur stdDeviation="4" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
                <clipPath id="globeClip"><ellipse cx="500" cy="310" rx="475" ry="272"/></clipPath>
              </defs>
              <rect class="map-deep" width="1000" height="620"/><ellipse class="globe-ocean" cx="500" cy="310" rx="475" ry="272"/>
              <g clip-path="url(#globeClip)">
                <g class="globe-stage" data-map-stage>
                  <rect class="map-grid-fill" width="1000" height="620"/>
                  <g class="graticule"><path d="M52 310 H948"/><path d="M500 38 V582"/><path d="M118 164 C310 218 692 218 884 164"/><path d="M104 456 C300 392 702 392 896 456"/><path d="M244 60 C206 188 206 434 244 560"/><path d="M756 60 C794 188 794 434 756 560"/></g>
                  <g class="land">
                    <path class="land-main" d="M78 205 C116 137 195 95 289 91 C342 62 434 71 498 116 C592 75 705 86 800 145 C872 191 909 270 881 338 C846 423 715 472 588 462 C548 495 495 517 421 519 C352 521 282 500 222 457 C167 419 122 360 96 294 C83 260 67 224 78 205 Z"/>
                    <path class="land-us" d="M142 320 C237 281 374 265 514 276 C645 286 765 314 844 356 C793 416 682 447 574 438 C529 478 461 497 378 490 C292 483 204 445 164 389 C151 371 142 347 142 320 Z"/>
                    <path class="lake" d="M635 276 C666 252 711 255 735 280 C709 301 664 301 635 276 Z"/><path class="lake" d="M704 294 C735 278 776 287 796 311 C764 327 732 320 704 294 Z"/>
                  </g>
                  <text class="region-label" x="444" y="205">CANADA</text><text class="region-label" x="414" y="386">UNITED STATES</text>
                  <g class="latency-edges">
                    <?php foreach ($latencyLinks as $i => $link): ?>
                      <?php
                        $fromNode = $latencyNodes[$link['from'] ?? ''] ?? null;
                        $toNode = $latencyNodes[$link['to'] ?? ''] ?? null;
                        if (!$fromNode || !$toNode) { continue; }
                        $x1 = (float)$fromNode['x'] * 10; $y1 = (float)$fromNode['y'] * 6.2;
                        $x2 = (float)$toNode['x'] * 10; $y2 = (float)$toNode['y'] * 6.2;
                        $dx = $x2 - $x1; $dy = $y2 - $y1; $dist = max(1, sqrt($dx * $dx + $dy * $dy));
                        $bend = min(82, max(28, $dist * 0.1)) * (($i % 2) === 0 ? -1 : 1);
                        $cx = (($x1 + $x2) / 2) + (-$dy / $dist) * $bend;
                        $cy = (($y1 + $y2) / 2) + ($dx / $dist) * $bend;
                        $pathId = 'latency-link-' . (int)$i;
                      ?>
                      <path id="<?= h($pathId) ?>" class="latency-path <?= h($link['tone'] ?? 'warn') ?>" d="M <?= h(number_format($x1, 1, '.', '')) ?> <?= h(number_format($y1, 1, '.', '')) ?> Q <?= h(number_format($cx, 1, '.', '')) ?> <?= h(number_format($cy, 1, '.', '')) ?> <?= h(number_format($x2, 1, '.', '')) ?> <?= h(number_format($y2, 1, '.', '')) ?>" data-link-from="<?= h((string)($link['from'] ?? '')) ?>" data-link-to="<?= h((string)($link['to'] ?? '')) ?>"/>
                      <circle class="latency-pulse <?= h($link['tone'] ?? 'warn') ?>" r="4" data-link-from="<?= h((string)($link['from'] ?? '')) ?>" data-link-to="<?= h((string)($link['to'] ?? '')) ?>"><animateMotion dur="<?= h(number_format(3.4 + ($i * 0.12), 2, '.', '')) ?>s" repeatCount="indefinite" rotate="auto"><mpath href="#<?= h($pathId) ?>"/></animateMotion></circle>
                    <?php endforeach; ?>
                  </g>
                  <g class="latency-nodes">
                    <?php foreach ($latencyNodes as $key => $node): ?>
                      <?php $x = (float)$node['x'] * 10; $y = (float)$node['y'] * 6.2; ?>
                      <g class="globe-node" data-node-key="<?= h((string)$key) ?>" role="button" tabindex="0" aria-pressed="false" aria-label="<?= h(($node['label'] ?? '') . ', ' . ($node['location'] ?? '')) ?>" transform="translate(<?= h(number_format($x, 1, '.', '')) ?> <?= h(number_format($y, 1, '.', '')) ?>)">
                        <circle class="node-hit" r="28"/><circle class="node-range" r="23"/><circle class="node-halo" r="14"/><circle class="node-dot" r="6"/>
                        <g class="node-label" transform="translate(20 -22)"><rect width="190" height="46" rx="6"/><text x="12" y="18" class="node-name"><?= h($node['label'] ?? '') ?></text><text x="12" y="34" class="node-city"><?= h($node['location'] ?? '') ?></text></g>
                      </g>
                    <?php endforeach; ?>
                  </g>
                </g>
              </g>
              <ellipse class="globe-rim" cx="500" cy="310" rx="475" ry="272"/>
            </svg>
          </div>
          <div class="latency-detail">
            <div class="latency-detail-head">
              <div><h3 data-latency-focus-title>Latency Links</h3><span data-latency-focus-count></span></div>
            </div>
            <div class="latency-filter-wrap">
              <div class="latency-filter-bar" aria-label="Latency focus">
                <?php foreach ($latencyNodes as $key => $node): ?><button type="button" data-latency-filter="<?= h((string)$key) ?>"><?= h((string)($node['label'] ?? $key)) ?></button><?php endforeach; ?>
                <button type="button" data-latency-filter="all">All</button>
              </div>
            </div>
            <div class="latency-list">
              <?php foreach ($latencyLinks as $link): ?>
                <div class="latency-row" data-link-row data-link-from="<?= h((string)($link['from'] ?? '')) ?>" data-link-to="<?= h((string)($link['to'] ?? '')) ?>"><span><?= h($link['label'] ?? '') ?></span><strong class="<?= h($link['tone'] ?? 'warn') ?>"><?= h(format_latency_ms($link['latency_ms'] ?? null)) ?></strong></div>
              <?php endforeach; ?>
            </div>
          </div>
        </article>
        <?php endif; ?>

        <section class="bottom-cards" id="logs">
          <article class="panel info-card">
            <div class="section-head bare"><h2>Discord Error Journal</h2><span class="badge <?= h($botJournalTone) ?>"><?= h($botJournalLabel) ?></span></div>
            <div class="table-line"><span>Warnings / 24h</span><strong><?= h((string)(int)($botJournal['warning_count'] ?? 0)) ?></strong></div>
            <div class="table-line"><span>Errors / 24h</span><strong><?= h((string)(int)($botJournal['error_count'] ?? 0)) ?></strong></div>
            <div class="table-line"><span>Latest entry</span><?= $relativeStrong($botJournal['latest_at'] ?? null) ?></div>
            <?php $journalEntries = is_array($botJournal['latest'] ?? null) ? $botJournal['latest'] : []; ?>
            <?php if ($journalEntries): ?>
              <div class="journal-list">
                <?php foreach (array_reverse(array_slice($journalEntries, -3)) as $entry): $entryTone = (int)($entry['priority'] ?? 4) <= 3 ? 'bad' : 'warn'; ?>
                  <div class="journal-entry <?= h($entryTone) ?>"><span class="journal-time mono"><?= h(local_time_text($entry['time'] ?? null)) ?></span><span class="badge <?= h($entryTone) ?>"><?= h($entryTone === 'bad' ? 'Error' : 'Warning') ?></span><p><?= h(redact($entry['message'] ?? '')) ?></p></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </article>

          <article class="panel info-card">
            <div class="section-head bare"><h2>Archive Breakdown</h2></div>
            <?php foreach (array_slice(($database['kinds'] ?? []), 0, 5) as $row): ?>
              <div class="table-line"><span><?= h($row['kind'] ?? '-') ?></span><strong><?= h(number_format((int)($row['count'] ?? 0))) ?></strong></div>
            <?php endforeach; ?>
            <div class="table-line"><span>Symbol index</span><strong><?= h(number_format((int)($summary['symbol_count'] ?? 0))) ?></strong></div>
          </article>

          <article class="panel info-card">
            <div class="section-head bare"><h2>Traffic Summary</h2></div>
            <div class="table-line"><span>Today</span><strong><?= h(format_bytes($trafficSeen ? $trafficToday : null)) ?></strong></div>
            <div class="table-line"><span>This month</span><strong><?= h(format_bytes($trafficSeen ? $trafficMonth : null)) ?></strong></div>
            <div class="table-line"><span>Daily avg</span><strong><?= h(format_bytes($trafficSeen ? $trafficAvg : null)) ?></strong></div>
          </article>
        </section>
      </div>
    </section>
  </section>

  <section class="detail-grid">
    <article class="panel table-card">
      <div class="section-head"><h2>Latest Signals</h2></div>
      <div class="table-scroll">
        <table>
          <thead><tr><th>Seen</th><th>Type</th><th>Symbol</th><th>Content</th></tr></thead>
          <tbody><?php foreach (($database['recent'] ?? []) as $row): ?><tr><td class="mono"><?= h(local_time_text($row['seen_at'] ?? $row['first_seen_at_utc'] ?? null)) ?></td><td><?= h($row['kind'] ?? '-') ?></td><td class="mono"><?= h($row['symbol'] ?? $row['underlying'] ?? '-') ?></td><td><?= h(redact($row['text'] ?? '')) ?></td></tr><?php endforeach; ?></tbody>
        </table>
      </div>
      <?php if (!empty($database['error'])): ?><p class="muted"><?= h(redact($database['error'])) ?></p><?php endif; ?>
    </article>

    <article class="panel ops-card">
      <div class="section-head"><h2>Runtime Details</h2></div>
      <div class="table-line"><span>Discord poll count</span><strong class="mono"><?= h($discord['poll_count'] ?? 'n/a') ?></strong></div>
      <div class="table-line"><span>Discord publish count</span><strong class="mono"><?= h($discord['publish_count'] ?? 'n/a') ?></strong></div>
      <div class="table-line"><span>Bot last event</span><?= $relativeStrong($discord['last_event_at'] ?? null) ?></div>
      <div class="table-line"><span>API change count</span><strong class="mono"><?= h($api['change_count'] ?? 'n/a') ?></strong></div>
      <div class="table-line"><span>API HTTP</span><strong><?= h((string)($apiHealth['http_code'] ?? 'n/a')) ?></strong></div>
      <div class="table-line"><span>API latest capture</span><?= $relativeStrong($api['last_capture_at'] ?? null) ?></div>
    </article>
  </section>

  <footer>
    <?php if (!empty($theme['repository_url'])): ?><a href="<?= h((string)$theme['repository_url']) ?>" target="_blank" rel="noopener noreferrer">SignalOps Status</a><?php else: ?>SignalOps Status<?php endif; ?>
    <span> / </span>
    <span>PHP Based self-hosted monitoring</span>
  </footer>
</main>
</body>
</html>
