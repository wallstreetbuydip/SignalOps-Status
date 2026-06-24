<?php
declare(strict_types=1);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_assoc_array(array $value): bool
{
    return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
}

function merge_config(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && is_assoc_array($value)) {
            $base[$key] = merge_config($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }
    return $base;
}

function redact($value): string
{
    $text = (string)($value ?? '');
    $text = preg_replace('/https?:\/\/[^\s]+/i', '[url]', $text) ?: $text;
    $text = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[private-host]', $text) ?: $text;
    $text = preg_replace('/(password|passwd|token|secret|key|authorization|api[_-]?key)=([^&\s]+)/i', '$1=[redacted]', $text) ?: $text;
    $text = preg_replace('/\b(Bearer|Bot)\s+[A-Za-z0-9._-]{12,}/i', '$1 [redacted]', $text) ?: $text;
    $text = preg_replace('/\b\d{12,}\b/', '[id]', $text) ?: $text;
    $text = preg_replace('/\b[A-Za-z0-9._-]{40,}\b/', '[redacted]', $text) ?: $text;
    return strlen($text) > 260 ? substr($text, 0, 257) . '...' : $text;
}

function now_iso(): string
{
    return date('Y-m-d H:i:s T');
}

function iso_time_attr($value): string
{
    $timestamp = timestamp_value($value);
    return $timestamp === false ? '' : gmdate('c', $timestamp);
}

function timestamp_value($value)
{
    if (!$value) {
        return false;
    }

    $text = trim((string)$value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/', $text)) {
        $text .= ' UTC';
    }

    return strtotime($text);
}

function age_text($value): string
{
    $timestamp = timestamp_value($value);
    if ($timestamp === false) {
        return $value ? redact($value) : 'n/a';
    }

    $delta = max(0, time() - $timestamp);
    if ($delta < 60) {
        return $delta . 's ago';
    }
    if ($delta < 3600) {
        return floor($delta / 60) . 'm ago';
    }
    if ($delta < 86400) {
        return floor($delta / 3600) . 'h ago';
    }
    return floor($delta / 86400) . 'd ago';
}

function local_time_text($value): string
{
    $timestamp = timestamp_value($value);
    if ($timestamp === false) {
        return redact($value);
    }
    return date('Y-m-d H:i:s T', $timestamp);
}

function format_bytes($bytes): string
{
    if ($bytes === null || $bytes === false) {
        return 'n/a';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return ($value >= 10 || $index === 0 ? number_format($value, 0) : number_format($value, 1)) . ' ' . $units[$index];
}

function percent($value): string
{
    return $value === null ? 'n/a' : number_format((float)$value, 1) . '%';
}

function compact_percent($value): string
{
    if ($value === null) {
        return 'n/a';
    }

    $number = (float)$value;
    return number_format($number, abs($number - round($number)) < 0.05 ? 0 : 1) . '%';
}

function sla_percent($value): string
{
    return $value === null ? 'n/a' : number_format((float)$value, 2) . '%';
}

function format_latency_ms($value): string
{
    if ($value === null || $value === false) {
        return 'n/a';
    }
    $latency = (float)$value;
    return ($latency < 10 ? number_format($latency, 1) : number_format($latency, 0)) . ' ms';
}

function status_tone($ok): string
{
    if ($ok === true) {
        return 'ok';
    }
    if ($ok === false) {
        return 'bad';
    }
    return 'warn';
}

function journal_status_tone(array $journal): string
{
    if ((int)($journal['error_count'] ?? 0) > 0 || ($journal['ok'] ?? null) === false) {
        return 'bad';
    }
    if ((int)($journal['warning_count'] ?? 0) > 0) {
        return 'warn';
    }
    return ($journal['ok'] ?? null) === true ? 'ok' : 'warn';
}

function journal_status_label(array $journal): string
{
    if ((int)($journal['error_count'] ?? 0) > 0) {
        return 'Errors detected';
    }
    if ((int)($journal['warning_count'] ?? 0) > 0) {
        return 'Warnings present';
    }
    return ($journal['ok'] ?? null) === true ? 'Quiet' : 'Not available';
}

function uptime_text($seconds): string
{
    if ($seconds === null) {
        return 'n/a';
    }
    $seconds = (int)$seconds;
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return "{$days}d {$hours}h";
    }
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    }
    return "{$minutes}m";
}

function gauge(string $label, $pct, string $hint = ''): string
{
    $value = $pct === null ? 0 : max(0, min(100, (float)$pct));
    $tone = $value >= 85 ? 'bad' : ($value >= 70 ? 'warn' : 'ok');
    return '<div class="gauge ' . $tone . '" style="--pct:' . h((string)$value) . '">'
        . '<div class="gauge-ring"><strong>' . h(percent($pct)) . '</strong></div>'
        . '<span>' . h($label) . '</span>'
        . ($hint !== '' ? '<small>' . h($hint) . '</small>' : '')
        . '</div>';
}

function meter_bar(string $label, $pct): string
{
    $value = $pct === null ? 0 : max(0, min(100, (float)$pct));
    $tone = $value >= 85 ? 'bad' : ($value >= 70 ? 'warn' : 'ok');
    return '<div class="meter-row ' . h($tone) . '">'
        . '<span>' . h($label) . '</span>'
        . '<div class="resource-meter"><i style="--w: ' . h((string)$value) . '%"></i></div>'
        . '<strong>' . h(compact_percent($pct)) . '</strong>'
        . '</div>';
}

function service_pill(string $service, string $state): string
{
    $tone = $state === 'active' ? 'ok' : ($state === 'inactive' ? 'warn' : 'bad');
    return '<span class="pill ' . $tone . '">' . h($service) . ': ' . h($state) . '</span>';
}

function endpoint_display_state(array $health): array
{
    if (($health['ok'] ?? null) === true) {
        return ['tone' => 'ok', 'label' => 'OK'];
    }
    if (($health['ok'] ?? null) === false) {
        return ['tone' => 'bad', 'label' => 'Offline'];
    }
    if (($health['http_code'] ?? null) && (int)$health['http_code'] < 500) {
        return ['tone' => 'warn', 'label' => 'Reachable'];
    }
    return ['tone' => 'warn', 'label' => 'Not configured'];
}
