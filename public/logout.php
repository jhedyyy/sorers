<?php
require __DIR__ . '/../app/bootstrap.php';

if (is_post()) {
    csrf_verify();
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');
