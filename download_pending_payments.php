 <?php
session_start();

// Check if user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    header("Location: index.php");
    exit();
}

require('fpdf.php');

class PDF extends FPDF {
    // Page header
    function Header() {
        global $school_name, $class_name, $term_name, $year;
        
        // School Logo
        if (file_exists('uploads/' . $_SESSION['school_badge']) && !empty($_SESSION['school_badge'])) {
            $this->Image('uploads/' . $_SESSION['school_badge'], 10, 10, 20);
        }
        
        // School name
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, strtoupper($school_name), 0, 1, 'C');
        
        // Report title
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'PENDING PAYMENTS REPORT', 0, 1, 'C');
        
        // Class and Term info
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, $class_name . ' - ' . $term_name . ' ' . $year, 0, 1, 'C');
        
        // Line break
        $this->Ln(2);
        
        // Table header - Increased column widths
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(192, 192, 192); // Gray background for header
        $this->Cell(15, 8, 'No.', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Adm No', 1, 0, 'C', true);
        $this->Cell(70, 8, 'Student Name', 1, 0, 'L', true);
        $this->Cell(25, 8, 'Section', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Expected', 1, 0, 'R', true);
        $this->Cell(40, 8, 'Paid', 1, 0, 'R', true);
        $this->Cell(40, 8, 'Balance', 1, 1, 'R', true);
    }

    // Page footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}     Generated on: ' . date('d-m-Y H:i'), 0, 0, 'C');
    }
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
$class_id = $_GET['class_id'] ?? 0;
$term_id = $_GET['term_id'] ?? 0;

// Get school details
$bursar_id = $_SESSION['user_id'];
$school_query = "SELECT schools.*, users.school_id 
                 FROM schools 
                 JOIN users ON schools.id = users.school_id 
                 WHERE users.user_id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $bursar_id);
$stmt->execute();
$result = $stmt->get_result();
$school_data = $result->fetch_assoc();
$school_name = $school_data['school_name'];
$_SESSION['school_badge'] = $school_data['badge'];
$stmt->close();

// Get class name
$class_query = "SELECT name FROM classes WHERE id = ?";
$stmt = $conn->prepare($class_query);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();
$class_data = $result->fetch_assoc();
$class_name = $class_data['name'];
$stmt->close();

// Get term details
$term_query = "SELECT name, year FROM terms WHERE id = ?";
$stmt = $conn->prepare($term_query);
$stmt->bind_param("i", $term_id);
$stmt->execute();
$result = $stmt->get_result();
$term_data = $result->fetch_assoc();
$term_name = $term_data['name'];
$year = $term_data['year'];
$stmt->close();

// Get students with pending payments with updated class progression logic
$students_query = "
    WITH StudentTermClass AS (
        SELECT 
            s.*,
            CASE 
                WHEN t.id >= s.current_term_id THEN s.class_id
                ELSE s.previous_class_id
            END as effective_class_id,
            CASE 
                WHEN t.id >= s.current_term_id THEN s.class_id
                WHEN t.id = 5 THEN 9  -- For Term 5, use class 9
                WHEN t.id = 7 THEN 9  -- For Term 7, use class 9
                WHEN t.id = 8 THEN 10 -- For Term 8, use class 10
                ELSE s.previous_class_id
            END as matching_class_id
        FROM students s
        CROSS JOIN (SELECT ? as id) t
        WHERE s.class_id = ?
    )
    SELECT 
        s.admission_number,
        s.firstname,
        s.lastname,
        s.section,
        COALESCE(SUM(fp.amount), 0) as amount_paid,
        (
            SELECT COALESCE(
                SUM(
                    CASE 
                        WHEN sfa.adjusted_amount IS NOT NULL THEN sfa.adjusted_amount
                        ELSE f.amount 
                    END
                ),
                0
            )
            FROM fees f
            LEFT JOIN student_fee_adjustments sfa ON sfa.student_id = s.id 
                AND sfa.term_id = f.term_id 
                AND sfa.fee_id = f.id
            WHERE f.class_id = s.matching_class_id
            AND f.term_id = ?
            AND (f.section = s.section OR f.section IS NULL)
            AND f.school_id = s.school_id
        ) as expected_amount
    FROM StudentTermClass s
    LEFT JOIN fee_payments fp ON s.id = fp.student_id AND fp.term_id = ?
    GROUP BY s.id, s.admission_number, s.firstname, s.lastname, s.section
    HAVING amount_paid < expected_amount AND expected_amount > 0
    ORDER BY s.section, (expected_amount - amount_paid) DESC, s.admission_number";

$stmt = $conn->prepare($students_query);
$stmt->bind_param("iiii", $term_id, $class_id, $term_id, $term_id);
$stmt->execute();
$result = $stmt->get_result();
$pending_students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Create PDF - use landscape for better width utilization
$pdf = new PDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10); // Reduced margins
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 9);

// Add student data
$counter = 1;
$total_expected = 0;
$total_paid = 0;
$total_balance = 0;

// Alternate row colors
$fill = false;

foreach ($pending_students as $student) {
    $balance = $student['expected_amount'] - $student['amount_paid'];
    
    $pdf->SetFillColor(248, 248, 248); // Light gray for alternate rows
    $pdf->Cell(15, 7.5, $counter, 1, 0, 'C', $fill);
    $pdf->Cell(30, 7.5, $student['admission_number'], 1, 0, 'C', $fill);
    $pdf->Cell(70, 7.5, $student['firstname'] . ' ' . $student['lastname'], 1, 0, 'L', $fill);
    $pdf->Cell(25, 7.5, ucfirst($student['section'] ?? 'N/A'), 1, 0, 'C', $fill);
    $pdf->Cell(40, 7.5, 'UGX ' . number_format($student['expected_amount']), 1, 0, 'R', $fill);
    $pdf->Cell(40, 7.5, 'UGX ' . number_format($student['amount_paid']), 1, 0, 'R', $fill);
    $pdf->Cell(40, 7.5, 'UGX ' . number_format($balance), 1, 1, 'R', $fill);
    
    $total_expected += $student['expected_amount'];
    $total_paid += $student['amount_paid'];
    $total_balance += $balance;
    $counter++;
    $fill = !$fill; // Toggle fill for next row
}

// Add summary with a box around it
$pdf->Ln(5);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetFillColor(180, 180, 180); // Darker gray for summary header
$pdf->Cell(0, 10, 'PAYMENT SUMMARY', 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 11);

// Count students by section
$boarding_students = array_filter($pending_students, function($s) { return $s['section'] === 'boarding'; });
$day_students = array_filter($pending_students, function($s) { return $s['section'] === 'day'; });

// Use more width for the summary section but with reduced heights
$pdf->SetFillColor(240, 240, 240); // Lighter background for summary rows

$pdf->Cell(150, 8, 'Boarding Students With Balance:', 1, 0, 'L', $fill);
$pdf->Cell(100, 8, count($boarding_students), 1, 1, 'R', $fill);
$fill = !$fill;

$pdf->Cell(150, 8, 'Day Students With Balance:', 1, 0, 'L', $fill);
$pdf->Cell(100, 8, count($day_students), 1, 1, 'R', $fill);
$fill = !$fill;

$pdf->Cell(150, 8, 'Total Expected Amount:', 1, 0, 'L', $fill);
$pdf->Cell(100, 8, 'UGX ' . number_format($total_expected), 1, 1, 'R', $fill);
$fill = !$fill;

$pdf->Cell(150, 8, 'Total Amount Paid:', 1, 0, 'L', $fill);
$pdf->Cell(100, 8, 'UGX ' . number_format($total_paid), 1, 1, 'R', $fill);

// Highlight the total outstanding balance with a different fill color
$pdf->SetFillColor(210, 210, 210); // Darker background for total
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(150, 10, 'TOTAL OUTSTANDING BALANCE:', 1, 0, 'L', true);
$pdf->Cell(100, 10, 'UGX ' . number_format($total_balance), 1, 1, 'R', true);

// Output PDF
$pdf->Output('Pending_Payments_' . $class_name . '_' . $term_name . '_' . $year . '.pdf', 'D');

$conn->close();
?>