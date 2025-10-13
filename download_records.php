 <?php
session_start();
require_once 'fpdf.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
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

// Get parameters
$term_id = isset($_GET['term']) ? $_GET['term'] : null;
$class_id = isset($_GET['class']) ? $_GET['class'] : null;
$exam_type = isset($_GET['exam_type']) ? $_GET['exam_type'] : null;
$category = isset($_GET['category']) ? $_GET['category'] : null;
$school_id = $_SESSION['school_id'];

// Fetch school details
$school_query = "SELECT school_name, registration_number, location, motto, email, badge, phone 
                 FROM schools WHERE id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_details = $stmt->get_result()->fetch_assoc();

// Fetch class and term details
$details_query = "SELECT c.name as class_name, t.name as term_name, t.year 
                 FROM classes c 
                 JOIN terms t ON t.school_id = c.school_id 
                 WHERE c.id = ? AND t.id = ?";
$stmt = $conn->prepare($details_query);
$stmt->bind_param("ii", $class_id, $term_id);
$stmt->execute();
$details = $stmt->get_result()->fetch_assoc();

// Fetch results
$results_query = "
    SELECT 
        s.firstname,
        s.lastname,
        sj.subject_name,
        e.exam_type,
        er.score
    FROM students s
    LEFT JOIN exam_results er ON s.id = er.student_id
    LEFT JOIN exams e ON er.exam_id = e.exam_id
    LEFT JOIN subjects sj ON er.subject_id = sj.subject_id
    WHERE s.class_id = ? 
        AND er.term_id = ?
        AND e.exam_type = ?
        AND e.category = ?
        AND s.school_id = ?
    ORDER BY s.firstname, s.lastname, sj.subject_name";

$stmt = $conn->prepare($results_query);
$stmt->bind_param("iissi", $class_id, $term_id, $exam_type, $category, $school_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Add this helper function after the database connection
function formatScore($score, $exam_type) {
    if ($score === null || $score === '' || $score === '-') {
        return '-';
    }
    
    if (strtolower($exam_type) === 'exam') {
        return number_format($score, 0); // No decimal for exams
    } else {
        return number_format($score, 1); // One decimal for activities
    }
}

// Get all unique subjects
$subjects = array();
foreach ($results as $result) {
    if (!in_array($result['subject_name'], $subjects)) {
        $subjects[] = $result['subject_name'];
    }
}
sort($subjects);

// Calculate column widths more efficiently
$studentColumnWidth = 50; // Reduced width for student names
$subjectCount = count($subjects);
$subjectColumnWidth = 20; // Reduced width for subject columns

// Custom PDF class
class PDF extends FPDF {
    public $subjects = [];
    public $studentColumnWidth = 50;
    public $subjectColumnWidth = 20;
    public $exam_type = '';
    public $school_details = [];
    public $report_details = [];
    public $report_exam_type = '';
    public $report_category = '';
    
    function setReportData($school_details, $details, $exam_type, $category) {
        $this->school_details = $school_details;
        $this->report_details = $details;
        $this->report_exam_type = $exam_type;
        $this->report_category = $category;
    }
    
    function Header() {
        // Modern header background
        $this->SetFillColor(0, 32, 96); // Dark blue background
        $this->Rect(0, 0, $this->GetPageWidth(), 40, 'F'); // Slightly smaller header
        
        // School Badge with proper positioning
        if (!empty($this->school_details['badge'])) {
            $badge_path = 'uploads/' . $this->school_details['badge'];
            if (file_exists($badge_path)) {
                $this->Image($badge_path, 15, 5, 30); // Slightly smaller badge
            }
        }
        
        // School Details with improved typography
        $this->SetTextColor(255, 255, 255); // White text
        $this->SetFont('Arial', 'B', 20); // Smaller school name font
        $this->SetXY(50, 8);
        $this->Cell(0, 8, strtoupper($this->school_details['school_name']), 0, 1, 'L');
        
        // Contact details with better spacing
        $this->SetFont('Arial', '', 10); // Smaller contact info
        $this->SetTextColor(200, 200, 200); // Light gray text
        $this->SetXY(50, 18);
        $this->Cell(0, 5, $this->school_details['location'], 0, 1, 'L');
        $this->SetXY(50, 23);
        $this->Cell(0, 5, 'Tel: ' . $this->school_details['phone'] . ' | Email: ' . $this->school_details['email'], 0, 1, 'L');
        
        // Report Title Box
        $this->Ln(5); // Reduced space
        $this->SetFillColor(240, 240, 240); // Light gray background
        $this->Rect(10, 45, $this->GetPageWidth()-20, 15, 'F'); // Smaller title box
        
        // Report Details with enhanced styling
        $this->SetTextColor(0, 32, 96); // Dark blue text
        $this->SetFont('Arial', 'B', 14); // Smaller title
        $this->SetXY(10, 46);
        $this->Cell(0, 7, 'ACADEMIC RESULTS REPORT', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 10); // Smaller details
        $this->SetTextColor(70, 70, 70); // Dark gray text
        $this->Cell(0, 6, 'Class: ' . $this->report_details['class_name'] . ' | ' . 
                         'Term: ' . $this->report_details['term_name'] . ' ' . $this->report_details['year'] . ' | ' .
                         'Exam: ' . $this->report_exam_type . ' - ' . $this->report_category, 0, 1, 'C');
        
        // Add subject headers on each page
        $this->Ln(5);
        
        // Only draw the table header if subjects have been set
        if (!empty($this->subjects)) {
            $this->drawTableHeader();
        }
    }
    
    function drawTableHeader() {
        // Table Header with improved styling
        $this->SetFillColor(0, 32, 96); // Dark blue header
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 8); // Smaller font for headers
        $this->SetX(10);
        $this->Cell($this->studentColumnWidth, 6, ' Student Name', 1, 0, 'L', true);

        foreach ($this->subjects as $subject) {
            // Abbreviate long subject names even more
            $displaySubject = (strlen($subject) > 9) ? substr($subject, 0, 7) . '..' : $subject;
            $this->Cell($this->subjectColumnWidth, 6, $displaySubject, 1, 0, 'C', true);
        }
        $this->Ln();
    }
    
    function Footer() {
        // Footer design
        $this->SetY(-20); // Smaller footer margin
        
        // Footer line
        $this->SetDrawColor(0, 32, 96);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), $this->GetPageWidth()-10, $this->GetY());
        
        // Footer text
        $this->SetY(-15);
        $this->SetTextColor(70, 70, 70);
        $this->SetFont('Arial', 'I', 7); // Smaller footer font
        $this->Cell(0, 8, 'Page ' . $this->PageNo() . ' of {nb}     |     Generated: ' . date('d/m/Y H:i'), 0, 0, 'C');
    }
}

// Create PDF
$pdf = new PDF();
$pdf->setReportData($school_details, $details, $exam_type, $category);
$pdf->subjects = $subjects;
$pdf->studentColumnWidth = $studentColumnWidth;
$pdf->subjectColumnWidth = $subjectColumnWidth;
$pdf->exam_type = $exam_type;

$pdf->AliasNbPages();
$pdf->AddPage('L');
$pdf->SetAutoPageBreak(true, 20); // Smaller bottom margin

// Prepare student data
$student_data = [];
foreach ($results as $result) {
    $student_name = $result['firstname'] . ' ' . $result['lastname'];
    if (!isset($student_data[$student_name])) {
        $student_data[$student_name] = [];
    }
    $student_data[$student_name][$result['subject_name']] = $result['score'];
}

// Table Data with enhanced styling
$pdf->SetFont('Arial', '', 7); // Smaller font for data
$rowCount = 0;
$rowHeight = 6; // Reduced row height

foreach ($student_data as $student_name => $scores) {
    if ($rowCount % 2 == 0) {
        $pdf->SetFillColor(240, 245, 255); // Light blue for even rows
    } else {
        $pdf->SetFillColor(255, 255, 255); // White for odd rows
    }
    
    // Student name in dark blue
    $pdf->SetTextColor(0, 32, 96);
    $pdf->Cell($studentColumnWidth, $rowHeight, ' ' . $student_name, 1, 0, 'L', true);
    
    foreach ($subjects as $subject) {
        $score = isset($scores[$subject]) ? $scores[$subject] : '-';
        
        // Set text color to dark blue for all scores
        $pdf->SetTextColor(0, 32, 96);
        
        // Display score without any special background
        $pdf->Cell($subjectColumnWidth, $rowHeight, formatScore($score, $exam_type), 1, 0, 'C', true);
    }
    
    $pdf->Ln();
    $rowCount++;
}

// Generate unique filename
$filename = sprintf("Academic_Results_%s_%s_%s_%s.pdf",
    preg_replace('/[^A-Za-z0-9]/', '', $details['class_name']),
    preg_replace('/[^A-Za-z0-9]/', '', $exam_type),
    preg_replace('/[^A-Za-z0-9]/', '', $category),
    date('Ymd_His')
);

$pdf->Output('D', $filename);

$conn->close();
?