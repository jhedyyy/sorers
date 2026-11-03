<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$f = ['full_name' => '', 'student_no' => '', 'email' => '', 'course' => ''];
$errors = [];
if (is_post()) {
    csrf_verify();
    foreach ($f as $k => $_) {
        $f[$k] = post($k);
    }
    $f['email'] = strtolower($f['email']);
    $pw = (string) ($_POST['password'] ?? '');
    $min = cfg('min_password_length');

    if ($f['full_name'] === '') $errors['full_name'] = 'Enter your full name.';
    if ($f['student_no'] === '') $errors['student_no'] = 'Enter your student number.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!in_array($f['course'], cfg('courses'), true)) $errors['course'] = 'Choose your course.';
    if (strlen($pw) < $min) $errors['password'] = "Use at least $min characters.";
    if ($pw !== (string) ($_POST['password2'] ?? '')) $errors['password2'] = 'Passwords do not match.';
    if (!isset($errors['email']) && one('SELECT 1 FROM users WHERE email = ?', [$f['email']])) $errors['email'] = 'That email is already registered.';
    if (!isset($errors['student_no']) && one('SELECT 1 FROM users WHERE student_no = ?', [$f['student_no']])) $errors['student_no'] = 'That student number is already registered.';

    if (!$errors) {
        q('INSERT INTO users (student_no, full_name, email, password_hash, role, course, created_at) VALUES (?,?,?,?,?,?,?)',
            [$f['student_no'], $f['full_name'], $f['email'], password_hash($pw, PASSWORD_DEFAULT), 'student', $f['course'], now()]);
        session_regenerate_id(true);
        $_SESSION['uid'] = insert_id();
        flash('ok', 'Welcome! Your account is ready.');
        redirect('dashboard.php');
    }
}

function field_err(array $errors, string $k): void
{
    if (isset($errors[$k])) echo '<p class="err">' . e($errors[$k]) . '</p>';
}

page_header('Create a student account');
?>
<div class="card auth">
  <form method="post">
    <?= csrf_field() ?>
    <p class="muted">We only collect what the system needs: name, student number, course and email.</p>
    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" required value="<?= e($f['full_name']) ?>"><?php field_err($errors, 'full_name') ?>
    <label for="student_no">Student number</label>
    <input id="student_no" name="student_no" required value="<?= e($f['student_no']) ?>"><?php field_err($errors, 'student_no') ?>
    <label for="course">Course</label>
    <select id="course" name="course" required>
      <option value="">Choose…</option>
      <?php foreach (cfg('courses') as $c): ?>
        <option <?= $f['course'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
      <?php endforeach; ?>
    </select><?php field_err($errors, 'course') ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required value="<?= e($f['email']) ?>"><?php field_err($errors, 'email') ?>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required minlength="<?= (int) cfg('min_password_length') ?>"><?php field_err($errors, 'password') ?>
    <label for="password2">Confirm password</label>
    <input id="password2" name="password2" type="password" required><?php field_err($errors, 'password2') ?>
    <div class="actions"><button>Create account</button> <a href="login.php">I already have an account</a></div>
  </form>
</div>
<?php page_footer();
