<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role('adviser', 'staff', 'admin');

$scope = $u['role'] === 'adviser' ? 'WHERE o.adviser_id = ' . (int) $u['id'] : '';
$rows = all("SELECT o.id, o.name, a.full_name AS adviser,
        (SELECT COUNT(*) FROM memberships m WHERE m.org_id=o.id AND m.status='active') AS members,
        (SELECT COUNT(*) FROM events e WHERE e.org_id=o.id AND e.status='published') AS events,
        (SELECT COUNT(*) FROM registrations r JOIN events e ON e.id=r.event_id WHERE e.org_id=o.id AND e.status='published' AND r.status='registered') AS regs,
        (SELECT COUNT(*) FROM attendance t JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id WHERE e.org_id=o.id AND t.status='present') AS present,
        (SELECT COUNT(*) FROM attendance t JOIN registrations r ON r.id=t.registration_id JOIN events e ON e.id=r.event_id WHERE e.org_id=o.id) AS marked
   FROM organizations o LEFT JOIN users a ON a.id = o.adviser_id $scope ORDER BY o.name");

page_header('Reports');
?>
<div class="card">
  <h2><?= $u['role'] === 'adviser' ? 'Your organizations' : 'Activity across all organizations' ?></h2>
  <p class="muted">Attendance rate = present ÷ attendance marked. Open an organization to see its events and download registrant lists.</p>
  <div class="scroll"><table>
    <tr><th>Organization</th><th>Adviser</th><th>Members</th><th>Published events</th><th>Registrations</th><th>Attendance rate</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr><td><a href="org.php?id=<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a></td><td><?= e($r['adviser'] ?: '—') ?></td>
        <td><?= (int) $r['members'] ?></td><td><?= (int) $r['events'] ?></td><td><?= (int) $r['regs'] ?></td>
        <td><?= $r['marked'] ? round(100 * $r['present'] / $r['marked']) . '% (' . (int) $r['present'] . '/' . (int) $r['marked'] . ')' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing to report yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php page_footer();
