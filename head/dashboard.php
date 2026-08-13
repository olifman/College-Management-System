<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include("../includes/config.php");

// ========================================================
// 🛡️ 1. SECURITY: HEAD OF DEPARTMENT AUTHENTICATION
// ========================================================
if(!isset($_SESSION['username']) || $_SESSION['role'] != 'head'){
    header("Location: ../index.php");
    exit();
}

$head_name = $_SESSION['username'];
$head_id = $_SESSION['user_id'];
$dept_id = $_SESSION['dept_id']; // Strictly scoped to this department!
$message = ""; $msg_type = "success";

date_default_timezone_set('Africa/Addis_Ababa');

// Fetch Head Profile & Department/College Info
// Fetch Head Profile & Department/College Info
$head_info_q = mysqli_query($conn, "SELECT h.*, d.dept_name, d.dept_code, d.college_id, c.college_name FROM head h JOIN departments d ON h.dept_id = d.id JOIN colleges c ON d.college_id = c.id WHERE h.id=$head_id");
$head_info = mysqli_fetch_assoc($head_info_q);
$college_id = $head_info['college_id']; // For knowing which admin is the boss

$profile_pic = !empty($head_info['profile_pic']) && file_exists("../uploads/".$head_info['profile_pic']) ? "../uploads/".$head_info['profile_pic'] : "https://ui-avatars.com/api/?name=".urlencode($head_info['name'])."&background=8b5cf6&color=fff";

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
$target_table = ($rec_role == 'teacher') ? 'teacher' : 'student';
                $extra_cond = ($rec_role == 'student') ? " AND status='accepted'" : "";
                $users = mysqli_query($conn, "SELECT id FROM `$target_table` WHERE dept_id=$dept_id AND is_deleted=0 $extra_cond");
                                while($u = mysqli_fetch_assoc($users)) {
                    $u_id = $u['id'];
                    mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($head_id, 'head', $u_id, 'teacher', '$msg', 0, 0)");
                }
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($head_id, 'head', 0, 'teacher', '📢 BROADCAST: $msg', 1, 1)");
            } else {
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($head_id, 'head', $rec_id, '$rec_role', '$msg', 0, 0)");
            }
        }
        echo json_encode(['status'=>'success']); exit();
    }
    
    if($action == 'edit_msg') {
        $msg_id = intval($_POST['msg_id']); $new_msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        mysqli_query($conn, "UPDATE messages SET is_edited=1, message='$new_msg' WHERE id=$msg_id AND sender_id=$head_id AND sender_role='head'");
        echo json_encode(['status'=>'success']); exit();
    }
    
    if($action == 'delete_msg') {
        $msg_id = intval($_POST['msg_id']);
        mysqli_query($conn, "UPDATE messages SET is_deleted=1 WHERE id=$msg_id AND sender_id=$head_id AND sender_role='head'");
        echo json_encode(['status'=>'success']); exit();
    }
    
   if($action == 'fetch_unread') {
        $q = mysqli_query($conn, "SELECT sender_id, sender_role, COUNT(*) as c FROM messages WHERE receiver_id=$head_id AND receiver_role='head' AND is_read=0 AND is_group=0 GROUP BY sender_id, sender_role");
        $data =[]; $total = 0;
        while($r = mysqli_fetch_assoc($q)){ $key = $r['sender_role'] . '_' . $r['sender_id']; $data[$key] = $r['c']; $total += $r['c']; }
        $data['total_all'] = $total;
        
        $pending_q = mysqli_query($conn, "SELECT COUNT(*) as p_count FROM student WHERE dept_id=$dept_id AND status='pending'");
        $data['pending_students'] = mysqli_fetch_assoc($pending_q)['p_count'] ?? 0;
        
        echo json_encode($data); exit();
    }
    
    if($action == 'approve_student_ajax') {
        $s_id = intval($_POST['student_id']);
        $stu_q = mysqli_query($conn, "SELECT * FROM student WHERE id=$s_id AND status='pending'");
        
        if($stu = mysqli_fetch_assoc($stu_q)) {
            $fname = $stu['first_name']; 
            $email = $stu['email'];
            
            $base_user = strtolower($fname); $username = $base_user; $c = 1;
            while(mysqli_num_rows(mysqli_query($conn, "SELECT id FROM student WHERE username='$username' AND id!=$s_id")) > 0){ $username = $base_user . $c; $c++; }
            $raw_pass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$'), 0, 8);
            
            mysqli_query($conn, "UPDATE student SET status='accepted', username='$username', password='$raw_pass' WHERE id=$s_id AND dept_id=$dept_id");
            mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Approved Student', 'Approved student ID: {$stu['id_number']}')");
            
            $smtp_user = ""; $smtp_pass = "";
            $h_q = mysqli_query($conn, "SELECT public_email, app_password FROM head WHERE id=$head_id LIMIT 1");
            if($h_data = mysqli_fetch_assoc($h_q)) { if(!empty($h_data['public_email'])) { $smtp_user = $h_data['public_email']; $smtp_pass = $h_data['app_password']; } }
            if(empty($smtp_user)) {
                $admin_q = mysqli_query($conn, "SELECT a.public_email, a.app_password FROM admin a JOIN departments d ON a.college_id = d.college_id WHERE d.id = $dept_id LIMIT 1");
                if($admin_data = mysqli_fetch_assoc($admin_q)) { if(!empty($admin_data['public_email'])) { $smtp_user = $admin_data['public_email']; $smtp_pass = $admin_data['app_password']; } }
            }
            if(empty($smtp_user)) {
                $sa_q = mysqli_query($conn, "SELECT public_email, app_password FROM super_admin LIMIT 1");
                if($sa_data = mysqli_fetch_assoc($sa_q)) { $smtp_user = $sa_data['public_email']; $smtp_pass = $sa_data['app_password']; }
            }
            
            require_once '../includes/PHPMailer/src/Exception.php';
            require_once '../includes/PHPMailer/src/PHPMailer.php';
            require_once '../includes/PHPMailer/src/SMTP.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP(); $mail->Host='smtp.gmail.com'; $mail->SMTPAuth=true; $mail->SMTPSecure='tls'; $mail->Port=587;
                $mail->Username = $smtp_user; $mail->Password = $smtp_pass;
                $mail->setFrom($smtp_user, 'EPLMS Dept Head'); $mail->addAddress($email);
                $mail->isHTML(true); $mail->Subject = 'EPLMS - Account Approved!';
                $mail->Body = "<div style='font-family:Arial;max-width:500px;margin:auto;border:1px solid #ddd;border-radius:10px;overflow:hidden;'><div style='background:#10b981;padding:20px;text-align:center;color:#fff;'><h2>Registration Approved!</h2></div><div style='padding:25px;'><p>Hello <b>$fname</b>,</p><p>Your registration has been approved by your Head of Department.</p><div style='background:#f3f4f6;padding:15px;border-radius:8px;margin:20px 0;'><p><b>Username:</b> <span style='color:#10b981;'>$username</span></p><p><b>Password:</b> <span style='color:#10b981;'>$raw_pass</span></p></div><p style='font-size:12px;color:#666;'>Login and change your password immediately.</p></div></div>";
                $mail->send();
            } catch (Exception $e) {}
            
            echo json_encode(['status'=>'success']); exit();
        } else {
            echo json_encode(['status'=>'error']); exit();
        }
    }
    
    if($action == 'fetch_chat') {
        $rec_id = intval($_POST['receiver_id']); $rec_role = mysqli_real_escape_string($conn, $_POST['receiver_role']); $is_group = intval($_POST['is_group']);
        
        if($is_group == 0) mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$head_id AND receiver_role='head'");
        
        $query = ($is_group == 1) 
            ? "SELECT * FROM messages WHERE is_group=1 AND receiver_role='$rec_role' ORDER BY sent_at ASC" 
            : "SELECT * FROM messages WHERE is_group=0 AND ((sender_id=$head_id AND sender_role='head' AND receiver_id=$rec_id AND receiver_role='$rec_role') OR (sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$head_id AND receiver_role='head')) ORDER BY sent_at ASC";
        
        $res = mysqli_query($conn, $query);
        if(mysqli_num_rows($res) == 0) { echo "<div class='tg-placeholder'><i class='fa-solid fa-lock'></i><p>End-to-end encrypted. Say hello!</p></div>"; exit(); }

        $html = '';
        while($m = mysqli_fetch_assoc($res)){
            $is_me = ($m['sender_role'] == 'head' && $m['sender_id'] == $head_id);
            $align = $is_me ? 'chat-right' : 'chat-left';
            $time = date("M d, H:i", strtotime($m['sent_at']));
            $msg_text = nl2br(htmlspecialchars($m['message']));
            $status = '';
            
            if($m['is_deleted'] == 1) { $msg_text = "<i style='color:var(--danger); opacity:0.8;'><i class='fa-solid fa-ban'></i> This message was deleted</i>"; $status = "<span style='color:var(--danger);'>Deleted</span>"; } 
            elseif($m['is_edited'] == 1) { $status = "<span style='opacity:0.6;'><i class='fa-solid fa-pen'></i> Edited</span>"; }

            $oncontext = ($is_me && $m['is_deleted'] == 0) ? "oncontextmenu='showContextMenu(event, {$m['id']}, \"".htmlspecialchars($m['message'], ENT_QUOTES)."\"); return false;'" : "";

            $html .= "<div class='chat-msg-wrapper {$align}'><div class='chat-bubble' {$oncontext} style='cursor: context-menu;'><div class='chat-text'>{$msg_text}</div><div class='chat-meta'>{$time} {$status}</div></div></div>";
        }
        echo $html; exit();
    }
}

// ========================================================
// 🔧 3. AUTO-DATABASE SETUP & MIGRATION (Head Level)
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS head_activities (id INT AUTO_INCREMENT PRIMARY KEY, head_id INT NOT NULL, action_type VARCHAR(100) NOT NULL, details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (head_id) REFERENCES head(id) ON DELETE CASCADE)");

function addColHead($conn, $table, $col, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if(mysqli_num_rows($res) == 0) mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

addColHead($conn, 'teacher', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColHead($conn, 'teacher', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
addColHead($conn, 'student', 'is_deleted', 'TINYINT(1) DEFAULT 0');
addColHead($conn, 'student', 'deleted_at', 'TIMESTAMP NULL DEFAULT NULL');
addColHead($conn, 'head', 'phone', 'VARCHAR(20) DEFAULT NULL');
addColHead($conn, 'head', 'public_email', 'VARCHAR(100) DEFAULT NULL');
addColHead($conn, 'head', 'app_password', 'VARCHAR(255) DEFAULT NULL');
addColHead($conn, 'head', 'two_factor_enabled', 'TINYINT(1) DEFAULT 0');
addColHead($conn, 'head', 'login_alerts', 'TINYINT(1) DEFAULT 1');
addColHead($conn, 'head', 'profile_locked', 'TINYINT(1) DEFAULT 0');
addColHead($conn, 'student', 'first_name', 'VARCHAR(50) DEFAULT NULL');
addColHead($conn, 'student', 'last_name', 'VARCHAR(50) DEFAULT NULL');
addColHead($conn, 'student', 'id_number', 'VARCHAR(50) DEFAULT NULL');
addColHead($conn, 'departments', 'registration_open', 'TINYINT(1) DEFAULT 1');
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS class_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_id INT NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    time_slot VARCHAR(50) NOT NULL,
    class_type VARCHAR(50) DEFAULT 'Lecture',
    room VARCHAR(50) DEFAULT 'TBA',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
mysqli_query($conn, "DELETE FROM teacher WHERE is_deleted=1 AND deleted_at < NOW() - INTERVAL 30 DAY");
// ========================================================
// ⚙️ 4. HEAD SETTINGS & SECURITY LOGIC (Unified Form)
// ========================================================
if(isset($_GET['updated']) && $_GET['updated'] == 'settings'){
    $message = "Settings Updated Successfully!";
    $msg_type = "success";
    echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
}

if(isset($_POST['save_all_settings'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['h_name']));
    $email = isset($_POST['h_email']) ? mysqli_real_escape_string($conn, trim($_POST['h_email'])) : $head_info['email']; 
    $public_email = mysqli_real_escape_string($conn, trim($_POST['h_public_email'])); 
    $app_pass = mysqli_real_escape_string($conn, trim($_POST['h_app_password'])); 
    $phone = mysqli_real_escape_string($conn, trim($_POST['h_phone']));
    $username = mysqli_real_escape_string($conn, trim($_POST['h_username']));
    
    $two_factor = isset($_POST['two_factor']) ? 1 : 0;
    $login_alerts = isset($_POST['login_alerts']) ? 1 : 0;
    $profile_locked = isset($_POST['profile_locked']) ? 1 : 0;
    
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];

    $verify = mysqli_query($conn, "SELECT id FROM head WHERE id=$head_id AND password='$current_pass'");
    if(mysqli_num_rows($verify) > 0){
        if(isset($_FILES['profile_pic']['name']) && !empty($_FILES['profile_pic']['name'])){
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $file_name = "head_" . $head_id . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], "../uploads/" . $file_name);
            mysqli_query($conn, "UPDATE head SET profile_pic='$file_name' WHERE id=$head_id");
        }
        
        $pass_query = !empty($new_pass) ? "password='$new_pass'," : "";
        $sql = "UPDATE head SET name='$name', email='$email', public_email='$public_email', app_password='$app_pass', phone='$phone', username='$username', $pass_query two_factor_enabled=$two_factor, login_alerts=$login_alerts, profile_locked=$profile_locked WHERE id=$head_id";
                
        if(mysqli_query($conn, $sql)){
            mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Profile Update', 'Updated profile & security settings')");
            $_SESSION['username'] = $username; 
            header("Location: dashboard.php?updated=settings"); 
            exit();
        } else { 
            $message = "Database Error: " . mysqli_error($conn); $msg_type = "error"; 
            echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
        }
    } else { 
        $message = "Save Failed: Incorrect Current Password!"; $msg_type = "error"; 
        echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
    }
}

// ========================================================
// 👨‍🏫 5. MANAGE TEACHERS LOGIC (CRUD + TRASH)
// ========================================================
if(isset($_POST['add_teacher'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['t_name']));
    $email = mysqli_real_escape_string($conn, trim($_POST['t_email']));
    $username = mysqli_real_escape_string($conn, trim($_POST['t_username']));
    $password = $_POST['t_password'];

    $check = mysqli_query($conn, "SELECT id FROM teacher WHERE username='$username' OR email='$email'");
    if(mysqli_num_rows($check) > 0){ $message = "Username/Email already exists!"; $msg_type = "error"; } 
    else { 
        mysqli_query($conn, "INSERT INTO teacher (dept_id, name, email, username, password, status, is_deleted) VALUES ($dept_id, '$name', '$email', '$username', '$password', 'active', 0)"); 
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Added Teacher', 'Registered Tr. $name')");
        $message = "Teacher Registered Successfully!"; 
    }
}
if(isset($_POST['toggle_teacher'])){
    $id = intval($_POST['teacher_id']); 
    mysqli_query($conn, "UPDATE teacher SET status = IF(status='active', 'inactive', 'active') WHERE id=$id AND dept_id=$dept_id"); 
    mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Toggled Status', 'Changed teacher account status')");
    $message = "Teacher Status Updated!";
}
if(isset($_POST['soft_delete_teacher'])){
    $t_id = intval($_POST['teacher_id']); $password = $_POST['head_password']; 
    $verify = mysqli_query($conn, "SELECT id FROM head WHERE id=$head_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){ 
        mysqli_query($conn, "UPDATE teacher SET is_deleted=1, deleted_at=NOW() WHERE id=$t_id AND dept_id=$dept_id"); 
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Deleted Teacher', 'Moved teacher to trash')");
        $message = "Teacher moved to Trash!"; 
    } else { $message = "Authentication Failed!"; $msg_type = "error"; }
}
if(isset($_POST['restore_teacher'])){
    $t_id = intval($_POST['teacher_id']); mysqli_query($conn, "UPDATE teacher SET is_deleted=0, deleted_at=NULL WHERE id=$t_id AND dept_id=$dept_id"); 
    $message = "Teacher Restored Successfully!";
}
// ========================================================
// 📚 MANAGE COURSES & ASSIGNMENTS LOGIC
// ========================================================
// Database fix for courses
addColHead($conn, 'course', 'is_deleted', 'TINYINT(1) DEFAULT 0');

if(isset($_POST['add_course'])){
    $c_name = mysqli_real_escape_string($conn, trim($_POST['course_name']));
    $c_code = mysqli_real_escape_string($conn, trim($_POST['course_code']));
    $check = mysqli_query($conn, "SELECT id FROM course WHERE course_code='$c_code' AND dept_id=$dept_id AND is_deleted=0");
    if(mysqli_num_rows($check) > 0){ $message = "Course Code already exists!"; $msg_type = "error"; }
    else {
        mysqli_query($conn, "INSERT INTO course (dept_id, course_name, course_code) VALUES ($dept_id, '$c_name', '$c_code')");
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Added Course', 'Created course $c_code')");
        $message = "Course Created Successfully!";
    }
}
if(isset($_POST['delete_course'])){
    $c_id = intval($_POST['course_id']);
    mysqli_query($conn, "UPDATE course SET is_deleted=1 WHERE id=$c_id AND dept_id=$dept_id");
    mysqli_query($conn, "DELETE FROM teacher_course WHERE course_id=$c_id"); // Remove assignments
    $message = "Course Deleted!";
}
if(isset($_POST['assign_course'])){
    $t_id = intval($_POST['teacher_id']); $c_id = intval($_POST['course_id']);
    $check = mysqli_query($conn, "SELECT id FROM teacher_course WHERE teacher_id=$t_id AND course_id=$c_id");
    if(mysqli_num_rows($check) > 0){ $message = "Teacher is already assigned to this course!"; $msg_type = "error"; }
    else {
        mysqli_query($conn, "INSERT INTO teacher_course (teacher_id, course_id) VALUES ($t_id, $c_id)");
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Assigned Course', 'Assigned course to a teacher')");
        $message = "Course Assigned Successfully!";
    }
}
if(isset($_POST['unassign_course'])){
    $tc_id = intval($_POST['tc_id']);
    mysqli_query($conn, "DELETE FROM teacher_course WHERE id=$tc_id");
    $message = "Course Unassigned!";
}
// ========================================================
// 📅 MANAGE CLASS SCHEDULE LOGIC
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS class_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_id INT NOT NULL,
    university_name VARCHAR(150) DEFAULT 'Bule Hora University',
    study_year VARCHAR(50) DEFAULT '3rd Year',
    semester VARCHAR(50) DEFAULT 'Semester II',
    day_of_week VARCHAR(20) NOT NULL,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    time_slot VARCHAR(50) NOT NULL,
    class_type VARCHAR(50) DEFAULT 'Lecture',
    room VARCHAR(50) DEFAULT 'TBA',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

addColHead($conn, 'class_schedule', 'university_name', "VARCHAR(150) DEFAULT 'Bule Hora University'");
addColHead($conn, 'class_schedule', 'study_year', "VARCHAR(50) DEFAULT '3rd Year'");
addColHead($conn, 'class_schedule', 'semester', "VARCHAR(50) DEFAULT 'Semester II'");

if(isset($_POST['add_schedule'])){
    $sch_id = isset($_POST['edit_schedule_id']) ? intval($_POST['edit_schedule_id']) : 0;
    
    $uni_name = mysqli_real_escape_string($conn, trim($_POST['university_name']));
    $year = mysqli_real_escape_string($conn, $_POST['study_year']);
    $sem = mysqli_real_escape_string($conn, $_POST['semester']);
    $day = mysqli_real_escape_string($conn, $_POST['day_of_week']);
    $course_id = intval($_POST['course_id']);
    $teacher_id = intval($_POST['teacher_id']);
    $time_slot = mysqli_real_escape_string($conn, trim($_POST['time_slot']));
    $class_type = mysqli_real_escape_string($conn, trim($_POST['class_type']));
    $room = mysqli_real_escape_string($conn, trim($_POST['room']));

    if($sch_id > 0) {
        mysqli_query($conn, "UPDATE class_schedule SET university_name='$uni_name', study_year='$year', semester='$sem', day_of_week='$day', course_id=$course_id, teacher_id=$teacher_id, time_slot='$time_slot', class_type='$class_type', room='$room' WHERE id=$sch_id AND dept_id=$dept_id");
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Updated Schedule', 'Updated $class_type class on $day')");
        $message = "Class Schedule Updated Successfully!";
    } else {
        mysqli_query($conn, "INSERT INTO class_schedule (dept_id, university_name, study_year, semester, day_of_week, course_id, teacher_id, time_slot, class_type, room) VALUES ($dept_id, '$uni_name', '$year', '$sem', '$day', $course_id, $teacher_id, '$time_slot', '$class_type', '$room')");
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Added Schedule', 'Added $class_type class on $day')");
        $message = "Class Scheduled Successfully!";
    }
}
if(isset($_POST['delete_schedule'])){
    $sch_id = intval($_POST['schedule_id']);
    mysqli_query($conn, "DELETE FROM class_schedule WHERE id=$sch_id AND dept_id=$dept_id");
    $message = "Schedule Entry Removed!";
}
// 🎓 STUDENT REGISTRATION & APPROVAL LOGIC
require_once '../includes/PHPMailer/src/Exception.php';
require_once '../includes/PHPMailer/src/PHPMailer.php';
require_once '../includes/PHPMailer/src/SMTP.php';

// Head Manual Student Add
if(isset($_POST['add_student'])){
    $fname = mysqli_real_escape_string($conn, trim($_POST['s_fname']));
    $lname = mysqli_real_escape_string($conn, trim($_POST['s_lname']));
    $id_num = mysqli_real_escape_string($conn, trim($_POST['s_id_num']));
    $email = mysqli_real_escape_string($conn, trim($_POST['s_email']));
    $phone = mysqli_real_escape_string($conn, trim($_POST['s_phone']));
    
    $check = mysqli_query($conn, "SELECT id FROM student WHERE email='$email' OR id_number='$id_num'");
    if(mysqli_num_rows($check) > 0){ $message = "Email or ID Number already exists!"; $msg_type = "error"; }
    else {
        // Generate Username & Password
        $base_user = strtolower($fname); $username = $base_user; $c = 1;
        while(mysqli_num_rows(mysqli_query($conn, "SELECT id FROM student WHERE username='$username'")) > 0){ $username = $base_user . $c; $c++; }
        $raw_pass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$'), 0, 8);
        $full_name = $fname . ' ' . $lname;

        mysqli_query($conn, "INSERT INTO student (dept_id, name, first_name, last_name, id_number, email, phone, username, password, status, is_deleted) VALUES ($dept_id, '$full_name', '$fname', '$lname', '$id_num', '$email', '$phone', '$username', '$raw_pass', 'accepted', 0)");
        
        // Email Credentials
        if(!empty($head_info['public_email']) && !empty($head_info['app_password'])) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP(); $mail->Host='smtp.gmail.com'; $mail->SMTPAuth=true; $mail->SMTPSecure='tls'; $mail->Port=587;
                $mail->Username = $head_info['public_email']; $mail->Password = $head_info['app_password'];
                $mail->setFrom($head_info['public_email'], 'EPLMS Dept Head'); $mail->addAddress($email);
                $mail->isHTML(true); $mail->Subject = 'EPLMS - Your Student Account Details';
                $mail->Body = "<div style='font-family:Arial;max-width:500px;margin:auto;border:1px solid #ddd;border-radius:10px;overflow:hidden;'><div style='background:#8b5cf6;padding:20px;text-align:center;color:#fff;'><h2>Welcome to EPLMS</h2></div><div style='padding:25px;'><p>Hello <b>$fname</b>,</p><p>Your department registration has been successfully created!</p><div style='background:#f3f4f6;padding:15px;border-radius:8px;margin:20px 0;'><p><b>Username:</b> <span style='color:#8b5cf6;'>$username</span></p><p><b>Password:</b> <span style='color:#8b5cf6;'>$raw_pass</span></p></div><p style='font-size:12px;color:#666;'>Please login to your student dashboard to access your courses.</p></div></div>";
                $mail->send();
            } catch (Exception $e) {}
        }
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Added Student', 'Registered $full_name')");
        $message = "Student Added & Credentials Sent!";
    }
}

// Student Approval Logic
if(isset($_POST['approve_student'])){
    $s_id = intval($_POST['student_id']);
    $stu_q = mysqli_query($conn, "SELECT * FROM student WHERE id=$s_id");
    if($stu = mysqli_fetch_assoc($stu_q)){
        $fname = $stu['first_name']; $email = $stu['email'];
        
        $base_user = strtolower($fname); $username = $base_user; $c = 1;
        while(mysqli_num_rows(mysqli_query($conn, "SELECT id FROM student WHERE username='$username' AND id!=$s_id")) > 0){ $username = $base_user . $c; $c++; }
        $raw_pass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$'), 0, 8);
        
        mysqli_query($conn, "UPDATE student SET status='accepted', username='$username', password='$raw_pass' WHERE id=$s_id AND dept_id=$dept_id");
        
        if(!empty($head_info['public_email']) && !empty($head_info['app_password'])) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP(); $mail->Host='smtp.gmail.com'; $mail->SMTPAuth=true; $mail->SMTPSecure='tls'; $mail->Port=587;
                $mail->Username = $head_info['public_email']; $mail->Password = $head_info['app_password'];
                $mail->setFrom($head_info['public_email'], 'EPLMS Dept Head'); $mail->addAddress($email);
                $mail->isHTML(true); $mail->Subject = 'EPLMS - Account Approved!';
                $mail->Body = "<div style='font-family:Arial;max-width:500px;margin:auto;border:1px solid #ddd;border-radius:10px;overflow:hidden;'><div style='background:#10b981;padding:20px;text-align:center;color:#fff;'><h2>Registration Approved!</h2></div><div style='padding:25px;'><p>Hello <b>$fname</b>,</p><p>Your registration has been approved by your Head of Department.</p><div style='background:#f3f4f6;padding:15px;border-radius:8px;margin:20px 0;'><p><b>Username:</b> <span style='color:#10b981;'>$username</span></p><p><b>Password:</b> <span style='color:#10b981;'>$raw_pass</span></p></div><p style='font-size:12px;color:#666;'>Login and change your password immediately.</p></div></div>";
                $mail->send();
            } catch (Exception $e) {}
        }
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Approved Student', 'Approved student ID: {$stu['id_number']}')");
        $message = "Student Approved & Credentials Emailed!";
    }
}
if(isset($_POST['block_student'])){
    $s_id = intval($_POST['student_id']);
    mysqli_query($conn, "UPDATE student SET status='blocked' WHERE id=$s_id AND dept_id=$dept_id");
    $message = "Student Blocked!";
}
// Edit Student Information
if(isset($_POST['edit_student'])){
    $s_id = intval($_POST['edit_s_id']);
    $fname = mysqli_real_escape_string($conn, trim($_POST['edit_s_fname']));
    $lname = mysqli_real_escape_string($conn, trim($_POST['edit_s_lname']));
    $id_num = mysqli_real_escape_string($conn, trim($_POST['edit_s_id_num']));
    $email = mysqli_real_escape_string($conn, trim($_POST['edit_s_email']));
    $phone = mysqli_real_escape_string($conn, trim($_POST['edit_s_phone']));
    $full_name = $fname . ' ' . $lname;

    mysqli_query($conn, "UPDATE student SET name='$full_name', first_name='$fname', last_name='$lname', id_number='$id_num', email='$email', phone='$phone' WHERE id=$s_id AND dept_id=$dept_id");
    mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Updated Student', 'Updated info for $fname')");
    $message = "Student Info Updated Successfully!";
}

// Delete (Trash) Student
if(isset($_POST['soft_delete_student'])){
    $s_id = intval($_POST['student_id']); $password = $_POST['head_password'];
    $verify = mysqli_query($conn, "SELECT id FROM head WHERE id=$head_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){
        mysqli_query($conn, "UPDATE student SET is_deleted=1, deleted_at=NOW() WHERE id=$s_id AND dept_id=$dept_id");
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Deleted Student', 'Moved student to trash')");
        $message = "Student moved to Trash!";
    } else { $message = "Authentication Failed!"; $msg_type = "error"; }
}
// Update Registration Status
if(isset($_POST['toggle_registration'])) {
    $current_reg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT registration_open FROM departments WHERE id=$dept_id"))['registration_open'];
    $new_status = $current_reg == 1 ? 0 : 1;
    mysqli_query($conn, "UPDATE departments SET registration_open=$new_status WHERE id=$dept_id");
    $message = $new_status == 1 ? "Student Registration OPENED!" : "Student Registration CLOSED!";
}
// ========================================================
// 🛡️ CYBER SECURITY ACTIONS (DEPT SCOPE)
// ========================================================
if(isset($_POST['unban_ip'])){
    $ip_id = intval($_POST['ip_id']);
    mysqli_query($conn, "DELETE FROM blocked_ips WHERE id=$ip_id");
    mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Security Action', 'Unbanned an IP address for dept user access')");
    $message = "IP Address Unbanned Successfully!";
}
// ========================================================
// 🌟 MANAGE STUDENT GRADES & EDIT REQUESTS
// ========================================================
if(isset($_POST['approve_edit_grade'])) {
    $g_id = intval($_POST['head_grade_id']);
    $password = $_POST['head_auth_password'];
    
    $verify = mysqli_query($conn, "SELECT id FROM head WHERE id=$head_id AND password='$password'");
    if(mysqli_num_rows($verify) > 0){
        $att = floatval($_POST['h_att']); $ass = floatval($_POST['h_ass']); $proj = floatval($_POST['h_proj']);
        $quiz = floatval($_POST['h_quiz']); $mid = floatval($_POST['h_mid']); $fin = floatval($_POST['h_fin']);
        $total = $att + $ass + $proj + $quiz + $mid + $fin;
        
        $letter = 'F';
        if($total >= 90) $letter = 'A+'; elseif($total >= 85) $letter = 'A'; elseif($total >= 80) $letter = 'A-';
        elseif($total >= 75) $letter = 'B+'; elseif($total >= 70) $letter = 'B'; elseif($total >= 65) $letter = 'B-';
        elseif($total >= 60) $letter = 'C+'; elseif($total >= 50) $letter = 'C'; elseif($total >= 40) $letter = 'D';

        mysqli_query($conn, "UPDATE student_grades SET attendance=$att, assignment=$ass, project=$proj, quiz=$quiz, mid_exam=$mid, final_exam=$fin, total_score=$total, grade_letter='$letter', edit_requested=0 WHERE id=$g_id");
        mysqli_query($conn, "INSERT INTO head_activities (head_id, action_type, details) VALUES ($head_id, 'Resolved Grade Edit', 'Adjusted grades for a student upon teacher request')");
        
        $message = "Student Grade Edited and Resolved Successfully!"; $msg_type = "success";
    } else {
        $message = "Authentication Failed: Incorrect HoD Password!"; $msg_type = "error";
    }
    echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('student_grades'));</script>";
}
// ========================================================
// 📊 7. FETCH LIVE DASHBOARD DATA (SCOPED TO DEPT)
// ========================================================
$teachers_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM teacher WHERE dept_id=$dept_id AND is_deleted=0"))['total'];
$students_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM student WHERE dept_id=$dept_id AND status='accepted'"))['total'];
$pending_students = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM student WHERE dept_id=$dept_id AND status='pending'"))['total'];
$courses_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM course WHERE dept_id=$dept_id AND is_deleted=0"))['total'];
$trash_teacher_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM teacher WHERE dept_id=$dept_id AND is_deleted=1"))['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1400"><title>EPLMS - Department Head Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* ======================================================== */
    /* 🎨 PREMIUM SYSTEM STYLES (100% Merged & Optimized)       */
    /* ======================================================== */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    
    :root { 
        --bg-color: #090b0f; --panel-bg: #14161c; --border-color: rgba(255,255,255,0.08); 
        --text-main: #f1f5f9; --text-muted: #94a3b8;
        --primary: #8b5cf6; --primary-hover: #7c3aed; --primary-glow: rgba(139, 92, 246, 0.25);
        --danger: #f43f5e; --success: #10b981; --warning: #f59e0b; --input-bg: rgba(0,0,0,0.2);
    }
    body.light-mode {
        --bg-color: #f4f7fb; --panel-bg: #ffffff; --border-color: #e2e8f0; 
        --text-main: #1e293b; --text-muted: #64748b;
        --primary: #7c3aed; --primary-hover: #6d28d9; --primary-glow: rgba(124, 58, 237, 0.2);
        --danger: #e11d48; --success: #059669; --warning: #d97706; --input-bg: #f8fafc;
    }
    body { background: var(--bg-color); color: var(--text-main); display: flex; height: 100vh; overflow-x: auto; overflow-y: hidden; transition: 0.3s; }
    .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; overflow-x: auto; min-width: 1000px; width: 100%; scroll-behavior: smooth; }
    /* SIDEBAR */
    .sidebar { position: relative; width: 280px; background: var(--panel-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; transition: width 0.3s ease; z-index: 100; box-shadow: 2px 0 15px rgba(0,0,0,0.02); }
    .sidebar.collapsed { width: 80px; }
    .sidebar.collapsed .sidebar-header h2, .sidebar.collapsed .nav-links span, .sidebar.collapsed .logout-btn span, .sidebar.collapsed .sidebar-section-title { display: none; }
    .sidebar.collapsed .sidebar-header { justify-content: center; padding: 25px 0; }
    .sidebar.collapsed .nav-links button { justify-content: center; padding: 14px 0; }
    .sidebar.collapsed .nav-links i.icon { margin: 0; font-size: 22px; }
    .sidebar.collapsed .logout-btn { padding: 12px 0; margin: 15px 10px; }
    
    .sidebar-header { padding: 25px 20px; font-size: 20px; font-weight: 800; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; margin-bottom: 20px;}
    .sidebar-header i { color: var(--primary); font-size: 24px; text-shadow: 0 0 10px var(--primary-glow); }
    .sidebar-section-title { padding: 0 20px 15px; color: var(--primary); font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 10px; }
    
    .nav-links { list-style: none; padding-top: 10px; overflow-y: auto; flex: 1; }
    .nav-links::-webkit-scrollbar { width: 4px; }
    .nav-links::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
    .nav-links button { width: 100%; display: flex; align-items: center; gap: 15px; background: transparent; border: none; color: var(--text-muted); font-size: 14.5px; font-weight: 600; padding: 15px 25px; cursor: pointer; transition: all 0.3s ease; position: relative;}
    .nav-links button:hover, .nav-links button.active { background: var(--primary-glow); color: var(--primary); border-right: 4px solid var(--primary); }
    .nav-links i.icon { width: 20px; font-size: 16px; text-align: center; }
    .logout-btn { background: rgba(244, 63, 94, 0.1); color: var(--danger); margin: 15px 20px; padding: 12px; text-align: center; border-radius: 10px; text-decoration: none; font-weight: 700; display: block; border: 1px solid rgba(244, 63, 94, 0.2); transition: 0.3s; }
    .logout-btn:hover { background: var(--danger); color: #fff; box-shadow: 0 5px 15px rgba(244, 63, 94, 0.3); }

    /* MAIN CONTENT */
    .top-header { background: var(--panel-bg); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 9999; min-height: 75px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
    .welcome-section { display: flex; align-items: center; gap: 15px; flex-shrink: 0;}
    .sa-avatar { width: 45px; height: 45px; min-width: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.1); flex-shrink: 0;}
    .welcome-text { white-space: nowrap; }
    .theme-toggle { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 18px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; flex-shrink: 0; transition: 0.3s; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
    .theme-toggle:hover { border-color: var(--primary); color: var(--primary); }

    .content-area { padding: 30px; max-width: 100%; overflow-x: hidden; box-sizing: border-box; }
    .section-tab { display: none; animation: fadeIn 0.4s ease; }
    .section-tab.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

    /* PANELS & FORMS (PREMIUM ENHANCED) */
    .grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; align-items: start; }
    
    .panel, .premium-panel { 
        background: var(--panel-bg); border-radius: 20px; border: 1px solid var(--border-color); 
        padding: 30px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); 
        transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; overflow: hidden;
    }
    body:not(.light-mode) .panel, body:not(.light-mode) .premium-panel { background: linear-gradient(145deg, #14161c, #0e1015); box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
    .panel:hover, .premium-panel:hover { box-shadow: 0 15px 40px rgba(0,0,0,0.08); transform: translateY(-3px); }
    body:not(.light-mode) .panel:hover, body:not(.light-mode) .premium-panel:hover { box-shadow: 0 15px 50px rgba(0,0,0,0.4); }

    .premium-panel { border-top: 4px solid var(--primary); }
    .premium-panel::after { content: ''; position: absolute; top:-50px; right:-50px; width:150px; height:150px; background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%); border-radius:50%; pointer-events: none; }

    .panel-title, .panel-title-premium { font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 12px; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; color: var(--text-main); }
    
    /* INPUTS & ICONS */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: 700; font-size: 12.5px; margin-bottom: 8px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .input-with-icon { position: relative; width: 100%; }
    .input-with-icon i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted); transition: 0.3s; font-size: 16px; z-index: 2; }
    .input-with-icon input, .input-with-icon select { 
        width: 100%; padding: 15px 15px 15px 50px !important; border: 1.5px solid var(--border-color); 
        border-radius: 12px; background: var(--input-bg); color: var(--text-main); 
        font-size: 14.5px; font-weight: 500; outline: none; transition: all 0.3s ease; position: relative; z-index: 1;
    }
    .input-with-icon input:focus, .input-with-icon select:focus { border-color: var(--primary); background: transparent; box-shadow: 0 0 0 4px var(--primary-glow); }
    .input-with-icon input:focus + i { color: var(--primary); }

    /* BUTTONS */
    .btn { padding: 12px 20px; background: var(--primary); color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 15px var(--primary-glow);}
    .btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px var(--primary-glow); }
    .glow-btn { background: linear-gradient(135deg, var(--primary) 0%, #6d28d9 100%); color: #fff; padding: 16px 35px; border-radius: 30px; font-size: 15px; font-weight: 800; border: none; cursor: pointer; box-shadow: 0 10px 25px var(--primary-glow); transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
    .glow-btn:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 15px 35px var(--primary-glow); }
    
    .btn-danger { background: rgba(244, 63, 94, 0.1); color: var(--danger); border: 1px solid rgba(244, 63, 94, 0.3); box-shadow: none; }
    .btn-danger:hover { background: var(--danger); color: #fff; box-shadow: 0 5px 15px rgba(244, 63, 94, 0.3); }
    .btn-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); box-shadow: none; }
    .btn-warning:hover { background: var(--warning); color: #fff; box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3); }
    .btn-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); box-shadow: none; }
    .btn-success:hover { background: var(--success); color: #fff; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }

    /* TABLES & BADGES */
    table { width: 100%; border-collapse: separate; border-spacing: 0; }
    th { color: var(--text-muted); font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 1px; padding: 15px; border-bottom: 2px solid var(--border-color); text-align: left; }
    td { padding: 18px 15px; border-bottom: 1px solid var(--border-color); vertical-align: middle; font-size: 14px; }
    tr:hover td { background: rgba(139, 92, 246, 0.02); }
    
    table .btn-sm { width: 36px; height: 36px; padding: 0; border-radius: 50%; display: inline-flex; justify-content: center; align-items: center; border: none; font-size: 14px; margin-left: 5px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    table .btn-sm:hover { transform: translateY(-3px) scale(1.1); }

    .badge { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-red { background: rgba(244, 63, 94, 0.1); color: var(--danger); border-color: rgba(244, 63, 94, 0.2); }
    .badge-yellow { background: rgba(245, 158, 11, 0.1); color: var(--warning); border-color: rgba(245, 158, 11, 0.2); }
    
    .main-sidebar-badge { background: var(--danger); color: #fff; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); box-shadow: 0 0 10px rgba(244, 63, 94, 0.5); animation: pulse-badge 2s infinite; }
    @keyframes pulse-badge { 0% { transform: scale(1) translateY(-50%); } 50% { transform: scale(1.1) translateY(-50%); } 100% { transform: scale(1) translateY(-50%); } }
    .chat-unread-badge { background: var(--danger); color: #fff; padding: 2px 7px; border-radius: 12px; font-size: 11px; font-weight: bold; display: none; margin-left: auto; box-shadow: 0 4px 10px rgba(244, 63, 94, 0.4); }

    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(14, 203, 129, 0.1); color: var(--success); border: 1px solid rgba(14, 203, 129, 0.3); }
    .alert-error { background: rgba(246, 70, 93, 0.1); color: var(--danger); border: 1px solid rgba(246, 70, 93, 0.3); }
    .info-alert { background: rgba(139, 92, 246, 0.05); border-left: 4px solid var(--primary); padding: 18px 20px; border-radius: 0 12px 12px 0; font-size: 13.5px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.6; }
    .info-alert strong { color: var(--text-main); font-weight: 700; display: block; margin-bottom: 5px; }

    /* MODALS */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
    .modal-overlay.active { display: flex; }
    .modal-box { background: var(--panel-bg); padding: 35px; border-radius: 24px; width: 90%; max-width: 450px; border: 1px solid var(--border-color); text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); animation: zoomIn 0.3s ease; }
    @keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }

    /* MAGIC CONTROL CENTER (HOME) */
    .welcome-banner { display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(139, 92, 246, 0.08) 100%); padding: 35px 40px; border-radius: 16px; border: 1px solid rgba(139, 92, 246, 0.2); margin-bottom: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; overflow: hidden; }
    .welcome-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(139, 92, 246, 0.05) 0%, transparent 60%); animation: rotateBg 20s linear infinite; z-index: 0; }
    @keyframes rotateBg { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .welcome-banner > div { z-index: 1; position: relative; }
    .welcome-banner h2 { font-size: 32px; margin-bottom: 8px; font-weight: 800; }
    .live-clock-container { background: rgba(0,0,0,0.4); padding: 15px 30px; border-radius: 50px; display: flex; align-items: center; gap: 15px; border: 1px solid var(--primary); backdrop-filter: blur(5px); }
    #real-time-clock { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: 3px; font-family: 'Courier New', Courier, monospace; }

    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px; margin-bottom: 30px; }
    .magic-card { position: relative; overflow: hidden; z-index: 1; padding: 25px; border-radius: 20px; border: 1px solid var(--border-color); background: var(--panel-bg); transition: all 0.4s; box-shadow: 0 8px 20px rgba(0,0,0,0.02); }
    .magic-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(139, 92, 246, 0.15); border-color: var(--primary); }
    .magic-card .bg-icon { position: absolute; right: -20px; bottom: -20px; font-size: 110px; opacity: 0.02; transform: rotate(-15deg); transition: 0.5s; z-index: -1; }
    .magic-card:hover .bg-icon { transform: rotate(0deg) scale(1.2); opacity: 0.08; color: var(--primary); }
    .icon-box { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; margin-bottom: 18px; box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .magic-card h2 { font-size: 42px; font-weight: 800; margin: 0 0 5px 0; color: var(--text-main); }
    .magic-card p { font-size: 13px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
    .pulse-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: var(--success); margin-right: 10px; animation: pulse-dot-anim 1.5s infinite; }
    @keyframes pulse-dot-anim { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(14, 203, 129, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(14, 203, 129, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(14, 203, 129, 0); } }

    /* HIERARCHY DRILL-DOWN STYLES */
    .breadcrumbs { background: var(--panel-bg); padding: 18px 25px; border-radius: 16px; margin-bottom: 25px; border: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
    .bc-item { color: var(--text-muted); cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .bc-item:hover, .bc-item.active { color: var(--primary); text-shadow: 0 0 10px var(--primary-glow); }
    .bc-separator { color: var(--border-color); font-size: 12px; }
    .oversight-view { display: none; animation: slideInRight 0.4s ease forwards; }
    .oversight-view.active { display: block; }
    @keyframes slideInRight { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    .magic-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    .magic-drill-card { background: linear-gradient(145deg, var(--panel-bg), rgba(255,255,255,0.02)); border: 1px solid var(--border-color); border-radius: 20px; padding: 25px; cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.03); }
    .magic-drill-card:hover { transform: translateY(-8px); box-shadow: 0 15px 35px rgba(16, 185, 129, 0.2); border-color: #10b981; }
    .magic-drill-card::after { content: '\f105'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; top: 50%; transform: translateY(-50%); font-size: 20px; color: var(--text-muted); transition: 0.3s; opacity: 0.5; }
    .magic-drill-card:hover::after { color: #10b981; transform: translateY(-50%) translateX(5px); opacity: 1; }
    .lvl-teacher { border-bottom: 4px solid #10b981; } .lvl-teacher:hover { border-color: #34d399; }
    .card-icon { width: 55px; height: 55px; border-radius: 16px; display: flex; justify-content: center; align-items: center; font-size: 24px; color: #fff; margin-bottom: 18px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);}

    /* TELEGRAM APP STYLES */
    .telegram-app { display: flex; height: 75vh; background: var(--panel-bg); border-radius: 20px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    .tg-sidebar { transition: width 0.3s ease; width: 400px; background: var(--input-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; }
    .tg-sidebar.collapsed { width: 0px; border: none; }
    .tg-search-bar { padding: 20px; border-bottom: 1px solid var(--border-color); }
    .tg-search-bar input { width: 100%; padding: 12px 20px; border-radius: 25px; border: 1px solid var(--border-color); background: var(--panel-bg); color: var(--text-main); outline: none; transition: 0.3s;}
    .tg-search-bar input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow);}
    .tg-folders { display: flex; overflow-x: auto; padding: 10px 15px; gap: 8px; border-bottom: 1px solid var(--border-color); }
    .tg-folders::-webkit-scrollbar { height: 0px; }
    .tg-folder { padding: 8px 15px; border-radius: 20px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); cursor: pointer; white-space: nowrap; transition: 0.3s; background: rgba(0,0,0,0.05); }
    body.light-mode .tg-folder { background: rgba(0,0,0,0.03); }
    .tg-folder.active { background: var(--primary); color: #fff; box-shadow: 0 4px 10px var(--primary-glow); }
    .tg-contacts { flex: 1; overflow-y: auto; }
    .tg-contacts::-webkit-scrollbar { width: 4px; }
    .tg-contacts::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
    .tg-contact-item { display: flex; align-items: center; gap: 15px; padding: 15px 20px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid rgba(255,255,255,0.02); }
    .tg-contact-item:hover, .tg-contact-item.active { background: rgba(139, 92, 246, 0.08); border-left: 4px solid var(--primary); }
    .tg-avatar { width: 48px; height: 48px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: 800; color: #fff; position: relative; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .tg-avatar.group { border-radius: 14px; }
    .tg-online-dot { position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; background: var(--success); border-radius: 50%; border: 3px solid var(--panel-bg); }
    .tg-info { flex: 1; overflow: hidden; }
    .tg-name { font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; justify-content: space-between; }
    .tg-role { font-size: 12.5px; color: var(--text-muted); display: block; margin-top: 4px; }
    
    .tg-chat-area { flex: 1; display: flex; flex-direction: column; background: url('https://www.transparenttextures.com/patterns/cubes.png'); }
    .tg-chat-header { padding: 15px 25px; background: var(--panel-bg); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); z-index: 10;}
    .tg-chat-title { font-size: 17px; font-weight: 800; color: var(--text-main); }
    .tg-chat-status { font-size: 12.5px; color: var(--success); font-weight: 600;}
    .tg-chat-history { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
    .tg-placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: var(--text-muted); opacity: 0.6; }
    .tg-placeholder i { font-size: 70px; margin-bottom: 20px; color: var(--border-color); }
    .tg-chat-input-area { padding: 20px 25px; background: var(--panel-bg); border-top: 1px solid var(--border-color); }
    .tg-chat-form { display: flex; gap: 15px; align-items: center; background: var(--input-bg); padding: 8px 8px 8px 25px; border-radius: 30px; border: 1px solid var(--border-color); transition: 0.3s;}
    .tg-chat-form:focus-within { border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-glow); }
    .tg-chat-form input { flex: 1; background: transparent; border: none; color: var(--text-main); font-size: 15px; outline: none; }
    .tg-chat-form button { width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), #6d28d9); border: none; color: #fff; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; font-size: 18px; box-shadow: 0 4px 10px var(--primary-glow);}
    .tg-chat-form button:hover { transform: scale(1.1); box-shadow: 0 6px 15px var(--primary-glow); }

    /* CHAT BUBBLES */
    .chat-msg-wrapper { display: flex; margin-bottom: 15px; width: 100%; position: relative; }
    .chat-right { justify-content: flex-end; margin-bottom: 15px; display:flex;}
    .chat-left { justify-content: flex-start; margin-bottom: 15px; display:flex;}
    .chat-bubble { max-width: 75%; padding: 14px 18px; border-radius: 20px; line-height: 1.5; font-size: 14.5px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);}
    .chat-right .chat-bubble { background: linear-gradient(135deg, var(--primary), #6d28d9); color: #fff; border-bottom-right-radius: 4px; }
    .chat-left .chat-bubble { background: var(--panel-bg); color: var(--text-main); border-bottom-left-radius: 4px; border: 1px solid var(--border-color); }
    .chat-meta { font-size: 10px; opacity: 0.7; margin-top: 8px; display: flex; justify-content: space-between; gap: 15px; font-weight: 600; letter-spacing: 0.5px;}
    
    /* PASSWORD VALIDATION & TOGGLE */
    .pw-group { position: relative; }
    .pw-eye { position: absolute; right: 18px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); transition: 0.3s; font-size: 18px; z-index: 10; }
    .pw-eye:hover { color: var(--primary); }
    .sec-toggle { display: flex; justify-content: space-between; align-items: center; background: var(--input-bg); padding: 18px 25px; border-radius: 14px; border: 1px solid var(--border-color); margin-bottom: 18px; transition: 0.3s;}
    .sec-toggle:hover { border-color: var(--primary); box-shadow: 0 5px 15px var(--primary-glow); }
    .switch { position: relative; display: inline-block; width: 46px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--text-muted); transition: .4s; border-radius: 34px; opacity: 0.5;}
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2);}
    input:checked + .slider { background-color: var(--primary); opacity: 1;}
    input:checked + .slider:before { transform: translateX(22px); }
    .pw-rules { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-top: 10px; display: none; border: 1px solid var(--border-color); }
    .rule-item { font-size: 12px; color: var(--danger); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
    .rule-item.valid { color: var(--success); }

    /* CONTEXT MENU */
    .chat-context-menu { display: none; position: fixed; z-index: 10000; width: 200px; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden; padding: 8px; animation: zoomIn 0.2s ease;}
    .context-item { padding: 12px 18px; font-size: 14px; font-weight: 600; color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 12px; transition: 0.2s; border-radius: 10px;}
    .context-item:hover { background: var(--input-bg); color: var(--primary); }
    .context-item.delete { color: var(--danger); margin-top: 5px; }
    .context-item.delete:hover { background: rgba(244, 63, 94, 0.1); color: var(--danger); }

    /* PROFILE & TABS UI */
    .profile-header-card { background: linear-gradient(135deg, #4c1d95 0%, #2e1065 100%); border-radius: 24px; padding: 45px 20px; text-align: center; position: relative; margin-bottom: 35px; border-bottom: 5px solid var(--primary); box-shadow: 0 15px 40px rgba(139, 92, 246, 0.2); overflow: hidden; }
    .profile-header-card::before { content: ''; position: absolute; top: -80px; right: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); border-radius: 50%; pointer-events: none; }
    .profile-avatar-large { width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 5px solid rgba(255,255,255,0.2); margin-bottom: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    
    .inner-tabs { display: flex; justify-content: center; gap: 15px; margin-bottom: 35px; background: rgba(0,0,0,0.1); padding: 8px; border-radius: 16px; display: inline-flex; border: 1px solid var(--border-color); }
    .inner-tab-btn { background: transparent; border: none; color: var(--text-muted); font-size: 14.5px; font-weight: 700; cursor: pointer; padding: 12px 25px; border-radius: 12px; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .inner-tab-btn:hover { color: var(--text-main); }
    .inner-tab-btn.active { background: var(--primary); color: #fff; box-shadow: 0 5px 15px var(--primary-glow); }
    .inner-tab-content { display: none; animation: fadeIn 0.4s; }
    .inner-tab-content.active { display: block; }
    
    /* HELP CENTER */
    .help-hero { background: linear-gradient(135deg, #4c1d95 0%, #8b5cf6 100%); border-radius: 16px; padding: 50px 30px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(139, 92, 246, 0.3); margin-bottom: 40px; border: 4px solid rgba(255,255,255,0.1); }
    .help-hero::before { content: ''; position: absolute; top: -50px; left: -50px; width: 250px; height: 250px; background: rgba(255,255,255,0.15); border-radius: 50%; filter: blur(30px); }
    .help-hero h2 { font-size: 38px; color: #ffffff; font-weight: 800; margin-bottom: 15px; text-shadow: 0 4px 15px rgba(0,0,0,0.4); }
    .help-hero p { font-size: 16px; color: #e0f2fe; max-width: 700px; margin: 0 auto 30px; line-height: 1.8; font-weight: 500; }
    .help-search-box { position: relative; max-width: 600px; margin: 0 auto; }
    .help-search-box input { width: 100%; padding: 18px 25px 18px 55px; border-radius: 30px; border: 2px solid #8b5cf6; background: #ffffff; color: #1e293b; font-size: 16px; font-weight: 600; outline: none; box-shadow: 0 8px 25px rgba(0,0,0,0.2); transition: 0.3s; }
    .help-search-box input:focus { border-color: #fcd535; transform: scale(1.03); box-shadow: 0 12px 35px rgba(252, 213, 53, 0.3); }
    .help-search-box i { position: absolute; left: 22px; top: 50%; transform: translateY(-50%); color: #8b5cf6; font-size: 20px; }
    .help-topic-section { background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 35px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
    .help-topic-title { font-size: 24px; font-weight: 800; color: var(--text-main); border-bottom: 3px solid rgba(139, 92, 246, 0.1); padding-bottom: 15px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; }
    .help-accordion-item { border: 1px solid var(--border-color); border-radius: 12px; margin-bottom: 15px; background: rgba(0,0,0,0.02); transition: 0.3s; overflow: hidden; }
    .help-accordion-item.active { border-color: var(--primary); box-shadow: 0 5px 20px rgba(139, 92, 246, 0.15); background: var(--panel-bg); }
    .help-acc-btn { width: 100%; text-align: left; background: transparent; border: none; padding: 20px 25px; font-size: 16px; font-weight: 700; color: var(--text-main); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.3s; }
    .help-acc-btn:hover { color: var(--primary); background: rgba(139, 92, 246, 0.05); }
    .help-acc-btn i { color: var(--text-muted); transition: transform 0.4s; font-size: 18px; }
    .help-accordion-item.active .help-acc-btn i { transform: rotate(180deg); color: var(--primary); }
    .help-acc-content { padding: 0 25px 25px 25px; color: var(--text-main); font-size: 14.5px; line-height: 1.8; display: none; animation: slideDownHelp 0.4s ease forwards; border-top: 1px dashed var(--border-color); margin-top: 5px; padding-top: 20px; }
    .help-pro-tip { background: rgba(16, 185, 129, 0.1); border-left: 5px solid #10b981; padding: 18px 20px; border-radius: 0 10px 10px 0; margin: 20px 0; color: var(--text-main); font-weight: 500; }
</style>
</head>
<body>

<aside class="sidebar" id="main-sidebar">
    <div class="sidebar-header" style="margin-bottom: 20px;">
        <i class="fa-solid fa-users-gear" style="color:var(--primary);"></i> <h2>DEPT BAR</h2>
    </div>
   
    <ul class="nav-links" style="padding-top: 10px;">
        <li><button class="tab-link active" onclick="openTab('home')"><i class="fa-solid fa-chart-pie icon"></i> <span>Control Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('teachers')"><i class="fa-solid fa-chalkboard-user icon"></i> <span>Manage Teachers</span> <?php if($trash_teacher_count > 0) echo "<span class='badge-noti'>$trash_teacher_count</span>"; ?></button></li>
        <li><button class="tab-link" onclick="openTab('courses')"><i class="fa-solid fa-book-open icon"></i> <span>Manage Courses</span></button></li>
        <li><button class="tab-link" onclick="openTab('schedule')"><i class="fa-regular fa-calendar-days icon" style="color: #f59e0b;"></i> <span>Manage Schedule</span></button></li>
<li><button class="tab-link" onclick="openTab('students')"><i class="fa-solid fa-user-graduate icon"></i> <span>Manage Students</span> <span class="main-sidebar-badge" id="badge_students" style="display:none; position:relative; right:0; transform:none; margin-left:auto;">0</span></button></li>        <li><button class="tab-link" onclick="openTab('student_grades')"><i class="fa-solid fa-star-half-stroke icon" style="color: #f59e0b;"></i> <span>Student Grades</span></button></li>
        <li><button class="tab-link" onclick="openTab('dept_oversight'); resetOversight();"><i class="fa-solid fa-network-wired icon" style="color: var(--primary);"></i> <span>Dept Oversight</span></button></li>

        <li><button class="tab-link" onclick="openTab('help')"><i class="fa-solid fa-circle-question icon"></i> <span>Help Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('about')"><i class="fa-solid fa-circle-info icon"></i> <span>About EPLMS</span></button></li>
               <li><button class="tab-link" onclick="openTab('broadcast')"><i class="fa-brands fa-telegram icon" style="color: #0ea5e9;"></i> <span>Communications</span> <span class="main-sidebar-badge" id="main_comm_badge" style="display:none;">0</span></button></li>
        <li><button class="tab-link" onclick="openTab('audit')"><i class="fa-solid fa-shield-halved icon" style="color: var(--danger);"></i> <span>Security Center</span></button></li>
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
            <div class="welcome-section">
                <img src="<?php echo $profile_pic; ?>" alt="Head" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); flex-shrink: 0;">
                <div class="welcome-text" style="white-space: nowrap;">
                    <h2 id="display-admin-name" style="font-size: 18px; font-weight: 700; color: var(--text-main);">Welcome, <?php echo htmlspecialchars($head_info['name']); ?></h2>
                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-users-gear" style="color:var(--primary);"></i> Head: <?php echo htmlspecialchars($head_info['dept_name']); ?></span>
                </div>
            </div>
        </div>
        <button class="theme-toggle" style="flex-shrink: 0;" onclick="toggleTheme()"><i class="fa-solid fa-moon" id="theme-icon"></i> <span>Dark Mode</span></button>
    </header>

    <div class="content-area">
        <?php if($message): ?>
            <div class="alert alert-<?php echo $msg_type; ?>"><i class="fa-solid <?php echo $msg_type=='success'?'fa-circle-check':'fa-triangle-exclamation'; ?>"></i> <?php echo $message; ?></div>
        <?php endif; ?>

        <!-- ============================================== -->
        <!-- TAB 1: MAGIC CONTROL CENTER                    -->
        <!-- ============================================== -->
        <div id="home" class="section-tab active">
            <div class="welcome-banner" style="background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(139, 92, 246, 0.08) 100%); border-color: rgba(139, 92, 246, 0.2);">
                <div>
                    <h2 id="greeting-text">
                        <?php 
                            $hour = date('H');
                            $greet = ($hour < 12) ? "Good Morning" : (($hour < 18) ? "Good Afternoon" : "Good Evening");
                            echo $greet . ", " . htmlspecialchars($head_info['name']); 
                        ?>!
                    </h2>
                    <p><i class="fa-solid fa-shield-check" style="color:var(--success);"></i> Department operations running smoothly.</p>
                </div>
                <div class="live-clock-container"><i class="fa-solid fa-clock"></i><span id="real-time-clock">00:00:00</span></div>
            </div>

            <div class="stats-grid">
                <div class="magic-card"><i class="fa-solid fa-chalkboard-user bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-chalkboard-user"></i></div><h2 class="counter" data-target="<?php echo $teachers_count; ?>">0</h2><p>Teachers</p></div>
                <div class="magic-card"><i class="fa-solid fa-user-graduate bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #ef4444, #b91c1c);"><i class="fa-solid fa-user-graduate"></i></div><h2 class="counter" data-target="<?php echo $students_count; ?>">0</h2><p>Active Students</p></div>
                <div class="magic-card"><i class="fa-solid fa-hourglass-half bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-hourglass-half"></i></div><h2 class="counter" data-target="<?php echo $pending_students; ?>">0</h2><p>Pending Approvals</p></div>
                <div class="magic-card"><i class="fa-solid fa-book-open bg-icon"></i><div class="icon-box" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-book-open"></i></div><h2 class="counter" data-target="<?php echo $courses_count; ?>">0</h2><p>Courses Created</p></div>
            </div>
            
            <div class="grid-2">
                <div class="panel" style="position: relative; overflow: hidden; box-shadow: 0 8px 20px rgba(0,0,0,0.1);">
                    <h3 class="panel-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary);"></i> Department Demographics</h3>
                    <div style="height: 250px; display:flex; justify-content:center; align-items:center; margin-top:10px;">
                        <canvas id="deptDemographicsChart"></canvas>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="panel" style="margin-bottom: 0; padding: 20px;">
                        <h3 class="panel-title" style="margin-bottom: 15px;"><i class="fa-solid fa-bolt" style="color:var(--primary);"></i> Quick Actions</h3>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button class="btn btn-sm" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.3);" onclick="openTab('teachers');"><i class="fa-solid fa-user-plus"></i> Add Teacher</button>
                            <button class="btn btn-sm" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3);" onclick="openTab('students');"><i class="fa-solid fa-list-check"></i> Approve Students</button>
                            <button class="btn btn-sm" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59,130,246,0.3);" onclick="openTab('broadcast');"><i class="fa-solid fa-paper-plane"></i> Broadcast</button>
                        </div>
                    </div>

                    <div class="panel" style="flex: 1; margin-bottom: 0; padding: 20px; display: flex; flex-direction: column;">
                        <h3 class="panel-title" style="margin-bottom: 10px;"><i class="fa-solid fa-clock-rotate-left" style="color:#f59e0b;"></i> Recent Activities</h3>
                        <div style="flex: 1; overflow-y: auto; max-height: 140px; padding-right: 5px;">
                            <?php
                            $acts = mysqli_query($conn, "SELECT * FROM head_activities WHERE head_id=$head_id ORDER BY created_at DESC LIMIT 8");
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

        <!-- ============================================== -->
        <!-- TAB 2: MANAGE TEACHERS                         -->
        <!-- ============================================== -->
        <div id="teachers" class="section-tab">
            <div class="grid-2">
                <div class="panel magic-form-panel">
                    <h3 class="panel-title"><i class="fa-solid fa-chalkboard-user"></i> Register New Teacher</h3>
                    <form method="POST">
                        <div class="form-group"><label>Full Name</label><div class="input-with-icon"><input type="text" name="t_name" required><i class="fa-solid fa-user"></i></div></div>
                        <div class="form-group"><label>Email</label><div class="input-with-icon"><input type="email" name="t_email" required><i class="fa-solid fa-envelope"></i></div></div>
                        <div class="form-group"><label>Username</label><div class="input-with-icon"><input type="text" name="t_username" required><i class="fa-solid fa-at"></i></div></div>
                        <div class="form-group"><label>Password</label><div class="input-with-icon"><input type="password" name="t_password" required><i class="fa-solid fa-key"></i></div></div>
                        <button type="submit" name="add_teacher" class="btn" style="width:100%;"><i class="fa-solid fa-plus"></i> Register Teacher</button>
                    </form>
                </div>
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-users"></i> Active Teachers in <?php echo $head_info['dept_name']; ?></h3>
                    <table>
                        <tr><th>Teacher Profile</th><th>Status</th><th>Action</th></tr>
                        <?php
                        $t_q = mysqli_query($conn, "SELECT * FROM teacher WHERE dept_id=$dept_id AND is_deleted=0 ORDER BY id DESC");
                        while($t = mysqli_fetch_assoc($t_q)){
                            $badge = $t['status'] == 'active' ? 'badge' : 'badge-red';
                            $btn_class = $t['status'] == 'active' ? 'btn-danger' : 'btn-success';
                            echo "<tr>
                                    <td><strong>{$t['name']}</strong><br><small>{$t['email']}</small></td>
                                    <td><span class='{$badge}'>".ucfirst($t['status'])."</span></td>
                                    <td>
                                        <form method='POST' style='display:inline;'><input type='hidden' name='teacher_id' value='{$t['id']}'><button type='submit' name='toggle_teacher' class='btn btn-sm {$btn_class}'>Toggle</button></form>
                                        <button type='button' class='btn btn-sm btn-danger' onclick=\"confirmDelete('teacher', {$t['id']}, '{$t['name']}')\"><i class='fa-solid fa-trash'></i></button>
                                    </td>
                                  </tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
            <!-- TRASH -->
            <div class="panel trash-panel" style="margin-top: 10px;">
                <h3 class="panel-title" style="color:var(--danger);"><i class="fa-solid fa-trash-can-arrow-up"></i> Recycle Bin (Teachers)</h3>
                <table>
                    <?php
                    $trash_t = mysqli_query($conn, "SELECT * FROM teacher WHERE dept_id=$dept_id AND is_deleted=1");
                    while($t = mysqli_fetch_assoc($trash_t)){
                        echo "<tr><td><strike>{$t['name']}</strike></td><td>{$t['deleted_at']}</td><td><form method='POST'><input type='hidden' name='teacher_id' value='{$t['id']}'><button type='submit' name='restore_teacher' class='btn btn-sm btn-success'>Restore</button></form></td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>
<!-- ============================================== -->
        <!-- TAB: MANAGE COURSES & ASSIGNMENTS              -->
        <!-- ============================================== -->
        <div id="courses" class="section-tab">
            <div class="grid-2">
                <!-- Create Course Form -->
                <div class="panel magic-form-panel">
                    <h3 class="panel-title"><i class="fa-solid fa-book-medical" style="color:var(--primary);"></i> Create New Course</h3>
                    <form method="POST">
                        <div class="form-group"><label>Course Name</label><div class="input-with-icon"><input type="text" name="course_name" required><i class="fa-solid fa-book"></i></div></div>
                        <div class="form-group"><label>Course Code</label><div class="input-with-icon"><input type="text" name="course_code" required><i class="fa-solid fa-hashtag"></i></div></div>
                        <button type="submit" name="add_course" class="btn" style="width:100%;"><i class="fa-solid fa-plus"></i> Add Course</button>
                    </form>
                </div>

                <!-- Assign Course Form -->
                <div class="panel magic-form-panel" style="border-top: 4px solid #10b981;">
                    <h3 class="panel-title"><i class="fa-solid fa-chalkboard-user" style="color:#10b981;"></i> Assign Course to Teacher</h3>
                    <div class="info-alert" style="margin-bottom: 15px; padding: 10px 15px; font-size: 12px; background: rgba(16, 185, 129, 0.05); border-left: 3px solid #10b981; color: var(--text-muted);">
                        <i class="fa-solid fa-circle-info" style="color:#10b981;"></i> A single teacher can be assigned to multiple courses.
                    </div>
                    <form method="POST">
                        <div class="form-group"><label>Select Teacher</label>
                            <div class="input-with-icon"><select name="teacher_id" required><option value="">-- Choose Teacher --</option>
                            <?php $t_list = mysqli_query($conn, "SELECT id, name FROM teacher WHERE dept_id=$dept_id AND is_deleted=0 AND status='active'"); while($tl = mysqli_fetch_assoc($t_list)) echo "<option value='{$tl['id']}'>{$tl['name']}</option>"; ?>
                            </select><i class="fa-solid fa-user-tie"></i></div>
                        </div>
                        <div class="form-group"><label>Select Course</label>
                            <div class="input-with-icon"><select name="course_id" required><option value="">-- Choose Course --</option>
                            <?php $c_list = mysqli_query($conn, "SELECT id, course_name, course_code FROM course WHERE dept_id=$dept_id AND is_deleted=0"); while($cl = mysqli_fetch_assoc($c_list)) echo "<option value='{$cl['id']}'>{$cl['course_name']} ({$cl['course_code']})</option>"; ?>
                            </select><i class="fa-solid fa-book-open"></i></div>
                        </div>
                        <button type="submit" name="assign_course" class="btn btn-success" style="width:100%;"><i class="fa-solid fa-link"></i> Assign Course</button>
                    </form>
                </div>
            </div>

            <div class="grid-2">
                <!-- Active Courses Table -->
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-list"></i> Active Courses</h3>
                    <table>
                        <tr><th>Course Details</th><th>Action</th></tr>
                        <?php
                        $courses = mysqli_query($conn, "SELECT * FROM course WHERE dept_id=$dept_id AND is_deleted=0 ORDER BY id DESC");
                        if(mysqli_num_rows($courses) > 0){
                            while($c = mysqli_fetch_assoc($courses)){
                                echo "<tr>
                                        <td><strong>{$c['course_name']}</strong><br><small style='color:var(--primary);'>{$c['course_code']}</small></td>
                                        <td><form method='POST'><input type='hidden' name='course_id' value='{$c['id']}'><button type='submit' name='delete_course' class='btn btn-sm btn-danger'><i class='fa-solid fa-trash'></i></button></form></td>
                                      </tr>";
                            }
                        } else { echo "<tr><td colspan='2' style='text-align:center;'>No courses added yet.</td></tr>"; }
                        ?>
                    </table>
                </div>

                <!-- Teacher Assignments Table -->
                <div class="panel" style="overflow-x:auto;">
                    <h3 class="panel-title"><i class="fa-solid fa-clipboard-list"></i> Teacher Assignments</h3>
                    <table>
                        <tr><th>Teacher</th><th>Assigned Course</th><th>Unassign</th></tr>
                        <?php
                        $assignments = mysqli_query($conn, "SELECT tc.id as tc_id, t.name as teacher_name, c.course_name, c.course_code FROM teacher_course tc JOIN teacher t ON tc.teacher_id = t.id JOIN course c ON tc.course_id = c.id WHERE t.dept_id=$dept_id AND c.is_deleted=0 ORDER BY t.name ASC");
                        if(mysqli_num_rows($assignments) > 0){
                            while($a = mysqli_fetch_assoc($assignments)){
                                echo "<tr>
                                        <td><strong>{$a['teacher_name']}</strong></td>
                                        <td>{$a['course_name']} <br><small style='color:var(--primary);'>{$a['course_code']}</small></td>
                                        <td><form method='POST'><input type='hidden' name='tc_id' value='{$a['tc_id']}'><button type='submit' name='unassign_course' class='btn btn-sm btn-warning' title='Unassign'><i class='fa-solid fa-unlink'></i></button></form></td>
                                      </tr>";
                            }
                        } else { echo "<tr><td colspan='3' style='text-align:center;'>No courses assigned yet.</td></tr>"; }
                        ?>
                    </table>
                </div>
            </div>
        </div>
       <!-- ============================================== -->
        <!-- TAB: MANAGE CLASS SCHEDULE                     -->
        <!-- ============================================== -->
        <div id="schedule" class="section-tab">
            <div class="grid-2">
                <!-- FORM PANEL -->
                <div class="premium-panel" style="border-top-color: #f59e0b; margin-bottom:0;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-regular fa-calendar-plus"></i></div> Add / Edit Schedule</h3>
                    <form method="POST">
                        <input type="hidden" name="edit_schedule_id" id="form_schedule_id" value="0">
                        
                        <div class="form-group"><label>University Name</label>
                            <div class="input-with-icon"><input type="text" name="university_name" id="form_uni_name" value="Bule Hora University" required><i class="fa-solid fa-building-columns"></i></div>
                        </div>

                        <div style="display:flex; gap:10px; margin-bottom:15px;">
                            <div class="form-group" style="flex:1; margin:0;"><label>Study Year</label>
                                <div class="input-with-icon"><select name="study_year" id="form_study_year" required>
                                    <option value="1st Year">1st Year</option><option value="2nd Year">2nd Year</option><option value="3rd Year" selected>3rd Year</option><option value="4th Year">4th Year</option><option value="5th Year">5th Year</option><option value="6th Year">6th Year</option><option value="7th Year">7th Year</option><option value="8th Year">8th Year</option><option value="9th Year">9th Year</option><option value="10th Year">10th Year</option>
                                </select><i class="fa-solid fa-graduation-cap"></i></div>
                            </div>
                            <div class="form-group" style="flex:1; margin:0;"><label>Semester</label>
                                <div class="input-with-icon"><select name="semester" id="form_semester" required>
                                    <option value="Semester I">Semester I</option><option value="Semester II" selected>Semester II</option><option value="Summer">Summer</option>
                                </select><i class="fa-solid fa-book-bookmark"></i></div>
                            </div>
                        </div>

                        <div class="form-group"><label>Day of the Week</label>
                            <div class="input-with-icon"><select name="day_of_week" id="form_day" required>
                                <option value="Monday">Monday</option><option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option><option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                            </select><i class="fa-solid fa-calendar-day"></i></div>
                        </div>
                        <div class="form-group"><label>Select Course</label>
                            <div class="input-with-icon"><select name="course_id" id="form_course" required><option value="">-- Choose Course --</option>
                            <?php $c_list = mysqli_query($conn, "SELECT id, course_name, course_code FROM course WHERE dept_id=$dept_id AND is_deleted=0"); while($cl = mysqli_fetch_assoc($c_list)) echo "<option value='{$cl['id']}'>{$cl['course_name']} ({$cl['course_code']})</option>"; ?>
                            </select><i class="fa-solid fa-book-open"></i></div>
                        </div>
                        <div class="form-group"><label>Select Teacher (Instructor)</label>
                            <div class="input-with-icon"><select name="teacher_id" id="form_teacher" required><option value="">-- Choose Instructor --</option>
                            <?php $t_list = mysqli_query($conn, "SELECT id, name FROM teacher WHERE dept_id=$dept_id AND is_deleted=0 AND status='active'"); while($tl = mysqli_fetch_assoc($t_list)) echo "<option value='{$tl['id']}'>{$tl['name']}</option>"; ?>
                            </select><i class="fa-solid fa-user-tie"></i></div>
                        </div>
                        
                        <div style="display:flex; gap:10px; margin-bottom:15px;">
                            <div class="form-group" style="flex:1; margin:0;"><label>Time (Local)</label><div class="input-with-icon"><input type="text" name="time_slot" id="form_time" placeholder="e.g. 2:00 - 4:00 AM" required><i class="fa-regular fa-clock"></i></div></div>
                            <div class="form-group" style="flex:1; margin:0;"><label>Class Type</label><div class="input-with-icon"><select name="class_type" id="form_type"><option value="Lecture">Lecture</option><option value="Lab">Lab</option><option value="Tutorial">Tutorial</option></select><i class="fa-solid fa-flask"></i></div></div>
                        </div>
                        <div class="form-group"><label>Room / Venue</label><div class="input-with-icon"><input type="text" name="room" id="form_room" placeholder="e.g. Seminar 2, Room 7" required><i class="fa-solid fa-door-open"></i></div></div>
                        
                        <button type="submit" name="add_schedule" id="btn_save_schedule" class="glow-btn" style="width:100%; justify-content:center; background:linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 5px 15px rgba(245, 158, 11, 0.4);"><i class="fa-solid fa-calendar-check"></i> Save Schedule</button>
                    </form>
                </div>

                <!-- OFFICIAL DOCUMENT VIEW PANEL -->
                <div class="premium-panel" style="padding: 0; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
                    <div style="padding: 40px; color: #111;">
                        
                        <?php
                        // Fetch latest metadata to update the header dynamically
                        $meta_q = mysqli_query($conn, "SELECT university_name, study_year, semester FROM class_schedule WHERE dept_id=$dept_id ORDER BY id DESC LIMIT 1");
                        $meta = mysqli_fetch_assoc($meta_q);
                        $uni_name = $meta ? $meta['university_name'] : "Bule Hora University";
                        $study_year = $meta ? $meta['study_year'] : "3rd Year";
                        $semester = $meta ? $meta['semester'] : "Semester II";
                        $current_year = date('Y');
                        ?>

                        <!-- Official Document Header -->
                        <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px;">
                            <h2 style="font-family: 'Times New Roman', Times, serif; font-size: 26px; font-weight: bold; color: #000; margin-bottom: 8px;"><?php echo htmlspecialchars($uni_name); ?></h2>
                            <h3 style="font-family: 'Times New Roman', Times, serif; font-size: 20px; font-weight: bold; color: #222; margin-bottom: 6px;"><?php echo htmlspecialchars($head_info['college_name']); ?></h3>
                            <h4 style="font-family: 'Times New Roman', Times, serif; font-size: 18px; font-weight: bold; color: #333; margin-bottom: 15px;"><?php echo htmlspecialchars($head_info['dept_name']); ?> Department</h4>
                            <h4 style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; color: #000; text-decoration: underline;">Tentative Class Schedule for <?php echo htmlspecialchars($head_info['dept_code']); ?> <?php echo $study_year; ?>, <?php echo $semester; ?>, <?php echo $current_year; ?> G.C.</h4>
                        </div>

                        <!-- Official Schedule Table -->
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; font-family: 'Times New Roman', Times, serif; font-size: 15px; color: #000; border: 2px solid #000;">
                                <tr style="background: #f0f0f0;">
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; text-align: center; font-weight: bold; font-size: 16px;">Days</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 16px;">Course Name</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 16px;">Course Code</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 16px;">Time (Local)</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 16px;">D_Type</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 16px;">Instructor</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 16px;">Venue</th>
                                    <th style="border: 1px solid #000; padding: 12px; color: #000; font-weight: bold; font-size: 14px; text-align: center; white-space:nowrap;">Action</th>
                                </tr>
                                <?php
                                $days =['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                                $schedule_exists = false;

                                foreach($days as $d){
                                    $sch_q = mysqli_query($conn, "SELECT s.*, c.course_name, c.course_code, t.name as teacher_name FROM class_schedule s JOIN course c ON s.course_id=c.id JOIN teacher t ON s.teacher_id=t.id WHERE s.dept_id=$dept_id AND s.day_of_week='$d' ORDER BY STR_TO_DATE(SUBSTRING_INDEX(s.time_slot, ' ', 1), '%l:%i') ASC");
                                    
                                    if(mysqli_num_rows($sch_q) > 0){
                                        $schedule_exists = true;
                                        $first = true;
                                        $rowspan = mysqli_num_rows($sch_q);
                                        
                                        while($sch = mysqli_fetch_assoc($sch_q)){
                                            $bg_color = ($sch['class_type'] == 'Lab') ? 'background: #d4d4d4;' : 'background: #fff;';
                                            
                                            echo "<tr style='{$bg_color}'>";
                                            
                                            if($first){
                                                echo "<td rowspan='{$rowspan}' style='border: 1px solid #000; padding: 10px; font-weight: bold; text-align: center; vertical-align: middle; font-size: 16px; background: #fff;'>{$d}</td>";
                                                $first = false;
                                            }
                                            
                                            echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['course_name']}</td>";
                                            echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['course_code']}</td>";
                                            echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['time_slot']}</td>";
                                            echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['class_type']}</td>";
                                            echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['teacher_name']}</td>";
                                            echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['room']}</td>";
                                            
                                            echo "<td style='border: 1px solid #000; padding: 5px; text-align: center; white-space: nowrap;'>
                                                    <button type='button' onclick=\"editSchedule({$sch['id']}, '{$sch['university_name']}', '{$sch['study_year']}', '{$sch['semester']}', '{$sch['day_of_week']}', {$sch['course_id']}, {$sch['teacher_id']}, '{$sch['time_slot']}', '{$sch['class_type']}', '{$sch['room']}')\" style='background:none; border:none; color:#f59e0b; cursor:pointer; font-size:16px; margin-right:8px;' title='Edit'><i class='fa-solid fa-pen-to-square'></i></button>
                                                    <form method='POST' style='display:inline; margin:0;' onsubmit=\"return confirm('Delete this schedule entry?');\">
                                                        <input type='hidden' name='schedule_id' value='{$sch['id']}'>
                                                        <button type='submit' name='delete_schedule' title='Delete' style='background:none; border:none; color:#dc2626; cursor:pointer; font-size:16px;'><i class='fa-solid fa-trash-can'></i></button>
                                                    </form>
                                                  </td>";
                                            echo "</tr>";
                                        }
                                    }
                                }

                                if(!$schedule_exists) {
                                    echo "<tr><td colspan='8' style='text-align:center; padding:30px; color:#555; font-style:italic;'>No schedule generated yet. Please add classes using the form.</td></tr>";
                                }
                                ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: STUDENT GRADES & EDIT REQUESTS            -->
        <!-- ============================================== -->
        <div id="student_grades" class="section-tab">
            <div class="premium-panel" style="border-top-color: #f59e0b; padding: 30px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
                    <div>
                        <h3 class="panel-title-premium" style="margin:0; border:none; padding:0; color:#f59e0b;">
                            <div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0 15px 0 0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-star-half-stroke"></i></div> 
                            Department Master Grades
                        </h3>
                        <p style="font-size:13px; color:var(--text-muted); margin-top:8px;">View published grades and securely resolve teacher edit requests.</p>
                    </div>
                </div>

                <div style="border-radius:16px; border:1px solid var(--border-color); background:var(--panel-bg); overflow:hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.02);">
                    <table style="width:100%; border-collapse:collapse; text-align:left;">
                        <tr style="background:rgba(245,158,11,0.05); color:#f59e0b; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                            <th style="padding:18px 25px; border-bottom:2px solid var(--border-color);">Student Profile</th>
                            <th style="padding:18px 20px; border-bottom:2px solid var(--border-color);">Course Details</th>
                            <th style="padding:18px 20px; text-align:center; border-bottom:2px solid var(--border-color);">Total</th>
                            <th style="padding:18px 20px; text-align:center; border-bottom:2px solid var(--border-color);">Grade</th>
                            <th style="padding:18px 25px; text-align:center; border-bottom:2px solid var(--border-color);">Action Status</th>
                        </tr>
                        <?php
                        $chk_tb = mysqli_query($conn, "SHOW TABLES LIKE 'student_grades'");
                        if(mysqli_num_rows($chk_tb) > 0) {
                            // Fetch grades with student profile pics
                            $all_g_q = mysqli_query($conn, "SELECT g.*, s.first_name, s.last_name, s.id_number, s.profile_pic, s.profile_locked, c.course_code, t.name as teacher_name FROM student_grades g JOIN student s ON g.student_id=s.id JOIN course c ON g.course_id=c.id JOIN teacher t ON g.teacher_id=t.id WHERE s.dept_id=$dept_id AND g.is_published=1 ORDER BY g.edit_requested DESC, s.first_name ASC");
                            
                            if(mysqli_num_rows($all_g_q) > 0) {
                                while($g = mysqli_fetch_assoc($all_g_q)) {
                                    $is_req = $g['edit_requested'] == 1;
                                    $highlight = $is_req ? "background:rgba(244,63,94,0.03);" : "";
                                    $border_left = $is_req ? "border-left: 4px solid #f43f5e;" : "border-left: 4px solid transparent;";
                                    
                                    // 🪄 Avatar Logic
                                    $s_pic = ($g['profile_locked'] == 0 && $g['profile_pic'] != 'default_student.png') ? "../uploads/".$g['profile_pic'] : "";
                                    $s_initial = strtoupper(substr($g['first_name'], 0, 1));
                                    $avatar = $s_pic ? "<img src='$s_pic' style='width:40px;height:40px;border-radius:50%;object-fit:cover; border:2px solid var(--border-color);'>" : "<div style='width:40px;height:40px;border-radius:50%;background:rgba(245,158,11,0.1);color:#f59e0b;display:flex;justify-content:center;align-items:center;font-weight:bold;border:1px solid rgba(245,158,11,0.2);'>{$s_initial}</div>";

                                    // 🪄 Grade Color Logic
                                    $l_color = '#f43f5e';
                                    if(in_array($g['grade_letter'],['A+','A','A-'])) $l_color = '#10b981';
                                    elseif(in_array($g['grade_letter'],['B+','B','B-'])) $l_color = '#3b82f6';
                                    elseif(in_array($g['grade_letter'], ['C+','C'])) $l_color = '#f59e0b';

                                    // Action Button Logic (Centered, Shorter Text)
                                    $btn = $is_req 
                                        ? "<button class='glow-btn' style='padding:8px 18px; font-size:12.5px; background:linear-gradient(135deg, #f43f5e, #be123c); box-shadow:0 4px 15px rgba(244,63,94,0.3);' onclick=\"openEditGradeModal({$g['id']}, '".addslashes($g['first_name'])." ".addslashes($g['last_name'])."', {$g['attendance']}, {$g['assignment']}, {$g['project']}, {$g['quiz']}, {$g['mid_exam']}, {$g['final_exam']})\"><i class='fa-solid fa-pen-to-square'></i> Resolve Edit</button>"
                                        : "<span class='badge' style='background:rgba(255,255,255,0.05); color:var(--text-muted); border:1px solid var(--border-color); font-size:11px;'><i class='fa-solid fa-lock'></i> Finalized</span>";

                                    echo "<tr style='border-bottom:1px solid var(--border-color); transition:0.3s; {$highlight} {$border_left}' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='".($is_req?'rgba(244,63,94,0.03)':'transparent')."'\">
                                            <td style='padding:15px 25px;'>
                                                <div style='display:flex; align-items:center; gap:12px;'>
                                                    {$avatar}
                                                    <div><strong style='color:var(--text-main); font-size:14px;'>{$g['first_name']} {$g['last_name']}</strong><br><span style='font-family:monospace; color:var(--text-muted); font-size:11.5px;'>{$g['id_number']}</span></div>
                                                </div>
                                            </td>
                                            <td style='padding:15px 20px;'>
                                                <strong style='color:var(--text-main); font-size:13.5px;'><i class='fa-solid fa-book-bookmark' style='color:#f59e0b; margin-right:5px;'></i> {$g['course_code']}</strong><br>
                                                <span style='color:var(--text-muted); font-size:12px;'><i class='fa-solid fa-user-tie' style='margin-right:5px;'></i> Tr. {$g['teacher_name']}</span>
                                            </td>
                                            <td style='padding:15px 20px; text-align:center;'>
                                                <strong style='color:#10b981; font-size:16px;'>{$g['total_score']}</strong>
                                            </td>
                                            <td style='padding:15px 20px; text-align:center;'>
                                                <strong style='color:{$l_color}; font-size:20px; text-shadow: 0 0 10px rgba(0,0,0,0.1);'>{$g['grade_letter']}</strong>
                                            </td>
                                            <td style='padding:15px 25px; text-align:center;'>
                                                {$btn}
                                            </td>
                                          </tr>";
                                }
                            } else { echo "<tr><td colspan='5' style='text-align:center; padding:50px; color:var(--text-muted);'><i class='fa-solid fa-folder-open' style='font-size:40px; opacity:0.3; margin-bottom:15px; display:block;'></i> No published grades yet.</td></tr>"; }
                        } else { echo "<tr><td colspan='5' style='text-align:center; padding:50px; color:var(--text-muted);'>No grades submitted yet.</td></tr>"; }
                        ?>
                    </table>
                </div>
            </div>
        </div>
      <!-- ============================================== -->
        <!-- TAB 3: MANAGE STUDENTS                         -->
        <!-- ============================================== -->
        <div id="students" class="section-tab">
            <div class="grid-2">
                <!-- Manual Add Panel -->
                <div class="premium-panel" style="border-top-color: var(--primary); margin-bottom:0; padding:25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-user-plus"></i></div> Manually Add Student</h3>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:20px;">System will auto-generate username & password and email them.</p>
                    <form method="POST">
                        <div style="display:flex; gap:15px; margin-bottom:15px;">
                            <div class="input-with-icon" style="flex:1;"><input type="text" name="s_fname" placeholder="First Name" required><i class="fa-solid fa-user"></i></div>
                            <div class="input-with-icon" style="flex:1;"><input type="text" name="s_lname" placeholder="Last Name" required><i class="fa-solid fa-user"></i></div>
                        </div>
                        <div class="input-with-icon" style="margin-bottom:15px;"><input type="text" name="s_id_num" placeholder="ID Number" required><i class="fa-solid fa-id-card"></i></div>
                        <div class="input-with-icon" style="margin-bottom:15px;"><input type="email" name="s_email" placeholder="Email Address" required><i class="fa-solid fa-envelope"></i></div>
                        <div class="input-with-icon" style="margin-bottom:20px;"><input type="text" name="s_phone" placeholder="Phone Number" required><i class="fa-solid fa-phone"></i></div>
                        <button type="submit" name="add_student" class="glow-btn" style="width:100%; justify-content:center;"><i class="fa-solid fa-paper-plane"></i> Add & Send Credentials</button>
                    </form>
                </div>

                <!-- Right Column: Registration Status & Pending -->
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- Registration Status Panel -->
                    <?php $reg_open = mysqli_fetch_assoc(mysqli_query($conn, "SELECT registration_open FROM departments WHERE id=$dept_id"))['registration_open']; ?>
                    <div class="premium-panel" style="margin-bottom:0; padding: 20px 25px; border-top-color: <?php echo $reg_open ? '#10b981' : 'var(--danger)'; ?>; background: linear-gradient(145deg, var(--panel-bg), <?php echo $reg_open ? 'rgba(16, 185, 129, 0.05)' : 'rgba(246, 70, 93, 0.05)'; ?>);">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <h3 style="font-size: 16px; margin-bottom:5px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-globe" style="color:var(--text-muted);"></i> Public Registration: 
                                    <?php echo $reg_open ? '<span style="color:#10b981; text-shadow: 0 0 10px rgba(16,185,129,0.5);">OPEN</span>' : '<span style="color:var(--danger); text-shadow: 0 0 10px rgba(246,70,93,0.5);">CLOSED</span>'; ?>
                                </h3>
                                <p style="font-size:12px; color:var(--text-muted);">Dept Code for students: <strong style="color:var(--primary); font-size:14px; background:rgba(139, 92, 246, 0.1); padding:2px 8px; border-radius:6px; letter-spacing:1px;"><?php echo htmlspecialchars($head_info['dept_code']); ?></strong></p>
                            </div>
                            <form method="POST">
                                <button type="submit" name="toggle_registration" class="glow-btn" style="padding:10px 15px; font-size:13px; background: <?php echo $reg_open ? 'linear-gradient(135deg, #f6465d, #be123c)' : 'linear-gradient(135deg, #10b981, #047857)'; ?>; box-shadow: 0 5px 15px <?php echo $reg_open ? 'rgba(246,70,93,0.3)' : 'rgba(16,185,129,0.3)'; ?>;">
                                    <?php echo $reg_open ? '<i class="fa-solid fa-lock"></i> Close' : '<i class="fa-solid fa-lock-open"></i> Open'; ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Pending Approvals Panel -->
                    <div class="premium-panel" style="flex:1; margin-bottom:0; padding: 25px; border-top-color: #f59e0b; overflow-y:auto; max-height:350px;">
                        <h3 class="panel-title-premium" style="margin-bottom: 15px; border:none; padding:0;"><div class="icon-box" style="width:30px; height:30px; font-size:14px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-hourglass-half"></i></div> Pending Approvals</h3>
                        <table style="width: 100%;">
                            <tr style="border-bottom: 1px solid var(--border-color);"><th style="padding:10px 0; color:var(--text-muted); font-size:11px;">STUDENT INFO</th><th style="padding:10px 0; color:var(--text-muted); font-size:11px;">ID / PHONE</th><th style="padding:10px 0; color:var(--text-muted); font-size:11px; text-align:right;">ACTION</th></tr>
                            <?php
                            $p_studs = mysqli_query($conn, "SELECT * FROM student WHERE dept_id=$dept_id AND status='pending' ORDER BY registered_at DESC");
                            if(mysqli_num_rows($p_studs)>0){
                                while($s = mysqli_fetch_assoc($p_studs)){
                                    echo "<tr style='border-bottom: 1px dashed rgba(255,255,255,0.05);'>
                                            <td style='padding:15px 0;'>
                                                <div style='display:flex; align-items:center; gap:10px;'>
                                                    <div style='width:35px; height:35px; border-radius:50%; background:rgba(245,158,11,0.1); color:#f59e0b; display:flex; align-items:center; justify-content:center; font-weight:bold;'>".strtoupper(substr($s['first_name'],0,1))."</div>
                                                    <div><strong style='color:var(--text-main); font-size:13px;'>{$s['first_name']} {$s['last_name']}</strong><br><small style='color:var(--text-muted); font-size:11px;'>{$s['email']}</small></div>
                                                </div>
                                            </td>
                                            <td style='padding:15px 0;'><strong style='color:var(--primary); font-family:monospace; font-size:13px;'>{$s['id_number']}</strong><br><small style='color:var(--text-muted); font-size:11px;'>{$s['phone']}</small></td>
                                          <td style='padding:15px 0; text-align:right;'>
    <button type='button' id='btn_approve_{$s['id']}' class='btn btn-sm' style='background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.3); margin-right:5px;' title='Approve & Email' onclick='approveStudentAjax({$s['id']})'><i class='fa-solid fa-check'></i></button>
    <form method='POST' style='display:inline;'><input type='hidden' name='student_id' value='{$s['id']}'><button type='submit' name='block_student' class='btn btn-sm' style='background:rgba(246,70,93,0.1); color:#f6465d; border:1px solid rgba(246,70,93,0.3);' title='Block'><i class='fa-solid fa-ban'></i></button></form>
</td>
                                          </tr>";
                                }
                            } else { echo "<tr><td colspan='3' style='text-align:center; padding:30px 0; color:var(--success);'><i class='fa-solid fa-clipboard-check' style='font-size:30px; margin-bottom:10px; display:block;'></i> All caught up! No pending approvals.</td></tr>"; }
                            ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Enrolled Students Directory -->
            <div class="premium-panel" style="margin-top:20px; border-top-color: #3b82f6; padding: 25px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 15px;">
                    <h3 class="panel-title-premium" style="margin:0; border:none; padding:0;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-users-viewfinder"></i></div> Enrolled Students Directory</h3>
                    
                    <!-- Search Box for Enrolled Students -->
                    <div class="input-with-icon" style="width: 250px;">
                        <input type="text" id="student_search" placeholder="Search students..." onkeyup="filterStudents()" style="padding: 8px 12px 8px 35px; font-size: 13px; background: rgba(0,0,0,0.2);">
                        <i class="fa-solid fa-magnifying-glass" style="font-size: 13px;"></i>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table id="enrolled_students_table" style="width: 100%;">
                        <tr style="background: rgba(0,0,0,0.2);"><th style="border-radius:8px 0 0 8px;">Name</th><th>ID Number</th><th>Email / Phone</th><th>Username</th><th>Status</th><th style="border-radius:0 8px 8px 0; text-align:right;">Action</th></tr>
                        <?php
                        $a_studs = mysqli_query($conn, "SELECT * FROM student WHERE dept_id=$dept_id AND status!='pending' AND is_deleted=0 ORDER BY first_name ASC");
                        if(mysqli_num_rows($a_studs)>0){
                            while($s = mysqli_fetch_assoc($a_studs)){
                                $badge = $s['status'] == 'accepted' ? "background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.3);" : "background:rgba(246,70,93,0.1); color:#f6465d; border:1px solid rgba(246,70,93,0.3);";
                                $action_btn = $s['status'] == 'accepted' 
                                    ? "<form method='POST' style='display:inline;'><input type='hidden' name='student_id' value='{$s['id']}'><button type='submit' name='block_student' class='btn btn-sm' style='background:rgba(246,70,93,0.1); color:#f6465d;' title='Suspend Account'><i class='fa-solid fa-ban'></i></button></form>"
                                    : "<form method='POST' style='display:inline;'><input type='hidden' name='student_id' value='{$s['id']}'><button type='submit' name='approve_student' class='btn btn-sm' style='background:rgba(16,185,129,0.1); color:#10b981;' title='Re-activate Account'><i class='fa-solid fa-check'></i></button></form>";
                                
                                echo "<tr class='student-row' style='border-bottom: 1px solid rgba(255,255,255,0.02); transition:0.3s;' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                        <td style='padding:15px;'><strong style='color:var(--text-main); font-size:14px;'>{$s['first_name']} {$s['last_name']}</strong></td>
                                        <td style='padding:15px; font-family:monospace; color:var(--text-muted);'>{$s['id_number']}</td>
                                        <td style='padding:15px; font-size:13px;'><span style='color:var(--text-main);'>{$s['email']}</span><br><span style='color:var(--text-muted);'>{$s['phone']}</span></td>
                                        <td style='padding:15px; color:var(--primary); font-weight:700; font-family:monospace;'>@{$s['username']}</td>
                                        <td style='padding:15px;'><span style='padding:4px 10px; border-radius:20px; font-size:11px; font-weight:bold; {$badge}'>".strtoupper($s['status'])."</span></td>
                                        <td style='padding:15px; text-align:right;'>
                                            {$action_btn}
                                            <button type='button' class='btn btn-sm' style='background:rgba(245,158,11,0.1); color:#f59e0b; margin-left:5px;' onclick=\"editStudent('{$s['id']}','{$s['first_name']}','{$s['last_name']}','{$s['id_number']}','{$s['email']}','{$s['phone']}')\"><i class='fa-solid fa-pen'></i></button>
                                            <button type='button' class='btn btn-sm' style='background:rgba(246,70,93,0.1); color:#f6465d; margin-left:5px;' onclick=\"confirmDelete('student', {$s['id']}, '{$s['first_name']} {$s['last_name']}')\"><i class='fa-solid fa-trash'></i></button>
                                        </td>
                                      </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' style='text-align:center; padding:20px;'>No enrolled students found.</td></tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
        </div>
      <!-- ============================================== -->
        <!-- TAB 4: DEPT OVERSIGHT (Premium Drill-down SPA) -->
        <!-- ============================================== -->
        <div id="dept_oversight" class="section-tab">
            
            <!-- Premium Banner for Oversight -->
            <div class="premium-panel" style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); border-top-color: #8b5cf6; padding: 40px 30px; text-align: center; margin-bottom: 25px;">
                <div class="icon-box" style="width: 70px; height: 70px; font-size: 30px; margin: 0 auto 15px auto; background: linear-gradient(135deg, #8b5cf6, #6d28d9); box-shadow: 0 10px 25px rgba(139, 92, 246, 0.4); border-radius: 20px;">
                    <i class="fa-solid fa-eye"></i>
                </div>
                <h2 style="font-size: 28px; font-weight: 800; color: #fff; margin-bottom: 10px; letter-spacing: 0.5px;">360° Department Oversight</h2>
                <p style="color: #cbd5e1; font-size: 14.5px; max-width: 600px; margin: 0 auto; line-height: 1.6;">
                    Deep dive into your department's analytics. Click on any teacher to view their assigned courses and monitor the enrolled student directory under their oversight.
                </p>
            </div>

            <div class="breadcrumbs" id="oversight-breadcrumbs" style="background: linear-gradient(145deg, var(--panel-bg), rgba(139, 92, 246, 0.05)); border: 1px solid rgba(139, 92, 246, 0.2);">
                <span class="bc-item active" onclick="navToLevel('lvl1', 'All Teachers', this, true)"><i class="fa-solid fa-chalkboard-user"></i> All Teachers</span>
            </div>

            <!-- LEVEL 1: TEACHERS -->
            <div id="view-lvl1" class="oversight-view active">
                <div class="magic-grid">
                    <?php
                    $all_techs = mysqli_query($conn, "SELECT * FROM teacher WHERE dept_id=$dept_id AND is_deleted=0");
                    if(mysqli_num_rows($all_techs) > 0){
                        while($tech = mysqli_fetch_assoc($all_techs)){
                            $t_id = $tech['id'];
                            // How many courses does this teacher have?
                            $c_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM teacher_course WHERE teacher_id=$t_id"))['c'];
                            
                            $t_pic = ($tech['profile_locked'] == 0 && $tech['profile_pic'] != 'default_teacher.png') ? "../uploads/".$tech['profile_pic'] : "";
                            $t_initial = strtoupper(substr($tech['name'],0,1));
                            $t_avatar = $t_pic ? "<img src='$t_pic' style='width:100%;height:100%;border-radius:12px;object-fit:cover;'>" : $t_initial;

                            echo "<div class='magic-drill-card lvl-teacher' onclick=\"navToLevel('lvl2_tech_{$t_id}', 'Tr. {$tech['name']}')\" style='border-top: 4px solid #10b981; padding: 30px 25px;'>
                                    <div style='display:flex; align-items:center; gap:15px; margin-bottom: 20px;'>
                                        <div class='card-icon' style='background:linear-gradient(135deg, #10b981, #047857); margin:0; width:60px; height:60px; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3); font-weight:bold; overflow:hidden;'>
                                            {$t_avatar}
                                        </div>
                                        <div>
                                            <h3 style='font-size:18px; font-weight:800; color:var(--text-main); margin-bottom:4px;'>{$tech['name']}</h3>
                                            <p style='font-size:13px; color:var(--text-muted); margin:0;'><i class='fa-solid fa-envelope' style='color:#10b981;'></i> {$tech['email']}</p>
                                        </div>
                                    </div>
                                    <div style='background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 12px 15px; border-radius: 10px; display:flex; justify-content:space-between; align-items:center;'>
                                        <span style='font-size:13px; font-weight:600; color:var(--text-main);'>Assigned Courses</span>
                                        <span class='badge' style='background:#10b981; color:#fff;'>{$c_count} Courses</span>
                                    </div>
                                  </div>";
                        }
                    } else { echo "<div class='info-alert warning' style='grid-column: 1/-1;'><i class='fa-solid fa-triangle-exclamation'></i> No teachers registered in this department yet.</div>"; }
                    ?>
                </div>
            </div>

            <!-- LEVEL 2: TEACHER DETAILS (Courses + Students) -->
            <?php
            mysqli_data_seek($all_techs, 0); 
            while($tech = mysqli_fetch_assoc($all_techs)){
                $t_id = $tech['id'];
                echo "<div id='view-lvl2_tech_{$t_id}' class='oversight-view'>";
                
                // Teacher Header Profile inside Level 2
                $t_pic = ($tech['profile_locked'] == 0 && $tech['profile_pic'] != 'default_teacher.png') ? "../uploads/".$tech['profile_pic'] : "";
                $t_initial = strtoupper(substr($tech['name'],0,1));
                
                echo "
                <div class='premium-panel' style='padding: 25px; display:flex; align-items:center; gap:20px; border-top-color:#10b981; background:linear-gradient(145deg, var(--panel-bg), rgba(16, 185, 129, 0.03));'>
                    <div style='width: 80px; height: 80px; border-radius: 50%; background: #10b981; color:#fff; display:flex; justify-content:center; align-items:center; font-size:30px; font-weight:bold; border:4px solid rgba(16,185,129,0.2); box-shadow: 0 10px 25px rgba(16,185,129,0.3); overflow:hidden;'>
                        ".($t_pic ? "<img src='$t_pic' style='width:100%;height:100%;object-fit:cover;'>" : $t_initial)."
                    </div>
                    <div>
                        <h2 style='font-size: 24px; font-weight: 800; margin-bottom: 5px; color:var(--text-main);'>{$tech['name']}</h2>
                        <span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; font-size:12px;'><i class='fa-solid fa-chalkboard-user'></i> Department Faculty</span>
                        <span style='margin-left: 10px; font-size:13px; color:var(--text-muted);'><i class='fa-solid fa-envelope'></i> {$tech['email']}</span>
                    </div>
                </div>
                
                <div class='grid-2'>
                    <!-- Assigned Courses for this teacher -->
                    <div class='panel'>
                        <h3 class='panel-title' style='color:#3b82f6;'><i class='fa-solid fa-book-open'></i> Assigned Courses</h3>
                        <div style='display:flex; flex-direction:column; gap:10px;'>";
                        
                        $tc_q = mysqli_query($conn, "SELECT c.* FROM teacher_course tc JOIN course c ON tc.course_id = c.id WHERE tc.teacher_id=$t_id AND c.is_deleted=0");
                        if(mysqli_num_rows($tc_q)>0){
                            while($tc = mysqli_fetch_assoc($tc_q)){
                                echo "<div style='padding:15px; border:1px solid rgba(59,130,246,0.2); background:rgba(59,130,246,0.05); border-radius:12px; display:flex; align-items:center; gap:15px; transition:0.3s;' onmouseover=\"this.style.transform='translateX(5px)'\" onmouseout=\"this.style.transform='translateX(0)'\">
                                        <div style='width:45px; height:45px; border-radius:10px; background:#3b82f6; color:#fff; display:flex; justify-content:center; align-items:center; font-size:18px; box-shadow:0 4px 10px rgba(59,130,246,0.3);'><i class='fa-solid fa-book'></i></div>
                                        <div><strong style='color:var(--text-main); font-size:15px;'>{$tc['course_name']}</strong><br><span style='color:#3b82f6; font-size:12px; font-family:monospace; font-weight:bold; background:rgba(59,130,246,0.1); padding:2px 6px; border-radius:4px;'>{$tc['course_code']}</span></div>
                                      </div>";
                            }
                        } else {
                            echo "<div class='info-alert' style='margin:0; background:rgba(255,255,255,0.05); border-left-color:var(--border-color);'><i class='fa-solid fa-circle-info'></i> No courses assigned yet.</div>";
                        }

                echo "  </div>
                    </div>

                    <!-- Department Students -->
                    <div class='panel' style='padding:0; overflow:hidden;'>
                        <div style='padding:20px 25px; border-bottom:1px solid var(--border-color); background:linear-gradient(to right, rgba(244,63,94,0.05), transparent);'>
                            <h3 style='margin:0; font-size:16px; font-weight:800; color:#f43f5e; display:flex; align-items:center; gap:10px;'><i class='fa-solid fa-users'></i> Enrolled Students in Dept</h3>
                        </div>
                        <div style='max-height:400px; overflow-y:auto;'>";
                        
                        $studs = mysqli_query($conn, "SELECT * FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 ORDER BY first_name ASC");
                        if(mysqli_num_rows($studs)>0){
                            while($s = mysqli_fetch_assoc($studs)){
                                $s_pic = ($s['profile_locked'] == 0 && $s['profile_pic'] != 'default_student.png') ? "../uploads/".$s['profile_pic'] : "";
                                $s_initial = strtoupper(substr($s['first_name'], 0, 1));
                                
                                echo "<div style='display:flex; align-items:center; gap:15px; padding:15px 25px; border-bottom:1px solid rgba(255,255,255,0.02); transition:0.3s;' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                        <div style='width:45px; height:45px; border-radius:50%; background:rgba(244,63,94,0.1); color:#f43f5e; display:flex; justify-content:center; align-items:center; font-weight:bold; border:2px solid rgba(244,63,94,0.2); overflow:hidden;'>
                                            ".($s_pic ? "<img src='$s_pic' style='width:100%;height:100%;object-fit:cover;'>" : $s_initial)."
                                        </div>
                                        <div><h4 style='font-size:14.5px; font-weight:700; color:var(--text-main); margin-bottom:3px;'>{$s['first_name']} {$s['last_name']}</h4><span style='font-size:12px; color:var(--text-muted); font-family:monospace;'><i class='fa-solid fa-id-card' style='color:var(--text-muted);'></i> {$s['id_number']}</span></div>
                                        <div style='margin-left:auto;'><span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; border-color:rgba(16,185,129,0.2);'>Active</span></div>
                                      </div>";
                            }
                        } else { echo "<div style='padding:40px; text-align:center; color:var(--text-muted);'><i class='fa-solid fa-user-slash' style='font-size:40px; margin-bottom:15px; opacity:0.3;'></i><br>No active students enrolled.</div>"; }
                        
                echo "  </div>
                    </div>
                </div>
                
                </div>"; // End of Level 2 View
            }
            ?>
        </div>

      <!-- ============================================== -->
        <!-- TAB 5: COMMUNICATIONS (Premium Telegram Style) -->
        <!-- ============================================== -->
        <div id="broadcast" class="section-tab">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin: 0;"><i class="fa-brands fa-telegram" style="color: #3b82f6;"></i> Security Communications Hub</h3>
                <span style="font-size:12.5px; color:var(--text-muted); background:var(--input-bg); padding:8px 15px; border-radius:20px; border:1px solid var(--border-color); font-weight:600;"><i class="fa-solid fa-lock" style="color:var(--success);"></i> End-to-End Encrypted</span>
            </div>
            
            <div class="telegram-app">
                <div class="tg-sidebar">
                    <div class="tg-search-bar" style="padding: 15px 20px;">
                        <div class="input-with-icon" style="margin:0;">
                            <input type="text" id="tg-search" placeholder="Search users or groups..." onkeyup="filterTelegramChats()" style="padding:12px 15px 12px 45px !important; border-radius:20px; font-size:13.5px; border-color:transparent; background:rgba(0,0,0,0.2);">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                    </div>
                    <div class="tg-folders">
                        <div class="tg-folder active" onclick="switchFolder('all')">All Chats</div>
                        <div class="tg-folder" onclick="switchFolder('admin')">Admins</div>
                        <div class="tg-folder" onclick="switchFolder('teacher')">Teachers</div>
                        <div class="tg-folder" onclick="switchFolder('student')">Students</div>
                    </div>
                    <div class="tg-contacts" id="tg-contacts-list">
                        
                        <!-- 📢 GROUPS (Broadcasts) -->
                        <div class="tg-contact-item chat-item-all chat-item-teacher" onclick="openTelegramChat(0, 'teacher', 1, '📢 All Teachers', 'Broadcast to my Teachers', '#10b981', '')">
                            <div class="tg-avatar group" style="background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 4px 10px rgba(16,185,129,0.3);"><i class="fa-solid fa-bullhorn"></i></div>
                            <div class="tg-info"><span class="tg-name">📢 All Teachers</span><span class="tg-role">Department Faculty</span></div>
                        </div>
                        <div class="tg-contact-item chat-item-all chat-item-student" onclick="openTelegramChat(0, 'student', 1, '📢 All Students', 'Broadcast to my Students', '#f43f5e', '')">
                            <div class="tg-avatar group" style="background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 0 4px 10px rgba(244,63,94,0.3);"><i class="fa-solid fa-bullhorn"></i></div>
                            <div class="tg-info"><span class="tg-name">📢 All Students</span><span class="tg-role">Enrolled Students</span></div>
                        </div>

                        <!-- 👑 HIGHER UPS & CONTACTS (WITH ADVANCED AVATARS) -->
                        <?php
                        function getAvatar($pic, $name, $bg, $color, $locked) {
                            if($locked == 1) return['type'=>'locked', 'html'=>"<i class='fa-solid fa-user-lock' style='font-size:18px; color:#fff;'></i>", 'url'=>'LOCKED'];
                            
                            $url = (!empty($pic) && file_exists("../uploads/".$pic)) ? "../uploads/".$pic : "https://ui-avatars.com/api/?name=".urlencode($name)."&background=$bg&color=$color&bold=true";
                            return['type'=>'img', 'html'=>"<img src='$url' style='width:100%;height:100%;border-radius:50%;object-fit:cover;'>", 'url'=>$url];
                        }

                        // 1. Super Admin
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

                        // 2. College Admin (Boss)
                        $a_list = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM admin WHERE college_id=$college_id AND is_deleted=0 LIMIT 1");
                        if($a = mysqli_fetch_assoc($a_list)){
                            $av = getAvatar($a['profile_pic'], $a['name'], 'f59e0b', 'fff', $a['profile_locked']);
                            $extra_badge = $av['type'] == 'img' ? "<i class='fa-solid fa-circle-check' style='position:absolute; bottom:-2px; right:-2px; color:#34d399; background:#fff; border-radius:50%; font-size:12px; z-index:5;'></i>" : "";
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-admin' id='contact_admin_{$a['id']}' onclick=\"openTelegramChat({$a['id']}, 'admin', 0, '".addslashes($a['name'])."', 'College Admin', '#f59e0b', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#f59e0b;' id='avatar_admin_{$a['id']}'>{$av['html']}{$extra_badge}</div>
                                    <div class='tg-info'><span class='tg-name'>{$a['name']} <i class='fa-solid fa-circle-check' style='color:#34d399; font-size:12px;' title='Verified Admin'></i></span><span class='tg-role'>College Admin</span></div>
                                    <span class='chat-unread-badge' id='badge_admin_{$a['id']}'>0</span>
                                  </div>";
                        }
                        
                        // 3. Private Teachers
                        $t_list = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM teacher WHERE dept_id=$dept_id AND is_deleted=0");
                        while($t = mysqli_fetch_assoc($t_list)){
                            $av = getAvatar($t['profile_pic'], $t['name'], '10b981', 'fff', $t['profile_locked']);
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-teacher' id='contact_teacher_{$t['id']}' onclick=\"openTelegramChat({$t['id']}, 'teacher', 0, '".addslashes($t['name'])."', 'Teacher', '#10b981', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#10b981;' id='avatar_teacher_{$t['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>{$t['name']}</span><span class='tg-role' style='color:#10b981;'>Teacher</span></div>
                                    <span class='chat-unread-badge' id='badge_teacher_{$t['id']}'>0</span>
                                  </div>";
                        }

                        // 4. Private Students
                        $s_list = mysqli_query($conn, "SELECT id, first_name, last_name, profile_pic, profile_locked FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0");
                        while($s = mysqli_fetch_assoc($s_list)){
                            $full_name = $s['first_name'] . ' ' . $s['last_name'];
                            $av = getAvatar($s['profile_pic'], $full_name, 'f43f5e', 'fff', $s['profile_locked']);
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-student' id='contact_student_{$s['id']}' onclick=\"openTelegramChat({$s['id']}, 'student', 0, '".addslashes($full_name)."', 'Student', '#f43f5e', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#f43f5e;' id='avatar_student_{$s['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>{$full_name}</span><span class='tg-role' style='color:#f43f5e;'>Student</span></div>
                                    <span class='chat-unread-badge' id='badge_student_{$s['id']}'>0</span>
                                  </div>";
                        }
                        ?>
                    </div>
                </div>
                
                <div class="tg-chat-area">
                    <div id="tg-placeholder" class="tg-placeholder">
                        <div style="width:120px; height:120px; background:var(--input-bg); border-radius:50%; display:flex; justify-content:center; align-items:center; margin-bottom:20px; border:2px dashed var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                            <i class="fa-regular fa-comments" style="font-size: 50px; color:var(--text-muted); margin:0;"></i>
                        </div>
                        <h3 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:5px;">EPLMS Secure Messaging</h3>
                        <p style="font-size:14px; color:var(--text-muted);">Select a chat from the sidebar to start messaging.</p>
                    </div>
                    
                    <div id="tg-active-chat" style="display:none; flex-direction:column; height:100%;">
                        <div class="tg-chat-header">
                            <div class="tg-avatar group" id="chat-header-avatar" style="background:#8b5cf6; box-shadow: 0 4px 10px rgba(0,0,0,0.1);"></div>
                            <div>
                                <div class="tg-chat-title" id="chat-header-name">Chat Name</div>
                                <div class="tg-chat-status" id="chat-header-role">Online</div>
                            </div>
                            <div style="margin-left:auto; display:flex; gap:10px;">
                                <button type="button" class="btn btn-sm" style="background:var(--input-bg); color:var(--text-muted); border:1px solid var(--border-color);"><i class="fa-solid fa-magnifying-glass"></i></button>
                                <button type="button" class="btn btn-sm" style="background:var(--input-bg); color:var(--text-muted); border:1px solid var(--border-color);"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            </div>
                        </div>
                        
                        <div class="tg-chat-history" id="chat-history-container"></div>
                        
                        <div class="tg-chat-input-area">
                            <form id="tg-chat-form" onsubmit="submitTelegramMsg(event)" class="tg-chat-form">
                                <input type="hidden" name="chat_receiver_id" id="chat_receiver_id">
                                <input type="hidden" name="chat_receiver_role" id="chat_receiver_role">
                                <input type="hidden" name="chat_is_group" id="chat_is_group">
                                <input type="hidden" name="edit_msg_id" id="edit_msg_id">
                                <i class="fa-solid fa-paperclip" style="color:var(--text-muted); font-size:18px; cursor:pointer; padding:0 10px; transition:0.3s;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'"></i>
                                <input type="text" name="chat_message" id="chat_message_input" placeholder="Write a secure message..." required autocomplete="off">
                                <button type="submit" id="btn_send_msg"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<!-- ============================================== -->
        <!-- TAB 6: DEPT SECURITY COMMAND CENTER            -->
        <!-- ============================================== -->
        <div id="audit" class="section-tab">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <div>
                    <h3 style="font-size: 24px; font-weight: 800; color: var(--danger); margin: 0; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-shield-halved"></i> Cyber Security Command Center</h3>
                    <p style="color: var(--text-muted); font-size: 13.5px; margin-top: 5px;">Monitor active threats, auto-banned IPs, and authentication health for your department.</p>
                </div>
                <span style="background: rgba(244, 63, 94, 0.1); color: var(--danger); padding: 8px 15px; border-radius: 20px; font-weight: 700; font-size: 12px; border: 1px solid rgba(244, 63, 94, 0.2); box-shadow: 0 0 10px rgba(244, 63, 94, 0.3); animation: pulse-badge 2s infinite;"><i class="fa-solid fa-radar"></i> Active Monitoring</span>
            </div>

            <!-- 1. ACCOUNTS SECURITY HEALTH -->
            <div class="premium-panel" style="border-top-color: var(--warning); padding: 25px;">
                <h3 class="panel-title-premium" style="border-bottom-color:rgba(245, 158, 11, 0.2);"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-users-viewfinder"></i></div> Department Accounts Health</h3>
                <div style="overflow-x:auto;">
                    <table style="width: 100%;">
                        <tr style="background: rgba(0,0,0,0.2);"><th style="border-radius:8px 0 0 8px;">Account Profile</th><th>Role</th><th>Last Known IP</th><th>Last Login</th><th style="border-radius:0 8px 8px 0;">Threat Level (24h)</th></tr>
                        <?php
                        $health_q = "
                            SELECT 'Teacher' as role, t.name as full_name, t.username, 
                            (SELECT ip_address FROM login_logs WHERE username=t.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_ip,
                            (SELECT attempt_time FROM login_logs WHERE username=t.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_login,
                            (SELECT COUNT(*) FROM login_logs WHERE username=t.username AND status='failed' AND attempt_time > NOW() - INTERVAL 1 DAY) as recent_fails
                            FROM teacher t WHERE t.dept_id=$dept_id AND t.is_deleted=0
                            UNION
                            SELECT 'Student' as role, CONCAT(s.first_name, ' ', s.last_name) as full_name, s.username, 
                            (SELECT ip_address FROM login_logs WHERE username=s.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_ip,
                            (SELECT attempt_time FROM login_logs WHERE username=s.username AND status='success' ORDER BY attempt_time DESC LIMIT 1) as last_login,
                            (SELECT COUNT(*) FROM login_logs WHERE username=s.username AND status='failed' AND attempt_time > NOW() - INTERVAL 1 DAY) as recent_fails
                            FROM student s WHERE s.dept_id=$dept_id AND s.status='accepted' AND s.is_deleted=0
                            ORDER BY recent_fails DESC LIMIT 5
                        ";
                        $health_res = mysqli_query($conn, $health_q);
                        if(mysqli_num_rows($health_res) > 0) {
                            while($sec = mysqli_fetch_assoc($health_res)){
                                $last_login = $sec['last_login'] ? date("d M Y, h:i A", strtotime($sec['last_login'])) : "<span style='color:var(--text-muted); font-size:12px;'>Never logged in</span>";
                                $last_ip = $sec['last_ip'] ? $sec['last_ip'] : "<span style='color:var(--text-muted); font-size:12px;'>Unknown</span>";
                                $fails = $sec['recent_fails'];
                                
                                if($fails == 0) { $threat_badge = "<span class='badge' style='background:rgba(16, 185, 129, 0.1); color:var(--success); border-color:rgba(16, 185, 129, 0.3);'><i class='fa-solid fa-shield-check'></i> Secure (0)</span>"; }
                                elseif($fails < 3) { $threat_badge = "<span class='badge badge-yellow'><i class='fa-solid fa-triangle-exclamation'></i> Low Risk ($fails)</span>"; }
                                else { $threat_badge = "<span class='badge badge-red'><i class='fa-solid fa-skull-crossbones'></i> HIGH RISK ($fails)</span>"; }
                                
                                $role_badge = $sec['role'] == 'Teacher' ? "<span class='badge' style='background:rgba(16, 185, 129, 0.1); color:#10b981; border:none;'>Teacher</span>" : "<span class='badge' style='background:rgba(244, 63, 94, 0.1); color:#f43f5e; border:none;'>Student</span>";
                                
                                echo "<tr style='border-bottom: 1px solid rgba(255,255,255,0.02); transition:0.3s;' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                        <td style='padding:15px;'><strong style='color:var(--text-main); font-size:14px;'>{$sec['full_name']}</strong><br><small style='color:var(--primary); font-family:monospace; font-weight:700;'>@{$sec['username']}</small></td>
                                        <td style='padding:15px;'>{$role_badge}</td>
                                        <td style='padding:15px; font-family:monospace; color:var(--text-muted); font-size:13px;'>{$last_ip}</td>
                                        <td style='padding:15px; font-size:13px; color:var(--text-muted);'>{$last_login}</td>
                                        <td style='padding:15px;'>{$threat_badge}</td>
                                      </tr>";
                            }
                        } else { echo "<tr><td colspan='5' style='text-align:center; padding:20px; color:var(--text-muted);'>No active accounts to monitor.</td></tr>"; }
                        ?>
                    </table>
                </div>
            </div>

            <div class="grid-2">
                <!-- 2. AUTO-BANNED IPS -->
                <div class="premium-panel" style="border-top-color: var(--danger); padding: 25px;">
                    <h3 class="panel-title-premium" style="color: var(--danger); border-bottom-color:rgba(244, 63, 94, 0.2);"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-ban"></i></div> Auto-Banned IPs</h3>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:15px;">IPs blocked by the system for failing 3+ login attempts on your department's accounts.</p>
                    <div style="overflow-x:auto;">
                        <table style="width:100%;">
                            <tr style="background: rgba(0,0,0,0.2);"><th style="border-radius:8px 0 0 8px;">IP Address</th><th>Reason</th><th style="border-radius:0 8px 8px 0; text-align:right;">Action</th></tr>
                            <?php
                            $check_ban_table = mysqli_query($conn, "SHOW TABLES LIKE 'blocked_ips'");
                            if(mysqli_num_rows($check_ban_table) > 0) {
                                // Fetch banned IPs that targeted this department's users
                                $banned_ips = mysqli_query($conn, "SELECT * FROM blocked_ips WHERE expires_at > NOW() AND ip_address IN (SELECT ip_address FROM login_logs WHERE username IN (SELECT username FROM teacher WHERE dept_id=$dept_id) OR username IN (SELECT username FROM student WHERE dept_id=$dept_id)) ORDER BY banned_at DESC");
                                
                                if(mysqli_num_rows($banned_ips) > 0){
                                    while($ip = mysqli_fetch_assoc($banned_ips)){
                                        echo "<tr style='border-bottom: 1px solid rgba(255,255,255,0.02);'>
                                                <td style='padding:15px; font-family:monospace; color:var(--danger); font-weight:800; font-size:14px;'><i class='fa-solid fa-network-wired'></i> {$ip['ip_address']}</td>
                                                <td style='padding:15px; font-size:12px; color:var(--text-muted); line-height:1.4;'>{$ip['ban_reason']}</td>
                                                <td style='padding:15px; text-align:right;'>
                                                    <form method='POST'>
                                                        <input type='hidden' name='ip_id' value='{$ip['id']}'>
                                                        <button type='submit' name='unban_ip' class='btn btn-sm' style='background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.3); padding:6px 12px; font-weight:bold; border-radius:8px;' title='Unblock this IP'><i class='fa-solid fa-unlock'></i> Unban</button>
                                                    </form>
                                                </td>
                                              </tr>";
                                    }
                                } else { echo "<tr><td colspan='3' style='text-align:center; padding:30px; color:var(--success);'><i class='fa-solid fa-shield-check' style='font-size:40px; display:block; margin-bottom:10px; opacity:0.8;'></i> Zero threats detected.</td></tr>"; }
                            } else { echo "<tr><td colspan='3' style='text-align:center; padding:20px;'>Security table not initialized.</td></tr>"; }
                            ?>
                        </table>
                    </div>
                </div>

                <!-- 3. LIVE AUTH LOGS -->
                <div class="premium-panel" style="padding: 25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-server"></i></div> Live Authentication Logs</h3>
                    <div style="max-height: 350px; overflow-y: auto; padding-right: 5px;">
                        <table style="width:100%;">
                            <tr style="background: rgba(0,0,0,0.2);"><th style="border-radius:8px 0 0 8px;">Time</th><th>Account / IP</th><th style="border-radius:0 8px 8px 0; text-align:right;">Status</th></tr>
                            <?php
                            $check_logs = mysqli_query($conn, "SHOW TABLES LIKE 'login_logs'");
                            if(mysqli_num_rows($check_logs) > 0) {
                                $logs = mysqli_query($conn, "SELECT * FROM login_logs WHERE username IN (SELECT username FROM teacher WHERE dept_id=$dept_id) OR username IN (SELECT username FROM student WHERE dept_id=$dept_id) ORDER BY attempt_time DESC LIMIT 20");
                                if(mysqli_num_rows($logs)>0){
                                    while($l=mysqli_fetch_assoc($logs)){
                                        $time = date("M d, H:i:s", strtotime($l['attempt_time']));
                                        
                                        if($l['status'] == 'success') { $s_badge = "<span style='color:var(--success); font-weight:800; font-size:12px;'><i class='fa-solid fa-check'></i> OK</span>"; }
                                        elseif($l['status'] == 'failed') { $s_badge = "<span style='color:var(--warning); font-weight:800; font-size:12px;'><i class='fa-solid fa-xmark'></i> FAIL</span>"; }
                                        elseif($l['status'] == 'otp_sent') { $s_badge = "<span style='color:var(--primary); font-weight:800; font-size:12px;'><i class='fa-solid fa-envelope'></i> OTP</span>"; }
                                        else { $s_badge = "<span style='color:var(--danger); font-weight:800; font-size:12px;'><i class='fa-solid fa-ban'></i> BLOCKED</span>"; }
                                        
                                        echo "<tr style='border-bottom: 1px dashed rgba(255,255,255,0.05); transition:0.3s;' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                                <td style='padding:12px; font-size:12px; color:var(--text-muted);'>{$time}</td>
                                                <td style='padding:12px;'><strong style='color:var(--text-main); font-size:13.5px;'>@{$l['username']}</strong><br><span style='font-family:monospace; color:var(--text-muted); font-size:11.5px;'><i class='fa-solid fa-location-crosshairs' style='color:var(--primary);'></i> {$l['ip_address']}</span></td>
                                                <td style='padding:12px; text-align:right;'>{$s_badge}</td>
                                              </tr>";
                                    }
                                } else { echo "<tr><td colspan='3' style='text-align:center; padding:30px; color:var(--text-muted);'>No login logs recorded yet.</td></tr>"; }
                            }
                            ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- 7. HELP CENTER & ULTIMATE KNOWLEDGE BASE       -->
        <!-- ============================================== -->
        <div id="help" class="section-tab">
            <div class="help-hero" style="background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 100%);">
                <h2><i class="fa-solid fa-book-open-reader"></i> Ultimate HoD Knowledge Base</h2>
                <p>Welcome to the comprehensive Department Head guide. This documentation covers every module, security protocol, and operational workflow you need to successfully manage your department on the EPLMS platform.</p>
                <div class="help-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="help-search-input" placeholder="Search for guides, settings, workflows..." onkeyup="searchHelpTopics()">
                </div>
            </div>

            <div id="help-content-wrapper">
                
                <!-- PART 1: SYSTEM SETTINGS & SECURITY -->
                <div class="premium-panel" style="border-top-color: #f59e0b; margin-bottom: 25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-user-shield"></i></div> 1. System Settings & Cybersecurity</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">1.1 Configuring Your 2-Factor Authentication (2FA) <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <div class="info-alert warning"><strong><i class="fa-solid fa-triangle-exclamation"></i> Mandatory Security Step:</strong> It is highly recommended to enable 2FA to prevent unauthorized access to the Department Dashboard.</div>
                            <p><strong>How it works:</strong> When 2FA is enabled, logging in requires more than just your password. The system will send a dynamic, 6-digit One-Time Password (OTP) to your <strong>Private Email Address</strong>.</p>
                            <ul style="margin-left: 20px; line-height: 1.8; margin-top: 10px;">
                                <li>Navigate to the <strong>Settings</strong> tab.</li>
                                <li>Under <strong>Personal Identity</strong>, ensure your Private Email is correct.</li>
                                <li>Toggle the <strong>Two-Factor Auth (2FA)</strong> switch to ON.</li>
                                <li>Enter your current password at the bottom and click <strong>Save & Authenticate</strong>.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">1.2 Setting Up the Department Mail Server (Crucial) <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>As the Head, you are responsible for dispatching system emails (like Registration Approvals and 2FA OTPs) to your Teachers and Students. You MUST configure the system mail server.</p>
                            <ol style="margin-left: 20px; line-height: 1.8; margin-top: 10px;">
                                <li>Go to the <strong>Settings</strong> tab.</li>
                                <li>Locate the <strong>System Mail Server</strong> section.</li>
                                <li>Enter your official Department Email in the <strong>Public Email Sender</strong> field.</li>
                                <li>Generate a <strong>16-character Google App Password</strong> (from your Google Account > Security > 2-Step Verification > App Passwords) and paste it into the App Password field.</li>
                            </ol>
                            <div class="help-pro-tip"><i class="fa-solid fa-lightbulb"></i> <strong>Pro Tip:</strong> If this is not configured, your students will NOT receive their auto-generated passwords when you approve them!</div>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">1.3 Profile Privacy Lock <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>If you wish to maintain a strictly professional presence without displaying your personal avatar photo to your subordinates (Teachers and Students):</p>
                            <p>Toggle <strong>Profile Privacy Lock</strong> to ON in Settings. This overrides your uploaded photo and displays your initial letter in the communications hub instead.</p>
                        </div>
                    </div>
                </div>

                <!-- PART 2: STUDENT MANAGEMENT -->
                <div class="premium-panel" style="border-top-color: #3b82f6; margin-bottom: 25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-user-graduate"></i></div> 2. Student Enrollment & Approvals</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">2.1 Controlling Public Registration & Department Code <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>To prevent the EPLMS database from crashing due to thousands of students registering simultaneously, students do NOT select their department from a dropdown. Instead, they use a <strong>Secret Department Code</strong>.</p>
                            <ul style="margin-left: 20px; line-height: 1.8; margin-top: 10px;">
                                <li>Your unique Department Code is visible in the <strong>Manage Students</strong> tab.</li>
                                <li>Share this code <em>only</em> with your official students.</li>
                                <li>You can dynamically <strong>OPEN</strong> or <strong>CLOSE</strong> public registration using the toggle button. If closed, no one can register under your department even if they have the code.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">2.2 The Approval Workflow (Auto-Credentials) <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>When a student registers, they are placed in the <strong>Pending Approvals</strong> queue.</p>
                            <div class="info-alert success">
                                <strong><i class="fa-solid fa-wand-magic-sparkles"></i> Magic Automation:</strong> When you click the green <strong>Approve</strong> button, the system automatically:
                                <br>1. Generates a unique username based on their first name.
                                <br>2. Generates a secure, randomized 8-character password.
                                <br>3. Emails these credentials directly to the student using your configured Mail Server.
                            </div>
                            <p>If an unknown individual registers, click the red <strong>Block</strong> button to deny them access permanently.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">2.3 Manually Adding & Managing Students <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>You can bypass public registration and manually add a student via the <strong>Manually Add Student</strong> form. The system will auto-generate and email their credentials instantly.</p>
                            <p>In the <strong>Enrolled Students Directory</strong>, you can:</p>
                            <ul style="margin-left: 20px; line-height: 1.8;">
                                <li><strong>Suspend:</strong> Temporarily block a student's login access.</li>
                                <li><strong>Edit:</strong> Update their email, phone, or ID number if they made a mistake.</li>
                                <li><strong>Trash:</strong> Move a student to the Recycle Bin (requires your password to authenticate).</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- PART 3: TEACHERS & COURSES -->
                <div class="premium-panel" style="border-top-color: #10b981; margin-bottom: 25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-chalkboard-user"></i></div> 3. Faculty & Course Logistics</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">3.1 Registering Department Teachers <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>Unlike students, Teachers cannot register publicly. They must be registered exclusively by the Department Head.</p>
                            <p>Go to the <strong>Manage Teachers</strong> tab, enter their exact details, and assign them a secure password. You can suspend or move teachers to the Recycle Bin just like students.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">3.2 Creating Courses & Assigning Workloads <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The <strong>Manage Courses</strong> tab allows you to build your department's curriculum curriculum.</p>
                            <ul style="margin-left: 20px; line-height: 1.8; margin-top: 10px;">
                                <li><strong>Create Course:</strong> Define the Course Name and unique Course Code (e.g., COMP101).</li>
                                <li><strong>Assign Course:</strong> Link an active teacher to a course. A single teacher can be assigned to multiple courses simultaneously.</li>
                                <li><strong>Unassign:</strong> If a teacher's workload changes, simply unassign the course from them without deleting the course itself.</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">3.3 Using the 360° Dept Oversight <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The <strong>Dept Oversight</strong> tab is a Single Page Application (SPA) designed for rapid analytics.</p>
                            <p>Click on any Teacher's card to instantly view their profile, see exactly which courses they have been assigned to, and view the entire directory of students operating under their department. Use the Breadcrumbs (top bar) to navigate back smoothly.</p>
                        </div>
                    </div>
                </div>

                <!-- PART 4: COMMUNICATIONS -->
                <div class="premium-panel" style="border-top-color: #0ea5e9; margin-bottom: 25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #0ea5e9, #0284c7);"><i class="fa-brands fa-telegram"></i></div> 4. Encrypted Communications Hub</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">4.1 Hierarchy Boundaries & Privacy <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The EPLMS communications module strictly enforces organizational hierarchy to prevent spam and maintain order:</p>
                            <ul style="margin-left: 20px; line-height: 1.8; margin-top: 10px;">
                                <li>You can communicate <strong>UP</strong> the chain to your specific College Admin and the global Super Admin.</li>
                                <li>You can communicate <strong>DOWN</strong> the chain to your specific Teachers and Approved Students.</li>
                                <li>You <strong>CANNOT</strong> chat with admins from other colleges, or teachers/students from other departments.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">4.2 Group Broadcasts vs Private Chats <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p><strong>Private Chat:</strong> Clicking on a specific individual initiates an End-to-End encrypted 1-on-1 session.</p>
                            <p><strong>Group Broadcast:</strong> Clicking on a Megaphone icon (📢 All Teachers or 📢 All Students) allows you to send an emergency or general announcement. The system processes this via an "Individual Loop", meaning the broadcast is delivered to each user as a direct message, ensuring they receive a red notification badge.</p>
                            <div class="help-pro-tip"><i class="fa-solid fa-mouse-pointer"></i> <strong>Pro Tip:</strong> Right-click on any message you've sent to instantly Edit or Delete it from everyone's screen in real-time!</div>
                        </div>
                    </div>
                </div>

                <!-- PART 5: SECURITY CENTER -->
                <div class="premium-panel" style="border-top-color: #f43f5e; margin-bottom: 25px;">
                    <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-radar"></i></div> 5. Cyber Security Command Center</h3>
                    
                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">5.1 Threat Monitoring & Account Health <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>You act as the primary cybersecurity officer for your department. The <strong>Security Center</strong> tab tracks every login attempt made by your teachers and students.</p>
                            <p>The <strong>Department Accounts Health</strong> table calculates a Threat Level based on failed login attempts within the last 24 hours. Accounts with multiple failures are marked as "High Risk" and require your immediate attention.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)">5.2 Auto-Banned IPs & Unbanning <i class="fa-solid fa-chevron-down"></i></button>
                        <div class="help-acc-content">
                            <p>The EPLMS features a rigid Anti-Brute Force mechanism. If any person (or hacker) tries to guess a teacher's or student's password and fails 3 or more times, the system records their IP address and <strong>Auto-Bans</strong> them for 24 hours.</p>
                            <p>If a student legitimately forgot their password and got locked out, you can find their IP in the "Auto-Banned IPs" table and click <strong>Unban</strong> to instantly restore their access.</p>
                        </div>
                    </div>
                </div>

            </div> <!-- End of wrapper -->
            
            <div style="text-align: center; margin-top: 40px; color: var(--text-muted); font-size: 13px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
                <p>EPLMS Head of Department Documentation v2.0 <br> Empowering Academic Leadership through Technology.</p>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- 8. ABOUT EPLMS -> EVOLVING TO RLMS             -->
        <!-- ============================================== -->
        <div id="about" class="section-tab">
            
            <!-- Hero Banner -->
            <div class="help-hero" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); border-bottom: 5px solid #3b82f6; padding: 60px 30px;">
                <div style="display: flex; justify-content: center; align-items: center; gap: 30px; margin-bottom: 25px;">
                    <div class="icon-box" style="width: 90px; height: 90px; font-size: 40px; margin: 0; background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 10px 30px rgba(59, 130, 246, 0.5); border-radius: 25px;">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <i class="fa-solid fa-arrow-right-arrow-left" style="font-size: 35px; color: #60a5fa; opacity: 0.7; animation: pulse-badge 2s infinite;"></i>
                    <div class="icon-box" style="width: 90px; height: 90px; font-size: 40px; margin: 0; background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.5); border-radius: 25px;">
                        <i class="fa-solid fa-network-wired"></i>
                    </div>
                </div>
                <h2 style="font-size: 42px; margin-bottom: 15px; text-shadow: 0 5px 15px rgba(0,0,0,0.5);">EPLMS <span style="color:#60a5fa;">is now</span> RLMS</h2>
                <p style="font-size: 17px; color: #e2e8f0; max-width: 900px; margin: 0 auto; line-height: 1.9; font-weight: 500;">
                    The Exam Portal & Learning Management System (EPLMS) has officially evolved into the <strong>Registration & Learning Management System (RLMS)</strong>. A complete paradigm shift in how universities handle admissions, course distributions, cybersecurity, and encrypted academic communications.
                </p>
            </div>

            <!-- The RLMS Vision & Core Features -->
            <div class="premium-panel" style="border-top-color: #3b82f6; padding: 40px;">
                <h3 class="panel-title-premium" style="font-size: 22px;"><div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-rocket"></i></div> The RLMS Vision & Capabilities</h3>
                <p style="color:var(--text-muted); line-height: 1.9; font-size: 15px; margin-bottom: 30px;">
                    Initially designed strictly for conducting examinations, the system has dramatically outgrown its original purpose. With the integration of advanced smart-registration logic, it now autonomously handles the entire academic lifecycle of a student and faculty member.
                </p>
                
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
                    <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-solid fa-user-check" style="font-size: 30px; color: #10b981; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Smart Registration</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">Public registration is driven by secret <strong>Department Codes</strong>. Upon Head approval, the system auto-generates secure credentials and dispatches them via SMTP directly to the user's email.</p>
                    </div>
                    
                    <div style="background: rgba(244, 63, 94, 0.05); border: 1px solid rgba(244, 63, 94, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-solid fa-shield-halved" style="font-size: 30px; color: #f43f5e; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Absolute Security (2FA)</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">Military-grade protection featuring Anti-Brute Force firewalls that auto-ban IPs after 3 failed attempts, coupled with mandatory OTP-based Two-Factor Authentication.</p>
                    </div>

                    <div style="background: rgba(14, 165, 233, 0.05); border: 1px solid rgba(14, 165, 233, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-brands fa-telegram" style="font-size: 30px; color: #0ea5e9; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Encrypted Comms Hub</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">A fully integrated, real-time AJAX chat system. Supports department-wide broadcasts and secure 1-on-1 messaging strictly bound by hierarchical access rules.</p>
                    </div>

                    <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-solid fa-bolt" style="font-size: 30px; color: #f59e0b; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">SPA Analytics</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">Single Page Application (SPA) architecture allows administrators and heads to effortlessly drill-down through colleges, teachers, and students without ever reloading the page.</p>
                    </div>
                </div>
            </div>
            
            <!-- The 5-Tier Architecture (Visual Cascading Layout) -->
            <div class="premium-panel" style="border-top-color: #8b5cf6; padding: 40px;">
                <h3 class="panel-title-premium" style="font-size: 22px;"><div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0; background:linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-sitemap"></i></div> The 5-Tier Enterprise Architecture</h3>
                <p style="color:var(--text-muted); line-height: 1.9; font-size: 15px; margin-bottom: 30px;">
                    RLMS operates on a strict Role-Based Access Control (RBAC) model. Data is siloed perfectly so no user can access information outside their designated jurisdiction.
                </p>
                
                <div style="display:flex; flex-direction:column; position: relative;">
                    <!-- Vertical connecting line -->
                    <div style="position: absolute; left: 35px; top: 30px; bottom: 40px; width: 4px; background: linear-gradient(to bottom, #f59e0b, #3b82f6, #8b5cf6, #10b981, #f43f5e); border-radius: 5px; opacity: 0.3;"></div>
                    
                    <!-- 1. Super Admin -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #f59e0b, #b45309); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(245, 158, 11, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-crown"></i></div>
                        <div style="background:rgba(245,158,11,0.05); border:1px solid rgba(245,158,11,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(245,158,11,0.1)'" onmouseout="this.style.background='rgba(245,158,11,0.05)'">
                            <h4 style="color:#f59e0b; font-size:16px; font-weight:800; margin-bottom:5px;">1. Super Admin (Global Owner)</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Holds absolute power over the entire university system. Creates Colleges, assigns College Admins, oversees global security logs, and manages system-wide social integrations.</p>
                        </div>
                    </div>

                    <!-- 2. College Admin -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1; margin-left: 20px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-building-columns"></i></div>
                        <div style="background:rgba(59,130,246,0.05); border:1px solid rgba(59,130,246,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(59,130,246,0.1)'" onmouseout="this.style.background='rgba(59,130,246,0.05)'">
                            <h4 style="color:#3b82f6; font-size:16px; font-weight:800; margin-bottom:5px;">2. College Admin (Campus Logic)</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Manages a specific college. Creates departments, assigns Department Heads, and configures the public mail server to route OTPs downwards.</p>
                        </div>
                    </div>

                    <!-- 3. Department Head -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1; margin-left: 40px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #6d28d9); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-users-gear"></i></div>
                        <div style="background:rgba(139,92,246,0.08); border:1px solid rgba(139,92,246,0.3); padding:20px; border-radius:16px; flex: 1; box-shadow: 0 5px 20px rgba(139, 92, 246, 0.1); transform: scale(1.02); z-index: 2;">
                            <h4 style="color:#8b5cf6; font-size:16px; font-weight:800; margin-bottom:5px;">3. Department Head <span class="badge" style="margin-left:10px; background:var(--primary); color:#fff; border:none;">You Are Here</span></h4>
                            <p style="color:var(--text-main); font-size:13.5px; line-height:1.6; margin:0;">Controls the department's ecosystem. Approves student registrations, manages faculty members, assigns courses, and acts as the local cybersecurity officer.</p>
                        </div>
                    </div>

                    <!-- 4. Teacher -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1; margin-left: 60px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #047857); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(16,185,129,0.1)'" onmouseout="this.style.background='rgba(16,185,129,0.05)'">
                            <h4 style="color:#10b981; font-size:16px; font-weight:800; margin-bottom:5px;">4. Teacher / Faculty (Course Logic)</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Responsible for delivering education. Uploads PDF materials, assignments, sets up exams, and grades students securely.</p>
                        </div>
                    </div>

                    <!-- 5. Student -->
                    <div style="display: flex; gap: 20px; position: relative; z-index: 1; margin-left: 80px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #f43f5e, #be123c); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(244, 63, 94, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-user-graduate"></i></div>
                        <div style="background:rgba(244,63,94,0.05); border:1px solid rgba(244,63,94,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(244,63,94,0.1)'" onmouseout="this.style.background='rgba(244,63,94,0.05)'">
                            <h4 style="color:#f43f5e; font-size:16px; font-weight:800; margin-bottom:5px;">5. Enrolled Student (Learner)</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">The end-user. Accesses course materials, submits assignments, takes secure exams, and chats with their respective teachers.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tech Stack Footer -->
            <div style="text-align: center; margin-top: 20px; padding: 35px 20px; border-top: 1px dashed var(--border-color); background: rgba(0,0,0,0.1); border-radius: 16px;">
                <div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
                    <span class="badge" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color);"><i class="fa-brands fa-php" style="color:#8b5cf6;"></i> PHP 8.x Optimized</span>
                    <span class="badge" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color);"><i class="fa-solid fa-database" style="color:#3b82f6;"></i> MySQLi Relational DB</span>
                    <span class="badge" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color);"><i class="fa-brands fa-js" style="color:#f59e0b;"></i> 100% Vanilla JS & AJAX</span>
                    <span class="badge" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color);"><i class="fa-solid fa-chart-pie" style="color:#10b981;"></i> Chart.js Integrated</span>
                </div>
                <p style="color: var(--text-muted); font-size: 14px; line-height:1.8;">
                    <strong>Designed & Developed for Modern Universities</strong>
                    <br>RLMS Core Architecture v2.5 | Enterprise Edition
                </p>
            </div>
        </div>
       <!-- ============================================== -->
        <!-- 8. SETTINGS & PROFILE (ULTIMATE PREMIUM UI)    -->
        <!-- ============================================== -->
        <div id="settings" class="section-tab">
            
            <!-- 🌟 Premium Profile Header 🌟 -->
            <div class="profile-header-card" style="background: linear-gradient(135deg, #4c1d95 0%, #312e81 100%); border-radius: 24px; padding: 40px 20px; text-align: center; position: relative; margin-bottom: 35px; border-bottom: 5px solid #8b5cf6; box-shadow: 0 15px 35px rgba(139, 92, 246, 0.2); overflow: hidden;">
                <!-- Decorative Background Circles -->
                <div style="position: absolute; top: -50px; left: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); border-radius: 50%;"></div>
                <div style="position: absolute; bottom: -50px; right: -50px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(139,92,246,0.2) 0%, transparent 70%); border-radius: 50%;"></div>
                
                <div class="profile-avatar-wrapper" style="width: 130px; height: 130px; margin: 0 auto 20px auto; position: relative; z-index: 2;">
                    <img src="<?php echo $profile_pic; ?>" alt="Profile" class="profile-avatar-large" id="preview_avatar_top" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid #c4b5fd; box-shadow: 0 10px 25px rgba(0,0,0,0.4);">
                    <label for="pic_upload" class="edit-avatar-btn" style="position: absolute; bottom: 5px; right: -5px; background: #fcd535; color: #000; width: 38px; height: 38px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 3px solid #312e81; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.3);" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"><i class="fa-solid fa-camera"></i></label>
                </div>
                
                <h2 class="profile-name" style="color: #ffffff; font-size: 28px; font-weight: 800; margin-bottom: 8px; position: relative; z-index: 2; letter-spacing: 0.5px;">
                    <?php echo htmlspecialchars($head_info['name']); ?> 
                    <i class="fa-solid fa-circle-check" style="color:#10b981; font-size:22px; text-shadow: 0 0 15px rgba(16, 185, 129, 0.6);" title="Verified Department Head"></i>
                </h2>
                <p class="profile-email" style="color: #c4b5fd; font-size: 15px; margin-bottom: 20px; font-weight: 500; position: relative; z-index: 2;"><i class="fa-solid fa-envelope" style="margin-right: 5px;"></i> <?php echo htmlspecialchars($head_info['email']); ?></p>
                
                <div class="profile-badges" style="position: relative; z-index: 2; display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;">
                    <span class="badge" style="background: rgba(139, 92, 246, 0.2); color: #ddd; border: 1px solid rgba(139, 92, 246, 0.4); padding: 8px 15px; font-size: 12px;"><i class="fa-solid fa-sitemap" style="color: #a78bfa;"></i> <?php echo htmlspecialchars($head_info['dept_name']); ?></span>
                    <span class="badge" style="background: rgba(59, 130, 246, 0.2); color: #ddd; border: 1px solid rgba(59, 130, 246, 0.4); padding: 8px 15px; font-size: 12px;"><i class="fa-solid fa-building-columns" style="color: #60a5fa;"></i> <?php echo htmlspecialchars($head_info['college_name']); ?></span>
                </div>
            </div>

            <!-- 🔘 Navigation Tabs for Settings 🔘 -->
            <div style="text-align: center; margin-bottom: 30px;">
                <div class="inner-tabs" style="display: inline-flex; background: var(--input-bg); padding: 8px; border-radius: 16px; border: 1px solid var(--border-color); box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                    <button class="inner-tab-btn active" onclick="switchInnerTab('account', this)" style="border-radius: 10px; padding: 12px 25px;"><i class="fa-solid fa-id-card-clip"></i> Account & Mail Server</button>
                    <button class="inner-tab-btn" onclick="switchInnerTab('security', this)" style="border-radius: 10px; padding: 12px 25px;"><i class="fa-solid fa-user-shield"></i> Security Policies</button>
                </div>
            </div>

            <!-- 📝 Main Settings Form 📝 -->
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="profile_pic" id="pic_upload" style="display:none;" onchange="previewImage(this)">
                
                <!-- TAB 1: ACCOUNT & MAIL SERVER -->
                <div id="inner-account" class="inner-tab-content active">
                    <div class="settings-grid">
                        
                        <!-- LEFT PANEL: PRIVATE IDENTITY -->
                        <div class="premium-panel" style="border-top-color: #8b5cf6;">
                            <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-user"></i></div> Personal Identity</h3>
                            
                            <div class="info-alert" style="background: rgba(139, 92, 246, 0.05); border-left: 4px solid #8b5cf6;">
                                <strong style="color: #8b5cf6;"><i class="fa-solid fa-lock"></i> Private Email Role</strong>
                                This email is your personal contact. When you enable 2FA, your College Admin will send your secure OTP login codes to this address.
                            </div>

                            <div class="form-group">
                                <label>Full Name</label>
                                <div class="input-with-icon"><input type="text" name="h_name" value="<?php echo htmlspecialchars($head_info['name']); ?>" required><i class="fa-solid fa-user-tie"></i></div>
                            </div>
                            <div class="form-group">
                                <label>Username</label>
                                <div class="input-with-icon"><input type="text" name="h_username" value="<?php echo htmlspecialchars($head_info['username']); ?>" required><i class="fa-solid fa-at"></i></div>
                            </div>
                            <div class="form-group">
                                <label style="color: #8b5cf6; font-weight: 800;">Private Email (Receives 2FA OTPs)</label>
                                <div class="input-with-icon"><input type="email" name="h_email" value="<?php echo htmlspecialchars($head_info['email']); ?>" required style="border-color: rgba(139, 92, 246, 0.5); background: rgba(139, 92, 246, 0.02);"><i class="fa-solid fa-envelope-circle-check" style="color: #8b5cf6;"></i></div>
                            </div>
                            <div class="form-group">
                                <label>Phone Number (Optional)</label>
                                <div class="input-with-icon"><input type="text" name="h_phone" value="<?php echo htmlspecialchars($head_info['phone'] ?? ''); ?>" placeholder="+251..."><i class="fa-solid fa-phone"></i></div>
                            </div>
                        </div>

                        <!-- RIGHT PANEL: PUBLIC MAIL SERVER -->
                        <div class="premium-panel" style="border-top-color: #f59e0b;">
                            <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-server"></i></div> System Mail Server</h3>
                            
                            <div class="info-alert warning" style="background: rgba(245, 158, 11, 0.05); border-left: 4px solid #f59e0b;">
                                <strong style="color: #f59e0b;"><i class="fa-solid fa-satellite-dish"></i> Public Sender Role</strong>
                                This acts as your Department's Mail Server. The system uses this to automatically send Registration Credentials and 2FA OTPs to your Teachers and Students.
                            </div>

                            <div class="form-group">
                                <label style="color: #f59e0b; font-weight: 800;">Public Email Sender (Dept Email)</label>
                                <div class="input-with-icon"><input type="email" name="h_public_email" value="<?php echo htmlspecialchars($head_info['public_email'] ?? ''); ?>" placeholder="e.g. cs_dept@college.edu" style="border-color: rgba(245, 158, 11, 0.5); background: rgba(245, 158, 11, 0.02);"><i class="fa-solid fa-envelope-open-text" style="color: #f59e0b;"></i></div>
                            </div>
                            <div class="form-group pw-group">
                                <label style="color: #f59e0b; font-weight: 800;">Google App Password (For SMTP)</label>
                                <div class="input-with-icon">
                                    <input type="password" name="h_app_password" id="head_app_pass" value="<?php echo htmlspecialchars($head_info['app_password'] ?? ''); ?>" placeholder="16-character code" style="border-color: rgba(245, 158, 11, 0.5); background: rgba(245, 158, 11, 0.02);">
                                    <i class="fa-solid fa-key" style="color: #f59e0b;"></i>
                                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('head_app_pass', this)" style="color: #f59e0b;"></i>
                                </div>
                                <small style="display:block; margin-top:8px; color:var(--text-muted); font-size:11.5px;"><i class="fa-brands fa-google"></i> Must be generated from Google Account > Security > App Passwords.</small>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- TAB 2: SECURITY POLICIES -->
                <div id="inner-security" class="inner-tab-content">
                    <div class="settings-grid">
                        <div class="premium-panel" style="border-top-color: #10b981;">
                            <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-shield-virus"></i></div> Privacy & Authentication Policies</h3>
                            
                            <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.6;">Configure how your account authenticates logins and how your profile appears to other users within the department.</p>

                            <!-- 2FA Toggle -->
                            <div class="sec-toggle" style="background: rgba(16, 185, 129, 0.03); border: 1px solid rgba(16, 185, 129, 0.2);">
                                <div class="sec-toggle-info">
                                    <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-mobile-screen-button" style="color:#10b981; margin-right:8px;"></i> Two-Factor Auth (2FA)</h4>
                                    <p style="color: var(--text-muted); font-size: 12.5px;">Require a dynamic OTP code sent to your Private Email during every login attempt.</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="two_factor" <?php echo $head_info['two_factor_enabled'] ? 'checked' : ''; ?>><span class="slider" style="background-color: #10b981;"></span></label>
                            </div>
                            
                            <!-- Login Alerts Toggle -->
                            <div class="sec-toggle">
                                <div class="sec-toggle-info">
                                    <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-bell" style="color:var(--primary); margin-right:8px;"></i> Login Alerts</h4>
                                    <p style="color: var(--text-muted); font-size: 12.5px;">Receive an email notification on new device login attempts.</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="login_alerts" <?php echo $head_info['login_alerts'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>

                            <!-- Profile Privacy Toggle -->
                            <div class="sec-toggle" style="border-left: 4px solid var(--danger);">
                                <div class="sec-toggle-info">
                                    <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-user-lock" style="color:var(--danger); margin-right:8px;"></i> Profile Privacy Lock</h4>
                                    <p style="color: var(--text-muted); font-size: 12.5px;">Hide your Avatar photo from Teachers & Students in the communications hub.</p>
                                </div>
                                <label class="switch"><input type="checkbox" name="profile_locked" <?php echo $head_info['profile_locked'] ? 'checked' : ''; ?>><span class="slider"></span></label>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- 🚨 BOTTOM SAVE SECTION (ALWAYS VISIBLE - DANGER ZONE) 🚨 -->
                <div class="premium-panel" style="margin-top: 15px; border: 1px solid rgba(244, 63, 94, 0.3); border-top: 5px solid var(--danger); box-shadow: 0 10px 40px rgba(244, 63, 94, 0.08); background: linear-gradient(180deg, var(--panel-bg) 0%, rgba(244, 63, 94, 0.03) 100%);">
                    <h3 class="panel-title-premium" style="color: var(--danger); border-bottom-color: rgba(244, 63, 94, 0.1); margin-bottom: 20px;">
                        <i class="fa-solid fa-fingerprint" style="font-size: 22px;"></i> Final Security Authorization
                    </h3>
                    <p style="color: var(--text-muted); font-size: 13.5px; margin-bottom: 25px; line-height: 1.6;">To apply any changes to your profile, mail server, or security policies, you must verify your identity using your current password.</p>
                    
                    <div class="settings-grid" style="gap: 30px;">
                        
                        <!-- Current Password (Required) -->
                        <div class="form-group pw-group">
                            <label style="color:var(--danger); font-size:13.5px; font-weight:800; text-transform:uppercase; letter-spacing:1px;">Current Password (Required) *</label>
                            <div class="input-with-icon">
                                <input type="password" name="current_password" id="curr_pass" placeholder="Enter your current password to verify..." required style="border: 2px solid rgba(244, 63, 94, 0.5); background: rgba(244, 63, 94, 0.05); padding: 16px 16px 16px 50px !important; font-size:15px; color: var(--text-main);">
                                <i class="fa-solid fa-shield-keyhole" style="color:var(--danger); font-size: 18px;"></i>
                                <i class="fa-solid fa-eye pw-eye" onclick="togglePw('curr_pass', this)" style="color: var(--danger);"></i>
                            </div>
                        </div>
                        
                        <!-- New Password (Optional) -->
                        <div class="form-group pw-group">
                            <label style="font-weight:700; font-size:13.5px;">New Strong Password (Optional)</label>
                            <div class="input-with-icon">
                                <input type="password" name="new_password" id="new_pass" placeholder="Leave blank if you don't want to change it" onkeyup="checkPasswordStrength()" style="padding: 16px 16px 16px 50px !important; font-size:15px; color: var(--text-main); border: 1px dashed var(--border-color);">
                                <i class="fa-solid fa-key"></i>
                                <i class="fa-solid fa-eye pw-eye" onclick="togglePw('new_pass', this)"></i>
                            </div>
                            
                          <div class="pw-rules" id="pw-rules" style="background: var(--bg-color); border: 1px dashed var(--border-color); padding: 15px; border-radius: 12px; margin-top: 15px;">
                                <div id="rule-length" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</div>
                                <div id="rule-upper" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> Upper & Lowercase letters</div>
                                <div id="rule-number" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> Contains a Number</div>
                                <div id="rule-special" class="rule-item"><i class="fa-solid fa-circle-xmark"></i> Special Character (@$!%*?&)</div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: right; margin-top:35px; border-top: 1px solid rgba(244, 63, 94, 0.1); padding-top: 25px;">
                        <button type="submit" name="save_all_settings" class="glow-btn" style="background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%); box-shadow: 0 10px 25px rgba(244, 63, 94, 0.4);">
                            <i class="fa-solid fa-shield-check" style="font-size: 20px;"></i> Save & Authenticate
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
</main>

<div id="secureDeleteModal" class="modal-overlay">
    <div class="modal-box" style="background: linear-gradient(145deg, var(--panel-bg), rgba(246, 70, 93, 0.05)); border: 1px solid var(--border-color); border-top: 4px solid var(--danger);">
        <h3 style="color:var(--danger); margin-bottom: 15px;"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Deletion</h3>
        <p style="color:var(--text-muted); font-size:14px; margin-bottom: 20px;">Are you sure you want to delete this teacher? Please authenticate to continue.</p>
        <form method="POST">
            <input type="hidden" name="teacher_id" id="del_teacher_id">
            <div class="form-group pw-group" style="margin-bottom: 20px;">
                <div class="input-with-icon">
                    <input type="password" name="head_password" id="del_head_pw" placeholder="Enter your password..." required style="border-color: var(--danger); background: rgba(246, 70, 93, 0.05);">
                    <i class="fa-solid fa-shield-keyhole" style="color:var(--danger);"></i>
                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('del_head_pw', this)" style="color:var(--danger);"></i>
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('secureDeleteModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="soft_delete_teacher" class="btn btn-danger" style="flex:1;"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Modals (Delete & Edit) -->
<div id="secureDeleteModal" class="modal-overlay">
    <div class="modal-box" style="background: linear-gradient(145deg, var(--panel-bg), rgba(246, 70, 93, 0.05)); border: 1px solid var(--border-color); border-top: 4px solid var(--danger);">
        <h3 style="color:var(--danger); margin-bottom: 15px;"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Deletion</h3>
        <p style="color:var(--text-muted); font-size:14px; margin-bottom: 20px;">Are you sure you want to move <strong id="del_name_display" style="color:var(--text-main);"></strong> to trash? Please authenticate.</p>
        <form method="POST">
            <input type="hidden" name="teacher_id" id="del_teacher_id">
            <input type="hidden" name="student_id" id="del_student_id">
            <input type="hidden" name="delete_type" id="del_type">
            
            <div class="form-group pw-group" style="margin-bottom: 20px;">
                <div class="input-with-icon">
                    <input type="password" name="head_password" id="del_head_pw" placeholder="Enter your password..." required style="border-color: var(--danger); background: rgba(246, 70, 93, 0.05);">
                    <i class="fa-solid fa-shield-keyhole" style="color:var(--danger);"></i>
                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('del_head_pw', this)" style="color:var(--danger);"></i>
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('secureDeleteModal').classList.remove('active')">Cancel</button>
                <button type="submit" id="btn_real_delete" class="btn btn-danger" style="flex:1;"><i class="fa-solid fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<div id="editStudentModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 500px; border-top: 4px solid #f59e0b;">
        <h3 style="color:#f59e0b; margin-bottom: 20px;"><i class="fa-solid fa-user-pen"></i> Edit Student Info</h3>
        <form method="POST">
            <input type="hidden" name="edit_s_id" id="edit_s_id">
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;"><label>First Name</label><div class="input-with-icon"><input type="text" name="edit_s_fname" id="edit_s_fname" required><i class="fa-solid fa-user"></i></div></div>
                <div class="form-group" style="flex:1;"><label>Last Name</label><div class="input-with-icon"><input type="text" name="edit_s_lname" id="edit_s_lname" required><i class="fa-solid fa-user"></i></div></div>
            </div>
            <div class="form-group"><label>ID Number</label><div class="input-with-icon"><input type="text" name="edit_s_id_num" id="edit_s_id_num" required><i class="fa-solid fa-id-card"></i></div></div>
            <div class="form-group"><label>Email Address</label><div class="input-with-icon"><input type="email" name="edit_s_email" id="edit_s_email" required><i class="fa-solid fa-envelope"></i></div></div>
            <div class="form-group"><label>Phone Number</label><div class="input-with-icon"><input type="text" name="edit_s_phone" id="edit_s_phone" required><i class="fa-solid fa-phone"></i></div></div>
            
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('editStudentModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="edit_student" class="btn btn-warning" style="flex:1; color:#fff;"><i class="fa-solid fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>
<!-- HEAD GRADE EDIT MODAL (PREMIUM & ORGANIZED) -->
<div id="headEditGradeModal" class="modal-overlay">
    <div class="modal-box" style="background: var(--panel-bg); border-top: 5px solid #f59e0b; max-width:600px; border-radius: 24px; padding: 40px; box-shadow: 0 20px 50px rgba(0,0,0,0.4);">
        
        <div class="icon-box" style="width:60px; height:60px; font-size:25px; margin:0 auto 15px auto; background:linear-gradient(135deg, #f59e0b, #b45309); color:#fff; border-radius:50%; box-shadow: 0 5px 15px rgba(245, 158, 11, 0.4); display:flex; justify-content:center; align-items:center;">
            <i class="fa-solid fa-user-pen"></i>
        </div>
        
        <h3 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom: 10px;">Resolve Grade Edit Request</h3>
        <p style="font-size:14px; color:var(--text-muted); margin-bottom:25px; line-height: 1.6;">Adjust the official scores for <strong id="head_edit_stu_name" style="color:#f59e0b;"></strong>. The teacher requested this correction.</p>
        
        <form method="POST">
            <input type="hidden" name="head_grade_id" id="head_edit_g_id">
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:25px; text-align:left;">
                
                <!-- Attendance -->
                <div class="form-group" style="margin:0;">
                    <label style="color:var(--text-muted); font-size:12px; margin-bottom:6px;"><i class="fa-solid fa-clipboard-user" style="color:#3b82f6;"></i> Attendance (Max 10)</label>
                    <input type="number" name="h_att" id="h_att" min="0" max="10" step="0.1" style="width:100%; padding:12px 15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); font-size:15px; font-weight:bold; border-radius:8px; outline:none; transition:0.3s;" onfocus="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 3px rgba(245, 158, 11, 0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';" required>
                </div>
                
                <!-- Assignment -->
                <div class="form-group" style="margin:0;">
                    <label style="color:var(--text-muted); font-size:12px; margin-bottom:6px;"><i class="fa-solid fa-file-pen" style="color:#10b981;"></i> Assignment (Max 10)</label>
                    <input type="number" name="h_ass" id="h_ass" min="0" max="10" step="0.1" style="width:100%; padding:12px 15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); font-size:15px; font-weight:bold; border-radius:8px; outline:none; transition:0.3s;" onfocus="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 3px rgba(245, 158, 11, 0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';" required>
                </div>
                
                <!-- Project -->
                <div class="form-group" style="margin:0;">
                    <label style="color:var(--text-muted); font-size:12px; margin-bottom:6px;"><i class="fa-solid fa-rocket" style="color:#8b5cf6;"></i> Project (Max 15)</label>
                    <input type="number" name="h_proj" id="h_proj" min="0" max="15" step="0.1" style="width:100%; padding:12px 15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); font-size:15px; font-weight:bold; border-radius:8px; outline:none; transition:0.3s;" onfocus="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 3px rgba(245, 158, 11, 0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';" required>
                </div>
                
                <!-- Quiz -->
                <div class="form-group" style="margin:0;">
                    <label style="color:var(--text-muted); font-size:12px; margin-bottom:6px;"><i class="fa-solid fa-stopwatch" style="color:#f43f5e;"></i> Quiz (Max 15)</label>
                    <input type="number" name="h_quiz" id="h_quiz" min="0" max="15" step="0.1" style="width:100%; padding:12px 15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); font-size:15px; font-weight:bold; border-radius:8px; outline:none; transition:0.3s;" onfocus="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 3px rgba(245, 158, 11, 0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';" required>
                </div>
                
                <!-- Mid Exam -->
                <div class="form-group" style="margin:0;">
                    <label style="color:var(--text-muted); font-size:12px; margin-bottom:6px;"><i class="fa-solid fa-file-lines" style="color:#0ea5e9;"></i> Mid Exam (Max 20)</label>
                    <input type="number" name="h_mid" id="h_mid" min="0" max="20" step="0.1" style="width:100%; padding:12px 15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); font-size:15px; font-weight:bold; border-radius:8px; outline:none; transition:0.3s;" onfocus="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 3px rgba(245, 158, 11, 0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';" required>
                </div>
                
                <!-- Final Exam -->
                <div class="form-group" style="margin:0;">
                    <label style="color:var(--text-muted); font-size:12px; margin-bottom:6px;"><i class="fa-solid fa-graduation-cap" style="color:#f59e0b;"></i> Final Exam (Max 30)</label>
                    <input type="number" name="h_fin" id="h_fin" min="0" max="30" step="0.1" style="width:100%; padding:12px 15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); font-size:15px; font-weight:bold; border-radius:8px; outline:none; transition:0.3s;" onfocus="this.style.borderColor='#f59e0b'; this.style.boxShadow='0 0 0 3px rgba(245, 158, 11, 0.1)';" onblur="this.style.borderColor='var(--border-color)'; this.style.boxShadow='none';" required>
                </div>
                
            </div>

            <div style="background: rgba(244, 63, 94, 0.05); border: 1px dashed rgba(244, 63, 94, 0.3); padding: 20px; border-radius: 12px; margin-bottom: 25px; text-align: left;">
                <div class="form-group pw-group" style="margin:0;">
                    <label style="color:var(--danger); font-size:13px; font-weight:800; margin-bottom:8px;"><i class="fa-solid fa-fingerprint"></i> Head of Department Password (Required)</label>
                    <div class="input-with-icon" style="position:relative;">
                        <input type="password" name="head_auth_password" id="head_auth_pw" placeholder="Enter your HoD password to authorize..." required style="width:100%; border: 1px solid var(--danger); background: var(--bg-color); color:var(--text-main); padding: 12px 15px 12px 45px !important; font-size:14px; border-radius:8px; outline:none;">
                        <i class="fa-solid fa-shield-keyhole" style="position:absolute; left:15px; top:50%; transform:translateY(-50%); color:var(--danger);"></i>
                        <i class="fa-solid fa-eye pw-eye" onclick="togglePw('head_auth_pw', this)" style="position:absolute; right:15px; top:50%; transform:translateY(-50%); color:var(--danger); cursor:pointer;"></i>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:15px;">
                <button type="button" class="btn" style="flex:1; background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); padding:15px; font-size:15px; border-radius:12px; font-weight:700; transition:0.3s;" onclick="document.getElementById('headEditGradeModal').classList.remove('active')" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='var(--input-bg)'">Cancel</button>
                <button type="submit" name="approve_edit_grade" class="glow-btn" style="flex:2; background:linear-gradient(135deg, #f59e0b, #d97706); box-shadow:0 8px 25px rgba(245, 158, 11, 0.4); color:#fff; padding:15px; font-size:15px; border-radius:12px; font-weight:800; border:none; cursor:pointer; transition:0.3s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'"><i class="fa-solid fa-save"></i> Save & Resolve Grades</button>
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
    // JS functions 
    const themeIcon = document.getElementById('theme-icon');
    function toggleTheme() { document.body.classList.toggle('light-mode'); const isLight = document.body.classList.contains('light-mode'); localStorage.setItem('eplms_theme', isLight ? 'light' : 'dark'); if(themeIcon) themeIcon.className = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon'; }
    if(localStorage.getItem('eplms_theme') === 'light'){ document.body.classList.add('light-mode'); if(themeIcon) themeIcon.className = 'fa-solid fa-sun'; }
    
    function animateCounters() { document.querySelectorAll('.counter').forEach(counter => { counter.innerText = '0'; const target = +counter.getAttribute('data-target'); const inc = target / 30; const update = () => { const c = +counter.innerText; if(c < target) { counter.innerText = Math.ceil(c + inc); setTimeout(update, 30); } else { counter.innerText = target; } }; update(); }); }
    animateCounters(); 

    function openTab(tabId) { document.querySelectorAll('.section-tab').forEach(el=>el.classList.remove('active')); document.querySelectorAll('.tab-link').forEach(el=>el.classList.remove('active')); document.getElementById(tabId).classList.add('active'); event.currentTarget.classList.add('active'); if(tabId === 'home') { animateCounters(); if(window.deptDChart) window.deptDChart.update(); } }
    
    function updateClock() { const now = new Date(); let h = now.getHours(); let m = now.getMinutes(); let s = now.getSeconds(); document.getElementById('real-time-clock').innerText = `${h%12||12}:${m<10?'0'+m:m}:${s<10?'0'+s:s} ${h>=12?'PM':'AM'}`; } setInterval(updateClock, 1000); updateClock();
    
   function confirmDelete(type, id, name) { 
        document.getElementById('del_name_display').innerText = name;
        document.getElementById('del_teacher_id').value = '';
        document.getElementById('del_student_id').value = '';
        
        let btn = document.getElementById('btn_real_delete');
        if(type==='teacher'){ 
            document.getElementById('del_teacher_id').value=id; 
            btn.name = 'soft_delete_teacher';
        } else if(type === 'student') {
            document.getElementById('del_student_id').value=id;
            btn.name = 'soft_delete_student';
        }
        document.getElementById('secureDeleteModal').classList.add('active'); 
    }

    function editStudent(id, fname, lname, idnum, email, phone) {
        document.getElementById('edit_s_id').value = id;
        document.getElementById('edit_s_fname').value = fname;
        document.getElementById('edit_s_lname').value = lname;
        document.getElementById('edit_s_id_num').value = idnum;
        document.getElementById('edit_s_email').value = email;
        document.getElementById('edit_s_phone').value = phone;
        document.getElementById('editStudentModal').classList.add('active');
    }

    function filterStudents() {
        let input = document.getElementById('student_search').value.toLowerCase();
        let rows = document.querySelectorAll('.student-row');
        rows.forEach(row => {
            let text = row.innerText.toLowerCase();
            row.style.display = text.indexOf(input) > -1 ? "" : "none";
        });
    }
    
    let breadcrumbTrail =[{ id: 'lvl1', title: 'All Teachers' }];
    function resetOversight() { navToLevel('lvl1', 'Teachers', null, true); }
    function navToLevel(viewId, title, element = null, isReset = false) {
        document.querySelectorAll('.oversight-view').forEach(el => el.classList.remove('active')); 
        const tgt = document.getElementById('view-' + viewId); if(tgt) tgt.classList.add('active');
        if(isReset) breadcrumbTrail =[{ id: viewId, title: 'All Teachers' }]; 
        else if(element && element.classList.contains('bc-item')) { const idx = breadcrumbTrail.findIndex(item => item.id === viewId); breadcrumbTrail = breadcrumbTrail.slice(0, idx + 1); }
        else breadcrumbTrail.push({ id: viewId, title: title });
        let bcHTML = ''; breadcrumbTrail.forEach((item, index) => { if(index>0) bcHTML += `<span class="bc-separator"><i class="fa-solid fa-chevron-right"></i></span>`; const isActive = (index === breadcrumbTrail.length - 1) ? 'active' : ''; bcHTML += `<span class="bc-item ${isActive}" onclick="navToLevel('${item.id}', '${item.title}', this)">${item.title}</span>`; }); document.getElementById('oversight-breadcrumbs').innerHTML = bcHTML;
    }
    
    function switchInnerTab(tabName, btnElement) { document.querySelectorAll('.inner-tab-btn').forEach(btn => btn.classList.remove('active')); document.querySelectorAll('.inner-tab-content').forEach(content => content.classList.remove('active')); btnElement.classList.add('active'); document.getElementById('inner-' + tabName).classList.add('active'); }
    function previewImage(input) { if(input.files && input.files[0]){ let reader = new FileReader(); reader.onload = function(e){ document.getElementById('preview_avatar_top').src = e.target.result; }; reader.readAsDataURL(input.files[0]); } }

    // Telegram Chat
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
                } else if(role === 'admin') {
                    imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#34d399; background:#fff; border-radius:50%; font-size:14px; border:2px solid var(--panel-bg); z-index:10;"></i>`;
                }
                avatarDiv.innerHTML = imgHtml; 
                avatarDiv.style.background = 'transparent'; 
            } else { 
                // Fallback (just in case)
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
    }    function fetchChatMessages() { if(currentChatId === null) return; let fd=new FormData(); fd.append('ajax_action','fetch_chat'); fd.append('receiver_id',currentChatId); fd.append('receiver_role',currentChatRole); fd.append('is_group',currentChatIsGroup); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.text()).then(h=>{ const chatHistory = document.getElementById('chat-history-container'); let isAtBottom = chatHistory.scrollHeight - chatHistory.clientHeight <= chatHistory.scrollTop + 50; chatHistory.innerHTML=h; if(isAtBottom) chatHistory.scrollTop = chatHistory.scrollHeight; }); }
    function submitTelegramMsg(e) { e.preventDefault(); let input=document.getElementById('chat_message_input'); if(!input.value.trim())return; let fd=new FormData(document.getElementById('tg-chat-form')); fd.append('ajax_action', document.getElementById('edit_msg_id').value ? 'edit_msg' : 'send_msg'); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.status==='success'){ input.value=''; document.getElementById('edit_msg_id').value=''; fetchChatMessages(); setTimeout(() => { const chatHistory = document.getElementById('chat-history-container'); chatHistory.scrollTop = chatHistory.scrollHeight; }, 100); }}); }
    
    function fetchUnreadBadges() { let fd = new FormData(); fd.append('ajax_action', 'fetch_unread'); fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(data=>{ document.querySelectorAll('.chat-unread-badge').forEach(b => b.style.display = 'none'); for(let key in data) { if(key !== 'total_all') { let badge = document.getElementById('badge_' + key); if(badge) { if(currentChatRole + '_' + currentChatId !== key) { badge.innerText = data[key]; badge.style.display = 'inline-block'; } else { fetchChatMessages(); } } } } let mainBadge = document.getElementById('main_comm_badge'); if(mainBadge) { if(data.total_all > 0) { mainBadge.innerText = data.total_all; mainBadge.style.display = 'inline-block'; mainBadge.style.position = 'absolute'; mainBadge.style.right = '15px'; mainBadge.style.top = '50%'; } else { mainBadge.style.display = 'none'; } } }).catch(err => console.log(err)); // 🪄 PENDING STUDENT BADGE LOGIC
            let stuBadge = document.getElementById('badge_students');
            if(stuBadge) {
                if(data.pending_students > 0) {
                    stuBadge.innerText = data.pending_students;
                    stuBadge.style.display = 'inline-block';
                } else {
                    stuBadge.style.display = 'none';
                }
            }} setInterval(fetchUnreadBadges, 2000); fetchUnreadBadges(); 

    function deleteMessage(msgId) { if(!confirm("Are you sure you want to delete this message?")) return; let fd = new FormData(); fd.append('ajax_action', 'delete_msg'); fd.append('msg_id', msgId); fetch(window.location.href, { method: 'POST', body: fd }).then(res => res.json()).then(data => { if(data.status === 'success') fetchChatMessages(); }); }
    function editMessage(msgId, text) { document.getElementById('chat_message_input').value = text; document.getElementById('edit_msg_id').value = msgId; document.getElementById('chat_message_input').focus(); }

    let ctxMenuMsgId = null; let ctxMenuMsgText = "";
    function showContextMenu(e, msgId, msgText) { e.preventDefault(); const ctxMenu = document.getElementById('chat-context-menu'); ctxMenuMsgId = msgId; ctxMenuMsgText = msgText; ctxMenu.style.display = 'block'; let x = e.pageX; let y = e.pageY; if(x + ctxMenu.offsetWidth > window.innerWidth) x = window.innerWidth - ctxMenu.offsetWidth - 10; if(y + ctxMenu.offsetHeight > window.innerHeight) y = window.innerHeight - ctxMenu.offsetHeight - 10; ctxMenu.style.left = x + 'px'; ctxMenu.style.top = y + 'px'; }
    document.addEventListener('click', function(e) { const ctxMenu = document.getElementById('chat-context-menu'); if(ctxMenu.style.display === 'block') ctxMenu.style.display = 'none'; });
    document.getElementById('ctx-edit').addEventListener('click', function() { if(ctxMenuMsgId) editMessage(ctxMenuMsgId, ctxMenuMsgText); });
    document.getElementById('ctx-delete').addEventListener('click', function() { if(ctxMenuMsgId) deleteMessage(ctxMenuMsgId); });

    function filterTelegramChats() { let input = document.getElementById('tg-search').value.toLowerCase(); document.querySelectorAll('.tg-contact-item').forEach(item => { let name = item.querySelector('.tg-name').innerText.toLowerCase(); item.style.display = name.indexOf(input) > -1 ? "flex" : "none"; }); }

    function togglePw(id, icon) { let input = document.getElementById(id); if (input.type === "password") { input.type = "text"; icon.classList.remove("fa-eye"); icon.classList.add("fa-eye-slash"); } else { input.type = "password"; icon.classList.remove("fa-eye-slash"); icon.classList.add("fa-eye"); } }
   function checkPasswordStrength() { 
        let pw = document.getElementById('new_pass').value; 
        let rulesBox = document.getElementById('pw-rules'); 
        
        if (pw.length > 0) {
            rulesBox.style.display = 'block'; 
        } else {
            rulesBox.style.display = 'none'; 
        }
        
        // 1. Length >= 8
        updateRule('rule-length', pw.length >= 8); 
        
        // 2. Uppercase & Lowercase
        let hasUpper = /[A-Z]/.test(pw);
        let hasLower = /[a-z]/.test(pw);
        updateRule('rule-upper', hasUpper && hasLower); 
        
        // 3. Number
        let hasNumber = /[0-9]/.test(pw);
        updateRule('rule-number', hasNumber); 
        
        // 4. Special Character
        let hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(pw);
        updateRule('rule-special', hasSpecial);
    }
    
    function updateRule(id, isValid) { 
        let el = document.getElementById(id); 
        if(!el) return;
        let icon = el.querySelector('i'); 
        
        if(isValid) { 
            // Magariisa (Green) godha
            el.style.color = '#10b981';
            icon.className = 'fa-solid fa-circle-check'; 
            icon.style.color = '#10b981';
        } else { 
            // Diimaa (Red) godha
            el.style.color = '#f43f5e';
            icon.className = 'fa-solid fa-circle-xmark'; 
            icon.style.color = '#f43f5e';
        } 
    }
    function toggleHelpAcc(btn) { const item = btn.parentElement; const content = btn.nextElementSibling; item.classList.toggle('active'); content.style.display = item.classList.contains('active') ? "block" : "none"; }
    function searchHelpTopics() { let input = document.getElementById('help-search-input').value.toLowerCase(); document.querySelectorAll('.help-accordion-item').forEach(item => { let text = item.innerText.toLowerCase(); item.style.display = text.indexOf(input) > -1 ? "block" : "none"; }); }

    setTimeout(() => { let alert = document.querySelector('.alert'); if(alert) alert.style.display = 'none'; }, 4000);

    const ctxDept = document.getElementById('deptDemographicsChart');
    if(ctxDept) {
        window.deptDChart = new Chart(ctxDept.getContext('2d'), {
            type: 'doughnut',
            data: { 
                labels:['Teachers', 'Active Students', 'Pending Students', 'Courses'], 
                datasets:[{ data:[<?php echo $teachers_count; ?>, <?php echo $students_count; ?>, <?php echo $pending_students; ?>, <?php echo $courses_count; ?>], backgroundColor:['#10b981', '#ef4444', '#f59e0b', '#3b82f6'], borderWidth: 0, hoverOffset: 10 }] 
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right', labels: { color: '#848e9c', font: { family: 'Inter' } } } }, animation: { animateScale: true, animateRotate: true } }
        });
    }
    // Edit Schedule Magic Function
    function editSchedule(id, uni, year, sem, day, course, teacher, time, type, room) {
        document.getElementById('form_schedule_id').value = id;
        document.getElementById('form_uni_name').value = uni;
        document.getElementById('form_study_year').value = year;
        document.getElementById('form_semester').value = sem;
        document.getElementById('form_day').value = day;
        document.getElementById('form_course').value = course;
        document.getElementById('form_teacher').value = teacher;
        document.getElementById('form_time').value = time;
        document.getElementById('form_type').value = type;
        document.getElementById('form_room').value = room;
        
        let btn = document.getElementById('btn_save_schedule');
        btn.innerHTML = '<i class="fa-solid fa-pen"></i> Update Schedule';
        btn.style.background = 'linear-gradient(135deg, #3b82f6, #1d4ed8)';
        btn.style.boxShadow = '0 5px 15px rgba(59, 130, 246, 0.4)';
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function openEditGradeModal(id, name, att, ass, proj, quiz, mid, fin) {
        document.getElementById('head_edit_g_id').value = id;
        document.getElementById('head_edit_stu_name').innerText = name;
        document.getElementById('h_att').value = att; document.getElementById('h_ass').value = ass;
        document.getElementById('h_proj').value = proj; document.getElementById('h_quiz').value = quiz;
        document.getElementById('h_mid').value = mid; document.getElementById('h_fin').value = fin;
        document.getElementById('headEditGradeModal').classList.add('active');
    }
    // 🪄 MAGIC: AJAX Student Approval Function
    function approveStudentAjax(studentId) {
        let btn = document.getElementById('btn_approve_' + studentId);
        if(!btn) return;
        
        btn.innerHTML = "<i class='fa-solid fa-circle-notch fa-spin'></i>";
        btn.disabled = true;
        btn.style.opacity = "0.7";

        let fd = new FormData();
        fd.append('ajax_action', 'approve_student_ajax');
        fd.append('student_id', studentId);

        fetch(window.location.href, {method: 'POST', body: fd})
        .then(response => response.json())
        .then(data => {
            if(data.status === 'success') {
                btn.innerHTML = "<i class='fa-solid fa-check-double'></i>";
                btn.style.background = "#10b981";
                btn.style.color = "#fff";
                
                setTimeout(() => {
                    let row = btn.closest('tr');
                    if(row) {
                        row.style.transition = "0.5s";
                        row.style.opacity = "0";
                        row.style.transform = "translateX(20px)";
                        setTimeout(() => { row.remove(); fetchUnreadBadges(); }, 500);
                    }
                }, 800);
                
                alert("Student Approved! Username and Password sent to their email instantly.");
                
            } else {
                alert("Error approving student. Please try again.");
                btn.innerHTML = "<i class='fa-solid fa-check'></i>";
                btn.disabled = false;
                btn.style.opacity = "1";
            }
        }).catch(err => {
            console.log(err);
            alert("Network error occurred.");
            btn.innerHTML = "<i class='fa-solid fa-check'></i>";
            btn.disabled = false;
        });
    }
</script>
</body>
</html>