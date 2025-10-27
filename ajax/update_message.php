<?php
session_start();
header('Content-Type: application/json');
@ini_set('display_errors', '0');
@error_reporting(0);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$message_id = isset($data['id']) ? (int)$data['id'] : 0;
$content = isset($data['content']) ? trim($data['content']) : '';

if ($message_id <= 0 || $content === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];

// Ensure the message exists and belongs to the current teacher in this school
$stmt = $conn->prepare("SELECT id FROM messages WHERE id = ? AND sender_user_id = ? AND school_id = ?");
$stmt->bind_param('iii', $message_id, $user_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Message not found or not permitted']);
    exit;
}
$stmt->close();

$stmt_upd = $conn->prepare("UPDATE messages SET content = ? WHERE id = ?");
$stmt_upd->bind_param('si', $content, $message_id);
if ($stmt_upd && $stmt_upd->execute()) {
    echo json_encode(['success' => true, 'id' => $message_id, 'content' => $content]);
} else {
    $err = $conn->error ? $conn->error : 'Failed to update';
    echo json_encode(['success' => false, 'message' => $err]);
}
$stmt_upd->close();

?>


