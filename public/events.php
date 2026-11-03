<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

$orgId = (int) ($_GET['org'] ?? 0);
$qtext = trim((string) ($_GET['q'] ?? ''));
$past  = !empty($_GET['past']);

$where = ["e.status IN ('published','cancelled')"];
$params = [];
if ($orgId) { $where[] = 'e.org_id = ?'; $params[] = $orgId; }
if ($qtext !== '') { $where[] = '(e.title LIKE ? OR e.venue LIKE ?)'; $params[] = "%$qtext%"; $params[] = "%$qtext%"; }
$where[] = $past ? 'e.end_at < ?' : 'e.end_at >= ?';
$params[] = now();

$rows = events_with_counts(implode(' AND ', $where), $params, $past ? 'e.start_at DESC' : 'e.start_at ASC');
$orgs = all('SELECT id, name FROM organizations ORDER BY name');

page_header('Events');
?>
<form method="get" class="card row">
  <div><label for="q">Search</label><input id="q" name="q" value="<?= e($qtext) ?>" placeholder="Title or venue"></div>
  <div><label for="org">Organization</label>
    <select id="org" name="org"><option value="">All</option>
      <?php foreach ($orgs as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $orgId === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div><label for="past">Show</label>
    <select id="past" name="past"><option value="">Upcoming</option><option value="1" <?= $past ? 'selected' : '' ?>>Past events</option></select></div>
  <div style="align-self:end"><button>Filter</button></div>
</form>
<div class="card"><?php event_list($rows, 'No events match.') ?></div>
<?php page_footer();
