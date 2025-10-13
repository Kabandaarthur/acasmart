 <?php
ob_clean(); // Clean any previous output
ob_start(); // Start output buffering
session_start();

// Check if user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    header("Location: index.php");
    exit();
}

require('fpdf.php');

class ExpensePDF extends FPDF {
    // Page header
    function Header() {
        global $school_name, $term_name, $term_year, $report_title;
        
        // School name
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(25); // Move to the right of logo
        $this->Cell(0, 10, strtoupper($school_name), 0, 1, 'C');
        
        // Report title
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(25);
        $this->Cell(0, 10, 'EXPENSE REPORT', 0, 1, 'C');
        
        // Term info
        $this->SetFont('Arial', '', 11);
        $this->Cell(25);
        $this->Cell(0, 6, $report_title, 0, 1, 'C');
        
        // Line below header
        $this->Ln(3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);
        
        // Column headers
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(25, 8, 'DATE', 1, 0, 'C');
        $this->Cell(45, 8, 'CATEGORY', 1, 0, 'C');
        $this->Cell(45, 8, 'RECIPIENT', 1, 0, 'C');
        $this->Cell(35, 8, 'AMOUNT', 1, 0, 'C');
        $this->Cell(30, 8, 'METHOD', 1, 1, 'C');
    }

    // Page footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Line(10, $this->GetY() - 2, 200, $this->GetY() - 2);
        $this->Cell(95, 10, date('d/m/Y H:i:s'), 0, 0, 'L');
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
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

// Get school info
$school_id = $_SESSION['school_id'];
$school_query = "SELECT school_name FROM schools WHERE id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school_data = $school_result->fetch_assoc();
$school_name = $school_data['school_name'];
$stmt->close();

// Get term info
$term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
$term_query = "SELECT t.name, t.year 
               FROM terms t
               WHERE t.id = ? AND t.school_id = ?";
$stmt = $conn->prepare($term_query);
$stmt->bind_param("ii", $term_id, $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$term_data = $term_result->fetch_assoc();
$term_name = $term_data['name'];
$term_year = $term_data['year'];
$stmt->close();

// Check if filtering by month
$month_year = isset($_GET['month_year']) ? $_GET['month_year'] : null;
$month_name = '';
$report_title = $term_name . ' ' . $term_year;
$filename_prefix = 'Expenses_Report_' . $term_name . '_' . $term_year;

if ($month_year) {
    // Extract year and month from month_year parameter
    list($year, $month) = explode('-', $month_year);
    $month_name = date('F', mktime(0, 0, 0, intval($month), 1, intval($year)));
    $report_title = $month_name . ' ' . $year . ' (' . $term_name . ' ' . $term_year . ')';
    $filename_prefix = 'Expenses_' . $month_name . '_' . $year;
}

// Build the expenses query
$expenses_query = "SELECT 
    e.expense_date,
    e.category,
    e.description,
    e.amount,
    e.payment_method,
    e.recipient_name
FROM expenses e
JOIN terms t ON e.term_id = t.id
WHERE e.term_id = ? AND t.school_id = ?";

$params = [$term_id, $school_id];
$param_types = "ii";

// Add month filter if provided
if ($month_year) {
    $expenses_query .= " AND YEAR(e.expense_date) = ? AND MONTH(e.expense_date) = ?";
    $params[] = $year;
    $params[] = $month;
    $param_types .= "ii";
}

// Add order by
$expenses_query .= " ORDER BY e.expense_date ASC";

// Prepare and execute query
$stmt = $conn->prepare($expenses_query);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$expenses_result = $stmt->get_result();

// Create PDF
$pdf = new ExpensePDF();
$pdf->AliasNbPages();
$pdf->AddPage('P', 'A4');
$pdf->SetFont('Arial', '', 9);
$pdf->SetAutoPageBreak(true, 15);

// Initialize total
$total_expenses = 0;

// Add alternating row styles
$alternate = false;
while ($expense = $expenses_result->fetch_assoc()) {
    // Format date
    $date = date('d/m/Y', strtotime($expense['expense_date']));
    
    // Format payment method
    $payment_method = ucwords(str_replace('_', ' ', $expense['payment_method']));
    
    // Format amount
    $amount = number_format($expense['amount'], 0, '.', ',');
    $total_expenses += $expense['amount'];

    // Set alternate row style
    if ($alternate) {
        $pdf->SetFont('Arial', '', 9);
    } else {
        $pdf->SetFont('Arial', '', 9);
    }
    
    // Row height
    $rowHeight = 6;
    
    // Add row data
    $pdf->Cell(25, $rowHeight, $date, 1, 0, 'C');
    $pdf->Cell(45, $rowHeight, $expense['category'], 1, 0, 'L');
    $pdf->Cell(45, $rowHeight, $expense['recipient_name'], 1, 0, 'L');
    $pdf->Cell(35, $rowHeight, 'UGX ' . $amount, 1, 0, 'R');
    $pdf->Cell(30, $rowHeight, $payment_method, 1, 1, 'C');
    
    $alternate = !$alternate;
}

// Add total line
$pdf->Ln(2);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(115, 8, 'TOTAL EXPENSES:', 0, 0, 'R');
$pdf->Cell(35, 8, 'UGX ' . number_format($total_expenses, 0, '.', ','), 0, 1, 'R');
$pdf->Line(115, $pdf->GetY() - 1, 195, $pdf->GetY() - 1);
$pdf->Ln(5);

// Add summary section
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'Expense Summary by Category', 0, 1, 'L');

// Get category summary - modified query to use terms join and include month filter if provided
$category_query = "SELECT 
    e.category,
    COUNT(*) as transaction_count,
    SUM(e.amount) as total_amount
FROM expenses e
JOIN terms t ON e.term_id = t.id
WHERE e.term_id = ? AND t.school_id = ?";

$cat_params = [$term_id, $school_id];
$cat_param_types = "ii";

// Add month filter if provided
if ($month_year) {
    $category_query .= " AND YEAR(e.expense_date) = ? AND MONTH(e.expense_date) = ?";
    $cat_params[] = $year;
    $cat_params[] = $month;
    $cat_param_types .= "ii";
}

$category_query .= " GROUP BY e.category ORDER BY total_amount DESC";

$stmt = $conn->prepare($category_query);
$stmt->bind_param($cat_param_types, ...$cat_params);
$stmt->execute();
$category_result = $stmt->get_result();

// Category summary table headers
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(80, 7, 'Category', 1, 0, 'C');
$pdf->Cell(30, 7, 'Transactions', 1, 0, 'C');
$pdf->Cell(40, 7, 'Total Amount', 1, 1, 'C');

// Category summary data
$pdf->SetFont('Arial', '', 9);
$alternate = false;
while ($category = $category_result->fetch_assoc()) {
    $pdf->Cell(80, 6, $category['category'], 1, 0, 'L');
    $pdf->Cell(30, 6, $category['transaction_count'], 1, 0, 'C');
    $pdf->Cell(40, 6, 'UGX ' . number_format($category['total_amount'], 0, '.', ','), 1, 1, 'R');
    $alternate = !$alternate;
}

ob_end_clean(); // Clean output buffer before sending PDF

// Output the PDF
$pdf->Output($filename_prefix . '.pdf', 'D');

$conn->close();
?