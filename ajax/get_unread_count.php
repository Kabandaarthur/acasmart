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

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_school_created (school_id, created_at),
  INDEX idx_school_id (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS message_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  user_id INT NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_read (message_id, user_id),
  INDEX idx_user (user_id),
  CONSTRAINT fk_reads_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sql = "SELECT COUNT(*) AS cnt
        FROM messages m
        WHERE m.school_id = ?
          AND m.sender_user_id <> ?
          AND NOT EXISTS (
            SELECT 1 FROM message_reads r WHERE r.message_id = m.id AND r.user_id = ?
          )";

$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $school_id, $user_id, $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$count = (int)($res['cnt'] ?? 0);

echo json_encode(['unread' => $count]);
exit();
?>


