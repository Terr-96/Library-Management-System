<?php
session_start();

// Store user name before destroying session (for goodbye message)
$user_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'User ';

// Destroy all session data
session_unset();
session_destroy();

// Start a new session for flash message (optional)
session_start();
$_SESSION['logout_message'] = "Goodbye, $user_name! You have been logged out successfully.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Logout - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" type="text/css" href="bootstrap-4.4.1/css/bootstrap.min.css" />
    <style type="text/css">
        /* === Your original CSS preserved === */
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
        
        .progress-bar {
            width: 100%;
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 20px;
            overflow: hidden;
        }
        
        .progress-fill {
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
            Thank you for using our Library Management System.<br>
            You will be redirected to the login page automatically.
        </p>
        
        <a href="login.php" class="btn btn-primary">Login Again</a>
        
        <div class="progress-bar" aria-hidden="true">
            <div class="progress-fill"></div>
        </div>
        
        <p style="font-size: 12px; color: #adb5bd; margin-top: 15px;">
            Redirecting in 3 seconds...
        </p>
    </main>

    <script>
        // Auto-redirect to login page after 3 seconds
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 3000);
    </script>
</body>
</html>