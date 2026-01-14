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

// Initialize variables
$id = $first_name = $last_name = $gender = $birth_date = $phone = $address = $guardian = "";
$blood_type = $weight = $height = $pulse_rate = $temperature = $diagnosis = "";
$edit = false;

// Check if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $patient = fetchOne($conn, "SELECT p.*, 
                                     GROUP_CONCAT(m.diagnosis SEPARATOR ', ') AS diagnosis
                              FROM patients p
                              LEFT JOIN medical_records m ON p.id = m.patient_id
                              WHERE p.id = ?
                              GROUP BY p.id", "i", [$id]);
    if ($patient) {
        $edit = true; // edit mode active
        // assign existing patient data to variables
        $first_name = $patient['first_name'] ?? '';
        $last_name  = $patient['last_name'] ?? '';
        $gender     = $patient['gender'] ?? '';
        $birth_date = $patient['birth_date'] ?? '';
        $phone      = $patient['phone'] ?? '';
        $address    = $patient['address'] ?? '';
        $guardian   = $patient['guardian'] ?? '';
        $blood_type = $patient['blood_type'] ?? '';
        $weight     = $patient['weight'] ?? '';
        $height     = $patient['height'] ?? '';
        $pulse_rate = $patient['pulse_rate'] ?? '';
        $temperature= $patient['temperature'] ?? '';
        $diagnosis  = $patient['diagnosis'] ?? '';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id         = (int)($_POST['id'] ?? 0);
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $gender     = trim($_POST['gender'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $guardian   = trim($_POST['guardian'] ?? '');
    $blood_type = trim($_POST['blood_type'] ?? '');
    $weight     = trim($_POST['weight'] ?? '');
    $height     = trim($_POST['height'] ?? '');
    $pulse_rate = trim($_POST['pulse_rate'] ?? '');
    $temperature= trim($_POST['temperature'] ?? '');
    $diagnosis  = trim($_POST['diagnosis'] ?? '');

    // Validation
    $errors = [];

    // Name validation - letters and spaces only
    if (!preg_match('/^[a-zA-Z\s]+$/', $first_name)) {
        $errors[] = "First name should contain only letters and spaces.";
    }
    if (!preg_match('/^[a-zA-Z\s]+$/', $last_name)) {
        $errors[] = "Last name should contain only letters and spaces.";
    }

    // Phone validation - numbers only
    if (!preg_match('/^[0-9]+$/', $phone)) {
        $errors[] = "Phone number should contain only numbers.";
    }

    // Guardian validation - letters and spaces only (optional)
    if ($guardian && !preg_match('/^[a-zA-Z\s]+$/', $guardian)) {
        $errors[] = "Guardian name should contain only letters and spaces.";
    }

    // Convert empty strings to NULL for numeric fields
    $weight = $weight === '' ? null : $weight;
    $height = $height === '' ? null : $height;
    $pulse_rate = $pulse_rate === '' ? null : $pulse_rate;
    $temperature = $temperature === '' ? null : $temperature;

    // If no validation errors, proceed with database operations
    if (empty($errors)) {
        if ($id) {
            // Update existing patient
            $params = [$first_name, $last_name, $gender, $birth_date, $phone, $address, $guardian, $blood_type, $weight, $height, $pulse_rate, $temperature, $id];
            $types = "ssssssssddddi"; 
            $result = execute($conn, "UPDATE patients SET first_name=?, last_name=?, gender=?, birth_date=?, phone=?, address=?, guardian=?, blood_type=?, weight=?, height=?, pulse_rate=?, temperature=? WHERE id=?", $types, $params);
            
            // Update or insert medical record with diagnosis
            if ($diagnosis) {
                // Check if medical record exists
                $existing_record = fetchOne($conn, "SELECT id FROM medical_records WHERE patient_id = ?", "i", [$id]);
                if ($existing_record) {
                    // Update existing medical record
                    execute($conn, "UPDATE medical_records SET diagnosis = ? WHERE patient_id = ?", "si", [$diagnosis, $id]);
                } else {
                    // Insert new medical record (using first doctor as default)
                    $first_doctor = fetchOne($conn, "SELECT id FROM users WHERE role = 'Doctor' LIMIT 1", null, []);
                    $doctor_id = $first_doctor ? $first_doctor['id'] : null;
                    execute($conn, "INSERT INTO medical_records (patient_id, doctor_id, diagnosis) VALUES (?, ?, ?)", "iis", [$id, $doctor_id, $diagnosis]);
                }
            }
            
            $patient_id = $id; // For updates, use the existing ID
        } else {
            // Insert new patient
            $patient_code = generate_patient_code($conn);
            $params = [$patient_code, $first_name, $last_name, $gender, $birth_date, $phone, $address, $guardian, $blood_type, $weight, $height, $pulse_rate, $temperature];
            $types = "sssssssssdddd"; 
            $result = execute($conn, "INSERT INTO patients (patient_code, first_name, last_name, gender, birth_date, phone, address, guardian, blood_type, weight, height, pulse_rate, temperature) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", $types, $params);
            $patient_id = $conn->insert_id; // For new patients, get the last inserted ID
            
            // Insert medical record with diagnosis if provided
            if ($diagnosis && $patient_id) {
                $first_doctor = fetchOne($conn, "SELECT id FROM users WHERE role = 'Doctor' LIMIT 1", null, []);
                $doctor_id = $first_doctor ? $first_doctor['id'] : null;
                execute($conn, "INSERT INTO medical_records (patient_id, doctor_id, diagnosis) VALUES (?, ?, ?)", "iis", [$patient_id, $doctor_id, $diagnosis]);
            }
        }

        if (!isset($result['error'])) {
            $action = $id ? 'updated' : 'added';
            // Redirect with patient ID for highlighting
            header("Location: patients.php?success=$action&action=$action&patient_id=$patient_id");
            exit();
        } else {
            $submit_error = "Error: " . $result['error'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - <?php echo $edit ? "Edit Patient" : "Add Patient"; ?></title> <!-- UPDATED TITLE -->

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
  .btn-info {
    background: #3182ce;
    color: #fff;
  }
  .btn-info:hover {
    background: #2b6cb0;
    transform: translateY(-1px);
  }
  .btn-outline {
    background: transparent;
    color: var(--navy-700);
    border: 1px solid var(--navy-700);
  }
  .btn-outline:hover {
    background: rgba(0, 31, 63, 0.1);
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

  /* Form Card */
  .form-card {
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    max-width:800px;
    border: 1px solid #f0f4f8;
  }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
  }

  @media (max-width: 768px) {
    .form-grid {
      grid-template-columns: 1fr;
    }
  }

  .form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .form-label {
    font-size: 14px;
    color: #1e3a5f;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .form-label.required:after {
    content: '*';
    color: #e53e3e;
    margin-left: 2px;
  }

  .form-control {
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #f8fbfd;
  }

  .form-control:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
    background: white;
  }

  .form-control.error {
    border-color: #e53e3e;
    background: #fff5f5;
  }

  .form-text {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
  }

  .form-error {
    color: #e53e3e;
    font-size: 12px;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .form-error:before {
    content: '⚠';
  }

  .form-actions {
    display: flex;
    gap: 12px;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #eef2f7;
  }

  .btn-submit {
    background: var(--navy-700);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 14px;
  }

  .btn-submit:hover {
    background: var(--accent);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
  }

  .btn-cancel {
    background: #6b7280;
    color: white;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    font-size: 14px;
  }

  .btn-cancel:hover {
    background: #4b5563;
    transform: translateY(-1px);
    color: white;
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
    .btn-submit, .btn-cancel {
      width: 100%;
      text-align: center;
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
          <h1><?php echo $edit ? "Edit Patient" : "Add Patient"; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Welcome to Gig Oca Robles Seamen's Hospital Davao Management System</p> <!-- MATCHED TO DASHBOARD -->
        </div>

        <div class="top-actions">
          <a href="patients.php" class="btn btn-outline">&larr; Back to Patients</a>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- Form Card -->
      <div class="form-card">
        <?php if (isset($errors) && !empty($errors)): ?>
          <div class="alert alert-error" style="margin-bottom: 20px;">
            <strong>Please correct the following errors:</strong><br>
            <?php foreach ($errors as $error): ?>
              • <?php echo htmlspecialchars($error); ?><br>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (isset($submit_error)): ?>
          <div class="alert alert-error" style="margin-bottom: 20px;">
            <?php echo htmlspecialchars($submit_error); ?>
          </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return validateForm()">
          <input type="hidden" name="id" value="<?php echo htmlspecialchars($id); ?>">

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label required">First Name</label>
              <input type="text" name="first_name" class="form-control" 
                     value="<?php echo htmlspecialchars($first_name); ?>" 
                     required
                     onkeypress="return allowLettersOnly(event)"
                     placeholder="Enter first name">
              <div class="form-text">Only letters and spaces allowed</div>
            </div>

            <div class="form-group">
              <label class="form-label required">Last Name</label>
              <input type="text" name="last_name" class="form-control" 
                     value="<?php echo htmlspecialchars($last_name); ?>" 
                     required
                     onkeypress="return allowLettersOnly(event)"
                     placeholder="Enter last name">
              <div class="form-text">Only letters and spaces allowed</div>
            </div>

            <div class="form-group">
              <label class="form-label required">Gender</label>
              <select name="gender" class="form-control" required>
                <option value="">Select Gender</option>
                <option value="Male" <?php if($gender==='Male') echo 'selected'; ?>>Male</option>
                <option value="Female" <?php if($gender==='Female') echo 'selected'; ?>>Female</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label required">Birth Date</label>
              <input type="date" name="birth_date" class="form-control" 
                     value="<?php echo htmlspecialchars($birth_date); ?>" required>
            </div>

            <div class="form-group">
              <label class="form-label required">Phone</label>
              <input type="text" name="phone" class="form-control" 
                     value="<?php echo htmlspecialchars($phone); ?>" 
                     required
                     onkeypress="return allowNumbersOnly(event)"
                     placeholder="Enter phone number">
              <div class="form-text">Only numbers allowed</div>
            </div>

            <div class="form-group">
              <label class="form-label required">Address</label>
              <textarea name="address" class="form-control" rows="2" 
                        required placeholder="Enter complete address"><?php echo htmlspecialchars($address); ?></textarea>
            </div>

            <div class="form-group">
              <label class="form-label">Guardian</label>
              <input type="text" name="guardian" class="form-control" 
                     value="<?php echo htmlspecialchars($guardian); ?>"
                     onkeypress="return allowLettersOnly(event)"
                     placeholder="Enter guardian name">
              <div class="form-text">Only letters and spaces allowed (optional)</div>
            </div>

            <div class="form-group">
              <label class="form-label">Blood Type</label>
              <select name="blood_type" class="form-control">
                <option value="">Select Blood Type</option>
                <option value="A+" <?php if($blood_type==='A+') echo 'selected'; ?>>A+</option>
                <option value="A-" <?php if($blood_type==='A-') echo 'selected'; ?>>A-</option>
                <option value="B+" <?php if($blood_type==='B+') echo 'selected'; ?>>B+</option>
                <option value="B-" <?php if($blood_type==='B-') echo 'selected'; ?>>B-</option>
                <option value="AB+" <?php if($blood_type==='AB+') echo 'selected'; ?>>AB+</option>
                <option value="AB-" <?php if($blood_type==='AB-') echo 'selected'; ?>>AB-</option>
                <option value="O+" <?php if($blood_type==='O+') echo 'selected'; ?>>O+</option>
                <option value="O-" <?php if($blood_type==='O-') echo 'selected'; ?>>O-</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Weight (kg)</label>
              <input type="number" step="0.1" name="weight" class="form-control" 
                     value="<?php echo htmlspecialchars($weight); ?>" 
                     onkeypress="return allowNumbersAndDecimal(event)"
                     placeholder="Enter weight in kg">
            </div>

            <div class="form-group">
              <label class="form-label">Height (m)</label>
              <input type="number" step="0.01" name="height" class="form-control" 
                     value="<?php echo htmlspecialchars($height); ?>" 
                     onkeypress="return allowNumbersAndDecimal(event)"
                     placeholder="Enter height in meters">
              <div class="form-text">Example: 1.75 for 175cm</div>
            </div>

            <div class="form-group">
              <label class="form-label">Pulse Rate (bpm)</label>
              <input type="number" name="pulse_rate" class="form-control" 
                     value="<?php echo htmlspecialchars($pulse_rate); ?>" 
                     onkeypress="return allowNumbersOnly(event)"
                     placeholder="Enter pulse rate">
            </div>

            <div class="form-group">
              <label class="form-label">Temperature (°C)</label>
              <input type="number" step="0.1" name="temperature" class="form-control" 
                     value="<?php echo htmlspecialchars($temperature); ?>" 
                     onkeypress="return allowNumbersAndDecimal(event)"
                     placeholder="Enter temperature">
            </div>
          </div>

          <div class="form-group" style="margin-top: 20px;">
            <label class="form-label">Diagnosis</label>
            <textarea name="diagnosis" class="form-control" rows="4" 
                      placeholder="Enter patient diagnosis"><?php echo htmlspecialchars($diagnosis); ?></textarea>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn-submit">
              <?php echo $edit ? "Update Patient" : "Add Patient"; ?>
            </button>
            <a href="patients.php" class="btn-cancel">Cancel</a>
          </div>
        </form>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <script>
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
       On page load: show toast if redirected
       ------------------------- */
    document.addEventListener('DOMContentLoaded', function() {
        // Check URL for success messages
        const urlParams = new URLSearchParams(window.location.search);
        const success = urlParams.get('success');
        const message = urlParams.get('message');
        
        if (success) {
            const messages = {
                'updated': 'Patient updated successfully!',
                'added': 'Patient added successfully!',
                'archived': 'Patient archived successfully!',
                'restored': 'Patient restored successfully!',
                'error': message || 'An error occurred.'
            };
            const msg = messages[success] || 'Operation completed.';
            
            // Determine toast type based on success
            let toastType = 'success';
            if (success === 'error') toastType = 'error';
            else if (success === 'warning') toastType = 'warning';
            else if (success === 'info') toastType = 'info';
            
            showToast(msg, toastType);
        }
    });

    // Validation functions
    function allowLettersOnly(event) {
        var charCode = event.which ? event.which : event.keyCode;
        // Allow letters (a-z, A-Z) and space (32)
        if ((charCode >= 65 && charCode <= 90) || (charCode >= 97 && charCode <= 122) || charCode === 32) {
            return true;
        }
        // Allow backspace, tab, enter, delete
        if (charCode === 8 || charCode === 9 || charCode === 13 || charCode === 46) {
            return true;
        }
        return false;
    }

    function allowNumbersOnly(event) {
        var charCode = event.which ? event.which : event.keyCode;
        // Allow numbers (0-9) and backspace (8), tab (9), enter (13), delete (46)
        if ((charCode >= 48 && charCode <= 57) || charCode === 8 || charCode === 9 || charCode === 13 || charCode === 46) {
            return true;
        }
        return false;
    }

    function allowNumbersAndDecimal(event) {
        var charCode = event.which ? event.which : event.keyCode;
        var value = event.target.value;
        
        // Allow numbers (0-9), decimal point (46), backspace (8), tab (9), enter (13), delete (46)
        if ((charCode >= 48 && charCode <= 57) || charCode === 46 || charCode === 8 || charCode === 9 || charCode === 13) {
            // Only allow one decimal point
            if (charCode === 46 && value.indexOf('.') !== -1) {
                return false;
            }
            return true;
        }
        return false;
    }

    function validateForm() {
        // Client-side validation
        const firstName = document.querySelector('input[name="first_name"]');
        const lastName = document.querySelector('input[name="last_name"]');
        const phone = document.querySelector('input[name="phone"]');
        const guardian = document.querySelector('input[name="guardian"]');
        
        // Check name fields
        const nameRegex = /^[A-Za-z\s]+$/;
        if (!nameRegex.test(firstName.value)) {
            showToast('First name should contain only letters and spaces.', 'error');
            firstName.focus();
            return false;
        }
        if (!nameRegex.test(lastName.value)) {
            showToast('Last name should contain only letters and spaces.', 'error');
            lastName.focus();
            return false;
        }
        
        // Check phone field
        const phoneRegex = /^[0-9]+$/;
        if (!phoneRegex.test(phone.value)) {
            showToast('Phone number should contain only numbers.', 'error');
            phone.focus();
            return false;
        }
        
        // Check guardian field (optional)
        if (guardian.value && !nameRegex.test(guardian.value)) {
            showToast('Guardian name should contain only letters and spaces.', 'error');
            guardian.focus();
            return false;
        }
        
        return true;
    }

    // Add real-time validation
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('input[pattern]');
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                const pattern = new RegExp(this.pattern);
                if (this.value && !pattern.test(this.value)) {
                    this.classList.add('error');
                } else {
                    this.classList.remove('error');
                }
            });
        });
    });
  </script>
</body>
</html>