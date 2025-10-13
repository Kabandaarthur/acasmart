 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit();
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

if (!isset($_POST['subject_id']) || !isset($_POST['class_id'])) {
    echo "No subject or class selected";
    exit();
}

$subject_id = $_POST['subject_id'];
$class_id = $_POST['class_id'];

// Get subject details with class name
$subject_query = "SELECT s.subject_name, s.class_id, c.name as class_name 
                  FROM subjects s 
                  JOIN classes c ON s.class_id = c.id 
                  WHERE s.subject_id = ? AND s.school_id = ?";
$stmt = $conn->prepare($subject_query);
$stmt->bind_param("ii", $subject_id, $school_id);
$stmt->execute();
$subject_result = $stmt->get_result();
$subject_data = $subject_result->fetch_assoc();
$stmt->close();

if (!$subject_data) {
    echo "Subject not found";
    exit();
}

// Get assigned students for the specific subject and class
$query = "SELECT s.id, s.firstname, s.lastname, s.gender, s.stream, c.name as class_name, 
          ss.created_at as assignment_date
          FROM students s
          JOIN student_subjects ss ON s.id = ss.student_id
          JOIN classes c ON s.class_id = c.id
          WHERE ss.subject_id = ? AND s.class_id = ? AND s.school_id = ? 
          AND (ss.status = 'active' OR ss.status IS NULL OR ss.status = '')
          ORDER BY s.firstname, s.lastname";

$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $subject_id, $class_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<div class='mb-3'>";
    echo "<h6>Subject: " . htmlspecialchars($subject_data['subject_name']) . "</h6>";
    echo "<h6>Class: " . htmlspecialchars($subject_data['class_name']) . "</h6>";
    echo "</div>";
    
    echo "<div class='table-responsive'>";
    echo "<table class='table table-hover'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>Student Name</th>";
    echo "<th>Gender</th>";
    echo "<th>Stream</th>";
    echo "<th>Class</th>";
    echo "<th>Action</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) . "</td>";
        echo "<td>" . htmlspecialchars($row['gender']) . "</td>";
        echo "<td>" . htmlspecialchars($row['stream']) . "</td>";
        echo "<td>" . htmlspecialchars($row['class_name']) . "</td>";
        echo "<td>";
        echo "<button type='button' class='btn btn-danger btn-sm remove-student' data-student-id='" . $row['id'] . "'>";
        echo "<i class='bi bi-trash'></i> Remove";
        echo "</button>";
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
} else {
    echo "<div class='alert alert-info'>";
    echo "<i class='bi bi-info-circle'></i> No students are currently assigned to this subject.";
    echo "</div>";
}

$stmt->close();
$conn->close();
