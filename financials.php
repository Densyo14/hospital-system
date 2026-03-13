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
$current_user_id = $_SESSION['user_id'];

// Role permissions (including Triage Queue)
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

// Preprocessing
$success = $_GET['success'] ?? '';
$action  = $_GET['action'] ?? '';
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;

$is_admin = ($current_role === 'Admin');
$is_social_worker = ($current_role === 'SocialWorker');
$is_billing = ($current_role === 'Billing');

// ACTION HANDLERS

// APPROVE action
if (isset($_GET['approve']) && ($is_admin || $is_social_worker)) {
    $id = (int)$_GET['approve'];
    
    $assessment = fetchOne($conn, 
        "SELECT f.*, p.first_name, p.last_name 
         FROM financial_assessment f 
         LEFT JOIN patients p ON f.patient_id = p.id 
         WHERE f.id = ?", 
        "i", 
        [$id]
    );
    
    if ($assessment) {
        $result = execute($conn, 
            "UPDATE financial_assessment SET status='Approved', reviewed_at=NOW(), reviewed_by=? WHERE id = ?", 
            "ii", 
            [$current_user_id, $id]
        );
        
        if (!isset($result['error'])) {
            // Auto-update related billing
            $update_billing = execute($conn,
                "UPDATE billing 
                 SET financial_assessment_id = ?, 
                     philhealth_coverage = CASE 
                         WHEN ? = 1 THEN total_amount * 0.3 
                         ELSE 0 
                     END,
                     hmo_coverage = CASE 
                         WHEN ? IS NOT NULL AND ? != '' THEN total_amount * 0.2 
                         ELSE 0 
                     END,
                     amount_due = total_amount - 
                         (CASE 
                             WHEN ? = 1 THEN total_amount * 0.3 
                             ELSE 0 
                         END + 
                         CASE 
                             WHEN ? IS NOT NULL AND ? != '' THEN total_amount * 0.2 
                             ELSE 0 
                         END)
                 WHERE patient_id = ? AND status = 'Unpaid' AND financial_assessment_id IS NULL",
                "iissiissi",
                [
                    $id, 
                    $assessment['philhealth_eligible'],
                    $assessment['hmo_provider'], $assessment['hmo_provider'],
                    $assessment['philhealth_eligible'],
                    $assessment['hmo_provider'], $assessment['hmo_provider'],
                    $assessment['patient_id']
                ]
            );
            
            header("Location: financials.php?success=approved&action=approve&assessment_id={$id}");
            exit();
        }
    }
    header("Location: financials.php?success=error&action=approve");
    exit();
}

// REJECT action
if (isset($_GET['reject']) && ($is_admin || $is_social_worker)) {
    $id = (int)$_GET['reject'];
    $result = execute($conn, 
        "UPDATE financial_assessment SET status='Rejected', reviewed_at=NOW(), reviewed_by=? WHERE id = ?", 
        "ii", 
        [$current_user_id, $id]
    );
    
    if (!isset($result['error'])) {
        header("Location: financials.php?success=rejected&action=reject&assessment_id={$id}");
        exit();
    } else {
        header("Location: financials.php?success=error&action=reject");
        exit();
    }
}

// ARCHIVE action
if (isset($_GET['archive']) && ($is_admin || $is_social_worker)) {
    $id = (int)$_GET['archive'];
    $reason = "Archived by user";

    $stmt = $conn->prepare("UPDATE financial_assessment SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?");
    $stmt->bind_param("ii", $current_user_id, $id);
    if ($stmt->execute()) {
        $stmt2 = $conn->prepare("INSERT INTO archive_logs (table_name, record_id, archived_by, reason) VALUES ('financial_assessment', ?, ?, ?)");
        $stmt2->bind_param("iis", $id, $current_user_id, $reason);
        $stmt2->execute();
        header("Location: financials.php?success=archived&action=archive&assessment_id={$id}");
        exit();
    } else {
        header("Location: financials.php?success=error&action=archive");
        exit();
    }
}

// RESTORE action
if (isset($_GET['restore']) && ($is_admin || $is_social_worker)) {
    $id = (int)$_GET['restore'];
    $stmt = $conn->prepare("UPDATE financial_assessment SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: financials.php?success=restored&action=restore&assessment_id={$id}");
        exit();
    } else {
        header("Location: financials.php?success=error&action=restore");
        exit();
    }
}

// Pagination
$assessments_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $assessments_per_page;

// Build query conditions
$show_archived = isset($_GET['show']) && $_GET['show'] === 'archived';
$archive_condition = $show_archived ? "f.is_archived = 1" : "f.is_archived = 0";

// Get total count
$total_assessments_query = "SELECT COUNT(*) as total FROM financial_assessment f WHERE $archive_condition";
$total_assessments_result = mysqli_query($conn, $total_assessments_query);
$total_assessments_row = mysqli_fetch_assoc($total_assessments_result);
$total_assessments = $total_assessments_row['total'];
$total_pages = ceil($total_assessments / $assessments_per_page);

// Fetch paginated assessments with billing info
$rows = fetchAll($conn, "
    SELECT 
        f.*, 
        p.first_name, 
        p.last_name,
        p.patient_code,
        arch_user.full_name AS archived_by_name,
        COUNT(b.id) as bill_count,
        SUM(CASE WHEN b.status = 'Unpaid' THEN b.amount_due ELSE 0 END) as total_unpaid
    FROM financial_assessment f 
    LEFT JOIN patients p ON f.patient_id = p.id
    LEFT JOIN users arch_user ON f.archived_by = arch_user.id
    LEFT JOIN billing b ON f.id = b.financial_assessment_id
    WHERE {$archive_condition}
    GROUP BY f.id
    ORDER BY f.id DESC
    LIMIT $offset, $assessments_per_page
", null, []);

// Get summary statistics (only for active assessments)
$total_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM financial_assessment", null, []);
$approved_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM financial_assessment WHERE status = 'Approved'", null, []);
$pending_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM financial_assessment WHERE status = 'Pending'", null, []);
$rejected_stats = fetchOne($conn, "SELECT COUNT(*) as total FROM financial_assessment WHERE status = 'Rejected'", null, []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - Financial Assessments</title>
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
  .btn-secondary { background:#6c757d; color:#fff; }
  .btn-secondary:hover { background:#5a6268; transform:translateY(-1px); }
  .btn-success { background:#10b981; color:#fff; }
  .btn-success:hover { background:#059669; transform:translateY(-1px); }
  .btn-danger { background:#e53e3e; color:#fff; }
  .btn-danger:hover { background:#c53030; transform:translateY(-1px); }
  .btn-warning { background:#f59e0b; color:#fff; }
  .btn-warning:hover { background:#d97706; transform:translateY(-1px); }
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
  .role-billing { background:#003366; color:white; }
  .role-doctor { background:#003366; color:white; }
  .role-nurse { background:#4d8cc9; color:white; }
  .role-staff { background:#6b7280; color:white; }
  .role-inventory { background:#1e6b8a; color:white; }
  .role-socialworker { background:#34495e; color:white; }

  /* Stats Cards */
  .stats-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(200px,1fr));
    gap:15px;
    margin-bottom:20px;
  }
  .stat-card{
    background:var(--panel);
    padding:20px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    text-align:center;
    border:1px solid #f0f4f8;
    transition:transform 0.2s;
  }
  .stat-card:hover{
    transform:translateY(-2px);
    border-color:var(--light-blue);
  }
  .stat-number{
    font-size:28px;
    font-weight:700;
    color:var(--navy-700);
    margin:10px 0;
  }
  .stat-label{
    color:var(--muted);
    font-size:14px;
  }

  /* Table */
  .table-wrap{
    background:var(--panel);
    padding:18px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    overflow:auto;
    border:1px solid #f0f4f8;
  }
  .table-controls{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
  }
  .search-input{
    padding:10px 12px;
    border-radius:10px;
    border:1px solid #e6eef0;
    background:transparent;
    min-width:220px;
  }
  .search-input:focus{
    outline:none;
    border-color:var(--light-blue);
    box-shadow:0 0 0 3px rgba(77,140,201,0.1);
  }
  table{ width:100%; border-collapse:collapse; min-width:800px; }
  thead th{
    background:#f8fbfd;
    padding:14px;
    text-align:left;
    color:#6b7280;
    font-weight:600;
    border-bottom:2px solid #e6eef0;
  }
  td, th{ padding:14px; border-bottom:1px solid #f0f3f4; color:#233; }

  /* Status and type – plain colored text, no background */
  .status{
    display:inline-block;
    font-weight:600;
    font-size:13px;
    background:none;
    padding:0;
    border-radius:0;
  }
  .pending{ color:#b45309; }      /* amber-700 */
  .approved{ color:#047857; }     /* green-700 */
  .rejected{ color:#b91c1c; }     /* red-700 */
  .archived{ color:#6b7280; }     /* gray-500 */

  .type{
    display:inline-block;
    font-weight:600;
    font-size:13px;
    background:none;
    padding:0;
    border-radius:0;
  }
  .type-charity{ color:#1e40af; }   /* blue-800 */
  .type-partial{ color:#b45309; }    /* amber-700 */
  .type-paying{ color:#0369a1; }     /* sky-700 */

  .philhealth-badge{
    font-weight:600;
    font-size:13px;
  }
  .philhealth-yes{ color:#047857; }
  .philhealth-no{ color:#6b7280; }

  .billing-info{
    font-size:12px;
    color:var(--muted);
    margin-top:4px;
  }

  /* Action buttons */
  .action-btn{
    padding:6px 10px;
    border-radius:6px;
    text-decoration:none;
    font-size:12px;
    font-weight:600;
    margin-right:4px;
    border:none;
    cursor:pointer;
    display:inline-block;
    transition:all 0.2s;
  }
  .action-btn:hover{ transform:translateY(-1px); box-shadow:0 2px 4px rgba(0,0,0,0.1); }
  .btn-view{ background:#3182ce; color:white; }
  .btn-edit{ background:#ed8936; color:white; }
  .btn-approve{ background:#10b981; color:white; }
  .btn-reject{ background:#e53e3e; color:white; }
  .btn-archive{ background:#6b7280; color:white; }
  .btn-restore{ background:#6b7280; color:white; }

  .action-buttons{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
  }

  /* Pagination */
  .pagination{
    display:flex;
    justify-content:center;
    align-items:center;
    margin-top:20px;
    gap:10px;
  }
  .pagination a, .pagination span{
    display:inline-block;
    padding:8px 12px;
    border-radius:6px;
    text-decoration:none;
    color:var(--navy-700);
    font-weight:500;
  }
  .pagination a:hover{ background:rgba(0,31,63,0.1); }
  .pagination .current{ background:var(--navy-700); color:white; }
  .pagination .disabled{ color:var(--muted); pointer-events:none; }

  /* Toast */
  .toast-container{
    position:fixed;
    top:20px;
    right:20px;
    z-index:9999;
    max-width:350px;
  }
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

  /* Modal */
  .modal{
    display:none;
    position:fixed;
    top:0; left:0; right:0; bottom:0;
    background:rgba(0,0,0,0.5);
    z-index:1000;
    align-items:center;
    justify-content:center;
  }
  .modal-content{
    background:white;
    border-radius:12px;
    width:90%;
    max-width:800px;
    max-height:90vh;
    overflow:auto;
    box-shadow:0 20px 60px rgba(0,0,0,0.3);
  }
  .modal-header{
    background:var(--navy-700);
    color:white;
    padding:20px;
    border-radius:12px 12px 0 0;
    display:flex;
    justify-content:space-between;
    align-items:center;
  }
  .modal-header h3{ margin:0; }
  .modal-close{
    background:none;
    border:none;
    color:white;
    font-size:24px;
    cursor:pointer;
  }
  .modal-body{ padding:20px; }
  .modal-footer{
    padding:20px;
    border-top:1px solid #eee;
    text-align:right;
  }

  @media (max-width:780px){
    .sidebar{ left:-320px; }
    .sidebar.open{ left:0; }
    .main{ margin-left:0; padding:12px; }
    .stats-grid{ grid-template-columns:1fr; }
    .action-buttons{ flex-direction:column; }
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
        <h1>Financial Assessments <?= $show_archived ? '(Archived)' : '' ?>
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Manage patient financial aid assessments</p>
      </div>
      <div class="top-actions">
        <?php if ($show_archived): ?>
          <a href="financials.php" class="btn btn-outline">View Active</a>
        <?php else: ?>
          <a href="financial_form.php" class="btn">+ New Assessment</a>
          <?php if ($is_admin || $is_social_worker): ?>
            <a href="financials.php?show=archived" class="btn btn-secondary">Archived</a>
          <?php endif; ?>
        <?php endif; ?>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div id="toast" class="toast-container"></div>

    <!-- Stats (only when not archived) -->
    <?php if (!$show_archived): ?>
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-number"><?= $total_stats['total'] ?? 0 ?></div>
        <div class="stat-label">Total Assessments</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?= $approved_stats['total'] ?? 0 ?></div>
        <div class="stat-label">Approved</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?= $pending_stats['total'] ?? 0 ?></div>
        <div class="stat-label">Pending</div>
      </div>
      <div class="stat-card">
        <div class="stat-number"><?= $rejected_stats['total'] ?? 0 ?></div>
        <div class="stat-label">Rejected</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div class="table-wrap">
      <div class="table-controls">
        <input type="text" id="searchInput" class="search-input" placeholder="Search patient name...">
        <div class="muted">Showing <span id="rowCount"><?= count($rows) ?></span> of <?= number_format($total_assessments) ?></div>
      </div>

      <table id="financialsTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>Patient</th>
            <th>Assessment Type</th>
            <th>PhilHealth</th>
            <th>HMO Provider</th>
            <th>Status</th>
            <th>Billing Info</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($rows) > 0): ?>
            <?php foreach($rows as $r): ?>
              <?php
              $type_class = 'type-' . strtolower($r['assessment_type'] ?? 'none');
              $status_class = strtolower($r['status'] ?? 'pending');
              $philhealth_class = $r['philhealth_eligible'] ? 'philhealth-yes' : 'philhealth-no';
              $philhealth_text = $r['philhealth_eligible'] ? 'Yes' : 'No';
              $bill_count = $r['bill_count'] ?? 0;
              $total_unpaid = $r['total_unpaid'] ?? 0;
              $billing_display = '';
              if ($bill_count > 0) {
                  $billing_display = "<div class='billing-info'>Bills: $bill_count";
                  if ($total_unpaid > 0) {
                      $billing_display .= " | Unpaid: ₱" . number_format($total_unpaid, 2);
                  }
                  $billing_display .= "</div>";
              }
              $is_archived = !empty($r['is_archived']);
              $row_bg = $is_archived ? 'style="background:rgba(149,165,166,0.1);"' : '';
              $patient_name = htmlspecialchars($r['first_name'] . ' ' . $r['last_name']);
              ?>
              <tr data-id="<?= $r['id'] ?>" <?= $row_bg ?>>
                <td>#<?= $r['id'] ?></td>
                <td>
                  <?= $patient_name ?><br>
                  <small class="muted"><?= htmlspecialchars($r['patient_code'] ?? '') ?></small>
                </td>
                <td><span class="type <?= $type_class ?>"><?= htmlspecialchars($r['assessment_type']) ?></span></td>
                <td><span class="philhealth-badge <?= $philhealth_class ?>"><?= $philhealth_text ?></span></td>
                <td><?= htmlspecialchars($r['hmo_provider'] ?? 'N/A') ?></td>
                <td>
                  <span class="status <?= $status_class ?>"><?= htmlspecialchars($r['status']) ?></span>
                  <?php if ($is_archived): ?>
                    <span class="status archived" style="margin-left:5px;">Archived</span>
                  <?php endif; ?>
                </td>
                <td><?= $billing_display ?></td>
                <td>
                  <div class="action-buttons">
                    <a href="financial_view.php?id=<?= $r['id'] ?>" class="action-btn btn-view">Chart</a>
                    <a href="financial_form.php?id=<?= $r['id'] ?>" class="action-btn btn-edit">Update</a>
                    <?php if ($r['status'] === 'Pending' && ($is_admin || $is_social_worker) && !$is_archived): ?>
                      <a href="financials.php?approve=<?= $r['id'] ?>" class="action-btn btn-approve" onclick="return confirm('Approve assessment for <?= htmlspecialchars($patient_name, ENT_QUOTES) ?>?')">Approve</a>
                      <a href="financials.php?reject=<?= $r['id'] ?>" class="action-btn btn-reject" onclick="return confirm('Reject assessment for <?= htmlspecialchars($patient_name, ENT_QUOTES) ?>?')">Reject</a>
                    <?php endif; ?>
                    <?php if ($is_admin || $is_social_worker): ?>
                      <?php if ($is_archived): ?>
                        <a href="financials.php?restore=<?= $r['id'] ?>" class="action-btn btn-restore" onclick="return confirm('Restore assessment for <?= htmlspecialchars($patient_name, ENT_QUOTES) ?>?')">Restore</a>
                      <?php else: ?>
                        <a href="financials.php?archive=<?= $r['id'] ?>" class="action-btn btn-archive" onclick="return confirm('Archive assessment for <?= htmlspecialchars($patient_name, ENT_QUOTES) ?>?')">Archive</a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" style="text-align:center; padding:30px;">No assessments found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?= $current_page-1 ?><?= $show_archived?'&show=archived':'' ?>">&laquo;</a>
          <?php else: ?>
            <span class="disabled">&laquo;</span>
          <?php endif; ?>
          <?php for($i=max(1,$current_page-2); $i<=min($total_pages,$current_page+2); $i++): ?>
            <?php if($i==$current_page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="?page=<?= $i ?><?= $show_archived?'&show=archived':'' ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?= $current_page+1 ?><?= $show_archived?'&show=archived':'' ?>">&raquo;</a>
          <?php else: ?>
            <span class="disabled">&raquo;</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- View Assessment Modal (if needed) -->
<div id="viewAssessmentModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Assessment Details</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div id="assessmentDetails" class="modal-body">
      <p style="color:var(--muted); text-align:center;">Loading...</p>
    </div>
    <div class="modal-footer">
      <button onclick="closeModal()" class="btn btn-secondary">Close</button>
    </div>
  </div>
</div>

<script>
function viewAssessment(id) {
  const modal = document.getElementById('viewAssessmentModal');
  const details = document.getElementById('assessmentDetails');
  details.innerHTML = '<p style="color:var(--muted); text-align:center;">Loading...</p>';
  modal.style.display = 'flex';

  fetch('financial_view.php?id=' + encodeURIComponent(id))
    .then(response => response.text())
    .then(html => { details.innerHTML = html; })
    .catch(err => {
      details.innerHTML = '<p class="alert alert-error">Error loading details.</p>';
    });
}

function closeModal() {
  document.getElementById('viewAssessmentModal').style.display = 'none';
}

window.onclick = function(e) {
  if (e.target.classList.contains('modal')) {
    e.target.style.display = 'none';
  }
};

// Search
const searchInput = document.getElementById('searchInput');
const tbody = document.querySelector('#financialsTable tbody');
const rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : [];
const rowCount = document.getElementById('rowCount');

function filterTable(q) {
  q = q.toLowerCase();
  let visible = 0;
  rows.forEach(r => {
    const text = r.textContent.toLowerCase();
    const ok = q === '' || text.includes(q);
    r.style.display = ok ? '' : 'none';
    if (ok) visible++;
  });
  if (rowCount) rowCount.textContent = visible;
}
if (searchInput) {
  searchInput.addEventListener('input', () => filterTable(searchInput.value));
  filterTable('');
}

function showToast(msg, type = 'success') {
  const container = document.getElementById('toast');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = `alert alert-${type}`;
  toast.innerHTML = `<div style="display:flex; justify-content:space-between;">${msg}<button onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white;">&times;</button></div>`;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 5000);
}

document.addEventListener('DOMContentLoaded', function() {
  const success = <?= json_encode($success) ?>;
  const aId = <?= json_encode($assessment_id) ?>;
  if (success) {
    const msgs = {
      added: 'Assessment added.',
      updated: 'Assessment updated.',
      approved: 'Assessment approved.',
      rejected: 'Assessment rejected.',
      archived: 'Assessment archived.',
      restored: 'Assessment restored.',
      error: 'An error occurred.'
    };
    showToast(msgs[success] || 'Done.', success === 'error' ? 'error' : 'success');
    if (aId && ['added','updated','approved','rejected','archived','restored'].includes(success)) {
      setTimeout(() => {
        const row = document.querySelector(`tr[data-id="${aId}"]`);
        if (row) {
          row.style.backgroundColor = 'rgba(0,31,63,0.1)';
          row.scrollIntoView({ behavior: 'smooth', block: 'center' });
          setTimeout(() => row.style.backgroundColor = '', 2000);
        }
      }, 300);
    }
  }
});
</script>
</body>
</html>