<?php
session_start();

// Check if user is logged in as admin or librarian
if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'librarian')) {        
    header("Location: index.php");
    exit();
}

// Helper function to safely output text   
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Database connection
$connection = new mysqli("localhost", "root", "", "lms");
if ($connection->connect_error) {
    die("Connection failed: " . $connection->connect_error);
}

// Handle book addition
$add_book_message = '';
if (isset($_POST['add_book'])) {
    // Validate and sanitize inputs
    $isbn = trim($_POST['isbn']);
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $publisher = trim($_POST['publisher']);
    $publication_year = !empty($_POST['publication_year']) ? (int)$_POST['publication_year'] : null;
    $category = trim($_POST['category']);
    $total_copies = (int)$_POST['total_copies'];
    $price = !empty($_POST['price']) ? (float)$_POST['price'] : null;
    $location = trim($_POST['location']);
    $available_copies = $total_copies;

    // Check if ISBN already exists
    $isbn_check = $connection->prepare("SELECT book_id FROM books WHERE isbn = ?");
    $isbn_check->bind_param("s", $isbn);
    $isbn_check->execute();
    $isbn_result = $isbn_check->get_result();

    if ($isbn_result->num_rows > 0) {
        $add_book_message = '<div class="alert alert-danger">A book with this ISBN already exists!</div>';
    } else {
        $stmt = $connection->prepare("INSERT INTO books (isbn, title, author, publisher, publication_year, category, total_copies, available_copies, location, price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("sssssisids",
            $isbn,
            $title,
            $author,
            $publisher,
            $publication_year,
            $category,
            $total_copies,
            $available_copies,
            $location,
            $price
        );

        if ($stmt->execute()) {
            $add_book_message = '<div class="alert alert-success">';
            $add_book_message .= '<strong>Book Added Successfully!</strong><br>';
            $add_book_message .= 'Book ID: <strong>' . $connection->insert_id . '</strong><br>';
            $add_book_message .= 'Title: <strong>' . e($title) . '</strong>';
            $add_book_message .= '</div>';
            // Optionally hide the add form after success via JS below
        } else {
            $add_book_message = '<div class="alert alert-danger">Failed to add book!</div>';
        }
        $stmt->close();
    }
    $isbn_check->close();
}

// Prepare search filters
$where_clause = "1=1";
$params = [];
$param_types = "";
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search = "%" . trim($_GET['search']) . "%";
    $where_clause .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $param_types .= "sss";
}
if (isset($_GET['category']) && !empty(trim($_GET['category']))) {
    $category = trim($_GET['category']);
    $where_clause .= " AND category = ?";
    $params[] = $category;
    $param_types .= "s";
}

// Fetch statistics
function fetch_single_value($connection, $query) {
    $result = $connection->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        return $row ? $row[array_key_first($row)] : 0;
    }
    return 0;
}

$total = fetch_single_value($connection, "SELECT COUNT(*) FROM books");
$active = fetch_single_value($connection, "SELECT COUNT(*) FROM books WHERE status='active'");
$copies = fetch_single_value($connection, "SELECT SUM(total_copies) FROM books");
$available = fetch_single_value($connection, "SELECT SUM(available_copies) FROM books");

// Fetch books with prepared statement
$books = [];
$query = "SELECT * FROM books WHERE $where_clause ORDER BY title";
$stmt = $connection->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    $stmt->close();
}

$connection->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Book Management - Library System</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" type="text/css" href="bootstrap-4.4.1/css/bootstrap.min.css" />
    <script type="text/javascript" src="bootstrap-4.4.1/js/jquery_latest.js"></script>
    <script type="text/javascript" src="bootstrap-4.4.1/js/bootstrap.min.js"></script>
    <style type="text/css">
        /* === Your original CSS preserved === */
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
        
        .form-section {
            background: rgba(248, 249, 250, 0.8);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: none;
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
        
        .badge-active {
            background: #28a745;
        }
        
        .badge-inactive {
            background: #dc3545;
        }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin: 15px 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand font-weight-bold" href="#">Book Management System</a>
            <div class="navbar-nav ml-auto">
                <a class="nav-link" href="dashboard.php">Dashboard</a>
                <a class="nav-link active" href="book_management.php">Books</a>
                <a class="nav-link" href="logout.php">Logout (<?php echo e($_SESSION['name']); ?>)</a>
            </div>
        </nav>

        <div class="content">
            <h2 class="mb-4">Book Management</h2>
            
            <!-- Statistics -->
            <div class="stats-row">
                <div class="stat-card">
                    <h3><?php echo e($total); ?></h3>
                    <p>Total Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($active); ?></h3>
                    <p>Active Books</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($copies); ?></h3>
                    <p>Total Copies</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo e($available); ?></h3>
                    <p>Available Copies</p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mb-3">
                <button class="btn btn-primary" onclick="toggleAddForm()">Add New Book</button>
                <button class="btn btn-secondary" onclick="toggleSearch()">Search Books</button>
            </div>

            <!-- Search Form -->
            <div class="form-section" id="searchForm">
                <h5>Search Books</h5>
                <form method="GET" action="">
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Search title, author, or ISBN" value="<?php echo isset($_GET['search']) ? e($_GET['search']) : ''; ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-control">
                                <option value="">All Categories</option>
                                <option value="Fiction" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Fiction') ? 'selected' : ''; ?>>Fiction</option>
                                <option value="Science" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Science') ? 'selected' : ''; ?>>Science</option>
                                <option value="History" <?php echo (isset($_GET['category']) && $_GET['category'] === 'History') ? 'selected' : ''; ?>>History</option>
                                <option value="Technology" <?php echo (isset($_GET['category']) && $_GET['category'] === 'Technology') ? 'selected' : ''; ?>>Technology</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary">Search</button>
                            <a href="book_management.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Add Book Form -->
            <div class="form-section" id="addBookForm">
                <h5>Add New Book</h5>
                <?php echo $add_book_message; ?>
                <form action="" method="post" autocomplete="off">
                    <div class="row">
                        <div class="col-md-6">
                            <input type="text" name="title" class="form-control" placeholder="Book Title *" required>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="author" class="form-control" placeholder="Author *" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" name="isbn" class="form-control" placeholder="ISBN *" required>
                        </div>
                        <div class="col-md-4">
                            <select name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <option value="Fiction">Fiction</option>
                                <option value="Science">Science</option>
                                <option value="History">History</option>
                                <option value="Technology">Technology</option>
                                <option value="Biography">Biography</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="number" name="publication_year" class="form-control" placeholder="Year" min="1800" max="<?php echo date('Y'); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <input type="text" name="publisher" class="form-control" placeholder="Publisher">
                        </div>
                        <div class="col-md-4">
                            <input type="number" name="total_copies" class="form-control" placeholder="Total Copies *" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <input type="number" name="price" class="form-control" placeholder="Price (RM)" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <input type="text" name="location" class="form-control" placeholder="Shelf Location (e.g., A-01-15)">
                        </div>
                    </div>
                    <div class="text-center mt-3">
                        <button type="submit" name="add_book" class="btn btn-primary">Add Book</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleAddForm()">Cancel</button>
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
                                $status_badge = ($row['status'] == 'active') ? 'badge-active' : 'badge-inactive';
                            ?>
                                <tr>
                                    <td><?php echo e($row['book_id']); ?></td>
                                    <td><strong><?php echo e($row['title']); ?></strong></td>
                                    <td><?php echo e($row['author']); ?></td>
                                    <td><?php echo e($row['category']); ?></td>
                                    <td><?php echo e($row['isbn']); ?></td>
                                    <td><?php echo e($row['total_copies']); ?></td>
                                    <td><?php echo e($row['available_copies']); ?></td>
                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo e(ucfirst($row['status'])); ?></span></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editBook(<?php echo e($row['book_id']); ?>)">Edit</button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteBook(<?php echo e($row['book_id']); ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted">No books found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function toggleAddForm() {
            var form = document.getElementById('addBookForm');
            form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
        }

        function toggleSearch() {
            var search = document.getElementById('searchForm');
            search.style.display = (search.style.display === 'none' || search.style.display === '') ? 'block' : 'none';
        }

        function editBook(bookId) {
            alert('Edit book feature - Book ID: ' + bookId);
        }

        function deleteBook(bookId) {
            if (confirm('Are you sure you want to delete this book?')) {
                alert('Delete book feature - Book ID: ' + bookId);
            }
        }

        <?php if (isset($_GET['search']) || isset($_GET['category'])): ?>
        document.getElementById('searchForm').style.display = 'block';
        <?php endif; ?>
    </script>
</body>
</html>