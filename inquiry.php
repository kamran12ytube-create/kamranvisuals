<?php
/**
 * Quote-form endpoint for the plain-HTML Hostinger build.
 *
 * Mirrors src/app/api/inquiry/route.ts: same validation, spam checks, rate
 * limit and JSON responses, so the form behaves the same. Email is sent with
 * Hostinger's PHP mail() from the site's own mailbox; the visitor is only the
 * Reply-To. Inquiry contents are never written to disk or logs.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_BODY_BYTES = 16 * 1024;
const MIN_FILL_MS = 3000;
const MAX_FILL_MS = 24 * 60 * 60 * 1000;
const LIMIT_HOUR = 5;
const LIMIT_DAY = 20;

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $code, string $message, int $status, array $extra = []): void
{
    respond($status, array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra));
}

$config = json_decode((string) @file_get_contents(__DIR__ . '/inquiry-config.json'), true);
if (!is_array($config)) {
    fail('unavailable', 'Online submission is not available at the moment. Please email or WhatsApp your inquiry instead.', 503);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(200, ['enabled' => true]);
}

if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    fail('bad_request', 'Unsupported request format.', 415);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES) {
    fail('too_large', 'Your message is too long. Please shorten it and try again.', 413);
}
$raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if (strlen($raw) > MAX_BODY_BYTES) {
    fail('too_large', 'Your message is too long. Please shorten it and try again.', 413);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    fail('bad_request', 'The request could not be read.', 400);
}

// --- Envelope: honeypot, timing, submission id ------------------------------
$honeypot = $body['website'] ?? '';
$startedAt = $body['startedAt'] ?? null;
$submissionId = $body['submissionId'] ?? '';
if (!is_string($honeypot) || strlen($honeypot) > 200 || !is_int($startedAt) || $startedAt <= 0
    || !is_string($submissionId) || !preg_match('/^[a-zA-Z0-9-]{8,64}$/', $submissionId)) {
    fail('bad_request', 'The request could not be read. Please refresh the page and try again.', 400);
}
if ($honeypot !== '') {
    fail('spam', 'Your inquiry could not be sent. Please email or WhatsApp instead.', 400);
}
$elapsed = (int) floor(microtime(true) * 1000) - $startedAt;
if ($elapsed < MIN_FILL_MS) {
    fail('too_fast', 'That was quick! Please check your details and press send again.', 400);
}
if ($elapsed > MAX_FILL_MS) {
    fail('bad_request', 'This form has been open for a long time. Please refresh the page and try again.', 400);
}

// --- Fields -------------------------------------------------------------------
$values = fn(array $list): array => array_map(fn($o) => is_array($o) ? $o['value'] : $o, $list);
$labelFor = function (array $list, string $value): string {
    foreach ($list as $o) {
        if ($o['value'] === $value) return $o['label'];
    }
    return $value;
};
$errors = [];
$str = function (string $key) use ($body): string {
    $v = $body[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};
$line = function (string $key, int $max, bool $required = false, int $min = 0, string $requiredMsg = '') use ($str, &$errors): string {
    $v = $str($key);
    if ($required && mb_strlen($v) < max(1, $min)) $errors[$key] ??= $requiredMsg;
    elseif (mb_strlen($v) > $max) $errors[$key] ??= "Please keep this under {$max} characters.";
    elseif (preg_match('/[\x00-\x1f\x7f]/', $v)) $errors[$key] ??= 'Please remove line breaks or special characters.';
    return $v;
};
$enum = function (string $key, array $allowed, bool $required = false, string $msg = '') use ($str, &$errors): string {
    $v = $str($key);
    if ($v === '' && !$required) return '';
    if (!in_array($v, $allowed, true)) $errors[$key] ??= $msg ?: 'Please choose an option from the list.';
    return $v;
};

$d = [];
$d['name'] = $line('name', 100, true, 2, 'Please enter your name.');
$d['email'] = $str('email');
if (strlen($d['email']) > 254 || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}
$d['company'] = $line('company', 120);
$d['role'] = $enum('role', $values($config['roles']));
$d['location'] = $line('location', 120);
$d['service'] = $enum('service', $values($config['services']), true, 'Please choose the service you need.');
$d['package'] = $enum('package', $values($config['packages']));
$d['projectType'] = $enum('projectType', $values($config['projectTypes']), true, 'Please choose a project type.');
$d['outputs'] = $line('outputs', 300);
$d['videoDuration'] = $enum('videoDuration', $values($config['videoDurations']));
$d['deadline'] = $line('deadline', 100);
$d['budgetMode'] = $str('budgetMode') === 'range' ? 'range' : 'not-sure';
$d['budgetCurrency'] = $enum('budgetCurrency', $config['currencies']);
$d['budgetRange'] = $line('budgetRange', 60);
$d['details'] = $str('details');
if (mb_strlen($d['details']) < 20) {
    $errors['details'] = 'Please describe your project in a little more detail (at least 20 characters).';
} elseif (mb_strlen($d['details']) > 5000) {
    $errors['details'] = 'Please keep the project details under 5,000 characters.';
}
$d['referenceUrl'] = $str('referenceUrl');
if ($d['referenceUrl'] !== '') {
    $u = parse_url($d['referenceUrl']);
    if (mb_strlen($d['referenceUrl']) > 500) {
        $errors['referenceUrl'] = 'Please use a shorter link.';
    } elseif (!$u || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || empty($u['host']) || isset($u['user']) || isset($u['pass'])) {
        $errors['referenceUrl'] = 'Please enter a full link starting with https:// (without any password in it).';
    }
}
if (($body['privacyAck'] ?? false) !== true) {
    $errors['privacyAck'] = 'Please confirm so I can reply to your inquiry.';
}
$d['marketingConsent'] = ($body['marketingConsent'] ?? false) === true;
if ($d['budgetMode'] === 'range') {
    if ($d['budgetCurrency'] === '') $errors['budgetCurrency'] ??= 'Please choose a currency.';
    if ($d['budgetRange'] === '') $errors['budgetRange'] ??= 'Please enter a budget range.';
} else {
    $d['budgetCurrency'] = $d['budgetRange'] = '';
}
if (!in_array($d['service'], $config['videoServices'], true) && !in_array($d['package'], $config['videoPackages'], true)) {
    $d['videoDuration'] = '';
}
if ($errors) {
    fail('invalid', 'Please correct the highlighted fields.', 422, ['fieldErrors' => $errors]);
}

// --- Rate limit and duplicate protection (hashed IP only; no inquiry data stored) ---
$store = sys_get_temp_dir() . '/kv-inquiry-' . substr(hash('sha256', __DIR__), 0, 12);
@mkdir($store, 0700, true);
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipKey = substr(hash('sha256', __DIR__ . ':' . $ip), 0, 32);
$now = time();
$hitsFile = "$store/ip-$ipKey.json";
$hits = array_values(array_filter((array) json_decode((string) @file_get_contents($hitsFile), true), fn($t) => is_int($t) && $t > $now - 86400));
$lastHour = count(array_filter($hits, fn($t) => $t > $now - 3600));
if ($lastHour >= LIMIT_HOUR || count($hits) >= LIMIT_DAY) {
    header('Retry-After: 3600');
    fail('rate_limited', 'Too many inquiries have been sent from this connection. Please wait a little and try again, or use email or WhatsApp.', 429);
}

// Same reference format as src/lib/inquiry/reference.ts (MK-YYMMDD-XXXXX).
$h = 2166136261;
foreach (str_split($submissionId) as $ch) {
    $h ^= ord($ch);
    $h = ($h * 16777619) & 0xFFFFFFFF;
}
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $alphabet[$h % 32];
    $h = intdiv($h, 32);
}
$reference = 'MK-' . gmdate('ymd') . '-' . $code;

// A retry of the same submission (e.g. after a network error) is not sent twice.
$sentFile = "$store/sent-" . hash('sha256', $submissionId);
if (is_file($sentFile)) {
    respond(200, ['ok' => true, 'reference' => $reference]);
}

// --- Email --------------------------------------------------------------------
$headerSafe = fn(string $v, int $max = 150): string => mb_substr(trim(preg_replace('/\s+/', ' ', preg_replace('/[\x00-\x1f\x7f]+/', ' ', $v))), 0, $max);
$esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$serviceLabel = $labelFor($config['services'], $d['service']);
$rows = [
    ['Reference', $reference],
    ['Name', $d['name']],
    ['Email', $d['email']],
    ['Company', $d['company']],
    ['Client role', $d['role'] ? $labelFor($config['roles'], $d['role']) : ''],
    ['Project location', $d['location']],
    ['Service', $serviceLabel],
    ['Package', $d['package'] ? $labelFor($config['packages'], $d['package']) : ''],
    ['Project type', $labelFor($config['projectTypes'], $d['projectType'])],
    ['Outputs / views', $d['outputs']],
    ['Video duration', $d['videoDuration'] ? $labelFor($config['videoDurations'], $d['videoDuration']) : ''],
    ['Preferred deadline', $d['deadline']],
    ['Budget', $d['budgetMode'] === 'range' ? trim("{$d['budgetRange']} {$d['budgetCurrency']}") : 'Not sure yet'],
    ['Reference link (not opened by the website)', $d['referenceUrl']],
    ['Privacy acknowledgement', 'Yes'],
    ['Marketing updates', $d['marketingConsent'] ? 'Yes — opted in' : 'No'],
];
$rows = array_filter($rows, fn($r) => $r[1] !== '');
$subject = $headerSafe("Quote inquiry {$reference} — {$serviceLabel} — {$d['name']}");

$text = "New quote inquiry from the {$config['brand']} website\n\n";
foreach ($rows as [$k, $v]) $text .= "$k: $v\n";
$text .= "\nProject details:\n{$d['details']}\n\nReply to this email to respond directly to the sender.\n";

$td = 'padding:8px 12px;border-bottom:1px solid #e7ded0;vertical-align:top;font:14px/1.5 Arial,sans-serif;';
$html = '<!doctype html><html><body style="margin:0;background:#f6f2eb;padding:24px;color:#1e1c1a">'
    . '<table role="presentation" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #d9d0c3;border-collapse:collapse">'
    . '<tr><td style="padding:20px 24px;border-bottom:2px solid #8a5a2b;font:600 18px/1.3 Georgia,serif">New quote inquiry · ' . $esc($reference) . '</td></tr>'
    . '<tr><td style="padding:8px 12px"><table role="presentation" width="100%" style="border-collapse:collapse">';
foreach ($rows as [$k, $v]) {
    $html .= "<tr><td style=\"{$td}color:#6b645c;width:38%\">" . $esc($k) . "</td><td style=\"{$td}\">" . $esc($v) . '</td></tr>';
}
$html .= '</table></td></tr>'
    . '<tr><td style="padding:16px 24px 8px;font:600 14px Arial,sans-serif">Project details</td></tr>'
    . '<tr><td style="padding:0 24px 24px;font:14px/1.6 Arial,sans-serif;white-space:pre-wrap">' . $esc($d['details']) . '</td></tr>'
    . '<tr><td style="padding:14px 24px;background:#efe8dd;font:12px/1.5 Arial,sans-serif;color:#4a4540">Reply to this email to respond directly to the sender. Links were submitted by the visitor and have not been checked — open with care.</td></tr>'
    . '</table></body></html>';

$to = $config['to'];
$boundary = 'kv-' . bin2hex(random_bytes(12));
$fromName = '=?UTF-8?B?' . base64_encode($config['brand'] . ' Website') . '?=';
$replyName = '=?UTF-8?B?' . base64_encode($headerSafe($d['name'], 100)) . '?=';
$headers = implode("\r\n", [
    "From: {$fromName} <{$to}>",
    "Reply-To: {$replyName} <{$d['email']}>",
    'MIME-Version: 1.0',
    "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
    'X-Inquiry-Reference: ' . $reference,
]);
$message = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($text))
    . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($html))
    . "--{$boundary}--\r\n";

$sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers, '-f' . $to);
if (!$sent) {
    fail('provider_error', 'Your inquiry could not be sent right now. Nothing was submitted — please try again shortly, or email or WhatsApp instead.', 502);
}

$hits[] = $now;
@file_put_contents($hitsFile, json_encode($hits), LOCK_EX);
@touch($sentFile);
respond(200, ['ok' => true, 'reference' => $reference]);
