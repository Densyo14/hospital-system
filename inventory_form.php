<?php
// Start session and require config
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get current user role and name for sidebar
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
    'MedicalRecordsBilling' => [
        'dashboard.php' => 'Dashboard',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'
    ]
];

// Get allowed pages for current role
$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Initialize variables
$id = $item_name = $category = $quantity = $threshold = $unit = "";
$edit = false;

// Check if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $item = fetchOne($conn, "SELECT * FROM inventory_items WHERE id = ?", "i", [$id]);
    if ($item) {
        $edit = true;
        $item_name = $item['item_name'] ?? '';
        $category = $item['category'] ?? '';
        $quantity = $item['quantity'] ?? 0;
        $threshold = $item['threshold'] ?? 5;
        $unit = $item['unit'] ?? 'pcs';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $item_name = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $threshold = (int)($_POST['threshold'] ?? 5);
    $unit = trim($_POST['unit'] ?? 'pcs');

    // Validation
    $errors = [];

    if (empty($item_name)) {
        $errors[] = "Item name is required.";
    }

    if (empty($category)) {
        $errors[] = "Category is required.";
    }

    if ($quantity < 0) {
        $errors[] = "Quantity cannot be negative.";
    }

    if ($threshold < 0) {
        $errors[] = "Threshold cannot be negative.";
    }

    if (empty($unit)) {
        $errors[] = "Unit is required.";
    }

    if (empty($errors)) {
        if ($id) {
            // Update existing item
            $params = [$item_name, $category, $quantity, $threshold, $unit, $id];
            $types = "ssiiis";
            $result = execute($conn, "UPDATE inventory_items SET item_name=?, category=?, quantity=?, threshold=?, unit=? WHERE id=?", $types, $params);
            $item_id = $id;
        } else {
            // Insert new item
            $params = [$item_name, $category, $quantity, $threshold, $unit];
            $types = "ssiii";
            $result = execute($conn, "INSERT INTO inventory_items (item_name, category, quantity, threshold, unit) VALUES (?,?,?,?,?)", $types, $params);
            $item_id = $conn->insert_id;
        }

        if (!isset($result['error'])) {
            $action = $id ? 'updated' : 'added';
            header("Location: inventory.php?success=$action&action=$action&item_id=$item_id");
            exit();
        } else {
            $error_message = $result['error'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Gig Oca Robles Seamen's Hospital Davao - <?php echo $edit ? "Edit" : "Add"; ?> Inventory Item</title>

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
  .btn-secondary {
    background: #6b7280;
    color: #fff;
  }
  .btn-secondary:hover {
    background: #4b5563;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.2);
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

  /* Form styles */
  .form-container {
    max-width: 700px;
    margin: 0 auto;
  }

  .form-card {
    background: var(--panel);
    padding: 30px;
    border-radius: 14px;
    box-shadow: var(--card-shadow);
    border: 1px solid #f0f4f8;
  }

  .form-title {
    font-size: 22px;
    margin-bottom: 20px;
    color: var(--navy-700);
    font-weight: 700;
  }

  .form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #1e3a5f;
  }

  .required {
    color: #e53e3e;
  }

  .form-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.2s ease;
    background: #fbfdfe;
  }

  .form-input:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
    background: white;
  }

  .form-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-size: 15px;
    background: #fbfdfe;
    cursor: pointer;
  }

  .form-select:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
    background: white;
  }

  .form-text {
    margin-top: 6px;
    font-size: 13px;
    color: var(--muted);
    line-height: 1.4;
  }

  .form-help {
    background: #f8fbfd;
    padding: 12px;
    border-radius: 8px;
    border-left: 3px solid var(--light-blue);
    margin-top: 10px;
    font-size: 13px;
    color: #4a5568;
  }

  .form-actions {
    display: flex;
    gap: 12px;
    margin-top: 25px;
  }

  .btn-primary {
    background: var(--navy-700);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
  }

  .btn-primary:hover {
    background: var(--accent);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
  }

  .btn-secondary {
    background: #6b7280;
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
  }

  .btn-secondary:hover {
    background: #4b5563;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(107, 114, 128, 0.2);
  }

  /* Form spacing */
  .form-group {
    margin-bottom: 20px;
  }

  /* Category color indicators */
  .category-option {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .category-color {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
  }

  .category-implant {
    background: #4299e1;
  }

  .category-medicine {
    background: #48bb78;
  }

  .category-suture {
    background: #ed8936;
  }

  .category-equipment {
    background: #9f7aea;
  }

  .category-general {
    background: #a0aec0;
  }

  /* Quantity indicator */
  .quantity-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 5px;
  }

  .indicator-bar {
    flex: 1;
    height: 6px;
    background: #e2e8f0;
    border-radius: 3px;
    overflow: hidden;
  }

  .indicator-fill {
    height: 100%;
    background: #48bb78;
    border-radius: 3px;
    transition: width 0.3s ease;
  }

  .indicator-low {
    background: #e53e3e;
  }

  .indicator-warning {
    background: #ed8936;
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
  .role-medicalrecordsbilling { background: #34495e; color: white; }

  /* Footer shadow */
  .footer-shadow{height:48px;background:linear-gradient(180deg,transparent,rgba(3,7,18,0.04));pointer-events:none;position:fixed;left:0;right:0;bottom:0}

  /* Responsive */
  @media (max-width:780px){
    .sidebar{position:fixed;left:-320px;transform:translateX(0)}
    .sidebar.open{left:0;transform:translateX(0)}
    .main{margin-left:0;padding:12px}
    .sidebar.collapsed{width:230px}
    .topbar{flex-direction:column;align-items:flex-start}
    .top-actions{width:100%;justify-content:space-between}
    .form-card {
      padding: 20px;
    }
    .form-actions {
      flex-direction: column;
    }
  }

  /* small niceties */
  a{color:inherit; text-decoration: none;}
  a:hover{color: var(--light-blue);}
  .muted{color:var(--muted);font-size:13px}
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
          <h1><?php echo $edit ? "Edit Inventory Item" : "Add Inventory Item"; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p><?php echo $edit ? "Update item information" : "Add new inventory item to the system"; ?></p>
        </div>

        <div class="top-actions">
          <a href="inventory.php" class="btn-secondary">← Back to Inventory</a>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- Error messages -->
      <?php if (isset($errors) && !empty($errors)): ?>
        <?php foreach($errors as $error): ?>
          <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (isset($error_message)): ?>
        <div class="alert alert-error">Error: <?php echo htmlspecialchars($error_message); ?></div>
      <?php endif; ?>

      <!-- Form -->
      <div class="form-container">
        <div class="form-card">
          <div class="form-title"><?php echo $edit ? "Edit Inventory Item Details" : "Add New Inventory Item"; ?></div>
          
          <form method="POST" class="inventory-form">
            <input type="hidden" name="id" value="<?php echo h($id); ?>">

            <div class="form-group">
              <label class="form-label">Item Name <span class="required">*</span></label>
              <input type="text" name="item_name" class="form-input" value="<?php echo h($item_name); ?>" required>
            </div>

            <div class="form-group">
              <label class="form-label">Category <span class="required">*</span></label>
              <select name="category" id="category" class="form-select" required>
                <option value="">-- Select Category --</option>
                <option value="Implant" <?php if($category=='Implant') echo 'selected'; ?>>
                  <span class="category-option">
                    <span class="category-color category-implant"></span>
                    Implant
                  </span>
                </option>
                <option value="Medicine" <?php if($category=='Medicine') echo 'selected'; ?>>
                  <span class="category-option">
                    <span class="category-color category-medicine"></span>
                    Medicine
                  </span>
                </option>
                <option value="Suture" <?php if($category=='Suture') echo 'selected'; ?>>
                  <span class="category-option">
                    <span class="category-color category-suture"></span>
                    Suture
                  </span>
                </option>
                <option value="Equipment" <?php if($category=='Equipment') echo 'selected'; ?>>
                  <span class="category-option">
                    <span class="category-color category-equipment"></span>
                    Equipment
                  </span>
                </option>
                <option value="General" <?php if($category=='General') echo 'selected'; ?>>
                  <span class="category-option">
                    <span class="category-color category-general"></span>
                    General
                  </span>
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Quantity <span class="required">*</span></label>
              <input type="number" name="quantity" id="quantity" class="form-input" min="0" value="<?php echo h($quantity); ?>" required>
              <div class="quantity-indicator" id="quantityIndicator">
                <div class="indicator-bar">
                  <div class="indicator-fill" id="quantityFill"></div>
                </div>
                <span id="quantityText"><?php echo h($quantity); ?> units</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Low Stock Threshold <span class="required">*</span></label>
              <input type="number" name="threshold" id="threshold" class="form-input" min="0" value="<?php echo h($threshold); ?>" required>
              <div class="form-text">System will alert when quantity falls below this number.</div>
            </div>

            <div class="form-group">
              <label class="form-label">Unit <span class="required">*</span></label>
              <input type="text" name="unit" class="form-input" value="<?php echo h($unit); ?>" required>
              <div class="form-text">e.g., pcs, boxes, bottles, packs, units</div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn-primary"><?php echo $edit ? "Update Item" : "Add Item"; ?></button>
              <a href="inventory.php" class="btn-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <script>
    // Update quantity indicator
    function updateQuantityIndicator() {
        const quantity = parseInt(document.getElementById('quantity').value) || 0;
        const threshold = parseInt(document.getElementById('threshold').value) || 5;
        const fill = document.getElementById('quantityFill');
        const text = document.getElementById('quantityText');
        
        // Calculate percentage (cap at 100%)
        const maxQuantity = Math.max(quantity, threshold * 2, 100);
        const percentage = Math.min((quantity / maxQuantity) * 100, 100);
        
        // Update fill width
        fill.style.width = percentage + '%';
        
        // Update text
        text.textContent = quantity + ' units';
        
        // Update color based on threshold
        if (quantity === 0) {
            fill.className = 'indicator-fill indicator-low';
        } else if (quantity <= threshold) {
            fill.className = 'indicator-fill indicator-warning';
        } else {
            fill.className = 'indicator-fill';
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Quantity indicator
        const quantityInput = document.getElementById('quantity');
        const thresholdInput = document.getElementById('threshold');
        
        if (quantityInput && thresholdInput) {
            // Initial update
            updateQuantityIndicator();
            
            // Update on input
            quantityInput.addEventListener('input', updateQuantityIndicator);
            thresholdInput.addEventListener('input', updateQuantityIndicator);
        }

        // Form validation
        const form = document.querySelector('.inventory-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const quantity = parseInt(document.getElementById('quantity').value) || 0;
                const threshold = parseInt(document.getElementById('threshold').value) || 0;
                
                if (quantity < 0) {
                    e.preventDefault();
                    alert('Quantity cannot be negative.');
                    document.getElementById('quantity').focus();
                }
                
                if (threshold < 0) {
                    e.preventDefault();
                    alert('Threshold cannot be negative.');
                    document.getElementById('threshold').focus();
                }
            });
        }

        // Show toast if there are success/error messages from inventory.php
        const urlParams = new URLSearchParams(window.location.search);
        const success = urlParams.get('success');
        const action = urlParams.get('action');
        
        if (success && action) {
            const messages = {
                'added': 'Inventory item added successfully!',
                'updated': 'Inventory item updated successfully!',
                'error': 'An error occurred performing the action.'
            };
            
            const msg = messages[success] || 'Operation completed.';
            const type = success === 'error' ? 'error' : 'success';
            
            showToast(msg, type);
        }
    });

    // Toast notification function
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast');
        if (!container) return;
        
        const typeClasses = {
            'success': 'alert-success',
            'error': 'alert-error',
            'warning': 'alert-warning',
            'info': 'alert-info'
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
  </script>
</body>
</html>