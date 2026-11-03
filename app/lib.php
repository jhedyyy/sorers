<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const ROLES = [
    'student' => 'Student',
    'officer' => 'Organization officer',
    'adviser' => 'Organization adviser',
    'staff'   => 'Student affairs staff',
    'admin'   => 'System administrator',
];

$GLOBALS['config'] = require __DIR__ . '/../config.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['config'][$key] ?? $default;
}

/* ---------- database ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $c = cfg('db');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if ($c['driver'] === 'sqlite') {
        @mkdir(dirname($c['sqlite_path']), 0775, true);
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r ?: null;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

function insert_id(): int
{
    return (int) db()->lastInsertId();
}

/* ---------- output, redirects, flash, CSRF ---------- */

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $ok = isset($_POST['_csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string) $_POST['_csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Invalid or expired form. Go back, refresh the page, and try again.');
    }
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function post(string $k, string $default = ''): string
{
    return trim((string) ($_POST[$k] ?? $default));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_dt(?string $s): string
{
    return $s ? date('D, M j, Y · g:i A', strtotime($s)) : '';
}

function to_input_dt(?string $s): string
{
    return $s ? date('Y-m-d\TH:i', strtotime($s)) : '';
}

function from_input_dt(string $s): ?string
{
    $t = strtotime($s);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/* ---------- authentication and roles ---------- */

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    if (!empty($_SESSION['uid'])) {
        $u = one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['uid']]);
        if ($u) {
            $cache = $u;
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $cache;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        page_header('Not allowed');
        echo '<div class="card"><h2>Not allowed</h2><p>Your role (' . e(ROLES[$u['role']]) . ') cannot open this page.</p></div>';
        page_footer();
        exit;
    }
    return $u;
}

function forbid(string $msg = 'You do not have access to that.'): never
{
    flash('error', $msg);
    redirect('dashboard.php');
}

/** Admin manages everything; an officer manages organizations where they hold an active officer membership. */
function can_manage_org(int $orgId, ?array $u = null): bool
{
    $u ??= current_user();
    if (!$u) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    if ($u['role'] !== 'officer') {
        return false;
    }
    return (bool) val(
        "SELECT 1 FROM memberships WHERE org_id = ? AND user_id = ? AND is_officer = 1 AND status = 'active'",
        [$orgId, $u['id']]
    );
}

/** Who may see an organization's member list and an event's registrants/attendance. */
function can_view_roster(int $orgId, ?array $u = null): bool
{
    $u ??= current_user();
    if (!$u) {
        return false;
    }
    if (in_array($u['role'], ['admin', 'staff'], true)) {
        return true;
    }
    if ($u['role'] === 'adviser') {
        return (bool) val('SELECT 1 FROM organizations WHERE id = ? AND adviser_id = ?', [$orgId, $u['id']]);
    }
    return can_manage_org($orgId, $u);
}

function managed_org_ids(array $u): array
{
    if ($u['role'] === 'admin') {
        return array_column(all('SELECT id FROM organizations'), 'id');
    }
    if ($u['role'] !== 'officer') {
        return [];
    }
    return array_column(all(
        "SELECT org_id FROM memberships WHERE user_id = ? AND is_officer = 1 AND status = 'active'",
        [$u['id']]
    ), 'org_id');
}

/** Keep users.role in step with officer memberships (student <-> officer). */
function sync_officer_role(int $userId): void
{
    $u = one('SELECT role FROM users WHERE id = ?', [$userId]);
    if (!$u || !in_array($u['role'], ['student', 'officer'], true)) {
        return;
    }
    $n = (int) val("SELECT COUNT(*) FROM memberships WHERE user_id = ? AND is_officer = 1 AND status = 'active'", [$userId]);
    $role = $n > 0 ? 'officer' : 'student';
    if ($role !== $u['role']) {
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $userId]);
    }
}

/* ---------- events and registration ---------- */

function events_with_counts(string $where = '1=1', array $params = [], string $order = 'e.start_at ASC'): array
{
    return all(
        "SELECT e.*, o.name AS org_name,
                (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.id AND r.status = 'registered') AS reg_count
           FROM events e JOIN organizations o ON o.id = e.org_id
          WHERE $where ORDER BY $order",
        $params
    );
}

/** @return array{0:string,1:string} [code, label] */
function event_state(array $ev): array
{
    $now = time();
    if ($ev['status'] === 'cancelled') {
        return ['cancelled', 'Cancelled'];
    }
    if ($ev['status'] === 'draft') {
        return ['draft', 'Draft'];
    }
    if (strtotime($ev['end_at']) < $now) {
        return ['ended', 'Ended'];
    }
    if (strtotime($ev['reg_deadline']) < $now) {
        return ['closed', 'Registration closed'];
    }
    if ($ev['capacity'] !== null && (int) $ev['reg_count'] >= (int) $ev['capacity']) {
        return ['full', 'Full'];
    }
    return ['open', 'Open'];
}

/** @return array{0:bool,1:string} */
function register_for_event(int $eventId, int $userId): array
{
    $ev = events_with_counts('e.id = ?', [$eventId])[0] ?? null;
    if (!$ev || $ev['status'] === 'draft') {
        return [false, 'Event not found.'];
    }
    [$state] = event_state($ev);
    $existing = one('SELECT * FROM registrations WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
    if ($existing && $existing['status'] === 'registered') {
        return [false, 'You are already registered for this event.'];
    }
    $blocked = [
        'cancelled' => 'This event was cancelled.',
        'ended'     => 'This event has already ended.',
        'closed'    => 'Registration for this event is closed.',
        'full'      => 'This event is full.',
    ];
    if (isset($blocked[$state])) {
        return [false, $blocked[$state]];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($existing) {
            q("UPDATE registrations SET status = 'registered', created_at = ? WHERE id = ?", [now(), $existing['id']]);
        } else {
            q('INSERT INTO registrations (event_id, user_id, status, created_at) VALUES (?, ?, ?, ?)',
                [$eventId, $userId, 'registered', now()]);
        }
        // Re-check the limit inside the transaction so two simultaneous sign-ups cannot exceed it.
        $count = (int) val("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'registered'", [$eventId]);
        if ($ev['capacity'] !== null && $count > (int) $ev['capacity']) {
            $pdo->rollBack();
            return [false, 'This event is full.'];
        }
        $pdo->commit();
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Could not save your registration. Please try again.'];
    }
    return [true, 'You are registered. See you there!'];
}

/** @return array{0:bool,1:string} */
function cancel_registration(int $eventId, int $userId): array
{
    $ev = one('SELECT * FROM events WHERE id = ?', [$eventId]);
    $reg = one("SELECT * FROM registrations WHERE event_id = ? AND user_id = ? AND status = 'registered'", [$eventId, $userId]);
    if (!$ev || !$reg) {
        return [false, 'No active registration found.'];
    }
    if (strtotime($ev['start_at']) < time()) {
        return [false, 'The event has already started, so the registration can no longer be cancelled.'];
    }
    q("UPDATE registrations SET status = 'cancelled' WHERE id = ?", [$reg['id']]);
    q('DELETE FROM attendance WHERE registration_id = ?', [$reg['id']]);
    return [true, 'Your registration was cancelled.'];
}

/** Neutralize spreadsheet formulas in CSV cells. */
function csv_safe($v): string
{
    $s = (string) $v;
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
}
