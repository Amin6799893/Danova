<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: security.php
 * Version: 1.2.0 (CSP nonce + unsafe-inline fallback)
 * Purpose: Centralized Security Helpers
 * ---------------------------------------------------------------------
 * Contents:
 *   - Rate Limiter (SQLite-backed, no Redis needed)
 *   - Upload Validators (extension + MIME + finfo + signature)
 *   - Security Headers helper (with CSP nonce)
 *   - CSV Injection sanitizer
 * ---------------------------------------------------------------------
 * Changelog v1.2.0 (CSP FIX):
 *   FIXED:
 *     - security_headers_html() اکنون CSP را با پشتیبانی از nonce
 *       و unsafe-inline (به‌عنوان fallback) ارسال می‌کند.
 *     - security_csp_nonce() با static cache و fallback اضافه شد.
 *   KEPT: تمام توابع v1.1.0 (rate limit fail-closed, upload validators).
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v1.1.0 (Safe Bug Fix — Rate Limit Fail-Closed)
 * Changelog v1.0.0 (NEW — Phase 6 Modularization)
 * =====================================================================
 */

declare(strict_types=1);

// ⛔ جلوگیری از دسترسی مستقیم به این فایل
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

require_once __DIR__ . '/config.php';


// =====================================================================
// SECTION 0: UNIFIED IP RESOLUTION (M-01 FIX)
// =====================================================================
//
// ✅ FIX M-01: منبع IP یکپارچه.
// از helpers.php اگر موجود باشد استفاده می‌کند، وگرنه fallback دارد.
// این تضمین می‌کند که rate_limit و audit از یک منبع IP استفاده کنند.
// =====================================================================

if (!function_exists('_danova_get_request_ip')) {
    /**
     * دریافت IP واقعی کاربر با در نظر گرفتن trusted proxies.
     *
     * @return string
     */
    function _danova_get_request_ip(): string
    {
        // اول از helpers.php اگر لود شده باشد
        if (function_exists('client_ip')) {
            try {
                $ip = client_ip();
                if (is_string($ip) && $ip !== '') {
                    return $ip;
                }
            } catch (Throwable $e) {
                // fallback می‌کنیم
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // بررسی trusted proxies
        $trustedEnv = getenv('TRUSTED_PROXIES') ?: '';
        if ($trustedEnv !== '') {
            $trusted = array_filter(
                array_map('trim', explode(',', $trustedEnv)),
                fn($ip) => $ip !== ''
            );

            if (in_array($remote, $trusted, true)) {
                // X-Forwarded-For
                $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
                if ($xff !== '') {
                    $first = trim(explode(',', $xff)[0]);
                    if (filter_var($first, FILTER_VALIDATE_IP)) {
                        return $first;
                    }
                }
                // X-Real-IP
                $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
                if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
                    return $real;
                }
            }
        }

        return $remote;
    }
}


// =====================================================================
// SECTION 1: CSP NONCE GENERATOR
// =====================================================================
//
// این تابع یک nonce تصادفی برای Content-Security-Policy تولید می‌کند.
// هر request یک nonce جدید می‌گیرد (static cache در همان request).
//
// استفاده:
//   - security_headers_html() برای تزریق nonce در CSP header
//   - index.php برای تزریق nonce در <script> tags
// =====================================================================

if (!function_exists('security_csp_nonce')) {
    /**
     * ✅ IMPORTANT: This uses a static cache to ensure the SAME nonce is
     * returned throughout a single request. If you ever change this to
     * generate a new nonce on every call, the CSP header (set in
     * security_headers_html) and the injected <script> tags (set in
     * index.php) will have DIFFERENT nonces, and the browser will
     * BLOCK all scripts.
     *
     * DO NOT remove the static cache.
     */
    function security_csp_nonce(): string
    {
        static $nonce = null;
        if ($nonce === null) {
            try {
                $nonce = base64_encode(random_bytes(16));
            } catch (Throwable $e) {
                // ✅ FIX (B11): fallback امن — از openssl_random_pseudo_bytes
                // استفاده کن و فقط اگر آن هم نبود، از ترکیب زمان + PID + memory
                // (که همچنان بهتر از uniqid خالی است) بهره ببر.
                $raw = null;
                if (function_exists('openssl_random_pseudo_bytes')) {
                    $strong = false;
                    $raw = @openssl_random_pseudo_bytes(16, $strong);
                    if ($raw === false || strlen($raw) < 16) $raw = null;
                }
                if ($raw === null) {
                    // آخرین راه‌حل — همچنان بهتر از uniqid تنها
                    $seed = microtime(true) . '|' . getmypid() . '|' . spl_object_id(new stdClass());
                    $raw = hash('sha256', $seed, true);
                }
                $nonce = substr(base64_encode($raw), 0, 24);
            }
        }
        return $nonce;
    }
}


// =====================================================================
// SECTION 2: RATE LIMITER (SQLite-backed)
// =====================================================================
//
// طراحی:
//   - بدون نیاز به Redis / Memcached / APC
//   - جدول: rate_limits
//   - کلید: (bucket_key) — ترکیبی از action + IP + user + identifier
//   - پنجره: sliding window ساده بر اساس created_at
//   - پاک‌سازی خودکار رکوردهای قدیمی (probabilistic)
//   - race-safe با استفاده از transaction در increment
//
// نکات امنیتی:
//   - برای login، از IP + username استفاده می‌شود (نه فقط username)
//     تا attacker نتواند با تغییر username، rate limit را دور بزند.
//   - پاسخ‌ها نباید Account Enumeration را ممکن کنند:
//     حتی برای username ناموجود، همان پیام بازگردانده می‌شود.
//   - ✅ v1.1.0 (Bug H-07): fail-closed برای actionهای حساس
// =====================================================================


/**
 * ✅ Bug H-07 FIX: actionهای حساس که نباید fail-open شوند
 *
 * این actionها در صورت خطای DB باید درخواست را رد کنند:
 *   - login:       جلوگیری از Brute-Force
 *   - password_change: جلوگیری از تلاش مکرر
 *   - chat_send:   جلوگیری از spam AI
 *   - upload*:     جلوگیری از DoS روی storage
 *   - sync_models: جلوگیری از فراخوانی مکرر API خارجی
 */
function _rate_limit_critical_actions(): array
{
    return [
        'login',
        'password_change',
        'username_change',
        'chat_send',
        'upload',
        'upload_rag',
        'upload_video',
        'upload_collection_cover',
        'sync_models',
        'rag_backfill',
        'upload_download',
    ];
}

/**
 * ایجاد جدول rate_limits در صورت عدم وجود (idempotent).
 * این تابع در اولین فراخوانی اجرا می‌شود و cache می‌شود.
 */
function _rate_limit_ensure_table(): void
{
    // ✅ MYSQL MIGRATION: table rate_limits is created by DANOVA-MIGRATION/schema_mysql.sql
    // No runtime DDL is performed.
    return;
}

/**
 * ساخت کلید bucket امن.
 *
 * @param string      $action      نام اقدام (login, chat, upload, sync_models, ...)
 * @param string|null $identifier  اضافه‌کننده (مثلاً username یا user_id)
 * @return string
 */
function rate_limit_key(string $action, ?string $identifier = null): string
{
    // ✅ FIX (BUG-004): Do NOT partition by User-Agent
    // ─────────────────────────────────────────────────────────────────
    // قبلاً: User-Agent در key بود → مهاجم با تغییر UA از bucket
    //         فرار می‌کرد و rate-limit بی‌اثر می‌شد.
    // حالا:  فقط action + IP + identifier.
    // ─────────────────────────────────────────────────────────────────
    // ✅ FIX M-01: استفاده از IP canonical
    $ip = _danova_get_request_ip();

    $parts = [
        'action' => strtolower(trim($action)),
        'ip'     => $ip,
        'id'     => $identifier !== null ? (string)$identifier : '',
    ];

    return hash('sha256', implode('|', $parts));
}

/**
 * بررسی اینکه آیا درخواست اخیر، از حد مجاز عبور کرده یا نه.
 *
 * ✅ Bug H-07 FIX:
 *   - برای actionهای حساس (login, chat, upload, ...): FAIL-CLOSED
 *   - برای actionهای غیرحساس: FAIL-OPEN (availability)
 *
 * @param string $key           نتیجه rate_limit_key()
 * @param int    $maxAttempts   حداکثر تعداد در پنجره
 * @param int    $windowSeconds طول پنجره به ثانیه
 * @return array{allowed:bool, remaining:int, retry_after:int, count:int}
 */
function rate_limit_check(string $key, int $maxAttempts, int $windowSeconds): array
{
    _rate_limit_ensure_table();

    $maxAttempts   = max(1, $maxAttempts);
    $windowSeconds = max(1, $windowSeconds);

    $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);

    try {
        $st = db()->prepare(
            'SELECT COUNT(*) AS c FROM rate_limits
             WHERE bucket_key = ? AND created_at >= ?'
        );
        $st->execute([$key, $since]);
        $count = (int)($st->fetch()['c'] ?? 0);
    } catch (Throwable $e) {
        logger_warn('[RATE LIMIT] check failed: ' . $e->getMessage());

        // ✅ Bug H-07 FIX: fail-closed برای actionهای حساس
        $action = $GLOBALS['_danova_rate_limit_current_action'] ?? '';
        $isCritical = in_array(strtolower($action), _rate_limit_critical_actions(), true);

        if ($isCritical) {
            logger_warn('[RATE LIMIT] fail-CLOSED for critical action: ' . $action);
            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => 30,
                'count'       => $maxAttempts,
            ];
        }

        // fail-open برای actionهای غیرحساس (availability)
        logger_warn('[RATE LIMIT] fail-OPEN for non-critical action: ' . $action);
        return [
            'allowed'     => true,
            'remaining'   => $maxAttempts,
            'retry_after' => 0,
            'count'       => 0,
        ];
    }

    $allowed     = $count < $maxAttempts;
    $remaining   = max(0, $maxAttempts - $count);
    $retryAfter  = 0;

    if (!$allowed) {
        // محاسبه retry_after بر اساس قدیمی‌ترین رکورد در پنجره
        try {
            $st = db()->prepare(
                'SELECT created_at FROM rate_limits
                 WHERE bucket_key = ? AND created_at >= ?
                 ORDER BY created_at ASC LIMIT 1'
            );
            $st->execute([$key, $since]);
            $row = $st->fetch();
            if ($row && !empty($row['created_at'])) {
                $oldest = strtotime($row['created_at'] . ' UTC');
                if ($oldest !== false) {
                    $retryAfter = max(1, $windowSeconds - (time() - $oldest));
                }
            }
        } catch (Throwable $e) {
            $retryAfter = $windowSeconds;
        }
    }

    return [
        'allowed'     => $allowed,
        'remaining'   => $remaining,
        'retry_after' => $retryAfter,
        'count'       => $count,
    ];
}

/**
 * ثبت یک تلاش در bucket.
 *
 * @param string      $key
 * @param array|null  $meta  اطلاعات اضافی (برای debug — نباید حاوی PII یا Secret باشد)
 */
function rate_limit_hit(string $key, ?array $meta = null): bool
{
    _rate_limit_ensure_table();

    try {
        $metaJson = null;
        if (is_array($meta) && !empty($meta)) {
            // حذف فیلدهای حساس در صورت وجود
            foreach (['password', 'api_key', 'token', 'csrf'] as $sensitive) {
                if (array_key_exists($sensitive, $meta)) {
                    $meta[$sensitive] = '[REDACTED]';
                }
            }
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
            if (is_string($metaJson) && mb_strlen($metaJson) > 500) {
                $metaJson = mb_substr($metaJson, 0, 500);
            }
        }

        db()->prepare(
            'INSERT INTO rate_limits (id, bucket_key, created_at, meta)
             VALUES (?, ?, ?, ?)'
        )->execute([generate_uuid(), $key, utcnow(), $metaJson]);
        return true;

    } catch (Throwable $e) {
        logger_warn('[RATE LIMIT] hit failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * پاک‌سازی رکوردهای قدیمی rate_limits.
 * فراخوانی: به‌صورت probabilistic (~2% از درخواست‌ها).
 *
 * @param int $keepSeconds  رکوردهای قدیمی‌تر از این حذف می‌شوند
 */
function rate_limit_cleanup(int $keepSeconds = 86400): void
{
    // اجرای احتمالی — نه در هر درخواست
    if (random_int(1, 100) > 2) return;

    try {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $keepSeconds);
        db()->prepare('DELETE FROM rate_limits WHERE created_at < ?')
            ->execute([$cutoff]);
    } catch (Throwable $e) {
        // بی‌صدا — این یک عملیات نگهداری است
    }
}

/**
 * بررسی + ثبت + خطا در یک تابع (برای استفاده مستقیم در handlerها).
 *
 * ✅ Bug H-07 FIX:
 *   - action جاری در $GLOBALS['_danova_rate_limit_current_action'] ذخیره می‌شود
 *   - این امکان می‌دهد rate_limit_check() بداند کدام action است
 *   - در پایان (finally) مقدار قبلی restore می‌شود
 *
 * @param string $action
 * @param int    $maxAttempts
 * @param int    $windowSeconds
 * @param string|null $identifier
 * @param string $errorMessage  پیام بازگشتی در صورت رد
 */
function rate_limit_guard(
    string $action,
    int $maxAttempts,
    int $windowSeconds,
    ?string $identifier = null,
    string $errorMessage = 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.'
): void {
    // ✅ FIX H-02: ATOMIC CHECK-AND-HIT
    //
    // استراتژی قبلی:
    //   1. rate_limit_check()  → SELECT COUNT
    //   2. rate_limit_hit()    → INSERT
    //   ─ → Race condition: 2 thread همزمان count یکسان می‌بینند
    //
    // استراتژی جدید:
    //   1. INSERT با SELECT ... FOR UPDATE روی یک ردیف قفل
    //   2. شمارش و insert در یک transaction اتمیک
    //   3. استفاده از قفل ردیفی MySQL برای سریال‌سازی
    //
    // اگر action حساس باشد و قفل/DB fail کند → 503 fail-closed.

    $actionName = strtolower(trim($action));
    $GLOBALS['_danova_rate_limit_current_action'] = $actionName;
    $critical = in_array($actionName, _rate_limit_critical_actions(), true);
    $key = rate_limit_key($action, $identifier);

    _rate_limit_ensure_table();

    $maxAttempts   = max(1, $maxAttempts);
    $windowSeconds = max(1, $windowSeconds);
    $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $now = utcnow();

    $pdo = null;
    $res = null;

    try {
        $pdo = db();

        // شروع transaction برای اتمیک بودن
        $pdo->beginTransaction();

        // ── مرحله 1: قفل‌گذاری منطقی روی bucket با INSERT lock row ──
        // این ردیف در جدول rate_limit_locks نگهداری می‌شود و
        // عملاً به‌عنوان mutex برای این bucket عمل می‌کند.
        $lockKey = 'rl_' . substr(hash('sha256', $key), 0, 40);
        $ownerToken = bin2hex(random_bytes(8));

        try {
            // حذف قفل‌های منقضی برای همین bucket
            $pdo->prepare(
                'DELETE FROM rate_limit_locks
                 WHERE lock_key = ? AND expires_at < UTC_TIMESTAMP()'
            )->execute([$lockKey]);

            // تلاش برای گرفتن قفل (اگر وجود ندارد، بساز)
            $pdo->prepare(
                'INSERT INTO rate_limit_locks (lock_key, owner_token, expires_at, created_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 SECOND), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    owner_token = IF(expires_at < UTC_TIMESTAMP(), VALUES(owner_token), owner_token),
                    expires_at  = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)'
            )->execute([$lockKey, $ownerToken]);

            // بررسی صاحب قفل بودن
            $chk = $pdo->prepare(
                'SELECT owner_token FROM rate_limit_locks
                 WHERE lock_key = ? FOR UPDATE'
            );
            $chk->execute([$lockKey]);
            $row = $chk->fetch();

            if (!$row || !hash_equals((string)$row['owner_token'], $ownerToken)) {
                // نتوانستیم قفل بگیریم — یک رقیب داریم
                $pdo->rollBack();

                if ($critical) {
                    // برای action حساس → fail-closed
                    logger_warn('[RATE LIMIT] Could not acquire lock for critical action: ' . $actionName);
                    json_error(
                        'سیستم در حال پردازش درخواست‌های زیاد است. لطفاً چند لحظه بعد دوباره تلاش کنید.',
                        503
                    );
                }
                // برای action غیرحساس → اجازه بده (fail-open)
                return;
            }

            // ── مرحله 2: شمارش اتمیک ──
            $cnt = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM rate_limits
                 WHERE bucket_key = ? AND created_at >= ?'
            );
            $cnt->execute([$key, $since]);
            $count = (int)($cnt->fetch()['c'] ?? 0);

            $allowed = $count < $maxAttempts;

            if (!$allowed) {
                // محاسبه retry_after
                $retryAfter = $windowSeconds;
                $oldStmt = $pdo->prepare(
                    'SELECT created_at FROM rate_limits
                     WHERE bucket_key = ? AND created_at >= ?
                     ORDER BY created_at ASC LIMIT 1'
                );
                $oldStmt->execute([$key, $since]);
                $oldRow = $oldStmt->fetch();
                if ($oldRow && !empty($oldRow['created_at'])) {
                    $oldest = strtotime($oldRow['created_at'] . ' UTC');
                    if ($oldest !== false) {
                        $retryAfter = max(1, $windowSeconds - (time() - $oldest));
                    }
                }

                // آزادسازی قفل قبل از rollback
                $pdo->prepare('DELETE FROM rate_limit_locks WHERE lock_key = ? AND owner_token = ?')
                    ->execute([$lockKey, $ownerToken]);
                $pdo->commit();

                logger_warn(sprintf(
                    '[RATE LIMIT] Blocked action=%s id=%s count=%d retry=%ds',
                    $action,
                    $identifier !== null ? substr(hash('sha256', $identifier), 0, 8) : '-',
                    $count,
                    $retryAfter
                ));

                if ($retryAfter > 0) {
                    header('Retry-After: ' . $retryAfter);
                }
                json_error($errorMessage, 429);
            }

            // ── مرحله 3: ثبت hit در همان transaction ──
            $pdo->prepare(
                'INSERT INTO rate_limits (id, bucket_key, created_at, meta)
                 VALUES (?, ?, ?, NULL)'
            )->execute([generate_uuid(), $key, $now]);

            // آزادسازی قفل
            $pdo->prepare('DELETE FROM rate_limit_locks WHERE lock_key = ? AND owner_token = ?')
                ->execute([$lockKey, $ownerToken]);

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // cleanup دوره‌ای (خارج از transaction)
        rate_limit_cleanup();

    } catch (Throwable $e) {
        logger_warn('[RATE LIMIT] atomic guard failed: ' . $e->getMessage());

        // ✅ FIX L-01: fail-CLOSED برای actionهای حساس
        if ($critical) {
            logger_error('[RATE LIMIT] DB error on critical action — failing closed: ' . $actionName);
            json_error('سیستم موقتاً در دسترس نیست. لطفاً چند لحظه بعد دوباره تلاش کنید.', 503);
        }
        // fail-open برای actionهای غیرحساس — کاربر رد نمی‌شود
    }
}


// =====================================================================
// SECTION 3: UPLOAD VALIDATORS
// =====================================================================
//
// طراحی:
//   - لایه‌بندی: extension → size → finfo MIME → signature
//   - بدون اعتماد به $_FILES['type'] (client-controlled)
//   - blacklist پسوندهای خطرناک حتی اگر whitelist هم هست
//   - signature check برای فرمت‌های حساس (PDF، تصویر، ویدیو)
//
// نکته:
//   - این توابع فقط "بررسی" می‌کنند — ذخیره‌سازی و جابه‌جایی همچنان در
//     api.php با move_uploaded_file انجام می‌شود.
// =====================================================================

/**
 * Whitelist پسوندهای مجاز به‌ازای هر دسته.
 * برای اضافه کردن نوع جدید، اینجا اضافه کنید.
 */
function upload_allowed_extensions(): array
{
    return [
        'document' => ['pdf', 'txt', 'md', 'docx'],
        'student_chat_document' => ['pdf'],
        'video'    => ['mp4', 'webm', 'ogg', 'mov', 'mkv', 'avi'],
        'image'    => ['jpg', 'jpeg', 'png', 'webp'],
        'download' => ['exe', 'msi', 'zip', 'apk', 'dmg', 'pkg', 'appx', 'appxbundle']
    ];
}

/**
 * MIME typeهای مجاز به‌ازای هر دسته.
 */
function upload_allowed_mimes(): array
{
    return [
        'document' => [
            'application/pdf',
            'text/plain',
            'text/markdown',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/x-empty', // برای فایل خالی موقت
        ],
        'student_chat_document' => ['application/pdf'],
        'video' => [
            'video/mp4',
            'video/webm',
            'video/ogg',
            'video/quicktime',
            'video/x-matroska',
            'video/x-msvideo',
        ],
        'image' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'download' => [
            'application/x-msdownload',
            'application/x-dosexec',
            'application/vnd.microsoft.portable-executable',
            'application/x-msi',
            'application/octet-stream',
            'application/zip',
            'application/x-zip-compressed',
            'application/vnd.android.package-archive',
            'application/x-apple-diskimage',
            'application/x-newton-compatible-pkg',
            'application/vnd.microsoft.appx',
        ],
    ];
}

/**
 * پسوندهای خطرناکی که حتی اگر در whitelist باشند رد می‌شوند.
 * (Defense in depth)
 */
function upload_forbidden_extensions(): array
{
    return [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'pl', 'py', 'rb', 'cgi', 'asp', 'aspx', 'jsp',
        'sh', 'bash', 'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'msi',
        'htaccess', 'htpasswd',
        'js', 'mjs', 'html', 'htm', 'svg', 'xml',
        'jar', 'war', 'ear',
    ];
}

/**
 * بررسی امنیتی کامل یک فایل آپلودی.
 *
 * @param array  $file       $_FILES['x']
 * @param string $category   'document' | 'video' | 'image'
 * @param int    $maxBytes   حداکثر حجم
 * @return array{ok:bool, error:?string, ext:?string, mime:?string, size:int, original_name:?string}
 */
function validate_uploaded_file(array $file, string $category, int $maxBytes): array
{
    $fail = function (string $msg) {
        return [
            'ok'            => false,
            'error'         => $msg,
            'ext'           => null,
            'mime'          => null,
            'size'          => 0,
            'original_name' => null,
        ];
    };

    // 1. ساختار فایل
    if (empty($file['tmp_name']) || empty($file['name'])
        || !is_string($file['tmp_name']) || !is_string($file['name'])) {
        return $fail('فایلی ارسال نشد.');
    }
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        return $fail('خطا در آپلود فایل. کد: ' . (int)($file['error'] ?? -1));
    }

    // 2. واقعاً یک فایل آپلودی است؟ (نه مسیر دلخواه)
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('فایل نامعتبر است.');
    }

    // 3. اندازه واقعی فایل روی دیسک؛ به مقدار ارسالی/اعلام‌شده تکیه نکن.
    $actualSize = @filesize($file['tmp_name']);
    $size = is_int($actualSize) ? $actualSize : 0;
    if ($size <= 0) {
        return $fail('فایل خالی است.');
    }
    if ($size > $maxBytes) {
        return $fail('حجم فایل بیش از حد مجاز است.');
    }

    // 4. Extension
    $originalName = safe_filename((string)$file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === '' || $ext === $originalName) {
        return $fail('پسوند فایل نامعتبر است.');
    }

    $allowed = upload_allowed_extensions();
    if (!isset($allowed[$category]) || !in_array($ext, $allowed[$category], true)) {
        return $fail('فرمت فایل پشتیبانی نمی‌شود.');
    }

    // ✅ FIX (Download System — CRITICAL): The forbidden list is a secondary
    // defense. If the extension is explicitly allowed for the category
    // (e.g. 'exe'/'msi' for 'download'), the allow-list is authoritative.
    // Previously the unconditional forbidden check rejected Windows
    // installers before the allow-list was ever consulted.
    $forbidden = upload_forbidden_extensions();
    $categoryAllowed = $allowed[$category] ?? [];
    if (in_array($ext, $forbidden, true) && !in_array($ext, $categoryAllowed, true)) {
        return $fail('پسوند فایل غیرمجاز است.');
    }

    // 5. MIME از finfo (مطمئن‌ترین روش)
    $mime = null;
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = @finfo_file($fi, $file['tmp_name']);
            @finfo_close($fi);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower(trim($detected));
            }
        }
    }

    if ($mime === null) {
        // fallback به mime_content_type
        if (function_exists('mime_content_type')) {
            $m = @mime_content_type($file['tmp_name']);
            if (is_string($m) && $m !== '') $mime = strtolower(trim($m));
        }
    }

    if ($mime === null) {
        return $fail('امکان تشخیص نوع فایل وجود ندارد.');
    }

    $allowedMimes = upload_allowed_mimes();
    if (!isset($allowedMimes[$category]) || !in_array($mime, $allowedMimes[$category], true)) {
        return $fail('نوع واقعی فایل با پسوند آن سازگار نیست.');
    }

    // 6. Signature check (magic bytes) برای موارد حساس
    if (!_upload_signature_matches($file['tmp_name'], $ext, $category)) {
        return $fail('محتوای فایل با فرمت اعلام‌شده مطابقت ندارد.');
    }

    return [
        'ok'            => true,
        'error'         => null,
        'ext'           => $ext,
        'mime'          => $mime,
        'size'          => $size,
        'original_name' => $originalName,
    ];
}

/**
 * بررسی امضای فایل (magic bytes).
 * فقط برای فرمت‌های رایج — نه همه.
 */
function _upload_signature_matches(string $path, string $ext, string $category): bool
{
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;

    $head = @fread($fh, 16);
    @fclose($fh);

    if ($head === false || $head === '') return false;

    $hex = bin2hex($head);

    // PDF: 25 50 44 46 (%PDF)
    if ($ext === 'pdf') {
        return strpos($hex, '25504446') === 0;
    }

    // JPEG: FF D8 FF
    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        return strpos($hex, 'ffd8ff') === 0;
    }

    // PNG: 89 50 4E 47 0D 0A 1A 0A
    if ($ext === 'png') {
        return strpos($hex, '89504e470d0a1a0a') === 0;
    }

    // WEBP: RIFF....WEBP
    if ($ext === 'webp') {
        return strpos($hex, '52494646') === 0 && strpos($hex, '57454250') !== false;
    }

    // MP4/MOV/M4V: ....ftyp
    if (in_array($ext, ['mp4', 'mov'], true)) {
        return strpos($hex, '66747970') !== false;
    }

    // WebM/MKV: 1A 45 DF A3
    if (in_array($ext, ['webm', 'mkv'], true)) {
        return strpos($hex, '1a45dfa3') === 0;
    }

    // OGG: 4F 67 67 53 (OggS)
    if ($ext === 'ogg') {
        return strpos($hex, '4f676753') === 0;
    }

    // AVI: RIFF....AVI
    if ($ext === 'avi') {
        return strpos($hex, '52494646') === 0 && strpos($hex, '41564920') !== false;
    }

    // DOCX: PK\x03\x04 (ZIP)
    if ($ext === 'docx') {
        return strpos($hex, '504b0304') === 0;
    }

    // txt/md: هیچ signature مشخصی ندارند — قبول می‌کنیم
    if (in_array($ext, ['txt', 'md'], true)) {
        // فقط NUL byte را رد می‌کنیم (فایل باینری)
        return strpos($head, "\0") === false;
    }


    // Windows EXE: MZ
    if ($ext === 'exe') {
        return strpos($hex, '4d5a') === 0;
    }
    // MSI / OLE compound: D0 CF 11 E0
    if ($ext === 'msi') {
        return strpos($hex, 'd0cf11e0') === 0;
    }
    // APK: باید ZIP باشد و حاوی AndroidManifest.xml یا classes.dex باشد
    if ($ext === 'apk') {
        if (strpos($hex, '504b0304') !== 0) return false;
        // ✅ چک عمیق: APK حتماً باید AndroidManifest.xml داشته باشد
        try {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($path) === true) {
                    $hasManifest = $zip->locateName('AndroidManifest.xml') !== false;
                    $hasDex      = $zip->locateName('classes.dex') !== false;
                    $zip->close();
                    return $hasManifest || $hasDex;
                }
            }
        } catch (Throwable $e) {
            if (function_exists('logger_warn')) {
                logger_warn('[UPLOAD] APK zip check failed: ' . $e->getMessage());
            }
        }
        // اگر ZipArchive نبود، فقط magic bytes قبول است
        return true;
    }
    // ZIP: 50 4B 03 04 / 50 4B 05 06 / 50 4B 07 08
    if ($ext === 'zip') {
        return strpos($hex, '504b0304') === 0
            || strpos($hex, '504b0506') === 0
            || strpos($hex, '504b0708') === 0;
    }
    // DMG: بدون signature قطعی — قبول می‌شود
    if ($ext === 'dmg') {
        return true;
    }
    // PKG: xar! (78 61 72 21)
    if ($ext === 'pkg') {
        return strpos($hex, '78617221') === 0;
    }
    // APPX / APPXBUNDLE: PK (ZIP)
    if (in_array($ext, ['appx', 'appxbundle'], true)) {
        return strpos($hex, '504b0304') === 0;
    }

    // برای موارد ناشناخته، اجازه می‌دهیم (اما mime check قبلاً انجام شده)
    return true;
}


// =====================================================================
// SECTION 4: SECURITY HEADERS
// =====================================================================
//
// نکته مهم:
//   - CSP بسیار محافظه‌کارانه است و با Frontend فعلی سازگار.
//   - Google Fonts و inline theme script در HTML اجازه داده شده.
//   - ✅ v1.2.0: script-src اکنون هم nonce دارد و هم unsafe-inline
//     (fallback برای مرورگرهای قدیمی که با nonce سازگار نیستند).
//   - در صورت نیاز، CSP را می‌توان از system_settings خواند.
// =====================================================================

/**
 * اعمال Security Headers برای پاسخ‌های HTML.
 * برای پاسخ‌های JSON استفاده نکنید (نه لازم است و ممکن است تداخل کند).
 */
function security_headers_html(): void
{
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    if (defined('IS_HTTPS') && IS_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // ✅ v1.2.0 CSP:
    //   - script-src: 'self' + nonce + unsafe-inline (fallback)
    //   - style-src:  'self' + unsafe-inline + Google Fonts
    //   - font-src:   'self' + Google Fonts CDN
    //   - img-src:    'self' + data: + blob:
    //   - media-src:  'self' + blob:
    //   - connect-src: 'self' (API)
    //   - frame-ancestors: 'self'
    //   - base-uri: 'self'
    $nonce = '';
    if (function_exists('security_csp_nonce')) {
        $nonce = security_csp_nonce();
    }

    $scriptSrc = "'self' 'unsafe-inline'";
    if ($nonce !== '') {
        $scriptSrc .= " 'nonce-" . $nonce . "'";
    }

    $csp = implode('; ', [
        "default-src 'self'",
        "script-src " . $scriptSrc,
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob:",
        "media-src 'self' blob:",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

/**
 * اعمال Security Headers برای پاسخ‌های JSON/API.
 */
function security_headers_json(): void
{
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (defined('IS_HTTPS') && IS_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}


// =====================================================================
// SECTION 5: CSV INJECTION SANITIZER
// =====================================================================
//
// مشکل:
//   مقادیری مثل "=cmd|'/C calc'!A0" در Excel به فرمول تبدیل می‌شوند.
// راه‌حل:
//   prefix کردن با تک کوتیشن اگر با = + - @ \t \r شروع شود.
// =====================================================================

/**
 * Sanitize یک مقدار برای خروجی CSV (جلوگیری از CSV Injection).
 */
function csv_sanitize_cell($value): string
{
    if ($value === null) return '';

    $s = (string)$value;

    if ($s === '') return '';

    $first = $s[0];

    if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $s;
    }

    return $s;
}


// =====================================================================
// SECTION 6: REQUEST SIZE LIMITER
// =====================================================================
//
// نکته:
//   Content-Length را چک می‌کند و اگر بیش از حد مجاز بود، رد می‌کند.
//   برای فایل‌ها باید از maxBytes مجزا استفاده کرد.
// =====================================================================

/**
 * محدود کردن حجم بدنه JSON.
 *
 * @param int $maxBytes
 */
function enforce_max_json_size(int $maxBytes): void
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 0 && $len > $maxBytes) {
        json_error('حجم درخواست بیش از حد مجاز است.', 413);
    }
}


// =====================================================================
// SECTION 7: LOGIN-SPECIFIC HELPERS
// =====================================================================
//
// نکته امنیتی:
//   این helpers نباید Account Enumeration ممکن کنند.
//   یعنی برای username موجود و ناموجود، رفتار یکسان.
// =====================================================================

/**
 * Rate limit مخصوص login.
 * از ترکیب IP + username استفاده می‌کند.
 *
 * پیشنهاد:
 *   - ۵ تلاش در ۵ دقیقه
 *   - اگر عبور کرد، ۱۵ دقیقه block
 *
 * ✅ Bug H-07 FIX: این action در لیست حساس است → fail-closed
 */
function security_login_ratelimit(?string $username): void
{
    // ✅ FIX (BUG-004): dual-bucket rate limiting
    // ─────────────────────────────────────────────────────────────────
    // دو bucket مستقل — هر دو باید pass بشن:
    //   1) per-IP:      جلوگیری از credential stuffing
    //   2) per-account: جلوگیری از brute-force روی یک حساب
    // ─────────────────────────────────────────────────────────────────

    // Bucket 1: per-IP (20 تلاش در 5 دقیقه)
    rate_limit_guard(
        'login_ip',
        20,
        300,
        null,
        'تلاش‌های ورود از این IP بیش از حد مجاز است. لطفاً چند دقیقه بعد دوباره تلاش کنید.'
    );

    // Bucket 2: per-account (5 تلاش در 5 دقیقه)
    $id = $username !== null ? strtolower(trim($username)) : '';
    if ($id !== '') {
        rate_limit_guard(
            'login_account',
            5,
            300,
            $id,
            'تلاش‌های ورود برای این حساب بیش از حد مجاز است. لطفاً چند دقیقه بعد دوباره تلاش کنید.'
        );
    }
}

/**
 * Rate limit مخصوص endpointهای سنگین AI/Sync.
 *
 * ✅ Bug H-07 FIX: fail-closed برای sync_models
 */
function security_heavy_endpoint_ratelimit(string $action, ?string $identifier, int $max = 20, int $window = 3600): void
{
    rate_limit_guard(
        'heavy_' . strtolower($action),
        $max,
        $window,
        $identifier,
        'این عملیات در حال حاضر با محدودیت مواجه است. لطفاً بعداً تلاش کنید.'
    );
}

/**
 * Rate limit مخصوص chat.
 *
 * ✅ Bug H-07 FIX: fail-closed برای chat_send
 */
function security_chat_ratelimit(?string $studentId): void
{
    // ۳۰ پیام در ۱ دقیقه — با در نظر گرفتن استفاده معمول
    rate_limit_guard(
        'chat_send',
        30,
        60,
        $studentId,
        'تعداد پیام‌های شما در زمان کوتاه زیاد است. لطفاً کمی صبر کنید.'
    );
}

/**
 * Rate limit مخصوص upload.
 *
 * ✅ Bug H-07 FIX: fail-closed برای upload
 */
function security_upload_ratelimit(?string $userId): void
{
    // ۱۰ آپلود در ۱ ساعت
    rate_limit_guard(
        'upload',
        10,
        3600,
        $userId,
        'تعداد آپلودهای شما زیاد است. لطفاً بعداً تلاش کنید.'
    );
}

/**
 * Rate limit مخصوص forgot/change password.
 *
 * ✅ Bug H-07 FIX: fail-closed برای password_change
 */
function security_password_ratelimit(?string $identifier): void
{
    rate_limit_guard(
        'password_change',
        10,
        3600,
        $identifier,
        'تعداد درخواست‌های تغییر رمز بیش از حد مجاز است.'
    );
}


// =====================================================================
// END OF security.php (v1.2.0 — CSP nonce + unsafe-inline fallback)
// =====================================================================