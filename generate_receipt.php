 <?php
require('fpdf.php');

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_GET['payment_id']) || !isset($_GET['student_id'])) {
    die('Payment ID and Student ID are required');
}

$payment_id = intval($_GET['payment_id']);
$student_id = intval($_GET['student_id']);

// Get payment details
$query = "SELECT p.*, f.fee_name, s.firstname, s.lastname, s.admission_number, 
          c.name as class_name, sch.school_name, sch.location as school_address,
          sch.phone as school_phone, sch.email as school_email, sch.badge as school_logo,
          sch.motto as school_motto, t.name as term_name, t.year as term_year,
          u.username as recorded_by, s.stream, u.firstname as bursar_firstname, u.lastname as bursar_lastname
   FROM fee_payments p
   LEFT JOIN fees f ON p.fee_id = f.id
   LEFT JOIN students s ON p.student_id = s.id
   LEFT JOIN classes c ON s.class_id = c.id
   LEFT JOIN schools sch ON p.school_id = sch.id
   LEFT JOIN terms t ON p.term_id = t.id
   LEFT JOIN users u ON p.created_by = u.user_id
   WHERE p.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();

if (!$payment) {
    die('Payment not found');
}

class ReceiptPDF extends FPDF {
    function __construct() {
        // Use A5 size (148 x 210 mm) - half of A4
        parent::__construct('P', 'mm', 'A5');
    }
    
    function Header() {
        global $payment;
        
        // Add badge if it exists - Reduced size from 35x35 to 30x30
        $badge_width = 30;
        $badge_height = 30;
        
        // Improved badge handling
        if (!empty($payment['school_logo'])) {
            $badge_path = 'uploads/' . basename($payment['school_logo']);
            if (file_exists($badge_path)) {
                // Get image dimensions to maintain aspect ratio
                list($width, $height) = getimagesize($badge_path);
                $ratio = $width / $height;
                
                // Adjust dimensions to maintain aspect ratio while fitting within bounds
                if ($ratio > 1) {
                    $badge_width = 30;
                    $badge_height = 30 / $ratio;
                } else {
                    $badge_height = 30;
                    $badge_width = 30 * $ratio;
                }
                
                // Position badge on the left, moved up from 10 to 5
                $this->Image($badge_path, 10, 5, $badge_width, $badge_height);
            }
        }
        
        // School details with modern typography - adjusted for A5 and aligned with badge
        $this->SetFont('Arial', 'B', 14); // Smaller font
        $this->SetY(5); // Align with badge, moved up from 10 to 5
        $this->Cell(0, 8, strtoupper($payment['school_name']), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 8); // Smaller font
        $this->Cell(0, 4, $payment['school_address'], 0, 1, 'C');
        $this->Cell(0, 4, 'Tel: ' . $payment['school_phone'], 0, 1, 'C');
        $this->Cell(0, 4, 'Email: ' . $payment['school_email'], 0, 1, 'C');
        
        if (!empty($payment['school_motto'])) {
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 4, 'Motto: ' . $payment['school_motto'], 0, 1, 'C');
        }
        
        $this->Ln(3);
        
        // Add line after school information
        $this->Line(10, $this->GetY(), 138, $this->GetY());
        $this->Ln(3);
        
        // Receipt title with modern styling
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'OFFICIAL PAYMENT RECEIPT', 0, 1, 'C');
        
        // Receipt number and date
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 6, 'Receipt No: ' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT), 0, 1, 'C');
        
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, 'Date: ' . date('d M Y', strtotime($payment['created_at'])), 0, 1, 'C');
        
        $this->Ln(3);
    }
    
    function Footer() {
        global $payment;
        
        // Modern footer design - adjusted for A5
        $this->SetY(-40);
        
        // Signature lines with modern spacing
        $this->SetFont('Arial', '', 8);
        $this->Cell(45, 4, '_____________________', 0, 0, 'C');
        $this->Cell(48, 4, '', 0, 0);
        $this->Cell(45, 4, '_____________________', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(45, 4, 'Bursar Signature', 0, 0, 'C');
        $this->Cell(48, 4, '', 0, 0);
        $this->Cell(45, 4, 'School Stamp', 0, 1, 'C');
        
        // Add school email in footer
        $this->SetFont('Arial', '', 7);
        $this->Cell(0, 4, 'Email: ' . $payment['school_email'], 0, 1, 'C');
    }
}

// Create PDF instance
$pdf = new ReceiptPDF();
$pdf->AddPage();

// Start of Student Information box
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, 'STUDENT INFORMATION', 0, 1, 'L');

// Draw box around student information
$startY = $pdf->GetY();
$pdf->SetFont('Arial', '', 8);
$pdf->Ln(1);
$pdf->Cell(40, 5, 'Student Name:', 0);
$pdf->Cell(98, 5, $payment['firstname'] . ' ' . $payment['lastname'], 0, 1);

$pdf->Cell(40, 5, 'Admission Number:', 0);
$pdf->Cell(98, 5, $payment['admission_number'], 0, 1);

$pdf->Cell(40, 5, 'Class:', 0);
$pdf->Cell(98, 5, $payment['class_name'], 0, 1);

$pdf->Cell(40, 5, 'Stream:', 0);
$pdf->Cell(98, 5, $payment['stream'], 0, 1);

$pdf->Cell(40, 5, 'Term:', 0);
$pdf->Cell(98, 5, $payment['term_name'] . ' ' . $payment['term_year'], 0, 1);

// Draw box around student section
$endY = $pdf->GetY();
$pdf->Rect(10, $startY, 128, $endY - $startY);

$pdf->Ln(3);

// Start of Payment Information box
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 6, 'PAYMENT DETAILS', 0, 1, 'L');

// Draw box around payment information
$startY = $pdf->GetY();
$pdf->SetFont('Arial', '', 8);
$pdf->Ln(1);
$pdf->Cell(40, 5, 'Payment Type:', 0);
$pdf->Cell(98, 5, $payment['fee_id'] == 0 ? 'School Fees' : $payment['fee_name'], 0, 1);

$pdf->Cell(40, 5, 'Amount Paid:', 0);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(98, 5, 'UGX ' . number_format($payment['amount'], 0), 0, 1);

$pdf->SetFont('Arial', '', 8);
$pdf->Cell(40, 5, 'Payment Method:', 0);
$pdf->Cell(98, 5, $payment['payment_method'], 0, 1);

if (!empty($payment['reference_number'])) {
    $pdf->Cell(40, 5, 'Reference Number:', 0);
    $pdf->Cell(98, 5, $payment['reference_number'], 0, 1);
}

$pdf->Cell(40, 5, 'Payment Time:', 0);
$pdf->Cell(98, 5, date('h:i A', strtotime($payment['created_at'])), 0, 1);

if (!empty($payment['notes'])) {
    $pdf->Ln(3);
    $pdf->Cell(40, 5, 'Notes:', 0);
    $pdf->MultiCell(98, 5, $payment['notes'], 0);
}

// Draw box around payment section
$endY = $pdf->GetY();
$pdf->Rect(10, $startY, 128, $endY - $startY);

// Add a notice about the receipt
$pdf->Ln(5);
$pdf->SetFont('Arial', 'I', 7);
$pdf->MultiCell(0, 3, 'This is an official receipt. Any alterations make it invalid. Keep this receipt for your records.', 0, 'C');

// Output PDF
$student_name = str_replace(' ', '_', $payment['firstname'] . '_' . $payment['lastname']);
$pdf->Output('Receipt_' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT) . '_' . $student_name . '.pdf', 'D');