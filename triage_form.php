<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Only Nurses, Doctors, Staff, and Admin can perform triage
$allowed_roles = ['Nurse', 'Doctor', 'Staff', 'Admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    die("Access denied.");
}

$current_role = $_SESSION['role'] ?? 'Guest';
$current_name = $_SESSION['name'] ?? 'User';
$current_user_id = $_SESSION['user_id'];

// Role permissions for sidebar (same as patients.php)
$role_permissions = [
    'Admin' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'billing.php' => 'Billing',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports',
        'users.php' => 'Users',
        'triage_queue.php' => 'Triage Queue'
    ],
    'Doctor' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'surgeries.php' => 'Surgeries',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports',
        'triage_queue.php' => 'Triage Queue'
    ],
    'Nurse' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports',
        'triage_queue.php' => 'Triage Queue'
    ],
    'Staff' => [
        'dashboard.php' => 'Dashboard',
        'patients.php' => 'Patients',
        'appointments.php' => 'Appointments',
        'reports.php' => 'Reports',
        'triage_queue.php' => 'Triage Queue'   // <-- ADDED
    ],
    'Inventory' => [
        'dashboard.php' => 'Dashboard',
        'inventory.php' => 'Inventory',
        'reports.php' => 'Reports'
    ],
    'Billing' => [
        'dashboard.php' => 'Dashboard',
        'billing.php' => 'Billing',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'
    ],
    'SocialWorker' => [
        'dashboard.php' => 'Dashboard',
        'financials.php' => 'Financial',
        'reports.php' => 'Reports'
    ]
];

$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Get patient ID from URL
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if (!$patient_id) {
    header("Location: patients.php?error=No patient selected");
    exit();
}

// Fetch patient details
$patient = fetchOne($conn, "SELECT id, first_name, last_name, patient_code FROM patients WHERE id = ?", "i", [$patient_id]);
if (!$patient) {
    header("Location: patients.php?error=Patient not found");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $severity = (int)($_POST['severity'] ?? 3);
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $bp = trim($_POST['blood_pressure'] ?? '');
    $hr = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
    $temp = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
    $o2 = !empty($_POST['oxygen_saturation']) ? (int)$_POST['oxygen_saturation'] : null;
    $pain = !empty($_POST['pain_level']) ? (int)$_POST['pain_level'] : null;
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (empty($chief_complaint)) {
        $errors[] = "Chief complaint is required.";
    }

    if (empty($errors)) {
        // Generate queue number: Q-YYYYMMDD-XXX
        $date = date('Ymd');
        $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM triage WHERE DATE(assessed_at) = CURDATE()");
        $row = mysqli_fetch_assoc($result);
        $seq = str_pad($row['cnt'] + 1, 3, '0', STR_PAD_LEFT);
        $queue_number = "Q-{$date}-{$seq}";

        $sql = "INSERT INTO triage (patient_id, assessed_by, severity, chief_complaint, blood_pressure, heart_rate, temperature, oxygen_saturation, pain_level, queue_number, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [$patient_id, $current_user_id, $severity, $chief_complaint, $bp, $hr, $temp, $o2, $pain, $queue_number, $notes];
        $types = "iiisssiiiss"; // adjust types as needed

        if (execute($conn, $sql, $types, $params)) {
            header("Location: triage_queue.php?success=added&queue=" . urlencode($queue_number));
            exit();
        } else {
            $error = "Failed to save triage. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Triage Assessment</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg: #eef3f7;
    --panel: #ffffff;
    --muted: #6b7280;
    --navy-700: #001F3F;
    --accent: #003366;
    --sidebar: #002855;
    --light-blue: #4d8cc9;
    --card-shadow: 0 6px 22px rgba(16,24,40,0.06);
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0;
    font-family:Inter, sans-serif;
    background:var(--bg);
    color:#0f1724;
  }
  .app { display:flex; min-height:100vh; }

  /* SIDEBAR */
  .sidebar {
    width:230px;
    background:linear-gradient(180deg, var(--sidebar), #001a33 120%);
    color:#eaf5ff;
    padding:18px 15px;
    display:flex;
    flex-direction:column;
    gap:14px;
    position:fixed;
    left:0;
    top:0;
    bottom:0;
    overflow-y:auto;
    z-index:30;
  }
  .sidebar::-webkit-scrollbar { width:4px; }
  .sidebar::-webkit-scrollbar-track { background:rgba(255,255,255,0.1); }
  .sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.3); border-radius:2px; }
  .logo-wrap{ display:flex; justify-content:center; }
  .logo-wrap img{ width:150px; height:auto; }
  .user-info{
    background:rgba(255,255,255,0.1);
    border-radius:8px;
    padding:10px;
    border-left:3px solid #9bcfff;
    font-size:13px;
  }
  .user-info h4{ margin:0 0 4px 0; color:#9bcfff; font-size:13px; }
  .user-info p{ margin:0; font-size:12px; color:rgba(255,255,255,0.9); }
  .menu{ margin-top:8px; display:flex; flex-direction:column; gap:6px; }
  .menu-item{
    display:flex;
    align-items:center;
    gap:10px;
    padding:9px 7px;
    border-radius:8px;
    color:rgba(255,255,255,0.95);
    font-weight:500;
    text-decoration:none;
    font-size:14px;
    transition:all 0.2s;
  }
  .menu-item:hover{ background:linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04)); }
  .menu-item.active{
    background:linear-gradient(90deg, rgba(255,255,255,0.08), rgba(255,255,255,0.04));
    border-left:4px solid #9bcfff;
    padding-left:5px;
  }
  .menu-item .icon{ width:16px; height:16px; fill:white; }
  .sidebar-bottom{ margin-top:auto; padding-top:15px; border-top:1px solid rgba(255,255,255,0.1); }

  /* MAIN */
  .main{ margin-left:230px; padding:18px 28px; width:100%; }
  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:8px;
  }
  .top-left h1{ font-size:22px; margin:0; font-weight:700; }
  .top-left p{ margin:6px 0 0; color:var(--muted); font-size:13px; }
  .top-actions{ display:flex; align-items:center; gap:12px; }
  .btn{
    background:var(--navy-700);
    color:#fff;
    padding:9px 14px;
    border-radius:10px;
    border:0;
    font-weight:600;
    text-decoration:none;
    display:inline-block;
    transition:all 0.2s;
    font-size:13px;
  }
  .btn:hover{ background:var(--accent); transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,31,63,0.2); }
  .btn-outline {
    background:transparent;
    color:var(--navy-700);
    border:1px solid var(--navy-700);
  }
  .btn-outline:hover {
    background:rgba(0,31,63,0.1);
    color:var(--navy-700);
  }
  .btn-secondary { background:#6c757d; color:#fff; }
  .btn-secondary:hover { background:#5a6268; transform:translateY(-1px); }
  .date-pill{
    background:var(--panel);
    padding:8px 12px;
    border-radius:999px;
    box-shadow:0 4px 14px rgba(16,24,40,0.06);
    font-size:13px;
    white-space:nowrap;
    border:1px solid #e6eef0;
  }
  .role-badge{
    display:inline-block;
    padding:4px 12px;
    border-radius:15px;
    font-size:0.8rem;
    font-weight:bold;
    margin-left:10px;
  }
  .role-admin { background:#001F3F; color:white; }
  .role-doctor { background:#003366; color:white; }
  .role-nurse { background:#4d8cc9; color:white; }
  .role-staff { background:#6b7280; color:white; }
  .role-inventory { background:#1e6b8a; color:white; }
  .role-billing { background:#0066cc; color:white; }
  .role-socialworker { background:#34495e; color:white; }

  /* Form Card */
  .form-card{
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    max-width:700px;
    border:1px solid #f0f4f8;
  }
  .form-group{
    margin-bottom:20px;
  }
  .form-label{
    display:block;
    margin-bottom:8px;
    font-weight:600;
    color:#1e3a5f;
  }
  .form-label.required:after{
    content:' *';
    color:#e53e3e;
  }
  .form-control, .form-textarea, .form-select{
    width:100%;
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    font-family:Inter, sans-serif;
    font-size:14px;
    background:#fff;
  }
  .form-control:focus, .form-textarea:focus, .form-select:focus{
    outline:none;
    border-color:var(--navy-700);
    box-shadow:0 0 0 2px rgba(0,31,63,0.1);
  }
  .form-textarea{ min-height:100px; resize:vertical; }
  .patient-info{
    background:#f8fbfd;
    padding:15px;
    border-radius:8px;
    margin-bottom:20px;
    border-left:4px solid var(--navy-700);
  }
  .form-actions{
    display:flex;
    gap:12px;
    margin-top:30px;
    padding-top:20px;
    border-top:1px solid #f0f3f4;
  }
  .alert{
    padding:12px 16px;
    border-radius:8px;
    margin-bottom:10px;
    color:white;
    font-weight:500;
  }
  .alert-error{ background:#e53e3e; border-left:4px solid #c53030; }
  @media (max-width:780px){
    .sidebar{ left:-320px; }
    .sidebar.open{ left:0; }
    .main{ margin-left:0; padding:12px; }
    .form-actions{ flex-direction:column; }
    .btn{ width:100%; text-align:center; }
  }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo-wrap"><a href="dashboard.php"><img src="logo.jpg" alt="Logo"></a></div>
    <div class="user-info">
      <h4>Logged as:</h4>
      <p><?= htmlspecialchars($current_name) ?><br><strong><?= htmlspecialchars($current_role) ?></strong></p>
    </div>
    <nav class="menu">
      <a href="dashboard.php" class="menu-item <?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'' ?>">
        <span class="icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></span> Dashboard
      </a>
      <?php foreach($allowed_pages as $page => $label): if($page !== 'dashboard.php'): ?>
        <a href="<?= $page ?>" class="menu-item <?= basename($_SERVER['PHP_SELF'])==$page?'active':'' ?>">
          <span class="icon">
            <?php
            $icons = [
              'patients.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>',
              'appointments.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>',
              'surgeries.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
              'inventory.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 8h-3V4H7v4H4v14h16V8zM9 6h6v2H9V6zm11 14H4v-9h16v9zm-7-7H8v-2h5v2z"/></svg>',
              'billing.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>',
              'financials.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
              'reports.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>',
              'users.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>',
              'triage_queue.php' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>'
            ];
            echo $icons[$page] ?? '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>';
            ?>
          </span> <?= $label ?>
        </a>
      <?php endif; endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
      <a href="logout.php" class="menu-item">
        <span class="icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg></span> Logout
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="top-left">
        <h1>Triage Assessment
          <span class="role-badge role-<?= strtolower($current_role) ?>"><?= htmlspecialchars($current_role) ?> View</span>
        </h1>
        <p>Assess patient severity and queue them for consultation</p>
      </div>
      <div class="top-actions">
        <a href="patients.php" class="btn btn-outline">&larr; Back to Patients</a>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div class="form-card">
      <div class="patient-info">
        <strong>Patient:</strong> <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>
        (<?= htmlspecialchars($patient['patient_code']) ?>)
      </div>

      <?php if (isset($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label required">Severity (1=Low, 5=Critical)</label>
          <select name="severity" class="form-select" required>
            <option value="1">1 - Low (e.g., mild headache)</option>
            <option value="2">2 - Moderate</option>
            <option value="3" selected>3 - Urgent</option>
            <option value="4">4 - Severe</option>
            <option value="5">5 - Critical (life-threatening)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label required">Chief Complaint / Initial Findings</label>
          <textarea name="chief_complaint" class="form-textarea" rows="3" required placeholder="e.g., fever, cough, chest pain"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Blood Pressure</label>
          <input type="text" name="blood_pressure" class="form-control" placeholder="120/80">
        </div>

        <div class="form-group">
          <label class="form-label">Heart Rate (bpm)</label>
          <input type="number" name="heart_rate" class="form-control" placeholder="72">
        </div>

        <div class="form-group">
          <label class="form-label">Temperature (°C)</label>
          <input type="number" step="0.1" name="temperature" class="form-control" placeholder="36.5">
        </div>

        <div class="form-group">
          <label class="form-label">Oxygen Saturation (%)</label>
          <input type="number" name="oxygen_saturation" class="form-control" placeholder="98">
        </div>

        <div class="form-group">
          <label class="form-label">Pain Level (0-10)</label>
          <input type="number" min="0" max="10" name="pain_level" class="form-control" placeholder="5">
        </div>

        <div class="form-group">
          <label class="form-label">Additional Notes</label>
          <textarea name="notes" class="form-textarea" rows="2"></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn">Submit Triage</button>
          <a href="patients.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// (Optional) You can add client-side validation here if needed
</script>
</body>
</html>