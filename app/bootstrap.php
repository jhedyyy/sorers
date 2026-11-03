<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require __DIR__ . '/lib.php';
require __DIR__ . '/layout.php';

// Friendly message if the database has not been set up yet.
try {
    db();
} catch (Throwable $t) {
    http_response_code(500);
    echo '<h1>Database not reachable</h1><p>Check <code>config.php</code> and run <code>php setup.php</code>. '
       . 'Details: ' . htmlspecialchars($t->getMessage()) . '</p>';
    exit;
}
