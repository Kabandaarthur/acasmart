 <?php
session_start();
// Ensure admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit('Unauthorized');
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'sms');
if ($conn->connect_error) {
    http_response_code(500);
    exit('Database connection failed');
}

$school_id = $_SESSION['school_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_type = isset($_GET['exam_type']) ? $conn->real_escape_string($_GET['exam_type']) : '';
$category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : '';

// Validation
if (!$class_id || !$exam_type || !$category) {
    http_response_code(400);
    exit('Invalid class ID, exam type, or category');
}

// Get current term
$term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt = $conn->prepare($term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$current_term = $term_result->fetch_assoc();

if (!$current_term) {
    http_response_code(400);
    exit('No current term found');
}

$term_id = $current_term['id'];

// Get class name
$class_query = "SELECT name FROM classes WHERE id = ? AND school_id = ?";
$stmt = $conn->prepare($class_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class_result = $stmt->get_result();
$class_name = $class_result->fetch_assoc()['name'] ?? 'Unknown Class';

// Fetch report data with term and category filter
$report_query = "
    SELECT
        s.firstname, s.lastname,
        er.subject_id, sub.subject_name,
        e.exam_type, er.score, er.topic, e.max_score
    FROM students s
    JOIN exam_results er ON s.id = er.student_id
    JOIN exams e ON er.exam_id = e.exam_id
    JOIN subjects sub ON er.subject_id = sub.subject_id
    WHERE s.class_id = ? 
    AND s.school_id = ? 
    AND e.exam_type = ?
    AND e.category = ? 
    AND e.term_id = ?
    ORDER BY s.lastname, s.firstname, sub.subject_name
";

$stmt = $conn->prepare($report_query);
$stmt->bind_param("iissi", $class_id, $school_id, $exam_type, $category, $term_id);
$stmt->execute();
$result = $stmt->get_result();

$report = [];
$subjects = [];
$max_scores = [];

while ($row = $result->fetch_assoc()) {
    $student_name = $row['firstname'] . ' ' . $row['lastname'];
    $subject = $row['subject_name'];
    
    if (!isset($report[$student_name])) {
        $report[$student_name] = [];
    }
    
    if (!in_array($subject, $subjects)) {
        $subjects[] = $subject;
        $max_scores[$subject] = $row['max_score'];
    }
    
    $report[$student_name][$subject] = $row['score'];
}

// Calculate totals and averages
foreach ($report as $student => $scores) {
    $total = 0;
    $count = 0;
    foreach ($subjects as $subject) {
        if (isset($scores[$subject])) {
            $total += ($scores[$subject] / $max_scores[$subject]) * 100; // Convert to percentage
            $count++;
        }
    }
    $average = $count > 0 ? $total / $count : 0;
    $report[$student]['total'] = round($total, 2);
    $report[$student]['average'] = round($average, 2);
}

// JSON response with term and category information
echo json_encode([
    'class_name' => $class_name,
    'exam_type' => $exam_type,
    'category' => $category,
    'term_name' => $current_term['name'],
    'academic_year' => $current_term['year'],
    'report' => $report,
    'subjects' => $subjects,
    'max_scores' => $max_scores
]);

$conn->close();
?>