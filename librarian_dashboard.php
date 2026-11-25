<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'librarian' || !isset($_SESSION['user_id'])) {
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
$tbl = $connection->query("SHOW TABLES LIKE 'reservations'");
$reservations_ready = ($tbl && $tbl->num_rows > 0);

// ---------- Statistics (one query) ----------
$stats = $connection->query("
    SELECT
        (SELECT COALESCE(SUM(available_copies), 0) FROM books WHERE status = 'active')                  AS available_copies,
        (SELECT COUNT(*) FROM loan_records WHERE return_date IS NULL)                                   AS active_loans,
        (SELECT COUNT(*) FROM loan_records WHERE return_date IS NULL AND due_date < CURDATE())          AS overdue_loans,
        (SELECT COUNT(*) FROM loan_records WHERE issue_date = CURDATE())                                AS issued_today,
        (SELECT COUNT(*) FROM loan_records WHERE return_date = CURDATE())                               AS returned_today
")->fetch_assoc();

$waiting_total = 0;
$reservation_rows = [];
if ($reservations_ready) {
    $waiting_total = (int)$connection->query("SELECT COUNT(*) AS c FROM reservations WHERE status = 'waiting'")->fetch_assoc()['c'];

    $stmt = $connection->prepare("
        SELECT b.book_id, b.title, b.available_copies,
               COUNT(*) AS waiting,
               (SELECT CONCAT(u.first_name, ' ', u.last_name, ' (', m.member_number, ')')
                FROM reservations r2
                JOIN members m ON r2.member_id = m.member_id
                JOIN users u ON m.user_id = u.user_id
                WHERE r2.book_id = b.book_id AND r2.status = 'waiting'
                ORDER BY r2.reservation_id ASC
                LIMIT 1) AS next_member
        FROM reservations r
        JOIN books b ON r.book_id = b.book_id
        WHERE r.status = 'waiting'
        GROUP BY b.book_id, b.title, b.available_copies
        ORDER BY (b.available_copies > 0) DESC, waiting DESC, b.title ASC
        LIMIT 10
    ");
    $stmt->execute();
    $reservation_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ---------- Overdue loans (most overdue first) ----------
$stmt = $connection->prepare("
    SELECT lr.loan_id, lr.due_date, DATEDIFF(CURDATE(), lr.due_date) AS days_late,
           b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE lr.return_date IS NULL AND lr.due_date < CURDATE()
    ORDER BY lr.due_date ASC
    LIMIT 10
");
$stmt->execute();
$overdue_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Today's activity (issues and returns as separate rows) ----------
$stmt = $connection->prepare("
    SELECT 'Issued' AS action, lr.loan_id, lr.due_date, lr.return_date,
           b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE lr.issue_date = CURDATE()
    UNION ALL
    SELECT 'Returned' AS action, lr.loan_id, lr.due_date, lr.return_date,
           b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE lr.return_date = CURDATE()
    ORDER BY loan_id DESC
    LIMIT 15
");
$stmt->execute();
$today_activity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Librarian Dashboard - Library Management System</title>
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
            background: rgba(40, 167, 69, 0.9);
            padding: 15px 30px;
            border-radius: 15px 15px 0 0;
        }

        .content {
            padding: 30px;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .stat-card p {
            margin: 0;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            background: rgba(248, 249, 250, 0.9);
            padding: 25px;
            border-radius: 10px;
            border-left: 5px solid #28a745;
        }

        .action-card h5 {
            color: #28a745;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .btn-librarian {
            background: #28a745;
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }

        .btn-librarian:hover {
            background: #218838;
            color: white;
            text-decoration: none;
        }

        .daily-tasks {
            background: rgba(248, 249, 250, 0.9);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .table th {
            background: #28a745;
            color: white;
            border: none;
        }

        .alert-warning {
            border-left: 5px solid #ffc107;
        }

        .section-anchor {
            scroll-margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="librarian_dashboard.php">📖 LMS Librarian</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item active"><a class="nav-link" href="librarian_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="issue_book.php">Issue Book</a></li>
                    <li class="nav-item"><a class="nav-link" href="return_book.php">Return Book</a></li>
                    <li class="nav-item"><a class="nav-link" href="book_management.php">Manage Books</a></li>
                    <li class="nav-item"><a class="nav-link" href="#overdue-section">Overdue</a></li>
                    <li class="nav-item"><a class="nav-link" href="#reservation-section">Reservations</a></li>
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
            <h2 class="mb-4">Daily Operations</h2>

            <!-- Statistics -->
            <div class="stats-row">
                <div class="stat-card" title="Copies on the shelf right now">
                    <h3><?php echo (int)$stats['available_copies']; ?></h3>
                    <p>Copies Available</p>
                </div>
                <div class="stat-card" title="Books currently borrowed">
                    <h3><?php echo (int)$stats['active_loans']; ?></h3>
                    <p>Active Loans</p>
                </div>
                <div class="stat-card" title="Borrowed books past their due date">
                    <h3><?php echo (int)$stats['overdue_loans']; ?></h3>
                    <p>Overdue Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$stats['issued_today']; ?></h3>
                    <p>Issued Today</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$stats['returned_today']; ?></h3>
                    <p>Returned Today</p>
                </div>
                <?php if ($reservations_ready): ?>
                    <div class="stat-card" title="Members waiting for a book">
                        <h3><?php echo $waiting_total; ?></h3>
                        <p>Waiting Reservations</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Overdue Alert -->
            <?php if ((int)$stats['overdue_loans'] > 0): ?>
                <div class="alert alert-warning">
                    <strong>Attention!</strong> There are <?php echo (int)$stats['overdue_loans']; ?> overdue books that need follow-up.
                    <a href="#overdue-section">See the list</a>.
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <h4 class="mb-3">Quick Actions</h4>
            <div class="quick-actions">
                <div class="action-card">
                    <h5>Issue Book</h5>
                    <p>Issue books to library members</p>
                    <a href="issue_book.php" class="btn btn-librarian">Issue Book</a>
                </div>

                <div class="action-card">
                    <h5>Return Book</h5>
                    <p>Process returns and fines</p>
                    <a href="return_book.php" class="btn btn-librarian">Return Book</a>
                </div>

                <div class="action-card">
                    <h5>Manage Books</h5>
                    <p>Add or edit book information</p>
                    <a href="book_management.php" class="btn btn-librarian">Manage Books</a>
                </div>

                <div class="action-card">
                    <h5>Overdue Follow-up</h5>
                    <p>See who has overdue books</p>
                    <a href="#overdue-section" class="btn btn-librarian">View Overdue</a>
                </div>

                <?php if ($reservations_ready): ?>
                    <div class="action-card">
                        <h5>Reservations</h5>
                        <p>See which books members are waiting for</p>
                        <a href="#reservation-section" class="btn btn-librarian">View Queue</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Overdue loans -->
            <div class="daily-tasks section-anchor" id="overdue-section">
                <h5 class="mb-3">Overdue Books</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Book Title</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Fine So Far</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($overdue_rows) > 0): ?>
                                <?php foreach ($overdue_rows as $row): ?>
                                    <tr>
                                        <td><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?><br><small class="text-muted"><?php echo e($row['member_number']); ?></small></td>
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
                    <p class="text-muted mb-0">Showing the 10 most overdue. <a href="return_book.php">Open Return Book</a> to see every loan sorted by due date.</p>
                <?php endif; ?>
            </div>

            <!-- Reservation queue -->
            <?php if ($reservations_ready): ?>
                <div class="daily-tasks section-anchor" id="reservation-section">
                    <h5 class="mb-3">Reservation Queue</h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
                                    <th>Waiting</th>
                                    <th>Next In Line</th>
                                    <th>Copies On Shelf</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($reservation_rows) > 0): ?>
                                    <?php foreach ($reservation_rows as $row): ?>
                                        <tr>
                                            <td><?php echo e($row['title']); ?></td>
                                            <td><?php echo (int)$row['waiting']; ?></td>
                                            <td><?php echo e($row['next_member']); ?></td>
                                            <td><?php echo (int)$row['available_copies']; ?></td>
                                            <td>
                                                <?php if ((int)$row['available_copies'] > 0): ?>
                                                    <span class="badge badge-success">Ready to issue</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Waiting for a return</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted">No members are waiting for books</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Today's Activity -->
            <div class="daily-tasks">
                <h5 class="mb-3">Today's Loan Activity</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Member</th>
                                <th>Book Title</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($today_activity) > 0): ?>
                                <?php foreach ($today_activity as $activity):
                                    if (!empty($activity['return_date'])) {
                                        $status_class = 'success';
                                        $status_text  = 'Returned';
                                    } elseif (strtotime($activity['due_date']) < strtotime('today')) {
                                        $status_class = 'danger';
                                        $status_text  = 'Overdue';
                                    } else {
                                        $status_class = 'warning';
                                        $status_text  = 'Active';
                                    }
                                ?>
                                    <tr>
                                        <td><span class="badge badge-<?php echo $activity['action'] === 'Issued' ? 'primary' : 'success'; ?>"><?php echo e($activity['action']); ?></span></td>
                                        <td><?php echo e($activity['first_name'] . ' ' . $activity['last_name']); ?><br><small class="text-muted"><?php echo e($activity['member_number']); ?></small></td>
                                        <td><?php echo e($activity['title']); ?></td>
                                        <td><?php echo fmt_date($activity['due_date']); ?></td>
                                        <td><span class="badge badge-<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted">No activity today</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
