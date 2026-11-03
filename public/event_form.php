<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$id = (int) ($_GET['id'] ?? 0);
$ev = $id ? one('SELECT * FROM events WHERE id = ?', [$id]) : null;
if ($id && !$ev) forbid('Event not found.');
$orgId = $ev ? (int) $ev['org_id'] : (int) ($_GET['org'] ?? 0);
$org = one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
if (!$org || !can_manage_org($orgId, $u)) forbid('Only officers of this organization can manage its events.');
if ($ev && $ev['status'] === 'cancelled') forbid('A cancelled event can no longer be edited.');

$regCount = $ev ? (int) val("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status='registered'", [$id]) : 0;
$f = $ev ?: ['title' => '', 'description' => '', 'venue' => '', 'start_at' => '', 'end_at' => '', 'reg_deadline' => '', 'capacity' => null, 'status' => 'draft'];
$error = '';

if (is_post()) {
    csrf_verify();
    $f['title'] = post('title');
    $f['description'] = post('description');
    $f['venue'] = post('venue');
    $f['start_at'] = from_input_dt(post('start_at'));
    $f['end_at'] = from_input_dt(post('end_at'));
    $f['reg_deadline'] = from_input_dt(post('reg_deadline'));
    $capRaw = post('capacity');
    $f['capacity'] = $capRaw === '' ? null : (int) $capRaw;
    $f['status'] = post('status') === 'published' ? 'published' : 'draft';

    if ($f['title'] === '' || $f['venue'] === '') {
        $error = 'Title and venue are required.';
    } elseif (!$f['start_at'] || !$f['end_at'] || !$f['reg_deadline']) {
        $error = 'Enter the start, end and registration deadline.';
    } elseif (strtotime($f['end_at']) <= strtotime($f['start_at'])) {
        $error = 'The event must end after it starts.';
    } elseif (strtotime($f['reg_deadline']) > strtotime($f['start_at'])) {
        $error = 'The registration deadline cannot be after the event starts.';
    } elseif ($f['capacity'] !== null && $f['capacity'] < 1) {
        $error = 'The limit must be at least 1, or leave it blank for no limit.';
    } elseif ($f['capacity'] !== null && $f['capacity'] < $regCount) {
        $error = "$regCount students are already registered, so the limit cannot be lower than that.";
    } else {
        if ($ev) {
            q('UPDATE events SET title=?, description=?, venue=?, start_at=?, end_at=?, reg_deadline=?, capacity=?, status=? WHERE id=?',
                [$f['title'], $f['description'], $f['venue'], $f['start_at'], $f['end_at'], $f['reg_deadline'], $f['capacity'], $f['status'], $id]);
        } else {
            q('INSERT INTO events (org_id,title,description,venue,start_at,end_at,reg_deadline,capacity,status,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$orgId, $f['title'], $f['description'], $f['venue'], $f['start_at'], $f['end_at'], $f['reg_deadline'], $f['capacity'], $f['status'], $u['id'], now()]);
            $id = insert_id();
        }
        flash('ok', $f['status'] === 'published' ? 'Event saved and published.' : 'Event saved as a draft. Publish it when ready.');
        redirect('event.php?id=' . $id);
    }
}

page_header($ev ? 'Edit event' : 'New event');
?>
<div class="card">
  <p class="muted"><?= e($org['name']) ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <label for="title">Title</label>
    <input id="title" name="title" required maxlength="200" value="<?= e($f['title']) ?>">
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($f['description']) ?></textarea>
    <label for="venue">Venue</label>
    <input id="venue" name="venue" required maxlength="150" value="<?= e($f['venue']) ?>">
    <div class="row">
      <div><label for="start_at">Starts</label><input id="start_at" name="start_at" type="datetime-local" required value="<?= e(to_input_dt($f['start_at'])) ?>"></div>
      <div><label for="end_at">Ends</label><input id="end_at" name="end_at" type="datetime-local" required value="<?= e(to_input_dt($f['end_at'])) ?>"></div>
      <div><label for="reg_deadline">Registration deadline</label><input id="reg_deadline" name="reg_deadline" type="datetime-local" required value="<?= e(to_input_dt($f['reg_deadline'])) ?>"></div>
      <div><label for="capacity">Participant limit</label><input id="capacity" name="capacity" type="number" min="1" placeholder="No limit" value="<?= e($f['capacity']) ?>"></div>
    </div>
    <label for="status">Visibility</label>
    <select id="status" name="status">
      <option value="draft" <?= $f['status'] === 'draft' ? 'selected' : '' ?>>Draft (only officers see it)</option>
      <option value="published" <?= $f['status'] === 'published' ? 'selected' : '' ?>>Published (students can register)</option>
    </select>
    <div class="actions"><button>Save event</button> <a href="<?= $ev ? 'event.php?id=' . $id : 'org.php?id=' . $orgId ?>">Cancel</a></div>
  </form>
</div>
<?php page_footer();
