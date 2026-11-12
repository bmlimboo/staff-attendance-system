<?php
// Copy this file to app/config.php and fill in the values.
// app/config.php is git-ignored so passwords never reach GitHub.
return [
    'app_name' => 'CNR Staff Attendance',

    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'staff_attendance',
        'user'    => 'attendance_user',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // Only list a proxy/load balancer here if the web server sits BEHIND one.
    // Then the real staff IP is read from X-Forwarded-For. Leave empty otherwise.
    // Example: ['10.10.10.1/32']
    'trusted_proxies' => [],

    // null = auto (secure cookies when the site is served over HTTPS).
    // Set true if HTTPS is terminated by a proxy in front of this server.
    'cookie_secure' => null,

    'debug' => false,
];
