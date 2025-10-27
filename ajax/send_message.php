<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed']);
    exit();
}

$school_id = $_SESSION['school_id'];
$sender_user_id = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$content = trim($input['content'] ?? '');

if ($content === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Message cannot be empty']);
    exit();
}

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_school_created (school_id, created_at),
  INDEX idx_school_id (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $conn->prepare("INSERT INTO messages (school_id, sender_user_id, content) VALUES (?, ?, ?)");
$stmt->bind_param('iis', $school_id, $sender_user_id, $content);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message']);
}
exit();
?>


