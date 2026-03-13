<?php
session_start();
require 'config.php';
require 'functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
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
    ]
];

$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Filters
$filter_username = isset($_GET['username']) ? trim($_GET['username']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_from = isset($_GET['from']) ? trim($_GET['from']) : '';
$filter_to = isset($_GET['to']) ? trim($_GET['to']) : '';

// Build WHERE clause
$where_parts = [];
$params = [];
$types = '';

if (!empty($filter_username)) {
    $where_parts[] = "username LIKE ?";
    $params[] = "%$filter_username%";
    $types .= 's';
}

if (!empty($filter_status)) {
    // Use the correct column name: login_status
    $where_parts[] = "login_status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if (!empty($filter_from)) {
    $where_parts[] = "login_time >= ?";
    $params[] = $filter_from . " 00:00:00";
    $types .= 's';
}

if (!empty($filter_to)) {
    $where_parts[] = "login_time <= ?";
    $params[] = $filter_to . " 23:59:59";
    $types .= 's';
}

$where = count($where_parts) > 0 ? "WHERE " . implode(" AND ", $where_parts) : "";

// Pagination
$items_per_page = 15;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM login_audit $where";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $items_per_page);

// Get audit logs
$audit_params = array_merge($params, [$items_per_page, $offset]);
$audit_types = $types . 'ii';

$sql = "SELECT * FROM login_audit $where ORDER BY login_time DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($audit_types, ...$audit_params);
$stmt->execute();
$audits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="login_audit_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Username', 'Status', 'IP Address', 'Login Time', 'Logout Time', 'Duration (min)']);
    
    $export_sql = "SELECT * FROM login_audit $where ORDER BY login_time DESC";
    $export_stmt = $conn->prepare($export_sql);
    if (!empty($params)) {
        $export_stmt->bind_param($types, ...$params);
    }
    $export_stmt->execute();
    $export_result = $export_stmt->get_result();
    
    while ($row = $export_result->fetch_assoc()) {
        $duration = '';
        if (!empty($row['logout_time'])) {
            $login_ts = strtotime($row['login_time']);
            $logout_ts = strtotime($row['logout_time']);
            $duration = round(($logout_ts - $login_ts) / 60, 2);
        }
        fputcsv($output, [
            $row['id'],
            $row['username'],
            $row['login_status'],   // Correct column name
            $row['ip_address'],
            $row['login_time'],
            $row['logout_time'] ?: '-',
            $duration ?: '-'
        ]);
    }
    fclose($output);
    exit();
}

// Get stats
$success_sql = "SELECT COUNT(*) as count FROM login_audit WHERE login_status = 'Success'";
$failed_sql = "SELECT COUNT(*) as count FROM login_audit WHERE login_status = 'Failed'";

// Append filters if any (reuse WHERE conditions but without adding another WHERE)
if (count($where_parts) > 0) {
    $filter_condition = " AND " . implode(" AND ", $where_parts);
    $success_sql .= $filter_condition;
    $failed_sql .= $filter_condition;
}

$success_count = $conn->query($success_sql)->fetch_assoc()['count'] ?? 0;
$failed_count = $conn->query($failed_sql)->fetch_assoc()['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - Login Audit</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  /* (Keep all existing CSS exactly as it was) */
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
    overflow-y: auto;
    z-index:30;
  }

  .sidebar::-webkit-scrollbar {
    width: 4px;
  }

  .sidebar::-webkit-scrollbar-track {
    background: transparent;
  }

  .sidebar::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.2);
    border-radius: 4px;
  }

  .logo-wrap {
    display:flex;
    align-items:center;
    justify-content:center;
    padding:10px 0;
    border-bottom: 1px solid rgba(255,255,255,0.1);
  }

  .logo-wrap img {
    width:150px;
    cursor: pointer;
    transition: transform 0.2s;
  }

  .logo-wrap img:hover {
    transform: scale(1.05);
  }

  .logo-wrap a {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .user-info {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 10px;
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

  .menu {
    display:flex;
    flex-direction:column;
    gap:8px;
    overflow-y:auto;
  }

  .menu::-webkit-scrollbar {
    width: 4px;
  }

  .menu::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.2);
    border-radius: 4px;
  }

  .menu-item {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 10px;
    border-radius:8px;
    color:rgba(255,255,255,0.8);
    text-decoration:none;
    font-size:14px;
    font-weight:500;
    transition:all 0.2s ease;
    cursor: pointer;
  }

  .menu-item:hover {
    background:rgba(255,255,255,0.1);
    color:#fff;
  }

  .menu-item.active {
    background:var(--light-blue);
    color:#fff;
  }

  .menu-item .icon {
    width:18px;
    height:18px;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
  }

  .menu-item .label {
    flex:1;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  .sidebar-bottom {
    margin-top:auto;
    font-size:13px;
    color:rgba(255,255,255,0.8);
    opacity:0.95;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.1);
  }

  /* MAIN */
  .main {
    margin-left:230px;
    padding:18px 28px;
    width:100%;
  }

  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:20px;
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
    font-size: 13px;
  }

  .btn:hover {
    background:var(--accent);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
  }

  .btn.secondary {
    background: #6c757d;
  }

  .btn.secondary:hover {
    background: #5a6268;
  }

  .date-pill {
    background: var(--panel);
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
    white-space: nowrap;
  }

  .role-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 8px;
    background: rgba(0,0,0,0.1);
    color: inherit;
  }

  /* Filters */
  .filter-wrap {
    background: var(--panel);
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: var(--card-shadow);
  }

  .filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
  }

  .filter-row.actions {
    margin-bottom: 0;
  }

  .filter-group {
    display: flex;
    flex-direction: column;
  }

  .filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 6px;
    text-transform: uppercase;
  }

  .filter-group input,
  .filter-group select {
    padding: 8px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-family: Inter;
    transition: border-color 0.2s;
  }

  .filter-group input:focus,
  .filter-group select:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }

  .filter-actions {
    display: flex;
    gap: 8px;
    align-items: flex-end;
  }

  .filter-actions .btn {
    padding: 8px 14px;
    font-size: 13px;
  }

  /* Stats */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
  }

  .stat-card {
    background: var(--panel);
    padding: 16px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    border-left: 4px solid var(--light-blue);
  }

  .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--navy-700);
  }

  .stat-label {
    font-size: 12px;
    color: var(--muted);
    margin-top: 6px;
    font-weight: 500;
  }

  .stat-card.success {
    border-left-color: #10b981;
  }

  .stat-card.danger {
    border-left-color: #ef4444;
  }

  /* Table */
  .table-wrap {
    background: var(--panel);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--card-shadow);
  }

  .table-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border-bottom: 1px solid #f3f4f6;
    flex-wrap: wrap;
    gap: 12px;
  }

  .left-controls {
    display: flex;
    gap: 8px;
  }

  .search-input {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    min-width: 250px;
    transition: border-color 0.2s;
  }

  .search-input:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
  }

  thead {
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
  }

  th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    font-size: 11px;
  }

  td {
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
  }

  tbody tr:hover {
    background: #f9fafb;
  }

  tbody tr:last-child td {
    border-bottom: none;
  }

  .status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
  }

  .status-Success {
    background: #d1fae5;
    color: #065f46;
  }

  .status-Failed {
    background: #fee2e2;
    color: #991b1b;
  }

  .pagination {
    display: flex;
    justify-content: center;
    gap: 4px;
    padding: 16px;
    border-top: 1px solid #f3f4f6;
  }

  .pagination a,
  .pagination span {
    padding: 6px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    text-decoration: none;
    color: var(--navy-700);
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s;
  }

  .pagination a:hover {
    background: var(--navy-700);
    color: white;
    border-color: var(--navy-700);
  }

  .pagination .active {
    background: var(--navy-700);
    color: white;
    border-color: var(--navy-700);
  }

  .pagination .disabled {
    color: #d1d5db;
    cursor: not-allowed;
    background: #f9fafb;
  }

  .empty-state {
    padding: 40px;
    text-align: center;
    color: var(--muted);
  }

  .empty-state p {
    margin: 0;
    font-size: 14px;
  }
</style>
</head>
<body>
  <div class="app">

    <!-- SIDEBAR (unchanged) -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
        <a href="dashboard.php">
          <img src="logo.jpg" alt="Hospital Logo">
        </a>
      </div>

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
          <h1>Login Audit Report
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Monitor and review user login activities and security - Gig Oca Robles Seamen's Hospital Davao</p>
        </div>

        <div class="top-actions">
          <a href="login_audit.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn">📥 Export CSV</a>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container (optional) -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- Filter Section -->
      <div class="filter-wrap">
        <form method="GET" action="" style="display: contents;">
          <div class="filter-row">
            <div class="filter-group">
              <label for="username">Username</label>
              <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($filter_username); ?>" placeholder="Search...">
            </div>

            <div class="filter-group">
              <label for="status">Status</label>
              <select id="status" name="status">
                <option value="">All Status</option>
                <option value="Success" <?php echo $filter_status === 'Success' ? 'selected' : ''; ?>>Success</option>
                <option value="Failed" <?php echo $filter_status === 'Failed' ? 'selected' : ''; ?>>Failed</option>
              </select>
            </div>

            <div class="filter-group">
              <label for="from">From Date</label>
              <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
            </div>

            <div class="filter-group">
              <label for="to">To Date</label>
              <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
            </div>
          </div>

          <div class="filter-row actions">
            <div class="filter-actions">
              <button type="submit" class="btn">Apply Filter</button>
              <a href="login_audit.php" class="btn secondary">Reset</a>
            </div>
          </div>
        </form>
      </div>

      <!-- Stats -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-value"><?php echo $total_records; ?></div>
          <div class="stat-label">Total Login Records</div>
        </div>
        <div class="stat-card success">
          <div class="stat-value"><?php echo $success_count; ?></div>
          <div class="stat-label">Successful Logins</div>
        </div>
        <div class="stat-card danger">
          <div class="stat-value"><?php echo $failed_count; ?></div>
          <div class="stat-label">Failed Attempts</div>
        </div>
      </div>

      <!-- Audit Table -->
      <div class="table-wrap" id="auditSection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by username or IP...">
          </div>
          <div class="muted" style="font-size: 13px;">Showing <span id="rowCount"><?php echo count($audits); ?></span> of <?php echo number_format($total_records); ?> records</div>
        </div>

        <?php if (!empty($audits)): ?>
          <table aria-label="Login audit table">
            <thead>
              <tr>
                <th>Username</th>
                <th>Status</th>
                <th>IP Address</th>
                <th>Login Time</th>
                <th>Logout Time</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($audits as $audit): 
                // Compute session duration
                $duration = '-';
                if (!empty($audit['logout_time'])) {
                    $login_ts = strtotime($audit['login_time']);
                    $logout_ts = strtotime($audit['logout_time']);
                    $duration_min = round(($logout_ts - $login_ts) / 60, 1);
                    $duration = $duration_min . ' min';
                }
              ?>
                <tr>
                  <td><strong><?php echo htmlspecialchars($audit['username']); ?></strong></td>
                  <td>
                    <span class="status-badge status-<?php echo $audit['login_status']; ?>">
                      <?php echo $audit['login_status']; ?>
                    </span>
                  </td>
                  <td><code style="background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px;"><?php echo htmlspecialchars($audit['ip_address'] ?: '-'); ?></code></td>
                  <td><?php echo date('M d, Y H:i', strtotime($audit['login_time'])); ?></td>
                  <td><?php echo $audit['logout_time'] ? date('M d, Y H:i', strtotime($audit['logout_time'])) : '-'; ?></td>
                  <td><?php echo $duration; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if ($total_pages > 1): ?>
            <div class="pagination">
              <?php if ($current_page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">« First</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>">‹ Prev</a>
              <?php else: ?>
                <span class="disabled">« First</span>
                <span class="disabled">‹ Prev</span>
              <?php endif; ?>

              <?php for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++): ?>
                <?php if ($i == $current_page): ?>
                  <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                  <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
              <?php endfor; ?>

              <?php if ($current_page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>">Next ›</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last »</a>
              <?php else: ?>
                <span class="disabled">Next ›</span>
                <span class="disabled">Last »</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="empty-state">
            <p>No login records found.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script>
    // Simple client-side filtering (optional)
    document.getElementById('searchInput').addEventListener('keyup', function() {
      let filter = this.value.toLowerCase();
      let rows = document.querySelectorAll('tbody tr');
      let visibleCount = 0;
      rows.forEach(row => {
        let username = row.cells[0].textContent.toLowerCase();
        let ip = row.cells[2].textContent.toLowerCase();
        if (username.includes(filter) || ip.includes(filter)) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });
      document.getElementById('rowCount').textContent = visibleCount;
    });
  </script>
</body>
</html>