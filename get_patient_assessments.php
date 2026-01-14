<?php
require 'config.php';
require 'functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit(json_encode(['error' => 'Unauthorized']));
}

$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if ($patient_id <= 0) {
    echo json_encode([]);
    exit;
}

$assessments = fetchAll($conn, 
    "SELECT id, assessment_type, status, philhealth_eligible, hmo_provider, 
            CONCAT('Assessment #', id, ' - ', assessment_type, ' (', status, ')') as display_name
     FROM financial_assessment 
     WHERE patient_id = ? AND status = 'Approved' AND is_archived = 0
     ORDER BY created_at DESC", 
    "i", 
    [$patient_id]
);

header('Content-Type: application/json');
echo json_encode($assessments ?: []);