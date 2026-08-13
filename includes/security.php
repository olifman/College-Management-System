<?php
// includes/security.php

// 1. Function IP Address namaa qulqulluun argachuu
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    else return $_SERVER['REMOTE_ADDR'];
}

// 2. Function IP Address Block ta'uu isaa mirkaneessu
function isIpBanned($conn, $ip) {
    $sql = "SELECT id FROM blocked_ips WHERE ip_address = ? AND expires_at > NOW()";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return (mysqli_num_rows($result) > 0);
}

// 3. Function Yaalii Dogongoraa Lakkaa'uu (Brute-Force Check)
function checkFailedAttempts($conn, $ip, $username) {
    // Daqiiqaa 15 darban keessatti yeroo meeqa dogongore?
    $sql = "SELECT COUNT(id) as failed_count FROM login_logs 
            WHERE ip_address = ? AND status = 'failed' AND attempt_time > NOW() - INTERVAL 15 MINUTE";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    $result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    
    if($result['failed_count'] >= 5) {
        // Yeroo 5 ol yoo ta'e, IP isaa Sa'aatii 24f Block godhi
        $ban_reason = "Too many failed login attempts for user: $username";
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $ban_sql = "INSERT IGNORE INTO blocked_ips (ip_address, ban_reason, expires_at) VALUES (?, ?, ?)";
        $ban_stmt = mysqli_prepare($conn, $ban_sql);
        mysqli_stmt_bind_param($ban_stmt, "sss", $ip, $ban_reason, $expires);
        mysqli_stmt_execute($ban_stmt);
        
        return true; // IP Banned
    }
    return false; // Safe
}

// 4. Function OTP (Dijitii 6) Uumuu fi Save gochuu
function generateAndSendOTP($conn, $user_id, $role, $email, $name, $admin_email_sender) {
    $otp = sprintf("%06d", mt_rand(100000, 999999)); // Dijitii 6 qulqulluu
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes')); // Daqiiqaa 10 qofa hojjeta
    
    // OTP moofaa haqii haaraa galchi
    mysqli_query($conn, "UPDATE otp_requests SET is_used=1 WHERE user_id=$user_id AND role='$role'");
    
    $sql = "INSERT INTO otp_requests (user_id, role, otp_code, expires_at) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "isss", $user_id, $role, $otp, $expires);
    mysqli_stmt_execute($stmt);
    
    // Email Erguu (HTML Design)
    $subject = "EPLMS - Your Login Verification Code";
    $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: EPLMS Security <{$admin_email_sender}>\r\n";
    $htmlContent = "
    <div style='font-family:Arial,sans-serif; max-width:500px; margin:0 auto; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;'>
        <div style='background-color:#181a20; padding:20px; text-align:center;'>
            <h2 style='color:#fcd535; margin:0;'>EPLMS Security</h2>
        </div>
        <div style='padding:30px; background:#fff; color:#333; text-align:center;'>
            <h3 style='margin-top:0;'>Hello, {$name}</h3>
            <p>A login attempt was made to your account. To complete the login process, please use the following 6-digit verification code:</p>
            <div style='font-size:32px; font-weight:bold; letter-spacing:5px; color:#3b82f6; background:#f0f4f8; padding:15px; border-radius:8px; margin:20px 0;'>
                {$otp}
            </div>
            <p style='color:#e74c3c; font-size:12px;'>This code will expire in 10 minutes. Do not share this with anyone!</p>
        </div>
    </div>";

    @mail($email, $subject, $htmlContent, $headers);
    return $otp;
}

// 5. Function Log galmeessuu
function logActivity($conn, $username, $status) {
    $ip = getUserIP();
    $agent = $_SERVER['HTTP_USER_AGENT'];
    $sql = "INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssss", $username, $ip, $agent, $status);
    mysqli_stmt_execute($stmt);
}
?>