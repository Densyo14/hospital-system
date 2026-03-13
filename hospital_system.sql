-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 11, 2026 at 01:43 PM
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
(4, 4, 5, '2025-12-19 08:00:00', 'Completed', 'Post-surgery reviews', 0, NULL, NULL, '', '2025-11-27 08:10:29'),
(5, 5, 7, '2025-12-12 11:00:00', 'Pending', 'Guardian unavailable waiting', 0, NULL, NULL, '', '2025-11-27 08:10:29'),
(6, 8, 4, '2025-11-28 19:45:00', 'Pending', 'hi', 0, NULL, NULL, NULL, '2025-11-27 08:10:29'),
(9, 25, 6, '2025-12-19 10:58:00', 'Pending', 'Cure to UTI', 0, NULL, NULL, 'Hes Bad', '2025-12-04 11:53:03'),
(10, 22, 4, '2025-12-25 21:15:00', 'Pending', 'Potty', 1, '2025-12-09 10:04:27', 31, 'Potty', '2025-12-08 08:53:03');

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
(1, 'patients', 9, 1, '2025-11-27 14:44:38', 'Archived by administrator'),
(2, 'billing', 8, 1, '2025-12-04 16:10:45', 'Archived by user'),
(3, 'billing', 10, 1, '2025-12-04 16:11:36', 'Archived by user'),
(4, 'financial_assessment', 1, 1, '2025-12-04 16:35:09', 'Archived by user'),
(5, 'patients', 21, 1, '2025-12-04 16:45:07', 'Archived by administrator'),
(6, 'financial_assessment', 5, 31, '2025-12-04 17:30:27', 'Archived by user'),
(7, 'billing', 10, 31, '2025-12-04 17:30:38', 'Archived by user'),
(8, 'patients', 28, 31, '2025-12-09 06:53:45', 'Archived by administrator'),
(9, 'appointments', 10, 31, '2025-12-09 10:04:27', 'Archived by administrator'),
(10, 'inventory_items', 13, 31, '2025-12-09 11:14:58', 'Archived by user');

-- --------------------------------------------------------

--
-- Table structure for table `billing`
--

CREATE TABLE `billing` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `financial_id` int(11) DEFAULT NULL,
  `financial_assessment_id` int(11) DEFAULT NULL,
  `surgery_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `philhealth_coverage` decimal(10,2) DEFAULT 0.00,
  `hmo_coverage` decimal(10,2) DEFAULT 0.00,
  `amount_due` decimal(10,2) DEFAULT NULL,
  `status` enum('Unpaid','Paid') DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billing`
--

INSERT INTO `billing` (`id`, `patient_id`, `financial_id`, `financial_assessment_id`, `surgery_id`, `total_amount`, `philhealth_coverage`, `hmo_coverage`, `amount_due`, `status`, `created_at`, `is_archived`, `archived_at`, `archived_by`, `paid_at`) VALUES
(6, 5, NULL, 5, 8, 25000.00, 15000.00, 5000.00, 5000.00, 'Paid', '2025-11-26 09:42:03', 0, NULL, NULL, NULL),
(7, 1, NULL, 1, 6, 35000.00, 20000.00, 5000.00, 10000.00, 'Unpaid', '2025-11-26 09:42:03', 0, NULL, NULL, NULL),
(8, 3, NULL, NULL, 7, 42000.00, 20000.00, 10000.00, 12000.00, 'Unpaid', '2025-11-26 09:42:03', 0, NULL, NULL, NULL),
(9, 4, NULL, 4, 9, 18000.00, 5000.00, 3000.00, 10000.00, 'Paid', '2025-11-26 09:42:03', 0, NULL, NULL, NULL),
(10, 2, NULL, NULL, NULL, 50000.00, 25000.00, 10000.00, 15000.00, 'Paid', '2025-11-26 09:42:03', 0, NULL, NULL, '2025-12-09 14:00:10'),
(14, 20, NULL, 7, 11, 10000.00, 2000.00, 1500.00, 6500.00, 'Paid', '2025-12-09 05:58:22', 0, NULL, NULL, '2025-12-09 13:58:55'),
(15, 21, NULL, 9, NULL, 90000.00, 45000.00, 27000.00, 18000.00, 'Paid', '2025-12-09 06:56:44', 0, NULL, NULL, '2025-12-09 15:14:59'),
(16, 8, NULL, 6, 11, 90000.00, 72000.00, 18000.00, 0.00, 'Unpaid', '2025-12-15 10:44:16', 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `financial_assessment`
--

CREATE TABLE `financial_assessment` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `billing_id` int(11) DEFAULT NULL,
  `assessment_type` enum('Charity','Partial','Paying') DEFAULT NULL,
  `philhealth_eligible` tinyint(1) DEFAULT 0,
  `hmo_provider` varchar(100) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `financial_assessment`
--

INSERT INTO `financial_assessment` (`id`, `patient_id`, `billing_id`, `assessment_type`, `philhealth_eligible`, `hmo_provider`, `status`, `is_archived`, `archived_at`, `archived_by`, `reviewed_at`, `reviewed_by`, `created_at`) VALUES
(1, 1, NULL, 'Charity', 1, NULL, 'Approved', 0, NULL, NULL, NULL, NULL, '2025-11-28 03:20:51'),
(2, 2, NULL, 'Paying', 1, 'Maxicare', 'Rejected', 0, NULL, NULL, '2025-11-28 11:21:01', 1, '2025-11-28 03:20:51'),
(3, 3, NULL, 'Partial', 1, 'Intellicare', 'Rejected', 0, NULL, NULL, NULL, NULL, '2025-11-28 03:20:51'),
(4, 4, NULL, 'Partial', 0, '', 'Approved', 0, NULL, NULL, NULL, NULL, '2025-11-28 03:20:51'),
(5, 5, NULL, 'Paying', 1, 'Generali', 'Approved', 0, NULL, NULL, NULL, NULL, '2025-11-28 03:20:51'),
(6, 8, NULL, 'Charity', 1, 'Intellicare', 'Approved', 0, NULL, NULL, '2025-12-15 19:24:32', 31, '2025-12-08 08:54:48'),
(7, 20, NULL, 'Partial', 0, '', 'Approved', 0, NULL, NULL, '2025-12-09 13:58:41', 31, '2025-12-09 05:58:35'),
(8, 28, NULL, 'Partial', 1, '', 'Pending', 0, NULL, NULL, NULL, NULL, '2025-12-15 09:38:30'),
(9, 21, NULL, 'Partial', 1, 'Maxicare', 'Approved', 0, NULL, NULL, NULL, 31, '2025-12-15 11:25:09');

--
-- Triggers `financial_assessment`
--
DELIMITER $$
CREATE TRIGGER `after_financial_assessment_update` AFTER UPDATE ON `financial_assessment` FOR EACH ROW BEGIN
    -- If financial assessment is approved and has changes that affect billing
    IF NEW.status = 'Approved' AND OLD.status != 'Approved' THEN
        -- Update related unpaid bills with new financial data
        UPDATE billing b
        SET 
            b.financial_assessment_id = NEW.id,
            -- Auto-calculate coverage based on assessment type (example logic)
            b.philhealth_coverage = CASE 
                WHEN NEW.philhealth_eligible = 1 THEN 
                    CASE NEW.assessment_type
                        WHEN 'Charity' THEN b.total_amount * 0.8
                        WHEN 'Partial' THEN b.total_amount * 0.5
                        WHEN 'Paying' THEN b.total_amount * 0.2
                        ELSE 0
                    END
                ELSE 0
            END,
            b.hmo_coverage = CASE 
                WHEN NEW.hmo_provider IS NOT NULL AND NEW.hmo_provider != '' THEN 
                    CASE NEW.assessment_type
                        WHEN 'Charity' THEN b.total_amount * 0.2
                        WHEN 'Partial' THEN b.total_amount * 0.3
                        WHEN 'Paying' THEN b.total_amount * 0.5
                        ELSE 0
                    END
                ELSE 0
            END,
            b.amount_due = b.total_amount - 
                (CASE 
                    WHEN NEW.philhealth_eligible = 1 THEN 
                        CASE NEW.assessment_type
                            WHEN 'Charity' THEN b.total_amount * 0.8
                            WHEN 'Partial' THEN b.total_amount * 0.5
                            WHEN 'Paying' THEN b.total_amount * 0.2
                            ELSE 0
                        END
                    ELSE 0
                END + 
                CASE 
                    WHEN NEW.hmo_provider IS NOT NULL AND NEW.hmo_provider != '' THEN 
                        CASE NEW.assessment_type
                            WHEN 'Charity' THEN b.total_amount * 0.2
                            WHEN 'Partial' THEN b.total_amount * 0.3
                            WHEN 'Paying' THEN b.total_amount * 0.5
                            ELSE 0
                        END
                    ELSE 0
                END)
        WHERE b.patient_id = NEW.patient_id 
        AND b.status = 'Unpaid'
        AND b.financial_assessment_id IS NULL;
    END IF;
END
$$
DELIMITER ;

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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_items`
--

INSERT INTO `inventory_items` (`id`, `item_name`, `category`, `quantity`, `threshold`, `unit`, `updated_at`, `is_archived`, `archived_at`, `archived_by`) VALUES
(1, 'Titanium Implant Rod', 'Implant', 48, 5, 'pcs', '2025-12-08 22:42:34', 0, NULL, NULL),
(2, 'Surgical Suture 3-0', 'Suture', 196, 20, 'packs', '2025-12-08 22:42:34', 0, NULL, NULL),
(3, 'Antibiotic Cefalexin', 'Medicine', 9, 10, '0', '2025-12-08 22:42:55', 0, NULL, NULL),
(4, 'Crutches (Child Size)', 'Equipment', 28, 5, '0', '2025-12-09 03:15:09', 0, NULL, NULL),
(5, 'Face Masks', 'General', 499, 50, '0', '2025-12-09 10:05:45', 0, NULL, NULL),
(6, 'Wheelchair Pediatric', 'Equipment', 9, 2, 'units', '2025-12-08 22:42:34', 0, NULL, NULL),
(7, 'Orthopedic Screws', 'Implant', 148, 15, 'pcs', '2025-12-08 22:42:34', 0, NULL, NULL),
(8, 'Gauze Pads', 'General', 297, 25, '0', '2025-12-09 10:05:49', 0, NULL, NULL),
(9, 'Orthopedic Plates', 'Implant', 75, 10, 'pcs', '2025-12-04 11:47:25', 0, NULL, NULL),
(10, 'Surgical Gloves', 'General', 850, 100, 'pairs', '2025-12-04 11:47:25', 0, NULL, NULL),
(11, 'Intravenous Fluids', 'Medicine', 45, 8, 'bags', '2025-12-04 11:47:25', 0, NULL, NULL),
(12, 'Sterile Drapes', 'General', 220, 30, 'packs', '2025-12-04 11:47:25', 0, NULL, NULL),
(13, 'Biogesic', 'Medicine', 15, 5, '0', '2025-12-09 03:14:58', 1, '2025-12-09 11:14:58', 31);

-- --------------------------------------------------------

--
-- Table structure for table `login_audit`
--

CREATE TABLE `login_audit` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `login_time` datetime DEFAULT current_timestamp(),
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `login_status` enum('Success','Failed') DEFAULT 'Success'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(5, 5, 7, 'Hip Dysplasia', 'Requires hip reconstruction surgery.', '2025-11-26 09:40:53'),
(6, 25, 3, 'UTI', NULL, '2025-12-04 11:51:13');

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
(5, 'P-1005', 'Ethan', 'Garcia', NULL, 'Male', '2013-07-18', '09123456705', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Louis Garcia', '2025-11-26 09:34:30', 1, '2025-12-09 07:05:34', 31),
(6, 'P-1006', 'Isabelle', 'Torres', NULL, 'Female', '2011-11-11', '09123456706', 'Davao del Sur', NULL, NULL, NULL, NULL, NULL, NULL, 'Janet Torres', '2025-11-26 09:34:30', 0, NULL, NULL),
(8, 'P-1008', 'Chloe', 'Martinez', NULL, 'Female', '2017-05-14', '09123456708', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Patricia Martinez', '2025-11-26 09:34:30', 0, NULL, NULL),
(20, 'P20250014', 'Dennis', 'Licayan', 'Male', NULL, '2001-02-09', '09183344144', 'Bagong buhay', 's', 'A-', 12.00, 2.00, 12, 1.00, NULL, '2025-11-27 06:22:24', 0, NULL, NULL),
(21, 'P20250015', 'Razel Joy', 'Licayans', 'Female', NULL, '2008-11-20', '09183344144', 'Davao City', 'Dennis Licayan', 'A+', 55.00, 100.00, 902, 45.00, NULL, '2025-11-27 06:29:39', 0, NULL, NULL),
(22, 'P-1010', 'Andrea', 'Lim', 'Female', 'Female', '2015-09-14', '09123456710', 'Davao City', NULL, NULL, NULL, NULL, NULL, NULL, 'Robert Lim', '2025-12-04 11:47:25', 0, NULL, NULL),
(23, 'P-1011', 'Daniel', 'Sy', 'Male', 'Male', '2016-04-08', '09123456711', 'Panabo City', NULL, NULL, NULL, NULL, NULL, NULL, 'Lily Sy', '2025-12-04 11:47:25', 0, NULL, NULL),
(24, 'P-1012', 'Mikaela', 'Ong', 'Female', 'Female', '2014-12-25', '09123456712', 'Tagum City', 's', 'B+', 22.00, 155.00, 160, 34.00, 'David Ong', '2025-12-04 11:47:25', 0, NULL, NULL),
(25, 'P20250013', 'James Paul', 'Sabuya', 'Male', NULL, '2010-02-10', '09159856712', 'Laverna Davao City', 'Kylle Sabuya', 'A-', 43.00, 172.00, 90, 37.00, NULL, '2025-12-04 11:51:13', 0, NULL, NULL),
(28, 'P20250028', 'Paulo', 'Anton', 'Male', NULL, '2004-10-18', '09155404344', 'Davao City', 'Paula Anton', 'AB-', 122.00, 160.00, 139, 70.00, NULL, '2025-12-08 08:56:09', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `patient_financial_billing_summary`
-- (See below for the actual view)
--
CREATE TABLE `patient_financial_billing_summary` (
`patient_id` int(11)
,`patient_code` varchar(20)
,`patient_name` varchar(201)
,`birth_date` date
,`gender` varchar(10)
,`financial_assessment_id` int(11)
,`assessment_type` enum('Charity','Partial','Paying')
,`financial_status` enum('Pending','Approved','Rejected')
,`philhealth_eligible` tinyint(1)
,`hmo_provider` varchar(100)
,`assessment_date` timestamp
,`billing_id` int(11)
,`total_amount` decimal(10,2)
,`philhealth_coverage` decimal(10,2)
,`hmo_coverage` decimal(10,2)
,`amount_due` decimal(10,2)
,`billing_status` enum('Unpaid','Paid')
,`billing_date` timestamp
,`paid_at` datetime
,`coverage_percentage` decimal(17,2)
,`validation_status` varchar(14)
);

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
(11, 8, 4, 'Achilles', '2026-12-09', 'room 12', 'Scheduled', 0, NULL, NULL, '2025-11-27 08:34:09'),
(12, 8, 5, 'Achilles', '2026-12-09', 'room 12', 'Scheduled', 0, NULL, NULL, '2025-12-08 08:53:22'),
(13, 24, 35, 'Feed', '2025-12-16', 'OR-25', 'Scheduled', 0, NULL, NULL, '2025-12-15 11:37:31');

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
(24, 11, 3, 5),
(25, 11, 13, 5),
(26, 11, 4, 2),
(27, 11, 5, 1),
(28, 11, 8, 3),
(29, 11, 7, 2),
(30, 11, 2, 4),
(31, 11, 1, 2),
(32, 11, 6, 1);

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1,
  `deactivated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `password`, `role`, `created_at`, `is_active`, `deactivated_at`) VALUES
(1, 'System Admin', 'admin@gmail.com', '$2y$10$YbzP7xoTF/ym2iFoxaVeNej6IyAEFrkNWVRc8SoQKISqQexqsCADS', 'Admin', '2025-11-26 08:57:08', 1, NULL),
(3, 'Dr. Mark Reyes', 'drreyes@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25', 0, '2025-11-28 11:39:26'),
(4, 'Dr. Anna Santos', 'drsantos@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25', 1, NULL),
(5, 'Dr. John Dela Cruz', 'drdelacruz@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25', 0, '2025-12-04 17:39:34'),
(6, 'Dr. Maria Tan', 'drtan@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25', 1, NULL),
(7, 'Dr. Peter Lee', 'drlee@gmail.com', '123456', 'Doctor', '2025-11-26 09:34:25', 1, NULL),
(8, 'Nurse Carla Mendoza', 'nursecarla@gmail.com', '123456', 'Nurse', '2025-11-26 09:34:25', 1, NULL),
(10, 'Staff Juan Torres', 'juan_staff@gmail.com', '123456', 'Staff', '2025-11-26 09:34:25', 1, NULL),
(11, 'Inventory Officer Paul Ramos', 'paul_inventory@gmail.com', '123456', 'Inventory', '2025-11-26 09:34:25', 1, NULL),
(12, 'Billing Officer Sarah Lim', 'sarah_billing@gmail.com', '123456', 'Billing', '2025-11-26 09:34:25', 1, NULL),
(13, 'Social Worker Joy Morales', 'joy_sw@gmail.com', '123456', 'Staff', '2025-11-26 09:34:25', 1, NULL),
(31, 'Dong Hernandezz', 'd.hernandez@gmail.com', '$2y$10$dlH6zwW517vYKNnvsaenj.TMpIAocYXb2GRTVii6aADIi/xYm4zbC', 'Admin', '2025-11-26 11:58:36', 1, NULL),
(32, 'Dennis Licayan', 'dennis@gmail.com', '$2y$10$e.E1ihXsNO2O6rSCs5gvLO5wmGRDMF2UYr0TsZ4S5CtV298bADLOG', 'Staff', '2025-12-04 09:32:25', 1, NULL),
(33, 'Louise Micko Tabarno', 'l.tabarno@gmail.com', '$2y$10$uFULf1H04sTuZpRY8Kv0qe8RxQMJUYQpBRV0e44bYp/rMYYVS1i1O', 'Staff', '2025-12-08 08:55:21', 1, NULL),
(34, 'Paul James', 'paul@gmail.com', '$2y$10$sy7epeyfO.4yWkAf3gwUwOPhQDCP4WGg5oEwbOgZDq7AFzViHmpni', 'Billing', '2025-12-09 03:31:53', 1, NULL),
(35, 'Dennis Licayan', 'd.licayan@gmail.com', '$2y$10$5xuqCgc6nrIPVeO6YDfwSOtVgGklNkAKpDU3Dgtx1aGs.wg/t9OfC', 'Doctor', '2025-12-15 11:34:04', 1, NULL);

-- --------------------------------------------------------

--
-- Structure for view `patient_financial_billing_summary`
--
DROP TABLE IF EXISTS `patient_financial_billing_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `patient_financial_billing_summary`  AS SELECT `p`.`id` AS `patient_id`, `p`.`patient_code` AS `patient_code`, concat(`p`.`first_name`,' ',`p`.`last_name`) AS `patient_name`, `p`.`birth_date` AS `birth_date`, `p`.`gender` AS `gender`, `fa`.`id` AS `financial_assessment_id`, `fa`.`assessment_type` AS `assessment_type`, `fa`.`status` AS `financial_status`, `fa`.`philhealth_eligible` AS `philhealth_eligible`, `fa`.`hmo_provider` AS `hmo_provider`, `fa`.`created_at` AS `assessment_date`, `b`.`id` AS `billing_id`, `b`.`total_amount` AS `total_amount`, `b`.`philhealth_coverage` AS `philhealth_coverage`, `b`.`hmo_coverage` AS `hmo_coverage`, `b`.`amount_due` AS `amount_due`, `b`.`status` AS `billing_status`, `b`.`created_at` AS `billing_date`, `b`.`paid_at` AS `paid_at`, CASE WHEN `b`.`total_amount` > 0 THEN round((`b`.`philhealth_coverage` + `b`.`hmo_coverage`) / `b`.`total_amount` * 100,2) ELSE 0 END AS `coverage_percentage`, CASE WHEN `fa`.`assessment_type` = 'Charity' AND `b`.`amount_due` = 0 THEN 'Matched' WHEN `fa`.`assessment_type` = 'Partial' AND `b`.`amount_due` > 0 AND `b`.`amount_due` < `b`.`total_amount` THEN 'Matched' WHEN `fa`.`assessment_type` = 'Paying' AND `b`.`amount_due` = `b`.`total_amount` THEN 'Matched' ELSE 'Check Required' END AS `validation_status` FROM ((`patients` `p` left join `financial_assessment` `fa` on(`p`.`id` = `fa`.`patient_id` and `fa`.`id` = (select max(`financial_assessment`.`id`) from `financial_assessment` where `financial_assessment`.`patient_id` = `p`.`id` and `financial_assessment`.`status` = 'Approved'))) left join `billing` `b` on(`p`.`id` = `b`.`patient_id` and `b`.`id` = (select max(`billing`.`id`) from `billing` where `billing`.`patient_id` = `p`.`id`))) WHERE `p`.`is_archived` = 0 ORDER BY `p`.`id` DESC ;

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
  ADD KEY `surgery_id` (`surgery_id`),
  ADD KEY `archived_by` (`archived_by`),
  ADD KEY `idx_billing_financial_id` (`financial_assessment_id`),
  ADD KEY `idx_billing_patient_status` (`patient_id`,`status`),
  ADD KEY `fk_billing_financial` (`financial_id`);

--
-- Indexes for table `financial_assessment`
--
ALTER TABLE `financial_assessment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `archived_by` (`archived_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `idx_financial_patient_status` (`patient_id`,`status`),
  ADD KEY `idx_financial_assessment_type` (`assessment_type`),
  ADD KEY `fk_financial_billing` (`billing_id`);

--
-- Indexes for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `archived_by` (`archived_by`);

--
-- Indexes for table `login_audit`
--
ALTER TABLE `login_audit`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `archive_logs`
--
ALTER TABLE `archive_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `financial_assessment`
--
ALTER TABLE `financial_assessment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `inventory_items`
--
ALTER TABLE `inventory_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `login_audit`
--
ALTER TABLE `login_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medical_records`
--
ALTER TABLE `medical_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `post_op_equipment`
--
ALTER TABLE `post_op_equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `surgeries`
--
ALTER TABLE `surgeries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `surgery_inventory`
--
ALTER TABLE `surgery_inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

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
  ADD CONSTRAINT `appointments_ibfk_4` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `appointments_ibfk_5` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_appointments_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`);

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
  ADD CONSTRAINT `billing_ibfk_2` FOREIGN KEY (`surgery_id`) REFERENCES `surgeries` (`id`),
  ADD CONSTRAINT `billing_ibfk_3` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `billing_ibfk_4` FOREIGN KEY (`financial_assessment_id`) REFERENCES `financial_assessment` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `billing_ibfk_5` FOREIGN KEY (`financial_assessment_id`) REFERENCES `financial_assessment` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_billing_financial` FOREIGN KEY (`financial_id`) REFERENCES `financial_assessment` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_billing_financial_assessment` FOREIGN KEY (`financial_assessment_id`) REFERENCES `financial_assessment` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_billing_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `fk_billing_surgery` FOREIGN KEY (`surgery_id`) REFERENCES `surgeries` (`id`);

--
-- Constraints for table `financial_assessment`
--
ALTER TABLE `financial_assessment`
  ADD CONSTRAINT `financial_assessment_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `financial_assessment_ibfk_2` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `financial_assessment_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `financial_assessment_ibfk_4` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `financial_assessment_ibfk_5` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_financial_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `inventory_items`
--
ALTER TABLE `inventory_items`
  ADD CONSTRAINT `inventory_items_ibfk_1` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `inventory_items_ibfk_2` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD CONSTRAINT `fk_medical_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_medical_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
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
  ADD CONSTRAINT `fk_surgeries_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_surgeries_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `surgeries_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`),
  ADD CONSTRAINT `surgeries_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `surgeries_ibfk_3` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `surgeries_ibfk_4` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `surgery_inventory`
--
ALTER TABLE `surgery_inventory`
  ADD CONSTRAINT `fk_surgery_inventory_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`),
  ADD CONSTRAINT `fk_surgery_inventory_surgery` FOREIGN KEY (`surgery_id`) REFERENCES `surgeries` (`id`),
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
