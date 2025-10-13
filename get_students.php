 <?php
session_start();
// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized access');
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    exit('Connection failed: ' . $conn->connect_error);
}

if (isset($_POST['class_id'])) {
    $class_id = $_POST['class_id'];
    $subject_id = isset($_POST['subject_id']) ? $_POST['subject_id'] : null;
    
    // Fetch students for the selected class
    $students_query = "SELECT id, firstname, lastname FROM students WHERE class_id = ?";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    
    // Get currently assigned students if subject_id is provided
    $assigned_students = [];
    if ($subject_id) {
        // Check for assignments with status = 'active' OR assignments without status field (legacy assignments)
        $assigned_query = "SELECT student_id FROM student_subjects 
                          WHERE subject_id = ? 
                          AND (status = 'active' OR status IS NULL OR status = '')";
        $stmt_assigned = $conn->prepare($assigned_query);
        $stmt_assigned->bind_param("i", $subject_id);
        $stmt_assigned->execute();
        $assigned_result = $stmt_assigned->get_result();
        while ($row = $assigned_result->fetch_assoc()) {
            $assigned_students[] = $row['student_id'];
        }
        $stmt_assigned->close();
    }
    
    while ($student = $students_result->fetch_assoc()) {
        $is_checked = in_array($student['id'], $assigned_students) ? 'checked' : '';
        $status_badge = in_array($student['id'], $assigned_students) ? 
            '<span class="badge bg-success ms-2">Assigned</span>' : 
            '<span class="badge bg-secondary ms-2">Not Assigned</span>';
        
        echo '<div class="form-check student-item">';
        echo '<input class="form-check-input student-checkbox" type="checkbox" name="student_ids[]" value="' . $student['id'] . '" id="student_' . $student['id'] . '" ' . $is_checked . '>';
        echo '<label class="form-check-label d-flex align-items-center" for="student_' . $student['id'] . '">';
        echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']);
        echo $status_badge;
        echo '</label>';
        echo '</div>';
    }
    
    $stmt->close();
}

$conn->close();