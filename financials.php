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

// Preprocessing
$success = $_GET['success'] ?? '';
$action  = $_GET['action'] ?? '';
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;

// Current user info
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_social_worker = ($current_user['role'] ?? '') === 'SocialWorker';
$is_billing = ($current_user['role'] ?? '') === 'Billing';

// ACTION HANDLERS

// APPROVE/REJECT actions
if (isset($_GET['approve']) && ($is_admin || $is_social_worker)) {
    $id = (int)$_GET['approve'];
    
    // Get assessment details for notification
    $assessment = fetchOne($conn, 
        "SELECT f.*, p.first_name, p.last_name 
         FROM financial_assessment f 
         LEFT JOIN patients p ON f.patient_id = p.id 
         WHERE f.id = ?", 
        "i", 
        [$id]
    );
    
    if ($assessment) {
        // Update status
        $result = execute($conn, 
            "UPDATE financial_assessment SET status='Approved', reviewed_at=NOW(), reviewed_by=? WHERE id = ?", 
            "ii", 
            [$current_user_id, $id]
        );
        
        if (!isset($result['error'])) {
            // Automatically update related billing records
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

// Get summary statistics
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
<title>Gig Oca Robles Seamen's Hospital - Financial Assessments</title>

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
  .btn-warning {
    background: #f39c12;
    color: #fff;
  }
  .btn-danger {
    background: #e74c3c;
    color: #fff;
  }
  .btn-secondary {
    background: #6c757d;
    color: #fff;
  }
  .btn-secondary:hover {
    background: #5a6268;
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
    background: #3498db;
    border-left: 4px solid #2980b9;
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

  /* Statistics Cards */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
  }
  
  .stat-card {
    background: var(--panel);
    padding: 20px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    text-align: center;
    border: 1px solid #f0f4f8;
    transition: transform 0.2s ease;
  }
  
  .stat-card:hover {
    transform: translateY(-2px);
    border-color: var(--light-blue);
  }
  
  .stat-number {
    font-size: 28px;
    font-weight: 700;
    color: var(--navy-700);
    margin: 10px 0;
  }
  
  .stat-label {
    color: var(--muted);
    font-size: 14px;
  }

  /* Financial Assessments table */
  .section-title{font-size:18px;margin:14px 0 8px 0;color:#1e3a5f}
  .table-wrap{background:var(--panel);padding:18px;border-radius:12px;box-shadow:var(--card-shadow);overflow:auto; border: 1px solid #f0f4f8;}
  .table-controls{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
  .search-input{padding:10px 12px;border-radius:10px;border:1px solid #e6eef0;background:transparent;min-width:220px}
  .search-input:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }
  table{width:100%;border-collapse:collapse;min-width:800px}
  thead th{background:#f8fbfd;padding:14px;text-align:left;color:#6b7280;font-weight:600; border-bottom: 2px solid #e6eef0;}
  td, th{padding:14px;border-bottom:1px solid #f0f3f4;color:#233}
  
  /* Status badges */
  .status{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .pending{background:#fff8e1;color:#8a6d00}
  .approved{background:#e8f4ff;color:#1e6b8a}
  .cancelled, .rejected{background:#ffe8e8;color:#b02b2b}
  
  /* Assessment type badges */
  .type{display:inline-block;padding:6px 10px;border-radius:16px;font-weight:600;font-size:13px}
  .type-charity{background:#e8f4ff;color:#1e6b8a}
  .type-partial{background:#fff8e1;color:#8a6d00}
  .type-paying{background:#e8f4ff;color:#0369a1}

  /* Billing info */
  .billing-info {
    font-size: 12px;
    color: #666;
    margin-top: 4px;
  }
  
  .billing-info span {
    margin-right: 8px;
  }

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
    color: var(--navy-700);
    font-weight: 500;
    transition: all 0.2s ease;
  }
  .pagination a:hover {
    background: rgba(0, 31, 63, 0.1);
  }
  .pagination .current {
    background: var(--navy-700);
    color: white;
  }
  .pagination .disabled {
    color: var(--muted);
    pointer-events: none;
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
  .role-doctor { background: #003366; color: white; }
  .role-nurse { background: #4d8cc9; color: white; }
  .role-staff { background: #6b7280; color: white; }
  .role-inventory { background: #1e6b8a; color: white; }
  .role-billing { background: #0066cc; color: white; }
  .role-socialworker { background: #34495e; color: white; }

  /* Modal */
  .modal-content {
    border-radius: 12px;
    border: none;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  }
  .modal-header {
    background: var(--navy-700);
    color: white;
    border-radius: 12px 12px 0 0;
  }

  /* Action buttons in table */
  .action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  .action-btn {
    padding: 6px 12px;
    border-radius: 6px;
    border: none;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
    font-weight: 500;
  }
  .view-btn { background: #3498db; color: white; }
  .edit-btn { background: #f39c12; color: white; }
  .approve-btn { background: #27ae60; color: white; }
  .reject-btn { background: #e74c3c; color: white; }
  .archive-btn { background: #7f8c8d; color: white; }
  .restore-btn { background: #95a5a6; color: white; }
  
  .action-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
  }

  /* Responsive */
  @media (max-width:780px){
    .sidebar{position:fixed;left:-320px;transform:translateX(0)}
    .sidebar.open{left:0;transform:translateX(0)}
    .main{margin-left:0;padding:12px}
    .sidebar.collapsed{width:230px}
    .topbar{flex-direction:column;align-items:flex-start}
    .top-actions{width:100%;justify-content:space-between}
    .stats-grid {
      grid-template-columns: 1fr;
    }
    .action-buttons {
      flex-direction: column;
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
        <!-- Make logo image clickable -->
        <a href="dashboard.php" style="display: block;">
          <img src="logo.jpg" alt="Seamen's Cure Logo">
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
          <h1>Financial Assessments <?php echo $show_archived ? '(Archived)' : ''; ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?> View
            </span>
          </h1>
          <p>Manage patient financial assessments and aid eligibility - Gig Oca Robles Seamen's Hospital Davao</p>
        </div>

        <div class="top-actions">
          <?php if ($show_archived): ?>
            <a href="financials.php" class="btn btn-secondary">View Active Assessments</a>
          <?php else: ?>
            <a href="financial_form.php" class="btn">+ New Assessment</a>
            <?php if ($is_admin || $is_social_worker): ?>
              <a href="financials.php?show=archived" class="btn btn-secondary">View Archived</a>
            <?php endif; ?>
          <?php endif; ?>
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Toast container -->
      <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

      <!-- Statistics -->
      <?php if (!$show_archived): ?>
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-number"><?php echo $total_stats['total']; ?></div>
          <div class="stat-label">Total Assessments</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?php echo $approved_stats['total']; ?></div>
          <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?php echo $pending_stats['total']; ?></div>
          <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
          <div class="stat-number"><?php echo $rejected_stats['total']; ?></div>
          <div class="stat-label">Rejected</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Financial Assessments table -->
      <div class="table-wrap" id="financialsSection">
        <div class="table-controls">
          <div class="left-controls">
            <input type="text" id="searchInput" class="search-input" placeholder="Search by patient name">
          </div>
          <div class="muted">Showing <span id="rowCount"><?php echo count($rows); ?></span> of <?php echo number_format($total_assessments); ?> assessments</div>
        </div>

        <table id="financialsTable" aria-label="Financial assessments table">
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
                // Assessment type badge
                $type_class = [
                    'Charity' => 'type-charity',
                    'Partial' => 'type-partial', 
                    'Paying' => 'type-paying'
                ][$r['assessment_type']] ?? 'type-partial';
                
                // Status badge
                $status_class = [
                    'Pending' => 'pending',
                    'Approved' => 'approved', 
                    'Rejected' => 'cancelled'
                ][$r['status']] ?? 'pending';
                
                // PhilHealth display
                $philhealth_text = $r['philhealth_eligible'] ? 'Yes' : 'No';
                $philhealth_class = $r['philhealth_eligible'] ? 'approved' : 'pending';
                
                // Billing info
                $bill_count = $r['bill_count'] ?? 0;
                $total_unpaid = $r['total_unpaid'] ?? 0;
                $billing_display = "";
                if ($bill_count > 0) {
                    $billing_display = "<div class='billing-info'><span>Bills: {$bill_count}</span>";
                    if ($total_unpaid > 0) {
                        $billing_display .= "<span>Unpaid: ₱" . number_format($total_unpaid, 2) . "</span>";
                    }
                    $billing_display .= "</div>";
                }
                ?>
                <tr data-assessment-id="<?php echo h($r['id']); ?>" <?php echo !empty($r['is_archived']) ? 'style="background-color: rgba(149, 165, 166, 0.1);"' : ''; ?>>
                  <td>#<?php echo h($r['id']); ?></td>
                  <td>
                    <?php echo h($r['first_name'] . ' ' . $r['last_name']); ?><br>
                    <small class="muted"><?php echo h($r['patient_code'] ?? ''); ?></small>
                  </td>
                  <td><span class="type <?php echo $type_class; ?>"><?php echo h($r['assessment_type']); ?></span></td>
                  <td><span class="status <?php echo $philhealth_class; ?>"><?php echo $philhealth_text; ?></span></td>
                  <td><?php echo h($r['hmo_provider'] ?? 'N/A'); ?></td>
                  <td>
                    <span class="status <?php echo $status_class; ?>"><?php echo h($r['status']); ?></span>
                    <?php if (!empty($r['is_archived'])): ?>
                      <span class="status pending" style="margin-left: 5px;">Archived</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php echo $billing_display; ?>
                    <?php if ($r['status'] === 'Approved'): ?>
                      <div class="muted">Approved assessment</div>
                    <?php endif; ?>
                  </td>
                  <td style="white-space: nowrap;">
                    <div class="action-buttons">
                      <button type="button" class="action-btn view-btn" onclick="viewAssessment(<?php echo h($r['id']); ?>)">View</button>
                      
                      <a href="financial_form.php?id=<?php echo h($r['id']); ?>" class="action-btn edit-btn">Edit</a>
                      
                      <?php if ($r['status'] === 'Pending' && ($is_admin || $is_social_worker) && empty($r['is_archived'])): ?>
                        <?php $patientName = addslashes(h($r['first_name'] . ' ' . $r['last_name'])); ?>
                        <button type="button" class="action-btn approve-btn" onclick="confirmApprove(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')">Approve</button>
                        <button type="button" class="action-btn reject-btn" onclick="confirmReject(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')">Reject</button>
                      <?php endif; ?>
                      
                      <?php if (($is_admin || $is_social_worker)): ?>
                        <?php $patientName = addslashes(h($r['first_name'] . ' ' . $r['last_name'])); ?>
                        <?php if (!empty($r['is_archived'])): ?>
                          <button type="button" class="action-btn restore-btn" onclick="confirmRestore(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')">Restore</button>
                        <?php else: ?>
                          <button type="button" class="action-btn archive-btn" onclick="confirmArchive(<?php echo h($r['id']); ?>, '<?php echo $patientName; ?>')">Archive</button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" style="text-align:center; color:#888; padding: 30px;">
                  <?php echo $show_archived ? 'No archived assessments found.' : 'No financial assessments found.'; ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?><?php echo $show_archived ? '&show=archived' : ''; ?>">&laquo; Previous</a>
          <?php else: ?>
            <span class="disabled">&laquo; Previous</span>
          <?php endif; ?>
          
          <?php 
          $start_page = max(1, $current_page - 2);
          $end_page = min($total_pages, $current_page + 2);
          
          for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i == $current_page): ?>
              <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
              <a href="?page=<?php echo $i; ?><?php echo $show_archived ? '&show=archived' : ''; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          
          <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?><?php echo $show_archived ? '&show=archived' : ''; ?>">Next &raquo;</a>
          <?php else: ?>
            <span class="disabled">Next &raquo;</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <!-- View Assessment Modal -->
  <div id="viewAssessmentModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; width:90%; max-width:800px; max-height:90vh; overflow:auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
      <div style="background:var(--navy-700); color:white; padding:20px; border-radius:12px 12px 0 0; display:flex; justify-content:space-between; align-items:center;">
        <h3 style="margin:0;">Financial Assessment Details</h3>
        <button onclick="closeModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer; padding: 4px 8px; border-radius: 4px; transition: background 0.2s ease;">&times;</button>
      </div>
      <div id="assessmentDetails" style="padding:20px;">
        <div class="text-center text-muted">Loading...</div>
      </div>
      <div style="padding:20px; border-top:1px solid #eee; text-align:right;">
        <button onclick="closeModal()" class="btn" style="background:#6c757d; color:white;">Close</button>
      </div>
    </div>
  </div>

  <script>
    /* -------------------------
       Modal functions
       ------------------------- */
    function viewAssessment(assessmentId) {
        const detailsContainer = document.getElementById('assessmentDetails');
        detailsContainer.innerHTML = '<div class="text-center py-3 text-muted">Loading assessment details…</div>';
        
        const modal = document.getElementById('viewAssessmentModal');
        modal.style.display = 'flex';
        
        // Fetch assessment details
        fetch('financial_view.php?id=' + encodeURIComponent(assessmentId))
        .then(response => response.text())
        .then(html => {
            detailsContainer.innerHTML = html;
        })
        .catch(err => {
            console.error('Error fetching assessment details:', err);
            detailsContainer.innerHTML = '<div class="alert alert-danger">Error loading assessment details. Please try again.</div>';
        });
    }

    function closeModal() {
        document.getElementById('viewAssessmentModal').style.display = 'none';
    }

    // Close modal when clicking outside
    document.getElementById('viewAssessmentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    /* -------------------------
       Confirmation functions
       ------------------------- */
    function confirmApprove(assessmentId, patientName) {
        if (confirm(`Approve financial assessment for "${patientName}"?\n\nThis will:\n1. Mark assessment as Approved\n2. Auto-update related billing records\n3. Calculate insurance coverage`)) {
            window.location.href = 'financials.php?approve=' + encodeURIComponent(assessmentId);
        }
    }

    function confirmReject(assessmentId, patientName) {
        if (confirm(`Reject financial assessment for "${patientName}"?`)) {
            window.location.href = 'financials.php?reject=' + encodeURIComponent(assessmentId);
        }
    }

    function confirmArchive(assessmentId, patientName) {
        if (confirm(`Archive assessment for "${patientName}"? This will mark the record as archived.`)) {
            window.location.href = 'financials.php?archive=' + encodeURIComponent(assessmentId);
        }
    }

    function confirmRestore(assessmentId, patientName) {
        if (confirm(`Restore assessment for "${patientName}"?`)) {
            window.location.href = 'financials.php?restore=' + encodeURIComponent(assessmentId);
        }
    }

    /* -------------------------
       Toast notifications
       ------------------------- */
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
                <button type="button" onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white; cursor:pointer; font-size:18px; margin-left:10px; padding: 2px 8px; border-radius: 4px; transition: background 0.2s ease;">&times;</button>
            </div>
        `;
        
        container.appendChild(toast);
        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 5000);
    }

    /* -------------------------
       Search functionality
       ------------------------- */
    const searchInput = document.getElementById('searchInput');
    const financialsTable = document.getElementById('financialsTable');
    const tbody = financialsTable.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const rowCount = document.getElementById('rowCount');

    function filterTable(q) {
        q = q.trim().toLowerCase();
        let visible = 0;
        rows.forEach(r => {
            const text = r.textContent.toLowerCase();
            const ok = q === '' || text.indexOf(q) !== -1;
            r.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });
        rowCount.textContent = visible;
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => filterTable(searchInput.value));
        filterTable('');
    }

    /* -------------------------
       On page load
       ------------------------- */
    document.addEventListener('DOMContentLoaded', function() {
        const success = <?php echo json_encode($success); ?>;
        const action  = <?php echo json_encode($action); ?>;
        const aId     = <?php echo json_encode($assessment_id); ?>;

        if (success) {
            const messages = {
                'added': 'Assessment created successfully!',
                'updated': 'Assessment updated successfully!',
                'approved': 'Assessment approved successfully! Billing records updated.',
                'rejected': 'Assessment rejected successfully!',
                'archived': 'Assessment archived successfully!',
                'restored': 'Assessment restored successfully!',
                'error': 'An error occurred performing the action.'
            };
            const msg = messages[success] || 'Operation completed.';
            
            let toastType = 'success';
            if (success === 'error') toastType = 'error';
            else if (success === 'warning') toastType = 'warning';
            else if (success === 'info') toastType = 'info';
            
            showToast(msg, toastType);

            // Highlight row
            if (aId && ['added','updated','approved','rejected','archived','restored'].includes(success)) {
                setTimeout(() => {
                    const row = document.querySelector(`tr[data-assessment-id="${aId}"]`);
                    if (row) {
                        row.style.backgroundColor = 'rgba(0, 31, 63, 0.1)';
                        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        setTimeout(() => {
                            row.style.backgroundColor = '';
                        }, 2000);
                    }
                }, 300);
            }
        }
    });
  </script>
</body>
</html>