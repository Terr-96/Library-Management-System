<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Register - Library Management System</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="bootstrap-4.4.1/css/bootstrap.min.css">
    <script type="text/javascript" src="bootstrap-4.4.1/js/jquery_latest.js"></script>
    <script type="text/javascript" src="bootstrap-4.4.1/js/bootstrap.min.js"></script>
    <style type="text/css">
        body {
            background-image: url("https://images.unsplash.com/photo-1481627834876-b7833e8f5570?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80");
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            background: rgba(255, 255, 255, 0.9);
            margin: 30px auto;
            max-width: 800px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            min-height: 90vh;
        }
        
        .navbar {
            background: rgba(68, 122, 176, 0.9);
            padding: 15px 30px;
            border-radius: 15px 15px 0 0;
        }
        
        .content {
            padding: 40px;
        }
        
        .form-section {
            background: rgba(248, 249, 250, 0.7);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .btn-primary {
            background: #007bff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="index.php">Library Management System</a>
            <div class="navbar-nav ml-auto">
                <a class="nav-link" href="index.php">Login</a>
                <a class="nav-link active" href="signup.php">Register</a>
            </div>
        </nav>

        <div class="content">
            <h2 class="text-center mb-4">Member Registration</h2>
            
            <form action="" method="post">
                <div class="form-section">
                    <h5 style="color: #007bff;">Personal Information</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <input type="text" name="first_name" class="form-control" placeholder="First Name *" required>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="last_name" class="form-control" placeholder="Last Name *" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <input type="email" name="email" class="form-control" placeholder="Email Address *" required>
                        </div>
                        <div class="col-md-6">
                            <input type="tel" name="phone_number" class="form-control" placeholder="Phone Number">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h5 style="color: #007bff;">Account Security</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <input type="password" name="password" class="form-control" placeholder="Password *" required>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>
                        <div class="col-md-6">
                            <input type="password" name="confirm_password" class="form-control" placeholder="Confirm Password *" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h5 style="color: #007bff;">Membership Type</h5>
                    <select name="membership_type" class="form-control" required>
                        <option value="">Select Membership Type</option>
                        <option value="student">Student</option>
                        <option value="standard">Standard</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>

                <div class="text-center">
                    <button type="submit" name="register" class="btn btn-primary mr-3">Register</button>
                    <a href="login.php" class="btn btn-secondary">Back to Login</a>
                </div>
            </form>

            <?php
            if (isset($_POST['register'])) {
                include('config.php');

                // Trim inputs
                $first_name = trim($_POST['first_name']);
                $last_name = trim($_POST['last_name']);
                $email = trim($_POST['email']);
                $phone_number = trim($_POST['phone_number']);
                $password = $_POST['password'];
                $confirm_password = $_POST['confirm_password'];
                $membership_type = $_POST['membership_type'];

                if ($password !== $confirm_password) {
                    echo '<div class="alert alert-danger">Passwords do not match!</div>';
                } elseif (strlen($password) < 8) {
                    echo '<div class="alert alert-danger">Password must be at least 8 characters!</div>';
                } else {
                    // Check if email exists
                    $email_check = $connection->prepare("SELECT user_id FROM users WHERE email = ?");
                    $email_check->bind_param("s", $email);
                    $email_check->execute();
                    $email_result = $email_check->get_result();

                    if ($email_result->num_rows > 0) {
                        echo '<div class="alert alert-danger">Email already registered!</div>';
                    } else {
                        // Create username
                        $username = strtolower($first_name . $last_name . rand(100, 999));
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        // Insert user
                        $stmt = $connection->prepare("INSERT INTO users (username, password_hash, email, role, first_name, last_name, phone_number, is_active) VALUES (?, ?, ?, 'member', ?, ?, ?, 1)");
                        $stmt->bind_param("ssssss", $username, $password_hash, $email, $first_name, $last_name, $phone_number);

                        if ($stmt->execute()) {
                            $user_id = $connection->insert_id;
                            $member_number = 'MEM' . date('Y') . str_pad($user_id, 4, '0', STR_PAD_LEFT);

                            // Insert member
                            $member_stmt = $connection->prepare("INSERT INTO members (user_id, member_number, membership_type, join_date, max_books_allowed) VALUES (?, ?, ?, CURDATE(), 3)");
                            $member_stmt->bind_param("iss", $user_id, $member_number, $membership_type);

                            if ($member_stmt->execute()) {
                                echo '<div class="alert alert-success">';
                                echo '<strong>Registration Successful!</strong><br>';
                                echo 'Username: <strong>' . htmlspecialchars($username) . '</strong><br>';
                                echo 'Member Number: <strong>' . htmlspecialchars($member_number) . '</strong><br>';
                                echo '<a href="login.php" class="btn btn-primary btn-sm mt-2">Login Now</a>';
                                echo '</div>';
                            } else {
                                echo '<div class="alert alert-danger">Member registration failed!</div>';
                            }
                            $member_stmt->close();
                        } else {
                            echo '<div class="alert alert-danger">User  registration failed!</div>';
                        }
                        $stmt->close();
                    }
                    $email_check->close();
                }
                mysqli_close($connection);
            }
            ?>
        </div>
    </div>
</body>
</html>