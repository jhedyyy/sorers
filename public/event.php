<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$id = (int) ($_GET['id'] ?? 0);
$ev = events_with_counts('e.id = ?', [$id])[0] ?? null;
if (!$ev) forbid('Event not found.');

$orgId  = (int) $ev['org_id'];
$manage = can_manage_org($orgId, $u);
$roster = can_view_roster($orgId, $u);
if ($ev['status'] === 'draft' && !$manage) forbid('Event not found.');
$self = 'event.php?id=' . $id;
$isStudent = in_array($u['role'], ['student', 'officer'], true);

if (is_post()) {
    csrf_verify();
    switch (post('action')) {
        case 'register':
            if (!$isStudent) forbid('Only students can register for events.');
            [$ok, $msg] = register_for_event($id, (int) $u['id']);
            flash($ok ? 'ok' : 'error', $msg);
            break;

        case 'cancel_reg':
            [$ok, $msg] = cancel_registration($id, (int) $u['id']);
            flash($ok ? 'ok' : 'error', $msg);
            break;

        case 'mark':
            if (!$manage || $ev['status'] !== 'published') forbid();
            $regs = array_column(all("SELECT id FROM registrations WHERE event_id = ? AND status='registered'", [$id]), 'id');
            foreach ((array) ($_POST['att'] ?? []) as $rid => $status) {
                $rid = (int) $rid;
                if (!in_array($rid, $regs, true)) continue;       // only this event's active registrants
                if ($status === 'present' || $status === 'absent') {
                    if (one('SELECT 1 FROM attendance WHERE registration_id = ?', [$rid])) {
                        q('UPDATE attendance SET status=?, marked_by=?, marked_at=? WHERE registration_id=?', [$status, $u['id'], now(), $rid]);
                    } else {
                        q('INSERT INTO attendance (registration_id,status,marked_by,marked_at) VALUES (?,?,?,?)', [$rid, $status, $u['id'], now()]);
                    }
                } else {
                    q('DELETE FROM attendance WHERE registration_id = ?', [$rid]);
                }
            }
            flash('ok', 'Attendance saved.');
            break;

        case 'publish':
            if (!$manage || $ev['status'] !== 'draft') forbid();
            q("UPDATE events SET status='published' WHERE id = ?", [$id]);
            flash('ok', 'Event published.');
            break;

        case 'unpublish':
            if (!$manage || $ev['status'] !== 'published' || $ev['reg_count'] > 0) { flash('error', 'Only a published event with no registrants can go back to draft. Cancel it instead.'); break; }
            q("UPDATE events SET status='draft' WHERE id = ?", [$id]);
            flash('ok', 'Event moved back to draft.');
            break;

        case 'cancel_event':
            if (!$manage || $ev['status'] === 'cancelled') forbid();
            q("UPDATE events SET status='cancelled' WHERE id = ?", [$id]);
            flash('ok', 'Event cancelled. Registrants will see it as cancelled.');
            break;

        case 'delete':
            if (!$manage || $ev['status'] !== 'draft') forbid('Only drafts can be deleted.');
            q('DELETE FROM events WHERE id = ?', [$id]);
            flash('ok', 'Draft deleted.');
            redirect('org.php?id=' . $orgId);

        default:
            forbid('Unknown action.');
    }
    redirect($self);
}

[$code, $label] = event_state($ev);
$myReg = one("SELECT * FROM registrations WHERE event_id = ? AND user_id = ? AND status='registered'", [$id, $u['id']]);
$myAtt = $myReg ? one('SELECT status FROM attendance WHERE registration_id = ?', [$myReg['id']]) : null;
$rows = $roster ? all("SELECT r.id AS rid, r.created_at, u.full_name, u.student_no, u.course, a.status AS att
                         FROM registrations r JOIN users u ON u.id = r.user_id
                         LEFT JOIN attendance a ON a.registration_id = r.id
                        WHERE r.event_id = ? AND r.status = 'registered' ORDER BY u.full_name", [$id]) : [];

page_header($ev['title']);
?>
<div class="card">
  <p><?= badge($code, $label) ?> · <a href="org.php?id=<?= $orgId ?>"><?= e($ev['org_name']) ?></a></p>
  <p><?= nl2br(e($ev['description'])) ?></p>
  <table>
    <tr><th>When</th><td><?= e(fmt_dt($ev['start_at'])) ?> – <?= e(date('g:i A', strtotime($ev['end_at']))) ?></td></tr>
    <tr><th>Where</th><td><?= e($ev['venue']) ?></td></tr>
    <tr><th>Register by</th><td><?= e(fmt_dt($ev['reg_deadline'])) ?></td></tr>
    <tr><th>Registered</th><td><?= (int) $ev['reg_count'] ?><?= $ev['capacity'] !== null ? ' of ' . (int) $ev['capacity'] . ' slots' : ' (no limit)' ?></td></tr>
  </table>

  <div class="actions">
    <?php if ($isStudent && $ev['status'] !== 'draft'): ?>
      <?php if ($myReg): ?>
        <?= badge('active', 'You are registered') ?>
        <?php if ($myAtt): ?><?= badge($myAtt['status'], 'Attendance: ' . $myAtt['status']) ?><?php endif; ?>
        <?php if (strtotime($ev['start_at']) > time() && $ev['status'] === 'published'): ?>
        <form method="post" onsubmit="return confirm('Cancel your registration?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reg"><button class="sec small">Cancel registration</button></form>
        <?php endif; ?>
      <?php elseif ($code === 'open'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="register"><button>Register</button></form>
      <?php else: ?>
        <span class="muted">Registration is not available: <?= e(strtolower($label)) ?>.</span>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($manage && $ev['status'] !== 'cancelled'): ?>
      <a class="btn sec" href="event_form.php?id=<?= $id ?>">Edit</a>
      <?php if ($ev['status'] === 'draft'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><button>Publish</button></form>
        <form method="post" onsubmit="return confirm('Delete this draft?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="danger small">Delete draft</button></form>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('Cancel this event for everyone?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_event"><button class="danger small">Cancel event</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($roster && $ev['status'] !== 'draft'): ?>
<div class="card">
  <h2>Registrants (<?= count($rows) ?>)</h2>
  <?php if (!$rows): ?><p class="muted">No one has registered yet.</p><?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="mark">
    <div class="scroll"><table>
      <tr><th>Name</th><th>Student no.</th><th>Course</th><th>Registered</th><th>Attendance</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['full_name']) ?></td><td><?= e($r['student_no']) ?></td><td><?= e($r['course']) ?></td>
        <td><?= e(date('M j, g:i A', strtotime($r['created_at']))) ?></td>
        <td>
          <?php if ($manage && $ev['status'] === 'published'): ?>
            <?php foreach (['present' => 'Present', 'absent' => 'Absent', '' => '—'] as $v => $l): ?>
              <label style="display:inline;font-weight:400;margin-right:8px"><input type="radio" name="att[<?= (int) $r['rid'] ?>]" value="<?= e($v) ?>" <?= ($r['att'] ?? '') === $v ? 'checked' : '' ?>> <?= e($l) ?></label>
            <?php endforeach; ?>
          <?php else: echo $r['att'] ? badge($r['att'], ucfirst($r['att'])) : '<span class="muted">Not marked</span>'; endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <div class="actions">
      <?php if ($manage && $ev['status'] === 'published'): ?><button>Save attendance</button><?php endif; ?>
      <a class="btn sec" href="export.php?event=<?= $id ?>">Download CSV</a>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_footer();
