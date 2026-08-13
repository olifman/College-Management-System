<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include("../includes/config.php");

// ========================================================
// 🛡️ 1. SECURITY: COLLEGE ADMIN AUTHENTICATION
// ========================================================
if(!isset($_SESSION['username']) || $_SESSION['role'] != 'admin'){
    header("Location: ../index.php");
    exit();
}

$admin_name = $_SESSION['username'];
$admin_id = $_SESSION['user_id'];
$college_id = $_SESSION['college_id'];
$message = ""; $msg_type = "success";
// 🪄 MAGIC: Success Message Handler for Admin
if(isset($_GET['updated']) && $_GET['updated'] == 1){
    $message = "Settings Updated Successfully!";
    $msg_type = "success";
    echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
}
date_default_timezone_set('Africa/Addis_Ababa');
// Auto-Database fix for unread messages tracking
$check_read = mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE 'is_read'");
if(mysqli_num_rows($check_read) == 0) mysqli_query($conn, "ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0");
// ========================================================
// 🚀 2. REAL-TIME CHAT AJAX API (NOTIFICATIONS INCLUDED)
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
                $query = ($rec_role == 'head') 
                    ? "SELECT h.id FROM head h JOIN departments d ON h.dept_id=d.id WHERE d.college_id=$college_id AND h.is_deleted=0"
                    : "SELECT t.id FROM teacher t JOIN departments d ON t.dept_id=d.id WHERE d.college_id=$college_id";
                
                $users = mysqli_query($conn, $query);
                while($u = mysqli_fetch_assoc($users)) {
                    $u_id = $u['id'];
                    mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($admin_id, 'admin', $u_id, '$rec_role', '$msg', 0, 0)");
                }
                // Group record
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($admin_id, 'admin', 0, '$rec_role', '📢 BROADCAST: $msg', 1, 1)");
            } else {
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($admin_id, 'admin', $rec_id, '$rec_role', '$msg', 0, 0)");
            }
        }
        echo json_encode(['status'=>'success']); exit();
    }
    if($action == 'edit_msg') {
        $msg_id = intval($_POST['msg_id']);
        $new_msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        mysqli_query($conn, "UPDATE messages SET is_edited=1, message='$new_msg' WHERE id=$msg_id AND sender_id=$admin_id AND sender_role='admin'");
        echo json_encode(['status'=>'success']); exit();
    }
    if($action == 'delete_msg') {
        $msg_id = intval($_POST['msg_id']);
        mysqli_query($conn, "UPDATE messages SET is_deleted=1 WHERE id=$msg_id AND sender_id=$admin_id AND sender_role='admin'");
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
        
        // 🪄 Mark as Read automatically when chat is opened
        if($is_group == 0) {
            mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$admin_id AND receiver_role='admin'");
        }
        
        $query = ($is_group == 1) 
            ? "SELECT * FROM messages WHERE is_group=1 AND receiver_role='$rec_role' ORDER BY sent_at ASC" 
            : "SELECT * FROM messages WHERE is_group=0 AND ((sender_id=$admin_id AND sender_role='admin' AND receiver_id=$rec_id AND receiver_role='$rec_role') OR (sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$admin_id AND receiver_role='admin')) ORDER BY sent_at ASC";
        
        $res = mysqli_query($conn, $query);
        if(mysqli_num_rows($res) == 0) { echo "<div class='tg-placeholder'><i class='fa-solid fa-lock'></i><p>End-to-end encrypted. Say hello!</p></div>"; exit(); }

        $html = '';
        while($m = mysqli_fetch_assoc($res)){
            $is_me = ($m['sender_role'] == 'admin' && $m['sender_id'] == $admin_id);
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
}

// ========================================================
// 🔧 3. AUTO-DATABASE SETUP & MIGRATION (Admin Level)
// ========================================================
function addColAdmin($conn, $table, $col, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if(mysqli_num_rows($res) == 0) mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

addColAdmin($conn, 'departments', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColAdmin($conn, 'departments', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
addColAdmin($conn, 'head', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColAdmin($conn, 'head', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
addColAdmin($conn, 'admin', 'profile_pic', "VARCHAR(255) DEFAULT 'default_admin.png'");
addColAdmin($conn, 'admin', 'phone', 'VARCHAR(20) DEFAULT NULL');
addColAdmin($conn, 'admin', 'public_email', 'VARCHAR(100) DEFAULT NULL');
addColAdmin($conn, 'admin', 'app_password', 'VARCHAR(255) DEFAULT NULL');
addColAdmin($conn, 'admin', 'two_factor_enabled', 'TINYINT(1) DEFAULT 0');
addColAdmin($conn, 'admin', 'login_alerts', 'TINYINT(1) DEFAULT 1');

mysqli_query($conn, "DELETE FROM departments WHERE is_deleted=1 AND deleted_at < NOW() - INTERVAL 30 DAY");
mysqli_query($conn, "DELETE FROM head WHERE is_deleted=1 AND deleted_at < NOW() - INTERVAL 30 DAY");

$admin_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.*, c.college_name FROM admin a JOIN colleges c ON a.college_id = c.id WHERE a.id=$admin_id"));
$profile_pic = !empty($admin_info['profile_pic']) && file_exists("../uploads/".$admin_info['profile_pic']) ? "../uploads/".$admin_info['profile_pic'] : "https://ui-avatars.com/api/?name=".urlencode($admin_info['name'])."&background=3b82f6&color=fff";
// ========================================================
// ⚙️ 4. ADMIN SETTINGS & SECURITY LOGIC
// ========================================================
if(isset($_POST['save_all_settings'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['a_name']));
    $email = mysqli_real_escape_string($conn, trim($_POST['a_email'])); 
    $public_email = mysqli_real_escape_string($conn, trim($_POST['a_public_email'])); 
    $app_pass = mysqli_real_escape_string($conn, trim($_POST['a_app_password'])); 
    $phone = mysqli_real_escape_string($conn, trim($_POST['a_phone']));
    $username = mysqli_real_escape_string($conn, trim($_POST['a_username']));
    
    // Checkbox values
    $two_factor = isset($_POST['two_factor']) ? 1 : 0;
    $login_alerts = isset($_POST['login_alerts']) ? 1 : 0;
    $profile_locked = isset($_POST['profile_locked']) ? 1 : 0;
    
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];

    $verify = mysqli_query($conn, "SELECT id FROM admin WHERE id=$admin_id AND password='$current_pass'");
    if(mysqli_num_rows($verify) > 0){
        if(isset($_FILES['profile_pic']['name']) && !empty($_FILES['profile_pic']['name'])){
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $file_name = "admin_" . $admin_id . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], "../uploads/" . $file_name);
            mysqli_query($conn, "UPDATE admin SET profile_pic='$file_name' WHERE id=$admin_id");
        }
        
        $pass_query = !empty($new_pass) ? "password='$new_pass'," : "";
        $sql = "UPDATE admin SET name='$name', email='$email', public_email='$public_email', app_password='$app_pass', phone='$phone', username='$username', $pass_query two_factor_enabled=$two_factor, login_alerts=$login_alerts, profile_locked=$profile_locked WHERE id=$admin_id";
                
        if(mysqli_query($conn, $sql)){
            $_SESSION['username'] = $username; 
            echo "<script>window.location.href='dashboard.php?updated=1';</script>"; exit();
        } else { $message = "Database Error!"; $msg_type = "error"; }
    } else { $message = "Save Failed: Incorrect Current Password!"; $msg_type = "error"; }
}

// ========================================================
// 🏢 5. MANAGE DEPARTMENTS LOGIC (CRUD + TRASH)
// ========================================================
if(isset($_POST['add_dept'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['dept_name']));
    $code = mysqli_real_escape_string($conn, trim($_POST['dept_code']));
    $check = mysqli_query($conn, "SELECT id FROM departments WHERE dept_code='$code' AND college_id=$college_id AND is_deleted=0");
    if(mysqli_num_rows($check) > 0){ $message = "Department Code already exists!"; $msg_type = "error"; }
    else { 
        mysqli_query($conn, "INSERT INTO departments (college_id, dept_name, dept_code, is_deleted) VALUES ($college_id, '$name', '$code', 0)"); 
        mysqli_query($conn, "INSERT INTO admin_activities (admin_id, action_type, details) VALUES ($admin_id, 'Created Department', 'Added $name')");
        $message = "Department Added Successfully!"; 
    }
}
if(isset($_POST['edit_dept'])){
    $d_id = intval($_POST['dept_id']); $name = mysqli_real_escape_string($conn, trim($_POST['dept_name'])); $code = mysqli_real_escape_string($conn, trim($_POST['dept_code']));
    mysqli_query($conn, "UPDATE departments SET dept_name='$name', dept_code='$code' WHERE id=$d_id AND college_id=$college_id"); 
    mysqli_query($conn, "INSERT INTO admin_activities (admin_id, action_type, details) VALUES ($admin_id, 'Updated Department', 'Updated info for $name')");
    $message = "Department Updated Successfully!";
}
if(isset($_POST['soft_delete_dept'])){
    $d_id = intval($_POST['dept_id']); $password = $_POST['admin_password']; 
    $verify = mysqli_query($conn, "SELECT id FROM admin WHERE id=$admin_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){ 
        mysqli_query($conn, "UPDATE departments SET is_deleted=1, deleted_at=NOW() WHERE id=$d_id AND college_id=$college_id"); 
        mysqli_query($conn, "INSERT INTO admin_activities (admin_id, action_type, details) VALUES ($admin_id, 'Deleted Department', 'Moved dept to trash')");
        $message = "Department moved to Trash!"; 
    } else { $message = "Authentication Failed!"; $msg_type = "error"; }
}
if(isset($_POST['restore_dept'])){
    $d_id = intval($_POST['dept_id']); mysqli_query($conn, "UPDATE departments SET is_deleted=0, deleted_at=NULL WHERE id=$d_id AND college_id=$college_id"); 
    $message = "Department Restored Successfully!";
}

// ========================================================
// 👔 6. MANAGE DEPARTMENT HEADS LOGIC
// ========================================================
if(isset($_POST['add_head'])){
    $d_id = intval($_POST['dept_id']); $name = mysqli_real_escape_string($conn, trim($_POST['head_name'])); $email = mysqli_real_escape_string($conn, trim($_POST['head_email']));
    $username = mysqli_real_escape_string($conn, trim($_POST['head_username'])); $password = $_POST['head_password'];
    $check = mysqli_query($conn, "SELECT id FROM head WHERE username='$username' OR email='$email'");
    if(mysqli_num_rows($check) > 0){ $message = "Username/Email already exists!"; $msg_type = "error"; } 
    else { 
        mysqli_query($conn, "INSERT INTO head (dept_id, name, email, username, password, status, is_deleted) VALUES ($d_id, '$name', '$email', '$username', '$password', 'active', 0)"); 
        mysqli_query($conn, "INSERT INTO admin_activities (admin_id, action_type, details) VALUES ($admin_id, 'Assigned Head', 'Assigned $name')");
        $message = "Department Head Assigned Successfully!"; 
    }
}
if(isset($_POST['toggle_head'])){
    $id = intval($_POST['head_id']); mysqli_query($conn, "UPDATE head SET status = IF(status='active', 'inactive', 'active') WHERE id=$id"); 
    mysqli_query($conn, "INSERT INTO admin_activities (admin_id, action_type, details) VALUES ($admin_id, 'Toggled Head', 'Changed head account status')");
    $message = "Head Status Updated!";
}
if(isset($_POST['soft_delete_head'])){
    $h_id = intval($_POST['head_id']); $password = $_POST['admin_password']; 
    $verify = mysqli_query($conn, "SELECT id FROM admin WHERE id=$admin_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){ 
        mysqli_query($conn, "UPDATE head SET is_deleted=1, deleted_at=NOW() WHERE id=$h_id"); 
        mysqli_query($conn, "INSERT INTO admin_activities (admin_id, action_type, details) VALUES ($admin_id, 'Deleted Head', 'Moved head to trash')");
        $message = "Head moved to Trash!"; 
    } else { $message = "Authentication Failed!"; $msg_type = "error"; }
}
if(isset($_POST['restore_head'])){
    $h_id = intval($_POST['head_id']); mysqli_query($conn, "UPDATE head SET is_deleted=0, deleted_at=NULL WHERE id=$h_id"); 
    $message = "Head Restored Successfully!";
}

// ========================================================
// 📊 7. FETCH LIVE DASHBOARD DATA
// ========================================================
$dept_count    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM departments WHERE college_id=$college_id AND is_deleted=0"))['total'];
$heads_count   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM head h JOIN departments d ON h.dept_id = d.id WHERE d.college_id=$college_id AND h.is_deleted=0"))['total'];
$teachers_count= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM teacher t JOIN departments d ON t.dept_id = d.id WHERE d.college_id=$college_id"))['total'];
$students_count= mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM student s JOIN departments d ON s.dept_id = d.id WHERE d.college_id=$college_id"))['total'];

$trash_dept_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM departments WHERE college_id=$college_id AND is_deleted=1"))['total'];
$trash_head_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM head h JOIN departments d ON h.dept_id = d.id WHERE d.college_id=$college_id AND h.is_deleted=1"))['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1400">
<title>EPLMS - College Admin Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* ======================================================== */
    /* 🎨 SYSTEM STYLES */
    /* ======================================================== */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    
    :root { 
        --bg-color: #0b0e14; --panel-bg: #181a20; --border-color: #2b3139; --text-main: #eaecef; --text-muted: #848e9c;
        --primary: #3b82f6; --primary-hover: #2563eb; --danger: #f6465d; --success: #0ecb81; --input-bg: #0b0e14;
    }
    body.light-mode {
        --bg-color: #f0f4f8; --panel-bg: #ffffff; --border-color: #e2e8f0; --text-main: #2d3436; --text-muted: #636e72;
        --primary: #3b82f6; --primary-hover: #2563eb; --input-bg: #f9f9f9;
    }
    body { background: var(--bg-color); color: var(--text-main); display: flex; height: 100vh; overflow-x: auto; overflow-y: hidden; transition: 0.3s; }
    /* ... Koodiin gidduu jiru akkuma jirutti dhiisi ... */
    .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; overflow-x: auto; min-width: 1000px; width: 100%; scroll-behavior: smooth; }
    /* SIDEBAR */
    .sidebar { position: relative; width: 280px; background: var(--panel-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; transition: width 0.3s ease; z-index: 100; }
    .sidebar.collapsed { width: 80px; }
    .sidebar.collapsed .sidebar-header h2, .sidebar.collapsed .nav-links span, .sidebar.collapsed .logout-btn span { display: none; }
    .sidebar.collapsed .sidebar-header { justify-content: center; padding: 25px 0; }
    .sidebar.collapsed .nav-links button { justify-content: center; padding: 14px 0; }
    .sidebar.collapsed .nav-links i.icon { margin: 0; font-size: 22px; }
    .sidebar.collapsed .logout-btn { padding: 12px 0; margin: 15px 10px; }
    
    .sidebar-header { padding: 25px 20px; font-size: 20px; font-weight: 800; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; }
    .sidebar-header i { color: var(--primary); }
    .nav-links { list-style: none; padding-top: 15px; overflow-y: auto; flex: 1; }
    .nav-links::-webkit-scrollbar { width: 4px; }
    .nav-links::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
    .nav-links button { width: 100%; display: flex; align-items: center; gap: 15px; background: transparent; border: none; color: var(--text-muted); font-size: 14px; font-weight: 600; padding: 14px 25px; cursor: pointer; transition: 0.3s; text-align: left; position: relative;}
    .nav-links button:hover, .nav-links button.active { background: rgba(59, 130, 246, 0.1); color: var(--primary); border-right: 4px solid var(--primary); }
    .nav-links i.icon { width: 20px; font-size: 16px; text-align: center; }
    .logout-btn { background: rgba(246, 70, 93, 0.1); color: var(--danger); margin: 15px 20px; padding: 12px; text-align: center; border-radius: 8px; text-decoration: none; font-weight: bold; display: block; border: 1px solid rgba(246, 70, 93, 0.2); transition: 0.3s; }
    .logout-btn:hover { background: var(--danger); color: #fff; }

    /* MAIN CONTENT */
    .top-header { background: var(--panel-bg); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 9999; min-height: 75px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .welcome-section { display: flex; align-items: center; gap: 15px; flex-shrink: 0;}
    .theme-toggle { background: var(--border-color); border: none; color: var(--text-main); padding: 10px 15px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; flex-shrink: 0;}

    .content-area { padding: 30px; max-width: 100%; overflow-x: hidden; box-sizing: border-box; }
    .section-tab { display: none; animation: fadeIn 0.4s ease; }
    .section-tab.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    /* PANELS & FORMS */
    .grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; align-items: start; }
    .panel { background: var(--panel-bg); border-radius: 12px; border: 1px solid var(--border-color); padding: 25px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .panel-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px;}
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 8px; color: var(--text-muted); }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--input-bg); color: var(--text-main); outline: none; font-family: 'Inter'; transition: 0.3s;}
    .form-group input:focus, .form-group select:focus { border-color: var(--primary); }
    .btn { padding: 10px 18px; background: var(--primary); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; transition: 0.3s;}
    body:not(.light-mode) .btn { color: #fff; } 
    .btn:hover { opacity: 0.9; transform: translateY(-2px); }
    .btn-danger { background: rgba(246, 70, 93, 0.1); color: var(--danger); border: 1px solid rgba(246, 70, 93, 0.3); }
    .btn-warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
    .btn-success { background: rgba(14, 203, 129, 0.1); color: var(--success); border: 1px solid rgba(14, 203, 129, 0.3); }
    .btn-sm { padding: 6px 10px; font-size: 12px; }

    /* TABLES & BADGES */
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 15px; text-align: left; font-size: 14px; border-bottom: 1px solid var(--border-color); }
    th { color: var(--text-muted); font-weight: 600; text-transform: uppercase; font-size: 12px; }
    .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; background: rgba(14, 203, 129, 0.1); color: var(--success); border: 1px solid rgba(14, 203, 129, 0.2); }
    .badge-red { background: rgba(246, 70, 93, 0.1); color: var(--danger); border-color: rgba(246, 70, 93, 0.2); }
    .badge-noti { background: var(--danger); color: #fff; padding: 2px 6px; border-radius: 10px; font-size: 10px; position: absolute; right: 15px; font-weight: bold;}
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(14, 203, 129, 0.1); color: var(--success); border: 1px solid rgba(14, 203, 129, 0.3); }
    .alert-error { background: rgba(246, 70, 93, 0.1); color: var(--danger); border: 1px solid rgba(246, 70, 93, 0.3); }
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
    .modal-overlay.active { display: flex; }
    .modal-box { background: var(--panel-bg); padding: 30px; border-radius: 12px; width: 90%; max-width: 400px; border: 1px solid var(--border-color); text-align: center; }

    /* MAGIC CONTROL CENTER STYLES */
    .welcome-banner { display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(59, 130, 246, 0.08) 100%); padding: 35px 40px; border-radius: 16px; border: 1px solid rgba(59, 130, 246, 0.2); margin-bottom: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; overflow: hidden; }
    .welcome-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(59, 130, 246, 0.05) 0%, transparent 60%); animation: rotateBg 20s linear infinite; z-index: 0; }
    @keyframes rotateBg { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .welcome-banner > div { z-index: 1; position: relative; }
    .welcome-banner h2 { font-size: 32px; margin-bottom: 8px; font-weight: 800; }
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

    /* OVERSIGHT DRILL-DOWN STYLES (Grid fixed) */
    .breadcrumbs { background: var(--panel-bg); padding: 15px 25px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; }
    .bc-item { color: var(--text-muted); cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .bc-item:hover, .bc-item.active { color: var(--primary); }
    .bc-separator { color: var(--border-color); font-size: 12px; }
    .oversight-view { display: none; animation: slideInRight 0.4s ease forwards; }
    .oversight-view.active { display: block; }
    @keyframes slideInRight { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    
    .magic-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    
    .magic-drill-card { background: linear-gradient(145deg, var(--panel-bg), rgba(255,255,255,0.02)); border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .magic-drill-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.3); }
    .magic-drill-card::after { content: '\f105'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; top: 50%; transform: translateY(-50%); font-size: 20px; color: var(--border-color); transition: 0.3s; }
    .magic-drill-card:hover::after { color: var(--primary); transform: translateY(-50%) translateX(5px); }
    .lvl-dept { border-bottom: 4px solid #8b5cf6; } .lvl-dept:hover { border-color: #a78bfa; }
    .lvl-teacher { border-bottom: 4px solid #10b981; } .lvl-teacher:hover { border-color: #34d399; }
    .card-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 22px; color: #fff; margin-bottom: 15px; }
    .card-meta { display: inline-block; background: rgba(0,0,0,0.3); padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; border: 1px solid rgba(255,255,255,0.05); margin-top: 15px; }

    /* TELEGRAM APP STYLES */
    .telegram-app { display: flex; height: 75vh; background: var(--panel-bg); border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
.tg-sidebar { transition: width 0.3s ease; width: 400px; background: rgba(0,0,0,0.2); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; }    .tg-sidebar.collapsed { width: 0px; border: none; }
    .tg-search-bar { padding: 15px; border-bottom: 1px solid var(--border-color); }
    .tg-search-bar input { width: 100%; padding: 10px 15px 10px 40px; border-radius: 20px; border: 1px solid var(--border-color); background: var(--bg-color); color: var(--text-main); outline: none; }
    .tg-folders { display: flex; overflow-x: auto; padding: 10px; gap: 5px; border-bottom: 1px solid var(--border-color); }
    .tg-folders::-webkit-scrollbar { height: 0px; }
    .tg-folder { padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; color: var(--text-muted); cursor: pointer; white-space: nowrap; transition: 0.3s; }
    .tg-folder.active { background: rgba(59, 130, 246, 0.1); color: var(--primary); }
    .tg-contact-item { display: flex; align-items: center; gap: 15px; padding: 15px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid rgba(255,255,255,0.02); }
    .tg-contact-item:hover, .tg-contact-item.active { background: rgba(59, 130, 246, 0.1); border-left: 3px solid var(--primary); }
    .tg-avatar { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: bold; color: #fff; position: relative; }
    .tg-avatar.group { border-radius: 12px; }
    .tg-info { flex: 1; overflow: hidden; }
    .tg-name { font-size: 14px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tg-role { font-size: 12px; color: var(--text-muted); display: block; margin-top: 3px; }
    /* 🪄 MAGIC: HIDE SCROLLBARS BUT KEEP SCROLLING 🪄 */
    .tg-contacts { flex: 1; overflow-y: auto; -ms-overflow-style: none; scrollbar-width: none; padding-right: 5px; }
    .tg-contacts::-webkit-scrollbar { display: none; width: 0px; background: transparent; }
    
    .tg-chat-history { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; -ms-overflow-style: none; scrollbar-width: none; }
    .tg-chat-history::-webkit-scrollbar { display: none; width: 0px; background: transparent; }
    
    .nav-links { list-style: none; padding-top: 10px; overflow-y: auto; flex: 1; -ms-overflow-style: none; scrollbar-width: none; }
    .nav-links::-webkit-scrollbar { display: none; width: 0px; }
    .chat-unread-badge { background: var(--danger); color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-left: auto; box-shadow: 0 2px 5px rgba(246, 70, 93, 0.4); }

    .tg-chat-area { flex: 1; display: flex; flex-direction: column; background: url('https://www.transparenttextures.com/patterns/cubes.png'); }
    .tg-chat-header { padding: 15px 25px; background: var(--panel-bg); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; }
    .tg-chat-history { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
    .tg-placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: var(--text-muted); opacity: 0.5; }
    .tg-placeholder i { font-size: 60px; margin-bottom: 15px; }
    .tg-chat-input-area { padding: 20px; background: var(--panel-bg); border-top: 1px solid var(--border-color); }
    .tg-chat-form { display: flex; gap: 15px; align-items: center; background: var(--bg-color); padding: 5px 5px 5px 20px; border-radius: 30px; border: 1px solid var(--border-color); }
    .tg-chat-form input { flex: 1; background: transparent; border: none; color: var(--text-main); font-size: 15px; outline: none; }
    .tg-chat-form button { width: 45px; height: 45px; border-radius: 50%; background: var(--primary); border: none; color: #fff; cursor: pointer; transition: 0.3s; }

    .chat-msg-wrapper { display: flex; margin-bottom: 15px; width: 100%; position: relative; }
    .chat-right { justify-content: flex-end; }
    .chat-left { justify-content: flex-start; }
    .chat-bubble { max-width: 75%; padding: 12px 16px; border-radius: 18px; line-height: 1.5; }
    .chat-right .chat-bubble { background: var(--primary); color: #fff; border-bottom-right-radius: 4px; }
.chat-left .chat-bubble { background: var(--bg-color); color: var(--text-main); border-bottom-left-radius: 4px; border: 1px solid var(--border-color); }
    .chat-meta { font-size: 10px; opacity: 0.7; margin-top: 6px; display: flex; justify-content: space-between; gap: 15px; font-weight: 600; }
    
    /* SETTINGS & PROFILE UI */
    .profile-header-card { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); border-radius: 20px; padding: 40px 20px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(37, 99, 235, 0.3); margin-bottom: 30px; z-index: 1;}
    .profile-header-card::before { content: ''; position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: rgba(255,255,255,0.1); border-radius: 50%; }
    .profile-header-card::after { content: ''; position: absolute; bottom: -50px; left: -50px; width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%; }
    
    .profile-avatar-wrapper { position: relative; width: 120px; height: 120px; margin: 0 auto 20px auto; z-index: 2; }
    .profile-avatar-large { width: 100%; height: 100%; border-radius: 20px; object-fit: cover; border: 4px solid rgba(255,255,255,0.2); box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    .edit-avatar-btn { position: absolute; bottom: -5px; right: -5px; background: var(--primary); color: #fff; width: 35px; height: 35px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 3px solid #1e40af; transition: 0.3s; }
    .edit-avatar-btn:hover { transform: scale(1.1); }
    
    .profile-name { color: #fff; font-size: 28px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; z-index: 2; position: relative;}
    .profile-email { color: rgba(255,255,255,0.8); font-size: 14px; margin-bottom: 20px; z-index: 2; position: relative;}
    
    .profile-badges { display: flex; justify-content: center; gap: 10px; z-index: 2; position: relative; margin-bottom: 30px;}
    .p-badge { background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; color: #fff; font-size: 12px; font-weight: 600; backdrop-filter: blur(5px); display: flex; align-items: center; gap: 5px;}
    
    /* Inner Tabs */
    .inner-tabs { display: flex; justify-content: center; gap: 20px; border-bottom: 1px solid var(--border-color); margin-bottom: 30px; padding-bottom: 10px; flex-wrap: wrap;}
    .inner-tab-btn { background: transparent; border: none; color: var(--text-muted); font-size: 14px; font-weight: 700; cursor: pointer; padding: 10px 20px; border-radius: 8px; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .inner-tab-btn:hover { background: rgba(255,255,255,0.05); }
    .inner-tab-btn.active { background: var(--primary); color: #fff; }

    .inner-tab-content { display: none; animation: fadeIn 0.4s; }
    .inner-tab-content.active { display: block; }

    /* INPUT ICONS & SETTINGS */
    .input-with-icon { position: relative; width: 100%; }
    .input-with-icon i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-muted); transition: 0.3s; font-size: 16px; }
    .input-with-icon input, .input-with-icon select { padding-left: 45px !important; }
    .sec-toggle { display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); padding: 15px 20px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 15px;}
    .sec-toggle-info h4 { font-size: 15px; color: var(--text-main); margin-bottom: 3px;}
    .sec-toggle-info p { font-size: 12px; color: var(--text-muted);}
    .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border-color); transition: .4s; border-radius: 34px; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .slider { background-color: var(--primary); }
    input:checked + .slider:before { transform: translateX(20px); }
    
    /* PASSWORD VALIDATION STYLES */
    .pw-group { position: relative; }
    .pw-eye { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); transition: 0.3s; z-index: 10; font-size: 16px; }
    .pw-eye:hover { color: var(--primary); }
    .pw-rules { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-top: 10px; display: none; border: 1px solid var(--border-color); }
    .rule-item { font-size: 12px; color: var(--danger); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
    .rule-item.valid { color: var(--success); }

    /* CONTEXT MENU */
    .chat-context-menu { display: none; position: fixed; z-index: 10000; width: 180px; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 8px 25px rgba(0,0,0,0.5); overflow: hidden; }
    .context-item { padding: 12px 15px; font-size: 13px; font-weight: 600; color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 10px; transition: 0.2s; }
    .context-item:hover { background: rgba(255,255,255,0.05); color: var(--primary); }
    .context-item.delete { color: var(--danger); border-top: 1px solid rgba(255,255,255,0.02); }
    .context-item.delete:hover { background: rgba(246, 70, 93, 0.1); color: var(--danger); }
/* NOTIFICATION BADGE STYLES */
    .main-sidebar-badge { background: var(--danger); color: #fff; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; margin-left: auto; box-shadow: 0 0 10px rgba(246, 70, 93, 0.5); animation: pulse-badge 2s infinite; z-index: 10; }
    @keyframes pulse-badge { 0% { transform: scale(1) translateY(-50%); } 50% { transform: scale(1.1) translateY(-50%); } 100% { transform: scale(1) translateY(-50%); } }
    .chat-unread-badge { background: var(--danger); color: #fff; padding: 2px 7px; border-radius: 12px; font-size: 11px; font-weight: bold; margin-left: auto; box-shadow: 0 2px 5px rgba(246, 70, 93, 0.4); display: none; }
/* 🌟 PREMIUM SETTINGS UI STYLES 🌟 */
    .premium-panel { background: linear-gradient(145deg, var(--panel-bg), rgba(255,255,255,0.01)); border: 1px solid var(--border-color); border-top: 4px solid var(--primary); border-radius: 16px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); position: relative; overflow: hidden; margin-bottom: 25px; transition: 0.3s; }
    .premium-panel:hover { box-shadow: 0 15px 40px rgba(0,0,0,0.1); transform: translateY(-3px); }
    .premium-panel::after { content: ''; position: absolute; top:-50px; right:-50px; width:150px; height:150px; background: radial-gradient(circle, rgba(59,130,246,0.08) 0%, transparent 70%); border-radius:50%; pointer-events: none; }
    
    .panel-title-premium { font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 12px; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 15px; color: var(--text-main); }
    
    .info-alert { background: rgba(59, 130, 246, 0.05); border-left: 3px solid var(--primary); padding: 15px 20px; border-radius: 0 8px 8px 0; font-size: 13px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.6; }
    .info-alert strong { color: var(--text-main); font-weight: 700; }
    .info-alert.warning { background: rgba(245, 158, 11, 0.05); border-left-color: #f59e0b; }
    .info-alert.success { background: rgba(16, 185, 129, 0.05); border-left-color: #10b981; }

    .glow-btn { background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: #fff; padding: 16px 35px; border-radius: 30px; font-size: 16px; font-weight: 800; border: none; cursor: pointer; box-shadow: 0 10px 25px rgba(59, 130, 246, 0.4); transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
    .glow-btn:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 15px 35px rgba(139, 92, 246, 0.5); }

    /* =================================================== */
    /* 📚 MAGIC HELP CENTER & DOCUMENTATION (High Contrast)*/
    /* =================================================== */
    .help-hero { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 16px; padding: 50px 30px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.3); margin-bottom: 40px; border: 4px solid rgba(255,255,255,0.1); }
    .help-hero::before { content: ''; position: absolute; top: -50px; left: -50px; width: 250px; height: 250px; background: rgba(255,255,255,0.15); border-radius: 50%; filter: blur(30px); }
    .help-hero h2 { font-size: 38px; color: #ffffff; font-weight: 800; margin-bottom: 15px; text-shadow: 0 4px 15px rgba(0,0,0,0.4); }
    .help-hero p { font-size: 16px; color: #e0f2fe; max-width: 700px; margin: 0 auto 30px; line-height: 1.8; font-weight: 500; }
    
    .help-search-box { position: relative; max-width: 600px; margin: 0 auto; }
    .help-search-box input { width: 100%; padding: 18px 25px 18px 55px; border-radius: 30px; border: 2px solid #60a5fa; background: #ffffff; color: #1e293b; font-size: 16px; font-weight: 600; outline: none; box-shadow: 0 8px 25px rgba(0,0,0,0.2); transition: 0.3s; }
    .help-search-box input:focus { border-color: #fcd535; transform: scale(1.03); box-shadow: 0 12px 35px rgba(252, 213, 53, 0.3); }
    .help-search-box i { position: absolute; left: 22px; top: 50%; transform: translateY(-50%); color: #3b82f6; font-size: 20px; }

    .help-topic-section { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 35px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    .help-topic-title { font-size: 24px; font-weight: 800; color: var(--text-main); border-bottom: 3px solid rgba(59, 130, 246, 0.1); padding-bottom: 15px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; }
    
    .help-accordion-item { border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 15px; background: rgba(0,0,0,0.02); transition: 0.3s; overflow: hidden; }
    body:not(.light-mode) .help-accordion-item { background: rgba(255,255,255,0.02); }
    .help-accordion-item.active { border-color: var(--primary); box-shadow: 0 5px 20px rgba(59, 130, 246, 0.15); background: var(--panel-bg); }
    
    .help-acc-btn { width: 100%; text-align: left; background: transparent; border: none; padding: 20px 25px; font-size: 16px; font-weight: 700; color: var(--text-main); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.3s; }
    .help-acc-btn:hover { color: var(--primary); background: rgba(59, 130, 246, 0.05); }
    .help-acc-btn i { color: var(--text-muted); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); font-size: 18px; }
    .help-accordion-item.active .help-acc-btn i { transform: rotate(180deg); color: var(--primary); }
    
    .help-acc-content { padding: 0 25px 25px 25px; color: var(--text-main); font-size: 14.5px; line-height: 1.8; display: none; animation: slideDownHelp 0.4s ease forwards; border-top: 1px dashed var(--border-color); margin-top: 5px; padding-top: 20px; }
    @keyframes slideDownHelp { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    
    .help-pro-tip { background: rgba(16, 185, 129, 0.1); border-left: 5px solid #10b981; padding: 18px 20px; border-radius: 0 10px 10px 0; margin: 20px 0; color: var(--text-main); font-weight: 500; }
    .help-warning { background: rgba(246, 70, 93, 0.1); border-left: 5px solid #f6465d; padding: 18px 20px; border-radius: 0 10px 10px 0; margin: 20px 0; color: var(--text-main); font-weight: 500; }
    .help-highlight { color: var(--primary); font-weight: 700; }
</style>
</head>
<body>

<aside class="sidebar" id="main-sidebar">
    <div class="sidebar-header" style="margin-bottom: 20px;">
        <i class="fa-solid fa-building-columns" style="color:var(--primary);"></i> <h2>COLLEGE BAR</h2>
    </div>
    
    <ul class="nav-links" style="padding-top: 10px;">
        <li><button class="tab-link active" onclick="openTab('home')"><i class="fa-solid fa-chart-pie icon"></i> <span>Control Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('departments')"><i class="fa-solid fa-sitemap icon"></i> <span>Manage Departments</span> <?php if($trash_dept_count > 0) echo "<span class='badge-noti'>$trash_dept_count</span>"; ?></button></li>
        <li><button class="tab-link" onclick="openTab('heads')"><i class="fa-solid fa-users-gear icon"></i> <span>Department Heads</span> <?php if($trash_head_count > 0) echo "<span class='badge-noti'>$trash_head_count</span>"; ?></button></li>
        <li><button class="tab-link" onclick="openTab('college_oversight'); resetOversight();"><i class="fa-solid fa-network-wired icon" style="color: var(--primary);"></i> <span>College Oversight</span></button></li>
       <li><button class="tab-link" onclick="openTab('broadcast')"><i class="fa-brands fa-telegram icon" style="color: #0ea5e9;"></i> <span>Communications</span> <span class="main-sidebar-badge" id="main_comm_badge" style="display:none; position:absolute; right:15px; top:50%; transform:translateY(-50%);">0</span></button></li>
        <li><button class="tab-link" onclick="openTab('audit')"><i class="fa-solid fa-shield-halved icon" style="color: var(--danger);"></i> <span>Security Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('help')"><i class="fa-solid fa-circle-question icon"></i> <span>Help Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('settings')"><i class="fa-solid fa-user-gear icon" style="color: #8b5cf6;"></i> <span>Settings</span></button></li>
    </ul>
    <a href="../logout.php" class="logout-btn"><i class="fa-solid fa-power-off"></i> <span>Secure Logout</span></a>
</aside>

<main class="main-content">
   <header class="top-header">
        <div style="display: flex; align-items: center; gap: 15px; flex-shrink: 0;">
            <button type="button" class="btn btn-sm" style="background:transparent; color:var(--text-main); border:1px solid var(--border-color); margin-right:10px;" onclick="document.getElementById('main-sidebar').classList.toggle('collapsed')">
                <i class="fa-solid fa-bars-staggered"></i>
            </button>
            <div class="welcome-section" style="display: flex; align-items: center; gap: 15px;">
                <img src="<?php echo $profile_pic; ?>" alt="Admin" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); flex-shrink: 0;">
                <div class="welcome-text" style="white-space: nowrap;">
                    <h2 id="display-admin-name" style="font-size: 18px; font-weight: 700; color: var(--text-main);">Welcome, <?php echo htmlspecialchars($admin_info['name']); ?></h2>
                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-building-columns" style="color:var(--primary);"></i> <?php echo htmlspecialchars($admin_info['college_name']); ?> Admin</span>
                </div>
            </div>
        </div>
        <button class="theme-toggle" style="flex-shrink: 0;" onclick="toggleTheme()"><i class="fa-solid fa-moon" id="theme-icon"></i> <span id="theme-text">Dark Mode</span></button>
    </header>

    <div class="content-area" style="position: relative;">
    <?php if($message): ?>
        <div class="alert alert-<?php echo $msg_type; ?>" style="position: relative; z-index: 1000; margin-bottom: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.05);">
            <i class="fa-solid <?php echo $msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation'; ?>" style="font-size:18px;"></i> 
            <span style="font-size:14.5px; font-weight:600;"><?php echo $message; ?></span>
        </div>
    <?php endif; ?>
        <!-- TAB 1: MAGIC CONTROL CENTER -->
        <div id="home" class="section-tab active">
            <div class="welcome-banner">
                <div>
                    <h2 id="greeting-text">
                        <?php 
                            $hour = date('H');
                            $greet = ($hour < 12) ? "Good Morning" : (($hour < 18) ? "Good Afternoon" : "Good Evening");
                            echo $greet . ", " . htmlspecialchars($admin_info['name']); 
                        ?>!
                    </h2>
                    <p><i class="fa-solid fa-shield-check" style="color:var(--success);"></i> College operations running smoothly.</p>
                </div>
                <div class="live-clock-container"><i class="fa-solid fa-clock"></i><span id="real-time-clock">00:00:00</span></div>
            </div>

            <div class="stats-grid">
                <div class="magic-card"><i class="fa-solid fa-sitemap bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-sitemap"></i></div><h2 class="counter" data-target="<?php echo $dept_count; ?>">0</h2><p>Departments</p></div>
                <div class="magic-card"><i class="fa-solid fa-users-gear bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-users-gear"></i></div><h2 class="counter" data-target="<?php echo $heads_count; ?>">0</h2><p>Heads</p></div>
                <div class="magic-card"><i class="fa-solid fa-chalkboard-user bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-chalkboard-user"></i></div><h2 class="counter" data-target="<?php echo $teachers_count; ?>">0</h2><p>Teachers</p></div>
                <div class="magic-card"><i class="fa-solid fa-user-graduate bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #ef4444, #b91c1c);"><i class="fa-solid fa-user-graduate"></i></div><h2 class="counter" data-target="<?php echo $students_count; ?>">0</h2><p>Students</p></div>
            </div>
            
            <div class="grid-2">
                <div class="panel" style="position: relative; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.1);">
                    <h3 class="panel-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary);"></i> College Demographics</h3>
                    <div style="height: 250px; display:flex; justify-content:center; align-items:center; margin-top:10px;">
                        <canvas id="collegeDemographicsChart"></canvas>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="panel" style="margin-bottom: 0; padding: 20px;">
                        <h3 class="panel-title" style="margin-bottom: 15px;"><i class="fa-solid fa-bolt" style="color:var(--primary);"></i> Quick Actions</h3>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button class="btn btn-sm" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59,130,246,0.3);" onclick="openTab('departments');"><i class="fa-solid fa-plus"></i> New Dept</button>
                            <button class="btn btn-sm" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border: 1px solid rgba(139,92,246,0.3);" onclick="openTab('heads');"><i class="fa-solid fa-user-plus"></i> Assign Head</button>
                            <button class="btn btn-sm" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.3);" onclick="openTab('broadcast');"><i class="fa-solid fa-paper-plane"></i> Broadcast</button>
                        </div>
                    </div>

                    <div class="panel" style="flex: 1; margin-bottom: 0; padding: 20px; display: flex; flex-direction: column;">
                        <h3 class="panel-title" style="margin-bottom: 10px;"><i class="fa-solid fa-clock-rotate-left" style="color:#f59e0b;"></i> Recent Activities</h3>
                        <div style="flex: 1; overflow-y: auto; max-height: 140px; padding-right: 5px;">
                            <?php
                            $acts = mysqli_query($conn, "SELECT * FROM admin_activities WHERE admin_id=$admin_id ORDER BY created_at DESC LIMIT 8");
                            if(mysqli_num_rows($acts)>0){
                                while($a = mysqli_fetch_assoc($acts)){
                                    $time = date("d M, H:i", strtotime($a['created_at']));
                                    echo "<div style='padding:10px; border-bottom:1px solid rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items: center;'>
                                            <div><strong style='color:var(--primary); font-size:13px;'>{$a['action_type']}</strong><br><span style='font-size:12px; color:var(--text-muted);'>{$a['details']}</span></div>
                                            <span style='font-size:10px; color:var(--text-muted); font-weight:600; background:rgba(0,0,0,0.2); padding:3px 8px; border-radius:10px;'>$time</span>
                                          </div>";
                                }
                            } else { echo "<p style='color:var(--text-muted); font-size:13px; text-align:center;'><i class='fa-solid fa-folder-open' style='font-size:24px; display:block; margin-bottom:10px;'></i> No recent activities.</p>"; }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: MANAGE DEPARTMENTS -->
        <div id="departments" class="section-tab">
            <div class="grid-2">
                <div class="panel">
                    <h3 class="panel-title"><i class="fa-solid fa-sitemap"></i> Add/Edit Department</h3>
                    <form method="POST">
                        <input type="hidden" name="dept_id" id="form_dept_id">
                        <div class="form-group"><label>Department Name</label><div class="input-with-icon"><input type="text" name="dept_name" id="form_dept_name" required><i class="fa-solid fa-sitemap"></i></div></div>
                        <div class="form-group"><label>Department Code</label><div class="input-with-icon"><input type="text" name="dept_code" id="form_dept_code" required><i class="fa-solid fa-hashtag"></i></div></div>
                        <button type="submit" name="add_dept" id="btn_add_dept" class="btn" style="width:100%;"><i class="fa-solid fa-plus"></i> Create Department</button>
                        <button type="submit" name="edit_dept" id="btn_edit_dept" class="btn btn-warning" style="width:100%; display:none;"><i class="fa-solid fa-pen"></i> Save Changes</button>
                    </form>
                </div>
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-list-check"></i> Active Departments</h3>
                    <table>
                        <tr><th>Department</th><th>Code</th><th>Action</th></tr>
                        <?php
                        $depts = mysqli_query($conn, "SELECT * FROM departments WHERE college_id=$college_id AND is_deleted=0 ORDER BY dept_name ASC");
                        while($d = mysqli_fetch_assoc($depts)){
                            echo "<tr>
                                    <td><strong>{$d['dept_name']}</strong></td>
                                    <td style='color:var(--primary);'>{$d['dept_code']}</td>
                                    <td>
                                        <button type='button' class='btn btn-sm btn-warning' onclick=\"editDept({$d['id']}, '{$d['dept_name']}', '{$d['dept_code']}')\"><i class='fa-solid fa-pen'></i></button>
                                        <button type='button' class='btn btn-sm btn-danger' onclick=\"confirmDelete('dept', {$d['id']}, '{$d['dept_name']}')\"><i class='fa-solid fa-trash'></i></button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
            <!-- TRASH -->
            <div class="panel" style="margin-top:10px; border-color: rgba(246, 70, 93, 0.3);">
                <h3 class="panel-title" style="color:var(--danger);"><i class="fa-solid fa-trash-can-arrow-up"></i> Recycle Bin (Departments)</h3>
                <table>
                    <tr><th>Deleted Dept</th><th>Deleted Date</th><th>Action</th></tr>
                    <?php
                    $trash = mysqli_query($conn, "SELECT * FROM departments WHERE college_id=$college_id AND is_deleted=1 ORDER BY deleted_at DESC");
                    while($t = mysqli_fetch_assoc($trash)){
                        echo "<tr><td><strike>{$t['dept_name']}</strike> ({$t['dept_code']})</td><td style='color:var(--danger);'>{$t['deleted_at']}</td><td><form method='POST'><input type='hidden' name='dept_id' value='{$t['id']}'><button type='submit' name='restore_dept' class='btn btn-sm btn-success'>Restore</button></form></td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- TAB 3: DEPARTMENT HEADS -->
        <div id="heads" class="section-tab">
            <div class="grid-2">
                <div class="panel">
                    <h3 class="panel-title"><i class="fa-solid fa-users-gear"></i> Assign Head</h3>
                    <form method="POST">
                        <div class="form-group"><label>Select Department</label>
                            <div class="input-with-icon"><select name="dept_id" required><option value="">-- Choose --</option>
                            <?php $d_list = mysqli_query($conn, "SELECT id, dept_name FROM departments WHERE college_id=$college_id AND is_deleted=0"); while($dl = mysqli_fetch_assoc($d_list)) echo "<option value='{$dl['id']}'>{$dl['dept_name']}</option>"; ?>
                            </select><i class="fa-solid fa-sitemap"></i></div>
                        </div>
                        <div class="form-group"><label>Full Name</label><div class="input-with-icon"><input type="text" name="head_name" required><i class="fa-solid fa-user"></i></div></div>
                        <div class="form-group"><label>Email</label><div class="input-with-icon"><input type="email" name="head_email" required><i class="fa-solid fa-envelope"></i></div></div>
                        <div class="form-group"><label>Username</label><div class="input-with-icon"><input type="text" name="head_username" required><i class="fa-solid fa-at"></i></div></div>
                        <div class="form-group"><label>Password</label><div class="input-with-icon"><input type="password" name="head_password" required><i class="fa-solid fa-key"></i></div></div>
                        <button type="submit" name="add_head" class="btn" style="width:100%;"><i class="fa-solid fa-plus"></i> Assign Head</button>
                    </form>
                </div>
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-users"></i> Active Department Heads</h3>
                    <table>
                        <tr><th>Head Profile</th><th>Department</th><th>Status</th><th>Action</th></tr>
                        <?php
                        $heads_q = mysqli_query($conn, "SELECT h.*, d.dept_name FROM head h JOIN departments d ON h.dept_id = d.id WHERE d.college_id=$college_id AND h.is_deleted=0 ORDER BY h.id DESC");
                        while($h = mysqli_fetch_assoc($heads_q)){
                            $badge = $h['status'] == 'active' ? 'badge' : 'badge-red';
                            $btn_class = $h['status'] == 'active' ? 'btn-danger' : 'btn-success';
                            echo "<tr>
                                    <td><strong>{$h['name']}</strong><br><small>{$h['email']}</small></td>
                                    <td>{$h['dept_name']}</td>
                                    <td><span class='{$badge}'>".ucfirst($h['status'])."</span></td>
                                    <td>
                                        <form method='POST' style='display:inline;'><input type='hidden' name='head_id' value='{$h['id']}'><button type='submit' name='toggle_head' class='btn btn-sm {$btn_class}'>Toggle</button></form>
                                        <button type='button' class='btn btn-sm btn-danger' onclick=\"confirmDelete('head', {$h['id']}, '{$h['name']}')\"><i class='fa-solid fa-trash'></i></button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
            <!-- TRASH -->
            <div class="panel" style="margin-top: 10px; border-color: rgba(246, 70, 93, 0.3);">
                <h3 class="panel-title" style="color:var(--danger);"><i class="fa-solid fa-trash-can-arrow-up"></i> Recycle Bin (Heads)</h3>
                <table>
                    <?php
                    $trash_h = mysqli_query($conn, "SELECT h.* FROM head h JOIN departments d ON h.dept_id=d.id WHERE d.college_id=$college_id AND h.is_deleted=1");
                    while($t = mysqli_fetch_assoc($trash_h)){
                        echo "<tr><td><strike>{$t['name']}</strike></td><td>{$t['deleted_at']}</td><td><form method='POST'><input type='hidden' name='head_id' value='{$t['id']}'><button type='submit' name='restore_head' class='btn btn-sm btn-success'>Restore</button></form></td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>

        <!-- TAB 4: COLLEGE OVERSIGHT (Drill-down) -->
        <div id="college_oversight" class="section-tab">
            <div class="breadcrumbs" id="oversight-breadcrumbs">
                <span class="bc-item active" onclick="navToLevel('lvl1', 'Departments', this, true)"><i class="fa-solid fa-sitemap"></i> All Departments</span>
            </div>

            <div id="view-lvl1" class="oversight-view active">
                <div class="magic-grid">
                    <?php
                    mysqli_data_seek($depts, 0); 
                    if(mysqli_num_rows($depts) > 0){
                        while($dept = mysqli_fetch_assoc($depts)){
                            $d_id = $dept['id'];
                            $h_q = mysqli_query($conn, "SELECT name FROM head WHERE dept_id=$d_id AND is_deleted=0 LIMIT 1");
                            $head_name = ($h_r = mysqli_fetch_assoc($h_q)) ? "Head: ".$h_r['name'] : "No Head";
                            $t_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM teacher WHERE dept_id=$d_id"))['c'];
                            
                            echo "<div class='magic-drill-card lvl-dept' onclick=\"navToLevel('lvl2_dept_{$d_id}', '{$dept['dept_name']}')\">
                                    <div class='card-icon' style='background:linear-gradient(135deg, #8b5cf6, #6d28d9);'><i class='fa-solid fa-sitemap'></i></div>
                                    <h3>{$dept['dept_name']}</h3>
                                    <p><i class='fa-solid fa-user-tie'></i> {$head_name}</p>
                                    <span class='card-meta' style='color:#8b5cf6;'><i class='fa-solid fa-chalkboard-user'></i> {$t_count} Teachers</span>
                                  </div>";
                        }
                    } else { echo "<p style='color:var(--text-muted);'>No departments found.</p>"; }
                    ?>
                </div>
            </div>

            <!-- LEVEL 2 & 3 -->
            <?php
            mysqli_data_seek($depts, 0);
            while($dept = mysqli_fetch_assoc($depts)){
                $d_id = $dept['id'];
                echo "<div id='view-lvl2_dept_{$d_id}' class='oversight-view'><div class='magic-grid'>";
                $teachers = mysqli_query($conn, "SELECT * FROM teacher WHERE dept_id=$d_id");
                if(mysqli_num_rows($teachers) > 0){
                    while($tech = mysqli_fetch_assoc($teachers)){
                        $t_id = $tech['id'];
                        $s_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM student WHERE dept_id=$d_id"))['c']; 
                        
                        echo "<div class='magic-drill-card lvl-teacher' onclick=\"navToLevel('lvl3_tech_{$t_id}', 'Tr. {$tech['name']}')\">
                                <div class='card-icon' style='background:linear-gradient(135deg, #10b981, #047857);'><i class='fa-solid fa-chalkboard-user'></i></div>
                                <h3>Tr. {$tech['name']}</h3>
                                <p><i class='fa-solid fa-envelope'></i> {$tech['email']}</p>
                                <span class='card-meta' style='color:#10b981;'><i class='fa-solid fa-user-graduate'></i> {$s_count} Students in Dept</span>
                              </div>";
                    }
                } else { echo "<p style='color:var(--text-muted);'>No teachers found.</p>"; }
                echo "</div></div>";
            }

            $all_techs = mysqli_query($conn, "SELECT t.id, t.dept_id FROM teacher t JOIN departments d ON t.dept_id=d.id WHERE d.college_id=$college_id");
            if($all_techs){
                while($tech = mysqli_fetch_assoc($all_techs)){
                    $t_id = $tech['id']; $d_id = $tech['dept_id'];
                    echo "<div id='view-lvl3_tech_{$t_id}' class='oversight-view'><div class='panel' style='padding:0; overflow:hidden;'>";
                    $studs = mysqli_query($conn, "SELECT * FROM student WHERE dept_id=$d_id ORDER BY name ASC");
                    if(mysqli_num_rows($studs)>0){
                        echo "<div style='padding:20px; border-bottom:1px solid var(--border-color); background:rgba(239, 68, 68, 0.05); color:#ef4444; font-weight:bold;'><i class='fa-solid fa-users'></i> Students List</div>";
                        while($s = mysqli_fetch_assoc($studs)){
                            echo "<div style='display:flex; align-items:center; gap:15px; padding:15px; border-bottom:1px solid rgba(255,255,255,0.05);'>
                                    <div style='width:40px; height:40px; border-radius:50%; background:rgba(239, 68, 68, 0.1); color:#ef4444; display:flex; justify-content:center; align-items:center; font-weight:bold;'>".substr($s['name'],0,1)."</div>
                                    <div><h4 style='font-size:15px; margin-bottom:3px;'>{$s['name']}</h4><span style='font-size:12px; color:var(--text-muted);'>@{$s['username']} | {$s['email']}</span></div>
                                    <div style='margin-left:auto;'><span class='badge' style='background:transparent; border-color:#ef4444; color:#ef4444;'>".ucfirst($s['status'])."</span></div>
                                  </div>";
                        }
                    } else { echo "<div style='padding:30px; text-align:center; color:var(--text-muted);'>No students enrolled yet.</div>"; }
                    echo "</div></div>";
                }
            }
            ?>
        </div>

        <!-- ============================================== -->
        <!-- 5. COMMUNICATIONS (Telegram Style + Notifications) -->
        <!-- ============================================== -->
        <div id="broadcast" class="section-tab">
            <h3 style="font-size: 22px; margin-bottom:20px;"><i class="fa-brands fa-telegram" style="color: #3b82f6;"></i> Communications Hub</h3>
            <div class="telegram-app">
                <div class="tg-sidebar">
                    <div class="tg-search-bar"><input type="text" id="tg-search" placeholder="Search..." onkeyup="filterTelegramChats()"></div>
                    <div class="tg-folders">
                        <div class="tg-folder active" onclick="switchFolder('all')">All Chats</div>
                        <div class="tg-folder" onclick="switchFolder('head')">Heads</div>
                        <div class="tg-folder" onclick="switchFolder('teacher')">Teachers</div>
                    </div>
                    <div class="tg-contacts" id="tg-contacts-list">
                        
                   <!-- 👑 CONTACTS LIST (WITH ADVANCED AVATARS - ADMIN SCOPE) -->
                        <?php
                        function getAvatar($pic, $name, $bg, $color, $locked) {
                            if($locked == 1) return['type'=>'locked', 'html'=>"<i class='fa-solid fa-user-lock' style='font-size:18px; color:#fff;'></i>", 'url'=>'LOCKED'];
                            
                            $url = (!empty($pic) && file_exists("../uploads/".$pic)) ? "../uploads/".$pic : "https://ui-avatars.com/api/?name=".urlencode($name)."&background=$bg&color=$color&bold=true";
                            return['type'=>'img', 'html'=>"<img src='$url' style='width:100%;height:100%;border-radius:50%;object-fit:cover;'>", 'url'=>$url];
                        }

                        // 1. Super Admin (Global Owner)
                        $sa_query = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM super_admin LIMIT 1");
                        if($sa_data = mysqli_fetch_assoc($sa_query)) {
                            $av = getAvatar($sa_data['profile_pic'], $sa_data['name'], 'fcd535', '000', $sa_data['profile_locked']);
                            $extra_badge = $av['type'] == 'img' ? "<i class='fa-solid fa-circle-check' style='position:absolute; bottom:-2px; right:-2px; color:#0ea5e9; background:#fff; border-radius:50%; font-size:12px; z-index:5;'></i>" : "";
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-admin' id='contact_super_admin_{$sa_data['id']}' onclick=\"openTelegramChat({$sa_data['id']}, 'super_admin', 0, '".addslashes($sa_data['name'])."', 'System Owner', '#fcd535', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#fcd535;' id='avatar_super_admin_{$sa_data['id']}'>{$av['html']}{$extra_badge}</div>
                                    <div class='tg-info'><span class='tg-name'>{$sa_data['name']} <i class='fa-solid fa-circle-check' style='color:#0ea5e9; font-size:12px;' title='Verified Owner'></i></span><span class='tg-role'>System Admin</span></div>
                                    <span class='chat-unread-badge' id='badge_super_admin_{$sa_data['id']}'>0</span>
                                  </div>";
                        }

                        // 📢 GROUP BROADCASTS
                        echo "<div class='tg-contact-item chat-item-all chat-item-head' onclick=\"openTelegramChat(0, 'head', 1, '📢 All Heads', 'My College Heads', '#8b5cf6', '')\">
                                <div class='tg-avatar group' style='background:#8b5cf6;'><i class='fa-solid fa-bullhorn'></i></div>
                                <div class='tg-info'><span class='tg-name'>📢 All Heads</span><span class='tg-role'>Broadcast</span></div>
                              </div>";
                        echo "<div class='tg-contact-item chat-item-all chat-item-teacher' onclick=\"openTelegramChat(0, 'teacher', 1, '📢 All Teachers', 'My College Teachers', '#10b981', '')\">
                                <div class='tg-avatar group' style='background:#10b981;'><i class='fa-solid fa-bullhorn'></i></div>
                                <div class='tg-info'><span class='tg-name'>📢 All Teachers</span><span class='tg-role'>Broadcast</span></div>
                              </div>";

                        // 2. Department Heads (Under this college)
                        $h_list = mysqli_query($conn, "SELECT h.id, h.name, h.profile_pic, h.profile_locked FROM head h JOIN departments d ON h.dept_id = d.id WHERE d.college_id=$college_id AND h.is_deleted=0");
                        while($h = mysqli_fetch_assoc($h_list)){
                            $av = getAvatar($h['profile_pic'], $h['name'], '8b5cf6', 'fff', $h['profile_locked']);
                            echo "<div class='tg-contact-item chat-item-all chat-item-head' id='contact_head_{$h['id']}' onclick=\"openTelegramChat({$h['id']}, 'head', 0, '".addslashes($h['name'])."', 'Head', '#8b5cf6', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#8b5cf6;' id='avatar_head_{$h['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>{$h['name']}</span><span class='tg-role' style='color:#8b5cf6;'>Head</span></div>
                                    <span class='chat-unread-badge' id='badge_head_{$h['id']}'>0</span>
                                  </div>";
                        }
                        
                        // 3. Teachers (Under this college)
                        $t_list = mysqli_query($conn, "SELECT t.id, t.name, t.profile_pic, t.profile_locked FROM teacher t JOIN departments d ON t.dept_id = d.id WHERE d.college_id=$college_id AND t.is_deleted=0");
                        while($t = mysqli_fetch_assoc($t_list)){
                            $av = getAvatar($t['profile_pic'], $t['name'], '10b981', 'fff', $t['profile_locked']);
                            echo "<div class='tg-contact-item chat-item-all chat-item-teacher' id='contact_teacher_{$t['id']}' onclick=\"openTelegramChat({$t['id']}, 'teacher', 0, '".addslashes($t['name'])."', 'Teacher', '#10b981', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#10b981;' id='avatar_teacher_{$t['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>{$t['name']}</span><span class='tg-role' style='color:#10b981;'>Teacher</span></div>
                                    <span class='chat-unread-badge' id='badge_teacher_{$t['id']}'>0</span>
                                  </div>";
                        }
                        ?>
                    </div>
                </div>
                <div class="tg-chat-area">
                    <div id="tg-placeholder" class="tg-placeholder"><i class="fa-regular fa-comments"></i><p>Select a chat</p></div>
                    <div id="tg-active-chat" style="display:none; flex-direction:column; height:100%;">
                        <div class="tg-chat-header">
                            <div class="tg-avatar group" id="chat-header-avatar" style="background:#3b82f6;"></div>
                            <div><div class="tg-chat-title" id="chat-header-name">Chat</div><div class="tg-chat-status" id="chat-header-role">Online</div></div>
                        </div>
                        <div class="tg-chat-history" id="chat-history-container"></div>
                        <div class="tg-chat-input-area">
                            <form id="tg-chat-form" onsubmit="submitTelegramMsg(event)" class="tg-chat-form">
                                <input type="hidden" name="chat_receiver_id" id="chat_receiver_id">
                                <input type="hidden" name="chat_receiver_role" id="chat_receiver_role">
                                <input type="hidden" name="chat_is_group" id="chat_is_group">
                                <input type="hidden" name="edit_msg_id" id="edit_msg_id">
                                <input type="text" name="chat_message" id="chat_message_input" placeholder="Message..." required autocomplete="off">
                                <button type="submit"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<!-- ============================================== -->
        <!-- 6. SECURITY COMMAND CENTER (COLLEGE SCOPE)     -->
        <!-- ============================================== -->
        <div id="audit" class="section-tab">
            <div style="margin-bottom: 25px;">
                <h3 style="font-size: 22px; color: var(--danger); display: flex; align-items: center; gap: 10px;"><i class="fa-solid fa-shield-halved"></i> College Security Command Center</h3>
                <p style="color: var(--text-muted); font-size: 14px; margin-top: 5px;">Monitor active threats, login activities, and strict oversight for your Department Heads and Teachers.</p>
            </div>

            <!-- HEADS SECURITY HEALTH CARD -->
            <div class="panel" style="border-left: 4px solid #8b5cf6; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                <h3 class="panel-title" style="color: #8b5cf6;"><i class="fa-solid fa-users-viewfinder"></i> Department Heads Security Health</h3>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Head Profile</th><th>Last Known IP</th><th>Last Login</th><th>Threat Level (24h)</th></tr></thead>
                        <tbody>
                            <?php
                            $head_sec_q = "SELECT h.id, h.name, d.dept_code, h.username,
                                            (SELECT attempt_time FROM login_logs WHERE username=h.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_login,
                                            (SELECT ip_address FROM login_logs WHERE username=h.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_ip,
                                            (SELECT COUNT(*) FROM login_logs WHERE username=h.username AND status='failed' AND attempt_time > NOW() - INTERVAL 1 DAY) as recent_fails
                                            FROM head h JOIN departments d ON h.dept_id = d.id WHERE d.college_id=$college_id AND h.is_deleted=0";
                            
                            $head_sec_res = mysqli_query($conn, $head_sec_q);
                            if(mysqli_num_rows($head_sec_res) > 0) {
                                while($sec = mysqli_fetch_assoc($head_sec_res)){
                                    $last_login = $sec['last_login'] ? date("d M Y, h:i A", strtotime($sec['last_login'])) : "<span style='color:var(--text-muted);'>Never logged in</span>";
                                    $last_ip = $sec['last_ip'] ? $sec['last_ip'] : "Unknown";
                                    $fails = $sec['recent_fails'];
                                    
                                    if($fails == 0) { $threat_badge = "<span class='badge' style='background:rgba(14, 203, 129, 0.1); color:var(--success); border-color:rgba(14, 203, 129, 0.3);'><i class='fa-solid fa-shield-check'></i> Secure (0 Fails)</span>"; }
                                    elseif($fails < 3) { $threat_badge = "<span class='badge' style='background:rgba(245,158,11,0.1); color:#f59e0b; border-color:rgba(245,158,11,0.3);'><i class='fa-solid fa-triangle-exclamation'></i> Low Risk ($fails Fails)</span>"; }
                                    else { $threat_badge = "<span class='badge badge-red'><i class='fa-solid fa-skull-crossbones'></i> HIGH RISK ($fails Fails)</span>"; }
                                    
                                    echo "<tr>
                                            <td><strong style='color:var(--text-main); font-size:15px;'>{$sec['name']}</strong><br><small style='color:#8b5cf6;'>@{$sec['username']} | {$sec['dept_code']}</small></td>
                                            <td style='font-family:monospace; color:#3b82f6;'>{$last_ip}</td>
                                            <td style='font-size:13px;'>{$last_login}</td>
                                            <td>{$threat_badge}</td>
                                          </tr>";
                                }
                            } else { echo "<tr><td colspan='4' style='text-align:center; color:var(--text-muted);'>No active heads found in your college.</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RECENT THREATS & LIVE LOGS -->
            <div class="grid-2">
                <div class="panel" style="border-left: 4px solid #f59e0b;">
                    <h3 class="panel-title" style="color: #f59e0b;"><i class="fa-solid fa-user-lock"></i> Suspicious Logins</h3>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:15px;">Recent failed attempts by users in your college.</p>
                    <div class="table-responsive">
                        <table>
                            <tr><th>User</th><th>IP Address</th><th>Time</th></tr>
                            <?php
                            // Fetch only failed logins for heads and teachers in this admin's college
                            $suspicious_q = mysqli_query($conn, "SELECT * FROM login_logs WHERE status='failed' AND (username IN (SELECT username FROM head h JOIN departments d ON h.dept_id=d.id WHERE d.college_id=$college_id) OR username IN (SELECT username FROM teacher t JOIN departments d ON t.dept_id=d.id WHERE d.college_id=$college_id)) ORDER BY attempt_time DESC LIMIT 5");
                            
                            if(mysqli_num_rows($suspicious_q) > 0){
                                while($susp = mysqli_fetch_assoc($suspicious_q)){
                                    $time = date("d M, h:i A", strtotime($susp['attempt_time']));
                                    echo "<tr>
                                            <td><strong style='color:var(--text-main); font-size:13px;'>{$susp['username']}</strong></td>
                                            <td style='font-family:monospace; color:var(--danger);'>{$susp['ip_address']}</td>
                                            <td style='font-size:12px; color:var(--text-muted);'>{$time}</td>
                                          </tr>";
                                }
                            } else { echo "<tr><td colspan='3' style='text-align:center; padding:20px; color:var(--success);'><i class='fa-solid fa-shield-check' style='font-size:30px; display:block; margin-bottom:10px;'></i> No suspicious activities detected.</td></tr>"; }
                            ?>
                        </table>
                    </div>
                </div>
                
                <div class="panel">
                    <h3 class="panel-title"><i class="fa-solid fa-server"></i> Live College Auth Logs</h3>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table>
                            <thead><tr><th>Time</th><th>User / Agent</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php
                            $check_logs = mysqli_query($conn, "SHOW TABLES LIKE 'login_logs'");
                            if(mysqli_num_rows($check_logs) > 0) {
                                // Fetch all login activities for heads and teachers in this admin's college
                                $logs = mysqli_query($conn, "SELECT * FROM login_logs WHERE username IN (SELECT username FROM head h JOIN departments d ON h.dept_id=d.id WHERE d.college_id=$college_id) OR username IN (SELECT username FROM teacher t JOIN departments d ON t.dept_id=d.id WHERE d.college_id=$college_id) ORDER BY attempt_time DESC LIMIT 20");
                                
                                if(mysqli_num_rows($logs) > 0){
                                    while($l = mysqli_fetch_assoc($logs)){
                                        $time = date("H:i:s", strtotime($l['attempt_time']));
                                        
                                        if($l['status'] == 'success') { $s_badge = "<span style='color:var(--success); font-weight:bold;'><i class='fa-solid fa-check'></i> OK</span>"; }
                                        elseif($l['status'] == 'failed') { $s_badge = "<span style='color:#f59e0b; font-weight:bold;'><i class='fa-solid fa-xmark'></i> FAIL</span>"; }
                                        elseif($l['status'] == 'otp_sent') { $s_badge = "<span style='color:var(--primary); font-weight:bold;'><i class='fa-solid fa-envelope'></i> OTP</span>"; }
                                        else { $s_badge = "<span style='color:var(--danger); font-weight:bold;'><i class='fa-solid fa-ban'></i> BLOCKED</span>"; }
                                        
                                        $agent = isset($l['user_agent']) ? substr($l['user_agent'], 0, 20) . "..." : "Unknown";
                                        
                                        echo "<tr style='border-bottom: 1px solid rgba(255,255,255,0.02);'>
                                                <td style='font-size:12px; color:var(--text-muted);'>{$time}</td>
                                                <td><strong style='color:var(--text-main); font-size:13px;'>{$l['username']}</strong><br><small style='font-family:monospace; color:var(--text-muted);'>{$l['ip_address']} | {$agent}</small></td>
                                                <td>{$s_badge}</td>
                                              </tr>";
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
        <!-- 7. HELP & DOCUMENTATION CENTER (Detailed Guide)-->
        <!-- ============================================== -->
        <div id="help" class="section-tab">
            
            <div class="help-hero">
                <h2><i class="fa-solid fa-book-open-reader"></i> College Admin Manual</h2>
                <p>Welcome to your comprehensive Knowledge Base. This guide explains every feature of your dashboard, from basic department management to advanced security operations and OTP configuration.</p>
                <div class="help-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="help-search-input" placeholder="Search topics (e.g., 'OTP', 'Delete Department', 'Chat')..." onkeyup="searchHelpTopics()">
                </div>
            </div>

            <div id="help-content-wrapper">
                
                <!-- SECTION 1: CONTROL CENTER -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-solid fa-chart-pie" style="color:#3b82f6;"></i> 1. The Control Center (Dashboard Home)</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Understanding the Metrics & Charts <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The Control Center is the first page you see. It provides a real-time, bird's-eye view of your entire college.</p>
                            <ul style="margin-left: 25px; margin-top: 10px;">
                                <li style="margin-bottom: 8px;"><strong>Animated Counters:</strong> Shows the total active Departments, Heads, Teachers, and Students currently registered under your specific college.</li>
                                <li style="margin-bottom: 8px;"><strong>College Demographics Chart:</strong> A dynamic Donut Chart (powered by Chart.js) that visualizes the proportion of your staff and students.</li>
                                <li style="margin-bottom: 8px;"><strong>Live System Health:</strong> Monitors the core database, security firewall, and SMTP server status globally.</li>
                                <li><strong>Recent Activities:</strong> A scrolling log of your most recent actions (e.g., assigning a head, updating a department). This ensures you have a record of what you did during your session.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: MANAGE DEPARTMENTS -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-solid fa-sitemap" style="color:#f59e0b;"></i> 2. Managing Departments</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">How to Add and Edit Departments <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>To structure your college, you must create Departments (e.g., "Computer Science", "Information Technology").</p>
                            <ul style="margin-left: 25px; margin-top: 10px;">
                                <li>Navigate to the <span class="help-highlight">Manage Departments</span> tab.</li>
                                <li>Fill in the <strong>Department Name</strong> and a unique <strong>Department Code</strong> (e.g., CS).</li>
                                <li>Click "Create Department".</li>
                                <li>To <strong>Edit</strong>, click the yellow <i class="fa-solid fa-pen"></i> button in the table. The form will scroll up and highlight, allowing you to update the details.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Secure Deletion & The Recycle Bin <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>Deleting a department is a critical action. Therefore, the system uses a <strong>Soft Delete</strong> mechanism.</p>
                            <p>When you click the red Trash icon, a secure modal will appear asking for your <strong>Admin Password</strong>. Once verified, the department is moved to the <span class="help-highlight">Recycle Bin</span> located at the bottom of the page.</p>
                            <div class="help-warning">
                                <strong><i class="fa-solid fa-triangle-exclamation"></i> Warning:</strong> Departments in the Recycle Bin are hidden from the system (their Heads and Teachers lose access). They will be permanently purged after 30 days. You can click "Restore" inside the Recycle Bin to recover them anytime before the 30 days are up.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: DEPARTMENT HEADS -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-solid fa-users-gear" style="color:#8b5cf6;"></i> 3. Managing Department Heads</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Assigning & Managing Heads <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>Every department requires a Head to manage teachers and courses. Go to the <span class="help-highlight">Department Heads</span> tab.</p>
                            <p>Select the department from the dropdown, fill in the Head's personal details, and assign a secure password. Once created, the Head can log in to their own dashboard.</p>
                            <div class="help-pro-tip">
                                <strong><i class="fa-solid fa-toggle-on"></i> Suspend Access:</strong> If a Head is on leave or compromised, you can instantly revoke their access by clicking the "Suspend/Activate" toggle button in the table.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: GLOBAL OVERSIGHT -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-solid fa-network-wired" style="color:#10b981;"></i> 4. College Oversight (Drill-Down Navigation)</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">How to use the Magic Drill-Down feature <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The <strong>College Oversight</strong> tab is a Single Page Application (SPA) designed to let you explore your college hierarchy without ever refreshing the page.</p>
                            <ol style="margin-left: 25px; margin-top: 10px; line-height: 2;">
                                <li><strong>Level 1:</strong> You will see cards for all your Departments. Click a Department card.</li>
                                <li><strong>Level 2:</strong> The view slides elegantly to show all Teachers assigned to that specific department. Click a Teacher card.</li>
                                <li><strong>Level 3:</strong> The view slides again to display a list of all Students enrolled under that department.</li>
                            </ol>
                            <p style="margin-top: 10px;">To go back, simply use the <strong>Breadcrumb Navigation</strong> at the top (e.g., <em>All Departments > Computer Science</em>).</p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 5: COMMUNICATIONS -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-brands fa-telegram" style="color:#0ea5e9;"></i> 5. Communications Hub (Telegram-Style)</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Broadcasting and Private Chats <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The system features a real-time, encrypted messaging platform. You do not need to refresh the page to receive messages.</p>
                            <ul style="margin-left: 25px; margin-top: 10px;">
                                <li style="margin-bottom: 8px;"><strong>Folders:</strong> Use the left sidebar to filter between All Chats, Heads, and Teachers.</li>
                                <li style="margin-bottom: 8px;"><strong>Groups (Broadcasts):</strong> Click "📢 All Heads" or "📢 All Teachers" to send mass announcements.</li>
                                <li style="margin-bottom: 8px;"><strong>Private Chats:</strong> Click on an individual's name to chat privately. Unread badges (red numbers) will appear if they send you a message.</li>
                                <li><strong>Editing/Deleting:</strong> <em>Right-click</em> on any message you sent. A custom menu will appear allowing you to Edit or Delete the message for everyone.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- SECTION 6: SECURITY CENTER -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-solid fa-shield-halved" style="color:var(--danger);"></i> 6. Security Center & Threat Monitoring</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">Monitoring Suspicious Activities <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>As a College Admin, you have oversight of the security health of your Heads and Teachers.</p>
                            <p>The <strong>Security Health Table</strong> analyzes the login habits of your staff. It shows their last known IP address and calculates a Threat Level based on failed login attempts within the last 24 hours.</p>
                            <p>The <strong>Suspicious Logins</strong> panel instantly highlights if someone is trying to brute-force an account within your college.</p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 7: SETTINGS & OTP LOGIC (CRITICAL) -->
                <div class="help-topic-section">
                    <h3 class="help-topic-title"><i class="fa-solid fa-user-gear" style="color:#e81cff;"></i> 7. Settings, 2FA, and OTP Logic (CRITICAL)</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">How the 2FA (Two-Factor Auth) Ecosystem Works <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>EPLMS uses an advanced Two-Tier Authentication Architecture. It is vital that you understand the difference between your <strong>Private Email</strong> and your <strong>Public Email</strong>.</p>
                            
                            <h4 style="margin-top: 15px; color: var(--primary);">A. Your Private Email (Receives Your OTP)</h4>
                            <p>When YOU (the College Admin) enable 2FA and try to log in, the system uses the <em>Super Admin's Public Email</em> to send an OTP code <strong>to your Private Email</strong>. You must enter this code to access your dashboard.</p>
                            
                            <h4 style="margin-top: 15px; color: #f59e0b;">B. Your Public Email & App Password (Sends OTP to Your Staff)</h4>
                            <p>As a College Admin, YOU are the security provider for your Heads and Teachers. When they enable 2FA, the system needs an email server to send them their codes.</p>
                            <ul style="margin-left: 25px; margin-top: 10px;">
                                <li>In the <span class="help-highlight">Settings</span> tab, you must fill in the <strong>Public Email (System Sender Email)</strong>.</li>
                                <li>You must also generate a 16-character <strong>Google App Password</strong> and save it in the system.</li>
                                <li>Once saved, the system will use your Public Email to securely dispatch OTP codes to the Private Emails of your Department Heads and Teachers when they log in.</li>
                            </ul>
                            
                            <div class="help-pro-tip">
                                <strong><i class="fa-solid fa-shield"></i> Profile Updates:</strong> To change your password, name, or emails, you must enter your current password in the red authorization box at the bottom of the Settings page. This prevents unauthorized scripts from hijacking your account.
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- End Content Wrapper -->
        </div>
       <!-- ============================================== -->
        <!-- 8. SETTINGS & PROFILE (PREMIUM OMG UI)         -->
        <!-- ============================================== -->
        <div id="settings" class="section-tab">
            <div class="profile-header-card" style="background: linear-gradient(135deg, #1e3a8a 0%, #312e81 100%); border-bottom: 5px solid #3b82f6;">
                <div class="profile-avatar-wrapper">
                    <img src="<?php echo $profile_pic; ?>" alt="Profile" class="profile-avatar-large" id="preview_avatar_top" style="border: 4px solid #60a5fa; box-shadow: 0 0 20px rgba(96, 165, 250, 0.5);">
                    <label for="pic_upload" class="edit-avatar-btn" style="background: #fcd535; color: #000; border-color: #1e3a8a;"><i class="fa-solid fa-camera"></i></label>
                </div>
                <h2 class="profile-name"><?php echo htmlspecialchars($admin_info['name']); ?> <i class="fa-solid fa-circle-check" style="color:#34d399; font-size:22px; text-shadow: 0 0 10px rgba(52, 211, 153, 0.5);" title="Verified College Admin"></i></h2>
                <p class="profile-email" style="color: #93c5fd; font-weight: 500;"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($admin_info['email']); ?></p>
                <div class="profile-badges" style="margin-top: 15px;">
                    <span class="p-badge" style="background: rgba(59, 130, 246, 0.3); border: 1px solid rgba(59, 130, 246, 0.5);"><i class="fa-solid fa-building-columns"></i> <?php echo htmlspecialchars($admin_info['college_name']); ?></span>
                    <span class="p-badge" style="background: rgba(16, 185, 129, 0.3); border: 1px solid rgba(16, 185, 129, 0.5);"><i class="fa-solid fa-shield-check"></i> System Administrator</span>
                </div>
            </div>

            <div class="inner-tabs" style="margin-bottom: 40px; background: rgba(0,0,0,0.1); padding: 10px; border-radius: 12px; display: inline-flex;">
                <button class="inner-tab-btn active" onclick="switchInnerTab('account', this)" style="border-radius: 8px;"><i class="fa-solid fa-id-card-clip"></i> Account & Mail Server</button>
                <button class="inner-tab-btn" onclick="switchInnerTab('security', this)" style="border-radius: 8px;"><i class="fa-solid fa-user-shield"></i> 2FA & Privacy Settings</button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="profile_pic" id="pic_upload" style="display:none;" onchange="previewImage(this)">
                
                <!-- TAB 1: ACCOUNT & MAIL SERVER -->
                <div id="inner-account" class="inner-tab-content active">
                    <div class="settings-grid">
                        
                        <!-- LEFT PANEL: PRIVATE IDENTITY -->
                        <div class="premium-panel" style="border-top-color: #3b82f6;">
                            <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-user"></i></div> Personal Identity</h3>
                            
                            <div class="info-alert success">
                                <strong><i class="fa-solid fa-lock"></i> Private Email Role:</strong><br>
                                This email is strictly for your security. When you enable 2FA, the <strong>Super Admin</strong> will send your OTP login codes to this private address.
                            </div>

                            <div class="form-group"><label>Full Name</label><div class="input-with-icon"><input type="text" name="a_name" value="<?php echo htmlspecialchars($admin_info['name']); ?>" required><i class="fa-solid fa-user-tie"></i></div></div>
                            <div class="form-group"><label>Unique Username</label><div class="input-with-icon"><input type="text" name="a_username" value="<?php echo htmlspecialchars($admin_info['username']); ?>" required><i class="fa-solid fa-at"></i></div></div>
                            <div class="form-group">
                                <label style="color: #10b981; font-weight: 700;">Private Email (Receives 2FA OTPs)</label>
                                <div class="input-with-icon"><input type="email" name="a_email" value="<?php echo htmlspecialchars($admin_info['email']); ?>" required style="border-color: #10b981;"><i class="fa-solid fa-envelope-circle-check" style="color: #10b981;"></i></div>
                            </div>
                            <div class="form-group"><label>Phone Number (Optional)</label><div class="input-with-icon"><input type="text" name="a_phone" value="<?php echo htmlspecialchars($admin_info['phone'] ?? ''); ?>"><i class="fa-solid fa-phone"></i></div></div>
                        </div>

                        <!-- RIGHT PANEL: PUBLIC MAIL SERVER -->
                        <div class="premium-panel" style="border-top-color: #f59e0b;">
                            <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-server"></i></div> System Mail Server</h3>
                            
                            <div class="info-alert warning">
                                <strong><i class="fa-solid fa-satellite-dish"></i> Public Sender Role:</strong><br>
                                This is your College's Mail Server. When your <strong>Heads or Teachers</strong> enable 2FA, the system uses this Email & App Password to automatically send OTP codes to them.
                            </div>

                            <div class="form-group">
                                <label style="color: #f59e0b; font-weight: 700;">Public Email Sender (College Email)</label>
                                <div class="input-with-icon"><input type="email" name="a_public_email" value="<?php echo htmlspecialchars($admin_info['public_email'] ?? ''); ?>" placeholder="e.g. admin@yourcollege.edu"><i class="fa-solid fa-envelope-open-text" style="color: #f59e0b;"></i></div>
                            </div>
                            <div class="form-group pw-group">
                                <label style="color: #f59e0b; font-weight: 700;">Google App Password (For SMTP)</label>
                                <div class="input-with-icon">
                                    <input type="password" name="a_app_password" id="admin_app_pass" value="<?php echo htmlspecialchars($admin_info['app_password'] ?? ''); ?>" placeholder="16-character Google App Code" style="border-color: #f59e0b;">
                                    <i class="fa-solid fa-key" style="color: #f59e0b;"></i>
                                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('admin_app_pass', this)" style="color: #f59e0b;"></i>
                                </div>
                                <small style="display:block; margin-top:8px; color:var(--text-muted); font-size:11px;"><i class="fa-brands fa-google"></i> Must be generated from Google Account > Security > App Passwords.</small>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- TAB 2: 2FA & PRIVACY SETTINGS -->
                <div id="inner-security" class="inner-tab-content">
                    <div class="settings-grid">
                        <div class="premium-panel" style="border-top-color: #8b5cf6;">
                            <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-shield-virus"></i></div> Advanced Security Policies</h3>
                            
                            <div class="sec-toggle" style="background: rgba(139, 92, 246, 0.05); border-color: rgba(139, 92, 246, 0.2);">
                                <div class="sec-toggle-info">
                                    <h4 style="color: #c4b5fd;"><i class="fa-solid fa-mobile-screen-button"></i> Two-Factor Auth (2FA)</h4>
                                    <p>Require dynamic OTP code sent to your Private Email during every login.</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="two_factor" <?php echo $admin_info['two_factor_enabled'] ? 'checked' : ''; ?>><span class="slider" style="background-color: #8b5cf6;"></span></label>
                            </div>
                            
                            <div class="sec-toggle">
                                <div class="sec-toggle-info">
                                    <h4><i class="fa-solid fa-bell"></i> Login Alerts</h4>
                                    <p>Receive an email notification on new device login attempts.</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="login_alerts" <?php echo $admin_info['login_alerts'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>

                            <div class="sec-toggle" style="margin-top: 20px; border-left: 3px solid var(--danger);">
                                <div class="sec-toggle-info">
                                    <h4><i class="fa-solid fa-user-lock"></i> Profile Privacy Lock</h4>
                                    <p>Hide your Avatar photo from Heads & Teachers in the chat list.</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="profile_locked" <?php echo isset($admin_info['profile_locked']) && $admin_info['profile_locked'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- BOTTOM SAVE SECTION (ALWAYS VISIBLE - OMG DESIGN) -->
                <div class="premium-panel" style="margin-top: 15px; border: 1px solid rgba(246, 70, 93, 0.3); border-top: 4px solid var(--danger); box-shadow: 0 10px 40px rgba(246, 70, 93, 0.08); background: linear-gradient(180deg, var(--panel-bg) 0%, rgba(246, 70, 93, 0.02) 100%);">
                    <h3 class="panel-title-premium" style="color: var(--danger); border-bottom-color: rgba(246, 70, 93, 0.1);">
                        <i class="fa-solid fa-fingerprint" style="font-size: 24px;"></i> Final Security Authorization
                    </h3>
                    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 25px;">To apply any changes to your profile, mail server, or security policies, you must verify your identity using your current password.</p>
                    
                    <div class="settings-grid" style="gap: 30px;">
                        <div class="form-group pw-group">
                            <label style="color:var(--danger); font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:1px;">Current Password (Required) *</label>
                            <div class="input-with-icon">
                                <input type="password" name="current_password" id="curr_pass" placeholder="Enter your current password to verify..." required style="border: 2px solid rgba(246, 70, 93, 0.5); background: rgba(246, 70, 93, 0.05); padding: 16px 16px 16px 45px; font-size:15px;">
                                <i class="fa-solid fa-shield-keyhole" style="color:var(--danger); font-size: 18px;"></i>
                                <i class="fa-solid fa-eye pw-eye" onclick="togglePw('curr_pass', this)" style="color: var(--danger);"></i>
                            </div>
                        </div>
                        
                        <div class="form-group pw-group">
                            <label style="font-weight:700;">New Strong Password (Optional)</label>
                            <div class="input-with-icon">
                                <input type="password" name="new_password" id="new_pass" placeholder="Leave blank if you don't want to change it" onkeyup="checkPasswordStrength()" style="padding: 16px 16px 16px 45px; font-size:15px;">
                                <i class="fa-solid fa-key"></i>
                                <i class="fa-solid fa-eye pw-eye" onclick="togglePw('new_pass', this)"></i>
                            </div>
                            
                            <div class="pw-rules" id="pw-rules" style="background: var(--bg-color); border: 1px dashed var(--border-color);">
                                <div id="rule-length" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</div>
                                <div id="rule-upper" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> Upper & Lowercase letters</div>
                                <div id="rule-number" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> Contains a Number</div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: right; margin-top:35px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 25px;">
                        <button type="submit" name="save_all_settings" class="glow-btn">
                            <i class="fa-solid fa-shield-check" style="font-size: 20px;"></i> Save & Authenticate
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
</main>

<!-- Delete Modals -->
<div id="secureDeleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Deletion</h3>
        <p>Delete <strong id="del_dept_name" style="color:var(--primary);"></strong>?</p>
        <form method="POST">
            <input type="hidden" name="dept_id" id="del_dept_id">
            <div class="form-group"><input type="password" name="admin_password" placeholder="Enter your password" required style="text-align:center;"></div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('secureDeleteModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="soft_delete_dept" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<div id="headDeleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Deletion</h3>
        <p>Delete <strong id="del_head_name" style="color:var(--primary);"></strong>?</p>
        <form method="POST">
            <input type="hidden" name="head_id" id="del_head_id">
            <div class="form-group"><input type="password" name="admin_password" placeholder="Enter your password" required style="text-align:center;"></div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('headDeleteModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="soft_delete_head" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- CUSTOM RIGHT CLICK MENU (CHAT) -->
<div id="chat-context-menu" class="chat-context-menu">
    <div class="context-item" id="ctx-edit"><i class="fa-solid fa-pen"></i> Edit Message</div>
    <div class="context-item delete" id="ctx-delete"><i class="fa-solid fa-trash"></i> Delete Message</div>
</div>

<script>
    const themeIcon = document.getElementById('theme-icon');
    const themeText = document.getElementById('theme-text');
    function toggleTheme() { document.body.classList.toggle('light-mode'); const isLight = document.body.classList.contains('light-mode'); localStorage.setItem('eplms_theme', isLight ? 'light' : 'dark'); if(themeIcon) themeIcon.className = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon'; if(themeText) themeText.innerText = isLight ? 'Dark Mode' : 'Light Mode'; }
    if(localStorage.getItem('eplms_theme') === 'light'){ document.body.classList.add('light-mode'); if(themeIcon) themeIcon.className = 'fa-solid fa-sun'; if(themeText) themeText.innerText = 'Dark Mode'; }
    
    function animateCounters() { document.querySelectorAll('.counter').forEach(counter => { counter.innerText = '0'; const target = +counter.getAttribute('data-target'); const inc = target / 30; const update = () => { const c = +counter.innerText; if(c < target) { counter.innerText = Math.ceil(c + inc); setTimeout(update, 30); } else { counter.innerText = target; } }; update(); }); }
    animateCounters(); 

    function openTab(tabId) { document.querySelectorAll('.section-tab').forEach(el=>el.classList.remove('active')); document.querySelectorAll('.tab-link').forEach(el=>el.classList.remove('active')); document.getElementById(tabId).classList.add('active'); event.currentTarget.classList.add('active'); if(tabId === 'home') { animateCounters(); if(window.collegeDChart) window.collegeDChart.update(); } }
    
    function updateClock() { const now = new Date(); let h = now.getHours(); let m = now.getMinutes(); let s = now.getSeconds(); document.getElementById('real-time-clock').innerText = `${h%12||12}:${m<10?'0'+m:m}:${s<10?'0'+s:s} ${h>=12?'PM':'AM'}`; } setInterval(updateClock, 1000); updateClock();
    
    function editDept(id, name, code) { document.getElementById('form_dept_id').value=id; document.getElementById('form_dept_name').value=name; document.getElementById('form_dept_code').value=code; document.getElementById('btn_add_dept').style.display='none'; document.getElementById('btn_edit_dept').style.display='block'; window.scrollTo(0,0); }
    function confirmDelete(type, id, name) { 
        if(type==='dept'){ document.getElementById('del_dept_id').value=id; document.getElementById('del_dept_name').innerText=name; document.getElementById('secureDeleteModal').classList.add('active'); }
        if(type==='head'){ document.getElementById('del_head_id').value=id; document.getElementById('del_head_name').innerText=name; document.getElementById('headDeleteModal').classList.add('active'); }
    }
    
    let breadcrumbTrail =[{ id: 'lvl1', title: 'All Departments' }];
    function resetOversight() { navToLevel('lvl1', 'Departments', null, true); }
    function navToLevel(viewId, title, element = null, isReset = false) {
        document.querySelectorAll('.oversight-view').forEach(el => el.classList.remove('active')); 
        const tgt = document.getElementById('view-' + viewId); if(tgt) tgt.classList.add('active');
        if(isReset) breadcrumbTrail =[{ id: viewId, title: 'All Departments' }]; 
        else if(element && element.classList.contains('bc-item')) { const idx = breadcrumbTrail.findIndex(item => item.id === viewId); breadcrumbTrail = breadcrumbTrail.slice(0, idx + 1); }
        else breadcrumbTrail.push({ id: viewId, title: title });
        let bcHTML = ''; breadcrumbTrail.forEach((item, index) => { if(index>0) bcHTML += `<span class="bc-separator"><i class="fa-solid fa-chevron-right"></i></span>`; const isActive = (index === breadcrumbTrail.length - 1) ? 'active' : ''; bcHTML += `<span class="bc-item ${isActive}" onclick="navToLevel('${item.id}', '${item.title}', this)">${item.title}</span>`; }); document.getElementById('oversight-breadcrumbs').innerHTML = bcHTML;
    }
    
    function switchInnerTab(tabName, btnElement) { document.querySelectorAll('.inner-tab-btn').forEach(btn => btn.classList.remove('active')); document.querySelectorAll('.inner-tab-content').forEach(content => content.classList.remove('active')); btnElement.classList.add('active'); document.getElementById('inner-' + tabName).classList.add('active'); }
    function previewImage(input) { if(input.files && input.files[0]){ let reader = new FileReader(); reader.onload = function(e){ document.getElementById('preview_avatar_top').src = e.target.result; }; reader.readAsDataURL(input.files[0]); } }

    // 🪄 Telegram Chat Logic
    let currentChatId=null, currentChatRole=null, currentChatIsGroup=null, chatInterval=null;
    function switchFolder(folder) { document.querySelectorAll('.tg-folder').forEach(el=>el.classList.remove('active')); event.currentTarget.classList.add('active'); document.querySelectorAll('.tg-contact-item').forEach(el=>{ el.style.display='none'; if(el.classList.contains('chat-item-'+folder)) el.style.display='flex'; }); }
    
  function openTelegramChat(id, role, isGroup, name, subtitle, color, avatar_url = '') { 
        document.getElementById('tg-placeholder').style.display='none'; 
        document.getElementById('tg-active-chat').style.display='flex'; 
        document.getElementById('chat-header-name').innerHTML=name; 
        document.getElementById('chat-header-role').innerText=subtitle; 
        
        const avatarDiv = document.getElementById('chat-header-avatar'); 
        avatarDiv.style.background = color; 
        avatarDiv.style.position = 'relative'; 
        
        if(isGroup === 1) { 
            avatarDiv.innerHTML = '<i class="fa-solid fa-bullhorn"></i>'; 
            avatarDiv.classList.add('group'); 
        } else { 
            if(avatar_url === 'LOCKED') {
                avatarDiv.innerHTML = '<i class="fa-solid fa-user-lock" style="font-size:20px; color:#fff;"></i>';
                avatarDiv.style.background = color;
            } else if(avatar_url && avatar_url !== '') { 
                let imgHtml = `<img src="${avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`; 
                if(role === 'super_admin') {
                    imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#0ea5e9; background:#fff; border-radius:50%; font-size:14px; border:2px solid var(--panel-bg); z-index:10;"></i>`;
                }
                avatarDiv.innerHTML = imgHtml; 
                avatarDiv.style.background = 'transparent'; 
            } else { 
                avatarDiv.innerHTML = name.replace(/<[^>]*>?/gm, '').trim().charAt(0).toUpperCase(); 
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
        let fd=new FormData(); 
        fd.append('ajax_action','fetch_chat'); fd.append('receiver_id',currentChatId); fd.append('receiver_role',currentChatRole); fd.append('is_group',currentChatIsGroup); 
        fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.text()).then(h=>{ 
            const chatHistory = document.getElementById('chat-history-container'); 
            let isAtBottom = chatHistory.scrollHeight - chatHistory.clientHeight <= chatHistory.scrollTop + 50; 
            chatHistory.innerHTML=h; 
            if(isAtBottom) chatHistory.scrollTop = chatHistory.scrollHeight; 
        }); 
    }
    
    function submitTelegramMsg(e) { 
        e.preventDefault(); let input=document.getElementById('chat_message_input'); if(!input.value.trim())return; 
        let fd=new FormData(document.getElementById('tg-chat-form')); 
        fd.append('ajax_action', document.getElementById('edit_msg_id').value ? 'edit_msg' : 'send_msg'); 
        fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ 
            if(d.status==='success'){ input.value=''; document.getElementById('edit_msg_id').value=''; fetchChatMessages(); setTimeout(() => { const chatHistory = document.getElementById('chat-history-container'); chatHistory.scrollTop = chatHistory.scrollHeight; }, 100); }
        }); 
    }

    // 🪄 MAGIC UNREAD BADGE SYSTEM
    function fetchUnreadBadges() {
        let fd = new FormData();
        fd.append('ajax_action', 'fetch_unread'); 
        
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
  
    }
    setInterval(fetchUnreadBadges, 2000); 
    fetchUnreadBadges(); 

    function deleteMessage(msgId) { if(!confirm("Are you sure you want to delete this message?")) return; let fd = new FormData(); fd.append('ajax_action', 'delete_msg'); fd.append('msg_id', msgId); fetch(window.location.href, { method: 'POST', body: fd }).then(res => res.json()).then(data => { if(data.status === 'success') fetchChatMessages(); }); }
    function editMessage(msgId, text) { document.getElementById('chat_message_input').value = text; document.getElementById('edit_msg_id').value = msgId; document.getElementById('chat_message_input').focus(); }

    let ctxMenuMsgId = null; let ctxMenuMsgText = "";
    function showContextMenu(e, msgId, msgText) { e.preventDefault(); const ctxMenu = document.getElementById('chat-context-menu'); ctxMenuMsgId = msgId; ctxMenuMsgText = msgText; ctxMenu.style.display = 'block'; let x = e.pageX; let y = e.pageY; if(x + ctxMenu.offsetWidth > window.innerWidth) x = window.innerWidth - ctxMenu.offsetWidth - 10; if(y + ctxMenu.offsetHeight > window.innerHeight) y = window.innerHeight - ctxMenu.offsetHeight - 10; ctxMenu.style.left = x + 'px'; ctxMenu.style.top = y + 'px'; }
    document.addEventListener('click', function(e) { const ctxMenu = document.getElementById('chat-context-menu'); if(ctxMenu.style.display === 'block') ctxMenu.style.display = 'none'; });
    document.getElementById('ctx-edit').addEventListener('click', function() { if(ctxMenuMsgId) editMessage(ctxMenuMsgId, ctxMenuMsgText); });
    document.getElementById('ctx-delete').addEventListener('click', function() { if(ctxMenuMsgId) deleteMessage(ctxMenuMsgId); });

    function filterTelegramChats() { let input = document.getElementById('tg-search').value.toLowerCase(); document.querySelectorAll('.tg-contact-item').forEach(item => { let name = item.querySelector('.tg-name').innerText.toLowerCase(); item.style.display = name.indexOf(input) > -1 ? "flex" : "none"; }); }

    function togglePw(id, icon) { let input = document.getElementById(id); if (input.type === "password") { input.type = "text"; icon.classList.remove("fa-eye"); icon.classList.add("fa-eye-slash"); } else { input.type = "password"; icon.classList.remove("fa-eye-slash"); icon.classList.add("fa-eye"); } }
    function checkPasswordStrength() { let pw = document.getElementById('new_pass').value; let rulesBox = document.getElementById('pw-rules'); if (pw.length > 0) rulesBox.style.display = 'block'; else rulesBox.style.display = 'none'; updateRule('rule-length', pw.length >= 8); updateRule('rule-upper', /[a-z]/.test(pw) && /[A-Z]/.test(pw)); updateRule('rule-number', /\d/.test(pw)); }
    function updateRule(id, isValid) { let el = document.getElementById(id); let icon = el.querySelector('i'); if(isValid) { el.classList.add('valid'); icon.className = 'fa-solid fa-circle-check'; } else { el.classList.remove('valid'); icon.className = 'fa-solid fa-circle-xmark'; } }

    setTimeout(() => { let alert = document.querySelector('.alert'); if(alert) alert.style.display = 'none'; }, 4000);

    const ctxCollege = document.getElementById('collegeDemographicsChart');
    if(ctxCollege) {
        window.collegeDChart = new Chart(ctxCollege.getContext('2d'), {
            type: 'doughnut',
            data: { 
                labels:['Departments', 'Heads', 'Teachers', 'Students'], 
                datasets:[{ data:[<?php echo $dept_count; ?>, <?php echo $heads_count; ?>, <?php echo $teachers_count; ?>, <?php echo $students_count; ?>], backgroundColor:['#3b82f6', '#8b5cf6', '#10b981', '#ef4444'], borderWidth: 0, hoverOffset: 10 }] 
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right', labels: { color: '#848e9c', font: { family: 'Inter' } } } }, animation: { animateScale: true, animateRotate: true } }
        });
    }
// --- HELP CENTER ACCORDION & SEARCH LOGIC ---
    function toggleHelpAcc(btn) {
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

</script>
</body>
</html>