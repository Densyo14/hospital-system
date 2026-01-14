<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Initialize variables
$id = $patient_id = $doctor_id = $surgery_type = $schedule_date = $operating_room = $status = "";
$edit = false;
$inventory_items = [];
$selected_items = [];

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

// Check if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $surgery = fetchOne($conn, "SELECT * FROM surgeries WHERE id = ?", "i", [$id]);
    if ($surgery) {
        $edit = true;
        $patient_id = $surgery['patient_id'] ?? '';
        $doctor_id = $surgery['doctor_id'] ?? '';
        $surgery_type = $surgery['surgery_type'] ?? '';
        $schedule_date = $surgery['schedule_date'] ?? '';
        $operating_room = $surgery['operating_room'] ?? '';
        $status = $surgery['status'] ?? 'Scheduled';
        
        // Get already reserved inventory items for this surgery
        $selected_items = fetchAll($conn, "SELECT * FROM surgery_inventory WHERE surgery_id = ?", "i", [$id]);
    }
}

// Fetch dropdown data
$patients = fetchAll($conn, "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, patient_code FROM patients WHERE is_archived = 0 ORDER BY first_name", null, []);
$doctors = fetchAll($conn, "SELECT id, full_name FROM users WHERE role='Doctor' ORDER BY full_name", null, []);
$inventory = fetchAll($conn, "SELECT * FROM inventory_items WHERE quantity > 0 ORDER BY item_name", null, []);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $id = (int)($_POST['id'] ?? 0);
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $surgery_type = trim($_POST['surgery_type'] ?? '');
    $schedule_date = trim($_POST['schedule_date'] ?? '');
    $operating_room = trim($_POST['operating_room'] ?? '');
    $status = trim($_POST['status'] ?? 'Scheduled');
    
    // Get inventory items
    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    // Validation
    $errors = [];

    if ($patient_id <= 0) {
        $errors[] = "Please select a patient.";
    }

    if ($doctor_id <= 0) {
        $errors[] = "Please select a surgeon.";
    }

    if (empty($surgery_type)) {
        $errors[] = "Surgery type is required.";
    }

    if (empty($schedule_date)) {
        $errors[] = "Schedule date is required.";
    } elseif (strtotime($schedule_date) < strtotime(date('Y-m-d'))) {
        $errors[] = "Schedule date cannot be in the past.";
    }

    if (empty($operating_room)) {
        $errors[] = "Operating room is required.";
    }

    // Validate inventory quantities
    $inventory_errors = [];
    foreach ($item_ids as $index => $item_id) {
        $qty = (int)($quantities[$index] ?? 0);
        if ($qty > 0) {
            $stock = fetchOne($conn, "SELECT quantity, item_name FROM inventory_items WHERE id = ?", "i", [$item_id]);
            if ($stock && $qty > $stock['quantity']) {
                $inventory_errors[] = "Only " . $stock['quantity'] . " " . $stock['item_name'] . " available in stock.";
            }
        }
    }

    if (empty($errors) && empty($inventory_errors)) {
        // Start transaction for data consistency
        $conn->begin_transaction();
        
        try {
            if ($id) {
                // Update existing surgery
                $params = [$patient_id, $doctor_id, $surgery_type, $schedule_date, $operating_room, $status, $id];
                $types = "iissssi";
                $result = execute($conn, "UPDATE surgeries SET patient_id=?, doctor_id=?, surgery_type=?, schedule_date=?, operating_room=?, status=? WHERE id=?", $types, $params);
                $surgery_id = $id;
                
                // Remove old inventory reservations and restore stock
                $old_items = fetchAll($conn, "SELECT item_id, quantity_used FROM surgery_inventory WHERE surgery_id = ?", "i", [$surgery_id]);
                foreach ($old_items as $old_item) {
                    execute($conn, "UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?", "ii", [$old_item['quantity_used'], $old_item['item_id']]);
                }
                execute($conn, "DELETE FROM surgery_inventory WHERE surgery_id = ?", "i", [$surgery_id]);
            } else {
                // Insert new surgery
                $params = [$patient_id, $doctor_id, $surgery_type, $schedule_date, $operating_room, $status];
                $types = "iissss";
                $result = execute($conn, "INSERT INTO surgeries (patient_id, doctor_id, surgery_type, schedule_date, operating_room, status) VALUES (?,?,?,?,?,?)", $types, $params);
                $surgery_id = $conn->insert_id;
            }

            // Handle inventory reservations
            foreach ($item_ids as $index => $item_id) {
                $qty = (int)($quantities[$index] ?? 0);
                if ($qty > 0) {
                    // Reserve inventory
                    execute($conn, "INSERT INTO surgery_inventory (surgery_id, item_id, quantity_used) VALUES (?,?,?)", "iii", [$surgery_id, $item_id, $qty]);
                    
                    // Reduce inventory stock
                    execute($conn, "UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?", "ii", [$qty, $item_id]);
                }
            }

            $conn->commit();
            
            $action = $id ? 'updated' : 'scheduled';
            header("Location: surgeries.php?success=$action&surgery_id=$surgery_id");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error saving surgery: " . $e->getMessage();
        }
    } else {
        // Combine all errors
        $errors = array_merge($errors, $inventory_errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - <?php echo $edit ? "Edit Surgery" : "Schedule Surgery"; ?></title> <!-- UPDATED TITLE -->

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
    text-decoration: none;
    display: inline-block;
    cursor: pointer;
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
  }
  .btn-info {
    background: #3182ce;
    color: #fff;
  }
  .btn-info:hover {
    background: #2b6cb0;
    transform: translateY(-1px);
  }
  .btn-warning {
    background: #ed8936;
    color: #fff;
  }
  .btn-warning:hover {
    background: #dd6b20;
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

  /* Toast/Alert container */
  .alert-container {
    margin-bottom: 20px;
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

  /* Alert color classes */
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
    background: var(--panel);
    padding: 24px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    max-width: 900px;
    margin: 0 auto;
    border: 1px solid #f0f4f8;
  }

  .form-group {
    margin-bottom: 20px;
  }

  .form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #1e3a5f;
  }

  .form-control, .form-select, .form-textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-family: Inter, sans-serif;
    font-size: 14px;
    transition: border-color 0.2s ease;
    background: #fff;
  }

  .form-control:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }

  .form-textarea {
    min-height: 100px;
    resize: vertical;
  }

  .required:after {
    content: " *";
    color: #e53e3e;
  }

  /* Searchable select styles */
  .searchable-select {
    position: relative;
  }
  
  .search-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-family: Inter, sans-serif;
    font-size: 14px;
    margin-bottom: 5px;
    background: #fff;
  }
  
  .search-input:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }
  
  .dropdown-list {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 200px;
    overflow-y: auto;
    background: white;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    box-shadow: var(--card-shadow);
    z-index: 1000;
    display: none;
  }
  
  .dropdown-list.show {
    display: block;
  }
  
  .dropdown-item {
    padding: 10px 12px;
    cursor: pointer;
    transition: background 0.2s;
  }
  
  .dropdown-item:hover {
    background: #f8fbfd;
  }
  
  .dropdown-item.selected {
    background: #e8f4ff;
    color: var(--navy-700);
    font-weight: 600;
  }
  
  .selected-display {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  
  .selected-display::after {
    content: "▼";
    font-size: 12px;
    color: var(--muted);
  }
  
  .hidden-select {
    display: none;
  }

  /* Inventory table */
  .inventory-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
  }

  .inventory-table thead {
    background: #f8fbfd;
  }

  .inventory-table th {
    padding: 12px 15px;
    text-align: left;
    color: #6b7280;
    font-weight: 600;
    border-bottom: 2px solid #eef2f7;
  }

  .inventory-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #f0f3f4;
    color: #233;
  }

  .inventory-table tbody tr:hover {
    background: #f8fbfd;
  }

  .inventory-table input[type="number"] {
    width: 80px;
    padding: 6px 8px;
    border: 1px solid #e6eef0;
    border-radius: 6px;
    text-align: center;
  }

  /* Form actions */
  .form-actions {
    display: flex;
    gap: 12px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #f0f3f4;
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

  /* Responsive */
  @media (max-width:780px){
    .sidebar{position:fixed;left:-320px;transform:translateX(0)}
    .sidebar.open{left:0;transform:translateX(0)}
    .main{margin-left:0;padding:12px}
    .sidebar.collapsed{width:230px}
    .topbar{flex-direction:column;align-items:flex-start}
    .top-actions{width:100%;justify-content:space-between}
    .form-actions {
      flex-direction: column;
    }
    .btn {
      width: 100%;
      text-align: center;
    }
    .inventory-table {
      display: block;
      overflow-x: auto;
    }
  }

  /* small niceties */
  a{color:inherit}
  .muted{color:var(--muted);font-size:13px}
</style>
</head>
<body>
  <div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
        <!-- Make logo image clickable and changed to JPG -->
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
          <h1><?php echo $edit ? "Edit Surgery" : "Schedule Surgery"; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Welcome to Gig Oca Robles Seamen's Hospital Davao Management System</p> <!-- MATCHED TO DASHBOARD -->
        </div>

        <div class="top-actions">
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Alert container -->
      <div class="alert-container">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong>Please fix the following errors:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
      </div>

      <!-- Form -->
      <div class="form-container">
        <form method="POST" onsubmit="return validateForm()">
          <input type="hidden" name="id" value="<?php echo h($id); ?>">

          <div class="form-group">
            <label class="form-label required">Patient</label>
            <div class="searchable-select">
              <!-- Hidden select for form submission -->
              <select name="patient_id" class="hidden-select" required>
                <option value="">Select Patient</option>
                <?php 
                $selected_patient_text = '';
                foreach($patients as $patient): 
                  $selected = $patient_id == $patient['id'];
                  if ($selected) {
                    $selected_patient_text = h($patient['full_name']) . " (" . h($patient['patient_code']) . ")";
                  }
                ?>
                  <option value="<?php echo h($patient['id']); ?>" <?php echo $selected ? 'selected' : ''; ?> data-display="<?php echo h($patient['full_name'] . " (" . $patient['patient_code'] . ")"); ?>">
                    <?php echo h($patient['full_name']); ?> (<?php echo h($patient['patient_code']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              
              <!-- Display for selected patient -->
              <div class="selected-display" onclick="toggleDropdown('patient')" id="patient-display">
                <?php echo $selected_patient_text ?: 'Select Patient'; ?>
              </div>
              
              <!-- Search input -->
              <input type="text" class="search-input" placeholder="Search patients..." onkeyup="filterOptions('patient')" id="patient-search">
              
              <!-- Dropdown list -->
              <div class="dropdown-list" id="patient-list">
                <?php foreach($patients as $patient): ?>
                  <div class="dropdown-item" data-value="<?php echo h($patient['id']); ?>" onclick="selectOption('patient', this)" <?php echo $patient_id == $patient['id'] ? 'data-selected="true"' : ''; ?>>
                    <?php echo h($patient['full_name']); ?> (<?php echo h($patient['patient_code']); ?>)
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label required">Surgeon</label>
            <div class="searchable-select">
              <!-- Hidden select for form submission -->
              <select name="doctor_id" class="hidden-select" required>
                <option value="">Select Surgeon</option>
                <?php 
                $selected_doctor_text = '';
                foreach($doctors as $doctor): 
                  $selected = $doctor_id == $doctor['id'];
                  if ($selected) {
                    $selected_doctor_text = h($doctor['full_name']);
                  }
                ?>
                  <option value="<?php echo h($doctor['id']); ?>" <?php echo $selected ? 'selected' : ''; ?> data-display="<?php echo h($doctor['full_name']); ?>">
                    <?php echo h($doctor['full_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              
              <!-- Display for selected doctor -->
              <div class="selected-display" onclick="toggleDropdown('doctor')" id="doctor-display">
                <?php echo $selected_doctor_text ?: 'Select Surgeon'; ?>
              </div>
              
              <!-- Search input -->
              <input type="text" class="search-input" placeholder="Search surgeons..." onkeyup="filterOptions('doctor')" id="doctor-search">
              
              <!-- Dropdown list -->
              <div class="dropdown-list" id="doctor-list">
                <?php foreach($doctors as $doctor): ?>
                  <div class="dropdown-item" data-value="<?php echo h($doctor['id']); ?>" onclick="selectOption('doctor', this)" <?php echo $doctor_id == $doctor['id'] ? 'data-selected="true"' : ''; ?>>
                    <?php echo h($doctor['full_name']); ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
              <label class="form-label required">Surgery Type</label>
              <input type="text" name="surgery_type" class="form-control" 
                  value="<?php echo h($surgery_type); ?>" 
                  placeholder="e.g., Clubfoot Correction, Tendon Release" required>
            </div>

            <div class="form-group">
              <label class="form-label required">Schedule Date</label>
              <input type="date" name="schedule_date" class="form-control" 
                  value="<?php echo h($schedule_date); ?>" 
                  min="<?php echo date('Y-m-d'); ?>" required>
            </div>
          </div>

          <div class="row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
              <label class="form-label required">Operating Room</label>
              <input type="text" name="operating_room" class="form-control" 
                  value="<?php echo h($operating_room); ?>" 
                  placeholder="e.g., OR-1, Room 201" required>
            </div>

            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="Scheduled" <?php if ($status == 'Scheduled') echo 'selected'; ?>>Scheduled</option>
                <option value="Completed" <?php if ($status == 'Completed') echo 'selected'; ?>>Completed</option>
                <option value="Cancelled" <?php if ($status == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
              </select>
            </div>
          </div>

          <!-- Inventory Reservation Section -->
          <div class="section-title" style="margin-top: 30px; margin-bottom: 15px; font-size: 18px; color: #1e3a5f; border-bottom: 2px solid #eef2f7; padding-bottom: 10px;">
            Reserve Inventory Items
          </div>
          
          <div class="alert" style="background: #e8f4ff; color: #1e6b8a; margin-bottom: 20px; border-left: 4px solid #4d8cc9;">
            <small>Select items and quantities needed for this surgery. Items will be reserved from inventory.</small>
          </div>
          
          <div style="overflow-x: auto;">
            <table class="inventory-table">
              <thead>
                <tr>
                  <th width="40%">Item Name</th>
                  <th width="20%">Available Stock</th>
                  <th width="20%">Unit</th>
                  <th width="20%">Quantity to Reserve</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($inventory as $index => $item): 
                  // Check if this item was previously selected
                  $selected_qty = 0;
                  if ($edit) {
                    foreach ($selected_items as $selected) {
                      if ($selected['item_id'] == $item['id']) {
                        $selected_qty = $selected['quantity_used'];
                        break;
                      }
                    }
                  }
                ?>
                  <tr>
                    <td>
                      <input type="hidden" name="item_id[]" value="<?php echo h($item['id']); ?>">
                      <?php echo h($item['item_name']); ?>
                      <?php if ($item['category']): ?>
                        <br><small class="muted"><?php echo h($item['category']); ?></small>
                      <?php endif; ?>
                      <?php if ($item['quantity'] <= $item['threshold']): ?>
                        <span style="background: #ed8936; color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 8px;">Low Stock</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo h($item['quantity']); ?></td>
                    <td><?php echo h($item['unit']); ?></td>
                    <td>
                      <input type="number" name="quantity[]" class="form-control" 
                          min="0" max="<?php echo h($item['quantity']); ?>" 
                          value="<?php echo h($selected_qty); ?>" 
                          placeholder="0"
                          style="width: 80px; margin: 0 auto; display: block;">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn">
              <?php echo $edit ? "Update Surgery" : "Schedule Surgery"; ?>
            </button>
            <a href="surgeries.php" class="btn btn-secondary">Cancel</a>
            <?php if ($edit): ?>
              <a href="surgery_view.php?id=<?php echo h($id); ?>" class="btn btn-info">View Details</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <script>
    // Searchable select functionality
    let openDropdown = null;
    
    function toggleDropdown(type) {
      const list = document.getElementById(`${type}-list`);
      const search = document.getElementById(`${type}-search`);
      const display = document.getElementById(`${type}-display`);
      
      if (openDropdown && openDropdown !== list) {
        openDropdown.classList.remove('show');
      }
      
      if (list.classList.contains('show')) {
        list.classList.remove('show');
        openDropdown = null;
      } else {
        list.classList.add('show');
        openDropdown = list;
        if (search) search.focus();
      }
      
      // Close dropdown when clicking outside
      document.addEventListener('click', function closeDropdown(e) {
        if (!list.contains(e.target) && e.target !== display) {
          list.classList.remove('show');
          openDropdown = null;
          document.removeEventListener('click', closeDropdown);
        }
      });
    }
    
    function filterOptions(type) {
      const search = document.getElementById(`${type}-search`);
      const list = document.getElementById(`${type}-list`);
      const items = list.getElementsByClassName('dropdown-item');
      const filter = search.value.toUpperCase();
      
      for (let i = 0; i < items.length; i++) {
        const text = items[i].textContent || items[i].innerText;
        if (text.toUpperCase().indexOf(filter) > -1) {
          items[i].style.display = "";
        } else {
          items[i].style.display = "none";
        }
      }
    }
    
    function selectOption(type, element) {
      const value = element.getAttribute('data-value');
      const text = element.textContent;
      const display = document.getElementById(`${type}-display`);
      const hiddenSelect = document.querySelector(`select[name="${type}_id"]`);
      
      // Update display
      display.textContent = text;
      
      // Update hidden select
      const option = hiddenSelect.querySelector(`option[value="${value}"]`);
      if (option) {
        hiddenSelect.value = value;
      }
      
      // Highlight selected item
      const items = document.getElementById(`${type}-list`).getElementsByClassName('dropdown-item');
      for (let i = 0; i < items.length; i++) {
        items[i].classList.remove('selected');
        items[i].removeAttribute('data-selected');
      }
      element.classList.add('selected');
      element.setAttribute('data-selected', 'true');
      
      // Close dropdown
      document.getElementById(`${type}-list`).classList.remove('show');
      openDropdown = null;
    }
    
    function highlightSelectedItems() {
      // Highlight selected patients
      const patientItems = document.getElementById('patient-list').getElementsByClassName('dropdown-item');
      for (let i = 0; i < patientItems.length; i++) {
        if (patientItems[i].getAttribute('data-selected') === 'true') {
          patientItems[i].classList.add('selected');
        }
      }
      
      // Highlight selected doctors
      const doctorItems = document.getElementById('doctor-list').getElementsByClassName('dropdown-item');
      for (let i = 0; i < doctorItems.length; i++) {
        if (doctorItems[i].getAttribute('data-selected') === 'true') {
          doctorItems[i].classList.add('selected');
        }
      }
    }

    function validateForm() {
      // Client-side validation
      const patientId = document.querySelector('select[name="patient_id"]').value;
      const doctorId = document.querySelector('select[name="doctor_id"]').value;
      const surgeryType = document.querySelector('input[name="surgery_type"]');
      const scheduleDate = document.querySelector('input[name="schedule_date"]');
      const operatingRoom = document.querySelector('input[name="operating_room"]');
      
      // Check patient
      if (!patientId) {
        alert('Please select a patient.');
        document.getElementById('patient-display').focus();
        return false;
      }
      
      // Check doctor
      if (!doctorId) {
        alert('Please select a surgeon.');
        document.getElementById('doctor-display').focus();
        return false;
      }
      
      // Check surgery type
      if (!surgeryType.value.trim()) {
        alert('Please enter surgery type.');
        surgeryType.focus();
        return false;
      }
      
      // Check schedule date
      if (!scheduleDate.value) {
        alert('Please select a schedule date.');
        scheduleDate.focus();
        return false;
      }
      
      // Check if schedule is in the past
      const schedule = new Date(scheduleDate.value);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      if (schedule < today) {
        alert('Schedule date cannot be in the past.');
        scheduleDate.focus();
        return false;
      }
      
      // Check operating room
      if (!operatingRoom.value.trim()) {
        alert('Please enter operating room.');
        operatingRoom.focus();
        return false;
      }
      
      // Validate inventory quantities
      const quantityInputs = document.querySelectorAll('input[name="quantity[]"]');
      let hasValidQuantity = false;
      for (let input of quantityInputs) {
        if (parseInt(input.value) > 0) {
          hasValidQuantity = true;
          const max = parseInt(input.max);
          const value = parseInt(input.value);
          if (value > max) {
            alert('Quantity cannot exceed available stock.');
            input.focus();
            return false;
          }
        }
      }
      
      return true;
    }

    // Highlight selected items on page load
    document.addEventListener('DOMContentLoaded', function() {
      highlightSelectedItems();
      
      // Set default date to tomorrow if not editing
      const scheduleInput = document.querySelector('input[name="schedule_date"]');
      if (scheduleInput && !scheduleInput.value) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        scheduleInput.value = tomorrow.toISOString().split('T')[0];
      }
    });
  </script>
</body>
</html>