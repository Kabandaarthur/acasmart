 <?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['school_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Database connection details
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';


// Create database connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Get teacher ID and school ID from session
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Check if exam_id is provided
if (!isset($_GET['exam_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Exam ID is required']);
    exit();
}

$exam_id = intval($_GET['exam_id']);

// Get current term and subject
$current_term_query = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1";
$stmt_term = $conn->prepare($current_term_query);
$stmt_term->bind_param("i", $school_id);
$stmt_term->execute();
$result_term = $stmt_term->get_result();
$current_term_id = $result_term->fetch_assoc()['id'];

$current_subject_query = "SELECT subject_id FROM teacher_subjects WHERE user_id = ? LIMIT 1";
$stmt_subject = $conn->prepare($current_subject_query);
$stmt_subject->bind_param("i", $teacher_id);
$stmt_subject->execute();
$result_subject = $stmt_subject->get_result();
$current_subject_id = $result_subject->fetch_assoc()['subject_id'];

// Verify that the exam belongs to the teacher's school, subject, and current term
$verify_query = "SELECT e.id
                 FROM exams e
                 FROM exam_results er
                 JOIN subjects s ON er.subject_id = s.subject_id
                 JOIN teacher_subjects ts ON s.subject_id = ts.subject_id
                 WHERE e.exam_id = ? AND e.school_id = ? AND ts.user_id = ? AND e.term_id = ?";
$stmt_verify = $conn->prepare($verify_query);
$stmt_verify->bind_param("iiii", $exam_id, $school_id, $teacher_id, $current_term_id);
$stmt_verify->execute();
$result_verify = $stmt_verify->get_result();

if ($result_verify->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access to this exam']);
    exit();
}

// Fetch exam details and student results for the current term and subject
$query = "SELECT e.exam_id as exam_id, e.max_score,
                 er.result_id as exam_result_id, s.id as student_id,
                 CONCAT(s.firstname, ' ', s.lastname) AS student_name, er.score
          FROM exams e
          LEFT JOIN exam_results er ON e.exam_id = er.exam_id
          LEFT JOIN students s ON er.student_id = s.id
          WHERE e.id = ? AND e.school_id = ? AND e.term_id = ?
          ORDER BY s.lastname, s.firstname";

$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $exam_id, $school_id, current_term_id);
$stmt->execute();
$result = $stmt->get_result();

$exam_info = null;
$students = [];

while ($row = $result->fetch_assoc()) {
    if ($exam_info === null) {
        $exam_info = [
            'exam_id' => $row['exam_id'],
            'exam_name' => $row['exam_name'],
            'max_score' => $row['max_score']
        ];
    }
    
    if ($row['student_id'] !== null) {
        $students[] = [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'exam_result_id' => $row['exam_result_id'],
            'score' => $row['score']
        ];
    }
}

// Prepare the response
$response = [
    'exam_info' => $exam_info,
    'students' => $students,
    'current_term_id' => $current_term_id,
    'current_subject_id' => $current_subject_id
];

// Set the response header to JSON
header('Content-Type: application/json');

// Output the JSON-encoded response
echo json_encode($response);

// Close the database connection
$conn->close();
?>