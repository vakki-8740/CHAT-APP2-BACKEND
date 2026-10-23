<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/http.php';

set_exception_handler(static function (Throwable $error): void {
    error_log('chat-api upload: ' . $error->getMessage());
    respond(500, ['error' => 'Server error while uploading.']);
});

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$config = app_config();
send_cors($config['allowed_origins'] ?? []);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    respond(405, ['error' => 'Method not allowed.']);
}

$uid = firebase_uid_from_token($config, bearer_token());
if ($uid === null) {
    header('WWW-Authenticate: Bearer');
    respond(401, ['error' => 'Sign in is required to upload files.']);
}

$folder = trim((string) ($_POST['folder'] ?? ''));
if (preg_match('#^(avatars|chatImages|voiceNotes)/([A-Za-z0-9_-]{1,200})$#', $folder, $matches) !== 1) {
    respond(400, ['error' => 'Invalid upload folder.']);
}

$kind = $matches[1];
$key = $matches[2];

if ($kind === 'avatars') {
    if (!hash_equals($uid, $key)) {
        respond(403, ['error' => 'You can only upload your own profile photo.']);
    }
} else {
    $participants = explode('_', $key);
    if (count($participants) !== 2 || !in_array($uid, $participants, true)) {
        respond(403, ['error' => 'You are not a participant in this chat.']);
    }
}

$imageTypes = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif'
];

$audioTypes = [
    'audio/webm' => 'webm',
    'video/webm' => 'webm',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/m4a' => 'm4a',
    'audio/mpeg' => 'mp3',
    'audio/mp3' => 'mp3',
    'audio/ogg' => 'ogg',
    'application/ogg' => 'ogg',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/wave' => 'wav',
    'audio/aac' => 'aac',
    'audio/3gpp' => '3gp'
];

$allowedTypes = $kind === 'voiceNotes' ? $audioTypes : $imageTypes;
$sizeLimit = $kind === 'voiceNotes'
    ? (int) ($config['max_audio_bytes'] ?? 15 * 1024 * 1024)
    : (int) ($config['max_image_bytes'] ?? 10 * 1024 * 1024);

$upload = $_FILES['file'] ?? null;
if (!is_array($upload)) {
    respond(400, ['error' => 'No file was uploaded.']);
}

$errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
if ($errorCode !== UPLOAD_ERR_OK) {
    $tooLarge = in_array($errorCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    respond($tooLarge ? 413 : 400, [
        'error' => $tooLarge ? 'File is larger than the server allows.' : 'Upload failed.'
    ]);
}

$tmpPath = (string) ($upload['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    respond(400, ['error' => 'Upload could not be verified.']);
}

$size = filesize($tmpPath);
if ($size === false || $size <= 0) {
    respond(400, ['error' => 'Uploaded file is empty.']);
}
if ($sizeLimit <= 0 || $size > $sizeLimit) {
    respond(413, ['error' => 'File is too large.']);
}

if (!class_exists('finfo')) {
    respond(500, ['error' => 'Server is missing the PHP fileinfo extension.']);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
$mime = is_string($mime) ? $mime : '';
if (!isset($allowedTypes[$mime])) {
    respond(415, ['error' => 'This file type is not allowed.']);
}

$extension = $allowedTypes[$mime];
$root = storage_root($config);
$directory = $root . DIRECTORY_SEPARATOR . $kind . DIRECTORY_SEPARATOR . $key;

if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    respond(500, ['error' => 'Could not create the storage folder.']);
}

$realDirectory = realpath($directory);
if (
    $realDirectory === false ||
    !str_starts_with($realDirectory, $root . DIRECTORY_SEPARATOR)
) {
    respond(500, ['error' => 'Invalid storage path.']);
}

$filename = bin2hex(random_bytes(16)) . '.' . $extension;
$target = $realDirectory . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($tmpPath, $target)) {
    respond(500, ['error' => 'Could not save the uploaded file.']);
}
@chmod($target, 0644);

$relativePath = $kind . '/' . $key . '/' . $filename;
respond(201, [
    'url' => signed_file_url($config, $relativePath),
    'path' => $relativePath
]);
