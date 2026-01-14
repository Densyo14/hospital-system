<?php
require_once 'config.php';
require_once 'functions.php';

$q = isset($_GET['q']) ? $_GET['q'] : '';
$show = isset($_GET['show']) ? $_GET['show'] : 'active';

// Check if archive columns exist
$table_check = fetchOne($conn, "SHOW COLUMNS FROM inventory_items LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;
$archive_condition = $has_archive_columns ? ($show === 'archived' ? "is_archived = 1" : "is_archived = 0") : "1=1";

// Get current user role for permission check
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user = fetchOne($conn, "SELECT role FROM users WHERE id = ?", "i", [$current_user_id]);
$is_admin = ($current_user['role'] ?? '') === 'Admin';
$is_inventory = ($current_user['role'] ?? '') === 'Inventory';

if ($q) {
    $rows = fetchAll($conn, 
        "SELECT * FROM inventory_items 
         WHERE {$archive_condition} AND (item_name LIKE ? OR category LIKE ?)
         ORDER BY item_name", 
        "ss", 
        ["%$q%", "%$q%"]
    );
} else {
    $rows = fetchAll($conn, "SELECT * FROM inventory_items WHERE {$archive_condition} ORDER BY item_name", null, []);
}

// Only echo <tr> rows - no other HTML
if (empty($rows)) {
    echo '<tr><td colspan="8" class="text-center text-muted py-4">No items found.</td></tr>';
} else {
    foreach($rows as $r) {
        $row_class = ($has_archive_columns && !empty($r['is_archived'])) ? 'table-secondary' : '';
        
        // Stock status badge
        $stock_status = '';
        $stock_class = '';
        if ($r['quantity'] <= 0) {
            $stock_status = 'Out of Stock';
            $stock_class = 'danger';
        } elseif ($r['quantity'] <= $r['threshold']) {
            $stock_status = 'Low Stock';
            $stock_class = 'warning';
        } else {
            $stock_status = 'In Stock';
            $stock_class = 'success';
        }
        
        $status_badge = '<span class="badge bg-'.$stock_class.'">'.$stock_status.'</span>';
        
        if ($has_archive_columns && !empty($r['is_archived'])) {
            $status_badge .= ' <span class="badge bg-danger">Archived</span>';
        }

        // Highlight low stock rows
        if ($r['quantity'] <= $r['threshold'] && empty($r['is_archived'])) {
            $row_class .= ' table-warning';
        }
        
        echo '<tr data-item-id="'.$r['id'].'" class="'.$row_class.'">
                <td>'.$r['id'].'</td>
                <td>'.h($r['item_name']).'</td>
                <td>'.h($r['category']).'</td>
                <td>'.$r['quantity'].'</td>
                <td>'.h($r['unit']).'</td>
                <td>'.$r['threshold'].'</td>
                <td>'.$status_badge.'</td>
                <td class="d-flex gap-2">';
        
        // Always show View button for all users
        echo '<button type="button" class="btn btn-sm btn-info" onclick="viewItem('.$r['id'].')">View</button>';
        
        // Edit link
        if (!$has_archive_columns || empty($r['is_archived'])) {
            echo '<a href="inventory_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
        } else {
            if ($is_admin || $is_inventory) {
                echo '<a href="inventory_form.php?id='.$r['id'].'" class="btn btn-sm btn-warning">Edit</a>';
            }
        }

        // Archive / Restore
        if ($has_archive_columns && ($is_admin || $is_inventory)) {
            if (!empty($r['is_archived'])) {
                echo '<button type="button" class="btn btn-sm btn-outline-success" onclick="confirmRestore('.$r['id'].', \''.h($r['item_name']).'\')">Restore</button>';
            } else {
                echo '<button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmArchive('.$r['id'].', \''.h($r['item_name']).'\')">Archive</button>';
            }
        }

        // Delete button
        if ($is_admin || $is_inventory) {
            echo '<button type="button" class="btn btn-sm btn-outline-dark" onclick="confirmDelete('.$r['id'].', \''.h($r['item_name']).'\')">Delete</button>';
        }
        
        echo '</td></tr>';
    }
}
?>