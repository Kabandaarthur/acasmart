 <?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
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

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendTeacherCredentials($username, $password, $email, $firstname, $lastname, $role) {
    $from = "schoolanalytics4@gmail.com";
    $fromName = "School Management System";
    $subject = "Your Teacher Account Details";

    $smtp = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'schoolanalytics4@gmail.com',
        'password' => 'bcerbdbqiwyearjy'
    ];

    $message = "
    <html>
    <body>
        <h2>Welcome to the School Management System</h2>
        <p>Dear $firstname $lastname,</p>
        <p>Your teacher account has been created successfully. Here are your login details:</p>
        <ul>
            <li><strong>Username:</strong> $username</li>
            <li><strong>Email:</strong> $email</li>
            <li><strong>Password:</strong> $password</li>
            <li><strong>Role:</strong> $role</li>
        </ul>
    </body>
    </html>
    ";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['username'];
        $mail->Password   = $smtp['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp['port'];

        $mail->setFrom($smtp['username'], $fromName);
        $mail->addAddress($email, "$firstname $lastname");

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

function sendMessageToTeachers($recipients, $subject, $messageBody) {
    $from = "schoolanalytics4@gmail.com";
    $fromName = "School Management System";

    $smtp = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'schoolanalytics4@gmail.com',
        'password' => 'bcerbdbqiwyearjy'
    ];

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['username'];
        $mail->Password   = $smtp['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp['port'];

        $mail->setFrom($smtp['username'], $fromName);

        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient['email'], $recipient['name']);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $messageBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

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

function generateUsername($firstname, $lastname, $school_name) {
    // Remove spaces and special characters from first and last names
    $firstname = trim(strtolower($firstname));
    $lastname = trim(strtolower($lastname));
    
    // Remove any special characters or spaces
    $firstname = preg_replace('/[^a-zA-Z0-9]/', '', $firstname);
    $lastname = preg_replace('/[^a-zA-Z0-9]/', '', $lastname);
    
    $school_abbr = generateSchoolAbbreviation($school_name);
    return $firstname . $lastname . '@' . $school_abbr;
}

$school_id = $_SESSION['school_id'];

// Get initial list of teachers
$stmt = $conn->prepare("SELECT * FROM users WHERE school_id = ? AND (role = 'teacher' OR role = 'admin')");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
$teachers = $result->fetch_all(MYSQLI_ASSOC);

// Get school name for username generation
$school_query = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
$school_query->bind_param("i", $school_id);
$school_query->execute();
$school_result = $school_query->get_result();
$school_data = $school_result->fetch_assoc();
$school_name = $school_data['school_name'];
$school_query->close();

// Handle all POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Handle message sending
    if (isset($_POST['send_message'])) {
        $subject = $_POST['message_subject'];
        $messageBody = $_POST['message_body'];
        $selectedTeachers = isset($_POST['selected_teachers']) ? $_POST['selected_teachers'] : [];
        
        if (empty($selectedTeachers)) {
            foreach ($teachers as $teacher) {
                $selectedTeachers[] = $teacher['user_id'];
            }
        }
        
        $recipients = [];
        foreach ($selectedTeachers as $teacherId) {
            $stmt = $conn->prepare("SELECT email, firstname, lastname FROM users WHERE user_id = ? AND school_id = ?");
            $stmt->bind_param("ii", $teacherId, $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($teacher = $result->fetch_assoc()) {
                $recipients[] = [
                    'email' => $teacher['email'],
                    'name' => $teacher['firstname'] . ' ' . $teacher['lastname']
                ];
            }
        }
        
        if (sendMessageToTeachers($recipients, $subject, $messageBody)) {
            $message = "Message sent successfully to selected teachers.";
        } else {
            $error = "Failed to send message. Please try again.";
        }
    }
    // Handle adding new teacher
    elseif (isset($_POST['add_teacher'])) {
        $firstname = $_POST['firstname'];
        $lastname = $_POST['lastname'];
        $username = $_POST['username'];
        $password_plain = $_POST['password'];
        $password = password_hash($password_plain, PASSWORD_DEFAULT);
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $role = $_POST['role'];

        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Teacher already registered with this username.";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, phone, firstname, lastname, school_id, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $username, $password, $email, $phone, $firstname, $lastname, $school_id, $role);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                if (sendTeacherCredentials($username, $password_plain, $email, $firstname, $lastname, $role)) {
                    $message = "Teacher added successfully and login credentials sent via email.";
                } else {
                    $message = "Teacher added successfully but failed to send email notification.";
                }
            } else {
                $error = "Error adding teacher.";
            }
        }
        $check_stmt->close();
    }
    // Handle updating teacher
    elseif (isset($_POST['update_teacher'])) {
        $user_id = $_POST['user_id'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $firstname = $_POST['firstname'];
        $lastname = $_POST['lastname'];
        $role = $_POST['role'];
    
        $stmt = $conn->prepare("UPDATE users SET email = ?, phone = ?, firstname = ?, lastname = ?, role = ? WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("sssssii", $email, $phone, $firstname, $lastname, $role, $user_id, $school_id);
        $stmt->execute();
    
        if ($stmt->affected_rows > 0) {
            $message = "Teacher updated successfully.";
        } else {
            $error = "Error updating teacher.";
        }
    }
    // Handle deleting teacher
    elseif (isset($_POST['delete_teacher'])) {
        $user_id = $_POST['user_id'];

        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND school_id = ?");
        $stmt->bind_param("ii", $user_id, $school_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $message = "Teacher deleted successfully.";
        } else {
            $error = "Error deleting teacher.";
        }
    }

    // Refresh teachers list after any action
    $stmt = $conn->prepare("SELECT * FROM users WHERE school_id = ? AND (role = 'teacher' OR role = 'admin')");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $teachers = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', Arial, sans-serif; }
        body {
            background-color: #f4f4f4;
            margin: 0;
            padding: 10px;
        }
        .container {
            max-width: 100%;
            margin: 0 auto;
            background-color: #fff;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1, h2 {
            color: #333;
            font-size: 1.5rem;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .message, .error { 
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .message {
            color: #4CAF50;
            background-color: #e8f5e9;
        }
        .error { 
            color: #f44336;
            background-color: #ffebee;
        }
        form { background-color: #f9f9f9; padding: 18px; border-radius: 10px; margin-bottom: 15px; box-shadow: 0 4px 14px rgba(0,0,0,0.06); }
        input[type="text"], input[type="password"], input[type="email"], select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            transition: border-color .2s, box-shadow .2s;
        }
        input[type="text"]:focus, input[type="password"]:focus, input[type="email"]:focus, select:focus { outline: none; border-color: #3498db; box-shadow: 0 0 0 3px rgba(52,152,219,0.15); }
        input[type="submit"], button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            width: 100%;
            margin-bottom: 5px;
            transition: transform .05s ease, box-shadow .2s ease;
        }
        input[type="submit"]:hover, button:hover { background-color: #45a049; box-shadow: 0 6px 16px rgba(76,175,80,0.2); }
        input[type="submit"]:active, button:active { transform: translateY(1px); }
        .btn-delete {
            background-color: #f44336;
        }
        .btn-delete:hover {
            background-color: #d32f2f;
        }
        .btn-update {
            background-color: #2196F3;
        }
        .btn-update:hover {
            background-color: #1976D2;
        }
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        @media screen and (max-width: 600px) {
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            tr {
                margin-bottom: 15px;
                border: 1px solid #ccc;
            }
            td {
                border: none;
                position: relative;
                padding-left: 50%;
            }
            td:before {
                position: absolute;
                top: 6px;
                left: 6px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                content: attr(data-label);
                font-weight: bold;
            }
        }
         .back-button {
            background-color: #3498db;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 15px;
            display: inline-block;
            text-decoration: none;
        }
        .back-button:hover {
            background-color: #2980b9;
        }
            .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            cursor: pointer;
            user-select: none;
        }
        textarea {
    resize: vertical;
    min-height: 150px;
    font-family: Arial, sans-serif;
}

.checkbox-container {
    margin-bottom: 5px;
}

.checkbox-container label {
    display: flex;
    align-items: center;
    gap: 5px;
}

button {
    background-color: #4CAF50;
    color: white;
    padding: 10px 15px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

button:hover {
    background-color: #45a049;
}
.section-button {
            background-color: #2c3e50;
            color: white;
            padding: 15px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            margin-bottom: 15px;
            width: 100%;
            text-align: left;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background-color 0.3s ease;
        }
        
        .section-button:hover {
            background-color: #34495e;
        }

        .collapsible-section {
            display: none;
            margin-bottom: 20px;
        }

        .section-icon {
            transition: transform 0.3s ease;
        }

        .section-button.active .section-icon {
            transform: rotate(180deg);
        }

        .fixed {
            position: fixed;
        }
        .inset-0 {
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
        }
        .z-50 {
            z-index: 50;
        }
        .hidden {
            display: none;
        }
        .flex {
            display: flex;
        }
        .items-center {
            align-items: center;
        }
        .justify-center {
            justify-content: center;
        }
        .min-h-screen {
            min-height: 100vh;
        }
        .bg-black {
            background-color: rgba(0, 0, 0, 0.5);
        }
        .bg-opacity-50 {
            background-opacity: 0.5;
        }
        .rounded-lg {
            border-radius: 0.5rem;
        }
        .space-x-4 > * + * {
            margin-left: 1rem;
        }
        .transition-colors {
            transition: background-color 0.3s ease;
        }
        #deleteTeacherDialog .bg-white {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        #deleteTeacherDialog button {
            width: auto;
            min-width: 100px;
        }
        #deleteTeacherDialog .text-4xl {
            font-size: 2rem;
        }
        #teacherToDelete {
            color: #4a5568;
            font-size: 0.875rem;
            margin: 0.5rem 0;
        }
        .teacher-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px 0;
        }

        .teacher-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
            transition: transform 0.3s ease;
        }

        .teacher-card:hover {
            transform: translateY(-5px);
        }

        .teacher-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }

        .teacher-avatar {
            width: 60px;
            height: 60px;
            background-color: #3498db;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            margin-right: 15px;
        }

        .teacher-info {
            flex-grow: 1;
        }

        .teacher-name {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin: 0;
        }

        .teacher-role {
            color: #7f8c8d;
            font-size: 14px;
        }

        .teacher-details {
            margin-top: 15px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            color: #34495e;
        }

        .detail-item i {
            width: 20px;
            margin-right: 10px;
            color: #3498db;
        }

        .teacher-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .teacher-actions button {
            flex: 1;
            padding: 8px;
            font-size: 14px;
        }

        .view-mode-toggle {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .view-mode-toggle button {
            padding: 10px 20px;
            background-color: #ecf0f1;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            color: #2c3e50;
            font-weight: bold;
        }

        .view-mode-toggle button.active {
            background-color: #3498db;
            color: white;
        }

        @media screen and (max-width: 768px) {
            .teacher-grid {
                grid-template-columns: 1fr;
            }
        }

        .action-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 220px));
            gap: 25px;
            margin-bottom: 30px;
            justify-content: center;
        }

        .action-card {
            aspect-ratio: 1;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            border-color: #4CAF50;
            background-color: #E8F5E9;
        }

        .action-card.active {
            background-color: #E8F5E9;
            border-color: #4CAF50;
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .action-card i {
            font-size: 2.8em;
            color: #3498db;
            margin-bottom: 12px;
        }

        .action-card:hover i,
        .action-card.active i {
            color: #4CAF50;
        }

        .action-card h3 {
            font-size: 1.2em;
            color: #2c3e50;
            margin: 10px 0;
            text-align: center;
        }

        .action-card:hover h3,
        .action-card.active h3 {
            color: #2E7D32;
        }

        .action-card p {
            font-size: 0.9em;
            color: #7f8c8d;
            text-align: center;
            margin: 0;
        }

        .action-card:hover p,
        .action-card.active p {
            color: #388E3C;
        }

        .action-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background-color: #4CAF50;
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .action-card:hover::after,
        .action-card.active::after {
            transform: scaleX(1);
        }

        @media screen and (max-width: 768px) {
            .action-cards {
                grid-template-columns: repeat(auto-fit, minmax(180px, 180px));
                gap: 20px;
            }

            .action-card {
                padding: 15px;
            }

            .action-card i {
                font-size: 2.3em;
            }

            .action-card h3 {
                font-size: 1.1em;
            }

            .action-card p {
                font-size: 0.85em;
            }
        }

        .content-section {
            display: none;
            margin-top: 30px;
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .content-section.active {
            display: block;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-header h2 {
            margin: 0;
            color: #2c3e50;
        }

        .close-section {
            background: none;
            border: none;
            color: #95a5a6;
            cursor: pointer;
            font-size: 1.5em;
            padding: 5px;
            width: auto;
        }

        .close-section:hover {
            color: #7f8c8d;
        }

        /* Add styles for the update form */
        #updateForm {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #updateForm h2 {
            color: #2c3e50;
            margin-bottom: 20px;
        }

        #updateForm .form-group {
            margin-bottom: 15px;
        }

        #updateForm label {
            display: block;
            margin-bottom: 5px;
            color: #34495e;
        }

        #updateForm input,
        #updateForm select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        #updateForm .button-group {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }

        #updateForm .button-group button {
            flex: 1;
        }
    </style>
</head>
<body>
<div class="container">
    <a href="school_admin_dashboard.php" class="back-button"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    
    <?php if (isset($message)): ?>
        <p class="message"><i class="fas fa-check-circle"></i> <?php echo $message; ?></p>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <p class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></p>
    <?php endif; ?>

    <!-- Action Cards -->
    <div class="action-cards">
        <div class="action-card" onclick="showSection('viewTeachersSection')">
            <i class="fas fa-users"></i>
            <h3>View Teachers</h3>
            <p>View and manage all teachers</p>
        </div>
        
        <div class="action-card" onclick="showSection('addTeacherSection')">
            <i class="fas fa-user-plus"></i>
            <h3>Add New Teacher</h3>
            <p>Register a new teacher</p>
        </div>
        
        <div class="action-card" onclick="showSection('messageSection')">
            <i class="fas fa-envelope"></i>
            <h3>Send Message</h3>
            <p>Send messages to teachers</p>
        </div>
    </div>

    <!-- View Teachers Section -->
    <div id="viewTeachersSection" class="content-section">
        <div class="section-header">
            <h2><i class="fas fa-users"></i> View Teachers</h2>
            <button class="close-section" onclick="closeSection('viewTeachersSection')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="view-mode-toggle">
            <button onclick="toggleViewMode('grid')" class="active" id="gridViewBtn">
                <i class="fas fa-th-large"></i> Grid View
            </button>
            <button onclick="toggleViewMode('table')" id="tableViewBtn">
                <i class="fas fa-list"></i> Table View
            </button>
        </div>

        <!-- Grid View -->
        <div id="gridView" class="teacher-grid">
            <?php foreach ($teachers as $teacher): ?>
                <div class="teacher-card">
                    <div class="teacher-header">
                        <div class="teacher-avatar">
                            <?php echo strtoupper(substr($teacher['firstname'], 0, 1) . substr($teacher['lastname'], 0, 1)); ?>
                        </div>
                        <div class="teacher-info">
                            <h3 class="teacher-name"><?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?></h3>
                            <span class="teacher-role"><?php echo ucfirst(htmlspecialchars($teacher['role'])); ?></span>
                        </div>
                    </div>
                    <div class="teacher-details">
                        <div class="detail-item">
                            <i class="fas fa-user"></i>
                            <span><?php echo htmlspecialchars($teacher['username']); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($teacher['email']); ?></span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-phone"></i>
                            <span><?php echo htmlspecialchars($teacher['phone']); ?></span>
                        </div>
                    </div>
                    <div class="teacher-actions">
                        <button class="btn-update" onclick="showUpdateForm(<?php echo htmlspecialchars(json_encode($teacher)); ?>)">
                            <i class="fas fa-edit"></i> Update
                        </button>
                        <button class="btn-delete" onclick="confirmDeleteTeacher(<?php echo $teacher['user_id']; ?>, '<?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Table View -->
        <div id="tableView" style="display: none;">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>First Name</th>
                        <th>Last Name</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td data-label="Username"><?php echo htmlspecialchars($teacher['username']); ?></td>
                            <td data-label="Email"><?php echo htmlspecialchars($teacher['email']); ?></td>
                            <td data-label="Phone"><?php echo htmlspecialchars($teacher['phone']); ?></td>
                            <td data-label="First Name"><?php echo htmlspecialchars($teacher['firstname']); ?></td>
                            <td data-label="Last Name"><?php echo htmlspecialchars($teacher['lastname']); ?></td>
                            <td data-label="Role"><?php echo htmlspecialchars($teacher['role']); ?></td>
                            <td data-label="Actions" class="action-buttons">
                                <button class="btn-update" onclick="showUpdateForm(<?php echo htmlspecialchars(json_encode($teacher)); ?>)">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                <button class="btn-delete" onclick="confirmDeleteTeacher(<?php echo $teacher['user_id']; ?>, '<?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Teacher Section -->
    <div id="addTeacherSection" class="content-section">
        <div class="section-header">
            <h2><i class="fas fa-user-plus"></i> Add New Teacher</h2>
            <button class="close-section" onclick="closeSection('addTeacherSection')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post">
            <input type="text" name="firstname" id="firstname" required placeholder="First Name">
            <input type="text" name="lastname" id="lastname" required placeholder="Last Name">
            <div class="username-preview alert alert-info" style="display: none;"></div>
            <div class="password-container">
                <input type="password" name="password" id="passwordInput" required placeholder="Password">
                <span class="toggle-password" onclick="togglePasswordVisibility()">👁️</span>
            </div>
            <input type="email" name="email" required placeholder="Email">
            <input type="text" name="phone" required placeholder="Phone Number">
            <select name="role" required>
                <option value="teacher">Teacher</option>
                <option value="admin">Admin</option>
            </select>
            <input type="hidden" name="username" id="usernameInput">
            <input type="submit" name="add_teacher" value="Add Teacher">
        </form>
    </div>

    <!-- Send Message Section -->
    <div id="messageSection" class="content-section">
        <div class="section-header">
            <h2><i class="fas fa-envelope"></i> Send Message to Teachers</h2>
            <button class="close-section" onclick="closeSection('messageSection')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" id="messageForm">
            <div style="margin-bottom: 15px;">
                <label><strong>Select Teachers:</strong></label>
                <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-top: 5px;">
                    <?php foreach ($teachers as $teacher): ?>
                    <div style="margin-bottom: 5px;">
                        <label>
                            <input type="checkbox" name="selected_teachers[]" value="<?php echo $teacher['user_id']; ?>">
                            <?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname'] ); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="text" name="message_subject" required placeholder="Message Subject">
            <textarea name="message_body" required placeholder="Message Content" style="width: 100%; height: 150px; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="selectAllTeachers()" style="flex: 1;">Select All Teachers</button>
                <input type="submit" name="send_message" value="Send Message" style="flex: 1;">
            </div>
        </form>
    </div>

    <div id="updateForm" style="display: none;">
        <h2><i class="fas fa-user-edit"></i> Update Teacher</h2>
        <form method="post">
            <input type="hidden" id="update_user_id" name="user_id">
            <input type="email" id="update_email" name="email" required placeholder="Email">
            <input type="text" id="update_phone" name="phone" required placeholder="Phone Number">
            <input type="text" id="update_firstname" name="firstname" required placeholder="First Name">
            <input type="text" id="update_lastname" name="lastname" required placeholder="Last Name">
            <select id="update_role" name="role" required>
                <option value="teacher">Teacher</option>
                <option value="admin">Admin</option>
            </select>
            <input type="submit" name="update_teacher" value="Update Teacher">
        </form>
    </div>
</div>

<!-- First, add this HTML for the delete teacher dialog (add it before the closing body tag) -->
<div id="deleteTeacherDialog" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4">
            <div class="text-center">
                <i class="fas fa-user-times text-red-500 text-4xl mb-4"></i>
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete Teacher</h3>
                <p class="text-gray-600 mb-2">Are you sure you want to delete this teacher?</p>
                <p id="teacherToDelete" class="text-sm font-semibold text-gray-800 mb-6"></p>
                
                <div class="flex justify-center space-x-4">
                    <button type="button" onclick="closeDeleteTeacherDialog()" 
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors">
                        Cancel
                    </button>
                    <form id="deleteTeacherForm" method="POST" class="inline">
                        <input type="hidden" name="delete_teacher" value="1">
                        <input type="hidden" id="deleteTeacherId" name="user_id" value="">
                        <button type="submit" 
                                class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors">
                            Delete Teacher
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function showUpdateForm(teacher) {
    document.getElementById('updateForm').style.display = 'block';
    document.getElementById('update_user_id').value = teacher.user_id;
    document.getElementById('update_phone').value = teacher.phone;
    document.getElementById('update_email').value = teacher.email;
    document.getElementById('update_firstname').value = teacher.firstname;
    document.getElementById('update_lastname').value = teacher.lastname;
    document.getElementById('update_role').value = teacher.role;
    document.getElementById('updateForm').scrollIntoView({ behavior: 'smooth' });
}

function togglePasswordVisibility() {
    const passwordInput = document.getElementById('passwordInput');
    passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
}

function selectAllTeachers() {
    const checkboxes = document.querySelectorAll('input[name="selected_teachers[]"]');
    const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);
    checkboxes.forEach(checkbox => {
        checkbox.checked = !allChecked;
    });
}

function confirmDeleteTeacher(teacherId, teacherName) {
    document.getElementById('deleteTeacherId').value = teacherId;
    document.getElementById('teacherToDelete').textContent = `Teacher: ${teacherName}`;
    document.getElementById('deleteTeacherDialog').classList.remove('hidden');
}

function closeDeleteTeacherDialog() {
    document.getElementById('deleteTeacherDialog').classList.add('hidden');
}

// Close dialog when clicking outside
document.getElementById('deleteTeacherDialog').addEventListener('click', function(event) {
    if (event.target === this) {
        closeDeleteTeacherDialog();
    }
});

// Add escape key listener to close dialog
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('deleteTeacherDialog').classList.contains('hidden')) {
        closeDeleteTeacherDialog();
    }
});

// Add username generation functionality
document.addEventListener('DOMContentLoaded', function() {
    const firstname = document.getElementById('firstname');
    const lastname = document.getElementById('lastname');
    const usernameInput = document.getElementById('usernameInput');
    const usernamePreview = document.querySelector('.username-preview');
    const schoolName = <?php echo json_encode($school_name); ?>;

    function generateSchoolAbbreviation(schoolName) {
        return schoolName.toLowerCase()
            .split(' ')
            .filter(word => word.length > 0)
            .map(word => word.replace(/[^a-zA-Z0-9]/g, ''))
            .map(word => word[0] || '')
            .join('');
    }

    function updateUsername() {
        if (firstname.value && lastname.value) {
            const first = firstname.value.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');
            const last = lastname.value.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');
            const abbr = generateSchoolAbbreviation(schoolName);
            const username = `${first}${last}@${abbr}`;
            usernameInput.value = username;
            usernamePreview.textContent = `Generated username: ${username}`;
            usernamePreview.style.display = 'block';
        } else {
            usernamePreview.style.display = 'none';
        }
    }

    firstname.addEventListener('input', updateUsername);
    lastname.addEventListener('input', updateUsername);
});

function toggleViewMode(mode) {
    const gridView = document.getElementById('gridView');
    const tableView = document.getElementById('tableView');
    const gridViewBtn = document.getElementById('gridViewBtn');
    const tableViewBtn = document.getElementById('tableViewBtn');

    if (mode === 'grid') {
        gridView.style.display = 'grid';
        tableView.style.display = 'none';
        gridViewBtn.classList.add('active');
        tableViewBtn.classList.remove('active');
    } else {
        gridView.style.display = 'none';
        tableView.style.display = 'block';
        gridViewBtn.classList.remove('active');
        tableViewBtn.classList.add('active');
    }
}

function showSection(sectionId) {
    // Remove active class from all cards
    document.querySelectorAll('.action-card').forEach(card => {
        card.classList.remove('active');
    });
    
    // Add active class to the clicked card
    const clickedCard = document.querySelector(`[onclick="showSection('${sectionId}')"]`);
    if (clickedCard) {
        clickedCard.classList.add('active');
    }
    
    // Hide all sections first
    document.querySelectorAll('.content-section').forEach(section => {
        section.classList.remove('active');
    });
    
    // Show the selected section
    const section = document.getElementById(sectionId);
    section.classList.add('active');
    section.scrollIntoView({ behavior: 'smooth' });
}

function closeSection(sectionId) {
    document.getElementById(sectionId).classList.remove('active');
    // Remove active class from the corresponding card
    document.querySelectorAll('.action-card').forEach(card => {
        card.classList.remove('active');
    });
}
</script>
</body>
</html