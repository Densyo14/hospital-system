<?php
$page_title = "Settings";
require 'header.php';
?>

<h2>System Settings</h2>

<div class="table-container">
    <h3>User Management</h3>

    <p>This section will allow the admin to manage hospital staff accounts:</p>

    <ul style="margin-left:20px; margin-top:10px;">
        <li>Add new users (Doctors, Nurses, Billing, Inventory, etc.)</li>
        <li>Edit user information</li>
        <li>Reset passwords</li>
        <li>Enable/Disable accounts</li>
    </ul>

    <hr style="margin:20px 0;">

    <h3>System Configuration</h3>

    <ul style="margin-left:20px; margin-top:10px;">
        <li>Upload/Edit Hospital Logo</li>
        <li>Backup & Restore Database (optional)</li>
        <li>Modify system preferences</li>
    </ul>

    <p style="margin-top:20px; color:#777;">
        More settings options can be added here as needed.
    </p>
</div>

<?php require 'footer.php'; ?>
