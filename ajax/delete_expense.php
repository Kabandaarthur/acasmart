 <?php
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

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get and validate expense ID
$expense_id = filter_input(INPUT_POST, 'expense_id', FILTER_SANITIZE_NUMBER_INT);

if (!$expense_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid expense ID']);
    exit();
}

// Prepare and execute query
$stmt = $conn->prepare("DELETE FROM expenses WHERE expense_id = ?");
$stmt->bind_param("i", $expense_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Expense deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Error deleting expense: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>