<?php
session_start();
include('config.php');

// Check if user is logged in as admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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

// Fetch count helper using prepared statements
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

$total_users = fetch_count($connection, "SELECT COUNT(*) FROM users WHERE is_active=1");
$total_books = fetch_count($connection, "SELECT COUNT(*) FROM books WHERE status='active'");
$total_members = fetch_count($connection, "SELECT COUNT(*) FROM members WHERE member_status='active'");
$total_loans = fetch_count($connection, "SELECT COUNT(*) FROM loan_records WHERE status='active'");

// Fetch recent loans
$recent_loans = [];
if ($stmt = $connection->prepare("
    SELECT lr.issue_date, u.first_name, u.last_name, b.title, m.member_number, lr.status
    FROM loan_records lr
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    JOIN books b ON lr.book_id = b.book_id
    ORDER BY lr.issue_date DESC
    LIMIT 10
")) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_loans[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Dashboard - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" type="text/css" href="bootstrap-4.4.1/css/bootstrap.min.css" />
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
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: bold;
        }
        
        .admin-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
                    <li class="nav-item active">
                        <a class="nav-link" href="admin_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="booksDropdown" role="button" data-toggle="dropdown">
                            Books
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="book_management.php">Manage Books</a>
                            <a class="dropdown-item" href="add_book.php">Add New Book</a>
                            <a class="dropdown-item" href="book_categories.php">Categories</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="membersDropdown" role="button" data-toggle="dropdown">
                            Members
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="member_management.php">All Members</a>
                            <a class="dropdown-item" href="member_registrations.php">New Registrations</a>
                            <a class="dropdown-item" href="suspended_members.php">Suspended Members</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="loansDropdown" role="button" data-toggle="dropdown">
                            Loans
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="loan_management.php">All Loans</a>
                            <a class="dropdown-item" href="overdue_books.php">Overdue Books</a>
                            <a class="dropdown-item" href="loan_history.php">Loan History</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-toggle="dropdown">
                            Reports
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="daily_reports.php">Daily Reports</a>
                            <a class="dropdown-item" href="monthly_reports.php">Monthly Reports</a>
                            <a class="dropdown-item" href="member_statistics.php">Member Statistics</a>
                            <a class="dropdown-item" href="book_statistics.php">Book Statistics</a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="system_settings.php">Settings</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                            Welcome, <?php echo e($_SESSION['name']); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="admin_profile.php">My Profile</a>
                            <a class="dropdown-item" href="change_password.php">Change Password</a>
                            <div class="dropdown-divider"></div>
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
                <div class="stat-card">
                    <h3><?php echo e($total_users); ?></h3>
                    <p>Total Users</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($total_books); ?></h3>
                    <p>Active Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($total_members); ?></h3>
                    <p>Active Members</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($total_loans); ?></h3>
                    <p>Active Loans</p>
                </div>
            </div>

            <!-- Admin Actions -->
            <h4 class="mb-3">Administrative Functions</h4>
            <div class="admin-actions">
                <div class="action-card">
                    <h5>User Management</h5>
                    <p>Manage system users, roles, and permissions</p>
                    <a href="user_management.php" class="btn btn-admin">Manage Users</a>
                </div>
                
                <div class="action-card">
                    <h5>Book Management</h5>
                    <p>Add, edit, and manage library books</p>
                    <a href="book_management.php" class="btn btn-admin">Manage Books</a>
                </div>
                
                <div class="action-card">
                    <h5>Member Management</h5>
                    <p>View and manage library members</p>
                    <a href="member_management.php" class="btn btn-admin">Manage Members</a>
                </div>
                
                <div class="action-card">
                    <h5>Loan Records</h5>
                    <p>View all loan transactions and history</p>
                    <a href="loan_management.php" class="btn btn-admin">View Loans</a>
                </div>
                
                <div class="action-card">
                    <h5>System Reports</h5>
                    <p>Generate reports and analytics</p>
                    <a href="reports.php" class="btn btn-admin">View Reports</a>
                </div>
                
                <div class="action-card">
                    <h5>System Settings</h5>
                    <p>Configure system parameters</p>
                    <a href="settings.php" class="btn btn-admin">Settings</a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="recent-activity">
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
                            <?php if (count($recent_loans) > 0): ?>
                                <?php foreach ($recent_loans as $loan): ?>
                                    <tr>
                                        <td><?php echo e(date('M d, Y', strtotime($loan['issue_date']))); ?></td>
                                        <td><?php echo e($loan['first_name'] . ' ' . $loan['last_name'] . ' (' . $loan['member_number'] . ')'); ?></td>
                                        <td><?php echo e($loan['title']); ?></td>
                                        <td>Book Issued</td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo ($loan['status'] == 'active' ? 'warning' : ($loan['status'] == 'returned' ? 'success' : 'danger')); 
                                            ?>">
                                                <?php echo e(ucfirst($loan['status'])); ?>
                                            </span>
                                        </td>
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