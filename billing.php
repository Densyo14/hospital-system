<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get current user role and name
$current_role = $_SESSION['role'] ?? 'Guest';
$current_name = $_SESSION['name'] ?? 'User';

// Define role permissions for navigation
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
        'users.php' => 'Users'
    ],
    'Doctor' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments', 
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'
    ],
    'Nurse' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'
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

// Get allowed pages for current role
$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Preprocessing
$success = $_GET['success'] ?? '';
$action  = $_GET['action'] ?? '';
$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

// Current user info for permission checks
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_billing = ($current_user['role'] ?? '') === 'Billing';
$is_social_worker = ($current_user['role'] ?? '') === 'SocialWorker';

// ACTION HANDLERS

// MARK PAID action (admin/billing only)
if (isset($_GET['mark_paid']) && ($is_admin || $is_billing)) {
    $id = (int)$_GET['mark_paid'];
    
    // Get bill details for audit
    $bill = fetchOne($conn, 
        "SELECT b.*, p.first_name, p.last_name 
         FROM billing b 
         LEFT JOIN patients p ON b.patient_id = p.id 
         WHERE b.id = ?", 
        "i", 
        [$id]
    );
    
    if ($bill) {
        // Check if bill has financial assessment
        if ($bill['financial_assessment_id']) {
            $assessment = fetchOne($conn, 
                "SELECT assessment_type, status FROM financial_assessment WHERE id = ?", 
                "i", 
                [$bill['financial_assessment_id']]
            );
            
            // Only allow payment if assessment is approved
            if ($assessment && $assessment['status'] !== 'Approved') {
                header("Location: billing.php?success=error&message=Assessment+not+approved");
                exit();
            }
        }
        
        $result = execute($conn, 
            "UPDATE billing SET status='Paid', paid_at=NOW() WHERE id = ?", 
            "i", 
            [$id]
        );
        
        if (!isset($result['error'])) {
            header("Location: billing.php?success=paid&action=mark_paid&bill_id={$id}");
            exit();
        }
    }
    header("Location: billing.php?success=error&action=mark_paid");
    exit();
}

// ARCHIVE action (admin/billing only)
if (isset($_GET['archive']) && ($is_admin || $is_billing)) {
    $id = (int)$_GET['archive'];
    $reason = "Archived by user";

    $stmt = $conn->prepare("UPDATE billing SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?");
    $stmt->bind_param("ii", $current_user_id, $id);
    if ($stmt->execute()) {
        $stmt2 = $conn->prepare("INSERT INTO archive_logs (table_name, record_id, archived_by, reason) VALUES ('billing', ?, ?, ?)");
        $stmt2->bind_param("iis", $id, $current_user_id, $reason);
        $stmt2->execute();
        header("Location: billing.php?success=archived&action=archive&bill_id={$id}");
        exit();
    } else {
        header("Location: billing.php?success=error&action=archive");
        exit();
    }
}

// RESTORE action (admin/billing only)
if (isset($_GET['restore']) && ($is_admin || $is_billing)) {
    $id = (int)$_GET['restore'];
    $stmt = $conn->prepare("UPDATE billing SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: billing.php?success=restored&action=restore&bill_id={$id}");
        exit();
    } else {
        header("Location: billing.php?success=error&action=restore");
        exit();
    }
}

// LINK FINANCIAL ASSESSMENT action
if (isset($_GET['link_assessment']) && ($is_admin || $is_billing || $is_social_worker)) {
    $bill_id = (int)$_GET['link_assessment'];
    $assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
    
    if ($assessment_id > 0) {
        // Get assessment details to calculate coverage
        $assessment = fetchOne($conn, 
            "SELECT assessment_type, philhealth_eligible, hmo_provider FROM financial_assessment WHERE id = ?", 
            "i", 
            [$assessment_id]
        );
        
        if ($assessment) {
            // Get bill total
            $bill = fetchOne($conn, "SELECT total_amount FROM billing WHERE id = ?", "i", [$bill_id]);
            
            if ($bill) {
                // Calculate coverage based on assessment type
                $coverage_calculation = calculateCoverage(
                    $bill['total_amount'],
                    $assessment['assessment_type'],
                    $assessment['philhealth_eligible'],
                    $assessment['hmo_provider']
                );
                
                // Update bill with financial assessment and calculated coverage
                $params = [
                    $assessment_id,
                    $coverage_calculation['philhealth_coverage'],
                    $coverage_calculation['hmo_coverage'],
                    $coverage_calculation['amount_due'],
                    $bill_id
                ];
                $result = execute($conn, 
                    "UPDATE billing SET financial_assessment_id = ?, philhealth_coverage = ?, hmo_coverage = ?, amount_due = ? WHERE id = ?", 
                    "idddi", 
                    $params
                );
                
                if (!isset($result['error'])) {
                    header("Location: billing.php?success=assessment_linked&bill_id={$bill_id}");
                    exit();
                }
            }
        }
    }
    header("Location: billing.php?success=error&message=Failed+to+link+assessment");
    exit();
}

// Helper function to calculate coverage
function calculateCoverage($total_amount, $assessment_type, $philhealth_eligible, $hmo_provider) {
    $philhealth_coverage = 0;
    $hmo_coverage = 0;
    
    switch($assessment_type) {
        case 'Charity':
            // Full coverage
            $philhealth_coverage = $philhealth_eligible ? $total_amount * 0.8 : 0;
            $hmo_coverage = !empty($hmo_provider) ? $total_amount * 0.2 : 0;
            break;
            
        case 'Partial':
            // Partial coverage (50-70%)
            $philhealth_coverage = $philhealth_eligible ? $total_amount * 0.5 : 0;
            $hmo_coverage = !empty($hmo_provider) ? $total_amount * 0.2 : 0;
            break;
            
        case 'Paying':
            // Minimal coverage (20-30%)
            $philhealth_coverage = $philhealth_eligible ? $total_amount * 0.2 : 0;
            $hmo_coverage = !empty($hmo_provider) ? $total_amount * 0.1 : 0;
            break;
            
        default:
            $philhealth_coverage = 0;
            $hmo_coverage = 0;
    }
    
    // Ensure coverage doesn't exceed total amount
    $total_coverage = $philhealth_coverage + $hmo_coverage;
    if ($total_coverage > $total_amount) {
        $ratio = $total_amount / $total_coverage;
        $philhealth_coverage *= $ratio;
        $hmo_coverage *= $ratio;
    }
    
    $amount_due = $total_amount - $philhealth_coverage - $hmo_coverage;
    
    return [
        'philhealth_coverage' => $philhealth_coverage,
        'hmo_coverage' => $hmo_coverage,
        'amount_due' => $amount_due
    ];
}

// Pagination
$bills_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $bills_per_page;

// Build query conditions
$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';
$where_conditions = $show_archived ? ["b.is_archived = 1"] : ["b.is_archived = 0"];

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';
$patient_filter = $_GET['patient'] ?? '';
$assessment_filter = $_GET['assessment'] ?? '';

// Apply filters
if (!empty($status_filter) && in_array($status_filter, ['Unpaid', 'Paid', 'Partially Paid'])) {
    $where_conditions[] = "b.status = '$status_filter'";
}

if (!empty($date_filter) && validateDate($date_filter, 'Y-m-d')) {
    $where_conditions[] = "DATE(b.created_at) = '$date_filter'";
}

if (!empty($patient_filter) && is_numeric($patient_filter)) {
    $where_conditions[] = "b.patient_id = $patient_filter";
}

if (!empty($assessment_filter)) {
    if ($assessment_filter === 'with') {
        $where_conditions[] = "b.financial_assessment_id IS NOT NULL";
    } elseif ($assessment_filter === 'without') {
        $where_conditions[] = "b.financial_assessment_id IS NULL";
    }
}

// Combine WHERE conditions
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count of bills for pagination
$total_bills_query = "SELECT COUNT(*) as total FROM billing b $where_clause";
$total_bills_result = mysqli_query($conn, $total_bills_query);
$total_bills_row = mysqli_fetch_assoc($total_bills_result);
$total_bills = $total_bills_row['total'];
$total_pages = ceil($total_bills / $bills_per_page);

// Fetch paginated bills with detailed financial assessment info
$rows = fetchAll($conn, "
    SELECT 
        b.*, 
        p.first_name, 
        p.last_name, 
        p.patient_code,
        s.surgery_type,
        s.schedule_date,
        fa.id as fa_id,
        fa.assessment_type,
        fa.status as fa_status,
        fa.philhealth_eligible,
        fa.hmo_provider,
        fa.created_at as fa_created,
        arch_user.full_name AS archived_by_name,
        COUNT(CASE WHEN b2.status = 'Unpaid' AND b2.patient_id = b.patient_id THEN 1 END) as patient_unpaid_count,
        SUM(CASE WHEN b2.status = 'Unpaid' AND b2.patient_id = b.patient_id THEN b2.amount_due ELSE 0 END) as patient_unpaid_total
    FROM billing b 
    LEFT JOIN patients p ON b.patient_id = p.id 
    LEFT JOIN surgeries s ON b.surgery_id = s.id 
    LEFT JOIN financial_assessment fa ON b.financial_assessment_id = fa.id
    LEFT JOIN users arch_user ON b.archived_by = arch_user.id
    LEFT JOIN billing b2 ON b.patient_id = b2.patient_id AND b2.is_archived = 0
    $where_clause
    GROUP BY b.id
    ORDER BY b.id DESC
    LIMIT $offset, $bills_per_page
", null, []);

// Get summary statistics
$total_stats = fetchOne($conn, "SELECT 
    COUNT(*) as total_bills,
    SUM(CASE WHEN status = 'Paid' THEN 1 ELSE 0 END) as paid_bills,
    SUM(CASE WHEN status = 'Unpaid' THEN 1 ELSE 0 END) as unpaid_bills,
    SUM(total_amount) as total_revenue,
    SUM(CASE WHEN status = 'Paid' THEN total_amount ELSE 0 END) as paid_revenue,
    SUM(CASE WHEN status = 'Unpaid' THEN amount_due ELSE 0 END) as pending_payments,
    SUM(philhealth_coverage + hmo_coverage) as total_coverage,
    COUNT(DISTINCT CASE WHEN financial_assessment_id IS NOT NULL THEN patient_id END) as patients_with_assessments
    FROM billing WHERE is_archived = 0", null, []);

// Get financial assessment statistics
$assessment_stats = fetchOne($conn, "
    SELECT 
        COUNT(DISTINCT b.patient_id) as total_patients,
        COUNT(DISTINCT CASE WHEN fa.id IS NOT NULL THEN b.patient_id END) as assessed_patients,
        SUM(CASE WHEN fa.assessment_type = 'Charity' THEN 1 ELSE 0 END) as charity_cases,
        SUM(CASE WHEN fa.assessment_type = 'Partial' THEN 1 ELSE 0 END) as partial_cases,
        SUM(CASE WHEN fa.assessment_type = 'Paying' THEN 1 ELSE 0 END) as paying_cases
    FROM billing b
    LEFT JOIN financial_assessment fa ON b.financial_assessment_id = fa.id
    WHERE b.is_archived = 0
    GROUP BY b.patient_id
    LIMIT 1
", null, []);

// Get filter badge text
$filter_text = [];
if (!empty($status_filter)) $filter_text[] = "Status: $status_filter";
if (!empty($date_filter)) $filter_text[] = "Date: " . date('M j, Y', strtotime($date_filter));
if (!empty($patient_filter)) {
    $patient = fetchOne($conn, "SELECT first_name, last_name FROM patients WHERE id = ?", "i", [$patient_filter]);
    if ($patient) {
        $filter_text[] = "Patient: " . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
    }
}
if (!empty($assessment_filter)) {
    $filter_text[] = $assessment_filter === 'with' ? "With Assessment" : "Without Assessment";
}
$filter_badge_text = implode(' • ', $filter_text);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - Billing</title>

<!-- Google Font -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --bg: #eef3f7;
    --panel: #ffffff;
    --muted: #6b7280;
    --navy-700: #001F3F;          /* Dark Navy */
    --accent: #003366;            /* Medium Navy */
    --sidebar: #002855;           /* Sidebar Navy */
    --light-blue: #4d8cc9;        /* Light Blue for accents */
    --card-shadow: 0 6px 22px rgba(16,24,40,0.06);
    --glass: rgba(255,255,255,0.6);
  }

  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;
    font-family:Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    background:var(--bg);
    color: #0f1724;
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
  }

  /* Layout */
  .app {
    display:flex;
    min-height:100vh;
    align-items:stretch;
  }

  /* SIDEBAR */
  .sidebar {
    width:230px;
    background:linear-gradient(180deg, var(--sidebar), #001a33 120%);
    color:#eaf5ff;
    padding:18px 15px;
    display:flex;
    flex-direction:column;
    gap:14px;
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    box-shadow: 2px 0 12px rgba(0,0,0,0.04);
    transition:width .22s ease, transform .22s ease;
    z-index:30;
    overflow-y: auto;
  }

  .sidebar::-webkit-scrollbar {
    width: 4px;
  }
  .sidebar::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
  }
  .sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
  }

  .sidebar.collapsed{
    width:72px;
  }
    
  .logo-wrap{
    display:flex;
    align-items:center;
    justify-content: center;
    padding-bottom:4px;
  }
  .logo-wrap img{
    width:150px;
    height:auto;
    display:block;
  }

  .menu{margin-top:8px; display:flex; flex-direction:column; gap:6px}
  .menu-item{
    display:flex;
    align-items:center;
    gap:10px;
    padding:9px 7px;
    border-radius:8px;
    cursor:pointer;
    color:rgba(255,255,255,0.95);
    font-weight:500;
    text-decoration: none;
    transition: all 0.2s ease;
    font-size: 14px;
  }
  .menu-item:hover {
    background: linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
  }
  .menu-item.active{ 
    background: linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04)); 
    border-left:4px solid #9bcfff; 
    padding-left:5px; 
  }
  .menu-item svg, .menu-item .icon{
    width:16px;height:16px;opacity:.95;
    fill: white;
  }

  .sidebar-bottom {
    margin-top:auto;
    font-size:13px;
    color:rgba(255,255,255,0.8);
    opacity:0.95;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.1);
  }

  .user-info {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 10px;
    margin-top: 8px;
    border-left: 3px solid #9bcfff;
    font-size: 13px;
  }

  .user-info h4 {
    margin: 0 0 4px 0;
    font-size: 13px;
    color: #9bcfff;
  }

  .user-info p {
    margin: 0;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.3;
  }

  @media (max-height: 700px) {
    .sidebar {
      padding: 15px 12px;
      gap: 10px;
    }
    .logo-wrap img {
      width: 130px;
    }
    .user-info {
      padding: 8px;
      font-size: 12px;
    }
    .user-info h4 {
      font-size: 12px;
    }
    .user-info p {
      font-size: 11px;
    }
    .menu {
      gap: 4px;
    }
    .menu-item {
      padding: 7px 5px;
      font-size: 13px;
    }
    .menu-item .icon {
      width: 15px;
      height: 15px;
    }
  }

  /* MAIN content */
  .main {
    margin-left:230px;
    padding:18px 28px;
    width:100%;
    transition:margin-left .22s ease;
  }
  .sidebar.collapsed ~ .main { margin-left:72px; }

  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:8px;
  }

  .top-left h1{font-size:22px;margin:0;font-weight:700}
  .top-left p{margin:6px 0 0 0;color:var(--muted);font-size:13px}

  .top-actions{display:flex;align-items:center;gap:12px}
  .btn{
    background:var(--navy-700);
    color:#fff;
    padding:9px 14px;
    border-radius:10px;
    border:0;
    font-weight:600;
    cursor:pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
  }
  .btn:hover {
    background:var(--accent);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
  }
  .btn-secondary {
    background: #6c757d;
    color: #fff;
  }
  .btn-warning {
    background: #f39c12;
    color: #fff;
  }
  .btn-danger {
    background: #e74c3c;
    color: #fff;
  }
  .btn-success {
    background: #27ae60;
    color: #fff;
  }
  .btn-info {
    background: #3498db;
    color: #fff;
  }
  .date-pill{
    background:var(--panel);
    padding:8px 12px;
    border-radius:999px;
    box-shadow:0 4px 14px rgba(16,24,40,0.06);
    font-size:13px;
    white-space: nowrap;
    border: 1px solid #e6eef0;
  }

  /* Filter badge */
  .filter-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #e8f4ff;
    color: var(--navy-700);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 500;
    margin: 10px 0;
  }
  .filter-badge .close-btn {
    background: none;
    border: none;
    color: var(--navy-700);
    cursor: pointer;
    font-size: 16px;
    padding: 0;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
  }
  .filter-badge .close-btn:hover {
    background: rgba(0, 31, 63, 0.1);
  }

  /* Toast container */
  .toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    max-width: 350px;
  }

  .alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    color: white;
    font-weight: 500;
    animation: slideIn 0.3s ease;
  }

  .alert-success {
    background: #27ae60;
    border-left: 4px solid #1e8449;
  }

  .alert-error, .alert-danger {
    background: #e74c3c;
    border-left: 4px solid #c0392b;
  }

  .alert-warning {
    background: #f39c12;
    border-left: 4px solid #d68910;
  }

  .alert-info {
    background: #3498db;
    border-left: 4px solid #2980b9;
  }

  @keyframes slideIn {
    from {
      transform: translateX(100%);
      opacity: 0;
    }
    to {
      transform: translateX(0);
      opacity: 1;
    }
  }

  /* Summary Cards */
  .summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin: 18px 0;
  }
  .summary-card {
    background: var(--panel);
    padding: 20px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    border: 1px solid #f0f4f8;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(16,24,40,0.12);
    border-color: var(--light-blue);
  }
  .summary-card h4 {
    margin: 0 0 10px 0;
    color: var(--muted);
    font-weight: 600;
    font-size: 14px;
  }
  .summary-card .value {
    font-size: 24px;
    font-weight: 800;
    margin-top: 8px;
    color: var(--navy-700);
  }
  .summary-card.primary { border-left: 4px solid var(--navy-700); }
  .summary-card.warning { border-left: 4px solid #f39c12; }
  .summary-card.info { border-left: 4px solid #3498db; }
  .summary-card.success { border-left: 4px solid #27ae60; }
  .summary-card.purple { border-left: 4px solid #9b59b6; }

  /* Financial assessment summary */
  .assessment-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin: 18px 0;
  }

  /* Billing table */
  .section-title{font-size:18px;margin:14px 0 8px 0;color:#1e3a5f}
  .table-wrap{background:var(--panel);padding:18px;border-radius:12px;box-shadow:var(--card-shadow);overflow:auto; border: 1px solid #f0f4f8;}
  .table-controls{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
  .search-input{padding:10px 12px;border-radius:10px;border:1px solid #e6eef0;background:transparent;min-width:220px}
  .search-input:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }
  table{width:100%;border-collapse:collapse;min-width:800px}
  thead th{background:#f8fbfd;padding:14px;text-align:left;color:#6b7280;font-weight:600; border-bottom: 2px solid #e6eef0;}
  td, th{padding:14px;border-bottom:1px solid #f0f3f4;color:#233}
  
  /* Status badges */
  .status{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .paid{background:#e8f4ff;color:#1e6b8a}
  .unpaid{background:#fce6e8;color:#b02b2b}
  .archived{background:#95a5a6;color:white}
  
  /* Assessment type badges */
  .type{display:inline-block;padding:4px 8px;border-radius:12px;font-weight:600;font-size:11px;margin-top:4px}
  .type-charity{background:#e8f4ff;color:#1e6b8a}
  .type-partial{background:#fff8e1;color:#8a6d00}
  .type-paying{background:#e0f2fe;color:#0369a1}
  .type-none{background:#f0f3f4;color:#6b7280}

  /* Patient info */
  .patient-unpaid {
    font-size: 11px;
    color: #e74c3c;
    margin-top: 3px;
  }

  /* Pagination */
  .pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 20px;
    gap: 10px;
  }
  .pagination a, .pagination span {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 6px;
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
  .pagination .disabled {
    color: var(--muted);
    pointer-events: none;
  }

  /* Role badge */
  .role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
    margin-left: 10px;
  }
  .role-admin { background: #001F3F; color: white; }
  .role-billing { background: #003366; color: white; }
  .role-doctor { background: #003366; color: white; }
  .role-nurse { background: #4d8cc9; color: white; }
  .role-staff { background: #6b7280; color: white; }
  .role-inventory { background: #1e6b8a; color: white; }
  .role-socialworker { background: #34495e; color: white; }

  /* Clickable image */
  .clickable-image {
    cursor: pointer;
    transition: transform 0.2s ease;
  }
  .clickable-image:hover {
    transform: scale(1.02);
  }

  /* Footer shadow */
  .footer-shadow{height:48px;background:linear-gradient(180deg,transparent,rgba(3,7,18,0.04));pointer-events:none;position:fixed;left:0;right:0;bottom:0}

  /* Responsive */
  @media (max-width:1100px){
    .table-wrap table{min-width:700px}
    .summary-cards, .assessment-summary{grid-template-columns:1fr}
  }
  @media (max-width:780px){
    .sidebar{position:fixed;left:-320px;transform:translateX(0)}
    .sidebar.open{left:0;transform:translateX(0)}
    .main{margin-left:0;padding:12px}
    .sidebar.collapsed{width:230px}
    .topbar{flex-direction:column;align-items:flex-start}
    .top-actions{width:100%;justify-content:space-between}
  }

  /* small niceties */
  a{color:inherit}
  .muted{color:var(--muted);font-size:13px}
</style>
</head>
<body>
  <div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
        <!-- Make logo image clickable -->
        <a href="dashboard.php" class="clickable-image">
          <img src="logo.jpg" alt="Seamen's Cure Logo">
        </a>
      </div>

      <!-- User info -->
      <div class="user-info">
        <h4>Logged as:</h4>
        <p><?php echo htmlspecialchars($current_name); ?><br><strong><?php echo htmlspecialchars($current_role); ?></strong></p>
      </div>

      <nav class="menu" id="mainMenu">
        <a href="dashboard.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
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
                    'users.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>'
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
        <a href="logout.php" class="menu-item" style="color: rgba(255,255,255,0.8);">
          <span class="icon">
            <svg viewBox="0 0 24 24" fill="currentColor">
              <path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
            </svg>
          </span>
          <span class="label">Logout</span>
        </a>
      </div>
    </aside>

    <!-- MAIN -->
    <div class="main" id="mainContent">
      <div class="topbar">
        <div class="top-left">
          <h1>Billing <?php echo $show_archived ? '(Archived)' : ''; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Manage patient billing and payments - Gig Oca Robles Seamen's Hospital Davao Management System</p>
        </div>

        <div class="top-actions">
          <?php if ($show_archived): ?>
            <a href="billing.php" class="btn btn-secondary">View Active Bills</a>
          <?php else: ?>
            <a href="billing_form.php" class="btn">+ Create Bill</a>
            <a href="financials.php" class="btn btn-info">Financial Assessments</a>
            <?php if ($is_admin || $is_billing): ?>
              <a href="billing.php?show=archived" class="btn" style="background: #6c757d;">View Archived</a>
            <?php endif; ?>
          <?php endif; ?>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- Filter badge -->
      <?php if (!empty($filter_badge_text)): ?>
        <div class="filter-badge">
          Filter: <?php echo htmlspecialchars($filter_badge_text); ?>
          <a href="billing.php" class="close-btn">&times;</a>
        </div>
      <?php endif; ?>

      <!-- Summary Statistics -->
      <div class="summary-cards">
        <div class="summary-card primary">
          <h4>Total Revenue</h4>
          <div class="value">₱ <?php echo number_format($total_stats['total_revenue'] ?? 0, 2); ?></div>
          <small class="muted">From <?php echo $total_stats['total_bills'] ?? 0; ?> bills</small>
        </div>
        <div class="summary-card warning">
          <h4>Pending Payments</h4>
          <div class="value">₱ <?php echo number_format($total_stats['pending_payments'] ?? 0, 2); ?></div>
          <small class="muted"><?php echo $total_stats['unpaid_bills'] ?? 0; ?> unpaid bills</small>
        </div>
        <div class="summary-card info">
          <h4>Insurance Coverage</h4>
          <div class="value">₱ <?php echo number_format($total_stats['total_coverage'] ?? 0, 2); ?></div>
          <small class="muted">PhilHealth + HMO</small>
        </div>
        <div class="summary-card success">
          <h4>Assessed Patients</h4>
          <div class="value"><?php echo $total_stats['patients_with_assessments'] ?? 0; ?></div>
          <small class="muted">With financial assessments</small>
        </div>
      </div>

      <!-- Financial Assessment Summary -->
      <div class="assessment-summary">
        <div class="summary-card" style="border-left: 4px solid #27ae60;">
          <h4>Charity Cases</h4>
          <div class="value"><?php echo $assessment_stats['charity_cases'] ?? 0; ?></div>
        </div>
        <div class="summary-card" style="border-left: 4px solid #f39c12;">
          <h4>Partial Aid</h4>
          <div class="value"><?php echo $assessment_stats['partial_cases'] ?? 0; ?></div>
        </div>
        <div class="summary-card" style="border-left: 4px solid #3498db;">
          <h4>Paying</h4>
          <div class="value"><?php echo $assessment_stats['paying_cases'] ?? 0; ?></div>
        </div>
      </div>

      <!-- Billing table -->
      <div class="table-wrap" id="billingSection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by patient name or assessment type">
          </div>
          <div class="muted">Showing <span id="rowCount"><?php echo count($rows); ?></span> of <?php echo number_format($total_bills); ?> bills</div>
        </div>

        <table id="billingTable" aria-label="Billing table">
          <thead>
            <tr>
              <th>Bill ID</th>
              <th>Patient</th>
              <th>Financial Assessment</th>
              <th>Total Amount</th>
              <th>Coverage</th>
              <th>Amount Due</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($rows) > 0): ?>
              <?php foreach($rows as $r): ?>
                <?php
                // Status badge
                $status_class = strtolower($r['status'] ?? 'unpaid');
                
                // Financial assessment badge
                $has_assessment = !empty($r['fa_id']);
                $assessment_type = $r['assessment_type'] ?? null;
                $fa_status = $r['fa_status'] ?? null;
                $type_class = $assessment_type ? 'type-' . strtolower($assessment_type) : 'type-none';
                
                // Patient unpaid info
                $patient_unpaid_info = '';
                if ($r['patient_unpaid_count'] > 1 && $r['status'] === 'Unpaid') {
                    $patient_unpaid_info = "<div class='patient-unpaid'>+ {$r['patient_unpaid_count']} other unpaid bills (₱" . number_format($r['patient_unpaid_total'] - $r['amount_due'], 2) . ")</div>";
                }
                ?>
                <tr data-bill-id="<?php echo h($r['id']); ?>" <?php echo !empty($r['is_archived']) ? 'style="background-color: rgba(149, 165, 166, 0.1);"' : ''; ?>>
                  <td>TX-<?php echo h($r['id']); ?></td>
                  <td>
                    <?php echo h($r['first_name'] . ' ' . $r['last_name']); ?>
                    <?php if ($r['surgery_type']): ?>
                      <br><small class="muted"><?php echo h($r['surgery_type']); ?></small>
                    <?php endif; ?>
                    <?php echo $patient_unpaid_info; ?>
                  </td>
                  <td>
                    <?php if ($has_assessment): ?>
                      <span class="type <?php echo $type_class; ?>"><?php echo h($assessment_type); ?></span>
                      <br>
                      <small class="muted">
                        <?php echo h($fa_status ?? 'N/A'); ?>
                        <?php if ($r['philhealth_eligible']): ?>
                          • <span style="color: #27ae60;">✓ PhilHealth</span>
                        <?php endif; ?>
                        <?php if (!empty($r['hmo_provider'])): ?>
                          • <span style="color: #3498db;">✓ <?php echo h($r['hmo_provider']); ?></span>
                        <?php endif; ?>
                      </small>
                    <?php else: ?>
                      <span class="type type-none">No Assessment</span>
                      <br>
                      <small class="muted">
                        <a href="financial_form.php?patient_id=<?php echo h($r['patient_id']); ?>&bill_id=<?php echo h($r['id']); ?>" style="color: var(--navy-700);">
                          Add Assessment
                        </a>
                      </small>
                    <?php endif; ?>
                  </td>
                  <td>₱ <?php echo number_format($r['total_amount'], 2); ?></td>
                  <td>
                    PhilHealth: ₱<?php echo number_format($r['philhealth_coverage'], 2); ?><br>
                    HMO: ₱<?php echo number_format($r['hmo_coverage'], 2); ?>
                  </td>
                  <td style="font-weight: 600;">₱ <?php echo number_format($r['amount_due'], 2); ?></td>
                  <td>
                    <span class="status <?php echo $status_class; ?>"><?php echo h($r['status']); ?></span>
                    <?php if (!empty($r['is_archived'])): ?>
                      <span class="status archived" style="margin-left: 5px;">Archived</span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space: nowrap;">
                    <button type="button" class="btn" onclick="viewBill(<?php echo h($r['id']); ?>)" style="background: #3498db; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">View</button>
                    
                    <a href="billing_form.php?id=<?php echo h($r['id']); ?>" class="btn" style="background: #f39c12; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px;">Edit</a>
                    
                    <?php if (!$has_assessment && ($is_admin || $is_billing || $is_social_worker)): ?>
                      <a href="financial_form.php?patient_id=<?php echo h($r['patient_id']); ?>&bill_id=<?php echo h($r['id']); ?>" class="btn" style="background: #9b59b6; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px;">+ Assessment</a>
                    <?php endif; ?>
                    
                    <?php if ($r['status'] === 'Unpaid' && ($is_admin || $is_billing) && empty($r['is_archived'])): ?>
                      <?php $patientName = addslashes(h($r['first_name'] . ' ' . $r['last_name'])); ?>
                      <button type="button" class="btn" onclick="confirmMarkPaid(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')" style="background: #27ae60; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">Mark Paid</button>
                    <?php endif; ?>
                    
                    <?php if (($is_admin || $is_billing)): ?>
                      <?php $patientName = addslashes(h($r['first_name'] . ' ' . $r['last_name'])); ?>
                      <?php if (!empty($r['is_archived'])): ?>
                        <button type="button" class="btn" onclick="confirmRestore(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')" style="background: #95a5a6; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">Restore</button>
                      <?php else: ?>
                        <button type="button" class="btn" onclick="confirmArchive(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')" style="background: #7f8c8d; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">Archive</button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" style="text-align:center; color:#888; padding: 30px;">
                  <?php echo $show_archived ? 'No archived bills found.' : 'No bills found.'; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($date_filter) ? "&date=$date_filter" : ''; ?><?php echo !empty($patient_filter) ? "&patient=$patient_filter" : ''; ?><?php echo !empty($assessment_filter) ? "&assessment=$assessment_filter" : ''; ?>">&laquo; Previous</a>
          <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
          <?php endif; ?>
          
          <?php 
          $start_page = max(1, $current_page - 2);
          $end_page = min($total_pages, $current_page + 2);
          
          for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i == $current_page): ?>
              <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
              <a href="?page=<?php echo $i; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($date_filter) ? "&date=$date_filter" : ''; ?><?php echo !empty($patient_filter) ? "&patient=$patient_filter" : ''; ?><?php echo !empty($assessment_filter) ? "&assessment=$assessment_filter" : ''; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($date_filter) ? "&date=$date_filter" : ''; ?><?php echo !empty($patient_filter) ? "&patient=$patient_filter" : ''; ?><?php echo !empty($assessment_filter) ? "&assessment=$assessment_filter" : ''; ?>">Next &raquo;</a>
          <?php else: ?>
            <span class="disabled">Next &raquo;</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <!-- View Bill Modal -->
  <div id="viewBillModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:800px; max-height:90vh; overflow:auto; border: 1px solid #f0f4f8; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
      <div style="background:var(--navy-700); color:white; padding:20px; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0;">Bill Details</h3>
        <button onclick="closeModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s ease;">&times;</button>
      </div>
      <div id="billDetails" style="padding:20px;">
        <div class="text-center text-muted">Loading...</div>
      </div>
      <div style="padding:20px; border-top:1px solid #eee; text-align:right;">
        <button onclick="closeModal()" class="btn" style="background:#6c757d; color:white;">Close</button>
      </div>
    </div>
  </div>

  <!-- Link Assessment Modal -->
  <div id="linkAssessmentModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:500px; max-height:90vh; overflow:auto; border: 1px solid #f0f4f8; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
      <div style="background:var(--navy-700); color:white; padding:20px; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0;">Link Financial Assessment</h3>
        <button onclick="closeLinkModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s ease;">&times;</button>
      </div>
      <div id="linkAssessmentContent" style="padding:20px;">
        <!-- Content will be loaded via AJAX -->
      </div>
    </div>
  </div>

  <div class="footer-shadow"></div>

  <script>
    /* -------------------------
       Modal functions
       ------------------------- */
    function viewBill(billId) {
        const detailsContainer = document.getElementById('billDetails');
        detailsContainer.innerHTML = '<div class="text-center py-3 text-muted">Loading bill details…</div>';
        
        const modal = document.getElementById('viewBillModal');
        modal.style.display = 'flex';
        
        fetch('billing_view.php?id=' + encodeURIComponent(billId))
        .then(response => response.text())
        .then(html => {
            detailsContainer.innerHTML = html;
        })
        .catch(err => {
            console.error('Error fetching bill details:', err);
            detailsContainer.innerHTML = '<div class="alert alert-danger">Error loading bill details. Please try again.</div>';
        });
    }

    function closeModal() {
        document.getElementById('viewBillModal').style.display = 'none';
    }

    function closeLinkModal() {
        document.getElementById('linkAssessmentModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('viewBillModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    document.getElementById('linkAssessmentModal').addEventListener('click', function(e) {
        if (e.target === this) closeLinkModal();
    });

    /* -------------------------
       Confirmation functions
       ------------------------- */
    function confirmMarkPaid(billId, patientName) {
        if (confirm(`Mark bill for "${patientName}" as paid? This action cannot be undone.`)) {
            window.location.href = 'billing.php?mark_paid=' + encodeURIComponent(billId);
        }
    }

    function confirmArchive(billId, patientName) {
        if (confirm(`Archive bill for "${patientName}"? This will mark the record as archived.`)) {
            window.location.href = 'billing.php?archive=' + encodeURIComponent(billId);
        }
    }

    function confirmRestore(billId, patientName) {
        if (confirm(`Restore bill for "${patientName}"?`)) {
            window.location.href = 'billing.php?restore=' + encodeURIComponent(billId);
        }
    }

    /* -------------------------
       Toast notifications
       ------------------------- */
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast');
        if (!container) return;
        
        const typeClasses = {
            'success': 'alert-success',
            'error': 'alert-error',
            'warning': 'alert-warning',
            'info': 'alert-info'
        };
        
        const toastClass = typeClasses[type] || 'alert-success';
        
        const toast = document.createElement('div');
        toast.className = `alert ${toastClass}`;
        toast.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="flex:1;">${message}</div>
                <button type="button" onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white; cursor:pointer; font-size:18px; margin-left:10px;">&times;</button>
            </div>
        `;
        
        container.appendChild(toast);
        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 5000);
    }

    /* -------------------------
       Search functionality
       ------------------------- */
    const searchInput = document.getElementById('searchInput');
    const billingTable = document.getElementById('billingTable');
    const tbody = billingTable.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const rowCount = document.getElementById('rowCount');

    function filterTable(q) {
        q = q.trim().toLowerCase();
        let visible = 0;
        rows.forEach(r => {
            const text = r.textContent.toLowerCase();
            const ok = q === '' || text.indexOf(q) !== -1;
            r.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });
        rowCount.textContent = visible;
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => filterTable(searchInput.value));
        filterTable('');
    }

    /* -------------------------
       On page load
       ------------------------- */
    document.addEventListener('DOMContentLoaded', function() {
        const success = <?php echo json_encode($success); ?>;
        const action  = <?php echo json_encode($action); ?>;
        const bId     = <?php echo json_encode($bill_id); ?>;

        if (success) {
            const messages = {
                'added': 'Bill created successfully!',
                'updated': 'Bill updated successfully!',
                'paid': 'Bill marked as paid!',
                'archived': 'Bill archived successfully!',
                'restored': 'Bill restored successfully!',
                'assessment_linked': 'Financial assessment linked successfully!',
                'error': 'An error occurred performing the action.'
            };
            const msg = messages[success] || 'Operation completed.';
            
            let toastType = 'success';
            if (success === 'error') toastType = 'error';
            else if (success === 'warning') toastType = 'warning';
            else if (success === 'info') toastType = 'info';
            
            showToast(msg, toastType);

            // Highlight row
            if (bId && ['added','updated','paid','archived','restored','assessment_linked'].includes(success)) {
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-bill-id="${bId}"]`);
                    if (row) {
                        row.style.backgroundColor = 'rgba(0, 31, 63, 0.1)';
                        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setTimeout(() => {
                            row.style.backgroundColor = '';
                        }, 2000);
                    }
                }, 300);
            }
        }
    });
  </script>
</body>
</html>