<?php
/**
 * =====================================================================
 * DANOVA — School Admin Test Accounts Module
 * =====================================================================
 * File: test_accounts.php
 * Version: 1.0.0
 * Purpose: Isolated test account management for Danova administrators
 * ---------------------------------------------------------------------
 * COMPLETELY ADDITIVE — does not modify any existing function/class.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('DANOVA_FRONT_CONTROLLER')) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'detail' => 'Direct access denied'], JSON_UNESCAPED_UNICODE));
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/auth.php';


// =====================================================================
// SECTION 1: TEST ACCOUNT MODEL
// =====================================================================

class TestAccount extends Model
{
    protected static string $table = 'test_accounts';
    protected static array $fillable = [
        'name', 'username', 'password_hash', 'school_id', 'ai_profile_id',
        'expires_at', 'message_limit', 'messages_used', 'status',
        'description', 'created_by', 'revoked_at', 'deleted_at',
        'last_login_at', 'session_version',
    ];

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_REVOKED   = 'revoked';
    public const STATUS_DELETED   = 'deleted';

    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_EXHAUSTED,
        self::STATUS_REVOKED,
        self::STATUS_DELETED,
    ];

    public static function findByUsername(string $username): ?array
    {
        // ═══════════════════════════════════════════════════════════════
        // DANOVA_FIX_FIND_BY_USERNAME_ACTIVE_ONLY
        // فقط حساب‌های غیر حذف‌شده را برمی‌گرداند.
        // ═══════════════════════════════════════════════════════════════
        $stmt = db()->prepare(
            "SELECT * FROM test_accounts
             WHERE username = ? AND status != 'deleted'
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ? self::cast($row) : null;
    }

    public static function listPaged(
        ?string $search = null,
        ?string $status = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM test_accounts WHERE 1=1';
        $params = [];

        if (!$includeDeleted) {
            $sql .= " AND status != 'deleted'";
        }

        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (name LIKE ? OR username LIKE ?)';
            $params[] = '%' . trim($search) . '%';
            $params[] = '%' . trim($search) . '%';
        }

        if ($status !== null && in_array($status, self::VALID_STATUSES, true)) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize
              . ' OFFSET ' . (($page - 1) * $pageSize);

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function deriveStatus(array $account): string
    {
        if ($account['status'] === self::STATUS_REVOKED) return self::STATUS_REVOKED;
        if ($account['status'] === self::STATUS_DELETED) return self::STATUS_DELETED;
        if (!empty($account['deleted_at'])) return self::STATUS_DELETED;
        if (!empty($account['revoked_at'])) return self::STATUS_REVOKED;

        $now = utcnow();
        if ($account['expires_at'] <= $now) return self::STATUS_EXPIRED;

        if ((int)$account['messages_used'] >= (int)$account['message_limit']) {
            return self::STATUS_EXHAUSTED;
        }

        return self::STATUS_ACTIVE;
    }

    public static function isUsable(array $account): bool
    {
        return self::deriveStatus($account) === self::STATUS_ACTIVE;
    }

    public static function remainingMessages(array $account): int
    {
        return max(0, (int)$account['message_limit'] - (int)$account['messages_used']);
    }

    public static function remainingSeconds(array $account): int
    {
        $expiresTs = strtotime($account['expires_at'] . ' UTC');
        if ($expiresTs === false) return 0;
        return max(0, $expiresTs - time());
    }

    public static function adminShape(array $a): array
    {
        $effectiveStatus = self::deriveStatus($a);
        $remainingSeconds = self::remainingSeconds($a);

        $aiName = null;
        if (!empty($a['ai_profile_id'])) {
            $ai = AIProfile::find($a['ai_profile_id']);
            $aiName = $ai ? $ai['name'] : null;
        }

        $schoolName = null;
        if (!empty($a['school_id'])) {
            $school = School::find($a['school_id']);
            $schoolName = $school ? $school['name'] : null;
        }

        return [
            'id'                 => $a['id'],
            'name'               => $a['name'],
            'username'           => $a['username'],
            'school_id'          => $a['school_id'],
            'school_name'        => $schoolName,
            'ai_profile_id'      => $a['ai_profile_id'],
            'ai_profile_name'    => $aiName,
            'expires_at'         => self::iso($a['expires_at']),
            'remaining_seconds'  => $remainingSeconds,
            'remaining_human'    => self::formatRemaining($remainingSeconds),
            'message_limit'      => (int)$a['message_limit'],
            'messages_used'      => (int)$a['messages_used'],
            'messages_remaining' => self::remainingMessages($a),
            'status'             => $effectiveStatus,
            'stored_status'      => $a['status'],
            'description'        => $a['description'],
            'created_by'         => $a['created_by'],
            'created_at'         => self::iso($a['created_at']),
            'updated_at'         => self::iso($a['updated_at']),
            'revoked_at'         => self::iso($a['revoked_at']),
            'deleted_at'         => self::iso($a['deleted_at']),
            'last_login_at'      => self::iso($a['last_login_at']),
        ];
    }

    public static function publicShape(array $a): array
    {
        return [
            'id'                 => $a['id'],
            'name'               => $a['name'],
            'status'             => self::deriveStatus($a),
            'remaining_seconds'  => self::remainingSeconds($a),
            'messages_remaining' => self::remainingMessages($a),
        ];
    }

    private static function formatRemaining(int $seconds): string
    {
        if ($seconds <= 0) return 'منقضی';
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) return $hours . ' ساعت و ' . $minutes . ' دقیقه';
        if ($minutes > 0) return $minutes . ' دقیقه';
        return 'کمتر از یک دقیقه';
    }

    public static function getSummary(): array
    {
        $pdo = db();
        $now = utcnow();
        $soonThreshold = gmdate('Y-m-d H:i:s', time() + 3600);

        $sql = "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' AND expires_at > ? AND messages_used < message_limit THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status IN ('expired','exhausted') OR (status = 'active' AND (expires_at <= ? OR messages_used >= message_limit)) THEN 1 ELSE 0 END) AS expired_or_exhausted,
            SUM(CASE WHEN status = 'active' AND expires_at > ? AND expires_at <= ? AND messages_used < message_limit THEN 1 ELSE 0 END) AS approaching_expiry,
            COALESCE(SUM(messages_used), 0) AS total_messages_consumed
            FROM test_accounts
            WHERE status != 'deleted'";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$now, $now, $now, $soonThreshold]);
        $row = $stmt->fetch();

        return [
            'total'                   => (int)($row['total'] ?? 0),
            'active'                  => (int)($row['active_count'] ?? 0),
            'expired_or_exhausted'    => (int)($row['expired_or_exhausted'] ?? 0),
            'approaching_expiry'      => (int)($row['approaching_expiry'] ?? 0),
            'total_messages_consumed' => (int)($row['total_messages_consumed'] ?? 0),
        ];
    }
}


// =====================================================================
// SECTION 2: TEST CONVERSATION MODEL
// =====================================================================

class TestConversation extends Model
{
    protected static string $table = 'test_conversations';
    protected static array $fillable = ['test_account_id', 'ai_profile_id', 'title'];

    public static function listByAccount(string $accountId, int $limit = 50): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM test_conversations WHERE test_account_id = ?
             ORDER BY updated_at DESC LIMIT ' . (int)$limit
        );
        $stmt->execute([$accountId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function publicShape(array $c): array
    {
        return [
            'id'            => $c['id'],
            'title'         => $c['title'] ?: 'گفتگوی تست',
            'ai_profile_id' => $c['ai_profile_id'],
            'created_at'    => self::iso($c['created_at']),
            'updated_at'    => self::iso($c['updated_at']),
        ];
    }
}


// =====================================================================
// SECTION 3: TEST MESSAGE MODEL
// =====================================================================

class TestMessage extends Model
{
    protected static string $table = 'test_messages';
    protected static array $fillable = [
        'conversation_id', 'test_account_id', 'role', 'content',
        'input_tokens', 'output_tokens', 'total_tokens',
    ];

    public static function listByConversation(string $conversationId, int $limit = 100): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM test_messages WHERE conversation_id = ?
             ORDER BY sequence_number ASC LIMIT ' . (int)$limit
        );
        $stmt->execute([$conversationId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function publicShape(array $m): array
    {
        return [
            'id'         => $m['id'],
            'role'       => $m['role'],
            'content'    => $m['content'],
            'created_at' => self::iso($m['created_at']),
        ];
    }
}


// =====================================================================
// SECTION 4: TEST ACCOUNT SERVICE
// =====================================================================

class TestAccountService
{
    public static function create(
        array $admin,
        string $name,
        string $username,
        string $password,
        ?string $schoolId,
        string $aiProfileId,
        string $expiresAt,
        int $messageLimit,
        ?string $description
    ): array {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 200) {
            return ['ok' => false, 'error' => 'نام پنل تست باید بین ۲ تا ۲۰۰ کاراکتر باشد.'];
        }

        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,100}$/', $username)) {
            return ['ok' => false, 'error' => 'نام کاربری باید ۳ تا ۱۰۰ کاراکتر و شامل حروف انگلیسی، اعداد، _، . و - باشد.'];
        }

        if (User::findByUsername($username)) {
            return ['ok' => false, 'error' => 'این نام کاربری قبلاً در سیستم استفاده شده است.'];
        }
        if (TestAccount::findByUsername($username)) {
            return ['ok' => false, 'error' => 'این نام کاربری قبلاً برای یک پنل تست استفاده شده است.'];
        }

        [$pwdOk, $pwdErr] = validate_password_strength($password);
        if (!$pwdOk) return ['ok' => false, 'error' => $pwdErr];

        $aiProfile = AIProfile::find($aiProfileId);
        if (!$aiProfile || $aiProfile['deleted_at'] !== null || (int)$aiProfile['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'پروفایل AI انتخاب‌شده معتبر یا فعال نیست.'];
        }

        if ($schoolId !== null && $schoolId !== '') {
            $school = School::find($schoolId);
            if (!$school || $school['deleted_at'] !== null) {
                return ['ok' => false, 'error' => 'مدرسه انتخاب‌شده معتبر نیست.'];
            }

            // ═══════════════════════════════════════════════════════════
            // DANOVA_FIX_TEST_ACCOUNT_TARGETING
            // چک سازگاری AI با مدرسه (سطح + جنسیت)
            // ═══════════════════════════════════════════════════════════
            $__aiLevel  = $aiProfile['target_school_type'] ?? null;
            $__aiGender = $aiProfile['target_gender']      ?? 'both';
            $__sLevel   = $school['school_type']           ?? null;
            $__sGender  = $school['gender']                ?? null;

            if ($__aiLevel !== null && $__sLevel !== null && $__aiLevel !== $__sLevel) {
                return [
                    'ok'    => false,
                    'error' => 'AI انتخاب‌شده برای سطح «' . $__aiLevel
                             . '» است ولی مدرسه «' . $__sLevel . '» است.',
                ];
            }
            if ($__aiGender !== 'both' && $__sGender !== null && $__aiGender !== $__sGender) {
                return [
                    'ok'    => false,
                    'error' => 'AI انتخاب‌شده برای جنسیت «' . $__aiGender
                             . '» است ولی مدرسه «' . $__sGender . '» است.',
                ];
            }
        } else {
            $schoolId = null;
        }

        $maxHours = (int)get_setting('test_account_max_expiry_hours', 72);
        if ($maxHours < 1) $maxHours = 72;

        $maxExpiry = gmdate('Y-m-d H:i:s', time() + ($maxHours * 3600));
        $now = utcnow();

        if ($expiresAt <= $now) {
            return ['ok' => false, 'error' => 'زمان انقضا باید در آینده باشد.'];
        }
        if ($expiresAt > $maxExpiry) {
            return ['ok' => false, 'error' => "حداکثر زمان انقضا {$maxHours} ساعت است."];
        }

        $maxMessages = (int)get_setting('test_account_max_messages', 50);
        if ($maxMessages < 1) $maxMessages = 50;
        if ($messageLimit < 1) $messageLimit = 10;
        if ($messageLimit > $maxMessages) {
            return ['ok' => false, 'error' => "حداکثر تعداد پیام {$maxMessages} است."];
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'این نام کاربری قبلاً در سیستم استفاده شده است.'];
            }

            $stmt = $pdo->prepare('SELECT id FROM test_accounts WHERE username = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'این نام کاربری قبلاً برای یک پنل تست استفاده شده است.'];
            }

            $id = generate_uuid();

            $pdo->prepare(
                'INSERT INTO test_accounts
                    (id, name, username, password_hash, school_id, ai_profile_id,
                     expires_at, message_limit, messages_used, status,
                     description, created_by, created_at, updated_at, session_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0)'
            )->execute([
                $id, $name, $username, hash_password($password),
                $schoolId, $aiProfileId, $expiresAt, $messageLimit,
                TestAccount::STATUS_ACTIVE,
                $description !== null && trim($description) !== ''
                    ? mb_substr(trim($description), 0, 500) : null,
                $admin['id'], $now, $now,
            ]);

            $pdo->commit();

            log_audit($admin, 'CREATE_TEST_ACCOUNT', 'test_account', $id, [
                'name'          => $name,
                'username'      => $username,
                'school_id'     => $schoolId,
                'ai_profile_id' => $aiProfileId,
                'expires_at'    => $expiresAt,
                'message_limit' => $messageLimit,
            ]);

            return ['ok' => true, 'id' => $id];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logger_error('[TEST-ACCOUNT] Create failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'خطا در ایجاد پنل تست.'];
        }
    }

    public static function authenticate(string $username, string $password): array
    {
        $account = TestAccount::findByUsername($username);

        if (!$account) {
            verify_password($password, dummy_hash());
            return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور صحیح نیست.'];
        }

        if (!verify_password($password, $account['password_hash'])) {
            return ['ok' => false, 'error' => 'نام کاربری یا رمز عبور صحیح نیست.'];
        }

        $status = TestAccount::deriveStatus($account);
        if ($status !== TestAccount::STATUS_ACTIVE) {
            $messages = [
                TestAccount::STATUS_EXPIRED   => 'این پنل تست منقضی شده است.',
                TestAccount::STATUS_EXHAUSTED => 'سقف پیام‌های این پنل تست تکمیل شده است.',
                TestAccount::STATUS_REVOKED   => 'این پنل تست لغو شده است.',
                TestAccount::STATUS_DELETED   => 'این پنل تست حذف شده است.',
            ];
            return ['ok' => false, 'error' => $messages[$status] ?? 'این پنل تست در دسترس نیست.'];
        }

        try {
            db()->prepare('UPDATE test_accounts SET last_login_at = ? WHERE id = ?')
                ->execute([utcnow(), $account['id']]);
        } catch (Throwable $e) {}

        return ['ok' => true, 'account' => $account];
    }

    public static function consumeMessage(string $accountId): array
    {
        $pdo = db();
        $attempts = 0;

        while ($attempts < 3) {
            $attempts++;
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT * FROM test_accounts WHERE id = ? FOR UPDATE');
                $stmt->execute([$accountId]);
                $account = $stmt->fetch();

                if (!$account) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'پنل تست یافت نشد.'];
                }

                $account = TestAccount::cast($account);
                $status = TestAccount::deriveStatus($account);

                if ($status !== TestAccount::STATUS_ACTIVE) {
                    $pdo->rollBack();
                    // ═══════════════════════════════════════════════════
                    // DANOVA_FIX_CONSUME_SPECIFIC_ERRORS
                    // ═══════════════════════════════════════════════════
                    $__statusMessages = [
                        TestAccount::STATUS_EXPIRED   => 'این پنل تست منقضی شده است.',
                        TestAccount::STATUS_EXHAUSTED => 'سقف پیام‌های این پنل تست تکمیل شده است.',
                        TestAccount::STATUS_REVOKED   => 'این پنل تست لغو شده است.',
                        TestAccount::STATUS_DELETED   => 'این پنل تست حذف شده است.',
                    ];
                    return [
                        'ok'    => false,
                        'error' => $__statusMessages[$status] ?? 'این پنل تست در دسترس نیست.',
                        'status'=> $status,
                    ];
                }

                $used = (int)$account['messages_used'];
                $limit = (int)$account['message_limit'];

                if ($used >= $limit) {
                    $pdo->prepare("UPDATE test_accounts SET status = 'exhausted', updated_at = ? WHERE id = ?")
                        ->execute([utcnow(), $accountId]);
                    $pdo->commit();
                    return ['ok' => false, 'error' => 'سقف پیام‌های این پنل تست تکمیل شده است.'];
                }

                $newUsed = $used + 1;
                $newStatus = $newUsed >= $limit ? 'exhausted' : 'active';

                $pdo->prepare(
                    'UPDATE test_accounts SET messages_used = ?, status = ?, updated_at = ? WHERE id = ?'
                )->execute([$newUsed, $newStatus, utcnow(), $accountId]);

                $pdo->commit();
                return ['ok' => true, 'remaining' => max(0, $limit - $newUsed)];

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $driverCode = is_array($e->errorInfo) && isset($e->errorInfo[1])
                    ? (int)$e->errorInfo[1] : 0;
                if (in_array($driverCode, [1205, 1213], true)) {
                    usleep(100000 * $attempts);
                    continue;
                }
                logger_error('[TEST-ACCOUNT] consumeMessage failed: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'خطا در ثبت مصرف پیام.'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                logger_error('[TEST-ACCOUNT] consumeMessage failed: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'خطا در ثبت مصرف پیام.'];
            }
        }

        return ['ok' => false, 'error' => 'سیستم مشغول است. لطفاً دوباره تلاش کنید.'];
    }

    public static function revoke(array $admin, string $accountId): array
    {
        $account = TestAccount::find($accountId);
        if (!$account) return ['ok' => false, 'error' => 'پنل تست یافت نشد.'];
        if ($account['status'] === TestAccount::STATUS_DELETED) {
            return ['ok' => false, 'error' => 'این پنل تست قبلاً حذف شده است.'];
        }

        $now = utcnow();
        db()->prepare(
            "UPDATE test_accounts
             SET status = 'revoked', revoked_at = ?, session_version = session_version + 1, updated_at = ?
             WHERE id = ?"
        )->execute([$now, $now, $accountId]);

        log_audit($admin, 'REVOKE_TEST_ACCOUNT', 'test_account', $accountId, [
            'username' => $account['username'],
        ]);

        return ['ok' => true];
    }

    public static function extendExpiration(array $admin, string $accountId, string $newExpiresAt): array
    {
        $account = TestAccount::find($accountId);
        if (!$account) return ['ok' => false, 'error' => 'پنل تست یافت نشد.'];

        $maxHours = (int)get_setting('test_account_max_expiry_hours', 72);
        if ($maxHours < 1) $maxHours = 72;

        $maxExpiry = gmdate('Y-m-d H:i:s', time() + ($maxHours * 3600));
        $now = utcnow();

        if ($newExpiresAt <= $now) {
            return ['ok' => false, 'error' => 'زمان انقضا باید در آینده باشد.'];
        }
        if ($newExpiresAt > $maxExpiry) {
            return ['ok' => false, 'error' => "حداکثر زمان انقضا {$maxHours} ساعت است."];
        }

        db()->prepare(
            'UPDATE test_accounts SET expires_at = ?, updated_at = ? WHERE id = ?'
        )->execute([$newExpiresAt, utcnow(), $accountId]);

        log_audit($admin, 'EXTEND_TEST_ACCOUNT', 'test_account', $accountId, [
            'new_expires_at' => $newExpiresAt,
        ]);

        return ['ok' => true];
    }

    public static function resetPassword(array $admin, string $accountId, string $newPassword): array
    {
        $account = TestAccount::find($accountId);
        if (!$account) return ['ok' => false, 'error' => 'پنل تست یافت نشد.'];

        [$ok, $err] = validate_password_strength($newPassword);
        if (!$ok) return ['ok' => false, 'error' => $err];

        db()->prepare(
            'UPDATE test_accounts SET password_hash = ?, session_version = session_version + 1, updated_at = ?
             WHERE id = ?'
        )->execute([hash_password($newPassword), utcnow(), $accountId]);

        log_audit($admin, 'RESET_TEST_ACCOUNT_PASSWORD', 'test_account', $accountId);

        return ['ok' => true];
    }

    public static function softDelete(array $admin, string $accountId): array
    {
        $account = TestAccount::find($accountId);
        if (!$account) return ['ok' => false, 'error' => 'پنل تست یافت نشد.'];

        $now = utcnow();

        // ═══════════════════════════════════════════════════════════════
        // DANOVA_FIX_SOFT_DELETE_FREE_USERNAME
        // username را آزاد می‌کنیم تا بتوان همان username را دوباره ساخت.
        // ═══════════════════════════════════════════════════════════════
        $freedUsername = 'deleted_' . $accountId . '_' . $account['username'];
        if (mb_strlen($freedUsername) > 100) {
            $freedUsername = mb_substr($freedUsername, 0, 100);
        }

        db()->prepare(
            "UPDATE test_accounts
             SET status = 'deleted',
                 deleted_at = ?,
                 username = ?,
                 session_version = session_version + 1,
                 updated_at = ?
             WHERE id = ?"
        )->execute([$now, $freedUsername, $now, $accountId]);

        log_audit($admin, 'DELETE_TEST_ACCOUNT', 'test_account', $accountId, [
            'original_username' => $account['username'],
            'freed_username'    => $freedUsername,
        ]);

        return ['ok' => true];
    }
}


// =====================================================================
// SECTION 5: API HANDLERS — ADMIN
// =====================================================================

function api_admin_test_accounts_list(): void
{
    auth_require_admin();

    $search = query('search', null);
    $status = query('status', null);
    $page = query_int('page', 1, 1);
    $pageSize = query_int('page_size', 25, 1, 100);

    $data = TestAccount::listPaged(
        is_string($search) ? $search : null,
        is_string($status) ? $status : null,
        false,
        $page,
        $pageSize
    );

    $out = [];
    foreach ($data['items'] as $acc) {
        $out[] = TestAccount::adminShape($acc);
    }

    json_ok(array_merge(
        ['test_accounts' => $out],
        paginate($data['total'], $page, $pageSize)
    ));
}

function api_admin_test_accounts_summary(): void
{
    auth_require_admin();
    json_ok(['summary' => TestAccount::getSummary()]);
}

function api_admin_test_accounts_get(string $id): void
{
    auth_require_admin();
    $acc = TestAccount::find($id);
    if (!$acc) json_error('پنل تست یافت نشد.', 404);
    json_ok(['test_account' => TestAccount::adminShape($acc)]);
}

function api_admin_test_accounts_create(): void
{
    auth_verify_csrf();
    $admin = auth_require_admin();
    auth_require_password_changed();

    if (function_exists('enforce_max_json_size')) {
        enforce_max_json_size(32 * 1024);
    }

    $body = request_json();

    $name          = trim((string)($body['name'] ?? ''));
    $username      = trim((string)($body['username'] ?? ''));
    $password      = (string)($body['password'] ?? '');
    $schoolId      = isset($body['school_id']) && $body['school_id'] !== '' ? (string)$body['school_id'] : null;
    $aiProfileId   = trim((string)($body['ai_profile_id'] ?? ''));
    $expiresAt     = trim((string)($body['expires_at'] ?? ''));
    $messageLimit  = (int)($body['message_limit'] ?? 10);
    $description   = isset($body['description']) ? trim((string)$body['description']) : null;

    if ($name === '') json_error('نام پنل تست الزامی است.', 400);
    if ($username === '') json_error('نام کاربری الزامی است.', 400);
    if ($password === '') json_error('رمز عبور الزامی است.', 400);
    if ($aiProfileId === '') json_error('انتخاب AI Profile الزامی است.', 400);
    if ($expiresAt === '') json_error('زمان انقضا الزامی است.', 400);

    // Normalize expiration to UTC
    try {
        $dt = new DateTime($expiresAt, new DateTimeZone('UTC'));
        $expiresAtUtc = $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        json_error('فرمت زمان انقضا نامعتبر است.', 400);
    }

    $result = TestAccountService::create(
        $admin, $name, $username, $password, $schoolId,
        $aiProfileId, $expiresAtUtc, $messageLimit, $description
    );

    if (!$result['ok']) {
        json_error($result['error'], 400);
    }

    $created = TestAccount::find($result['id']);
    $shape = TestAccount::adminShape($created);

    // Include the password ONCE in the response
    $shape['password'] = $password;

    // ═══════════════════════════════════════════════════════════════════
    // DANOVA_FIX_NO_STORE_PASSWORD_RESPONSE
    // جلوگیری از کش شدن response حاوی رمز خام
    // ═══════════════════════════════════════════════════════════════════
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    json_response([
        'success'      => true,
        'message'      => 'پنل تست با موفقیت ایجاد شد.',
        'test_account' => $shape,
    ], 201);
}

function api_admin_test_accounts_revoke(string $id): void
{
    auth_verify_csrf();
    $admin = auth_require_admin();
    auth_require_password_changed();

    $result = TestAccountService::revoke($admin, $id);
    if (!$result['ok']) json_error($result['error'], 400);

    json_ok(['message' => 'پنل تست لغو شد.']);
}

function api_admin_test_accounts_extend(string $id): void
{
    auth_verify_csrf();
    $admin = auth_require_admin();
    auth_require_password_changed();

    $newExpiresAt = trim((string)request_input('expires_at', ''));
    if ($newExpiresAt === '') json_error('زمان انقضای جدید الزامی است.', 400);

    try {
        $dt = new DateTime($newExpiresAt, new DateTimeZone('UTC'));
        $newExpiresAtUtc = $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        json_error('فرمت زمان نامعتبر است.', 400);
    }

    $result = TestAccountService::extendExpiration($admin, $id, $newExpiresAtUtc);
    if (!$result['ok']) json_error($result['error'], 400);

    json_ok(['message' => 'زمان انقضا تمدید شد.']);
}

function api_admin_test_accounts_reset_password(string $id): void
{
    auth_verify_csrf();
    $admin = auth_require_admin();
    auth_require_password_changed();

    $newPassword = (string)request_input('new_password', '');
    if ($newPassword === '') json_error('رمز جدید الزامی است.', 400);

    $result = TestAccountService::resetPassword($admin, $id, $newPassword);
    if (!$result['ok']) json_error($result['error'], 400);

    json_ok(['message' => 'رمز عبور پنل تست تغییر کرد.']);
}

function api_admin_test_accounts_soft_delete(string $id): void
{
    auth_verify_csrf();
    $admin = auth_require_admin();
    auth_require_password_changed();

    $result = TestAccountService::softDelete($admin, $id);
    if (!$result['ok']) json_error($result['error'], 400);

    json_ok(['message' => 'پنل تست حذف شد.']);
}


// =====================================================================
// SECTION 6: API HANDLERS — TEST ACCOUNT SELF-SERVICE
// =====================================================================

function _test_account_current(): ?array
{
    $accountId = $_SESSION['test_account_id'] ?? null;
    if (!$accountId) return null;

    $account = TestAccount::find($accountId);
    if (!$account) return null;

    // Verify session version
    $sessionSv = (int)($_SESSION['test_account_sv'] ?? 0);
    $accountSv = (int)($account['session_version'] ?? 0);
    if ($sessionSv !== $accountSv) {
        _test_account_destroy_session();
        return null;
    }

    // Verify status
    if (!TestAccount::isUsable($account)) {
        return null;
    }

    return $account;
}

function _test_account_require(): array
{
    $acc = _test_account_current();
    if (!$acc) {
        json_error('احراز هویت نشده یا پنل تست منقضی شده است.', 401);
    }
    return $acc;
}

function _test_account_destroy_session(): void
{
    unset(
        $_SESSION['test_account_id'],
        $_SESSION['test_account_sv']
    );
}

function api_test_auth_login(): void
{
    if (function_exists('enforce_max_json_size')) {
        enforce_max_json_size(64 * 1024);
    }

    $body = request_json();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        json_error('نام کاربری و رمز عبور الزامی است.', 400);
    }

    if (function_exists('rate_limit_guard')) {
        rate_limit_guard('test_login', 10, 300, null,
            'تعداد تلاش‌های ورود بیش از حد مجاز است.');
    }

    $result = TestAccountService::authenticate($username, $password);
    if (!$result['ok']) {
        json_error($result['error'], 401);
    }

    $account = $result['account'];

    session_regenerate_id(true);

    $_SESSION['test_account_id'] = $account['id'];
    $_SESSION['test_account_sv'] = (int)($account['session_version'] ?? 0);

    json_ok([
        'test_account' => TestAccount::publicShape($account),
        'message'      => 'ورود موفق',
    ]);
}

function api_test_auth_logout(): void
{
    auth_verify_csrf();
    _test_account_destroy_session();
    json_ok(['message' => 'خروج موفق']);
}

function api_test_auth_me(): void
{
    $account = _test_account_require();

    $aiName = null;
    if (!empty($account['ai_profile_id'])) {
        $ai = AIProfile::find($account['ai_profile_id']);
        $aiName = $ai ? $ai['name'] : null;
    }

    json_ok([
        'test_account' => array_merge(TestAccount::publicShape($account), [
            'ai_profile_id'   => $account['ai_profile_id'],
            'ai_profile_name' => $aiName,
            'message_limit'   => (int)$account['message_limit'],
            'messages_used'   => (int)$account['messages_used'],
            'expires_at'      => str_replace(' ', 'T', $account['expires_at']),
        ]),
    ]);
}

function api_test_chat_list_conversations(): void
{
    $account = _test_account_require();
    $convs = TestConversation::listByAccount($account['id'], 50);

    json_ok([
        'conversations' => array_map([TestConversation::class, 'publicShape'], $convs),
    ]);
}

function api_test_chat_create_conversation(): void
{
    auth_verify_csrf();
    $account = _test_account_require();

    $id = TestConversation::create([
        'test_account_id' => $account['id'],
        'ai_profile_id'   => $account['ai_profile_id'],
        'title'           => 'گفتگوی تست',
    ]);

    $conv = TestConversation::find($id);

    json_response([
        'success'      => true,
        'conversation' => TestConversation::publicShape($conv),
    ], 201);
}

function api_test_chat_get_conversation(string $id): void
{
    $account = _test_account_require();

    $conv = TestConversation::find($id);
    if (!$conv || $conv['test_account_id'] !== $account['id']) {
        json_error('گفتگو یافت نشد.', 404);
    }

    $messages = TestMessage::listByConversation($id, 200);

    json_ok([
        'conversation' => TestConversation::publicShape($conv),
        'messages'     => array_map([TestMessage::class, 'publicShape'], $messages),
    ]);
}

function api_test_chat_send_message(string $conversationId): void
{
    auth_verify_csrf();
    $account = _test_account_require();

    // Block if expired mid-session
    $fresh = TestAccount::find($account['id']);
    if (!$fresh || !TestAccount::isUsable($fresh)) {
        _test_account_destroy_session();
        json_error('پنل تست منقضی یا غیرفعال شده است.', 403);
    }

    $conv = TestConversation::find($conversationId);
    if (!$conv || $conv['test_account_id'] !== $account['id']) {
        json_error('گفتگو یافت نشد.', 404);
    }

    $body = request_json();
    $content = trim((string)($body['content'] ?? ''));
    if ($content === '') json_error('متن پیام الزامی است.', 400);
    if (mb_strlen($content) > 4000) json_error('پیام بیش از حد طولانی است.', 400);

    // ── Consume message slot (atomic) ──
    $consume = TestAccountService::consumeMessage($account['id']);
    if (!$consume['ok']) {
        json_error($consume['error'], 403);
    }

    try {
        // Save user message
        $userMsgId = TestMessage::create([
            'conversation_id' => $conversationId,
            'test_account_id' => $account['id'],
            'role'            => 'user',
            'content'         => $content,
        ]);

        // Get AI profile
        $aiProfile = AIProfile::find($account['ai_profile_id']);
        if (!$aiProfile) {
            json_error('پروفایل AI در دسترس نیست.', 500);
        }

        // Build messages history (last 10)
        $recent = TestMessage::listByConversation($conversationId, 20);
        $aiMessages = [];
        foreach ($recent as $m) {
            if (!in_array($m['role'], ['user', 'assistant'], true)) continue;
            $aiMessages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $systemPrompt = $aiProfile['system_prompt'] ?: 'تو یک دستیار آموزشی هستی.';
        $systemPrompt .= "\n\n⚠️ این یک محیط تست است. پاسخ‌ها ممکن است برای اهداف آموزشی محدود باشند.";

        // Generate AI response
        $aiResult = ai_service()->generateResponse($aiProfile, $systemPrompt, $aiMessages);

        if (($aiResult['status'] ?? 'success') !== 'success' || empty($aiResult['content'])) {
            // ═══════════════════════════════════════════════════════════
            // DANOVA_FIX_REFUND_TEST_MESSAGE_ON_AI_FAIL
            // اگر AI پاسخ نداد، پیام مصرف‌شده را برگردان.
            // ═══════════════════════════════════════════════════════════
            try {
                db()->prepare(
                    "UPDATE test_accounts
                     SET messages_used = GREATEST(0, messages_used - 1),
                         status = CASE
                             WHEN status = 'exhausted' THEN 'active'
                             ELSE status
                         END,
                         updated_at = ?
                     WHERE id = ?"
                )->execute([utcnow(), $account['id']]);
            } catch (Throwable $__e) {
                logger_warn('[TEST-CHAT] refund slot failed: ' . $__e->getMessage());
            }

            try {
                db()->prepare(
                    "DELETE FROM test_messages WHERE id = ? AND test_account_id = ?"
                )->execute([$userMsgId, $account['id']]);
            } catch (Throwable $__e) {
                logger_warn('[TEST-CHAT] user message rollback failed: ' . $__e->getMessage());
            }

            json_error('پاسخ از سرور AI دریافت نشد. لطفاً دوباره تلاش کنید.', 502);
        }

        $assistantContent = trim((string)$aiResult['content']);

        $assistantMsgId = TestMessage::create([
            'conversation_id' => $conversationId,
            'test_account_id' => $account['id'],
            'role'            => 'assistant',
            'content'         => $assistantContent,
            'input_tokens'    => (int)($aiResult['input_tokens'] ?? 0),
            'output_tokens'   => (int)($aiResult['output_tokens'] ?? 0),
            'total_tokens'    => (int)($aiResult['total_tokens'] ?? 0),
        ]);

        // Update conversation updated_at
        db()->prepare('UPDATE test_conversations SET updated_at = ? WHERE id = ?')
            ->execute([utcnow(), $conversationId]);

        $userMsg = TestMessage::find($userMsgId);
        $assistantMsg = TestMessage::find($assistantMsgId);

        json_ok([
            'user_message'      => TestMessage::publicShape($userMsg),
            'assistant_message' => TestMessage::publicShape($assistantMsg),
            'messages_remaining'=> $consume['remaining'],
        ]);

    } catch (Throwable $e) {
        logger_error('[TEST-CHAT] send_message failed: ' . $e->getMessage());
        json_error('خطا در پردازش پیام.', 500);
    }
}


// =====================================================================
// SECTION 7: CLEANUP (called by lifecycle cron)
// =====================================================================

/**
 * Cleanup expired test accounts.
 *
 * Called automatically by DataLifecycleManager::runFullCleanup().
 * Safe to call multiple times (idempotent).
 */
function danova_test_accounts_cleanup(): array
{
    $result = ['marked_expired' => 0, 'deleted' => 0, 'errors' => []];

    try {
        $now = utcnow();

        // Mark expired
        $stmt = db()->prepare(
            "UPDATE test_accounts
             SET status = 'expired', updated_at = ?
             WHERE status = 'active' AND expires_at <= ?"
        );
        $stmt->execute([$now, $now]);
        $result['marked_expired'] = $stmt->rowCount();

        // Mark exhausted (that weren't caught at consumption time)
        $stmt = db()->prepare(
            "UPDATE test_accounts
             SET status = 'exhausted', updated_at = ?
             WHERE status = 'active' AND messages_used >= message_limit"
        );
        $stmt->execute([$now]);
        $result['marked_expired'] += $stmt->rowCount();

        // Physical deletion after retention period
        $retentionHours = (int)get_setting('test_account_cleanup_retention_hours', 24);
        if ($retentionHours < 1) $retentionHours = 24;

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionHours * 3600));

        $pdo = db();
        $pdo->beginTransaction();

        // Delete old accounts (FK cascade will handle conversations/messages)
        $stmt = $pdo->prepare(
            "DELETE FROM test_accounts
             WHERE status IN ('expired','exhausted','revoked','deleted')
               AND updated_at < ?"
        );
        $stmt->execute([$cutoff]);
        $result['deleted'] = $stmt->rowCount();

        $pdo->commit();

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        logger_error('[TEST-CLEANUP] failed: ' . $e->getMessage());
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}
