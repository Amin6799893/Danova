<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: config.php
 * Version: 3.0.1 (PATCH — Safe shell_exec fallback)
 * Purpose: Configuration + Database + Helpers + Crypto
 * ---------------------------------------------------------------------
 * Changelog v3.0.1 (PATCH — shell_exec undefined):
 *   ADDED:
 *     - Section 0a: Safe shell_exec helpers
 *       * danova_shell_available()
 *       * danova_safe_shell_exec()
 *       * danova_command_exists()
 *     - Stub for shell_exec() اگر در سرور غیرفعال باشد.
 *   KEPT:
 *     - تمام منطق v3.0.0 (Merged Lifecycle Support) بدون تغییر.
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v3.0.0 (CIRCULAR DEPENDENCY FIX):
 *   FIXED:
 *     - danova_run_bugfix_migrations() دیگر lifecycle.php را
 *       require نمی‌کند (چون lifecycle.php حذف شده و محتوایش
 *       در api.php یکپارچه شده است).
 *     - lifecycle migrations اکنون در index.php (بعد از require
 *       api.php) با flag روزانه اجرا می‌شوند.
 *   KEPT:
 *     - تمام migration های قبلی (v2.7.0) بدون تغییر.
 *     - تمام تنظیمات، ثابت‌ها، helpers و crypto.
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v2.7.0 (CRITICAL FIX):
 *   FIXED:
 *     - Migration دیگر در هر request اجرا نمی‌شود (فقط یک‌بار در روز).
 *       این باعث رفع خطای "database is locked" و کندی می‌شود.
 *     - db() با تنظیمات بهینه‌تر WAL (autocheckpoint, cache_size, temp_store)
 *     - danova_run_bugfix_migrations() با PRAGMA journal_mode = WAL
 *   KEPT: تمام تغییرات v2.6.0 و قبل‌تر.
 * ---------------------------------------------------------------------
 * Changelog v2.6.0: Add Lifecycle & Ticket Support
 * Changelog v2.5.0: Fix BOOTSTRAP — Migration on Existing Installations
 * Changelog v2.4.0: ai_usage.quota_units + rag_usage_multiplier + GROUP E
 * Changelog v2.3.0: Time Management Helpers
 * Changelog v2.2.3: Index `idx_users_school_manager`
 * Changelog v2.2.2 (BUG FIX — BUG-LOCK-01): UNIQUE INDEX table-lock در messages
 * =====================================================================
 */

declare(strict_types=1);

// ⛔ جلوگیری از دسترسی مستقیم
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

// =====================================================================
// 0a. ✅ PATCH v3.0.1: SAFE SHELL_EXEC FALLBACK
// ---------------------------------------------------------------------
// در برخی هاست‌ها shell_exec غیرفعال است. این بلاک:
//   1. یک stub برای shell_exec تعریف می‌کند (اگر غیرفعال باشد).
//   2. سه تابع امن برای بررسی و اجرای دستورات shell ارائه می‌دهد.
//
// نکته: این بلاک باید قبل از هر require_once باشد تا در دسترس
//       سایر فایل‌ها (services.php، api.php) قرار گیرد.
// =====================================================================

if (!function_exists('shell_exec')) {
    /**
     * Stub برای shell_exec — اگر در php.ini غیرفعال شده باشد.
     * این تابع از Fatal Error «Call to undefined function» جلوگیری می‌کند.
     *
     * @param string $cmd
     * @return string|null همیشه null برمی‌گرداند
     */
    function shell_exec($cmd) {
        return null;
    }
}

if (!function_exists('danova_shell_available')) {
    /**
     * بررسی می‌کند که آیا shell_exec واقعاً در دسترس است یا نه.
     *
     * این تابع:
     *   - وجود تابع را چک می‌کند
     *   - لیست disable_functions را چک می‌کند
     *   - نتیجه را cache می‌کند (static)
     *
     * @return bool
     */
    function danova_shell_available(): bool
    {
        static $available = null;
        if ($available !== null) return $available;

        // ۱. چک وجود تابع
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            return $available = false;
        }

        // ۲. چک disable_functions
        $disabled = ini_get('disable_functions');
        if ($disabled) {
            $list = array_map('trim', explode(',', strtolower($disabled)));
            if (in_array('shell_exec', $list, true)) {
                return $available = false;
            }
        }

        return $available = true;
    }
}

if (!function_exists('danova_safe_shell_exec')) {
    /**
     * اجرای امن shell_exec.
     *
     * اگر shell_exec در دسترس نباشد، null برمی‌گرداند.
     * در صورت خطا هم null برمی‌گرداند (fail-safe).
     *
     * @param string $cmd دستور shell
     * @return string|null خروجی یا null
     */
    function danova_safe_shell_exec(string $cmd): ?string
    {
        if (!danova_shell_available()) {
            return null;
        }

        try {
            $out = @shell_exec($cmd);
            return is_string($out) ? $out : null;
        } catch (Throwable $e) {
            if (function_exists('logger_warn')) {
                logger_warn('[SHELL] shell_exec failed: ' . $e->getMessage());
            }
            return null;
        }
    }
}

if (!function_exists('danova_command_exists')) {
    /**
     * بررسی وجود یک دستور در سیستم (معادل `command -v`).
     *
     * اگر shell_exec غیرفعال باشد، همیشه false برمی‌گرداند.
     *
     * @param string $command نام دستور (مثلاً 'pdftotext', 'ffmpeg')
     * @return bool
     */
    function danova_command_exists(string $command): bool
    {
        if (!danova_shell_available()) return false;

        // sanitize نام دستور — فقط حروف، اعداد، _، -
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $command);
        if ($safe === '') return false;

        $out = danova_safe_shell_exec("command -v {$safe} 2>/dev/null");
        return $out !== null && trim($out) !== '';
    }
}

// =====================================================================
// 0b. LOGGING (EARLY) — defined here so it can be used by danova_load_secrets()
// =====================================================================

function prune_log_archives(string $logFile, int $maxCount = 20, int $maxBytes = 104857600): void
{
    $archives = glob($logFile . '.*');
    if (!is_array($archives)) return;

    $archives = array_values(array_filter($archives, 'is_file'));
    usort($archives, static function (string $a, string $b): int {
        return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
    });

    $keptCount = 0;
    $keptBytes = 0;
    foreach ($archives as $archive) {
        $size = (int)(filesize($archive) ?: 0);
        if ($keptCount >= $maxCount || $size > $maxBytes - $keptBytes) {
            @unlink($archive);
            continue;
        }
        $keptCount++;
        $keptBytes += $size;
    }
}

function log_line(string $level, string $msg): void {
    $msg = preg_replace('/\b(password|api[_-]?key|secret(?:_key)?|token|cookie)\b\s*([:=])\s*([^\s,;]+)/i', '$1$2[REDACTED]', $msg) ?? $msg;
    $msg = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $msg) ?? $msg;
    $line = sprintf("[%s] [%s] %s\n", gmdate('Y-m-d H:i:s'), $level, $msg);
    $logFile = LOGS_DIR . '/danova.log';
    $maxBytes = 5 * 1024 * 1024;
    if (is_file($logFile) && (@filesize($logFile) ?: 0) >= $maxBytes) {
        $rotatedFile = $logFile . '.' . gmdate('YmdHis');
        $suffix = 1;
        while (file_exists($rotatedFile) && $suffix <= 100) {
            $rotatedFile = $logFile . '.' . gmdate('YmdHis') . '.' . $suffix++;
        }
        if (@rename($logFile, $rotatedFile)) {
            prune_log_archives($logFile);
        }
    }
    // ✅ FIX: گزارش خطای نوشتن لاگ به‌جای سکوت
    $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        // fallback به error_log سیستم
        error_log('[DANOVA LOG WRITE FAILED] ' . rtrim($line));
    }
    if (DEBUG) error_log(rtrim($line));
}

function logger_debug(string $m): void { log_line('DEBUG', $m); }
function logger_info(string $m):  void { log_line('INFO',  $m); }
function logger_warn(string $m):  void { log_line('WARN',  $m); }
function logger_error(string $m): void { log_line('ERROR', $m); }


// =====================================================================
// 0. HTTPS DETECTION + SESSION SETTINGS
// =====================================================================

function danova_is_trusted_proxy(): bool
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $configured = getenv('DANOVA_TRUSTED_PROXIES') ?: '127.0.0.1,::1';
    $trusted = array_filter(array_map('trim', explode(',', $configured)), static fn($ip) => $ip !== '');
    return in_array($remote, $trusted, true);
}

$forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (danova_is_trusted_proxy() && $forwardedProto === 'https')
);

ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// ✅ FIX: SameSite از ENV قابل تنظیم (پیش‌فرض: Lax)
$__samesite = getenv('DANOVA_COOKIE_SAMESITE') ?: 'Lax';
if (!in_array($__samesite, ['Lax', 'Strict', 'None'], true)) {
    $__samesite = 'Lax';
}
ini_set('session.cookie_samesite', $__samesite);
ini_set('session.cookie_path', '/');
ini_set('session.cookie_domain', '');

// =====================================================================
// 1. ENVIRONMENT
// =====================================================================

define('APP_NAME', 'DANOVA');
define('APP_VERSION', '3.3.1-php');

define('APP_ENV', 'production');

define('DEBUG', APP_ENV === 'development');
define('IS_PRODUCTION', APP_ENV === 'production');
define('IS_HTTPS', $isHttps);

// =====================================================================
// 2. PATHS
// =====================================================================

define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('VIDEOS_DIR', DATA_DIR . '/videos');
define('THUMBNAILS_DIR', DATA_DIR . '/thumbnails');
define('UPLOADS_DIR', DATA_DIR . '/uploads');
define('RAG_DIR', DATA_DIR . '/rag');
define('LOGS_DIR', DATA_DIR . '/logs');
define('DB_PATH', DATA_DIR . '/danova.db');
define('SECRETS_FILE', DATA_DIR . '/secrets.php');

// Student chat uploads live beside the application directory, outside its web root.
define('STUDENT_UPLOADS_DIR', dirname(BASE_DIR) . '/DANOVA-DATA/student_uploads');

// 🆕 Ticket attachments (خارج از web root)
define('TICKET_UPLOADS_DIR', dirname(BASE_DIR) . '/DANOVA-DATA/ticket_uploads');

// 🆕 Download Center releases (خارج از web root)
define('DOWNLOADS_DIR', dirname(BASE_DIR) . '/DANOVA-DATA/downloads');

// 🆕 سقف حجم فایل انتشار (مستقل از MAX_UPLOAD_SIZE_MB)
define('MAX_DOWNLOAD_FILE_SIZE_MB', 500);

// =====================================================================
// 3. AUTO-CREATE DIRECTORIES + PROTECT DATA
// =====================================================================

foreach ([DATA_DIR, VIDEOS_DIR, THUMBNAILS_DIR, UPLOADS_DIR, RAG_DIR, LOGS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}

// 🆕 پوشه‌ی پیوست‌های تیکت (خارج از web root)
if (!is_dir(TICKET_UPLOADS_DIR)) @mkdir(TICKET_UPLOADS_DIR, 0755, true);

// 🆕 پوشه‌ی فایل‌های انتشار Download Center
if (!is_dir(DOWNLOADS_DIR)) @mkdir(DOWNLOADS_DIR, 0755, true);

$dataHtaccess = DATA_DIR . '/.htaccess';
if (!file_exists($dataHtaccess)) {
    $htaccessContent = <<<'HTACCESS'
# محافظت از فایل‌های دیتابیس، secrets و آپلودها
Require all denied
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;
    @file_put_contents($dataHtaccess, $htaccessContent);
}

$dataIndexHtml = DATA_DIR . '/index.html';
if (!file_exists($dataIndexHtml)) {
    @file_put_contents($dataIndexHtml, '<!-- Silence is golden -->');
}

// =====================================================================
// 4. SECRETS (auto-generated, stored outside web root)
// =====================================================================

function danova_generate_random_password(int $length = 16): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $max = strlen($alphabet) - 1;

    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function danova_load_secrets(): array
{
    /* DANOVA-FIX: DAN-006
     * Fail loudly if no secrets file exists. Avoid silently generating a
     * new one, which could overwrite a partial but recoverable config.
     */
    $hasMain = file_exists(SECRETS_FILE);
    $alt = dirname(BASE_DIR) . '/DANOVA-DATA/secrets.php';
    $hasAlt  = file_exists($alt);

    if (!$hasMain && !$hasAlt) {
        error_log('[DANOVA] FATAL: no secrets file found at ' . SECRETS_FILE);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'detail'  => 'Server configuration error: secrets file missing.',
        ], JSON_UNESCAPED_UNICODE));
    }

    static $secrets = null;
    if ($secrets !== null) return $secrets;

    // ✅ FIX (robust secrets loading):
    //   - چک می‌کند که فایل هم SECRET_KEY/DEFAULT_ADMIN_PASSWORD داشته باشد
    //     و هم MYSQL_USER/MYSQL_PASSWORD
    //   - اگر فایل ناقص بود، از DANOVA-DATA/secrets.php می‌خواند و آن را
    //     روی data/secrets.php کپی می‌کند
    //   - اگر هیچ‌کدام معتبر نبود، خطای واضح می‌دهد

    $altSecretsFile = dirname(BASE_DIR) . '/DANOVA-DATA/secrets.php';

    // ---------- کمکی: بررسی معتبر بودن آرایه secrets ----------
    $isComplete = function ($arr): bool {
        if (!is_array($arr)) return false;
        if (empty($arr['SECRET_KEY'])) return false;
        if (empty($arr['DEFAULT_ADMIN_PASSWORD'])) return false;
        if (empty($arr['MYSQL_USER'])) return false;
        if (empty($arr['MYSQL_PASSWORD'])) return false;
        return true;
    };

    $isPartial = function ($arr): bool {
        if (!is_array($arr)) return false;
        // حداقل SECRET_KEY داشته باشد تا ناقص حساب شود
        return !empty($arr['SECRET_KEY']);
    };

    // ---------- ۱. تلاش از SECRETS_FILE (data/secrets.php) ----------
    if (file_exists(SECRETS_FILE)) {
        $loaded = @include SECRETS_FILE;

        if ($isComplete($loaded)) {
            if (!array_key_exists('GAPGPT_API_KEY', $loaded)) {
                $loaded['GAPGPT_API_KEY'] = '';
            }
            return $secrets = $loaded;
        }

        if ($isPartial($loaded)) {
            error_log('[DANOVA] SECRETS_FILE is partial (missing MYSQL_*) — trying alternate path');

            // سعی کن از DANOVA-DATA بخوانی
            if (file_exists($altSecretsFile)) {
                $altLoaded = @include $altSecretsFile;
                if ($isComplete($altLoaded)) {
                    // کپی روی data/secrets.php
                    if (@copy($altSecretsFile, SECRETS_FILE)) {
                        @chmod(SECRETS_FILE, 0600);
                        error_log('[DANOVA] Copied complete secrets from DANOVA-DATA to SECRETS_FILE');
                    } else {
                        error_log('[DANOVA] Failed to copy complete secrets — using alternate directly');
                    }
                    if (!array_key_exists('GAPGPT_API_KEY', $altLoaded)) {
                        $altLoaded['GAPGPT_API_KEY'] = '';
                    }
                    return $secrets = $altLoaded;
                }
            }
        }
    } else {
        // SECRETS_FILE وجود ندارد
        error_log('[DANOVA] SECRETS_FILE not found — trying alternate path');

        if (file_exists($altSecretsFile)) {
            $altLoaded = @include $altSecretsFile;
            if ($isComplete($altLoaded)) {
                // کپی روی data/secrets.php
                @copy($altSecretsFile, SECRETS_FILE);
                @chmod(SECRETS_FILE, 0600);
                error_log('[DANOVA] Copied secrets from DANOVA-DATA to SECRETS_FILE');
                if (!array_key_exists('GAPGPT_API_KEY', $altLoaded)) {
                    $altLoaded['GAPGPT_API_KEY'] = '';
                }
                return $secrets = $altLoaded;
            }
        }
    }

    // ---------- ۲. اگر هیچ فایل معتبری نبود، یک فایل جدید بساز ----------
    error_log('[DANOVA] No valid secrets found — generating new (MySQL credentials will be MISSING)');

    $secretKey = bin2hex(random_bytes(32));
    $adminPass = danova_generate_random_password(16);

    $content = "<?php\n"
             . "// ============================================================\n"
             . "// DANOVA - Auto-generated secrets. DO NOT COMMIT TO VCS.\n"
             . "// Generated: " . gmdate('c') . "\n"
             . "// ============================================================\n"
             . "//\n"
             . "// ⚠️  هشدار: این فایل به‌طور خودکار ساخته شده و فاقد\n"
             . "//    اطلاعات MySQL است. لطفاً مقادیر MYSQL_* را پر کنید\n"
             . "//    یا فایل کامل را در data/secrets.php قرار دهید.\n"
             . "// ============================================================\n"
             . "return [\n"
             . "    'SECRET_KEY'             => " . var_export($secretKey, true) . ",\n"
             . "    'DEFAULT_ADMIN_PASSWORD' => " . var_export($adminPass, true) . ",\n"
             . "    'GAPGPT_API_KEY'         => " . var_export('', true) . ",\n"
             . "    'generated_at'           => " . var_export(gmdate('c'), true) . ",\n"
             . "    // ---- MySQL (پر کنید) ----\n"
             . "    'MYSQL_HOST'             => " . var_export('localhost', true) . ",\n"
             . "    'MYSQL_PORT'             => 3306,\n"
             . "    'MYSQL_DATABASE'         => " . var_export('', true) . ",\n"
             . "    'MYSQL_USER'             => " . var_export('', true) . ",\n"
             . "    'MYSQL_PASSWORD'         => " . var_export('', true) . ",\n"
             . "    'MYSQL_CHARSET'          => " . var_export('utf8mb4', true) . ",\n"
             . "];\n";

    if (@file_put_contents(SECRETS_FILE, $content, LOCK_EX) === false) {
        error_log('[DANOVA] FATAL: cannot write secrets.php — check data/ permissions');
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('Server configuration error: cannot write secrets file.');
    }
    @chmod(SECRETS_FILE, 0600);

    error_log('[DANOVA] New secrets file generated at ' . SECRETS_FILE . ' — MySQL credentials are missing');

    $loaded = @include SECRETS_FILE;
    if (!is_array($loaded) || empty($loaded['SECRET_KEY'])) {
        error_log('[DANOVA] FATAL: secrets.php unreadable after generation');
        http_response_code(500);
        die('Server configuration error.');
    }
    return $secrets = $loaded;
}

$__secrets = danova_load_secrets();

// =====================================================================
// 5. SECURITY CONSTANTS
// =====================================================================

define('SECRET_KEY', (string)$__secrets['SECRET_KEY']);
define('ENCRYPTION_KEY', base64_encode(hash('sha256', SECRET_KEY . ':danova-encryption-v1', true)));

define('SESSION_MAX_AGE', 86400);
define('SESSION_COOKIE_NAME', 'DANOVASESSID');
define('CSRF_COOKIE_NAME', 'DANOVACSRF');
define('CSRF_HEADER_NAME', 'x-csrf-token');

define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', (string)$__secrets['DEFAULT_ADMIN_PASSWORD']);
define('DEFAULT_ADMIN_NAME', 'مدیر DANOVA');

// =====================================================================
// 6. AI PROVIDERS
// =====================================================================

define('OPENAI_API_KEY', '');
define('GOOGLE_API_KEY', '');
define('ANTHROPIC_API_KEY', '');

define(
    'GAPGPT_API_KEY',
    getenv('GAPGPT_API_KEY')
        ?: ((string)($__secrets['GAPGPT_API_KEY'] ?? ''))
);
define(
    'GAPGPT_BASE_URL',
    getenv('GAPGPT_BASE_URL')
        ?: 'https://api.gapgpt.app/v1'
);

define('AI_DEFAULT_MODEL', 'deepseek-v4-flash');
define('AI_TIMEOUT', 60);

define('SUPPORTED_PROVIDERS', ['gapgpt', 'openai', 'google', 'anthropic', 'local']);
define('NEW_PROFILE_ALLOWED_PROVIDERS', ['gapgpt']);

define('EMBEDDING_PROVIDER', 'auto');
define('EMBEDDING_DIM', 384);

define('MAX_UPLOAD_SIZE_MB', 50);
define('MAX_VIDEO_SIZE_MB', 500);
define('MAX_VIDEO_DURATION_SECONDS', 86400);

define('RAG_CHUNK_SIZE', 800);
define('RAG_CHUNK_OVERLAP', 120);
define('RAG_TOP_K', 5);
define('RAG_MIN_SCORE', 0.01);
define('RAG_MAX_CONTEXT_TOKENS', 1500);

// =====================================================================
// 6b. RAG 2.0 CONSTANTS
// =====================================================================

define('RAG_HYBRID_ENABLED',              true);
define('RAG_HYBRID_CANDIDATE_COUNT',      60);
define('RAG_HYBRID_VECTOR_WEIGHT',        0.15);
define('RAG_HYBRID_LEXICAL_WEIGHT',       0.85);

define('RAG_RERANK_ENABLED',              true);
define('RAG_RERANK_TOP_N',                5);
define('RAG_RERANK_INPUT_MAX',            40);

define('RAG_DYNAMIC_THRESHOLD_ENABLED',   true);
define('RAG_DYNAMIC_THRESHOLD_RELATIVE',  0.20);
define('RAG_DYNAMIC_THRESHOLD_ABSOLUTE',  0.01);
define('RAG_DYNAMIC_THRESHOLD_MIN_KEEP',  2);

define('RAG_CONTEXT_MAX_SOURCES',         5);
define('RAG_CONTEXT_HEADER_ENABLED',      true);

define('RAG_GROUNDEDNESS_MODE',           'strict');
define('RAG_GROUNDEDNESS_MIN_SCORE',      0.20);

define('RAG_NORMALIZATION_ENABLED',       true);

define('RAG_EMBEDDING_CACHE_ENABLED',     true);
define('RAG_EMBEDDING_CACHE_TTL',         86400 * 30);

define('RAG_HEADING_AWARE_CHUNKING',      true);
define('RAG_HEADING_MIN_BODY_SIZE',       80);

define('RAG_PDF_PAGE_PROVENANCE',         true);

// =====================================================================
// 6c. SCHOOL / VIDEO / AI TARGETING ENUMS
// =====================================================================

const SCHOOL_TYPES          = ['elementary', 'middle', 'high'];
const SCHOOL_GENDERS        = ['girls', 'boys'];
const VIDEO_TARGET_GENDERS  = ['girls', 'boys', 'both'];
const AI_TARGET_GENDERS     = ['girls', 'boys', 'both'];

const GRADES_BY_LEVEL = [
    'elementary' => [1, 2, 3, 4, 5, 6],
    'middle'     => [7, 8, 9],
    'high'       => [10, 11, 12],
];

// =====================================================================
// 6d. VIDEO COLLECTION ENUMS
// =====================================================================

const VIDEO_COLLECTION_LEVELS = ['elementary', 'middle', 'high'];

// =====================================================================
// 6e. USAGE RESERVATION CONSTANTS
// =====================================================================

define('USAGE_RESERVATION_TTL_SECONDS', 300);
define('USAGE_RESERVATION_MAX_ATTEMPTS', 5);
define('USAGE_RESERVATION_LOCK_RETRY_US', 100000);
define('USAGE_RESERVATION_HARD_TTL_SECONDS', 3600);

// =====================================================================
// 6g. TEST ACCOUNTS CONSTANTS
// =====================================================================
define('TEST_ACCOUNT_DEFAULT_EXPIRY_HOURS', 24);
define('TEST_ACCOUNT_MAX_EXPIRY_HOURS',     72);
define('TEST_ACCOUNT_DEFAULT_MESSAGES',     10);
define('TEST_ACCOUNT_MAX_MESSAGES',         50);
define('TEST_ACCOUNT_RETENTION_HOURS',      24);

// =====================================================================
// 6f. TIMEZONE & DAILY RESET
// =====================================================================

define('DANOVA_TIMEZONE', 'Asia/Tehran');

function danova_today(): string
{
    try {
        $tz = new DateTimeZone(DANOVA_TIMEZONE);
        $now = new DateTime('now', $tz);
        return $now->format('Y-m-d');
    } catch (Throwable $e) {
        logger_warn('[TIMEZONE] Invalid timezone, falling back to UTC: ' . DANOVA_TIMEZONE);
        return gmdate('Y-m-d');
    }
}

function danova_today_start_utc(): string
{
    try {
        $tz = new DateTimeZone(DANOVA_TIMEZONE);
        $now = new DateTime('now', $tz);
        $now->setTime(0, 0, 0);
        $now->setTimezone(new DateTimeZone('UTC'));
        return $now->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        logger_warn('[TIMEZONE] today_start_utc failed: ' . $e->getMessage());
        return gmdate('Y-m-d 00:00:00');
    }
}

function danova_today_end_utc(): string
{
    try {
        $tz = new DateTimeZone(DANOVA_TIMEZONE);
        $now = new DateTime('now', $tz);
        $now->setTime(23, 59, 59);
        $now->setTimezone(new DateTimeZone('UTC'));
        return $now->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        logger_warn('[TIMEZONE] today_end_utc failed: ' . $e->getMessage());
        return gmdate('Y-m-d 23:59:59');
    }
}

function danova_is_same_day(?string $usageDate): bool
{
    if ($usageDate === null || $usageDate === '') return false;
    return $usageDate === danova_today();
}

// =====================================================================
// 7. DATABASE (PDO / SQLite)
// =====================================================================

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // ✅ MYSQL MIGRATION v1.0 — DSN switched from SQLite to MySQL 8
    // Credentials are loaded from DANOVA-DATA/secrets.php
    $secrets = danova_load_secrets();

    $mysqlHost = (string)($secrets['MYSQL_HOST'] ?? 'localhost');
    $mysqlPort = (int)   ($secrets['MYSQL_PORT'] ?? 3306);
    $mysqlDb   = (string)($secrets['MYSQL_DATABASE'] ?? 'danova_main');
    $mysqlUser = (string)($secrets['MYSQL_USER'] ?? '');
    $mysqlPass = (string)($secrets['MYSQL_PASSWORD'] ?? '');
    $mysqlCharset = (string)($secrets['MYSQL_CHARSET'] ?? 'utf8mb4');

    if ($mysqlUser === '' || $mysqlPass === '') {
        error_log('[DB] MySQL credentials missing in secrets.php');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'detail'  => IS_PRODUCTION
                ? 'خطای پیکربندی دیتابیس'
                : 'MySQL credentials missing (MYSQL_USER / MYSQL_PASSWORD).',
        ], JSON_UNESCAPED_UNICODE));
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $mysqlHost, $mysqlPort, $mysqlDb, $mysqlCharset
    );

    try {
        $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+00:00', sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
    } catch (PDOException $e) {
        error_log('[DB] MySQL connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'detail'  => IS_PRODUCTION
                ? 'خطای اتصال به دیتابیس'
                : 'MySQL Connection failed: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE));
    }
    return $pdo;
}

// =====================================================================
// 8. HELPERS
// =====================================================================

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $detail, int $status = 400, array $extra = []): void {
    json_response(array_merge([
        'success' => false,
        'detail'  => $detail,
        'status'  => $status,
    ], $extra), $status);
}

function json_ok(array $data = []): void {
    json_response(array_merge(['success' => true], $data));
}

function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function utcnow(): string {
    return gmdate('Y-m-d H:i:s');
}

function safe_filename(string $name, int $maxLen = 200): string {
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1f<>:"\/\\\\|?*]/', '_', $name);
    $name = mb_substr($name, 0, $maxLen);
    return $name ?: 'unnamed';
}

function safe_file_path(?string $path, string $allowedDir): ?string
{
    if (!$path) return null;

    $real = @realpath($path);
    $allowed = @realpath($allowedDir);

    if ($real === false || $allowed === false) return null;

    $real    = rtrim(str_replace('\\', '/', $real), '/');
    $allowed = rtrim(str_replace('\\', '/', $allowed), '/');

    if ($real !== $allowed && strpos($real, $allowed . '/') !== 0) {
        if (function_exists('logger_warn')) {
            logger_warn('[SECURITY] Path traversal attempt blocked: ' . $path);
        }
        return null;
    }

    if (!is_file($real)) return null;
    return $real;
}

function compute_sha256(string $path): string {
    return hash_file('sha256', $path) ?: '';
}

function estimate_tokens(string $text): int {
    if ($text === '') return 0;
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    $totalChars = mb_strlen($text, 'UTF-8');
    $asciiMatches = preg_match_all('/[\x00-\x7F]/', $text);
    $ascii = ($asciiMatches === false) ? 0 : $asciiMatches;
    $nonAscii = max(0, $totalChars - $ascii);
    $asciiTokens    = intdiv($ascii, 4);
    $nonAsciiTokens = $nonAscii + intdiv($nonAscii, 4);
    return max(1, $asciiTokens + $nonAsciiTokens);
}

function random_token(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

// =====================================================================
// 8b. TARGETING VALIDATION HELPERS
// =====================================================================

function validate_school_type(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    return in_array($v, SCHOOL_TYPES, true) ? $v : null;
}

function validate_school_gender(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    return in_array($v, SCHOOL_GENDERS, true) ? $v : null;
}

function validate_video_target_gender(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    return in_array($v, VIDEO_TARGET_GENDERS, true) ? $v : null;
}

function validate_ai_target_gender(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    return in_array($v, AI_TARGET_GENDERS, true) ? $v : null;
}

function grade_belongs_to_level(int $grade, string $level): bool
{
    $grades = GRADES_BY_LEVEL[$level] ?? null;
    if ($grades === null) return false;
    return in_array($grade, $grades, true);
}

function validate_grade_for_level($grade, string $level): ?int
{
    if ($grade === null || $grade === '') return null;
    $g = (int)$grade;
    if ($g < 1 || $g > 12) return null;
    return grade_belongs_to_level($g, $level) ? $g : null;
}

function map_grade_text_to_number(?string $text, ?string $level = null): ?int
{
    if ($text === null) return null;
    $t = trim((string)$text);
    if ($t === '') return null;

    $normalized = preg_replace('/\b(پایه|کلاس|grade|class)\b/ui', ' ', $t);
    $normalized = preg_replace('/\s+/u', ' ', trim((string)$normalized));
    $normalized = mb_strtolower($normalized, 'UTF-8');

    $normalized = str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
        $normalized
    );

    if (preg_match('/^(\d{1,2})$/', $normalized, $m)) {
        $g = (int)$m[1];
        if ($g >= 1 && $g <= 12) {
            if ($level !== null) {
                return grade_belongs_to_level($g, $level) ? $g : null;
            }
            return $g;
        }
        return null;
    }

    $faMap = [
        'اول' => 1, 'یکم' => 1,
        'دوم' => 2,
        'سوم' => 3,
        'چهارم' => 4,
        'پنجم' => 5,
        'ششم' => 6,
        'هفتم' => 7,
        'هشتم' => 8,
        'نهم' => 9,
        'دهم' => 10,
        'یازدهم' => 11,
        'دوازدهم' => 12,
    ];

    $enMap = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4,
        'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8,
        'ninth' => 9, 'tenth' => 10, 'eleventh' => 11, 'twelfth' => 12,
    ];

    $matched = null;
    foreach ($faMap as $word => $num) {
        if (mb_strpos($normalized, $word) !== false) {
            if ($matched !== null && $matched !== $num) return null;
            $matched = $num;
        }
    }
    if ($matched === null) {
        foreach ($enMap as $word => $num) {
            if (strpos($normalized, $word) !== false) {
                if ($matched !== null && $matched !== $num) return null;
                $matched = $num;
            }
        }
    }

    if ($matched === null) return null;

    if ($level !== null) {
        return grade_belongs_to_level($matched, $level) ? $matched : null;
    }
    return $matched;
}

function validate_video_collection_level(?string $v): ?string
{
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    return in_array($v, VIDEO_COLLECTION_LEVELS, true) ? $v : null;
}

// =====================================================================
// 9. PASSWORD (bcrypt)
// =====================================================================

const PASSWORD_MIN_LENGTH = 6;
const PASSWORD_MAX_LENGTH = 72;

function hash_password(string $password): string {
    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        throw new InvalidArgumentException('رمز عبور باید حداقل ۶ کاراکتر باشد.');
    }
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verify_password(string $plain, string $hash): bool {
    if ($hash === '' || $plain === '') return false;
    return password_verify($plain, $hash);
}

function validate_password_strength(string $password): array {
    if ($password === '') return [false, 'رمز عبور الزامی است.'];
    $len = mb_strlen($password);
    if ($len < PASSWORD_MIN_LENGTH) return [false, 'رمز عبور باید حداقل ۶ کاراکتر باشد.'];
    if ($len > PASSWORD_MAX_LENGTH) return [false, 'رمز عبور نباید بیشتر از ۷۲ کاراکتر باشد.'];
    return [true, ''];
}

function dummy_hash(): string {
    static $h = null;
    if ($h === null) $h = hash_password('danova-dummy-password-for-timing-defense');
    return $h;
}

// =====================================================================
// 10. API KEY ENCRYPTION (AES-256-GCM)
// =====================================================================

function encrypt_api_key(?string $plain): ?string {
    if ($plain === null || $plain === '') return null;

    $key = base64_decode(ENCRYPTION_KEY);
    if (strlen($key) < 32) {
        $key = hash('sha256', ENCRYPTION_KEY, true);
    } else {
        $key = substr($key, 0, 32);
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) return null;

    return base64_encode($iv . $tag . $cipher);
}

function decrypt_api_key(?string $encrypted): ?string {
    if ($encrypted === null || $encrypted === '') return null;

    if (strpos($encrypted, 'PLAIN:') === 0) {
        logger_warn('[SECURITY] Legacy PLAIN: api_key detected — must be re-encrypted via admin panel');
        return null;
    }

    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 28) return null;

    $key = base64_decode(ENCRYPTION_KEY);
    if (strlen($key) < 32) {
        $key = hash('sha256', ENCRYPTION_KEY, true);
    } else {
        $key = substr($key, 0, 32);
    }

    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);

    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

function mask_api_key(string $key): string {
    $key = trim($key);
    $len = strlen($key);
    if ($len <= 4) return str_repeat('•', $len);
    return str_repeat('•', 8) . substr($key, -4);
}

// =====================================================================
// 11. LOGGING (MOVED UP — see near top of file)
// =====================================================================
// NOTE: Logger functions are now defined BEFORE section 4 (SECRETS),
// so they can be safely used by danova_load_secrets().
// See the 'LOGGING (EARLY)' block near the top of this file.

// =====================================================================
// 12. INITIALIZE DATABASE (schema + seed + migrations)
// =====================================================================

function init_database(): void {
    // ✅ MYSQL MIGRATION: schema is now defined in schema_mysql.sql
    // and applied externally by DANOVA-MIGRATION/migrate_sqlite_to_mysql.php.
    // This function only verifies that the expected tables exist.
    $pdo = db();

    $requiredTables = ['users', 'schools', 'classes', 'students', 'ai_profiles',
        'rag_collections', 'rag_documents', 'rag_chunks', 'conversations', 'messages',
        'ai_usage', 'videos', 'video_usage', 'system_settings', 'audit_logs', 'rate_limits'];

    // ═══════════════════════════════════════════════════════════════════
    // DANOVA_FIX_REQUIRED_TABLES_TEST_ACCOUNTS
    // جداول Test Account اختیاری هستند (اگر نباشند، فقط warning می‌دهیم)
    // ═══════════════════════════════════════════════════════════════════
    $optionalTestTables = ['test_accounts', 'test_conversations', 'test_messages'];
    $testTablesStmt = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $missingTestTables = [];
    foreach ($optionalTestTables as $t) {
        $testTablesStmt->execute([$t]);
        if (!$testTablesStmt->fetch()) $missingTestTables[] = $t;
    }
    if (!empty($missingTestTables) && function_exists('logger_warn')) {
        logger_warn('[BOOT] Test Account tables missing (feature will be disabled): '
            . implode(', ', $missingTestTables)
            . ' — run the DDL in DANOVA-MIGRATION/test_accounts.sql');
    }

    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );

    $missing = [];
    foreach ($requiredTables as $t) {
        $stmt->execute([$t]);
        if (!$stmt->fetch()) $missing[] = $t;
    }

    if (!empty($missing)) {
        logger_error('[DB] Missing MySQL tables: ' . implode(', ', $missing));
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'success' => false,
            'detail'  => IS_PRODUCTION
                ? 'دیتابیس ناقص است. لطفاً migration را کامل کنید.'
                : 'Missing tables: ' . implode(', ', $missing),
        ], JSON_UNESCAPED_UNICODE));
    }

    // Ensure default admin exists
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ? LIMIT 1');
        $stmt->execute(['danova_admin']);
        if (!$stmt->fetch()) {
            $adminId = generate_uuid();
            $hash = hash_password(DEFAULT_ADMIN_PASSWORD);
            $now = utcnow();
            $pdo->prepare(
                'INSERT INTO users
                    (id, username, password_hash, full_name, role, is_active,
                     session_version, must_change_password, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 0, 1, ?, ?)'
            )->execute([$adminId, DEFAULT_ADMIN_USERNAME, $hash,
                        DEFAULT_ADMIN_NAME, 'danova_admin', $now, $now]);
            logger_info('[SEED] Default admin created (MySQL): ' . DEFAULT_ADMIN_USERNAME);
        }
    } catch (Throwable $e) {
        logger_warn('[DB] Admin check failed: ' . $e->getMessage());
    }
}

function danova_default_model_catalog_json(): string
{
    $catalog = [
        ['key' => 'deepseek_v4_flash', 'label' => 'DeepSeek V4 Flash', 'api_id' => 'deepseek-v4-flash', 'verified' => true],
        ['key' => 'deepseek_v4_pro',   'label' => 'DeepSeek V4 Pro',   'api_id' => 'deepseek-v4-pro',   'verified' => true],
        ['key' => 'gpt_5_5',           'label' => 'GPT-5.5',           'api_id' => 'gpt-5.5',           'verified' => true],
        ['key' => 'gpt_5_4',           'label' => 'GPT-5.4',           'api_id' => 'gpt-5.4',           'verified' => true],
        ['key' => 'gpt_5_3_codex',     'label' => 'GPT-5.3 Codex',     'api_id' => 'gpt-5.3-codex',     'verified' => true],
        ['key' => 'claude_opus',       'label' => 'Claude Opus',       'api_id' => null,                'verified' => false],
        ['key' => 'claude_sonnet',     'label' => 'Claude Sonnet',     'api_id' => null,                'verified' => false],
        ['key' => 'gemini_pro',        'label' => 'Gemini Pro',        'api_id' => null,                'verified' => false],
        ['key' => 'gemini_flash',      'label' => 'Gemini Flash',      'api_id' => null,                'verified' => false],
        ['key' => 'qwen',              'label' => 'Qwen',              'api_id' => null,                'verified' => false],
    ];
    return json_encode($catalog, JSON_UNESCAPED_UNICODE);
}

// [CLEANED-2026] Function danova_run_migrations removed (was SQLite-only, not used after MySQL migration)


// [CLEANED-2026] Function danova_run_bugfix_migrations removed (was SQLite-only, not used after MySQL migration)


// [CLEANED-2026] Function danova_run_video_collection_migrations removed (was SQLite-only, not used after MySQL migration)


// [CLEANED-2026] Function danova_run_rag_migrations removed (was SQLite-only, not used after MySQL migration)


// =====================================================================
// 12b. USAGE RESERVATION CLEANUP
// =====================================================================

function danova_cleanup_expired_reservations(int $keepSeconds = 86400): int
{
    try {
        $now = utcnow();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $keepSeconds);

        db()->prepare(
            "UPDATE usage_reservations
             SET status = 'expired'
             WHERE status = 'pending' AND expires_at < ?"
        )->execute([$now]);

        $stmt = db()->prepare(
            "DELETE FROM usage_reservations
             WHERE status IN ('committed', 'released', 'expired')
               AND created_at < ?"
        );
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();

    } catch (Throwable $e) {
        logger_warn('[RESERVATION CLEANUP] ' . $e->getMessage());
        return 0;
    }
}

function danova_maybe_cleanup_reservations(): void
{
    try {
        if (random_int(1, 100) <= 3) {
            danova_cleanup_expired_reservations();
        }
    } catch (Throwable $e) {
        // silent
    }
}

// =====================================================================
// 13. GET/SET SETTINGS
// =====================================================================

function &_danova_settings_cache_ref()
{
    static $cache = [];
    return $cache;
}

function get_setting(string $key, $default = null) {
    $cache = &_danova_settings_cache_ref();

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare('SELECT value, value_type FROM system_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row || $row['value'] === null) {
        $cache[$key] = $default;
        return $default;
    }

    switch ($row['value_type']) {
        case 'int':
            $val = (int)$row['value'];
            break;
        case 'float':
            $val = (float)$row['value'];
            break;
        case 'bool':
            $val = in_array(strtolower($row['value']), ['true', '1', 'yes'], true);
            break;
        case 'json':
            $val = json_decode($row['value'], true);
            if ($val === null) $val = $default;
            break;
        default:
            $val = $row['value'];
    }

    $cache[$key] = $val;
    return $val;
}

function set_setting(string $key, string $value, string $type = 'string', string $desc = ''): void {
    $cache = &_danova_settings_cache_ref();

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM system_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if ($row) {
        $sql = 'UPDATE system_settings SET value = ?, value_type = ?, updated_at = ?';
        $params = [$value, $type, utcnow()];
        if ($desc !== '') {
            $sql .= ', description = ?';
            $params[] = $desc;
        }
        $sql .= ' WHERE id = ?';
        $params[] = $row['id'];
        $pdo->prepare($sql)->execute($params);
    } else {
        $pdo->prepare('INSERT INTO system_settings (id, key, value, value_type, description, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([generate_uuid(), $key, $value, $type, $desc, utcnow()]);
    }

    unset($cache[$key]);
}

// =====================================================================
// 14. AUDIT LOG
// =====================================================================

function log_audit(?array $actor, string $action, ?string $entityType = null, ?string $entityId = null, ?array $meta = null): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        db()->prepare('
            INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, log_metadata, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            generate_uuid(),
            $actor['id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $ip,
            $ua,
            utcnow(),
        ]);
    } catch (Throwable $e) {
        logger_warn('[AUDIT] Failed: ' . $e->getMessage());
    }
}

// =====================================================================
// 15. BOOTSTRAP
// =====================================================================

if (file_exists(BASE_DIR . '/vendor/autoload.php')) {
    require_once BASE_DIR . '/vendor/autoload.php';
}

try {
    // ✅ MYSQL MIGRATION: schema is verified inside init_database().
    // Legacy SQLite migrations (danova_run_migrations, danova_run_bugfix_migrations,
    // danova_run_rag_migrations, danova_run_video_collection_migrations,
    // danova_run_download_migrations) are no longer called here.
    // They remain in the file only for reference / SQLite rollback.
    init_database();
} catch (Throwable $e) {
    logger_error('[BOOT] DB init failed: ' . $e->getMessage());
    if (DEBUG) {
        http_response_code(500);
        die('DB init failed: ' . $e->getMessage());
    }
    http_response_code(500);
    die('Server error');
}

// =====================================================================
// 16. TIME MANAGEMENT HELPERS
// =====================================================================

function danova_time_settings(): array
{
    return [
        'timezone'              => (string) get_setting('time_timezone', DANOVA_TIMEZONE),
        'reset_hour'            => (int)    get_setting('time_reset_hour', 0),
        'reset_minute'          => (int)    get_setting('time_reset_minute', 0),
        'reset_second'          => (int)    get_setting('time_reset_second', 0),
        'ntp_server'            => (string) get_setting('time_ntp_server', 'pool.ntp.org'),
        'sync_enabled'          => (bool)   get_setting('time_sync_enabled', false),
        'manual_offset_seconds' => (int)    get_setting('time_manual_offset_seconds', 0),
    ];
}

function danova_time_info(): array
{
    $settings = danova_time_settings();

    $tzName = $settings['timezone'];
    try {
        $tz = new DateTimeZone($tzName);
    } catch (Throwable $e) {
        logger_warn('[TIME] Invalid timezone in settings: ' . $tzName . ' — falling back to UTC');
        $tzName = 'UTC';
        $tz = new DateTimeZone('UTC');
    }

    $utcNow   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $localNow = $utcNow->setTimezone($tz);

    $offsetSeconds = $tz->getOffset($localNow);
    $offsetSign    = $offsetSeconds >= 0 ? '+' : '-';
    $offsetAbs     = abs($offsetSeconds);
    $offsetStr     = sprintf(
        '%s%02d:%02d',
        $offsetSign,
        intdiv($offsetAbs, 3600),
        intdiv($offsetAbs % 3600, 60)
    );

    $nextReset = danova_next_reset_at($tz, $settings);

    return [
        'server_utc'          => $utcNow->format('Y-m-d H:i:s'),
        'server_local'        => $localNow->format('Y-m-d H:i:s'),
        'server_timestamp'    => $utcNow->getTimestamp(),
        'timezone'            => $tzName,
        'timezone_offset'     => $offsetStr,
        'timezone_offset_sec' => $offsetSeconds,
        'is_dst'              => (bool) $localNow->format('I'),
        'date_gregorian'      => $localNow->format('Y-m-d'),
        'date_persian'        => danova_gregorian_to_jalali(
                                    (int) $localNow->format('Y'),
                                    (int) $localNow->format('n'),
                                    (int) $localNow->format('j')
                                ),
        'time_hms'            => $localNow->format('H:i:s'),
        'weekday_persian'     => danova_weekday_persian((int) $localNow->format('w')),
        'weekday_number'      => (int) $localNow->format('w'),
        'month_persian'       => danova_month_persian((int) $localNow->format('n')),
        'settings'            => $settings,
        'next_reset'          => $nextReset,
        'drift_check'         => danova_server_time_health(),
    ];
}

function danova_next_reset_at(?DateTimeZone $tz = null, ?array $settings = null): array
{
    if ($tz === null) {
        try {
            $tz = new DateTimeZone(DANOVA_TIMEZONE);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('UTC');
        }
    }
    if ($settings === null) {
        $settings = danova_time_settings();
    }

    $now = new DateTimeImmutable('now', $tz);

    $resetToday = $now->setTime(
        max(0, min(23, (int) $settings['reset_hour'])),
        max(0, min(59, (int) $settings['reset_minute'])),
        max(0, min(59, (int) $settings['reset_second']))
    );

    if ($resetToday <= $now) {
        $resetToday = $resetToday->modify('+1 day');
    }

    $secondsUntil = $resetToday->getTimestamp() - $now->getTimestamp();

    return [
        'utc'           => $resetToday->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'local'         => $resetToday->format('Y-m-d H:i:s'),
        'timestamp'     => $resetToday->getTimestamp(),
        'seconds_until' => max(0, $secondsUntil),
        'hms'           => $resetToday->format('H:i:s'),
        'date_persian'  => danova_gregorian_to_jalali(
                                (int) $resetToday->format('Y'),
                                (int) $resetToday->format('n'),
                                (int) $resetToday->format('j')
                            ),
    ];
}

function danova_server_time_health(): array
{
    // ✅ Bug #11 FIX
    $now     = time();
    $reqTime = (int) ($_SERVER['REQUEST_TIME'] ?? $now);
    $elapsed = abs($now - $reqTime);

    return [
        'healthy'       => $elapsed <= 300,
        'drift_seconds' => $elapsed,
        'note'          => 'این مقدار فقط تأخیر داخلی request است، نه drift واقعی ساعت.',
    ];
}

function danova_gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
    $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4)
           - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400)
           + $gd + $g_d_m[$gm - 1];

    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy  += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }

    return [
        'year'      => $jy,
        'month'     => $jm,
        'day'       => $jd,
        'formatted' => sprintf('%04d/%02d/%02d', $jy, $jm, $jd),
    ];
}

function danova_weekday_persian(int $w): string
{
    $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    return $days[$w] ?? '—';
}

function danova_month_persian(int $m): string
{
    $months = [
        1 => 'ژانویه', 2 => 'فوریه', 3 => 'مارس', 4 => 'آوریل',
        5 => 'مه', 6 => 'ژوئن', 7 => 'جولای', 8 => 'اوت',
        9 => 'سپتامبر', 10 => 'اکتبر', 11 => 'نوامبر', 12 => 'دسامبر',
    ];
    return $months[$m] ?? '—';
}


// =====================================================================
// DOWNLOAD CENTER MIGRATIONS
// =====================================================================

// [CLEANED-2026] Function danova_run_download_migrations removed (was SQLite-only, not used after MySQL migration)



// =====================================================================
// END OF config.php (v3.0.1 — PATCH: Safe shell_exec fallback)
// =====================================================================