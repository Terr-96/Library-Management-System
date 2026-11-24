<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$member_id = (int)$_SESSION['user_id'];
const FINE_PER_DAY = 1.00;   // RM per day overdue
const DUE_SOON_DAYS = 2;     // show "Due Soon" badge

// ---------- Member information (prepared statement) ----------
$stmt = $connection->prepare("
    SELECT m.*, u.first_name, u.last_name, u.email
    FROM members m
    JOIN users u ON m.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    // Account exists in session but no member record
    session_destroy();
    header("Location: login.php");
    exit();
}

// loan_records.member_id references members.member_id (NOT users.user_id)
$loan_member_id = (int)$member['member_id'];

// ---------- Statistics (one query instead of three) ----------
$stmt = $connection->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status IN ('active','overdue')), 0) AS current_cnt,
        COALESCE(SUM(status = 'overdue' OR (status = 'active' AND due_date < CURDATE())), 0) AS overdue_cnt
    FROM loan_records
    WHERE member_id = ?
");
$stmt->bind_param("i", $loan_member_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$current_count   = (int)$stats['current_cnt'];
$total_count     = (int)$stats['total'];
$overdue_count   = (int)$stats['overdue_cnt'];
$available_slots = max(0, (int)$member['max_books_allowed'] - $current_count);

// ---------- Current loans (fine & dates calculated by the database) ----------
$stmt = $connection->prepare("
    SELECT lr.issue_date, lr.due_date, b.title, b.author,
           DATEDIFF(CURDATE(), lr.due_date) AS days_late
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    WHERE lr.member_id = ? AND lr.status IN ('active', 'overdue')
    ORDER BY lr.due_date ASC
");
$stmt->bind_param("i", $loan_member_id);
$stmt->execute();
$current_loans = $stmt->get_result();

// ---------- Helpers ----------
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : '-';
}

function member_status_badge($status) {
    $map = [
        'active'    => 'success',
        'suspended' => 'danger',
        'expired'   => 'warning',
        'inactive'  => 'warning',
    ];
    $key = strtolower((string)$status);
    return $map[$key] ?? 'warning';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Member Dashboard - Library Management System</title>
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

        /* Member Info */
        .member-info {
            background: #f8f9fa;
            padding: 30px 35px;
            border-radius: 15px;
            margin-bottom: 40px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.2);
            transition: box-shadow 0.3s ease;
        }

        .member-info:hover {
            box-shadow: 0 10px 25px rgba(67, 100, 247, 0.4);
        }

        .member-info h4 {
            font-weight: 700;
            color: #0052d4;
            margin-bottom: 20px;
        }

        .member-info p {
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, #4364f7, #6fb1fc);
            color: white;
            padding: 30px 20px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 8px 20px rgba(67, 100, 247, 0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: default;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(67, 100, 247, 0.5);
        }

        .stat-card h3 {
            font-size: 3rem;
            font-weight: 800;
            margin-bottom: 10px;
            letter-spacing: 1.2px;
        }

        .stat-card p {
            font-size: 1.2rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .action-card {
            background: #f8f9fa;
            padding: 30px 25px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            transition: box-shadow 0.3s ease;
        }

        .action-card:hover {
            box-shadow: 0 12px 30px rgba(67, 100, 247, 0.3);
        }

        .action-card h5 {
            color: #4364f7;
            font-weight: 700;
            margin-bottom: 18px;
            font-size: 1.3rem;
        }

        .action-card p {
            font-size: 1rem;
            margin-bottom: 20px;
            color: #555;
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

        /* Current Books */
        .current-books {
            background: #f8f9fa;
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
        }

        .current-books h5 {
            font-weight: 700;
            color: #4364f7;
            margin-bottom: 25px;
            font-size: 1.5rem;
        }

        /* Table Styling */
        .table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(67, 100, 247, 0.1);
        }

        .table thead th {
            background: #4364f7;
            color: white;
            border: none;
            font-weight: 700;
            font-size: 1rem;
            text-align: center;
        }

        .table tbody td {
            vertical-align: middle;
            text-align: center;
            font-size: 0.95rem;
            color: #444;
        }

        .table tbody tr:hover {
            background-color: #e6f0ff;
            transition: background-color 0.3s ease;
        }

        /* Badges */
        .badge-warning {
            background: #ffc107;
            color: #212529;
            font-weight: 600;
            padding: 0.45em 0.75em;
            border-radius: 12px;
        }

        .badge-success {
            background: #28a745;
            font-weight: 600;
            padding: 0.45em 0.75em;
            border-radius: 12px;
        }

        .badge-danger {
            background: #dc3545;
            font-weight: 600;
            padding: 0.45em 0.75em;
            border-radius: 12px;
        }

        .badge-info {
            background: #17a2b8;
            color: #fff;
            font-weight: 600;
            padding: 0.45em 0.75em;
            border-radius: 12px;
        }

        /* Responsive tweaks */
        @media (max-width: 768px) {
            .content {
                padding: 25px 20px;
            }
            .navbar {
                padding: 15px 20px;
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
                    <li class="nav-item active">
                        <a class="nav-link" href="member_dashboard.php">My Dashboard</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="booksDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Books
                        </a>
                        <div class="dropdown-menu" aria-labelledby="booksDropdown">
                            <a class="dropdown-item" href="book_catalog.php">Browse Catalog</a>
                            <a class="dropdown-item" href="book_search.php">Search Books</a>
                            <a class="dropdown-item" href="new_arrivals.php">New Arrivals</a>
                            <a class="dropdown-item" href="popular_books.php">Popular Books</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="myBooksDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            My Books
                        </a>
                        <div class="dropdown-menu" aria-labelledby="myBooksDropdown">
                            <a class="dropdown-item" href="current_loans.php">Current Loans</a>
                            <a class="dropdown-item" href="borrowing_history.php">Borrowing History</a>
                            <a class="dropdown-item" href="renew_books.php">Renew Books</a>
                            <a class="dropdown-item" href="reservations.php">My Reservations</a>
                        </div>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="accountDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            My Account
                        </a>
                        <div class="dropdown-menu" aria-labelledby="accountDropdown">
                            <a class="dropdown-item" href="account_overview.php">Account Overview</a>
                            <a class="dropdown-item" href="payment_history.php">Payment History</a>
                            <a class="dropdown-item" href="fines_fees.php">Fines &amp; Fees</a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="help_support.php">Help</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Welcome, <?php echo e($_SESSION['name'] ?? $member['first_name']); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                            <a class="dropdown-item" href="update_profile.php">My Profile</a>
                            <a class="dropdown-item" href="notification_settings.php">Notifications</a>
                            <a class="dropdown-item" href="update_profile.php#password">Change Password</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="logout.php">Logout</a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="content">
            <!-- Member Information -->
            <section class="member-info">
                <div class="row">
                    <div class="col-md-6">
                        <h4>Member Profile</h4>
                        <p><strong>Name:</strong> <?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></p>
                        <p><strong>Member Number:</strong> <?php echo e($member['member_number']); ?></p>
                        <p><strong>Membership Type:</strong> <?php echo e(ucfirst($member['membership_type'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h4>Account Status</h4>
                        <p><strong>Join Date:</strong> <?php echo fmt_date($member['join_date']); ?></p>
                        <p><strong>Status:</strong>
                            <span class="badge badge-<?php echo member_status_badge($member['member_status']); ?>">
                                <?php echo e(ucfirst($member['member_status'])); ?>
                            </span>
                        </p>
                        <p><strong>Total Fines:</strong> RM <?php echo number_format((float)$member['total_fine_amount'], 2); ?></p>
                    </div>
                </div>
            </section>

            <!-- Statistics -->
            <section class="stats-row">
                <div class="stat-card" title="Books you currently have borrowed">
                    <h3><?php echo $current_count; ?></h3>
                    <p>Current Books</p>
                </div>
                <div class="stat-card" title="How many more books you can borrow">
                    <h3><?php echo $available_slots; ?></h3>
                    <p>Available Slots</p>
                </div>
                <div class="stat-card" title="Total books you have borrowed">
                    <h3><?php echo $total_count; ?></h3>
                    <p>Total Borrowed</p>
                </div>
                <div class="stat-card" title="Books overdue for return">
                    <h3><?php echo $overdue_count; ?></h3>
                    <p>Overdue Books</p>
                </div>
            </section>

            <!-- Quick Actions -->
            <h4 class="mb-4" style="color:#4364f7; font-weight:700;">Library Services</h4>
            <section class="quick-actions">
                <div class="action-card">
                    <h5>Browse Books</h5>
                    <p>Search and browse available books</p>
                    <a href="book_catalog.php" class="btn btn-member">Browse Catalog</a>
                </div>

                <div class="action-card">
                    <h5>My Borrowing History</h5>
                    <p>View your complete borrowing history</p>
                    <a href="borrowing_history.php" class="btn btn-member">View History</a>
                </div>

                <div class="action-card">
                    <h5>Renew Books</h5>
                    <p>Extend your book due dates</p>
                    <a href="renew_books.php" class="btn btn-member">Renew Books</a>
                </div>

                <div class="action-card">
                    <h5>Reserve Books</h5>
                    <p>Reserve books that are currently unavailable</p>
                    <a href="reserve_books.php" class="btn btn-member">Reserve Books</a>
                </div>

                <div class="action-card">
                    <h5>Pay Fines</h5>
                    <p>View and pay outstanding fines</p>
                    <a href="pay_fines.php" class="btn btn-member">Pay Fines</a>
                </div>

                <div class="action-card">
                    <h5>Update Profile</h5>
                    <p>Update your personal information</p>
                    <a href="update_profile.php" class="btn btn-member">Update Profile</a>
                </div>
            </section>

            <!-- Current Books -->
            <section class="current-books">
                <h5 class="mb-4">Currently Borrowed Books</h5>
                <div class="table-responsive">
                    <table class="table table-hover text-center">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Author</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Fine</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($current_loans->num_rows > 0): ?>
                                <?php while ($loan = $current_loans->fetch_assoc()):
                                    $days_late  = (int)$loan['days_late'];
                                    $is_overdue = $days_late > 0;
                                    $days_left  = -$days_late;
                                    $is_due_soon = !$is_overdue && $days_left <= DUE_SOON_DAYS;
                                    $fine = $is_overdue ? $days_late * FINE_PER_DAY : 0;

                                    if ($is_overdue) {
                                        $badge_class = 'danger';
                                        $badge_text  = 'Overdue';
                                    } elseif ($is_due_soon) {
                                        $badge_class = 'info';
                                        $badge_text  = 'Due Soon';
                                    } else {
                                        $badge_class = 'warning';
                                        $badge_text  = 'Active';
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo e($loan['title']); ?></strong></td>
                                    <td><?php echo e($loan['author']); ?></td>
                                    <td><?php echo fmt_date($loan['issue_date']); ?></td>
                                    <td><?php echo fmt_date($loan['due_date']); ?></td>
                                    <td><span class="badge badge-<?php echo $badge_class; ?>"><?php echo $badge_text; ?></span></td>
                                    <td><?php echo $fine > 0 ? 'RM ' . number_format($fine, 2) : '-'; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted">No books currently borrowed</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
<?php $stmt->close(); ?>