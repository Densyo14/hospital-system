<?php
session_start();

// If user is logged in, update their logout time in the audit log
if (isset($_SESSION['user_id'])) {
    require 'config.php';  // Include database connection

    $user_id = $_SESSION['user_id'];

    // Update the most recent login record for this user that hasn't been logged out yet
    $update_sql = "UPDATE login_audit 
                   SET logout_time = NOW() 
                   WHERE user_id = ? AND logout_time IS NULL 
                   ORDER BY login_time DESC LIMIT 1";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Destroy the session
session_unset();
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>