 <?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    die(json_encode(['error' => 'Unauthorized access']));
}
// Database connection
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_37541636';
$db_pass = '2C3z0YzNwzMxS8N';
$db_name = 'if0_37541636_sms';


$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

$term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;
$month_year = isset($_GET['month_year']) ? $_GET['month_year'] : null;

try {
    // Base query
    $query = "SELECT 
        e.*,
        t.name as term_name,
        t.year as term_year
    FROM expenses e
    LEFT JOIN terms t ON e.term_id = t.id
    WHERE e.term_id = ?";
    
    // Add month filter if provided (format: YYYY-MM)
    $params = [$term_id];
    $param_types = "i";
    
    if ($month_year) {
        // Extract year and month from the month_year parameter
        list($year, $month) = explode('-', $month_year);
        
        // Add date filtering conditions
        $query .= " AND YEAR(e.expense_date) = ? AND MONTH(e.expense_date) = ?";
        $params[] = $year;
        $params[] = $month;
        $param_types .= "ii";
    }
    
    // Add order by
    $query .= " ORDER BY e.expense_date DESC, e.expense_id DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    // Dynamically bind parameters
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $expenses = [];
    while ($row = $result->fetch_assoc()) {
        $expenses[] = [
            'expense_id' => $row['expense_id'],
            'term_id' => $row['term_id'],
            'category' => $row['category'],
            'amount' => floatval($row['amount']),
            'expense_date' => date('Y-m-d', strtotime($row['expense_date'])),
            'description' => $row['description'],
            'payment_method' => ucfirst(str_replace('_', ' ', $row['payment_method'])),
            'recipient_name' => $row['recipient_name'],
            'term_info' => $row['term_name'] . ' ' . $row['term_year']
        ];
    }
    
    // Format for DataTables
    echo json_encode([
        'data' => $expenses
    ]);

} catch (Exception $e) {
    // Log error
    error_log("Error in get_expenses.php: " . $e->getMessage());
    
    echo json_encode([
        'data' => [],
        'error' => 'Failed to fetch expenses: ' . $e->getMessage()
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>