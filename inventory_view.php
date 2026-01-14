<?php
require 'config.php';
require 'functions.php';
session_start();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div class='alert alert-danger'>Invalid item ID.</div>";
    exit;
}

$id = (int)$_GET['id'];

// Fetch item with all details
$item = fetchOne($conn, "SELECT i.*, u.full_name AS archived_by_name FROM inventory_items i LEFT JOIN users u ON i.archived_by = u.id WHERE i.id = ?", "i", [$id]);

if (!$item) {
    echo "<div class='alert alert-danger'>Item not found.</div>";
    exit;
}

// Check if archive columns exist
$table_check = fetchOne($conn, "SHOW COLUMNS FROM inventory_items LIKE 'is_archived'");
$has_archive_columns = $table_check !== null;

// Archived info
$is_archived = $has_archive_columns && !empty($item['is_archived']);
$archived_badge = $is_archived
    ? "<span class='badge bg-danger'>Archived</span>"
    : "<span class='badge bg-success'>Active</span>";

// Stock status
$stock_status = '';
$stock_class = '';
if ($item['quantity'] <= 0) {
    $stock_status = 'Out of Stock';
    $stock_class = 'danger';
} elseif ($item['quantity'] <= $item['threshold']) {
    $stock_status = 'Low Stock';
    $stock_class = 'warning';
} else {
    $stock_status = 'In Stock';
    $stock_class = 'success';
}

$stock_badge = "<span class='badge bg-{$stock_class}'>{$stock_status}</span>";

$archived_info = "";
if ($is_archived) {
    $archived_info = "
        <div class='alert alert-warning mt-3'>
            <strong>Archived:</strong> " . ($item['archived_at'] ? date('F j, Y g:i A', strtotime($item['archived_at'])) : 'N/A') . "<br>
            <strong>Archived by:</strong> " . htmlspecialchars($item['archived_by_name'] ?? 'Unknown') . "
        </div>
    ";
}

// Fetch usage in surgeries
$surgery_usage = fetchAll($conn, "
    SELECT s.id, s.surgery_type, s.schedule_date, si.quantity_used
    FROM surgery_inventory si
    INNER JOIN surgeries s ON si.surgery_id = s.id
    WHERE si.item_id = ?
    ORDER BY s.schedule_date DESC
", "i", [$id]);
?>

<div class="container-fluid">
    <h4 class="mb-3">Inventory Item Details</h4>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Item ID:</strong><br><?php echo htmlspecialchars($item['id']); ?></div>
        <div class="col-md-6"><strong>Status:</strong><br><?php echo $stock_badge . ' ' . $archived_badge; ?></div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Item Name:</strong><br><?php echo htmlspecialchars($item['item_name']); ?></div>
        <div class="col-md-6"><strong>Category:</strong><br><?php echo htmlspecialchars($item['category']); ?></div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Current Quantity:</strong><br><?php echo htmlspecialchars($item['quantity']); ?> <?php echo htmlspecialchars($item['unit']); ?></div>
        <div class="col-md-6"><strong>Low Stock Threshold:</strong><br><?php echo htmlspecialchars($item['threshold']); ?> <?php echo htmlspecialchars($item['unit']); ?></div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Unit:</strong><br><?php echo htmlspecialchars($item['unit']); ?></div>
        <div class="col-md-6"><strong>Last Updated:</strong><br><?php echo htmlspecialchars($item['updated_at'] ?? 'N/A'); ?></div>
    </div>

    <?php if (!empty($surgery_usage)): ?>
    <div class="mb-3">
        <strong>Usage in Surgeries:</strong>
        <div class="table-responsive mt-2">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Surgery ID</th>
                        <th>Surgery Type</th>
                        <th>Date</th>
                        <th>Quantity Used</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($surgery_usage as $usage): ?>
                    <tr>
                        <td>S-<?php echo htmlspecialchars($usage['id']); ?></td>
                        <td><?php echo htmlspecialchars($usage['surgery_type']); ?></td>
                        <td><?php echo htmlspecialchars($usage['schedule_date']); ?></td>
                        <td><?php echo htmlspecialchars($usage['quantity_used']); ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php echo $archived_info; ?>
</div>