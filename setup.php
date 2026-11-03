<?php
// Usage:  php setup.php            create tables and an admin account
//         php setup.php --demo     also load demo organizations, events and users
//         php setup.php --fresh    wipe the database first (add --demo if wanted)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line: php setup.php');
}
require __DIR__ . '/app/lib.php';

$demo  = in_array('--demo', $argv, true);
$fresh = in_array('--fresh', $argv, true);
$c = cfg('db');

// 1. Make sure the database exists (and wipe it for --fresh).
if ($c['driver'] === 'sqlite') {
    if ($fresh && file_exists($c['sqlite_path'])) {
        unlink($c['sqlite_path']);
    }
} else {
    $root = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $name = str_replace('`', '', $c['name']);
    if ($fresh) {
        $root->exec("DROP DATABASE IF EXISTS `$name`");
    }
    $root->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

// 2. Create tables.
$pdo = db();
$exists = $c['driver'] === 'sqlite'
    ? (bool) val("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")
    : (bool) val("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'");
if ($exists) {
    exit("Tables already exist. Use --fresh to wipe and rebuild.\n");
}
$sql = file_get_contents(__DIR__ . '/database/schema.sql');
if ($c['driver'] === 'sqlite') {
    $sql = str_replace('AUTO_INCREMENT', 'AUTOINCREMENT', $sql);
}
$sql = preg_replace('/--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
echo "Tables created.\n";

// 3. Accounts.
function mkuser(string $name, string $email, string $role, ?string $no, ?string $course, string $pw): int
{
    q('INSERT INTO users (student_no, full_name, email, password_hash, role, course, created_at) VALUES (?,?,?,?,?,?,?)',
        [$no, $name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $course, now()]);
    return insert_id();
}

if (!$demo) {
    $pw = getenv('ADMIN_PASSWORD') ?: bin2hex(random_bytes(6));
    mkuser('System Administrator', 'admin@icct.test', 'admin', null, null, $pw);
    echo "Admin account: admin@icct.test / $pw   (change the email and password after first login)\n";
    exit;
}

$pw = 'Password123!';
mkuser('System Administrator', 'admin@icct.test', 'admin', null, null, $pw);
$staff   = mkuser('Sofia Reyes (Student Affairs)', 'staff@icct.test', 'staff', null, null, $pw);
$advIT   = mkuser('Ms. Eva Jaravata', 'adviser1@icct.test', 'adviser', null, null, $pw);
$advBA   = mkuser('Mr. Carlo Mendoza', 'adviser2@icct.test', 'adviser', null, null, $pw);
$students = [];
$people = [
    ['Juan Dela Cruz',  '2024-0001', 'BSIT'], ['Maria Santos',    '2024-0002', 'BSIT'],
    ['Pedro Garcia',    '2024-0003', 'BSCS'], ['Ana Lim',         '2024-0004', 'BSBA'],
    ['Luis Torres',     '2024-0005', 'BSBA'], ['Carla Navarro',   '2024-0006', 'BSTM'],
    ['Mark Villanueva', '2024-0007', 'BSIT'], ['Joy Aquino',      '2024-0008', 'BSHM'],
];
foreach ($people as $i => [$n, $no, $course]) {
    $students[$i] = mkuser($n, 'student' . ($i + 1) . '@icct.test', 'student', $no, $course, $pw);
}

q('INSERT INTO organizations (name, description, course_scope, adviser_id, created_at) VALUES (?,?,?,?,?)',
    ['Junior Philippine Computer Society', 'Workshops, hackathons and tech talks for IT and computer studies students.', 'Open to all courses', $advIT, now()]);
$jpcs = insert_id();
q('INSERT INTO organizations (name, description, course_scope, adviser_id, created_at) VALUES (?,?,?,?,?)',
    ['Business Administration Society', 'Career talks, entrepreneurship fairs and business competitions.', 'Open to all courses', $advBA, now()]);
$bas = insert_id();

$mem = function (int $org, int $user, string $pos, int $off, string $status = 'active') {
    q('INSERT INTO memberships (org_id, user_id, position, is_officer, status, joined_at) VALUES (?,?,?,?,?,?)',
        [$org, $user, $pos, $off, $status, now()]);
};
$mem($jpcs, $students[0], 'President', 1);
$mem($jpcs, $students[1], 'Secretary', 1);
$mem($jpcs, $students[2], 'Member', 0);
$mem($jpcs, $students[6], 'Member', 0);
$mem($jpcs, $students[3], 'Member', 0, 'pending');
$mem($bas,  $students[4], 'President', 1);
$mem($bas,  $students[3], 'Member', 0);
$mem($bas,  $students[5], 'Member', 0);
foreach ([$students[0], $students[1], $students[4]] as $uid) {
    sync_officer_role($uid);
}

$ev = function (int $org, string $title, string $desc, string $venue, string $start, int $hours, string $deadline, ?int $cap, string $status, int $by) {
    q('INSERT INTO events (org_id,title,description,venue,start_at,end_at,reg_deadline,capacity,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$org, $title, $desc, $venue, date('Y-m-d H:i:s', strtotime($start)), date('Y-m-d H:i:s', strtotime($start . " +$hours hours")),
         date('Y-m-d H:i:s', strtotime($deadline)), $cap, $status, $by, now()]);
    return insert_id();
};
$e1 = $ev($jpcs, 'Intro to Web Development Workshop', 'Hands-on HTML, CSS and JavaScript for beginners. Bring a laptop.', 'Computer Lab 2', '+7 days 13:00', 3, '+5 days 17:00', 30, 'published', $students[0]);
$e2 = $ev($jpcs, 'Hackathon Info Session', 'Learn how the campus hackathon works and form teams.', 'AVR', '+14 days 15:00', 2, '+12 days 17:00', 3, 'published', $students[0]);
$e3 = $ev($bas,  'Entrepreneurship Fair', 'Student-run booths and guest speakers.', 'Gymnasium', '+10 days 09:00', 6, '+8 days 17:00', null, 'published', $students[4]);
$e4 = $ev($jpcs, 'Git and GitHub Basics', 'Version control for group projects.', 'Computer Lab 1', '-5 days 13:00', 2, '-6 days 17:00', 40, 'published', $students[1]);
$ev($bas, 'Career Talk (draft)', 'Speaker still to be confirmed.', 'AVR', '+20 days 14:00', 2, '+18 days 17:00', 50, 'draft', $students[4]);

$reg = function (int $e, int $u, bool $att = false, string $a = '') {
    q("INSERT INTO registrations (event_id,user_id,status,created_at) VALUES (?,?, 'registered', ?)", [$e, $u, now()]);
    $rid = insert_id();
    if ($a) {
        q('INSERT INTO attendance (registration_id,status,marked_by,marked_at) VALUES (?,?,?,?)', [$rid, $a, null, now()]);
    }
};
$reg($e1, $students[2]); $reg($e1, $students[6]); $reg($e1, $students[3]);
$reg($e2, $students[2]); $reg($e2, $students[6]); $reg($e2, $students[1]);   // 3 of 3: full
$reg($e3, $students[3]); $reg($e3, $students[5]);
$reg($e4, $students[2], true, 'present'); $reg($e4, $students[6], true, 'present'); $reg($e4, $students[3], true, 'absent');

echo "Demo data loaded. Every demo account uses the password: $pw\n";
echo "  admin@icct.test   staff@icct.test   adviser1@icct.test   adviser2@icct.test\n";
echo "  student1@icct.test (JPCS officer)   student5@icct.test (BAS officer)   student3@icct.test (plain student)\n";
