<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Library Management System - Home</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="bootstrap-4.4.1/css/bootstrap.min.css">
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
        .features {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            margin-top: 40px;
        }
        .feature-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 30px;
            margin: 15px;
            flex: 1 1 250px;
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
            font-size: 1.5rem;
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
                        <a class="nav-link" href="about.php">About Library</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                    <?php if(isset($_SESSION['user_id'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="member_dashboard.php">Dashboard</a>
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
            <p>Your gateway to knowledge, learning, and discovery. Explore thousands of books, access digital resources, and join our vibrant community.</p>
            <a href="book_catalog.php" class="btn btn-primary">Browse Catalog</a>
        </section>

        <section class="features">
            <div class="feature-box">
                <div class="feature-icon">📚</div>
                <div class="feature-title">Extensive Collection</div>
                <div class="feature-desc">Thousands of books across various genres and subjects, updated regularly.</div>
            </div>
            <div class="feature-box">
                <div class="feature-icon">🕒</div>
                <div class="feature-title">Flexible Hours</div>
                <div class="feature-desc">Open daily from 8:00 AM to 9:00 PM to fit your schedule.</div>
            </div>
            <div class="feature-box">
                <div class="feature-icon">💻</div>
                <div class="feature-title">Digital Access</div>
                <div class="feature-desc">Access e-books, journals, and online resources anytime, anywhere.</div>
            </div>
            <div class="feature-box">
                <div class="feature-icon">👥</div>
                <div class="feature-title">Community Events</div>
                <div class="feature-desc">Join workshops, reading groups, and educational programs.</div>
            </div>
        </section>

        <footer>
            &copy; <?php echo date("Y"); ?> Library Management System. All rights reserved.
        </footer>
    </div>
</body>
</html>
