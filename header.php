<?php
// Start output buffering at the very beginning
ob_start();

session_start();
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
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

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Now output the HTML - all PHP logic is done above
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Tebowcure Hospital System</title>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">

<!-- Bootstrap for form styling -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
  :root{
    --bg: #eef3f7;
    --panel: #ffffff;
    --muted: #9aa6a6;
    --green-700: #0e8f6f;
    --accent: #0b6b57;
    --sidebar:#0b5b4f;
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

  /* SIDEBAR - Updated to match appointments.php exactly */
  .sidebar {
    width:230px;
    background:linear-gradient(180deg,var(--sidebar),#073934 120%);
    color:#eafaf3;
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
    background: linear-gradient(90deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
  }
  .menu-item.active{ 
    background: linear-gradient(90deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)); 
    border-left:4px solid #b7ffdd; 
    padding-left:5px; 
  }
  .menu-item svg, .menu-item .icon{
    width:16px;height:16px;opacity:.95;
    fill: white; /* White icons */
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
    border-left: 3px solid #b7ffdd;
    font-size: 13px;
  }

  .user-info h4 {
    margin: 0 0 4px 0;
    font-size: 13px;
    color: #b7ffdd;
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
    background:var(--green-700);
    color:#fff;
    padding:9px 14px;
    border-radius:10px;
    border:0;
    font-weight:600;
    cursor:pointer;
    text-decoration: none;
    display: inline-block;
    transition: background 0.2s ease;
  }
  .btn:hover {
    background:var(--accent);
  }
  .btn-warning {
    background: #f39c12;
    color: #fff;
  }
  .btn-danger {
    background: #e74c3c;
    color: #fff;
  }
  .date-pill{
    background:var(--panel);
    padding:8px 12px;
    border-radius:999px;
    box-shadow:0 4px 14px rgba(16,24,40,0.06);
    font-size:13px;
    white-space: nowrap;
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

  /* Toast color classes */
  .alert-success {
    background: #27ae60; /* Green */
    border-left: 4px solid #1e8449;
  }

  .alert-error, .alert-danger {
    background: #e74c3c; /* Red */
    border-left: 4px solid #c0392b;
  }

  .alert-warning {
    background: #f39c12; /* Orange/Yellow */
    border-left: 4px solid #d68910;
  }

  .alert-info {
    background: #3498db; /* Blue */
    border-left: 4px solid #2980b9;
  }

  .alert-primary {
    background: var(--green-700); /* Your theme green */
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
  .section-title{font-size:18px;margin:14px 0 8px 0;color:#2b3b3b}
  .table-wrap{background:var(--panel);padding:18px;border-radius:12px;box-shadow:var(--card-shadow);overflow:auto}
  .table-controls{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
  .search-input{padding:10px 12px;border-radius:10px;border:1px solid #e6eef0;background:transparent;min-width:220px}
  table{width:100%;border-collapse:collapse;min-width:800px}
  thead th{background:#f8fbfd;padding:14px;text-align:left;color:#6b7280;font-weight:600}
  td, th{padding:14px;border-bottom:1px solid #f0f3f4;color:#233}
  
  /* Status badges */
  .status{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .pending{background:#fff4ce;color:#8a6d00}
  .approved{background:#dff7e8;color:#1f7b3b}
  .completed{background:#e0f2fe;color:#0369a1}
  .cancelled{background:#fce6e8;color:#b02b2b}

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
    color: var(--green-700);
    font-weight: 500;
    transition: all 0.2s ease;
  }
  .pagination a:hover {
    background: rgba(14, 143, 111, 0.1);
  }
  .pagination .current {
    background: var(--green-700);
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
  .role-admin { background: #e74c3c; color: white; }
  .role-doctor { background: #3498db; color: white; }
  .role-nurse { background: #9b59b6; color: white; }
  .role-staff { background: #95a5a6; color: white; }
  .role-inventory { background: #f39c12; color: white; }
  .role-billing { background: #27ae60; color: white; }
  .role-socialworker { background: #34495e; color: white; }

  /* Modal */
  .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 20px 25px rgba(0,0,0,0.1);
  }
  .modal-header {
    background: var(--green-700);
    color: white;
    border-radius: 12px 12px 0 0;
  }

  /* Footer shadow */
  .footer-shadow{height:48px;background:linear-gradient(180deg,transparent,rgba(3,7,18,0.04));pointer-events:none;position:fixed;left:0;right:0;bottom:0}

  /* Content panels */
  .panel{
    background:var(--panel);
    border-radius:14px;
    padding:20px;
    box-shadow: var(--card-shadow);
    margin-bottom: 20px;
    color: #0f1724;
  }
  .panel h2, .panel h3{
    margin:0 0 15px 0;
    color: #0f1724;
  }
  .panel h2{font-size:18px;}

  /* Table styling */
  .table-container{
    background:var(--panel);
    border-radius:12px;
    box-shadow: var(--card-shadow);
    overflow:hidden;
    margin-bottom:20px;
  }

  /* Form styling */
  .form-group{margin-bottom:15px}
  .form-label{
    display:block;
    margin-bottom:6px;
    font-weight:600;
    color: #0f1724;
  }
  .form-control{
    width:100%;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    background:#ffffff;
    color: #0f1724;
    transition: border 0.2s ease;
  }
  .form-control:focus{
    outline:none;
    border-color:var(--green-700);
    box-shadow:0 0 0 3px rgba(14,143,111,0.1);
  }

  /* Action buttons */
  .action-btns{display:flex;gap:8px;flex-wrap:wrap}
  .btn-sm{
    padding:6px 12px;
    font-size:13px;
    text-decoration:none;
    border-radius:6px;
    border:none;
    cursor:pointer;
    transition:all 0.2s ease;
    color: white;
  }
  .btn-edit{background:#3498db;}
  .btn-delete{background:#e74c3c;}
  .btn-view{background:#95a5a6;}
  .btn-archive{background:#dc3545;}
  .btn-restore{background:#ffc107; color:#212529;}
  .btn-sm:hover{opacity:0.9; color: white;}

  /* Row highlight animation */
  .row-highlight {
    animation: highlightPulse 2s ease;
  }
  
  @keyframes highlightPulse {
    0% { background-color: rgba(33, 150, 243, 0.1); }
    50% { background-color: rgba(33, 150, 243, 0.3); }
    100% { background-color: transparent; }
  }

  /* Utility classes */
  .muted{color:var(--muted);font-size:13px}
  .text-right{text-align:right}
  .text-center{text-align:center}

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
</style>
</head>
<body>
  <!-- Toast Notification Container -->
  <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

  <div class="app">
    <!-- SIDEBAR - Updated with white SVG icons -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
        <a href="dashboard.php" style="display: block;">
          <img src="logo.png" alt="Tebow Cure Logo">
        </a>
      </div>

      <!-- User info like in dashboard -->
      <div class="user-info">
        <h4>Logged as:</h4>
        <p><?php echo htmlspecialchars($current_name); ?><br><strong><?php echo htmlspecialchars($current_role); ?></strong></p>
      </div>

      <nav class="menu" id="mainMenu">
        <?php foreach($allowed_pages as $page => $label): ?>
          <a href="<?php echo $page; ?>" class="menu-item <?php echo $current_page === $page ? 'active' : ''; ?>">
            <span class="icon">
              <?php 
                $icons = [
                  'dashboard.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>',
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

    <!-- MAIN CONTENT -->
    <div class="main" id="mainContent">
      <div class="topbar">
        <div class="top-left">
          <h1>
            <?php 
              $page_titles = [
                'dashboard.php' => 'Dashboard',
                'patients.php' => 'Patient Management',
                'appointments.php' => 'Appointment Scheduling',
                'surgeries.php' => 'Surgery Management',
                'inventory.php' => 'Inventory Management',
                'billing.php' => 'Billing & Payments',
                'financials.php' => 'Financial Assessment',
                'reports.php' => 'Reports & Analytics',
                'users.php' => 'User Management'
              ];
              echo $page_titles[$current_page] ?? 'Tebow CURE Hospital';
            ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Tebow CURE Children's Hospital Management System</p>
        </div>

        <div class="top-actions">
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Content will be inserted here -->
      <div class="content-wrapper">