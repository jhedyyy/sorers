<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$orgs = all("SELECT o.*, a.full_name AS adviser,
                    (SELECT COUNT(*) FROM memberships m WHERE m.org_id=o.id AND m.status='active') AS members,
                    (SELECT COUNT(*) FROM events e WHERE e.org_id=o.id AND e.status='published' AND e.end_at >= ?) AS upcoming
               FROM organizations o LEFT JOIN users a ON a.id = o.adviser_id ORDER BY o.name", [now()]);

page_header('Organizations');
if ($u['role'] === 'admin') echo '<p><a class="btn" href="org_form.php">New organization</a></p>';
if (!$orgs) echo '<p class="muted">No organizations yet.</p>';
echo '<div class="grid">';
foreach ($orgs as $o) {
    echo '<div class="card"><h2><a href="org.php?id=' . (int) $o['id'] . '">' . e($o['name']) . '</a></h2>'
       . '<p class="muted">' . e($o['course_scope'] ?: '') . '</p>'
       . '<p>' . e(mb_strimwidth((string) $o['description'], 0, 140, '…')) . '</p>'
       . '<p class="muted">' . (int) $o['members'] . ' members · ' . (int) $o['upcoming'] . ' upcoming event(s)'
       . ($o['adviser'] ? ' · Adviser: ' . e($o['adviser']) : '') . '</p></div>';
}
echo '</div>';
page_footer();
