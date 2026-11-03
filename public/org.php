<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$id = (int) ($_GET['id'] ?? 0);
$org = one('SELECT o.*, a.full_name AS adviser FROM organizations o LEFT JOIN users a ON a.id = o.adviser_id WHERE o.id = ?', [$id]);
if (!$org) forbid('Organization not found.');

$manage = can_manage_org($id, $u);
$roster = can_view_roster($id, $u);
$mine   = one('SELECT * FROM memberships WHERE org_id = ? AND user_id = ?', [$id, $u['id']]);
$self   = 'org.php?id=' . $id;

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $mid = (int) post('membership_id');
    $target = $mid ? one('SELECT * FROM memberships WHERE id = ? AND org_id = ?', [$mid, $id]) : null;

    switch ($action) {
        case 'join':
            if (!in_array($u['role'], ['student', 'officer'], true)) forbid('Only students can join organizations.');
            if ($mine) { flash('info', 'You already have a membership or a pending request here.'); break; }
            q("INSERT INTO memberships (org_id,user_id,position,is_officer,status,joined_at) VALUES (?,?,?,?,?,?)",
                [$id, $u['id'], 'Member', 0, 'pending', now()]);
            flash('ok', 'Join request sent. An officer will review it.');
            break;

        case 'leave':
            if ($mine) {
                q('DELETE FROM memberships WHERE id = ?', [$mine['id']]);
                sync_officer_role((int) $u['id']);
                flash('ok', $mine['status'] === 'pending' ? 'Request withdrawn.' : 'You left the organization.');
            }
            break;

        case 'add_member':
            if (!$manage) forbid();
            $sn = post('student_no');
            $stu = one("SELECT id, role FROM users WHERE student_no = ? AND is_active = 1", [$sn]);
            if (!$stu) { flash('error', 'No student with that student number.'); break; }
            if (!in_array($stu['role'], ['student', 'officer'], true)) { flash('error', 'That account is not a student.'); break; }
            $ex = one('SELECT * FROM memberships WHERE org_id = ? AND user_id = ?', [$id, $stu['id']]);
            if ($ex && $ex['status'] === 'active') { flash('info', 'Already a member.'); break; }
            if ($ex) q("UPDATE memberships SET status='active' WHERE id = ?", [$ex['id']]);
            else q('INSERT INTO memberships (org_id,user_id,position,is_officer,status,joined_at) VALUES (?,?,?,?,?,?)',
                [$id, $stu['id'], post('position') ?: 'Member', 0, 'active', now()]);
            flash('ok', 'Member added.');
            break;

        case 'approve':
            if (!$manage || !$target) forbid();
            q("UPDATE memberships SET status='active' WHERE id = ?", [$mid]);
            flash('ok', 'Request approved.');
            break;

        case 'update_member':
            if (!$manage || !$target) forbid();
            $pos = post('position') ?: 'Member';
            $off = isset($_POST['is_officer']) ? 1 : 0;
            if ($target['status'] !== 'active') $off = 0;
            q('UPDATE memberships SET position = ?, is_officer = ? WHERE id = ?', [mb_substr($pos, 0, 60), $off, $mid]);
            sync_officer_role((int) $target['user_id']);
            flash('ok', 'Member updated.');
            break;

        case 'remove_member':
            if (!$manage || !$target) forbid();
            q('DELETE FROM memberships WHERE id = ?', [$mid]);
            sync_officer_role((int) $target['user_id']);
            flash('ok', 'Member removed.');
            break;

        default:
            forbid('Unknown action.');
    }
    redirect($self);
}

$members = $roster ? all("SELECT m.*, u.full_name, u.student_no, u.course FROM memberships m JOIN users u ON u.id = m.user_id
                          WHERE m.org_id = ? ORDER BY m.status DESC, m.is_officer DESC, u.full_name", [$id]) : [];
$memberCount = (int) val("SELECT COUNT(*) FROM memberships WHERE org_id = ? AND status='active'", [$id]);
$hideDraft = $manage ? '' : " AND e.status <> 'draft'";
$events = events_with_counts('e.org_id = ?' . $hideDraft, [$id], 'e.start_at DESC');

page_header($org['name']);
?>
<div class="card">
  <p><?= nl2br(e($org['description'])) ?></p>
  <p class="muted"><?= e($org['course_scope']) ?> · <?= $memberCount ?> active members<?= $org['adviser'] ? ' · Adviser: ' . e($org['adviser']) : '' ?></p>
  <div class="actions">
    <?php if (in_array($u['role'], ['student', 'officer'], true)): ?>
      <?php if (!$mine): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="join"><button>Request to join</button></form>
      <?php elseif ($mine['status'] === 'pending'): ?>
        <?= badge('pending', 'Join request pending') ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leave"><button class="sec small">Withdraw</button></form>
      <?php else: ?>
        <?= badge('active', 'You are a member · ' . $mine['position']) ?>
        <form method="post" onsubmit="return confirm('Leave this organization?')"><?= csrf_field() ?><input type="hidden" name="action" value="leave"><button class="sec small">Leave</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($manage): ?><a class="btn" href="event_form.php?org=<?= $id ?>">New event</a><?php endif; ?>
    <?php if ($u['role'] === 'admin'): ?><a class="btn sec" href="org_form.php?id=<?= $id ?>">Edit organization</a><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Events</h2>
  <?php event_list($events, 'No events yet.') ?>
</div>

<?php if ($roster): ?>
<div class="card">
  <h2>Members</h2>
  <div class="scroll"><table>
    <tr><th>Name</th><th>Student no.</th><th>Course</th><th>Position</th><th>Status</th><?php if ($manage): ?><th>Manage</th><?php endif; ?></tr>
    <?php foreach ($members as $m): ?>
    <tr>
      <td><?= e($m['full_name']) ?></td><td><?= e($m['student_no']) ?></td><td><?= e($m['course']) ?></td>
      <?php if ($manage): ?>
        <td colspan="2">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="update_member"><input type="hidden" name="membership_id" value="<?= (int) $m['id'] ?>">
            <input name="position" maxlength="60" value="<?= e($m['position']) ?>" aria-label="Position">
            <?php if ($m['status'] === 'active'): ?>
            <label style="margin:0;font-weight:400"><input type="checkbox" name="is_officer" <?= $m['is_officer'] ? 'checked' : '' ?>> Officer</label>
            <?php else: echo badge('pending', 'Pending'); endif; ?>
            <button class="small sec">Save</button>
          </form>
        </td>
        <td>
          <?php if ($m['status'] === 'pending'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="membership_id" value="<?= (int) $m['id'] ?>"><button class="small">Approve</button></form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Remove this person?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="membership_id" value="<?= (int) $m['id'] ?>"><button class="small danger">Remove</button></form>
        </td>
      <?php else: ?>
        <td><?= e($m['position']) ?><?= $m['is_officer'] ? ' ' . badge('officer', 'Officer') : '' ?></td>
        <td><?= badge($m['status'], ucfirst($m['status'])) ?></td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($manage): ?>
  <h3>Add a member</h3>
  <form method="post" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_member">
    <div><label for="sn">Student number</label><input id="sn" name="student_no" required></div>
    <div><label for="pos">Position</label><input id="pos" name="position" value="Member"></div>
    <div style="align-self:end"><button>Add</button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_footer();
