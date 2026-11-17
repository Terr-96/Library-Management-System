<?php
session_start();
include('config.php');

// Check if user is logged in as librarian
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'librarian') {
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

// Helper function to safely output text
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Fetch counts with prepared statements
function fetch_count($connection, $query) {
    if ($stmt = $connection->prepare($query)) {
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return $count;
    }
    return 0;
}

$books_count = fetch_count($connection, "SELECT COUNT(*) FROM books WHERE status='active'");
$loans_count = fetch_count($connection, "SELECT COUNT(*) FROM loan_records WHERE status='active'");
$overdue_count = fetch_count($connection, "SELECT COUNT(*) FROM loan_records WHERE status='overdue'");
$returned_count = fetch_count($connection, "SELECT COUNT(*) FROM loan_records WHERE return_date = CURDATE()");
$issued_count = fetch_count($connection, "SELECT COUNT(*) FROM loan_records WHERE issue_date = CURDATE()");

// Fetch today's activity
$today_activity = [];
if ($stmt = $connection->prepare("
    SELECT lr.issue_date, lr.return_date, lr.due_date, lr.status, u.first_name, u.last_name, b.title, m.member_number
    FROM loan_records lr
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    JOIN books b ON lr.book_id = b.book_id
    WHERE DATE(lr.issue_date) = CURDATE() OR DATE(lr.return_date) = CURDATE()
    ORDER BY GREATEST(lr.issue_date, IFNULL(lr.return_date, '0000-00-00')) DESC
    LIMIT 15
")) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $today_activity[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Librarian Dashboard - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" type="text/css" href="bootstrap-4.4.1/css/bootstrap.min.css" />
    <script type="text/javascript" src="bootstrap-4.4.1/js/jquery_latest.js"></script>
    <script type="text/javascript" src="bootstrap-4.4.1/js/bootstrap.min.js"></script>
    <style type="text/css">
        /* === Your original CSS preserved === */
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
                    <li class="nav-item active">
                        <a class="nav-link" href="librarian_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="transactionsDropdown" role="button" data-toggle="dropdown">
                            Transactions
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="issue_book.php">Issue Book</a>
                            <a class="dropdown-item" href="return_book.php">Return Book</a>
                            <a class="dropdown-item" href="renew_book.php">Renew Book</a>
                            <a class="dropdown-item" href="reserve_book.php">Book Reservations</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="booksDropdown" role="button" data-toggle="dropdown">
                            Books
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="book_management.php">Manage Books</a>
                            <a class="dropdown-item" href="book_search.php">Search Books</a>
                            <a class="dropdown-item" href="book_availability.php">Check Availability</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="membersDropdown" role="button" data-toggle="dropdown">
                            Members
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="member_search.php">Search Members</a>
                            <a class="dropdown-item" href="member_loans.php">Member Loans</a>
                            <a class="dropdown-item" href="member_fines.php">Member Fines</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-toggle="dropdown">
                            Reports
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="overdue_report.php">Overdue Books</a>
                            <a class="dropdown-item" href="daily_transactions.php">Daily Transactions</a>
                            <a class="dropdown-item" href="fine_collection.php">Fine Collection</a>
                        </div>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                            Welcome, <?php echo e($_SESSION['name']); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="librarian_profile.php">My Profile</a>
                            <a class="dropdown-item" href="change_password.php">Change Password</a>
                            <div class="dropdown-divider"></div>
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
                <div class="stat-card">
                    <h3><?php echo e($books_count); ?></h3>
                    <p>Available Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($loans_count); ?></h3>
                    <p>Active Loans</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($overdue_count); ?></h3>
                    <p>Overdue Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($issued_count); ?></h3>
                    <p>Issued Today</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($returned_count); ?></h3>
                    <p>Returned Today</p>
                </div>
            </div>

            <!-- Overdue Alert -->
            <?php if ($overdue_count > 0): ?>
            <div class="alert alert-warning">
                <strong>Attention!</strong> There are <?php echo e($overdue_count); ?> overdue books that need follow-up.
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
                    <p>Process book returns</p>
                    <a href="return_book.php" class="btn btn-librarian">Return Book</a>
                </div>
                
                <div class="action-card">
                    <h5>Manage Books</h5>
                    <p>Add or edit book information</p>
                    <a href="book_management.php" class="btn btn-librarian">Manage Books</a>
                </div>
                
                <div class="action-card">
                    <h5>Member Search</h5>
                    <p>Search and view member details</p>
                    <a href="member_search.php" class="btn btn-librarian">Search Members</a>
                </div>
                
                <div class="action-card">
                    <h5>Loan History</h5>
                    <p>View borrowing history and reports</p>
                    <a href="loan_history.php" class="btn btn-librarian">View History</a>
                </div>
                
                <div class="action-card">
                    <h5>Fine Management</h5>
                    <p>Calculate and manage fines</p>
                    <a href="fine_management.php" class="btn btn-librarian">Manage Fines</a>
                </div>
            </div>

            <!-- Today's Activity -->
            <div class="daily-tasks">
                <h5 class="mb-3">Today's Loan Activity</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Member</th>
                                <th>Book Title</th>
                                <th>Action</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($today_activity) > 0): ?>
                                <?php foreach ($today_activity as $activity): 
                                    $action = (date('Y-m-d', strtotime($activity['return_date'])) == date('Y-m-d')) ? 'Returned' : 'Issued';
                                    $time = ($action === 'Returned') ? date('H:i', strtotime($activity['return_date'])) : date('H:i', strtotime($activity['issue_date']));
                                ?>
                                    <tr>
                                        <td><?php echo e($time); ?></td>
                                        <td><?php echo e($activity['first_name'] . ' ' . $activity['last_name']); ?><br><small class="text-muted"><?php echo e($activity['member_number']); ?></small></td>
                                        <td><?php echo e($activity['title']); ?></td>
                                        <td><span class="badge badge-<?php echo $action === 'Issued' ? 'primary' : 'success'; ?>"><?php echo e($action); ?></span></td>
                                        <td><?php echo e(date('M d, Y', strtotime($activity['due_date']))); ?></td>
                                        <td><span class="badge badge-<?php echo ($activity['status'] == 'active' ? 'warning' : ($activity['status'] == 'returned' ? 'success' : 'danger')); ?>"><?php echo e(ucfirst($activity['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted">No activity today</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>