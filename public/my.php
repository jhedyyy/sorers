<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role('student', 'officer');

$regs = all("SELECT e.id, e.title, e.start_at, e.venue, e.status AS ev_status, o.name AS org_name, a.status AS att
               FROM registrations r JOIN events e ON e.id = r.event_id JOIN organizations o ON o.id = e.org_id
               LEFT JOIN attendance a ON a.registration_id = r.id
              WHERE r.user_id = ? AND r.status = 'registered' ORDER BY e.start_at DESC", [$u['id']]);
$mems = all('SELECT m.*, o.name FROM memberships m JOIN organizations o ON o.id = m.org_id WHERE m.user_id = ? ORDER BY o.name', [$u['id']]);
$attended = count(array_filter($regs, fn($r) => $r['att'] === 'present'));

page_header('My activity');
?>
<div class="stats">
  <div class="stat"><b><?= count($mems) ?></b><span>Organizations</span></div>
  <div class="stat"><b><?= count($regs) ?></b><span>Registrations</span></div>
  <div class="stat"><b><?= $attended ?></b><span>Events attended</span></div>
</div>
<div class="card"><h2>My organizations</h2>
  <?php if (!$mems): ?><p class="muted">You have not joined any organization. <a href="orgs.php">Browse organizations</a>.</p><?php endif; ?>
  <?php foreach ($mems as $m): ?>
    <p><a href="org.php?id=<?= (int) $m['org_id'] ?>"><?= e($m['name']) ?></a> · <?= e($m['position']) ?>
      <?= $m['status'] === 'pending' ? badge('pending', 'Pending approval') : '' ?></p>
  <?php endforeach; ?>
</div>
<div class="card"><h2>My registrations and attendance</h2>
  <?php if (!$regs): ?><p class="muted">No registrations yet. <a href="events.php">Find an event</a>.</p><?php else: ?>
  <div class="scroll"><table>
    <tr><th>Event</th><th>Organization</th><th>Date</th><th>Attendance</th></tr>
    <?php foreach ($regs as $r): ?>
    <tr><td><a href="event.php?id=<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a><?= $r['ev_status'] === 'cancelled' ? ' ' . badge('cancelled', 'Cancelled') : '' ?></td>
        <td><?= e($r['org_name']) ?></td><td><?= e(fmt_dt($r['start_at'])) ?></td>
        <td><?= $r['att'] ? badge($r['att'], ucfirst($r['att'])) : '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; ?>
  </table></div><?php endif; ?>
</div>
<?php page_footer();
