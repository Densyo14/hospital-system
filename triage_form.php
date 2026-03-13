<?php
session_start();
require 'config.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$allowed_roles = ['Nurse', 'Doctor', 'Admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    die("Access denied.");
}

$patient_id = $_GET['patient_id'] ?? 0;
if (!$patient_id) {
    die("No patient selected.");
}

$patient = fetchOne($conn, "SELECT id, first_name, last_name, patient_code FROM patients WHERE id = ?", "i", [$patient_id]);
if (!$patient) {
    die("Patient not found.");
}

// Define role permissions for navigation (same as patients.php)
$role_permissions = [
    'Admin' => [ 'dashboard.php' => 'Dashboard', 'patients.php' => 'Patients', 'appointments.php' => 'Appointments', 'surgeries.php' => 'Surgeries', 'inventory.php' => 'Inventory', 'billing.php' => 'Billing', 'financials.php' => 'Financial', 'reports.php' => 'Reports', 'users.php' => 'Users', 'triage_queue.php' => 'Triage Queue' ],
    'Doctor' => [ 'dashboard.php' => 'Dashboard', 'patients.php' => 'Patients', 'appointments.php' => 'Appointments', 'surgeries.php' => 'Surgeries', 'inventory.php' => 'Inventory', 'reports.php' => 'Reports', 'triage_queue.php' => 'Triage Queue' ],
    'Nurse' => [ 'dashboard.php' => 'Dashboard', 'patients.php' => 'Patients', 'appointments.php' => 'Appointments', 'inventory.php' => 'Inventory', 'reports.php' => 'Reports', 'triage_queue.php' => 'Triage Queue' ],
    'Staff' => [ 'dashboard.php' => 'Dashboard', 'patients.php' => 'Patients', 'appointments.php' => 'Appointments', 'reports.php' => 'Reports' ],
    'Inventory' => [ 'dashboard.php' => 'Dashboard', 'inventory.php' => 'Inventory', 'reports.php' => 'Reports' ],
    'Billing' => [ 'dashboard.php' => 'Dashboard', 'billing.php' => 'Billing', 'financials.php' => 'Financial', 'reports.php' => 'Reports' ],
    'SocialWorker' => [ 'dashboard.php' => 'Dashboard', 'financials.php' => 'Financial', 'reports.php' => 'Reports' ]
];
$current_role = $_SESSION['role'] ?? 'Guest';
$current_name = $_SESSION['name'] ?? 'User';
$allowed_pages = $role_permissions[$current_role] ?? ['dashboard.php' => 'Dashboard'];

// Initialize form values
$severity = 3;
$chief_complaint = '';
$bp = '';
$hr = '';
$temp = '';
$o2 = '';
$pain = '';
$notes = '';

// Handle form submission (same as before)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $severity = (int)($_POST['severity'] ?? 3);
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $bp = trim($_POST['blood_pressure'] ?? '');
    $hr = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
    $temp = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
    $o2 = !empty($_POST['oxygen_saturation']) ? (int)$_POST['oxygen_saturation'] : null;
    $pain = !empty($_POST['pain_level']) ? (int)$_POST['pain_level'] : null;
    $notes = trim($_POST['notes'] ?? '');

    // Generate queue number: Q-YYYYMMDD-XXX
    $date = date('Ymd');
    $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM triage WHERE DATE(assessed_at) = CURDATE()");
    $row = mysqli_fetch_assoc($result);
    $seq = str_pad($row['cnt'] + 1, 3, '0', STR_PAD_LEFT);
    $queue_number = "Q-{$date}-{$seq}";

    // Insert triage record
    $sql = "INSERT INTO triage (patient_id, assessed_by, severity, chief_complaint, blood_pressure, heart_rate, temperature, oxygen_saturation, pain_level, queue_number, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $params = [$patient_id, $_SESSION['user_id'], $severity, $chief_complaint, $bp, $hr, $temp, $o2, $pain, $queue_number, $notes];
    $types = "iiisssiiiss"; // adjust based on actual types: i=int, s=string, d=double

    if (execute($conn, $sql, $types, $params)) {
        header("Location: triage_queue.php?success=added&queue=" . urlencode($queue_number));
        exit();
    } else {
        $error = "Failed to save triage. Please try again.";
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
  body{
    margin:0;
    font-family:Inter, sans-serif;
    background:var(--bg);
    color:#0f1724;
  }
  .app { display:flex; min-height:100vh; }
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

  .form-card{
    background:var(--panel);
    padding:24px;
    border-radius:12px;
    box-shadow:var(--card-shadow);
    margin-top:20px;
    max-width:800px;
    border:1px solid #f0f4f8;
  }
  .form-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));
    gap:20px;
    margin-bottom:20px;
  }
  .form-group{
    display:flex;
    flex-direction:column;
    gap:8px;
  }
  .form-label{
    font-size:14px;
    color:#1e3a5f;
    font-weight:600;
  }
  .form-control{
    padding:10px 12px;
    border:1px solid #e6eef0;
    border-radius:8px;
    font-size:14px;
    background:#f8fbfd;
  }
  .form-control:focus{
    outline:none;
    border-color:var(--light-blue);
    box-shadow:0 0 0 3px rgba(77,140,201,0.1);
    background:white;
  }
  .form-text{ font-size:12px; color:var(--muted); }
  .btn-submit{
    background:var(--navy-700);
    color:white;
    border:none;
    padding:10px 20px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
  }
  .btn-submit:hover{ background:var(--accent); transform:translateY(-1px); }
  .btn-cancel{
    background:#6b7280;
    color:white;
    text-decoration:none;
    padding:10px 20px;
    border-radius:8px;
    font-weight:600;
  }
  .btn-cancel:hover{ background:#4b5563; }
  .alert {
    padding:12px 14px;
    border-radius:10px;
    margin-bottom:16px;
    border:1px solid rgba(231, 76, 60, 0.3);
    background:#ffe9e7;
    color:#991f1b;
  }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR (same as patients.php) -->
  <aside class="sidebar">
    <div class="logo-wrap"><a href="dashboard.php"><img src="logo.jpg" alt="Logo"></a></div>
    <div class="user-info">
      <h4>Logged as:</h4>
      <p><?= htmlspecialchars($current_name) ?><br><strong><?= htmlspecialchars($current_role) ?></strong></p>
    </div>
    <nav class="menu">
      <a href="dashboard.php" class="menu-item <?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'' ?>">Dashboard</a>
      <?php foreach($allowed_pages as $page => $label): ?>
        <?php if($page !== 'dashboard.php'): ?>
          <a href="<?= $page ?>" class="menu-item <?= basename($_SERVER['PHP_SELF'])==$page?'active':'' ?>"><?= $label ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
      <a href="logout.php" class="menu-item">Logout</a>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="topbar">
      <div class="top-left">
        <h1>Triage Assessment</h1>
        <p>Patient: <?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?> (<?= htmlspecialchars($patient['patient_code']) ?>)</p>
      </div>
      <div class="top-actions">
        <a href="patients.php" class="btn btn-outline">&larr; Back to Patients</a>
        <div class="date-pill"><?= date('l, jS F Y') ?></div>
      </div>
    </div>

    <div class="form-card">
      <?php if (isset($error)): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Severity (1=Low, 5=Critical) *</label>
            <select name="severity" class="form-control" required>
              <option value="1" <?= $severity===1 ? 'selected' : '' ?>>1 - Low</option>
              <option value="2" <?= $severity===2 ? 'selected' : '' ?>>2 - Moderate</option>
              <option value="3" <?= $severity===3 ? 'selected' : '' ?>>3 - Urgent</option>
              <option value="4" <?= $severity===4 ? 'selected' : '' ?>>4 - Severe</option>
              <option value="5" <?= $severity===5 ? 'selected' : '' ?>>5 - Critical</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Chief Complaint / Initial Findings *</label>
            <textarea name="chief_complaint" class="form-control" rows="3" required><?= htmlspecialchars($chief_complaint) ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Blood Pressure</label>
            <input type="text" name="blood_pressure" class="form-control" placeholder="120/80" value="<?= htmlspecialchars($bp) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Heart Rate (bpm)</label>
            <input type="number" name="heart_rate" class="form-control" value="<?= htmlspecialchars($hr) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Temperature (C)</label>
            <input type="number" step="0.1" name="temperature" class="form-control" value="<?= htmlspecialchars($temp) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Respiratory Rate</label>
            <input type="number" name="oxygen_saturation" class="form-control" value="<?= htmlspecialchars($o2) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Pain Level (0-10)</label>
            <input type="number" min="0" max="10" name="pain_level" class="form-control" value="<?= htmlspecialchars($pain) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($notes) ?></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-submit">Submit Triage</button>
          <a href="patients.php" class="btn-cancel">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
