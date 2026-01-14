<?php
require_once 'config.php';
require_once 'functions.php';

$q = isset($_GET['q']) ? $_GET['q'] : '';
$show = isset($_GET['show']) ? $_GET['show'] : 'active';

// Check if archive columns exist
$table_check = fetchOne($conn, "SHOW COLUMNS FROM surgeries LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;
$archive_condition = $has_archive_columns ? ($show === 'archived' ? "s.is_archived = 1" : "s.is_archived = 0") : "1=1";

// Get current user role for permission check
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';

if ($q) {
    $rows = fetchAll($conn, 
        "SELECT s.*, 
                p.first_name, p.last_name,
                u.full_name AS doctor_name
         FROM surgeries s
         INNER JOIN patients p ON s.patient_id = p.id
         INNER JOIN users u ON s.doctor_id = u.id
         WHERE {$archive_condition} AND (
            CONCAT(p.first_name,' ',p.last_name) LIKE ? 
            OR u.full_name LIKE ? 
            OR s.surgery_type LIKE ?
         )
         ORDER BY s.schedule_date DESC",
        "sss", 
        ["%$q%", "%$q%", "%$q%"]
    );
} else {
    $rows = fetchAll($conn, 
        "SELECT s.*, 
                p.first_name, p.last_name,
                u.full_name AS doctor_name
         FROM surgeries s
         INNER JOIN patients p ON s.patient_id = p.id
         INNER JOIN users u ON s.doctor_id = u.id
         WHERE {$archive_condition}
         ORDER BY s.schedule_date DESC",
        null, []
    );
}

// Only echo <tr> rows - no other HTML
if (empty($rows)) {
    echo '<tr><td colspan="8" class="text-center text-muted py-4">No surgeries found.</td></tr>';
} else {
    foreach ($rows as $r) {
        $row_class = ($has_archive_columns && !empty($r['is_archived'])) ? 'table-secondary' : '';
        
        // Status badge with colors
        $status_class = [
            'Scheduled' => 'warning',
            'Completed' => 'success', 
            'Cancelled' => 'danger'
        ][$r['status']] ?? 'secondary';
        
        $status_badge = '<span class="badge bg-'.$status_class.'">'.h($r['status']).'</span>';
        
        if ($has_archive_columns && !empty($r['is_archived'])) {
            $status_badge .= ' <span class="badge bg-danger">Archived</span>';
        }

        // Format date
        $date_formatted = date('M j, Y', strtotime($r['schedule_date']));
        
        echo '<tr data-surgery-id="'.$r['id'].'" class="'.$row_class.'">
                <td>S-'.$r['id'].'</td>
                <td>'.h($r['first_name'].' '.$r['last_name']).'</td>
                <td>'.h($r['surgery_type']).'</td>
                <td>'.h($r['doctor_name']).'</td>
                <td>'.h($date_formatted).'</td>
                <td>'.h($r['operating_room']).'</td>
                <td>'.$status_badge.'</td>
                <td class="d-flex gap-2">';
        
        // Always show View button for all users
        echo '<button type="button" class="btn btn-sm btn-info" onclick="viewSurgery('.$r['id'].')">View</button>';
        
        // Edit link
        if (!$has_archive_columns || empty($r['is_archived'])) {
            echo '<a href="surgery_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
        } else {
            if ($is_admin) {
                echo '<a href="surgery_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
            }
        }

        // Archive / Restore
        if ($has_archive_columns) {
            if (!empty($r['is_archived'])) {
                if ($is_admin) {
                    echo '<button type="button" class="btn btn-sm btn-outline-success" onclick="confirmRestore('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Restore</button>';
                }
            } else {
                if ($is_admin) {
                    echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmArchive('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Archive</button>';
                }
            }
        }

        // Delete button
        if ($is_admin) {
            echo '<button type="button" class="btn btn-sm btn-outline-dark" onclick="confirmDelete('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Delete</button>';
        }
        
        echo '</td></tr>';
    }
}
?>