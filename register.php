 <?php
session_start();

// Database connection
$db_host = "localhost";   
$db_user = "root";
$db_pass = "";
$db_name = "sms";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection Error:" . $conn->connect_error);
}

// User registration handler
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'register') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $email = $_POST['email'];
    $firstname = $_POST['firstname'];
    $lastname = $_POST['lastname'];
    $role = $_POST['role'];
    $school_id = isset($_POST['school_id']) ? $_POST['school_id'] : null;

    // Validate input
    if ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        // Check if username or email already exists
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $error = "Username or email already exists.";
        } else {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert new user
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, firstname, lastname, role, school_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssi", $username, $hashed_password, $email, $firstname, $lastname, $role, $school_id);

            if ($stmt->execute()) {
                $_SESSION['registration_success'] = true;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background-color: white;
            padding: 2rem;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 1rem;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        input, button, select {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .toggle-form {
            text-align: center;
            margin-top: 10px;
        }
        .notification {
            background-color: #4CAF50;
            color: white;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 1rem;
            text-align: center;
            display: none;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>SCHOOL MANAGEMENT SYSTEM</h1>
    <div id="notification" class="notification"></div>
    <div id="loginForm">
        <form method="POST" action="">
            <input type="hidden" name="action" value="register">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="text" name="firstname" placeholder="First Name" required>
            <input type="text" name="lastname" placeholder="Last Name" required>
            <select name="role" id="role" required>
                <option value="">Select User Type</option>
                <option value="super_admin">Super Admin</option>
                <option value="admin">Admin</option>
                <option value="teacher">Teacher</option>
            </select>
            <input type="number" name="school_id" id="school_id" placeholder="School ID" style="display: none;">
            <button type="submit">Register</button>
        </form>
        <div class="toggle-form">
            <p>Already have an account? <a href="login.php">Login</a></p>
        </div>
    </div>
</div>

<script>
function showNotification(message) {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.style.display = 'block';
    setTimeout(() => {
        notification.style.display = 'none';
    }, 3000);
}

<?php
if (isset($_SESSION['registration_success'])) {
    echo "showNotification('User registered successfully!');";
    unset($_SESSION['registration_success']);
}
?>
</script>
</body>
</html