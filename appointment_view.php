<?php
require 'config.php';
require 'functions.php';
session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div class='alert alert-danger'>Invalid appointment ID.</div>";
    exit;
}

$id = (int)$_GET['id'];

// Fetch appointment with all details (removed email field)
$appointment = fetchOne($conn, "
    SELECT a.*, 
           p.first_name AS patient_first, p.last_name AS patient_last,
           p.phone AS patient_phone, p.birth_date AS patient_birth_date,
           u.full_name AS doctor_name,
           arch_user.full_name AS archived_by_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN users u ON a.doctor_id = u.id
    LEFT JOIN users arch_user ON a.archived_by = arch_user.id
    WHERE a.id = ?
", "i", [$id]);

if (!$appointment) {
    echo "<div class='alert alert-danger'>Appointment not found.</div>";
    exit;
}

// Archived info
$is_archived = !empty($appointment['is_archived']);
$archived_badge = $is_archived
    ? "<span class='badge bg-danger'>Archived</span>"
    : "<span class='badge bg-success'>Active</span>";

// Status badge
$status_class = [
    'Pending' => 'warning',
    'Approved' => 'success', 
    'Completed' => 'info',
    'Cancelled' => 'danger'
][$appointment['status']] ?? 'secondary';

$status_badge = "<span class='badge bg-{$status_class}'>{$appointment['status']}</span>";

$archived_info = "";
if ($is_archived) {
    $archived_info = "
        <div class='alert alert-warning mt-3'>
            <strong>Archived:</strong> " . ($appointment['archived_at'] ? date('F j, Y g:i A', strtotime($appointment['archived_at'])) : 'N/A') . "<br>
            <strong>Archived by:</strong> " . htmlspecialchars($appointment['archived_by_name'] ?? 'Unknown') . "
        </div>
    ";
}

// Format dates
$schedule_formatted = date('F j, Y g:i A', strtotime($appointment['schedule_datetime']));
$created_formatted = $appointment['created_at'] ? date('F j, Y g:i A', strtotime($appointment['created_at'])) : 'N/A';
$patient_age = $appointment['patient_birth_date'] ? floor((time() - strtotime($appointment['patient_birth_date'])) / 31556926) : 'N/A';
?>

<div class="container-fluid">
    <h4 class="mb-3">Appointment Information</h4>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Appointment ID:</strong><br><?php echo htmlspecialchars($appointment['id']); ?></div>
        <div class="col-md-6"><strong>Status:</strong><br><?php echo $status_badge . ' ' . $archived_badge; ?></div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Patient:</strong><br>
            <?php echo htmlspecialchars($appointment['patient_first'] . " " . $appointment['patient_last']); ?><br>
            <small class="text-muted">
                DOB: <?php echo htmlspecialchars($appointment['patient_birth_date'] ?? 'N/A'); ?> 
                (<?php echo $patient_age; ?> years)<br>
                Phone: <?php echo htmlspecialchars($appointment['patient_phone'] ?? 'N/A'); ?>
            </small>
        </div>
        <div class="col-md-6">
            <strong>Doctor:</strong><br>
            <?php echo htmlspecialchars($appointment['doctor_name']); ?>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Scheduled Date & Time:</strong><br><?php echo htmlspecialchars($schedule_formatted); ?></div>
        <div class="col-md-6"><strong>Created:</strong><br><?php echo htmlspecialchars($created_formatted); ?></div>
    </div>

    <div class="mb-3">
        <strong>Reason for Appointment:</strong><br>
        <div class="border rounded p-3 bg-light">
            <?php echo nl2br(htmlspecialchars($appointment['reason'] ?? 'No reason provided.')); ?>
        </div>
    </div>

    <?php if (!empty($appointment['notes'])): ?>
    <div class="mb-3">
        <strong>Additional Notes:</strong><br>
        <div class="border rounded p-3 bg-light">
            <?php echo nl2br(htmlspecialchars($appointment['notes'])); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php echo $archived_info; ?>
</div>