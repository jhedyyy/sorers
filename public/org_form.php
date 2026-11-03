<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role('admin');

$id = (int) ($_GET['id'] ?? 0);
$org = $id ? one('SELECT * FROM organizations WHERE id = ?', [$id]) : null;
if ($id && !$org) forbid('Organization not found.');

$f = $org ?: ['name' => '', 'description' => '', 'course_scope' => 'Open to all courses', 'adviser_id' => null];
$advisers = all("SELECT id, full_name FROM users WHERE role = 'adviser' AND is_active = 1 ORDER BY full_name");
$error = '';

if (is_post()) {
    csrf_verify();
    if (post('action') === 'delete' && $org) {
        q('DELETE FROM organizations WHERE id = ?', [$id]);
        flash('ok', 'Organization deleted.');
        redirect('orgs.php');
    }
    $f['name'] = post('name');
    $f['description'] = post('description');
    $f['course_scope'] = post('course_scope');
    $f['adviser_id'] = (int) post('adviser_id') ?: null;
    if ($f['name'] === '') {
        $error = 'Enter the organization name.';
    } elseif (one('SELECT 1 FROM organizations WHERE name = ? AND id <> ?', [$f['name'], $id])) {
        $error = 'An organization with that name already exists.';
    } elseif ($f['adviser_id'] && !one("SELECT 1 FROM users WHERE id = ? AND role = 'adviser'", [$f['adviser_id']])) {
        $error = 'Choose a valid adviser.';
    } else {
        if ($org) {
            q('UPDATE organizations SET name=?, description=?, course_scope=?, adviser_id=? WHERE id=?',
                [$f['name'], $f['description'], $f['course_scope'], $f['adviser_id'], $id]);
        } else {
            q('INSERT INTO organizations (name, description, course_scope, adviser_id, created_at) VALUES (?,?,?,?,?)',
                [$f['name'], $f['description'], $f['course_scope'], $f['adviser_id'], now()]);
            $id = insert_id();
        }
        flash('ok', 'Organization saved.');
        redirect('org.php?id=' . $id);
    }
}

page_header($org ? 'Edit organization' : 'New organization');
?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?>
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <label for="name">Name</label>
    <input id="name" name="name" required value="<?= e($f['name']) ?>">
    <label for="description">Description</label>
    <textarea id="description" name="description"><?= e($f['description']) ?></textarea>
    <div class="row">
      <div><label for="course_scope">Who can join</label>
        <input id="course_scope" name="course_scope" value="<?= e($f['course_scope']) ?>"></div>
      <div><label for="adviser_id">Adviser</label>
        <select id="adviser_id" name="adviser_id">
          <option value="">— none yet —</option>
          <?php foreach ($advisers as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= (int) $f['adviser_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['full_name']) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="actions"><button>Save</button> <a href="<?= $org ? 'org.php?id=' . $id : 'orgs.php' ?>">Cancel</a></div>
  </form>
  <?php if ($org): ?>
  <form method="post" onsubmit="return confirm('Delete this organization with its members and events? This cannot be undone.')" class="actions">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="danger small">Delete organization</button>
  </form>
  <?php endif; ?>
</div>
<?php page_footer();
