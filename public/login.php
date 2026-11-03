<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$email = '';
$error = '';
if (is_post()) {
    csrf_verify();
    $email = strtolower(post('email'));
    $u = one('SELECT * FROM users WHERE email = ?', [$email]);
    // Same message for unknown email, wrong password, or deactivated account.
    if ($u && $u['is_active'] && password_verify(post('password'), $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        redirect('dashboard.php');
    }
    $error = 'Incorrect email or password, or the account is inactive.';
}

page_header('Log in');
?>
<div class="card auth">
  <form method="post">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required autofocus value="<?= e($email) ?>">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <div class="actions"><button>Log in</button> <a href="register.php">Create a student account</a></div>
  </form>
</div>
<?php page_footer();
