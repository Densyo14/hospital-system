<?php
require 'config.php';
require 'functions.php';
session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div class='alert alert-danger'>Invalid bill ID.</div>";
    exit;
}

$id = (int)$_GET['id'];
$show_financial = isset($_GET['show_financial']);

// Fetch bill with all details INCLUDING FINANCIAL ASSESSMENT
$bill = fetchOne($conn, "
    SELECT b.*, 
           p.first_name, p.last_name, p.phone, p.address,
           s.surgery_type, s.schedule_date,
           fa.id as financial_assessment_id,
           fa.assessment_type, 
           fa.status as financial_status,
           fa.philhealth_eligible,
           fa.hmo_provider,
           fa.created_at as assessment_date,
           fa.reviewed_at,
           u.full_name AS archived_by_name,
           reviewer.full_name as reviewed_by_name
    FROM billing b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN surgeries s ON b.surgery_id = s.id
    LEFT JOIN financial_assessment fa ON b.financial_assessment_id = fa.id
    LEFT JOIN users u ON b.archived_by = u.id
    LEFT JOIN users reviewer ON fa.reviewed_by = reviewer.id
    WHERE b.id = ?
", "i", [$id]);

if (!$bill) {
    echo "<div class='alert alert-danger'>Bill not found.</div>";
    exit;
}

// Check if archive columns exist
$table_check = fetchOne($conn, "SHOW COLUMNS FROM billing LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;

// Archived info
$is_archived = $has_archive_columns && !empty($bill['is_archived']);
$archived_badge = $is_archived
    ? "<span class='badge bg-danger'>Archived</span>"
    : "<span class='badge bg-success'>Active</span>";

// Status badge
$status_class = [
    'Unpaid' => 'danger',
    'Paid' => 'success'
][$bill['status']] ?? 'secondary';

$status_badge = "<span class='badge bg-{$status_class}'>{$bill['status']}</span>";

// Financial assessment status badge
$financial_status_badge = '';
if ($bill['financial_assessment_id']) {
    $financial_status_class = [
        'Pending' => 'warning',
        'Approved' => 'success',
        'Rejected' => 'danger'
    ][$bill['financial_status']] ?? 'secondary';
    
    $financial_status_badge = "<span class='badge bg-{$financial_status_class}'>{$bill['financial_status']}</span>";
    
    // Assessment type badge
    $assessment_type_class = [
        'Charity' => 'success',
        'Partial' => 'warning',
        'Paying' => 'info'
    ][$bill['assessment_type']] ?? 'secondary';
    
    $assessment_type_badge = "<span class='badge bg-{$assessment_type_class}'>{$bill['assessment_type']}</span>";
}

$archived_info = "";
if ($is_archived) {
    $archived_info = "
        <div class='alert alert-warning mt-3'>
            <strong>Archived:</strong> " . ($bill['archived_at'] ? date('F j, Y g:i A', strtotime($bill['archived_at'])) : 'N/A') . "<br>
            <strong>Archived by:</strong> " . htmlspecialchars($bill['archived_by_name'] ?? 'Unknown') . "
        </div>
    ";
}

// Format dates
$created_formatted = date('F j, Y g:i A', strtotime($bill['created_at']));
$paid_formatted = $bill['paid_at'] ? date('F j, Y g:i A', strtotime($bill['paid_at'])) : 'N/A';
$surgery_date = $bill['schedule_date'] ? date('F j, Y', strtotime($bill['schedule_date'])) : 'N/A';
$assessment_date = $bill['assessment_date'] ? date('F j, Y g:i A', strtotime($bill['assessment_date'])) : 'N/A';
$reviewed_date = $bill['reviewed_at'] ? date('F j, Y g:i A', strtotime($bill['reviewed_at'])) : 'N/A';

// Calculate coverage percentage
$coverage_percentage = $bill['total_amount'] > 0 
    ? round((($bill['philhealth_coverage'] + $bill['hmo_coverage']) / $bill['total_amount']) * 100, 2)
    : 0;
?>

<style>
    .badge { font-size: 0.8em; padding: 5px 10px; border-radius: 12px; }
    .badge-success { background-color: #28a745; color: white; }
    .badge-danger { background-color: #dc3545; color: white; }
    .badge-warning { background-color: #ffc107; color: #212529; }
    .badge-info { background-color: #17a2b8; color: white; }
    .badge-secondary { background-color: #6c757d; color: white; }
    
    .card { border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
    .card-header { border-bottom: 1px solid rgba(0,0,0,0.1); font-weight: 600; }
    
    .row { margin-bottom: 15px; }
    .text-muted { color: #6c757d; font-size: 0.9em; }
    .text-primary { color: #007bff; }
    .text-success { color: #28a745; }
    .text-danger { color: #dc3545; }
    .text-warning { color: #ffc107; }
    
    .insurance-indicator { 
        display: inline-block; 
        padding: 3px 8px; 
        border-radius: 10px; 
        font-size: 0.8em;
        margin-right: 5px;
        margin-bottom: 5px;
    }
    .insurance-yes { background-color: #d4edda; color: #155724; }
    .insurance-no { background-color: #f8d7da; color: #721c24; }
    
    .coverage-bar {
        height: 20px;
        background-color: #e9ecef;
        border-radius: 10px;
        overflow: hidden;
        margin: 10px 0;
    }
    .coverage-fill {
        height: 100%;
        background: linear-gradient(90deg, #28a745, #20c997);
        border-radius: 10px;
        transition: width 0.5s ease;
    }
    
    .assessment-card {
        border-left: 4px solid;
        padding-left: 15px;
        margin-bottom: 20px;
    }
    .assessment-charity { border-color: #28a745; }
    .assessment-partial { border-color: #ffc107; }
    .assessment-paying { border-color: #17a2b8; }
</style>

<div class="container-fluid">
    <h4 class="mb-3">Bill Details - TX-<?php echo htmlspecialchars($bill['id']); ?></h4>

    <div class="row mb-3">
        <div class="col-md-4"><strong>Bill ID:</strong><br>TX-<?php echo htmlspecialchars($bill['id']); ?></div>
        <div class="col-md-4"><strong>Status:</strong><br><?php echo $status_badge . ' ' . $archived_badge; ?></div>
        <div class="col-md-4"><strong>Created:</strong><br><?php echo htmlspecialchars($created_formatted); ?></div>
    </div>

    <!-- Patient & Surgery Info -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0">👤 Patient Information</h6>
                </div>
                <div class="card-body">
                    <strong><?php echo htmlspecialchars($bill['first_name'] . " " . $bill['last_name']); ?></strong><br>
                    <small class="text-muted">
                        Phone: <?php echo htmlspecialchars($bill['phone'] ?? 'N/A'); ?><br>
                        Address: <?php echo htmlspecialchars($bill['address'] ?? 'N/A'); ?>
                    </small>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-light">
                    <h6 class="mb-0">🏥 Surgery Information</h6>
                </div>
                <div class="card-body">
                    <strong><?php echo htmlspecialchars($bill['surgery_type'] ?? 'N/A'); ?></strong><br>
                    <small class="text-muted">
                        Date: <?php echo htmlspecialchars($surgery_date); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Assessment Section -->
    <?php if ($bill['financial_assessment_id']): ?>
    <div class="assessment-card assessment-<?php echo strtolower($bill['assessment_type'] ?? ''); ?>">
        <h5>📋 Financial Assessment</h5>
        <div class="row">
            <div class="col-md-6">
                <strong>Assessment Type:</strong><br>
                <?php echo $assessment_type_badge; ?><br>
                <small class="text-muted">ID: FA-<?php echo htmlspecialchars($bill['financial_assessment_id']); ?></small>
            </div>
            <div class="col-md-6">
                <strong>Status:</strong><br>
                <?php echo $financial_status_badge; ?>
            </div>
        </div>
        
        <div class="row mt-2">
            <div class="col-md-6">
                <strong>Insurance Eligibility:</strong><br>
                <?php if ($bill['philhealth_eligible']): ?>
                    <span class="insurance-indicator insurance-yes">✓ PhilHealth Eligible</span>
                <?php else: ?>
                    <span class="insurance-indicator insurance-no">✗ PhilHealth Not Eligible</span>
                <?php endif; ?>
                
                <?php if (!empty($bill['hmo_provider'])): ?>
                    <span class="insurance-indicator insurance-yes">✓ HMO: <?php echo htmlspecialchars($bill['hmo_provider']); ?></span>
                <?php else: ?>
                    <span class="insurance-indicator insurance-no">✗ No HMO</span>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <strong>Dates:</strong><br>
                <small class="text-muted">
                    Assessed: <?php echo htmlspecialchars($assessment_date); ?><br>
                    <?php if ($bill['reviewed_at']): ?>
                        Reviewed: <?php echo htmlspecialchars($reviewed_date); ?><br>
                        By: <?php echo htmlspecialchars($bill['reviewed_by_name'] ?? 'N/A'); ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning">
        <strong>⚠️ No Financial Assessment Linked</strong><br>
        This bill is not linked to a financial assessment. Consider adding one for proper insurance coverage calculation.
        <div class="mt-2">
            <a href="financial_form.php?patient_id=<?php echo $bill['patient_id']; ?>&bill_id=<?php echo $bill['id']; ?>" 
               class="btn btn-sm btn-primary">Add Financial Assessment</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Billing Summary -->
    <div class="card mt-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">💰 Billing Summary</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <strong>Total Amount:</strong><br>
                    <h4 class="text-primary">₱ <?php echo number_format($bill['total_amount'], 2); ?></h4>
                </div>
                <div class="col-md-6">
                    <strong>Amount Due:</strong><br>
                    <h4 class="<?php echo $bill['status'] === 'Paid' ? 'text-success' : 'text-danger'; ?>">
                        ₱ <?php echo number_format($bill['amount_due'], 2); ?>
                    </h4>
                </div>
            </div>
            
            <!-- Coverage Bar -->
            <?php if ($bill['total_amount'] > 0): ?>
            <div class="mt-3">
                <strong>Insurance Coverage: <?php echo $coverage_percentage; ?>%</strong>
                <div class="coverage-bar">
                    <div class="coverage-fill" style="width: <?php echo min($coverage_percentage, 100); ?>%"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <hr>
            
            <div class="row">
                <div class="col-md-6">
                    <strong>PhilHealth Coverage:</strong><br>
                    <span class="text-success">₱ <?php echo number_format($bill['philhealth_coverage'], 2); ?></span>
                    <?php if ($bill['financial_assessment_id'] && !$bill['philhealth_eligible'] && $bill['philhealth_coverage'] > 0): ?>
                        <br><small class="text-warning">⚠️ Coverage exists but patient is not PhilHealth eligible</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <strong>HMO Coverage:</strong><br>
                    <span class="text-success">₱ <?php echo number_format($bill['hmo_coverage'], 2); ?></span>
                    <?php if ($bill['financial_assessment_id'] && empty($bill['hmo_provider']) && $bill['hmo_coverage'] > 0): ?>
                        <br><small class="text-warning">⚠️ Coverage exists but no HMO provider specified</small>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Financial Assessment Impact -->
            <?php if ($bill['financial_assessment_id']): ?>
            <hr>
            <div class="row">
                <div class="col-md-12">
                    <strong>Financial Assessment Impact:</strong><br>
                    <small class="text-muted">
                        <?php
                        $coverage_calculated = $bill['philhealth_coverage'] + $bill['hmo_coverage'];
                        $patient_pays = $bill['amount_due'];
                        $total = $bill['total_amount'];
                        
                        echo "Based on the <strong>" . htmlspecialchars($bill['assessment_type']) . "</strong> assessment, ";
                        echo "the patient is responsible for <strong>₱ " . number_format($patient_pays, 2) . "</strong> ";
                        echo "out of the total <strong>₱ " . number_format($total, 2) . "</strong> ";
                        echo "(" . round(($patient_pays / $total) * 100, 1) . "%).";
                        ?>
                    </small>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Dates & Archive Info -->
    <div class="row mt-3">
        <div class="col-md-4">
            <strong>Created:</strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($created_formatted); ?></small>
        </div>
        <div class="col-md-4">
            <strong>Paid Date:</strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($paid_formatted); ?></small>
        </div>
        <?php if ($bill['financial_assessment_id']): ?>
        <div class="col-md-4">
            <strong>Financial Assessment:</strong><br>
            <small class="text-muted">
                <?php echo htmlspecialchars($assessment_date); ?><br>
                <a href="financials.php?assessment_id=<?php echo $bill['financial_assessment_id']; ?>" 
                   class="btn btn-sm btn-outline-primary mt-1">View Assessment</a>
            </small>
        </div>
        <?php endif; ?>
    </div>

    <?php echo $archived_info; ?>
    
    <!-- Action Buttons -->
    <div class="mt-4 border-top pt-3">
        <div class="btn-group">
            <a href="billing_form.php?id=<?php echo $bill['id']; ?>" class="btn btn-primary">Edit Bill</a>
            <?php if (!$bill['financial_assessment_id']): ?>
                <a href="financial_form.php?patient_id=<?php echo $bill['patient_id']; ?>&bill_id=<?php echo $bill['id']; ?>" 
                   class="btn btn-success">Add Financial Assessment</a>
            <?php endif; ?>
            <a href="billing.php" class="btn btn-secondary">Back to Billing</a>
        </div>
    </div>
</div>