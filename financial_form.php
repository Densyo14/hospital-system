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

// Check permissions
$allowed_roles = ['Admin', 'Billing', 'SocialWorker'];
if (!in_array($current_role, $allowed_roles)) {
    header("Location: financials.php?success=error&message=Unauthorized");
    exit();
}

// Initialize variables
$id = $patient_id = $assessment_type = $philhealth_eligible = $hmo_provider = $status = "";
$bill_id = "";
$edit = false;
$error_message = "";
$success_message = "";

// Check if linking from billing
$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

// Check if editing
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $assessment = fetchOne($conn, 
        "SELECT f.*, p.first_name, p.last_name, p.patient_code, 
                p.address, p.phone, p.birth_date,
                COUNT(b.id) as bill_count,
                SUM(CASE WHEN b.status = 'Unpaid' THEN b.amount_due ELSE 0 END) as total_unpaid
         FROM financial_assessment f 
         LEFT JOIN patients p ON f.patient_id = p.id 
         LEFT JOIN billing b ON f.id = b.financial_assessment_id
         WHERE f.id = ?", 
        "i", 
        [$id]
    );
    
    if ($assessment) {
        $edit = true;
        $patient_id = $assessment['patient_id'] ?? '';
        $assessment_type = $assessment['assessment_type'] ?? 'Charity';
        $philhealth_eligible = $assessment['philhealth_eligible'] ?? 0;
        $hmo_provider = $assessment['hmo_provider'] ?? '';
        $status = $assessment['status'] ?? 'Pending';
        
        // Patient info
        $patient_info = [
            'name' => $assessment['first_name'] . ' ' . $assessment['last_name'],
            'code' => $assessment['patient_code'] ?? '',
            'phone' => $assessment['phone'] ?? '',
            'address' => $assessment['address'] ?? '',
            'dob' => $assessment['birth_date'] ?? '',
            'bills' => $assessment['bill_count'] ?? 0,
            'unpaid' => $assessment['total_unpaid'] ?? 0
        ];
    } else {
        header("Location: financials.php?success=error&message=Assessment+not+found");
        exit();
    }
}

// If linking from billing, get patient info from bill
if ($bill_id && !$edit) {
    $bill = fetchOne($conn, 
        "SELECT b.*, p.first_name, p.last_name, p.patient_code, 
                p.address, p.phone, p.birth_date
         FROM billing b 
         LEFT JOIN patients p ON b.patient_id = p.id 
         WHERE b.id = ?", 
        "i", 
        [$bill_id]
    );
    
    if ($bill) {
        $patient_id = $bill['patient_id'];
        $patient_info = [
            'name' => $bill['first_name'] . ' ' . $bill['last_name'],
            'code' => $bill['patient_code'] ?? '',
            'phone' => $bill['phone'] ?? '',
            'address' => $bill['address'] ?? '',
            'dob' => $bill['birth_date'] ?? '',
            'bill_amount' => $bill['total_amount'] ?? 0,
            'bill_id' => $bill_id
        ];
        
        // Check if patient already has an assessment
        $existing_assessment = fetchOne($conn, 
            "SELECT id, status FROM financial_assessment 
             WHERE patient_id = ? AND is_archived = 0", 
            "i", 
            [$patient_id]
        );
        
        if ($existing_assessment) {
            // Redirect to edit existing assessment
            header("Location: financial_form.php?id=" . $existing_assessment['id'] . "&bill_id=" . $bill_id);
            exit();
        }
    }
}

// Fetch all patients for dropdown
$patients = fetchAll($conn, 
    "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, patient_code 
     FROM patients 
     WHERE is_archived = 0 
     ORDER BY first_name, last_name", 
    null, []
);

// Get HMO providers list
$hmo_providers = ['Maxicare', 'Intellicare', 'Medicard', 'Generali', 'Pacific Cross', 'Other'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $bill_id = (int)($_POST['bill_id'] ?? 0);
    
    // Get form data
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $assessment_type = trim($_POST['assessment_type'] ?? 'Charity');
    $philhealth_eligible = isset($_POST['philhealth_eligible']) ? 1 : 0;
    $hmo_provider = trim($_POST['hmo_provider'] ?? '');
    $status = trim($_POST['status'] ?? 'Pending');
    
    $current_user_id = $_SESSION['user_id'] ?? 0;

    // Validation
    $errors = [];

    if (empty($patient_id)) {
        $errors[] = "Patient is required.";
    }

    if (empty($assessment_type)) {
        $errors[] = "Assessment type is required.";
    }

    // For new assessments, check if patient already has one
    if (!$edit) {
        $existing = fetchOne($conn, 
            "SELECT id, status FROM financial_assessment 
             WHERE patient_id = ? AND is_archived = 0", 
            "i", 
            [$patient_id]
        );
        if ($existing) {
            $errors[] = "This patient already has an active financial assessment (#" . $existing['id'] . ").";
        }
    }

    if (empty($errors)) {
        try {
            $conn->autocommit(FALSE);
            
            if ($edit) {
                // Update existing assessment
                $stmt = $conn->prepare("
                    UPDATE financial_assessment 
                    SET patient_id = ?, assessment_type = ?, philhealth_eligible = ?, 
                        hmo_provider = ?, status = ?, reviewed_at = CASE 
                            WHEN status != ? THEN NOW() 
                            ELSE reviewed_at 
                        END,
                        reviewed_by = CASE 
                            WHEN status != ? THEN ? 
                            ELSE reviewed_by 
                        END
                    WHERE id = ?
                ");
                
                $new_status = $status;
                $stmt->bind_param("isissiiii", 
                    $patient_id, $assessment_type, $philhealth_eligible, 
                    $hmo_provider, $status,
                    $new_status, $new_status, $current_user_id, $id
                );
                
                $assessment_id = $id;
            } else {
                // Insert new assessment
                $stmt = $conn->prepare("
                    INSERT INTO financial_assessment 
                    (patient_id, assessment_type, philhealth_eligible, hmo_provider, 
                     status, reviewed_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $reviewed_by = ($status === 'Approved' || $status === 'Rejected') ? $current_user_id : null;
                $stmt->bind_param("isissi",
                    $patient_id, $assessment_type, $philhealth_eligible, 
                    $hmo_provider, $status, $reviewed_by
                );
            }

            if ($stmt->execute()) {
                if (!$edit) {
                    $assessment_id = $conn->insert_id;
                }
                
                // If assessment is approved and we have a bill_id, update the bill
                if ($bill_id && $status === 'Approved') {
                    $bill = fetchOne($conn, 
                        "SELECT total_amount FROM billing WHERE id = ?", 
                        "i", 
                        [$bill_id]
                    );
                    
                    if ($bill) {
                        // Calculate coverage based on assessment type
                        $coverage = calculateCoverage(
                            $bill['total_amount'],
                            $assessment_type,
                            $philhealth_eligible,
                            $hmo_provider
                        );
                        
                        // Update bill with financial assessment
                        $update_stmt = $conn->prepare("
                            UPDATE billing 
                            SET financial_assessment_id = ?, 
                                philhealth_coverage = ?, 
                                hmo_coverage = ?, 
                                amount_due = ?
                            WHERE id = ?
                        ");
                        
                        $update_stmt->bind_param("idddi", 
                            $assessment_id,
                            $coverage['philhealth_coverage'],
                            $coverage['hmo_coverage'],
                            $coverage['amount_due'],
                            $bill_id
                        );
                        
                        if (!$update_stmt->execute()) {
                            throw new Exception("Failed to update bill: " . $update_stmt->error);
                        }
                        
                        $success_message = "Assessment created and bill updated successfully!";
                    }
                }
                
                // If assessment is approved, update all unpaid bills for this patient
                if ($status === 'Approved') {
                    $unpaid_bills = fetchAll($conn, 
                        "SELECT id, total_amount FROM billing 
                         WHERE patient_id = ? AND status = 'Unpaid' 
                         AND financial_assessment_id IS NULL", 
                        "i", 
                        [$patient_id]
                    );
                    
                    foreach ($unpaid_bills as $bill) {
                        $coverage = calculateCoverage(
                            $bill['total_amount'],
                            $assessment_type,
                            $philhealth_eligible,
                            $hmo_provider
                        );
                        
                        $update_stmt = $conn->prepare("
                            UPDATE billing 
                            SET financial_assessment_id = ?, 
                                philhealth_coverage = ?, 
                                hmo_coverage = ?, 
                                amount_due = ?
                            WHERE id = ?
                        ");
                        
                        $update_stmt->bind_param("idddi", 
                            $assessment_id,
                            $coverage['philhealth_coverage'],
                            $coverage['hmo_coverage'],
                            $coverage['amount_due'],
                            $bill['id']
                        );
                        
                        $update_stmt->execute();
                    }
                }
                
                $conn->commit();
                
                // Redirect based on context
                if ($bill_id) {
                    header("Location: billing.php?success=assessment_linked&bill_id=" . $bill_id);
                } else {
                    $action = $edit ? 'updated' : 'added';
                    header("Location: financials.php?success=" . $action . "&assessment_id=" . $assessment_id);
                }
                exit();
                
            } else {
                throw new Exception("Database error: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        } finally {
            $conn->autocommit(TRUE);
        }
    } else {
        // Display validation errors
        $error_message = "";
        foreach ($errors as $error) {
            $error_message .= "<div class='alert alert-danger'>$error</div>";
        }
    }
}

// Helper function to calculate coverage
function calculateCoverage($total_amount, $assessment_type, $philhealth_eligible, $hmo_provider) {
    $philhealth_coverage = 0;
    $hmo_coverage = 0;
    
    // Define coverage percentages
    $coverage_matrix = [
        'Charity' => [
            'philhealth' => 0.8, // 80%
            'hmo' => 0.2         // 20%
        ],
        'Partial' => [
            'philhealth' => 0.5, // 50%
            'hmo' => 0.3         // 30%
        ],
        'Paying' => [
            'philhealth' => 0.2, // 20%
            'hmo' => 0.1         // 10%
        ]
    ];
    
    $matrix = $coverage_matrix[$assessment_type] ?? $coverage_matrix['Partial'];
    
    if ($philhealth_eligible) {
        $philhealth_coverage = $total_amount * $matrix['philhealth'];
    }
    
    if (!empty($hmo_provider)) {
        $hmo_coverage = $total_amount * $matrix['hmo'];
    }
    
    // Ensure total coverage doesn't exceed total amount
    $total_coverage = $philhealth_coverage + $hmo_coverage;
    if ($total_coverage > $total_amount) {
        $ratio = $total_amount / $total_coverage;
        $philhealth_coverage *= $ratio;
        $hmo_coverage *= $ratio;
    }
    
    $amount_due = $total_amount - $philhealth_coverage - $hmo_coverage;
    
    return [
        'philhealth_coverage' => round($philhealth_coverage, 2),
        'hmo_coverage' => round($hmo_coverage, 2),
        'amount_due' => round($amount_due, 2),
        'coverage_percentage' => $total_amount > 0 ? round(($total_coverage / $total_amount) * 100, 2) : 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Hospital Dashboard - <?php echo $edit ? "Edit Financial Assessment" : "New Financial Assessment"; ?></title>

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
    display: inline-flex;
    align-items: center;
    gap: 6px;
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
  .btn-success {
    background: #27ae60;
    color: #fff;
  }
  .btn-warning {
    background: #f39c12;
    color: #fff;
  }
  .btn-info {
    background: #3498db;
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
    display: flex;
    align-items: center;
    gap: 10px;
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

  /* Patient info card */
  .patient-card {
    background: linear-gradient(135deg, #f8fbfd 0%, #e8f4ff 100%);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid #d1e7e7;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  }

  .patient-card h4 {
    margin: 0 0 15px 0;
    color: var(--navy-700);
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .patient-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
  }

  .info-item {
    display: flex;
    flex-direction: column;
  }

  .info-label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 600;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .info-value {
    font-size: 14px;
    font-weight: 500;
    color: #1e3a5f;
  }

  .unpaid-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #fce6e8;
    color: #b02b2b;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 4px;
  }

  /* Form styles */
  .form-container {
    background: var(--panel);
    padding: 28px;
    border-radius: 12px;
    box-shadow: var(--card-shadow);
    max-width: 800px;
    margin: 0 auto;
    border: 1px solid #f0f4f8;
  }

  .form-group {
    margin-bottom: 24px;
    position: relative;
  }

  .form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #1e3a5f;
    font-size: 14px;
  }

  .form-control, .form-select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid #e6eef0;
    border-radius: 8px;
    font-family: Inter, sans-serif;
    font-size: 14px;
    transition: all 0.2s ease;
    background: #fff;
  }

  .form-control:focus, .form-select:focus {
    outline: none;
    border-color: var(--light-blue);
    box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
  }

  .form-control[readonly] {
    background-color: #f8fbfd;
    color: #1e3a5f;
    font-weight: 600;
    border-color: #d1e7e7;
  }

  .required:after {
    content: " *";
    color: #e74c3c;
  }

  /* Checkbox styling */
  .form-check {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 15px 0;
  }

  .form-check-input {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--navy-700);
  }
  
  .form-check-label {
    cursor: pointer;
    font-weight: 500;
    color: #1e3a5f;
    font-size: 14px;
  }

  /* Assessment type cards */
  .assessment-type-selector {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 15px 0;
  }

  .type-card {
    border: 2px solid #e6eef0;
    border-radius: 10px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-align: center;
    background: white;
  }

  .type-card:hover {
    border-color: var(--navy-700);
    background: #f8fbfd;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16,24,40,0.08);
  }

  .type-card.selected {
    border-color: var(--navy-700);
    background: #e8f4ff;
    box-shadow: 0 4px 12px rgba(0, 31, 63, 0.1);
  }

  .type-name {
    font-weight: 600;
    color: #1e3a5f;
    margin-bottom: 6px;
  }

  .type-desc {
    font-size: 12px;
    color: var(--muted);
    line-height: 1.4;
  }

  /* Coverage preview */
  .coverage-preview {
    background: linear-gradient(135deg, #f8fbfd 0%, #e8f4ff 100%);
    border-radius: 10px;
    padding: 20px;
    margin: 20px 0;
    border: 1px solid #d1e7e7;
  }

  .coverage-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
  }

  .coverage-header h5 {
    margin: 0;
    color: var(--navy-700);
  }

  .coverage-percentage {
    background: var(--navy-700);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
  }

  .coverage-bars {
    margin: 15px 0;
  }

  .coverage-bar {
    height: 24px;
    background: #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 10px;
    position: relative;
  }

  .coverage-fill {
    height: 100%;
    border-radius: 12px;
    transition: width 0.5s ease;
  }

  .philhealth-fill {
    background: linear-gradient(90deg, #1e6b8a, #4d8cc9);
  }

  .hmo-fill {
    background: linear-gradient(90deg, #003366, #6f42c1);
  }

  .coverage-label {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: white;
    font-weight: 600;
    font-size: 12px;
    text-shadow: 1px 1px 1px rgba(0,0,0,0.3);
  }

  .coverage-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-top: 20px;
  }

  .summary-item {
    text-align: center;
    padding: 12px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e6eef0;
  }

  .summary-label {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 6px;
  }

  .summary-value {
    font-size: 18px;
    font-weight: 700;
    color: #1e3a5f;
  }

  .summary-amount-due {
    color: #e74c3c;
  }

  /* Form actions */
  .form-actions {
    display: flex;
    gap: 12px;
    margin-top: 30px;
    padding-top: 25px;
    border-top: 2px solid #f0f3f4;
  }

  /* Role badge */
  .role-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
    margin-left: 10px;
  }
  .role-admin { background: #001F3F; color: white; }
  .role-billing { background: #003366; color: white; }
  .role-socialworker { background: #34495e; color: white; }
  .role-doctor { background: #003366; color: white; }
  .role-nurse { background: #4d8cc9; color: white; }
  .role-staff { background: #6b7280; color: white; }
  .role-inventory { background: #1e6b8a; color: white; }

  /* Clickable image */
  .clickable-image {
    cursor: pointer;
    transition: transform 0.2s ease;
  }
  .clickable-image:hover {
    transform: scale(1.02);
  }

  /* Footer shadow */
  .footer-shadow{height:48px;background:linear-gradient(180deg,transparent,rgba(3,7,18,0.04));pointer-events:none;position:fixed;left:0;right:0;bottom:0}

  /* Responsive */
  @media (max-width: 1100px) {
    .assessment-type-selector {
      grid-template-columns: 1fr;
    }
    
    .coverage-summary {
      grid-template-columns: 1fr;
    }
  }
  
  @media (max-width: 780px) {
    .sidebar {
      position: fixed;
      left: -320px;
      transform: translateX(0);
    }
    .sidebar.open {
      left: 0;
      transform: translateX(0);
    }
    .main {
      margin-left: 0;
      padding: 12px;
    }
    .sidebar.collapsed {
      width: 230px;
    }
    .topbar {
      flex-direction: column;
      align-items: flex-start;
    }
    .top-actions {
      width: 100%;
      justify-content: space-between;
    }
    .form-actions {
      flex-direction: column;
    }
    .btn {
      width: 100%;
      text-align: center;
      justify-content: center;
    }
    .patient-info-grid {
      grid-template-columns: 1fr;
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
        <a href="dashboard.php" class="clickable-image">
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
          <h1>
            <?php 
            if ($bill_id && !$edit) {
                echo "Link Financial Assessment to Bill";
            } else {
                echo $edit ? "Edit Financial Assessment #" . $id : "New Financial Assessment";
            }
            ?>
            <span class="role-badge role-<?php echo strtolower($current_role); ?>">
              <?php echo htmlspecialchars($current_role); ?>
            </span>
          </h1>
          <p>
            <?php 
            if ($bill_id && !$edit) {
                echo "Create financial assessment for bill TX-" . $bill_id;
            } else {
                echo $edit ? "Update patient's financial assessment details" : "Assess patient's financial capability and coverage";
            }
            ?> - Gig Oca Robles Seamen's Hospital Davao Management System
          </p>
        </div>

        <div class="top-actions">
          <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
        </div>
      </div>

      <!-- Alert container -->
      <div class="alert-container">
        <?php 
        if (!empty($error_message)) echo $error_message;
        if (!empty($success_message)) echo "<div class='alert alert-success'>" . $success_message . "</div>";
        ?>
      </div>

      <!-- Patient Info Card -->
      <?php if (isset($patient_info)): ?>
      <div class="patient-card">
        <h4>
          <span>Patient Information</span>
          <?php if ($bill_id && !$edit): ?>
            <span style="font-size: 14px; background: #e8f4ff; color: var(--navy-700); padding: 4px 8px; border-radius: 12px;">
              Bill TX-<?php echo $bill_id; ?>
            </span>
          <?php endif; ?>
        </h4>
        <div class="patient-info-grid">
          <div class="info-item">
            <span class="info-label">Name</span>
            <span class="info-value"><?php echo htmlspecialchars($patient_info['name']); ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Patient Code</span>
            <span class="info-value"><?php echo htmlspecialchars($patient_info['code']); ?></span>
          </div>
          <?php if (!empty($patient_info['phone'])): ?>
          <div class="info-item">
            <span class="info-label">Phone</span>
            <span class="info-value"><?php echo htmlspecialchars($patient_info['phone']); ?></span>
          </div>
          <?php endif; ?>
          <?php if (!empty($patient_info['dob'])): ?>
          <div class="info-item">
            <span class="info-label">Date of Birth</span>
            <span class="info-value"><?php echo date('M j, Y', strtotime($patient_info['dob'])); ?></span>
          </div>
          <?php endif; ?>
          <?php if ($edit && $patient_info['bills'] > 0): ?>
          <div class="info-item">
            <span class="info-label">Linked Bills</span>
            <span class="info-value"><?php echo $patient_info['bills']; ?> bill(s)</span>
            <?php if ($patient_info['unpaid'] > 0): ?>
              <span class="unpaid-badge">
                ₱ <?php echo number_format($patient_info['unpaid'], 2); ?> unpaid
              </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if ($bill_id && !$edit && isset($patient_info['bill_amount'])): ?>
          <div class="info-item">
            <span class="info-label">Bill Amount</span>
            <span class="info-value" style="font-weight: 700; color: #e74c3c;">
              ₱ <?php echo number_format($patient_info['bill_amount'], 2); ?>
            </span>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Form -->
      <div class="form-container">
        <form method="POST" onsubmit="return validateForm()">
          <input type="hidden" name="id" value="<?php echo h($id); ?>">
          <input type="hidden" name="bill_id" value="<?php echo h($bill_id); ?>">
          
          <?php if ($edit || (isset($patient_id) && $patient_id > 0)): ?>
            <!-- For edits or when patient is already known -->
            <input type="hidden" name="patient_id" value="<?php echo h($patient_id); ?>">
          <?php else: ?>
            <!-- For new assessments without specific patient -->
            <div class="form-group">
              <label class="form-label required">Patient</label>
              <select name="patient_id" class="form-select" required onchange="loadPatientInfo(this.value)">
                <option value="">Select Patient</option>
                <?php foreach($patients as $patient): ?>
                  <option value="<?php echo h($patient['id']); ?>" 
                    <?php echo $patient_id == $patient['id'] ? 'selected' : ''; ?>>
                    <?php echo h($patient['full_name']); ?> (<?php echo h($patient['patient_code']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <!-- Assessment Type -->
          <div class="form-group">
            <label class="form-label required">Assessment Type</label>
            <div class="assessment-type-selector">
              <div class="type-card" data-type="Charity" onclick="selectType('Charity')" 
                   id="type-charity" <?php echo $assessment_type == 'Charity' ? 'class="selected"' : ''; ?>>
                <div class="type-name">Charity</div>
                <div class="type-desc">Full financial assistance - 100% coverage</div>
              </div>
              <div class="type-card" data-type="Partial" onclick="selectType('Partial')" 
                   id="type-partial" <?php echo $assessment_type == 'Partial' ? 'class="selected"' : ''; ?>>
                <div class="type-name">Partial</div>
                <div class="type-desc">Partial assistance - 50-80% coverage</div>
              </div>
              <div class="type-card" data-type="Paying" onclick="selectType('Paying')" 
                   id="type-paying" <?php echo $assessment_type == 'Paying' ? 'class="selected"' : ''; ?>>
                <div class="type-name">Paying</div>
                <div class="type-desc">Full payment - 20-30% coverage</div>
              </div>
            </div>
            <input type="hidden" name="assessment_type" id="assessment_type" value="<?php echo h($assessment_type); ?>" required>
          </div>

          <!-- Insurance Coverage -->
          <div class="form-group">
            <label class="form-label">Insurance Coverage</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="philhealth_eligible" id="philhealth_eligible" 
                <?php echo $philhealth_eligible ? 'checked' : ''; ?> onchange="updateCoveragePreview()">
              <label class="form-check-label" for="philhealth_eligible">
                PhilHealth Eligible
              </label>
            </div>
            
            <div style="margin-top: 15px;">
              <label class="form-label">HMO Provider</label>
              <select name="hmo_provider" class="form-select" onchange="updateCoveragePreview()">
                <option value="">No HMO Coverage</option>
                <?php foreach($hmo_providers as $provider): ?>
                  <option value="<?php echo h($provider); ?>" 
                    <?php echo $hmo_provider == $provider ? 'selected' : ''; ?>>
                    <?php echo h($provider); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Coverage Preview -->
          <?php if ($bill_id && isset($patient_info['bill_amount'])): ?>
          <div class="coverage-preview" id="coveragePreview">
            <div class="coverage-header">
              <h5>Coverage Preview</h5>
              <div class="coverage-percentage" id="coveragePercent">0%</div>
            </div>
            
            <div class="coverage-bars">
              <div class="coverage-bar">
                <div class="coverage-fill philhealth-fill" id="philhealthBar" style="width: 0%">
                  <span class="coverage-label" id="philhealthLabel">PhilHealth: ₱0.00</span>
                </div>
              </div>
              <div class="coverage-bar">
                <div class="coverage-fill hmo-fill" id="hmoBar" style="width: 0%">
                  <span class="coverage-label" id="hmoLabel">HMO: ₱0.00</span>
                </div>
              </div>
            </div>
            
            <div class="coverage-summary">
              <div class="summary-item">
                <div class="summary-label">Total Amount</div>
                <div class="summary-value" id="totalAmount">₱ <?php echo number_format($patient_info['bill_amount'], 2); ?></div>
              </div>
              <div class="summary-item">
                <div class="summary-label">Total Coverage</div>
                <div class="summary-value" id="totalCoverage">₱ 0.00</div>
              </div>
              <div class="summary-item">
                <div class="summary-label">Amount Due</div>
                <div class="summary-value summary-amount-due" id="amountDue">₱ <?php echo number_format($patient_info['bill_amount'], 2); ?></div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Status -->
          <div class="form-group">
            <label class="form-label required">Status</label>
            <select name="status" class="form-select" required>
              <option value="Pending" <?php echo $status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
              <option value="Approved" <?php echo $status == 'Approved' ? 'selected' : ''; ?>>Approved</option>
              <option value="Rejected" <?php echo $status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
          </div>

          <!-- Form Actions -->
          <div class="form-actions">
            <button type="submit" class="btn">
              <?php if ($bill_id && !$edit): ?>
                <span>Link Assessment to Bill</span>
              <?php else: ?>
                <span><?php echo $edit ? 'Save Changes' : 'Create Assessment'; ?></span>
              <?php endif; ?>
            </button>
            <a href="<?php echo $bill_id ? 'billing.php' : 'financials.php'; ?>" class="btn btn-secondary">Cancel</a>
            <?php if ($edit): ?>
              <a href="financial_view.php?id=<?php echo h($id); ?>" class="btn btn-info" target="_blank">View Assessment</a>
            <?php endif; ?>
            <?php if ($bill_id): ?>
              <a href="billing_view.php?id=<?php echo h($bill_id); ?>" class="btn btn-warning" target="_blank">View Bill</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div><!-- .main -->

  </div><!-- .app -->

  <div class="footer-shadow"></div>

  <script>
    // Assessment type selection
    function selectType(type) {
        document.getElementById('assessment_type').value = type;
        
        // Update UI
        document.querySelectorAll('.type-card').forEach(card => {
            card.classList.remove('selected');
        });
        document.getElementById('type-' + type.toLowerCase()).classList.add('selected');
        
        updateCoveragePreview();
    }
    
    // Coverage calculation for billing integration
    function calculateCoverage(totalAmount, assessmentType, philhealthEligible, hmoProvider) {
        let philhealthCoverage = 0;
        let hmoCoverage = 0;
        
        // Define coverage percentages
        const coverageMatrix = {
            'Charity': { philhealth: 0.8, hmo: 0.2 },
            'Partial': { philhealth: 0.5, hmo: 0.3 },
            'Paying': { philhealth: 0.2, hmo: 0.1 }
        };
        
        const matrix = coverageMatrix[assessmentType] || coverageMatrix['Partial'];
        
        if (philhealthEligible) {
            philhealthCoverage = totalAmount * matrix.philhealth;
        }
        
        if (hmoProvider && hmoProvider !== '') {
            hmoCoverage = totalAmount * matrix.hmo;
        }
        
        // Ensure total coverage doesn't exceed total amount
        const totalCoverage = philhealthCoverage + hmoCoverage;
        if (totalCoverage > totalAmount) {
            const ratio = totalAmount / totalCoverage;
            philhealthCoverage *= ratio;
            hmoCoverage *= ratio;
        }
        
        const amountDue = totalAmount - philhealthCoverage - hmoCoverage;
        const coveragePercentage = totalAmount > 0 ? (totalCoverage / totalAmount) * 100 : 0;
        
        return {
            philhealthCoverage: philhealthCoverage,
            hmoCoverage: hmoCoverage,
            totalCoverage: totalCoverage,
            amountDue: amountDue,
            coveragePercentage: coveragePercentage
        };
    }
    
    // Update coverage preview
    function updateCoveragePreview() {
        <?php if ($bill_id && isset($patient_info['bill_amount'])): ?>
        const totalAmount = <?php echo $patient_info['bill_amount']; ?>;
        const assessmentType = document.getElementById('assessment_type').value;
        const philhealthEligible = document.getElementById('philhealth_eligible').checked;
        const hmoSelect = document.querySelector('select[name="hmo_provider"]');
        const hmoProvider = hmoSelect ? hmoSelect.value : '';
        
        const coverage = calculateCoverage(totalAmount, assessmentType, philhealthEligible, hmoProvider);
        
        // Update bars
        const philhealthPercent = (coverage.philhealthCoverage / totalAmount) * 100;
        const hmoPercent = (coverage.hmoCoverage / totalAmount) * 100;
        
        document.getElementById('philhealthBar').style.width = philhealthPercent + '%';
        document.getElementById('hmoBar').style.width = hmoPercent + '%';
        
        // Update labels
        document.getElementById('philhealthLabel').textContent = 
            `PhilHealth: ₱${coverage.philhealthCoverage.toFixed(2)}`;
        document.getElementById('hmoLabel').textContent = 
            `HMO: ₱${coverage.hmoCoverage.toFixed(2)}`;
        
        // Update summary
        document.getElementById('totalCoverage').textContent = 
            `₱ ${coverage.totalCoverage.toFixed(2)}`;
        document.getElementById('amountDue').textContent = 
            `₱ ${coverage.amountDue.toFixed(2)}`;
        document.getElementById('coveragePercent').textContent = 
            `${coverage.coveragePercentage.toFixed(1)}%`;
        <?php endif; ?>
    }
    
    // Load patient info via AJAX
    function loadPatientInfo(patientId) {
        if (!patientId) return;
        
        // In a real implementation, you would fetch patient details via AJAX
        console.log('Loading patient info for ID:', patientId);
    }
    
    // Form validation
    function validateForm() {
        const patientId = document.querySelector('select[name="patient_id"]')?.value || 
                         document.querySelector('input[name="patient_id"]')?.value;
        const assessmentType = document.getElementById('assessment_type').value;
        
        if (!patientId) {
            alert('Please select a patient.');
            const patientSelect = document.querySelector('select[name="patient_id"]');
            if (patientSelect) patientSelect.focus();
            return false;
        }
        
        if (!assessmentType) {
            alert('Please select an assessment type.');
            return false;
        }
        
        return true;
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize assessment type selection
        const currentType = document.getElementById('assessment_type').value;
        if (currentType) {
            selectType(currentType);
        }
        
        // Initialize coverage preview
        updateCoveragePreview();
        
        // Auto-calculate if we have a bill
        <?php if ($bill_id && isset($patient_info['bill_amount'])): ?>
        // Trigger initial calculation
        updateCoveragePreview();
        <?php endif; ?>
    });
  </script>
</body>
</html>