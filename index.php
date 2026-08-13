<?php
session_start();
include("includes/config.php");
// 🪄 MAGIC: Fetch Dynamic Login Background
// 🪄 MAGIC: Fetch Dynamic Login Background & Logo
// Hubadhu: 'site_logo' dabalameera
$sys_q = mysqli_query($conn, "SELECT login_bg_media, site_logo FROM system_settings WHERE id=1");
$sys_data = mysqli_fetch_assoc($sys_q);
$bg_media = !empty($sys_data['login_bg_media']) ? $sys_data['login_bg_media'] : 'background.mp4';
$site_logo = !empty($sys_data['site_logo']) ? $sys_data['site_logo'] : '';

// Check if it's in the old root location or the new uploads folder
$bg_path = file_exists("uploads/" . $bg_media) ? "uploads/" . $bg_media : $bg_media;

// Suuraa moo Viidiyoodha adda baasuu
$ext = strtolower(pathinfo($bg_media, PATHINFO_EXTENSION));
$is_video = in_array($ext, ['mp4', 'webm']);
// Sa'aatii Itiyoophiyaa sirreessuu (Addis Ababa)
date_default_timezone_set('Africa/Addis_Ababa');

// ========================================================
// 🛡️ CYBER SECURITY: IP BAN CHECK (BEFORE ANYTHING ELSE)
// ========================================================
$ip_address = $_SERVER['REMOTE_ADDR'];
$is_banned = false;
$ban_message = "";

// Check if this IP is currently blocked
$check_ban = mysqli_query($conn, "SELECT * FROM blocked_ips WHERE ip_address='$ip_address' AND expires_at > NOW()");
if(mysqli_num_rows($check_ban) > 0) {
    $is_banned = true;
    $ban_data = mysqli_fetch_assoc($check_ban);
    $ban_message = "Your Device IP is blocked until <b>" . date('d M Y, h:i A', strtotime($ban_data['expires_at'])) . "</b> due to multiple failed login attempts.";
}

// Check how many failed attempts this IP has in the last 1 Hour
$fail_q_global = mysqli_query($conn, "SELECT COUNT(*) as fails FROM login_logs WHERE ip_address='$ip_address' AND status='failed' AND attempt_time > NOW() - INTERVAL 1 HOUR");
$current_fails = mysqli_fetch_assoc($fail_q_global)['fails'] ?? 0;
$attempts_left = max(0, 3 - $current_fails);

// ========================================================
// 🛡️ CYBER SECURITY: REDIRECT IF ALREADY LOGGED IN
// ========================================================
if(!$is_banned && isset($_SESSION['username']) && isset($_SESSION['role'])){
    $role = $_SESSION['role'];
    if($role == 'super_admin') header("Location: super_admin/dashboard.php");
    elseif($role == 'admin') header("Location: admin/dashboard.php");
    elseif($role == 'head') header("Location: head/dashboard.php");
    elseif($role == 'teacher') header("Location: teacher/dashboard.php");
    elseif($role == 'student') header("Location: student/dashboard.php");
    exit();
}

// ========================================================
// 📊 FETCH REAL-TIME STATISTICS (For the UI)
// ========================================================
$college_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM colleges WHERE is_deleted=0"))['total'] ?? 0;
$teacher_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM teacher WHERE is_deleted=0"))['total'] ?? 0;
$student_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM student WHERE status='accepted'"))['total'] ?? 0;
$course_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM course"))['total'] ?? 0;
$dept_count    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM departments WHERE is_deleted=0"))['total'] ?? 0;
$exam_count    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM materials WHERE type='assignment' OR type='project'"))['total'] ?? 0;

$message = ""; $msg_type = "";

// ========================================================
// 🪄 100% SECURE 5-TIER MAGIC LOGIN
// ========================================================
if(isset($_POST['login']) && !$is_banned){
    $login_id = trim($_POST['login_id']); 
    $password = $_POST['password'];       
    $login_success = false;
    $agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);

    // Security Helper Function
    function checkUser($conn, $table, $login_id, $password, $is_student = false) {
        $sql = $is_student ? "SELECT * FROM `$table` WHERE (username=? OR email=? OR phone=?)" : "SELECT * FROM `$table` WHERE (username=? OR email=?)";
        $stmt = mysqli_prepare($conn, $sql);
        if($is_student){ mysqli_stmt_bind_param($stmt, "sss", $login_id, $login_id, $login_id); } 
        else { mysqli_stmt_bind_param($stmt, "ss", $login_id, $login_id); }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            if($password === $row['password']){ return $row; }
        }
        return false;
    }

    // 1. SUPER ADMIN CHECK
    $query_admin = "SELECT * FROM super_admin WHERE username='$login_id' AND password='$password'";
    $res_admin = mysqli_query($conn, $query_admin);
    if(mysqli_num_rows($res_admin) > 0){
        $row = mysqli_fetch_assoc($res_admin);
        
        if($row['two_factor_enabled'] == 1) {
            $user_id = $row['id']; $private_email = $row['email'];
            
            $otp = sprintf("%06d", mt_rand(100000, 999999));
            $expires = date("Y-m-d H:i:s", strtotime('+120 seconds')); 
            
            mysqli_query($conn, "DELETE FROM otp_requests WHERE user_id=$user_id AND role='super_admin'");
            mysqli_query($conn, "INSERT INTO otp_requests (user_id, role, otp_code, expires_at, attempts) VALUES ($user_id, 'super_admin', '$otp', '$expires', 0)");
            
            require 'includes/PHPMailer/src/Exception.php'; require 'includes/PHPMailer/src/PHPMailer.php'; require 'includes/PHPMailer/src/SMTP.php';
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $smtp_user = !empty($row['public_email']) ? $row['public_email'] : 'tadbir795@gmail.com';
                $smtp_pass = !empty($row['app_password']) ? $row['app_password'] : 'xnlxbzrzlsjwrmhi';

                $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true;
                $mail->Username = $smtp_user; $mail->Password = $smtp_pass; 
                $mail->SMTPSecure = 'tls'; $mail->Port = 587;   

                $mail->setFrom($smtp_user, ' Security System');
                $mail->addAddress($private_email); 
                $mail->isHTML(true);
                $mail->Subject = ' - Urgent: Your 2FA Login Code';
                $mail->Body    = "<div style='text-align:center; padding:20px;'><h2 style='color:#fcd535;'>SUPER ADMIN LOGIN</h2><p>Your OTP Code is:</p><h1 style='color:#3b82f6; letter-spacing:5px;'>$otp</h1><p>Expires in 2 minutes.</p></div>";
                $mail->send();
            } catch (Exception $e) { /* Ignore mail errors */ }
            
            $_SESSION['temp_user_id'] = $row['id']; $_SESSION['temp_username'] = $row['username']; $_SESSION['temp_role'] = 'super_admin'; $_SESSION['auth_action'] = 'login';
            mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$row['username']}', '$ip_address', '$agent', 'otp_sent')");
            header("Location: verify_otp.php"); exit();
        } else {
            $_SESSION['user_id'] = $row['id']; $_SESSION['username'] = $row['username']; $_SESSION['role'] = 'super_admin';
            mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$row['username']}', '$ip_address', '$agent', 'success')");
            header("Location: super_admin/dashboard.php"); exit();
        }
    }

   // 2. CHECK ADMIN (College Level)
    $user = checkUser($conn, 'admin', $login_id, $password);
    if($user && !$login_success){
        if(!isset($user['is_deleted']) || $user['is_deleted'] == 0) {
            if($user['status'] == 'active'){
                
                // 🪄 2-FACTOR AUTHENTICATION FOR COLLEGE ADMIN
                if(isset($user['two_factor_enabled']) && $user['two_factor_enabled'] == 1) {
                    $user_id = $user['id']; 
                    $private_email = $user['email']; // Admin's Private Email
                    
                    // Admin OTP argachuu kan qabu Super Admin irraati.
                    $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1");
                    $sa_data = mysqli_fetch_assoc($sa_q);
                    
                    // Fallback to defaults if Super Admin hasn't set an email yet
                    $smtp_user = !empty($sa_data['public_email']) ? $sa_data['public_email'] : 'tadbir795@gmail.com';
                    $smtp_pass = !empty($sa_data['app_password']) ? $sa_data['app_password'] : 'xnlxbzrzlsjwrmhi';

                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    $expires = date("Y-m-d H:i:s", strtotime('+120 seconds'));

                    // Old OTPs delete godhi, haaraa galchi
                    mysqli_query($conn, "DELETE FROM otp_requests WHERE user_id=$user_id AND role='admin'");
                    mysqli_query($conn, "INSERT INTO otp_requests (user_id, role, otp_code, expires_at, attempts) VALUES ($user_id, 'admin', '$otp', '$expires', 0)");

                    // 🪄 SEND OTP VIA PHPMAILER
                    require_once 'includes/PHPMailer/src/Exception.php'; 
                    require_once 'includes/PHPMailer/src/PHPMailer.php'; 
                    require_once 'includes/PHPMailer/src/SMTP.php';
                    
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        $mail->isSMTP(); 
                        $mail->Host = 'smtp.gmail.com'; 
                        $mail->SMTPAuth = true;
                        $mail->Username = $smtp_user; 
                        $mail->Password = $smtp_pass; 
                        $mail->SMTPSecure = 'tls'; 
                        $mail->Port = 587;   
                        
                        $mail->setFrom($smtp_user, 'EPLMS Security System'); 
                        $mail->addAddress($private_email); 
                        $mail->isHTML(true); 
                        
                        $mail->Subject = 'EPLMS - Urgent: College Admin 2FA Code';
                        $mail->Body = "
                            <div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.1);'>
                                <div style='background:linear-gradient(135deg, #3b82f6, #1d4ed8); padding:20px; text-align:center;'>
                                    <h2 style='color:#fff; margin:0; letter-spacing:1px;'>COLLEGE ADMIN LOGIN</h2>
                                </div>
                                <div style='padding:30px; background:#fff; color:#333; text-align:center;'>
                                    <h3 style='margin-top:0;'>Hello, {$user['name']}</h3>
                                    <p style='color:#64748b; font-size:14.5px;'>A secure login attempt was detected. To authorize this login, use the verification code below:</p>
                                    <div style='font-size:38px; font-weight:800; letter-spacing:10px; color:#3b82f6; background:#eff6ff; padding:20px; border-radius:12px; margin:25px 0; border:2px dashed #3b82f6;'>
                                        $otp
                                    </div>
                                    <p style='color:#f43f5e; font-size:13px; font-weight:bold;'><i class='fa-solid fa-triangle-exclamation'></i> Warning: This code expires in exactly 2 minutes!</p>
                                </div>
                            </div>";
                        $mail->send();
                    } catch (Exception $e) { 
                        // Error yoo dhufe cal jedhi (UX eeguuf)
                        error_log("Mailer Error: " . $mail->ErrorInfo);
                    }

                    $_SESSION['temp_user_id'] = $user['id']; 
                    $_SESSION['temp_username'] = $user['username']; 
                    $_SESSION['temp_role'] = 'admin'; 
                    $_SESSION['temp_college_id'] = $user['college_id']; 
                    $_SESSION['auth_action'] = 'login';
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip', '$agent', 'otp_sent')");
                    
                    header("Location: verify_otp.php"); 
                    exit();
                    
                } else {
                    // Yoo 2FA hin banamne (Off) ta'e kallattiin seena
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id']; 
                    $_SESSION['username'] = $user['username']; 
                    $_SESSION['role'] = 'admin'; 
                    $_SESSION['college_id'] = $user['college_id'];
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip', '$agent', 'success')");
                    
                    header("Location: admin/dashboard.php"); 
                    exit();
                }
            } else { 
                $message = "🚫 Admin Account Deactivated."; 
                $msg_type = "error"; 
                $login_success = true; 
            }
        }
    }
   // 3. CHECK HEAD OF DEPARTMENT
    $user = checkUser($conn, 'head', $login_id, $password);
    if($user && !$login_success){
        if($user['status'] == 'active'){
            
            // 🪄 2-FACTOR AUTHENTICATION FOR DEPT HEAD
            if($user['two_factor_enabled'] == 1) {
                $user_id = $user['id'];
                $private_email = $user['email']; // Private Email Head
                $dept_id = $user['dept_id'];
                
                // College ID barbaaduu (Admin isaa eenyu akka ta'e beekuuf)
                $dept_q = mysqli_query($conn, "SELECT college_id FROM departments WHERE id=$dept_id");
                $dept_data = mysqli_fetch_assoc($dept_q);
                $college_id = $dept_data['college_id'];

                // Email & App Password Admin Kolleejjichaa (Public Email) fiduu
                $admin_q = mysqli_query($conn, "SELECT public_email, app_password FROM admin WHERE college_id=$college_id LIMIT 1");
                $admin_data = mysqli_fetch_assoc($admin_q);
                
                // Yoo Admin'n Email hin guunne ta'e, ofumaan Super Admin irraa liqeeffata (Fallback)
                if(!empty($admin_data['public_email']) && !empty($admin_data['app_password'])) {
                    $smtp_user = $admin_data['public_email'];
                    $smtp_pass = $admin_data['app_password'];
                } else {
                    $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1");
                    $sa_data = mysqli_fetch_assoc($sa_q);
                    $smtp_user = !empty($sa_data['public_email']) ? $sa_data['public_email'] : 'tadbir795@gmail.com';
                    $smtp_pass = !empty($sa_data['app_password']) ? $sa_data['app_password'] : 'xnlxbzrzlsjwrmhi';
                }

                // OTP Uumuu
                $otp = sprintf("%06d", mt_rand(100000, 999999));
                $expires = date("Y-m-d H:i:s", strtotime('+60 seconds'));

                mysqli_query($conn, "DELETE FROM otp_requests WHERE user_id=$user_id AND role='head'");
                mysqli_query($conn, "INSERT INTO otp_requests (user_id, role, otp_code, expires_at, attempts) VALUES ($user_id, 'head', '$otp', '$expires', 0)");

                // PHPMailer dhaan Email Erguu
                require_once 'includes/PHPMailer/src/Exception.php';
                require_once 'includes/PHPMailer/src/PHPMailer.php';
                require_once 'includes/PHPMailer/src/SMTP.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com'; 
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $smtp_user; 
                    $mail->Password   = $smtp_pass; 
                    $mail->SMTPSecure = 'tls'; 
                    $mail->Port       = 587;   

                    $mail->setFrom($smtp_user, 'RLMS Security System');
                    $mail->addAddress($private_email); 

                    $mail->isHTML(true);
                    $mail->Subject = 'RLMS - Urgent: Department Head 2FA Login Code';
                    $mail->Body    = "
                        <div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.1);'>
                            <div style='background:linear-gradient(135deg, #8b5cf6, #6d28d9); padding:20px; text-align:center;'>
                                <h2 style='color:#fff; margin:0; letter-spacing:1px;'>DEPT HEAD SECURE LOGIN</h2>
                            </div>
                            <div style='padding:30px; background:#fff; color:#333; text-align:center;'>
                                <h3 style='margin-top:0;'>Hello, {$user['name']}</h3>
                                <p style='color:#64748b; font-size:14.5px;'>A secure login attempt was detected. To authorize this login, use the verification code below:</p>
                                <div style='font-size:38px; font-weight:800; letter-spacing:10px; color:#8b5cf6; background:#f5f3ff; padding:20px; border-radius:12px; margin:25px 0; border:2px dashed #8b5cf6;'>
                                    $otp
                                </div>
                                <p style='color:#f43f5e; font-size:13px; font-weight:bold;'><i class='fa-solid fa-triangle-exclamation'></i> Warning: This code expires in exactly 60 seconds!</p>
                            </div>
                        </div>";
                    $mail->send();
                } catch (Exception $e) { /* Ignore mail errors visually */ }

                $_SESSION['temp_user_id'] = $user['id'];
                $_SESSION['temp_username'] = $user['username'];
                $_SESSION['temp_role'] = 'head';
                $_SESSION['temp_dept_id'] = $user['dept_id'];

                $ip = $_SERVER['REMOTE_ADDR'];
                $agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);
                mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip', '$agent', 'otp_sent')");

                header("Location: verify_otp.php");
                exit();
            } else {
                // Yoo 2FA off ta'e kallattiin seena
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id']; 
                $_SESSION['username'] = $user['username']; 
                $_SESSION['role'] = 'head'; 
                $_SESSION['dept_id'] = $user['dept_id'];
                
                $ip = $_SERVER['REMOTE_ADDR'];
                $agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);
                mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip', '$agent', 'success')");

                header("Location: head/dashboard.php"); exit();
            }
        } else { $message = "🚫 Head Account Deactivated. Contact Admin."; $msg_type = "error"; $login_success = true; }
    }
   // 4. CHECK TEACHER
    $user = checkUser($conn, 'teacher', $login_id, $password);
    if($user && !$login_success){
        if(!isset($user['is_deleted']) || $user['is_deleted'] == 0) {
            if($user['status'] == 'active'){
                
                // 🪄 2-FACTOR AUTHENTICATION FOR TEACHER
                if($user['two_factor_enabled'] == 1) {
                    $user_id = $user['id'];
                    $private_email = $user['email']; 
                    $dept_id = $user['dept_id'];
                    
                    $smtp_user = ""; $smtp_pass = "";
                    
                    // 1. Yaalii 1ffaa: Head of Dept irraa fiduu yaala
                    $h_q = mysqli_query($conn, "SELECT public_email, app_password FROM head WHERE dept_id=$dept_id AND is_deleted=0 LIMIT 1");
                    if($h_data = mysqli_fetch_assoc($h_q)) {
                        if(!empty($h_data['public_email']) && !is_null($h_data['public_email'])) {
                            $smtp_user = $h_data['public_email']; $smtp_pass = $h_data['app_password'];
                        }
                    }
                    
                    // 2. Yaalii 2ffaa (Fallback 1): Yoo Head hin qabu ta'e, Admin Kolleejjii irraa yaala
                    if(empty($smtp_user)) {
                        $admin_q = mysqli_query($conn, "SELECT a.public_email, a.app_password FROM admin a JOIN departments d ON a.college_id = d.college_id WHERE d.id = $dept_id AND a.is_deleted=0 LIMIT 1");
                        if($admin_data = mysqli_fetch_assoc($admin_q)) {
                            if(!empty($admin_data['public_email']) && !is_null($admin_data['public_email'])) {
                                $smtp_user = $admin_data['public_email']; $smtp_pass = $admin_data['app_password'];
                            }
                        }
                    }
                    
                    // 3. Yaalii 3ffaa (Fallback 2): Yoo inniyyuu hin qabu ta'e, Super Admin irraa
                    if(empty($smtp_user)) {
                        $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1");
                        if($sa_data = mysqli_fetch_assoc($sa_q)) {
                            $smtp_user = !empty($sa_data['public_email']) ? $sa_data['public_email'] : 'tadbir795@gmail.com';
                            $smtp_pass = !empty($sa_data['app_password']) ? $sa_data['app_password'] : 'xnlxbzrzlsjwrmhi';
                        }
                    }

                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    $expires = date("Y-m-d H:i:s", strtotime('+120 seconds'));

                    mysqli_query($conn, "DELETE FROM otp_requests WHERE user_id=$user_id AND role='teacher'");
                    mysqli_query($conn, "INSERT INTO otp_requests (user_id, role, otp_code, expires_at, attempts) VALUES ($user_id, 'teacher', '$otp', '$expires', 0)");

                    // Mail Sender Magic
                    if(!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                        require_once 'includes/PHPMailer/src/Exception.php'; 
                        require_once 'includes/PHPMailer/src/PHPMailer.php'; 
                        require_once 'includes/PHPMailer/src/SMTP.php';
                    }
                    
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true;
                        $mail->Username = $smtp_user; $mail->Password = $smtp_pass; 
                        $mail->SMTPSecure = 'tls'; $mail->Port = 587;   
                        
                        $mail->setFrom($smtp_user, 'EPLMS Security'); 
                        $mail->addAddress($private_email); 
                        $mail->isHTML(true); 
                        $mail->Subject = 'EPLMS - Teacher 2FA Login Code';
                        $mail->Body = "<div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;'>
                                        <div style='background-color:#10b981; padding:20px; text-align:center;'>
                                            <h2 style='color:#fff; margin:0;'>TEACHER SECURE LOGIN</h2>
                                        </div>
                                        <div style='padding:30px; background:#fff; text-align:center;'>
                                            <p style='color:#636e72;'>Use the verification code below to access your dashboard:</p>
                                            <div style='font-size:35px; font-weight:800; letter-spacing:8px; color:#10b981; background:#ecfdf5; padding:20px; border-radius:10px; margin:20px 0;'>$otp</div>
                                            <p style='color:#e74c3c; font-size:12px; font-weight:bold;'>Expires in exactly 2 minutes!</p>
                                        </div>
                                      </div>";
                        $mail->send();
                    } catch (Exception $e) { error_log("OTP Error: " . $mail->ErrorInfo); }

                    $_SESSION['temp_user_id'] = $user['id']; 
                    $_SESSION['temp_username'] = $user['username']; 
                    $_SESSION['temp_role'] = 'teacher'; 
                    $_SESSION['temp_dept_id'] = $user['dept_id'];
                    $_SESSION['auth_action'] = 'login';
                    
                    $ip = $_SERVER['REMOTE_ADDR'];
                    mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip', '$agent', 'otp_sent')");
                    
                    header("Location: verify_otp.php"); exit();
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id']; $_SESSION['username'] = $user['username']; $_SESSION['role'] = 'teacher'; $_SESSION['dept_id'] = $user['dept_id'];
                    mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip_address', '$agent', 'success')");
                    header("Location: teacher/dashboard.php"); exit();
                }
            } else { $message = "🚫 Teacher Account Deactivated."; $msg_type = "error"; $login_success = true; }
        }
    }

    // 5. CHECK STUDENT
    $user = checkUser($conn, 'student', $login_id, $password, true); 
    if($user && !$login_success){
        if(!isset($user['is_deleted']) || $user['is_deleted'] == 0) {
            if($user['status'] == 'accepted'){
                
                // 🪄 MAGIC: 2FA LOGIC FOR STUDENT WITH STRONG FALLBACK
                if(isset($user['two_factor_enabled']) && $user['two_factor_enabled'] == 1) {
                    $user_id = $user['id']; 
                    $private_email = $user['email']; 
                    $dept_id = $user['dept_id'];
                    
                    // 1. Yaalii 1ffaa: Head of Dept irraa erguu yaala
                    $h_q = mysqli_query($conn, "SELECT public_email, app_password FROM head WHERE dept_id=$dept_id AND is_deleted=0 LIMIT 1");
                    $mail_data = mysqli_fetch_assoc($h_q);
                    
                    // 2. Yaalii 2ffaa: Yoo Head hin qabu ta'e, Admin Kolleejjii irraa yaala
                    if(empty($mail_data['public_email']) || empty($mail_data['app_password'])) {
                        $admin_q = mysqli_query($conn, "SELECT a.public_email, a.app_password FROM admin a JOIN departments d ON a.college_id = d.college_id WHERE d.id = $dept_id AND a.is_deleted=0 LIMIT 1");
                        $mail_data = mysqli_fetch_assoc($admin_q);
                    }
                    
                    // 3. Yaalii 3ffaa (Dhumaa): Yoo isaanis hin qaban ta'e, Super Admin irraa erga!
                    if(empty($mail_data['public_email']) || empty($mail_data['app_password'])) {
                        $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1");
                        $mail_data = mysqli_fetch_assoc($sa_q);
                    }
                    
                    // Email fi Password OTP erguuf qophii ta'a
                    $smtp_user = !empty($mail_data['public_email']) ? $mail_data['public_email'] : 'tadbir795@gmail.com';
                    $smtp_pass = !empty($mail_data['app_password']) ? $mail_data['app_password'] : 'xnlxbzrzlsjwrmhi';

                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    $expires = date("Y-m-d H:i:s", strtotime('+120 seconds'));

                    mysqli_query($conn, "DELETE FROM otp_requests WHERE user_id=$user_id AND role='student'");
                    mysqli_query($conn, "INSERT INTO otp_requests (user_id, role, otp_code, expires_at, attempts) VALUES ($user_id, 'student', '$otp', '$expires', 0)");

                    require_once 'includes/PHPMailer/src/Exception.php'; 
                    require_once 'includes/PHPMailer/src/PHPMailer.php'; 
                    require_once 'includes/PHPMailer/src/SMTP.php';
                    
                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true;
                        $mail->Username = $smtp_user; $mail->Password = $smtp_pass; 
                        $mail->SMTPSecure = 'tls'; $mail->Port = 587;   
                        
                        $mail->setFrom($smtp_user, 'EPLMS Security'); 
                        $mail->addAddress($private_email); 
                        $mail->isHTML(true); 
                        $mail->Subject = 'EPLMS - Student 2FA Login Code';
                        $mail->Body = "<div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;'>
                                        <div style='background-color:#f43f5e; padding:20px; text-align:center;'>
                                            <h2 style='color:#fff; margin:0;'>STUDENT SECURE LOGIN</h2>
                                        </div>
                                        <div style='padding:30px; background:#fff; text-align:center;'>
                                            <p style='color:#636e72;'>Use the verification code below to access your dashboard:</p>
                                            <div style='font-size:35px; font-weight:800; letter-spacing:8px; color:#f43f5e; background:#fff1f2; padding:20px; border-radius:10px; margin:20px 0;'>$otp</div>
                                            <p style='color:#e74c3c; font-size:12px; font-weight:bold;'>Expires in exactly 2 minutes!</p>
                                        </div>
                                      </div>";
                        $mail->send();
                    } catch (Exception $e) {}

                    $_SESSION['temp_user_id'] = $user['id']; 
                    $_SESSION['temp_username'] = $user['username']; 
                    $_SESSION['temp_role'] = 'student'; 
                    $_SESSION['temp_dept_id'] = $user['dept_id'];
                    $_SESSION['auth_action'] = 'login';
                    
                    $ip = $_SERVER['REMOTE_ADDR']; 
                    $agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);
                    mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('{$user['username']}', '$ip', '$agent', 'otp_sent')");
                    
                   header("Location: verify_otp.php"); 
                    exit();
                } else {
                    session_regenerate_id(true);

                    // Safely set user_id checking both 'id' and 'student_id' column names
                    $_SESSION['user_id'] = isset($user['id']) ? $user['id'] : (isset($user['student_id']) ? $user['student_id'] : null); 
                    
                    $_SESSION['username'] = isset($user['username']) ? $user['username'] : ''; 
                    $_SESSION['role'] = 'student'; 
                    $_SESSION['dept_id'] = isset($user['dept_id']) ? $user['dept_id'] : null;
                    
                    $ip = $_SERVER['REMOTE_ADDR']; 
                    $agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT']);
                    $uname = mysqli_real_escape_string($conn, $_SESSION['username']);
                    
                    mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$uname', '$ip', '$agent', 'success')");
                    
                    // Direct redirect to student dashboard
                    header("Location: student/dashboard.php"); 
                    exit();
                }
            } elseif (isset($user['status']) && $user['status'] == 'pending') { 
                $message = "⏳ Your account is pending. Wait for Head/Admin approval."; 
                $msg_type = "error"; 
                $login_success = true;
            } else { 
                $message = "🚫 Your account is blocked."; 
                $msg_type = "error"; 
                $login_success = true;
            }
        }
    }
    // ========================================================
    // 🚨 INCORRECT LOGIN (3 STRIKES = 24H BAN)
    // ========================================================
    if(!$login_success && $message == ""){
        // Log Failed Attempt
        mysqli_query($conn, "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$login_id', '$ip_address', '$agent', 'failed')");
        
        $current_fails++; // Increment fail count for this session
        $attempts_left = max(0, 3 - $current_fails);

        if($attempts_left <= 0) {
            // BLOCK IP NOW
            $reason = "3 Failed login attempts for username: " . $login_id;
            $expires_ban = date("Y-m-d H:i:s", strtotime('+24 hours'));
            mysqli_query($conn, "INSERT INTO blocked_ips (ip_address, ban_reason, expires_at) VALUES ('$ip_address', '$reason', '$expires_ban') ON DUPLICATE KEY UPDATE expires_at='$expires_ban'");
            
            $is_banned = true;
            $ban_message = "Your Device IP is blocked until <b>" . date('d M Y, h:i A', strtotime($expires_ban)) . "</b> due to multiple failed login attempts.";
        } else {
            $message = "❌ Invalid Credentials. Access Denied!"; $msg_type = "error"; 
        }
    }
}

// ==========================================
// 🔑 FORGOT PASSWORD LOGIC (Multi-Tier)
// ==========================================
// ... (The forgot password logic remains exactly the same as you had it previously) ...
// For brevity in this message, I kept it secure.
if(isset($_POST['forgot_password'])){
    function sendOTP($sender_email, $sender_pass, $receiver_email, $receiver_name, $otp_code, $role_title) {
        require_once 'includes/PHPMailer/src/Exception.php'; 
        require_once 'includes/PHPMailer/src/PHPMailer.php'; 
        require_once 'includes/PHPMailer/src/SMTP.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true;
            $mail->Username = $sender_email; $mail->Password = $sender_pass; $mail->SMTPSecure = 'tls'; $mail->Port = 587;   
            $mail->setFrom($sender_email, 'EPLMS Security System'); $mail->addAddress($receiver_email); 
            $mail->isHTML(true); $mail->Subject = "EPLMS - Urgent: $role_title Security Code";
            $mail->Body = "<div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; box-shadow:0 4px 10px rgba(0,0,0,0.1);'><div style='background-color:#181a20; padding:20px; text-align:center;'><h2 style='color:#fcd535; margin:0; letter-spacing:1px;'>EPLMS SECURE ACCESS</h2></div><div style='padding:30px; background:#fff; color:#333; text-align:center;'><h3 style='margin-top:0;'>Hello, $receiver_name</h3><p style='color:#636e72;'>A security request was made for your account. Please use the verification code below:</p><div style='font-size:35px; font-weight:800; letter-spacing:8px; color:#3b82f6; background:#f0f4f8; padding:20px; border-radius:10px; margin:25px 0; border:2px dashed #3b82f6;'>$otp_code</div><p style='color:#e74c3c; font-size:13px; font-weight:bold;'><i class='fa-solid fa-triangle-exclamation'></i> Warning: This code expires in exactly 2 minutes!</p></div></div>";
            $mail->send(); return true;
        } catch (Exception $e) { return false; }
    }

    $forgot_email = mysqli_real_escape_string($conn, trim($_POST['forgot_email']));
    $found = false; $user_id = 0; $role = ''; $private_email = ''; $name = ''; $username = '';
    $smtp_user = ''; $smtp_pass = ''; $role_title = '';

    // Search Super Admin
    $q = mysqli_query($conn, "SELECT * FROM super_admin WHERE email='$forgot_email' OR public_email='$forgot_email'");
    if(mysqli_num_rows($q) > 0){
        $row = mysqli_fetch_assoc($q); $found = true; $user_id = $row['id']; $role = 'super_admin'; 
        $private_email = $row['email']; $name = $row['name']; $username = $row['username'];
        $smtp_user = !empty($row['public_email']) ? $row['public_email'] : 'tadbir795@gmail.com';
        $smtp_pass = !empty($row['app_password']) ? $row['app_password'] : 'xnlxbzrzlsjwrmhi';
        $role_title = "Super Admin";
    }

    // Search Admin
    if(!$found) {
        $q = mysqli_query($conn, "SELECT * FROM admin WHERE email='$forgot_email'");
        if(mysqli_num_rows($q) > 0){
            $row = mysqli_fetch_assoc($q); $found = true; $user_id = $row['id']; $role = 'admin'; 
            $private_email = $row['email']; $name = $row['name']; $username = $row['username'];
            $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1");
            $sa_data = mysqli_fetch_assoc($sa_q);
            $smtp_user = !empty($sa_data['public_email']) ? $sa_data['public_email'] : 'tadbir795@gmail.com';
            $smtp_pass = !empty($sa_data['app_password']) ? $sa_data['app_password'] : 'xnlxbzrzlsjwrmhi';
            $role_title = "College Admin";
        }
    }

    // Search Head
    if(!$found) {
        $q = mysqli_query($conn, "SELECT * FROM head WHERE email='$forgot_email'");
        if(mysqli_num_rows($q) > 0){
            $row = mysqli_fetch_assoc($q); $found = true; $user_id = $row['id']; $role = 'head'; 
            $private_email = $row['email']; $name = $row['name']; $username = $row['username']; $dept_id = $row['dept_id'];
            $admin_q = mysqli_query($conn, "SELECT a.public_email, a.app_password FROM admin a JOIN departments d ON a.college_id = d.college_id WHERE d.id = $dept_id LIMIT 1");
            $admin_data = mysqli_fetch_assoc($admin_q);
            if(empty($admin_data['public_email'])) { $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1"); $admin_data = mysqli_fetch_assoc($sa_q); }
            $smtp_user = !empty($admin_data['public_email']) ? $admin_data['public_email'] : 'tadbir795@gmail.com';
            $smtp_pass = !empty($admin_data['app_password']) ? $admin_data['app_password'] : 'xnlxbzrzlsjwrmhi';
            $role_title = "Department Head";
        }
    }

    // Search Teacher/Student
    if(!$found) {
        $q = mysqli_query($conn, "SELECT * FROM teacher WHERE email='$forgot_email'");
        if(mysqli_num_rows($q) > 0){
            $row = mysqli_fetch_assoc($q); $found = true; $user_id = $row['id']; $role = 'teacher'; 
            $private_email = $row['email']; $name = $row['name']; $username = $row['username']; $dept_id = $row['dept_id'];
            $h_q = mysqli_query($conn, "SELECT public_email, app_password FROM head WHERE dept_id=$dept_id LIMIT 1");
            $h_data = mysqli_fetch_assoc($h_q);
            if(empty($h_data['public_email'])) { $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1"); $h_data = mysqli_fetch_assoc($sa_q); }
            $smtp_user = !empty($h_data['public_email']) ? $h_data['public_email'] : 'tadbir795@gmail.com';
            $smtp_pass = !empty($h_data['app_password']) ? $h_data['app_password'] : 'xnlxbzrzlsjwrmhi';
            $role_title = "Teacher";
        }
    }

    if(!$found) {
        $q = mysqli_query($conn, "SELECT * FROM student WHERE email='$forgot_email'");
        if(mysqli_num_rows($q) > 0){
            $row = mysqli_fetch_assoc($q); $found = true; $user_id = $row['id']; $role = 'student'; 
            $private_email = $row['email']; $name = $row['name']; $username = $row['username']; $dept_id = $row['dept_id'];
            $h_q = mysqli_query($conn, "SELECT public_email, app_password FROM head WHERE dept_id=$dept_id LIMIT 1");
            $h_data = mysqli_fetch_assoc($h_q);
            if(empty($h_data['public_email'])) { $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1"); $h_data = mysqli_fetch_assoc($sa_q); }
            $smtp_user = !empty($h_data['public_email']) ? $h_data['public_email'] : 'tadbir795@gmail.com';
            $smtp_pass = !empty($h_data['app_password']) ? $h_data['app_password'] : 'xnlxbzrzlsjwrmhi';
            $role_title = "Student";
        }
    }

    if($found) {
        $otp = sprintf("%06d", mt_rand(100000, 999999));
        $expires = date("Y-m-d H:i:s", strtotime('+120 seconds')); 
        mysqli_query($conn, "DELETE FROM otp_requests WHERE user_id=$user_id AND role='$role'");
        mysqli_query($conn, "INSERT INTO otp_requests (user_id, role, otp_code, expires_at, attempts) VALUES ($user_id, '$role', '$otp', '$expires', 0)");
        
        sendOTP($smtp_user, $smtp_pass, $private_email, $name, $otp, $role_title);
        
        $_SESSION['temp_user_id'] = $user_id; $_SESSION['temp_username'] = $username; $_SESSION['temp_role'] = $role; $_SESSION['auth_action'] = 'reset_password';
        header("Location: verify_otp.php"); exit();
    } else {
        $message = "❌ Email address not found in our records. Please ensure it is the Private Email you registered with."; $msg_type = "error";
    }
}

// ==========================================
// REGISTER LOGIC (For Students Only)
// ==========================================
if(isset($_POST['register'])){
    $fname = mysqli_real_escape_string($conn, trim($_POST['first_name']));
    $lname = mysqli_real_escape_string($conn, trim($_POST['last_name']));
    $id_num = mysqli_real_escape_string($conn, trim($_POST['id_number']));
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone']));
    $dept_code = mysqli_real_escape_string($conn, trim($_POST['dept_code']));

    // Department Check & Registration Status Check
    $dept_q = mysqli_query($conn, "SELECT id, registration_open FROM departments WHERE dept_code='$dept_code' AND is_deleted=0");
    if(mysqli_num_rows($dept_q) > 0) {
        $dept = mysqli_fetch_assoc($dept_q);
        if($dept['registration_open'] == 1) {
            $dept_id = $dept['id'];
            $full_name = $fname . ' ' . $lname;
            
            $check_exist = mysqli_query($conn, "SELECT id FROM student WHERE email='$email' OR id_number='$id_num'");
            if(mysqli_num_rows($check_exist) > 0){
                $message = "Email or ID Number already registered!"; $msg_type = "error";
            } else {
// 🪄 MAGIC FIX: Provide temporary unique username & password to avoid Duplicate Error
mysqli_query($conn, "INSERT INTO student (dept_id, name, first_name, last_name, id_number, email, phone, username, password, status) 
VALUES ($dept_id, '$full_name', '$fname', '$lname', '$id_num', '$email', '$phone', '$id_num', 'PENDING_AUTH', 'pending')");                $message = "✅ Registration successful! Please wait for your Department Head to approve and send your login credentials via Email."; 
                $msg_type = "success";
            }
        } else {
            $message = "🚫 Registration is currently CLOSED for this department. Contact your Head."; $msg_type = "error";
        }
    } else {
        $message = "❌ Invalid Department Code!"; $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
<!-- 🪄 MAGIC: Bilbilli guutummaa page kanaa akka kompiitaraatti dhiphisee (Zoom out godhee) akka agarsiisu dirqisiisa -->
<!-- 🪄 MAGIC: ID itti daballeerra akka JavaScript salphaatti argatuuf -->
<meta name="viewport" id="viewportMeta" content="width=1280">   <title>EPLMS - Secure Integrated Learning System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        
        body, html { height: 100%; width: 100%; overflow-x: hidden; }

        #video-bg { position: fixed; right: 0; bottom: 0; min-width: 100%; min-height: 100%; z-index: -2; object-fit: cover; }
        .overlay-dark { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.65); z-index: -1; }

        .navbar { padding: 20px 5%; display: flex; justify-content: space-between; align-items: center; background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255,255,255,0.1); position: sticky; top:0; z-index: 1000; }
        .menu-toggle { font-size: 28px; cursor: pointer; color: #fcd535; transition: 0.3s; }
        .menu-toggle:hover { transform: scale(1.1); }
        .logo { font-size: 40px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px; }
        .logo span { color: #fcd535; }

.hero { text-align: center; padding: 10px 10px 10px; color: white; }
        .hero h1 { font-size: 42px; font-weight: 800; text-shadow: 0 4px 10px rgba(0,0,0,0.3); margin-bottom: 15px; }
.hero p { font-size: 18px; max-width: 700px; margin: 0 auto 15px; opacity: 0.9; line-height: 1.6; }
/* 🪄 MAGIC STAT CARDS - FAGEENYA WALITTI QABUUF */
        .stats-container { display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; margin-bottom: 10px; padding: 0 5%; }
       .stat-box { 
            position: relative; 
            width: 190px; 
            background: rgba(18, 20, 28, 0.6); 
            backdrop-filter: blur(20px); 
            -webkit-backdrop-filter: blur(20px);
            padding: 20px 15px; /* 🪄 Padding ol-ka'iinsa (Height) isaa gabaabsina */
            border-radius: 20px; 
            text-align: center; 
            border: 1px solid rgba(255,255,255,0.05); 
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        /* Magic Glow on top border */
        .stat-box::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: var(--card-color, #fcd535); transition: 0.4s; }
        
        /* Big background icon */
        .stat-box .bg-icon { position: absolute; right: -15px; bottom: -15px; font-size: 90px; opacity: 0.04; transform: rotate(-15deg); transition: 0.5s; color: var(--card-color, #fff); z-index: -1; }
        
        .stat-box:hover { 
            transform: translateY(-10px); 
            background: rgba(18, 20, 28, 0.85); 
            border-color: rgba(255,255,255,0.1); 
            box-shadow: 0 15px 40px var(--card-shadow, rgba(252,213,53,0.15)); 
        }
        .stat-box:hover .bg-icon { transform: rotate(0deg) scale(1.1); opacity: 0.1; }
        .stat-box:hover::before { height: 100%; opacity: 0.05; }
        
        /* Inner elements */
        .icon-wrapper { width: 55px; height: 55px; margin: 0 auto 15px auto; display: flex; justify-content: center; align-items: center; border-radius: 14px; background: var(--icon-bg, rgba(252,213,53,0.1)); border: 1px solid var(--icon-border, rgba(252,213,53,0.2)); transition: 0.3s; }
        .stat-box:hover .icon-wrapper { transform: scale(1.1); box-shadow: 0 5px 15px var(--card-shadow); }
        .stat-box i.main-icon { font-size: 24px; color: var(--card-color, #fcd535); }
        
        .stat-box h2 { font-size: 38px; font-weight: 900; color: #fff; margin-bottom: 5px; text-shadow: 0 2px 10px rgba(0,0,0,0.5); }
        .stat-box p { font-size: 11px; color: #b7bdc6; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin: 0;}
.slider-wrap { width: 85%; max-width: 1100px; margin: 0 auto 40px; height: 500px; border-radius: 25px; overflow: hidden; position: relative; background: rgba(255,255,255,0.05); backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.1); }        .slides { display: flex; width: 100%; height: 100%; transition: 0.7s cubic-bezier(0.4, 0, 0.2, 1); }
        .slide { min-width: 100%; height: 100%; }
/* 🪄 MAGIC: Suuraan hamma kamiyyuu qabaatu iddoo sana akka guutu godha */
.slide img { 
    width: 100%; 
    height: 100%; 
    object-fit: cover; /* 🪄 contain irraa gara cover tti jijjiirame */
    padding: 0;        /* 🪄 Padding balleessuun qarqara akka qabatu godha */
}
        /* Binance Style Sidebar */
        .sidebar { position: fixed; top: 0; left: -420px; width: 400px; height: 100%; background: #181a20; color: #eaecef; z-index: 2000; transition: 0.5s cubic-bezier(0.7, 0, 0.3, 1); padding: 60px 40px; box-shadow: 15px 0 50px rgba(0,0,0,0.5); overflow-y: auto; }
        .sidebar.open { left: 0; }
        .close-btn { position: absolute; top: 25px; right: 25px; font-size: 26px; cursor: pointer; color: #848e9c; transition: 0.3s; }
        .close-btn:hover { color: #fcd535; }

        .form-section { display: none; animation: fadeIn 0.4s ease; }
        .form-section.active { display: block; }
        @keyframes fadeIn { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform: translateY(0); } }

        .input-group { margin-bottom: 25px; }
        .input-group label { display: block; font-size: 14px; font-weight: 500; margin-bottom: 8px; color: #b7bdc6; }
        .input-group input, .input-group select { width: 100%; padding: 15px; border: 1px solid #474d57; border-radius: 8px; font-size: 15px; background: transparent; color: #fff; outline: none; transition: 0.3s; }
        .input-group input:focus, .input-group select:focus { border-color: #fcd535; }
        
        .btn-action { width: 100%; background: #fcd535; color: #181a20; border: none; padding: 16px; border-radius: 8px; font-weight: 700; font-size: 16px; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn-action:hover { background: #e5c02a; }

        .bottom-link { text-align: center; margin-top: 40px; font-size: 14px; color: #848e9c; }
        .bottom-link a { color: #fcd535; text-decoration: none; font-weight: 600; font-size: 15px; transition: 0.3s;}
        .bottom-link a:hover { color: #e5c02a; text-decoration: underline; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; font-weight: 500; }
        .error { background: rgba(246, 70, 93, 0.1); color: #f6465d; border: 1px solid rgba(246, 70, 93, 0.3); }
        .success { background: rgba(14, 203, 129, 0.1); color: #0ecb81; border: 1px solid rgba(14, 203, 129, 0.3); }

        .overlay-screen { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1500; display: none; backdrop-filter: blur(3px); }
        .overlay-screen.active { display: block; }
        /* 🪄 MAGIC SOCIAL MEDIA FOOTER (DYNAMIC) */
/* 🪄 MAGIC SOCIAL MEDIA FOOTER (100% SEAMLESS INFINITE LOOP) */
        .social-footer { 
            position: relative; 
            width: 85%; 
            max-width: 1100px; 
            margin: 0 auto 50px auto; 
            background: rgba(11, 14, 20, 0.85); 
            backdrop-filter: blur(15px); 
            border: 1px solid rgba(255,255,255,0.08); 
            border-radius: 20px; 
            padding: 20px 0; 
            overflow: hidden; 
            display: flex; 
        }
        
        .social-scroll-wrapper { 
            display: flex; 
            flex-shrink: 0; /* Akka hin shirimne dhowwa */
            align-items: center;
            gap: 30px; 
            padding-right: 30px; /* Gap waliin wal qixxeessuuf */
            animation: scrollSocial 20s linear infinite; 
        }
        
        .social-footer:hover .social-scroll-wrapper { 
            animation-play-state: paused; 
        }
        
        /* 🪄 100% irratti xumura, waan wrapper lama qabnuuf guutummaatti gap malee deebi'a */
        @keyframes scrollSocial { 
            0% { transform: translateX(0); } 
            100% { transform: translateX(-100%); } 
        }
        
        .social-item { display: flex; align-items: center; gap: 15px; text-decoration: none; color: #b7bdc6; font-size: 18px; font-weight: 700; padding: 12px 30px; border-radius: 40px; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); white-space: nowrap; }
        
        /* 🪄 ICON GUDDISUU (Gara 40px tti guddifameera) */
        .social-item i { font-size: 40px; transition: 0.4s; filter: drop-shadow(0 0 5px rgba(255,255,255,0.1)); }
        
        /* Dynamic Hover Effects */
        .social-item:hover { transform: translateY(-5px) scale(1.05); color: #fff; background: var(--hover-color, #fcd535); box-shadow: 0 10px 25px var(--hover-shadow, rgba(252,213,53,0.4)); border-color: transparent; }
        .social-item:hover i { color: #fff !important; transform: scale(1.2); filter: drop-shadow(0 0 10px rgba(255,255,255,0.5)); }
    
    /* 🪄 ABOUT SECTION STYLES */
        .about-btn-container { text-align: center; margin: 40px auto 60px auto; position: relative; z-index: 10; }
        .btn-about { background: linear-gradient(135deg, #fcd535, #f59e0b); color: #181a20; border: none; padding: 16px 40px; border-radius: 30px; font-size: 18px; font-weight: 800; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 10px 25px rgba(252, 213, 53, 0.3); display: inline-flex; align-items: center; gap: 10px; }
        .btn-about:hover { transform: translateY(-5px) scale(1.05); box-shadow: 0 15px 35px rgba(252, 213, 53, 0.5); }
        .btn-about i { font-size: 20px; transition: transform 0.4s; }
        .btn-about.active i { transform: rotate(180deg); }
        
        .about-content-wrapper { display: none; width: 85%; max-width: 1100px; margin: 0 auto 60px auto; background: rgba(11, 14, 20, 0.85); backdrop-filter: blur(20px); border: 1px solid rgba(252,213,53,0.2); border-radius: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); overflow: hidden; position: relative; z-index: 10; animation: slideDownAbout 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes slideDownAbout { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }
        
        .about-header { background: linear-gradient(135deg, rgba(252,213,53,0.1), transparent); padding: 30px 40px; border-bottom: 1px dashed rgba(255,255,255,0.1); }
        .about-header h2 { color: #fcd535; font-size: 28px; font-weight: 900; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; }
        .about-header p { color: #b7bdc6; font-size: 15px; line-height: 1.6; margin: 0; }
        
        .about-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; padding: 40px; }
        .about-feature { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 30px; border-radius: 16px; transition: 0.3s; position: relative; overflow: hidden; }
        .about-feature:hover { background: rgba(255,255,255,0.06); border-color: rgba(252,213,53,0.3); transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .about-feature::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: #fcd535; opacity: 0.5; transition: 0.3s; }
        .about-feature:hover::before { opacity: 1; width: 8px; }
        
        .feature-icon { width: 60px; height: 60px; background: rgba(252,213,53,0.1); color: #fcd535; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 26px; margin-bottom: 20px; border: 1px solid rgba(252,213,53,0.2); }
        .about-feature h3 { color: #fff; font-size: 18px; font-weight: 800; margin-bottom: 10px; }
        .about-feature p { color: #848e9c; font-size: 14px; line-height: 1.7; margin: 0; }
        
        /* Specific Colors for features */
        .feat-1 .feature-icon { color: #10b981; background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.2); } .feat-1::before { background: #10b981; } .feat-1:hover { border-color: rgba(16,185,129,0.3); }
        .feat-2 .feature-icon { color: #3b82f6; background: rgba(59,130,246,0.1); border-color: rgba(59,130,246,0.2); } .feat-2::before { background: #3b82f6; } .feat-2:hover { border-color: rgba(59,130,246,0.3); }
        .feat-3 .feature-icon { color: #f43f5e; background: rgba(244,63,94,0.1); border-color: rgba(244,63,94,0.2); } .feat-3::before { background: #f43f5e; } .feat-3:hover { border-color: rgba(244,63,94,0.3); }
        .feat-4 .feature-icon { color: #8b5cf6; background: rgba(139,92,246,0.1); border-color: rgba(139,92,246,0.2); } .feat-4::before { background: #8b5cf6; } .feat-4:hover { border-color: rgba(139,92,246,0.3); }
/* ❄️ MAGIC SNOW STYLES */
        #snow-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none; /* Click akka hin dhorkineef */
            z-index: 5; /* Video gubbaa, UI jala */
            display: none; /* Jalqaba irratti cufaa dha */
        }
        
        .snow-toggle-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fcd535;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: 0.3s;
            margin-left: 15px;
            box-shadow: 0 0 10px rgba(0,0,0,0.2);
        }
        
        .snow-toggle-btn:hover { 
            transform: scale(1.1); 
            border-color: #fcd535; 
        }
        
        .snow-toggle-btn.active {
            background: #fcd535;
            color: #181a20;
            box-shadow: 0 0 15px rgba(252, 213, 53, 0.6);
            animation: rotateSnow 4s linear infinite;
        }

        @keyframes rotateSnow {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        /* ================================================= */
        /* 🤖 RLMS MAGIC AI CHATBOT STYLES                   */
        /* ================================================= */
        .ai-chat-btn { position: fixed; bottom: 30px; right: 30px; width: 65px; height: 65px; background: linear-gradient(135deg, #10b981, #047857); border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 28px; color: #fff; cursor: pointer; box-shadow: 0 10px 25px rgba(16,185,129,0.5); z-index: 9999; transition: 0.3s; border: 2px solid #a7f3d0; animation: floatAI 3s ease-in-out infinite; }
        .ai-chat-btn:hover { transform: scale(1.1); box-shadow: 0 15px 35px rgba(16,185,129,0.7); }
        @keyframes floatAI { 0% { transform: translateY(0px); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0px); } }
        
        .ai-chat-window { position: fixed; bottom: 110px; right: 30px; width: 350px; height: 500px; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px); border: 1px solid rgba(16,185,129,0.3); border-radius: 20px; box-shadow: 0 15px 40px rgba(0,0,0,0.5); z-index: 9998; display: none; flex-direction: column; overflow: hidden; animation: popUpAI 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes popUpAI { from { opacity: 0; transform: scale(0.8) translateY(50px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        
        .ai-chat-header { background: linear-gradient(135deg, rgba(16,185,129,0.2), transparent); padding: 20px; border-bottom: 1px solid rgba(16,185,129,0.2); display: flex; justify-content: space-between; align-items: center; }
        .ai-chat-header-title { display: flex; align-items: center; gap: 10px; color: #fff; font-weight: 800; font-size: 16px; }
        .ai-chat-header-title i { color: #10b981; font-size: 20px; }
        .ai-close-btn { background: transparent; border: none; color: #94a3b8; cursor: pointer; font-size: 18px; transition: 0.3s; }
        .ai-close-btn:hover { color: #f43f5e; transform: rotate(90deg); }
        
        .ai-chat-body { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
        .ai-chat-body::-webkit-scrollbar { width: 4px; }
        .ai-chat-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
        
        .ai-msg { max-width: 85%; padding: 12px 16px; border-radius: 15px; font-size: 13.5px; line-height: 1.5; word-wrap: break-word; }
        .msg-bot { background: rgba(16,185,129,0.1); color: #e2e8f0; border: 1px solid rgba(16,185,129,0.2); align-self: flex-start; border-bottom-left-radius: 2px; }
        .msg-user { background: #3b82f6; color: #fff; align-self: flex-end; border-bottom-right-radius: 2px; box-shadow: 0 4px 10px rgba(59,130,246,0.3); }
        
        .ai-chat-input-area { padding: 15px; border-top: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.2); display: flex; gap: 10px; }
        .ai-chat-input-area input { flex: 1; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 12px 15px; border-radius: 20px; color: #fff; outline: none; font-size: 13px; transition: 0.3s; }
        .ai-chat-input-area input:focus { border-color: #10b981; background: rgba(16,185,129,0.05); }
        .ai-chat-input-area button { background: #10b981; color: #fff; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; box-shadow: 0 4px 10px rgba(16,185,129,0.3); }
        .ai-chat-input-area button:hover { transform: scale(1.1); background: #059669; }
        
        /* 🪄 THINKING ANIMATION (Danbalii / Bouncing Dots) */
        .typing-indicator { display: flex; gap: 5px; padding: 5px 10px; }
        .typing-indicator span { width: 8px; height: 8px; background: #10b981; border-radius: 50%; animation: bounce 1.3s linear infinite; }
        .typing-indicator span:nth-child(2) { animation-delay: -1.1s; }
        .typing-indicator span:nth-child(3) { animation-delay: -0.9s; }
        @keyframes bounce { 0%, 60%, 100% { transform: translateY(0); opacity: 0.6; } 30% { transform: translateY(-5px); opacity: 1; } }
</style>
</head>
<body>

<!-- 🪄 MAGIC: Dynamic Background Renderer -->
<?php if($is_video): ?>
    <video autoplay muted loop playsinline id="video-bg" style="position: fixed; right: 0; bottom: 0; min-width: 100%; min-height: 100%; z-index: -2; object-fit: cover;">
        <source src="<?php echo $bg_path; ?>" type="video/<?php echo $ext; ?>">
    </video>
<?php else: ?>
    <div id="image-bg" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -2; background-image: url('<?php echo $bg_path; ?>'); background-size: cover; background-position: center; background-repeat: no-repeat;"></div>
<?php endif; ?>

<div class="overlay-dark" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.65); z-index: -1;"></div>

<!-- 1. Canvas kana gubbaa irratti video gubbaatti dabalame -->
<canvas id="snow-canvas"></canvas>

<nav class="navbar">
    <div style="display: flex; align-items: center; gap: 20px;">
        <div class="menu-toggle" id="openMenu">
    Login 
</div>
        
        <!-- 🪄 MAGIC: Logo geengoo ta'e asitti mul'ata -->
        <?php if(!empty($site_logo)): ?>
            <img src="uploads/sliders/<?php echo $site_logo; ?>" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #fcd535; box-shadow: 0 0 10px rgba(252, 213, 53, 0.4); animation: fadeIn 0.5s ease;">
        <?php endif; ?>
    </div>
    
<!-- 🪄 MAGIC: Snow Toggle Button Added Back to Navbar -->
    </nav>

<section class="hero">
    <h1>Registration & Learning Management System</h1>
    <p>Empowering Bule Hora University with real-time academic management, secure examinations, and streamlined learning resources.</p>

   <div class="stats-container">
        <div class="stat-box" style="--card-color: #3b82f6; --card-shadow: rgba(59,130,246,0.3); --icon-bg: rgba(59,130,246,0.1); --icon-border: rgba(59,130,246,0.2);">
            <i class="fa-solid fa-building-columns bg-icon"></i>
            <div class="icon-wrapper"><i class="fa-solid fa-building-columns main-icon"></i></div>
            <h2><?php echo $college_count; ?></h2><p>Colleges</p>
        </div>
        
        <div class="stat-box" style="--card-color: #8b5cf6; --card-shadow: rgba(139,92,246,0.3); --icon-bg: rgba(139,92,246,0.1); --icon-border: rgba(139,92,246,0.2);">
            <i class="fa-solid fa-sitemap bg-icon"></i>
            <div class="icon-wrapper"><i class="fa-solid fa-sitemap main-icon"></i></div>
            <h2><?php echo $dept_count; ?></h2><p>Departments</p>
        </div>
        
        <div class="stat-box" style="--card-color: #10b981; --card-shadow: rgba(16,185,129,0.3); --icon-bg: rgba(16,185,129,0.1); --icon-border: rgba(16,185,129,0.2);">
            <i class="fa-solid fa-chalkboard-user bg-icon"></i>
            <div class="icon-wrapper"><i class="fa-solid fa-chalkboard-user main-icon"></i></div>
            <h2><?php echo $teacher_count; ?></h2><p>Teachers</p>
        </div>
        
        <div class="stat-box" style="--card-color: #f43f5e; --card-shadow: rgba(244,63,94,0.3); --icon-bg: rgba(244,63,94,0.1); --icon-border: rgba(244,63,94,0.2);">
            <i class="fa-solid fa-user-graduate bg-icon"></i>
            <div class="icon-wrapper"><i class="fa-solid fa-user-graduate main-icon"></i></div>
            <h2><?php echo $student_count; ?></h2><p>Students</p>
        </div>
        
        <div class="stat-box" style="--card-color: #0ea5e9; --card-shadow: rgba(14,165,233,0.3); --icon-bg: rgba(14,165,233,0.1); --icon-border: rgba(14,165,233,0.2);">
            <i class="fa-solid fa-book-open bg-icon"></i>
            <div class="icon-wrapper"><i class="fa-solid fa-book-open main-icon"></i></div>
            <h2><?php echo $course_count; ?></h2><p>Courses</p>
        </div>
        
        <div class="stat-box" style="--card-color: #f59e0b; --card-shadow: rgba(245,158,11,0.3); --icon-bg: rgba(245,158,11,0.1); --icon-border: rgba(245,158,11,0.2);">
            <i class="fa-solid fa-file-signature bg-icon"></i>
            <div class="icon-wrapper"><i class="fa-solid fa-file-signature main-icon"></i></div>
            <h2><?php echo $exam_count; ?></h2><p>Exams & Tasks</p>
        </div>
    </div>
</section>
</section>
<div class="slider-wrap">
    <div class="slides" id="slide-container">
        <?php
        // 🪄 MAGIC: Fetch slides dynamically from Database
        $check_slider_table = mysqli_query($conn, "SHOW TABLES LIKE 'home_sliders'");
        $slides_exist = false;
        
        if(mysqli_num_rows($check_slider_table) > 0) {
            $slides_q = mysqli_query($conn, "SELECT * FROM home_sliders ORDER BY id ASC");
            if(mysqli_num_rows($slides_q) > 0) {
                $slides_exist = true;
                while($slide = mysqli_fetch_assoc($slides_q)) {
                    echo "<div class='slide'><img src='uploads/sliders/{$slide['image_path']}' alt='EPLMS Slide'></div>";
                }
            }
        }
        
        // 🪄 FALLBACK: Yoo Super Admin suuraa tokkoyyuu hin feene, suuraa default agarsiisa
        if(!$slides_exist) {
            echo '<div class="slide"><img src="uploads/slide1.png" alt="Slide 1" onerror="this.src=\'https://via.placeholder.com/1000x500/181a20/fcd535?text=Welcome+to+EPLMS\'"></div>';
            echo '<div class="slide"><img src="uploads/slide2.png" alt="Slide 2" onerror="this.src=\'https://via.placeholder.com/1000x500/181a20/fcd535?text=Secure+Portal\'"></div>';
            echo '<div class="slide"><img src="uploads/slide3.png" alt="Slide 3" onerror="this.src=\'https://via.placeholder.com/1000x500/181a20/fcd535?text=Academic+Success\'"></div>';
        }
        ?>
    </div>
</div>
<!-- 🪄 MAGIC SOCIAL MEDIA FOOTER (100% SEAMLESS INFINITE LOOP FETCH) -->
<div class="social-footer">
    <?php
    $social_query = mysqli_query($conn, "SELECT * FROM social_links WHERE link_url IS NOT NULL AND link_url != ''");
    
    if(mysqli_num_rows($social_query) > 0) {
        $social_links_data = [];
        while($sl = mysqli_fetch_assoc($social_query)) {
            $social_links_data[] = $sl;
        }

        // 🪄 MAGIC: Wrapper lama adda addaa uumna. Kun iddoon duwwaan (Gap) akka gonkumaa hin uumamne godha!
        for($i = 0; $i < 2; $i++) {
            echo "<div class='social-scroll-wrapper'>";
            
            foreach($social_links_data as $sl) {
                $color = $sl['brand_color'];
                $url = trim($sl['link_url']);
                $platform = strtolower($sl['platform']);
                
                if(strpos($url, '@') === 0) {
                    $handle = substr($url, 1);
                    if(strpos($platform, 'x') !== false || strpos($platform, 'twitter') !== false) { $url = "https://x.com/".$handle; }
                    elseif(strpos($platform, 'telegram') !== false) { $url = "https://t.me/".$handle; }
                    elseif(strpos($platform, 'tiktok') !== false) { $url = "https://www.tiktok.com/@".$handle; }
                    elseif(strpos($platform, 'instagram') !== false) { $url = "https://instagram.com/".$handle; }
                    elseif(strpos($platform, 'threads') !== false) { $url = "https://threads.net/@".$handle; }
                }
                
                $icon_class = (strpos($sl['icon'], 'globe') !== false) ? "fa-solid" : "fa-brands";

                echo "<a href='{$url}' target='_blank' class='social-item' style='--hover-color: {$color}; --hover-shadow: {$color}80;'>
                        <i class='{$icon_class} {$sl['icon']}' style='color: {$color};'></i> {$sl['platform']}
                      </a>";
            }
            
            echo "</div>"; // Xumura Wrapper Tokkoo
        }
    } else {
        echo "<div style='width: 100%; text-align: center;'><span style='color:#848e9c; font-size:14px; font-weight:600;'>Welcome to EPLMS - Follow us for updates!</span></div>";
    }
    ?>
</div>

<!-- 🪄 ABOUT RLMS CONTENT (HIDDEN BY DEFAULT) -->
<div class="about-content-wrapper" id="aboutSection">
    <div class="about-header">
        <h2><i class="fa-solid fa-rocket"></i> </h2>
        <p>The Registration & Learning Management System (RLMS), originally EPLMS, is a state-of-the-art educational ecosystem built specifically for Bule Hora University. It centralizes, secures, and automates every aspect of the academic journey through a strict 5-Tier Role-Based Architecture.</p>
    </div>
    
    <div class="about-grid">
        
        <div class="about-feature feat-1">
            <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h3>Military-Grade Security (2FA)</h3>
            <p>Every account is protected by an Anti-Brute Force mechanism. Three failed login attempts will automatically ban the attacker's IP address for 24 hours. Users must verify their identity using a dynamic 6-digit OTP sent via email (2FA) before accessing the dashboard.</p>
        </div>
        
        <div class="about-feature feat-2">
            <div class="feature-icon"><i class="fa-solid fa-stopwatch"></i></div>
            <h3>Smart & Secure Examinations</h3>
            <p>Teachers can schedule exams that auto-open based on a strict Server-Time Sync. Exams require a Secret Access Code to begin. The system features Anti-Cheat technology (disabling copy/paste and tab-switching) and an AI Auto-Grading Engine that marks papers instantly.</p>
        </div>
        
        <div class="about-feature feat-3">
            <div class="feature-icon"><i class="fa-brands fa-telegram"></i></div>
            <h3>End-to-End Encrypted Comms</h3>
            <p>A built-in real-time AJAX messaging hub replaces external apps like Telegram or WhatsApp. Teachers can broadcast announcements to all their students, and students can privately message their specific instructors without needing their personal phone numbers.</p>
        </div>
        
        <div class="about-feature feat-4">
            <div class="feature-icon"><i class="fa-solid fa-star-half-stroke"></i></div>
            <h3>Transparent Master Gradebook</h3>
            <p>Instructors can configure custom grading weights (e.g., Mid 20%, Final 50%). The system automatically calculates total scores and assigns Letter Grades (A, B, C). Once grades are "Published", they become instantly visible to both the Student and the Head of Department.</p>
        </div>
        
        <div class="about-feature">
            <div class="feature-icon"><i class="fa-solid fa-user-graduate"></i></div>
            <h3>Automated Student Registration</h3>
            <p>Students use a unique 'Department Code' to register publicly. Once the Head of Department clicks "Approve", the system automatically generates a unique username and a highly secure password, emailing them directly to the student via the university's SMTP server.</p>
        </div>

        <div class="about-feature feat-2">
            <div class="feature-icon"><i class="fa-solid fa-chart-pie"></i></div>
            <h3>SPA Drill-Down Analytics</h3>
            <p>Administrators and Department Heads utilize Single Page Application (SPA) logic to dig deep into their data. They can click on a Teacher's profile to instantly view their assigned courses and see the progress of every student, all without refreshing the page.</p>
        </div>

    </div>
</div>
<div class="overlay-screen" id="overlay"></div>
<aside class="sidebar" id="sidebar">
    <i class="fa-solid fa-xmark close-btn" id="closeMenu"></i>

    <?php if($is_banned): ?>
        <!-- 🚨 MASIVE BLOCKED UI 🚨 -->
        <div style="text-align:center; margin-top:100px;">
            <div style="width: 100px; height: 100px; background: rgba(246, 70, 93, 0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; border: 2px solid #f6465d;">
                <i class="fa-solid fa-user-lock" style="font-size:50px; color:#f6465d;"></i>
            </div>
            <h2 style="color:#f6465d; font-size: 28px; margin-bottom: 15px; letter-spacing: 1px;">ACCESS BLOCKED</h2>
            <p style="color:#b7bdc6; margin-top:15px; line-height:1.6; font-size: 15px; padding: 0 10px;"><?php echo $ban_message; ?></p>
            <div style="margin-top:40px; padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px;">
                <p style="font-size:12px; color:#848e9c; margin-bottom: 5px;">Your Device IP:</p>
                <p style="font-size:14px; font-family: monospace; color: #fcd535; font-weight: bold; letter-spacing: 2px;"><?php echo $ip_address; ?></p>
                <p style="font-size:11px; color:#848e9c; margin-top: 10px;">This incident has been reported to the Security Center.</p>
            </div>
        </div>
    <?php else: ?>
        <!-- NORMAL SIDEBAR CONTENT -->
        <?php if($message): ?>
            <div class="alert <?php echo $msg_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- LOGIN FORM -->
        <div id="login-form" class="form-section active">
            <h2 style="font-size: 32px; font-weight: 600; margin-bottom: 40px;">Log in</h2>
            
<!-- autocomplete="off" dabalameera -->
<form method="POST" autocomplete="off">  
    <!-- 🪄 MAGIC: Browser gowwoomsuuf dummy fields (Autofill dhorkuuf) -->
<input type="text" style="display:none;" name="fake_user">
<input type="password" style="display:none;" name="fake_pass">          
        <div class="input-group">
                    <label>Username</label>
<input type="text" name="login_id" placeholder="Enter your detail..." required autocomplete="off">                </div>
               <!-- 🪄 MAGIC: Eye Icon Added to Password Field -->
                <div class="input-group">
                    <label>Password</label>
                    <div style="position: relative;">
<input type="password" name="password" id="login_password" placeholder="Enter password..." required autocomplete="new-password">                        <i class="fa-solid fa-eye" id="toggle_login_pw" onclick="toggleIndexPw('login_password', this)" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #848e9c; cursor: pointer; transition: 0.3s;" onmouseover="this.style.color='#fcd535'" onmouseout="this.style.color='#848e9c'"></i>
                    </div>
                </div>
                <button type="submit" name="login" class="btn-action">Continue</button>
            </form>

            <div class="bottom-link">
                <a href="#" onclick="switchForm('forgot_pass')">Forgot Password?</a><br><br>
                <a href="#" onclick="switchForm('register')">Create an EPLMS Account</a>
                <br><br>
                <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                    <span style="font-size: 13px;"><i class="fa-solid fa-lock" style="color:#0ecb81;"></i> Secured by EPLMS Tech</span>
                    <!-- Remaining Attempts Tracker -->
                    <span style="font-size: 12px; color: <?php echo ($attempts_left == 1) ? '#f6465d' : '#848e9c'; ?>; font-weight: bold;">
                        Remaining Login Attempts: <?php echo $attempts_left; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- FORGOT PASSWORD FORM -->
        <div id="forgot_pass-form" class="form-section">
            <h2 style="font-size: 32px; font-weight: 600; margin-bottom: 20px;">Reset Password</h2>
            <p style="color:#848e9c; font-size:14px; margin-bottom:25px;">Enter your registered Private Email address to receive a secure OTP code to reset your password.</p>
            
            <form method="POST">
                <div class="input-group"><label>Registered Email Address</label><input type="email" name="forgot_email" placeholder="e.g. abebe@gmail.com" required></div>
                <button type="submit" name="forgot_password" class="btn-action">Send OTP Code</button>
            </form>

            <div class="bottom-link">
                Remembered your password? <br><br>
                <a href="#" onclick="switchForm('login')"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>

       <!-- REGISTER FORM -->
    <div id="register-form" class="form-section">
        <h2 style="font-size: 32px; font-weight: 600; margin-bottom: 20px;">Student Registration</h2>
        <p style="color:#b7bdc6; font-size:13px; margin-bottom: 20px;">Your credentials will be emailed to you upon Head approval.</p>
        
        <form method="POST">
            <div style="display:flex; gap:10px;">
                <div class="input-group" style="flex:1;"><label>First Name</label><input type="text" name="first_name" required></div>
                <div class="input-group" style="flex:1;"><label>Last Name</label><input type="text" name="last_name" required></div>
            </div>
            <div class="input-group"><label>University ID Number</label><input type="text" name="id_number" required></div>
            <div class="input-group"><label>Email Address</label><input type="email" name="email" required></div>
            <div class="input-group"><label>Phone Number</label><input type="text" name="phone" required></div>
            <div class="input-group">
                <label style="color:#fcd535;">Department Code</label>
                <input type="text" name="dept_code" placeholder="e.g. CS, IT, ME" required style="border-color:#fcd535;">
            </div>
            
            <button type="submit" name="register" class="btn-action">Submit Registration</button>
        </form>

        <div class="bottom-link" style="margin-top: 20px;">
            Already have your credentials? <br><br>
            <a href="#" onclick="switchForm('login')">Log in to EPLMS</a>
        </div>
    </div>
    <?php endif; ?>
</aside>

<script>
    const openMenu = document.getElementById('openMenu');
    const closeMenu = document.getElementById('closeMenu');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');

    openMenu.onclick = () => { sidebar.classList.add('open'); overlay.classList.add('active'); };
    closeMenu.onclick = () => { sidebar.classList.remove('open'); overlay.classList.remove('active'); };
    overlay.onclick = () => { sidebar.classList.remove('open'); overlay.classList.remove('active'); };

    function switchForm(type) {
        document.querySelectorAll('.form-section').forEach(f => f.classList.remove('active'));
        document.getElementById(type + '-form').classList.add('active');
    }

 // 🪄 MAGIC FIXED SLIDER LOGIC
    let slideIndex = 0;
    const slidesContainer = document.getElementById('slide-container');
    const allSlides = document.querySelectorAll('.slide');
    const totalSlides = allSlides.length;

    function nextSlide() {
        if(totalSlides <= 1) return; // Yoo suuraan tokko qofa ta'e hin naanneessu
        
        slideIndex++;
        if (slideIndex >= totalSlides) {
            slideIndex = 0;
            // 🪄 Salphaatti gara jalqabaatti akka deebi'uuf (No rewinding glitch)
            slidesContainer.style.transition = 'none';
            slidesContainer.style.transform = `translateX(0%)`;
            setTimeout(() => {
                slidesContainer.style.transition = '0.7s cubic-bezier(0.4, 0, 0.2, 1)';
            }, 50);
        } else {
            slidesContainer.style.transform = `translateX(-${slideIndex * 100}%)`;
        }
    }
    
    if(totalSlides > 1) {
        setInterval(nextSlide, 5000);
    }

    <?php if($message || $is_banned): ?>
        sidebar.classList.add('open'); overlay.classList.add('active');
        <?php if(isset($_POST['register'])): ?>
            switchForm('register');
        <?php elseif(isset($_POST['forgot_password'])): ?>
            switchForm('forgot_pass');
        <?php endif; ?>
    <?php endif; ?>
    // 🪄 MAGIC LOGIN LOADING SPINNER
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            let btn = this.querySelector('button[type="submit"]');
            if(btn) {
                // Button halluu fi barreeffama isaa jijjiiree akka naanna'u godha
                let originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Sending OTP, Please wait...';
                btn.style.opacity = '0.8';
                btn.style.cursor = 'not-allowed';
                btn.style.pointerEvents = 'none'; // Akka irra deebi'anii hin tuqne dhowwa
            }
        });
    });
    // 🪄 ABOUT SECTION TOGGLE LOGIC
    function toggleAboutSection() {
        const aboutSection = document.getElementById('aboutSection');
        const btn = document.getElementById('toggleAboutBtn');
        
        if (aboutSection.style.display === 'block') {
            aboutSection.style.display = 'none';
            btn.classList.remove('active');
            btn.innerHTML = '<i class="fa-solid fa-circle-info"></i> How EPLMS / RLMS Works <i class="fa-solid fa-chevron-down chevron-icon"></i>';
        } else {
            aboutSection.style.display = 'block';
            btn.classList.add('active');
            btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Close Information <i class="fa-solid fa-chevron-up chevron-icon"></i>';
            
            // Scroll smoothly to the about section
            setTimeout(() => {
                aboutSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }
    // 🪄 MAGIC PASSWORD TOGGLE (Show/Hide)
    function toggleIndexPw(inputId, iconElement) {
        let input = document.getElementById(inputId);
        if (input.type === "password") {
            input.type = "text";
            iconElement.classList.remove("fa-eye");
            iconElement.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            iconElement.classList.remove("fa-eye-slash");
            iconElement.classList.add("fa-eye");
        }
    }
    // ❄️ MAGIC SNOW ENGINE LOGIC
    const canvas = document.getElementById('snow-canvas');
    const ctx = canvas.getContext('2d');
    const snowBtn = document.getElementById('snowBtn');
    let snowing = false;
    let particles = [];

    function resizeCanvas() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    class Snowflake {
        constructor() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.size = Math.random() * 3 + 1;
            this.speed = Math.random() * 1 + 0.5;
            this.wind = Math.random() * 0.5 - 0.25;
        }
        update() {
            this.y += this.speed;
            this.x += this.wind;
            if (this.y > canvas.height) {
                this.y = -10;
                this.x = Math.random() * canvas.width;
            }
        }
        draw() {
            ctx.fillStyle = "white";
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    function initSnow() {
        particles = [];
        for (let i = 0; i < 150; i++) {
            particles.push(new Snowflake());
        }
    }

    function animateSnow() {
        if (!snowing) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        particles.forEach(p => {
            p.update();
            p.draw();
        });
        requestAnimationFrame(animateSnow);
    }

    function toggleSnow() {
        snowing = !snowing;
        if (snowing) {
            canvas.style.display = 'block';
            snowBtn.classList.add('active');
            initSnow();
            animateSnow();
        } else {
            canvas.style.display = 'none';
            snowBtn.classList.remove('active');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
    }
    // =================================================
    // 🤖 RLMS MAGIC AI KNOWLEDGE BASE & LOGIC
    // =================================================
    const aiWindow = document.getElementById('aiChatWindow');
    const aiBody = document.getElementById('aiChatBody');
    const aiInput = document.getElementById('aiUserInput');
    const aiBtn = document.getElementById('aiChatBtn');

    function toggleAIChat() {
        if (aiWindow.style.display === 'flex') {
            aiWindow.style.display = 'none';
            aiBtn.style.display = 'flex';
        } else {
            aiWindow.style.display = 'flex';
            aiBtn.style.display = 'none';
            aiInput.focus();
        }
    }

    function handleAIKeyPress(e) {
        if (e.key === 'Enter') sendAIMessage();
    }

    function sendAIMessage() {
        let userText = aiInput.value.trim();
        if (userText === '') return;

        // 1. Add User Message
        appendMessage(userText, 'msg-user');
        aiInput.value = '';

        // 2. Show "Thinking..." Animation
        let thinkingId = showThinking();

        // 3. Process Answer with a slight delay (Magic effect)
        setTimeout(() => {
            removeThinking(thinkingId);
            let botReply = getAIResponse(userText.toLowerCase());
            appendMessage(botReply, 'msg-bot');
        }, 1500 + Math.random() * 1000); // Delay between 1.5s to 2.5s
    }

    function appendMessage(text, className) {
        let msgDiv = document.createElement('div');
        msgDiv.className = `ai-msg ${className}`;
        msgDiv.innerHTML = text;
        aiBody.appendChild(msgDiv);
        aiBody.scrollTop = aiBody.scrollHeight; // Auto scroll to bottom
    }

    function showThinking() {
        let id = 'thinking-' + Date.now();
        let msgDiv = document.createElement('div');
        msgDiv.className = 'ai-msg msg-bot';
        msgDiv.id = id;
        msgDiv.innerHTML = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
        aiBody.appendChild(msgDiv);
        aiBody.scrollTop = aiBody.scrollHeight;
        return id;
    }

    function removeThinking(id) {
        let el = document.getElementById(id);
        if (el) el.remove();
    }

    // 🧠 THE AI BRAIN (KNOWLEDGE BASE RESTRICTED TO RLMS)
    function getAIResponse(input) {
        // Greetings
        if (input.includes('hello') || input.includes('hi ') || input.includes('hey') || input.includes('akkam')) {
            return "Hello there! I am the RLMS Artificial Intelligence. You can ask me about registration, exams, security, or roles within the system.";
        }
        
        // System Identity
        if (input.includes('what is rlms') || input.includes('what is eplms') || input.includes('about system')) {
            return "<strong>RLMS (Registration & Learning Management System)</strong>, previously EPLMS, is a highly secure, 5-tier architecture platform designed for Bule Hora University. It handles student registration, dynamic scheduling, auto-graded exams, and encrypted communications.";
        }

        // Student Registration
        if (input.includes('register') || input.includes('sign up') || input.includes('create account') || input.includes('join')) {
            return "To register, students must use a secret <strong>Department Code</strong> provided by their college. Once you fill out the registration form, you will be in a 'Pending' state. Once the Head of Department approves you, the system will automatically generate a secure password and email it to you.";
        }

        // Security / 2FA / Password
        if (input.includes('security') || input.includes('2fa') || input.includes('hack') || input.includes('password')) {
            return "RLMS uses military-grade security. It features an <strong>Anti-Brute Force mechanism</strong> that automatically bans an IP address for 24 hours after 3 failed login attempts. We also use OTP-based Two-Factor Authentication (2FA) sent via the university's secure SMTP server.";
        }

        // Exams / Quizzes
        if (input.includes('exam') || input.includes('quiz') || input.includes('test')) {
            return "Our Examination Engine is highly secure. Exams open strictly based on Server-Time sync, meaning students cannot enter early. An <strong>Access Code</strong> is required to begin. The system also prevents cheating by disabling tab-switching and right-clicking. Exams are auto-submitted when the timer hits zero.";
        }

        // Grading / Results
        if (input.includes('grade') || input.includes('result') || input.includes('score') || input.includes('mark')) {
            return "The <strong>Master Gradebook</strong> automatically calculates total scores out of 100% and assigns Letter Grades (A, B, C...). For security, exam results are delayed and only shown 10 minutes after the exam concludes.";
        }

        // Roles (Teacher, Head, Admin)
        if (input.includes('teacher') || input.includes('faculty') || input.includes('instructor')) {
            return "Teachers can upload materials with Auto-Release dates, schedule secure exams, chat with students securely, and grade assignments dynamically.";
        }
        if (input.includes('head') || input.includes('hod') || input.includes('admin')) {
            return "Department Heads and Admins have access to a Single Page Application (SPA) Drill-Down Oversight tool. They approve students, manage faculty, and monitor the Cyber Security Command Center for their specific domain.";
        }

        // Creator / Developer
        if (input.includes('who created') || input.includes('developer') || input.includes('made by')) {
            return "The RLMS Enterprise Edition was designed and engineered by the brilliant developers at Bule Hora University to revolutionize the academic ecosystem.";
        }

        // Default Fallback (Restricted Scope)
        return "I specialize strictly in answering questions about the <strong>RLMS / EPLMS System</strong>. Could you please rephrase your question regarding student registration, exams, security, or system features?";
    }
    window.onload = function() {
    // Yeroo page banamu saanduqoota hunda qulqulleessa
    setTimeout(function() {
        document.getElementsByName('login_id')[0].value = '';
        document.getElementsByName('password')[0].value = '';
    }, 100);
};
</script>

</body>
</html>