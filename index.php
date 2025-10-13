<?php
session_start();

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

// Email configuration
$smtp_host = 'smtp.gmail.com'; // Change to your SMTP server
$smtp_port = 587;
$smtp_username = 'schoolanalytics4@gmail.com'; // Change to your email
$smtp_password = 'bcerbdbqiwyearjy'; // Change to your app password
$from_email = 'schoolanalytics4@gmail.com'; // Change to your email
$from_name = 'Acasmart Password Reset';

// PHPMailer setup
$phpmailer_available = false;
if (file_exists('PHPMailer/src/PHPMailer.php')) {
    require_once 'PHPMailer/src/Exception.php';
    require_once 'PHPMailer/src/PHPMailer.php';
    require_once 'PHPMailer/src/SMTP.php';
    $phpmailer_available = true;
}

$error = "";
$success = "";
$show_password_step = false;
$username_for_retry = "";

function getDbConnection() {
    global $db_host, $db_user, $db_pass, $db_name;
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        throw new Exception("Connection Error:" . $conn->connect_error);
    }
    return $conn;
}

function sendPasswordResetEmail($to_email, $to_name, $verification_code) {
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $from_email, $from_name, $phpmailer_available;
    
    // Check if PHPMailer is available
    if ($phpmailer_available && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_username;
            $mail->Password   = $smtp_password;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp_port;
            
            // Recipients
            $mail->setFrom($from_email, $from_name);
            $mail->addAddress($to_email, $to_name);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = "Password Reset Verification Code - Acasmart";
            $mail->Body = "
            <html>
            <head>
                <title>Password Reset Verification Code</title>
            </head>
            <body>
                <h2>Password Reset Request</h2>
                <p>Hello " . htmlspecialchars($to_name) . ",</p>
                <p>You have requested to reset your password for your Acasmart account.</p>
                <p>Your verification code is: <strong style='font-size: 24px; color: #1a73e8;'>" . $verification_code . "</strong></p>
                <p>This code will expire in 15 minutes.</p>
                <p>If you did not request this password reset, please ignore this email.</p>
                <br>
                <p>Best regards,<br>Acasmart Team</p>
            </body>
            </html>
            ";
            
            $mail->send();
            error_log("Email sent successfully to: " . $to_email);
            return true;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo);
            return false;
        }
    } else {
        // Fallback to basic mail() function if PHPMailer not available
        $subject = "Password Reset Verification Code - Acasmart";
        $message = "
        <html>
        <head>
            <title>Password Reset Verification Code</title>
        </head>
        <body>
            <h2>Password Reset Request</h2>
            <p>Hello " . htmlspecialchars($to_name) . ",</p>
            <p>You have requested to reset your password for your Acasmart account.</p>
            <p>Your verification code is: <strong style='font-size: 24px; color: #1a73e8;'>" . $verification_code . "</strong></p>
            <p>This code will expire in 15 minutes.</p>
            <p>If you did not request this password reset, please ignore this email.</p>
            <br>
            <p>Best regards,<br>Acasmart Team</p>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $from_name . " <" . $from_email . ">" . "\r\n";
        
        error_log("Using fallback mail() function");
        return mail($to_email, $subject, $message, $headers);
    }
}

// Handle username verification
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'verify_username') {
    header('Content-Type: application/json');
    
    try {
        $username = $_POST['username'];
        $conn = getDbConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                u.user_id, u.username, u.password, u.email, u.phone, u.firstname, u.lastname, 
                u.school_id, u.role, u.created_at, u.updated_at,
                s.status as school_status, s.school_name, s.motto, s.badge,
                CONCAT(u.firstname, ' ', u.lastname) as full_name 
            FROM users u 
            LEFT JOIN schools s ON u.school_id = s.id 
            WHERE u.username = ?
            UNION
            SELECT 
                st.id as user_id, st.student_email as username, st.student_password as password,
                st.student_email as email, NULL as phone, st.firstname, st.lastname, st.school_id,
                'student' as role, st.created_at, st.updated_at,
                s.status as school_status, s.school_name, s.motto, s.badge,
                CONCAT(st.firstname, ' ', st.lastname) as full_name
            FROM students st
            LEFT JOIN schools s ON st.school_id = s.id
            WHERE st.student_email = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $stmt->bind_param("ss", $username, $username);
        
        if (!$stmt->execute()) {
            throw new Exception("Query execution failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check if school badge exists and is valid
            $badge_path = null;
            if (!empty($user['badge']) && file_exists('uploads/' . $user['badge'])) {
                $badge_path = 'uploads/' . $user['badge'];
            }
            
            echo json_encode([
                'valid' => true,
                'school_name' => $user['school_name'],
                'school_motto' => $user['motto'],
                'school_badge' => $badge_path,
                'full_name' => $user['full_name']
            ]);
        } else {
            echo json_encode(['valid' => false]);
        }
        
        $stmt->close();
        $conn->close();
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'valid' => false,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle forgot password - send verification code
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'forgot_password') {
    header('Content-Type: application/json');
    
    try {
        $username = $_POST['username'];
        $email = $_POST['email'];
        
        // Debug: Log received data
        error_log("Forgot password request - Username: " . $username . ", Email: " . $email);
        
        $conn = getDbConnection();
        
        // Check if username and email match
        $stmt = $conn->prepare("
            SELECT u.user_id, u.email, u.firstname, u.lastname, u.username
            FROM users u 
            WHERE u.username = ? AND u.email = ?
            UNION
            SELECT st.id as user_id, st.student_email as email, st.firstname, st.lastname, st.student_email as username
            FROM students st
            WHERE st.student_email = ? AND st.student_email = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $stmt->bind_param("ssss", $username, $email, $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Generate 6-digit verification code
            $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            
            // Store verification code in database (create table if not exists)
            $create_table_sql = "
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    username VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    verification_code VARCHAR(6) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ";
            $conn->query($create_table_sql);
            
            // Insert verification code
            $insert_stmt = $conn->prepare("
                INSERT INTO password_reset_tokens (user_id, username, email, verification_code, expires_at) 
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
            ");
            $insert_stmt->bind_param("isss", $user['user_id'], $user['username'], $user['email'], $verification_code);
            $insert_stmt->execute();
            
            // Send email
            $email_sent = sendPasswordResetEmail($user['email'], $user['firstname'] . ' ' . $user['lastname'], $verification_code);
            
            if ($email_sent) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Verification code sent to your email address',
                    'user_id' => $user['user_id']
                ]);
            } else {
                // For debugging - include the verification code in the response
                echo json_encode([
                    'success' => true, // Set to true for testing
                    'message' => 'Email sending failed, but here is your verification code for testing: ' . $verification_code,
                    'user_id' => $user['user_id'],
                    'verification_code' => $verification_code // For testing
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Username and email do not match our records'
            ]);
        }
        
        $stmt->close();
        $conn->close();
        exit;
        
    } catch (Exception $e) {
        error_log("Forgot password error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred. Please try again later.'
        ]);
        exit;
    } catch (Error $e) {
        error_log("Forgot password fatal error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred. Please try again later.'
        ]);
        exit;
    }
}

// Handle verification code verification
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'verify_code') {
    header('Content-Type: application/json');
    
    try {
        $user_id = $_POST['user_id'];
        $verification_code = $_POST['verification_code'];
        $conn = getDbConnection();
        
        // Verify the code
        $stmt = $conn->prepare("
            SELECT id FROM password_reset_tokens 
            WHERE user_id = ? AND verification_code = ? AND expires_at > NOW() AND used = 0
        ");
        $stmt->bind_param("is", $user_id, $verification_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            echo json_encode([
                'success' => true,
                'message' => 'Code verified successfully!'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or expired verification code'
            ]);
        }
        
        $stmt->close();
        $conn->close();
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle password reset with verification code
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    header('Content-Type: application/json');
    
    try {
        $user_id = $_POST['user_id'];
        $verification_code = $_POST['verification_code'];
        $new_password = $_POST['new_password'];
        $conn = getDbConnection();
        
        // Verify the code
        $stmt = $conn->prepare("
            SELECT id FROM password_reset_tokens 
            WHERE user_id = ? AND verification_code = ? AND expires_at > NOW() AND used = 0
        ");
        $stmt->bind_param("is", $user_id, $verification_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $token = $result->fetch_assoc();
            
            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password in users table
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            $update_stmt->execute();
            
            // Also update in students table if it's a student
            $update_student_stmt = $conn->prepare("UPDATE students SET student_password = ? WHERE id = ?");
            $update_student_stmt->bind_param("si", $hashed_password, $user_id);
            $update_student_stmt->execute();
            
            // Mark token as used
            $mark_used_stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE id = ?");
            $mark_used_stmt->bind_param("i", $token['id']);
            $mark_used_stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Password reset successfully! You can now login with your new password.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or expired verification code'
            ]);
        }
        
        $stmt->close();
        $conn->close();
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Handle login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'login') {
    try {
        $username = $_POST['username'];
        $password = $_POST['password'];
        $show_password_step = true;
        $username_for_retry = $username;

        $conn = getDbConnection();
        
        $stmt = $conn->prepare("
            SELECT 
                u.user_id, u.username, u.password, u.email, u.phone, u.firstname, u.lastname, 
                u.school_id, u.role, u.created_at, u.updated_at,
                s.status as school_status, s.school_name, s.motto, s.badge,
                CONCAT(u.firstname, ' ', u.lastname) as full_name 
            FROM users u 
            LEFT JOIN schools s ON u.school_id = s.id 
            WHERE u.username = ?
            UNION
            SELECT 
                st.id as user_id, st.student_email as username, st.student_password as password,
                st.student_email as email, NULL as phone, st.firstname, st.lastname, st.school_id,
                'student' as role, st.created_at, st.updated_at,
                s.status as school_status, s.school_name, s.motto, s.badge,
                CONCAT(st.firstname, ' ', st.lastname) as full_name
            FROM students st
            LEFT JOIN schools s ON st.school_id = s.id
            WHERE st.student_email = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Unable to verify credentials");
        }

        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                if ($user['role'] === 'super_admin' || 
                    ($user['school_status'] === 'active' && $user['school_id'] !== null)) {
                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['school_id'] = $user['school_id'] ?? null;

                    $stmt->close();
                    $conn->close();

                    switch ($user['role']) {
                        case 'super_admin':
                            header("Location: super_admin_dashboard.php");
                            break;
                        case 'admin':
                            header("Location: school_admin_dashboard.php?school_id=" . $user['school_id']);
                            break;
                        case 'teacher':
                            header("Location: teacher_dashboard.php?school_id=" . $user['school_id']);
                            break;
                        case 'bursar':
                            header("Location: bursar_dashboard.php");
                            break;
                        case 'student':
                            header("Location: student_dashboard.php");
                            break;
                        default:
                            $error = "Invalid user role";
                    }
                    exit();
                } else {
                    $error = "Access denied. Please contact system administrator.";
                }
            } else {
                $error = "Incorrect password. Please try again.";
                $show_password_step = true; // Keep user on password step
                $username_for_retry = $username; // Preserve username
            }
        } else {
            $error = "Invalid username";
            $show_password_step = false;
        }
        
        $stmt->close();
        $conn->close();

    } catch (Exception $e) {
        $error = "An error occurred. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="apple-touch-icon" sizes="180x180" href="favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon_io/favicon-16x16.png">
    <link rel="manifest" href="favicon_io/site.webmanifest">
    <meta name="description" content="Acasmart - A comprehensive solution for managing school operations eg. ReportCard Generation, Summary of results for each exam and activity.">

    <!-- WhatsApp and Open Graph Meta Tags -->
    <meta property="og:title" content="Acasmart - School Management System">
    <meta property="og:description" content="A comprehensive solution for managing school operations including ReportCard Generation and exam results.">
    <meta property="og:image" content="https://schoolanalysis.kesug.com/assets/images/logo.jpg">
    <meta property="og:image:width" content="400">
    <meta property="og:image:height" content="400">
    <meta property="og:url" content="https://schoolanalysis.kesug.com/index.php">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Acasmart">

    <!-- Twitter Card Tags -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Acasmart - School Management System">
    <meta name="twitter:description" content="A comprehensive solution for managing school operations including ReportCard Generation and exam results.">
    <meta name="twitter:image" content="https://schoolanalysis.kesug.com/assets/images/logo.jpg">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <title>Acasmart - Your School Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #1a73e8;
            --secondary-color: #4285f4;
            --text-color: #333;
            --error-color: #d93025;
            --success-color: #0f9d58;
            --bg-gradient: linear-gradient(135deg, #1a73e8 0%, #0d47a1 100%);
        }

        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: var(--bg-gradient);
            position: relative;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* Layout */
        .main-content {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
            position: relative;
            z-index: 1;
            min-height: 100vh;
            width: 100%;
            box-sizing: border-box;
        }

        .container {
            background-color: rgba(255, 255, 255, 0.98);
            padding: 2rem;
            border-radius: 24px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 420px;
            position: relative;
            margin: 0 auto;
            box-sizing: border-box;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
            padding: 0;
        }

        .logo-img {
            max-width: 180px;
            height: auto;
            margin: 0 auto;
            display: block;
        }

        /* Instructions Card */
        .instructions-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .card-icon {
            font-size: 1.25rem;
            color: var(--primary-color);
            background: white;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.1);
            flex-shrink: 0;
        }

        .card-content h3 {
            font-size: 1rem;
            color: #2c3e50;
            margin: 0 0 0.25rem 0;
            font-weight: 600;
        }

        .card-content p {
            color: #666;
            font-size: 0.85rem;
            line-height: 1.4;
            margin: 0;
        }

        /* Form Elements */
        .input-group {
            margin-bottom: 1.25rem;
        }

        .input-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-color);
            font-weight: 500;
            width: 100%;
        }

        .input-label span {
            color: var(--text-color);
        }

        .input-label a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            white-space: nowrap;
        }

        .input-label a:hover {
            color: #0d47a1;
            background-color: rgba(26, 115, 232, 0.1);
        }

        .input-wrapper {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
        }

        .input-wrapper input {
            width: 100%;
            padding: 0.875rem 2.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
            color: #333;
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
            height: 48px;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            transition: all 0.3s ease;
            z-index: 2;
            font-size: 0.9rem;
            pointer-events: none;
        }

        .input-wrapper i.hidden {
            opacity: 0;
            visibility: hidden;
        }

        /* School Badge */
        .school-badge {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            display: none;
            background-color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            z-index: 3;
            overflow: hidden;
            pointer-events: all;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .school-badge.active {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
        }

        .school-badge img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .school-name-tooltip {
            position: absolute;
            left: 3.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            display: none;
            white-space: nowrap;
            z-index: 4;
            pointer-events: none;
        }

        .school-badge:hover {
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .school-badge:hover + .school-name-tooltip {
            display: block;
        }

        .input-wrapper input.has-badge {
            padding-left: 3.75rem;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 0.875rem;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-family: 'Poppins', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, #0d47a1 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.2);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
            margin-top: 0.75rem;
        }

        .btn-secondary:hover {
            background: rgba(26, 115, 232, 0.1);
        }

        .btn-link {
            background: transparent;
            color: #666;
            border: none;
            text-decoration: none;
            font-size: 0.9rem;
            margin-top: 0.75rem;
            padding: 0.5rem;
            width: auto;
            display: inline-block;
        }

        .btn-link:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #666;
            font-size: 0.85rem;
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-step {
            display: none;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }

        .login-step.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
            animation: fadeIn 0.3s ease-out;
        }

        .welcome-user {
            animation: fadeIn 0.3s ease-out;
        }

        /* Responsive Styles */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .container {
                padding: 2rem;
                margin: 1rem;
                max-width: none;
            }

            .logo-img {
                max-width: 160px;
            }
        }

        @media (max-width: 480px) {
            .container {
                margin: 1rem;
                padding: 1.5rem;
                max-width: calc(100% - 2rem);
            }

            .input-wrapper input {
                font-size: 0.9rem;
                height: 44px;
                padding: 0.875rem 2.5rem;
            }

            .school-badge {
                width: 28px;
                height: 28px;
                left: 0.875rem;
            }

            .input-wrapper input.has-badge {
                padding-left: 3.25rem;
            }

            .message {
                padding: 0.875rem;
                font-size: 0.9rem;
                margin-bottom: 0.875rem;
            }

            .logo-img {
                max-width: 140px;
            }
        }

        /* Message Styles */
        .message {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
            font-weight: 500;
            opacity: 1;
            transition: all 0.3s ease;
            animation: slideDown 0.3s ease-out;
        }

        .message.success {
            background-color: rgba(15, 157, 88, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(15, 157, 88, 0.2);
        }

        .message.error {
            background-color: rgba(217, 48, 37, 0.1);
            color: var(--error-color);
            border: 1px solid rgba(217, 48, 37, 0.2);
        }

        .message i {
            font-size: 1.25rem;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
            gap: 0.5rem;
        }

        .step {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #e0e0e0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .step.active {
            background-color: var(--primary-color);
            transform: scale(1.2);
        }

        .step.completed {
            background-color: var(--success-color);
        }
    </style>
</head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-HJL2CJ5RYR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-HJL2CJ5RYR');
</script>
<body>
    <!-- Main Content Wrapper -->
    <div class="main-content">
        <div class="message-container" id="messageContainer"></div>
        <div class="container">
            <!-- Logo Section -->
            <div class="logo-section">
                <!-- Replace src with your actual logo path -->
                <img src="assets/images/logo.jpg" alt="School Management System Logo" class="logo-img" id="schoolLogo">
            </div>
            <!-- Instructions Card -->
            <div class="instructions-card">
                <div class="card-icon">
                    <i class="fas fa-info-circle"></i>
                </div>
                <div class="card-content">
                    <h3>Get Started</h3>
                    <p>Enter your username and password to access your account</p>
                </div>
            </div>

            <?php
            if (!empty($error)) {
                echo "<div class='error'><i class='fas fa-exclamation-circle'></i>$error</div>";
            }
            if (!empty($success)) {
                echo "<div class='success'><i class='fas fa-check-circle'></i>$success</div>";
            }
            ?>

            <div class="step-indicator">
                <div class="step active" data-step="1"></div>
                <div class="step" data-step="2"></div>
            </div>

            <div id="loginForm">
                <!-- Step 1: Username -->
                <div class="login-step active" id="step1">
                    <form id="usernameForm">
                        <div class="input-group">
                            <div class="input-label">
                                <span>Username</span>
                                <a href="https://wa.link/djjwmv">Need Help?</a>
                            </div>
                            <div class="input-wrapper">
                                <input type="text" name="username" id="username" placeholder="Enter your username" required>
                                <i class="fas fa-user"></i>
                                <div class="school-badge">
                                    <img src="" alt="School Badge">
                                </div>
                                <div class="school-name-tooltip"></div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Continue <i class="fas fa-arrow-right"></i></button>
                    </form>
                </div>

                <!-- Step 2: Password -->
                <div class="login-step" id="step2">
                    <div class="welcome-user" id="welcomeUser">
                        <?php if ($show_password_step): 
                            try {
                                $conn = getDbConnection();
                                // Check both users and students tables for the username
                                $stmt = $conn->prepare("
                                    SELECT CONCAT(firstname, ' ', lastname) as full_name, 'user' as type FROM users WHERE username = ?
                                    UNION
                                    SELECT CONCAT(firstname, ' ', lastname) as full_name, 'student' as type FROM students WHERE student_email = ?
                                ");
                                if ($stmt) {
                                    $stmt->bind_param("ss", $username_for_retry, $username_for_retry);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    $user = $result->fetch_assoc();
                                    if ($user && $user['full_name']) {
                                        $prefix = $user['type'] === 'student' ? 'Student' : '';
                                        echo $prefix . " " . htmlspecialchars($user['full_name']);
                                    } else {
                                        echo "Welcome back, User";
                                    }
                                    $stmt->close();
                                } else {
                                    echo "Welcome back, User";
                                }
                                $conn->close();
                            } catch (Exception $e) {
                                echo "Welcome back, User";
                            }
                        endif; ?>
                    </div>
                    <form method="POST" action="" id="loginForm">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="username" id="hiddenUsername" value="<?php echo htmlspecialchars($username_for_retry); ?>">
                        <div class="input-group">
                            <div class="input-label">Password</div>
                            <div class="input-wrapper">
                                <input type="password" name="password" id="passwordInput" placeholder="Enter your password" required autofocus>
                                <i class="fas fa-lock"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Login <i class="fas fa-sign-in-alt"></i></button>
                        <button type="button" class="btn btn-secondary" id="backToUsernameBtn">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn btn-link" id="forgotPasswordBtn">
                            <i class="fas fa-key"></i> Forgot Password?
                        </button>
                    </form>
                </div>

                <!-- Step 3: Forgot Password - Email -->
                <div class="login-step" id="step3">
                    <div class="welcome-user">
                        <p>Enter the email address associated with your account</p>
                    </div>
                    <form id="forgotPasswordForm">
                        <div class="input-group">
                            <div class="input-label">Email Address</div>
                            <div class="input-wrapper">
                                <input type="email" name="email" id="emailInput" placeholder="Enter your email address" required>
                                <i class="fas fa-envelope"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Send Verification Code <i class="fas fa-paper-plane"></i></button>
                        <button type="button" class="btn btn-secondary" id="backToLoginBtn">
                            <i class="fas fa-arrow-left"></i> Back to Login
                        </button>
                    </form>
                </div>

                <!-- Step 4: Forgot Password - Verification Code -->
                <div class="login-step" id="step4">
                    <div class="welcome-user">
                        <h3>Enter Verification Code</h3>
                        <p>We've sent a 6-digit code to your email address</p>
                    </div>
                    <form id="verificationCodeForm">
                        <input type="hidden" id="resetUserId" name="user_id">
                        <div class="input-group">
                            <div class="input-label">Verification Code</div>
                            <div class="input-wrapper">
                                <input type="text" name="verification_code" id="verificationCodeInput" placeholder="Enter 6-digit code" maxlength="6" required>
                                <i class="fas fa-shield-alt"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Verify Code <i class="fas fa-check"></i></button>
                        <button type="button" class="btn btn-secondary" id="backToEmailBtn">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </form>
                </div>

                <!-- Step 5: Forgot Password - New Password -->
                <div class="login-step" id="step5">
                    <div class="welcome-user">
                        <h3>Set New Password</h3>
                        <p>Code verified! Please enter your new password</p>
                    </div>
                    <form id="newPasswordForm">
                        <input type="hidden" id="verifiedUserId" name="user_id">
                        <input type="hidden" id="verifiedCode" name="verification_code">
                        <div class="input-group">
                            <div class="input-label">New Password</div>
                            <div class="input-wrapper">
                                <input type="password" name="new_password" id="newPasswordInput" placeholder="Enter new password" required>
                                <i class="fas fa-lock"></i>
                            </div>
                        </div>
                        <div class="input-group">
                            <div class="input-label">Confirm New Password</div>
                            <div class="input-wrapper">
                                <input type="password" name="confirm_password" id="confirmPasswordInput" placeholder="Confirm new password" required>
                                <i class="fas fa-lock"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Reset Password <i class="fas fa-check"></i></button>
                        <button type="button" class="btn btn-secondary" id="backToVerificationBtn">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </form>
                </div>
            </div>

            <!-- Footer -->
            <div class="login-footer">
                <p>© <?php echo date('Y'); ?> Acasmart. All rights reserved.</p>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Service Worker Registration
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/School_Management_System/sw.js')
                        .then(registration => {
                            console.log('ServiceWorker registration successful');
                        })
                        .catch(err => {
                            console.log('ServiceWorker registration failed: ', err);
                        });
                });
            }

            // Login form handling
            const usernameForm = document.getElementById('usernameForm');
            const usernameInput = document.getElementById('username');
            const schoolBadge = document.querySelector('.school-badge');
            const schoolBadgeImg = schoolBadge?.querySelector('img');
            const schoolNameTooltip = document.querySelector('.school-name-tooltip');
            const usernameIcon = document.querySelector('.input-group .fa-user');
            const step1 = document.getElementById('step1');
            const step2 = document.getElementById('step2');

            let typingTimer;
            const doneTypingInterval = 500;

            function resetBadge() {
                if (schoolBadge) {
                    schoolBadge.classList.remove('active');
                    schoolBadgeImg.src = '';
                    schoolNameTooltip.textContent = '';
                    usernameIcon.classList.remove('hidden');
                    usernameInput.classList.remove('has-badge');
                }
            }

            function showStep(step) {
                const steps = document.querySelectorAll('.login-step');
                steps.forEach(el => {
                    el.classList.remove('active');
                    el.style.display = 'none';
                });

                // Show/hide step indicator based on current step
                const stepIndicator = document.querySelector('.step-indicator');
                if (stepIndicator) {
                    if (step <= 2) {
                        stepIndicator.style.display = 'flex';
                        // Update step indicator (only for steps 1 and 2)
                        const stepIndicators = document.querySelectorAll('.step');
                        stepIndicators.forEach((indicator, index) => {
                            indicator.classList.remove('active', 'completed');
                            if (index + 1 < step) {
                                indicator.classList.add('completed');
                            } else if (index + 1 === step) {
                                indicator.classList.add('active');
                            }
                        });
                    } else {
                        stepIndicator.style.display = 'none';
                    }
                }

                const targetStep = document.getElementById('step' + step);
                if (targetStep) {
                    targetStep.style.display = 'block';
                    setTimeout(() => {
                        targetStep.classList.add('active');
                        if (step === 2) {
                            const passwordInput = targetStep.querySelector('input[type="password"]');
                            if (passwordInput) passwordInput.focus();
                        } else if (step === 3) {
                            const emailInput = targetStep.querySelector('input[type="email"]');
                            if (emailInput) emailInput.focus();
                        } else if (step === 4) {
                            const verificationInput = targetStep.querySelector('input[name="verification_code"]');
                            if (verificationInput) verificationInput.focus();
                        } else if (step === 5) {
                            const newPasswordInput = targetStep.querySelector('input[name="new_password"]');
                            if (newPasswordInput) newPasswordInput.focus();
                        }
                    }, 50);
                }
            }


            function displayBadge(data) {
                if (!schoolBadge || !schoolBadgeImg) return;

                usernameIcon.classList.add('hidden');
                
                schoolBadgeImg.onload = function() {
                    schoolBadge.classList.add('active');
                    usernameInput.classList.add('has-badge');
                };
                
                schoolBadgeImg.onerror = function() {
                    resetBadge();
                };

                const timestamp = new Date().getTime();
                schoolBadgeImg.src = `${data.school_badge}?t=${timestamp}`;
                
                if (schoolNameTooltip) {
                    schoolNameTooltip.innerHTML = `
                        ${data.school_name}
                        ${data.school_motto ? `<br><small>${data.school_motto}</small>` : ''}
                    `;
                }
            }

            async function checkUsernameAndUpdateBadge() {
                const username = usernameInput.value.trim();

                try {
                    const response = await fetch('index.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=verify_username&username=${encodeURIComponent(username)}`
                    });

                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }

                    const data = await response.json();

                    if (data.valid && data.school_badge) {
                        displayBadge(data);
                    } else {
                        resetBadge();
                    }
                } catch (error) {
                    console.error('Error:', error);
                    resetBadge();
                }
            }

            // Event listener for username input
            if (usernameInput) {
                usernameInput.addEventListener('input', function() {
                    clearTimeout(typingTimer);
                    
                    const username = this.value.trim();
                    if (username.length > 2) {
                        typingTimer = setTimeout(checkUsernameAndUpdateBadge, doneTypingInterval);
                    } else {
                        resetBadge();
                    }
                });
            }

            // Handle username form submission
            if (usernameForm) {
                usernameForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    const username = usernameInput.value.trim();
                    
                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=verify_username&username=${encodeURIComponent(username)}`
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();
                        
                        if (data.valid) {
                            document.getElementById('hiddenUsername').value = username;
                            const welcomeUser = document.getElementById('welcomeUser');
                            if (welcomeUser) {
                                welcomeUser.textContent = `${data.full_name || 'User'}`;
                            }
                            showMessage('Username verified successfully!', 'success');
                            showStep(2);
                        } else {
                            if (data.error) {
                                showMessage('Error: ' + data.error);
                            } else {
                                showMessage('Invalid username. Please try again.');
                            }
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showMessage('An error occurred while verifying the username. Please try again.');
                    }
                });
            }

            // Back button functionality
            const backToUsernameBtn = document.getElementById('backToUsernameBtn');
            if (backToUsernameBtn) {
                backToUsernameBtn.addEventListener('click', () => showStep(1));
            }

            const backToLoginBtn = document.getElementById('backToLoginBtn');
            if (backToLoginBtn) {
                backToLoginBtn.addEventListener('click', () => showStep(2));
            }

            const backToEmailBtn = document.getElementById('backToEmailBtn');
            if (backToEmailBtn) {
                backToEmailBtn.addEventListener('click', () => showStep(3));
            }

            const backToVerificationBtn = document.getElementById('backToVerificationBtn');
            if (backToVerificationBtn) {
                backToVerificationBtn.addEventListener('click', () => showStep(4));
            }

            // Forgot Password button functionality
            const forgotPasswordBtn = document.getElementById('forgotPasswordBtn');
            if (forgotPasswordBtn) {
                forgotPasswordBtn.addEventListener('click', () => {
                    showStep(3);
                });
            }

            // Forgot Password Form Handler
            const forgotPasswordForm = document.getElementById('forgotPasswordForm');
            if (forgotPasswordForm) {
                forgotPasswordForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    const email = document.getElementById('emailInput').value.trim();
                    const username = document.getElementById('hiddenUsername').value;
                    
                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=forgot_password&username=${encodeURIComponent(username)}&email=${encodeURIComponent(email)}`
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            document.getElementById('resetUserId').value = data.user_id;
                            showMessage(data.message || 'Verification code sent to your email!', 'success');
                            showStep(4);
                        } else {
                            showMessage(data.message || 'Error sending verification code');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showMessage('Network error: ' + error.message);
                    }
                });
            }

            // Verification Code Form Handler
            const verificationCodeForm = document.getElementById('verificationCodeForm');
            if (verificationCodeForm) {
                verificationCodeForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    const user_id = document.getElementById('resetUserId').value;
                    const verification_code = document.getElementById('verificationCodeInput').value.trim();
                    
                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=verify_code&user_id=${encodeURIComponent(user_id)}&verification_code=${encodeURIComponent(verification_code)}`
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            // Store verified user ID and code for next step
                            document.getElementById('verifiedUserId').value = user_id;
                            document.getElementById('verifiedCode').value = verification_code;
                            showMessage('Code verified successfully!', 'success');
                            showStep(5);
                        } else {
                            showMessage(data.message || 'Invalid verification code');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showMessage('An error occurred. Please try again.');
                    }
                });
            }

            // New Password Form Handler
            const newPasswordForm = document.getElementById('newPasswordForm');
            if (newPasswordForm) {
                newPasswordForm.addEventListener('submit', async function(event) {
                    event.preventDefault();
                    const user_id = document.getElementById('verifiedUserId').value;
                    const verification_code = document.getElementById('verifiedCode').value;
                    const new_password = document.getElementById('newPasswordInput').value;
                    const confirm_password = document.getElementById('confirmPasswordInput').value;
                    
                    if (new_password !== confirm_password) {
                        showMessage('Passwords do not match');
                        return;
                    }
                    
                    if (new_password.length < 6) {
                        showMessage('Password must be at least 6 characters long');
                        return;
                    }
                    
                    try {
                        const response = await fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=reset_password&user_id=${encodeURIComponent(user_id)}&verification_code=${encodeURIComponent(verification_code)}&new_password=${encodeURIComponent(new_password)}`
                        });

                        const data = await response.json();
                        
                        if (data.success) {
                            showMessage('Password reset successfully! You can now login with your new password.', 'success');
                            setTimeout(() => {
                                showStep(1);
                                // Clear forms
                                document.getElementById('emailInput').value = '';
                                document.getElementById('verificationCodeInput').value = '';
                                document.getElementById('newPasswordInput').value = '';
                                document.getElementById('confirmPasswordInput').value = '';
                            }, 2000);
                        } else {
                            showMessage(data.message || 'Error resetting password');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        showMessage('An error occurred. Please try again.');
                    }
                });
            }

            function showMessage(message, type = 'error') {
                const messageContainer = document.createElement('div');
                messageContainer.className = `message ${type}`;
                messageContainer.innerHTML = `
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                    ${message}
                `;
                
                const existingMessage = document.querySelector('.message');
                if (existingMessage) {
                    existingMessage.remove();
                }
                
                const container = document.querySelector('.container');
                container.insertBefore(messageContainer, container.firstChild);
                
                setTimeout(() => {
                    messageContainer.style.opacity = '0';
                    setTimeout(() => messageContainer.remove(), 300);
                }, 3000);
            }

            function showPasswordError(message) {
                // Show error message
                showMessage(message, 'error');
                
                // Keep user on password step (step 2)
                showStep(2);
                
                // Clear password field and focus on it
                const passwordInput = document.getElementById('passwordInput');
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.focus();
                }
                
                // Add visual feedback to password input
                passwordInput.style.borderColor = '#d93025';
                passwordInput.style.backgroundColor = '#ffebee';
                
                // Remove error styling after 3 seconds
                setTimeout(() => {
                    passwordInput.style.borderColor = '#e0e0e0';
                    passwordInput.style.backgroundColor = '#f8f9fa';
                }, 3000);
            }

            // Handle password errors on page load
            <?php if ($show_password_step && !empty($error)): ?>
            // If there's a password error, show step 2 and focus on password field
            showStep(2);
            const passwordInput = document.getElementById('passwordInput');
            if (passwordInput) {
                passwordInput.focus();
                // Add visual feedback for password error
                passwordInput.style.borderColor = '#d93025';
                passwordInput.style.backgroundColor = '#ffebee';
                
                // Remove error styling after 3 seconds
                setTimeout(() => {
                    passwordInput.style.borderColor = '#e0e0e0';
                    passwordInput.style.backgroundColor = '#f8f9fa';
                }, 3000);
            }
            <?php endif; ?>

            // Initial reset
            resetBadge();
        });
    </script>
</body>
</html