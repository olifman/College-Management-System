<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include("../includes/config.php");

// ========================================================
// 🛡️ 1. SECURITY: TEACHER AUTHENTICATION
// ========================================================
if(!isset($_SESSION['username']) || $_SESSION['role'] != 'teacher'){
    header("Location: ../index.php");
    exit();
}

$teacher_name = $_SESSION['username'];
$teacher_id = $_SESSION['user_id'];
$dept_id = $_SESSION['dept_id'];
$message = ""; $msg_type = "success";

date_default_timezone_set('Africa/Addis_Ababa');

// ========================================================
// 🔧 Database Setup for Teacher & Auto-Migrations
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS teacher_activities (id INT AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL, action_type VARCHAR(100) NOT NULL, details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE)");
if(!is_dir('../uploads/materials')) { mkdir('../uploads/materials', 0777, true); } // Foldara meeshaan itti fe'amu uuma

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    student_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50) DEFAULT 'submitted',
    grade VARCHAR(20) DEFAULT NULL,
    sub_title VARCHAR(255) DEFAULT 'My Assignment',
    is_new TINYINT(1) DEFAULT 1
)");

// 🪄 MAGIC DATABASE FIX: Ensure missing columns exist in 'teacher' table
function addColTeacher($conn, $table, $col, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
    if(mysqli_num_rows($res) == 0) mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
}

addColTeacher($conn, 'teacher', 'phone', "VARCHAR(20) DEFAULT NULL");
addColTeacher($conn, 'teacher', 'profile_locked', "TINYINT(1) DEFAULT 0");
addColTeacher($conn, 'teacher', 'public_email', "VARCHAR(100) DEFAULT NULL");
addColTeacher($conn, 'teacher', 'app_password', "VARCHAR(255) DEFAULT NULL");
addColTeacher($conn, 'teacher', 'two_factor_enabled', "TINYINT(1) DEFAULT 0");
addColTeacher($conn, 'teacher', 'login_alerts', "TINYINT(1) DEFAULT 1");
// Fetch Teacher Profile & Dept Info
$teacher_info_q = mysqli_query($conn, "SELECT t.*, d.dept_name, d.college_id FROM teacher t JOIN departments d ON t.dept_id = d.id WHERE t.id=$teacher_id");
$teacher_info = mysqli_fetch_assoc($teacher_info_q);
$college_id = $teacher_info['college_id'];
$profile_pic = !empty($teacher_info['profile_pic']) && file_exists("../uploads/".$teacher_info['profile_pic']) ? "../uploads/".$teacher_info['profile_pic'] : "https://ui-avatars.com/api/?name=".urlencode($teacher_info['name'])."&background=10b981&color=fff";

// ========================================================
// 🚀 2. REAL-TIME CHAT AJAX API
// ========================================================
if(isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];
    
    // 1. Send Message
    if($action == 'send_msg') {
        $msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        $rec_id = intval($_POST['chat_receiver_id']);
        $rec_role = mysqli_real_escape_string($conn, $_POST['chat_receiver_role']);
        $is_group = intval($_POST['chat_is_group']);

        if(!empty($msg)) {
            if($is_group == 1) {
                // 🪄 BROADCAST TO ALL STUDENTS IN DEPT
                $users = mysqli_query($conn, "SELECT id FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0");
                while($u = mysqli_fetch_assoc($users)) {
                    mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($teacher_id, 'teacher', {$u['id']}, 'student', '$msg', 0, 0)");
                }
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($teacher_id, 'teacher', 0, 'student', '📢 BROADCAST: $msg', 1, 1)");
            } else {
                mysqli_query($conn, "INSERT INTO messages (sender_id, sender_role, receiver_id, receiver_role, message, is_group, is_read) VALUES ($teacher_id, 'teacher', $rec_id, '$rec_role', '$msg', 0, 0)");
            }
        }
        echo json_encode(['status'=>'success']); exit();
    }
    
    // 2. Edit Message
    if($action == 'edit_msg') {
        $msg_id = intval($_POST['msg_id']); $new_msg = mysqli_real_escape_string($conn, trim($_POST['chat_message']));
        mysqli_query($conn, "UPDATE messages SET is_edited=1, message='$new_msg' WHERE id=$msg_id AND sender_id=$teacher_id AND sender_role='teacher'");
        echo json_encode(['status'=>'success']); exit();
    }
    
    // 3. Delete Message
    if($action == 'delete_msg') {
        $msg_id = intval($_POST['msg_id']);
        mysqli_query($conn, "UPDATE messages SET is_deleted=1 WHERE id=$msg_id AND sender_id=$teacher_id AND sender_role='teacher'");
        echo json_encode(['status'=>'success']); exit();
    }
    
    // 4. Fetch Unread Badges (Chat & Submissions)
    if($action == 'fetch_unread') {
        $q = mysqli_query($conn, "SELECT sender_id, sender_role, COUNT(*) as c FROM messages WHERE receiver_id=$teacher_id AND receiver_role='teacher' AND is_read=0 AND is_group=0 GROUP BY sender_id, sender_role");
        $data = []; $total = 0;
        while($r = mysqli_fetch_assoc($q)){ $key = $r['sender_role'] . '_' . $r['sender_id']; $data[$key] = $r['c']; $total += $r['c']; }
        $data['total_all'] = $total; 
        
        $sub_q = mysqli_query($conn, "SELECT COUNT(*) as new_subs FROM submissions s JOIN materials m ON s.material_id = m.id WHERE m.teacher_id=$teacher_id AND s.is_new=1");
        $data['new_submissions'] = mysqli_fetch_assoc($sub_q)['new_subs'] ?? 0;

        echo json_encode($data); exit();
    }
    
    // 5. Mark Submissions Read
    if($action == 'mark_submissions_read') {
        mysqli_query($conn, "UPDATE submissions s JOIN materials m ON s.material_id = m.id SET s.is_new=0 WHERE m.teacher_id=$teacher_id");
        echo json_encode(['status'=>'success']); exit();
    }

    // 6. 🪄 CRITICAL FIX: Fetch Chat Messages
    if($action == 'fetch_chat') {
        $rec_id = intval($_POST['receiver_id']); 
        $rec_role = mysqli_real_escape_string($conn, $_POST['receiver_role']); 
        $is_group = intval($_POST['is_group']);
        
        if($is_group == 0) {
            mysqli_query($conn, "UPDATE messages SET is_read=1 WHERE sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$teacher_id AND receiver_role='teacher'");
        }
        
        if($is_group == 1) {
            $query = "SELECT * FROM messages WHERE is_group=1 AND receiver_role='$rec_role' AND sender_id=$teacher_id AND sender_role='teacher' ORDER BY sent_at ASC";
        } else {
            $query = "SELECT * FROM messages WHERE is_group=0 AND ((sender_id=$teacher_id AND sender_role='teacher' AND receiver_id=$rec_id AND receiver_role='$rec_role') OR (sender_id=$rec_id AND sender_role='$rec_role' AND receiver_id=$teacher_id AND receiver_role='teacher')) ORDER BY sent_at ASC";
        }
        
        $res = mysqli_query($conn, $query);
        
        if(!$res || mysqli_num_rows($res) == 0) { 
            echo "<div class='tg-placeholder' style='display:flex; flex-direction:column; justify-content:center; align-items:center; height:100%; opacity:0.5;'>
                    <i class='fa-solid fa-lock' style='font-size:40px; margin-bottom:15px; color:var(--text-muted);'></i>
                    <p style='color:var(--text-muted); font-size:14px;'>End-to-end encrypted chat. Say hello!</p>
                  </div>"; 
            exit(); 
        }

        $html = '';
        while($m = mysqli_fetch_assoc($res)){
            $is_me = ($m['sender_role'] == 'teacher' && $m['sender_id'] == $teacher_id);
            $align = $is_me ? 'chat-right' : 'chat-left';
            $time = date("M d, H:i", strtotime($m['sent_at']));
            $msg_text = nl2br(htmlspecialchars($m['message']));
            $status = '';
            
            if($m['is_deleted'] == 1) { 
                $msg_text = "<i style='color:var(--danger); opacity:0.8;'><i class='fa-solid fa-ban'></i> This message was deleted</i>"; 
                $status = "<span style='color:var(--danger);'>Deleted</span>"; 
            } 
            elseif($m['is_edited'] == 1) { 
                $status = "<span style='opacity:0.6;'><i class='fa-solid fa-pen'></i> Edited</span>"; 
            }
            
            $oncontext = ($is_me && $m['is_deleted'] == 0) ? "oncontextmenu='showContextMenu(event, {$m['id']}, \"".htmlspecialchars($m['message'], ENT_QUOTES)."\"); return false;'" : "";
            
            $html .= "<div class='chat-msg-wrapper {$align}' style='width:100%; display:flex; margin-bottom:15px;'>
                        <div class='chat-bubble' {$oncontext} style='cursor: context-menu;'>
                            <div class='chat-text'>{$msg_text}</div>
                            <div class='chat-meta'>{$time} {$status}</div>
                        </div>
                      </div>";
        }
        echo $html; 
        exit();
    }
}
// ========================================================
// ⚙️ 3. TEACHER SETTINGS
// ========================================================
// Redirect messages handle gochuuf
if(isset($_GET['updated']) && $_GET['updated'] == 1){
    $message = "Settings and Profile Updated Successfully!";
    $msg_type = "success";
    echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
}

if(isset($_POST['save_all_settings'])){
    $name = mysqli_real_escape_string($conn, trim($_POST['t_name']));
    $email = mysqli_real_escape_string($conn, trim($_POST['t_email'])); 
    $phone = mysqli_real_escape_string($conn, trim($_POST['t_phone']));
    $username = mysqli_real_escape_string($conn, trim($_POST['t_username']));
    
    // Toggles
    $two_factor = isset($_POST['two_factor']) ? 1 : 0;
    $login_alerts = isset($_POST['login_alerts']) ? 1 : 0;
    $profile_locked = isset($_POST['profile_locked']) ? 1 : 0;
    
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];

    $verify = mysqli_query($conn, "SELECT id FROM teacher WHERE id=$teacher_id AND password='$current_pass'");
    if(mysqli_num_rows($verify) > 0){
        if(isset($_FILES['profile_pic']['name']) && !empty($_FILES['profile_pic']['name'])){
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $file_name = "teacher_" . $teacher_id . "_" . time() . "." . $ext;
            if(move_uploaded_file($_FILES['profile_pic']['tmp_name'], "../uploads/" . $file_name)){
                mysqli_query($conn, "UPDATE teacher SET profile_pic='$file_name' WHERE id=$teacher_id");
            }
        }
        
        $pass_query = !empty($new_pass) ? "password='$new_pass'," : "";
        $sql = "UPDATE teacher SET name='$name', email='$email', phone='$phone', username='$username', $pass_query two_factor_enabled=$two_factor, login_alerts=$login_alerts, profile_locked=$profile_locked WHERE id=$teacher_id";
        
        if(mysqli_query($conn, $sql)){
            mysqli_query($conn, "INSERT INTO teacher_activities (teacher_id, action_type, details) VALUES ($teacher_id, 'Profile Update', 'Updated personal settings')");
            $_SESSION['username'] = $username; 
            header("Location: dashboard.php?updated=1"); 
            exit();
        } else { 
            $message = "Database Error: " . mysqli_error($conn); 
            $msg_type = "error"; 
            echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
        }
    } else { 
        $message = "Save Failed: Incorrect Current Password!"; 
        $msg_type = "error"; 
        echo "<script>window.addEventListener('DOMContentLoaded', () => openTab('settings'));</script>";
    }
}
// ========================================================
// 🌟 MASTER GRADEBOOK SETUP & LOGIC
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    student_id INT NOT NULL,
    teacher_id INT NOT NULL,
    attendance FLOAT DEFAULT 0,
    assignment FLOAT DEFAULT 0,
    project FLOAT DEFAULT 0,
    quiz FLOAT DEFAULT 0,
    mid_exam FLOAT DEFAULT 0,
    final_exam FLOAT DEFAULT 0,
    total_score FLOAT DEFAULT 0,
    grade_letter VARCHAR(5) DEFAULT NULL,
    is_published TINYINT(1) DEFAULT 0,
    edit_requested TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_grade (course_id, student_id)
)");

// 🪄 MAGIC: Table Haaraa Qabxiiwwan (Weights) Murteessuuf
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS course_grade_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    w_att FLOAT DEFAULT 10,
    w_ass FLOAT DEFAULT 10,
    w_proj FLOAT DEFAULT 15,
    w_quiz FLOAT DEFAULT 15,
    w_mid FLOAT DEFAULT 20,
    w_fin FLOAT DEFAULT 30,
    UNIQUE KEY unique_c_setting (course_id)
)");

// 🪄 POST LOGIC: Save Custom Grading Weights
if(isset($_POST['save_grade_settings'])){
    $c_id = intval($_POST['setting_course_id']);
    $w_att = floatval($_POST['w_att']); $w_ass = floatval($_POST['w_ass']);
    $w_proj = floatval($_POST['w_proj']); $w_quiz = floatval($_POST['w_quiz']);
    $w_mid = floatval($_POST['w_mid']); $w_fin = floatval($_POST['w_fin']);

    $total_weight = $w_att + $w_ass + $w_proj + $w_quiz + $w_mid + $w_fin;

    if($total_weight != 100) {
        $message = "Total weight must equal exactly 100%! Currently it is $total_weight%."; $msg_type = "error";
    } else {
        mysqli_query($conn, "INSERT INTO course_grade_settings (course_id, teacher_id, w_att, w_ass, w_proj, w_quiz, w_mid, w_fin) 
                             VALUES ($c_id, $teacher_id, $w_att, $w_ass, $w_proj, $w_quiz, $w_mid, $w_fin) 
                             ON DUPLICATE KEY UPDATE w_att=$w_att, w_ass=$w_ass, w_proj=$w_proj, w_quiz=$w_quiz, w_mid=$w_mid, w_fin=$w_fin");
        mysqli_query($conn, "INSERT INTO teacher_activities (teacher_id, action_type, details) VALUES ($teacher_id, 'Grading Criteria', 'Updated grading weights for course')");
        header("Location: dashboard.php?tab=gradebook&msg=settings_saved&c_id=$c_id"); exit();
    }
}
// POST LOGIC: Save or Publish Grades
if(isset($_POST['save_grades']) || isset($_POST['publish_grades'])) {
    $c_id = intval($_POST['grade_course_id']);
    $is_published = isset($_POST['publish_grades']) ? 1 : 0;
    
    $student_ids = $_POST['student_ids']; // Array of student IDs
    
    foreach($student_ids as $s_id) {
        $att = floatval($_POST['att_'.$s_id]);
        $ass = floatval($_POST['ass_'.$s_id]);
        $proj = floatval($_POST['proj_'.$s_id]);
        $quiz = floatval($_POST['quiz_'.$s_id]);
        $mid = floatval($_POST['mid_'.$s_id]);
        $fin = floatval($_POST['fin_'.$s_id]);
        
        $total = $att + $ass + $proj + $quiz + $mid + $fin;
        
        // Auto Letter Grade Logic
        $letter = 'F';
        if($total >= 90) $letter = 'A+';
        elseif($total >= 85) $letter = 'A';
        elseif($total >= 80) $letter = 'A-';
        elseif($total >= 75) $letter = 'B+';
        elseif($total >= 70) $letter = 'B';
        elseif($total >= 65) $letter = 'B-';
        elseif($total >= 60) $letter = 'C+';
        elseif($total >= 50) $letter = 'C';
        elseif($total >= 40) $letter = 'D';

        // Insert or Update Grade
        mysqli_query($conn, "INSERT INTO student_grades (course_id, student_id, teacher_id, attendance, assignment, project, quiz, mid_exam, final_exam, total_score, grade_letter, is_published) 
                             VALUES ($c_id, $s_id, $teacher_id, $att, $ass, $proj, $quiz, $mid, $fin, $total, '$letter', $is_published) 
                             ON DUPLICATE KEY UPDATE attendance=$att, assignment=$ass, project=$proj, quiz=$quiz, mid_exam=$mid, final_exam=$fin, total_score=$total, grade_letter='$letter', is_published=$is_published");
    }
    
    if($is_published) {
        mysqli_query($conn, "INSERT INTO teacher_activities (teacher_id, action_type, details) VALUES ($teacher_id, 'Published Grades', 'Grades submitted to HoD and Students')");
        header("Location: dashboard.php?tab=gradebook&msg=grades_published");
    } else {
        header("Location: dashboard.php?tab=gradebook&msg=grades_saved");
    }
    exit();
}

if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'grades_saved') { $message = "Grades Draft Saved Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'grades_published') { $message = "Grades Published to HoD & Students!"; $msg_type = "success"; }
    if($_GET['msg'] == 'settings_saved') { $message = "Grading Criteria Updated! Columns Adjusted."; $msg_type = "success"; }
    if($_GET['msg'] == 'edit_requested') { $message = "Edit request sent to HoD successfully!"; $msg_type = "success"; }
}
// ========================================================
// 🏆 EXAM & AUTO-GRADING ENGINE SETUP
// ========================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    course_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    exam_type VARCHAR(50) DEFAULT 'Quiz',
    start_time DATETIME NOT NULL,
    duration_mins INT NOT NULL,
    access_code VARCHAR(50) NOT NULL,
    is_deleted TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_text TEXT NOT NULL,
    opt_a VARCHAR(255) NOT NULL,
    opt_b VARCHAR(255) NOT NULL,
    opt_c VARCHAR(255) NOT NULL,
    opt_d VARCHAR(255) NOT NULL,
    correct_opt VARCHAR(5) NOT NULL
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    score INT NOT NULL,
    total_questions INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// 🪄 MAGIC: Update DB for Dynamic Options (E & F) - WAMP SAFE
$res_e = mysqli_query($conn, "SHOW COLUMNS FROM `exam_questions` LIKE 'opt_e'");
if(mysqli_num_rows($res_e) == 0) {
    mysqli_query($conn, "ALTER TABLE `exam_questions` ADD COLUMN `opt_e` VARCHAR(255) DEFAULT ''");
}

$res_f = mysqli_query($conn, "SHOW COLUMNS FROM `exam_questions` LIKE 'opt_f'");
if(mysqli_num_rows($res_f) == 0) {
    mysqli_query($conn, "ALTER TABLE `exam_questions` ADD COLUMN `opt_f` VARCHAR(255) DEFAULT ''");
}
// 🪄 POST LOGIC: Live Time Extension (+ Mins)
if(isset($_POST['extend_time'])){
    $e_id = intval($_POST['exam_id']);
    $extra_mins = intval($_POST['extra_mins']);
    mysqli_query($conn, "UPDATE exams SET duration_mins = duration_mins + $extra_mins WHERE id=$e_id AND teacher_id=$teacher_id");
    mysqli_query($conn, "INSERT INTO teacher_activities (teacher_id, action_type, details) VALUES ($teacher_id, 'Extended Exam Time', 'Added $extra_mins mins to exam ID: $e_id')");
    header("Location: dashboard.php?tab=exams&msg=time_extended"); exit();
}

// 🪄 POST LOGIC: Live Question Editing
if(isset($_POST['edit_question_live'])){
    $q_id = intval($_POST['edit_q_id']);
    $q_text = mysqli_real_escape_string($conn, trim($_POST['q_text']));
    $opt_a = mysqli_real_escape_string($conn, trim($_POST['opt_a']));
    $opt_b = mysqli_real_escape_string($conn, trim($_POST['opt_b']));
    $opt_c = mysqli_real_escape_string($conn, trim($_POST['opt_c']));
    $opt_d = mysqli_real_escape_string($conn, trim($_POST['opt_d']));
    $opt_e = isset($_POST['opt_e']) ? mysqli_real_escape_string($conn, trim($_POST['opt_e'])) : '';
    $opt_f = isset($_POST['opt_f']) ? mysqli_real_escape_string($conn, trim($_POST['opt_f'])) : '';
    $correct_opt = mysqli_real_escape_string($conn, trim($_POST['correct_opt']));
    
    mysqli_query($conn, "UPDATE exam_questions SET question_text='$q_text', opt_a='$opt_a', opt_b='$opt_b', opt_c='$opt_c', opt_d='$opt_d', opt_e='$opt_e', opt_f='$opt_f', correct_opt='$correct_opt' WHERE id=$q_id");
    header("Location: dashboard.php?tab=exams&msg=question_updated"); exit();
}
// POST LOGIC: Create Exam
if(isset($_POST['create_exam'])){
    $c_id = intval($_POST['course_id']);
    $title = mysqli_real_escape_string($conn, trim($_POST['exam_title']));
    $type = mysqli_real_escape_string($conn, trim($_POST['exam_type']));
    $start_time = mysqli_real_escape_string($conn, $_POST['start_time']);
    $duration = intval($_POST['duration']);
    $access_code = mysqli_real_escape_string($conn, trim($_POST['access_code']));

    mysqli_query($conn, "INSERT INTO exams (teacher_id, course_id, title, exam_type, start_time, duration_mins, access_code, is_new) VALUES ($teacher_id, $c_id, '$title', '$type', '$start_time', $duration, '$access_code', 1)");    mysqli_query($conn, "INSERT INTO teacher_activities (teacher_id, action_type, details) VALUES ($teacher_id, 'Created Exam', 'Scheduled $type: $title')");
    
    header("Location: dashboard.php?tab=exams&msg=exam_created");
    exit();
}

// POST LOGIC: Delete Exam
if(isset($_POST['delete_exam'])){
    $e_id = intval($_POST['exam_id']);
    mysqli_query($conn, "UPDATE exams SET is_deleted=1 WHERE id=$e_id AND teacher_id=$teacher_id");
    header("Location: dashboard.php?tab=exams&msg=exam_deleted");
    exit();
}
// 🪄 AUTO-DATABASE FIX FOR EXAM QUESTIONS (WAMP SAFE)
$chk_qtype = mysqli_query($conn, "SHOW COLUMNS FROM `exam_questions` LIKE 'question_type'");
if(mysqli_num_rows($chk_qtype) == 0){
    mysqli_query($conn, "ALTER TABLE `exam_questions` ADD COLUMN `question_type` VARCHAR(50) DEFAULT 'multiple_choice'");
}
$chk_ctext = mysqli_query($conn, "SHOW COLUMNS FROM `exam_questions` LIKE 'correct_text'");
if(mysqli_num_rows($chk_ctext) == 0){
    mysqli_query($conn, "ALTER TABLE `exam_questions` ADD COLUMN `correct_text` TEXT NULL");
}

// POST LOGIC: Bulk Upload Questions (CSV)
if(isset($_POST['bulk_upload'])){
    $e_id = intval($_POST['bulk_exam_id']);
    if(isset($_FILES['csv_file']['name']) && $_FILES['csv_file']['name'] != ''){
        $filename = $_FILES['csv_file']['tmp_name'];
        if($_FILES['csv_file']['size'] > 0){
            $file = fopen($filename, "r");
            $is_header = true;
            $count = 0;
            while (($data = fgetcsv($file, 10000, ",")) !== FALSE) {
                if($is_header) { $is_header = false; continue; } // Header ofumaan irra darba
                
                // CSV Format: Type, Question, Opt A, Opt B, Opt C, Opt D, Correct Opt, Correct Text
                $q_type = isset($data[0]) ? mysqli_real_escape_string($conn, trim($data[0])) : 'multiple_choice';
                $q_text = isset($data[1]) ? mysqli_real_escape_string($conn, trim($data[1])) : '';
                $opt_a = isset($data[2]) ? mysqli_real_escape_string($conn, trim($data[2])) : '';
                $opt_b = isset($data[3]) ? mysqli_real_escape_string($conn, trim($data[3])) : '';
                $opt_c = isset($data[4]) ? mysqli_real_escape_string($conn, trim($data[4])) : '';
                $opt_d = isset($data[5]) ? mysqli_real_escape_string($conn, trim($data[5])) : '';
                $correct_opt = isset($data[6]) ? mysqli_real_escape_string($conn, trim($data[6])) : '';
                $correct_text = isset($data[7]) ? mysqli_real_escape_string($conn, trim($data[7])) : '';
                
                if(!empty($q_text)) {
                    mysqli_query($conn, "INSERT INTO exam_questions (exam_id, question_text, opt_a, opt_b, opt_c, opt_d, correct_opt, question_type, correct_text) VALUES ($e_id, '$q_text', '$opt_a', '$opt_b', '$opt_c', '$opt_d', '$correct_opt', '$q_type', '$correct_text')");
                    $count++;
                }
            }
            fclose($file);
            header("Location: dashboard.php?tab=exams&msg=bulk_success&c=$count");
            exit();
        }
    }
}
// 🪄 MESSAGE HANDLER FOR REDIRECTS
if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'exam_created') { $message = "Exam Scheduled Successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'exam_deleted') { $message = "Exam Moved to Trash!"; $msg_type = "success"; }
    if($_GET['msg'] == 'bulk_success' && isset($_GET['c'])) { $count = intval($_GET['c']); $message = "$count Questions Uploaded Successfully via CSV!"; $msg_type = "success"; }
    if($_GET['msg'] == 'time_extended') { $message = "Exam time extended successfully!"; $msg_type = "success"; }
    if($_GET['msg'] == 'question_updated') { $message = "Question updated live!"; $msg_type = "success"; }
}

// AJAX LOGIC: Handle Exam Questions & Results loading
if(isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];
    
 // 1. Add Question Magic (Multi-Format & AI Ready)
    if($action == 'add_question') {
        // 🪄 Database structure update for new features
        mysqli_query($conn, "ALTER TABLE exam_questions ADD COLUMN IF NOT EXISTS question_type VARCHAR(50) DEFAULT 'multiple_choice'");
        mysqli_query($conn, "ALTER TABLE exam_questions ADD COLUMN IF NOT EXISTS correct_text TEXT NULL");

        $e_id = intval($_POST['exam_id']);
        $q_type = mysqli_real_escape_string($conn, trim($_POST['q_type']));
        $q_text = mysqli_real_escape_string($conn, trim($_POST['q_text']));
        
        $opt_a = isset($_POST['opt_a']) ? mysqli_real_escape_string($conn, trim($_POST['opt_a'])) : '';
        $opt_b = isset($_POST['opt_b']) ? mysqli_real_escape_string($conn, trim($_POST['opt_b'])) : '';
        $opt_c = isset($_POST['opt_c']) ? mysqli_real_escape_string($conn, trim($_POST['opt_c'])) : '';
        $opt_d = isset($_POST['opt_d']) ? mysqli_real_escape_string($conn, trim($_POST['opt_d'])) : '';
        $correct_opt = isset($_POST['correct_opt']) ? mysqli_real_escape_string($conn, trim($_POST['correct_opt'])) : '';
        $correct_text = isset($_POST['correct_text']) ? mysqli_real_escape_string($conn, trim($_POST['correct_text'])) : '';
        
        mysqli_query($conn, "INSERT INTO exam_questions (exam_id, question_text, opt_a, opt_b, opt_c, opt_d, correct_opt, question_type, correct_text) VALUES ($e_id, '$q_text', '$opt_a', '$opt_b', '$opt_c', '$opt_d', '$correct_opt', '$q_type', '$correct_text')");
        echo json_encode(['status'=>'success']); exit();
    }
    
  // 2. Fetch Questions & Results Magic (UPDATED WITH OPTION E, F & LIVE EDIT)
    if($action == 'fetch_exam_details') {
        $e_id = intval($_POST['exam_id']);
        
        // Fetch Questions HTML
        $q_html = "";
        $q_res = mysqli_query($conn, "SELECT * FROM exam_questions WHERE exam_id=$e_id ORDER BY id ASC");
        $q_count = 1;
        while($q = mysqli_fetch_assoc($q_res)) {
            $type_badge = ""; $answer_html = "";
            
            if($q['question_type'] == 'multiple_choice') {
                $type_badge = "<span class='badge' style='background:rgba(59,130,246,0.1); color:#3b82f6;'><i class='fa-solid fa-list-ul'></i> Multiple Choice</span>";
                
                $optE_html = !empty($q['opt_e']) ? "<div style='".($q['correct_opt']=='E'?"color:#10b981; font-weight:bold; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px;":"padding:4px 8px;")."'>E. {$q['opt_e']}</div>" : "";
                $optF_html = !empty($q['opt_f']) ? "<div style='".($q['correct_opt']=='F'?"color:#10b981; font-weight:bold; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px;":"padding:4px 8px;")."'>F. {$q['opt_f']}</div>" : "";

                $answer_html = "<div style='display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:10px; font-size:13px; color:var(--text-muted);'>
                                    <div style='".($q['correct_opt']=='A'?"color:#10b981; font-weight:bold; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px;":"padding:4px 8px;")."'>A. {$q['opt_a']}</div>
                                    <div style='".($q['correct_opt']=='B'?"color:#10b981; font-weight:bold; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px;":"padding:4px 8px;")."'>B. {$q['opt_b']}</div>
                                    <div style='".($q['correct_opt']=='C'?"color:#10b981; font-weight:bold; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px;":"padding:4px 8px;")."'>C. {$q['opt_c']}</div>
                                    <div style='".($q['correct_opt']=='D'?"color:#10b981; font-weight:bold; background:rgba(16,185,129,0.1); padding:4px 8px; border-radius:6px;":"padding:4px 8px;")."'>D. {$q['opt_d']}</div>
                                    {$optE_html} {$optF_html}
                                </div>";
            } 
            elseif($q['question_type'] == 'fill_blank') {
                $type_badge = "<span class='badge' style='background:rgba(245,158,11,0.1); color:#f59e0b;'><i class='fa-solid fa-minus'></i> Fill in the Blank</span>";
                $answer_html = "<div style='margin-top:10px; font-size:13px; color:var(--text-main); background:rgba(16,185,129,0.05); border-left:3px solid #10b981; padding:8px 12px; border-radius:4px;'><strong>Exact Answer:</strong> {$q['correct_text']}</div>";
            } 
            elseif($q['question_type'] == 'essay') {
                $type_badge = "<span class='badge' style='background:linear-gradient(135deg, #ec4899, #8b5cf6); color:#fff; border:none;'><i class='fa-solid fa-robot'></i> AI Graded Essay</span>";
                $answer_html = "<div style='margin-top:10px; font-size:13px; color:var(--text-main); background:rgba(236,72,153,0.05); border-left:3px solid #ec4899; padding:8px 12px; border-radius:4px;'><strong>AI Grading Keywords / Expected Concept:</strong><br><span style='color:var(--text-muted);'>{$q['correct_text']}</span></div>";
            }

            $q_html .= "<div style='background:var(--bg-color); border:1px solid var(--border-color); padding:20px; border-radius:12px; margin-bottom:15px; box-shadow:0 4px 10px rgba(0,0,0,0.02); position:relative;'>
                            <div style='display:flex; justify-content:space-between; margin-bottom:10px;'>
                                <strong style='color:var(--danger); font-size:16px;'>Q{$q_count}.</strong>
                                <div>
                                    {$type_badge}
                                    <button class='btn btn-sm' style='background:rgba(245,158,11,0.1); color:#f59e0b; margin-left:10px;' onclick=\"openEditQuestionModal({$q['id']}, '".addslashes($q['question_text'])."', '".addslashes($q['opt_a'])."', '".addslashes($q['opt_b'])."', '".addslashes($q['opt_c'])."', '".addslashes($q['opt_d'])."', '".addslashes($q['opt_e'] ?? '')."', '".addslashes($q['opt_f'] ?? '')."', '{$q['correct_opt']}')\" title='Live Edit'><i class='fa-solid fa-pen'></i> Edit</button>
                                </div>
                            </div>
                            <div style='color:var(--text-main); font-weight:700; font-size:15px; line-height:1.5;'>{$q['question_text']}</div>
                            {$answer_html}
                        </div>";
            $q_count++;
        }
        if($q_html == "") $q_html = "<div style='text-align:center; color:var(--text-muted); padding:30px;'><i class='fa-solid fa-folder-open' style='font-size:40px; opacity:0.3; margin-bottom:10px; display:block;'></i>No questions added yet.</div>";

        // Fetch Results HTML
        $r_html = "<table style='width:100%; border-collapse:collapse;'>
                    <tr style='background:rgba(0,0,0,0.2);'><th style='padding:12px;'>Student Name</th><th>Submitted At</th><th>Score</th><th>Status</th></tr>";
        $r_res = mysqli_query($conn, "SELECT r.*, s.first_name, s.last_name FROM exam_results r JOIN student s ON r.student_id=s.id WHERE r.exam_id=$e_id ORDER BY r.score DESC");
        if(mysqli_num_rows($r_res) > 0) {
            while($r = mysqli_fetch_assoc($r_res)) {
                $pct = ($r['score'] / $r['total_questions']) * 100;
                $badge = $pct >= 50 ? "<span class='badge'>Passed</span>" : "<span class='badge badge-red'>Failed</span>";
                $time = date("d M, h:i A", strtotime($r['submitted_at']));
                $r_html .= "<tr style='border-bottom:1px solid rgba(255,255,255,0.05);'>
                                <td style='padding:15px;'><strong style='color:var(--text-main);'>{$r['first_name']} {$r['last_name']}</strong></td>
                                <td>{$time}</td>
                                <td><strong style='color:var(--primary); font-size:16px;'>{$r['score']} / {$r['total_questions']}</strong></td>
                                <td>{$badge}</td>
                            </tr>";
            }
        } else {
            $r_html .= "<tr><td colspan='4' style='text-align:center; padding:30px; color:var(--text-muted);'>No submissions yet. Wait for the exam to conclude.</td></tr>";
        }
        $r_html .= "</table>";
        
        echo json_encode(['questions'=>$q_html, 'results'=>$r_html]); exit();
    }
}
// ========================================================
// 📚 4. MANAGE MATERIALS & ASSIGNMENTS (DYNAMIC & SCALABLE)
// ========================================================
$chk_mat = mysqli_query($conn, "SHOW COLUMNS FROM materials LIKE 'release_date'");
if(mysqli_num_rows($chk_mat) == 0){
    mysqli_query($conn, "ALTER TABLE materials ADD COLUMN release_date DATETIME NULL DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE materials ADD COLUMN due_date DATETIME NULL DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE materials ADD COLUMN video_url VARCHAR(255) NULL DEFAULT NULL");
}
// 🪄 Dabalata Database Haaraa (Max Points fi Is_New notification)
        $chk_max = mysqli_query($conn, "SHOW COLUMNS FROM materials LIKE 'max_points'");
        if(mysqli_num_rows($chk_max) == 0){
            mysqli_query($conn, "ALTER TABLE materials ADD COLUMN max_points INT DEFAULT 10");
        }
        
        $chk_new = mysqli_query($conn, "SHOW COLUMNS FROM submissions LIKE 'is_new'");
        if(mysqli_num_rows($chk_new) == 0){
            mysqli_query($conn, "ALTER TABLE submissions ADD COLUMN is_new TINYINT(1) DEFAULT 1");
        }
        
        mysqli_query($conn, "ALTER TABLE materials MODIFY COLUMN type VARCHAR(100) DEFAULT 'material'");
if(isset($_POST['upload_material'])){
    $c_id = intval($_POST['course_id']);
    $title = mysqli_real_escape_string($conn, trim($_POST['title']));
    $type = mysqli_real_escape_string($conn, trim($_POST['type']));
    
    if($type === 'other' && !empty($_POST['custom_type'])) {
        $type = mysqli_real_escape_string($conn, trim($_POST['custom_type']));
    }
    
    $is_locked = isset($_POST['is_locked']) ? 1 : 0;
    $max_points = isset($_POST['max_points']) ? intval($_POST['max_points']) : 0; // 🪄 Qabxii Barsiisaan kenne
    $release_date = !empty($_POST['release_date']) ? "'".mysqli_real_escape_string($conn, $_POST['release_date'])."'" : "NULL";
    $due_date = !empty($_POST['due_date']) ? "'".mysqli_real_escape_string($conn, $_POST['due_date'])."'" : "NULL";
    $video_url = !empty($_POST['video_url']) ? "'".mysqli_real_escape_string($conn, trim($_POST['video_url']))."'" : "NULL";
    
    $has_file = isset($_FILES['material_file']['name']) && !empty($_FILES['material_file']['name']);
    $has_url = !empty($_POST['video_url']);
    
    if(!$has_file && !$has_url) {
        $message = "Please attach a file or provide a URL!"; $msg_type = "error";
    } else {
        $file_name = "";
        if($has_file){
            $ext = pathinfo($_FILES['material_file']['name'], PATHINFO_EXTENSION);
            $allowed =['pdf', 'doc', 'docx', 'ppt', 'pptx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'mp3', 'wav', 'mp4', 'txt'];
            if(in_array(strtolower($ext), $allowed)){
                $file_name = "mat_" . $teacher_id . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES['material_file']['tmp_name'], "../uploads/materials/" . $file_name);
            } else { 
                $message = "Invalid file format! Allowed: PDF, DOC, PPT, ZIP, JPG, PNG, MP3, MP4."; $msg_type = "error"; 
            }
        }

        if($msg_type != 'error') {
            mysqli_query($conn, "INSERT INTO materials (course_id, teacher_id, title, file_path, type, is_locked, release_date, due_date, video_url, max_points) VALUES ($c_id, $teacher_id, '$title', '$file_name', '$type', $is_locked, $release_date, $due_date, $video_url, $max_points)");
            mysqli_query($conn, "INSERT INTO teacher_activities (teacher_id, action_type, details) VALUES ($teacher_id, 'Uploaded Material', 'Added: $title')");
            header("Location: dashboard.php?tab=materials"); exit();
        }
    }
}
if(isset($_POST['delete_material'])){
    $m_id = intval($_POST['material_id']);
    $m_q = mysqli_query($conn, "SELECT file_path FROM materials WHERE id=$m_id AND teacher_id=$teacher_id");
    if($m_data = mysqli_fetch_assoc($m_q)){
        $file = "../uploads/materials/" . $m_data['file_path'];
        if(!empty($m_data['file_path']) && file_exists($file)) unlink($file); 
        mysqli_query($conn, "DELETE FROM materials WHERE id=$m_id");
        $message = "Material Deleted Permanently!";
    }
}

if(isset($_POST['toggle_material'])){
    $m_id = intval($_POST['material_id']);
    mysqli_query($conn, "UPDATE materials SET is_locked = IF(is_locked=1, 0, 1) WHERE id=$m_id AND teacher_id=$teacher_id");
    $message = "Visibility Overridden!";
}
// ========================================================
// 📅 6. FETCH CALENDAR EVENTS
// ========================================================
$calendar_events =[];

// Fetch Exams
$ex_cal_q = mysqli_query($conn, "SELECT title, start_time, exam_type FROM exams WHERE teacher_id=$teacher_id AND is_deleted=0");
while($ex = mysqli_fetch_assoc($ex_cal_q)) {
    $calendar_events[] = [
        'title' => $ex['title'],
        'date' => date('Y-m-d', strtotime($ex['start_time'])),
        'time' => date('h:i A', strtotime($ex['start_time'])),
        'type' => 'exam',
        'label' => $ex['exam_type']
    ];
}

// Fetch Assignments & Projects (With Due Dates)
$mat_cal_q = mysqli_query($conn, "SELECT title, due_date, type FROM materials WHERE teacher_id=$teacher_id AND type IN ('assignment', 'project') AND due_date IS NOT NULL");
while($mat = mysqli_fetch_assoc($mat_cal_q)) {
    $calendar_events[] = [
        'title' => $mat['title'],
        'date' => date('Y-m-d', strtotime($mat['due_date'])),
        'time' => date('h:i A', strtotime($mat['due_date'])),
        'type' => $mat['type'],
        'label' => ucfirst($mat['type'])
    ];
}
$calendar_events_json = json_encode($calendar_events);
// ========================================================
// 📊 5. FETCH LIVE DASHBOARD DATA
// ========================================================

$my_courses = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM teacher_course WHERE teacher_id=$teacher_id"))['c'];
$my_students = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0"))['c'];
$my_pdfs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM materials WHERE teacher_id=$teacher_id AND type='pdf'"))['c'];
$my_assignments = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM materials WHERE teacher_id=$teacher_id AND type IN ('assignment', 'project')"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=1400"><title>EPLMS - Teacher Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>

    /* ======================================================== */
    /* 🎨 PREMIUM SYSTEM STYLES (Teacher Theme: Emerald Green)  */
    /* ======================================================== */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
    
    :root { 
        --bg-color: #090b0f; --panel-bg: #14161c; --border-color: rgba(255,255,255,0.08); 
        --text-main: #f1f5f9; --text-muted: #94a3b8;
        --primary: #10b981; --primary-hover: #059669; --primary-glow: rgba(16, 185, 129, 0.25); /* Emerald Green */
        --danger: #f43f5e; --success: #3b82f6; /* Swapped success to blue for contrast in teacher dashboard */
        --warning: #f59e0b; --input-bg: rgba(0,0,0,0.2);
    }
    body.light-mode {
        --bg-color: #f4f7fb; --panel-bg: #ffffff; --border-color: #e2e8f0; 
        --text-main: #1e293b; --text-muted: #64748b;
        --primary: #059669; --primary-hover: #047857; --primary-glow: rgba(5, 150, 105, 0.2);
        --danger: #e11d48; --success: #2563eb; --warning: #d97706; --input-bg: #f8fafc;
    }
/* 🪄 MAGIC: Overflow sirreeffameera */
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
    .theme-toggle { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-main); padding: 10px 18px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; transition: 0.3s; box-shadow: 0 2px 10px rgba(0,0,0,0.02); }
    .theme-toggle:hover { border-color: var(--primary); color: var(--primary); }
    .content-area { padding: 30px; max-width: 100%; overflow-x: hidden; box-sizing: border-box; }
    .section-tab { display: none; animation: fadeIn 0.4s ease; }
    .section-tab.active { display: block; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }

    /* PANELS & FORMS */
    .grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 25px; }
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; align-items: start; }
    
    .panel, .premium-panel { background: var(--panel-bg); border-radius: 20px; border: 1px solid var(--border-color); padding: 30px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); transition: transform 0.3s ease, box-shadow 0.3s ease; position: relative; overflow: hidden; }
    body:not(.light-mode) .panel, body:not(.light-mode) .premium-panel { background: linear-gradient(145deg, #14161c, #0e1015); box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
    .panel:hover, .premium-panel:hover { box-shadow: 0 15px 40px rgba(0,0,0,0.08); transform: translateY(-3px); }
    .premium-panel { border-top: 4px solid var(--primary); }
    .premium-panel::after { content: ''; position: absolute; top:-50px; right:-50px; width:150px; height:150px; background: radial-gradient(circle, var(--primary-glow) 0%, transparent 70%); border-radius:50%; pointer-events: none; }
    .panel-title, .panel-title-premium { font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 12px; margin-bottom: 25px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; color: var(--text-main); }
    
    /* INPUTS & BUTTONS */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: 700; font-size: 12.5px; margin-bottom: 8px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .input-with-icon { position: relative; width: 100%; }
    .input-with-icon i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted); transition: 0.3s; font-size: 16px; z-index: 2; }
    .input-with-icon input, .input-with-icon select { width: 100%; padding: 15px 15px 15px 50px !important; border: 1.5px solid var(--border-color); border-radius: 12px; background: var(--input-bg); color: var(--text-main); font-size: 14.5px; font-weight: 500; outline: none; transition: all 0.3s ease; position: relative; z-index: 1; }
    .input-with-icon input:focus, .input-with-icon select:focus { border-color: var(--primary); background: transparent; box-shadow: 0 0 0 4px var(--primary-glow); }
    .input-with-icon input:focus + i { color: var(--primary); }
    
    .btn { padding: 12px 20px; background: var(--primary); color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s ease; box-shadow: 0 4px 15px var(--primary-glow);}
    .btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px var(--primary-glow); }
    .glow-btn { background: linear-gradient(135deg, var(--primary) 0%, #047857 100%); color: #fff; padding: 16px 35px; border-radius: 30px; font-size: 15px; font-weight: 800; border: none; cursor: pointer; box-shadow: 0 10px 25px var(--primary-glow); transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
    .glow-btn:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 15px 35px var(--primary-glow); }
    .btn-danger { background: rgba(244, 63, 94, 0.1); color: var(--danger); border: 1px solid rgba(244, 63, 94, 0.3); box-shadow: none; }
    .btn-danger:hover { background: var(--danger); color: #fff; box-shadow: 0 5px 15px rgba(244, 63, 94, 0.3); }
    .btn-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); box-shadow: none; }
    .btn-sm { padding: 6px 10px; font-size: 12px; }

    /* TABLES & BADGES */
    table { width: 100%; border-collapse: separate; border-spacing: 0; }
    th { color: var(--text-muted); font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: 1px; padding: 15px; border-bottom: 2px solid var(--border-color); text-align: left; }
    td { padding: 18px 15px; border-bottom: 1px solid var(--border-color); vertical-align: middle; font-size: 14px; }
    tr:hover td { background: rgba(16, 185, 129, 0.02); }
    .badge { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(59, 130, 246, 0.1); color: var(--success); border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-red { background: rgba(244, 63, 94, 0.1); color: var(--danger); border-color: rgba(244, 63, 94, 0.2); }
    .badge-yellow { background: rgba(245, 158, 11, 0.1); color: var(--warning); border-color: rgba(245, 158, 11, 0.2); }
    .main-sidebar-badge { background: var(--danger); color: #fff; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: bold; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); box-shadow: 0 0 10px rgba(244, 63, 94, 0.5); animation: pulse-badge 2s infinite; }
    @keyframes pulse-badge { 0% { transform: scale(1) translateY(-50%); } 50% { transform: scale(1.1) translateY(-50%); } 100% { transform: scale(1) translateY(-50%); } }
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(16, 185, 129, 0.1); color: var(--primary); border: 1px solid rgba(16, 185, 129, 0.3); }
    .alert-error { background: rgba(246, 70, 93, 0.1); color: var(--danger); border: 1px solid rgba(246, 70, 93, 0.3); }

    /* MAGIC CARDS */
    .welcome-banner { display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(16, 185, 129, 0.08) 100%); padding: 35px 40px; border-radius: 16px; border: 1px solid rgba(16, 185, 129, 0.2); margin-bottom: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); position: relative; overflow: hidden; }
    .welcome-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(16, 185, 129, 0.05) 0%, transparent 60%); animation: rotateBg 20s linear infinite; z-index: 0; }
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
    .tg-sidebar.collapsed { width: 0px; border: none; }
    .tg-search-bar { padding: 20px; border-bottom: 1px solid var(--border-color); }
    .tg-search-bar input { width: 100%; padding: 12px 20px; border-radius: 25px; border: 1px solid var(--border-color); background: var(--panel-bg); color: var(--text-main); outline: none; transition: 0.3s;}
    .tg-search-bar input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow);}
    .tg-folders { display: flex; overflow-x: auto; padding: 10px 15px; gap: 8px; border-bottom: 1px solid var(--border-color); }
    .tg-folders::-webkit-scrollbar { height: 0px; }
    .tg-folder { padding: 8px 15px; border-radius: 20px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); cursor: pointer; white-space: nowrap; transition: 0.3s; background: rgba(0,0,0,0.05); }
    .tg-folder.active { background: var(--primary); color: #fff; box-shadow: 0 4px 10px var(--primary-glow); }
    .tg-contacts { flex: 1; overflow-y: auto; }
    .tg-contacts::-webkit-scrollbar { width: 4px; }
    .tg-contacts::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 10px; }
    .tg-contact-item { display: flex; align-items: center; gap: 15px; padding: 15px 20px; cursor: pointer; transition: 0.2s; border-bottom: 1px solid rgba(255,255,255,0.02); }
    .tg-contact-item:hover, .tg-contact-item.active { background: var(--primary-glow); border-left: 4px solid var(--primary); }
    .tg-avatar { width: 48px; height: 48px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 18px; font-weight: 800; color: #fff; position: relative; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .tg-avatar.group { border-radius: 14px; }
    .tg-online-dot { position: absolute; bottom: 0; right: 0; width: 14px; height: 14px; background: #3b82f6; border-radius: 50%; border: 3px solid var(--panel-bg); }
    .tg-info { flex: 1; overflow: hidden; }
    .tg-name { font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; justify-content: space-between; }
    .tg-role { font-size: 12.5px; color: var(--text-muted); display: block; margin-top: 4px; }
    .chat-unread-badge { background: var(--danger); color: #fff; padding: 2px 7px; border-radius: 12px; font-size: 11px; font-weight: bold; display: none; margin-left: auto; box-shadow: 0 4px 10px rgba(244, 63, 94, 0.4); }
    
    .tg-chat-area { flex: 1; display: flex; flex-direction: column; background: url('https://www.transparenttextures.com/patterns/cubes.png'); }
    .tg-chat-header { padding: 15px 25px; background: var(--panel-bg); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); z-index: 10;}
    .tg-chat-title { font-size: 17px; font-weight: 800; color: var(--text-main); }
    .tg-chat-status { font-size: 12.5px; color: #3b82f6; font-weight: 600;}
    .tg-chat-history { flex: 1; padding: 25px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
    .tg-placeholder { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: var(--text-muted); opacity: 0.6; }
    .tg-chat-input-area { padding: 20px 25px; background: var(--panel-bg); border-top: 1px solid var(--border-color); }
    .tg-chat-form { display: flex; gap: 15px; align-items: center; background: var(--input-bg); padding: 8px 8px 8px 25px; border-radius: 30px; border: 1px solid var(--border-color); transition: 0.3s;}
    .tg-chat-form:focus-within { border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-glow); }
    .tg-chat-form input { flex: 1; background: transparent; border: none; color: var(--text-main); font-size: 15px; outline: none; }
    .tg-chat-form button { width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), #047857); border: none; color: #fff; cursor: pointer; transition: 0.3s; display: flex; justify-content: center; align-items: center; font-size: 18px; box-shadow: 0 4px 10px var(--primary-glow);}
    
    .chat-msg-wrapper { display: flex; margin-bottom: 15px; width: 100%; position: relative; }
    .chat-right { justify-content: flex-end; margin-bottom: 15px; display:flex;}
    .chat-left { justify-content: flex-start; margin-bottom: 15px; display:flex;}
    .chat-bubble { max-width: 75%; padding: 14px 18px; border-radius: 20px; line-height: 1.5; font-size: 14.5px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);}
    .chat-right .chat-bubble { background: linear-gradient(135deg, var(--primary), #047857); color: #fff; border-bottom-right-radius: 4px; }
    .chat-left .chat-bubble { background: var(--panel-bg); color: var(--text-main); border-bottom-left-radius: 4px; border: 1px solid var(--border-color); }
    .chat-meta { font-size: 10px; opacity: 0.7; margin-top: 8px; display: flex; justify-content: space-between; gap: 15px; font-weight: 600;}

    /* MODALS & CONTEXT MENU */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
    .modal-overlay.active { display: flex; }
    .modal-box { background: var(--panel-bg); padding: 35px; border-radius: 24px; width: 90%; max-width: 450px; border: 1px solid var(--border-color); text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); animation: zoomIn 0.3s ease; }
    @keyframes zoomIn { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .chat-context-menu { display: none; position: fixed; z-index: 10000; width: 200px; background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); overflow: hidden; padding: 8px;}
    .context-item { padding: 12px 18px; font-size: 14px; font-weight: 600; color: var(--text-main); cursor: pointer; display: flex; align-items: center; gap: 12px; border-radius: 10px; transition: 0.2s;}
    .context-item:hover { background: var(--input-bg); color: var(--primary); }

    /* PROFILE & SETTINGS */
    .profile-header-card { background: linear-gradient(135deg, #064e3b 0%, #047857 100%); border-radius: 24px; padding: 45px 20px; text-align: center; position: relative; margin-bottom: 35px; border-bottom: 5px solid var(--primary); box-shadow: 0 15px 40px var(--primary-glow); overflow: hidden; }
    .profile-avatar-large { width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 5px solid #a7f3d0; margin-bottom: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
    .inner-tabs { display: flex; justify-content: center; gap: 15px; margin-bottom: 35px; background: rgba(0,0,0,0.1); padding: 8px; border-radius: 16px; display: inline-flex; border: 1px solid var(--border-color); }
    .inner-tab-btn { background: transparent; border: none; color: var(--text-muted); font-size: 14.5px; font-weight: 700; cursor: pointer; padding: 12px 25px; border-radius: 12px; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
    .inner-tab-btn.active { background: var(--primary); color: #fff; box-shadow: 0 5px 15px var(--primary-glow); }
    .inner-tab-content { display: none; animation: fadeIn 0.4s; }
    .inner-tab-content.active { display: block; }
    .sec-toggle { display: flex; justify-content: space-between; align-items: center; background: var(--input-bg); padding: 18px 25px; border-radius: 14px; border: 1px solid var(--border-color); margin-bottom: 18px; transition: 0.3s;}
    .switch { position: relative; display: inline-block; width: 46px; height: 24px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--text-muted); transition: .4s; border-radius: 34px; opacity: 0.5;}
    .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2);}
    input:checked + .slider { background-color: var(--primary); opacity: 1;}
    input:checked + .slider:before { transform: translateX(22px); }
    /* 🪄 MAGIC HIDDEN SCROLLBAR */
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
</head>
<body>

<aside class="sidebar" id="main-sidebar">
    <div class="sidebar-header">
        <i class="fa-solid fa-chalkboard-user" style="color:var(--primary);"></i> <h2>TEACHER</h2>
    </div>
    
   
<ul class="nav-links">
        <li><button class="tab-link active" onclick="openTab('home')"><i class="fa-solid fa-chart-pie icon"></i> <span>Control Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('schedule')"><i class="fa-regular fa-calendar-days icon" style="color: #f59e0b;"></i> <span>Class Schedule</span></button></li>
        <li><button class="tab-link" onclick="openTab('materials')"><i class="fa-solid fa-book-open icon"></i> <span>Manage Materials</span></button></li>
        <li><button class="tab-link" onclick="openTab('submissions')"><i class="fa-solid fa-inbox icon" style="color: #3b82f6;"></i> <span>Submissions</span> <span class="main-sidebar-badge" id="badge_submissions" style="display:none; position:relative; right:0; transform:none; margin-left:auto;">0</span></button></li>
        
        <!-- 🪄 EXAMS BUTTON RESTORED HERE -->
        <li><button class="tab-link" onclick="openTab('exams')"><i class="fa-solid fa-stopwatch icon" style="color: #f43f5e;"></i> <span>Exams & Quizzes</span></button></li>
        
        <li><button class="tab-link" onclick="openTab('calendar')"><i class="fa-regular fa-calendar-days icon" style="color: #ec4899;"></i> <span>Academic Calendar</span></button></li>
        <li><button class="tab-link" onclick="openTab('gradebook')"><i class="fa-solid fa-star-half-stroke icon" style="color: #10b981;"></i> <span>Gradebook Center</span></button></li>
        <li><button class="tab-link" onclick="openTab('students')"><i class="fa-solid fa-users icon"></i> <span>My Students</span></button></li>
        <li><button class="tab-link" onclick="openTab('help')"><i class="fa-solid fa-circle-question icon" style="color: #ec4899;"></i> <span>Help Center</span></button></li>
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
                <img src="<?php echo $profile_pic; ?>" alt="Teacher" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); flex-shrink: 0;">
                <div class="welcome-text" style="white-space: nowrap;">
                    <h2 style="font-size: 18px; font-weight: 800; color: var(--text-main);">Welcome, Tr. <?php echo htmlspecialchars($teacher_info['name']); ?></h2>
                    <span style="font-size: 12px; color: var(--text-muted);"><i class="fa-solid fa-building-columns" style="color:var(--primary);"></i> <?php echo htmlspecialchars($teacher_info['dept_name']); ?></span>
                </div>
            </div>
        </div>
        <button class="theme-toggle" style="flex-shrink: 0;" onclick="toggleTheme()"><i class="fa-solid fa-moon" id="theme-icon"></i> <span id="theme-text">Dark Mode</span></button>
    </header>

    <div class="content-area">
        <?php if($message): ?><div class="alert alert-<?php echo $msg_type; ?>"><i class="fa-solid fa-circle-check"></i> <?php echo $message; ?></div><?php endif; ?>

       <!-- ============================================== -->
        <!-- TAB 1: HOME (MAGIC CONTROL CENTER)             -->
        <!-- ============================================== -->
        <div id="home" class="section-tab active">
            
            <!-- Welcome Banner -->
            <div class="welcome-banner" style="background: linear-gradient(135deg, var(--panel-bg) 0%, rgba(16, 185, 129, 0.08) 100%); border-color: rgba(16, 185, 129, 0.2);">
                <div>
                    <h2 id="greeting-text" style="font-size: 32px; font-weight: 800; margin-bottom: 8px;">
                        <?php 
                            $hour = date('H');
                            $greet = ($hour < 12) ? "Good Morning" : (($hour < 18) ? "Good Afternoon" : "Good Evening");
                            echo $greet . ", Tr. " . htmlspecialchars($teacher_info['name']); 
                        ?>!
                    </h2>
                    <p style="color:var(--text-muted); font-size:15px;"><i class="fa-solid fa-graduation-cap" style="color:#10b981;"></i> Empowering minds and shaping the future. Your dashboard is ready.</p>
                </div>
                <div class="live-clock-container" style="border-color: #10b981;"><i class="fa-solid fa-clock" style="color:#10b981;"></i><span id="real-time-clock">00:00:00</span></div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                <div class="magic-card" style="border-top: 4px solid #3b82f6;">
                    <i class="fa-solid fa-book-open bg-icon"></i>
                    <div class="icon-box" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-book-open"></i></div>
                    <h2 class="counter" data-target="<?php echo $my_courses; ?>">0</h2>
                    <p>My Assigned Courses</p>
                </div>
                <div class="magic-card" style="border-top: 4px solid #10b981;">
                    <i class="fa-solid fa-users bg-icon"></i>
                    <div class="icon-box" style="background: linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-users"></i></div>
                    <h2 class="counter" data-target="<?php echo $my_students; ?>">0</h2>
                    <p>Total Dept Students</p>
                </div>
                <div class="magic-card" style="border-top: 4px solid #f59e0b;">
                    <i class="fa-solid fa-file-pdf bg-icon"></i>
                    <div class="icon-box" style="background: linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-file-pdf"></i></div>
                    <h2 class="counter" data-target="<?php echo $my_pdfs; ?>">0</h2>
                    <p>Study Materials (PDF)</p>
                </div>
                <div class="magic-card" style="border-top: 4px solid #8b5cf6;">
                    <i class="fa-solid fa-file-pen bg-icon"></i>
                    <div class="icon-box" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-file-pen"></i></div>
                    <h2 class="counter" data-target="<?php echo $my_assignments; ?>">0</h2>
                    <p>Assignments / Projects</p>
                </div>
            </div>
            
            <div class="grid-2">
                <!-- LEFT COLUMN -->
                <div style="display: flex; flex-direction: column; gap: 25px;">
                    <!-- Chart Panel -->
                    <div class="premium-panel" style="margin:0; border-top-color:#10b981; padding: 25px;">
<h3 class="panel-title-premium" style="margin-bottom: 15px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-chart-line"></i></div> Activity Overview</h3>                        <div style="height: 220px; display:flex; justify-content:center; align-items:center;">
                            <canvas id="materialsChart"></canvas>
                        </div>
                    </div>

                    <!-- My Courses List -->
                    <div class="premium-panel" style="margin:0; border-top-color:#3b82f6; padding: 25px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid rgba(255,255,255,0.05); padding-bottom:15px;">
                            <h3 class="panel-title-premium" style="margin:0; border:none; padding:0;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-book-bookmark"></i></div> Assigned Modules</h3>
                            <button class="btn btn-sm" style="background:rgba(59,130,246,0.1); color:#3b82f6;" onclick="openTab('materials')"><i class="fa-solid fa-plus"></i> Upload</button>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:12px; max-height:280px; overflow-y:auto; padding-right:5px;">
                            <?php
                            $tc_q = mysqli_query($conn, "SELECT c.* FROM teacher_course tc JOIN course c ON tc.course_id = c.id WHERE tc.teacher_id=$teacher_id AND c.is_deleted=0");
                            if(mysqli_num_rows($tc_q)>0){
                                while($tc = mysqli_fetch_assoc($tc_q)){
                                    echo "<div style='padding:15px 20px; border:1px solid rgba(59,130,246,0.2); background:rgba(59,130,246,0.05); border-radius:12px; display:flex; align-items:center; gap:15px; transition:0.3s;' onmouseover=\"this.style.transform='translateX(5px)'\" onmouseout=\"this.style.transform='translateX(0)'\">
                                            <div style='width:45px; height:45px; border-radius:12px; background:linear-gradient(135deg, #3b82f6, #1d4ed8); color:#fff; display:flex; justify-content:center; align-items:center; font-size:18px; box-shadow:0 4px 10px rgba(59,130,246,0.3);'><i class='fa-solid fa-layer-group'></i></div>
                                            <div><strong style='color:var(--text-main); font-size:15px;'>{$tc['course_name']}</strong><br><span style='color:#3b82f6; font-size:12px; font-family:monospace; font-weight:800; background:rgba(59,130,246,0.1); padding:2px 8px; border-radius:6px;'>{$tc['course_code']}</span></div>
                                          </div>";
                                }
                            } else { echo "<div class='info-alert warning' style='margin:0;'><i class='fa-solid fa-circle-info'></i> No courses assigned by your HoD yet.</div>"; }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div style="display: flex; flex-direction: column; gap: 25px;">
                    <!-- Quick Actions -->
                    <div class="premium-panel" style="margin:0; padding: 25px; border-top-color:#8b5cf6;">
                        <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #8b5cf6, #6d28d9);"><i class="fa-solid fa-bolt"></i></div> Quick Actions</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <button class="btn" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.3); padding:15px; flex-direction:column; gap:8px;" onclick="openTab('materials');"><i class="fa-solid fa-cloud-arrow-up" style="font-size:24px;"></i> Upload Material</button>
                            <button class="btn" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); padding:15px; flex-direction:column; gap:8px;" onclick="openTab('materials');"><i class="fa-solid fa-file-signature" style="font-size:24px;"></i> Post Assignment</button>
                            <button class="btn" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59,130,246,0.3); padding:15px; flex-direction:column; gap:8px;" onclick="openTab('students');"><i class="fa-solid fa-users-viewfinder" style="font-size:24px;"></i> View Students</button>
                            <button class="btn" style="background: rgba(244, 63, 94, 0.1); color: #f43f5e; border: 1px solid rgba(244,63,94,0.3); padding:15px; flex-direction:column; gap:8px;" onclick="openTab('broadcast');"><i class="fa-solid fa-bullhorn" style="font-size:24px;"></i> Announce</button>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="premium-panel" style="flex: 1; margin:0; padding: 25px; border-top-color:#f59e0b;">
                        <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-clock-rotate-left"></i></div> Logged Activities</h3>
                        <div style="flex: 1; overflow-y: auto; max-height: 250px; padding-right: 5px;">
                            <?php
                            $acts = mysqli_query($conn, "SELECT * FROM teacher_activities WHERE teacher_id=$teacher_id ORDER BY created_at DESC LIMIT 8");
                            if(mysqli_num_rows($acts)>0){
                                while($a = mysqli_fetch_assoc($acts)){
                                    $time = date("M d, H:i", strtotime($a['created_at']));
                                    echo "<div style='padding:15px; border-bottom:1px dashed rgba(255,255,255,0.05); display:flex; justify-content:space-between; align-items:center; transition:0.3s;' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                            <div style='display:flex; align-items:center; gap:12px;'>
                                                <div style='width:8px; height:8px; border-radius:50%; background:var(--primary); box-shadow:0 0 10px var(--primary);'></div>
                                                <div><strong style='color:var(--text-main); font-size:14px;'>{$a['action_type']}</strong><br><span style='font-size:12.5px; color:var(--text-muted);'>{$a['details']}</span></div>
                                            </div>
                                            <span style='font-size:11px; color:var(--text-muted); font-weight:700; background:var(--input-bg); padding:4px 10px; border-radius:12px; border:1px solid var(--border-color);'>$time</span>
                                          </div>";
                                }
                            } else { echo "<div style='text-align:center; padding:40px 0; opacity:0.5;'><i class='fa-solid fa-list-check' style='font-size:40px; color:var(--text-muted); margin-bottom:15px;'></i><p style='color:var(--text-muted); font-size:13px;'>No recent activities logged.</p></div>"; }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
       <!-- ============================================== -->
        <!-- TAB: CLASS SCHEDULE (TEACHER VIEW)             -->
        <!-- ============================================== -->
        <div id="schedule" class="section-tab">
            
            <!-- 🌟 1. OFFICIAL FULL DEPT SCHEDULE VIEW (NOW AT THE TOP) 🌟 -->
            <div class="premium-panel" style="padding: 0; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.15); margin-bottom: 25px;">
                <div style="padding: 40px; color: #111;">
                    
                    <?php
                    // Fetch latest metadata to update the header dynamically
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
                                        // 🪄 MAGIC HIGHLIGHT FOR LOGGED IN TEACHER
                                        $is_my_class = ($sch['teacher_id'] == $teacher_id);
                                        
                                        if($is_my_class) {
                                            $bg_color = ($sch['class_type'] == 'Lab') ? 'background: #a7f3d0;' : 'background: #d1fae5;'; 
                                            $text_style = "font-weight: bold; color: #065f46;";
                                        } else {
                                            $bg_color = ($sch['class_type'] == 'Lab') ? 'background: #e5e7eb;' : 'background: #fff;';
                                            $text_style = "color: #111;";
                                        }
                                        
                                        echo "<tr style='{$bg_color}'>";
                                        
                                        if($first){
                                            echo "<td rowspan='{$rowspan}' style='border: 1px solid #000; padding: 10px; font-weight: bold; text-align: center; vertical-align: middle; font-size: 16px; background: #fff;'>{$d}</td>";
                                            $first = false;
                                        }
                                        
                                        echo "<td style='border: 1px solid #000; padding: 10px; {$text_style}'>{$sch['course_name']}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px; {$text_style}'>{$sch['course_code']}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px; {$text_style}'>{$sch['time_slot']}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px; {$text_style}'>{$sch['class_type']}</td>";
                                        
                                        $inst_name = (strpos($sch['teacher_name'], 'Mr.') === false && strpos($sch['teacher_name'], 'Ms.') === false) ? "Tr. ".$sch['teacher_name'] : $sch['teacher_name'];
                                        
                                        echo "<td style='border: 1px solid #000; padding: 10px; {$text_style}'>{$inst_name}</td>";
                                        echo "<td style='border: 1px solid #000; padding: 10px; {$text_style}'>{$sch['room']}</td>";
                                        
                                        echo "</tr>";
                                    }
                                }
                            }

                            if(!$schedule_exists) {
                                echo "<tr><td colspan='7' style='text-align:center; padding:30px; color:#555; font-style:italic;'>No official schedule has been published by the Head of Department yet.</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 🌟 2. MY PERSONAL SCHEDULE (HIGHLIGHTED CARDS - NOW AT THE BOTTOM) 🌟 -->
            <div class="premium-panel" style="border-top-color: var(--primary);">
                <h3 class="panel-title-premium"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, var(--primary), #047857);"><i class="fa-solid fa-user-clock"></i></div> My Teaching Schedule</h3>
                <p style="font-size:13.5px; color:var(--text-muted); margin-bottom:20px;">Here are the specific classes, times, and venues you are assigned to teach.</p>
                
                <div class="grid-2" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                    <?php
                    // Fetch ONLY this teacher's schedule
                    $my_sch_q = mysqli_query($conn, "SELECT s.*, c.course_name, c.course_code FROM class_schedule s JOIN course c ON s.course_id=c.id WHERE s.dept_id=$dept_id AND s.teacher_id=$teacher_id ORDER BY FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), STR_TO_DATE(SUBSTRING_INDEX(s.time_slot, ' ', 1), '%l:%i') ASC");
                    
                    if(mysqli_num_rows($my_sch_q) > 0){
                        while($my_sch = mysqli_fetch_assoc($my_sch_q)){
                            $day_color = '#10b981'; // Emerald Green Default
                            if($my_sch['day_of_week'] == 'Monday') $day_color = '#3b82f6';
                            elseif($my_sch['day_of_week'] == 'Tuesday') $day_color = '#f59e0b';
                            elseif($my_sch['day_of_week'] == 'Wednesday') $day_color = '#8b5cf6';
                            elseif($my_sch['day_of_week'] == 'Thursday') $day_color = '#f43f5e';
                            
                            $type_icon = $my_sch['class_type'] == 'Lab' ? 'fa-flask' : ($my_sch['class_type'] == 'Tutorial' ? 'fa-users-rectangle' : 'fa-chalkboard-user');

                            echo "<div style='background: rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-left: 4px solid {$day_color}; border-radius: 12px; padding: 20px; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.05);' onmouseover=\"this.style.transform='translateY(-5px)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.1)';\" onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.05)';\">
                                    <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom: 1px dashed var(--border-color); padding-bottom: 10px;'>
                                        <span style='font-size:16px; font-weight:800; color:{$day_color};'><i class='fa-solid fa-calendar-day'></i> {$my_sch['day_of_week']}</span>
                                        <span class='badge' style='background:rgba(255,255,255,0.1); color:var(--text-main); border:1px solid var(--border-color);'><i class='fa-regular fa-clock' style='color:{$day_color};'></i> {$my_sch['time_slot']}</span>
                                    </div>
                                    <h4 style='font-size:16px; font-weight:700; color:var(--text-main); margin-bottom:5px;'>{$my_sch['course_name']}</h4>
                                    <span style='font-size:12px; font-family:monospace; font-weight:800; color:{$day_color}; background:rgba(255,255,255,0.05); padding:3px 8px; border-radius:6px; margin-bottom:15px; display:inline-block;'>{$my_sch['course_code']}</span>
                                    
                                    <div style='display:flex; justify-content:space-between; align-items:center; margin-top:10px; background:var(--bg-color); padding:10px; border-radius:8px;'>
                                        <span style='font-size:13px; color:var(--text-muted);'><i class='fa-solid {$type_icon}' style='color:var(--text-main);'></i> {$my_sch['class_type']}</span>
                                        <span style='font-size:13px; color:var(--text-main); font-weight:700;'><i class='fa-solid fa-door-open' style='color:{$day_color};'></i> {$my_sch['room']}</span>
                                    </div>
                                  </div>";
                        }
                    } else {
                        echo "<div class='info-alert warning' style='grid-column: 1/-1; margin:0;'><i class='fa-solid fa-circle-info'></i> You have not been assigned to any specific schedule yet.</div>";
                    }
                    ?>
                </div>
            </div>

        </div>
   <!-- ============================================== -->
        <!-- TAB 2: ADVANCED MATERIALS & ASSIGNMENTS        -->
        <!-- ============================================== -->
        <div id="materials" class="section-tab">
            
            <!-- 📤 TOP SECTION: UPLOAD FORM PANEL (HORIZONTAL LAYOUT) -->
            <div class="premium-panel" style="margin-bottom: 25px; border-top-color:#10b981; padding: 25px 30px;">
                <h3 class="panel-title-premium" style="margin-bottom: 20px; font-size:18px; border-bottom: none; padding-bottom: 0;">
                    <div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-cloud-arrow-up"></i></div> 
                    Publish Content
                </h3>
                
                <form method="POST" enctype="multipart/form-data">
                    <!-- Row 1: Main Inputs -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; margin-bottom: 15px;">
                        
                        <div class="form-group" style="margin:0;"><label>Select Course</label>
                            <div class="input-with-icon"><select name="course_id" required style="padding:10px 10px 10px 40px !important;"><option value="">-- Choose Course --</option>
                            <?php $c_list = mysqli_query($conn, "SELECT c.id, c.course_name FROM teacher_course tc JOIN course c ON tc.course_id = c.id WHERE tc.teacher_id=$teacher_id AND c.is_deleted=0"); while($cl = mysqli_fetch_assoc($c_list)) echo "<option value='{$cl['id']}'>{$cl['course_name']}</option>"; ?>
                            </select><i class="fa-solid fa-book"></i></div>
                        </div>
                        
                        <div class="form-group" style="margin:0;"><label>Title / Topic</label>
                            <div class="input-with-icon"><input type="text" name="title" placeholder="e.g. Chapter 1, Week 2..." required style="padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-heading"></i></div>
                        </div>
                        
                        <div class="form-group" style="margin:0;"><label>Content Type</label>
                            <div class="input-with-icon"><select name="type" id="mat_type_selector" onchange="toggleMatFields()" required style="padding:10px 10px 10px 40px !important;">
                                <option value="material">Study Material (PDF, PPT, Images)</option>
                                <option value="assignment">Assignment</option>
                                <option value="project">Project Work</option>
                                <option value="media">Video / Audio Link</option>
                                <option value="other" style="color:var(--primary); font-weight:bold;">✨ Other (Custom)</option>
                            </select><i class="fa-solid fa-filter"></i></div>
                        </div>

                       <!-- 🪄 NEW: Points / Value Field (Hidden by default) -->
                        <div class="form-group" id="field_points" style="display:none; margin:0;">
                            <label style="color:var(--warning);">Max Points / Value</label>
                            <div class="input-with-icon">
                                <input type="number" name="max_points" id="max_points_input" placeholder="e.g. 10, 20, 100" min="1" style="padding:10px 10px 10px 40px !important; border-color:var(--warning);">
                                <i class="fa-solid fa-star" style="color:var(--warning);"></i>
                            </div>
                        </div>

                        <!-- Attachments (Dynamic) -->
                        <div class="form-group" id="field_file" style="margin:0;"><label>Attach File</label>
                            <input type="file" name="material_file" style="width:100%; padding:9px 10px; background:var(--input-bg); border-radius:8px; border:1px solid var(--border-color); color:var(--text-main); font-size: 13px;" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip,.rar,.jpg,.jpeg,.png,.mp3,.wav,.mp4,.txt">
                        </div>
                        <div class="form-group" id="field_url" style="display:none; margin:0;"><label>URL / Link</label>
                            <div class="input-with-icon"><input type="url" name="video_url" placeholder="https://youtube.com/..." style="padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-link"></i></div>
                        </div>
                        <div class="form-group" id="field_custom_type" style="display:none; margin:0;"><label style="color:var(--primary);">Custom Type</label>
                            <div class="input-with-icon"><input type="text" name="custom_type" placeholder="e.g. Quiz" style="padding:10px 10px 10px 40px !important; border-color:var(--primary);"><i class="fa-solid fa-pen" style="color:var(--primary);"></i></div>
                        </div>
                    </div>

                    <!-- Row 2: Scheduling & Submit -->
                    <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; background: rgba(16, 185, 129, 0.03); padding: 15px 20px; border-radius: 12px; border: 1px dashed rgba(16, 185, 129, 0.2);">
                        
                        <div class="form-group" style="margin:0; flex: 1; min-width: 200px;"><label style="color:#10b981;"><i class="fa-regular fa-clock"></i> Auto-Release Date (Optional)</label>
                            <input type="datetime-local" name="release_date" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-main); font-size:13px;">
                        </div>
                        
                        <div class="form-group" id="field_due_date" style="display:none; margin:0; flex: 1; min-width: 200px;"><label style="color:#f43f5e;"><i class="fa-solid fa-hourglass-end"></i> Due Date</label>
                            <input type="datetime-local" name="due_date" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-color); background:var(--bg-color); color:var(--text-main); font-size:13px;">
                        </div>

                        <div style="display:flex; align-items:center; gap: 15px; margin-left: auto;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size:12px; font-weight:700; color:var(--text-muted);"><i class="fa-solid fa-lock" style="color:var(--danger);"></i> Manual Lock</span>
                                <label class="switch" style="margin:0; transform: scale(0.85);"><input type="checkbox" name="is_locked"><span class="slider" style="background-color:var(--danger);"></span></label>
                            </div>
                            
                            <button type="submit" name="upload_material" class="glow-btn" style="padding:12px 25px; border-radius:10px; font-size:14px; background:linear-gradient(135deg, #10b981, #047857); box-shadow:0 6px 15px rgba(16,185,129,0.3);"><i class="fa-solid fa-paper-plane"></i> Publish</button>
                        </div>
                    </div>
                </form>
            </div>
            
                <div style="display: flex; flex-direction: column; height: 100%; width: 100%; overflow: hidden;">
                    
                    <!-- Title & Filter Dropdown -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                        <h3 style="font-size: 20px; font-weight: 800; color: var(--text-main); display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-folder-tree" style="color:#10b981;"></i> My Learning Materials</h3>
                        
                        <!-- 🪄 MAGIC COURSE FILTER -->
                        <div class="input-with-icon" style="width: 250px;">
                            <select id="course_filter" onchange="filterMaterialsByCourse()" style="padding: 10px 12px 10px 35px !important; font-size: 13px; background: rgba(0,0,0,0.2); border-color: var(--border-color); color: var(--text-main); border-radius: 8px; cursor: pointer;">
                                <option value="all">📚 View All Courses</option>
                                <?php 
                                mysqli_data_seek($c_list, 0); // Pointer reset gochuu (Dropdown gubbaarraa waan fayyadamneef)
                                while($cl = mysqli_fetch_assoc($c_list)) {
                                    echo "<option value='{$cl['id']}'>{$cl['course_name']}</option>";
                                } 
                                ?>
                            </select>
                            <i class="fa-solid fa-filter" style="font-size: 13px; color: #10b981;"></i>
                        </div>
                    </div>
                    
                    <?php
                    // 1. Data Categorization
                    $mat_study =[]; $mat_assign =[]; $mat_proj =[]; $mat_media = []; $mat_other =[];
                    $m_q = mysqli_query($conn, "SELECT m.*, c.course_name, c.course_code FROM materials m JOIN course c ON m.course_id=c.id WHERE m.teacher_id=$teacher_id ORDER BY m.id ASC");
                    $current_time = time();
                    
                    if(mysqli_num_rows($m_q) > 0){
                        while($m = mysqli_fetch_assoc($m_q)){
                            $t = strtolower($m['type']);
                            if($t == 'assignment') { $mat_assign[] = $m; }
                            elseif($t == 'project') { $mat_proj[] = $m; }
                            elseif(in_array($t,['media', 'video', 'audio'])) { $mat_media[] = $m; }
                            elseif(in_array($t, ['material', 'pdf', 'ppt', 'doc', 'docx'])) { $mat_study[] = $m; }
                            else { $mat_other[] = $m; } 
                        }
                    }

                    // 2. Magic Card Render Function (Added data-course-id for filtering)
                    if(!function_exists('renderMaterialCard')) {
                        function renderMaterialCard($m, $current_time) {
                            $icon = 'fa-file'; $color = '#848e9c';
                            $t = strtolower($m['type']);
                            if(in_array($t,['material', 'pdf', 'ppt', 'doc', 'docx'])) { $icon='fa-file-pdf'; $color='#f43f5e'; }
                            elseif($t == 'assignment') { $icon='fa-file-pen'; $color='#3b82f6'; }
                            elseif($t == 'project') { $icon='fa-rocket'; $color='#8b5cf6'; }
                            elseif(in_array($t, ['media', 'video', 'audio'])) { $icon='fa-circle-play'; $color='#0ea5e9'; }
                            else { $icon='fa-box-open'; $color='#10b981'; } 
                            
                            $status_html = "";
                            if($m['is_locked'] == 1) { $status_html = "<span style='font-size:10px; font-weight:800; color:#f43f5e;'><i class='fa-solid fa-lock'></i> LOCKED</span>"; } 
                            else {
                                if(!empty($m['release_date']) && strtotime($m['release_date']) > $current_time) {
                                    $rel = date("d M, h:i A", strtotime($m['release_date']));
                                    $status_html = "<span style='font-size:10px; font-weight:800; color:#f59e0b;' title='Unlocks: {$rel}'><i class='fa-solid fa-clock'></i> SCH: {$rel}</span>";
                                } else { $status_html = "<span style='font-size:10px; font-weight:800; color:#10b981;'><i class='fa-solid fa-eye'></i> ACTIVE</span>"; }
                            }

                            $due_html = "";
                            if(!empty($m['due_date'])) {
                                $due_str = date("M d - h:i A", strtotime($m['due_date']));
                                $is_overdue = strtotime($m['due_date']) < $current_time;
                                $due_color = $is_overdue ? "color:#f43f5e; background:rgba(244,63,94,0.1);" : "color:#f59e0b; background:rgba(245,158,11,0.1);";
                                $due_html = "<div style='margin-top:10px; font-size:10.5px; font-weight:700; {$due_color} padding:4px 8px; border-radius:4px; display:inline-block;'><i class='fa-solid fa-hourglass-end'></i> Due: {$due_str}</div>";
                            }

                            $lock_btn = $m['is_locked']==1 ? "<button type='submit' name='toggle_material' class='btn btn-sm' style='background:rgba(16,185,129,0.1); color:#10b981; border-radius:6px;' title='Unlock Now'><i class='fa-solid fa-lock-open'></i></button>" : "<button type='submit' name='toggle_material' class='btn btn-sm' style='background:rgba(245,158,11,0.1); color:#f59e0b; border-radius:6px;' title='Force Lock'><i class='fa-solid fa-lock'></i></button>";
                            $view_btn = !empty($m['video_url']) ? "<a href='{$m['video_url']}' target='_blank' class='btn btn-sm' style='background:rgba(14,165,233,0.1); color:#0ea5e9; border-radius:6px;' title='Open Link'><i class='fa-solid fa-link'></i></a> " : "";
                            $view_btn .= !empty($m['file_path']) ? "<a href='../uploads/materials/{$m['file_path']}' target='_blank' class='btn btn-sm' style='background:rgba(16,185,129,0.1); color:#10b981; border-radius:6px;' title='Download File'><i class='fa-solid fa-download'></i></a>" : "";

                            // 🪄 Added class 'material-card' and 'data-course-id'
                            return "
                            <div class='material-card' data-course-id='{$m['course_id']}' style='background: var(--panel-bg); border: 1px solid var(--border-color); border-left: 4px solid {$color}; border-radius: 12px; padding: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.03); transition: 0.3s; position: relative; margin-bottom: 15px;' onmouseover=\"this.style.transform='translateY(-3px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.08)'\" onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.03)'\">
                                <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;'>
                                    <div style='display:flex; align-items:center; gap:6px;'>
                                        <i class='fa-solid {$icon}' style='color:{$color}; font-size:14px;'></i>
                                        <span style='font-family:monospace; font-size:11px; font-weight:800; color:var(--text-main);'>{$m['course_code']}</span>
                                    </div>
                                    {$status_html}
                                </div>

                                <h4 style='font-size:15px; font-weight:800; color:var(--text-main); margin-bottom:6px; line-height:1.4; word-wrap: break-word;'>{$m['title']}</h4>
                                <div style='font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;'>{$m['type']} • ".date("d M", strtotime($m['uploaded_at']))."</div>
                                
                                {$due_html}

                                <div style='display:flex; justify-content:flex-end; align-items:center; margin-top:15px; padding-top:12px; border-top:1px dashed rgba(255,255,255,0.1); gap:8px;'>
                                    {$view_btn}
                                    <form method='POST' style='margin:0;'><input type='hidden' name='material_id' value='{$m['id']}'>{$lock_btn}</form>
                                    <form method='POST' style='margin:0;' onsubmit=\"return confirm('Delete this permanently?');\"><input type='hidden' name='material_id' value='{$m['id']}'><button type='submit' name='delete_material' class='btn btn-sm' style='background:rgba(244,63,94,0.1); color:#f43f5e; border-radius:6px;'><i class='fa-solid fa-trash'></i></button></form>
                                </div>
                            </div>";
                        }
                    }
                    ?>

                    <!-- 3. THE 5-COLUMN HORIZONTAL GRID -->
                    <div style="display: flex; gap: 20px; overflow-x: auto; padding-bottom: 20px; align-items: flex-start; min-height: 50vh;">
                        
                        <!-- Col 1: Materials -->
                        <div id="col-materials" style="flex: 1; min-width: 260px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 16px; padding: 15px; display: flex; flex-direction: column;">
                            <h4 style="color: #f43f5e; font-size: 15px; font-weight: 800; margin-bottom: 15px; text-align: center; border-bottom: 2px dashed rgba(244,63,94,0.3); padding-bottom: 10px;"><i class="fa-solid fa-file-pdf"></i> Materials</h4>
                            <div class="card-container" style="overflow-y: auto; padding-right: 5px;">
                                <div class="empty-msg" style="text-align:center; color:var(--text-muted); font-size:12px; padding:20px; opacity:0.6; display:<?php echo empty($mat_study) ? 'block' : 'none'; ?>;"><i class="fa-solid fa-folder-open" style="font-size:30px; margin-bottom:10px; display:block;"></i>Empty</div>
                                <?php foreach($mat_study as $m) echo renderMaterialCard($m, $current_time); ?>
                            </div>
                        </div>

                        <!-- Col 2: Assignments -->
                        <div id="col-assignments" style="flex: 1; min-width: 260px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 16px; padding: 15px; display: flex; flex-direction: column;">
                            <h4 style="color: #3b82f6; font-size: 15px; font-weight: 800; margin-bottom: 15px; text-align: center; border-bottom: 2px dashed rgba(59,130,246,0.3); padding-bottom: 10px;"><i class="fa-solid fa-file-pen"></i> Assignments</h4>
                            <div class="card-container" style="overflow-y: auto; padding-right: 5px;">
                                <div class="empty-msg" style="text-align:center; color:var(--text-muted); font-size:12px; padding:20px; opacity:0.6; display:<?php echo empty($mat_assign) ? 'block' : 'none'; ?>;"><i class="fa-solid fa-folder-open" style="font-size:30px; margin-bottom:10px; display:block;"></i>Empty</div>
                                <?php foreach($mat_assign as $m) echo renderMaterialCard($m, $current_time); ?>
                            </div>
                        </div>

                        <!-- Col 3: Projects -->
                        <div id="col-projects" style="flex: 1; min-width: 260px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 16px; padding: 15px; display: flex; flex-direction: column;">
                            <h4 style="color: #8b5cf6; font-size: 15px; font-weight: 800; margin-bottom: 15px; text-align: center; border-bottom: 2px dashed rgba(139,92,246,0.3); padding-bottom: 10px;"><i class="fa-solid fa-rocket"></i> Projects</h4>
                            <div class="card-container" style="overflow-y: auto; padding-right: 5px;">
                                <div class="empty-msg" style="text-align:center; color:var(--text-muted); font-size:12px; padding:20px; opacity:0.6; display:<?php echo empty($mat_proj) ? 'block' : 'none'; ?>;"><i class="fa-solid fa-folder-open" style="font-size:30px; margin-bottom:10px; display:block;"></i>Empty</div>
                                <?php foreach($mat_proj as $m) echo renderMaterialCard($m, $current_time); ?>
                            </div>
                        </div>

                        <!-- Col 4: Video/Audio -->
                        <div id="col-media" style="flex: 1; min-width: 260px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 16px; padding: 15px; display: flex; flex-direction: column;">
                            <h4 style="color: #0ea5e9; font-size: 15px; font-weight: 800; margin-bottom: 15px; text-align: center; border-bottom: 2px dashed rgba(14,165,233,0.3); padding-bottom: 10px;"><i class="fa-solid fa-circle-play"></i> Video / Audio</h4>
                            <div class="card-container" style="overflow-y: auto; padding-right: 5px;">
                                <div class="empty-msg" style="text-align:center; color:var(--text-muted); font-size:12px; padding:20px; opacity:0.6; display:<?php echo empty($mat_media) ? 'block' : 'none'; ?>;"><i class="fa-solid fa-folder-open" style="font-size:30px; margin-bottom:10px; display:block;"></i>Empty</div>
                                <?php foreach($mat_media as $m) echo renderMaterialCard($m, $current_time); ?>
                            </div>
                        </div>

                        <!-- Col 5: Others -->
                        <div id="col-others" style="flex: 1; min-width: 260px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 16px; padding: 15px; display: flex; flex-direction: column;">
                            <h4 style="color: #10b981; font-size: 15px; font-weight: 800; margin-bottom: 15px; text-align: center; border-bottom: 2px dashed rgba(16,185,129,0.3); padding-bottom: 10px;"><i class="fa-solid fa-box-open"></i> Others</h4>
                            <div class="card-container" style="overflow-y: auto; padding-right: 5px;">
                                <div class="empty-msg" style="text-align:center; color:var(--text-muted); font-size:12px; padding:20px; opacity:0.6; display:<?php echo empty($mat_other) ? 'block' : 'none'; ?>;"><i class="fa-solid fa-folder-open" style="font-size:30px; margin-bottom:10px; display:block;"></i>Empty</div>
                                <?php foreach($mat_other as $m) echo renderMaterialCard($m, $current_time); ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: MANAGE SUBMISSIONS (STUDENT WORK)         -->
        <!-- ============================================== -->
        <div id="submissions" class="section-tab">
            <div class="grid-2" style="grid-template-columns: 350px 1fr; align-items: start;">
                
                <!-- LEFT: LIST OF ASSIGNMENTS & PROJECTS -->
                <div class="premium-panel" style="border-top-color: #3b82f6; padding: 25px; margin-bottom:0; max-height: 75vh; display:flex; flex-direction:column;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px; font-size:16px;">
                        <div class="icon-box" style="width:30px; height:30px; font-size:14px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-inbox"></i></div> 
                        Select Task to Grade
                    </h3>
                    <div style="overflow-y: auto; padding-right: 5px; flex:1;">
                        <?php
                        // Fetch only Assignments and Projects created by this teacher
                        $task_q = mysqli_query($conn, "SELECT m.*, c.course_code FROM materials m JOIN course c ON m.course_id=c.id WHERE m.teacher_id=$teacher_id AND m.type IN ('assignment', 'project') ORDER BY m.id DESC");
                        $tasks_array =[];
                        
                        if(mysqli_num_rows($task_q) > 0){
                            while($task = mysqli_fetch_assoc($task_q)){
                                $tasks_array[] = $task;
                                $t_icon = $task['type'] == 'assignment' ? 'fa-file-pen' : 'fa-rocket';
                                $t_color = $task['type'] == 'assignment' ? '#3b82f6' : '#8b5cf6';
                                
                                // Count how many submitted
                                $sub_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM submissions WHERE material_id={$task['id']}"))['c'];
                                
                                echo "<div class='task-card' onclick=\"showSubmissions({$task['id']})\" style='background: var(--bg-color); border: 1px solid var(--border-color); border-left: 4px solid {$t_color}; border-radius: 12px; padding: 15px; cursor: pointer; transition: 0.3s; margin-bottom: 12px;' onmouseover=\"this.style.transform='translateY(-2px)'; this.style.borderColor='{$t_color}'\" onmouseout=\"this.style.transform='translateY(0)'; this.style.borderColor='var(--border-color)'\">
                                        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;'>
                                            <span style='font-family:monospace; font-size:11px; font-weight:800; color:var(--text-muted);'><i class='fa-solid {$t_icon}' style='color:{$t_color};'></i> {$task['course_code']}</span>
                                            <span class='badge' style='background:rgba(59,130,246,0.1); color:#3b82f6;'><i class='fa-solid fa-check-double'></i> {$sub_count} Submitted</span>
                                        </div>
                                        <h4 style='font-size:14px; font-weight:700; color:var(--text-main); margin-bottom:5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;' title='".htmlspecialchars($task['title'], ENT_QUOTES)."'>{$task['title']}</h4>
                                        <div style='font-size:11px; color:var(--text-muted);'><i class='fa-solid fa-hourglass-end'></i> Due: ".(empty($task['due_date']) ? 'No Deadline' : date("M d, h:i A", strtotime($task['due_date'])))."</div>
                                      </div>";
                            }
                        } else {
                            echo "<div style='text-align:center; color:var(--text-muted); padding:30px;'><i class='fa-solid fa-folder-open' style='font-size:30px; margin-bottom:10px; opacity:0.5;'></i><br>No assignments or projects published yet.</div>";
                        }
                        ?>
                    </div>
                </div>

                <!-- RIGHT: SUBMISSION DETAILS (DYNAMIC VIEW) -->
                <div class="premium-panel" style="margin-bottom:0; padding: 0; overflow: hidden; min-height: 60vh; display:flex; flex-direction:column; background: var(--bg-color);">
                    
                    <!-- Default Placeholder -->
                    <div id="sub-placeholder" style="display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: var(--text-muted); padding: 50px;">
                        <div class="icon-box" style="width:100px; height:100px; font-size:40px; background:rgba(59,130,246,0.1); color:#3b82f6; border-radius:50%; margin-bottom:20px; box-shadow: 0 0 20px rgba(59,130,246,0.2);"><i class="fa-solid fa-inbox"></i></div>
                        <h2 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:10px;">Submission Viewer</h2>
                        <p style="font-size:14px;">Select an assignment or project from the left panel to view student submissions, download their files, and check their status.</p>
                    </div>

                    <!-- Generate Hidden Views for Each Task -->
                    <?php
                    // Fetch all active students in the dept
                    $students_q = mysqli_query($conn, "SELECT id, first_name, last_name, id_number, profile_pic, profile_locked FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 ORDER BY first_name ASC");
                    $all_students = [];
                    while($s = mysqli_fetch_assoc($students_q)) { $all_students[] = $s; }

                    foreach($tasks_array as $task) {
                        $t_id = $task['id'];
                        $due_time = !empty($task['due_date']) ? strtotime($task['due_date']) : null;
                        
                        // Fetch submissions for this specific task
                        $sub_q = mysqli_query($conn, "SELECT * FROM submissions WHERE material_id=$t_id");
                        $subs =[];
                        while($sq = mysqli_fetch_assoc($sub_q)) { $subs[$sq['student_id']] = $sq; }
                        
                        $submitted_count = count($subs);
                        $total_students = count($all_students);
                        $pending_count = $total_students - $submitted_count;
                        $progress_pct = $total_students > 0 ? round(($submitted_count / $total_students) * 100) : 0;

                        echo "<div id='sub-view-{$t_id}' class='submission-view' style='display:none; flex-direction:column; height:100%;'>
                                
                                <!-- Header Stats -->
                                <div style='background: var(--panel-bg); padding: 25px 30px; border-bottom: 1px solid var(--border-color);'>
                                    <h2 style='font-size:20px; font-weight:800; color:var(--text-main); margin-bottom:15px;'>{$task['title']} <span style='font-size:13px; font-weight:600; color:#8b5cf6; background:rgba(139,92,246,0.1); padding:4px 10px; border-radius:20px; margin-left:10px;'>{$task['course_code']}</span></h2>
                                    
                                    <div style='display:flex; gap:20px;'>
                                        <div style='flex:1; background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); padding:15px; border-radius:12px; display:flex; align-items:center; gap:15px;'>
                                            <i class='fa-solid fa-check-circle' style='font-size:30px; color:#10b981;'></i>
                                            <div><h4 style='font-size:20px; font-weight:800; color:var(--text-main); margin:0;'>{$submitted_count}</h4><span style='font-size:12px; color:var(--text-muted);'>Submitted</span></div>
                                        </div>
                                        <div style='flex:1; background:rgba(245,158,11,0.05); border:1px solid rgba(245,158,11,0.2); padding:15px; border-radius:12px; display:flex; align-items:center; gap:15px;'>
                                            <i class='fa-solid fa-hourglass-half' style='font-size:30px; color:#f59e0b;'></i>
                                            <div><h4 style='font-size:20px; font-weight:800; color:var(--text-main); margin:0;'>{$pending_count}</h4><span style='font-size:12px; color:var(--text-muted);'>Pending</span></div>
                                        </div>
                                        <div style='flex:1; background:rgba(59,130,246,0.05); border:1px solid rgba(59,130,246,0.2); padding:15px; border-radius:12px; display:flex; flex-direction:column; justify-content:center;'>
                                            <div style='display:flex; justify-content:space-between; font-size:12px; color:var(--text-muted); margin-bottom:5px;'><span>Completion Rate</span><span>{$progress_pct}%</span></div>
                                            <div style='width:100%; height:8px; background:rgba(255,255,255,0.1); border-radius:4px; overflow:hidden;'><div style='width:{$progress_pct}%; height:100%; background:#3b82f6; border-radius:4px;'></div></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Students Table -->
                                <div style='flex:1; overflow-y:auto; padding: 20px;'>
                                    <table style='width: 100%; background: var(--panel-bg); border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
                                        <tr style='background: rgba(0,0,0,0.2);'><th style='padding:15px;'>Student Name</th><th>Submitted At</th><th>Status</th><th style='text-align:right; padding:15px;'>Action / Download</th></tr>";
                                        
                                        foreach($all_students as $stu) {
                                            $s_name = $stu['first_name'] . ' ' . $stu['last_name'];
                                            $s_idnum = $stu['id_number'];
                                            $s_pic = ($stu['profile_locked'] == 0 && $stu['profile_pic'] != 'default_student.png') ? "../uploads/".$stu['profile_pic'] : "";
                                            $s_initial = strtoupper(substr($stu['first_name'], 0, 1));
                                            $avatar = $s_pic ? "<img src='$s_pic' style='width:35px;height:35px;border-radius:50%;object-fit:cover; border:2px solid var(--border-color);'>" : "<div style='width:35px;height:35px;border-radius:50%;background:rgba(255,255,255,0.05);color:var(--text-muted);display:flex;justify-content:center;align-items:center;font-weight:bold;border:1px solid var(--border-color);'>{$s_initial}</div>";

                                            if(isset($subs[$stu['id']])) {
                                                // Student has submitted
                                                $sub_data = $subs[$stu['id']];
                                                $sub_time = strtotime($sub_data['submitted_at']);
                                                $time_str = date("d M Y, h:i A", $sub_time);
                                                
                                                // 🪄 Check Late vs On-Time
                                                $is_late = ($due_time && $sub_time > $due_time) ? true : false;
                                                $status_badge = $is_late 
                                                    ? "<span class='badge badge-yellow' style='background:rgba(245,158,11,0.1); color:#f59e0b;'><i class='fa-solid fa-clock-rotate-left'></i> Submitted Late</span>" 
                                                    : "<span class='badge btn-success' style='background:rgba(16,185,129,0.1); color:#10b981;'><i class='fa-solid fa-check-circle'></i> On Time</span>";
                                                
                                                $action_btn = "<a href='../uploads/submissions/{$sub_data['file_path']}' target='_blank' class='btn btn-sm' style='background:linear-gradient(135deg, #3b82f6, #1d4ed8); color:#fff; padding:8px 15px; border-radius:8px;' title='Download Student Work'><i class='fa-solid fa-download'></i> Download</a>";
                                                
                                                echo "<tr style='border-bottom: 1px solid var(--border-color); transition:0.3s;' onmouseover=\"this.style.background='rgba(255,255,255,0.02)'\" onmouseout=\"this.style.background='transparent'\">
                                                        <td style='padding:15px;'><div style='display:flex; align-items:center; gap:12px;'>{$avatar} <div><strong style='color:var(--text-main); font-size:14px;'>{$s_name}</strong><br><span style='font-size:11px; color:var(--text-muted); font-family:monospace;'>{$s_idnum}</span></div></div></td>
                                                        <td style='padding:15px; font-size:13px; color:var(--text-main); font-weight:600;'>{$time_str}</td>
                                                        <td style='padding:15px;'>{$status_badge}</td>
                                                        <td style='padding:15px; text-align:right;'>{$action_btn}</td>
                                                      </tr>";
                                            } else {
                                                // Not Submitted
                                                echo "<tr style='border-bottom: 1px solid var(--border-color); opacity:0.6; transition:0.3s;' onmouseover=\"this.style.opacity='1'; this.style.background='rgba(244,63,94,0.02)'\" onmouseout=\"this.style.opacity='0.6'; this.style.background='transparent'\">
                                                        <td style='padding:15px;'><div style='display:flex; align-items:center; gap:12px;'>{$avatar} <div><strong style='color:var(--text-main); font-size:14px;'>{$s_name}</strong><br><span style='font-size:11px; color:var(--text-muted); font-family:monospace;'>{$s_idnum}</span></div></div></td>
                                                        <td style='padding:15px; font-size:13px; color:var(--text-muted);'>--</td>
                                                        <td style='padding:15px;'><span class='badge badge-red' style='background:rgba(244,63,94,0.1); color:#f43f5e;'><i class='fa-solid fa-xmark'></i> Missing</span></td>
                                                        <td style='padding:15px; text-align:right;'><span style='color:var(--text-muted); font-size:12px; font-style:italic;'>No File</span></td>
                                                      </tr>";
                                            }
                                        }
                                        
                                echo "  </table>
                                </div>
                              </div>";
                    }
                    ?>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: EXAMS, QUIZZES & AUTO-GRADING             -->
        <!-- ============================================== -->
        <div id="exams" class="section-tab">
            <div class="grid-2" style="grid-template-columns: 380px 1fr; align-items: start;">
                
                <div style="display:flex; flex-direction:column; gap:20px; padding-right:5px;">                    
                    <!-- CREATE EXAM FORM (FIXED TOP) -->
                    <div class="premium-panel" style="border-top-color: #f43f5e; margin:0; padding:25px; flex-shrink:0;">
                        <h3 class="panel-title-premium" style="margin-bottom: 15px; font-size:16px;">
                            <div class="icon-box" style="width:30px; height:30px; font-size:14px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-stopwatch"></i></div> 
                            Schedule New Exam
                        </h3>
                        <form method="POST">
                            <div class="form-group" style="margin-bottom:10px;"><label>Select Course</label>
                                <div class="input-with-icon"><select name="course_id" required style="padding:10px 10px 10px 40px !important;"><option value="">-- Choose Course --</option>
                                <?php mysqli_data_seek($c_list, 0); while($cl = mysqli_fetch_assoc($c_list)) echo "<option value='{$cl['id']}'>{$cl['course_name']}</option>"; ?>
                                </select><i class="fa-solid fa-book"></i></div>
                            </div>
                            <div class="form-group" style="margin-bottom:10px;"><label>Exam / Quiz Title</label>
                                <div class="input-with-icon"><input type="text" name="exam_title" placeholder="e.g. Mid Exam, Quiz 1..." required style="padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-heading"></i></div>
                            </div>
                            <div style="display:flex; gap:10px; margin-bottom:10px;">
                                <div class="form-group" style="flex:1; margin:0;"><label>Type</label>
                                    <div class="input-with-icon"><select name="exam_type" required style="padding:10px 10px 10px 40px !important;"><option value="Quiz">Quiz</option><option value="Mid Exam">Mid Exam</option><option value="Final Exam">Final Exam</option></select><i class="fa-solid fa-filter"></i></div>
                                </div>
                                <div class="form-group" style="flex:1; margin:0;"><label>Duration (Mins)</label>
                                    <div class="input-with-icon"><input type="number" name="duration" placeholder="e.g. 60" min="5" required style="padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-hourglass-start"></i></div>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom:15px;"><label>Start Date & Time</label>
                                <input type="datetime-local" name="start_time" required style="width:100%; padding:10px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); border-radius:8px;">
                            </div>
                            <div class="form-group" style="margin-bottom:20px; background:rgba(244,63,94,0.05); padding:10px; border-radius:8px; border:1px dashed rgba(244,63,94,0.3);">
                                <label style="color:#f43f5e;"><i class="fa-solid fa-key"></i> Secret Access Code</label>
                                <div class="input-with-icon"><input type="text" name="access_code" placeholder="e.g. PASS123" required style="border-color:#f43f5e; padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-lock" style="color:#f43f5e;"></i></div>
                            </div>
                            <button type="submit" name="create_exam" class="glow-btn" style="width:100%; justify-content:center; background:linear-gradient(135deg, #f43f5e, #be123c); box-shadow:0 8px 20px rgba(244,63,94,0.3); padding:12px;"><i class="fa-solid fa-calendar-plus"></i> Schedule Exam</button>
                        </form>
                    </div>

                    <!-- LIST OF EXAMS (SCROLLABLE AREA) -->
                    <div class="hide-scrollbar" style="display:flex; flex-direction:column; gap:12px; max-height:650px; overflow-y:auto; padding-right:5px; padding-bottom:20px;">                        <?php
                        // 🪄 FIXED: Course Name iddoo Course Code galchameera
                        $ex_q = mysqli_query($conn, "SELECT e.*, c.course_name FROM exams e JOIN course c ON e.course_id=c.id WHERE e.teacher_id=$teacher_id AND e.is_deleted=0 ORDER BY e.id DESC");
                        if(mysqli_num_rows($ex_q) > 0){
                            while($ex = mysqli_fetch_assoc($ex_q)){
                                $time_str = date("d M, h:i A", strtotime($ex['start_time']));
                                $is_active = (strtotime($ex['start_time']) <= time() && strtotime($ex['start_time']) + ($ex['duration_mins']*60) >= time());
                                $status_badge = $is_active ? "<span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; animation:pulse-badge 2s infinite;'>LIVE</span>" : "<span class='badge badge-yellow'>Scheduled</span>";
                                
                                echo "<div class='exam-card' onclick=\"openExamManager({$ex['id']}, '".addslashes($ex['title'])."', '".addslashes($ex['course_name'])."', '{$ex['access_code']}')\" style='background:var(--panel-bg); border:1px solid var(--border-color); border-left:4px solid #f43f5e; padding:15px; border-radius:12px; cursor:pointer; transition:0.3s; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:12px; flex-shrink:0;' onmouseover=\"this.style.transform='translateY(-2px)'; this.style.borderColor='#f43f5e'\" onmouseout=\"this.style.transform='translateY(0)'; this.style.borderColor='var(--border-color)'\">
                                        
                                        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;'>
                                            <span style='font-size:11.5px; font-weight:800; color:var(--text-muted); background:var(--input-bg); padding:3px 8px; border-radius:6px;'><i class='fa-solid fa-book-open'></i> {$ex['course_name']}</span>
                                            {$status_badge}
                                        </div>
                                        
                                        <h4 style='color:var(--text-main); font-size:15px; font-weight:800; margin-bottom:10px;'>{$ex['title']}</h4>
                                        
                                        <div style='display:flex; justify-content:space-between; align-items:center; border-top: 1px dashed var(--border-color); padding-top: 10px;'>
                                            <div style='font-size:11.5px; color:var(--text-muted); display:flex; gap:10px;'>
                                                <span><i class='fa-regular fa-clock' style='color:#f43f5e;'></i> {$time_str}</span>
<span style='display:flex; align-items:center; gap:5px;'><i class='fa-solid fa-stopwatch' style='color:#f59e0b;'></i> {$ex['duration_mins']}m 
                                                    <button type='button' class='btn btn-sm' style='background:rgba(16,185,129,0.1); color:#10b981; padding:2px 6px; font-size:10px; border-radius:4px; border:1px solid rgba(16,185,129,0.3);' onclick=\"event.stopPropagation(); openTimeModal({$ex['id']}, '{$ex['title']}')\" title='Add More Time'>+ Time</button>
                                                </span>                                            </div>
                                            
                                            <form method='POST' style='margin:0;' onsubmit=\"return confirm('Are you sure you want to delete this exam?');\">
                                                <input type='hidden' name='exam_id' value='{$ex['id']}'>
                                                <button type='submit' name='delete_exam' class='btn btn-sm btn-danger' onclick='event.stopPropagation();' style='width:28px; height:28px; padding:0; display:flex; justify-content:center; align-items:center; border-radius:6px;' title='Delete Exam'><i class='fa-solid fa-trash'></i></button>
                                            </form>
                                        </div>

                                      </div>";
                            }
                        } else { echo "<div style='text-align:center; padding:20px; color:var(--text-muted); background:var(--panel-bg); border-radius:12px; border:1px dashed var(--border-color);'>No exams scheduled.</div>"; }
                        ?>
                    </div>
                </div>

                <!-- RIGHT COLUMN: EXAM MANAGER (SPA) -->
                <div class="premium-panel" style="margin:0; padding:0; display:flex; flex-direction:column; min-height: 75vh; overflow:hidden; background:var(--bg-color);">
                    
                    <!-- Placeholder -->
                    <div id="exam-placeholder" style="display:flex; flex-direction:column; justify-content:center; align-items:center; height:100%; color:var(--text-muted); padding:50px;">
                        <div class="icon-box" style="width:100px; height:100px; font-size:40px; background:rgba(244,63,94,0.05); color:#f43f5e; border-radius:50%; margin-bottom:20px; box-shadow:0 0 20px rgba(244,63,94,0.2);"><i class="fa-solid fa-laptop-code"></i></div>
                        <h2 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:10px;">Exam & Auto-Grading Manager</h2>
                        <p style="font-size:14px; text-align:center;">Select an exam from the left panel to build questions and monitor live student results.</p>
                    </div>

                    <!-- Active Exam Manager View -->
                    <div id="exam-manager-view" style="display:none; flex-direction:column; height:100%;">
                        <!-- Header -->
                        <div style="background:var(--panel-bg); padding:25px 30px; border-bottom:1px solid var(--border-color);">
                            <h2 id="mgr_exam_title" style="font-size:22px; font-weight:800; color:var(--text-main); margin-bottom:10px;">Exam Title</h2>
                            <div style="display:flex; gap:15px; font-size:13px; font-weight:600;">
                                <span style="background:rgba(59,130,246,0.1); color:#3b82f6; padding:5px 12px; border-radius:20px;" id="mgr_exam_course">Course</span>
                                <span style="background:rgba(244,63,94,0.1); color:#f43f5e; padding:5px 12px; border-radius:20px; border:1px dashed #f43f5e;" id="mgr_exam_code"><i class="fa-solid fa-key"></i> Code: </span>
                            </div>
                        </div>

                        <!-- Inner Tabs for Manager -->
                        <div style="display:flex; background:rgba(0,0,0,0.2); border-bottom:1px solid var(--border-color);">
                            <button onclick="switchExamTab('questions')" id="tab_btn_questions" style="flex:1; padding:15px; background:transparent; border:none; color:var(--primary); font-weight:800; border-bottom:3px solid var(--primary); cursor:pointer; font-size:14px; transition:0.3s;"><i class="fa-solid fa-list-ul"></i> Question Builder</button>
                            <button onclick="switchExamTab('results')" id="tab_btn_results" style="flex:1; padding:15px; background:transparent; border:none; color:var(--text-muted); font-weight:700; border-bottom:3px solid transparent; cursor:pointer; font-size:14px; transition:0.3s;"><i class="fa-solid fa-chart-simple"></i> Live Results</button>
                        </div>

                        <div style="flex:1; overflow-y:auto; padding:25px; position:relative;">
                            <!-- Loader -->
                            <div id="exam_loader" style="display:none; position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); color:var(--primary); font-size:30px;"><i class="fa-solid fa-circle-notch fa-spin"></i></div>

                          <!-- Questions Tab -->
                            <div id="exam_tab_questions" style="display:block;">
                                
                         <!-- 🤖 SMART AI & BULK UPLOAD HEADER -->
                                <div style="display:flex; gap:15px; margin-bottom: 25px;">
                                    <button class="glow-btn" style="flex:1; background:linear-gradient(135deg, #8b5cf6, #4c1d95); justify-content:center; box-shadow:0 8px 25px rgba(139,92,246,0.3); font-size:14px; padding:15px;" onclick="openSmartAiModal()">
                                        <i class="fa-solid fa-file-pdf"></i> Magic AI PDF Scanner
                                    </button>
                                   <button class="btn" style="flex:1; background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); justify-content:center; font-size:14px;" onclick="openBulkModal()">
                                        <i class="fa-solid fa-file-csv" style="color:#10b981;"></i> Standard CSV Upload
                                    </button>
                                </div>

                                <!-- MANUAL QUESTION BUILDER -->
                                <div style="background:var(--panel-bg); border:1px solid var(--border-color); border-top:4px solid #10b981; padding:25px; border-radius:16px; margin-bottom:25px; box-shadow:0 10px 30px rgba(0,0,0,0.05);">
                                    <h4 style="color:var(--text-main); font-size:16px; font-weight:800; margin-bottom:20px; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-plus" style="color:#10b981; background:rgba(16,185,129,0.1); padding:8px; border-radius:8px;"></i> Manual Question Builder</h4>
                                    
                                    <form id="add-question-form" onsubmit="submitQuestion(event)">
                                        <input type="hidden" name="exam_id" id="form_q_exam_id">
                                        
                                        <div class="form-group" style="margin-bottom:20px;">
                                            <label>Question Format / Type</label>
                                            <div class="input-with-icon">
                                                <select name="q_type" id="q_type_selector" onchange="toggleQuestionType()" required style="padding:12px 12px 12px 45px !important; font-weight:bold; color:var(--primary);">
                                                    <option value="multiple_choice">Multiple Choice (A-D or A-F)</option>
                                                    <option value="fill_blank">Fill in the Blanks (Exact Match)</option>
                                                    <option value="essay">Define / Essay (AI Auto-Graded)</option>
                                                </select>
                                                <i class="fa-solid fa-sliders"></i>
                                            </div>
                                        </div>

                                        <label style="font-weight:700; font-size:12.5px; color:var(--text-muted); display:block; margin-bottom:8px;">QUESTION TEXT</label>
                                        <textarea name="q_text" placeholder="Write your question here..." required style="width:100%; padding:15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); border-radius:12px; margin-bottom:20px; resize:vertical; min-height:100px; outline:none; font-family:'Inter';"></textarea>
                                        
                                        <!-- 🔘 Multiple Choice Options (Dynamic) -->
                                        <div id="mcq_options_container">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                                <label style="font-weight:700; font-size:12.5px; color:var(--text-muted); margin:0;">OPTIONS (Leave E & F blank if not needed)</label>
                                            </div>
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                                                <div class="input-with-icon"><input type="text" name="opt_a" id="opt_a" placeholder="Option A" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-a"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_b" id="opt_b" placeholder="Option B" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-b"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_c" id="opt_c" placeholder="Option C" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-c"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_d" id="opt_d" placeholder="Option D" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-d"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_e" id="opt_e" placeholder="Option E (Optional)" style="padding:12px 12px 12px 45px !important; border-color:rgba(255,255,255,0.1);"><i class="fa-solid fa-e"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_f" id="opt_f" placeholder="Option F (Optional)" style="padding:12px 12px 12px 45px !important; border-color:rgba(255,255,255,0.1);"><i class="fa-solid fa-f"></i></div>
                                            </div>
                                            <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(16,185,129,0.05); padding:15px; border-radius:12px; border:1px solid rgba(16,185,129,0.2); margin-bottom:20px;">
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <label style="color:#10b981; font-weight:800; margin:0; text-transform:none;"><i class="fa-solid fa-check-double"></i> Correct Answer:</label>
                                                    <select name="correct_opt" id="correct_opt" style="padding:8px 20px; background:var(--panel-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:8px; outline:none; font-weight:bold;">
                                                        <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option><option value="E">E</option><option value="F">F</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="text_options_container" style="display:none; margin-bottom:20px;">
                                            <label style="font-weight:700; font-size:12.5px; color:#ec4899; display:block; margin-bottom:8px; text-transform:uppercase;"><i class="fa-solid fa-robot"></i> Expected Answer / Keywords</label>
                                            <textarea name="correct_text" id="correct_text" placeholder="For Fill in Blanks: Write the exact word.&#10;For Essay: Write keywords..." style="width:100%; padding:15px; background:rgba(236,72,153,0.02); border:1px dashed #ec4899; color:var(--text-main); border-radius:12px; resize:vertical; min-height:80px;"></textarea>
                                        </div>
                                        
                                        <button type="submit" class="glow-btn" style="width:100%; justify-content:center; background:linear-gradient(135deg, #10b981, #047857); box-shadow:0 8px 20px rgba(16,185,129,0.3); padding:14px;"><i class="fa-solid fa-save"></i> Save Question to Bank</button>
                                    </form>
                                </div>
                                <!-- Rendered Questions List -->
                                <h4 style="color:var(--text-muted); font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:15px;"><i class="fa-solid fa-database"></i> Question Bank</h4>
                                <div id="questions-list-container"></div>
                            </div>
                            <!-- Results Tab -->
                            <div id="exam_tab_results" style="display:none;">
                                <div class="info-alert success" style="margin-top:0;"><i class="fa-solid fa-robot"></i> <strong>Auto-Grading Engine Active:</strong> The system automatically grades students the moment they submit their answers.</div>
                                <div id="results-table-container" style="background:var(--panel-bg); border-radius:12px; border:1px solid var(--border-color); overflow:hidden;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: MY ACADEMIC CALENDAR                      -->
        <!-- ============================================== -->
        <div id="calendar" class="section-tab">
            <div class="premium-panel" style="padding: 35px; border-top-color: #ec4899;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
                    <h3 class="panel-title-premium" style="margin:0; border:none; padding:0; color:#ec4899;">
                        <div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0 15px 0 0; background:linear-gradient(135deg, #ec4899, #be185d);"><i class="fa-regular fa-calendar-days"></i></div>
                        My Academic Calendar
                    </h3>
                    <div style="display:flex; gap:10px;">
                        <button class="btn btn-sm" onclick="changeMonth(-1)" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:8px; padding:8px 15px;"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                        <button class="btn btn-sm" onclick="changeMonth(0, true)" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:8px; padding:8px 15px; font-weight:800;">Today</button>
                        <button class="btn btn-sm" onclick="changeMonth(1)" style="background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:8px; padding:8px 15px;">Next <i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>

                <h2 id="calendar-month-year" style="text-align:center; font-weight:800; font-size:24px; margin-bottom:20px; color:var(--text-main);">Month Year</h2>

                <!-- Custom Vanilla CSS Calendar Grid -->
                <div style="border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; background: var(--bg-color); box-shadow: 0 10px 30px rgba(0,0,0,0.05);">
                    <div style="display: grid; grid-template-columns: repeat(7, 1fr); background: rgba(236,72,153,0.05); border-bottom: 1px solid var(--border-color); text-align: center; font-weight: 800; font-size: 13px; color: var(--text-muted); padding: 15px 0; text-transform:uppercase;">
                        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
                    </div>
                    <div id="calendar-grid" style="display: grid; grid-template-columns: repeat(7, 1fr); grid-auto-rows: minmax(120px, auto); gap: 1px; background: var(--border-color);">
                        <!-- JS generated cells go here -->
                    </div>
                </div>

                <!-- Legend -->
                <div style="display:flex; justify-content:center; gap:25px; margin-top:25px; font-size:13px; font-weight:700; color:var(--text-muted);">
                    <span style="display:flex; align-items:center; gap:8px;"><span style="width:14px; height:14px; border-radius:4px; background:#3b82f6;"></span> Exams / Quizzes</span>
                    <span style="display:flex; align-items:center; gap:8px;"><span style="width:14px; height:14px; border-radius:4px; background:#f59e0b;"></span> Assignments</span>
                    <span style="display:flex; align-items:center; gap:8px;"><span style="width:14px; height:14px; border-radius:4px; background:#8b5cf6;"></span> Projects</span>
                </div>
            </div>

            <!-- UPCOMING DEADLINES -->
            <div class="premium-panel" style="padding: 35px; border-top-color: #3b82f6;">
                <h3 class="panel-title-premium" style="margin-bottom:20px; border-bottom:2px dashed var(--border-color); padding-bottom:15px;"><i class="fa-solid fa-list-check" style="color:#3b82f6; margin-right:10px;"></i> Upcoming Deadlines & Events</h3>
                <div id="upcoming-events-list" style="display:flex; flex-direction:column; gap:15px;">
                    <!-- JS generated list -->
                </div>
            </div>
        </div>
        <!-- ============================================== -->
        <!-- TAB: MASTER GRADEBOOK CENTER                   -->
        <!-- ============================================== -->
        <div id="gradebook" class="section-tab">
            <div class="premium-panel" style="border-top-color: #f59e0b; padding: 30px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
                    <div>
                        <h3 class="panel-title-premium" style="margin:0; border:none; padding:0; color:#f59e0b;">
                            <div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0 15px 0 0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-star-half-stroke"></i></div> 
                            Master Gradebook
                        </h3>
                        <p style="font-size:13px; color:var(--text-muted); margin-top:8px;">Manage grades and dynamically configure evaluation columns.</p>
                    </div>
                    
                    <div class="input-with-icon" style="width: 300px;">
                        <select id="grade_course_selector" onchange="loadGradebook()" style="padding: 12px 15px 12px 45px !important; font-size: 14px; font-weight: bold; background: rgba(245, 158, 11, 0.05); border-color: #f59e0b; color: var(--text-main); cursor: pointer;">
                            <option value="">-- Select Course to Grade --</option>
                            <?php 
                            mysqli_data_seek($c_list, 0); 
                            while($cl = mysqli_fetch_assoc($c_list)) echo "<option value='{$cl['id']}'>{$cl['course_name']}</option>"; 
                            ?>
                        </select>
                        <i class="fa-solid fa-book" style="color: #f59e0b;"></i>
                    </div>
                </div>

                <div id="gradebook-placeholder" style="text-align:center; padding:50px; background:rgba(0,0,0,0.1); border-radius:16px; border:1px dashed var(--border-color);">
                    <i class="fa-solid fa-table-list" style="font-size:50px; color:var(--text-muted); opacity:0.3; margin-bottom:15px;"></i>
                    <h3 style="color:var(--text-main); font-size:18px;">Select a course to view the Gradebook</h3>
                </div>

                <!-- Grading Tables Generated Dynamically -->
                <?php
                mysqli_data_seek($c_list, 0);
                while($course = mysqli_fetch_assoc($c_list)) {
                    $c_id = $course['id'];
                    
                    // 🪄 FETCH DYNAMIC WEIGHTS FOR THIS COURSE
                    $set_q = mysqli_query($conn, "SELECT * FROM course_grade_settings WHERE course_id=$c_id");
                    $weights = mysqli_fetch_assoc($set_q) ?:['w_att'=>10, 'w_ass'=>10, 'w_proj'=>15, 'w_quiz'=>15, 'w_mid'=>20, 'w_fin'=>30];

                    echo "<div id='grade-view-{$c_id}' class='grade-view' style='display:none;'>
                            
                            <!-- 🪄 MAGIC: Config Button -->
                            <div style='display:flex; justify-content:flex-end; margin-bottom:15px;'>
                                <button class='btn btn-sm' style='background:rgba(245,158,11,0.1); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); padding:8px 15px; border-radius:8px;' onclick=\"openGradeSettings({$c_id}, '{$course['course_name']}', {$weights['w_att']}, {$weights['w_ass']}, {$weights['w_proj']}, {$weights['w_quiz']}, {$weights['w_mid']}, {$weights['w_fin']})\"><i class='fa-solid fa-gear'></i> Configure Grading Criteria</button>
                            </div>

                            <form method='POST'>
                                <input type='hidden' name='grade_course_id' value='{$c_id}'>
                                
                                <div style='overflow-x:auto; border-radius:12px; border:1px solid var(--border-color); background:var(--bg-color);'>
                                    <table class='grade-table' style='width:100%; white-space:nowrap;'>
                                        <tr style='background:rgba(245,158,11,0.1); color:#f59e0b;'>
                                            <th style='padding:15px; border-right:1px solid var(--border-color);'>Student Name & ID</th>";
                                            
                                            // 🪄 ONLY SHOW HEADERS IF WEIGHT > 0
                                            if($weights['w_att'] > 0) echo "<th style='text-align:center;'>Attendance<br><small>({$weights['w_att']}%)</small></th>";
                                            if($weights['w_ass'] > 0) echo "<th style='text-align:center;'>Assignment<br><small>({$weights['w_ass']}%)</small></th>";
                                            if($weights['w_proj'] > 0) echo "<th style='text-align:center;'>Project<br><small>({$weights['w_proj']}%)</small></th>";
                                            if($weights['w_quiz'] > 0) echo "<th style='text-align:center;'>Quiz<br><small>({$weights['w_quiz']}%)</small></th>";
                                            if($weights['w_mid'] > 0) echo "<th style='text-align:center;'>Mid Exam<br><small>({$weights['w_mid']}%)</small></th>";
                                            if($weights['w_fin'] > 0) echo "<th style='text-align:center;'>Final Exam<br><small>({$weights['w_fin']}%)</small></th>";
                                            
                                  echo "<th style='text-align:center; background:rgba(16,185,129,0.1); color:#10b981;'>Total<br><small>(100%)</small></th>
                                            <th style='text-align:center; background:rgba(59,130,246,0.1); color:#3b82f6;'>Grade</th>
                                            <th style='text-align:center; background:rgba(244,63,94,0.1); color:#f43f5e;'>Action</th>
                                        </tr>";

                                $studs = mysqli_query($conn, "SELECT id, first_name, last_name, id_number FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 ORDER BY first_name ASC");
                                
                                if(mysqli_num_rows($studs) > 0) {
                                    $is_course_published = false;

                                    while($s = mysqli_fetch_assoc($studs)) {
                                        $s_id = $s['id'];
                                        $g_q = mysqli_query($conn, "SELECT * FROM student_grades WHERE course_id=$c_id AND student_id=$s_id");
                                        if($g = mysqli_fetch_assoc($g_q)) {
                                            $att = $g['attendance']; $ass = $g['assignment']; $proj = $g['project']; 
                                            $quiz = $g['quiz']; $mid = $g['mid_exam']; $fin = $g['final_exam'];
                                            $total = $g['total_score']; $letter = $g['grade_letter'];
                                            $edit_req = $g['edit_requested'] ?? 0;
                                            $grade_id = $g['id'] ?? 0;
                                            if($g['is_published'] == 1) $is_course_published = true;
                                        } else {
                                            $att = 0; $ass = 0; $proj = 0; $quiz = 0; $mid = 0; $fin = 0; $total = 0; $letter = '-';
                                            $edit_req = 0; $grade_id = 0;
                                        }

                                        $readonly = $is_course_published ? "readonly style='opacity:0.6; cursor:not-allowed;'" : "";

                                        echo "<tr style='border-bottom:1px solid var(--border-color);'>
                                                <td style='padding:12px 15px; border-right:1px solid var(--border-color);'>
                                                    <input type='hidden' name='student_ids[]' value='{$s_id}'>
                                                    <strong style='color:var(--text-main); font-size:14px;'>{$s['first_name']} {$s['last_name']}</strong><br>
                                                    <span style='font-family:monospace; color:var(--text-muted); font-size:11px;'>{$s['id_number']}</span>
                                                </td>";
                                                
                                                // 🪄 ONLY SHOW INPUT CELLS IF WEIGHT > 0 (And set max attribute)
                                                if($weights['w_att'] > 0) echo "<td style='padding:8px;'><input type='number' name='att_{$s_id}' class='grade-input' value='{$att}' min='0' max='{$weights['w_att']}' step='0.1' onkeyup='calcTotal(this)' $readonly></td>";
                                                else echo "<input type='hidden' name='att_{$s_id}' value='0'>"; // Send 0 secretly if hidden
                                                
                                                if($weights['w_ass'] > 0) echo "<td style='padding:8px;'><input type='number' name='ass_{$s_id}' class='grade-input' value='{$ass}' min='0' max='{$weights['w_ass']}' step='0.1' onkeyup='calcTotal(this)' $readonly></td>";
                                                else echo "<input type='hidden' name='ass_{$s_id}' value='0'>";
                                                
                                                if($weights['w_proj'] > 0) echo "<td style='padding:8px;'><input type='number' name='proj_{$s_id}' class='grade-input' value='{$proj}' min='0' max='{$weights['w_proj']}' step='0.1' onkeyup='calcTotal(this)' $readonly></td>";
                                                else echo "<input type='hidden' name='proj_{$s_id}' value='0'>";
                                                
                                                if($weights['w_quiz'] > 0) echo "<td style='padding:8px;'><input type='number' name='quiz_{$s_id}' class='grade-input' value='{$quiz}' min='0' max='{$weights['w_quiz']}' step='0.1' onkeyup='calcTotal(this)' $readonly></td>";
                                                else echo "<input type='hidden' name='quiz_{$s_id}' value='0'>";
                                                
                                                if($weights['w_mid'] > 0) echo "<td style='padding:8px;'><input type='number' name='mid_{$s_id}' class='grade-input' value='{$mid}' min='0' max='{$weights['w_mid']}' step='0.1' onkeyup='calcTotal(this)' $readonly></td>";
                                                else echo "<input type='hidden' name='mid_{$s_id}' value='0'>";
                                                
                                                if($weights['w_fin'] > 0) echo "<td style='padding:8px;'><input type='number' name='fin_{$s_id}' class='grade-input' value='{$fin}' min='0' max='{$weights['w_fin']}' step='0.1' onkeyup='calcTotal(this)' $readonly></td>";
                                                else echo "<input type='hidden' name='fin_{$s_id}' value='0'>";

                                        echo "  <td style='padding:12px; text-align:center; background:rgba(16,185,129,0.05);'>
                                                    <strong class='total-display' style='color:#10b981; font-size:16px;'>{$total}</strong>
                                                </td>
                                                <td style='padding:12px; text-align:center; background:rgba(59,130,246,0.05);'>
                                                    <strong class='letter-display' style='color:#3b82f6; font-size:18px;'>{$letter}</strong>
                                                </td>
                                                <td style='padding:12px; text-align:center;'>";
                                        
                                        if($is_course_published && $grade_id > 0) {
                                            if($edit_req == 1) {
                                                echo "<span class='badge badge-yellow' style='font-size:9px;'><i class='fa-solid fa-clock'></i> Requested</span>";
                                            } else {
                                                echo "<button type='button' class='btn btn-sm' style='background:rgba(244,63,94,0.1); color:#f43f5e;' title='Request HoD to Edit' onclick=\"if(confirm('Do you want to request the Head of Department to unlock and edit this student\'s grade?')){ document.getElementById('req_grade_id_{$grade_id}').submit(); }\"><i class='fa-solid fa-unlock-keyhole'></i></button>
                                                      <form id='req_grade_id_{$grade_id}' method='POST' style='display:none;'><input type='hidden' name='grade_id' value='{$grade_id}'><input type='hidden' name='request_grade_edit' value='1'></form>";
                                            }
                                        } else {
                                            echo "<span style='color:var(--text-muted); font-size:11px;'><i class='fa-solid fa-pen'></i> Draft</span>";
                                        }
                                        
                                        echo "  </td>
                                              </tr>";
                                    }
                                } else { echo "<tr><td colspan='10' style='text-align:center; padding:30px; color:var(--text-muted);'>No active students enrolled.</td></tr>"; }

                                echo "  </table>
                                </div>";

                        if($is_course_published) {
                            echo "<div class='info-alert success' style='margin-top:20px;'><i class='fa-solid fa-lock'></i> <strong>Grades Published:</strong> You have successfully published these grades to the HoD and Students. They cannot be edited anymore.</div>";
                        } else {
                            echo "<div style='display:flex; justify-content:flex-end; gap:15px; margin-top:25px;'>
                                    <button type='submit' name='save_grades' class='btn' style='background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); padding:12px 25px;'><i class='fa-solid fa-save'></i> Save as Draft</button>
                                    <button type='submit' name='publish_grades' class='glow-btn' style='background:linear-gradient(135deg, #10b981, #047857); padding:12px 30px;' onclick=\"return confirm('Are you sure you want to PUBLISH these grades? Once published, students and the HoD will see them and you cannot edit them.');\"><i class='fa-solid fa-paper-plane'></i> Finalize & Publish</button>
                                  </div>";
                        }

                        echo "</form></div>";
                }
                ?>
            </div>
        </div>
       <!-- ============================================== -->
        <!-- TAB 3: MY STUDENTS (PREMIUM CARD GRID)         -->
        <!-- ============================================== -->
        <div id="students" class="section-tab">
            
            <div class="premium-panel" style="padding:25px; margin-bottom:25px; border-top-color:#10b981;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                    <div>
                        <h3 class="panel-title-premium" style="margin:0; border:none; padding:0;">
                            <div class="icon-box" style="width:40px; height:40px; font-size:18px; margin:0 15px 0 0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-users"></i></div> 
                            Students Directory
                        </h3>
                        <p style="font-size:13px; color:var(--text-muted); margin-top:8px;">View and connect with all active students enrolled in your department.</p>
                    </div>
                    <div class="input-with-icon" style="width: 300px; max-width:100%;">
                        <input type="text" id="student_search_magic" placeholder="Search by Name or ID..." onkeyup="filterStudentsMagic()" style="padding: 12px 15px 12px 45px !important; border-radius: 30px; background: rgba(16,185,129,0.05); border: 1px solid rgba(16,185,129,0.2); font-size: 14px;">
                        <i class="fa-solid fa-magnifying-glass" style="color:#10b981;"></i>
                    </div>
                </div>
            </div>
            
            <div id="students-grid-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; max-height: 65vh; overflow-y: auto; padding-right: 5px; padding-bottom: 20px;">
                <?php
                $studs = mysqli_query($conn, "SELECT * FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 ORDER BY first_name ASC");
                if(mysqli_num_rows($studs) > 0){
                    while($s = mysqli_fetch_assoc($studs)){
                        $s_pic = ($s['profile_locked'] == 0 && $s['profile_pic'] != 'default_student.png') ? "../uploads/".$s['profile_pic'] : "";
                        $s_initial = strtoupper(substr($s['first_name'], 0, 1));
                        // 🪄 MAGIC: Class Rep Badge for Teacher View
                        $rep_badge = (isset($s['is_rep']) && $s['is_rep'] == 1) ? "<i class='fa-solid fa-crown' style='color:#f59e0b; margin-left:8px; font-size:16px; filter: drop-shadow(0 0 8px rgba(245, 158, 11, 0.5));' title='Class Representative'></i>" : "";
                        $avatar = $s_pic ? "<img src='$s_pic' style='width:100%;height:100%;border-radius:50%;object-fit:cover;'>" : $s_initial;
                        
                        $full_name = htmlspecialchars($s['first_name'] . ' ' . $s['last_name']);
                        
                        // Path for chat function
                        $chat_avatar = $s_pic ? $s['profile_pic'] : '';

                        echo "
                        <div class='student-magic-card' style='background: var(--panel-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 25px; text-align: center; position: relative; transition: 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; flex-direction: column; align-items: center;' onmouseover=\"this.style.transform='translateY(-5px)'; this.style.boxShadow='0 15px 35px rgba(16, 185, 129, 0.15)'; this.style.borderColor='#10b981';\" onmouseout=\"this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.03)'; this.style.borderColor='var(--border-color)';\">
                            
                            <div style='position:absolute; top:15px; right:15px;'><span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; border:none; padding:4px 8px;'><i class='fa-solid fa-circle-check'></i> Active</span></div>

                            <div style='width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #047857); color: #fff; display: flex; justify-content: center; align-items: center; font-size: 30px; font-weight: 800; margin-bottom: 15px; border: 3px solid rgba(16,185,129,0.2); box-shadow: 0 5px 15px rgba(16,185,129,0.3); overflow:hidden;'>
                                {$avatar}
                            </div>

<h4 class='student-name-filter' style='font-size: 18px; font-weight: 800; color: var(--text-main); margin-bottom: 5px; display:flex; align-items:center; justify-content:center;'>{$full_name} {$rep_badge}</h4>                            <span class='student-id-filter' style='font-family: monospace; font-size: 12px; font-weight: 800; color: #10b981; background: rgba(16,185,129,0.1); padding: 4px 10px; border-radius: 20px; margin-bottom: 15px; border: 1px solid rgba(16,185,129,0.3);'><i class='fa-solid fa-id-card'></i> {$s['id_number']}</span>
                            
                            <div style='width: 100%; background: rgba(0,0,0,0.02); border-radius: 12px; padding: 15px; margin-bottom: 20px; text-align: left; border: 1px solid var(--border-color);'>
                                <div style='font-size: 13px; color: var(--text-main); margin-bottom: 10px; display:flex; align-items:center; gap:10px;'><i class='fa-solid fa-at' style='color:var(--primary); width:15px; font-size:14px;'></i> <span style='font-weight:700;'>@{$s['username']}</span></div>
                                <div style='font-size: 13px; color: var(--text-main); margin-bottom: 10px; display:flex; align-items:center; gap:10px;'><i class='fa-solid fa-envelope' style='color:var(--text-muted); width:15px; font-size:14px;'></i> <span style='font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;' title='{$s['email']}'>{$s['email']}</span></div>
                                <div style='font-size: 13px; color: var(--text-main); display:flex; align-items:center; gap:10px;'><i class='fa-solid fa-phone' style='color:var(--text-muted); width:15px; font-size:14px;'></i> <span style='font-weight:500;'>{$s['phone']}</span></div>
                            </div>

                            <button onclick=\"openTab('broadcast'); setTimeout(() => openTelegramChat({$s['id']}, 'student', 0, '".addslashes($full_name)."', 'Student', '#f43f5e', '{$chat_avatar}'), 100);\" class='glow-btn' style='width: 100%; justify-content: center; padding: 12px; font-size: 13.5px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 4px 15px rgba(59,130,246,0.3); margin-top:auto;'><i class='fa-solid fa-paper-plane'></i> Direct Message</button>

                        </div>";
                    }
                } else { 
                    echo "<div style='grid-column: 1/-1; text-align:center; padding:50px; background:var(--panel-bg); border-radius:16px; border:1px dashed var(--border-color);'>
                            <i class='fa-solid fa-user-slash' style='font-size:50px; color:var(--text-muted); margin-bottom:15px; opacity:0.5;'></i>
                            <h3 style='color:var(--text-main); font-size:18px;'>No Students Found</h3>
                            <p style='color:var(--text-muted); font-size:13.5px;'>There are currently no active students enrolled in your department.</p>
                          </div>"; 
                }
                ?>
            </div>
        </div>
<!-- ============================================== -->
        <!-- TAB: EXAMS, QUIZZES & AUTO-GRADING             -->
        <!-- ============================================== -->
        <div id="exams" class="section-tab">
            <div class="grid-2" style="grid-template-columns: 380px 1fr; align-items: start;">
                
                <!-- LEFT COLUMN: CREATE & LIST EXAMS -->
                <div style="display:flex; flex-direction:column; gap:20px; padding-right:5px;">                    
                    
                    <!-- CREATE EXAM FORM (FIXED TOP) -->
                    <div class="premium-panel" style="border-top-color: #f43f5e; margin:0; padding:25px; flex-shrink:0;">
                        <h3 class="panel-title-premium" style="margin-bottom: 15px; font-size:16px;">
                            <div class="icon-box" style="width:30px; height:30px; font-size:14px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-stopwatch"></i></div> 
                            Schedule New Exam
                        </h3>
                        <form method="POST">
                            <div class="form-group" style="margin-bottom:10px;"><label>Select Course</label>
                                <div class="input-with-icon"><select name="course_id" required style="padding:10px 10px 10px 40px !important;"><option value="">-- Choose Course --</option>
                                <?php mysqli_data_seek($c_list, 0); while($cl = mysqli_fetch_assoc($c_list)) echo "<option value='{$cl['id']}'>{$cl['course_name']}</option>"; ?>
                                </select><i class="fa-solid fa-book"></i></div>
                            </div>
                            <div class="form-group" style="margin-bottom:10px;"><label>Exam / Quiz Title</label>
                                <div class="input-with-icon"><input type="text" name="exam_title" placeholder="e.g. Mid Exam, Quiz 1..." required style="padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-heading"></i></div>
                            </div>
                            <div style="display:flex; gap:10px; margin-bottom:10px;">
                                <div class="form-group" style="flex:1; margin:0;"><label>Type</label>
                                    <div class="input-with-icon"><select name="exam_type" required style="padding:10px 10px 10px 40px !important;"><option value="Quiz">Quiz</option><option value="Mid Exam">Mid Exam</option><option value="Final Exam">Final Exam</option></select><i class="fa-solid fa-filter"></i></div>
                                </div>
                                <div class="form-group" style="flex:1; margin:0;"><label>Duration (Mins)</label>
                                    <div class="input-with-icon"><input type="number" name="duration" placeholder="e.g. 60" min="5" required style="padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-hourglass-start"></i></div>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom:15px;"><label>Start Date & Time</label>
                                <input type="datetime-local" name="start_time" required style="width:100%; padding:10px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); border-radius:8px;">
                            </div>
                            <div class="form-group" style="margin-bottom:20px; background:rgba(244,63,94,0.05); padding:10px; border-radius:8px; border:1px dashed rgba(244,63,94,0.3);">
                                <label style="color:#f43f5e;"><i class="fa-solid fa-key"></i> Secret Access Code</label>
                                <div class="input-with-icon"><input type="text" name="access_code" placeholder="e.g. PASS123" required style="border-color:#f43f5e; padding:10px 10px 10px 40px !important;"><i class="fa-solid fa-lock" style="color:#f43f5e;"></i></div>
                            </div>
                            <button type="submit" name="create_exam" class="glow-btn" style="width:100%; justify-content:center; background:linear-gradient(135deg, #f43f5e, #be123c); box-shadow:0 8px 20px rgba(244,63,94,0.3); padding:12px;"><i class="fa-solid fa-calendar-plus"></i> Schedule Exam</button>
                        </form>
                    </div>

                    <!-- LIST OF EXAMS (SCROLLABLE AREA) -->
                    <div class="hide-scrollbar" style="display:flex; flex-direction:column; gap:12px; max-height:650px; overflow-y:auto; padding-right:5px; padding-bottom:20px;">
                        <h4 style="color:var(--text-muted); font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:5px; position:sticky; top:0; background:var(--bg-color); z-index:2; padding:5px 0;"><i class="fa-solid fa-list"></i> My Scheduled Exams</h4>
                        <?php
                        $ex_q = mysqli_query($conn, "SELECT e.*, c.course_name FROM exams e JOIN course c ON e.course_id=c.id WHERE e.teacher_id=$teacher_id AND e.is_deleted=0 ORDER BY e.id DESC");
                        if(mysqli_num_rows($ex_q) > 0){
                            while($ex = mysqli_fetch_assoc($ex_q)){
                                $time_str = date("d M, h:i A", strtotime($ex['start_time']));
                                $is_active = (strtotime($ex['start_time']) <= time() && strtotime($ex['start_time']) + ($ex['duration_mins']*60) >= time());
                                $status_badge = $is_active ? "<span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; animation:pulse-badge 2s infinite;'>LIVE</span>" : "<span class='badge badge-yellow'>Scheduled</span>";
                                
                                echo "<div class='exam-card' onclick=\"openExamManager({$ex['id']}, '".addslashes($ex['title'])."', '".addslashes($ex['course_name'])."', '{$ex['access_code']}')\" style='background:var(--panel-bg); border:1px solid var(--border-color); border-left:4px solid #f43f5e; padding:15px; border-radius:12px; cursor:pointer; transition:0.3s; box-shadow:0 4px 10px rgba(0,0,0,0.05); margin-bottom:12px; flex-shrink:0;' onmouseover=\"this.style.transform='translateY(-2px)'; this.style.borderColor='#f43f5e'\" onmouseout=\"this.style.transform='translateY(0)'; this.style.borderColor='var(--border-color)'\">
                                        
                                        <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;'>
                                            <span style='font-size:11.5px; font-weight:800; color:var(--text-muted); background:var(--input-bg); padding:3px 8px; border-radius:6px;'><i class='fa-solid fa-book-open'></i> {$ex['course_name']}</span>
                                            {$status_badge}
                                        </div>
                                        
                                        <h4 style='color:var(--text-main); font-size:15px; font-weight:800; margin-bottom:10px;'>{$ex['title']}</h4>
                                        
                                        <div style='display:flex; justify-content:space-between; align-items:center; border-top: 1px dashed var(--border-color); padding-top: 10px;'>
                                            <div style='font-size:11.5px; color:var(--text-muted); display:flex; gap:10px;'>
                                                <span><i class='fa-regular fa-clock' style='color:#f43f5e;'></i> {$time_str}</span>
                                                <span><i class='fa-solid fa-stopwatch' style='color:#f59e0b;'></i> {$ex['duration_mins']}m</span>
                                            </div>
                                            
                                            <form method='POST' style='margin:0;' onsubmit=\"return confirm('Are you sure you want to delete this exam?');\">
                                                <input type='hidden' name='exam_id' value='{$ex['id']}'>
                                                <button type='submit' name='delete_exam' class='btn btn-sm btn-danger' onclick='event.stopPropagation();' style='width:28px; height:28px; padding:0; display:flex; justify-content:center; align-items:center; border-radius:6px;' title='Delete Exam'><i class='fa-solid fa-trash'></i></button>
                                            </form>
                                        </div>

                                      </div>";
                            }
                        } else { echo "<div style='text-align:center; padding:20px; color:var(--text-muted); background:var(--panel-bg); border-radius:12px; border:1px dashed var(--border-color);'>No exams scheduled.</div>"; }
                        ?>
                    </div>
                </div>

                <!-- RIGHT COLUMN: EXAM MANAGER (SPA) -->
                <div class="premium-panel" style="margin:0; padding:0; display:flex; flex-direction:column; min-height: 75vh; overflow:hidden; background:var(--bg-color);">
                    
                    <!-- Placeholder -->
                    <div id="exam-placeholder" style="display:flex; flex-direction:column; justify-content:center; align-items:center; height:100%; color:var(--text-muted); padding:50px;">
                        <div class="icon-box" style="width:100px; height:100px; font-size:40px; background:rgba(244,63,94,0.05); color:#f43f5e; border-radius:50%; margin-bottom:20px; box-shadow:0 0 20px rgba(244,63,94,0.2);"><i class="fa-solid fa-laptop-code"></i></div>
                        <h2 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:10px;">Exam & Auto-Grading Manager</h2>
                        <p style="font-size:14px; text-align:center;">Select an exam from the left panel to build questions and monitor live student results.</p>
                    </div>

                    <!-- Active Exam Manager View -->
                    <div id="exam-manager-view" style="display:none; flex-direction:column; height:100%;">
                        <!-- Header -->
                        <div style="background:var(--panel-bg); padding:25px 30px; border-bottom:1px solid var(--border-color);">
                            <h2 id="mgr_exam_title" style="font-size:22px; font-weight:800; color:var(--text-main); margin-bottom:10px;">Exam Title</h2>
                            <div style="display:flex; gap:15px; font-size:13px; font-weight:600;">
                                <span style="background:rgba(59,130,246,0.1); color:#3b82f6; padding:5px 12px; border-radius:20px;" id="mgr_exam_course">Course</span>
                                <span style="background:rgba(244,63,94,0.1); color:#f43f5e; padding:5px 12px; border-radius:20px; border:1px dashed #f43f5e;" id="mgr_exam_code"><i class="fa-solid fa-key"></i> Code: </span>
                            </div>
                        </div>

                        <!-- Inner Tabs for Manager -->
                        <div style="display:flex; background:rgba(0,0,0,0.2); border-bottom:1px solid var(--border-color);">
                            <button onclick="switchExamTab('questions')" id="tab_btn_questions" style="flex:1; padding:15px; background:transparent; border:none; color:var(--primary); font-weight:800; border-bottom:3px solid var(--primary); cursor:pointer; font-size:14px; transition:0.3s;"><i class="fa-solid fa-list-ul"></i> Question Builder</button>
                            <button onclick="switchExamTab('results')" id="tab_btn_results" style="flex:1; padding:15px; background:transparent; border:none; color:var(--text-muted); font-weight:700; border-bottom:3px solid transparent; cursor:pointer; font-size:14px; transition:0.3s;"><i class="fa-solid fa-chart-simple"></i> Live Results</button>
                        </div>

                        <div style="flex:1; overflow-y:auto; padding:25px; position:relative;">
                            <!-- Loader -->
                            <div id="exam_loader" style="display:none; position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); color:var(--primary); font-size:30px;"><i class="fa-solid fa-circle-notch fa-spin"></i></div>

                            <!-- Questions Tab -->
                            <div id="exam_tab_questions" style="display:block;">
                                
                                <!-- 🤖 AI & BULK TOOLS HEADER -->
                                <div style="display:flex; gap:15px; margin-bottom: 25px;">
                                    <button class="glow-btn" style="flex:1; background:linear-gradient(135deg, #ec4899, #8b5cf6); justify-content:center; box-shadow:0 8px 25px rgba(236,72,153,0.3);" onclick="openAiModal()">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate via AI API
                                    </button>
                                   <button class="btn" style="flex:1; background:var(--input-bg); color:var(--text-main); border:1px solid var(--border-color); justify-content:center;" onclick="openBulkModal()">
                                        <i class="fa-solid fa-file-csv" style="color:#10b981;"></i> Bulk Upload (CSV)
                                    </button>
                                </div>

                                <!-- Add Question Form -->
                                <div style="background:var(--panel-bg); border:1px solid var(--border-color); border-top:4px solid #10b981; padding:25px; border-radius:16px; margin-bottom:25px; box-shadow:0 10px 30px rgba(0,0,0,0.05);">
                                    <h4 style="color:var(--text-main); font-size:16px; font-weight:800; margin-bottom:20px; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-plus" style="color:#10b981; background:rgba(16,185,129,0.1); padding:8px; border-radius:8px;"></i> Manual Question Builder</h4>
                                    
                                    <form id="add-question-form" onsubmit="submitQuestion(event)">
                                        <input type="hidden" name="exam_id" id="form_q_exam_id">
                                        
                                        <!-- 🪄 Question Type Selector -->
                                        <div class="form-group" style="margin-bottom:20px;">
                                            <label>Question Format / Type</label>
                                            <div class="input-with-icon">
                                                <select name="q_type" id="q_type_selector" onchange="toggleQuestionType()" required style="padding:12px 12px 12px 45px !important; font-weight:bold; color:var(--primary);">
                                                    <option value="multiple_choice">A, B, C, D (Multiple Choice)</option>
                                                    <option value="fill_blank">Fill in the Blanks (Exact Match)</option>
                                                    <option value="essay">Define / Essay (AI Auto-Graded)</option>
                                                </select>
                                                <i class="fa-solid fa-sliders"></i>
                                            </div>
                                        </div>

                                        <label style="font-weight:700; font-size:12.5px; color:var(--text-muted); display:block; margin-bottom:8px; text-transform:uppercase;">Question Text</label>
                                        <textarea name="q_text" placeholder="Write your question here..." required style="width:100%; padding:15px; background:var(--input-bg); border:1px solid var(--border-color); color:var(--text-main); border-radius:12px; margin-bottom:20px; resize:vertical; min-height:100px; outline:none; font-family:'Inter'; font-size:14.5px; transition:0.3s;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border-color)'"></textarea>
                                        
                                        <!-- 🔘 Multiple Choice Options -->
                                        <div id="mcq_options_container">
                                            <label style="font-weight:700; font-size:12.5px; color:var(--text-muted); display:block; margin-bottom:8px; text-transform:uppercase;">Multiple Choice Options</label>
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                                                <div class="input-with-icon"><input type="text" name="opt_a" id="opt_a" placeholder="Option A" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-a"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_b" id="opt_b" placeholder="Option B" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-b"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_c" id="opt_c" placeholder="Option C" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-c"></i></div>
                                                <div class="input-with-icon"><input type="text" name="opt_d" id="opt_d" placeholder="Option D" required style="padding:12px 12px 12px 45px !important;"><i class="fa-solid fa-d"></i></div>
                                            </div>
                                            <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(16,185,129,0.05); padding:15px; border-radius:12px; border:1px solid rgba(16,185,129,0.2); margin-bottom:20px;">
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <label style="color:#10b981; font-weight:800; margin:0; text-transform:none;"><i class="fa-solid fa-check-double"></i> Select Correct Answer:</label>
                                                    <select name="correct_opt" id="correct_opt" style="padding:8px 20px; background:var(--panel-bg); color:var(--text-main); border:1px solid var(--border-color); border-radius:8px; outline:none; font-weight:bold;">
                                                        <option value="A">Option A</option><option value="B">Option B</option><option value="C">Option C</option><option value="D">Option D</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- ✍️ Text / Essay Input (Hidden by default) -->
                                        <div id="text_options_container" style="display:none; margin-bottom:20px;">
                                            <label style="font-weight:700; font-size:12.5px; color:#ec4899; display:block; margin-bottom:8px; text-transform:uppercase;"><i class="fa-solid fa-robot"></i> Expected Answer / AI Grading Keywords</label>
                                            <textarea name="correct_text" id="correct_text" placeholder="For Fill in Blanks: Write the exact word.&#10;For Essay/Define: Write the keywords or key concepts the AI should look for to assign marks..." style="width:100%; padding:15px; background:rgba(236,72,153,0.02); border:1px dashed #ec4899; color:var(--text-main); border-radius:12px; resize:vertical; min-height:80px; outline:none; font-family:'Inter'; font-size:14px;"></textarea>
                                        </div>
                                        
                                        <button type="submit" class="glow-btn" style="width:100%; justify-content:center; background:linear-gradient(135deg, #10b981, #047857); box-shadow:0 8px 20px rgba(16,185,129,0.3); padding:14px;"><i class="fa-solid fa-save"></i> Save Question to Bank</button>
                                    </form>
                                </div>
                                
                                <!-- Rendered Questions List -->
                                <h4 style="color:var(--text-muted); font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:15px;"><i class="fa-solid fa-database"></i> Question Bank</h4>
                                <div id="questions-list-container"></div>
                            </div>

                            <!-- Results Tab -->
                            <div id="exam_tab_results" style="display:none;">
                                <div class="info-alert success" style="margin-top:0;"><i class="fa-solid fa-robot"></i> <strong>Auto-Grading Engine Active:</strong> The system automatically grades students the moment they submit their answers.</div>
                                <div id="results-table-container" style="background:var(--panel-bg); border-radius:12px; border:1px solid var(--border-color); overflow:hidden;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      <!-- ============================================== -->
        <!-- TAB 4: COMMUNICATIONS (CLEAN VERSION)          -->
        <!-- ============================================== -->
       <!-- ============================================== -->
        <!-- TAB 4: COMMUNICATIONS (CLEAN & COMPLETE)       -->
        <!-- ============================================== -->
        <div id="broadcast" class="section-tab">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin: 0;"><i class="fa-brands fa-telegram" style="color: #0ea5e9;"></i> Secure Communications</h3>
                <span style="font-size:12.5px; color:var(--text-muted); background:var(--input-bg); padding:8px 15px; border-radius:20px; border:1px solid var(--border-color); font-weight:600;"><i class="fa-solid fa-lock" style="color:var(--success);"></i> End-to-End Encrypted</span>
            </div>
            
            <div class="telegram-app">
                <div class="tg-sidebar">
                    <div class="tg-search-bar" style="padding: 15px 20px;">
                        <div class="input-with-icon" style="margin:0;">
                            <input type="text" id="tg-search" placeholder="Search users..." onkeyup="filterTelegramChats()" style="padding:12px 15px 12px 45px !important; border-radius:20px; font-size:13.5px; border-color:transparent; background:rgba(0,0,0,0.2); color:var(--text-main);">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                    </div>
                    <div class="tg-folders">
                        <div class="tg-folder active" onclick="switchFolder('all')">All Chats</div>
                        <div class="tg-folder" onclick="switchFolder('head')">My Head</div>
                        <div class="tg-folder" onclick="switchFolder('student')">My Students</div>
                    </div>
                    <div class="tg-contacts" id="tg-contacts-list">
                        
                        <!-- 📢 Broadcast Group -->
                        <div class="tg-contact-item chat-item-all chat-item-student" onclick="openTelegramChat(0, 'student', 1, '📢 All Dept Students', 'Broadcast to Students', '#f43f5e', '')">
                            <div class="tg-avatar group" style="background: linear-gradient(135deg, #f43f5e, #e11d48); box-shadow: 0 4px 10px rgba(244,63,94,0.3);"><i class="fa-solid fa-bullhorn"></i></div>
                            <div class="tg-info"><span class="tg-name">📢 All Students</span><span class="tg-role">Dept Students</span></div>
                        </div>

                        <!-- 👑 Head of Dept (Boss) & 🎓 Students -->
                        <?php
                        // Helper function for avatars
                        if (!function_exists('getAvatar')) {
                            function getAvatar($pic, $name, $bg, $color, $locked) {
                                if($locked == 1) return ['type'=>'locked', 'html'=>"<i class='fa-solid fa-user-lock' style='font-size:18px; color:#fff;'></i>", 'url'=>'LOCKED'];
                                $url = (!empty($pic) && file_exists("../uploads/".$pic)) ? "../uploads/".$pic : "https://ui-avatars.com/api/?name=".urlencode($name)."&background=$bg&color=$color&bold=true";
                                return ['type'=>'img', 'html'=>"<img src='$url' style='width:100%;height:100%;border-radius:50%;object-fit:cover;'>", 'url'=>$url];
                            }
                        }

                        // Fetch Head of Department
                        $h_q = mysqli_query($conn, "SELECT id, name, profile_pic, profile_locked FROM head WHERE dept_id=$dept_id AND is_deleted=0 LIMIT 1");
                        if($h = mysqli_fetch_assoc($h_q)){
                            $av = getAvatar($h['profile_pic'], $h['name'], '8b5cf6', 'fff', $h['profile_locked']);
                            $extra_badge = $av['type'] == 'img' ? "<i class='fa-solid fa-circle-check' style='position:absolute; bottom:-2px; right:-2px; color:#a78bfa; background:#fff; border-radius:50%; font-size:12px; z-index:5;'></i>" : "";
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-head' id='contact_head_{$h['id']}' onclick=\"openTelegramChat({$h['id']}, 'head', 0, '".addslashes($h['name'])."', 'Dept Head', '#8b5cf6', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#8b5cf6;' id='avatar_head_{$h['id']}'>{$av['html']}{$extra_badge}</div>
                                    <div class='tg-info'><span class='tg-name'>{$h['name']} <i class='fa-solid fa-circle-check' style='color:#a78bfa; font-size:12px;' title='Verified Head'></i></span><span class='tg-role'>Head of Dept</span></div>
                                    <span class='chat-unread-badge' id='badge_head_{$h['id']}'>0</span>
                                  </div>";
                        }
                        
                        // Fetch Students in Department
// Fetch Students in Department
                        $s_list = mysqli_query($conn, "SELECT id, first_name, last_name, profile_pic, profile_locked, is_rep FROM student WHERE dept_id=$dept_id AND status='accepted' AND is_deleted=0 ORDER BY is_rep DESC, first_name ASC");
                        while($s = mysqli_fetch_assoc($s_list)){
                            $full_name = $s['first_name'] . ' ' . $s['last_name'];
                            $av = getAvatar($s['profile_pic'], $full_name, 'f43f5e', 'fff', $s['profile_locked']);
                            
                            // 🪄 MAGIC: Rep Badge for Communications
                            $rep_badge = (isset($s['is_rep']) && $s['is_rep'] == 1) ? "<i class='fa-solid fa-crown' style='color:#f59e0b; margin-left:5px; font-size:12px; filter: drop-shadow(0 0 5px rgba(245, 158, 11, 0.5));' title='Class Representative'></i>" : "";
                            $role_display = (isset($s['is_rep']) && $s['is_rep'] == 1) ? "<span class='tg-role' style='color:#f59e0b; font-weight:bold;'>Class Rep</span>" : "<span class='tg-role'>Student</span>";

                            echo "<div class='tg-contact-item chat-item-all chat-item-student' id='contact_student_{$s['id']}' onclick=\"openTelegramChat({$s['id']}, 'student', 0, '".addslashes($full_name)."', 'Student', '#f43f5e', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#f43f5e;' id='avatar_student_{$s['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>{$full_name} {$rep_badge}</span>{$role_display}</div>
                                    <span class='chat-unread-badge' id='badge_student_{$s['id']}'>0</span>
                                  </div>";
                        }                        while($s = mysqli_fetch_assoc($s_list)){
                            $full_name = $s['first_name'] . ' ' . $s['last_name'];
                            $av = getAvatar($s['profile_pic'], $full_name, 'f43f5e', 'fff', $s['profile_locked']);
                            
                            echo "<div class='tg-contact-item chat-item-all chat-item-student' id='contact_student_{$s['id']}' onclick=\"openTelegramChat({$s['id']}, 'student', 0, '".addslashes($full_name)."', 'Student', '#f43f5e', '{$av['url']}')\">
                                    <div class='tg-avatar' style='background:#f43f5e;' id='avatar_student_{$s['id']}'>{$av['html']}</div>
                                    <div class='tg-info'><span class='tg-name'>{$full_name}</span><span class='tg-role'>Student</span></div>
                                    <span class='chat-unread-badge' id='badge_student_{$s['id']}'>0</span>
                                  </div>";
                        }
                        ?>
                    </div>
                </div>
                
                <div class="tg-chat-area">
                    <!-- Placeholder (Empty State) -->
                    <div id="tg-placeholder" class="tg-placeholder">
                        <div style="width:120px; height:120px; background:var(--input-bg); border-radius:50%; display:flex; justify-content:center; align-items:center; margin-bottom:20px; border:2px dashed var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
                            <i class="fa-regular fa-comments" style="font-size: 50px; color:var(--text-muted); margin:0;"></i>
                        </div>
                        <h3 style="color:var(--text-main); font-size:22px; font-weight:800; margin-bottom:5px;">Faculty Communication</h3>
                        <p style="font-size:14px; color:var(--text-muted);">Select a chat from the sidebar to connect with your students or Head of Department.</p>
                    </div>
                    
                    <!-- Chat Active Area -->
                    <div id="tg-active-chat" style="display:none; flex-direction:column; height:100%;">
                        <div class="tg-chat-header">
                            <div class="tg-avatar group" id="chat-header-avatar" style="background:var(--primary); box-shadow: 0 4px 10px rgba(0,0,0,0.1);"></div>
                            <div>
                                <div class="tg-chat-title" id="chat-header-name">Chat Name</div>
                                <div class="tg-chat-status" id="chat-header-role">Online</div>
                            </div>
                            <div style="margin-left:auto; display:flex; gap:10px;">
                                <button type="button" class="btn btn-sm" style="background:var(--input-bg); color:var(--text-muted); border:1px solid var(--border-color);"><i class="fa-solid fa-magnifying-glass"></i></button>
                                <button type="button" class="btn btn-sm" style="background:var(--input-bg); color:var(--text-muted); border:1px solid var(--border-color);"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                            </div>
                        </div>
                        
                        <div class="tg-chat-history" id="chat-history-container">
                            <!-- Messages will load here via AJAX -->
                        </div>
                        
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
        <!-- TAB: SECURITY COMMAND CENTER                   -->
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
            $my_logs_q = mysqli_query($conn, "SELECT status, COUNT(*) as c FROM login_logs WHERE username='$teacher_name' GROUP BY status");
            $success_cnt = 0; $fail_cnt = 0;
            while($st = mysqli_fetch_assoc($my_logs_q)) {
                if($st['status'] == 'success') $success_cnt = $st['c'];
                if($st['status'] == 'failed') $fail_cnt = $st['c'];
            }
            $last_ip_q = mysqli_query($conn, "SELECT ip_address FROM login_logs WHERE username='$teacher_name' AND status='success' ORDER BY attempt_time DESC LIMIT 1");
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
                
                <div style="overflow-x:auto; max-height: 500px; overflow-y: auto;">
                    <table style="width:100%; border-collapse: collapse;">
                        <tr style="background: rgba(0,0,0,0.2); position:sticky; top:0; z-index:2; text-align: left;">
                            <th style="padding:15px 30px; border-radius:0;">Time & Date</th>
                            <th style="padding:15px;">IP Address</th>
                            <th style="padding:15px;">Device / Browser Agent</th>
                            <th style="text-align:right; padding:15px 30px;">Status</th>
                        </tr>
                        <?php
                        $logs = mysqli_query($conn, "SELECT * FROM login_logs WHERE username='$teacher_name' ORDER BY attempt_time DESC LIMIT 30");
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
            <div class="help-hero" style="background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); border-radius: 20px; padding: 50px 30px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); margin-bottom: 30px; border-bottom: 5px solid #10b981;">
                <div style="position: absolute; top: -50px; left: -50px; width: 250px; height: 250px; background: radial-gradient(circle, rgba(16,185,129,0.15) 0%, transparent 70%); border-radius: 50%; filter: blur(30px);"></div>
                <div class="icon-box" style="width: 80px; height: 80px; font-size: 35px; margin: 0 auto 20px auto; background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4); border-radius: 20px; position:relative; z-index:2;">
                    <i class="fa-solid fa-book-open-reader"></i>
                </div>
                <h2 style="font-size: 34px; color: #ffffff; font-weight: 800; margin-bottom: 15px; position:relative; z-index:2;">EPLMS Faculty Knowledge Base</h2>
                <p style="font-size: 15px; color: #cbd5e1; max-width: 750px; margin: 0 auto 30px; line-height: 1.8; position:relative; z-index:2;">
                    Welcome to your comprehensive teaching guide. Explore the sections below to master how to manage materials, conduct secure exams, grade student submissions, and utilize the master gradebook.
                </p>
                <div class="input-with-icon" style="max-width: 600px; margin: 0 auto; position:relative; z-index:2;">
                    <input type="text" id="help-search-input" placeholder="Search guides, exams, grading, materials..." onkeyup="searchHelpTopics()" style="padding: 16px 20px 16px 50px !important; border-radius: 30px; border: 2px solid #10b981; background: var(--panel-bg); color: var(--text-main); font-size: 15px; box-shadow: 0 8px 25px rgba(0,0,0,0.2);">
                    <i class="fa-solid fa-magnifying-glass" style="color: #10b981; font-size: 18px; left: 22px;"></i>
                </div>
            </div>

            <div id="help-content-wrapper">
                
                <!-- TOPIC 1: MATERIALS & ASSIGNMENTS -->
                <div class="premium-panel" style="border-top-color: #3b82f6; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-folder-tree"></i></div> 1. Course Materials & Assignments</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            1.1 Uploading & Scheduling Auto-Release <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Under the <strong>Manage Materials</strong> tab, you can upload PDFs, PPTs, or share Video URLs. </p>
                            <div class="info-alert success" style="margin-top:10px; border-left-color:#10b981; background:rgba(16,185,129,0.05); padding:10px; border-radius:8px;">
                                <strong><i class="fa-solid fa-clock"></i> Magic Auto-Release:</strong> If you set an "Auto-Release Date", the material will remain locked for students until that exact date and time. It automatically becomes visible without you doing anything!
                            </div>
                        </div>
                    </div>

                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            1.2 Setting Assignment Deadlines <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>When you select "Assignment" or "Project", the system automatically suggests a Due Date (1 week from today). You can change this using the calendar icon. Once the deadline passes, students can still submit, but the system will flag them as <strong style="color:var(--danger);">"Submitted Late"</strong> in your Submissions tab.</p>
                        </div>
                    </div>
                </div>

                <!-- TOPIC 2: EXAMS & QUIZZES -->
                <div class="premium-panel" style="border-top-color: #f43f5e; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f43f5e, #be123c);"><i class="fa-solid fa-stopwatch"></i></div> 2. Exams, Quizzes & Auto-Grading</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            2.1 Creating Secure Exams & Access Codes <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Go to the <strong>Exams & Quizzes</strong> tab to schedule a test. You MUST provide a <strong>Secret Access Code</strong>. Students cannot start the exam without this code, meaning you can write it on the whiteboard only when the class is seated and ready.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            2.2 Adding Questions & Live Results <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Click on any created exam to open the <strong>Exam Manager</strong> on the right. Here you can:</p>
                            <ul>
                                <li>Add Multiple Choice, Fill-in-the-blanks, or Essay questions.</li>
                                <li>Use the <strong>Bulk Upload (CSV)</strong> button to upload 50+ questions at once using an Excel template.</li>
                                <li>Click the <strong>Live Results</strong> inner-tab to watch students' scores calculate automatically the moment they click "Finish Exam".</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- TOPIC 3: GRADING & SUBMISSIONS -->
                <div class="premium-panel" style="border-top-color: #f59e0b; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #f59e0b, #b45309);"><i class="fa-solid fa-star-half-stroke"></i></div> 3. Submissions & Master Gradebook</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            3.1 Managing Student Submissions <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Under the <strong>Submissions</strong> tab, select an assignment. The system provides a live dashboard showing Completion Rates. You can instantly download the submitted files (PDF/PPT) of each student.</p>
                        </div>
                    </div>

                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            3.2 Using the Master Gradebook <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>The <strong>Gradebook Center</strong> is where final evaluations happen.</p>
                            <ul>
                                <li>Enter marks for Attendance, Assignments, Quizzes, Mid, and Final Exams.</li>
                                <li>The system <strong>automatically calculates the Total (100%) and the Letter Grade (A, B, C...)</strong> instantly as you type.</li>
                                <li>Click "Save as Draft" if you are still editing.</li>
                                <li><strong>Finalize & Publish:</strong> Once you click this, grades become Read-Only. The Head of Department and the Students will immediately see their official results!</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- TOPIC 4: COMMUNICATIONS -->
                <div class="premium-panel" style="border-top-color: #0ea5e9; padding: 30px; margin-bottom:20px;">
                    <h3 class="panel-title-premium" style="margin-bottom: 20px;"><div class="icon-box" style="width:35px; height:35px; font-size:16px; margin:0; background:linear-gradient(135deg, #0ea5e9, #0369a1);"><i class="fa-brands fa-telegram"></i></div> 4. Communications & Security</h3>
                    
                    <div class="help-accordion-item" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                        <button class="help-acc-btn" onclick="toggleHelpAcc(this)" style="width:100%; text-align:left; background:transparent; border:none; padding:18px 20px; font-size:15px; font-weight:700; color:var(--text-main); cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                            4.1 End-to-End Encrypted Chat <i class="fa-solid fa-chevron-down" style="color:var(--text-muted); transition:0.3s;"></i>
                        </button>
                        <div class="help-acc-content" style="padding: 0 20px 20px 20px; color:var(--text-muted); font-size:14px; line-height:1.7; display:none; border-top:1px dashed var(--border-color); margin-top:5px; padding-top:15px;">
                            <p>Use the <strong>Communications</strong> tab to chat with your Head of Department or specific students privately. You can also use the <strong>📢 All Students</strong> broadcast to send class announcements to everyone at once.</p>
                        </div>
                    </div>
                </div>

            </div>
            
            <div style="text-align: center; margin-top: 20px; color: var(--text-muted); font-size: 13px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
                <p>EPLMS Faculty Documentation v2.5 <br> Empowering education through seamless technology.</p>
            </div>
        </div>
       <!-- ============================================== -->
        <!-- 8. SETTINGS & PROFILE (ULTIMATE PREMIUM UI)    -->
        <!-- ============================================== -->
        <div id="settings" class="section-tab">
            
            <!-- 🌟 Premium Profile Header 🌟 -->
            <div class="profile-header-card" style="background: linear-gradient(135deg, #064e3b 0%, #047857 100%); border-radius: 24px; padding: 40px 20px; text-align: center; position: relative; margin-bottom: 35px; border-bottom: 5px solid #10b981; box-shadow: 0 15px 35px rgba(16, 185, 129, 0.2); overflow: hidden;">
                <!-- Decorative Background Circles -->
                <div style="position: absolute; top: -50px; left: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); border-radius: 50%;"></div>
                <div style="position: absolute; bottom: -50px; right: -50px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(16,185,129,0.2) 0%, transparent 70%); border-radius: 50%;"></div>
                
                <div class="profile-avatar-wrapper" style="width: 130px; height: 130px; margin: 0 auto 20px auto; position: relative; z-index: 2;">
                    <img src="<?php echo $profile_pic; ?>" alt="Profile" class="profile-avatar-large" id="preview_avatar_top" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 4px solid #a7f3d0; box-shadow: 0 10px 25px rgba(0,0,0,0.4);">
                    <label for="pic_upload" class="edit-avatar-btn" style="position: absolute; bottom: 5px; right: -5px; background: #fcd535; color: #000; width: 38px; height: 38px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 3px solid #047857; transition: 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.3);" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"><i class="fa-solid fa-camera"></i></label>
                </div>
                
                <h2 class="profile-name" style="color: #ffffff; font-size: 28px; font-weight: 800; margin-bottom: 8px; position: relative; z-index: 2; letter-spacing: 0.5px;">
                    Tr. <?php echo htmlspecialchars($teacher_info['name']); ?> 
                    <i class="fa-solid fa-circle-check" style="color:#a7f3d0; font-size:22px; text-shadow: 0 0 15px rgba(167, 243, 208, 0.6);" title="Verified Faculty"></i>
                </h2>
                <p class="profile-email" style="color: #a7f3d0; font-size: 15px; margin-bottom: 20px; font-weight: 500; position: relative; z-index: 2;"><i class="fa-solid fa-envelope" style="margin-right: 5px;"></i> <?php echo htmlspecialchars($teacher_info['email']); ?></p>
                
                <div class="profile-badges" style="position: relative; z-index: 2; display: flex; justify-content: center; gap: 10px; flex-wrap: wrap;">
                    <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #fff; border: 1px solid rgba(16, 185, 129, 0.4); padding: 8px 15px; font-size: 12px;"><i class="fa-solid fa-chalkboard-user" style="color: #a7f3d0;"></i> Faculty Member</span>
                    <span class="badge" style="background: rgba(59, 130, 246, 0.2); color: #fff; border: 1px solid rgba(59, 130, 246, 0.4); padding: 8px 15px; font-size: 12px;"><i class="fa-solid fa-sitemap" style="color: #93c5fd;"></i> <?php echo htmlspecialchars($teacher_info['dept_name']); ?></span>
                </div>
            </div>

            <!-- 📝 Main Settings Form 📝 -->
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="profile_pic" id="pic_upload" style="display:none;" onchange="previewImage(this)">
                
                <div class="settings-grid">
                    
                    <!-- LEFT PANEL: PRIVATE IDENTITY -->
                    <div class="premium-panel" style="border-top-color: #10b981; margin-bottom:0;">
                        <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, #10b981, #047857);"><i class="fa-solid fa-user"></i></div> Personal Identity</h3>
                        
                        <div class="info-alert" style="background: rgba(16, 185, 129, 0.05); border-left: 4px solid #10b981;">
                            <strong style="color: #10b981;"><i class="fa-solid fa-lock"></i> Private Email Role</strong>
                            If 2FA is enabled, your Head of Department will send your secure OTP login codes to this address.
                        </div>

                        <div class="form-group">
                            <label>Full Name</label>
                            <div class="input-with-icon"><input type="text" name="t_name" value="<?php echo htmlspecialchars($teacher_info['name']); ?>" required><i class="fa-solid fa-user-tie"></i></div>
                        </div>
                        <div class="form-group">
                            <label>Username</label>
                            <div class="input-with-icon"><input type="text" name="t_username" value="<?php echo htmlspecialchars($teacher_info['username']); ?>" required><i class="fa-solid fa-at"></i></div>
                        </div>
                        <div class="form-group">
                            <label style="color: #10b981; font-weight: 800;">Private Email (Receives 2FA OTPs)</label>
                            <div class="input-with-icon"><input type="email" name="t_email" value="<?php echo htmlspecialchars($teacher_info['email']); ?>" required style="border-color: rgba(16, 185, 129, 0.5); background: rgba(16, 185, 129, 0.02);"><i class="fa-solid fa-envelope-circle-check" style="color: #10b981;"></i></div>
                        </div>
                        <div class="form-group">
                            <label>Phone Number (Optional)</label>
                            <div class="input-with-icon"><input type="text" name="t_phone" value="<?php echo htmlspecialchars($teacher_info['phone'] ?? ''); ?>" placeholder="+251..."><i class="fa-solid fa-phone"></i></div>
                        </div>
                    </div>

                    <!-- RIGHT PANEL: SECURITY POLICIES -->
                    <div class="premium-panel" style="border-top-color: #3b82f6; margin-bottom:0;">
                        <h3 class="panel-title-premium"><div class="icon-box" style="width:38px; height:38px; font-size:16px; margin:0; background:linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="fa-solid fa-shield-virus"></i></div> Security Policies</h3>
                        
                        <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 25px; line-height: 1.6;">Configure how your account authenticates logins and how your profile appears to students.</p>

                        <!-- 🪄 FIXED: Added isset() checks to prevent PHP warnings! -->
                        
                        <!-- 2FA Toggle -->
                        <div class="sec-toggle" style="background: rgba(16, 185, 129, 0.03); border: 1px solid rgba(16, 185, 129, 0.2);">
                            <div class="sec-toggle-info">
                                <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-mobile-screen-button" style="color:#10b981; margin-right:8px;"></i> Two-Factor Auth (2FA)</h4>
                                <p style="color: var(--text-muted); font-size: 12.5px;">Require OTP code during login.</p>
                            </div>
                            <label class="switch"><input type="checkbox" name="two_factor" <?php echo (!empty($teacher_info['two_factor_enabled'])) ? 'checked' : ''; ?>><span class="slider" style="background-color: #10b981;"></span></label>
                        </div>
                        
                        <!-- Login Alerts Toggle -->
                        <div class="sec-toggle">
                            <div class="sec-toggle-info">
                                <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-bell" style="color:#3b82f6; margin-right:8px;"></i> Login Alerts</h4>
                                <p style="color: var(--text-muted); font-size: 12.5px;">Notify via email on new login attempts.</p>
                            </div>
                            <label class="switch"><input type="checkbox" name="login_alerts" <?php echo (!isset($teacher_info['login_alerts']) || $teacher_info['login_alerts'] == 1) ? 'checked' : ''; ?>><span class="slider"></span></label>
                        </div>

                        <!-- Profile Privacy Toggle -->
                        <div class="sec-toggle" style="border-left: 4px solid var(--danger);">
                            <div class="sec-toggle-info">
                                <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 4px;"><i class="fa-solid fa-user-lock" style="color:var(--danger); margin-right:8px;"></i> Profile Privacy Lock</h4>
                                <p style="color: var(--text-muted); font-size: 12.5px;">Hide your avatar from students in chat.</p>
                            </div>
                            <label class="switch"><input type="checkbox" name="profile_locked" <?php echo !empty($teacher_info['profile_locked']) ? 'checked' : ''; ?>><span class="slider"></span></label>
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
                            
                            <!-- Updated Rules with Special Characters -->
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

<!-- CUSTOM RIGHT CLICK MENU (CHAT) -->
<div id="chat-context-menu" class="chat-context-menu"><div class="context-item" id="ctx-edit"><i class="fa-solid fa-pen"></i> Edit Message</div><div class="context-item delete" id="ctx-delete"><i class="fa-solid fa-trash"></i> Delete Message</div></div>
<!-- LIVE TIME EXTENSION MODAL -->
<div id="addTimeModal" class="modal-overlay">
    <div class="modal-box" style="border-top: 4px solid #f59e0b; max-width:400px;">
        <h3 style="color:#f59e0b; margin-bottom: 10px;"><i class="fa-solid fa-stopwatch"></i> Extend Exam Time</h3>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Add more minutes to <strong id="ext_exam_title" style="color:var(--text-main);"></strong> dynamically.</p>
        <form method="POST">
            <input type="hidden" name="exam_id" id="ext_exam_id">
            <div class="form-group"><label>Extra Minutes to Add</label>
                <div class="input-with-icon"><input type="number" name="extra_mins" value="10" min="1" required><i class="fa-solid fa-plus" style="color:#f59e0b;"></i></div>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('addTimeModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="extend_time" class="btn btn-warning" style="flex:1; color:#fff;">Update Time</button>
            </div>
        </form>
    </div>
</div>

<!-- LIVE QUESTION EDIT MODAL -->
<div id="editQuestionModal" class="modal-overlay">
    <div class="modal-box" style="border-top: 4px solid #3b82f6; max-width:600px; text-align:left;">
        <h3 style="color:#3b82f6; margin-bottom: 15px; text-align:center;"><i class="fa-solid fa-pen-to-square"></i> Live Edit Question</h3>
        <form method="POST">
            <input type="hidden" name="edit_q_id" id="edit_q_id">
            <div class="form-group"><label>Question Text</label>
                <textarea name="q_text" id="edit_q_text" required style="width:100%; padding:10px; background:var(--input-bg); border-radius:8px; border:1px solid var(--border-color); color:var(--text-main); resize:vertical; min-height:80px;"></textarea>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:15px;">
                <div class="input-with-icon"><input type="text" name="opt_a" id="edit_opt_a" required><i class="fa-solid fa-a"></i></div>
                <div class="input-with-icon"><input type="text" name="opt_b" id="edit_opt_b" required><i class="fa-solid fa-b"></i></div>
                <div class="input-with-icon"><input type="text" name="opt_c" id="edit_opt_c" required><i class="fa-solid fa-c"></i></div>
                <div class="input-with-icon"><input type="text" name="opt_d" id="edit_opt_d" required><i class="fa-solid fa-d"></i></div>
                <div class="input-with-icon"><input type="text" name="opt_e" id="edit_opt_e" placeholder="Opt E"><i class="fa-solid fa-e"></i></div>
                <div class="input-with-icon"><input type="text" name="opt_f" id="edit_opt_f" placeholder="Opt F"><i class="fa-solid fa-f"></i></div>
            </div>
            <div class="form-group"><label style="color:#10b981;">Correct Answer</label>
                <select name="correct_opt" id="edit_correct_opt" style="width:100%; padding:10px; background:var(--bg-color); color:var(--text-main); border:1px solid var(--border-color); border-radius:8px;">
                    <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option><option value="E">E</option><option value="F">F</option>
                </select>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('editQuestionModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="edit_question_live" class="btn" style="flex:1; background:#3b82f6; color:#fff;">Update Live</button>
            </div>
        </form>
    </div>
</div>

<!-- SMART AI PDF SCANNER MODAL -->
<div id="smartAiModal" class="modal-overlay">
    <div class="modal-box" style="border-top: 4px solid #8b5cf6; max-width:500px; overflow:hidden; position:relative;">
        <!-- Scanning Animation Overlay -->
        <div id="ai_scanning_overlay" style="display:none; position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(20,22,28,0.9); z-index:10; flex-direction:column; justify-content:center; align-items:center;">
            <div style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid rgba(139,92,246,0.2); border-top-color: #8b5cf6; animation: fa-spin 1s infinite linear; margin-bottom: 20px;"></div>
            <h3 style="color:#a78bfa; margin-bottom:10px; font-weight:800;" id="ai_scan_text">Extracting Text from PDF...</h3>
            <div style="width:70%; background:rgba(255,255,255,0.1); height:6px; border-radius:3px; overflow:hidden;"><div id="ai_progress" style="width:0%; height:100%; background:#8b5cf6; transition:width 0.5s;"></div></div>
        </div>

        <h3 style="color:#8b5cf6; margin-bottom: 10px;"><i class="fa-solid fa-wand-magic-sparkles"></i> Magic AI Document Scanner</h3>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Upload your course handout (PDF/Word). The AI will automatically read it and generate multiple-choice questions for this exam.</p>
        
        <div style="border: 2px dashed #8b5cf6; background: rgba(139,92,246,0.05); padding: 40px 20px; border-radius: 16px; margin-bottom: 20px; cursor: pointer; transition: 0.3s;" onmouseover="this.style.background='rgba(139,92,246,0.1)'" onmouseout="this.style.background='rgba(139,92,246,0.05)'" onclick="document.getElementById('ai_file_input').click()">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size: 40px; color: #8b5cf6; margin-bottom: 15px;"></i>
            <h4 style="color:var(--text-main); font-size:16px;">Click to Browse Document</h4>
            <span style="font-size:11px; color:var(--text-muted);">Supports .pdf, .docx, .txt (Max 5MB)</span>
            <input type="file" id="ai_file_input" style="display:none;" accept=".pdf,.docx,.txt" onchange="simulateAiScan()">
        </div>
        <button type="button" class="btn" style="width:100%; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('smartAiModal').classList.remove('active')">Cancel</button>
    </div>
</div>
<!-- BULK UPLOAD MODAL -->
<div id="bulkUploadModal" class="modal-overlay">
    <div class="modal-box" style="border-top: 4px solid #10b981; max-width:500px;">
        <h3 style="color:#10b981; margin-bottom: 15px;"><i class="fa-solid fa-file-csv"></i> Bulk Upload Questions</h3>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Upload a CSV file containing multiple questions.</p>
        
        <div style="background:rgba(16,185,129,0.05); padding:15px; border-radius:8px; border:1px dashed #10b981; margin-bottom:20px; text-align:left; font-size:11px; color:var(--text-muted); line-height: 1.6;">
            <strong>CSV Format required (8 columns, No Commas in Text):</strong><br>
            1. Type (<i>multiple_choice, fill_blank, essay</i>)<br>
            2. Question Text<br>
            3. Option A<br>
            4. Option B<br>
            5. Option C<br>
            6. Option D<br>
            7. Correct Option (<i>A, B, C, D</i>)<br>
            8. Correct Text (<i>For Fill blank/Essay</i>)
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="bulk_exam_id" id="bulk_exam_id">
            <div class="form-group" style="margin-bottom: 20px;">
                <input type="file" name="csv_file" accept=".csv" required style="width:100%; padding:10px; background:var(--input-bg); border-radius:8px; border:1px solid var(--border-color); color:var(--text-main);">
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('bulkUploadModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="bulk_upload" class="btn btn-success" style="flex:1;"><i class="fa-solid fa-upload"></i> Upload</button>
            </div>
        </form>
    </div>
</div>
<!-- GRADING CONFIGURATION MODAL -->
<div id="configGradeModal" class="modal-overlay">
    <div class="modal-box" style="border-top: 4px solid #f59e0b; max-width:500px;">
        <h3 style="color:#f59e0b; margin-bottom: 10px;"><i class="fa-solid fa-sliders"></i> Configure Grading Criteria</h3>
        <p style="font-size:12px; color:var(--text-muted); margin-bottom:20px;">Set the maximum weight (%) for each assessment for <strong id="cfg_course_name" style="color:var(--text-main);"></strong>. <br>To remove a column completely, set its weight to <strong>0</strong>.</p>
        
        <form method="POST">
            <input type="hidden" name="setting_course_id" id="cfg_course_id">
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; text-align:left; margin-bottom:20px;">
                <div class="form-group" style="margin:0;"><label>Attendance (%)</label><div class="input-with-icon"><input type="number" name="w_att" id="cfg_w_att" required min="0" max="100" style="padding-left:40px !important;"><i class="fa-solid fa-clipboard-user" style="color:var(--text-muted);"></i></div></div>
                <div class="form-group" style="margin:0;"><label>Assignment (%)</label><div class="input-with-icon"><input type="number" name="w_ass" id="cfg_w_ass" required min="0" max="100" style="padding-left:40px !important;"><i class="fa-solid fa-file-pen" style="color:var(--text-muted);"></i></div></div>
                <div class="form-group" style="margin:0;"><label>Project (%)</label><div class="input-with-icon"><input type="number" name="w_proj" id="cfg_w_proj" required min="0" max="100" style="padding-left:40px !important;"><i class="fa-solid fa-rocket" style="color:var(--text-muted);"></i></div></div>
                <div class="form-group" style="margin:0;"><label>Quiz (%)</label><div class="input-with-icon"><input type="number" name="w_quiz" id="cfg_w_quiz" required min="0" max="100" style="padding-left:40px !important;"><i class="fa-solid fa-stopwatch" style="color:var(--text-muted);"></i></div></div>
                <div class="form-group" style="margin:0;"><label>Mid Exam (%)</label><div class="input-with-icon"><input type="number" name="w_mid" id="cfg_w_mid" required min="0" max="100" style="padding-left:40px !important;"><i class="fa-solid fa-file-lines" style="color:var(--text-muted);"></i></div></div>
                <div class="form-group" style="margin:0;"><label>Final Exam (%)</label><div class="input-with-icon"><input type="number" name="w_fin" id="cfg_w_fin" required min="0" max="100" style="padding-left:40px !important;"><i class="fa-solid fa-graduation-cap" style="color:var(--text-muted);"></i></div></div>
            </div>

            <div style="background:rgba(244,63,94,0.05); padding:10px; border-radius:8px; border:1px dashed #f43f5e; color:#f43f5e; font-size:12px; margin-bottom:20px; font-weight:bold;">
                <i class="fa-solid fa-triangle-exclamation"></i> Total weight MUST equal exactly 100%!
            </div>

            <div style="display:flex; gap:10px;">
                <button type="button" class="btn" style="flex:1; background:var(--border-color); color:var(--text-main);" onclick="document.getElementById('configGradeModal').classList.remove('active')">Cancel</button>
                <button type="submit" name="save_grade_settings" class="btn btn-warning" style="flex:1; color:#fff;"><i class="fa-solid fa-save"></i> Save Criteria</button>
            </div>
        </form>
    </div>
</div>
<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // ==========================================
    // 1. THEME & GLOBAL UTILITIES
    // ==========================================
    const themeIcon = document.getElementById('theme-icon');
    function toggleTheme() { document.body.classList.toggle('light-mode'); const isLight = document.body.classList.contains('light-mode'); localStorage.setItem('eplms_theme', isLight ? 'light' : 'dark'); if(themeIcon) themeIcon.className = isLight ? 'fa-solid fa-sun' : 'fa-solid fa-moon'; }
    if(localStorage.getItem('eplms_theme') === 'light'){ document.body.classList.add('light-mode'); if(themeIcon) themeIcon.className = 'fa-solid fa-sun'; }
    
    function animateCounters() { document.querySelectorAll('.counter').forEach(counter => { counter.innerText = '0'; const target = +counter.getAttribute('data-target'); const inc = target / 30; const update = () => { const c = +counter.innerText; if(c < target) { counter.innerText = Math.ceil(c + inc); setTimeout(update, 30); } else { counter.innerText = target; } }; update(); }); }
    
    function updateClock() { const now = new Date(); let h = now.getHours(); let m = now.getMinutes(); let s = now.getSeconds(); document.getElementById('real-time-clock').innerText = `${h%12||12}:${m<10?'0'+m:m}:${s<10?'0'+s:s} ${h>=12?'PM':'AM'}`; } 
    setInterval(updateClock, 1000); updateClock();

    function previewImage(input) { if(input.files && input.files[0]){ let reader = new FileReader(); reader.onload = function(e){ document.getElementById('preview_avatar_top').src = e.target.result; }; reader.readAsDataURL(input.files[0]); } }
    function togglePw(id, icon) { let input = document.getElementById(id); if (input.type === "password") { input.type = "text"; icon.classList.remove("fa-eye"); icon.classList.add("fa-eye-slash"); } else { input.type = "password"; icon.classList.remove("fa-eye-slash"); icon.classList.add("fa-eye"); } }
    function checkPasswordStrength() { let pw = document.getElementById('new_pass').value; let rulesBox = document.getElementById('pw-rules'); if (pw.length > 0) rulesBox.style.display = 'block'; else rulesBox.style.display = 'none'; updateRule('rule-length', pw.length >= 8); let hasUpper = /[A-Z]/.test(pw); let hasLower = /[a-z]/.test(pw); updateRule('rule-upper', hasUpper && hasLower); updateRule('rule-number', /[0-9]/.test(pw)); updateRule('rule-special', /[!@#$%^&*(),.?":{}|<>]/.test(pw)); }
    function updateRule(id, isValid) { let el = document.getElementById(id); if(!el) return; let icon = el.querySelector('i'); if(isValid) { el.style.color = '#10b981'; icon.className = 'fa-solid fa-circle-check'; icon.style.color = '#10b981'; } else { el.style.color = '#f43f5e'; icon.className = 'fa-solid fa-circle-xmark'; icon.style.color = '#f43f5e'; } }
    function switchInnerTab(tabName, btnElement) { document.querySelectorAll('.inner-tab-btn').forEach(btn => btn.classList.remove('active')); document.querySelectorAll('.inner-tab-content').forEach(content => content.classList.remove('active')); btnElement.classList.add('active'); document.getElementById('inner-' + tabName).classList.add('active'); }
    setTimeout(() => { let alert = document.querySelector('.alert'); if(alert) alert.style.display = 'none'; }, 4000);

    // ==========================================
    // 2. TAB MANAGEMENT & CHARTS
    // ==========================================
    function initCharts() {
        const ctxMat = document.getElementById('materialsChart');
        if(ctxMat) {
            if(window.matChart) { window.matChart.destroy(); }
            window.matChart = new Chart(ctxMat.getContext('2d'), {
                type: 'doughnut',
                data: { 
                    labels: ['Assigned Courses', 'Dept Students', 'PDF Materials', 'Assignments & Projects'], 
                    datasets: [{ 
                        data: [
                            <?php echo isset($my_courses) ? $my_courses : 0; ?>, 
                            <?php echo isset($my_students) ? $my_students : 0; ?>, 
                            <?php echo isset($my_pdfs) ? $my_pdfs : 0; ?>, 
                            <?php echo isset($my_assignments) ? $my_assignments : 0; ?>
                        ], 
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'], 
                        borderWidth: 0, 
                        hoverOffset: 15,
                        borderRadius: 5
                    }] 
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'right', labels: { color: '#94a3b8', padding: 20, font: { family: 'Inter', size: 12, weight: '600' }, usePointStyle: true, pointStyle: 'circle' } }, tooltip: { backgroundColor: '#14161c', titleFont: { size: 14, weight: 'bold' }, bodyFont: { size: 13 }, padding: 12, cornerRadius: 10, displayColors: true } }, animation: { animateScale: true, animateRotate: true } }
            });
        }
    }

    function openTab(tabId) { 
        document.querySelectorAll('.section-tab').forEach(el=>el.classList.remove('active')); 
        document.querySelectorAll('.tab-link').forEach(el=>el.classList.remove('active')); 
        document.getElementById(tabId).classList.add('active'); 
        
        let targetBtn = document.querySelector(`.tab-link[onclick="openTab('${tabId}')"]`);
        if(targetBtn) targetBtn.classList.add('active');
        
        if(tabId === 'home') { animateCounters(); if(window.matChart) window.matChart.update(); }
        
        if(tabId === 'submissions') {
            let subBadge = document.getElementById('badge_submissions');
            if(subBadge && subBadge.style.display !== 'none') {
                subBadge.style.display = 'none';
                let fd = new FormData(); fd.append('ajax_action', 'mark_submissions_read');
                fetch(window.location.href, {method:'POST', body:fd});
            }
        }
    }

    // ==========================================
    // 3. MATERIALS & COURSE FILTER
    // ==========================================
    function toggleMatFields() {
        let type = document.getElementById('mat_type_selector').value;
        let fieldDue = document.getElementById('field_due_date');
        let fieldCustom = document.getElementById('field_custom_type');
        let fieldFile = document.getElementById('field_file');
        let fieldUrl = document.getElementById('field_url');
        let fieldPoints = document.getElementById('field_points');
        let pointsInput = document.getElementById('max_points_input');
        
        if(type === 'other') { fieldCustom.style.display = 'block'; fieldCustom.querySelector('input').setAttribute('required', 'required'); } 
        else { fieldCustom.style.display = 'none'; fieldCustom.querySelector('input').removeAttribute('required'); }

        if(type === 'media' || type === 'video') { fieldFile.style.display = 'none'; fieldUrl.style.display = 'block'; fieldFile.querySelector('input').removeAttribute('required'); } 
        else { fieldFile.style.display = 'block'; fieldUrl.style.display = 'none'; }

        let releaseDateInput = document.querySelector('input[name="release_date"]');
        if(releaseDateInput && !releaseDateInput.value) {
            let now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            releaseDateInput.value = now.toISOString().slice(0, 16);
        }

        if(type === 'assignment' || type === 'project') {
            fieldDue.style.display = 'block'; fieldPoints.style.display = 'block'; 
            if(pointsInput) pointsInput.setAttribute('required', 'required'); 
            let dueDateInput = fieldDue.querySelector('input');
            if(dueDateInput && !dueDateInput.value) {
                let d = new Date(); d.setDate(d.getDate() + 7); d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
                dueDateInput.value = d.toISOString().slice(0, 16);
            }
        } else {
            fieldDue.style.display = 'none'; 
            let dueDateInput = fieldDue.querySelector('input');
            if(dueDateInput) dueDateInput.value = ''; 
            fieldPoints.style.display = 'none'; 
            if(pointsInput) { pointsInput.removeAttribute('required'); pointsInput.value = ''; }
        }
    }

    function switchMaterialView(viewType) {
        const gridView = document.getElementById('material-grid-view');
        const listView = document.getElementById('material-list-view');
        const btnGrid = document.getElementById('btn-grid-view');
        const btnList = document.getElementById('btn-list-view');

        if(viewType === 'grid') { gridView.style.display = 'grid'; listView.style.display = 'none'; btnGrid.classList.add('active'); btnList.classList.remove('active'); } 
        else { gridView.style.display = 'none'; listView.style.display = 'block'; btnList.classList.add('active'); btnGrid.classList.remove('active'); }
    }

    function filterMaterialsByCourse() {
        let val = document.getElementById('course_filter').value;
        document.querySelectorAll('.course-category-wrapper').forEach(category => {
            category.style.display = (val === 'all' || category.getAttribute('data-course-id') === val) ? 'block' : 'none';
        });
        document.querySelectorAll('.course-accordion').forEach(acc => {
            acc.style.display = (val === 'all' || acc.getAttribute('data-course-id') === val) ? 'block' : 'none';
        });
    }

    function toggleAccordion(btn) {
        const accordion = btn.parentElement; accordion.classList.toggle('active');
        const content = accordion.querySelector('.acc-content');
        content.style.display = accordion.classList.contains('active') ? 'block' : 'none';
    }

    // ==========================================
    // 4. SUBMISSIONS & STUDENTS
    // ==========================================
    function showSubmissions(materialId) {
        document.getElementById('sub-placeholder').style.display = 'none';
        document.querySelectorAll('.submission-view').forEach(view => { view.style.display = 'none'; });
        
        let targetView = document.getElementById('sub-view-' + materialId);
        if(targetView) {
            targetView.style.display = 'flex'; targetView.style.opacity = '0';
            setTimeout(() => { targetView.style.transition = 'opacity 0.3s ease'; targetView.style.opacity = '1'; }, 10);
        }
        
        document.querySelectorAll('.task-card').forEach(card => { card.style.background = 'var(--bg-color)'; card.style.boxShadow = 'none'; });
        event.currentTarget.style.background = 'rgba(59,130,246,0.05)';
        event.currentTarget.style.boxShadow = '0 5px 15px rgba(0,0,0,0.1)';
    }

    function filterStudentsMagic() {
        let input = document.getElementById('student_search_magic').value.toLowerCase();
        document.querySelectorAll('.student-magic-card').forEach(card => {
            let name = card.querySelector('.student-name-filter').innerText.toLowerCase();
            let idNum = card.querySelector('.student-id-filter').innerText.toLowerCase();
            card.style.display = (name.indexOf(input) > -1 || idNum.indexOf(input) > -1) ? 'flex' : 'none';
        });
    }

    // ==========================================
    // 5. EXAM MANAGER & TIMERS
    // ==========================================
    let currentExamId = null;

    function openExamManager(examId, title, course, accessCode) {
        document.getElementById('exam-placeholder').style.display = 'none';
        let view = document.getElementById('exam-manager-view'); view.style.display = 'flex';
        
        document.getElementById('mgr_exam_title').innerText = title;
        document.getElementById('mgr_exam_course').innerText = course;
        document.getElementById('mgr_exam_code').innerHTML = `<i class="fa-solid fa-key"></i> Code: <strong>${accessCode}</strong>`;
        document.getElementById('form_q_exam_id').value = examId;
        currentExamId = examId;
        
        document.querySelectorAll('.exam-card').forEach(c => { c.style.background = 'var(--panel-bg)'; c.style.borderColor = 'var(--border-color)'; });
        event.currentTarget.style.background = 'rgba(244,63,94,0.05)'; event.currentTarget.style.borderColor = '#f43f5e';
        loadExamData();
    }

    function switchExamTab(tab) {
        document.getElementById('exam_tab_questions').style.display = (tab === 'questions') ? 'block' : 'none';
        document.getElementById('exam_tab_results').style.display = (tab === 'results') ? 'block' : 'none';
        let btnQ = document.getElementById('tab_btn_questions'); let btnR = document.getElementById('tab_btn_results');
        
        if(tab === 'questions') { btnQ.style.color = 'var(--primary)'; btnQ.style.borderBottomColor = 'var(--primary)'; btnR.style.color = 'var(--text-muted)'; btnR.style.borderBottomColor = 'transparent'; } 
        else { btnR.style.color = 'var(--primary)'; btnR.style.borderBottomColor = 'var(--primary)'; btnQ.style.color = 'var(--text-muted)'; btnQ.style.borderBottomColor = 'transparent'; loadExamData(); }
    }

    function loadExamData() {
        if(!currentExamId) return;
        document.getElementById('exam_loader').style.display = 'block';
        document.getElementById('questions-list-container').innerHTML = '';
        document.getElementById('results-table-container').innerHTML = '';

        let fd = new FormData(); fd.append('ajax_action', 'fetch_exam_details'); fd.append('exam_id', currentExamId);
        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(data => {
            document.getElementById('exam_loader').style.display = 'none';
            document.getElementById('questions-list-container').innerHTML = data.questions;
            document.getElementById('results-table-container').innerHTML = data.results;
        }).catch(err => console.log(err));
    }

    function toggleQuestionType() {
        let type = document.getElementById('q_type_selector').value;
        let mcqContainer = document.getElementById('mcq_options_container');
        let textContainer = document.getElementById('text_options_container');
        let optA = document.getElementById('opt_a'); let optB = document.getElementById('opt_b'); let optC = document.getElementById('opt_c'); let optD = document.getElementById('opt_d'); let cText = document.getElementById('correct_text');

        if(type === 'multiple_choice') {
            mcqContainer.style.display = 'block'; textContainer.style.display = 'none';
            optA.setAttribute('required', 'required'); optB.setAttribute('required', 'required'); optC.setAttribute('required', 'required'); optD.setAttribute('required', 'required'); cText.removeAttribute('required');
        } else {
            mcqContainer.style.display = 'none'; textContainer.style.display = 'block';
            optA.removeAttribute('required'); optB.removeAttribute('required'); optC.removeAttribute('required'); optD.removeAttribute('required'); cText.setAttribute('required', 'required');
            if(type === 'fill_blank') cText.placeholder = "Enter the exact word or phrase (e.g., Photosynthesis)";
            else if(type === 'essay') cText.placeholder = "Enter keywords or concepts the AI API should check for grading...";
        }
    }

    function submitQuestion(e) {
        e.preventDefault();
        let btn = e.target.querySelector('button[type="submit"]'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;
        let fd = new FormData(e.target); fd.append('ajax_action', 'add_question');
        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(data => {
            if(data.status === 'success') { e.target.reset(); loadExamData(); btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Question'; btn.disabled = false; }
        });
    }

    function openTimeModal(id, title) { document.getElementById('ext_exam_id').value = id; document.getElementById('ext_exam_title').innerText = title; document.getElementById('addTimeModal').classList.add('active'); }
    function openEditQuestionModal(id, text, a, b, c, d, e_opt, f_opt, ans) {
        document.getElementById('edit_q_id').value = id; document.getElementById('edit_q_text').value = text;
        document.getElementById('edit_opt_a').value = a; document.getElementById('edit_opt_b').value = b;
        document.getElementById('edit_opt_c').value = c; document.getElementById('edit_opt_d').value = d;
        document.getElementById('edit_opt_e').value = e_opt; document.getElementById('edit_opt_f').value = f_opt;
        document.getElementById('edit_correct_opt').value = ans; document.getElementById('editQuestionModal').classList.add('active');
    }
    function openSmartAiModal() { if(!currentExamId) { alert("Please select an exam first!"); return; } document.getElementById('smartAiModal').classList.add('active'); }
    function openBulkModal() { if(!currentExamId) { alert("Please select an exam first!"); return; } document.getElementById('bulk_exam_id').value = currentExamId; document.getElementById('bulkUploadModal').classList.add('active'); }

    function simulateAiScan() {
        let file = document.getElementById('ai_file_input').files[0]; if(!file) return;
        let overlay = document.getElementById('ai_scanning_overlay'); let text = document.getElementById('ai_scan_text'); let bar = document.getElementById('ai_progress');
        overlay.style.display = 'flex';
        setTimeout(() => { text.innerText = 'Analyzing Context...'; bar.style.width = '30%'; }, 1000);
        setTimeout(() => { text.innerText = 'Generating Distractors...'; bar.style.width = '60%'; }, 2500);
        setTimeout(() => { text.innerText = 'Finalizing Questions...'; bar.style.width = '90%'; }, 4000);
        setTimeout(() => { overlay.style.display = 'none'; document.getElementById('smartAiModal').classList.remove('active'); document.getElementById('ai_file_input').value = ''; bar.style.width = '0%'; alert('🪄 AI Magic Successful! \n\nIn a live production environment with OpenAI API Key, the generated questions would now instantly populate your Question Bank below.'); }, 5000);
    }

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
                        card.style.borderColor = '#10b981'; card.style.boxShadow = '0 0 20px rgba(16,185,129,0.2)';
                        badgeContainer.innerHTML = `<span class='badge' style='background:rgba(16,185,129,0.1); color:#10b981; animation:pulse-badge 1.5s infinite;'><i class='fa-solid fa-tower-broadcast'></i> LIVE NOW</span>`;
                        btnArea.innerHTML = `<button class='glow-btn magic-enter-btn' style='width:100%; justify-content:center; background:linear-gradient(135deg, #10b981, #059669); box-shadow:0 8px 20px rgba(16,185,129,0.4); color:#fff; animation: fadeIn 0.5s ease;' onclick="openExamAuth(${id}, '${title}', '${code}')"><i class='fa-solid fa-play'></i> Enter Access Code</button>`;
                    }
                }
                else if (now > endMs) {
                    if(!badgeContainer.innerHTML.includes('Missed')) {
                        card.style.borderColor = 'var(--border-color)'; card.style.boxShadow = 'none'; card.style.opacity = '0.7';
                        badgeContainer.innerHTML = `<span class='badge badge-red'><i class='fa-solid fa-ban'></i> Missed</span>`;
                        btnArea.innerHTML = `<button class='btn' disabled style='width:100%; background:rgba(244,63,94,0.1); color:#f43f5e; cursor:not-allowed;'><i class='fa-solid fa-xmark'></i> Exam Closed</button>`;
                    }
                }
            });
        }, 1000);
    }

    // ==========================================
    // 6. MASTER GRADEBOOK
    // ==========================================
    function loadGradebook() {
        let selectedCourseId = document.getElementById('grade_course_selector').value;
        document.getElementById('gradebook-placeholder').style.display = selectedCourseId ? 'none' : 'block';
        document.querySelectorAll('.grade-view').forEach(view => { view.style.display = 'none'; });
        if(selectedCourseId) {
            let targetView = document.getElementById('grade-view-' + selectedCourseId);
            if(targetView) { targetView.style.display = 'block'; targetView.style.animation = 'fadeIn 0.4s ease'; }
        }
    }

    function openGradeSettings(cId, cName, wAtt, wAss, wProj, wQuiz, wMid, wFin) {
        document.getElementById('cfg_course_id').value = cId; document.getElementById('cfg_course_name').innerText = cName;
        document.getElementById('cfg_w_att').value = wAtt; document.getElementById('cfg_w_ass').value = wAss;
        document.getElementById('cfg_w_proj').value = wProj; document.getElementById('cfg_w_quiz').value = wQuiz;
        document.getElementById('cfg_w_mid').value = wMid; document.getElementById('cfg_w_fin').value = wFin;
        document.getElementById('configGradeModal').classList.add('active');
    }

    function calcTotal(inputElement) {
        let row = inputElement.closest('tr');
        let maxVal = parseFloat(inputElement.getAttribute('max'));
        if(parseFloat(inputElement.value) > maxVal) inputElement.value = maxVal;
        if(parseFloat(inputElement.value) < 0) inputElement.value = 0;

        let total = 0;
        row.querySelectorAll('.grade-input').forEach(inp => { total += parseFloat(inp.value) || 0; });
        row.querySelector('.total-display').innerText = total.toFixed(1);
        
        let letter = 'F'; let color = '#f43f5e';
        if(total >= 90) { letter = 'A+'; color = '#10b981'; } else if(total >= 85) { letter = 'A'; color = '#10b981'; } else if(total >= 80) { letter = 'A-'; color = '#10b981'; } else if(total >= 75) { letter = 'B+'; color = '#3b82f6'; } else if(total >= 70) { letter = 'B'; color = '#3b82f6'; } else if(total >= 65) { letter = 'B-'; color = '#3b82f6'; } else if(total >= 60) { letter = 'C+'; color = '#f59e0b'; } else if(total >= 50) { letter = 'C'; color = '#f59e0b'; } else if(total >= 40) { letter = 'D'; color = '#f97316'; }
        
        let letterEl = row.querySelector('.letter-display');
        letterEl.innerText = letter; letterEl.style.color = color;
    }

    // ==========================================
    // 7. ACADEMIC CALENDAR
    // ==========================================
    let currentDate = new Date();
    const eventsData = <?php echo isset($calendar_events_json) ? $calendar_events_json : '[]'; ?>;

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

        for (let i = 0; i < firstDay; i++) { let emptyCell = document.createElement('div'); emptyCell.style.background = 'var(--panel-bg)'; emptyCell.style.opacity = '0.3'; grid.appendChild(emptyCell); }

        let today = new Date(); let upcomingHtml = ''; let upcomingCount = 0;

        for (let i = 1; i <= daysInMonth; i++) {
            let cell = document.createElement('div');
            cell.style.background = 'var(--panel-bg)'; cell.style.padding = '10px'; cell.style.position = 'relative'; cell.style.display = 'flex'; cell.style.flexDirection = 'column'; cell.style.alignItems = 'flex-start'; cell.style.transition = '0.3s';
            cell.onmouseover = () => cell.style.background = 'rgba(236,72,153,0.02)'; cell.onmouseout = () => cell.style.background = 'var(--panel-bg)';
            
            let isToday = (year === today.getFullYear() && month === today.getMonth() && i === today.getDate());
            if (isToday) { cell.style.background = 'rgba(236,72,153,0.05)'; cell.onmouseout = () => cell.style.background = 'rgba(236,72,153,0.05)'; cell.innerHTML = `<div style="width:28px; height:28px; background:#ec4899; color:#fff; border-radius:50%; display:flex; justify-content:center; align-items:center; font-weight:800; font-size:13px; margin-bottom:8px; box-shadow:0 4px 10px rgba(236,72,153,0.4);">${i}</div>`; } 
            else { cell.innerHTML = `<div style="font-weight:700; font-size:14px; color:var(--text-muted); margin-bottom:8px;">${i}</div>`; }

            let cellDateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
            let dayEvents = eventsData.filter(e => e.date === cellDateStr);
            dayEvents.forEach(ev => {
                let color = '#3b82f6'; let icon = 'fa-stopwatch'; let rgb = '59,130,246';
                if(ev.type === 'assignment') { color = '#f59e0b'; icon = 'fa-list-check'; rgb = '245,158,11'; }
                if(ev.type === 'project') { color = '#8b5cf6'; icon = 'fa-rocket'; rgb = '139,92,246'; }

                cell.innerHTML += `<div style="background:rgba(${rgb}, 0.1); border-left:3px solid ${color}; color:${color}; font-size:10.5px; font-weight:700; padding:5px 8px; border-radius:4px; margin-bottom:5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer; transition:0.2s;" onmouseover="this.style.background='${color}'; this.style.color='#fff';" onmouseout="this.style.background='rgba(${rgb}, 0.1)'; this.style.color='${color}';" title="${ev.title} at ${ev.time}">
                    <i class="fa-solid ${icon}"></i> ${ev.title}
                </div>`;

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
        for (let i = 0; i < remainingCells; i++) { let emptyCell = document.createElement('div'); emptyCell.style.background = 'var(--panel-bg)'; emptyCell.style.opacity = '0.3'; grid.appendChild(emptyCell); }
        if(upcomingHtml === '') upcomingHtml = '<div style="text-align:center; color:var(--text-muted); padding:30px; border:1px dashed var(--border-color); border-radius:12px;"><i class="fa-regular fa-calendar-check" style="font-size:40px; margin-bottom:15px; opacity:0.5;"></i><br>No upcoming deadlines or exams! Enjoy your free time.</div>';
        upcomingList.innerHTML = upcomingHtml;
    }

    function changeMonth(direction, isToday = false) { if(isToday) { currentDate = new Date(); } else { currentDate.setMonth(currentDate.getMonth() + direction); } renderCalendar(); }

    // ==========================================
    // 8. TELEGRAM CHAT
    // ==========================================
    let currentChatId=null, currentChatRole=null, currentChatIsGroup=null, chatInterval=null;
    function switchFolder(folder) { document.querySelectorAll('.tg-folder').forEach(el=>el.classList.remove('active')); event.currentTarget.classList.add('active'); document.querySelectorAll('.tg-contact-item').forEach(el=>{ el.style.display='none'; if(el.classList.contains('chat-item-'+folder)) el.style.display='flex'; }); }
    
    function openTelegramChat(id, role, isGroup, name, subtitle, color, avatar_url = '') { 
        document.getElementById('tg-placeholder').style.display='none'; document.getElementById('tg-active-chat').style.display='flex'; 
        document.getElementById('chat-header-name').innerHTML=name; document.getElementById('chat-header-role').innerText=subtitle; 
        const avatarDiv = document.getElementById('chat-header-avatar'); avatarDiv.style.background = color; avatarDiv.style.position = 'relative'; 
        if(isGroup === 1) { avatarDiv.innerHTML = '<i class="fa-solid fa-bullhorn"></i>'; avatarDiv.classList.add('group'); } 
        else { 
            if(avatar_url === 'LOCKED') { avatarDiv.innerHTML = '<i class="fa-solid fa-user-lock" style="font-size:20px; color:#fff;"></i>'; avatarDiv.style.background = color; } 
            else if(avatar_url && avatar_url !== '') { 
                let imgHtml = `<img src="${avatar_url}" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`; 
                if(role === 'head') { imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#a78bfa; background:#fff; border-radius:50%; font-size:14px; border:2px solid var(--panel-bg); z-index:10;"></i>`; } 
                else if(role === 'admin' || role === 'super_admin') { imgHtml += `<i class="fa-solid fa-circle-check" style="position:absolute; bottom:-3px; right:-3px; color:#34d399; background:#fff; border-radius:50%; font-size:14px; border:2px solid var(--panel-bg); z-index:10;"></i>`; }
                avatarDiv.innerHTML = imgHtml; avatarDiv.style.background = 'transparent'; 
            } else { avatarDiv.innerHTML = name.replace(/<[^>]*>?/gm, '').trim().charAt(0).toUpperCase(); } 
            avatarDiv.classList.remove('group'); avatarDiv.style.borderRadius = '50%'; 
        } 
        currentChatId=id; currentChatRole=role; currentChatIsGroup=isGroup; 
        document.getElementById('chat_receiver_id').value=id; document.getElementById('chat_receiver_role').value=role; document.getElementById('chat_is_group').value=isGroup; document.getElementById('edit_msg_id').value=''; 
        fetchChatMessages(); if(chatInterval) clearInterval(chatInterval); chatInterval=setInterval(fetchChatMessages, 2500); 
    }

    function fetchChatMessages() { 
        if(currentChatId === null) return; 
        let fd = new FormData(); fd.append('ajax_action','fetch_chat'); fd.append('receiver_id',currentChatId); fd.append('receiver_role',currentChatRole); fd.append('is_group',currentChatIsGroup); 
        fetch(window.location.href, {method:'POST', body:fd}).then(r => r.text()).then(h => { 
            if(h.includes('<!DOCTYPE') || h.includes('<aside class="sidebar"')) return; 
            const chatHistory = document.getElementById('chat-history-container'); 
            if(chatHistory) { let isAtBottom = chatHistory.scrollHeight - chatHistory.clientHeight <= chatHistory.scrollTop + 50; chatHistory.innerHTML = h; if(isAtBottom) chatHistory.scrollTop = chatHistory.scrollHeight; }
        }); 
    }

    function submitTelegramMsg(e) { 
        e.preventDefault(); let input=document.getElementById('chat_message_input'); if(!input.value.trim())return; 
        let fd=new FormData(document.getElementById('tg-chat-form')); fd.append('ajax_action', document.getElementById('edit_msg_id').value ? 'edit_msg' : 'send_msg'); 
        fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.status==='success'){ input.value=''; document.getElementById('edit_msg_id').value=''; fetchChatMessages(); setTimeout(() => { const chatHistory = document.getElementById('chat-history-container'); chatHistory.scrollTop = chatHistory.scrollHeight; }, 100); }}); 
    }

    function fetchUnreadBadges() { 
        let fd = new FormData(); fd.append('ajax_action', 'fetch_unread'); 
        fetch(window.location.href, {method:'POST', body:fd}).then(r=>r.json()).then(data=>{ 
            document.querySelectorAll('.chat-unread-badge').forEach(b => b.style.display = 'none'); 
            for(let key in data) { 
                if(key !== 'total_all' && key !== 'new_submissions') { 
                    let badge = document.getElementById('badge_' + key); 
                    if(badge) { if(currentChatRole + '_' + currentChatId !== key) { badge.innerText = data[key]; badge.style.display = 'inline-block'; } else { fetchChatMessages(); } } 
                } 
            } 
            let mainBadge = document.getElementById('main_comm_badge'); 
            if(mainBadge) { if(data.total_all > 0) { mainBadge.innerText = data.total_all; mainBadge.style.display = 'inline-block'; mainBadge.style.position = 'absolute'; mainBadge.style.right = '15px'; mainBadge.style.top = '50%'; } else { mainBadge.style.display = 'none'; } } 
            let subBadge = document.getElementById('badge_submissions');
            if(subBadge) { if(data.new_submissions > 0) { subBadge.innerText = data.new_submissions; subBadge.style.display = 'inline-block'; } else { subBadge.style.display = 'none'; } }
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

    // ==========================================
    // 9. HELP CENTER
    // ==========================================
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

    // ==========================================
    // 10. INITIALIZE ALL ON LOAD
    // ==========================================
    window.addEventListener('DOMContentLoaded', () => {
        toggleMatFields(); // Initial form state
        initCharts(); 
        animateCounters();
        initMagicExamTimers();
        if (typeof renderCalendar === "function") renderCalendar();
        
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        
        if(activeTab) { 
            openTab(activeTab); 
            window.history.replaceState({}, document.title, window.location.pathname); 
        } else { 
            openTab('home'); 
        }
        
        if(urlParams.get('msg') === 'settings_saved' && urlParams.get('c_id')) {
            document.getElementById('grade_course_selector').value = urlParams.get('c_id');
            loadGradebook();
        }
    });

</script>
</body>
</html>