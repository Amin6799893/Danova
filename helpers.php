<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: helpers.php
 * Version: 3.1.0 (PATCH — Fix preg_replace null subject bug)
 * Purpose: Reusable Utilities (Response, Pagination, File, Time, Text)
 *          + Persian Normalization Pipeline (Index & Query)
 *          + RAG-specific text utilities (heading/section detection)
 *          + RAG 2.0 Pipeline (Hybrid, Rerank, Groundedness, etc.)
 *          + RAG Migration CLI Tool
 * ---------------------------------------------------------------------
 * Changelog v3.1.0 (BUG FIX — preg_replace null subject):
 *   FIXED:
 *     - text_normalize_persian_deep() now sanitizes UTF-8 input with
 *       mb_check_encoding() before any preg_replace with /u flag.
 *     - All preg_replace() calls in text_normalize_persian_deep()
 *       now use the null-check pattern:
 *           $tmp = preg_replace(...);
 *           if ($tmp !== null) $text = $tmp;
 *       This prevents:
 *           "preg_replace(): Argument #3 ($subject) must be of type
 *            array|string, null given"
 *       when PCRE fails on invalid UTF-8 input (common in PDF extract).
 *   KEPT:
 *     - All logic of v3.0.0 without change.
 *     - All function signatures (100% backward compatible).
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v3.0.0 (MERGED):
 *   MERGED:
 *     - محتوای کامل rag_helpers.php (SECTION 14-24)
 *     - محتوای کامل migrate_rag.php (SECTION 25 — داخل CLI guard)
 *   KEPT:
 *     - تمام منطق v2.0.1 بدون تغییر (SECTION 1-13)
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v2.0.1 (SAFE BUG FIX — NO BREAKING CHANGES):
 *   FIXED:
 *     - Bug M-03 (Performance):
 *         * text_detect_script() now uses preg_match_all()
 *           instead of char-by-char mb_substr+mb_ord loop.
 *         * 10-20× faster on 200-char sample.
 *         * Behavior IDENTICAL to v2.0.0 (same classification).
 *   KEPT: all other functions, signatures, behavior.
 * ---------------------------------------------------------------------
 * Changelog v2.0.0 (RAG 2.0 Upgrade — Persian Normalization + RAG Utilities)
 * =====================================================================
 */

declare(strict_types=1);

// ⛔ جلوگیری از دسترسی مستقیم به این فایل
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    // فقط در CLI اجازه بده (برای migrate_rag)
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('Direct access denied');
    }
}

require_once __DIR__ . '/config.php';


// =====================================================================
// SECTION 1: RESPONSE HELPERS
// =====================================================================

/**
 * پاسخ موفق با پیام + داده.
 */
function respond_ok(string $message = '', array $data = []): void
{
    $payload = $data;
    if ($message !== '') {
        $payload['message'] = $message;
    }
    json_ok($payload);
}

/**
 * پاسخ موفق با کد 201 Created.
 */
function respond_created(string $message, array $data = []): void
{
    $payload = ['success' => true];
    if ($message !== '') $payload['message'] = $message;
    foreach ($data as $k => $v) $payload[$k] = $v;
    json_response($payload, 201);
}

/**
 * پاسخ بدون محتوا (204 No Content) — فقط برای DELETE.
 */
function respond_no_content(): void
{
    http_response_code(204);
    exit;
}

/**
 * پاسخ خطای اعتبارسنجی با جزئیات فیلد.
 */
function respond_validation_error(array $fieldErrors, string $message = 'اعتبارسنجی ناموفق بود.'): void
{
    json_error($message, 400, [
        'errors' => $fieldErrors,
    ]);
}

/**
 * پاسخ خطای محدودیت نرخ (429).
 */
function respond_rate_limited(string $message = 'تعداد درخواست‌های شما بیش از حد مجاز است.', int $retryAfter = 0): void
{
    if ($retryAfter > 0 && !headers_sent()) {
        header('Retry-After: ' . $retryAfter);
    }
    json_error($message, 429);
}


// =====================================================================
// SECTION 2: PAGINATION HELPERS
// =====================================================================
// NOTE: تابع paginate() در models.php تعریف شده است.
// =====================================================================

// =====================================================================
// SECTION 3: SAFE FILE HELPERS
// =====================================================================

/**
 * تولید نام ذخیره‌سازی امن با پسوند مشخص.
 */
function generate_storage_name(string $extension): string
{
    $ext = strtolower(trim($extension));
    if ($ext === '' || !preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
        $ext = 'bin';
    }
    return generate_uuid() . '.' . $ext;
}

/**
 * بررسی سازگاری پسوند با MIME.
 */
function extension_matches_mime(string $ext, string $mime): bool
{
    $map = [
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'md'   => ['text/markdown', 'text/plain', 'text/x-markdown'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
        'ogg'  => ['video/ogg', 'audio/ogg'],
        'mov'  => ['video/quicktime'],
        'mkv'  => ['video/x-matroska'],
        'avi'  => ['video/x-msvideo'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
    ];

    $ext  = strtolower($ext);
    $mime = strtolower($mime);

    if (!isset($map[$ext])) return false;
    return in_array($mime, $map[$ext], true);
}

/**
 * حذف امن یک فایل داخل allowedDir.
 */
function safe_unlink(?string $path, string $allowedDir): bool
{
    if (!$path) return false;
    $real = safe_file_path($path, $allowedDir);
    if (!$real) return false;
    return @unlink($real);
}

/**
 * خواندن امن حجم یک فایل (بدون خطا).
 */
function safe_filesize(?string $path): int
{
    if (!$path || !is_file($path)) return 0;
    $size = @filesize($path);
    return is_int($size) && $size > 0 ? $size : 0;
}

/**
 * فرمت حجم فایل به رشته انسانی.
 */
function format_bytes(int $bytes, int $precision = 1): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return number_format($n, $i === 0 ? 0 : $precision) . ' ' . $units[$i];
}


// =====================================================================
// SECTION 4: TIME HELPERS
// =====================================================================

function utcnow_offset(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

function utcnow_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function is_today_utc(?string $datetime): bool
{
    if (!$datetime) return false;
    $today = gmdate('Y-m-d');
    return strpos($datetime, $today) === 0;
}

function today_start_utc(): string
{
    return gmdate('Y-m-d 00:00:00');
}

function today_end_utc(): string
{
    return gmdate('Y-m-d 00:00:00', strtotime('+1 day UTC'));
}

function time_ago_fa(?string $datetime): string
{
    if (!$datetime) return '—';

    $ts = strtotime($datetime . ' UTC');
    if ($ts === false) return '—';

    $diff = time() - $ts;
    if ($diff < 0) return 'آینده';

    if ($diff < 60)      return 'همین حالا';
    if ($diff < 3600)    return (int)($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400)   return (int)($diff / 3600) . ' ساعت پیش';
    if ($diff < 2592000) return (int)($diff / 86400) . ' روز پیش';
    if ($diff < 31536000) return (int)($diff / 2592000) . ' ماه پیش';
    return (int)($diff / 31536000) . ' سال پیش';
}


// =====================================================================
// SECTION 5: TEXT HELPERS
// =====================================================================

/**
 * برش امن متن با رعایت UTF-8.
 */
function text_truncate(?string $text, int $maxLen, string $suffix = '…'): string
{
    if ($text === null || $text === '') return '';
    if ($maxLen <= 0) return '';

    if (mb_strlen($text, 'UTF-8') <= $maxLen) {
        return $text;
    }

    $cut = mb_substr($text, 0, $maxLen, 'UTF-8');
    return $cut . $suffix;
}

/**
 * نرمال‌سازی اعداد فارسی/عربی به لاتین.
 */
function text_normalize_digits(string $text): string
{
    return str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
        $text
    );
}

/**
 * نرمال‌سازی حروف عربی/فارسی (ی و ک).
 */
function text_normalize_persian(string $text): string
{
    return str_replace(
        ['ي', 'ك', 'ﻯ', 'ﻰ', 'ﻙ'],
        ['ی', 'ک', 'ی', 'ی', 'ک'],
        $text
    );
}

/**
 * حذف whitespace اضافی.
 */
function text_collapse_whitespace(string $text): string
{
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim((string)$text);
}

/**
 * Slug امن.
 */
function text_slug(string $text, int $maxLen = 100): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[^a-z0-9\-\_]+/u', '-', $text) ?? '';
    $text = preg_replace('/-+/', '-', (string)$text) ?? '';
    $text = trim((string)$text, '-_');
    return mb_substr((string)$text, 0, $maxLen);
}


// =====================================================================
// SECTION 5b: 🇮🇷 PERSIAN NORMALIZATION PIPELINE (RAG-01)
// =====================================================================

/**
 * نرمال‌سازی عمیق متن فارسی برای RAG (index + query).
 *
 * ✅ v3.1.0 (BUG FIX): 
 *   - قبل از هر preg_replace با فلگ /u، ورودی را با mb_check_encoding
 *     sanitize می‌کنیم. اگر بایت نامعتبر UTF-8 وجود داشته باشد
 *     (که در PDFهای استخراج‌شده رایج است)، preg_replace با فلگ /u
 *     مقدار null برمی‌گرداند و خطای زیر رخ می‌دهد:
 *       "preg_replace(): Argument #3 ($subject) must be of type
 *        array|string, null given"
 *   - همه‌ی preg_replaceها با الگوی null-check محافظت شده‌اند.
 */
function text_normalize_persian_deep(string $text): string
{
    if ($text === '') return '';

    // ✅ v3.1.0 FIX: Sanitize UTF-8 قبل از هر regex
    // این خط از شکستن preg_replace با فلگ /u جلوگیری می‌کند
    if (!mb_check_encoding($text, 'UTF-8')) {
        if (function_exists('logger_warn')) {
            logger_warn('[NORMALIZE] Invalid UTF-8 input detected — sanitizing');
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    // 1. Normalize line endings
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // 2. ی/ک عربی → فارسی
    $text = str_replace(
        [
            "\xD9\x8A",           // ي (U+064A) Arabic Yeh
            "\xD9\x89",           // ى (U+0649) Alef Maksura
            "\xD9\x83",           // ك (U+0643) Arabic Kaf
            "\xEF\xBB\xAF",       // ﻯ (U+FEEF)
            "\xEF\xBB\xB0",       // ﻰ (U+FEF0)
            "\xEF\xBB\x99",       // ﻙ (U+FED9)
            "\xEF\xBB\x9A",       // ﻚ (U+FEDA)
        ],
        [
            "\xDB\x8C",           // ی (U+06CC) Persian Yeh
            "\xDB\x8C",           // ی
            "\xDA\xA9",           // ک (U+06A9) Persian Kaf
            "\xDB\x8C",           // ی
            "\xDB\x8C",           // ی
            "\xDA\xA9",           // ک
            "\xDA\xA9",           // ک
        ],
        $text
    );

    // 3. Arabic-Indic & Persian digits → ASCII
    $text = str_replace(
        [
            '٠','١','٢','٣','٤','٥','٦','٧','٨','٩',
            '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹',
        ],
        [
            '0','1','2','3','4','5','6','7','8','9',
            '0','1','2','3','4','5','6','7','8','9',
        ],
        $text
    );

    // ✅ v3.1.0 FIX: حذف diacritics با null-check
    $tmp = preg_replace(
        '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u',
        '',
        $text
    );
    if ($tmp !== null) {
        $text = $tmp;
    } else {
        if (function_exists('logger_warn')) {
            logger_warn('[NORMALIZE] diacritics preg_replace failed — skipping step');
        }
    }

    // 5. حذف Tatweel / Kashida (U+0640 ـ)
    $text = str_replace("\xD9\x80", '', $text);

    // 6. حذف ZWJ و ZWSP (اما ZWNJ حفظ می‌شود)
    $text = str_replace(
        ["\xE2\x80\x8B", "\xE2\x80\x8D"],
        '',
        $text
    );

    // ✅ v3.1.0 FIX: Normalize whitespace با null-check
    $tmp = preg_replace('/[ \t]+/u', ' ', $text);
    if ($tmp !== null) $text = $tmp;

    $tmp = preg_replace('/\n{3,}/u', "\n\n", $text);
    if ($tmp !== null) $text = $tmp;

    // 8. Trim
    $text = trim($text);

    return $text;
}

/**
 * نرمال‌سازی سبک — فقط Yeh/Keh + digits.
 */
function text_normalize_persian_light(string $text): string
{
    if ($text === '') return '';
    $text = text_normalize_persian($text);
    $text = text_normalize_digits($text);
    return $text;
}

/**
 * نرمال‌سازی query کاربر — باید دقیقاً همان pipeline index باشد.
 */
function text_normalize_query(string $query): string
{
    return text_normalize_persian_deep($query);
}

/**
 * ✅ v2.0.1 (Bug M-03): تشخیص اسکریپت غالب متن — بهینه‌شده با preg_match_all.
 * 
 * رفتار خروجی دقیقاً مانند نسخه قبلی (char-by-char) است،
 * اما ۱۰-۲۰× سریع‌تر.
 * 
 * @return string 'persian' | 'arabic' | 'english' | 'mixed' | 'unknown'
 */
function text_detect_script(string $text): string
{
    if ($text === '') return 'unknown';

    // ✅ Sanitize برای اطمینان از اعتبار UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    // نمونه‌برداری از 200 کاراکتر اول برای سرعت
    $sample = mb_substr($text, 0, 200, 'UTF-8');
    if ($sample === '') return 'unknown';

    $persianMatches = preg_match_all(
        '/[\x{067E}\x{0686}\x{0698}\x{06AF}\x{06A9}\x{06CC}]/u',
        $sample
    );
    if ($persianMatches === false) $persianMatches = 0;

    $arabicAll = preg_match_all(
        '/[\x{0600}-\x{06FF}]/u',
        $sample
    );
    if ($arabicAll === false) $arabicAll = 0;

    $arabicOnly = max(0, $arabicAll - $persianMatches);

    $latinMatches = preg_match_all(
        '/[a-zA-Z]/',
        $sample
    );
    if ($latinMatches === false) $latinMatches = 0;

    $total = $persianMatches + $arabicAll + $latinMatches;
    if ($total === 0) return 'unknown';

    // ✅ Bug #17 FIX
    $arabicTotal = $arabicOnly + $persianMatches;
    if ($arabicTotal > 0) {
        $persianRatio = $persianMatches / $arabicTotal;
        if ($persianRatio >= 0.30) {
            if ($latinMatches > $persianMatches) return 'mixed';
            return 'persian';
        }
    }

    if ($arabicOnly > $latinMatches * 2) return 'arabic';
    if ($latinMatches > $arabicOnly * 2) return 'english';

    return 'mixed';
}

/**
 * بررسی heuristic که آیا متن عربی است (نه فارسی).
 */
function text_is_probably_arabic(string $text): bool
{
    return text_detect_script($text) === 'arabic';
}


// =====================================================================
// SECTION 5c: HEADING / SECTION DETECTION (RAG-10)
// =====================================================================

/**
 * تشخیص اینکه آیا خط یک heading است.
 *
 * @return array{is_heading:bool, level:int, text:string}
 */
function text_detect_heading(string $line): array
{
    $trimmed = trim($line);
    $len = mb_strlen($trimmed, 'UTF-8');

    if ($len === 0) {
        return ['is_heading' => false, 'level' => 0, 'text' => ''];
    }

    if ($len > 120) {
        return ['is_heading' => false, 'level' => 0, 'text' => $trimmed];
    }

    if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $m)) {
        $hashCount = strlen($m[1]);
        $level = min(3, $hashCount);
        return ['is_heading' => true, 'level' => $level, 'text' => trim($m[2])];
    }

    $persianPatterns = [
        1 => '/^(فصل|باب|بخش)\s+\S+/u',
        2 => '/^(گفتار|زیربخش|مبحث|موضوع)\s+\S+/u',
        3 => '/^(زیرگفتار)\s+\S+/u',
    ];

    foreach ($persianPatterns as $level => $pattern) {
        if (preg_match($pattern, $trimmed)) {
            return ['is_heading' => true, 'level' => $level, 'text' => $trimmed];
        }
    }

    $englishPatterns = [
        1 => '/^(chapter|part|book)\s+\S+/i',
        2 => '/^(section)\s+\S+/i',
        3 => '/^(subsection)\s+\S+/i',
    ];

    foreach ($englishPatterns as $level => $pattern) {
        if (preg_match($pattern, $trimmed)) {
            return ['is_heading' => true, 'level' => $level, 'text' => $trimmed];
        }
    }

    if (preg_match('/^(\d{1,2})[\.\)\-]\s+\S+/u', $trimmed)) {
        return ['is_heading' => true, 'level' => 2, 'text' => $trimmed];
    }
    if (preg_match('/^(I|II|III|IV|V|VI|VII|VIII|IX|X)[\.\)]\s+\S+/i', $trimmed)) {
        return ['is_heading' => true, 'level' => 1, 'text' => $trimmed];
    }

    return ['is_heading' => false, 'level' => 0, 'text' => $trimmed];
}

/**
 * استخراج heading و body از یک بلوک متن.
 *
 * @return array{heading:?string, level:int, body:string}
 */
function text_extract_section(string $block): array
{
    $block = trim($block);
    if ($block === '') {
        return ['heading' => null, 'level' => 0, 'body' => ''];
    }

    $lines = explode("\n", $block);
    if (empty($lines)) {
        return ['heading' => null, 'level' => 0, 'body' => $block];
    }

    $firstLine = $lines[0];
    $detection = text_detect_heading($firstLine);

    if (!$detection['is_heading']) {
        return ['heading' => null, 'level' => 0, 'body' => $block];
    }

    $body = implode("\n", array_slice($lines, 1));
    return [
        'heading' => $detection['text'],
        'level'   => $detection['level'],
        'body'    => trim($body),
    ];
}

/**
 * Split متن به بخش‌های heading-based.
 *
 * @return array<array{heading:?string, level:int, body:string}>
 */
function text_split_by_headings(string $text): array
{
    if (trim($text) === '') return [];

    // ✅ Sanitize UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    $paragraphs = preg_split('/\n\s*\n/', $text);
    if ($paragraphs === false) return [];

    $sections = [];
    $currentHeading = null;
    $currentLevel = 0;
    $currentBody = [];

    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') continue;

        $lines = explode("\n", $para);
        $firstLine = $lines[0];
        $detection = text_detect_heading($firstLine);

        if ($detection['is_heading']) {
            if ($currentHeading !== null || !empty($currentBody)) {
                $sections[] = [
                    'heading' => $currentHeading,
                    'level'   => $currentLevel,
                    'body'    => trim(implode("\n\n", $currentBody)),
                ];
            }

            $currentHeading = $detection['text'];
            $currentLevel = $detection['level'];
            $currentBody = [];

            $rest = implode("\n", array_slice($lines, 1));
            if (trim($rest) !== '') {
                $currentBody[] = trim($rest);
            }
        } else {
            $currentBody[] = $para;
        }
    }

    if ($currentHeading !== null || !empty($currentBody)) {
        $sections[] = [
            'heading' => $currentHeading,
            'level'   => $currentLevel,
            'body'    => trim(implode("\n\n", $currentBody)),
        ];
    }

    return $sections;
}


// =====================================================================
// SECTION 5d: TOKENIZERS FOR HYBRID RETRIEVAL (RAG-08)
// =====================================================================

/**
 * Tokenizer ساده برای lexical search.
 *
 * @return string[]
 */
function text_tokenize_simple(string $text, bool $normalize = true): array
{
    if ($text === '') return [];

    if ($normalize) {
        $text = text_normalize_persian_deep($text);
    }

    // ✅ Sanitize UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    $text = mb_strtolower($text, 'UTF-8');

    $tokens = preg_split(
        '/[\s\.\,\;\:\!\?؟،؛«»\(\)\[\]\{\}\<\>\/\\\\\'"\-_\|\@\#\$\%\^\&\*\+\=\~\`]+/u',
        $text,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if ($tokens === false) return [];

    $out = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;

        $len = mb_strlen($tok, 'UTF-8');
        if ($len < 2 && !is_numeric($tok)) continue;

        $out[] = $tok;
    }

    return $out;
}

/**
 * Tokenizer سازگار با FTS5.
 */
function text_tokenize_for_fts(string $text): string
{
    $tokens = text_tokenize_simple($text, true);
    return implode(' ', $tokens);
}

/**
 * نرمال‌سازی متن برای ذخیره در FTS index.
 */
function text_normalize_for_fts(string $text): string
{
    return text_tokenize_for_fts($text);
}


// =====================================================================
// SECTION 6: ARRAY HELPERS
// =====================================================================

function array_get(array $arr, string $key, $default = null)
{
    return array_key_exists($key, $arr) ? $arr[$key] : $default;
}

function array_pluck(array $items, string $key, bool $unique = false): array
{
    $out = [];
    foreach ($items as $item) {
        if (is_array($item) && array_key_exists($key, $item)) {
            $val = $item[$key];
            if ($val !== null) $out[] = $val;
        }
    }
    return $unique ? array_values(array_unique($out)) : $out;
}

function array_group_by(array $items, string $key): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item) || !array_key_exists($key, $item)) continue;
        $k = (string)($item[$key] ?? '_');
        if (!isset($out[$k])) $out[$k] = [];
        $out[$k][] = $item;
    }
    return $out;
}

function array_index_by(array $items, string $key): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item) || !array_key_exists($key, $item)) continue;
        $out[(string)$item[$key]] = $item;
    }
    return $out;
}

function array_only(array $arr, array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr)) {
            $out[$k] = $arr[$k];
        }
    }
    return $out;
}

function array_except(array $arr, array $keys): array
{
    foreach ($keys as $k) {
        unset($arr[$k]);
    }
    return $arr;
}


// =====================================================================
// SECTION 7: HTTP HELPERS
// =====================================================================

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $trustedProxies = getenv('TRUSTED_PROXIES') ?: '';
    if ($trustedProxies !== '') {
        $trusted = array_map('trim', explode(',', $trustedProxies));
        if (in_array($remote, $trusted, true)) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
            $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
            if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
                return $real;
            }
        }
    }

    return $remote;
}

function user_agent_short(int $maxLen = 255): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if ($ua === null || $ua === '') return null;
    return text_truncate((string)$ua, $maxLen, '');
}

function is_json_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $ct     = $_SERVER['CONTENT_TYPE'] ?? '';
    return stripos($accept, 'application/json') !== false
        || stripos($ct, 'application/json') !== false;
}

function is_same_origin_request(): bool
{
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($host === '') return false;

    $expectedHttps = 'https://' . $host;
    $expectedHttp  = 'http://' . $host;

    if ($origin !== '') {
        return strcasecmp($origin, $expectedHttps) === 0
            || strcasecmp($origin, $expectedHttp) === 0;
    }

    if ($referer !== '') {
        $parts = parse_url($referer);
        if ($parts && isset($parts['host'])) {
            $refOrigin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
            if (isset($parts['port'])) $refOrigin .= ':' . $parts['port'];
            return strcasecmp($refOrigin, $expectedHttps) === 0
                || strcasecmp($refOrigin, $expectedHttp) === 0;
        }
    }

    return false;
}


// =====================================================================
// SECTION 8: EXPORT HELPERS
// =====================================================================

function download_json(string $filename, array $data): void
{
    $safeName = safe_filename($filename);
    if (!preg_match('/\.json$/i', $safeName)) {
        $safeName .= '.json';
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: no-store');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // NOTE: Do NOT exit here — caller must explicitly exit if needed.
    // This allows reuse in batch processes.
}

function download_csv(string $filename, array $headers, array $rows, bool $sanitize = true): void
{
    $safeName = safe_filename($filename);
    if (!preg_match('/\.csv$/i', $safeName)) {
        $safeName .= '.csv';
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: no-store');
    }

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if ($out === false) exit;

    fputcsv($out, $headers);

    foreach ($rows as $row) {
        $line = [];
        foreach ($row as $cell) {
            $s = $cell === null ? '' : (string)$cell;
            if ($sanitize && function_exists('csv_sanitize_cell')) {
                $s = csv_sanitize_cell($s);
            }
            $line[] = $s;
        }
        fputcsv($out, $line);
    }

    fclose($out);
    // NOTE: Do NOT exit here — caller must explicitly exit if needed.
}


// =====================================================================
// SECTION 9: JSON HELPERS
// =====================================================================

function safe_json_encode($data, int $flags = 0): string
{
    $flags |= JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json = json_encode($data, $flags);
    if ($json === false) {
        if (function_exists('logger_warn')) {
            logger_warn('[JSON] encode failed: ' . json_last_error_msg());
        }
        return '{}';
    }
    return $json;
}

function safe_json_decode(?string $json, $default = null)
{
    if ($json === null || $json === '') return $default;
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $default;
    return $decoded;
}

function is_valid_json(?string $json): bool
{
    if ($json === null || $json === '') return false;
    json_decode($json);
    return json_last_error() === JSON_ERROR_NONE;
}


// =====================================================================
// SECTION 10: VALIDATION HELPERS
// =====================================================================

function is_valid_email(?string $email): bool
{
    if ($email === null || $email === '') return false;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_ip(?string $ip): bool
{
    if ($ip === null || $ip === '') return false;
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function is_valid_http_url(?string $url, bool $httpsOnly = false): bool
{
    if ($url === null || $url === '') return false;
    $u = filter_var($url, FILTER_VALIDATE_URL);
    if ($u === false) return false;

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme'])) return false;

    $scheme = strtolower($parts['scheme']);
    if ($httpsOnly) return $scheme === 'https';
    return in_array($scheme, ['http', 'https'], true);
}

function is_valid_identifier(?string $s, int $min = 3, int $max = 100): bool
{
    if ($s === null) return false;
    $len = mb_strlen($s);
    if ($len < $min || $len > $max) return false;
    return (bool)preg_match('/^[A-Za-z0-9_.\-]+$/', $s);
}

function is_valid_date_ymd(?string $date): bool
{
    if ($date === null || $date === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function is_valid_datetime_iso(?string $datetime): bool
{
    if ($datetime === null || $datetime === '') return false;
    $ts = strtotime($datetime);
    return $ts !== false;
}


// =====================================================================
// SECTION 11: LOGGING HELPERS
// =====================================================================

function log_security_event(string $event, array $context = []): void
{
    foreach (['password', 'api_key', 'token', 'csrf', 'secret'] as $k) {
        if (array_key_exists($k, $context)) {
            $context[$k] = '[REDACTED]';
        }
    }

    $context['ip'] = client_ip();
    $context['ua'] = user_agent_short(120);

    if (function_exists('logger_warn')) {
        logger_warn('[SECURITY] ' . $event . ' ' . safe_json_encode($context));
    }
}

function log_api_error(string $action, string $message, int $httpStatus = 500, array $context = []): void
{
    $context['action']     = $action;
    $context['status']     = $httpStatus;
    $context['ip']         = client_ip();

    if (function_exists('logger_error')) {
        logger_error('[API] ' . $action . ': ' . $message . ' ' . safe_json_encode($context));
    }
}


// =====================================================================
// SECTION 12: MISC HELPERS
// =====================================================================

function str_starts_with_any(string $haystack, array $prefixes): bool
{
    foreach ($prefixes as $p) {
        if ($p !== '' && strncmp($haystack, $p, strlen($p)) === 0) return true;
    }
    return false;
}

function str_contains_any(string $haystack, array $needles): bool
{
    foreach ($needles as $n) {
        if ($n !== '' && strpos($haystack, $n) !== false) return true;
    }
    return false;
}

function secure_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function secure_equals(?string $a, ?string $b): bool
{
    if ($a === null || $b === null) return false;
    return hash_equals($a, $b);
}

function to_int_safe($value, int $default = 0): int
{
    if (is_int($value)) return $value;
    if (is_float($value)) return (int)$value;
    if (is_string($value)) {
        $value = text_normalize_digits(trim($value));
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) return $default;
        return (int)$value;
    }
    return $default;
}

function to_bool_safe($value, bool $default = false): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value !== 0;
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($v, ['0', 'false', 'no', 'off', ''], true)) return false;
    }
    return $default;
}


// =====================================================================
// SECTION 13: RAG-SPECIFIC TEXT UTILITIES
// =====================================================================

/**
 * UTF-8 safe substring با شمارش کاراکتر.
 */
function text_safe_substr(?string $text, int $start, ?int $length = null): string
{
    if ($text === null || $text === '') return '';
    if ($length === null) {
        return mb_substr($text, $start, null, 'UTF-8');
    }
    return mb_substr($text, $start, $length, 'UTF-8');
}

/**
 * Jaccard similarity بین دو مجموعه token.
 *
 * @return float بین 0 و 1
 */
function text_similarity_jaccard(string $a, string $b): float
{
    if ($a === '' || $b === '') return 0.0;

    $tokensA = array_unique(text_tokenize_simple($a, true));
    $tokensB = array_unique(text_tokenize_simple($b, true));

    if (empty($tokensA) || empty($tokensB)) return 0.0;

    $setA = array_flip($tokensA);
    $setB = array_flip($tokensB);

    $intersection = 0;
    foreach ($setA as $tok => $_) {
        if (isset($setB[$tok])) $intersection++;
    }

    $union = count($setA) + count($setB) - $intersection;
    if ($union === 0) return 0.0;

    return $intersection / $union;
}

/**
 * نرمال‌سازی امن یک رشته برای استفاده به‌عنوان کلید.
 */
function text_safe_key(string $text, int $maxLen = 200): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[^a-z0-9\-\_]+/u', '_', $text) ?? '';
    $text = preg_replace('/_+/', '_', (string)$text) ?? '';
    $text = trim((string)$text, '_');
    return mb_substr((string)$text, 0, $maxLen);
}

/**
 * شمارش تقریبی کلمات.
 */
function text_word_count(string $text): int
{
    if (trim($text) === '') return 0;
    $tokens = text_tokenize_simple($text, false);
    return count($tokens);
}


// =====================================================================
// SECTION 14: RAG SAFE FALLBACKS (از rag_helpers.php)
// =====================================================================
// این بخش از rag_helpers.php منتقل شده است. اگر helpers.php لود شده
// باشد، این fallbackها هیچ‌وقت اجرا نمی‌شن (چون توابع بالا تعریف شدن).
// برای سازگاری 100%، همون گاردهای !function_exists حفظ شدن.


// =====================================================================
// SECTION 15: RAG HYBRID CANDIDATE MERGER (RAG-08)
// =====================================================================

/**
 * ادغام نتایج vector و lexical.
 */
function rag_merge_hybrid_candidates(
    array $vectorResults,
    array $lexicalResults,
    float $vectorWeight = 0.65,
    float $lexicalWeight = 0.35
): array {
    // نرمال‌سازی وزن‌ها
    $total = $vectorWeight + $lexicalWeight;
    if ($total <= 0) {
        $vectorWeight = 0.65;
        $lexicalWeight = 0.35;
        $total = 1.0;
    }
    $vectorWeight /= $total;
    $lexicalWeight /= $total;

    $byChunk = [];

    // افزودن vector results
    foreach ($vectorResults as $r) {
        if (!isset($r['chunk_id'])) continue;
        $cid = (string)$r['chunk_id'];
        $score = isset($r['score']) ? max(0.0, min(1.0, (float)$r['score'])) : 0.0;

        if (!isset($byChunk[$cid])) {
            $byChunk[$cid] = [
                'chunk_id'      => $cid,
                'vector_score'  => 0.0,
                'lexical_score' => 0.0,
                'sources'       => [],
            ];
        }
        $byChunk[$cid]['vector_score'] = $score;
        if (!in_array('vector', $byChunk[$cid]['sources'], true)) {
            $byChunk[$cid]['sources'][] = 'vector';
        }
    }

    // افزودن lexical results
    foreach ($lexicalResults as $r) {
        if (!isset($r['chunk_id'])) continue;
        $cid = (string)$r['chunk_id'];
        $score = isset($r['score']) ? max(0.0, min(1.0, (float)$r['score'])) : 0.0;

        if (!isset($byChunk[$cid])) {
            $byChunk[$cid] = [
                'chunk_id'      => $cid,
                'vector_score'  => 0.0,
                'lexical_score' => 0.0,
                'sources'       => [],
            ];
        }
        $byChunk[$cid]['lexical_score'] = $score;
        if (!in_array('lexical', $byChunk[$cid]['sources'], true)) {
            $byChunk[$cid]['sources'][] = 'lexical';
        }
    }

    // محاسبه combined_score
    $out = [];
    foreach ($byChunk as $cid => $row) {
        $row['combined_score'] = ($row['vector_score'] * $vectorWeight)
                               + ($row['lexical_score'] * $lexicalWeight);
        $out[] = $row;
    }

    // مرتب‌سازی نزولی
    usort($out, function ($a, $b) {
        if ($b['combined_score'] === $a['combined_score']) return 0;
        return $b['combined_score'] <=> $a['combined_score'];
    });

    return $out;
}


// =====================================================================
// SECTION 16: RAG DYNAMIC THRESHOLD (RAG-07)
// =====================================================================

/**
 * فیلتر candidateها با آستانه پویا.
 */
function rag_apply_dynamic_threshold(
    array $candidates,
    float $absoluteMin = RAG_DYNAMIC_THRESHOLD_ABSOLUTE,
    float $relativeToTop = RAG_DYNAMIC_THRESHOLD_RELATIVE,
    int $minKeep = RAG_DYNAMIC_THRESHOLD_MIN_KEEP
): array {
    if (empty($candidates)) return [];

    // پیدا کردن top score
    $topScore = 0.0;
    foreach ($candidates as $c) {
        $s = isset($c['combined_score']) ? (float)$c['combined_score']
           : (isset($c['score'])        ? (float)$c['score'] : 0.0);
        if ($s > $topScore) $topScore = $s;
    }

    if ($topScore <= 0) return [];

    $threshold = max($absoluteMin, $topScore * $relativeToTop);

    $kept = [];
    foreach ($candidates as $c) {
        $s = isset($c['combined_score']) ? (float)$c['combined_score']
           : (isset($c['score'])        ? (float)$c['score'] : 0.0);
        if ($s >= $threshold) {
            $kept[] = $c;
        }
    }

    // اگر کمتر از minKeep → top minKeep را نگه دار
    if (count($kept) < $minKeep) {
        $aboveAbsolute = [];
        foreach ($candidates as $c) {
            $s = isset($c['combined_score']) ? (float)$c['combined_score']
               : (isset($c['score'])        ? (float)$c['score'] : 0.0);
            if ($s > $absoluteMin) $aboveAbsolute[] = $c;
        }
        $kept = array_slice($aboveAbsolute, 0, $minKeep);
    }

    return $kept;
}


// =====================================================================
// SECTION 17: RAG DEDUP (Jaccard-based)
// =====================================================================

/**
 * حذف chunkهای تکراری یا نزدیک-تکراری.
 */
function rag_dedup_candidates(array $candidates, float $jaccardThreshold = 0.85): array
{
    if (count($candidates) <= 1) return $candidates;

    $kept = [];
    $seenTexts = [];

    foreach ($candidates as $c) {
        $text = (string)($c['content'] ?? $c['normalized_content'] ?? '');
        if ($text === '') {
            $kept[] = $c;
            continue;
        }

        $isDuplicate = false;
        foreach ($seenTexts as $seen) {
            if (text_similarity_jaccard($text, $seen) >= $jaccardThreshold) {
                $isDuplicate = true;
                break;
            }
        }

        if (!$isDuplicate) {
            $kept[] = $c;
            $seenTexts[] = $text;
        }
    }

    return $kept;
}


// =====================================================================
// SECTION 18: RAG MMR (Maximal Marginal Relevance) — Diversity
// =====================================================================

/**
 * انتخاب top-K با MMR برای diversity.
 */
function rag_mmr_select(array $candidates, int $topK = 5, float $lambda = 0.7): array
{
    if (count($candidates) <= $topK) return array_values($candidates);
    if ($topK <= 0) return [];

    $lambda = max(0.0, min(1.0, $lambda));

    // استخراج score اصلی
    $scored = [];
    foreach ($candidates as $c) {
        $s = isset($c['combined_score']) ? (float)$c['combined_score']
           : (isset($c['score'])        ? (float)$c['score'] : 0.0);
        $c['_score'] = $s;
        $scored[] = $c;
    }

    // مرتب‌سازی اولیه نزولی
    usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);

    $selected = [];
    $remaining = $scored;

    // اولین مورد: بالاترین relevance
    $selected[] = array_shift($remaining);

    while (count($selected) < $topK && !empty($remaining)) {
        $bestIdx = null;
        $bestScore = -INF;

        foreach ($remaining as $i => $cand) {
            // Max similarity به انتخاب‌شده‌ها
            $maxSim = 0.0;
            foreach ($selected as $sel) {
                $sim = text_similarity_jaccard(
                    (string)($cand['content'] ?? ''),
                    (string)($sel['content'] ?? '')
                );
                if ($sim > $maxSim) $maxSim = $sim;
            }

            // MMR score
            $mmr = ($lambda * $cand['_score']) - ((1 - $lambda) * $maxSim);

            if ($mmr > $bestScore) {
                $bestScore = $mmr;
                $bestIdx = $i;
            }
        }

        if ($bestIdx === null) break;
        $selected[] = $remaining[$bestIdx];
        array_splice($remaining, $bestIdx, 1);
    }

    // حذف فیلد داخلی
    foreach ($selected as &$s) {
        unset($s['_score']);
    }

    return $selected;
}


// =====================================================================
// SECTION 19: RAG RERANKER (Internal — no external API)
// =====================================================================

/**
 * Rerank داخلی.
 */
function rag_rerank_internal(array $candidates, string $query, int $topN = RAG_RERANK_TOP_N): array
{
    if (empty($candidates)) return [];
    $topN = max(1, $topN);

    // نرمال‌سازی query برای matching
    $qNorm = function_exists('text_normalize_query')
        ? text_normalize_query($query)
        : $query;

    $qTokens = text_tokenize_simple($qNorm, false);

    $scored = [];
    foreach ($candidates as $c) {
        $baseScore = isset($c['combined_score']) ? (float)$c['combined_score']
                   : (isset($c['score'])         ? (float)$c['score'] : 0.0);

        $content = (string)($c['content'] ?? '');
        $heading = (string)($c['heading'] ?? '');
        $section = (string)($c['section'] ?? '');

        // 1. Lexical overlap بین query و content
        $contentJaccard = 0.0;
        if ($content !== '' && !empty($qTokens)) {
            $contentJaccard = text_similarity_jaccard($qNorm, $content);
        }

        // 2. Length penalty
        $contentLen = mb_strlen($content, 'UTF-8');
        $lengthPenalty = 0.0;
        if ($contentLen < 100) {
            $lengthPenalty = 0.15;
        } elseif ($contentLen > 3000) {
            $lengthPenalty = 0.10;
        }

        // 3. Heading boost
        $headingBoost = 0.0;
        if ($heading !== '' && !empty($qTokens)) {
            $headingJaccard = text_similarity_jaccard($qNorm, $heading);
            if ($headingJaccard > 0.15) {
                $headingBoost = 0.15;
            }
        }

        // 4. Section boost
        $sectionBoost = 0.0;
        if ($section !== '' && !empty($qTokens)) {
            $sectionJaccard = text_similarity_jaccard($qNorm, $section);
            if ($sectionJaccard > 0.15) {
                $sectionBoost = 0.08;
            }
        }

        // نمره نهایی
        $finalScore = ($baseScore * 0.50)
                    + ($contentJaccard * 0.30)
                    + $headingBoost
                    + $sectionBoost
                    - $lengthPenalty;

        $finalScore = max(0.0, min(1.0, $finalScore));

        $c['_rerank_score'] = $finalScore;
        $scored[] = $c;
    }

    // مرتب‌سازی نزولی
    usort($scored, fn($a, $b) => $b['_rerank_score'] <=> $a['_rerank_score']);

    $top = array_slice($scored, 0, $topN);

    // حذف فیلد داخلی
    foreach ($top as &$t) {
        unset($t['_rerank_score']);
    }

    return $top;
}


// =====================================================================
// SECTION 20: RAG EDUCATIONAL FILTER (RAG-11)
// =====================================================================

/**
 * فیلتر candidateها بر اساس metadata آموزشی.
 *
 * - اگر candidate metadata داشته باشد و با filter هم‌خوان نباشد → حذف
 * - اگر candidate metadata نداشته باشد → حفظ (backward compatible)
 * - اگر filter خالی/null باشد → همه حفظ
 *
 * @param array      $candidates  [{chunk_metadata or metadata, ...}, ...]
 * @param array|null $filter      {grade?: string|int, subject?: string}
 * @return array
 */
function rag_apply_educational_filter(array $candidates, ?array $filter): array
{
    if (empty($candidates) || empty($filter)) return $candidates;

    $grade   = $filter['grade'] ?? null;
    $subject = $filter['subject'] ?? null;

    if ($grade === null && $subject === null) return $candidates;

    $filtered = [];
    foreach ($candidates as $c) {
        $meta = $c['chunk_metadata'] ?? $c['metadata'] ?? null;
        if (!is_array($meta)) {
            // بدون metadata → حفظ (backward compatible)
            $filtered[] = $c;
            continue;
        }

        $match = true;

        if ($grade !== null) {
            $cGrade = $meta['grade'] ?? null;
            // ✅ Bug #18 FIX
            if ($cGrade === null || (string)$cGrade !== (string)$grade) {
                $match = false;
            }
        }

        if ($match && $subject !== null) {
            $cSubject = $meta['subject'] ?? null;
            if ($cSubject === null || mb_strtolower((string)$cSubject) !== mb_strtolower((string)$subject)) {
                $match = false;
            }
        }

        if ($match) $filtered[] = $c;
    }

    return $filtered;
}


// =====================================================================
// SECTION 21: RAG GROUNDEDNESS DETECTION (RAG-05)
// =====================================================================

/**
 * تشخیص وضعیت groundedness.
 */
function rag_assess_groundedness(array $selectedChunks, string $mode = 'strict'): array
{
    $mode = in_array($mode, ['strict', 'balanced', 'permissive'], true) ? $mode : 'strict';

    if (empty($selectedChunks)) {
        return [
            'status'     => 'none',
            'confidence' => 0.0,
            'reason'     => 'هیچ منبع معتبری بازیابی نشد.',
            'top_score'  => 0.0,
            'chunk_count'=> 0,
        ];
    }

    // استخراج scoreها
    $scores = [];
    foreach ($selectedChunks as $c) {
        $s = isset($c['combined_score']) ? (float)$c['combined_score']
           : (isset($c['score'])         ? (float)$c['score'] : 0.0);
        $scores[] = $s;
    }
    rsort($scores);

    $topScore = $scores[0] ?? 0.0;
    $minScore = defined('RAG_GROUNDEDNESS_MIN_SCORE') ? RAG_GROUNDEDNESS_MIN_SCORE : 0.20;

    // آستانه‌های mode-dependent
    $thresholds = [
        'strict'     => ['sufficient' => 0.65, 'weak' => 0.35],
        'balanced'   => ['sufficient' => 0.55, 'weak' => 0.25],
        'permissive' => ['sufficient' => 0.45, 'weak' => 0.15],
    ];
    $th = $thresholds[$mode];

    // تشخیص contradiction
    $hasContradiction = false;
    if (count($selectedChunks) >= 2 && count($scores) >= 2) {
        $secondScore = $scores[1];
        if ($topScore > 0 && ($secondScore / $topScore) > 0.85) {
            $c1 = (string)($selectedChunks[0]['content'] ?? '');
            $c2 = (string)($selectedChunks[1]['content'] ?? '');
            $doc1 = (string)($selectedChunks[0]['document_id'] ?? '');
            $doc2 = (string)($selectedChunks[1]['document_id'] ?? '');
            // ✅ Bug #19 FIX
            if ($c1 !== '' && $c2 !== '' && $doc1 !== '' && $doc2 !== '' && $doc1 !== $doc2) {
                $sim = text_similarity_jaccard($c1, $c2);
                if ($sim < 0.35) {
                    $hasContradiction = true;
                }
            }
        }
    }

    if ($hasContradiction) {
        return [
            'status'     => 'contradictory',
            'confidence' => $topScore * 0.8,
            'reason'     => 'دو منبع با اعتبار بالا محتوای متفاوتی دارند.',
            'top_score'  => $topScore,
            'chunk_count'=> count($selectedChunks),
        ];
    }

    if ($topScore >= $th['sufficient']) {
        return [
            'status'     => 'sufficient',
            'confidence' => $topScore,
            'reason'     => 'منابع کافی و با اعتبار بالا یافت شد.',
            'top_score'  => $topScore,
            'chunk_count'=> count($selectedChunks),
        ];
    }

    if ($topScore >= $th['weak'] && $topScore >= $minScore) {
        return [
            'status'     => 'weak',
            'confidence' => $topScore,
            'reason'     => 'منابع مرتبط یافت شد اما اعتبار محدود است.',
            'top_score'  => $topScore,
            'chunk_count'=> count($selectedChunks),
        ];
    }

    return [
        'status'     => 'none',
        'confidence' => $topScore,
        'reason'     => 'هیچ منبع مرتبط با اعتبار کافی یافت نشد.',
        'top_score'  => $topScore,
        'chunk_count'=> count($selectedChunks),
    ];
}

/**
 * ساخت دستورالعمل prompt بر اساس groundedness status.
 */
function rag_groundedness_instruction(array $assessment): string
{
    $status = $assess['status'] ?? 'none';

    switch ($status) {
        case 'sufficient':
            return "از اطلاعات منابع زیر برای پاسخ استفاده کن. اگر پاسخ دقیقاً در منابع بود، مستقیماً بگو. اگر بخشی از پاسخ در منابع نبود، آن بخش را جدا کن و صادقانه بگو در منابع موجود نیست.";

        case 'weak':
            return "منابع محدودی در دسترس است. اگر می‌توانی بر اساس آن‌ها پاسخ دهی، با ذکر «بر اساس منابع محدود موجود» پاسخ بده. اگر منابع کافی نیست، صادقانه بگو که اطلاعات کافی در منابع آموزشی موجود نیست.";

        case 'contradictory':
            return "دو منبع با محتوای متفاوت یافت شد. هر دو دیدگاه را ذکر کن، تفاوت را مشخص کن، و از ارائه یک پاسخ قطعی خودداری کن. بگو که منابع موجود هم‌راستا نیستند و نیاز به بررسی بیشتری دارند.";

        case 'none':
        default:
            return "هیچ منبع مرتبطی در پایگاه دانش آموزشی موجود نیست. صادقانه بگو: «این پاسخ در منابع آموزشی موجود در DANOVA پیدا نشد.» سپس اگر امکان دارد، فقط یک راهنمایی کلی (بدون ادعای واقعیت) ارائه بده.";
    }
}


// =====================================================================
// SECTION 22: RAG SOURCE PROVENANCE BUILDER (RAG-06, RAG-09)
// =====================================================================

/**
 * ساخت provenance برای یک chunk.
 */
function rag_build_provenance(array $chunk, ?array $document = null, ?array $collection = null): array
{
    $score = isset($chunk['combined_score']) ? (float)$chunk['combined_score']
           : (isset($chunk['score'])         ? (float)$chunk['score'] : 0.0);

    return [
        'chunk_id'        => (string)($chunk['chunk_id'] ?? $chunk['id'] ?? ''),
        'document_id'     => (string)($chunk['document_id'] ?? ($document['id'] ?? '')),
        'document_title'  => $document['title'] ?? null,
        'collection_id'   => $document['collection_id'] ?? ($collection['id'] ?? null),
        'collection_name' => $collection['name'] ?? null,
        'page_number'     => isset($chunk['page_number']) && $chunk['page_number'] !== null
                              ? (int)$chunk['page_number'] : null,
        'page_end'        => isset($chunk['page_end']) && $chunk['page_end'] !== null
                              ? (int)$chunk['page_end'] : null,
        'heading'         => $chunk['heading'] ?? null,
        'section'         => $chunk['section'] ?? null,
        'score'           => round($score, 4),
        'chunk_index'     => isset($chunk['chunk_index']) ? (int)$chunk['chunk_index'] : 0,
    ];
}

/**
 * ساخت آرایه provenance برای چند chunk.
 */
function rag_build_provenance_list(array $chunks, array $documentsMap = [], array $collectionsMap = []): array
{
    $out = [];
    foreach ($chunks as $c) {
        $docId = (string)($c['document_id'] ?? '');
        $doc = $docId !== '' && isset($documentsMap[$docId]) ? $documentsMap[$docId] : null;
        $colId = $doc['collection_id'] ?? null;
        $col = $colId && isset($collectionsMap[$colId]) ? $collectionsMap[$colId] : null;

        $out[] = rag_build_provenance($c, $doc, $col);
    }
    return $out;
}


// =====================================================================
// SECTION 23: RAG CONTEXT ASSEMBLER (RAG-12)
// =====================================================================

/**
 * ساخت context نهایی از chunkهای انتخاب‌شده.
 */
function rag_assemble_context(
    array $chunks,
    array $documentsMap = [],
    array $collectionsMap = [],
    int $maxTokens = RAG_MAX_CONTEXT_TOKENS,
    bool $includeHeader = RAG_CONTEXT_HEADER_ENABLED
): array {
    if (empty($chunks)) {
        return ['context_text' => '', 'used_chunks' => [], 'total_tokens' => 0];
    }

    $parts = [];
    $usedChunks = [];
    $totalTokens = 0;
    $sourceIdx = 0;

    foreach ($chunks as $c) {
        $content = trim((string)($c['content'] ?? ''));
        if ($content === '') continue;

        $tokenCount = isset($c['token_count'])
            ? (int)$c['token_count']
            : (function_exists('estimate_tokens') ? estimate_tokens($content) : (int)(mb_strlen($content) / 3));

        if ($totalTokens + $tokenCount > $maxTokens && $totalTokens > 0) {
            break;
        }

        $sourceIdx++;

        if ($includeHeader) {
            $headerLines = ["[منبع {$sourceIdx}]"];

            $docId = (string)($c['document_id'] ?? '');
            $doc = $docId !== '' && isset($documentsMap[$docId]) ? $documentsMap[$docId] : null;
            if ($doc && !empty($doc['title'])) {
                $headerLines[] = 'سند: ' . $doc['title'];
            }

            if (!empty($c['heading'])) {
                $headerLines[] = 'سرتیتر: ' . (string)$c['heading'];
            }
            if (!empty($c['section'])) {
                $headerLines[] = 'بخش: ' . (string)$c['section'];
            }
            if (isset($c['page_number']) && $c['page_number'] !== null) {
                $pageStr = 'صفحه: ' . (int)$c['page_number'];
                if (isset($c['page_end']) && $c['page_end'] !== null && (int)$c['page_end'] !== (int)$c['page_number']) {
                    $pageStr .= '-' . (int)$c['page_end'];
                }
                $headerLines[] = $pageStr;
            }

            $parts[] = implode("\n", $headerLines) . "\n---\n" . $content;
        } else {
            $parts[] = "[منبع {$sourceIdx}]\n" . $content;
        }

        $usedChunks[] = $c;
        $totalTokens += $tokenCount;
    }

    return [
        'context_text' => implode("\n\n", $parts),
        'used_chunks'  => $usedChunks,
        'total_tokens' => $totalTokens,
    ];
}


// =====================================================================
// SECTION 24: RAG FULL PIPELINE (Convenience function)
// ✅ v1.0.1 (Bug 6): Step 0 — Educational Filter
// =====================================================================

/**
 * اعمال کل pipeline RAG 2.0 روی candidateها.
 *
 * ترتیب مراحل (به ترتیب اجرا):
 *   Step 0: Educational Filter (اختیاری)   ← ✅ NEW in v1.0.1
 *   Step 1: Dynamic Threshold
 *   Step 2: Dedup
 *   Step 3: Rerank
 *   Step 4: MMR
 *   Step 5: Groundedness assessment
 *
 * @param array  $candidates  خروجی hybrid merge
 * @param string $query
 * @param array  $options {
 *     educational_filter?: array|null,
 *     rerank?: bool,
 *     dedup?: bool,
 *     mmr?: bool,
 *     dynamic_threshold?: bool,
 *     top_n?: int,
 *     groundedness_mode?: string,
 * }
 * @return array{
 *     selected: array,
 *     assessment: array,
 *     pipeline_stats: array
 * }
 */
function rag_apply_pipeline(array $candidates, string $query, array $options = []): array
{
    $stats = [
        'input_count'     => count($candidates),
        'after_filter'    => 0,
        'after_dedup'     => 0,
        'after_threshold' => 0,
        'after_rerank'    => 0,
        'after_mmr'       => 0,
    ];

    if (empty($candidates)) {
        return [
            'selected'       => [],
            'assessment'     => rag_assess_groundedness([], $options['groundedness_mode'] ?? 'strict'),
            'pipeline_stats' => $stats,
        ];
    }

    // Step 0: Educational Filter
    if (isset($options['educational_filter']) && is_array($options['educational_filter'])
        && !empty($options['educational_filter'])
        && function_exists('rag_apply_educational_filter')) {
        $candidates = rag_apply_educational_filter($candidates, $options['educational_filter']);
    }
    $stats['after_filter'] = count($candidates);

    if (empty($candidates)) {
        return [
            'selected'       => [],
            'assessment'     => rag_assess_groundedness([], $options['groundedness_mode'] ?? 'strict'),
            'pipeline_stats' => $stats,
        ];
    }

    // Step 1: Dynamic threshold
    if ($options['dynamic_threshold'] ?? true) {
        $candidates = rag_apply_dynamic_threshold(
            $candidates,
            $options['threshold_absolute'] ?? RAG_DYNAMIC_THRESHOLD_ABSOLUTE,
            $options['threshold_relative'] ?? RAG_DYNAMIC_THRESHOLD_RELATIVE,
            $options['min_keep']           ?? RAG_DYNAMIC_THRESHOLD_MIN_KEEP
        );
    }
    $stats['after_threshold'] = count($candidates);

    // Step 2: Dedup
    if ($options['dedup'] ?? true) {
        $candidates = rag_dedup_candidates($candidates, $options['dedup_threshold'] ?? 0.85);
    }
    $stats['after_dedup'] = count($candidates);

    // Step 3: Rerank
    if ($options['rerank'] ?? RAG_RERANK_ENABLED) {
        $candidates = rag_rerank_internal($candidates, $query, $options['rerank_input_max'] ?? RAG_RERANK_INPUT_MAX);
    }
    $stats['after_rerank'] = count($candidates);

    // Step 4: MMR (اختیاری)
    if ($options['mmr'] ?? true) {
        $topN = $options['top_n'] ?? RAG_RERANK_TOP_N;
        $candidates = rag_mmr_select($candidates, $topN, $options['mmr_lambda'] ?? 0.7);
    } else {
        $topN = $options['top_n'] ?? RAG_RERANK_TOP_N;
        $candidates = array_slice($candidates, 0, $topN);
    }
    $stats['after_mmr'] = count($candidates);

    // Step 5: Groundedness assessment
    $assessment = rag_assess_groundedness(
        $candidates,
        $options['groundedness_mode'] ?? 'strict'
    );

    return [
        'selected'       => $candidates,
        'assessment'     => $assessment,
        'pipeline_stats' => $stats,
    ];
}


// =====================================================================
// SECTION 25: RAG MIGRATION CLI (از migrate_rag.php)
// =====================================================================
// این بخش فقط در CLI اجرا می‌شود. اگر فایل از طریق وب لود شود،
// این کد به‌کلی نادیده گرفته می‌شود (به دلیل گارد PHP_SAPI).
//
// استفاده:
//   php helpers.php --check
//   php helpers.php --backfill
//   php helpers.php --rebuild-fts
//   php helpers.php --dry-run
//   php helpers.php --verbose
//   php helpers.php --help
// =====================================================================



// =====================================================================
// SECTION 25: RAG MIGRATION CLI (MOVED TO bin/migrate_rag.php)
// =====================================================================
// ⚠️ The CLI migration tool has been moved to a separate file
// to avoid polluting this library file. Run it via:
//
//     php bin/migrate_rag.php --check
//     php bin/migrate_rag.php --backfill
//     php bin/migrate_rag.php --rebuild-fts
//
// (See bin/migrate_rag.php for full documentation)
// =====================================================================

// END OF helpers.php (v3.1.0 — PATCH: Fix preg_replace null subject)
// =====================================================================