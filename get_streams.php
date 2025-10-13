 <?php
session_start();

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;

$query = "SELECT DISTINCT stream 
          FROM students 
          WHERE school_id = ? 
          AND class_id = ? 
          AND stream IS NOT NULL 
          ORDER BY stream ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $school_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();

echo '<option value="">All Streams</option>';
while ($row = $result->fetch_assoc()) {
    echo '<option value="' . htmlspecialchars($row['stream']) . '">' . htmlspecialchars($row['stream']) . '</option>';
}

$stmt->close();
$conn->close();
?