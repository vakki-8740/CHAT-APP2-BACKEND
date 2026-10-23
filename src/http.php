<?php

declare(strict_types=1);

ini_set('display_errors', '0');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function backend_env(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}

function backend_config_path(): string
{
    return dirname(__DIR__) . '/config.php';
}

function backend_config_source(): string
{
    if (is_file(backend_config_path())) {
        return 'config.php';
    }

    foreach (
        [
            'CHAT_FIREBASE_API_KEY',
            'CHAT_AUTH_LOOKUP_URL',
            'CHAT_ALLOWED_ORIGINS',
            'CHAT_PUBLIC_BASE_URL',
            'CHAT_FILE_URL_SECRET',
            'CHAT_STORAGE_DIR'
        ] as $name
    ) {
        if (backend_env($name) !== null) {
            return 'environment variables';
        }
    }

    return 'none';
}

function backend_detect_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }

    $proto = (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if ($proto === '') {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $proto = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }
    $proto = strtolower(trim(explode(',', $proto)[0]));
    if (!in_array($proto, ['http', 'https'], true)) {
        $proto = 'https';
    }

    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }

    return $proto . '://' . $host . $scriptDir;
}

function backend_clean_url(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $cleaned = preg_replace('/\\s+/', '', $value);

    return $cleaned === '' ? null : $cleaned;
}

function backend_env_config(): array
{
    $config = [];
    $map = [
        'firebase_api_key' => 'CHAT_FIREBASE_API_KEY',
        'auth_lookup_url' => 'CHAT_AUTH_LOOKUP_URL',
        'public_base_url' => 'CHAT_PUBLIC_BASE_URL',
        'file_url_secret' => 'CHAT_FILE_URL_SECRET',
        'storage_dir' => 'CHAT_STORAGE_DIR'
    ];

    foreach ($map as $key => $name) {
        $value = backend_env($name);
        if ($value === null) {
            continue;
        }
        $config[$key] = in_array(
            $key,
            ['auth_lookup_url', 'public_base_url', 'file_url_secret', 'firebase_api_key'],
            true
        )
            ? backend_clean_url($value)
            : $value;
    }

    $origins = backend_clean_url(backend_env('CHAT_ALLOWED_ORIGINS'));
    if ($origins !== null) {
        $config['allowed_origins'] = array_values(
            array_filter(
                array_map(
                    static fn (string $origin): string => rtrim($origin, '/'),
                    explode(',', $origins)
                ),
                static fn (string $origin): bool => $origin !== ''
            )
        );
    }

    foreach (['max_image_bytes', 'max_audio_bytes', 'file_url_ttl'] as $key) {
        $value = backend_env('CHAT_' . strtoupper($key));
        if ($value !== null && ctype_digit($value)) {
            $config[$key] = (int) $value;
        }
    }

    return $config;
}

function load_backend_config(): array
{
    $config = [
        'auth_lookup_url' => 'https://identitytoolkit.googleapis.com/v1/accounts:lookup',
        'storage_dir' => dirname(__DIR__) . '/storage',
        'allowed_origins' => [],
        'max_image_bytes' => 10 * 1024 * 1024,
        'max_audio_bytes' => 15 * 1024 * 1024,
        'file_url_ttl' => 3600
    ];

    $path = backend_config_path();
    if (is_file($path)) {
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Backend configuration is invalid.');
        }
        $config = array_replace($config, $loaded);
    }

    $config = array_replace($config, backend_env_config());

    if (trim((string) ($config['public_base_url'] ?? '')) === '') {
        $detected = backend_detect_base_url();
        if ($detected !== '') {
            $config['public_base_url'] = $detected;
        }
    }

    return $config;
}

function app_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    try {
        $loaded = load_backend_config();
    } catch (Throwable $error) {
        respond(500, ['error' => 'Backend configuration is invalid.']);
    }

    $secret = (string) ($loaded['file_url_secret'] ?? '');
    if (strlen($secret) < 32 || str_contains($secret, 'CHANGE_ME')) {
        respond(500, ['error' => 'Set a strong file_url_secret in config.php or CHAT_FILE_URL_SECRET.']);
    }

    if (trim((string) ($loaded['firebase_api_key'] ?? '')) === '') {
        respond(500, ['error' => 'Set firebase_api_key or CHAT_FIREBASE_API_KEY.']);
    }

    if (trim((string) ($loaded['public_base_url'] ?? '')) === '') {
        respond(500, ['error' => 'Set public_base_url or CHAT_PUBLIC_BASE_URL.']);
    }

    return $config = $loaded;
}

function send_cors(array $allowedOrigins, string $methods = 'POST, OPTIONS'): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || !in_array($origin, $allowedOrigins, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '') {
        $header = (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

function firebase_uid_from_token(array $config, string $idToken): ?string
{
    if ($idToken === '' || !function_exists('curl_init')) {
        return null;
    }

    $apiKey = (string) ($config['firebase_api_key'] ?? '');
    $lookupUrl = (string) ($config['auth_lookup_url'] ?? '');
    if ($apiKey === '' || $lookupUrl === '') {
        return null;
    }

    $separator = str_contains($lookupUrl, '?') ? '&' : '?';
    $url = $lookupUrl . $separator . 'key=' . rawurlencode($apiKey);

    $handle = curl_init($url);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['idToken' => $idToken]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($body) || $status !== 200) {
        error_log('chat-api: Firebase token lookup failed with status ' . $status);
        return null;
    }

    $data = json_decode($body, true);
    $uid = $data['users'][0]['localId'] ?? null;

    return is_string($uid) && $uid !== '' ? $uid : null;
}

function storage_root(array $config, bool $requireWritable = true): string
{
    $root = realpath((string) ($config['storage_dir'] ?? ''));
    if ($root === false || !is_dir($root)) {
        respond(500, ['error' => 'Storage directory was not found.']);
    }
    if ($requireWritable && !is_writable($root)) {
        respond(500, ['error' => 'Storage directory is not writable.']);
    }

    return $root;
}

function signed_file_url(array $config, string $relativePath): string
{
    $base = rtrim((string) ($config['public_base_url'] ?? ''), '/');
    if ($base === '') {
        respond(500, ['error' => 'Set public_base_url or CHAT_PUBLIC_BASE_URL.']);
    }

    $signature = hash_hmac('sha256', $relativePath, (string) $config['file_url_secret']);

    return $base . '/file.php?p=' . rawurlencode($relativePath) . '&s=' . $signature;
}
