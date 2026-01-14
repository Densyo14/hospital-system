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

// Preprocessing
$success = $_GET['success'] ?? '';
$action = $_GET['action'] ?? '';
$report_type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? '';

// Current user info for permission checks
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_doctor = ($current_user['role'] ?? '') === 'Doctor';
$is_nurse = ($current_user['role'] ?? '') === 'Nurse';
$is_staff = ($current_user['role'] ?? '') === 'Staff';
$is_inventory = ($current_user['role'] ?? '') === 'Inventory';
$is_billing = ($current_user['role'] ?? '') === 'Billing';
$is_social_worker = ($current_user['role'] ?? '') === 'SocialWorker';

// Define report permissions for each role
$report_permissions = [
    'Admin' => ['patient', 'appointment', 'surgery', 'billing', 'inventory', 'financial', 'export'],
    'Doctor' => ['patient', 'appointment', 'surgery'],
    'Nurse' => ['patient', 'appointment'],
    'Staff' => ['patient', 'appointment'],
    'Inventory' => ['inventory'],
    'Billing' => ['billing', 'financial'],
    'SocialWorker' => ['financial']
];

// Function to check if user has permission for a report type
function hasReportPermission($user_role, $report_type, $report_permissions) {
    if (empty($report_type)) return true; // No specific report requested
    return in_array($report_type, $report_permissions[$user_role] ?? []);
}

// Check permission for current report
if (!hasReportPermission($current_role, $report_type, $report_permissions) && !empty($report_type)) {
    header("Location: reports.php?success=error&message=Access denied. You don't have permission to access this report.");
    exit();
}

// Get date filter parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Validate and sanitize dates
if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = '';
}
if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = '';
}

// Build WHERE clause for date filtering
$date_where = '';
$date_params = [];
$date_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $date_where = " WHERE DATE(created_at) BETWEEN ? AND ?";
    $date_params = [$start_date, $end_date];
    $date_types = 'ss';
} elseif (!empty($start_date)) {
    $date_where = " WHERE DATE(created_at) >= ?";
    $date_params = [$start_date];
    $date_types = 's';
} elseif (!empty($end_date)) {
    $date_where = " WHERE DATE(created_at) <= ?";
    $date_params = [$end_date];
    $date_types = 's';
}

// CSV Export Functionality
function generateCSV($data, $filename) {
    if (empty($data)) {
        return false;
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 compatibility with Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    if (!empty($data[0])) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Write data rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Function to safely get column value
function safeColumnSelect($conn, $table, $columns) {
    $existing_columns = [];
    $result = $conn->query("SHOW COLUMNS FROM $table");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
    }
    
    $selected_columns = [];
    foreach ($columns as $column) {
        if (in_array($column, $existing_columns)) {
            $selected_columns[] = "$column";
        }
    }
    
    return $selected_columns;
}

// Handle CSV Export with permission check
if ($report_type && $format === 'csv') {
    // Check permission again for CSV export
    if (!hasReportPermission($current_role, $report_type, $report_permissions)) {
        header("Location: reports.php?success=error&message=Access denied. You don't have permission to download this report.");
        exit();
    }
    
    $filename = '';
    $data = [];
    
    // Add date range to filename
    $date_suffix = '';
    if (!empty($start_date) && !empty($end_date)) {
        $date_suffix = '_' . $start_date . '_to_' . $end_date;
    } elseif (!empty($start_date)) {
        $date_suffix = '_from_' . $start_date;
    } elseif (!empty($end_date)) {
        $date_suffix = '_until_' . $end_date;
    }
    
    switch ($report_type) {
        case 'patient':
            if (!in_array($current_role, ['Admin', 'Doctor', 'Nurse', 'Staff'])) {
                header("Location: reports.php?success=error&message=Access denied. You don't have permission to download patient reports.");
                exit();
            }
            $filename = 'patient_statistics' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            // Build query with date filtering
            $base_query = "
                SELECT 
                    p.id,
                    p.patient_code,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    p.birth_date,
                    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age,
                    p.sex,
                    p.phone,
                    p.address,
                    p.guardian_name,
                    p.blood_type,
                    p.weight,
                    p.height,
                    p.pulse_rate,
                    p.temperature,
                    p.created_at,
                    p.is_archived,
                    CASE WHEN p.is_archived = 1 THEN 'Archived' ELSE 'Active' END as status,
                    COUNT(DISTINCT a.id) as total_appointments,
                    COUNT(DISTINCT s.id) as total_surgeries
                FROM patients p
                LEFT JOIN appointments a ON p.id = a.patient_id
                LEFT JOIN surgeries s ON p.id = s.patient_id
            ";
            
            // Add date filter if specified
            if (!empty($date_where)) {
                $base_query .= str_replace('created_at', 'p.created_at', $date_where);
            }
            
            $base_query .= " GROUP BY p.id ORDER BY p.created_at DESC";
            
            $data = fetchAll($conn, $base_query, $date_types, $date_params);
            break;
            
        case 'appointment':
            if (!in_array($current_role, ['Admin', 'Doctor', 'Nurse', 'Staff'])) {
                header("Location: reports.php?success=error&message=Access denied. You don't have permission to download appointment reports.");
                exit();
            }
            $filename = 'appointment_summary' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            
            // Get existing columns in appointments table
            $appointment_columns = safeColumnSelect($conn, 'appointments', [
                'id', 'patient_id', 'doctor_id', 'appointment_time', 'appointment_date', 
                'date', 'time', 'status', 'reason', 'notes', 'is_archived', 'created_at'
            ]);
            
            // Build column list
            $column_list = empty($appointment_columns) ? 'a.*' : 'a.' . implode(', a.', $appointment_columns);
            
            // Build query
            $base_query = "
                SELECT 
                    $column_list,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    u.full_name as doctor_name
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.doctor_id = u.id
            ";
            
            // Add date filter if specified
            if (!empty($date_where)) {
                $base_query .= str_replace('created_at', 'a.created_at', $date_where);
            } else {
                $base_query .= " WHERE 1=1";
            }
            
            $base_query .= " ORDER BY a.created_at DESC";
            
            $data = fetchAll($conn, $base_query, $date_types, $date_params);
            break;
            
        case 'surgery':
            if (!in_array($current_role, ['Admin', 'Doctor'])) {
                header("Location: reports.php?success=error&message=Access denied. You don't have permission to download surgery reports.");
                exit();
            }
            $filename = 'surgery_reports' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            
            // Get existing columns in surgeries table
            $surgery_columns = safeColumnSelect($conn, 'surgeries', [
                'id', 'patient_id', 'doctor_id', 'surgery_type', 'surgery_time', 
                'surgery_date', 'schedule_date', 'date', 'operating_room', 'room',
                'status', 'notes', 'description', 'is_archived', 'created_at'
            ]);
            
            // Build column list
            $column_list = empty($surgery_columns) ? 's.*' : 's.' . implode(', s.', $surgery_columns);
            
            // Build query
            $base_query = "
                SELECT 
                    $column_list,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    u.full_name as doctor_name
                FROM surgeries s
                LEFT JOIN patients p ON s.patient_id = p.id
                LEFT JOIN users u ON s.doctor_id = u.id
            ";
            
            // Add date filter if specified
            if (!empty($date_where)) {
                $base_query .= str_replace('created_at', 's.created_at', $date_where);
            } else {
                $base_query .= " WHERE 1=1";
            }
            
            $base_query .= " ORDER BY s.created_at DESC";
            
            $data = fetchAll($conn, $base_query, $date_types, $date_params);
            break;
            
        case 'billing':
            if (!in_array($current_role, ['Admin', 'Billing'])) {
                header("Location: reports.php?success=error&message=Access denied. You don't have permission to download billing reports.");
                exit();
            }
            $filename = 'billing_summary' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            // Build query with date filtering
            $base_query = "
                SELECT 
                    b.id,
                    b.patient_id,
                    b.surgery_id,
                    b.total_amount,
                    b.philhealth_coverage,
                    b.hmo_coverage,
                    b.amount_due,
                    b.status,
                    b.paid_at,
                    b.is_archived,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    s.surgery_type,
                    b.created_at
                FROM billing b
                LEFT JOIN patients p ON b.patient_id = p.id
                LEFT JOIN surgeries s ON b.surgery_id = s.id
            ";
            
            // Add date filter if specified
            if (!empty($date_where)) {
                $base_query .= str_replace('created_at', 'b.created_at', $date_where);
            } else {
                $base_query .= " WHERE 1=1";
            }
            
            $base_query .= " ORDER BY b.created_at DESC";
            
            $data = fetchAll($conn, $base_query, $date_types, $date_params);
            break;
            
        case 'inventory':
            if (!in_array($current_role, ['Admin', 'Inventory'])) {
                header("Location: reports.php?success=error&message=Access denied. You don't have permission to download inventory reports.");
                exit();
            }
            $filename = 'inventory_usage' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            // Build query with date filtering
            $base_query = "
                SELECT 
                    i.id,
                    i.item_name,
                    i.category,
                    i.quantity,
                    i.threshold,
                    i.unit,
                    i.updated_at,
                    i.is_archived,
                    CASE 
                        WHEN i.quantity <= i.threshold THEN 'Low Stock'
                        ELSE 'In Stock'
                    END as stock_status
                FROM inventory_items i
            ";
            
            // Add date filter if specified
            if (!empty($date_where)) {
                $base_query .= str_replace('created_at', 'i.updated_at', $date_where);
            } else {
                $base_query .= " WHERE 1=1";
            }
            
            $base_query .= " ORDER BY i.updated_at DESC";
            
            $data = fetchAll($conn, $base_query, $date_types, $date_params);
            break;
            
        case 'financial':
            if (!in_array($current_role, ['Admin', 'Billing', 'SocialWorker'])) {
                header("Location: reports.php?success=error&message=Access denied. You don't have permission to download financial reports.");
                exit();
            }
            $filename = 'financial_assessments' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            // Build query with date filtering
            $base_query = "
                SELECT 
                    f.id,
                    f.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    f.assessment_type,
                    CASE f.philhealth_eligible 
                        WHEN 1 THEN 'Yes'
                        ELSE 'No'
                    END as philhealth_eligible,
                    f.hmo_provider,
                    f.status,
                    f.reviewed_at,
                    f.is_archived,
                    f.created_at
                FROM financial_assessment f
                INNER JOIN patients p ON f.patient_id = p.id
            ";
            
            // Add date filter if specified
            if (!empty($date_where)) {
                $base_query .= str_replace('created_at', 'f.created_at', $date_where);
            } else {
                $base_query .= " WHERE 1=1";
            }
            
            $base_query .= " ORDER BY f.created_at DESC";
            
            $data = fetchAll($conn, $base_query, $date_types, $date_params);
            break;
            
        case 'export':
            // Comprehensive export for admins only
            if (!$is_admin) {
                header("Location: reports.php?success=error&message=Access denied. Only administrators can perform comprehensive exports.");
                exit();
            }
            $filename = 'comprehensive_export' . $date_suffix . '_' . date('Y-m-d') . '.csv';
            
            // Collect data from all tables
            $tables_data = [];
            
            // Patients summary with date filter
            $patients_query = "
                SELECT 
                    'Patients' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_count,
                    MAX(created_at) as latest_record
                FROM patients
            " . (!empty($date_where) ? $date_where : '');
            $patients_data = fetchOne($conn, $patients_query, $date_types, $date_params);
            if ($patients_data) $tables_data[] = $patients_data;
            
            // Appointments summary with date filter
            $appointments_query = "
                SELECT 
                    'Appointments' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_count,
                    MAX(created_at) as latest_record
                FROM appointments
            " . (!empty($date_where) ? $date_where : '');
            $appointments_data = fetchOne($conn, $appointments_query, $date_types, $date_params);
            if ($appointments_data) $tables_data[] = $appointments_data;
            
            // Surgeries summary with date filter
            $surgeries_query = "
                SELECT 
                    'Surgeries' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_count,
                    MAX(created_at) as latest_record
                FROM surgeries
            " . (!empty($date_where) ? $date_where : '');
            $surgeries_data = fetchOne($conn, $surgeries_query, $date_types, $date_params);
            if ($surgeries_data) $tables_data[] = $surgeries_data;
            
            // Inventory items summary with date filter
            $inventory_query = "
                SELECT 
                    'Inventory Items' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_count,
                    MAX(updated_at) as latest_record
                FROM inventory_items
            " . (!empty($date_where) ? str_replace('created_at', 'updated_at', $date_where) : '');
            $inventory_data = fetchOne($conn, $inventory_query, $date_types, $date_params);
            if ($inventory_data) $tables_data[] = $inventory_data;
            
            // Billing summary with date filter
            $billing_query = "
                SELECT 
                    'Billing Records' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_count,
                    MAX(created_at) as latest_record
                FROM billing
            " . (!empty($date_where) ? $date_where : '');
            $billing_data = fetchOne($conn, $billing_query, $date_types, $date_params);
            if ($billing_data) $tables_data[] = $billing_data;
            
            // Financial assessments summary with date filter
            $financial_query = "
                SELECT 
                    'Financial Assessments' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) as archived_count,
                    MAX(created_at) as latest_record
                FROM financial_assessment
            " . (!empty($date_where) ? $date_where : '');
            $financial_data = fetchOne($conn, $financial_query, $date_types, $date_params);
            if ($financial_data) $tables_data[] = $financial_data;
            
            // Users summary with date filter
            $users_query = "
                SELECT 
                    'Users' as category,
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_count,
                    MAX(created_at) as latest_record
                FROM users
            " . (!empty($date_where) ? $date_where : '');
            $users_data = fetchOne($conn, $users_query, $date_types, $date_params);
            if ($users_data) $tables_data[] = $users_data;
            
            // Medical records summary with date filter
            $medical_query = "
                SELECT 
                    'Medical Records' as category,
                    COUNT(*) as total_count,
                    MAX(created_at) as latest_record
                FROM medical_records
            " . (!empty($date_where) ? $date_where : '');
            $medical_data = fetchOne($conn, $medical_query, $date_types, $date_params);
            if ($medical_data) $tables_data[] = $medical_data;
            
            $data = $tables_data;
            break;
    }
    
    if (!empty($data) && !empty($filename)) {
        generateCSV($data, $filename);
    } else {
        // If no data or empty, redirect back with error
        header("Location: reports.php?success=error&message=No data available for export");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Hospital Dashboard - Reports</title>
    
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

        *{box-sizing:border-box; margin:0; padding:0;}
        html,body{height:100%; font-family: 'Inter', sans-serif;}
        body{
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
            z-index:30;
            overflow-y: auto;
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
            color:rgba(255,255,255,0.95);
            font-weight:500;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        .menu-item:hover {
            background: linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
        }
        .menu-item.active{ 
            background: linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04)); 
            border-left:4px solid #9bcfff; 
            padding-left:5px; 
        }
        .menu-item svg{
            width:16px;height:16px;opacity:.95;
            fill: white;
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

        .sidebar-bottom {
            margin-top:auto;
            font-size:13px;
            color:rgba(255,255,255,0.8);
            opacity:0.95;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        /* MAIN content */
        .main {
            margin-left:230px;
            padding:18px 28px;
            width:100%;
            flex:1;
        }

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
            border:none;
            font-weight:600;
            text-decoration: none;
            display: inline-block;
            cursor:pointer;
            transition: all 0.2s ease;
        }
        .btn:hover {
            background:var(--accent);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 31, 63, 0.2);
        }
        .btn-outline {
            background: transparent;
            color: var(--navy-700);
            border: 1px solid var(--navy-700);
        }
        .btn-outline:hover {
            background: var(--navy-700);
            color: white;
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
            background: #001F3F;
            border-left: 4px solid #003366;
        }

        .alert-error {
            background: #e74c3c;
            border-left: 4px solid #c0392b;
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

        /* Date Filter Styles */
        .date-filter-container {
            background: #f8fbfd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #4d8cc9;
        }

        .date-filter-container h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #1e3a5f;
            font-size: 18px;
        }

        .filter-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }

        .form-control {
            padding: 10px 12px;
            border: 1px solid #e6eef0;
            border-radius: 6px;
            font-size: 14px;
            min-width: 200px;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--light-blue);
            box-shadow: 0 0 0 3px rgba(77, 140, 201, 0.1);
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-filter {
            background: var(--navy-700);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-filter:hover {
            background: var(--accent);
            transform: translateY(-1px);
        }

        .btn-reset {
            background: #6b7280;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s ease;
        }
        .btn-reset:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .filter-info {
            margin-top: 10px;
            padding: 10px;
            background: #e8f4ff;
            border-radius: 6px;
            font-size: 14px;
            color: #1e6b8a;
        }

        /* Reports container */
        .reports-container {
            background:var(--panel);
            padding:24px;
            border-radius:12px;
            box-shadow:var(--card-shadow);
            margin-top: 20px;
            border: 1px solid #f0f4f8;
        }

        .reports-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #eef2f7;
        }

        .reports-header h2 {
            font-size: 24px;
            margin: 0 0 8px 0;
            color: #1e3a5f;
        }

        .reports-header p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        /* Report cards */
        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .report-card {
            background: #f8fbfd;
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid var(--navy-700);
            border: 1px solid #f0f4f8;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,24,40,0.12);
            border-color: var(--light-blue);
        }

        .report-card h3 {
            margin: 0 0 12px 0;
            font-size: 18px;
            color: #1e3a5f;
        }

        .report-card p {
            margin: 0 0 16px 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-stats {
            font-size: 12px;
            color: #888;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
        }

        /* Report list */
        .report-list {
            margin-top: 30px;
        }

        .report-list h3 {
            font-size: 18px;
            margin-bottom: 16px;
            color: #1e3a5f;
        }

        .report-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .report-list li {
            padding: 12px 16px;
            margin-bottom: 8px;
            background: #f8fbfd;
            border-radius: 8px;
            border-left: 3px solid #4d8cc9;
        }

        /* Export Options */
        .export-options {
            background: #f8fbfd;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #4d8cc9;
            margin-top: 30px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
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

        .no-permission {
            opacity: 0.6;
        }
        .no-permission .btn-sm {
            background: #6b7280 !important;
            cursor: not-allowed;
        }

        /* Responsive */
        @media (max-width:780px){
            .sidebar{
                position:fixed;
                left:-230px;
                transition: left 0.3s ease;
            }
            .sidebar.open{
                left:0;
            }
            .main{
                margin-left:0;
                padding:12px;
            }
            .report-grid {
                grid-template-columns: 1fr;
            }
            .export-buttons {
                flex-direction: column;
            }
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            .form-control {
                min-width: auto;
                width: 100%;
            }
            .filter-buttons {
                width: 100%;
            }
            .btn-filter, .btn-reset {
                flex: 1;
                text-align: center;
            }
        }
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
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                </svg>
                <span class="label">Dashboard</span>
            </a>
            <?php foreach($allowed_pages as $page => $label): ?>
                <?php if($page !== 'dashboard.php'): ?>
                    <a href="<?php echo $page; ?>" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) == $page ? 'active' : ''; ?>">
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
                        <span class="label"><?php echo $label; ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <a href="logout.php" class="menu-item" style="color: rgba(255,255,255,0.8);">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>
                </svg>
                <span class="label">Logout</span>
            </a>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main" id="mainContent">
        <div class="topbar">
            <div class="top-left">
                <h1>Reports
                    <span class="role-badge role-<?php echo strtolower($current_role); ?>">
                        <?php echo htmlspecialchars($current_role); ?> View
                    </span>
                </h1>
                <p>Generate and view hospital reports and analytics - Gig Oca Robles Seamen's Hospital Davao</p>
            </div>

            <div class="top-actions">
                <?php if ($is_admin): ?>
                    <a href="reports.php?type=export&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-outline">Export All Data</a>
                <?php endif; ?>
                <div class="date-pill"><?php echo date('l, jS F Y'); ?></div>
            </div>
        </div>

        <!-- Toast container -->
        <div id="toast" class="toast-container" aria-live="polite" aria-atomic="true"></div>

        <!-- Date Filter Section -->
        <div class="date-filter-container">
            <h3>Filter Reports by Date Range</h3>
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                
                <div class="form-group">
                    <label for="start_date">From Date:</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" 
                           value="<?php echo htmlspecialchars($start_date); ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="end_date">To Date:</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" 
                           value="<?php echo htmlspecialchars($end_date); ?>"
                           min="<?php echo !empty($start_date) ? $start_date : ''; ?>"
                           max="<?php echo date('Y-m-d'); ?>">
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn-filter">Apply Filter</button>
                    <a href="reports.php" class="btn-reset">Reset Filter</a>
                </div>
            </form>
            
            <?php if (!empty($start_date) || !empty($end_date)): ?>
                <div class="filter-info">
                    Currently showing reports 
                    <?php if (!empty($start_date) && !empty($end_date)): ?>
                        from <strong><?php echo date('F j, Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('F j, Y', strtotime($end_date)); ?></strong>
                    <?php elseif (!empty($start_date)): ?>
                        from <strong><?php echo date('F j, Y', strtotime($start_date)); ?></strong> onwards
                    <?php elseif (!empty($end_date)): ?>
                        until <strong><?php echo date('F j, Y', strtotime($end_date)); ?></strong>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="reports-container">
            <div class="reports-header">
                <h2>Hospital Reports Dashboard</h2>
                <p>Select from the available report categories below to generate analytics and insights</p>
            </div>

            <!-- Report Cards with Permission Checks -->
            <div class="report-grid">
                <!-- Patient Statistics Card -->
                <div class="report-card <?php echo !hasReportPermission($current_role, 'patient', $report_permissions) ? 'no-permission' : ''; ?>">
                    <h3>Patient Statistics</h3>
                    <p>Monthly/daily reports, new patient registrations, demographic analysis, and patient visit trends.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'patient', $report_permissions)): ?>
                            <a href="reports.php?type=patient&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-sm" style="background: #4d8cc9; color: white;">Download CSV</a>
                        <?php else: ?>
                            <button class="btn btn-sm" style="background: #6b7280; color: white; cursor: not-allowed;" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Available to: All Medical Roles</span>
                    </div>
                </div>

                <!-- Appointment Summary Card -->
                <div class="report-card <?php echo !hasReportPermission($current_role, 'appointment', $report_permissions) ? 'no-permission' : ''; ?>">
                    <h3>Appointment Summary</h3>
                    <p>Completed vs cancelled appointments, daily appointment counts, doctor schedules, and wait times.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'appointment', $report_permissions)): ?>
                            <a href="reports.php?type=appointment&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-sm" style="background: #4d8cc9; color: white;">Download CSV</a>
                        <?php else: ?>
                            <button class="btn btn-sm" style="background: #6b7280; color: white; cursor: not-allowed;" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Available to: Doctor, Nurse, Staff</span>
                    </div>
                </div>

                <!-- Surgery Reports Card -->
                <div class="report-card <?php echo !hasReportPermission($current_role, 'surgery', $report_permissions) ? 'no-permission' : ''; ?>">
                    <h3>Surgery Reports</h3>
                    <p>By type, by doctor, monthly surgery statistics, success rates, and post-operative outcomes.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'surgery', $report_permissions)): ?>
                            <a href="reports.php?type=surgery&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-sm" style="background: #4d8cc9; color: white;">Download CSV</a>
                        <?php else: ?>
                            <button class="btn btn-sm" style="background: #6b7280; color: white; cursor: not-allowed;" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Available to: Doctor, Admin</span>
                    </div>
                </div>

                <!-- Billing Summary Card -->
                <div class="report-card <?php echo !hasReportPermission($current_role, 'billing', $report_permissions) ? 'no-permission' : ''; ?>">
                    <h3>Billing Summary</h3>
                    <p>Revenue reports, paid vs unpaid invoices, payment methods, and financial period summaries.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'billing', $report_permissions)): ?>
                            <a href="reports.php?type=billing&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-sm" style="background: #4d8cc9; color: white;">Download CSV</a>
                        <?php else: ?>
                            <button class="btn btn-sm" style="background: #6b7280; color: white; cursor: not-allowed;" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Available to: Billing, Admin</span>
                    </div>
                </div>

                <!-- Inventory Usage Card -->
                <div class="report-card <?php echo !hasReportPermission($current_role, 'inventory', $report_permissions) ? 'no-permission' : ''; ?>">
                    <h3>Inventory Usage</h3>
                    <p>Items consumed per surgery, stock levels, expiration tracking, and reorder recommendations.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'inventory', $report_permissions)): ?>
                            <a href="reports.php?type=inventory&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-sm" style="background: #4d8cc9; color: white;">Download CSV</a>
                        <?php else: ?>
                            <button class="btn btn-sm" style="background: #6b7280; color: white; cursor: not-allowed;" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Available to: Inventory, Admin</span>
                    </div>
                </div>

                <!-- Financial Reports Card -->
                <div class="report-card <?php echo !hasReportPermission($current_role, 'financial', $report_permissions) ? 'no-permission' : ''; ?>">
                    <h3>Financial Reports</h3>
                    <p>Revenue vs expenses, profit/loss statements, budget vs actuals, and financial forecasting.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'financial', $report_permissions)): ?>
                            <a href="reports.php?type=financial&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn btn-sm" style="background: #4d8cc9; color: white;">Download CSV</a>
                        <?php else: ?>
                            <button class="btn btn-sm" style="background: #6b7280; color: white; cursor: not-allowed;" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Available to: Admin, Billing, Social Worker</span>
                    </div>
                </div>
            </div>

            <!-- Quick Report List -->
            <div class="report-list">
                <h3>Quick Reports</h3>
                <ul>
                    <li><strong>Today's Appointments:</strong> View all appointments scheduled for today with status</li>
                    <li><strong>Monthly Patient Admissions:</strong> New patients registered this month</li>
                    <li><strong>Pending Bills:</strong> Unpaid invoices and payment reminders</li>
                    <li><strong>Low Stock Alerts:</strong> Inventory items below minimum threshold</li>
                    <li><strong>Surgery Schedule:</strong> Upcoming surgeries for the week</li>
                </ul>
            </div>

            <!-- Export Options -->
            <div class="export-options">
                <h3 style="margin-top: 0;">Export Options</h3>
                <p style="margin-bottom: 15px;">Export reports in various formats for external use:</p>
                <div class="export-buttons">
                    <?php if ($is_admin): ?>
                        <a href="reports.php?type=export&format=csv<?php echo !empty($start_date) ? '&start_date=' . $start_date : ''; ?><?php echo !empty($end_date) ? '&end_date=' . $end_date : ''; ?>" class="btn" style="background: var(--navy-700); color: white;">Comprehensive CSV Export</a>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn" style="background: #4d8cc9; color: white;">Print Current View</button>
                </div>
                <p style="margin-top: 15px; color: #777; font-size: 13px;">
                    Note: CSV files are compatible with Excel, Google Sheets, and other spreadsheet applications.
                </p>
            </div>
        </div>
    </div><!-- .main -->
</div><!-- .app -->

<script>
    // Toast notifications
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast');
        if (!container) return;
        
        const toastClass = type === 'error' ? 'alert-error' : 'alert-success';
        
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

    document.addEventListener('DOMContentLoaded', function() {
        const success = <?php echo json_encode($success); ?>;
        const message = <?php echo isset($_GET['message']) ? json_encode($_GET['message']) : 'null'; ?>;
        
        if (success === 'error' && message) {
            showToast(message, 'error');
        }
        
        // Date range validation
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', function() {
                if (this.value) {
                    endDateInput.min = this.value;
                    if (endDateInput.value && endDateInput.value < this.value) {
                        endDateInput.value = this.value;
                    }
                }
            });
            
            endDateInput.addEventListener('change', function() {
                if (this.value) {
                    startDateInput.max = this.value;
                    if (startDateInput.value && startDateInput.value > this.value) {
                        startDateInput.value = this.value;
                    }
                }
            });
        }
    });
</script>
</body>
</html>