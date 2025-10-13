 <?php
session_start();

// Check if the user is logged in and is a super admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
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

$error = '';
$success = '';
$new_admin = null;

// Include PHPMailer files
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Function to generate school email
function generateSchoolEmail($firstname, $lastname, $school_name, $conn) {
    // Clean and format names
    $firstname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstname));
    $lastname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastname));
    $school_domain = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $school_name));
    
    // Generate base email
    $base_email = $firstname . "." . $lastname . "@" . $school_domain . ".edu";
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $base_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If email exists, add a number
    if ($result->num_rows > 0) {
        $counter = 1;
        do {
            $new_email = $firstname . "." . $lastname . $counter . "@" . $school_domain . ".edu";
            $stmt->bind_param("s", $new_email);
            $stmt->execute();
            $result = $stmt->get_result();
            $counter++;
        } while ($result->num_rows > 0);
        return $new_email;
    }
    
    return $base_email;
}

// Function to generate username from school name
function generateUsername($firstname, $lastname, $school_name) {
    // Clean and format names
    $firstname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstname));
    $lastname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastname));
    
    // Generate school abbreviation
    $school_abbr = '';
    $words = explode(' ', trim(strtolower($school_name)));
    foreach ($words as $word) {
        if (!empty($word)) {
            $word = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if (!empty($word)) {
                $school_abbr .= $word[0];
            }
        }
    }
    
    return $firstname . $lastname . '@' . $school_abbr;
}

// Email function to send credentials
function sendAdminNotification($username, $password, $email, $firstname, $lastname, $school_name) {
    $from = "schoolanalytics4@gmail.com";
    $fromName = "School Management System";
    $subject = "School Registration and Admin Credentials";

    $smtp = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'schoolanalytics4@gmail.com', // Your Gmail address
        'password' => 'bcerbdbqiwyearjy'           // Use an App Password
    ];

    $message = "
    <html>
    <body>
        <h2>Welcome to the School Management System</h2>
        <p>Dear $firstname $lastname,</p>
        <p>Your school <strong>$school_name</strong> has been successfully registered in the system. You have been assigned as the administrator. Here are your login details:</p>
        <ul>
            <li><strong>Username:</strong> $username</li>
            <li><strong>Password:</strong> $password</li>
        </ul>
        <p>Use these credentials to log in and manage your school's details.</p>
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

// Inserting the admin and sending notification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_admin') {
    $school_id = intval($_POST['school_id']);
    $firstname = $conn->real_escape_string($_POST['firstname']);
    $lastname = $conn->real_escape_string($_POST['lastname']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $email = $conn->real_escape_string($_POST['email']);
    
    // Get school name for username generation
    $school_query = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_query->bind_param("i", $school_id);
    $school_query->execute();
    $school_result = $school_query->get_result();
    $school_data = $school_result->fetch_assoc();
    $school_name = $school_data['school_name'];
    $school_query->close();
    
    // Generate username
    $username = generateUsername($firstname, $lastname, $school_name);
    $password = $_POST['password']; // Plain password for email
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $role = 'admin';
    $created_at = date('Y-m-d H:i:s');
    $updated_at = date('Y-m-d H:i:s');

    // Check if username already exists
    $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $error = "Admin already registered with this username.";
    } else {
        // Check if email already exists
        $check_email_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check_email_stmt->bind_param("s", $email);
        $check_email_stmt->execute();
        $check_email_result = $check_email_stmt->get_result();

        if ($check_email_result->num_rows > 0) {
            $error = "This email is already registered in the system.";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, phone, firstname, lastname, school_id, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssisss", $username, $hashedPassword, $email, $phone, $firstname, $lastname, $school_id, $role, $created_at, $updated_at);

            if ($stmt->execute()) {
                // Send email notification
                if (sendAdminNotification($username, $password, $email, $firstname, $lastname, $school_name)) {
                    $success = "School admin added and notified successfully.";
                } else {
                    $error = "Admin added, but failed to send notification.";
                }

                $new_admin = [
                    'school_id' => $school_id,
                    'user_id' => $stmt->insert_id,
                    'firstname' => $firstname,
                    'lastname' => $lastname
                ];
            } else {
                $error = "Failed to add school admin: " . $stmt->error;
            }
            $stmt->close();
        }
        $check_email_stmt->close();
    }
    $check_stmt->close();
}

// Fetch all schools with their admins
$schools_query = "SELECT s.id, s.school_name, s.registration_number, s.location, s.status, 
                         u.user_id, u.firstname, u.lastname 
                  FROM schools s 
                  LEFT JOIN users u ON s.id = u.school_id AND u.role = 'admin'";
$schools_result = $conn->query($schools_query);

if (!$schools_result) {
    die("Error fetching schools: " . $conn->error);
}

$schools = $schools_result->fetch_all(MYSQLI_ASSOC);

// Update the schools array with the newly added admin
if ($new_admin) {
    foreach ($schools as &$school) {
        if ($school['id'] == $new_admin['school_id']) {
            $school['user_id'] = $new_admin['user_id'];
            $school['firstname'] = $new_admin['firstname'];
            $school['lastname'] = $new_admin['lastname'];
            break;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Admin Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .container {
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 30px;
            margin-top: 50px;
        }
        h1 {
            color: #007bff;
            margin-bottom: 30px;
        }
        .table {
            background-color: #ffffff;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }
        .modal-content {
            border-radius: 15px;
        }
        .modal-header {
            background-color: #007bff;
            color: #ffffff;
            border-top-left-radius: 15px;
            border-top-right-radius: 15px;
        }
        .modal-title {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-center mb-4">School Admin Management</h1>
        <a href="super_admin_dashboard.php" class="btn btn-secondary mb-4"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        <?php
        if (!empty($error)) {
            echo "<div class='alert alert-danger'>{$error}</div>";
        }
        if (!empty($success)) {
            echo "<div class='alert alert-success'>{$success}</div>";
        }
        ?>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>School Name</th>
                        <th>Registration Number</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Current Admin</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $school): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                        <td><?php echo htmlspecialchars($school['registration_number']); ?></td>
                        <td><?php echo htmlspecialchars($school['location']); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $school['status'] == 'active' ? 'success' : 'warning'; ?>">
                                <?php echo htmlspecialchars($school['status']); ?>
                            </span>
                        </td>
                        <td>
                             <?php
                                 if ($school['user_id']) {
                                       echo htmlspecialchars($school['firstname'] . ' ' . $school['lastname']);
                                      } else {
                                          echo "<span class='text-muted'>No admin assigned</span>";
                                    }
                             ?>
                        </td>
                        <td>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAdminModal<?php echo $school['id']; ?>">
    <i class="fas <?php echo $school['user_id'] ? 'fa-user-edit' : 'fa-user-plus'; ?> me-2"></i>
    <?php echo $school['user_id'] ? 'Change Admin' : 'Add Admin'; ?>
</button>
                        </td>
                    </tr>

                    <!-- Add/Change Admin Modal -->
                    <div class="modal fade" id="addAdminModal<?php echo $school['id']; ?>" tabindex="-1" aria-labelledby="addAdminModalLabel<?php echo $school['id']; ?>" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="addAdminModalLabel<?php echo $school['id']; ?>">
                                        <?php echo $school['user_id'] ? 'Change Admin' : 'Add Admin'; ?> for <?php echo htmlspecialchars($school['school_name']); ?>
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                <form method="POST" action="">
    <input type="hidden" name="action" value="add_admin">
    <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
    <div class="mb-3">
        <label for="firstname" class="form-label">First Name</label>
        <input type="text" class="form-control" id="firstname" name="firstname" required>
    </div>
    <div class="mb-3">
        <label for="lastname" class="form-label">Last Name</label>
        <input type="text" class="form-control" id="lastname" name="lastname" required>
    </div>
    <div class="username-preview alert alert-info" style="display: none;"></div>
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control" id="email" name="email" required>
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
            <input type="password" class="form-control" id="password" name="password" required>
            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility()">
                Show
            </button>
        </div>
    </div>
    <div class="mb-3">
        <label for="phone" class="form-label">Phone</label>
        <input type="text" class="form-control" id="phone" name="phone" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Save</button>
</form>

                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const firstname = document.getElementById('firstname');
    const lastname = document.getElementById('lastname');
    const usernamePreview = document.querySelector('.username-preview');
    
    function updateUsernamePreview() {
        if (firstname.value && lastname.value) {
            const first = firstname.value.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');
            const last = lastname.value.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');
            const schoolName = '<?php echo addslashes($school_name); ?>';
            const abbr = schoolName.toLowerCase()
                .split(' ')
                .filter(word => word.length > 0)
                .map(word => word.replace(/[^a-zA-Z0-9]/g, ''))
                .map(word => word[0] || '')
                .join('');
            const username = `${first}${last}@${abbr}`;
            usernamePreview.textContent = `Generated username: ${username}`;
            usernamePreview.style.display = 'block';
        } else {
            usernamePreview.style.display = 'none';
        }
    }

    firstname.addEventListener('input', updateUsernamePreview);
    lastname.addEventListener('input', updateUsernamePreview);
});

function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleButton = event.currentTarget;
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleButton.textContent = 'Hide';
    } else {
        passwordInput.type = 'password';
        toggleButton.textContent = 'Show';
    }
}
    </script>
</body>
</html>