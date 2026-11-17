<?php
session_start();
include('config.php');

// Check if user is logged in as member
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member') {
    header("Location: login.php");
    exit();
}

// Get member information safely
$member_id = (int)$_SESSION['user_id'];
$member_query = mysqli_query($connection, "
    SELECT m.*, u.first_name, u.last_name, u.email 
    FROM members m 
    JOIN users u ON m.user_id = u.user_id 
    WHERE u.user_id = $member_id
");
$member = mysqli_fetch_assoc($member_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Member Dashboard - Library Management System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="bootstrap-4.4.1/css/bootstrap.min.css" />
    <script src="bootstrap-4.4.1/js/jquery_latest.js"></script>
    <script src="bootstrap-4.4.1/js/bootstrap.min.js"></script>
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
                            <a class="dropdown-item" href="fines_fees.php">Fines & Fees</a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="help_support.php">Help</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
                            <a class="dropdown-item" href="member_profile.php">My Profile</a>
                            <a class="dropdown-item" href="notification_settings.php">Notifications</a>
                            <a class="dropdown-item" href="change_password.php">Change Password</a>
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
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></p>
                        <p><strong>Member Number:</strong> <?php echo htmlspecialchars($member['member_number']); ?></p>
                        <p><strong>Membership Type:</strong> <?php echo ucfirst(htmlspecialchars($member['membership_type'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <h4>Account Status</h4>
                        <p><strong>Join Date:</strong> <?php echo date('M d, Y', strtotime($member['join_date'])); ?></p>
                        <p><strong>Status:</strong> <span class="badge badge-success"><?php echo ucfirst(htmlspecialchars($member['member_status'])); ?></span></p>
                        <p><strong>Total Fines:</strong> RM <?php echo number_format($member['total_fine_amount'], 2); ?></p>
                    </div>
                </div>
            </section>

            <!-- Statistics -->
            <section class="stats-row">
                <?php
                $current_books = mysqli_query($connection, "SELECT COUNT(*) as total FROM loan_records WHERE member_id = $member_id AND status = 'active'");
                $total_borrowed = mysqli_query($connection, "SELECT COUNT(*) as total FROM loan_records WHERE member_id = $member_id");
                $overdue_books = mysqli_query($connection, "SELECT COUNT(*) as total FROM loan_records WHERE member_id = $member_id AND status = 'overdue'");

                $current_count = mysqli_fetch_assoc($current_books)['total'];
                $total_count = mysqli_fetch_assoc($total_borrowed)['total'];
                $overdue_count = mysqli_fetch_assoc($overdue_books)['total'];
                $available_slots = $member['max_books_allowed'] - $current_count;
                ?>
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
                            <?php
                            $current_loans = mysqli_query($connection, "
                                                               SELECT lr.*, b.title, b.author
                                FROM loan_records lr
                                JOIN books b ON lr.book_id = b.book_id
                                WHERE lr.member_id = $member_id AND lr.status IN ('active', 'overdue')
                                ORDER BY lr.due_date ASC
                            ");

                            if (mysqli_num_rows($current_loans) > 0) {
                                while ($loan = mysqli_fetch_assoc($current_loans)) {
                                    $is_overdue = (strtotime($loan['due_date']) < time());
                                    $days_overdue = $is_overdue ? floor((time() - strtotime($loan['due_date'])) / 86400) : 0;
                                    $fine = $days_overdue * 1.00; // RM 1 per day

                                    echo "<tr>";
                                    echo "<td><strong>" . htmlspecialchars($loan['title']) . "</strong></td>";
                                    echo "<td>" . htmlspecialchars($loan['author']) . "</td>";
                                    echo "<td>" . date('M d, Y', strtotime($loan['issue_date'])) . "</td>";
                                    echo "<td>" . date('M d, Y', strtotime($loan['due_date'])) . "</td>";
                                    echo "<td><span class='badge badge-" . ($is_overdue ? 'danger' : 'warning') . "'>" . ($is_overdue ? 'Overdue' : 'Active') . "</span></td>";
                                    echo "<td>" . ($fine > 0 ? 'RM ' . number_format($fine, 2) : '-') . "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='6' class='text-center text-muted'>No books currently borrowed</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
