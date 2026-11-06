<?php
/** Small helpers for pages: formatting, flash messages, redirects, pagination. */

function money($paise): string {
    $paise = (int)$paise;
    $sym = setting('currency_symbol');
    return $paise % 100 === 0 ? $sym . number_format($paise / 100) : $sym . number_format($paise / 100, 2);
}

function local_dt(?string $utc, bool $time = true): string {
    if (!$utc) return '—';
    $ts = utc_ts($utc) + setting('timezone_offset_minutes') * 60;
    return gmdate($time ? 'd M Y, h:i A' : 'd M Y', $ts);
}

/** Local (display timezone) calendar date as Y-m-d, for now or a UTC datetime. */
function local_date(?string $utc = null, string $shift = ''): string {
    $ts = ($utc ? utc_ts($utc) : time()) + setting('timezone_offset_minutes') * 60;
    if ($shift !== '') $ts = strtotime($shift, $ts);
    return gmdate('Y-m-d', $ts);
}

function time_ago(?string $utc): string {
    if (!$utc) return 'never';
    $d = time() - utc_ts($utc);
    if ($d < 0) {
        $d = -$d;
        $fmt = fn($n, $u) => "in $n $u" . ($n == 1 ? '' : 's');
    } else {
        $fmt = fn($n, $u) => "$n $u" . ($n == 1 ? '' : 's') . ' ago';
    }
    if ($d < 60) return $d < 10 ? 'just now' : $fmt($d, 'sec');
    if ($d < 3600) return $fmt(intdiv($d, 60), 'min');
    if ($d < 86400) return $fmt(intdiv($d, 3600), 'hour');
    if ($d < 86400 * 30) return $fmt(intdiv($d, 86400), 'day');
    return local_dt($utc, false);
}

function badge(string $status): string {
    $map = [
        'active' => 'green', 'paid' => 'green', 'sent' => 'green', 'verified' => 'green', 'answered' => 'blue',
        'issued' => 'blue', 'open' => 'blue', 'created' => 'gray', 'queued' => 'gray', 'logged' => 'gray',
        'pending_review' => 'amber', 'suspended' => 'amber', 'expiring' => 'amber', 'normal' => 'gray',
        'expired' => 'red', 'revoked' => 'red', 'banned' => 'red', 'blocked' => 'red', 'failed' => 'red',
        'rejected' => 'red', 'deleted' => 'red', 'refunded' => 'purple', 'cancelled' => 'gray', 'closed' => 'gray',
        'high' => 'amber', 'urgent' => 'red', 'low' => 'gray', 'superadmin' => 'purple', 'admin' => 'blue',
        'support' => 'green', 'viewer' => 'gray', 'unverified' => 'amber', 'removed' => 'gray',
    ];
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge badge-' . ($map[$status] ?? 'gray') . '">' . e($label) . '</span>';
}

function flash_cookie(): string {
    return defined('HM_PORTAL') && HM_PORTAL === 'admin' ? 'hm_aflash' : 'hm_uflash';
}

function flash(string $msg, string $kind = 'success'): void {
    $list = flash_peek();
    $list[] = [$kind, $msg];
    set_cookie(flash_cookie(), sign_value(base64_encode(json_encode($list))), 0);
}

function flash_peek(): array {
    $v = unsign_value($_COOKIE[flash_cookie()] ?? null);
    $list = $v ? json_decode(base64_decode($v), true) : [];
    return is_array($list) ? $list : [];
}

function flash_take(): array {
    $list = flash_peek();
    if ($list) set_cookie(flash_cookie(), '', time() - 3600);
    return $list;
}

function redirect(string $url): never {
    // Only allow local redirects or our own portal URLs.
    if (preg_match('#^https?://#i', $url) && !str_starts_with($url, site_url()) && !str_starts_with($url, admin_url())
        && !str_starts_with($url, 'https://accounts.google.com/')) {
        $url = './';
    }
    header('Location: ' . $url, true, 303);
    exit;
}

function json_out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function input(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function input_int(string $key, int $default = 0): int {
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function qs(array $changes): string {
    $params = array_merge($_GET, $changes);
    foreach ($params as $k => $v) if ($v === null || $v === '') unset($params[$k]);
    return '?' . http_build_query($params);
}

/** Returns [limit, offset, page, pages]. */
function paginate(int $total, int $per = 25): array {
    $pages = max(1, (int)ceil($total / $per));
    $page = min($pages, max(1, input_int('page', 1)));
    return [$per, ($page - 1) * $per, $page, $pages];
}

function pager(int $page, int $pages, int $total): string {
    if ($pages <= 1) return '<div class="pager"><span class="muted">' . number_format($total) . ' total</span></div>';
    $h = '<div class="pager"><span class="muted">' . number_format($total) . ' total</span>';
    if ($page > 1) $h .= '<a class="btn btn-sm" href="' . e(qs(['page' => $page - 1])) . '">‹ Prev</a>';
    $h .= "<span>Page $page of $pages</span>";
    if ($page < $pages) $h .= '<a class="btn btn-sm" href="' . e(qs(['page' => $page + 1])) . '">Next ›</a>';
    return $h . '</div>';
}

function short_agent(string $ua): string {
    if (str_contains($ua, 'Edg/')) $b = 'Edge';
    elseif (str_contains($ua, 'OPR/')) $b = 'Opera';
    elseif (str_contains($ua, 'Chrome/')) $b = 'Chrome';
    elseif (str_contains($ua, 'Firefox/')) $b = 'Firefox';
    elseif (str_contains($ua, 'Safari/')) $b = 'Safari';
    elseif (str_contains($ua, 'InterviewLens')) $b = 'InterviewLens app';
    elseif (str_contains($ua, 'HireMind')) $b = 'HireMind app';
    else $b = 'Browser';
    $o = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'iPhone') ? 'iPhone'
        : (str_contains($ua, 'Mac OS') ? 'Mac' : (str_contains($ua, 'Linux') ? 'Linux' : ''))));
    return $o ? "$b on $o" : $b;
}

function csv_download(string $filename, array $header, iterable $rows): never {
    end_buffers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $r) {
        // Stop spreadsheet formula injection.
        $r = array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($r));
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

function initials(string $name, string $email = ''): string {
    $src = trim($name) ?: $email;
    $parts = preg_split('/[\s@._-]+/', $src);
    return strtoupper(substr($parts[0] ?? '?', 0, 1) . substr($parts[1] ?? '', 0, 1));
}
