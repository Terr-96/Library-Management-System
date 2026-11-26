<?php
session_start();

// Not signed in (or already logged out, e.g. the page was refreshed)? Go straight to the login page.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Keep the name only to say goodbye, then wipe everything
$user_name = (string)($_SESSION['name'] ?? '');

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// Stop the browser from showing old pages from its cache when someone presses Back
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Logout - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta http-equiv="refresh" content="3;url=login.php">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style type="text/css">
        body {
            background-image: url("https://images.unsplash.com/photo-1481627834876-b7833e8f5570?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80");
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
        }

        .logout-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 400px;
            width: 100%;
        }

        .logout-icon {
            font-size: 64px;
            color: #28a745;
            margin-bottom: 20px;
        }

        .logout-title {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .logout-message {
            color: #6c757d;
            margin-bottom: 30px;
        }

        .btn-primary {
            background: #007bff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary:hover {
            background: #0056b3;
            text-decoration: none;
            color: white;
        }

        .redirect-bar {
            width: 100%;
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 20px;
            overflow: hidden;
        }

        .redirect-fill {
            height: 100%;
            background: #007bff;
            width: 0%;
            animation: progress 3s linear forwards;
        }

        @keyframes progress {
            to {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="logout-container" role="main" aria-live="polite" aria-atomic="true">
        <div class="logout-icon" aria-hidden="true">✓</div>
        <h2 class="logout-title">Logged Out Successfully</h2>
        <p class="logout-message">
            <?php if ($user_name !== ''): ?>Goodbye, <?php echo e($user_name); ?>!<br><?php endif; ?>
            Thank you for using our Library Management System.<br>
            You will be redirected to the login page automatically.
        </p>

        <a href="login.php" class="btn btn-primary">Login Again</a>

        <div class="redirect-bar" aria-hidden="true">
            <div class="redirect-fill"></div>
        </div>

        <p style="font-size: 12px; color: #adb5bd; margin-top: 15px;">
            Redirecting in 3 seconds...
        </p>
    </main>
</body>
</html>
