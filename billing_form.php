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

// Get allowed pages
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

// Fetch surgeries
$surgeries = fetchAll($conn, 
    "SELECT s.id, s.surgery_type, s.schedule_date, p.first_name, p.last_name 
     FROM surgeries s 
     LEFT JOIN patients p ON s.patient_id = p.id 
     WHERE s.status != 'Cancelled' 
     ORDER BY s.schedule_date DESC", 
    null, []
);

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
    $surgery_id = (int)($_POST['surgery_id'] ?? 0);
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
            // Update existing bill
            if ($financial_assessment_id) {
                $params = [$patient_id, $surgery_id, $financial_assessment_id, $total_amount, 
                          $philhealth_coverage, $hmo_coverage, $amount_due, $status, $id];
                $types = "iiiddddsi";
                $result = execute($conn, 
                    "UPDATE billing SET patient_id=?, surgery_id=?, financial_assessment_id=?, 
                     total_amount=?, philhealth_coverage=?, hmo_coverage=?, amount_due=?, status=? 
                     WHERE id=?", 
                    $types, $params
                );
            } else {
                $params = [$patient_id, $surgery_id, $total_amount, $philhealth_coverage, 
                          $hmo_coverage, $amount_due, $status, $id];
                $types = "iiddddsi";
                $result = execute($conn, 
                    "UPDATE billing SET patient_id=?, surgery_id=?, total_amount=?, 
                     philhealth_coverage=?, hmo_coverage=?, amount_due=?, status=? 
                     WHERE id=?", 
                    $types, $params
                );
            }
            $bill_id = $id;
        } else {
            // Insert new bill
            if ($financial_assessment_id) {
                $params = [$patient_id, $surgery_id, $financial_assessment_id, $total_amount, 
                          $philhealth_coverage, $hmo_coverage, $amount_due, $status];
                $types = "iiidddds";
                $result = execute($conn, 
                    "INSERT INTO billing (patient_id, surgery_id, financial_assessment_id, total_amount, 
                     philhealth_coverage, hmo_coverage, amount_due, status) 
                     VALUES (?,?,?,?,?,?,?,?)", 
                    $types, $params
                );
            } else {
                $params = [$patient_id, $surgery_id, $total_amount, $philhealth_coverage, 
                          $hmo_coverage, $amount_due, $status];
                $types = "iidddds";
                $result = execute($conn, 
                    "INSERT INTO billing (patient_id, surgery_id, total_amount, philhealth_coverage, 
                     hmo_coverage, amount_due, status) 
                     VALUES (?,?,?,?,?,?,?)", 
                    $types, $params
                );
            }
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
    background: #6c757d;
    color: #fff;
  }
  .btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-1px);
  }
  .btn-warning {
    background: #f39c12;
    color: #fff;
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

  /* Alert container */
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

  .alert-success {
    background: #27ae60;
    border-left: 4px solid #1e8449;
  }

  .alert-error, .alert-danger {
    background: #e74c3c;
    border-left: 4px solid #c0392b;
  }

  .alert-warning {
    background: #f39c12;
    border-left: 4px solid #d68910;
  }

  .alert-info {
    background: var(--light-blue);
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

  /* Form styles */
  .form-container {
    background: var(--panel);
    padding: 24px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    max-width: 700px;
    margin: 0 auto;
    border: 1px solid #f0f4f8;
  }

  .form-group {
    margin-bottom: 20px;
    position: relative;
  }

  .form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #0f1724;
  }

  .form-control, .form-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-family: Inter, sans-serif;
    font-size: 14px;
    transition: border-color 0.2s ease;
    background: #fff;
  }

  .form-control:focus, .form-select:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }

  .form-control[readonly] {
    background-color: #f8fbfd;
    color: #0f1724;
    font-weight: 600;
    border-color: #d1e7dd;
  }

  .required:after {
    content: " *";
    color: #e74c3c;
  }

  /* Financial assessment info */
  .assessment-info {
    background: #f8fbfd;
    border-radius: 8px;
    padding: 15px;
    margin: 15px 0;
    border-left: 4px solid var(--navy-700);
  }
  
  .assessment-info h5 {
    margin: 0 0 10px 0;
    color: var(--navy-700);
  }
  
  .coverage-preview {
    background: white;
    border-radius: 6px;
    padding: 15px;
    margin: 10px 0;
    border: 1px solid #e6eef0;
  }
  
  .coverage-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f3f4;
  }
  
  .coverage-row:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
  }
  
  .coverage-label {
    color: var(--muted);
  }
  
  .coverage-value {
    font-weight: 600;
  }
  
  .coverage-amount-due {
    font-size: 18px;
    font-weight: 700;
    color: #e74c3c;
  }

  /* Patient info display for edits */
  .patient-display {
    background: #f8fbfd;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    border-left: 4px solid var(--navy-700);
  }
  
  .patient-display h5 {
    margin: 0 0 10px 0;
    color: var(--navy-700);
  }
  
  .patient-display p {
    margin: 5px 0;
    color: #0f1724;
  }

  /* Form actions */
  .form-actions {
    display: flex;
    gap: 12px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #f0f3f4;
  }

  /* Role badge */
  .role-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
    margin-left: 10px;
  }
  .role-admin { background: #001F3F; color: white; }
  .role-billing { background: #0066cc; color: white; }

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
  }

  /* Clickable image */
  .clickable-image {
    cursor: pointer;
    transition: transform 0.2s ease;
  }
  .clickable-image:hover {
    transform: scale(1.02);
  }

  .muted{color:var(--muted);font-size:13px}
</style>
</head>
<body>
  <div class="app">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
      <div class="logo-wrap">
        <!-- Make logo image clickable -->
        <a href="dashboard.php" class="clickable-image">
          <img src="logo.jpg" alt="Seamen's Cure Logo">
        </a>
      </div>

      <!-- User info like in dashboard.php -->
      <div class="user-info">
        <h4>Logged as:</h4>
        <p><?php echo htmlspecialchars($current_name); ?><br><strong><?php echo htmlspecialchars($current_role); ?></strong></p>
      </div>

      <nav class="menu" id="mainMenu">
        <a href="dashboard.php" class="menu-item">
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
          <h1><?php echo $edit ? "Edit Bill TX-" . $id : ($link_bill_id ? "Link Financial Assessment to Bill" : "Create New Bill"); ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Gig Oca Robles Seamen's Hospital Davao Management System</p>
        </div>

        <div class="top-actions">
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Alert container -->
      <div class="alert-container">
        <?php if(!empty($error_message)) echo $error_message; ?>
      </div>

      <!-- Form -->
      <div class="form-container">
        <form method="POST" onsubmit="return validateForm()">
          <input type="hidden" name="id" value="<?php echo h($id); ?>">
          
          <?php if ($edit && !$link_bill_id): ?>
            <!-- For edits, show patient info as read-only -->
            <div class="patient-display">
              <h5>Patient Information</h5>
              <p><strong>Name:</strong> <?php echo htmlspecialchars($patient_info ?? ''); ?></p>
              <input type="hidden" name="patient_id" value="<?php echo h($patient_id); ?>">
              <div class="muted">Patient cannot be changed for existing bills.</div>
            </div>
          <?php elseif ($link_bill_id): ?>
            <!-- When linking from financial assessment -->
            <div class="alert alert-info">
              <strong>Linking Financial Assessment to Bill</strong><br>
              You are adding a financial assessment to bill TX-<?php echo h($link_bill_id); ?>
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
                    <p><strong>Patient:</strong> <?php echo htmlspecialchars($linked_bill['first_name'] . ' ' . $linked_bill['last_name'] . ' (' . $linked_bill['patient_code'] . ')'); ?></p>
                    <p><strong>Total Amount:</strong> ₱ <?php echo number_format($linked_bill['total_amount'], 2); ?></p>
                    <input type="hidden" name="patient_id" value="<?php echo h($linked_bill['patient_id']); ?>">
                    <input type="hidden" name="id" value="<?php echo h($link_bill_id); ?>">
                </div>
            <?php endif; ?>
          <?php else: ?>
            <!-- For new bills, show patient selection -->
            <div class="form-group">
              <label class="form-label required">Patient</label>
              <select name="patient_id" id="patientSelect" class="form-select" required onchange="loadPatientAssessments()">
                <option value="">Select Patient</option>
                <?php foreach($patients as $patient): ?>
                  <option value="<?php echo h($patient['id']); ?>" 
                    <?php echo $patient_id == $patient['id'] ? 'selected' : ''; ?>>
                    <?php echo h($patient['first_name'] . ' ' . $patient['last_name']); ?> (<?php echo h($patient['patient_code']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <!-- Surgery selection -->
          <div class="form-group">
            <label class="form-label">Surgery (Optional)</label>
            <select name="surgery_id" class="form-select">
              <option value="">Select Surgery (Optional)</option>
              <?php foreach($surgeries as $surgery): ?>
                <option value="<?php echo h($surgery['id']); ?>" 
                  <?php echo $surgery_id == $surgery['id'] ? 'selected' : ''; ?>>
                  <?php echo h($surgery['first_name'] . ' ' . $surgery['last_name'] . ' - ' . $surgery['surgery_type']); ?>
                  (<?php echo date('M j, Y', strtotime($surgery['schedule_date'])); ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="muted mt-1">Link this bill to a specific surgery for better tracking.</div>
          </div>

          <!-- Financial Assessment Selection -->
          <div class="form-group">
            <label class="form-label">Financial Assessment</label>
            <select name="financial_assessment_id" id="financialAssessmentSelect" class="form-select" onchange="calculateCoverage()">
              <option value="">No Financial Assessment</option>
              <?php if (!empty($financial_assessments)): ?>
                <?php foreach($financial_assessments as $fa): ?>
                  <option value="<?php echo h($fa['id']); ?>" 
                    <?php echo $financial_assessment_id == $fa['id'] ? 'selected' : ''; ?>
                    data-type="<?php echo h($fa['assessment_type']); ?>"
                    data-philhealth="<?php echo $fa['philhealth_eligible']; ?>"
                    data-hmo="<?php echo h($fa['hmo_provider'] ?? ''); ?>">
                    <?php echo h($fa['display_name']); ?>
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
            <div class="muted mt-1">
              Select a financial assessment to automatically calculate insurance coverage.
              <?php if (!$edit && !$link_bill_id): ?>
                <a href="financial_form.php" target="_blank" style="color: var(--navy-700); font-weight: 600;">Create New Assessment</a>
              <?php endif; ?>
            </div>
          </div>

          <!-- Total Amount -->
          <div class="form-group">
            <label class="form-label required">Total Amount (₱)</label>
            <input type="number" name="total_amount" id="totalAmount" class="form-control" 
                   step="0.01" min="0" required
                   value="<?php echo h($total_amount); ?>" oninput="calculateCoverage()">
          </div>

          <!-- Coverage Preview (will be updated by JavaScript) -->
          <div id="coveragePreview" style="display: <?php echo ($total_amount > 0 && !empty($financial_assessment_id)) ? 'block' : 'none'; ?>;">
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
                <div class="coverage-row" style="border-top: 2px solid #e74c3c; padding-top: 10px; margin-top: 10px;">
                  <span class="coverage-label" style="font-weight: 700;">Amount Due:</span>
                  <span class="coverage-amount-due" id="previewAmountDue">₱ 0.00</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Status -->
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="Unpaid" <?php echo $status == 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
              <option value="Paid" <?php echo $status == 'Paid' ? 'selected' : ''; ?>>Paid</option>
            </select>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn">
              <?php echo $edit ? "Update Bill" : ($link_bill_id ? "Link Assessment" : "Create Bill"); ?>
            </button>
            <a href="billing.php" class="btn btn-secondary">Cancel</a>
            <?php if ($edit && !$link_bill_id): ?>
              <a href="billing_view.php?id=<?php echo h($id); ?>" class="btn" style="background: var(--light-blue);">View Bill</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <script>
    let currentAssessments = <?php echo json_encode($financial_assessments); ?>;
    
    function loadPatientAssessments() {
        const patientId = document.getElementById('patientSelect').value;
        const assessmentSelect = document.getElementById('financialAssessmentSelect');
        
        if (!patientId) {
            // Reset to default if no patient selected
            assessmentSelect.innerHTML = '<option value="">No Financial Assessment</option>';
            return;
        }
        
        // Fetch assessments for this patient via AJAX
        fetch('get_patient_assessments.php?patient_id=' + encodeURIComponent(patientId))
            .then(response => response.json())
            .then(data => {
                currentAssessments = data;
                updateAssessmentSelect();
                calculateCoverage();
            })
            .catch(err => {
                console.error('Error loading assessments:', err);
            });
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
        
        let philhealthCoverage = 0;
        let hmoCoverage = 0;
        
        // Update preview values
        document.getElementById('previewTotal').textContent = '₱ ' + totalAmount.toFixed(2);
        
        if (selectedOption.value && totalAmount > 0) {
            const assessmentType = selectedOption.getAttribute('data-type');
            const philhealthEligible = selectedOption.getAttribute('data-philhealth') === '1';
            const hmoProvider = selectedOption.getAttribute('data-hmo') || '';
            
            // Calculate coverage based on assessment type
            if (philhealthEligible) {
                switch(assessmentType) {
                    case 'Charity':
                        philhealthCoverage = totalAmount * 0.8; // 80% coverage
                        break;
                    case 'Partial':
                        philhealthCoverage = totalAmount * 0.5; // 50% coverage
                        break;
                    case 'Paying':
                        philhealthCoverage = totalAmount * 0.2; // 20% coverage
                        break;
                }
            }
            
            if (hmoProvider) {
                switch(assessmentType) {
                    case 'Charity':
                        hmoCoverage = totalAmount * 0.2; // 20% coverage
                        break;
                    case 'Partial':
                        hmoCoverage = totalAmount * 0.3; // 30% coverage
                        break;
                    case 'Paying':
                        hmoCoverage = totalAmount * 0.1; // 10% coverage
                        break;
                }
            }
            
            // Ensure total coverage doesn't exceed total amount
            const totalCoverage = philhealthCoverage + hmoCoverage;
            if (totalCoverage > totalAmount) {
                const ratio = totalAmount / totalCoverage;
                philhealthCoverage *= ratio;
                hmoCoverage *= ratio;
            }
            
            // Show preview
            previewDiv.style.display = 'block';
        } else {
            // Hide preview if no assessment selected
            previewDiv.style.display = 'none';
            philhealthCoverage = 0;
            hmoCoverage = 0;
        }
        
        const totalCoverage = philhealthCoverage + hmoCoverage;
        const amountDue = totalAmount - totalCoverage;
        
        // Update preview values
        document.getElementById('previewPhilhealth').textContent = '₱ ' + philhealthCoverage.toFixed(2);
        document.getElementById('previewHMO').textContent = '₱ ' + hmoCoverage.toFixed(2);
        document.getElementById('previewTotalCoverage').textContent = '₱ ' + totalCoverage.toFixed(2);
        document.getElementById('previewAmountDue').textContent = '₱ ' + amountDue.toFixed(2);
        
        // Update hidden fields if they exist
        const philhealthInput = document.getElementById('philhealth_coverage');
        const hmoInput = document.getElementById('hmo_coverage');
        const amountDueInput = document.getElementById('amount_due');
        
        if (philhealthInput) philhealthInput.value = philhealthCoverage;
        if (hmoInput) hmoInput.value = hmoCoverage;
        if (amountDueInput) amountDueInput.value = amountDue;
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
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        calculateCoverage();
        
        // If we're linking a bill, auto-calculate based on existing amount
        <?php if ($link_bill_id && !empty($linked_bill)): ?>
            document.getElementById('totalAmount').value = <?php echo $linked_bill['total_amount']; ?>;
            calculateCoverage();
        <?php endif; ?>
    });
  </script>
</body>
</html>