<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function csrf_ok($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// ---------- Load current data ----------
$stmt = $connection->prepare("
    SELECT u.username, u.first_name, u.last_name, u.email, u.phone_number, u.password_hash,
           m.member_number, m.membership_type, m.join_date
    FROM users u
    LEFT JOIN members m ON m.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$account) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// ---------- Flash message (shown once after a successful save) ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$profile_errors  = [];
$password_errors = [];

// Values shown in the profile form (replaced by posted values if validation fails)
$form = [
    'first_name' => $account['first_name'],
    'last_name'  => $account['last_name'],
    'email'      => $account['email'],
    'phone'      => $account['phone_number'] ?? '',
];

// ---------- Handle form submissions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_ok($_POST['csrf_token'] ?? '')) {
        $profile_errors[] = "Your session expired. Please try again.";
    } elseif ($action === 'profile') {

        $form['first_name'] = trim($_POST['first_name'] ?? '');
        $form['last_name']  = trim($_POST['last_name'] ?? '');
        $form['email']      = trim($_POST['email'] ?? '');
        $form['phone']      = trim($_POST['phone'] ?? '');

        if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 50) {
            $profile_errors[] = "First name is required (max 50 characters).";
        }
        if ($form['last_name'] === '' || mb_strlen($form['last_name']) > 50) {
            $profile_errors[] = "Last name is required (max 50 characters).";
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 100) {
            $profile_errors[] = "Please enter a valid email address.";
        }
        if ($form['phone'] !== '' && !preg_match('/^[0-9+\-\s]{8,15}$/', $form['phone'])) {
            $profile_errors[] = "Phone number may only contain digits, spaces, + and - (8 to 15 characters).";
        }

        // Email must be unique (excluding this user)
        if (empty($profile_errors)) {
            $stmt = $connection->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ?");
            $stmt->bind_param("si", $form['email'], $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $profile_errors[] = "That email address is already used by another account.";
            }
            $stmt->close();
        }

        if (empty($profile_errors)) {
            $phone_value = $form['phone'] === '' ? null : $form['phone'];
            try {
                $stmt = $connection->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone_number = ? WHERE user_id = ?");
                $stmt->bind_param("ssssi", $form['first_name'], $form['last_name'], $form['email'], $phone_value, $user_id);
                $stmt->execute();
                $stmt->close();

                $_SESSION['name']  = $form['first_name'] . ' ' . $form['last_name'];
                $_SESSION['email'] = $form['email'];
                $_SESSION['flash'] = ['type' => 'success', 'text' => 'Your profile has been updated.'];
                header("Location: update_profile.php");
                exit();
            } catch (mysqli_sql_exception $ex) {
                // 1062 = duplicate entry (race condition on the unique email)
                if ((int)$ex->getCode() === 1062) {
                    $profile_errors[] = "That email address is already used by another account.";
                } else {
                    error_log("Profile update failed: " . $ex->getMessage());
                    $profile_errors[] = "Something went wrong. Please try again later.";
                }
            }
        }

    } elseif ($action === 'password') {

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $account['password_hash'])) {
            $password_errors[] = "Your current password is incorrect.";
        }
        if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $password_errors[] = "New password must be at least 8 characters and contain letters and numbers.";
        }
        if ($new !== $confirm) {
            $password_errors[] = "New password and confirmation do not match.";
        }
        if (empty($password_errors) && $current === $new) {
            $password_errors[] = "New password must be different from the current one.";
        }

        if (empty($password_errors)) {
            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $connection->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->bind_param("si", $new_hash, $user_id);
            $stmt->execute();
            $stmt->close();

            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Your password has been changed.'];
            header("Location: update_profile.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Update Profile - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        /* Background & Fonts */
        body {
            background-image: url("https://images.unsplash.com/photo-1481627834876-b7833e8f5570?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80");
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }

        /* Container */
        .container {
            background: rgba(255, 255, 255, 0.98);
            margin: 30px auto;
            max-width: 1200px;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
            min-height: 90vh;
            padding-bottom: 40px;
            overflow: hidden;
        }

        /* Navbar */
        .navbar {
            background: linear-gradient(90deg, #568de4ff, #4364f7, #6fb1fc);
            padding: 15px 40px;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.8rem;
            color: #fff !important;
            letter-spacing: 1.5px;
        }

        .nav-link {
            color: #e0e7ff !important;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .nav-link:hover,
        .nav-item.active > .nav-link {
            color: #ffd700 !important;
        }

        /* Content Padding */
        .content {
            padding: 40px 50px;
        }

        .page-title {
            color: #0052d4;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .page-subtitle {
            color: #666;
            margin-bottom: 30px;
        }

        /* Form cards */
        .form-card {
            background: #f8f9fa;
            padding: 30px 35px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.2);
            margin-bottom: 30px;
            transition: box-shadow 0.3s ease;
        }

        .form-card:hover {
            box-shadow: 0 10px 25px rgba(67, 100, 247, 0.4);
        }

        .form-card h4 {
            font-weight: 700;
            color: #0052d4;
            margin-bottom: 20px;
        }

        .form-card label {
            font-weight: 600;
            color: #4364f7;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .form-card .form-control {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 10px 14px;
            height: auto;
        }

        .form-card .form-control:focus {
            border-color: #4364f7;
            box-shadow: 0 0 0 0.2rem rgba(67, 100, 247, 0.25);
        }

        .form-card .form-control[readonly] {
            background: #e9ecef;
            color: #666;
        }

        .form-card small {
            color: #777;
        }

        .btn-member {
            background: #4364f7;
            border: none;
            color: white;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 5px 15px rgba(67, 100, 247, 0.4);
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
            display: inline-block;
        }

        .btn-member:hover {
            background: #2a47c7;
            box-shadow: 0 8px 25px rgba(42, 71, 199, 0.6);
            color: white;
            text-decoration: none;
        }

        .alert {
            border-radius: 12px;
        }

        .alert ul {
            margin-bottom: 0;
            padding-left: 20px;
        }

        /* Responsive tweaks */
        @media (max-width: 768px) {
            .content {
                padding: 25px 20px;
            }
            .navbar {
                padding: 15px 20px;
            }
            .form-card {
                padding: 25px 22px;
            }
        }
    </style>
</head>
<body>
    <div class="container shadow-lg">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="member_dashboard.php">📚 My Library</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="member_dashboard.php">My Dashboard</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="booksDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Books
                        </a>
                        <div class="dropdown-menu" aria-labelledby="booksDropdown">
                            <a class="dropdown-item" href="book_catalog.php">Browse Catalog</a>
                            <a class="dropdown-item" href="book_catalog.php#q">Search Books</a>
                            <a class="dropdown-item" href="book_catalog.php?sort=newest">Newest Books</a>
                            <a class="dropdown-item" href="book_catalog.php?sort=popular">Popular Books</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="myBooksDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            My Books
                        </a>
                        <div class="dropdown-menu" aria-labelledby="myBooksDropdown">
                            <a class="dropdown-item" href="member_dashboard.php#current-loans">Current Loans</a>
                            <a class="dropdown-item" href="borrowing_history.php">Borrowing History</a>
                            <a class="dropdown-item" href="renew_books.php">Renew Books</a>
                            <a class="dropdown-item" href="reserve_books.php#my-reservations">My Reservations</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="accountDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            My Account
                        </a>
                        <div class="dropdown-menu" aria-labelledby="accountDropdown">
                            <a class="dropdown-item" href="member_dashboard.php#account-overview">Account Overview</a>
                            <a class="dropdown-item" href="pay_fines.php#payment-history">Payment History</a>
                            <a class="dropdown-item" href="pay_fines.php">Fines &amp; Fees</a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="help_support.php">Help</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown active">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Welcome, <?php echo e($_SESSION['name'] ?? ''); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                            <a class="dropdown-item" href="update_profile.php">My Profile</a>
                            <a class="dropdown-item" href="update_profile.php#password">Change Password</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="logout.php">Logout</a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="content">
            <h3 class="page-title">Update Profile</h3>
            <p class="page-subtitle">Keep your contact details up to date and change your password.</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <div class="row">
                <!-- Profile details -->
                <div class="col-lg-7">
                    <form class="form-card" method="post" action="update_profile.php" novalidate>
                        <h4>Personal Information</h4>

                        <?php if (!empty($profile_errors)): ?>
                            <div class="alert alert-danger">
                                <ul>
                                    <?php foreach ($profile_errors as $err): ?>
                                        <li><?php echo e($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="username">Username</label>
                                <input type="text" class="form-control" id="username" value="<?php echo e($account['username']); ?>" readonly>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="member_number">Member Number</label>
                                <input type="text" class="form-control" id="member_number" value="<?php echo e($account['member_number'] ?? '-'); ?>" readonly>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="first_name">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" maxlength="50" required
                                       value="<?php echo e($form['first_name']); ?>">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="last_name">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" maxlength="50" required
                                       value="<?php echo e($form['last_name']); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="100" required
                                   value="<?php echo e($form['email']); ?>">
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" maxlength="15"
                                   placeholder="e.g. 0123456789" value="<?php echo e($form['phone']); ?>">
                            <small>Optional. Digits, spaces, + and - only.</small>
                        </div>

                        <input type="hidden" name="action" value="profile">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        <button type="submit" class="btn btn-member">Save Changes</button>
                    </form>
                </div>

                <!-- Change password -->
                <div class="col-lg-5">
                    <form class="form-card" id="password" method="post" action="update_profile.php#password" novalidate>
                        <h4>Change Password</h4>

                        <?php if (!empty($password_errors)): ?>
                            <div class="alert alert-danger">
                                <ul>
                                    <?php foreach ($password_errors as $err): ?>
                                        <li><?php echo e($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password"
                                   autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password"
                                   autocomplete="new-password" required>
                            <small>At least 8 characters, with letters and numbers.</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                   autocomplete="new-password" required>
                        </div>

                        <input type="hidden" name="action" value="password">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        <button type="submit" class="btn btn-member">Change Password</button>
                    </form>
                </div>
            </div>

            <a href="member_dashboard.php" class="btn btn-member">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
