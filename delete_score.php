 
<?php
// delete_score.php
session_start();

// Check if the user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';


$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$student_id = $data['studentId'];
$school_id = $_SESSION['school_id'];

// Get the current term
$query_current_term = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt_term = $conn->prepare($query_current_term);
$stmt_term->bind_param("i", $school_id);
$stmt_term->execute();
$result_term = $stmt_term->get_result();
$current_term = $result_term->fetch_assoc();

if (!$current_term) {
    echo json_encode(['success' => false, 'message' => 'No active term found']);
    exit();
}

$current_term_id = $current_term['id'];

// Get the exam ID
$query_exam = "SELECT exam_id FROM exams WHERE exam_type = ? AND school_id = ? AND term_id = ? LIMIT 1";
$stmt_exam = $conn->prepare($query_exam);
$stmt_exam->bind_param("sii", $data['examType'], $school_id, $current_term_id);
$stmt_exam->execute();
$result_exam = $stmt_exam->get_result();
$exam = $result_exam->fetch_assoc();

if (!$exam) {
    echo json_encode(['success' => false, 'message' => 'Exam not found']);
    exit();
}

// Delete the score
$query_delete = "DELETE FROM exam_results 
                 WHERE exam_id = ? 
                 AND student_id = ? 
                 AND subject_id = ? 
                 AND school_id = ? 
                 AND term_id = ?";

$stmt_delete = $conn->prepare($query_delete);
$stmt_delete->bind_param("iiiii", 
    $exam['exam_id'],
    $student_id,
    $data['subjectId'],
    $school_id,
    $current_term_id
);

if ($stmt_delete->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to delete score']);
}

$conn->close();
?