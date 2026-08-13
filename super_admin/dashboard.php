

<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include("../includes/config.php");

// ========================================================
// 🛡️ 1. SECURITY: SUPER ADMIN AUTHENTICATION
// ========================================================
if(!isset($_SESSION['username']) || $_SESSION['role'] != 'super_admin'){
    header("Location: ../index.php");
    exit();
}

$super_admin_name = $_SESSION['username'];
$super_admin_id = $_SESSION['user_id'];
$message = ""; $msg_type = "success";

// ========================================================
// 🚀 2. REAL-TIME CHAT AJAX API (NO PAGE RELOAD)
// ========================================================
if(isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];
    
    if($action == 'send_msg') {
        $msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        $rec_id = intval($_POST['chat_receiver_id']);
        $rec_role = mysqli_real_escape_string($conn, $_POST['chat_receiver_role']);
        $is_group = intval($_POST['chat_is_group']);

        if(!empty($msg)) {
            if($is_group == 1) {
                $target_table = ($rec_role == 'admin') ? 'admin' : (($rec_role == 'head') ? 'head' : 'teacher');
                $users = mysqli_query($conn, "SELECT id FROM `$target_table` WHERE is_deleted=0");
                
                while($u = mysqli_fetch_assoc($users)) {
                    $u_id = $u['id'];
                    mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($super_admin_id, 'super_admin', $u_id, '$rec_role', '$msg', 0, 0)");
                }
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($super_admin_id, 'super_admin', 0, '$rec_role', '📢 BROADCAST: $msg', 1, 1)");
            } else {
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($super_admin_id, 'super_admin', $rec_id, '$rec_role', '$msg', 0, 0)");
            }
        }
        echo json_encode(['status'=>'success']); exit();
    }
    if($action == 'edit_msg') {
        $msg_id = intval($_POST['msg_id']);
        $new_msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        mysqli_query($conn, "UPDATE messages SET is_edited=1, message='$new_msg' WHERE id=$msg_id AND sender_id=$super_admin_id AND sender_role='super_admin'");
        echo json_encode(['status'=>'success']); exit();
    }

    if($action == 'delete_msg') {
        $msg_id = intval($_POST['msg_id']);
        mysqli_query($conn, "UPDATE messages SET is_deleted=1 WHERE id=$msg_id AND sender_id=$super_admin_id AND sender_role='super_admin'");
        echo json_encode(['status'=>'success']); exit();
    }
    if($action == 'fetch_unread') {
        $my_id = isset($super_admin_id) ? $super_admin_id : (isset($admin_id) ? $admin_id : 0);
        $my_role = isset($super_admin_id) ? 'super_admin' : (isset($admin_id) ? 'admin' : '');
        
        $q = mysqli_query($conn, "SELECT sender_id, sender_role, COUNT(*) as c FROM messages WHERE receiver_id=$my_id AND receiver_role='$my_role' AND is_read=0 AND is_group=0 GROUP BY sender_id, sender_role");
        
        $data =[];
        $total = 0;
        while($r = mysqli_fetch_assoc($q)){
            $key = $r['sender_role'] . '_' . $r['sender_id'];
            $data[$key] = $r['c'];
            $total += $r['c'];
        }
        $data['total_all'] = $total;
        echo json_encode($data); 
        exit();
    }
   if($action == 'fetch_chat') {
        $rec_id = intval($_POST['receiver_id']);
        $rec_role = mysqli_real_escape_string($conn, $_POST['receiver_role']);
        $is_group = intval($_POST['is_group']);
        
        if($is_group == 0) {
            mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$super_admin_id AND receiver_role='super_admin'");
        }

        $query = ($is_group == 1) 
            ? "SELECT * FROM messages WHERE is_group=1 AND receiver_role='$rec_role' ORDER BY sent_at ASC" 
            : "SELECT * FROM messages WHERE is_group=0 AND ((sender_id=$super_admin_id AND sender_role='super_admin' AND receiver_id=$rec_id AND receiver_role='$rec_role') OR (sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$super_admin_id AND receiver_role='super_admin')) ORDER BY sent_at ASC";
        
        $res = mysqli_query($conn, $query);
        if(mysqli_num_rows($res) == 0) { echo "<div class='tg-placeholder'><i class='fa-solid fa-lock'></i><p>End-to-end encrypted. Say hello!</p></div>"; exit(); }

        $html = '';
        while($m = mysqli_fetch_assoc($res)){
            $is_me = ($m['sender_role'] == 'super_admin' && $m['sender_id'] == $super_admin_id);
            $align = $is_me ? 'chat-right' : 'chat-left';
            $time = date("M d, H:i", strtotime($m['sent_at']));
            
            $msg_text = nl2br(htmlspecialchars($m['message']));
            $status = '';
            
            if($m['is_deleted'] == 1) {
                $msg_text = "<i style='color:var(--danger); opacity:0.8;'><i class='fa-solid fa-ban'></i> This message was deleted</i>";
                $status = "<span style='color:var(--danger);'>Deleted</span>";
            } elseif($m['is_edited'] == 1) {
                $status = "<span style='opacity:0.6;'><i class='fa-solid fa-pen'></i> Edited</span>";
            }

            $oncontext = "";
            if($is_me && $m['is_deleted'] == 0) {
                $safe_msg = htmlspecialchars($m['message'], ENT_QUOTES);
                $oncontext = "oncontextmenu='showContextMenu(event, {$m['id']}, \"{$safe_msg}\"); return false;'";
            }

            $html .= "<div class='chat-msg-wrapper {$align}'>
                        <div class='chat-bubble' {$oncontext} style='cursor: context-menu;'>
                            <div class='chat-text'>{$msg_text}</div>
                            <div class='chat-meta'>{$time} {$status}</div>
                        </div>
                      </div>";
        }
        echo $html; exit();
    }
    if($action == 'update_social') {
        $id = intval($_POST['id']);
        $new_url = mysqli_real_escape_string($conn, trim($_POST['url']));
        mysqli_query($conn, "UPDATE social_links SET link_url='$new_url' WHERE id=$id");
        echo json_encode(['status'=>'success']); 
        exit();
    }
}
// ========================================================
// 🪄 MAGIC: ADD NEW SOCIAL LINK (AUTO-DETECT PLATFORM)
// ========================================================
if(isset($_POST['add_magic_social'])){
    $url = trim($_POST['new_social_url']);
    $platform = 'Website'; $icon = 'fa-globe'; $color = '#64748b'; // Default Fallback

    // 🪄 Auto-Detect Engine
    if(stripos($url, 'whatsapp.com') !== false || stripos($url, 'wa.me') !== false) {
        $platform = 'WhatsApp'; $icon = 'fa-whatsapp'; $color = '#25D366';
    } elseif(stripos($url, 'github.com') !== false) {
        $platform = 'GitHub'; $icon = 'fa-github'; $color = '#333333';
    } elseif(stripos($url, 'discord.gg') !== false || stripos($url, 'discord.com') !== false) {
        $platform = 'Discord'; $icon = 'fa-discord'; $color = '#5865F2';
    } elseif(stripos($url, 'snapchat.com') !== false) {
        $platform = 'Snapchat'; $icon = 'fa-snapchat'; $color = '#FFFC00';
    } elseif(stripos($url, 'reddit.com') !== false) {
        $platform = 'Reddit'; $icon = 'fa-reddit'; $color = '#FF4500';
    } elseif(stripos($url, 'pinterest.com') !== false) {
        $platform = 'Pinterest'; $icon = 'fa-pinterest'; $color = '#E60023';
    } elseif(stripos($url, 't.me') !== false || stripos($url, 'telegram') !== false) {
        $platform = 'Telegram'; $icon = 'fa-telegram'; $color = '#229ED9';
    } elseif(stripos($url, 'facebook.com') !== false) {
        $platform = 'Facebook'; $icon = 'fa-facebook'; $color = '#1877F2';
    } elseif(stripos($url, 'instagram.com') !== false) {
        $platform = 'Instagram'; $icon = 'fa-instagram'; $color = '#E4405F';
    }

    $url = mysqli_real_escape_string($conn, $url);
    mysqli_query($conn, "INSERT INTO social_links (platform, link_url, icon, brand_color) VALUES ('$platform', '$url', '$icon', '$color')");
    header("Location: dashboard.php?tab=social_media&msg=social_added"); exit();
}

// 🗑️ Delete Social Link
if(isset($_POST['delete_social'])){
    $s_id = intval($_POST['del_social_id']);
    mysqli_query($conn, "DELETE FROM social_links WHERE id=$s_id");
    header("Location: dashboard.php?tab=social_media&msg=social_deleted"); exit();
}
// ========================================================
// 🪄 MAGIC: HOME SLIDER MANAGEMENT LOGIC (SUPER ADMIN)
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS home_sliders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
if(!is_dir('../uploads/sliders')) { mkdir('../uploads/sliders', 0777, true); }

if(isset($_POST['upload_slide'])){
    if(isset($_FILES['slide_image']['name']) && !empty($_FILES['slide_image']['name'])){
        $ext = pathinfo($_FILES['slide_image']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp']; 
        
        if(in_array(strtolower($ext), $allowed)){
            $file_name = "slide_" . time() . "_" . rand(1000,9999) . "." . $ext;
            $target_path = "../uploads/sliders/" . $file_name;
            
            if(move_uploaded_file($_FILES['slide_image']['tmp_name'], $target_path)){
                mysqli_query($conn, "INSERT INTO home_sliders (image_path) VALUES ('$file_name')");
                $message = "New Slide added successfully!"; $msg_type = "success";
                echo "<script>window.location.href='dashboard.php?tab=social_media&msg=slide_added';</script>"; exit();
            } else { $message = "Failed to move uploaded file. Check folder permissions."; $msg_type = "error"; }
        } else { $message = "Invalid file format! Allowed: JPG, PNG, WEBP, GIF."; $msg_type = "error"; }
    }
}

if(isset($_POST['delete_slide'])){
    $s_id = intval($_POST['slide_id']);
    $s_q = mysqli_query($conn, "SELECT image_path FROM home_sliders WHERE id=$s_id");
    if($s_data = mysqli_fetch_assoc($s_q)){
        $file = "../uploads/sliders/" . $s_data['image_path'];
        if(file_exists($file)) { unlink($file); }
        mysqli_query($conn, "DELETE FROM home_sliders WHERE id=$s_id");
        $message = "Slide deleted successfully!"; $msg_type = "success";
        echo "<script>window.location.href='dashboard.php?tab=social_media&msg=slide_deleted';</script>"; exit();
    }
}
// ========================================================
// 🪄 MAGIC: DYNAMIC SITE LOGO FROM SLIDERS
// ========================================================
// 1. Column haaraa dabaluu
$chk_logo = mysqli_query($conn, "SHOW COLUMNS FROM system_settings LIKE 'site_logo'");
if(mysqli_num_rows($chk_logo) == 0){
    mysqli_query($conn, "ALTER TABLE system_settings ADD COLUMN site_logo VARCHAR(255) DEFAULT NULL");
}

// 2. Logic Logo filachuu
if(isset($_POST['set_logo'])){
    $logo_path = mysqli_real_escape_string($conn, $_POST['logo_path']);
    mysqli_query($conn, "UPDATE system_settings SET site_logo='$logo_path' WHERE id=1");
    echo "<script>window.location.href='dashboard.php?tab=social_media&msg=logo_set';</script>"; exit();
}
// ========================================================
// 🪄 MAGIC: LOGIN PAGE BACKGROUND VIDEO/IMAGE UPLOAD
// ========================================================
$chk_bg = mysqli_query($conn, "SHOW COLUMNS FROM system_settings LIKE 'login_bg_media'");
if(mysqli_num_rows($chk_bg) == 0){
    mysqli_query($conn, "ALTER TABLE system_settings ADD COLUMN login_bg_media VARCHAR(255) DEFAULT 'background.mp4'");
}

if(isset($_POST['update_bg'])){
    if(isset($_FILES['bg_file']['name']) && !empty($_FILES['bg_file']['name'])){
        $ext = strtolower(pathinfo($_FILES['bg_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['mp4', 'webm', 'jpg', 'jpeg', 'png', 'webp'];
        
        if(in_array($ext, $allowed)){
            $file_name = "bg_" . time() . "." . $ext;
            $target_path = "../uploads/" . $file_name;
            
            if(move_uploaded_file($_FILES['bg_file']['tmp_name'], $target_path)){
                mysqli_query($conn, "UPDATE system_settings SET login_bg_media='$file_name' WHERE id=1");
                echo "<script>window.location.href='dashboard.php?tab=social_media&msg=bg_updated';</script>"; exit();
            } else { $message = "Failed to upload file."; $msg_type = "error"; }
        } else { $message = "Invalid format! Only MP4, WEBM, JPG, PNG allowed."; $msg_type = "error"; }
    }
}
// ========================================================
// 🔧 3. AUTO-DATABASE SETUP & MIGRATION
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS admin_activities (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, action_type VARCHAR(100) NOT NULL, details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE CASCADE)");

function addColSafe($conn, $table, $col, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if(mysqli_num_rows($res) == 0) mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

addColSafe($conn, 'colleges', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'colleges', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
addColSafe($conn, 'admin', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'admin', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
addColSafe($conn, 'super_admin', 'profile_pic', "VARCHAR(255) DEFAULT 'default_sa.png'");
addColSafe($conn, 'messages', 'is_edited', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'messages', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'super_admin', 'phone', 'VARCHAR(20) DEFAULT NULL');
addColSafe($conn, 'super_admin', 'two_factor_enabled', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'super_admin', 'login_alerts', 'TINYINT(1) DEFAULT 1');
addColSafe($conn, 'super_admin', 'public_email', 'VARCHAR(100) DEFAULT NULL');
addColSafe($conn, 'super_admin', 'app_password', 'VARCHAR(255) DEFAULT NULL');
addColSafe($conn, 'super_admin', 'profile_locked', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'admin', 'profile_locked', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'head', 'profile_pic', "VARCHAR(255) DEFAULT 'default_head.png'");
addColSafe($conn, 'head', 'profile_locked', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'teacher', 'profile_pic', "VARCHAR(255) DEFAULT 'default_teacher.png'");
addColSafe($conn, 'teacher', 'profile_locked', 'TINYINT(1) DEFAULT 0');
addColSafe($conn, 'student', 'profile_pic', "VARCHAR(255) DEFAULT 'default_student.png'");
addColSafe($conn, 'student', 'profile_locked', 'TINYINT(1) DEFAULT 0');

mysqli_query($conn, "DELETE FROM colleges WHERE is_deleted=1 AND deleted_at < NOW() - INTERVAL 30 DAY");
mysqli_query($conn, "DELETE FROM admin WHERE is_deleted=1 AND deleted_at < NOW() - INTERVAL 30 DAY");

// Fetch SA Profile Info
$sa_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM super_admin WHERE id=$super_admin_id"));
$profile_pic = !empty($sa_info['profile_pic']) && file_exists("../uploads/".$sa_info['profile_pic']) ? "../uploads/".$sa_info['profile_pic'] : "https://ui-avatars.com/api/?name=".urlencode($sa_info['name'])."&background=fcd535&color=000";

date_default_timezone_set('Africa/Addis_Ababa');

// Greeting Logic (PHP)
$current_hour = date('H');
if ($current_hour >= 6 && $current_hour < 12) {
    $greeting = "Good Morning";
} elseif ($current_hour >= 12 && $current_hour < 18) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS social_links (id INT AUTO_INCREMENT PRIMARY KEY, platform VARCHAR(50), link_url VARCHAR(255), icon VARCHAR(50), brand_color VARCHAR(20))");
$check_social = mysqli_query($conn, "SELECT id FROM social_links");
if(mysqli_num_rows($check_social) == 0) {
    $social_data = [['X (Twitter)', '@biruk2217', 'fa-x-twitter', '#ffffff'],['Telegram', '@brex2217', 'fa-telegram', '#229ED9'],['Facebook', 'https://www.facebook.com/profile.php?id=61564927622966', 'fa-facebook', '#1877F2'],['YouTube', 'https://www.youtube.com/@brex-media', 'fa-youtube', '#FF0000'],['LinkedIn', 'https://www.linkedin.com/in/bir-tad-7652663b6', 'fa-linkedin', '#0A66C2'],['Instagram', 'https://www.instagram.com/brex2217', 'fa-instagram', '#E4405F'],['TikTok', 'https://www.tiktok.com/@brex2217', 'fa-tiktok', '#ff0050'],['Threads', 'https://www.threads.com/@brex2217', 'fa-threads', '#ffffff']];
    foreach($social_data as $s) {
        mysqli_query($conn, "INSERT INTO social_links (platform, link_url, icon, brand_color) VALUES ('{$s[0]}', '{$s[1]}', '{$s[2]}', '{$s[3]}')");
    }
}

// ========================================================
// ⚙️ SUPER ADMIN SETTINGS & SECURITY LOGIC (UNIFIED)
// ========================================================

// Amma Button tokkichatu waan hundumaa Save godha!
if(isset($_POST['save_all_settings'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['sa_name']));
    $email = mysqli_real_escape_string($conn, trim($_POST['sa_email'])); // Private
    $public_email = mysqli_real_escape_string($conn, trim($_POST['sa_public_email'])); // Public Sender
    $app_pass = mysqli_real_escape_string($conn, trim($_POST['sa_app_password'])); // App Password
    $phone = mysqli_real_escape_string($conn, trim($_POST['sa_phone']));
    $username = mysqli_real_escape_string($conn, trim($_POST['sa_username']));
    
    $two_factor = isset($_POST['two_factor']) ? 1 : 0;
    $login_alerts = isset($_POST['login_alerts']) ? 1 : 0;
    $profile_locked = isset($_POST['profile_locked']) ? 1 : 0;

    // Passwords
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];

    $verify = mysqli_query($conn, "SELECT id FROM super_admin WHERE id=$super_admin_id AND password='$current_pass'");
    if(mysqli_num_rows($verify) > 0){
        
        if(isset($_FILES['profile_pic']['name']) && !empty($_FILES['profile_pic']['name'])){
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $file_name = "sa_" . $super_admin_id . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], "../uploads/" . $file_name);
            mysqli_query($conn, "UPDATE super_admin SET profile_pic='$file_name' WHERE id=$super_admin_id");
        }
        
        $pass_query = !empty($new_pass) ? "password='$new_pass'," : "";
        $sql = "UPDATE super_admin SET 
                name='$name', 
                email='$email', 
                public_email='$public_email', 
                app_password='$app_pass', 
                phone='$phone', 
                username='$username', 
                $pass_query 
                two_factor_enabled=$two_factor, 
                login_alerts=$login_alerts, 
                profile_locked=$profile_locked 
                WHERE id=$super_admin_id";
                
       if(mysqli_query($conn, $sql)){
            $_SESSION['username'] = $username; 
            header("Location: dashboard.php?tab=settings&msg=settings_updated"); exit();
        } else {
            $message = "Database Error!"; $msg_type = "error";
        }
    } else {
        $message = "Save Failed: Incorrect Current Password!"; $msg_type = "error";
    }
}
if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'settings_updated') { $message = "Settings Updated Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'social_added') { $message = "New Platform Auto-Detected and Added!"; $msg_type = "success"; }
    if($_GET['msg'] == 'social_deleted') { $message = "Social Link Removed!"; $msg_type = "success"; }
    if($_GET['msg'] == 'slide_added') { $message = "New Homepage Slide Added Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'slide_deleted') { $message = "Slide Deleted Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'logo_set') { $message = "Website Logo Updated Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'bg_updated') { $message = "Login Background Media Updated Successfully!"; $msg_type = "success"; }
}
// ========================================================
// 🏢 4. MANAGE COLLEGES LOGIC (CRUD + TRASH)
// ========================================================
if(isset($_POST['add_college'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['college_name']));
    $code = mysqli_real_escape_string($conn, trim($_POST['college_code']));
    $check = mysqli_query($conn, "SELECT id FROM colleges WHERE college_code='$code' AND is_deleted=0");
    if(mysqli_num_rows($check) > 0){ $message = "College Code already exists!"; $msg_type = "error"; }
    else { mysqli_query($conn, "INSERT INTO colleges (college_name, college_code, is_deleted) VALUES ('$name', '$code', 0)"); $message = "College Added Successfully!"; }
}
if(isset($_POST['edit_college'])){
    $c_id = intval($_POST['college_id']); $name = mysqli_real_escape_string($conn, trim($_POST['college_name'])); $code = mysqli_real_escape_string($conn, trim($_POST['college_code']));
    mysqli_query($conn, "UPDATE colleges SET college_name='$name', college_code='$code' WHERE id=$c_id"); $message = "College Updated Successfully!";
}
if(isset($_POST['soft_delete_college'])){
    $c_id = intval($_POST['college_id']); $password = $_POST['sa_password']; 
    $verify = mysqli_query($conn, "SELECT id FROM super_admin WHERE id=$super_admin_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){ mysqli_query($conn, "UPDATE colleges SET is_deleted=1, deleted_at=NOW() WHERE id=$c_id"); $message = "College moved to Trash! (30 days until permanent deletion)."; } 
    else { $message = "Authentication Failed! Incorrect Password."; $msg_type = "error"; }
}
if(isset($_POST['restore_college'])){
    $c_id = intval($_POST['college_id']); mysqli_query($conn, "UPDATE colleges SET is_deleted=0, deleted_at=NULL WHERE id=$c_id"); $message = "College Restored Successfully!";
}

// ========================================================
// 👔 5. MANAGE COLLEGE ADMINS LOGIC (CRUD + TRASH)
// ========================================================
if(isset($_POST['add_admin'])){
    $c_id = intval($_POST['college_id']); $name = mysqli_real_escape_string($conn, trim($_POST['admin_name'])); $email = mysqli_real_escape_string($conn, trim($_POST['admin_email']));
    $username = mysqli_real_escape_string($conn, trim($_POST['admin_username'])); $password = $_POST['admin_password'];
    $check = mysqli_query($conn, "SELECT id FROM admin WHERE username='$username' OR email='$email'");
    if(mysqli_num_rows($check) > 0){ $message = "Admin Username/Email already exists!"; $msg_type = "error"; } 
    else { mysqli_query($conn, "INSERT INTO admin (college_id, name, email, username, password, status, is_deleted) VALUES ($c_id, '$name', '$email', '$username', '$password', 'active', 0)"); $message = "College Admin Assigned Successfully!"; }
}
if(isset($_POST['toggle_admin'])){
    $id = intval($_POST['admin_id']); mysqli_query($conn, "UPDATE admin SET status = IF(status='active', 'inactive', 'active') WHERE id=$id"); $message = "Admin Status Updated!";
}
if(isset($_POST['soft_delete_admin'])){
    $a_id = intval($_POST['admin_id']); $password = $_POST['sa_password']; 
    $verify = mysqli_query($conn, "SELECT id FROM super_admin WHERE id=$super_admin_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){ mysqli_query($conn, "UPDATE admin SET is_deleted=1, deleted_at=NOW() WHERE id=$a_id"); $message = "Admin moved to Trash!"; } 
    else { $message = "Authentication Failed! Incorrect Password."; $msg_type = "error"; }
}
if(isset($_POST['restore_admin'])){
    $a_id = intval($_POST['admin_id']); mysqli_query($conn, "UPDATE admin SET is_deleted=0, deleted_at=NULL WHERE id=$a_id"); $message = "Admin Restored Successfully!";
}

// ========================================================
// 🛡️ 6. SECURITY CENTER LOGIC (Unban IP)
// ========================================================
if(isset($_POST['unban_ip'])){
    $ip_id = intval($_POST['ip_id']);
    mysqli_query($conn, "DELETE FROM blocked_ips WHERE id=$ip_id");
    $message = "IP Address Unbanned Successfully!";
}

// ========================================================
// 📊 7. FETCH LIVE DASHBOARD DATA
// ========================================================
$colleges_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM colleges WHERE is_deleted=0"))['total'];
$admins_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM admin WHERE is_deleted=0"))['total'];
$heads_count    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM head"))['total'];
$teachers_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM teacher"))['total'];
$students_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM student"))['total'];
$trash_colleges_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM colleges WHERE is_deleted=1"))['total'];
$trash_admins_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM admin WHERE is_deleted=1"))['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1400"><title>EPLMS - Super Admin Global Control</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* ======================================================== */
    /* 🎨 8. SYSTEM STYLES (CSS)                                */
    /* ======================================================== */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    
    :root { 
        --bg-color: #0b0e14; --panel-bg: #181a20; --border-color: #2b3139; --text-main: #eaecef; --text-muted: #848e9c;
        --primary: #fcd535; --primary-hover: #e5c02a; --danger: #f6465d; --success: #0ecb81; --input-bg: #0b0e14;
    }
    body.light-mode {
        --bg-color: #f0f4f8; --panel-bg: #ffffff; --border-color: #e2e8f0; --text-main: #2d3436; --text-muted: #636e72;
        --primary: #5b4dff; --primary-hover: #4a3ae0; --input-bg: #f9f9f9;
    }
body { background: var(--bg-color); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; transition: 0.3s; }
    .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; overflow-x: hidden; width: 100%; }
    /* SIDEBAR & TOGGLE STYLES */
    .sidebar { position: relative; width: 280px; background: var(--panel-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; transition: width 0.3s ease; z-index: 100; }
    .sidebar.collapsed { width: 80px; }
    .sidebar.collapsed .sidebar-header h3, .sidebar.collapsed .nav-links span, .sidebar.collapsed .logout-btn span { display: none; }
    .sidebar.collapsed .sidebar-header { justify-content: center; padding: 25px 0; }
    .sidebar.collapsed .nav-links button { justify-content: center; padding: 14px 0; }
    .sidebar.collapsed .nav-links i.icon { margin: 0; font-size: 22px; }
    .sidebar.collapsed .logout-btn { padding: 12px 0; margin: 15px 10px; }
    .sidebar.collapsed .sidebar-section-title { display: none; }
    .sidebar-toggle-btn { position: absolute; right: -15px; top: 30px; background: var(--primary); color: #000; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 101; border: 2px solid var(--bg-color); transition: 0.3s; }
    .sidebar.collapsed .sidebar-toggle-btn { transform: rotate(180deg); }
    
    .sidebar-header { padding: 25px 20px; font-size: 20px; font-weight: 800; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; }
    .sidebar-header i { color: var(--primary); }
    .nav-links { list-style: none; padding: 15px 0; overflow-y: auto; flex: 1; }
    .nav-links::-webkit-scrollbar { width: 4px; }
    .nav-links::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
    .nav-links button { width: 100%; display: flex; align-items: center; gap: 15px; background: transparent; border: none; color: var(--text-muted); font-size: 14px; font-weight: 600; padding: 14px 25px; cursor: pointer; transition: 0.3s; text-align: left; position: relative;}
    .nav-links button:hover, .nav-links button.active { background: rgba(132, 142, 156, 0.1); color: var(--primary); border-right: 4px solid var(--primary); }
    .nav-links i.icon { width: 20px; font-size: 16px; text-align: center; }
    .logout-btn { background: rgba(246, 70, 93, 0.1); color: var(--danger); margin: 15px 20px; padding: 12px; text-align: center; border-radius: 8px; text-decoration: none; font-weight: bold; display: block; border: 1px solid rgba(246, 70, 93, 0.2); transition: 0.3s; }
    .logout-btn:hover { background: var(--danger); color: #fff; }

    /* MAIN CONTENT */
    .top-header { background: var(--panel-bg); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 9999; min-height: 75px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .welcome-section { display: flex; align-items: center; gap: 15px; flex-shrink: 0;}
    .sa-avatar { width: 45px; height: 45px; min-width: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex-shrink: 0;}
    .welcome-text { white-space: nowrap; }
    .welcome-text h2 { font-size: 18px; font-weight: 700; margin-bottom: 2px; color: var(--text-main);}
    .welcome-text span { font-size: 12px; color: var(--text-muted); font-weight: 500; }
    .theme-toggle { background: var(--border-color); border: none; color: var(--text-main); padding: 10px 15px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; flex-shrink: 0;}

    .content-area { padding: 30px; max-width: 100%; overflow-x: hidden; box-sizing: border-box; }
    .section-tab { display: none; animation: fadeIn 0.4s ease; }
    .section-tab.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    /* PANELS & FORMS */
    .grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
    /* Settings Grid Haaraa */
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; align-items: start; }
    .panel { background: var(--panel-bg); border-radius: 12px; border: 1px solid var(--border-color); padding: 25px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .panel-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;}
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 8px; color: var(--text-muted); }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--input-bg); color: var(--text-main); outline: none; font-family: 'Inter'; transition: 0.3s;}
    .form-group input:focus, .form-group select:focus { border-color: var(--primary); }
    .btn { padding: 10px 18px; background: var(--primary); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: 0.3s;}
    body:not(.light-mode) .btn { color: #000; }
    .btn:hover { opacity: 0.9; transform: translateY(-2px); }
    .btn-danger { background: rgba(246, 70, 93, 0.1); color: var(--danger); border: 1px solid rgba(246, 70, 93, 0.3); }
    .btn-warning { background: rgba(252, 213, 53, 0.1); color: var(--primary); border: 1px solid rgba(252, 213, 53, 0.3); }
    .btn-success { background: rgba(14, 203, 129, 0.1); color: var(--success); border: 1px solid rgba(14, 203, 129, 0.3); }
    .btn-sm { padding: 6px 10px; font-size: 12px; }

    /* TABLES & BADGES */
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; font-size: 14px; border-bottom: 1px solid var(--border-color); }
    th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 12px; }
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; background: rgba(14, 203, 129, 0.1); color: var(--success); border: 1px solid rgba(14, 203, 129, 0.2); }
    .badge-red { background: rgba(246, 70, 93, 0.1); color: var(--danger); border-color: rgba(246, 70, 93, 0.2); }
    .badge-noti { background: var(--danger); color: #fff; padding: 2px 6px; border-radius: 10px; font-size: 10px; position: absolute; right: 15px; font-weight: bold;}
   /* 🪄 MAGIC ALERT STYLES (Moodle/Premium Style) */
    .alert { padding: 18px 25px; border-radius: 8px; margin-bottom: 25px; font-weight: 600; display: flex; align-items: center; gap: 12px; font-size: 15px; width: 100%; box-shadow: 0 4px 10px rgba(0,0,0,0.02); animation: fadeInDown 0.5s ease; }
    @keyframes fadeInDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-success i { color: #059669; font-size: 18px; }
    .alert-error { background: #fee2e2; color: #9f1239; border: 1px solid #fecdd3; }
    .alert-error i { color: #e11d48; font-size: 18px; }
    /* MODALS */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
    .modal-overlay.active { display: flex; }
    .modal-box { background: var(--panel-bg); padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; border: 1px solid var(--border-color); text-align: center; }

    /* MAGIC CONTROL CENTER STYLES */
    .welcome-banner { display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(252, 213, 53, 0.08) 100%); padding: 35px 40px; border-radius: 16px; border: 1px solid rgba(252, 213, 53, 0.2); margin-bottom: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; overflow: hidden; }
    .welcome-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(252, 213, 53, 0.05) 0%, transparent 60%); animation: rotateBg 20s linear infinite; z-index: 0; }
    @keyframes rotateBg { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .welcome-banner > div { z-index: 1; position: relative; }
    .welcome-banner h2 { font-size: 32px; margin-bottom: 8px; font-weight: 800; letter-spacing: -0.5px; }
    .live-clock-container { background: rgba(0,0,0,0.4); padding: 15px 30px; border-radius: 50px; display: flex; align-items: center; gap: 15px; border: 1px solid var(--primary); backdrop-filter: blur(5px); }
    #real-time-clock { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: 3px; font-family: 'Courier New', Courier, monospace; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .magic-card { position: relative; overflow: hidden; z-index: 1; padding: 25px; border-radius: 16px; border: 1px solid var(--border-color); background: var(--panel-bg); transition: all 0.4s; box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .magic-card:hover { transform: translateY(-10px) scale(1.03); box-shadow: 0 20px 40px rgba(0,0,0,0.4); border-color: var(--primary); }
    .magic-card::after { content: ''; position: absolute; top: 0; left: -100%; width: 50%; height: 100%; background: linear-gradient(to right, transparent, rgba(255,255,255,0.05), transparent); transform: skewX(-25deg); transition: 0.7s; z-index: -1; }
    .magic-card:hover::after { left: 150%; }
    .magic-card .bg-icon { position: absolute; right: -20px; bottom: -20px; font-size: 100px; opacity: 0.03; transform: rotate(-15deg); transition: 0.5s; z-index: -1; }
    .magic-card:hover .bg-icon { transform: rotate(0deg) scale(1.2); opacity: 0.1; color: var(--primary); }
    .icon-box { width: 55px; height: 55px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; margin-bottom: 15px; }
    .magic-card h2 { font-size: 42px; font-weight: 800; margin: 0 0 5px 0; }
    .magic-card p { font-size: 13px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 2px; }
    .pulse-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: var(--success); margin-right: 10px; animation: pulse-dot-anim 1.5s infinite; }
    @keyframes pulse-dot-anim { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(14, 203, 129, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(14, 203, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(14, 203, 129, 0); } }

    /* MAGIC DRILL-DOWN OVERSIGHT STYLES */
    .breadcrumbs { background: var(--panel-bg); padding: 15px 25px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; }
    .bc-item { color: var(--text-muted); cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .bc-item:hover { color: var(--primary); }
    .bc-item.active { color: var(--primary); pointer-events: none; }
    .bc-separator { color: var(--border-color); font-size: 12px; }
    .oversight-view { display: none; animation: slideInRight 0.4s ease forwards; }
    .oversight-view.active { display: block; }
    @keyframes slideInRight { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    .magic-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    .magic-drill-card { background: linear-gradient(145deg, var(--panel-bg), rgba(255,255,255,0.02)); border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .magic-drill-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.3); }
    .magic-drill-card::after { content: '\f105'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; top: 50%; transform: translateY(-50%); font-size: 20px; color: var(--border-color); transition: 0.3s; }
    .magic-drill-card:hover::after { color: var(--primary); transform: translateY(-50%) translateX(5px); }
    .lvl-college { border-bottom: 4px solid #3b82f6; } .lvl-college:hover { border-color: #60a5fa; }
    .lvl-dept { border-bottom: 4px solid #8b5cf6; } .lvl-dept:hover { border-color: #a78bfa; }
    .lvl-teacher { border-bottom: 4px solid #10b981; } .lvl-teacher:hover { border-color: #34d399; }
    .card-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 22px; color: #fff; margin-bottom: 15px; }
    .magic-drill-card h3 { font-size: 18px; margin-bottom: 5px; }
    .magic-drill-card p { font-size: 13px; color: var(--text-muted); font-weight: 500; margin-bottom: 15px; }
    .card-meta { display: inline-block; background: rgba(0,0,0,0.3); padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; border: 1px solid rgba(255,255,255,0.05); }
    .student-list-item { display: flex; align-items: center; gap: 15px; padding: 15px; border-bottom: 1px solid var(--border-color); transition: 0.3s; }
    .student-list-item:hover { background: rgba(255,255,255,0.02); }
    .student-avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(239, 68, 68, 0.1); color: #ef4444; display: flex; justify-content: center; align-items: center; font-weight: bold; font-size: 16px; border: 1px solid rgba(239, 68, 68, 0.3); }

    /* TELEGRAM-STYLE COMMUNICATION HUB STYLES */
    .telegram-app { display: flex; height: 75vh; background: var(--panel-bg); border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
.tg-sidebar { transition: width 0.3s ease; width: 400px; background: rgba(0,0,0,0.2); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; }    .tg-sidebar.collapsed { width: 0px; border: none; }
    .tg-search-bar { padding: 15px; border-bottom: 1px solid var(--border-color); }
    .tg-search-bar input { width: 100%; padding: 10px 15px 10px 40px; border-radius: 20px; border: 1px solid var(--border-color); background: var(--bg-color) url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="%23848e9c"><path d="M505 442.7L405.3 343c-4.5-4.5-10.6-7-17-7H372c27.6-35.3 44-79.7 44-128C416 93.1 322.9 0 208 0S0 93.1 0 208s93.1 208 208 208c48.3 0 92.7-16.4 128-44v16.3c0 6.4 2.5 12.5 7 17l99.7 99.7c9.4 9.4 24.6 9.4 33.9 0l28.3-28.3c9.4-9.4 9.4-24.6.1-34zM208 336c-70.7 0-128-57.2-128-128 0-70.7 57.2-128 128-128 70.7 0 128 57.2 128 128 0 70.7-57.2 128-128 128z"/></svg>') no-repeat 15px center; background-size: 14px; color: var(--text-main); font-size: 13px; outline: none; }
    .tg-folders { display: flex; overflow-x: auto; padding: 10px; gap: 5px; border-bottom: 1px solid var(--border-color); }
    .tg-folders::-webkit-scrollbar { height: 0px; }
    .tg-folder { padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer; white-space: nowrap; transition: 0.3s; background: rgba(255,255,255,0.02); }
    .tg-folder.active { background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); }
    .tg-contacts { flex: 1; overflow-y: auto; }
    .tg-contacts::-webkit-scrollbar { width: 4px; }
    .tg-contacts::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
    .tg-contact-item { display: flex; align-items: center; gap: 15px; padding: 15px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid rgba(255,255,255,0.02); }
    .tg-contact-item:hover { background: rgba(255,255,255,0.05); }
    .tg-contact-item.active { background: rgba(59, 130, 246, 0.1); border-left: 3px solid #3b82f6; }
    .tg-avatar { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: bold; color: #fff; position: relative; }
    .tg-avatar.group { border-radius: 12px; }
    .tg-online-dot { position: absolute; bottom: 0; right: 0; width: 12px; height: 12px; background: var(--success); border-radius: 50%; border: 2px solid var(--panel-bg); }
    .tg-info { flex: 1; overflow: hidden; }
    .tg-name { font-size: 14px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; justify-content: space-between; }
    .tg-role { font-size: 12px; color: var(--text-muted); display: block; margin-top: 3px; }
    .tg-chat-area { flex: 1; display: flex; flex-direction: column; background: url('https://www.transparenttextures.com/patterns/cubes.png'); }
    .tg-chat-header { padding: 15px 25px; background: var(--panel-bg); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); z-index: 10;}
    .tg-chat-title { font-size: 16px; font-weight: 700; color: #fff; }
    .tg-chat-status { font-size: 12px; color: var(--success); }
    .tg-chat-history { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
    .tg-placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: var(--text-muted); opacity: 0.5; }
    .tg-placeholder i { font-size: 60px; margin-bottom: 15px; }
    .tg-chat-input-area { padding: 20px; background: var(--panel-bg); border-top: 1px solid var(--border-color); }
    .tg-chat-form { display: flex; gap: 15px; align-items: center; background: var(--bg-color); padding: 5px 5px 5px 20px; border-radius: 30px; border: 1px solid var(--border-color); }
    .tg-chat-form input { flex: 1; background: transparent; border: none; color: var(--text-main); font-size: 15px; outline: none; }
    .tg-chat-form button { width: 45px; height: 45px; border-radius: 50%; background: #3b82f6; border: none; color: #fff; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; font-size: 16px; }
    .tg-chat-form button:hover { background: #2563eb; transform: scale(1.05); }

    /* CHAT BUBBLES STYLES */
    .chat-msg-wrapper { display: flex; margin-bottom: 15px; width: 100%; position: relative; }
    .chat-right { justify-content: flex-end; }
    .chat-left { justify-content: flex-start; }
    .chat-bubble { max-width: 75%; padding: 12px 16px; border-radius: 18px; position: relative; word-wrap: break-word; box-shadow: 0 4px 10px rgba(0,0,0,0.1); line-height: 1.5; }
    .chat-right .chat-bubble { background: var(--primary); color: #000; border-bottom-right-radius: 4px; }
.chat-left .chat-bubble { background: var(--bg-color); color: var(--text-main); border-bottom-left-radius: 4px; border: 1px solid var(--border-color); }    .chat-meta { font-size: 10px; opacity: 0.7; margin-top: 6px; display: flex; justify-content: space-between; gap: 15px; font-weight: 600; }
    .chat-right .chat-meta { color: rgba(0,0,0,0.6); }

    /* CUSTOM RIGHT-CLICK CONTEXT MENU */
    .chat-context-menu { display: none; position: fixed; z-index: 10000; width: 180px; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 8px 25px rgba(0,0,0,0.5); overflow: hidden; }
    .context-item { padding: 12px 15px; font-size: 13px; font-weight: 600; color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 10px; transition: 0.2s; }
    .context-item:hover { background: rgba(255,255,255,0.05); color: var(--primary); }
    .context-item.delete { color: var(--danger); border-top: 1px solid rgba(255,255,255,0.02); }
    .context-item.delete:hover { background: rgba(246, 70, 93, 0.1); color: var(--danger); }

    /* =================================================== */
    /* 🌟 SETTINGS & PROFILE UI                            */
    /* =================================================== */
    .profile-header-card { background: linear-gradient(135deg, #6d28d9 0%, #4c1d95 100%); border-radius: 20px; padding: 40px 20px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(109, 40, 217, 0.3); margin-bottom: 30px; z-index: 1;}
    .profile-header-card::before { content: ''; position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: rgba(255,255,255,0.1); border-radius: 50%; }
    .profile-header-card::after { content: ''; position: absolute; bottom: -50px; left: -50px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%; }
    
    .profile-avatar-wrapper { position: relative; width: 120px; height: 120px; margin: 0 auto 20px auto; z-index: 2; }
    .profile-avatar-large { width: 100%; height: 100%; border-radius: 20px; object-fit: cover; border: 4px solid rgba(255,255,255,0.2); box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    .edit-avatar-btn { position: absolute; bottom: -5px; right: -5px; background: var(--primary); color: #000; width: 35px; height: 35px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 3px solid #4c1d95; transition: 0.3s; }
    .edit-avatar-btn:hover { transform: scale(1.1); }
    
    .profile-name { color: #fff; font-size: 28px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; z-index: 2; position: relative;}
    .profile-email { color: rgba(255,255,255,0.8); font-size: 14px; margin-bottom: 20px; z-index: 2; position: relative;}
    
    .profile-badges { display: flex; justify-content: center; gap: 10px; z-index: 2; position: relative; margin-bottom: 30px;}
    .p-badge { background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; color: #fff; font-size: 12px; font-weight: 600; backdrop-filter: blur(5px); display: flex; align-items: center; gap: 5px;}
    .p-badge.active { background: var(--success); color: #fff; }
    
    .profile-stats-row { display: flex; justify-content: center; gap: 15px; z-index: 2; position: relative; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 20px;}
    .p-stat-box { background: rgba(255,255,255,0.1); padding: 15px 25px; border-radius: 12px; flex: 1; max-width: 150px; backdrop-filter: blur(5px); transition: 0.3s;}
    .p-stat-box:hover { background: rgba(255,255,255,0.2); transform: translateY(-3px);}
    .p-stat-box h3 { color: #fff; font-size: 24px; margin-bottom: 5px; }
    .p-stat-box p { color: rgba(255,255,255,0.7); font-size: 10px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;}

    /* Inner Tabs */
    .inner-tabs { display: flex; justify-content: center; gap: 20px; border-bottom: 1px solid var(--border-color); margin-bottom: 30px; padding-bottom: 10px; flex-wrap: wrap;}
    .inner-tab-btn { background: transparent; border: none; color: var(--text-muted); font-size: 14px; font-weight: 700; cursor: pointer; padding: 10px 20px; border-radius: 8px; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .inner-tab-btn:hover { background: rgba(255,255,255,0.05); }
    .inner-tab-btn.active { background: var(--primary); color: #000; }

    .inner-tab-content { display: none; animation: fadeIn 0.4s; }
    .inner-tab-content.active { display: block; }
    
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
    .info-card { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 15px; transition: 0.3s;}
    .info-card:hover { border-color: var(--primary); box-shadow: 0 5px 15px rgba(0,0,0,0.1);}
    .info-icon { width: 45px; height: 45px; background: rgba(91, 77, 255, 0.1); color: #5b4dff; border-radius: 10px; display: flex; justify-content: center; align-items: center; font-size: 18px; }
    .info-data label { display: block; font-size: 11px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 5px; }
    .info-data input { background: transparent; border: none; border-bottom: 1px solid var(--border-color); color: var(--text-main); font-size: 15px; font-weight: 600; width: 100%; outline: none; padding-bottom: 5px; transition: 0.3s;}
    .info-data input:focus { border-bottom-color: var(--primary); }

    /* Toggle Switch specific for Security */
    .sec-toggle { display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); padding: 15px 20px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 15px;}
    .sec-toggle-info h4 { font-size: 15px; color: var(--text-main); margin-bottom: 3px;}
    .sec-toggle-info p { font-size: 12px; color: var(--text-muted);}
    
    /* Toggle Switch generic */
    .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border-color); transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: var(--primary); }
    input:checked + .slider:before { transform: translateX(20px); }
    .info-card { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 15px; }
    .info-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: bold; }
    /* PASSWORD VALIDATION STYLES */
    .pw-group { position: relative; }
    .pw-eye { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); transition: 0.3s; z-index: 10; font-size: 16px; }
    .pw-eye:hover { color: var(--primary); }
    
    .pw-rules { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-top: 10px; display: none; border: 1px solid var(--border-color); }
    .rule-item { font-size: 12px; color: var(--danger); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
    .rule-item.valid { color: var(--success); }
    
    /* =================================================== */
    /* 📚 MAGIC HELP CENTER & DOCUMENTATION STYLES         */
    /* =================================================== */
    .help-hero { background: linear-gradient(135deg, #1e1b4b 0%, #3b82f6 100%); border-radius: 16px; padding: 40px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.2); margin-bottom: 30px; }
    .help-hero::before { content: ''; position: absolute; top: -50px; left: -50px; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; filter: blur(20px); }
    .help-hero h2 { font-size: 36px; color: #fff; font-weight: 800; margin-bottom: 15px; text-shadow: 0 2px 10px rgba(0,0,0,0.3); }
    .help-hero p { font-size: 16px; color: rgba(255,255,255,0.8); max-width: 600px; margin: 0 auto 25px; line-height: 1.6; }
    
    .help-search-box { position: relative; max-width: 500px; margin: 0 auto; }
    .help-search-box input { width: 100%; padding: 15px 20px 15px 50px; border-radius: 30px; border: none; background: rgba(255,255,255,0.9); color: #333; font-size: 16px; outline: none; box-shadow: 0 5px 15px rgba(0,0,0,0.2); transition: 0.3s; }
    .help-search-box input:focus { background: #fff; transform: scale(1.02); }
    .help-search-box i { position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: #3b82f6; font-size: 18px; }

    .help-grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .help-card { background: linear-gradient(145deg, var(--panel-bg), rgba(255,255,255,0.02)); border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; text-align: center; transition: 0.3s; cursor: pointer; }
    .help-card:hover { transform: translateY(-10px); border-color: var(--primary); box-shadow: 0 10px 25px rgba(252, 213, 53, 0.15); }
    .help-card i { font-size: 40px; margin-bottom: 15px; background: -webkit-linear-gradient(#fcd535, #f59e0b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .help-card h3 { font-size: 18px; color: var(--text-main); margin-bottom: 10px; }
    .help-card p { font-size: 13px; color: var(--text-muted); line-height: 1.5; }

    .help-topic-section { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; margin-bottom: 20px; }
    .help-topic-title { font-size: 22px; color: var(--text-main); border-bottom: 2px solid rgba(255,255,255,0.05); padding-bottom: 15px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    
    .help-accordion-item { border: 1px solid var(--border-color); border-radius: 10px; margin-bottom: 15px; overflow: hidden; background: rgba(0,0,0,0.1); transition: 0.3s; }
    .help-accordion-item.active { border-color: var(--primary); box-shadow: 0 0 15px rgba(252, 213, 53, 0.1); }
    .help-acc-btn { width: 100%; text-align: left; background: transparent; border: none; padding: 18px 20px; font-size: 15px; font-weight: 700; color: var(--text-main); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.3s; }
    .help-acc-btn:hover { background: rgba(255,255,255,0.02); color: var(--primary); }
    .help-acc-btn i { color: var(--text-muted); transition: 0.3s; }
    .help-accordion-item.active .help-acc-btn i { transform: rotate(180deg); color: var(--primary); }
    
    .help-acc-content { padding: 0 20px 20px 20px; color: var(--text-muted); font-size: 14px; line-height: 1.8; display: none; animation: slideDownHelp 0.3s ease forwards; }
    @keyframes slideDownHelp { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    
    .help-pro-tip { background: rgba(16, 185, 129, 0.1); border-left: 4px solid var(--success); padding: 15px; border-radius: 0 8px 8px 0; margin: 15px 0; color: #a7f3d0; }
    .help-warning { background: rgba(246, 70, 93, 0.1); border-left: 4px solid var(--danger); padding: 15px; border-radius: 0 8px 8px 0; margin: 15px 0; color: #fecdd3; }
    
    .help-step-list { list-style: none; counter-reset: help-counter; margin-top: 15px; }
    .help-step-list li { position: relative; padding-left: 45px; margin-bottom: 20px; }
    .help-step-list li::before { counter-increment: help-counter; content: counter(help-counter); position: absolute; left: 0; top: -2px; width: 30px; height: 30px; background: var(--primary); color: #000; font-weight: bold; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 14px; box-shadow: 0 4px 10px rgba(252, 213, 53, 0.3); }
/* =================================================== */
    /* 🌐 MAGIC SOCIAL MEDIA STYLES                        */
    /* =================================================== */
    .social-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-top: 20px; }
    .social-card { position: relative; background: linear-gradient(145deg, var(--panel-bg), rgba(255,255,255,0.02)); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; display: flex; align-items: center; gap: 15px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 5px 15px rgba(0,0,0,0.1); overflow: hidden; }
    .social-card::before { content: ''; position: absolute; left: 0; top: 0; width: 4px; height: 100%; background: var(--brand-color); transition: 0.3s; }
    .social-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
    .social-card:hover::before { width: 100%; opacity: 0.05; }
    .social-card:hover .del-soc-btn { opacity: 1 !important; }
    .social-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 24px; color: var(--brand-color); background: rgba(255,255,255,0.05); }
    .social-info { flex: 1; overflow: hidden; }
    .social-info h4 { color: var(--text-main); font-size: 15px; margin-bottom: 5px; }
    
    .social-text { font-size: 13px; color: var(--text-muted); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; user-select: none; }
    .social-input { width: 100%; background: rgba(0,0,0,0.2); border: 1px solid var(--primary); color: var(--primary); padding: 5px 10px; border-radius: 6px; font-size: 13px; outline: none; }
    
    .dbl-click-hint { position: absolute; top: 10px; right: 15px; font-size: 10px; color: var(--text-muted); opacity: 0; transition: 0.3s; background: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 10px; }
    .social-card:hover .dbl-click-hint { opacity: 1; }
   /* NOTIFICATION BADGE STYLES */
    .main-sidebar-badge { background: var(--danger); color: #fff; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; margin-left: auto; box-shadow: 0 0 10px rgba(246, 70, 93, 0.5); animation: pulse-badge 2s infinite; z-index: 10; }
    @keyframes pulse-badge { 0% { transform: scale(1) translateY(-50%); } 50% { transform: scale(1.1) translateY(-50%); } 100% { transform: scale(1) translateY(-50%); } }
    .chat-unread-badge { background: var(--danger); color: #fff; padding: 2px 7px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-left: auto; box-shadow: 0 2px 5px rgba(246, 70, 93, 0.4); display: none; }
</style>
</head>
<body>
<aside class="sidebar" id="main-sidebar">
    <div class="sidebar-header" style="margin-bottom: 20px;">
        <i class="fa-solid fa-chess-king" style="color:var(--primary);"></i> <h3>EPLMS SITE BAR</h3>
      
    </div>
    
    

    <!-- Menu items gadi buusuuf padding-top itti dabalameera -->
    <ul class="nav-links" style="padding-top: 10px;">
        <li><button class="tab-link active" onclick="openTab('home')"><i class="fa-solid fa-chart-pie icon"></i> <span>Control Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('colleges')"><i class="fa-solid fa-building-columns icon"></i> <span>Manage Colleges</span> <?php if($trash_colleges_count > 0) echo "<span class='badge-noti'>$trash_colleges_count</span>"; ?></button></li>
        <li><button class="tab-link" onclick="openTab('admins')"><i class="fa-solid fa-user-tie icon"></i> <span>College Admins</span> <?php if($trash_admins_count > 0) echo "<span class='badge-noti'>$trash_admins_count</span>"; ?></button></li>
        <li><button class="tab-link" onclick="openTab('admin_oversight'); resetOversight();"><i class="fa-solid fa-network-wired icon" style="color: #3b82f6;"></i> <span>Global Oversight</span></button></li>
        <li><button class="tab-link" onclick="openTab('help')"><i class="fa-solid fa-circle-question icon"></i> <span>Help Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('about')"><i class="fa-solid fa-circle-info icon"></i> <span>About EPLMS</span></button></li>
        <li><button class="tab-link" onclick="openTab('social_media')"><i class="fa-solid fa-hashtag icon" style="color: #e81cff;"></i> <span>Social Links</span></button></li>
        <li><button class="tab-link" onclick="openTab('broadcast')"><i class="fa-brands fa-telegram icon" style="color: #0ea5e9;"></i> <span>Communications</span> <span class="main-sidebar-badge" id="main_comm_badge" style="display:none; position:absolute; right:15px; top:50%; transform:translateY(-50%);">0</span></button></li>       <li><button class="tab-link" onclick="openTab('audit')"><i class="fa-solid fa-shield-halved icon"></i> <span>Security Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('settings')"><i class="fa-solid fa-user-gear icon" style="color: #8b5cf6;"></i> <span>Settings</span></button></li>
    </ul>
    <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-power-off"></i> <span>Secure Logout</span></a>
</aside>

<main class="main-content">
   <header class="top-header" style="position: sticky; top: 0; z-index: 9999; background: var(--panel-bg); box-shadow: 0 4px 15px rgba(0,0,0,0.05); min-height: 75px;">
        <div style="display: flex; align-items: center; gap: 15px; flex-shrink: 0;">
            <button type="button" class="btn btn-sm" style="background:transparent; color:var(--text-main); border:1px solid var(--border-color); margin-right:10px;" onclick="document.getElementById('main-sidebar').classList.toggle('collapsed')">
                <i class="fa-solid fa-bars-staggered"></i>
            </button>
            <div class="welcome-section" style="display: flex; align-items: center; gap: 15px;">
                <img src="<?php echo $profile_pic; ?>" alt="Super Admin" style="width: 45px; height: 45px; min-width: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex-shrink: 0;">
                <div class="welcome-text" style="white-space: nowrap;">
<h2 id="display-admin-name" style="font-size: 18px; font-weight: 700; color: var(--text-main);">
    Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>
</h2>                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-crown" style="color:var(--primary);"></i> Supreme Authority</span>
                </div>
            </div>
        </div>
        <button class="theme-toggle" style="flex-shrink: 0;" onclick="toggleTheme()"><i class="fa-solid fa-moon" id="theme-icon"></i> <span id="theme-text">Dark Mode</span></button>
    </header>

    <div class="content-area">
        <?php if($message): ?>
            <div class="alert alert-<?php echo $msg_type; ?>"><i class="fa-solid <?php echo $msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation'; ?>"></i> <?php echo $message; ?></div>
        <?php endif; ?>
       <!-- ============================================== -->
        <!-- TAB 1: MAGIC CONTROL CENTER                    -->
        <!-- ============================================== -->
        <div id="home" class="section-tab active">
            <div class="welcome-banner">
                <div>
<h2 id="greeting-text">
    <?php 
        $hour = date('H');
        $greet = ($hour < 12) ? "Good Morning" : (($hour < 18) ? "Good Afternoon" : "Good Evening");
        echo $greet . ", " . htmlspecialchars($_SESSION['username']); 
    ?>!
</h2>                    <p><i class="fa-solid fa-shield-check" style="color:var(--success);"></i> System is secure and running smoothly. Here is your global overview.</p>
                </div>
                <div class="live-clock-container"><i class="fa-solid fa-clock"></i><span id="real-time-clock">00:00:00</span></div>
            </div>

            <div class="stats-grid">
                <div class="magic-card"><i class="fa-solid fa-building-columns bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-building-columns"></i></div><h2 class="counter" data-target="<?php echo $colleges_count; ?>">0</h2><p>Colleges</p></div>
                <div class="magic-card"><i class="fa-solid fa-user-tie bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-user-tie"></i></div><h2 class="counter" data-target="<?php echo $admins_count; ?>">0</h2><p>Admins</p></div>
                <div class="magic-card"><i class="fa-solid fa-users-gear bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-users-gear"></i></div><h2 class="counter" data-target="<?php echo $heads_count; ?>">0</h2><p>Heads</p></div>
                <div class="magic-card"><i class="fa-solid fa-chalkboard-user bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-chalkboard-user"></i></div><h2 class="counter" data-target="<?php echo $teachers_count; ?>">0</h2><p>Teachers</p></div>
                <div class="magic-card"><i class="fa-solid fa-user-graduate bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #ef4444, #b91c1c);"><i class="fa-solid fa-user-graduate"></i></div><h2 class="counter" data-target="<?php echo $students_count; ?>">0</h2><p>Students</p></div>
            </div>

            <div class="grid-2">
                <div class="panel" style="position: relative; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.1);">
                    <h3 class="panel-title"><i class="fa-solid fa-chart-pie"></i> System Demographics</h3>
                    <div style="height: 280px; display:flex; justify-content:center; align-items:center; margin-top:10px;"><canvas id="demographicsChart"></canvas></div>
                </div>
                <div class="panel" style="box-shadow: 0 8px 20px rgba(0,0,0,0.1);">
                    <h3 class="panel-title"><i class="fa-solid fa-satellite-dish"></i> Live System Health</h3>
                    <table class="status-table" style="width: 100%;">
                        <tr><th style="padding-bottom: 15px;">Component</th><th style="padding-bottom: 15px;">Status</th><th style="padding-bottom: 15px;">Performance</th></tr>
                        <tr style="border-bottom: 1px solid var(--border-color);"><td><strong style="color:#3b82f6;"><i class="fa-solid fa-database"></i> Core Database</strong></td><td><span class="pulse-dot"></span> <span style="color:var(--success); font-weight:800;">ONLINE</span></td><td><span class="badge" style="background:transparent; border-color:#3b82f6; color:#3b82f6; font-size:12px;">99.9% Uptime</span></td></tr>
                        <tr style="border-bottom: 1px solid var(--border-color);"><td><strong style="color:#10b981;"><i class="fa-solid fa-shield-halved"></i> Security Firewall</strong></td><td><span class="pulse-dot"></span> <span style="color:var(--success); font-weight:800;">ACTIVE</span></td><td><span class="badge" style="background:transparent; border-color:#10b981; color:#10b981; font-size:12px;">0 Threats Detected</span></td></tr>
                        <tr style="border-bottom: none;"><td><strong style="color:var(--primary);"><i class="fa-solid fa-envelope"></i> SMTP Email Server</strong></td><td><span class="pulse-dot" style="background:var(--primary); box-shadow: 0 0 0 0 rgba(252,213,53,0.7);"></span> <span style="color:var(--primary); font-weight:800;">SYNCING</span></td><td><span class="badge" style="background:transparent; border-color:var(--primary); color:var(--primary); font-size:12px;">Connected</span></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 2: MANAGE COLLEGES                         -->
        <!-- ============================================== -->
        <div id="colleges" class="section-tab">
            <div class="grid-2">
                <div class="panel">
                    <h3 class="panel-title"><i class="fa-solid fa-building-columns"></i> Add/Edit College</h3>
                    <form method="POST">
                        <input type="hidden" name="college_id" id="form_col_id">
                        <div class="form-group"><label>College Name</label><input type="text" name="college_name" id="form_col_name" required></div>
                        <div class="form-group"><label>College Code</label><input type="text" name="college_code" id="form_col_code" required></div>
                        <button type="submit" name="add_college" id="btn_add_col" class="btn" style="width:100%;"><i class="fa-solid fa-plus"></i> Create College</button>
                        <button type="submit" name="edit_college" id="btn_edit_col" class="btn btn-warning" style="width:100%; display:none;"><i class="fa-solid fa-pen"></i> Save Changes</button>
                    </form>
                </div>
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-list-check"></i> Registered Colleges</h3>
                    <table>
                        <tr><th>College Info</th><th>Status</th><th>Action</th></tr>
                        <?php
                        $cols = mysqli_query($conn, "SELECT c.*, (SELECT COUNT(id) FROM admin WHERE college_id=c.id AND is_deleted=0) as has_admin FROM colleges c WHERE is_deleted=0 ORDER BY id DESC");
                        while($c = mysqli_fetch_assoc($cols)){
                            $status = $c['has_admin'] > 0 ? "<span class='badge'><i class='fa-solid fa-check-circle'></i> Active</span>" : "<span class='badge badge-red'><i class='fa-solid fa-clock'></i> No Admin</span>";
                            echo "<tr>
                                    <td><strong>{$c['college_name']}</strong><br><small style='color:var(--primary);'>{$c['college_code']}</small></td>
                                    <td>{$status}</td>
                                    <td>
                                        <button class='btn btn-sm btn-warning' onclick=\"editCollege({$c['id']}, '{$c['college_name']}', '{$c['college_code']}')\"><i class='fa-solid fa-pen'></i></button>
                                        <button class='btn btn-sm btn-danger' onclick=\"confirmDelete({$c['id']}, '{$c['college_name']}')\"><i class='fa-solid fa-trash'></i></button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
            <!-- TRASH -->
            <div class="panel" style="border-color: rgba(246, 70, 93, 0.3); margin-top:10px;">
                <h3 class="panel-title" style="color:var(--danger);"><i class="fa-solid fa-trash-can-arrow-up"></i> Recycle Bin (Colleges)</h3>
                <table>
                    <tr><th>Deleted College</th><th>Deleted Date</th><th>Action</th></tr>
                    <?php
                    $trash = mysqli_query($conn, "SELECT * FROM colleges WHERE is_deleted=1 ORDER BY deleted_at DESC");
                    while($t = mysqli_fetch_assoc($trash)){
                        echo "<tr><td><strike>{$t['college_name']}</strike> ({$t['college_code']})</td><td style='color:var(--danger);'>{$t['deleted_at']}</td><td><form method='POST'><input type='hidden' name='college_id' value='{$t['id']}'><button type='submit' name='restore_college' class='btn btn-sm btn-success'>Restore</button></form></td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 3: COLLEGE ADMINS                          -->
        <!-- ============================================== -->
        <div id="admins" class="section-tab">
            <div class="grid-2">
                <div class="panel">
                    <h3 class="panel-title"><i class="fa-solid fa-user-shield"></i> Assign Admin</h3>
                    <form method="POST">
                        <div class="form-group"><label>Select College</label><select name="college_id" required><option value="" disabled selected>-- Choose --</option>
                            <?php $c_list = mysqli_query($conn, "SELECT * FROM colleges WHERE is_deleted=0"); while($c = mysqli_fetch_assoc($c_list)) echo "<option value='{$c['id']}'>{$c['college_name']}</option>"; ?>
                        </select></div>
                        <div class="form-group"><label>Full Name</label><input type="text" name="admin_name" required></div>
                        <div class="form-group"><label>Email</label><input type="email" name="admin_email" required></div>
                        <div class="form-group"><label>Username</label><input type="text" name="admin_username" required></div>
                        <div class="form-group"><label>Password</label><input type="password" name="admin_password" required></div>
                        <button type="submit" name="add_admin" class="btn" style="width:100%;">Assign Admin</button>
                    </form>
                </div>
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-users"></i> Active Admins</h3>
                    <table>
                        <tr><th>Admin Profile</th><th>College</th><th>Status</th><th>Action</th></tr>
                        <?php
                        $admins = mysqli_query($conn, "SELECT a.*, c.college_name FROM admin a JOIN colleges c ON a.college_id = c.id WHERE a.is_deleted=0 ORDER BY a.id DESC");
                        while($a = mysqli_fetch_assoc($admins)){
                            $badge = $a['status'] == 'active' ? 'badge' : 'badge-red';
                            $btn_text = $a['status'] == 'active' ? 'Suspend' : 'Activate';
                            $btn_class = $a['status'] == 'active' ? 'btn-danger' : 'btn-success';
                            echo "<tr>
                                    <td><strong>{$a['name']}</strong><br><small>{$a['email']}</small></td>
                                    <td>{$a['college_name']}</td>
                                    <td><span class='{$badge}'>".ucfirst($a['status'])."</span></td>
                                    <td>
                                        <form method='POST' style='display:inline;'><input type='hidden' name='admin_id' value='{$a['id']}'><button type='submit' name='toggle_admin' class='btn btn-sm {$btn_class}'>{$btn_text}</button></form>
                                        <button class='btn btn-sm btn-danger' onclick=\"confirmAdminDelete({$a['id']}, '{$a['name']}')\"><i class='fa-solid fa-trash'></i></button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
            <!-- TRASH -->
            <div class="panel" style="border-color: rgba(246, 70, 93, 0.3); margin-top: 10px;">
                <h3 class="panel-title" style="color:var(--danger);"><i class="fa-solid fa-trash-can-arrow-up"></i> Recycle Bin (Admins)</h3>
                <table>
                    <?php
                    $trash_a = mysqli_query($conn, "SELECT * FROM admin WHERE is_deleted=1");
                    while($t = mysqli_fetch_assoc($trash_a)){
                        echo "<tr><td><strike>{$t['name']}</strike></td><td>{$t['deleted_at']}</td><td><form method='POST'><input type='hidden' name='admin_id' value='{$t['id']}'><button type='submit' name='restore_admin' class='btn btn-sm btn-success'>Restore</button></form></td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 4: GLOBAL OVERSIGHT (The Magic SPA Drill-down) -->
        <!-- ============================================== -->
        <div id="admin_oversight" class="section-tab">
            <div class="breadcrumbs" id="oversight-breadcrumbs">
                <span class="bc-item active" onclick="navToLevel('lvl1', 'Colleges', this, true)"><i class="fa-solid fa-earth-americas"></i> All Colleges</span>
            </div>

            <!-- LEVEL 1: LIST OF COLLEGES -->
            <div id="view-lvl1" class="oversight-view active">
                <div class="magic-grid">
                    <?php
                    $colleges = mysqli_query($conn, "SELECT c.*, a.name as admin_name FROM colleges c LEFT JOIN admin a ON c.id = a.college_id WHERE c.is_deleted=0");
                    if(mysqli_num_rows($colleges) > 0){
                        while($col = mysqli_fetch_assoc($colleges)){
                            $col_id = $col['id'];
                            $admin_name = $col['admin_name'] ? "Admin: ".$col['admin_name'] : "No Admin Assigned";
                            $dept_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM departments WHERE college_id=$col_id"))['c'];
                            
                            echo "<div class='magic-drill-card lvl-college' onclick=\"navToLevel('lvl2_col_{$col_id}', '{$col['college_name']}')\">
                                    <div class='card-icon' style='background:linear-gradient(135deg, #3b82f6, #1d4ed8);'><i class='fa-solid fa-building-columns'></i></div>
                                    <h3>{$col['college_name']}</h3>
                                    <p><i class='fa-solid fa-user-shield'></i> {$admin_name}</p>
                                    <span class='card-meta' style='color:#3b82f6;'><i class='fa-solid fa-layer-group'></i> {$dept_count} Departments</span>
                                  </div>";
                        }
                    } else { echo "<p style='color:var(--text-muted);'>No colleges found.</p>"; }
                    ?>
                </div>
            </div>

            <!-- LEVEL 2: DEPARTMENTS -->
            <?php
            mysqli_data_seek($colleges, 0); 
            while($col = mysqli_fetch_assoc($colleges)){
                $col_id = $col['id'];
                echo "<div id='view-lvl2_col_{$col_id}' class='oversight-view'><div class='magic-grid'>";
                
                $depts = mysqli_query($conn, "SELECT d.*, h.name as head_name FROM departments d LEFT JOIN head h ON d.id = h.dept_id WHERE d.college_id=$col_id");
                if(mysqli_num_rows($depts) > 0){
                    while($dept = mysqli_fetch_assoc($depts)){
                        $dept_id = $dept['id'];
                        $head_name = $dept['head_name'] ? "Head: ".$dept['head_name'] : "No Head Assigned";
                        $teacher_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM teacher WHERE dept_id=$dept_id"))['c'];
                        
                        echo "<div class='magic-drill-card lvl-dept' onclick=\"navToLevel('lvl3_dept_{$dept_id}', '{$dept['dept_name']}')\">
                                <div class='card-icon' style='background:linear-gradient(135deg, #8b5cf6, #6d28d9);'><i class='fa-solid fa-users-gear'></i></div>
                                <h3>{$dept['dept_name']}</h3>
                                <p><i class='fa-solid fa-user-tie'></i> {$head_name}</p>
                                <span class='card-meta' style='color:#8b5cf6;'><i class='fa-solid fa-chalkboard-user'></i> {$teacher_count} Teachers</span>
                              </div>";
                    }
                } else { echo "<div class='panel' style='grid-column: 1 / -1;'><p style='color:var(--text-muted);'>No departments registered under this college.</p></div>"; }
                echo "</div></div>";
            }
            ?>

            <!-- LEVEL 3: TEACHERS -->
            <?php
            $all_depts = mysqli_query($conn, "SELECT id FROM departments");
            if($all_depts) {
                while($dept = mysqli_fetch_assoc($all_depts)){
                    $dept_id = $dept['id'];
                    echo "<div id='view-lvl3_dept_{$dept_id}' class='oversight-view'><div class='magic-grid'>";
                    
                    $teachers = mysqli_query($conn, "SELECT * FROM teacher WHERE dept_id=$dept_id");
                    if(mysqli_num_rows($teachers) > 0){
                        while($tech = mysqli_fetch_assoc($teachers)){
                            $tech_id = $tech['id'];
                            $student_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM student WHERE dept_id=$dept_id"))['c']; 
                            
                            echo "<div class='magic-drill-card lvl-teacher' onclick=\"navToLevel('lvl4_tech_{$tech_id}', 'Tr. {$tech['name']}')\">
                                    <div class='card-icon' style='background:linear-gradient(135deg, #10b981, #047857);'><i class='fa-solid fa-chalkboard-user'></i></div>
                                    <h3>Tr. {$tech['name']}</h3>
                                    <p><i class='fa-solid fa-envelope'></i> {$tech['email']}</p>
                                    <span class='card-meta' style='color:#10b981;'><i class='fa-solid fa-user-graduate'></i> {$student_count} Students in Dept</span>
                                  </div>";
                        }
                    } else { echo "<div class='panel' style='grid-column: 1 / -1;'><p style='color:var(--text-muted);'>No teachers registered in this department.</p></div>"; }
                    echo "</div></div>";
                }
            }
            ?>

            <!-- LEVEL 4: STUDENTS -->
            <?php
            $all_teachers = mysqli_query($conn, "SELECT id, dept_id FROM teacher");
            if($all_teachers) {
                while($tech = mysqli_fetch_assoc($all_teachers)){
                    $tech_id = $tech['id'];
                    $dept_id = $tech['dept_id'];
                    echo "<div id='view-lvl4_tech_{$tech_id}' class='oversight-view'><div class='panel' style='padding:0; overflow:hidden;'>";
                    
                    $students = mysqli_query($conn, "SELECT * FROM student WHERE dept_id=$dept_id ORDER BY name ASC");
                    if(mysqli_num_rows($students) > 0){
                        echo "<div style='padding:20px; border-bottom:1px solid var(--border-color); background:rgba(239, 68, 68, 0.05); color:#ef4444; font-weight:bold;'><i class='fa-solid fa-users'></i> Students List</div>";
                        while($stud = mysqli_fetch_assoc($students)){
                            $initial = strtoupper(substr($stud['name'], 0, 1));
                            echo "<div class='student-list-item'>
                                    <div class='student-avatar'>{$initial}</div>
                                    <div><h4 style='font-size:15px; margin-bottom:3px;'>{$stud['name']}</h4><span style='font-size:12px; color:var(--text-muted);'>@{$stud['username']} | {$stud['email']}</span></div>
                                    <div style='margin-left:auto;'><span class='badge' style='background:transparent; border-color:#ef4444; color:#ef4444;'>".ucfirst($stud['status'])."</span></div>
                                  </div>";
                        }
                    } else { echo "<div style='padding:30px; text-align:center; color:var(--text-muted);'>No students enrolled yet.</div>"; }
                    echo "</div></div>";
                }
            }
            ?>
        </div>

        <!-- ============================================== -->
        <!-- 5. TELEGRAM-STYLE COMMUNICATION HUB            -->
        <!-- ============================================== -->
        <div id="broadcast" class="section-tab">
            <div style="margin-bottom: 20px;">
                <h3 style="font-size: 22px; color: #fff; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-brands fa-telegram" style="color: #3b82f6;"></i> Security Communication Hub
                </h3>
                <p style="color: var(--text-muted); font-size: 13px;">Encrypted messaging. Broadcast to groups or initiate private chats.</p>
            </div>

            <div class="telegram-app">
                <div class="tg-sidebar">
                    <div class="tg-search-bar"><input type="text" id="tg-search" placeholder="Search users or groups..." onkeyup="filterTelegramChats()"></div>
                    <div class="tg-folders">
                        <div class="tg-folder active" onclick="switchFolder('all')">All Chats</div>
                        <div class="tg-folder" onclick="switchFolder('admin')">Admins (<?php echo $admins_count; ?>)</div>
                        <div class="tg-folder" onclick="switchFolder('head')">Heads (<?php echo $heads_count; ?>)</div>
                        <div class="tg-folder" onclick="switchFolder('teacher')">Teachers (<?php echo $teachers_count; ?>)</div>
                    </div>
                    <div class="tg-contacts" id="tg-contacts-list">
                        <!-- GROUPS -->
                        <div class="tg-contact-item chat-item-all chat-item-admin" onclick="openTelegramChat(0, 'admin', 1, '📢 All Admins Group', 'Broadcast to all <?php echo $admins_count; ?> Admins', '#f59e0b')">
                            <div class="tg-avatar group" style="background: linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-bullhorn"></i></div>
                            <div class="tg-info"><span class="tg-name">📢 All Admins</span><span class="tg-role">Official Broadcast Group</span></div>
                        </div>
                        <div class="tg-contact-item chat-item-all chat-item-head" onclick="openTelegramChat(0, 'head', 1, '📢 All Heads Group', 'Broadcast to all <?php echo $heads_count; ?> Department Heads', '#8b5cf6')">
                            <div class="tg-avatar group" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-bullhorn"></i></div>
                            <div class="tg-info"><span class="tg-name">📢 All Heads</span><span class="tg-role">Official Broadcast Group</span></div>
                        </div>
                        <div class="tg-contact-item chat-item-all chat-item-teacher" onclick="openTelegramChat(0, 'teacher', 1, '📢 All Teachers Group', 'Broadcast to all <?php echo $teachers_count; ?> Teachers', '#10b981')">
                            <div class="tg-avatar group" style="background: linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-bullhorn"></i></div>
                            <div class="tg-info"><span class="tg-name">📢 All Teachers</span><span class="tg-role">Official Broadcast Group</span></div>
                        </div>

                  <!-- PRIVATE CONTACTS -->
                        <?php
                        $admin_list = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM admin WHERE is_deleted=0");
                        while($a = mysqli_fetch_assoc($admin_list)){
                            $init = strtoupper(substr($a['name'],0,1));
                            $pic = ($a['profile_locked'] == 0 && $a['profile_pic'] != 'default_admin.png') ? $a['profile_pic'] : '';
                            echo "<div class='tg-contact-item chat-item-all chat-item-admin private-chat' onclick=\"openTelegramChat({$a['id']}, 'admin', 0, '{$a['name']}', 'College Admin', '#f59e0b', '{$pic}')\">
                                    <div class='tg-avatar' style='background:#f59e0b;' id='avatar_admin_{$a['id']}'>{$init}<div class='tg-online-dot'></div></div>
                                    <div class='tg-info'><span class='tg-name'>{$a['name']}</span><span class='tg-role' style='color:#f59e0b;'>Admin</span></div>
                                    <span class='chat-unread-badge' id='badge_admin_{$a['id']}' style='display:none;'>0</span>
                                  </div>";
                        }
                        $head_list = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM head");
                        if($head_list) {
                            while($h = mysqli_fetch_assoc($head_list)){
                                $init = strtoupper(substr($h['name'],0,1));
                                $pic = ($h['profile_locked'] == 0 && $h['profile_pic'] != 'default_head.png') ? $h['profile_pic'] : '';
                                echo "<div class='tg-contact-item chat-item-all chat-item-head private-chat' onclick=\"openTelegramChat({$h['id']}, 'head', 0, '{$h['name']}', 'Department Head', '#8b5cf6', '{$pic}')\">
                                        <div class='tg-avatar' style='background:#8b5cf6;' id='avatar_head_{$h['id']}'>{$init}</div>
                                        <div class='tg-info'><span class='tg-name'>{$h['name']}</span><span class='tg-role' style='color:#8b5cf6;'>Head</span></div>
                                        <span class='chat-unread-badge' id='badge_head_{$h['id']}' style='display:none;'>0</span>
                                      </div>";
                            }
                        }
                        $teacher_list = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM teacher");
                        if($teacher_list) {
                            while($t = mysqli_fetch_assoc($teacher_list)){
                                $init = strtoupper(substr($t['name'],0,1));
                                $pic = ($t['profile_locked'] == 0 && $t['profile_pic'] != 'default_teacher.png') ? $t['profile_pic'] : '';
                                echo "<div class='tg-contact-item chat-item-all chat-item-teacher private-chat' onclick=\"openTelegramChat({$t['id']}, 'teacher', 0, '{$t['name']}', 'Teacher', '#10b981', '{$pic}')\">
                                        <div class='tg-avatar' style='background:#10b981;' id='avatar_teacher_{$t['id']}'>{$init}</div>
                                        <div class='tg-info'><span class='tg-name'>{$t['name']}</span><span class='tg-role' style='color:#10b981;'>Teacher</span></div>
                                        <span class='chat-unread-badge' id='badge_teacher_{$t['id']}' style='display:none;'>0</span>
                                      </div>";
                            }
                        }
                        ?>
                    </div>
                </div>

                <!-- RIGHT CHAT AREA -->
                <div class="tg-chat-area">
                    <div id="tg-placeholder" class="tg-placeholder">
                        <i class="fa-regular fa-comments"></i>
                        <p>Select a chat to start messaging securely</p>
                    </div>
                    <div id="tg-active-chat" style="display: none; flex-direction: column; height: 100%;">
                        <div class="tg-chat-header">
                            <button type="button" class="btn btn-sm" style="background:transparent; color:var(--text-main); border:1px solid var(--border-color); margin-right:10px;" onclick="document.querySelector('.tg-sidebar').classList.toggle('collapsed')" title="Expand/Collapse Contacts">
                                <i class="fa-solid fa-bars-staggered"></i>
                            </button>
                            <div class="tg-avatar group" id="chat-header-avatar" style="background:#3b82f6;"><i class="fa-solid fa-users"></i></div>
                            <div>
                                <div class="tg-chat-title" id="chat-header-name">Chat Name</div>
                                <div class="tg-chat-status" id="chat-header-role">Online</div>
                            </div>
                        </div>
                        <div class="tg-chat-history" id="chat-history-container"></div>
                        <div class="tg-chat-input-area">
                            <form id="tg-chat-form" onsubmit="submitTelegramMsg(event)" class="tg-chat-form">
                                <input type="hidden" name="chat_receiver_id" id="chat_receiver_id">
                                <input type="hidden" name="chat_receiver_role" id="chat_receiver_role">
                                <input type="hidden" name="chat_is_group" id="chat_is_group">
                                <input type="hidden" name="edit_msg_id" id="edit_msg_id">
                                <i class="fa-solid fa-paperclip" style="color:var(--text-muted); font-size:18px; cursor:pointer; padding: 0 10px;"></i>
                                <input type="text" name="chat_message" id="chat_message_input" placeholder="Write a secure message..." required autocomplete="off">
                                <button type="submit"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- 6. SECURITY CENTER                             -->
        <!-- ============================================== -->
        <div id="audit" class="section-tab">
            <div style="margin-bottom: 25px;">
                <h3 style="font-size: 22px; color: var(--danger); display: flex; align-items: center; gap: 10px;"><i class="fa-solid fa-shield-halved"></i> Global Security Command Center</h3>
                <p style="color: var(--text-muted); font-size: 14px; margin-top: 5px;">Monitor active threats, auto-banned IPs, and strict oversight.</p>
            </div>
            <div class="panel" style="border-left: 4px solid #f59e0b; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                <h3 class="panel-title" style="color: #f59e0b;"><i class="fa-solid fa-user-shield"></i> College Admins Security Health</h3>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Admin Profile</th><th>Last Known IP</th><th>Last Login</th><th>Threat Level (24h)</th></tr></thead>
                        <tbody>
                            <?php
                            $admin_sec_q = "SELECT a.id, a.name, c.college_code, a.username,
                                            (SELECT attempt_time FROM login_logs WHERE username=a.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_login,
                                            (SELECT ip_address FROM login_logs WHERE username=a.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_ip,
                                            (SELECT COUNT(*) FROM login_logs WHERE username=a.username AND status='failed' AND attempt_time > NOW() - INTERVAL 1 DAY) as recent_fails
                                            FROM admin a JOIN colleges c ON a.college_id = c.id WHERE a.is_deleted=0";
                            $admin_sec_res = mysqli_query($conn, $admin_sec_q);
                            if(mysqli_num_rows($admin_sec_res) > 0) {
                                while($sec = mysqli_fetch_assoc($admin_sec_res)){
                                    $last_login = $sec['last_login'] ? date("d M Y, h:i A", strtotime($sec['last_login'])) : "<span style='color:var(--text-muted);'>Never logged in</span>";
                                    $last_ip = $sec['last_ip'] ? $sec['last_ip'] : "Unknown";
                                    $fails = $sec['recent_fails'];
                                    if($fails == 0) { $threat_badge = "<span class='badge'><i class='fa-solid fa-shield-check'></i> Secure (0 Fails)</span>"; }
                                    elseif($fails < 3) { $threat_badge = "<span class='badge' style='background:rgba(245,158,11,0.1); color:#f59e0b; border-color:rgba(245,158,11,0.3);'><i class='fa-solid fa-triangle-exclamation'></i> Low Risk ($fails Fails)</span>"; }
                                    else { $threat_badge = "<span class='badge badge-red'><i class='fa-solid fa-skull-crossbones'></i> HIGH RISK ($fails Fails)</span>"; }
                                    echo "<tr><td><strong style='color:var(--text-main); font-size:15px;'>{$sec['name']}</strong><br><small style='color:var(--primary);'>@{$sec['username']} | {$sec['college_code']}</small></td><td style='font-family:monospace; color:#8b5cf6;'>{$last_ip}</td><td style='font-size:13px;'>{$last_login}</td><td>{$threat_badge}</td></tr>";
                                }
                            } else { echo "<tr><td colspan='4' style='text-align:center; color:var(--text-muted);'>No active admins.</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="grid-2">
                <div class="panel" style="border-left: 4px solid var(--danger);">
                    <h3 class="panel-title" style="color: var(--danger);"><i class="fa-solid fa-ban"></i> Auto-Banned IPs</h3>
                    <div class="table-responsive">
                        <table>
                            <tr><th>IP Address</th><th>Reason</th><th>Action</th></tr>
                            <?php
                            $check_ban_table = mysqli_query($conn, "SHOW TABLES LIKE 'blocked_ips'");
                            if(mysqli_num_rows($check_ban_table) > 0) {
                                $banned_ips = mysqli_query($conn, "SELECT * FROM blocked_ips WHERE expires_at > NOW() ORDER BY banned_at DESC");
                                if(mysqli_num_rows($banned_ips) > 0){
                                    while($ip = mysqli_fetch_assoc($banned_ips)){
                                        echo "<tr><td style='font-family:monospace; color:var(--danger); font-weight:bold;'>{$ip['ip_address']}</td><td style='font-size:12px; color:var(--text-muted);'>{$ip['ban_reason']}</td><td><form method='POST'><input type='hidden' name='ip_id' value='{$ip['id']}'><button type='submit' name='unban_ip' class='btn btn-sm btn-success'><i class='fa-solid fa-unlock'></i> Unban</button></form></td></tr>";
                                    }
                                } else { echo "<tr><td colspan='3' style='text-align:center; padding:20px; color:var(--success);'><i class='fa-solid fa-shield-check' style='font-size:30px; display:block; margin-bottom:10px;'></i> No threats detected.</td></tr>"; }
                            } else { echo "<tr><td colspan='3' style='text-align:center;'>Table not initialized</td></tr>"; }
                            ?>
                        </table>
                    </div>
                </div>
                <div class="panel">
                    <h3 class="panel-title"><i class="fa-solid fa-server"></i> Live Global Auth Logs</h3>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table>
                            <thead><tr><th>Time</th><th>User / Agent</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php
                            $check_logs = mysqli_query($conn, "SHOW TABLES LIKE 'login_logs'");
                            if(mysqli_num_rows($check_logs) > 0) {
                                $logs = mysqli_query($conn, "SELECT * FROM login_logs ORDER BY attempt_time DESC LIMIT 20");
                                if(mysqli_num_rows($logs) > 0){
                                    while($l = mysqli_fetch_assoc($logs)){
                                        $time = date("H:i:s", strtotime($l['attempt_time']));
                                        if($l['status'] == 'success') { $s_badge = "<span style='color:var(--success); font-weight:bold;'><i class='fa-solid fa-check'></i> OK</span>"; }
                                        elseif($l['status'] == 'failed') { $s_badge = "<span style='color:#f59e0b; font-weight:bold;'><i class='fa-solid fa-xmark'></i> FAIL</span>"; }
                                        elseif($l['status'] == 'otp_sent') { $s_badge = "<span style='color:var(--primary); font-weight:bold;'><i class='fa-solid fa-envelope'></i> OTP</span>"; }
                                        else { $s_badge = "<span style='color:var(--danger); font-weight:bold;'><i class='fa-solid fa-ban'></i> BLOCKED</span>"; }
                                        $agent = substr($l['user_agent'], 0, 20) . "...";
                                        echo "<tr style='border-bottom: 1px solid rgba(255,255,255,0.02);'><td style='font-size:12px; color:var(--text-muted);'>{$time}</td><td><strong style='color:var(--text-main); font-size:13px;'>{$l['username']}</strong><br><small style='font-family:monospace; color:var(--text-muted);'>{$l['ip_address']} | {$agent}</small></td><td>{$s_badge}</td></tr>";
                                    }
                                } else { echo "<tr><td colspan='3' style='text-align:center; color:var(--text-muted);'>No logs recorded yet.</td></tr>"; }
                            } else { echo "<tr><td colspan='3' style='text-align:center;'>Log table not initialized.</td></tr>"; }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

  <!-- ============================================== -->
        <!-- TAB: HELP & DOCUMENTATION CENTER               -->
        <!-- ============================================== -->
        <div id="help" class="section-tab">
            
            <!-- Hero Section -->
            <div class="help-hero">
                <h2>How can we help you, <?php echo htmlspecialchars($super_admin_name); ?>?</h2>
                <p>Welcome to the official EPLMS Super Admin Knowledge Base. Explore comprehensive guides, security protocols, and system architecture details below.</p>
                <div class="help-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="help-search-input" placeholder="Search for guides, settings, or security..." onkeyup="searchHelpTopics()">
                </div>
            </div>

            <!-- Quick Links / Categories -->
            <div class="help-grid-cards">
                <div class="help-card" onclick="document.getElementById('doc-getting-started').scrollIntoView({behavior: 'smooth'});">
                    <i class="fa-solid fa-rocket"></i>
                    <h3>Getting Started</h3>
                    <p>Learn the basics of navigating the Control Center and global stats.</p>
                </div>
                <div class="help-card" onclick="document.getElementById('doc-hierarchy').scrollIntoView({behavior: 'smooth'});">
                    <i class="fa-solid fa-network-wired"></i>
                    <h3>Hierarchy Management</h3>
                    <p>Manage Colleges, Admins, and Drill-down data structures.</p>
                </div>
                <div class="help-card" onclick="document.getElementById('doc-security').scrollIntoView({behavior: 'smooth'});">
                    <i class="fa-solid fa-shield-halved"></i>
                    <h3>Security & Audits</h3>
                    <p>Threat detection, Auto-bans, and 2FA configuration.</p>
                </div>
                <div class="help-card" onclick="document.getElementById('doc-communication').scrollIntoView({behavior: 'smooth'});">
                    <i class="fa-brands fa-telegram"></i>
                    <h3>Communications</h3>
                    <p>Using the Telegram-style hub for broadcasts and private chats.</p>
                </div>
            </div>

            <!-- DOCUMENTATION CONTENT -->
            <div id="help-content-wrapper">
                
                <!-- Section 1 -->
                <div class="help-topic-section" id="doc-getting-started">
                    <h3 class="help-topic-title"><i class="fa-solid fa-rocket" style="color:var(--primary);"></i> 1. Getting Started: The Control Center</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">What is the Control Center? <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The Control Center is your landing page and the heart of the EPLMS. It provides a real-time, animated overview of your entire university network.</p>
                            <ul class="help-step-list">
                                <li><strong>Animated Counters:</strong> Instantly view the total number of Colleges, Admins, Heads, Teachers, and Students. These numbers update in real-time.</li>
                                <li><strong>System Demographics:</strong> The Donut Chart (powered by Chart.js) visualizes the ratio of users across different roles.</li>
                                <li><strong>Live System Health:</strong> Monitors the status of the Core Database, Security Firewall, and SMTP Email server to ensure 99.9% uptime.</li>
                            </ul>
                            <div class="help-pro-tip">
                                <strong><i class="fa-solid fa-lightbulb"></i> Pro Tip:</strong> Click the "Dark Mode / Light Mode" toggle at the top right to switch themes. The system remembers your preference automatically!
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2 -->
                <div class="help-topic-section" id="doc-hierarchy">
                    <h3 class="help-topic-title"><i class="fa-solid fa-building-columns" style="color:#3b82f6;"></i> 2. Managing Colleges & Admins</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">How to Add, Edit, or Delete a College <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>Under the <strong>Manage Colleges</strong> tab, you hold absolute power over the institution's structure.</p>
                            <ul class="help-step-list">
                                <li><strong>Add College:</strong> Enter the official name and a unique short code (e.g., COE for College of Engineering).</li>
                                <li><strong>Edit College:</strong> Click the Yellow Pen icon. The form will glow, allowing you to update details securely.</li>
                                <li><strong>Soft Delete (Recycle Bin):</strong> Click the Red Trash icon. </li>
                            </ul>
                            <div class="help-warning">
                                <strong><i class="fa-solid fa-triangle-exclamation"></i> Security Verification:</strong> Deleting a college requires your Super Admin password. Once deleted, it moves to the Recycle Bin for 30 days before permanent automatic purging. You can restore it anytime within this window.
                            </div>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">How the Drill-Down Oversight Works <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The <strong>Global Oversight</strong> tab uses a Single Page Application (SPA) magic drill-down architecture.</p>
                            <p>Instead of cluttered tables, simply click on a College card to slide into its Departments. Click a Department to view Teachers, and click a Teacher to view their enrolled Students. Use the Breadcrumbs at the top (e.g., <em>All Colleges > Engineering</em>) to navigate backward smoothly.</p>
                        </div>
                    </div>
                </div>

                <!-- Section 3 -->
                <div class="help-topic-section" id="doc-communication">
                    <h3 class="help-topic-title"><i class="fa-brands fa-telegram" style="color:#0ea5e9;"></i> 3. Security Communication Hub</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Using the Telegram-Style Chat <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The Communications tab allows encrypted messaging without ever reloading the page (AJAX-powered).</p>
                            <ul class="help-step-list">
                                <li><strong>Folders:</strong> Use the left sidebar to filter contacts by All, Admins, Heads, or Teachers.</li>
                                <li><strong>Broadcasts:</strong> Click on a "📢 Group" to send a mass notification to all users in that role.</li>
                                <li><strong>Private Chat:</strong> Click an individual's name to send a secure, private message.</li>
                                <li><strong>Right-Click Magic:</strong> Right-click on any message you sent. A custom context menu will appear allowing you to <strong>Edit</strong> or <strong>Delete</strong> the message globally in real-time.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Section 4 -->
                <div class="help-topic-section" id="doc-security">
                    <h3 class="help-topic-title"><i class="fa-solid fa-shield-halved" style="color:var(--danger);"></i> 4. Cyber Security & Threat Management</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Understanding Threat Intelligence (Auto-Bans) <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>EPLMS is built with a military-grade <strong>Anti-Brute Force mechanism</strong>.</p>
                            <p>If any user or bot attempts to log in with an incorrect password 5 times, their IP Address is instantly captured and banned for 24 hours. You can view these banned IPs in the <strong>Security Center</strong> and manually unban them if necessary.</p>
                            <div class="help-pro-tip">
                                <strong><i class="fa-solid fa-server"></i> Live Global Auth Logs:</strong> Every login attempt (Success, Failed, Blocked) is recorded with the exact time, User Agent (Browser info), and IP Address for your auditing purposes.
                            </div>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Configuring 2-Factor Authentication (2FA) <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>Navigate to the <strong>Settings</strong> tab and open <em>Account Security</em>. Turn on the 2FA switch. Once activated, every login attempt will send a dynamic 6-digit OTP code to your registered email. You must enter this code to access the dashboard.</p>
                        </div>
                    </div>
                </div>

            </div> <!-- End Content Wrapper -->
            
            <div style="text-align: center; margin-top: 40px; color: var(--text-muted); font-size: 13px;">
                <p>EPLMS Super Admin Documentation v1.0 <br> Developed for Ultimate Control and Maximum Security.</p>
            </div>

        </div>

<style>
    .acc-item { margin-bottom: 10px; border: 1px solid var(--border-color); border-radius: 8px; }
    .acc-btn { width: 100%; padding: 15px; background: rgba(255,255,255,0.05); color: var(--text-main); border: none; text-align: left; font-weight: 700; cursor: pointer; display: flex; justify-content: space-between; }
    .acc-content { padding: 15px; display: none; color: var(--text-muted); font-size: 14px; line-height: 1.6; }
    .acc-btn::after { content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900; }

</style>

<script>
    function toggleAcc(btn) {
        let content = btn.nextElementSibling;
        content.style.display = content.style.display === "block" ? "none" : "block";
    }
</script>

<!-- ============================================== -->
        <!-- TAB: ABOUT EPLMS (Global System Documentation) -->
        <!-- ============================================== -->
        <div id="about" class="section-tab">
            <div class="panel" style="background: var(--panel-bg); color: var(--text-main); padding: 40px; border-radius: 20px;">
                
                <!-- Title & Header -->
                <div style="text-align: center; margin-bottom: 50px;">
                    <h2 style="font-size: 38px; color: var(--primary); margin-bottom: 10px;">EPLMS v2.0</h2>
                    <p style="font-size: 16px; color: var(--text-muted);">The Ultimate Exam Portal & Learning Management System</p>
                </div>

                <!-- Book-like Content Layout -->
                <div style="column-count: 2; column-gap: 50px; line-height: 1.8; font-size: 15px; color: var(--text-main);">
                    
                    <h3 style="color:var(--primary); margin-bottom:15px;"><i class="fa-solid fa-book"></i> System Overview</h3>
                    <p style="margin-bottom:20px;">EPLMS is a revolutionary academic platform developed for Bule Hora University. It centralizes all academic processes, from college creation to student grade management.</p>
                    
                    <h3 style="color:var(--primary); margin-bottom:15px;"><i class="fa-solid fa-users"></i> User Hierarchy</h3>
                    <p style="margin-bottom:20px;">The system operates on a 5-tier role-based access control (RBAC) model:</p>
                    <ol style="margin-left:20px; margin-bottom:20px;">
                        <li><strong>Super Admin:</strong> Global control, system security, and audit logs.</li>
                        <li><strong>College Admin:</strong> Manages specific colleges and their respective staff.</li>
                        <li><strong>Head of Dept:</strong> Manages departments, assigns courses, and monitors teachers.</li>
                        <li><strong>Teacher:</strong> Manages course materials, assignments, and student grades.</li>
                        <li><strong>Student:</strong> Accesses resources, tracks grades, and communicates securely.</li>
                    </ol>

                    <h3 style="color:var(--primary); margin-bottom:15px;"><i class="fa-solid fa-shield-halved"></i> Security & Privacy</h3>
                    <p style="margin-bottom:20px;">We prioritize data integrity. Our multi-layer security includes:</p>
                    <ul style="margin-left:20px; margin-bottom:20px;">
                        <li><strong>End-to-End Encryption:</strong> All chat messages are secured.</li>
                        <li><strong>Brute Force Defense:</strong> Automatic IP banning after suspicious login attempts.</li>
                        <li><strong>2FA (Two-Factor Auth):</strong> Mandatory verification codes for critical system access.</li>
                    </ul>

                    <h3 style="color:var(--primary); margin-bottom:15px;"><i class="fa-solid fa-database"></i> Data Architecture</h3>
                    <p style="margin-bottom:20px;">Our database uses advanced relational mapping. Relationships are strictly defined with foreign key constraints, ensuring no orphaned data (e.g., deleting a college cascades to linked records securely).</p>

                    <h3 style="color:var(--primary); margin-bottom:15px;"><i class="fa-solid fa-server"></i> Storage & Cloud Strategy</h3>
                    <p>The system supports local storage and cloud-based backups to ensure university records are never lost. We perform daily snapshots of the database.</p>
                </div>

                <!-- Footer Sign-off -->
                <div style="margin-top: 50px; padding-top: 30px; border-top: 1px solid var(--border-color); text-align: center; color: var(--text-muted);">
                    <p><strong>Developed by EPLMS Engineering Team</strong></p>
                    <p style="font-size: 12px; margin-top: 10px;">&copy; <?php echo date("Y"); ?> All rights reserved. Unauthorized access is strictly prohibited.</p>
                </div>

            </div>
        </div>
       <!-- ============================================== -->
        <!-- 🌐 TAB: WEBSITE CONTENT & SOCIAL MEDIA         -->
        <!-- ============================================== -->
        <div id="social_media" class="section-tab">
            
            <!-- 1. SOCIAL MEDIA LINKS SECTION -->
            <div class="panel" style="border-top: 4px solid #e81cff; margin-bottom: 30px;">
                <div style="margin-bottom: 25px;">
                    <h3 style="font-size: 22px; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-share-nodes" style="color: #e81cff;"></i> Official Social Media Platforms
                    </h3>
                    <p style="color: var(--text-muted); font-size: 14px; margin-top: 5px;">Double-click any link below to update it instantly. These links are globally synced.</p>
                </div>

               <div class="social-grid" id="social-grid-wrapper">
                    <?php
                    $socials = mysqli_query($conn, "SELECT * FROM social_links");
                    while($sl = mysqli_fetch_assoc($socials)){
                        // Format icon properly (brands vs solid)
                        $icon_class = (strpos($sl['icon'], 'globe') !== false) ? "fa-solid" : "fa-brands";
                        
                        echo "
                        <div class='social-card' style='--brand-color: {$sl['brand_color']}; position:relative;' ondblclick='editSocial({$sl['id']})'>
                            <div class='social-icon'><i class='{$icon_class} {$sl['icon']}'></i></div>
                            <div class='social-info'>
                                <h4>{$sl['platform']}</h4>
                                <span id='social_text_{$sl['id']}' class='social-text'>{$sl['link_url']}</span>
                                <input type='text' id='social_input_{$sl['id']}' class='social-input' style='display:none;' value='{$sl['link_url']}' 
                                    onblur='saveSocial({$sl['id']})' onkeypress='handleSocialEnter(event, {$sl['id']})'>
                            </div>
                            <span class='dbl-click-hint'><i class='fa-solid fa-hand-pointer'></i> Dbl-click to edit</span>
                            
                            <!-- 🗑️ Delete Button (Visible on hover via CSS) -->
                            <form method='POST' style='position:absolute; top:8px; right:8px; margin:0;' onsubmit=\"return confirm('Delete this link?');\">
                                <input type='hidden' name='del_social_id' value='{$sl['id']}'>
                                <button type='submit' name='delete_social' style='background:rgba(246,70,93,0.1); border:none; color:var(--danger); cursor:pointer; width:20px; height:20px; border-radius:50%; display:flex; justify-content:center; align-items:center; opacity:0; transition:0.3s;' class='del-soc-btn'><i class='fa-solid fa-xmark' style='font-size:11px;'></i></button>
                            </form>
                        </div>";
                    }
                    ?>
                    
                    <!-- 🪄 MAGIC ADD NEW BUTTON -->
                    <div class='social-card' style='--brand-color: #3b82f6; border: 2px dashed rgba(59,130,246,0.5); background: rgba(59,130,246,0.02); justify-content: center; cursor: pointer;' onclick="showAddSocialInput()" id="add-social-btn">
                        <div style="text-align: center; color: #3b82f6;">
                            <i class="fa-solid fa-plus" style="font-size: 24px; margin-bottom: 5px;"></i>
                            <h4 style="margin:0; font-size:14px;">Add New Link</h4>
                        </div>
                    </div>

                    <!-- 🪄 HIDDEN INPUT FORM -->
                    <div class='social-card' style='display:none; border: 2px solid #3b82f6; padding: 15px;' id="add-social-form-card">
                        <form method="POST" style="width: 100%; display: flex; align-items: center; gap: 10px;">
                            <input type="hidden" name="add_magic_social" value="1">
                            <input type="url" name="new_social_url" id="new_social_input" placeholder="Paste URL & press Enter..." required style="flex:1; padding: 10px; border-radius: 8px; border: 1px solid #3b82f6; background: var(--bg-color); color: var(--text-main); font-size: 13px; outline:none;">
                            <button type="submit" class="btn btn-sm" style="background:#3b82f6; color:#fff; padding:10px;"><i class="fa-solid fa-check"></i></button>
                        </form>
                    </div>
                </div>
            </div>
<!-- 🪄 MAGIC: LOGIN PAGE BACKGROUND SECTION -->
            <div class="panel" style="border-top: 4px solid #10b981; margin-bottom: 30px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
                    <div>
                        <h3 style="font-size: 22px; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-photo-film" style="color: #10b981;"></i> Login Page Background (Video/Image)
                        </h3>
                        <p style="color: var(--text-muted); font-size: 14px; margin-top: 5px;">Change the main background of the EPLMS login portal. Upload an MP4 video or High-Quality Image.</p>
                    </div>
                </div>

                <div style="background: rgba(16, 185, 129, 0.05); border: 1px dashed #10b981; padding: 25px; border-radius: 16px; text-align: center; max-width: 500px;">
                    <form method="POST" enctype="multipart/form-data">
                        <i class="fa-solid fa-film" style="font-size: 30px; color: #10b981; margin-bottom: 15px;"></i>
                        <input type="file" name="bg_file" required accept="video/mp4, video/webm, image/jpeg, image/png, image/webp" style="width: 100%; padding: 10px; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-main); margin-bottom: 15px; font-size: 13px;">
                        <button type="submit" name="update_bg" class="btn" style="width: 100%; background: linear-gradient(135deg, #10b981, #059669); justify-content: center;"><i class="fa-solid fa-upload"></i> Set as Background</button>
                    </form>
                </div>
            </div>
            <!-- 2. 🪄 MAGIC HERO SLIDER MANAGEMENT SECTION -->
            <div class="panel" style="border-top: 4px solid #3b82f6;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
                    <div>
                        <h3 style="font-size: 22px; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-images" style="color: #3b82f6;"></i> Homepage Hero Sliders
                        </h3>
                        <p style="color: var(--text-muted); font-size: 14px; margin-top: 5px;">Upload and manage the dynamic banner images shown on the main login page.</p>
                    </div>
                </div>

                <div class="grid-2" style="grid-template-columns: 350px 1fr; align-items: start;">
                    
                    <!-- UPLOAD FORM -->
                    <div style="background: rgba(59, 130, 246, 0.05); border: 1px dashed #3b82f6; padding: 25px; border-radius: 16px; text-align: center;">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size: 40px; color: #3b82f6; margin-bottom: 15px;"></i>
                        <h4 style="color: var(--text-main); margin-bottom: 10px;">Add New Slide</h4>
                        <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">Recommended size: 1000x500px (PNG, JPG, WEBP).</p>
                        
                        <form method="POST" enctype="multipart/form-data">
<input type="file" name="slide_image" required accept="image/*" style="width: 100%; padding: 10px; background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-main); margin-bottom: 15px; font-size: 13px;">                            <button type="submit" name="upload_slide" class="btn" style="width: 100%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); justify-content: center;"><i class="fa-solid fa-plus"></i> Upload & Publish</button>
                        </form>
                    </div>

                   <!-- GALLERY GRID -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; max-height: 400px; overflow-y: auto; padding-right: 5px;">
                        <?php
                        // 🪄 Current Logo fiduu (Akka adda baafnuuf)
                        $sys_data_logo = mysqli_fetch_assoc(mysqli_query($conn, "SELECT site_logo FROM system_settings WHERE id=1"));
                        $current_logo = $sys_data_logo['site_logo'] ?? '';

                        $slides = mysqli_query($conn, "SELECT * FROM home_sliders ORDER BY id DESC");
                        if(mysqli_num_rows($slides) > 0){
                            $count = 1;
                            while($slide = mysqli_fetch_assoc($slides)){
                                
                                // 🪄 Check if this slide is the active logo
                                $is_logo = ($slide['image_path'] == $current_logo);
                                $star_color = $is_logo ? "#fcd535" : "#ffffff";
                                $star_title = $is_logo ? "Current Logo" : "Set as Logo";
                                
                                echo "
                                <div style='position: relative; border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color); box-shadow: 0 4px 10px rgba(0,0,0,0.1); group;'>
                                    <img src='../uploads/sliders/{$slide['image_path']}' style='width: 100%; height: 120px; object-fit: cover; display: block;'>
                                    
                                    <!-- 🪄 BUTTON HAARAA: Set Logo (Gara bitaa gubbaa) -->
                                    <div style='position: absolute; top: 5px; left: 5px; z-index: 5;'>
                                        <form method='POST' style='margin:0;'>
                                            <input type='hidden' name='logo_path' value='{$slide['image_path']}'>
                                            <button type='submit' name='set_logo' style='background: rgba(0,0,0,0.5); border: none; cursor: pointer; font-size: 16px; color: {$star_color}; padding: 5px 8px; border-radius: 5px; transition: 0.3s;' title='{$star_title}' onmouseover=\"this.style.color='#fcd535'\" onmouseout=\"this.style.color='{$star_color}'\">
                                                <i class='fa-solid fa-star'></i>
                                            </button>
                                        </form>
                                    </div>

                                    <div style='position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: flex; flex-direction: column; justify-content: center; align-items: center; opacity: 0; transition: 0.3s;' onmouseover=\"this.style.opacity='1'\" onmouseout=\"this.style.opacity='0'\">
                                        <form method='POST' onsubmit=\"return confirm('Delete this slide permanently?');\">
                                            <input type='hidden' name='slide_id' value='{$slide['id']}'>
                                            <button type='submit' name='delete_slide' class='btn btn-sm btn-danger' style='padding: 8px 15px;'><i class='fa-solid fa-trash'></i> Delete</button>
                                        </form>
                                    </div>
                                    <div style='position: absolute; bottom: 5px; left: 10px; background: rgba(0,0,0,0.7); color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: bold;'>Slide {$count}</div>
                                </div>";
                                $count++;
                            }
                        } else {
                            echo "<div style='grid-column: 1/-1; text-align: center; padding: 50px; color: var(--text-muted); border: 1px dashed var(--border-color); border-radius: 12px;'>
                                    <i class='fa-solid fa-images' style='font-size: 40px; margin-bottom: 10px; opacity: 0.3;'></i><br>
                                    No slides uploaded yet. The default system images will be shown to users.
                                  </div>";
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- 7. SUPER ADMIN SETTINGS                        -->
        <!-- ============================================== -->
        <div id="settings" class="section-tab">
            <div class="profile-header-card">
                <div class="profile-avatar-wrapper">
                    <img src="<?php echo $profile_pic; ?>" alt="Profile" class="profile-avatar-large" id="preview_avatar">
                    <label for="pic_upload" class="edit-avatar-btn"><i class="fa-solid fa-camera"></i></label>
                </div>
                <h2 class="profile-name"><?php echo htmlspecialchars($sa_info['name']); ?> <i class="fa-solid fa-circle-check" style="color:#0ecb81; font-size:20px;" title="Verified Identity"></i></h2>
                <p class="profile-email"><?php echo htmlspecialchars($sa_info['email']); ?></p>
                <div class="profile-badges">
                    <span class="p-badge"><i class="fa-solid fa-crown"></i> Super Admin</span>
                    <span class="p-badge active"><i class="fa-solid fa-shield-halved"></i> Full Access Control</span>
                </div>
                <div class="profile-stats-row">
                    <div class="p-stat-box"><h3>24</h3><p>Months Active</p></div>
                    <div class="p-stat-box"><h3><?php echo $colleges_count; ?></h3><p>Colleges Managed</p></div>
                    <div class="p-stat-box"><h3>100%</h3><p>Security Health</p></div>
                </div>
            </div>

            <div class="inner-tabs">
                <button class="inner-tab-btn active" onclick="switchInnerTab('account', this)"><i class="fa-solid fa-user-shield"></i> 1. Account Security</button>
                <button class="inner-tab-btn" onclick="switchInnerTab('msg_security', this)"><i class="fa-solid fa-message-lock"></i> 2. Message Security</button>
                <button class="inner-tab-btn" onclick="switchInnerTab('advanced', this)"><i class="fa-solid fa-server"></i> 3. Advanced & Privacy</button>
                <button class="inner-tab-btn" onclick="switchInnerTab('general', this)"><i class="fa-solid fa-sliders"></i> 4. General Settings</button>
            </div>

          <!-- TAB 1: ACCOUNT SECURITY (Unified Form) -->
            <div id="inner-account" class="inner-tab-content active">
                <form method="POST" enctype="multipart/form-data">
                    
                    <div class="settings-grid">
                        <!-- COLUMN 1: Profile & Contacts -->
                        <div class="panel">
                            <h3 style="color:var(--text-main); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-id-card" style="color:var(--primary);"></i> Profile Protection</h3>
                            <input type="file" id="pic_upload" name="profile_pic" style="display:none;" onchange="previewImage(this)">
                            
                            <div class="form-group"><label>Unique Username</label><input type="text" name="sa_username" value="<?php echo htmlspecialchars($sa_info['username']); ?>" required></div>
                            <div class="form-group"><label>Full Name</label><input type="text" name="sa_name" value="<?php echo htmlspecialchars($sa_info['name']); ?>" required></div>
                            
                            <div class="form-group"><label>Private Email (HIDDEN - For 2FA/OTP)</label><div class="input-with-icon"><input type="email" name="sa_email" value="<?php echo htmlspecialchars($sa_info['email']); ?>" required><i class="fa-solid fa-lock" style="color:var(--success);"></i></div></div>
                            <div class="form-group"><label>Public Email (System Sender Email)</label><div class="input-with-icon"><input type="email" name="sa_public_email" value="<?php echo htmlspecialchars($sa_info['public_email'] ?? ''); ?>" placeholder="security@eplms.com"><i class="fa-solid fa-envelope" style="color:var(--primary);"></i></div></div>
                            
                            <div class="form-group pw-group">
                                <label style="color:var(--primary);">Google App Password (For SMTP Sending)</label>
                                <div class="input-with-icon">
                                    <input type="password" name="sa_app_password" id="smtp_app_pass" value="<?php echo htmlspecialchars($sa_info['app_password'] ?? ''); ?>" placeholder="16-character code (e.g. xnlxbzrzlsjwrmhi)">
                                    <i class="fa-solid fa-key" style="color:var(--primary);"></i>
                                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('smtp_app_pass', this)"></i>
                                </div>
                            </div>
                            
                            <div class="form-group"><label>Phone Number (Optional)</label><div class="input-with-icon"><input type="text" name="sa_phone" value="<?php echo htmlspecialchars($sa_info['phone'] ?? ''); ?>" placeholder="+251..."><i class="fa-solid fa-phone"></i></div></div>
<div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-lock"></i> Profile Lock</h4><p>Hide your profile picture from others.</p></div><label class="switch"><input type="checkbox" name="profile_locked" <?php echo $sa_info['profile_locked'] ? 'checked' : ''; ?>><span class="slider"></span></label></div>                        </div>

                        <!-- COLUMN 2: Security & Toggles -->
                        <div class="panel">
                            <h3 style="color:var(--text-main); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-shield-check" style="color:var(--success);"></i> Authentication</h3>
                            
                            <div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-mobile-screen-button"></i> Two-Factor Auth (2FA)</h4><p>Require OTP to Private Email during login.</p></div>
                                <label class="switch"><input type="checkbox" name="two_factor" <?php echo $sa_info['two_factor_enabled'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            
                            <div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-bell"></i> Login Alerts</h4><p>Notify via email on new login attempts.</p></div>
                                <label class="switch"><input type="checkbox" name="login_alerts" <?php echo $sa_info['login_alerts'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>
                            
                            <h3 style="color:var(--text-main); font-size:16px; margin:25px 0 15px;"><i class="fa-solid fa-laptop"></i> Device Management</h3>
                            <div style="background: rgba(14,203,129,0.1); padding: 12px; border-radius: 8px; border: 1px solid rgba(14,203,129,0.2); font-size:13px; display:flex; justify-content:space-between; align-items:center;">
                                <div><i class="fa-solid fa-desktop"></i> Current Session<br><small>IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></small></div>
                                <span class="badge">Active Now</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- BOTTOM SECTION: Password & UNIFIED SAVE BUTTON -->
                    <div class="panel" style="margin-top: 25px; border: 1px solid rgba(91, 77, 255, 0.4); box-shadow: 0 10px 30px rgba(91, 77, 255, 0.1);">
                        <h3 style="color:var(--primary); font-size:18px; margin-bottom:15px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;">
                            <i class="fa-solid fa-shield-halved"></i> Security Authorization & Save
                        </h3>
                        
                        <div class="settings-grid">
                            <div class="form-group pw-group">
                                <label style="color:var(--danger); font-size:14px;">Current Password (Required to Save Any Changes)</label>
                                <div class="input-with-icon">
                                    <input type="password" name="current_password" id="curr_pass" placeholder="Enter your password to verify it's you..." required style="border-color: var(--danger);">
                                    <i class="fa-solid fa-shield" style="color:var(--danger);"></i>
                                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('curr_pass', this)"></i>
                                </div>
                            </div>
                            
                            <div class="form-group pw-group">
                                <label>New Strong Password (Optional)</label>
                                <div class="input-with-icon">
                                    <input type="password" name="new_password" id="new_pass" placeholder="Leave blank to keep current password" onkeyup="checkPasswordStrength()">
                                    <i class="fa-solid fa-key"></i>
                                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('new_pass', this)"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Password Rules UI -->
                        <div class="pw-rules" id="pw-rules">
                            <div id="rule-length" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</div>
                            <div id="rule-upper" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> One uppercase & lowercase</div>
                            <div id="rule-number" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> One number</div>
                            <div id="rule-special" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> One special character (@$!%*?&)</div>
                        </div>

                        <!-- UNIFIED MAGIC SAVE BUTTON -->
                        <div style="text-align: right; margin-top:25px; padding-top:20px; border-top: 1px solid var(--border-color);">
                            <button type="submit" name="save_all_settings" class="btn" style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color:#fff; padding:16px 35px; font-size:16px; border-radius:30px; box-shadow: 0 8px 20px rgba(91, 77, 255, 0.3); border:none;">
                                <i class="fa-solid fa-floppy-disk"></i> Save All Settings
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <!-- TAB 2: MESSAGE SECURITY -->
            <div id="inner-msg_security" class="inner-tab-content">
                <div class="settings-grid">
                    <div class="panel">
                        <h3 style="color:var(--text-main); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-comment-slash" style="color:var(--primary);"></i> Message Security</h3>
                        <div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-lock"></i> End-to-End Encryption</h4><p>Ensure all chat messages are encrypted.</p></div><label class="switch"><input type="checkbox" checked disabled><span class="slider"></span></label></div>
                        <div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-clock-rotate-left" style="color:var(--danger);"></i> Auto-Delete Messages</h4><p>Purge chats older than 90 days.</p></div><label class="switch"><input type="checkbox"><span class="slider"></span></label></div>
                    </div>
                    <div class="panel">
                        <h3 style="color:var(--text-main); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-bullhorn" style="color:#f59e0b;"></i> Broadcast Control</h3>
                        <div class="form-group"><label>Default Priority Alert Level</label><select><option>High Priority (Pops up on screen)</option><option>Normal (Silent Notification)</option></select></div>
                        <div class="form-group"><label>Schedule Messages</label><select><option>Allowed for Admins & Super Admins</option><option>Only Super Admin</option></select></div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: ADVANCED SECURITY -->
            <div id="inner-advanced" class="inner-tab-content">
                <div class="settings-grid">
                    <div class="panel">
                        <h3 style="color:var(--danger); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-shield-virus"></i> Advanced Security</h3>
                        <div class="form-group"><label>Session Timeout (Idle time)</label><select><option>15 Minutes</option><option>30 Minutes</option><option>1 Hour</option></select></div>
                        <div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-user-secret"></i> Anti-Brute Force</h4><p>Auto-ban IPs after 5 failed attempts.</p></div><label class="switch"><input type="checkbox" checked disabled><span class="slider"></span></label></div>
                    </div>
                    <div class="panel">
                        <h3 style="color:var(--primary); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-database"></i> Data & Privacy</h3>
                        <div class="sec-toggle"><div class="sec-toggle-info"><h4><i class="fa-solid fa-eye-slash"></i> Control Visibility</h4><p>Hide Phone Numbers from students.</p></div><label class="switch"><input type="checkbox" checked><span class="slider"></span></label></div>
                        <h4 style="margin-top:20px; font-size:13px; color:var(--text-main);">Backup Strategy</h4>
                        <div style="display:flex; gap:10px; margin-top:10px;">
                            <button class="btn btn-sm btn-primary"><i class="fa-solid fa-cloud-arrow-up"></i> Cloud Backup</button>
                            <button class="btn btn-sm btn-warning"><i class="fa-solid fa-hard-drive"></i> Local Backup</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: GENERAL SETTINGS -->
            <div id="inner-general" class="inner-tab-content">
                <div class="panel" style="max-width:600px; margin:0 auto;">
                    <h3 style="color:var(--text-main); font-size:16px; margin-bottom:15px;"><i class="fa-solid fa-sliders" style="color:var(--success);"></i> General System Settings</h3>
                    <div class="form-group"><label>System Theme Preference</label><select><option>Auto (Follow System)</option><option>Dark Mode (Default)</option><option>Light Mode</option></select></div>
                    <div class="form-group"><label>Default Language</label><select><option>English (US)</option><option>Afaan Oromoo</option><option>Amharic</option></select></div>
                    <div style="margin-top:25px; padding-top:15px; border-top:1px solid var(--border-color);">
                        <label style="display:block; font-size:13px; font-weight:bold; margin-bottom:10px;">Storage Usage (Database & Media)</label>
                        <div style="width: 100%; background: rgba(255,255,255,0.1); border-radius: 10px; height: 15px; overflow: hidden; margin-bottom:5px;"><div style="width: 45%; background: var(--primary); height: 100%;"></div></div>
                        <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted);"><span>45 GB Used</span><span>100 GB Total</span></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<!-- CUSTOM RIGHT CLICK MENU (CHAT) -->
<div id="chat-context-menu" class="chat-context-menu">
    <div class="context-item" id="ctx-edit"><i class="fa-solid fa-pen"></i> Edit Message</div>
    <div class="context-item delete" id="ctx-delete"><i class="fa-solid fa-trash"></i> Delete Message</div>
</div>

<!-- SECURE MODALS -->
<div id="secureDeleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Security Verification</h3>
        <p>Delete <strong id="del_col_name" style="color:var(--primary);"></strong>? Enter Super Admin password.</p>
        <form method="POST">
            <input type="hidden" name="college_id" id="del_col_id">
            <div class="form-group"><input type="password" name="sa_password" required style="text-align:center;"></div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('secureDeleteModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="soft_delete_college" class="btn btn-danger">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<div id="adminDeleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Security Verification</h3>
        <p>Delete <strong id="del_admin_name" style="color:var(--primary);"></strong>? Enter Super Admin password.</p>
        <form method="POST">
            <input type="hidden" name="admin_id" id="del_admin_id">
            <div class="form-group"><input type="password" name="sa_password" required style="text-align:center;"></div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('adminDeleteModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="soft_delete_admin" class="btn btn-danger">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        
        if(activeTab) { 
            openTab(activeTab); 
            window.history.replaceState({}, document.title, window.location.pathname + "?tab=" + activeTab); 
        } else { 
            openTab('home'); 
        }
    });

    setTimeout(() => { 
        let alertBox = document.querySelector('.alert'); 
        if(alertBox) {
            alertBox.style.transition = 'opacity 0.5s ease';
            alertBox.style.opacity = '0';
            setTimeout(() => alertBox.style.display = 'none', 500);
        }
    }, 5000);
    // --- 1. LIGHT / DARK MODE TOGGLE ---
    const themeIcon = document.getElementById('theme-icon');
    const themeText = document.getElementById('theme-text');
    function toggleTheme() {
        document.body.classList.toggle('light-mode');
        const isLight = document.body.classList.contains('light-mode');
        localStorage.setItem('eplms_theme', isLight ? 'light' : 'dark');
        if(themeIcon) themeIcon.className = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        if(themeText) themeText.innerText = isLight ? 'Dark Mode' : 'Light Mode';
    }
    if(localStorage.getItem('eplms_theme') === 'light'){
        document.body.classList.add('light-mode');
        if(themeIcon) themeIcon.className = 'fa-solid fa-sun';
        if(themeText) themeText.innerText = 'Dark Mode';
    }

    // --- 2. ANIMATED COUNTERS ---
    function animateCounters() {
        document.querySelectorAll('.counter').forEach(counter => {
            counter.innerText = '0';
            const target = +counter.getAttribute('data-target');
            const inc = target / 30; 
            const update = () => {
                const c = +counter.innerText;
                if(c < target) { counter.innerText = Math.ceil(c + inc); setTimeout(update, 30); }
                else { counter.innerText = target; }
            };
            update();
        });
    }
    animateCounters(); 

    // --- 3. TAB SWITCHING ---
    function openTab(tabId) {
        document.querySelectorAll('.section-tab').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-link').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        event.currentTarget.classList.add('active');
        if(tabId === 'home') { animateCounters(); if(window.demographicsChart) window.demographicsChart.update(); }
    }

    // --- 4. COLLEGE & ADMIN ACTIONS ---
    function editCollege(id, name, code) {
        document.getElementById('form_col_id').value = id; document.getElementById('form_col_name').value = name; document.getElementById('form_col_code').value = code;
        document.getElementById('btn_add_col').style.display = 'none'; document.getElementById('btn_edit_col').style.display = 'flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function confirmDelete(id, name) {
        document.getElementById('del_col_id').value = id; document.getElementById('del_col_name').innerText = name;
        document.getElementById('secureDeleteModal').classList.add('active');
    }
    function confirmAdminDelete(id, name) {
        document.getElementById('del_admin_id').value = id; document.getElementById('del_admin_name').innerText = name;
        document.getElementById('adminDeleteModal').classList.add('active');
    }

    // --- 5. MAGIC DRILL-DOWN NAVIGATION ---
    let breadcrumbTrail =[{ id: 'lvl1', title: 'All Colleges' }];
    function resetOversight() { navToLevel('lvl1', 'Colleges', null, true); }
    function navToLevel(viewId, title, element = null, isReset = false) {
        document.querySelectorAll('.oversight-view').forEach(el => el.classList.remove('active'));
        const targetView = document.getElementById('view-' + viewId);
        if(targetView) targetView.classList.add('active');
        
        const bcDiv = document.getElementById('oversight-breadcrumbs');
        if (isReset) { breadcrumbTrail =[{ id: viewId, title: 'All Colleges' }]; } 
        else if (element && element.classList.contains('bc-item')) {
            const idx = breadcrumbTrail.findIndex(item => item.id === viewId);
            breadcrumbTrail = breadcrumbTrail.slice(0, idx + 1);
        } else { breadcrumbTrail.push({ id: viewId, title: title }); }

        let bcHTML = '';
        breadcrumbTrail.forEach((item, index) => {
            if(index > 0) bcHTML += `<span class="bc-separator"><i class="fa-solid fa-chevron-right"></i></span>`;
            const isActive = (index === breadcrumbTrail.length - 1) ? 'active' : '';
            const icon = (index === 0) ? '<i class="fa-solid fa-earth-americas"></i> ' : '';
            bcHTML += `<span class="bc-item ${isActive}" onclick="navToLevel('${item.id}', '${item.title}', this)">${icon}${item.title}</span>`;
        });
        bcDiv.innerHTML = bcHTML;
    }

    // --- 6. CHART.JS ---
    const ctx = document.getElementById('demographicsChart').getContext('2d');
    window.demographicsChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels:['Admins', 'Heads', 'Teachers', 'Students'], datasets:[{ data:[<?php echo $admins_count; ?>, <?php echo $heads_count; ?>, <?php echo $teachers_count; ?>, <?php echo $students_count; ?>], backgroundColor:['#f59e0b', '#8b5cf6', '#10b981', '#ef4444'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '75%', plugins: { legend: { position: 'right' } } }
    });

   // --- 7. LIVE CLOCK (Time Only) ---
    function updateClock() {
        const now = new Date(); 
        let h = now.getHours(); let m = now.getMinutes(); let s = now.getSeconds();
        const ampm = h >= 12 ? 'PM' : 'AM';
        
        h = h % 12; h = h ? h : 12; 
        m = m < 10 ? '0'+m : m; 
        s = s < 10 ? '0'+s : s;
        
        if(document.getElementById('real-time-clock')) {
            document.getElementById('real-time-clock').innerText = `${h}:${m}:${s} ${ampm}`;
        }
    }
    setInterval(updateClock, 1000); updateClock();

    // --- 8. TELEGRAM CHAT LOGIC ---
    function switchFolder(folder) {
        document.querySelectorAll('.tg-folder').forEach(el => el.classList.remove('active'));
        event.currentTarget.classList.add('active');
        document.querySelectorAll('.tg-contact-item').forEach(el => {
            el.style.display = 'none'; 
            if (el.classList.contains('chat-item-' + folder)) el.style.display = 'flex'; 
        });
    }

    let currentChatId = null; let currentChatRole = null; let currentChatIsGroup = null; let chatInterval = null;

   function openTelegramChat(id, role, isGroup, name, subtitle, color, avatar_file = '') { 
        document.getElementById('tg-placeholder').style.display='none'; 
        document.getElementById('tg-active-chat').style.display='flex'; 
        document.getElementById('chat-header-name').innerHTML=name; 
        document.getElementById('chat-header-role').innerText=subtitle; 
        
        const avatarDiv = document.getElementById('chat-header-avatar'); 
        avatarDiv.style.background = color; 
        avatarDiv.style.position = 'relative'; // For premium badge positioning
        
        if(isGroup === 1) { 
            avatarDiv.innerHTML = '<i class="fa-solid fa-bullhorn"></i>'; 
            avatarDiv.classList.add('group'); 
        } else { 
            if(avatar_file && avatar_file !== '') {
                let imgHtml = `<img src="../uploads/${avatar_file}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
                
                if(role === 'super_admin') {
                    imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#0ea5e9; background:#fff; border-radius:50%; font-size:16px; border:2px solid var(--panel-bg); z-index:10;"></i>`;
                }
                avatarDiv.innerHTML = imgHtml;
                avatarDiv.style.background = 'transparent';
            } else {
                let initialHtml = name.replace(/<[^>]*>?/gm, '').trim().charAt(0).toUpperCase();
                if(role === 'super_admin') initialHtml = '<i class="fa-solid fa-crown"></i>'; // Super admin default icon
                
                if(role === 'super_admin') {
                    initialHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#0ea5e9; background:#fff; border-radius:50%; font-size:16px; border:2px solid var(--panel-bg); z-index:10;"></i>`;
                }
                avatarDiv.innerHTML = initialHtml; 
            }
            avatarDiv.classList.remove('group'); 
            avatarDiv.style.borderRadius = '50%'; 
        } 
        
        currentChatId=id; currentChatRole=role; currentChatIsGroup=isGroup; 
        document.getElementById('chat_receiver_id').value=id; 
        document.getElementById('chat_receiver_role').value=role; 
        document.getElementById('chat_is_group').value=isGroup; 
        document.getElementById('edit_msg_id').value=''; 
        
        fetchChatMessages(); 
        if(chatInterval) clearInterval(chatInterval); 
        chatInterval=setInterval(fetchChatMessages, 2500); 
    }
    function fetchChatMessages() {
        if(currentChatId === null) return;
        let formData = new FormData();
        formData.append('ajax_action', 'fetch_chat'); formData.append('receiver_id', currentChatId); formData.append('receiver_role', currentChatRole); formData.append('is_group', currentChatIsGroup);
        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.text()).then(html => {
            const chatHistory = document.getElementById('chat-history-container');
            let isAtBottom = chatHistory.scrollHeight - chatHistory.clientHeight <= chatHistory.scrollTop + 50;
            chatHistory.innerHTML = html;
            if(isAtBottom) chatHistory.scrollTop = chatHistory.scrollHeight;
        });
    }

    function submitTelegramMsg(e) {
        e.preventDefault();
        let input = document.getElementById('chat_message_input');
        if(input.value.trim() === '') return;
        let formData = new FormData(document.getElementById('tg-chat-form'));
        let editId = document.getElementById('edit_msg_id').value;
        formData.append('ajax_action', editId ? 'edit_msg' : 'send_msg');
        if(editId) formData.append('msg_id', editId);
        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => {
            if(data.status === 'success') {
                input.value = ''; document.getElementById('edit_msg_id').value = '';
                fetchChatMessages();
                setTimeout(() => { const chatHistory = document.getElementById('chat-history-container'); chatHistory.scrollTop = chatHistory.scrollHeight; }, 100);
            }
        });
    }

    function deleteMessage(msgId) {
        if(!confirm("Are you sure you want to delete this message for everyone?")) return;
        let formData = new FormData(); formData.append('ajax_action', 'delete_msg'); formData.append('msg_id', msgId);
        fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.json()).then(data => { if(data.status === 'success') fetchChatMessages(); });
    }

    function editMessage(msgId, text) {
        document.getElementById('chat_message_input').value = text;
        document.getElementById('edit_msg_id').value = msgId;
        document.getElementById('chat_message_input').focus();
    }

    let ctxMenuMsgId = null; let ctxMenuMsgText = "";
    function showContextMenu(e, msgId, msgText) {
        e.preventDefault(); 
        const ctxMenu = document.getElementById('chat-context-menu');
        ctxMenuMsgId = msgId; ctxMenuMsgText = msgText;
        ctxMenu.style.display = 'block';
        let x = e.pageX; let y = e.pageY;
        if(x + ctxMenu.offsetWidth > window.innerWidth) x = window.innerWidth - ctxMenu.offsetWidth - 10;
        if(y + ctxMenu.offsetHeight > window.innerHeight) y = window.innerHeight - ctxMenu.offsetHeight - 10;
        ctxMenu.style.left = x + 'px'; ctxMenu.style.top = y + 'px';
    }

    document.addEventListener('click', function(e) { const ctxMenu = document.getElementById('chat-context-menu'); if(ctxMenu.style.display === 'block') ctxMenu.style.display = 'none'; });
    document.getElementById('ctx-edit').addEventListener('click', function() { if(ctxMenuMsgId) editMessage(ctxMenuMsgId, ctxMenuMsgText); });
    document.getElementById('ctx-delete').addEventListener('click', function() { if(ctxMenuMsgId) deleteMessage(ctxMenuMsgId); });

    function filterTelegramChats() {
        let input = document.getElementById('tg-search').value.toLowerCase();
        document.querySelectorAll('.tg-contact-item').forEach(item => {
            let name = item.querySelector('.tg-name').innerText.toLowerCase();
            item.style.display = name.indexOf(input) > -1 ? "flex" : "none";
        });
    }

    // --- 9. INNER TABS & PROFILE PIC PREVIEW ---
    function switchInnerTab(tabName, btnElement) {
        document.querySelectorAll('.inner-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.inner-tab-content').forEach(content => content.classList.remove('active'));
        btnElement.classList.add('active');
        document.getElementById('inner-' + tabName).classList.add('active');
    }

    function previewImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) { document.getElementById('preview_avatar').src = e.target.result; }
            reader.readAsDataURL(input.files[0]);
        }
    }

    setTimeout(() => { let alert = document.querySelector('.alert'); if(alert) alert.style.display = 'none'; }, 4000);
// --- 13. HELP CENTER ACCORDION & SEARCH LOGIC ---
    function toggleHelpAcc(btn) {
        // Close all other accordions (Optional, remove if you want multiple open)
        /*
        document.querySelectorAll('.help-accordion-item').forEach(item => {
            if(item !== btn.parentElement) {
                item.classList.remove('active');
                item.querySelector('.help-acc-content').style.display = 'none';
            }
        });
        */
        
        const item = btn.parentElement;
        const content = btn.nextElementSibling;
        
        item.classList.toggle('active');
        if (item.classList.contains('active')) {
            content.style.display = "block";
        } else {
            content.style.display = "none";
        }
    }

    function searchHelpTopics() {
        let input = document.getElementById('help-search-input').value.toLowerCase();
        let items = document.querySelectorAll('.help-accordion-item');
        
        items.forEach(item => {
            let text = item.innerText.toLowerCase();
            if(text.indexOf(input) > -1) {
                item.style.display = "block";
            } else {
                item.style.display = "none";
            }
        });
    }
    // --- 14. SOCIAL MEDIA DOUBLE-CLICK MAGIC ---
    function editSocial(id) {
        document.getElementById('social_text_' + id).style.display = 'none';
        let input = document.getElementById('social_input_' + id);
        input.style.display = 'block';
        input.focus();
    }

    function handleSocialEnter(e, id) {
        if(e.key === 'Enter') { document.getElementById('social_input_' + id).blur(); }
    }

    function saveSocial(id) {
        let input = document.getElementById('social_input_' + id);
        let newVal = input.value.trim();
        let textSpan = document.getElementById('social_text_' + id);

        input.style.display = 'none';
        textSpan.style.display = 'block';

        if(newVal === textSpan.innerText) return;

        let fd = new FormData();
        fd.append('ajax_action', 'update_social');
        fd.append('id', id);
        fd.append('url', newVal);

        fetch(window.location.href, { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.status === 'success') {
                textSpan.innerText = newVal; 
                textSpan.parentElement.parentElement.style.boxShadow = '0 0 20px var(--success)';
                setTimeout(() => { textSpan.parentElement.parentElement.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)'; }, 1000);
            }
        }).catch(err => console.log(err));
    }
    // 🪄 MAGIC ADD SOCIAL FUNCTION
    function showAddSocialInput() {
        document.getElementById('add-social-btn').style.display = 'none';
        let formCard = document.getElementById('add-social-form-card');
        formCard.style.display = 'flex';
        document.getElementById('new_social_input').focus();
    }
    // --- 15. PASSWORD EYE ICON & VALIDATION LOGIC ---
    function togglePw(id, icon) {
        let input = document.getElementById(id);
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }

    function checkPasswordStrength() {
        let pw = document.getElementById('new_pass').value;
        let rulesBox = document.getElementById('pw-rules');
        
        if (pw.length > 0) rulesBox.style.display = 'block';
        else rulesBox.style.display = 'none';

        updateRule('rule-length', pw.length >= 8);
        updateRule('rule-upper', /[a-z]/.test(pw) && /[A-Z]/.test(pw));
        updateRule('rule-number', /\d/.test(pw));
        updateRule('rule-special', /[@$!%*?&]/.test(pw));
    }

    function updateRule(id, isValid) {
        let el = document.getElementById(id);
        let icon = el.querySelector('i');
        if(isValid) {
            el.classList.add('valid');
            icon.className = 'fa-solid fa-circle-check';
        } else {
            el.classList.remove('valid');
            icon.className = 'fa-solid fa-circle-xmark';
        }
    }
    // 🪄 MAGIC UNREAD BADGE SYSTEM
    function fetchUnreadBadges() {
        let fd = new FormData();
        fd.append('ajax_action', 'fetch_unread'); // Maqaan kun PHP wajjin tokko ta'uu qaba!
        
        fetch(window.location.href, {method:'POST', body:fd})
        .then(r=>r.json())
        .then(data=>{
            document.querySelectorAll('.chat-unread-badge').forEach(b => b.style.display = 'none');
            
            for(let key in data) {
                if(key !== 'total_all') {
                    let badge = document.getElementById('badge_' + key);
                    if(badge) {
                        if(currentChatRole + '_' + currentChatId !== key) {
                            badge.innerText = data[key];
                            badge.style.display = 'inline-block';
                        } else {
                            fetchChatMessages(); 
                        }
                    }
                }
            }
            
            let mainBadge = document.getElementById('main_comm_badge');
            if(mainBadge) {
                if(data.total_all > 0) {
                    mainBadge.innerText = data.total_all;
                    mainBadge.style.display = 'inline-block';
                    mainBadge.style.position = 'absolute';
                    mainBadge.style.right = '15px';
                    mainBadge.style.top = '50%';
                } else {
                    mainBadge.style.display = 'none';
                }
            }
        }).catch(err => console.log('Badge fetch error:', err));
        // 🪄 Auto-load avatars in sidebar
    window.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.tg-contact-item').forEach(item => {
            let onclickAttr = item.getAttribute('onclick');
            if(onclickAttr && onclickAttr.includes("openTelegramChat")) {
                let params = onclickAttr.match(/'([^']+)'/g); // Extract string params
                if(params && params.length >= 4) {
                    let role = params[0].replace(/'/g, "");
                    let avatar_file = params[4] ? params[4].replace(/'/g, "") : "";
                    
                    if(avatar_file && avatar_file !== '') {
                        let avatarDiv = item.querySelector('.tg-avatar');
                        if(avatarDiv && !avatarDiv.classList.contains('group')) {
                            let imgHtml = `<img src="../uploads/${avatar_file}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
                            if(role === 'super_admin') {
                                imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#0ea5e9; background:#fff; border-radius:50%; font-size:14px; border:2px solid var(--panel-bg); z-index:10;"></i>`;
                            }
                            avatarDiv.innerHTML = imgHtml;
                            avatarDiv.style.background = 'transparent';
                        }
                    }
                }
            }
        });
    });
    }

    setInterval(fetchUnreadBadges, 2000); 
    fetchUnreadBadges(); 
</script>
</body>
</html>