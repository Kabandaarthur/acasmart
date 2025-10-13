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

try {
    // Get category summary for the term
    $query = "SELECT 
        category,
        COUNT(*) as transaction_count,
        SUM(amount) as total_amount
    FROM expenses 
    WHERE term_id = ?
    GROUP BY category
    ORDER BY SUM(amount) DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            'category' => $row['category'],
            'transaction_count' => $row['transaction_count'],
            'total_amount' => floatval($row['total_amount'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $categories
    ]);

} catch (Exception $e) {
    error_log("Error in get_category_summary.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch category summary'
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
} <?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    die(json_encode(['error' => 'Unauthorized access']));
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

$term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;

try {
    // Get category summary for the term
    $query = "SELECT 
        category,
        COUNT(*) as transaction_count,
        SUM(amount) as total_amount
    FROM expenses 
    WHERE term_id = ?
    GROUP BY category
    ORDER BY SUM(amount) DESC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            'category' => $row['category'],
            'transaction_count' => $row['transaction_count'],
            'total_amount' => floatval($row['total_amount'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $categories
    ]);

} catch (Exception $e) {
    error_log("Error in get_category_summary.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch category summary'
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}