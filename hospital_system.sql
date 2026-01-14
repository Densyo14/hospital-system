-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 27, 2025 at 09:36 AM
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
-- Database: `hospital_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `schedule_datetime` datetime DEFAULT NULL,
  `status` enum('Pending','Approved','Completed','Cancelled') DEFAULT 'Pending',
  `reason` text DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `patient_id`, `doctor_id`, `schedule_datetime`, `status`, `reason`, `is_archived`, `archived_at`, `archived_by`, `notes`, `created_at`) VALUES
(1, 1, 3, '2025-11-27 09:00:00', 'Approved', 'Follow-up Checkup', 0, NULL, NULL, NULL, '2025-11-27 08:10:29'),
(2, 2, 4, '2025-11-29 10:30:00', 'Cancelled', 'Initial Consultation', 0, NULL, NULL, '', '2025-11-27 08:10:29'),
(3, 3, 5, '2025-11-30 14:00:00', 'Approved', 'Surgery Scheduling', 0, NULL, NULL, NULL, '2025-11-27 08:10:29'),
(4, 4, 6, '2025-12-01 08:00:00', 'Completed', 'Post-surgery review', 0, NULL, NULL, NULL, '2025-11-27 08:10:29'),
(5, 5, 7, '2025-12-02 11:00:00', 'Cancelled', 'Guardian unavailable', 0, NULL, NULL, NULL, '2025-11-27 08:10:29'),
(6, 8, 4, '2025-11-28 19:45:00', 'Pending', 'hi', 0, NULL, NULL, NULL, '2025-11-27 08:10:29');

-- --------------------------------------------------------

--
-- Table structure for table `archive_logs`
--

CREATE TABLE `archive_logs` (
  `id` int(11) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) NOT NULL,
  `archived_by` int(11) NOT NULL,
  `archived_at` datetime DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `archive_logs`
--

INSERT INTO `archive_logs` (`id`, `table_name`, `record_id`, `archived_by`, `archived_at`, `reason`) VALUES
(1, 'patients', 9, 1, '2025-11-27 14:44:38', 'Archived by administrator');

-- --------------------------------------------------------

--
-- Table structure for table `billing`
--

CREATE TABLE `billing` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `surgery_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `philhealth_coverage` decimal(10,2) DEFAULT 0.00,
  `hmo_coverage` decimal(10,2) DEFAULT 0.00,
  `amount_due` decimal(10,2) DEFAULT NULL,
  `status` enum('Unpaid','Paid') DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billing`
--

INSERT INTO `billing` (`id`, `patient_id`, `surgery_id`, `total_amount`, `philhealth_coverage`, `hmo_coverage`, `amount_due`, `status`, `created_at`) VALUES
(6, 5, 8, 25000.00, 15000.00, 5000.00, 5000.00, 'Paid', '2025-11-26 09:42:03'),
(7, 1, 6, 35000.00, 20000.00, 5000.00, 10000.00, 'Unpaid', '2025-11-26 09:42:03'),
(8, 3, 7, 42000.00, 20000.00, 10000.00, 12000.00, 'Unpaid', '2025-11-26 09:42:03'),
(9, 4, 9, 18000.00, 5000.00, 3000.00, 10000.00, 'Paid', '2025-11-26 09:42:03'),
(10, 2, 10, 50000.00, 25000.00, 10000.00, 15000.00, 'Unpaid', '2025-11-26 09:42:03');

-- --------------------------------------------------------

--
-- Table structure for table `financial_assessment`
--

CREATE TABLE `financial_assessment` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `assessment_type` enum('Charity','Partial','Paying') DEFAULT NULL,
  `philhealth_eligible` tinyint(1) DEFAULT 0,
  `hmo_provider` varchar(100) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `financial_assessment`
--

INSERT INTO `financial_assessment` (`id`, `patient_id`, `assessment_type`, `philhealth_eligible`, `hmo_provider`, `status`) VALUES
(1, 1, 'Charity', 1, NULL, 'Approved'),
(2, 2, 'Paying', 1, 'Maxicare', 'Pending'),
(3, 3, 'Partial', 1, 'Intellicare', 'Approved'),
(4, 4, 'Charity', 0, NULL, 'Approved'),
(5, 5, 'Paying', 1, 'Generali', 'Approved');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_items`
--

CREATE TABLE `inventory_items` (
  `id` int(11) NOT NULL,
  `item_name` varchar(150) DEFAULT NULL,
  `category` enum('Implant','Medicine','Suture','Equipment','General') DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `threshold` int(11) DEFAULT 5,
  `unit` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_items`
--

INSERT INTO `inventory_items` (`id`, `item_name`, `category`, `quantity`, `threshold`, `unit`, `updated_at`) VALUES
(1, 'Titanium Implant Rod', 'Implant', 48, 5, 'pcs', '2025-11-26 09:58:54'),
(2, 'Surgical Suture 3-0', 'Suture', 196, 20, 'packs', '2025-11-26 09:58:54'),
(3, 'Antibiotic Cefalexin', 'Medicine', 115, 10, 'bottles', '2025-11-26 09:58:54'),
(4, 'Crutches (Child Size)', 'Equipment', 28, 5, 'pairs', '2025-11-26 09:58:54'),
(5, 'Face Masks', 'General', 499, 50, 'boxes', '2025-11-26 09:58:54'),
(6, 'Wheelchair Pediatric', 'Equipment', 9, 2, 'units', '2025-11-26 09:58:54'),
(7, 'Orthopedic Screws', 'Implant', 148, 15, 'pcs', '2025-11-26 09:58:54'),
(8, 'Gauze Pads', 'General', 297, 25, 'packs', '2025-11-26 09:58:54');

-- --------------------------------------------------------

--
-- Table structure for table `medical_records`
--

CREATE TABLE `medical_records` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `clinical_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_records`
--

INSERT INTO `medical_records` (`id`, `patient_id`, `doctor_id`, `diagnosis`, `clinical_notes`, `created_at`) VALUES
(1, 1, 3, 'Clubfoot', 'Initial evaluation completed. Recommended for surgery.', '2025-11-26 09:40:53'),
(2, 2, 4, 'Scoliosis', 'Scheduled for imaging and further evaluation.', '2025-11-26 09:40:53'),
(3, 3, 5, 'Leg Deformity', 'Bone realignment procedure recommended.', '2025-11-26 09:40:53'),
(4, 4, 6, 'Tendon Shortening', 'Patient preparing for tendon release.', '2025-11-26 09:40:53'),
(5, 5, 7, 'Hip Dysplasia', 'Requires hip reconstruction surgery.', '2025-11-26 09:40:53');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` int(11) NOT NULL,
  `patient_code` varchar(20) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `guardian` varchar(100) DEFAULT NULL,
  `blood_type` varchar(10) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `pulse_rate` int(11) DEFAULT NULL,
  `temperature` decimal(4,2) DEFAULT NULL,
  `guardian_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`id`, `patient_code`, `first_name`, `last_name`, `gender`, `sex`, `birth_date`, `phone`, `address`, `guardian`, `blood_type`, `weight`, `height`, `pulse_rate`, `temperature`, `guardian_name`, `created_at`, `is_archived`, `archived_at`, `archived_by`) VALUES
(1, 'P-1001', 'Gabriel', 'Lopez', NULL, 'Male', '2014-06-12', '09123456701', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Maria Lopez', '2025-11-26 09:34:30', 0, NULL, NULL),
(2, 'P-1002', 'Hannah', 'Reyes', NULL, 'Female', '2012-03-22', '09123456702', 'Panabo City', NULL, NULL, NULL, NULL, NULL, NULL, 'James Reyes', '2025-11-26 09:34:30', 0, NULL, NULL),
(3, 'P-1003', 'Miguel', 'Santos', NULL, 'Male', '2015-01-10', '09123456703', 'Tagum City', NULL, NULL, NULL, NULL, NULL, NULL, 'Ana Santos', '2025-11-26 09:34:30', 0, NULL, NULL),
(4, 'P-1004', 'Sophia', 'Dela Cruz', NULL, 'Female', '2016-08-30', '09123456704', 'Digos City', NULL, NULL, NULL, NULL, NULL, NULL, 'Mark Dela Cruz', '2025-11-26 09:34:30', 0, NULL, NULL),
(5, 'P-1005', 'Ethan', 'Garcia', NULL, 'Male', '2013-07-18', '09123456705', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Louis Garcia', '2025-11-26 09:34:30', 0, NULL, NULL),
(6, 'P-1006', 'Isabelle', 'Torres', NULL, 'Female', '2011-11-11', '09123456706', 'Davao del Sur', NULL, NULL, NULL, NULL, NULL, NULL, 'Janet Torres', '2025-11-26 09:34:30', 0, NULL, NULL),
(7, 'P-1007', 'Lucas', 'Fernandez', NULL, 'Male', '2014-02-02', '09123456707', 'Panabo City', NULL, NULL, NULL, NULL, NULL, NULL, 'John Fernandez', '2025-11-26 09:34:30', 0, NULL, NULL),
(8, 'P-1008', 'Chloe', 'Martinez', NULL, 'Female', '2017-05-14', '09123456708', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Patricia Martinez', '2025-11-26 09:34:30', 0, NULL, NULL),
(9, 'P-1009', 'Nathan', 'Ramos', NULL, 'Male', '2014-12-01', '09123456709', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Peter Ramos', '2025-11-26 09:34:30', 1, '2025-11-27 14:44:38', 1),
(20, 'P20250014', 'Dennis12', 'Licayan', 'Male', NULL, '2001-02-09', '09183344144', 'Bagong buhay', 's', 'A-', 12.00, 2.00, 1, 1.00, NULL, '2025-11-27 06:22:24', 0, NULL, NULL),
(21, 'P20250015', 'Razel Joy', 'Licayan', 'Male', NULL, '2008-11-20', '09183344144', 'Davao City', 'Dennis Licayan', 'A+', 55.00, 100.00, 90, 45.00, NULL, '2025-11-27 06:29:39', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `post_op_equipment`
--

CREATE TABLE `post_op_equipment` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `equipment_name` varchar(100) DEFAULT NULL,
  `assigned_date` date DEFAULT NULL,
  `returned` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `post_op_equipment`
--

INSERT INTO `post_op_equipment` (`id`, `patient_id`, `equipment_name`, `assigned_date`, `returned`) VALUES
(1, 5, 'Crutches', '2025-12-06', 0),
(2, 3, 'Wheelchair Pediatric', '2025-12-08', 0),
(3, 1, 'Crutches', '2025-11-29', 1),
(4, 4, 'Walker (Child Size)', '2025-12-10', 0);

-- --------------------------------------------------------

--
-- Table structure for table `surgeries`
--

CREATE TABLE `surgeries` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `surgery_type` varchar(150) DEFAULT NULL,
  `schedule_date` date DEFAULT NULL,
  `operating_room` varchar(20) DEFAULT NULL,
  `status` enum('Scheduled','Completed','Cancelled') DEFAULT 'Scheduled',
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `surgeries`
--

INSERT INTO `surgeries` (`id`, `patient_id`, `doctor_id`, `surgery_type`, `schedule_date`, `operating_room`, `status`, `is_archived`, `archived_at`, `archived_by`, `created_at`) VALUES
(6, 1, 3, 'Clubfoot Correction', '2025-11-28', 'OR-1', 'Scheduled', 0, NULL, NULL, '2025-11-27 08:34:09'),
(7, 2, 4, 'Scoliosis Adjustment', '2025-12-01', 'OR-2', 'Scheduled', 0, NULL, NULL, '2025-11-27 08:34:09'),
(8, 3, 5, 'Bone Realignment', '2025-12-03', 'OR-3', 'Completed', 0, NULL, NULL, '2025-11-27 08:34:09'),
(9, 4, 6, 'Tendon Release', '2025-12-05', 'OR-1', 'Scheduled', 0, NULL, NULL, '2025-11-27 08:34:09'),
(10, 5, 7, 'Hip Reconstruction', '2025-12-10', 'OR-3', 'Cancelled', 0, NULL, NULL, '2025-11-27 08:34:09'),
(11, 8, 5, 'Achilles', '2026-12-09', 'room 12', 'Scheduled', 0, NULL, NULL, '2025-11-27 08:34:09');

-- --------------------------------------------------------

--
-- Table structure for table `surgery_inventory`
--

CREATE TABLE `surgery_inventory` (
  `id` int(11) NOT NULL,
  `surgery_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `quantity_used` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `surgery_inventory`
--

INSERT INTO `surgery_inventory` (`id`, `surgery_id`, `item_id`, `quantity_used`) VALUES
(8, 11, 3, 5),
(9, 11, 4, 2),
(10, 11, 5, 1),
(11, 11, 8, 3),
(12, 11, 7, 2),
(13, 11, 2, 4),
(14, 11, 1, 2),
(15, 11, 6, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('Admin','Doctor','Nurse','Staff','Inventory','Billing','SocialWorker') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'System Admin', 'admin@gmail.com', '$2y$10$YbzP7xoTF/ym2iFoxaVeNej6IyAEFrkNWVRc8SoQKISqQexqsCADS', 'Admin', '2025-11-26 08:57:08'),
(3, 'Dr. Mark Reyes', 'drreyes@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25'),
(4, 'Dr. Anna Santos', 'drsantos@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25'),
(5, 'Dr. John Dela Cruz', 'drdelacruz@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25'),
(6, 'Dr. Maria Tan', 'drtan@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25'),
(7, 'Dr. Peter Lee', 'drlee@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25'),
(8, 'Nurse Carla Mendoza', 'nursecarla@gmail.com', '123456', 'Nurse', '2025-11-26 09:34:25'),
(10, 'Staff Juan Torres', 'juan_staff@gmail.com', '123456', 'Staff', '2025-11-26 09:34:25'),
(11, 'Inventory Officer Paul Ramos', 'paul_inventory@gmail.com', '123456', 'Inventory', '2025-11-26 09:34:25'),
(12, 'Billing Officer Sarah Lim', 'sarah_billing@gmail.com', '123456', 'Billing', '2025-11-26 09:34:25'),
(13, 'Social Worker Joy Morales', 'joy_sw@gmail.com', '123456', 'SocialWorker', '2025-11-26 09:34:25'),
(31, 'Dong Hernandez', 'd.hernandez@gmail.com', '$2y$10$dlH6zwW517vYKNnvsaenj.TMpIAocYXb2GRTVii6aADIi/xYm4zbC', 'Staff', '2025-11-26 11:58:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `archived_by` (`archived_by`),
  ADD KEY `idx_appointments_archived` (`is_archived`),
  ADD KEY `idx_appointments_doctor_datetime` (`doctor_id`,`schedule_datetime`);

--
-- Indexes for table `archive_logs`
--
ALTER TABLE `archive_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `archived_by` (`archived_by`);

--
-- Indexes for table `billing`
--
ALTER TABLE `billing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `surgery_id` (`surgery_id`);

--
-- Indexes for table `financial_assessment`
--
ALTER TABLE `financial_assessment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `patient_code` (`patient_code`),
  ADD KEY `archived_by` (`archived_by`);

--
-- Indexes for table `post_op_equipment`
--
ALTER TABLE `post_op_equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `surgeries`
--
ALTER TABLE `surgeries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `archived_by` (`archived_by`);

--
-- Indexes for table `surgery_inventory`
--
ALTER TABLE `surgery_inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `surgery_id` (`surgery_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `archive_logs`
--
ALTER TABLE `archive_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `financial_assessment`
--
ALTER TABLE `financial_assessment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `medical_records`
--
ALTER TABLE `medical_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `post_op_equipment`
--
ALTER TABLE `post_op_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `surgeries`
--
ALTER TABLE `surgeries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `surgery_inventory`
--
ALTER TABLE `surgery_inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `appointments_ibfk_4` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `archive_logs`
--
ALTER TABLE `archive_logs`
  ADD CONSTRAINT `archive_logs_ibfk_1` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `billing`
--
ALTER TABLE `billing`
  ADD CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `billing_ibfk_2` FOREIGN KEY (`surgery_id`) REFERENCES `surgeries` (`id`);

--
-- Constraints for table `financial_assessment`
--
ALTER TABLE `financial_assessment`
  ADD CONSTRAINT `financial_assessment_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`);

--
-- Constraints for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD CONSTRAINT `medical_records_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `medical_records_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `post_op_equipment`
--
ALTER TABLE `post_op_equipment`
  ADD CONSTRAINT `post_op_equipment_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`);

--
-- Constraints for table `surgeries`
--
ALTER TABLE `surgeries`
  ADD CONSTRAINT `surgeries_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `surgeries_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `surgeries_ibfk_3` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `surgery_inventory`
--
ALTER TABLE `surgery_inventory`
  ADD CONSTRAINT `surgery_inventory_ibfk_1` FOREIGN KEY (`surgery_id`) REFERENCES `surgeries` (`id`),
  ADD CONSTRAINT `surgery_inventory_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`);

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `auto_delete_old_archives` ON SCHEDULE EVERY 1 DAY STARTS '2025-11-27 14:41:01' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    DELETE FROM patients 
    WHERE is_archived = 1 AND archived_at < DATE_SUB(NOW(), INTERVAL 10 YEAR);
    
    DELETE FROM archive_logs 
    WHERE archived_at < DATE_SUB(NOW(), INTERVAL 10 YEAR);
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
