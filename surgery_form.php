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

// Initialize variables
$id = $patient_id = $doctor_id = $surgery_type = $schedule_date = $operating_room = "";
$status = "Scheduled";
$edit = false;
$error_message = "";

// Get patients for dropdown
$patients = fetchAll($conn, "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, patient_code FROM patients WHERE is_archived = 0 ORDER BY last_name, first_name");

// Get doctors for dropdown
$doctors = fetchAll($conn, "SELECT id, full_name FROM users WHERE role = 'Doctor' AND is_active = 1 ORDER BY full_name");

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
    }
}

// Check if created_by column exists
$has_created_by = fetchOne($conn, "SHOW COLUMNS FROM surgeries LIKE 'created_by'") !== null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $surgery_type = trim($_POST['surgery_type'] ?? '');
    $schedule_date = trim($_POST['schedule_date'] ?? '');
    $operating_room = trim($_POST['operating_room'] ?? '');
    $new_status = trim($_POST['status'] ?? 'Scheduled');
    $status_reason = trim($_POST['status_reason'] ?? '');

    $errors = [];

    if (empty($patient_id)) $errors[] = "Please select a patient.";
    if (empty($doctor_id)) $errors[] = "Please select a doctor.";
    if (empty($surgery_type)) $errors[] = "Please enter surgery type.";
    if (empty($schedule_date)) $errors[] = "Please select a schedule date.";
    else {
        $schedule_time = strtotime($schedule_date);
        $today = strtotime(date('Y-m-d'));
        if ($schedule_time < $today) $errors[] = "Schedule date cannot be in the past.";
    }

    // If editing, check status change permissions
    if ($id) {
        $current_surgery = fetchOne($conn, "SELECT status, doctor_id FROM surgeries WHERE id = ?", "i", [$id]);
        $current_status = $current_surgery['status'] ?? 'Scheduled';
        $surgery_doctor = $current_surgery['doctor_id'] ?? 0;

        $is_owner = ($surgery_doctor == $current_user_id);
        $can_approve = ($current_role === 'Admin') || ($current_role === 'Doctor' && $is_owner);
        $can_cancel  = ($current_role === 'Admin') || ($current_role === 'Doctor' && $is_owner) ||
                       ($current_role === 'Nurse');
        $can_complete = ($current_role === 'Admin') || ($current_role === 'Doctor' && $is_owner) ||
                        ($current_role === 'Nurse');

        $allowed = [];
        if ($current_status === 'Scheduled') {
            if ($can_approve) $allowed[] = 'Approved';
            if ($can_cancel) $allowed[] = 'Cancelled';
        } elseif ($current_status === 'Approved') {
            if ($can_complete) $allowed[] = 'Completed';
            if ($can_cancel) $allowed[] = 'Cancelled';
        } else {
            $allowed = [$current_status];
        }

        if (!in_array($new_status, $allowed)) {
            $errors[] = "Invalid status change.";
        }

        if (($new_status === 'Cancelled' || $new_status === 'Completed') && empty($status_reason)) {
            $errors[] = "Please provide a reason for " . strtolower($new_status) . ".";
        }
    }

    if (empty($errors)) {
        if ($id) {
            // Build update query dynamically
            $fields = ["patient_id = ?", "doctor_id = ?", "surgery_type = ?", "schedule_date = ?", "operating_room = ?", "status = ?"];
            $params = [$patient_id, $doctor_id, $surgery_type, $schedule_date, $operating_room, $new_status];
            $types = "iissss";

            if ($new_status !== $current_status) {
                if ($new_status === 'Approved') {
                    $fields[] = "approved_by = ?";
                    $fields[] = "approved_at = NOW()";
                    $params[] = $current_user_id;
                    $types .= "i";
                } elseif ($new_status === 'Cancelled') {
                    $fields[] = "cancelled_by = ?";
                    $fields[] = "cancelled_at = NOW()";
                    $fields[] = "cancellation_reason = ?";
                    $params[] = $current_user_id;
                    $params[] = $status_reason;
                    $types .= "is";
                } elseif ($new_status === 'Completed') {
                    $fields[] = "completed_by = ?";
                    $fields[] = "completed_at = NOW()";
                    $fields[] = "completion_reason = ?";
                    $params[] = $current_user_id;
                    $params[] = $status_reason;
                    $types .= "is";
                }
            }

            $params[] = $id;
            $types .= "i";
            $sql = "UPDATE surgeries SET " . implode(", ", $fields) . " WHERE id = ?";
            $result = execute($conn, $sql, $types, $params);
            $surgery_id = $id;
            $action = 'updated';
        } else {
            // Insert new surgery
            if ($has_created_by) {
                $params = [$patient_id, $doctor_id, $surgery_type, $schedule_date, $operating_room, 'Scheduled', $_SESSION['user_id']];
                $types = "iissssi";
                $sql = "INSERT INTO surgeries (patient_id, doctor_id, surgery_type, schedule_date, operating_room, status, created_by) VALUES (?,?,?,?,?,?,?)";
            } else {
                $params = [$patient_id, $doctor_id, $surgery_type, $schedule_date, $operating_room, 'Scheduled'];
                $types = "iissss";
                $sql = "INSERT INTO surgeries (patient_id, doctor_id, surgery_type, schedule_date, operating_room, status) VALUES (?,?,?,?,?,?)";
            }
            $result = execute($conn, $sql, $types, $params);
            $surgery_id = $conn->insert_id;
            $action = 'added';
        }

        if (!isset($result['error'])) {
            header("Location: surgeries.php?success=$action&surgery_id=$surgery_id");
            exit();
        } else {
            $error_message = "<div class='alert alert-error mt-2'>Error: " . $result['error'] . "</div>";
        }
    } else {
        $error_message = "";
        foreach ($errors as $error) {
            $error_message .= "<div class='alert alert-error mt-2'>$error</div>";
        }
    }
}

function getAllowedStatuses($current_status, $role, $is_owner) {
    $allowed = [];
    $can_approve = ($role === 'Admin') || ($role === 'Doctor' && $is_owner);
    $can_cancel  = ($role === 'Admin') || ($role === 'Doctor' && $is_owner) ||
                   ($role === 'Nurse');
    $can_complete = ($role === 'Admin') || ($role === 'Doctor' && $is_owner) ||
                    ($role === 'Nurse');

    if ($current_status === 'Scheduled') {
        if ($can_approve) $allowed['Approved'] = 'Approve';
        if ($can_cancel) $allowed['Cancelled'] = 'Cancel';
    } elseif ($current_status === 'Approved') {
        if ($can_complete) $allowed['Completed'] = 'Complete';
        if ($can_cancel) $allowed['Cancelled'] = 'Cancel';
    }
    return $allowed;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?= $edit ? "Edit Surgery" : "Add Surgery" ?></title>
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
  .btn-secondary {
    background:#6c757d;
    color:#fff;
  }
  .btn-secondary:hover {
    background:#5a6268;
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

  /* Form Card */
  .form-card{
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    max-width:700px;
    border:1px solid #f0f4f8;
  }
  .form-group{
    margin-bottom:20px;
  }
  .form-label{
    display:block;
    margin-bottom:8px;
    font-weight:600;
    color:#1e3a5f;
  }
  .form-label.required:after{
    content:' *';
    color:#e53e3e;
  }
  .form-control, .form-textarea, .form-select{
    width:100%;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    font-family:Inter, sans-serif;
    font-size:14px;
    background:#fff;
  }
  .form-control:focus, .form-textarea:focus, .form-select:focus{
    outline:none;
    border-color:var(--navy-700);
    box-shadow:0 0 0 2px rgba(0,31,63,0.1);
  }
  .form-textarea{ min-height:100px; resize:vertical; }
  .readonly-status{
    background:#f8fbfd;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
  }

  /* Searchable select */
  .searchable-select{ position:relative; }
  .search-input{
    width:100%;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    margin-bottom:5px;
    background:#fff;
  }
  .dropdown-list{
    position:absolute;
    top:100%;
    left:0;
    right:0;
    max-height:200px;
    overflow-y:auto;
    background:white;
    border:1px solid #e6eef0;
    border-radius:8px;
    box-shadow:0 4px 12px rgba(0,0,0,0.1);
    z-index:1000;
    display:none;
  }
  .dropdown-list.show{ display:block; }
  .dropdown-item{
    padding:10px 12px;
    cursor:pointer;
    transition:background 0.2s;
  }
  .dropdown-item:hover{ background:#f8fbfd; }
  .dropdown-item.selected{
    background:#e8f4ff;
    color:var(--navy-700);
    font-weight:600;
  }
  .selected-display{
    width:100%;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    background:#fff;
    cursor:pointer;
    display:flex;
    justify-content:space-between;
    align-items:center;
  }
  .selected-display::after{
    content:"▼";
    font-size:12px;
    color:var(--muted);
  }
  .hidden-select{ display:none; }

  .status-pill {
    display:inline-block;
    padding:6px 12px;
    border-radius:16px;
    font-weight:600;
    font-size:13px;
  }
  .status-pill.scheduled{ background:#fff8e1; color:#8a6d00; }
  .status-pill.approved{ background:#e8f4ff; color:#1e6b8a; }
  .status-pill.completed{ background:#d1fae5; color:#065f46; }
  .status-pill.cancelled{ background:#fee2e2; color:#b91c1c; }

  .reason-field{
    margin-top:10px;
    display:none;
  }

  .form-actions{
    display:flex;
    gap:12px;
    margin-top:30px;
    padding-top:20px;
    border-top:1px solid #f0f3f4;
  }

  .alert{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:10px;
    color:white;
    font-weight:500;
  }
  .alert-error{ background:#e53e3e; border-left:4px solid #c53030; }

  @media (max-width:780px){
    .sidebar{ left:-320px; }
    .sidebar.open{ left:0; }
    .main{ margin-left:0; padding:12px; }
    .form-actions{ flex-direction:column; }
    .btn{ width:100%; text-align:center; }
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
        <h1><?= $edit ? "Edit Surgery" : "Add Surgery" ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Schedule and manage surgeries</p>
      </div>
      <div class="top-actions">
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div class="alert-container">
      <?php if(isset($error_message)) echo $error_message; ?>
    </div>

    <div class="form-card">
      <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="id" value="<?= h($id) ?>">

        <!-- Patient -->
        <div class="form-group">
          <label class="form-label required">Patient</label>
          <div class="searchable-select">
            <select name="patient_id" class="hidden-select" required>
              <option value="">Select Patient</option>
              <?php
              $selected_patient_text = '';
              foreach($patients as $patient):
                $selected = $patient_id == $patient['id'];
                if ($selected) $selected_patient_text = h($patient['full_name']) . " (" . h($patient['patient_code']) . ")";
              ?>
                <option value="<?= h($patient['id']) ?>" <?= $selected ? 'selected' : '' ?> data-display="<?= h($patient['full_name'] . " (" . $patient['patient_code'] . ")") ?>">
                  <?= h($patient['full_name']) ?> (<?= h($patient['patient_code']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="selected-display" onclick="toggleDropdown('patient')" id="patient-display">
              <?= $selected_patient_text ?: 'Select Patient' ?>
            </div>
            <input type="text" class="search-input" placeholder="Search patients..." onkeyup="filterOptions('patient')" id="patient-search">
            <div class="dropdown-list" id="patient-list">
              <?php foreach($patients as $patient): ?>
                <div class="dropdown-item" data-value="<?= h($patient['id']) ?>" onclick="selectOption('patient', this)" <?= $patient_id == $patient['id'] ? 'data-selected="true"' : '' ?>>
                  <?= h($patient['full_name']) ?> (<?= h($patient['patient_code']) ?>)
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Doctor -->
        <div class="form-group">
          <label class="form-label required">Doctor</label>
          <div class="searchable-select">
            <select name="doctor_id" class="hidden-select" required>
              <option value="">Select Doctor</option>
              <?php
              $selected_doctor_text = '';
              foreach($doctors as $doctor):
                $selected = $doctor_id == $doctor['id'];
                if ($selected) $selected_doctor_text = h($doctor['full_name']);
              ?>
                <option value="<?= h($doctor['id']) ?>" <?= $selected ? 'selected' : '' ?> data-display="<?= h($doctor['full_name']) ?>">
                  <?= h($doctor['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="selected-display" onclick="toggleDropdown('doctor')" id="doctor-display">
              <?= $selected_doctor_text ?: 'Select Doctor' ?>
            </div>
            <input type="text" class="search-input" placeholder="Search doctors..." onkeyup="filterOptions('doctor')" id="doctor-search">
            <div class="dropdown-list" id="doctor-list">
              <?php foreach($doctors as $doctor): ?>
                <div class="dropdown-item" data-value="<?= h($doctor['id']) ?>" onclick="selectOption('doctor', this)" <?= $doctor_id == $doctor['id'] ? 'data-selected="true"' : '' ?>>
                  <?= h($doctor['full_name']) ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Surgery Type -->
        <div class="form-group">
          <label class="form-label required">Surgery Type</label>
          <input type="text" name="surgery_type" class="form-control" value="<?= h($surgery_type) ?>" required>
        </div>

        <!-- Schedule Date -->
        <div class="form-group">
          <label class="form-label required">Schedule Date</label>
          <input type="date" name="schedule_date" class="form-control"
                 value="<?= h($schedule_date) ?>"
                 min="<?= date('Y-m-d') ?>" required>
        </div>

        <!-- Operating Room -->
        <div class="form-group">
          <label class="form-label">Operating Room</label>
          <input type="text" name="operating_room" class="form-control" value="<?= h($operating_room) ?>">
        </div>

        <!-- Status (only when editing) -->
        <?php if ($edit): 
          $is_owner = ($doctor_id == $current_user_id);
          $allowed_statuses = getAllowedStatuses($status, $current_role, $is_owner);
        ?>
        <div class="form-group">
          <label class="form-label">Change Status</label>
          <?php if (!empty($allowed_statuses)): ?>
            <select name="status" id="statusSelect" class="form-select">
              <option value="<?= $status ?>" selected><?= $status ?></option>
              <?php foreach ($allowed_statuses as $val => $label): ?>
                <option value="<?= $val ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <div class="readonly-status">
              <span class="status-pill <?= strtolower($status) ?>"><?= $status ?></span>
              <small class="muted"> (Cannot be changed)</small>
            </div>
          <?php endif; ?>

          <!-- Reason field for Cancel/Complete -->
          <div id="statusReasonGroup" class="form-group reason-field">
            <label for="status_reason">Reason for change:</label>
            <textarea name="status_reason" id="status_reason" class="form-textarea" rows="2" placeholder="Enter reason..."></textarea>
          </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
          <button type="submit" class="btn"><?= $edit ? "Update" : "Add" ?> Surgery</button>
          <a href="surgeries.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
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
    search.focus();
  }

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
    items[i].style.display = text.toUpperCase().indexOf(filter) > -1 ? '' : 'none';
  }
}

function selectOption(type, element) {
  const value = element.getAttribute('data-value');
  const text = element.textContent;
  const display = document.getElementById(`${type}-display`);
  const hiddenSelect = document.querySelector(`select[name="${type}_id"]`);

  display.textContent = text;
  hiddenSelect.value = value;

  const items = document.getElementById(`${type}-list`).getElementsByClassName('dropdown-item');
  for (let i = 0; i < items.length; i++) {
    items[i].classList.remove('selected');
    items[i].removeAttribute('data-selected');
  }
  element.classList.add('selected');
  element.setAttribute('data-selected', 'true');

  document.getElementById(`${type}-list`).classList.remove('show');
  openDropdown = null;
}

// Show/hide reason field when status changes
document.addEventListener('DOMContentLoaded', function() {
  const statusSelect = document.getElementById('statusSelect');
  const reasonGroup = document.getElementById('statusReasonGroup');
  if (statusSelect && reasonGroup) {
    function toggleReason() {
      const val = statusSelect.value;
      if (val === 'Cancelled' || val === 'Completed') {
        reasonGroup.style.display = 'block';
      } else {
        reasonGroup.style.display = 'none';
      }
    }
    statusSelect.addEventListener('change', toggleReason);
    toggleReason(); // initial
  }

  // Highlight selected items
  ['patient', 'doctor'].forEach(type => {
    const items = document.getElementById(`${type}-list`).getElementsByClassName('dropdown-item');
    for (let i = 0; i < items.length; i++) {
      if (items[i].getAttribute('data-selected') === 'true') {
        items[i].classList.add('selected');
      }
    }
  });
});

function validateForm() {
  const patientId = document.querySelector('select[name="patient_id"]').value;
  const doctorId = document.querySelector('select[name="doctor_id"]').value;
  const surgeryType = document.querySelector('input[name="surgery_type"]').value.trim();
  const scheduleDate = document.querySelector('input[name="schedule_date"]').value;

  if (!patientId) { alert('Please select a patient.'); return false; }
  if (!doctorId) { alert('Please select a doctor.'); return false; }
  if (!surgeryType) { alert('Please enter surgery type.'); return false; }
  if (!scheduleDate) { alert('Please select a schedule date.'); return false; }
  if (new Date(scheduleDate) < new Date().setHours(0,0,0,0)) {
    alert('Schedule date cannot be in the past.');
    return false;
  }

  // If status changed to Cancel/Complete, ensure reason is provided
  const statusSelect = document.getElementById('statusSelect');
  if (statusSelect) {
    const newStatus = statusSelect.value;
    const reasonField = document.getElementById('status_reason');
    if ((newStatus === 'Cancelled' || newStatus === 'Completed') && (!reasonField.value.trim())) {
      alert('Please provide a reason for ' + newStatus.toLowerCase() + '.');
      reasonField.focus();
      return false;
    }
  }
  return true;
}
</script>
</body>
</html>