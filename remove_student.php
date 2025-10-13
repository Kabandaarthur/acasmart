 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Get and validate input parameters
    $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
    $subject_id = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : 0;

    if (!$student_id || !$subject_id) {
        throw new Exception("Invalid student or subject ID");
    }

    // Get the admin's school_id
    $admin_id = $_SESSION['user_id'];
    $school_query = "SELECT school_id FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($school_query);
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin_data = $result->fetch_assoc();
    $school_id = $admin_data['school_id'];
    $stmt->close();

    // Verify that the student and subject belong to the admin's school
    $verify_query = "SELECT s.id, sub.subject_id 
                    FROM students s
                    JOIN subjects sub ON sub.school_id = s.school_id
                    WHERE s.id = ? AND sub.subject_id = ? AND s.school_id = ?";
    
    $stmt = $conn->prepare($verify_query);
    $stmt->bind_param("iii", $student_id, $subject_id, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Student or subject not found in your school");
    }
    $stmt->close();

    // Delete the student-subject association
    $delete_query = "DELETE FROM student_subjects 
                    WHERE student_id = ? AND subject_id = ?";
    
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("ii", $student_id, $subject_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response['success'] = true;
            $response['message'] = "Student successfully removed from the subject";
        } else {
            $response['message'] = "Student was not enrolled in this subject";
        }
    } else {
        throw new Exception("Error removing student from subject");
    }
    
    $stmt->close();

} catch (Exception $e) {
    $response['message'] = "Error: " . $e->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response);
exit()