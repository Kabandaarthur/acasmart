<?php
session_start();
header('Content-Type: application/json');

// Basic auth check consistent with other endpoints
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$rawName = isset($_GET['student_name']) ? $_GET['student_name'] : '';
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$school_id = $_SESSION['school_id'] ?? 0;

if (!$rawName || !$class_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

// Decode once in case of double-encoding
$studentName = urldecode($rawName);
$studentName = trim($studentName);

// Try a few name variants (firstname lastname, lastname firstname)
// Normalize by removing extra spaces for comparison
$normalized = preg_replace('/\s+/', ' ', $studentName);
$parts = explode(' ', $normalized);

$possibleNames = [];
if (count($parts) >= 2) {
    $first = array_shift($parts);
    $last = implode(' ', $parts);
    $possibleNames[] = "$first $last";
    $possibleNames[] = "$last $first";
} else {
    $possibleNames[] = $normalized;
}

// Prepare a flexible query that strips spaces when comparing
$query = "SELECT id, firstname, lastname FROM students WHERE class_id = ? AND school_id = ? AND (
            REPLACE(CONCAT(firstname, ' ', lastname), ' ', '') = REPLACE(?, ' ', '')
            OR REPLACE(CONCAT(lastname, ' ', firstname), ' ', '') = REPLACE(?, ' ', '')
        ) LIMIT 1";

$stmt = $conn->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare query']);
    exit();
}

// Use the first possible normalized name
$searchName = $possibleNames[0];
$stmt->bind_param('iiss', $class_id, $school_id, $searchName, $searchName);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    echo json_encode(['student_id' => $row['id']]);
    $stmt->close();
    $conn->close();
    exit();
}

// Try second variant if exists
if (isset($possibleNames[1])) {
    $searchName = $possibleNames[1];
    $stmt->bind_param('iiss', $class_id, $school_id, $searchName, $searchName);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        echo json_encode(['student_id' => $row['id']]);
        $stmt->close();
        $conn->close();
        exit();
    }
}

// If not found, return an informative error
http_response_code(404);
echo json_encode(['error' => 'Student not found']);
$stmt->close();
$conn->close();
exit();
?>
