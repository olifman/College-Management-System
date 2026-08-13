<?php
// student/take_exam.php
session_start();
include("../includes/config.php");
date_default_timezone_set('Africa/Addis_Ababa');

if(!isset($_SESSION['username']) || $_SESSION['role'] != 'student'){
    header("Location: ../index.php"); 
    exit();
}

$student_id = $_SESSION['user_id'];
$is_review_mode = false;
$e_id = 0;

// ==========================================
// 🔍 1. REVIEW MODE CHECK
// ==========================================
if(isset($_GET['review'])) {
    $e_id = intval($_GET['review']);
    
    $chk_res = mysqli_query($conn, "SELECT * FROM exam_results WHERE exam_id=$e_id AND student_id=$student_id");
    if(mysqli_num_rows($chk_res) > 0) {
        $res_data = mysqli_fetch_assoc($chk_res);
        $sub_time = strtotime($res_data['submitted_at']);
        if(time() >= ($sub_time + (10 * 60))) {
            $is_review_mode = true;
        } else {
            echo "<script>alert('Results are still compiling. Please wait.'); window.location.href='dashboard.php?tab=exams';</script>"; exit();
        }
    } else {
        header("Location: dashboard.php?tab=exams"); exit();
    }
} 
// ==========================================
// 📝 2. NORMAL EXAM MODE CHECK
// ==========================================
elseif(isset($_POST['enter_exam'])) {
    $e_id = intval($_POST['exam_id']);
    $access_code = mysqli_real_escape_string($conn, trim($_POST['student_access_code']));
    
    $ex_q = mysqli_query($conn, "SELECT * FROM exams WHERE id=$e_id AND is_deleted=0");
    if($ex = mysqli_fetch_assoc($ex_q)) {
        if($access_code === $ex['access_code']) {
            $chk = mysqli_query($conn, "SELECT id FROM exam_results WHERE exam_id=$e_id AND student_id=$student_id");
            if(mysqli_num_rows($chk) > 0){
                echo "<script>alert('You have already completed this exam.'); window.location.href='dashboard.php?tab=exams';</script>"; exit();
            }
            
            $_SESSION['active_exam_id'] = $e_id;
            $_SESSION['exam_start_time'] = time();
            $_SESSION['exam_duration_mins'] = $ex['duration_mins'];
            
            $q_res = mysqli_query($conn, "SELECT * FROM exam_questions WHERE exam_id=$e_id ORDER BY RAND()"); 
            $questions = [];
            while($row = mysqli_fetch_assoc($q_res)) {
                if($row['question_type'] == 'multiple_choice') {
                    $opts = [];
                    if(!empty($row['opt_a'])) $opts['A'] = $row['opt_a'];
                    if(!empty($row['opt_b'])) $opts['B'] = $row['opt_b'];
                    if(!empty($row['opt_c'])) $opts['C'] = $row['opt_c'];
                    if(!empty($row['opt_d'])) $opts['D'] = $row['opt_d'];
                    if(!empty($row['opt_e'])) $opts['E'] = $row['opt_e'];
                    if(!empty($row['opt_f'])) $opts['F'] = $row['opt_f'];
                    
                    $keys = array_keys($opts); shuffle($keys);
                    $shuffled_opts = [];
                    foreach ($keys as $key) { $shuffled_opts[$key] = $opts[$key]; }
                    $row['shuffled_options'] = $shuffled_opts;
                }
                $questions[] = $row;
            }
            $_SESSION['exam_'.$e_id.'_q_order'] = $questions;
            header("Location: take_exam.php"); exit();
        } else {
            echo "<script>alert('Invalid Access Code! Try again.'); window.location.href='dashboard.php?tab=exams';</script>"; exit();
        }
    } else {
        echo "<script>alert('Exam not found!'); window.location.href='dashboard.php?tab=exams';</script>"; exit();
    }
} 
// 🪄 3. SUCCESS MODE CHECK (Mirkaneessa Xumuramuusaa)
elseif(isset($_SESSION['exam_submitted'])) {
    // UI dhumaa (Success page) qofa agarsiisuuf asitti dhiisna
    $is_success_mode = true;
}
elseif(isset($_SESSION['active_exam_id'])) {
    $e_id = $_SESSION['active_exam_id'];
} else {
    header("Location: dashboard.php?tab=exams"); exit();
}

// Yoo Success Mode hin taane qofa ragaa fida
if(!isset($is_success_mode)) {
    $ex = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id=$e_id"));

    if($is_review_mode) {
        $q_res = mysqli_query($conn, "SELECT * FROM exam_questions WHERE exam_id=$e_id ORDER BY id ASC"); 
        $questions = [];
        while($row = mysqli_fetch_assoc($q_res)) { $questions[] = $row; }
        
        $has_answers_db = false;
        $stu_answers = [];
        $stu_is_correct = [];
        
        $ans_chk = mysqli_query($conn, "SHOW TABLES LIKE 'student_answers'");
        if(mysqli_num_rows($ans_chk) > 0) {
            $has_answers_db = true;
            $ans_q = mysqli_query($conn, "SELECT question_id, student_answer, is_correct FROM student_answers WHERE exam_id=$e_id AND student_id=$student_id");
            while($a = mysqli_fetch_assoc($ans_q)) { 
                $stu_answers[$a['question_id']] = $a['student_answer']; 
                $stu_is_correct[$a['question_id']] = $a['is_correct']; 
            }
        }
    } else {
        $questions = isset($_SESSION['exam_'.$e_id.'_q_order']) ? $_SESSION['exam_'.$e_id.'_q_order'] : [];
        $current_time = time();
        $started_at = $_SESSION['exam_start_time'];
        $duration_secs = $_SESSION['exam_duration_mins'] * 60;
        $time_passed = $current_time - $started_at;
        $seconds_left = $duration_secs - $time_passed;
    }

    $total_q = count($questions);

    // ==========================================
    // 🚀 4. SUBMIT EXAM LOGIC (Qabxii Herreguu)
    // ==========================================
    if(!$is_review_mode && (isset($_POST['submit_exam']) || $seconds_left <= 0)){
        $score = 0;
        
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            question_id INT NOT NULL,
            student_id INT NOT NULL,
            student_answer TEXT NOT NULL,
            is_correct TINYINT(1) DEFAULT 0
        )");

        foreach($questions as $q) {
            $q_id = $q['id'];
            $ans = isset($_POST['q_'.$q_id]) ? $_POST['q_'.$q_id] : '';
            $is_correct = 0;
            
            if($q['question_type'] == 'multiple_choice') {
                if($ans === $q['correct_opt']) { $score++; $is_correct = 1; }
            } elseif($q['question_type'] == 'fill_blank') {
                if(strtolower(trim($ans)) === strtolower(trim($q['correct_text']))) { $score++; $is_correct = 1; }
            } elseif($q['question_type'] == 'essay') {
                $keywords = explode(',', strtolower($q['correct_text']));
                $student_ans = strtolower(trim($ans));
                $match_count = 0;
                foreach($keywords as $kw) {
                    if(!empty(trim($kw)) && strpos($student_ans, trim($kw)) !== false) $match_count++;
                }
                if(count($keywords) > 0 && $match_count >= ceil(count($keywords)/2)) { $score++; $is_correct = 1; }
            }
            
            if(!empty($ans)) {
                $safe_ans = mysqli_real_escape_string($conn, $ans);
                mysqli_query($conn, "INSERT INTO student_answers (exam_id, question_id, student_id, student_answer, is_correct) VALUES ($e_id, $q_id, $student_id, '$safe_ans', $is_correct)");
            }
        }
        
        $chk_exist = mysqli_query($conn, "SELECT id FROM exam_results WHERE exam_id=$e_id AND student_id=$student_id");
        if(mysqli_num_rows($chk_exist) == 0){
            mysqli_query($conn, "INSERT INTO exam_results (exam_id, student_id, score, total_questions) VALUES ($e_id, $student_id, $score, $total_q)");
        }
        
        unset($_SESSION['active_exam_id']);
        unset($_SESSION['exam_start_time']);
        unset($_SESSION['exam_duration_mins']);
        unset($_SESSION['exam_'.$e_id.'_q_order']);
        
        // 🪄 MAGIC: Session milkaa'uu uumee ofitti deebisa
        $_SESSION['exam_submitted'] = true;
        
        echo "<script>
                localStorage.removeItem('eplms_exam_{$e_id}_answers');
                localStorage.removeItem('eplms_exam_{$e_id}_flags');
                window.location.href = 'take_exam.php';
              </script>";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($is_success_mode) ? "Exam Submitted" : ($is_review_mode ? "Review Exam" : "Secure Exam"); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php if(isset($is_success_mode)): ?>
    <!-- ========================================== -->
    <!-- 🟢 SUCCESS PAGE CSS -->
    <!-- ========================================== -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap');
        * { box-sizing: border-box; font-family: 'Inter', sans-serif; margin: 0; padding: 0; }
        body { background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; overflow: hidden; }
        
        .success-card { background: #fff; width: 90%; max-width: 500px; padding: 50px 30px; border-radius: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.1); text-align: center; border-top: 8px solid #10b981; animation: slideUp 0.5s ease; position: relative; overflow: hidden; }
        @keyframes slideUp { from { transform: translateY(50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .success-card::before { content: ''; position: absolute; top: -100px; left: 50%; transform: translateX(-50%); width: 200px; height: 200px; background: radial-gradient(circle, rgba(16,185,129,0.15) 0%, transparent 70%); border-radius: 50%; z-index: 0; }
        
        .icon-circle { width: 100px; height: 100px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-size: 50px; display: flex; justify-content: center; align-items: center; border-radius: 50%; margin: 0 auto 25px auto; position: relative; z-index: 1; box-shadow: 0 10px 25px rgba(16,185,129,0.4); animation: popIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes popIn { 0% { transform: scale(0); } 100% { transform: scale(1); } }
        
        h1 { font-size: 28px; font-weight: 900; color: #0f172a; margin-bottom: 15px; position: relative; z-index: 1; }
        p { font-size: 14.5px; color: #64748b; line-height: 1.6; margin-bottom: 25px; position: relative; z-index: 1; }
        
        .delay-notice { background: rgba(245, 158, 11, 0.05); border: 1px dashed rgba(245, 158, 11, 0.5); color: #b45309; padding: 20px; border-radius: 12px; margin-bottom: 30px; display: flex; align-items: center; gap: 15px; text-align: left; position: relative; z-index: 1; }
        .delay-notice i { font-size: 30px; color: #f59e0b; animation: spin-slow 4s linear infinite; }
        @keyframes spin-slow { 100% { transform: rotate(360deg); } }
        .delay-notice-text strong { display: block; color: #b45309; font-size: 15px; margin-bottom: 4px; }
        .delay-notice-text span { font-size: 13px; color: #78350f; font-weight: 600; line-height: 1.5; }

        .btn-return { background: linear-gradient(135deg, #f43f5e, #be123c); color: #fff; border: none; padding: 16px 30px; border-radius: 30px; font-size: 15px; font-weight: 800; cursor: pointer; transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; box-shadow: 0 10px 20px rgba(244,63,94,0.3); position: relative; z-index: 1; text-decoration: none; width: 100%; justify-content: center; }
        .btn-return:hover { transform: translateY(-3px); box-shadow: 0 15px 30px rgba(244,63,94,0.4); }
   #screenshot-overlay{
    backdrop-filter: blur(20px);
}
    </style>
    
    <?php else: ?>
    <!-- ========================================== -->
    <!-- 🔵 NORMAL EXAM / REVIEW PAGE CSS -->
    <!-- ========================================== -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        
        * { box-sizing: border-box; font-family: 'Inter', sans-serif; margin: 0; padding: 0; 
            -webkit-user-select: <?php echo $is_review_mode ? 'text' : 'none'; ?>; 
            user-select: <?php echo $is_review_mode ? 'text' : 'none'; ?>; 
        }
        
        input[type="text"], textarea { -webkit-user-select: text; user-select: text; }

        body { background: #f0f2f5; color: #1e293b; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        
        .exam-header { background: #ffffff; border-bottom: 2px solid #e2e8f0; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 10px rgba(0,0,0,0.02); z-index: 10; }
        .exam-title { font-size: 20px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        
        .timer-box { background: #fef2f2; border: 2px solid #f43f5e; color: #e11d48; padding: 8px 20px; border-radius: 30px; font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 10px; font-family: monospace; letter-spacing: 2px; }
        .timer-box.warning { animation: pulse-danger 1s infinite; background: #e11d48; color: #fff; }
        @keyframes pulse-danger { 0% { box-shadow: 0 0 0 0 rgba(225,29,72,0.7); } 70% { box-shadow: 0 0 0 10px rgba(225,29,72,0); } 100% { box-shadow: 0 0 0 0 rgba(225,29,72,0); } }
        
        .review-mode-box { background: #ecfdf5; border: 2px solid #10b981; color: #059669; padding: 8px 20px; border-radius: 30px; font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 10px rgba(16,185,129,0.2); }

/* 🪄 MAGIC: Guutummaatti gara qarqaraatti dhiiba (100% width) */
        .main-container { display: flex; flex: 1; overflow: hidden; max-width: 100%; margin: 0; width: 100%; padding: 20px 30px; gap: 30px; }
        .questions-area { flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; }
        
        .q-card { display: none; height: 100%; }
        .q-card.active { display: flex; flex-direction: column; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* 🪄 NEW: Layout for Info Box (Left) & Question (Right) */
        .q-layout-wrapper { display: flex; gap: 20px; flex: 1; overflow-y: auto; padding-right: 10px; padding-bottom: 20px;}
        .q-layout-wrapper::-webkit-scrollbar { width: 6px; }
        .q-layout-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

/* Left Info Box - TALLER (Full Height like Right Sidebar) */
        .q-info-box { width: 280px; min-width: 280px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); height: calc(100vh - 120px); position: sticky; top: 0; display: flex; flex-direction: column; gap: 18px; margin-left: 0; }
        .q-info-box h3 { font-size: 24px; font-weight: 900; color: #0f172a; margin-bottom: 5px; }
        .q-status { font-size: 15px; font-weight: 700; color: #64748b; }
        .q-status.correct-txt { color: #10b981; }
        .q-status.wrong-txt { color: #e11d48; }
        .q-mark { font-size: 14.5px; color: #475569; background: #f1f5f9; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0; font-weight: 600;}
        .flag-btn { background: transparent; border: none; color: #64748b; cursor: pointer; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 10px; transition: 0.3s; padding: 0; text-align: left;}
        .flag-btn:hover { color: #f43f5e; }
        .flag-btn.flagged { color: #f43f5e; font-weight: 900; }

        /* Right Question Content */
        .q-content-box { flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 35px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
        .q-text { font-size: 17.5px; font-weight: 600; color: #1e293b; margin-bottom: 30px; line-height: 1.6; }
        .q-text { font-size: 18px; font-weight: 600; color: #0f172a; margin-bottom: 30px; line-height: 1.6; }
        
        .option-label { display: flex; align-items: center; gap: 15px; padding: 18px 20px; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 15px; font-size: 16px; color: #334155; font-weight: 500; transition: 0.2s;}
        .option-label input[type="radio"] { accent-color: #3b82f6; width: 20px; height: 20px; }
        
        <?php if(!$is_review_mode): ?>
        .option-label { cursor: pointer; }
        .option-label:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .option-label.selected { background: #eff6ff; border-color: #3b82f6; font-weight: 700; color: #1d4ed8; }
        <?php endif; ?>

        /* Review Mode Highlights */
        .option-label.correct { background: #ecfdf5; border-color: #10b981; border-width: 2px; color: #065f46; font-weight: 800; box-shadow: 0 0 15px rgba(16,185,129,0.3); }
        .option-label.wrong-selected { background: #fff1f2; border-color: #e11d48; border-width: 2px; color: #9f1239; font-weight: 800; box-shadow: 0 0 15px rgba(225,29,72,0.3); }
        .option-label.actual-correct { background: #f0fdf4; border-color: #10b981; color: #065f46; border-style: dashed; border-width: 2px; } 
        .correct-answer-box { background: #fffbeb; color: #b45309; padding: 18px 25px; border-radius: 12px; margin-top: 25px; font-weight: 700; border: 1px solid #fde68a; border-left: 6px solid #f59e0b; font-size: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 15px; }

        .text-input { width: 100%; padding: 20px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 16px; outline: none; transition: 0.3s; resize: vertical; color: #0f172a; font-weight: 500; }
        
        .q-footer { padding: 20px 30px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; border-radius: 0 0 12px 12px; }
        .nav-btn { background: #fff; border: 1px solid #cbd5e1; color: #334155; padding: 12px 25px; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .nav-btn:hover:not(:disabled) { background: #f1f5f9; border-color: #94a3b8; }
        .nav-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* Right Navigation Sidebar - PUSHED TO THE EXTREME RIGHT */
        .nav-sidebar { width: 350px; min-width: 350px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 30px; display: flex; flex-direction: column; box-shadow: 0 4px 15px rgba(0,0,0,0.03); height: calc(100vh - 120px); position: sticky; top: 20px; margin-right: 0; }
        .nav-title { font-size: 18px; font-weight: 900; color: #0f172a; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #e2e8f0;}
        
        /* flex: 1 makes it stretch and push the button to the bottom */
        .nav-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px; margin-bottom: 30px; overflow-y: auto; flex: 1; padding-right: 5px; align-content: start; }
        
        .nav-box { height: 50px; border: 2px solid #e2e8f0; border-radius: 8px; display: flex; flex-direction: column; justify-content: center; align-items: center; font-size: 16px; font-weight: 800; color: #475569; cursor: pointer; text-decoration: none; transition: 0.2s; background: #fff; position: relative; }
        .nav-box:hover { border-color: #94a3b8; }
        .nav-box.active-nav { border-color: #3b82f6; border-width: 3px; transform: scale(1.05); }
        
        <?php if(!$is_review_mode): ?>
        .nav-box.answered { background: #10b981; border-color: #059669; color: #fff; }
        .nav-box.flagged { border-color: #f43f5e; }
        <?php else: ?>
        .nav-box.correct { background: #10b981; border-color: #059669; color: #fff; }
        .nav-box.wrong { background: #f43f5e; border-color: #e11d48; color: #fff; }
        <?php endif; ?>

        .submit-btn { margin-top: auto; background: linear-gradient(135deg, #f43f5e, #be123c); color: #fff; border: none; padding: 18px; border-radius: 10px; font-size: 16px; font-weight: 800; cursor: pointer; transition: 0.3s; width: 100%; display: flex; justify-content: center; align-items: center; gap: 10px; box-shadow: 0 4px 15px rgba(244,63,94,0.3); }
        .review-return-btn { margin-top: auto; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; border: none; padding: 18px; border-radius: 10px; font-size: 16px; font-weight: 800; cursor: pointer; transition: 0.3s; width: 100%; display: flex; justify-content: center; align-items: center; gap: 10px; text-decoration: none;}

        #cheat-warning { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(225, 29, 72, 0.95); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; color: #fff; text-align: center; padding: 20px; backdrop-filter: blur(10px); }
    </style>
    <?php endif; ?>
</head>
<body>

<?php if(isset($is_success_mode)): ?>
    <!-- ========================================== -->
    <!-- 🟢 SUCCESS PAGE HTML -->
    <!-- ========================================== -->
    <div class="success-card">
        <div class="icon-circle"><i class="fa-solid fa-check"></i></div>
        <h1>Thank You!</h1>
        <p>Your examination has been securely submitted and recorded in the system. Great effort!</p>
        
        <div class="delay-notice">
            <i class="fa-solid fa-hourglass-half"></i>
            <div class="delay-notice-text">
                <strong>Result Pending Setup</strong>
                <span>Your official score will be available in the <b>Examination Center</b> exactly 10 minutes after the exam concludes. Please check back later.</span>
            </div>
        </div>
        
        <a href="dashboard.php?tab=exams" class="btn-return" onclick="<?php unset($_SESSION['exam_submitted']); ?>"><i class="fa-solid fa-arrow-left"></i> Return to Examination Center</a>
    </div>

<?php else: ?>
    <!-- ========================================== -->
    <!-- 🔵 NORMAL EXAM / REVIEW HTML -->
    <!-- ========================================== -->
    <?php if(!$is_review_mode): ?>
    <div id="cheat-warning">
        <i class="fa-solid fa-triangle-exclamation" style="font-size: 80px; margin-bottom: 20px; animation: pulse-warning 1s infinite;"></i>
        <h1 style="font-size: 40px; font-weight: 900; margin-bottom: 10px;">WARNING: TAB SWITCH DETECTED!</h1>
        <p style="font-size: 18px; max-width: 600px; line-height: 1.6; margin-bottom: 30px;">You are not allowed to leave this page or switch tabs during the exam.</p>
        <button onclick="dismissWarning()" style="padding: 15px 40px; font-size: 18px; font-weight: bold; background: #fff; color: #e11d48; border: none; border-radius: 30px; cursor: pointer;">I Understand, Return to Exam</button>
    </div>
    <?php endif; ?>

    <div class="exam-header">
        <div class="exam-title">
            <i class="fa-solid fa-laptop-code" style="color:<?php echo $is_review_mode ? '#10b981' : '#f43f5e'; ?>;"></i> 
            <?php echo htmlspecialchars($ex['title']); ?> <?php echo $is_review_mode ? "(REVIEW MODE)" : ""; ?>
        </div>
        
        <?php if(!$is_review_mode): ?>
        <div class="timer-box" id="timer">
            <i class="fa-solid fa-stopwatch"></i> <span id="time-display">00:00:00</span>
        </div>
        <?php else: ?>
        <div class="review-mode-box">
            <i class="fa-solid fa-eye"></i> Viewing Results
        </div>
        <?php endif; ?>
    </div>

    <div class="main-container">
        <form id="examForm" method="POST" style="display:contents;">
            
        <div class="questions-area">
                <?php 
                $i = 1;
                foreach($questions as $q) {
                    $q_id = $q['id'];
                    $is_first = ($i == 1) ? 'active' : '';
                    
                    $stu_ans = ($is_review_mode && isset($stu_answers[$q_id])) ? trim($stu_answers[$q_id]) : '';
                    $is_q_correct = ($is_review_mode && isset($stu_is_correct[$q_id])) ? $stu_is_correct[$q_id] : 0; 
                    
                    // Status text for Review Mode
                    $status_text = "Not answered yet";
                    $status_class = "";
                    if($is_review_mode) {
                        if($stu_ans == '') { $status_text = "Not answered"; $status_class = "wrong-txt"; }
                        elseif($is_q_correct == 1) { $status_text = "Correct"; $status_class = "correct-txt"; }
                        else { $status_text = "Incorrect"; $status_class = "wrong-txt"; }
                    }

                    echo "<div class='q-card {$is_first}' id='question_{$i}'>
                            <div class='q-layout-wrapper'>
                                
                                <!-- 🪄 NEW: LEFT INFO BOX -->
                                <div class='q-info-box'>
                                    <h3>Question {$i}</h3>
                                    <div class='q-status {$status_class}' id='status_text_{$i}'>{$status_text}</div>
                                    <div class='q-mark'>Mark: " . ($is_q_correct ? "1.00" : "0.00") . " out of 1.00</div>
                                    <button type='button' class='flag-btn' id='flag_btn_{$i}' onclick='toggleFlag({$i}, {$q_id})'><i class='fa-regular fa-flag'></i> Flag question</button>
                                </div>
                                
                                <!-- 🪄 NEW: RIGHT QUESTION CONTENT -->
                                <div class='q-content-box'>
                                    <div class='q-text'>{$q['question_text']}</div>";
                                    
                                    $correct_answer_display = "";
                                    $stu_ans_clean = strtoupper(trim($stu_ans)); 
                                    
                                    if($q['question_type'] == 'multiple_choice') {
                                        $options = ['A' => $q['opt_a'], 'B' => $q['opt_b'], 'C' => $q['opt_c'], 'D' => $q['opt_d']];
                                        if(!empty($q['opt_e'])) $options['E'] = $q['opt_e'];
                                        if(!empty($q['opt_f'])) $options['F'] = $q['opt_f'];
                                        
                                        $correct_original_letter = strtoupper(trim($q['correct_opt'])); 
                                        $correct_answer_text = isset($options[$correct_original_letter]) ? $options[$correct_original_letter] : ''; 

                                        $shuffled = (!$is_review_mode) ? $q['shuffled_options'] : $options;
                                        $display_letter_ascii = 65; 
                                        
                                        foreach($shuffled as $original_letter => $val) {
                                            $display_letter = chr($display_letter_ascii);
                                            
                                            $class = "option-label";
                                            $checked = "";
                                            $icon_html = "";
                                            
                                            if($is_review_mode) {
                                                if($original_letter == $stu_ans_clean) {
                                                    $checked = "checked='checked'";
                                                    if($is_q_correct == 1) { 
                                                        $class .= " correct"; 
                                                        $icon_html = "<i class='fa-solid fa-check' style='margin-left:auto; font-size:22px; color:#10b981;'></i>";
                                                    } else {
                                                        $class .= " wrong-selected"; 
                                                        $icon_html = "<i class='fa-solid fa-xmark' style='margin-left:auto; font-size:22px; color:#e11d48;'></i>";
                                                    }
                                                } 
                                                elseif ($original_letter == $correct_original_letter) {
                                                    $class .= " actual-correct";
                                                    $icon_html = "<i class='fa-solid fa-check' style='margin-left:auto; font-size:20px; color:#10b981; opacity:0.6;'></i>";
                                                }
                                            }
                                            
                                            $disabled = $is_review_mode ? "disabled" : "";
                                            $onclick = !$is_review_mode ? "onclick='markAnswered({$i}, \"{$original_letter}\", {$q_id})'" : "";

                                            echo "<label class='{$class}' id='label_{$i}_{$original_letter}' {$onclick}>
                                                    <input type='radio' name='q_{$q_id}' value='{$original_letter}' id='radio_{$q_id}_{$original_letter}' {$checked} {$disabled}>
                                                    <strong style='font-size:16px; margin-right:8px; width:25px;'>{$display_letter}.</strong> {$val}
                                                    {$icon_html}
                                                  </label>";
                                                  
                                            $display_letter_ascii++;
                                        }
                                        
                                        if($is_review_mode) {
                                            $user_status_msg = empty($stu_ans_clean) ? "<span style='color:#e11d48; font-weight:800;'><i class='fa-solid fa-triangle-exclamation'></i> You skipped this question.</span>" : "<span style='color:#92400e;'><i class='fa-solid fa-user-pen'></i> Your Answer: <b>" . (isset($options[$stu_ans_clean]) ? $options[$stu_ans_clean] : $stu_ans_clean) . "</b></span>";
                                            
                                            $correct_answer_display = "
                                            <div class='correct-answer-box'>
                                                <i class='fa-solid fa-lightbulb' style='font-size:24px; color:#f59e0b;'></i> 
                                                <div>
                                                    <div style='margin-bottom:6px; font-size:13.5px;'>{$user_status_msg}</div>
                                                    <div>The correct answer is: <strong style='color:#b45309; font-size:16px;'>{$correct_answer_text}</strong></div>
                                                </div>
                                            </div>";
                                        }

                                    } elseif($q['question_type'] == 'fill_blank' || $q['question_type'] == 'essay') {
                                        $readonly = $is_review_mode ? "readonly" : "";
                                        $class = "text-input";
                                        
                                        if($is_review_mode) {
                                            $border_color = ($is_q_correct == 1) ? "#10b981" : "#f43f5e";
                                            echo "<textarea class='{$class}' rows='4' {$readonly} style='margin-bottom:15px; border-color: {$border_color}; border-width: 2px; color: #0f172a; font-weight: 600;'>".(empty($stu_ans) ? "NO ANSWER PROVIDED" : $stu_ans)."</textarea>";
                                            $correct_answer_display = "<div class='correct-answer-box'><i class='fa-solid fa-robot' style='font-size:24px; color:#f59e0b;'></i> <div>The expected answer/keywords: <br><strong style='color:#b45309; font-size:16px;'>{$q['correct_text']}</strong></div></div>";
                                        } else {
                                            echo "<textarea name='q_{$q_id}' id='text_{$q_id}' class='{$class}' rows='8' placeholder='Type your answer here...' oninput='markTextAnswered({$i}, this, {$q_id})'></textarea>";
                                        }
                                    }
                                    
                                    echo $correct_answer_display;

                    echo "      </div>
                            </div> <!-- End Layout Wrapper -->

                            <!-- FOOTER BUTTONS -->
                            <div class='q-footer'>
                                <button type='button' class='nav-btn' onclick='prevQuestion({$i})' ".(($i == 1) ? 'disabled' : '')."><i class='fa-solid fa-chevron-left'></i> Previous</button>
                                <span style='font-size:14px; font-weight:700; color:#64748b;'>Page {$i} of {$total_q}</span>
                                <button type='button' class='nav-btn' onclick='nextQuestion({$i})' ".(($i == $total_q) ? 'style="display:none;"' : '').">Next Question <i class='fa-solid fa-chevron-right'></i></button>
                            </div>

                          </div>";
                    $i++;
                }
                ?>
            </div>

            <div class="nav-sidebar">
                <div class="nav-title">Quiz Navigation</div>
                <div class="nav-grid">
                    <?php
                    for($j = 1; $j <= $total_q; $j++) {
                        $active_class = ($j == 1) ? 'active-nav' : '';
                        
                        if($is_review_mode) {
                            $q_id_rev = $questions[$j-1]['id'];
                            $ans_rev = isset($stu_answers[$q_id_rev]) ? $stu_answers[$q_id_rev] : '';
                            $corr_db = isset($stu_is_correct[$q_id_rev]) ? $stu_is_correct[$q_id_rev] : 0;

                            if($ans_rev == '') {
                                $active_class .= " wrong"; 
                            } elseif ($corr_db == 1) {
                                $active_class .= " correct"; 
                            } else {
                                $active_class .= " wrong"; 
                            }
                        }
                        
                        echo "<div class='nav-box {$active_class}' id='nav_{$j}' onclick='goToQuestion({$j})'>{$j}</div>";
                    }
                    ?>
                </div>
                
               <?php if(!$is_review_mode): ?>
                    <!-- 🪄 JIJJIIRAMA: onclick gara 'manualSubmit()' tti jijjiirameera -->
                    <button type="button" class="submit-btn" onclick="manualSubmit()"><i class="fa-solid fa-paper-plane"></i> Finish & Submit</button>
                    <button type="submit" name="submit_exam" id="realSubmitBtn" style="display:none;"></button>
                <?php else: ?>
                    <a href="dashboard.php?tab=exams" class="review-return-btn"><i class="fa-solid fa-arrow-left"></i> Exit Review</a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <?php if(!$is_review_mode): ?>
    <script>
        const examId = <?php echo $e_id; ?>;
        const totalQuestions = <?php echo $total_q; ?>;
        let currentQuestion = 1;

     // ==========================================
        // 🛡️ ANTI-CHEAT & SCREENSHOT PREVENTION (MAGIC LEVEL)
        // ==========================================
        // ==========================================
// 🛡️ ADVANCED SCREENSHOT PROTECTION
// ==========================================

// Create dark overlay
const screenshotOverlay = document.createElement("div");
screenshotOverlay.id = "screenshot-overlay";

screenshotOverlay.style.position = "fixed";
screenshotOverlay.style.top = "0";
screenshotOverlay.style.left = "0";
screenshotOverlay.style.width = "100%";
screenshotOverlay.style.height = "100%";
screenshotOverlay.style.background = "rgba(0,0,0,0.98)";
screenshotOverlay.style.zIndex = "999999";
screenshotOverlay.style.display = "none";
screenshotOverlay.style.justifyContent = "center";
screenshotOverlay.style.alignItems = "center";
screenshotOverlay.style.flexDirection = "column";
screenshotOverlay.style.color = "#fff";
screenshotOverlay.style.fontSize = "28px";
screenshotOverlay.style.fontWeight = "900";
screenshotOverlay.innerHTML = `
    <i class="fa-solid fa-shield-halved" style="font-size:80px; margin-bottom:20px;"></i>
    SCREENSHOT BLOCKED
`;

document.body.appendChild(screenshotOverlay);


        let cheatCount = 0;
        const mainContainer = document.querySelector('.main-container');

        // 1. Tab Switching & Window Focus (Blur Effect)
        document.addEventListener("visibilitychange", function() {
            if (document.hidden) {
                handleCheatAttempt("Tab switch or Window minimized detected!");
            }
        });

        

        window.addEventListener("focus", function() {
            mainContainer.style.filter = "none";
            mainContainer.style.opacity = "1";
        });

        function handleCheatAttempt(reason) {
            cheatCount++;
            if(cheatCount >= 2) { 
                alert("Security Violation: " + reason + " Your exam has been auto-submitted.");
                forceSubmit(); 
            } else { 
                document.getElementById('cheat-warning').style.display = 'flex'; 
            }
        }

        function dismissWarning() { 
            document.getElementById('cheat-warning').style.display = 'none'; 
            mainContainer.style.filter = "none";
            mainContainer.style.opacity = "1";
        }

        // 2. Disable Right Click
        document.addEventListener('contextmenu', event => event.preventDefault());

        // 3. 🪄 MAGIC: KEYBOARD LISTENER (Block Screenshot & Developer Tools)
        document.addEventListener('keyup', function(e) {
            // PrtScn (Print Screen) button
            if (e.key === 'PrintScreen' || e.keyCode === 44) {
                copyToClipboard("Nice try! Screenshots are disabled for this exam.");
                mainContainer.style.display = 'none'; // Fuula dhoksa
                alert("Security Warning: Screenshots are strictly prohibited!");
                setTimeout(() => { mainContainer.style.display = 'flex'; }, 2000); // Sekondii 2 booda deebisa
            }
        });

        document.addEventListener('keydown', function(e) {
            // Block Windows + Shift + S (Snipping Tool)
            if (e.shiftKey && e.metaKey && e.key.toLowerCase() === 's') {
                e.preventDefault();
                handleCheatAttempt("Screen Snipping tool detected!");
            }
            
            // Block Ctrl+P (Print)
            if (e.ctrlKey && e.key.toLowerCase() === 'p') {
                e.preventDefault();
                alert("Printing is disabled.");
            }

            // Block Ctrl+C (Copy), Ctrl+X (Cut)
            if (e.ctrlKey && (e.key.toLowerCase() === 'c' || e.key.toLowerCase() === 'x')) {
                e.preventDefault();
            }

            // Block Developer Tools (F12, Ctrl+Shift+I, Ctrl+U)
            if (e.key === 'F12' || e.keyCode === 123) {
                e.preventDefault();
            }
            if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'i') {
                e.preventDefault();
            }
            if (e.ctrlKey && e.key.toLowerCase() === 'u') {
                e.preventDefault();
            }
        });

        // 🪄 Helper function to clear clipboard when they try to screenshot
        function copyToClipboard(text) {
            let dummy = document.createElement("textarea");
            document.body.appendChild(dummy);
            dummy.value = text;
            dummy.select();
            document.execCommand("copy");
            document.body.removeChild(dummy);
        }

        // TIMER
        let timeLeftInSeconds = <?php echo $seconds_left; ?>; 
        const timerDisplay = document.getElementById('time-display');
        const timerBox = document.getElementById('timer');

        if(timeLeftInSeconds <= 0) forceSubmit();

        const countdown = setInterval(function() {
            timeLeftInSeconds--;
            if (timeLeftInSeconds <= 0) {
                clearInterval(countdown);
                forceSubmit();
            } else {
                const hours = Math.floor(timeLeftInSeconds / 3600);
                const minutes = Math.floor((timeLeftInSeconds % 3600) / 60);
                const seconds = timeLeftInSeconds % 60;
                timerDisplay.innerHTML = (hours < 10 ? "0" + hours : hours) + ":" + (minutes < 10 ? "0" + minutes : minutes) + ":" + (seconds < 10 ? "0" + seconds : seconds);
                if(timeLeftInSeconds < 300) timerBox.classList.add('warning');
            }
        }, 1000);

        // LOCAL STORAGE
        window.addEventListener('DOMContentLoaded', () => {
            let savedAnswers = JSON.parse(localStorage.getItem(`eplms_exam_${examId}_answers`)) || {};
            for (const [qId, ans] of Object.entries(savedAnswers)) {
                let radio = document.getElementById(`radio_${qId}_${ans}`);
                if(radio) {
                    radio.checked = true;
                    let qNum = radio.closest('.q-card').id.split('_')[1];
                    markAnswered(qNum, ans, qId, false);
                } else {
                    let txtInput = document.getElementById(`text_${qId}`);
                    if(txtInput) {
                        txtInput.value = ans;
                        let qNum = txtInput.closest('.q-card').id.split('_')[1];
                        markTextAnswered(qNum, txtInput, qId, false);
                    }
                }
            }
        });

        function saveToLocal(qId, val) {
            let saved = JSON.parse(localStorage.getItem(`eplms_exam_${examId}_answers`)) || {};
            saved[qId] = val; localStorage.setItem(`eplms_exam_${examId}_answers`, JSON.stringify(saved));
        }

        function markAnswered(qNum, letter, qId, doSave = true) {
            document.querySelectorAll(`[id^='label_${qNum}_']`).forEach(lbl => lbl.classList.remove('selected'));
            let clickedLabel = document.getElementById(`label_${qNum}_${letter}`);
            if(clickedLabel) clickedLabel.classList.add('selected');
            document.getElementById(`radio_${qId}_${letter}`).checked = true;
            document.getElementById(`nav_${qNum}`).classList.add('answered');
            if(doSave) saveToLocal(qId, letter);
        }
        // 🪄 MAGIC FLAG FUNCTION
        function toggleFlag(qNum, qId) {
            let btn = document.getElementById(`flag_btn_${qNum}`);
            let navBox = document.getElementById(`nav_${qNum}`);
            let icon = btn.querySelector('i');
            
            let flags = JSON.parse(localStorage.getItem(`eplms_exam_${examId}_flags`)) || {};

            if(btn.classList.contains('flagged')) {
                // Remove Flag
                btn.classList.remove('flagged');
                icon.className = 'fa-regular fa-flag';
                btn.innerHTML = `<i class='fa-regular fa-flag'></i> Flag question`;
                navBox.style.borderTop = '2px solid #e2e8f0'; // Remove red top border
                delete flags[qId];
            } else {
                // Add Flag
                btn.classList.add('flagged');
                icon.className = 'fa-solid fa-flag';
                btn.innerHTML = `<i class='fa-solid fa-flag'></i> Remove flag`;
                navBox.style.borderTop = '4px solid #f43f5e'; // Add red top border on nav
                flags[qId] = true;
            }
            
            localStorage.setItem(`eplms_exam_${examId}_flags`, JSON.stringify(flags));
        }

        // Restore Flags on Load
        window.addEventListener('DOMContentLoaded', () => {
            // Restore Answers (Existing code)
            let savedAnswers = JSON.parse(localStorage.getItem(`eplms_exam_${examId}_answers`)) || {};
            for (const [qId, ans] of Object.entries(savedAnswers)) {
                let radio = document.getElementById(`radio_${qId}_${ans}`);
                if(radio) {
                    radio.checked = true;
                    let qNum = radio.closest('.q-card').id.split('_')[1];
                    markAnswered(qNum, ans, qId, false);
                } else {
                    let txtInput = document.getElementById(`text_${qId}`);
                    if(txtInput) {
                        txtInput.value = ans;
                        let qNum = txtInput.closest('.q-card').id.split('_')[1];
                        markTextAnswered(qNum, txtInput, qId, false);
                    }
                }
            }

            // Restore Flags (New code)
            let savedFlags = JSON.parse(localStorage.getItem(`eplms_exam_${examId}_flags`)) || {};
            for (const [qId, isFlagged] of Object.entries(savedFlags)) {
                if(isFlagged) {
                    // Find the button and simulate a click to restore state
                    let btn = document.querySelector(`button[onclick*="toggleFlag("][onclick*=", ${qId})"]`);
                    if(btn) {
                        let qNum = btn.id.split('_')[2];
                        btn.classList.add('flagged');
                        btn.innerHTML = `<i class='fa-solid fa-flag'></i> Remove flag`;
                        document.getElementById(`nav_${qNum}`).style.borderTop = '4px solid #f43f5e';
                    }
                }
            }
        });

        function markTextAnswered(qNum, inputElement, qId, doSave = true) {
            let val = inputElement.value;
            let navBox = document.getElementById(`nav_${qNum}`);
            if(val.trim().length > 0) navBox.classList.add('answered'); else navBox.classList.remove('answered');
            if(doSave) saveToLocal(qId, val);
        }

        function goToQuestion(qNum) {
            document.querySelectorAll('.q-card').forEach(card => card.classList.remove('active'));
            document.getElementById(`question_${qNum}`).classList.add('active');
            document.querySelectorAll('.nav-box').forEach(box => box.classList.remove('active-nav'));
            document.getElementById(`nav_${qNum}`).classList.add('active-nav');
        }

        function nextQuestion(current) { if(current < totalQuestions) { goToQuestion(current + 1); } }
        function prevQuestion(current) { if(current > 1) { goToQuestion(current - 1); } }

// 🪄 MAGIC SUBMIT LOGIC (With Confirmation)
    function manualSubmit() {
        // Barataan gaaffii meeqa akka deebise lakkaa'uu
        let totalAnswered = document.querySelectorAll('.nav-box.answered').length;
        let totalQ = <?php echo $total_q; ?>;
        
        let confirmMsg = "";
        
        if(totalAnswered < totalQ) {
            // Yoo gaaffiin hafe jiraate
            let missing = totalQ - totalAnswered;
            confirmMsg = `⚠️ WARNING: You still have ${missing} unanswered question(s)!\n\nAre you absolutely sure you want to finish and submit this exam?`;
        } else {
            // Yoo hunda deebise
            confirmMsg = `You have answered all questions.\n\nAre you ready to submit your exam?`;
        }

        if(confirm(confirmMsg)) {
            forceSubmit();
        }
    }

  // 🪄 MAGIC SUBMIT LOGIC (With Custom Confirmation)
        function manualSubmit() {
            // Barataan gaaffii meeqa akka deebise lakkaa'uu
            let totalAnswered = document.querySelectorAll('.nav-box.answered').length;
            let totalQ = <?php echo $total_q; ?>;
            
            let missing = totalQ - totalAnswered;
            
            if(missing > 0) {
                // Yoo gaaffiin hafe jiraate (Custom confirmation window)
                let confirmSubmit = confirm(`⚠️ WARNING: You still have ${missing} unanswered question(s)!\n\nAre you absolutely sure you want to finish and submit this exam early?`);
                if(confirmSubmit) {
                    forceSubmit();
                }
            } else {
                // Yoo hunda deebiseera ta'e
                let confirmSubmit = confirm(`✅ You have answered all ${totalQ} questions.\n\nAre you ready to submit your exam?`);
                if(confirmSubmit) {
                    forceSubmit();
                }
            }
        }

        function forceSubmit() { 
            // Tab switch warning akka hin dhufne dhorka
            document.getElementById('realSubmitBtn').click(); 
        } 
        window.addEventListener("blur", () => {

    screenshotOverlay.style.display = "flex";

    mainContainer.style.filter = "blur(30px)";
    mainContainer.style.opacity = "0";

});

window.addEventListener("focus", () => {

    screenshotOverlay.style.display = "none";

    mainContainer.style.filter = "none";
    mainContainer.style.opacity = "1";

});   
    </script>
    <?php else: ?>
    <script>
        const totalQuestions = <?php echo $total_q; ?>;
        function goToQuestion(qNum) {
            document.querySelectorAll('.q-card').forEach(card => card.classList.remove('active'));
            document.getElementById(`question_${qNum}`).classList.add('active');
            document.querySelectorAll('.nav-box').forEach(box => box.classList.remove('active-nav'));
            document.getElementById(`nav_${qNum}`).classList.add('active-nav');
        }
        function nextQuestion(current) { if(current < totalQuestions) { goToQuestion(current + 1); } }
        function prevQuestion(current) { if(current > 1) { goToQuestion(current - 1); } }
    </script>
    <?php endif; ?>

<?php endif; ?>
</body>
</html>