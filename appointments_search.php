<?php
require_once 'config.php';
require_once 'functions.php';

$q = isset($_GET['q']) ? $_GET['q'] : '';

// Get current user role for permission check
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';

if ($q) {
    $rows = fetchAll($conn, "SELECT a.*, 
                                    p.first_name AS patient_first, p.last_name AS patient_last,
                                    u.full_name AS doctor_name
                             FROM appointments a
                             LEFT JOIN patients p ON a.patient_id = p.id
                             LEFT JOIN users u ON a.doctor_id = u.id
                             WHERE CONCAT(p.first_name,' ',p.last_name) LIKE ? OR u.full_name LIKE ?
                             ORDER BY a.schedule_datetime DESC", "ss", ["%$q%", "%$q%"]);
} else {
    $rows = fetchAll($conn, "SELECT a.*, 
                                    p.first_name AS patient_first, p.last_name AS patient_last,
                                    u.full_name AS doctor_name
                             FROM appointments a
                             LEFT JOIN patients p ON a.patient_id = p.id
                             LEFT JOIN users u ON a.doctor_id = u.id
                             ORDER BY a.schedule_datetime DESC", null, []);
}

// Only echo <tr> rows - no other HTML
if (count($rows) == 0) {
    echo '<tr><td colspan="7" class="text-center text-muted py-4">No appointments found.</td></tr>';
} else {
    foreach ($rows as $r) {
        // Status badge with colors
        $status_class = [
            'Pending' => 'warning',
            'Approved' => 'success', 
            'Completed' => 'info',
            'Cancelled' => 'danger'
        ][$r['status']] ?? 'secondary';
        
        $status_badge = '<span class="badge bg-'.$status_class.'">'.h($r['status']).'</span>';

        // Format datetime
        $schedule_formatted = date('M j, Y g:i A', strtotime($r['schedule_datetime']));

        // Shorten reason for table display
        $reason_display = $r['reason'] ?? '';
        if (strlen($reason_display) > 60) {
            $reason_display = substr($reason_display, 0, 60) . '...';
        }
        
        echo '<tr data-appointment-id="'.$r['id'].'">
                <td>'.$r['id'].'</td>
                <td>'.h($r['patient_first'].' '.$r['patient_last']).'</td>
                <td>'.h($r['doctor_name']).'</td>
                <td>'.h($schedule_formatted).'</td>
                <td>'.h($reason_display).'</td>
                <td>'.$status_badge.'</td>
                <td class="d-flex gap-2">';
        
        // Always show View button for all users
        echo '<button type="button" class="btn btn-sm btn-info" onclick="viewAppointment('.$r['id'].')">View</button>';
        
        // Edit link
        echo '<a href="appointment_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
        
        // Delete button (admin only)
        if ($is_admin) {
            echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete('.$r['id'].', \''.h($r['patient_first'].' '.$r['patient_last']).'\')">Delete</button>';
        }
        
        echo '</td></tr>';
    }
}
?>