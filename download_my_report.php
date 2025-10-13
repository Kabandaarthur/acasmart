<?php
session_start();
require('fpdf.php');

// Only allow logged-in students
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

// DB connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$school_id = $_SESSION['school_id'];
$student_id = $_SESSION['user_id'];

// Determine output mode
$inline = isset($_GET['inline']) && $_GET['inline'] == '1';

// Get current term
$current_term_query = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$term_row = $term_result->fetch_assoc();
if (!$term_row) {
    die('No active term found');
}
$current_term_id = $term_row['id'];

// Get student details including class_id
$student_query = "SELECT s.id, s.firstname, s.lastname, s.class_id, c.name, s.stream, s.image, 
                         s.gender, s.lin_number, s.admission_number
                  FROM students s
                  JOIN classes c ON s.class_id = c.id
                  WHERE s.id = ? AND s.school_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("ii", $student_id, $school_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student_details = $student_result->fetch_assoc();
if (!$student_details) {
    die('Student not found');
}
$class_id = (int)$student_details['class_id'];

// Get school details
$school_query = "SELECT s.school_name, s.motto, s.email, s.location, s.phone, s.badge, 
                        t.name, t.year, t.next_term_start_date
                 FROM schools s
                 LEFT JOIN terms t ON s.id = t.school_id AND t.id = ?
                 WHERE s.id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("ii", $current_term_id, $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school_details = $school_result->fetch_assoc();

// Get grading scale
$grading_scale_query = "SELECT grade, min_score, max_score, remarks 
                        FROM grading_scales 
                        WHERE school_id = ? 
                        ORDER BY min_score DESC";
$stmt = $conn->prepare($grading_scale_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$grading_scale_result = $stmt->get_result();
$grading_scale = [];
while ($row = $grading_scale_result->fetch_assoc()) {
    $grading_scale[$row['grade']] = [
        'min_score' => $row['min_score'],
        'max_score' => $row['max_score'],
        'remarks' => $row['remarks']
    ];
}

// Get exam categories
$exam_categories_query = "SELECT DISTINCT category, exam_type 
                         FROM exams 
                         WHERE school_id = ? 
                         AND term_id = ?
                         ORDER BY category, exam_type";
$stmt = $conn->prepare($exam_categories_query);
$stmt->bind_param("ii", $school_id, $current_term_id);
$stmt->execute();
$exam_categories_result = $stmt->get_result();

$exam_categories = [];
while ($row = $exam_categories_result->fetch_assoc()) {
    if (!isset($exam_categories[$row['category']])) {
        $exam_categories[$row['category']] = [];
    }
    $exam_categories[$row['category']][] = $row['exam_type'];
}

// Helpers borrowed from download_report_card
function getGrade($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) {
            return $grade;
        }
    }
    return 'N/A';
}
function getRemarks($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) {
            return $scale['remarks'];
        }
    }
    return 'N/A';
}
function getTeacherInitials($fullname) {
    $names = explode(' ', trim($fullname));
    $initials = '';
    foreach ($names as $name) {
        if (!empty($name)) {
            $initials .= strtoupper(substr($name, 0, 1));
        }
    }
    return $initials;
}
function getOverallComment($overall_grade, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($grade == $overall_grade) {
            return $scale['remarks'];
        }
    }
    return 'N/A';
}

class PDF extends FPDF {
    protected $school_details;
    function __construct($school_details) { parent::__construct(); $this->school_details = $school_details; }
    function Header() {
        $badge_width = 35; $badge_height = 35;
        if (!empty($this->school_details['badge']) && file_exists('uploads/' . basename($this->school_details['badge']))) {
            $this->Image('uploads/' . basename($this->school_details['badge']), 10, 2, $badge_width, $badge_height);
        }
        $this->SetY(8); $this->SetTextColor(0,0,0); $this->SetFont('Arial','B',16);
        $this->Cell(0,6,$this->school_details['school_name'],0,1,'C');
        $this->SetFont('Arial','I',9); $this->Cell(0,5,'Motto: ' . $this->school_details['motto'],0,1,'C');
        $this->SetFont('Arial','',9); $this->Cell(0,5,$this->school_details['email'],0,1,'C');
        $this->Cell(0,5,$this->school_details['location'] . ' | Phone: ' . $this->school_details['phone'],0,1,'C');
        $this->SetFont('Arial','B',11);
        $this->Cell(0,6,'End of Term Report - ' . $this->school_details['name'] . ' (' . $this->school_details['year'] . ')',0,1,'C');
        $this->SetLineWidth(0.5); $this->Ln(3); $this->Line(10,$this->GetY(),200,$this->GetY()); $this->Ln(8);
    }
    function Footer() { $this->SetY(-10); $this->SetTextColor(0,0,0); $this->SetFont('Arial','I',8); $this->Cell(0,10,$this->school_details['email'],0,0,'C'); }

    function AddStudentInfo($student_details) {
        $this->SetY($this->GetY() - 2);
        $this->SetTextColor(0,0,0); $this->SetFont('Arial','B',12); $this->Cell(0,6,'Student Information',0,1);
        $this->SetFont('Arial','',10); $line_height=5; $label_width=20; $left_col_width=120; $right_col_width=60;
        $x_start=$this->GetX(); $y_start=$this->GetY();
        $left_details = [
            'Name' => $student_details['firstname'] . ' ' . $student_details['lastname'],
            'Class' => $student_details['name'],
            'Stream' => $student_details['stream']
        ];
        $right_details = [
            'Gender' => $student_details['gender'] ?? '',
            'Adm No.' => $student_details['admission_number'] ?? ''
        ];
        $current_y = $y_start;
        foreach ($left_details as $label => $value) { $this->SetXY($x_start,$current_y); $this->Cell($label_width,$line_height,$label . ':',0,0); $this->Cell($left_col_width - $label_width,$line_height,$value,0,0); $current_y += $line_height; }
        $current_y = $y_start; $right_x = $x_start + $left_col_width - 20;
        foreach ($right_details as $label => $value) { $this->SetXY($right_x,$current_y); $this->Cell($label_width,$line_height,$label . ':',0,0); $this->Cell($right_col_width - $label_width,$line_height,$value,0,0); $current_y += $line_height; }
        if (!empty($student_details['image'])) {
            $img_path = 'uploads/' . basename($student_details['image']);
            if (file_exists($img_path)) { $this->Image($img_path, $this->GetPageWidth() - 40, $y_start - 10, 25, 25); }
        }
        $max_y = max($current_y, $y_start + 15); $this->SetXY($x_start, $max_y + 3);
    }

    function AddResultsTable($exam_categories, $exam_results, $grading_scale) {
        $this->SetFillColor(220,220,220); $this->SetTextColor(0,0,0); $this->SetFont('Arial','B',8.5);
        $header_col_width=40; $score_col_width=17; $teacher_col_width=22; $row_height=7;
        $has_activity_categories=false; $activity_categories=[]; foreach ($exam_categories as $category => $exam_types) { if (!empty($exam_types) && $exam_types[0] === 'activity') { $has_activity_categories=true; $activity_categories[]=$category; } }
        $header_height=8.5; $this->Cell($header_col_width,$header_height,'SUBJECT',1,0,'C',true);
        $last_activity_category = end($activity_categories);
        foreach ($exam_categories as $category => $exam_types) {
            $max_score = null; foreach ($exam_results as $subject => $data) { if (isset($data['categories'][$category]['max_score'])) { $max_score = $data['categories'][$category]['max_score']; break; } }
            $header_text = $category; if ($max_score !== null) { $header_text = (strlen($category) > 5) ? (substr($category,0,4) . "\n(" . $max_score . ")") : ($category . "\n(" . $max_score . ")"); }
            $this->Cell($score_col_width,$header_height,$header_text,1,0,'C',true);
            if ($has_activity_categories && $category === $last_activity_category) { $this->Cell($score_col_width,$header_height,'Act/20',1,0,'C',true); }
        }
        $this->Cell($score_col_width,$header_height,'Total',1,0,'C',true);
        $grade_col_width=20; $this->Cell($grade_col_width,$header_height,'GRADE',1,0,'C',true);
        $remarks_col_width=30; $this->Cell($remarks_col_width,$header_height,'REMARKS',1,0,'C',true); $this->Cell($teacher_col_width,$header_height,'TEACHER',1,1,'C',true);

        $this->SetTextColor(0,0,0); $this->SetFont('Arial','',8); $total_subject_marks=0; $total_max_marks=0; $subject_count=0;
        foreach ($exam_results as $subject => $data) {
            $this->Cell($header_col_width,$row_height,$subject,1,0,'L');
            $teacher_initials = 'N/A'; $activity_total=0; $activity_max=0; $activity_count=0; $exam_categories_total=0; $exam_categories_max=0; $has_any_score=false;
            $last_activity_category = end($activity_categories);
            foreach ($exam_categories as $category => $exam_types) {
                $max_score=0; if (isset($data['categories'][$category])) {
                    $score = $data['categories'][$category]['score']; $max_score = $data['categories'][$category]['max_score'];
                    if ($score !== null) { $has_any_score=true; $formatted_score = (!empty($exam_types) && $exam_types[0] === 'exam') ? number_format((float)$score,0,'.','') : number_format((float)$score,1,'.',''); $this->Cell($score_col_width,$row_height,$formatted_score,1,0,'C'); if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_total += $score; $activity_max += $max_score; $activity_count++; } else { $exam_categories_total += $score; $exam_categories_max += $max_score; } }
                    else { $this->Cell($score_col_width,$row_height,'-',1,0,'C'); if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_max += $max_score; $activity_count++; } else { $exam_categories_max += $max_score; } }
                } else { $this->Cell($score_col_width,$row_height,'-',1,0,'C'); }
                if ($has_activity_categories && $category === $last_activity_category) { if ($activity_count > 0) { if ($activity_total > 0) { $score_out_of_20 = ($activity_max > 0) ? round(($activity_total / $activity_max) * 20) : 0; $this->Cell($score_col_width,$row_height,(string)$score_out_of_20,1,0,'C'); } else { $this->Cell($score_col_width,$row_height,'-',1,0,'C'); } } else { $this->Cell($score_col_width,$row_height,'-',1,0,'C'); } }
            }
            if ($has_any_score) {
                $total = ($has_activity_categories && $activity_count > 0 ? round(($activity_total / $activity_max) * 20) : 0) + ($exam_categories_max > 0 ? round(($exam_categories_total / $exam_categories_max) * ($has_activity_categories ? 80 : 100)) : 0);
                $max_possible_total = 100; $subject_percentage = $total; if ($max_possible_total > 0) { $total_subject_marks += $total; $total_max_marks += $max_possible_total; $subject_count++; }
                $grade = getGrade($subject_percentage, $grading_scale); $remarks = getRemarks($subject_percentage, $grading_scale); $teacher_initials = getTeacherInitials($data['teacher']);
                if ($total > 0) { $this->Cell($score_col_width,$row_height,(string)$total,1,0,'C'); } else { $this->Cell($score_col_width,$row_height,'-',1,0,'C'); }
                $this->Cell($grade_col_width,$row_height,$grade,1,0,'C'); $this->Cell($remarks_col_width,$row_height,$remarks,1,0,'C'); $this->Cell($teacher_col_width,$row_height,$teacher_initials,1,1,'C');
            } else {
                $this->Cell($score_col_width,$row_height,'-',1,0,'C'); $this->Cell($grade_col_width,$row_height,'-',1,0,'C'); $this->Cell($remarks_col_width,$row_height,'-',1,0,'C'); $this->Cell($teacher_col_width,$row_height,$teacher_initials,1,1,'C');
            }
        }
        if ($subject_count < 13) { $extra_space = (13 - $subject_count) * 1.5; $this->Ln($extra_space); } else { $this->Ln(3); }
        $average_percentage = ($total_max_marks > 0 && $subject_count > 0) ? ($total_subject_marks / $subject_count) : 0; return round($average_percentage, 1);
    }
}

// Fetch all student subjects and all exam categories rows
$results_query = "
SELECT 
    s.subject_name,
    e.exam_type,
    e.category,
    er.score,
    e.max_score,
    u.firstname AS teacher_firstname,
    u.lastname AS teacher_lastname
FROM student_subjects ss
JOIN subjects s ON ss.subject_id = s.subject_id
CROSS JOIN (
    SELECT DISTINCT category, exam_type, exam_id, max_score
    FROM exams 
    WHERE school_id = ? 
    AND term_id = ?
) e
LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
    AND er.student_id = ? 
    AND er.subject_id = s.subject_id
    AND er.school_id = ?
LEFT JOIN (
    SELECT subject_id, user_id
    FROM teacher_subjects
    WHERE is_class_teacher = 0
    AND class_id = ?
) ts ON s.subject_id = ts.subject_id
LEFT JOIN (
    SELECT subject_id, user_id
    FROM teacher_subjects
    WHERE is_class_teacher = 1
    AND class_id = ?
) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id
LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id 
    AND u.role = 'teacher'
WHERE s.class_id = ?
AND ss.student_id = ?
ORDER BY s.subject_name, e.category, e.exam_type";

$stmt = $conn->prepare($results_query);
$stmt->bind_param("iiiiiiii", 
    $school_id,
    $current_term_id,
    $student_id,
    $school_id,
    $class_id,
    $class_id,
    $class_id,
    $student_id
);
$stmt->execute();
$results = $stmt->get_result();

// Process results into structure used by PDF
$exam_results = [];
while ($row = $results->fetch_assoc()) {
    $subject = $row['subject_name'];
    $category = $row['category'];
    if (!isset($exam_results[$subject])) {
        $exam_results[$subject] = [
            'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']),
            'categories' => []
        ];
    }
    if (!isset($exam_results[$subject]['categories'][$category])) {
        $exam_results[$subject]['categories'][$category] = [
            'score' => $row['score'],
            'max_score' => $row['max_score']
        ];
    }
}

// Count subjects
$subject_count = count($exam_results);

// Calculate class position using same logic as bulk reports
$all_students_query = "SELECT id, firstname, lastname FROM students WHERE class_id = ? AND school_id = ? ORDER BY lastname, firstname";
$stmt = $conn->prepare($all_students_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$all_students_result = $stmt->get_result();

$student_averages = [];
while ($student_row = $all_students_result->fetch_assoc()) {
    $student_results_query = "
        SELECT 
            s.subject_name,
            e.exam_type,
            e.category,
            er.score,
            e.max_score,
            u.firstname AS teacher_firstname,
            u.lastname AS teacher_lastname
        FROM student_subjects ss
        JOIN subjects s ON ss.subject_id = s.subject_id
        CROSS JOIN (
            SELECT DISTINCT category, exam_type, exam_id, max_score
            FROM exams 
            WHERE school_id = ? 
            AND term_id = ?
        ) e
        LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
            AND er.student_id = ? 
            AND er.subject_id = s.subject_id
            AND er.school_id = ?
        LEFT JOIN (
            SELECT subject_id, user_id
            FROM teacher_subjects
            WHERE is_class_teacher = 0
            AND class_id = ?
        ) ts ON s.subject_id = ts.subject_id
        LEFT JOIN (
            SELECT subject_id, user_id
            FROM teacher_subjects
            WHERE is_class_teacher = 1
            AND class_id = ?
        ) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id
        LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id 
            AND u.role = 'teacher'
        WHERE s.class_id = ?
        AND ss.student_id = ?
        ORDER BY s.subject_name, e.category, e.exam_type";

    $stmt2 = $conn->prepare($student_results_query);
    $stmt2->bind_param("iiiiiiii", 
        $school_id,
        $current_term_id,
        $student_row['id'],
        $school_id,
        $class_id,
        $class_id,
        $class_id,
        $student_row['id']
    );
    $stmt2->execute();
    $student_results = $stmt2->get_result();

    $student_exam_results = [];
    $total_subject_marks_calc = 0;
    $total_max_marks_calc = 0;
    $subject_count_calc = 0;

    while ($r = $student_results->fetch_assoc()) {
        $subj = $r['subject_name'];
        $cat = $r['category'];
        if (!isset($student_exam_results[$subj])) {
            $student_exam_results[$subj] = [ 'categories' => [] ];
            $subject_count_calc++;
        }
        if (!isset($student_exam_results[$subj]['categories'][$cat])) {
            $student_exam_results[$subj]['categories'][$cat] = [ 'score' => null, 'max_score' => $r['max_score'] ];
        }
        if ($r['score'] !== null) {
            $student_exam_results[$subj]['categories'][$cat]['score'] = $r['score'];
        }
    }

    // Determine if there are any activity categories
    $has_activity_categories = false;
    foreach ($exam_categories as $category => $exam_types) {
        if (!empty($exam_types) && $exam_types[0] === 'activity') { $has_activity_categories = true; break; }
    }

    foreach ($student_exam_results as $subj => $data) {
        $activity_total = 0; $activity_max = 0; $activity_count = 0;
        $exam_categories_total = 0; $exam_categories_max = 0; $has_any_score = false;
        foreach ($exam_categories as $category => $exam_types) {
            if (isset($data['categories'][$category])) {
                $score = $data['categories'][$category]['score'];
                $maxs = $data['categories'][$category]['max_score'];
                if ($score !== null) {
                    $has_any_score = true;
                    if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_total += $score; $activity_max += $maxs; $activity_count++; }
                    else { $exam_categories_total += $score; $exam_categories_max += $maxs; }
                } else {
                    if (!empty($exam_types) && $exam_types[0] === 'activity') { $activity_max += $maxs; $activity_count++; }
                    else { $exam_categories_max += $maxs; }
                }
            }
        }
        if ($has_any_score) {
            $total = ($has_activity_categories && $activity_count > 0 ? round(($activity_total / $activity_max) * 20) : 0) +
                     ($exam_categories_max > 0 ? round(($exam_categories_total / $exam_categories_max) * ($has_activity_categories ? 80 : 100)) : 0);
            $total_subject_marks_calc += $total; $total_max_marks_calc += 100;
        }
    }

    $avg_percentage = ($total_max_marks_calc > 0 && $subject_count_calc > 0) ? ($total_subject_marks_calc / $subject_count_calc) : 0;
    $student_averages[] = [ 'id' => $student_row['id'], 'average' => round($avg_percentage, 1) ];
}

usort($student_averages, function($a, $b) { return $b['average'] <=> $a['average']; });
$student_position = 'N/A';
$total_students = count($student_averages);
for ($i = 0; $i < count($student_averages); $i++) {
    if ($student_averages[$i]['id'] == $student_id) {
        $current_average = $student_averages[$i]['average'];
        $actual_position = $i + 1;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($student_averages[$j]['average'] == $current_average) { $actual_position = $j + 1; } else { break; }
        }
        $student_position = $actual_position;
        break;
    }
}

// Generate PDF
$pdf = new PDF($school_details);
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFontSize(11);
$pdf->AddStudentInfo($student_details);
$pdf->Ln(2);
$average_percentage = $pdf->AddResultsTable($exam_categories, $exam_results, $grading_scale);

// Overall summary and comments
$pdf->Ln(1); $pdf->SetFont('Arial','B',10); $pdf->SetFont('Arial','',10); $pdf->SetTextColor(0,0,0);
$pdf->Cell(45,5.5,'Overall Percentage:',0,0,'R');
$rounded_average = round($average_percentage);
$pdf->Cell(25,5.5,sprintf("%.1f%%", $average_percentage),0,0);
$pdf->Cell(30,5.5,'Overall Grade:',0,0,'R');
$overall_grade = getGrade($rounded_average, $grading_scale);
$pdf->Cell(18,5.5,$overall_grade,0,0);
$pdf->Cell(45,5.5,'Overall Comment:',0,0,'R');
$overall_comment = getOverallComment($overall_grade, $grading_scale);
$pdf->MultiCell(0,5.5,$overall_comment,0,'L');

// Position small table
$pdf->Ln(1);
$table_width = 70; $table_height = 6; $x_start = $pdf->GetX(); $y_start = $pdf->GetY();
$pdf->Rect($x_start, $y_start, $table_width, $table_height);
$pdf->SetXY($x_start, $y_start);
$pdf->SetFillColor(240,240,240); $pdf->SetFont('Arial','B',9);
$pdf->Cell(35,$table_height,'Position in Class:',1,0,'C',true);
$pdf->SetFillColor(255,255,255); $pdf->SetFont('Arial','B',10);
$pdf->Cell(35,$table_height, $student_position . ' out of ' . $total_students,1,1,'C',true);

// Comments from class_comment_templates matching overall percentage
$comments_query = "SELECT id, type, comment, min_score, max_score FROM class_comment_templates 
                   WHERE school_id = ? AND (class_id = ? OR class_id IS NULL) AND ? BETWEEN min_score AND max_score
                   ORDER BY class_id DESC, type";
$stmt = $conn->prepare($comments_query);
$stmt->bind_param("iid", $school_id, $class_id, $average_percentage);
$stmt->execute();
$comments_result = $stmt->get_result();
$class_teacher_comment = 'No comments available for this performance level';
$head_teacher_comment = 'No comments available for this performance level';
while ($row = $comments_result->fetch_assoc()) {
    if ($row['type'] == 'class_teacher') { $class_teacher_comment = $row['comment']; }
    elseif ($row['type'] == 'head_teacher') { $head_teacher_comment = $row['comment']; }
}

if ($subject_count < 13) { $pdf->Ln(3); } else { $pdf->Ln(1); }
$pdf->SetFont('Arial','B',10); $pdf->Cell(47,5,'Class Teacher\'s Comment:',0,0,'L'); $pdf->SetFont('Arial','',9); $pdf->MultiCell(0,5,$class_teacher_comment,0,'L');
if ($subject_count < 13) { $pdf->Ln(2); }
$pdf->SetFont('Arial','B',9); $pdf->Cell(47,5,'Class Teacher Signature:',0,0,'L'); $pdf->Cell(100,5,'____________________',0,1,'L');
if ($subject_count < 13) { $pdf->Ln(4); } else { $pdf->Ln(1); }
$pdf->SetFont('Arial','B',10); $pdf->Cell(47,5,'Head Teacher\'s Comment:',0,0,'L'); $pdf->SetFont('Arial','',9); $pdf->MultiCell(0,5,$head_teacher_comment,0,'L');
if ($subject_count < 13) { $pdf->Ln(2); } else { $pdf->Ln(1); }
$pdf->SetFont('Arial','B',9); $pdf->Cell(47,5,'Head Teacher Signature:',0,0,'L'); $pdf->Cell(100,5,'____________________',0,1,'L');
if ($subject_count < 13) { $pdf->Ln(5); } else { $pdf->Ln(3); }

if (!empty($school_details['next_term_start_date'])) {
    $pdf->Ln(3);
    $pdf->SetFont('Arial','B',9); $pdf->Cell(40,4,'Next Term Begins:',0,0,'L'); $pdf->SetFont('Arial','B',9); $pdf->SetTextColor(0,0,0);
    $formatted_date = date('F j, Y', strtotime($school_details['next_term_start_date']));
    $x = $pdf->GetX(); $y = $pdf->GetY(); $date_width = $pdf->GetStringWidth($formatted_date);
    $pdf->Cell(0,4,$formatted_date,0,1,'L'); $pdf->Line($x, $y + 4, $x + $date_width, $y + 4);
}

// Grading System table (same as bulk report)
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 5, 'Grading System:', 0, 1, 'L');
$pdf->SetFillColor(230, 230, 230);
$pdf->SetX(10);
foreach ($grading_scale as $grade => $scale) {
    $pdf->Cell(22, 6, $grade, 1, 0, 'C', true);
}
$pdf->Ln();
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(10);
foreach ($grading_scale as $scale) {
    $pdf->Cell(22, 5, $scale['max_score'] . ' - ' . $scale['min_score'], 1, 0, 'C');
}

// Spacing and Description section similar to bulk report
if ($subject_count > 13) {
    $pdf->Ln(1);
} else {
    $pdf->Ln(6);
}

if ($subject_count <= 13) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(80, 5, 'DESCRIPTION:', 0, 1, 'L');
    $pdf->Ln(1);
    $descriptions = [
        '1. Exceptional' => 'Demonstrates extra ordinary level of competency by applying innovatively and creatively the acquired knowledge',
        '2. Outstanding' => 'Demonstrates high level of competency by applying the acquired knowledge and skills in real life situation',
        '3. Satisfactory' => 'Demonstrates adequate of competency by applying the acquired knowledge and skills in real life situation',
        '4. Basic' => 'Demonstrates minimum level of competency by applying the acquired knowledge and skills in real life situation',
        '5. Elementary' => 'Demonstrates below the basic level of competency by applying the acquired knowledge in real life situation'
    ];
    $pdf->SetFont('Arial', '', 9);
    $rowHeight = 6;
    $spacing = 0.75;
    foreach ($descriptions as $level => $description) {
        $pdf->SetX(15);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(32, $rowHeight, $level . ':', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(155, $rowHeight, $description, 0, 1);
        $pdf->Ln($spacing);
    }
} else {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(0, 5, '', 0, 1, 'L');
    $pdf->Ln(3);
    $table_width = 190; $col_width = $table_width / 5; $row_height = 5;
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX(10);
    $headers = ['Exceptional', 'Outstanding', 'Satisfactory', 'Basic', 'Elementary'];
    foreach ($headers as $header) { $pdf->Cell($col_width, $row_height, $header, 1, 0, 'C', true); }
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetX(10);
    $brief_descriptions = [
        'Extraordinary innovative creative',
        'High knowledge skills',
        'Adequate knowledge skills',
        'Minimum knowledge skills',
        'Below basic knowledge'
    ];
    foreach ($brief_descriptions as $description) { $pdf->Cell($col_width, $row_height, $description, 1, 0, 'C'); }
    $pdf->Ln();
}

// Final spacing consistent with bulk report
if ($subject_count > 13) {
    $pdf->Ln(0.5);
} else {
    $pdf->Ln(2);
}

// Output
$student_name_file = str_replace(' ', '_', $student_details['firstname'] . '_' . $student_details['lastname']);
$filename = 'report_card_' . $student_name_file . '.pdf';
while (ob_get_level()) { ob_end_clean(); }
// Allow embedding in iframe for inline preview
header('Content-Type: application/pdf');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
if ($inline) {
    header('Content-Disposition: inline; filename="' . $filename . '"');
    $pdf->Output('I', $filename);
} else {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $pdf->Output('D', $filename);
}

$conn->close();
exit();
?>

