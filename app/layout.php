<?php
declare(strict_types=1);

function nav_items(?array $u): array
{
    if (!$u) {
        return [];
    }
    $items = [
        ['dashboard.php', 'Dashboard'],
        ['events.php', 'Events'],
        ['orgs.php', 'Organizations'],
    ];
    if (in_array($u['role'], ['student', 'officer'], true)) {
        $items[] = ['my.php', 'My activity'];
    }
    if (in_array($u['role'], ['adviser', 'staff', 'admin'], true)) {
        $items[] = ['reports.php', 'Reports'];
    }
    if ($u['role'] === 'admin') {
        $items[] = ['users.php', 'Users'];
    }
    return $items;
}

function page_header(string $title): void
{
    $u = current_user();
    $here = basename($_SERVER['SCRIPT_NAME']);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(cfg('app_short')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="top">
  <div class="wrap bar">
    <a class="brand" href="<?= $u ? 'dashboard.php' : 'login.php' ?>"><?= e(cfg('app_short')) ?><small><?= e(cfg('campus')) ?></small></a>
    <?php if ($u): ?>
    <nav>
      <?php foreach (nav_items($u) as [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $here === $href ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="logout.php" class="who">
      <?= csrf_field() ?>
      <span><?= e($u['full_name']) ?> <em><?= e(ROLES[$u['role']]) ?></em></span>
      <button class="link">Log out</button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
<?php endforeach; ?>
<h1><?= e($title) ?></h1>
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="wrap foot">
  <?= e(cfg('app_name')) ?> · <?= e(cfg('campus')) ?>. Personal data is handled under the Data Privacy Act (RA 10173).
</footer>
</body>
</html>
<?php
}

function badge(string $code, string $label): string
{
    return '<span class="badge b-' . e($code) . '">' . e($label) . '</span>';
}

/** Compact event list used by several pages. $rows come from events_with_counts(). */
function event_list(array $rows, string $empty = 'Nothing here yet.'): void
{
    if (!$rows) {
        echo '<p class="muted">' . e($empty) . '</p>';
        return;
    }
    echo '<ul class="evlist">';
    foreach ($rows as $ev) {
        [$code, $label] = event_state($ev);
        $cap = $ev['capacity'] !== null ? $ev['reg_count'] . ' / ' . $ev['capacity'] : $ev['reg_count'] . ' registered';
        echo '<li><div><a href="event.php?id=' . (int) $ev['id'] . '"><strong>' . e($ev['title']) . '</strong></a>'
            . '<div class="muted">' . e($ev['org_name']) . ' · ' . e(fmt_dt($ev['start_at'])) . ' · ' . e($ev['venue']) . '</div></div>'
            . '<div class="right">' . badge($code, $label) . '<div class="muted">' . e($cap) . '</div></div></li>';
    }
    echo '</ul>';
}
