<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$role = $u['role'];
$nowS = now();

page_header('Dashboard');
echo '<p class="muted">Signed in as ' . e(ROLES[$role]) . '.</p>';

/* Officers and admins: what they manage. */
if (in_array($role, ['officer', 'admin'], true)) {
    $ids = managed_org_ids($u);
    $in = $ids ? implode(',', array_map('intval', $ids)) : '0';
    $orgs = all("SELECT o.*, (SELECT COUNT(*) FROM memberships m WHERE m.org_id=o.id AND m.status='active') AS members,
                        (SELECT COUNT(*) FROM memberships m WHERE m.org_id=o.id AND m.status='pending') AS pending
                   FROM organizations o WHERE o.id IN ($in) ORDER BY o.name");
    echo '<div class="card"><h2>' . ($role === 'admin' ? 'All organizations' : 'Organizations you manage') . '</h2>';
    if (!$orgs) echo '<p class="muted">None yet.</p>';
    foreach ($orgs as $o) {
        echo '<p><a href="org.php?id=' . (int) $o['id'] . '"><strong>' . e($o['name']) . '</strong></a> · '
           . (int) $o['members'] . ' members'
           . ($o['pending'] ? ' · ' . badge('pending', $o['pending'] . ' join request(s)') : '')
           . ' <a class="btn small sec" href="event_form.php?org=' . (int) $o['id'] . '">New event</a></p>';
    }
    echo '</div>';
    echo '<div class="card"><h2>Upcoming events you manage</h2>';
    event_list(events_with_counts("e.org_id IN ($in) AND e.end_at >= ? AND e.status <> 'cancelled'", [$nowS]), 'No upcoming events.');
    echo '</div>';
}

/* Advisers: their organizations. */
if ($role === 'adviser') {
    $orgs = all("SELECT o.*, (SELECT COUNT(*) FROM memberships m WHERE m.org_id=o.id AND m.status='active') AS members
                   FROM organizations o WHERE o.adviser_id = ? ORDER BY o.name", [$u['id']]);
    echo '<div class="card"><h2>Organizations you advise</h2>';
    if (!$orgs) echo '<p class="muted">No organization is assigned to you yet. Ask the administrator.</p>';
    foreach ($orgs as $o) {
        echo '<p><a href="org.php?id=' . (int) $o['id'] . '"><strong>' . e($o['name']) . '</strong></a> · ' . (int) $o['members'] . ' members</p>';
    }
    echo '</div>';
    echo '<div class="card"><h2>Upcoming events in your organizations</h2>';
    event_list(events_with_counts("o.adviser_id = ? AND e.end_at >= ? AND e.status <> 'draft'", [$u['id'], $nowS]), 'No upcoming events.');
    echo '</div>';
}

/* Staff and admin: campus-wide numbers. */
if (in_array($role, ['staff', 'admin'], true)) {
    $stats = [
        'Organizations'   => val('SELECT COUNT(*) FROM organizations'),
        'Active members'  => val("SELECT COUNT(*) FROM memberships WHERE status='active'"),
        'Published events'=> val("SELECT COUNT(*) FROM events WHERE status='published'"),
        'Registrations'   => val("SELECT COUNT(*) FROM registrations WHERE status='registered'"),
    ];
    echo '<div class="stats">';
    foreach ($stats as $label => $n) echo '<div class="stat"><b>' . (int) $n . '</b><span>' . e($label) . '</span></div>';
    echo '</div>';
    echo '<div class="card"><h2>Upcoming events across all organizations</h2>';
    event_list(events_with_counts("e.end_at >= ? AND e.status = 'published'", [$nowS]), 'No upcoming events.');
    echo '<p><a href="reports.php">Open reports</a></p></div>';
}

/* Students and officers: personal view. */
if (in_array($role, ['student', 'officer'], true)) {
    $mine = events_with_counts("e.id IN (SELECT event_id FROM registrations WHERE user_id = ? AND status='registered') AND e.end_at >= ? AND e.status <> 'cancelled'", [$u['id'], $nowS]);
    echo '<div class="card"><h2>Your upcoming events</h2>';
    event_list($mine, 'You have not registered for any upcoming event.');
    echo '</div>';
    $open = events_with_counts("e.status='published' AND e.reg_deadline >= ? AND e.id NOT IN (SELECT event_id FROM registrations WHERE user_id = ? AND status='registered')", [$nowS, $u['id']]);
    echo '<div class="card"><h2>Open for registration</h2>';
    event_list(array_slice($open, 0, 5), 'No events are open right now.');
    echo '<p><a href="events.php">See all events</a></p></div>';
}

page_footer();
