 <?php
// Database connection
// Database connection
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_37541636';
$db_pass = '2C3z0YzNwzMxS8N';
$db_name = 'if0_37541636_sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Validate required fields
    $required_fields = ['category_id', 'amount', 'expense_date', 'description', 'payment_method', 'recipient_name'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    // Sanitize and validate input
    $category_id = filter_var($_POST['category_id'], FILTER_VALIDATE_INT);
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $expense_date = $_POST['expense_date'];
    $description = trim($_POST['description']);
    $payment_method = trim($_POST['payment_method']);
    $reference_number = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
    $recipient_name = trim($_POST['recipient_name']);
    
    // Get current term and academic year
    $term_query = "SELECT id, academic_year FROM terms WHERE start_date <= ? AND end_date >= ? LIMIT 1";
    $term_stmt = $conn->prepare($term_query);
    $term_stmt->bind_param('ss', $expense_date, $expense_date);
    $term_stmt->execute();
    $result = $term_stmt->get_result();
    $term_data = $result->fetch_assoc();
    
    if (!$term_data) {
        throw new Exception("No active term found for the selected date");
    }

    // Begin transaction
    $conn->begin_transaction();

    // Insert expense record
    $query = "INSERT INTO expenses (category_id, amount, description, expense_date, term_id, academic_year, 
              payment_method, reference_number, recipient_name, created_by) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('idssississi', 
        $category_id,
        $amount,
        $description,
        $expense_date,
        $term_data['id'],
        $term_data['academic_year'],
        $payment_method,
        $reference_number,
        $recipient_name,
        $_SESSION['user_id']
    );
    $stmt->execute();

    // If this is a salary payment, create salary payment record
    if ($category_id == 1) { // Assuming category_id 1 is for salaries
        $expense_id = $conn->insert_id;
        
        // Extract month and year from expense date
        $date = new DateTime($expense_date);
        $month = $date->format('F');
        $year = $date->format('Y');
        
        $salary_query = "INSERT INTO salary_payments (expense_id, staff_id, month, year, 
                        basic_salary, net_salary, payment_date, payment_status) 
                        VALUES (?, NULL, ?, ?, ?, ?, ?, 'paid')";
        
        $salary_stmt = $conn->prepare($salary_query);
        $salary_stmt->bind_param('issdds',
            $expense_id,
            $month,
            $year,
            $amount,
            $amount,
            $expense_date
        );
        $salary_stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Expense recorded successfully']);

} catch (Exception $e) {
    if ($conn->connect_error === false) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>