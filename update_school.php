 <?php
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $school_id = $_POST['school_id'];
    $school_name = $conn->real_escape_string($_POST['school_name']); // Add school name
    $motto = $conn->real_escape_string($_POST['motto']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);

    // Initialize update query with school_name
    $update_query = "UPDATE schools SET school_name = ?, motto = ?, email = ?, phone = ?";
    $params = array($school_name, $motto, $email, $phone);
    $types = "ssss";

    // Handle file upload if new badge is provided
    if (isset($_FILES['badge']) && $_FILES['badge']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['badge']['name'];
        $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($filetype, $allowed)) {
            // Generate unique filename
            $new_filename = uniqid('badge_') . '.' . $filetype;
            $upload_path = 'uploads/' . $new_filename;

            if (move_uploaded_file($_FILES['badge']['tmp_name'], $upload_path)) {
                // Get old badge filename to delete
                $stmt = $conn->prepare("SELECT badge FROM schools WHERE id = ?");
                $stmt->bind_param("i", $school_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $old_badge = $result->fetch_assoc()['badge'];

                // Delete old badge file if exists
                if ($old_badge && file_exists('uploads/' . $old_badge)) {
                    unlink('uploads/' . $old_badge);
                }

                // Add badge to update query
                $update_query .= ", badge = ?";
                $params[] = $new_filename;
                $types .= "s";
            }
        }
    }

    $update_query .= " WHERE id = ?";
    $params[] = $school_id;
    $types .= "i";

    // Prepare and execute the update
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        $_SESSION['notification'] = "School details have been successfully updated!";
    } else {
        $_SESSION['error'] = "Error updating school details: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();
    
    header("Location: super_admin_dashboard.php");
    exit();
}
