<?php
session_start();
include('config.php');

// ---------- Access control (admin or librarian) ----------
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'librarian'], true) || !isset($_SESSION['user_id'])) {
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

$dashboard_url = ($_SESSION['role'] === 'admin') ? 'admin_dashboard.php' : 'librarian_dashboard.php';

const BOOKS_PER_PAGE = 15;

class RuleException extends Exception {}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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

// ---------- Read and validate the add/edit form ----------
function read_book_form() {
    $fields = ['isbn', 'title', 'author', 'publisher', 'publication_year', 'category', 'total_copies', 'price', 'location'];
    $raw = [];
    foreach ($fields as $f) {
        $raw[$f] = trim((string)($_POST[$f] ?? ''));
    }

    $errors = [];
    if ($raw['isbn'] === '' || mb_strlen($raw['isbn']) > 20 || !preg_match('/^[0-9Xx\-\s]+$/', $raw['isbn'])) {
        $errors[] = "ISBN is required (digits and hyphens only, up to 20 characters).";
    }
    if ($raw['title'] === '' || mb_strlen($raw['title']) > 255) {
        $errors[] = "Title is required (max 255 characters).";
    }
    if ($raw['author'] === '' || mb_strlen($raw['author']) > 255) {
        $errors[] = "Author is required (max 255 characters).";
    }
    if (mb_strlen($raw['publisher']) > 100) {
        $errors[] = "Publisher can be at most 100 characters.";
    }
    if ($raw['category'] === '' || mb_strlen($raw['category']) > 50) {
        $errors[] = "Category is required (max 50 characters).";
    }
    if (mb_strlen($raw['location']) > 100) {
        $errors[] = "Shelf location can be at most 100 characters.";
    }

    $year = null;
    if ($raw['publication_year'] !== '') {
        if (!ctype_digit($raw['publication_year']) || (int)$raw['publication_year'] < 1901 || (int)$raw['publication_year'] > (int)date('Y') + 1) {
            $errors[] = "Year must be between 1901 and " . ((int)date('Y') + 1) . ".";
        } else {
            $year = (int)$raw['publication_year'];
        }
    }

    $total = 0;
    if (!ctype_digit($raw['total_copies']) || (int)$raw['total_copies'] < 1 || (int)$raw['total_copies'] > 1000) {
        $errors[] = "Total copies must be a whole number from 1 to 1000.";
    } else {
        $total = (int)$raw['total_copies'];
    }

    $price = null;
    if ($raw['price'] !== '') {
        if (!is_numeric($raw['price']) || (float)$raw['price'] < 0 || (float)$raw['price'] > 99999999.99) {
            $errors[] = "Price must be a positive number.";
        } else {
            $price = round((float)$raw['price'], 2);
        }
    }

    $data = [
        'isbn'             => $raw['isbn'],
        'title'            => $raw['title'],
        'author'           => $raw['author'],
        'publisher'        => $raw['publisher'] === '' ? null : $raw['publisher'],
        'publication_year' => $year,
        'category'         => $raw['category'],
        'total_copies'     => $total,
        'price'            => $price,
        'location'         => $raw['location'] === '' ? null : $raw['location'],
    ];
    return ['raw' => $raw, 'data' => $data, 'errors' => $errors];
}

// Rebuild a safe "back to the list" URL from the filters the user had
function back_url($back_string) {
    parse_str((string)$back_string, $b);
    $keep = [];
    foreach (['q', 'category', 'status', 'page'] as $k) {
        if (isset($b[$k]) && is_string($b[$k]) && $b[$k] !== '') {
            $keep[$k] = $b[$k];
        }
    }
    return 'book_management.php' . (count($keep) ? '?' . http_build_query($keep) : '');
}

// ---------- Form state ----------
$form_mode   = 'add';
$edit_id     = 0;
$form_errors = [];
$show_form   = isset($_GET['add']);
$form = ['isbn' => '', 'title' => '', 'author' => '', 'publisher' => '', 'publication_year' => '',
         'category' => '', 'total_copies' => '1', 'price' => '', 'location' => ''];

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $redirect = back_url($_POST['back'] ?? '');
    $result   = null;

    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        $result = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];

    } elseif ($action === 'add_book') {
        $parsed = read_book_form();
        $form = $parsed['raw'];
        $form_errors = $parsed['errors'];
        $show_form = true;

        if (empty($form_errors)) {
            $d = $parsed['data'];
            try {
                $stmt = $connection->prepare("SELECT book_id FROM books WHERE isbn = ?");
                $stmt->bind_param("s", $d['isbn']);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $form_errors[] = "A book with this ISBN already exists.";
                } else {
                    $available = $d['total_copies'];
                    $stmt = $connection->prepare("
                        INSERT INTO books (isbn, title, author, publisher, publication_year, category, total_copies, available_copies, location, price, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                    ");
                    // s s s s i s i i s d
                    $stmt->bind_param("ssssisiisd",
                        $d['isbn'], $d['title'], $d['author'], $d['publisher'], $d['publication_year'],
                        $d['category'], $d['total_copies'], $available, $d['location'], $d['price']
                    );
                    $stmt->execute();
                    $new_id = $connection->insert_id;
                    $stmt->close();

                    $result = ['type' => 'success', 'text' => 'Book added: “' . $d['title'] . '” (ID ' . $new_id . ').'];
                }
            } catch (mysqli_sql_exception $ex) {
                if ((int)$ex->getCode() === 1062) {
                    $form_errors[] = "A book with this ISBN already exists.";
                } else {
                    error_log("Add book failed: " . $ex->getMessage());
                    $form_errors[] = "Something went wrong. Please try again.";
                }
            }
        }

    } elseif ($action === 'edit_book') {
        $edit_id = (int)($_POST['book_id'] ?? 0);
        $parsed = read_book_form();
        $form = $parsed['raw'];
        $form_errors = $parsed['errors'];
        $form_mode = 'edit';
        $show_form = true;

        if (empty($form_errors)) {
            $d = $parsed['data'];
            try {
                $connection->begin_transaction();

                // Lock the book so loans cannot change while we recalculate copies
                $stmt = $connection->prepare("SELECT book_id FROM books WHERE book_id = ? FOR UPDATE");
                $stmt->bind_param("i", $edit_id);
                $stmt->execute();
                $found = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if (!$found) {
                    throw new RuleException("That book could not be found.");
                }

                $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM loan_records WHERE book_id = ? AND return_date IS NULL");
                $stmt->bind_param("i", $edit_id);
                $stmt->execute();
                $on_loan = (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();

                if ($d['total_copies'] < $on_loan) {
                    throw new RuleException("Total copies cannot be lower than the " . $on_loan . " cop" . ($on_loan === 1 ? "y" : "ies") . " currently on loan.");
                }

                $stmt = $connection->prepare("SELECT book_id FROM books WHERE isbn = ? AND book_id <> ?");
                $stmt->bind_param("si", $d['isbn'], $edit_id);
                $stmt->execute();
                $dup = $stmt->get_result()->num_rows > 0;
                $stmt->close();
                if ($dup) {
                    throw new RuleException("Another book already uses this ISBN.");
                }

                // Copies on the shelf are always total minus copies out
                $available = $d['total_copies'] - $on_loan;
                $stmt = $connection->prepare("
                    UPDATE books
                    SET isbn = ?, title = ?, author = ?, publisher = ?, publication_year = ?, category = ?,
                        total_copies = ?, available_copies = ?, location = ?, price = ?
                    WHERE book_id = ?
                ");
                // s s s s i s i i s d i
                $stmt->bind_param("ssssisiisdi",
                    $d['isbn'], $d['title'], $d['author'], $d['publisher'], $d['publication_year'],
                    $d['category'], $d['total_copies'], $available, $d['location'], $d['price'], $edit_id
                );
                $stmt->execute();
                $stmt->close();

                $connection->commit();
                $result = ['type' => 'success', 'text' => 'Book updated: “' . $d['title'] . '”.'];
            } catch (RuleException $ex) {
                $connection->rollback();
                $form_errors[] = $ex->getMessage();
            } catch (Throwable $ex) {
                $connection->rollback();
                error_log("Edit book failed: " . $ex->getMessage());
                $form_errors[] = "Something went wrong. Please try again.";
            }
        }

    } elseif ($action === 'set_status') {
        $book_id = (int)($_POST['book_id'] ?? 0);
        $to      = (($_POST['to'] ?? '') === 'active') ? 'active' : 'inactive';
        $stmt = $connection->prepare("UPDATE books SET status = ? WHERE book_id = ?");
        $stmt->bind_param("si", $to, $book_id);
        $stmt->execute();
        $stmt->close();
        $result = ['type' => 'success', 'text' => 'Book ' . ($to === 'active' ? 'activated' : 'deactivated') . '.'];

    } elseif ($action === 'delete_book') {
        $book_id = (int)($_POST['book_id'] ?? 0);
        try {
            $stmt = $connection->prepare("SELECT title FROM books WHERE book_id = ?");
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$book) {
                throw new RuleException("That book could not be found.");
            }

            $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM loan_records WHERE book_id = ?");
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $history = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            if ($reservations_ready) {
                $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM reservations WHERE book_id = ?");
                $stmt->bind_param("i", $book_id);
                $stmt->execute();
                $history += (int)$stmt->get_result()->fetch_assoc()['c'];
                $stmt->close();
            }

            if ($history > 0) {
                throw new RuleException("“" . $book['title'] . "” has loan or reservation history and cannot be deleted. Deactivate it instead.");
            }

            $stmt = $connection->prepare("DELETE FROM books WHERE book_id = ?");
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $stmt->close();
            $result = ['type' => 'success', 'text' => '“' . $book['title'] . '” was deleted.'];
        } catch (RuleException $ex) {
            $result = ['type' => 'danger', 'text' => $ex->getMessage()];
        } catch (Throwable $ex) {
            error_log("Delete book failed: " . $ex->getMessage());
            $result = ['type' => 'danger', 'text' => 'The book could not be deleted. Deactivate it instead.'];
        }
    }

    // After a finished action: go back to the list (so refreshing never repeats it)
    if ($result !== null) {
        $_SESSION['flash'] = $result;
        header("Location: " . $redirect);
        exit();
    }
}

// ---------- GET ?edit=ID: load the book into the form ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $connection->prepare("SELECT * FROM books WHERE book_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $form_mode = 'edit';
        $show_form = true;
        $form = [
            'isbn'             => $row['isbn'],
            'title'            => $row['title'],
            'author'           => $row['author'],
            'publisher'        => $row['publisher'] ?? '',
            'publication_year' => ((int)$row['publication_year'] > 0) ? $row['publication_year'] : '',
            'category'         => $row['category'],
            'total_copies'     => $row['total_copies'],
            'price'            => $row['price'] ?? '',
            'location'         => $row['location'] ?? '',
        ];
    } else {
        $edit_id = 0;
    }
}

// ---------- Filters for the list ----------
$q        = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$status   = $_GET['status'] ?? '';
if (!in_array($status, ['active', 'inactive'], true)) {
    $status = '';
}
$page = max(1, (int)($_GET['page'] ?? 1));

$where  = "1=1";
$types  = '';
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR publisher LIKE ?)";
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($category !== '') {
    $where .= " AND category = ?";
    $types .= 's';
    $params[] = $category;
}
if ($status !== '') {
    $where .= " AND status = ?";
    $types .= 's';
    $params[] = $status;
}

// ---------- Statistics ----------
$stats = $connection->query("
    SELECT COUNT(*) AS total_books,
           COALESCE(SUM(status = 'active'), 0) AS active_books,
           COALESCE(SUM(total_copies), 0) AS total_copies,
           COALESCE(SUM(available_copies), 0) AS available_copies
    FROM books
")->fetch_assoc();

// ---------- Count + page of books ----------
$stmt = $connection->prepare("SELECT COUNT(*) AS c FROM books WHERE $where");
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_rows = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$total_pages = max(1, (int)ceil($total_rows / BOOKS_PER_PAGE));
$page   = min($page, $total_pages);
$offset = ($page - 1) * BOOKS_PER_PAGE;

$stmt = $connection->prepare("SELECT * FROM books WHERE $where ORDER BY title ASC LIMIT ? OFFSET ?");
$list_types  = $types . 'ii';
$list_params = array_merge($params, [BOOKS_PER_PAGE, $offset]);
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Existing categories (for the filter and for the add/edit suggestions)
$categories = [];
$cat_result = $connection->query("SELECT DISTINCT category FROM books WHERE category <> '' ORDER BY category ASC");
while ($r = $cat_result->fetch_assoc()) {
    $categories[] = $r['category'];
}

function page_url($p) {
    $query = [
        'q'        => $_GET['q'] ?? '',
        'category' => $_GET['category'] ?? '',
        'status'   => $_GET['status'] ?? '',
        'page'     => $p,
    ];
    return 'book_management.php?' . http_build_query(array_filter($query, function ($v) { return $v !== '' && $v !== null; }));
}

$back_value   = $_SERVER['QUERY_STRING'] ?? '';
$has_filters  = ($q !== '' || $category !== '' || $status !== '');
$show_search  = $has_filters;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Book Management - Library System</title>
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
            background: rgba(90, 141, 191, 0.9);
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
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-card p {
            margin: 0;
        }

        .form-section {
            background: rgba(248, 249, 250, 0.8);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: none;
        }

        .form-section.show {
            display: block;
        }

        .form-section label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #0056b3;
            margin-bottom: 3px;
        }

        .form-control {
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 10px;
        }

        .btn-primary {
            background: #007bff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
        }

        .table {
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }

        .table th {
            background: #007bff;
            color: white;
            font-weight: 600;
            border: none;
        }

        .table td {
            vertical-align: middle;
        }

        .badge-active {
            background: #28a745;
            color: #fff;
        }

        .badge-inactive {
            background: #dc3545;
            color: #fff;
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .alert ul {
            margin-bottom: 0;
            padding-left: 20px;
            text-align: left;
        }

        .row-actions form {
            display: inline;
        }

        .pagination {
            justify-content: center;
        }

        .page-link {
            border-radius: 8px;
            margin: 0 3px;
        }

        .small-meta {
            color: #777;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="book_management.php">Book Management System</a>
            <div class="navbar-nav ml-auto">
                <a class="nav-link" href="<?php echo e($dashboard_url); ?>">Dashboard</a>
                <a class="nav-link" href="issue_book.php">Issue Book</a>
                <a class="nav-link" href="return_book.php">Return Book</a>
                <a class="nav-link active" href="book_management.php">Books</a>
                <a class="nav-link" href="logout.php">Logout (<?php echo e($_SESSION['name'] ?? ''); ?>)</a>
            </div>
        </nav>

        <div class="content">
            <h2 class="mb-4">Book Management</h2>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['text']); ?></div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-row">
                <div class="stat-card">
                    <h3><?php echo (int)$stats['total_books']; ?></h3>
                    <p>Total Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$stats['active_books']; ?></h3>
                    <p>Active Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$stats['total_copies']; ?></h3>
                    <p>Total Copies</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo (int)$stats['available_copies']; ?></h3>
                    <p>Available Copies</p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mb-3">
                <a class="btn btn-primary" href="book_management.php?add=1#book-form">Add New Book</a>
                <button class="btn btn-secondary" type="button" onclick="toggleSearch()">Search Books</button>
            </div>

            <!-- Search Form -->
            <div class="form-section <?php echo $show_search ? 'show' : ''; ?>" id="searchForm">
                <h5>Search Books</h5>
                <form method="GET" action="book_management.php">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" name="q" class="form-control" placeholder="Search title, author, ISBN or publisher"
                                   maxlength="100" value="<?php echo e($q); ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-control">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo e($cat); ?>" <?php echo $cat === $category ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">Any status</option>
                                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Search</button>
                            <a href="book_management.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Add / Edit Book Form -->
            <div class="form-section <?php echo $show_form ? 'show' : ''; ?>" id="book-form">
                <h5><?php echo $form_mode === 'edit' ? 'Edit Book #' . (int)$edit_id : 'Add New Book'; ?></h5>

                <?php if (!empty($form_errors)): ?>
                    <div class="alert alert-danger">
                        <ul>
                            <?php foreach ($form_errors as $err): ?>
                                <li><?php echo e($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="book_management.php" method="post" autocomplete="off">
                    <div class="row">
                        <div class="col-md-6">
                            <label for="title">Title *</label>
                            <input type="text" id="title" name="title" class="form-control" maxlength="255" required
                                   value="<?php echo e($form['title']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="author">Author *</label>
                            <input type="text" id="author" name="author" class="form-control" maxlength="255" required
                                   value="<?php echo e($form['author']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <label for="isbn">ISBN *</label>
                            <input type="text" id="isbn" name="isbn" class="form-control" maxlength="20" required
                                   value="<?php echo e($form['isbn']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="category">Category *</label>
                            <input type="text" id="category" name="category" class="form-control" maxlength="50" required
                                   list="categoryList" placeholder="Pick one or type a new one"
                                   value="<?php echo e($form['category']); ?>">
                            <datalist id="categoryList">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo e($cat); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label for="publication_year">Year</label>
                            <input type="number" id="publication_year" name="publication_year" class="form-control"
                                   min="1901" max="<?php echo (int)date('Y') + 1; ?>"
                                   value="<?php echo e($form['publication_year']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <label for="publisher">Publisher</label>
                            <input type="text" id="publisher" name="publisher" class="form-control" maxlength="100"
                                   value="<?php echo e($form['publisher']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="total_copies">Total Copies *</label>
                            <input type="number" id="total_copies" name="total_copies" class="form-control" min="1" max="1000" required
                                   value="<?php echo e($form['total_copies']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="price">Price (RM)</label>
                            <input type="number" id="price" name="price" class="form-control" step="0.01" min="0"
                                   value="<?php echo e($form['price']); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <label for="location">Shelf Location</label>
                            <input type="text" id="location" name="location" class="form-control" maxlength="100"
                                   placeholder="e.g., A-01-15" value="<?php echo e($form['location']); ?>">
                        </div>
                    </div>

                    <input type="hidden" name="action" value="<?php echo $form_mode === 'edit' ? 'edit_book' : 'add_book'; ?>">
                    <?php if ($form_mode === 'edit'): ?>
                        <input type="hidden" name="book_id" value="<?php echo (int)$edit_id; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="back" value="<?php echo e($back_value); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">

                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-primary"><?php echo $form_mode === 'edit' ? 'Save Changes' : 'Add Book'; ?></button>
                        <a href="<?php echo e(back_url($back_value)); ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>

            <!-- Books Table -->
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>ISBN</th>
                            <th>Copies</th>
                            <th>Available</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($books) > 0): ?>
                            <?php foreach ($books as $row):
                                $is_active    = ($row['status'] === 'active');
                                $status_badge = $is_active ? 'badge-active' : 'badge-inactive';
                                $meta = [];
                                if (!empty($row['publisher'])) { $meta[] = $row['publisher']; }
                                if ((int)$row['publication_year'] > 0) { $meta[] = $row['publication_year']; }
                                if (!empty($row['location'])) { $meta[] = 'Shelf ' . $row['location']; }
                            ?>
                                <tr>
                                    <td><?php echo (int)$row['book_id']; ?></td>
                                    <td>
                                        <strong><?php echo e($row['title']); ?></strong>
                                        <?php if ($meta): ?><br><span class="small-meta"><?php echo e(implode(' · ', $meta)); ?></span><?php endif; ?>
                                    </td>
                                    <td><?php echo e($row['author']); ?></td>
                                    <td><?php echo e($row['category']); ?></td>
                                    <td><?php echo e($row['isbn']); ?></td>
                                    <td><?php echo (int)$row['total_copies']; ?></td>
                                    <td><?php echo (int)$row['available_copies']; ?></td>
                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo e(ucfirst($row['status'])); ?></span></td>
                                    <td class="row-actions">
                                        <a class="btn btn-sm btn-outline-primary" href="book_management.php?edit=<?php echo (int)$row['book_id']; ?>#book-form">Edit</a>

                                        <form method="post" action="book_management.php">
                                            <input type="hidden" name="action" value="set_status">
                                            <input type="hidden" name="book_id" value="<?php echo (int)$row['book_id']; ?>">
                                            <input type="hidden" name="to" value="<?php echo $is_active ? 'inactive' : 'active'; ?>">
                                            <input type="hidden" name="back" value="<?php echo e($back_value); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $is_active ? 'Deactivate' : 'Activate'; ?></button>
                                        </form>

                                        <form method="post" action="book_management.php"
                                              onsubmit="return confirm('Delete this book permanently? This only works for books with no loan history.');">
                                            <input type="hidden" name="action" value="delete_book">
                                            <input type="hidden" name="book_id" value="<?php echo (int)$row['book_id']; ?>">
                                            <input type="hidden" name="back" value="<?php echo e($back_value); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted">No books found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="text-muted">
                Showing <?php echo $total_rows === 0 ? 0 : $offset + 1; ?>–<?php echo min($offset + BOOKS_PER_PAGE, $total_rows); ?>
                of <?php echo $total_rows; ?> book<?php echo $total_rows === 1 ? '' : 's'; ?>.
            </p>

            <?php if ($total_pages > 1):
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);
            ?>
                <nav aria-label="Book pages">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo e(page_url(max(1, $page - 1))); ?>">Previous</a>
                        </li>
                        <?php for ($i = $start; $i <= $end; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo e(page_url($i)); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo e(page_url(min($total_pages, $page + 1))); ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleSearch() {
            document.getElementById('searchForm').classList.toggle('show');
        }
    </script>
</body>
</html>
