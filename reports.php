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
$action = $_GET['action'] ?? '';
$report_type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? '';

// Current user info for permission checks
$is_admin = ($current_role === 'Admin');
$is_doctor = ($current_role === 'Doctor');
$is_nurse = ($current_role === 'Nurse');
$is_staff = ($current_role === 'Staff');
$is_inventory = ($current_role === 'Inventory');
$is_billing = ($current_role === 'Billing');
$is_social_worker = ($current_role === 'SocialWorker');

// Define report permissions for each role
$report_permissions = [
    'Admin' => ['patient', 'appointment', 'surgery', 'billing', 'inventory', 'financial', 'export', 'triage'],
    'Doctor' => ['patient', 'appointment', 'surgery', 'triage'],
    'Nurse' => ['patient', 'appointment', 'triage'],
    'Staff' => ['patient', 'appointment'],
    'Inventory' => ['inventory'],
    'Billing' => ['billing', 'financial'],
    'SocialWorker' => ['financial']
];

function hasReportPermission($user_role, $report_type, $report_permissions) {
    if (empty($report_type)) return true;
    return in_array($report_type, $report_permissions[$user_role] ?? []);
}

if (!hasReportPermission($current_role, $report_type, $report_permissions) && !empty($report_type)) {
    header("Location: reports.php?success=error&message=Access denied.");
    exit();
}

// Date filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = '';
if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = '';

// Include FPDF
require_once('fpdf/fpdf.php');

// PDF Generation Class
class PDF extends FPDF {
    function Header() {
        // Logo
        if (file_exists('logo.jpg')) {
            $this->Image('logo.jpg', 10, 6, 30);
        }
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Move to the right
        $this->Cell(80);
        // Title
        $this->Cell(30, 10, 'Hospital Management System Report', 0, 0, 'C');
        // Line break
        $this->Ln(20);
    }

    function Footer() {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
        // Arial italic 8
        $this->SetFont('Arial', 'I', 8);
        // Page number
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// PDF generation function
function generatePDF($data, $title, $filename, $headers = []) {
    $pdf = new PDF();
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Title
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->Ln(10);
    
    // Date range if applicable
    global $start_date, $end_date;
    if (!empty($start_date) || !empty($end_date)) {
        $pdf->SetFont('Arial', 'I', 10);
        $date_text = "Date Range: ";
        if (!empty($start_date) && !empty($end_date)) {
            $date_text .= date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date));
        } elseif (!empty($start_date)) {
            $date_text .= 'From ' . date('F j, Y', strtotime($start_date));
        } elseif (!empty($end_date)) {
            $date_text .= 'Until ' . date('F j, Y', strtotime($end_date));
        }
        $pdf->Cell(0, 10, $date_text, 0, 1, 'C');
        $pdf->Ln(5);
    }
    
    // Generation timestamp
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 10, 'Generated on: ' . date('F j, Y H:i:s'), 0, 1, 'R');
    $pdf->Ln(5);
    
    if (empty($data)) {
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'No data available for the selected criteria.', 0, 1, 'C');
    } else {
        // Create table
        if (!empty($headers)) {
            // Table header
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetFillColor(200, 220, 255);
            
            // Calculate column widths based on number of columns
            $num_cols = count($headers);
            $col_width = 190 / $num_cols; // 190 is usable width (210 - margins)
            
            foreach ($headers as $header) {
                $pdf->Cell($col_width, 10, $header, 1, 0, 'C', true);
            }
            $pdf->Ln();
            
            // Table data
            $pdf->SetFont('Arial', '', 10);
            $fill = false;
            
            foreach ($data as $row) {
                $pdf->SetFillColor(240, 240, 240);
                $row_data = array_values($row);
                
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                
                foreach ($row_data as $i => $cell) {
                    if ($i > 0) $pdf->SetX($x + ($i * $col_width));
                    $pdf->MultiCell($col_width, 6, $cell, 1, 'L', $fill);
                    $pdf->SetXY($x + (($i + 1) * $col_width), $y);
                }
                $pdf->Ln();
                $fill = !$fill;
            }
        }
    }
    
    // Output PDF
    ob_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    $pdf->Output('D', $filename . '.pdf');
    exit();
}

// Handle PDF generation
if ($report_type && $format === 'pdf') {
    if (!hasReportPermission($current_role, $report_type, $report_permissions)) {
        header("Location: reports.php?success=error&message=Access denied.");
        exit();
    }
    
    global $conn;
    $data = [];
    $headers = [];
    $title = '';
    $filename = $report_type . '_report';
    
    // Build date WHERE clause based on table column
    $date_where = '';
    $date_params = [];
    $date_types = '';
    
    if (!empty($start_date) && !empty($end_date)) {
        $date_where = " AND DATE(created_at) BETWEEN ? AND ?";
        $date_params = [$start_date, $end_date];
        $date_types = 'ss';
    } elseif (!empty($start_date)) {
        $date_where = " AND DATE(created_at) >= ?";
        $date_params = [$start_date];
        $date_types = 's';
    } elseif (!empty($end_date)) {
        $date_where = " AND DATE(created_at) <= ?";
        $date_params = [$end_date];
        $date_types = 's';
    }
    
    // Add date suffix to filename
    if (!empty($start_date) && !empty($end_date)) {
        $filename .= '_' . $start_date . '_to_' . $end_date;
    } elseif (!empty($start_date)) {
        $filename .= '_from_' . $start_date;
    } elseif (!empty($end_date)) {
        $filename .= '_until_' . $end_date;
    }
    
    switch ($report_type) {
        case 'patient':
            $title = 'Patient Statistics Report';
            $headers = ['Patient Code', 'Name', 'Gender', 'Birth Date', 'Phone', 'Guardian', 'Blood Type'];
            
            $query = "SELECT patient_code, first_name, last_name, sex as gender, birth_date, phone, guardian_name, blood_type 
                     FROM patients WHERE is_archived = 0" . $date_where . " ORDER BY created_at DESC";
            
            $stmt = $conn->prepare($query);
            if (!empty($date_params)) {
                $stmt->bind_param($date_types, ...$date_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'code' => $row['patient_code'],
                    'name' => $row['first_name'] . ' ' . $row['last_name'],
                    'gender' => $row['gender'] ?? 'N/A',
                    'birth_date' => $row['birth_date'] ?? 'N/A',
                    'phone' => $row['phone'] ?? 'N/A',
                    'guardian' => $row['guardian_name'] ?? 'N/A',
                    'blood_type' => $row['blood_type'] ?? 'N/A'
                ];
            }
            $stmt->close();
            break;
            
        case 'appointment':
            $title = 'Appointment Reports';
            $headers = ['Patient', 'Doctor', 'Schedule', 'Status', 'Reason', 'Notes'];
            
            $query = "SELECT 
                     CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                     u.full_name as doctor_name,
                     a.schedule_datetime,
                     a.status,
                     a.reason,
                     a.notes
                     FROM appointments a
                     JOIN patients p ON a.patient_id = p.id
                     JOIN users u ON a.doctor_id = u.id
                     WHERE a.is_archived = 0" . str_replace('created_at', 'a.created_at', $date_where) . " 
                     ORDER BY a.schedule_datetime DESC";
            
            $stmt = $conn->prepare($query);
            if (!empty($date_params)) {
                $stmt->bind_param($date_types, ...$date_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'patient' => $row['patient_name'],
                    'doctor' => $row['doctor_name'],
                    'schedule' => date('Y-m-d H:i', strtotime($row['schedule_datetime'])),
                    'status' => $row['status'],
                    'reason' => $row['reason'] ?? 'N/A',
                    'notes' => $row['notes'] ?? 'N/A'
                ];
            }
            $stmt->close();
            break;
            
        case 'surgery':
            $title = 'Surgery Reports';
            $headers = ['Patient', 'Surgeon', 'Surgery Type', 'Schedule Date', 'Room', 'Status'];
            
            $query = "SELECT 
                     CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                     u.full_name as surgeon_name,
                     s.surgery_type,
                     s.schedule_date,
                     s.operating_room,
                     s.status
                     FROM surgeries s
                     JOIN patients p ON s.patient_id = p.id
                     JOIN users u ON s.doctor_id = u.id
                     WHERE s.is_archived = 0" . str_replace('created_at', 's.created_at', $date_where) . " 
                     ORDER BY s.schedule_date DESC";
            
            $stmt = $conn->prepare($query);
            if (!empty($date_params)) {
                $stmt->bind_param($date_types, ...$date_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'patient' => $row['patient_name'],
                    'surgeon' => $row['surgeon_name'],
                    'type' => $row['surgery_type'],
                    'date' => $row['schedule_date'],
                    'room' => $row['operating_room'] ?? 'N/A',
                    'status' => $row['status']
                ];
            }
            $stmt->close();
            break;
            
        case 'billing':
            $title = 'Billing Reports';
            $headers = ['Patient', 'Total Amount', 'PhilHealth', 'HMO', 'Amount Due', 'Status', 'Paid At'];
            
            $query = "SELECT 
                     CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                     b.total_amount,
                     b.philhealth_coverage,
                     b.hmo_coverage,
                     b.amount_due,
                     b.status,
                     b.paid_at
                     FROM billing b
                     JOIN patients p ON b.patient_id = p.id
                     WHERE b.is_archived = 0" . str_replace('created_at', 'b.created_at', $date_where) . " 
                     ORDER BY b.created_at DESC";
            
            $stmt = $conn->prepare($query);
            if (!empty($date_params)) {
                $stmt->bind_param($date_types, ...$date_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'patient' => $row['patient_name'],
                    'total' => '₱' . number_format($row['total_amount'], 2),
                    'philhealth' => '₱' . number_format($row['philhealth_coverage'], 2),
                    'hmo' => '₱' . number_format($row['hmo_coverage'], 2),
                    'due' => '₱' . number_format($row['amount_due'], 2),
                    'status' => $row['status'],
                    'paid_at' => $row['paid_at'] ? date('Y-m-d', strtotime($row['paid_at'])) : 'Unpaid'
                ];
            }
            $stmt->close();
            break;
            
        case 'inventory':
            $title = 'Inventory Report';
            $headers = ['Item Name', 'Category', 'Quantity', 'Unit', 'Threshold', 'Status'];
            
            $query = "SELECT item_name, category, quantity, unit, threshold,
                     CASE WHEN quantity <= threshold THEN 'LOW STOCK' ELSE 'In Stock' END as stock_status
                     FROM inventory_items 
                     WHERE is_archived = 0" . $date_where . " 
                     ORDER BY category, item_name";
            
            $stmt = $conn->prepare($query);
            if (!empty($date_params)) {
                $stmt->bind_param($date_types, ...$date_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'item' => $row['item_name'],
                    'category' => $row['category'],
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'] ?? 'pcs',
                    'threshold' => $row['threshold'],
                    'status' => $row['stock_status']
                ];
            }
            $stmt->close();
            break;
            
        case 'financial':
            $title = 'Financial Assessment Report';
            $headers = ['Patient', 'Assessment Type', 'PhilHealth Eligible', 'HMO Provider', 'Status', 'Reviewed At'];
            
            $query = "SELECT 
                     CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                     fa.assessment_type,
                     fa.philhealth_eligible,
                     fa.hmo_provider,
                     fa.status,
                     fa.reviewed_at
                     FROM financial_assessment fa
                     JOIN patients p ON fa.patient_id = p.id
                     WHERE fa.is_archived = 0" . str_replace('created_at', 'fa.created_at', $date_where) . " 
                     ORDER BY fa.created_at DESC";
            
            $stmt = $conn->prepare($query);
            if (!empty($date_params)) {
                $stmt->bind_param($date_types, ...$date_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'patient' => $row['patient_name'],
                    'type' => $row['assessment_type'] ?? 'N/A',
                    'philhealth' => $row['philhealth_eligible'] ? 'Yes' : 'No',
                    'hmo' => $row['hmo_provider'] ?? 'None',
                    'status' => $row['status'],
                    'reviewed' => $row['reviewed_at'] ? date('Y-m-d', strtotime($row['reviewed_at'])) : 'Pending'
                ];
            }
            $stmt->close();
            break;
            
        case 'triage':
            $title = 'Triage Queue Report';
            $headers = ['Patient', 'Assessed By', 'Severity', 'Chief Complaint', 'Status', 'Assessed At'];
            
            $query = "SELECT 
                     CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                     u.full_name as assessed_by_name,
                     t.severity,
                     t.chief_complaint,
                     t.status,
                     t.assessed_at
                     FROM triage t
                     JOIN patients p ON t.patient_id = p.id
                     JOIN users u ON t.assessed_by = u.id
                     ORDER BY t.assessed_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $severity_label = '';
                switch($row['severity']) {
                    case 1: $severity_label = 'Low (1)'; break;
                    case 2: $severity_label = 'Low-Moderate (2)'; break;
                    case 3: $severity_label = 'Moderate (3)'; break;
                    case 4: $severity_label = 'High (4)'; break;
                    case 5: $severity_label = 'Critical (5)'; break;
                    default: $severity_label = 'Unknown';
                }
                
                $data[] = [
                    'patient' => $row['patient_name'],
                    'assessed_by' => $row['assessed_by_name'],
                    'severity' => $severity_label,
                    'complaint' => $row['chief_complaint'] ?? 'N/A',
                    'status' => $row['status'],
                    'assessed_at' => date('Y-m-d H:i', strtotime($row['assessed_at']))
                ];
            }
            $stmt->close();
            break;
            
        case 'export':
            $title = 'Complete System Export Report';
            $headers = ['Report Type', 'Total Records', 'Date Range'];
            
            // Get counts from all tables
            $tables = [
                'patients' => 'Patients',
                'appointments' => 'Appointments',
                'surgeries' => 'Surgeries',
                'billing' => 'Billing',
                'inventory_items' => 'Inventory Items',
                'financial_assessment' => 'Financial Assessments',
                'users' => 'Users',
                'triage' => 'Triage Records'
            ];
            
            foreach ($tables as $table => $label) {
                $where_clause = '';
                if (in_array($table, ['patients', 'appointments', 'surgeries', 'billing', 'inventory_items', 'financial_assessment'])) {
                    $where_clause = " WHERE is_archived = 0";
                }
                
                $query = "SELECT COUNT(*) as count FROM $table" . $where_clause;
                $stmt = $conn->prepare($query);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = $result->fetch_assoc()['count'];
                
                $data[] = [
                    'type' => $label,
                    'count' => $count,
                    'range' => (!empty($start_date) || !empty($end_date)) ? 'Filtered Applied' : 'All Time'
                ];
                $stmt->close();
            }
            break;
    }
    
    if (!empty($data)) {
        generatePDF($data, $title, $filename, $headers);
    } else {
        header("Location: reports.php?success=error&message=No data found for the selected criteria.");
        exit();
    }
}

// Keep CSV export functionality
function generateCSV($data, $filename) {
    if (empty($data)) return false;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    if (!empty($data[0])) fputcsv($output, array_keys($data[0]));
    foreach ($data as $row) fputcsv($output, $row);
    fclose($output);
    exit();
}

// CSV export handling
if ($report_type && $format === 'csv') {
    if (!hasReportPermission($current_role, $report_type, $report_permissions)) {
        header("Location: reports.php?success=error&message=Access denied.");
        exit();
    }
    
    // Add CSV handling here if needed
    header("Location: reports.php?success=error&message=CSV export temporarily disabled.");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Hospital Dashboard - Reports</title>
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
        *{box-sizing:border-box; margin:0; padding:0;}
        html,body{height:100%; font-family:'Inter',sans-serif;}
        body{background:var(--bg); color:#0f1724;}
        .app{display:flex; min-height:100vh;}

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
            left:0; top:0; bottom:0;
            overflow-y:auto;
            z-index:30;
        }
        .sidebar::-webkit-scrollbar{width:4px;}
        .sidebar::-webkit-scrollbar-track{background:rgba(255,255,255,0.1);}
        .sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.3); border-radius:2px;}
        .logo-wrap{display:flex; justify-content:center;}
        .logo-wrap img{width:150px; height:auto;}
        .user-info{
            background:rgba(255,255,255,0.1);
            border-radius:8px;
            padding:10px;
            border-left:3px solid #9bcfff;
            font-size:13px;
        }
        .user-info h4{margin:0 0 4px 0; color:#9bcfff; font-size:13px;}
        .user-info p{margin:0; font-size:12px; color:rgba(255,255,255,0.9);}
        .menu{margin-top:8px; display:flex; flex-direction:column; gap:6px;}
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
        .menu-item:hover{background:linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));}
        .menu-item.active{
            background:linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
            border-left:4px solid #9bcfff;
            padding-left:5px;
        }
        .menu-item .icon{width:16px; height:16px; fill:white;}
        .sidebar-bottom{margin-top:auto; padding-top:15px; border-top:1px solid rgba(255,255,255,0.1);}

        /* MAIN */
        .main{margin-left:230px; padding:18px 28px; width:100%;}
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin-bottom:20px;
        }
        .top-left h1{font-size:22px; margin:0; font-weight:700;}
        .top-left p{margin:6px 0 0; color:var(--muted); font-size:13px;}
        .top-actions{display:flex; align-items:center; gap:12px;}
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
            cursor: pointer;
        }
        .btn:hover{background:var(--accent); transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,31,63,0.2);}
        .btn-outline{
            background:transparent;
            color:var(--navy-700);
            border:1px solid var(--navy-700);
        }
        .btn-outline:hover{
            background:var(--navy-700);
            color:#fff;
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
        .role-admin{background:#001F3F; color:white;}
        .role-billing{background:#003366; color:white;}
        .role-doctor{background:#003366; color:white;}
        .role-nurse{background:#4d8cc9; color:white;}
        .role-staff{background:#6b7280; color:white;}
        .role-inventory{background:#1e6b8a; color:white;}
        .role-socialworker{background:#34495e; color:white;}

        /* Date Filter */
        .date-filter-container{
            background:#f8fbfd;
            padding:20px;
            border-radius:8px;
            margin-bottom:20px;
            border-left:4px solid #4d8cc9;
        }
        .date-filter-container h3{
            margin:0 0 15px 0;
            color:#1e3a5f;
            font-size:18px;
        }
        .filter-form{
            display:flex;
            gap:15px;
            align-items:flex-end;
            flex-wrap:wrap;
        }
        .form-group{
            display:flex;
            flex-direction:column;
            gap:5px;
        }
        .form-group label{
            font-size:14px;
            color:#6b7280;
            font-weight:500;
        }
        .form-control{
            padding:10px 12px;
            border:1px solid #e6eef0;
            border-radius:6px;
            font-size:14px;
            min-width:200px;
        }
        .form-control:focus{
            outline:none;
            border-color:var(--light-blue);
            box-shadow:0 0 0 3px rgba(77,140,201,0.1);
        }
        .filter-buttons{display:flex; gap:10px;}
        .btn-filter{
            background:var(--navy-700);
            color:#fff;
            padding:10px 20px;
            border-radius:6px;
            border:none;
            cursor:pointer;
            font-weight:500;
        }
        .btn-filter:hover{background:var(--accent);}
        .btn-reset{
            background:#6b7280;
            color:#fff;
            padding:10px 20px;
            border-radius:6px;
            border:none;
            cursor:pointer;
            font-weight:500;
            text-decoration:none;
            display:inline-block;
        }
        .btn-reset:hover{background:#5a6268;}
        .filter-info{
            margin-top:10px;
            padding:10px;
            background:#e8f4ff;
            border-radius:6px;
            font-size:14px;
            color:#1e6b8a;
        }

        /* Reports container */
        .reports-container{
            background:var(--panel);
            padding:24px;
            border-radius:12px;
            box-shadow:var(--card-shadow);
            margin-top:20px;
        }
        .reports-header{
            margin-bottom:24px;
            padding-bottom:16px;
            border-bottom:1px solid #eef2f7;
        }
        .reports-header h2{
            font-size:24px;
            margin:0 0 8px 0;
            color:#1e3a5f;
        }
        .reports-header p{margin:0; color:var(--muted); font-size:14px;}

        /* Report cards */
        .report-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(300px,1fr));
            gap:20px;
            margin-bottom:30px;
        }
        .report-card{
            background:#f8fbfd;
            border-radius:10px;
            padding:20px;
            border-left:4px solid var(--navy-700);
            transition:transform 0.2s;
        }
        .report-card:hover{
            transform:translateY(-2px);
            box-shadow:0 8px 25px rgba(16,24,40,0.12);
        }
        .report-card h3{
            margin:0 0 12px 0;
            font-size:18px;
            color:#1e3a5f;
        }
        .report-card p{
            margin:0 0 16px 0;
            color:#6b7280;
            font-size:14px;
        }
        .card-footer{
            display:flex;
            justify-content:space-between;
            align-items:center;
        }
        .report-stats{font-size:12px; color:#888;}
        .btn-sm{padding:6px 12px; font-size:13px; border-radius:6px;}
        .no-permission{opacity:0.6;}
        .no-permission .btn-sm{background:#6b7280 !important; cursor:not-allowed;}
        .report-list{
            margin-top:30px;
        }
        .report-list h3{
            font-size:18px;
            margin-bottom:16px;
            color:#1e3a5f;
        }
        .report-list ul{
            list-style:none;
            padding:0;
        }
        .report-list li{
            padding:12px 16px;
            margin-bottom:8px;
            background:#f8fbfd;
            border-radius:8px;
            border-left:3px solid #4d8cc9;
        }
        .export-options{
            background:#f8fbfd;
            padding:20px;
            border-radius:8px;
            border-left:4px solid #4d8cc9;
            margin-top:30px;
        }
        .export-buttons{
            display:flex;
            gap:10px;
            margin-top:15px;
            flex-wrap:wrap;
        }
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
        .alert-success{background:#001F3F;}
        .alert-error{background:#e53e3e;}
        @keyframes slideIn{
            from{transform:translateX(100%); opacity:0;}
            to{transform:translateX(0); opacity:1;}
        }
        @media (max-width:780px){
            .sidebar{left:-320px;}
            .sidebar.open{left:0;}
            .main{margin-left:0; padding:12px;}
            .report-grid{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>
<div class="app">
    <!-- SIDEBAR -->
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
                <h1>Reports
                    <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
                </h1>
                <p>Generate and view hospital reports and analytics</p>
            </div>
            <div class="top-actions">
                <?php if ($is_admin): ?>
                    <a href="reports.php?type=export&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-outline">Export All Data (PDF)</a>
                <?php endif; ?>
                <div class="date-pill"><?= date('l, jS F Y') ?></div>
            </div>
        </div>

        <div id="toast" class="toast-container"></div>

        <!-- Date Filter -->
        <div class="date-filter-container">
            <h3>Filter Reports by Date Range</h3>
            <form method="GET" class="filter-form">
                <input type="hidden" name="type" value="<?= htmlspecialchars($report_type) ?>">
                <input type="hidden" name="format" value="pdf">
                <div class="form-group">
                    <label for="start_date">From Date:</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">To Date:</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" min="<?= $start_date ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn-filter">Apply Filter</button>
                    <a href="reports.php" class="btn-reset">Reset</a>
                </div>
            </form>
            <?php if (!empty($start_date) || !empty($end_date)): ?>
                <div class="filter-info">
                    Showing reports 
                    <?php if (!empty($start_date) && !empty($end_date)): ?>
                        from <strong><?= date('F j, Y', strtotime($start_date)) ?></strong> to <strong><?= date('F j, Y', strtotime($end_date)) ?></strong>
                    <?php elseif (!empty($start_date)): ?>
                        from <strong><?= date('F j, Y', strtotime($start_date)) ?></strong> onwards
                    <?php elseif (!empty($end_date)): ?>
                        until <strong><?= date('F j, Y', strtotime($end_date)) ?></strong>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="reports-container">
            <div class="reports-header">
                <h2>Hospital Reports Dashboard</h2>
                <p>Select a report category to generate a PDF report.</p>
            </div>

            <!-- Report Cards -->
            <div class="report-grid">
                <!-- Patient Statistics -->
                <div class="report-card <?= !hasReportPermission($current_role, 'patient', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Patient Statistics</h3>
                    <p>Patient demographics, contact information, and blood types.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'patient', $report_permissions)): ?>
                            <a href="reports.php?type=patient&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">All Medical Roles</span>
                    </div>
                </div>

                <!-- Appointment Reports -->
                <div class="report-card <?= !hasReportPermission($current_role, 'appointment', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Appointment Reports</h3>
                    <p>Appointment schedules, status, and doctor assignments.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'appointment', $report_permissions)): ?>
                            <a href="reports.php?type=appointment&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Doctor, Nurse, Staff</span>
                    </div>
                </div>

                <!-- Surgery Reports -->
                <div class="report-card <?= !hasReportPermission($current_role, 'surgery', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Surgery Reports</h3>
                    <p>Surgery schedules, types, and assigned surgeons.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'surgery', $report_permissions)): ?>
                            <a href="reports.php?type=surgery&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Doctor, Admin</span>
                    </div>
                </div>

                <!-- Billing Reports -->
                <div class="report-card <?= !hasReportPermission($current_role, 'billing', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Billing Reports</h3>
                    <p>Patient billing, payments, and coverage details.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'billing', $report_permissions)): ?>
                            <a href="reports.php?type=billing&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Billing, Admin</span>
                    </div>
                </div>

                <!-- Inventory Usage -->
                <div class="report-card <?= !hasReportPermission($current_role, 'inventory', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Inventory Report</h3>
                    <p>Current stock levels, categories, and low stock alerts.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'inventory', $report_permissions)): ?>
                            <a href="reports.php?type=inventory&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Inventory, Admin</span>
                    </div>
                </div>

                <!-- Financial Reports -->
                <div class="report-card <?= !hasReportPermission($current_role, 'financial', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Financial Assessment</h3>
                    <p>Financial assessments, PhilHealth eligibility, and HMO coverage.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'financial', $report_permissions)): ?>
                            <a href="reports.php?type=financial&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Admin, Billing, Social Worker</span>
                    </div>
                </div>

                <!-- Triage Reports -->
                <div class="report-card <?= !hasReportPermission($current_role, 'triage', $report_permissions) ? 'no-permission' : '' ?>">
                    <h3>Triage Queue</h3>
                    <p>Patient triage assessments, severity levels, and queue status.</p>
                    <div class="card-footer">
                        <?php if (hasReportPermission($current_role, 'triage', $report_permissions)): ?>
                            <a href="reports.php?type=triage&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn btn-sm" style="background:#4d8cc9;">Download PDF</a>
                        <?php else: ?>
                            <button class="btn btn-sm" disabled>No Permission</button>
                        <?php endif; ?>
                        <span class="report-stats">Medical Staff</span>
                    </div>
                </div>
            </div>

            <!-- Quick Report List -->
            <div class="report-list">
                <h3>Quick Reports</h3>
                <ul>
                    <li><strong>Today's Appointments:</strong> View all appointments scheduled for today</li>
                    <li><strong>Low Stock Alerts:</strong> Inventory items below threshold level</li>
                    <li><strong>Pending Bills:</strong> Unpaid invoices and pending payments</li>
                    <li><strong>Critical Triage:</strong> Patients with severity level 4-5</li>
                    <li><strong>Upcoming Surgeries:</strong> Scheduled surgeries for the week</li>
                </ul>
            </div>

            <!-- Export Options -->
            <div class="export-options">
                <h3 style="margin-top:0;">Export Options</h3>
                <p>Generate comprehensive PDF reports:</p>
                <div class="export-buttons">
                    <?php if ($is_admin): ?>
                        <a href="reports.php?type=export&format=pdf<?= !empty($start_date) ? '&start_date='.$start_date : '' ?><?= !empty($end_date) ? '&end_date='.$end_date : '' ?>" class="btn" style="background:var(--navy-700);">Complete System Export</a>
                    <?php endif; ?>
                    <button onclick="window.print()" class="btn" style="background:#4d8cc9;">Print Current View</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showToast(msg, type='success') {
    const container = document.getElementById('toast');
    if(!container) return;
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.innerHTML = `<div style="display:flex; justify-content:space-between;">${msg}<button onclick="this.parentElement.parentElement.remove()" style="background:none; border:none; color:white;">&times;</button></div>`;
    container.appendChild(toast);
    setTimeout(()=>toast.remove(),5000);
}

document.addEventListener('DOMContentLoaded',function(){
    const success = <?= json_encode($success) ?>;
    const message = <?= isset($_GET['message']) ? json_encode($_GET['message']) : 'null' ?>;
    if(success==='error' && message) showToast(message,'error');

    // Date range validation
    const start = document.getElementById('start_date');
    const end = document.getElementById('end_date');
    if(start && end){
        start.addEventListener('change',function(){
            if(this.value) end.min = this.value;
        });
        end.addEventListener('change',function(){
            if(this.value) start.max = this.value;
        });
    }
});
</script>
</body>
</html>