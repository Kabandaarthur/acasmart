 <?php
// Turn off error display but keep error logging
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to catch any unwanted output
ob_start();

session_start();
header('Content-Type: application/json');

// Log incoming data
error_log("POST data: " . print_r($_POST, true));

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_37541636';
$db_pass = '2C3z0YzNwzMxS8N';
$db_name = 'if0_37541636_sms';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Validate and sanitize input
    $category = isset($_POST['category']) ? htmlspecialchars($_POST['category']) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $expense_date = isset($_POST['expense_date']) ? htmlspecialchars($_POST['expense_date']) : '';
    $description = isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '';
    $payment_method = isset($_POST['payment_method']) ? htmlspecialchars($_POST['payment_method']) : '';
    $recipient_name = isset($_POST['recipient_name']) ? htmlspecialchars($_POST['recipient_name']) : '';
    $term_id = isset($_POST['term_id']) ? (int)$_POST['term_id'] : null;

    // Log sanitized data
    error_log("Sanitized data: " . print_r([
        'category' => $category,
        'amount' => $amount,
        'expense_date' => $expense_date,
        'description' => $description,
        'payment_method' => $payment_method,
        'recipient_name' => $recipient_name
    ], true));

    // Validate required fields
    if (!$category || !$amount || !$expense_date || !$description || !$payment_method || !$recipient_name || !$term_id) {
        throw new Exception('All required fields must be filled');
    }

    // Prepare and execute query
    $stmt = $conn->prepare("INSERT INTO expenses (category, amount, expense_date, description, payment_method, recipient_name, term_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("sdssssi", $category, $amount, $expense_date, $description, $payment_method, $recipient_name, $term_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $response = [
        'success' => true,
        'message' => 'Expense added successfully',
        'expense_id' => $conn->insert_id
    ];

    // Clear any output buffered content
    ob_clean();
    
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in add_expense.php: " . $e->getMessage());
    
    // Clear any output buffered content
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}

// End output buffering
ob_end_flush();