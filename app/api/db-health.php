<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDatabase();

    $stmt = $pdo->query("
    SELECT
        DATABASE() AS database_name,
        CURRENT_USER() AS authenticated_user,
        @@hostname AS database_host
");

    $result = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'database' => $result['database_name'] ?? null,
        'current_user' => $result['authenticated_user'] ?? null,
        'database_host' => $result['database_host'] ?? null,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error_type' => get_class($e),
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
