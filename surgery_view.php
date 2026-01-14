    <?php
    require 'config.php';
    require 'functions.php';
    session_start();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        echo "<div class='alert alert-danger'>Invalid surgery ID.</div>";
        exit;
    }

    $id = (int)$_GET['id'];

    // Fetch surgery with all details
    $surgery = fetchOne($conn, "
        SELECT s.*, 
            p.first_name, p.last_name, p.birth_date, p.phone,
            u.full_name AS doctor_name,
            arch_user.full_name AS archived_by_name
        FROM surgeries s
        INNER JOIN patients p ON s.patient_id = p.id
        INNER JOIN users u ON s.doctor_id = u.id
        LEFT JOIN users arch_user ON s.archived_by = arch_user.id
        WHERE s.id = ?
    ", "i", [$id]);

    if (!$surgery) {
        echo "<div class='alert alert-danger'>Surgery not found.</div>";
        exit;
    }

    // Check if archive columns exist
    $table_check = fetchOne($conn, "SHOW COLUMNS FROM surgeries LIKE 'is_archived'");
    $has_archive_columns = $table_check !== null;

    // Archived info
    $is_archived = $has_archive_columns && !empty($surgery['is_archived']);
    $archived_badge = $is_archived
        ? "<span class='badge bg-danger'>Archived</span>"
        : "<span class='badge bg-success'>Active</span>";

    // Status badge
    $status_class = [
        'Scheduled' => 'warning',
        'Completed' => 'success', 
        'Cancelled' => 'danger'
    ][$surgery['status']] ?? 'secondary';

    $status_badge = "<span class='badge bg-{$status_class}'>{$surgery['status']}</span>";

    $archived_info = "";
    if ($is_archived) {
        $archived_info = "
            <div class='alert alert-warning mt-3'>
                <strong>Archived:</strong> " . ($surgery['archived_at'] ? date('F j, Y g:i A', strtotime($surgery['archived_at'])) : 'N/A') . "<br>
                <strong>Archived by:</strong> " . htmlspecialchars($surgery['archived_by_name'] ?? 'Unknown') . "
            </div>
        ";
    }

    // Format dates
    $schedule_formatted = date('F j, Y', strtotime($surgery['schedule_date']));
    $patient_age = $surgery['birth_date'] ? floor((time() - strtotime($surgery['birth_date'])) / 31556926) : 'N/A';

    // Fetch inventory items used in this surgery
    $inventory_items = fetchAll($conn, "
        SELECT si.quantity_used, ii.item_name, ii.category, ii.unit
        FROM surgery_inventory si
        INNER JOIN inventory_items ii ON si.item_id = ii.id
        WHERE si.surgery_id = ?
        ORDER BY ii.category, ii.item_name
    ", "i", [$id]);
    ?>

    <div class="container-fluid">
        <h4 class="mb-3">Surgery Information</h4>

        <div class="row mb-3">
            <div class="col-md-6"><strong>Surgery ID:</strong><br>S-<?php echo htmlspecialchars($surgery['id']); ?></div>
            <div class="col-md-6"><strong>Status:</strong><br><?php echo $status_badge . ' ' . $archived_badge; ?></div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <strong>Patient:</strong><br>
                <?php echo htmlspecialchars($surgery['first_name'] . " " . $surgery['last_name']); ?><br>
                <small class="text-muted">
                    DOB: <?php echo htmlspecialchars($surgery['birth_date'] ?? 'N/A'); ?> 
                    (<?php echo $patient_age; ?> years)<br>
                    Phone: <?php echo htmlspecialchars($surgery['phone'] ?? 'N/A'); ?>
                </small>
            </div>
            <div class="col-md-6">
                <strong>Surgeon:</strong><br>
                <?php echo htmlspecialchars($surgery['doctor_name']); ?>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6"><strong>Surgery Type:</strong><br><?php echo htmlspecialchars($surgery['surgery_type']); ?></div>
            <div class="col-md-6"><strong>Scheduled Date:</strong><br><?php echo htmlspecialchars($schedule_formatted); ?></div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6"><strong>Operating Room:</strong><br><?php echo htmlspecialchars($surgery['operating_room'] ?? 'Not specified'); ?></div>
            <div class="col-md-6"><strong>Created:</strong><br><?php echo htmlspecialchars($surgery['created_at'] ?? 'N/A'); ?></div>
        </div>

        <?php if (!empty($inventory_items)): ?>
        <div class="mb-3">
            <strong>Inventory Items Used:</strong>
            <div class="table-responsive mt-2">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Quantity Used</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($inventory_items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><?php echo htmlspecialchars($item['quantity_used']); ?></td>
                            <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php echo $archived_info; ?>
    </div>