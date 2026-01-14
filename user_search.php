<?php
require_once 'config.php';
require_once 'functions.php';

session_start();

// Only Admins can access this
if ($_SESSION['role'] != 'Admin') {
    echo '<tr><td colspan="7" class="text-center text-danger">Access denied.</td></tr>';
    exit();
}

$q = isset($_GET['q']) ? $_GET['q'] : '';
$show = isset($_GET['show']) ? $_GET['show'] : 'active';

// Check if is_active column exists
$table_check = fetchOne($conn, "SHOW COLUMNS FROM users LIKE 'is_active'");
$has_status_columns = $table_check !== null;
$status_condition = $has_status_columns ? ($show === 'inactive' ? "is_active = 0" : "is_active = 1") : "1=1";

if ($q) {
    $rows = fetchAll($conn, 
        "SELECT id, full_name, username, role, created_at" . 
        ($has_status_columns ? ", is_active, deactivated_at" : "") . "
         FROM users 
         WHERE {$status_condition} AND (full_name LIKE ? OR username LIKE ?)
         ORDER BY id DESC", 
        "ss", 
        ["%$q%", "%$q%"]
    );
} else {
    $rows = fetchAll($conn, 
        "SELECT id, full_name, username, role, created_at" . 
        ($has_status_columns ? ", is_active, deactivated_at" : "") . "
         FROM users 
         WHERE {$status_condition}
         ORDER BY id DESC", 
        null, []
    );
}

// Only echo <tr> rows - no other HTML
if (empty($rows)) {
    $message = $show === 'inactive' ? 'No inactive users found.' : 'No users found.';
    echo '<tr><td colspan="7" class="text-center text-muted py-4">'.$message.'</td></tr>';
} else {
    foreach($rows as $r) {
        $row_class = ($has_status_columns && empty($r['is_active'])) ? 'table-secondary' : '';
        
        // Role badge with colors
        $role_class = [
            'Admin' => 'danger',
            'Doctor' => 'primary',
            'Nurse' => 'info',
            'Staff' => 'secondary',
            'Inventory' => 'warning',
            'Billing' => 'success',
            'SocialWorker' => 'dark'
        ][$r['role']] ?? 'secondary';
        
        $role_badge = '<span class="badge bg-'.$role_class.'">'.h($r['role']).'</span>';

        // Status badge
        $status_badge = '';
        if ($has_status_columns) {
            if ($r['is_active']) {
                $status_badge = '<span class="badge bg-success">Active</span>';
            } else {
                $status_badge = '<span class="badge bg-danger">Inactive</span>';
                if ($r['deactivated_at']) {
                    $status_badge .= '<br><small class="text-muted">'.date('M j, Y', strtotime($r['deactivated_at'])).'</small>';
                }
            }
        } else {
            $status_badge = '<span class="badge bg-success">Active</span>';
        }

        // Format created date
        $created_formatted = date('M j, Y g:i A', strtotime($r['created_at']));
        
        echo '<tr data-user-id="'.$r['id'].'" class="'.$row_class.'">
                <td>'.$r['id'].'</td>
                <td>'.h($r['full_name']).'</td>
                <td>'.h($r['username']).'</td>
                <td>'.$role_badge.'</td>
                <td>'.$status_badge.'</td>
                <td><small>'.h($created_formatted).'</small></td>
                <td class="d-flex gap-2">';
        
        // Edit button (Admin can edit any user)
        echo '<a href="user_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';

        // Activate/Deactivate buttons
        if ($has_status_columns) {
            if ($r['is_active']) {
                // Cannot deactivate self
                if ($r['id'] != $_SESSION['user_id']) {
                    echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDeactivate('.$r['id'].', \''.h($r['full_name']).'\')">Deactivate</button>';
                } else {
                    echo '<button type="button" class="btn btn-sm btn-outline-secondary" disabled>Deactivate</button>';
                }
            } else {
                echo '<button type="button" class="btn btn-sm btn-outline-success" onclick="confirmActivate('.$r['id'].', \''.h($r['full_name']).'\')">Activate</button>';
            }
        }

        // Delete button (cannot delete self)
        if ($r['id'] != $_SESSION['user_id']) {
            echo '<button type="button" class="btn btn-sm btn-outline-dark" onclick="confirmDelete('.$r['id'].', \''.h($r['full_name']).'\')">Delete</button>';
        } else {
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" disabled>Delete</button>';
        }
        
        echo '</td></tr>';
    }
}
?>