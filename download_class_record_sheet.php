<?php
session_start();
require('fpdf.php');

// Check if user is logged in and is an admin
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
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$school_id = $_SESSION['school_id'];

if (!$class_id || !$subject_id) {
    die('Invalid class or subject ID');
}

// Get current term
$current_term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$term_row = $term_result->fetch_assoc();
$current_term_id = $term_row['id'];
$term_name = $term_row['name'];
$term_year = $term_row['year'];

// Get school details
$school_query = "SELECT school_name, motto, email, location, phone, badge FROM schools WHERE id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school_details = $school_result->fetch_assoc();

// Get class and subject details
$class_subject_query = "SELECT c.name as class_name, s.subject_name 
                       FROM classes c 
                       JOIN subjects s ON c.id = s.class_id 
                       WHERE c.id = ? AND s.subject_id = ? AND c.school_id = ?";
$stmt = $conn->prepare($class_subject_query);
$stmt->bind_param("iii", $class_id, $subject_id, $school_id);
$stmt->execute();
$class_subject_result = $stmt->get_result();
$class_subject_details = $class_subject_result->fetch_assoc();

// Get exam categories and types for this subject and term
$exam_categories_query = "SELECT DISTINCT e.category, e.exam_type, e.max_score 
                         FROM exams e
                         INNER JOIN exam_subjects es ON e.exam_id = es.exam_id
                         WHERE e.school_id = ? AND e.term_id = ? AND es.subject_id = ?
                         ORDER BY e.exam_type, e.category";
$stmt = $conn->prepare($exam_categories_query);
$stmt->bind_param("iii", $school_id, $current_term_id, $subject_id);
$stmt->execute();
$exam_categories_result = $stmt->get_result();

// Organize exam types by category
$exam_categories = [];
$all_categories = [];
while ($row = $exam_categories_result->fetch_assoc()) {
    $category = $row['category'];
    $exam_type = $row['exam_type'];
    $max_score = $row['max_score'];
    
    if (!isset($exam_categories[$category])) {
        $exam_categories[$category] = [];
        $all_categories[] = $category;
    }
    
    $exam_categories[$category][] = [
        'exam_type' => $exam_type,
        'max_score' => $max_score
    ];
}

// Get students for this class who are assigned to this subject (including removed students)
$students_query = "SELECT s.id, s.firstname, s.lastname, s.admission_number, ss.status as assignment_status
                  FROM students s
                  JOIN student_subjects ss ON s.id = ss.student_id
                  WHERE s.class_id = ? AND s.school_id = ? AND ss.subject_id = ?
                  ORDER BY s.lastname, s.firstname";
$stmt = $conn->prepare($students_query);
$stmt->bind_param("iii", $class_id, $school_id, $subject_id);
$stmt->execute();
$students_result = $stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);

// PDF Class Definition
class RecordSheetPDF extends FPDF {
    protected $school_details;
    protected $class_subject_details;
    protected $term_name;
    protected $term_year;
    protected $exam_categories;
    protected $all_categories;

    function __construct($school_details, $class_subject_details, $term_name, $term_year, $exam_categories, $all_categories) {
        parent::__construct();
        $this->school_details = $school_details;
        $this->class_subject_details = $class_subject_details;
        $this->term_name = $term_name;
        $this->term_year = $term_year;
        $this->exam_categories = $exam_categories;
        $this->all_categories = $all_categories;
    }

    function Header() {
        // Add badge if it exists
        $badge_width = 30;
        $badge_height = 30;
        if (!empty($this->school_details['badge']) && file_exists('uploads/' . basename($this->school_details['badge']))) {
            $this->Image('uploads/' . basename($this->school_details['badge']), 10, 8, $badge_width, $badge_height);
        }

        // School name and details
        $this->SetY(10);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 6, $this->school_details['school_name'], 0, 1, 'C');

        $this->SetFont('Arial', 'I', 9);
        $this->Cell(0, 5, 'Motto: ' . $this->school_details['motto'], 0, 1, 'C');

        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, $this->school_details['email'], 0, 1, 'C');
        $this->Cell(0, 5, $this->school_details['location'] . ' | Phone: ' . $this->school_details['phone'], 0, 1, 'C');

        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, 'CLASS RECORD SHEET', 0, 1, 'C');

        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, $this->class_subject_details['class_name'] . ' - ' . $this->class_subject_details['subject_name'], 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 6, $this->term_name . ' ' . $this->term_year, 0, 1, 'C');

        // Add bold line after school details
        $this->SetLineWidth(0.5);
        $this->Ln(3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        
        $this->Ln(5);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
    }

    function AddRecordTable($students) {
        $this->SetFillColor(220, 220, 220);
        $this->SetTextColor(0, 0, 0);

        // Calculate column widths
        $left_margin = 10;
        $right_margin = 10;
        $table_width = $this->GetPageWidth() - ($left_margin + $right_margin);
        $this->SetX($left_margin);

        // Fixed column widths
        $col_sno = 15;      // Serial number
        $col_name = 50;     // Student name
        $col_adm = 25;      // Admission number

        // Calculate remaining width for exam categories
        $used_width = $col_sno + $col_name + $col_adm;
        $remaining_width = $table_width - $used_width;
        
        // Count total exam columns needed
        $total_exam_columns = 0;
        foreach ($this->exam_categories as $category => $exam_types) {
            $total_exam_columns += count($exam_types);
        }
        
        $col_exam_width = $total_exam_columns > 0 ? max(15, floor($remaining_width / $total_exam_columns)) : 15;
        $col_exam_width = min($col_exam_width, 20); // Cap at 20

        $row_height = 8;

        // Headers
        $this->SetFont('Arial', 'B', 9);
        
        // Serial Number
        $this->Cell($col_sno, $row_height, 'S/No', 1, 0, 'C', true);
        
        // Student Name
        $this->Cell($col_name, $row_height, 'STUDENT NAME', 1, 0, 'C', true);
        
        // Admission Number
        $this->Cell($col_adm, $row_height, 'ADM NO.', 1, 0, 'C', true);
        
        // Exam Categories
        foreach ($this->all_categories as $category) {
            if (isset($this->exam_categories[$category])) {
                $exam_types = $this->exam_categories[$category];
                foreach ($exam_types as $exam) {
                    $this->Cell($col_exam_width, $row_height, $category, 1, 0, 'C', true);
                }
            }
        }
        
        $this->Ln();

        // Student rows
        $this->SetFont('Arial', '', 8);
        $serial_number = 1;
        
        foreach ($students as $student) {
            // Serial Number
            $this->Cell($col_sno, $row_height, $serial_number, 1, 0, 'C');
            
            // Student Name
            $full_name = $student['firstname'] . ' ' . $student['lastname'];
            $this->Cell($col_name, $row_height, $full_name, 1, 0, 'L');
            
            // Admission Number
            $this->Cell($col_adm, $row_height, $student['admission_number'], 1, 0, 'C');
            
            // Exam Categories - empty cells for manual entry
            foreach ($this->all_categories as $category) {
                if (isset($this->exam_categories[$category])) {
                    $exam_types = $this->exam_categories[$category];
                    foreach ($exam_types as $exam) {
                        $this->Cell($col_exam_width, $row_height, '', 1, 0, 'C');
                    }
                }
            }
            
            $this->Ln();
            
            $serial_number++;
        }

        $this->Ln(10);
        
        // Add instructions
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 6, 'INSTRUCTIONS FOR TEACHERS:', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 9);
        $instructions = [
            "1. Fill in the marks for each student in the appropriate category columns.",
            "2. Keep this record sheet for your reference and submit to the administration.",
            "3. Ensure all marks are recorded before the end of the term."
        ];
        
        foreach ($instructions as $instruction) {
            $this->Cell(0, 5, $instruction, 0, 1, 'L');
        }
    }
}

// Generate PDF
$pdf = new RecordSheetPDF($school_details, $class_subject_details, $term_name, $term_year, $exam_categories, $all_categories);
$pdf->AliasNbPages();
$pdf->AddPage();

$pdf->AddRecordTable($students);

// Output PDF
$filename = 'Record_Sheet_' . str_replace(' ', '_', $class_subject_details['class_name']) . '_' . 
           str_replace(' ', '_', $class_subject_details['subject_name']) . '_' . 
           $term_name . '_' . $term_year . '.pdf';

$pdf->Output('D', $filename);

$conn->close();
exit();
?>
