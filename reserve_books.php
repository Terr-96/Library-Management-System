<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ---------- Reservation rules ----------
const MAX_ACTIVE_RESERVATIONS = 3; // waiting reservations one member can hold at once

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

// ---------- Member record (reservations.member_id -> members.member_id) ----------
$stmt = $connection->prepare("SELECT member_id, member_status FROM members WHERE user_id = ?");
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

// ---------- Is the reservations table installed? ----------
$tbl = $connection->query("SHOW TABLES LIKE 'reservations'");
$table_ready = ($tbl && $tbl->num_rows > 0);

// ---------- Flash message ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Handle POST actions ----------
if ($table_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token  = $_POST['csrf_token'] ?? '';
    $result = ['type' => 'danger', 'text' => 'Something went wrong. Please try again.'];

    if (!hash_equals($csrf_token, (string)$token)) {
        $result['text'] = 'Your session expired. Please try again.';

    } elseif ($action === 'reserve') {
        $book_id = (int)($_POST['book_id'] ?? 0);

        // Load the book
        $stmt = $connection->prepare("SELECT title, available_copies FROM books WHERE book_id = ? AND status = 'active'");
        $stmt->bind_param("i", $book_id);
        $stmt->execute();
        $book = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // How many waiting reservations does this member already have?
        $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM reservations WHERE member_id = ? AND status = 'waiting'");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $my_waiting = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        // Does the member already hold this book?
        $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM loan_records WHERE member_id = ? AND book_id = ? AND return_date IS NULL");
        $stmt->bind_param("ii", $member_id, $book_id);
        $stmt->execute();
        $i_have = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        if ($is_suspended) {
            $result['text'] = 'Your membership is suspended, so you cannot reserve books.';
        } elseif (!$book) {
            $result['text'] = 'That book could not be found.';
        } elseif ((int)$book['available_copies'] > 0) {
            $result['text'] = 'This book is available now. Please ask the librarian to borrow it for you.';
        } elseif ($i_have > 0) {
            $result['text'] = 'You are already borrowing this book.';
        } elseif ($my_waiting >= MAX_ACTIVE_RESERVATIONS) {
            $result['text'] = 'You can only have ' . MAX_ACTIVE_RESERVATIONS . ' active reservations at a time.';
        } else {
            // The NOT EXISTS keeps a double-click from creating two reservations
            $stmt = $connection->prepare("
                INSERT INTO reservations (member_id, book_id)
                SELECT ?, ? FROM DUAL
                WHERE NOT EXISTS (
                    SELECT 1 FROM reservations
                    WHERE member_id = ? AND book_id = ? AND status = 'waiting'
                )
            ");
            $stmt->bind_param("iiii", $member_id, $book_id, $member_id, $book_id);
            $stmt->execute();
            $created = ($stmt->affected_rows === 1);
            $stmt->close();

            if ($created) {
                $result = ['type' => 'success', 'text' => '“' . $book['title'] . '” has been reserved. You will be in the queue until it is available.'];
            } else {
                $result['text'] = 'You have already reserved this book.';
            }
        }

    } elseif ($action === 'cancel') {
        $reservation_id = (int)($_POST['reservation_id'] ?? 0);
        $stmt = $connection->prepare("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ? AND member_id = ? AND status = 'waiting'");
        $stmt->bind_param("ii", $reservation_id, $member_id);
        $stmt->execute();
        $cancelled = ($stmt->affected_rows === 1);
        $stmt->close();

        $result = $cancelled
            ? ['type' => 'success', 'text' => 'Your reservation has been cancelled.']
            : ['type' => 'danger',  'text' => 'That reservation could not be cancelled.'];
    }

    $_SESSION['flash'] = $result;
    header("Location: reserve_books.php");
    exit();
}

// ---------- Data for the page ----------
$unavailable = [];
$my_reservations = [];
$my_waiting_count = 0;

if ($table_ready) {
    // Books with no copies on the shelf
    $stmt = $connection->prepare("
        SELECT b.book_id, b.title, b.author, b.category,
               (SELECT COUNT(*) FROM reservations rw WHERE rw.book_id = b.book_id AND rw.status = 'waiting') AS queue_len,
               (SELECT COUNT(*) FROM reservations rm WHERE rm.book_id = b.book_id AND rm.member_id = ? AND rm.status = 'waiting') AS my_waiting,
               (SELECT COUNT(*) FROM loan_records lm WHERE lm.book_id = b.book_id AND lm.member_id = ? AND lm.return_date IS NULL) AS i_have
        FROM books b
        WHERE b.status = 'active' AND b.available_copies <= 0
        ORDER BY b.title ASC
        LIMIT 100
    ");
    $stmt->bind_param("ii", $member_id, $member_id);
    $stmt->execute();
    $unavailable = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // This member's reservations (waiting first, then history)
    $stmt = $connection->prepare("
        SELECT r.reservation_id, r.reserved_at, r.status, b.title, b.author, b.available_copies,
               (SELECT COUNT(*) FROM reservations r2
                WHERE r2.book_id = r.book_id AND r2.status = 'waiting' AND r2.reservation_id <= r.reservation_id) AS queue_pos
        FROM reservations r
        JOIN books b ON r.book_id = b.book_id
        WHERE r.member_id = ?
        ORDER BY (r.status = 'waiting') DESC, r.reserved_at DESC
        LIMIT 30
    ");
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $my_reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($my_reservations as $r) {
        if ($r['status'] === 'waiting') {
            $my_waiting_count++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reserve Books - Library Management System</title>
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

        /* Rules box */
        .rules-box {
            background: #f8f9fa;
            padding: 22px 30px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            margin-bottom: 35px;
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

        .btn-cancel {
            background: #fff;
            border: 1px solid #dc3545;
            color: #dc3545;
            padding: 8px 18px;
            border-radius: 10px;
            font-weight: 600;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .btn-cancel:hover {
            background: #dc3545;
            color: #fff;
        }

        .not-allowed {
            color: #888;
            font-size: 0.85rem;
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

        .badge-secondary {
            background: #6c757d;
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
            <h3 class="page-title">Reserve Books</h3>
            <p class="page-subtitle">Join the queue for books that are currently out, and manage your reservations.</p>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <?php if (!$table_ready): ?>
                <div class="alert alert-warning">
                    <strong>One-time setup needed.</strong> The <code>reservations</code> table does not exist yet.
                    Run this in phpMyAdmin (SQL tab), then reload this page:
<pre class="mb-0 mt-2">CREATE TABLE reservations (
  reservation_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  book_id INT NOT NULL,
  reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('waiting','cancelled','fulfilled') NOT NULL DEFAULT 'waiting',
  KEY idx_book_status (book_id, status),
  KEY idx_member (member_id),
  FOREIGN KEY (member_id) REFERENCES members(member_id),
  FOREIGN KEY (book_id) REFERENCES books(book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
                </div>
            <?php else: ?>

                <section class="rules-box">
                    <h5>Reservation rules</h5>
                    <ul>
                        <li>You can reserve books that currently have no copies on the shelf. Available books can be borrowed at the desk.</li>
                        <li>You can hold up to <strong><?php echo MAX_ACTIVE_RESERVATIONS; ?></strong> active reservations (you have <strong><?php echo $my_waiting_count; ?></strong>).</li>
                        <li>Reservations are served in the order they were made. A book with a waiting reservation cannot be renewed by its current borrower.</li>
                    </ul>
                </section>

                <?php if ($is_suspended): ?>
                    <div class="alert alert-danger">Your membership is suspended, so reservations are unavailable. Please contact the librarian.</div>
                <?php endif; ?>

                <!-- Books that can be reserved -->
                <h4 class="section-title">Books Currently Unavailable</h4>
                <section class="table-box">
                    <div class="table-responsive">
                        <table class="table table-hover text-center">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
                                    <th>Author</th>
                                    <th>Category</th>
                                    <th>Waiting</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($unavailable) > 0): ?>
                                    <?php foreach ($unavailable as $b): ?>
                                        <tr>
                                            <td><strong><?php echo e($b['title']); ?></strong></td>
                                            <td><?php echo e($b['author']); ?></td>
                                            <td><span class="badge badge-info"><?php echo e($b['category']); ?></span></td>
                                            <td><?php echo (int)$b['queue_len']; ?></td>
                                            <td>
                                                <?php if ((int)$b['my_waiting'] > 0): ?>
                                                    <span class="badge badge-success">Reserved by you</span>
                                                <?php elseif ((int)$b['i_have'] > 0): ?>
                                                    <span class="not-allowed">You have this book</span>
                                                <?php elseif ($is_suspended): ?>
                                                    <span class="not-allowed">Membership suspended</span>
                                                <?php elseif ($my_waiting_count >= MAX_ACTIVE_RESERVATIONS): ?>
                                                    <span class="not-allowed">Reservation limit reached</span>
                                                <?php else: ?>
                                                    <form method="post" action="reserve_books.php" class="m-0"
                                                          onsubmit="return confirm('Reserve this book?');">
                                                        <input type="hidden" name="action" value="reserve">
                                                        <input type="hidden" name="book_id" value="<?php echo (int)$b['book_id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                        <button type="submit" class="btn btn-renew">Reserve</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-muted py-4">Every book is available right now. Nothing needs to be reserved.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- My reservations -->
                <h4 class="section-title" id="my-reservations">My Reservations</h4>
                <section class="table-box">
                    <div class="table-responsive">
                        <table class="table table-hover text-center">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
                                    <th>Author</th>
                                    <th>Reserved On</th>
                                    <th>Queue Position</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($my_reservations) > 0): ?>
                                    <?php foreach ($my_reservations as $r):
                                        $waiting = ($r['status'] === 'waiting');
                                        $ready   = $waiting && (int)$r['queue_pos'] === 1 && (int)$r['available_copies'] > 0;
                                    ?>
                                        <tr>
                                            <td><strong><?php echo e($r['title']); ?></strong></td>
                                            <td><?php echo e($r['author']); ?></td>
                                            <td><?php echo fmt_date($r['reserved_at']); ?></td>
                                            <td><?php echo $waiting ? '#' . (int)$r['queue_pos'] : '-'; ?></td>
                                            <td>
                                                <?php if ($ready): ?>
                                                    <span class="badge badge-success">Ready – see the librarian</span>
                                                <?php elseif ($waiting): ?>
                                                    <span class="badge badge-warning">Waiting</span>
                                                <?php elseif ($r['status'] === 'fulfilled'): ?>
                                                    <span class="badge badge-success">Fulfilled</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Cancelled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($waiting): ?>
                                                    <form method="post" action="reserve_books.php" class="m-0"
                                                          onsubmit="return confirm('Cancel this reservation?');">
                                                        <input type="hidden" name="action" value="cancel">
                                                        <input type="hidden" name="reservation_id" value="<?php echo (int)$r['reservation_id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                        <button type="submit" class="btn btn-cancel">Cancel</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="not-allowed">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-muted py-4">You have no reservations yet.</td>
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
