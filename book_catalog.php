<?php
session_start();
include('config.php');

// ---------- Access control ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'member' || !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

const BOOKS_PER_PAGE = 9;

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// ---------- Read & sanitise filters ----------
$q            = trim($_GET['q'] ?? '');
$category     = trim($_GET['category'] ?? '');
$availability = (($_GET['availability'] ?? '') === 'available') ? 'available' : 'all';

$sort_options = [
    'title'     => ['label' => 'Title (A–Z)',        'sql' => 'b.title ASC'],
    'title_desc'=> ['label' => 'Title (Z–A)',        'sql' => 'b.title DESC'],
    'author'    => ['label' => 'Author (A–Z)',       'sql' => 'b.author ASC, b.title ASC'],
    'newest'    => ['label' => 'Newest published',   'sql' => 'b.publication_year DESC, b.title ASC'],
    'available' => ['label' => 'Most copies available', 'sql' => 'b.available_copies DESC, b.title ASC'],
];
$sort = $_GET['sort'] ?? 'title';
if (!array_key_exists($sort, $sort_options)) {
    $sort = 'title';
}
$page = max(1, (int)($_GET['page'] ?? 1));

// ---------- Build WHERE clause (values are always bound, never concatenated) ----------
$where  = ["b.status = 'active'"];
$types  = '';
$params = [];

if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.publisher LIKE ?)";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($category !== '') {
    $where[] = "b.category = ?";
    $types  .= 's';
    $params[] = $category;
}
if ($availability === 'available') {
    $where[] = "b.available_copies > 0";
}
$where_sql = implode(' AND ', $where);

// ---------- Total count (for pagination) ----------
$stmt = $connection->prepare("SELECT COUNT(*) AS total FROM books b WHERE $where_sql");
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_books = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_books / BOOKS_PER_PAGE));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * BOOKS_PER_PAGE;

// ---------- Books for this page ----------
$order_sql = $sort_options[$sort]['sql'];
$stmt = $connection->prepare("
    SELECT b.book_id, b.isbn, b.title, b.author, b.publisher, b.publication_year,
           b.category, b.total_copies, b.available_copies, b.location
    FROM books b
    WHERE $where_sql
    ORDER BY $order_sql
    LIMIT ? OFFSET ?
");
$list_types  = $types . 'ii';
$list_params = array_merge($params, [BOOKS_PER_PAGE, $offset]);
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Category list for the dropdown ----------
$categories = [];
$cat_result = $connection->query("SELECT DISTINCT category FROM books WHERE status = 'active' ORDER BY category ASC");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row['category'];
}

// ---------- Helpers ----------
function page_url($p) {
    $query = [
        'q'            => $_GET['q'] ?? '',
        'category'     => $_GET['category'] ?? '',
        'availability' => $_GET['availability'] ?? '',
        'sort'         => $_GET['sort'] ?? '',
        'page'         => $p,
    ];
    return 'book_catalog.php?' . http_build_query(array_filter($query, function ($v) { return $v !== '' && $v !== null; }));
}

$showing_from = $total_books === 0 ? 0 : $offset + 1;
$showing_to   = min($offset + BOOKS_PER_PAGE, $total_books);
$has_filters  = ($q !== '' || $category !== '' || $availability !== 'all');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Browse Catalog - Library Management System</title>
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

        /* Filter bar */
        .filter-bar {
            background: #f8f9fa;
            padding: 25px 30px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            margin-bottom: 25px;
        }

        .filter-bar label {
            font-weight: 600;
            color: #4364f7;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .filter-bar .form-control {
            border-radius: 10px;
            border: 1px solid #ced4da;
        }

        .filter-bar .form-control:focus {
            border-color: #4364f7;
            box-shadow: 0 0 0 0.2rem rgba(67, 100, 247, 0.25);
        }

        .btn-member {
            background: #4364f7;
            border: none;
            color: white;
            padding: 10px 24px;
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

        .btn-reset {
            color: #4364f7;
            font-weight: 600;
            margin-left: 12px;
        }

        .result-summary {
            color: #555;
            margin-bottom: 20px;
            font-size: 1rem;
        }

        /* Book cards */
        .book-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .book-card {
            background: #f8f9fa;
            padding: 25px 25px 20px;
            border-radius: 15px;
            border-left: 8px solid #4364f7;
            box-shadow: 0 6px 15px rgba(67, 100, 247, 0.15);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .book-card:hover {
            box-shadow: 0 12px 30px rgba(67, 100, 247, 0.3);
            transform: translateY(-4px);
        }

        .book-card.unavailable {
            border-left-color: #dc3545;
        }

        .book-card h5 {
            color: #4364f7;
            font-weight: 700;
            font-size: 1.25rem;
            margin-bottom: 4px;
        }

        .book-card .author {
            color: #555;
            font-size: 1rem;
            margin-bottom: 14px;
        }

        .book-meta {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 4px;
        }

        .book-meta strong {
            color: #444;
        }

        .book-footer {
            margin-top: auto;
            padding-top: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 15px;
            color: #666;
        }

        .empty-state h5 {
            color: #4364f7;
            font-weight: 700;
        }

        /* Pagination */
        .pagination {
            justify-content: center;
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
                    <li class="nav-item dropdown active">
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
            <h3 class="page-title">Browse Catalog</h3>
            <p class="page-subtitle">Search the library collection and check which books are available.</p>

            <!-- Search & Filters -->
            <form class="filter-bar" method="get" action="book_catalog.php">
                <div class="form-row">
                    <div class="form-group col-lg-4 col-md-12">
                        <label for="q">Search</label>
                        <input type="text" class="form-control" id="q" name="q" maxlength="100"
                               placeholder="Title, author, ISBN or publisher"
                               value="<?php echo e($q); ?>">
                    </div>
                    <div class="form-group col-lg-2 col-md-4">
                        <label for="category">Category</label>
                        <select class="form-control" id="category" name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo e($cat); ?>" <?php echo $cat === $category ? 'selected' : ''; ?>>
                                    <?php echo e($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-lg-2 col-md-4">
                        <label for="availability">Availability</label>
                        <select class="form-control" id="availability" name="availability">
                            <option value="all" <?php echo $availability === 'all' ? 'selected' : ''; ?>>All books</option>
                            <option value="available" <?php echo $availability === 'available' ? 'selected' : ''; ?>>Available now</option>
                        </select>
                    </div>
                    <div class="form-group col-lg-2 col-md-4">
                        <label for="sort">Sort by</label>
                        <select class="form-control" id="sort" name="sort">
                            <?php foreach ($sort_options as $key => $opt): ?>
                                <option value="<?php echo e($key); ?>" <?php echo $key === $sort ? 'selected' : ''; ?>>
                                    <?php echo e($opt['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-lg-2 col-md-12 d-flex align-items-end">
                        <button type="submit" class="btn btn-member">Search</button>
                        <?php if ($has_filters): ?>
                            <a href="book_catalog.php" class="btn-reset">Reset</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- Results -->
            <?php if ($total_books > 0): ?>
                <p class="result-summary">
                    Showing <strong><?php echo $showing_from; ?>–<?php echo $showing_to; ?></strong>
                    of <strong><?php echo $total_books; ?></strong> book<?php echo $total_books === 1 ? '' : 's'; ?>
                    <?php if ($q !== ''): ?> for “<?php echo e($q); ?>”<?php endif; ?>
                </p>

                <section class="book-grid">
                    <?php foreach ($books as $book):
                        $available = (int)$book['available_copies'];
                        $total     = (int)$book['total_copies'];
                        $year      = (int)$book['publication_year'];
                    ?>
                        <article class="book-card <?php echo $available > 0 ? '' : 'unavailable'; ?>">
                            <h5><?php echo e($book['title']); ?></h5>
                            <div class="author">by <?php echo e($book['author']); ?></div>

                            <div class="book-meta"><strong>ISBN:</strong> <?php echo e($book['isbn']); ?></div>
                            <?php if (!empty($book['publisher'])): ?>
                                <div class="book-meta"><strong>Publisher:</strong> <?php echo e($book['publisher']); ?></div>
                            <?php endif; ?>
                            <?php if ($year > 0): ?>
                                <div class="book-meta"><strong>Year:</strong> <?php echo $year; ?></div>
                            <?php endif; ?>
                            <?php if (!empty($book['location'])): ?>
                                <div class="book-meta"><strong>Shelf:</strong> <?php echo e($book['location']); ?></div>
                            <?php endif; ?>

                            <div class="book-footer">
                                <span class="badge badge-info"><?php echo e($book['category']); ?></span>
                                <?php if ($available > 0): ?>
                                    <span class="badge badge-success"><?php echo $available; ?> of <?php echo $total; ?> available</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Unavailable</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <!-- Pagination -->
                <?php if ($total_pages > 1):
                    $start = max(1, $page - 2);
                    $end   = min($total_pages, $page + 2);
                ?>
                    <nav aria-label="Catalog pages">
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo e(page_url(max(1, $page - 1))); ?>">Previous</a>
                            </li>
                            <?php if ($start > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo e(page_url(1)); ?>">1</a></li>
                                <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo e(page_url($i)); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($end < $total_pages): ?>
                                <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?php echo e(page_url($total_pages)); ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo e(page_url(min($total_pages, $page + 1))); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <h5>No books found</h5>
                    <p>Try a different keyword, or clear the filters to see the whole collection.</p>
                    <a href="book_catalog.php" class="btn btn-member">Show all books</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>