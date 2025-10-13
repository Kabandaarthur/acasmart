 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get class name if class_id is provided
$class_name = "all_classes";
if (isset($_GET['class_id'])) {
    $class_id = intval($_GET['class_id']);
    $stmt = $conn->prepare("SELECT name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $class_id, $_SESSION['school_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $class_name = strtolower(str_replace(' ', '_', $row['name']));
    }
    $stmt->close();
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="students_upload_template_' . $class_name . '.csv"');

// Create the CSV header row with required fields
// Note: admission_number is automatically generated
$headers = [
    'firstname',
    'lastname',
    'gender',
    'age',
    'stream',
    'lin_number',
    'father_name',
    'father_contact',
    'mother_name',
    'mother_contact',
    'home_of_residence',
    'home_email'
];

// Open output stream
$output = fopen('php://output', 'w');

// Write header row only
fputcsv($output, $headers);

// Close the output stream
fclose($output);
exit();
?>