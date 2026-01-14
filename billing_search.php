<?php
require_once 'config.php';
require_once 'functions.php';

$q = isset($_GET['q']) ? $_GET['q'] : '';
$show = isset($_GET['show']) ? $_GET['show'] : 'active';

// Check if archive columns exist
$table_check = fetchOne($conn, "SHOW COLUMNS FROM billing LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;
$archive_condition = $has_archive_columns ? ($show === 'archived' ? "b.is_archived = 1" : "b.is_archived = 0") : "1=1";

// Get current user role for permission check
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_billing = ($current_user['role'] ?? '') === 'Billing';

if ($q) {
    $rows = fetchAll($conn, 
        "SELECT b.*, p.first_name AS pf, p.last_name AS pl, s.surgery_type 
         FROM billing b 
         LEFT JOIN patients p ON b.patient_id = p.id 
         LEFT JOIN surgeries s ON b.surgery_id = s.id 
         WHERE {$archive_condition} AND CONCAT(p.first_name,' ',p.last_name) LIKE ? 
         ORDER BY b.id DESC", 
        "s", 
        ["%$q%"]
    );
} else {
    $rows = fetchAll($conn, 
        "SELECT b.*, p.first_name AS pf, p.last_name AS pl, s.surgery_type 
         FROM billing b 
         LEFT JOIN patients p ON b.patient_id = p.id 
         LEFT JOIN surgeries s ON b.surgery_id = s.id 
         WHERE {$archive_condition}
         ORDER BY b.id DESC", 
        null, []
    );
}

// Only echo <tr> rows - no other HTML
if (empty($rows)) {
    echo '<tr><td colspan="9" class="text-center text-muted py-4">No bills found.</td></tr>';
} else {
    foreach($rows as $r) {
        $row_class = ($has_archive_columns && !empty($r['is_archived'])) ? 'table-secondary' : '';
        
        // Status badge with colors
        $status_class = [
            'Unpaid' => 'danger',
            'Paid' => 'success'
        ][$r['status']] ?? 'secondary';
        
        $status_badge = '<span class="badge bg-'.$status_class.'">'.h($r['status']).'</span>';
        
        if ($has_archive_columns && !empty($r['is_archived'])) {
            $status_badge .= ' <span class="badge bg-danger">Archived</span>';
        }
        
        echo '<tr data-bill-id="'.$r['id'].'" class="'.$row_class.'">
                <td>TX-'.$r['id'].'</td>
                <td>'.h($r['pf'].' '.$r['pl']).'</td>
                <td>'.h($r['surgery_type'] ?? 'N/A').'</td>
                <td class="text-end">₱ '.number_format($r['total_amount'],2).'</td>
                <td class="text-end">₱ '.number_format($r['philhealth_coverage'],2).'</td>
                <td class="text-end">₱ '.number_format($r['hmo_coverage'],2).'</td>
                <td class="text-end fw-bold">₱ '.number_format($r['amount_due'],2).'</td>
                <td>'.$status_badge.'</td>
                <td class="d-flex gap-2">';
        
        // Always show View button for all users
        echo '<button type="button" class="btn btn-sm btn-info" onclick="viewBill('.$r['id'].')">View</button>';
        
        // Edit link
        if (!$has_archive_columns || empty($r['is_archived'])) {
            echo '<a href="billing_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
        } else {
            if ($is_admin || $is_billing) {
                echo '<a href="billing_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
            }
        }

        // Mark Paid button
        if ($r['status'] === 'Unpaid' && ($is_admin || $is_billing) && (empty($r['is_archived']) || !$has_archive_columns)) {
            echo '<button type="button" class="btn btn-sm btn-success" onclick="confirmMarkPaid('.$r['id'].', \''.h($r['pf'].' '.$r['pl']).'\')">Mark Paid</button>';
        }

        // Archive / Restore
        if ($has_archive_columns && ($is_admin || $is_billing)) {
            if (!empty($r['is_archived'])) {
                echo '<button type="button" class="btn btn-sm btn-outline-success" onclick="confirmRestore('.$r['id'].', \''.h($r['pf'].' '.$r['pl']).'\')">Restore</button>';
            } else {
                echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmArchive('.$r['id'].', \''.h($r['pf'].' '.$r['pl']).'\')">Archive</button>';
            }
        }

        // Delete button
        if ($is_admin || $is_billing) {
            echo '<button type="button" class="btn btn-sm btn-outline-dark" onclick="confirmDelete('.$r['id'].', \''.h($r['pf'].' '.$r['pl']).'\')">Delete</button>';
        }
        
        echo '</td></tr>';
    }
}
?>