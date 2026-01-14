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

// Define role permissions for navigation - ADDED REPORTS TO ALL ROLES
$role_permissions = [
    'Admin' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients', 
        'appointments.php' => 'Appointments',
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'billing.php' => 'Billing',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports',  // ADDED THIS LINE
        'users.php' => 'Users'
    ],
    'Doctor' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments', 
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'  // ADDED THIS LINE
    ],
    'Nurse' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'  // ADDED THIS LINE
    ],
    'Staff' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'reports.php' => 'Reports'  // ADDED THIS LINE
    ],
    'Inventory' => [
        'dashboard.php' => 'Dashboard',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'  // ADDED THIS LINE
    ],
    'Billing' => [
        'dashboard.php' => 'Dashboard', 
        'billing.php' => 'Billing',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'  // ADDED THIS LINE
    ],
    'SocialWorker' => [
        'dashboard.php' => 'Dashboard',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'  // ADDED THIS LINE
    ]
];

// Get allowed pages for current role
$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// ========== NEW CODE: Handle filter parameters from dashboard ==========
// Get filter parameters from URL
$filter = $_GET['filter'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';

// Build WHERE conditions based on filters
$where_conditions = ["a.is_archived = 0"];
$params = [];
$types = '';

// Handle 'today' filter from dashboard
if ($filter === 'today') {
    $where_conditions[] = "DATE(a.schedule_datetime) = CURDATE()";
}

// Handle status filter from dashboard (like 'Pending', etc.)
if (!empty($status_filter) && in_array($status_filter, ['Pending', 'Approved', 'Completed', 'Cancelled'])) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

// Handle date filter if provided
if (!empty($date_filter) && validateDate($date_filter, 'Y-m-d')) {
    $where_conditions[] = "DATE(a.schedule_datetime) = ?";
    $params[] = $date_filter;
    $types .= 's';
}

// Combine WHERE conditions for main query
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : 'WHERE a.is_archived = 0';
// ========== END NEW CODE ==========

// Preprocessing
$success = isset($_GET['success']) ? $_GET['success'] : '';
$action  = isset($_GET['action']) ? $_GET['action'] : '';
$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

// Current user info for permission checks
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';

// ARCHIVE action (admin only)
if (isset($_GET['archive']) && $is_admin) {
    $id = (int)$_GET['archive'];
    
    // Archive the appointment
    $stmt = execute($conn, 
        "UPDATE appointments SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?", 
        "ii", [$current_user_id, $id]
    );
    
    // Log the archive action
    $log_stmt = execute($conn,
        "INSERT INTO archive_logs (table_name, record_id, archived_by, archived_at, reason) VALUES (?, ?, ?, NOW(), ?)",
        "siis", ['appointments', $id, $current_user_id, 'Archived by administrator']
    );
    
    header("Location: appointments.php?success=archived&action=archive&appointment_id=" . $id);
    exit();
}

// RESTORE action (admin only)  
if (isset($_GET['restore']) && $is_admin) {
    $id = (int)$_GET['restore'];
    
    // Unarchive the appointment
    $stmt = execute($conn, 
        "UPDATE appointments SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?", 
        "i", [$id]
    );
    
    header("Location: appointments.php?success=restored&action=restore&appointment_id=" . $id);
    exit();
}

// Check if we're viewing archived appointments
$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';
$archive_condition = $show_archived ? "a.is_archived = 1" : "a.is_archived = 0";

// Sorting
$sort_column = $_GET['sort'] ?? 'schedule';
$sort_order = $_GET['order'] ?? 'desc';

// Validate sort column
$allowed_columns = ['id', 'patient', 'doctor', 'schedule', 'reason', 'status'];
if (!in_array($sort_column, $allowed_columns)) {
    $sort_column = 'schedule';
}

// Validate sort order
if (!in_array($sort_order, ['asc', 'desc'])) {
    $sort_order = 'desc';
}

// Build ORDER BY clause based on sort column
$order_by = '';
switch ($sort_column) {
    case 'id':
        $order_by = "a.id";
        break;
    case 'patient':
        $order_by = "p.last_name, p.first_name";
        break;
    case 'doctor':
        $order_by = "u.full_name";
        break;
    case 'schedule':
        $order_by = "a.schedule_datetime";
        break;
    case 'reason':
        $order_by = "a.reason";
        break;
    case 'status':
        $order_by = "a.status";
        break;
    default:
        $order_by = "a.schedule_datetime";
}

// Only add sort order
$order_by .= " " . strtoupper($sort_order);

// Pagination
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// ========== UPDATED CODE: Get total count with filters ==========
// Get total count of appointments for pagination WITH FILTERS
$total_appointments_query = "SELECT COUNT(*) as total FROM appointments a ";
if ($show_archived) {
    $total_appointments_query .= "WHERE a.is_archived = 1";
} else {
    // Use the filter conditions from dashboard
    $where_conditions_count = ["a.is_archived = 0"];
    if ($filter === 'today') {
        $where_conditions_count[] = "DATE(a.schedule_datetime) = CURDATE()";
    }
    if (!empty($status_filter) && in_array($status_filter, ['Pending', 'Approved', 'Completed', 'Cancelled'])) {
        $where_conditions_count[] = "a.status = '$status_filter'";
    }
    if (!empty($date_filter) && validateDate($date_filter, 'Y-m-d')) {
        $where_conditions_count[] = "DATE(a.schedule_datetime) = '$date_filter'";
    }
    $total_appointments_query .= "WHERE " . implode(' AND ', $where_conditions_count);
}

$total_appointments_result = mysqli_query($conn, $total_appointments_query);
$total_appointments_row = mysqli_fetch_assoc($total_appointments_result);
$total_appointments = $total_appointments_row['total'];
$total_pages = ceil($total_appointments / $items_per_page);
// ========== END UPDATED CODE ==========

// ========== UPDATED CODE: Fetch appointments with filters ==========
// Base query
$query = "
    SELECT a.*, 
           p.first_name AS patient_first, p.last_name AS patient_last,
           u.full_name AS doctor_name,
           arch.full_name AS archived_by_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON a.doctor_id = u.id
    LEFT JOIN users arch ON a.archived_by = arch.id
";

// Add WHERE conditions
if ($show_archived) {
    $query .= " WHERE a.is_archived = 1";
} else {
    // Use the filter conditions from dashboard
    $where_conditions_query = ["a.is_archived = 0"];
    $query_params = [];
    $query_types = '';
    
    if ($filter === 'today') {
        $where_conditions_query[] = "DATE(a.schedule_datetime) = CURDATE()";
    }
    if (!empty($status_filter) && in_array($status_filter, ['Pending', 'Approved', 'Completed', 'Cancelled'])) {
        $where_conditions_query[] = "a.status = ?";
        $query_params[] = $status_filter;
        $query_types .= 's';
    }
    if (!empty($date_filter) && validateDate($date_filter, 'Y-m-d')) {
        $where_conditions_query[] = "DATE(a.schedule_datetime) = ?";
        $query_params[] = $date_filter;
        $query_types .= 's';
    }
    
    $query .= " WHERE " . implode(' AND ', $where_conditions_query);
}

// Add ORDER BY and LIMIT
$query .= " ORDER BY $order_by LIMIT ?, ?";

// Add pagination parameters
$query_params[] = $offset;
$query_params[] = $items_per_page;
$query_types .= 'ii';

// Prepare and execute query
$stmt = mysqli_prepare($conn, $query);
if (!empty($query_params)) {
    mysqli_stmt_bind_param($stmt, $query_types, ...$query_params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Fetch all appointments
$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}
// ========== END UPDATED CODE ==========
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Tebowcure - Appointments</title>

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
    color:rgba(255,255,255,0.95);
    font-weight:500;
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s ease;
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

  /* Responsive adjustments */
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
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
  }
  .btn:hover {
    background:var(--accent);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
  }
  .btn-warning {
    background: #f39c12;
    color: #fff;
  }
  .btn-info {
    background: #3498db;
    color: #fff;
  }
  .btn-secondary {
    background: #6c757d;
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

  /* ========== NEW STYLE: Filter badge ========== */
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
  /* ========== END NEW STYLE ========== */

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

  /* Toast color classes */
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

  .alert-primary {
    background: var(--navy-700);
    border-left: 4px solid var(--accent);
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

  /* Appointments table */
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
  
  /* Sortable headers */
  .sortable-header {
    cursor: pointer;
    user-select: none;
    position: relative;
    padding-right: 20px !important;
  }
  
  .sortable-header:hover {
    background-color: #f0f3f4;
  }
  
  .sortable-header:after {
    content: '';
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
  }
  
  .sortable-header.asc:after {
    border-bottom: 5px solid var(--navy-700);
    border-top: none;
  }
  
  .sortable-header.desc:after {
    border-top: 5px solid var(--navy-700);
    border-bottom: none;
  }
  
  .sortable-header:not(.asc):not(.desc):after {
    border-top: 5px solid #ccc;
    border-bottom: 5px solid transparent;
    opacity: 0.5;
  }
  
  /* Status badges */
  .status{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .pending{background:#fff8e1;color:#8a6d00}
  .approved{background:#e8f4ff;color:#1e6b8a}
  .completed{background:#e0f2fe;color:#0369a1}
  .cancelled{background:#ffe8e8;color:#b02b2b}
  .archived{background:#95a5a6;color:white}
  
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

  /* Responsive */
  @media (max-width:1100px){
    .table-wrap table{min-width:700px}
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
        <a href="dashboard.php" style="display: block;">
          <img src="logo.jpg" alt="Tebow Cure Logo">
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
              <path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l 2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
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
          <h1>Appointments <?php echo $show_archived ? '(Archived)' : ''; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Manage patient appointments and schedules</p>
        </div>

        <div class="top-actions">
          <?php if ($show_archived): ?>
            <a href="appointments.php" class="btn" style="background: #6c757d; color: white;">View Active Appointments</a>
          <?php else: ?>
            <a href="appointment_form.php" class="btn">+ Add Appointment</a>
            <?php if ($is_admin): ?>
              <a href="appointments.php?show=archived" class="btn" style="background: #6c757d; color: white;">View Archived</a>
            <?php endif; ?>
          <?php endif; ?>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- ========== NEW CODE: Active Filter Badge ========== -->
      <?php if (!empty($filter) || !empty($status_filter) || !empty($date_filter)): ?>
        <div class="filter-badge">
          Filter applied: 
          <?php 
            $filter_text = [];
            if ($filter === 'today') $filter_text[] = "Today's Appointments";
            if (!empty($status_filter)) $filter_text[] = "Status: $status_filter";
            if (!empty($date_filter)) $filter_text[] = "Date: " . date('M j, Y', strtotime($date_filter));
            echo implode(' • ', $filter_text);
          ?>
          <a href="appointments.php" class="close-btn">&times;</a>
        </div>
      <?php endif; ?>
      <!-- ========== END NEW CODE ========== -->

      <!-- Appointments table -->
      <div class="table-wrap" id="appointmentsSection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by patient or doctor name">
          </div>
          <div class="muted">Showing <span id="rowCount"><?php echo count($rows); ?></span> of <?php echo number_format($total_appointments); ?> appointments</div>
        </div>

        <table id="appointmentsTable" aria-label="Appointments table">
          <thead>
            <tr>
              <th class="sortable-header <?php echo $sort_column == 'id' ? $sort_order : ''; ?>" 
                  onclick="sortTable('id', '<?php echo $sort_column == 'id' && $sort_order == 'asc' ? 'desc' : 'asc'; ?>')">ID</th>
              <th class="sortable-header <?php echo $sort_column == 'patient' ? $sort_order : ''; ?>" 
                  onclick="sortTable('patient', '<?php echo $sort_column == 'patient' && $sort_order == 'asc' ? 'desc' : 'asc'; ?>')">Patient</th>
              <th class="sortable-header <?php echo $sort_column == 'doctor' ? $sort_order : ''; ?>" 
                  onclick="sortTable('doctor', '<?php echo $sort_column == 'doctor' && $sort_order == 'asc' ? 'desc' : 'asc'; ?>')">Doctor</th>
              <th class="sortable-header <?php echo $sort_column == 'schedule' ? $sort_order : ''; ?>" 
                  onclick="sortTable('schedule', '<?php echo $sort_column == 'schedule' && $sort_order == 'asc' ? 'desc' : 'asc'; ?>')">Schedule</th>
              <th class="sortable-header <?php echo $sort_column == 'reason' ? $sort_order : ''; ?>" 
                  onclick="sortTable('reason', '<?php echo $sort_column == 'reason' && $sort_order == 'asc' ? 'desc' : 'asc'; ?>')">Reason</th>
              <th class="sortable-header <?php echo $sort_column == 'status' ? $sort_order : ''; ?>" 
                  onclick="sortTable('status', '<?php echo $sort_column == 'status' && $sort_order == 'asc' ? 'desc' : 'asc'; ?>')">Status</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($rows) > 0): ?>
              <?php foreach($rows as $r): ?>
                <?php
                // Status badge
                $status_class = [
                    'Pending' => 'pending',
                    'Approved' => 'approved', 
                    'Completed' => 'completed',
                    'Cancelled' => 'cancelled'
                ][$r['status']] ?? 'pending';
                
                // Format datetime
                $schedule_formatted = date('M j, Y g:i A', strtotime($r['schedule_datetime']));

                // Shorten reason for table display
                $reason_display = $r['reason'] ?? '';
                if (strlen($reason_display) > 60) {
                    $reason_display = substr($reason_display, 0, 60) . '...';
                }
                
                // Row background for archived records
                $row_class = $r['is_archived'] ? 'style="background-color: rgba(149, 165, 166, 0.1);"' : '';
                ?>
                <tr data-appointment-id="<?php echo h($r['id']); ?>" <?php echo $row_class; ?>>
                  <td>#<?php echo h($r['id']); ?></td>
                  <td><?php echo h(trim(($r['patient_first'] ?? '').' '.($r['patient_last'] ?? ''))); ?></td>
                  <td><?php echo h($r['doctor_name'] ?? ''); ?></td>
                  <td><?php echo h($schedule_formatted); ?></td>
                  <td><?php echo h($reason_display); ?></td>
                  <td>
                    <span class="status <?php echo $status_class; ?>"><?php echo h($r['status']); ?></span>
                    <?php if($r['is_archived']): ?>
                      <span class="status archived" style="margin-left: 5px;">Archived</span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space: nowrap;">
                    <button type="button" class="btn" onclick="viewAppointment(<?php echo h($r['id']); ?>)" style="background: #3498db; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">View</button>
                    
                    <?php if(!$r['is_archived']): ?>
                      <a href="appointment_form.php?id=<?php echo h($r['id']); ?>" class="btn" style="background: #f39c12; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px;">Edit</a>
                      
                      <?php if ($is_admin): ?>
                        <?php $patientName = addslashes(trim(($r['patient_first'] ?? '').' '.($r['patient_last'] ?? ''))); ?>
                        <button type="button" class="btn" onclick="confirmArchive(<?php echo h($r['id']); ?>, '<?php echo h($patientName); ?>')" style="background: #6c757d; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">Archive</button>
                      <?php endif; ?>
                    <?php else: ?>
                      <?php if ($is_admin): ?>
                        <?php $patientName = addslashes(trim(($r['patient_first'] ?? '').' '.($r['patient_last'] ?? ''))); ?>
                        <button type="button" class="btn" onclick="confirmRestore(<?php echo h($r['id']); ?>, '<?php echo h($patientName); ?>')" style="background: #95a5a6; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px;">Restore</button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" style="text-align:center; color:#888; padding: 30px;">
                  <?php if($show_archived): ?>
                    No archived appointments found.
                  <?php else: ?>
                    <?php if (!empty($filter) || !empty($status_filter) || !empty($date_filter)): ?>
                      No appointments match your filter criteria. 
                      <a href="appointments.php" style="color: var(--navy-700); text-decoration: underline;">Clear filters</a> to see all appointments.
                    <?php else: ?>
                      No appointments found.
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        
        <!-- Pagination with sorting and filters -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($filter) ? "&filter=$filter" : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($date_filter) ? "&date=$date_filter" : ''; ?>">&laquo; Previous</a>
          <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
          <?php endif; ?>
          
          <?php 
          // Show limited page numbers
          $start_page = max(1, $current_page - 2);
          $end_page = min($total_pages, $current_page + 2);
          
          for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i == $current_page): ?>
              <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
              <a href="?page=<?php echo $i; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($filter) ? "&filter=$filter" : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($date_filter) ? "&date=$date_filter" : ''; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($filter) ? "&filter=$filter" : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($date_filter) ? "&date=$date_filter" : ''; ?>">Next &raquo;</a>
          <?php else: ?>
            <span class="disabled">Next &raquo;</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <!-- View Appointment Modal -->
  <div id="viewAppointmentModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:800px; max-height:90vh; overflow:auto;">
      <div style="background:var(--navy-700); color:white; padding:20px; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0;">Appointment Details</h3>
        <button onclick="closeModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer;">&times;</button>
      </div>
      <div id="appointmentDetails" style="padding:20px;">
        <div class="text-center text-muted">Loading...</div>
      </div>
      <div style="padding:20px; border-top:1px solid #eee; text-align:right;">
        <button onclick="closeModal()" class="btn" style="background:#6c757d; color:white;">Close</button>
      </div>
    </div>
  </div>

  <script>
    /* -------------------------
       Sorting functionality
       ------------------------- */
    function sortTable(column, order) {
        const url = new URL(window.location.href);
        url.searchParams.set('sort', column);
        url.searchParams.set('order', order);
        url.searchParams.set('page', 1); // Reset to page 1 when sorting
        
        // Preserve filter parameters
        <?php if (!empty($filter)): ?>url.searchParams.set('filter', '<?php echo $filter; ?>');<?php endif; ?>
        <?php if (!empty($status_filter)): ?>url.searchParams.set('status', '<?php echo $status_filter; ?>');<?php endif; ?>
        <?php if (!empty($date_filter)): ?>url.searchParams.set('date', '<?php echo $date_filter; ?>');<?php endif; ?>
        <?php if ($show_archived): ?>url.searchParams.set('show', 'archived');<?php endif; ?>
        
        window.location.href = url.toString();
    }

    /* -------------------------
       Modal functions
       ------------------------- */
    function viewAppointment(appointmentId) {
        const detailsContainer = document.getElementById('appointmentDetails');
        detailsContainer.innerHTML = '<div class="text-center py-3 text-muted">Loading appointment details…</div>';
        
        const modal = document.getElementById('viewAppointmentModal');
        modal.style.display = 'flex';
        
        // Simple AJAX fetch
        fetch('appointment_view.php?id=' + encodeURIComponent(appointmentId))
        .then(response => response.text())
        .then(html => {
            detailsContainer.innerHTML = html;
        })
        .catch(err => {
            console.error('Error fetching appointment details:', err);
            detailsContainer.innerHTML = '<div class="alert alert-danger">Error loading appointment details. Please try again.</div>';
        });
    }

    function closeModal() {
        document.getElementById('viewAppointmentModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('viewAppointmentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    /* -------------------------
       Archive/Restore functions
       ------------------------- */
    function confirmArchive(appointmentId, patientName) {
        if (confirm(`Archive appointment for "${patientName}"? This will mark the record as archived.`)) {
            window.location.href = 'appointments.php?archive=' + encodeURIComponent(appointmentId);
        }
    }

    function confirmRestore(appointmentId, patientName) {
        if (confirm(`Restore appointment for "${patientName}"?`)) {
            window.location.href = 'appointments.php?restore=' + encodeURIComponent(appointmentId);
        }
    }

    /* -------------------------
       Toast notifications
       ------------------------- */
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast');
        if (!container) return;
        
        // Map type to CSS class
        const typeClasses = {
            'success': 'alert-success',
            'error': 'alert-error',
            'warning': 'alert-warning',
            'info': 'alert-info',
            'primary': 'alert-primary'
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
    const appointmentsTable = document.getElementById('appointmentsTable');
    const tbody = appointmentsTable.querySelector('tbody');
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
        // initialize count
        filterTable('');
    }

    /* -------------------------
       On page load: show toast & highlight if redirected
       ------------------------- */
    document.addEventListener('DOMContentLoaded', function() {
        // success messages passed via query string
        const success = <?php echo json_encode($success); ?>;
        const action  = <?php echo json_encode($action); ?>;
        const aId     = <?php echo json_encode($appointment_id); ?>;

        if (success) {
            const messages = {
                'added': 'Appointment added successfully!',
                'updated': 'Appointment updated successfully!',
                'archived': 'Appointment archived successfully!',
                'restored': 'Appointment restored successfully!',
                'error': 'An error occurred performing the action.'
            };
            const msg = messages[success] || 'Operation completed.';
            
            // Determine toast type based on success
            let toastType = 'success';
            if (success === 'error') toastType = 'error';
            else if (success === 'warning') toastType = 'warning';
            else if (success === 'info') toastType = 'info';
            
            showToast(msg, toastType);

            // Highlight added/updated/archived/restored row if id present
            if (aId && ['added','updated','archived','restored'].includes(success)) {
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-appointment-id="${aId}"]`);
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