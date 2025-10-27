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
$since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;
$limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 50;

// Ensure table exists (lightweight safety)
$conn->query("CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_school_created (school_id, created_at),
  INDEX idx_school_id (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$query = "SELECT m.id, m.content, m.created_at, m.sender_user_id, u.username, CONCAT(u.firstname, ' ', u.lastname) AS sender_name
          FROM messages m
          JOIN users u ON u.user_id = m.sender_user_id
          WHERE m.school_id = ? " . ($since_id > 0 ? "AND m.id > ? " : "") . "
          ORDER BY m.id DESC
          LIMIT ?";

if ($since_id > 0) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iii', $school_id, $since_id, $limit);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $school_id, $limit);
}

$stmt->execute();
$result = $stmt->get_result();
$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => (int)$row['id'],
        'content' => $row['content'],
        'created_at' => $row['created_at'],
        'sender_user_id' => (int)$row['sender_user_id'],
        'sender_name' => $row['sender_name'],
        'username' => $row['username']
    ];
}

// Return in chronological order ascending for UI convenience
usort($messages, function($a, $b){ return $a['id'] <=> $b['id']; });

echo json_encode(['messages' => $messages]);
exit();
?>


