 <?php

session_start();
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';


$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Unauthorized');
}

$class_id = $_GET['class_id'] ?? null;
$term_id = $_GET['term_id'] ?? null;
$school_id = $_SESSION['school_id'];

if (!$class_id || !$term_id) {
    http_response_code(400);
    exit('Missing parameters');
}

$query = "SELECT DISTINCT exam_type 
          FROM exams 
          WHERE school_id = ? 
          AND term_id = ? 
          AND is_active = 1 
          ORDER BY exam_type";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $school_id, $term_id);
$stmt->execute();
$result = $stmt->get_result();

$exam_types = [];
while ($row = $result->fetch_assoc()) {
    $exam_types[] = $row['exam_type'];
}

header('Content-Type: application/json');
echo json_encode($exam_types);
exit();