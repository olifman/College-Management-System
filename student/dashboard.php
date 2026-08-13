<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include("../includes/config.php");

// ========================================================
// 🛡️ 1. SECURITY: STUDENT AUTHENTICATION
// ========================================================
if(!isset($_SESSION['username']) || $_SESSION['role'] != 'student'){
    header("Location: ../index.php");
    exit();
}

$student_name = $_SESSION['username'];
$student_id = $_SESSION['user_id'];
$dept_id = $_SESSION['dept_id'];
$message = ""; $msg_type = "success";

date_default_timezone_set('Africa/Addis_Ababa');

// Fetch Student Profile & Dept/College Info
$stu_info_q = mysqli_query($conn, "SELECT s.*, d.dept_name, d.college_id, c.college_name FROM student s JOIN departments d ON s.dept_id = d.id JOIN colleges c ON d.college_id = c.id WHERE s.id=$student_id");
$stu_info = mysqli_fetch_assoc($stu_info_q);
$college_id = $stu_info['college_id'];
$profile_pic = !empty($stu_info['profile_pic']) && file_exists("../uploads/".$stu_info['profile_pic']) ? "../uploads/".$stu_info['profile_pic'] : "https://ui-avatars.com/api/?name=".urlencode($stu_info['first_name'] . ' ' . $stu_info['last_name'])."&background=f43f5e&color=fff";
$full_name = htmlspecialchars($stu_info['first_name'] . ' ' . $stu_info['last_name']);

// Folders Setup
if(!is_dir('../uploads/submissions')) { mkdir('../uploads/submissions', 0777, true); }
// 🪄 MAGIC: Column haaraa Settings barataa uumuu
$chk_2fa = mysqli_query($conn, "SHOW COLUMNS FROM student LIKE 'two_factor_enabled'");
if(mysqli_num_rows($chk_2fa) == 0) {
    mysqli_query($conn, "ALTER TABLE student ADD COLUMN two_factor_enabled TINYINT(1) DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE student ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE student ADD COLUMN login_alerts TINYINT(1) DEFAULT 1");
    mysqli_query($conn, "ALTER TABLE student ADD COLUMN profile_locked TINYINT(1) DEFAULT 0");
}
// ========================================================
// 🚀 2. REAL-TIME CHAT AJAX API (STUDENT SCOPE)
// ========================================================
if(isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];
    
   if($action == 'send_msg') {
        $msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        $rec_id = intval($_POST['chat_receiver_id']);
        $rec_role = mysqli_real_escape_string($conn, $_POST['chat_receiver_role']);
        $is_group = isset($_POST['chat_is_group']) ? intval($_POST['chat_is_group']) : 0;
        
        if(!empty($msg)) {
            if($is_group == 1 && isset($stu_info['is_rep']) && $stu_info['is_rep'] == 1) {
                // 🪄 CLASS REP BROADCAST TO STUDENTS
                $users = mysqli_query($conn, "SELECT id FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 AND id!=$student_id");
                while($u = mysqli_fetch_assoc($users)) {
                    mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($student_id, 'student', {$u['id']}, 'student', '$msg', 0, 0)");
                }
                // Record the group message for the sender (Rep)
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($student_id, 'student', 0, 'student', '📢 REP ANNOUNCEMENT: $msg', 1, 1)");
            } else {
                // Normal Private Message (To Teacher, Head, or Classmate)
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($student_id, 'student', $rec_id, '$rec_role', '$msg', 0, 0)");
            }
        }
        echo json_encode(['status'=>'success']); exit();
    }
    
    if($action == 'edit_msg') {
        $msg_id = intval($_POST['msg_id']); $new_msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        mysqli_query($conn, "UPDATE messages SET is_edited=1, message='$new_msg' WHERE id=$msg_id AND sender_id=$student_id AND sender_role='student'");
        echo json_encode(['status'=>'success']); exit();
    }
    if($action == 'delete_msg') {
        $msg_id = intval($_POST['msg_id']);
        mysqli_query($conn, "UPDATE messages SET is_deleted=1 WHERE id=$msg_id AND sender_id=$student_id AND sender_role='student'");
        echo json_encode(['status'=>'success']); exit();
    }  
if($action == 'fetch_unread') {
        // 1. Chat Unread Messages
        $q = mysqli_query($conn, "SELECT sender_id, sender_role, is_group, COUNT(*) as c FROM messages WHERE ((receiver_id=$student_id AND receiver_role='student' AND is_group=0) OR (receiver_role='student' AND is_group=1)) AND is_read=0 GROUP BY sender_id, sender_role, is_group");
        $data =[]; $total = 0;
        while($r = mysqli_fetch_assoc($q)){ 
            if($r['is_group'] == 1) { $key = 'group_' . $r['sender_role'] . '_' . $r['sender_id']; } 
            else { $key = $r['sender_role'] . '_' . $r['sender_id']; }
            $data[$key] = $r['c']; $total += $r['c']; 
        }
        $data['total_all'] = $total; 
        
        $mat_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM materials m WHERE m.type NOT IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.is_new=1 AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id)");
        $data['new_materials'] = mysqli_fetch_assoc($mat_q)['c'] ?? 0;

        $ass_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM materials m WHERE m.type IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.id NOT IN (SELECT material_id FROM submissions WHERE student_id=$student_id) AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id)");
        $data['new_assignments'] = mysqli_fetch_assoc($ass_q)['c'] ?? 0;

        $ex_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM exams e JOIN teacher t ON e.teacher_id=t.id WHERE t.dept_id=$dept_id AND e.is_deleted=0 AND e.id NOT IN (SELECT exam_id FROM exam_results WHERE student_id=$student_id)");
        $data['new_exams'] = mysqli_fetch_assoc($ex_q)['c'] ?? 0;

        echo json_encode($data); exit();
    }

    if($action == 'mark_tab_read') {
        $tab = $_POST['tab_name'];
        if($tab == 'courses') {
            mysqli_query($conn, "UPDATE materials SET is_new=0 WHERE type NOT IN ('assignment', 'project') AND course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id)");
        } 
        echo json_encode(['status'=>'success']); exit();
    }

    if($action == 'mark_tab_read') {
        $tab = $_POST['tab_name'];
        
        $dept_courses_sql = "SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id";
        
        if($tab == 'courses') {
            mysqli_query($conn, "UPDATE materials SET is_new=0 WHERE type NOT IN ('assignment', 'project') AND is_new=1 AND course_id IN ($dept_courses_sql)");
        } elseif($tab == 'assignments') {
            mysqli_query($conn, "UPDATE materials SET is_new=0 WHERE type IN ('assignment', 'project') AND is_new=1 AND course_id IN ($dept_courses_sql)");
        } elseif($tab == 'exams') {
            mysqli_query($conn, "UPDATE exams SET is_new=0 WHERE is_new=1 AND teacher_id IN (SELECT id FROM teacher WHERE dept_id=$dept_id)");
        }
        echo json_encode(['status'=>'success']); exit();
    }
    
    if($action == 'mark_submissions_read') {
        mysqli_query($conn, "UPDATE submissions s JOIN materials m ON s.material_id = m.id SET s.is_new=0 WHERE m.teacher_id=$teacher_id AND s.is_new=1");
        echo json_encode(['status'=>'success']); exit();
    }
    
    if($action == 'fetch_chat') {
        $rec_id = intval($_POST['receiver_id']); $rec_role = mysqli_real_escape_string($conn, $_POST['receiver_role']); $is_group = intval($_POST['is_group']);
        
        if($is_group == 0) mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$student_id AND receiver_role='student'");
        elseif($is_group == 1) mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE sender_id=$rec_id AND sender_role='$rec_role' AND receiver_role='student' AND is_group=1");
        
        $query = ($is_group == 1) 
            ? "SELECT * FROM messages WHERE is_group=1 AND receiver_role='student' AND sender_role='$rec_role' AND sender_id=$rec_id ORDER BY sent_at ASC" 
            : "SELECT * FROM messages WHERE is_group=0 AND ((sender_id=$student_id AND sender_role='student' AND receiver_id=$rec_id AND receiver_role='$rec_role') OR (sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$student_id AND receiver_role='student')) ORDER BY sent_at ASC";
        
        $res = mysqli_query($conn, $query);
        if(mysqli_num_rows($res) == 0) { echo "<div class='tg-placeholder'><i class='fa-solid fa-lock'></i><p>End-to-end encrypted chat.</p></div>"; exit(); }

        $html = '';
        while($m = mysqli_fetch_assoc($res)){
            $is_me = ($m['sender_role'] == 'student' && $m['sender_id'] == $student_id);
            $align = $is_me ? 'chat-right' : 'chat-left';
            $time = date("M d, H:i", strtotime($m['sent_at']));
            $msg_text = nl2br(htmlspecialchars($m['message']));
            $status = '';
            
            if($m['is_deleted'] == 1) { $msg_text = "<i style='color:var(--danger); opacity:0.8;'><i class='fa-solid fa-ban'></i> This message was deleted</i>"; $status = "<span style='color:var(--danger);'>Deleted</span>"; } 
            elseif($m['is_edited'] == 1) { $status = "<span style='opacity:0.6;'><i class='fa-solid fa-pen'></i> Edited</span>"; }

            $oncontext = ($is_me && $m['is_deleted'] == 0 && $is_group == 0) ? "oncontextmenu='showContextMenu(event, {$m['id']}, \"".htmlspecialchars($m['message'], ENT_QUOTES)."\"); return false;'" : "";

            $html .= "<div class='chat-msg-wrapper {$align}'><div class='chat-bubble' {$oncontext} style='cursor: context-menu;'><div class='chat-text'>{$msg_text}</div><div class='chat-meta'>{$time} {$status}</div></div></div>";
        }
        echo $html; exit();
    }
}
// ========================================================
// 📝 3. ASSIGNMENT & PROJECT SUBMISSION LOGIC (100% FIXED)
// ========================================================
// 1. Dura Database keessatti Table 'submissions' uumna (Yoo hin jirre)
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    student_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    status VARCHAR(50) DEFAULT 'submitted',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$chk_grade = mysqli_query($conn, "SHOW COLUMNS FROM submissions LIKE 'grade'");
if(mysqli_num_rows($chk_grade) == 0) {
    mysqli_query($conn, "ALTER TABLE submissions ADD COLUMN grade VARCHAR(20) DEFAULT NULL");
}
// 🪄 MAGIC: Notification Tracking for New Materials & Exams
$chk_new_mat = mysqli_query($conn, "SHOW COLUMNS FROM materials LIKE 'is_new'");
if(mysqli_num_rows($chk_new_mat) == 0) mysqli_query($conn, "ALTER TABLE materials ADD COLUMN is_new TINYINT(1) DEFAULT 1");

$chk_new_exam = mysqli_query($conn, "SHOW COLUMNS FROM exams LIKE 'is_new'");
if(mysqli_num_rows($chk_new_exam) == 0) mysqli_query($conn, "ALTER TABLE exams ADD COLUMN is_new TINYINT(1) DEFAULT 1");
// 2. Folder uumna
if(!is_dir('../uploads/submissions')) { mkdir('../uploads/submissions', 0777, true); }
// 🪄 MAGIC: Column Title (Mata-duree) yoo hin jirre ofumaan akka dabalu gochuu
$chk_title = mysqli_query($conn, "SHOW COLUMNS FROM submissions LIKE 'sub_title'");
if(mysqli_num_rows($chk_title) == 0) {
    mysqli_query($conn, "ALTER TABLE submissions ADD COLUMN sub_title VARCHAR(255) DEFAULT 'My Assignment'");
}
// 3. Logic Faayila Erguu (Submit)
if(isset($_POST['submit_assignment'])) {
    $mat_id = intval($_POST['material_id']);
$raw_title = isset($_POST['sub_title']) ? $_POST['sub_title'] : 'My Assignment';
$sub_title = mysqli_real_escape_string($conn, trim($raw_title));    
    $chk = mysqli_query($conn, "SELECT id FROM submissions WHERE material_id=$mat_id AND student_id=$student_id");
    if(mysqli_num_rows($chk) > 0) {
        $message = "You have already submitted this task!"; $msg_type = "warning";
    } else {
        if(isset($_FILES['sub_file']) && $_FILES['sub_file']['error'] == 0) {
            $ext = pathinfo($_FILES['sub_file']['name'], PATHINFO_EXTENSION);
            $allowed =['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'txt'];
            
            if(in_array(strtolower($ext), $allowed)){
                $file_name = "sub_" . $student_id . "_" . $mat_id . "_" . time() . "." . $ext;
                $target_path = "../uploads/submissions/" . $file_name;
                
                if(move_uploaded_file($_FILES['sub_file']['tmp_name'], $target_path)){
                    // Insert into DB with Title
                    $insert_sql = "INSERT INTO submissions (material_id, student_id, sub_title, file_path, status, submitted_at) VALUES ($mat_id, $student_id, '$sub_title', '$file_name', 'submitted', NOW())";
                    
                    if(mysqli_query($conn, $insert_sql)) {
                      echo "<script>window.location.href='dashboard.php?tab=assignments&msg=submitted';</script>";
                        exit();
                    }
                } else { $message = "Failed to upload file."; $msg_type = "error"; }
            } else { $message = "Invalid file format! (PDF, Word, Images, ZIP only)"; $msg_type = "error"; }
        } else { $message = "Please attach a valid file!"; $msg_type = "error"; }
    }}
// ========================================================
// ⚙️ 4. STUDENT SETTINGS (Profile Update & Sync)
// ========================================================
// 🪄 MAGIC: Unified Message Handler for Student Dashboard
if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'submitted') { $message = "Work Submitted Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'updated') { 
        $message = "Security & Profile Settings Updated Successfully!"; 
        $msg_type = "success"; 
        echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
    }
}

if(isset($_POST['save_settings'])) {
    
    // 🪄 MAGIC: Fetch all inputs correctly
    $email = mysqli_real_escape_string($conn, trim($_POST['s_email']));
    $username = mysqli_real_escape_string($conn, trim($_POST['s_username']));
    $phone = mysqli_real_escape_string($conn, trim($_POST['s_phone']));
    
    // Security Toggles
    $two_factor = isset($_POST['two_factor']) ? 1 : 0;
    $login_alerts = isset($_POST['login_alerts']) ? 1 : 0;
    $profile_locked = isset($_POST['profile_locked']) ? 1 : 0;

    $current_pass = $_POST['current_password'];
    $new_pass = trim($_POST['new_password']);

    // Verify current password first
    $verify = mysqli_query($conn, "SELECT id FROM student WHERE id=$student_id AND password='$current_pass'");
    
    if(mysqli_num_rows($verify) > 0){
        
        // Profile Pic Logic
        if(isset($_FILES['profile_pic']['name']) && !empty($_FILES['profile_pic']['name'])){
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $file_name = "stu_" . $student_id . "_" . time() . "." . $ext;
            if(move_uploaded_file($_FILES['profile_pic']['tmp_name'], "../uploads/" . $file_name)){
                mysqli_query($conn, "UPDATE student SET profile_pic='$file_name' WHERE id=$student_id");
            }
        }
        
        // 🪄 FIXED: Password update logic without breaking SQL syntax
        if(!empty($new_pass)) {
            $sql = "UPDATE student SET email='$email', username='$username', phone='$phone', password='$new_pass', two_factor_enabled=$two_factor, login_alerts=$login_alerts, profile_locked=$profile_locked, updated_at=NOW() WHERE id=$student_id";
        } else {
            $sql = "UPDATE student SET email='$email', username='$username', phone='$phone', two_factor_enabled=$two_factor, login_alerts=$login_alerts, profile_locked=$profile_locked, updated_at=NOW() WHERE id=$student_id";
        }
        
        // Execute the Update
        if(mysqli_query($conn, $sql)){
            $_SESSION['username'] = $username; // Update session if username changed
            header("Location: dashboard.php?tab=settings&msg=updated"); 
            exit();
        } else { 
            $message = "Database Error: Username or Email might already be taken by someone else!"; 
            $msg_type = "error"; 
            echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
        }
    } else { 
        $message = "Authentication Failed: Incorrect Current Password!"; 
        $msg_type = "error"; 
        echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
    }
}
// ========================================================
// 📊 5. FETCH LIVE DASHBOARD DATA & CALENDAR
// ========================================================
// 1. Courses list for the student
$c_q = mysqli_query($conn, "SELECT DISTINCT c.id, c.course_name, c.course_code FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id AND c.is_deleted=0 AND t.is_deleted=0 AND t.status='active'");
$my_courses_count = mysqli_num_rows($c_q);
// 2. Fetch Study Materials Count (PDF, PPT, Video, etc.)
$total_materials = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM materials m WHERE m.type NOT IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id)"))['c'];
// 3. Fetch Assignments & Projects Count
$total_assignments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM materials m WHERE m.type IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id)"))['c'];
// 5. Upcoming & Live Exams (Not taken yet, and time not expired)
$upcoming_exams = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM exams e JOIN teacher t ON e.teacher_id=t.id WHERE t.dept_id=$dept_id AND e.is_deleted=0 AND e.id NOT IN (SELECT exam_id FROM exam_results WHERE student_id=$student_id) AND DATE_ADD(e.start_time, INTERVAL e.duration_mins MINUTE) > NOW()"))['c'];
// 4. Pending & Completed Tasks
$pending_tasks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM materials m WHERE m.type IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.id NOT IN (SELECT material_id FROM submissions WHERE student_id=$student_id) AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id)"))['c'];
$completed_tasks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM submissions WHERE student_id=$student_id"))['c'];
// 📅 CALENDAR DATA PREP
$calendar_events =[];
$ex_cal_q = mysqli_query($conn, "SELECT e.title, e.start_time, e.exam_type FROM exams e JOIN course c ON e.course_id=c.id JOIN teacher t ON e.teacher_id=t.id WHERE t.dept_id=$dept_id AND e.is_deleted=0");
while($ex = mysqli_fetch_assoc($ex_cal_q)) {
    $calendar_events[] =['title' => $ex['title'], 'date' => date('Y-m-d', strtotime($ex['start_time'])), 'time' => date('h:i A', strtotime($ex['start_time'])), 'type' => 'exam', 'label' => $ex['exam_type']];
}

$mat_cal_q = mysqli_query($conn, "SELECT m.title, m.due_date, m.type FROM materials m JOIN course c ON m.course_id=c.id JOIN teacher t ON m.teacher_id=t.id WHERE t.dept_id=$dept_id AND m.type IN ('assignment', 'project') AND m.due_date IS NOT NULL AND m.is_locked=0");
while($mat = mysqli_fetch_assoc($mat_cal_q)) {
    $calendar_events[] =['title' => $mat['title'], 'date' => date('Y-m-d', strtotime($mat['due_date'])), 'time' => date('h:i A', strtotime($mat['due_date'])), 'type' => $mat['type'], 'label' => ucfirst($mat['type'])];
}
$calendar_events_json = json_encode($calendar_events);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1400"><title>EPLMS - Student Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    
    /* 🪄 MAGIC: STUDENT SETTINGS TOGGLE STYLES */
    .sec-toggle { display: flex; justify-content: space-between; align-items: center; background: var(--input-bg); padding: 18px 25px; border-radius: 14px; border: 1px solid var(--border-color); margin-bottom: 18px; transition: 0.3s;}
    .sec-toggle:hover { border-color: var(--primary); box-shadow: 0 5px 15px var(--primary-glow); }
    .switch { position: relative; display: inline-block; width: 46px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--text-muted); transition: .4s; border-radius: 34px; opacity: 0.5;}
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2);}
    input:checked + .slider { background-color: var(--primary); opacity: 1;}
    input:checked + .slider:before { transform: translateX(22px); }
    /* 🪄 MAGIC CARD VIEW ENHANCEMENTS */
    .course-category-title { font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 15px; display: flex; align-items: center; gap: 10px; border-bottom: 2px dashed rgba(59, 130, 246, 0.2); padding-bottom: 10px; }
    .magic-material-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 35px; }
    .magic-material-card { background: var(--bg-color); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; transition: 0.3s; display: flex; flex-direction: column; box-shadow: 0 4px 15px rgba(0,0,0,0.02); position: relative; overflow: hidden; }
    .magic-material-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; transition: 0.3s; }
    .magic-material-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); border-color: transparent; }
    .magic-material-card:hover::before { width: 100%; opacity: 0.05; }
    /* 🪄 MAGIC VIEW SWITCHER STYLES */
    .view-controls { display: flex; gap: 10px; background: var(--input-bg); padding: 5px; border-radius: 12px; border: 1px solid var(--border-color); }
    .view-btn { padding: 8px 15px; border-radius: 8px; border: none; background: transparent; color: var(--text-muted); cursor: pointer; transition: 0.3s; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .view-btn.active { background: var(--primary); color: #fff; box-shadow: 0 4px 10px var(--primary-glow); }

    /* Accordion Styles */
    .course-accordion { margin-bottom: 15px; border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; background: var(--panel-bg); }
    .acc-header { width: 100%; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; background: transparent; border: none; color: var(--text-main); cursor: pointer; transition: 0.3s; text-align: left; }
    .acc-header:hover { background: rgba(255,255,255,0.02); }
    .acc-header i.chevron { transition: 0.4s; color: var(--text-muted); }
    .course-accordion.active .acc-header i.chevron { transform: rotate(180deg); color: var(--primary); }
    .course-accordion.active { border-color: var(--primary); box-shadow: 0 10px 25px var(--primary-glow); }
    .acc-content { display: none; padding: 0 25px 25px 25px; animation: slideDown 0.3s ease; }
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    /* ======================================================== */
    /* 🎨 STUDENT THEME: RUBY RED & INDIGO (Premium UI)         */
    /* ======================================================== */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    
    :root { 
        --bg-color: #090b0f; --panel-bg: #14161c; --border-color: rgba(255,255,255,0.08); 
        --text-main: #f1f5f9; --text-muted: #94a3b8;
        --primary: #f43f5e; --primary-hover: #e11d48; --primary-glow: rgba(244, 63, 94, 0.25); 
        --danger: #f43f5e; --success: #10b981; --warning: #f59e0b; --input-bg: rgba(0,0,0,0.2);
    }
    body.light-mode {
        --bg-color: #f8fafc; --panel-bg: #ffffff; --border-color: #e2e8f0; 
        --text-main: #1e293b; --text-muted: #64748b;
        --primary: #f43f5e; --primary-hover: #e11d48; --primary-glow: rgba(244, 63, 94, 0.2);
        --danger: #e11d48; --success: #059669; --warning: #d97706; --input-bg: #f1f5f9;
    }
body { background: var(--bg-color); color: var(--text-main); display: flex; height: 100vh; overflow-x: auto; overflow-y: hidden; transition: 0.3s; }
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
.main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; overflow-x: auto; min-width: 1000px; width: 100%; scroll-behavior: smooth;}    .top-header { background: var(--panel-bg); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 9999; min-height: 75px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
    .welcome-section { display: flex; align-items: center; gap: 15px; flex-shrink: 0;}
    .theme-toggle { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 18px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; transition: 0.3s; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
    .theme-toggle:hover { border-color: var(--primary); color: var(--primary); }
    .content-area { padding: 30px; max-width: 100%; overflow-x: hidden; box-sizing: border-box; }
    .section-tab { display: none; animation: fadeIn 0.4s ease; }
    .section-tab.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    .hide-scrollbar::-webkit-scrollbar { display: none; } .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    /* PANELS & FORMS */
    .grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; align-items: start; }
    .premium-panel { background: var(--panel-bg); border-radius: 20px; border: 1px solid var(--border-color); padding: 30px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; overflow: hidden; border-top: 4px solid var(--primary); }
    body:not(.light-mode) .premium-panel { background: linear-gradient(145deg, #14161c, #0e1015); box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
    .premium-panel:hover { box-shadow: 0 15px 40px rgba(0,0,0,0.08); transform: translateY(-3px); }
    .premium-panel::after { content: ''; position: absolute; top:-50px; right:-50px; width:150px; height:150px; background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%); border-radius:50%; pointer-events: none; }
    .panel-title-premium { font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 12px; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; color: var(--text-main); }
    
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: 700; font-size: 12.5px; margin-bottom: 8px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .input-with-icon { position: relative; width: 100%; }
    .input-with-icon i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted); transition: 0.3s; font-size: 16px; z-index: 2; }
    .input-with-icon input, .input-with-icon select { width: 100%; padding: 15px 15px 15px 50px !important; border: 1.5px solid var(--border-color); border-radius: 12px; background: var(--input-bg); color: var(--text-main); font-size: 14.5px; font-weight: 500; outline: none; transition: all 0.3s ease; position: relative; z-index: 1; }
    .input-with-icon input:focus, .input-with-icon select:focus { border-color: var(--primary); background: transparent; box-shadow: 0 0 0 4px var(--primary-glow); }
    .input-with-icon input:focus + i { color: var(--primary); }
    
    .btn { padding: 12px 20px; background: var(--primary); color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s ease; box-shadow: 0 4px 15px var(--primary-glow);}
    .btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px var(--primary-glow); }
    .glow-btn { background: linear-gradient(135deg, var(--primary) 0%, #be123c 100%); color: #fff; padding: 16px 35px; border-radius: 30px; font-size: 15px; font-weight: 800; border: none; cursor: pointer; box-shadow: 0 10px 25px var(--primary-glow); transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
    .glow-btn:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 15px 35px var(--primary-glow); }

    /* BADGES & ALERTS */
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-red { background: rgba(244, 63, 94, 0.1); color: var(--danger); border-color: rgba(244, 63, 94, 0.2); }
    .badge-yellow { background: rgba(245, 158, 11, 0.1); color: var(--warning); border-color: rgba(245, 158, 11, 0.2); }
/* 🪄 MAGIC RED NOTIFICATION BADGE */
    .main-sidebar-badge { background: #f43f5e; color: #fff; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 900; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); box-shadow: 0 0 15px rgba(244, 63, 94, 0.8); animation: pulse-danger 1.5s infinite; border: 1px solid #fff; }
    @keyframes pulse-danger { 0% { box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.8); } 70% { box-shadow: 0 0 0 12px rgba(244, 63, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(244, 63, 94, 0); } }    @keyframes pulse-badge { 0% { transform: scale(1) translateY(-50%); } 50% { transform: scale(1.1) translateY(-50%); } 100% { transform: scale(1) translateY(-50%); } }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
    .alert-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); }
    .alert-error { background: rgba(246, 70, 93, 0.1); color: var(--danger); border: 1px solid rgba(246, 70, 93, 0.3); }

    /* MAGIC CARDS */
    .welcome-banner { display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(244, 63, 94, 0.08) 100%); padding: 35px 40px; border-radius: 16px; border: 1px solid rgba(244, 63, 94, 0.2); margin-bottom: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; overflow: hidden; }
    .welcome-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(244, 63, 94, 0.05) 0%, transparent 60%); animation: rotateBg 20s linear infinite; z-index: 0; }
    @keyframes rotateBg { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .welcome-banner > div { z-index: 1; position: relative; }
    .live-clock-container { background: rgba(0,0,0,0.4); padding: 15px 30px; border-radius: 50px; display: flex; align-items: center; gap: 15px; border: 1px solid var(--primary); backdrop-filter: blur(5px); }
    #real-time-clock { font-size: 26px; font-weight: 800; color: #fff; letter-spacing: 3px; font-family: 'Courier New', Courier, monospace; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px; margin-bottom: 30px; }
    .magic-card { position: relative; overflow: hidden; z-index: 1; padding: 25px; border-radius: 20px; border: 1px solid var(--border-color); background: var(--panel-bg); transition: all 0.4s; box-shadow: 0 8px 20px rgba(0,0,0,0.02); }
    .magic-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px var(--primary-glow); border-color: var(--primary); }
    .magic-card .bg-icon { position: absolute; right: -20px; bottom: -20px; font-size: 110px; opacity: 0.02; transform: rotate(-15deg); transition: 0.5s; z-index: -1; }
    .magic-card:hover .bg-icon { transform: rotate(0deg) scale(1.2); opacity: 0.08; color: var(--primary); }
    .icon-box { width: 55px; height: 55px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; margin-bottom: 18px; box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .magic-card h2 { font-size: 42px; font-weight: 800; margin: 0 0 5px 0; color: var(--text-main); }
    .magic-card p { font-size: 13px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }

    /* TELEGRAM APP STYLES */
    .telegram-app { display: flex; height: 75vh; background: var(--panel-bg); border-radius: 20px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    .tg-sidebar { transition: width 0.3s ease; width: 400px; background: var(--input-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; overflow: hidden; }
    .tg-search-bar { padding: 20px; border-bottom: 1px solid var(--border-color); }
    .tg-search-bar input { width: 100%; padding: 12px 20px; border-radius: 25px; border: 1px solid var(--border-color); background: var(--panel-bg); color: var(--text-main); outline: none;}
    .tg-folders { display: flex; overflow-x: auto; padding: 10px 15px; gap: 8px; border-bottom: 1px solid var(--border-color); }
    .tg-folder { padding: 8px 15px; border-radius: 20px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); cursor: pointer; white-space: nowrap; transition: 0.3s; background: rgba(0,0,0,0.05); }
    .tg-folder.active { background: var(--primary); color: #fff; box-shadow: 0 4px 10px var(--primary-glow); }
    .tg-contacts { flex: 1; overflow-y: auto; }
    .tg-contact-item { display: flex; align-items: center; gap: 15px; padding: 15px 20px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid rgba(255,255,255,0.02); }
    .tg-contact-item:hover, .tg-contact-item.active { background: var(--primary-glow); border-left: 4px solid var(--primary); }
    .tg-avatar { width: 48px; height: 48px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: 800; color: #fff; position: relative; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .tg-avatar.group { border-radius: 14px; }
    .tg-online-dot { position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; background: var(--success); border-radius: 50%; border: 3px solid var(--panel-bg); }
    .tg-info { flex: 1; overflow: hidden; }
    .tg-name { font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; justify-content: space-between; }
    .tg-role { font-size: 12.5px; color: var(--text-muted); display: block; margin-top: 4px; }
    .chat-unread-badge { background: var(--danger); color: #fff; padding: 2px 7px; border-radius: 12px; font-size: 11px; font-weight: bold; display: none; margin-left: auto; box-shadow: 0 4px 10px rgba(244, 63, 94, 0.4); }
    
    .tg-chat-area { flex: 1; display: flex; flex-direction: column; background: url('https://www.transparenttextures.com/patterns/cubes.png'); }
    .tg-chat-header { padding: 15px 25px; background: var(--panel-bg); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); z-index: 10;}
    .tg-chat-title { font-size: 17px; font-weight: 800; color: var(--text-main); }
    .tg-chat-history { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
    .tg-placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: var(--text-muted); opacity: 0.6; }
    .tg-chat-input-area { padding: 20px 25px; background: var(--panel-bg); border-top: 1px solid var(--border-color); }
    .tg-chat-form { display: flex; gap: 15px; align-items: center; background: var(--input-bg); padding: 8px 8px 8px 25px; border-radius: 30px; border: 1px solid var(--border-color); transition: 0.3s;}
    .tg-chat-form input { flex: 1; background: transparent; border: none; color: var(--text-main); font-size: 15px; outline: none; }
    .tg-chat-form button { width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), #be123c); border: none; color: #fff; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; font-size: 18px; box-shadow: 0 4px 10px var(--primary-glow);}
    
    .chat-msg-wrapper { display: flex; margin-bottom: 15px; width: 100%; position: relative; }
    .chat-right { justify-content: flex-end; margin-bottom: 15px; display:flex;}
    .chat-left { justify-content: flex-start; margin-bottom: 15px; display:flex;}
    .chat-bubble { max-width: 75%; padding: 14px 18px; border-radius: 20px; line-height: 1.5; font-size: 14.5px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);}
    .chat-right .chat-bubble { background: linear-gradient(135deg, var(--primary), #be123c); color: #fff; border-bottom-right-radius: 4px; }
    .chat-left .chat-bubble { background: var(--panel-bg); color: var(--text-main); border-bottom-left-radius: 4px; border: 1px solid var(--border-color); }
    .chat-meta { font-size: 10px; opacity: 0.7; margin-top: 8px; display: flex; justify-content: space-between; gap: 15px; font-weight: 600;}

    /* MODALS & CONTEXT MENU */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
    .modal-overlay.active { display: flex; }
    .modal-box { background: var(--panel-bg); padding: 35px; border-radius: 24px; width: 90%; max-width: 450px; border: 1px solid var(--border-color); text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); animation: zoomIn 0.3s ease; }
    @keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .chat-context-menu { display: none; position: fixed; z-index: 10000; width: 200px; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); padding: 8px; animation: zoomIn 0.2s ease;}
    .context-item { padding: 12px 18px; font-size: 14px; font-weight: 600; color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 12px; border-radius: 10px; transition: 0.2s;}
    .context-item:hover { background: var(--input-bg); color: var(--primary); }
/* 🪄 HELP CENTER & ABOUT STYLES */
    .help-hero { background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); border-radius: 20px; padding: 50px 30px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin-bottom: 30px; border-bottom: 5px solid #ec4899; }
    .help-hero::before { content: ''; position: absolute; top: -50px; left: -50px; width: 250px; height: 250px; background: radial-gradient(circle, rgba(236,72,153,0.15) 0%, transparent 70%); border-radius: 50%; filter: blur(30px); }
    .help-accordion-item { background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden; transition:0.3s; }
    .help-accordion-item.active { border-color:var(--primary); box-shadow:0 5px 20px var(--primary-glow); }
    .help-acc-btn { width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
    .help-acc-content { padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px; }
    /* PROFILE UI */
    .profile-header-card { background: linear-gradient(135deg, #be123c 0%, #881337 100%); border-radius: 24px; padding: 45px 20px; text-align: center; position: relative; margin-bottom: 35px; border-bottom: 5px solid var(--primary); box-shadow: 0 15px 40px var(--primary-glow); overflow: hidden; }
    .profile-avatar-large { width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 5px solid #fecdd3; margin-bottom: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
</style>
</head>
<body>

<aside class="sidebar" id="main-sidebar">
    <div class="sidebar-header">
        <i class="fa-solid fa-graduation-cap" style="color:var(--primary);"></i> <h2>STUDENT</h2>
    </div>
    
   

    <ul class="nav-links">
        <li><button class="tab-link active" onclick="openTab('home')"><i class="fa-solid fa-chart-pie icon"></i> <span>Control Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('schedule')"><i class="fa-regular fa-calendar-days icon" style="color: #f59e0b;"></i> <span>Class Schedule</span></button></li>
        
        <!-- Courses & Materials -->
        <li><button class="tab-link" onclick="openTab('courses')"><i class="fa-solid fa-book-open-reader icon"></i> <span>Courses & Materials</span> <span class="main-sidebar-badge" id="badge_courses" style="display:none; position:relative; right:0; transform:none; margin-left:auto;">0</span></button></li>
        
        <!-- Assignments -->
        <li><button class="tab-link" onclick="openTab('assignments')"><i class="fa-solid fa-list-check icon"></i> <span>Assignments</span> <span class="main-sidebar-badge" id="badge_assignments" style="display:none; position:relative; right:0; transform:none; margin-left:auto;">0</span></button></li>        
        
        <!-- Exams -->
        <li><button class="tab-link" onclick="openTab('exams')"><i class="fa-solid fa-stopwatch icon"></i> <span>Take Exams</span> <span class="main-sidebar-badge" id="badge_exams" style="display:none; position:relative; right:0; transform:none; margin-left:auto;">0</span></button></li>
        
        <li><button class="tab-link" onclick="openTab('grades')"><i class="fa-solid fa-star-half-stroke icon" style="color: #f59e0b;"></i> <span>My Grades</span></button></li>
        <li><button class="tab-link" onclick="openTab('calendar')"><i class="fa-regular fa-calendar-days icon" style="color: #ec4899;"></i> <span>Academic Calendar</span></button></li>
        <li><button class="tab-link" onclick="openTab('help')"><i class="fa-solid fa-circle-question icon" style="color: #ec4899;"></i> <span>Help Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('about')"><i class="fa-solid fa-rocket icon" style="color: #3b82f6;"></i> <span>About RLMS</span></button></li>
                <li><button class="tab-link" onclick="openTab('broadcast')"><i class="fa-brands fa-telegram icon" style="color: #0ea5e9;"></i> <span>Communications</span> <span class="main-sidebar-badge" id="main_comm_badge" style="display:none;">0</span></button></li>

        <li><button class="tab-link" onclick="openTab('audit')"><i class="fa-solid fa-shield-halved icon" style="color: var(--danger);"></i> <span>Security Logs</span></button></li>
        <li><button class="tab-link" onclick="openTab('settings')"><i class="fa-solid fa-user-gear icon" style="color: var(--primary);"></i> <span>Settings</span></button></li>
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
                <img src="<?php echo $profile_pic; ?>" alt="Student" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); flex-shrink: 0;">
                <div class="welcome-text" style="white-space: nowrap;">
                    <h2 style="font-size: 18px; font-weight: 800; color: var(--text-main);"><?php echo $full_name; ?></h2>
                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-building-columns" style="color:var(--primary);"></i> <?php echo htmlspecialchars($stu_info['college_name']); ?></span>
                </div>
            </div>
        </div>
        <button class="theme-toggle" style="flex-shrink: 0;" onclick="toggleTheme()"><i class="fa-solid fa-moon" id="theme-icon"></i> <span id="theme-text">Dark Mode</span></button>
    </header>

   <!-- 🪄 MAGIC NOTIFICATION CENTER (TOP FIXED) -->
    <div id="magic-alert-container" style="position: sticky; top: 0; z-index: 9999; width: 100%; margin-bottom: 20px;">
        <?php if($message): ?>
            <div class="alert-premium <?php echo ($msg_type=='success')?'success-glow':'error-glow'; ?>" id="auto-hide-alert" style="display: flex; align-items: center; gap: 15px; padding: 18px 25px; border-radius: 12px; background: <?php echo ($msg_type=='success')?'#d1fae5':'#fee2e2'; ?>; border: 2px solid <?php echo ($msg_type=='success')?'#10b981':'#f43f5e'; ?>; color: <?php echo ($msg_type=='success')?'#065f46':'#991b1b'; ?>; box-shadow: 0 10px 30px rgba(0,0,0,0.1); animation: slideDownAlert 0.5s ease forwards;">
                <div style="width: 35px; height: 35px; border-radius: 50%; background: <?php echo ($msg_type=='success')?'#10b981':'#f43f5e'; ?>; color: #fff; display: flex; justify-content: center; align-items: center; font-size: 18px; flex-shrink: 0;">
                    <i class="fa-solid <?php echo ($msg_type=='success')?'fa-check-double':'fa-triangle-exclamation'; ?>"></i>
                </div>
                <div>
                    <strong style="display: block; font-size: 15px;"><?php echo ($msg_type=='success')?'System Notification':'Security Alert'; ?></strong>
                    <span style="font-size: 14px; font-weight: 500;"><?php echo $message; ?></span>
                </div>
                <button onclick="this.parentElement.style.display='none'" style="margin-left: auto; background: transparent; border: none; font-size: 20px; cursor: pointer; color: inherit; opacity: 0.5;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <style>
                @keyframes slideDownAlert { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
                .success-glow { box-shadow: 0 0 20px rgba(16, 185, 129, 0.2); }
                .error-glow { box-shadow: 0 0 20px rgba(244, 63, 94, 0.2); }
            </style>
            
            <script>
                setTimeout(() => {
                    const alertBox = document.getElementById('auto-hide-alert');
                    if(alertBox) {
                        alertBox.style.transition = "0.8s ease";
                        alertBox.style.opacity = "0";
                        alertBox.style.transform = "translateY(-20px)";
                        setTimeout(() => alertBox.style.display = "none", 800);
                    }
                }, 5000); 
            </script>
        <?php endif; ?>
    </div>

    <div class="content-area">
      <!-- ============================================== -->
        <!-- TAB 1: HOME (MAGIC CONTROL CENTER)             -->
        <!-- ============================================== -->
        <div id="home" class="section-tab active">
            
            <!-- 🌟 Premium Welcome Banner 🌟 -->
            <div class="welcome-banner" style="background: linear-gradient(135deg, #be123c 0%, #4c0519 100%); border: none; padding: 40px; border-radius: 24px; box-shadow: 0 15px 35px rgba(225, 29, 72, 0.3);">
                <div style="position: absolute; top: -50px; right: -50px; width: 250px; height: 250px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>
                <div>
                    <h2 id="greeting-text" style="font-size: 36px; font-weight: 800; margin-bottom: 10px; color:#fff; text-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                        <?php 
                            $hour = date('H');
                            $greet = ($hour < 12) ? "Good Morning" : (($hour < 18) ? "Good Afternoon" : "Good Evening");
                            echo $greet . ", <span style='color:#fecdd3;'>" . htmlspecialchars($stu_info['first_name']) . "</span>"; 
                        ?>!
                    </h2>
                    <p style="color:#fecdd3; font-size:16px; font-weight:500; letter-spacing:0.5px;"><i class="fa-solid fa-rocket" style="color:#fcd535;"></i> Welcome back to your ultimate learning dashboard.</p>
                </div>
                <div class="live-clock-container" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2); box-shadow: 0 5px 15px rgba(0,0,0,0.2); padding: 15px 30px;"><i class="fa-solid fa-clock" style="color:#fcd535;"></i><span id="real-time-clock" style="color:#fff;">00:00:00</span></div>
            </div>

            <!-- 📊 5 Magic Stat Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div class="magic-card" style="border-top: 4px solid #3b82f6; padding: 25px;">
                    <div class="icon-box" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); width:50px; height:50px; font-size:22px;"><i class="fa-solid fa-book-open"></i></div>
                    <h2 class="counter" data-target="<?php echo $my_courses_count; ?>" style="font-size: 38px;">0</h2>
                    <p style="font-size: 12px;">Enrolled Courses</p>
                </div>
                <div class="magic-card" style="border-top: 4px solid #8b5cf6; padding: 25px;">
                    <div class="icon-box" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9); width:50px; height:50px; font-size:22px;"><i class="fa-solid fa-folder-open"></i></div>
                    <h2 class="counter" data-target="<?php echo $total_materials; ?>" style="font-size: 38px;">0</h2>
                    <p style="font-size: 12px;">Study Materials</p>
                </div>
                <div class="magic-card" style="border-top: 4px solid #0ea5e9; padding: 25px;">
                    <div class="icon-box" style="background: linear-gradient(135deg, #0ea5e9, #0369a1); width:50px; height:50px; font-size:22px;"><i class="fa-solid fa-laptop-file"></i></div>
                    <h2 class="counter" data-target="<?php echo $total_assignments; ?>" style="font-size: 38px;">0</h2>
                    <p style="font-size: 12px;">Assignments & Projects</p>
                </div>
              <div class="magic-card" style="border-top: 4px solid #f43f5e; padding: 25px;">
                    <div class="icon-box" style="background: linear-gradient(135deg, #f43f5e, #be123c); width:50px; height:50px; font-size:22px;"><i class="fa-solid fa-stopwatch"></i></div>
                    <h2 class="counter" data-target="<?php echo $upcoming_exams; ?>" style="font-size: 38px; color: #f43f5e;">0</h2>
                    <p style="font-size: 12px;">Upcoming Exams</p>
                </div>
                <div class="magic-card" style="border-top: 4px solid #10b981; padding: 25px;">
                    <div class="icon-box" style="background: linear-gradient(135deg, #10b981, #047857); width:50px; height:50px; font-size:22px;"><i class="fa-solid fa-check-double"></i></div>
                    <h2 class="counter" data-target="<?php echo $completed_tasks; ?>" style="font-size: 38px; color: #10b981;">0</h2>
                    <p style="font-size: 12px;">Completed Tasks</p>
                </div>
            </div>
            
            <div class="grid-2">
                <!-- LEFT PANEL: Modules & Progress -->
                <div class="premium-panel" style="margin:0; border-top-color:#3b82f6; padding: 25px;">
    <h3 class="panel-title-premium" style="margin-bottom: 20px; font-size: 16px;">
        <div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-chart-pie"></i></div> 
        Task Progress Overview
    </h3>
    
    <?php
    $total_all_tasks = $total_materials + $total_assignments + $upcoming_exams + $completed_tasks;
    $total_all_tasks = $total_all_tasks > 0 ? $total_all_tasks : 1; // Zero division error ittisuuf
    
    $p_mat = round(($total_materials / $total_all_tasks) * 100);
    $p_ass = round(($total_assignments / $total_all_tasks) * 100);
    $p_exm = round(($upcoming_exams / $total_all_tasks) * 100);
    $p_cmp = round(($completed_tasks / $total_all_tasks) * 100);
    ?>

    <div style="display: flex; align-items: center; gap: 20px;">
        <!-- Chart Area (Geengoo) -->
        <div style="flex: 1; height: 180px; position: relative;">
            <canvas id="studentTaskChart"></canvas>
        </div>
        
        <!-- Progress Bars (Istaatistiksii) -->
        <div style="flex: 1.2; display: flex; flex-direction: column; gap: 12px;">
            <!-- Materials -->
            <div>
                <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:var(--text-main); margin-bottom:4px;">
                    <span><i class="fa-solid fa-folder-open" style="color:#8b5cf6;"></i> Materials</span>
                    <span style="color:var(--text-muted);"><?php echo $total_materials; ?> Items</span>
                </div>
                <div style="height:6px; background:rgba(139,92,246,0.1); border-radius:10px; overflow:hidden;">
                    <div style="height:100%; width:<?php echo $p_mat; ?>%; background:linear-gradient(90deg, #8b5cf6, #a78bfa); border-radius:10px; transition:1s;"></div>
                </div>
            </div>
            <!-- Assignments -->
            <div>
                <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:var(--text-main); margin-bottom:4px;">
                    <span><i class="fa-solid fa-laptop-file" style="color:#0ea5e9;"></i> Assignments</span>
                    <span style="color:var(--text-muted);"><?php echo $total_assignments; ?> Tasks</span>
                </div>
                <div style="height:6px; background:rgba(14,165,233,0.1); border-radius:10px; overflow:hidden;">
                    <div style="height:100%; width:<?php echo $p_ass; ?>%; background:linear-gradient(90deg, #0ea5e9, #38bdf8); border-radius:10px; transition:1s;"></div>
                </div>
            </div>
            <!-- Exams -->
            <div>
                <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:var(--text-main); margin-bottom:4px;">
                    <span><i class="fa-solid fa-stopwatch" style="color:#f43f5e;"></i> Upcoming Exams</span>
                    <span style="color:var(--text-muted);"><?php echo $upcoming_exams; ?> Exams</span>
                </div>
                <div style="height:6px; background:rgba(244,63,94,0.1); border-radius:10px; overflow:hidden;">
                    <div style="height:100%; width:<?php echo $p_exm; ?>%; background:linear-gradient(90deg, #f43f5e, #fb7185); border-radius:10px; transition:1s;"></div>
                </div>
            </div>
            <!-- Completed -->
            <div>
                <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:var(--text-main); margin-bottom:4px;">
                    <span><i class="fa-solid fa-check-double" style="color:#10b981;"></i> Completed</span>
                    <span style="color:var(--text-muted);"><?php echo $completed_tasks; ?> Done</span>
                </div>
                <div style="height:6px; background:rgba(16,185,129,0.1); border-radius:10px; overflow:hidden;">
                    <div style="height:100%; width:<?php echo $p_cmp; ?>%; background:linear-gradient(90deg, #10b981, #34d399); border-radius:10px; transition:1s;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

                    <div class="premium-panel" style="flex:1; margin:0; border-top-color:#8b5cf6; padding: 25px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:15px;">
                            <h3 class="panel-title-premium" style="margin:0; border:none; padding:0;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-book-bookmark"></i></div> Enrolled Modules</h3>
                            <button class="btn btn-sm" style="background:rgba(139,92,246,0.1); color:#8b5cf6;" onclick="openTab('courses')">View All</button>
                        </div>
                        <div class="hide-scrollbar" style="display:flex; flex-direction:column; gap:12px; max-height:220px; overflow-y:auto; padding-right:5px;">
                            <?php
                            mysqli_data_seek($c_q, 0);
                            if(mysqli_num_rows($c_q)>0){
                                while($c = mysqli_fetch_assoc($c_q)){
                                    echo "<div style='padding:15px 20px; border:1px solid rgba(139,92,246,0.2); background:rgba(139,92,246,0.05); border-radius:12px; display:flex; align-items:center; gap:15px; transition:0.3s;' onmouseover=\"this.style.transform='translateX(5px)'\" onmouseout=\"this.style.transform='translateX(0)'\">
                                            <div style='width:45px; height:45px; border-radius:12px; background:linear-gradient(135deg, #8b5cf6, #6d28d9); color:#fff; display:flex; justify-content:center; align-items:center; font-size:18px; box-shadow:0 4px 10px rgba(139,92,246,0.3);'><i class='fa-solid fa-layer-group'></i></div>
                                            <div><strong style='color:var(--text-main); font-size:14px;'>{$c['course_name']}</strong><br><span style='color:#8b5cf6; font-size:11px; font-family:monospace; font-weight:800; background:rgba(139,92,246,0.1); padding:2px 8px; border-radius:6px;'>{$c['course_code']}</span></div>
                                          </div>";
                                }
                            } else { echo "<div class='info-alert warning' style='margin:0;'><i class='fa-solid fa-circle-info'></i> No courses available yet.</div>"; }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PANEL: TO-DO LIST (Action Needed) -->
                <div style="display: flex; flex-direction: column; gap: 25px;">
                    <div class="premium-panel" style="flex: 1; margin:0; padding: 25px; border-top-color:#f59e0b; background: linear-gradient(180deg, var(--panel-bg) 0%, rgba(245, 158, 11, 0.02) 100%);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                            <h3 class="panel-title-premium" style="margin:0; border:none; padding:0;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-clipboard-list"></i></div> Action Needed (To-Do)</h3>
                            <span class="badge badge-yellow" style="font-size: 12px; padding: 6px 12px;"><?php echo $pending_tasks; ?> Tasks</span>
                        </div>
                        
                        <div class="hide-scrollbar" style="flex: 1; overflow-y: auto; max-height: 460px; padding-right: 5px;">
                            <?php
                            $todo_q = mysqli_query($conn, "SELECT m.*, c.course_code FROM materials m JOIN course c ON m.course_id=c.id WHERE m.type IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.id NOT IN (SELECT material_id FROM submissions WHERE student_id=$student_id) AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id) ORDER BY m.due_date ASC");
                            
                            if(mysqli_num_rows($todo_q)>0){
                                $current_time = time();
                                while($todo = mysqli_fetch_assoc($todo_q)){
                                    $is_overdue = !empty($todo['due_date']) && strtotime($todo['due_date']) < $current_time;
                                    $color = $is_overdue ? '#f43f5e' : '#f59e0b';
                                    $bg_color = $is_overdue ? 'rgba(244,63,94,0.05)' : 'rgba(245,158,11,0.05)';
                                    $due_str = !empty($todo['due_date']) ? date("M d, Y • h:i A", strtotime($todo['due_date'])) : 'No Deadline';
                                    $icon = $todo['type'] == 'project' ? 'fa-rocket' : 'fa-file-pen';
                                    
                                    echo "<div style='padding:20px; border:1px solid {$color}; background:{$bg_color}; border-radius:12px; margin-bottom:15px; display:flex; justify-content:space-between; align-items:center; transition:0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.02);' onmouseover=\"this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.05)';\" onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.02)';\">
                                            <div style='display:flex; align-items:center; gap:15px;'>
                                                <div style='width:45px; height:45px; border-radius:12px; background:rgba(255,255,255,0.8); color:{$color}; display:flex; justify-content:center; align-items:center; font-size:18px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);'><i class='fa-solid {$icon}'></i></div>
                                                <div>
                                                    <strong style='color:var(--text-main); font-size:15px; display:block; margin-bottom:3px;'>{$todo['title']}</strong>
                                                    <span style='font-size:11.5px; font-family:monospace; color:var(--text-main); background:rgba(0,0,0,0.1); padding:2px 8px; border-radius:6px;'>{$todo['course_code']}</span>
                                                </div>
                                            </div>
                                            <div style='text-align:right;'>
                                                <span style='font-size:11.5px; color:{$color}; font-weight:800; display:block; margin-bottom:8px;'><i class='fa-solid fa-hourglass-end'></i> Due: $due_str</span>
                                                <button class='glow-btn' style='background:linear-gradient(135deg, {$color}, " . ($is_overdue ? '#be123c' : '#b45309') . "); padding:8px 20px; font-size:12px; border-radius:8px;' onclick=\"openTab('assignments')\"><i class='fa-solid fa-upload'></i> Turn In</button>
                                            </div>
                                          </div>";
                                }
                            } else { 
                                echo "<div style='text-align:center; padding:50px 20px; opacity:0.6;'><i class='fa-solid fa-glass-cheers' style='font-size:60px; color:#10b981; margin-bottom:20px; display:block;'></i><h3 style='color:var(--text-main); font-size:18px;'>All Caught Up!</h3><p style='color:var(--text-muted); font-size:13.5px;'>You have no pending assignments or projects.</p></div>"; 
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<!-- ============================================== -->
        <!-- TAB: CLASS SCHEDULE (STUDENT VIEW)             -->
        <!-- ============================================== -->
        <div id="schedule" class="section-tab">
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <div>
                    <h3 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin: 0; display:flex; align-items:center; gap:10px;"><i class="fa-regular fa-calendar-days" style="color:#f59e0b;"></i> Official Class Schedule</h3>
                    <p style="color: var(--text-muted); font-size: 13.5px; margin-top: 5px;">View your full weekly timetable and today's upcoming classes.</p>
                </div>
            </div>

            <!-- 🌟 1. TODAY'S CLASSES (HIGHLIGHTED CARDS AT TOP) 🌟 -->
            <div class="premium-panel" style="border-top-color: var(--primary); padding: 30px; margin-bottom: 25px;">
                <h3 class="panel-title-premium" style="margin-bottom:20px; border-bottom:1px dashed var(--border-color); padding-bottom:15px;">
                    <div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, var(--primary), #be123c);"><i class="fa-solid fa-bolt"></i></div> 
                    Today's Classes
                    <span style="margin-left:auto; font-size:13px; font-family:monospace; background:rgba(244,63,94,0.1); color:var(--primary); padding:5px 12px; border-radius:20px;">
                        <i class="fa-regular fa-clock"></i> <?php echo date('l, d M Y'); ?>
                    </span>
                </h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                    <?php
                    $today_day = date('l'); // Returns 'Monday', 'Tuesday', etc.
                    $today_sch_q = mysqli_query($conn, "SELECT s.*, c.course_name, c.course_code, t.name as teacher_name FROM class_schedule s JOIN course c ON s.course_id=c.id JOIN teacher t ON s.teacher_id=t.id WHERE s.dept_id=$dept_id AND s.day_of_week='$today_day' ORDER BY STR_TO_DATE(SUBSTRING_INDEX(s.time_slot, ' ', 1), '%l:%i') ASC");
                    
                    if(mysqli_num_rows($today_sch_q) > 0){
                        while($my_sch = mysqli_fetch_assoc($today_sch_q)){
                            $type_icon = $my_sch['class_type'] == 'Lab' ? 'fa-flask' : ($my_sch['class_type'] == 'Tutorial' ? 'fa-users-rectangle' : 'fa-chalkboard-user');
                            $card_color = $my_sch['class_type'] == 'Lab' ? '#10b981' : 'var(--primary)';

                            echo "<div style='background: var(--bg-color); border: 1px solid var(--border-color); border-left: 4px solid {$card_color}; border-radius: 12px; padding: 20px; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.02);' onmouseover=\"this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.08)';\" onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.02)';\">
                                    <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom: 1px dashed var(--border-color); padding-bottom: 10px;'>
                                        <span class='badge' style='background:rgba(255,255,255,0.05); color:var(--text-main); border:1px solid var(--border-color); font-size:12px;'><i class='fa-regular fa-clock' style='color:{$card_color};'></i> {$my_sch['time_slot']}</span>
                                        <span style='font-size:11px; font-family:monospace; font-weight:800; color:{$card_color}; background:rgba(0,0,0,0.05); padding:3px 8px; border-radius:6px;'>{$my_sch['course_code']}</span>
                                    </div>
                                    <h4 style='font-size:15px; font-weight:800; color:var(--text-main); margin-bottom:5px; line-height:1.4;'>{$my_sch['course_name']}</h4>
                                    <div style='font-size:12.5px; color:var(--text-muted); margin-bottom:15px;'><i class='fa-solid fa-user-tie'></i> Inst. {$my_sch['teacher_name']}</div>
                                    
                                    <div style='display:flex; justify-content:space-between; align-items:center; margin-top:10px; background:var(--panel-bg); padding:10px; border-radius:8px; border:1px solid var(--border-color);'>
                                        <span style='font-size:12.5px; color:var(--text-muted); font-weight:600;'><i class='fa-solid {$type_icon}' style='color:{$card_color}; width:15px;'></i> {$my_sch['class_type']}</span>
                                        <span style='font-size:12.5px; color:var(--text-main); font-weight:800;'><i class='fa-solid fa-door-open' style='color:var(--text-muted);'></i> {$my_sch['room']}</span>
                                    </div>
                                  </div>";
                        }
                    } else {
                        echo "<div style='grid-column: 1/-1; background:rgba(16,185,129,0.05); border:1px dashed #10b981; border-radius:12px; padding:30px; text-align:center;'>
                                <i class='fa-solid fa-glass-cheers' style='font-size:40px; color:#10b981; margin-bottom:15px; display:block;'></i>
                                <h4 style='color:var(--text-main); font-size:16px;'>No Classes Today!</h4>
                                <p style='color:var(--text-muted); font-size:13px;'>Take some time to rest or catch up on your assignments.</p>
                              </div>";
                    }
                    ?>
                </div>
            </div>

            <!-- 🌟 2. OFFICIAL FULL DEPT SCHEDULE VIEW 🌟 -->
            <div class="premium-panel" style="padding: 0; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-top-color: #f59e0b;">
                <div style="padding: 40px; color: #111;">
                    
                    <?php
                    // Fetch metadata
                    $dept_meta_q = mysqli_query($conn, "SELECT d.dept_name, d.dept_code, c.college_name FROM departments d JOIN colleges c ON d.college_id = c.id WHERE d.id=$dept_id");
                    $dept_meta = mysqli_fetch_assoc($dept_meta_q);
                    $college_name_display = $dept_meta ? $dept_meta['college_name'] : "Unknown College";
                    $dept_name_display = $dept_meta ? $dept_meta['dept_name'] : "Unknown Department";
                    $dept_code_display = $dept_meta ? $dept_meta['dept_code'] : "DEPT";

                    $meta_q = mysqli_query($conn, "SELECT university_name, study_year, semester FROM class_schedule WHERE dept_id=$dept_id ORDER BY id DESC LIMIT 1");
                    $meta = mysqli_fetch_assoc($meta_q);
                    $uni_name = $meta ? $meta['university_name'] : "Bule Hora University";
                    $study_year = $meta ? $meta['study_year'] : "3rd Year";
                    $semester = $meta ? $meta['semester'] : "Semester II";
                    $current_year = date('Y');
                    ?>

                    <!-- Official Document Header -->
                    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 20px;">
                        <h2 style="font-family: 'Times New Roman', Times, serif; font-size: 26px; font-weight: bold; color: #000; margin-bottom: 8px; text-transform: uppercase;"><?php echo htmlspecialchars($uni_name); ?></h2>
                        <h3 style="font-family: 'Times New Roman', Times, serif; font-size: 20px; font-weight: bold; color: #222; margin-bottom: 6px;"><?php echo htmlspecialchars($college_name_display); ?></h3>
                        <h4 style="font-family: 'Times New Roman', Times, serif; font-size: 18px; font-weight: bold; color: #333; margin-bottom: 15px;"><?php echo htmlspecialchars($dept_name_display); ?> Department</h4>
                        <h4 style="font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; color: #000; text-decoration: underline;">Tentative Class Schedule for <?php echo htmlspecialchars($dept_code_display); ?> <?php echo htmlspecialchars($study_year); ?>, <?php echo htmlspecialchars($semester); ?>, <?php echo htmlspecialchars($current_year); ?> G.C.</h4>
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
                                        // Shade Lab rows
                                        $bg_color = ($sch['class_type'] == 'Lab') ? 'background: #e5e5e5;' : 'background: #fff;';
                                        
                                        // Highlight today's classes mildly
                                        if($d == $today_day) {
                                            $bg_color = ($sch['class_type'] == 'Lab') ? 'background: #fecdd3;' : 'background: #fff1f2;';
                                        }

                                        echo "<tr style='{$bg_color}'>";
                                        
                                        if($first){
                                            echo "<td rowspan='{$rowspan}' style='border: 1px solid #000; padding: 10px; font-weight: bold; text-align: center; vertical-align: middle; font-size: 16px; background: #fff;'>{$d}</td>";
                                            $first = false;
                                        }
                                        
                                        echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['course_name']}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['course_code']}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['time_slot']}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['class_type']}</td>";
                                        
                                        $inst_name = (strpos($sch['teacher_name'], 'Mr.') === false && strpos($sch['teacher_name'], 'Ms.') === false) ? "Tr. ".$sch['teacher_name'] : $sch['teacher_name'];
                                        
                                        echo "<td style='border: 1px solid #000; padding: 10px;'>{$inst_name}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px;'>{$sch['room']}</td>";
                                        
                                        echo "</tr>";
                                    }
                                }
                            }

                            if(!$schedule_exists) {
                                echo "<tr><td colspan='7' style='text-align:center; padding:30px; color:#555; font-style:italic;'>The official schedule has not been published by your department yet.</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                </div>
            </div>

        </div>
      <!-- ============================================== -->
        <!-- TAB 2: MY COURSES & MATERIALS (DUAL VIEW)      -->
        <!-- ============================================== -->
        <div id="courses" class="section-tab">
            <div class="premium-panel" style="border-top-color: #3b82f6; padding: 30px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px;">
                    <div>
                        <h3 class="panel-title-premium" style="margin:0; border:none; padding:0;"><div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0 15px 0 0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-book-open-reader"></i></div> Learning Materials Vault</h3>
                        <p style="font-size:13px; color:var(--text-muted); margin-top:5px;">Access all your course resources in your preferred layout.</p>
                    </div>

                    <!-- 🪄 MAGIC VIEW SWITCHER -->
                    <div style="display:flex; align-items:center; gap:20px;">
                       <div class="view-controls">
                            <!-- 🪄 'active' irraa 'Card' gara 'Course' tti jijjiirame -->
                            <button class="view-btn" id="btn-grid-view" onclick="switchMaterialView('grid')"><i class="fa-solid fa-grip"></i> Card View</button>
                            <button class="view-btn active" id="btn-list-view" onclick="switchMaterialView('list')"><i class="fa-solid fa-layer-group"></i> Course View</button>
                        </div>
                        <div class="input-with-icon" style="width: 250px;">
                            <select id="course_filter" onchange="filterMaterialsByCourse()" style="padding: 10px 12px 10px 35px !important; font-size: 13px; background: rgba(0,0,0,0.2); border-color: var(--border-color); color: var(--text-main); border-radius: 8px;">
                                <option value="all">📚 All Courses</option>
                                <?php mysqli_data_seek($c_q, 0); while($cl = mysqli_fetch_assoc($c_q)) echo "<option value='{$cl['id']}'>{$cl['course_name']}</option>"; ?>
                            </select>
                            <i class="fa-solid fa-filter" style="font-size: 13px; color: #3b82f6;"></i>
                        </div>
                    </div>
                </div>

<!-- 1️⃣ OPTION A: GRID / CARD VIEW (Course-Categorized Magic View) -->
                <div id="material-grid-view" style="display: none;">
                    <?php
                    // 1. Fetch all distinct courses the student is enrolled in
                    $enrolled_courses_q = mysqli_query($conn, "SELECT DISTINCT c.id, c.course_name, c.course_code FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id AND c.is_deleted=0 AND t.is_deleted=0 AND t.status='active'");
                    
                    if(mysqli_num_rows($enrolled_courses_q) > 0){
                        $materials_exist = false;

                        // 2. Loop through each course
                        while($c_data = mysqli_fetch_assoc($enrolled_courses_q)) {
                            $cid = $c_data['id'];
                            
                            // 3. Fetch materials ONLY for this specific course
                            $m_q = mysqli_query($conn, "SELECT m.*, t.name as teacher_name FROM materials m JOIN teacher t ON m.teacher_id=t.id WHERE m.course_id=$cid AND m.is_locked=0 AND m.type NOT IN ('assignment', 'project') AND (m.release_date IS NULL OR m.release_date <= NOW()) ORDER BY m.id DESC");
                            
                            // Only display the category if there are materials in it
                            if(mysqli_num_rows($m_q) > 0){
                                $materials_exist = true;
                                
                                echo "<div class='course-category-wrapper material-card' data-course-id='{$cid}'>
                                        <h3 class='course-category-title'>
                                            <i class='fa-solid fa-layer-group' style='color:#3b82f6;'></i> {$c_data['course_name']} 
                                            <span style='font-size:11px; font-family:monospace; background:rgba(59,130,246,0.1); color:#3b82f6; padding:3px 8px; border-radius:6px; margin-left:10px;'>{$c_data['course_code']}</span>
                                        </h3>
                                        <div class='magic-material-grid'>";

                                while($m = mysqli_fetch_assoc($m_q)){
                                    $icon = 'fa-file'; $color = '#10b981'; $bg_color = 'rgba(16,185,129,0.05)';
                                    $t = strtolower($m['type']);
                                    if(in_array($t,['material', 'pdf', 'ppt', 'doc', 'docx'])) { $icon='fa-file-pdf'; $color='#f43f5e'; $bg_color='rgba(244,63,94,0.05)'; }
                                    elseif(in_array($t, ['media', 'video', 'audio'])) { $icon='fa-circle-play'; $color='#0ea5e9'; $bg_color='rgba(14,165,233,0.05)'; }
                                    
                                    $view_btn = !empty($m['video_url']) ? "<a href='{$m['video_url']}' target='_blank' class='glow-btn' style='background:linear-gradient(135deg, {$color}, #0369a1); width:100%; justify-content:center; padding:10px; font-size:13px; box-shadow:0 4px 15px rgba(14,165,233,0.3);'><i class='fa-solid fa-play'></i> Watch Video</a>" : (!empty($m['file_path']) ? "<a href='../uploads/materials/{$m['file_path']}' target='_blank' class='glow-btn' style='background:linear-gradient(135deg, {$color}, #be123c); width:100%; justify-content:center; padding:10px; font-size:13px; box-shadow:0 4px 15px rgba(244,63,94,0.3);'><i class='fa-solid fa-download'></i> Download File</a>" : "");

                                    echo "
                                    <div class='magic-material-card' style='--brand-color: {$color};'>
                                        <div style='display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;'>
                                            <div style='width:40px; height:40px; border-radius:10px; background:{$bg_color}; color:{$color}; display:flex; justify-content:center; align-items:center; font-size:18px;'><i class='fa-solid {$icon}'></i></div>
                                            <span style='font-size:10.5px; font-weight:700; color:var(--text-muted); background:var(--panel-bg); padding:3px 8px; border-radius:6px; border:1px solid var(--border-color);'><i class='fa-regular fa-clock'></i> ".date("d M", strtotime($m['uploaded_at']))."</span>
                                        </div>
                                        <h4 style='font-size:15px; font-weight:800; color:var(--text-main); margin-bottom:8px; line-height:1.4;'>{$m['title']}</h4>
                                        <div style='font-size:12px; color:var(--text-muted); margin-bottom:20px;'><i class='fa-solid fa-user-tie'></i> Tr. {$m['teacher_name']}</div>
                                        <div style='margin-top:auto; position:relative; z-index:2;'>{$view_btn}</div>
                                    </div>";
                                }

                                echo "  </div>
                                      </div>"; // End category wrapper
                            }
                        }

                        if(!$materials_exist) {
                            echo "<div class='info-alert' style='grid-column:1/-1;'><i class='fa-solid fa-circle-info'></i> No materials available for your courses yet.</div>";
                        }
                    } else { 
                        echo "<div class='info-alert' style='grid-column:1/-1;'><i class='fa-solid fa-circle-info'></i> You are not enrolled in any courses yet.</div>"; 
                    }
                    ?>
                </div>

                <!-- 2️⃣ OPTION B: COURSE-WISE ACCORDION VIEW (Visible by default) -->
                <div id="material-list-view" style="display: block;">
                    <?php
                    mysqli_data_seek($c_q, 0);
                    while($course = mysqli_fetch_assoc($c_q)){
                        $cid = $course['id'];
                        // Count materials for this specific course
                        $cnt_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM materials WHERE course_id=$cid AND is_locked=0 AND (release_date IS NULL OR release_date <= NOW())");
                        $mat_count = mysqli_fetch_assoc($cnt_q)['c'];
                        
                        echo "<div class='course-accordion' data-course-id='{$cid}'>
                                <button class='acc-header' onclick='toggleAccordion(this)'>
                                    <div style='display:flex; align-items:center; gap:15px;'>
                                        <div style='width:40px; height:40px; background:rgba(99,102,241,0.1); color:var(--secondary); border-radius:10px; display:flex; justify-content:center; align-items:center; font-size:18px;'><i class='fa-solid fa-book'></i></div>
                                        <div>
                                            <strong style='font-size:16px; color:var(--text-main);'>{$course['course_name']}</strong><br>
                                            <small style='color:var(--text-muted); font-family:monospace;'>{$course['course_code']} • {$mat_count} Items</small>
                                        </div>
                                    </div>
                                    <i class='fa-solid fa-chevron-down chevron'></i>
                                </button>
                                <div class='acc-content'>
                                    <div style='display:grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; padding-top: 10px;'>";
                                    
                                    $m_sub_q = mysqli_query($conn, "SELECT * FROM materials WHERE course_id=$cid AND is_locked=0 AND (release_date IS NULL OR release_date <= NOW()) AND type NOT IN ('assignment', 'project')");
                                    if(mysqli_num_rows($m_sub_q) > 0){
                                        while($sm = mysqli_fetch_assoc($m_sub_q)){
                                            $icon = 'fa-file'; $color = '#10b981';
                                            if($sm['type'] == 'pdf') $icon='fa-file-pdf';
                                            elseif($sm['type'] == 'video') $icon='fa-circle-play';
                                            
                                            $link = !empty($sm['video_url']) ? $sm['video_url'] : "../uploads/materials/".$sm['file_path'];
                                            
                                            echo "<a href='{$link}' target='_blank' style='text-decoration:none; background:var(--input-bg); padding:15px; border-radius:12px; display:flex; align-items:center; gap:12px; border:1px solid var(--border-color); transition:0.3s;' onmouseover='this.style.borderColor=var(--primary)'>
                                                    <i class='fa-solid {$icon}' style='font-size:20px; color:var(--primary);'></i>
                                                    <div>
                                                        <div style='font-size:13.5px; font-weight:700; color:var(--text-main);'>{$sm['title']}</div>
                                                        <small style='color:var(--text-muted); text-transform:uppercase;'>{$sm['type']}</small>
                                                    </div>
                                                  </a>";
                                        }
                                    } else { echo "<div style='color:var(--text-muted); font-size:13px; font-style:italic;'>No materials yet.</div>"; }
                                    
                        echo "      </div>
                                </div>
                              </div>";
                    }
                    ?>
                </div>

            </div>
        </div>
      <!-- ============================================== -->
        <!-- TAB 3: ASSIGNMENTS & SUBMISSIONS               -->
        <!-- ============================================== -->
        <div id="assignments" class="section-tab">
            
            <!-- TOP SECTION: PENDING TASKS & SUBMISSION PORTAL -->
            <div class="grid-2" style="grid-template-columns: 1fr 380px; margin-bottom: 30px;">
                
                <!-- LEFT: Pending Tasks Cards (Like Image 2) -->
                <div class="premium-panel" style="border-top-color: #f59e0b; margin-bottom:0; display:flex; flex-direction:column; max-height:80vh; padding: 30px;">
                    <h3 class="panel-title-premium" style="border:none; margin-bottom: 25px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-list-check"></i></div> Pending & Overdue Tasks</h3>
                    
                    <div class="hide-scrollbar" style="flex:1; overflow-y:auto; padding-right:5px;">
                        <?php
$a_q = mysqli_query($conn, "SELECT m.*, c.course_code, c.course_name, t.name as teacher_name FROM materials m JOIN course c ON m.course_id=c.id JOIN teacher t ON m.teacher_id=t.id WHERE m.type IN ('assignment', 'project') AND m.is_locked=0 AND (m.release_date IS NULL OR m.release_date <= NOW()) AND m.id NOT IN (SELECT material_id FROM submissions WHERE student_id=$student_id) AND m.course_id IN (SELECT DISTINCT c.id FROM course c JOIN teacher_course tc ON c.id=tc.course_id JOIN teacher t ON tc.teacher_id=t.id WHERE t.dept_id=$dept_id) ORDER BY m.due_date ASC");                        
                        if(mysqli_num_rows($a_q)>0){
                            $current_time = time();
                          while($a = mysqli_fetch_assoc($a_q)){
                                $is_overdue = !empty($a['due_date']) && strtotime($a['due_date']) < $current_time;
                                $color = $is_overdue ? '#f43f5e' : '#f59e0b';
                                
                                $status_badge = $is_overdue 
                                    ? "<span style='color: #f43f5e; background: rgba(244,63,94,0.1); padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 900; border: 1px solid rgba(244,63,94,0.3); box-shadow: 0 0 10px rgba(244,63,94,0.2);'><i class='fa-solid fa-circle-exclamation'></i> OVERDUE</span>" 
                                    : "<span style='color: #f59e0b; background: rgba(245,158,11,0.1); padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 900; border: 1px solid rgba(245,158,11,0.3); box-shadow: 0 0 10px rgba(245,158,11,0.2);'><i class='fa-solid fa-clock-rotate-left'></i> PENDING</span>";
                                
                                $time_color = $is_overdue ? "color: #f43f5e;" : "color: #f59e0b;";
                                $due_str = !empty($a['due_date']) ? date("M d, Y - h:i A", strtotime($a['due_date'])) : 'No Deadline';
                                
                                // 🪄 Salphaatti Download gochuuf button miidhagaa
                                $dl_btn = !empty($a['file_path']) ? "<a href='../uploads/materials/{$a['file_path']}' target='_blank' style='background: rgba(16,185,129,0.1); color: #10b981; font-size: 13px; font-weight: 800; text-decoration: none; padding: 10px 15px; border-radius: 8px; border: 1px solid rgba(16,185,129,0.3); transition: 0.3s;' onmouseover=\"this.style.background='#10b981'; this.style.color='#fff';\"><i class='fa-solid fa-cloud-arrow-down'></i> DL File</a>" : "";

                                // Point/Value DB irraa fudhachuu
                                $pts = isset($a['max_points']) ? $a['max_points'] : 10;

                                echo "
                                <div style='background: var(--bg-color); border: 2px solid transparent; position: relative; border-radius: 16px; padding: 25px; margin-bottom: 20px; box-shadow: 0 8px 25px rgba(0,0,0,0.04); transition: 0.4s;' onmouseover=\"this.style.transform='translateY(-5px)'; this.style.boxShadow='0 15px 35px rgba(245,158,11,0.15)'; this.style.borderColor='{$color}';\" onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 8px 25px rgba(0,0,0,0.04)'; this.style.borderColor='transparent';\">
                                    
                                    <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;'>
                                        <span style='background: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(139,92,246,0.1)); color: #3b82f6; padding: 6px 15px; border-radius: 8px; font-weight: 800; font-family: monospace; font-size: 13px; border: 1px solid rgba(59,130,246,0.2);'><i class='fa-solid fa-layer-group'></i> {$a['course_code']}</span>
                                        {$status_badge}
                                    </div>
                                    
                                    <h4 style='font-size: 20px; font-weight: 800; color: var(--text-main); margin-bottom: 10px; line-height: 1.4;'>{$a['title']}</h4>
                                    
                                    <div style='display:flex; align-items:center; gap:8px;'>
    <span style='font-size: 11px; font-family: monospace; font-weight: 800; background: var(--panel-bg); padding: 5px 10px; border-radius: 6px; color: var(--primary); border: 1px solid var(--border-color); letter-spacing: 0.5px;'><i class='fa-solid fa-layer-group'></i> {$a['course_code']}</span>
    <span style='font-size: 13px; font-weight: 700; color: var(--text-muted);'>{$a['course_name']}</span>
</div>

                                    <div style='display: flex; justify-content: space-between; align-items: center; background: var(--panel-bg); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color);'>
                                        <div style='font-size: 13px; font-weight: 800; {$time_color}'><i class='fa-regular fa-clock'></i> Due: {$due_str}</div>
                                        <div style='display: flex; gap: 12px; align-items: center;'>
                                            {$dl_btn}
                                            <button class='glow-btn' style='background: linear-gradient(135deg, {$color}, " . ($is_overdue ? '#9f1239' : '#b45309') . "); padding: 10px 25px; font-size: 14px; border-radius: 8px; box-shadow: 0 5px 15px rgba(".($is_overdue?'244,63,94':'245,158,11').",0.3); border:none; cursor:pointer; font-weight:800; transition: 0.3s;' onclick=\"openSubmitForm({$a['id']}, '".addslashes($a['title'])."', '{$a['course_code']}')\" onmouseover=\"this.style.transform='scale(1.05)'\" onmouseout=\"this.style.transform='scale(1)'\"><i class='fa-solid fa-paper-plane'></i> Turn In</button>
                                        </div>
                                    </div>
                                </div>";
                            }
                        } else { 
                            echo "<div style='text-align:center; padding:50px 20px;'><i class='fa-solid fa-glass-cheers' style='font-size:50px; color:#10b981; opacity:0.5; margin-bottom:15px; display:block;'></i><h3 style='color:var(--text-main); font-size:18px;'>All Caught Up!</h3><p style='color:var(--text-muted); font-size:13px;'>You have no pending tasks.</p></div>"; 
                        }
                        ?>
                    </div>
                </div>

              <!-- RIGHT: Submission Portal -->
                <div class="premium-panel" style="margin:0; border-top-color: #10b981; padding: 30px;">
                    <div id="submit_placeholder" style="text-align:center; padding:50px 10px; color:var(--text-muted); display: flex; flex-direction: column; justify-content: center; height: 100%;">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size:70px; opacity:0.2; margin-bottom:20px; color:#10b981;"></i>
                        <h3 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:10px;">Submission Portal</h3>
                        <p style="font-size:14px; line-height:1.6;">Select a pending assignment from the left to turn in your work.</p>
                    </div>

                 <div id="submit_form_area" style="display:none; flex-direction:column; height:100%;">
                        <h3 class="panel-title-premium" style="margin-bottom: 25px; color:#10b981; border-bottom:2px dashed rgba(16,185,129,0.2); padding-bottom:15px;"><i class="fa-solid fa-paper-plane"></i> Turn In Work</h3>
                        
                        <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); padding:20px; border-radius:16px; margin-bottom:25px;">
                            <span style="font-family:monospace; font-size:12px; font-weight:800; color:#10b981; background:var(--bg-color); padding:5px 12px; border-radius:8px; border:1px solid rgba(16,185,129,0.2);" id="disp_sub_code">CODE</span>
                            <h4 id="disp_sub_title" style="font-size:18px; font-weight:800; color:var(--text-main); margin-top:15px; line-height: 1.4;">Assignment Title</h4>
                        </div>

                        <!-- ENCTYPE IS STRICTLY REQUIRED HERE -->
                        <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; flex: 1;">
                            <input type="hidden" name="material_id" id="form_sub_mat_id">
                            
                            <!-- 🪄 TITLE INPUT ADDED HERE -->
                            <div class="form-group">
                                <label style="color:var(--text-main); font-size:13px; font-weight:700; margin-bottom: 8px;">Submission Title / Note *</label>
                                <div class="input-with-icon">
                                    <input type="text" name="sub_title" placeholder="e.g. My Assignment 1 Answers" required style="padding:15px 15px 15px 45px !important; border-color: #10b981;">
                                    <i class="fa-solid fa-pen" style="color:#10b981;"></i>
                                </div>
                            </div>

                            <div class="form-group" style="flex: 1; margin-top:10px;">
                                <label style="color:#10b981; font-size:14px; font-weight:800; margin-bottom: 10px;">Attach Your File *</label>
                                <input type="file" name="sub_file" required style="width:100%; padding:25px; background:var(--input-bg); border-radius:16px; border:2px dashed #10b981; color:var(--text-main); font-weight:bold; cursor:pointer;" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.rar,.jpg,.jpeg,.png,.txt">
                                <small style="display:block; margin-top:12px; color:var(--text-muted); font-size:12px; line-height: 1.5;"><i class="fa-solid fa-circle-info"></i> Allowed files: PDF, Word, PPT, Images, ZIP, TXT.</small>
                            </div>
                            
                            <button type="submit" name="submit_assignment" class="glow-btn" style="width:100%; justify-content:center; background:linear-gradient(135deg, #10b981, #047857); box-shadow:0 10px 25px rgba(16,185,129,0.4); padding:20px; font-size: 16px; border-radius: 12px; margin-top: 10px;"><i class="fa-solid fa-rocket"></i> Submit Assignment</button>
                        </form>
                    </div>
                </div>

            </div>

          <!-- BOTTOM SECTION: ALL TASKS & SUBMISSIONS TABLE -->
            <div class="premium-panel" style="padding: 0; overflow: hidden; border-top-color: #3b82f6;">
                <div style="padding: 25px 30px; border-bottom: 1px solid var(--border-color); background: rgba(59, 130, 246, 0.02); display:flex; justify-content:space-between; align-items:center;">
                    <h3 class="panel-title-premium" style="margin:0; border:none; padding:0; font-size: 20px; color: var(--text-main);"><i class="fa-solid fa-list-check" style="color: #3b82f6;"></i> Your Assignments History</h3>
                </div>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
                        <tr style="background: rgba(59, 130, 246, 0.05); border-bottom: 2px solid var(--border-color);">
                            <th style="padding: 18px 30px; color: var(--text-muted); font-size: 12px; font-weight: 800; text-transform: uppercase;">COURSE DETAILS</th>
                            <th style="padding: 18px 15px; color: var(--text-muted); font-size: 12px; font-weight: 800; text-transform: uppercase;">ASSIGNMENT</th>
                            <th style="padding: 18px 15px; color: var(--text-muted); font-size: 12px; font-weight: 800; text-transform: uppercase;">DUE / SUBMITTED</th>
                            <th style="padding: 18px 15px; color: var(--text-muted); font-size: 12px; font-weight: 800; text-transform: uppercase; text-align:center;">MAX POINT</th>
                            <th style="padding: 18px 15px; color: var(--text-muted); font-size: 12px; font-weight: 800; text-transform: uppercase; text-align:center;">STATUS</th>
                            <th style="padding: 18px 15px; color: #10b981; font-size: 13px; font-weight: 900; text-transform: uppercase; text-align:center;"><i class="fa-solid fa-star"></i> SCORE</th>
                            <th style="padding: 18px 30px; color: var(--text-muted); font-size: 12px; font-weight: 800; text-align:right; text-transform: uppercase;">ACTION</th>
                        </tr>
                        
                        <?php
                        $all_tasks_q = mysqli_query($conn, "
                            SELECT m.id as mat_id, m.title, m.type, m.due_date, m.max_points, 
                                   c.course_code, c.course_name, 
                                   s.status as sub_status, s.file_path as sub_file, s.submitted_at, s.grade 
                            FROM materials m 
                            JOIN course c ON m.course_id = c.id 
                            JOIN teacher_course tc ON c.id = tc.course_id 
                            JOIN teacher t ON tc.teacher_id = t.id 
                            LEFT JOIN submissions s ON m.id = s.material_id AND s.student_id = $student_id
                            WHERE t.dept_id = $dept_id 
                              AND m.type IN ('assignment', 'project') 
                              AND m.is_locked = 0 
                              AND (m.release_date IS NULL OR m.release_date <= NOW())
                            ORDER BY m.id DESC
                        ");
                        
                        if(mysqli_num_rows($all_tasks_q) > 0) {
                            while($task = mysqli_fetch_assoc($all_tasks_q)) {
                                $max_pts = isset($task['max_points']) ? $task['max_points'] : 10;
                                
                                if(!empty($task['sub_file'])) {
                                    // 🟢 SUBMITTED
                                    $status_badge = "<span style='background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 800; border: 1px solid rgba(16, 185, 129, 0.3); box-shadow: 0 0 10px rgba(16,185,129,0.2);'><i class='fa-solid fa-check-double'></i> SUBMITTED</span>";
                                    $time_display = "<span style='color:var(--text-muted); font-size:12.5px;'><i class='fa-solid fa-check' style='color:#10b981;'></i> " . date("M d, h:i A", strtotime($task['submitted_at'])) . "</span>";
                                    $action_btn = "<a href='../uploads/submissions/{$task['sub_file']}' target='_blank' class='btn' style='background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; padding: 8px 15px; font-size: 12px; border-radius: 8px; text-decoration:none;'><i class='fa-solid fa-eye'></i> View Work</a>";
                                    
                                    if(!empty($task['grade'])) {
                                        $score_display = "<strong style='color:#10b981; font-size:18px;'>{$task['grade']}</strong> <span style='color:var(--text-muted); font-size:12px;'>/ {$max_pts}</span>";
                                    } else {
                                        $score_display = "<span style='color:var(--text-muted); font-size:16px; font-weight:bold;' title='Pending Grading'>--</span>";
                                    }

                                } else {
                                    // 🔴 OPEN / PENDING / OVERDUE
                                    $is_overdue = !empty($task['due_date']) && strtotime($task['due_date']) < time();
                                    
                                    if($is_overdue) {
                                        $status_badge = "<span style='background: rgba(244, 63, 94, 0.1); color: #f43f5e; padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 800; border: 1px solid rgba(244, 63, 94, 0.3); box-shadow: 0 0 10px rgba(244,63,94,0.2);'><i class='fa-solid fa-triangle-exclamation'></i> OVERDUE</span>";
                                    } else {
                                        $status_badge = "<span style='background: rgba(244, 63, 94, 0.1); color: #f43f5e; padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 800; border: 1px dashed rgba(244, 63, 94, 0.5);'><i class='fa-solid fa-folder-open'></i> OPEN</span>";
                                    }
                                    
                                    $time_display = !empty($task['due_date']) ? "<span style='color:#f59e0b; font-size:12.5px;'><i class='fa-solid fa-hourglass-end'></i> Due: " . date("M d, h:i A", strtotime($task['due_date'])) . "</span>" : "<span style='color:var(--text-muted); font-size:12.5px;'>No Deadline</span>";
                                    
                                    $action_btn = "<button onclick=\"openSubmitForm({$task['mat_id']}, '".addslashes($task['title'])."', '{$task['course_code']}')\" class='btn' style='background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; padding: 8px 20px; font-size: 12px; border-radius: 8px; border:none; cursor:pointer; box-shadow: 0 4px 10px rgba(245,158,11,0.3);'><i class='fa-solid fa-upload'></i> Turn In</button>";
                                    
                                    $score_display = "<span style='color:var(--text-muted); font-size:16px; font-weight:bold;'>--</span>";
                                }

                                echo "<tr style='border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s;' onmouseover=\"this.style.background='rgba(59, 130, 246, 0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                        <td style='padding: 20px 30px;'>
                                            <strong style='color: var(--text-main); font-size: 14.5px;'>{$task['course_name']}</strong><br>
                                            <span style='font-family: monospace; font-size: 11px; color: #3b82f6; font-weight: bold; background:rgba(59,130,246,0.1); padding:2px 6px; border-radius:4px;'>{$task['course_code']}</span>
                                        </td>
                                        <td style='padding: 20px 15px; font-size: 14px; color: var(--text-main); font-weight: 600;'>{$task['title']}</td>
                                        <td style='padding: 20px 15px;'>{$time_display}</td>
                                        <td style='padding: 20px 15px; font-size: 14px; color: #f59e0b; font-weight: 800; text-align:center;'><i class='fa-solid fa-star'></i> {$max_pts} Pts</td>
                                        <td style='padding: 20px 15px; text-align:center;'>{$status_badge}</td>
                                        <td style='padding: 20px 15px; text-align:center; background:rgba(16,185,129,0.02);'>{$score_display}</td>
                                        <td style='padding: 20px 30px; text-align:right;'>{$action_btn}</td>
                                      </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' style='text-align:center; padding: 50px; color: var(--text-muted); font-size: 14px;'><i class='fa-solid fa-folder-open' style='font-size: 50px; margin-bottom: 15px; opacity: 0.3; display:block;'></i> No assignments or projects found.</td></tr>";
                        }
                        ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 4: EXAMS & QUIZZES                         -->
        <!-- ============================================== -->
        <div id="exams" class="section-tab">
            <div class="premium-panel" style="border-top-color: #f43f5e; padding:30px;">
                <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-stopwatch"></i></div> Examination Center</h3>
                
                <div class="hide-scrollbar" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; max-height:65vh; overflow-y:auto;">
                <?php
                        $ex_q = mysqli_query($conn, "SELECT e.*, c.course_code, t.name as teacher_name FROM exams e JOIN course c ON e.course_id=c.id JOIN teacher t ON e.teacher_id=t.id WHERE t.dept_id=$dept_id AND e.is_deleted=0 ORDER BY e.start_time ASC");
                        
                        if(mysqli_num_rows($ex_q) > 0){
                            while($ex = mysqli_fetch_assoc($ex_q)){
                                // 🪄 VARIABLE DEFINITION FIX: Declare variables properly
                                $current_time = time();
                                $start_time = strtotime($ex['start_time']);
                                $duration_mins = (int)$ex['duration_mins'];
                                $duration_seconds = $duration_mins * 60;
                                $end_time = $start_time + $duration_seconds;
                                
                                $time_str = date("d M Y, h:i A", $start_time);
                                
                                $iso_start = date("Y-m-d\TH:i:s", $start_time); 
                                
                                $chk_res = mysqli_query($conn, "SELECT score, total_questions, submitted_at FROM exam_results WHERE exam_id={$ex['id']} AND student_id=$student_id");
                                $has_taken = (mysqli_num_rows($chk_res) > 0);
                                $res_data = $has_taken ? mysqli_fetch_assoc($chk_res) : null;
                                
                                $card_id = "exam_card_{$ex['id']}";
                                $badge_id = "exam_badge_{$ex['id']}";
                                $btn_area_id = "exam_btn_area_{$ex['id']}";
                                
                                $safe_title = htmlspecialchars(addslashes($ex['title']), ENT_QUOTES);
                                $safe_code = htmlspecialchars($ex['course_code'], ENT_QUOTES);

                                $status_badge = "<span id='{$badge_id}' class='badge badge-yellow'><i class='fa-solid fa-spinner fa-spin'></i> Syncing Time...</span>";
                                $action_html = "<button class='btn' disabled style='width:100%; background:var(--input-bg); color:var(--text-muted); cursor:not-allowed;'><i class='fa-solid fa-clock'></i> Please Wait...</button>";

                               if($has_taken) {
                                $sub_time = strtotime($res_data['submitted_at']);
                                $result_release_time = $sub_time + (10 * 60); // Submitted + 10 mins
                                
                                if($current_time >= $result_release_time) {
                                    $status_badge = "<span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981;'><i class='fa-solid fa-check-double'></i> Completed</span>";
                                    $action_html = "<div style='text-align:center; padding:15px; background:rgba(16,185,129,0.05); border-radius:12px; border:1px solid rgba(16,185,129,0.2);'>
                                                        <div style='font-size:11px; color:var(--text-muted); text-transform:uppercase; margin-bottom:5px;'>Your Score</div>
                                                        <strong style='font-size:24px; color:#10b981; font-weight:900;'>{$res_data['score']} <span style='font-size:16px; color:var(--text-muted);'>/ {$res_data['total_questions']}</span></strong>
                                                        <a href='take_exam.php?review={$ex['id']}' class='btn btn-sm' style='display:block; margin-top:10px; background:linear-gradient(135deg, #10b981, #059669); color:#fff; text-decoration:none; box-shadow:0 4px 10px rgba(16,185,129,0.3); padding:8px;'><i class='fa-solid fa-eye'></i> Review Answers</a>
                                                    </div>";
                                } else {
                                    $release_str = date("h:i A", $result_release_time);
                                    $status_badge = "<span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981;'><i class='fa-solid fa-check-double'></i> Submitted</span>";
                                    $action_html = "<div style='text-align:center; padding:15px; background:rgba(245,158,11,0.05); border-radius:12px; border:1px solid rgba(245,158,11,0.2);'>
                                                        <i class='fa-solid fa-clock-rotate-left' style='font-size:24px; color:#f59e0b; margin-bottom:10px; animation: fa-spin 3s linear infinite;'></i>
                                                        <div style='font-size:13px; color:var(--text-main); font-weight:600;'>Results compiling...</div>
                                                        <div style='font-size:11px; color:var(--text-muted); margin-top:5px;'>Check back at <strong style='color:#f59e0b;'>{$release_str}</strong></div>
                                                    </div>";
                                }
                            }

                                echo "
                                <div id='{$card_id}' class='exam-card magic-exam-card' data-id='{$ex['id']}' data-start-iso='{$iso_start}' data-duration='{$duration_mins}' data-title='{$safe_title}' data-code='{$safe_code}' data-taken='".($has_taken?1:0)."' style='background: var(--bg-color); border: 1px solid var(--border-color); border-left: 4px solid #f43f5e; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; box-shadow: 0 4px 10px rgba(0,0,0,0.05); transition: 0.5s;'>
                                <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;'>
                                        <span style='font-family:monospace; font-size:11px; font-weight:800; color:var(--text-muted); background:var(--input-bg); padding:3px 8px; border-radius:6px;'><i class='fa-solid fa-stopwatch' style='color:#f43f5e;'></i> {$ex['course_code']}</span>
                                        <div id='badge_container_{$ex['id']}'>{$status_badge}</div>
                                    </div>
                                    <h4 style='font-size:16px; font-weight:800; color:var(--text-main); margin-bottom:5px;'>{$ex['title']}</h4>
                                    <div style='font-size:12px; color:var(--text-muted); margin-bottom:15px;'><i class='fa-solid fa-user-tie'></i> Tr. {$ex['teacher_name']}</div>
                                    
                                    <div style='display:flex; justify-content:space-between; align-items:center; background:var(--panel-bg); padding:10px 15px; border-radius:8px; border:1px dashed rgba(255,255,255,0.1); margin-bottom: 20px;'>
                                        <span style='font-size:11.5px; font-weight:700; color:var(--text-main);'><i class='fa-regular fa-calendar-check' style='color:#f43f5e;'></i> {$time_str}</span>
                                        <span style='font-size:11.5px; font-weight:700; color:var(--warning);'><i class='fa-solid fa-hourglass-half'></i> {$duration_mins} mins</span>
                                    </div>

                                    <div id='{$btn_area_id}' style='margin-top:auto;'>{$action_html}</div>
                                </div>";
                            }
                        } else { echo "<div class='info-alert' style='grid-column:1/-1;'><i class='fa-solid fa-circle-info'></i> No exams scheduled for you currently.</div>"; }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB 5: MY GRADES                               -->
        <!-- ============================================== -->
        <div id="grades" class="section-tab">
            <div class="premium-panel" style="border-top-color: #f59e0b; padding:30px;">
                <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-star-half-stroke"></i></div> Official Grade Report</h3>
                <p style="font-size:13px; color:var(--text-muted); margin-bottom:25px;">These grades have been finalized and published by your instructors.</p>

                <div class="hide-scrollbar" style="overflow-x:auto;">
                    <table style="width: 100%; border-collapse: separate; border-spacing: 0; background: var(--bg-color); border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color);">
                        <tr style="background: rgba(245,158,11,0.05);">
                            <th style="padding:15px; color:#f59e0b; border-right:1px solid var(--border-color);">Course Name & Code</th>
                            <th style="text-align:center;">Att (10)</th>
                            <th style="text-align:center;">Assig (10)</th>
                            <th style="text-align:center;">Proj (15)</th>
                            <th style="text-align:center;">Quiz (15)</th>
                            <th style="text-align:center;">Mid (20)</th>
                            <th style="text-align:center;">Final (30)</th>
                            <th style="text-align:center; background:rgba(16,185,129,0.1); color:#10b981; font-size:13px;">Total</th>
                            <th style="text-align:center; background:rgba(59,130,246,0.1); color:#3b82f6; font-size:13px;">Grade</th>
                        </tr>
                        <?php
                        $gr_q = mysqli_query($conn, "SELECT g.*, c.course_name, c.course_code FROM student_grades g JOIN course c ON g.course_id=c.id WHERE g.student_id=$student_id AND g.is_published=1 ORDER BY c.course_name ASC");
                        if(mysqli_num_rows($gr_q) > 0){
                            while($g = mysqli_fetch_assoc($gr_q)){
                                $l_color = '#f43f5e';
                                if(in_array($g['grade_letter'],['A+','A','A-'])) $l_color = '#10b981';
                                elseif(in_array($g['grade_letter'],['B+','B','B-'])) $l_color = '#3b82f6';
                                elseif(in_array($g['grade_letter'], ['C+','C'])) $l_color = '#f59e0b';
                                
                                echo "<tr style='border-bottom:1px solid var(--border-color);'>
                                        <td style='padding:15px; border-right:1px solid var(--border-color);'><strong style='color:var(--text-main); font-size:14px;'>{$g['course_name']}</strong><br><span style='font-family:monospace; font-size:11px; color:var(--text-muted);'>{$g['course_code']}</span></td>
                                        <td style='text-align:center; padding:15px; font-weight:600;'>{$g['attendance']}</td>
                                        <td style='text-align:center; padding:15px; font-weight:600;'>{$g['assignment']}</td>
                                        <td style='text-align:center; padding:15px; font-weight:600;'>{$g['project']}</td>
                                        <td style='text-align:center; padding:15px; font-weight:600;'>{$g['quiz']}</td>
                                        <td style='text-align:center; padding:15px; font-weight:600;'>{$g['mid_exam']}</td>
                                        <td style='text-align:center; padding:15px; font-weight:600;'>{$g['final_exam']}</td>
                                        <td style='text-align:center; padding:15px; background:rgba(16,185,129,0.02);'><strong style='color:#10b981; font-size:16px;'>{$g['total_score']}</strong></td>
                                        <td style='text-align:center; padding:15px; background:rgba(59,130,246,0.02);'><strong style='color:{$l_color}; font-size:20px;'>{$g['grade_letter']}</strong></td>
                                      </tr>";
                            }
                        } else { echo "<tr><td colspan='9' style='text-align:center; padding:40px; color:var(--text-muted);'><i class='fa-solid fa-lock' style='font-size:40px; opacity:0.3; margin-bottom:15px; display:block;'></i>No published grades available yet.</td></tr>"; }
                        ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 6: ACADEMIC CALENDAR                       -->
        <!-- ============================================== -->
        <div id="calendar" class="section-tab">
            <div class="premium-panel" style="padding: 35px; border-top-color: #ec4899;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                    <h3 class="panel-title-premium" style="margin:0; border:none; padding:0; color:#ec4899;">
                        <div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0 15px 0 0; background:linear-gradient(135deg, #ec4899, #be185d);"><i class="fa-regular fa-calendar-days"></i></div>
                        My Academic Calendar
                    </h3>
                    <div style="display:flex; gap:10px;">
                        <button class="btn btn-sm" onclick="changeMonth(-1)" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color);"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                        <button class="btn btn-sm" onclick="changeMonth(0, true)" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); font-weight:800;">Today</button>
                        <button class="btn btn-sm" onclick="changeMonth(1)" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color);">Next <i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>

                <h2 id="calendar-month-year" style="text-align:center; font-weight:800; font-size:24px; margin-bottom:20px; color:var(--text-main);">Month Year</h2>

                <!-- 🪄 MAGIC: Border color made explicit to show grid lines clearly -->
                <div style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    <div style="display: grid; grid-template-columns: repeat(7, 1fr); background: #f8fafc; border-bottom: 1px solid #e2e8f0; text-align: center; font-weight: 800; font-size: 13px; color: #475569; padding: 15px 0; text-transform:uppercase;">
                        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                    </div>
                    <!-- Gap 1px creates the grid lines using the container's background color -->
                    <div id="calendar-grid" style="display: grid; grid-template-columns: repeat(7, 1fr); grid-auto-rows: minmax(120px, auto); gap: 1px; background: #e2e8f0;">
                        <!-- JS generated cells go here -->
                    </div>
                </div>

                <!-- Legend -->
                <div style="display:flex; justify-content:center; gap:25px; margin-top:25px; font-size:13px; font-weight:700; color:var(--text-muted);">
                    <span style="display:flex; align-items:center; gap:8px;"><span style="width:14px; height:14px; border-radius:4px; background:#f43f5e;"></span> Exams / Quizzes</span>
                    <span style="display:flex; align-items:center; gap:8px;"><span style="width:14px; height:14px; border-radius:4px; background:#f59e0b;"></span> Assignments</span>
                    <span style="display:flex; align-items:center; gap:8px;"><span style="width:14px; height:14px; border-radius:4px; background:#8b5cf6;"></span> Projects</span>
                </div>
            </div>

            <!-- UPCOMING DEADLINES -->
            <div class="premium-panel" style="padding: 35px; border-top-color: #3b82f6;">
                <h3 class="panel-title-premium" style="margin-bottom:20px; border-bottom:2px dashed var(--border-color); padding-bottom:15px;"><i class="fa-solid fa-list-check" style="color:#3b82f6; margin-right:10px;"></i> Upcoming Deadlines</h3>
                <div id="upcoming-events-list" style="display:flex; flex-direction:column; gap:15px;">
                    <!-- JS generated list -->
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB 7: COMMUNICATIONS                          -->
        <!-- ============================================== -->
        <div id="broadcast" class="section-tab">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin: 0;"><i class="fa-brands fa-telegram" style="color: #0ea5e9;"></i> Secure Communications</h3>
                <span style="font-size:12.5px; color:var(--text-muted); background:var(--input-bg); padding:8px 15px; border-radius:20px; border:1px solid var(--border-color); font-weight:600;"><i class="fa-solid fa-lock" style="color:var(--success);"></i> End-to-End Encrypted</span>
            </div>

            <div class="telegram-app">
                <div class="tg-sidebar">
                    <div class="tg-search-bar" style="padding: 15px 20px;">
                        <div class="input-with-icon" style="margin:0;"><input type="text" id="tg-search" placeholder="Search..." onkeyup="filterTelegramChats()" style="padding:12px 15px 12px 45px !important; border-radius:20px; font-size:13.5px; border-color:transparent; background:rgba(0,0,0,0.2);"><i class="fa-solid fa-magnifying-glass"></i></div>
                    </div>
                  <div class="tg-folders">
                        <div class="tg-folder active" onclick="switchFolder('all')">All Chats</div>
                        <div class="tg-folder" onclick="switchFolder('head')">HoD</div>
                        <div class="tg-folder" onclick="switchFolder('teacher')">My Teachers</div>
                        <div class="tg-folder" onclick="switchFolder('student')">Classmates</div> <!-- 🪄 Folder haaraa barattootaaf dabalame -->
                    </div>
                  <div class="tg-contacts" id="tg-contacts-list">
                        
                        <?php
                        // Helper function for avatars
                        if (!function_exists('getAvatar')) {
                            function getAvatar($pic, $name, $bg, $color, $locked) {
                                if($locked == 1) return ['type'=>'locked', 'html'=>"<i class='fa-solid fa-user-lock' style='font-size:18px; color:#fff;'></i>", 'url'=>'LOCKED'];
                                $url = (!empty($pic) && file_exists("../uploads/".$pic)) ? "../uploads/".$pic : "https://ui-avatars.com/api/?name=".urlencode($name)."&background=$bg&color=$color&bold=true";
                                return ['type'=>'img', 'html'=>"<img src='$url' style='width:100%;height:100%;border-radius:50%;object-fit:cover;'>", 'url'=>$url];
                            }
                        }

                        // 👑 Head of Dept (Boss)
                        $h_q = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM head WHERE id=(SELECT dept_id FROM student WHERE id=$student_id LIMIT 1)");
                        if($h = mysqli_fetch_assoc($h_q)){
                            $av = getAvatar($h['profile_pic'], $h['name'], '8b5cf6', 'fff', $h['profile_locked']);
                            $extra_badge = $av['type'] == 'img' ? "<i class='fa-solid fa-circle-check' style='position:absolute; bottom:-2px; right:-2px; color:#a78bfa; background:#fff; border-radius:50%; font-size:12px; z-index:5;'></i>" : "";
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-head' id='contact_head_{$h['id']}' onclick=\"openTelegramChat({$h['id']}, 'head', 0, '".addslashes($h['name'])."', 'Dept Head', '#8b5cf6', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#8b5cf6;' id='avatar_head_{$h['id']}'>{$av['html']}{$extra_badge}</div>
                                    <div class='tg-info'><span class='tg-name'>{$h['name']} <i class='fa-solid fa-circle-check' style='color:#a78bfa; font-size:12px;' title='Verified Head'></i></span><span class='tg-role'>Head of Dept</span></div>
                                    <span class='chat-unread-badge' id='badge_head_{$h['id']}'>0</span>
                                  </div>";
                        }
                        
                        // 👨‍🏫 Teachers of this student's enrolled courses
                        $t_list = mysqli_query($conn, "SELECT DISTINCT t.id, t.name, t.profile_pic, t.profile_locked FROM teacher t JOIN teacher_course tc ON t.id=tc.teacher_id JOIN course c ON tc.course_id=c.id WHERE t.dept_id=$dept_id AND t.is_deleted=0");
                        while($t = mysqli_fetch_assoc($t_list)){
                            $av = getAvatar($t['profile_pic'], $t['name'], '10b981', 'fff', $t['profile_locked']);
                            echo "<div class='tg-contact-item chat-item-all chat-item-teacher' id='contact_teacher_{$t['id']}' onclick=\"openTelegramChat({$t['id']}, 'teacher', 0, 'Tr. ".addslashes($t['name'])."', 'Course Instructor', '#10b981', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#10b981;' id='avatar_teacher_{$t['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>Tr. {$t['name']}</span><span class='tg-role' style='color:#10b981;'>Instructor</span></div>
                                    <span class='chat-unread-badge' id='badge_teacher_{$t['id']}'>0</span>
                                  </div>";
                        }

                        // 🎓 My Classmates (Other Students in the same Dept)
                        if(isset($stu_info['is_rep']) && $stu_info['is_rep'] == 1) {
                            echo "<div class='tg-contact-item chat-item-all chat-item-student' onclick=\"openTelegramChat(0, 'student', 1, '📢 All Classmates', 'Broadcast to Students', '#f43f5e', '')\">
                                    <div class='tg-avatar group' style='background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 0 4px 10px rgba(244,63,94,0.3);'><i class='fa-solid fa-bullhorn'></i></div>
                                    <div class='tg-info'><span class='tg-name'>📢 All Classmates</span><span class='tg-role' style='color:#f59e0b;'><i class='fa-solid fa-crown'></i> Class Rep Broadcast</span></div>
                                  </div>";
                        }

                        $s_list = mysqli_query($conn, "SELECT id, first_name, last_name, profile_pic, profile_locked, is_rep FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 AND id!=$student_id ORDER BY is_rep DESC, first_name ASC");
                        while($s = mysqli_fetch_assoc($s_list)){
                            $full_name = $s['first_name'] . ' ' . $s['last_name'];
                            $av = getAvatar($s['profile_pic'], $full_name, 'f43f5e', 'fff', $s['profile_locked']);
                            
                            // 🪄 MAGIC: Rep Badge for Communications
                            $rep_badge = (isset($s['is_rep']) && $s['is_rep'] == 1) ? "<i class='fa-solid fa-crown' style='color:#f59e0b; margin-left:5px; font-size:12px; filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.5));' title='Class Representative'></i>" : "";
                            $role_display = (isset($s['is_rep']) && $s['is_rep'] == 1) ? "<span class='tg-role' style='color:#f59e0b; font-weight:bold;'>Class Rep</span>" : "<span class='tg-role'>Classmate</span>";

                            echo "<div class='tg-contact-item chat-item-all chat-item-student' id='contact_student_{$s['id']}' onclick=\"openTelegramChat({$s['id']}, 'student', 0, '".addslashes($full_name)."', 'Classmate', '#f43f5e', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#f43f5e;' id='avatar_student_{$s['id']}'>{$av['html']}</div>
                                    <div class='tg-info'>
                                        <span class='tg-name'>{$full_name} {$rep_badge}</span>
                                        {$role_display}
                                    </div>
                                    <span class='chat-unread-badge' id='badge_student_{$s['id']}'>0</span>
                                  </div>";
                        }
                        ?>
                    </div>
                </div>
                <div class="tg-chat-area">
                    <div id="tg-placeholder" class="tg-placeholder">
                        <div style="width:120px; height:120px; background:var(--input-bg); border-radius:50%; display:flex; justify-content:center; align-items:center; margin-bottom:20px; border:2px dashed var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.1);"><i class="fa-regular fa-comments" style="font-size: 50px; color:var(--text-muted); margin:0;"></i></div>
                        <h3 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:5px;">EPLMS Secure Messaging</h3>
                        <p style="font-size:14px; color:var(--text-muted);">Select a chat from the sidebar to communicate with your faculty.</p>
                    </div>
                    <div id="tg-active-chat" style="display:none; flex-direction:column; height:100%;">
                        <div class="tg-chat-header">
                            <div class="tg-avatar group" id="chat-header-avatar" style="background:var(--primary);"></div>
                            <div><div class="tg-chat-title" id="chat-header-name">Chat Name</div><div class="tg-chat-status" id="chat-header-role">Online</div></div>
                        </div>
                        <div class="tg-chat-history" id="chat-history-container"></div>
                        <div class="tg-chat-input-area">
                            <form id="tg-chat-form" onsubmit="submitTelegramMsg(event)" class="tg-chat-form">
                                <input type="hidden" name="chat_receiver_id" id="chat_receiver_id">
                                <input type="hidden" name="chat_receiver_role" id="chat_receiver_role">
                                <input type="hidden" name="chat_is_group" id="chat_is_group">
                                <input type="hidden" name="edit_msg_id" id="edit_msg_id">
                                <input type="text" name="chat_message" id="chat_message_input" placeholder="Message..." required autocomplete="off">
                                <button type="submit" style="background: linear-gradient(135deg, var(--primary), #be123c);"><i class="fa-solid fa-paper-plane"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: SECURITY COMMAND CENTER (STUDENT)         -->
        <!-- ============================================== -->
        <div id="audit" class="section-tab">
            
            <!-- HEADER -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                <div>
                    <h3 style="font-size: 24px; font-weight: 800; color: var(--danger); margin: 0; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-shield-halved"></i> My Security Command Center</h3>
                    <p style="color: var(--text-muted); font-size: 13.5px; margin-top: 5px;">Monitor your personal account authentication history and active sessions.</p>
                </div>
                <span style="background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 8px 15px; border-radius: 20px; font-weight: 700; font-size: 12px; border: 1px solid rgba(16, 185, 129, 0.2); box-shadow: 0 0 10px rgba(16, 185, 129, 0.3); animation: pulse-badge 2s infinite;"><i class="fa-solid fa-lock"></i> Account Secured</span>
            </div>

            <?php
            // 🪄 Fetch personal security stats
            $my_logs_q = mysqli_query($conn, "SELECT status, COUNT(*) as c FROM login_logs WHERE username='$student_name' GROUP BY status");
            $success_cnt = 0; $fail_cnt = 0;
            while($st = mysqli_fetch_assoc($my_logs_q)) {
                if($st['status'] == 'success') $success_cnt = $st['c'];
                if($st['status'] == 'failed') $fail_cnt = $st['c'];
            }
            $last_ip_q = mysqli_query($conn, "SELECT ip_address FROM login_logs WHERE username='$student_name' AND status='success' ORDER BY attempt_time DESC LIMIT 1");
            $last_ip = ($last_ip_q && mysqli_num_rows($last_ip_q)>0) ? mysqli_fetch_assoc($last_ip_q)['ip_address'] : 'Unknown';
            ?>

            <!-- MINI STATS GRID -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px;">
                <div style="background: var(--panel-bg); border: 1px solid var(--border-color); border-left: 4px solid var(--success); padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); transition: 0.3s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
                    <div style="color:var(--text-muted); font-size:12px; font-weight:700; text-transform:uppercase; margin-bottom:10px;">Successful Logins</div>
                    <div style="font-size:24px; font-weight:800; color:var(--text-main);"><i class="fa-solid fa-check-circle" style="color:var(--success); font-size:20px; margin-right:10px;"></i> <?php echo $success_cnt; ?></div>
                </div>
                <div style="background: var(--panel-bg); border: 1px solid var(--border-color); border-left: 4px solid var(--danger); padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); transition: 0.3s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
                    <div style="color:var(--text-muted); font-size:12px; font-weight:700; text-transform:uppercase; margin-bottom:10px;">Failed Attempts</div>
                    <div style="font-size:24px; font-weight:800; color:var(--text-main);"><i class="fa-solid fa-triangle-exclamation" style="color:var(--danger); font-size:20px; margin-right:10px;"></i> <?php echo $fail_cnt; ?></div>
                </div>
                <div style="background: var(--panel-bg); border: 1px solid var(--border-color); border-left: 4px solid var(--primary); padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); transition: 0.3s;" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform='translateY(0)'">
                    <div style="color:var(--text-muted); font-size:12px; font-weight:700; text-transform:uppercase; margin-bottom:10px;">Last Known IP</div>
                    <div style="font-size:18px; font-weight:800; color:var(--primary); font-family:monospace; margin-top:5px;"><i class="fa-solid fa-network-wired" style="font-size:16px; margin-right:10px;"></i> <?php echo $last_ip; ?></div>
                </div>
            </div>

            <!-- PREMIUM TABLE -->
            <div class="premium-panel" style="border-top-color: var(--danger); padding: 0; overflow:hidden;">
                <div style="padding: 25px 30px; border-bottom: 1px solid var(--border-color); background: linear-gradient(145deg, rgba(244, 63, 94, 0.05), transparent);">
                    <h3 class="panel-title-premium" style="color: var(--danger); border:none; padding:0; margin:0;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-clock-rotate-left"></i></div> Authentication History</h3>
                </div>
                
                <div style="overflow-x:auto; max-height: 500px; overflow-y: auto;" class="hide-scrollbar">
                    <table style="width:100%; border-collapse: collapse;">
                        <tr style="background: rgba(0,0,0,0.2); position:sticky; top:0; z-index:2; text-align: left;">
                            <th style="padding:15px 30px; border-radius:0;">Time & Date</th>
                            <th style="padding:15px;">IP Address</th>
                            <th style="padding:15px;">Device / Browser Agent</th>
                            <th style="text-align:right; padding:15px 30px;">Status</th>
                        </tr>
                        <?php
                        $logs = mysqli_query($conn, "SELECT * FROM login_logs WHERE username='$student_name' ORDER BY attempt_time DESC LIMIT 30");
                        if(mysqli_num_rows($logs)>0){
                            while($l=mysqli_fetch_assoc($logs)){
                                $time = date("M d, Y - h:i A", strtotime($l['attempt_time']));
                                $s_badge = '';
                                $bg_hover = '';
                                
                                // Colors & Badges based on status
                                if($l['status'] == 'success') { 
                                    $s_badge = "<span class='badge' style='background:rgba(16, 185, 129, 0.1); color:#10b981; border:1px solid rgba(16,185,129,0.3);'><i class='fa-solid fa-check'></i> OK</span>"; 
                                    $bg_hover = "rgba(16,185,129,0.03)";
                                }
                                elseif($l['status'] == 'otp_sent') { 
                                    $s_badge = "<span class='badge' style='background:rgba(59,130,246,0.1); color:#3b82f6; border:1px solid rgba(59,130,246,0.3);'><i class='fa-solid fa-envelope'></i> OTP</span>"; 
                                    $bg_hover = "rgba(59,130,246,0.03)";
                                }
                                else { 
                                    $s_badge = "<span class='badge badge-red' style='background:rgba(244,63,94,0.1); color:#f43f5e; border:1px solid rgba(244,63,94,0.3);'><i class='fa-solid fa-xmark'></i> FAIL</span>"; 
                                    $bg_hover = "rgba(244,63,94,0.03)";
                                }
                                
                                // 🪄 MAGIC DEVICE DETECTION (Laptop vs Phone Icon)
                                $agent = $l['user_agent'];
                                $dev_icon = "fa-globe";
                                if(stripos($agent, 'Mobile') !== false || stripos($agent, 'Android') !== false || stripos($agent, 'iPhone') !== false) { 
                                    $dev_icon = "fa-mobile-screen-button"; 
                                } elseif(stripos($agent, 'Windows') !== false || stripos($agent, 'Macintosh') !== false || stripos($agent, 'Linux') !== false) { 
                                    $dev_icon = "fa-laptop"; 
                                }

                                echo "<tr style='border-bottom: 1px solid rgba(255,255,255,0.03); transition:0.3s;' onmouseover=\"this.style.background='{$bg_hover}'\" onmouseout=\"this.style.background='transparent'\">
                                        <td style='padding:18px 30px; font-size:12.5px; color:var(--text-muted); font-weight:600;'><i class='fa-regular fa-clock' style='margin-right:8px; opacity:0.7;'></i> {$time}</td>
                                        <td style='padding:18px 15px; font-family:monospace; color:var(--text-main); font-weight:700; font-size:13.5px;'>{$l['ip_address']}</td>
                                        <td style='padding:18px 15px; font-size:12px; color:var(--text-muted);'><i class='fa-solid {$dev_icon}' style='color:var(--primary); margin-right:8px; font-size:14px;'></i> ".substr($agent,0,50)."...</td>
                                        <td style='padding:18px 30px; text-align:right;'>{$s_badge}</td>
                                      </tr>";
                            }
                        } else { 
                            echo "<tr><td colspan='4' style='text-align:center; padding:50px; color:var(--text-muted);'><i class='fa-solid fa-shield-cat' style='font-size:50px; opacity:0.3; margin-bottom:15px; display:block;'></i><h3 style='color:var(--text-main); font-size:18px; margin-bottom:5px;'>No records found</h3><p style='font-size:13px;'>Your login activity will appear here.</p></td></tr>"; 
                        }
                        ?>
                    </table>
                </div>
            </div>
        </div>
<!-- ============================================== -->
        <!-- TAB: ULTIMATE HELP CENTER (KNOWLEDGE BASE)     -->
        <!-- ============================================== -->
        <div id="help" class="section-tab">
            <div class="help-hero" style="background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); border-radius: 20px; padding: 50px 30px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin-bottom: 30px; border-bottom: 5px solid #f43f5e;">
                <div style="position: absolute; top: -50px; left: -50px; width: 250px; height: 250px; background: radial-gradient(circle, rgba(244,63,94,0.15) 0%, transparent 70%); border-radius: 50%; filter: blur(30px);"></div>
                <div class="icon-box" style="width: 80px; height: 80px; font-size: 35px; margin: 0 auto 20px auto; background: linear-gradient(135deg, #f43f5e, #be123c); box-shadow: 0 10px 25px rgba(244, 63, 94, 0.4); border-radius: 20px; position:relative; z-index:2;">
                    <i class="fa-solid fa-user-astronaut"></i>
                </div>
                <h2 style="font-size: 34px; color: #ffffff; font-weight: 800; margin-bottom: 15px; position:relative; z-index:2;">RLMS Student Guidebook</h2>
                <p style="font-size: 15px; color: #cbd5e1; max-width: 750px; margin: 0 auto 30px; line-height: 1.8; position:relative; z-index:2;">
                    Welcome to your comprehensive student manual. Learn how to navigate the system, submit assignments, take strictly-timed secure exams, and understand the cybersecurity protocols that protect your account.
                </p>
                <div class="input-with-icon" style="max-width: 600px; margin: 0 auto; position:relative; z-index:2;">
                    <input type="text" id="help-search-input" placeholder="Search guides, exams, assignments, security..." onkeyup="searchHelpTopics()" style="padding: 16px 20px 16px 50px !important; border-radius: 30px; border: 2px solid #f43f5e; background: var(--panel-bg); color: var(--text-main); font-size: 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.2);">
                    <i class="fa-solid fa-magnifying-glass" style="color: #f43f5e; font-size: 18px; left: 22px;"></i>
                </div>
            </div>

            <div id="help-content-wrapper">
                
                <!-- TOPIC 1: ASSIGNMENTS -->
                <div class="premium-panel" style="border-top-color: #f59e0b; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-list-check"></i></div> 1. Submitting Assignments & Projects</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            1.1 How to submit your work <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Go to the <strong>Assignments & Projects</strong> tab. You will see a list of "Pending Tasks". Click the <span style="color:#fff; background:#f59e0b; padding:4px 8px; border-radius:6px; font-weight:bold; font-size:12px;"><i class="fa-solid fa-upload"></i> Turn In</span> button next to an assignment. The submission portal will magically appear on the right side.</p>
                            <p style="margin-top:10px;">Fill in the "Submission Title" and attach your file (PDF, DOCX, ZIP, etc.). Click Submit. The system will automatically notify your teacher!</p>
                        </div>
                    </div>

                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            1.2 Deadlines & Overdue Status <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Pay close attention to the <strong>Due Date</strong>. If the deadline passes, you can still submit your work, but your submission will be flagged with a <strong style="color:var(--danger);">"Submitted Late"</strong> badge in red. This might affect your final grade depending on your teacher's policy.</p>
                        </div>
                    </div>
                </div>

                <!-- TOPIC 2: EXAMS & QUIZZES -->
                <div class="premium-panel" style="border-top-color: #f43f5e; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-stopwatch"></i></div> 2. Taking Secure Exams</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            2.1 Access Codes & Live Time Sync <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <div class="info-alert error" style="margin-top:0; border-left-color:var(--danger); background:rgba(244,63,94,0.05); padding:15px; border-radius:8px;">
                                <strong style="color:#f43f5e;"><i class="fa-solid fa-triangle-exclamation"></i> Strict Time Window:</strong> Exams are synchronized strictly with the server clock. You CANNOT open an exam before it starts, and you CANNOT enter after it ends.
                            </div>
                            <p style="margin-top:15px;">When an exam reaches its start time, it will flash <strong style="color:#10b981; background:rgba(16,185,129,0.1); padding:2px 8px; border-radius:6px;">LIVE NOW</strong>. Click it, and you will be prompted for a <strong>Secret Access Code</strong>. Your teacher will provide this code in class or via the Communications hub.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            2.2 Auto-Submission & AI Grading <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Once you enter the exam, a countdown timer will appear at the top. <strong>If the timer reaches 00:00:00, the system will automatically force-submit your answers.</strong></p>
                            <p style="margin-top:10px;">RLMS uses an Auto-Grading Engine. Multiple-choice questions are graded instantly. For Essay questions, the AI scans your answer for required keywords set by the teacher.</p>
                            <p style="margin-top:10px;">Results are delayed by exactly <strong>10 minutes</strong> after submission to prevent cheating.</p>
                        </div>
                    </div>
                </div>

                <!-- TOPIC 3: SECURITY & PRIVACY -->
                <div class="premium-panel" style="border-top-color: #10b981; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-shield-halved"></i></div> 3. Cybersecurity & Your Account</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            3.1 Two-Factor Authentication (2FA) <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>To protect your grades and personal data, go to the <strong>Settings</strong> tab and enable <strong>Two-Factor Auth</strong>.</p>
                            <p style="margin-top:10px;">When you try to log in, the system will email a 6-digit OTP code to your registered email address. This guarantees that even if someone steals your password, they cannot access your dashboard without your email.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            3.2 The Auto-Ban Protocol (Anti-Hacking) <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>The RLMS is equipped with Military-Grade Anti-Brute Force security. <strong>If anyone inputs the wrong password 3 times in a row, their device (IP Address) is automatically BANNED for 24 hours.</strong></p>
                            <p style="margin-top:10px;">If you accidentally get yourself banned, you must physically contact your Head of Department to Unban your IP.</p>
                        </div>
                    </div>
                </div>

            </div>
            
            <div style="text-align: center; margin-top: 20px; color: var(--text-muted); font-size: 13px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
                <p>EPLMS Student Documentation v2.5 <br> Empowering education through seamless technology.</p>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- TAB: ABOUT RLMS                                -->
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
                    The Exam Portal & Learning Management System (EPLMS) has officially evolved into the <strong>Registration & Learning Management System (RLMS)</strong>. A complete paradigm shift designed specifically for Bule Hora University students.
                </p>
            </div>

            <!-- The RLMS Vision & Core Features -->
            <div class="premium-panel" style="border-top-color: #3b82f6; padding: 40px;">
                <h3 class="panel-title-premium" style="font-size: 22px;"><div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-rocket"></i></div> Student Empowerments</h3>
                
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
                    <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-solid fa-star-half-stroke" style="font-size: 30px; color: #10b981; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Transparent Grading</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">The Master Gradebook calculates your exact score out of 100% and displays your corresponding Letter Grade (A, B, C...) the moment your teacher publishes it.</p>
                    </div>
                    
                    <div style="background: rgba(14, 165, 233, 0.05); border: 1px solid rgba(14, 165, 233, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-brands fa-telegram" style="font-size: 30px; color: #0ea5e9; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Faculty Connectivity</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">A fully integrated, real-time AJAX chat system. You can directly message your Head of Department or any instructor teaching your active courses.</p>
                    </div>

                    <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); padding: 25px; border-radius: 16px; transition: 0.3s;" onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-regular fa-calendar-days" style="font-size: 30px; color: #f59e0b; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Smart Calendar</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">Your dashboard automatically extracts deadlines from assignments, projects, and exams, placing them directly onto your personalized interactive calendar.</p>
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
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Holds absolute power over the entire university system.</p>
                        </div>
                    </div>

                    <!-- 2. College Admin -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1; margin-left: 20px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-building-columns"></i></div>
                        <div style="background:rgba(59,130,246,0.05); border:1px solid rgba(59,130,246,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(59,130,246,0.1)'" onmouseout="this.style.background='rgba(59,130,246,0.05)'">
                            <h4 style="color:#3b82f6; font-size:16px; font-weight:800; margin-bottom:5px;">2. College Admin (Campus Logic)</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Manages a specific college and assigns Department Heads.</p>
                        </div>
                    </div>

                    <!-- 3. Department Head -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1; margin-left: 40px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #6d28d9); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-users-gear"></i></div>
                        <div style="background:rgba(139,92,246,0.05); border:1px solid rgba(139,92,246,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(139,92,246,0.1)'" onmouseout="this.style.background='rgba(139,92,246,0.05)'">
                            <h4 style="color:#8b5cf6; font-size:16px; font-weight:800; margin-bottom:5px;">3. Department Head</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Controls the department's ecosystem and approves student registrations.</p>
                        </div>
                    </div>

                    <!-- 4. Teacher -->
                    <div style="display: flex; gap: 20px; margin-bottom: 20px; position: relative; z-index: 1; margin-left: 60px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #047857); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); padding:20px; border-radius:16px; flex: 1; transition: 0.3s;" onmouseover="this.style.background='rgba(16,185,129,0.1)'" onmouseout="this.style.background='rgba(16,185,129,0.05)'">
                            <h4 style="color:#10b981; font-size:16px; font-weight:800; margin-bottom:5px;">4. Teacher / Faculty (Course Logic)</h4>
                            <p style="color:var(--text-muted); font-size:13.5px; line-height:1.6; margin:0;">Responsible for delivering education and grading students securely.</p>
                        </div>
                    </div>

                    <!-- 5. Student -->
                    <div style="display: flex; gap: 20px; position: relative; z-index: 1; margin-left: 80px;">
                        <div style="width: 70px; height: 70px; min-width: 70px; border-radius: 50%; background: linear-gradient(135deg, #f43f5e, #be123c); display: flex; justify-content: center; align-items: center; font-size: 25px; color: #fff; box-shadow: 0 5px 15px rgba(244, 63, 94, 0.4); border: 4px solid var(--panel-bg);"><i class="fa-solid fa-user-graduate"></i></div>
                        <div style="background:rgba(244,63,94,0.08); border:1px solid rgba(244,63,94,0.3); padding:20px; border-radius:16px; flex: 1; box-shadow: 0 5px 20px rgba(244, 63, 94, 0.1); transform: scale(1.02); z-index: 2;">
                            <h4 style="color:#f43f5e; font-size:16px; font-weight:800; margin-bottom:5px;">5. Enrolled Student (Learner) <span class="badge" style="margin-left:10px; background:var(--primary); color:#fff; border:none;">You Are Here</span></h4>
                            <p style="color:var(--text-main); font-size:13.5px; line-height:1.6; margin:0;">The end-user. Accesses course materials, submits assignments, takes secure exams, and chats with their respective teachers.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tech Stack Footer -->
            <div style="text-align: center; margin-top: 20px; padding: 35px 20px; border-top: 1px dashed var(--border-color); background: rgba(0,0,0,0.1); border-radius: 16px;">
                <p style="color: var(--text-muted); font-size: 14px; line-height:1.8;">
                    <strong>Designed & Developed for Modern Universities</strong>
                    <br>RLMS Core Architecture v2.5 | Enterprise Edition
                </p>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: ABOUT RLMS                                -->
        <!-- ============================================== -->
        <div id="about" class="section-tab">
            <div class="help-hero" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); border-bottom: 5px solid #3b82f6; padding: 60px 30px;">
                <div style="display: flex; justify-content: center; align-items: center; gap: 30px; margin-bottom: 25px;">
                    <div class="icon-box" style="width: 90px; height: 90px; font-size: 40px; margin: 0; background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 10px 30px rgba(59, 130, 246, 0.5); border-radius: 25px;"><i class="fa-solid fa-graduation-cap"></i></div>
                    <i class="fa-solid fa-arrow-right-arrow-left" style="font-size: 35px; color: #60a5fa; opacity: 0.7;"></i>
                    <div class="icon-box" style="width: 90px; height: 90px; font-size: 40px; margin: 0; background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.5); border-radius: 25px;"><i class="fa-solid fa-network-wired"></i></div>
                </div>
                <h2 style="font-size: 42px; margin-bottom: 15px; text-shadow: 0 5px 15px rgba(0,0,0,0.5);">EPLMS <span style="color:#60a5fa;">is now</span> RLMS</h2>
                <p style="font-size: 17px; color: #e2e8f0; max-width: 900px; margin: 0 auto; line-height: 1.9; font-weight: 500;">
                    The Exam Portal & Learning Management System (EPLMS) has officially evolved into the <strong>Registration & Learning Management System (RLMS)</strong>. A complete paradigm shift designed specifically for Bule Hora University students.
                </p>
            </div>

            <div class="premium-panel" style="border-top-color: #3b82f6; padding: 40px;">
                <h3 class="panel-title-premium" style="font-size: 22px;"><div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-rocket"></i></div> Student Empowerments</h3>
                
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
                    <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 25px; border-radius: 16px;">
                        <i class="fa-solid fa-star-half-stroke" style="font-size: 30px; color: #10b981; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Transparent Grading</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">The Master Gradebook calculates your exact score out of 100% and displays your corresponding Letter Grade (A, B, C...) the moment your teacher publishes it.</p>
                    </div>
                    
                    <div style="background: rgba(14, 165, 233, 0.05); border: 1px solid rgba(14, 165, 233, 0.2); padding: 25px; border-radius: 16px;">
                        <i class="fa-brands fa-telegram" style="font-size: 30px; color: #0ea5e9; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Faculty Connectivity</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">A fully integrated, real-time AJAX chat system. You can directly message your Head of Department or any instructor teaching your active courses.</p>
                    </div>

                    <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); padding: 25px; border-radius: 16px;">
                        <i class="fa-regular fa-calendar-days" style="font-size: 30px; color: #f59e0b; margin-bottom: 15px;"></i>
                        <h4 style="font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 10px;">Smart Calendar</h4>
                        <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">Your dashboard automatically extracts deadlines from assignments, projects, and exams, placing them directly onto your personalized interactive calendar.</p>
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 20px; padding: 35px 20px; border-top: 1px dashed var(--border-color); background: rgba(0,0,0,0.1); border-radius: 16px;">
                <p style="color: var(--text-muted); font-size: 14px; line-height:1.8;">
                    <strong>RLMS Enterprise Edition</strong>
                    <br>Developed securely for the students of tomorrow.
                </p>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- 8. SETTINGS & PROFILE (ULTIMATE PREMIUM UI)    -->
        <!-- ============================================== -->
        <div id="settings" class="section-tab">
            
            <!-- 🌟 Premium Profile Header 🌟 -->
            <div class="profile-header-card" style="background: linear-gradient(135deg, #be123c 0%, #881337 100%); border-bottom: 5px solid var(--primary); border-radius: 24px; padding: 45px 20px; text-align: center; position: relative; margin-bottom: 35px; box-shadow: 0 15px 40px var(--primary-glow); overflow: hidden;">
                <div class="profile-avatar-wrapper" style="width: 130px; height: 130px; margin: 0 auto 20px auto; position: relative;">
                    <img src="<?php echo $profile_pic; ?>" alt="Profile" class="profile-avatar-large" id="preview_avatar_top" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid #fecdd3; box-shadow: 0 10px 25px rgba(0,0,0,0.4);">
                    <label for="pic_upload" class="edit-avatar-btn" style="position: absolute; bottom: 5px; right: -5px; background: #fcd535; color: #000; width: 38px; height: 38px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 3px solid #881337; transition: 0.3s;"><i class="fa-solid fa-camera"></i></label>
                </div>
                <h2 style="color: #ffffff; font-size: 28px; font-weight: 800; margin-bottom: 8px;">
                    <?php echo $full_name; ?> 
                    <i class="fa-solid fa-circle-check" style="color:#fecdd3; font-size:22px;" title="Active Learner"></i>
                </h2>
                <p style="color: #fecdd3; font-size: 15px; margin-bottom: 20px; font-weight: 500;"><i class="fa-solid fa-id-card"></i> <?php echo htmlspecialchars($stu_info['id_number']); ?></p>
                <div style="display: flex; justify-content: center; gap: 10px;">
                    <span class="badge" style="background: rgba(255,255,255,0.2); color: #fff; border: 1px solid rgba(255,255,255,0.4); padding: 8px 15px;"><i class="fa-solid fa-sitemap"></i> <?php echo htmlspecialchars($stu_info['dept_name']); ?></span>
                </div>
            </div>

            <!-- 📝 Main Settings Form 📝 -->
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="profile_pic" id="pic_upload" style="display:none;" onchange="previewImage(this)">
                
                <div class="settings-grid">
                    
           <!-- LEFT PANEL: PERSONAL INFO -->
                    <div class="premium-panel" style="border-top-color: var(--primary); margin-bottom:0;">
                        <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, var(--primary), #be123c);"><i class="fa-solid fa-user-pen"></i></div> Personal Settings</h3>
                        
                        <div class="info-alert" style="background: rgba(244, 63, 94, 0.05); border-left: 4px solid var(--danger);">
                            <strong style="color: var(--danger);"><i class="fa-solid fa-rotate"></i> Global Live-Sync</strong>
                            Changes made to your Email, Username, or Phone will instantly update your profile on your Teachers' dashboards.
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                            <div class="form-group" style="margin:0;"><label>First Name (Locked)</label><div class="input-with-icon"><input type="text" value="<?php echo htmlspecialchars($stu_info['first_name']); ?>" disabled style="opacity:0.6; cursor:not-allowed;"><i class="fa-solid fa-user"></i></div></div>
                            <div class="form-group" style="margin:0;"><label>Last Name (Locked)</label><div class="input-with-icon"><input type="text" value="<?php echo htmlspecialchars($stu_info['last_name']); ?>" disabled style="opacity:0.6; cursor:not-allowed;"><i class="fa-solid fa-user"></i></div></div>
                        </div>
                        
                        <div class="form-group"><label>University ID Number (Locked)</label><div class="input-with-icon"><input type="text" value="<?php echo htmlspecialchars($stu_info['id_number']); ?>" disabled style="opacity:0.6; cursor:not-allowed;"><i class="fa-solid fa-id-card"></i></div></div>
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                            <div class="form-group" style="margin:0;"><label style="color:var(--primary); font-weight:800;">Email Address (Editable)</label><div class="input-with-icon"><input type="email" name="s_email" value="<?php echo htmlspecialchars($stu_info['email']); ?>" required style="border-color:rgba(244,63,94,0.5);"><i class="fa-solid fa-envelope" style="color:var(--primary);"></i></div></div>
                            <div class="form-group" style="margin:0;"><label style="color:var(--primary); font-weight:800;">Username (Editable)</label><div class="input-with-icon"><input type="text" name="s_username" value="<?php echo htmlspecialchars($stu_info['username']); ?>" required style="border-color:rgba(244,63,94,0.5);"><i class="fa-solid fa-at" style="color:var(--primary);"></i></div></div>
                        </div>

                        <div class="form-group"><label style="color:var(--primary); font-weight:800;">Phone Number (Editable)</label><div class="input-with-icon"><input type="text" name="s_phone" value="<?php echo htmlspecialchars($stu_info['phone'] ?? ''); ?>" required style="border-color:rgba(244,63,94,0.5);"><i class="fa-solid fa-phone" style="color:var(--primary);"></i></div></div>
                    </div>

                    <!-- RIGHT PANEL: SECURITY POLICIES -->
                    <div class="premium-panel" style="border-top-color: #3b82f6; margin-bottom:0;">
                        <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-shield-virus"></i></div> Security Policies</h3>
                        
                        <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.6;">Configure how your account authenticates logins and how your profile appears to other users.</p>

                        <!-- 2FA Toggle -->
                        <div class="sec-toggle" style="background: rgba(16, 185, 129, 0.03); border: 1px solid rgba(16, 185, 129, 0.2);">
                            <div class="sec-toggle-info">
                                <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-mobile-screen-button" style="color:#10b981; margin-right:8px;"></i> Two-Factor Auth (2FA)</h4>
                                <p style="color: var(--text-muted); font-size: 12.5px;">Require a dynamic OTP code sent to your email during every login attempt.</p>
                            </div>
                            <label class="switch"><input type="checkbox" name="two_factor" <?php echo (!empty($stu_info['two_factor_enabled'])) ? 'checked' : ''; ?>><span class="slider" style="background-color: #10b981;"></span></label>
                        </div>
                        
                        <!-- Login Alerts Toggle -->
                        <div class="sec-toggle">
                            <div class="sec-toggle-info">
                                <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-bell" style="color:#3b82f6; margin-right:8px;"></i> Login Alerts</h4>
                                <p style="color: var(--text-muted); font-size: 12.5px;">Receive an email notification on new device login attempts.</p>
                            </div>
                            <label class="switch"><input type="checkbox" name="login_alerts" <?php echo (!isset($stu_info['login_alerts']) || $stu_info['login_alerts'] == 1) ? 'checked' : ''; ?>><span class="slider"></span></label>
                        </div>

                        <!-- Profile Privacy Toggle -->
                        <div class="sec-toggle" style="border-left: 4px solid var(--warning);">
                            <div class="sec-toggle-info">
                                <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-user-lock" style="color:var(--warning); margin-right:8px;"></i> Profile Privacy Lock</h4>
                                <p style="color: var(--text-muted); font-size: 12.5px;">Hide your Avatar photo from Teachers in the communications hub.</p>
                            </div>
                            <label class="switch"><input type="checkbox" name="profile_locked" <?php echo (!empty($stu_info['profile_locked'])) ? 'checked' : ''; ?>><span class="slider" style="background-color: var(--warning);"></span></label>
                        </div>
                    </div>
                </div>

                <!-- 🚨 BOTTOM SAVE SECTION (ALWAYS VISIBLE - DANGER ZONE) 🚨 -->
                <div class="premium-panel" style="margin-top: 25px; border: 1px solid rgba(244, 63, 94, 0.3); border-top: 5px solid var(--danger); box-shadow: 0 10px 40px rgba(244, 63, 94, 0.08); background: linear-gradient(180deg, var(--panel-bg) 0%, rgba(244, 63, 94, 0.03) 100%);">
                    <h3 class="panel-title-premium" style="color: var(--danger); border-bottom-color: rgba(244, 63, 94, 0.1); margin-bottom: 20px;">
                        <i class="fa-solid fa-fingerprint" style="font-size: 22px;"></i> Final Security Authorization
                    </h3>
                    <p style="color: var(--text-muted); font-size: 13.5px; margin-bottom: 25px; line-height: 1.6;">To apply any changes to your profile or security policies, you must verify your identity using your current password.</p>
                    
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
                        <button type="submit" name="save_settings" class="glow-btn" style="background: linear-gradient(135deg, #f43f5e 0%, #be123c 100%); box-shadow: 0 10px 25px rgba(244, 63, 94, 0.4);">
                            <i class="fa-solid fa-shield-check" style="font-size: 20px;"></i> Save & Authenticate
                        </button>
                    </div>
                </div>
            </form>
        </div>

    </div>
</main>


<!-- 🪄 SECURE EXAM GATEWAY MODAL (FIXED) -->
<div id="examAuthModal" class="modal-overlay">
    <div class="modal-box" style="border-top: 4px solid #f43f5e; max-width:400px; padding:35px; border-radius:24px;">
        <div class="icon-box" style="width:70px; height:70px; margin:0 auto 20px auto; background:rgba(244,63,94,0.1); color:#f43f5e; font-size:28px; border-radius:50%; display:flex; justify-content:center; align-items:center; box-shadow:0 10px 20px rgba(244,63,94,0.2);">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h3 style="color:#f43f5e; font-size: 22px; font-weight:800; margin-bottom: 10px;">Secure Exam Gateway</h3>
        <p style="font-size:14px; color:var(--text-muted); margin-bottom:25px; line-height:1.5;">Enter the Secret Access Code provided by your instructor to begin <br><strong id="auth_exam_title" style="color:var(--text-main); font-size:15px; margin-top:5px; display:block;"></strong></p>
        
        <form action="take_exam.php" method="POST">
            <input type="hidden" name="exam_id" id="auth_exam_id">
            
            <div class="form-group pw-group" style="margin-bottom: 25px;">
                <div class="input-with-icon" style="position:relative;">
                    <input type="password" name="student_access_code" id="auth_code" placeholder="Enter Access Code" required style="width: 100%; border: 2px solid #f43f5e; background: rgba(244,63,94,0.05); text-align:center; font-size:20px; font-weight:800; letter-spacing:5px; color:var(--text-main); padding: 15px; border-radius:12px; outline:none; transition:0.3s;" onfocus="this.style.boxShadow='0 0 0 4px rgba(244,63,94,0.2)'" onblur="this.style.boxShadow='none'">
                    <i class="fa-solid fa-key" style="color:#f43f5e; position:absolute; left:15px; top:50%; transform:translateY(-50%);"></i>
                    <i class="fa-solid fa-eye pw-eye" onclick="togglePw('auth_code', this)" style="color:#f43f5e; position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer;"></i>
                </div>
            </div>
            
            <div style="display:flex; gap:15px;">
                <button type="button" class="btn" style="flex:1; background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:12px; font-size:15px; font-weight:700;" onclick="document.getElementById('examAuthModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="enter_exam" class="glow-btn" style="flex:1.5; justify-content:center; background:linear-gradient(135deg, #f43f5e, #be123c); border-radius:12px; font-size:15px; box-shadow:0 8px 25px rgba(244,63,94,0.4); border:none;"><i class="fa-solid fa-door-open"></i> Begin Exam</button>
            </div>
        </form>
    </div>
</div>

<!-- CUSTOM RIGHT CLICK MENU (CHAT) -->
<div id="chat-context-menu" class="chat-context-menu"><div class="context-item" id="ctx-edit"><i class="fa-solid fa-pen"></i> Edit Message</div><div class="context-item delete" id="ctx-delete"><i class="fa-solid fa-trash"></i> Delete Message</div></div>
<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const themeIcon = document.getElementById('theme-icon');
    function toggleTheme() { document.body.classList.toggle('light-mode'); const isLight = document.body.classList.contains('light-mode'); localStorage.setItem('eplms_theme', isLight ? 'light' : 'dark'); if(themeIcon) themeIcon.className = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon'; }
    if(localStorage.getItem('eplms_theme') === 'light'){ document.body.classList.add('light-mode'); if(themeIcon) themeIcon.className = 'fa-solid fa-sun'; }
    
    function animateCounters() { document.querySelectorAll('.counter').forEach(counter => { counter.innerText = '0'; const target = +counter.getAttribute('data-target'); const inc = target / 30; const update = () => { const c = +counter.innerText; if(c < target) { counter.innerText = Math.ceil(c + inc); setTimeout(update, 30); } else { counter.innerText = target; } }; update(); }); }
    
    function updateClock() { const now = new Date(); let h = now.getHours(); let m = now.getMinutes(); let s = now.getSeconds(); document.getElementById('real-time-clock').innerText = `${h%12||12}:${m<10?'0'+m:m}:${s<10?'0'+s:s} ${h>=12?'PM':'AM'}`; } setInterval(updateClock, 1000); updateClock();

    // 🪄 MAGIC SUBMISSION FORM LOGIC (INLINE SPA)
    function openSubmitForm(id, title, code) {
        openTab('assignments');
        document.getElementById('submit_placeholder').style.display = 'none';
        
        let formArea = document.getElementById('submit_form_area');
        formArea.style.display = 'flex';
        
        document.getElementById('form_sub_mat_id').value = id;
        document.getElementById('disp_sub_title').innerText = title;
        document.getElementById('disp_sub_code').innerText = code;
        
        formArea.style.opacity = '0';
        formArea.style.transform = 'translateY(20px)';
        setTimeout(() => {
            formArea.style.transition = 'all 0.4s ease';
            formArea.style.opacity = '1';
            formArea.style.transform = 'translateY(0)';
        }, 50);

        formArea.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    function openExamAuth(id, title, code) {
        document.getElementById('auth_exam_id').value = id;
        document.getElementById('auth_exam_title').innerText = title + " (" + code + ")";
        document.getElementById('auth_code').value = '';
        document.getElementById('examAuthModal').classList.add('active');
    }

    // 🪄 MAGIC EXAM AUTO-OPENER TIMER
    function initMagicExamTimers() {
        const serverTimeNowMs = <?php echo time() * 1000; ?>;
        const clientTimeNowMs = new Date().getTime();
        const timeDiff = serverTimeNowMs - clientTimeNowMs; 

        setInterval(() => {
            let now = new Date().getTime() + timeDiff; 
            
            document.querySelectorAll('.magic-exam-card').forEach(card => {
                let taken = parseInt(card.getAttribute('data-taken'));
                if(taken === 1) return; 

                let startAttr = card.getAttribute('data-start-iso');
                let startMs = startAttr ? new Date(startAttr).getTime() : 0;
                let durationAttr = card.getAttribute('data-duration');
                let endMs = startMs + (parseInt(durationAttr) * 60000);

                let id = card.getAttribute('data-id');
                let title = card.getAttribute('data-title');
                let code = card.getAttribute('data-code');

                let badgeContainer = document.getElementById('badge_container_' + id);
                let btnArea = document.getElementById('exam_btn_area_' + id);
                
                if(!badgeContainer || !btnArea) return;

                if(now < startMs) {
                    let diffMs = startMs - now;
                    let h = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    let m = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
                    let s = Math.floor((diffMs % (1000 * 60)) / 1000);
                    let timeStr = (h > 0 ? h + "h " : "") + m + "m " + s + "s";

                    if(!badgeContainer.innerHTML.includes('Upcoming')) {
                        card.style.borderColor = 'var(--border-color)';
                        badgeContainer.innerHTML = `<span class='badge badge-yellow'><i class='fa-solid fa-clock'></i> Upcoming</span>`;
                        btnArea.innerHTML = `<button class='btn magic-wait-btn' disabled style='width:100%; background:var(--input-bg); color:var(--text-muted); cursor:not-allowed; transition: 0.4s;'><i class='fa-solid fa-hourglass-half'></i> Starts in: <strong class='time-left' style='color:var(--warning); margin-left:5px;'></strong></button>`;
                    }
                    let timeLeftSpan = btnArea.querySelector('.time-left');
                    if(timeLeftSpan) timeLeftSpan.innerText = timeStr;
                }
                else if (now >= startMs && now <= endMs) {
                    if(!badgeContainer.innerHTML.includes('LIVE NOW')) {
                        card.style.borderColor = '#10b981';
                        card.style.boxShadow = '0 0 20px rgba(16,185,129,0.2)';
                        badgeContainer.innerHTML = `<span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; animation:pulse-badge 1.5s infinite;'><i class='fa-solid fa-tower-broadcast'></i> LIVE NOW</span>`;
                        btnArea.innerHTML = `<button class='glow-btn magic-enter-btn' style='width:100%; justify-content:center; background:linear-gradient(135deg, #10b981, #059669); box-shadow:0 8px 20px rgba(16,185,129,0.4); color:#fff; animation: fadeIn 0.5s ease;' onclick="openExamAuth(${id}, '${title}', '${code}')"><i class='fa-solid fa-play'></i> Enter Access Code</button>`;
                    }
                }
                else if (now > endMs) {
                    if(!badgeContainer.innerHTML.includes('Missed')) {
                        card.style.borderColor = 'var(--border-color)';
                        card.style.boxShadow = 'none';
                        card.style.opacity = '0.7';
                        badgeContainer.innerHTML = `<span class='badge badge-red'><i class='fa-solid fa-ban'></i> Missed</span>`;
                        btnArea.innerHTML = `<button class='btn' disabled style='width:100%; background:rgba(244,63,94,0.1); color:#f43f5e; cursor:not-allowed;'><i class='fa-solid fa-xmark'></i> Exam Closed</button>`;
                    }
                }
            });
        }, 1000);
    }

    function previewImage(input) { if(input.files && input.files[0]){ let reader = new FileReader(); reader.onload = function(e){ document.getElementById('preview_avatar_top').src = e.target.result; }; reader.readAsDataURL(input.files[0]); } }

    // Telegram Chat Functions
    let currentChatId=null, currentChatRole=null, currentChatIsGroup=null, chatInterval=null;
    function switchFolder(folder) { document.querySelectorAll('.tg-folder').forEach(el=>el.classList.remove('active')); event.currentTarget.classList.add('active'); document.querySelectorAll('.tg-contact-item').forEach(el=>{ el.style.display='none'; if(el.classList.contains('chat-item-'+folder)) el.style.display='flex'; }); }
    function openTelegramChat(id, role, isGroup, name, subtitle, color, avatar_url = '') { document.getElementById('tg-placeholder').style.display='none'; document.getElementById('tg-active-chat').style.display='flex'; document.getElementById('chat-header-name').innerHTML=name; document.getElementById('chat-header-role').innerText=subtitle; const avatarDiv = document.getElementById('chat-header-avatar'); avatarDiv.style.background = color; avatarDiv.style.position = 'relative'; if(isGroup === 1) { avatarDiv.innerHTML = '<i class="fa-solid fa-bullhorn"></i>'; avatarDiv.classList.add('group'); } else { if(avatar_url === 'LOCKED') { avatarDiv.innerHTML = '<i class="fa-solid fa-user-lock" style="font-size:20px; color:#fff;"></i>'; avatarDiv.style.background = color; } else if(avatar_url && avatar_url !== '') { let imgHtml = `<img src="${avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`; if(role === 'head') { imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#a78bfa; background:#fff; border-radius:50%; font-size:14px; border:2px solid var(--panel-bg); z-index:10;"></i>`; } avatarDiv.innerHTML = imgHtml; avatarDiv.style.background = 'transparent'; } else { avatarDiv.innerHTML = name.replace(/<[^>]*>?/gm, '').trim().charAt(0).toUpperCase(); } avatarDiv.classList.remove('group'); avatarDiv.style.borderRadius = '50%'; } currentChatId=id; currentChatRole=role; currentChatIsGroup=isGroup; document.getElementById('chat_receiver_id').value=id; document.getElementById('chat_receiver_role').value=role; document.getElementById('chat_is_group').value=isGroup; document.getElementById('edit_msg_id').value=''; fetchChatMessages(); if(chatInterval) clearInterval(chatInterval); chatInterval=setInterval(fetchChatMessages, 2500); }
    function fetchChatMessages() { if(currentChatId === null) return; let fd=new FormData(); fd.append('ajax_action','fetch_chat'); fd.append('receiver_id',currentChatId); fd.append('receiver_role',currentChatRole); fd.append('is_group',currentChatIsGroup); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.text()).then(h=>{ const chatHistory = document.getElementById('chat-history-container'); let isAtBottom = chatHistory.scrollHeight - chatHistory.clientHeight <= chatHistory.scrollTop + 50; chatHistory.innerHTML=h; if(isAtBottom) chatHistory.scrollTop = chatHistory.scrollHeight; }); }
    function submitTelegramMsg(e) { e.preventDefault(); let input=document.getElementById('chat_message_input'); if(!input.value.trim())return; let fd=new FormData(document.getElementById('tg-chat-form')); fd.append('ajax_action', document.getElementById('edit_msg_id').value ? 'edit_msg' : 'send_msg'); fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.status==='success'){ input.value=''; document.getElementById('edit_msg_id').value=''; fetchChatMessages(); setTimeout(() => { const chatHistory = document.getElementById('chat-history-container'); chatHistory.scrollTop = chatHistory.scrollHeight; }, 100); }}); }
    
    function fetchUnreadBadges() { 
        let fd = new FormData(); fd.append('ajax_action', 'fetch_unread'); 
        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(data=>{ 
            document.querySelectorAll('.chat-unread-badge').forEach(b => b.style.display = 'none'); 
            for(let key in data) { 
                if(key !== 'total_all' && key !== 'new_materials' && key !== 'new_assignments' && key !== 'new_exams') { 
                    let badge = document.getElementById('badge_' + key); 
                    if(badge) { 
                        if(currentChatRole + '_' + currentChatId !== key) { 
                            badge.innerText = data[key]; badge.style.display = 'inline-block'; 
                        } else { fetchChatMessages(); } 
                    } 
                } 
            } 
            let mainBadge = document.getElementById('main_comm_badge'); 
            if(mainBadge) { if(data.total_all > 0) { mainBadge.innerText = data.total_all; mainBadge.style.display = 'inline-block'; mainBadge.style.position = 'absolute'; mainBadge.style.right = '15px'; mainBadge.style.top = '50%'; } else { mainBadge.style.display = 'none'; } }
            
            let matBadge = document.getElementById('badge_courses');
            if(matBadge) { if(data.new_materials > 0) { matBadge.innerText = data.new_materials; matBadge.style.display = 'inline-block'; } else { matBadge.style.display = 'none'; } }

            let assBadge = document.getElementById('badge_assignments');
            if(assBadge) { if(data.new_assignments > 0) { assBadge.innerText = data.new_assignments; assBadge.style.display = 'inline-block'; } else { assBadge.style.display = 'none'; } }

            let exBadge = document.getElementById('badge_exams');
            if(exBadge) { if(data.new_exams > 0) { exBadge.innerText = data.new_exams; exBadge.style.display = 'inline-block'; } else { exBadge.style.display = 'none'; } }
        }).catch(err => console.log(err)); 
    } 
    setInterval(fetchUnreadBadges, 2000); fetchUnreadBadges();
    
    function deleteMessage(msgId) { if(!confirm("Are you sure you want to delete this message?")) return; let fd = new FormData(); fd.append('ajax_action', 'delete_msg'); fd.append('msg_id', msgId); fetch(window.location.href, { method: 'POST', body: fd }).then(res => res.json()).then(data => { if(data.status === 'success') fetchChatMessages(); }); }
    function editMessage(msgId, text) { document.getElementById('chat_message_input').value = text; document.getElementById('edit_msg_id').value = msgId; document.getElementById('chat_message_input').focus(); }

    let ctxMenuMsgId = null; let ctxMenuMsgText = "";
    function showContextMenu(e, msgId, msgText) { e.preventDefault(); const ctxMenu = document.getElementById('chat-context-menu'); ctxMenuMsgId = msgId; ctxMenuMsgText = msgText; ctxMenu.style.display = 'block'; let x = e.pageX; let y = e.pageY; if(x + ctxMenu.offsetWidth > window.innerWidth) x = window.innerWidth - ctxMenu.offsetWidth - 10; if(y + ctxMenu.offsetHeight > window.innerHeight) y = window.innerHeight - ctxMenu.offsetHeight - 10; ctxMenu.style.left = x + 'px'; ctxMenu.style.top = y + 'px'; }
    document.addEventListener('click', function(e) { const ctxMenu = document.getElementById('chat-context-menu'); if(ctxMenu.style.display === 'block') ctxMenu.style.display = 'none'; });
    document.getElementById('ctx-edit').addEventListener('click', function() { if(ctxMenuMsgId) editMessage(ctxMenuMsgId, ctxMenuMsgText); });
    document.getElementById('ctx-delete').addEventListener('click', function() { if(ctxMenuMsgId) deleteMessage(ctxMenuMsgId); });
    function filterTelegramChats() { let input = document.getElementById('tg-search').value.toLowerCase(); document.querySelectorAll('.tg-contact-item').forEach(item => { let name = item.querySelector('.tg-name').innerText.toLowerCase(); item.style.display = name.indexOf(input) > -1 ? "flex" : "none"; }); }

    // 🪄 HELP CENTER MAGIC ACCORDION
    function toggleHelpAcc(btn) { 
        const item = btn.parentElement; const content = btn.nextElementSibling; const icon = btn.querySelector('i.fa-chevron-down');
        document.querySelectorAll('.help-accordion-item').forEach(otherItem => {
            if(otherItem !== item) { otherItem.classList.remove('active'); let otherContent = otherItem.querySelector('.help-acc-content'); let otherIcon = otherItem.querySelector('i.fa-chevron-down'); if(otherContent) otherContent.style.display = "none"; if(otherIcon) otherIcon.style.transform = "rotate(0deg)"; }
        });
        item.classList.toggle('active'); 
        if (item.classList.contains('active')) { content.style.display = "block"; icon.style.transform = "rotate(180deg)"; icon.style.color = 'var(--primary)'; } 
        else { content.style.display = "none"; icon.style.transform = "rotate(0deg)"; icon.style.color = 'var(--text-muted)'; }
    }

    function searchHelpTopics() { let input = document.getElementById('help-search-input').value.toLowerCase(); document.querySelectorAll('.help-accordion-item').forEach(item => { let text = item.innerText.toLowerCase(); item.style.display = text.indexOf(input) > -1 ? "block" : "none"; }); }
    function togglePw(id, icon) { let input = document.getElementById(id); if (input.type === "password") { input.type = "text"; icon.classList.remove("fa-eye"); icon.classList.add("fa-eye-slash"); } else { input.type = "password"; icon.classList.remove("fa-eye-slash"); icon.classList.add("fa-eye"); } }
    function checkPasswordStrength() { let pw = document.getElementById('new_pass').value; let rulesBox = document.getElementById('pw-rules'); if (pw.length > 0) rulesBox.style.display = 'block'; else rulesBox.style.display = 'none'; updateRule('rule-length', pw.length >= 8); let hasUpper = /[A-Z]/.test(pw); let hasLower = /[a-z]/.test(pw); updateRule('rule-upper', hasUpper && hasLower); updateRule('rule-number', /[0-9]/.test(pw)); updateRule('rule-special', /[!@#$%^&*(),.?":{}|<>]/.test(pw)); }
    function updateRule(id, isValid) { let el = document.getElementById(id); if(!el) return; let icon = el.querySelector('i'); if(isValid) { el.style.color = '#10b981'; icon.className = 'fa-solid fa-circle-check'; icon.style.color = '#10b981'; } else { el.style.color = '#f43f5e'; icon.className = 'fa-solid fa-circle-xmark'; icon.style.color = '#f43f5e'; } }

    setTimeout(() => { let alert = document.querySelector('.alert'); if(alert) alert.style.display = 'none'; }, 4000);

    // 🪄 MAGIC ACADEMIC CALENDAR
    let currentDate = new Date();
    const eventsData = <?php echo $calendar_events_json; ?>;

    function renderCalendar() {
        const monthYear = document.getElementById('calendar-month-year');
        const grid = document.getElementById('calendar-grid');
        const upcomingList = document.getElementById('upcoming-events-list');
        
        if(!grid) return; grid.innerHTML = ''; upcomingList.innerHTML = '';
        const year = currentDate.getFullYear(); const month = currentDate.getMonth();
        const monthNames =["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        monthYear.innerText = `${monthNames[month]} ${year}`;

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        for (let i = 0; i < firstDay; i++) { let emptyCell = document.createElement('div'); emptyCell.style.background = '#f8fafc'; emptyCell.style.opacity = '0.5'; grid.appendChild(emptyCell); }

        let today = new Date(); let upcomingHtml = ''; let upcomingCount = 0;

        for (let i = 1; i <= daysInMonth; i++) {
            let cell = document.createElement('div');
            cell.style.background = 'var(--panel-bg)'; cell.style.padding = '10px'; cell.style.position = 'relative'; cell.style.display = 'flex'; cell.style.flexDirection = 'column'; cell.style.alignItems = 'flex-start'; cell.style.transition = '0.3s';
            cell.onmouseover = () => cell.style.background = 'rgba(244,63,94,0.02)'; cell.onmouseout = () => cell.style.background = 'var(--panel-bg)';
            
            let isToday = (year === today.getFullYear() && month === today.getMonth() && i === today.getDate());
            if (isToday) { cell.style.background = 'rgba(244,63,94,0.05)'; cell.onmouseout = () => cell.style.background = 'rgba(244,63,94,0.05)'; cell.innerHTML = `<div style="width:28px; height:28px; background:#f43f5e; color:#fff; border-radius:50%; display:flex; justify-content:center; align-items:center; font-weight:800; font-size:13px; margin-bottom:10px; box-shadow:0 4px 10px rgba(244,63,94,0.4);">${i}</div>`; } 
            else { cell.innerHTML = `<div style="font-weight:700; font-size:15px; color:var(--text-main); margin-bottom:10px; padding-left:5px;">${i}</div>`; }

            let cellDateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
            let dayEvents = eventsData.filter(e => e.date === cellDateStr);
            dayEvents.forEach(ev => {
                let color = '#f43f5e'; let icon = 'fa-stopwatch'; let rgb = '244,63,94';
                if(ev.type === 'assignment') { color = '#f59e0b'; icon = 'fa-list-check'; rgb = '245,158,11'; }
                if(ev.type === 'project') { color = '#8b5cf6'; icon = 'fa-rocket'; rgb = '139,92,246'; }

                cell.innerHTML += `<div style="width:100%; background:rgba(${rgb}, 0.1); border-left:3px solid ${color}; color:${color}; font-size:11px; font-weight:700; padding:5px 8px; border-radius:4px; margin-bottom:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; transition:0.2s;" onmouseover="this.style.background='${color}'; this.style.color='#fff';" onmouseout="this.style.background='rgba(${rgb}, 0.1)'; this.style.color='${color}';" title="${ev.title} at ${ev.time}"><i class="fa-solid ${icon}"></i> ${ev.title}</div>`;

                let timeParts = ev.time.match(/(\d+):(\d+)\s(AM|PM)/);
                if(timeParts) {
                    let evHour = parseInt(timeParts[1]);
                    if(timeParts[3] === 'PM' && evHour !== 12) evHour += 12;
                    if(timeParts[3] === 'AM' && evHour === 12) evHour = 0;
                    let evDateTime = new Date(year, month, i, evHour, parseInt(timeParts[2]));
                    if(evDateTime >= today && upcomingCount < 5) {
                        upcomingHtml += `
                        <div style="display:flex; align-items:center; justify-content:space-between; padding:20px; border:1px solid var(--border-color); border-radius:12px; background:var(--bg-color); transition:0.3s; border-left: 4px solid ${color};" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 5px 15px rgba(${rgb},0.15)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                            <div style="display:flex; align-items:center; gap:20px;">
                                <div style="width:45px; height:45px; border-radius:12px; background:rgba(${rgb}, 0.1); color:${color}; display:flex; justify-content:center; align-items:center; font-size:20px;"><i class="fa-solid ${icon}"></i></div>
                                <div>
                                    <h4 style="font-size:16px; font-weight:800; color:var(--text-main); margin-bottom:4px;">${ev.title}</h4>
                                    <span style="font-size:12.5px; color:var(--text-muted); font-weight:500;"><i class="fa-regular fa-clock" style="color:${color};"></i> ${monthNames[month]} ${i}, ${year} • ${ev.time}</span>
                                </div>
                            </div>
                            <span class="badge" style="background:rgba(${rgb}, 0.1); color:${color}; border:none; padding:8px 15px; font-size:12px;">${ev.label}</span>
                        </div>`;
                        upcomingCount++;
                    }
                }
            });
            grid.appendChild(cell);
        }

        let totalCells = firstDay + daysInMonth;
        let remainingCells = (7 - (totalCells % 7)) % 7;
        for (let i = 0; i < remainingCells; i++) { let emptyCell = document.createElement('div'); emptyCell.style.background = '#f8fafc'; emptyCell.style.opacity = '0.5'; grid.appendChild(emptyCell); }
        if(upcomingHtml === '') upcomingHtml = '<div style="text-align:center; color:var(--text-muted); padding:30px; border:1px dashed var(--border-color); border-radius:12px;"><i class="fa-regular fa-calendar-check" style="font-size:40px; margin-bottom:15px; opacity:0.5;"></i><br>No upcoming deadlines or exams! Enjoy your free time.</div>';
        upcomingList.innerHTML = upcomingHtml;
    }

    function changeMonth(direction, isToday = false) { if(isToday) { currentDate = new Date(); } else { currentDate.setMonth(currentDate.getMonth() + direction); } renderCalendar(); }

    // 🌟 CHART.JS: TASK PROGRESS OVERVIEW (MAGIC DONUT) 🌟
    function initCharts() {
        const ctxTask = document.getElementById('studentTaskChart');
        if(ctxTask) {
            if(window.taskChart) { window.taskChart.destroy(); }
            
            window.taskChart = new Chart(ctxTask.getContext('2d'), {
                type: 'doughnut',
                data: { 
                    labels: ['Materials', 'Assignments', 'Exams', 'Completed'], 
                    datasets: [{ 
                        data: [
                            <?php echo $total_materials; ?>, 
                            <?php echo $total_assignments; ?>, 
                            <?php echo $upcoming_exams; ?>, 
                            <?php echo $completed_tasks; ?>
                        ], 
                        backgroundColor: ['#8b5cf6', '#0ea5e9', '#f43f5e', '#10b981'], 
                        borderWidth: 0, 
                        hoverOffset: 8 
                    }] 
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    cutout: '75%', 
                    plugins: { 
                        legend: { display: false },
                        tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', titleFont: { family: 'Inter', size: 13 }, bodyFont: { family: 'Inter', size: 14, weight: 'bold' }, padding: 10, cornerRadius: 8, displayColors: true }
                    }, 
                    animation: { animateScale: true, animateRotate: true } 
                }
            });
        }
    }

    function openTab(tabId) { 
        document.querySelectorAll('.section-tab').forEach(el=>el.classList.remove('active')); 
        document.querySelectorAll('.tab-link').forEach(el=>el.classList.remove('active')); 
        document.getElementById(tabId).classList.add('active'); 
        
        let targetBtn = document.querySelector(`.tab-link[onclick="openTab('${tabId}')"]`);
        if(targetBtn) targetBtn.classList.add('active');
        
        if(tabId === 'home') { 
            animateCounters(); 
            if(window.taskChart) window.taskChart.update(); 
        }
        
        if(tabId === 'courses' || tabId === 'assignments' || tabId === 'exams') {
            let badgeId = 'badge_' + tabId;
            let badge = document.getElementById(badgeId);
            if(badge && badge.style.display !== 'none') {
                badge.style.display = 'none';
                let fd = new FormData(); fd.append('ajax_action', 'mark_tab_read'); fd.append('tab_name', tabId);
                fetch(window.location.href, {method: 'POST', body: fd}).catch(err => console.log('Badge clear error:', err));
            }
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        initCharts(); 
        animateCounters();
        initMagicExamTimers();
        
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        
        if(activeTab) { openTab(activeTab); window.history.replaceState({}, document.title, window.location.pathname); } 
        else { openTab('home'); }
        
        if (typeof renderCalendar === "function") { renderCalendar(); }
    });

    // 🪄 MAGIC VIEW SWITCHER LOGIC
    function switchMaterialView(viewType) {
        const gridView = document.getElementById('material-grid-view');
        const listView = document.getElementById('material-list-view');
        const btnGrid = document.getElementById('btn-grid-view');
        const btnList = document.getElementById('btn-list-view');

        if(viewType === 'grid') {
            gridView.style.display = 'grid'; 
            listView.style.display = 'none';
            btnGrid.classList.add('active');
            btnList.classList.remove('active');
        } else {
            gridView.style.display = 'none';
            listView.style.display = 'block';
            btnList.classList.add('active');
            btnGrid.classList.remove('active');
        }
    }

    function toggleAccordion(btn) {
        const accordion = btn.parentElement;
        accordion.classList.toggle('active');
        const content = accordion.querySelector('.acc-content');
        content.style.display = accordion.classList.contains('active') ? 'block' : 'none';
    }

    // 🪄 MAGIC COURSE FILTER LOGIC
    function filterMaterialsByCourse() {
        let val = document.getElementById('course_filter').value;
        
        document.querySelectorAll('.course-category-wrapper').forEach(category => {
            if(val === 'all' || category.getAttribute('data-course-id') === val) { category.style.display = 'block'; } else { category.style.display = 'none'; }
        });

        document.querySelectorAll('.course-accordion').forEach(acc => {
            acc.style.display = (val === 'all' || acc.getAttribute('data-course-id') === val) ? 'block' : 'none';
        });
    }
</script>
</body>
</html>