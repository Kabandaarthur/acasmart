 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Database connection (you may want to put this in a separate file)
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);

}

// Get the admin's school_id and user details
$admin_id = $_SESSION['user_id'];
$user_query = "SELECT users.school_id, schools.school_name, users.firstname, users.lastname 
               FROM users 
               JOIN schools ON users.school_id = schools.id 
               WHERE users.user_id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin_data = $result->fetch_assoc();
$school_id = $admin_data['school_id'];
$school_name = $admin_data['school_name'];
$user_fullname = $admin_data['firstname'] . ' ' . $admin_data['lastname'];
$stmt->close();

// Fetch current term information
$current_term_query = "SELECT id, name, year, next_term_start_date FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term_result = $stmt->get_result();
$current_term = $current_term_result->fetch_assoc();
$stmt->close();

// Handle term registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_term'])) {
    $term_name = $_POST['term_name'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $year = $_POST['year'];

    // Set all previous terms to not current
    $update_terms = "UPDATE terms SET is_current = 0 WHERE school_id = ?";
    $stmt = $conn->prepare($update_terms);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $stmt->close();

    // Insert new term
    $insert_term = "INSERT INTO terms (school_id, name, start_date, end_date, is_current, year) VALUES (?, ?, ?, ?, 1, ?)";
    $stmt = $conn->prepare($insert_term);
    $stmt->bind_param("isssi", $school_id, $term_name, $start_date, $end_date, $year);
    $stmt->execute();
    $stmt->close();

    $_SESSION['success_message'] = "New term registered successfully.";

    // Redirect to refresh the page
    header("Location: school_admin_dashboard.php");
    exit();
}

// Handle Next Term Start Date Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_next_term'])) {
    $next_term_start_date = $_POST['next_term_start_date'];
    $current_term_id = $current_term['id'] ?? 0;
    $confirm_update = isset($_POST['confirm_update']) ? $_POST['confirm_update'] : 'no';

    if ($current_term_id > 0) {
        // Check if next term start date already exists and no confirmation yet
        if (!empty($current_term['next_term_start_date']) && $confirm_update !== 'yes') {
            $_SESSION['term_date_exists'] = true;
            $_SESSION['new_next_term_date'] = $next_term_start_date;
            $_SESSION['warning_message'] = "Next term start date already exists. Do you want to update it?";
            header("Location: school_admin_dashboard.php");
            exit();
        }
        
        $update_next_term = "UPDATE terms SET next_term_start_date = ? WHERE id = ? AND school_id = ?";
        $stmt = $conn->prepare($update_next_term);
        $stmt->bind_param("sii", $next_term_start_date, $current_term_id, $school_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success_message'] = "Next term start date updated successfully to " . date('F j, Y', strtotime($next_term_start_date));
        header("Location: school_admin_dashboard.php");
        exit();
    } else {
        $_SESSION['error_message'] = "No active term found to update.";
        header("Location: school_admin_dashboard.php");
        exit();
    }
}

// Fetch school-specific stats
$total_students = $conn->query("SELECT COUNT(*) FROM students WHERE school_id = $school_id")->fetch_row()[0];
$total_teachers = $conn->query("SELECT COUNT(*) FROM users WHERE school_id = $school_id AND (role = 'teacher' OR role = 'admin')")->fetch_row()[0];
$total_exams = $conn->query("SELECT COUNT(*) FROM exams WHERE school_id = $school_id")->fetch_row()[0];
$total_subjects = $conn->query("SELECT COUNT(*) FROM subjects WHERE school_id = $school_id")->fetch_row()[0];

  // Fetch the badge URL for the user's school
  $school_id = $_SESSION['school_id']; // Assuming school_id is stored in the session
  $query = "SELECT badge FROM schools WHERE id = ?";
  $stmt = $conn->prepare($query);
  $stmt->bind_param('i', $school_id);
  $stmt->execute();
  $result = $stmt->get_result();
  
  $badge_path = "https://via.placeholder.com/80"; // Default placeholder in case badge is missing
  if ($result->num_rows > 0) {
      $row = $result->fetch_assoc();
      $badge_path = !empty($row['badge']) ? 'uploads/' . htmlspecialchars($row['badge']) : $badge_path;
  }
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        html, body, * { font-family: 'Poppins', Arial, sans-serif; }
        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateX(6px);
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .gradient-custom {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            }
            .sidebar {
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
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
        }

        /* Add smooth scrolling for mobile menu */
        .sidebar {
            -webkit-overflow-scrolling: touch;
            overflow-y: auto;
        }

        /* Hide scrollbar for Chrome, Safari and Opera */
        .sidebar::-webkit-scrollbar {
            display: none;
        }

        /* Hide scrollbar for IE, Edge and Firefox */
        .sidebar {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
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
<body class="bg-gray-100">
    
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar w-64 space-y-4 py-4 px-2 absolute inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition duration-200 ease-in-out z-40">
            <!-- School Logo and Admin Info -->
            <div class="flex flex-col items-center space-y-2 mb-4">
                <img src="<?php echo $badge_path; ?>" alt="School Badge" class="w-20 h-20 rounded-full border-4 border-white shadow-lg">
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
                <!-- Archive Management Links -->
                <a href="archive_results.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-archive text-lg"></i>
                    <span>Archive Results</span>
                </a>
                <a href="view_archives.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-box-open text-lg"></i>
                    <span>View Archives</span>
                </a>
                <a href="school_settings.php" class="sidebar-item flex items-center space-x-3 text-white p-2 rounded-lg text-sm">
                    <i class="fas fa-chalkboard text-lg"></i>
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
                            <h1 class="text-2xl font-bold ml-2"><?php echo htmlspecialchars($school_name); ?></h1>
                        </div>
                        <?php if ($current_term): ?>
                            <p class="text-blue-200 hidden md:block"><?php echo htmlspecialchars($current_term['name']); ?> - <?php echo htmlspecialchars($current_term['year']); ?></p>
                        <?php endif; ?>
                        <div class="flex items-center space-x-4">
                            <a href="index.php" class="bg-white text-blue-800 px-4 py-2 rounded-lg hover:bg-blue-50 transition duration-150">
                                <i class="fas fa-sign-out-alt mr-2"></i>Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Dashboard Content -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100">
                <div class="container mx-auto px-6 py-8">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-md" role="alert">
                            <?php 
                                echo $_SESSION['success_message']; 
                                unset($_SESSION['success_message']);
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-md" role="alert">
                            <?php 
                                echo $_SESSION['error_message']; 
                                unset($_SESSION['error_message']);
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['warning_message'])): ?>
                        <div class="mb-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded shadow-md" role="alert">
                            <?php echo $_SESSION['warning_message']; ?>
                            
                            <form method="POST" action="" class="mt-2">
                                <input type="hidden" name="next_term_start_date" value="<?php echo $_SESSION['new_next_term_date']; ?>">
                                <input type="hidden" name="confirm_update" value="yes">
                                <div class="flex space-x-2">
                                    <button type="submit" name="update_next_term" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                                        Yes, Update
                                    </button>
                                    <a href="school_admin_dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-block">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                            
                            <?php 
                                unset($_SESSION['warning_message']); 
                                unset($_SESSION['term_date_exists']);
                                unset($_SESSION['new_next_term_date']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <!-- Stats Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <!-- Students Stats -->
                        <div class="stat-card bg-white rounded-lg shadow-lg p-6">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-blue-500 bg-opacity-10">
                                    <i class="fas fa-user-graduate text-2xl text-blue-500"></i>
                                </div>
                                <div class="ml-4">
                                    <h3 class="text-3xl font-bold text-gray-700"><?php echo $total_students; ?></h3>
                                    <p class="text-sm text-gray-500">Total Students</p>
            </div>
        </div>
                </div>
            
                        <!-- Teachers Stats -->
                        <div class="stat-card bg-white rounded-lg shadow-lg p-6">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-green-500 bg-opacity-10">
                                    <i class="fas fa-chalkboard-teacher text-2xl text-green-500"></i>
                                </div>
                                <div class="ml-4">
                                    <h3 class="text-3xl font-bold text-gray-700"><?php echo $total_teachers; ?></h3>
                                    <p class="text-sm text-gray-500">Total Teachers</p>
                        </div>
                    </div>
                        </div>
                </div>

                <!-- Term Management Section -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Current Term Card -->
                        <div class="bg-white rounded-lg shadow-lg">
                            <div class="p-6">
                                <h2 class="text-xl font-bold text-gray-700 mb-4">Current Term</h2>
                                <?php if ($current_term): ?>
                                    <div class="bg-blue-50 rounded-lg p-4">
                                        <div class="flex items-center space-x-4">
                                            <div class="p-3 rounded-full bg-blue-500 bg-opacity-10">
                                                <i class="fas fa-calendar text-xl text-blue-500"></i>
                                            </div>
                                            <div>
                                                <h3 class="text-lg font-semibold text-gray-700"><?php echo htmlspecialchars($current_term['name']); ?></h3>
                                                <p class="text-gray-600">Academic Year: <?php echo htmlspecialchars($current_term['year']); ?></p>
                                                <?php if (!empty($current_term['next_term_start_date'])): ?>
                                                <p class="text-gray-600">Next Term Begins: <?php echo date('F j, Y', strtotime($current_term['next_term_start_date'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500">No active term.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Register New Term Card -->
                        <div class="bg-white rounded-lg shadow-lg">
                            <div class="p-6">
                                <h2 class="text-xl font-bold text-gray-700 mb-4">Register New Term</h2>
                                <form method="POST" action="" class="space-y-4">
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2">Term Name</label>
                                        <select name="term_name" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="First Term">First Term</option>
                                            <option value="Second Term">Second Term</option>
                                            <option value="Third Term">Third Term</option>
                                        </select>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-gray-700 text-sm font-bold mb-2">Start Date</label>
                                            <input type="date" name="start_date" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                        </div>
                                        <div>
                                            <label class="block text-gray-700 text-sm font-bold mb-2">End Date</label>
                                            <input type="date" name="end_date" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                    </div>
                                    </div>
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2">Year</label>
                                        <input type="number" name="year" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                    </div>
                                    <button type="submit" name="register_term" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-150">
                                        Register Term
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Next Term Start Date Card -->
                    <?php if ($current_term): ?>
                    <div class="mt-6">
                        <div class="bg-white rounded-lg shadow-lg">
                            <div class="p-6">
                                <h2 class="text-xl font-bold text-gray-700 mb-4">Set Next Term Start Date</h2>
                                <form method="POST" action="" class="space-y-4">
                                    <div>
                                        <label class="block text-gray-700 text-sm font-bold mb-2">Next Term Start Date for <?php echo htmlspecialchars($current_term['name']); ?></label>
                                        <input type="date" name="next_term_start_date" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo $current_term['next_term_start_date'] ?? ''; ?>" required>
                                        <?php if (!empty($current_term['next_term_start_date'])): ?>
                                            <p class="text-sm text-amber-600 mt-1">
                                                <i class="fas fa-info-circle mr-1"></i> 
                                                Current next term date: <?php echo date('F j, Y', strtotime($current_term['next_term_start_date'])); ?>
                                            </p>
                                         <?php endif; ?> 
                                    </div>
                                    <button type="submit" name="update_next_term" class="w-full bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition duration-150">
                                        <?php echo !empty($current_term['next_term_start_date']) ? 'Update Next Term Date' : 'Set Next Term Date'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>
    <script>
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.flex-1');
        let isSidebarOpen = false;

        mobileMenuButton.addEventListener('click', () => {
            isSidebarOpen = !isSidebarOpen;
            if (isSidebarOpen) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0');
                // Add overlay
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
            // Remove overlay
            const overlay = document.getElementById('sidebar-overlay');
            if (overlay) {
                overlay.remove();
            }
        }

        // Close sidebar when window is resized to larger screen
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) { // 768px is the md breakpoint in Tailwind
                closeSidebar();
            }
        });
    </script>
</body>
</html