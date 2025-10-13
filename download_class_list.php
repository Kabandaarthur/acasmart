 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Assuming the user's school_id is stored in the session
$user_school_id = $_SESSION['school_id'];

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch school information including the year from the terms table
$school_query = $conn->prepare("
    SELECT s.school_name , s.motto, t.year, s.badge, s.email, t.name
    FROM schools s 
    JOIN terms t ON t.school_id = s.id 
    WHERE s.id = ? AND t.is_current = 1
");
$school_query->bind_param("i", $user_school_id);
$school_query->execute();
$school_result = $school_query->get_result();
$school = $school_result->fetch_assoc();

// Fetch students for the selected class
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;

if ($selected_class_id) {
    $student_query = $conn->prepare("
        SELECT admission_number, firstname, lastname, gender, stream, classes.name AS class_name 
        FROM students 
        INNER JOIN classes ON students.class_id = classes.id 
        WHERE students.class_id = ? AND students.school_id = ?
        ORDER BY admission_number ASC
    ");
    $student_query->bind_param("ii", $selected_class_id, $user_school_id);
    $student_query->execute();
    $students = $student_query->get_result();

    // Get class name for the file name
    $class_info = $students->fetch_assoc();
    $class_name = $class_info ? $class_info['class_name'] : 'Unknown';
    $students->data_seek(0); // Reset pointer to beginning

    // Include FPDF library
    require_once 'fpdf.php';

   class PDF extends FPDF {
    private $class_name;
    private $startY;
    private $headerHeight;

    function setClassName($name) {
        $this->class_name = $name;
    }

    // Initialize Poppins font family
    function initFontFamily() {
        // Add Poppins font family with proper character width definitions
        // Font files are now in the root directory for FPDF compatibility
        $this->AddFont('Poppins', '', 'Poppins-Regular.php');
        $this->AddFont('Poppins', 'B', 'Poppins-Bold.php');
        $this->AddFont('Poppins', 'I', 'Poppins-Italic.php');
        $this->AddFont('Poppins', 'BI', 'Poppins-BoldItalic.php');
    }

    // Add new function for table headers
    function TableHeaders() {
        $this->SetDrawColor(0);  // Black color for borders
        $this->SetLineWidth(0.2);
        $this->SetFont('Poppins', 'B', 10);
        $this->SetTextColor(0);  // Black text
        $this->SetFillColor(240, 240, 240);  // Light gray background for headers

        // Headers with adjusted widths
        $this->Cell(12, 8, 'No.', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Adm No.', 1, 0, 'C', true);
        $this->Cell(50, 8, 'Firstname', 1, 0, 'C', true);
        $this->Cell(50, 8, 'Lastname', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Sex', 1, 0, 'C', true);
        $this->Cell(20, 8, 'Stream', 1, 1, 'C', true);
    }

    function Header() {
        global $school;
        
        // Store initial Y position
        $initialY = $this->GetY();
        
        // Remove colored backgrounds and use simple borders instead
        $this->SetDrawColor(0);  // Black color for borders
        
        // Add school badge
        $badge_path = 'uploads/' . $school['badge'];
        if (file_exists($badge_path)) {
            $this->Image($badge_path, 10, 10, 25, 25, '', '', '', true, 300, '', false, false, 1);
        }
        
        // School name with reduced font size
        $this->SetTextColor(0);  // Black text
        $this->SetFont('Poppins', 'B', 16);
        $this->SetXY(40, 12);
        $this->Cell(0, 8, strtoupper($school['school_name']), 0, 1, 'L');

        // Compact additional details
        $this->SetFont('Poppins', 'I', 10);
        $this->SetTextColor(0);  // Black
        $this->SetX(40);
        $this->Cell(0, 5, 'Motto: "' . $school['motto'] . '"', 0, 1, 'L');
        
        $this->SetFont('Poppins', '', 8);
        $this->SetX(40);
        $this->Cell(100, 4, 'Email: ' . $school['email'], 0, 0, 'L');
        $this->Cell(50, 4, 'Year: ' . $school['year'] . ' | Term: ' . $school['name'], 0, 1, 'R');

        // Add class name under the header
        $this->Ln(5);
        $this->SetFont('Poppins', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 8, 'Class: ' . $this->class_name, 0, 1, 'L');
        
        // Simple line at bottom of header
        $this->Line(10, $this->GetY(), $this->w - 10, $this->GetY());

        // Add table headers
        $this->Ln(2);
        $this->TableHeaders();
        
        // Store the header height for future reference
        $this->headerHeight = $this->GetY() - $initialY;
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Poppins', 'I', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | ' . $this->class_name . ' Class List ', 0, 0, 'C');
    }
}

    $pdf = new PDF('P', 'mm', 'A4');
    $pdf->initFontFamily(); // Initialize Poppins fonts
    $pdf->setClassName($class_name);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    
    // Add student data rows with reduced row height
    $pdf->SetFont('Poppins', '', 9);
    $count = 1;
    $rowHeight = 8; // Height of each row
    $maxY = 270; // Maximum Y position before starting new page

    while ($student = $students->fetch_assoc()) {
        // Check if we need a new page
        if ($pdf->GetY() + $rowHeight > $maxY) {
            $pdf->AddPage();
        }

        $pdf->SetTextColor(0, 0, 0);

        // Data cells with matching widths and increased height
        $pdf->Cell(12, $rowHeight, $count++, 1, 0, 'C');
        $pdf->Cell(25, $rowHeight, $student['admission_number'], 1, 0, 'C');
        $pdf->Cell(50, $rowHeight, $student['firstname'], 1, 0, 'L');
        $pdf->Cell(50, $rowHeight, $student['lastname'], 1, 0, 'L');
        $pdf->Cell(30, $rowHeight, $student['gender'], 1, 0, 'C');
        $pdf->Cell(20, $rowHeight, $student['stream'], 1, 1, 'L');
    }

    // Generate filename with class name, date, time, and term
    $current_date = date('Y-m-d');
    $current_time = date('H-i-s');
    $term = str_replace(' ', '_', $school['name']);
    $filename = strtolower(str_replace(' ', '_', $class_name)) . '_class_list_' . $term . '_' . $current_date . '_' . $current_time . '.pdf';
    $pdf->Output('D', $filename);
} else {
    echo "No class selected.";
}

// Close connections
$school_query->close();
$student_query->close();
$conn->close();
