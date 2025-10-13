 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    exit('Unauthorized');
}

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    exit("Connection failed: " . $conn->connect_error);
}

$user_school_id = $_SESSION['school_id'];

// Get the latest admission number for this school
$query = $conn->prepare("SELECT admission_number FROM students WHERE school_id = ? ORDER BY id DESC LIMIT 1");
$query->bind_param("i", $user_school_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $lastNumber = $row['admission_number'];
    
    // Extract the numerical part
    $matches = [];
    if (preg_match('/ADM-(\d+)/', $lastNumber, $matches)) {
        $number = intval($matches[1]) + 1;
        echo 'ADM-' . sprintf('%04d', $number); // Format to ensure 3 digits with leading zeros
    }
} else {
    // If no students exist or pattern doesn't match, start with 001
    echo 'ADM-0001';
}

$conn->close();
?