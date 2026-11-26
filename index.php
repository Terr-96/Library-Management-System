<?php
session_start();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Who is looking at the page?
$dashboards = [
    'admin'     => 'admin_dashboard.php',
    'librarian' => 'librarian_dashboard.php',
    'member'    => 'member_dashboard.php',
];
$role         = $_SESSION['role'] ?? '';
$logged_in    = isset($_SESSION['user_id']) && isset($dashboards[$role]);
$dashboard    = $logged_in ? $dashboards[$role] : '';
$is_member    = $logged_in && $role === 'member';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Library Management System - Home</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <style type="text/css">
        html {
            scroll-behavior: smooth;
        }

        body {
            background-image: url("https://images.unsplash.com/photo-1481627834876-b7833e8f5570?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80");
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #343a40;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            margin: 50px auto;
            max-width: 1000px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 40px;
        }

        .navbar {
            background: rgba(52, 58, 64, 0.9);
            padding: 15px 30px;
            border-radius: 15px 15px 0 0;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.8rem;
            color: #fff !important;
        }

        .nav-link {
            color: #ddd !important;
            font-weight: 500;
        }

        .nav-link:hover {
            color: #007bff !important;
        }

        .hero-section {
            text-align: center;
            padding: 60px 20px;
        }

        .hero-section h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: #007bff;
        }

        .hero-section p {
            font-size: 1.25rem;
            color: #555;
            max-width: 700px;
            margin: 0 auto 40px;
        }

        .btn-primary {
            background: #007bff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: background 0.3s ease;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-outline-primary {
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .section-title {
            text-align: center;
            font-weight: 700;
            color: #343a40;
            margin-top: 40px;
            scroll-margin-top: 20px;
        }

        .about-text {
            max-width: 750px;
            margin: 15px auto 0;
            text-align: center;
            color: #555;
            font-size: 1.1rem;
        }

        .features {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .feature-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 30px;
            margin: 15px;
            flex: 1 1 220px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .feature-box:hover {
            transform: translateY(-10px);
        }

        .feature-icon {
            font-size: 50px;
            color: #007bff;
            margin-bottom: 20px;
        }

        .feature-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #343a40;
        }

        .feature-desc {
            font-size: 1rem;
            color: #6c757d;
        }

        footer {
            text-align: center;
            padding: 20px 0;
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 60px;
            border-top: 1px solid #dee2e6;
        }

        @media (max-width: 768px) {
            .features {
                flex-direction: column;
                align-items: center;
            }
            .feature-box {
                max-width: 90%;
            }
            .hero-section h1 {
                font-size: 2.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand" href="index.php">Library Management System</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item active">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#services">Services</a>
                    </li>
                    <?php if ($logged_in): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo e($dashboard); ?>">Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">Logout</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link btn btn-outline-light ml-2 px-3" href="login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-outline-light ml-2 px-3" href="signup.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <section class="hero-section">
            <h1>Welcome to Our Library</h1>
            <p>Search the collection, renew and reserve books, and manage your fines, all in one place.</p>
            <?php if ($is_member): ?>
                <a href="book_catalog.php" class="btn btn-primary">Browse Catalog</a>
                <a href="<?php echo e($dashboard); ?>" class="btn btn-outline-primary ml-2">My Dashboard</a>
            <?php elseif ($logged_in): ?>
                <a href="<?php echo e($dashboard); ?>" class="btn btn-primary">Go to Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-primary">Login to Browse the Catalog</a>
                <a href="signup.php" class="btn btn-outline-primary ml-2">Register</a>
            <?php endif; ?>
        </section>

        <section id="about">
            <h3 class="section-title">About</h3>
            <p class="about-text">
                This Library Management System helps a library run its day-to-day work. Members search the catalog,
                renew and reserve books and pay fines online. Librarians record loans and returns, and administrators
                keep an eye on the whole system.
            </p>
        </section>

        <section id="services">
            <h3 class="section-title">Services</h3>
            <div class="features">
                <div class="feature-box">
                    <div class="feature-icon">📚</div>
                    <div class="feature-title">Search the Catalog</div>
                    <div class="feature-desc">Find books by title, author, ISBN or category, and see at a glance which ones are available.</div>
                </div>
                <div class="feature-box">
                    <div class="feature-icon">🔄</div>
                    <div class="feature-title">Renew &amp; Reserve</div>
                    <div class="feature-desc">Extend your due dates online, or join the queue for a book that is currently out.</div>
                </div>
                <div class="feature-box">
                    <div class="feature-icon">💳</div>
                    <div class="feature-title">Pay Fines Online</div>
                    <div class="feature-desc">See what you owe and settle your fines, with a full payment history.</div>
                </div>
                <div class="feature-box">
                    <div class="feature-icon">📋</div>
                    <div class="feature-title">Track Your Loans</div>
                    <div class="feature-desc">Check what you have borrowed, what is due soon and your complete borrowing history.</div>
                </div>
            </div>
        </section>

        <footer>
            &copy; <?php echo date("Y"); ?> Library Management System. All rights reserved.
        </footer>
    </div>
</body>
</html>
