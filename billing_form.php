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

// Define role permissions
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

$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Check permissions - only Admin, Billing can create/edit bills
$allowed_roles = ['Admin', 'Billing'];
if (!in_array($current_role, $allowed_roles)) {
    header("Location: billing.php?success=error&message=Unauthorized");
    exit();
}

// Initialize variables
$id = $patient_id = $surgery_id = $total_amount = $philhealth_coverage = $hmo_coverage = $amount_due = $status = "";
$financial_assessment_id = null;
$edit = false;
$error_message = "";

// Check if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $bill = fetchOne($conn, 
        "SELECT b.*, p.first_name, p.last_name, p.patient_code, s.surgery_type,
                fa.id as fa_id, fa.assessment_type, fa.status as fa_status
         FROM billing b 
         LEFT JOIN patients p ON b.patient_id = p.id 
         LEFT JOIN surgeries s ON b.surgery_id = s.id
         LEFT JOIN financial_assessment fa ON b.financial_assessment_id = fa.id
         WHERE b.id = ?", 
        "i", 
        [$id]
    );
    if ($bill) {
        $edit = true;
        $patient_id = $bill['patient_id'] ?? '';
        $surgery_id = $bill['surgery_id'] ?? '';
        $financial_assessment_id = $bill['financial_assessment_id'] ?? null;
        $total_amount = $bill['total_amount'] ?? '';
        $philhealth_coverage = $bill['philhealth_coverage'] ?? 0;
        $hmo_coverage = $bill['hmo_coverage'] ?? 0;
        $amount_due = $bill['amount_due'] ?? '';
        $status = $bill['status'] ?? 'Unpaid';
        $patient_info = $bill['first_name'] . ' ' . $bill['last_name'] . ' (' . $bill['patient_code'] . ')';
        $surgery_info = $bill['surgery_type'] ?? 'N/A';
    } else {
        header("Location: billing.php?success=error&message=Bill+not+found");
        exit();
    }
}

// Check if we have patient_id from URL (for new bills)
if (!$edit && isset($_GET['patient_id'])) {
    $patient_id = (int)$_GET['patient_id'];
    $patient = fetchOne($conn, 
        "SELECT first_name, last_name, patient_code FROM patients WHERE id = ?", 
        "i", 
        [$patient_id]
    );
    if ($patient) {
        $patient_info = $patient['first_name'] . ' ' . $patient['last_name'] . ' (' . $patient['patient_code'] . ')';
    }
}

// Check if we have bill_id from URL (for linking financial assessment)
$link_bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

// Fetch patients
$patients = fetchAll($conn, "SELECT id, first_name, last_name, patient_code FROM patients WHERE is_archived = 0 ORDER BY first_name, last_name", null, []);

// Fetch surgeries - exclude archived and cancelled
// Check if is_archived column exists
$table_check = fetchOne($conn, "SHOW COLUMNS FROM surgeries LIKE 'is_archived'");
$has_archive_column = $table_check !== null;

$surgery_query = "SELECT s.id, s.surgery_type, s.schedule_date, p.first_name, p.last_name 
                  FROM surgeries s 
                  LEFT JOIN patients p ON s.patient_id = p.id 
                  WHERE s.status != 'Cancelled' ";
if ($has_archive_column) {
    $surgery_query .= " AND (s.is_archived IS NULL OR s.is_archived = 0) ";
}
$surgery_query .= " ORDER BY s.schedule_date DESC";

$surgeries = fetchAll($conn, $surgery_query, null, []);

// Fetch approved financial assessments for the patient (if patient_id is set)
$financial_assessments = [];
if ($patient_id || ($edit && !empty($patient_id))) {
    $pid = $patient_id;
    $financial_assessments = fetchAll($conn, 
        "SELECT id, assessment_type, status, philhealth_eligible, hmo_provider, 
                CONCAT('Assessment #', id, ' - ', assessment_type, ' (', status, ')') as display_name
         FROM financial_assessment 
         WHERE patient_id = ? AND status = 'Approved' AND is_archived = 0
         ORDER BY created_at DESC", 
        "i", 
        [$pid]
    );
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    
    // Get form data
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    // Surgery: treat empty/0 as NULL
    $surgery_id = !empty($_POST['surgery_id']) ? (int)$_POST['surgery_id'] : null;
    $financial_assessment_id = !empty($_POST['financial_assessment_id']) ? (int)$_POST['financial_assessment_id'] : null;
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $status = trim($_POST['status'] ?? 'Unpaid');
    
    // If linking from financial assessment form
    if ($link_bill_id && !$edit) {
        $id = $link_bill_id;
        $bill = fetchOne($conn, "SELECT * FROM billing WHERE id = ?", "i", [$id]);
        if ($bill) {
            $edit = true;
            $patient_id = $bill['patient_id'];
        }
    }

    // Validation
    $errors = [];

    if (empty($patient_id)) {
        $errors[] = "Patient is required.";
    }

    if ($total_amount <= 0) {
        $errors[] = "Total amount must be greater than 0.";
    }

    // If financial assessment is selected, fetch its details
    $philhealth_coverage = 0;
    $hmo_coverage = 0;
    $amount_due = $total_amount;
    
    if (!empty($financial_assessment_id)) {
        $assessment = fetchOne($conn, 
            "SELECT assessment_type, philhealth_eligible, hmo_provider FROM financial_assessment WHERE id = ?", 
            "i", 
            [$financial_assessment_id]
        );
        
        if ($assessment && $assessment['philhealth_eligible']) {
            // Calculate coverage based on assessment type
            switch($assessment['assessment_type']) {
                case 'Charity':
                    $philhealth_coverage = $total_amount * 0.8; // 80% coverage
                    break;
                case 'Partial':
                    $philhealth_coverage = $total_amount * 0.5; // 50% coverage
                    break;
                case 'Paying':
                    $philhealth_coverage = $total_amount * 0.2; // 20% coverage
                    break;
            }
        }
        
        if ($assessment && !empty($assessment['hmo_provider'])) {
            // Calculate HMO coverage based on assessment type
            switch($assessment['assessment_type']) {
                case 'Charity':
                    $hmo_coverage = $total_amount * 0.2; // 20% coverage
                    break;
                case 'Partial':
                    $hmo_coverage = $total_amount * 0.3; // 30% coverage
                    break;
                case 'Paying':
                    $hmo_coverage = $total_amount * 0.1; // 10% coverage
                    break;
            }
        }
        
        // Ensure total coverage doesn't exceed total amount
        $total_coverage = $philhealth_coverage + $hmo_coverage;
        if ($total_coverage > $total_amount) {
            $ratio = $total_amount / $total_coverage;
            $philhealth_coverage *= $ratio;
            $hmo_coverage *= $ratio;
        }
        
        $amount_due = $total_amount - $philhealth_coverage - $hmo_coverage;
    }

    if (empty($errors)) {
        if ($edit) {
            // --- UPDATE existing bill, handling nullable fields ---
            $fields = [];
            $params = [];
            $types = "";

            $fields[] = "patient_id = ?";
            $params[] = $patient_id;
            $types .= "i";

            // Surgery: if null, set column to NULL
            if ($surgery_id === null) {
                $fields[] = "surgery_id = NULL";
            } else {
                $fields[] = "surgery_id = ?";
                $params[] = $surgery_id;
                $types .= "i";
            }

            // Financial assessment: if null, set column to NULL
            if ($financial_assessment_id === null) {
                $fields[] = "financial_assessment_id = NULL";
            } else {
                $fields[] = "financial_assessment_id = ?";
                $params[] = $financial_assessment_id;
                $types .= "i";
            }

            $fields[] = "total_amount = ?";
            $params[] = $total_amount;
            $types .= "d";

            $fields[] = "philhealth_coverage = ?";
            $params[] = $philhealth_coverage;
            $types .= "d";

            $fields[] = "hmo_coverage = ?";
            $params[] = $hmo_coverage;
            $types .= "d";

            $fields[] = "amount_due = ?";
            $params[] = $amount_due;
            $types .= "d";

            $fields[] = "status = ?";
            $params[] = $status;
            $types .= "s";

            // WHERE clause
            $fields[] = "id = ?";
            $params[] = $id;
            $types .= "i";

            $sql = "UPDATE billing SET " . implode(", ", $fields);
            $result = execute($conn, $sql, $types, $params);
            $bill_id = $id;

        } else {
            // --- INSERT new bill, handling nullable fields ---
            $columns = [];
            $placeholders = [];
            $params = [];
            $types = "";

            $columns[] = "patient_id";
            $placeholders[] = "?";
            $params[] = $patient_id;
            $types .= "i";

            // Surgery: only include if not null
            if ($surgery_id !== null) {
                $columns[] = "surgery_id";
                $placeholders[] = "?";
                $params[] = $surgery_id;
                $types .= "i";
            }

            // Financial assessment: only include if not null
            if ($financial_assessment_id !== null) {
                $columns[] = "financial_assessment_id";
                $placeholders[] = "?";
                $params[] = $financial_assessment_id;
                $types .= "i";
            }

            $columns[] = "total_amount";
            $placeholders[] = "?";
            $params[] = $total_amount;
            $types .= "d";

            $columns[] = "philhealth_coverage";
            $placeholders[] = "?";
            $params[] = $philhealth_coverage;
            $types .= "d";

            $columns[] = "hmo_coverage";
            $placeholders[] = "?";
            $params[] = $hmo_coverage;
            $types .= "d";

            $columns[] = "amount_due";
            $placeholders[] = "?";
            $params[] = $amount_due;
            $types .= "d";

            $columns[] = "status";
            $placeholders[] = "?";
            $params[] = $status;
            $types .= "s";

            $sql = "INSERT INTO billing (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
            $result = execute($conn, $sql, $types, $params);
            $bill_id = $conn->insert_id;
        }

        if (!isset($result['error'])) {
            $action = $edit ? 'updated' : 'added';
            
            // If this was linking from financial assessment, redirect to billing
            if ($link_bill_id) {
                header("Location: billing.php?success=assessment_linked&bill_id={$bill_id}");
            } else {
                header("Location: billing.php?success={$action}&action={$action}&bill_id={$bill_id}");
            }
            exit();
        } else {
            $error_message = "<div class='alert alert-danger mt-2'>Error: " . $result['error'] . "</div>";
        }
    } else {
        // Display validation errors
        $error_message = "";
        foreach ($errors as $error) {
            $error_message .= "<div class='alert alert-danger mt-2'>$error</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - <?php echo $edit ? "Edit Bill" : "Create New Bill"; ?></title>
<!-- Google Font -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  /* Keep your existing styles – unchanged */
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
  /* SIDEBAR (same as before) */
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
    margin-bottom:20px;
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
    cursor:pointer;
    transition:all 0.2s;
    font-size:13px;
  }
  .btn:hover{ background:var(--accent); transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,31,63,0.2); }
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
  .role-billing { background:#0066cc; color:white; }

  /* Alert */
  .alert-container{ margin-bottom:20px; }
  .alert{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:10px;
    box-shadow:0 4px 12px rgba(0,0,0,0.15);
    color:white;
    font-weight:500;
    animation:slideIn 0.3s;
  }
  .alert-success{ background:#27ae60; border-left:4px solid #1e8449; }
  .alert-error{ background:#e53e3e; border-left:4px solid #c53030; }
  .alert-info{ background:#3498db; border-left:4px solid #2980b9; }
  @keyframes slideIn{
    from{ transform:translateX(100%); opacity:0; }
    to{ transform:translateX(0); opacity:1; }
  }

  /* Form */
  .form-container{
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    max-width:700px;
    margin:0 auto;
    border:1px solid #f0f4f8;
  }
  .form-group{ margin-bottom:20px; }
  .form-label{
    display:block;
    margin-bottom:8px;
    font-weight:600;
    color:#0f1724;
  }
  .form-control, .form-select{
    width:100%;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    font-family:Inter, sans-serif;
    font-size:14px;
    background:#fff;
  }
  .form-control:focus, .form-select:focus{
    outline:none;
    border-color:var(--light-blue);
    box-shadow:0 0 0 3px rgba(77,140,201,0.1);
  }
  .form-control[readonly]{
    background:#f8fbfd;
    color:#0f1724;
    font-weight:600;
    border-color:#d1e7dd;
  }
  .required:after{
    content:" *";
    color:#e53e3e;
  }

  /* Assessment info */
  .assessment-info{
    background:#f8fbfd;
    border-radius:8px;
    padding:15px;
    margin:15px 0;
    border-left:4px solid var(--navy-700);
  }
  .assessment-info h5{
    margin:0 0 10px 0;
    color:var(--navy-700);
  }
  .coverage-preview{
    background:white;
    border-radius:6px;
    padding:15px;
    margin:10px 0;
    border:1px solid #e6eef0;
  }
  .coverage-row{
    display:flex;
    justify-content:space-between;
    margin-bottom:8px;
    padding-bottom:8px;
    border-bottom:1px solid #f0f3f4;
  }
  .coverage-row:last-child{
    border-bottom:none;
    margin-bottom:0;
    padding-bottom:0;
  }
  .coverage-label{ color:var(--muted); }
  .coverage-value{ font-weight:600; }
  .coverage-amount-due{
    font-size:18px;
    font-weight:700;
    color:#e53e3e;
  }

  .patient-display{
    background:#f8fbfd;
    border-radius:8px;
    padding:15px;
    margin-bottom:15px;
    border-left:4px solid var(--navy-700);
  }
  .patient-display h5{
    margin:0 0 10px 0;
    color:var(--navy-700);
  }
  .patient-display p{ margin:5px 0; }

  .form-actions{
    display:flex;
    gap:12px;
    margin-top:30px;
    padding-top:20px;
    border-top:1px solid #f0f3f4;
  }

  @media (max-width:780px){
    .sidebar{ left:-320px; }
    .sidebar.open{ left:0; }
    .main{ margin-left:0; padding:12px; }
    .form-actions{ flex-direction:column; }
    .btn{ width:100%; text-align:center; }
  }
  .muted{ color:var(--muted); font-size:13px; }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR (same as before) -->
  <aside class="sidebar">
    <div class="logo-wrap"><a href="dashboard.php"><img src="logo.jpg" alt="Logo"></a></div>
    <div class="user-info">
      <h4>Logged as:</h4>
      <p><?= htmlspecialchars($current_name) ?><br><strong><?= htmlspecialchars($current_role) ?></strong></p>
    </div>
    <nav class="menu">
      <a href="dashboard.php" class="menu-item">
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
              'users.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>'
            ];
            echo $icons[$page] ?? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>';
            ?>
          </span> <?= $label ?>
        </a>
      <?php endif; endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
      <a href="logout.php" class="menu-item">Logout</a>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="top-left">
        <h1><?= $edit ? "Edit Bill TX-" . $id : ($link_bill_id ? "Link Financial Assessment to Bill" : "Create New Bill"); ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Gig Oca Robles Seamen's Hospital Davao</p>
      </div>
      <div class="top-actions">
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div class="alert-container">
      <?php if(!empty($error_message)) echo $error_message; ?>
    </div>

    <div class="form-container">
      <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="id" value="<?= h($id) ?>">
        
        <?php if ($edit && !$link_bill_id): ?>
          <div class="patient-display">
            <h5>Patient Information</h5>
            <p><strong>Name:</strong> <?= htmlspecialchars($patient_info ?? '') ?></p>
            <input type="hidden" name="patient_id" value="<?= h($patient_id) ?>">
            <div class="muted">Patient cannot be changed for existing bills.</div>
          </div>
        <?php elseif ($link_bill_id): ?>
          <div class="alert alert-info">
            <strong>Linking Financial Assessment to Bill</strong><br>
            You are adding a financial assessment to bill TX-<?= h($link_bill_id) ?>
          </div>
          <?php 
          $linked_bill = fetchOne($conn, 
              "SELECT b.*, p.first_name, p.last_name, p.patient_code 
               FROM billing b 
               LEFT JOIN patients p ON b.patient_id = p.id 
               WHERE b.id = ?", 
              "i", 
              [$link_bill_id]
          );
          if ($linked_bill): ?>
            <div class="patient-display">
              <h5>Bill Information</h5>
              <p><strong>Patient:</strong> <?= htmlspecialchars($linked_bill['first_name'] . ' ' . $linked_bill['last_name'] . ' (' . $linked_bill['patient_code'] . ')') ?></p>
              <p><strong>Total Amount:</strong> ₱ <?= number_format($linked_bill['total_amount'], 2) ?></p>
              <input type="hidden" name="patient_id" value="<?= h($linked_bill['patient_id']) ?>">
              <input type="hidden" name="id" value="<?= h($link_bill_id) ?>">
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="form-group">
            <label class="form-label required">Patient</label>
            <select name="patient_id" id="patientSelect" class="form-select" required onchange="loadPatientAssessments()">
              <option value="">Select Patient</option>
              <?php foreach($patients as $patient): ?>
                <option value="<?= h($patient['id']) ?>" <?= $patient_id == $patient['id'] ? 'selected' : '' ?>>
                  <?= h($patient['first_name'] . ' ' . $patient['last_name']) ?> (<?= h($patient['patient_code']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <!-- Surgery selection -->
        <div class="form-group">
          <label class="form-label">Surgery (Optional)</label>
          <select name="surgery_id" class="form-select">
            <option value="">No Surgery</option>
            <?php foreach($surgeries as $surgery): ?>
              <option value="<?= h($surgery['id']) ?>" <?= $surgery_id == $surgery['id'] ? 'selected' : '' ?>>
                <?= h($surgery['first_name'] . ' ' . $surgery['last_name'] . ' - ' . $surgery['surgery_type']) ?>
                (<?= date('M j, Y', strtotime($surgery['schedule_date'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted mt-1">Link this bill to a specific surgery for better tracking.</div>
        </div>

        <!-- Financial Assessment -->
        <div class="form-group">
          <label class="form-label">Financial Assessment</label>
          <select name="financial_assessment_id" id="financialAssessmentSelect" class="form-select" onchange="calculateCoverage()">
            <option value="">No Financial Assessment</option>
            <?php if (!empty($financial_assessments)): ?>
              <?php foreach($financial_assessments as $fa): ?>
                <option value="<?= h($fa['id']) ?>" 
                  <?= $financial_assessment_id == $fa['id'] ? 'selected' : '' ?>
                  data-type="<?= h($fa['assessment_type']) ?>"
                  data-philhealth="<?= $fa['philhealth_eligible'] ?>"
                  data-hmo="<?= h($fa['hmo_provider'] ?? '') ?>">
                  <?= h($fa['display_name']) ?>
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <div class="muted mt-1">
            Select a financial assessment to automatically calculate coverage.
            <?php if (!$edit && !$link_bill_id): ?>
              <a href="financial_form.php" target="_blank" style="color: var(--navy-700);">Create New Assessment</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Total Amount -->
        <div class="form-group">
          <label class="form-label required">Total Amount (₱)</label>
          <input type="number" name="total_amount" id="totalAmount" class="form-control" 
                 step="0.01" min="0" required
                 value="<?= h($total_amount) ?>" oninput="calculateCoverage()">
        </div>

        <!-- Coverage Preview -->
        <div id="coveragePreview" style="display: <?= ($total_amount > 0 && !empty($financial_assessment_id)) ? 'block' : 'none'; ?>;">
          <div class="assessment-info">
            <h5>Coverage Calculation</h5>
            <div class="coverage-preview">
              <div class="coverage-row">
                <span class="coverage-label">Total Amount:</span>
                <span class="coverage-value" id="previewTotal">₱ 0.00</span>
              </div>
              <div class="coverage-row">
                <span class="coverage-label">PhilHealth Coverage:</span>
                <span class="coverage-value" id="previewPhilhealth">₱ 0.00</span>
              </div>
              <div class="coverage-row">
                <span class="coverage-label">HMO Coverage:</span>
                <span class="coverage-value" id="previewHMO">₱ 0.00</span>
              </div>
              <div class="coverage-row">
                <span class="coverage-label">Total Coverage:</span>
                <span class="coverage-value" id="previewTotalCoverage">₱ 0.00</span>
              </div>
              <div class="coverage-row" style="border-top:2px solid #e53e3e; padding-top:10px; margin-top:10px;">
                <span class="coverage-label" style="font-weight:700;">Amount Due:</span>
                <span class="coverage-amount-due" id="previewAmountDue">₱ 0.00</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Status -->
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="Unpaid" <?= $status == 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
            <option value="Paid" <?= $status == 'Paid' ? 'selected' : '' ?>>Paid</option>
          </select>
        </div>

        <!-- Actions -->
        <div class="form-actions">
          <button type="submit" class="btn">
            <?= $edit ? "Update Bill" : ($link_bill_id ? "Link Assessment" : "Create Bill") ?>
          </button>
          <a href="billing.php" class="btn btn-secondary">Cancel</a>
          <?php if ($edit && !$link_bill_id): ?>
            <a href="billing_view.php?id=<?= h($id) ?>" class="btn" style="background: var(--light-blue);">View Bill</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let currentAssessments = <?= json_encode($financial_assessments) ?>;

function loadPatientAssessments() {
    const patientId = document.getElementById('patientSelect').value;
    const assessmentSelect = document.getElementById('financialAssessmentSelect');
    
    if (!patientId) {
        assessmentSelect.innerHTML = '<option value="">No Financial Assessment</option>';
        return;
    }
    
    fetch('get_patient_assessments.php?patient_id=' + encodeURIComponent(patientId))
        .then(response => response.json())
        .then(data => {
            currentAssessments = data;
            updateAssessmentSelect();
            calculateCoverage();
        })
        .catch(err => console.error('Error loading assessments:', err));
}

function updateAssessmentSelect() {
    const assessmentSelect = document.getElementById('financialAssessmentSelect');
    let html = '<option value="">No Financial Assessment</option>';
    
    if (currentAssessments && currentAssessments.length > 0) {
        currentAssessments.forEach(fa => {
            html += `<option value="${fa.id}" 
                      data-type="${fa.assessment_type}"
                      data-philhealth="${fa.philhealth_eligible}"
                      data-hmo="${fa.hmo_provider || ''}">
                      ${fa.display_name || `Assessment #${fa.id} - ${fa.assessment_type} (${fa.status})`}
                    </option>`;
        });
    }
    
    assessmentSelect.innerHTML = html;
}

function calculateCoverage() {
    const totalAmount = parseFloat(document.getElementById('totalAmount').value) || 0;
    const assessmentSelect = document.getElementById('financialAssessmentSelect');
    const selectedOption = assessmentSelect.options[assessmentSelect.selectedIndex];
    const previewDiv = document.getElementById('coveragePreview');
    
    document.getElementById('previewTotal').textContent = '₱ ' + totalAmount.toFixed(2);
    
    let philhealth = 0, hmo = 0;
    
    if (selectedOption.value && totalAmount > 0) {
        const type = selectedOption.getAttribute('data-type');
        const philhealthEligible = selectedOption.getAttribute('data-philhealth') === '1';
        const hmoProvider = selectedOption.getAttribute('data-hmo') || '';
        
        if (philhealthEligible) {
            if (type === 'Charity') philhealth = totalAmount * 0.8;
            else if (type === 'Partial') philhealth = totalAmount * 0.5;
            else if (type === 'Paying') philhealth = totalAmount * 0.2;
        }
        
        if (hmoProvider) {
            if (type === 'Charity') hmo = totalAmount * 0.2;
            else if (type === 'Partial') hmo = totalAmount * 0.3;
            else if (type === 'Paying') hmo = totalAmount * 0.1;
        }
        
        // Adjust if total coverage exceeds total amount
        const totalCoverage = philhealth + hmo;
        if (totalCoverage > totalAmount) {
            const ratio = totalAmount / totalCoverage;
            philhealth *= ratio;
            hmo *= ratio;
        }
        
        previewDiv.style.display = 'block';
    } else {
        previewDiv.style.display = 'none';
    }
    
    const totalCoverage = philhealth + hmo;
    const amountDue = totalAmount - totalCoverage;
    
    document.getElementById('previewPhilhealth').textContent = '₱ ' + philhealth.toFixed(2);
    document.getElementById('previewHMO').textContent = '₱ ' + hmo.toFixed(2);
    document.getElementById('previewTotalCoverage').textContent = '₱ ' + totalCoverage.toFixed(2);
    document.getElementById('previewAmountDue').textContent = '₱ ' + amountDue.toFixed(2);
}

function validateForm() {
    const totalAmount = document.getElementById('totalAmount').value;
    const patientId = document.querySelector('select[name="patient_id"]')?.value || 
                     document.querySelector('input[name="patient_id"]')?.value;
    
    if (!patientId) {
        alert('Please select a patient.');
        if (document.getElementById('patientSelect')) {
            document.getElementById('patientSelect').focus();
        }
        return false;
    }
    
    if (!totalAmount || parseFloat(totalAmount) <= 0) {
        alert('Total amount must be greater than 0.');
        document.getElementById('totalAmount').focus();
        return false;
    }
    
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    calculateCoverage();
    <?php if ($link_bill_id && !empty($linked_bill)): ?>
        document.getElementById('totalAmount').value = <?= $linked_bill['total_amount'] ?>;
        calculateCoverage();
    <?php endif; ?>
});
</script>
</body>
</html>