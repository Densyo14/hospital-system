<?php
require 'config.php';
require 'functions.php';
session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div class='alert alert-danger'>Invalid assessment ID.</div>";
    exit;
}

$id = (int)$_GET['id'];

// Fetch assessment with all details including billing info
$assessment = fetchOne($conn, "
    SELECT 
        f.*, 
        p.first_name, 
        p.last_name, 
        p.birth_date, 
        p.phone, 
        p.address,
        p.patient_code,
        rev_user.full_name AS reviewed_by_name,
        arch_user.full_name AS archived_by_name,
        COUNT(DISTINCT b.id) as total_bills,
        SUM(CASE WHEN b.status = 'Paid' THEN 1 ELSE 0 END) as paid_bills,
        SUM(CASE WHEN b.status = 'Unpaid' THEN 1 ELSE 0 END) as unpaid_bills,
        SUM(CASE WHEN b.status = 'Unpaid' THEN b.amount_due ELSE 0 END) as total_unpaid
    FROM financial_assessment f
    LEFT JOIN patients p ON f.patient_id = p.id
    LEFT JOIN users rev_user ON f.reviewed_by = rev_user.id
    LEFT JOIN users arch_user ON f.archived_by = arch_user.id
    LEFT JOIN billing b ON f.id = b.financial_assessment_id
    WHERE f.id = ?
    GROUP BY f.id, p.id, rev_user.id, arch_user.id
", "i", [$id]);

if (!$assessment) {
    echo "<div class='alert alert-danger'>Assessment not found.</div>";
    exit;
}

// Get related billing records
$billing_records = fetchAll($conn, "
    SELECT 
        b.*,
        s.surgery_type,
        s.schedule_date
    FROM billing b
    LEFT JOIN surgeries s ON b.surgery_id = s.id
    WHERE b.financial_assessment_id = ?
    ORDER BY b.created_at DESC
", "i", [$id]);

// Archived info
$is_archived = !empty($assessment['is_archived']);
$archived_badge = $is_archived
    ? "<span class='badge bg-danger'>Archived</span>"
    : "<span class='badge bg-success'>Active</span>";

// Assessment type badge
$type_class = [
    'Charity' => 'success',
    'Partial' => 'warning', 
    'Paying' => 'info'
][$assessment['assessment_type']] ?? 'secondary';

$type_badge = "<span class='badge bg-{$type_class}'>{$assessment['assessment_type']}</span>";

// Status badge
$status_class = [
    'Pending' => 'warning',
    'Approved' => 'success', 
    'Rejected' => 'danger'
][$assessment['status']] ?? 'secondary';

$status_badge = "<span class='badge bg-{$status_class}'>{$assessment['status']}</span>";

// PhilHealth badge
$philhealth_badge = $assessment['philhealth_eligible'] 
    ? '<span class="badge bg-success">Yes</span>' 
    : '<span class="badge bg-secondary">No</span>';

// Format dates
function formatDate($date) {
    return $date ? date('F j, Y g:i A', strtotime($date)) : 'N/A';
}

$created_formatted = formatDate($assessment['created_at']);
$reviewed_formatted = formatDate($assessment['reviewed_at']);
$archived_formatted = formatDate($assessment['archived_at']);

// Calculate patient age
$patient_age = 'N/A';
if ($assessment['birth_date']) {
    $birth_date = new DateTime($assessment['birth_date']);
    $today = new DateTime();
    $interval = $today->diff($birth_date);
    $patient_age = $interval->y;
}

// Billing summary
$billing_summary = "";
if ($assessment['total_bills'] > 0) {
    $billing_summary = "
        <div class='billing-summary mt-3' style='background: #f8f9fa; padding: 15px; border-radius: 8px;'>
            <h5>Billing Summary</h5>
            <div class='row'>
                <div class='col-md-3'><strong>Total Bills:</strong> {$assessment['total_bills']}</div>
                <div class='col-md-3'><strong>Paid:</strong> {$assessment['paid_bills']}</div>
                <div class='col-md-3'><strong>Unpaid:</strong> {$assessment['unpaid_bills']}</div>
                <div class='col-md-3'><strong>Total Unpaid:</strong> ₱" . number_format($assessment['total_unpaid'], 2) . "</div>
            </div>
        </div>
    ";
}

// Archived info display
$archived_info = "";
if ($is_archived) {
    $archived_info = "
        <div class='alert alert-warning mt-3'>
            <strong>Archived:</strong> {$archived_formatted}<br>
            <strong>Archived by:</strong> " . htmlspecialchars($assessment['archived_by_name'] ?? 'Unknown') . "
        </div>
    ";
}

// Display billing records
$billing_table = "";
if (!empty($billing_records)) {
    $billing_table .= "
        <div class='billing-records mt-4'>
            <h5>Related Billing Records</h5>
            <table class='table table-sm'>
                <thead>
                    <tr>
                        <th>Bill ID</th>
                        <th>Service</th>
                        <th>Total Amount</th>
                        <th>Coverage</th>
                        <th>Amount Due</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    ";
    
    foreach ($billing_records as $bill) {
        $status_badge = $bill['status'] == 'Paid' 
            ? '<span class="badge bg-success">Paid</span>' 
            : '<span class="badge bg-warning">Unpaid</span>';
        
        $service = $bill['surgery_type'] ? htmlspecialchars($bill['surgery_type']) : 'General Service';
        if ($bill['schedule_date']) {
            $service .= ' (' . date('M j, Y', strtotime($bill['schedule_date'])) . ')';
        }
        
        $billing_table .= "
            <tr>
                <td>#{$bill['id']}</td>
                <td>{$service}</td>
                <td>₱" . number_format($bill['total_amount'], 2) . "</td>
                <td>
                    PhilHealth: ₱" . number_format($bill['philhealth_coverage'], 2) . "<br>
                    HMO: ₱" . number_format($bill['hmo_coverage'], 2) . "
                </td>
                <td>₱" . number_format($bill['amount_due'], 2) . "</td>
                <td>{$status_badge}</td>
            </tr>
        ";
    }
    
    $billing_table .= "
                </tbody>
            </table>
        </div>
    ";
} else {
    $billing_table = "
        <div class='alert alert-info mt-3'>
            No billing records linked to this assessment.
        </div>
    ";
}

// Coverage information based on assessment type
$coverage_info = "";
switch ($assessment['assessment_type']) {
    case 'Charity':
        $coverage_info = "<p class='text-success'><strong>Coverage:</strong> 100% coverage (Full charity)</p>";
        break;
    case 'Partial':
        $philhealth_percent = $assessment['philhealth_eligible'] ? '30%' : '0%';
        $hmo_percent = $assessment['hmo_provider'] ? '20%' : '0%';
        $total_coverage = ($assessment['philhealth_eligible'] ? 30 : 0) + ($assessment['hmo_provider'] ? 20 : 0);
        $coverage_info = "<p class='text-warning'><strong>Coverage:</strong> {$total_coverage}% total ({$philhealth_percent} PhilHealth + {$hmo_percent} HMO)</p>";
        break;
    case 'Paying':
        $coverage_info = "<p class='text-info'><strong>Coverage:</strong> 0% coverage (Full payment required)</p>";
        break;
}
?>

<div class="container-fluid">
    <h4 class="mb-3">Financial Assessment Details</h4>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Assessment ID:</strong><br>
            #<?php echo htmlspecialchars($assessment['id']); ?>
        </div>
        <div class="col-md-6">
            <strong>Status:</strong><br>
            <?php echo $status_badge . ' ' . $archived_badge; ?>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Patient Information:</strong><br>
            <?php echo htmlspecialchars($assessment['first_name'] . " " . $assessment['last_name']); ?><br>
            <small class="text-muted">
                Code: <?php echo htmlspecialchars($assessment['patient_code'] ?? 'N/A'); ?><br>
                DOB: <?php echo htmlspecialchars($assessment['birth_date'] ?? 'N/A'); ?> (<?php echo $patient_age; ?> years)<br>
                Phone: <?php echo htmlspecialchars($assessment['phone'] ?? 'N/A'); ?><br>
                Address: <?php echo htmlspecialchars($assessment['address'] ?? 'N/A'); ?>
            </small>
        </div>
        <div class="col-md-6">
            <strong>Assessment Details:</strong><br>
            Type: <?php echo $type_badge; ?><br><br>
            
            PhilHealth Eligible: <?php echo $philhealth_badge; ?><br><br>
            
            HMO Provider: <?php echo htmlspecialchars($assessment['hmo_provider'] ?? 'None'); ?><br><br>
            
            <?php echo $coverage_info; ?>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Created:</strong><br>
            <?php echo htmlspecialchars($created_formatted); ?>
        </div>
        <div class="col-md-6">
            <strong>Reviewed:</strong><br>
            <?php echo htmlspecialchars($reviewed_formatted); ?><br>
            <?php if ($assessment['reviewed_by_name']): ?>
                <small class="text-muted">by <?php echo htmlspecialchars($assessment['reviewed_by_name']); ?></small>
            <?php endif; ?>
        </div>
    </div>

    <?php echo $billing_summary; ?>
    <?php echo $archived_info; ?>
    <?php echo $billing_table; ?>

    <style>
        .badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .bg-success { background: #27ae60; color: white; }
        .bg-warning { background: #f39c12; color: white; }
        .bg-danger { background: #e74c3c; color: white; }
        .bg-info { background: #3498db; color: white; }
        .bg-secondary { background: #95a5a6; color: white; }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            background: #f8f9fa;
            padding: 8px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }
        .table td {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }
        .table-sm th, .table-sm td {
            padding: 6px 8px;
        }
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
    </style>
</div>