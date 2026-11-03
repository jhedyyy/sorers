<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_role('admin');

$errors = [];
if (is_post()) {
    csrf_verify();
    $action = post('action');
    $uid = (int) post('user_id');
    $target = $uid ? one('SELECT * FROM users WHERE id = ?', [$uid]) : null;

    if ($action === 'create') {
        $role = post('role');
        $email = strtolower(post('email'));
        $pw = (string) ($_POST['password'] ?? '');
        $no = post('student_no') ?: null;
        if (!isset(ROLES[$role])) $errors[] = 'Choose a role.';
        if (post('full_name') === '') $errors[] = 'Enter a name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if (strlen($pw) < cfg('min_password_length')) $errors[] = 'Password is too short.';
        if (!$errors && one('SELECT 1 FROM users WHERE email = ?', [$email])) $errors[] = 'That email is already used.';
        if (!$errors && $no && one('SELECT 1 FROM users WHERE student_no = ?', [$no])) $errors[] = 'That student number is already used.';
        if (!$errors) {
            q('INSERT INTO users (student_no, full_name, email, password_hash, role, course, created_at) VALUES (?,?,?,?,?,?,?)',
                [$no, post('full_name'), $email, password_hash($pw, PASSWORD_DEFAULT), $role, post('course') ?: null, now()]);
            flash('ok', 'Account created.');
            redirect('users.php');
        }
    } elseif ($target) {
        $isSelf = (int) $target['id'] === (int) $me['id'];
        if ($action === 'update') {
            $role = post('role');
            $active = isset($_POST['is_active']) ? 1 : 0;
            if (!isset(ROLES[$role])) { flash('error', 'Invalid role.'); }
            elseif ($isSelf && ($role !== 'admin' || !$active)) { flash('error', 'You cannot demote or deactivate your own account.'); }
            else {
                q('UPDATE users SET role = ?, is_active = ? WHERE id = ?', [$role, $active, $uid]);
                sync_officer_role($uid);
                flash('ok', 'Account updated.');
            }
        } elseif ($action === 'reset') {
            $pw = (string) ($_POST['new_password'] ?? '');
            if (strlen($pw) < cfg('min_password_length')) flash('error', 'New password is too short.');
            else { q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]); flash('ok', 'Password reset.'); }
        }
        redirect('users.php' . (isset($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
    }
}

$qtext = trim((string) ($_GET['q'] ?? ''));
$rows = $qtext === ''
    ? all('SELECT * FROM users ORDER BY full_name')
    : all('SELECT * FROM users WHERE full_name LIKE ? OR email LIKE ? OR student_no LIKE ? ORDER BY full_name', ["%$qtext%", "%$qtext%", "%$qtext%"]);

page_header('Users');
?>
<form method="get" class="card row">
  <div><label for="q">Search name, email or student no.</label><input id="q" name="q" value="<?= e($qtext) ?>"></div>
  <div style="align-self:end"><button>Search</button></div>
</form>

<div class="card"><h2><?= count($rows) ?> account(s)</h2>
  <div class="scroll"><table>
    <tr><th>Name</th><th>Email / student no.</th><th>Role and status</th><th>Reset password</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['full_name']) ?></td>
      <td><?= e($r['email']) ?><br><span class="muted"><?= e($r['student_no']) ?></span></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
        <select name="role" aria-label="Role"><?php foreach (ROLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $r['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <label style="margin:0;font-weight:400"><input type="checkbox" name="is_active" <?= $r['is_active'] ? 'checked' : '' ?>> Active</label>
        <button class="small sec">Save</button></form></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
        <input type="password" name="new_password" placeholder="New password" aria-label="New password" autocomplete="new-password"><button class="small sec">Reset</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted">Students become officers automatically when an officer or admin marks them as an officer of an organization.</p>
</div>

<div class="card"><h2>Create an account</h2>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="row">
      <div><label for="n">Full name</label><input id="n" name="full_name" required></div>
      <div><label for="em">Email</label><input id="em" name="email" type="email" required></div>
      <div><label for="r">Role</label><select id="r" name="role"><?php foreach (ROLES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label for="sn">Student no. (students)</label><input id="sn" name="student_no"></div>
      <div><label for="co">Course (students)</label><input id="co" name="course"></div>
      <div><label for="pw">Temporary password</label><input id="pw" name="password" type="password" required autocomplete="new-password"></div>
    </div>
    <div class="actions"><button>Create account</button></div>
  </form>
</div>
<?php page_footer();
