 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$class_id = $_GET['class_id'] ?? null;
$format = $_GET['format'] ?? 'csv';
$exam_type = $_GET['exam_type'] ?? '';
$category = $_GET['category'] ?? '';

if (!$class_id || !$exam_type) {
    die("Missing required parameters");
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

$school_id = $_SESSION['school_id'];

// Updated function to get current term based on new table structure
function getCurrentTerm($conn, $school_id) {
    $query = "SELECT id, name, start_date, end_date, year 
              FROM terms 
              WHERE school_id = ? 
              AND is_current = 1 
              LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get school information function
function getSchoolInfo($conn, $school_id) {
    $query = "SELECT school_name, email, phone, badge, location FROM schools WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Updated function to get class report with term filter and category
function getClassReport($conn, $class_id, $school_id, $exam_type, $category) {
    // First get current term
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

    $report_query = "SELECT s.firstname, s.lastname, sub.subject_name, er.score
                     FROM students s
                     JOIN exam_results er ON s.id = er.student_id
                     JOIN exams e ON er.exam_id = e.exam_id
                     JOIN subjects sub ON er.subject_id = sub.subject_id
                     WHERE s.class_id = ? 
                     AND s.school_id = ? 
                     AND e.exam_type = ?
                     AND e.category = ?
                     AND e.term_id = ?
                     ORDER BY s.lastname, s.firstname, sub.subject_name";
    
    $stmt = $conn->prepare($report_query);
    $stmt->bind_param("iissi", $class_id, $school_id, $exam_type, $category, $current_term['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $report = [];
    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $student_name = $row['firstname'] . '  ' . $row['lastname'];
        if (!isset($report[$student_name])) {
            $report[$student_name] = [];
        }
        $report[$student_name][$row['subject_name']] = $row['score'];
        if (!in_array($row['subject_name'], $subjects)) {
            $subjects[] = $row['subject_name'];
        }
    }
    
    // Format term information
    $term_info = $current_term['name'] . ' - ' . $current_term['year'];
    
    return [
        'report' => $report, 
        'subjects' => $subjects,
        'term_info' => $term_info,
        'class_name' => $class_name
    ];
}

$report_data = getClassReport($conn, $class_id, $school_id, $exam_type, $category);

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="class_report.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add term information to CSV
    fputcsv($output, ['Term:', $report_data['term_info']]);
    fputcsv($output, []); // Empty line for spacing
    
    $headers = array_merge(['Student'], $report_data['subjects']);
    fputcsv($output, $headers);
    
    foreach ($report_data['report'] as $student => $scores) {
        $row = [$student];
        foreach ($report_data['subjects'] as $subject) {
            $score = $scores[$subject] ?? '-';
            // Format score based on exam type
            if ($score !== '-') {
                if ($exam_type === 'activity') {
                    $score = number_format($score, 1); // One decimal point for activity
                } else {
                    $score = number_format($score, 0); // No decimal points for exam
                }
            }
            $row[] = $score;
        }
        fputcsv($output, $row);
    }
    
    fclose($output);
} 
elseif ($format === 'pdf') {
    // Get school information
    $school_info = getSchoolInfo($conn, $school_id);
    
    require('fpdf.php');

class PDF extends FPDF {
    // Change all properties to public
    public $schoolInfo;
    public $termInfo;
    public $termDates;
    public $subjects = [];
    public $studentColWidth = 40;
    public $subjectColWidth = 0;
    
    function setSchoolInfo($info) {
        $this->schoolInfo = $info;
    }

    function setTermInfo($termInfo) {
        $this->termInfo = $termInfo;
    }
    
    function setSubjects($subjects) {
        $this->subjects = $subjects;
        $pageWidth = $this->GetPageWidth() - 20;
        $this->subjectColWidth = ($pageWidth - $this->studentColWidth) / count($subjects);
    }

    function Header() {
        if (!$this->schoolInfo) {
            return;
        }
        
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
        $this->Cell(0, 8, 'Class Report - ' . $GLOBALS['exam_type'] . ' (' . $GLOBALS['category'] . ')', 0, 1, 'C', true);
            
        // Term information
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 10);
        $this->Cell(0, 6, $this->termInfo, 0, 1, 'C');
        
        $this->Ln(1);
        
        // Table headers on each page
        $this->SetFillColor(200, 220, 255);
        $this->SetFont('Helvetica', 'B', 7);
        $this->Cell($this->studentColWidth, 6, 'Student Name', 1, 0, 'L', true);
        
        foreach ($this->subjects as $subject) {
            // Truncate subject name to 3 characters
            $shortSubject = substr($subject, 0, 3);
            $this->Cell($this->subjectColWidth, 6, $shortSubject, 1, 0, 'C', true);
        }
        $this->Ln();
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 7);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Confidential Class Report', 0, 0, 'C');
    }
}

    // When processing the data, you might want to create a mapping of short names
    $subject_mapping = [];
    foreach ($report_data['subjects'] as $subject) {
        $subject_mapping[$subject] = substr($subject, 0, 3);
    }

    // Then when setting up the PDF:
    $pdf = new PDF('L', 'mm', 'A4');
    $pdf->setSchoolInfo($school_info);
    $pdf->setTermInfo($report_data['term_info']);

    // If you want to keep the original subject names in the data but show short versions in headers:
    $pdf->setSubjects($report_data['subjects']); // Original subjects for data processing

    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Increase row height and font size
    $rowHeight = 6; // Increased from 5 to 8
    
    // Add data with larger font
    $pdf->SetFont('Helvetica', '', 9); // Increased from 8 to 10
    foreach ($report_data['report'] as $student => $scores) {
        $pdf->Cell($pdf->studentColWidth, $rowHeight, $student, 1, 0, 'L');
        foreach ($report_data['subjects'] as $subject) {
            $score = $scores[$subject] ?? '-';
            // Format score based on exam type
            if ($score !== '-') {
                if ($GLOBALS['exam_type'] === 'activity') {
                    $score = number_format($score, 1); // One decimal point for activity
                } else {
                    $score = number_format($score, 0); // No decimal points for exam
                }
            }
            $pdf->Cell($pdf->subjectColWidth, $rowHeight, $score, 1, 0, 'C');
        }
        $pdf->Ln();
    }

    // Generate unique filename with timestamp and random string
    $timestamp = date('Ymd_His');
    $random = substr(md5(rand()), 0, 6);
    $filename = sprintf("Class_Report_%s_%s_%s_%s_%s.pdf",
        preg_replace('/[^A-Za-z0-9]/', '', $report_data['class_name']),
        preg_replace('/[^A-Za-z0-9]/', '', $exam_type),
        preg_replace('/[^A-Za-z0-9]/', '', $category),
        $timestamp,
        $random
    );

    // Clear any output before sending PDF
    ob_clean();
    
    $pdf->Output('D', $filename);
    exit(); // Add this to prevent any further output
} else {
    die("Invalid format specified");
}

$conn->close();
ob_end_flush(); // End output bufferin