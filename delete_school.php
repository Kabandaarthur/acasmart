 <?php
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit();
}

// Check if an ID was provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['notification'] = "Invalid school ID provided.";
    header("Location: super_admin_dashboard.php");
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

$school_id = (int)$_GET['id'];

// First, get the school badge filename if it exists
$stmt = $conn->prepare("SELECT badge FROM schools WHERE id = ?");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
$school = $result->fetch_assoc();

// Delete the school from the database
$stmt = $conn->prepare("DELETE FROM schools WHERE id = ?");
$stmt->bind_param("i", $school_id);

if ($stmt->execute()) {
    // If deletion was successful and there was a badge, delete the file
    if ($school && $school['badge']) {
        $badge_path = "uploads/" . $school['badge'];
        if (file_exists($badge_path)) {
            unlink($badge_path);
        }
    }
    
    $_SESSION['notification'] = "School has been successfully deleted.";
} else {
    $_SESSION['notification'] = "Error deleting school: " . $conn->error;
}

$stmt->close();
$conn->close();

// Redirect back to the dashboard
header("Location: super_admin_dashboard.php");
exit()