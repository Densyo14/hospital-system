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
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';
$patient_filter = $_GET['patient'] ?? '';
$doctor_filter = $_GET['doctor'] ?? '';

$success = $_GET['success'] ?? '';
$action  = $_GET['action'] ?? '';
$surgery_id = isset($_GET['surgery_id']) ? (int)$_GET['surgery_id'] : 0;

$is_admin = ($current_role === 'Admin');

// Check archive columns
$table_check = fetchOne($conn, "SHOW COLUMNS FROM surgeries LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;

// Archive / Restore (admin only)
if (isset($_GET['archive']) && $is_admin && $has_archive_columns) {
    $id = (int)$_GET['archive'];
    execute($conn, "UPDATE surgeries SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?", "ii", [$current_user_id, $id]);
    header("Location: surgeries.php?success=archived&surgery_id=$id");
    exit();
}
if (isset($_GET['restore']) && $is_admin && $has_archive_columns) {
    $id = (int)$_GET['restore'];
    execute($conn, "UPDATE surgeries SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?", "i", [$id]);
    header("Location: surgeries.php?success=restored&surgery_id=$id");
    exit();
}

$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';

// Build conditions
$conditions = [];
if ($has_archive_columns) {
    $conditions[] = $show_archived ? "s.is_archived = 1" : "s.is_archived = 0";
}
if (!empty($status_filter) && in_array($status_filter, ['Scheduled', 'Completed', 'Cancelled', 'In Progress'])) {
    $conditions[] = "s.status = '$status_filter'";
}
if (!empty($date_filter) && validateDate($date_filter, 'Y-m-d')) {
    $conditions[] = "DATE(s.schedule_date) = '$date_filter'";
}
if (!empty($patient_filter) && is_numeric($patient_filter)) {
    $conditions[] = "s.patient_id = $patient_filter";
}
if (!empty($doctor_filter) && is_numeric($doctor_filter)) {
    $conditions[] = "s.doctor_id = $doctor_filter";
}
$where_clause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

// Pagination
$items_per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $items_per_page;

$total = fetchOne($conn, "SELECT COUNT(*) as total FROM surgeries s $where_clause")['total'] ?? 0;
$total_pages = ceil($total / $items_per_page);

// Sorting
$sort_column = $_GET['sort'] ?? 'schedule';
$sort_order = $_GET['order'] ?? 'desc';
$allowed_columns = ['id', 'patient', 'doctor', 'type', 'date', 'status'];
if (!in_array($sort_column, $allowed_columns)) $sort_column = 'schedule';
if (!in_array($sort_order, ['asc', 'desc'])) $sort_order = 'desc';

switch ($sort_column) {
    case 'id':       $order_by = "s.id"; break;
    case 'patient':  $order_by = "p.last_name, p.first_name"; break;
    case 'doctor':   $order_by = "u.full_name"; break;
    case 'type':     $order_by = "s.surgery_type"; break;
    case 'date':     $order_by = "s.schedule_date"; break;
    case 'status':   $order_by = "s.status"; break;
    default:         $order_by = "s.schedule_date";
}
$order_by .= " " . strtoupper($sort_order);

// Fetch surgeries
$query = "
    SELECT s.*, 
           p.first_name, p.last_name,
           u.full_name AS doctor_name
    FROM surgeries s
    INNER JOIN patients p ON s.patient_id = p.id
    INNER JOIN users u ON s.doctor_id = u.id
    $where_clause
    ORDER BY $order_by
    LIMIT $offset, $items_per_page
";
$rows = fetchAll($conn, $query);

// Get dropdown data
$patients = fetchAll($conn, "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM patients WHERE is_archived = 0 ORDER BY last_name");
$doctors = fetchAll($conn, "SELECT id, full_name FROM users WHERE role = 'Doctor' AND is_active = 1 ORDER BY full_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Surgeries</title>
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
  .btn-secondary { background:#6c757d; color:#fff; }
  .btn-secondary:hover { background:#5a6268; }
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
    flex-wrap:wrap;
  }
  .filter-form{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
  }
  .filter-select{
    padding:8px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    background:#fff;
    min-width:140px;
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
  table{ width:100%; border-collapse:collapse; min-width:900px; }
  thead th{
    background:#f8fbfd;
    padding:14px;
    text-align:left;
    color:#6b7280;
    font-weight:600;
    border-bottom:2px solid #e6eef0;
  }
  td, th{ padding:14px; border-bottom:1px solid #f0f3f4; color:#233; }
  .sortable-header{
    cursor:pointer;
    position:relative;
    padding-right:20px !important;
  }
  .sortable-header:hover{ background-color:#f0f3f4; }
  .sortable-header:after{
    content:'';
    position:absolute;
    right:8px;
    top:50%;
    transform:translateY(-50%);
    width:0; height:0;
    border-left:5px solid transparent;
    border-right:5px solid transparent;
  }
  .sortable-header.asc:after{ border-bottom:5px solid var(--navy-700); border-top:none; }
  .sortable-header.desc:after{ border-top:5px solid var(--navy-700); border-bottom:none; }
  .sortable-header:not(.asc):not(.desc):after{ border-top:5px solid #ccc; opacity:0.5; }

  .status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 16px;
    font-weight: 600;
    font-size: 13px;
    cursor: default;          /* not clickable */
    transition: none;
    background: transparent;   /* no background */
}

/* No hover effect */
.status:hover {
    filter: none;
}

/* Text colors only – adjust shades as needed */
.pending          { color: #b45f06; }   /* amber */
.waiting          { color: #b45f06; }   /* same as pending */
.scheduled        { color: #b45f06; }   /* optional */
.approved         { color: #0b5e7c; }   /* teal */
.completed        { color: #0d6632; }   /* green */
.cancelled        { color: #a12b2b; }   /* red */
.in-progress      { color: #1b6b8f; }   /* blue */
.in_consultation  { color: #5e3c9c; }   /* purple */
.archived         { color: #4a4f55; }   /* gray */

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

  .toast-container{
    position:fixed;
    top:20px;
    right:20px;
    z-index:9999;
    max-width:350px;
  }
  .alert{
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

  <div class="main">
    <div class="topbar">
      <div class="top-left">
        <h1>Surgeries <?= $show_archived ? '(Archived)' : '' ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Manage surgical procedures</p>
      </div>
      <div class="top-actions">
        <?php if ($show_archived): ?>
          <a href="surgeries.php" class="btn btn-outline">View Active</a>
        <?php else: ?>
          <a href="surgery_form.php" class="btn">+ New Surgery</a>
          <?php if ($is_admin && $has_archive_columns): ?>
            <a href="surgeries.php?show=archived" class="btn" style="background:#6c757d;">Archived</a>
          <?php endif; ?>
        <?php endif; ?>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div id="toast" class="toast-container"></div>

    <?php if (!empty($status_filter) || !empty($date_filter) || !empty($patient_filter) || !empty($doctor_filter)): ?>
      <div class="filter-badge">
        Filters: 
        <?php
        $bits = [];
        if (!empty($status_filter)) $bits[] = "Status: $status_filter";
        if (!empty($date_filter)) $bits[] = "Date: " . date('M j, Y', strtotime($date_filter));
        if (!empty($patient_filter)) {
            $p = fetchOne($conn, "SELECT CONCAT(first_name,' ',last_name) AS name FROM patients WHERE id = ?", "i", [$patient_filter]);
            if ($p) $bits[] = "Patient: " . $p['name'];
        }
        if (!empty($doctor_filter)) {
            $d = fetchOne($conn, "SELECT full_name FROM users WHERE id = ?", "i", [$doctor_filter]);
            if ($d) $bits[] = "Doctor: " . $d['full_name'];
        }
        echo implode(' • ', $bits);
        ?>
        <a href="surgeries.php" class="close-btn">&times;</a>
      </div>
    <?php endif; ?>

    <div class="table-wrap">
      <div class="table-controls">
        <form method="GET" class="filter-form">
          <select name="patient" class="filter-select">
            <option value="">All Patients</option>
            <?php foreach($patients as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $patient_filter == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="doctor" class="filter-select">
            <option value="">All Doctors</option>
            <?php foreach($doctors as $d): ?>
              <option value="<?= $d['id'] ?>" <?= $doctor_filter == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="filter-select">
            <option value="">All Status</option>
            <option value="Scheduled" <?= $status_filter=='Scheduled'?'selected':'' ?>>Scheduled</option>
            <option value="In Progress" <?= $status_filter=='In Progress'?'selected':'' ?>>In Progress</option>
            <option value="Completed" <?= $status_filter=='Completed'?'selected':'' ?>>Completed</option>
            <option value="Cancelled" <?= $status_filter=='Cancelled'?'selected':'' ?>>Cancelled</option>
          </select>
          <input type="date" name="date" class="filter-select" value="<?= htmlspecialchars($date_filter) ?>" style="min-width:140px;">
          <button type="submit" class="btn">Filter</button>
          <a href="surgeries.php" class="btn btn-outline">Clear</a>
        </form>
        <div class="muted">Showing <span id="rowCount"><?= count($rows) ?></span> of <?= number_format($total) ?></div>
      </div>

      <table id="surgeriesTable">
        <thead>
          <tr>
            <th class="sortable-header <?= $sort_column=='id'?$sort_order:'' ?>" onclick="sortTable('id','<?= $sort_column=='id' && $sort_order=='asc'?'desc':'asc' ?>')">ID</th>
            <th class="sortable-header <?= $sort_column=='patient'?$sort_order:'' ?>" onclick="sortTable('patient','<?= $sort_column=='patient' && $sort_order=='asc'?'desc':'asc' ?>')">Patient</th>
            <th class="sortable-header <?= $sort_column=='type'?$sort_order:'' ?>" onclick="sortTable('type','<?= $sort_column=='type' && $sort_order=='asc'?'desc':'asc' ?>')">Surgery Type</th>
            <th class="sortable-header <?= $sort_column=='doctor'?$sort_order:'' ?>" onclick="sortTable('doctor','<?= $sort_column=='doctor' && $sort_order=='asc'?'desc':'asc' ?>')">Doctor</th>
            <th class="sortable-header <?= $sort_column=='date'?$sort_order:'' ?>" onclick="sortTable('date','<?= $sort_column=='date' && $sort_order=='asc'?'desc':'asc' ?>')">Date</th>
            <th>Operating Room</th>
            <th class="sortable-header <?= $sort_column=='status'?$sort_order:'' ?>" onclick="sortTable('status','<?= $sort_column=='status' && $sort_order=='asc'?'desc':'asc' ?>')">Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) > 0): ?>
            <?php foreach($rows as $r): ?>
              <?php
              $status_class = [
                  'Scheduled'   => 'scheduled',
                  'Completed'   => 'completed',
                  'Cancelled'   => 'cancelled',
                  'In Progress' => 'in-progress'
              ][$r['status']] ?? 'scheduled';
              $is_archived = $has_archive_columns && !empty($r['is_archived']);
              if ($is_archived) $status_class = 'archived';
              $row_bg = $is_archived ? 'style="background:rgba(149,165,166,0.1);"' : '';
              $patient_name = h($r['first_name'] . ' ' . $r['last_name']);
              ?>
              <tr data-id="<?= $r['id'] ?>" <?= $row_bg ?>>
                <td>S-<?= $r['id'] ?></td>
                <td><?= $patient_name ?></td>
                <td><?= h($r['surgery_type'] ?? '') ?></td>
                <td><?= h($r['doctor_name'] ?? '') ?></td>
                <td><?= date('M j, Y', strtotime($r['schedule_date'])) ?></td>
                <td><?= h($r['operating_room'] ?? '') ?></td>
                <td><span class="status <?= $status_class ?>"><?= $is_archived ? 'Archived' : $r['status'] ?></span></td>
                <td style="white-space:nowrap;">
                  <a href="surgery_view.php?id=<?= $r['id'] ?>" class="action-btn btn-chart">Chart</a>
                  <?php if (!$is_archived): ?>
                    <a href="surgery_form.php?id=<?= $r['id'] ?>" class="action-btn btn-update">Update</a>
                    <?php if ($is_admin): ?>
                      <button onclick="confirmArchive(<?= $r['id'] ?>, '<?= htmlspecialchars($patient_name, ENT_QUOTES) ?>')" class="action-btn btn-archive">Archive</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if ($is_admin): ?>
                      <button onclick="confirmRestore(<?= $r['id'] ?>, '<?= htmlspecialchars($patient_name, ENT_QUOTES) ?>')" class="action-btn btn-archive">Restore</button>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" style="text-align:center; padding:30px;">No surgeries found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&sort=<?= $sort_column ?>&order=<?= $sort_order ?><?= $show_archived?'&show=archived':'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($date_filter)?"&date=$date_filter":'' ?><?= !empty($patient_filter)?"&patient=$patient_filter":'' ?><?= !empty($doctor_filter)?"&doctor=$doctor_filter":'' ?>">&laquo;</a>
          <?php else: ?>
            <span class="disabled">&laquo;</span>
          <?php endif; ?>
          <?php for($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
            <?php if($i==$page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?>&sort=<?= $sort_column ?>&order=<?= $sort_order ?><?= $show_archived?'&show=archived':'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($date_filter)?"&date=$date_filter":'' ?><?= !empty($patient_filter)?"&patient=$patient_filter":'' ?><?= !empty($doctor_filter)?"&doctor=$doctor_filter":'' ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&sort=<?= $sort_column ?>&order=<?= $sort_order ?><?= $show_archived?'&show=archived':'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($date_filter)?"&date=$date_filter":'' ?><?= !empty($patient_filter)?"&patient=$patient_filter":'' ?><?= !empty($doctor_filter)?"&doctor=$doctor_filter":'' ?>">&raquo;</a>
          <?php else: ?>
            <span class="disabled">&raquo;</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- View Surgery Modal -->
<div id="viewSurgeryModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Surgery Details</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div id="surgeryDetails" class="modal-body">Loading...</div>
    <div class="modal-footer">
      <button onclick="closeModal()" class="btn btn-secondary">Close</button>
    </div>
  </div>
</div>

<script>
function sortTable(column, order) {
  const url = new URL(window.location.href);
  url.searchParams.set('sort', column);
  url.searchParams.set('order', order);
  url.searchParams.set('page', 1);
  window.location.href = url.toString();
}

function viewSurgery(id) {
  const modal = document.getElementById('viewSurgeryModal');
  const details = document.getElementById('surgeryDetails');
  details.innerHTML = 'Loading...';
  modal.style.display = 'flex';
  fetch('surgery_view.php?id=' + id)
    .then(r => r.text())
    .then(html => details.innerHTML = html)
    .catch(() => details.innerHTML = '<div class="alert alert-error">Error loading details.</div>');
}

function closeModal() {
  document.getElementById('viewSurgeryModal').style.display = 'none';
}

window.onclick = function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.style.display = 'none';
  }
};

function confirmArchive(id, name) {
  if (confirm(`Archive surgery for "${name}"?`)) {
    window.location.href = 'surgeries.php?archive=' + id;
  }
}
function confirmRestore(id, name) {
  if (confirm(`Restore surgery for "${name}"?`)) {
    window.location.href = 'surgeries.php?restore=' + id;
  }
}

// Search (client‑side)
const searchInput = document.getElementById('searchInput');
if (searchInput) {
  const tbody = document.querySelector('#surgeriesTable tbody');
  const rows = Array.from(tbody.querySelectorAll('tr'));
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
    rowCount.textContent = visible;
  }
  searchInput.addEventListener('input', () => filterTable(searchInput.value));
  filterTable('');
}

function showToast(msg, type = 'success') {
  const container = document.getElementById('toast');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `alert alert-${type}`;
  toast.innerHTML = `<div style="display:flex; justify-content:space-between;">${msg}<button onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white;">&times;</button></div>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}

document.addEventListener('DOMContentLoaded', function() {
  const success = <?= json_encode($success) ?>;
  const sId = <?= json_encode($surgery_id) ?>;
  if (success) {
    const msgs = {
      added: 'Surgery added.',
      updated: 'Surgery updated.',
      archived: 'Surgery archived.',
      restored: 'Surgery restored.',
      error: 'An error occurred.'
    };
    showToast(msgs[success] || 'Done.', success === 'error' ? 'error' : 'success');
    if (sId && ['added','updated','archived','restored'].includes(success)) {
      setTimeout(() => {
        const row = document.querySelector(`tr[data-id="${sId}"]`);
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