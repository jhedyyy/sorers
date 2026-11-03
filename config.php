<?php
// Configuration. Defaults match a stock XAMPP install (MySQL, user root, no password).
// Override with environment variables, or edit the values below.
return [
    'app_name'  => 'Student Organization Management and Event Registration System',
    'app_short' => 'SORERS',
    'campus'    => 'ICCT Colleges Foundation Inc., Cainta Campus',
    'db' => [
        'driver'      => getenv('DB_DRIVER') ?: 'mysql',      // 'mysql' or 'sqlite'
        'host'        => getenv('DB_HOST') ?: '127.0.0.1',
        'port'        => getenv('DB_PORT') ?: '3306',
        'name'        => getenv('DB_NAME') ?: 'sorers',
        'user'        => getenv('DB_USER') ?: 'root',
        'pass'        => getenv('DB_PASS') ?: '',
        'sqlite_path' => getenv('DB_SQLITE') ?: __DIR__ . '/storage/sorers.sqlite',
    ],
    // Course list shown on the sign-up form (organizations are open to all courses).
    'courses' => ['BSIT', 'BSCS', 'BSBA', 'BSHM', 'BSTM', 'BSOA', 'Other'],
    'min_password_length' => 8,
];
