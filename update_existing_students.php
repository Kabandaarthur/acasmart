 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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


$user_school_id = $_SESSION['school_id'];
$message = '';

// Function to generate school abbreviation from school name
function generateSchoolAbbreviation($school_name) {
    $words = explode(' ', trim(strtolower($school_name)));
    $abbreviation = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            // Remove any special characters from the word
            $word = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if (!empty($word)) {
                $abbreviation .= $word[0];
            }
        }
    }
    return $abbreviation;
}

// Function to generate student email address
function generateStudentEmail($conn, $firstname, $lastname, $school_id) {
    // Get school name
    $school_query = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_query->bind_param("i", $school_id);
    $school_query->execute();
    $school_result = $school_query->get_result();
    $school_data = $school_result->fetch_assoc();
    $school_query->close();
    
    if (!$school_data) {
        return null;
    }
    
    $school_name = $school_data['school_name'];
    $school_short = generateSchoolAbbreviation($school_name);
    
    // Clean and format names
    $firstname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstname));
    $lastname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastname));
    
    // Generate base email
    $base_email = $firstname . "." . $lastname . "@students." . $school_short . ".ac.ug";
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT id FROM students WHERE student_email = ?");
    $stmt->bind_param("s", $base_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If email exists, add a number
    if ($result->num_rows > 0) {
        $counter = 1;
        do {
            $new_email = $firstname . "." . $lastname . $counter . "@students." . $school_short . ".ac.ug";
            $stmt->bind_param("s", $new_email);
            $stmt->execute();
            $result = $stmt->get_result();
            $counter++;
        } while ($result->num_rows > 0);
        return $new_email;
    }
    
    return $base_email;
}

// Function to generate student username
function generateStudentUsername($conn, $firstname, $lastname, $school_id) {
    // Get school name
    $school_query = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_query->bind_param("i", $school_id);
    $school_query->execute();
    $school_result = $school_query->get_result();
    $school_data = $school_result->fetch_assoc();
    $school_query->close();
    
    if (!$school_data) {
        return null;
    }
    
    $school_name = $school_data['school_name'];
    $school_short = generateSchoolAbbreviation($school_name);
    
    // Clean and format names
    $firstname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstname));
    $lastname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastname));
    
    // Generate base username
    $base_username = $firstname . "." . $lastname . "@" . $school_short;
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM students WHERE student_username = ?");
    $stmt->bind_param("s", $base_username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If username exists, add a number
    if ($result->num_rows > 0) {
        $counter = 1;
        do {
            $new_username = $firstname . "." . $lastname . $counter . "@" . $school_short;
            $stmt->bind_param("s", $new_username);
            $stmt->execute();
            $result = $stmt->get_result();
            $counter++;
        } while ($result->num_rows > 0);
        return $new_username;
    }
    
    return $base_username;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_students'])) {
    // Get all students without email/username
    $students_query = "SELECT id, firstname, lastname, admission_number FROM students 
                       WHERE school_id = ? AND (student_email IS NULL OR student_username IS NULL)";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("i", $user_school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $updated_count = 0;
    $errors = [];
    
    while ($student = $result->fetch_assoc()) {
        try {
            // Generate email and username
            $student_email = generateStudentEmail($conn, $student['firstname'], $student['lastname'], $user_school_id);
            $student_username = generateStudentUsername($conn, $student['firstname'], $student['lastname'], $user_school_id);
            $student_password = password_hash($student['admission_number'], PASSWORD_DEFAULT);
            
            // Update student record
            $update_query = "UPDATE students SET student_email = ?, student_username = ?, student_password = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sssi", $student_email, $student_username, $student_password, $student['id']);
            
            if ($update_stmt->execute()) {
                $updated_count++;
            } else {
                $errors[] = "Failed to update student: " . $student['firstname'] . " " . $student['lastname'];
            }
            $update_stmt->close();
            
        } catch (Exception $e) {
            $errors[] = "Error processing student: " . $student['firstname'] . " " . $student['lastname'] . " - " . $e->getMessage();
        }
    }
    
    if ($updated_count > 0) {
        $message = "Successfully updated $updated_count students with email addresses and usernames.";
        if (!empty($errors)) {
            $message .= "<br>Errors: " . implode(", ", $errors);
        }
    } else {
        $message = "No students were updated. " . implode(", ", $errors);
    }
}

// Get count of students without email/username
$count_query = "SELECT COUNT(*) as count FROM students 
                WHERE school_id = ? AND (student_email IS NULL OR student_username IS NULL)";
$stmt = $conn->prepare($count_query);
$stmt->bind_param("i", $user_school_id);
$stmt->execute();
$count_result = $stmt->get_result();
$students_without_accounts = $count_result->fetch_assoc()['count'];

// Get total students count
$total_query = "SELECT COUNT(*) as count FROM students WHERE school_id = ?";
$stmt = $conn->prepare($total_query);
$stmt->bind_param("i", $user_school_id);
$stmt->execute();
$total_result = $stmt->get_result();
$total_students = $total_result->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Existing Students - School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: #f3f4f6;
        }
        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }
        .card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="page-header">
        <div class="container mx-auto px-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <i class="fas fa-user-graduate text-white text-3xl"></i>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Update Existing Students</h1>
                        <p class="text-blue-100 text-sm">Generate email addresses and usernames for existing students</p>
                    </div>
                </div>
                <a href="school_admin_dashboard.php" class="flex items-center px-4 py-2 bg-white text-blue-600 rounded-lg hover:bg-blue-50 transition-colors duration-300">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4">
        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="card p-6">
                <div class="flex items-center space-x-4">
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-users text-blue-600 text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold">Total Students</h3>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $total_students; ?></p>
                    </div>
                </div>
            </div>

            <div class="card p-6">
                <div class="flex items-center space-x-4">
                    <div class="bg-yellow-100 p-3 rounded-full">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold">Need Accounts</h3>
                        <p class="text-3xl font-bold text-yellow-600"><?php echo $students_without_accounts; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Update Form -->
        <div class="card p-6">
            <div class="flex items-center space-x-4 mb-6">
                <i class="fas fa-sync-alt text-2xl text-blue-500"></i>
                <h2 class="text-xl font-semibold">Generate Student Accounts</h2>
            </div>

            <?php if ($students_without_accounts > 0): ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                <strong>Note:</strong> This will generate email addresses and usernames for <?php echo $students_without_accounts; ?> students who don't have accounts yet.
                                The default password will be their admission number.
                            </p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="">
                    <button type="submit" name="update_students" class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-colors duration-300 flex items-center">
                        <i class="fas fa-magic mr-2"></i>
                        Generate Student Accounts
                    </button>
                </form>
            <?php else: ?>
                <div class="bg-green-50 border-l-4 border-green-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle text-green-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700">
                                <strong>Great!</strong> All students already have email addresses and usernames generated.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Instructions -->
        <div class="card p-6 mt-8">
            <div class="flex items-center space-x-4 mb-6">
                <i class="fas fa-info-circle text-2xl text-blue-500"></i>
                <h2 class="text-xl font-semibold">How It Works</h2>
            </div>
            
            <div class="space-y-4">
                <div class="flex items-start space-x-3">
                    <div class="bg-blue-100 p-2 rounded-full">
                        <i class="fas fa-envelope text-blue-600"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold">Email Generation</h4>
                        <p class="text-gray-600">Student emails follow the format: firstname.lastname@students.schoolshortname.ac.ug</p>
                    </div>
                </div>
                
                <div class="flex items-start space-x-3">
                    <div class="bg-green-100 p-2 rounded-full">
                        <i class="fas fa-user text-green-600"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold">Username Generation</h4>
                        <p class="text-gray-600">Student usernames follow the format: firstname.lastname@schoolshortname</p>
                    </div>
                </div>
                
                <div class="flex items-start space-x-3">
                    <div class="bg-purple-100 p-2 rounded-full">
                        <i class="fas fa-key text-purple-600"></i>
                    </div>
                    <div>
                        <h4 class="font-semibold">Default Password</h4>
                        <p class="text-gray-600">The default password for all students is their admission number</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>