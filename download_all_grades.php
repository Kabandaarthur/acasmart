 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$class_id = $_GET['class_id'] ?? null;
$exam_type = $_GET['exam_type'] ?? '';
$category = $_GET['category'] ?? '';

if (!$class_id || !$exam_type) {
    die("Missing required parameters");
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$school_id = $_SESSION['school_id'];

// Function to get current term
function getCurrentTerm($conn, $school_id) {
    $query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get school information
function getSchoolInfo($conn, $school_id) {
    $query = "SELECT school_name, email, phone, badge, location FROM schools WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get grading scale
function getGradingScale($conn, $school_id) {
    $query = "SELECT grade, min_score, max_score, remarks FROM grading_scales WHERE school_id = ? ORDER BY min_score DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $grades = [];
    while ($row = $result->fetch_assoc()) {
        $grades[] = $row;
    }
    return $grades;
}

// Function to calculate grade
function calculateGrade($score, $max_score, $grades) {
    if ($score === '-' || !is_numeric($score) || !is_numeric($max_score) || $max_score == 0) {
        return ['grade' => '-', 'remarks' => '-'];
    }
    
    // Convert score to percentage
    $percentage = ($score / $max_score) * 100;
    
    foreach ($grades as $grade) {
        if ($percentage >= $grade['min_score'] && $percentage <= $grade['max_score']) {
            return [
                'grade' => $grade['grade'],
                'remarks' => $grade['remarks']
            ];
        }
    }
    return ['grade' => '-', 'remarks' => '-'];
}

// Get class report data with overall calculations
function getClassReport($conn, $class_id, $school_id) {
    $current_term = getCurrentTerm($conn, $school_id);
    if (!$current_term) {
        die("No active term found");
    }

    // Get class name
    $class_query = "SELECT name FROM classes WHERE id = ? AND school_id = ?";
    $stmt = $conn->prepare($class_query);
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $class_result = $stmt->get_result()->fetch_assoc();
    $class_name = $class_result['name'] ?? 'Unknown';

    // Get all students in the class
    $students_query = "SELECT id, firstname, lastname FROM students 
                      WHERE class_id = ? AND school_id = ? 
                      ORDER BY lastname, firstname";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    
    $report = [];
    $subjects = [];
    
    while ($student = $students_result->fetch_assoc()) {
        // Get exam results with grades for each student
        $results_query = "WITH SubjectScores AS (
            SELECT 
                s.subject_name,
                s.subject_id,
                er.score,
                er.topic,
                e.max_score,
                e.exam_type,
                e.category,
                u.firstname AS teacher_firstname,
                u.lastname AS teacher_lastname,
                ((er.score / e.max_score) * 100) as percentage
            FROM subjects s
            JOIN exam_results er ON s.subject_id = er.subject_id
            JOIN exams e ON er.exam_id = e.exam_id
            LEFT JOIN teacher_subjects ts ON s.subject_id = ts.subject_id AND ts.class_id = ?
            LEFT JOIN users u ON ts.user_id = u.user_id
            WHERE er.student_id = ?
            AND er.school_id = ?
            AND e.term_id = ?
            AND s.class_id = ?
        )
        SELECT 
            subject_name,
            GROUP_CONCAT(DISTINCT CONCAT(teacher_firstname, ' ', teacher_lastname)) as teacher,
            GROUP_CONCAT(DISTINCT topic) as topics,
            AVG(percentage) as avg_percentage,
            SUM(score) as total_score,
            SUM(max_score) as total_max_score
        FROM SubjectScores
        GROUP BY subject_name";
        
        $stmt = $conn->prepare($results_query);
        $stmt->bind_param("iiiii", 
            $class_id,
            $student['id'],
            $school_id,
            $current_term['id'],
            $class_id
        );
        $stmt->execute();
        $results = $stmt->get_result();
        
        $student_name = $student['firstname'] . ' ' . $student['lastname'];
        $report[$student_name] = [
            'subjects' => [],
            'overall_score' => 0,
            'overall_max' => 0
        ];
        
        while ($row = $results->fetch_assoc()) {
            $subject = $row['subject_name'];
            if (!in_array($subject, $subjects)) {
                $subjects[] = $subject;
            }
            
            $report[$student_name]['subjects'][$subject] = [
                'score' => $row['total_score'],
                'max_score' => $row['total_max_score'],
                'percentage' => $row['avg_percentage'],
                'teacher' => $row['teacher']
            ];
            
            // Add to overall totals
            $report[$student_name]['overall_score'] += $row['total_score'];
            $report[$student_name]['overall_max'] += $row['total_max_score'];
        }
    }
    
    return [
        'report' => $report,
        'subjects' => $subjects,
        'term_info' => $current_term['name'] . ' - ' . $current_term['year'],
        'class_name' => $class_name
    ];
}

// Get necessary data
$school_info = getSchoolInfo($conn, $school_id);
$grades_scale = getGradingScale($conn, $school_id);
$report_data = getClassReport($conn, $class_id, $school_id);

// Generate PDF
require('fpdf.php');

class PDF extends FPDF {
    public $schoolInfo;
    public $termInfo;
    public $subjects = [];
    public $studentColWidth = 50;
    public $subjectColWidth = 15;
    
    function setSchoolInfo($info) {
        $this->schoolInfo = $info;
    }

    function setTermInfo($termInfo) {
        $this->termInfo = $termInfo;
    }
    
    function setSubjects($subjects) {
        $this->subjects = $subjects;
    }

    function Header() {
        if (!$this->schoolInfo) return;
        
        // Extended background for header
        $this->SetFillColor(240, 248, 255);
        $this->Rect(0, 0, $this->w, 45, 'F');
        
        // Badge handling
        if (!empty($this->schoolInfo['badge'])) {
            $badge_path = 'uploads/' . $this->schoolInfo['badge'];
            if (file_exists($badge_path) && getimagesize($badge_path)) {
                list($width, $height) = getimagesize($badge_path);
                $aspect_ratio = $width / $height;
                
                $max_height = 30;
                $max_width = 30;
                
                if ($aspect_ratio > 1) {
                    $new_width = $max_width;
                    $new_height = $max_width / $aspect_ratio;
                } else {
                    $new_height = $max_height;
                    $new_width = $max_height * $aspect_ratio;
                }
                
                $y_position = 12 + ($max_height - $new_height) / 2;
                $this->Image($badge_path, 10, $y_position, $new_width, $new_height, '', '', '', false, 300);
            }
        }

        // School name
        $this->SetTextColor(0, 51, 102);
        $this->SetFont('Arial', 'B', 16);
        $this->SetXY(45, 15);
        $this->Cell(0, 8, strtoupper($this->schoolInfo['school_name']), 0, 1, 'L');

        // Contact information
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(51, 51, 51);
        $this->SetX(45);
        if (!empty($this->schoolInfo['email'])) {
            $this->Cell(0, 5, 'Email: ' . $this->schoolInfo['email'], 0, 1, 'L');
        }
        if (!empty($this->schoolInfo['location'])) {
            $this->SetX(45);
            $this->Cell(0, 5, ' ' . $this->schoolInfo['location'], 0, 1, 'L');
        }
        if (!empty($this->schoolInfo['phone'])) {
            $this->SetX(45);
            $this->Cell(0, 5, 'Tel: ' . $this->schoolInfo['phone'], 0, 1, 'L');
        }
        
        // Report title with term information
        $this->Ln(2);
        $this->SetFillColor(0, 51, 102);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell(0, 8, 'Class Grade Report - ' . $GLOBALS['exam_type'] . ' (' . $GLOBALS['category'] . ')', 0, 1, 'C', true);
            
        // Term information
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 10);
        $this->Cell(0, 6, $this->termInfo, 0, 1, 'C');
        
        $this->Ln(1);
        
        // Table headers
        $this->SetFillColor(200, 220, 255);
        $this->SetFont('Helvetica', 'B', 7);
        
        // First row: Subject numbers
        $this->Cell($this->studentColWidth, 6, '', 1, 0, 'L', true);
        for ($i = 1; $i <= count($this->subjects); $i++) {
            $this->Cell($this->subjectColWidth, 6, $i, 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Second row: Subject codes
        $this->Cell($this->studentColWidth, 6, 'Student Name', 1, 0, 'L', true);
        foreach ($this->subjects as $subject) {
            $this->Cell($this->subjectColWidth, 6, substr($subject, 0, 3), 1, 0, 'C', true);
        }
        $this->Ln();
    }

    function Footer() {
        // Add grading scale at the bottom
        $this->SetY(-20);  // Moved up since we removed subject key
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, 'Grading Scale:', 0, 1);
        
        // Display grading scale in a compact format
        $this->SetFont('Arial', '', 7);
        $x = 10;
        $y = $this->GetY();
        $col = 0;
        foreach ($GLOBALS['grades_scale'] as $grade) {
            if ($col >= 6) { // 6 grades per row
                $col = 0;
                $y += 4;
                $this->SetXY($x, $y);
            }
            $this->Cell(45, 4, $grade['grade'] . ' (' . $grade['min_score'] . '-' . $grade['max_score'] . '%)', 0, 0);
            $col++;
        }
        
        // Page number
        $this->SetY(-10);
        $this->SetFont('Helvetica', 'I', 7);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Confidential Grade Report', 0, 0, 'C');
    }
}

// Create PDF
$pdf = new PDF('L', 'mm', 'A4');
$pdf->setSchoolInfo($school_info);
$pdf->setTermInfo($report_data['term_info']);
$pdf->setSubjects($report_data['subjects']);
$pdf->AliasNbPages();
$pdf->AddPage();

// Add data
$pdf->SetFont('Arial', '', 8);
foreach ($report_data['report'] as $student => $data) {
    $pdf->Cell($pdf->studentColWidth, 7, $student, 1, 0, 'L');
    
    $total_percentage = 0;
    $subject_count = 0;
    
    // Subject grades
    foreach ($report_data['subjects'] as $subject) {
        $subject_data = $data['subjects'][$subject] ?? null;
        if ($subject_data && $subject_data['max_score'] > 0) {
            $grade_info = calculateGrade($subject_data['score'], $subject_data['max_score'], $grades_scale);
            $pdf->Cell($pdf->subjectColWidth, 7, $grade_info['grade'], 1, 0, 'C');
            $total_percentage += $subject_data['percentage'];
            $subject_count++;
        } else {
            $pdf->Cell($pdf->subjectColWidth, 7, '-', 1, 0, 'C');
        }
    }
    $pdf->Ln();
}

// Generate filename
$timestamp = date('Ymd_His');
$random = substr(md5(rand()), 0, 6);
$filename = sprintf("Overall_Grades_%s_%s_%s_%s_%s.pdf",
    preg_replace('/[^A-Za-z0-9]/', '', $report_data['class_name']),
    preg_replace('/[^A-Za-z0-9]/', '', $exam_type),
    preg_replace('/[^A-Za-z0-9]/', '', $category),
    $timestamp,
    $random
);

// Output PDF
ob_clean();
$pdf->Output('D', $filename);
exit();
?>