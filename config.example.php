<?php

// Copy this file to config.php on the PHP host and fill in real values.
// config.php is gitignored and must never be committed.

return [
    // Firebase Web API key. Used only to validate Firebase ID tokens through
    // Google's Identity Toolkit (accounts:lookup). This key is public in the
    // Firebase web config, but keep the backend copy server-side.
    'firebase_api_key' => 'YOUR_FIREBASE_WEB_API_KEY',

    // Production:
    'auth_lookup_url' => 'https://identitytoolkit.googleapis.com/v1/accounts:lookup',
    // Local Auth emulator (only for testing):
    // 'auth_lookup_url' => 'http://127.0.0.1:9099/identitytoolkit.googleapis.com/v1/accounts:lookup',

    // Exact frontend origins allowed by CORS. No trailing slash.
    'allowed_origins' => [
        'https://your-project.web.app',
        'https://your-domain.com'
        // 'http://localhost:5180'
    ],

    // Public HTTPS base URL of this backend (no trailing slash).
    'public_base_url' => 'https://api.your-domain.com',

    // Generate with:
    // php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'file_url_secret' => 'CHANGE_ME_WITH_A_RANDOM_64_CHAR_HEX_SECRET',

    // Keep this directory outside the public document root.
    'storage_dir' => __DIR__ . '/storage',

    'max_image_bytes' => 8 * 1024 * 1024,
    'max_audio_bytes' => 15 * 1024 * 1024
];
