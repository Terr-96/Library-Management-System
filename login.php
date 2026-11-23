<?php
session_start();
include('config.php');

// --- Login attempt limiting ---
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!isset($_SESSION['last_login_attempt'])) {
    $_SESSION['last_login_attempt'] = 0;
}

$max_attempts = 5;
$lockout_time = 300; // 5 minutes lockout
$locked_out = false;
$remaining = 0;
$login_error = "";

if ($_SESSION['login_attempts'] >= $max_attempts) {
    $time_since_last_attempt = time() - $_SESSION['last_login_attempt'];
    if ($time_since_last_attempt < $lockout_time) {
        $locked_out = true;
        $remaining = $lockout_time - $time_since_last_attempt;
    } else {
        $_SESSION['login_attempts'] = 0;
    }
}

// --- CSRF token helpers ---
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --- Handle login BEFORE HTML ---
if (isset($_POST['login']) && !$locked_out) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $login_error = "Invalid CSRF token. Please try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $connection->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {

            // --- Block suspended / expired members from logging in ---
            $account_blocked = false;
            if ($user['role'] !== 'admin' && $user['role'] !== 'librarian') {
                $stmt = $connection->prepare("SELECT member_status FROM members WHERE user_id = ?");
                $stmt->bind_param("i", $user['user_id']);
                $stmt->execute();
                $member_row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $blocked_statuses = ['suspended', 'expired', 'inactive', 'blocked'];
                if (!$member_row) {
                    $account_blocked = true;
                    $login_error = "Member record not found. Please contact the librarian.";
                } elseif (in_array(strtolower($member_row['member_status']), $blocked_statuses, true)) {
                    $account_blocked = true;
                    $login_error = "Your membership is " . strtolower($member_row['member_status']) . ". Please contact the librarian.";
                }
            }

            if (!$account_blocked) {
                session_regenerate_id(true); // secure session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['username'] = $user['username'];

                $_SESSION['login_attempts'] = 0; // reset attempts
                unset($_SESSION['csrf_token']);  // new token for next session state

                if ($user['role'] === 'admin') {
                    header('Location: admin_dashboard.php');
                } elseif ($user['role'] === 'librarian') {
                    header('Location: librarian_dashboard.php');
                } else {
                    header('Location: member_dashboard.php');
                }
                exit;
            }
        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_login_attempt'] = time();
            $login_error = "Invalid username or password!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Login - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style type="text/css">
        body {
            background-image: url("https://images.unsplash.com/photo-1481627834876-b7833e8f5570?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80");
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .login-form {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header h2 {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .login-header p {
            color: #6c757d;
            margin: 0;
        }
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 14px;
            margin-bottom: 15px;
            border: 1px solid #ced4da;
        }
        .form-control:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }
        .btn-login {
            background: #007bff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            width: 100%;
            color: white;
            font-size: 16px;
        }
        .btn-login:hover {
            background: #0056b3;
            color: white;
        }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }
        .links {
            text-align: center;
            margin-top: 20px;
        }
        .links a {
            color: #007bff;
            text-decoration: none;
        }
        .links a:hover {
            text-decoration: underline;
        }
        .back-home-btn {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="back-home-btn">
            <a href="index.php" class="btn btn-outline-light btn-sm">← Back to Home</a>
        </div>
        <div class="login-form">
            <div class="login-header">
                <h2>Library System</h2>
                <p>Please sign in to continue</p>
            </div>

            <?php if ($locked_out): ?>
                <div class="alert alert-danger">
                    Too many failed login attempts. Please try again in <?php echo ceil($remaining); ?> seconds.
                </div>
            <?php else: ?>
                <?php if (!empty($login_error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <form action="" method="post" autocomplete="on">
                    <input type="text" name="username" class="form-control" placeholder="Username" autocomplete="username" required autofocus
                           value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="password" name="password" class="form-control" placeholder="Password" autocomplete="current-password" required>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="login" class="btn btn-login">Login</button>
                </form>
            <?php endif; ?>

            <div class="links">
                <p>New user? <a href="signup.php">Register here</a></p>
            </div>
        </div>
    </div>
</body>
</html>