<?php
declare(strict_types=1);

$app = $config['app'] ?? [];
$theme = $app['theme'] ?? [];
$database = $status['database'] ?? ['ok' => null, 'summary' => [], 'kinds' => [], 'recent' => []];
$summary = $database['summary'] ?? [];
$endpoints = $status['endpoints'] ?? [];
$discordHealth = $endpoints['discord'] ?? ['ok' => null, 'data' => []];
$apiHealth = $endpoints['api'] ?? ['ok' => null, 'data' => []];
$discord = is_array($discordHealth['data'] ?? null) ? $discordHealth['data'] : [];
$api = is_array($apiHealth['data'] ?? null) ? $apiHealth['data'] : [];
$discordDisplay = endpoint_display_state($discordHealth);
$apiDisplay = endpoint_display_state($apiHealth);
$machines = is_array($status['machines'] ?? null) ? $status['machines'] : [];
$onlineMachines = count(array_filter($machines, fn($machine) => ($machine['probe']['ok'] ?? null) === true));
$latencyMap = $status['latency_map'] ?? ['enabled' => false, 'nodes' => [], 'links' => []];
$latencyNodes = is_array($latencyMap['nodes'] ?? null) ? $latencyMap['nodes'] : [];
$latencyLinks = is_array($latencyMap['links'] ?? null) ? $latencyMap['links'] : [];
$machineByKey = [];
foreach ($machines as $machineRow) {
    $machineKey = (string)($machineRow['key'] ?? '');
    if ($machineKey !== '') {
        $machineByKey[$machineKey] = $machineRow;
    }
}
$discordProbe = is_array($machineByKey['discord']['probe'] ?? null) ? $machineByKey['discord']['probe'] : [];
$botJournal = is_array($discordProbe['journal'] ?? null) ? $discordProbe['journal'] : [];
$botJournalTone = journal_status_tone($botJournal);
$botJournalLabel = journal_status_label($botJournal);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="<?= h((string)($config['refresh_seconds'] ?? 30)) ?>">
  <title><?= h($app['name'] ?? 'SignalOps Status') ?></title>
  <meta name="description" content="PHP Based status dashboard for Discord bots, trading services, and private server fleets.">
  <link rel="stylesheet" href="/assets/signalops.css?v=20260616">
  <script src="/assets/signalops-map.js?v=20260616" defer></script>
</head>
<body style="--brand: <?= h($theme['accent'] ?? '#20b981') ?>">
<main class="page">
  <header class="topbar">
    <div>
      <div class="brand-line">
        <h1><?= h($app['name'] ?? 'SignalOps Status') ?></h1>
        <span class="runtime-badge">PHP Based</span>
      </div>
      <p class="subtitle"><?= h($app['subtitle'] ?? '') ?></p>
    </div>
    <div class="stamp">
      <span>Last refresh</span>
      <strong><?= h(now_iso()) ?></strong>
    </div>
  </header>

  <section class="summary-grid" aria-label="Summary">
    <article class="card summary-card accent-green">
      <div class="card-kicker">Archive</div>
      <h2><?= h((string)($config['database']['label'] ?? 'Database Server')) ?></h2>
      <div class="metric"><?= h(number_format((int)($summary['event_count'] ?? 0))) ?></div>
      <div class="row"><span>Latest event</span><strong><?= h(age_text($summary['latest_event_at'] ?? null)) ?></strong></div>
      <div class="row"><span>Status</span><span class="badge <?= h(status_tone($database['ok'] ?? null)) ?>"><?= h(($database['ok'] ?? null) === true ? 'OK' : (($database['ok'] ?? null) === false ? 'Error' : 'Demo / Optional')) ?></span></div>
    </article>

    <article class="card summary-card accent-blue">
      <div class="card-kicker">Discord</div>
      <h2>Discord Bot</h2>
      <div class="metric"><?= h((string)($discord['seen_hashes'] ?? $discord['publish_count'] ?? 'n/a')) ?></div>
      <div class="row"><span>Last poll</span><strong><?= h(age_text($discord['last_poll_ok_at'] ?? null)) ?></strong></div>
      <div class="row"><span>Status</span><span class="badge <?= h($discordDisplay['tone']) ?>"><?= h($discordDisplay['label']) ?></span></div>
    </article>

    <article class="card summary-card accent-violet">
      <div class="card-kicker">Trading</div>
      <h2>API Service</h2>
      <div class="metric"><?= h((string)($api['capture_count'] ?? $api['change_count'] ?? 'n/a')) ?></div>
      <div class="row"><span>Latest capture</span><strong><?= h(age_text($api['last_capture_at'] ?? null)) ?></strong></div>
      <div class="row"><span>Status</span><span class="badge <?= h($apiDisplay['tone']) ?>"><?= h($apiDisplay['label']) ?></span></div>
    </article>

    <article class="card summary-card accent-amber">
      <div class="card-kicker">Fleet</div>
      <h2>Servers Online</h2>
      <div class="metric"><?= h((string)$onlineMachines) ?><small>/<?= h((string)count($machines)) ?></small></div>
      <div class="row"><span>Private network</span><strong><?= h(count($latencyLinks) ? count($latencyLinks) . ' paths' : 'n/a') ?></strong></div>
      <div class="row"><span>Journal</span><span class="badge <?= h($botJournalTone) ?>"><?= h($botJournalLabel) ?></span></div>
    </article>
  </section>

  <section class="panel">
    <div class="panel-title">
      <h2>Server Resource Monitor</h2>
      <span>Encrypted private network probes</span>
    </div>
    <div class="machines">
      <?php foreach ($machines as $machine): $probe = $machine['probe'] ?? empty_probe(null); ?>
        <?php
          $cpuInfo = is_array($probe['cpu'] ?? null) ? $probe['cpu'] : [];
          $memory = is_array($probe['memory'] ?? null) ? $probe['memory'] : [];
          $traffic = is_array($probe['traffic'] ?? null) ? $probe['traffic'] : [];
          $sla = is_array($machine['sla'] ?? null) ? $machine['sla'] : [];
          $cpuModel = (string)($cpuInfo['model'] ?? 'n/a');
          $memoryUsed = (is_numeric($memory['total'] ?? null) && is_numeric($memory['available'] ?? null)) ? (float)$memory['total'] - (float)$memory['available'] : null;
          $pingOk = $sla['last_ok'] ?? null;
          $pingLabel = $pingOk === true ? 'Reachable' : ($pingOk === false ? 'Packet loss' : 'Not measured');
        ?>
        <article class="card machine-card">
          <div class="machine-title">
            <div class="machine-heading">
              <h3><?= h($machine['label'] ?? 'Server') ?></h3>
              <span><?= h(trim((string)($machine['location'] ?? '') . (($machine['role'] ?? '') ? ' · ' . $machine['role'] : ''))) ?></span>
            </div>
            <span class="badge <?= h(status_tone($probe['ok'] ?? null)) ?>"><?= h(($probe['ok'] ?? null) === true ? 'Online' : (($probe['ok'] ?? null) === false ? 'Offline' : 'Limited')) ?></span>
          </div>
          <div class="sla-line">
            <span>Ping SLA</span>
            <strong class="<?= h(status_tone($pingOk)) ?>"><?= h($pingLabel) ?> · <?= h(format_latency_ms($sla['last_latency_ms'] ?? null)) ?></strong>
          </div>
          <div class="sla-grid">
            <div><span>Today</span><strong class="mono"><?= h(sla_percent($sla['today_pct'] ?? null)) ?></strong></div>
            <div><span>Month</span><strong class="mono"><?= h(sla_percent($sla['month_pct'] ?? null)) ?></strong></div>
            <div><span>Checks</span><strong class="mono"><?= h((string)($sla['checks_today'] ?? 0)) ?></strong></div>
          </div>
          <div class="gauges">
            <?= gauge('CPU', $probe['cpu_pct'] ?? null) ?>
            <?= gauge('Memory', $memory['used_pct'] ?? null, format_bytes($memoryUsed) . ' used') ?>
            <?= gauge('I/O wait', $probe['iowait_pct'] ?? null) ?>
          </div>
          <div class="spec-grid">
            <div class="spec-item"><span>Total memory</span><strong class="mono"><?= h(format_bytes($memory['total'] ?? null)) ?></strong></div>
            <div class="spec-item"><span>CPU cores</span><strong class="mono"><?= h((string)($cpuInfo['cores'] ?? 'n/a')) ?></strong></div>
            <div class="spec-item spec-wide"><span>CPU model</span><strong title="<?= h($cpuModel) ?>"><?= h($cpuModel) ?></strong></div>
          </div>
          <div class="traffic-grid">
            <div><span>Traffic today</span><strong class="mono"><?= h(format_bytes($traffic['today_bytes'] ?? null)) ?></strong></div>
            <div><span>This month</span><strong class="mono"><?= h(format_bytes($traffic['month_bytes'] ?? null)) ?></strong></div>
            <div><span>Daily avg</span><strong class="mono"><?= h(format_bytes($traffic['avg_daily_bytes'] ?? null)) ?></strong></div>
          </div>
          <div class="row"><span>Load average</span><strong class="mono"><?= h(implode(' / ', array_map(fn($v) => number_format((float)$v, 2), $probe['load'] ?? [])) ?: 'n/a') ?></strong></div>
          <div class="row"><span>Uptime</span><strong><?= h(uptime_text($probe['uptime_seconds'] ?? null)) ?></strong></div>
          <?php foreach (($probe['services'] ?? []) as $service => $state): ?>
            <div class="pill-row"><?= service_pill((string)$service, (string)$state) ?></div>
          <?php endforeach; ?>
          <?php foreach (($probe['disks'] ?? []) as $disk): $width = ($disk['used_pct'] ?? null) === null ? 0 : max(0, min(100, (float)$disk['used_pct'])); ?>
            <div class="row"><span class="mono"><?= h($disk['path'] ?? '/') ?></span><strong><?= h(percent($disk['used_pct'] ?? null)) ?></strong></div>
            <div class="bar" title="<?= h(format_bytes($disk['free'] ?? null)) ?> free"><i style="--w: <?= h((string)$width) ?>%"></i></div>
          <?php endforeach; ?>
          <?php if (!empty($probe['error'])): ?><p class="muted"><?= h(redact($probe['error'])) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if (($latencyMap['enabled'] ?? false) && $latencyNodes && $latencyLinks): ?>
  <section class="panel latency-panel">
    <div class="panel-title">
      <h2>Network Latency Map</h2>
      <span>Point-to-point private network latency</span>
    </div>
    <div class="latency-layout">
      <div class="network-map" data-network-map aria-label="Interactive server latency map">
        <div class="map-toolbar" aria-label="Map controls">
          <button type="button" data-map-zoom-in aria-label="Zoom in">+</button>
          <button type="button" data-map-zoom-out aria-label="Zoom out">-</button>
          <button type="button" data-map-reset aria-label="Reset map">Reset</button>
        </div>
        <svg class="globe-svg" viewBox="0 0 1000 620" role="img" aria-label="North America private network map">
          <defs>
            <radialGradient id="ocean" cx="50%" cy="48%" r="64%">
              <stop offset="0%" stop-color="#17313d"/>
              <stop offset="100%" stop-color="#071017"/>
            </radialGradient>
            <pattern id="grid" width="38" height="38" patternUnits="userSpaceOnUse">
              <path d="M38 0H0V38" fill="none" stroke="#8fb4c2" stroke-opacity="0.10"/>
            </pattern>
            <pattern id="landDots" width="8" height="8" patternUnits="userSpaceOnUse">
              <circle cx="1.8" cy="1.8" r="1" fill="#d7e3e3" fill-opacity="0.22"/>
              <circle cx="5.9" cy="5.4" r="0.75" fill="#d7e3e3" fill-opacity="0.14"/>
            </pattern>
            <filter id="mapGlow" x="-35%" y="-35%" width="170%" height="170%">
              <feGaussianBlur stdDeviation="4" result="blur"/>
              <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
            </filter>
            <clipPath id="globeClip"><ellipse cx="500" cy="310" rx="475" ry="272"/></clipPath>
          </defs>
          <rect class="map-deep" width="1000" height="620"/>
          <ellipse class="globe-ocean" cx="500" cy="310" rx="475" ry="272"/>
          <g clip-path="url(#globeClip)">
            <g class="globe-stage" data-map-stage>
              <rect class="map-grid-fill" width="1000" height="620"/>
              <g class="graticule"><path d="M52 310 H948"/><path d="M500 38 V582"/><path d="M118 164 C310 218 692 218 884 164"/><path d="M104 456 C300 392 702 392 896 456"/><path d="M244 60 C206 188 206 434 244 560"/><path d="M756 60 C794 188 794 434 756 560"/></g>
              <g class="land">
                <path class="land-main" d="M78 205 C116 137 195 95 289 91 C342 62 434 71 498 116 C592 75 705 86 800 145 C872 191 909 270 881 338 C846 423 715 472 588 462 C548 495 495 517 421 519 C352 521 282 500 222 457 C167 419 122 360 96 294 C83 260 67 224 78 205 Z"/>
                <path class="land-us" d="M142 320 C237 281 374 265 514 276 C645 286 765 314 844 356 C793 416 682 447 574 438 C529 478 461 497 378 490 C292 483 204 445 164 389 C151 371 142 347 142 320 Z"/>
                <path class="lake" d="M635 276 C666 252 711 255 735 280 C709 301 664 301 635 276 Z"/>
                <path class="lake" d="M704 294 C735 278 776 287 796 311 C764 327 732 320 704 294 Z"/>
              </g>
              <text class="region-label" x="444" y="205">CANADA</text>
              <text class="region-label" x="414" y="386">UNITED STATES</text>
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
          <div><h3 data-latency-focus-title>Web Server Links</h3><span data-latency-focus-count></span></div>
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
    </div>
  </section>
  <?php endif; ?>

  <section class="two-col">
    <article class="card table-card">
      <div class="section-head"><h3>Archive Breakdown</h3></div>
      <table>
        <thead><tr><th>Type</th><th>Count</th></tr></thead>
        <tbody><?php foreach (($database['kinds'] ?? []) as $row): ?><tr><td><?= h($row['kind'] ?? '-') ?></td><td class="mono"><?= h(number_format((int)($row['count'] ?? 0))) ?></td></tr><?php endforeach; ?></tbody>
      </table>
      <div class="row"><span>Symbol index</span><strong><?= h(number_format((int)($summary['symbol_count'] ?? 0))) ?></strong></div>
      <?php if (!empty($database['error'])): ?><p class="muted"><?= h(redact($database['error'])) ?></p><?php endif; ?>
    </article>
    <article class="card table-card">
      <div class="section-head"><h3>Latest Signals</h3></div>
      <table>
        <thead><tr><th>Seen</th><th>Type</th><th>Symbol</th><th>Content</th></tr></thead>
        <tbody><?php foreach (($database['recent'] ?? []) as $row): ?><tr><td class="mono"><?= h(local_time_text($row['seen_at'] ?? $row['first_seen_at_utc'] ?? null)) ?></td><td><?= h($row['kind'] ?? '-') ?></td><td class="mono"><?= h($row['symbol'] ?? $row['underlying'] ?? '-') ?></td><td><?= h(redact($row['text'] ?? '')) ?></td></tr><?php endforeach; ?></tbody>
      </table>
    </article>
  </section>

  <section class="ops-grid">
    <article class="card detail-card"><div class="section-head"><h3>Bot Status</h3></div><div class="row"><span>Poll count</span><strong class="mono"><?= h($discord['poll_count'] ?? 'n/a') ?></strong></div><div class="row"><span>Publish count</span><strong class="mono"><?= h($discord['publish_count'] ?? 'n/a') ?></strong></div><div class="row"><span>Last event</span><strong><?= h(age_text($discord['last_event_at'] ?? null)) ?></strong></div></article>
    <article class="card detail-card"><div class="section-head"><h3>API Status</h3></div><div class="row"><span>Change count</span><strong class="mono"><?= h($api['change_count'] ?? 'n/a') ?></strong></div><div class="row"><span>HTTP</span><strong><?= h((string)($apiHealth['http_code'] ?? 'n/a')) ?></strong></div><div class="row"><span>Latest capture</span><strong><?= h(age_text($api['last_capture_at'] ?? null)) ?></strong></div></article>
    <article class="card detail-card journal-card">
      <div class="section-head"><h3>Discord Error Journal</h3><span class="badge <?= h($botJournalTone) ?>"><?= h($botJournalLabel) ?></span></div>
      <div class="journal-metrics"><div><span>Warnings / 24h</span><strong class="mono"><?= h((string)(int)($botJournal['warning_count'] ?? 0)) ?></strong></div><div><span>Errors / 24h</span><strong class="mono"><?= h((string)(int)($botJournal['error_count'] ?? 0)) ?></strong></div><div><span>Latest entry</span><strong><?= h(age_text($botJournal['latest_at'] ?? null)) ?></strong></div></div>
      <?php $journalEntries = is_array($botJournal['latest'] ?? null) ? $botJournal['latest'] : []; ?>
      <?php if ($journalEntries): ?><div class="journal-list"><?php foreach (array_reverse($journalEntries) as $entry): $entryTone = (int)($entry['priority'] ?? 4) <= 3 ? 'bad' : 'warn'; ?><div class="journal-entry <?= h($entryTone) ?>"><span class="journal-time mono"><?= h(local_time_text($entry['time'] ?? null)) ?></span><span class="badge <?= h($entryTone) ?>"><?= h($entryTone === 'bad' ? 'Error' : 'Warning') ?></span><p><?= h(redact($entry['message'] ?? '')) ?></p></div><?php endforeach; ?></div><?php else: ?><p class="muted">No Discord bot warnings or errors were recorded in the last 24 hours.</p><?php endif; ?>
    </article>
  </section>

  <footer>
    <?php if (!empty($theme['repository_url'])): ?><a href="<?= h((string)$theme['repository_url']) ?>" target="_blank" rel="noopener noreferrer">SignalOps Status</a><?php else: ?>SignalOps Status<?php endif; ?>
    <span> · </span>
    <span>PHP Based self-hosted monitoring</span>
  </footer>
</main>
</body>
</html>
