<?php
session_start();
require 'config.php';
require 'login_audit_helper.php';

$error = "";
$username = "";

// Process login
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'];  // Get client IP address

    if (empty($username) || empty($password)) {
        $error = "Please enter your username/email and password.";
        // Optionally log empty submission? Usually not.
    } else {

        $stmt = $conn->prepare("SELECT id, full_name, username, password, role, is_active 
                                FROM users 
                                WHERE username = ? OR username LIKE ? LIMIT 1");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();

            if (!$row['is_active']) {
                $error = "Your account is deactivated. Contact admin.";
                // Log failed attempt (deactivated account)
                logLoginAttempt($conn, $username, null, 'Failed');
            } else if (password_verify($password, $row['password'])) {

                // Login successful
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['name'] = $row['full_name'];
                $_SESSION['username'] = $row['username'];

                // Log successful login
                logLoginAttempt($conn, $row['username'], $row['id'], 'Success');

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Incorrect password.";
                // Log failed attempt (wrong password)
                logLoginAttempt($conn, $username, null, 'Failed');
            }
        } else {
            $error = "Account not found.";
            // Log failed attempt (user not found)
            logLoginAttempt($conn, $username, null, 'Failed');
        }

        $stmt->close();
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Seamen's Hospital – Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
/* (Your existing CSS – unchanged) */
*{
  margin:0;
  padding:0;
  box-sizing:border-box;
  font-family:"Segoe UI", sans-serif;
}

body{
  display:flex;
  justify-content:center;
  align-items:center;
  min-height:100vh;
  overflow:hidden;
  background:#000;
}

.background{
  position:fixed;
  inset:0;
  background:url("background_logo.jpg") center/cover no-repeat;
  filter:brightness(0.65);
  z-index:-1;
}

.login-container{
  width:420px;
  padding:40px 35px;
  text-align:center;
  backdrop-filter:blur(22px);
  -webkit-backdrop-filter:blur(22px);
  background:rgba(255,255,255,0.10);
  border-radius:25px;
  box-shadow:0 10px 40px rgba(0,0,0,0.35);
  border:1px solid rgba(255,255,255,0.1);
}

.logo{
  width:75px;
  margin-bottom:10px;
}

h2{
  color:#fff;
  font-size:26px;
  font-weight:700;
  margin-bottom:30px;
}

.error{
  background:rgba(255,75,75,0.15);
  color:#ffdcdc;
  padding:12px;
  border-radius:10px;
  margin-bottom:18px;
  font-size:14px;
}

input{
  width:100%;
  padding:14px;
  border:none;
  border-radius:12px;
  margin-bottom:12px;
  font-size:15px;
  background:rgba(255,255,255,0.95);
}
input:focus{
  outline:none;
  box-shadow:0 0 0 3px rgba(0, 40, 85, 0.4);
}

.login-btn{
  width:100%;
  padding:14px;
  border:none;
  border-radius:12px;
  font-weight:600;
  cursor:pointer;
  color:#fff;
  font-size:16px;
  background:linear-gradient(90deg, #001F3F, #003366, #002855);
  transition:all 0.3s ease;
}
.login-btn:hover{
  transform:translateY(-2px);
  box-shadow:0 8px 22px rgba(0, 40, 85, 0.4);
  background:linear-gradient(90deg, #002855, #004080, #003366);
}

.forgot{
  margin-top:14px;
}
.forgot a{
  color:#a3cfff;
  font-size:14px;
  text-decoration:none;
}
.forgot a:hover{
  text-decoration:underline;
  color:#c2e0ff;
}

.footer{
  margin-top:20px;
  font-size:12px;
  color:rgba(255,255,255,0.7);
}

</style>
</head>

<body>

<div class="background"></div>

<div class="login-container">

    <img src="logo.jpg" class="logo" alt="CURE Logo">

    <h2>Welcome to <br>Gig Oca Robles Seamen's Hospital Davao</h2>

    <?php if(!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="username" placeholder="Username or Email"
            value="<?php echo htmlspecialchars($username); ?>" required>

        <input type="password" name="password" placeholder="Password" required>

        <button class="login-btn" type="submit">Login</button>
    </form>
       
    <div class="footer">
        <a style="color: rgba(255,255,255,0.7);">GIG Oca Robles Seamen's Hospital. All rights reserved.</a>
    </div>

</div>

</body>
</html>