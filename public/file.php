<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/http.php';

$config = app_config();
send_cors($config['allowed_origins'] ?? [], 'GET, HEAD, OPTIONS');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, OPTIONS');
    respond(405, ['error' => 'Method not allowed.']);
}

$path = $_GET['p'] ?? '';
$signature = $_GET['s'] ?? '';
if (!is_string($path) || !is_string($signature)) {
    respond(400, ['error' => 'Invalid file request.']);
}

$expected = hash_hmac('sha256', $path, (string) $config['file_url_secret']);
if (!hash_equals($expected, $signature)) {
    respond(403, ['error' => 'Invalid file signature.']);
}

if (
    preg_match(
        '#^(avatars|chatImages|voiceNotes)/[A-Za-z0-9_-]{1,200}/[a-f0-9]{32}\.(png|jpg|webp|gif|webm|m4a|mp3|ogg|wav|aac|3gp)$#',
        $path,
        $matches
    ) !== 1
) {
    respond(400, ['error' => 'Invalid file path.']);
}

$mimeTypes = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'webm' => 'audio/webm',
    'm4a' => 'audio/mp4',
    'mp3' => 'audio/mpeg',
    'ogg' => 'audio/ogg',
    'wav' => 'audio/wav',
    'aac' => 'audio/aac',
    '3gp' => 'audio/3gpp'
];

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!isset($mimeTypes[$extension])) {
    respond(415, ['error' => 'Unsupported media type.']);
}

$root = storage_root($config, false);
$fullPath = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
if (
    $fullPath === false ||
    !is_file($fullPath) ||
    !str_starts_with($fullPath, $root . DIRECTORY_SEPARATOR)
) {
    respond(404, ['error' => 'File not found.']);
}

$size = filesize($fullPath);
if ($size === false) {
    respond(500, ['error' => 'Could not read the file.']);
}

$start = 0;
$end = $size - 1;
$status = 200;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $parts) === 1) {
    $startRaw = $parts[1];
    $endRaw = $parts[2];

    if ($startRaw === '' && $endRaw === '') {
        respond(416, ['error' => 'Invalid range.']);
    }

    if ($startRaw === '') {
        $suffix = (int) $endRaw;
        if ($suffix <= 0) {
            header('Content-Range: bytes */' . $size);
            respond(416, ['error' => 'Invalid range.']);
        }
        $start = max(0, $size - $suffix);
        $end = $size - 1;
    } else {
        $start = (int) $startRaw;
        $end = $endRaw === '' ? $size - 1 : (int) $endRaw;
        if ($start >= $size || $end < $start) {
            header('Content-Range: bytes */' . $size);
            respond(416, ['error' => 'Invalid range.']);
        }
        $end = min($end, $size - 1);
    }

    $status = 206;
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

http_response_code($status);
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');

if ($method === 'HEAD') {
    exit;
}

$handle = fopen($fullPath, 'rb');
if ($handle === false) {
    exit;
}

fseek($handle, $start);
$remaining = $end - $start + 1;

while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}

fclose($handle);
