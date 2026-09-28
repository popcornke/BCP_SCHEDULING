<?php

declare(strict_types=1);

require_once __DIR__ . '/app/shared/auth.php';

authStart();
authNoCache();


$dashboard = 'app/dashboard/dashboard.php';


if (authLoggedIn()) {
    header('Location: ' . $dashboard, true, 303);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? null;

    if (!authCsrfValid($token)) {
        http_response_code(403);
        $error = 'Session expired. Refresh the page and try again.';
    } elseif (
        $username === '' ||
        $password === '' ||
        strlen($username) > 80 ||
        strlen($password) > 1024
    ) {
        $error = 'Invalid username or password.';
    } else {
        try {
            $db = authDb();

            $stmt = $db->prepare(
                'SELECT
                    user_id,
                    username,
                    password_hash,
                    role,
                    is_active,
                    failed_attempts,
                    (locked_until > NOW()) AS is_locked
                 FROM auth_users
                 WHERE username = :username
                 LIMIT 1'
            );

            $stmt->execute([
                'username' => $username
            ]);

            $user = $stmt->fetch();

            $valid = $user
                && (int) $user['is_active'] === 1
                && (int) $user['is_locked'] !== 1
                && password_verify(
                    $password,
                    $user['password_hash']
                );

            if (!$valid) {

                if ($user && (int) $user['is_locked'] !== 1) {
                    $update = $db->prepare(
                        'UPDATE auth_users
                         SET
                            locked_until =
                                CASE
                                    WHEN failed_attempts + 1 >= 5
                                    THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                                    ELSE locked_until
                                END,
                            failed_attempts =
                                CASE
                                    WHEN failed_attempts + 1 >= 5
                                    THEN 0
                                    ELSE failed_attempts + 1
                                END
                         WHERE user_id = :id'
                    );

                    $update->execute([
                        'id' => (int) $user['user_id']
                    ]);
                }

                // Generic response prevents account enumeration.
                $error = 'Invalid username or password, or account temporarily locked.';
            } else {

                $reset = $db->prepare(
                    'UPDATE auth_users
                     SET failed_attempts = 0,
                         locked_until = NULL
                     WHERE user_id = :id'
                );

                $reset->execute([
                    'id' => (int) $user['user_id']
                ]);

                session_regenerate_id(true);

                $_SESSION = [];

                $_SESSION['auth_user_id'] = (int) $user['user_id'];
                $_SESSION['auth_username'] = $user['username'];
                $_SESSION['auth_role'] = $user['role'];
                $_SESSION['auth_login_time'] = time();
                $_SESSION['auth_last_activity'] = time();
                $_SESSION['auth_regenerated_at'] = time();

                authCsrf();

                header('Location: ' . $dashboard, true, 303);
                exit;
            }
        } catch (Throwable $e) {
            error_log('BCP login error: ' . $e->getMessage());
            $error = 'Login service is temporarily unavailable.';
        }
    }
}

$csrf = authCsrf();

function loginEscape(string $text): string
{
    return htmlspecialchars(
        $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | BCP Scheduling System</title>
    <link rel="icon" href="app/assets/images/BCP_LOGO.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- External CSS link mapping to your assets folder -->
    <link rel="stylesheet" href="app/assets/css/login.css">
</head>

<body class="bcp-login-page">

    <main class="bcp-login">

        <section class="login-intro">

            <div class="brand">
                <div class="brand-icon">BCP</div>

                <div>
                    <strong>BESTLINK COLLEGE</strong>
                    <span>OF THE PHILIPPINES</span>
                </div>
            </div>

            <div class="intro-content">

                <span class="intro-badge">
                    ACADEMIC SCHEDULING PLATFORM
                </span>

                <h1>
                    A smarter way to<br>
                    manage academic<br>
                    <span>schedules.</span>
                </h1>

                <p>
                    Manage class schedules, faculty assignments,
                    examinations, and classroom availability
                    in one place.
                </p>

            </div>

            <div class="intro-footer">
                BCP Class Scheduling System
            </div>

        </section>

        <section class="login-form-area">

            <div class="login-card">

                <div class="login-card-header">

                    <span class="welcome-label">
                        WELCOME BACK
                    </span>

                    <h2>Sign in to your account</h2>

                    <p>
                        Enter your account credentials
                        to continue.
                    </p>

                </div>

                <?php if ($error !== ''): ?>

                    <div class="login-error" role="alert">
                        <?= loginEscape($error) ?>
                    </div>

                <?php endif; ?>

                <form method="POST" action="" autocomplete="on">

                    <input type="hidden" name="csrf_token" value="<?= loginEscape($csrf) ?>">

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Enter your username"
                            autocomplete="username"
                            maxlength="80"
                            required
                            autofocus>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required>
                    </div>

                    <button class="login-button" type="submit">
                        Sign In
                        <span aria-hidden="true">→</span>
                    </button>

                </form>

                <p class="login-support">
                    Authorized Admin and Scheduler access only.
                </p>

            </div>

            <div class="login-bottom">
                &copy; <?= date('Y') ?> Bestlink College of the Philippines
            </div>

        </section>

    </main>

</body>

</html>