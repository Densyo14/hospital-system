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

// Pagination for transactions
$transactions_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $transactions_per_page;

// Get total count of transactions for pagination
$total_transactions_query = "SELECT COUNT(*) as total FROM billing WHERE is_archived = 0";
$total_transactions_result = mysqli_query($conn, $total_transactions_query);
$total_transactions_row = mysqli_fetch_assoc($total_transactions_result);
$total_transactions = $total_transactions_row['total'];
$total_pages = ceil($total_transactions / $transactions_per_page);

// Fetch paginated billing transactions for the table
$billing_query = "
    SELECT 
        billing.id,
        billing.total_amount,
        billing.amount_due,
        billing.status,
        billing.created_at,
        patients.first_name,
        patients.last_name
    FROM billing
    INNER JOIN patients ON billing.patient_id = patients.id
    WHERE billing.is_archived = 0
    ORDER BY billing.created_at DESC
    LIMIT $offset, $transactions_per_page
";
$billing_result = mysqli_query($conn, $billing_query);

// Get main statistics for KPI cards
$total_patients = fetchOne($conn, "SELECT COUNT(*) as total FROM patients WHERE is_archived = 0", null, []);
$today_appointments = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments WHERE DATE(schedule_datetime) = CURDATE() AND is_archived = 0", null, []);
$total_transactions_count = fetchOne($conn, "SELECT COUNT(*) as total FROM billing WHERE is_archived = 0", null, []);
$total_revenue = fetchOne($conn, "SELECT SUM(amount_due) as total FROM billing WHERE status = 'Paid' AND is_archived = 0", null, []);

// Get year and month from URL or use current
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : null;

// Get patient statistics for the graph
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

// Get today's appointments for the right panel
$today_date = date('Y-m-d');
$todays_appointments_query = "
    SELECT 
        appointments.schedule_datetime,
        patients.first_name,
        patients.last_name,
        appointments.reason
    FROM appointments
    INNER JOIN patients ON appointments.patient_id = patients.id
    WHERE DATE(appointments.schedule_datetime) = ? 
    AND appointments.is_archived = 0
    AND appointments.status IN ('Approved', 'Pending')
    ORDER BY appointments.schedule_datetime
    LIMIT 5
";
$todays_appointments = fetchAll($conn, $todays_appointments_query, 's', [$today_date]);

// Get recent reports/alerts
$recent_reports = [
    ['message' => 'New patient registration completed', 'time' => '1 minute ago'],
    ['message' => 'Surgery scheduled for tomorrow', 'time' => '10 minutes ago'],
    ['message' => 'Inventory restock needed for sutures', 'time' => '1 hour ago']
];

// Role-specific additional stats - Get counts first
if (in_array($current_role, ['Doctor', 'Nurse', 'Admin'])) {
    $pending_appointments = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments WHERE status = 'Pending' AND is_archived = 0", null, []);
    $pending_appointments_count = $pending_appointments['total'] ?? 0;
}

if (in_array($current_role, ['Doctor', 'Admin'])) {
    $scheduled_surgeries = fetchOne($conn, "SELECT COUNT(*) as total FROM surgeries WHERE status = 'Scheduled' AND is_archived = 0", null, []);
    $scheduled_surgeries_count = $scheduled_surgeries['total'] ?? 0;
}

if (in_array($current_role, ['Inventory', 'Admin'])) {
    $low_stock_items = fetchOne($conn, "SELECT COUNT(*) as total FROM inventory_items WHERE quantity <= threshold AND is_archived = 0", null, []);
    $low_stock_items_count = $low_stock_items['total'] ?? 0;
}

if (in_array($current_role, ['Billing', 'Admin'])) {
    $pending_payments = fetchOne($conn, "SELECT COUNT(*) as total FROM billing WHERE status = 'Unpaid' AND is_archived = 0", null, []);
    $pending_payments_count = $pending_payments['total'] ?? 0;
}

if (in_array($current_role, ['SocialWorker', 'Admin'])) {
    $pending_assessments = fetchOne($conn, "SELECT COUNT(*) as total FROM financial_assessment WHERE status = 'Pending' AND is_archived = 0", null, []);
    $pending_assessments_count = $pending_assessments['total'] ?? 0;
}

// Get available years for filter dropdown
$available_years_query = "SELECT DISTINCT YEAR(created_at) as year FROM patients WHERE created_at IS NOT NULL ORDER BY year DESC";
$available_years_result = mysqli_query($conn, $available_years_query);
$available_years = [];
while ($row = mysqli_fetch_assoc($available_years_result)) {
    $available_years[] = $row['year'];
}
if (empty($available_years)) {
    $available_years = [date('Y')];
}

// Get month names for modal
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard</title>

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
  .brand-text{font-weight:700;font-size:16px}

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
  .date-pill{
    background:var(--panel);
    padding:8px 12px;
    border-radius:999px;
    box-shadow:0 4px 14px rgba(16,24,40,0.06);
    font-size:13px;
    white-space: nowrap;
    border: 1px solid #e6eef0;
  }

  /* KPI Cards */
  .cards-row{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    margin:18px 0;
  }
  .kcard{
    background:var(--panel);
    padding:18px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    position:relative;
    overflow:visible;
    cursor:pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    text-decoration: none;
    color: inherit;
    display: block;
    border: 1px solid #f0f4f8;
  }
  .kcard:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(16,24,40,0.12);
    border-color: var(--light-blue);
  }
  .kcard h4{margin:0;color:var(--muted);font-weight:600;font-size:14px}
  .kcard .value{font-size:24px;font-weight:800;margin-top:12px; color: var(--navy-700);}
  .kcard .kicon{position:absolute;right:14px;top:14px;font-size:20px;opacity:0.95; color: var(--light-blue);}

  /* Main grid */
  .main-grid{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
  .panel{background:var(--panel);border-radius:14px;padding:18px;box-shadow:var(--card-shadow); border: 1px solid #f0f4f8;}
  .panel h3{margin:0 0 12px 0;font-size:16px; color: var(--navy-700);}

  .graph-box{height:300px;border-radius:10px;background:#fbfdfe;padding:12px; border: 1px solid #e6eef0;}
  .small-list{display:flex;flex-direction:column;gap:12px}
  .small-item{
    background:#fbfeff;
    padding:12px;
    border-radius:10px;
    border:1px solid #f0f4f3;
    font-size:14px;
    transition: all 0.2s ease;
  }
  .small-item:hover {
    border-color: var(--light-blue);
    background: #f0f9ff;
  }

  .kpis{display:flex;gap:12px;margin-top:14px}
  .kpi{
    flex:1;
    background:var(--panel);
    padding:12px;
    border-radius:10px;
    box-shadow:var(--card-shadow);
    cursor:pointer;
    transition: transform 0.2s ease;
    text-decoration: none;
    color: inherit;
    display: block;
    border: 1px solid #f0f4f8;
  }
  .kpi:hover {
    transform: translateY(-2px);
    border-color: var(--light-blue);
  }
  .kpi h4{margin:0;font-size:14px} 
  .kpi p{margin:6px 0 0 0;font-weight:700; color: var(--navy-700);}

  /* Transactions table */
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
  .status{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .paid{background:#e8f4ff;color:#1e6b8a}
  .pending{background:#fff8e1;color:#8a6d00}
  .failed{background:#ffe8e8;color:#b02b2b}

  /* Transaction row clickable */
  .transaction-row {
    cursor: pointer;
    transition: background-color 0.2s ease;
  }
  .transaction-row:hover {
    background-color: #f8fbfd;
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

  /* Role badge styling */
  .role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
    margin-left: 10px;
  }
  .role-admin { background: #001F3F; color: white; }
  .role-doctor { background: #003366; color: white; }
  .role-nurse { background: #4d8cc9; color: white; }
  .role-staff { background: #6b7280; color: white; }
  .role-inventory { background: #1e6b8a; color: white; }
  .role-billing { background: #0066cc; color: white; }
  .role-socialworker { background: #34495e; color: white; }

  /* Role stats section */
  .role-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
  }
  .role-stat-card {
    background: var(--panel);
    padding: 20px;
    border-radius: 8px;
    box-shadow: var(--card-shadow);
    text-align: center;
    cursor: pointer;
    transition: transform 0.2s ease;
    text-decoration: none;
    color: inherit;
    display: block;
    border: 1px solid #f0f4f8;
  }
  .role-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(16,24,40,0.12);
    border-color: var(--light-blue);
  }
  .role-stat-card h4 {
    margin: 0 0 10px 0;
    font-size: 0.9rem;
    color: var(--muted);
  }
  .role-stat-card p {
    margin: 0;
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--navy-700);
  }

  /* Graph filter controls */
  .graph-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 15px;
    flex-wrap: wrap;
  }
  .filter-select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #e6eef0;
    background: white;
    font-size: 14px;
  }
  .filter-select:focus {
    outline: none;
    border-color: var(--light-blue);
  }
  .filter-btn {
    background: var(--navy-700);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.2s ease;
  }
  .filter-btn:hover {
    background: var(--accent);
    transform: translateY(-1px);
  }
  .reset-btn {
    background: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.2s ease;
  }
  .reset-btn:hover {
    background: #5a6268;
    transform: translateY(-1px);
  }

  /* Modal Styles */
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
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    z-index: 1001;
    width: 90%;
    max-width: 800px;
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

  .modal-header h3 {
    margin: 0;
    font-size: 18px;
  }

  .close-modal {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s ease;
  }

  .close-modal:hover {
    background: rgba(255, 255, 255, 0.2);
  }

  .modal-content {
    padding: 24px;
    max-height: calc(80vh - 70px);
    overflow-y: auto;
  }

  .patient-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .patient-card {
    background: #f8fbfd;
    border-radius: 8px;
    padding: 16px;
    border: 1px solid #e6eef0;
    transition: all 0.2s ease;
  }

  .patient-card:hover {
    border-color: var(--navy-700);
    background: #f0f7ff;
  }

  .patient-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
  }

  .patient-name {
    font-weight: 600;
    font-size: 16px;
    color: #233;
  }

  .patient-code {
    background: #e6eef0;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    color: #6b7280;
  }

  .patient-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    font-size: 14px;
  }

  .detail-item {
    display: flex;
    gap: 8px;
  }

  .detail-label {
    color: #6b7280;
    min-width: 80px;
  }

  .detail-value {
    color: #233;
    font-weight: 500;
  }

  .surgery-badge {
    display: inline-block;
    padding: 4px 8px;
    background: #e8f4ff;
    color: #1e6b8a;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 8px;
  }

  .no-patients {
    text-align: center;
    padding: 40px;
    color: #6b7280;
  }

  /* Modal Tabs */
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
    color: #6b7280;
    transition: all 0.2s ease;
  }

  .tab-btn.active {
    color: var(--navy-700);
    border-bottom-color: var(--navy-700);
  }

  .tab-btn:hover:not(.active) {
    color: #233;
    background: #f8fbfd;
  }

  .tab-content {
    display: none;
  }

  .tab-content.active {
    display: block;
  }

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
    .cards-row{grid-template-columns:repeat(2,1fr)}
    .main-grid{grid-template-columns:1fr}
    .kcard .kicon{display:none}
    .table-wrap table{min-width:700px}
    .graph-controls {
      flex-direction: column;
      align-items: flex-start;
    }
    .modal {
      width: 95%;
      max-height: 90vh;
    }
    .modal-content {
      max-height: calc(90vh - 70px);
    }
  }
  @media (max-width:780px){
    .sidebar{position:fixed;left:-320px;transform:translateX(0)}
    .sidebar.open{left:0;transform:translateX(0)}
    .main{margin-left:0;padding:12px}
    .sidebar.collapsed{width:230px}
    .topbar{flex-direction:column;align-items:flex-start}
    .top-actions{width:100%;justify-content:space-between}
    .patient-details {
      grid-template-columns: 1fr;
    }
    .modal-tabs {
      flex-direction: column;
    }
    .tab-btn {
      border-bottom: none;
      border-left: 3px solid transparent;
      text-align: left;
    }
    .tab-btn.active {
      border-left-color: var(--navy-700);
    }
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

      <!-- User info like in financials.php -->
      <div class="user-info">
        <h4>Logged as:</h4>
        <p><?php echo htmlspecialchars($current_name); ?><br><strong><?php echo htmlspecialchars($current_role); ?></strong></p>
      </div>

      <nav class="menu" id="mainMenu">
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
            <a href="<?php echo $page; ?>" class="menu-item">
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
          <h1>Hello, <?php echo htmlspecialchars($current_name); ?> 
            <span style="font-size:18px">👋</span>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Welcome to Gig Oca Robles Seamen's Hospital Davao Management System</p>
        </div>

        <div class="top-actions">
          <a href="patients.php?action=add" class="btn">+ Add patient</a>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- KPI Cards - All clickable WITH FILTER PARAMETERS -->
      <section class="cards-row" aria-label="overview cards">
        <a href="patients.php" class="kcard">
          <h4>Total Patients</h4>
          <div class="value">
            <?php echo number_format($total_patients['total'] ?? 0); ?>
          </div>
          <div class="kicon">👤</div>
        </a>

        <a href="appointments.php?filter=today" class="kcard">
          <h4>Appointments Today</h4>
          <div class="value">
            <?php echo number_format($today_appointments['total'] ?? 0); ?>
          </div>
          <div class="kicon">📅</div>
        </a>

        <a href="billing.php" class="kcard">
          <h4>Transactions</h4>
          <div class="value">
            <?php echo number_format($total_transactions_count['total'] ?? 0); ?>
          </div>
          <div class="kicon">💳</div>
        </a>

        <a href="financials.php" class="kcard">
          <h4>Total Revenue</h4>
          <div class="value">
            ₱ <?php echo number_format($total_revenue['total'] ?? 0, 2); ?>
          </div>
          <div class="kicon">🪙</div>
        </a>
      </section>

      <!-- Role-Specific Statistics - All clickable WITH FILTER PARAMETERS -->
      <?php if (in_array($current_role, ['Doctor', 'Nurse', 'Admin', 'Inventory', 'Billing', 'SocialWorker'])): ?>
      <div class="section-title">Your Department Overview</div>
      <div class="role-stats">
        <?php if (in_array($current_role, ['Doctor', 'Nurse', 'Admin'])): ?>
          <a href="appointments.php?status=Pending" class="role-stat-card">
            <h4>Pending Appointments</h4>
            <p><?php echo number_format($pending_appointments_count ?? 0); ?></p>
          </a>
        <?php endif; ?>

        <?php if (in_array($current_role, ['Doctor', 'Admin'])): ?>
          <a href="surgeries.php?status=Scheduled" class="role-stat-card">
            <h4>Scheduled Surgeries</h4>
            <p><?php echo number_format($scheduled_surgeries_count ?? 0); ?></p>
          </a>
        <?php endif; ?>

        <?php if (in_array($current_role, ['Inventory', 'Admin'])): ?>
          <a href="inventory.php?filter=low_stock" class="role-stat-card">
            <h4>Low Stock Items</h4>
            <p><?php echo number_format($low_stock_items_count ?? 0); ?></p>
          </a>
        <?php endif; ?>

        <?php if (in_array($current_role, ['Billing', 'Admin'])): ?>
          <a href="billing.php?status=Unpaid" class="role-stat-card">
            <h4>Pending Payments</h4>
            <p><?php echo number_format($pending_payments_count ?? 0); ?></p>
          </a>
        <?php endif; ?>

        <?php if (in_array($current_role, ['SocialWorker', 'Admin'])): ?>
          <a href="financials.php?status=Pending" class="role-stat-card">
            <h4>Pending Assessments</h4>
            <p><?php echo number_format($pending_assessments_count ?? 0); ?></p>
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Transactions + Other Panels -->
      <div class="section-title">Latest Transactions</div>

      <div class="table-wrap" id="transactionsSection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search transactions (ID, patient, type...)">
          </div>
          <div class="muted">Showing <span id="rowCount"><?php echo mysqli_num_rows($billing_result); ?></span> results</div>
        </div>

        <table id="txTable" aria-label="Latest transactions table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Patient</th>
              <th>Date</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>

          <tbody>
            <?php if (mysqli_num_rows($billing_result) > 0): ?>
              <?php while ($row = mysqli_fetch_assoc($billing_result)): ?>
                <tr class="transaction-row" onclick="window.location.href='billing.php?action=view&id=<?php echo $row['id']; ?>'">
                  <td>TX-<?php echo htmlspecialchars($row['id']); ?></td>
                  <td><?php echo htmlspecialchars($row['first_name'] . " " . $row['last_name']); ?></td>
                  <td><?php echo date("Y-m-d", strtotime($row['created_at'])); ?></td>
                  <td>₱ <?php echo number_format($row['amount_due'], 2); ?></td>
                  <td>
                    <?php if ($row['status'] == "Paid"): ?>
                      <span class="status paid">Paid</span>
                    <?php else: ?>
                      <span class="status pending">Pending</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align:center; color:#888;">No transactions found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?>">&laquo; Previous</a>
          <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
          <?php endif; ?>
          
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $current_page): ?>
              <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
              <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?>">Next &raquo;</a>
          <?php else: ?>
            <span class="disabled">Next &raquo;</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Main grid (patient stats + small right column) -->
      <div style="height:18px"></div>
      <div class="main-grid" style="margin-top:18px">
        <div>
          <div class="panel">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <h3>Patient Statistics - <?php echo $current_year; ?></h3>
              <div class="graph-controls">
                <select id="yearFilter" class="filter-select">
                  <option value="">Select Year</option>
                  <?php foreach($available_years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $current_year == $year ? 'selected' : ''; ?>>
                      <?php echo $year; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                
                <button id="applyFilter" class="filter-btn">Apply Filter</button>
                <a href="dashboard.php" class="reset-btn" style="text-decoration:none; display:inline-block;">Reset</a>
              </div>
            </div>
            
            <div class="graph-box">
              <canvas id="patientsChart" style="width:100%;height:100%"></canvas>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:12px">
              <div style="display:flex;gap:12px;align-items:center">
                <div style="width:10px;height:10px;border-radius:50%;background:#001F3F"></div>
                <div style="font-size:13px;color:var(--muted)">Total patients</div>

                <div style="width:10px;height:10px;border-radius:50%;background:#4d8cc9;margin-left:14px"></div>
                <div style="font-size:13px;color:var(--muted)">Surgical patients</div>
              </div>

              <div style="font-size:13px;color:var(--muted)">Year-<?php echo $current_year; ?></div>
            </div>
          </div>

          <div style="display:flex;gap:14px;margin-top:16px">
            <a href="financials.php" class="kpi">
              <h4>Revenue This Month</h4>
              <p>₱ <?php 
                $month_revenue = fetchOne($conn, 
                  "SELECT SUM(amount_due) as total FROM billing 
                   WHERE status = 'Paid' AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
                   AND YEAR(created_at) = YEAR(CURRENT_DATE())", null, []);
                echo number_format($month_revenue['total'] ?? 0, 2);
              ?></p>
              <small style="color:var(--muted)">Monthly Revenue</small>
            </a>

            <a href="surgeries.php?status=Scheduled" class="kpi">
              <h4>Active Surgeries</h4>
              <p><?php 
                $active_surgeries = fetchOne($conn, 
                  "SELECT COUNT(*) as total FROM surgeries 
                   WHERE status = 'Scheduled' AND is_archived = 0", null, []);
                echo number_format($active_surgeries['total'] ?? 0);
              ?></p>
              <small style="color:var(--muted)">Scheduled procedures</small>
            </a>
          </div>
        </div>

        <aside class="right" style="min-width:280px">
          <div class="panel" style="margin-bottom:12px">
            <h3>Today <?php echo date('d M Y'); ?></h3>

            <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px">
              <?php if (!empty($todays_appointments)): ?>
                <?php foreach($todays_appointments as $appointment): ?>
                  <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f0f4f3; cursor:pointer;" 
                       onclick="window.location.href='appointments.php?filter=today'">
                    <div>
                      <div style="font-weight:500;"><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></div>
                      <div style="font-size:12px;color:var(--muted);"><?php echo htmlspecialchars($appointment['reason']); ?></div>
                    </div>
                    <div style="color:var(--muted);font-size:12px;">
                      <?php echo date("H:i", strtotime($appointment['schedule_datetime'])); ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div style="text-align:center;color:var(--muted);padding:20px; cursor:pointer;" 
                     onclick="window.location.href='appointments.php?filter=today'">
                  No appointments today (Click to view all)
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="panel small-list">
            <h3 style="margin-bottom:8px">Recent Activity</h3>
            <?php foreach($recent_reports as $report): ?>
              <div class="small-item" style="cursor:pointer;" onclick="window.location.href='patients.php'">
                <?php echo htmlspecialchars($report['message']); ?>
                <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                  <?php echo $report['time']; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </aside>
      </div>

      <div style="height:36px"></div>
    </div><!-- .main -->

  </div><!-- .app -->

  <!-- Patient Details Modal -->
  <div class="modal-overlay" id="modalOverlay"></div>
  <div class="modal" id="patientModal">
    <div class="modal-header">
      <h3 id="modalTitle">Patient Details</h3>
      <button class="close-modal" id="closeModal">&times;</button>
    </div>
    <div class="modal-content" id="modalContent">
      <!-- Content will be loaded via AJAX -->
    </div>
  </div>

  <div class="footer-shadow"></div>

  <!-- Chart.js CDN -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <script>
    /* -------------------------
       Graph filter functionality
       ------------------------- */
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

    /* -------------------------
       Table search / filter
       ------------------------- */
    const searchInput = document.getElementById('searchInput');
    const txTable = document.getElementById('txTable');
    const tbody = txTable.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const rowCount = document.getElementById('rowCount');

    function filterTable(q){
      q = q.trim().toLowerCase();
      let visible = 0;
      rows.forEach(r=>{
        const text = r.textContent.toLowerCase();
        const ok = q === '' || text.indexOf(q) !== -1;
        r.style.display = ok ? '' : 'none';
        if(ok) visible++;
      });
      rowCount.textContent = visible;
    }

    if (searchInput) {
      searchInput.addEventListener('input', () => filterTable(searchInput.value));
      // initialize count
      filterTable('');
    }

    /* -------------------------
       Modal functionality
       ------------------------- */
    const modalOverlay = document.getElementById('modalOverlay');
    const patientModal = document.getElementById('patientModal');
    const closeModal = document.getElementById('closeModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalContent = document.getElementById('modalContent');

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

    // Close modal when clicking overlay or close button
    modalOverlay.addEventListener('click', hideModal);
    closeModal.addEventListener('click', hideModal);

    // Close modal with Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') hideModal();
    });

    // Prevent modal from closing when clicking inside modal
    patientModal.addEventListener('click', (e) => {
      e.stopPropagation();
    });

    /* -------------------------
       Load patient data via AJAX
       ------------------------- */
    async function loadPatientData(month, year) {
      try {
        const response = await fetch(`get_patient_data.php?month=${month}&year=${year}`);
        const data = await response.json();
        
        modalTitle.textContent = `Patient Details - ${data.month_name} ${year}`;
        
        let content = `
          <div class="modal-tabs">
            <button class="tab-btn active" onclick="switchTab('all-patients')">All Patients (${data.total_count})</button>
            <button class="tab-btn" onclick="switchTab('surgical-patients')">Surgical Patients (${data.surgical_count})</button>
          </div>
        `;

        // All Patients Tab
        content += `
          <div id="all-patients" class="tab-content active">
            <div class="patient-list">
        `;
        
        if (data.all_patients.length > 0) {
          data.all_patients.forEach(patient => {
            const hasSurgery = data.surgical_patients.some(sp => sp.id === patient.id);
            content += `
              <div class="patient-card">
                <div class="patient-header">
                  <div class="patient-name">${patient.first_name} ${patient.last_name}</div>
                  <div class="patient-code">${patient.patient_code}</div>
                </div>
                <div class="patient-details">
                  <div class="detail-item">
                    <span class="detail-label">Gender:</span>
                    <span class="detail-value">${patient.sex || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Age:</span>
                    <span class="detail-value">${patient.age || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">${patient.phone || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Guardian:</span>
                    <span class="detail-value">${patient.guardian_name || 'N/A'}</span>
                  </div>
                </div>
                ${hasSurgery ? '<div class="surgery-badge">Has Surgery</div>' : ''}
              </div>
            `;
          });
        } else {
          content += `
            <div class="no-patients">
              <p>No patients found for ${data.month_name} ${year}</p>
            </div>
          `;
        }
        
        content += `
            </div>
          </div>
        `;

        // Surgical Patients Tab
        content += `
          <div id="surgical-patients" class="tab-content">
            <div class="patient-list">
        `;
        
        if (data.surgical_patients.length > 0) {
          data.surgical_patients.forEach(patient => {
            content += `
              <div class="patient-card">
                <div class="patient-header">
                  <div class="patient-name">${patient.first_name} ${patient.last_name}</div>
                  <div class="patient-code">${patient.patient_code}</div>
                </div>
                <div class="patient-details">
                  <div class="detail-item">
                    <span class="detail-label">Surgery Type:</span>
                    <span class="detail-value">${patient.surgery_type || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Surgery Date:</span>
                    <span class="detail-value">${patient.surgery_date || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Doctor:</span>
                    <span class="detail-value">${patient.doctor_name || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">${patient.surgery_status || 'N/A'}</span>
                  </div>
                </div>
                <div class="patient-details" style="margin-top: 8px;">
                  <div class="detail-item">
                    <span class="detail-label">Gender:</span>
                    <span class="detail-value">${patient.sex || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Age:</span>
                    <span class="detail-value">${patient.age || 'N/A'}</span>
                  </div>
                  <div class="detail-item">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">${patient.phone || 'N/A'}</span>
                  </div>
                </div>
              </div>
            `;
          });
        } else {
          content += `
            <div class="no-patients">
              <p>No surgical patients found for ${data.month_name} ${year}</p>
            </div>
          `;
        }
        
        content += `
            </div>
          </div>
        `;

        modalContent.innerHTML = content;
        showModal();
      } catch (error) {
        console.error('Error loading patient data:', error);
        modalContent.innerHTML = '<div class="no-patients"><p>Error loading patient data. Please try again.</p></div>';
        showModal();
      }
    }

    // Tab switching function
    window.switchTab = function(tabId) {
      // Hide all tab contents
      document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
      });
      
      // Show selected tab content
      document.getElementById(tabId).classList.add('active');
      
      // Update active tab button
      document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
      });
      
      // Find the button that was clicked
      const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => 
        btn.getAttribute('onclick')?.includes(tabId)
      );
      if (activeBtn) {
        activeBtn.classList.add('active');
      }
    }

    /* -------------------------
       Chart.js - Patient Statistics
       ------------------------- */
    const ctx = document.getElementById('patientsChart').getContext('2d');
    const gradient1 = ctx.createLinearGradient(0,0,0,300);
    gradient1.addColorStop(0,'rgba(0, 31, 63, 0.18)'); // Dark navy
    gradient1.addColorStop(1,'rgba(0, 31, 63, 0.02)');

    const gradient2 = ctx.createLinearGradient(0,0,0,300);
    gradient2.addColorStop(0,'rgba(77, 140, 201, 0.16)'); // Light blue
    gradient2.addColorStop(1,'rgba(77, 140, 201, 0.02)');

    if (ctx) {
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: <?php echo json_encode($monthly_labels); ?>,
          datasets: [
            {
              label:'Total patients',
              data: <?php echo json_encode($monthly_totals); ?>,
              borderColor:'#001F3F', // Dark navy
              backgroundColor: gradient1,
              tension:0.4,
              pointRadius:4,
              pointBackgroundColor:'#001F3F', // Dark navy
              pointBorderColor:'#ffffff',
              pointBorderWidth:2,
              pointHoverRadius:6,
              fill:true,
              borderWidth:3
            },
            {
              label:'Surgical patients',
              data: <?php echo json_encode($monthly_surgical); ?>,
              borderColor:'#4d8cc9', // Light blue
              backgroundColor: gradient2,
              tension:0.4,
              pointRadius:4,
              pointBackgroundColor:'#4d8cc9', // Light blue
              pointBorderColor:'#ffffff',
              pointBorderWidth:2,
              pointHoverRadius:6,
              fill:true,
              borderWidth:3
            }
          ]
        },
        options: {
          responsive:true,
          maintainAspectRatio:false,
          plugins: {
            legend:{display:false},
            tooltip:{
              mode:'index',
              intersect:false,
              callbacks: {
                label: function(context) {
                  let label = context.dataset.label || '';
                  if (label) {
                    label += ': ';
                  }
                  label += context.parsed.y;
                  return label;
                }
              }
            }
          },
          scales:{
            x:{
              grid:{display:false},
              ticks:{color:'#6b7280'}
            },
            y:{
              grid:{color:'rgba(15,23,36,0.06)'},
              ticks:{color:'#6b7280'},
              beginAtZero: true
            }
          },
          interaction: {
            intersect: false,
            mode: 'index'
          },
          // Make chart clickable - when a point is clicked, load patient data
          onClick: (evt, elements) => {
            if (elements.length > 0) {
              const element = elements[0];
              const monthIndex = element.index;
              const month = monthIndex + 1; // Convert to 1-12
              const year = <?php echo $current_year; ?>;
              
              // Load patient data for this month
              loadPatientData(month, year);
            }
          }
        }
      });
    }
  </script>
</body>
</html>