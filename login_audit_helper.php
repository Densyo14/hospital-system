<?php
/**
 * Login Audit Helper Functions
 * Include this file at the top of login.php to enable login tracking
 */

function getBrowserInfo() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    if (preg_match('/MSIE|Trident|Edge/i', $user_agent)) {
        return 'Internet Explorer';
    } elseif (preg_match('/Firefox/i', $user_agent)) {
        return 'Firefox';
    } elseif (preg_match('/Chrome/i', $user_agent)) {
        return 'Chrome';
    } elseif (preg_match('/Safari/i', $user_agent)) {
        return 'Safari';
    } elseif (preg_match('/Opera|OPR/i', $user_agent)) {
        return 'Opera';
    }
    
    return 'Unknown';
}

function getDeviceInfo() {
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    if (preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $user_agent)) {
        return 'Mobile Device';
    } elseif (preg_match('/Tablet|iPad/i', $user_agent)) {
        return 'Tablet';
    } else {
        return 'Desktop';
    }
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    return trim($ip);
}

function logLoginAttempt($conn, $username, $user_id = null, $status = 'Success', $reason = '') {
    try {
        $ip_address = getClientIP();
        $browser_info = getBrowserInfo();
        $device_info = getDeviceInfo();
        $login_time = date('Y-m-d H:i:s');
        
        $sql = "INSERT INTO login_audit (user_id, username, ip_address, login_time, status, reason, browser_info, device_info) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Login Audit: Prepare failed - " . $conn->error);
            return false;
        }
        
        $stmt->bind_param('isssssss', $user_id, $username, $ip_address, $login_time, $status, $reason, $browser_info, $device_info);
        
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            error_log("Login Audit: Execute failed - " . $stmt->error);
            $stmt->close();
            return false;
        }
    } catch (Exception $e) {
        error_log("Login Audit Error: " . $e->getMessage());
        return false;
    }
}

function logLogout($conn, $user_id) {
    try {
        if (!$user_id) return false;
        
        // Get the most recent login session for this user
        $sql = "UPDATE login_audit 
                SET logout_time = NOW(), 
                    session_duration = UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(login_time)
                WHERE user_id = ? AND logout_time IS NULL 
                ORDER BY login_time DESC LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Logout Audit: Prepare failed - " . $conn->error);
            return false;
        }
        
        $stmt->bind_param('i', $user_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            error_log("Logout Audit: Execute failed - " . $stmt->error);
            $stmt->close();
            return false;
        }
    } catch (Exception $e) {
        error_log("Logout Audit Error: " . $e->getMessage());
        return false;
    }
}

function isAccountLocked($conn, $username, $max_attempts = 5, $lockout_duration = 15) {
    // Check failed attempts in the last X minutes
    $sql = "SELECT COUNT(*) as attempt_count 
            FROM login_audit 
            WHERE username = ? 
            AND status = 'Failed' 
            AND login_time > DATE_SUB(NOW(), INTERVAL ? MINUTE)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    
    $stmt->bind_param('si', $username, $lockout_duration);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $stmt->close();
    
    return $row['attempt_count'] >= $max_attempts;
}
?>
