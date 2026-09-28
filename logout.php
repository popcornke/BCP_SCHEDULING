<?php

declare(strict_types=1);

require_once __DIR__ . '/app/shared/auth.php';

authStart();
authNoCache();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

if (!authCsrfValid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid logout request.');
}

authClear();

header('Clear-Site-Data: "cache"');
header('Location: /index.php', true, 303);

exit;
