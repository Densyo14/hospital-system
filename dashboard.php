<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get current user info
$current_user_id = $_SESSION['user_id'];
$current_role = $_SESSION['role'] ?? 'Guest';
$current_name = $_SESSION['name'] ?? 'User';

// Define role permissions for navigation (keeping your existing structure)
$role_permissions = [
    'Admin' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients', 
        'appointments.php' => 'Appointments',
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'billing.php' => 'Billing',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports',
        'users.php' => 'Users',
        'triage_queue.php' => 'Triage Queue'
    ],
    'Doctor' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments', 
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports',
        'triage_queue.php' => 'Triage Queue'
    ],
    'Nurse' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports',
        'triage_queue.php' => 'Triage Queue'
    ],
    'Staff' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'reports.php' => 'Reports'
    ],
    'Inventory' => [
        'dashboard.php' => 'Dashboard',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'
    ],
    'Billing' => [
        'dashboard.php' => 'Dashboard', 
        'billing.php' => 'Billing',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'
    ],
    'SocialWorker' => [
        'dashboard.php' => 'Dashboard',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'
    ]
];

$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Get year and month from URL or use current
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : null;

// ============= ROLE-SPECIFIC DASHBOARD DATA =============

// 1. ADMIN - Sees everything
if ($current_role === 'Admin') {
    // Total counts
    $total_patients = fetchOne($conn, "SELECT COUNT(*) as total FROM patients WHERE is_archived = 0")['total'] ?? 0;
    $total_staff = fetchOne($conn, "SELECT COUNT(*) as total FROM users WHERE is_active = 1")['total'] ?? 0;
    $total_appointments = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments WHERE is_archived = 0")['total'] ?? 0;
    $total_surgeries = fetchOne($conn, "SELECT COUNT(*) as total FROM surgeries WHERE is_archived = 0")['total'] ?? 0;
    
    // Today's activity
    $today_appointments = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments WHERE DATE(schedule_datetime) = CURDATE() AND is_archived = 0")['total'] ?? 0;
    $today_surgeries = fetchOne($conn, "SELECT COUNT(*) as total FROM surgeries WHERE schedule_date = CURDATE() AND is_archived = 0")['total'] ?? 0;
    $today_triage = fetchOne($conn, "SELECT COUNT(*) as total FROM triage WHERE DATE(assessed_at) = CURDATE()")['total'] ?? 0;
    
    // Pending items
    $pending_appointments = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments WHERE status = 'Pending' AND is_archived = 0")['total'] ?? 0;
    $pending_assessments = fetchOne($conn, "SELECT COUNT(*) as total FROM financial_assessment WHERE status = 'Pending' AND is_archived = 0")['total'] ?? 0;
    $low_stock_items = fetchOne($conn, "SELECT COUNT(*) as total FROM inventory_items WHERE quantity <= threshold AND is_archived = 0")['total'] ?? 0;
    
    // Financial data
    $total_revenue = fetchOne($conn, "SELECT SUM(amount_due) as total FROM billing WHERE status = 'Paid' AND is_archived = 0")['total'] ?? 0;
    $pending_payments = fetchOne($conn, "SELECT SUM(amount_due) as total FROM billing WHERE status = 'Unpaid' AND is_archived = 0")['total'] ?? 0;
    
   // Recent activities (for timeline) - FIXED ambiguous column issue
$recent_activities = fetchAll($conn, "
    (SELECT 'appointment' as type, a.id as activity_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name, 
            a.status, a.created_at FROM appointments a
     JOIN patients p ON a.patient_id = p.id
     WHERE a.is_archived = 0
     ORDER BY a.created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'surgery' as type, s.id as activity_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            s.status, s.created_at FROM surgeries s
     JOIN patients p ON s.patient_id = p.id
     WHERE s.is_archived = 0
     ORDER BY s.created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'triage' as type, t.id as activity_id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            t.status, t.assessed_at as created_at FROM triage t
     JOIN patients p ON t.patient_id = p.id
     ORDER BY t.assessed_at DESC LIMIT 5)
    ORDER BY created_at DESC LIMIT 10
");
}

// 2. DOCTOR - Focus on patients, appointments, surgeries
elseif ($current_role === 'Doctor') {
    // My patients count
    $my_patients = fetchOne($conn, "
        SELECT COUNT(DISTINCT patient_id) as total FROM (
            SELECT patient_id FROM appointments WHERE doctor_id = ? AND is_archived = 0
            UNION
            SELECT patient_id FROM surgeries WHERE doctor_id = ? AND is_archived = 0
            UNION
            SELECT patient_id FROM medical_records WHERE doctor_id = ?
        ) as my_patients", "iii", [$current_user_id, $current_user_id, $current_user_id])['total'] ?? 0;
    
    // My today's schedule
    $my_today_appointments = fetchAll($conn, "
        SELECT a.*, p.first_name, p.last_name, p.patient_code
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        WHERE a.doctor_id = ? AND DATE(a.schedule_datetime) = CURDATE() 
        AND a.is_archived = 0
        ORDER BY a.schedule_datetime", "i", [$current_user_id]);
    
    $my_upcoming_surgeries = fetchAll($conn, "
        SELECT s.*, p.first_name, p.last_name, p.patient_code
        FROM surgeries s
        JOIN patients p ON s.patient_id = p.id
        WHERE s.doctor_id = ? AND s.schedule_date >= CURDATE() 
        AND s.is_archived = 0 AND s.status != 'Completed'
        ORDER BY s.schedule_date LIMIT 5", "i", [$current_user_id]);
    
    // Pending tasks
    $pending_appointments = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments WHERE doctor_id = ? AND status = 'Pending' AND is_archived = 0", "i", [$current_user_id])['total'] ?? 0;
    $assigned_triage = fetchOne($conn, "SELECT COUNT(*) as total FROM triage WHERE assigned_doctor = ? AND status = 'waiting'", "i", [$current_user_id])['total'] ?? 0;
    
    // Recent medical records I created
    $recent_records = fetchAll($conn, "
        SELECT mr.*, p.first_name, p.last_name
        FROM medical_records mr
        JOIN patients p ON mr.patient_id = p.id
        WHERE mr.doctor_id = ?
        ORDER BY mr.created_at DESC LIMIT 5", "i", [$current_user_id]);
}

// 3. NURSE - Focus on triage, appointments, inventory
elseif ($current_role === 'Nurse') {
    // Triage queue
    $triage_waiting = fetchOne($conn, "SELECT COUNT(*) as total FROM triage WHERE status = 'waiting'")['total'] ?? 0;
    $triage_in_consultation = fetchOne($conn, "SELECT COUNT(*) as total FROM triage WHERE status = 'in_consultation'")['total'] ?? 0;
    
    // Critical patients in triage (severity 4-5)
    $critical_triage = fetchAll($conn, "
        SELECT t.*, p.first_name, p.last_name, p.patient_code
        FROM triage t
        JOIN patients p ON t.patient_id = p.id
        WHERE t.severity >= 4 AND t.status IN ('waiting', 'in_consultation')
        ORDER BY t.severity DESC, t.assessed_at ASC LIMIT 5");
    
    // Today's appointments
    $today_appointments = fetchAll($conn, "
        SELECT a.*, p.first_name, p.last_name, p.patient_code, u.full_name as doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON a.doctor_id = u.id
        WHERE DATE(a.schedule_datetime) = CURDATE() AND a.is_archived = 0
        ORDER BY a.schedule_datetime");
    
    // Inventory alerts
    $low_stock_alerts = fetchAll($conn, "
        SELECT * FROM inventory_items 
        WHERE quantity <= threshold AND is_archived = 0
        ORDER BY quantity ASC LIMIT 5");
}

// 4. STAFF - Focus on patient registration, appointments
elseif ($current_role === 'Staff') {
    // Today's registrations
    $today_registrations = fetchOne($conn, "SELECT COUNT(*) as total FROM patients WHERE DATE(created_at) = CURDATE() AND is_archived = 0")['total'] ?? 0;
    
    // Recent patients registered
    $recent_patients = fetchAll($conn, "
        SELECT * FROM patients 
        WHERE is_archived = 0 
        ORDER BY created_at DESC LIMIT 5");
    
    // Today's appointments for front desk
    $today_appointments = fetchAll($conn, "
        SELECT a.*, p.first_name, p.last_name, p.patient_code, u.full_name as doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN users u ON a.doctor_id = u.id
        WHERE DATE(a.schedule_datetime) = CURDATE() AND a.is_archived = 0
        ORDER BY a.schedule_datetime");
    
    // Pending tasks
    $pending_appointments_tomorrow = fetchOne($conn, "
        SELECT COUNT(*) as total FROM appointments 
        WHERE DATE(schedule_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) 
        AND is_archived = 0")['total'] ?? 0;
}

// 5. INVENTORY - Focus on stock management
elseif ($current_role === 'Inventory') {
    // Stock statistics
    $total_items = fetchOne($conn, "SELECT COUNT(*) as total FROM inventory_items WHERE is_archived = 0")['total'] ?? 0;
    $low_stock_count = fetchOne($conn, "SELECT COUNT(*) as total FROM inventory_items WHERE quantity <= threshold AND is_archived = 0")['total'] ?? 0;
    $out_of_stock = fetchOne($conn, "SELECT COUNT(*) as total FROM inventory_items WHERE quantity = 0 AND is_archived = 0")['total'] ?? 0;
    
    // Items needing reorder
    $items_to_reorder = fetchAll($conn, "
        SELECT * FROM inventory_items 
        WHERE quantity <= threshold AND is_archived = 0
        ORDER BY (quantity - threshold) ASC LIMIT 10");
    
    // Recent inventory updates
    $recent_updates = fetchAll($conn, "
        SELECT * FROM inventory_items 
        WHERE is_archived = 0 
        ORDER BY updated_at DESC LIMIT 5");
    
    // Category distribution
    $category_counts = fetchAll($conn, "
        SELECT category, COUNT(*) as count, SUM(quantity) as total_quantity
        FROM inventory_items 
        WHERE is_archived = 0
        GROUP BY category");
}

// 6. BILLING - Focus on financials
elseif ($current_role === 'Billing') {
    // Financial overview
    $today_collections = fetchOne($conn, "
        SELECT SUM(amount_due) as total FROM billing 
        WHERE DATE(paid_at) = CURDATE() AND status = 'Paid' AND is_archived = 0")['total'] ?? 0;
    
    $month_collections = fetchOne($conn, "
        SELECT SUM(amount_due) as total FROM billing 
        WHERE MONTH(paid_at) = MONTH(CURDATE()) AND YEAR(paid_at) = YEAR(CURDATE()) 
        AND status = 'Paid' AND is_archived = 0")['total'] ?? 0;
    
    $pending_bills = fetchOne($conn, "
        SELECT COUNT(*) as total, SUM(amount_due) as total_amount 
        FROM billing WHERE status = 'Unpaid' AND is_archived = 0")[0] ?? ['total' => 0, 'total_amount' => 0];
    
    // Recent unpaid bills
    $recent_unpaid = fetchAll($conn, "
        SELECT b.*, p.first_name, p.last_name, p.patient_code
        FROM billing b
        JOIN patients p ON b.patient_id = p.id
        WHERE b.status = 'Unpaid' AND b.is_archived = 0
        ORDER BY b.created_at DESC LIMIT 10");
    
    // Recent payments
    $recent_payments = fetchAll($conn, "
        SELECT b.*, p.first_name, p.last_name, p.patient_code
        FROM billing b
        JOIN patients p ON b.patient_id = p.id
        WHERE b.status = 'Paid' AND b.paid_at IS NOT NULL AND b.is_archived = 0
        ORDER BY b.paid_at DESC LIMIT 5");
}

// 7. SOCIAL WORKER - Focus on financial assessments
elseif ($current_role === 'SocialWorker') {
    // Assessment overview
    $pending_assessments = fetchOne($conn, "
        SELECT COUNT(*) as total FROM financial_assessment 
        WHERE status = 'Pending' AND is_archived = 0")['total'] ?? 0;
    
    $approved_assessments = fetchOne($conn, "
        SELECT COUNT(*) as total FROM financial_assessment 
        WHERE status = 'Approved' AND is_archived = 0")['total'] ?? 0;
    
    // Patients needing assessment
    $patients_needing_assessment = fetchAll($conn, "
        SELECT p.*, 
               (SELECT COUNT(*) FROM billing WHERE patient_id = p.id AND status = 'Unpaid') as unpaid_bills
        FROM patients p
        WHERE p.is_archived = 0 
        AND NOT EXISTS (
            SELECT 1 FROM financial_assessment fa 
            WHERE fa.patient_id = p.id AND fa.status = 'Approved'
        )
        AND EXISTS (
            SELECT 1 FROM billing b WHERE b.patient_id = p.id AND b.status = 'Unpaid'
        )
        ORDER BY p.created_at DESC LIMIT 10");
    
    // Recent assessments
    $recent_assessments = fetchAll($conn, "
        SELECT fa.*, p.first_name, p.last_name, p.patient_code, u.full_name as reviewer_name
        FROM financial_assessment fa
        JOIN patients p ON fa.patient_id = p.id
        LEFT JOIN users u ON fa.reviewed_by = u.id
        WHERE fa.is_archived = 0
        ORDER BY fa.created_at DESC LIMIT 5");
}

// Get patient statistics for the graph (for all roles)
$patient_stats_query = "
    SELECT 
        MONTH(created_at) as month,
        COUNT(*) as total_patients,
        COUNT(CASE WHEN EXISTS (
            SELECT 1 FROM surgeries WHERE surgeries.patient_id = patients.id 
            AND surgeries.status = 'Completed'
        ) THEN 1 END) as surgical_patients
    FROM patients 
    WHERE YEAR(created_at) = ? AND is_archived = 0
    GROUP BY MONTH(created_at)
    ORDER BY month
";
$patient_stats = fetchAll($conn, $patient_stats_query, 'i', [$current_year]);

// Prepare chart data
$monthly_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthly_totals = array_fill(0, 12, 0);
$monthly_surgical = array_fill(0, 12, 0);

foreach ($patient_stats as $stat) {
    $month_index = $stat['month'] - 1;
    $monthly_totals[$month_index] = $stat['total_patients'];
    $monthly_surgical[$month_index] = $stat['surgical_patients'];
}

// Get available years for filter dropdown
$available_years = fetchAll($conn, "SELECT DISTINCT YEAR(created_at) as year FROM patients WHERE created_at IS NOT NULL ORDER BY year DESC");
$available_years = array_column($available_years, 'year');
if (empty($available_years)) {
    $available_years = [date('Y')];
}

// Get month names
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Helper function to get status badge class
function getStatusBadge($status) {
    $classes = [
        'Pending' => 'badge-warning',
        'Approved' => 'badge-info',
        'Completed' => 'badge-success',
        'Cancelled' => 'badge-danger',
        'Scheduled' => 'badge-warning',
        'Paid' => 'badge-success',
        'Unpaid' => 'badge-warning',
        'waiting' => 'badge-warning',
        'in_consultation' => 'badge-info'
    ];
    return $classes[$status] ?? 'badge-secondary';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Dashboard - <?php echo $current_role; ?> View | Hospital System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ========== VARIABLES ========== */
        :root {
            --bg: #eef3f7;
            --panel: #ffffff;
            --muted: #6b7280;
            --navy-700: #001F3F;
            --accent: #003366;
            --sidebar: #002855;
            --light-blue: #4d8cc9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --card-shadow: 0 6px 22px rgba(16,24,40,0.06);
        }

        /* ========== RESET & BASE ========== */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: #0f1724;
            line-height: 1.5;
        }

        .app {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles - Matching other files */
/* Sidebar Styles - Matching other files EXACTLY */
.sidebar {
    width: 230px; /* Changed from 260px to 230px */
    background: linear-gradient(180deg, var(--sidebar), #001a33 120%);
    color: #eaf5ff;
    padding: 18px 15px; /* Matching your original padding */
    display: flex;
    flex-direction: column;
    gap: 14px; /* Matching your original gap */
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    overflow-y: auto;
    z-index: 30;
}

/* Main content margin should match sidebar width */
.main {
    margin-left: 230px; /* This must match sidebar width */
    padding: 18px 28px;
    width: 100%;
    transition: margin-left 0.22s ease;
}

/* Logo wrap - matching original */
.logo-wrap {
    display: flex;
    justify-content: center;
}

.logo-wrap img {
    width: 150px;
    height: auto;
}

/* User info - matching original */
.user-info{
    background:rgba(255,255,255,0.1);
    border-radius:8px;
    padding:10px;
    border-left:3px solid #9bcfff;
    font-size:13px;
  }
  .user-info h4{ margin:0 0 4px 0; color:#9bcfff; font-size:13px; }
  .user-info p{ margin:0; font-size:12px; color:rgba(255,255,255,0.9); }
  .menu{ margin-top:8px; display:flex; flex-direction:column; gap:6px; }
  .menu-item{
    display:flex;
    align-items:center;
    gap:10px;
    padding:9px 7px;
    border-radius:8px;
    color:rgba(255,255,255,0.95);
    font-weight:500;
    text-decoration:none;
    font-size:14px;
    transition:all 0.2s;
  }

/* Menu - matching original */
.menu {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 10px; /* Original gap */
    padding: 9px 7px; /* Original padding */
    border-radius: 8px;
    color: rgba(255,255,255,0.95);
    font-weight: 500;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s;
}

.menu-item:hover {
    background: linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
}

.menu-item.active {
    background: linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
    border-left: 4px solid #9bcfff;
    padding-left: 5px; /* Original active padding */
}

.menu-item .icon {
    width: 16px; /* Original icon size */
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.menu-item .icon svg {
    width: 100%;
    height: 100%;
    fill: white;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 15px; /* Original padding */
    border-top: 1px solid rgba(255,255,255,0.1);
}
        /* ========== MAIN CONTENT ========== */
      .main {
    margin-left: 230px; /* Must match sidebar width */
    padding: 18px 28px; /* Matching your original padding */
    width: 100%;
    transition: margin-left 0.22s ease;
}
        /* ========== TOP BAR ========== */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .top-left h1 {
            font-size: 24px;
            margin: 0;
            font-weight: 700;
            color: var(--navy-700);
        }

        .top-left p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn {
            background: var(--navy-700);
            color: #fff;
            padding: 10px 18px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .btn:hover {
            background: var(--accent);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
        }

        .btn-outline {
            background: transparent;
            color: var(--navy-700);
            border: 1px solid var(--navy-700);
        }

        .btn-outline:hover {
            background: rgba(0, 31, 63, 0.1);
        }

        .date-pill {
            background: var(--panel);
            padding: 10px 18px;
            border-radius: 999px;
            box-shadow: var(--card-shadow);
            font-size: 14px;
            white-space: nowrap;
            border: 1px solid #e6eef0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        .role-admin { background: var(--navy-700); color: white; }
        .role-doctor { background: #003366; color: white; }
        .role-nurse { background: #4d8cc9; color: white; }
        .role-staff { background: #6b7280; color: white; }
        .role-inventory { background: #1e6b8a; color: white; }
        .role-billing { background: #0066cc; color: white; }
        .role-socialworker { background: #34495e; color: white; }

        /* ========== WELCOME SECTION ========== */
        .welcome-section {
            background: linear-gradient(135deg, var(--navy-700) 0%, var(--accent) 100%);
            border-radius: 16px;
            padding: 32px;
            color: white;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
        }

        .welcome-section::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="rgba(255,255,255,0.05)"><path d="M20 20 L80 20 L80 80 L20 80 Z" stroke="white" stroke-width="2" fill="none"/><circle cx="50" cy="50" r="15" stroke="white" stroke-width="2" fill="none"/></svg>') repeat;
            opacity: 0.1;
        }

        .welcome-section h2 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .welcome-section p {
            font-size: 16px;
            opacity: 0.9;
            max-width: 600px;
        }

        .quick-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .quick-action-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            backdrop-filter: blur(5px);
        }

        .quick-action-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* ========== KPI CARDS ========== */
        .cards-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .kcard {
            background: var(--panel);
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            border: 1px solid #f0f4f8;
        }

        .kcard:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(16,24,40,0.12);
            border-color: var(--light-blue);
        }

        .kcard-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .kcard h4 {
            margin: 0;
            color: var(--muted);
            font-weight: 500;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kcard .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--navy-700);
            line-height: 1.2;
            margin-bottom: 8px;
        }

        .kcard .sub-text {
            font-size: 13px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .kcard .sub-text i {
            font-size: 12px;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        .kicon {
            width: 48px;
            height: 48px;
            background: rgba(77, 140, 201, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--light-blue);
            font-size: 24px;
        }

        /* ========== SECTION TITLES ========== */
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--navy-700);
            margin: 32px 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title a {
            font-size: 14px;
            font-weight: 400;
            color: var(--light-blue);
            text-decoration: none;
        }

        .section-title a:hover {
            text-decoration: underline;
        }

        /* ========== GRID LAYOUTS ========== */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .dashboard-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .panel {
            background: var(--panel);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid #f0f4f8;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .panel-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            color: var(--navy-700);
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-warning { background: #fff8e1; color: #8a6d00; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-info { background: #e8f4ff; color: #1e6b8a; }

        /* ========== LISTS ========== */
        .list-item {
            padding: 16px 0;
            border-bottom: 1px solid #f0f4f8;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-item:hover {
            background: #f8fbfd;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .item-title {
            font-weight: 600;
            color: #233;
        }

        .item-subtitle {
            font-size: 13px;
            color: var(--muted);
            margin-top: 4px;
        }

        .item-meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            color: var(--muted);
            margin-top: 8px;
        }

        .item-meta i {
            margin-right: 4px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        /* ========== TABLES ========== */
        .table-wrap {
            background: var(--panel);
            border-radius: 16px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid #f0f4f8;
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            border-bottom: 2px solid #e6eef0;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #f0f3f4;
            font-size: 14px;
        }

        tr {
            cursor: pointer;
            transition: background 0.2s ease;
        }

        tr:hover {
            background: #f8fbfd;
        }

        /* ========== GRAPH BOX ========== */
        .graph-box {
            height: 300px;
            margin-top: 16px;
        }

        .graph-controls {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .filter-select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e6eef0;
            font-size: 14px;
        }

        /* ========== INVENTORY GRID ========== */
        .inventory-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .inventory-item {
            background: #f8fbfd;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e6eef0;
        }

        .inventory-item h4 {
            margin: 0 0 8px;
            font-size: 14px;
            color: var(--navy-700);
        }

        .inventory-item .quantity {
            font-size: 24px;
            font-weight: 700;
            color: var(--navy-700);
        }

        .inventory-item .threshold {
            font-size: 12px;
            color: var(--muted);
        }

        .progress-bar {
            height: 6px;
            background: #e6eef0;
            border-radius: 3px;
            margin: 12px 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--light-blue);
            border-radius: 3px;
        }

        /* ========== PAGINATION ========== */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .pagination a, .pagination span {
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--navy-700);
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .pagination a:hover {
            background: rgba(0, 31, 63, 0.1);
        }

        .pagination .current {
            background: var(--navy-700);
            color: white;
        }

        /* ========== MODAL STYLES (keeping your existing) ========== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            backdrop-filter: blur(2px);
        }

        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            z-index: 1001;
            width: 90%;
            max-width: 900px;
            max-height: 80vh;
            overflow: hidden;
        }

        .modal-header {
            padding: 20px 24px;
            background: var(--navy-700);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }

        .modal-content {
            padding: 24px;
            max-height: calc(80vh - 70px);
            overflow-y: auto;
        }

        .modal-tabs {
            display: flex;
            border-bottom: 1px solid #e6eef0;
            margin-bottom: 20px;
        }

        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--muted);
        }

        .tab-btn.active {
            color: var(--navy-700);
            border-bottom-color: var(--navy-700);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 1200px) {
            .dashboard-grid,
            .dashboard-grid-3 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main {
                margin-left: 0;
                padding: 16px;
            }
            
            .topbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .top-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .cards-row {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                padding: 24px;
            }
            
            .quick-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR - Updated to match other files -->
<aside class="sidebar">
    <div class="logo-wrap">
        <a href="dashboard.php"><img src="logo.jpg" alt="Seamen's Cure Logo"></a>
    </div>

    <div class="user-info">
        <h4>Logged as:</h4>
        <p>
            <strong><?php echo htmlspecialchars($current_name); ?></strong><br>
            <span style="color: #9bcfff;"><?php echo htmlspecialchars($current_role); ?></span>
        </p>
    </div>

    <nav class="menu">
        <a href="dashboard.php" class="menu-item active">
            <span class="icon">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                </svg>
            </span>
            <span class="label">Dashboard</span>
        </a>
        
        <?php foreach($allowed_pages as $page => $label): ?>
            <?php if($page !== 'dashboard.php'): ?>
                <a href="<?php echo $page; ?>" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == $page ? 'active' : ''; ?>">
                    <span class="icon">
                        <?php 
                            $icons = [
                                'patients.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
                                'appointments.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>',
                                'surgeries.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
                                'inventory.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 8h-3V4H7v4H4v14h16V8zM9 6h6v2H9V6zm11 14H4v-9h16v9zm-7-7H8v-2h5v2z"/></svg>',
                                'billing.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>',
                                'financials.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
                                'reports.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>',
                                'users.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
                                'triage_queue.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
                            ];
                            echo $icons[$page] ?? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>';
                        ?>
                    </span>
                    <span class="label"><?php echo $label; ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <a href="logout.php" class="menu-item">
            <span class="icon">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
            </span>
            <span class="label">Logout</span>
        </a>
    </div>
</aside>
    <!-- MAIN CONTENT -->
    <div class="main">
        <!-- Top Bar -->
        <div class="topbar">
            <div class="top-left">
                <h1>
                    Dashboard
                    <span class="role-badge role-<?php echo strtolower($current_role); ?>">
                        <?php echo htmlspecialchars($current_role); ?> View
                    </span>
                </h1>
                <p>Welcome back, <?php echo htmlspecialchars($current_name); ?>! Here's what's happening today.</p>
            </div>

            <div class="top-actions">
                <a href="patients.php?action=add" class="btn">
                    <i class="fas fa-plus"></i> New Patient
                </a>
                <div class="date-pill">
                    <i class="far fa-calendar-alt"></i>
                    <?php echo date('l, F j, Y'); ?>
                </div>
            </div>
        </div>

        <!-- Welcome Section with Quick Actions -->
        <div class="welcome-section">
            <h2>Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 18 ? 'Afternoon' : 'Evening'); ?>, <?php echo htmlspecialchars($current_name); ?>!</h2>
            <p>
                <?php
                if ($current_role === 'Admin') {
                    echo "You have full access to manage the hospital system. Monitor all activities and generate reports.";
                } elseif ($current_role === 'Doctor') {
                    echo "You have {$my_patients} patients under your care. Check your schedule and pending appointments.";
                } elseif ($current_role === 'Nurse') {
                    echo "There are {$triage_waiting} patients waiting in triage. {$triage_in_consultation} currently in consultation.";
                } elseif ($current_role === 'Staff') {
                    echo "You've registered {$today_registrations} patients today. Stay on top of new registrations.";
                } elseif ($current_role === 'Inventory') {
                    echo "You have {$low_stock_count} items that need reordering. Check the inventory alerts.";
                } elseif ($current_role === 'Billing') {
                    echo "Today's collections: ₱" . number_format($today_collections, 2) . ". Keep up the good work!";
                } elseif ($current_role === 'SocialWorker') {
                    echo "You have {$pending_assessments} pending financial assessments. Review them soon.";
                }
                ?>
            </p>
            <div class="quick-actions">
                <?php if ($current_role === 'Admin'): ?>
                    <a href="reports.php" class="quick-action-btn"><i class="fas fa-chart-bar"></i> View Reports</a>
                    <a href="users.php?action=add" class="quick-action-btn"><i class="fas fa-user-plus"></i> Add User</a>
                <?php elseif ($current_role === 'Doctor'): ?>
                    <a href="appointments.php?filter=today" class="quick-action-btn"><i class="fas fa-calendar-day"></i> Today's Schedule</a>
                    <a href="triage_queue.php" class="quick-action-btn"><i class="fas fa-clock"></i> Triage Queue</a>
                <?php elseif ($current_role === 'Nurse'): ?>
                    <a href="triage_queue.php" class="quick-action-btn"><i class="fas fa-clock"></i> Manage Triage</a>
                    <a href="inventory.php?filter=low_stock" class="quick-action-btn"><i class="fas fa-exclamation-triangle"></i> Check Inventory</a>
                <?php elseif ($current_role === 'Staff'): ?>
                    <a href="patients.php?action=add" class="quick-action-btn"><i class="fas fa-user-plus"></i> Register Patient</a>
                    <a href="appointments.php?action=add" class="quick-action-btn"><i class="fas fa-calendar-plus"></i> Schedule Appointment</a>
                <?php elseif ($current_role === 'Inventory'): ?>
                    <a href="inventory.php?action=add" class="quick-action-btn"><i class="fas fa-plus-circle"></i> Add Item</a>
                    <a href="reports.php?type=inventory" class="quick-action-btn"><i class="fas fa-file-alt"></i> Inventory Report</a>
                <?php elseif ($current_role === 'Billing'): ?>
                    <a href="billing.php?status=Unpaid" class="quick-action-btn"><i class="fas fa-hourglass-half"></i> Pending Bills</a>
                    <a href="financials.php" class="quick-action-btn"><i class="fas fa-chart-line"></i> Financial Overview</a>
                <?php elseif ($current_role === 'SocialWorker'): ?>
                    <a href="financials.php?status=Pending" class="quick-action-btn"><i class="fas fa-clipboard-check"></i> Pending Assessments</a>
                    <a href="patients.php" class="quick-action-btn"><i class="fas fa-users"></i> View Patients</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========== ROLE-SPECIFIC DASHBOARD CONTENT ========== -->

        <!-- ADMIN DASHBOARD -->
        <?php if ($current_role === 'Admin'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="patients.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Total Patients</h4>
                        <div class="kicon"><i class="fas fa-user-injured"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($total_patients); ?></div>
                    <div class="sub-text">
                        <i class="fas fa-arrow-up trend-up"></i> +12% from last month
                    </div>
                </a>
                <a href="appointments.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Today's Appointments</h4>
                        <div class="kicon"><i class="fas fa-calendar-check"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($today_appointments); ?></div>
                    <div class="sub-text">
                        <i class="fas fa-clock"></i> <?php echo $pending_appointments; ?> pending
                    </div>
                </a>
                <a href="surgeries.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Today's Surgeries</h4>
                        <div class="kicon"><i class="fas fa-procedures"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($today_surgeries); ?></div>
                    <div class="sub-text">
                        <i class="fas fa-calendar"></i> <?php echo $total_surgeries; ?> total scheduled
                    </div>
                </a>
                <a href="billing.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Revenue</h4>
                        <div class="kicon"><i class="fas fa-coins"></i></div>
                    </div>
                    <div class="value">₱<?php echo number_format($total_revenue, 2); ?></div>
                    <div class="sub-text">
                        <i class="fas fa-hourglass-half"></i> ₱<?php echo number_format($pending_payments, 2); ?> pending
                    </div>
                </a>
            </div>

            <!-- Main Grid -->
            <div class="dashboard-grid">
                <!-- Left Column - Patient Statistics -->
                <div class="panel">
                    <div class="panel-header">
                        <h3>Patient Statistics - <?php echo $current_year; ?></h3>
                        <div class="graph-controls">
                            <select id="yearFilter" class="filter-select">
                                <?php foreach($available_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $current_year == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button id="applyFilter" class="btn" style="padding: 8px 16px;">Apply</button>
                        </div>
                    </div>
                    <div class="graph-box">
                        <canvas id="patientsChart"></canvas>
                    </div>
                </div>

                

            <!-- Alerts Section -->
            <div class="dashboard-grid-3">
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i> Pending Approvals</h3>
                    </div>
                    <div>
                        <div class="list-item" onclick="window.location.href='appointments.php?status=Pending'">
                            <div class="item-title">Appointments: <?php echo $pending_appointments; ?></div>
                            <div class="item-subtitle">Awaiting approval</div>
                        </div>
                        <div class="list-item" onclick="window.location.href='financials.php?status=Pending'">
                            <div class="item-title">Financial Assessments: <?php echo $pending_assessments; ?></div>
                            <div class="item-subtitle">Needs review</div>
                        </div>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-boxes" style="color: var(--info);"></i> Low Stock Alerts</h3>
                    </div>
                    <div>
                        <div class="list-item" onclick="window.location.href='inventory.php?filter=low_stock'">
                            <div class="item-title">Items Low in Stock: <?php echo $low_stock_items; ?></div>
                            <div class="item-subtitle">Reorder soon</div>
                        </div>
                    </div>
                </div>
                
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-users" style="color: var(--success);"></i> Staff Overview</h3>
                    </div>
                    <div>
                        <div class="list-item" onclick="window.location.href='users.php'">
                            <div class="item-title">Active Staff: <?php echo $total_staff; ?></div>
                            <div class="item-subtitle">Across all departments</div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- DOCTOR DASHBOARD -->
        <?php elseif ($current_role === 'Doctor'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="patients.php" class="kcard">
                    <div class="kcard-header">
                        <h4>My Patients</h4>
                        <div class="kicon"><i class="fas fa-user-md"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($my_patients); ?></div>
                </a>
                <a href="appointments.php?status=Pending" class="kcard">
                    <div class="kcard-header">
                        <h4>Pending Appointments</h4>
                        <div class="kicon"><i class="fas fa-clock"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($pending_appointments); ?></div>
                </a>
                <a href="triage_queue.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Patients in Triage</h4>
                        <div class="kicon"><i class="fas fa-ambulance"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($assigned_triage); ?></div>
                </a>
                <a href="surgeries.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Upcoming Surgeries</h4>
                        <div class="kicon"><i class="fas fa-procedures"></i></div>
                    </div>
                    <div class="value"><?php echo count($my_upcoming_surgeries); ?></div>
                </a>
            </div>

            <!-- Today's Schedule -->
            <div class="dashboard-grid">
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-calendar-day"></i> Today's Appointments</h3>
                        <a href="appointments.php?filter=today">View All</a>
                    </div>
                    <?php if (!empty($my_today_appointments)): ?>
                        <?php foreach($my_today_appointments as $appt): ?>
                            <div class="list-item" onclick="window.location.href='appointment_view.php?id=<?php echo $appt['id']; ?>'">
                                <div class="item-header">
                                    <span class="item-title"><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></span>
                                    <span class="status-badge <?php echo getStatusBadge($appt['status']); ?>">
                                        <?php echo $appt['status']; ?>
                                    </span>
                                </div>
                                <div class="item-subtitle">
                                    <i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($appt['schedule_datetime'])); ?> • 
                                    <?php echo htmlspecialchars($appt['reason']); ?>
                                </div>
                                <div class="item-meta">
                                    <span><i class="fas fa-id-card"></i> <?php echo $appt['patient_code']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--muted);">
                            <i class="far fa-calendar-check" style="font-size: 48px; margin-bottom: 16px;"></i>
                            <p>No appointments scheduled for today</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Surgeries</h3>
                        <a href="surgeries.php">View All</a>
                    </div>
                    <?php if (!empty($my_upcoming_surgeries)): ?>
                        <?php foreach($my_upcoming_surgeries as $surgery): ?>
                            <div class="list-item" onclick="window.location.href='surgery_view.php?id=<?php echo $surgery['id']; ?>'">
                                <div class="item-header">
                                    <span class="item-title"><?php echo htmlspecialchars($surgery['first_name'] . ' ' . $surgery['last_name']); ?></span>
                                    <span class="status-badge <?php echo getStatusBadge($surgery['status']); ?>">
                                        <?php echo $surgery['status']; ?>
                                    </span>
                                </div>
                                <div class="item-subtitle">
                                    <i class="fas fa-calendar"></i> <?php echo date('M j, Y', strtotime($surgery['schedule_date'])); ?> • 
                                    <?php echo htmlspecialchars($surgery['surgery_type']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--muted);">
                            <i class="fas fa-procedures" style="font-size: 48px; margin-bottom: 16px;"></i>
                            <p>No upcoming surgeries</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Medical Records -->
            <div class="panel" style="margin-top: 24px;">
                <div class="panel-header">
                    <h3><i class="fas fa-notes-medical"></i> Recent Medical Records</h3>
                    <a href="medical_records.php">View All</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Diagnosis</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_records as $record): ?>
                            <tr onclick="window.location.href='medical_record_view.php?id=<?php echo $record['id']; ?>'">
                                <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($record['diagnosis']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($record['created_at'])); ?></td>
                                <td>
                                    <span class="badge badge-info">View</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- NURSE DASHBOARD -->
        <?php elseif ($current_role === 'Nurse'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="triage_queue.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Waiting in Triage</h4>
                        <div class="kicon"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($triage_waiting); ?></div>
                </a>
                <a href="triage_queue.php?status=in_consultation" class="kcard">
                    <div class="kcard-header">
                        <h4>In Consultation</h4>
                        <div class="kicon"><i class="fas fa-user-md"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($triage_in_consultation); ?></div>
                </a>
                <a href="appointments.php?filter=today" class="kcard">
                    <div class="kcard-header">
                        <h4>Today's Appointments</h4>
                        <div class="kicon"><i class="fas fa-calendar-check"></i></div>
                    </div>
                    <div class="value"><?php echo count($today_appointments); ?></div>
                </a>
                <a href="inventory.php?filter=low_stock" class="kcard">
                    <div class="kcard-header">
                        <h4>Low Stock Alerts</h4>
                        <div class="kicon"><i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i></div>
                    </div>
                    <div class="value"><?php echo count($low_stock_alerts); ?></div>
                </a>
            </div>

            <!-- Critical Triage Patients -->
            <div class="panel" style="margin-bottom: 24px;">
                <div class="panel-header">
                    <h3><i class="fas fa-exclamation-circle" style="color: var(--danger);"></i> Critical Patients (Severity 4-5)</h3>
                    <a href="triage_queue.php?severity=high">View All</a>
                </div>
                <?php if (!empty($critical_triage)): ?>
                    <?php foreach($critical_triage as $patient): ?>
                        <div class="list-item" onclick="window.location.href='triage_view.php?id=<?php echo $patient['id']; ?>'">
                            <div class="item-header">
                                <span class="item-title"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></span>
                                <span class="status-badge badge-danger">Severity <?php echo $patient['severity']; ?>/5</span>
                            </div>
                            <div class="item-subtitle">
                                <i class="fas fa-stethoscope"></i> <?php echo htmlspecialchars($patient['chief_complaint']); ?>
                            </div>
                            <div class="item-meta">
                                <span><i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($patient['assessed_at'])); ?></span>
                                <span><i class="fas fa-temperature-high"></i> <?php echo $patient['temperature'] ?? 'N/A'; ?>°C</span>
                                <span><i class="fas fa-heartbeat"></i> <?php echo $patient['heart_rate'] ?? 'N/A'; ?> bpm</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--muted);">
                        <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px;"></i>
                        <p>No critical patients in triage</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="dashboard-grid">
                <!-- Today's Appointments -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-calendar-day"></i> Today's Appointments</h3>
                        <a href="appointments.php?filter=today">View All</a>
                    </div>
                    <?php if (!empty($today_appointments)): ?>
                        <?php foreach(array_slice($today_appointments, 0, 5) as $appt): ?>
                            <div class="list-item" onclick="window.location.href='appointment_view.php?id=<?php echo $appt['id']; ?>'">
                                <div class="item-header">
                                    <span class="item-title"><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></span>
                                    <span class="status-badge <?php echo getStatusBadge($appt['status']); ?>">
                                        <?php echo $appt['status']; ?>
                                    </span>
                                </div>
                                <div class="item-subtitle">
                                    <i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($appt['schedule_datetime'])); ?> • 
                                    Dr. <?php echo htmlspecialchars($appt['doctor_name']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: var(--muted);">
                            No appointments today
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Low Stock Alerts -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-boxes"></i> Low Stock Items</h3>
                        <a href="inventory.php">Manage</a>
                    </div>
                    <?php if (!empty($low_stock_alerts)): ?>
                        <?php foreach($low_stock_alerts as $item): ?>
                            <div class="list-item" onclick="window.location.href='inventory.php?action=edit&id=<?php echo $item['id']; ?>'">
                                <div class="item-header">
                                    <span class="item-title"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                    <span class="status-badge badge-warning">Low Stock</span>
                                </div>
                                <div class="item-subtitle">
                                    Quantity: <?php echo $item['quantity']; ?> / Threshold: <?php echo $item['threshold']; ?>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(100, ($item['quantity'] / $item['threshold']) * 100); ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: var(--muted);">
                            All items are well-stocked
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- STAFF DASHBOARD -->
        <?php elseif ($current_role === 'Staff'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="patients.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Today's Registrations</h4>
                        <div class="kicon"><i class="fas fa-user-plus"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($today_registrations); ?></div>
                </a>
                <a href="appointments.php?filter=today" class="kcard">
                    <div class="kcard-header">
                        <h4>Today's Appointments</h4>
                        <div class="kicon"><i class="fas fa-calendar-check"></i></div>
                    </div>
                    <div class="value"><?php echo count($today_appointments); ?></div>
                </a>
                <a href="appointments.php?date=<?php echo date('Y-m-d', strtotime('+1 day')); ?>" class="kcard">
                    <div class="kcard-header">
                        <h4>Tomorrow's Appointments</h4>
                        <div class="kicon"><i class="fas fa-calendar-plus"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($pending_appointments_tomorrow); ?></div>
                </a>
            </div>

            <div class="dashboard-grid">
                <!-- Recent Patients -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-user-injured"></i> Recently Registered Patients</h3>
                        <a href="patients.php">View All</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Patient Code</th>
                                <th>Name</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_patients as $patient): ?>
                                <tr onclick="window.location.href='patient_view.php?id=<?php echo $patient['id']; ?>'">
                                    <td><?php echo htmlspecialchars($patient['patient_code']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></td>
                                    <td><?php echo date('g:i A', strtotime($patient['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Today's Schedule -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-calendar-day"></i> Today's Schedule</h3>
                        <a href="appointments.php?filter=today">View All</a>
                    </div>
                    <?php if (!empty($today_appointments)): ?>
                        <?php foreach($today_appointments as $appt): ?>
                            <div class="list-item" onclick="window.location.href='appointment_view.php?id=<?php echo $appt['id']; ?>'">
                                <div class="item-header">
                                    <span class="item-title"><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></span>
                                    <span class="status-badge <?php echo getStatusBadge($appt['status']); ?>">
                                        <?php echo $appt['status']; ?>
                                    </span>
                                </div>
                                <div class="item-subtitle">
                                    <i class="far fa-clock"></i> <?php echo date('g:i A', strtotime($appt['schedule_datetime'])); ?> • 
                                    Dr. <?php echo htmlspecialchars($appt['doctor_name']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: var(--muted);">
                            No appointments today
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- INVENTORY DASHBOARD -->
        <?php elseif ($current_role === 'Inventory'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="inventory.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Total Items</h4>
                        <div class="kicon"><i class="fas fa-boxes"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($total_items); ?></div>
                </a>
                <a href="inventory.php?filter=low_stock" class="kcard">
                    <div class="kcard-header">
                        <h4>Low Stock Items</h4>
                        <div class="kicon"><i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($low_stock_count); ?></div>
                </a>
                <a href="inventory.php?filter=out_of_stock" class="kcard">
                    <div class="kcard-header">
                        <h4>Out of Stock</h4>
                        <div class="kicon"><i class="fas fa-times-circle" style="color: var(--danger);"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($out_of_stock); ?></div>
                </a>
            </div>

            <div class="dashboard-grid">
                <!-- Items to Reorder -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-shopping-cart"></i> Items to Reorder</h3>
                        <a href="inventory.php?filter=low_stock">View All</a>
                    </div>
                    <?php if (!empty($items_to_reorder)): ?>
                        <?php foreach($items_to_reorder as $item): ?>
                            <div class="list-item" onclick="window.location.href='inventory.php?action=edit&id=<?php echo $item['id']; ?>'">
                                <div class="item-header">
                                    <span class="item-title"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                    <span class="status-badge badge-warning">Reorder</span>
                                </div>
                                <div class="item-subtitle">
                                    Category: <?php echo $item['category']; ?> • Current: <?php echo $item['quantity']; ?> / Threshold: <?php echo $item['threshold']; ?>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(100, ($item['quantity'] / $item['threshold']) * 100); ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: var(--muted);">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success); margin-bottom: 16px;"></i>
                            <p>All items are well-stocked</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Category Distribution -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-chart-pie"></i> Inventory by Category</h3>
                    </div>
                    <div style="height: 300px;">
                        <canvas id="categoryChart"></canvas>
                    </div>
                    <div style="margin-top: 20px;">
                        <?php foreach($category_counts as $cat): ?>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <span><?php echo $cat['category']; ?></span>
                                <span><strong><?php echo $cat['count']; ?></strong> items (<?php echo $cat['total_quantity']; ?> units)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Updates -->
            <div class="panel" style="margin-top: 24px;">
                <div class="panel-header">
                    <h3><i class="fas fa-history"></i> Recent Inventory Updates</h3>
                    <a href="inventory.php">Manage Inventory</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_updates as $item): ?>
                            <tr onclick="window.location.href='inventory.php?action=edit&id=<?php echo $item['id']; ?>'">
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo $item['category']; ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo date('M j, g:i A', strtotime($item['updated_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- BILLING DASHBOARD -->
        <?php elseif ($current_role === 'Billing'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="billing.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Today's Collections</h4>
                        <div class="kicon"><i class="fas fa-coins"></i></div>
                    </div>
                    <div class="value">₱<?php echo number_format($today_collections, 2); ?></div>
                </a>
                <a href="billing.php?status=Unpaid" class="kcard">
                    <div class="kcard-header">
                        <h4>Pending Bills</h4>
                        <div class="kicon"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($pending_bills['total']); ?></div>
                    <div class="sub-text">₱<?php echo number_format($pending_bills['total_amount'] ?? 0, 2); ?> total</div>
                </a>
                <a href="financials.php" class="kcard">
                    <div class="kcard-header">
                        <h4>Monthly Collections</h4>
                        <div class="kicon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="value">₱<?php echo number_format($month_collections, 2); ?></div>
                </a>
            </div>

            <div class="dashboard-grid">
                <!-- Unpaid Bills -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-hourglass-half"></i> Unpaid Bills</h3>
                        <a href="billing.php?status=Unpaid">View All</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Amount Due</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_unpaid as $bill): ?>
                                <tr onclick="window.location.href='billing.php?action=view&id=<?php echo $bill['id']; ?>'">
                                    <td><?php echo htmlspecialchars($bill['first_name'] . ' ' . $bill['last_name']); ?></td>
                                    <td>₱<?php echo number_format($bill['amount_due'], 2); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($bill['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Payments -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-check-circle" style="color: var(--success);"></i> Recent Payments</h3>
                        <a href="billing.php?status=Paid">View All</a>
                    </div>
                    <?php foreach($recent_payments as $payment): ?>
                        <div class="list-item" onclick="window.location.href='billing.php?action=view&id=<?php echo $payment['id']; ?>'">
                            <div class="item-header">
                                <span class="item-title"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></span>
                                <span class="status-badge badge-success">Paid</span>
                            </div>
                            <div class="item-subtitle">
                                ₱<?php echo number_format($payment['amount_due'], 2); ?> • <?php echo date('M j, g:i A', strtotime($payment['paid_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <!-- SOCIAL WORKER DASHBOARD -->
        <?php elseif ($current_role === 'SocialWorker'): ?>
            <!-- KPI Cards -->
            <div class="cards-row">
                <a href="financials.php?status=Pending" class="kcard">
                    <div class="kcard-header">
                        <h4>Pending Assessments</h4>
                        <div class="kicon"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($pending_assessments); ?></div>
                </a>
                <a href="financials.php?status=Approved" class="kcard">
                    <div class="kcard-header">
                        <h4>Approved Assessments</h4>
                        <div class="kicon"><i class="fas fa-check-circle" style="color: var(--success);"></i></div>
                    </div>
                    <div class="value"><?php echo number_format($approved_assessments); ?></div>
                </a>
            </div>

            <!-- Patients Needing Assessment -->
            <div class="panel" style="margin-bottom: 24px;">
                <div class="panel-header">
                    <h3><i class="fas fa-exclamation-circle" style="color: var(--warning);"></i> Patients Needing Financial Assessment</h3>
                    <a href="financials.php">View All</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Code</th>
                            <th>Unpaid Bills</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($patients_needing_assessment as $patient): ?>
                            <tr onclick="window.location.href='financials.php?action=add&patient_id=<?php echo $patient['id']; ?>'">
                                <td><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></td>
                                <td><?php echo $patient['patient_code']; ?></td>
                                <td><?php echo $patient['unpaid_bills']; ?></td>
                                <td>
                                    <span class="badge badge-info">Create Assessment</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Assessments -->
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-history"></i> Recent Assessments</h3>
                    <a href="financials.php">View All</a>
                </div>
                <?php foreach($recent_assessments as $assessment): ?>
                    <div class="list-item" onclick="window.location.href='financials.php?action=view&id=<?php echo $assessment['id']; ?>'">
                        <div class="item-header">
                            <span class="item-title"><?php echo htmlspecialchars($assessment['first_name'] . ' ' . $assessment['last_name']); ?></span>
                            <span class="status-badge <?php echo getStatusBadge($assessment['status']); ?>">
                                <?php echo $assessment['status']; ?>
                            </span>
                        </div>
                        <div class="item-subtitle">
                            Type: <?php echo $assessment['assessment_type'] ?? 'N/A'; ?> • 
                            Reviewed by: <?php echo $assessment['reviewer_name'] ?? 'Pending'; ?>
                        </div>
                        <div class="item-meta">
                            <span><i class="far fa-calendar"></i> <?php echo date('M j, Y', strtotime($assessment['created_at'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Patient Statistics Graph (shown to roles that can see it) -->
        <?php if (in_array($current_role, ['Doctor'])): ?>
        <div class="panel" style="margin-top: 32px;">
            <div class="panel-header">
                <h3>Patient Statistics - <?php echo $current_year; ?></h3>
                <div class="graph-controls">
                    <select id="yearFilter" class="filter-select">
                        <?php foreach($available_years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $current_year == $year ? 'selected' : ''; ?>>
                                <?php echo $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button id="applyFilter" class="btn" style="padding: 8px 16px;">Apply</button>
                </div>
            </div>
            <div class="graph-box">
                <canvas id="patientsChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Patient Details Modal -->
<div class="modal-overlay" id="modalOverlay"></div>
<div class="modal" id="patientModal">
    <div class="modal-header">
        <h3 id="modalTitle">Patient Details</h3>
        <button class="close-modal" id="closeModal">&times;</button>
    </div>
    <div class="modal-content" id="modalContent"></div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// ========== CHART INITIALIZATION ==========
<?php if (in_array($current_role, ['Admin', 'Doctor', 'Inventory'])): ?>
// Patient Statistics Chart
const ctx = document.getElementById('patientsChart')?.getContext('2d');
if (ctx) {
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($monthly_labels); ?>,
            datasets: [
                {
                    label: 'Total Patients',
                    data: <?php echo json_encode($monthly_totals); ?>,
                    borderColor: '#001F3F',
                    backgroundColor: 'rgba(0, 31, 63, 0.1)',
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#001F3F',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                    fill: true,
                    borderWidth: 3
                },
                {
                    label: 'Surgical Patients',
                    data: <?php echo json_encode($monthly_surgical); ?>,
                    borderColor: '#4d8cc9',
                    backgroundColor: 'rgba(77, 140, 201, 0.1)',
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#4d8cc9',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                    fill: true,
                    borderWidth: 3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true }
            },
            onClick: (evt, elements) => {
                if (elements.length > 0) {
                    const element = elements[0];
                    const monthIndex = element.index;
                    const month = monthIndex + 1;
                    const year = <?php echo $current_year; ?>;
                    loadPatientData(month, year);
                }
            }
        }
    });
}
<?php endif; ?>

<?php if ($current_role === 'Inventory'): ?>
// Category Distribution Chart
const catCtx = document.getElementById('categoryChart')?.getContext('2d');
if (catCtx) {
    new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($category_counts, 'category')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($category_counts, 'count')); ?>,
                backgroundColor: [
                    '#001F3F',
                    '#4d8cc9',
                    '#10b981',
                    '#f59e0b',
                    '#ef4444'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}
<?php endif; ?>

// ========== FILTER FUNCTIONALITY ==========
const yearFilter = document.getElementById('yearFilter');
const applyFilterBtn = document.getElementById('applyFilter');

if (applyFilterBtn) {
    applyFilterBtn.addEventListener('click', () => {
        const year = yearFilter.value;
        if (year) {
            window.location.href = 'dashboard.php?year=' + year;
        }
    });
}

// ========== MODAL FUNCTIONS ==========
const modalOverlay = document.getElementById('modalOverlay');
const patientModal = document.getElementById('patientModal');
const closeModal = document.getElementById('closeModal');

function showModal() {
    modalOverlay.style.display = 'block';
    patientModal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function hideModal() {
    modalOverlay.style.display = 'none';
    patientModal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

if (modalOverlay) {
    modalOverlay.addEventListener('click', hideModal);
}

if (closeModal) {
    closeModal.addEventListener('click', hideModal);
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') hideModal();
});

if (patientModal) {
    patientModal.addEventListener('click', (e) => e.stopPropagation());
}

async function loadPatientData(month, year) {
    try {
        const response = await fetch(`get_patient_data.php?month=${month}&year=${year}`);
        const data = await response.json();
        
        document.getElementById('modalTitle').textContent = `Patient Details - ${data.month_name} ${year}`;
        
        // Build modal content (keeping your existing structure)
        let content = `
            <div class="modal-tabs">
                <button class="tab-btn active" onclick="switchTab('all-patients')">All Patients (${data.total_count})</button>
                <button class="tab-btn" onclick="switchTab('surgical-patients')">Surgical Patients (${data.surgical_count})</button>
            </div>
        `;

        // All Patients Tab
        content += `<div id="all-patients" class="tab-content active"><div class="patient-list">`;
        if (data.all_patients.length > 0) {
            data.all_patients.forEach(patient => {
                content += `
                    <div class="patient-card">
                        <div class="patient-header">
                            <div class="patient-name">${patient.first_name} ${patient.last_name}</div>
                            <div class="patient-code">${patient.patient_code}</div>
                        </div>
                        <div class="patient-details">
                            <div class="detail-item"><span class="detail-label">Gender:</span> ${patient.sex || 'N/A'}</div>
                            <div class="detail-item"><span class="detail-label">Age:</span> ${patient.age || 'N/A'}</div>
                            <div class="detail-item"><span class="detail-label">Phone:</span> ${patient.phone || 'N/A'}</div>
                        </div>
                    </div>
                `;
            });
        } else {
            content += '<div class="no-patients"><p>No patients found</p></div>';
        }
        content += '</div></div>';

        // Surgical Patients Tab
        content += `<div id="surgical-patients" class="tab-content"><div class="patient-list">`;
        if (data.surgical_patients.length > 0) {
            data.surgical_patients.forEach(patient => {
                content += `
                    <div class="patient-card">
                        <div class="patient-header">
                            <div class="patient-name">${patient.first_name} ${patient.last_name}</div>
                            <div class="patient-code">${patient.patient_code}</div>
                        </div>
                        <div class="patient-details">
                            <div class="detail-item"><span class="detail-label">Surgery:</span> ${patient.surgery_type || 'N/A'}</div>
                            <div class="detail-item"><span class="detail-label">Date:</span> ${patient.surgery_date || 'N/A'}</div>
                        </div>
                    </div>
                `;
            });
        } else {
            content += '<div class="no-patients"><p>No surgical patients found</p></div>';
        }
        content += '</div></div>';

        document.getElementById('modalContent').innerHTML = content;
        showModal();
    } catch (error) {
        console.error('Error loading patient data:', error);
    }
}

// Tab switching function
window.switchTab = function(tabId) {
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}
</script>
</body>
</html>