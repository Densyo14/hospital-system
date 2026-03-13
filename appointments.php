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

// Role permissions
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
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';

$success = $_GET['success'] ?? '';
$action  = $_GET['action'] ?? '';
$appointment_id = $_GET['appointment_id'] ?? 0;

$is_admin = ($current_role === 'Admin');

// Handle status updates (unchanged)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = (int)$_POST['appointment_id'];
    $new_status = $_POST['new_status'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($new_status, ['Approved', 'Cancelled', 'Completed'])) {
        die("Invalid status.");
    }

    $appt = fetchOne($conn, "SELECT doctor_id, status FROM appointments WHERE id = ?", "i", [$id]);
    if (!$appt) die("Appointment not found.");

    $is_owner = ($appt['doctor_id'] == $current_user_id);

    $can_approve = ($current_role === 'Admin') || ($current_role === 'Doctor' && $is_owner);
    $can_cancel  = ($current_role === 'Admin') || 
                   ($current_role === 'Doctor' && $is_owner) ||
                   ($current_role === 'Nurse') ||
                   ($current_role === 'Staff');
    $can_complete = ($current_role === 'Admin') || 
                    ($current_role === 'Doctor' && $is_owner) ||
                    ($current_role === 'Nurse');

    if ($new_status === 'Approved' && !$can_approve) die("Not authorized to approve.");
    if ($new_status === 'Cancelled' && !$can_cancel) die("Not authorized to cancel.");
    if ($new_status === 'Completed' && !$can_complete) die("Not authorized to complete.");

    if ($appt['status'] === 'Completed' || $appt['status'] === 'Cancelled') {
        die("Cannot change status of completed/cancelled appointment.");
    }

    if ($new_status === 'Approved') {
        execute($conn, "UPDATE appointments SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?", "ii", [$current_user_id, $id]);
    } elseif ($new_status === 'Cancelled') {
        if (empty($reason)) die("Cancellation reason required.");
        execute($conn, "UPDATE appointments SET status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?", "isi", [$current_user_id, $reason, $id]);
    } elseif ($new_status === 'Completed') {
        if (empty($reason)) die("Completion notes required.");
        execute($conn, "UPDATE appointments SET status = 'Completed', completed_by = ?, completed_at = NOW(), completion_reason = ? WHERE id = ?", "isi", [$current_user_id, $reason, $id]);
    }

    header("Location: appointments.php?success=status_updated&appointment_id=$id");
    exit();
}

// Archive / Restore (admin only)
if (isset($_GET['archive']) && $is_admin) {
    $id = (int)$_GET['archive'];
    execute($conn, "UPDATE appointments SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?", "ii", [$current_user_id, $id]);
    header("Location: appointments.php?success=archived&appointment_id=$id");
    exit();
}
if (isset($_GET['restore']) && $is_admin) {
    $id = (int)$_GET['restore'];
    execute($conn, "UPDATE appointments SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?", "i", [$id]);
    header("Location: appointments.php?success=restored&appointment_id=$id");
    exit();
}

$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';

// Sorting
$sort_column = $_GET['sort'] ?? 'schedule';
$sort_order = $_GET['order'] ?? 'desc';
$allowed_columns = ['id', 'patient', 'doctor', 'schedule', 'status'];
if (!in_array($sort_column, $allowed_columns)) $sort_column = 'schedule';
if (!in_array($sort_order, ['asc', 'desc'])) $sort_order = 'desc';

// Build conditions
$conditions = [];
if (!$show_archived) {
    $conditions[] = "a.is_archived = 0";
} else {
    $conditions[] = "a.is_archived = 1";
}
if ($filter === 'today') {
    $conditions[] = "DATE(a.schedule_datetime) = CURDATE()";
}
if (!empty($status_filter) && in_array($status_filter, ['Pending', 'Approved', 'Completed', 'Cancelled'])) {
    $conditions[] = "a.status = '$status_filter'";
}
if (!empty($date_filter) && validateDate($date_filter, 'Y-m-d')) {
    $conditions[] = "DATE(a.schedule_datetime) = '$date_filter'";
}
if ($current_role === 'Doctor') {
    $conditions[] = "a.doctor_id = $current_user_id";
}
$where_clause = 'WHERE ' . implode(' AND ', $conditions);

$total = fetchOne($conn, "SELECT COUNT(*) as total FROM appointments a $where_clause")['total'] ?? 0;
$total_pages = ceil($total / 10);
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * 10;

switch ($sort_column) {
    case 'id':       $order_by = "a.id"; break;
    case 'patient':  $order_by = "p.last_name, p.first_name"; break;
    case 'doctor':   $order_by = "u.full_name"; break;
    case 'schedule': $order_by = "a.schedule_datetime"; break;
    case 'status':   $order_by = "a.status"; break;
    default:         $order_by = "a.schedule_datetime";
}
$order_by .= " " . strtoupper($sort_order);

$query = "
    SELECT 
        a.id, a.schedule_datetime, a.status, a.is_archived,
        p.first_name AS patient_first, p.last_name AS patient_last,
        u.full_name AS doctor_name,
        a.doctor_id
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON a.doctor_id = u.id
    $where_clause
    ORDER BY $order_by
    LIMIT $offset, 10
";
$rows = fetchAll($conn, $query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Appointments</title>
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

  .status{
    display:inline-block;
    padding:6px 10px;
    border-radius:16px;
    font-weight:600;
    font-size:13px;
    cursor:pointer;
    transition: filter 0.2s;
  }
  .status:hover{ filter: brightness(0.95); }
  .status.editable{ cursor:pointer; }
  .status.not-editable{ cursor:default; opacity:0.7; }
  .pending{ background:#fff8e1; color:#8a6d00; }
  .approved{ background:#e8f4ff; color:#1e6b8a; }
  .completed{ background:#d1fae5; color:#065f46; }
  .cancelled{ background:#fee2e2; color:#b91c1c; }
  .archived{ background:#95a5a6; color:white; }

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
    max-width:500px;
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
  .radio-group{
    margin:15px 0;
  }
  .radio-group label{
    display:block;
    margin-bottom:8px;
    font-weight:500;
  }
  .radio-group input[type="radio"]{
    margin-right:8px;
  }
  .reason-field{
    width:100%;
    padding:10px;
    border:1px solid #ccc;
    border-radius:8px;
    margin-top:10px;
    display:none;
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
        <h1>Appointments <?= $show_archived ? '(Archived)' : '' ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Manage patient appointments</p>
      </div>
      <div class="top-actions">
        <?php if ($show_archived): ?>
          <a href="appointments.php" class="btn btn-outline">View Active</a>
        <?php else: ?>
          <a href="appointment_form.php" class="btn">+ New Appointment</a>
          <?php if ($is_admin): ?>
            <a href="appointments.php?show=archived" class="btn" style="background:#6c757d;">Archived</a>
          <?php endif; ?>
        <?php endif; ?>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div id="toast" class="toast-container"></div>

    <?php if (!empty($filter) || !empty($status_filter) || !empty($date_filter)): ?>
      <div class="filter-badge">
        Filter: 
        <?php 
          $bits = [];
          if ($filter === 'today') $bits[] = "Today";
          if (!empty($status_filter)) $bits[] = "Status: $status_filter";
          if (!empty($date_filter)) $bits[] = "Date: ".date('M j, Y', strtotime($date_filter));
          echo implode(' • ', $bits);
        ?>
        <a href="appointments.php" class="close-btn">&times;</a>
      </div>
    <?php endif; ?>

    <!-- Filter controls -->
    <div class="filter-controls">
      <select id="statusFilter" class="filter-select" onchange="applyFilter()">
        <option value="">All Status</option>
        <option value="Pending" <?= $status_filter=='Pending'?'selected':'' ?>>Pending</option>
        <option value="Approved" <?= $status_filter=='Approved'?'selected':'' ?>>Approved</option>
        <option value="Completed" <?= $status_filter=='Completed'?'selected':'' ?>>Completed</option>
        <option value="Cancelled" <?= $status_filter=='Cancelled'?'selected':'' ?>>Cancelled</option>
      </select>
      <input type="date" id="dateFilter" class="filter-select" value="<?= htmlspecialchars($date_filter) ?>" onchange="applyFilter()">
      <button onclick="applyFilter()" class="btn" style="background:var(--navy-700);">Apply</button>
      <a href="appointments.php" class="btn btn-outline">Clear</a>
    </div>

    <div class="table-wrap">
      <div class="table-controls">
        <input type="text" id="searchInput" class="search-input" placeholder="Search patient or doctor...">
        <div class="muted">Showing <span id="rowCount"><?= count($rows) ?></span> of <?= number_format($total) ?></div>
      </div>

      <table id="appointmentsTable">
        <thead>
          <tr>
            <th class="sortable-header <?= $sort_column=='id'?$sort_order:'' ?>" onclick="sortTable('id','<?= $sort_column=='id' && $sort_order=='asc'?'desc':'asc' ?>')">ID</th>
            <th class="sortable-header <?= $sort_column=='patient'?$sort_order:'' ?>" onclick="sortTable('patient','<?= $sort_column=='patient' && $sort_order=='asc'?'desc':'asc' ?>')">Patient</th>
            <th class="sortable-header <?= $sort_column=='doctor'?$sort_order:'' ?>" onclick="sortTable('doctor','<?= $sort_column=='doctor' && $sort_order=='asc'?'desc':'asc' ?>')">Doctor</th>
            <th class="sortable-header <?= $sort_column=='schedule'?$sort_order:'' ?>" onclick="sortTable('schedule','<?= $sort_column=='schedule' && $sort_order=='asc'?'desc':'asc' ?>')">Schedule</th>
            <th class="sortable-header <?= $sort_column=='status'?$sort_order:'' ?>" onclick="sortTable('status','<?= $sort_column=='status' && $sort_order=='asc'?'desc':'asc' ?>')">Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) > 0): ?>
            <?php foreach($rows as $r): ?>
              <?php
              $status_class = [
                  'Pending' => 'pending',
                  'Approved' => 'approved',
                  'Completed' => 'completed',
                  'Cancelled' => 'cancelled'
              ][$r['status']] ?? 'pending';
              $schedule = date('M j, Y g:i A', strtotime($r['schedule_datetime']));
              $patient_name = trim(($r['patient_first']??'').' '.($r['patient_last']??''));
              $row_bg = $r['is_archived'] ? 'style="background:rgba(149,165,166,0.1);"' : '';

              $can_edit_status = false;
              if (!$r['is_archived']) {
                  if ($current_role === 'Admin') {
                      $can_edit_status = true;
                  } elseif ($current_role === 'Doctor' && $r['doctor_id'] == $current_user_id) {
                      $can_edit_status = true;
                  } elseif ($current_role === 'Nurse') {
                      $can_edit_status = true;
                  } elseif ($current_role === 'Staff') {
                      $can_edit_status = true;
                  }
              }
              $status_clickable_class = $can_edit_status ? 'editable' : 'not-editable';
              ?>
              <tr data-id="<?= $r['id'] ?>" data-doctor="<?= $r['doctor_id'] ?>" data-status="<?= $r['status'] ?>" <?= $row_bg ?>>
                <td>#<?= $r['id'] ?></td>
                <td><?= htmlspecialchars($patient_name) ?></td>
                <td><?= htmlspecialchars($r['doctor_name'] ?? '') ?></td>
                <td><?= $schedule ?></td>
                <td>
                  <span class="status <?= $status_class ?> <?= $status_clickable_class ?>" 
                        data-id="<?= $r['id'] ?>"
                        data-status="<?= $r['status'] ?>"
                        data-doctor="<?= $r['doctor_id'] ?>"
                        data-role="<?= htmlspecialchars($current_role) ?>"
                        onclick="<?= $can_edit_status ? 'openStatusModal(this)' : '' ?>">
                    <?= $r['status'] ?>
                    <?php if($r['is_archived']): ?> (Archived)<?php endif; ?>
                  </span>
                </td>
                <td style="white-space:nowrap;">
                  <a href="appointment_view.php?id=<?= $r['id'] ?>" class="action-btn btn-chart">Chart</a>
                  <?php if (!$r['is_archived'] && $current_role !== 'Doctor'): ?>
                    <a href="appointment_form.php?id=<?= $r['id'] ?>" class="action-btn btn-update">Update</a>
                  <?php endif; ?>
                  <?php if ($is_admin): ?>
                    <?php if (!$r['is_archived']): ?>
                      <a href="appointments.php?archive=<?= $r['id'] ?>" class="action-btn btn-archive" onclick="return confirm('Archive appointment for &quot;<?= htmlspecialchars($patient_name, ENT_QUOTES) ?>&quot;?')">Archive</a>
                    <?php else: ?>
                      <a href="appointments.php?restore=<?= $r['id'] ?>" class="action-btn btn-archive" onclick="return confirm('Restore appointment for &quot;<?= htmlspecialchars($patient_name, ENT_QUOTES) ?>&quot;?')">Restore</a>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" style="text-align:center; padding:30px;">No appointments found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&sort=<?= $sort_column ?>&order=<?= $sort_order ?><?= $show_archived?'&show=archived':'' ?><?= !empty($filter)?"&filter=$filter":'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($date_filter)?"&date=$date_filter":'' ?>">&laquo;</a>
          <?php else: ?>
            <span class="disabled">&laquo;</span>
          <?php endif; ?>
          <?php for($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
            <?php if($i==$page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?>&sort=<?= $sort_column ?>&order=<?= $sort_order ?><?= $show_archived?'&show=archived':'' ?><?= !empty($filter)?"&filter=$filter":'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($date_filter)?"&date=$date_filter":'' ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&sort=<?= $sort_column ?>&order=<?= $sort_order ?><?= $show_archived?'&show=archived':'' ?><?= !empty($filter)?"&filter=$filter":'' ?><?= !empty($status_filter)?"&status=$status_filter":'' ?><?= !empty($date_filter)?"&date=$date_filter":'' ?>">&raquo;</a>
          <?php else: ?>
            <span class="disabled">&raquo;</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Status Modal -->
<div id="statusModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Update Appointment Status</h3>
      <button class="modal-close" onclick="closeStatusModal()">&times;</button>
    </div>
    <form method="POST" id="statusForm">
      <div class="modal-body">
        <input type="hidden" name="appointment_id" id="modal_appointment_id" value="">
        <input type="hidden" name="update_status" value="1">
        <div class="radio-group" id="statusOptions"></div>
        <div id="reasonContainer" style="display:none;">
          <label for="reason">Reason / Notes:</label>
          <textarea name="reason" id="reason" class="reason-field" rows="3" placeholder="Enter reason..." style="display:block; width:100%;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" style="background:#6c757d;" onclick="closeStatusModal()">Close</button>
        <button type="submit" class="btn" style="background:var(--navy-700);">Update Status</button>
      </div>
    </form>
  </div>
</div>

<script>
// ================== GLOBAL VARIABLES ==================
const currentUserId = <?= json_encode($current_user_id) ?>;

// ================== FILTER ==================
function applyFilter() {
    const status = document.getElementById('statusFilter').value;
    const date = document.getElementById('dateFilter').value;
    let url = 'appointments.php?';
    const params = [];
    if (status) params.push('status=' + encodeURIComponent(status));
    if (date) params.push('date=' + encodeURIComponent(date));
    <?php if ($show_archived): ?>params.push('show=archived');<?php endif; ?>
    window.location.href = url + params.join('&');
}

// ================== SORTING ==================
function sortTable(column, order) {
    const url = new URL(window.location.href);
    url.searchParams.set('sort', column);
    url.searchParams.set('order', order);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// ================== STATUS MODAL ==================
function openStatusModal(element) {
    const id = element.getAttribute('data-id');
    const currentStatus = element.getAttribute('data-status');
    const doctorId = parseInt(element.getAttribute('data-doctor') || '0');
    const userRole = element.getAttribute('data-role');

    const modal = document.getElementById('statusModal');
    const optionsDiv = document.getElementById('statusOptions');
    const reasonContainer = document.getElementById('reasonContainer');
    const reasonInput = document.getElementById('reason');
    const submitBtn = document.querySelector('#statusModal .modal-footer button[type="submit"]');

    if (!modal) {
        console.error('Modal not found!');
        return;
    }

    document.getElementById('modal_appointment_id').value = id;
    optionsDiv.innerHTML = '';
    reasonContainer.style.display = 'none';
    reasonInput.value = '';
    reasonInput.required = false;
    submitBtn.style.display = 'inline-block';

    let actions = [];

    if (currentStatus === 'Pending') {
        if (userRole === 'Admin' || (userRole === 'Doctor' && doctorId === currentUserId)) {
            actions.push({value: 'Approved', label: 'Approve'});
        }
        if (userRole !== 'Inventory' && userRole !== 'Billing' && userRole !== 'SocialWorker') {
            actions.push({value: 'Cancelled', label: 'Cancel'});
        }
    } else if (currentStatus === 'Approved') {
        if (userRole === 'Admin' || userRole === 'Doctor' || userRole === 'Nurse') {
            actions.push({value: 'Completed', label: 'Complete'});
        }
        if (userRole !== 'Inventory' && userRole !== 'Billing' && userRole !== 'SocialWorker') {
            actions.push({value: 'Cancelled', label: 'Cancel'});
        }
    } else {
        optionsDiv.innerHTML = '<p>No further status changes possible.</p>';
        submitBtn.style.display = 'none';
        modal.style.display = 'flex';
        return;
    }

    if (actions.length === 0) {
        optionsDiv.innerHTML = '<p>No actions available for your role.</p>';
        submitBtn.style.display = 'none';
        modal.style.display = 'flex';
        return;
    }

    actions.forEach(act => {
        const label = document.createElement('label');
        label.innerHTML = `<input type="radio" name="new_status" value="${act.value}" required> ${act.label}`;
        optionsDiv.appendChild(label);
    });

    document.querySelectorAll('input[name="new_status"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'Cancelled' || this.value === 'Completed') {
                reasonContainer.style.display = 'block';
                reasonInput.required = true;
            } else {
                reasonContainer.style.display = 'none';
                reasonInput.required = false;
            }
        });
    });

    modal.style.display = 'flex';
}

function closeStatusModal() {
    document.getElementById('statusModal').style.display = 'none';
}

window.onclick = function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
};

// ================== SEARCH ==================
const searchInput = document.getElementById('searchInput');
const tbody = document.querySelector('#appointmentsTable tbody');
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

// ================== TOAST ==================
function showToast(msg, type = 'success') {
    const container = document.getElementById('toast');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.innerHTML = `<div style="display:flex; justify-content:space-between;">${msg}<button onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white;">&times;</button></div>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

// ================== PAGE LOAD ==================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Script loaded, functions defined.');
    const success = <?= json_encode($success) ?>;
    const aId = <?= json_encode($appointment_id) ?>;
    if (success) {
        const msgs = {
            added: 'Appointment added.',
            updated: 'Appointment updated.',
            archived: 'Appointment archived.',
            restored: 'Appointment restored.',
            status_updated: 'Status updated successfully.',
            error: 'An error occurred.'
        };
        showToast(msgs[success] || 'Done.', success === 'error' ? 'error' : 'success');
        if (aId && ['added','updated','archived','restored','status_updated'].includes(success)) {
            setTimeout(() => {
                const row = document.querySelector(`tr[data-id="${aId}"]`);
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