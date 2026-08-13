<?php
// 1. Session gubbaatti qofa eegala
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include("includes/config.php");

// Yoo session yeroofii (Temp) hin jirre gara index deebisi
if(!isset($_SESSION['temp_user_id']) || !isset($_SESSION['temp_role'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['temp_user_id'];
$role = $_SESSION['temp_role'];
$username = $_SESSION['temp_username'];
$ip_address = $_SERVER['REMOTE_ADDR'];

$message = "";
$msg_type = "error";

if(isset($_POST['verify_otp'])) {
    $entered_otp = mysqli_real_escape_string($conn, trim($_POST['otp_code']));
    
    // OTP Database irraa fiduu
    $query = mysqli_query($conn, "SELECT * FROM otp_requests WHERE user_id=$user_id AND role='$role' ORDER BY id DESC LIMIT 1");
    
    if(mysqli_num_rows($query) > 0) {
        $otp_data = mysqli_fetch_assoc($query);
        $attempts = $otp_data['attempts'];
        
        // A. Yeroon darbeeraa? (Expired)
        if(strtotime($otp_data['expires_at']) < time()) {
            $message = "⏳ OTP has expired! Please return to login and request a new one.";
        } 
        // B. Koodiin sirriidhaa? (SUCCESS)
        elseif($otp_data['otp_code'] === $entered_otp) {
            
            // Session-oota dhaabbataa qabsiisuu
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = $role;
            
            // Re-assign role sessions (College/Dept ID)
            if(isset($_SESSION['temp_college_id'])) {
                $_SESSION['college_id'] = $_SESSION['temp_college_id'];
                unset($_SESSION['temp_college_id']);
            }
            if(isset($_SESSION['temp_dept_id'])) {
                $_SESSION['dept_id'] = $_SESSION['temp_dept_id'];
                unset($_SESSION['temp_dept_id']);
            }

            // OTP Haquu
            mysqli_query($conn, "DELETE FROM otp_requests WHERE id={$otp_data['id']}");
            
            // Log Success
            $agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);
            mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$username', '$ip_address', '$agent', 'success')");
            
            // Clear temp sessions
            unset($_SESSION['temp_user_id']); unset($_SESSION['temp_role']); unset($_SESSION['temp_username']);
            
            // 🪄 DYNAMIC REDIRECT
            if($role == 'super_admin') {
                header("Location: super_admin/dashboard.php");
            } else if($role == 'admin') {
                header("Location: admin/dashboard.php");
            } else if($role == 'head') {
                header("Location: head/dashboard.php");
            } else if($role == 'teacher') {
                header("Location: teacher/dashboard.php");
            } else if($role == 'student') {
                header("Location: student/dashboard.php");
            }
            exit();
        } 
        // C. Koodiin Dogongora (Attempts & Blocking)
        else {
            $attempts++;
            mysqli_query($conn, "UPDATE otp_requests SET attempts=$attempts WHERE id={$otp_data['id']}");
            
            if($attempts >= 3) {
                // 🚨 IP CUFUU (24 Hours)
                $reason = "Brute force: 3 Failed OTP attempts for account: " . $username;
                $expires_ban = date("Y-m-d H:i:s", strtotime('+24 hours'));
                mysqli_query($conn, "INSERT INTO blocked_ips (ip_address, ban_reason, expires_at) VALUES ('$ip_address', '$reason', '$expires_ban') ON DUPLICATE KEY UPDATE expires_at='$expires_ban'");
                
                 mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$username', '$ip_address', '{$_SERVER['HTTP_USER_AGENT']}', 'blocked')");                
                session_destroy();
                echo "<script>alert('🚨 SECURITY ALERT: Maximum attempts reached. Your IP is blocked for 24 hours!'); window.location.href='index.php';</script>";
                exit();
            } else {
                $rem = 3 - $attempts;
                $message = "❌ Incorrect OTP! You have $rem attempt(s) left.";
            }
        }
    } else {
        $message = "Invalid request. Please return to login page.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EPLMS - Security Verification</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #0b0e14; color: #fff; display: flex; justify-content: center; align-items: center; height: 100vh; overflow: hidden; }
        .otp-container { background: #181a20; padding: 40px; border-radius: 20px; text-align: center; max-width: 450px; width: 90%; border: 1px solid #2b3139; box-shadow: 0 15px 35px rgba(0,0,0,0.5); position: relative; }
        .otp-container::before { content:''; position:absolute; top:-50px; left:50%; transform:translateX(-50%); width:100px; height:100px; background:#fcd535; border-radius:50%; filter:blur(50px); z-index:-1; opacity:0.2;}
        h2 { font-size: 24px; margin-bottom: 10px; }
        p { color: #848e9c; font-size: 14px; margin-bottom: 30px; line-height: 1.5; }
        .otp-input { width: 100%; text-align: center; letter-spacing: 15px; font-size: 28px; font-weight: 800; padding: 15px; border-radius: 12px; background: #0b0e14; border: 2px solid #2b3139; color: #fcd535; outline: none; transition: 0.3s; }
        .otp-input:focus { border-color: #fcd535; }
        .btn { width: 100%; padding: 16px; background: #fcd535; color: #000; font-size: 16px; font-weight: bold; border: none; border-radius: 10px; cursor: pointer; margin-bottom: 20px; transition: 0.3s; }
        .btn:hover { background: #e5c02a; transform: translateY(-2px); }
        .timer-box { font-size: 18px; font-weight: 800; color: #0ecb81; display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 20px;}
        .timer-box.warning { color: #f6465d; }
        .alert { background: rgba(246, 70, 93, 0.1); color: #f6465d; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; border: 1px solid rgba(246, 70, 93, 0.3); font-weight: 600;}
    </style>
</head>
<body>
    <div class="otp-container">
        <i class="fa-solid fa-shield-halved" style="font-size: 50px; color: #fcd535; margin-bottom: 20px;"></i>
        <h2>2-Step Verification</h2>
        <p>A 6-digit secure code was sent to your email. Enter it below to unlock your dashboard.</p>
        
        <?php if($message): ?>
            <div class="alert"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $message; ?></div>
        <?php endif; ?>

        <div class="timer-box" id="countdown-timer">
            <i class="fa-regular fa-clock"></i> <span id="time">02:00</span>
        </div>

        <form method="POST">
            <div style="margin-bottom: 25px;">
                <input type="text" name="otp_code" class="otp-input" maxlength="6" placeholder="------" required autocomplete="off" autofocus>
            </div>
            <button type="submit" name="verify_otp" class="btn" id="verifyBtn">Verify & Secure Login</button>
        </form>
        <a href="index.php" style="color:#848e9c; text-decoration:none; font-size:13px;"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>

    <script>
        // 🪄 120 Seconds (2 Minutes) Timer
        let timeLeft = 120; 
        const timerElement = document.getElementById('time');
        const timerBox = document.getElementById('countdown-timer');
        const verifyBtn = document.getElementById('verifyBtn');

        const countdown = setInterval(function() {
            if(timeLeft <= 0) {
                clearInterval(countdown);
                timerElement.innerHTML = "00:00";
                timerBox.classList.add('warning');
                verifyBtn.disabled = true;
                verifyBtn.innerHTML = "OTP Expired";
                document.querySelector('.otp-input').disabled = true;
            } else {
                let minutes = Math.floor(timeLeft / 60);
                let seconds = timeLeft % 60;
                seconds = seconds < 10 ? "0" + seconds : seconds;
                timerElement.innerHTML = `0${minutes}:${seconds}`;
                if(timeLeft <= 20) timerBox.classList.add('warning');
            }
            timeLeft -= 1;
        }, 1000);
    </script>
</body>
</html>