<?php
session_start();
include('config.php');

// ---------- Access control (librarian or admin) ----------
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['librarian', 'admin'], true) || !isset($_SESSION['user_id'])) {
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

// ---------- Fine rule (same rate the member pages use) ----------
const FINE_PER_DAY = 1.00; // RM per day overdue

class RuleException extends Exception {}

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

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Optional tables ----------
$tbl = $connection->query("SHOW TABLES LIKE 'reservations'");
$reservations_ready = ($tbl && $tbl->num_rows > 0);
$tbl = $connection->query("SHOW TABLES LIKE 'payments'");
$payments_ready = ($tbl && $tbl->num_rows > 0);

// ---------- Flash message ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Handle "return book" ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = ['type' => 'danger', 'text' => 'Something went wrong. Please try again.'];

    $loan_id = (int)($_POST['loan_id'] ?? 0);
    $waive   = isset($_POST['waive']) && $_POST['waive'] === '1';

    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        $result['text'] = 'Your session expired. Please try again.';
    } else {
        try {
            $connection->begin_transaction();

            // Lock the loan row so it cannot be returned twice
            $stmt = $connection->prepare("
                SELECT lr.loan_id, lr.member_id, lr.book_id, lr.return_date,
                       DATEDIFF(CURDATE(), lr.due_date) AS days_late,
                       b.title, m.member_number, u.first_name, u.last_name
                FROM loan_records lr
                JOIN books b ON lr.book_id = b.book_id
                JOIN members m ON lr.member_id = m.member_id
                JOIN users u ON m.user_id = u.user_id
                WHERE lr.loan_id = ?
                FOR UPDATE
            ");
            $stmt->bind_param("i", $loan_id);
            $stmt->execute();
            $loan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                throw new RuleException("That loan could not be found.");
            }
            if (!empty($loan['return_date'])) {
                throw new RuleException("This book has already been returned.");
            }

            $member_id = (int)$loan['member_id'];
            $book_id   = (int)$loan['book_id'];
            $days_late = max(0, (int)$loan['days_late']);
            $fine      = $waive ? 0.0 : $days_late * FINE_PER_DAY;

            // Close the loan and record the fine
            $stmt = $connection->prepare("
                UPDATE loan_records
                SET return_date = CURDATE(), status = 'returned', fine_amount = ?
                WHERE loan_id = ? AND return_date IS NULL
            ");
            $stmt->bind_param("di", $fine, $loan_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuleException("This book has already been returned.");
            }
            $stmt->close();

            // Put the copy back on the shelf
            $stmt = $connection->prepare("UPDATE books SET available_copies = LEAST(available_copies + 1, total_copies) WHERE book_id = ?");
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $stmt->close();

            // Member's counters
            $stmt = $connection->prepare("UPDATE members SET current_books_issued = GREATEST(current_books_issued - 1, 0) WHERE member_id = ?");
            $stmt->bind_param("i", $member_id);
            $stmt->execute();
            $stmt->close();

            // Keep members.total_fine_amount equal to the unpaid recorded fines
            if ($payments_ready) {
                $sync_sql = "
                    UPDATE members
                    SET total_fine_amount = (
                        SELECT COALESCE(SUM(GREATEST(lr.fine_amount - COALESCE(
                                   (SELECT SUM(p.amount) FROM payments p WHERE p.loan_id = lr.loan_id), 0), 0)), 0)
                        FROM loan_records lr WHERE lr.member_id = ?
                    )
                    WHERE member_id = ?";
            } else {
                $sync_sql = "
                    UPDATE members
                    SET total_fine_amount = (
                        SELECT COALESCE(SUM(lr.fine_amount), 0) FROM loan_records lr WHERE lr.member_id = ?
                    )
                    WHERE member_id = ?";
            }
            $stmt = $connection->prepare($sync_sql);
            $stmt->bind_param("ii", $member_id, $member_id);
            $stmt->execute();
            $stmt->close();

            // Is anyone waiting for this book?
            $waiting = 0;
            if ($reservations_ready) {
                $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM reservations WHERE book_id = ? AND status = 'waiting'");
                $stmt->bind_param("i", $book_id);
                $stmt->execute();
                $waiting = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
            }

            $connection->commit();

            $text = '“' . $loan['title'] . '” returned by ' . $loan['first_name'] . ' ' . $loan['last_name'] . ' (' . $loan['member_number'] . ').';
            if ($days_late > 0 && !$waive) {
                $text .= ' Overdue by ' . $days_late . ' day' . ($days_late === 1 ? '' : 's') . ': fine of ' . money($fine) . ' recorded.';
            } elseif ($days_late > 0 && $waive) {
                $text .= ' Overdue by ' . $days_late . ' day' . ($days_late === 1 ? '' : 's') . ': fine waived.';
            }
            if ($waiting > 0) {
                $text .= ' ' . $waiting . ' member' . ($waiting === 1 ? ' is' : 's are') . ' waiting for this book.';
            }
            $result = ['type' => 'success', 'text' => $text];
        } catch (RuleException $ex) {
            $connection->rollback();
            $result['text'] = $ex->getMessage();
        } catch (Throwable $ex) {
            $connection->rollback();
            error_log("Return book failed: " . $ex->getMessage());
        }
    }

    $_SESSION['flash'] = $result;
    $back = 'return_book.php' . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : '');
    header("Location: " . $back);
    exit();
}

// ---------- Search & list of books that are still out ----------
$q = trim($_GET['q'] ?? '');

$where  = "lr.return_date IS NULL";
$types  = '';
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= " AND (m.member_number LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR b.title LIKE ? OR b.isbn LIKE ?)";
    $types  = 'ssss';
    $params = [$like, $like, $like, $like];
}

$stmt = $connection->prepare("
    SELECT lr.loan_id, lr.issue_date, lr.due_date,
           DATEDIFF(CURDATE(), lr.due_date) AS days_late,
           b.title, b.isbn, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    WHERE $where
    ORDER BY lr.due_date ASC
    LIMIT 50
");
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$loans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary of everything currently out
$summary = $connection->query("
    SELECT COUNT(*) AS out_cnt, COALESCE(SUM(due_date < CURDATE()), 0) AS overdue_cnt
    FROM loan_records WHERE return_date IS NULL
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Return Book - Library Management System</title>
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

        .search-card {
            background: rgba(248, 249, 250, 0.9);
            padding: 20px 25px;
            border-radius: 10px;
            border-left: 5px solid #28a745;
            margin-bottom: 25px;
        }

        .form-control {
            border-radius: 8px;
            padding: 10px 15px;
        }

        .btn-librarian {
            background: #28a745;
            border: none;
            color: white;
            padding: 10px 24px;
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

        .table td {
            vertical-align: middle;
        }

        .waive-label {
            font-size: 0.8rem;
            color: #666;
            display: block;
            margin-bottom: 6px;
        }

        .alert {
            border-radius: 8px;
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
                    <li class="nav-item"><a class="nav-link" href="librarian_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="issue_book.php">Issue Book</a></li>
                    <li class="nav-item active"><a class="nav-link" href="return_book.php">Return Book</a></li>
                    <li class="nav-item"><a class="nav-link" href="book_management.php">Manage Books</a></li>
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
            <h2 class="mb-4">Return Book</h2>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <div class="stats-row">
                <div class="stat-card">
                    <h3><?php echo (int)$summary['out_cnt']; ?></h3>
                    <p>Books Currently Out</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$summary['overdue_cnt']; ?></h3>
                    <p>Overdue</p>
                </div>
            </div>

            <form class="search-card" method="get" action="return_book.php">
                <div class="form-row">
                    <div class="col-md-9 mb-2 mb-md-0">
                        <input type="text" class="form-control" name="q" maxlength="100"
                               placeholder="Search by member number, member name, book title or ISBN"
                               value="<?php echo e($q); ?>">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-librarian">Search</button>
                        <?php if ($q !== ''): ?>
                            <a href="return_book.php" class="btn btn-secondary">Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="daily-tasks">
                <h5 class="mb-3">Books Not Yet Returned</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Book Title</th>
                                <th>Issued</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Fine If Returned Today</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($loans) > 0): ?>
                                <?php foreach ($loans as $loan):
                                    $days_late  = (int)$loan['days_late'];
                                    $is_overdue = $days_late > 0;
                                    $fine       = $is_overdue ? $days_late * FINE_PER_DAY : 0;
                                ?>
                                    <tr>
                                        <td><?php echo e($loan['first_name'] . ' ' . $loan['last_name']); ?><br><small class="text-muted"><?php echo e($loan['member_number']); ?></small></td>
                                        <td><?php echo e($loan['title']); ?><br><small class="text-muted"><?php echo e($loan['isbn']); ?></small></td>
                                        <td><?php echo fmt_date($loan['issue_date']); ?></td>
                                        <td><?php echo fmt_date($loan['due_date']); ?></td>
                                        <td>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge badge-danger">Overdue <?php echo $days_late; ?>d</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">On loan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $fine > 0 ? money($fine) : '-'; ?></td>
                                        <td>
                                            <form method="post" action="return_book.php" class="m-0"
                                                  onsubmit="return confirm('Mark this book as returned?');">
                                                <input type="hidden" name="loan_id" value="<?php echo (int)$loan['loan_id']; ?>">
                                                <input type="hidden" name="q" value="<?php echo e($q); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                <?php if ($is_overdue): ?>
                                                    <label class="waive-label">
                                                        <input type="checkbox" name="waive" value="1"> Waive fine
                                                    </label>
                                                <?php endif; ?>
                                                <button type="submit" class="btn btn-sm btn-librarian">Return</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted">
                                    <?php echo $q !== '' ? 'No outstanding loans match your search.' : 'All books have been returned.'; ?>
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($loans) === 50): ?>
                    <p class="text-muted mb-0">Showing the first 50 results. Use the search box to narrow them down.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
