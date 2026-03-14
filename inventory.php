<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_role = $_SESSION['role'] ?? 'Guest';
$current_name = $_SESSION['name'] ?? 'User';
$current_user_id = $_SESSION['user_id'];

// Role permissions (including Triage Queue)
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

// Filter parameters
$filter = $_GET['filter'] ?? '';
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search_term = $_GET['search'] ?? '';

$success = $_GET['success'] ?? '';
$action = $_GET['action'] ?? '';
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

$is_admin = ($current_role === 'Admin');
$is_inventory = ($current_role === 'Inventory');

// Archive / Restore (admin/inventory only)
$table_check = fetchOne($conn, "SHOW COLUMNS FROM inventory_items LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;

if (isset($_GET['archive']) && ($is_admin || $is_inventory) && $has_archive_columns) {
    $id = (int)$_GET['archive'];
    execute($conn, "UPDATE inventory_items SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?", "ii", [$current_user_id, $id]);
    header("Location: inventory.php?success=archived&item_id=$id");
    exit();
}
if (isset($_GET['restore']) && ($is_admin || $is_inventory) && $has_archive_columns) {
    $id = (int)$_GET['restore'];
    execute($conn, "UPDATE inventory_items SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?", "i", [$id]);
    header("Location: inventory.php?success=restored&item_id=$id");
    exit();
}

$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';

// Build conditions
$conditions = [];
if ($has_archive_columns) {
    $conditions[] = $show_archived ? "i.is_archived = 1" : "i.is_archived = 0";
} else {
    $show_archived = false;
}
if ($filter === 'low_stock') {
    $conditions[] = "i.quantity <= i.threshold";
}
if (!empty($category_filter)) {
    $conditions[] = "i.category = '$category_filter'";
}
if (!empty($status_filter)) {
    if ($status_filter === 'low_stock') {
        $conditions[] = "i.quantity <= i.threshold AND i.quantity > 0";
    } elseif ($status_filter === 'out_of_stock') {
        $conditions[] = "i.quantity = 0";
    } elseif ($status_filter === 'in_stock') {
        $conditions[] = "i.quantity > i.threshold";
    }
}
if (!empty($search_term)) {
    $search = mysqli_real_escape_string($conn, $search_term);
    $conditions[] = "(i.item_name LIKE '%$search%' OR i.category LIKE '%$search%')";
}
$where_clause = 'WHERE ' . implode(' AND ', $conditions);

$total = fetchOne($conn, "SELECT COUNT(*) as total FROM inventory_items i $where_clause")['total'] ?? 0;
$total_pages = ceil($total / 10);
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * 10;

$rows = fetchAll($conn, "
    SELECT i.*, arch.full_name AS archived_by_name
    FROM inventory_items i
    LEFT JOIN users arch ON i.archived_by = arch.id
    $where_clause
    ORDER BY i.item_name
    LIMIT $offset, 10
");

// Low stock alerts (active items only)
$low_stock_where = $has_archive_columns ? "is_archived = 0 AND quantity <= threshold" : "quantity <= threshold";
if (!empty($category_filter)) {
    $low_stock_where .= " AND category = '$category_filter'";
}
$low_stock_items = fetchAll($conn, "SELECT * FROM inventory_items WHERE {$low_stock_where} ORDER BY quantity ASC");

// Unique categories for filter dropdown
$categories = fetchAll($conn, "SELECT DISTINCT category FROM inventory_items WHERE category IS NOT NULL AND category != '' ORDER BY category");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - Inventory</title>
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
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;
    font-family:Inter, sans-serif;
    background:var(--bg);
    color:#0f1724;
  }
  .app { display:flex; min-height:100vh; }

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
    overflow-y:auto;
    z-index:30;
  }
  .sidebar::-webkit-scrollbar { width:4px; }
  .sidebar::-webkit-scrollbar-track { background:rgba(255,255,255,0.1); }
  .sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.3); border-radius:2px; }
  .logo-wrap{ display:flex; justify-content:center; }
  .logo-wrap img{ width:150px; height:auto; }
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
  .menu-item:hover{ background:linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04)); }
  .menu-item.active{
    background:linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
    border-left:4px solid #9bcfff;
    padding-left:5px;
  }
  .menu-item .icon{ width:16px; height:16px; fill:white; }
  .sidebar-bottom{ margin-top:auto; padding-top:15px; border-top:1px solid rgba(255,255,255,0.1); }

  /* MAIN */
  .main{ margin-left:230px; padding:18px 28px; width:100%; }
  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:8px;
  }
  .top-left h1{ font-size:22px; margin:0; font-weight:700; }
  .top-left p{ margin:6px 0 0; color:var(--muted); font-size:13px; }
  .top-actions{ display:flex; align-items:center; gap:12px; }
  .btn{
    background:var(--navy-700);
    color:#fff;
    padding:9px 14px;
    border-radius:10px;
    border:0;
    font-weight:600;
    text-decoration:none;
    display:inline-block;
    transition:all 0.2s;
    font-size:13px;
  }
  .btn:hover{ background:var(--accent); transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,31,63,0.2); }
  .btn-outline {
    background:transparent;
    color:var(--navy-700);
    border:1px solid var(--navy-700);
  }
  .btn-outline:hover {
    background:rgba(0,31,63,0.1);
    color:var(--navy-700);
  }
  .btn-secondary {
    background:#6c757d;
    color:#fff;
  }
  .btn-secondary:hover {
    background:#5a6268;
    transform:translateY(-1px);
  }
  .date-pill{
    background:var(--panel);
    padding:8px 12px;
    border-radius:999px;
    box-shadow:0 4px 14px rgba(16,24,40,0.06);
    font-size:13px;
    white-space:nowrap;
    border:1px solid #e6eef0;
  }
  .role-badge{
    display:inline-block;
    padding:4px 12px;
    border-radius:15px;
    font-size:0.8rem;
    font-weight:bold;
    margin-left:10px;
  }
  .role-admin { background:#001F3F; color:white; }
  .role-doctor { background:#003366; color:white; }
  .role-nurse { background:#4d8cc9; color:white; }
  .role-staff { background:#6b7280; color:white; }
  .role-inventory { background:#1e6b8a; color:white; }
  .role-billing { background:#0066cc; color:white; }
  .role-socialworker { background:#34495e; color:white; }

  /* Filter badge */
  .filter-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#e8f4ff;
    color:var(--navy-700);
    padding:6px 12px;
    border-radius:20px;
    font-size:14px;
    margin:10px 0;
  }
  .filter-badge .close-btn{
    background:none;
    border:none;
    color:var(--navy-700);
    cursor:pointer;
    font-size:16px;
    width:20px; height:20px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
  }
  .filter-badge .close-btn:hover{ background:rgba(0,31,63,0.1); }

  /* Filter controls */
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
  }
  .filter-select:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77,140,201,0.1);
  }

  /* Table */
  .table-wrap{
    background:var(--panel);
    padding:18px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    overflow:auto;
    border:1px solid #f0f4f8;
  }
  .table-controls{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
  }
  .search-input{
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #e6eef0;
    background:transparent;
    min-width:220px;
  }
  .search-input:focus{
    outline:none;
    border-color:var(--light-blue);
    box-shadow:0 0 0 3px rgba(77,140,201,0.1);
  }
  table{ width:100%; border-collapse:collapse; min-width:800px; }
  thead th{
    background:#f8fbfd;
    padding:14px;
    text-align:left;
    color:#6b7280;
    font-weight:600;
    border-bottom:2px solid #e6eef0;
  }
  td, th{ padding:14px; border-bottom:1px solid #f0f3f4; color:#233; }

  /* Status badges */
 .status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 16px;
    font-weight: 600;
    font-size: 13px;
    cursor: default;
    background: transparent;
    transition: none;
}
.status:hover {
    filter: none;
}

/* Text colors for all statuses – no backgrounds */
.pending          { color: #b45f06; }      /* darker amber */
.waiting          { color: #b45f06; }      /* same as pending */
.scheduled        { color: #b45f06; }      /* optional */
.approved         { color: #0b5e7c; }      /* darker teal */
.completed        { color: #0d6632; }      /* darker green */
.cancelled        { color: #a12b2b; }      /* darker red */
.in-progress      { color: #1b6b8f; }      /* darker blue */
.in_consultation  { color: #5e3c9c; }      /* darker purple */
.archived         { color: #4a4f55; }      /* darker gray */

/* Inventory statuses – text only */
.in-stock         { color: #1f7b3b; }      /* green */
.low-stock        { color: #8a6d00; }      /* amber */
.out-of-stock     { color: #b91c1c; }      /* red */

  .action-btn{
    padding:6px 10px;
    border-radius:6px;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
    margin-right:4px;
    border:none;
    cursor:pointer;
    display:inline-block;
  }
  .btn-chart{ background:#3182ce; color:white; }
  .btn-update{ background:#ed8936; color:white; }
  .btn-archive{ background:#6b7280; color:white; }

  /* Alerts */
  .alerts-wrap{
    background:var(--panel);
    padding:18px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    border:1px solid #f0f4f8;
  }
  .alerts-header{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:12px;
  }
  .alerts-header h4{
    margin:0;
    font-size:16px;
    color:#1e3a5f;
  }
  .alert-item{
    padding:10px 12px;
    border-radius:8px;
    margin-bottom:8px;
    font-size:14px;
    transition:all 0.2s;
  }
  .alert-item:hover{ transform:translateX(3px); }
  .alert-warning-bg{ background:#fff8e1; border-left:4px solid #ed8936; }
  .alert-danger-bg{ background:#fee2e2; border-left:4px solid #e53e3e; }
  .alert-success-bg{ background:#dff7e8; border-left:4px solid #38a169; }

  /* Pagination */
  .pagination{
    display:flex;
    justify-content:center;
    align-items:center;
    margin-top:20px;
    gap:10px;
  }
  .pagination a, .pagination span{
    display:inline-block;
    padding:8px 12px;
    border-radius:6px;
    text-decoration:none;
    color:var(--navy-700);
    font-weight:500;
  }
  .pagination a:hover{ background:rgba(0,31,63,0.1); }
  .pagination .current{ background:var(--navy-700); color:white; }
  .pagination .disabled{ color:var(--muted); pointer-events:none; }

  /* Modal */
  .modal{
    display:none;
    position:fixed;
    top:0; left:0; right:0; bottom:0;
    background:rgba(0,0,0,0.5);
    z-index:1000;
    align-items:center;
    justify-content:center;
  }
  .modal-content{
    background:white;
    border-radius:12px;
    width:90%;
    max-width:800px;
    max-height:90vh;
    overflow:auto;
    border:1px solid #f0f4f8;
    box-shadow:var(--card-shadow);
  }
  .modal-header{
    background:var(--navy-700);
    color:white;
    padding:20px;
    border-radius:12px 12px 0 0;
    display:flex;
    justify-content:space-between;
    align-items:center;
  }
  .modal-header h3{ margin:0; }
  .modal-close{
    background:none;
    border:none;
    color:white;
    font-size:24px;
    cursor:pointer;
  }
  .modal-body{ padding:20px; }
  .modal-footer{
    padding:20px;
    border-top:1px solid #eee;
    text-align:right;
  }

  .toast-container{
    position:fixed;
    top:20px;
    right:20px;
    z-index:9999;
    max-width:350px;
  }
  .alert-toast{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:10px;
    box-shadow:0 4px 12px rgba(0,0,0,0.15);
    color:white;
    font-weight:500;
    animation:slideIn 0.3s;
  }
  .alert-success{ background:#001F3F; border-left:4px solid #003366; }
  .alert-error{ background:#e53e3e; border-left:4px solid #c53030; }
  @keyframes slideIn{
    from{ transform:translateX(100%); opacity:0; }
    to{ transform:translateX(0); opacity:1; }
  }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo-wrap"><a href="dashboard.php"><img src="logo.jpg" alt="Logo"></a></div>
    <div class="user-info">
      <h4>Logged as:</h4>
      <p><?= htmlspecialchars($current_name) ?><br><strong><?= htmlspecialchars($current_role) ?></strong></p>
    </div>
    <nav class="menu">
      <a href="dashboard.php" class="menu-item <?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'' ?>">
        <span class="icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></span> Dashboard
      </a>
      <?php foreach($allowed_pages as $page => $label): if($page !== 'dashboard.php'): ?>
        <a href="<?= $page ?>" class="menu-item <?= basename($_SERVER['PHP_SELF'])==$page?'active':'' ?>">
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
          </span> <?= $label ?>
        </a>
      <?php endif; endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
      <a href="logout.php" class="menu-item">
        <span class="icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg></span> Logout
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="top-left">
        <h1>Inventory <?= $show_archived ? '(Archived)' : '' ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Manage hospital supplies and equipment</p>
      </div>
      <div class="top-actions">
        <?php if ($show_archived): ?>
          <a href="inventory.php" class="btn btn-outline">View Active</a>
        <?php else: ?>
          <a href="inventory_form.php" class="btn">+ Add Item</a>
          <?php if (($is_admin || $is_inventory) && $has_archive_columns): ?>
            <a href="inventory.php?show=archived" class="btn btn-secondary">Archived</a>
          <?php endif; ?>
        <?php endif; ?>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div id="toast" class="toast-container"></div>

    <!-- Filter badge -->
    <?php if (!empty($filter) || !empty($category_filter) || !empty($status_filter) || !empty($search_term)): ?>
      <div class="filter-badge">
        Filters:
        <?php
        $bits = [];
        if ($filter === 'low_stock') $bits[] = "Low Stock";
        if (!empty($category_filter)) $bits[] = "Category: $category_filter";
        if (!empty($status_filter)) {
          $labels = ['low_stock' => 'Low Stock', 'out_of_stock' => 'Out of Stock', 'in_stock' => 'In Stock'];
          $bits[] = "Status: " . ($labels[$status_filter] ?? $status_filter);
        }
        if (!empty($search_term)) $bits[] = "Search: \"$search_term\"";
        echo implode(' • ', $bits);
        ?>
        <a href="inventory.php" class="close-btn">&times;</a>
      </div>
    <?php endif; ?>

    <!-- Filter controls -->
    <div class="filter-controls">
      <select id="categoryFilter" class="filter-select" onchange="applyFilter()">
        <option value="">All Categories</option>
        <?php foreach($categories as $cat): ?>
          <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $category_filter === $cat['category'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($cat['category']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select id="statusFilter" class="filter-select" onchange="applyFilter()">
        <option value="">All Statuses</option>
        <option value="in_stock" <?= $status_filter === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
        <option value="low_stock" <?= $status_filter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
        <option value="out_of_stock" <?= $status_filter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
      </select>

      <input type="text" id="searchFilter" class="filter-select" placeholder="Search items..." 
             value="<?= htmlspecialchars($search_term) ?>" onkeyup="if(event.key==='Enter') applyFilter()">

      <button onclick="applyFilter()" class="btn" style="background:var(--navy-700);">Apply</button>
      <a href="inventory.php" class="btn btn-outline">Clear</a>
    </div>

    <div class="table-wrap">
      <div class="table-controls">
        <input type="text" id="searchInput" class="search-input" placeholder="Search by name or category...">
        <div class="muted">Showing <span id="rowCount"><?= count($rows) ?></span> of <?= number_format($total) ?> items</div>
      </div>

      <table id="inventoryTable">
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
              if ($r['quantity'] <= 0) {
                  $stock_status = 'out-of-stock';
                  $status_text = 'Out of Stock';
              } elseif ($r['quantity'] <= $r['threshold']) {
                  $stock_status = 'low-stock';
                  $status_text = 'Low Stock';
              } else {
                  $stock_status = 'in-stock';
                  $status_text = 'In Stock';
              }
              $is_archived = $has_archive_columns && !empty($r['is_archived']);
              $row_bg = $is_archived ? 'style="background:rgba(149,165,166,0.1);"' : '';
              $item_name = htmlspecialchars($r['item_name'] ?? '');
              ?>
              <tr data-id="<?= $r['id'] ?>" <?= $row_bg ?>>
                <td>#<?= $r['id'] ?></td>
                <td><?= $item_name ?></td>
                <td><?= htmlspecialchars($r['category'] ?? '') ?></td>
                <td><?= $r['quantity'] ?></td>
                <td><?= htmlspecialchars($r['unit'] ?? '') ?></td>
                <td><?= $r['threshold'] ?></td>
                <td>
                  <span class="status <?= $stock_status ?>"><?= $status_text ?></span>
                  <?php if ($is_archived): ?>
                    <span class="status archived" style="margin-left:5px;">Archived</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <a href="inventory_view.php?id=<?= $r['id'] ?>" class="action-btn btn-chart">Chart</a>
                  <?php if (!$is_archived || $is_admin || $is_inventory): ?>
                    <a href="inventory_form.php?id=<?= $r['id'] ?>" class="action-btn btn-update">Update</a>
                  <?php endif; ?>
                  <?php if (($is_admin || $is_inventory) && $has_archive_columns): ?>
    <?php if ($is_archived): ?>
        <a href="inventory.php?restore=<?= $r['id'] ?>" class="action-btn btn-archive" onclick="return confirm('Restore item &quot;<?= htmlspecialchars($item_name, ENT_QUOTES) ?>&quot;?')">Restore</a>
    <?php else: ?>
        <a href="inventory.php?archive=<?= $r['id'] ?>" class="action-btn btn-archive" onclick="return confirm('Archive item &quot;<?= htmlspecialchars($item_name, ENT_QUOTES) ?>&quot;?')">Archive</a>
    <?php endif; ?>
<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" style="text-align:center; padding:30px;">No items found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?><?= $show_archived?'&show=archived':'' ?><?= !empty($filter)?"&filter=$filter":'' ?><?= !empty($category_filter)?"&category=$category_filter":'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($search_term)?"&search=$search_term":'' ?>">&laquo;</a>
          <?php else: ?>
            <span class="disabled">&laquo;</span>
          <?php endif; ?>
          <?php for($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
            <?php if($i==$page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?><?= $show_archived?'&show=archived':'' ?><?= !empty($filter)?"&filter=$filter":'' ?><?= !empty($category_filter)?"&category=$category_filter":'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($search_term)?"&search=$search_term":'' ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?><?= $show_archived?'&show=archived':'' ?><?= !empty($filter)?"&filter=$filter":'' ?><?= !empty($category_filter)?"&category=$category_filter":'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($search_term)?"&search=$search_term":'' ?>">&raquo;</a>
          <?php else: ?>
            <span class="disabled">&raquo;</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Low Stock Alerts -->
    <div class="alerts-wrap">
      <div class="alerts-header">
        <span style="font-size:20px;">📢</span>
        <h4>Low Stock Alerts</h4>
      </div>
      <?php if (empty($low_stock_items)): ?>
        <div class="alert-item alert-success-bg">✅ No low stock items.</div>
      <?php else: ?>
        <?php foreach($low_stock_items as $l): ?>
          <?php
          $alert_class = $l['quantity'] == 0 ? 'alert-danger-bg' : 'alert-warning-bg';
          $urgency = $l['quantity'] == 0 ? 'Out of Stock!' : 'Low Stock';
          ?>
          <div class="alert-item <?= $alert_class ?>">
            <strong><?= htmlspecialchars($l['item_name']) ?></strong> —
            <?= $l['quantity'] ?> <?= htmlspecialchars($l['unit']) ?> remaining
            (threshold: <?= $l['threshold'] ?>) — <strong><?= $urgency ?></strong>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- View Item Modal -->
<div id="viewItemModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Item Details</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div id="itemDetails" class="modal-body">
      <p style="color: var(--muted); text-align:center;">Loading...</p>
    </div>
    <div class="modal-footer">
      <button onclick="closeModal()" class="btn btn-secondary">Close</button>
    </div>
  </div>
</div>

<script>
// ================== FILTER FUNCTIONS ==================
function applyFilter() {
    const category = document.getElementById('categoryFilter').value;
    const status = document.getElementById('statusFilter').value;
    const search = document.getElementById('searchFilter').value;
    let url = 'inventory.php?';
    const params = [];
    <?php if ($show_archived): ?>params.push('show=archived');<?php endif; ?>
    if (category) params.push('category=' + encodeURIComponent(category));
    if (status) params.push('status=' + encodeURIComponent(status));
    if (search) params.push('search=' + encodeURIComponent(search));
    window.location.href = url + params.join('&');
}

// ================== MODAL FUNCTIONS ==================
function viewItem(id) {
    console.log('viewItem called with id', id); // Debug log
    const modal = document.getElementById('viewItemModal');
    const details = document.getElementById('itemDetails');
    
    if (!modal) {
        console.error('Modal element not found!');
        alert('Error: Modal element missing.');
        return;
    }
    if (!details) {
        console.error('Details container not found!');
        alert('Error: Details container missing.');
        return;
    }
    
    details.innerHTML = '<p style="color: var(--muted); text-align:center;">Loading...</p>';
    modal.style.display = 'flex';

    fetch('inventory_view.php?id=' + encodeURIComponent(id))
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.text();
        })
        .then(html => {
            details.innerHTML = html;
        })
        .catch(err => {
            console.error('Fetch error:', err);
            details.innerHTML = '<p class="alert alert-error">Error loading details. Please try again.</p>';
        });
}

function closeModal() {
    const modal = document.getElementById('viewItemModal');
    if (modal) modal.style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
};

// ================== ARCHIVE/RESTORE ==================
function confirmArchive(id, name) {
    if (confirm('Archive item "' + name + '"?')) {
        window.location.href = 'inventory.php?archive=' + id;
    }
}
function confirmRestore(id, name) {
    if (confirm('Restore item "' + name + '"?')) {
        window.location.href = 'inventory.php?restore=' + id;
    }
}

// ================== TABLE SEARCH ==================
const searchInput = document.getElementById('searchInput');
const tbody = document.querySelector('#inventoryTable tbody');
const rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
const rowCount = document.getElementById('rowCount');

function filterTable(q) {
    q = q.toLowerCase();
    let visible = 0;
    rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        const ok = q === '' || text.includes(q);
        r.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });
    if (rowCount) rowCount.textContent = visible;
}
if (searchInput) {
    searchInput.addEventListener('input', () => filterTable(searchInput.value));
    filterTable('');
}

// ================== TOAST NOTIFICATIONS ==================
function showToast(msg, type = 'success') {
    const container = document.getElementById('toast');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `alert-toast alert-${type}`;
    toast.innerHTML = `<div style="display:flex; justify-content:space-between;">${msg}<button onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white;">&times;</button></div>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

// ================== PAGE LOAD ==================
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM fully loaded, viewItem available:', typeof viewItem === 'function');
    
    const success = <?= json_encode($success) ?>;
    const iId = <?= json_encode($item_id) ?>;
    if (success) {
        const msgs = {
            added: 'Item added.',
            updated: 'Item updated.',
            archived: 'Item archived.',
            restored: 'Item restored.',
            error: 'An error occurred.'
        };
        showToast(msgs[success] || 'Done.', success === 'error' ? 'error' : 'success');
        if (iId && ['added','updated','archived','restored'].includes(success)) {
            setTimeout(() => {
                const row = document.querySelector(`tr[data-id="${iId}"]`);
                if (row) {
                    row.style.backgroundColor = 'rgba(0,31,63,0.1)';
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => row.style.backgroundColor = '', 2000);
                }
            }, 300);
        }
    }
});
</script>
</body>
</html>