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

// Role permissions (with Triage Queue)
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

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: surgeries.php?success=error&message=Invalid surgery ID");
    exit;
}

$id = (int)$_GET['id'];
$is_admin = ($current_role === 'Admin');

// Fetch surgery with all details including tracking users
$surgery = fetchOne($conn, "
    SELECT 
        s.*,
        p.first_name, p.last_name, p.birth_date, p.phone, p.address,
        u.full_name AS doctor_name,
        creator.full_name AS created_by_name,
        approver.full_name AS approved_by_name,
        canceller.full_name AS cancelled_by_name,
        completer.full_name AS completed_by_name,
        arch_user.full_name AS archived_by_name
    FROM surgeries s
    INNER JOIN patients p ON s.patient_id = p.id
    INNER JOIN users u ON s.doctor_id = u.id
    LEFT JOIN users creator ON s.created_by = creator.id
    LEFT JOIN users approver ON s.approved_by = approver.id
    LEFT JOIN users canceller ON s.cancelled_by = canceller.id
    LEFT JOIN users completer ON s.completed_by = completer.id
    LEFT JOIN users arch_user ON s.archived_by = arch_user.id
    WHERE s.id = ?
", "i", [$id]);

if (!$surgery) {
    header("Location: surgeries.php?success=error&message=Surgery+not+found");
    exit;
}

$table_check = fetchOne($conn, "SHOW COLUMNS FROM surgeries LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;
$is_archived = $has_archive_columns && !empty($surgery['is_archived']);

function formatDate($datetime) {
    return $datetime ? date('F j, Y g:i A', strtotime($datetime)) : 'N/A';
}

$patient_age = $surgery['birth_date'] ? floor((time() - strtotime($surgery['birth_date'])) / 31556926) : 'N/A';
$schedule_formatted = date('F j, Y', strtotime($surgery['schedule_date']));

// Fetch inventory items used
$inventory_items = fetchAll($conn, "
    SELECT si.quantity_used, ii.item_name, ii.category, ii.unit
    FROM surgery_inventory si
    INNER JOIN inventory_items ii ON si.item_id = ii.id
    WHERE si.surgery_id = ?
    ORDER BY ii.category, ii.item_name
", "i", [$id]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - View Surgery</title>
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
  .btn-secondary { background:#6c757d; color:#fff; }
  .btn-secondary:hover { background:#5a6268; transform:translateY(-1px); }
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

  /* Surgery Details Card */
  .surgery-card{
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    border:1px solid #f0f4f8;
  }
  .surgery-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
    padding-bottom:15px;
    border-bottom:1px solid #eef2f7;
  }
  .surgery-header h3{
    margin:0;
    color:#1e3a5f;
    font-size:18px;
  }
  .patient-info-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
    gap:20px;
    margin-bottom:25px;
  }
  .info-group{
    background:#f8fbfd;
    padding:15px;
    border-radius:8px;
    border-left:3px solid var(--navy-700);
  }
  .info-label{
    font-size:12px;
    color:var(--muted);
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.5px;
    margin-bottom:5px;
  }
  .info-value{
    font-size:15px;
    color:#2b3b3b;
    font-weight:500;
    line-height:1.4;
  }
  .status{
    display:inline-block;
    padding:6px 10px;
    border-radius:16px;
    font-weight:600;
    font-size:13px;
  }
  .scheduled{ background:#fff8e1; color:#8a6d00; }
  .approved{ background:#e8f4ff; color:#1e6b8a; }
  .completed{ background:#d1fae5; color:#065f46; }
  .cancelled{ background:#fee2e2; color:#b91c1c; }
  .archived{ background:#95a5a6; color:white; }
  .active{ background:#dff7e8; color:#1f7b3b; }

  .section-title{
    font-size:18px;
    color:#1e3a5f;
    margin:25px 0 15px 0;
    padding-bottom:10px;
    border-bottom:2px solid #eef2f7;
  }

  .alert{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:20px;
    color:white;
    font-weight:500;
  }
  .alert-warning{ background:#f59e0b; }
  .alert-error{ background:#e53e3e; border-left:4px solid #c53030; }

  .inventory-table{
    width:100%;
    border-collapse:collapse;
    margin-top:15px;
  }
  .inventory-table th{
    background:#f8fbfd;
    padding:12px;
    text-align:left;
    color:#6b7280;
    font-weight:600;
    border-bottom:2px solid #e6eef0;
  }
  .inventory-table td{
    padding:12px;
    border-bottom:1px solid #f0f3f4;
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
        <h1>View Surgery
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Surgery details and tracking information</p>
      </div>
      <div class="top-actions">
        <a href="surgeries.php" class="btn btn-outline">&larr; Back to Surgeries</a>
        <?php if (!$is_archived || $is_admin): ?>
          <a href="surgery_form.php?id=<?= $id ?>" class="btn" style="background:#ed8936;">Update Surgery</a>
        <?php endif; ?>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div id="toast" class="toast-container"></div>

    <div class="surgery-card">
      <div class="surgery-header">
        <h3>Surgery #S-<?= $surgery['id'] ?></h3>
        <div>
          <span class="status <?= strtolower($surgery['status']) ?>"><?= $surgery['status'] ?></span>
          <span class="status <?= $is_archived ? 'archived' : 'active' ?>" style="margin-left:8px;"><?= $is_archived ? 'Archived' : 'Active' ?></span>
        </div>
      </div>

      <?php if ($is_archived && !empty($surgery['archived_at'])): ?>
        <div class="alert alert-warning" style="margin-bottom:20px;">
          <strong>⚠️ This surgery is archived</strong><br>
          Archived on: <?= formatDate($surgery['archived_at']) ?><br>
          <?php if (!empty($surgery['archived_by_name'])): ?>
            Archived by: <?= htmlspecialchars($surgery['archived_by_name']) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="patient-info-grid">
        <div class="info-group">
          <div class="info-label">Patient</div>
          <div class="info-value"><?= htmlspecialchars($surgery['first_name'] . ' ' . $surgery['last_name']) ?></div>
          <div style="font-size:13px; color:#6b7280;">
            DOB: <?= htmlspecialchars($surgery['birth_date'] ?? 'N/A') ?> (<?= $patient_age ?> yrs)<br>
            Phone: <?= htmlspecialchars($surgery['phone'] ?? 'N/A') ?><br>
            Address: <?= nl2br(htmlspecialchars($surgery['address'] ?? 'N/A')) ?>
          </div>
        </div>
        <div class="info-group">
          <div class="info-label">Surgeon</div>
          <div class="info-value"><?= htmlspecialchars($surgery['doctor_name']) ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Surgery Type</div>
          <div class="info-value"><?= htmlspecialchars($surgery['surgery_type']) ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Scheduled Date</div>
          <div class="info-value"><?= htmlspecialchars($schedule_formatted) ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Operating Room</div>
          <div class="info-value"><?= htmlspecialchars($surgery['operating_room'] ?? 'Not specified') ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Created</div>
          <div class="info-value"><?= formatDate($surgery['created_at']) ?></div>
          <div style="font-size:13px; color:#6b7280;">by <?= htmlspecialchars($surgery['created_by_name'] ?? 'N/A') ?></div>
        </div>
      </div>

      <!-- Approval / Cancellation / Completion Details -->
      <?php if ($surgery['status'] == 'Approved' && !empty($surgery['approved_by'])): ?>
      <div class="section-title">Approval Information</div>
      <div class="patient-info-grid" style="margin-bottom:0;">
        <div class="info-group">
          <div class="info-label">Approved by</div>
          <div class="info-value"><?= htmlspecialchars($surgery['approved_by_name'] ?? 'Unknown') ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Approved at</div>
          <div class="info-value"><?= formatDate($surgery['approved_at']) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($surgery['status'] == 'Cancelled' && !empty($surgery['cancelled_by'])): ?>
      <div class="section-title">Cancellation Information</div>
      <div class="patient-info-grid" style="margin-bottom:0;">
        <div class="info-group">
          <div class="info-label">Cancelled by</div>
          <div class="info-value"><?= htmlspecialchars($surgery['cancelled_by_name'] ?? 'Unknown') ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Cancelled at</div>
          <div class="info-value"><?= formatDate($surgery['cancelled_at']) ?></div>
        </div>
        <div class="info-group" style="grid-column:span 2;">
          <div class="info-label">Reason</div>
          <div class="info-value"><?= nl2br(htmlspecialchars($surgery['cancellation_reason'] ?? 'N/A')) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($surgery['status'] == 'Completed' && !empty($surgery['completed_by'])): ?>
      <div class="section-title">Completion Information</div>
      <div class="patient-info-grid" style="margin-bottom:0;">
        <div class="info-group">
          <div class="info-label">Completed by</div>
          <div class="info-value"><?= htmlspecialchars($surgery['completed_by_name'] ?? 'Unknown') ?></div>
        </div>
        <div class="info-group">
          <div class="info-label">Completed at</div>
          <div class="info-value"><?= formatDate($surgery['completed_at']) ?></div>
        </div>
        <div class="info-group" style="grid-column:span 2;">
          <div class="info-label">Notes</div>
          <div class="info-value"><?= nl2br(htmlspecialchars($surgery['completion_reason'] ?? 'N/A')) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Inventory Items -->
      <?php if (count($inventory_items) > 0): ?>
      <div class="section-title">Inventory Items Used</div>
      <table class="inventory-table">
        <thead>
          <tr>
            <th>Item Name</th>
            <th>Category</th>
            <th>Quantity Used</th>
            <th>Unit</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($inventory_items as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['item_name']) ?></td>
              <td><?= htmlspecialchars($item['category']) ?></td>
              <td><?= htmlspecialchars($item['quantity_used']) ?></td>
              <td><?= htmlspecialchars($item['unit']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div class="surgery-header" style="margin-top:30px; border-top:1px solid #eef2f7; padding-top:20px;">
        <h3>Actions</h3>
      </div>
      <div style="display:flex; gap:10px; margin-top:20px; flex-wrap:wrap;">
        <a href="surgeries.php" class="btn" style="background:#6c757d;">Back to List</a>
        <?php if (!$is_archived || $is_admin): ?>
          <a href="surgery_form.php?id=<?= $id ?>" class="btn" style="background:#ed8936;">Update Surgery</a>
        <?php endif; ?>
        <?php if ($is_admin && $has_archive_columns): ?>
          <?php if ($is_archived): ?>
            <a href="surgeries.php?restore=<?= $id ?>" class="btn" style="background:#95a5a6;" onclick="return confirm('Restore this surgery?')">Restore Surgery</a>
          <?php else: ?>
            <a href="surgeries.php?archive=<?= $id ?>" class="btn" style="background:#7f8c8d;" onclick="return confirm('Archive this surgery?')">Archive Surgery</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function showToast(msg, type = 'success') {
  const container = document.getElementById('toast');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `alert-toast alert-${type}`;
  toast.innerHTML = `<div style="display:flex; justify-content:space-between;">${msg}<button onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white;">&times;</button></div>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}

document.addEventListener('DOMContentLoaded', function() {
  const urlParams = new URLSearchParams(window.location.search);
  const success = urlParams.get('success');
  const message = urlParams.get('message');
  if (success) {
    const msg = {
      updated: 'Surgery updated successfully!',
      archived: 'Surgery archived.',
      restored: 'Surgery restored.',
      error: message || 'An error occurred.'
    }[success] || 'Operation completed.';
    showToast(msg, success === 'error' ? 'error' : 'success');
  }
});
</script>
</body>
</html>