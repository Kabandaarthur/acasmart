 <?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Database connection
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_37541636';
$db_pass = '2C3z0YzNwzMxS8N';
$db_name = 'if0_37541636_sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Get expense ID from request
$expense_id = filter_input(INPUT_GET, 'expense_id', FILTER_SANITIZE_NUMBER_INT);

if (!$expense_id) {
    echo json_encode(['error' => 'Invalid expense ID']);
    exit();
}

// Get expense details
$stmt = $conn->prepare("SELECT * FROM expenses WHERE expense_id = ?");
$stmt->bind_param("i", $expense_id);
$stmt->execute();
$result = $stmt->get_result();

if ($expense = $result->fetch_assoc()) {
    // Format the date to Y-m-d for the date input
    $expense['expense_date'] = date('Y-m-d', strtotime($expense['expense_date']));
    echo json_encode($expense);
} else {
    echo json_encode(['error' => 'Expense not found']);
}

$stmt->close();
$conn->close();