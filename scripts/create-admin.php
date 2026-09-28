<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

require_once dirname(__DIR__) . '/app/shared/auth.php';

echo "BCP Admin Account Setup\n";
echo "=======================\n\n";

echo "Username: ";
$username = trim((string) fgets(STDIN));

echo "Password (minimum 12 characters): ";
$password = rtrim((string) fgets(STDIN), "\r\n");

if (
    !preg_match('/^[a-zA-Z0-9_.-]{4,80}$/', $username)
    || strlen($password) < 12
) {
    exit("Invalid username or password.\n");
}

try {

    $db = authDb();

    $stmt = $db->prepare(
        'INSERT INTO auth_users
            (username, password_hash, role, is_active)
         VALUES
            (:username, :password_hash, :role, 1)'
    );

    $stmt->execute([
        'username' => $username,
        'password_hash' => password_hash(
            $password,
            PASSWORD_DEFAULT
        ),
        'role' => 'ADMIN'
    ]);

    echo "\nAdmin account created successfully.\n";

} catch (PDOException $e) {

    if ($e->getCode() === '23000') {
        exit("\nUsername already exists.\n");
    }

    error_log('BCP admin setup: ' . $e->getMessage());

    exit("\nUnable to create Admin account.\n");
}

$password = '';
