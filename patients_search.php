<?php
require_once 'config.php';
require_once 'functions.php';

$q = isset($_GET['q']) ? $_GET['q'] : '';
$show = isset($_GET['show']) ? $_GET['show'] : 'active';
$archive_condition = $show === 'archived' ? "p.is_archived = 1" : "p.is_archived = 0";

if ($q) {
    $rows = fetchAll($conn, "SELECT p.*, 
                                     ROUND(DATEDIFF(CURDATE(), p.birth_date)/365) AS age,
                                     GROUP_CONCAT(m.diagnosis SEPARATOR ', ') AS diagnosis
                              FROM patients p
                              LEFT JOIN medical_records m ON p.id = m.patient_id
                              WHERE $archive_condition AND CONCAT(p.first_name,' ',p.last_name) LIKE ?
                              GROUP BY p.id
                              ORDER BY p.id DESC", "s", ["%$q%"]);
} else {
    $rows = fetchAll($conn, "SELECT p.*, 
                                     ROUND(DATEDIFF(CURDATE(), p.birth_date)/365) AS age,
                                     GROUP_CONCAT(m.diagnosis SEPARATOR ', ') AS diagnosis
                              FROM patients p
                              LEFT JOIN medical_records m ON p.id = m.patient_id
                              WHERE $archive_condition
                              GROUP BY p.id
                              ORDER BY p.id DESC", null, []);
}

// Get current user role for permission check
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';

// Only echo <tr> rows - no other HTML
if (count($rows) == 0) {
    echo '<tr><td colspan="8" class="no-data">No patients found.</td></tr>';
} else {
    foreach ($rows as $r) {
        $row_class = $r['is_archived'] ? 'archived-row' : '';
        $status_badge = $r['is_archived'] ? '<span class="archived-badge">Archived</span>' : '<span class="active-badge">Active</span>';
        
        // Truncate long diagnosis
        $diagnosis_display = $r['diagnosis'] ?? '';
        if (strlen($diagnosis_display) > 50) {
            $diagnosis_display = substr($diagnosis_display, 0, 50) . '...';
        }
        
        echo '<tr data-patient-id="'.$r['id'].'" class="'.$row_class.'">
                <td>'.$r['id'].'</td>
                <td>'.h($r['patient_code']).'</td>
                <td>'.h($r['first_name'].' '.$r['last_name']).'</td>
                <td>'.h($r['birth_date']).'</td>
                <td>'.h($r['age']).'</td>
                <td>'.h($diagnosis_display).'</td>
                <td>'.$status_badge.'</td>
                <td class="actions">';
        
        // Always show View button for all users
        echo '<button type="button" class="btn-view" onclick="viewPatient('.$r['id'].')">View</button>';
        
        if ($r['is_archived']) {
            // Archived patient actions - only show restore for admin
            if ($is_admin) {
                echo '<button type="button" class="btn-restore" onclick="confirmRestore('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Restore</button>';
            }
        } else {
            // Active patient actions - show Edit for everyone
            echo '<a href="patient_form.php?id='.$r['id'].'" class="btn-edit">Edit</a>';
            
            // Only show Archive for admin
            if ($is_admin) {
                echo '<button type="button" class="btn-archive" onclick="confirmArchive('.$r['id'].', \''.h($r['first_name'].' '.$r['last_name']).'\')">Archive</button>';
            }
        }
        
        echo '</td></tr>';
    }
}
?>