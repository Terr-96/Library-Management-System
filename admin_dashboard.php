<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Session timeout: 30 minutes
$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

const FINE_PER_DAY = 1.00; // RM per day overdue (same rate as the rest of the system)

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : '-';
}

function money($amount) {
    return 'RM ' . number_format((float)$amount, 2);
}

// ---------- Optional tables ----------
$tbl = $connection->query("SHOW TABLES LIKE 'payments'");
$payments_ready = ($tbl && $tbl->num_rows > 0);
$tbl = $connection->query("SHOW TABLES LIKE 'reservations'");
$reservations_ready = ($tbl && $tbl->num_rows > 0);

// ---------- Statistics (one query) ----------
$stats = $connection->query("
    SELECT
        (SELECT COUNT(*) FROM users WHERE is_active = 1)                                         AS total_users,
        (SELECT COUNT(*) FROM books WHERE status = 'active')                                     AS total_books,
        (SELECT COUNT(*) FROM members WHERE member_status = 'active')                            AS total_members,
        (SELECT COUNT(*) FROM loan_records WHERE return_date IS NULL)                            AS active_loans,
        (SELECT COUNT(*) FROM loan_records WHERE return_date IS NULL AND due_date < CURDATE())   AS overdue_loans,
        (SELECT COALESCE(SUM(total_fine_amount), 0) FROM members)                                AS unpaid_fines
")->fetch_assoc();

$collected = 0.0;
if ($payments_ready) {
    $collected = (float)$connection->query("SELECT COALESCE(SUM(amount), 0) AS s FROM payments")->fetch_assoc()['s'];
}
$waiting = 0;
if ($reservations_ready) {
    $waiting = (int)$connection->query("SELECT COUNT(*) AS c FROM reservations WHERE status = 'waiting'")->fetch_assoc()['c'];
}

// ---------- Users by role ----------
$role_rows = $connection->query("
    SELECT role, COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active
    FROM users
    GROUP BY role
    ORDER BY FIELD(role, 'admin', 'librarian', 'member')
")->fetch_all(MYSQLI_ASSOC);

// ---------- Newest accounts ----------
$stmt = $connection->prepare("
    SELECT username, first_name, last_name, role, is_active, created_at
    FROM users
    ORDER BY created_at DESC, user_id DESC
    LIMIT 5
");
$stmt->execute();
$new_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Overdue loans ----------
$stmt = $connection->prepare("
    SELECT lr.due_date, DATEDIFF(CURDATE(), lr.due_date) AS days_late,
           b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE lr.return_date IS NULL AND lr.due_date < CURDATE()
    ORDER BY lr.due_date ASC
    LIMIT 5
");
$stmt->execute();
$overdue_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Recent activity (issues and returns as separate rows) ----------
$stmt = $connection->prepare("
    SELECT 'Book Issued' AS action, lr.issue_date AS action_date, lr.loan_id, lr.due_date, lr.return_date,
           b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    UNION ALL
    SELECT 'Book Returned' AS action, lr.return_date AS action_date, lr.loan_id, lr.due_date, lr.return_date,
           b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE lr.return_date IS NOT NULL
    ORDER BY action_date DESC, loan_id DESC
    LIMIT 10
");
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Dashboard - Library Management System</title>
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

        .container {
            background: rgba(255, 255, 255, 0.95);
            margin: 20px auto;
            max-width: 1200px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            min-height: 90vh;
        }

        .navbar {
            background: rgba(209, 113, 122, 0.9);
            padding: 15px 30px;
            border-radius: 15px 15px 0 0;
        }

        .content {
            padding: 30px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #d8525fff, #c44c58ff);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .stat-card p {
            margin: 0;
        }

        .admin-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            background: rgba(248, 249, 250, 0.9);
            padding: 25px;
            border-radius: 10px;
            border-left: 5px solid #dc3545;
        }

        .action-card h5 {
            color: #dc3545;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .btn-admin {
            background: #dc3545;
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .btn-admin:hover {
            background: #c82333;
            color: white;
            text-decoration: none;
        }

        .recent-activity {
            background: rgba(248, 249, 250, 0.9);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            scroll-margin-top: 20px;
        }

        .table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .table th {
            background: #dc3545;
            color: white;
            border: none;
        }

        .badge-role {
            background: #6c757d;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="admin_dashboard.php">📚 LMS Admin Panel</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item active"><a class="nav-link" href="admin_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="book_management.php">Manage Books</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="loansDropdown" role="button" data-toggle="dropdown">
                            Loans
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="issue_book.php">Issue Book</a>
                            <a class="dropdown-item" href="return_book.php">Return Book</a>
                            <a class="dropdown-item" href="#overdue-section">Overdue Books</a>
                        </div>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="#users-section">Users</a></li>
                    <li class="nav-item"><a class="nav-link" href="#activity-section">Activity</a></li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                            Welcome, <?php echo e($_SESSION['name'] ?? ''); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="logout.php">Logout</a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="content">
            <h2 class="mb-4">System Overview</h2>

            <!-- Statistics -->
            <div class="stats-row">
                <div class="stat-card" title="Accounts that are switched on">
                    <h3><?php echo (int)$stats['total_users']; ?></h3>
                    <p>Total Users</p>
                </div>
                <div class="stat-card" title="Book titles that can be borrowed">
                    <h3><?php echo (int)$stats['total_books']; ?></h3>
                    <p>Active Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$stats['total_members']; ?></h3>
                    <p>Active Members</p>
                </div>
                <div class="stat-card" title="Books currently borrowed">
                    <h3><?php echo (int)$stats['active_loans']; ?></h3>
                    <p>Active Loans</p>
                </div>
                <div class="stat-card" title="Borrowed books past their due date">
                    <h3><?php echo (int)$stats['overdue_loans']; ?></h3>
                    <p>Overdue Loans</p>
                </div>
                <div class="stat-card" title="Fines recorded and not yet paid">
                    <h3><?php echo money($stats['unpaid_fines']); ?></h3>
                    <p>Unpaid Fines</p>
                </div>
                <?php if ($payments_ready): ?>
                    <div class="stat-card" title="All payments received so far">
                        <h3><?php echo money($collected); ?></h3>
                        <p>Fines Collected</p>
                    </div>
                <?php endif; ?>
                <?php if ($reservations_ready): ?>
                    <div class="stat-card" title="Members waiting for a book">
                        <h3><?php echo $waiting; ?></h3>
                        <p>Waiting Reservations</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Admin Actions -->
            <h4 class="mb-3">Administrative Functions</h4>
            <div class="admin-actions">
                <div class="action-card">
                    <h5>Book Management</h5>
                    <p>Add, edit, deactivate and delete library books</p>
                    <a href="book_management.php" class="btn btn-admin">Manage Books</a>
                </div>

                <div class="action-card">
                    <h5>Issue Book</h5>
                    <p>Lend a book to a member</p>
                    <a href="issue_book.php" class="btn btn-admin">Issue Book</a>
                </div>

                <div class="action-card">
                    <h5>Return Book</h5>
                    <p>Process returns and record fines</p>
                    <a href="return_book.php" class="btn btn-admin">Return Book</a>
                </div>

                <div class="action-card">
                    <h5>Users &amp; Accounts</h5>
                    <p>See user counts by role and the newest accounts</p>
                    <a href="#users-section" class="btn btn-admin">View Users</a>
                </div>
            </div>

            <!-- Users -->
            <div class="recent-activity" id="users-section">
                <h5 class="mb-3">Users</h5>
                <div class="row">
                    <div class="col-lg-5 mb-3">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Role</th><th>Active</th><th>Total</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($role_rows as $r): ?>
                                        <tr>
                                            <td><?php echo e(ucfirst($r['role'])); ?></td>
                                            <td><?php echo (int)$r['active']; ?></td>
                                            <td><?php echo (int)$r['total']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-7 mb-3">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Newest Accounts</th><th>Role</th><th>Registered</th><th>Status</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($new_users as $u): ?>
                                        <tr>
                                            <td><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?><br><small class="text-muted"><?php echo e($u['username']); ?></small></td>
                                            <td><span class="badge badge-role"><?php echo e(ucfirst($u['role'])); ?></span></td>
                                            <td><?php echo fmt_date($u['created_at']); ?></td>
                                            <td><span class="badge badge-<?php echo (int)$u['is_active'] === 1 ? 'success' : 'danger'; ?>"><?php echo (int)$u['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overdue -->
            <div class="recent-activity" id="overdue-section">
                <h5 class="mb-3">Overdue Books</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Book</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Fine So Far</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($overdue_rows) > 0): ?>
                                <?php foreach ($overdue_rows as $row): ?>
                                    <tr>
                                        <td><?php echo e($row['first_name'] . ' ' . $row['last_name'] . ' (' . $row['member_number'] . ')'); ?></td>
                                        <td><?php echo e($row['title']); ?></td>
                                        <td><?php echo fmt_date($row['due_date']); ?></td>
                                        <td><span class="badge badge-danger"><?php echo (int)$row['days_late']; ?> days</span></td>
                                        <td><?php echo money((int)$row['days_late'] * FINE_PER_DAY); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted">No overdue books 🎉</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ((int)$stats['overdue_loans'] > count($overdue_rows)): ?>
                    <p class="text-muted mb-0">Showing the 5 most overdue of <?php echo (int)$stats['overdue_loans']; ?>. <a href="return_book.php">Open Return Book</a> to see every loan sorted by due date.</p>
                <?php endif; ?>
            </div>

            <!-- Recent Activity -->
            <div class="recent-activity" id="activity-section">
                <h5 class="mb-3">Recent System Activity</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Member</th>
                                <th>Book</th>
                                <th>Action</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent) > 0): ?>
                                <?php foreach ($recent as $loan):
                                    if (!empty($loan['return_date'])) {
                                        $status_class = 'success';
                                        $status_text  = 'Returned';
                                    } elseif (strtotime($loan['due_date']) < strtotime('today')) {
                                        $status_class = 'danger';
                                        $status_text  = 'Overdue';
                                    } else {
                                        $status_class = 'warning';
                                        $status_text  = 'Active';
                                    }
                                ?>
                                    <tr>
                                        <td><?php echo fmt_date($loan['action_date']); ?></td>
                                        <td><?php echo e($loan['first_name'] . ' ' . $loan['last_name'] . ' (' . $loan['member_number'] . ')'); ?></td>
                                        <td><?php echo e($loan['title']); ?></td>
                                        <td><?php echo e($loan['action']); ?></td>
                                        <td><span class="badge badge-<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted">No recent activity</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
