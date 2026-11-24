<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ---------- Renewal rules ----------
const MAX_RENEWALS  = 2;   // how many times one loan can be renewed
const RENEWAL_DAYS  = 14;  // days added to the due date each time

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : '-';
}

$user_id = (int)$_SESSION['user_id'];

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Member record (loan_records.member_id -> members.member_id) ----------
$stmt = $connection->prepare("SELECT member_id, member_status, total_fine_amount FROM members WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) {
    session_destroy();
    header("Location: login.php");
    exit();
}
$member_id    = (int)$member['member_id'];
$is_suspended = strtolower($member['member_status']) !== 'active';
$has_fines    = (float)$member['total_fine_amount'] > 0;

// ---------- Is the renewal_count column installed? ----------
$col = $connection->query("SHOW COLUMNS FROM loan_records LIKE 'renewal_count'");
$column_ready = ($col && $col->num_rows > 0);

// ---------- Is the reservations table installed? (blocks renewal when someone is waiting) ----------
$tbl = $connection->query("SHOW TABLES LIKE 'reservations'");
$reservations_ready = ($tbl && $tbl->num_rows > 0);
$waiting_clause = $reservations_ready
    ? " AND NOT EXISTS (SELECT 1 FROM reservations rs WHERE rs.book_id = loan_records.book_id AND rs.status = 'waiting' AND rs.member_id <> loan_records.member_id)"
    : "";
$waiting_select = $reservations_ready
    ? "(SELECT COUNT(*) FROM reservations rs WHERE rs.book_id = lr.book_id AND rs.status = 'waiting' AND rs.member_id <> lr.member_id)"
    : "0";

// ---------- Flash message ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Handle a renewal request ----------
if ($column_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_id = (int)($_POST['loan_id'] ?? 0);
    $token   = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, (string)$token)) {
        $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } elseif ($is_suspended) {
        $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Your membership is suspended, so books cannot be renewed.'];
    } elseif ($has_fines) {
        $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Please pay your outstanding fines before renewing books.'];
    } else {
        // All rules are checked inside the UPDATE itself, so two quick clicks cannot renew twice.
        $days  = RENEWAL_DAYS;
        $max   = MAX_RENEWALS;
        $stmt  = $connection->prepare("
            UPDATE loan_records
            SET due_date = DATE_ADD(due_date, INTERVAL $days DAY),
                renewal_count = renewal_count + 1
            WHERE loan_id = ?
              AND member_id = ?
              AND return_date IS NULL
              AND due_date >= CURDATE()
              AND renewal_count < $max$waiting_clause
        ");
        $stmt->bind_param("ii", $loan_id, $member_id);
        $stmt->execute();
        $renewed = ($stmt->affected_rows === 1);
        $stmt->close();

        if ($renewed) {
            $stmt = $connection->prepare("
                SELECT lr.due_date, b.title
                FROM loan_records lr JOIN books b ON lr.book_id = b.book_id
                WHERE lr.loan_id = ? AND lr.member_id = ?
            ");
            $stmt->bind_param("ii", $loan_id, $member_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => '“' . $row['title'] . '” was renewed. New due date: ' . fmt_date($row['due_date']) . '.'
            ];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'This book cannot be renewed. It may be overdue, already returned, at the renewal limit, or reserved by another member.'];
        }
    }
    header("Location: renew_books.php");
    exit();
}

// ---------- Loans that are still with the member ----------
$loans = [];
if ($column_ready) {
    $days = RENEWAL_DAYS;
    $stmt = $connection->prepare("
        SELECT lr.loan_id, lr.issue_date, lr.due_date, lr.renewal_count,
               DATE_ADD(lr.due_date, INTERVAL $days DAY) AS new_due_date,
               DATEDIFF(CURDATE(), lr.due_date) AS days_late,
               $waiting_select AS waiting_cnt,
               b.title, b.author
        FROM loan_records lr
        JOIN books b ON lr.book_id = b.book_id
        WHERE lr.member_id = ? AND lr.return_date IS NULL
        ORDER BY lr.due_date ASC
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Renew Books - Library Management System</title>
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

        /* Rules box */
        .rules-box {
            background: #f8f9fa;
            padding: 22px 30px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            margin-bottom: 30px;
        }

        .rules-box h5 {
            color: #4364f7;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .rules-box ul {
            margin-bottom: 0;
            padding-left: 20px;
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

        .btn-renew {
            background: #4364f7;
            border: none;
            color: white;
            padding: 8px 18px;
            border-radius: 10px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(67, 100, 247, 0.4);
            transition: background-color 0.3s ease;
        }

        .btn-renew:hover {
            background: #2a47c7;
            color: white;
        }

        .not-allowed {
            color: #888;
            font-size: 0.85rem;
        }

        /* Table */
        .current-books {
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

        .alert {
            border-radius: 12px;
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
                    <li class="nav-item dropdown active">
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
                    <li class="nav-item dropdown">
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
            <h3 class="page-title">Renew Books</h3>
            <p class="page-subtitle">Extend the due date of books you are still borrowing.</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <?php if (!$column_ready): ?>
                <div class="alert alert-warning">
                    <strong>One-time setup needed.</strong> The <code>renewal_count</code> column is missing from
                    <code>loan_records</code>. Run this in phpMyAdmin (SQL tab), then reload this page:
                    <pre class="mb-0 mt-2">ALTER TABLE loan_records ADD renewal_count INT NOT NULL DEFAULT 0;</pre>
                </div>
            <?php else: ?>

                <section class="rules-box">
                    <h5>Renewal rules</h5>
                    <ul>
                        <li>Each book can be renewed up to <strong><?php echo MAX_RENEWALS; ?> times</strong>, adding <strong><?php echo RENEWAL_DAYS; ?> days</strong> each time.</li>
                        <li>Overdue books must be returned and cannot be renewed.</li>
                        <li>You cannot renew while you have outstanding fines or a suspended membership.</li>
                        <li>A book that another member has reserved cannot be renewed.</li>
                    </ul>
                </section>

                <?php if ($is_suspended): ?>
                    <div class="alert alert-danger">Your membership is suspended, so renewals are unavailable. Please contact the librarian.</div>
                <?php elseif ($has_fines): ?>
                    <div class="alert alert-warning">You have outstanding fines of RM <?php echo number_format((float)$member['total_fine_amount'], 2); ?>. Please pay them before renewing books.</div>
                <?php endif; ?>

                <section class="current-books">
                    <div class="table-responsive">
                        <table class="table table-hover text-center">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
                                    <th>Author</th>
                                    <th>Due Date</th>
                                    <th>Renewals Used</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($loans) > 0): ?>
                                    <?php foreach ($loans as $loan):
                                        $days_late  = (int)$loan['days_late'];
                                        $is_overdue = $days_late > 0;
                                        $used       = (int)$loan['renewal_count'];

                                        if ($is_overdue) {
                                            $badge_class = 'danger';
                                            $badge_text  = 'Overdue';
                                        } elseif ($days_late >= -2) {
                                            $badge_class = 'info';
                                            $badge_text  = 'Due Soon';
                                        } else {
                                            $badge_class = 'warning';
                                            $badge_text  = 'Active';
                                        }

                                        // Why can't this loan be renewed? (empty = it can)
                                        $reason = '';
                                        if ($is_suspended)            { $reason = 'Membership suspended'; }
                                        elseif ($has_fines)           { $reason = 'Pay fines first'; }
                                        elseif ($is_overdue)          { $reason = 'Overdue – please return'; }
                                        elseif ($used >= MAX_RENEWALS){ $reason = 'Renewal limit reached'; }
                                        elseif ((int)$loan['waiting_cnt'] > 0) { $reason = 'Reserved by another member'; }
                                    ?>
                                        <tr>
                                            <td><strong><?php echo e($loan['title']); ?></strong></td>
                                            <td><?php echo e($loan['author']); ?></td>
                                            <td><?php echo fmt_date($loan['due_date']); ?></td>
                                            <td><?php echo $used; ?> / <?php echo MAX_RENEWALS; ?></td>
                                            <td><span class="badge badge-<?php echo $badge_class; ?>"><?php echo $badge_text; ?></span></td>
                                            <td>
                                                <?php if ($reason === ''): ?>
                                                    <form method="post" action="renew_books.php" class="m-0"
                                                          onsubmit="return confirm('Renew this book until <?php echo e(fmt_date($loan['new_due_date'])); ?>?');">
                                                        <input type="hidden" name="loan_id" value="<?php echo (int)$loan['loan_id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                        <button type="submit" class="btn btn-renew"
                                                                title="New due date: <?php echo e(fmt_date($loan['new_due_date'])); ?>">
                                                            Renew +<?php echo RENEWAL_DAYS; ?> days
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="not-allowed"><?php echo e($reason); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-muted py-4">You have no borrowed books to renew.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <div class="mt-4">
                <a href="member_dashboard.php" class="btn btn-member">← Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>
