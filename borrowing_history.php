<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

const ROWS_PER_PAGE = 10;
const FINE_PER_DAY  = 1.00; // RM per day overdue (same rate as the dashboard)

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : '-';
}

$user_id = (int)$_SESSION['user_id'];

// ---------- Find the member record (loan_records.member_id -> members.member_id) ----------
$stmt = $connection->prepare("SELECT member_id, member_number FROM members WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    session_destroy();
    header("Location: login.php");
    exit();
}
$member_id = (int)$member['member_id'];

// ---------- Filters ----------
$allowed_filters = ['all', 'current', 'overdue', 'returned'];
$filter = $_GET['status'] ?? 'all';
if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}
$page = max(1, (int)($_GET['page'] ?? 1));

$where = "lr.member_id = ?";
if ($filter === 'current') {
    $where .= " AND lr.return_date IS NULL";
} elseif ($filter === 'overdue') {
    $where .= " AND lr.return_date IS NULL AND lr.due_date < CURDATE()";
} elseif ($filter === 'returned') {
    $where .= " AND lr.return_date IS NOT NULL";
}

// ---------- Summary numbers (always for the whole history) ----------
$stmt = $connection->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(return_date IS NOT NULL), 0) AS returned_cnt,
        COALESCE(SUM(return_date IS NULL), 0) AS current_cnt,
        COALESCE(SUM(return_date IS NULL AND due_date < CURDATE()), 0) AS overdue_cnt
    FROM loan_records
    WHERE member_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---------- Count for pagination ----------
$stmt = $connection->prepare("SELECT COUNT(*) AS total FROM loan_records lr WHERE $where");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$total_rows = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_rows / ROWS_PER_PAGE));
$page   = min($page, $total_pages);
$offset = ($page - 1) * ROWS_PER_PAGE;

// ---------- Loan rows ----------
$limit  = ROWS_PER_PAGE;
$stmt = $connection->prepare("
    SELECT lr.loan_id, lr.issue_date, lr.due_date, lr.return_date, lr.fine_amount,
           b.title, b.author,
           DATEDIFF(CURDATE(), lr.due_date) AS days_late
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    WHERE $where
    ORDER BY lr.issue_date DESC, lr.loan_id DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $member_id, $limit, $offset);
$stmt->execute();
$loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function page_url($p) {
    $query = ['status' => $_GET['status'] ?? '', 'page' => $p];
    return 'borrowing_history.php?' . http_build_query(array_filter($query, function ($v) { return $v !== '' && $v !== null; }));
}

$filter_labels = [
    'all'      => 'All',
    'current'  => 'Currently borrowed',
    'overdue'  => 'Overdue',
    'returned' => 'Returned',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Borrowing History - Library Management System</title>
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

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: linear-gradient(135deg, #4364f7, #6fb1fc);
            color: white;
            padding: 25px 20px;
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
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: 1.2px;
        }

        .stat-card p {
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin: 0;
        }

        /* Filter tabs */
        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
        }

        .filter-tabs a {
            padding: 9px 22px;
            border-radius: 12px;
            background: #f8f9fa;
            color: #4364f7;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #d6defa;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .filter-tabs a:hover {
            background: #e6f0ff;
            text-decoration: none;
        }

        .filter-tabs a.active {
            background: #4364f7;
            color: #fff;
            box-shadow: 0 5px 15px rgba(67, 100, 247, 0.4);
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

        /* History table */
        .history-box {
            background: #f8f9fa;
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
        }

        .table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(67, 100, 247, 0.1);
            margin-bottom: 0;
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

        /* Pagination */
        .pagination {
            justify-content: center;
            margin-top: 25px;
            margin-bottom: 0;
        }

        .page-link {
            color: #4364f7;
            font-weight: 600;
            border-radius: 10px;
            margin: 0 3px;
            border: 1px solid #d6defa;
        }

        .page-item.active .page-link {
            background: #4364f7;
            border-color: #4364f7;
            color: #fff;
        }

        .page-item.disabled .page-link {
            color: #aaa;
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
                    <li class="nav-item">
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
                    <li class="nav-item dropdown active">
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
                            Welcome, <?php echo e($_SESSION['name'] ?? ''); ?>
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
            <h3 class="page-title">Borrowing History</h3>
            <p class="page-subtitle">Every book you have borrowed from the library, newest first.</p>

            <!-- Summary -->
            <section class="stats-row">
                <div class="stat-card" title="All books you have ever borrowed">
                    <h3><?php echo (int)$summary['total']; ?></h3>
                    <p>Total Borrowed</p>
                </div>
                <div class="stat-card" title="Books you have returned">
                    <h3><?php echo (int)$summary['returned_cnt']; ?></h3>
                    <p>Returned</p>
                </div>
                <div class="stat-card" title="Books you still have">
                    <h3><?php echo (int)$summary['current_cnt']; ?></h3>
                    <p>Currently Borrowed</p>
                </div>
                <div class="stat-card" title="Books past their due date">
                    <h3><?php echo (int)$summary['overdue_cnt']; ?></h3>
                    <p>Overdue</p>
                </div>
            </section>

            <!-- Filter tabs -->
            <div class="filter-tabs">
                <?php foreach ($filter_labels as $key => $label): ?>
                    <a href="borrowing_history.php?status=<?php echo e($key); ?>"
                       class="<?php echo $filter === $key ? 'active' : ''; ?>"><?php echo e($label); ?></a>
                <?php endforeach; ?>
            </div>

            <!-- History table -->
            <section class="history-box">
                <div class="table-responsive">
                    <table class="table table-hover text-center">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Author</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Return Date</th>
                                <th>Status</th>
                                <th>Fine</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($loans) > 0): ?>
                                <?php foreach ($loans as $loan):
                                    $returned   = !empty($loan['return_date']);
                                    $days_late  = (int)$loan['days_late'];
                                    $is_overdue = !$returned && $days_late > 0;

                                    if ($returned) {
                                        $badge_class = 'success';
                                        $badge_text  = 'Returned';
                                        $fine = (float)$loan['fine_amount'];
                                    } elseif ($is_overdue) {
                                        $badge_class = 'danger';
                                        $badge_text  = 'Overdue';
                                        $fine = $days_late * FINE_PER_DAY;
                                    } else {
                                        $badge_class = 'warning';
                                        $badge_text  = 'Active';
                                        $fine = 0;
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo e($loan['title']); ?></strong></td>
                                        <td><?php echo e($loan['author']); ?></td>
                                        <td><?php echo fmt_date($loan['issue_date']); ?></td>
                                        <td><?php echo fmt_date($loan['due_date']); ?></td>
                                        <td><?php echo $returned ? fmt_date($loan['return_date']) : '-'; ?></td>
                                        <td><span class="badge badge-<?php echo $badge_class; ?>"><?php echo $badge_text; ?></span></td>
                                        <td><?php echo $fine > 0 ? 'RM ' . number_format($fine, 2) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-muted py-4">
                                        <?php echo $filter === 'all' ? 'You have not borrowed any books yet.' : 'No records match this filter.'; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav aria-label="History pages">
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo e(page_url(max(1, $page - 1))); ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo e(page_url($i)); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo e(page_url(min($total_pages, $page + 1))); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>

            <div class="mt-4">
                <a href="member_dashboard.php" class="btn btn-member">← Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>