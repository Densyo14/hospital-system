<?php
require_once 'config.php';

function fetchAll($conn, $sql, $types = null, $params = [])
{
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function fetchOne($conn, $sql, $types = null, $params = [])
{
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row;
}

function execute($conn, $sql, $types = null, $params = [])
{
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $ok = $stmt->execute();
    if ($ok) {
        $id = $stmt->insert_id;
        $stmt->close();
        return $id ?: true;
    } else {
        $err = $stmt->error;
        $stmt->close();
        return ['error' => $err];
    }
}

function h($s){ 
    return htmlspecialchars($s, ENT_QUOTES); 
}

/**
 * Generate a unique patient code using auto-increment ID
 * @param mysqli $conn Database connection
 * @param int|null $patient_id Existing patient ID (for updating)
 * @return string Generated patient code in format PYYYYXXXX
 */
function generate_patient_code($conn, $patient_id = null){
    $year = date('Y');
    $prefix = 'P' . $year;
    
    if ($patient_id) {
        // For existing patients, use their actual ID
        return $prefix . str_pad($patient_id, 4, '0', STR_PAD_LEFT);
    }
    
    // Get the next auto-increment value from information_schema
    $result = fetchOne($conn, 
        "SELECT AUTO_INCREMENT 
         FROM information_schema.TABLES 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = 'patients'", null, []);
    
    if ($result && isset($result['AUTO_INCREMENT'])) {
        $next_id = $result['AUTO_INCREMENT'];
        return $prefix . str_pad($next_id, 4, '0', STR_PAD_LEFT);
    }
    
    // Fallback: if we can't get auto_increment, use max ID + 1
    $max_result = fetchOne($conn, 
        "SELECT MAX(id) as max_id FROM patients", null, []);
    
    if ($max_result && isset($max_result['max_id'])) {
        $next_id = $max_result['max_id'] + 1;
    } else {
        $next_id = 1;
    }
    
    return $prefix . str_pad($next_id, 4, '0', STR_PAD_LEFT);
}

/**
 * Check if a patient code already exists (for validation)
 * @param mysqli $conn Database connection
 * @param string $patient_code Patient code to check
 * @param int|null $exclude_id Patient ID to exclude (for updates)
 * @return bool True if code exists, false otherwise
 */
function patient_code_exists($conn, $patient_code, $exclude_id = null) {
    $sql = "SELECT COUNT(*) as count FROM patients WHERE patient_code = ?";
    $params = [$patient_code];
    $types = "s";
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
        $types .= "i";
    }
    
    $result = fetchOne($conn, $sql, $types, $params);
    return ($result['count'] ?? 0) > 0;
}

/**
 * Archive a record with logging
 * @param mysqli $conn Database connection
 * @param string $table_name Table to archive from
 * @param int $record_id Record ID to archive
 * @param int $user_id User performing the archive
 * @param string $reason Reason for archiving
 * @return bool|array Success or error array
 */
function archive_record($conn, $table_name, $record_id, $user_id, $reason = '') {
    // First, check if the table has is_archived column
    $columns_result = fetchOne($conn, 
        "SHOW COLUMNS FROM $table_name LIKE 'is_archived'", null, []);
    
    if (!$columns_result) {
        return ['error' => "Table $table_name does not have is_archived column"];
    }
    
    // Archive the record
    $archive_sql = "UPDATE $table_name 
                    SET is_archived = 1, 
                        archived_at = NOW(), 
                        archived_by = ? 
                    WHERE id = ?";
    
    $archive_result = execute($conn, $archive_sql, "ii", [$user_id, $record_id]);
    
    if ($archive_result === true || is_numeric($archive_result)) {
        // Log the archive action
        $log_sql = "INSERT INTO archive_logs (table_name, record_id, archived_by, reason) 
                    VALUES (?, ?, ?, ?)";
        execute($conn, $log_sql, "siis", [$table_name, $record_id, $user_id, $reason]);
        
        return true;
    }
    
    return $archive_result;
}

/**
 * Restore an archived record
 * @param mysqli $conn Database connection
 * @param string $table_name Table to restore to
 * @param int $record_id Record ID to restore
 * @return bool|array Success or error array
 */
function restore_record($conn, $table_name, $record_id) {
    $sql = "UPDATE $table_name 
            SET is_archived = 0, 
                archived_at = NULL, 
                archived_by = NULL 
            WHERE id = ?";
    
    return execute($conn, $sql, "i", [$record_id]);
}

/**
 * Check user permissions based on role
 * @param string $current_role User's current role
 * @param string $required_role Required role or array of roles
 * @return bool True if user has permission
 */
function has_permission($current_role, $required_role) {
    if (is_array($required_role)) {
        return in_array($current_role, $required_role);
    }
    return $current_role === $required_role;
}

/**
 * Get role-specific navigation items
 * @param string $role User role
 * @return array Navigation items for the role
 */
function get_role_navigation($role) {
    $role_permissions = [
        'Admin' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'patients.php' => ['label' => 'Patients', 'icon' => '👥'],
            'appointments.php' => ['label' => 'Appointments', 'icon' => '📅'],
            'surgeries.php' => ['label' => 'Surgeries', 'icon' => '🔪'],
            'inventory.php' => ['label' => 'Inventory', 'icon' => '📦'],
            'billing.php' => ['label' => 'Billing', 'icon' => '💳'],
            'financials.php' => ['label' => 'Financial', 'icon' => '📊'],
            'users.php' => ['label' => 'Users', 'icon' => '⚙️']
        ],
        'Doctor' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'patients.php' => ['label' => 'Patients', 'icon' => '👥'],
            'appointments.php' => ['label' => 'Appointments', 'icon' => '📅'],
            'surgeries.php' => ['label' => 'Surgeries', 'icon' => '🔪'],
            'inventory.php' => ['label' => 'Inventory', 'icon' => '📦']
        ],
        'Nurse' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'patients.php' => ['label' => 'Patients', 'icon' => '👥'],
            'appointments.php' => ['label' => 'Appointments', 'icon' => '📅'],
            'inventory.php' => ['label' => 'Inventory', 'icon' => '📦']
        ],
        'Staff' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'patients.php' => ['label' => 'Patients', 'icon' => '👥'],
            'appointments.php' => ['label' => 'Appointments', 'icon' => '📅']
        ],
        'Inventory' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'inventory.php' => ['label' => 'Inventory', 'icon' => '📦']
        ],
        'Billing' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'billing.php' => ['label' => 'Billing', 'icon' => '💳'],
            'financials.php' => ['label' => 'Financial', 'icon' => '📊']
        ],
        'SocialWorker' => [
            'dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠'],
            'financials.php' => ['label' => 'Financial', 'icon' => '📊']
        ]
    ];
    
    return $role_permissions[$role] ?? ['dashboard.php' => ['label' => 'Dashboard', 'icon' => '🏠']];
}

/**
 * Sanitize input data
 * @param mixed $data Input data to sanitize
 * @return mixed Sanitized data
 */
function sanitize_input($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitize_input($value);
        }
        return $data;
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Format currency in Philippine Peso
 * @param float $amount Amount to format
 * @return string Formatted currency string
 */
function format_currency($amount) {
    return '₱ ' . number_format($amount, 2);
}

/**
 * Format date for display
 * @param string $date Date string
 * @param string $format Output format (default: 'Y-m-d')
 * @return string Formatted date
 */
function format_date($date, $format = 'Y-m-d') {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    return date($format, strtotime($date));
}

/**
 * Get status badge HTML
 * @param string $status Status value
 * @return string HTML badge
 */
function get_status_badge($status) {
    $badges = [
        'Pending' => '<span class="status pending">Pending</span>',
        'Approved' => '<span class="status paid">Approved</span>',
        'Completed' => '<span class="status paid">Completed</span>',
        'Cancelled' => '<span class="status failed">Cancelled</span>',
        'Paid' => '<span class="status paid">Paid</span>',
        'Unpaid' => '<span class="status pending">Unpaid</span>',
        'Scheduled' => '<span class="status pending">Scheduled</span>',
        'Rejected' => '<span class="status failed">Rejected</span>'
    ];
    
    return $badges[$status] ?? '<span class="status">' . h($status) . '</span>';
}

// NEW FUNCTIONS FOR DOCTOR-SPECIFIC PATIENT VIEW

/**
 * Get patient IDs for a specific doctor
 * @param mysqli $conn Database connection
 * @param int $doctor_id Doctor's user ID
 * @return array Array of patient IDs
 */
function getDoctorPatients($conn, $doctor_id) {
    // Get patient IDs from appointments where this doctor is assigned
    $appointment_patients = fetchAll($conn, "
        SELECT DISTINCT patient_id 
        FROM appointments 
        WHERE doctor_id = ? AND is_archived = 0
        UNION
        SELECT DISTINCT patient_id 
        FROM medical_records 
        WHERE doctor_id = ?
        UNION
        SELECT DISTINCT patient_id 
        FROM surgeries 
        WHERE doctor_id = ? AND is_archived = 0
    ", "iii", [$doctor_id, $doctor_id, $doctor_id]);
    
    $patient_ids = [];
    foreach ($appointment_patients as $row) {
        if ($row['patient_id']) {
            $patient_ids[] = $row['patient_id'];
        }
    }
    
    return array_unique($patient_ids);
}

/**
 * Check if doctor can access specific patient
 * @param mysqli $conn Database connection
 * @param int $doctor_id Doctor's user ID
 * @param int $patient_id Patient ID
 * @return bool True if doctor can access patient
 */
function canDoctorAccessPatient($conn, $doctor_id, $patient_id) {
    $result = fetchOne($conn, "
        SELECT 
            (EXISTS(SELECT 1 FROM appointments WHERE patient_id = ? AND doctor_id = ? AND is_archived = 0)) OR
            (EXISTS(SELECT 1 FROM medical_records WHERE patient_id = ? AND doctor_id = ?)) OR
            (EXISTS(SELECT 1 FROM surgeries WHERE patient_id = ? AND doctor_id = ? AND is_archived = 0))
        AS has_access
    ", "iiiiii", [$patient_id, $doctor_id, $patient_id, $doctor_id, $patient_id, $doctor_id]);
    
    return $result['has_access'] ?? false;
}

/**
 * Get doctor's assigned patients count
 * @param mysqli $conn Database connection
 * @param int $doctor_id Doctor's user ID
 * @return int Number of patients assigned to doctor
 */
function getDoctorPatientCount($conn, $doctor_id) {
    $result = fetchOne($conn, "
        SELECT COUNT(DISTINCT patient_id) as patient_count
        FROM (
            SELECT patient_id FROM appointments WHERE doctor_id = ? AND is_archived = 0
            UNION
            SELECT patient_id FROM medical_records WHERE doctor_id = ?
            UNION
            SELECT patient_id FROM surgeries WHERE doctor_id = ? AND is_archived = 0
        ) as doctor_patients
    ", "iii", [$doctor_id, $doctor_id, $doctor_id]);
    
    return $result['patient_count'] ?? 0;
}

/**
 * Get doctor's patient list with basic info
 * @param mysqli $conn Database connection
 * @param int $doctor_id Doctor's user ID
 * @return array List of patients with ID, name, and code
 */
function getDoctorPatientList($conn, $doctor_id) {
    $patients = fetchAll($conn, "
        SELECT DISTINCT p.id, p.patient_code, p.first_name, p.last_name
        FROM patients p
        WHERE p.id IN (
            SELECT patient_id FROM appointments WHERE doctor_id = ? AND is_archived = 0
            UNION
            SELECT patient_id FROM medical_records WHERE doctor_id = ?
            UNION
            SELECT patient_id FROM surgeries WHERE doctor_id = ? AND is_archived = 0
        )
        AND p.is_archived = 0
        ORDER BY p.last_name, p.first_name
    ", "iii", [$doctor_id, $doctor_id, $doctor_id]);
    
    return $patients;
}
?>