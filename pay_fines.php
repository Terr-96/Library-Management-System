<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

const FINE_PER_DAY = 1.00; // RM per day, only used to estimate fines on books not yet returned

$payment_methods = [
    'online_banking' => 'Online Banking (FPX)',
    'card'           => 'Credit / Debit Card',
    'ewallet'        => 'E-Wallet',
];

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

$user_id = (int)$_SESSION['user_id'];

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Member record (loan_records.member_id -> members.member_id) ----------
$stmt = $connection->prepare("SELECT member_id FROM members WHERE user_id = ?");
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

// ---------- Is the payments table installed? ----------
$tbl = $connection->query("SHOW TABLES LIKE 'payments'");
$table_ready = ($tbl && $tbl->num_rows > 0);

// ---------- Flash message ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Fines that are recorded on a loan and not fully paid yet ----------
function get_outstanding($connection, $member_id) {
    $stmt = $connection->prepare("
        SELECT lr.loan_id, lr.due_date, lr.return_date, b.title,
               lr.fine_amount,
               COALESCE(pp.paid, 0) AS paid,
               ROUND(lr.fine_amount - COALESCE(pp.paid, 0), 2) AS due_amount
        FROM loan_records lr
        JOIN books b ON lr.book_id = b.book_id
        LEFT JOIN (SELECT loan_id, SUM(amount) AS paid FROM payments GROUP BY loan_id) pp
               ON pp.loan_id = lr.loan_id
        WHERE lr.member_id = ?
          AND lr.fine_amount - COALESCE(pp.paid, 0) > 0
        ORDER BY lr.due_date ASC
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ---------- Handle a payment ----------
if ($table_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST['csrf_token'] ?? '';
    $method = $_POST['method'] ?? '';
    $target = $_POST['loan_id'] ?? '';
    $result = ['type' => 'danger', 'text' => 'Something went wrong. Please try again.'];

    if (!hash_equals($csrf_token, (string)$token)) {
        $result['text'] = 'Your session expired. Please try again.';
    } elseif (!array_key_exists($method, $payment_methods)) {
        $result['text'] = 'Please choose a payment method.';
    } elseif ($target !== 'all' && !ctype_digit((string)$target)) {
        $result['text'] = 'Invalid payment request.';
    } else {
        try {
            $connection->begin_transaction();

            // Lock this member's row so two payments cannot run at the same time
            $stmt = $connection->prepare("SELECT member_id FROM members WHERE member_id = ? FOR UPDATE");
            $stmt->bind_param("i", $member_id);
            $stmt->execute();
            $stmt->close();

            $rows = get_outstanding($connection, $member_id);
            if ($target !== 'all') {
                $rows = array_values(array_filter($rows, function ($r) use ($target) {
                    return (int)$r['loan_id'] === (int)$target;
                }));
            }

            if (count($rows) === 0) {
                $connection->rollback();
                $result['text'] = 'There is nothing to pay for this selection.';
            } else {
                $reference = 'PAY' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $total = 0.0;

                $ins = $connection->prepare("INSERT INTO payments (member_id, loan_id, amount, method, reference) VALUES (?, ?, ?, ?, ?)");
                foreach ($rows as $r) {
                    $loan_id = (int)$r['loan_id'];
                    $amount  = (float)$r['due_amount'];
                    $ins->bind_param("iidss", $member_id, $loan_id, $amount, $method, $reference);
                    $ins->execute();
                    $total += $amount;
                }
                $ins->close();

                // Keep members.total_fine_amount equal to what is still unpaid
                $stmt = $connection->prepare("
                    UPDATE members
                    SET total_fine_amount = (
                        SELECT COALESCE(SUM(GREATEST(lr.fine_amount - COALESCE(
                                   (SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = lr.loan_id), 0), 0)), 0)
                        FROM loan_records lr
                        WHERE lr.member_id = ?
                    )
                    WHERE member_id = ?
                ");
                $stmt->bind_param("ii", $member_id, $member_id);
                $stmt->execute();
                $stmt->close();

                $connection->commit();
                $result = [
                    'type' => 'success',
                    'text' => 'Payment of ' . money($total) . ' received via ' . $payment_methods[$method] . '. Reference: ' . $reference . '.'
                ];
            }
        } catch (Throwable $ex) {
            $connection->rollback();
            error_log("Fine payment failed: " . $ex->getMessage());
        }
    }

    $_SESSION['flash'] = $result;
    header("Location: pay_fines.php");
    exit();
}

// ---------- Data for the page ----------
$outstanding   = [];
$history       = [];
$total_due     = 0.0;
$total_paid    = 0.0;
$payment_count = 0;
$accruing      = [];

if ($table_ready) {
    $outstanding = get_outstanding($connection, $member_id);
    foreach ($outstanding as $o) {
        $total_due += (float)$o['due_amount'];
    }

    // Payment history
    $stmt = $connection->prepare("
        SELECT p.payment_id, p.amount, p.method, p.reference, p.paid_at, b.title
        FROM payments p
        JOIN loan_records lr ON p.loan_id = lr.loan_id
        JOIN books b ON lr.book_id = b.book_id
        WHERE p.member_id = ?
        ORDER BY p.paid_at DESC, p.payment_id DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Totals over the whole history
    $stmt = $connection->prepare("SELECT COALESCE(SUM(amount), 0) AS paid, COUNT(DISTINCT reference) AS cnt FROM payments WHERE member_id = ?");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $sum = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total_paid    = (float)$sum['paid'];
    $payment_count = (int)$sum['cnt'];
}

// Books that are overdue and not yet returned: the fine is still growing
$stmt = $connection->prepare("
    SELECT b.title, lr.due_date, DATEDIFF(CURDATE(), lr.due_date) AS days_late
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    WHERE lr.member_id = ? AND lr.return_date IS NULL AND lr.due_date < CURDATE()
    ORDER BY lr.due_date ASC
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$accruing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Pay Fines - Library Management System</title>
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

        .section-title {
            color: #4364f7;
            font-weight: 700;
            margin-bottom: 18px;
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
            font-size: 2.2rem;
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

        /* Info box */
        .info-box {
            background: #f8f9fa;
            padding: 22px 30px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            margin-bottom: 30px;
            color: #555;
        }

        .info-box h5 {
            color: #4364f7;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .info-box p {
            margin-bottom: 6px;
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

        .method-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .method-bar label {
            font-weight: 600;
            color: #4364f7;
            margin: 0;
        }

        .method-bar .form-control {
            border-radius: 10px;
            max-width: 280px;
        }

        /* Table boxes */
        .table-box {
            background: #f8f9fa;
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            margin-bottom: 40px;
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

        .small-note {
            color: #777;
            font-size: 0.85rem;
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
                    <li class="nav-item dropdown active">
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
            <h3 class="page-title">Fines &amp; Payments</h3>
            <p class="page-subtitle">See what you owe, pay your fines and review your payment history.</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <?php if (!$table_ready): ?>
                <div class="alert alert-warning">
                    <strong>One-time setup needed.</strong> The <code>payments</code> table does not exist yet.
                    Run this in phpMyAdmin (SQL tab), then reload this page:
<pre class="mb-0 mt-2">CREATE TABLE payments (
  payment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  loan_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('online_banking','card','ewallet') NOT NULL,
  reference VARCHAR(30) NOT NULL,
  paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_member (member_id),
  KEY idx_loan (loan_id),
  UNIQUE KEY uq_reference_loan (reference, loan_id),
  FOREIGN KEY (member_id) REFERENCES members(member_id),
  FOREIGN KEY (loan_id) REFERENCES loan_records(loan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
                </div>
            <?php else: ?>

                <!-- Summary -->
                <section class="stats-row">
                    <div class="stat-card" title="Fines recorded on your loans that are not paid yet">
                        <h3><?php echo money($total_due); ?></h3>
                        <p>Outstanding</p>
                    </div>
                    <div class="stat-card" title="Everything you have paid so far">
                        <h3><?php echo money($total_paid); ?></h3>
                        <p>Total Paid</p>
                    </div>
                    <div class="stat-card" title="Number of payments you have made">
                        <h3><?php echo $payment_count; ?></h3>
                        <p>Payments Made</p>
                    </div>
                </section>

                <section class="info-box">
                    <h5>Demo payment</h5>
                    <p>This is a demonstration payment page for the Library Management System. No real money is charged and no card details are collected.</p>
                </section>

                <!-- Outstanding fines -->
                <h4 class="section-title">Outstanding Fines</h4>
                <section class="table-box">
                    <?php if (count($outstanding) > 0): ?>
                        <form method="post" action="pay_fines.php" onsubmit="return confirm('Proceed with this demo payment?');">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">

                            <div class="method-bar">
                                <label for="method">Payment method</label>
                                <select class="form-control" id="method" name="method" required>
                                    <?php foreach ($payment_methods as $key => $label): ?>
                                        <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="loan_id" value="all" class="btn btn-member">
                                    Pay all (<?php echo money($total_due); ?>)
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover text-center">
                                    <thead>
                                        <tr>
                                            <th>Book Title</th>
                                            <th>Due Date</th>
                                            <th>Returned</th>
                                            <th>Fine</th>
                                            <th>Paid</th>
                                            <th>Balance</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($outstanding as $o): ?>
                                            <tr>
                                                <td><strong><?php echo e($o['title']); ?></strong></td>
                                                <td><?php echo fmt_date($o['due_date']); ?></td>
                                                <td><?php echo !empty($o['return_date']) ? fmt_date($o['return_date']) : '<span class="badge badge-danger">Not returned</span>'; ?></td>
                                                <td><?php echo money($o['fine_amount']); ?></td>
                                                <td><?php echo money($o['paid']); ?></td>
                                                <td><strong><?php echo money($o['due_amount']); ?></strong></td>
                                                <td>
                                                    <button type="submit" name="loan_id" value="<?php echo (int)$o['loan_id']; ?>" class="btn btn-renew">Pay</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="text-center text-muted my-4">You have no outstanding fines. 🎉</p>
                    <?php endif; ?>
                </section>

                <!-- Fines still growing -->
                <?php if (count($accruing) > 0): ?>
                    <h4 class="section-title">Overdue Books Not Yet Returned</h4>
                    <section class="table-box">
                        <p class="small-note">These books are past their due date, so the fine keeps growing (RM <?php echo number_format(FINE_PER_DAY, 2); ?> per day). The final fine is recorded by the librarian when the book is returned, and can then be paid here.</p>
                        <div class="table-responsive">
                            <table class="table table-hover text-center">
                                <thead>
                                    <tr>
                                        <th>Book Title</th>
                                        <th>Due Date</th>
                                        <th>Days Overdue</th>
                                        <th>Estimated Fine So Far</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($accruing as $a): ?>
                                        <tr>
                                            <td><strong><?php echo e($a['title']); ?></strong></td>
                                            <td><?php echo fmt_date($a['due_date']); ?></td>
                                            <td><span class="badge badge-danger"><?php echo (int)$a['days_late']; ?> days</span></td>
                                            <td><?php echo money((int)$a['days_late'] * FINE_PER_DAY); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Payment history -->
                <h4 class="section-title" id="payment-history">Payment History</h4>
                <section class="table-box">
                    <div class="table-responsive">
                        <table class="table table-hover text-center">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reference</th>
                                    <th>Book</th>
                                    <th>Method</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($history) > 0): ?>
                                    <?php foreach ($history as $h): ?>
                                        <tr>
                                            <td><?php echo fmt_date($h['paid_at']); ?></td>
                                            <td><span class="badge badge-info"><?php echo e($h['reference']); ?></span></td>
                                            <td><?php echo e($h['title']); ?></td>
                                            <td><?php echo e($payment_methods[$h['method']] ?? $h['method']); ?></td>
                                            <td><strong><?php echo money($h['amount']); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-muted py-4">You have not made any payments yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <a href="member_dashboard.php" class="btn btn-member">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
