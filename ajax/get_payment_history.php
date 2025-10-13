 <?php
session_start();

// Check if user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Database connection
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_37541636';
$db_pass = '2C3z0YzNwzMxS8N';
$db_name = 'if0_37541636_sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Connection failed']);
    exit();
}

if (!isset($_GET['student_id']) || !isset($_GET['term_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Student ID and Term ID are required']);
    exit();
}

$student_id = intval($_GET['student_id']);
$term_id = intval($_GET['term_id']);
$school_id = $_SESSION['school_id'];

// Get payment history
$query = "SELECT 
            fp.*,
            t.name as term_name,
            t.year as term_year
          FROM fee_payments fp
          LEFT JOIN terms t ON fp.term_id = t.id
          WHERE fp.student_id = ? 
          AND fp.term_id = ?
          AND fp.school_id = ?
          ORDER BY fp.payment_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $student_id, $term_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
$payments = $result->fetch_all(MYSQLI_ASSOC);

// Get payment summary
$summary_query = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_amount,
                    MAX(payment_date) as last_payment_date,
                    GROUP_CONCAT(DISTINCT payment_method) as payment_methods
                 FROM fee_payments 
                 WHERE student_id = ? 
                 AND term_id = ? 
                 AND school_id = ?";

$stmt = $conn->prepare($summary_query);
$stmt->bind_param("iii", $student_id, $term_id, $school_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Format the response
$response = [
    'payments' => $payments,
    'summary' => [
        'total_payments' => intval($summary['total_payments']),
        'total_amount' => floatval($summary['total_amount']),
        'last_payment_date' => $summary['last_payment_date'],
        'payment_methods' => explode(',', $summary['payment_methods'] ?? '')
    ]
];

header('Content-Type: application/json');
echo json_encode($response);
exit();