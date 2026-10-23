# Personal Chat — PHP media backend

Small PHP service for profile photos, chat images and voice notes. It replaces
Firebase Storage; Firebase Auth + Firestore stay the app backend.

The frontend keeps only URLs + metadata in Firestore. File bytes live on the PHP
host.

## Requirements

- PHP 8.0+ with `curl` and `fileinfo`
- HTTPS (required for microphone recording and for production uploads)
- Writable `storage/` directory
- Frontend and backend may be on completely different domains; configure CORS

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/upload.php` | Multipart upload: `file` + `folder` |
| `GET` / `HEAD` | `/file.php?p=...&s=...` | Serve a signed file URL |
| `GET` | `/health.php` | Liveness check |
| `GET` | `/` | HTML status dashboard |
| `GET` | `/status.php` | Dashboard diagnostics as JSON |

Upload response:

```json
{
  "url": "https://api.example.com/file.php?p=voiceNotes%2FuidA_uidB%2Fabc.webm&s=...",
  "path": "voiceNotes/uidA_uidB/abc.webm"
}
```

## Dashboard

Open the backend's public URL (`https://api.your-domain.com/`) to see a small
status page. It checks PHP/extensions, config, storage, upload limits, CORS,
Firebase token validation and endpoint files, then lists which features are
working and which need attention. It never displays API keys, file secrets,
tokens or absolute server paths.

The page calls `/status.php`, so both files must stay in `public/`.

## 1. Upload the backend

Point the PHP host's document root to **`backend-php/public`**. The
`config.php`, `src/` and `storage/` directories must stay outside the document
root.

```
backend-php/
  config.php            <- create from config.example.php
  src/http.php
  public/
    index.html
    status.php
    upload.php
    file.php
    health.php
    .user.ini
  storage/              <- writable, contains uploaded files
```

If your host does not let you change the document root, create a subdomain or
addon domain and point it to this `public` folder. Do not expose the repository
root as the website root.

## 2. Configure

You can use either `config.php` or environment variables. Environment variables
override values from the file, which is useful for PaaS/serverless hosts such as
Wasmer where secrets should not be committed.

```bash
cp config.example.php config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # file_url_secret
```

Supported environment variables:

| Variable | Purpose |
| --- | --- |
| `CHAT_FIREBASE_API_KEY` | Firebase Web API key for token validation |
| `CHAT_AUTH_LOOKUP_URL` | Identity Toolkit lookup URL |
| `CHAT_ALLOWED_ORIGINS` | Comma-separated frontend origins |
| `CHAT_PUBLIC_BASE_URL` | Public backend base URL, including `/public` if used |
| `CHAT_FILE_URL_SECRET` | Random 32+ character signing secret |
| `CHAT_STORAGE_DIR` | Writable/persistent storage path |
| `CHAT_MAX_IMAGE_BYTES` | Optional image size limit |
| `CHAT_MAX_AUDIO_BYTES` | Optional audio size limit |

If neither `config.php` nor the required variables exist, the dashboard and
uploads report a configuration error.

Fill in `config.php`:

- `firebase_api_key` — Firebase Web API key (used to validate ID tokens).
- `auth_lookup_url` — keep the Google Identity Toolkit URL in production.
- `allowed_origins` — exact frontend origin(s), e.g.
  `https://your-project.web.app`. Add custom domains and preview domains too.
- `public_base_url` — public HTTPS URL of this backend, no trailing slash.
- `file_url_secret` — random 64-character hex string. Rotating it invalidates
  existing media URLs.
- `storage_dir` — defaults to `backend-php/storage`.
- size limits — defaults are 8 MB images / 15 MB audio.

Make the storage directory writable and protect it:

```bash
chmod 775 storage
```

The included `storage/.htaccess` blocks direct Apache access. The app serves
files through `file.php` with signed URLs, so uploaded files are not publicly
listable.

### Wasmer Edge

Wasmer serves the repository/app root, so the backend is reachable under
`/public/` (for example `https://APP.wasmer.app/public/upload.php`). Deploy the
`backend-php` directory as the app, set the environment variables above, and
mount a persistent volume for media:

```yaml
volumes:
  - name: media
    mount: /data
```

Then set `CHAT_STORAGE_DIR=/data`. Without a volume, Wasmer's filesystem is
ephemeral and uploaded files can disappear after a restart or redeploy.

Set `CHAT_PUBLIC_BASE_URL` to the `/public` URL, for example
`https://APP.wasmer.app/public`, and add the Vercel/custom frontend origin to
`CHAT_ALLOWED_ORIGINS`. The app's root URL itself may return 403 because only
`public/` is meant to be web-accessible.

## 3. Upload limits

`public/.user.ini` sets 20 MB upload / 25 MB POST limits for PHP-FPM/CGI. On
Apache with `mod_php`, also set in the panel or `.htaccess`:

```apache
php_value upload_max_filesize 20M
php_value post_max_size 25M
```

The backend still enforces its own smaller limits and MIME checks.

## 4. Frontend configuration

In the frontend `.env`:

```env
VITE_STORAGE_PROVIDER=http
VITE_UPLOAD_ENDPOINT=https://api.your-domain.com/upload.php
```

Leave `VITE_UPLOAD_TOKEN` empty: the frontend sends the signed-in user's
Firebase ID token and the PHP backend validates it. Leave
`VITE_FIREBASE_STORAGE_BUCKET` empty. Rebuild and redeploy the frontend.

If the backend and frontend are on different origins, `allowed_origins` must
contain the exact frontend origin or browsers will block uploads.

## Security model

- **Authentication:** every upload needs `Authorization: Bearer <Firebase ID
  token>`. The backend validates the token through Google Identity Toolkit
  (`accounts:lookup`). No Firebase service account is needed.
- **Chat authorization:** folder names are checked. `avatars/<uid>` must match
  the caller. `chatImages/<uidA>_<uidB>` and `voiceNotes/<uidA>_<uidB>` must
  contain the caller's UID.
- **File access:** URLs carry an HMAC signature over the storage path. Files
  cannot be fetched without a valid signature, and directory listing is blocked.
- **Uploads:** MIME is detected server-side with `finfo`, extensions are chosen
  by the server, filenames are random 128-bit values, and size limits are
  enforced.
- `config.php` and `storage/` are gitignored.

Note: media URLs are bearer links (like Firebase Storage download URLs). Anyone
who receives a link can open the file. Do not display them outside the private
chat. Deleting a chat message does not delete the underlying file; add a
scheduled cleanup job if permanent deletion is required.

The PHP host must be able to make outbound HTTPS requests to
`identitytoolkit.googleapis.com` (through cURL). Without outbound access, token
validation cannot work.

### API key restrictions

If Firebase token validation always returns `401`, check the API key
restrictions in Google Cloud Console. A key restricted to HTTP referrers may
reject server-side lookup. Create/use a key that allows the Identity Toolkit
API from the server, or allow your server's IP.

## Local testing with the Firebase emulator

In `config.php`:

```php
'auth_lookup_url' => 'http://127.0.0.1:9099/identitytoolkit.googleapis.com/v1/accounts:lookup',
'allowed_origins' => ['http://localhost:5180'],
'public_base_url' => 'http://127.0.0.1:8090',
```

Start the PHP server:

```bash
cd backend-php
php -S 127.0.0.1:8090 -d upload_max_filesize=20M -d post_max_size=25M -t public
```

On Termux, PHP's built-in server may fail with `Cannot create lock`. Add
`-d opcache.enable=0 -d opcache.enable_cli=0` to the command.

Set the frontend local env and restart Vite:

```env
VITE_STORAGE_PROVIDER=http
VITE_UPLOAD_ENDPOINT=http://127.0.0.1:8090/upload.php
```

`file_url_secret` must be at least 32 characters even for local testing.

## Backups

Back up `storage/` and `config.php` together. Restoring files without the same
`file_url_secret` makes old signed URLs invalid (the files still exist, but old
links return 403).
