<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$allowed_roles = ['Nurse', 'Doctor', 'Admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    die("Access denied.");
}

// Get current user role and name
$current_role = $_SESSION['role'] ?? 'Guest';
$current_name = $_SESSION['name'] ?? 'User';
$current_user_id = $_SESSION['user_id'];

// Define role permissions for navigation (same as patients.php)
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

// Handle start and complete actions
if (isset($_GET['start']) && is_numeric($_GET['start'])) {
    $triage_id = (int)$_GET['start'];
    $user_id = $_SESSION['user_id'];
    execute($conn, "UPDATE triage SET status = 'in_consultation', started_by = ?, started_at = NOW() WHERE id = ?", "ii", [$user_id, $triage_id]);
    header("Location: triage_queue.php");
    exit();
}
if (isset($_GET['complete']) && is_numeric($_GET['complete'])) {
    $triage_id = (int)$_GET['complete'];
    $user_id = $_SESSION['user_id'];
    execute($conn, "UPDATE triage SET status = 'completed', completed_by = ?, completed_at = NOW() WHERE id = ?", "ii", [$user_id, $triage_id]);
    header("Location: triage_queue.php");
    exit();
}

// Fetch waiting patients (all)
$waiting = fetchAll($conn, "
    SELECT t.*, p.first_name, p.last_name, p.patient_code,
           TIMESTAMPDIFF(MINUTE, t.assessed_at, NOW()) as wait_minutes
    FROM triage t
    JOIN patients p ON t.patient_id = p.id
    WHERE t.status = 'waiting'
    ORDER BY t.severity DESC, t.assessed_at ASC
");

// Fetch in consultation patients – filter by doctor if role is Doctor
if ($current_role === 'Doctor') {
    $in_consult = fetchAll($conn, "
        SELECT t.*, p.first_name, p.last_name, p.patient_code, u.full_name AS doctor_name
        FROM triage t
        JOIN patients p ON t.patient_id = p.id
        LEFT JOIN users u ON t.started_by = u.id
        WHERE t.status = 'in_consultation' AND t.started_by = ?
        ORDER BY t.assessed_at ASC
    ", "i", [$current_user_id]);
} else {
    $in_consult = fetchAll($conn, "
        SELECT t.*, p.first_name, p.last_name, p.patient_code, u.full_name AS doctor_name
        FROM triage t
        JOIN patients p ON t.patient_id = p.id
        LEFT JOIN users u ON t.started_by = u.id
        WHERE t.status = 'in_consultation'
        ORDER BY t.assessed_at ASC
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Triage Queue</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  /* Same CSS as patients.php – include all styles from before */
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

  /* SIDEBAR – same as patients.php */
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
    cursor:pointer;
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

  /* Queue sections */
  .queue-section{
    background:var(--panel);
    border-radius:12px;
    padding:20px;
    margin-bottom:30px;
    box-shadow:var(--card-shadow);
  }
  table{ width:100%; border-collapse:collapse; }
  th{
    background:#f8fbfd;
    padding:12px;
    text-align:left;
    color:#6b7280;
    border-bottom:2px solid #e6eef0;
  }
  td{ padding:12px; border-bottom:1px solid #f0f3f4; }
  .severity-badge{
    display:inline-block; padding:4px 10px; border-radius:20px; font-weight:600; font-size:12px;
  }
  .sev1{ background:#d1fae5; color:#065f46; }
  .sev2{ background:#fed7aa; color:#92400e; }
  .sev3{ background:#fde68a; color:#92400e; }
  .sev4{ background:#fecaca; color:#b91c1c; }
  .sev5{ background:#fee2e2; color:#991b1b; font-weight:700; }
  .action-btn{
    padding:6px 12px; border-radius:6px; text-decoration:none; font-size:13px; margin-right:5px; border:none; cursor:pointer;
  }
  .btn-success{ background:#10b981; color:white; }
  .btn-warning{ background:#f59e0b; color:white; }
  .btn-primary{ background:#3182ce; color:white; }

  /* Toast */
  .toast-container{ position:fixed; top:20px; right:20px; z-index:9999; max-width:350px; }
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

  @media (max-width:780px){
    .sidebar{ left:-320px; }
    .sidebar.open{ left:0; }
    .main{ margin-left:0; padding:12px; }
  }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR (same as patients.php) -->
  <aside class="sidebar" id="sidebar">
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
        <h1>Triage Queue</h1>
        <p>Manage patient flow – Gig Oca Robles Seamen's Hospital Davao</p>
      </div>
      <div class="top-actions">
        <a href="patients.php" class="btn btn-outline">← Back to Patients</a>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
      <div style="background:#d1fae5; padding:10px; border-radius:8px; margin-bottom:20px;">
        Patient added to queue with number <?= htmlspecialchars($_GET['queue'] ?? '') ?>
      </div>
    <?php endif; ?>

    <!-- Waiting Patients -->
    <div class="queue-section">
      <h3>Waiting (<?= count($waiting) ?>)</h3>
      <?php if (count($waiting) > 0): ?>
        <table>
          <thead>
            <tr>
              <th>Queue #</th>
              <th>Patient</th>
              <th>Severity</th>
              <th>Chief Complaint</th>
              <th>Wait (min)</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($waiting as $w): ?>
              <tr>
                <td><?= htmlspecialchars($w['queue_number']) ?></td>
                <td><?= htmlspecialchars($w['first_name'] . ' ' . $w['last_name']) ?></td>
                <td><span class="severity-badge sev<?= $w['severity'] ?>"><?= $w['severity'] ?></span></td>
                <td><?= htmlspecialchars($w['chief_complaint']) ?></td>
                <td><?= $w['wait_minutes'] ?></td>
                <td>
                  <?php if ($_SESSION['role'] === 'Doctor' || $_SESSION['role'] === 'Admin'): ?>
                    <a href="triage_queue.php?start=<?= $w['id'] ?>" class="action-btn btn-success" onclick="return confirm('Start consultation?')">Start</a>
                  <?php endif; ?>
                  <a href="patient_view.php?id=<?= $w['patient_id'] ?>" class="action-btn btn-primary">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p>No patients waiting.</p>
      <?php endif; ?>
    </div>

    <!-- In Consultation (filtered if doctor) -->
    <?php if (count($in_consult) > 0): ?>
    <div class="queue-section">
      <h3>In Consultation <?= ($current_role === 'Doctor') ? '(Your patients)' : '' ?></h3>
      <table>
        <thead>
          <tr>
            <th>Queue #</th>
            <th>Patient</th>
            <th>Severity</th>
            <th>Chief Complaint</th>
            <th>Doctor</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($in_consult as $c): ?>
            <tr>
              <td><?= htmlspecialchars($c['queue_number']) ?></td>
              <td><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
              <td><span class="severity-badge sev<?= $c['severity'] ?>"><?= $c['severity'] ?></span></td>
              <td><?= htmlspecialchars($c['chief_complaint']) ?></td>
              <td><?= htmlspecialchars($c['doctor_name'] ?? 'Not assigned') ?></td>
              <td>
                <?php if ($_SESSION['role'] === 'Doctor' || $_SESSION['role'] === 'Admin'): ?>
                  <a href="triage_queue.php?complete=<?= $c['id'] ?>" class="action-btn btn-warning" onclick="return confirm('Mark as completed?')">Complete</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>