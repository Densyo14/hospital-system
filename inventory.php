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

// Define role permissions for navigation - ADDED REPORTS TO ALL ROLES TO MATCH APPOINTMENTS.PHP
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
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search_term = $_GET['search'] ?? '';
// ========== END NEW CODE ==========

// Preprocessing
$success = isset($_GET['success']) ? $_GET['success'] : '';
$action  = isset($_GET['action']) ? $_GET['action'] : '';
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

// Current user info for permission checks
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_inventory = ($current_user['role'] ?? '') === 'Inventory';

// Check if archive columns exist in inventory_items table
$table_check = fetchOne($conn, "SHOW COLUMNS FROM inventory_items LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;

// ARCHIVE action (admin/inventory only) - only if archive columns exist
if (isset($_GET['archive']) && ($is_admin || $is_inventory) && $has_archive_columns) {
    $id = (int)$_GET['archive'];
    $reason = "Archived by user";

    $stmt = $conn->prepare("UPDATE inventory_items SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?");
    $stmt->bind_param("ii", $current_user_id, $id);
    if ($stmt->execute()) {
        $stmt2 = $conn->prepare("INSERT INTO archive_logs (table_name, record_id, archived_by, reason) VALUES ('inventory_items', ?, ?, ?)");
        $stmt2->bind_param("iis", $id, $current_user_id, $reason);
        $stmt2->execute();
        header("Location: inventory.php?success=archived&action=archive&item_id={$id}");
        exit();
    } else {
        header("Location: inventory.php?success=error&action=archive");
        exit();
    }
}

// RESTORE action (admin/inventory only) - only if archive columns exist
if (isset($_GET['restore']) && ($is_admin || $is_inventory) && $has_archive_columns) {
    $id = (int)$_GET['restore'];
    $stmt = $conn->prepare("UPDATE inventory_items SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: inventory.php?success=restored&action=restore&item_id={$id}");
        exit();
    } else {
        header("Location: inventory.php?success=error&action=restore");
        exit();
    }
}

// Pagination
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Check if show archived
$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';

// ========== UPDATED CODE: Build query conditions with filters ==========
// Start with base conditions
if ($has_archive_columns) {
    $where_conditions = $show_archived ? ["i.is_archived = 1"] : ["i.is_archived = 0"];
} else {
    $where_conditions = ["1=1"];
    $show_archived = false;
}

// Handle filter from dashboard (low_stock)
if ($filter === 'low_stock') {
    $where_conditions[] = "i.quantity <= i.threshold";
}

// Handle category filter if provided
if (!empty($category_filter)) {
    $where_conditions[] = "i.category = '$category_filter'";
}

// Handle status filter if provided
if (!empty($status_filter) && in_array($status_filter, ['low_stock', 'out_of_stock', 'in_stock'])) {
    if ($status_filter === 'low_stock') {
        $where_conditions[] = "i.quantity <= i.threshold AND i.quantity > 0";
    } elseif ($status_filter === 'out_of_stock') {
        $where_conditions[] = "i.quantity = 0";
    } elseif ($status_filter === 'in_stock') {
        $where_conditions[] = "i.quantity > i.threshold";
    }
}

// Handle search term if provided
if (!empty($search_term)) {
    $search_term_escaped = mysqli_real_escape_string($conn, $search_term);
    $where_conditions[] = "(i.item_name LIKE '%$search_term_escaped%' OR i.category LIKE '%$search_term_escaped%')";
}

// Combine WHERE conditions
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
// ========== END UPDATED CODE ==========

// Get total count of items for pagination WITH FILTERS
$total_items_query = "SELECT COUNT(*) as total FROM inventory_items i $where_clause";
$total_items_result = mysqli_query($conn, $total_items_query);
$total_items_row = mysqli_fetch_assoc($total_items_result);
$total_items = $total_items_row['total'];
$total_pages = ceil($total_items / $items_per_page);

// ========== UPDATED CODE: Fetch paginated items with filters ==========
$query = "
    SELECT i.*" . 
    ($has_archive_columns ? ", arch_user.full_name AS archived_by_name" : "") . "
    FROM inventory_items i" .
    ($has_archive_columns ? " LEFT JOIN users arch_user ON i.archived_by = arch_user.id" : "") . "
    $where_clause
    ORDER BY i.item_name
    LIMIT $offset, $items_per_page
";

$rows = fetchAll($conn, $query, null, []);
// ========== END UPDATED CODE ==========

// Get low stock items - UPDATED to respect filters
$low_stock_where = $has_archive_columns ? "is_archived = 0 AND quantity <= threshold" : "quantity <= threshold";
if (!empty($where_conditions)) {
    // Remove the archive condition since we want active items only for alerts
    $low_stock_where = "quantity <= threshold";
    if (!empty($category_filter)) {
        $low_stock_where .= " AND category = '$category_filter'";
    }
}
$low_stock_items = fetchAll($conn, "SELECT * FROM inventory_items WHERE {$low_stock_where} ORDER BY quantity ASC", null, []);

// Get unique categories for filter dropdown
$categories = fetchAll($conn, "SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL AND category != '' ORDER BY category", null, []);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - Inventory</title> <!-- UPDATED TITLE -->

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

  /* SIDEBAR - MATCHED TO DASHBOARD THEME */
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

  /* Responsive adjustments for small screens */
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
  .btn-warning {
    background: #ed8936;
    color: #fff;
  }
  .btn-warning:hover {
    background: #dd6b20;
    transform: translateY(-1px);
  }
  .btn-danger {
    background: #e53e3e;
    color: #fff;
  }
  .btn-danger:hover {
    background: #c53030;
    transform: translateY(-1px);
  }
  .btn-success {
    background: #38a169;
    color: #fff;
  }
  .btn-success:hover {
    background: #2f855a;
    transform: translateY(-1px);
  }
  .btn-info {
    background: #3182ce;
    color: #fff;
  }
  .btn-info:hover {
    background: #2b6cb0;
    transform: translateY(-1px);
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

  /* ========== NEW STYLE: Filter badge and controls ========== */
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
    border-left: 4px solid var(--light-blue);
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

  .filter-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    margin: 15px 0;
    flex-wrap: wrap;
  }
  .filter-select {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #e6eef0;
    background: white;
    font-size: 14px;
    min-width: 150px;
    transition: all 0.2s ease;
  }
  .filter-select:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
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
    background: #3182ce;
    border-left: 4px solid #2b6cb0;
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

  /* Inventory table */
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
  
  /* Status badges - matching appointments.php style */
  .status{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .in-stock{background:#dff7e8;color:#1f7b3b}
  .low-stock{background:#fff4ce;color:#8a6d00}
  .out-of-stock{background:#fce6e8;color:#b02b2b}
  .archived{background:#95a5a6;color:white}

  /* Low Stock Alerts */
  .alerts-wrap{background:var(--panel);padding:18px;border-radius:12px;box-shadow:var(--card-shadow);margin-top:20px; border: 1px solid #f0f4f8;}
  .alerts-header{display:flex;align-items:center;gap:10px;margin-bottom:12px}
  .alerts-header h4{margin:0;font-size:16px;color:#1e3a5f}
  .alert-item{padding:10px 12px;border-radius:8px;margin-bottom:8px;font-size:14px; transition: all 0.2s ease;}
  .alert-item:hover {
    transform: translateX(3px);
  }
  .alert-warning-bg{background:#fff4ce;border-left:4px solid #ed8936}
  .alert-danger-bg{background:#fce6e8;border-left:4px solid #e53e3e}
  .alert-success-bg{background:#dff7e8;border-left:4px solid #38a169}

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

  /* Footer shadow */
  .footer-shadow{height:48px;background:linear-gradient(180deg,transparent,rgba(3,7,18,0.04));pointer-events:none;position:fixed;left:0;right:0;bottom:0}

  /* Responsive */
  @media (max-width:1100px){
    .table-wrap table{min-width:700px}
    .filter-controls {
      flex-direction: column;
      align-items: flex-start;
    }
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

    <!-- SIDEBAR - Updated to match dashboard -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
  
        <a href="dashboard.php" class="clickable-image">
          <img src="logo.jpg" alt="Seamen's Cure Logo"> <!-- CHANGED TO JPG -->
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
                    'reports.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>',  // ADDED REPORTS ICON
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
          <h1>Inventory <?php echo $show_archived ? '(Archived)' : ''; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Welcome to Gig Oca Robles Seamen's Hospital Davao Management System</p> <!-- MATCHED TO DASHBOARD -->
        </div>

        <div class="top-actions">
          <?php if ($show_archived): ?>
            <a href="inventory.php" class="btn" style="background: #6b7280; color: white;">View Active Inventory</a>
          <?php else: ?>
            <a href="inventory_form.php" class="btn">+ Add Item</a>
            <?php if (($is_admin || $is_inventory) && $has_archive_columns): ?>
              <a href="inventory.php?show=archived" class="btn" style="background: #6b7280; color: white;">View Archived</a>
            <?php endif; ?>
          <?php endif; ?>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- ========== NEW CODE: Active Filter Badge ========== -->
      <?php if (!empty($filter) || !empty($category_filter) || !empty($status_filter) || !empty($search_term)): ?>
        <div class="filter-badge">
          Filter applied: 
          <?php 
            $filter_text = [];
            if ($filter === 'low_stock') $filter_text[] = "Low Stock Items";
            if (!empty($category_filter)) $filter_text[] = "Category: $category_filter";
            if (!empty($status_filter)) {
              $status_labels = ['low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock', 'in_stock' => 'In Stock'];
              $filter_text[] = "Status: " . ($status_labels[$status_filter] ?? $status_filter);
            }
            if (!empty($search_term)) $filter_text[] = "Search: \"$search_term\"";
            echo implode(' • ', $filter_text);
          ?>
          <a href="inventory.php" class="close-btn">&times;</a>
        </div>
      <?php endif; ?>
      <!-- ========== END NEW CODE ========== -->

      <!-- ========== NEW CODE: Filter Controls ========== -->
      <div class="filter-controls">
        <select id="categoryFilter" class="filter-select" onchange="applyFilter()">
          <option value="">All Categories</option>
          <?php foreach($categories as $category): ?>
            <option value="<?php echo htmlspecialchars($category['category']); ?>" 
              <?php echo $category_filter === $category['category'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($category['category']); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select id="statusFilter" class="filter-select" onchange="applyFilter()">
          <option value="">All Statuses</option>
          <option value="in_stock" <?php echo $status_filter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
          <option value="low_stock" <?php echo $status_filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
          <option value="out_of_stock" <?php echo $status_filter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
        </select>

        <input type="text" id="searchFilter" class="filter-select" placeholder="Search items..." 
               value="<?php echo htmlspecialchars($search_term); ?>" onkeyup="applyFilterOnEnter(event)">

        <button onclick="applyFilter()" class="filter-btn">Apply Filters</button>
        <a href="inventory.php" class="filter-btn" style="background: #6b7280;">Clear All</a>
      </div>
      <!-- ========== END NEW CODE ========== -->

      <!-- Inventory table -->
      <div class="table-wrap" id="inventorySection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by item name or category">
          </div>
          <div class="muted">Showing <span id="rowCount"><?php echo count($rows); ?></span> of <?php echo number_format($total_items); ?> items</div>
        </div>

        <table id="inventoryTable" aria-label="Inventory table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Item Name</th>
              <th>Category</th>
              <th>Quantity</th>
              <th>Unit</th>
              <th>Threshold</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
            <?php if (count($rows) > 0): ?>
              <?php foreach($rows as $r): ?>
                <?php
                // Stock status
                if ($r['quantity'] <= 0) {
                    $stock_status = 'Out of Stock';
                    $stock_class = 'out-of-stock';
                } elseif ($r['quantity'] <= $r['threshold']) {
                    $stock_status = 'Low Stock';
                    $stock_class = 'low-stock';
                } else {
                    $stock_status = 'In Stock';
                    $stock_class = 'in-stock';
                }
                
                $row_class = ($has_archive_columns && !empty($r['is_archived'])) ? 'style="background-color: rgba(149, 165, 166, 0.1);"' : '';
                ?>
                <tr data-item-id="<?php echo h($r['id']); ?>" <?php echo $row_class; ?>>
                  <td>#<?php echo h($r['id']); ?></td>
                  <td><?php echo h($r['item_name'] ?? ''); ?></td>
                  <td><?php echo h($r['category'] ?? ''); ?></td>
                  <td><?php echo h($r['quantity']); ?></td>
                  <td><?php echo h($r['unit'] ?? ''); ?></td>
                  <td><?php echo h($r['threshold']); ?></td>
                  <td>
                    <span class="status <?php echo $stock_class; ?>"><?php echo $stock_status; ?></span>
                    <?php if ($has_archive_columns && !empty($r['is_archived'])): ?>
                      <span class="status archived" style="margin-left: 5px;">Archived</span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space: nowrap;">
                    <button type="button" class="btn" onclick="viewItem(<?php echo h($r['id']); ?>)" style="background: #3182ce; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px; transition: all 0.2s ease;">View</button>
                    
                    <?php if (!$has_archive_columns || empty($r['is_archived'])): ?>
                      <a href="inventory_form.php?id=<?php echo h($r['id']); ?>" class="btn" style="background: #ed8936; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; transition: all 0.2s ease; display: inline-block;">Edit</a>
                    <?php elseif (($is_admin || $is_inventory)): ?>
                      <a href="inventory_form.php?id=<?php echo h($r['id']); ?>" class="btn" style="background: #ed8936; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; transition: all 0.2s ease; display: inline-block;">Edit</a>
                    <?php endif; ?>
                    
                    <?php if ($has_archive_columns && ($is_admin || $is_inventory)): ?>
                      <?php $itemName = addslashes($r['item_name'] ?? ''); ?>
                      <?php if (!empty($r['is_archived'])): ?>
                        <button type="button" class="btn" onclick="confirmRestore(<?php echo h($r['id']); ?>, '<?php echo h($itemName); ?>')" style="background: #95a5a6; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px; transition: all 0.2s ease;">Restore</button>
                      <?php else: ?>
                        <button type="button" class="btn" onclick="confirmArchive(<?php echo h($r['id']); ?>, '<?php echo h($itemName); ?>')" style="background: #7f8c8d; color: white; padding: 6px 12px; border-radius: 6px; border: none; font-size: 13px; transition: all 0.2s ease;">Archive</button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" style="text-align:center; color:#6b7280; padding: 30px;">
                  <?php if($show_archived): ?>
                    No archived items found.
                  <?php else: ?>
                    <?php if (!empty($filter) || !empty($category_filter) || !empty($status_filter) || !empty($search_term)): ?>
                      No items match your filter criteria. 
                      <a href="inventory.php" style="color: var(--navy-700); text-decoration: underline;">Clear filters</a> to see all items.
                    <?php else: ?>
                      No inventory items found.
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        
        <!-- Pagination with filters -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($filter) ? "&filter=$filter" : ''; ?><?php echo !empty($category_filter) ? "&category=$category_filter" : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($search_term) ? "&search=$search_term" : ''; ?>">&laquo; Previous</a>
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
              <a href="?page=<?php echo $i; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($filter) ? "&filter=$filter" : ''; ?><?php echo !empty($category_filter) ? "&category=$category_filter" : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($search_term) ? "&search=$search_term" : ''; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?><?php echo $show_archived ? '&show=archived' : ''; ?><?php echo !empty($filter) ? "&filter=$filter" : ''; ?><?php echo !empty($category_filter) ? "&category=$category_filter" : ''; ?><?php echo !empty($status_filter) ? "&status=$status_filter" : ''; ?><?php echo !empty($search_term) ? "&search=$search_term" : ''; ?>">Next &raquo;</a>
          <?php else: ?>
            <span class="disabled">Next &raquo;</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Low Stock Alerts -->
      <div class="alerts-wrap">
        <div class="alerts-header">
          <span style="font-size: 20px;">📢</span>
          <h4>Low Stock Alerts</h4>
        </div>
        
        <?php if (empty($low_stock_items)): ?>
          <div class="alert-item alert-success-bg">
            ✅ No low stock items.
          </div>
        <?php else: ?>
          <?php foreach($low_stock_items as $l): ?>
            <?php 
            $alert_class = $l['quantity'] == 0 ? 'alert-danger-bg' : 'alert-warning-bg';
            $urgency_text = $l['quantity'] == 0 ? 'Out of Stock!' : 'Low Stock';
            ?>
            <div class="alert-item <?php echo $alert_class; ?>">
              <strong><?php echo h($l['item_name']); ?></strong> — <?php echo $l['quantity']; ?> <?php echo h($l['unit']); ?> remaining (threshold: <?php echo $l['threshold']; ?>) — <strong><?php echo $urgency_text; ?></strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <!-- View Item Modal -->
  <div id="viewItemModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:800px; max-height:90vh; overflow:auto; border: 1px solid #f0f4f8; box-shadow: var(--card-shadow);">
      <div style="background:var(--navy-700); color:white; padding:20px; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0;">Inventory Item Details</h3>
        <button onclick="closeModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: background 0.2s ease;">&times;</button>
      </div>
      <div id="itemDetails" style="padding:20px;">
        <div class="text-center text-muted">Loading...</div>
      </div>
      <div style="padding:20px; border-top:1px solid #eee; text-align:right;">
        <button onclick="closeModal()" class="btn" style="background:#6b7280; color:white;">Close</button>
      </div>
    </div>
  </div>

  <script>
    /* -------------------------
       Filter functions
       ------------------------- */
    function applyFilter() {
        const category = document.getElementById('categoryFilter').value;
        const status = document.getElementById('statusFilter').value;
        const search = document.getElementById('searchFilter').value;
        
        let url = 'inventory.php?';
        const params = [];
        
        <?php if (!empty($filter)): ?>params.push(`filter=<?php echo $filter; ?>`);<?php endif; ?>
        if (category) params.push(`category=${encodeURIComponent(category)}`);
        if (status) params.push(`status=${encodeURIComponent(status)}`);
        if (search) params.push(`search=${encodeURIComponent(search)}`);
        <?php if ($show_archived): ?>params.push('show=archived');<?php endif; ?>
        
        window.location.href = url + params.join('&');
    }

    function applyFilterOnEnter(event) {
        if (event.key === 'Enter') {
            applyFilter();
        }
    }

    /* -------------------------
       Modal functions
       ------------------------- */
    function viewItem(itemId) {
        const detailsContainer = document.getElementById('itemDetails');
        detailsContainer.innerHTML = '<div class="text-center py-3 text-muted">Loading item details…</div>';
        
        const modal = document.getElementById('viewItemModal');
        modal.style.display = 'flex';
        
        // Simple AJAX fetch
        fetch('inventory_view.php?id=' + encodeURIComponent(itemId))
        .then(response => response.text())
        .then(html => {
            detailsContainer.innerHTML = html;
        })
        .catch(err => {
            console.error('Error fetching item details:', err);
            detailsContainer.innerHTML = '<div class="alert alert-danger">Error loading item details. Please try again.</div>';
        });
    }

    function closeModal() {
        document.getElementById('viewItemModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('viewItemModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    /* -------------------------
       Confirmation functions
       ------------------------- */
    function confirmArchive(itemId, itemName) {
        if (confirm(`Archive item "${itemName}"? This will mark the record as archived.`)) {
            window.location.href = 'inventory.php?archive=' + encodeURIComponent(itemId);
        }
    }

    function confirmRestore(itemId, itemName) {
        if (confirm(`Restore item "${itemName}"?`)) {
            window.location.href = 'inventory.php?restore=' + encodeURIComponent(itemId);
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
    const inventoryTable = document.getElementById('inventoryTable');
    const tbody = inventoryTable.querySelector('tbody');
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
        const iId     = <?php echo json_encode($item_id); ?>;
        const message = <?php echo isset($_GET['message']) ? json_encode($_GET['message']) : 'null'; ?>;

        if (success) {
            const messages = {
                'added': 'Item added successfully!',
                'updated': 'Item updated successfully!',
                'archived': 'Item archived successfully!',
                'restored': 'Item restored successfully!',
                'error': message || 'An error occurred performing the action.'
            };
            const msg = messages[success] || 'Operation completed.';
            
            // Determine toast type based on success
            let toastType = 'success';
            if (success === 'error') toastType = 'error';
            else if (success === 'warning') toastType = 'warning';
            else if (success === 'info') toastType = 'info';
            
            showToast(msg, toastType);

            // Highlight added/updated/archived/restored row if id present
            if (iId && ['added','updated','archived','restored'].includes(success)) {
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-item-id="${iId}"]`);
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