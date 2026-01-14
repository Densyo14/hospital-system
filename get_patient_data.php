<?php
session_start();
require 'config.php';
require 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get month and year from request
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month
if ($month < 1 || $month > 12) {
    $month = date('m');
}

// Month names
$month_names = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get all patients for the selected month
$all_patients_query = "
    SELECT 
        p.id,
        p.patient_code,
        p.first_name,
        p.last_name,
        p.sex,
        p.birth_date,
        p.phone,
        p.guardian_name,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
    FROM patients p
    WHERE YEAR(p.created_at) = ? 
    AND MONTH(p.created_at) = ?
    AND p.is_archived = 0
    ORDER BY p.created_at DESC
";

$all_patients = fetchAll($conn, $all_patients_query, 'ii', [$year, $month]);

// Get surgical patients for the selected month
$surgical_patients_query = "
    SELECT 
        p.id,
        p.patient_code,
        p.first_name,
        p.last_name,
        p.sex,
        p.birth_date,
        p.phone,
        p.guardian_name,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age,
        s.surgery_type,
        s.schedule_date as surgery_date,
        s.status as surgery_status,
        u.full_name as doctor_name
    FROM patients p
    INNER JOIN surgeries s ON p.id = s.patient_id
    LEFT JOIN users u ON s.doctor_id = u.id
    WHERE YEAR(p.created_at) = ? 
    AND MONTH(p.created_at) = ?
    AND p.is_archived = 0
    AND s.is_archived = 0
    ORDER BY s.schedule_date DESC
";

$surgical_patients = fetchAll($conn, $surgical_patients_query, 'ii', [$year, $month]);

// Format dates and calculate age
foreach ($all_patients as &$patient) {
    if ($patient['birth_date']) {
        $birthDate = new DateTime($patient['birth_date']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        $patient['age'] = $age;
    }
    
    if ($patient['birth_date']) {
        $patient['birth_date'] = date('M d, Y', strtotime($patient['birth_date']));
    }
}

foreach ($surgical_patients as &$patient) {
    if ($patient['birth_date']) {
        $birthDate = new DateTime($patient['birth_date']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        $patient['age'] = $age;
    }
    
    if ($patient['birth_date']) {
        $patient['birth_date'] = date('M d, Y', strtotime($patient['birth_date']));
    }
    
    if ($patient['surgery_date']) {
        $patient['surgery_date'] = date('M d, Y', strtotime($patient['surgery_date']));
    }
}

// Prepare response
$response = [
    'month_name' => $month_names[$month],
    'month' => $month,
    'year' => $year,
    'total_count' => count($all_patients),
    'surgical_count' => count($surgical_patients),
    'all_patients' => $all_patients,
    'surgical_patients' => $surgical_patients
];

header('Content-Type: application/json');
echo json_encode($response);
?>