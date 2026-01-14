<?php
require_once 'config.php';
require_once 'functions.php';

$q = isset($_GET['q']) ? $_GET['q'] : '';
$show = isset($_GET['show']) ? $_GET['show'] : 'active';

// Check if archive columns exist
$table_check = fetchOne($conn, "SHOW COLUMNS FROM financial_assessment LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;
$archive_condition = $has_archive_columns ? ($show === 'archived' ? "f.is_archived = 1" : "f.is_archived = 0") : "1=1";

// Get current user role for permission check
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_social_worker = ($current_user['role'] ?? '') === 'SocialWorker';

if ($q) {
    $rows = fetchAll($conn, 
        "SELECT f.*, p.first_name, p.last_name 
         FROM financial_assessment f 
         LEFT JOIN patients p ON f.patient_id = p.id 
         WHERE {$archive_condition} AND CONCAT(p.first_name,' ',p.last_name) LIKE ? 
         ORDER BY f.id DESC", 
        "s", 
        ["%$q%"]
    );
} else {
    $rows = fetchAll($conn, 
        "SELECT f.*, p.first_name, p.last_name 
         FROM financial_assessment f 
         LEFT JOIN patients p ON f.patient_id = p.id 
         WHERE {$archive_condition}
         ORDER BY f.id DESC", 
        null, []
    );
}

// Only echo <tr> rows - no other HTML
if (empty($rows)) {
    echo '<tr><td colspan="7" class="text-center text-muted py-4">No assessments found.</td></tr>';
} else {
    foreach($rows as $r) {
        $row_class = ($has_archive_columns && !empty($r['is_archived'])) ? 'table-secondary' : '';
        
        // Assessment type badge with colors
        $type_class = [
            'Charity' => 'success',
            'Partial' => 'warning', 
            'Paying' => 'info'
        ][$r['assessment_type']] ?? 'secondary';
        
        $type_badge = '<span class="badge bg-'.$type_class.'">'.h($r['assessment_type']).'</span>';
        
        // Status badge with colors
        $status_class = [
            'Pending' => 'warning',
            'Approved' => 'success', 
            'Rejected' => 'danger'
        ][$r['status']] ?? 'secondary';
        
        $status_badge = '<span class="badge bg-'.$status_class.'">'.h($r['status']).'</span>';
        
        if ($has_archive_columns && !empty($r['is_archived'])) {
            $status_badge .= ' <span class="badge bg-danger">Archived</span>';
        }

        // PhilHealth badge
        $philhealth_badge = $r['philhealth_eligible'] 
            ? '<span class="badge bg-success">Yes</span>' 
            : '<span class="badge bg-secondary">No</span>';
        
        echo '<tr data-assessment-id="'.$r['id'].'" class="'.$row_class.'">
                <td>'.$r['id'].'</td>
                <td>'.h($r['first_name'].' '.$r['last_name']).'</td>
                <td>'.$type_badge.'</td>
                <td>'.$philhealth_badge.'</td>
                <td>'.h($r['hmo_provider'] ?? 'N/A').'</td>
                <td>'.$status_badge.'</td>
                <td class="d-flex gap-2">';
        
        // Always show View button for all users
        echo '<button type="button" class="btn btn-sm btn-info" onclick="viewAssessment('.$r['id'].')">View</button>';
        
        // Edit link
        if (!$has_archive_columns || empty($r['is_archived'])) {
            echo '<a href="financial_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
        } else {
            if ($is_admin || $is_social_worker) {
                echo '<a href="financial_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
            }
        }

        // Approve/Reject buttons
        if ($r['status'] === 'Pending' && ($is_admin || $is_social_worker) && (empty($r['is_archived']) || !$has_archive_columns)) {
            echo '<button type="button" class="btn btn-sm btn-success" onclick="confirmApprove('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Approve</button>';
            echo '<button type="button" class="btn btn-sm btn-danger" onclick="confirmReject('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Reject</button>';
        }

        // Archive / Restore
        if ($has_archive_columns && ($is_admin || $is_social_worker)) {
            if (!empty($r['is_archived'])) {
                echo '<button type="button" class="btn btn-sm btn-outline-success" onclick="confirmRestore('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Restore</button>';
            } else {
                echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmArchive('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Archive</button>';
            }
        }

        // Delete button
        if ($is_admin || $is_social_worker) {
            echo '<button type="button" class="btn btn-sm btn-outline-dark" onclick="confirmDelete('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Delete</button>';
        }
        
        echo '</td></tr>';
    }
}
?>