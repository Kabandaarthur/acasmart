<?php
session_start();

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
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

$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    $errors = [];
    if ($new_password === '' || $confirm_password === '') {
        $errors[] = 'New password and confirmation are required.';
    }
    if ($new_password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }
    if (strlen($new_password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    // Fetch current hashed password for teacher
    $fetch_query = "SELECT password FROM users WHERE user_id = ? AND school_id = ? AND role = 'teacher'";
    $stmt_fetch = $conn->prepare($fetch_query);
    $stmt_fetch->bind_param("ii", $teacher_id, $school_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        $errors[] = 'Account not found.';
    } else {
        $hashed = $row['password'];
        if ($current_password === '' || !password_verify($current_password, $hashed)) {
            $errors[] = 'Current password is incorrect.';
        }
    }

    if (empty($errors)) {
        $new_hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update_query = "UPDATE users SET password = ? WHERE user_id = ? AND school_id = ? AND role = 'teacher'";
        $stmt_update = $conn->prepare($update_query);
        $stmt_update->bind_param("sii", $new_hashed, $teacher_id, $school_id);
        if ($stmt_update->execute()) {
            header('Location: profile.php?success=' . urlencode('Password updated successfully.'));
            exit();
        } else {
            $errors[] = 'Failed to update password. Please try again.';
        }
    }

    if (!empty($errors)) {
        header('Location: profile.php?error=' . urlencode(implode(' ', $errors)));
        exit();
    }
}

header('Location: profile.php');
exit();
?>


