 <?php
session_start();

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
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


$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Validation
    $errors = [];
    
    // Check if passwords are not empty
    if (empty($new_password)) {
        $errors[] = "New password is required.";
    }
    
    if (empty($confirm_password)) {
        $errors[] = "Confirm password is required.";
    }
    
    // Check if passwords match
    if ($new_password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check password length (minimum 6 characters)
    if (strlen($new_password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    
    // If no errors, update the password
    if (empty($errors)) {
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password in database
        $update_query = "UPDATE students SET student_password = ? WHERE id = ? AND school_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sii", $hashed_password, $student_id, $school_id);
        
        if ($stmt->execute()) {
            $success_message = "Password updated successfully!";
        } else {
            $errors[] = "Failed to update password. Please try again.";
        }
        
        $stmt->close();
    }
}

// Redirect back to student dashboard with messages
$redirect_url = "student_dashboard.php";

if (!empty($errors)) {
    $error_string = implode(" ", $errors);
    $redirect_url .= "?error=" . urlencode($error_string);
}

if (isset($success_message)) {
    $redirect_url .= "?success=" . urlencode($success_message);
}

header("Location: " . $redirect_url);
exit();
?>