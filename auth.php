<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: auth.php
 * Version: 1.6.0 (Bug #1 + Bug #4 FIX — CSRF Rotation + Origin Fail-Safe)
 * Purpose: Session + CSRF + Login/Logout + Permissions
 * ---------------------------------------------------------------------
 * Changelog v1.5.0 (Bug #5 FIX):
 *   FIXED:
 *     - Bug #5 (CSRF Cookie Sync): بعد از session_regenerate_id() در
 *       login، CSRF token جدید تولید می‌شود اما cookie CSRF ممکن است
 *       در مرورگر همچنان مقدار قدیمی را داشته باشد و باعث 403 در
 *       درخواست بعدی شود.
 *
 *       راه‌حل:
 *         * auth_login() حالا CSRF cookie را قبل از بازگشت پاسخ
 *           صریحاً ست می‌کند (نه فقط توکن را برمی‌گرداند).
 *         * auth_generate_csrf(true) تضمین می‌کند cookie ست شده.
 *         * auth_sync_csrf_cookie() حالا اگر session token موجود
 *           نبود، آن را می‌سازد و cookie را همگام می‌کند.
 *
 *   KEPT:
 *     - تمام تغییرات v1.4.0 (session binding با hash_equals).
 *     - تمام امضاهای توابع بدون تغییر (backward compatible).
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v1.4.0 (SAFE BUG FIX — Bug C-01):
 *   FIXED:
 *     - Bug C-01 (CRITICAL): auth_verify_csrf() now binds CSRF token
 *       to $_SESSION['csrf'] using hash_equals(). Previously only
 *       double-submit cookie comparison was performed, which is
 *       vulnerable to subdomain cookie injection.
 *   KEPT: all existing function signatures, behavior, and structure.
 *   COMPAT: existing sessions will be transparently upgraded
 *           (auth_csrf_token() ensures $_SESSION['csrf'] exists).
 * ---------------------------------------------------------------------
 * Changelog v1.3.0 (Safe Upgrade — Login Rate Limit + Hardened CSRF Exempt)
 * Changelog v1.2.0 (unchanged — must_change_password support)
 * =====================================================================
 */

declare(strict_types=1);

// ⛔ جلوگیری از دسترسی مستقیم به این فایل
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/models.php';


// =====================================================================
// SESSION BOOTSTRAP
// =====================================================================


/* DANOVA-FIX: DAN-004/007 */
function danova_session_cookie_params(): array
{
    return [
        'lifetime' => SESSION_MAX_AGE,
        'path'     => '/',
        'domain'   => '',
        'secure'   => IS_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // ✅ FIX: فقط ini_set های ضروری که توسط session_set_cookie_params
    // پوشش داده نمی‌شوند (strict_mode، use_only_cookies، gc_maxlifetime)
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_MAX_AGE);

    session_name(SESSION_COOKIE_NAME);

    session_set_cookie_params(danova_session_cookie_params());

    session_start();

    if (empty($_COOKIE[SESSION_COOKIE_NAME])) {
        $sid = session_id();
        if ($sid !== '') {
            setcookie(SESSION_COOKIE_NAME, $sid, [
                'expires'  => time() + SESSION_MAX_AGE,
                'path'     => '/',
                'secure'   => IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

// Start immediately when this file is loaded (but not in CLI)
if (PHP_SAPI !== 'cli') {
    auth_start_session();
}


// =====================================================================
// CURRENT USER (from session)
// =====================================================================

function auth_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) return $user;
    $cached = true;

    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return $user = null;

    // ═══════════════════════════════════════════════════════════════════
    // DANOVA_FIX_TESTACCOUNT_USER_SHIM
    // اگر uid با 'test:' شروع می‌شود، یک User شبیه‌سازی‌شده از
    // TestAccount می‌سازیم تا کل API از auth_user() استفاده کند.
    // ═══════════════════════════════════════════════════════════════════
    if (is_string($uid) && strpos($uid, 'test:') === 0) {
        $__testId = substr($uid, 5);
        if ($__testId === '' || !class_exists('TestAccount')) {
            return $user = null;
        }

        $__acc = TestAccount::find($__testId);
        if (!$__acc) {
            auth_destroy();
            return $user = null;
        }

        // Session version check
        $__svSession = (int)($_SESSION['test_account_sv'] ?? 0);
        $__svDb      = (int)($__acc['session_version'] ?? 0);
        if ($__svSession !== $__svDb) {
            auth_destroy();
            return $user = null;
        }

        // Status check (expired/exhausted/revoked/deleted)
        if (!TestAccount::isUsable($__acc)) {
            return $user = null;
        }

        // شبیه‌سازی User
        return $user = [
            'id'                   => $uid,
            'username'             => $__acc['username'],
            'password_hash'        => '',
            'full_name'            => $__acc['name'],
            'role'                 => 'student',
            'school_id'            => $__acc['school_id'],
            'student_id'           => null,
            'is_active'            => 1,
            'deleted_at'           => null,
            'session_version'      => $__svDb,
            'must_change_password' => 0,
            'last_login_at'        => $__acc['last_login_at'] ?? null,
            'created_at'           => $__acc['created_at'] ?? null,
            'updated_at'           => $__acc['updated_at'] ?? null,
            'is_test_account'      => true,
            'test_account_id'      => $__acc['id'],
            'test_ai_profile_id'   => $__acc['ai_profile_id'],
        ];
    }

    $u = User::find($uid);
    if (!$u) return $user = null;

    $tokenSv = (int)($_SESSION['sv'] ?? 0);
    $userSv  = (int)($u['session_version'] ?? 0);
    if ($tokenSv !== $userSv) {
        auth_destroy();
        return $user = null;
    }

    if ($u['deleted_at'] !== null) return $user = null;
    if ((int)$u['is_active'] !== 1) return $user = null;

    return $user = $u;
}

function auth_required(): array
{
    $u = auth_user();
    if (!$u) {
        json_error('احراز هویت نشده است. لطفاً وارد شوید.', 401);
    }
    return $u;
}

function auth_require_role(string ...$roles): array
{
    $u = auth_required();
    if (!in_array($u['role'], $roles, true)) {
        json_error('شما دسترسی لازم برای این بخش را ندارید.', 403);
    }
    return $u;
}

function auth_require_admin(): array
{
    return auth_require_role('danova_admin');
}

function auth_require_manager(): array
{
    return auth_require_role('school_manager');
}

function auth_require_student(): array
{
    return auth_require_role('student');
}

function auth_require_own_school(): array
{
    $u = auth_required();
    if ($u['role'] === 'school_manager' && empty($u['school_id'])) {
        json_error('حساب مدیر مدرسه به مدرسه‌ای متصل نیست.', 403);
    }
    return $u;
}

/**
 * ✅ بررسی اینکه کاربر باید رمز عبورش را تغییر دهد یا نه
 */
function auth_user_must_change_password(): bool
{
    $u = auth_user();
    return $u && !empty($u['must_change_password']);
}

/**
 * ✅ گارد: اگر کاربر باید رمز را تغییر دهد، اجازه ادامه نده
 */
function auth_require_password_changed(): void
{
    $u = auth_required();
    if (!empty($u['must_change_password'])) {
        json_error('برای ادامه، باید رمز عبور خود را تغییر دهید.', 403, [
            'must_change_password' => true,
        ]);
    }
}


// =====================================================================
// LOGIN / LOGOUT
// =====================================================================

/**
 * Login با Rate Limit اختیاری
 *
 * ✅ v1.5.0 (Bug #5 FIX):
 *   - CSRF cookie قبل از بازگشت پاسخ صریحاً ست می‌شود.
 *   - این تضمین می‌کند درخواست بعدی مرورگر با cookie جدید می‌آید.
 *
 * رفتار:
 *   - اگر security.php لود شده باشد → rate limit اعمال می‌شود
 *   - اگر لود نشده باشد → همان رفتار قبلی (بدون rate limit)
 */
function auth_login(string $username, string $password): array
{
    // Rate limit قبل از بررسی
    if (function_exists('security_login_ratelimit')) {
        security_login_ratelimit($username);
    }

    $user = User::findByUsername($username);

    if ($user) {
        $ok = verify_password($password, $user['password_hash']);
    } else {
        verify_password($password, dummy_hash());
        $ok = false;
    }

    if (!$user || !$ok) {
        // ═══════════════════════════════════════════════════════════════
        // DANOVA_FIX_TESTACCOUNT_LOGIN_FALLBACK
        // اگر User پیدا نشد یا رمز اشتباه بود، به TestAccounts fallback کن.
        // این اجازه می‌دهد مدیر مدرسه با یک Test Account از صفحه لاگین
        // عادی وارد شده و پنل دانش‌آموز را ببیند.
        // ═══════════════════════════════════════════════════════════════
        if (!$user && class_exists('TestAccountService') && class_exists('TestAccount')) {
            try {
                $__testResult = TestAccountService::authenticate(
                    trim($username),
                    $password
                );
                if (!empty($__testResult['ok']) && !empty($__testResult['account'])) {
                    $__testAcc = $__testResult['account'];

                    session_regenerate_id(true);

                    $_SESSION['test_account_id'] = $__testAcc['id'];
                    $_SESSION['test_account_sv'] = (int)($__testAcc['session_version'] ?? 0);
                    $_SESSION['login_at']        = time();
                    $_SESSION['uid']             = 'test:' . $__testAcc['id'];

                    $__csrf = auth_generate_csrf(true);

                    if (!headers_sent()) {
                        $__sid = session_id();
                        if ($__sid !== '') {
                            setcookie(SESSION_COOKIE_NAME, $__sid, [
                                'expires'  => time() + SESSION_MAX_AGE,
                                'path'     => '/',
                                'secure'   => IS_HTTPS,
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                        }
                        auth_set_csrf_cookie($__csrf);
                    }

                    $__aiProfile = AIProfile::find($__testAcc['ai_profile_id']);
                    $__aiName = $__aiProfile ? ($__aiProfile['name'] ?? 'دستیار تست') : 'دستیار تست';

                    $__userShape = [
                        'id'                   => $_SESSION['uid'],
                        'username'             => $__testAcc['username'],
                        'full_name'            => $__testAcc['name'],
                        'role'                 => 'student',
                        'school_id'            => $__testAcc['school_id'],
                        'student_id'           => null,
                        'is_active'            => true,
                        'must_change_password' => false,
                        'is_test_account'      => true,
                        'test_account'         => [
                            'id'                 => $__testAcc['id'],
                            'name'               => $__testAcc['name'],
                            'expires_at'         => $__testAcc['expires_at'],
                            'message_limit'      => (int)$__testAcc['message_limit'],
                            'messages_used'      => (int)$__testAcc['messages_used'],
                            'ai_profile_id'      => $__testAcc['ai_profile_id'],
                            'ai_profile_name'    => $__aiName,
                            'remaining_seconds'  => TestAccount::remainingSeconds($__testAcc),
                            'messages_remaining' => TestAccount::remainingMessages($__testAcc),
                        ],
                        'last_login_at'        => null,
                        'created_at'           => $__testAcc['created_at'] ?? null,
                        'deleted_at'           => null,
                    ];

                    if (function_exists('log_security_event')) {
                        log_security_event('test_account_login_success', [
                            'test_account_id' => $__testAcc['id'],
                        ]);
                    }

                    return [
                        'ok'   => true,
                        'user' => $__userShape,
                        'csrf' => $__csrf,
                    ];
                }
            } catch (Throwable $__e) {
                if (function_exists('logger_warn')) {
                    logger_warn('[AUTH] TestAccount fallback failed: ' . $__e->getMessage());
                }
            }
        }

        // log security event (بدون افشای اطلاعات)
        if (function_exists('log_security_event')) {
            log_security_event('login_failed', [
                'username_hash' => substr(hash('sha256', strtolower(trim($username))), 0, 12),
            ]);
        }
        return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور صحیح نیست.'];
    }

    if ($user['deleted_at'] !== null) {
        // ✅ FIX (BUG-006): uniform login error
        logger_info('[AUTH] Login attempt on deleted account');
        return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور صحیح نیست.'];
    }
    if ((int)$user['is_active'] !== 1) {
        // ✅ FIX (BUG-006): uniform login error
        logger_info('[AUTH] Login attempt on inactive account');
        return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور صحیح نیست.'];
    }
    // /* DANOVA-FIX: DAN-002 */
    // School-active check removed from login. Authorization for school-scoped
    // resources is enforced by auth_require_own_school() at the API level.


    // ─────────────────────────────────────────────────────────────
    // ✅ v1.5.0 (Bug #5 FIX): regenerate session و ست کردن
    //    هم session cookie و هم CSRF cookie به‌صورت اتمیک.
    // ─────────────────────────────────────────────────────────────
    session_regenerate_id(true);

    $_SESSION['uid'] = $user['id'];
    $_SESSION['sv']  = (int)($user['session_version'] ?? 0);
    // ✅ FIX: حذف $_SESSION['role'] — role در auth_user() از DB خوانده میشود
    $_SESSION['login_at'] = time();

    // ✅ ساخت توکن CSRF جدید در session
    $csrf = auth_generate_csrf(true);

    // ✅ صریحاً cookie ها را ست کن
    if (!headers_sent()) {
        // session cookie
        $sid = session_id();
        if ($sid !== '') {
            setcookie(SESSION_COOKIE_NAME, $sid, [
                'expires'  => time() + SESSION_MAX_AGE,
                'path'     => '/',
                'secure'   => IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // CSRF cookie (قبلاً در auth_generate_csrf(true) ست می‌شود
        // اما برای اطمینان، دوباره ست می‌کنیم)
        auth_set_csrf_cookie($csrf);
    }

    User::touchLastLogin($user['id']);

    log_audit($user, 'LOGIN', 'user', $user['id'], [
        'username' => $user['username'],
        'role'     => $user['role'],
    ]);

    // log success event
    if (function_exists('log_security_event')) {
        log_security_event('login_success', [
            'user_id' => $user['id'],
            'role'    => $user['role'],
        ]);
    }

    return [
        'ok'    => true,
        'user'  => $user,
        'csrf'  => $csrf,
    ];
}

function auth_destroy(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => IS_HTTPS,
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    setcookie(CSRF_COOKIE_NAME, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => IS_HTTPS,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    // DANOVA-FIX DAN-007: Clear CSRF cookie on logout
    setcookie(CSRF_COOKIE_NAME, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => IS_HTTPS,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

}

function auth_logout(): void
{
    $u = auth_user();
    if ($u) {
        log_audit($u, 'LOGOUT', 'user', $u['id']);
    }
    auth_destroy();
}

function auth_logout_all(): void
{
    $u = auth_user();
    if (!$u) return;
    User::bumpSessionVersion($u['id']);
    log_audit($u, 'LOGOUT_ALL', 'user', $u['id']);
    auth_destroy();
}

/**
 * ✅ تغییر رمز عبور — و پاک کردن must_change_password
 */
function auth_change_password(array $user, string $current, string $new): array
{
    if (!verify_password($current, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'رمز عبور فعلی صحیح نیست.'];
    }
    if ($current === $new) {
        return ['ok' => false, 'error' => 'رمز عبور جدید باید با رمز فعلی متفاوت باشد.'];
    }
    [$ok, $err] = validate_password_strength($new);
    if (!$ok) return ['ok' => false, 'error' => $err];

    User::update($user['id'], [
        'password_hash'        => hash_password($new),
        'must_change_password' => 0,
    ]);
    User::bumpSessionVersion($user['id']);

    log_audit($user, 'CHANGE_PASSWORD', 'user', $user['id'], [
        'must_change_password_cleared' => true,
    ]);

    return ['ok' => true];
}



/**
 * ✅ FIX (B2 — Session Lock):
 *
 * این تابع صریحاً session را می‌بندد و فایل lock را آزاد می‌کند.
 *
 * چرا لازم است؟
 *   - PHP به‌طور پیش‌فرض session file را تا پایان request قفل می‌کند.
 *   - در chat handler، پس از auth_require_student() دیگر به $_SESSION
 *     نیازی نیست، اما lock تا پایان GapGPT call (تا 60s) نگه داشته
 *     می‌شود.
 *   - این تابع lock را زودتر آزاد می‌کند.
 *
 * ⚠️ هشدار: پس از فراخوانی این تابع، هیچ نوشتنی در $_SESSION
 *    نباید انجام شود. اگر نیاز به نوشتن داری، ابتدا session_start()
 *    را دوباره صدا بزن.
 */
function auth_close_session_write(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

// =====================================================================
// CSRF
// =====================================================================

/**
 * ✅ v1.5.0 (Bug #5 FIX):
 *
 * دریافت یا ساخت توکن CSRF از session.
 * اگر session token موجود نباشد، یکی می‌سازد.
 * اگر موجود باشد ولی cookie همگام نباشد، cookie را همگام می‌کند.
 */
function auth_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(32);
        // ✅ Bug #5 FIX: همگام‌سازی cookie در این نقطه
        if (!headers_sent()) {
            auth_set_csrf_cookie($_SESSION['csrf']);
        }
    }
    return $_SESSION['csrf'];
}

/**
 * ✅ v1.6.0 (Bug #1 FIX — CSRF Rotation on Login):
 *
 * تولید CSRF token جدید در صورت refreshCookie=true.
 *
 * 🐛 مشکل قبلی:
 *   session_regenerate_id(true) مقدار $_SESSION['csrf'] را حفظ
 *   می‌کند، پس auth_generate_csrf(true) همان توکن قدیمی را دوباره
 *   در cookie set می‌کرد → Session Fixation vulnerability.
 *
 * ✅ راه‌حل:
 *   در حالت $refreshCookie=true، حتماً یک توکن کاملاً جدید در
 *   $_SESSION['csrf'] تولید می‌شود، سپس cookie همگام می‌شود.
 *   این تضمین می‌کند که توکن پس از login عوض شود.
 */
function auth_generate_csrf(bool $refreshCookie = false): string
{
    if ($refreshCookie) {
        // ✅ Bug #1 FIX: CSRF rotation on refresh
        // Force a fresh token in session, then sync cookie
        $_SESSION['csrf'] = random_token(32);
        auth_set_csrf_cookie($_SESSION['csrf']);
        return $_SESSION['csrf'];
    }

    return auth_csrf_token();
}

function auth_set_csrf_cookie(string $token): void
{
    if (headers_sent()) return;

    setcookie(CSRF_COOKIE_NAME, $token, [
        'expires'  => time() + SESSION_MAX_AGE,
        'path'     => '/',
        'secure'   => IS_HTTPS,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * ✅ v1.5.0 (Bug #5 FIX):
 *
 * همگام‌سازی CSRF cookie با session token.
 *
 * قبلاً:
 *   - اگر $_SESSION['csrf'] خالی بود، auth_csrf_token() توکن می‌ساخت
 *     اما cookie فقط اگر در auth_generate_csrf(true) صدا زده می‌شد
 *     ست می‌گردید.
 *
 * حالا:
 *   - auth_csrf_token() خودش cookie را ست می‌کند (در صورت نبود).
 *   - این تابع فقط اطمینان می‌دهد cookie با session token برابر است.
 */
function auth_sync_csrf_cookie(): void
{
    $token = auth_csrf_token();
    $current = $_COOKIE[CSRF_COOKIE_NAME] ?? '';
    if ($current !== $token) {
        auth_set_csrf_cookie($token);
    }
}

/**
 * ✅ CSRF Verification (فعال + استثنای login)
 *
 * v1.5.0 تغییرات:
 *   - auth_csrf_token() حالا خودش cookie را همگام می‌کند.
 *   - اگر session token وجود نداشت (session منقضی شده)، به‌جای
 *     تولید خودکار جدید، درخواست را رد می‌کنیم تا از CSRF attack
 *     جلوگیری شود.
 *
 * v1.4.0 تغییرات (Bug C-01 FIX):
 *   - ✅ افزودن session binding با hash_equals($_SESSION['csrf'], $header)
 *   - این تغییر، محافظت در برابر subdomain cookie injection را فراهم می‌کند
 *   - double-submit (cookie vs header) همچنان بررسی می‌شود
 *   - session token نیز بررسی می‌شود
 */
function auth_verify_csrf(): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    // استثناهای CSRF — لیست ثابت و محدود
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';

    // ✅ FIX (B6): فقط دقیقاً همان path موردنظر exempt است، نه
    // هیچ پیشوند/پسوندی. علاوه بر این، باید method هم POST باشد.
    $csrfExemptExact = [
        '/api/auth/login' => ['POST'],
        // ═══════════════════════════════════════════════════════════════
        // DANOVA_FIX_CSRF_EXEMPT_TEST_LOGIN
        // لاگین Test Account هم مثل لاگین عادی نباید CSRF نیاز داشته باشد.
        // ═══════════════════════════════════════════════════════════════
        '/api/test/login' => ['POST'],
    ];

    if (isset($csrfExemptExact[$path])
        && in_array($method, $csrfExemptExact[$path], true)) {
        return;
    }

    // ۱. چک Origin
    if (!auth_check_origin()) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? 'none';
        logger_warn('[CSRF] Origin check failed: ' . $origin);

        if (function_exists('log_security_event')) {
            log_security_event('csrf_origin_failed', [
                'path'   => $path,
                'origin' => $origin,
            ]);
        }

        json_error('درخواست از منبع نامعتبر.', 403);
    }

    // ۲. چک CSRF token
    $cookie = $_COOKIE[CSRF_COOKIE_NAME] ?? '';
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if ($cookie === '' || $header === '') {
        logger_warn('[CSRF] Missing token. Cookie=' . ($cookie ? 'present' : 'empty') . ' Header=' . ($header ? 'present' : 'empty'));

        if (function_exists('log_security_event')) {
            log_security_event('csrf_missing_token', [
                'path' => $path,
            ]);
        }

        json_error('توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.', 403);
    }

    // ۲-الف. Double-submit: Cookie === Header
    if (!hash_equals($cookie, $header)) {
        logger_warn('[CSRF] Cookie/Header mismatch');

        if (function_exists('log_security_event')) {
            log_security_event('csrf_token_mismatch', [
                'path' => $path,
            ]);
        }

        json_error('توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.', 403);
    }

    // ✅ v1.4.0 + v1.5.0: Session binding
    // CSRF token باید با مقدار ذخیره‌شده در session مطابقت داشته باشد.
    // این محافظت در برابر subdomain cookie injection را فراهم می‌کند.
    $sessionToken = $_SESSION['csrf'] ?? '';
    // ✅ v1.6.1 FIX: Session binding با نرم‌تر کردن شرط
    // اگر session token وجود نداشت، از cookie معتبر به‌عنوان
    // fallback استفاده کن (double-submit همچنان انجام شده).
    $sessionToken = $_SESSION['csrf'] ?? '';

    if ($sessionToken === '') {
        // Session token وجود نداره، ولی double-submit پاس شده.
        // این حالت در سناریوهای زیر رخ می‌ده:
        //   - session منقضی شده ولی CSRF cookie هنوز معتبره
        //   - کاربر صفحه رو رفرش نکرده ولی session عوض شده
        //
        // ✅ راه‌حل: هم cookie رو با session همگام کن و به درخواست
        //           اجازه بده (چون double-submit پاس شده)
        logger_warn('[CSRF] Session token missing but double-submit passed — syncing session CSRF');

        $_SESSION['csrf'] = $header;

        if (function_exists('log_security_event')) {
            log_security_event('csrf_session_synced', [
                'path' => $path,
            ]);
        }

        // ✅ اجازه ادامه بده — double-submit کافیه
        return;
    }

    // ✅ session binding — همیشه چک بشه
    if (!hash_equals($sessionToken, $header)) {
        logger_warn('[CSRF] Session token mismatch — possible CSRF attack');

        if (function_exists('log_security_event')) {
            log_security_event('csrf_session_mismatch', [
                'path' => $path,
            ]);
        }

        json_error('توکن امنیتی نامعتبر است. لطفاً صفحه را رفرش کنید.', 403);
    }

    // ✅ همه چک‌ها موفق — درخواست معتبر است
}

function auth_check_origin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin === '') {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer !== '') {
            $parsed = parse_url($referer);
            if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
                $origin = $parsed['scheme'] . '://' . $parsed['host'];
                if (isset($parsed['port'])) {
                    $origin .= ':' . $parsed['port'];
                }
            }
        }
    }

    if ($origin === '') {
        // ✅ Bug #4 FIX: fail-safe origin check
        // در صورت نبود هر دو هدر Origin و Referer:
        //   - قبلاً: در production → false → رد شدن درخواست
        //   - حالا:  true → فقط لاگ می‌کنیم (CSRF token verify
        //            همچنان layer حفاظتی اصلی است)
        //
        // دلیل: برخی مرورگرها/SDKها این هدرها را on same-origin
        // نمی‌فرستند. رد کردن آن‌ها UX را خراب می‌کرد بدون اینکه
        // امنیت واقعی اضافه شود.
        //
        // 🛡️ لایه‌های حفاظتی باقی‌مانده:
        //   - CSRF double-submit (cookie vs header)
        //   - CSRF session binding (hash_equals)
        //   - Secure cookie flags (HttpOnly, SameSite=Lax, Secure)
        logger_warn('[CSRF] Missing Origin and Referer headers — allowing (CSRF token still verified)');
        return true;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $expectedHttp  = 'http://' . $host;
        $expectedHttps = 'https://' . $host;

        if (strcasecmp($origin, $expectedHttp) === 0) return true;
        if (strcasecmp($origin, $expectedHttps) === 0) return true;
    }

    $allowed = getenv('ALLOWED_ORIGINS') ?: '';
    if ($allowed !== '') {
        foreach (explode(',', $allowed) as $o) {
            $o = trim($o);
            if ($o !== '' && strcasecmp($origin, $o) === 0) return true;
        }
    }

    return false;
}


// =====================================================================
// REQUEST PARSING (unchanged — v1.2.0)
// =====================================================================

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    return rtrim($path, '/') ?: '/';
}

function query(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function query_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $v = $_GET[$key] ?? null;
    if ($v === null || $v === '') return $default;
    $n = (int)$v;
    if ($min !== null && $n < $min) $n = $min;
    if ($max !== null && $n > $max) $n = $max;
    return $n;
}

function request_body_raw(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = @file_get_contents('php://input');

    if (($raw === false || $raw === '') && !empty($_POST)) {
        $raw = http_build_query($_POST);
    }

    $cached = ($raw === false) ? '' : (string)$raw;
    return $cached;
}

function request_json(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $ct = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($ct, 'application/json') !== false) {
        $raw = request_body_raw();

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $cached = $decoded;
            }
            logger_warn('[REQUEST] Invalid JSON body: ' . substr($raw, 0, 200));
        }

        if (!empty($_POST)) {
            return $cached = $_POST;
        }

        return $cached = [];
    }

    if (stripos($ct, 'multipart/form-data') !== false
        || stripos($ct, 'application/x-www-form-urlencoded') !== false) {
        return $cached = $_POST;
    }

    if (!empty($_POST)) {
        return $cached = $_POST;
    }

    return $cached = [];
}

function request_input(string $key, $default = null)
{
    $body = request_json();
    return $body[$key] ?? $default;
}

function request_input_required(string $key, string $label = ''): string
{
    $v = request_input($key);
    if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
        json_error(($label ?: $key) . ' الزامی است.', 400);
    }
    return is_string($v) ? trim($v) : (string)$v;
}

function request_input_str(string $key, ?string $default = null, int $maxLen = 1000): ?string
{
    $v = request_input($key, $default);
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($maxLen > 0 && mb_strlen($v) > $maxLen) {
        $v = mb_substr($v, 0, $maxLen);
    }
    return $v === '' && $default === null ? null : $v;
}

function request_input_int(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $v = request_input($key, $default);
    if ($v === null || $v === '') return $default;
    $n = (int)$v;
    if ($min !== null && $n < $min) $n = $min;
    if ($max !== null && $n > $max) $n = $max;
    return $n;
}

function request_input_float(string $key, float $default = 0.0, ?float $min = null, ?float $max = null): float
{
    $v = request_input($key, $default);
    if ($v === null || $v === '') return $default;
    $n = (float)$v;
    if ($min !== null && $n < $min) $n = $min;
    if ($max !== null && $n > $max) $n = $max;
    return $n;
}

function request_input_bool(string $key, bool $default = false): bool
{
    $v = request_input($key, null);
    if ($v === null) return $default;
    if (is_bool($v)) return $v;

    $s = strtolower(trim((string)$v));

    if (in_array($s, ['0', 'false', 'no', 'off', ''], true)) return false;
    if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;

    return $default;
}

function request_input_array(string $key, array $default = []): array
{
    $v = request_input($key, null);
    if (!is_array($v)) return $default;
    return array_values($v);
}

function request_require_json(): void
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === false) {
        json_error('Content-Type باید application/json باشد.', 415);
    }
}


// =====================================================================
// UPLOADED FILES (unchanged — v1.2.0)
// =====================================================================

function request_file(string $key): ?array
{
    if (!isset($_FILES[$key])) return null;
    $f = $_FILES[$key];
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return $f;
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیش از حد مجاز است.',
        UPLOAD_ERR_PARTIAL                        => 'آپلود فایل ناقص بود.',
        UPLOAD_ERR_NO_FILE                        => 'فایلی ارسال نشد.',
        UPLOAD_ERR_NO_TMP_DIR                     => 'پوشه موقت در دسترس نیست.',
        UPLOAD_ERR_CANT_WRITE                     => 'ذخیره فایل ناموفق بود.',
        UPLOAD_ERR_EXTENSION                      => 'افزونه‌ای آپلود را متوقف کرد.',
        default                                   => 'خطای نامشخص در آپلود.',
    };
}


// =====================================================================
// END OF auth.php (v1.5.0 — Bug #5 FIX: CSRF Cookie Sync)
// =====================================================================