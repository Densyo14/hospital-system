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

// Initialize variables
$id = $first_name = $last_name = $gender = $birth_date = $phone = $address = $guardian = "";
$blood_type = $weight = $height = $pulse_rate = $temperature = $initial_findings = "";
$edit = false;

// Check if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $patient = fetchOne($conn, "SELECT * FROM patients WHERE id = ?", "i", [$id]);
    if ($patient) {
        $edit = true;
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
        // No diagnosis field now
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
    $initial_findings = trim($_POST['initial_findings'] ?? ''); // NEW field

    // Validation (same as before)
    $errors = [];

    if (!preg_match('/^[a-zA-Z\s]+$/', $first_name)) {
        $errors[] = "First name should contain only letters and spaces.";
    }
    if (!preg_match('/^[a-zA-Z\s]+$/', $last_name)) {
        $errors[] = "Last name should contain only letters and spaces.";
    }
    if (!preg_match('/^[0-9]+$/', $phone)) {
        $errors[] = "Phone number should contain only numbers.";
    }
    if ($guardian && !preg_match('/^[a-zA-Z\s]+$/', $guardian)) {
        $errors[] = "Guardian name should contain only letters and spaces.";
    }

    $weight = $weight === '' ? null : $weight;
    $height = $height === '' ? null : $height;
    $pulse_rate = $pulse_rate === '' ? null : $pulse_rate;
    $temperature = $temperature === '' ? null : $temperature;

    if (empty($errors)) {
        if ($id) {
            // Update existing patient
            $params = [$first_name, $last_name, $gender, $birth_date, $phone, $address, $guardian, $blood_type, $weight, $height, $pulse_rate, $temperature, $id];
            $types = "ssssssssddddi";
            $result = execute($conn, "UPDATE patients SET first_name=?, last_name=?, gender=?, birth_date=?, phone=?, address=?, guardian=?, blood_type=?, weight=?, height=?, pulse_rate=?, temperature=? WHERE id=?", $types, $params);
            $patient_id = $id;
            $action = 'updated';
        } else {
            // Insert new patient with created_by
            // Insert new patient with created_by
$patient_code = generate_patient_code($conn);
$params = [$patient_code, $first_name, $last_name, $gender, $birth_date, $phone, $address, $guardian, $blood_type, $weight, $height, $pulse_rate, $temperature, $_SESSION['user_id']];
$types = "sssssssssddddi"; // 9 strings, 4 decimals, 1 integer = 14 placeholders
$result = execute($conn, "INSERT INTO patients (patient_code, first_name, last_name, gender, birth_date, phone, address, guardian, blood_type, weight, height, pulse_rate, temperature, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)", $types, $params);

            // If initial findings were entered, we could store them in session to pre-fill triage form, or show a prompt.
            // For now, we'll store in session to be used after redirect.
            if (!empty($initial_findings)) {
                $_SESSION['initial_findings_for_triage'] = $initial_findings;
                $_SESSION['triage_patient_id'] = $patient_id;
            }
        }

        if (!isset($result['error'])) {
            // Redirect with success message
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
<title>Hospital Dashboard - <?php echo $edit ? "Update Patient" : "Add Patient"; ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  /* Copy all styles from previous version, but we can keep minimal */
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
  body{
    margin:0;
    font-family:Inter, sans-serif;
    background:var(--bg);
    color:#0f1724;
  }
  .app { display:flex; min-height:100vh; }
  .sidebar { /* same as before, we can reuse or include via external CSS, but we'll keep short for brevity */
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

  .form-card{
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    max-width:800px;
    border:1px solid #f0f4f8;
  }
  .form-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));
    gap:20px;
    margin-bottom:20px;
  }
  @media (max-width:768px){ .form-grid{ grid-template-columns:1fr; } }
  .form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
  }
  .form-label{
    font-size:14px;
    color:#1e3a5f;
    font-weight:600;
  }
  .form-label.required:after{
    content:'*';
    color:#e53e3e;
    margin-left:2px;
  }
  .form-control{
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    font-size:14px;
    font-family:inherit;
    transition:border-color 0.2s, box-shadow 0.2s;
    background:#f8fbfd;
  }
  .form-control:focus{
    outline:none;
    border-color:var(--light-blue);
    box-shadow:0 0 0 3px rgba(77,140,201,0.1);
    background:white;
  }
  .form-text{ font-size:12px; color:var(--muted); margin-top:2px; }
  .form-error{ color:#e53e3e; font-size:12px; margin-top:4px; }

  .form-actions{
    display:flex;
    gap:12px;
    margin-top:25px;
    padding-top:20px;
    border-top:1px solid #eef2f7;
  }
  .btn-submit{
    background:var(--navy-700);
    color:white;
    border:none;
    padding:10px 20px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
    transition:all 0.2s;
    font-size:14px;
  }
  .btn-submit:hover{
    background:var(--accent);
    transform:translateY(-1px);
    box-shadow:0 4px 12px rgba(0,31,63,0.2);
  }
  .btn-cancel{
    background:#6b7280;
    color:white;
    text-decoration:none;
    padding:10px 20px;
    border-radius:8px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:all 0.2s;
    font-size:14px;
  }
  .btn-cancel:hover{
    background:#4b5563;
    transform:translateY(-1px);
    color:white;
  }

  .alert{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:20px;
    color:white;
    font-weight:500;
  }
  .alert-error{ background:#e53e3e; border-left:4px solid #c53030; }
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
        <h1><?= $edit ? "Update Patient" : "Add Patient" ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Patient registration – Gig Oca Robles Seamen's Hospital Davao</p>
      </div>
      <div class="top-actions">
        <a href="patients.php" class="btn btn-outline">&larr; Back to Patients</a>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div id="toast" class="toast-container"></div>

    <div class="form-card">
      <?php if (isset($errors) && !empty($errors)): ?>
        <div class="alert alert-error">
          <strong>Please correct the following errors:</strong><br>
          <?php foreach ($errors as $error): ?> • <?= htmlspecialchars($error) ?><br><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (isset($submit_error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($submit_error) ?></div>
      <?php endif; ?>

      <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">

        <div class="form-grid">
          <div class="form-group">
            <label class="form-label required">First Name</label>
            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($first_name) ?>" required onkeypress="return allowLettersOnly(event)" placeholder="Enter first name">
            <div class="form-text">Only letters and spaces allowed</div>
          </div>
          <div class="form-group">
            <label class="form-label required">Last Name</label>
            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($last_name) ?>" required onkeypress="return allowLettersOnly(event)" placeholder="Enter last name">
            <div class="form-text">Only letters and spaces allowed</div>
          </div>
          <div class="form-group">
            <label class="form-label required">Gender</label>
            <select name="gender" class="form-control" required>
              <option value="">Select Gender</option>
              <option value="Male" <?= $gender==='Male'?'selected':'' ?>>Male</option>
              <option value="Female" <?= $gender==='Female'?'selected':'' ?>>Female</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label required">Birth Date</label>
            <input type="date" name="birth_date" class="form-control" value="<?= htmlspecialchars($birth_date) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label required">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($phone) ?>" required onkeypress="return allowNumbersOnly(event)" placeholder="Enter phone number">
            <div class="form-text">Only numbers allowed</div>
          </div>
          <div class="form-group">
            <label class="form-label required">Address</label>
            <textarea name="address" class="form-control" rows="2" required placeholder="Enter complete address"><?= htmlspecialchars($address) ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Guardian</label>
            <input type="text" name="guardian" class="form-control" value="<?= htmlspecialchars($guardian) ?>" onkeypress="return allowLettersOnly(event)" placeholder="Enter guardian name">
            <div class="form-text">Only letters and spaces allowed (optional)</div>
          </div>
          <div class="form-group">
            <label class="form-label">Blood Type</label>
            <select name="blood_type" class="form-control">
              <option value="">Select Blood Type</option>
              <option value="A+" <?= $blood_type==='A+'?'selected':'' ?>>A+</option>
              <option value="A-" <?= $blood_type==='A-'?'selected':'' ?>>A-</option>
              <option value="B+" <?= $blood_type==='B+'?'selected':'' ?>>B+</option>
              <option value="B-" <?= $blood_type==='B-'?'selected':'' ?>>B-</option>
              <option value="AB+" <?= $blood_type==='AB+'?'selected':'' ?>>AB+</option>
              <option value="AB-" <?= $blood_type==='AB-'?'selected':'' ?>>AB-</option>
              <option value="O+" <?= $blood_type==='O+'?'selected':'' ?>>O+</option>
              <option value="O-" <?= $blood_type==='O-'?'selected':'' ?>>O-</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Weight (kg)</label>
            <input type="number" step="0.1" name="weight" class="form-control" value="<?= htmlspecialchars($weight) ?>" onkeypress="return allowNumbersAndDecimal(event)" placeholder="Enter weight in kg">
          </div>
          <div class="form-group">
            <label class="form-label">Height (m)</label>
            <input type="number" step="0.01" name="height" class="form-control" value="<?= htmlspecialchars($height) ?>" onkeypress="return allowNumbersAndDecimal(event)" placeholder="Enter height in meters">
          </div>
          <div class="form-group">
            <label class="form-label">Pulse Rate (bpm)</label>
            <input type="number" name="pulse_rate" class="form-control" value="<?= htmlspecialchars($pulse_rate) ?>" onkeypress="return allowNumbersOnly(event)" placeholder="Enter pulse rate">
          </div>
          <div class="form-group">
            <label class="form-label">Temperature (°C)</label>
            <input type="number" step="0.1" name="temperature" class="form-control" value="<?= htmlspecialchars($temperature) ?>" onkeypress="return allowNumbersAndDecimal(event)" placeholder="Enter temperature">
          </div>
        </div>

        <!-- NEW: Initial Findings field (optional) -->
        <div class="form-group" style="margin-top: 20px;">
          <label class="form-label">Initial Findings (Optional)</label>
          <textarea name="initial_findings" class="form-control" rows="3" placeholder="Enter any initial observations (will be used in triage)"><?= htmlspecialchars($initial_findings) ?></textarea>
          <div class="form-text">This information will be available when you triage the patient.</div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn-submit"><?= $edit ? "Update Patient" : "Add Patient" ?></button>
          <a href="patients.php" class="btn-cancel">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function allowLettersOnly(e) {
  var char = String.fromCharCode(e.which);
  if (!/^[a-zA-Z\s]+$/.test(char) && e.which !== 8 && e.which !== 9 && e.which !== 13 && e.which !== 46) return false;
  return true;
}
function allowNumbersOnly(e) {
  var char = String.fromCharCode(e.which);
  if (!/^[0-9]+$/.test(char) && e.which !== 8 && e.which !== 9 && e.which !== 13 && e.which !== 46) return false;
  return true;
}
function allowNumbersAndDecimal(e) {
  var char = String.fromCharCode(e.which);
  if (!/^[0-9.]$/.test(char) && e.which !== 8 && e.which !== 9 && e.which !== 13 && e.which !== 46) return false;
  if (char === '.' && e.target.value.includes('.')) return false;
  return true;
}
function validateForm() {
  var firstName = document.querySelector('input[name="first_name"]').value;
  var lastName = document.querySelector('input[name="last_name"]').value;
  var phone = document.querySelector('input[name="phone"]').value;
  var guardian = document.querySelector('input[name="guardian"]').value;

  var nameRegex = /^[A-Za-z\s]+$/;
  if (!nameRegex.test(firstName)) { showToast('First name should contain only letters and spaces.', 'error'); return false; }
  if (!nameRegex.test(lastName)) { showToast('Last name should contain only letters and spaces.', 'error'); return false; }
  if (!/^[0-9]+$/.test(phone)) { showToast('Phone number should contain only numbers.', 'error'); return false; }
  if (guardian && !nameRegex.test(guardian)) { showToast('Guardian name should contain only letters and spaces.', 'error'); return false; }
  return true;
}
function showToast(msg, type) {
  // optional toast function (can be omitted if not needed)
  alert(msg); // simple fallback
}
</script>
</body>
</html>