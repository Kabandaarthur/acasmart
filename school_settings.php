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

// Get the admin's school_id and school details
$admin_id = $_SESSION['user_id'];
$user_query = "SELECT users.school_id, schools.*, users.firstname, users.lastname 
               FROM users 
               JOIN schools ON users.school_id = schools.id 
               WHERE users.user_id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$school_data = $result->fetch_assoc();
$school_id = $school_data['school_id'];
$user_fullname = $school_data['firstname'] . ' ' . $school_data['lastname'];
$stmt->close();

// Get additional school statistics
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM students WHERE school_id = ?) as total_students,
    (SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'teacher') as total_teachers,
    (SELECT COUNT(*) FROM subjects WHERE school_id = ?) as total_subjects,
    (SELECT COUNT(*) FROM exams WHERE school_id = ?) as total_exams,
    (SELECT COUNT(*) FROM terms WHERE school_id = ?) as total_terms";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("iiiii", $school_id, $school_id, $school_id, $school_id, $school_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stmt->close();

$error = '';
$success = '';
$edit_mode = false;

// Handle edit mode toggle
if (isset($_GET['edit'])) {
    $edit_mode = $_GET['edit'] === 'true';
}

// Create uploads directory if it doesn't exist
$upload_dir = 'uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_school'])) {
    $school_name = $conn->real_escape_string($_POST['school_name']);
    $registration_number = $conn->real_escape_string($_POST['registration_number']);
    $location = $conn->real_escape_string($_POST['location']);
    $status = $conn->real_escape_string($_POST['status']);
    $motto = $conn->real_escape_string($_POST['motto']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    
    // Handle file upload for badge
    $badge_path = $school_data['badge']; // Keep existing badge if no new one uploaded
    if (isset($_FILES['badge']) && $_FILES['badge']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['badge']['name'];
        $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($filetype, $allowed)) {
            // Generate unique filename
            $new_filename = uniqid('badge_') . '.' . $filetype;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['badge']['tmp_name'], $upload_path)) {
                // Delete old badge if it exists
                if ($school_data['badge'] && file_exists($upload_dir . $school_data['badge'])) {
                    unlink($upload_dir . $school_data['badge']);
                }
                $badge_path = $new_filename;
            } else {
                $error = "Failed to upload the new badge image.";
            }
        } else {
            $error = "Invalid file type. Only JPG, JPEG, PNG and GIF files are allowed.";
        }
    }
    
    if (empty($error)) {
        $stmt = $conn->prepare("UPDATE schools SET school_name = ?, registration_number = ?, location = ?, status = ?, motto = ?, email = ?, phone = ?, badge = ? WHERE id = ?");
        $stmt->bind_param("ssssssssi", $school_name, $registration_number, $location, $status, $motto, $email, $phone, $badge_path, $school_id);
        
        if ($stmt->execute()) {
            $success = "School settings updated successfully!";
            // Refresh school data
            $user_query = "SELECT users.school_id, schools.*, users.firstname, users.lastname 
                           FROM users 
                           JOIN schools ON users.school_id = schools.id 
                           WHERE users.user_id = ?";
            $stmt2 = $conn->prepare($user_query);
            $stmt2->bind_param("i", $admin_id);
            $stmt2->execute();
            $result = $stmt2->get_result();
            $school_data = $result->fetch_assoc();
            $stmt2->close();
            $edit_mode = false; // Exit edit mode after successful update
        } else {
            $error = "Error updating school settings: " . $stmt->error;
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Settings - <?php echo htmlspecialchars($school_data['school_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #1e40af;
            --accent-color: #f59e0b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --bg-primary: #ffffff;
            --bg-secondary: #f9fafb;
            --bg-tertiary: #f3f4f6;
            --border-color: #e5e7eb;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .dark {
            --text-primary: #f9fafb;
            --text-secondary: #d1d5db;
            --bg-primary: #111827;
            --bg-secondary: #1f2937;
            --bg-tertiary: #374151;
            --border-color: #4b5563;
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px 0 rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
        }

        html, body, * { 
            font-family: 'Poppins', Arial, sans-serif; 
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        body {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
        }

        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateX(6px);
        }

        .gradient-custom {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
        }

        .sidebar {
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
        }

        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-lg);
        }

        .form-input {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .form-input:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            border-color: var(--primary-color);
        }

        .badge-preview {
            max-width: 150px;
            max-height: 150px;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .stat-card {
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 50px;
            padding: 10px;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            transform: scale(1.1);
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100vh;
                top: 0;
                left: 0;
                z-index: 40;
            }
            .main-content {
                margin-left: 0;
            }
            .theme-toggle {
                top: 10px;
                right: 10px;
            }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .slide-in {
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
    </style>
</head>
<body>
    <!-- Theme Toggle Button -->
    <div class="theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
        <i id="theme-icon" class="fas fa-moon text-xl"></i>
    </div>

    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar w-64 space-y-4 py-4 px-2 absolute inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition duration-200 ease-in-out z-40">
            <!-- School Logo and Admin Info -->
            <div class="flex flex-col items-center space-y-2 mb-4">
                <img src="<?php echo !empty($school_data['badge']) ? 'uploads/' . htmlspecialchars($school_data['badge']) : 'https://via.placeholder.com/80'; ?>" 
                     alt="School Badge" class="w-20 h-20 rounded-full border-4 border-white shadow-lg">
                <div class="text-center">
                    <h2 class="text-lg font-bold text-white"><?php echo htmlspecialchars($user_fullname); ?></h2>
                    <p class="text-blue-200 text-xs">School Administrator</p>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-2 px-4">
                <a href="school_admin_dashboard.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg transition duration-150 text-sm">
                    <i class="fas fa-home text-lg"></i>
                    <span>Home</span>
                </a>
                <a href="manage_teachers.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-chalkboard-teacher text-lg"></i>
                    <span>Teachers</span>
                </a>
                <a href="manage_subjects.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-book text-lg"></i>
                    <span>Subjects</span>
                </a>
                <a href="manage_students.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-user-graduate text-lg"></i>
                    <span>Students</span>
                </a>
                <a href="manage_exams.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-file-alt text-lg"></i>
                    <span>Exams</span>
                </a>
                <a href="student_subjects.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-graduation-cap text-lg"></i>
                    <span>Student-Subjects</span>
                </a>
                <a href="manage_records.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-pen-alt text-lg"></i>
                    <span>Subject-results</span>
                </a>
                <a href="view_report.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-chart-bar text-lg"></i>
                    <span>Reports</span>
                </a>
                <a href="download_alevel_reports.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-chart-bar text-lg"></i>
                    <span>Download Alevel Reports</span>
                </a>
                <a href="promote_students.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-arrow-up text-lg"></i>
                    <span>Student Promotion</span>
                </a>
                <a href="view_analysis.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-chart-bar text-lg"></i>
                    <span>Class Analysis</span>
                </a>
                <a href="grading.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-clipboard-list text-lg"></i>
                    <span>Grading</span>
                </a>
                <a href="archive_results.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-archive text-lg"></i>
                    <span>Archive Results</span>
                </a>
                <a href="view_archives.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-box-open text-lg"></i>
                    <span>View Archives</span>
                </a>
                <a href="school_settings.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm bg-white bg-opacity-20">
                    <i class="fas fa-cog text-lg"></i>
                    <span>School Settings</span>
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Navigation -->
            <header class="gradient-custom text-white shadow-lg">
                <div class="container mx-auto px-4 py-4">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center">
                            <button id="mobile-menu-button" class="md:hidden text-white focus:outline-none">
                                <i class="fas fa-bars text-2xl"></i>
                            </button>
                            <h1 class="text-2xl font-bold ml-2">School Settings</h1>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100">
                <div class="container mx-auto px-6 py-8">
                    <!-- Success/Error Messages -->
                    <?php if (!empty($success)): ?>
                        <div id="success-message" class="mb-6 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-md fade-in" role="alert">
                            <div class="flex">
                                <div class="flex-1">
                                    <i class="fas fa-check-circle text-xl mr-3"></i>
                                    <div>
                                        <p class="font-bold">Success!</p>
                                        <p><?php echo htmlspecialchars($success); ?></p>
                                    </div>
                                </div>
                                <button onclick="document.getElementById('success-message').remove()" class="text-green-700 hover:text-green-900">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <script>
                            // Auto-hide success message after 1 minute
                            setTimeout(function() {
                                const successMessage = document.getElementById('success-message');
                                if (successMessage) {
                                    successMessage.style.transition = 'opacity 0.5s ease-out';
                                    successMessage.style.opacity = '0';
                                    setTimeout(() => successMessage.remove(), 500);
                                }
                            }, 60000); // 60000 milliseconds = 1 minute
                        </script>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-md fade-in" role="alert">
                            <div class="flex">
                                <i class="fas fa-exclamation-circle text-xl mr-3"></i>
                                <div>
                                    <p class="font-bold">Error!</p>
                                    <p><?php echo htmlspecialchars($error); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$edit_mode): ?>
                    <!-- Display View -->
                    <div class="bg-white rounded-lg shadow-lg overflow-hidden fade-in">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800">
                            
                                    <?php echo htmlspecialchars($school_data['school_name']); ?>
                                </h2>
                                <p class="text-gray-600 mt-1">School Information and Statistics</p>
                            </div>
                            <a href="?edit=true" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition duration-150">
                                <i class="fas fa-edit mr-2"></i>Update Settings
                            </a>
                        </div>

                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <!-- School Details -->
                                <div class="space-y-6">
                                    <div class="bg-blue-50 p-4 rounded-lg">
                                        <h3 class="text-lg font-semibold text-blue-800 mb-4">Basic Information</h3>
                                        <div class="space-y-4">
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">Registration Number</label>
                                                <p class="text-gray-800"><?php echo htmlspecialchars($school_data['registration_number']); ?></p>
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">Location</label>
                                                <p class="text-gray-800"><?php echo htmlspecialchars($school_data['location']); ?></p>
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">Status</label>
                                                <p class="mt-1">
                                                    <span class="px-3 py-1 rounded-full text-sm <?php echo $school_data['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                        <?php echo ucfirst(htmlspecialchars($school_data['status'])); ?>
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="bg-purple-50 p-4 rounded-lg">
                                        <h3 class="text-lg font-semibold text-purple-800 mb-4">Contact Information</h3>
                                        <div class="space-y-4">
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">
                                                    <i class="fas fa-envelope mr-2"></i>Email Address
                                                </label>
                                                <p class="text-gray-800"><?php echo htmlspecialchars($school_data['email']); ?></p>
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">
                                                    <i class="fas fa-phone mr-2"></i>Phone Number
                                                </label>
                                                <p class="text-gray-800"><?php echo htmlspecialchars($school_data['phone']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- School Identity and Statistics -->
                                <div class="space-y-6">
                                    <div class="bg-green-50 p-4 rounded-lg">
                                        <h3 class="text-lg font-semibold text-green-800 mb-4">School Identity</h3>
                                        <div class="space-y-4">
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">School Motto</label>
                                                <p class="text-gray-800 italic">"<?php echo htmlspecialchars($school_data['motto']); ?>"</p>
                                            </div>
                                            <div>
                                                <label class="text-sm font-medium text-gray-600">School Badge</label>
                                                <div class="mt-2">
                                                    <img src="<?php echo !empty($school_data['badge']) ? 'uploads/' . htmlspecialchars($school_data['badge']) : 'https://via.placeholder.com/150x150?text=No+Badge'; ?>" 
                                                         alt="School Badge" 
                                                         class="badge-preview">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Statistics Cards -->
                                    <div class="bg-yellow-50 p-4 rounded-lg">
                                        <h3 class="text-lg font-semibold text-yellow-800 mb-4">Quick Statistics</h3>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="stat-card p-4 rounded-lg bg-white">
                                                <p class="text-sm font-medium text-blue-600">Students</p>
                                                <p class="text-2xl font-bold text-blue-800"><?php echo number_format($stats['total_students']); ?></p>
                                            </div>
                                            <div class="stat-card p-4 rounded-lg bg-white">
                                                <p class="text-sm font-medium text-green-600">Teachers</p>
                                                <p class="text-2xl font-bold text-green-800"><?php echo number_format($stats['total_teachers']); ?></p>
                                            </div>
                                            <div class="stat-card p-4 rounded-lg bg-white">
                                                <p class="text-sm font-medium text-purple-600">Subjects</p>
                                                <p class="text-2xl font-bold text-purple-800"><?php echo number_format($stats['total_subjects']); ?></p>
                                            </div>
                                            <div class="stat-card p-4 rounded-lg bg-white">
                                                <p class="text-sm font-medium text-yellow-600">Exams</p>
                                                <p class="text-2xl font-bold text-yellow-800"><?php echo number_format($stats['total_exams']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- School Settings Form -->
                    <div class="bg-white rounded-lg shadow-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-school mr-2 text-blue-600"></i>
                                School Information
                            </h2>
                            <p class="text-gray-600 mt-1">Update your school's details and settings</p>
                        </div>
                        
                        <form method="POST" action="" enctype="multipart/form-data" class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- School Name -->
                                <div class="md:col-span-2">
                                    <label for="school_name" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-school mr-1"></i>School Name
                                    </label>
                                    <input type="text" 
                                           id="school_name" 
                                           name="school_name" 
                                           value="<?php echo htmlspecialchars($school_data['school_name']); ?>" 
                                           class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                           required>
                                </div>

                                <!-- Registration Number -->
                                <div>
                                    <label for="registration_number" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-id-card mr-1"></i>Registration Number
                                    </label>
                                    <input type="text" 
                                           id="registration_number" 
                                           name="registration_number" 
                                           value="<?php echo htmlspecialchars($school_data['registration_number']); ?>" 
                                           class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                           required>
                                </div>

                                <!-- Location -->
                                <div>
                                    <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-map-marker-alt mr-1"></i>Location
                                    </label>
                                    <input type="text" 
                                           id="location" 
                                           name="location" 
                                           value="<?php echo htmlspecialchars($school_data['location']); ?>" 
                                           class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                           required>
                                </div>

                                <!-- Status -->
                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-toggle-on mr-1"></i>Status
                                    </label>
                                    <select id="status" 
                                            name="status" 
                                            class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                            required>
                                        <option value="active" <?php echo $school_data['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $school_data['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>

                                <!-- School Motto -->
                                <div>
                                    <label for="motto" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-quote-left mr-1"></i>School Motto
                                    </label>
                                    <input type="text" 
                                           id="motto" 
                                           name="motto" 
                                           value="<?php echo htmlspecialchars($school_data['motto']); ?>" 
                                           class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                           required>
                                </div>

                                <!-- Email -->
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-envelope mr-1"></i>Email Address
                                    </label>
                                    <input type="email" 
                                           id="email" 
                                           name="email" 
                                           value="<?php echo htmlspecialchars($school_data['email']); ?>" 
                                           class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                           required>
                                </div>

                                <!-- Phone -->
                                <div>
                                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-phone mr-1"></i>Phone Number
                                    </label>
                                    <input type="tel" 
                                           id="phone" 
                                           name="phone" 
                                           value="<?php echo htmlspecialchars($school_data['phone']); ?>" 
                                           class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" 
                                           required>
                                </div>

                                <!-- School Badge -->
                                <div class="md:col-span-2">
                                    <label for="badge" class="block text-sm font-medium text-gray-700 mb-2">
                                        <i class="fas fa-image mr-1"></i>School Badge
                                    </label>
                                    <div class="flex flex-col md:flex-row items-start md:items-center space-y-4 md:space-y-0 md:space-x-6">
                                        <div class="flex-1">
                                            <input type="file" 
                                                   id="badge" 
                                                   name="badge" 
                                                   accept="image/*" 
                                                   class="form-input w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                            <p class="text-sm text-gray-500 mt-1">Upload a new badge image (JPG, PNG, GIF) or leave empty to keep current badge</p>
                                        </div>
                                        <div class="text-center">
                                            <p class="text-sm font-medium text-gray-700 mb-2">Current Badge:</p>
                                            <img src="<?php echo !empty($school_data['badge']) ? 'uploads/' . htmlspecialchars($school_data['badge']) : 'https://via.placeholder.com/150x150?text=No+Badge'; ?>" 
                                                 alt="Current School Badge" 
                                                 class="badge-preview mx-auto" 
                                                 id="currentBadge">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex flex-col sm:flex-row gap-4 mt-8 pt-6 border-t border-gray-200">
                                <button type="submit" 
                                        name="update_school" 
                                        class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-6 rounded-lg transition duration-150 flex items-center justify-center">
                                    <i class="fas fa-save mr-2"></i>
                                    Update School Settings
                                </button>
                                <a href="school_admin_dashboard.php" 
                                   class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-medium py-3 px-6 rounded-lg transition duration-150 flex items-center justify-center">
                                    <i class="fas fa-arrow-left mr-2"></i>
                                    Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Theme Toggle Functionality
        function toggleTheme() {
            const html = document.documentElement;
            const themeIcon = document.getElementById('theme-icon');
            
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                themeIcon.className = 'fas fa-moon text-xl';
                document.cookie = 'theme=light; path=/; max-age=31536000';
            } else {
                html.classList.add('dark');
                themeIcon.className = 'fas fa-sun text-xl';
                document.cookie = 'theme=dark; path=/; max-age=31536000';
            }
        }

        // Mobile menu toggle
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const sidebar = document.getElementById('sidebar');
        let isSidebarOpen = false;

        mobileMenuButton.addEventListener('click', () => {
            isSidebarOpen = !isSidebarOpen;
            if (isSidebarOpen) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0');
                const overlay = document.createElement('div');
                overlay.id = 'sidebar-overlay';
                overlay.className = 'fixed inset-0 bg-black bg-opacity-50 z-30';
                overlay.addEventListener('click', closeSidebar);
                document.body.appendChild(overlay);
            } else {
                closeSidebar();
            }
        });

        function closeSidebar() {
            sidebar.classList.remove('translate-x-0');
            sidebar.classList.add('-translate-x-full');
            isSidebarOpen = false;
            const overlay = document.getElementById('sidebar-overlay');
            if (overlay) {
                overlay.remove();
            }
        }

        // Close sidebar when window is resized to larger screen
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                closeSidebar();
            }
        });

        // Image preview for badge upload
        document.getElementById('badge').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const currentBadge = document.getElementById('currentBadge');
            
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    currentBadge.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('input[required], select[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('border-red-500');
                    isValid = false;
                } else {
                    field.classList.remove('border-red-500');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    </script>
</body>
</html>
