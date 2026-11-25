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

// ---------- Lending rules ----------
const LOAN_DAYS = 14; // default loan period in days

class RuleException extends Exception {}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date($date) {
    $ts = strtotime((string)$date);
    return $ts ? date('M d, Y', $ts) : '-';
}

$librarian_id = (int)$_SESSION['user_id'];

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------- Optional tables ----------
$tbl = $connection->query("SHOW TABLES LIKE 'reservations'");
$reservations_ready = ($tbl && $tbl->num_rows > 0);

// ---------- Flash message ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Handle "issue book" ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = ['type' => 'danger', 'text' => 'Something went wrong. Please try again.'];

    $member_number = trim($_POST['member_number'] ?? '');
    $book_key      = trim($_POST['book_key'] ?? '');

    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        $result['text'] = 'Your session expired. Please try again.';
    } elseif ($member_number === '' || $book_key === '' || mb_strlen($member_number) > 20 || mb_strlen($book_key) > 20) {
        $result['text'] = 'Please enter both a member number and a book ISBN or ID.';
    } else {
        try {
            $connection->begin_transaction();

            // --- Member (locked so two librarians cannot issue to the same member at once) ---
            $stmt = $connection->prepare("
                SELECT m.member_id, m.member_number, m.member_status, m.max_books_allowed, m.total_fine_amount,
                       u.first_name, u.last_name
                FROM members m
                JOIN users u ON m.user_id = u.user_id
                WHERE m.member_number = ?
                FOR UPDATE
            ");
            $stmt->bind_param("s", $member_number);
            $stmt->execute();
            $member = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$member) {
                throw new RuleException("Member number “" . $member_number . "” was not found.");
            }
            $member_id = (int)$member['member_id'];
            $member_name = $member['first_name'] . ' ' . $member['last_name'];

            if (strtolower($member['member_status']) !== 'active') {
                throw new RuleException($member_name . "'s membership is " . strtolower($member['member_status']) . ". Books cannot be issued.");
            }

            // --- Book (locked, so the copy count cannot change under us) ---
            $book_id_guess = ctype_digit($book_key) ? (int)$book_key : 0;
            $stmt = $connection->prepare("
                SELECT book_id, title, available_copies
                FROM books
                WHERE (isbn = ? OR book_id = ?) AND status = 'active'
                FOR UPDATE
            ");
            $stmt->bind_param("si", $book_key, $book_id_guess);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$book) {
                throw new RuleException("No active book matches “" . $book_key . "”.");
            }
            $book_id   = (int)$book['book_id'];
            $available = (int)$book['available_copies'];

            if ($available <= 0) {
                throw new RuleException("“" . $book['title'] . "” has no copies available. The member can reserve it.");
            }

            // --- Member's current situation ---
            $stmt = $connection->prepare("
                SELECT COUNT(*) AS on_loan,
                       COALESCE(SUM(due_date < CURDATE()), 0) AS overdue,
                       COALESCE(SUM(book_id = ?), 0) AS has_this_book
                FROM loan_records
                WHERE member_id = ? AND return_date IS NULL
            ");
            $stmt->bind_param("ii", $book_id, $member_id);
            $stmt->execute();
            $situation = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$situation['has_this_book'] > 0) {
                throw new RuleException($member_name . " is already borrowing this book.");
            }
            if ((int)$situation['overdue'] > 0) {
                throw new RuleException($member_name . " has overdue books. They must be returned first.");
            }
            if ((int)$situation['on_loan'] >= (int)$member['max_books_allowed']) {
                throw new RuleException($member_name . " has reached the limit of " . (int)$member['max_books_allowed'] . " books.");
            }
            if ((float)$member['total_fine_amount'] > 0) {
                throw new RuleException($member_name . " has unpaid fines of RM " . number_format((float)$member['total_fine_amount'], 2) . ".");
            }

            // --- Reservation queue ---
            $reservation_id = null;
            if ($reservations_ready) {
                $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM reservations WHERE book_id = ? AND status = 'waiting'");
                $stmt->bind_param("i", $book_id);
                $stmt->execute();
                $total_waiting = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();

                $stmt = $connection->prepare("
                    SELECT r.reservation_id,
                           (SELECT COUNT(*) FROM reservations r2
                            WHERE r2.book_id = r.book_id AND r2.status = 'waiting' AND r2.reservation_id <= r.reservation_id) AS pos
                    FROM reservations r
                    WHERE r.member_id = ? AND r.book_id = ? AND r.status = 'waiting'
                    LIMIT 1
                ");
                $stmt->bind_param("ii", $member_id, $book_id);
                $stmt->execute();
                $my_res = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($my_res) {
                    if ((int)$my_res['pos'] > $available) {
                        throw new RuleException("Other members are ahead of " . $member_name . " in the reservation queue for this book.");
                    }
                    $reservation_id = (int)$my_res['reservation_id'];
                } elseif ($available <= $total_waiting) {
                    throw new RuleException("The remaining copies of “" . $book['title'] . "” are held for members in the reservation queue.");
                }
            }

            // --- Everything is fine: create the loan and update the counts ---
            $days = LOAN_DAYS;
            $stmt = $connection->prepare("
                INSERT INTO loan_records (member_id, book_id, librarian_id, issue_date, due_date, status, fine_amount)
                VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL $days DAY), 'active', 0)
            ");
            $stmt->bind_param("iii", $member_id, $book_id, $librarian_id);
            $stmt->execute();
            $loan_id = $connection->insert_id;
            $stmt->close();

            $stmt = $connection->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ? AND available_copies > 0");
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuleException("The last copy was just taken. Please try again.");
            }
            $stmt->close();

            $stmt = $connection->prepare("UPDATE members SET current_books_issued = current_books_issued + 1 WHERE member_id = ?");
            $stmt->bind_param("i", $member_id);
            $stmt->execute();
            $stmt->close();

            if ($reservation_id !== null) {
                $stmt = $connection->prepare("UPDATE reservations SET status = 'fulfilled' WHERE reservation_id = ?");
                $stmt->bind_param("i", $reservation_id);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $connection->prepare("SELECT due_date FROM loan_records WHERE loan_id = ?");
            $stmt->bind_param("i", $loan_id);
            $stmt->execute();
            $due = $stmt->get_result()->fetch_assoc()['due_date'];
            $stmt->close();

            $connection->commit();
            $result = [
                'type' => 'success',
                'text' => '“' . $book['title'] . '” issued to ' . $member_name . ' (' . $member['member_number'] . '). Due date: ' . fmt_date($due) . '.'
                          . ($reservation_id !== null ? ' Their reservation was marked as fulfilled.' : '')
            ];
        } catch (RuleException $ex) {
            $connection->rollback();
            $result['text'] = $ex->getMessage();
        } catch (Throwable $ex) {
            $connection->rollback();
            error_log("Issue book failed: " . $ex->getMessage());
        }
    }

    $_SESSION['flash'] = $result;
    header("Location: issue_book.php");
    exit();
}

// ---------- Recent loans (so the librarian can see what was just issued) ----------
$stmt = $connection->prepare("
    SELECT lr.loan_id, lr.issue_date, lr.due_date, lr.return_date, b.title, m.member_number, u.first_name, u.last_name
    FROM loan_records lr
    JOIN books b ON lr.book_id = b.book_id
    JOIN members m ON lr.member_id = m.member_id
    JOIN users u ON m.user_id = u.user_id
    ORDER BY lr.loan_id DESC
    LIMIT 10
");
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Issue Book - Library Management System</title>
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

        .form-card {
            background: rgba(248, 249, 250, 0.9);
            padding: 25px;
            border-radius: 10px;
            border-left: 5px solid #28a745;
            margin-bottom: 30px;
        }

        .form-card h5 {
            color: #28a745;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .form-card label {
            font-weight: 600;
            color: #28a745;
            font-size: 0.9rem;
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

        .rules-box {
            background: rgba(248, 249, 250, 0.9);
            padding: 18px 25px;
            border-radius: 10px;
            border-left: 5px solid #20c997;
            margin-bottom: 25px;
            color: #555;
        }

        .rules-box ul {
            margin-bottom: 0;
            padding-left: 20px;
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
                    <li class="nav-item active"><a class="nav-link" href="issue_book.php">Issue Book</a></li>
                    <li class="nav-item"><a class="nav-link" href="return_book.php">Return Book</a></li>
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
            <h2 class="mb-4">Issue Book</h2>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <form class="form-card" method="post" action="issue_book.php" autocomplete="off">
                <h5>New Loan</h5>
                <div class="form-row">
                    <div class="form-group col-md-5">
                        <label for="member_number">Member Number</label>
                        <input type="text" class="form-control" id="member_number" name="member_number"
                               maxlength="20" placeholder="e.g. LIB2025001" required autofocus>
                    </div>
                    <div class="form-group col-md-5">
                        <label for="book_key">Book ISBN or Book ID</label>
                        <input type="text" class="form-control" id="book_key" name="book_key"
                               maxlength="20" placeholder="e.g. 978-0-670-81302-8 or 5" required>
                    </div>
                    <div class="form-group col-md-2 d-flex align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                        <button type="submit" class="btn btn-librarian btn-block">Issue</button>
                    </div>
                </div>
            </form>

            <div class="rules-box">
                <strong>Lending rules</strong>
                <ul class="mt-2">
                    <li>Loan period is <?php echo LOAN_DAYS; ?> days from today.</li>
                    <li>The member must be active, within their book limit, with no overdue books and no unpaid fines.</li>
                    <li>The book must have a copy available and must not be held for the reservation queue.</li>
                </ul>
            </div>

            <div class="daily-tasks">
                <h5 class="mb-3">Latest Loans</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Issued</th>
                                <th>Member</th>
                                <th>Book Title</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent) > 0): ?>
                                <?php foreach ($recent as $r): ?>
                                    <tr>
                                        <td><?php echo fmt_date($r['issue_date']); ?></td>
                                        <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?><br><small class="text-muted"><?php echo e($r['member_number']); ?></small></td>
                                        <td><?php echo e($r['title']); ?></td>
                                        <td><?php echo fmt_date($r['due_date']); ?></td>
                                        <td>
                                            <?php if (!empty($r['return_date'])): ?>
                                                <span class="badge badge-success">Returned</span>
                                            <?php elseif (strtotime($r['due_date']) < strtotime('today')): ?>
                                                <span class="badge badge-danger">Overdue</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Active</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted">No loans yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
