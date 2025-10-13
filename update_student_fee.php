 <?php
session_start();

// Check if user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = $_POST['student_id'];
    $fee_id = $_POST['fee_id'];
    $term_id = $_POST['term_id'];
    $adjusted_amount = $_POST['adjusted_amount'];
    $adjustment_reason = $_POST['adjustment_reason'];
    
    // First, check if an adjustment already exists
    $check_query = "SELECT id FROM student_fee_adjustments 
                   WHERE student_id = ? AND fee_id = ? AND term_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("iii", $student_id, $fee_id, $term_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing adjustment
        $update_query = "UPDATE student_fee_adjustments 
                        SET adjusted_amount = ?, adjustment_reason = ?, updated_at = NOW() 
                        WHERE student_id = ? AND fee_id = ? AND term_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("dsiii", $adjusted_amount, $adjustment_reason, $student_id, $fee_id, $term_id);
    } else {
        // Insert new adjustment
        $insert_query = "INSERT INTO student_fee_adjustments 
                        (student_id, fee_id, term_id, adjusted_amount, adjustment_reason, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("iiids", $student_id, $fee_id, $term_id, $adjusted_amount, $adjustment_reason);
    }
    
    if ($stmt->execute()) {
        header("Location: record_payment.php?view=student&student_id=" . $student_id . "&success=1");
    } else {
        header("Location: record_payment.php?view=student&student_id=" . $student_id . "&error=1");
    }
    
    $stmt->close();
}

$conn->close();
?>