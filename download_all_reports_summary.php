<?php
session_start();
require('fpdf.php');

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// DB
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$school_id = $_SESSION['school_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
if (!$class_id) { die('Invalid class ID'); }

// Current term
$stmt = $conn->prepare("SELECT id FROM terms WHERE school_id = ? AND is_current = 1");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_res = $stmt->get_result();
$term_row = $term_res->fetch_assoc();
if (!$term_row) { die('No active term found'); }
$current_term_id = $term_row['id'];

// School details
$stmt = $conn->prepare("SELECT s.school_name, s.motto, s.email, s.location, s.phone, s.badge, t.name, t.year, t.next_term_start_date FROM schools s LEFT JOIN terms t ON s.id = t.school_id AND t.id = ? WHERE s.id = ?");
$stmt->bind_param("ii", $current_term_id, $school_id);
$stmt->execute();
$school_details = $stmt->get_result()->fetch_assoc();

// Grading scale
$grading_scale = [];
$stmt = $conn->prepare("SELECT grade, min_score, max_score, remarks FROM grading_scales WHERE school_id = ? ORDER BY min_score DESC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $grading_scale[$row['grade']] = [
        'min_score' => $row['min_score'],
        'max_score' => $row['max_score'],
        'remarks' => $row['remarks']
    ];
}

// Exam categories (list used for detailed table and summary building)
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

// Utilities and helpers (ported from download_report_card.php)
function getGradeFromPercentage($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) { if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) { return $grade; } }
    return 'N/A';
}
function getRemarksFromPercentage($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) { if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) { return $scale['remarks']; } }
    return 'N/A';
}
function getTeacherInitialsGlobal($fullname) {
    $names = explode(' ', trim($fullname)); $initials = '';
    foreach ($names as $name) { if (!empty($name)) { $initials .= strtoupper(substr($name, 0, 1)); } }
    return $initials;
}

function processExamResultsDetailed($results) {
    $exam_results = [];
    while ($row = $results->fetch_assoc()) {
        $subject = $row['subject_name'];
        $teacher = trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']);
        $topic = isset($row['topic']) && trim($row['topic']) !== '' ? trim($row['topic']) : 'Assessment';
        $score = isset($row['score']) ? (float)$row['score'] : null;
        $max = isset($row['max_score']) ? (float)$row['max_score'] : 3;
        if (!isset($exam_results[$subject])) { $exam_results[$subject] = [ 'teacher' => $teacher, 'topics' => [], 'subject_id' => $row['subject_id'] ?? null ]; }
        if (!isset($exam_results[$subject]['subject_id']) || $exam_results[$subject]['subject_id'] === null) { $exam_results[$subject]['subject_id'] = $row['subject_id'] ?? null; }
        if (empty($exam_results[$subject]['teacher']) && !empty($teacher)) { $exam_results[$subject]['teacher'] = $teacher; }
        if (!isset($exam_results[$subject]['topics'][$topic])) { $exam_results[$subject]['topics'][$topic] = [ 'sum_score' => 0.0, 'sum_max' => 0.0, 'count' => 0, 'entries' => [], 'competency' => $topic ]; }
        $exam_results[$subject]['topics'][$topic]['entries'][] = ['score' => $score, 'max' => $max];
        if ($score !== null) { $exam_results[$subject]['topics'][$topic]['sum_score'] += $score; $exam_results[$subject]['topics'][$topic]['count'] += 1; }
        $exam_results[$subject]['topics'][$topic]['sum_max'] += $max;
    }
    return $exam_results;
}

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
            $this->SetFont('Arial', '', 9); if (!empty($this->school_details['email'])) { $this->Cell(0, 5, $this->school_details['email'], 0, 1, 'C'); }
            $location_phone = trim(($this->school_details['location'] ?? '') . (empty($this->school_details['phone']) ? '' : ' | Phone: ' . $this->school_details['phone'])); if (!empty($location_phone)) { $this->Cell(0, 5, $location_phone, 0, 1, 'C'); }
            $this->SetFont('Arial', 'B', 11); $term_label = ''; if (!empty($this->school_details['name'])) { $term_label = ' - ' . $this->school_details['name']; if (!empty($this->school_details['year'])) { $term_label .= ' (' . $this->school_details['year'] . ')'; } }
            $this->Cell(0, 6, 'End of Term Report' . $term_label, 0, 1, 'C');
            $this->SetLineWidth(0.5); $this->Ln(3); $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY()); $this->Ln(10);
        }
    }
    function Footer() {
        $this->SetDrawColor(0, 0, 0); $this->SetLineWidth(0.4); $margin = 7; $this->Rect($margin, 5, $this->GetPageWidth() - 2*$margin, $this->GetPageHeight() - 10);
        $this->SetY(-12); $this->SetX($margin); $this->SetTextColor(0, 0, 0); $this->SetFont('Arial', 'I', 8);
        $this->Cell($this->GetPageWidth() - 2*$margin, 10, $this->school_details['email'], 0, 0, 'C');
    }
    function AddStudentInfo($student_details) {
        $this->SetY($this->GetY() + 4); $this->SetTextColor(0, 0, 0); $this->SetFont('Arial', 'B', 12); $this->Cell(0, 6, 'Student Information', 0, 1);
        $this->SetFont('Arial', '', 10); $line_height = 5; $label_width = 20; $left_col_width = 120; $right_col_width = 60;
        $x_start = $this->GetX(); $y_start = $this->GetY();
        $left_details = [ 'Name' => $student_details['firstname'] . ' ' . $student_details['lastname'], 'Class' => ($student_details['name'] ?? ''), 'Stream' => ($student_details['stream'] ?? '') ];
        $right_details = [ 'Gender' => ($student_details['gender'] ?? ''), 'Adm No.' => ($student_details['admission_number'] ?? '') ];
        $current_y = $y_start; foreach ($left_details as $label => $value) { $this->SetXY($x_start, $current_y); $this->Cell($label_width, $line_height, $label . ':', 0, 0); $this->Cell($left_col_width - $label_width, $line_height, $value, 0, 0); $current_y += $line_height; }
        $current_y = $y_start; $right_x = $x_start + $left_col_width - 20; foreach ($right_details as $label => $value) { $this->SetXY($right_x, $current_y); $this->Cell($label_width, $line_height, $label . ':', 0, 0); $this->Cell($right_col_width - $label_width, $line_height, $value, 0, 0); $current_y += $line_height; }
        if (!empty($student_details['image'])) { $img_path = 'uploads/' . basename($student_details['image']); if (file_exists($img_path)) { $this->Image($img_path, $this->GetPageWidth() - 40, $y_start - 10, 25, 25); } }
        $max_y = max($current_y, $y_start + 15); $this->SetXY($x_start, $max_y + 3);
    }
    private function getStringLines($text, $max_width) { $words = explode(' ', $text); $lines = 1; $current_line_width = 0; foreach ($words as $word) { $word_width = $this->GetStringWidth($word . ' '); if ($current_line_width + $word_width > $max_width && $current_line_width > 0) { $lines++; $current_line_width = $word_width; } else { $current_line_width += $word_width; } } return $lines; }
    private function getDescriptorFromScore($score) { if ($score >= 2.5) return 'Exceptional'; if ($score >= 2.0) return 'Outstanding'; if ($score >= 1.5) return 'Satisfactory'; if ($score >= 1.0) return 'Basic'; return 'Elementary'; }
    private function getRemarksFromScore($score) { if ($score >= 2.5) return 'Very nice performance'; if ($score >= 2.0) return 'Good work, maintain'; if ($score >= 1.5) return 'Fair but you can do better'; if ($score >= 1.0) return 'Poor, please concentrate'; return 'Below expectations'; }
    private function getIdentifierFromScore($score) { if ($score >= 2.5) return 3; if ($score >= 1.5) return 2; return 1; }
    private function getTeacherInitials($fullname) { return getTeacherInitialsGlobal($fullname); }
    function AddResultsTable($exam_categories, $exam_results, $grading_scale, $is_few_subjects = false) {
        $this->SetLineWidth(0.3); $this->SetFillColor(240, 240, 240); $this->SetTextColor(0, 0, 0); $this->SetFont('Arial', 'B', 10);
        $col_subject = 35; $col_topic = 60; $col_topic_num = 8; $col_score = 15; $col_descriptor = 24; $col_remarks = 36; $col_teacher = 20; $row_h = 8; $tableX = 10;
        $this->Cell($col_subject, $row_h, 'SUBJECT', 1, 0, 'C', true); $this->Cell($col_topic, $row_h, 'TOPIC and Competency', 1, 0, 'C', true); $this->Cell($col_score, $row_h, 'SCORE', 1, 0, 'C', true); $this->Cell($col_descriptor, $row_h, 'DESCRIPTOR', 1, 0, 'C', true); $this->Cell($col_remarks, $row_h, 'REMARKS', 1, 0, 'C', true); $this->Cell($col_teacher, $row_h, 'TR', 1, 1, 'C', true);
        $this->SetFont('Arial', '', 9); $total_score = 0; $total_max = 0; $total_topics = 0;
        foreach ($exam_results as $subject => $data) {
            $teacherInit = $this->getTeacherInitials($data['teacher'] ?? '');
            $valid_topics = [];
            foreach ($data['topics'] as $topicName => $tdata) { if (strtolower($topicName) !== 'general assessment' && strtolower($topicName) !== 'assessment') { $valid_topics[$topicName] = $tdata; } }
            $topic_count = count($valid_topics); $row_h = 8; $tableX = 10;
            $subject_block_height = $row_h; if ($topic_count > 0) { $topic_width = $col_topic - $col_topic_num; foreach ($valid_topics as $topicName => $tdata) { $topic_text = $topicName; if (isset($tdata['competency']) && $tdata['competency'] !== $topicName) { $topic_text = $topicName . ': ' . $tdata['competency']; } $topic_lines = $this->getStringLines($topic_text, $topic_width); $topic_height = $topic_lines * $row_h; $subject_block_height += $topic_height; } }
            $bottom_margin = 20; $page_height = $this->GetPageHeight(); $available = $page_height - $bottom_margin - $this->GetY(); if ($subject_block_height > $available) { $this->AddPage(); $this->SetFont('Arial', 'B', 9); $this->Cell($col_subject, $row_h, 'SUBJECT', 1, 0, 'C', true); $this->Cell($col_topic, $row_h, 'TOPIC and Competency', 1, 0, 'C', true); $this->Cell($col_score, $row_h, 'SCORE', 1, 0, 'C', true); $this->Cell($col_descriptor, $row_h, 'DESCRIPTOR', 1, 0, 'C', true); $this->Cell($col_remarks, $row_h, 'REMARKS', 1, 0, 'C', true); $this->Cell($col_teacher, $row_h, 'TEACHER / ', 1, 1, 'C', true); $this->SetFont('Arial', '', 8); }
            $subject_start_y = $this->GetY(); $subject_sum = 0; $subject_n = 0; $subject_score_sum = 0; $topic_width = $col_topic - $col_topic_num; $subject_height = 0; foreach ($valid_topics as $topicName => $tdata) { $topic_text = $topicName; if (isset($tdata['competency']) && $tdata['competency'] !== $topicName) { $topic_text = $topicName . ': ' . $tdata['competency']; } $topic_lines = $this->getStringLines($topic_text, $topic_width); $subject_height += $topic_lines * $row_h; }
            $topic_index = 0; $current_y = $subject_start_y; foreach ($valid_topics as $topicName => $tdata) { $topic_index++; $this->SetXY($tableX, $current_y); $avg_score = 0; if ($tdata['count'] > 0) { $avg_score = $tdata['sum_score'] / $tdata['count']; }
                $descriptor = $this->getDescriptorFromScore($avg_score); $this->SetX($tableX + $col_subject); $this->SetFont('Arial', 'B', 8); $topic_text = $topicName; if (isset($tdata['competency']) && $tdata['competency'] !== $topicName) { $topic_text = $topicName . ': ' . $tdata['competency']; }
                $topic_lines = $this->getStringLines($topic_text, $topic_width); $topic_cell_height = $topic_lines * $row_h; $this->Cell($col_topic_num, $topic_cell_height, $topic_index . '.', 1, 0, 'C'); $this->MultiCell($topic_width, $row_h, $topic_text, 1, 'L'); $this->SetXY($tableX + $col_subject + $col_topic, $current_y); $this->SetFont('Arial', '', 8); $this->Cell($col_score, $topic_cell_height, ($tdata['count'] > 0 ? number_format($avg_score, 1) : '-'), 1, 0, 'C'); $this->Cell($col_descriptor, $topic_cell_height, $descriptor, 1, 0, 'C'); $current_y += $topic_cell_height; /* ensure topic counted even if missed */ $topic_avg = ($tdata['count'] > 0) ? ($tdata['sum_score'] / $tdata['count']) : 0; $subject_sum += $topic_avg; $subject_score_sum += $topic_avg; $subject_n += 1; }
            // subject_sum, subject_n already include missed topics as zero
            $this->Rect($tableX, $subject_start_y, $col_subject, $subject_height); $this->Rect($tableX + $col_subject + $col_topic + $col_score + $col_descriptor, $subject_start_y, $col_remarks, $subject_height); $this->Rect($tableX + $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks, $subject_start_y, $col_teacher, $subject_height);
            $subject_remarks = $this->getRemarksFromScore($subject_n > 0 ? ($subject_sum / $subject_n) : 0); $this->SetXY($tableX + $col_subject + $col_topic + $col_score + $col_descriptor + 1, $subject_start_y + ($subject_height / 2) - ($row_h / 2)); $this->MultiCell($col_remarks - 2, 4, $subject_remarks, 0, 'L');
            $this->SetFont('Arial', 'B', 9); $this->SetXY($tableX, $subject_start_y + ($subject_height / 2) - ($row_h / 2)); $this->Cell($col_subject, $row_h, strtoupper($subject), 0, 0, 'C'); $this->SetXY($tableX + $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks, $subject_start_y + ($subject_height / 2) - ($row_h / 2)); $this->Cell($col_teacher, $row_h, $teacherInit, 0, 0, 'C'); $this->SetY($subject_start_y + $subject_height);
            if ($subject_n > 0) { $total_score += $subject_score_sum; $total_max += ($subject_n * 3); $total_topics += $subject_n; }
        }
        $this->SetFont('Arial', 'B', 9); $average_score = $total_topics > 0 ? ($total_score / $total_topics) : 0; $this->Cell($col_subject + $col_topic, $row_h, 'TOTAL SCORES', 1, 0, 'C', true); $this->Cell($col_score, $row_h, number_format($total_score, 1), 1, 0, 'C', true); $this->Cell($col_descriptor + $col_remarks + $col_teacher, $row_h, 'AVERAGE : ' . number_format($average_score, 1), 1, 1, 'C', true);
        $percentage = $average_score > 0 ? ($average_score / 3) * 100 : 0; $overall_descriptor = $this->getDescriptorFromScore($average_score); $identifier_num = $this->getIdentifierFromScore($average_score);
        $this->Ln(2); $this->SetFont('Arial', 'B', 9); $label1 = 'OVERALL COMMENT: '; $spacer = '    '; $label2 = $spacer . '| ' . 'IDENTIFIER: '; $value1 = $overall_descriptor; $value2 = (string)$identifier_num; $full_width = $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks + $col_teacher; $y = $this->GetY(); $x = $this->GetX(); $this->Cell($full_width, $row_h, '', 1, 0, 'L'); $w_label1 = $this->GetStringWidth($label1); $this->SetFont('Arial', '', 9); $w_value1 = $this->GetStringWidth($value1); $this->SetFont('Arial', 'B', 9); $w_label2 = $this->GetStringWidth($label2); $this->SetFont('Arial', '', 9); $w_value2 = $this->GetStringWidth($value2); $text_width = $w_label1 + $w_value1 + $w_label2 + $w_value2; $text_x = $x + ($full_width - $text_width) / 2; $text_y = $y + ($row_h / 2) - 1; $this->SetXY($text_x, $text_y); $this->SetFont('Arial', 'B', 9); $this->Cell($w_label1, 0, $label1, 0, 0, 'L'); $this->SetFont('Arial', '', 9); $this->Cell($w_value1, 0, $value1, 0, 0, 'L'); $this->SetFont('Arial', 'B', 9); $this->Cell($w_label2, 0, $label2, 0, 0, 'L'); $this->SetFont('Arial', '', 9); $this->Cell($w_value2, 0, $value2, 0, 1, 'L'); $this->SetY($y + $row_h);
        return $average_score;
    }
    // Summary table similar to download_report_card.php
    function AddSummaryTable($exam_categories, $exam_results, $grading_scale, $is_few_subjects = false) {
        $this->SetFillColor(220, 220, 220);
        $this->SetTextColor(0, 0, 0);

        $left_margin = 10; $right_margin = 10;
        $table_width = $this->GetPageWidth() - ($left_margin + $right_margin);
        $this->SetX($left_margin);

        $has_activity_categories = false; $activity_categories = [];
        foreach ($exam_categories as $category => $exam_types) {
            if (!empty($exam_types) && $exam_types[0] === 'activity') { $has_activity_categories = true; $activity_categories[] = $category; }
        }

        $num_dynamic_cols = count($exam_categories) + ($has_activity_categories ? 1 : 0);
        if ($num_dynamic_cols <= 0) { $num_dynamic_cols = 1; }

        $min_subject_w = 36; $min_total_w = 16; $min_grade_w = 16; $min_remarks_w = 24; $min_teacher_w = 18; $min_score_w = 12;
        $fixed_total = $min_subject_w + $min_total_w + $min_grade_w + $min_remarks_w + $min_teacher_w;
        $remaining_for_scores = $table_width - $fixed_total;

        if ($remaining_for_scores < $num_dynamic_cols * $min_score_w) {
            $shrink = min(8, (int)ceil((($num_dynamic_cols * $min_score_w) - $remaining_for_scores) / 2));
            $min_subject_w = max(30, $min_subject_w - (int)ceil($shrink / 2));
            $min_remarks_w = max(18, $min_remarks_w - (int)floor($shrink / 2));
            $fixed_total = $min_subject_w + $min_total_w + $min_grade_w + $min_remarks_w + $min_teacher_w;
            $remaining_for_scores = $table_width - $fixed_total;
            $this->SetFont('Arial', 'B', 8);
        } else {
            $this->SetFont('Arial', 'B', 9);
        }

        $score_col_width = max($min_score_w, floor($remaining_for_scores / $num_dynamic_cols));
        $score_col_width = min($score_col_width, 22);

        $header_col_width = $min_subject_w; $teacher_col_width = $min_teacher_w; $grade_col_width = $min_grade_w; $remarks_col_width = $min_remarks_w; $row_height = 8; $header_height = 9;

        // Headers
        $this->Cell($header_col_width, $header_height, 'SUBJECT', 1, 0, 'C', true);
        $last_activity_category = null; $activity_categories_processed = 0; $total_activity_categories = 0;
        foreach ($exam_categories as $category => $exam_types) { if (!empty($exam_types) && $exam_types[0] === 'activity') { $total_activity_categories++; $last_activity_category = $category; } }
        foreach ($exam_categories as $category => $exam_types) {
            $max_score = null;
            foreach ($exam_results as $subject => $data) { if (isset($data['categories'][$category]['max_score'])) { $max_score = $data['categories'][$category]['max_score']; break; } }
            $header_text = $category;
            if ($max_score !== null) { $header_text = (strlen($category) > 5) ? substr($category, 0, 4) . "\n(" . $max_score . ")" : $category . "\n(" . $max_score . ")"; }
            $this->Cell($score_col_width, $header_height, $header_text, 1, 0, 'C', true);
            if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_categories_processed++; }
            if ($has_activity_categories && $activity_categories_processed === $total_activity_categories && $category === $last_activity_category) {
                $this->Cell($score_col_width, $header_height, 'Act/20', 1, 0, 'C', true);
            }
        }
        $this->Cell($min_total_w, $header_height, 'Total', 1, 0, 'C', true);
        $this->Cell($grade_col_width, $header_height, 'GRADE', 1, 0, 'C', true);
        $this->Cell($remarks_col_width, $header_height, 'REMARKS', 1, 0, 'C', true);
        $this->Cell($teacher_col_width, $header_height, 'TEACHER', 1, 1, 'C', true);

        // Body
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', ($score_col_width <= $min_score_w ? 7.5 : 8.5));
        $total_subject_marks = 0; $total_max_marks = 0; $subject_count = 0;
        foreach ($exam_results as $subject => $data) {
            $this->Cell($header_col_width, $row_height, $subject, 1, 0, 'L');
            $teacher_initials = 'N/A';
            $activity_total = 0; $activity_max = 0; $activity_count = 0; $exam_categories_total = 0; $exam_categories_max = 0; $has_any_score = false;
            $last_activity_category = null; $activity_categories_processed = 0; $total_activity_categories = 0;
            foreach ($exam_categories as $category => $exam_types) { if (!empty($exam_types) && $exam_types[0] === 'activity') { $total_activity_categories++; $last_activity_category = $category; } }
            foreach ($exam_categories as $category => $exam_types) {
                $max_score = 0;
                if (isset($data['categories'][$category])) {
                    $score = $data['categories'][$category]['score'];
                    $max_score = $data['categories'][$category]['max_score'];
                    if ($score !== null) {
                        $has_any_score = true;
                        $formatted_score = (!empty($exam_types) && $exam_types[0] === 'exam') ? number_format((float)$score, 0, '.', '') : number_format((float)$score, 1, '.', '');
                        $this->Cell($score_col_width, $row_height, $formatted_score, 1, 0, 'C');
                        if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_total += $score; $activity_max += $max_score; $activity_count++; } else { $exam_categories_total += $score; $exam_categories_max += $max_score; }
                    } else {
                        $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                        if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_max += $max_score; $activity_count++; } else { $exam_categories_max += $max_score; }
                    }
                } else { $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C'); }
                if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_categories_processed++; }
                if ($has_activity_categories && $activity_categories_processed === $total_activity_categories && $category === $last_activity_category) {
                    if ($activity_count > 0) {
                        if ($activity_total > 0) { $score_out_of_20 = ($activity_max > 0) ? round(($activity_total / $activity_max) * 20) : 0; $this->Cell($score_col_width, $row_height, (string)$score_out_of_20, 1, 0, 'C'); }
                        else { $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C'); }
                    } else { $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C'); }
                }
            }
            $has_any_category = ($activity_count > 0) || ($exam_categories_max > 0);
            if ($has_any_category) {
                $total = ($has_activity_categories && $activity_count > 0 ? round(($activity_total / $activity_max) * 20) : 0)
                       + ($exam_categories_max > 0 ? round(($exam_categories_total / $exam_categories_max) * ($has_activity_categories ? 80 : 100)) : 0);
                $max_possible_total = 100; $subject_percentage = $total;
                if ($max_possible_total > 0) { $total_subject_marks += $total; $total_max_marks += $max_possible_total; $subject_count++; }
                $grade = $this->getGradeFromPercentage($subject_percentage, $grading_scale);
                $remarks = $this->getRemarksFromPercentage($subject_percentage, $grading_scale);
                $teacher_initials = $this->getTeacherInitials($data['teacher']);
                $this->Cell($min_total_w, $row_height, (string)$total, 1, 0, 'C');
                $this->Cell($grade_col_width, $row_height, $grade, 1, 0, 'C');
                $this->Cell($remarks_col_width, $row_height, $remarks, 1, 0, 'C');
                $this->Cell($teacher_col_width, $row_height, $teacher_initials, 1, 1, 'C');
            } else {
                $this->Cell($min_total_w, $row_height, '-', 1, 0, 'C');
                $this->Cell($grade_col_width, $row_height, '-', 1, 0, 'C');
                $this->Cell($remarks_col_width, $row_height, '-', 1, 0, 'C');
                $this->Cell($teacher_col_width, $row_height, $teacher_initials, 1, 1, 'C');
            }
        }
        if ($subject_count < 13) { $this->Ln((13 - $subject_count) * 2); } else { $this->Ln(4); }
        $average_percentage = ($total_max_marks > 0 && $subject_count > 0) ? ($total_subject_marks / $subject_count) : 0;
        return round($average_percentage, 1);
    }
    private function getGradeFromPercentage($percentage, $grading_scale) {
        foreach ($grading_scale as $grade => $scale) { if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) { return $grade; } }
        return 'N/A';
    }
    private function getRemarksFromPercentage($percentage, $grading_scale) {
        foreach ($grading_scale as $grade => $scale) { if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) { return $scale['remarks']; } }
        return 'N/A';
    }
    function AddDescriptorTable() { $this->Ln(10); $this->SetFont('Arial', 'B', 12); $this->Cell(0, 8, 'DESCRIPTOR EXPLANATION', 0, 1, 'C'); $this->SetFont('Arial', 'B', 10); $this->SetFillColor(240, 240, 240); $col_descriptor = 40; $col_score_range = 30; $col_meaning = 120; $this->Cell($col_descriptor, 8, 'DESCRIPTOR', 1, 0, 'C', true); $this->Cell($col_score_range, 8, 'SCORE RANGE', 1, 0, 'C', true); $this->Cell($col_meaning, 8, 'MEANING', 1, 1, 'C', true); $this->SetFont('Arial', '', 9); $rows = [['Exceptional','2.5 - 3.0','Exceptional performance demonstrating mastery of all competencies'],['Outstanding','2.0 - 2.4','Good performance with solid understanding of most competencies'],['Satisfactory','1.5 - 1.9','Acceptable performance meeting basic requirements'],['Basic','1.0 - 1.4','Minimal performance requiring additional support'],['Elementary','0.0 - 0.9','Beginning level requiring significant improvement']]; foreach ($rows as $r) { $this->Cell($col_descriptor, 6, $r[0], 1, 0, 'C'); $this->Cell($col_score_range, 6, $r[1], 1, 0, 'C'); $this->Cell($col_meaning, 6, $r[2], 1, 1, 'L'); } }
    function AddGradingScaleTable($grading_scale) { $this->Ln(8); $this->SetFont('Arial', 'B', 12); $this->Cell(0, 8, 'GRADING SCALE', 0, 1, 'C'); $left_margin = 10; $right_margin = 10; $table_width = $this->GetPageWidth() - ($left_margin + $right_margin); $this->SetX($left_margin); $max_cols = 6; $grades = []; $ranges = []; foreach ($grading_scale as $grade => $scale) { if (count($grades) >= $max_cols) { break; } $grades[] = (string)$grade; $min_int = (string)intval(round((float)$scale['min_score'])); $max_int = (string)intval(round((float)$scale['max_score'])); $ranges[] = $min_int.'-'.$max_int; } $num_cols = max(1, min($max_cols, count($grades))); $col_w = floor($table_width / $num_cols); $remaining = $table_width - ($col_w * $num_cols); $this->SetFont('Arial', 'B', 10); $this->SetFillColor(240, 240, 240); for ($i = 0; $i < $num_cols; $i++) { $w = $col_w + ($i == ($num_cols - 1) ? $remaining : 0); $this->Cell($w, 8, isset($grades[$i]) ? $grades[$i] : '', 1, 0, 'C', true); } $this->Ln(); $this->SetFont('Arial', '', 10); for ($i = 0; $i < $num_cols; $i++) { $w = $col_w + ($i == ($num_cols - 1) ? $remaining : 0); $this->Cell($w, 8, isset($ranges[$i]) ? $ranges[$i] : '', 1, 0, 'C'); } $this->Ln(); }
    function AddCompetencyExplanation() { $this->Ln(8); $this->SetFont('Arial', 'B', 11); $this->Cell(0, 8, 'COMPETENCY EXPLANATION', 0, 1, 'C'); $left_margin = 10; $right_margin = 10; $width = $this->GetPageWidth() - ($left_margin + $right_margin); $this->SetX($left_margin); $this->SetFont('Arial', '', 9); $text = 'Competency: The overall expected capability of a learner at the end of a topic, term or year, after being exposed to a body of knowledge, skills and values. Descriptor: Gives details on the extent to which the learner has achieved the stipulated learning outcomes in a given topic. Identifier: A label or grade that distinguishes learners according to their learning achievement of the set competencies. It refers to the average of the scores attained for the different learning outcomes that make up the competency.'; $this->MultiCell($width, 5, $text, 0, 'L'); }
    function AddNextTermStartDate() { if (!empty($this->school_details['next_term_start_date']) && $this->school_details['next_term_start_date'] !== '0000-00-00') { $required_space = 3 + 8 + 3; $available_space = $this->GetPageHeight() - $this->GetY() - 15; if ($available_space < $required_space) { $this->AddPage(); } else { $this->Ln(3); } $table_width = 100; $table_height = 8; $x_start = $this->GetX() + ($this->GetPageWidth() - $table_width) / 2; $y_start = $this->GetY(); $date_text = date('l, d F Y', strtotime($this->school_details['next_term_start_date'])); $full_text = 'Next Term Starts: ' . $date_text; $this->SetFont('Arial', 'B', 10); $this->SetTextColor(0, 0, 0); $this->SetFillColor(240, 240, 240); $this->SetDrawColor(0, 0, 0); $this->SetLineWidth(0.5); $this->SetXY($x_start, $y_start); $this->Cell($table_width, $table_height, $full_text, 1, 0, 'C', true); $this->SetY($y_start + $table_height + 3); } }
}

// Temp dir for PDFs
$temp_dir = 'temp_reports_format2_' . time();
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

        // Detailed results
        $results_query = "SELECT s.subject_id, s.subject_name, e.exam_type, e.category, er.score, er.topic AS topic, e.max_score, u.firstname AS teacher_firstname, u.lastname AS teacher_lastname FROM student_subjects ss JOIN subjects s ON ss.subject_id = s.subject_id CROSS JOIN ( SELECT DISTINCT category, exam_type, exam_id, max_score FROM exams WHERE school_id = ? AND term_id = ? ) e LEFT JOIN exam_results er ON er.exam_id = e.exam_id AND er.student_id = ? AND er.subject_id = s.subject_id AND er.school_id = ? LEFT JOIN ( SELECT subject_id, user_id FROM teacher_subjects WHERE is_class_teacher = 0 AND class_id = ? ) ts ON s.subject_id = ts.subject_id LEFT JOIN ( SELECT subject_id, user_id FROM teacher_subjects WHERE is_class_teacher = 1 AND class_id = ? ) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id AND u.role = 'teacher' WHERE s.class_id = ? AND ss.student_id = ? ORDER BY s.subject_name, e.category, e.exam_type";
        $stmt = $conn->prepare($results_query);
        $stmt->bind_param("iiiiiiii", $school_id, $current_term_id, $student['id'], $school_id, $class_id, $class_id, $class_id, $student['id']);
        $stmt->execute();
        $results = $stmt->get_result();
        $exam_results_detailed = processExamResultsDetailed($results);
        // Ensure all topics/activities done by class for each subject are present even if student missed them
        foreach ($exam_results_detailed as $subjName => &$subjData) {
            if (!isset($subjData['subject_id']) || !$subjData['subject_id']) { continue; }
            $subject_id_for_topics = (int)$subjData['subject_id'];
            $topics_stmt = $conn->prepare("SELECT DISTINCT CASE WHEN er.topic IS NULL OR TRIM(er.topic) = '' THEN 'Assessment' ELSE TRIM(er.topic) END AS topic FROM exam_results er JOIN exams e ON er.exam_id = e.exam_id WHERE e.term_id = ? AND er.school_id = ? AND er.subject_id = ?");
            $topics_stmt->bind_param("iii", $current_term_id, $school_id, $subject_id_for_topics);
            $topics_stmt->execute();
            $topics_res = $topics_stmt->get_result();
            while ($trow = $topics_res->fetch_assoc()) {
                $topicNameAll = $trow['topic'];
                if (!isset($subjData['topics'][$topicNameAll])) {
                    $subjData['topics'][$topicNameAll] = [ 'sum_score' => 0.0, 'sum_max' => 0.0, 'count' => 0, 'entries' => [], 'competency' => $topicNameAll ];
                }
            }
        }
        unset($subjData);

        // Summary results structure
        $summary_results_query = "SELECT s.subject_name, e.exam_type, e.category, er.score, e.max_score, u.firstname AS teacher_firstname, u.lastname AS teacher_lastname FROM student_subjects ss JOIN subjects s ON ss.subject_id = s.subject_id CROSS JOIN ( SELECT DISTINCT category, exam_type, exam_id, max_score FROM exams WHERE school_id = ? AND term_id = ? ) e INNER JOIN exam_subjects es ON es.exam_id = e.exam_id AND es.subject_id = s.subject_id LEFT JOIN exam_results er ON er.exam_id = e.exam_id AND er.student_id = ? AND er.subject_id = s.subject_id AND er.school_id = ? LEFT JOIN ( SELECT subject_id, user_id FROM teacher_subjects WHERE is_class_teacher = 0 AND class_id = ? ) ts ON s.subject_id = ts.subject_id LEFT JOIN ( SELECT subject_id, user_id FROM teacher_subjects WHERE is_class_teacher = 1 AND class_id = ? ) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id AND u.role = 'teacher' WHERE s.class_id = ? AND ss.student_id = ? ORDER BY s.subject_name, e.category, e.exam_type";
        $stmt = $conn->prepare($summary_results_query);
        $stmt->bind_param("iiiiiiii", $school_id, $current_term_id, $student['id'], $school_id, $class_id, $class_id, $class_id, $student['id']);
        $stmt->execute();
        $summary_results = $stmt->get_result();
        $summary_exam_results = []; $summary_exam_categories = [];
        while ($row = $summary_results->fetch_assoc()) {
            $subject = $row['subject_name']; $category = $row['category']; $exam_type = $row['exam_type'];
            if (!isset($summary_exam_results[$subject])) { $summary_exam_results[$subject] = [ 'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']), 'categories' => [] ]; }
            if (!isset($summary_exam_results[$subject]['categories'][$category])) { $summary_exam_results[$subject]['categories'][$category] = [ 'score' => null, 'max_score' => $row['max_score'] ]; }
            if ($row['score'] !== null) { $summary_exam_results[$subject]['categories'][$category]['score'] = $row['score']; }
            if (!isset($summary_exam_categories[$category])) { $summary_exam_categories[$category] = []; }
            if (!in_array($exam_type, $summary_exam_categories[$category])) { $summary_exam_categories[$category][] = $exam_type; }
        }

        // Position
        $position_query = "WITH SubjectAverages AS ( SELECT er.student_id, s.subject_name, AVG((er.score / e.max_score) * 100) as subject_average FROM exam_results er JOIN exams e ON er.exam_id = e.exam_id JOIN subjects s ON er.subject_id = s.subject_id WHERE e.term_id = ? AND s.class_id = ? GROUP BY er.student_id, s.subject_name ), StudentAverages AS ( SELECT s.id, s.firstname, s.lastname, AVG(sa.subject_average) as average_percentage FROM students s JOIN SubjectAverages sa ON s.id = sa.student_id WHERE s.class_id = ? GROUP BY s.id, s.firstname, s.lastname ), Rankings AS ( SELECT id, firstname, lastname, ROUND(average_percentage, 1) as average_percentage, DENSE_RANK() OVER (ORDER BY ROUND(average_percentage, 1) DESC) as position, COUNT(*) OVER () as total_students FROM StudentAverages ) SELECT position, total_students FROM Rankings WHERE id = ?";
        $stmt = $conn->prepare($position_query);
        $stmt->bind_param("iiii", $current_term_id, $class_id, $class_id, $student['id']);
        $stmt->execute();
        $pos = $stmt->get_result()->fetch_assoc();

        // Build PDF (two-format report like download_report_card.php)
        $pdf = new PDF($school_details);
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->SetFontSize(11);
        $pdf->AddStudentInfo($student_details);
        $pdf->Ln(2);
        $average_score = $pdf->AddResultsTable($exam_categories, $exam_results_detailed, $grading_scale, false);
        $pdf->AddDescriptorTable();
        $pdf->AddGradingScaleTable($grading_scale);
        $pdf->AddCompetencyExplanation();

        // Summary page
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 14); $pdf->Cell(0, 8, 'ACADEMIC PERFORMANCE SUMMARY', 0, 1, 'C'); $pdf->Ln(5);
        $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.5); $pdf->Line(10, $pdf->GetY(), $pdf->GetPageWidth() - 10, $pdf->GetY()); $pdf->Ln(5);
        $summary_average = $pdf->AddSummaryTable($summary_exam_categories, $summary_exam_results, $grading_scale, false);
        $pdf->Ln(8); $pdf->SetFont('Arial', 'B', 12); $pdf->Cell(0, 6, 'PERFORMANCE SUMMARY', 0, 1, 'C'); $pdf->Ln(3);
        $pdf->SetFont('Arial', '', 10); $pdf->Cell(45, 6, 'Overall Percentage:', 0, 0, 'R'); $pdf->Cell(25, 6, sprintf("%.1f%%", $summary_average), 0, 0); $pdf->Cell(30, 6, 'Overall Grade:', 0, 0, 'R'); $overall_grade_summary = getGradeFromPercentage($summary_average, $grading_scale); $pdf->Cell(18, 6, $overall_grade_summary, 0, 0); $pdf->Cell(45, 6, 'Overall Comment:', 0, 0, 'R'); $overall_comment = getRemarksFromPercentage($summary_average, $grading_scale); $pdf->MultiCell(0, 6, $overall_comment, 0, 'L');
        $pdf->Ln(2); $table_width = 80; $table_height = 7; $x_start = $pdf->GetX(); $y_start = $pdf->GetY(); $pdf->Rect($x_start, $y_start, $table_width, $table_height); $pdf->SetXY($x_start, $y_start); $pdf->SetFillColor(240, 240, 240); $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(40, $table_height, 'Position in Class:', 1, 0, 'C', true); $pdf->SetFillColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 11); if ($pos) { $pdf->Cell(40, $table_height, $pos['position'] . ' out of ' . $pos['total_students'], 1, 1, 'C', true); } else { $pdf->Cell(40, $table_height, 'N/A', 1, 1, 'C', true); } $pdf->Ln(5);

        // Comments based on percentage (match download_report_card.php)
        $avg_for_comments = (int) round(min(100, max(0, $summary_average)));
        $comments_query = "SELECT id, type, comment, min_score, max_score FROM class_comment_templates WHERE school_id = ? AND (class_id = ? OR class_id IS NULL) AND ? BETWEEN min_score AND max_score ORDER BY class_id DESC, type";
        $stmt = $conn->prepare($comments_query);
        $stmt->bind_param("iid", $school_id, $class_id, $avg_for_comments);
        $stmt->execute();
        $comments_result = $stmt->get_result();
        $class_teacher_comment = 'No comments available for this performance level';
        $head_teacher_comment = 'No comments available for this performance level';
        while ($row = $comments_result->fetch_assoc()) {
            if ($row['type'] == 'class_teacher') { $class_teacher_comment = $row['comment']; }
            elseif ($row['type'] == 'head_teacher') { $head_teacher_comment = $row['comment']; }
        }

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, 'Class Teacher\'s Comment:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $class_teacher_comment, 0, 'L');
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 6, 'Class Teacher Signature:', 0, 0, 'L');
        $pdf->Cell(100, 6, '____________________', 0, 1, 'L');
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, 'Head Teacher\'s Comment:', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 6, $head_teacher_comment, 0, 'L');
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 6, 'Head Teacher Signature:', 0, 0, 'L');
        $pdf->Cell(100, 6, '____________________', 0, 1, 'L');
        $pdf->Ln(2);

        $pdf->AddNextTermStartDate();

        // Save PDF
        $filename = $temp_dir . '/report_card_' . str_replace(' ', '_', $student['firstname'] . '_' . $student['lastname']) . '.pdf';
        $pdf->Output('F', $filename);
        $success_count++;
    } catch (Exception $e) {
        $error_count++; $error_log[] = 'Error processing ' . $student['lastname'] . ', ' . $student['firstname'] . ': ' . $e->getMessage();
        continue;
    }
}

// Zip all PDFs and stream
$zip = new ZipArchive();
$zipName = 'class_reports_format2_' . $class_name . '_' . date('Y-m-d_H-i-s') . '.zip';
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
    $f = fopen($zipName, 'rb');
    while (!feof($f)) { echo fread($f, 8192); flush(); }
    fclose($f);
    @unlink($zipName);
    $conn->close();
    exit();
} else {
    die('Failed to create ZIP archive');
}

?>


