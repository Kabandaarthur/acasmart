 <?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

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

    // Get and validate input
    $expense_id = isset($_POST['expense_id']) ? (int)$_POST['expense_id'] : 0;
    $category = isset($_POST['category']) ? htmlspecialchars($_POST['category']) : '';
    $amount = isset($_POST['amount']) ? str_replace(',', '', $_POST['amount']) : 0;
    $expense_date = isset($_POST['expense_date']) ? $_POST['expense_date'] : '';
    $description = isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '';
    $payment_method = isset($_POST['payment_method']) ? htmlspecialchars($_POST['payment_method']) : '';
    $recipient_name = isset($_POST['recipient_name']) ? htmlspecialchars($_POST['recipient_name']) : '';

    // Validate required fields
    if (!$expense_id || !$category || !$amount || !$expense_date || !$description || !$payment_method || !$recipient_name) {
        throw new Exception('All required fields must be filled');
    }

    // Validate amount
    if (!is_numeric($amount) || $amount <= 0) {
        throw new Exception('Invalid amount');
    }

    // Validate date
    $date = DateTime::createFromFormat('Y-m-d', $expense_date);
    if (!$date || $date->format('Y-m-d') !== $expense_date) {
        throw new Exception('Invalid date format');
    }

    // Start transaction
    $conn->begin_transaction();

    // Check if expense exists
    $check = $conn->prepare("SELECT expense_id FROM expenses WHERE expense_id = ?");
    $check->bind_param("i", $expense_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Expense not found');
    }
    $check->close();

    // Update expense
    $stmt = $conn->prepare("UPDATE expenses SET category = ?, amount = ?, expense_date = ?, description = ?, payment_method = ?, recipient_name = ? WHERE expense_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("sdssssi", $category, $amount, $expense_date, $description, $payment_method, $recipient_name, $expense_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    if ($stmt->affected_rows === 0) {
        throw new Exception('No changes were made to the expense');
    }

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Expense updated successfully',
        'expense_id' => $expense_id
    ]);

} catch (Exception $e) {
    // Rollback transaction if active
    if (isset($conn) && $conn->connect_error === false) {
        $conn->rollback();
    }
    
    error_log("Error in update_expense.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} finally {
    // Close statements and connection
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}