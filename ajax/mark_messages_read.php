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
$user_id = $_SESSION['user_id'];

$max_id = isset($_POST['max_id']) ? intval($_POST['max_id']) : 0;
if ($max_id <= 0) {
    echo json_encode(['success' => true]);
    exit();
}

// Ensure reads table exists
$conn->query("CREATE TABLE IF NOT EXISTS message_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_read (message_id, user_id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Insert read markers up to max_id for messages in this school
$sql = "INSERT IGNORE INTO message_reads (message_id, user_id)
        SELECT m.id, ? FROM messages m
        WHERE m.school_id = ? AND m.id <= ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $user_id, $school_id, $max_id);
$stmt->execute();

echo json_encode(['success' => true]);
exit();
?>


