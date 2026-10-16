<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: index.php
 * Version: 5.0.0 (Lifecycle Migrations Bootstrap + Merged HTML Shell)
 * Purpose: SPA + API Router + Static Asset Server + Full HTML Shell
 *          + Lifecycle Migrations Bootstrap
 * ---------------------------------------------------------------------
 * Changelog v5.0.0 (LIFECYCLE MIGRATIONS):
 *   ADDED:
 *     - فراخوانی danova_run_lifecycle_migrations() بعد از require api.php
 *       با flag روزانه (یک‌بار در روز).
 *     - این کار Circular Dependency را حل می‌کند چون تابع lifecycle
 *       اکنون داخل api.php تعریف شده است.
 *   KEPT:
 *     - تمام منطق v4.0.0 (Static files, API routes, SPA fallback,
 *       CSP nonce injection, Path containment).
 *     - HTML Shell inline (merged از index.html).
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v4.0.0 (MERGED):
 *   MERGED:
 *     - محتوای کامل index.html داخل همین فایل قرار گرفت.
 *     - دیگر نیازی به فایل جداگانه index.html نیست.
 *   KEPT:
 *     - تمام منطق v3.5.2 (Static files, API routes, SPA fallback,
 *       CSP nonce injection, Path containment).
 * ---------------------------------------------------------------------
 * Changelog v3.5.2 (SECURITY FIX — Path Containment):
 *   FIXED:
 *     - Generic Static Files Handler: path containment check is now
 *       separator-aware.
 * ---------------------------------------------------------------------
 * Changelog v3.5.1 (CSP FIX):
 *   ADDED:
 *     - تزریق خودکار nonce به همه <script> tags برای رفع خطای CSP.
 * =====================================================================
 */

declare(strict_types=1);

// ⛔ هیچ خروجی قبل از header نباید داشته باشیم
while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_start();

// 1. Define front controller flag (required by api.php)
if (!defined('DANOVA_FRONT_CONTROLLER')) {
    define('DANOVA_FRONT_CONTROLLER', true);
}

// 2. Load core
// ✅ FIX: Order matters — auth.php باید قبل از config.php بارگذاری شود
// چون config.php در سطح فایل ini_set های مربوط به session را انجام می‌دهد
// و session نباید فعال باشد. auth.php تابع auth_start_session() را
// در سطح فایل صدا می‌زند که session را شروع می‌کند.
// ✅ FIXED by fix_danova.py: config.php BEFORE auth.php
// (auth.php uses SESSION_MAX_AGE from config.php)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
}

require_once __DIR__ . '/api.php';

// =====================================================================
// ✅ FIX (Bug #7): Global Exception Handler
// ---------------------------------------------------------------------
// هر Throwable که در api.php مدیریت نشده باشد، به پاسخ JSON معتبر
// تبدیل می‌شود تا فرانت‌اند بتواند آن را پارس کند.
// =====================================================================
set_exception_handler(function (Throwable $e) {
    logger_error('[UNCAUGHT] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    $detail = APP_ENV === 'production'
        ? 'خطای داخلی سرور. لطفاً دوباره تلاش کنید.'
        : ('Uncaught: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'detail'  => $detail,
        'status'  => 500,
    ], JSON_UNESCAPED_UNICODE);

    exit;
});

// =====================================================================
// 2b. LIFECYCLE MIGRATIONS (once per day)
// ---------------------------------------------------------------------
// چون تابع danova_run_lifecycle_migrations() اکنون در api.php
// تعریف شده، فقط بعد از require api.php قابل دسترسی است.
// برای جلوگیری از اجرای مکرر، از flag روزانه استفاده می‌کنیم.
// =====================================================================
if (PHP_SAPI !== 'cli' && function_exists('danova_run_lifecycle_migrations')) {
    $lifecycleFlag = DATA_DIR . '/.lifecycle_migrations_' . gmdate('Y-m-d');
    if (!file_exists($lifecycleFlag)) {
        try {
            danova_run_lifecycle_migrations(db());
            @touch($lifecycleFlag);

            // پاک‌سازی فلگ‌های قدیمی‌تر از ۷ روز
            foreach (glob(DATA_DIR . '/.lifecycle_migrations_*') as $old) {
                if (filemtime($old) < time() - 7 * 86400) {
                    @unlink($old);
                }
            }
        } catch (Throwable $e) {
            logger_warn('[BOOT] lifecycle migrations failed: ' . $e->getMessage());
        }
    }
}

// 3. Parse request
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

// =====================================================================
// 3b. ⚡ CRITICAL STATIC ASSETS (Direct Serve)
// ---------------------------------------------------------------------
// این بخش قبل از هر چیز اجرا می‌شود تا مطمئن شویم فایل‌های حیاتی
// (app.js, style.css) همیشه با Content-Type صحیح سرو شوند.
// =====================================================================

$criticalAssets = [
    '/app.js'        => 'application/javascript; charset=utf-8',
    '/style.css'     => 'text/css; charset=utf-8',
];

if (isset($criticalAssets[$path]) && $method === 'GET') {
    $file = __DIR__ . $path;

    if (is_file($file) && is_readable($file)) {
        // پاک کردن هر خروجی احتمالی قبلی
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Failed to read asset.';
            exit;
        }

        // ✅ حذف UTF-8 BOM در صورت وجود (علت اصلی نمایش کد در مرورگر)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        // ✅ هدرهای صحیح
        if (!headers_sent()) {
            header('Content-Type: ' . $criticalAssets[$path]);
            header('Content-Length: ' . strlen($content));
            // ✅ FIX (BUG-009): version-based cache-busting
            // ─────────────────────────────────────────────────────
            // قبلاً: immutable با max-age=86400 → بعد از deploy،
            //         مرورگر تا 1 روز نسخه قدیمی را نگه می‌داشت.
            // حالا:  ETag + short max-age + must-revalidate.
            // ─────────────────────────────────────────────────────
            $__etag = '"' . md5($content) . '"';
            $__if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            header('ETag: ' . $__etag);
            if ($__if_none_match === $__etag) {
                http_response_code(304);
                exit;
            }
            header('Cache-Control: public, max-age=300, must-revalidate');
            header('X-Content-Type-Options: nosniff');
            header('Access-Control-Allow-Origin: *');
        }

        echo $content;
        exit;
    }

    // فایل پیدا نشد → 404 تمیز
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found: ' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    exit;
}

// 4. Health check
if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. API routes → api_handle()
if (strpos($path, '/api/') === 0 || $path === '/api') {
    api_handle($path, $method);
    exit;
}

// ✅ FIX (BUG-001): Block private directories
// ─────────────────────────────────────────────────────────────────────
// جلوگیری از سرو فایل‌های داخل data/ و مسیرهای حساس از طریق
// generic static handler — مستقل از .htaccess.
// ─────────────────────────────────────────────────────────────────────
$__forbidden_prefixes = [
    '/data/',
    '/DANOVA-DATA/',
    '/vendor/',
    '/bin/',
    '/tests/',
    '/.git/',
    '/.env',
    '/secrets.php',
    '/config.php',
    '/auth.php',
    '/api.php',
    '/models.php',
    '/services.php',
    '/helpers.php',
    '/security.php',
];

$__path_lower = strtolower($path);
foreach ($__forbidden_prefixes as $__fp) {
    if (strpos($__path_lower, strtolower($__fp)) === 0) {
        logger_warn('[SECURITY] Blocked private path: ' . $path);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '403 Forbidden';
        exit;
    }
}

// مسدودسازی پسوندهای حساس در هر جای پروژه
$__forbidden_ext = [
    'db', 'sqlite', 'sqlite3', 'log', 'env', 'bak',
    'old', 'save', 'swp', 'orig', 'sql', 'lock', 'ini',
];
$__path_ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (in_array($__path_ext, $__forbidden_ext, true)) {
    logger_warn('[SECURITY] Blocked sensitive extension: ' . $path);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    exit;
}

// =====================================================================
// 6. Generic Static Files Handler
// ---------------------------------------------------------------------
// برای بقیه فایل‌های استاتیک (تصاویر، فونت‌ها، ...)
//
// ✅ v3.5.2 SECURITY FIX:
//   Path containment check is now separator-aware. Previously used
//   strpos($realFile, $realBase) === 0 which could be bypassed by
//   a sibling directory sharing the project name prefix.
// =====================================================================

$staticMimes = [
    'css'   => 'text/css; charset=utf-8',
    'js'    => 'application/javascript; charset=utf-8',
    'mjs'   => 'application/javascript; charset=utf-8',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'svg'   => 'image/svg+xml',
    'webp'  => 'image/webp',
    'ico'   => 'image/x-icon',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
    'otf'   => 'font/otf',
    'eot'   => 'application/vnd.ms-fontobject',
    'json'  => 'application/json; charset=utf-8',
    'map'   => 'application/json; charset=utf-8',
    'mp4'   => 'video/mp4',
    'webm'  => 'video/webm',
    'ogg'   => 'video/ogg',
];

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

if ($ext !== '' && isset($staticMimes[$ext])) {
    // مسیر امن
    $safePath = str_replace(['..', "\0"], '', $path);
    $safePath = ltrim($safePath, '/');

    $filePath = __DIR__ . '/' . $safePath;
    $realFile = @realpath($filePath);
    $realBase = @realpath(__DIR__);

    // ✅ v3.5.2 SECURITY FIX: separator-aware path containment
    $isContained = false;
    if ($realFile !== false && $realBase !== false) {
        // Normalize separators for cross-platform safety (Windows \ vs /)
        $realFileNorm = str_replace('\\', '/', $realFile);
        $realBaseNorm = rtrim(str_replace('\\', '/', $realBase), '/');

        // Require strict containment: file must be at base + '/' + ...
        // This prevents sibling-prefix bypass (e.g. /site vs /site-secrets)
        $isContained = str_starts_with($realFileNorm, $realBaseNorm . '/');
    }

    if (
        $isContained &&
        is_file($realFile) &&
        is_readable($realFile)
    ) {
        $content = @file_get_contents($realFile);

        if ($content !== false && $content !== '') {
            // حذف BOM برای فایل‌های متنی
            if (in_array($ext, ['css', 'js', 'mjs', 'json', 'map', 'svg'], true)) {
                if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                    $content = substr($content, 3);
                }
            }

            // پاک کردن output buffer
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            if (!headers_sent()) {
                header('Content-Type: ' . $staticMimes[$ext]);
                header('Content-Length: ' . strlen($content));
                header('Cache-Control: public, max-age=300, must-revalidate');
                header('X-Content-Type-Options: nosniff');
            }

            echo $content;
            exit;
        }
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    exit;
}

// =====================================================================
// 7. SPA Shell → HTML کامل (inline)
// =====================================================================

// پاک کردن output buffer قبل از ارسال HTML
while (ob_get_level() > 0) {
    @ob_end_clean();
}

if (function_exists('security_headers_html')) {
    security_headers_html();
} else {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}

// ─────────────────────────────────────────────────────────────────────
// HTML SHELL (سابقاً index.html)
// ─────────────────────────────────────────────────────────────────────
$html = <<<'DANOVA_HTML'
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="DANOVA - پلتفرم آموزشی هوش مصنوعی">
    <meta name="theme-color" content="#3C3CCC">

    <title>دانُوا | پلتفرم آموزشی هوش مصنوعی</title>

    <!-- Version 5.0.0 (Lifecycle merged into app.js + style.css) -->

    <!-- Early theme apply (prevent flash) -->
    <script>
        (function() {
            try {
                var saved = localStorage.getItem('danova-theme');
                if (saved) {
                    document.documentElement.setAttribute('data-theme', saved);
                } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/style.css">
</head>

<body>

<div id="app">

    <!-- ================================================================
         VIEW: LOGIN
         ================================================================ -->
    <section id="view-login" class="view view-login" data-view="login">

        <div class="login-container">

            <aside class="login-brand">
                <div class="login-brand-content">
                    <div class="brand-logo">
                        <span class="brand-text">دانُوا</span>
                        <span class="brand-text-en">DANOVA</span>
                    </div>
                    <h1 class="login-brand-title">
                        پلتفرم آموزشی<br>
                        <span class="text-accent">هوش مصنوعی</span>
                    </h1>
                    <p class="login-brand-description">
                        تجربه یادگیری هوشمند، شخصی‌سازی‌شده و متناسب با نیاز هر دانش‌آموز.
                    </p>
                    <ul class="login-brand-features">
                        <li><span class="feature-icon">◆</span><span>دستیار هوشمند آموزشی</span></li>
                        <li><span class="feature-icon">◆</span><span>محتوای اختصاصی برای هر کلاس</span></li>
                        <li><span class="feature-icon">◆</span><span>ویدیوهای آموزشی تعاملی</span></li>
                    </ul>
                </div>
                <footer class="login-brand-footer"><span>© ۱۴۰۴ DANOVA</span></footer>
            </aside>

            <main class="login-form-wrapper">
                <button type="button" id="theme-toggle-login" class="theme-toggle theme-toggle-login" aria-label="تغییر حالت روشن/تاریک">
                    <span class="theme-icon theme-icon-light">☀</span>
                    <span class="theme-icon theme-icon-dark">☾</span>
                </button>

                <div class="login-form-container">
                    <div class="brand-logo brand-logo-mobile">
                        <span class="brand-text">دانُوا</span>
                        <span class="brand-text-en">DANOVA</span>
                    </div>

                    <header class="login-header">
                        <h2 class="login-title">ورود به دانُوا</h2>
                        <p class="login-subtitle">برای ورود به حساب کاربری خود، اطلاعات ورود را وارد کنید.</p>
                    </header>

                    <div id="login-error" class="alert alert-error" hidden>
                        <span class="alert-icon">⚠</span>
                        <span class="alert-message"></span>
                    </div>

                    <form id="login-form" class="login-form" novalidate>
                        <div class="form-group">
                            <label for="login-username" class="form-label">نام کاربری</label>
                            <input type="text" id="login-username" name="username" class="form-input"
                                   placeholder="نام کاربری خود را وارد کنید" autocomplete="username"
                                   dir="ltr" required minlength="3" maxlength="100">
                        </div>
                        <div class="form-group">
                            <label for="login-password" class="form-label">رمز عبور</label>
                            <div class="input-with-action">
                                <input type="password" id="login-password" name="password" class="form-input"
                                       placeholder="رمز عبور خود را وارد کنید" autocomplete="current-password"
                                       dir="ltr" required minlength="6" maxlength="200">
                                <button type="button" id="toggle-password" class="input-action-btn"
                                        aria-label="نمایش/مخفی کردن رمز عبور"
                                        aria-pressed="false">
                                    <span class="password-icon-show">👁</span>
                                    <span class="password-icon-hide" hidden>🙈</span>
                                </button>
                            </div>
                        </div>
                        <button type="submit" id="login-submit" class="btn btn-primary btn-block btn-lg">
                            <span class="btn-label">ورود</span>
                            <span class="btn-loader" hidden><span class="spinner spinner-sm"></span></span>
                        </button>
                    </form>

                    <footer class="login-footer">
                        <p class="login-help-text">در صورت نیاز به راهنمایی، با مدیر مدرسه خود تماس بگیرید.</p>
                        <button type="button" id="login-download-cta" class="login-download-cta">
                            <span class="login-download-cta-icon">📥</span>
                            <span class="login-download-cta-text">
                                <strong>مرکز دانلود دانُوا</strong>
                                <small>نسخه رسمی برای دستگاه شما</small>
                            </span>
                            <span class="login-download-cta-arrow">←</span>
                        </button>
                    </footer>
                </div>
            </main>

        </div>
    </section>
    <!-- END VIEW: LOGIN -->


    <!-- ================================================================
         VIEW: STUDENT PANEL
         ================================================================ -->
    <section id="view-student" class="view view-student" data-view="student" hidden>

        <div class="app-layout">

            <aside class="sidebar" id="sidebar-student">
                <div class="sidebar-header">
                    <div class="brand-logo brand-logo-sm"><span class="brand-text">دانُوا</span></div>
                    <button type="button" class="sidebar-close" data-sidebar-close aria-label="بستن منو">✕</button>
                </div>

                <nav class="sidebar-nav">
                    <ul class="nav-list">
                        <li class="nav-item"><a href="#student-chat" class="nav-link nav-link-active" data-student-nav="chat"><span class="nav-icon">💬</span><span class="nav-label">گفتگو با AI</span></a></li>
                        <li class="nav-item"><a href="#student-history" class="nav-link" data-student-nav="history"><span class="nav-icon">📋</span><span class="nav-label">گفتگوهای من</span></a></li>
                        <li class="nav-item"><a href="#student-videos" class="nav-link" data-student-nav="videos"><span class="nav-icon">🎬</span><span class="nav-label">ویدیوهای آموزشی</span></a></li>
                        <li class="nav-item"><a href="#student-tickets" class="nav-link" data-student-nav="tickets"><span class="nav-icon">🎫</span><span class="nav-label">تیکت پشتیبانی</span></a></li>
                        <li class="nav-item"><a href="#student-profile" class="nav-link" data-student-nav="profile"><span class="nav-icon">👤</span><span class="nav-label">پروفایل من</span></a></li>
                    </ul>
                </nav>

                <div class="sidebar-footer"></div>
            </aside>

            <div class="main-wrapper">
                <header class="app-header">
                    <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="باز کردن منو">☰</button>
                    <h1 class="page-title" id="student-page-title">گفتگو با AI</h1>

                    <div class="header-actions">
                        <button type="button" class="theme-toggle" data-theme-toggle aria-label="تغییر حالت روشن/تاریک">
                            <span class="theme-icon theme-icon-light">☀</span>
                            <span class="theme-icon theme-icon-dark">☾</span>
                        </button>

                        <div class="profile-menu">
                            <button type="button" class="profile-btn" data-profile-toggle aria-label="منوی کاربری">
                                <span class="profile-avatar">👤</span>
                                <span class="profile-name" id="student-header-name">—</span>
                            </button>
                            <div class="profile-dropdown" data-profile-dropdown hidden>
                                <div class="dropdown-header">
                                    <div class="dropdown-name" id="student-dropdown-name">—</div>
                                    <div class="dropdown-role">دانش‌آموز</div>
                                </div>
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item" data-student-nav="profile"><span>👤</span><span>پروفایل من</span></button>
                                <button type="button" class="dropdown-item dropdown-item-danger" id="student-logout"><span>🚪</span><span>خروج از حساب</span></button>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="app-content" id="student-content">

                    <!-- SUBVIEW: CHAT -->
                    <div class="subview" data-student-view="chat">
                        <div class="chat-layout">
                            <aside class="chat-history-panel" id="chat-history-panel">
                                <div class="chat-history-header">
                                    <h3 class="panel-title">گفتگوهای من</h3>
                                    <button type="button" id="new-conversation-btn" class="btn btn-sm btn-primary">+ جدید</button>
                                </div>
                                <div class="chat-history-list" id="chat-history-list">
                                    <div class="empty-state empty-state-sm">
                                        <div class="empty-icon">💭</div>
                                        <div class="empty-text">هنوز گفتگویی ندارید</div>
                                    </div>
                                </div>
                            </aside>

                            <div class="chat-main">
                                <div class="ai-selector-wrapper">
                                    <label class="ai-selector-label">
                                        <span aria-hidden="true">🤖</span>
                                        <span>انتخاب دستیار:</span>
                                    </label>
                                    <div class="ai-selector" id="ai-selector">
                                        <div class="ai-selector-placeholder">در حال بارگذاری...</div>
                                    </div>
                                </div>

                                <!-- ✅ v4.3.0: Textbook RAG Toggle — Real iOS-style Switch -->
                                <div class="textbook-rag-bar">
                                    <label class="textbook-rag-label" for="textbook-rag-toggle">
                                        <input type="checkbox" id="textbook-rag-toggle" class="textbook-rag-checkbox" aria-label="اتصال به کتاب درسی" checked>
                                        <span class="textbook-rag-switch" aria-hidden="true">
                                            <span class="textbook-rag-thumb"></span>
                                        </span>
                                        <span class="textbook-rag-text">اتصال به کتاب درسی</span>
                                        <span class="textbook-rag-status" aria-live="polite">غیرفعال</span>
                                    </label>
                                </div>

                                <div class="chat-messages" id="chat-messages">
                                    <div class="chat-welcome" id="chat-welcome">
                                        <div class="welcome-icon">🤖</div>
                                        <h2 class="welcome-title">سلام، <span id="welcome-student-name">—</span>! 👋</h2>
                                        <p class="welcome-text">
                                            آماده‌ای با هم یاد بگیریم؟<br>
                                            یه سوال بپرس تا شروع کنیم.
                                        </p>
                                    </div>
                                </div>

                                <div class="chat-file-preview" id="chat-file-preview" hidden>
                                    <span id="chat-file-preview-text"></span>
                                    <button type="button" id="chat-file-remove" class="chat-file-remove" aria-label="حذف فایل">×</button>
                                </div>
                                <form class="chat-input-wrapper" id="chat-form">
                                    <input type="file" id="chat-file-input" accept=".pdf,application/pdf" hidden>
                                    <textarea id="chat-input" class="chat-input" placeholder="سؤالت را بنویس..."
                                              rows="1" maxlength="4000"
                                              aria-label="متن پیام برای ارسال به دستیار"></textarea>
                                    <button type="button" id="chat-file-btn" class="chat-file-btn"
                                            aria-label="پیوست فایل PDF"
                                            title="پیوست فایل PDF (حداکثر حجم از تنظیمات سیستم)" hidden>📎</button>
                                    <button type="submit" id="chat-send-btn" class="chat-send-btn" aria-label="ارسال پیام">
                                        <span class="send-icon">➤</span>
                                    </button>
                                </form>

                                <div class="chat-input-hint">
                                    <span>Enter برای ارسال • Shift+Enter برای خط جدید</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SUBVIEW: HISTORY -->
                    <div class="subview" data-student-view="history" hidden>
                        <div class="history-view">
                            <div class="view-header">
                                <h2 class="view-title">گفتگوهای من</h2>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-ghost" data-bulk-action="toggle-mode">☑ انتخاب گروهی</button>
                                    <button type="button" class="btn btn-primary" data-new-conversation>+ گفتگوی جدید</button>
                                </div>
                            </div>
                            <div class="history-full-list" id="history-full-list">
                                <div class="empty-state">
                                    <div class="empty-icon">💭</div>
                                    <div class="empty-title">هنوز گفتگویی ندارید</div>
                                    <div class="empty-text">اولین گفتگوت رو شروع کن!</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SUBVIEW: VIDEOS -->
                    <div class="subview" data-student-view="videos" hidden>
                        <div class="videos-view">

                            <section id="student-collections-section" hidden>
                                <div class="view-header" style="margin-bottom: 16px;">
                                    <div>
                                        <h2 class="view-title">📚 مجموعه‌های آموزشی</h2>
                                        <p class="view-subtitle">درس‌ها به‌صورت مجموعه‌های منظم</p>
                                    </div>
                                </div>
                                <div class="classes-grid" id="student-collections-grid">
                                    <div class="empty-state">
                                        <div class="empty-title">در حال بارگذاری...</div>
                                    </div>
                                </div>
                                <hr style="margin: 32px 0; border: none; border-top: 1px solid var(--color-border);">
                            </section>

                            <section id="student-collection-detail" hidden>
                                <div class="view-header">
                                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                        <button type="button" class="btn btn-ghost btn-sm" id="student-collection-back-btn">
                                            → بازگشت به مجموعه‌ها
                                        </button>
                                        <div>
                                            <h2 class="view-title" id="student-collection-detail-title">—</h2>
                                            <p class="view-subtitle" id="student-collection-detail-subtitle">—</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="card" style="margin-bottom: 16px;">
                                    <div class="card-body">
                                        <div class="info-row">
                                            <span class="info-label">تعداد ویدیو:</span>
                                            <span class="info-value" id="student-collection-video-count">—</span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">مدت کل:</span>
                                            <span class="info-value" id="student-collection-total-duration">—</span>
                                        </div>
                                        <div class="info-row">
                                            <span class="info-label">پیشرفت:</span>
                                            <span class="info-value" id="student-collection-progress">—</span>
                                        </div>
                                        <div class="usage-bar" style="margin-top: 8px;">
                                            <div class="usage-bar-fill" id="student-collection-progress-bar" style="width: 0%"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="videos-grid" id="student-collection-videos-grid">
                                    <div class="empty-state">
                                        <div class="empty-title">در حال بارگذاری...</div>
                                    </div>
                                </div>
                            </section>

                            <div id="student-videos-main">
                                <div class="view-header">
                                    <h2 class="view-title">ویدیوهای آموزشی</h2>
                                    <p class="view-subtitle">ویدیوهای مخصوص کلاس شما</p>
                                </div>
                                <div class="filters-bar">
                                    <button type="button" class="filter-chip filter-chip-active" data-video-filter="all">همه</button>
                                    <button type="button" class="filter-chip" data-video-filter="unwatched">مشاهده‌نشده</button>
                                    <button type="button" class="filter-chip" data-video-filter="in_progress">در حال مشاهده</button>
                                    <button type="button" class="filter-chip" data-video-filter="completed">تکمیل‌شده</button>
                                </div>
                                <div class="videos-grid" id="videos-grid">
                                    <div class="empty-state">
                                        <div class="empty-icon">🎬</div>
                                        <div class="empty-title">هنوز ویدیویی موجود نیست</div>
                                        <div class="empty-text">فعلاً ویدیوی آموزشی جدیدی برای شما قرار نگرفته است.</div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- SUBVIEW: TICKETS -->
                    <div class="subview" data-student-view="tickets" hidden>
                        <div class="view-header">
                            <div>
                                <h2 class="view-title">🎫 تیکت‌های پشتیبانی</h2>
                                <p class="view-subtitle">ارتباط با تیم پشتیبانی DANOVA</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-ticket-action="open-create-modal">
                                + تیکت جدید
                            </button>
                        </div>

                        <div class="tickets-view">
                            <aside class="tickets-sidebar">
                                <div class="tickets-sidebar-header">
                                    <span class="tickets-sidebar-title">تیکت‌های من</span>
                                    <button type="button" class="btn btn-sm btn-ghost" data-ticket-action="refresh-list" aria-label="بازخوانی">↻</button>
                                </div>
                                <div class="tickets-filter-bar">
                                    <button type="button" class="tickets-filter-chip tickets-filter-chip-active" data-ticket-filter="all">همه</button>
                                    <button type="button" class="tickets-filter-chip" data-ticket-filter="open">باز</button>
                                    <button type="button" class="tickets-filter-chip" data-ticket-filter="in_progress">در حال بررسی</button>
                                    <button type="button" class="tickets-filter-chip" data-ticket-filter="waiting_user">در انتظار شما</button>
                                    <button type="button" class="tickets-filter-chip" data-ticket-filter="closed">بسته‌شده</button>
                                </div>
                                <div class="tickets-list" data-tickets-list>
                                    <div class="tickets-list-empty">
                                        <div class="tickets-list-empty-icon">🎫</div>
                                        <div class="tickets-list-empty-title">در حال بارگذاری...</div>
                                    </div>
                                </div>
                            </aside>

                            <main class="tickets-main" data-tickets-main>
                                <div class="empty-state" style="margin:auto;">
                                    <div class="empty-icon">🎫</div>
                                    <div class="empty-title">یک تیکت انتخاب کنید</div>
                                    <div class="empty-text">از لیست سمت راست، یک تیکت را برای مشاهده‌ی جزئیات انتخاب کنید.</div>
                                </div>
                            </main>
                        </div>
                    </div>

                    <!-- SUBVIEW: PROFILE -->
                    <div class="subview" data-student-view="profile" hidden>
                        <div class="profile-view">
                            <div class="view-header">
                                <h2 class="view-title">پروفایل من</h2>
                            </div>

                            <div class="profile-grid">
                                <div class="card">
                                    <div class="card-header"><h3 class="card-title">اطلاعات تحصیلی</h3></div>
                                    <div class="card-body">
                                        <div class="info-row"><span class="info-label">نام:</span><span class="info-value" id="profile-fullname">—</span></div>
                                        <div class="info-row"><span class="info-label">نام کاربری:</span><span class="info-value" id="profile-username">—</span></div>
                                        <div class="info-row"><span class="info-label">مدرسه:</span><span class="info-value" id="profile-school">—</span></div>
                                        <div class="info-row"><span class="info-label">کلاس:</span><span class="info-value" id="profile-class">—</span></div>
                                        <div class="info-row"><span class="info-label">نقش:</span><span class="info-value"><span class="badge badge-primary">دانش‌آموز</span></span></div>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header"><h3 class="card-title">ویرایش نام نمایشی</h3></div>
                                    <div class="card-body">
                                        <form id="profile-name-form">
                                            <div class="form-group">
                                                <label for="profile-name-input" class="form-label">نام نمایشی</label>
                                                <input type="text" id="profile-name-input" class="form-input"
                                                       placeholder="نام خود را وارد کنید" minlength="2" maxlength="150" required>
                                            </div>
                                            <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
                                        </form>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header"><h3 class="card-title">تغییر رمز عبور</h3></div>
                                    <div class="card-body">
                                        <form id="profile-password-form">
                                            <div class="form-group">
                                                <label for="current-password" class="form-label">رمز عبور فعلی</label>
                                                <input type="password" id="current-password" class="form-input" dir="ltr" required minlength="6">
                                            </div>
                                            <div class="form-group">
                                                <label for="new-password" class="form-label">رمز عبور جدید</label>
                                                <input type="password" id="new-password" class="form-input" dir="ltr" required minlength="6">
                                            </div>
                                            <div class="form-group">
                                                <label for="confirm-password" class="form-label">تکرار رمز عبور جدید</label>
                                                <input type="password" id="confirm-password" class="form-input" dir="ltr" required minlength="6">
                                            </div>
                                            <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </main>
            </div>
        </div>
    </section>
    <!-- END VIEW: STUDENT -->


    <!-- ================================================================
         VIEW: MANAGER PANEL
         ================================================================ -->
    <section id="view-manager" class="view view-manager" data-view="manager" hidden>

        <div class="app-layout">

            <aside class="sidebar" id="sidebar-manager">
                <div class="sidebar-header">
                    <div class="brand-logo brand-logo-sm"><span class="brand-text">دانُوا</span></div>
                    <button type="button" class="sidebar-close" data-sidebar-close aria-label="بستن منو">✕</button>
                </div>

                <nav class="sidebar-nav">
                    <ul class="nav-list">
                        <li class="nav-item"><a href="#manager-dashboard" class="nav-link nav-link-active" data-manager-nav="dashboard"><span class="nav-icon">📊</span><span class="nav-label">داشبورد</span></a></li>
                        <li class="nav-item"><a href="#manager-students" class="nav-link" data-manager-nav="students"><span class="nav-icon">👥</span><span class="nav-label">دانش‌آموزان</span></a></li>
                        <li class="nav-item"><a href="#manager-classes" class="nav-link" data-manager-nav="classes"><span class="nav-icon">🏫</span><span class="nav-label">کلاس‌ها</span></a></li>
                        <li class="nav-item"><a href="#manager-usage" class="nav-link" data-manager-nav="usage"><span class="nav-icon">🤖</span><span class="nav-label">مصرف هوش مصنوعی</span></a></li>
                        <li class="nav-item"><a href="#manager-profile" class="nav-link" data-manager-nav="profile"><span class="nav-icon">👤</span><span class="nav-label">پروفایل من</span></a></li>
                    </ul>
                </nav>
            </aside>

            <div class="main-wrapper">
                <header class="app-header">
                    <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="باز کردن منو">☰</button>
                    <h1 class="page-title" id="manager-page-title">داشبورد مدرسه</h1>

                    <div class="header-actions">
                        <button type="button" class="theme-toggle" data-theme-toggle aria-label="تغییر حالت روشن/تاریک">
                            <span class="theme-icon theme-icon-light">☀</span>
                            <span class="theme-icon theme-icon-dark">☾</span>
                        </button>

                        <div class="profile-menu">
                            <button type="button" class="profile-btn" data-profile-toggle aria-label="منوی کاربری">
                                <span class="profile-avatar">👤</span>
                                <span class="profile-name" id="manager-header-name">—</span>
                            </button>
                            <div class="profile-dropdown" data-profile-dropdown hidden>
                                <div class="dropdown-header">
                                    <div class="dropdown-name" id="manager-dropdown-name">—</div>
                                    <div class="dropdown-role">مدیر مدرسه</div>
                                </div>
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item" data-manager-nav="profile"><span>👤</span><span>پروفایل من</span></button>
                                <button type="button" class="dropdown-item dropdown-item-danger" id="manager-logout"><span>🚪</span><span>خروج از حساب</span></button>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="app-content" id="manager-content">

                    <div class="subview" data-manager-view="dashboard">
                        <div class="view-header">
                            <h2 class="view-title">داشبورد مدرسه</h2>
                            <p class="view-subtitle" id="manager-school-name">—</p>
                        </div>
                        <div class="kpi-grid">
                            <div class="kpi-card"><div class="kpi-icon">👥</div><div class="kpi-label">دانش‌آموزان</div><div class="kpi-value" id="manager-kpi-students">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">🏫</div><div class="kpi-label">کلاس‌ها</div><div class="kpi-value" id="manager-kpi-classes">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">💬</div><div class="kpi-label">پیام‌های ۳۰ روز اخیر</div><div class="kpi-value" id="manager-kpi-messages">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">📈</div><div class="kpi-label">مصرف توکن</div><div class="kpi-value" id="manager-kpi-tokens">—</div></div>
                        </div>
                    </div>

                    <div class="subview" data-manager-view="students" hidden>
                        <div class="view-header">
                            <h2 class="view-title">دانش‌آموزان</h2>
                            <p class="view-subtitle">لیست دانش‌آموزان مدرسه</p>
                        </div>
                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="manager-students-search" placeholder="جستجوی دانش‌آموز...">
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>نام</th><th>کد دانش‌آموزی</th><th>کلاس</th><th>وضعیت</th></tr></thead>
                                <tbody id="manager-students-tbody">
                                    <tr><td colspan="4" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="subview" data-manager-view="classes" hidden>
                        <div class="view-header">
                            <h2 class="view-title">کلاس‌ها</h2>
                            <p class="view-subtitle">کلاس‌های مدرسه</p>
                        </div>
                        <div class="classes-grid" id="manager-classes-grid">
                            <div class="empty-state"><div class="empty-icon">🏫</div><div class="empty-title">در حال بارگذاری...</div></div>
                        </div>
                    </div>

                    <div class="subview" data-manager-view="usage" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مصرف هوش مصنوعی</h2>
                            <p class="view-subtitle">آمار مصرف AI مدرسه</p>
                        </div>
                        <div class="filters-bar">
                            <button type="button" class="filter-chip filter-chip-active" data-usage-days="7">۷ روز</button>
                            <button type="button" class="filter-chip" data-usage-days="30">۳۰ روز</button>
                            <button type="button" class="filter-chip" data-usage-days="90">۹۰ روز</button>
                        </div>
                        <div class="kpi-grid">
                            <div class="kpi-card"><div class="kpi-label">پیام‌ها</div><div class="kpi-value" id="manager-usage-messages">—</div></div>
                            <div class="kpi-card"><div class="kpi-label">توکن ورودی</div><div class="kpi-value" id="manager-usage-input">—</div></div>
                            <div class="kpi-card"><div class="kpi-label">توکن خروجی</div><div class="kpi-value" id="manager-usage-output">—</div></div>
                            <div class="kpi-card"><div class="kpi-label">مجموع توکن</div><div class="kpi-value" id="manager-usage-total">—</div></div>
                        </div>
                    </div>

                    <div class="subview" data-manager-view="profile" hidden>
                        <div class="view-header"><h2 class="view-title">پروفایل من</h2></div>
                        <div class="profile-grid">
                            <div class="card">
                                <div class="card-header"><h3 class="card-title">اطلاعات حساب</h3></div>
                                <div class="card-body">
                                    <div class="info-row"><span class="info-label">نام:</span><span class="info-value" id="manager-profile-name">—</span></div>
                                    <div class="info-row"><span class="info-label">نام کاربری:</span><span class="info-value" id="manager-profile-username">—</span></div>
                                    <div class="info-row"><span class="info-label">مدرسه:</span><span class="info-value" id="manager-profile-school">—</span></div>
                                    <div class="info-row"><span class="info-label">نقش:</span><span class="info-value"><span class="badge badge-accent">مدیر مدرسه</span></span></div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header"><h3 class="card-title">تغییر رمز عبور</h3></div>
                                <div class="card-body">
                                    <form id="manager-password-form">
                                        <div class="form-group"><label for="manager-current-password" class="form-label">رمز عبور فعلی</label><input type="password" id="manager-current-password" class="form-input" dir="ltr" required minlength="6"></div>
                                        <div class="form-group"><label for="manager-new-password" class="form-label">رمز عبور جدید</label><input type="password" id="manager-new-password" class="form-input" dir="ltr" required minlength="6"></div>
                                        <div class="form-group"><label for="manager-confirm-password" class="form-label">تکرار رمز عبور جدید</label><input type="password" id="manager-confirm-password" class="form-input" dir="ltr" required minlength="6"></div>
                                        <button type="submit" class="btn btn-primary">تغییر رمز عبور</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </main>
            </div>
        </div>
    </section>
    <!-- END VIEW: MANAGER -->


    <!-- ================================================================
         VIEW: ADMIN PANEL
         ================================================================ -->
    <section id="view-admin" class="view view-admin" data-view="admin" hidden>

        <div class="app-layout">

            <aside class="sidebar" id="sidebar-admin">
                <div class="sidebar-header">
                    <div class="brand-logo brand-logo-sm">
                        <span class="brand-text">دانُوا</span>
                        <span class="brand-tag">پنل مدیریت</span>
                    </div>
                    <button type="button" class="sidebar-close" data-sidebar-close aria-label="بستن منو">✕</button>
                </div>

                <nav class="sidebar-nav">
                    <ul class="nav-list">
                        <li class="nav-item"><a href="#admin-dashboard" class="nav-link nav-link-active" data-admin-nav="dashboard"><span class="nav-icon">📊</span><span class="nav-label">داشبورد</span></a></li>
                        <li class="nav-item"><a href="#admin-schools" class="nav-link" data-admin-nav="schools"><span class="nav-icon">🏫</span><span class="nav-label">مدارس</span></a></li>
                        <li class="nav-item"><a href="#admin-managers" class="nav-link" data-admin-nav="managers"><span class="nav-icon">👔</span><span class="nav-label">مدیران مدارس</span></a></li>
                        <li class="nav-item"><a href="#admin-classes" class="nav-link" data-admin-nav="classes"><span class="nav-icon">🎓</span><span class="nav-label">کلاس‌ها</span></a></li>
                        <li class="nav-item"><a href="#admin-students" class="nav-link" data-admin-nav="students"><span class="nav-icon">👥</span><span class="nav-label">دانش‌آموزان</span></a></li>
                        <li class="nav-item"><a href="#admin-ai-profiles" class="nav-link" data-admin-nav="ai-profiles"><span class="nav-icon">🤖</span><span class="nav-label">پروفایل‌های AI</span></a></li>
                        <li class="nav-item"><a href="#admin-rag" class="nav-link" data-admin-nav="rag"><span class="nav-icon">📚</span><span class="nav-label">پایگاه دانش RAG</span></a></li>
                        <li class="nav-item"><a href="#admin-videos" class="nav-link" data-admin-nav="videos"><span class="nav-icon">🎬</span><span class="nav-label">ویدیوها</span></a></li>
                        <li class="nav-item"><a href="#admin-video-collections" class="nav-link" data-admin-nav="video-collections"><span class="nav-icon">🎞</span><span class="nav-label">مجموعه‌های ویدیو</span></a></li>
                        <li class="nav-item"><a href="#admin-downloads" class="nav-link" data-admin-nav="downloads"><span class="nav-icon">📥</span><span class="nav-label">دانلود</span></a></li>
                        <li class="nav-item"><a href="#admin-subscriptions" class="nav-link" data-admin-nav="subscriptions"><span class="nav-icon">📝</span><span class="nav-label">اشتراک‌ها</span></a></li>
                        <li class="nav-item"><a href="#admin-settings" class="nav-link" data-admin-nav="settings"><span class="nav-icon">⚙️</span><span class="nav-label">تنظیمات سیستم</span></a></li>
                        <li class="nav-item"><a href="#admin-time" class="nav-link" data-admin-nav="time"><span class="nav-icon">🕐</span><span class="nav-label">ساعت و زمان</span></a></li>
                        <li class="nav-item"><a href="#admin-tickets" class="nav-link" data-admin-nav="tickets"><span class="nav-icon">🎫</span><span class="nav-label">مدیریت تیکت‌ها</span></a></li>
                        <li class="nav-item"><a href="#admin-storage" class="nav-link" data-admin-nav="storage"><span class="nav-icon">💾</span><span class="nav-label">مدیریت حافظه</span></a></li>
                        <li class="nav-item"><a href="#admin-audit" class="nav-link" data-admin-nav="audit"><span class="nav-icon">📋</span><span class="nav-label">گزارش فعالیت‌ها</span></a></li>
                        <li class="nav-item"><a href="#admin-reports" class="nav-link" data-admin-nav="reports"><span class="nav-icon">📊</span><span class="nav-label">خروجی و گزارش‌ها</span></a></li>
                        <li class="nav-item"><a href="#admin-test-accounts" class="nav-link" data-admin-nav="test-accounts"><span class="nav-icon">🧪</span><span class="nav-label">پنل‌های تست</span></a></li>
                        <li class="nav-item"><a href="#admin-system" class="nav-link" data-admin-nav="system"><span class="nav-icon">🛡</span><span class="nav-label">سیستم</span></a></li>
                    </ul>
                </nav>
            </aside>

            <div class="main-wrapper">
                <header class="app-header">
                    <button type="button" class="sidebar-toggle" data-sidebar-toggle aria-label="باز کردن منو">☰</button>
                    <h1 class="page-title" id="admin-page-title">داشبورد مدیریت</h1>

                    <div class="header-actions">
                        <button type="button" class="theme-toggle" data-theme-toggle aria-label="تغییر حالت روشن/تاریک">
                            <span class="theme-icon theme-icon-light">☀</span>
                            <span class="theme-icon theme-icon-dark">☾</span>
                        </button>

                        <div class="profile-menu">
                            <button type="button" class="profile-btn" data-profile-toggle aria-label="منوی کاربری">
                                <span class="profile-avatar">👤</span>
                                <span class="profile-name" id="admin-header-name">—</span>
                            </button>
                            <div class="profile-dropdown" data-profile-dropdown hidden>
                                <div class="dropdown-header">
                                    <div class="dropdown-name" id="admin-dropdown-name">—</div>
                                    <div class="dropdown-role">مدیر DANOVA</div>
                                </div>
                                <div class="dropdown-divider"></div>
                                <button type="button" class="dropdown-item" data-admin-action="change-admin-username"><span>👤</span><span>تغییر نام کاربری</span></button>
                                <button type="button" class="dropdown-item" data-admin-action="change-admin-password"><span>🔒</span><span>تغییر رمز عبور</span></button>
                                <button type="button" class="dropdown-item dropdown-item-danger" id="admin-logout"><span>🚪</span><span>خروج از حساب</span></button>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="app-content" id="admin-content">

                    <div id="must-change-password-banner" class="alert alert-warning" hidden style="margin-bottom: 16px;">
                        <span class="alert-icon">⚠</span>
                        <span><strong>توجه:</strong> شما هنوز از رمز عبور پیش‌فرض استفاده می‌کنید. برای امنیت، لطفاً همین حالا رمز عبور خود را تغییر دهید.</span>
                        <button type="button" class="btn btn-sm btn-primary" data-admin-action="change-admin-password" style="margin-right: auto;">
                            تغییر رمز عبور
                        </button>
                    </div>

                    <!-- DASHBOARD -->
                    <div class="subview" data-admin-view="dashboard">
                        <div class="view-header">
                            <h2 class="view-title">داشبورد مدیریت</h2>
                            <p class="view-subtitle">نمای کلی پلتفرم DANOVA</p>
                        </div>
                        <div class="kpi-grid">
                            <div class="kpi-card"><div class="kpi-icon">🏫</div><div class="kpi-label">مدارس</div><div class="kpi-value" id="admin-kpi-schools">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">🎓</div><div class="kpi-label">کلاس‌ها</div><div class="kpi-value" id="admin-kpi-classes">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">👥</div><div class="kpi-label">دانش‌آموزان</div><div class="kpi-value" id="admin-kpi-students">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">🤖</div><div class="kpi-label">پروفایل‌های AI</div><div class="kpi-value" id="admin-kpi-ai">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">🎬</div><div class="kpi-label">ویدیوها</div><div class="kpi-value" id="admin-kpi-videos">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">💬</div><div class="kpi-label">پیام‌های ۳۰ روز</div><div class="kpi-value" id="admin-kpi-messages">—</div></div>
                        </div>
                    </div>

                    <!-- SCHOOLS -->
                    <div class="subview" data-admin-view="schools" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مدیریت مدارس</h2>
                            <button type="button" class="btn btn-primary" data-admin-action="create-school">+ افزودن مدرسه</button>
                        </div>
                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-schools-search" placeholder="جستجوی مدرسه...">
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>نام مدرسه</th><th>کد</th><th>سطح</th><th>جنسیت</th><th>دانش‌آموزان</th><th>کلاس‌ها</th><th>وضعیت</th><th>ایجاد</th><th>عملیات</th></tr></thead>
                                <tbody id="admin-schools-tbody">
                                    <tr><td colspan="9" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-schools-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- MANAGERS -->
                    <div class="subview" data-admin-view="managers" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مدیریت مدیران مدارس</h2>
                            <button type="button" class="btn btn-primary" data-admin-action="create-manager">+ افزودن مدیر</button>
                        </div>
                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-managers-search" placeholder="جستجوی مدیر (نام یا نام کاربری)...">
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>نام</th>
                                        <th>نام کاربری</th>
                                        <th>مدرسه</th>
                                        <th>وضعیت</th>
                                        <th>آخرین ورود</th>
                                        <th>ایجاد</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="admin-managers-tbody">
                                    <tr><td colspan="7" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-managers-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- CLASSES -->
                    <div class="subview" data-admin-view="classes" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مدیریت کلاس‌ها</h2>
                            <button type="button" class="btn btn-primary" data-admin-action="create-class">+ افزودن کلاس</button>
                        </div>
                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-classes-search" placeholder="جستجوی کلاس...">
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>نام کلاس</th><th>مدرسه</th><th>پایه</th><th>دانش‌آموزان</th><th>AI</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                                <tbody id="admin-classes-tbody">
                                    <tr><td colspan="7" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-classes-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- STUDENTS -->
                    <div class="subview" data-admin-view="students" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مدیریت دانش‌آموزان</h2>
                            <button type="button" class="btn btn-primary" data-admin-action="create-student">+ افزودن دانش‌آموز</button>
                        </div>
                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-students-search" placeholder="جستجوی دانش‌آموز...">
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>نام</th><th>نام کاربری</th><th>کد دانش‌آموزی</th><th>مدرسه</th><th>کلاس</th><th>وضعیت</th><th>ایجاد</th><th>عملیات</th></tr></thead>
                                <tbody id="admin-students-tbody">
                                    <tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-students-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- AI PROFILES -->
                    <div class="subview" data-admin-view="ai-profiles" hidden>
                        <div class="view-header">
                            <h2 class="view-title">پروفایل‌های هوش مصنوعی</h2>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="btn btn-ghost" data-admin-action="sync-gapgpt-models" title="دریافت آخرین لیست مدل‌ها از GapGPT">
                                    🔄 بروزرسانی مدل‌ها
                                </button>
                                <button type="button" class="btn btn-primary" data-admin-action="create-ai">+ ایجاد AI Profile</button>
                            </div>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>نام</th><th>Provider</th><th>Model</th><th>API Key</th><th>کلاس‌ها</th><th>RAG</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                                <tbody id="admin-ai-tbody">
                                    <tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-ai-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- RAG -->
                    <div class="subview" data-admin-view="rag" hidden>
                        <div class="view-header">
                            <h2 class="view-title">پایگاه دانش RAG</h2>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="btn btn-primary" data-admin-action="create-rag">+ مجموعه جدید</button>
                                <button type="button" class="btn btn-accent" data-admin-action="upload-rag">⬆ آپلود سند</button>
                            </div>
                        </div>
                        <div class="rag-grid" id="admin-rag-grid">
                            <div class="empty-state"><div class="empty-icon">📚</div><div class="empty-title">در حال بارگذاری...</div></div>
                        </div>
                        <div id="admin-rag-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- VIDEOS -->
                    <div class="subview" data-admin-view="videos" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مدیریت ویدیوها</h2>
                            <button type="button" class="btn btn-primary" data-admin-action="upload-video">⬆ آپلود ویدیو</button>
                        </div>
                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-videos-search" placeholder="جستجوی ویدیو...">
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>عنوان</th><th>پایه</th><th>درس</th><th>هدف</th><th>مدت</th><th>حجم</th><th>مشاهده</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                                <tbody id="admin-videos-tbody">
                                    <tr><td colspan="9" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-videos-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- VIDEO COLLECTIONS -->
                    <div class="subview" data-admin-view="video-collections" hidden>
                        <div class="view-header">
                            <h2 class="view-title">مجموعه‌های ویدیو</h2>
                            <p class="view-subtitle">سازمان‌دهی ویدیوها در مجموعه‌های آموزشی</p>
                            <button type="button" class="btn btn-primary" data-admin-action="create-video-collection">+ مجموعه جدید</button>
                        </div>

                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-video-collections-search" placeholder="جستجوی مجموعه...">
                            <select class="form-input" id="admin-video-collections-level-filter" style="max-width:160px;">
                                <option value="">همه سطوح</option>
                                <option value="elementary">ابتدایی</option>
                                <option value="middle">متوسطه اول</option>
                                <option value="high">متوسطه دوم</option>
                            </select>
                            <select class="form-input" id="admin-video-collections-status-filter" style="max-width:140px;">
                                <option value="">همه وضعیت‌ها</option>
                                <option value="1">فعال</option>
                                <option value="0">غیرفعال</option>
                            </select>
                        </div>

                        <div class="rag-grid" id="admin-video-collections-grid">
                            <div class="empty-state">
                                <div class="empty-icon">🎞</div>
                                <div class="empty-title">در حال بارگذاری...</div>
                            </div>
                        </div>
                        <div id="admin-video-collections-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- SUBSCRIPTIONS -->
                    <div class="subview" data-admin-view="subscriptions" hidden>
                        <div class="view-header">
                            <h2 class="view-title">اشتراک‌ها</h2>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>مدرسه</th><th>پلن</th><th>شروع</th><th>پایان</th><th>وضعیت</th><th>عملیات</th></tr></thead>
                                <tbody id="admin-subs-tbody">
                                    <tr><td colspan="6" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-subs-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- SETTINGS -->
                    <div class="subview" data-admin-view="settings" hidden>
                        <div class="view-header">
                            <h2 class="view-title">تنظیمات سیستم</h2>
                        </div>
                        <div class="settings-list" id="admin-settings-list">
                            <div class="empty-state"><div class="empty-icon">⚙️</div><div class="empty-title">در حال بارگذاری...</div></div>
                        </div>
                    </div>

                    <!-- TIME & CLOCK -->
                    <div class="subview" data-admin-view="time" hidden>

                        <div class="view-header">
                            <div>
                                <h2 class="view-title">🕐 ساعت و زمان</h2>
                                <p class="view-subtitle">مدیریت زمان سرور، منطقه زمانی و ریست محدودیت روزانه</p>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button type="button" class="btn btn-ghost" id="time-sync-btn">
                                    🔄 بررسی همگام‌سازی
                                </button>
                                <button type="button" class="btn btn-primary" id="time-setup-btn">
                                    ⚙️ تنظیمات زمان
                                </button>
                            </div>
                        </div>

                        <div class="time-clock-card">
                            <div class="time-clock-label">زمان زنده سرور</div>
                            <div class="time-clock-display" id="time-live-clock">--:--:--</div>
                            <div class="time-clock-date" id="time-live-date">—</div>
                            <div class="time-clock-tz" id="time-live-tz">—</div>
                        </div>

                        <div class="kpi-grid" id="time-kpi-grid">
                            <div class="kpi-card">
                                <div class="kpi-icon">📅</div>
                                <div class="kpi-label">تاریخ شمسی</div>
                                <div class="kpi-value" id="time-kpi-persian">—</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-icon">📆</div>
                                <div class="kpi-label">تاریخ میلادی</div>
                                <div class="kpi-value" id="time-kpi-gregorian">—</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-icon">🌍</div>
                                <div class="kpi-label">منطقه زمانی</div>
                                <div class="kpi-value" id="time-kpi-timezone">—</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-icon">⏱️</div>
                                <div class="kpi-label">آفست UTC</div>
                                <div class="kpi-value" id="time-kpi-offset">—</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-icon">🩺</div>
                                <div class="kpi-label">سلامت ساعت سرور</div>
                                <div class="kpi-value" id="time-kpi-health">—</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-icon">📆</div>
                                <div class="kpi-label">روز هفته</div>
                                <div class="kpi-value" id="time-kpi-weekday">—</div>
                            </div>
                        </div>

                        <div class="time-countdown-card">
                            <div class="time-countdown-header">
                                <span>⏳ تا ریست بعدی محدودیت روزانه دانش‌آموزان</span>
                            </div>
                            <div class="time-countdown-display" id="time-countdown-display">--:--:--</div>
                            <div class="time-countdown-meta">
                                <span>زمان ریست بعدی:</span>
                                <strong id="time-countdown-reset-at">—</strong>
                            </div>
                            <div class="time-countdown-meta">
                                <span>ساعت ریست:</span>
                                <strong id="time-countdown-reset-hms">—</strong>
                            </div>
                            <div class="time-countdown-progress">
                                <div class="time-countdown-bar" id="time-countdown-bar" style="width:0%"></div>
                            </div>
                        </div>

                        <div class="card" style="margin-top:16px;">
                            <div class="card-header">
                                <h3 class="card-title">📋 تنظیمات فعلی</h3>
                            </div>
                            <div class="card-body">
                                <div class="info-row">
                                    <span class="info-label">منطقه زمانی:</span>
                                    <span class="info-value" id="time-setting-timezone">—</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">ساعت ریست:</span>
                                    <span class="info-value" id="time-setting-reset">—</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">سرور NTP:</span>
                                    <span class="info-value" id="time-setting-ntp">—</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">همگام‌سازی NTP:</span>
                                    <span class="info-value" id="time-setting-sync">—</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">آفست دستی:</span>
                                    <span class="info-value" id="time-setting-offset">—</span>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- ADMIN TICKETS -->
                    <div class="subview" data-admin-view="tickets" hidden>
                        <div class="view-header">
                            <div>
                                <h2 class="view-title">🎫 مدیریت تیکت‌ها</h2>
                                <p class="view-subtitle">پاسخ به تیکت‌های پشتیبانی کاربران</p>
                            </div>
                        </div>

                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-tickets-search" placeholder="جستجوی تیکت...">
                            <button type="button" class="tickets-filter-chip tickets-filter-chip-active" data-admin-ticket-filter="all">همه</button>
                            <button type="button" class="tickets-filter-chip" data-admin-ticket-filter="open">باز</button>
                            <button type="button" class="tickets-filter-chip" data-admin-ticket-filter="in_progress">در حال بررسی</button>
                            <button type="button" class="tickets-filter-chip" data-admin-ticket-filter="waiting_user">در انتظار کاربر</button>
                            <button type="button" class="tickets-filter-chip" data-admin-ticket-filter="closed">بسته‌شده</button>
                        </div>

                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>موضوع</th>
                                        <th>کاربر</th>
                                        <th>اولویت</th>
                                        <th>وضعیت</th>
                                        <th>دسته</th>
                                        <th>آخرین فعالیت</th>
                                        <th>ایجاد</th>
                                    </tr>
                                </thead>
                                <tbody data-admin-tickets-tbody>
                                    <tr><td colspan="7" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ADMIN STORAGE -->
                    <div class="subview" data-admin-view="storage" hidden>
                        <div class="view-header">
                            <h2 class="view-title">💾 مدیریت حافظه</h2>
                            <p class="view-subtitle">پایش مصرف فضای ذخیره‌سازی و پاکسازی داده‌های منقضی</p>
                        </div>
                        <div data-storage-dashboard>
                            <div class="storage-loading">
                                <span class="spinner spinner-lg"></span>
                                <div class="text-muted">در حال بارگذاری اطلاعات حافظه...</div>
                            </div>
                        </div>
                    </div>

                    <!-- AUDIT -->
                    <div class="subview" data-admin-view="audit" hidden>
                        <div class="view-header">
                            <h2 class="view-title">گزارش فعالیت‌ها</h2>
                            <button type="button" class="btn btn-accent" data-admin-action="export-audit">⬇ خروجی CSV</button>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead><tr><th>زمان</th><th>کاربر</th><th>عملیات</th><th>نوع</th><th>IP</th><th>جزئیات</th></tr></thead>
                                <tbody id="admin-audit-tbody">
                                    <tr><td colspan="6" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-audit-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- REPORTS / OUTPUT -->
                    <div class="subview" data-admin-view="reports" hidden>

                        <div class="view-header">
                            <div>
                                <h2 class="view-title">خروجی و گزارش‌ها</h2>
                                <p class="view-subtitle">مشاهده، جستجو، فیلتر و دانلود CSV داده‌های مدیریتی</p>
                            </div>
                        </div>

                        <div class="filters-bar" id="reports-dataset-tabs">
                            <button type="button" class="filter-chip filter-chip-active" data-report-dataset="schools">🏫 مدارس</button>
                            <button type="button" class="filter-chip" data-report-dataset="students">👥 دانش‌آموزان</button>
                            <button type="button" class="filter-chip" data-report-dataset="classes">🎓 کلاس‌ها</button>
                            <button type="button" class="filter-chip" data-report-dataset="users">👤 کاربران و مدیران</button>
                            <button type="button" class="filter-chip" data-report-dataset="ai_usage">🤖 مصرف AI</button>
                            <button type="button" class="filter-chip" data-report-dataset="conversations">💬 مکالمات</button>
                            <button type="button" class="filter-chip" data-report-dataset="statistics">📈 آمار</button>
                            <button type="button" class="filter-chip" data-report-dataset="audit_logs">📋 لاگ‌ها</button>
                        </div>

                        <div class="filters-bar" id="reports-filters-bar"></div>

                        <div class="view-header" style="margin-top: 16px; margin-bottom: 12px;">
                            <span class="text-muted" id="reports-count">—</span>
                            <button type="button" class="btn btn-primary" id="reports-download-btn">
                                ⬇ دانلود CSV
                            </button>
                        </div>

                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead id="reports-preview-thead">
                                    <tr><th>دیتاستی انتخاب کنید</th></tr>
                                </thead>
                                <tbody id="reports-preview-tbody">
                                    <tr><td class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div id="reports-pagination" class="pagination-bar"></div>

                    </div>

                    <!-- DOWNLOADS -->
                    <div class="subview" data-admin-view="downloads" hidden>
                        <div class="view-header">
                            <div>
                                <h2 class="view-title">مدیریت دانلود</h2>
                                <p class="view-subtitle">انتشارهای Windows / Android / iOS</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-admin-action="create-download">+ افزودن انتشار</button>
                        </div>

                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-downloads-search" placeholder="جستجوی عنوان یا نسخه...">
                            <select class="form-input" id="admin-downloads-platform-filter" style="max-width:160px;">
                                <option value="">همه پلتفرم‌ها</option>
                                <option value="windows">Windows</option>
                                <option value="android">Android</option>
                                <option value="ios">iOS</option>
                            </select>
                            <select class="form-input" id="admin-downloads-status-filter" style="max-width:160px;">
                                <option value="">همه وضعیت‌ها</option>
                                <option value="1">منتشرشده</option>
                                <option value="0">پیش‌نویس</option>
                            </select>
                        </div>

                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>عنوان</th>
                                        <th>پلتفرم</th>
                                        <th>نسخه</th>
                                        <th>نوع</th>
                                        <th>حجم</th>
                                        <th>آخرین نسخه</th>
                                        <th>وضعیت</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="admin-downloads-tbody">
                                    <tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-downloads-pagination" class="pagination-bar"></div>
                    </div>


                    <!-- TEST ACCOUNTS -->
                    <div class="subview" data-admin-view="test-accounts" hidden>
                        <div class="view-header">
                            <div>
                                <h2 class="view-title">🧪 پنل‌های تست</h2>
                                <p class="view-subtitle">ایجاد و مدیریت پنل‌های تست موقت برای مدارس</p>
                            </div>
                            <button type="button" class="btn btn-primary" data-admin-action="create-test-account">
                                + ساخت پنل تست
                            </button>
                        </div>

                        <div class="kpi-grid" id="test-accounts-summary">
                            <div class="kpi-card"><div class="kpi-icon">📊</div><div class="kpi-label">کل پنل‌های تست</div><div class="kpi-value" id="test-kpi-total">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">✅</div><div class="kpi-label">فعال</div><div class="kpi-value" id="test-kpi-active">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">⏰</div><div class="kpi-label">منقضی / تمام‌شده</div><div class="kpi-value" id="test-kpi-expired">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">⚠️</div><div class="kpi-label">نزدیک به انقضا</div><div class="kpi-value" id="test-kpi-approaching">—</div></div>
                            <div class="kpi-card"><div class="kpi-icon">💬</div><div class="kpi-label">مجموع پیام‌های مصرف‌شده</div><div class="kpi-value" id="test-kpi-messages">—</div></div>
                        </div>

                        <div class="filters-bar">
                            <input type="text" class="form-input filter-search" id="admin-test-accounts-search" placeholder="جستجوی نام یا نام کاربری...">
                            <select class="form-input" id="admin-test-accounts-status-filter" style="max-width:180px;">
                                <option value="">همه وضعیت‌ها</option>
                                <option value="active">فعال</option>
                                <option value="expired">منقضی</option>
                                <option value="exhausted">تمام‌شده</option>
                                <option value="revoked">لغو‌شده</option>
                            </select>
                        </div>

                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>نام پنل</th>
                                        <th>نام کاربری</th>
                                        <th>مدرسه</th>
                                        <th>AI Profile</th>
                                        <th>انقضا</th>
                                        <th>پیام‌ها</th>
                                        <th>وضعیت</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody id="admin-test-accounts-tbody">
                                    <tr><td colspan="8" class="table-empty">در حال بارگذاری...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="admin-test-accounts-pagination" class="pagination-bar"></div>
                    </div>

                    <!-- SYSTEM -->
                    <div class="subview" data-admin-view="system" hidden>
                        <div class="view-header">
                            <h2 class="view-title">سیستم</h2>
                            <p class="view-subtitle">وضعیت و مدیریت پیشرفته</p>
                        </div>

                        <div class="kpi-grid" id="admin-system-grid">
                            <div class="empty-state"><div class="empty-title">در حال بارگذاری...</div></div>
                        </div>

                        <div class="danger-zone">
                            <h3>⚠️ منطقه خطر — ریست دیتابیس</h3>
                            <p>با این عملیات، تمام داده‌های پلتفرم (به‌جز حساب ادمین فعلی) به‌طور کامل حذف خواهند شد. این عمل <strong>برگشت‌ناپذیر</strong> است.</p>
                            <ul>
                                <li>همه مدارس، کلاس‌ها، دانش‌آموزان</li>
                                <li>همه کاربران (به‌جز ادمین فعلی)</li>
                                <li>همه پروفایل‌های AI، RAG، ویدیوها</li>
                                <li>همه گفتگوها، پیام‌ها و آمار مصرف</li>
                                <li>همه اشتراک‌ها</li>
                            </ul>
                            <button type="button" class="btn btn-danger" data-admin-action="open-db-reset">🔄 ریست دیتابیس</button>
                        </div>
                    </div>

                </main>
            </div>
        </div>
    </section>
    <!-- END VIEW: ADMIN -->

</div>
<!-- END #app -->


<!-- ================================================================
     GLOBAL MODALS
     ================================================================ -->

<!-- Video Player Modal -->
<div id="video-player-modal" class="modal modal-fullscreen" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="video-player-title">—</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body">
            <video id="video-player" class="video-player" controls preload="metadata">
                مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.
            </video>
            <p class="video-description" id="video-player-description"></p>

            <div id="video-player-collection-nav" class="filters-bar" style="margin-top:16px; justify-content:space-between;" hidden>
                <button type="button" class="btn btn-ghost" id="video-player-prev-btn">
                    → ویدیوی قبلی
                </button>
                <span class="text-muted" id="video-player-collection-pos">—</span>
                <button type="button" class="btn btn-ghost" id="video-player-next-btn">
                    ویدیوی بعدی ←
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Confirm -->
<div id="modal-confirm" class="modal modal-sm" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-confirm-title">تأیید</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body">
            <p class="confirm-message" id="modal-confirm-message">آیا مطمئن هستید؟</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
            <button type="button" class="btn btn-danger" id="modal-confirm-ok">تأیید</button>
        </div>
    </div>
</div>

<!-- Modal: Typed Confirm -->
<div id="modal-typed-confirm" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="typed-confirm-title">تأیید حذف دائمی</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body">
            <div class="typed-confirm-box">
                <div id="typed-confirm-message"></div>
                <div style="margin-top: 8px;">
                    نام مورد انتظار: <span class="typed-confirm-expected" id="typed-confirm-expected">—</span>
                </div>
            </div>

            <div class="form-group">
                <label for="typed-confirm-input" class="form-label">برای تأیید، نام دقیق را تایپ کنید یا کلمه DELETE را وارد کنید:</label>
                <input type="text" id="typed-confirm-input" class="form-input" dir="ltr" autocomplete="off">
                <span class="form-error" id="typed-confirm-error" hidden></span>
            </div>

            <label class="form-checkbox" id="typed-confirm-cascade-wrap" hidden>
                <input type="checkbox" id="typed-confirm-cascade">
                <span>حذف وابستگی‌ها (cascade)</span>
            </label>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
            <button type="button" class="btn btn-danger" id="typed-confirm-ok" disabled>حذف دائمی</button>
        </div>
    </div>
</div>

<!-- Modal: Prompt -->
<div id="modal-prompt" class="modal modal-sm" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="prompt-title">ورود مقدار</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body">
            <label for="prompt-input" class="prompt-label" id="prompt-label"></label>
            <input type="text" id="prompt-input" class="form-input" dir="ltr">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
            <button type="button" class="btn btn-primary" id="prompt-ok">ذخیره</button>
        </div>
    </div>
</div>

<!-- Modal: School -->
<div id="modal-school" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-school-title">افزودن مدرسه</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-school" class="modal-body">
            <input type="hidden" id="school-id">
            <div class="form-group">
                <label for="school-name" class="form-label">نام مدرسه *</label>
                <input type="text" id="school-name" class="form-input" required minlength="2" maxlength="200">
            </div>
            <div class="form-group">
                <label for="school-code" class="form-label">کد مدرسه *</label>
                <input type="text" id="school-code" class="form-input" dir="ltr" required minlength="2" maxlength="50">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="school-type" class="form-label">نوع مدرسه / سطح *</label>
                    <select id="school-type" class="form-input" required>
                        <option value="elementary">ابتدایی</option>
                        <option value="middle">متوسطه اول</option>
                        <option value="high">متوسطه دوم</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="school-gender" class="form-label">جنسیت مدرسه *</label>
                    <select id="school-gender" class="form-input" required>
                        <option value="girls">دخترانه</option>
                        <option value="boys">پسرانه</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="school-description" class="form-label">توضیحات</label>
                <textarea id="school-description" class="form-input" rows="3" maxlength="2000"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="school-phone" class="form-label">شماره تماس</label>
                    <input type="text" id="school-phone" class="form-input" dir="ltr" maxlength="30">
                </div>
                <div class="form-group">
                    <label for="school-email" class="form-label">ایمیل</label>
                    <input type="email" id="school-email" class="form-input" dir="ltr" maxlength="150">
                </div>
            </div>
            <div class="form-group">
                <label for="school-address" class="form-label">آدرس</label>
                <textarea id="school-address" class="form-input" rows="2" maxlength="1000"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Manager -->
<div id="modal-manager" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-manager-title">افزودن مدیر مدرسه</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-manager" class="modal-body">
            <input type="hidden" id="manager-id">

            <div class="form-group">
                <label for="manager-school" class="form-label">مدرسه *</label>
                <select id="manager-school" class="form-input" required></select>
                <span class="form-hint" id="manager-school-hint">
                    هر مدرسه می‌تواند یک مدیر فعال داشته باشد.
                </span>
            </div>

            <div class="form-group">
                <label for="manager-fullname" class="form-label">نام و نام خانوادگی *</label>
                <input type="text" id="manager-fullname" class="form-input"
                       required minlength="2" maxlength="150">
            </div>

            <div class="form-group" data-manager-create-only>
                <label for="manager-username" class="form-label">نام کاربری *</label>
                <input type="text" id="manager-username" class="form-input" dir="ltr"
                       required minlength="3" maxlength="100"
                       pattern="[A-Za-z0-9_.\-]+"
                       placeholder="مثال: manager_ali">
                <span class="form-hint">فقط حروف انگلیسی، اعداد، _، . و -</span>
            </div>

            <div class="form-group" data-manager-create-only>
                <label for="manager-password" class="form-label">رمز عبور *</label>
                <input type="password" id="manager-password" class="form-input" dir="ltr"
                       required minlength="6" maxlength="72">
                <span class="form-hint">حداقل ۶ کاراکتر</span>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Class -->
<div id="modal-class" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-class-title">افزودن کلاس</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-class" class="modal-body">
            <input type="hidden" id="class-id">
            <div class="form-group">
                <label for="class-school" class="form-label">مدرسه *</label>
                <select id="class-school" class="form-input" required></select>
            </div>
            <div class="form-group">
                <label for="class-name" class="form-label">نام کلاس *</label>
                <input type="text" id="class-name" class="form-input" required maxlength="150">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="class-grade" class="form-label">پایه</label>
                    <input type="text" id="class-grade" class="form-input" maxlength="50">
                </div>
                <div class="form-group">
                    <label for="class-year" class="form-label">سال تحصیلی</label>
                    <input type="text" id="class-year" class="form-input" maxlength="20" placeholder="۱۴۰۵-۱۴۰۶">
                </div>
            </div>
            <div class="form-group">
                <label for="class-description" class="form-label">توضیحات</label>
                <textarea id="class-description" class="form-input" rows="2" maxlength="2000"></textarea>
            </div>

            <div class="form-group" id="class-ai-group" hidden>
                <label class="form-label">دستیارهای هوش مصنوعی کلاس</label>
                <span class="form-hint">
                    می‌توانید چند دستیار را انتخاب کنید. یکی می‌تواند به‌عنوان پیش‌فرض تعیین شود.
                </span>
                <div class="rag-selector" id="class-ai-selector">
                    <div class="ai-selector-placeholder">در حال بارگذاری...</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Student -->
<div id="modal-student" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-student-title">افزودن دانش‌آموز</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-student" class="modal-body">
            <input type="hidden" id="student-id">
            <div class="form-group">
                <label for="student-school" class="form-label">مدرسه *</label>
                <select id="student-school" class="form-input" required></select>
            </div>
            <div class="form-group">
                <label for="student-class" class="form-label">کلاس</label>
                <select id="student-class" class="form-input"></select>
            </div>
            <div class="form-group">
                <label for="student-fullname" class="form-label">نام و نام خانوادگی *</label>
                <input type="text" id="student-fullname" class="form-input" required minlength="2" maxlength="150">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="student-code" class="form-label">کد دانش‌آموزی</label>
                    <input type="text" id="student-code" class="form-input" dir="ltr" maxlength="50">
                </div>
                <div class="form-group">
                    <label for="student-grade" class="form-label">پایه</label>
                    <input type="text" id="student-grade" class="form-input" maxlength="50">
                </div>
            </div>
            <div class="form-group" data-student-create-only>
                <label for="student-username" class="form-label">نام کاربری *</label>
                <input type="text" id="student-username" class="form-input" dir="ltr" required minlength="3" maxlength="100">
            </div>
            <div class="form-group" data-student-create-only>
                <label for="student-password" class="form-label">رمز عبور *</label>
                <input type="password" id="student-password" class="form-input" dir="ltr" required minlength="6" maxlength="72">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: AI Profile -->
<div id="modal-ai" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-ai-title">ایجاد AI Profile</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-ai" class="modal-body">
            <input type="hidden" id="ai-id">

            <div class="form-group">
                <label for="ai-name" class="form-label">نام AI *</label>
                <input type="text" id="ai-name" class="form-input" required minlength="2" maxlength="200" placeholder="مثال: AI ریاضی چهارم">
            </div>
            <div class="form-group">
                <label for="ai-description" class="form-label">توضیحات</label>
                <textarea id="ai-description" class="form-input" rows="2" maxlength="2000"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ai-target-school-type" class="form-label">مقطع هدف AI *</label>
                    <select id="ai-target-school-type" class="form-input" required>
                        <option value="">— انتخاب کنید —</option>
                        <option value="elementary">ابتدایی</option>
                        <option value="middle">متوسطه اول</option>
                        <option value="high">متوسطه دوم</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ai-target-gender" class="form-label">جنسیت هدف AI *</label>
                    <select id="ai-target-gender" class="form-input" required>
                        <option value="">— انتخاب کنید —</option>
                        <option value="both">هردو</option>
                        <option value="girls">دخترانه</option>
                        <option value="boys">پسرانه</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="ai-target-grade" class="form-label">پایه هدف AI (اختیاری)</label>
                <select id="ai-target-grade" class="form-input">
                    <option value="">— کل مقطع —</option>
                </select>
                <span class="form-hint">
                    اگر خالی بگذارید، AI برای تمام پایه‌های آن مقطع قابل استفاده خواهد بود.
                </span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ai-provider" class="form-label">Provider</label>
                    <select id="ai-provider" class="form-input">
                        <option value="gapgpt">GapGPT</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="ai-model" class="form-label">Model</label>
                    <select id="ai-model" class="form-input" required>
                    </select>
                    <span class="form-hint" id="ai-model-hint"></span>
                </div>
            </div>

            <div class="form-group" id="ai-api-key-group">
                <label for="ai-api-key" class="form-label">
                    API Key
                    <span class="form-hint-inline" id="ai-api-key-hint-wrap" hidden>
                        (ذخیره‌شده: <code id="ai-api-key-hint">—</code>)
                    </span>
                </label>
                <div class="input-with-action">
                    <input type="password" id="ai-api-key" class="form-input" dir="ltr"
                           autocomplete="off" placeholder="کلید API خود را وارد کنید...">
                    <button type="button" class="input-action-btn" id="ai-api-key-toggle"
                            aria-label="نمایش/مخفی کردن" tabindex="-1">
                        <span class="password-icon-show">👁</span>
                        <span class="password-icon-hide" hidden>🙈</span>
                    </button>
                </div>
                <span class="form-hint" id="ai-api-key-help">
                    برای GapGPT معمولاً نیازی به کلید اختصاصی روی هر AI نیست (کلید سراسری از پیکربندی سرور خوانده می‌شود). فقط در صورت نیاز، کلید اختصاصی وارد کنید.
                </span>
                <div class="form-row" style="margin-top: 8px;">
                    <button type="button" class="btn btn-sm btn-ghost" id="ai-test-connection-btn">
                        🔌 تست اتصال
                    </button>
                    <button type="button" class="btn btn-sm btn-ghost" id="ai-clear-api-key-btn" hidden
                            style="color: var(--color-error);">
                        🗑 پاک کردن کلید
                    </button>
                </div>
                <span class="form-hint" id="ai-test-status"></span>
            </div>

            <div class="form-group">
                <label for="ai-prompt" class="form-label">System Prompt</label>
                <textarea id="ai-prompt" class="form-input" rows="5" maxlength="10000" placeholder="شخصیت و قوانین AI را اینجا بنویسید..."></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="ai-temperature" class="form-label">Temperature</label>
                    <input type="number" id="ai-temperature" class="form-input" dir="ltr" min="0" max="2" step="0.1" value="0.7">
                </div>
                <div class="form-group">
                    <label for="ai-max-tokens" class="form-label">حداکثر توکن خروجی</label>
                    <input type="number" id="ai-max-tokens" class="form-input" dir="ltr" min="1" max="10000" value="1000">
                </div>
            </div>

            <div class="form-group" id="ai-rag-group">
                <label class="form-label">مجموعه‌های RAG متصل</label>
                <span class="form-hint">
                    می‌توانید چند مجموعه را انتخاب کنید. اگر خالی بگذارید، هیچ مجموعه‌ای متصل نمی‌شود.
                </span>
                <div class="rag-selector" id="ai-rag-selector">
                    <div class="ai-selector-placeholder">در حال بارگذاری...</div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: RAG Collection -->
<div id="modal-rag" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">ایجاد مجموعه RAG</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-rag" class="modal-body">
            <div class="form-group">
                <label for="rag-name" class="form-label">نام مجموعه *</label>
                <input type="text" id="rag-name" class="form-input" required minlength="2" maxlength="200">
            </div>
            <div class="form-group">
                <label for="rag-description" class="form-label">توضیحات</label>
                <textarea id="rag-description" class="form-input" rows="2" maxlength="2000"></textarea>
            </div>
            <div class="form-group">
                <label for="rag-type" class="form-label">نوع</label>
                <select id="rag-type" class="form-input">
                    <option value="textbook">کتاب درسی</option>
                    <option value="school_content">محتوای مدرسه</option>
                    <option value="exam_content">نمونه سوال</option>
                    <option value="custom">سایر</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: RAG Upload -->
<div id="modal-rag-upload" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">آپلود سند RAG</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-rag-upload" class="modal-body">
            <div class="form-group">
                <label for="rag-upload-collection" class="form-label">مجموعه *</label>
                <select id="rag-upload-collection" class="form-input" required></select>
            </div>
            <div class="form-group">
                <label for="rag-upload-title" class="form-label">عنوان (اختیاری)</label>
                <input type="text" id="rag-upload-title" class="form-input" maxlength="255" placeholder="پیش‌فرض: نام فایل">
            </div>
            <div class="form-group">
                <label for="rag-upload-file" class="form-label">فایل *</label>
                <div class="file-input-wrap">
                    <input type="file" id="rag-upload-file" accept=".pdf,.txt,.md,.docx" required>
                    <span class="file-hint">فرمت‌های مجاز: PDF، TXT، MD، DOCX</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">آپلود و پردازش</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: RAG Detail -->
<div id="modal-rag-detail" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">جزئیات مجموعه RAG</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body" id="rag-detail-body">
            <div class="empty-title">در حال بارگذاری...</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>بستن</button>
        </div>
    </div>
</div>

<!-- Modal: Video Upload -->
<div id="modal-video-upload" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">آپلود ویدیو</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-video-upload" class="modal-body">
            <div class="form-group">
                <label for="video-upload-file" class="form-label">فایل ویدیو *</label>
                <div class="file-input-wrap">
                    <input type="file" id="video-upload-file" accept=".mp4,.webm,.ogg,.mov,.mkv,.avi" required>
                    <span class="file-hint">فرمت‌های مجاز: MP4، WebM، OGG، MOV، MKV، AVI</span>
                </div>
            </div>
            <div class="form-group">
                <label for="video-upload-title" class="form-label">عنوان *</label>
                <input type="text" id="video-upload-title" class="form-input" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="video-upload-description" class="form-label">توضیحات</label>
                <textarea id="video-upload-description" class="form-input" rows="2" maxlength="5000"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="video-upload-target-school-type" class="form-label">هدف ویدیو — سطح *</label>
                    <select id="video-upload-target-school-type" class="form-input" required>
                        <option value="elementary">ابتدایی</option>
                        <option value="middle">متوسطه اول</option>
                        <option value="high">متوسطه دوم</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="video-upload-target-gender" class="form-label">هدف ویدیو — جنسیت *</label>
                    <select id="video-upload-target-gender" class="form-input" required>
                        <option value="both">هردو</option>
                        <option value="girls">دخترانه</option>
                        <option value="boys">پسرانه</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="video-upload-grade" class="form-label">پایه هدف (اختیاری)</label>
                <select id="video-upload-grade" class="form-input">
                    <option value="">— کل مقطع —</option>
                </select>
                <span class="form-hint">
                    اگر خالی بگذارید، ویدیو برای تمام پایه‌های آن مقطع نمایش داده می‌شود.
                </span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="video-upload-subject" class="form-label">درس</label>
                    <input type="text" id="video-upload-subject" class="form-input" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="video-upload-chapter" class="form-label">فصل</label>
                    <input type="text" id="video-upload-chapter" class="form-input" maxlength="100">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="video-upload-category" class="form-label">دسته</label>
                    <input type="text" id="video-upload-category" class="form-input" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="video-upload-duration" class="form-label">مدت (ثانیه — اختیاری)</label>
                    <input type="number" id="video-upload-duration" class="form-input" dir="ltr" min="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">آپلود ویدیو</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Video Edit -->
<div id="modal-video-edit" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">ویرایش ویدیو</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-video-edit" class="modal-body">
            <input type="hidden" id="video-edit-id">
            <div class="form-group">
                <label for="video-edit-title" class="form-label">عنوان *</label>
                <input type="text" id="video-edit-title" class="form-input" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="video-edit-description" class="form-label">توضیحات</label>
                <textarea id="video-edit-description" class="form-input" rows="2" maxlength="5000"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="video-edit-target-school-type" class="form-label">هدف ویدیو — سطح *</label>
                    <select id="video-edit-target-school-type" class="form-input" required>
                        <option value="elementary">ابتدایی</option>
                        <option value="middle">متوسطه اول</option>
                        <option value="high">متوسطه دوم</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="video-edit-target-gender" class="form-label">هدف ویدیو — جنسیت *</label>
                    <select id="video-edit-target-gender" class="form-input" required>
                        <option value="both">هردو</option>
                        <option value="girls">دخترانه</option>
                        <option value="boys">پسرانه</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="video-edit-grade" class="form-label">پایه هدف (اختیاری)</label>
                <select id="video-edit-grade" class="form-input">
                    <option value="">— کل مقطع —</option>
                </select>
                <span class="form-hint">
                    اگر خالی بگذارید، ویدیو برای تمام پایه‌های آن مقطع نمایش داده می‌شود.
                </span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="video-edit-subject" class="form-label">درس</label>
                    <input type="text" id="video-edit-subject" class="form-input" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="video-edit-chapter" class="form-label">فصل</label>
                    <input type="text" id="video-edit-chapter" class="form-input" maxlength="100">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="video-edit-category" class="form-label">دسته</label>
                    <input type="text" id="video-edit-category" class="form-input" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="video-edit-order" class="form-label">ترتیب نمایش</label>
                    <input type="number" id="video-edit-order" class="form-input" dir="ltr" min="0" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Video Collection -->
<div id="modal-video-collection" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-video-collection-title">ایجاد مجموعه ویدیو</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-video-collection" class="modal-body">
            <input type="hidden" id="video-collection-id">

            <div class="form-group">
                <label for="video-collection-title" class="form-label">عنوان *</label>
                <input type="text" id="video-collection-title" class="form-input"
                       required minlength="2" maxlength="200"
                       placeholder="مثال: آموزش پرامپت‌نویسی مقدماتی">
            </div>

            <div class="form-group">
                <label for="video-collection-description" class="form-label">توضیحات</label>
                <textarea id="video-collection-description" class="form-input" rows="3" maxlength="2000"
                          placeholder="توضیح کوتاه درباره این مجموعه"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="video-collection-category" class="form-label">دسته‌بندی</label>
                    <input type="text" id="video-collection-category" class="form-input" maxlength="100"
                           placeholder="مثال: هوش مصنوعی">
                </div>
                <div class="form-group">
                    <label for="video-collection-level" class="form-label">سطح</label>
                    <select id="video-collection-level" class="form-input">
                        <option value="">— انتخاب کنید —</option>
                        <option value="elementary">ابتدایی</option>
                        <option value="middle">متوسطه اول</option>
                        <option value="high">متوسطه دوم</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="video-collection-sort-order" class="form-label">ترتیب نمایش</label>
                    <input type="number" id="video-collection-sort-order" class="form-input" dir="ltr"
                           min="0" value="0">
                    <span class="form-hint">عدد کوچک‌تر، زودتر نمایش داده می‌شود.</span>
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <label class="form-checkbox" style="margin-bottom: 12px;">
                        <input type="checkbox" id="video-collection-is-active" checked>
                        <span>فعال</span>
                    </label>
                </div>
            </div>

            <div class="form-group" id="video-collection-cover-group" hidden>
                <label class="form-label">تصویر کاور</label>
                <div id="video-collection-cover-preview" style="margin-bottom: 8px;"></div>
                <div class="file-input-wrap">
                    <input type="file" id="video-collection-cover-file"
                           accept=".jpg,.jpeg,.png,.webp">
                    <span class="file-hint">فرمت‌های مجاز: JPG، PNG، WebP — حداکثر ۵ مگابایت</span>
                </div>
                <div style="margin-top: 8px;">
                    <button type="button" class="btn btn-sm btn-ghost" id="video-collection-cover-upload-btn">
                        ⬆ آپلود کاور
                    </button>
                    <span class="form-hint" id="video-collection-cover-status"></span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Manage Collection Videos -->
<div id="modal-collection-videos" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-collection-videos-title">مدیریت ویدیوهای مجموعه</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="collection-videos-collection-id">

            <div class="form-group">
                <label for="collection-videos-add-select" class="form-label">افزودن ویدیو به مجموعه</label>
                <div class="form-row">
                    <select id="collection-videos-add-select" class="form-input">
                        <option value="">— انتخاب ویدیو —</option>
                    </select>
                    <button type="button" class="btn btn-primary" id="collection-videos-add-btn">
                        + افزودن
                    </button>
                </div>
                <span class="form-hint">می‌توانید یک ویدیو را در چند مجموعه مختلف قرار دهید.</span>
            </div>

            <hr style="margin: 16px 0; border: none; border-top: 1px solid var(--color-border);">

            <div class="form-group">
                <label class="form-label">
                    ویدیوهای فعلی
                    <span class="form-hint-inline" id="collection-videos-count">(0)</span>
                </label>
                <div id="collection-videos-list" style="max-height: 420px; overflow-y: auto;">
                    <div class="empty-state">
                        <div class="empty-title">در حال بارگذاری...</div>
                    </div>
                </div>
                <span class="form-hint">
                    ترتیب ویدیوها با دکمه‌های ▲ ▼ تغییر می‌کند و بلافاصله ذخیره می‌شود.
                </span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>بستن</button>
        </div>
    </div>
</div>

<!-- Modal: Download Release -->
<div id="modal-download" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-download-title">افزودن انتشار</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-download" class="modal-body" enctype="multipart/form-data">
            <input type="hidden" id="download-id">
            <input type="hidden" id="download-mode" value="create">

            <div class="form-group">
                <label for="download-title" class="form-label">عنوان انتشار *</label>
                <input type="text" id="download-title" class="form-input" required minlength="2" maxlength="200"
                       placeholder="مثال: DANOVA Desktop">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="download-platform" class="form-label">پلتفرم *</label>
                    <select id="download-platform" class="form-input" required>
                        <option value="windows">Windows</option>
                        <option value="android">Android</option>
                        <option value="ios">iOS</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="download-version" class="form-label">نسخه *</label>
                    <input type="text" id="download-version" class="form-input" dir="ltr" required
                           placeholder="1.0.0" pattern="\d+(\.\d+){0,3}(-[A-Za-z0-9.\-]+)?">
                </div>
            </div>

            <div class="form-group" id="download-release-type-group">
                <label for="download-release-type" class="form-label">نوع انتشار *</label>
                <select id="download-release-type" class="form-input" required>
                    <option value="file">فایل قابل دانلود</option>
                    <option value="store_link">لینک فروشگاه</option>
                </select>
                <span class="form-hint" id="download-release-type-hint">برای iOS فقط لینک فروشگاه مجاز است.</span>
            </div>

            <div class="form-group" id="download-file-group">
                <label for="download-file" class="form-label">فایل انتشار</label>
                <div class="file-input-wrap">
                    <input type="file" id="download-file" accept=".exe,.msi,.zip,.apk">
                    <span class="file-hint" id="download-file-hint">
                        Windows: .exe / .msi / .zip — Android: .apk
                    </span>
                </div>
                <span class="form-hint" id="download-file-current"></span>
            </div>

            <div class="form-group" id="download-url-group" hidden>
                <label for="download-external-url" class="form-label">لینک فروشگاه *</label>
                <input type="url" id="download-external-url" class="form-input" dir="ltr"
                       placeholder="https://apps.apple.com/...">
                <span class="form-hint">فقط لینک HTTPS معتبر (App Store، TestFlight، Google Play و ...)</span>
            </div>

            <div class="form-group">
                <label for="download-min-os" class="form-label">حداقل نسخه سیستم‌عامل (اختیاری)</label>
                <input type="text" id="download-min-os" class="form-input" maxlength="100"
                       placeholder="مثال: Windows 10+ / Android 10+ / iOS 16+">
            </div>

            <div class="form-group">
                <label for="download-description" class="form-label">توضیحات</label>
                <textarea id="download-description" class="form-input" rows="3" maxlength="5000"></textarea>
            </div>

            <label class="form-checkbox">
                <input type="checkbox" id="download-publish-now" checked>
                <span>انتشار فوری</span>
            </label>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="download-submit-btn">ذخیره</button>
            </div>
        
            
</form>

            <!-- Upload Progress Overlay (Download only) -->
            <div id="download-progress-overlay" class="download-progress-overlay" hidden>
                <div class="download-progress-card">
                    <div class="download-progress-header">
                        <div class="download-progress-icon">
                            <span class="download-progress-icon-arrow">⬆</span>
                        </div>
                        <div class="download-progress-title-wrap">
                            <div class="download-progress-title">در حال آپلود فایل...</div>
                            <div class="download-progress-file" id="download-progress-filename">—</div>
                        </div>
                    </div>

                    <div class="download-progress-bar-wrap">
                        <div class="download-progress-bar" id="download-progress-bar" style="width:0%">
                            <div class="download-progress-bar-shine"></div>
                        </div>
                    </div>

                    <div class="download-progress-stats">
                        <div class="download-progress-stat">
                            <div class="download-progress-stat-label">درصد</div>
                            <div class="download-progress-stat-value" id="download-progress-percent">۰٪</div>
                        </div>
                        <div class="download-progress-stat">
                            <div class="download-progress-stat-label">حجم</div>
                            <div class="download-progress-stat-value" id="download-progress-size">— / —</div>
                        </div>
                        <div class="download-progress-stat">
                            <div class="download-progress-stat-label">سرعت</div>
                            <div class="download-progress-stat-value" id="download-progress-speed">—</div>
                        </div>
                        <div class="download-progress-stat">
                            <div class="download-progress-stat-label">باقی‌مانده</div>
                            <div class="download-progress-stat-value" id="download-progress-eta">—</div>
                        </div>
                    </div>

                    <div class="download-progress-actions">
                        <button type="button" class="btn btn-ghost btn-sm" id="download-progress-cancel">
                            لغو آپلود
                        </button>
                    </div>
                </div>
            </div>

    </div>
</div>

<!-- Modal: Admin Password Change -->
<div id="modal-admin-password" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">تغییر رمز عبور ادمین</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-admin-password" class="modal-body">
            <div class="form-group">
                <label for="admin-current-password" class="form-label">رمز عبور فعلی</label>
                <input type="password" id="admin-current-password" class="form-input" dir="ltr" required minlength="6">
            </div>
            <div class="form-group">
                <label for="admin-new-password" class="form-label">رمز عبور جدید</label>
                <input type="password" id="admin-new-password" class="form-input" dir="ltr" required minlength="6" maxlength="72">
            </div>
            <div class="form-group">
                <label for="admin-confirm-password" class="form-label">تکرار رمز عبور جدید</label>
                <input type="password" id="admin-confirm-password" class="form-input" dir="ltr" required minlength="6">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">تغییر رمز</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Admin Username Change -->
<div id="modal-admin-username" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">تغییر نام کاربری ادمین</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-admin-username" class="modal-body">
            <div class="alert alert-warning">
                <span class="alert-icon">⚠</span>
                <span>بعد از تغییر نام کاربری، باید با نام جدید وارد شوید.</span>
            </div>

            <div class="form-group">
                <label for="admin-new-username" class="form-label">نام کاربری جدید *</label>
                <input type="text" id="admin-new-username" class="form-input" dir="ltr"
                       required minlength="3" maxlength="100"
                       pattern="[A-Za-z0-9_.\-]+"
                       placeholder="مثال: admin_new">
                <span class="form-hint">فقط حروف انگلیسی، اعداد، _، . و - مجاز است.</span>
            </div>

            <div class="form-group">
                <label for="admin-username-password" class="form-label">رمز عبور فعلی (برای تأیید) *</label>
                <input type="password" id="admin-username-password" class="form-input" dir="ltr"
                       required autocomplete="current-password">
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">تغییر نام کاربری</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Database Reset -->
<div id="modal-db-reset" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">🔄 ریست دیتابیس</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-db-reset" class="modal-body">
            <div class="typed-confirm-box">
                <strong>هشدار:</strong> این عملیات تمام داده‌های پلتفرم را حذف می‌کند و برگشت‌ناپذیر است.
                برای تأیید، کلمه <code>RESET-DANOVA</code> را وارد کنید و رمز عبور خود را مجدداً بدهید.
            </div>

            <div class="form-group">
                <label for="db-reset-keyword" class="form-label">کلمه تأیید *</label>
                <input type="text" id="db-reset-keyword" class="form-input" dir="ltr" required placeholder="RESET-DANOVA" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="db-reset-password" class="form-label">رمز عبور ادمین *</label>
                <input type="password" id="db-reset-password" class="form-input" dir="ltr" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="db-reset-username" class="form-label">نام کاربری ادمین (اختیاری)</label>
                <input type="text" id="db-reset-username" class="form-input" dir="ltr" autocomplete="username">
            </div>

            <label class="form-checkbox">
                <input type="checkbox" id="db-reset-drop-files">
                <span>حذف فایل‌های ویدیو، بندانگشتی و RAG</span>
            </label>

            <label class="form-checkbox">
                <input type="checkbox" id="db-reset-keep-audit" checked>
                <span>حفظ گزارش فعالیت‌ها (Audit Log)</span>
            </label>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-danger">🔄 ریست دیتابیس</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Time Setup -->
<div id="modal-time-setup" class="modal" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">⚙️ تنظیمات ساعت و زمان</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-time-setup" class="modal-body">

            <div class="form-group">
                <label for="time-timezone" class="form-label">منطقه زمانی *</label>
                <input type="text" id="time-timezone" class="form-input" dir="ltr"
                       required maxlength="64" placeholder="Asia/Tehran">
                <span class="form-hint">
                    مثال‌ها: <code>Asia/Tehran</code>، <code>UTC</code>، <code>Europe/London</code>
                </span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="time-reset-hour" class="form-label">ساعت ریست *</label>
                    <input type="number" id="time-reset-hour" class="form-input" dir="ltr"
                           required min="0" max="23" value="0">
                </div>
                <div class="form-group">
                    <label for="time-reset-minute" class="form-label">دقیقه ریست *</label>
                    <input type="number" id="time-reset-minute" class="form-input" dir="ltr"
                           required min="0" max="59" value="0">
                </div>
            </div>

            <div class="form-group">
                <label for="time-reset-second" class="form-label">ثانیه ریست *</label>
                <input type="number" id="time-reset-second" class="form-input" dir="ltr"
                       required min="0" max="59" value="0">
                <span class="form-hint">
                    پیش‌فرض: ۰۰:۰۰:۰۰ به وقت تهران (نیمه‌شب).
                </span>
            </div>

            <hr style="margin:16px 0; border:none; border-top:1px solid var(--color-border);">

            <div class="form-group">
                <label for="time-ntp-server" class="form-label">سرور NTP (اختیاری)</label>
                <input type="text" id="time-ntp-server" class="form-input" dir="ltr"
                       maxlength="255" placeholder="pool.ntp.org">
            </div>

            <label class="form-checkbox">
                <input type="checkbox" id="time-sync-enabled">
                <span>فعال‌سازی همگام‌سازی NTP</span>
            </label>

            <div class="form-group" style="margin-top:12px;">
                <label for="time-manual-offset" class="form-label">آفست دستی (ثانیه)</label>
                <input type="number" id="time-manual-offset" class="form-input" dir="ltr"
                       min="-86400" max="86400" value="0">
                <span class="form-hint">
                    برای اصلاح جزئی ساعت (مثلاً ‎+۳۰ یا ‎-۶۰). مقدار پیش‌فرض ۰ است.
                </span>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات</button>
            </div>
        </form>
    </div>
</div>


<!-- Modal: Create Test Account -->
<div id="modal-test-account" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">🧪 ساخت پنل تست</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <form id="form-test-account" class="modal-body">
            <input type="hidden" id="test-account-id">

            <div class="form-group">
                <label for="test-account-name" class="form-label">نام پنل تست *</label>
                <input type="text" id="test-account-name" class="form-input"
                       required minlength="2" maxlength="200"
                       placeholder="مثال: تست مدرسه نمونه">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="test-account-username" class="form-label">نام کاربری *</label>
                    <input type="text" id="test-account-username" class="form-input" dir="ltr"
                           required minlength="3" maxlength="100"
                           pattern="[A-Za-z0-9_.\-]+"
                           placeholder="test_school1">
                    <span class="form-hint">فقط حروف انگلیسی، اعداد، _، . و -</span>
                </div>
                <div class="form-group">
                    <label for="test-account-password" class="form-label">رمز عبور *</label>
                    <input type="text" id="test-account-password" class="form-input" dir="ltr"
                           required minlength="6" maxlength="72">
                    <span class="form-hint">حداقل ۶ کاراکتر</span>
                </div>
            </div>

            <div class="form-group">
                <label for="test-account-school" class="form-label">مدرسه مرتبط (اختیاری)</label>
                <select id="test-account-school" class="form-input">
                    <option value="">— بدون مدرسه (تست مستقل) —</option>
                </select>
                <span class="form-hint">اگر خالی بگذارید، پنل تست به هیچ مدرسه‌ای متصل نیست.</span>
            </div>

            <div class="form-group">
                <label for="test-account-ai" class="form-label">دستیار AI *</label>
                <select id="test-account-ai" class="form-input" required>
                    <option value="">— انتخاب کنید —</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="test-account-expires" class="form-label">زمان انقضا *</label>
                    <input type="datetime-local" id="test-account-expires" class="form-input"
                           required dir="ltr">
                    <span class="form-hint">حداکثر ۷۲ ساعت از الان</span>
                </div>
                <div class="form-group">
                    <label for="test-account-message-limit" class="form-label">سقف پیام *</label>
                    <input type="number" id="test-account-message-limit" class="form-input"
                           required min="1" max="50" value="10" dir="ltr">
                    <span class="form-hint">حداکثر ۵۰ پیام</span>
                </div>
            </div>

            <div class="form-group">
                <label for="test-account-description" class="form-label">توضیحات داخلی (اختیاری)</label>
                <textarea id="test-account-description" class="form-input"
                          rows="2" maxlength="500"
                          placeholder="یادداشت داخلی برای هدف این پنل تست"></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>انصراف</button>
                <button type="submit" class="btn btn-primary" id="test-account-submit">ایجاد پنل تست</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Download Center (Public) -->
<div id="modal-download-center" class="modal modal-lg" hidden>
    <div class="modal-backdrop" data-modal-close></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">📥 مرکز دانلود دانُوا</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="بستن">✕</button>
        </div>
        <div class="modal-body" id="download-center-body">
            <div class="empty-state">
                <div class="empty-icon">📥</div>
                <div class="empty-title">در حال بارگذاری...</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-modal-close>بستن</button>
        </div>
    </div>
</div>

<!-- ================================================================
     GLOBAL CONTAINERS
     ================================================================ -->

<div id="toast-container" class="toast-container" aria-live="polite"></div>

<div id="loading-overlay" class="loading-overlay" hidden>
    <div class="loading-spinner">
        <span class="spinner spinner-lg"></span>
        <span class="loading-text">در حال بارگذاری...</span>
    </div>
</div>


<!-- ================================================================
     SCRIPTS
     ================================================================ -->

<script src="/app.js" defer></script>

</body>
</html>
DANOVA_HTML;

// =====================================================================
// 7b. ✅ تزریق nonce به همه <script> tags (رفع خطای CSP)
// =====================================================================
if (function_exists('security_csp_nonce')) {
    $nonce = security_csp_nonce();

    if ($nonce !== '' && $nonce !== null) {
        $html = preg_replace_callback(
            '/<script\b([^>]*)>/i',
            function ($m) use ($nonce) {
                $attrs = $m[1];

                // حذف nonce قبلی در صورت وجود (جلوگیری از تکرار)
                $attrs = preg_replace('/\s+nonce\s*=\s*["\'][^"\']*["\']/i', '', $attrs);

                // اطمینان از اینکه type="module" بدون تغییر باقی می‌ماند
                return '<script' . $attrs . ' nonce="'
                     . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8')
                     . '">';
            },
            $html
        );
    }
}

echo $html;
exit;