 <?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

// Get current year and month
$current_year = date('Y');
$current_month = date('m');

try {
    // Get total expenses for the term
    $total_query = "SELECT 
        COALESCE(SUM(amount), 0) as total_expenses,
        COUNT(DISTINCT category) as total_categories,
        COALESCE(AVG(amount), 0) as average_expense
    FROM expenses 
    WHERE term_id = ?";
    
    $stmt = $conn->prepare($total_query);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    // Get current month expenses
    $month_query = "SELECT 
        COALESCE(SUM(amount), 0) as month_expenses,
        COUNT(*) as month_count
    FROM expenses 
    WHERE term_id = ? AND YEAR(expense_date) = ? AND MONTH(expense_date) = ?";
    
    $stmt = $conn->prepare($month_query);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("iii", $term_id, $current_year, $current_month);
    $stmt->execute();
    $month_result = $stmt->get_result();
    $month_stats = $month_result->fetch_assoc();
    
    echo json_encode([
        'total_expenses' => 'UGX ' . number_format($stats['total_expenses'], 0),
        'total_categories' => $stats['total_categories'],
        'average_expense' => 'UGX ' . number_format($stats['average_expense'], 0),
        'month_expenses' => 'UGX ' . number_format($month_stats['month_expenses'], 0),
        'month_count' => $month_stats['month_count'],
        'current_month' => date('F Y')
    ]);

} catch (Exception $e) {
    error_log("Error in get_expense_stats.php: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to fetch expense statistics']);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>