<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Rules shown here match the ones used by the other pages
$faq = [
    [
        'q' => 'How do I borrow a book?',
        'a' => 'Find the book in the catalog, note its shelf location, and take it to the librarian at the front desk. The librarian records the loan for you, and it will then appear on your dashboard.',
    ],
    [
        'q' => 'How many books can I borrow at once?',
        'a' => 'Your limit depends on your membership type and is shown on your dashboard as "Available Slots". Overdue books still count towards your limit.',
    ],
    [
        'q' => 'How do I renew a book?',
        'a' => 'Open Renew Books from the My Books menu. Each book can be renewed up to 2 times, adding 14 days each time. Overdue books, books reserved by another member, and accounts with unpaid fines cannot be renewed.',
    ],
    [
        'q' => 'What if the book I want is already borrowed?',
        'a' => 'Open Reserve Books and join the queue. You can hold up to 3 reservations at a time and cancel any of them. When the book is returned and you are first in line, your reservation shows as "Ready".',
    ],
    [
        'q' => 'How are fines calculated?',
        'a' => 'Fines are RM 1.00 for each day a book is overdue. The final fine is recorded by the librarian when the book is returned, and you can then pay it under My Account → Fines & Fees.',
    ],
    [
        'q' => 'How do I change my details or password?',
        'a' => 'Click your name at the top right and choose My Profile or Change Password.',
    ],
    [
        'q' => 'My membership shows as suspended. What now?',
        'a' => 'Suspended members cannot borrow, renew or reserve books. Please speak to the librarian at the front desk.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Help - Library Management System</title>
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

        /* FAQ */
        .faq-item {
            background: #f8f9fa;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            margin-bottom: 18px;
            overflow: hidden;
        }

        .faq-question {
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 18px 25px;
            font-weight: 700;
            font-size: 1.1rem;
            color: #4364f7;
        }

        .faq-question:focus {
            outline: none;
            box-shadow: none;
        }

        .faq-answer {
            padding: 0 25px 20px;
            color: #555;
            font-size: 1rem;
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
                    <li class="nav-item active">
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
            <h3 class="page-title">Help &amp; Support</h3>
            <p class="page-subtitle">Answers to common questions about borrowing, renewing, reserving and fines.</p>

            <div id="faq">
                <?php foreach ($faq as $i => $item): ?>
                    <div class="faq-item">
                        <button class="faq-question" type="button" data-toggle="collapse"
                                data-target="#faq<?php echo $i; ?>" aria-expanded="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                            <?php echo e($item['q']); ?>
                        </button>
                        <div id="faq<?php echo $i; ?>" class="collapse <?php echo $i === 0 ? 'show' : ''; ?>" data-parent="#faq">
                            <div class="faq-answer"><?php echo e($item['a']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="mt-4 mb-4 text-muted">Still need help? Please ask the librarian at the front desk.</p>
            <a href="member_dashboard.php" class="btn btn-member">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
