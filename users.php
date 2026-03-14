<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Only Admins can access this page
if ($_SESSION['role'] != 'Admin') {
    echo "<div class='alert alert-danger text-center mt-4'>Access denied. Only administrators can manage users.</div>";
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
    'MedicalRecordsBilling' => [
        'dashboard.php' => 'Dashboard',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'
    ]
];

$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Preprocessing
$success = $_GET['success'] ?? '';
$action = $_GET['action'] ?? '';
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// DEACTIVATE/ACTIVATE user (cannot deactivate self)
if (isset($_GET['deactivate'])) {
    $id = (int)$_GET['deactivate'];
    if ($id != $_SESSION['user_id']) {
        $result = execute($conn, "UPDATE users SET is_active = 0, deactivated_at = NOW() WHERE id = ?", "i", [$id]);
        if (!isset($result['error'])) {
            header("Location: users.php?success=deactivated&action=deactivate&user_id={$id}");
            exit();
        } else {
            header("Location: users.php?success=error&action=deactivate");
            exit();
        }
    } else {
        header("Location: users.php?success=error&action=deactivate&message=Cannot deactivate your own account");
        exit();
    }
}

if (isset($_GET['activate'])) {
    $id = (int)$_GET['activate'];
    $result = execute($conn, "UPDATE users SET is_active = 1, deactivated_at = NULL WHERE id = ?", "i", [$id]);
    if (!isset($result['error'])) {
        header("Location: users.php?success=activated&action=activate&user_id={$id}");
        exit();
    } else {
        header("Location: users.php?success=error&action=activate");
        exit();
    }
}

// Pagination
$users_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $users_per_page;

// Determine active/inactive filter
$show_inactive = isset($_GET['show']) && $_GET['show'] === 'inactive';

// Check if is_active column exists
$table_check = fetchOne($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
$has_status_columns = $table_check !== null;

if ($has_status_columns) {
    $status_condition = $show_inactive ? "u.is_active = 0" : "u.is_active = 1";
} else {
    $status_condition = "1=1"; // Show all users if column doesn't exist
}

// Get total count of users for pagination
$total_users_query = "SELECT COUNT(*) as total FROM users u WHERE $status_condition";
$total_users_result = mysqli_query($conn, $total_users_query);
$total_users_row = mysqli_fetch_assoc($total_users_result);
$total_users = $total_users_row['total'];
$total_pages = ceil($total_users / $users_per_page);

// Fetch paginated users
$rows = fetchAll($conn, "
    SELECT u.id, u.full_name, u.username, u.role, u.created_at" . 
    ($has_status_columns ? ", u.is_active, u.deactivated_at" : "") . "
    FROM users u 
    WHERE {$status_condition}
    ORDER BY u.id DESC
    LIMIT $offset, $users_per_page
", null, []);

// Get user statistics
$total_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM users", null, []);
$active_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM users WHERE is_active = 1", null, []);
$doctor_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM users WHERE role = 'Doctor'", null, []);
$staff_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM users WHERE role IN ('Staff', 'Nurse', 'Inventory', 'Billing', 'MedicalRecordsBilling')", null, []);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Gig Oca Robles Seamen's Hospital Davao - User Management</title>

<!-- Google Font -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --bg: #eef3f7;
    --panel: #ffffff;
    --muted: #6b7280;
    --navy-700: #001F3F;
    --accent: #003366;
    --sidebar: #002855;
    --light-blue: #4d8cc9;
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

  .top-left h1{font-size:22px;margin:0;font-weight:700; color: var(--navy-700);}
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
  .btn-warning {
    background: #ed8936;
    color: #fff;
  }
  .btn-warning:hover {
    background: #dd6b20;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(237, 137, 54, 0.2);
  }
  .btn-danger {
    background: #e53e3e;
    color: #fff;
  }
  .btn-danger:hover {
    background: #c53030;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(229, 62, 62, 0.2);
  }
  .btn-success {
    background: #38a169;
    color: #fff;
  }
  .btn-success:hover {
    background: #2f855a;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(56, 161, 105, 0.2);
  }
  .btn-info {
    background: var(--light-blue);
    color: #fff;
  }
  .btn-info:hover {
    background: #3a7ab3;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(77, 140, 201, 0.2);
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
    background: #38a169;
    border-left: 4px solid #2f855a;
  }

  .alert-error, .alert-danger {
    background: #e53e3e;
    border-left: 4px solid #c53030;
  }

  .alert-warning {
    background: #ed8936;
    border-left: 4px solid #dd6b20;
  }

  .alert-info {
    background: var(--light-blue);
    border-left: 4px solid #3a7ab3;
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

  /* Users table */
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
  
  /* Status badges – no background, only colored text */
  .status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 16px;
    font-weight: 600;
    font-size: 13px;
    background: transparent;
  }
  .status.active   { color: #0d6632; } /* dark green */
  .status.inactive { color: #a12b2b; } /* dark red */
  .status.pending  { color: #b45f06; } /* amber */
  
  /* Role badges – no background, only colored text */
  .role {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 16px;
    font-weight: 600;
    font-size: 13px;
    background: transparent;
  }
  .role-admin          { color: #a12b2b; } /* dark red */
  .role-doctor         { color: #0b5e7c; } /* dark teal */
  .role-nurse          { color: #5e3c9c; } /* dark purple */
  .role-staff          { color: #b45f06; } /* amber */
  .role-inventory      { color: #97266d; } /* dark pink */
  .role-billing        { color: #0d6632; } /* dark green */
  .role-medicalrecordsbilling { color: #553c9a; } /* deep purple */

  /* Summary Cards */
  .summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin: 18px 0;
  }
  .summary-card {
    background: var(--panel);
    padding: 20px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid #f0f4f8;
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
  .summary-card.primary { border-left: 4px solid var(--light-blue); }
  .summary-card.success { border-left: 4px solid #38a169; }
  .summary-card.warning { border-left: 4px solid #ed8936; }
  .summary-card.danger { border-left: 4px solid #e53e3e; }

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

  /* Role badge in header */
  .role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
    margin-left: 10px;
    background: transparent;
  }
  .role-admin { color: #001F3F; background: transparent; }
  .role-doctor { color: #003366; background: transparent; }
  .role-nurse { color: #4d8cc9; background: transparent; }
  .role-staff { color: #6b7280; background: transparent; }
  .role-inventory { color: #1e6b8a; background: transparent; }
  .role-billing { color: #0066cc; background: transparent; }
  .role-medicalrecordsbilling { color: #34495e; background: transparent; }

  /* Modal */
  .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 20px 25px rgba(0,0,0,0.1);
  }
  .modal-header {
    background: var(--navy-700);
    color: white;
    border-radius: 12px 12px 0 0;
  }

  /* Button sizes */
  .btn-sm {
    padding: 6px 12px;
    font-size: 13px;
    border-radius: 6px;
  }

  /* Footer shadow */
  .footer-shadow{height:48px;background:linear-gradient(180deg,transparent,rgba(3,7,18,0.04));pointer-events:none;position:fixed;left:0;right:0;bottom:0}

  /* Responsive */
  @media (max-width:1100px){
    .table-wrap table{min-width:700px}
    .summary-cards{grid-template-columns:repeat(2,1fr)}
  }
  @media (max-width:780px){
    .sidebar{position:fixed;left:-320px;transform:translateX(0)}
    .sidebar.open{left:0;transform:translateX(0)}
    .main{margin-left:0;padding:12px}
    .sidebar.collapsed{width:230px}
    .topbar{flex-direction:column;align-items:flex-start}
    .top-actions{width:100%;justify-content:space-between}
    .summary-cards{grid-template-columns:1fr}
  }

  /* small niceties */
  a{color:inherit; text-decoration: none;}
  a:hover{color: var(--light-blue);}
  .muted{color:var(--muted);font-size:13px}
  tr:hover { background-color: #f8fbfd; }
</style>
</head>
<body>
  <div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
        <a href="dashboard.php" style="display: block;">
          <img src="logo.jpg" alt="Gig Oca Robles Seamen's Hospital Davao Logo">
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
          <h1>User Management <?php echo $show_inactive ? '(Inactive)' : ''; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Manage system users and permissions</p>
        </div>

        <div class="top-actions">
          <?php if ($show_inactive): ?>
            <a href="users.php" class="btn" style="background: #6b7280; color: white;">View Active Users</a>
          <?php else: ?>
            <a href="user_form.php" class="btn">+ Add User</a>
            <?php if ($has_status_columns): ?>
              <a href="users.php?show=inactive" class="btn" style="background: #6b7280; color: white;">View Inactive</a>
            <?php endif; ?>
          <?php endif; ?>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- Summary Statistics -->
      <div class="summary-cards">
        <div class="summary-card primary">
          <h4>Total Users</h4>
          <div class="value"><?php echo number_format($total_stats['total'] ?? 0); ?></div>
        </div>
        <div class="summary-card success">
          <h4>Active Users</h4>
          <div class="value"><?php echo number_format($active_stats['total'] ?? $total_stats['total'] ?? 0); ?></div>
        </div>
        <div class="summary-card warning">
          <h4>Doctors</h4>
          <div class="value"><?php echo number_format($doctor_stats['total'] ?? 0); ?></div>
        </div>
        <div class="summary-card danger">
          <h4>Staff</h4>
          <div class="value"><?php echo number_format($staff_stats['total'] ?? 0); ?></div>
        </div>
      </div>

      <!-- Users table -->
      <div class="table-wrap" id="usersSection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by name or email">
          </div>
          <div class="muted">Showing <span id="rowCount"><?php echo count($rows); ?></span> of <?php echo number_format($total_users); ?> users</div>
        </div>

        <table id="usersTable" aria-label="Users table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Full Name</th>
              <th>Email/Username</th>
              <th>Role</th>
              <th>Status</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($rows) > 0): ?>
              <?php foreach($rows as $r): ?>
                <?php
                // Role class
                $role_class_map = [
                    'Admin' => 'role-admin',
                    'Doctor' => 'role-doctor',
                    'Nurse' => 'role-nurse',
                    'Staff' => 'role-staff',
                    'Inventory' => 'role-inventory',
                    'Billing' => 'role-billing',
                    'MedicalRecordsBilling' => 'role-medicalrecordsbilling'
                ];
                $role_class = $role_class_map[$r['role']] ?? 'role-staff';
                
                // Status
                $status_text = 'Active';
                $status_class = 'active';
                if ($has_status_columns) {
                    if (empty($r['is_active'])) {
                        $status_text = 'Inactive';
                        $status_class = 'inactive';
                    }
                }
                
                // Format created date
                $created_formatted = date('M j, Y g:i A', strtotime($r['created_at']));
                
                $row_class = ($has_status_columns && empty($r['is_active'])) ? 'style="background-color: rgba(107, 114, 128, 0.1);"' : '';
                ?>
                <tr data-user-id="<?php echo h($r['id']); ?>" <?php echo $row_class; ?>>
                  <td>#<?php echo h($r['id']); ?></td>
                  <td><?php echo h($r['full_name'] ?? ''); ?></td>
                  <td><?php echo h($r['username'] ?? ''); ?></td>
                  <td><span class="role <?php echo $role_class; ?>"><?php 
                    // Display user-friendly role name
                    if ($r['role'] === 'MedicalRecordsBilling') {
                        echo 'Medical Records Billing';
                    } else {
                        echo h($r['role']);
                    }
                  ?></span></td>
                  <td>
                    <span class="status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                    <?php if ($has_status_columns && !empty($r['deactivated_at'])): ?>
                      <br><small class="muted"><?php echo date('M j, Y', strtotime($r['deactivated_at'])); ?></small>
                    <?php endif; ?>
                  </td>
                  <td><small><?php echo h($created_formatted); ?></small></td>
                  <td style="white-space: nowrap;">
                    <a href="user_form.php?id=<?php echo h($r['id']); ?>" class="btn btn-sm btn-warning">Edit</a>
                    
                    <?php if ($has_status_columns): ?>
                      <?php $userName = addslashes($r['full_name'] ?? ''); ?>
                      <?php if (!empty($r['is_active'])): ?>
                        <?php if ($r['id'] != $_SESSION['user_id']): ?>
                          <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeactivate(<?php echo h($r['id']); ?>, '<?php echo h($userName); ?>')">Deactivate</button>
                        <?php else: ?>
                          <button type="button" class="btn btn-sm" style="background: #6b7280; color: white;" disabled>Deactivate</button>
                        <?php endif; ?>
                      <?php else: ?>
                        <button type="button" class="btn btn-sm btn-success" onclick="confirmActivate(<?php echo h($r['id']); ?>, '<?php echo h($userName); ?>')">Activate</button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" style="text-align:center; color:#6b7280; padding: 30px;">
                  <?php echo $show_inactive ? 'No inactive users found.' : 'No users found.'; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?><?php echo $show_inactive ? '&show=inactive' : ''; ?>">&laquo; Previous</a>
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
              <a href="?page=<?php echo $i; ?><?php echo $show_inactive ? '&show=inactive' : ''; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?><?php echo $show_inactive ? '&show=inactive' : ''; ?>">Next &raquo;</a>
          <?php else: ?>
            <span class="disabled">Next &raquo;</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <script>
    function confirmDeactivate(userId, userName) {
        if (confirm(`Deactivate user "${userName}"? This user will no longer be able to log in.`)) {
            window.location.href = 'users.php?deactivate=' + encodeURIComponent(userId);
        }
    }

    function confirmActivate(userId, userName) {
        if (confirm(`Activate user "${userName}"? This user will be able to log in again.`)) {
            window.location.href = 'users.php?activate=' + encodeURIComponent(userId);
        }
    }

    function showToast(message, type = 'success') {
        const container = document.getElementById('toast');
        if (!container) return;
        
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

    const searchInput = document.getElementById('searchInput');
    const usersTable = document.getElementById('usersTable');
    const tbody = usersTable.querySelector('tbody');
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

    document.addEventListener('DOMContentLoaded', function() {
        const success = <?php echo json_encode($success); ?>;
        const action  = <?php echo json_encode($action); ?>;
        const uId     = <?php echo json_encode($user_id); ?>;
        const message = <?php echo isset($_GET['message']) ? json_encode($_GET['message']) : 'null'; ?>;

        if (success) {
            const messages = {
                'added': 'User added successfully!',
                'updated': 'User updated successfully!',
                'deactivated': 'User deactivated successfully!',
                'activated': 'User activated successfully!',
                'error': message || 'An error occurred performing the action.'
            };
            const msg = messages[success] || 'Operation completed.';
            
            let toastType = 'success';
            if (success === 'error') toastType = 'error';
            else if (success === 'warning') toastType = 'warning';
            else if (success === 'info') toastType = 'info';
            
            showToast(msg, toastType);

            if (uId && ['added','updated','activated','deactivated'].includes(success)) {
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-user-id="${uId}"]`);
                    if (row) {
                        row.style.backgroundColor = 'rgba(77, 140, 201, 0.1)';
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