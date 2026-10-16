<?php
/**
 * =====================================================================
 * DANOVA - AI-Powered Education Platform
 * =====================================================================
 * File: models.php
 * Version: 2.4.2 (FULL — Bug Fix: Parse Error + MySQL 'key' column)
 * Purpose: All data models + CRUD helpers
 * =====================================================================
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

require_once __DIR__ . '/config.php';


// =====================================================================
// BASE MODEL
// =====================================================================

abstract class Model
{
    protected static string $table = '';
    protected static array $fillable = [];
    protected static array $boolColumns = [];
    protected static array $jsonColumns = [];

    protected static array $_columnCache = [];

    public static function find(?string $id): ?array
    {
        if (!$id) return null;
        $stmt = db()->prepare('SELECT * FROM ' . static::$table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? static::cast($row) : null;
    }

    public static function findWhere(array $where, ?string $orderBy = null, ?int $limit = null): ?array
    {
        $rows = static::where($where, $orderBy, $limit, 0);
        return $rows[0] ?? null;
    }

    public static function where(
        array $where = [],
        ?string $orderBy = null,
        ?int $limit = null,
        int $offset = 0
    ): array {
        $sql = 'SELECT * FROM ' . static::$table;
        $params = [];

        if ($where) {
            $clauses = [];
            foreach ($where as $col => $val) {
                $safeCol = static::sanitizeColumnName($col);
                if ($safeCol === null) continue;

                if ($val === null) {
                    $clauses[] = "$safeCol IS NULL";
                } elseif (is_array($val)) {
                    if (empty($val)) {
                        $clauses[] = '1 = 0';
                        continue;
                    }
                    $ph = implode(',', array_fill(0, count($val), '?'));
                    $clauses[] = "$safeCol IN ($ph)";
                    foreach ($val as $v) $params[] = $v;
                } else {
                    $clauses[] = "$safeCol = ?";
                    $params[] = $val;
                }
            }
            if ($clauses) {
                $sql .= ' WHERE ' . implode(' AND ', $clauses);
            }
        }

        if ($orderBy) {
            $safeOrder = static::sanitizeOrderBy($orderBy);
            if ($safeOrder !== null) {
                $sql .= ' ORDER BY ' . $safeOrder;
            }
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
            if ($offset > 0) $sql .= ' OFFSET ' . (int)$offset;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return array_map([static::class, 'cast'], $stmt->fetchAll());
    }

    public static function count(array $where = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . static::$table;
        $params = [];
        if ($where) {
            $clauses = [];
            foreach ($where as $col => $val) {
                $safeCol = static::sanitizeColumnName($col);
                if ($safeCol === null) continue;

                if ($val === null) {
                    $clauses[] = "$safeCol IS NULL";
                } else {
                    $clauses[] = "$safeCol = ?";
                    $params[] = $val;
                }
            }
            if ($clauses) {
                $sql .= ' WHERE ' . implode(' AND ', $clauses);
            }
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    public static function raw(string $sql, array $params = []): array
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return array_map([static::class, 'cast'], $stmt->fetchAll());
    }

    public static function create(array $data): string
    {
        $data['id'] = $data['id'] ?? generate_uuid();
        $now = utcnow();
        if (static::hasColumn('created_at') && empty($data['created_at'])) {
            $data['created_at'] = $now;
        }
        if (static::hasColumn('updated_at') && empty($data['updated_at'])) {
            $data['updated_at'] = $now;
        }

        $allowed = array_merge(['id'], static::$fillable, ['created_at', 'updated_at']);
        $data = array_intersect_key($data, array_flip($allowed));

        foreach (static::$jsonColumns as $col) {
            if (isset($data[$col]) && is_array($data[$col])) {
                $data[$col] = json_encode($data[$col], JSON_UNESCAPED_UNICODE);
            }
        }

        $cols = [];
        $vals = [];
        foreach ($data as $col => $val) {
            $safeCol = static::sanitizeColumnName($col);
            if ($safeCol === null) continue;
            $cols[] = $safeCol;
            $vals[] = $val;
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO ' . static::$table . ' (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
        db()->prepare($sql)->execute($vals);
        return $data['id'];
    }

    public static function update(string $id, array $data): bool
    {
        if (empty($data)) return false;

        $data = array_intersect_key($data, array_flip(static::$fillable));

        foreach (static::$jsonColumns as $col) {
            if (isset($data[$col]) && is_array($data[$col])) {
                $data[$col] = json_encode($data[$col], JSON_UNESCAPED_UNICODE);
            }
        }

        if (static::hasColumn('updated_at')) {
            $data['updated_at'] = utcnow();
        }

        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $safeCol = static::sanitizeColumnName($col);
            if ($safeCol === null) continue;
            $sets[] = "$safeCol = ?";
            $params[] = $val;
        }

        if (empty($sets)) return false;

        $params[] = $id;

        $sql = 'UPDATE ' . static::$table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?';
        return db()->prepare($sql)->execute($params);
    }

    public static function delete(string $id): bool
    {
        return db()->prepare('DELETE FROM ' . static::$table . ' WHERE id = ?')->execute([$id]);
    }

    public static function deleteMany(array $ids): int
    {
        if (empty($ids)) return 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare('DELETE FROM ' . static::$table . " WHERE id IN ($ph)");
        $stmt->execute(array_values($ids));
        return $stmt->rowCount();
    }

    public static function cast(array $row): array
    {
        foreach (static::$boolColumns as $col) {
            if (array_key_exists($col, $row) && $row[$col] !== null) {
                $row[$col] = (bool)$row[$col];
            }
        }
        foreach (static::$jsonColumns as $col) {
            if (array_key_exists($col, $row) && is_string($row[$col]) && $row[$col] !== '') {
                $decoded = json_decode($row[$col], true);
                if (json_last_error() === JSON_ERROR_NONE) $row[$col] = $decoded;
            }
        }
        return $row;
    }

    protected static function encodeJson(?array $data): ?string
    {
        if ($data === null) return null;
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected static function decodeJson(?string $data): ?array
    {
        if ($data === null || $data === '') return null;
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected static function hasColumn(string $col): bool
    {
        $key = static::$table . '.' . $col;
        if (isset(static::$_columnCache[$key])) {
            return static::$_columnCache[$key];
        }

        try {
            $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', static::$table);
            $safeCol   = preg_replace('/[^A-Za-z0-9_]/', '', $col);

            if ($safeTable === '' || $safeCol === '') {
                return static::$_columnCache[$key] = false;
            }

            $stmt = db()->query("SHOW COLUMNS FROM `{$safeTable}` LIKE " . db()->quote($safeCol));
            $exists = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            if (function_exists('logger_warn')) {
                logger_warn('[MODEL] hasColumn failed for ' . $key . ': ' . $e->getMessage());
            }
            return static::$_columnCache[$key] = false;
        }

        return static::$_columnCache[$key] = $exists;
    }

    protected static function iso(?string $dt): ?string
    {
        if (!$dt) return null;
        return str_replace(' ', 'T', $dt);
    }

    protected static function sanitizeColumnName(string $col): ?string
    {
        $col = trim($col);
        if ($col === '' || mb_strlen($col) > 64) return null;
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $col)) {
            return null;
        }

        // ✅ FIX: محافظت از کلمات رزرو MySQL با backtick
        static $reservedWords = [
            'key', 'keys', 'order', 'group', 'desc', 'asc', 'select',
            'table', 'index', 'primary', 'foreign', 'unique', 'and', 'or',
            'where', 'from', 'join', 'left', 'right', 'inner', 'outer',
            'insert', 'update', 'delete', 'create', 'drop', 'alter',
            'database', 'column', 'row', 'rows', 'values', 'set',
            'into', 'as', 'on', 'using', 'by', 'having', 'limit', 'offset',
            'union', 'all', 'distinct', 'between', 'like', 'in', 'is',
            'null', 'not', 'exists', 'case', 'when', 'then', 'else', 'end',
            'read', 'write', 'lock', 'unlock', 'use', 'show', 'status',
            'condition', 'function', 'procedure', 'trigger', 'event',
            'view', 'temporary', 'replace', 'ignore', 'force', 'regexp',
            'binary', 'char', 'varchar', 'text', 'blob', 'int', 'integer',
            'tinyint', 'smallint', 'mediumint', 'bigint', 'float', 'double',
            'decimal', 'numeric', 'date', 'time', 'datetime', 'timestamp',
            'year', 'interval', 'true', 'false', 'unknown',
        ];

        // برای ستون‌های ساده (بدون نقطه)
        if (strpos($col, '.') === false) {
            if (in_array(strtolower($col), $reservedWords, true)) {
                return '`' . $col . '`';
            }
            return $col;
        }

        // برای ستون‌های با نقطه (table.column)
        [$tablePart, $colPart] = explode('.', $col, 2);
        $safeTable = in_array(strtolower($tablePart), $reservedWords, true)
            ? '`' . $tablePart . '`' : $tablePart;
        $safeColPart = in_array(strtolower($colPart), $reservedWords, true)
            ? '`' . $colPart . '`' : $colPart;

        return $safeTable . '.' . $safeColPart;
    }

    protected static function sanitizeOrderBy(string $orderBy): ?string
    {
        $orderBy = trim($orderBy);
        if ($orderBy === '' || mb_strlen($orderBy) > 200) return null;

        if (!preg_match('/^[A-Za-z0-9_.,`\s]+$/', $orderBy)) return null;

        $parts = array_map('trim', explode(',', $orderBy));
        if (count($parts) > 3) return null;

        static $reservedWords = [
            'key','keys','order','group','desc','asc','select',
            'table','index','primary','foreign','unique','and','or',
            'where','from','join','left','right','inner','outer',
            'insert','update','delete','create','drop','alter',
            'database','column','row','rows','values','set',
            'into','as','on','using','by','having','limit','offset',
            'union','all','distinct','between','like','in','is',
            'null','not','exists','case','when','then','else','end',
            'read','write','lock','unlock','use','show','status',
            'condition','function','procedure','trigger','event',
            'view','temporary','replace','ignore','force','regexp',
            'binary','char','varchar','text','blob','int','integer',
            'tinyint','smallint','mediumint','bigint','float','double',
            'decimal','numeric','date','time','datetime','timestamp',
            'year','interval','true','false','unknown',
        ];

        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '') continue;

            /* DANOVA-FIX: DAN-014 */
            if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?(?:\s+(ASC|DESC))?$/i', $part, $m)) {
                $col = $m[1];
                $dir = isset($m[2]) ? strtoupper($m[2]) : 'ASC';
                $safeParts[] = '`' . $col . '` ' . $dir;
                continue;
            }

            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)(?:\s+(ASC|DESC))?$/i', $part, $m)) {
                return null;
            }

            $col = $m[1];
            $dir = isset($m[2]) ? strtoupper($m[2]) : 'ASC';

            if (strpos($col, '.') !== false) {
                [$tablePart, $colPart] = explode('.', $col, 2);
                $safeTable = in_array(strtolower($tablePart), $reservedWords, true)
                    ? '`' . $tablePart . '`' : $tablePart;
                $safeColPart = in_array(strtolower($colPart), $reservedWords, true)
                    ? '`' . $colPart . '`' : $colPart;
                $safeParts[] = $safeTable . '.' . $safeColPart . ' ' . $dir;
            } else {
                if (in_array(strtolower($col), $reservedWords, true)) {
                    $safeParts[] = '`' . $col . '` ' . $dir;
                } else {
                    $safeParts[] = $col . ' ' . $dir;
                }
            }
        }

        if (empty($safeParts)) return null;
        return implode(', ', $safeParts);
    }
}


// =====================================================================
// USER
// =====================================================================

class User extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = [
        'username', 'password_hash', 'full_name', 'role',
        'school_id', 'student_id', 'is_active', 'deleted_at',
        'session_version', 'last_login_at', 'must_change_password',
    ];
    protected static array $boolColumns = ['is_active', 'must_change_password'];

    public static function findByUsername(string $username): ?array
    {
        return self::findWhere(['username' => $username]);
    }

    public static function getByStudentId(string $studentId): ?array
    {
        return self::findWhere(['student_id' => $studentId]);
    }

    public static function getSchoolManagers(string $schoolId): array
    {
        return self::where([
            'school_id' => $schoolId,
            'role'      => 'school_manager',
        ]);
    }

    public static function touchLastLogin(string $id): void
    {
        db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([utcnow(), $id]);
    }

    public static function bumpSessionVersion(string $id): int
    {
        // ✅ Bug #40 FIX
        $pdo = db();
        $startedHere = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedHere = true;
            }
            $pdo->prepare(
                'UPDATE users SET session_version = session_version + 1, updated_at = ? WHERE id = ?'
            )->execute([utcnow(), $id]);
            $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            $newVersion = (int)($row['session_version'] ?? 0);
            if ($startedHere) {
                $pdo->commit();
            }
            return $newVersion;
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logger_warn('[USER] bumpSessionVersion failed: ' . $e->getMessage());
            return 0;
        }
    }

    public static function listManagersPaged(
        ?string $schoolId = null,
        ?string $search = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $page     = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        $sql = "SELECT * FROM users WHERE role = 'school_manager'";
        $params = [];

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        if ($schoolId) {
            $sql .= ' AND school_id = ?';
            $params[] = $schoolId;
        }
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (full_name LIKE ? OR username LIKE ?)';
            $params[] = '%' . trim($search) . '%';
            $params[] = '%' . trim($search) . '%';
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function publicShape(array $u, array $extra = []): array
    {
        return array_merge([
            'id'                   => $u['id'],
            'username'             => $u['username'],
            'full_name'            => $u['full_name'],
            'role'                 => $u['role'],
            'school_id'            => $u['school_id'],
            'student_id'           => $u['student_id'],
            'is_active'            => (bool)$u['is_active'],
            'must_change_password' => (bool)($u['must_change_password'] ?? 0),
            'last_login_at'        => self::iso($u['last_login_at'] ?? null),
            'created_at'           => self::iso($u['created_at'] ?? null),
            'deleted_at'           => self::iso($u['deleted_at'] ?? null),
        ], $extra);
    }
}


// =====================================================================
// SCHOOL
// =====================================================================

class School extends Model
{
    protected static string $table = 'schools';
    protected static array $fillable = [
        'name', 'code', 'description', 'phone', 'email', 'address',
        'school_type', 'gender',
        'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function findByCode(string $code): ?array
    {
        return self::findWhere(['code' => $code]);
    }

    public static function listPaged(
        ?string $search = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM schools WHERE 1=1';
        $params = [];

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (name LIKE ? OR code LIKE ?)';
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function batchCounts(array $schoolIds): array
    {
        if (empty($schoolIds)) return ['classes' => [], 'students' => []];

        $ph = implode(',', array_fill(0, count($schoolIds), '?'));

        $stmt = db()->prepare(
            "SELECT school_id, COUNT(*) AS c FROM classes
             WHERE school_id IN ($ph) AND deleted_at IS NULL
             GROUP BY school_id"
        );
        $stmt->execute($schoolIds);
        $classCounts = [];
        foreach ($stmt->fetchAll() as $r) {
            $classCounts[$r['school_id']] = (int)$r['c'];
        }

        $stmt = db()->prepare(
            "SELECT school_id, COUNT(*) AS c FROM students
             WHERE school_id IN ($ph) AND deleted_at IS NULL
             GROUP BY school_id"
        );
        $stmt->execute($schoolIds);
        $studentCounts = [];
        foreach ($stmt->fetchAll() as $r) {
            $studentCounts[$r['school_id']] = (int)$r['c'];
        }

        return ['classes' => $classCounts, 'students' => $studentCounts];
    }

    public static function publicShape(array $s, array $counts = []): array
    {
        return [
            'id'            => $s['id'],
            'name'          => $s['name'],
            'code'          => $s['code'],
            'description'   => $s['description'],
            'phone'         => $s['phone'],
            'email'         => $s['email'],
            'address'       => $s['address'],
            'school_type'   => $s['school_type'] ?? 'elementary',
            'gender'        => $s['gender'] ?? 'boys',
            'is_active'     => (bool)$s['is_active'],
            'deleted_at'    => self::iso($s['deleted_at']),
            'student_count' => $counts['student_count'] ?? 0,
            'class_count'   => $counts['class_count'] ?? 0,
            'created_at'    => self::iso($s['created_at']),
            'updated_at'    => self::iso($s['updated_at']),
        ];
    }
}


// =====================================================================
// CLASS (school class)
// =====================================================================

class ClassModel extends Model
{
    protected static string $table = 'classes';
    protected static array $fillable = [
        'school_id', 'name', 'grade', 'academic_year', 'description',
        'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function listBySchool(string $schoolId, bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM classes WHERE school_id = ?';
        $params = [$schoolId];
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function listPaged(
        ?string $schoolId = null,
        ?string $search = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM classes WHERE 1=1';
        $params = [];

        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        if ($schoolId) {
            $sql .= ' AND school_id = ?';
            $params[] = $schoolId;
        }
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function batchCounts(array $classIds): array
    {
        if (empty($classIds)) return ['students' => [], 'ai' => []];
        $ph = implode(',', array_fill(0, count($classIds), '?'));

        $stmt = db()->prepare(
            "SELECT class_id, COUNT(*) AS c FROM students
             WHERE class_id IN ($ph) AND deleted_at IS NULL
             GROUP BY class_id"
        );
        $stmt->execute($classIds);
        $students = [];
        foreach ($stmt->fetchAll() as $r) $students[$r['class_id']] = (int)$r['c'];

        $stmt = db()->prepare(
            "SELECT class_id, COUNT(*) AS c FROM class_ai_profiles
             WHERE class_id IN ($ph) AND is_active = 1
             GROUP BY class_id"
        );
        $stmt->execute($classIds);
        $ai = [];
        foreach ($stmt->fetchAll() as $r) $ai[$r['class_id']] = (int)$r['c'];

        return ['students' => $students, 'ai' => $ai];
    }

    public static function effectiveGrade(array $class, ?string $level = null): ?int
    {
        $g = $class['grade'] ?? null;
        if ($g === null || $g === '') return null;

        if (is_numeric($g)) {
            $n = (int)$g;
            if ($n >= 1 && $n <= 12) {
                if ($level !== null) {
                    return grade_belongs_to_level($n, $level) ? $n : null;
                }
                return $n;
            }
            return null;
        }

        return map_grade_text_to_number((string)$g, $level);
    }

    public static function publicShape(array $c, ?string $schoolName = null, array $counts = []): array
    {
        return [
            'id'            => $c['id'],
            'school_id'     => $c['school_id'],
            'school_name'   => $schoolName,
            'name'          => $c['name'],
            'grade'         => $c['grade'],
            'academic_year' => $c['academic_year'],
            'description'   => $c['description'],
            'is_active'     => (bool)$c['is_active'],
            'deleted_at'    => self::iso($c['deleted_at']),
            'student_count' => $counts['student_count'] ?? 0,
            'ai_count'      => $counts['ai_count'] ?? 0,
            'created_at'    => self::iso($c['created_at']),
            'updated_at'    => self::iso($c['updated_at']),
        ];
    }
}


// =====================================================================
// STUDENT
// =====================================================================

class Student extends Model
{
    protected static string $table = 'students';
    protected static array $fillable = [
        'school_id', 'class_id', 'student_code', 'full_name',
        'first_name', 'last_name', 'grade', 'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function findByCode(string $schoolId, string $code): ?array
    {
        return self::findWhere(['school_id' => $schoolId, 'student_code' => $code]);
    }

    public static function listBySchool(
        string $schoolId,
        ?string $classId = null,
        ?string $search = null,
        ?bool $isActive = null,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM students WHERE school_id = ? AND deleted_at IS NULL';
        $params = [$schoolId];

        if ($classId) {
            $sql .= ' AND class_id = ?';
            $params[] = $classId;
        }
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (full_name LIKE ? OR student_code LIKE ?)';
            $params[] = '%' . trim($search) . '%';
            $params[] = '%' . trim($search) . '%';
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY full_name ASC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function listPaged(
        ?string $schoolId = null,
        ?string $classId = null,
        ?string $search = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM students WHERE 1=1';
        $params = [];

        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        if ($schoolId)  { $sql .= ' AND school_id = ?';  $params[] = $schoolId; }
        if ($classId)   { $sql .= ' AND class_id = ?';   $params[] = $classId; }
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND (full_name LIKE ? OR student_code LIKE ?)';
            $params[] = '%' . trim($search) . '%';
            $params[] = '%' . trim($search) . '%';
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function batchEnrich(array $students): array
    {
        if (empty($students)) return [];

        $schoolIds = array_values(array_unique(array_filter(array_column($students, 'school_id'))));
        $classIds  = array_values(array_unique(array_filter(array_column($students, 'class_id'))));
        $ids       = array_column($students, 'id');

        $schoolNames = [];
        if ($schoolIds) {
            $ph = implode(',', array_fill(0, count($schoolIds), '?'));
            $stmt = db()->prepare("SELECT id, name FROM schools WHERE id IN ($ph)");
            $stmt->execute($schoolIds);
            foreach ($stmt->fetchAll() as $r) $schoolNames[$r['id']] = $r['name'];
        }

        $classNames = [];
        if ($classIds) {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            $stmt = db()->prepare("SELECT id, name FROM classes WHERE id IN ($ph)");
            $stmt->execute($classIds);
            foreach ($stmt->fetchAll() as $r) $classNames[$r['id']] = $r['name'];
        }

        $usernames = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT student_id, username FROM users WHERE student_id IN ($ph)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $r) $usernames[$r['student_id']] = $r['username'];
        }

        return [
            'school_names' => $schoolNames,
            'class_names'  => $classNames,
            'usernames'    => $usernames,
        ];
    }

    public static function publicShape(array $s, array $enrich = []): array
    {
        return [
            'id'           => $s['id'],
            'full_name'    => $s['full_name'],
            'first_name'   => $s['first_name'],
            'last_name'    => $s['last_name'],
            'student_code' => $s['student_code'],
            'grade'        => $s['grade'],
            'school_id'    => $s['school_id'],
            'school_name'  => $enrich['school_name'] ?? null,
            'class_id'     => $s['class_id'],
            'class_name'   => $enrich['class_name'] ?? null,
            'username'     => $enrich['username'] ?? null,
            'is_active'    => (bool)$s['is_active'],
            'deleted_at'   => self::iso($s['deleted_at']),
            'created_at'   => self::iso($s['created_at']),
            'updated_at'   => self::iso($s['updated_at']),
        ];
    }

    public static function effectiveGrade(array $student, ?string $level = null, ?array &$classCache = null): ?int
    {
        if (!empty($student['class_id'])) {
            $cid = $student['class_id'];

            $cls = null;
            if ($classCache !== null && array_key_exists($cid, $classCache)) {
                $cls = $classCache[$cid];
            } else {
                $cls = ClassModel::find($cid);
                if ($classCache !== null) {
                    $classCache[$cid] = $cls;
                }
            }

            if ($cls) {
                $g = ClassModel::effectiveGrade($cls, $level);
                if ($g !== null) return $g;
            }
        }

        $g = $student['grade'] ?? null;
        if ($g === null || $g === '') return null;
        if (is_numeric($g)) {
            $n = (int)$g;
            if ($n >= 1 && $n <= 12) {
                if ($level !== null) {
                    return grade_belongs_to_level($n, $level) ? $n : null;
                }
                return $n;
            }
            return null;
        }
        return map_grade_text_to_number((string)$g, $level);
    }
}


// =====================================================================
// AI PROFILE
// =====================================================================

class AIProfile extends Model
{
    protected static string $table = 'ai_profiles';
    protected static array $fillable = [
        'name', 'slug', 'description', 'avatar',
        'model_provider', 'model_name', 'system_prompt',
        'temperature', 'max_output_tokens',
        'api_key_encrypted', 'api_key_hint', 'extra_config',
        'target_school_type', 'target_gender', 'target_grade',
        'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];
    protected static array $jsonColumns = ['extra_config'];

    public static function setApiKeyOn(string $id, ?string $plain): void
    {
        if ($plain === null || $plain === '') {
            db()->prepare('UPDATE ai_profiles SET api_key_encrypted = NULL, api_key_hint = NULL, updated_at = ? WHERE id = ?')
                ->execute([utcnow(), $id]);
            return;
        }
        $enc = encrypt_api_key($plain);
        $hint = mask_api_key($plain);
        db()->prepare('UPDATE ai_profiles SET api_key_encrypted = ?, api_key_hint = ?, updated_at = ? WHERE id = ?')
            ->execute([$enc, $hint, utcnow(), $id]);
    }

    public static function hasApiKey(array $profile): bool
    {
        return !empty($profile['api_key_encrypted']);
    }

    public static function effectiveApiKey(array $profile): ?string
    {
        $own = decrypt_api_key($profile['api_key_encrypted'] ?? null);

        // ✅ FIX: اگر رمزنگاری شکست خورد، هشدار بده
        if ($own === null
            && !empty($profile['api_key_encrypted'])
            && function_exists('logger_warn')) {
            logger_warn(sprintf(
                '[AI] Failed to decrypt api_key for profile %s — falling back to global keys',
                $profile['id'] ?? '?'
            ));
        }

        if ($own) return $own;

        $provider = strtolower($profile['model_provider'] ?? '');

        if ($provider === 'gapgpt' && GAPGPT_API_KEY !== '') {
            return GAPGPT_API_KEY;
        }

        if ($provider === 'openai'    && OPENAI_API_KEY)    return OPENAI_API_KEY;
        if ($provider === 'google'    && GOOGLE_API_KEY)    return GOOGLE_API_KEY;
        if ($provider === 'anthropic' && ANTHROPIC_API_KEY) return ANTHROPIC_API_KEY;

        return null;
    }

    public static function findBySlug(string $slug): ?array
    {
        return self::findWhere(['slug' => $slug]);
    }

    public static function listPaged(
        ?string $search = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM ai_profiles WHERE 1=1';
        $params = [];

        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function batchCounts(array $profileIds): array
    {
        if (empty($profileIds)) return ['classes' => [], 'rag' => []];
        $ph = implode(',', array_fill(0, count($profileIds), '?'));

        $stmt = db()->prepare(
            "SELECT ai_profile_id, COUNT(*) AS c FROM class_ai_profiles
             WHERE ai_profile_id IN ($ph) AND is_active = 1
             GROUP BY ai_profile_id"
        );
        $stmt->execute($profileIds);
        $classes = [];
        foreach ($stmt->fetchAll() as $r) $classes[$r['ai_profile_id']] = (int)$r['c'];

        $stmt = db()->prepare(
            "SELECT ai_profile_id, COUNT(*) AS c FROM ai_profile_rag_collections
             WHERE ai_profile_id IN ($ph) AND is_active = 1
             GROUP BY ai_profile_id"
        );
        $stmt->execute($profileIds);
        $rag = [];
        foreach ($stmt->fetchAll() as $r) $rag[$r['ai_profile_id']] = (int)$r['c'];

        return ['classes' => $classes, 'rag' => $rag];
    }

    public static function hasCompleteTargeting(array $p): bool
    {
        $st = $p['target_school_type'] ?? null;
        $g  = $p['target_gender'] ?? null;

        if ($st === null || !in_array($st, SCHOOL_TYPES, true)) return false;
        if ($g === null || !in_array($g, AI_TARGET_GENDERS, true)) return false;

        if (isset($p['target_grade']) && $p['target_grade'] !== null && $p['target_grade'] !== '') {
            $tg = (int)$p['target_grade'];
            if (!grade_belongs_to_level($tg, $st)) return false;
        }

        return true;
    }

    public static function isLegacyUnclassified(array $p): bool
    {
        return !self::hasCompleteTargeting($p);
    }

    public static function isEligibleForContext(
        array $ai,
        ?array $school,
        ?array $class = null,
        ?array $student = null
    ): bool {
        if (!self::hasCompleteTargeting($ai)) return false;

        $aiLevel  = $ai['target_school_type'];
        $aiGender = $ai['target_gender'];
        $aiGrade  = $ai['target_grade'] ?? null;

        if (!$school) return false;
        $schoolLevel = $school['school_type'] ?? null;
        if ($schoolLevel === null || $schoolLevel !== $aiLevel) return false;

        if ($aiGender !== 'both') {
            $ctxGender = null;

            if ($student && !empty($student['gender'])) {
                $ctxGender = $student['gender'];
            }

            if ($ctxGender === null) {
                $schoolGender = $school['gender'] ?? null;
                if ($schoolGender === 'both') {
                    return false;
                }
                $ctxGender = $schoolGender;
            }

            if ($ctxGender !== $aiGender) return false;
        }

        if ($aiGrade !== null && $aiGrade !== '') {
            $targetGrade = (int)$aiGrade;

            $ctxGrade = null;
            if ($class !== null) {
                $ctxGrade = ClassModel::effectiveGrade($class, $aiLevel);
            } elseif ($student !== null) {
                $ctxGrade = Student::effectiveGrade($student, $aiLevel);
            }

            if ($ctxGrade === null) return false;
            if ($ctxGrade !== $targetGrade) return false;
        }

        return true;
    }

    public static function isEligibleForStudent(array $ai, array $student): bool
    {
        if (empty($student['school_id'])) return false;
        $school = School::find($student['school_id']);
        if (!$school) return false;

        $class = null;
        if (!empty($student['class_id'])) {
            $class = ClassModel::find($student['class_id']);
        }

        return self::isEligibleForContext($ai, $school, $class, $student);
    }

    public static function filterEligibleForStudent(array $aiList, array $student): array
    {
        if (empty($aiList)) return [];

        $school = null;
        if (!empty($student['school_id'])) {
            $school = School::find($student['school_id']);
        }
        $class = null;
        if (!empty($student['class_id'])) {
            $class = ClassModel::find($student['class_id']);
        }

        $out = [];
        foreach ($aiList as $ai) {
            if (self::isEligibleForContext($ai, $school, $class, $student)) {
                $out[] = $ai;
            }
        }
        return $out;
    }

    public static function publicShape(array $p, array $counts = []): array
    {
        return [
            'id'               => $p['id'],
            'name'             => $p['name'],
            'slug'             => $p['slug'],
            'description'      => $p['description'],
            'avatar'           => $p['avatar'],
            'model_provider'   => $p['model_provider'],
            'model_name'       => $p['model_name'],
            'system_prompt'    => $p['system_prompt'],
            'temperature'      => (float)$p['temperature'],
            'max_output_tokens'=> (int)$p['max_output_tokens'],
            'target_school_type' => $p['target_school_type'] ?? null,
            'target_gender'      => $p['target_gender'] ?? null,
            'target_grade'       => isset($p['target_grade']) && $p['target_grade'] !== null
                                    ? (int)$p['target_grade']
                                    : null,
            'is_legacy'        => self::isLegacyUnclassified($p),
            'is_active'        => (bool)$p['is_active'],
            'deleted_at'       => self::iso($p['deleted_at']),
            'class_count'      => $counts['class_count'] ?? 0,
            'rag_count'        => $counts['rag_count'] ?? 0,
            'has_api_key'      => self::hasApiKey($p),
            'api_key_hint'     => $p['api_key_hint'],
            'created_at'       => self::iso($p['created_at']),
            'updated_at'       => self::iso($p['updated_at']),
        ];
    }
}


// =====================================================================
// CLASS ↔ AI PROFILE
// =====================================================================

class ClassAIProfile extends Model
{
    protected static string $table = 'class_ai_profiles';
    protected static array $fillable = [
        'class_id', 'ai_profile_id', 'is_default', 'is_active',
    ];
    protected static array $boolColumns = ['is_default', 'is_active'];

    public static function findLink(string $classId, string $aiProfileId): ?array
    {
        return self::findWhere([
            'class_id'      => $classId,
            'ai_profile_id' => $aiProfileId,
        ]);
    }

    public static function listByClass(string $classId, bool $onlyActive = true): array
    {
        $sql = 'SELECT cp.*, ap.name AS ai_name, ap.model_provider, ap.model_name,
                       ap.is_active AS ai_active, ap.deleted_at AS ai_deleted,
                       ap.target_school_type, ap.target_gender, ap.target_grade
                FROM class_ai_profiles cp
                LEFT JOIN ai_profiles ap ON ap.id = cp.ai_profile_id
                WHERE cp.class_id = ?';
        $params = [$classId];
        if ($onlyActive) $sql .= ' AND cp.is_active = 1';
        $sql .= ' ORDER BY cp.is_default DESC, cp.created_at ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function listByAiProfile(string $aiProfileId): array
    {
        return self::where(['ai_profile_id' => $aiProfileId]);
    }

    public static function isCompatibleWithClass(array $ai, array $class): bool
    {
        if (empty($class['school_id'])) return false;
        $school = School::find($class['school_id']);
        if (!$school) return false;

        return AIProfile::isEligibleForContext($ai, $school, $class, null);
    }

    public static function setDefault(string $classId, string $linkId): bool
    {
        $link = self::find($linkId);
        if (!$link || $link['class_id'] !== $classId) return false;
        if ((int)$link['is_active'] !== 1) return false;

        db()->beginTransaction();
        try {
            db()->prepare('UPDATE class_ai_profiles SET is_default = 0 WHERE class_id = ?')
                ->execute([$classId]);
            db()->prepare('UPDATE class_ai_profiles SET is_default = 1 WHERE id = ?')
                ->execute([$linkId]);
            db()->commit();
            return true;
        } catch (Throwable $e) {
            db()->rollBack();
            return false;
        }
    }

    public static function normalizeDefault(string $classId): void
    {
        try {
            db()->prepare(
                'UPDATE class_ai_profiles
                 SET is_default = 0
                 WHERE class_id = ? AND is_active = 0 AND is_default = 1'
            )->execute([$classId]);
        } catch (Throwable $e) {
            logger_warn('[ClassAIProfile] normalizeDefault step1 failed: ' . $e->getMessage());
        }

        $stmt = db()->prepare(
            'SELECT id FROM class_ai_profiles
             WHERE class_id = ? AND is_active = 1 AND is_default = 1
             ORDER BY created_at ASC'
        );
        $stmt->execute([$classId]);
        $rows = $stmt->fetchAll();

        if (count($rows) > 1) {
            $keep = $rows[0]['id'];
            db()->prepare(
                'UPDATE class_ai_profiles SET is_default = 0
                 WHERE class_id = ? AND id != ?'
            )->execute([$classId, $keep]);
        } elseif (count($rows) === 0) {
            $first = db()->prepare(
                'SELECT id FROM class_ai_profiles
                 WHERE class_id = ? AND is_active = 1
                 ORDER BY created_at ASC LIMIT 1'
            );
            $first->execute([$classId]);
            $r = $first->fetch();
            if ($r) {
                db()->prepare('UPDATE class_ai_profiles SET is_default = 1 WHERE id = ?')
                    ->execute([$r['id']]);
            }
        }
    }

    public static function listForStudent(string $classId): array
    {
        $sql = 'SELECT ap.*, cp.is_default
                FROM class_ai_profiles cp
                JOIN ai_profiles ap ON ap.id = cp.ai_profile_id
                WHERE cp.class_id = ? AND cp.is_active = 1
                  AND ap.is_active = 1 AND ap.deleted_at IS NULL
                ORDER BY cp.is_default DESC, ap.name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$classId]);
        return $stmt->fetchAll();
    }

    public static function listForStudentEligible(array $student): array
    {
        if (empty($student['class_id'])) return [];

        $classId = $student['class_id'];
        $assigned = self::listForStudent($classId);
        if (empty($assigned)) return [];

        $eligible = AIProfile::filterEligibleForStudent($assigned, $student);
        return $eligible;
    }
}


// =====================================================================
// RAG COLLECTION
// =====================================================================

class RAGCollection extends Model
{
    protected static string $table = 'rag_collections';
    protected static array $fillable = [
        'name', 'description', 'type', 'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function listPaged(
        ?string $search = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM rag_collections WHERE 1=1';
        $params = [];

        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND name LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function batchDocCounts(array $collectionIds): array
    {
        if (empty($collectionIds)) return [];
        $ph = implode(',', array_fill(0, count($collectionIds), '?'));
        $stmt = db()->prepare(
            "SELECT collection_id, COUNT(*) AS c FROM rag_documents
             WHERE collection_id IN ($ph) AND deleted_at IS NULL
             GROUP BY collection_id"
        );
        $stmt->execute($collectionIds);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) $counts[$r['collection_id']] = (int)$r['c'];
        return $counts;
    }

    public static function publicShape(array $c, int $docCount = 0): array
    {
        return [
            'id'             => $c['id'],
            'name'           => $c['name'],
            'description'    => $c['description'],
            'type'           => $c['type'],
            'is_active'      => (bool)$c['is_active'],
            'deleted_at'     => self::iso($c['deleted_at']),
            'document_count' => $docCount,
            'created_at'     => self::iso($c['created_at']),
            'updated_at'     => self::iso($c['updated_at']),
        ];
    }
}


// =====================================================================
// RAG DOCUMENT
// =====================================================================

class RAGDocument extends Model
{
    protected static string $table = 'rag_documents';
    protected static array $fillable = [
        'collection_id', 'title', 'file_name', 'file_path',
        'mime_type', 'file_size', 'file_hash', 'source', 'version',
        'status', 'processing_error', 'chunk_count', 'is_active', 'deleted_at',
        'page_count', 'extractor', 'language', 'grade', 'subject',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function listByCollection(string $collectionId): array
    {
        return self::where(
            ['collection_id' => $collectionId, 'deleted_at' => null],
            'created_at DESC'
        );
    }

    public static function publicShape(array $d): array
    {
        return [
            'id'               => $d['id'],
            'collection_id'    => $d['collection_id'],
            'title'            => $d['title'],
            'file_name'        => $d['file_name'],
            'mime_type'        => $d['mime_type'],
            'file_size'        => $d['file_size'] !== null ? (int)$d['file_size'] : null,
            'file_hash'        => $d['file_hash'],
            'status'           => $d['status'],
            'processing_error' => $d['processing_error'],
            'chunk_count'      => (int)$d['chunk_count'],
            'page_count'       => isset($d['page_count']) && $d['page_count'] !== null
                                  ? (int)$d['page_count'] : null,
            'extractor'        => $d['extractor'] ?? null,
            'language'         => $d['language'] ?? null,
            'grade'            => $d['grade'] ?? null,
            'subject'          => $d['subject'] ?? null,
            'is_active'        => (bool)$d['is_active'],
            'deleted_at'       => self::iso($d['deleted_at']),
            'created_at'       => self::iso($d['created_at']),
            'updated_at'       => self::iso($d['updated_at']),
        ];
    }

    public static function updateProcessingMeta(
        string $id,
        ?int $pageCount = null,
        ?string $extractor = null,
        ?string $language = null
    ): bool {
        $data = [];
        if ($pageCount !== null) $data['page_count'] = $pageCount;
        if ($extractor !== null) $data['extractor'] = $extractor;
        if ($language !== null) $data['language'] = $language;

        if (empty($data)) return false;
        return self::update($id, $data);
    }

    public static function listReadyByCollections(array $collectionIds): array
    {
        if (empty($collectionIds)) return [];

        $ph = implode(',', array_fill(0, count($collectionIds), '?'));
        $sql = "SELECT * FROM rag_documents
                WHERE collection_id IN ($ph)
                  AND status = 'ready'
                  AND is_active = 1
                  AND deleted_at IS NULL
                ORDER BY created_at DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute($collectionIds);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }
}


// =====================================================================
// RAG CHUNK
// =====================================================================

class RAGChunk extends Model
{
    protected static string $table = 'rag_chunks';
    protected static array $fillable = [
        'document_id', 'chunk_index', 'content', 'token_count',
        'chunk_metadata', 'embedding', 'embedding_model',
        'page_number', 'page_end', 'heading', 'section',
        'embedding_dim', 'normalized_content',
    ];
    protected static array $jsonColumns = ['chunk_metadata', 'embedding'];

    protected static ?bool $_hasFts5 = null;

    public static function listByDocument(string $documentId): array
    {
        return self::where(['document_id' => $documentId], 'chunk_index ASC');
    }

    public static function listWithEmbedding(array $documentIds): array
    {
        if (empty($documentIds)) return [];
        $ph = implode(',', array_fill(0, count($documentIds), '?'));
        $stmt = db()->prepare(
            "SELECT * FROM rag_chunks WHERE document_id IN ($ph) AND embedding IS NOT NULL LIMIT 2000"
        );
        $stmt->execute($documentIds);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function deleteByDocument(string $documentId): int
    {
        $stmt = db()->prepare('DELETE FROM rag_chunks WHERE document_id = ?');
        $stmt->execute([$documentId]);
        return $stmt->rowCount();
    }

    public static function createWithMetadata(array $data): string
    {
        if (!isset($data['normalized_content']) && isset($data['content'])) {
            if (function_exists('text_normalize_persian_deep')) {
                $data['normalized_content'] = text_normalize_persian_deep((string)$data['content']);
            }
        }

        if (!isset($data['embedding_dim']) && !empty($data['embedding']) && is_array($data['embedding'])) {
            $data['embedding_dim'] = count($data['embedding']);
        }

        return self::create($data);
    }

    public static function listWithMetadata(array $documentIds, array $options = []): array
    {
        if (empty($documentIds)) return [];

        $withEmbedding = !empty($options['with_embedding']);
        $onlyWithEmbedding = !empty($options['only_with_embedding']);
        $limit = isset($options['limit']) ? (int)$options['limit'] : 2000;
        $limit = max(1, min(10000, $limit));

        $cols = 'id, document_id, chunk_index, content, token_count, chunk_metadata,
                 embedding_model, page_number, page_end, heading, section,
                 embedding_dim, normalized_content, created_at';
        if ($withEmbedding) {
            $cols .= ', embedding';
        }

        $ph = implode(',', array_fill(0, count($documentIds), '?'));
        $sql = "SELECT $cols FROM rag_chunks WHERE document_id IN ($ph)";
        if ($onlyWithEmbedding) {
            $sql .= " AND embedding IS NOT NULL";
        }
        $sql .= " ORDER BY document_id ASC, chunk_index ASC LIMIT $limit";

        $stmt = db()->prepare($sql);
        $stmt->execute($documentIds);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $r = self::cast($r);
            if (!$withEmbedding) unset($r['embedding']);
            $rows[] = $r;
        }
        return $rows;
    }

    public static function backfillNormalizedContent(int $batchSize = 500): int
    {
        if (!function_exists('text_normalize_persian_deep')) {
            return 0;
        }

        $batchSize = max(1, min(5000, $batchSize));

        $pdo = db();
        $startedHere = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedHere = true;
            }

            $stmt = $pdo->prepare(
                "SELECT id, content FROM rag_chunks
                 WHERE normalized_content IS NULL
                   AND content IS NOT NULL
                   AND content != ''
                 LIMIT $batchSize"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                if ($startedHere) $pdo->commit();
                return 0;
            }

            $upd = $pdo->prepare(
                'UPDATE rag_chunks SET normalized_content = ?
                 WHERE id = ? AND normalized_content IS NULL'
            );
            $count = 0;
            foreach ($rows as $r) {
                $normalized = text_normalize_persian_deep((string)$r['content']);
                $upd->execute([$normalized, $r['id']]);
                $count += $upd->rowCount();
            }

            if ($startedHere) $pdo->commit();
            return $count;

        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('logger_warn')) {
                logger_warn('[RAG] backfillNormalizedContent failed: ' . $e->getMessage());
            }
            return 0;
        }
    }

    public static function hasFtsSupport(): bool
    {
        if (self::$_hasFts5 !== null) {
            return self::$_hasFts5;
        }

        try {
            // ✅ FIX: بررسی دقیق‌تر — باید FULLTEXT روی هر دو ستون
            // (content و normalized_content) وجود داشته باشد.
            $stmt = db()->prepare(
                "SELECT COLUMN_NAME FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = 'rag_chunks'
                   AND index_type = 'FULLTEXT'"
            );
            $stmt->execute();
            $cols = array_column($stmt->fetchAll(), 'COLUMN_NAME');

            // در MySQL، FULLTEXT می‌تواند روی چند ستون باشد — اینجا
            // بررسی می‌کنیم که حداقل یکی از ستون‌ها index دارد.
            $hasContent    = in_array('content', $cols, true);
            $hasNormalized = in_array('normalized_content', $cols, true);

            self::$_hasFts5 = ($hasContent || $hasNormalized);

            if (!self::$_hasFts5) {
                logger_warn('[RAG] No FULLTEXT index on rag_chunks.content or normalized_content');
            }
        } catch (Throwable $e) {
            self::$_hasFts5 = false;
            logger_warn('[RAG] FULLTEXT check failed: ' . $e->getMessage());
        }

        return self::$_hasFts5;
    }

    public static function lexicalSearch(string $query, array $documentIds, int $limit = 60): array
    {
        if (trim($query) === '' || empty($documentIds)) return [];
        $limit = max(1, min(500, $limit));

        if (function_exists('text_normalize_for_fts')) {
            $normalizedQuery = text_normalize_for_fts($query);
        } elseif (function_exists('text_normalize_persian_deep')) {
            $normalizedQuery = text_normalize_persian_deep($query);
        } else {
            $normalizedQuery = $query;
        }

        if (trim($normalizedQuery) === '') return [];

        if (self::hasFtsSupport()) {
            try {
                $results = self::_fts5Search($normalizedQuery, $documentIds, $limit);
                if (!empty($results)) {
                    return $results;
                }
            } catch (Throwable $e) {
                logger_warn('[RAGChunk] FTS5 search failed, falling back: ' . $e->getMessage());
            }
        }

        return self::_likeSearch($normalizedQuery, $documentIds, $limit);
    }

    private static function _fts5Search(string $normalizedQuery, array $documentIds, int $limit): array
    {
        $tokens = preg_split('/\s+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) return [];

        $cleanTokens = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '' || mb_strlen($t) < 2) continue;
            $t = preg_replace('/[+\-><()~*"@]+/u', ' ', $t);
            $t = trim($t);
            if ($t === '') continue;
            $cleanTokens[] = '+' . $t . '*';
        }
        if (empty($cleanTokens)) return [];

        $ftsQuery = implode(' ', $cleanTokens);
        $docPh = implode(',', array_fill(0, count($documentIds), '?'));

        $sql = "SELECT
                    c.id AS chunk_id,
                    c.document_id AS document_id,
                    MATCH(c.content, c.normalized_content) AGAINST (? IN BOOLEAN MODE) AS relevance
                FROM rag_chunks c
                WHERE MATCH(c.content, c.normalized_content) AGAINST (? IN BOOLEAN MODE)
                  AND c.document_id IN ($docPh)
                ORDER BY relevance DESC
                LIMIT " . (int)$limit;

        $stmt = db()->prepare($sql);
        $params = array_merge([$ftsQuery, $ftsQuery], $documentIds);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $out = [];
        $rank = 0;
        $maxRel = 0.0;
        foreach ($rows as $r) {
            $rel = (float)$r['relevance'];
            if ($rel > $maxRel) $maxRel = $rel;
        }
        if ($maxRel <= 0) $maxRel = 1.0;

        foreach ($rows as $r) {
            $rank++;
            $rel = (float)$r['relevance'];
            $score = $rel / $maxRel;
            $out[] = [
                'chunk_id'    => (string)$r['chunk_id'],
                'document_id' => (string)$r['document_id'],
                'score'       => $score,
                'rank'        => $rank,
            ];
        }
        return $out;
    }

    private static function _likeSearch(string $normalizedQuery, array $documentIds, int $limit): array
    {
        $tokens = preg_split('/\s+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) return [];

        $tokens = array_slice($tokens, 0, 5);

        $docPh = implode(',', array_fill(0, count($documentIds), '?'));

        $likeClauses = [];
        $likeParams = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t) < 2) continue;
            $likeClauses[] = "(normalized_content LIKE ? OR content LIKE ?)";
            $likeParams[] = '%' . $t . '%';
            $likeParams[] = '%' . $t . '%';
        }
        if (empty($likeClauses)) return [];

        $sql = "SELECT id, document_id, content, normalized_content
                FROM rag_chunks
                WHERE document_id IN ($docPh)
                  AND (" . implode(' OR ', $likeClauses) . ")
                LIMIT " . (int)$limit;

        $stmt = db()->prepare($sql);
        $params = array_merge($documentIds, $likeParams);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $out = [];
        $rank = 0;
        foreach ($rows as $r) {
            $rank++;
            $text = (string)($r['normalized_content'] ?? $r['content'] ?? '');
            $matchCount = 0;
            foreach ($tokens as $t) {
                if (mb_strlen($t) >= 2 && mb_strpos($text, $t) !== false) {
                    $matchCount++;
                }
            }
            $score = count($tokens) > 0 ? $matchCount / count($tokens) : 0;

            $out[] = [
                'chunk_id'    => (string)$r['id'],
                'document_id' => (string)$r['document_id'],
                'score'       => $score,
                'rank'        => $rank,
            ];
        }

        usort($out, function ($a, $b) {
            if ($b['score'] === $a['score']) return $a['rank'] - $b['rank'];
            return $b['score'] <=> $a['score'];
        });

        foreach ($out as $i => &$item) {
            $item['rank'] = $i + 1;
        }

        return $out;
    }

    public static function searchByDocumentIds(
        string $query,
        array $documentIds,
        int $limit = 60
    ): array {
        return self::lexicalSearch($query, $documentIds, $limit);
    }
}


// =====================================================================
// AI PROFILE ↔ RAG COLLECTION
// =====================================================================

class AIProfileRAGCollection extends Model
{
    protected static string $table = 'ai_profile_rag_collections';
    protected static array $fillable = [
        'ai_profile_id', 'collection_id', 'is_active', 'priority',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function findLink(string $aiProfileId, string $collectionId): ?array
    {
        return self::findWhere([
            'ai_profile_id' => $aiProfileId,
            'collection_id' => $collectionId,
        ]);
    }

    public static function listActiveForAI(string $aiProfileId): array
    {
        $sql = 'SELECT l.*, c.name AS collection_name, c.is_active AS collection_active,
                       c.deleted_at AS collection_deleted
                FROM ai_profile_rag_collections l
                JOIN rag_collections c ON c.id = l.collection_id
                WHERE l.ai_profile_id = ? AND l.is_active = 1
                ORDER BY l.priority DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$aiProfileId]);
        return $stmt->fetchAll();
    }

    public static function listForAI(string $aiProfileId): array
    {
        $sql = 'SELECT l.*, c.name AS collection_name
                FROM ai_profile_rag_collections l
                LEFT JOIN rag_collections c ON c.id = l.collection_id
                WHERE l.ai_profile_id = ?
                ORDER BY l.priority DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$aiProfileId]);
        return $stmt->fetchAll();
    }

    public static function listForCollection(string $collectionId): array
    {
        $sql = 'SELECT l.*, ap.name AS ai_name
                FROM ai_profile_rag_collections l
                LEFT JOIN ai_profiles ap ON ap.id = l.ai_profile_id
                WHERE l.collection_id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute([$collectionId]);
        return $stmt->fetchAll();
    }

    public static function deactivateAllForAI(string $aiProfileId): void
    {
        db()->prepare('UPDATE ai_profile_rag_collections SET is_active = 0 WHERE ai_profile_id = ?')
            ->execute([$aiProfileId]);
    }
}


// =====================================================================
// CONVERSATION
// =====================================================================

class Conversation extends Model
{
    protected static string $table = 'conversations';
    protected static array $fillable = [
        'student_id', 'ai_profile_id', 'title', 'is_archived',
    ];
    protected static array $boolColumns = ['is_archived'];

    public static function listByStudent(string $studentId, int $page = 1, int $pageSize = 25): array
    {
        $offset = ($page - 1) * $pageSize;

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM conversations WHERE student_id = ? AND is_archived = 0');
        $countStmt->execute([$studentId]);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql = 'SELECT c.*, ap.name AS ai_profile_name
                FROM conversations c
                LEFT JOIN ai_profiles ap ON ap.id = c.ai_profile_id
                WHERE c.student_id = ? AND c.is_archived = 0
                ORDER BY c.updated_at DESC
                LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
        $stmt = db()->prepare($sql);
        $stmt->execute([$studentId]);
        $items = $stmt->fetchAll();

        $ids = array_column($items, 'id');
        $counts = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $cStmt = db()->prepare("SELECT conversation_id, COUNT(*) AS c FROM messages WHERE conversation_id IN ($ph) GROUP BY conversation_id");
            $cStmt->execute($ids);
            foreach ($cStmt->fetchAll() as $r) $counts[$r['conversation_id']] = (int)$r['c'];
        }

        return ['items' => $items, 'total' => $total, 'message_counts' => $counts];
    }

    public static function publicShape(array $c, int $messageCount = 0): array
    {
        return [
            'id'              => $c['id'],
            'title'           => $c['title'] ?: 'گفتگوی بدون عنوان',
            'ai_profile_id'   => $c['ai_profile_id'],
            'ai_profile_name' => $c['ai_profile_name'] ?? null,
            'message_count'   => $messageCount,
            'created_at'      => self::iso($c['created_at']),
            'updated_at'      => self::iso($c['updated_at']),
        ];
    }

    public static function archive(string $id): void
    {
        db()->prepare('UPDATE conversations SET is_archived = 1, updated_at = ? WHERE id = ?')
            ->execute([utcnow(), $id]);
    }
}


// =====================================================================
// MESSAGE
// =====================================================================

class Message extends Model
{
    protected static string $table = 'messages';

    protected static array $fillable = [
        'conversation_id', 'role', 'content',
        'input_tokens', 'output_tokens', 'total_tokens',
        'rag_used', 'rag_chunks_used',
        'client_message_id',
    ];
    protected static array $boolColumns = ['rag_used'];

    public static function listByConversation(string $conversationId, int $limit = 200, int $offset = 0): array
    {
        $sql = 'SELECT * FROM messages WHERE conversation_id = ?
                ORDER BY sequence_number ASC
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        $stmt = db()->prepare($sql);
        $stmt->execute([$conversationId]);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function recentByConversation(string $conversationId, int $limit = 10): array
    {
        $sql = 'SELECT * FROM messages WHERE conversation_id = ?
                ORDER BY sequence_number DESC LIMIT ' . (int)$limit;
        $stmt = db()->prepare($sql);
        $stmt->execute([$conversationId]);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());
        return array_reverse($rows);
    }

    public static function findByClientId(string $conversationId, string $clientMessageId): ?array
    {
        if ($clientMessageId === '' || $conversationId === '') return null;

        if (!static::hasColumn('client_message_id')) return null;

        return self::findWhere([
            'conversation_id'   => $conversationId,
            'client_message_id' => $clientMessageId,
        ]);
    }

    public static function findAssistantAfter(string $userMessageId): ?array
    {
        if ($userMessageId === '') return null;

        $stmt = db()->prepare(
            "SELECT sequence_number AS _seq, conversation_id, created_at
             FROM messages
             WHERE id = ? AND role = 'user'
             LIMIT 1"
        );
        $stmt->execute([$userMessageId]);
        $userRow = $stmt->fetch();
        if (!$userRow) return null;

        $convId = (string)$userRow['conversation_id'];
        $seq    = (int)$userRow['_seq'];

        $sql = "SELECT m.*
                FROM messages m
                WHERE m.conversation_id = ?
                  AND m.role = 'assistant'
                  AND m.sequence_number > ?
                  AND m.sequence_number < COALESCE(
                        (SELECT MIN(mu.sequence_number) FROM messages mu
                         WHERE mu.conversation_id = m.conversation_id
                           AND mu.role = 'user'
                           AND mu.sequence_number > ?),
                        9223372036854775807
                  )
                ORDER BY m.sequence_number ASC
                LIMIT 1";

        $stmt = db()->prepare($sql);
        $stmt->execute([$convId, $seq, $seq]);

        $row = $stmt->fetch();
        return $row ? self::cast($row) : null;
    }

    public static function createIdempotent(array $data): string
    {
        try {
            return self::create($data);

        } catch (PDOException $e) {
            $sqlState  = $e->getCode();
            $errorInfo = $e->errorInfo;
            $driverCode = is_array($errorInfo) && isset($errorInfo[1])
                ? (int)$errorInfo[1]
                : 0;

            $sqlStateStr = (string)$sqlState;
            $sqlStateInt = is_numeric($sqlState) ? (int)$sqlState : 0;

            $isConstraintViolation = (
                $sqlStateStr === '23000'
                || $sqlStateInt === 23000
                || $driverCode === 19
            );

            if (!$isConstraintViolation) {
                throw $e;
            }

            $msg = $e->getMessage();

            $isClientDuplicate = (
                stripos($msg, 'client_message_id') !== false
                || stripos($msg, 'idx_messages_client_unique') !== false
            );

            if ($isClientDuplicate) {
                throw new RuntimeException('CLIENT_MESSAGE_ID_DUPLICATE', 409, $e);
            }

            throw $e;
        }
    }

    public static function publicShape(array $m): array
    {
        return [
            'id'              => $m['id'],
            'role'            => $m['role'],
            'content'         => $m['content'],
            'created_at'      => self::iso($m['created_at']),
            'rag_used'        => (bool)$m['rag_used'],
            'rag_chunks_used' => (int)$m['rag_chunks_used'],
        ];
    }
}


// =====================================================================
// AI USAGE
// =====================================================================

class AIUsage extends Model
{
    protected static string $table = 'ai_usage';
    protected static array $fillable = [
        'user_id', 'student_id', 'school_id', 'class_id', 'ai_profile_id',
        'conversation_id', 'message_id', 'provider', 'model',
        'input_tokens', 'output_tokens', 'total_tokens', 'cached_tokens',
        'request_duration_ms', 'request_status', 'estimated_cost',
        'cost', 'cost_details',
        'quota_units',
    ];

    public static function todayCountByStudent(string $studentId): int
    {
        $start = function_exists('danova_today_start_utc')
            ? danova_today_start_utc()
            : gmdate('Y-m-d 00:00:00');

        $stmt = db()->prepare(
            "SELECT COUNT(*) AS c FROM ai_usage
             WHERE student_id = ? AND created_at >= ? AND request_status = 'success'"
        );
        $stmt->execute([$studentId, $start]);
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    public static function sumTokensByStudentToday(string $studentId): int
    {
        $start = function_exists('danova_today_start_utc')
            ? danova_today_start_utc()
            : gmdate('Y-m-d 00:00:00');

        $end = function_exists('danova_today_end_utc')
            ? danova_today_end_utc()
            : gmdate('Y-m-d 23:59:59');

        return self::sumTotalTokensByStudentBetween($studentId, $start, $end);
    }

    public static function sumTotalTokensByStudentBetween(string $studentId, string $start, string $end): int
    {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(total_tokens), 0) AS s FROM ai_usage
             WHERE student_id = ? AND created_at >= ? AND created_at <= ?
               AND request_status = 'success'"
        );
        $stmt->execute([$studentId, $start, $end]);
        return (int)($stmt->fetch()['s'] ?? 0);
    }

    public static function sumSince(?string $studentId, ?string $schoolId, string $since): array
    {
        $sql = 'SELECT COUNT(*) AS c,
                       COALESCE(SUM(input_tokens), 0)  AS in_t,
                       COALESCE(SUM(output_tokens), 0) AS out_t,
                       COALESCE(SUM(total_tokens), 0)  AS tot_t
                FROM ai_usage WHERE created_at >= ?';
        $params = [$since];

        if ($studentId) { $sql .= ' AND student_id = ?'; $params[] = $studentId; }
        if ($schoolId)  { $sql .= ' AND school_id = ?';  $params[] = $schoolId; }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: ['c' => 0, 'in_t' => 0, 'out_t' => 0, 'tot_t' => 0];
    }

    public static function usageByClass(string $schoolId, string $since): array
    {
        $sql = 'SELECT c.id, c.name, COUNT(u.id) AS msg_count, COALESCE(SUM(u.total_tokens), 0) AS tokens
                FROM classes c
                JOIN students s ON s.class_id = c.id
                JOIN ai_usage u ON u.student_id = s.id
                WHERE c.school_id = ? AND c.deleted_at IS NULL AND u.created_at >= ?
                GROUP BY c.id, c.name
                ORDER BY msg_count DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$schoolId, $since]);
        return $stmt->fetchAll();
    }

    public static function byClass(string $schoolId, string $since): array
    {
        $sql = 'SELECT class_id,
                       COUNT(*) AS msg_count,
                       COALESCE(SUM(input_tokens), 0)  AS in_t,
                       COALESCE(SUM(output_tokens), 0) AS out_t,
                       COALESCE(SUM(total_tokens), 0)  AS tot_t,
                       COALESCE(SUM(cached_tokens), 0) AS cached_t,
                       COALESCE(SUM(cost), 0)          AS cost_sum
                FROM ai_usage
                WHERE school_id = ? AND created_at >= ? AND class_id IS NOT NULL
                GROUP BY class_id
                ORDER BY msg_count DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute([$schoolId, $since]);
        return $stmt->fetchAll();
    }
}


// =====================================================================
// VIDEO
// =====================================================================

class Video extends Model
{
    protected static string $table = 'videos';
    protected static array $fillable = [
        'title', 'description', 'file_name', 'file_path', 'thumbnail_path',
        'mime_type', 'duration', 'file_size', 'file_hash',
        'category', 'grade', 'grade_legacy_text', 'subject', 'chapter', 'sort_order',
        'target_school_type', 'target_gender',
        'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function listPaged(
        ?string $search = null,
        ?string $grade = null,
        ?string $subject = null,
        ?bool $isActive = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM videos WHERE 1=1';
        $params = [];

        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND title LIKE ?';
            $params[] = '%' . trim($search) . '%';
        }
        if ($grade)   { $sql .= ' AND grade = ?';   $params[] = $grade; }
        if ($subject) { $sql .= ' AND subject = ?'; $params[] = $subject; }
        if ($isActive !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY sort_order ASC, created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function effectiveGrade(array $video): ?int
    {
        $g = $video['grade'] ?? null;
        if ($g === null || $g === '') return null;

        $level = $video['target_school_type'] ?? 'elementary';

        if (is_numeric($g)) {
            $n = (int)$g;
            return grade_belongs_to_level($n, $level) ? $n : null;
        }

        return map_grade_text_to_number((string)$g, $level);
    }

    public static function listForStudent(
        string $studentId,
        ?string $filter = null,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $offset = ($page - 1) * $pageSize;

        $student = Student::find($studentId);
        if (!$student || !$student['school_id']) {
            return ['items' => [], 'total' => 0, 'progress' => []];
        }
        $school = School::find($student['school_id']);
        if (!$school) {
            return ['items' => [], 'total' => 0, 'progress' => []];
        }

        $sType   = $school['school_type'] ?? 'elementary';
        $sGender = $school['gender'] ?? 'boys';

        $classCache = [];
        $studentGrade = Student::effectiveGrade($student, $sType, $classCache);

        $base = 'FROM videos v
                 WHERE v.is_active = 1 AND v.deleted_at IS NULL
                   AND v.target_school_type = ?
                   AND (v.target_gender = ? OR v.target_gender = ?)';
        $params = [$sType, 'both', $sGender];

        if ($filter && $filter !== 'all') {
            if ($filter === 'unwatched') {
                $base .= ' AND v.id NOT IN (SELECT video_id FROM video_usage WHERE student_id = ?)';
                $params[] = $studentId;
            } elseif ($filter === 'in_progress') {
                $base .= ' AND v.id IN (
                    SELECT video_id FROM video_usage
                    WHERE student_id = ? AND completed = 0 AND watch_percentage > 0
                )';
                $params[] = $studentId;
            } elseif ($filter === 'completed') {
                $base .= ' AND v.id IN (
                    SELECT video_id FROM video_usage
                    WHERE student_id = ? AND completed = 1
                )';
                $params[] = $studentId;
            }
        }

        $sql = 'SELECT v.* ' . $base . ' ORDER BY v.sort_order ASC, v.created_at DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $allVideos = array_map([self::class, 'cast'], $stmt->fetchAll());

        $filtered = [];
        foreach ($allVideos as $v) {
            $videoGrade = self::effectiveGrade($v);

            if ($videoGrade === null) {
                $filtered[] = $v;
                continue;
            }

            if ($studentGrade === null) {
                continue;
            }

            if ($videoGrade === $studentGrade) {
                $filtered[] = $v;
            }
        }

        $total = count($filtered);
        $items = array_slice($filtered, $offset, $pageSize);

        $progress = [];
        if ($items) {
            $ids = array_column($items, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pStmt = db()->prepare("SELECT * FROM video_usage WHERE student_id = ? AND video_id IN ($ph)");
            $pStmt->execute(array_merge([$studentId], $ids));
            foreach ($pStmt->fetchAll() as $r) {
                $progress[$r['video_id']] = $r;
            }
        }

        return ['items' => $items, 'total' => $total, 'progress' => $progress];
    }

    public static function studentCanAccess(array $video, array $student): bool
    {
        if ((int)$video['is_active'] !== 1 || $video['deleted_at'] !== null) return false;
        if (empty($student['school_id'])) return false;

        $school = School::find($student['school_id']);
        if (!$school || (int)$school['is_active'] !== 1 || $school['deleted_at'] !== null) {
            return false;
        }

        $sType   = $school['school_type'] ?? 'elementary';
        $sGender = $school['gender'] ?? 'boys';

        if (($video['target_school_type'] ?? 'elementary') !== $sType) return false;

        $tg = $video['target_gender'] ?? 'both';
        if ($tg !== 'both' && $tg !== $sGender) return false;

        $videoGrade = self::effectiveGrade($video);
        if ($videoGrade === null) {
            return true;
        }

        $studentGrade = Student::effectiveGrade($student, $sType);
        if ($studentGrade === null) return false;

        return $studentGrade === $videoGrade;
    }

    public static function batchViewCounts(array $videoIds): array
    {
        if (empty($videoIds)) return [];
        $ph = implode(',', array_fill(0, count($videoIds), '?'));
        $stmt = db()->prepare("SELECT video_id, COUNT(*) AS c FROM video_usage WHERE video_id IN ($ph) GROUP BY video_id");
        $stmt->execute($videoIds);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) $counts[$r['video_id']] = (int)$r['c'];
        return $counts;
    }

    public static function publicShape(array $v, int $viewCount = 0, ?array $progress = null): array
    {
        return [
            'id'             => $v['id'],
            'title'          => $v['title'],
            'description'    => $v['description'],
            'file_name'      => $v['file_name'],
            'thumbnail_path' => $v['thumbnail_path'],
            'mime_type'      => $v['mime_type'],
            'duration'       => $v['duration'] !== null ? (int)$v['duration'] : null,
            'file_size'      => $v['file_size'] !== null ? (int)$v['file_size'] : null,
            'file_hash'      => $v['file_hash'],
            'category'       => $v['category'],
            'grade'          => $v['grade'],
            'grade_legacy_text' => $v['grade_legacy_text'] ?? null,
            'effective_grade'   => self::effectiveGrade($v),
            'subject'        => $v['subject'],
            'chapter'        => $v['chapter'],
            'sort_order'     => (int)$v['sort_order'],
            'target_school_type' => $v['target_school_type'] ?? 'elementary',
            'target_gender'      => $v['target_gender'] ?? 'both',
            'is_active'      => (bool)$v['is_active'],
            'deleted_at'     => self::iso($v['deleted_at']),
            'view_count'     => $viewCount,
            'stream_url'     => '/api/videos/' . $v['id'] . '/stream',
            'thumbnail_url'  => $v['thumbnail_path'] ? '/api/videos/' . $v['id'] . '/thumbnail' : null,
            'progress'       => $progress,
            'created_at'     => self::iso($v['created_at']),
            'updated_at'     => self::iso($v['updated_at']),
        ];
    }

    public static function studentShape(array $v, ?array $progress = null): array
    {
        return [
            'id'             => $v['id'],
            'title'          => $v['title'],
            'description'    => $v['description'],
            'thumbnail_path' => $v['thumbnail_path'],
            'duration'       => $v['duration'] !== null ? (int)$v['duration'] : null,
            'category'       => $v['category'],
            'grade'          => self::effectiveGrade($v),
            'subject'        => $v['subject'],
            'chapter'        => $v['chapter'],
            'file_url'       => '/api/videos/' . $v['id'] . '/stream',
            'progress'       => $progress,
        ];
    }
}


// =====================================================================
// VIDEO USAGE
// =====================================================================

class VideoUsage extends Model
{
    protected static string $table = 'video_usage';
    protected static array $fillable = [
        'student_id', 'video_id', 'watched_seconds', 'last_position',
        'watch_percentage', 'completed',
    ];
    protected static array $boolColumns = ['completed'];

    public static function findFor(string $studentId, string $videoId): ?array
    {
        return self::findWhere(['student_id' => $studentId, 'video_id' => $videoId]);
    }

    public static function listByStudent(string $studentId): array
    {
        return self::where(['student_id' => $studentId]);
    }

    public static function upsertProgress(
        string $studentId,
        string $videoId,
        int $lastPos,
        float $pct,
        int $watchedSeconds,
        bool $completed,
        int $completionThreshold = 90
    ): array {
        $existing = self::findFor($studentId, $videoId);

        if (!$existing) {
            $id = self::create([
                'student_id'       => $studentId,
                'video_id'         => $videoId,
                'watched_seconds'  => $watchedSeconds,
                'last_position'    => $lastPos,
                'watch_percentage' => $pct,
                'completed'        => $completed ? 1 : 0,
            ]);
            return self::find($id);
        }

        $newPct = max((float)$existing['watch_percentage'], $pct);
        $newWatched = max((int)$existing['watched_seconds'], $watchedSeconds);
        $newCompleted = $existing['completed'] || $completed;

        db()->prepare(
            'UPDATE video_usage
             SET last_position = ?, watch_percentage = ?, watched_seconds = ?,
                 completed = ?, last_watched_at = ?
             WHERE id = ?'
        )->execute([$lastPos, $newPct, $newWatched, $newCompleted ? 1 : 0, utcnow(), $existing['id']]);

        return self::find($existing['id']);
    }
}


// =====================================================================
// VIDEO COLLECTION
// =====================================================================

class VideoCollection extends Model
{
    protected static string $table = 'video_collections';
    protected static array $fillable = [
        'title', 'description', 'cover_image', 'category', 'level',
        'sort_order', 'is_active', 'deleted_at',
    ];
    protected static array $boolColumns = ['is_active'];

    public static function listPaged(
        array $filters = [],
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT * FROM video_collections WHERE 1=1';
        $params = [];

        $includeDeleted = !empty($filters['include_deleted']);
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $search = $filters['search'] ?? null;
        if ($search !== null && trim((string)$search) !== '') {
            $sql .= ' AND (title LIKE ? OR description LIKE ?)';
            $term = '%' . trim((string)$search) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $category = $filters['category'] ?? null;
        if ($category !== null && trim((string)$category) !== '') {
            $sql .= ' AND category = ?';
            $params[] = trim((string)$category);
        }

        $level = $filters['level'] ?? null;
        if ($level !== null && trim((string)$level) !== '') {
            $sql .= ' AND level = ?';
            $params[] = trim((string)$level);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $filters['is_active'] ? 1 : 0;
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY sort_order ASC, created_at DESC';
        $sql .= ' LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([self::class, 'cast'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total];
    }

    public static function listActiveForStudent(): array
    {
        return self::where(
            ['is_active' => 1, 'deleted_at' => null],
            'sort_order ASC, created_at DESC'
        );
    }

    public static function batchVideoCounts(array $collectionIds): array
    {
        if (empty($collectionIds)) return [];

        $ph = implode(',', array_fill(0, count($collectionIds), '?'));
        $stmt = db()->prepare(
            "SELECT cv.collection_id, COUNT(*) AS c
             FROM collection_videos cv
             JOIN videos v ON v.id = cv.video_id
             WHERE cv.collection_id IN ($ph)
               AND v.deleted_at IS NULL
               AND v.is_active = 1
             GROUP BY cv.collection_id"
        );
        $stmt->execute($collectionIds);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) {
            $counts[$r['collection_id']] = (int)$r['c'];
        }
        return $counts;
    }

    public static function batchTotalDuration(array $collectionIds): array
    {
        if (empty($collectionIds)) return [];

        $ph = implode(',', array_fill(0, count($collectionIds), '?'));
        $stmt = db()->prepare(
            "SELECT cv.collection_id, COALESCE(SUM(v.duration), 0) AS total_sec
             FROM collection_videos cv
             JOIN videos v ON v.id = cv.video_id
             WHERE cv.collection_id IN ($ph)
               AND v.deleted_at IS NULL
               AND v.is_active = 1
             GROUP BY cv.collection_id"
        );
        $stmt->execute($collectionIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['collection_id']] = (int)$r['total_sec'];
        }
        return $out;
    }

    public static function getVideosForStudent(
        string $collectionId,
        array $student
    ): array {
        $studentId = $student['id'] ?? null;
        if (!$studentId) {
            return [
                'videos' => [], 'progress' => [],
                'stats' => ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0],
                'progress_percent' => 0.0,
            ];
        }

        $sql = "SELECT v.*, cv.sort_order AS cv_sort_order
                FROM collection_videos cv
                JOIN videos v ON v.id = cv.video_id
                WHERE cv.collection_id = ?
                ORDER BY cv.sort_order ASC, cv.created_at ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute([$collectionId]);
        $allVideos = array_map([self::class, 'cast'], $stmt->fetchAll());

        $school = null;
        $studentClass = null;

        if (!empty($student['school_id'])) {
            $school = School::find($student['school_id']);
        }
        if (!empty($student['class_id'])) {
            $studentClass = ClassModel::find($student['class_id']);
        }

        $videos = [];
        foreach ($allVideos as $v) {
            if (self::_studentCanAccessPreFetched($v, $student, $school, $studentClass)) {
                $videos[] = $v;
            }
        }

        $progress = [];
        if (!empty($videos)) {
            $ids = array_column($videos, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pStmt = db()->prepare(
                "SELECT * FROM video_usage
                 WHERE student_id = ? AND video_id IN ($ph)"
            );
            $pStmt->execute(array_merge([$studentId], $ids));
            foreach ($pStmt->fetchAll() as $r) {
                $progress[$r['video_id']] = $r;
            }
        }

        $completed = 0;
        $inProgress = 0;
        $notStarted = 0;

        foreach ($videos as $v) {
            $vid = $v['id'];
            if (isset($progress[$vid])) {
                $pu = $progress[$vid];
                if ((int)$pu['completed'] === 1) {
                    $completed++;
                } elseif ((float)$pu['watch_percentage'] > 0) {
                    $inProgress++;
                } else {
                    $notStarted++;
                }
            } else {
                $notStarted++;
            }
        }

        $total = count($videos);
        $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        return [
            'videos'   => $videos,
            'progress' => $progress,
            'stats'    => [
                'total'        => $total,
                'completed'    => $completed,
                'in_progress'  => $inProgress,
                'not_started'  => $notStarted,
            ],
            'progress_percent' => $percent,
        ];
    }

    private static function _studentCanAccessPreFetched(
        array $video,
        array $student,
        ?array $school,
        ?array $studentClass
    ): bool {
        if ((int)$video['is_active'] !== 1 || $video['deleted_at'] !== null) return false;
        if (!$school) return false;
        if ((int)$school['is_active'] !== 1 || $school['deleted_at'] !== null) return false;

        $sType   = $school['school_type'] ?? 'elementary';
        $sGender = $school['gender'] ?? 'boys';

        if (($video['target_school_type'] ?? 'elementary') !== $sType) return false;

        $tg = $video['target_gender'] ?? 'both';
        if ($tg !== 'both' && $tg !== $sGender) return false;

        $videoGrade = Video::effectiveGrade($video);
        if ($videoGrade === null) {
            return true;
        }

        $studentGrade = null;
        if ($studentClass) {
            $studentGrade = ClassModel::effectiveGrade($studentClass, $sType);
        }
        if ($studentGrade === null) {
            $g = $student['grade'] ?? null;
            if ($g !== null && $g !== '') {
                if (is_numeric($g)) {
                    $n = (int)$g;
                    if ($n >= 1 && $n <= 12) {
                        $studentGrade = grade_belongs_to_level($n, $sType) ? $n : null;
                    }
                } else {
                    $studentGrade = map_grade_text_to_number((string)$g, $sType);
                }
            }
        }

        if ($studentGrade === null) return false;

        return $studentGrade === $videoGrade;
    }

    public static function computeStudentProgress(
        string $collectionId,
        string $studentId
    ): array {
        $student = Student::find($studentId);
        if (!$student) {
            return ['total' => 0, 'completed' => 0, 'progress_percent' => 0.0];
        }

        $result = self::getVideosForStudent($collectionId, $student);
        $stats = $result['stats'];

        return [
            'total'            => $stats['total'],
            'completed'        => $stats['completed'],
            'progress_percent' => $result['progress_percent'],
        ];
    }

    public static function publicShape(array $c, array $counts = [], array $progress = []): array
    {
        $coverUrl = null;
        if (!empty($c['cover_image'])) {
            $coverUrl = '/api/admin/video-collections/' . $c['id'] . '/cover';
        }

        return [
            'id'                => $c['id'],
            'title'             => $c['title'],
            'description'       => $c['description'],
            'cover_image'       => $c['cover_image'] ?? null,
            'cover_url'         => $coverUrl,
            'category'          => $c['category'],
            'level'             => $c['level'],
            'sort_order'        => (int)$c['sort_order'],
            'is_active'         => (bool)$c['is_active'],
            'deleted_at'        => self::iso($c['deleted_at']),
            'video_count'       => $counts['video_count'] ?? 0,
            'total_duration'    => $counts['total_duration'] ?? 0,
            'progress'          => !empty($progress) ? [
                'total_videos'     => $progress['total'] ?? 0,
                'completed_videos' => $progress['completed'] ?? 0,
                'percent'          => $progress['progress_percent'] ?? 0.0,
            ] : null,
            'created_at'        => self::iso($c['created_at']),
            'updated_at'        => self::iso($c['updated_at']),
        ];
    }

    public static function studentShape(array $c, array $counts = [], array $progress = []): array
    {
        $coverUrl = null;
        if (!empty($c['cover_image'])) {
            $coverUrl = '/api/admin/video-collections/' . $c['id'] . '/cover';
        }

        return [
            'id'             => $c['id'],
            'title'          => $c['title'],
            'description'    => $c['description'],
            'cover_url'      => $coverUrl,
            'category'       => $c['category'],
            'level'          => $c['level'],
            'video_count'    => $counts['video_count'] ?? 0,
            'total_duration' => $counts['total_duration'] ?? 0,
            'progress'       => [
                'total_videos'     => $progress['total'] ?? 0,
                'completed_videos' => $progress['completed'] ?? 0,
                'percent'          => $progress['progress_percent'] ?? 0.0,
            ],
        ];
    }
}


// =====================================================================
// COLLECTION ↔ VIDEO (Many-to-Many)
// =====================================================================

class CollectionVideo extends Model
{
    protected static string $table = 'collection_videos';
    protected static array $fillable = [
        'collection_id', 'video_id', 'sort_order',
    ];

    public static function findLink(string $collectionId, string $videoId): ?array
    {
        return self::findWhere([
            'collection_id' => $collectionId,
            'video_id'      => $videoId,
        ]);
    }

    public static function listVideosForCollection(
        string $collectionId,
        bool $onlyActive = true
    ): array {
        $sql = "SELECT v.*, cv.sort_order AS cv_sort_order, cv.id AS cv_id
                FROM collection_videos cv
                JOIN videos v ON v.id = cv.video_id
                WHERE cv.collection_id = ?";
        $params = [$collectionId];

        if ($onlyActive) {
            $sql .= " AND v.deleted_at IS NULL AND v.is_active = 1";
        }

        $sql .= " ORDER BY cv.sort_order ASC, cv.created_at ASC";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function listLinksForCollection(string $collectionId): array
    {
        return self::where(
            ['collection_id' => $collectionId],
            'sort_order ASC, created_at ASC'
        );
    }

    public static function listCollectionsForVideo(string $videoId): array
    {
        $sql = "SELECT c.*, cv.sort_order AS cv_sort_order
                FROM collection_videos cv
                JOIN video_collections c ON c.id = cv.collection_id
                WHERE cv.video_id = ?
                ORDER BY c.sort_order ASC, cv.sort_order ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute([$videoId]);
        return array_map([VideoCollection::class, 'cast'], $stmt->fetchAll());
    }

    public static function getNextSortOrder(string $collectionId): int
    {
        $stmt = db()->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) AS m
             FROM collection_videos
             WHERE collection_id = ?'
        );
        $stmt->execute([$collectionId]);
        $row = $stmt->fetch();
        return (int)($row['m'] ?? -1) + 1;
    }

    public static function addVideoSafe(
        string $collectionId,
        string $videoId
    ): array {
        if ($collectionId === '' || $videoId === '') {
            return [
                'ok' => false, 'id' => null, 'sort_order' => null,
                'error' => 'شناسه‌ها نامعتبر هستند.',
                'error_code' => 'INVALID_IDS',
            ];
        }

        $pdo = db();
        $attempts = 0;
        $maxAttempts = USAGE_RESERVATION_MAX_ATTEMPTS;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                $pdo->beginTransaction();

                $dup = $pdo->prepare(
                    'SELECT id FROM collection_videos
                     WHERE collection_id = ? AND video_id = ?'
                );
                $dup->execute([$collectionId, $videoId]);
                if ($dup->fetch()) {
                    $pdo->exec('ROLLBACK');
                    return [
                        'ok' => false, 'id' => null, 'sort_order' => null,
                        'error' => 'این ویدیو قبلاً به این Collection اضافه شده است.',
                        'error_code' => 'DUPLICATE_RELATION',
                    ];
                }

                $maxStmt = $pdo->prepare(
                    'SELECT COALESCE(MAX(sort_order), -1) AS m
                     FROM collection_videos WHERE collection_id = ?'
                );
                $maxStmt->execute([$collectionId]);
                $nextSort = (int)($maxStmt->fetch()['m'] ?? -1) + 1;

                $id = generate_uuid();
                $ins = $pdo->prepare(
                    'INSERT INTO collection_videos (id, collection_id, video_id, sort_order, created_at)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $ins->execute([$id, $collectionId, $videoId, $nextSort, utcnow()]);

                $pdo->exec('COMMIT');

                return [
                    'ok' => true, 'id' => $id, 'sort_order' => $nextSort,
                    'error' => null, 'error_code' => null,
                ];

            } catch (PDOException $e) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}

                $msg = $e->getMessage();

                if (stripos($msg, 'database is locked') !== false
                    || stripos($msg, 'database table is locked') !== false) {
                    usleep(USAGE_RESERVATION_LOCK_RETRY_US * $attempts);
                    continue;
                }

                if (stripos($msg, 'UNIQUE') !== false) {
                    if (stripos($msg, 'video_id') !== false
                        || stripos($msg, 'collection_id, video_id') !== false) {
                        return [
                            'ok' => false, 'id' => null, 'sort_order' => null,
                            'error' => 'این ویدیو قبلاً به این Collection اضافه شده است.',
                            'error_code' => 'DUPLICATE_RELATION',
                        ];
                    }
                    if (stripos($msg, 'sort_order') !== false
                        || stripos($msg, 'idx_cv_collection_sort_unique') !== false) {
                        usleep(USAGE_RESERVATION_LOCK_RETRY_US * $attempts);
                        continue;
                    }
                }

                logger_warn('[CollectionVideo::addVideoSafe] ' . $msg);
                return [
                    'ok' => false, 'id' => null, 'sort_order' => null,
                    'error' => 'خطا در افزودن ویدیو.',
                    'error_code' => 'DB_ERROR',
                ];

            } catch (Throwable $e) {
                try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
                logger_warn('[CollectionVideo::addVideoSafe] ' . $e->getMessage());
                return [
                    'ok' => false, 'id' => null, 'sort_order' => null,
                    'error' => 'خطای غیرمنتظره.',
                    'error_code' => 'UNKNOWN',
                ];
            }
        }

        return [
            'ok' => false, 'id' => null, 'sort_order' => null,
            'error' => 'سیستم مشغول است. لطفاً دوباره تلاش کنید.',
            'error_code' => 'LOCKED',
        ];
    }

    public static function setVideoOrder(
        string $collectionId,
        array $orderedVideoIds
    ): array {
        if (empty($orderedVideoIds)) {
            return ['ok' => false, 'error' => 'لیست ویدیوها خالی است.', 'updated' => 0];
        }

        $seen = [];
        $clean = [];
        foreach ($orderedVideoIds as $vid) {
            $vid = (string)$vid;
            if ($vid === '' || isset($seen[$vid])) continue;
            $seen[$vid] = true;
            $clean[] = $vid;
        }

        $existing = self::where(['collection_id' => $collectionId]);
        $existingIds = [];
        foreach ($existing as $link) {
            $existingIds[(string)$link['video_id']] = true;
        }

        foreach ($clean as $vid) {
            if (!isset($existingIds[$vid])) {
                return [
                    'ok' => false,
                    'error' => "ویدیو $vid در این Collection عضو نیست.",
                    'updated' => 0,
                ];
            }
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $tempOffset = -1 * (count($clean) + 1000);

            $upd = $pdo->prepare(
                'UPDATE collection_videos
                 SET sort_order = ?
                 WHERE collection_id = ? AND video_id = ?'
            );

            foreach ($clean as $idx => $vid) {
                $upd->execute([$tempOffset - $idx, $collectionId, $vid]);
            }

            $updated = 0;
            foreach ($clean as $idx => $vid) {
                $upd->execute([$idx, $collectionId, $vid]);
                $updated += $upd->rowCount();
            }

            $pdo->commit();
            return ['ok' => true, 'error' => null, 'updated' => $updated];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logger_warn('[COLLECTION-VIDEO] setVideoOrder failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'ذخیره ترتیب با خطا مواجه شد.', 'updated' => 0];
        }
    }

    public static function deleteAllForCollection(string $collectionId): int
    {
        $stmt = db()->prepare('DELETE FROM collection_videos WHERE collection_id = ?');
        $stmt->execute([$collectionId]);
        return $stmt->rowCount();
    }

    public static function deleteAllForVideo(string $videoId): int
    {
        $stmt = db()->prepare('DELETE FROM collection_videos WHERE video_id = ?');
        $stmt->execute([$videoId]);
        return $stmt->rowCount();
    }

    public static function publicShape(array $link, ?array $video = null): array
    {
        return [
            'id'            => $link['id'] ?? null,
            'collection_id' => $link['collection_id'] ?? null,
            'video_id'      => $link['video_id'] ?? null,
            'sort_order'    => (int)($link['sort_order'] ?? 0),
            'video'         => $video ? [
                'id'       => $video['id'],
                'title'    => $video['title'],
                'duration' => isset($video['duration']) ? (int)$video['duration'] : null,
                'grade'    => Video::effectiveGrade($video),
                'subject'  => $video['subject'] ?? null,
            ] : null,
            'created_at'    => self::iso($link['created_at'] ?? null),
        ];
    }
}


// =====================================================================
// SUBSCRIPTION
// =====================================================================

class Subscription extends Model
{
    protected static string $table = 'subscriptions';
    protected static array $fillable = [
        'school_id', 'plan_name', 'start_date', 'end_date', 'status', 'limits_config',
    ];
    protected static array $jsonColumns = ['limits_config'];

    public static function listBySchool(string $schoolId): array
    {
        return self::where(['school_id' => $schoolId], 'created_at DESC');
    }

    public static function listPaged(
        ?string $schoolId = null,
        ?string $status = null,
        int $page = 1,
        int $pageSize = 25
    ): array {
        $sql = 'SELECT s.*, sc.name AS school_name
                FROM subscriptions s
                LEFT JOIN schools sc ON sc.id = s.school_id
                WHERE 1=1';
        $params = [];

        if ($schoolId) { $sql .= ' AND s.school_id = ?'; $params[] = $schoolId; }
        if ($status)   { $sql .= ' AND s.status = ?';    $params[] = $status; }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY s.created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return ['items' => $rows, 'total' => $total];
    }

    public static function publicShape(array $s): array
    {
        return [
            'id'           => $s['id'],
            'school_id'    => $s['school_id'],
            'school_name'  => $s['school_name'] ?? null,
            'plan_name'    => $s['plan_name'],
            'start_date'   => self::iso($s['start_date']),
            'end_date'     => self::iso($s['end_date']),
            'status'       => $s['status'],
            'limits_config'=> self::decodeJson($s['limits_config'] ?? null),
            'created_at'   => self::iso($s['created_at']),
            'updated_at'   => self::iso($s['updated_at']),
        ];
    }
}


// =====================================================================
// SYSTEM SETTING
// =====================================================================

class SystemSetting extends Model
{
    protected static string $table = 'system_settings';
    protected static array $fillable = ['key', 'value', 'value_type', 'description'];

    public static function listAll(): array
    {
        // `key` is a MySQL reserved word — must be backticked
        return self::where([], '`key` ASC');
    }

    public static function publicShape(array $s): array
    {
        return [
            'id'          => $s['id'],
            'key'         => $s['key'],
            'value'       => $s['value'],
            'value_type'  => $s['value_type'],
            'description' => $s['description'],
            'updated_at'  => self::iso($s['updated_at']),
        ];
    }
}


// =====================================================================
// AUDIT LOG
// =====================================================================

class AuditLog extends Model
{
    protected static string $table = 'audit_logs';
    protected static array $fillable = [
        'actor_user_id', 'action', 'entity_type', 'entity_id',
        'log_metadata', 'ip_address', 'user_agent',
    ];
    protected static array $jsonColumns = ['log_metadata'];

    public static function listPaged(
        ?string $action = null,
        ?string $entityType = null,
        ?string $actorUserId = null,
        ?string $search = null,
        int $page = 1,
        int $pageSize = 50
    ): array {
        $sql = 'SELECT * FROM audit_logs WHERE 1=1';
        $params = [];

        if ($action)     { $sql .= ' AND action = ?';        $params[] = $action; }
        if ($entityType) { $sql .= ' AND entity_type = ?';   $params[] = $entityType; }
        if ($actorUserId){ $sql .= ' AND actor_user_id = ?'; $params[] = $actorUserId; }
        if ($search && trim($search) !== '') {
            $sql .= ' AND (action LIKE ? OR entity_type LIKE ?)';
            $params[] = '%' . trim($search) . '%';
            $params[] = '%' . trim($search) . '%';
        }

        $countStmt = db()->prepare('SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub');
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (($page - 1) * $pageSize);
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $actorIds = array_values(array_unique(array_filter(array_column($rows, 'actor_user_id'))));
        $actors = [];
        if ($actorIds) {
            $ph = implode(',', array_fill(0, count($actorIds), '?'));
            $aStmt = db()->prepare("SELECT id, username, full_name FROM users WHERE id IN ($ph)");
            $aStmt->execute($actorIds);
            foreach ($aStmt->fetchAll() as $r) $actors[$r['id']] = $r;
        }

        return ['items' => $rows, 'total' => $total, 'actors' => $actors];
    }

    public static function publicShape(array $l, ?array $actor = null): array
    {
        return [
            'id'             => $l['id'],
            'actor_user_id'  => $l['actor_user_id'],
            'actor_username' => $actor['username'] ?? null,
            'actor_full_name'=> $actor['full_name'] ?? null,
            'action'         => $l['action'],
            'entity_type'    => $l['entity_type'],
            'entity_id'      => $l['entity_id'],
            'metadata'       => self::decodeJson($l['log_metadata'] ?? null),
            'ip_address'     => $l['ip_address'],
            'user_agent'     => $l['user_agent'],
            'created_at'     => self::iso($l['created_at']),
        ];
    }

    public static function exportAll(int $limit = 10000): array
    {
        $stmt = db()->prepare('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT ' . (int)$limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}


// =====================================================================
// DOWNLOAD RELEASE
// =====================================================================

class DownloadRelease extends Model
{
    protected static string $table = 'download_releases';
    protected static array $fillable = [
        'title', 'description', 'platform', 'version', 'release_type',
        'file_name', 'stored_file_name', 'file_path', 'external_url',
        'file_size', 'mime_type', 'checksum', 'min_os_version',
        'is_published', 'is_latest', 'is_active',
        'published_at', 'deleted_at',
        'created_by', 'updated_by',
    ];
    protected static array $boolColumns = ['is_published', 'is_latest', 'is_active'];

    public static function listPaged(
        array $filters = [],
        int $page = 1,
        int $pageSize = 25
    ): array {
        $page     = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        $sql = 'SELECT * FROM download_releases WHERE 1=1';
        $params = [];

        if (empty($filters['include_deleted'])) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $search = $filters['search'] ?? null;
        if ($search !== null && trim((string)$search) !== '') {
            $sql .= ' AND (title LIKE ? OR version LIKE ?)';
            $term = '%' . trim((string)$search) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $platform = $filters['platform'] ?? null;
        if ($platform !== null && in_array($platform, ['windows', 'android', 'ios'], true)) {
            $sql .= ' AND platform = ?';
            $params[] = $platform;
        }

        if (array_key_exists('is_published', $filters) && $filters['is_published'] !== null) {
            $sql .= ' AND is_published = ?';
            $params[] = $filters['is_published'] ? 1 : 0;
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = $filters['is_active'] ? 1 : 0;
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

    public static function listPublic(): array
    {
        $sql = 'SELECT * FROM download_releases
                WHERE is_published = 1
                  AND is_active = 1
                  AND deleted_at IS NULL
                ORDER BY platform ASC, published_at DESC';
        $stmt = db()->query($sql);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function listLatestPublic(): array
    {
        $sql = 'SELECT * FROM download_releases
                WHERE is_published = 1
                  AND is_active = 1
                  AND is_latest = 1
                  AND deleted_at IS NULL';
        $stmt = db()->query($sql);
        return array_map([self::class, 'cast'], $stmt->fetchAll());
    }

    public static function setLatest(string $id): array
    {
        $release = self::find($id);
        if (!$release) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        // ✅ Bug #25 FIX
        if ($release['deleted_at'] !== null) {
            return ['ok' => false, 'error' => 'deleted'];
        }
        if ((int)$release['is_published'] !== 1) {
            return ['ok' => false, 'error' => 'not_published'];
        }
        if ((int)$release['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'not_active'];
        }
        if ($release['deleted_at'] !== null) {
            return ['ok' => false, 'error' => 'deleted'];
        }

        $pdo = db();
        try {
            $pdo->beginTransaction();

            $pdo->prepare('UPDATE download_releases SET is_latest = 0 WHERE platform = ?')
                ->execute([$release['platform']]);
            $pdo->prepare('UPDATE download_releases SET is_latest = 1, updated_at = ? WHERE id = ?')
                ->execute([utcnow(), $id]);

            $pdo->commit();
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            logger_warn('[DOWNLOAD] setLatest failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db_error'];
        }
    }

    public static function publicShape(array $r): array
    {
        return [
            'id'             => $r['id'],
            'title'          => $r['title'],
            'description'    => $r['description'],
            'platform'       => $r['platform'],
            'version'        => $r['version'],
            'release_type'   => $r['release_type'],
            'file_name'      => $r['file_name'],
            'file_size'      => $r['file_size'] !== null ? (int)$r['file_size'] : null,
            'mime_type'      => $r['mime_type'],
            'external_url'   => $r['release_type'] === 'store_link' ? $r['external_url'] : null,
            'min_os_version' => $r['min_os_version'],
            'is_latest'      => (bool)$r['is_latest'],
            'published_at'   => self::iso($r['published_at']),
            'download_url'   => $r['release_type'] === 'file'
                                ? '/api/downloads/' . $r['id'] . '/file'
                                : null,
        ];
    }

    public static function adminShape(array $r): array
    {
        return array_merge(self::publicShape($r), [
            'is_published'     => (bool)$r['is_published'],
            'is_active'        => (bool)$r['is_active'],
            'deleted_at'       => self::iso($r['deleted_at']),
            'checksum'         => $r['checksum'],
            'stored_file_name' => $r['stored_file_name'],
            'created_by'       => $r['created_by'],
            'updated_by'       => $r['updated_by'],
            'created_at'       => self::iso($r['created_at']),
            'updated_at'       => self::iso($r['updated_at']),
        ]);
    }
}


// =====================================================================
// PAGINATION HELPER
// =====================================================================

function paginate(int $total, int $page, int $pageSize): array
{
    $page = max(1, $page);
    $pageSize = max(1, $pageSize);
    $total = max(0, $total);
    $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 0;

    return [
        'total'       => $total,
        'page'        => $page,
        'page_size'   => $pageSize,
        'total_pages' => $totalPages,
        'has_more'    => $page < $totalPages,
    ];
}
