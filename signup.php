<?php
session_start();
include('config.php');

// Already signed in? Send people to their own dashboard instead of the registration form.
if (isset($_SESSION['role'], $_SESSION['user_id'])) {
    $destinations = [
        'admin'     => 'admin_dashboard.php',
        'librarian' => 'librarian_dashboard.php',
        'member'    => 'member_dashboard.php',
    ];
    if (isset($destinations[$_SESSION['role']])) {
        header("Location: " . $destinations[$_SESSION['role']]);
        exit();
    }
}

const MIN_PASSWORD_LENGTH = 8;
const MAX_PASSWORD_BYTES  = 72; // bcrypt ignores everything after 72 bytes
const MEMBERSHIP_TYPES    = ['student', 'standard', 'premium'];
const DEFAULT_MAX_BOOKS   = 3;

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Success message (shown once after redirect) ----------
$done = $_SESSION['signup_done'] ?? null;
unset($_SESSION['signup_done']);

$errors = [];
$form = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone_number' => '', 'membership_type' => ''];

// Build a username like "johndoe482" that is guaranteed to be unused
function make_username($connection, $first, $last) {
    $base = mb_strtolower($first . $last, 'UTF-8');
    $base = preg_replace('/[^a-z0-9]/', '', $base);
    if ($base === '') {
        $base = 'member';
    }
    $base = substr($base, 0, 40);

    $stmt = $connection->prepare("SELECT user_id FROM users WHERE username = ?");
    for ($i = 0; $i < 20; $i++) {
        $candidate = $base . random_int(100, 9999);
        $stmt->bind_param("s", $candidate);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            return $candidate;
        }
    }
    $stmt->close();
    return $base . bin2hex(random_bytes(3)); // extremely unlikely fallback
}

// ---------- Handle registration ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    foreach (['first_name', 'last_name', 'email', 'phone_number', 'membership_type'] as $f) {
        $form[$f] = trim((string)($_POST[$f] ?? ''));
    }
    $password         = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = "Your session expired. Please try again.";
    }

    $name_pattern = '/^[\p{L}\p{M}\s\'.\-]+$/u';
    if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 50 || !preg_match($name_pattern, $form['first_name'])) {
        $errors[] = "Please enter a valid first name (letters only, up to 50 characters).";
    }
    if ($form['last_name'] === '' || mb_strlen($form['last_name']) > 50 || !preg_match($name_pattern, $form['last_name'])) {
        $errors[] = "Please enter a valid last name (letters only, up to 50 characters).";
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) {
        $errors[] = "Please enter a valid email address.";
    }
    if ($form['phone_number'] !== '' && !preg_match('/^[0-9+\-\s]{8,15}$/', $form['phone_number'])) {
        $errors[] = "Phone number may only contain digits, spaces, + and - (8 to 15 characters).";
    }
    if (!in_array($form['membership_type'], MEMBERSHIP_TYPES, true)) {
        $errors[] = "Please choose a membership type.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    } elseif (strlen($password) < MIN_PASSWORD_LENGTH || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must be at least " . MIN_PASSWORD_LENGTH . " characters and contain letters and numbers.";
    } elseif (strlen($password) > MAX_PASSWORD_BYTES) {
        $errors[] = "Password is too long (maximum " . MAX_PASSWORD_BYTES . " characters).";
    }

    if (empty($errors)) {
        try {
            // Is the email already taken?
            $stmt = $connection->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->bind_param("s", $form['email']);
            $stmt->execute();
            $taken = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($taken) {
                $errors[] = "That email address is already registered.";
            } else {
                $username      = make_username($connection, $form['first_name'], $form['last_name']);
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $phone         = $form['phone_number'] === '' ? null : $form['phone_number'];

                // The user row and the member row are created together or not at all
                $connection->begin_transaction();
                try {
                    $stmt = $connection->prepare("
                        INSERT INTO users (username, password_hash, email, role, first_name, last_name, phone_number, is_active)
                        VALUES (?, ?, ?, 'member', ?, ?, ?, 1)
                    ");
                    $stmt->bind_param("ssssss", $username, $password_hash, $form['email'], $form['first_name'], $form['last_name'], $phone);
                    $stmt->execute();
                    $user_id = $connection->insert_id;
                    $stmt->close();

                    $member_number = 'MEM' . date('Y') . str_pad((string)$user_id, 4, '0', STR_PAD_LEFT);
                    $max_books     = DEFAULT_MAX_BOOKS;
                    $stmt = $connection->prepare("
                        INSERT INTO members (user_id, member_number, membership_type, join_date, max_books_allowed)
                        VALUES (?, ?, ?, CURDATE(), ?)
                    ");
                    $stmt->bind_param("issi", $user_id, $member_number, $form['membership_type'], $max_books);
                    $stmt->execute();
                    $stmt->close();

                    $connection->commit();
                } catch (Throwable $inner) {
                    $connection->rollback();
                    throw $inner;
                }

                $_SESSION['signup_done'] = ['username' => $username, 'member_number' => $member_number];
                $_SESSION['csrf_token']  = bin2hex(random_bytes(32));
                header("Location: signup.php");
                exit();
            }
        } catch (mysqli_sql_exception $ex) {
            if ((int)$ex->getCode() === 1062) {
                $errors[] = "That email address is already registered.";
            } else {
                error_log("Registration failed: " . $ex->getMessage());
                $errors[] = "Registration failed. Please try again later.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Register - Library Management System</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            height: auto;
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
        }

        .alert ul {
            margin-bottom: 0;
            padding-left: 20px;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="index.php">Library Management System</a>
            <div class="navbar-nav ml-auto">
                <a class="nav-link" href="login.php">Login</a>
                <a class="nav-link active" href="signup.php">Register</a>
            </div>
        </nav>

        <div class="content">
            <h2 class="text-center mb-4">Member Registration</h2>

            <?php if ($done): ?>
                <div class="alert alert-success text-center">
                    <strong>Registration Successful!</strong><br>
                    Username: <strong><?php echo e($done['username']); ?></strong><br>
                    Member Number: <strong><?php echo e($done['member_number']); ?></strong><br>
                    <small>Please write down your username. You need it to log in.</small><br>
                    <a href="login.php" class="btn btn-primary btn-sm mt-2">Login Now</a>
                </div>
            <?php else: ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?php echo e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="signup.php" method="post" autocomplete="off">
                    <div class="form-section">
                        <h5 style="color: #007bff;">Personal Information</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <input type="text" name="first_name" class="form-control" placeholder="First Name *"
                                       maxlength="50" required value="<?php echo e($form['first_name']); ?>">
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="last_name" class="form-control" placeholder="Last Name *"
                                       maxlength="50" required value="<?php echo e($form['last_name']); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <input type="email" name="email" class="form-control" placeholder="Email Address *"
                                       maxlength="100" required value="<?php echo e($form['email']); ?>">
                            </div>
                            <div class="col-md-6">
                                <input type="tel" name="phone_number" class="form-control" placeholder="Phone Number"
                                       maxlength="15" value="<?php echo e($form['phone_number']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h5 style="color: #007bff;">Account Security</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <input type="password" name="password" class="form-control" placeholder="Password *"
                                       minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" maxlength="<?php echo MAX_PASSWORD_BYTES; ?>"
                                       autocomplete="new-password" required>
                                <small class="text-muted">At least <?php echo MIN_PASSWORD_LENGTH; ?> characters, with letters and numbers</small>
                            </div>
                            <div class="col-md-6">
                                <input type="password" name="confirm_password" class="form-control" placeholder="Confirm Password *"
                                       maxlength="<?php echo MAX_PASSWORD_BYTES; ?>" autocomplete="new-password" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h5 style="color: #007bff;">Membership Type</h5>
                        <select name="membership_type" class="form-control" required>
                            <option value="">Select Membership Type</option>
                            <?php foreach (MEMBERSHIP_TYPES as $type): ?>
                                <option value="<?php echo e($type); ?>" <?php echo $form['membership_type'] === $type ? 'selected' : ''; ?>><?php echo e(ucfirst($type)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                    <div class="text-center">
                        <button type="submit" name="register" class="btn btn-primary mr-3">Register</button>
                        <a href="login.php" class="btn btn-secondary">Back to Login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>