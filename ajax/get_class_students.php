 <?php
session_start();

// Database connection
$db_host = 'sql208.infinityfree.com';
$db_user = 'if0_37541636';
$db_pass = '2C3z0YzNwzMxS8N';
$db_name = 'if0_37541636_sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    die(json_encode(['error' => 'Unauthorized access']));
}

if (!isset($_POST['class_id'])) {
    die(json_encode(['error' => 'Class ID is required']));
}

try {
    // Get class name
    $stmt = $conn->prepare("SELECT class_name FROM classes WHERE class_id = ?");
    $stmt->execute([$_POST['class_id']]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get students in the class with their fee balances
    $stmt = $conn->prepare("
        SELECT 
            s.student_id,
            s.admission_number,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            COALESCE(
                (SELECT (f.amount - COALESCE(SUM(p.amount), 0))
                FROM fee_structure f
                LEFT JOIN payments p ON p.student_id = s.student_id 
                    AND p.term_id = (SELECT term_id FROM terms WHERE is_current = 1)
                WHERE f.class_id = s.current_class_id 
                    AND f.term_id = (SELECT term_id FROM terms WHERE is_current = 1)
                GROUP BY f.amount
                ), 0
            ) as balance
        FROM students s
        WHERE s.current_class_id = ? AND s.status = 'active'
        ORDER BY s.admission_number
    ");
    $stmt->execute([$_POST['class_id']]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'class_name' => $class['class_name'],
        'students' => $students
    ]);

} catch (PDOException $e) {
    die(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}