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

// Get the bursar's school_id and user details
$bursar_id = $_SESSION['user_id'];
$user_query = "SELECT users.school_id, schools.school_name, users.firstname, users.lastname 
               FROM users 
               JOIN schools ON users.school_id = schools.id 
               WHERE users.user_id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $bursar_id);
$stmt->execute();
$result = $stmt->get_result();
$bursar_data = $result->fetch_assoc();
$school_id = $bursar_data['school_id'];
$school_name = $bursar_data['school_name'];
$user_fullname = $bursar_data['firstname'] . ' ' . $bursar_data['lastname'];
$stmt->close();

// Get selected class and section
$selected_class = isset($_GET['class_id']) ? $_GET['class_id'] : null;
$selected_section = isset($_GET['section']) ? $_GET['section'] : null;

// Handle bulk section update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_section'])) {
    if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
        // Use the current section instead of form input
        $section = $selected_section;
        $success_count = 0;
        $error_count = 0;
        
        // Prepare the update statement
        $update_query = "UPDATE students SET section = ? WHERE id = ? AND school_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sii", $section, $student_id, $school_id);
        
        foreach ($_POST['student_ids'] as $student_id) {
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        $stmt->close();
        
        if ($success_count > 0) {
            $success_message = "$success_count student(s) updated successfully!";
            // Redirect to refresh the page with updated data
            $redirect_url = "?class_id=" . $selected_class . "&section=" . $selected_section;
            header("Location: " . $redirect_url);
            exit();
        }
        if ($error_count > 0) {
            $error_message = "$error_count student(s) failed to update.";
        }
    } else {
        $error_message = "No students selected for update.";
    }
}

// Handle individual section update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_section'])) {
    $student_id = $_POST['student_id'];
    $section = $_POST['section'];
    
    // Validate section value
    if (!in_array($section, ['boarding', 'day'])) {
        $error_message = "Invalid section value. Must be either 'boarding' or 'day'.";
    } else {
        $update_query = "UPDATE students SET section = ? WHERE id = ? AND school_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sii", $section, $student_id, $school_id);
        
        if ($stmt->execute()) {
            $success_message = "Student section updated successfully!";
            // Double check if the update was actually applied
            $verify_query = "SELECT section FROM students WHERE id = ? AND school_id = ?";
            $verify_stmt = $conn->prepare($verify_query);
            $verify_stmt->bind_param("ii", $student_id, $school_id);
            $verify_stmt->execute();
            $result = $verify_stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if ($row['section'] !== $section) {
                    $error_message = "Update failed: Section not saved correctly.";
                }
            }
            $verify_stmt->close();
        } else {
            $error_message = "Error updating student section.";
        }
        $stmt->close();
    }
}

// Get class name if class is selected
$class_name = '';
if ($selected_class) {
    $class_name_query = "SELECT name FROM classes WHERE id = ? AND school_id = ?";
    $stmt = $conn->prepare($class_name_query);
    $stmt->bind_param("ii", $selected_class, $school_id);
    $stmt->execute();
    $class_result = $stmt->get_result();
    if ($class_row = $class_result->fetch_assoc()) {
        $class_name = $class_row['name'];
    }
    $stmt->close();
}

// Fetch all classes for the school
$classes_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY id";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$stmt->close();

// Fetch students if class and section are selected
$students_result = null;
if ($selected_class && $selected_section) {
    $students_query = "SELECT s.* FROM students s 
                      WHERE s.school_id = ? 
                      AND s.class_id = ?
                      AND (s.section = ? OR s.section IS NULL)
                      ORDER BY s.firstname, s.lastname";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("iis", $school_id, $selected_class, $selected_section);
    $stmt->execute();
    $students_result = $stmt->get_result();
    $stmt->close();
}

// Get student counts for each class
$class_counts = [];
if (!$selected_class) {
    $count_query = "SELECT 
                        c.id,
                        COUNT(s.id) as total_students,
                        SUM(CASE WHEN s.section = 'boarding' THEN 1 ELSE 0 END) as boarding_count,
                        SUM(CASE WHEN s.section = 'day' THEN 1 ELSE 0 END) as day_count,
                        SUM(CASE WHEN s.section IS NULL THEN 1 ELSE 0 END) as unassigned_count
                    FROM classes c
                    LEFT JOIN students s ON c.id = s.class_id AND s.school_id = c.school_id
                    WHERE c.school_id = ?
                    GROUP BY c.id";
    $stmt = $conn->prepare($count_query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $count_result = $stmt->get_result();
    while ($row = $count_result->fetch_assoc()) {
        $class_counts[$row['id']] = $row;
    }
    $stmt->close();
}

// Get counts for the section cards when a class is selected
$counts = null;
if ($selected_class && !$selected_section) {
    $count_query = "SELECT 
                        COUNT(*) as total_students,
                        SUM(CASE WHEN section = 'boarding' THEN 1 ELSE 0 END) as boarding_count,
                        SUM(CASE WHEN section = 'day' THEN 1 ELSE 0 END) as day_count,
                        SUM(CASE WHEN section IS NULL THEN 1 ELSE 0 END) as unassigned_count
                    FROM students 
                    WHERE school_id = ? AND class_id = ?";
    $stmt = $conn->prepare($count_query);
    $stmt->bind_param("ii", $school_id, $selected_class);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Section Management - <?php echo htmlspecialchars($school_name); ?></title>
    <!-- TailwindCSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts - Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.js"></script>
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        /* Font weight adjustments */
        .font-semibold {
            font-weight: 600 !important;
        }
        .font-medium {
            font-weight: 500 !important;
        }
        /* Text size adjustments */
        .text-2xl {
            font-size: 1.5rem;
            line-height: 2rem;
            font-weight: 600;
        }
        .text-xl {
            font-size: 1.25rem;
            line-height: 1.75rem;
            font-weight: 500;
        }
        .text-lg {
            font-size: 1.125rem;
            line-height: 1.75rem;
        }
        .text-base {
            font-size: 1rem;
            line-height: 1.5rem;
        }
        .text-sm {
            font-size: 0.875rem;
            line-height: 1.25rem;
        }
        .text-xs {
            font-size: 0.75rem;
            line-height: 1rem;
        }
        /* DataTables customization */
        .dataTables_wrapper {
            font-family: 'Poppins', sans-serif !important;
            font-size: 0.875rem;
        }
        .dataTables_filter input {
            font-family: 'Poppins', sans-serif !important;
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            padding: 0.375rem 0.75rem;
        }
        .dataTables_length select {
            font-family: 'Poppins', sans-serif !important;
            border-radius: 0.375rem;
            border: 1px solid #d1d5db;
            padding: 0.375rem 1.5rem 0.375rem 0.75rem;
        }
        /* Card and button styles */
        .hover\:border-green-500:hover {
            transition: all 0.2s ease;
        }
        .hover\:bg-green-50:hover {
            transition: all 0.2s ease;
        }
        .hover\:shadow-lg:hover {
            transition: all 0.2s ease;
        }
        /* Form elements */
        select, button {
            font-family: 'Poppins', sans-serif !important;
        }
        /* Alert messages */
        [role="alert"] {
            font-family: 'Poppins', sans-serif;
            font-weight: 400;
        }
        /* Navigation and breadcrumbs */
        .breadcrumb {
            font-weight: 400;
        }
        /* Status badges */
        .rounded-full {
            font-weight: 500;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Top Navigation Bar -->
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="flex items-center justify-between px-8 py-5">
                <div class="flex items-center space-x-4">
                    <a href="bursar_dashboard.php" class="flex items-center text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Dashboard
                    </a>
                    <h1 class="text-2xl font-bold text-gray-800 ml-4">
                        Student Section Management
                        <span class="text-lg font-normal text-gray-500 ml-2">Boarding & Day Students</span>
                    </h1>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="container mx-auto px-4 py-8">
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <!-- Breadcrumb Navigation -->
            <div class="flex items-center space-x-2 text-sm text-gray-600 mb-6">
                <span>
                    <i class="fas fa-home"></i>
                    Dashboard
                </span>
                <span>›</span>
                <span>Student Sections</span>
                <?php if ($selected_class): ?>
                    <span>›</span>
                    <span><?php echo htmlspecialchars($class_name); ?></span>
                <?php endif; ?>
                <?php if ($selected_section): ?>
                    <span>›</span>
                    <span><?php echo ucfirst(htmlspecialchars($selected_section)); ?> Section</span>
                <?php endif; ?>
            </div>

            <!-- Class Selection -->
            <?php if (!$selected_class): ?>
                <div class="bg-white rounded-lg shadow-sm p-6 mb-8">
                    <h2 class="text-2xl font-semibold mb-6">Select a Class</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php while ($class = $classes_result->fetch_assoc()): 
                            $counts = $class_counts[$class['id']] ?? [
                                'total_students' => 0,
                                'boarding_count' => 0,
                                'day_count' => 0,
                                'unassigned_count' => 0
                            ];
                        ?>
                            <a href="?class_id=<?php echo $class['id']; ?>" 
                               class="block bg-white rounded-xl border border-gray-200 hover:border-green-500 hover:bg-green-50 hover:shadow-lg transition-all duration-200">
                                <div class="p-6">
                                    <div class="flex items-center justify-between mb-4">
                                        <h3 class="text-xl font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($class['name']); ?>
                                        </h3>
                                        <span class="bg-green-100 text-green-800 text-sm font-medium px-3 py-1 rounded-full">
                                            <?php echo $counts['total_students']; ?> Students
                                        </span>
                                    </div>
                                    
                                    <div class="space-y-3">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-600">
                                                <i class="fas fa-bed text-blue-600 mr-2"></i>
                                                Boarding Students
                                            </span>
                                            <span class="font-medium text-blue-600">
                                                <?php echo $counts['boarding_count']; ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-600">
                                                <i class="fas fa-sun text-green-600 mr-2"></i>
                                                Day Students
                                            </span>
                                            <span class="font-medium text-green-600">
                                                <?php echo $counts['day_count']; ?>
                                            </span>
                                        </div>
                                        <?php if ($counts['unassigned_count'] > 0): ?>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-gray-600">
                                                <i class="fas fa-question-circle text-gray-400 mr-2"></i>
                                                Unassigned
                                            </span>
                                            <span class="font-medium text-gray-600">
                                                <?php echo $counts['unassigned_count']; ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between">
                                        <span class="text-sm text-green-600">Click to manage sections</span>
                                        <i class="fas fa-chevron-right text-green-600"></i>
                                    </div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>
            
            <!-- Section Selection -->
            <?php elseif ($selected_class && !$selected_section): ?>
                <div class="bg-white rounded-lg shadow-sm p-6 mb-8">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-semibold">Select Section for <?php echo htmlspecialchars($class_name); ?></h2>
                        <a href="?" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Back to Classes
                        </a>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <a href="?class_id=<?php echo $selected_class; ?>&section=boarding" 
                           class="flex flex-col items-center p-8 bg-blue-50 rounded-xl hover:bg-blue-100 transition-colors duration-150">
                            <i class="fas fa-bed text-4xl text-blue-600 mb-4"></i>
                            <span class="text-xl font-medium text-blue-800">Boarding Section</span>
                            <p class="text-blue-600 mt-2">
                                <?php echo ($counts['boarding_count'] ?? 0) . ' students assigned'; ?>
                            </p>
                            <?php if (($counts['unassigned_count'] ?? 0) > 0): ?>
                                <p class="text-gray-500 mt-1">
                                    <?php echo $counts['unassigned_count'] . ' unassigned students available'; ?>
                                </p>
                            <?php endif; ?>
                        </a>
                        <a href="?class_id=<?php echo $selected_class; ?>&section=day" 
                           class="flex flex-col items-center p-8 bg-green-50 rounded-xl hover:bg-green-100 transition-colors duration-150">
                            <i class="fas fa-sun text-4xl text-green-600 mb-4"></i>
                            <span class="text-xl font-medium text-green-800">Day Section</span>
                            <p class="text-green-600 mt-2">
                                <?php echo ($counts['day_count'] ?? 0) . ' students assigned'; ?>
                            </p>
                            <?php if (($counts['unassigned_count'] ?? 0) > 0): ?>
                                <p class="text-gray-500 mt-1">
                                    <?php echo $counts['unassigned_count'] . ' unassigned students available'; ?>
                                </p>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

            <!-- Students Management -->
            <?php elseif ($selected_class && $selected_section): ?>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-semibold">
                            Managing <?php echo ucfirst(htmlspecialchars($selected_section)); ?> Students in <?php echo htmlspecialchars($class_name); ?>
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                (Showing <?php echo ucfirst($selected_section); ?> and Unassigned Students)
                            </span>
                        </h2>
                        <a href="?class_id=<?php echo $selected_class; ?>" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Back to Sections
                        </a>
                    </div>

                    <form method="POST" id="bulkUpdateForm" class="mb-8" onsubmit="return validateBulkForm()">
                        <input type="hidden" name="section" value="<?php echo htmlspecialchars($selected_section); ?>">
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <button type="button" id="selectAll" class="text-sm text-blue-600 hover:text-blue-800">Select All</button>
                                    <span class="text-gray-400">|</span>
                                    <button type="button" id="deselectAll" class="text-sm text-blue-600 hover:text-blue-800">Deselect All</button>
                                </div>
                                <button type="submit" name="bulk_update_section" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Update Selected Students to <?php echo ucfirst(htmlspecialchars($selected_section)); ?>
                                </button>
                            </div>
                        </div>
                    
                        <table id="studentsTable" class="w-full mt-4">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Select</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Section</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Individual Update</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if ($students_result): while ($student = $students_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" 
                                                   class="student-checkbox rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                            $section = $student['section'] ?? 'Not Set';
                                            $section_class = $section === 'boarding' ? 'bg-blue-100 text-blue-800' : ($section === 'day' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800');
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $section_class; ?>">
                                                <?php echo ucfirst(htmlspecialchars($section)); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <form method="POST" class="inline-flex space-x-2">
                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                <select name="section" class="rounded-md border-gray-300 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                                    <option value="">Select Section</option>
                                                    <option value="boarding" <?php echo ($student['section'] ?? '') === 'boarding' ? 'selected' : ''; ?>>Boarding</option>
                                                    <option value="day" <?php echo ($student['section'] ?? '') === 'day' ? 'selected' : ''; ?>>Day</option>
                                                </select>
                                                <button type="submit" name="update_section" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                    Update
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            var table = null;
            if ($('#studentsTable').length) {
                table = $('#studentsTable').DataTable({
                    "pageLength": 25,
                    "order": [[1, "asc"]], // Sort by name
                    "language": {
                        "search": "Search students:"
                    }
                });
            }

            // Handle select all functionality
            $('#selectAll').click(function() {
                $('.student-checkbox').prop('checked', true);
            });

            $('#deselectAll').click(function() {
                $('.student-checkbox').prop('checked', false);
            });
        });

        // Form validation function
        function validateBulkForm() {
            var checkedBoxes = $('.student-checkbox:checked').length;
            
            if (checkedBoxes === 0) {
                alert('Please select at least one student to update.');
                return false;
            }
            
            return true;
        }
    </script>
</body>
</html>
<?php
// Close the database connection at the very end
$conn->close();
?>