<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$id = (int) ($_GET['event'] ?? 0);
$ev = one('SELECT * FROM events WHERE id = ?', [$id]);
if (!$ev || !can_view_roster((int) $ev['org_id'], $u)) forbid('You cannot export that.');

$rows = all("SELECT u.student_no, u.full_name, u.course, u.email, r.created_at, a.status AS att
               FROM registrations r JOIN users u ON u.id = r.user_id
               LEFT JOIN attendance a ON a.registration_id = r.id
              WHERE r.event_id = ? AND r.status = 'registered' ORDER BY u.full_name", [$id]);

$name = preg_replace('/[^A-Za-z0-9]+/', '-', $ev['title']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="registrants-' . trim($name, '-') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
fputcsv($out, ['Student no.', 'Name', 'Course', 'Email', 'Registered at', 'Attendance']);
foreach ($rows as $r) {
    fputcsv($out, array_map('csv_safe', [$r['student_no'], $r['full_name'], $r['course'], $r['email'], $r['created_at'], $r['att'] ?? 'not marked']));
}
fclose($out);
