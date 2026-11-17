-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 17, 2026 at 11:23 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `lms`
--

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `book_id` int(11) NOT NULL,
  `isbn` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `publisher` varchar(100) DEFAULT NULL,
  `publication_year` year(4) DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `total_copies` int(11) DEFAULT 1,
  `available_copies` int(11) DEFAULT 1,
  `location` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`book_id`, `isbn`, `title`, `author`, `publisher`, `publication_year`, `category`, `total_copies`, `available_copies`, `location`, `price`, `status`) VALUES
(1, '978-1111111111', 'PHP Basics', 'Author A', NULL, NULL, 'Technology', 5, 5, NULL, NULL, 'active'),
(2, '978-2222222222', 'Intro to MySQL', 'Author B', NULL, NULL, 'Technology', 3, 3, NULL, NULL, 'active'),
(3, '978-3-16-148410-0', 'The Great Gatsby', 'F. Scott Fitzgerald', 'Scribner', '1925', 'Fiction', 5, 5, 'A-01-01', 29.90, 'active'),
(4, '978-0-14-044913-6', 'Meditations', 'Marcus Aurelius', 'Penguin Classics', '0000', 'Philosophy', 3, 3, 'A-02-05', 19.50, 'active'),
(5, '978-0-670-81302-8', '1984', 'George Orwell', 'Secker & Warburg', '1949', 'Fiction', 4, 2, 'A-03-07', 25.00, 'active'),
(6, '978-0-307-74176-9', 'Sapiens', 'Yuval Noah Harari', 'Harper', '2011', 'Non-Fiction', 6, 6, 'B-01-03', 45.00, 'active'),
(7, '978-1-56619-909-4', 'Clean Code', 'Robert C. Martin', 'Prentice Hall', '2008', 'Technology', 2, 2, 'C-02-10', 55.00, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `loan_records`
--

CREATE TABLE `loan_records` (
  `loan_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `librarian_id` int(11) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('active','returned','overdue') DEFAULT 'active',
  `fine_amount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `loan_records`
--

INSERT INTO `loan_records` (`loan_id`, `member_id`, `book_id`, `librarian_id`, `issue_date`, `due_date`, `return_date`, `status`, `fine_amount`) VALUES
(1, 1, 1, 2, '2025-09-01', '2025-09-15', NULL, 'active', 0.00),
(2, 1, 2, 2, '2025-08-20', '2025-09-05', '2025-09-04', 'returned', 0.00),
(3, 1, 3, 2, '2025-08-01', '2025-08-15', NULL, 'overdue', 5.00),
(4, 2, 1, 2, '2025-09-05', '2025-09-20', NULL, 'active', 0.00),
(5, 2, 4, 2, '2025-09-05', '2025-09-20', NULL, 'active', 0.00),
(6, 3, 5, 2, '2025-09-10', '2025-09-25', NULL, 'active', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `member_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `member_number` varchar(20) NOT NULL,
  `membership_type` enum('standard','premium','student') DEFAULT 'standard',
  `join_date` date NOT NULL,
  `max_books_allowed` int(11) DEFAULT 3,
  `current_books_issued` int(11) DEFAULT 0,
  `total_fine_amount` decimal(10,2) DEFAULT 0.00,
  `member_status` enum('active','suspended') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`member_id`, `user_id`, `member_number`, `membership_type`, `join_date`, `max_books_allowed`, `current_books_issued`, `total_fine_amount`, `member_status`) VALUES
(1, 3, 'LIB2025001', 'standard', '2025-09-01', 3, 0, 0.00, 'active'),
(2, 4, 'LIB2025002', 'premium', '2025-09-05', 5, 0, 0.00, 'active'),
(3, 5, 'LIB2025003', 'student', '2025-09-10', 2, 0, 0.00, 'active'),
(7, 25, 'MEM20250025', 'student', '2025-09-17', 3, 0, 0.00, 'active'),
(8, 26, 'MEM20250026', 'premium', '2025-09-17', 3, 0, 0.00, 'active'),
(9, 27, 'MEM20250027', 'standard', '2025-09-17', 3, 0, 0.00, 'active'),
(10, 28, 'MEM20250028', 'premium', '2025-09-18', 3, 0, 0.00, 'active'),
(11, 32, 'MEM20250032', 'standard', '2025-09-19', 3, 0, 0.00, 'active'),
(12, 33, 'MEM20260033', 'premium', '2026-10-29', 3, 0, 0.00, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('admin','librarian','member') NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `email`, `role`, `first_name`, `last_name`, `phone_number`, `is_active`, `created_at`) VALUES
(1, 'admin1', 'e10adc3949ba59abbe56e057f20f883e', 'admin1@example.com', 'admin', 'Alice', 'Admin', '0123456789', 1, '2025-09-17 02:00:00'),
(2, 'librarian1', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'librarian1@example.com', 'librarian', 'Bob', 'Librarian', '0123456788', 1, '2025-09-17 02:05:00'),
(3, 'member1', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'member1@example.com', 'member', 'John', 'Doe', NULL, 1, '2025-09-17 02:10:00'),
(4, 'member2', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'member2@example.com', 'member', 'Charlie', 'Member', NULL, 1, '2025-09-17 02:15:00'),
(5, 'member3', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'member3@example.com', 'member', 'David', 'Member', NULL, 1, '2025-09-17 02:20:00'),
(25, 'test1', 'e10adc3949ba59abbe56e057f20f883e', 'ryanyap1996@1utar.my', 'member', 'CHEE HOW', 'YAP', '0164576670', 1, '2025-09-17 04:28:07'),
(26, 'ryanyap108', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'ryanyap1996@gmail.com', 'member', 'Ryan', 'Yap', '0164576670', 1, '2025-09-17 04:30:52'),
(27, 'msaswawawa841', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'zenosvon2@gmail.com', 'member', 'MSAS', 'wawawa', '0164576670', 1, '2025-09-17 08:09:31'),
(28, 'apokalimwei904', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'www8@example.com', 'member', 'Apokalim', 'Wei', '01456733333', 1, '2025-09-18 06:30:32'),
(31, 'admin2', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'admin2@example.com', 'admin', 'Bob', 'Admin', '0123456790', 1, '2025-09-18 18:24:21'),
(32, 'karimabbul239', '$2y$10$1Klm8rLB3m7iU8Abo1/IseyqEHuKP5POppE2qpZg2qpuhrjHrx9nS', 'kakak@example.com', 'member', 'karim', 'abbul', '231321312312', 1, '2025-09-18 19:31:09'),
(33, 'cheryllourdes259', '$2y$10$UYjYf/PEMTx.IDtZ0IfRFu.zAiZFDyCUDwgnJFlKgrbbB6PCnB5v.', 'CL777@gmail.com', 'member', 'Cheryl', 'Lourdes', '0172345567', 1, '2026-10-29 11:43:58');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`book_id`),
  ADD UNIQUE KEY `isbn` (`isbn`);

--
-- Indexes for table `loan_records`
--
ALTER TABLE `loan_records`
  ADD PRIMARY KEY (`loan_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `book_id` (`book_id`),
  ADD KEY `librarian_id` (`librarian_id`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`member_id`),
  ADD UNIQUE KEY `member_number` (`member_number`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `loan_records`
--
ALTER TABLE `loan_records`
  MODIFY `loan_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `loan_records`
--
ALTER TABLE `loan_records`
  ADD CONSTRAINT `loan_records_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`),
  ADD CONSTRAINT `loan_records_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`),
  ADD CONSTRAINT `loan_records_ibfk_3` FOREIGN KEY (`librarian_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `members`
--
ALTER TABLE `members`
  ADD CONSTRAINT `members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
