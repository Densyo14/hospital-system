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

// Initialize variables with default values
$id = $patient_id = $doctor_id = $schedule_datetime = $reason = $notes = "";
$status = "Pending";
$edit = false;
$error_message = "";
$success_message = "";

// Get patients and doctors
$patients = fetchAll($conn, "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, patient_code FROM patients WHERE is_archived = 0 ORDER BY last_name, first_name");
$doctors = fetchAll($conn, "SELECT id, full_name FROM users WHERE role = 'Doctor' AND is_active = 1 ORDER BY full_name");

// Check if we're restoring from a previous POST (validation errors)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submitted'])) {
    // Restore form values from POST data
    $id = (int)($_POST['id'] ?? 0);
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $schedule_datetime = trim($_POST['schedule_datetime'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = trim($_POST['status'] ?? 'Pending');
    $edit = ($id > 0);
}

// Check if editing (GET request)
if (!$edit && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $appointment = fetchOne($conn, "SELECT * FROM appointments WHERE id = ?", "i", [$id]);
    if ($appointment) {
        $edit = true;
        $patient_id = $appointment['patient_id'] ?? '';
        $doctor_id = $appointment['doctor_id'] ?? '';
        $schedule_datetime = $appointment['schedule_datetime'] ?? '';
        $reason = $appointment['reason'] ?? '';
        $notes = $appointment['notes'] ?? '';
        $status = $appointment['status'] ?? 'Pending';
        
        // Check if user has permission to edit this appointment
        if ($current_role === 'Doctor' && $doctor_id != $current_user_id) {
            header("Location: appointments.php?error=You can only edit your own appointments");
            exit();
        }
    } else {
        header("Location: appointments.php?error=Appointment not found");
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_submitted'])) {
    // Get form data (already restored above, but get again to be safe)
    $id = (int)($_POST['id'] ?? 0);
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $schedule_datetime = trim($_POST['schedule_datetime'] ?? '');
    $reason_text = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $new_status = trim($_POST['status'] ?? 'Pending');
    $status_reason = trim($_POST['status_reason'] ?? '');

    // Validate input
    $errors = [];
    
    if (empty($patient_id)) {
        $errors[] = "Please select a patient.";
    }
    
    if (empty($doctor_id)) {
        $errors[] = "Please select a doctor.";
    }
    
    if (empty($schedule_datetime)) {
        $errors[] = "Please select a schedule date and time.";
    } else {
        $schedule_time = strtotime($schedule_datetime);
        $current_time = time();
        if ($schedule_time < $current_time - 300) { // Allow 5 minutes grace period
            $errors[] = "Schedule cannot be in the past.";
        }
    }
    
    if (empty($reason_text)) {
        $errors[] = "Please provide a reason for the appointment.";
    }

    // Check for scheduling conflicts (excluding current appointment if editing)
    if (empty($errors)) {
        $conflict_query = "SELECT id FROM appointments WHERE doctor_id = ? AND schedule_datetime = ? AND is_archived = 0";
        $params = [$doctor_id, $schedule_datetime];
        $types = "is";
        
        if ($id > 0) {
            $conflict_query .= " AND id != ?";
            $params[] = $id;
            $types .= "i";
        }
        
        $conflict = fetchOne($conn, $conflict_query, $types, $params);
        if ($conflict) {
            $errors[] = "This doctor already has an appointment scheduled at this time.";
        }
    }

    // If editing, validate status change
    if ($id > 0) {
        $current_appt = fetchOne($conn, "SELECT status, doctor_id FROM appointments WHERE id = ?", "i", [$id]);
        if ($current_appt) {
            $current_status = $current_appt['status'];
            $appt_doctor = $current_appt['doctor_id'];
            
            // Check if status is being changed
            if ($new_status !== $current_status) {
                $is_owner = ($appt_doctor == $current_user_id);
                
                // Define permissions
                $can_approve = ($current_role === 'Admin') || ($current_role === 'Doctor' && $is_owner);
                $can_cancel = ($current_role === 'Admin') || 
                             ($current_role === 'Doctor' && $is_owner) ||
                             ($current_role === 'Nurse') || 
                             ($current_role === 'Staff');
                $can_complete = ($current_role === 'Admin') || 
                               ($current_role === 'Doctor' && $is_owner) ||
                               ($current_role === 'Nurse');

                // Validate allowed transitions
                $allowed = false;
                if ($current_status === 'Pending') {
                    if ($new_status === 'Approved' && $can_approve) $allowed = true;
                    if ($new_status === 'Cancelled' && $can_cancel) $allowed = true;
                } elseif ($current_status === 'Approved') {
                    if ($new_status === 'Completed' && $can_complete) $allowed = true;
                    if ($new_status === 'Cancelled' && $can_cancel) $allowed = true;
                } elseif ($current_status === 'Completed' || $current_status === 'Cancelled') {
                    $errors[] = "Cannot change status of completed or cancelled appointments.";
                }

                if (!$allowed && $new_status !== $current_status) {
                    $errors[] = "You don't have permission to change status to " . $new_status;
                }

                // If changing to Cancelled or Completed, reason is required
                if (($new_status === 'Cancelled' || $new_status === 'Completed') && empty($status_reason)) {
                    $errors[] = "Please provide a reason for " . strtolower($new_status) . ".";
                }
            }
        }
    }

    // If no errors, proceed with save/update
    if (empty($errors)) {
        if ($id > 0) {
            // First update the basic appointment details
            $sql = "UPDATE appointments SET 
                    patient_id = ?, 
                    doctor_id = ?, 
                    schedule_datetime = ?, 
                    reason = ?, 
                    notes = ? 
                    WHERE id = ?";
            
            $params = [$patient_id, $doctor_id, $schedule_datetime, $reason_text, $notes, $id];
            $types = "iisssi";
            
            $result = execute($conn, $sql, $types, $params);
            
            // Then handle status change if needed
            if ($new_status !== $current_status) {
                if ($new_status === 'Approved') {
                    execute($conn, "UPDATE appointments SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?", "ii", [$current_user_id, $id]);
                } elseif ($new_status === 'Cancelled') {
                    execute($conn, "UPDATE appointments SET status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ? WHERE id = ?", "isi", [$current_user_id, $status_reason, $id]);
                } elseif ($new_status === 'Completed') {
                    execute($conn, "UPDATE appointments SET status = 'Completed', completed_by = ?, completed_at = NOW(), completion_reason = ? WHERE id = ?", "isi", [$current_user_id, $status_reason, $id]);
                }
            }
            
            $action = 'updated';
        } else {
            // Insert new appointment
            $sql = "INSERT INTO appointments (patient_id, doctor_id, schedule_datetime, reason, notes, status, created_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'Pending', ?, NOW())";
            
            $params = [$patient_id, $doctor_id, $schedule_datetime, $reason_text, $notes, $current_user_id];
            $types = "iisssi";
            
            $result = execute($conn, $sql, $types, $params);
            $id = $conn->insert_id;
            $action = 'added';
        }

        if (!isset($result['error'])) {
            header("Location: appointments.php?success=$action&appointment_id=$id");
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

// Helper function to get allowed status transitions for display
function getAllowedStatuses($current_status, $role, $is_owner) {
    $allowed = [];
    $can_approve = ($role === 'Admin') || ($role === 'Doctor' && $is_owner);
    $can_cancel  = ($role === 'Admin') || ($role === 'Doctor' && $is_owner) ||
                   ($role === 'Nurse') || ($role === 'Staff');
    $can_complete = ($role === 'Admin') || ($role === 'Doctor' && $is_owner) ||
                    ($role === 'Nurse');

    if ($current_status === 'Pending') {
        if ($can_approve) $allowed['Approved'] = 'Approve';
        if ($can_cancel) $allowed['Cancelled'] = 'Cancel';
    } elseif ($current_status === 'Approved') {
        if ($can_complete) $allowed['Completed'] = 'Complete';
        if ($can_cancel) $allowed['Cancelled'] = 'Cancel';
    }
    return $allowed;
}

// Helper function to safely get value for select options
function selected($value1, $value2) {
    return ($value1 == $value2) ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?= $edit ? "Edit Appointment" : "Add Appointment" ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  /* Same styles as before */
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
    cursor:pointer;
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
    transform:translateY(-1px);
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

  /* Error highlight */
  .form-control.error, .form-select.error, .form-textarea.error {
    border-color: #e53e3e;
    background-color: #fff5f5;
  }
  
  .field-error {
    color: #e53e3e;
    font-size: 12px;
    margin-top: 4px;
  }

  .status-pill {
    display:inline-block;
    padding:6px 12px;
    border-radius:16px;
    font-weight:600;
    font-size:13px;
  }
  .status-pill.pending{ background:#fff8e1; color:#8a6d00; }
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
  .alert-success{ background:#001F3F; border-left:4px solid #003366; }
  .alert-warning{ background:#f59e0b; color:#000; border-left:4px solid #d97706; }

  /* Patient highlight */
  .patient-highlight {
    background-color: #f0f9ff;
    border-left: 4px solid var(--navy-700);
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
  }

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
        <h1><?= $edit ? "Edit Appointment" : "Add Appointment" ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Schedule and manage patient appointments</p>
      </div>
      <div class="top-actions">
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div class="alert-container">
      <?php if(isset($error_message)) echo $error_message; ?>
    </div>

    <?php if ($edit && $patient_id > 0): ?>
   
    <?php endif; ?>

    <div class="form-card">
      <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="form_submitted" value="1">
        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">

        <!-- Patient -->
        <div class="form-group">
          <label class="form-label required">Patient</label>
          <select name="patient_id" id="patient_select" class="form-select" required>
            <option value="">Select Patient</option>
            <?php foreach($patients as $patient): ?>
              <option value="<?= htmlspecialchars($patient['id']) ?>" <?= selected($patient_id, $patient['id']) ?>>
                <?= htmlspecialchars($patient['full_name']) ?> (<?= htmlspecialchars($patient['patient_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($edit && $patient_id > 0): ?>
          <small class="field-error" id="patient_error" style="display:none;">Patient selection is required</small>
          <?php endif; ?>
        </div>

        <!-- Doctor -->
        <div class="form-group">
          <label class="form-label required">Doctor</label>
          <select name="doctor_id" class="form-select" required>
            <option value="">Select Doctor</option>
            <?php foreach($doctors as $doctor): ?>
              <option value="<?= htmlspecialchars($doctor['id']) ?>" <?= selected($doctor_id, $doctor['id']) ?>>
                <?= htmlspecialchars($doctor['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Schedule -->
        <div class="form-group">
          <label class="form-label required">Schedule Date & Time</label>
          <input type="datetime-local" name="schedule_datetime" class="form-control"
                 value="<?= $schedule_datetime ? date('Y-m-d\TH:i', strtotime($schedule_datetime)) : '' ?>"
                 min="<?= date('Y-m-d\TH:i') ?>" required>
          <small class="muted">Select a future date and time</small>
        </div>

        <!-- Reason -->
        <div class="form-group">
          <label class="form-label required">Reason</label>
          <textarea name="reason" class="form-textarea" rows="3" placeholder="Enter reason for appointment" required><?= htmlspecialchars($reason) ?></textarea>
        </div>

        <!-- Notes -->
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-textarea" rows="3" placeholder="Additional notes about the appointment"><?= htmlspecialchars($notes) ?></textarea>
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
              <option value="<?= htmlspecialchars($status) ?>" selected>Current: <?= htmlspecialchars($status) ?></option>
              <?php foreach ($allowed_statuses as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>">Change to: <?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <div class="readonly-status">
              <span class="status-pill <?= strtolower($status) ?>"><?= htmlspecialchars($status) ?></span>
              <small class="muted"> (Cannot be changed)</small>
              <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            </div>
          <?php endif; ?>

          <!-- Reason field for Cancel/Complete -->
          <div id="statusReasonGroup" class="form-group reason-field">
            <label for="status_reason" class="form-label required">Reason for change:</label>
            <textarea name="status_reason" id="status_reason" class="form-textarea" rows="2" placeholder="Enter reason..."></textarea>
          </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
          <button type="submit" class="btn" id="submitBtn"><?= $edit ? "Update" : "Add" ?> Appointment</button>
          <a href="appointments.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Show/hide reason field when status changes
document.addEventListener('DOMContentLoaded', function() {
  const statusSelect = document.getElementById('statusSelect');
  const reasonGroup = document.getElementById('statusReasonGroup');
  
  if (statusSelect && reasonGroup) {
    function toggleReason() {
      const val = statusSelect.value;
      // Only show reason field for Cancel or Complete
      if (val === 'Cancelled' || val === 'Completed') {
        reasonGroup.style.display = 'block';
        document.getElementById('status_reason').required = true;
      } else {
        reasonGroup.style.display = 'none';
        document.getElementById('status_reason').required = false;
      }
    }
    statusSelect.addEventListener('change', toggleReason);
    toggleReason(); // initial check
  }

  // Set default datetime for new appointments
  const scheduleInput = document.querySelector('input[name="schedule_datetime"]');
  if (scheduleInput && !scheduleInput.value && !<?= $edit ? 'true' : 'false' ?>) {
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(9, 0, 0, 0); // Set to 9:00 AM next day
    
    const year = tomorrow.getFullYear();
    const month = String(tomorrow.getMonth() + 1).padStart(2, '0');
    const day = String(tomorrow.getDate()).padStart(2, '0');
    const hours = String(tomorrow.getHours()).padStart(2, '0');
    const minutes = String(tomorrow.getMinutes()).padStart(2, '0');
    
    scheduleInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
  }

  // Log the current patient ID to console for debugging
  const patientSelect = document.getElementById('patient_select');
  if (patientSelect) {
    console.log('Current patient ID:', patientSelect.value);
  }
});

function validateForm() {
  const patientSelect = document.querySelector('select[name="patient_id"]');
  const patientId = patientSelect.value;
  const doctorId = document.querySelector('select[name="doctor_id"]').value;
  const schedule = document.querySelector('input[name="schedule_datetime"]').value;
  const reason = document.querySelector('textarea[name="reason"]').value.trim();

  // Log values for debugging
  console.log('Patient ID before submit:', patientId);
  
  if (!patientId) { 
    alert('Please select a patient.'); 
    patientSelect.focus();
    patientSelect.classList.add('error');
    return false; 
  }
  
  if (!doctorId) { 
    alert('Please select a doctor.'); 
    document.querySelector('select[name="doctor_id"]').focus();
    return false; 
  }
  
  if (!schedule) { 
    alert('Please select a schedule date and time.'); 
    document.querySelector('input[name="schedule_datetime"]').focus();
    return false; 
  }
  
  const scheduleDate = new Date(schedule);
  const now = new Date();
  if (scheduleDate < now) { 
    alert('Schedule cannot be in the past.'); 
    return false; 
  }
  
  if (!reason) { 
    alert('Please provide a reason.'); 
    document.querySelector('textarea[name="reason"]').focus();
    return false; 
  }

  // If status changed to Cancel/Complete, ensure reason is provided
  const statusSelect = document.getElementById('statusSelect');
  if (statusSelect) {
    const newStatus = statusSelect.value;
    const reasonField = document.getElementById('status_reason');
    if ((newStatus === 'Cancelled' || newStatus === 'Completed') && (!reasonField.value || !reasonField.value.trim())) {
      alert('Please provide a reason for ' + newStatus.toLowerCase() + '.');
      reasonField.focus();
      return false;
    }
  }
  
  // Final confirmation
  return confirm('Are you sure you want to save these changes?');
}
</script>
</body>
</html>