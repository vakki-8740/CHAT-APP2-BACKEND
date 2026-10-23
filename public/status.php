<?php

declare(strict_types=1);

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

require dirname(__DIR__) . '/src/http.php';

function add_check(array &$checks, string $id, string $label, string $status, string $message): void
{
    $checks[] = [
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'message' => $message
    ];
}

function parse_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    if ($unit === 'g') {
        $number *= 1024 * 1024 * 1024;
    } elseif ($unit === 'm') {
        $number *= 1024 * 1024;
    } elseif ($unit === 'k') {
        $number *= 1024;
    }

    return (int) $number;
}

function storage_stats(string $directory): array
{
    $files = 0;
    $bytes = 0;

    if (!is_dir($directory)) {
        return ['files' => 0, 'bytes' => 0];
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        $prefixLength = strlen($directory) + 1;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), $prefixLength));
            $folder = explode('/', $relative)[0] ?? '';
            if (!in_array($folder, ['avatars', 'chatImages', 'voiceNotes'], true)) {
                continue;
            }

            $files++;
            $bytes += $file->getSize();
            if ($files >= 50000) {
                break;
            }
        }
    } catch (Throwable $error) {
        return ['files' => 0, 'bytes' => 0];
    }

    return ['files' => $files, 'bytes' => $bytes];
}

function host_only(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);

    return is_string($host) && $host !== '' ? $host : '';
}

function is_local_host(string $host): bool
{
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function check_firebase_lookup(array $config): array
{
    if (!function_exists('curl_init')) {
        return ['error', 'The PHP cURL extension is missing.'];
    }

    $lookupUrl = (string) ($config['auth_lookup_url'] ?? '');
    $apiKey = (string) ($config['firebase_api_key'] ?? '');
    if ($lookupUrl === '' || $apiKey === '') {
        return ['error', 'Set firebase_api_key and auth_lookup_url in config.php.'];
    }

    $separator = str_contains($lookupUrl, '?') ? '&' : '?';
    $url = $lookupUrl . $separator . 'key=' . rawurlencode($apiKey);

    $handle = curl_init($url);
    if ($handle === false) {
        return ['error', 'Could not start the Firebase Auth lookup request.'];
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['idToken' => 'dashboard-check']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 7,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if (!is_string($body)) {
        return ['error', 'Cannot reach Firebase Auth lookup: ' . ($curlError ?: 'network error')];
    }

    $decoded = json_decode($body, true);
    $message = '';
    if (is_array($decoded)) {
        $message = (string) ($decoded['error']['message'] ?? '');
    }

    $keyProblem = stripos($message, 'api key') !== false
        || stripos($message, 'API_KEY') !== false;

    if ($status === 400 && $keyProblem) {
        return ['error', 'Firebase API key was rejected. Check firebase_api_key and its API restrictions.'];
    }
    if (in_array($status, [400, 401, 403], true)) {
        return ['ok', 'Firebase Auth lookup is reachable and rejected the test token as expected.'];
    }
    if ($status >= 200 && $status < 300) {
        return ['warn', 'Firebase Auth lookup responded unexpectedly; verify the lookup URL.'];
    }

    return ['warn', 'Firebase Auth lookup returned HTTP ' . $status . '.'];
}

$checks = [];
$config = null;
$configError = '';
$configSource = backend_config_source();

if ($configSource === 'none') {
    $configError = 'config.php is missing and no CHAT_* environment variables were found.';
} else {
    try {
        $config = load_backend_config();
    } catch (Throwable $error) {
        $configError = 'Backend configuration could not be loaded.';
    }
}

if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    add_check($checks, 'php', 'PHP version', 'ok', 'PHP ' . PHP_VERSION . ' is supported.');
} else {
    add_check($checks, 'php', 'PHP version', 'error', 'PHP 8.0 or newer is required.');
}

foreach (['curl', 'fileinfo', 'json', 'openssl'] as $extension) {
    if (extension_loaded($extension)) {
        add_check($checks, 'ext-' . $extension, 'PHP ' . $extension . ' extension', 'ok', 'Loaded.');
    } else {
        add_check(
            $checks,
            'ext-' . $extension,
            'PHP ' . $extension . ' extension',
            'error',
            'Missing. Install php-' . $extension . ' on the server.'
        );
    }
}

if ($configError !== '') {
    add_check($checks, 'config', 'Configuration', 'error', $configError);
} else {
    add_check(
        $checks,
        'config',
        'Configuration',
        'ok',
        $configSource === 'config.php'
            ? 'config.php loaded.'
            : 'Environment variables loaded.'
    );

    $required = ['firebase_api_key', 'auth_lookup_url', 'public_base_url', 'storage_dir'];
    $missing = [];
    foreach ($required as $key) {
        if (trim((string) ($config[$key] ?? '')) === '') {
            $missing[] = $key;
        }
    }
    if ($missing === []) {
        add_check($checks, 'config-values', 'Required values', 'ok', 'All required settings are present.');
    } else {
        add_check(
            $checks,
            'config-values',
            'Required values',
            'error',
            'Missing: ' . implode(', ', $missing) . '.'
        );
    }

    $secret = (string) ($config['file_url_secret'] ?? '');
    if (strlen($secret) >= 32 && !str_contains($secret, 'CHANGE_ME')) {
        add_check($checks, 'secret', 'File URL secret', 'ok', 'Strong secret is configured.');
    } else {
        add_check(
            $checks,
            'secret',
            'File URL secret',
            'error',
            'Set a random file_url_secret of at least 32 characters.'
        );
    }

    $origins = $config['allowed_origins'] ?? null;
    if (!is_array($origins) || $origins === []) {
        add_check(
            $checks,
            'cors',
            'CORS origins',
            'error',
            'Add your frontend origin(s) to allowed_origins.'
        );
    } else {
        $invalidOrigins = array_filter($origins, static function ($origin): bool {
            return !is_string($origin) || filter_var($origin, FILTER_VALIDATE_URL) === false;
        });
        if ($invalidOrigins !== []) {
            add_check(
                $checks,
                'cors',
                'CORS origins',
                'warn',
                'Some allowed_origins entries are not valid absolute URLs.'
            );
        } else {
            add_check(
                $checks,
                'cors',
                'CORS origins',
                'ok',
                count($origins) . ' frontend origin(s) allowed.'
            );
        }

        $requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($requestOrigin !== '' && !in_array($requestOrigin, $origins, true)) {
            add_check(
                $checks,
                'cors-request',
                'Current request origin',
                'warn',
                'This origin is not in allowed_origins: ' . $requestOrigin
            );
        }
    }

    $baseUrl = (string) ($config['public_base_url'] ?? '');
    $baseHost = host_only($baseUrl);
    if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || $baseHost === '') {
        add_check($checks, 'base-url', 'Public backend URL', 'error', 'Set a valid public_base_url.');
    } elseif (str_starts_with(strtolower($baseUrl), 'http://') && !is_local_host($baseHost)) {
        add_check(
            $checks,
            'base-url',
            'Public backend URL',
            'warn',
            'Use HTTPS for public_base_url in production.'
        );
    } else {
        add_check($checks, 'base-url', 'Public backend URL', 'ok', 'Public URL looks valid.');
    }

    if (filter_var((string) ($config['auth_lookup_url'] ?? ''), FILTER_VALIDATE_URL) === false) {
        add_check($checks, 'auth-url', 'Auth lookup URL', 'error', 'Set a valid auth_lookup_url.');
    } else {
        add_check($checks, 'auth-url', 'Auth lookup URL', 'ok', 'Firebase Auth lookup URL is configured.');
        [$authStatus, $authMessage] = check_firebase_lookup($config);
        add_check($checks, 'firebase-auth', 'Firebase token validation', $authStatus, $authMessage);
    }
}

$storageDirectory = (string) ($config['storage_dir'] ?? (dirname(__DIR__) . '/storage'));
$storageReal = realpath($storageDirectory);

if ($storageReal === false || !is_dir($storageReal)) {
    add_check($checks, 'storage', 'Storage directory', 'error', 'Storage directory was not found.');
} elseif (!is_writable($storageReal)) {
    add_check($checks, 'storage', 'Storage directory', 'error', 'Storage directory is not writable.');
} else {
    add_check($checks, 'storage', 'Storage directory', 'ok', 'Storage directory is writable.');
}

foreach (['upload.php', 'file.php', 'health.php'] as $endpointFile) {
    if (is_file(__DIR__ . '/' . $endpointFile)) {
        add_check(
            $checks,
            'file-' . $endpointFile,
            $endpointFile,
            'ok',
            'Endpoint file is present.'
        );
    } else {
        add_check(
            $checks,
            'file-' . $endpointFile,
            $endpointFile,
            'error',
            'Endpoint file is missing from the public directory.'
        );
    }
}

$maxImage = (int) ($config['max_image_bytes'] ?? 8 * 1024 * 1024);
$maxAudio = (int) ($config['max_audio_bytes'] ?? 15 * 1024 * 1024);
$uploadMax = parse_ini_bytes((string) ini_get('upload_max_filesize'));
$postMax = parse_ini_bytes((string) ini_get('post_max_size'));
$limitProblem = false;
$limitMessages = [];

if ($uploadMax > 0 && $uploadMax < $maxAudio) {
    $limitProblem = true;
    $limitMessages[] = 'upload_max_filesize is smaller than max_audio_bytes.';
}
if ($postMax > 0 && $postMax < $maxAudio + 1024 * 1024) {
    $limitProblem = true;
    $limitMessages[] = 'post_max_size is too small for a maximum-size voice note.';
}

add_check(
    $checks,
    'limits',
    'PHP upload limits',
    $limitProblem ? 'warn' : 'ok',
    $limitProblem
        ? implode(' ', $limitMessages)
        : 'PHP limits allow the configured image/voice sizes.'
);

$checkStatuses = [];
foreach ($checks as $check) {
    $checkStatuses[$check['id']] = $check['status'];
}

$allOk = static function (array $ids) use ($checkStatuses): bool {
    foreach ($ids as $id) {
        if (($checkStatuses[$id] ?? 'error') !== 'ok') {
            return false;
        }
    }

    return true;
};

$hasProblem = static function (array $ids) use ($checkStatuses): bool {
    foreach ($ids as $id) {
        if (($checkStatuses[$id] ?? 'error') === 'error') {
            return true;
        }
    }

    return false;
};

$mediaIds = ['config', 'config-values', 'secret', 'storage', 'file-upload.php', 'file-file.php', 'firebase-auth'];
$mediaReady = $allOk($mediaIds);
$mediaProblem = $hasProblem($mediaIds);

$features = [
    [
        'label' => 'Backend API',
        'status' => $configError === '' ? 'ok' : 'error',
        'detail' => $configError === '' ? 'Running and configured.' : $configError
    ],
    [
        'label' => 'Chat images',
        'status' => $mediaProblem ? 'error' : ($mediaReady ? 'ok' : 'warn'),
        'detail' => $mediaReady
            ? 'Upload, validation and serving are ready.'
            : 'Complete the configuration and diagnostics above.'
    ],
    [
        'label' => 'Voice notes',
        'status' => $mediaProblem ? 'error' : ($mediaReady ? 'ok' : 'warn'),
        'detail' => $mediaReady
            ? 'Audio upload and signed playback URLs are ready.'
            : 'Complete the configuration and diagnostics above.'
    ],
    [
        'label' => 'Profile photos',
        'status' => $mediaProblem ? 'error' : ($mediaReady ? 'ok' : 'warn'),
        'detail' => $mediaReady
            ? 'Avatar uploads are enabled.'
            : 'Complete the configuration and diagnostics above.'
    ],
    [
        'label' => 'Signed file URLs',
        'status' => ($checkStatuses['secret'] ?? 'error') === 'ok' && ($checkStatuses['file-file.php'] ?? 'error') === 'ok'
            ? 'ok'
            : 'error',
        'detail' => 'Uploaded files are served through file.php with HMAC signatures.'
    ],
    [
        'label' => 'Firebase login validation',
        'status' => $checkStatuses['firebase-auth'] ?? 'error',
        'detail' => 'Every upload must carry a valid Firebase ID token.'
    ],
    [
        'label' => 'CORS for frontend',
        'status' => $checkStatuses['cors'] ?? 'error',
        'detail' => 'Only the configured frontend origins can upload from browsers.'
    ],
    [
        'label' => 'Text chat (Firebase)',
        'status' => 'info',
        'detail' => 'Handled by Auth + Firestore on the frontend; PHP is not involved.'
    ],
    [
        'label' => 'Firebase Storage',
        'status' => 'off',
        'detail' => 'Not used. This PHP backend stores images and voice notes.'
    ]
];

$errors = 0;
$warnings = 0;
foreach ($checks as $check) {
    if ($check['status'] === 'error') {
        $errors++;
    } elseif ($check['status'] === 'warn') {
        $warnings++;
    }
}

$overall = $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok');
$stats = storage_stats($storageReal !== false ? $storageReal : $storageDirectory);

echo json_encode(
    [
        'ok' => $errors === 0,
        'overall' => $overall,
        'checked_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'errors' => $errors,
        'warnings' => $warnings,
        'checks' => $checks,
        'features' => $features,
        'config' => [
            'public_base_url' => (string) ($config['public_base_url'] ?? ''),
            'auth_host' => host_only((string) ($config['auth_lookup_url'] ?? '')),
            'allowed_origins' => is_array($config['allowed_origins'] ?? null)
                ? array_values($config['allowed_origins'])
                : [],
            'config_source' => $configSource,
            'max_image_bytes' => $maxImage,
            'max_audio_bytes' => $maxAudio,
            'php_upload_max_bytes' => $uploadMax,
            'php_post_max_bytes' => $postMax,
            'storage_files' => $stats['files'],
            'storage_bytes' => $stats['bytes']
        ]
    ],
    JSON_UNESCAPED_SLASHES
);
