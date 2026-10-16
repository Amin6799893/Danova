<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: services.php
 * Version: 2.4.2 (FULL FIX — httpPostJson + quota_version compatibility)
 * Purpose: AI + Embedding + RAG + Text Extraction + Video services
 * ---------------------------------------------------------------------
 * Changelog v2.4.2 (CRITICAL FIXES):
 *   FIXED:
 *     - [CRITICAL] Added httpPostJson() method to AIService class.
 *       Previously callGapGPT()/callOpenAI()/etc called $this->httpPostJson()
 *       which did NOT exist → Fatal error: Call to undefined method
 *       → Chat was completely broken with HTTP 500.
 *     - [CRITICAL] reserve_usage_slot() no longer requires the
 *       'quota_version' column on students table (which may not exist
 *       on MySQL databases migrated from SQLite without it).
 *       Uses a fallback mechanism if column is missing.
 *   KEPT:
 *     - All logic of v2.4.1 (GapGPT Embedding Provider).
 *   COMPAT: 100% backward compatible.
 * ---------------------------------------------------------------------
 * Changelog v2.4.1 (CRITICAL FIX — RAG Embedding Provider):
 *   FIXED:
 *     - EmbeddingService now recognizes 'gapgpt' as a valid semantic
 *       embedding provider.
 * ---------------------------------------------------------------------
 * Changelog v2.4.0 (BUG FIX — Bug #7: total_tokens):
 *   FIXED:
 *     - record_ai_usage() now accepts optional $totalTokens parameter.
 * ---------------------------------------------------------------------
 * Changelog v2.3.1 (BUG FIX — preg_replace null subject):
 *   FIXED: Sanitize UTF-8 in TextService::clean() and RAG indexing.
 * ---------------------------------------------------------------------
 * Changelog v2.3.0 (Smalot PDFParser Support):
 *   ADDED: extractPdfWithSmalot() method.
 * ---------------------------------------------------------------------
 * Changelog v2.2.2 (FINAL FIX — Cannot redeclare):
 *   REMOVED: All danova_* function definitions (they're in config.php).
 * =====================================================================
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/models.php';

// ✅ RAG 2.0 helpers (optional — fallback if not loaded)
if (file_exists(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}
if (file_exists(__DIR__ . '/rag_helpers.php')) {
    require_once __DIR__ . '/rag_helpers.php';
}


// =====================================================================
// HELPER: Safe UTF-8 sanitizer
// =====================================================================

if (!function_exists('_services_safe_utf8')) {
    function _services_safe_utf8($text): string
    {
        if ($text === null) return '';
        if (!is_string($text)) {
            $text = (string)$text;
        }
        if ($text === '') return '';

        if (!mb_check_encoding($text, 'UTF-8')) {
            if (function_exists('logger_warn')) {
                logger_warn('[UTF8] Invalid UTF-8 detected — sanitizing');
            }
            $sanitized = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (!is_string($sanitized)) {
                $sanitized = preg_replace('/[\x80-\xFF]/', '', $text);
                if ($sanitized === null) {
                    $sanitized = '';
                }
            }
            return (string)$sanitized;
        }

        return $text;
    }
}


// =====================================================================
// TEXT SERVICE
// =====================================================================

class TextService
{
    public static function extractFromFile(string $path, ?string $mime = null): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("فایل پیدا نشد: $path");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ['txt', 'md', 'csv'], true)) {
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('خواندن فایل متنی ناموفق بود.');
            }
            $content = _services_safe_utf8((string)$content);

            return [
                'text'       => $content,
                'pages'      => [
                    ['page_number' => 1, 'text' => $content],
                ],
                'extractor'  => 'plain',
                'page_count' => 1,
            ];
        }

        if ($ext === 'pdf') {
            return self::extractPdfStructured($path);
        }

        if ($ext === 'docx') {
            $text = self::extractDocx($path);
            $text = _services_safe_utf8($text);

            return [
                'text'       => $text,
                'pages'      => [
                    ['page_number' => 1, 'text' => $text],
                ],
                'extractor'  => 'docx',
                'page_count' => 1,
            ];
        }

        throw new RuntimeException("فرمت فایل پشتیبانی نمی‌شود: .$ext");
    }

    private static function extractPdfStructured(string $path): array
    {
        // ۱. Smalot PDFParser
        if (class_exists('\\Smalot\\PdfParser\\Parser')) {
            try {
                $result = self::extractPdfWithSmalot($path);
                if (trim((string)$result['text']) !== '') {
                    logger_info(
                        '[PDF] Extracted via Smalot PDFParser: '
                        . basename($path)
                        . ' (pages=' . $result['page_count']
                        . ', tokens=' . estimate_tokens($result['text']) . ')'
                    );
                    return $result;
                }
            } catch (Throwable $e) {
                logger_warn('[PDF/Smalot] Failed: ' . $e->getMessage()
                    . ' — trying pdftotext');
            }
        } else {
            logger_warn('[PDF] Smalot PDFParser class not found — checking pdftotext');
        }

        // ۲. pdftotext
        $pageCount = self::_detectPdfPageCount($path);

        if (function_exists('danova_command_exists') && danova_command_exists('pdftotext') && $pageCount > 0) {
            $pages = self::_extractPdfViaPdftotextPerPage($path, $pageCount);

            if (!empty($pages)) {
                foreach ($pages as $i => $p) {
                    $pages[$i]['text'] = _services_safe_utf8((string)($p['text'] ?? ''));
                }
            }

            if (!empty($pages)) {
                $fullText = '';
                foreach ($pages as $p) {
                    $fullText .= ($fullText !== '' ? "\n\n" : '') . $p['text'];
                }
                if (trim($fullText) !== '') {
                    logger_info(
                        '[PDF] Extracted via pdftotext: '
                        . basename($path)
                        . ' (pages=' . count($pages) . ')'
                    );
                    return [
                        'text'       => $fullText,
                        'pages'      => $pages,
                        'extractor'  => 'pdftotext',
                        'page_count' => count($pages),
                    ];
                }
            }
        }

        // ۳. Fallback داخلی
        logger_warn('[PDF] Using internal parser (limited to 5MB, basic PDFs only)');
        $text = self::_extractPdfInternal($path);
        $text = _services_safe_utf8($text);

        return [
            'text'       => $text,
            'pages'      => [
                ['page_number' => 1, 'text' => $text],
            ],
            'extractor'  => 'internal',
            'page_count' => 1,
        ];
    }

    private static function extractPdfWithSmalot(string $path): array
    {
        if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
            throw new RuntimeException('Smalot PDFParser library not loaded.');
        }

        $parser = null;
        $pdf    = null;

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($path);
        } catch (Throwable $e) {
            throw new RuntimeException('smalot_parse_failed: ' . $e->getMessage(), 0, $e);
        }

        $fullText = '';
        try {
            $fullText = (string)$pdf->getText();
            $fullText = _services_safe_utf8($fullText);
        } catch (Throwable $e) {
            logger_warn('[PDF/Smalot] getText() failed: ' . $e->getMessage());
        }

        $pages = [];
        try {
            $pdfPages = $pdf->getPages();
            $pageNum  = 0;

            foreach ($pdfPages as $page) {
                $pageNum++;
                try {
                    $pageText = (string)$page->getText();
                    $pageText = _services_safe_utf8($pageText);
                } catch (Throwable $e) {
                    logger_warn('[PDF/Smalot] page ' . $pageNum
                        . ' getText failed: ' . $e->getMessage());
                    $pageText = '';
                }

                if (trim($pageText) !== '') {
                    $pages[] = [
                        'page_number' => $pageNum,
                        'text'        => $pageText,
                    ];
                }
            }
        } catch (Throwable $e) {
            logger_warn('[PDF/Smalot] per-page extraction failed: '
                . $e->getMessage());
        }

        if (empty($pages) && trim($fullText) !== '') {
            $pages = [[
                'page_number' => 1,
                'text'        => $fullText,
            ]];
        }

        if (trim($fullText) === '' && !empty($pages)) {
            $parts = [];
            foreach ($pages as $p) {
                $parts[] = $p['text'];
            }
            $fullText = implode("\n\n", $parts);
        }

        if (trim($fullText) === '' && empty($pages)) {
            throw new RuntimeException('empty_pdf_text');
        }

        unset($pdf, $parser, $pdfPages);

        return [
            'text'       => $fullText,
            'pages'      => $pages,
            'extractor'  => 'smalot_pdfparser',
            'page_count' => count($pages),
        ];
    }

    private static function _detectPdfPageCount(string $path): int
    {
        if (function_exists('danova_command_exists') && danova_command_exists('pdfinfo')) {
            $cmd = 'pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null';
            $out = function_exists('danova_safe_shell_exec')
                ? danova_safe_shell_exec($cmd)
                : null;
            if (is_string($out) && preg_match('/^Pages:\s*(\d+)/m', $out, $m)) {
                return (int)$m[1];
            }
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            logger_warn('[PDF] Cannot read file for page count: ' . basename($path));
            return 0;
        }

        $maxScan = 20 * 1024 * 1024;
        if (strlen($raw) > $maxScan) {
            $raw = substr($raw, 0, $maxScan);
        }

        $raw = _services_safe_utf8($raw);

        $count = preg_match_all('#/Type\s*/Page(?!s)#', $raw);
        if ($count === false) $count = 0;

        if ($count > 0) {
            return (int)$count;
        }

        $matched = @preg_match_all('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#s', $raw, $matches);
        if ($matched !== false && $matched > 0 && isset($matches[1]) && is_array($matches[1])) {
            $total = 0;
            foreach ($matches[1] as $c) {
                $total += (int)$c;
            }
            if ($total > 0) {
                return $total;
            }
        } elseif ($matched === false) {
            logger_warn('[PDF] preg_match_all failed in _detectPdfPageCount: ' . preg_last_error_msg());
        }

        logger_warn('[PDF] Could not detect page count for: ' . basename($path));
        return 0;
    }

    private static function _extractPdfViaPdftotextPerPage(string $path, int $pageCount): array
    {
        $pages = [];
        $maxPages = min($pageCount, 200);

        for ($i = 1; $i <= $maxPages; $i++) {
            $tmp = tempnam(sys_get_temp_dir(), 'pdf_p_');
            if (!$tmp) continue;

            $cmd = sprintf(
                'pdftotext -layout -f %d -l %d %s %s 2>/dev/null',
                $i, $i,
                escapeshellarg($path),
                escapeshellarg($tmp)
            );

            if (function_exists('danova_safe_shell_exec')) {
                danova_safe_shell_exec($cmd);
            }

            $text = @file_get_contents($tmp);
            @unlink($tmp);

            if ($text !== false && trim((string)$text) !== '') {
                $text = _services_safe_utf8((string)$text);
                $pages[] = [
                    'page_number' => $i,
                    'text'        => $text,
                ];
            }
        }

        return $pages;
    }

    private static function _extractPdfInternal(string $path): string
    {
        $fileSize = @filesize($path);
        if ($fileSize === false || $fileSize <= 0) {
            throw new RuntimeException('استخراج متن PDF ناموفق بود (حجم نامعتبر).');
        }

        $maxRegexBytes = 5 * 1024 * 1024;
        if ($fileSize > $maxRegexBytes) {
            throw new RuntimeException(
                'فایل PDF بزرگ‌تر از حد مجاز برای پردازش داخلی است. '
                . 'لطفاً کتابخانه smalot/pdfparser را نصب کنید '
                . 'یا pdftotext را روی سرور فعال نمایید.'
            );
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('استخراج متن PDF ناموفق بود.');
        }

        if (strlen($raw) > $maxRegexBytes) {
            $raw = substr($raw, 0, $maxRegexBytes);
        }

        $text = '';

        $pattern = '/stream\s*(.{1,500000}?)\s*endstream/s';
        $result = @preg_match_all($pattern, $raw, $matches);

        if ($result === false || preg_last_error() !== PREG_NO_ERROR) {
            logger_warn('[PDF] regex error: ' . preg_last_error_msg());
            throw new RuntimeException(
                'استخراج متن PDF ناموفق بود. لطفاً smalot/pdfparser را نصب کنید.'
            );
        }

        if ($result > 0 && isset($matches[1])) {
            foreach ($matches[1] as $stream) {
                $stream = trim((string)$stream);
                if ($stream === '') continue;

                $decoded = @gzuncompress($stream);
                if ($decoded === false) $decoded = @gzinflate($stream);
                if ($decoded === false) continue;

                if (preg_match_all('/\((.*?)\)/s', $decoded, $tm)) {
                    foreach ($tm[1] as $chunk) {
                        $text .= stripcslashes($chunk) . ' ';
                    }
                }
            }
        }

        if (trim($text) === '') {
            throw new RuntimeException(
                'استخراج متن PDF ناموفق بود. لطفاً smalot/pdfparser را نصب کنید.'
            );
        }

        return _services_safe_utf8($text);
    }

    private static function extractDocx(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('افزونه ZipArchive در PHP فعال نیست.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('باز کردن فایل DOCX ناموفق بود.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xml) {
            throw new RuntimeException('محتوای DOCX پیدا نشد.');
        }

        $xml = _services_safe_utf8((string)$xml);

        $tmp = preg_replace('/<\/w:p>/', "\n", $xml);
        if ($tmp !== null) $xml = $tmp;

        $tmp = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
        if ($tmp !== null) $xml = $tmp;

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        if (trim($text) === '') {
            throw new RuntimeException('هیچ متنی از DOCX استخراج نشد.');
        }

        return $text;
    }

    public static function clean(string $text): string
    {
        if ($text === '') return '';

        $text = _services_safe_utf8($text);

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $tmp = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
        if ($tmp !== null) $text = $tmp;

        $tmp = preg_replace('/\n{3,}/', "\n\n", $text);
        if ($tmp !== null) $text = $tmp;

        $tmp = preg_replace('/[ \t]{2,}/', ' ', $text);
        if ($tmp !== null) $text = $tmp;

        return trim($text);
    }

    public static function chunk(
        string $text,
        int $chunkSize = RAG_CHUNK_SIZE,
        int $overlap = RAG_CHUNK_OVERLAP
    ): array {
        $chunkSize = max(100, $chunkSize);
        $overlap   = max(0, min($overlap, intdiv($chunkSize, 2)));

        $text = self::clean($text);
        if ($text === '') return [];

        if (RAG_HEADING_AWARE_CHUNKING && function_exists('text_split_by_headings')) {
            return self::_chunkHeadingAware($text, $chunkSize, $overlap);
        }

        return self::_chunkParagraphBased($text, $chunkSize, $overlap);
    }

    public static function chunkStructured(
        array $pages,
        int $chunkSize = RAG_CHUNK_SIZE,
        int $overlap = RAG_CHUNK_OVERLAP
    ): array {
        if (empty($pages)) return [];

        $chunkSize = max(100, $chunkSize);
        $overlap   = max(0, min($overlap, intdiv($chunkSize, 2)));

        $allChunks = [];
        $globalIndex = 0;
        $lastHeading = null;
        $lastSection = null;

        foreach ($pages as $page) {
            $pageNum  = isset($page['page_number']) ? (int)$page['page_number'] : null;
            $pageText = self::clean(_services_safe_utf8((string)($page['text'] ?? '')));
            if ($pageText === '') continue;

            $blocks = [];
            if (RAG_HEADING_AWARE_CHUNKING && function_exists('text_split_by_headings')) {
                $sections = text_split_by_headings($pageText);
                foreach ($sections as $sec) {
                    $blocks[] = [
                        'heading' => $sec['heading'],
                        'level'   => $sec['level'],
                        'body'    => $sec['body'],
                    ];
                }
            } else {
                $blocks[] = ['heading' => null, 'level' => 0, 'body' => $pageText];
            }

            foreach ($blocks as $block) {
                $heading = $block['heading'] ?? null;
                $body    = trim((string)($block['body'] ?? ''));

                if ($heading !== null) {
                    $lastHeading = $heading;
                }

                if ($body === '') {
                    if ($heading !== null && mb_strlen($heading, 'UTF-8') >= 20) {
                        $allChunks[] = [
                            'content'     => $heading,
                            'index'       => $globalIndex++,
                            'token_count' => estimate_tokens($heading),
                            'metadata'    => ['heading_only' => true],
                            'page_number' => $pageNum,
                            'page_end'    => $pageNum,
                            'heading'     => $heading,
                            'section'     => $lastSection,
                        ];
                    }
                    continue;
                }

                $fullContent = $heading !== null
                    ? $heading . "\n\n" . $body
                    : $body;

                $subChunks = self::_splitBody($fullContent, $chunkSize, $overlap);

                foreach ($subChunks as $sub) {
                    $allChunks[] = [
                        'content'     => $sub,
                        'index'       => $globalIndex++,
                        'token_count' => estimate_tokens($sub),
                        'metadata'    => [],
                        'page_number' => $pageNum,
                        'page_end'    => $pageNum,
                        'heading'     => $heading ?? $lastHeading,
                        'section'     => $lastSection,
                    ];
                }
            }
        }

        return $allChunks;
    }

    private static function _chunkHeadingAware(string $text, int $chunkSize, int $overlap): array
    {
        $sections = text_split_by_headings($text);
        if (empty($sections)) {
            return self::_chunkParagraphBased($text, $chunkSize, $overlap);
        }

        $chunks = [];
        $idx = 0;

        foreach ($sections as $sec) {
            $heading = $sec['heading'] ?? null;
            $body    = trim((string)($sec['body'] ?? ''));

            if ($body === '') {
                if ($heading !== null && mb_strlen($heading, 'UTF-8') >= 20) {
                    $chunks[] = [
                        'content'     => $heading,
                        'index'       => $idx++,
                        'token_count' => estimate_tokens($heading),
                        'metadata'    => ['heading_only' => true],
                        'heading'     => $heading,
                    ];
                }
                continue;
            }

            $full = $heading !== null ? ($heading . "\n\n" . $body) : $body;
            $subs = self::_splitBody($full, $chunkSize, $overlap);

            foreach ($subs as $sub) {
                $chunks[] = [
                    'content'     => $sub,
                    'index'       => $idx++,
                    'token_count' => estimate_tokens($sub),
                    'metadata'    => [],
                    'heading'     => $heading,
                ];
            }
        }

        return $chunks;
    }

    private static function _chunkParagraphBased(string $text, int $chunkSize, int $overlap): array
    {
        $paragraphs = array_filter(
            array_map('trim', preg_split('/\n{2,}/', $text) ?: []),
            function ($p) { return $p !== ''; }
        );

        $chunks = [];
        $current = '';
        $idx = 0;

        $flush = function (string $t) use (&$chunks, &$idx) {
            $t = trim($t);
            if ($t === '') return;
            $chunks[] = [
                'content'     => $t,
                'index'       => $idx++,
                'token_count' => estimate_tokens($t),
                'metadata'    => [],
            ];
        };

        foreach ($paragraphs as $para) {
            if (mb_strlen($current) + mb_strlen($para) + 2 <= $chunkSize) {
                $current = $current === '' ? $para : ($current . "\n\n" . $para);
                continue;
            }

            $flush($current);
            $tail = $overlap > 0 && $current !== ''
                ? mb_substr($current, -$overlap)
                : '';

            if (mb_strlen($para) > $chunkSize) {
                $sentences = preg_split('/(?<=[.!?؟])\s+/u', $para) ?: [$para];
                $buf = $tail;
                foreach ($sentences as $s) {
                    if (mb_strlen($buf) + mb_strlen($s) + 1 <= $chunkSize) {
                        $buf = $buf === '' ? $s : ($buf . ' ' . $s);
                    } else {
                        $flush($buf);
                        $buf = $s;
                    }
                }
                $current = $buf;
            } else {
                $current = $tail === '' ? $para : ($tail . "\n\n" . $para);
            }
        }

        $flush($current);
        return $chunks;
    }

    private static function _splitBody(string $text, int $chunkSize, int $overlap): array
    {
        $text = trim($text);
        if ($text === '') return [];

        if (mb_strlen($text, 'UTF-8') <= $chunkSize) {
            return [$text];
        }

        $paragraphs = array_filter(
            array_map('trim', preg_split('/\n{2,}/', $text) ?: []),
            function ($p) { return $p !== ''; }
        );

        if (count($paragraphs) <= 1) {
            return self::_splitBySentence($text, $chunkSize, $overlap);
        }

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') continue;

            if (mb_strlen($para, 'UTF-8') > $chunkSize) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                $subs = self::_splitBySentence($para, $chunkSize, $overlap);
                foreach ($subs as $sub) {
                    $chunks[] = $sub;
                }
                continue;
            }

            $bufferLen = mb_strlen($buffer, 'UTF-8');
            $paraLen   = mb_strlen($para, 'UTF-8');

            if ($buffer === '') {
                $buffer = $para;
            } elseif ($bufferLen + 2 + $paraLen <= $chunkSize) {
                $buffer .= "\n\n" . $para;
            } else {
                $chunks[] = $buffer;
                $tail = '';
                if ($overlap > 0) {
                    $tail = self::_safeTailOverlap($buffer, $overlap);
                }
                $buffer = $tail === ''
                    ? $para
                    : ($tail . "\n\n" . $para);
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    private static function _splitBySentence(string $text, int $chunkSize, int $overlap): array
    {
        $sentences = preg_split('/(?<=[.!?؟])\s+/u', $text);
        if ($sentences === false || empty($sentences)) {
            return self::_hardSplitByWord($text, $chunkSize, $overlap);
        }

        $chunks = [];
        $buffer = '';

        foreach ($sentences as $sent) {
            $sent = trim($sent);
            if ($sent === '') continue;

            if (mb_strlen($sent, 'UTF-8') > $chunkSize) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }
                $subs = self::_hardSplitByWord($sent, $chunkSize, $overlap);
                foreach ($subs as $sub) {
                    $chunks[] = $sub;
                }
                continue;
            }

            $bufferLen = mb_strlen($buffer, 'UTF-8');
            $sentLen   = mb_strlen($sent, 'UTF-8');

            if ($buffer === '') {
                $buffer = $sent;
            } elseif ($bufferLen + 1 + $sentLen <= $chunkSize) {
                $buffer .= ' ' . $sent;
            } else {
                $chunks[] = $buffer;
                $tail = $overlap > 0
                    ? self::_safeTailOverlap($buffer, $overlap)
                    : '';
                $buffer = $tail === ''
                    ? $sent
                    : ($tail . ' ' . $sent);
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    private static function _hardSplitByWord(string $text, int $chunkSize, int $overlap): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || empty($words)) return [$text];

        $chunks = [];
        $buffer = '';

        foreach ($words as $w) {
            $bufferLen = mb_strlen($buffer, 'UTF-8');
            $wLen      = mb_strlen($w, 'UTF-8');

            if ($buffer === '') {
                $buffer = $w;
            } elseif ($bufferLen + 1 + $wLen <= $chunkSize) {
                $buffer .= ' ' . $w;
            } else {
                $chunks[] = $buffer;
                $tail = $overlap > 0
                    ? self::_safeTailOverlap($buffer, $overlap)
                    : '';
                $buffer = $tail === ''
                    ? $w
                    : ($tail . ' ' . $w);
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    private static function _safeTailOverlap(string $text, int $overlap): string
    {
        if ($overlap <= 0 || $text === '') return '';

        $len = mb_strlen($text, 'UTF-8');
        if ($len <= $overlap) return $text;

        $tail = mb_substr($text, -$overlap, null, 'UTF-8');

        $skip = 0;
        $tailLen = mb_strlen($tail, 'UTF-8');

        while ($skip < $tailLen) {
            $ch = mb_substr($tail, $skip, 1, 'UTF-8');
            if (preg_match('/\s/u', $ch)) {
                return mb_substr($tail, $skip + 1, null, 'UTF-8');
            }
            $skip++;
        }

        return '';
    }
}


// =====================================================================
// VECTOR HELPER
// =====================================================================

class VectorHelper
{
    public static function cosine(array $a, array $b): float
    {
        $la = count($a);
        $lb = count($b);

        if ($la === 0 || $lb === 0) {
            return 0.0;
        }

        if ($la !== $lb) {
            if (function_exists('logger_warn')) {
                logger_warn(sprintf(
                    '[RAG] Vector dimension mismatch: %d vs %d — similarity=0.0 (reindex may be required)',
                    $la,
                    $lb
                ));
            }
            return 0.0;
        }

        $dot = 0.0; $na = 0.0; $nb = 0.0;
        for ($i = 0; $i < $la; $i++) {
            $va = (float)$a[$i];
            $vb = (float)$b[$i];
            $dot += $va * $vb;
            $na  += $va * $va;
            $nb  += $vb * $vb;
        }
        if ($na <= 0 || $nb <= 0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }
}


// =====================================================================
// RAG PRE-FILTER HELPER (unused — for future use)
// =====================================================================

function rag_prefilter_candidates(array $docIds, string $query, int $limit = 200): array
{
    if (empty($docIds) || trim($query) === '') {
        return [];
    }

    $limit = max(10, min(1000, $limit));

    try {
        if (!method_exists('RAGChunk', 'lexicalSearch')) {
            return [];
        }

        $lexResults = RAGChunk::lexicalSearch($query, $docIds, $limit);

        $ids = [];
        foreach ($lexResults as $r) {
            if (isset($r['chunk_id'])) {
                $ids[] = (string)$r['chunk_id'];
            }
        }

        return array_values(array_unique($ids));

    } catch (Throwable $e) {
        if (function_exists('logger_warn')) {
            logger_warn('[RAG-PREFILTER] failed: ' . $e->getMessage());
        }
        return [];
    }
}


// =====================================================================
// EMBEDDING SERVICE
// =====================================================================

class EmbeddingService
{
    public $provider = 'local';
    public $dim      = EMBEDDING_DIM;
    public $model    = '';
    private $apiKey  = null;

    const OPENAI_BATCH_SIZE = 100;

    private static bool $_logged_init = false;

    private static function _log_init_once(string $msg): void
    {
        if (self::$_logged_init) return;
        self::$_logged_init = true;
        if (function_exists('logger_info')) {
            logger_info($msg);
        }
    }

    public function __construct(?string $provider = null, ?string $apiKey = null, ?string $model = null)
    {
        $requested = strtolower(trim($provider !== null ? $provider : EMBEDDING_PROVIDER));
        if ($requested === 'auto' || $requested === '') {
            if ($apiKey && GOOGLE_API_KEY)          $requested = 'google';
            elseif ($apiKey)                         $requested = 'openai';
            elseif (GAPGPT_API_KEY)                  $requested = 'gapgpt';
            elseif (OPENAI_API_KEY)                  $requested = 'openai';
            elseif (GOOGLE_API_KEY)                  $requested = 'google';
            else                                     $requested = 'local';
        }

        if ($requested === 'gapgpt') {
            $key = $apiKey ? $apiKey : GAPGPT_API_KEY;
            if (!$key) {
                logger_warn('[EMBEDDING] GapGPT requested but no API key; falling back to local.');
                $requested = 'local';
            } else {
                $this->provider = 'gapgpt';
                $this->apiKey   = $key;
                $this->model    = $model ? $model : 'text-embedding-3-small';
                $this->dim      = 1536;
                self::_log_init_once("[EMBEDDING] Using GapGPT: {$this->model} (dim=1536)");
                return;
            }
        }

        if ($requested === 'openai') {
            $key = $apiKey ? $apiKey : OPENAI_API_KEY;
            if (!$key) {
                logger_warn('[EMBEDDING] OpenAI requested but no API key; falling back to local.');
                $requested = 'local';
            } else {
                $this->provider = 'openai';
                $this->apiKey   = $key;
                $this->model    = $model ? $model : 'text-embedding-3-small';
                $this->dim      = 1536;
                self::_log_init_once("[EMBEDDING] Using OpenAI: {$this->model}");
                return;
            }
        }

        if ($requested === 'google') {
            $key = $apiKey ? $apiKey : GOOGLE_API_KEY;
            if (!$key) {
                logger_warn('[EMBEDDING] Google requested but no API key; falling back to local.');
                $requested = 'local';
            } else {
                $this->provider = 'google';
                $this->apiKey   = $key;
                $this->model    = $model ? $model : 'text-embedding-004';
                $this->dim      = 768;
                self::_log_init_once("[EMBEDDING] Using Google: {$this->model}");
                return;
            }
        }

        $this->provider = 'local';
        $this->dim      = EMBEDDING_DIM;
        self::_log_init_once("[EMBEDDING] Using local hash-based embedding (dim={$this->dim})");
        if (function_exists('logger_warn')) {
            logger_warn(
                '[EMBEDDING] ⚠️ Local hash-based embedding is NOT semantic — RAG quality will be poor. '
                . 'Configure OPENAI_API_KEY or GOOGLE_API_KEY in data/secrets.php for production.'
            );
        }
    }

    public function isSemantic(): bool
    {
        return in_array($this->provider, ['openai', 'google', 'gapgpt'], true);
    }

    public function providerLabel(): string
    {
        if (!$this->isSemantic()) {
            return 'local_hash_non_semantic';
        }
        return $this->provider;
    }

    public function embed(string $text): ?array
    {
        if (trim($text) === '') return null;
        try {
            if ($this->provider === 'openai' || $this->provider === 'gapgpt') {
                return $this->embedOpenAICompatible($text);
            }
            if ($this->provider === 'google') return $this->embedGoogle($text);
            return $this->embedLocal($text);
        } catch (Throwable $e) {
            logger_warn("[EMBEDDING] {$this->provider} failed: " . $e->getMessage());
            return null;
        }
    }

    public function embedBatch(array $texts): array
    {
        if (empty($texts)) return [];

        $cleaned = [];
        foreach ($texts as $t) {
            $s = (string)$t;
            $cleaned[] = $s === '' ? ' ' : $s;
        }

        if ($this->provider === 'openai' || $this->provider === 'gapgpt') {
            return $this->embedBatchOpenAICompatible($cleaned);
        }

        $out = [];
        foreach ($cleaned as $t) {
            $out[] = $this->embed($t);
        }
        return $out;
    }

    private function _embeddingsUrl(): string
    {
        if ($this->provider === 'gapgpt') {
            return rtrim(GAPGPT_BASE_URL, '/') . '/embeddings';
        }
        return 'https://api.openai.com/v1/embeddings';
    }

    private function embedOpenAICompatible(string $text): array
    {
        $url = $this->_embeddingsUrl();
        $body = [
            'model' => $this->model,
            'input' => $text,
        ];
        $resp = $this->httpPostJson($url, $body, [
            "Authorization: Bearer {$this->apiKey}",
        ], AI_TIMEOUT);
        if (!isset($resp['data'][0]['embedding'])) {
            throw new RuntimeException($this->provider . ' embedding: invalid response');
        }
        return array_map('floatval', $resp['data'][0]['embedding']);
    }

    private function embedBatchOpenAICompatible(array $texts): array
    {
        $url = $this->_embeddingsUrl();
        $allResults = [];
        $total = count($texts);
        $maxRetries = 3;

        for ($i = 0; $i < $total; $i += self::OPENAI_BATCH_SIZE) {
            $chunk = array_slice($texts, $i, self::OPENAI_BATCH_SIZE);
            $body = [
                'model' => $this->model,
                'input' => array_values($chunk),
            ];

            $resp = null;
            $lastErr = null;
            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                try {
                    $resp = $this->httpPostJson($url, $body, [
                        "Authorization: Bearer {$this->apiKey}",
                    ], AI_TIMEOUT);
                    break;
                } catch (Throwable $e) {
                    $lastErr = $e;
                    $msg = $e->getMessage();
                    $retryable = (stripos($msg, '429') !== false)
                              || (stripos($msg, 'rate') !== false)
                              || (stripos($msg, '5') !== false && preg_match('/HTTP 5\d\d/', $msg));
                    if (!$retryable || $attempt === $maxRetries - 1) {
                        throw $e;
                    }
                    $sleepMs = (int)(250 * (2 ** $attempt));
                    usleep($sleepMs * 1000);
                }
            }

            if (!isset($resp['data']) || !is_array($resp['data'])) {
                throw new RuntimeException($this->provider . ' batch embedding: invalid response');
            }

            if (count($resp['data']) !== count($chunk)) {
                throw new RuntimeException($this->provider . ' batch embedding: count mismatch');
            }

            foreach ($resp['data'] as $item) {
                if (!isset($item['embedding'])) {
                    throw new RuntimeException($this->provider . ' batch embedding: missing embedding');
                }
                $allResults[] = array_map('floatval', $item['embedding']);
            }
        }

        if (count($allResults) !== $total) {
            throw new RuntimeException($this->provider . ' batch embedding: total count mismatch');
        }

        return $allResults;
    }

    private function embedGoogle(string $text): array
    {
        $model = $this->model;
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$this->apiKey}";
        $body = [
            'model'   => "models/{$model}",
            'content' => ['parts' => [['text' => $text]]],
        ];
        $resp = $this->httpPostJson($url, $body, [], AI_TIMEOUT);
        if (!isset($resp['embedding']['values'])) {
            throw new RuntimeException('Google embedding: invalid response');
        }
        return array_map('floatval', $resp['embedding']['values']);
    }

    private function embedLocal(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') return array_fill(0, $this->dim, 0.0);

        $vec = array_fill(0, $this->dim, 0.0);

        preg_match_all('/\w+/u', $text, $tokens);
        foreach ($tokens[0] as $tok) {
            $h = self::stableHash($tok);
            $vec[$h % $this->dim] += 1.0;
        }

        $len = mb_strlen($text);
        for ($i = 0; $i < $len - 2; $i++) {
            $gram = mb_substr($text, $i, 3);
            $h = self::stableHash($gram);
            $vec[$h % $this->dim] += 0.3;
        }

        $norm = 0.0;
        foreach ($vec as $v) $norm += $v * $v;
        $norm = sqrt($norm);
        if ($norm > 0) {
            foreach ($vec as $i => $v) $vec[$i] = $v / $norm;
        }
        return $vec;
    }

    private static function stableHash(string $token): int
    {
        $md5 = md5($token, true);
        $val = unpack('N', substr($md5, 0, 4))[1];
        return $val & 0x7FFFFFFF;
    }

    private function httpPostJson(string $url, array $body, array $headers = [], int $timeout = 60): array
    {
        $ch = curl_init($url);
        if (!$ch) throw new RuntimeException('curl_init failed');

        $allHeaders = array_merge(['Content-Type: application/json'], $headers);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('curl error: ' . $err);
        }
        $decoded = json_decode($raw, true);
        if ($code >= 400) {
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : "HTTP $code";
            throw new RuntimeException("API error: $msg");
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response');
        }
        return $decoded;
    }
}


// =====================================================================
// RAG SERVICE
// =====================================================================

class RAGService
{
    public function pickProviderForCollection(string $collectionId): EmbeddingService
    {
        try {
            $links = AIProfileRAGCollection::listForCollection($collectionId);
            foreach ($links as $link) {
                if ((int)$link['is_active'] !== 1) continue;
                $profile = AIProfile::find($link['ai_profile_id']);
                if ($profile) {
                    return get_embedding_provider_for_ai($profile);
                }
            }
        } catch (Throwable $e) {
            logger_warn('[RAG] Could not resolve per-AI provider: ' . $e->getMessage());
        }
        return new EmbeddingService();
    }

    public function indexDocument(string $documentId): void
    {
        $doc = RAGDocument::find($documentId);
        if (!$doc) {
            logger_warn("[RAG] Document $documentId not found.");
            return;
        }

        try {
            RAGDocument::update($doc['id'], [
                'status'           => 'processing',
                'processing_error' => null,
            ]);

            $path = safe_file_path($doc['file_path'], RAG_DIR);
            if (!$path) {
                throw new RuntimeException('مسیر فایل نامعتبر است یا خارج از دایرکتوری مجاز قرار دارد.');
            }

            $extracted = TextService::extractFromFile($path, $doc['mime_type']);

            if (is_string($extracted)) {
                $extracted = [
                    'text'       => $extracted,
                    'pages'      => [['page_number' => 1, 'text' => $extracted]],
                    'extractor'  => 'legacy',
                    'page_count' => 1,
                ];
            }

            $rawText = (string)($extracted['text'] ?? '');
            if ($rawText !== '' && !mb_check_encoding($rawText, 'UTF-8')) {
                logger_warn('[RAG] Invalid UTF-8 in extracted text for doc ' . $doc['id'] . ' — sanitizing');
                $rawText = _services_safe_utf8($rawText);
                $extracted['text'] = $rawText;
            }

            $pages   = is_array($extracted['pages'] ?? null) ? $extracted['pages'] : [];

            if (!empty($pages)) {
                foreach ($pages as $i => $p) {
                    $pageText = (string)($p['text'] ?? '');
                    if ($pageText !== '' && !mb_check_encoding($pageText, 'UTF-8')) {
                        $pages[$i]['text'] = _services_safe_utf8($pageText);
                    }
                }
            }

            $extractor = (string)($extracted['extractor'] ?? 'unknown');
            $pageCount = (int)($extracted['page_count'] ?? count($pages));

            $cleaned = TextService::clean($rawText);
            if ($cleaned === '') {
                throw new RuntimeException('فایل خالی است یا متنی استخراج نشد.');
            }

            $language = 'unknown';
            if (function_exists('text_detect_script')) {
                $language = text_detect_script($cleaned);
            }

            $educationalMeta = [];
            if (!empty($doc['grade'])) {
                $educationalMeta['grade'] = (string)$doc['grade'];
            }
            if (!empty($doc['subject'])) {
                $educationalMeta['subject'] = (string)$doc['subject'];
            }

            if (!empty($pages) && count($pages) > 1) {
                $chunks = TextService::chunkStructured($pages, RAG_CHUNK_SIZE, RAG_CHUNK_OVERLAP);
            } else {
                $legacyChunks = TextService::chunk($cleaned, RAG_CHUNK_SIZE, RAG_CHUNK_OVERLAP);
                $singlePageNum = !empty($pages[0]['page_number']) ? (int)$pages[0]['page_number'] : 1;
                $chunks = [];
                foreach ($legacyChunks as $lc) {
                    $lc['page_number'] = $singlePageNum;
                    $lc['page_end']    = $singlePageNum;
                    $chunks[] = $lc;
                }
            }

            if (empty($chunks)) {
                throw new RuntimeException('chunking نتیجه‌ای نداشت.');
            }

            RAGChunk::deleteByDocument($doc['id']);

            $provider = $this->pickProviderForCollection($doc['collection_id']);
            $contents = [];
            foreach ($chunks as $c) {
                $contents[] = $c['content'];
            }
            $embeddings = $provider->embedBatch($contents);

            $embeddingModel = $provider->provider;
            $embeddingDim   = (int)$provider->dim;

            foreach ($chunks as $i => $chunk) {
                $emb = isset($embeddings[$i]) ? $embeddings[$i] : null;

                $normalizedContent = null;
                if (RAG_NORMALIZATION_ENABLED && function_exists('text_normalize_persian_deep')) {
                    $normalizedContent = text_normalize_persian_deep((string)$chunk['content']);
                }

                $chunkMeta = $chunk['metadata'] ?? [];
                if (!is_array($chunkMeta)) $chunkMeta = [];
                if (!empty($educationalMeta)) {
                    $chunkMeta = array_merge($educationalMeta, $chunkMeta);
                }

                RAGChunk::createWithMetadata([
                    'document_id'        => $doc['id'],
                    'chunk_index'        => $chunk['index'],
                    'content'            => $chunk['content'],
                    'token_count'        => $chunk['token_count'],
                    'chunk_metadata'     => $chunkMeta,
                    'embedding'          => $emb ?: null,
                    'embedding_model'    => $embeddingModel,
                    'embedding_dim'      => $embeddingDim,
                    'normalized_content' => $normalizedContent,
                    'page_number'        => $chunk['page_number'] ?? null,
                    'page_end'           => $chunk['page_end'] ?? null,
                    'heading'            => $chunk['heading'] ?? null,
                    'section'            => $chunk['section'] ?? null,
                ]);
            }

            RAGDocument::updateProcessingMeta(
                $doc['id'],
                $pageCount > 0 ? $pageCount : null,
                $extractor,
                $language
            );

            RAGDocument::update($doc['id'], [
                'status'           => 'ready',
                'processing_error' => null,
                'chunk_count'      => count($chunks),
            ]);

            logger_info(sprintf(
                '[RAG] Indexed doc %s (%s): %d chunks, provider=%s, extractor=%s, pages=%d, lang=%s, grade=%s',
                $doc['id'],
                $doc['title'],
                count($chunks),
                $provider->provider,
                $extractor,
                $pageCount,
                $language,
                $doc['grade'] ?? '-'
            ));

        } catch (Throwable $e) {
            logger_error("[RAG] Indexing failed for $documentId: " . $e->getMessage());
            try {
                RAGDocument::update($documentId, [
                    'status'           => 'failed',
                    'processing_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            } catch (Throwable $e2) {}
        }
    }

    public function retrieve(
        string $aiProfileId,
        string $query,
        int $topK = RAG_TOP_K,
        float $minScore = RAG_MIN_SCORE,
        int $maxContextTokens = RAG_MAX_CONTEXT_TOKENS,
        ?array $educationalFilter = null
    ): array {
        if (trim($query) === '') {
            return self::_emptyRetrievalResult();
        }

        try {
            return $this->_retrieveInternal(
                $aiProfileId,
                $query,
                $topK,
                $minScore,
                $maxContextTokens,
                $educationalFilter
            );
        } catch (Throwable $e) {
            logger_error('[RAG] retrieve() failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            $empty = self::_emptyRetrievalResult();
            $empty['success'] = false;
            $empty['error']   = 'retrieval_failed';
            $empty['error_detail'] = mb_substr($e->getMessage(), 0, 300);
            $empty['groundedness']['reason'] = 'retrieval_failed: ' . mb_substr($e->getMessage(), 0, 100);
            return $empty;
        }
    }

    private static function _emptyRetrievalResult(): array
    {
        return [
            'context_text'   => '',
            'chunks'         => [],
            'used_count'     => 0,
            'total_tokens'   => 0,
            'provenance'     => [],
            'groundedness'   => [
                'status'     => 'none',
                'confidence' => 0.0,
                'reason'     => 'no_retrieval',
                'top_score'  => 0.0,
                'chunk_count'=> 0,
            ],
            'pipeline_stats' => [],
            'success'        => true,
            'error'          => null,
        ];
    }

    private function _retrieveInternal(
        string $aiProfileId,
        string $query,
        int $topK,
        float $minScore,
        int $maxContextTokens,
        ?array $educationalFilter = null
    ): array {
        $result = self::_emptyRetrievalResult();
        $profile = AIProfile::find($aiProfileId);
        if (!$profile) return $result;

        $links = AIProfileRAGCollection::listActiveForAI($aiProfileId);
        $activeLinks = [];
        foreach ($links as $l) {
            if ((int)$l['collection_active'] === 1 && $l['collection_deleted'] === null) {
                $activeLinks[] = $l;
            }
        }
        if (empty($activeLinks)) return $result;

        usort($activeLinks, function ($a, $b) {
            return (int)$b['priority'] <=> (int)$a['priority'];
        });

        $collectionIds = [];
        foreach ($activeLinks as $l) $collectionIds[] = $l['collection_id'];

        $docs = RAGDocument::listReadyByCollections($collectionIds);
        if (empty($docs)) return $result;

        $docIds = array_column($docs, 'id');
        $documentsMap = [];
        foreach ($docs as $d) $documentsMap[$d['id']] = $d;

        $queryNorm = $query;
        if (RAG_NORMALIZATION_ENABLED && function_exists('text_normalize_query')) {
            $queryNorm = text_normalize_query($query);
        }
        if (trim($queryNorm) === '') $queryNorm = $query;

        $provider = get_embedding_provider_for_ai($profile);
        $qEmb = $provider->embed($queryNorm);
        if (!$qEmb) return $result;

        $chunksAll = [];
        if (class_exists('RAGChunk') && method_exists('RAGChunk', 'listWithMetadata')) {
            $chunksAll = RAGChunk::listWithMetadata(
                $docIds,
                ['with_embedding' => true, 'only_with_embedding' => true, 'limit' => 5000]
            );
        } else {
            $chunksAll = RAGChunk::listWithEmbedding($docIds);
        }

        $sampleModel = null;
        foreach ($chunksAll as $sc) {
            if (!empty($sc['embedding_model'])) {
                $sampleModel = (string)$sc['embedding_model'];
                break;
            }
        }
        if ($sampleModel !== null && $sampleModel !== $provider->provider) {
            logger_error(sprintf(
                '[RAG] PROVIDER MISMATCH: docs indexed with "%s" but query using "%s" — results may be empty. Re-index required.',
                $sampleModel,
                $provider->provider
            ));
            $result['success'] = false;
            $result['error']   = 'provider_mismatch';
            $result['groundedness']['reason'] = sprintf(
                'Embedding provider mismatch (docs=%s, query=%s). لطفاً اسناد را دوباره ایندکس کنید.',
                $sampleModel,
                $provider->provider
            );
            return $result;
        }

        $hybridEnabled = get_setting('rag_hybrid_enabled', RAG_HYBRID_ENABLED);
        $candidateCount = RAG_HYBRID_CANDIDATE_COUNT;

        $vectorCandidates = [];
        foreach ($chunksAll as $ch) {
            if (!is_array($ch['embedding']) || empty($ch['embedding'])) continue;
            $score = VectorHelper::cosine($qEmb, $ch['embedding']);
            $floorScore = min($minScore, RAG_DYNAMIC_THRESHOLD_ABSOLUTE * 0.5);
            if ($score < $floorScore) continue;

            $vectorCandidates[] = [
                'chunk_id'    => (string)$ch['id'],
                'document_id' => (string)$ch['document_id'],
                'score'       => $score,
                'chunk'       => $ch,
            ];
        }

        usort($vectorCandidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $vectorCandidates = array_slice($vectorCandidates, 0, $candidateCount);

        $lexicalCandidates = [];
        if ($hybridEnabled && method_exists('RAGChunk', 'lexicalSearch')) {
            try {
                $lexResults = RAGChunk::lexicalSearch($queryNorm, $docIds, $candidateCount);
                foreach ($lexResults as $lr) {
                    $lexicalCandidates[] = [
                        'chunk_id'    => (string)$lr['chunk_id'],
                        'document_id' => (string)$lr['document_id'],
                        'score'       => (float)$lr['score'],
                    ];
                }
            } catch (Throwable $e) {
                logger_warn('[RAG] Lexical search failed: ' . $e->getMessage());
            }
        }

        $mergedCandidates = $this->mergeCandidates(
            $vectorCandidates,
            $lexicalCandidates,
            $chunksAll,
            $hybridEnabled,
            $provider
        );

        if (empty($mergedCandidates)) return $result;

        $groundednessMode = (string)get_setting('rag_groundedness_mode', RAG_GROUNDEDNESS_MODE);
        if (!in_array($groundednessMode, ['strict', 'balanced', 'permissive'], true)) {
            $groundednessMode = 'strict';
        }

        $pipelineResult = $this->runPipeline(
            $mergedCandidates,
            $queryNorm,
            $groundednessMode,
            $topK,
            $educationalFilter
        );

        $selected = $pipelineResult['selected'];
        $assessment = $pipelineResult['assessment'];
        $pipelineStats = $pipelineResult['pipeline_stats'];

        if (empty($selected)) {
            $result['groundedness'] = $assessment;
            $result['pipeline_stats'] = $pipelineStats;
            return $result;
        }

        $collectionsMap = [];
        foreach ($docs as $d) {
            $cid = $d['collection_id'] ?? null;
            if ($cid && !isset($collectionsMap[$cid])) {
                $col = RAGCollection::find($cid);
                if ($col) $collectionsMap[$cid] = $col;
            }
        }

        $includeHeader = (bool)get_setting('rag_context_header_enabled', RAG_CONTEXT_HEADER_ENABLED);
        $contextResult = rag_assemble_context(
            $selected,
            $documentsMap,
            $collectionsMap,
            $maxContextTokens,
            $includeHeader
        );

        $provenance = rag_build_provenance_list($selected, $documentsMap, $collectionsMap);

        $legacyChunks = [];
        foreach ($selected as $s) {
            $legacyChunks[] = [
                'chunk_id'    => $s['chunk_id'] ?? '',
                'document_id' => $s['document_id'] ?? '',
                'score'       => round((float)($s['combined_score'] ?? $s['score'] ?? 0), 4),
                'tokens'      => (int)($s['token_count'] ?? 0),
                'preview'     => mb_substr((string)($s['content'] ?? ''), 0, 200),
            ];
        }

        $result['context_text']   = $contextResult['context_text'];
        $result['chunks']         = $legacyChunks;
        $result['used_count']     = count($contextResult['used_chunks']);
        $result['total_tokens']   = (int)$contextResult['total_tokens'];
        $result['provenance']     = $provenance;
        $result['groundedness']   = $assessment;
        $result['pipeline_stats'] = $pipelineStats;

        return $result;
    }

    private function mergeCandidates(
        array $vectorCandidates,
        array $lexicalCandidates,
        array $chunksAll,
        bool $hybridEnabled,
        ?EmbeddingService $provider = null
    ): array {
        $chunkById = [];
        foreach ($chunksAll as $c) {
            $chunkById[(string)$c['id']] = $c;
        }

        if (!$hybridEnabled || empty($lexicalCandidates)) {
            $out = [];
            foreach ($vectorCandidates as $vc) {
                $chunk = $chunkById[$vc['chunk_id']] ?? null;
                if (!$chunk) continue;
                $out[] = array_merge($chunk, [
                    'chunk_id'       => $vc['chunk_id'],
                    'combined_score' => (float)$vc['score'],
                    'vector_score'   => (float)$vc['score'],
                    'lexical_score'  => 0.0,
                ]);
            }
            return $out;
        }

        if (function_exists('rag_merge_hybrid_candidates')) {
            $vInput = [];
            foreach ($vectorCandidates as $vc) {
                $vInput[] = ['chunk_id' => $vc['chunk_id'], 'score' => (float)$vc['score']];
            }
            $lInput = [];
            foreach ($lexicalCandidates as $lc) {
                $lInput[] = ['chunk_id' => $lc['chunk_id'], 'score' => (float)$lc['score']];
            }

            $vectorWeight  = RAG_HYBRID_VECTOR_WEIGHT;
            $lexicalWeight = RAG_HYBRID_LEXICAL_WEIGHT;

            $isSemantic = $provider !== null ? $provider->isSemantic() : true;
            if (!$isSemantic) {
                $vectorWeight  = 0.20;
                $lexicalWeight = 0.80;
            }

            $merged = rag_merge_hybrid_candidates(
                $vInput,
                $lInput,
                $vectorWeight,
                $lexicalWeight
            );

            $out = [];
            foreach ($merged as $m) {
                $chunk = $chunkById[$m['chunk_id']] ?? null;
                if (!$chunk) continue;
                $out[] = array_merge($chunk, [
                    'chunk_id'       => $m['chunk_id'],
                    'combined_score' => (float)$m['combined_score'],
                    'vector_score'   => (float)$m['vector_score'],
                    'lexical_score'  => (float)$m['lexical_score'],
                ]);
            }
            return $out;
        }

        $byId = [];
        foreach ($vectorCandidates as $vc) {
            if (!isset($chunkById[$vc['chunk_id']])) continue;
            $byId[$vc['chunk_id']] = array_merge($chunkById[$vc['chunk_id']], [
                'chunk_id'       => $vc['chunk_id'],
                'combined_score' => (float)$vc['score'],
            ]);
        }
        foreach ($lexicalCandidates as $lc) {
            if (!isset($chunkById[$lc['chunk_id']])) continue;
            if (!isset($byId[$lc['chunk_id']])) {
                $byId[$lc['chunk_id']] = array_merge($chunkById[$lc['chunk_id']], [
                    'chunk_id'       => $lc['chunk_id'],
                    'combined_score' => (float)$lc['score'],
                ]);
            }
        }
        return array_values($byId);
    }

    private function runPipeline(
        array $candidates,
        string $query,
        string $groundednessMode,
        int $topN,
        ?array $educationalFilter = null
    ): array {
        $stats = ['input_count' => count($candidates)];

        if (!function_exists('rag_apply_pipeline')) {
            usort($candidates, function ($a, $b) {
                $sa = (float)($a['combined_score'] ?? $a['score'] ?? 0);
                $sb = (float)($b['combined_score'] ?? $b['score'] ?? 0);
                return $sb <=> $sa;
            });
            $selected = array_slice($candidates, 0, $topN);
            return [
                'selected'       => $selected,
                'assessment'     => [
                    'status' => count($selected) > 0 ? 'weak' : 'none',
                    'confidence' => 0.0,
                    'reason' => 'fallback_no_helpers',
                    'top_score' => 0.0,
                    'chunk_count' => count($selected),
                ],
                'pipeline_stats' => $stats,
            ];
        }

        $result = rag_apply_pipeline($candidates, $query, [
            'rerank'            => (bool)get_setting('rag_rerank_enabled', RAG_RERANK_ENABLED),
            'dedup'             => true,
            'mmr'               => true,
            'dynamic_threshold' => RAG_DYNAMIC_THRESHOLD_ENABLED,
            'top_n'             => $topN,
            'groundedness_mode' => $groundednessMode,
            'educational_filter'=> $educationalFilter,
        ]);

        return [
            'selected'       => $result['selected'] ?? [],
            'assessment'     => $result['assessment'] ?? [],
            'pipeline_stats' => $result['pipeline_stats'] ?? [],
        ];
    }
}

function get_embedding_provider_for_ai(array $aiProfile): EmbeddingService
{
    $provider = strtolower(isset($aiProfile['model_provider']) ? $aiProfile['model_provider'] : '');
    $key = AIProfile::effectiveApiKey($aiProfile);

    if ($provider === 'gapgpt') {
        $effectiveKey = $key ?: GAPGPT_API_KEY;
        if ($effectiveKey) {
            return new EmbeddingService('gapgpt', $effectiveKey);
        }
        logger_warn('[EMBEDDING] GapGPT AI profile but no GapGPT API key available — falling back to auto');
        return new EmbeddingService();
    }

    if (in_array($provider, ['openai', 'google'], true) && $key) {
        return new EmbeddingService($provider, $key);
    }

    if (GAPGPT_API_KEY)  return new EmbeddingService('gapgpt', GAPGPT_API_KEY);
    if (OPENAI_API_KEY)  return new EmbeddingService('openai', OPENAI_API_KEY);
    if (GOOGLE_API_KEY)  return new EmbeddingService('google', GOOGLE_API_KEY);

    return new EmbeddingService();
}


// =====================================================================
// AI SERVICE
// =====================================================================

class AIService
{
    public function generateResponse(array $aiProfile, string $systemPrompt, array $messages): array
    {
        $start = microtime(true);
        $result = [
            'content'       => '',
            'input_tokens'  => 0,
            'output_tokens' => 0,
            'total_tokens'  => 0,
            'cached_tokens' => null,
            'cost'          => null,
            'cost_details'  => null,
            'status'        => 'success',
            'error'         => null,
            'provider'      => isset($aiProfile['model_provider']) ? $aiProfile['model_provider'] : 'unknown',
            'model'         => isset($aiProfile['model_name']) ? $aiProfile['model_name'] : 'unknown',
            'duration_ms'   => 0,
        ];

        $provider = strtolower(isset($aiProfile['model_provider']) ? $aiProfile['model_provider'] : '');
        $apiKey = AIProfile::effectiveApiKey($aiProfile);

        if ($provider !== 'local' && !$apiKey) {
            $result['content'] = 'این دستیار هنوز تنظیم نشده است. لطفاً از مدیر بخواهید کلید API را وارد کند.';
            $result['status'] = 'error';
            $result['error']  = 'missing_api_key';
            $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
            return $result;
        }

        try {
            if ($provider === 'gapgpt') {
                $out = $this->callGapGPT($aiProfile, $apiKey, $systemPrompt, $messages);
            } elseif ($provider === 'openai') {
                $out = $this->callOpenAI($aiProfile, $apiKey, $systemPrompt, $messages);
            } elseif ($provider === 'google') {
                $out = $this->callGoogle($aiProfile, $apiKey, $systemPrompt, $messages);
            } elseif ($provider === 'anthropic') {
                $out = $this->callAnthropic($aiProfile, $apiKey, $systemPrompt, $messages);
            } elseif ($provider === 'local') {
                $out = $this->callLocal($aiProfile, $systemPrompt, $messages);
            } else {
                throw new RuntimeException("Provider پشتیبانی نمی‌شود: $provider");
            }
            $result = array_merge($result, $out);
        } catch (Throwable $e) {
            $safeMsg = $this->sanitizeProviderError($e->getMessage(), $apiKey);
            logger_warn("[AI] Call failed ($provider): " . $safeMsg);

            $result['content'] = 'متأسفم، در حال حاضر امکان پاسخ‌دهی نیست. لطفاً بعداً تلاش کنید.';
            $result['status']  = 'error';
            $result['error']   = mb_substr($safeMsg, 0, 200);
        }

        $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
        return $result;
    }

    private function sanitizeProviderError(string $message, ?string $apiKey): string
    {
        if ($apiKey !== null && $apiKey !== '' && strlen($apiKey) >= 8) {
            $message = str_replace($apiKey, '[REDACTED]', $message);
        }
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]{8,}/i', 'Bearer [REDACTED]', $message) ?? $message;
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-[REDACTED]', $message) ?? $message;
        return $message;
    }

    private function callGapGPT(array $p, string $key, string $sys, array $msgs): array
    {
        $messages = [];
        if ($sys !== '') {
            $messages[] = ['role' => 'system', 'content' => $sys];
        }
        foreach ($msgs as $m) {
            if (!isset($m['role']) || !isset($m['content'])) continue;
            $messages[] = ['role' => $m['role'], 'content' => (string)$m['content']];
        }

        $model = isset($p['model_name']) && $p['model_name'] !== ''
            ? (string)$p['model_name']
            : AI_DEFAULT_MODEL;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => (float)(isset($p['temperature']) ? $p['temperature'] : 0.7),
            'max_tokens'  => (int)(isset($p['max_output_tokens']) ? $p['max_output_tokens'] : 1000),
        ];

        $url = rtrim(GAPGPT_BASE_URL, '/') . '/chat/completions';

        $resp = $this->httpPostJson(
            $url,
            $body,
            ["Authorization: Bearer $key"],
            AI_TIMEOUT
        );

        $content = isset($resp['choices'][0]['message']['content'])
            ? (string)$resp['choices'][0]['message']['content']
            : '';

        $usage = isset($resp['usage']) && is_array($resp['usage']) ? $resp['usage'] : [];

        $promptTokens     = isset($usage['prompt_tokens'])     && is_numeric($usage['prompt_tokens'])     ? (int)$usage['prompt_tokens']     : null;
        $completionTokens = isset($usage['completion_tokens']) && is_numeric($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null;
        $totalTokensRaw   = isset($usage['total_tokens'])      && is_numeric($usage['total_tokens'])      ? (int)$usage['total_tokens']      : null;
        $cachedTokens     = isset($usage['cached_tokens'])     && is_numeric($usage['cached_tokens'])     ? (int)$usage['cached_tokens']     : null;

        $totalTokens = $totalTokensRaw;
        if ($totalTokens === null && $promptTokens !== null && $completionTokens !== null) {
            $totalTokens = $promptTokens + $completionTokens;
        }

        $cost = isset($usage['cost']) && is_numeric($usage['cost']) ? (float)$usage['cost'] : null;

        $costDetails = null;
        if (isset($usage['cost_details']) && is_array($usage['cost_details'])) {
            $costDetails = $usage['cost_details'];
        }

        return [
            'content'       => trim($content),
            'input_tokens'  => $promptTokens !== null ? $promptTokens : 0,
            'output_tokens' => $completionTokens !== null ? $completionTokens : 0,
            'total_tokens'  => $totalTokens !== null ? $totalTokens : 0,
            'cached_tokens' => $cachedTokens,
            'cost'          => $cost,
            'cost_details'  => $costDetails,
        ];
    }

    private function callOpenAI(array $p, string $key, string $sys, array $msgs): array
    {
        $messages = [];
        if ($sys !== '') {
            $messages[] = ['role' => 'system', 'content' => $sys];
        }
        foreach ($msgs as $m) {
            if (!isset($m['role']) || !isset($m['content'])) continue;
            $messages[] = ['role' => $m['role'], 'content' => (string)$m['content']];
        }

        $body = [
            'model'       => isset($p['model_name']) && $p['model_name'] ? $p['model_name'] : 'gpt-4o-mini',
            'messages'    => $messages,
            'temperature' => (float)(isset($p['temperature']) ? $p['temperature'] : 0.7),
            'max_tokens'  => (int)(isset($p['max_output_tokens']) ? $p['max_output_tokens'] : 1000),
        ];

        $resp = $this->httpPostJson(
            'https://api.openai.com/v1/chat/completions',
            $body,
            ["Authorization: Bearer $key"]
        );

        $content = isset($resp['choices'][0]['message']['content']) ? $resp['choices'][0]['message']['content'] : '';
        $usage = isset($resp['usage']) ? $resp['usage'] : [];
        return [
            'content'       => trim($content),
            'input_tokens'  => (int)(isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0),
            'output_tokens' => (int)(isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0),
            'total_tokens'  => (int)(isset($usage['total_tokens']) ? $usage['total_tokens'] : 0),
        ];
    }

    private function callGoogle(array $p, string $key, string $sys, array $msgs): array
    {
        $model = isset($p['model_name']) && $p['model_name'] ? $p['model_name'] : 'gemini-1.5-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

        $contents = [];
        foreach ($msgs as $m) {
            $role = isset($m['role']) ? $m['role'] : 'user';
            if ($role === 'assistant') $role = 'model';
            if (!in_array($role, ['user', 'model'], true)) continue;
            $contents[] = [
                'role'  => $role,
                'parts' => [['text' => (string)(isset($m['content']) ? $m['content'] : '')]],
            ];
        }
        if (empty($contents)) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'ping']]];
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => (float)(isset($p['temperature']) ? $p['temperature'] : 0.7),
                'maxOutputTokens' => (int)(isset($p['max_output_tokens']) ? $p['max_output_tokens'] : 1000),
            ],
        ];
        if ($sys !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $sys]]];
        }

        $resp = $this->httpPostJson($url, $body, []);

        $content = '';
        if (isset($resp['candidates'][0]['content']['parts'])) {
            foreach ($resp['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) $content .= $part['text'];
            }
        }

        $in  = (int)(isset($resp['usageMetadata']['promptTokenCount']) ? $resp['usageMetadata']['promptTokenCount'] : 0);
        $out = (int)(isset($resp['usageMetadata']['candidatesTokenCount']) ? $resp['usageMetadata']['candidatesTokenCount'] : 0);

        if ($in === 0) {
            $in = estimate_tokens($sys);
            foreach ($msgs as $m) $in += estimate_tokens((string)(isset($m['content']) ? $m['content'] : ''));
        }
        if ($out === 0) $out = estimate_tokens($content);

        return [
            'content'       => trim($content),
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'total_tokens'  => $in + $out,
        ];
    }

    private function callAnthropic(array $p, string $key, string $sys, array $msgs): array
    {
        $messages = [];
        foreach ($msgs as $m) {
            $role = isset($m['role']) ? $m['role'] : 'user';
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            $messages[] = ['role' => $role, 'content' => (string)(isset($m['content']) ? $m['content'] : '')];
        }
        if (empty($messages)) {
            $messages[] = ['role' => 'user', 'content' => 'ping'];
        }

        $body = [
            'model'       => isset($p['model_name']) && $p['model_name'] ? $p['model_name'] : 'claude-3-haiku-20240307',
            'max_tokens'  => (int)(isset($p['max_output_tokens']) ? $p['max_output_tokens'] : 1000),
            'temperature' => (float)(isset($p['temperature']) ? $p['temperature'] : 0.7),
            'system'      => $sys,
            'messages'    => $messages,
        ];

        $resp = $this->httpPostJson(
            'https://api.anthropic.com/v1/messages',
            $body,
            [
                "x-api-key: $key",
                'anthropic-version: 2023-06-01',
            ]
        );

        $content = '';
        if (isset($resp['content']) && is_array($resp['content'])) {
            foreach ($resp['content'] as $block) {
                if (isset($block['text'])) $content .= $block['text'];
            }
        }

        $in  = (int)(isset($resp['usage']['input_tokens']) ? $resp['usage']['input_tokens'] : 0);
        $out = (int)(isset($resp['usage']['output_tokens']) ? $resp['usage']['output_tokens'] : 0);

        return [
            'content'       => trim($content),
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'total_tokens'  => $in + $out,
        ];
    }

    private function callLocal(array $p, string $sys, array $msgs): array
    {
        $userMsg = '';
        for ($i = count($msgs) - 1; $i >= 0; $i--) {
            if (isset($msgs[$i]['role']) && $msgs[$i]['role'] === 'user') {
                $userMsg = (string)(isset($msgs[$i]['content']) ? $msgs[$i]['content'] : '');
                break;
            }
        }

        if (trim($userMsg) === '') {
            $content = 'سلام! چطور می‌تونم کمکت کنم؟';
            $in = estimate_tokens($sys);
            foreach ($msgs as $m) $in += estimate_tokens((string)(isset($m['content']) ? $m['content'] : ''));
            $out = estimate_tokens($content);
            return [
                'content'       => $content,
                'input_tokens'  => $in,
                'output_tokens' => $out,
                'total_tokens'  => $in + $out,
            ];
        }

        $memoryAnswer = $this->tryAnswerFromHistory($userMsg, $msgs);
        if ($memoryAnswer !== null) {
            $in = estimate_tokens($sys);
            foreach ($msgs as $m) $in += estimate_tokens((string)(isset($m['content']) ? $m['content'] : ''));
            $out = estimate_tokens($memoryAnswer);
            return [
                'content'       => $memoryAnswer,
                'input_tokens'  => $in,
                'output_tokens' => $out,
                'total_tokens'  => $in + $out,
            ];
        }

        $lower = mb_strtolower($userMsg, 'UTF-8');

        $hasRag = mb_strpos($sys, 'منبع') !== false || mb_strpos($sys, 'منابع آموزشی') !== false;
        $preview = mb_substr($userMsg, 0, 200);

        if (mb_strpos($userMsg, 'ریاضی') !== false || mb_strpos($lower, 'math') !== false) {
            $content = "سوال ریاضی خوبی پرسیدی! 🧮\n\n"
                     . "**مرحله ۱:** صورت مسئله رو با دقت بخون\n"
                     . "**مرحله ۲:** اطلاعات داده‌شده رو مشخص کن\n"
                     . "**مرحله ۳:** فرمول یا روش مناسب رو انتخاب کن\n"
                     . "**مرحله ۴:** محاسبات رو انجام بده\n\n";
            if ($hasRag) $content .= '_پاسخ بر اساس منابع آموزشی کلاس شما ارائه شد._';
        } elseif (preg_match('/سلام|\bhi\b|\bhello\b/iu', $userMsg)) {
            $content = 'سلام! 👋 من دستیار آموزشی DANOVA هستم. چطور می‌تونم کمکت کنم؟';
        } elseif (mb_strpos($userMsg, 'علم') !== false || mb_strpos($lower, 'science') !== false) {
            $content = "عالیه! علوم یکی از جذاب‌ترین درس‌هاست 🔬\n\n"
                     . "درباره کدوم موضوع می‌خوای بدونی؟\n"
                     . "- گیاهان\n- حیوانات\n- انرژی\n- زمین و فضا";
        } elseif (mb_strpos($userMsg, 'فارسی') !== false || mb_strpos($userMsg, 'ادبیات') !== false) {
            $content = "فارسی و ادبیات دنیای قشنگی داره! 📖\n\n"
                     . "درباره چی می‌خوای بدونی؟\n"
                     . "- دستور زبان\n- آرایه‌های ادبی\n- معنی شعر\n- املا";
        } elseif (mb_strpos($userMsg, 'انگلیسی') !== false || mb_strpos($lower, 'english') !== false) {
            $content = "Great! Let's practice English together. 🌍\n\n"
                     . "What would you like to work on?\n"
                     . "- Vocabulary\n- Grammar\n- Conversation";
        } else {
            $content = "سوالت رو دریافت کردم: «{$preview}»\n\n"
                     . "من اینجام تا کمکت کنم یاد بگیری. 😊\n"
                     . "می‌تونی سوالت رو دقیق‌تر بپرسی تا بهتر کمکت کنم.";
            if ($hasRag) $content .= "\n\n_منابع مرتبط در دسترس بود._";
        }

        $in = estimate_tokens($sys);
        foreach ($msgs as $m) $in += estimate_tokens((string)(isset($m['content']) ? $m['content'] : ''));
        $out = estimate_tokens($content);

        return [
            'content'       => $content,
            'input_tokens'  => $in,
            'output_tokens' => $out,
            'total_tokens'  => $in + $out,
        ];
    }

    private function tryAnswerFromHistory(string $userMsg, array $msgs): ?string
    {
        $lower = mb_strtolower($userMsg);

        $isNameQuestion =
            (mb_strpos($userMsg, 'اسم من') !== false)
            || (mb_strpos($userMsg, 'نام من') !== false)
            || (mb_strpos($userMsg, 'من کی ام') !== false)
            || (mb_strpos($userMsg, 'من کی هستم') !== false)
            || (mb_strpos($userMsg, 'من کی‌ام') !== false)
            || (mb_strpos($userMsg, 'اسمم چیه') !== false)
            || (mb_strpos($userMsg, 'اسمم چیه؟') !== false)
            || (mb_strpos($userMsg, 'اسم من چیه') !== false)
            || (mb_strpos($userMsg, 'اسم من چیه؟') !== false)
            || (mb_strpos($lower, 'my name') !== false)
            || (mb_strpos($lower, 'who am i') !== false);

        if ($isNameQuestion) {
            $nameRegex = '/(?:من|اسمم|نامم)\s+([\p{L}\x{200C}]{2,40})\s+(?:هستم|ام|است|ه|هستم\.|است\.)/u';

            $foundName = null;

            for ($i = 0; $i < count($msgs); $i++) {
                if (!isset($msgs[$i]['role']) || $msgs[$i]['role'] !== 'user') continue;
                $prev = (string)($msgs[$i]['content'] ?? '');
                if ($prev === '') continue;

                if (preg_match($nameRegex, $prev, $m)) {
                    $name = trim($m[1]);

                    $blacklist = [
                        'کی', 'چی', 'چیه', 'آم', 'هستم', 'هستم؟',
                        'یک', 'به', 'از', 'در', 'با', 'که', 'و',
                        'رفتم', 'اومدم', 'گفتم', 'دیدم', 'خوردم',
                        'خسته', 'گرسنه', 'تشنه', 'خوشحال', 'ناراحت',
                        'دانش‌آموز', 'دانش آموز', 'معلم', 'مدرس', 'مدیر',
                    ];

                    if (in_array($name, $blacklist, true)) continue;
                    if (mb_strlen($name) < 2) continue;

                    $foundName = $name;
                }
            }

            if ($foundName !== null) {
                return "اسم شما **{$foundName}** است. 😊";
            }

            return "متأسفم، من اسم شما را در گفتگوهای قبلی پیدا نکردم. می‌تونید دوباره بگید؟";
        }

        return null;
    }

    /**
     * ✅ FIX v2.4.2 (CRITICAL): افزودن متد httpPostJson که در AIService گم شده بود.
     *
     * این متد برای ارسال درخواست‌های POST با فرمت JSON به APIهای خارجی
     * (مانند GapGPT، OpenAI، Google، Anthropic) استفاده می‌شود.
     *
     * بدون این متد، فراخوانی‌های $this->httpPostJson() در
     * callGapGPT()/callOpenAI()/callGoogle()/callAnthropic() باعث
     * خطای Fatal error: Call to undefined method می‌شدند و کل چت
     * از کار می‌افتاد.
     */
    private function httpPostJson(string $url, array $body, array $headers = [], int $timeout = 60): array
    {
        $ch = curl_init($url);
        if (!$ch) {
            throw new RuntimeException('curl_init failed');
        }

        $allHeaders = array_merge(['Content-Type: application/json'], $headers);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('curl error: ' . $err);
        }

        $decoded = json_decode($raw, true);

        if ($code >= 400) {
            $msg = isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : "HTTP $code";
            throw new RuntimeException("API error ($code): $msg");
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from API');
        }

        return $decoded;
    }
}


// =====================================================================
// PROVIDER TEST
// =====================================================================

function test_provider_connection(string $provider, string $apiKey, string $modelName = ''): array
{
    $provider = strtolower(trim($provider));
    if (in_array($provider, ['gemini', 'google-ai', 'googleai'], true))   $provider = 'google';
    if (in_array($provider, ['claude', 'anthropic-ai'], true))            $provider = 'anthropic';
    if (in_array($provider, ['gapgpt', 'gap-gpt'], true))                 $provider = 'gapgpt';

    if (!in_array($provider, SUPPORTED_PROVIDERS, true)) {
        return [false, "Provider ناشناخته: $provider"];
    }
    if ($provider !== 'local' && $apiKey === '') {
        return [false, 'کلید API وارد نشده است.'];
    }

    try {
        if ($provider === 'local') {
            return [true, 'Provider محلی نیازی به تست ندارد.'];
        }

        if ($provider === 'gapgpt') {
            $model = $modelName !== '' ? $modelName : AI_DEFAULT_MODEL;
            $url = rtrim(GAPGPT_BASE_URL, '/') . '/chat/completions';
            $resp = curl_json($url, [
                'model'      => $model,
                'messages'   => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ], ["Authorization: Bearer $apiKey"]);
            return !empty($resp['choices']) ? [true, 'اتصال به GapGPT موفق بود.'] : [false, 'پاسخ نامعتبر از GapGPT.'];
        }

        if ($provider === 'openai') {
            $resp = curl_json('https://api.openai.com/v1/chat/completions', [
                'model'      => $modelName ? $modelName : 'gpt-4o-mini',
                'messages'   => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 5,
            ], ["Authorization: Bearer $apiKey"]);
            return !empty($resp['choices']) ? [true, 'اتصال به OpenAI موفق بود.'] : [false, 'پاسخ نامعتبر از OpenAI.'];
        }

        if ($provider === 'google') {
            $model = $modelName ? $modelName : 'gemini-1.5-flash';
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
            $resp = curl_json($url, [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'ping']]]],
                'generationConfig' => ['maxOutputTokens' => 5],
            ]);
            return !empty($resp['candidates']) ? [true, 'اتصال به Google AI موفق بود.'] : [false, 'پاسخ نامعتبر از Google AI.'];
        }

        if ($provider === 'anthropic') {
            $resp = curl_json('https://api.anthropic.com/v1/messages', [
                'model'      => $modelName ? $modelName : 'claude-3-haiku-20240307',
                'max_tokens' => 5,
                'messages'   => [['role' => 'user', 'content' => 'ping']],
            ], [
                "x-api-key: $apiKey",
                'anthropic-version: 2023-06-01',
            ]);
            return !empty($resp['content']) ? [true, 'اتصال به Anthropic موفق بود.'] : [false, 'پاسخ نامعتبر از Anthropic.'];
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if (stripos($err, '401') !== false || stripos($err, 'invalid') !== false || stripos($err, 'unauthorized') !== false) {
            return [false, 'کلید API نامعتبر است.'];
        }
        if (stripos($err, '429') !== false || stripos($err, 'rate') !== false) {
            return [false, 'محدودیت درخواست. بعداً تلاش کنید.'];
        }
        if (stripos($err, 'timeout') !== false || stripos($err, 'timed out') !== false) {
            return [false, 'زمان اتصال به پایان رسید.'];
        }
        return [false, 'خطا در اتصال: ' . mb_substr($err, 0, 150)];
    }

    return [false, "Provider ناشناخته: $provider"];
}

function curl_json(string $url, array $body, array $headers = [], int $timeout = 20): array
{
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('curl_init failed');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('curl error: ' . $err);
    $decoded = json_decode($raw, true);
    if ($code >= 400) {
        $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : "HTTP $code";
        throw new RuntimeException("API error ($code): $msg");
    }
    return is_array($decoded) ? $decoded : [];
}


// =====================================================================
// VIDEO SERVICE
// =====================================================================

class VideoService
{
    public static function generateThumbnail(string $videoPath, string $thumbDir = THUMBNAILS_DIR): ?string
    {
        if (!self::ffmpegAvailable()) return null;

        $safeVideo = safe_file_path($videoPath, VIDEOS_DIR);
        if (!$safeVideo) {
            logger_warn('[VIDEO] generateThumbnail: invalid video path (outside VIDEOS_DIR): ' . $videoPath);
            return null;
        }

        $realThumbDir = @realpath($thumbDir);
        if ($realThumbDir === false) {
            if (!is_dir($thumbDir)) {
                @mkdir($thumbDir, 0755, true);
            }
            $realThumbDir = @realpath($thumbDir);
        }
        if ($realThumbDir === false || strpos($realThumbDir, realpath(DATA_DIR)) !== 0) {
            logger_warn('[VIDEO] generateThumbnail: invalid thumbnail directory: ' . $thumbDir);
            return null;
        }

        $stem = pathinfo($safeVideo, PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9._-]/', '_', $stem) ?? 'video';
        $thumbPath = rtrim($realThumbDir, '/') . '/' . $stem . '.jpg';

        if (file_exists($thumbPath)) @unlink($thumbPath);

        $cmd = sprintf(
            'ffmpeg -y -ss 00:00:03 -i %s -vframes 1 -vf "scale=480:-1" %s 2>/dev/null',
            escapeshellarg($safeVideo),
            escapeshellarg($thumbPath)
        );

        if (function_exists('danova_safe_shell_exec')) {
            danova_safe_shell_exec($cmd);
        }

        if (file_exists($thumbPath) && filesize($thumbPath) > 0) {
            return $thumbPath;
        }
        if (file_exists($thumbPath)) @unlink($thumbPath);
        return null;
    }

    public static function ffmpegAvailable(): bool
    {
        static $avail = null;
        if ($avail !== null) return $avail;

        if (function_exists('danova_command_exists')) {
            return $avail = danova_command_exists('ffmpeg');
        }
        return $avail = false;
    }

    public static function extractDuration(string $videoPath): int
    {
        if ($videoPath === '' || !is_file($videoPath)) return 0;

        if (function_exists('danova_command_exists') && danova_command_exists('ffprobe')) {
            $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
                 . escapeshellarg($videoPath) . ' 2>/dev/null';
            $out = function_exists('danova_safe_shell_exec')
                ? danova_safe_shell_exec($cmd)
                : null;
            if (is_string($out) && is_numeric(trim($out))) {
                return (int)round((float)trim($out));
            }
        }

        if (self::ffmpegAvailable()) {
            $cmd = 'ffmpeg -i ' . escapeshellarg($videoPath) . ' 2>&1 | grep Duration';
            $out = function_exists('danova_safe_shell_exec')
                ? danova_safe_shell_exec($cmd)
                : null;
            if (is_string($out) && preg_match('/Duration:\s*(\d+):(\d+):(\d+)\.(\d+)/', $out, $m)) {
                return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)$m[3];
            }
        }

        return 0;
    }
}


// =====================================================================
// SINGLETONS
// =====================================================================

function rag_service(): RAGService
{
    static $s = null;
    if ($s === null) $s = new RAGService();
    return $s;
}

function ai_service(): AIService
{
    static $s = null;
    if ($s === null) $s = new AIService();
    return $s;
}

function embedding_service(): EmbeddingService
{
    static $s = null;
    if ($s === null) $s = new EmbeddingService();
    return $s;
}


// =====================================================================
// USAGE HELPERS
// =====================================================================

function check_daily_limit(string $studentId): array
{
    $limit = (int)get_setting('ai_daily_message_limit', 50);
    $used = AIUsage::todayCountByStudent($studentId);
    $remaining = max(0, $limit - $used);
    return [
        'allowed'   => $used < $limit,
        'remaining' => $remaining,
        'limit'     => $limit,
        'used'      => $used,
    ];
}

function check_daily_token_limit(string $studentId, int $requestedTokens = 0): array
{
    $limit = (int)get_setting('ai_daily_token_limit', 100000);

    if ($limit <= 0) {
        return [
            'allowed'   => true,
            'limit'     => 0,
            'used'      => 0,
            'remaining' => null,
        ];
    }

    $used = 0;
    try {
        $used = AIUsage::sumTokensByStudentToday($studentId);
    } catch (Throwable $e) {
        logger_warn('[TOKEN LIMIT] read failed: ' . $e->getMessage());
        $used = 0;
    }

    $remaining = max(0, $limit - $used);

    if ($requestedTokens > 0 && ($used + $requestedTokens) > $limit) {
        return [
            'allowed'   => false,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $remaining,
        ];
    }

    return [
        'allowed'   => $used < $limit,
        'limit'     => $limit,
        'used'      => $used,
        'remaining' => $remaining,
    ];
}


/**
 * ✅ FIX v2.4.2 (CRITICAL): حذف وابستگی به ستون quota_version.
 *
 * در نسخه‌های قبلی، این تابع از یک الگوی Optimistic Locking با
 * ستون quota_version در جدول students استفاده می‌کرد. اما این
 * ستون ممکن است در دیتابیس‌های MySQL که از SQLite مهاجرت کرده‌اند
 * وجود نداشته باشد و باعث خطای زیر شود:
 *
 *   SQLSTATE[42S22]: Column not found: 1054 Unknown column
 *   'quota_version' in 'field list'
 *
 * راه‌حل: استفاده از SELECT ... FOR UPDATE روی رکورد student
 * به‌عنوان جایگزین قفل. این روش در MySQL کاملاً استاندارد است
 * و نیازی به ستون اضافی ندارد.
 *
 * @param string      $studentId
 * @param string|null $conversationId
 * @param int         $reservedTokens
 * @param int         $quotaUnits
 * @param string|null $clientMessageId
 * @return array
 */
function reserve_usage_slot(
    string $studentId,
    ?string $conversationId,
    int $reservedTokens = 0,
    int $quotaUnits = 1,
    ?string $clientMessageId = null
): array {
    if ($studentId === '') {
        return [
            'ok' => false, 'reservation_id' => null, 'reason' => 'invalid_student',
            'used_messages' => 0, 'used_tokens' => 0,
        ];
    }

    if ($quotaUnits < 1) $quotaUnits = 1;

    $msgLimit   = (int)get_setting('ai_daily_message_limit', 50);
    $tokenLimit = (int)get_setting('ai_daily_token_limit', 100000);

    $today = function_exists('danova_today')
        ? danova_today()
        : gmdate('Y-m-d');
    $now        = gmdate('Y-m-d H:i:s');
    $expiresAt  = gmdate('Y-m-d H:i:s', time() + USAGE_RESERVATION_TTL_SECONDS);
    $hardCutoff = gmdate('Y-m-d H:i:s', time() - USAGE_RESERVATION_HARD_TTL_SECONDS);

    $pdo = db();
    $attempts = 0;
    $maxAttempts = USAGE_RESERVATION_MAX_ATTEMPTS;

    while ($attempts < $maxAttempts) {
        $attempts++;
        try {
            $pdo->beginTransaction();

            // ✅ FIX v2.4.2: استفاده از SELECT ... FOR UPDATE به‌جای
            // optimistic lock با quota_version.
            //
            // این کار در MySQL کاملاً استاندارد است و ردیف را
            // تا پایان transaction قفل می‌کند.
            // ═══════════════════════════════════════════════════════════
            // DANOVA_FIX2_QUOTA_VERSION_FIXED
            // فقط id را select می‌کنیم، نه quota_version را
            // (که ممکن است در برخی نصب‌ها وجود نداشته باشد).
            // ═══════════════════════════════════════════════════════════
            $studentCheck = $pdo->prepare(
                'SELECT id FROM students WHERE id = ? FOR UPDATE'
            );
            $studentCheck->execute([$studentId]);
            $studentRow = $studentCheck->fetch();
            if (!$studentRow) {
                $pdo->rollBack();
                return [
                    'ok' => false, 'reservation_id' => null, 'reason' => 'invalid_student',
                    'used_messages' => 0, 'used_tokens' => 0,
                ];
            }

            // ─────────────────────────────────────────────────────────────
            // 1. Reaper نرم (soft TTL)
            // ─────────────────────────────────────────────────────────────
            $pdo->prepare(
                "UPDATE usage_reservations
                 SET status = 'expired'
                 WHERE status = 'pending' AND expires_at < ?"
            )->execute([$now]);

            // ─────────────────────────────────────────────────────────────
            // 2. Reaper سخت (hard TTL)
            // ─────────────────────────────────────────────────────────────
            $pdo->prepare(
                "UPDATE usage_reservations
                 SET status = 'expired'
                 WHERE status = 'pending' AND created_at < ?"
            )->execute([$hardCutoff]);

            // ─────────────────────────────────────────────────────────────
            // 3. محاسبه consumed از ai_usage
            // ─────────────────────────────────────────────────────────────
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(quota_units), 0) AS c,
                        COALESCE(SUM(total_tokens), 0) AS t
                 FROM ai_usage
                 WHERE student_id = ?
                   AND created_at >= ?
                   AND request_status = 'success'"
            );
            $startOfDay = function_exists('danova_today_start_utc')
                ? danova_today_start_utc()
                : gmdate('Y-m-d 00:00:00');
            $stmt->execute([$studentId, $startOfDay]);
            $consumed = $stmt->fetch();
            $usedMessages = (int)($consumed['c'] ?? 0);
            $usedTokens   = (int)($consumed['t'] ?? 0);

            // ─────────────────────────────────────────────────────────────
            // 4. محاسبه pending از usage_reservations
            // ─────────────────────────────────────────────────────────────
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(reserved_messages), 0) AS c,
                        COALESCE(SUM(reserved_tokens), 0) AS t
                 FROM usage_reservations
                 WHERE student_id = ?
                   AND day = ?
                   AND status = 'pending'
                   AND expires_at >= ?"
            );
            $stmt->execute([$studentId, $today, $now]);
            $pending = $stmt->fetch();
            $pendingMessages = (int)($pending['c'] ?? 0);
            $pendingTokens   = (int)($pending['t'] ?? 0);

            // ─────────────────────────────────────────────────────────────
            // 5. Idempotency check
            // ─────────────────────────────────────────────────────────────
            if ($clientMessageId !== null && $clientMessageId !== '' && $conversationId !== null) {
                $existingReservation = $pdo->prepare(
                    "SELECT status, id FROM usage_reservations
                     WHERE conversation_id = ? AND client_message_id = ?
                     ORDER BY created_at DESC LIMIT 1 FOR UPDATE"
                );
                $existingReservation->execute([$conversationId, $clientMessageId]);
                $existing = $existingReservation->fetch();

                if ($existing !== false && $existing !== null) {
                    $existingStatus = (string)($existing['status'] ?? '');
                    $pdo->commit();

                    $reason = 'idempotency_' . ($existingStatus !== '' ? $existingStatus : 'duplicate');
                    if ($existingStatus === 'pending') {
                        $reason = 'idempotency_pending';
                    } elseif ($existingStatus === 'committed') {
                        $reason = 'idempotency_completed';
                    }

                    return [
                        'ok' => false,
                        'reservation_id' => null,
                        'reason' => $reason,
                        'used_messages' => $usedMessages + $pendingMessages,
                        'used_tokens' => $usedTokens + $pendingTokens,
                    ];
                }
            }

            $totalMessages = $usedMessages + $pendingMessages;
            $totalTokens   = $usedTokens + $pendingTokens;

            // ─────────────────────────────────────────────────────────────
            // 6. چک limits
            // ─────────────────────────────────────────────────────────────
            if ($msgLimit > 0 && ($totalMessages + $quotaUnits) > $msgLimit) {
                $pdo->commit();
                return [
                    'ok' => false, 'reservation_id' => null,
                    'reason' => 'message_limit',
                    'used_messages' => $totalMessages,
                    'used_tokens' => $totalTokens,
                ];
            }

            if ($tokenLimit > 0 && $reservedTokens > 0
                && ($totalTokens + $reservedTokens) > $tokenLimit) {
                $pdo->commit();
                return [
                    'ok' => false, 'reservation_id' => null,
                    'reason' => 'token_limit',
                    'used_messages' => $totalMessages,
                    'used_tokens' => $totalTokens,
                ];
            }

            // ─────────────────────────────────────────────────────────────
            // 7. ثبت reservation
            // ─────────────────────────────────────────────────────────────
            $reservationId = generate_uuid();
            $ins = $pdo->prepare(
                'INSERT INTO usage_reservations
                    (id, student_id, conversation_id, day,
                     reserved_messages, reserved_tokens, client_message_id, status, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $reservationId, $studentId, $conversationId, $today,
                $quotaUnits, $reservedTokens, $clientMessageId, 'pending', $now, $expiresAt,
            ]);

            $pdo->commit();

            return [
                'ok' => true,
                'reservation_id' => $reservationId,
                'reason' => null,
                'used_messages' => $totalMessages,
                'used_tokens' => $totalTokens,
            ];

        } catch (PDOException $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}

            $msg = $e->getMessage();
            $driverCode = is_array($e->errorInfo) && isset($e->errorInfo[1])
                ? (int)$e->errorInfo[1]
                : 0;
            $retryableLock = stripos($msg, 'database is locked') !== false
                || in_array($driverCode, [1205, 1213], true)
                || (string)$e->getCode() === '40001';
            if ($retryableLock) {
                usleep(USAGE_RESERVATION_LOCK_RETRY_US * $attempts);
                continue;
            }
            logger_warn('[reserve_usage_slot] ' . $msg);
            return [
                'ok' => false, 'reservation_id' => null, 'reason' => 'db_error',
                'used_messages' => 0, 'used_tokens' => 0,
            ];
        } catch (Throwable $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
            logger_warn('[reserve_usage_slot] ' . $e->getMessage());
            return [
                'ok' => false, 'reservation_id' => null, 'reason' => 'unknown',
                'used_messages' => 0, 'used_tokens' => 0,
            ];
        }
    }

    return [
        'ok' => false, 'reservation_id' => null, 'reason' => 'locked',
        'used_messages' => 0, 'used_tokens' => 0,
    ];
}

function commit_usage_slot(?string $reservationId): bool
{
    if (!$reservationId) return false;

    try {
        $stmt = db()->prepare(
            "UPDATE usage_reservations
             SET status = 'committed'
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reservationId]);

        return $stmt->rowCount() > 0;

    } catch (Throwable $e) {
        logger_warn('[commit_usage_slot] ' . $e->getMessage());
        return false;
    }
}

function release_usage_slot(?string $reservationId): bool
{
    if (!$reservationId) return false;

    try {
        $stmt = db()->prepare(
            "UPDATE usage_reservations
             SET status = 'released'
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reservationId]);

        return $stmt->rowCount() > 0;

    } catch (Throwable $e) {
        logger_warn('[release_usage_slot] ' . $e->getMessage());
        return false;
    }
}

function record_ai_usage(
    ?array $user,
    ?string $studentId,
    ?string $aiProfileId,
    ?string $conversationId,
    ?string $messageId,
    string $provider,
    string $model,
    int $inputTokens,
    int $outputTokens,
    int $durationMs,
    string $status = 'success',
    ?int $cachedTokens = null,
    ?float $cost = null,
    ?array $costDetails = null,
    ?string $classId = null,
    int $quotaUnits = 1,
    ?int $totalTokens = null
): bool {
    try {
        $schoolId = isset($user['school_id']) ? $user['school_id'] : null;
        $finalClassId = $classId;

        if ($studentId) {
            $student = Student::find($studentId);
            if ($student) {
                if (!$schoolId && !empty($student['school_id'])) {
                    $schoolId = $student['school_id'];
                }
                if ($finalClassId === null && !empty($student['class_id'])) {
                    $finalClassId = $student['class_id'];
                }
            }
        }

        $costDetailsJson = null;
        if (is_array($costDetails)) {
            $costDetailsJson = json_encode($costDetails, JSON_UNESCAPED_UNICODE);
        }

        $computedTotal = $inputTokens + $outputTokens;
        if ($totalTokens === null || $totalTokens <= 0) {
            $totalTokens = $computedTotal;
        }
        if ($totalTokens < max($inputTokens, $outputTokens)) {
            $totalTokens = $computedTotal;
        }
        $divergenceThreshold = max(15, (int)($computedTotal * 0.20));
        if ($totalTokens !== null && $computedTotal > 0
            && abs($totalTokens - $computedTotal) > $divergenceThreshold) {
            logger_warn(sprintf(
                '[USAGE] Token divergence: provider=%d, computed=%d (in=%d, out=%d, threshold=%d)',
                $totalTokens, $computedTotal, $inputTokens, $outputTokens, $divergenceThreshold
            ));
        }

        AIUsage::create([
            'user_id'             => isset($user['id']) ? $user['id'] : null,
            'student_id'          => $studentId,
            'school_id'           => $schoolId,
            'class_id'            => $finalClassId,
            'ai_profile_id'       => $aiProfileId,
            'conversation_id'     => $conversationId,
            'message_id'          => $messageId,
            'provider'            => $provider,
            'model'               => $model,
            'input_tokens'        => $inputTokens,
            'output_tokens'       => $outputTokens,
            'total_tokens'        => $totalTokens,
            'cached_tokens'       => $cachedTokens,
            'request_duration_ms' => $durationMs,
            'request_status'      => $status,
            'estimated_cost'      => $cost,
            'cost'                => $cost,
            'cost_details'        => $costDetailsJson,
            'quota_units'         => max(1, $quotaUnits),
        ]);

        return true;

    } catch (Throwable $e) {
        logger_warn('[USAGE] Failed to record: ' . $e->getMessage());
        return false;
    }
}


// =====================================================================
// GapGPT MODEL CATALOG HELPERS
// =====================================================================

function get_gapgpt_model_catalog(): array
{
    try {
        $raw = get_setting('gapgpt_model_catalog', null);
        if ($raw === null) return [];
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
    } catch (Throwable $e) {
        logger_warn('[GAPGPT] Failed to read model catalog: ' . $e->getMessage());
    }
    return [];
}

function find_gapgpt_model_by_api_id(string $apiId): ?array
{
    $catalog = get_gapgpt_model_catalog();
    foreach ($catalog as $m) {
        if (!is_array($m)) continue;
        if (($m['api_id'] ?? null) === $apiId && ($m['verified'] ?? false) === true) {
            return $m;
        }
    }
    return null;
}

function get_verified_gapgpt_models(): array
{
    $out = [];
    foreach (get_gapgpt_model_catalog() as $m) {
        if (!is_array($m)) continue;
        $apiId = $m['api_id'] ?? null;
        $verified = $m['verified'] ?? false;
        if ($apiId === null || $apiId === '' || $verified !== true) continue;
        $out[] = [
            'key'    => $m['key']    ?? '',
            'label'  => $m['label']  ?? '',
            'api_id' => $apiId,
        ];
    }
    return $out;
}


// =====================================================================
// AUDIT BATCH HELPER (unused — for future use)
// =====================================================================

function danova_batch_audit_flush(array $entries): int
{
    if (empty($entries)) return 0;

    $pdo = db();
    $count = 0;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (id, actor_user_id, action, entity_type, entity_id,
                 log_metadata, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;
        $now = utcnow();

        foreach ($entries as $entry) {
            $actor  = $entry['actor']  ?? null;
            $action = $entry['action'] ?? 'unknown';
            $eType  = $entry['entity_type'] ?? null;
            $eId    = $entry['entity_id']   ?? null;
            $meta   = $entry['meta'] ?? null;

            $metaJson = is_array($meta)
                ? json_encode($meta, JSON_UNESCAPED_UNICODE)
                : null;

            $stmt->execute([
                generate_uuid(),
                $actor['id'] ?? null,
                $action,
                $eType,
                $eId,
                $metaJson,
                $ip,
                $ua,
                $now,
            ]);
            $count++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('logger_warn')) {
            logger_warn('[AUDIT-BATCH] flush failed: ' . $e->getMessage());
        }
        return 0;
    }

    return $count;
}

// =====================================================================
// END OF services.php (v2.4.2 — CRITICAL FIX: httpPostJson + quota_version)
// =====================================================================