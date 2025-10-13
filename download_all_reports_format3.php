<?php
session_start();
require('fpdf.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$school_id = $_SESSION['school_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
if (!$class_id) { die('Invalid class ID'); }

// Current term
$stmt = $conn->prepare("SELECT id FROM terms WHERE school_id = ? AND is_current = 1");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_row = $stmt->get_result()->fetch_assoc();
if (!$term_row) { die('No active term found'); }
$current_term_id = $term_row['id'];

// School details
$stmt = $conn->prepare("SELECT s.school_name, s.motto, s.email, s.location, s.phone, s.badge, t.name, t.year, t.next_term_start_date FROM schools s LEFT JOIN terms t ON s.id = t.school_id AND t.id = ? WHERE s.id = ?");
$stmt->bind_param("ii", $current_term_id, $school_id);
$stmt->execute();
$school_details = $stmt->get_result()->fetch_assoc();

// Get next class information
$next_class_query = "SELECT c2.name as next_class_name 
                     FROM classes c1 
                     JOIN classes c2 ON c1.id + 1 = c2.id 
                     WHERE c1.id = ? AND c1.school_id = ?";
$stmt = $conn->prepare($next_class_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$next_class_result = $stmt->get_result();
$next_class = $next_class_result->fetch_assoc();

// Grading scale
$grading_scale = [];
$stmt = $conn->prepare("SELECT grade, min_score, max_score, remarks FROM grading_scales WHERE school_id = ? ORDER BY min_score DESC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $grading_scale[$row['grade']] = [ 'min_score' => $row['min_score'], 'max_score' => $row['max_score'], 'remarks' => $row['remarks'] ];
}

// Exam categories
$exam_categories = [];
$stmt = $conn->prepare("SELECT DISTINCT category, exam_type FROM exams WHERE school_id = ? AND term_id = ? ORDER BY category, exam_type");
$stmt->bind_param("ii", $school_id, $current_term_id);
$stmt->execute();
$exam_categories_result = $stmt->get_result();
while ($row = $exam_categories_result->fetch_assoc()) {
    if (!isset($exam_categories[$row['category']])) { $exam_categories[$row['category']] = []; }
    $exam_categories[$row['category']][] = $row['exam_type'];
}

// Class name
$stmt = $conn->prepare("SELECT name FROM classes WHERE id = ? AND school_id = ?");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class_row = $stmt->get_result()->fetch_assoc();
$class_name = str_replace(' ', '_', $class_row['name']);

// Students in class
$stmt = $conn->prepare("SELECT id, firstname, lastname FROM students WHERE class_id = ? AND school_id = ? ORDER BY lastname, firstname");
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$students_result = $stmt->get_result();

function getGradeFromPercentage($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) { if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) { return $grade; } }
    return 'N/A';
}
function getRemarksFromPercentage($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) { if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) { return $scale['remarks']; } }
    return 'N/A';
}
function getTeacherInitialsGlobal($fullname) { $names = explode(' ', trim($fullname)); $initials = ''; foreach ($names as $name) { if (!empty($name)) { $initials .= strtoupper(substr($name, 0, 1)); } } return $initials; }

class PDF extends FPDF {
    protected $school_details;
    function __construct($school_details) { parent::__construct(); $this->school_details = $school_details; }
    function Header() {
        if ($this->PageNo() == 1) {
            $badge_width = 35; $badge_height = 35;
            if (!empty($this->school_details['badge']) && file_exists('uploads/' . basename($this->school_details['badge']))) { $this->Image('uploads/' . basename($this->school_details['badge']), 10, 6, $badge_width, $badge_height); }
            $this->SetY(14); $this->SetTextColor(0, 0, 0); $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 6, $this->school_details['school_name'], 0, 1, 'C');
            $this->SetFont('Arial', 'I', 9); if (!empty($this->school_details['motto'])) { $this->Cell(0, 5, 'Motto: ' . $this->school_details['motto'], 0, 1, 'C'); }
            $this->SetFont('Arial', '', 9); if (!empty($this->school_details['email'])) { $this->Cell(0, 5, 'Email: ' . $this->school_details['email'], 0, 1, 'C'); }
            $location_phone = trim(($this->school_details['location'] ?? '') . (empty($this->school_details['phone']) ? '' : ' | Phone: ' . $this->school_details['phone'])); if (!empty($location_phone)) { $this->Cell(0, 5, $location_phone, 0, 1, 'C'); }
            $this->SetFont('Arial', 'B', 11); $term_label = ''; if (!empty($this->school_details['name'])) { $term_label = ' - ' . $this->school_details['name']; if (!empty($this->school_details['year'])) { $term_label .= ' (' . $this->school_details['year'] . ')'; } }
            $this->Cell(0, 6, 'End of Term Report' . $term_label, 0, 1, 'C');
            $this->SetLineWidth(0.5); $this->Ln(3); $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY()); $this->Ln(6);
        }
    }
    function Footer() { $this->SetY(-12); $this->SetTextColor(0, 0, 0); $this->SetFont('Arial', 'I', 8); $this->Cell(0, 10, $this->school_details['email'], 0, 0, 'C'); }
    private function getGradeFromPercentage($percentage, $grading_scale) { return getGradeFromPercentage($percentage, $grading_scale); }
    private function getRemarksFromPercentage($percentage, $grading_scale) { return getRemarksFromPercentage($percentage, $grading_scale); }
    private function getTeacherInitials($fullname) { return getTeacherInitialsGlobal($fullname); }
    function AddDescriptorTable() { $this->Ln(10); $this->SetFont('Arial', 'B', 12); $this->Cell(0, 8, 'DESCRIPTOR EXPLANATION', 0, 1, 'C'); $this->SetFont('Arial', 'B', 10); $this->SetFillColor(240, 240, 240); $col_descriptor = 40; $col_score_range = 30; $col_meaning = 120; $this->Cell($col_descriptor, 8, 'DESCRIPTOR', 1, 0, 'C', true); $this->Cell($col_score_range, 8, 'SCORE RANGE', 1, 0, 'C', true); $this->Cell($col_meaning, 8, 'MEANING', 1, 1, 'C', true); $this->SetFont('Arial', '', 9); $rows = [['Exceptional','2.5 - 3.0','Exceptional performance demonstrating mastery of all competencies'],['Outstanding','2.0 - 2.4','Good performance with solid understanding of most competencies'],['Satisfactory','1.5 - 1.9','Acceptable performance meeting basic requirements'],['Basic','1.0 - 1.4','Minimal performance requiring additional support'],['Elementary','0.0 - 0.9','Beginning level requiring significant improvement']]; foreach ($rows as $r) { $this->Cell($col_descriptor, 6, $r[0], 1, 0, 'C'); $this->Cell($col_score_range, 6, $r[1], 1, 0, 'C'); $this->Cell($col_meaning, 6, $r[2], 1, 1, 'L'); } }
    function AddGradingScaleTable($grading_scale) { $this->Ln(2); $left_margin = 10; $right_margin = 10; $table_width = $this->GetPageWidth() - ($left_margin + $right_margin); $this->SetX($left_margin); $max_cols = 6; $grades = []; $ranges = []; foreach ($grading_scale as $grade => $scale) { if (count($grades) >= $max_cols) { break; } $grades[] = (string)$grade; $min_int = (string)intval(round((float)$scale['min_score'])); $max_int = (string)intval(round((float)$scale['max_score'])); $ranges[] = $min_int.'-'.$max_int; } $num_cols = max(1, min($max_cols, count($grades))); $col_w = floor($table_width / $num_cols); $remaining = $table_width - ($col_w * $num_cols); $this->SetFont('Arial', 'B', 7.5); $this->SetFillColor(240, 240, 240); $cell_h = 5; for ($i = 0; $i < $num_cols; $i++) { $w = $col_w + ($i == ($num_cols - 1) ? $remaining : 0); $this->Cell($w, $cell_h, isset($grades[$i]) ? $grades[$i] : '', 1, 0, 'C', true); } $this->Ln(); $this->SetFont('Arial', '', 7.5); for ($i = 0; $i < $num_cols; $i++) { $w = $col_w + ($i == ($num_cols - 1) ? $remaining : 0); $this->Cell($w, $cell_h, isset($ranges[$i]) ? $ranges[$i] : '', 1, 0, 'C'); } $this->Ln(1); }
    function AddNextTermStartDate() { if (!empty($this->school_details['next_term_start_date']) && $this->school_details['next_term_start_date'] !== '0000-00-00') { if ($this->GetPageHeight() - $this->GetY() - 15 < 7) { return; } $this->Ln(1); $table_width = 80; $table_height = 5; $x_start = $this->GetX() + ($this->GetPageWidth() - $table_width) / 2; $y_start = $this->GetY(); $date_text = date('D, d M Y', strtotime($this->school_details['next_term_start_date'])); $full_text = 'Next Term Begins: ' . $date_text; $this->SetFont('Arial', 'B', 8); $this->SetTextColor(0, 0, 0); $this->SetDrawColor(0, 0, 0); $this->SetFillColor(240,240,240); $this->SetLineWidth(0.3); $this->SetXY($x_start, $y_start); $this->Cell($table_width, $table_height, $full_text, 1, 0, 'C', true); $this->SetY($y_start + $table_height + 1); } }
    function AddRemarksGuideCompact() { $left_margin = 10; $right_margin = 10; $table_width = $this->GetPageWidth() - ($left_margin + $right_margin); $this->SetX($left_margin); $this->Ln(2); $this->SetFont('Arial', 'B', 11); $this->Cell(0, 7, 'PERFORMANCE REMARKS GUIDE', 0, 1, 'C'); $this->SetFont('Arial', 'B', 9); $this->SetFillColor(240,240,240); $col_range = 40; $col_remark = $table_width - $col_range; $row_h = 6; $this->SetX($left_margin); $this->Cell($col_range, $row_h, 'RANGE (%)', 1, 0, 'C', true); $this->Cell($col_remark, $row_h, 'REMARK', 1, 1, 'C', true); $this->SetFont('Arial', '', 8.5); $rows = [['80 - 100','Good work done'],['60 - 79','Keep it up'],['50 - 59','Fair - improve'],['40 - 49','Needs improvement'],['0 - 39','Serious improvement needed']]; foreach ($rows as $r) { $this->SetX($left_margin); $this->Cell($col_range, $row_h, $r[0], 1, 0, 'C'); $this->Cell($col_remark, $row_h, $r[1], 1, 1, 'L'); } }
    function AddSummaryTableFormat3($exam_categories, $exam_results, $grading_scale) {
        $this->SetFillColor(220, 220, 220); $this->SetTextColor(0, 0, 0);
        $left_margin = 10; $right_margin = 10; $table_width = $this->GetPageWidth() - ($left_margin + $right_margin); $this->SetX($left_margin);
        $this->SetFont('Arial', 'B', 8);
        // Columns sum to 190 (page width 210 - margins 10+10)
        // SUBJECT | M.O.T | E.O.T | AVG SCORE | Total | GRADE | REMARKS | EXPLANATION | TEACHER
        $col_subject = 38; $col_mot = 14; $col_eot = 14; $col_avg = 20; $col_total = 14; $col_grade = 12; $col_remarks = 26; $col_expl = 30; $col_teacher = 20; $row_h = 6.5;
        // Header row with stacked labels for M.O.T and E.O.T
        $header_height = 10; $line_h = $header_height / 2;
        $header_y = $this->GetY(); $x = $this->GetX();
        // SUBJECT
        $this->Cell($col_subject, $header_height, 'SUBJECT', 1, 0, 'C', true);
        // M.O.T with (3) stacked
        $this->SetXY($x + $col_subject, $header_y);
        $this->MultiCell($col_mot, $line_h, "M.O.T\n(3)", 1, 'C', true);
        // E.O.T with (3) stacked
        $this->SetXY($x + $col_subject + $col_mot, $header_y);
        $this->MultiCell($col_eot, $line_h, "E.O.T\n(3)", 1, 'C', true);
        // Move X to after E.O.T cell to continue
        $this->SetXY($x + $col_subject + $col_mot + $col_eot, $header_y);
        $this->Cell($col_avg, $header_height, 'AVG SCORE', 1, 0, 'C', true);
        $this->Cell($col_total, $header_height, 'Total', 1, 0, 'C', true);
        $this->Cell($col_grade, $header_height, 'GRADE', 1, 0, 'C', true);
        $this->Cell($col_remarks, $header_height, 'DESCRIPTOR', 1, 0, 'C', true);
        $this->Cell($col_expl, $header_height, 'REMARKS', 1, 0, 'C', true);
        $this->Cell($col_teacher, $header_height, 'TEACHER', 1, 1, 'C', true);
        $this->SetFont('Arial', '', 8);

        $total_subject_marks = 0; $subject_count = 0;
        // Reorder subjects so specified ones appear last
        $endSubjects = ['Tawheed','Fiqhi','Hadith','Quran'];
        $subjects = array_keys($exam_results);
        $mainSubjects = array_values(array_filter($subjects, function($s) use ($endSubjects) { return !in_array($s, $endSubjects); }));
        $tailSubjects = array_values(array_filter($subjects, function($s) use ($endSubjects) { return in_array($s, $endSubjects); }));
        $orderedSubjects = array_merge($mainSubjects, $tailSubjects);
        $drawnTailSeparator = false;
        foreach ($orderedSubjects as $subject) { $data = $exam_results[$subject];
            // Aggregate activities -> M.O.T out of 20; Exams -> E.O.T raw scaled to 80
            $activity_score_sum = 0; $activity_max_sum = 0; $has_activity = false;
            $exam_score_sum = 0; $exam_max_sum = 0; $has_exam = false;
            foreach ($data['categories'] as $category => $cdata) {
                $types = $exam_categories[$category] ?? [];
                $score = $cdata['score']; $max_score = $cdata['max_score'];
                if (!empty($types) && $types[0] === 'activity') { $has_activity = true; $activity_max_sum += $max_score; if ($score !== null) { $activity_score_sum += $score; } }
                else { $has_exam = true; $exam_max_sum += $max_score; if ($score !== null) { $exam_score_sum += $score; } }
            }
            $has_activity_assigned = ($activity_max_sum > 0);
            $has_exam_assigned = ($exam_max_sum > 0);
            
            // Calculate out of 3 directly from raw scores to avoid precision issues
            $mot3_val = $has_activity_assigned ? round(($activity_score_sum * 3) / max(1,$activity_max_sum), 1) : 0;
            $eot3_val = $has_exam_assigned ? round(($exam_score_sum * 3) / max(1,$exam_max_sum), 1) : 0;
            
            // Calculate percentages for averaging
            $mot_percentage = $has_activity_assigned ? ($activity_score_sum / max(1,$activity_max_sum)) * 100 : 0;
            $eot_percentage = $has_exam_assigned ? ($exam_score_sum / max(1,$exam_max_sum)) * 100 : 0;
            
            // Display values with '-' when no activity assigned
            $mot_display = $has_activity_assigned ? (string)$mot3_val : '-';
            $eot_display = $has_exam_assigned ? (string)$eot3_val : '-';
            
            // Calculate average percentage first, then convert to out of 3
            if (!$has_activity_assigned && $has_exam_assigned) {
                $avg_percentage = $eot_percentage;
            } elseif ($has_activity_assigned && !$has_exam_assigned) {
                $avg_percentage = $mot_percentage;
            } elseif ($has_activity_assigned && $has_exam_assigned) {
                $avg_percentage = ($mot_percentage + $eot_percentage) / 2;
            } else {
                $avg_percentage = 0;
            }
            
            // Convert average percentage to out of 3 and round it
            $avg_score = round(($avg_percentage / 100) * 3, 1);
            
            // Total should be calculated from the rounded average score (what's displayed)
            $total = (int)round(($avg_score / 3) * 100);
            $grade = $this->getGradeFromPercentage($total, $grading_scale); $remarks = $this->getRemarksFromPercentage($total, $grading_scale);
            // Explanation mapping based on percentage
            if ($total >= 80) { $explanation = 'Good work done'; }
            elseif ($total >= 60) { $explanation = 'Keep it up'; }
            elseif ($total >= 50) { $explanation = 'Fair - improve'; }
            elseif ($total >= 40) { $explanation = 'Needs improvement'; }
            else { $explanation = 'Serious improvement needed'; }
            $teacher_initials = $this->getTeacherInitials($data['teacher'] ?? '');
            // Draw bold separator when the first tail subject is about to render
            if (!$drawnTailSeparator && in_array($subject, $endSubjects)) { 
                $this->SetLineWidth(0.6); 
                $line_start = $left_margin; 
                $line_end = $left_margin + $col_subject + $col_mot + $col_eot + $col_avg + $col_total + $col_grade + $col_remarks + $col_expl + $col_teacher; 
                $this->Line($line_start, $this->GetY(), $line_end, $this->GetY()); 
                $this->SetLineWidth(0.3); 
                $drawnTailSeparator = true; 
            }
            $this->Cell($col_subject, $row_h, $subject, 1, 0, 'L');
            $this->Cell($col_mot, $row_h, $mot_display, 1, 0, 'C');
            $this->Cell($col_eot, $row_h, $eot_display, 1, 0, 'C');
            $this->Cell($col_avg, $row_h, (string)$avg_score, 1, 0, 'C');
            $this->Cell($col_total, $row_h, (string)$total, 1, 0, 'C');
            $this->Cell($col_grade, $row_h, $grade, 1, 0, 'C');
            $this->Cell($col_remarks, $row_h, $remarks, 1, 0, 'L');
            $this->Cell($col_expl, $row_h, $explanation, 1, 0, 'L');
            $this->Cell($col_teacher, $row_h, $teacher_initials, 1, 1, 'C');
            $total_subject_marks += $total; $subject_count++;
        }
        if ($subject_count < 13) { $this->Ln(max(0, (13 - $subject_count - 1))); } else { $this->Ln(1); }
        return $subject_count > 0 ? round($total_subject_marks / $subject_count, 1) : 0;
    }
}

$temp_dir = 'temp_reports_format3_' . time();
if (!mkdir($temp_dir, 0777, true)) { die('Failed to create temporary directory'); }

$success_count = 0; $error_count = 0; $error_log = [];

while ($student = $students_result->fetch_assoc()) {
    try {
        // Student details
        $stmt = $conn->prepare("SELECT s.id, s.firstname, s.lastname, c.name, s.stream, s.image, s.gender, s.lin_number, s.admission_number FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
        $stmt->bind_param("ii", $student['id'], $school_id);
        $stmt->execute();
        $student_details = $stmt->get_result()->fetch_assoc();
        if (!$student_details) { throw new Exception('Student details not found'); }

        // Summary results structure for format3
        $summary_results_query = "SELECT s.subject_name, e.exam_type, e.category, er.score, e.max_score, u.firstname AS teacher_firstname, u.lastname AS teacher_lastname FROM student_subjects ss JOIN subjects s ON ss.subject_id = s.subject_id CROSS JOIN ( SELECT DISTINCT category, exam_type, exam_id, max_score FROM exams WHERE school_id = ? AND term_id = ? ) e INNER JOIN exam_subjects es ON es.exam_id = e.exam_id AND es.subject_id = s.subject_id LEFT JOIN exam_results er ON er.exam_id = e.exam_id AND er.student_id = ? AND er.subject_id = s.subject_id AND er.school_id = ? LEFT JOIN ( SELECT subject_id, user_id FROM teacher_subjects WHERE is_class_teacher = 0 AND class_id = ? ) ts ON s.subject_id = ts.subject_id LEFT JOIN ( SELECT subject_id, user_id FROM teacher_subjects WHERE is_class_teacher = 1 AND class_id = ? ) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id AND u.role = 'teacher' WHERE s.class_id = ? AND ss.student_id = ? ORDER BY s.subject_name, e.category, e.exam_type";
        $stmt = $conn->prepare($summary_results_query);
        $stmt->bind_param("iiiiiiii", $school_id, $current_term_id, $student['id'], $school_id, $class_id, $class_id, $class_id, $student['id']);
        $stmt->execute();
        $summary_results = $stmt->get_result();
        $summary_exam_results = [];
        while ($row = $summary_results->fetch_assoc()) {
            $subject = $row['subject_name']; $category = $row['category']; $exam_type = $row['exam_type'];
            if (!isset($summary_exam_results[$subject])) { $summary_exam_results[$subject] = [ 'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']), 'categories' => [] ]; }
            if (!isset($summary_exam_results[$subject]['categories'][$category])) { $summary_exam_results[$subject]['categories'][$category] = [ 'score' => null, 'max_score' => $row['max_score'] ]; }
            if ($row['score'] !== null) { $summary_exam_results[$subject]['categories'][$category]['score'] = $row['score']; }
        }

        // Build PDF
        $pdf = new PDF($school_details);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        // Student info block
        $pdf->SetFont('Arial', 'B', 12); $pdf->Cell(0, 6, 'Student Information', 0, 1);
        $pdf->SetFont('Arial', '', 10); $line_h = 5; $label_w = 20; $left_w = 120; $right_w = 60; $x_start = $pdf->GetX(); $y_start = $pdf->GetY();
        $left = [ 'Name' => $student_details['firstname'] . ' ' . $student_details['lastname'], 'Class' => ($student_details['name'] ?? ''), 'Stream' => ($student_details['stream'] ?? '') ];
        $right = [ 'Gender' => ($student_details['gender'] ?? ''), 'Adm No.' => ($student_details['admission_number'] ?? '') ];
        $current_y = $y_start; foreach ($left as $label => $value) { $pdf->SetXY($x_start, $current_y); $pdf->Cell($label_w, $line_h, $label . ':', 0, 0); $pdf->Cell($left_w - $label_w, $line_h, $value, 0, 0); $current_y += $line_h; }
        $current_y = $y_start; $right_x = $x_start + $left_w - 20; foreach ($right as $label => $value) { $pdf->SetXY($right_x, $current_y); $pdf->Cell($label_w, $line_h, $label . ':', 0, 0); $pdf->Cell($right_w - $label_w, $line_h, $value, 0, 0); $current_y += $line_h; }
        if (!empty($student_details['image'])) { $img_path = 'uploads/' . basename($student_details['image']); if (file_exists($img_path)) { $pdf->Image($img_path, $pdf->GetPageWidth() - 40, $y_start - 10, 25, 25); } }
        $max_y = max($current_y, $y_start + 15); $pdf->SetXY($x_start, $max_y + 1);

        // Summary table (format 3)
        $summary_average = $pdf->AddSummaryTableFormat3($exam_categories, $summary_exam_results, $grading_scale);

        // Performance summary line (compact)
        $pdf->Ln(2); $pdf->SetFont('Arial', '', 10); $overall_grade = getGradeFromPercentage($summary_average, $grading_scale); $overall_comment = getRemarksFromPercentage($summary_average, $grading_scale);
        $pdf->Cell(45, 5, 'Overall Percentage:', 0, 0, 'R'); $pdf->Cell(25, 5, sprintf("%.1f%%", $summary_average), 0, 0); $pdf->Cell(30, 5, 'Overall Grade:', 0, 0, 'R'); $pdf->Cell(18, 5, $overall_grade, 0, 0); $pdf->Cell(45, 5, 'Overall Comment:', 0, 0, 'R'); $pdf->MultiCell(0, 5, $overall_comment, 0, 'L');

        // Comments lookup based on average percentage
        $avg_for_comments = (int) round(min(100, max(0, $summary_average)));
        $comments_query = "SELECT id, type, comment, min_score, max_score FROM class_comment_templates WHERE school_id = ? AND (class_id = ? OR class_id IS NULL) AND ? BETWEEN min_score AND max_score ORDER BY class_id DESC, type";
        $stmt = $conn->prepare($comments_query);
        $stmt->bind_param("iid", $school_id, $class_id, $avg_for_comments);
        $stmt->execute();
        $comments_result = $stmt->get_result();
        $class_teacher_comment = 'No comments available for this performance level';
        $head_teacher_comment = 'No comments available for this performance level';
        while ($row = $comments_result->fetch_assoc()) { if ($row['type'] == 'class_teacher') { $class_teacher_comment = $row['comment']; } elseif ($row['type'] == 'head_teacher') { $head_teacher_comment = $row['comment']; } }

        // Render comments and signatures (compact)
        $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 5, 'Class Teacher\'s Comment:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(0, 5, mb_strimwidth($class_teacher_comment, 0, 180, '...'), 0, 'L');
        $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(45, 5, 'Class Teacher Signature:', 0, 0, 'L'); $pdf->Cell(80, 5, '____________________________', 0, 1, 'L');
        $pdf->Ln(1); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(0, 5, 'Head Teacher\'s Comment:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 9); $pdf->MultiCell(0, 5, mb_strimwidth($head_teacher_comment, 0, 180, '...'), 0, 'L');
        $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(45, 5, 'Head Teacher Signature:', 0, 0, 'L'); $pdf->Cell(80, 5, '_____________________________', 0, 1, 'L'); $pdf->Ln(3);

        // Add promotion and next term tables on the same line
        $pdf->Ln(1);
        $table_width = 80;
        $table_height = 5;
        $y_start = $pdf->GetY();
        
        // Promotion table (left side) - only for Third Term
        if ($school_details['name'] === 'Third Term' && $next_class) {
            $promotion_text = 'Promoted To: ' . $next_class['next_class_name'];
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetFillColor(240,240,240);
            $pdf->SetLineWidth(0.3);
            $pdf->SetXY(10, $y_start);
            $pdf->Cell($table_width, $table_height, $promotion_text, 1, 0, 'C', true);
        }
        
        // Next term start date (right side)
        if (!empty($school_details['next_term_start_date']) && $school_details['next_term_start_date'] !== '0000-00-00') {
            $date_text = date('D, d M Y', strtotime($school_details['next_term_start_date']));
            $next_term_text = 'Next Term Begins: ' . $date_text;
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetFillColor(240,240,240);
            $pdf->SetLineWidth(0.3);
            $right_x = $pdf->GetPageWidth() - 10 - $table_width;
            $pdf->SetXY($right_x, $y_start);
            $pdf->Cell($table_width, $table_height, $next_term_text, 1, 0, 'C', true);
        }
        
        $pdf->SetY($y_start + $table_height + 1);

        // Keep compact: only grading scale, and minimize spacing to fit one page; no separate remarks/descriptor tables
        $pdf->AddGradingScaleTable($grading_scale);

        // Save PDF
        $filename = $temp_dir . '/report_card_f3_' . str_replace(' ', '_', $student['firstname'] . '_' . $student['lastname']) . '.pdf';
        $pdf->Output('F', $filename);
        $success_count++;
    } catch (Exception $e) { $error_count++; $error_log[] = 'Error processing ' . $student['lastname'] . ', ' . $student['firstname'] . ': ' . $e->getMessage(); continue; }
}

// Zip and stream
$zip = new ZipArchive();
$zipName = 'class_reports_format3_' . $class_name . '_' . date('Y-m-d_H-i-s') . '.zip';
while (ob_get_level()) { ob_end_clean(); }
if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    $files = glob($temp_dir . '/*');
    foreach ($files as $file) { if (file_exists($file) && is_file($file) && filesize($file) > 0) { $zip->addFile($file, basename($file)); } }
    $zip->close();
    foreach ($files as $file) { @unlink($file); }
    @rmdir($temp_dir);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=' . basename($zipName));
    header('Content-Length: ' . filesize($zipName));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $f = fopen($zipName, 'rb'); while (!feof($f)) { echo fread($f, 8192); flush(); } fclose($f); @unlink($zipName); $conn->close(); exit();
} else { die('Failed to create ZIP archive'); }

?>


