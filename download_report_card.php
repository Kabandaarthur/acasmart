<?php
session_start();
require('fpdf.php');

// Auth: allow admin or student
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}

// Include database connection  
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get required parameters
$school_id = $_SESSION['school_id'];
$role = $_SESSION['role'];

// Initialize IDs depending on role
$student_id = 0;
$class_id = 0;

if ($role === 'admin') {
    // Admin can request any student's report explicitly via query params
    $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
    $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
} elseif ($role === 'student') {
    // Student can only download their own report; ignore query params
    $student_id = intval($_SESSION['user_id']);
    // Fetch student's class to avoid relying on URL
    $tmp_conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($tmp_conn->connect_error) { die('Connection failed: ' . $tmp_conn->connect_error); }
    $stmtTmp = $tmp_conn->prepare("SELECT class_id FROM students WHERE id = ? AND school_id = ?");
    $stmtTmp->bind_param("ii", $student_id, $school_id);
    $stmtTmp->execute();
    $resTmp = $stmtTmp->get_result();
    $rowTmp = $resTmp->fetch_assoc();
    if ($rowTmp) { $class_id = intval($rowTmp['class_id']); }
    $stmtTmp->close();
    $tmp_conn->close();
}

function displayError($message) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Report Card Download</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .error-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
            background-color: #fff;
            border-left: 4px solid #dc3545;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #dc3545;
            margin-top: 0;
        }
        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #6c757d;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .back-button:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Error</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <a href="javascript:history.back()" class="back-button">← Go Back</a>
    </div>
</body>
</html>';
    exit();
}

// Validate parameters
$validation_errors = [];
if (!$school_id) { $validation_errors[] = "School ID not found in session"; }
if (!$student_id) { $validation_errors[] = "Invalid student ID"; }
if (!$class_id) { $validation_errors[] = "Invalid class ID"; }
if (!empty($validation_errors)) { displayError("Invalid parameters:\n• " . implode("\n• ", $validation_errors)); }

// Get current term
$current_term_query = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$term_row = $term_result->fetch_assoc();

if (!$term_row) {
    displayError("No active term found. Please set an active term first.");
}
$current_term_id = $term_row['id'];

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

// Get student details
$student_query = "SELECT s.id, s.firstname, s.lastname, c.name, s.stream, s.image, 
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

// Get class teacher details
$class_teacher_query = "SELECT u.firstname, u.lastname 
                       FROM teacher_subjects ts 
                       JOIN users u ON ts.user_id = u.user_id 
                       WHERE ts.class_id = ? AND ts.is_class_teacher = 1 
                       AND u.school_id = ? LIMIT 1";
$stmt = $conn->prepare($class_teacher_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class_teacher_result = $stmt->get_result();
$class_teacher = $class_teacher_result->fetch_assoc();

// Get head teacher details
$head_teacher_query = "SELECT firstname, lastname FROM users 
                      WHERE role = 'admin' AND school_id = ? LIMIT 1";
$stmt = $conn->prepare($head_teacher_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$head_teacher_result = $stmt->get_result();
$head_teacher = $head_teacher_result->fetch_assoc();

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

// Organize exam types by category
$exam_categories = [];
$all_exam_types = [];
while ($row = $exam_categories_result->fetch_assoc()) {
    if (!isset($exam_categories[$row['category']])) {
        $exam_categories[$row['category']] = [];
    }
    $exam_categories[$row['category']][] = $row['exam_type'];
    $all_exam_types[] = $row['exam_type'];
}

// Helper function to calculate overall score out of 20
function calculateOverallScore($scores, $max_scores) {
    if (empty($scores) || empty($max_scores)) return null;
    
    $total_score = 0;
    $total_max = 0;
    
    foreach ($scores as $index => $score) {
        if ($score !== null && isset($max_scores[$index]) && $max_scores[$index] > 0) {
            $total_score += $score;
            $total_max += $max_scores[$index];
        }
    }
    
    if ($total_max == 0) return null;
    return ($total_score / $total_max) * 20; // Convert to score out of 20
}

// Process exam results from database
function processExamResults($results) {
    // Build a simplified per-subject summary suitable for the sample template
    $exam_results = [];
    while ($row = $results->fetch_assoc()) {
        $subject = $row['subject_name'];
        $teacher = trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']);
        $topic = isset($row['topic']) && trim($row['topic']) !== '' ? trim($row['topic']) : 'Assessment';
        $score = isset($row['score']) ? (float)$row['score'] : null;
        $max = isset($row['max_score']) ? (float)$row['max_score'] : 3; // Default to 3 based on sample

        if (!isset($exam_results[$subject])) {
            $exam_results[$subject] = [
                'teacher' => $teacher,
                'topics' => [],
                'subject_id' => $row['subject_id'] ?? null
            ];
        }

        // Ensure teacher is set (use first non-empty)
        if (empty($exam_results[$subject]['teacher']) && !empty($teacher)) {
            $exam_results[$subject]['teacher'] = $teacher;
        }

        if (!isset($exam_results[$subject]['topics'][$topic])) {
            $exam_results[$subject]['topics'][$topic] = [
                'sum_score' => 0.0,
                'sum_max' => 0.0,
                'count' => 0,
                'entries' => [],
                'competency' => $topic // Store the competency description
            ];
        }

        // Add this entry to the topic
        $exam_results[$subject]['topics'][$topic]['entries'][] = ['score' => $score, 'max' => $max];
        if ($score !== null) {
            $exam_results[$subject]['topics'][$topic]['sum_score'] += $score;
            $exam_results[$subject]['topics'][$topic]['count'] += 1;
        }
        $exam_results[$subject]['topics'][$topic]['sum_max'] += $max;
    }

    return $exam_results;
}

// Utility Functions
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

// PDF Class Definition
class PDF extends FPDF {
    protected $school_details;

    function __construct($school_details) {
        parent::__construct();
        $this->school_details = $school_details;
    }

    function Header() {
        // Only show header on the first page
        if ($this->PageNo() == 1) {
            // Badge (larger)
            $badge_width = 35;
            $badge_height = 35;
            if (!empty($this->school_details['badge']) && file_exists('uploads/' . basename($this->school_details['badge']))) {
                $this->Image('uploads/' . basename($this->school_details['badge']), 10, 6, $badge_width, $badge_height);
            }

            // School info centered
            $this->SetY(14);
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 6, $this->school_details['school_name'], 0, 1, 'C');

            $this->SetFont('Arial', 'I', 9);
            if (!empty($this->school_details['motto'])) {
                $this->Cell(0, 5, 'Motto: ' . $this->school_details['motto'], 0, 1, 'C');
            }

            $this->SetFont('Arial', '', 9);
            if (!empty($this->school_details['email'])) {
                $this->Cell(0, 5, $this->school_details['email'], 0, 1, 'C');
            }
            $location_phone = trim(($this->school_details['location'] ?? '') . (empty($this->school_details['phone']) ? '' : ' | Phone: ' . $this->school_details['phone']));
            if (!empty($location_phone)) {
                $this->Cell(0, 5, $location_phone, 0, 1, 'C');
            }

            $this->SetFont('Arial', 'B', 11);
            $term_label = '';
            if (!empty($this->school_details['name'])) {
                $term_label = ' - ' . $this->school_details['name'];
                if (!empty($this->school_details['year'])) {
                    $term_label .= ' (' . $this->school_details['year'] . ')';
                }
            }
            $this->Cell(0, 6, 'End of Term Report' . $term_label, 0, 1, 'C');

           
            // Thick divider line
            $this->SetLineWidth(0.5);
            $this->Ln(3);
            $this->Line(10, $this->GetY(), $this->GetPageWidth() - 10, $this->GetY());
            $this->Ln(10);
        }
    }

    function Footer() {
        // Draw bold page border on every page
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.4);
        $margin = 7;
        $this->Rect($margin, 5, $this->GetPageWidth() - 2*$margin, $this->GetPageHeight() - 10);

        // Footer content inside border
        $this->SetY(-12);
        $this->SetX($margin);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell($this->GetPageWidth() - 2*$margin, 10, $this->school_details['email'], 0, 0, 'C');
    }

    function AddStudentInfo($student_details) {
        // Student info styled like download_all_reports
        $this->SetY($this->GetY() + 4);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'Student Information', 0, 1);

        $this->SetFont('Arial', '', 10);
        $line_height = 5;
        $label_width = 20;
        $left_col_width = 120;
        $right_col_width = 60;

        $x_start = $this->GetX();
        $y_start = $this->GetY();

        $left_details = [
            'Name' => $student_details['firstname'] . ' ' . $student_details['lastname'],
            'Class' => ($student_details['name'] ?? ''),
            'Stream' => ($student_details['stream'] ?? '')
        ];

        $right_details = [
            'Gender' => ($student_details['gender'] ?? ''),
            'Adm No.' => ($student_details['admission_number'] ?? '')
        ];

        // Left column
        $current_y = $y_start;
        foreach ($left_details as $label => $value) {
            $this->SetXY($x_start, $current_y);
            $this->Cell($label_width, $line_height, $label . ':', 0, 0);
            $this->Cell($left_col_width - $label_width, $line_height, $value, 0, 0);
            $current_y += $line_height;
        }

        // Right column
        $current_y = $y_start;
        $right_x = $x_start + $left_col_width - 20;
        foreach ($right_details as $label => $value) {
            $this->SetXY($right_x, $current_y);
            $this->Cell($label_width, $line_height, $label . ':', 0, 0);
            $this->Cell($right_col_width - $label_width, $line_height, $value, 0, 0);
            $current_y += $line_height;
        }

        // Image on right
        if (!empty($student_details['image'])) {
            $img_path = 'uploads/' . basename($student_details['image']);
            if (file_exists($img_path)) {
                $this->Image($img_path, $this->GetPageWidth() - 40, $y_start - 10, 25, 25);
            }
        }

        $max_y = max($current_y, $y_start + 15);
        $this->SetXY($x_start, $max_y + 3);
    }

    function AddResultsTable($exam_categories, $exam_results, $grading_scale, $is_few_subjects = false) {
        // Set initial line width for clean borders
        $this->SetLineWidth(0.3);
        
        // Table format to match the sample image exactly:
        // SUBJECT | TOPIC and Competency | SCORE | DESCRIPTOR | REMARKS | TEACHER / SIGN
        $this->SetFillColor(240, 240, 240);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 10);

        // Column widths (adjusted: wider DESCRIPTOR, slightly narrower REMARKS)
        $col_subject = 35;
        $col_topic = 60;
        $col_topic_num = 8; // width for numbering column inside topic
        $col_score = 15;
        $col_descriptor = 24; // increased by 4
        $col_remarks = 36;    // reduced by 4
        $col_teacher = 20;
        $row_h = 8;

        $tableX = 10;
        $table_width = $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks + $col_teacher;

        // Header row with borders
        $header_y = $this->GetY();
        $this->Cell($col_subject, $row_h, 'SUBJECT', 1, 0, 'C', true);
        $this->Cell($col_topic, $row_h, 'TOPIC and Competency', 1, 0, 'C', true);
        $this->Cell($col_score, $row_h, 'SCORE', 1, 0, 'C', true);
        $this->Cell($col_descriptor, $row_h, 'DESCRIPTOR', 1, 0, 'C', true);
        $this->Cell($col_remarks, $row_h, 'REMARKS', 1, 0, 'C', true);
        $this->Cell($col_teacher, $row_h, 'TR', 1, 1, 'C', true);

        // Content
        $this->SetFont('Arial', '', 9);
        $total_score = 0;        // Sum of topic averages (each out of 3)
        $total_max = 0;          // Sum of topic max scores (3 per topic)
        $total_topics = 0;       // Count of topics considered across all subjects

        foreach ($exam_results as $subject => $data) {
            $teacherInit = getTeacherInitials($data['teacher'] ?? '');

            // Calculate valid topics and subject block height
            $valid_topics = [];
            foreach ($data['topics'] as $topicName => $tdata) {
                if (strtolower($topicName) !== 'general assessment' && strtolower($topicName) !== 'assessment') {
                    $valid_topics[$topicName] = $tdata;
                }
            }
            $topic_count = count($valid_topics);

            $row_h = 8; // ensure local use
            $tableX = 10; // left margin

            // Compute subject block height accounting for multi-line topics
            $subject_block_height = $row_h; // minimum height
            if ($topic_count > 0) {
                $topic_width = $col_topic - $col_topic_num;
                foreach ($valid_topics as $topicName => $tdata) {
                    $topic_text = $topicName;
                    if (isset($tdata['competency']) && $tdata['competency'] !== $topicName) {
                        $topic_text = $topicName . ': ' . $tdata['competency'];
                    }
                    $topic_lines = $this->getStringLines($topic_text, $topic_width);
                    $topic_height = $topic_lines * $row_h;
                    $subject_block_height += $topic_height;
                }
            }
            $bottom_margin = 20;
            $page_height = $this->GetPageHeight();
            $available = $page_height - $bottom_margin - $this->GetY();
            if ($subject_block_height > $available) {
                // New page and reprint table header
                $this->AddPage();
                $this->SetFont('Arial', 'B', 9);
                $this->Cell($col_subject, $row_h, 'SUBJECT', 1, 0, 'C', true);
                $this->Cell($col_topic, $row_h, 'TOPIC and Competency', 1, 0, 'C', true);
                $this->Cell($col_score, $row_h, 'SCORE', 1, 0, 'C', true);
                $this->Cell($col_descriptor, $row_h, 'DESCRIPTOR', 1, 0, 'C', true);
                $this->Cell($col_remarks, $row_h, 'REMARKS', 1, 0, 'C', true);
                $this->Cell($col_teacher, $row_h, 'TEACHER / ', 1, 1, 'C', true);
                $this->SetFont('Arial', '', 8);
            }

            $subject_start_y = $this->GetY();

            // Compute subject average for remarks; include missed topics as zero
            $subject_sum = 0;
            $subject_n = 0;
            $subject_score_sum = 0;
            foreach ($valid_topics as $tdata) {
                $topic_avg = ($tdata['count'] > 0) ? ($tdata['sum_score'] / $tdata['count']) : 0;
                $subject_sum += $topic_avg;
                $subject_score_sum += $topic_avg;
                $subject_n += 1;
            }
            $subject_avg_score = $subject_n > 0 ? ($subject_sum / $subject_n) : 0;
            $subject_remarks = $this->getRemarksFromScore($subject_avg_score);

            if ($topic_count === 0) {
                // Single row for subjects with no topics
                $this->Cell($col_subject, $row_h, strtoupper($subject), 1, 0, 'C');
                $this->SetFont('Arial', 'B', 8);
                $this->Cell($col_topic, $row_h, 'No recorded topics this term', 1, 0, 'L');
                $this->SetFont('Arial', '', 8);
                $this->Cell($col_score, $row_h, '-', 1, 0, 'C');
                $this->Cell($col_descriptor, $row_h, '-', 1, 0, 'C');
                $this->Cell($col_remarks, $row_h, '-', 1, 0, 'L');
                $this->Cell($col_teacher, $row_h, $teacherInit, 1, 1, 'C');
                continue;
            }

            // Calculate actual subject height based on multi-line topics
            $subject_height = 0;
            $topic_width = $col_topic - $col_topic_num;
            foreach ($valid_topics as $topicName => $tdata) {
                $topic_text = $topicName;
                if (isset($tdata['competency']) && $tdata['competency'] !== $topicName) {
                    $topic_text = $topicName . ': ' . $tdata['competency'];
                }
                $topic_lines = $this->getStringLines($topic_text, $topic_width);
                $subject_height += $topic_lines * $row_h;
            }

            // First, draw all topic rows without subject and teacher cells
            $topic_index = 0;
            $current_y = $subject_start_y;
            
            foreach ($valid_topics as $topicName => $tdata) {
                $topic_index++;
                
                // Set position for this row
                $this->SetXY($tableX, $current_y);
                
                // Calculate average score for this topic
                $avg_score = 0;
                if ($tdata['count'] > 0) {
                    $avg_score = $tdata['sum_score'] / $tdata['count'];
                }

                // Get descriptor
                $descriptor = $this->getDescriptorFromScore($avg_score);

                // Skip subject cell (will be filled later with merged cell)
                $this->SetX($tableX + $col_subject);
                
                // Topic cell
                $this->SetFont('Arial', 'B', 8);
                // Build topic text without the numbering; numbering will be in its own cell
                $topic_text = $topicName;
                if (isset($tdata['competency']) && $tdata['competency'] !== $topicName) {
                    $topic_text = $topicName . ': ' . $tdata['competency'];
                }
                
                // Calculate how many lines the topic text will need
                $topic_lines = $this->getStringLines($topic_text, $topic_width);
                $topic_cell_height = $topic_lines * $row_h;
                
                // Numbering cell with right border acts as a vertical separator
                $this->Cell($col_topic_num, $topic_cell_height, $topic_index . '.', 1, 0, 'C');
                
                // Topic text cell with text wrapping
                $this->MultiCell($topic_width, $row_h, $topic_text, 1, 'L');
                
                // Adjust current position after MultiCell
                $this->SetXY($tableX + $col_subject + $col_topic, $current_y);
                
                // Score, descriptor cells - need to match the topic cell height
                $this->SetFont('Arial', '', 8);
                $this->Cell($col_score, $topic_cell_height, ($tdata['count'] > 0 ? number_format($avg_score, 1) : '-'), 1, 0, 'C');
                $this->Cell($col_descriptor, $topic_cell_height, $descriptor, 1, 0, 'C');
                
                // Move to next topic position
                $current_y += $topic_cell_height;
            }

            // Draw merged subject, remarks, and teacher rectangles to fully close borders
            $this->Rect($tableX, $subject_start_y, $col_subject, $subject_height);
            $this->Rect($tableX + $col_subject + $col_topic + $col_score + $col_descriptor, $subject_start_y, $col_remarks, $subject_height);
            $this->Rect($tableX + $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks, $subject_start_y, $col_teacher, $subject_height);

            // Place remark text in merged remarks cell
            $this->SetXY($tableX + $col_subject + $col_topic + $col_score + $col_descriptor + 1, $subject_start_y + ($subject_height / 2) - ($row_h / 2));
            $this->MultiCell($col_remarks - 2, 4, $subject_remarks, 0, 'L');

            // Center subject name vertically within the merged cell
            $this->SetFont('Arial', 'B', 9);
            $this->SetXY($tableX, $subject_start_y + ($subject_height / 2) - ($row_h / 2));
            $this->Cell($col_subject, $row_h, strtoupper($subject), 0, 0, 'C');

            // Center teacher initials vertically within the merged cell
            $this->SetXY($tableX + $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks, $subject_start_y + ($subject_height / 2) - ($row_h / 2));
            $this->Cell($col_teacher, $row_h, $teacherInit, 0, 0, 'C');

            // Set Y to the end of this subject block
            $this->SetY($subject_start_y + $subject_height);

            // Add to totals (aggregate by topics)
            if ($subject_n > 0) {
                $total_score += $subject_score_sum;     // sum of topic averages (missed topics counted as 0)
                $total_max += ($subject_n * 3);         // each topic max is 3
                $total_topics += $subject_n;            // count topics
            }
        }

        // Combine Total Score and Average on one row
        $this->SetFont('Arial', 'B', 9);
        $average_score = $total_topics > 0 ? ($total_score / $total_topics) : 0;
        $this->Cell($col_subject + $col_topic, $row_h, 'TOTAL SCORES', 1, 0, 'C', true);
        $this->Cell($col_score, $row_h, number_format($total_score, 1), 1, 0, 'C', true);
        $this->Cell($col_descriptor + $col_remarks + $col_teacher, $row_h, 'AVERAGE : ' . number_format($average_score, 1), 1, 1, 'C', true);

        // Overall comment row: Descriptor, Identifier (grade), and small comment
        $percentage = $average_score > 0 ? ($average_score / 3) * 100 : 0;
        $overall_descriptor = $this->getDescriptorFromScore($average_score);
        $identifier_num = $this->getIdentifierFromScore($average_score);

        // Add spacing before overall row to separate from totals row
        $this->Ln(2);

        // Render bold labels and computed identifier centered in a single bordered row
        $this->SetFont('Arial', 'B', 9);
        $label1 = 'OVERALL COMMENT: ';
        $spacer = '    '; // extra spacing between chunks
        $label2 = $spacer . '| ' . 'IDENTIFIER: ';
        $value1 = $overall_descriptor;
        $value2 = (string)$identifier_num;
        $full_width = $col_subject + $col_topic + $col_score + $col_descriptor + $col_remarks + $col_teacher;
        $y = $this->GetY();
        $x = $this->GetX();
        // Draw the enclosing cell border first
        $this->Cell($full_width, $row_h, '', 1, 0, 'L');
        // Measure total text width for centering
        $w_label1 = $this->GetStringWidth($label1);
        $this->SetFont('Arial', '', 9);
        $w_value1 = $this->GetStringWidth($value1);
        $this->SetFont('Arial', 'B', 9);
        $w_label2 = $this->GetStringWidth($label2);
        $this->SetFont('Arial', '', 9);
        $w_value2 = $this->GetStringWidth($value2);
        $text_width = $w_label1 + $w_value1 + $w_label2 + $w_value2;
        // Center horizontally and vertically within the row
        $text_x = $x + ($full_width - $text_width) / 2;
        $text_y = $y + ($row_h / 2) - 1; // improved vertical centering
        $this->SetXY($text_x, $text_y);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w_label1, 0, $label1, 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_value1, 0, $value1, 0, 0, 'L');
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($w_label2, 0, $label2, 0, 0, 'L');
        $this->SetFont('Arial', '', 9);
        $this->Cell($w_value2, 0, $value2, 0, 1, 'L');
        // Move Y to next line after the bordered row
        $this->SetY($y + $row_h);

        return $average_score;
    }

    function AddNextTermStartDate() {
        if (!empty($this->school_details['next_term_start_date']) && $this->school_details['next_term_start_date'] !== '0000-00-00') {
            // Check if there's enough space on current page (table height + spacing)
            $required_space = 3 + 8 + 3; // Ln(3) + table_height(8) + bottom spacing(3)
            $available_space = $this->GetPageHeight() - $this->GetY() - 15; // 15 for bottom margin
            
            if ($available_space < $required_space) {
                // Not enough space, add a new page
                $this->AddPage();
            } else {
                // Enough space, just add minimal spacing
                $this->Ln(3);
            }
            
            // Create a single row table for next term date
            $table_width = 100;
            $table_height = 8;
            $x_start = $this->GetX() + ($this->GetPageWidth() - $table_width) / 2; // Center the table
            $y_start = $this->GetY();
            
            // Format the date text
            $date_text = date('l, d F Y', strtotime($this->school_details['next_term_start_date']));
            $full_text = 'Next Term Starts: ' . $date_text;
            
            // Add text in the single row with border and background
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 0, 0);
            $this->SetFillColor(240, 240, 240);
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.5);
            $this->SetXY($x_start, $y_start);
            $this->Cell($table_width, $table_height, $full_text, 1, 0, 'C', true);
            
            // Move cursor below the table
            $this->SetY($y_start + $table_height + 3);
        }
    }

    function AddTeacherComments($class_teacher, $head_teacher, $class_teacher_comment, $head_teacher_comment) {
        $this->Ln(8);

        // Simplified comments without boxes/tables; pull text from DB via parameter
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, 'CLASS TEACHER COMMENT:', 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, 5, !empty($class_teacher_comment) ? $class_teacher_comment : 'No comment available.', 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 5, 'Signature: _______________', 0, 1, 'L');

        $this->Ln(4);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, 'HEAD TEACHER COMMENT:', 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, 5, !empty($class_teacher_comment) ? $class_teacher_comment : 'No comment available.', 0, 'L');
        $this->SetFont('Arial', '', 8);
        // Intentionally omit head teacher name; only show signature line
        $this->Cell(0, 5, 'Signature: _______________', 0, 1, 'L');

        $this->Ln(3);

    }

    function AddDescriptorTable() {
        $this->Ln(10);
        
        // Descriptor explanation table
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'DESCRIPTOR EXPLANATION', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(240, 240, 240);
        
        // Table headers
        $col_descriptor = 40;
        $col_score_range = 30;
        $col_meaning = 120;
        
        $this->Cell($col_descriptor, 8, 'DESCRIPTOR', 1, 0, 'C', true);
        $this->Cell($col_score_range, 8, 'SCORE RANGE', 1, 0, 'C', true);
        $this->Cell($col_meaning, 8, 'MEANING', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 9);
        $descriptors = [
            ['Exceptional', '2.5 - 3.0', 'Exceptional performance demonstrating mastery of all competencies'],
            ['Outstanding', '2.0 - 2.4', 'Good performance with solid understanding of most competencies'],
            ['Satisfactory', '1.5 - 1.9', 'Acceptable performance meeting basic requirements'],
            ['Basic', '1.0 - 1.4', 'Minimal performance requiring additional support'],
            ['Elementary', '0.0 - 0.9', 'Beginning level requiring significant improvement']
        ];
        
        foreach ($descriptors as $row) {
            $this->Cell($col_descriptor, 6, $row[0], 1, 0, 'C');
            $this->Cell($col_score_range, 6, $row[1], 1, 0, 'C');
            $this->Cell($col_meaning, 6, $row[2], 1, 1, 'L');
        }
    }

    function AddGradingScaleTable($grading_scale) {
        $this->Ln(8);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'GRADING SCALE', 0, 1, 'C');
        $left_margin = 10;
        $right_margin = 10;
        $table_width = $this->GetPageWidth() - ($left_margin + $right_margin);
        $this->SetX($left_margin);
        $max_cols = 6;
        $grades = [];
        $ranges = [];
        foreach ($grading_scale as $grade => $scale) {
            if (count($grades) >= $max_cols) { break; }
            $grades[] = (string)$grade;
            $min_int = (string)intval(round((float)$scale['min_score']));
            $max_int = (string)intval(round((float)$scale['max_score']));
            $ranges[] = $min_int.'-'.$max_int;
        }
        $num_cols = max(1, min($max_cols, count($grades)));
        $col_w = floor($table_width / $num_cols);
        $remaining = $table_width - ($col_w * $num_cols);
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(240, 240, 240);
        for ($i = 0; $i < $num_cols; $i++) {
            $w = $col_w + ($i == ($num_cols - 1) ? $remaining : 0);
            $this->Cell($w, 8, isset($grades[$i]) ? $grades[$i] : '', 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetFont('Arial', '', 10);
        for ($i = 0; $i < $num_cols; $i++) {
            $w = $col_w + ($i == ($num_cols - 1) ? $remaining : 0);
            $this->Cell($w, 8, isset($ranges[$i]) ? $ranges[$i] : '', 1, 0, 'C');
        }
        $this->Ln();
    }

    function AddCompetencyExplanation() {
        $this->Ln(8);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, 'COMPETENCY EXPLANATION', 0, 1, 'C');

        $left_margin = 10;
        $right_margin = 10;
        $width = $this->GetPageWidth() - ($left_margin + $right_margin);

        $this->SetX($left_margin);
        $this->SetFont('Arial', '', 9);
        $text = 'Competency: The overall expected capability of a learner at the end of a topic, term or year, after being exposed to a body of knowledge, skills and values. Descriptor: Gives details on the extent to which the learner has achieved the stipulated learning outcomes in a given topic. Identifier: A label or grade that distinguishes learners according to their learning achievement of the set competencies. It refers to the average of the scores attained for the different learning outcomes that make up the competency.';
        $this->MultiCell($width, 5, $text, 0, 'L');
    }

    private function getDescriptorFromScore($score) {
        // Based on the sample image descriptors
        if ($score >= 2.5) return 'Exceptional';
        if ($score >= 2.0) return 'Outstanding';
        if ($score >= 1.5) return 'Satisfactory';
        if ($score >= 1.0) return 'Basic';
        return 'Elementary';
    }

    private function getRemarksFromScore($score) {
        // Generate remarks based on score performance
        if ($score >= 2.5) return 'Very nice performance';
        if ($score >= 2.0) return 'Good work, maintain';
        if ($score >= 1.5) return 'Fair but you can do better';
        if ($score >= 1.0) return 'Poor, please concentrate';
        return 'Below expectations';
    }

    private function getIdentifierFromScore($score) {
        // Map average out of 3 to identifier 3/2/1
        if ($score >= 2.5) return 3;
        if ($score >= 1.5) return 2;
        return 1;
    }

    private function getStringLines($text, $max_width) {
        // Calculate how many lines the text will need when wrapped
        $words = explode(' ', $text);
        $lines = 1;
        $current_line_width = 0;
        
        foreach ($words as $word) {
            $word_width = $this->GetStringWidth($word . ' ');
            if ($current_line_width + $word_width > $max_width && $current_line_width > 0) {
                $lines++;
                $current_line_width = $word_width;
            } else {
                $current_line_width += $word_width;
            }
        }
        
        return $lines;
    }

    // New method for summary table format (from download_all_reports.php)
    function AddSummaryTable($exam_categories, $exam_results, $grading_scale, $is_few_subjects = false) {
        $this->SetFillColor(220, 220, 220);
        $this->SetTextColor(0, 0, 0);

        // Compute dynamic widths to fit all columns on the page
        $left_margin = 10;
        $right_margin = 10;
        $table_width = $this->GetPageWidth() - ($left_margin + $right_margin);
        $this->SetX($left_margin);

        // Determine activity presence
        $has_activity_categories = false;
        $activity_categories = [];
        foreach ($exam_categories as $category => $exam_types) {
            if (!empty($exam_types) && $exam_types[0] === 'activity') {
                $has_activity_categories = true;
                $activity_categories[] = $category;
            }
        }

        $num_dynamic_cols = count($exam_categories) + ($has_activity_categories ? 1 : 0);
        if ($num_dynamic_cols <= 0) { $num_dynamic_cols = 1; }

        // Baseline fixed widths (minimums)
        $min_subject_w = 36;
        $min_total_w = 16;
        $min_grade_w = 16;
        $min_remarks_w = 24;
        $min_teacher_w = 18;
        $min_score_w = 12;

        $fixed_total = $min_subject_w + $min_total_w + $min_grade_w + $min_remarks_w + $min_teacher_w;
        $remaining_for_scores = $table_width - $fixed_total;

        // Fallback in case of very tight space
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

        // Adopt computed widths
        $header_col_width = $min_subject_w;
        $teacher_col_width = $min_teacher_w;
        $grade_col_width = $min_grade_w;
        $remarks_col_width = $min_remarks_w;
        $row_height = 8;

        // Headers
        $header_height = 9;
        $this->Cell($header_col_width, $header_height, 'SUBJECT', 1, 0, 'C', true);
        
        // Category headers with max scores
        $last_activity_category = null;
        $activity_categories_processed = 0;
        $total_activity_categories = 0;
        
        // Count total activity categories first
        foreach ($exam_categories as $category => $exam_types) {
            if (!empty($exam_types) && $exam_types[0] === 'activity') {
                $total_activity_categories++;
                $last_activity_category = $category;
            }
        }
        
        foreach ($exam_categories as $category => $exam_types) {
            // Get max score for this category from first subject that has it
            $max_score = null;
            foreach ($exam_results as $subject => $data) {
                if (isset($data['categories'][$category]['max_score'])) {
                    $max_score = $data['categories'][$category]['max_score'];
                    break;
                }
            }
            
            // Display category with max score in brackets
            $header_text = $category;
            if ($max_score !== null) {
                if (strlen($category) > 5) {
                    $header_text = substr($category, 0, 4) . "\n(" . $max_score . ")";
                } else {
                    $header_text = $category . "\n(" . $max_score . ")";
                }
            }
            
            $this->Cell($score_col_width, $header_height, $header_text, 1, 0, 'C', true);
            
            // Track activity categories processed
            if (!empty($exam_types) && $exam_types[0] === 'activity') {
                $activity_categories_processed++;
            }
            
            // Add Score/20 header after ALL activity categories are processed
            if ($has_activity_categories && $activity_categories_processed === $total_activity_categories && $category === $last_activity_category) {
                $this->Cell($score_col_width, $header_height, 'Act/20', 1, 0, 'C', true);
            }
        }
        
        // Additional columns
        $this->Cell($min_total_w, $header_height, 'Total', 1, 0, 'C', true);
        $this->Cell($grade_col_width, $header_height, 'GRADE', 1, 0, 'C', true);
        $this->Cell($remarks_col_width, $header_height, 'REMARKS', 1, 0, 'C', true);
        $this->Cell($teacher_col_width, $header_height, 'TEACHER', 1, 1, 'C', true);

        // Content
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', ($score_col_width <= $min_score_w ? 7.5 : 8.5));
        $total_subject_marks = 0;
        $total_max_marks = 0;
        $subject_count = 0;

        foreach ($exam_results as $subject => $data) {
            $this->Cell($header_col_width, $row_height, $subject, 1, 0, 'L');
            
            $teacher_initials = 'N/A'; 

            // Track activity scores and exam scores separately
            $activity_total = 0;
            $activity_max = 0;
            $activity_count = 0;
            $exam_categories_total = 0;
            $exam_categories_max = 0;
            $has_any_score = false;

            // First pass: Count available categories and their max scores
            $expected_activity_categories = 0;
            $expected_exam_categories = 0;
            foreach ($exam_categories as $category => $exam_types) {
                if (!empty($exam_types)) {
                    if ($exam_types[0] === 'activity') {
                        $expected_activity_categories++;
                    } else {
                        $expected_exam_categories++;
                    }
                }
            }

            // Display scores by category
            $last_activity_category = null;
            $activity_categories_processed = 0;
            $total_activity_categories = 0;
            
            // Count total activity categories first
            foreach ($exam_categories as $category => $exam_types) {
                if (!empty($exam_types) && $exam_types[0] === 'activity') {
                    $total_activity_categories++;
                    $last_activity_category = $category;
                }
            }
            
            foreach ($exam_categories as $category => $exam_types) {
                $max_score = 0;
                if (isset($data['categories'][$category])) {
                    $score = $data['categories'][$category]['score'];
                    $max_score = $data['categories'][$category]['max_score'];
                    
                    if ($score !== null) {
                        $has_any_score = true;
                        // Format score based on exam type
                        if (!empty($exam_types) && $exam_types[0] === 'exam') {
                            $formatted_score = number_format((float)$score, 0, '.', '');
                        } else {
                            $formatted_score = number_format((float)$score, 1, '.', '');
                        }
                        $this->Cell($score_col_width, $row_height, $formatted_score, 1, 0, 'C');
                        
                        // Separate tracking for activity and exam categories
                        if (!empty($exam_types) && $exam_types[0] === 'activity') {
                            $activity_total += $score;
                            $activity_max += $max_score;
                            $activity_count++;
                        } else {
                            $exam_categories_total += $score;
                            $exam_categories_max += $max_score;
                        }
                    } else {
                        $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                        if (!empty($exam_types) && $exam_types[0] === 'activity') {
                            $activity_max += $max_score;
                            $activity_count++;
                        } else {
                            $exam_categories_max += $max_score;
                        }
                    }
                } else {
                    $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                }
                
                // Track activity categories processed
                if (!empty($exam_types) && $exam_types[0] === 'activity') {
                    $activity_categories_processed++;
                }
                
                // Add Score/20 after ALL activity categories are processed
                if ($has_activity_categories && $activity_categories_processed === $total_activity_categories && $category === $last_activity_category) {
                    if ($activity_count > 0) {
                        if ($activity_total > 0) {
                            $score_out_of_20 = ($activity_max > 0) ? 
                                round(($activity_total / $activity_max) * 20) : 0;
                            $this->Cell($score_col_width, $row_height, (string)$score_out_of_20, 1, 0, 'C');
                        } else {
                            $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                        }
                    } else {
                        $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                    }
                }
            }

            // If the subject has any defined categories, compute total (missing scores count as 0)
            $has_any_category = ($activity_count > 0) || ($exam_categories_max > 0);
            if ($has_any_category) {
                $total = ($has_activity_categories && $activity_count > 0 ? 
                    round(($activity_total / $activity_max) * 20) : 0) + 
                    ($exam_categories_max > 0 ? 
                        round(($exam_categories_total / $exam_categories_max) * 
                        ($has_activity_categories ? 80 : 100)) : 0);
                
                $max_possible_total = 100;
                $subject_percentage = $total;

                if ($max_possible_total > 0) {
                    $total_subject_marks += $total;
                    $total_max_marks += $max_possible_total;
                    $subject_count++;
                }

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

        // Add extra spacing for students with fewer subjects to fill the page better
        if ($subject_count < 13) {
            $extra_space = (13 - $subject_count) * 2;
            $this->Ln($extra_space);
        } else {
            $this->Ln(4);
        }

        // Calculate overall average percentage
        $average_percentage = ($total_max_marks > 0 && $subject_count > 0) ? 
            ($total_subject_marks / $subject_count) : 0;
        
        return round($average_percentage, 1);
    }

    // Helper methods for summary table
    private function getGradeFromPercentage($percentage, $grading_scale) {
        foreach ($grading_scale as $grade => $scale) {
            if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) {
                return $grade;
            }
        }
        return 'N/A';
    }

    private function getRemarksFromPercentage($percentage, $grading_scale) {
        foreach ($grading_scale as $grade => $scale) {
            if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) {
                return $scale['remarks'];
            }
        }
        return 'N/A';
    }

    private function getTeacherInitials($fullname) {
        $names = explode(' ', trim($fullname));
        $initials = '';
        foreach ($names as $name) {
            if (!empty($name)) {
                $initials .= strtoupper(substr($name, 0, 1));
            }
        }
        return $initials;
    }
}

// Get exam results
$results_query = "
SELECT 
    s.subject_id,
    s.subject_name,
    e.exam_type,
    e.category,
    er.score,
    er.topic AS topic,
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

// Process results for detailed format
$exam_results = processExamResults($results);
// Merge in topics done in class for each subject, even if student missed them
foreach ($exam_results as $subjName => &$subjData) {
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
$subject_count = count($exam_results);

// Process results for summary format (convert to categories structure)
$summary_exam_results = [];
$summary_exam_categories = [];

// Re-query for summary format with categories structure
$summary_results_query = "
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
INNER JOIN exam_subjects es ON es.exam_id = e.exam_id AND es.subject_id = s.subject_id
LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
    AND er.student_id = ? 
    AND er.subject_id = s.subject_id
    AND er.school_id = ?
LEFT JOIN (
    SELECT 
        subject_id, 
        user_id
    FROM teacher_subjects
    WHERE is_class_teacher = 0
    AND class_id = ?
) ts ON s.subject_id = ts.subject_id
LEFT JOIN (
    SELECT 
        subject_id, 
        user_id
    FROM teacher_subjects
    WHERE is_class_teacher = 1
    AND class_id = ?
) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id
LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id 
    AND u.role = 'teacher'
WHERE s.class_id = ?
AND ss.student_id = ?
ORDER BY s.subject_name, e.category, e.exam_type";

$stmt = $conn->prepare($summary_results_query);
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
$summary_results = $stmt->get_result();

// Process summary results
while ($row = $summary_results->fetch_assoc()) {
    $subject = $row['subject_name'];
    $category = $row['category'];
    $exam_type = $row['exam_type'];
    
    if (!isset($summary_exam_results[$subject])) {
        $summary_exam_results[$subject] = [
            'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']),
            'categories' => []
        ];
    }
    
    if (!isset($summary_exam_results[$subject]['categories'][$category])) {
        $summary_exam_results[$subject]['categories'][$category] = [
            'score' => null,
            'max_score' => $row['max_score']
        ];
    }
    
    if ($row['score'] !== null) {
        $summary_exam_results[$subject]['categories'][$category]['score'] = $row['score'];
    }
    
    // Build categories structure
    if (!isset($summary_exam_categories[$category])) {
        $summary_exam_categories[$category] = [];
    }
    if (!in_array($exam_type, $summary_exam_categories[$category])) {
        $summary_exam_categories[$category][] = $exam_type;
    }
}

// Calculate position
$position_query = "
WITH SubjectAverages AS (
    SELECT 
        er.student_id,
        s.subject_name,
        AVG((er.score / e.max_score) * 100) as subject_average
    FROM exam_results er
    JOIN exams e ON er.exam_id = e.exam_id
    JOIN subjects s ON er.subject_id = s.subject_id
    WHERE e.term_id = ? 
    AND s.class_id = ?
    GROUP BY er.student_id, s.subject_name
),
StudentAverages AS (
    SELECT 
        s.id,
        s.firstname,
        s.lastname,
        AVG(sa.subject_average) as average_percentage
    FROM students s
    JOIN SubjectAverages sa ON s.id = sa.student_id
    WHERE s.class_id = ?
    GROUP BY s.id, s.firstname, s.lastname
),
Rankings AS (
    SELECT 
        id,
        firstname,
        lastname,
        ROUND(average_percentage, 1) as average_percentage,
        DENSE_RANK() OVER (ORDER BY ROUND(average_percentage, 1) DESC) as position,
        COUNT(*) OVER () as total_students
    FROM StudentAverages
)
SELECT position, total_students, average_percentage
FROM Rankings
WHERE id = ?";

$stmt = $conn->prepare($position_query);
$stmt->bind_param("iiii", 
    $current_term_id, 
    $class_id, 
    $class_id, 
    $student_id
);
$stmt->execute();
$position_result = $stmt->get_result();
$position_data = $position_result->fetch_assoc();

// Generate PDF
$pdf = new PDF($school_details);
$pdf->AliasNbPages();
$pdf->AddPage();

$is_few_subjects = ($subject_count <= 13);
if ($is_few_subjects) {
    $pdf->SetFontSize(11);
} else {
    $pdf->SetFontSize(11);
}

$pdf->AddStudentInfo($student_details);
$pdf->Ln(2);

$is_compact_layout = false;
$average_score = $pdf->AddResultsTable($exam_categories, $exam_results, $grading_scale, $is_compact_layout);

// Add descriptor and competence explanations on the first page (as they were originally)
$pdf->AddDescriptorTable();
$pdf->AddGradingScaleTable($grading_scale);
$pdf->AddCompetencyExplanation();

// ========================================
// COMBINED REPORT FORMAT
// ========================================
// This report now includes TWO formats:
// 1. DETAILED FORMAT (Page 1): Competency-based assessment with topics and descriptors
// 2. SUMMARY FORMAT (Page 2): Traditional grade table with categories and totals
// ========================================

// Add a new page for the summary table format
$pdf->AddPage();

// Add header for summary section
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 8, 'ACADEMIC PERFORMANCE SUMMARY', 0, 1, 'C');
$pdf->Ln(5);

// Add a separator line
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.5);
$pdf->Line(10, $pdf->GetY(), $pdf->GetPageWidth() - 10, $pdf->GetY());
$pdf->Ln(5);

// Add the summary table
$summary_average = $pdf->AddSummaryTable($summary_exam_categories, $summary_exam_results, $grading_scale, $is_compact_layout);

// Add performance summary exactly as in download_all_reports.php
$pdf->Ln(8);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 6, 'PERFORMANCE SUMMARY', 0, 1, 'C');
$pdf->Ln(3);

// Overall performance details (matching download_all_reports.php format)
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(45, 6, 'Overall Percentage:', 0, 0, 'R');
$pdf->Cell(25, 6, sprintf("%.1f%%", $summary_average), 0, 0);
$pdf->Cell(30, 6, 'Overall Grade:', 0, 0, 'R');
$overall_grade_summary = getGrade($summary_average, $grading_scale);
$pdf->Cell(18, 6, $overall_grade_summary, 0, 0);
$pdf->Cell(45, 6, 'Overall Comment:', 0, 0, 'R');
$overall_comment = getOverallComment($overall_grade_summary, $grading_scale);
$pdf->MultiCell(0, 6, $overall_comment, 0, 'L');
$pdf->Ln(2);

// Add position information (matching download_all_reports.php format)
$pdf->Ln(2);

// Create a small table for position information (matching download_all_reports.php)
$table_width = 80;
$table_height = 7;
$x_start = $pdf->GetX();
$y_start = $pdf->GetY();

// Draw table border
$pdf->Rect($x_start, $y_start, $table_width, $table_height);

// Add label and value on the same line
$pdf->SetXY($x_start, $y_start);
$pdf->SetFillColor(240, 240, 240); // Light gray background
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(40, $table_height, 'Position in Class:', 1, 0, 'C', true);

$pdf->SetFillColor(255, 255, 255); // White background for value
$pdf->SetFont('Arial', 'B', 11);
if ($position_data) {
    $pdf->Cell(40, $table_height, $position_data['position'] . ' out of ' . $position_data['total_students'], 1, 1, 'C', true);
} else {
    $pdf->Cell(40, $table_height, 'N/A', 1, 1, 'C', true);
}
$pdf->Ln(5);

// Convert average (out of 3) to percentage out of 100 for comments lookup
$average_percentage = ($average_score !== null) ? (int) round(min(100, max(0, ($average_score / 3) * 100))) : null;

// Fetch comments from DB using the same method as download_all_reports.php
$comments_query = "SELECT 
    id,
    type, 
    comment,
    min_score,
    max_score 
    FROM class_comment_templates 
    WHERE school_id = ? 
    AND (class_id = ? OR class_id IS NULL)
    AND ? BETWEEN min_score AND max_score
    ORDER BY class_id DESC, type";  // Prioritize specific class comments over default

$stmt = $conn->prepare($comments_query);
if (!$stmt) {
    throw new Exception("Failed to prepare comments query: " . $conn->error);
}

$stmt->bind_param("iid", 
    $school_id,
    $class_id,
    $average_percentage
);

if (!$stmt->execute()) {
    throw new Exception("Failed to execute comments query: " . $stmt->error);
}

$comments_result = $stmt->get_result();

// Fallback comments in case none are found
$class_teacher_comment = 'No comments available for this performance level';
$head_teacher_comment = 'No comments available for this performance level';

// Process returned comments
while ($row = $comments_result->fetch_assoc()) {
    if ($row['type'] == 'class_teacher') {
        $class_teacher_comment = $row['comment'];
    } elseif ($row['type'] == 'head_teacher') {
        $head_teacher_comment = $row['comment'];
    }
}

// Add comments and signature lines (matching download_all_reports.php format)
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

// Add next term start date after comments
$pdf->AddNextTermStartDate();

// Output the PDF
$student_name = str_replace(' ', '_', $student_details['firstname'] . '_' . $student_details['lastname']);
$filename = 'report_card_' . $student_name . '.pdf';

// Clean output buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Send appropriate headers
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Output PDF
$pdf->Output('D', $filename);

// Close database connection
$conn->close();
exit();