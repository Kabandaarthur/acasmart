 <?php
session_start();
require_once 'fpdf.php'; // For PDF generation using TCPDF

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
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
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$school_id = $_SESSION['school_id'];

// Fetch exam details
$exam_query = "SELECT exam_type, category, max_score FROM exams WHERE exam_id = ? AND school_id = ?";
$stmt = $conn->prepare($exam_query);
$stmt->bind_param("ii", $exam_id, $school_id);
$stmt->execute();
$exam_result = $stmt->get_result();
$exam_details = $exam_result->fetch_assoc();

// Fetch class and subject names
$info_query = "SELECT c.name as class_name, s.subject_name 
               FROM classes c 
               JOIN subjects s ON s.school_id = c.school_id 
               WHERE c.id = ? AND s.subject_id = ? AND c.school_id = ?";
$stmt = $conn->prepare($info_query);
$stmt->bind_param("iii", $class_id, $subject_id, $school_id);
$stmt->execute();
$info_result = $stmt->get_result();
$info = $info_result->fetch_assoc();

// Fetch results
$query = "SELECT 
            s.firstname, 
            s.lastname,
            er.score
          FROM students s
          LEFT JOIN exam_results er ON s.id = er.student_id 
          WHERE s.class_id = ? 
          AND er.exam_id = ? 
          AND er.subject_id = ?
          ORDER BY s.firstname, s.lastname";

$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $class_id, $exam_id, $subject_id);
$stmt->execute();
$results = $stmt->get_result();

// Fetch school details
$school_query = "SELECT school_name, registration_number, location, motto, email, badge, phone 
                 FROM schools WHERE id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_details = $stmt->get_result()->fetch_assoc();

// Generate unique filename with timestamp and random string
function generateUniqueFilename($class_name, $subject_name, $exam_type) {
    $timestamp = date('Ymd_His'); // Format: YYYYMMDD_HHMMSS
    $random = substr(md5(uniqid()), 0, 6); // 6 character random string
    
    return sprintf("%s_%s_%s_%s_%s", 
        preg_replace('/[^A-Za-z0-9]/', '', $class_name),
        preg_replace('/[^A-Za-z0-9]/', '', $subject_name),
        preg_replace('/[^A-Za-z0-9]/', '', $exam_type),
        $timestamp,
        $random
    );
}

// Generate unique filename
$filename = generateUniqueFilename(
    $info['class_name'],
    $info['subject_name'],
    $exam_details['exam_type']
);

if ($format === 'csv') {
    // CSV Generation
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, [
        'Class: ' . $info['class_name'],
        'Subject: ' . $info['subject_name'],
        'Exam: ' . $exam_details['exam_type'] . ' - ' . $exam_details['category']
    ]);
    fputcsv($output, []); // Empty line
    fputcsv($output, ['Student Name', 'Score (Max: ' . $exam_details['max_score'] . ')', 'Grade']);
    
    // Write data
    while ($row = $results->fetch_assoc()) {
        $grade = calculateGrade($row['score']);
        fputcsv($output, [
            $row['firstname'] . ' ' . $row['lastname'],
            $row['score'],
            $grade
        ]);
    }
    
    fclose($output);
} else {
    // PDF Generation using FPDF
    class PDF extends FPDF {
        private $headerPrinted = false; // Flag to track if header has been printed

        function Header() {
            if ($this->headerPrinted) {
                return; // Skip header if it has already been printed
            }

            // Background color for header section - Light gray
            $this->SetFillColor(240, 242, 245); // Light gray background
            $this->Rect(0, 0, 210, 40, 'F');
            
            // Dark text for header
            $this->SetTextColor(51, 51, 51); // Dark gray text
            
            // Add badge if it exists
            if (!empty($GLOBALS['school_details']['badge'])) {
                $badge_path = 'uploads/' . $GLOBALS['school_details']['badge'];
                if (file_exists($badge_path)) {
                    $this->Image($badge_path, 10, 5, 30); // Adjust position and size as needed
                }
            }

            $this->headerPrinted = true; // Set flag to true after printing header
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(128, 128, 128);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
            $this->Cell(0, 10, 'Report generated on: ' . date('d/m/Y'), 0, 0, 'R');
        }
    }

    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(10, 10, 10);

    // School Header with adjusted positioning to accommodate badge
    if (!empty($school_details['badge'])) {
        // If badge exists, start text content further right
        $pdf->SetX(45);
    }

    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(160, 8, strtoupper($school_details['school_name']), 0, 1, 'C');

    $pdf->SetFont('Arial', '', 9);
    if (!empty($school_details['badge'])) {
        $pdf->SetX(45);
    }
    $pdf->Cell(160, 4, 'Registration No: ' . $school_details['registration_number'], 0, 1, 'C');

    if (!empty($school_details['badge'])) {
        $pdf->SetX(45);
    }
    $pdf->Cell(160, 4, $school_details['location'], 0, 1, 'C');

    if (!empty($school_details['badge'])) {
        $pdf->SetX(45);
    }
    $pdf->Cell(160, 4, 'Tel: ' . $school_details['phone'] . ' | Email: ' . $school_details['email'], 0, 1, 'C');

    if (!empty($school_details['badge'])) {
        $pdf->SetX(45);
    }
    $pdf->Cell(160, 4, 'Motto: ' . $school_details['motto'], 0, 1, 'C');

    // Decorative line - darker gray
    $pdf->SetDrawColor(200, 200, 200); // Gray line
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY() + 5, 200, $pdf->GetY() + 5);
    $pdf->Ln(10);

    // Report Title with background
    $pdf->SetFillColor(41, 128, 185); // Blue background
    $pdf->SetTextColor(255, 255, 255); // White text
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(190, 8, 'ACADEMIC RESULTS', 0, 1, 'C', true);
    $pdf->Ln(5);

    // Class and Exam Info in a nice box
    $pdf->SetTextColor(0, 0, 0); // Black text
    $pdf->SetFillColor(240, 240, 240); // Light gray background
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(190, 7, 'Examination Details', 1, 1, 'C', true);
    
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(95, 6, 'Class: ' . $info['class_name'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'Subject: ' . $info['subject_name'], 1, 1, 'L');
    $pdf->Cell(95, 6, 'Exam: ' . $exam_details['exam_type'] . ' - ' . $exam_details['category'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'Maximum Score: ' . $exam_details['max_score'], 1, 1, 'L');
    $pdf->Ln(5);

    // Table Header
    $pdf->SetFillColor(52, 152, 219); // Bright blue for header
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetDrawColor(41, 128, 185); // Border color

    // Column headers
    $pdf->Cell(15, 7, 'No.', 1, 0, 'C', true);
    $pdf->Cell(115, 7, 'Student Name', 1, 0, 'L', true);
    $pdf->Cell(60, 7, 'Score', 1, 1, 'C', true);

    // Table Data
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $rowCount = 0;
    $results->data_seek(0);

    while ($row = $results->fetch_assoc()) {
        // Alternate row colors
        $fill = $rowCount % 2 === 0;
        $pdf->SetFillColor(245, 247, 250); // Light blue-gray for alternate rows
        
        // Format score to 1 decimal place
        $score = is_numeric($row['score']) ? number_format($row['score'], 1) : $row['score'];
        
        $pdf->Cell(15, 6, $rowCount + 1, 1, 0, 'C', $fill);
        $pdf->Cell(115, 6, $row['firstname'] . ' ' . $row['lastname'], 1, 0, 'L', $fill);
        $pdf->Cell(60, 6, $score, 1, 1, 'C', $fill);
        
        $rowCount++;

        // Add new page if needed
        if($pdf->GetY() > 250) {
            $pdf->AddPage();
            
            // Repeat the header on new page
            $pdf->SetFillColor(52, 152, 219);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(15, 7, 'No.', 1, 0, 'C', true);
            $pdf->Cell(115, 7, 'Student Name', 1, 0, 'L', true);
            $pdf->Cell(60, 7, 'Score', 1, 1, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 9);
        }
    }

    // Summary box at the bottom
    $pdf->Ln(5);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(190, 6, 'Total Students: ' . $rowCount, 1, 1, 'L', true);

    // Output PDF
    $pdf->Output('D', $filename . '.pdf');
}

$conn->close();
?>