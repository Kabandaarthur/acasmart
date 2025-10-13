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

$message = '';
$messageType = '';                                        

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_class':
                // Convert class name to uppercase before processing
                $name = strtoupper($conn->real_escape_string($_POST['class_name']));
                $school_id = intval($_SESSION['school_id']);
                $code = $conn->real_escape_string($_POST['class_code']);
                $description = $conn->real_escape_string($_POST['class_description']);
                
                // Check if class already exists for this school
                $check_sql = "SELECT id FROM classes WHERE school_id = ? AND name = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("is", $school_id, $name);
                $check_stmt->execute();
                $result = $check_stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $message = "Error: Class '$name' is already registered for this school";
                    $messageType = "error";
                } else {
                    // Proceed with insertion
                    $sql = "INSERT INTO classes (school_id, name, code, description) VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isss", $school_id, $name, $code, $description);
                    
                    if ($stmt->execute()) {
                        $message = "New class added successfully";
                        $messageType = "success";
                    } else {
                        $message = "Error: " . $stmt->error;
                        $messageType = "error";
                    }
                    $stmt->close();
                }
                $check_stmt->close();
                break;

            case 'add_subject':
                $name = $conn->real_escape_string($_POST['subject_name']);
                $subject_code = $conn->real_escape_string($_POST['subject_code']);
                $school_id = intval($_SESSION['school_id']);
                $class_id = intval($_POST['class_id']);
                
                $sql = "INSERT INTO subjects (school_id, class_id, subject_name, subject_code) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiss", $school_id, $class_id, $name, $subject_code);
                
                if ($stmt->execute()) {
                    $message = "New subject added successfully";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $stmt->error;
                    $messageType = "error";
                }
                $stmt->close();
                break;

            case 'edit_subject':
                $subject_id = intval($_POST['subject_id']);
                $name = $conn->real_escape_string($_POST['subject_name']);
                $subject_code = $conn->real_escape_string($_POST['subject_code']);
                $class_id = intval($_POST['class_id']);
                
                $sql = "UPDATE subjects SET subject_name = ?, subject_code = ?, class_id = ? WHERE subject_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssii", $name, $subject_code, $class_id, $subject_id);
                
                if ($stmt->execute()) {
                    $message = "Subject updated successfully";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $stmt->error;
                    $messageType = "error";
                }
                $stmt->close();
                break;

            case 'assign_teacher':
                $teacher_id = intval($_POST['teacher_id']);
                $class_id = intval($_POST['class_id']);
                $is_class_teacher = isset($_POST['is_class_teacher']) ? 1 : 0;
                $school_id = intval($_SESSION['school_id']);
                
                // Start a transaction
                $conn->begin_transaction();
                
                try {
                    if ($is_class_teacher) {
                        // Get current class teacher's ID before removing their assignments
                        $current_ct_sql = "SELECT user_id FROM teacher_subjects 
                                         WHERE class_id = ? AND is_class_teacher = 1 
                                         LIMIT 1";
                        $current_ct_stmt = $conn->prepare($current_ct_sql);
                        $current_ct_stmt->bind_param("i", $class_id);
                        $current_ct_stmt->execute();
                        $current_ct_result = $current_ct_stmt->get_result();
                        $old_class_teacher = $current_ct_result->fetch_assoc();
                        
                        if ($old_class_teacher) {
                            // Remove all assignments for the old class teacher in this class
                            $delete_old_ct_sql = "DELETE FROM teacher_subjects 
                                                WHERE user_id = ? AND class_id = ?";
                            $delete_old_ct_stmt = $conn->prepare($delete_old_ct_sql);
                            $delete_old_ct_stmt->bind_param("ii", $old_class_teacher['user_id'], $class_id);
                            $delete_old_ct_stmt->execute();
                        }

                        // Remove any existing assignments for the new class teacher in this class
                        $delete_sql = "DELETE FROM teacher_subjects 
                                      WHERE user_id = ? AND class_id = ?";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("ii", $teacher_id, $class_id);
                        $delete_stmt->execute();

                        // Get all subjects for this class
                        $subjects_sql = "SELECT subject_id FROM subjects WHERE class_id = ?";
                        $subjects_stmt = $conn->prepare($subjects_sql);
                        $subjects_stmt->bind_param("i", $class_id);
                        $subjects_stmt->execute();
                        $subjects_result = $subjects_stmt->get_result();

                        // Assign the new teacher to all subjects as class teacher
                        while ($subject = $subjects_result->fetch_assoc()) {
                            $insert_sql = "INSERT INTO teacher_subjects 
                                         (user_id, subject_id, class_id, is_class_teacher) 
                                         VALUES (?, ?, ?, 1)";
                            $insert_stmt = $conn->prepare($insert_sql);
                            $insert_stmt->bind_param("iii", $teacher_id, $subject['subject_id'], $class_id);
                            $insert_stmt->execute();
                        }

                        $message = "Class teacher changed successfully. Previous class teacher's assignments have been removed.";
                        $messageType = "success";
                    } else {
                        // Regular subject teacher assignment
                        $subject_id = intval($_POST['subject_id']);
                        
                        // Check if this exact assignment already exists
                        $check_sql = "SELECT * FROM teacher_subjects 
                                    WHERE subject_id = ? AND class_id = ? AND user_id = ? AND is_class_teacher = 0";
                        $check_stmt = $conn->prepare($check_sql);
                        $check_stmt->bind_param("iii", $subject_id, $class_id, $teacher_id);
                        $check_stmt->execute();
                        $result = $check_stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            throw new Exception("This teacher is already assigned to this subject");
                        }
                        
                        // Insert the new assignment
                        $insert_sql = "INSERT INTO teacher_subjects 
                                     (user_id, subject_id, class_id, is_class_teacher) 
                                     VALUES (?, ?, ?, 0)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $insert_stmt->bind_param("iii", $teacher_id, $subject_id, $class_id);
                        
                        if (!$insert_stmt->execute()) {
                            throw new Exception("Failed to assign teacher");
                        }
                        
                        $message = "Teacher assigned successfully";
                        $messageType = "success";
                    }
                    
                    $conn->commit();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $message = "Error: " . $e->getMessage();
                    $messageType = "error";
                }
                break;

            case 'delete_assignment':
                $assignment_id = intval($_POST['assignment_id']);
                
                $sql = "DELETE FROM teacher_subjects WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $assignment_id);
                
                if ($stmt->execute()) {
                    $message = "Assignment deleted successfully";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $stmt->error;
                    $messageType = "error";
                }
                $stmt->close();
                break;

            case 'edit_assignment':
                $assignment_id = intval($_POST['assignment_id']);
                $teacher_id = intval($_POST['teacher_id']);
                $is_class_teacher = isset($_POST['is_class_teacher']) ? 1 : 0;
                
                if ($is_class_teacher) {
                    // Get class_id for the assignment being edited
                    $check_sql = "SELECT class_id, subject_id FROM teacher_subjects WHERE id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("i", $assignment_id);
                    $check_stmt->execute();
                    $result = $check_stmt->get_result();
                    $current = $result->fetch_assoc();
                    
                    // Remove existing class teacher assignments for this class
                    $delete_sql = "DELETE FROM teacher_subjects WHERE class_id = ? AND is_class_teacher = 1";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bind_param("i", $current['class_id']);
                    $delete_stmt->execute();
                    
                    // Get all subjects for this class
                    $subjects_sql = "SELECT subject_id FROM subjects WHERE class_id = ?";
                    $subjects_stmt = $conn->prepare($subjects_sql);
                    $subjects_stmt->bind_param("i", $current['class_id']);
                    $subjects_stmt->execute();
                    $subjects_result = $subjects_stmt->get_result();

                    // Assign the teacher to all subjects as class teacher
                    while ($subject = $subjects_result->fetch_assoc()) {
                        $insert_sql = "INSERT INTO teacher_subjects 
                                     (user_id, subject_id, class_id, is_class_teacher) 
                                     VALUES (?, ?, ?, 1)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $insert_stmt->bind_param("iii", $teacher_id, $subject['subject_id'], $current['class_id']);
                        $insert_stmt->execute();
                    }
                    
                    $message = "Class teacher assignment updated successfully";
                    $messageType = "success";
                } else {
                    // Regular subject teacher assignment update
                    $sql = "UPDATE teacher_subjects SET user_id = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ii", $teacher_id, $assignment_id);
                    
                    if ($stmt->execute()) {
                        $message = "Assignment updated successfully";
                        $messageType = "success";
                    } else {
                        $message = "Error: " . $stmt->error;
                        $messageType = "error";
                    }
                    $stmt->close();
                }
                break;

            case 'delete_subject':
                $subject_id = intval($_POST['subject_id']);
                
                // First delete related records from teacher_subjects
                $sql = "DELETE FROM teacher_subjects WHERE subject_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $subject_id);
                $stmt->execute();
                
                // Then delete the subject
                $sql = "DELETE FROM subjects WHERE subject_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $subject_id);
                
                if ($stmt->execute()) {
                    $message = "Subject deleted successfully";
                    $messageType = "success";
                } else {
                    $message = "Error: " . $stmt->error;
                    $messageType = "error";
                }
                $stmt->close();
                break;
        }
    }
}

// Fetch all classes, subjects, and teachers for the current school
$school_id = intval($_SESSION['school_id']);
$classes = $conn->query("SELECT * FROM classes WHERE school_id = $school_id ORDER BY id ASC");
$subjects = $conn->query("SELECT * FROM subjects WHERE school_id = $school_id ORDER BY subject_name");
$teachers = $conn->query("SELECT * FROM users WHERE (role = 'teacher' OR role = 'admin') AND school_id = $school_id ORDER BY username");                             
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes, Subjects, and Teachers - School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Add Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Enhanced Header Section -->
    <nav class="bg-gradient-to-r from-blue-800 to-blue-600 p-6 shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold text-white">Subject Management</h1>
                <p class="text-blue-100 mt-1">Manage classes, subjects, and teacher assignments</p>
            </div>
            <a href="school_admin_dashboard.php" 
               class="flex items-center bg-white text-blue-800 px-4 py-2 rounded-lg hover:bg-blue-50 transition-all duration-300 shadow-md">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>
    </nav>

    <!-- Enhanced Form Cards Grid -->
    <div class="container mx-auto mt-8 px-4">
        <?php if ($message): ?>
            <div class="animate-fade-in mb-6">
                <div class="<?php echo $messageType === 'success' ? 'bg-green-100 border-l-4 border-green-500' : 'bg-red-100 border-l-4 border-red-500'; ?> p-4 rounded-r-lg shadow-sm">
                    <div class="flex items-center">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?> mr-3 text-<?php echo $messageType === 'success' ? 'green-500' : 'red-500'; ?>"></i>
                        <p class="<?php echo $messageType === 'success' ? 'text-green-700' : 'text-red-700'; ?> font-medium">
                            <?php echo $message; ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Add Class Card -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden transform transition-all duration-300 hover:shadow-xl">
                <div class="bg-gradient-to-r from-blue-800 to-blue-600 px-6 py-4">
                    <h2 class="text-xl font-semibold text-white flex items-center">
                        <i class="fas fa-school mr-2"></i> Add New Class
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_class">
                        <div>
                            <label for="class_name" class="block text-sm font-medium text-gray-700 mb-2">Class Name</label>
                            <select id="class_name" name="class_name" 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300">
                                <option value="">Select a class</option>
                                <option value="Senior One">SENIOR ONE</option>
                                <option value="Senior Two">SENIOR TWO</option>
                                <option value="Senior Three">SENIOR THREE</option>
                                <option value="Senior Four">SENIOR FOUR</option>
                                <option value="Senior Five">SENIOR FIVE</option>
                                <option value="Senior Six">SENIOR SIX</option>
                            </select>
                        </div>
                        <div>
                            <label for="class_code" class="block text-sm font-medium text-gray-700 mb-2">Class Code</label>
                            <input type="text" id="class_code" name="class_code" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300">
                        </div>
                        <div>
                            <label for="class_description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                            <textarea id="class_description" name="class_description" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300" rows="3"></textarea>
                        </div>
                        <button type="submit" 
                                class="w-full bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700 transition-all duration-300 shadow-md flex items-center justify-center">
                            <i class="fas fa-plus mr-2"></i> Add Class
                        </button>
                    </form>
                </div>
            </div>

            <!-- Add Subject Card (similar styling) -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden transform transition-all duration-300 hover:shadow-xl">
                <div class="bg-gradient-to-r from-green-800 to-green-600 px-6 py-4">
                    <h2 class="text-xl font-semibold text-white flex items-center">
                        <i class="fas fa-book mr-2"></i> Add New Subject
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_subject">
                        <div>
                            <label for="subject_name" class="block text-sm font-medium text-gray-700 mb-2">Subject Name</label>
                            <input type="text" id="subject_name" name="subject_name" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all duration-300">
                        </div>
                        <div>
                            <label for="subject_code" class="block text-sm font-medium text-gray-700 mb-2">Subject Code</label>
                            <input type="text" id="subject_code" name="subject_code" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all duration-300">
                        </div>
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700 mb-2">Class</label>
                            <select name="class_id" id="class_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all duration-300">
                                <?php 
                                $classes->data_seek(0);
                                while ($class = $classes->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <button type="submit" 
                                class="w-full bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 transition-all duration-300 shadow-md flex items-center justify-center">
                            <i class="fas fa-plus mr-2"></i> Add Subject
                        </button>
                    </form>
                </div>
            </div>

            <!-- Assign Teacher Card (similar styling) -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden transform transition-all duration-300 hover:shadow-xl">
                <div class="bg-gradient-to-r from-purple-800 to-purple-600 px-6 py-4">
                    <h2 class="text-xl font-semibold text-white flex items-center">
                        <i class="fas fa-chalkboard-teacher mr-2"></i> Assign Teacher
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="assign_teacher">
                        <div>
                            <label for="teacher_id" class="block text-sm font-medium text-gray-700 mb-2">Teacher/Admin</label>
                            <select name="teacher_id" id="teacher_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all duration-300">
                                <?php 
                                $teachers->data_seek(0);
                                while ($teacher = $teachers->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $teacher['user_id']; ?>">
                                        <?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label for="subject_id" class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                            <select name="subject_id" id="subject_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all duration-300">
                                <?php 
                                $subjects->data_seek(0);
                                while ($subject = $subjects->fetch_assoc()):
                                    // Fetch the class name for this subject
                                    $class_query = $conn->prepare("SELECT name FROM classes WHERE id = ?");
                                    $class_query->bind_param("i", $subject['class_id']);
                                    $class_query->execute();
                                    $class_result = $class_query->get_result();
                                    $class_name = $class_result->fetch_assoc()['name'];
                                ?>
                                    <option value="<?php echo $subject['subject_id']; ?>">
                                        <?php echo htmlspecialchars($subject['subject_name']); ?> 
                                        (<?php echo htmlspecialchars($class_name); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700 mb-2">Class</label>
                            <select name="class_id" id="class_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all duration-300">
                                <?php 
                                $classes->data_seek(0);
                                while ($class = $classes->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_class_teacher" class="form-checkbox">
                                <span class="ml-2">Assign as Class Teacher</span>
                            </label>
                        </div>
                        <button type="submit" 
                                class="w-full bg-purple-600 text-white px-6 py-3 rounded-lg hover:bg-purple-700 transition-all duration-300 shadow-md flex items-center justify-center">
                            <i class="fas fa-plus mr-2"></i> Assign Teacher
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Enhanced Classes Overview Section -->
        <div class="mt-12 bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="bg-gradient-to-r from-gray-800 to-gray-700 px-6 py-4">
                <h2 class="text-2xl font-bold text-white flex items-center">
                    <i class="fas fa-school mr-2"></i> Classes Overview
                </h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php
                    $classes->data_seek(0);
                    while ($class = $classes->fetch_assoc()):
                        $class_id = $class['id'];
                        $class_subjects = $conn->query("SELECT 
                            s.subject_id, 
                            s.subject_name, 
                            s.subject_code, 
                            s.class_id,
                            GROUP_CONCAT(
                                DISTINCT CASE 
                                    WHEN ts.is_class_teacher = 0 THEN 
                                        CONCAT(ts.id, ':', u.user_id, ':', u.firstname, ' ', u.lastname) 
                                    END
                                SEPARATOR '||'
                            ) as teacher_assignments,
                            MAX(CASE WHEN ts.is_class_teacher = 1 THEN 1 ELSE 0 END) as has_class_teacher
                            FROM subjects s 
                            LEFT JOIN teacher_subjects ts ON s.subject_id = ts.subject_id AND ts.class_id = $class_id
                            LEFT JOIN users u ON ts.user_id = u.user_id
                            WHERE s.class_id = $class_id
                            GROUP BY s.subject_id, s.subject_name, s.subject_code, s.class_id");
                        
                        // Get class teacher
                        $class_teacher_query = $conn->query("SELECT CONCAT(u.firstname, ' ', u.lastname) as teacher_name 
                            FROM teacher_subjects ts 
                            JOIN users u ON ts.user_id = u.user_id 
                            WHERE ts.class_id = $class_id AND ts.is_class_teacher = 1");
                        $class_teacher = $class_teacher_query->fetch_assoc();
                    ?>
                        <div class="bg-gray-50 rounded-lg p-4 shadow-sm hover:shadow-md transition duration-300">
                            <h3 class="text-xl font-semibold mb-2 text-blue-600">
                                <?php echo htmlspecialchars($class['name']); ?>
                                <span class="text-sm font-normal text-gray-600">(<?php echo htmlspecialchars($class['code']); ?>)</span>
                            </h3>
                            <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($class['description']); ?></p>
                            
                            <!-- Display Class Teacher -->
                            <div class="mb-4 p-2 bg-blue-50 rounded">
                                <div class="flex justify-between items-center">
                                    <h4 class="font-medium text-blue-700">Class Teacher:</h4>
                                    <button onclick="openChangeClassTeacherModal(<?php echo $class_id; ?>, '<?php echo htmlspecialchars($class['name']); ?>')" 
                                            class="text-blue-600 hover:text-blue-800 flex items-center text-sm">
                                        <i class="fas fa-exchange-alt mr-1"></i> Change
                                    </button>
                                </div>
                                <p class="text-blue-600 mt-2">
                                    <?php echo $class_teacher ? htmlspecialchars($class_teacher['teacher_name']) : 'Not assigned'; ?>
                                </p>
                            </div>

                            <div class="mt-4">
                                <h4 class="text-lg font-medium mb-2 text-gray-700">Subjects and Teachers:</h4>
                                <?php if ($class_subjects->num_rows > 0): ?>
                                    <ul class="space-y-4">
                                        <?php while ($subject = $class_subjects->fetch_assoc()): 
                                            $teacher_assignments = [];
                                            if ($subject['teacher_assignments']) {
                                                $assignments = explode('||', $subject['teacher_assignments']);
                                                foreach ($assignments as $assignment) {
                                                    if ($assignment) { // Check if assignment is not empty
                                                        list($assignment_id, $teacher_id, $teacher_name) = explode(':', $assignment);
                                                        $teacher_assignments[] = [
                                                            'assignment_id' => $assignment_id,
                                                            'teacher_id' => $teacher_id,
                                                            'teacher_name' => $teacher_name
                                                        ];
                                                    }
                                                }
                                            }
                                        ?>
                                            <li class="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                                                <!-- Subject Header -->
                                                <div class="flex items-center justify-between border-b border-gray-200 pb-3 mb-3">
                                                    <div>
                                                        <h5 class="text-lg font-semibold text-gray-800">
                                                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                            <span class="text-sm text-gray-500 ml-2">(<?php echo htmlspecialchars($subject['subject_code']); ?>)</span>
                                                        </h5>
                                                    </div>
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="openEditSubjectModal(<?php echo $subject['subject_id']; ?>, '<?php echo addslashes($subject['subject_name']); ?>', '<?php echo addslashes($subject['subject_code']); ?>', <?php echo $subject['class_id']; ?>)" 
                                                                class="p-1 text-green-600 hover:text-green-800 transition-colors" 
                                                                title="Edit Subject">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="confirmDeleteSubject(<?php echo $subject['subject_id']; ?>, '<?php echo addslashes($subject['subject_name']); ?>')" 
                                                                class="p-1 text-red-600 hover:text-red-800 transition-colors"
                                                                title="Delete Subject">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Teachers List -->
                                                <div class="space-y-2">
                                                    <?php if (!empty($teacher_assignments)): ?>
                                                        <?php foreach ($teacher_assignments as $assignment): ?>
                                                            <div class="flex items-center justify-between py-2 px-3 bg-gray-50 rounded-lg">
                                                                <div class="flex items-center">
                                                                    <i class="fas fa-user-tie text-gray-500 mr-2"></i>
                                                                    <span class="text-gray-700">
                                                                        <?php echo htmlspecialchars($assignment['teacher_name']); ?>
                                                                    </span>
                                                                </div>
                                                                <div class="flex items-center space-x-2">
                                                                    <button onclick="openEditModal(<?php echo $assignment['assignment_id']; ?>, <?php echo $assignment['teacher_id']; ?>, false)" 
                                                                            class="p-1 text-blue-600 hover:text-blue-800 transition-colors"
                                                                            title="Change Teacher">
                                                                        <i class="fas fa-exchange-alt"></i>
                                                                    </button>
                                                                    <button onclick="confirmDeleteTeacher(<?php echo $assignment['assignment_id']; ?>)" 
                                                                            class="p-1 text-red-600 hover:text-red-800 transition-colors"
                                                                            title="Remove Teacher">
                                                                        <i class="fas fa-user-minus"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <?php if ($subject['has_class_teacher']): ?>
                                                            <div class="flex items-center justify-center py-3 text-blue-600">
                                                                <i class="fas fa-info-circle mr-2"></i>
                                                                <span class="text-sm">Taught by Class Teacher</span>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="flex items-center justify-center py-3 text-gray-500">
                                                                <span class="text-sm italic">No additional teachers assigned</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endwhile; ?>
                                    </ul>
                                <?php else: ?>
                                    <div class="flex items-center justify-center p-4 text-gray-500">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <span class="italic">No subjects assigned yet</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Edit Teacher Assignment</h3>
                <form id="editForm" method="POST" class="mt-2">
                    <input type="hidden" name="action" value="edit_assignment">
                    <input type="hidden" id="assignmentId" name="assignment_id" value="">
                    <div class="mt-2">
                        <select name="teacher_id" id="teacherSelect" class="w-full p-2 border rounded" required>
                            <?php 
                            $teachers->data_seek(0);
                            while ($teacher = $teachers->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $teacher['user_id']; ?>">
                                    <?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mt-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="is_class_teacher" id="editClassTeacher" class="form-checkbox">
                            <span class="ml-2">Assign as Class Teacher</span>
                        </label>
                    </div>
                    <div class="items-center px-4 py-3">
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-300">
                            Update Assignment
                        </button>
                    </div>
                </form>
                <button onclick="closeEditModal()" class="mt-3 px-4 py-2 bg-gray-300 text-gray-700 text-base font-medium rounded-md w-full shadow-sm hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editSubjectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Edit Subject</h3>
                <form id="editSubjectForm" method="POST" class="mt-2">
                    <input type="hidden" name="action" value="edit_subject">
                    <input type="hidden" id="editSubjectId" name="subject_id" value="">
                    <div class="mt-2">
                        <label for="editSubjectName" class="block text-left text-sm font-medium text-gray-700">Subject Name</label>
                        <input type="text" id="editSubjectName" name="subject_name" class="w-full p-2 border rounded" required>
                    </div>
                    <div class="mt-2">
                        <label for="editSubjectCode" class="block text-left text-sm font-medium text-gray-700">Subject Code</label>
                        <input type="text" id="editSubjectCode" name="subject_code" class="w-full p-2 border rounded" required>
                    </div>
                    <div class="mt-2">
                        <label for="editSubjectClass" class="block text-left text-sm font-medium text-gray-700">Class</label>
                        <select name="class_id" id="editSubjectClass" class="w-full p-2 border rounded" required>
                            <?php 
                            $classes->data_seek(0);
                            while ($class = $classes->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="items-center px-4 py-3">
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-300">
                            Update Subject
                        </button>
                    </div>
                </form>
                <button onclick="closeEditSubjectModal()" class="mt-3 px-4 py-2 bg-gray-300 text-gray-700 text-base font-medium rounded-md w-full shadow-sm hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-300">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add this Delete Subject Confirmation Modal -->
<div id="deleteSubjectModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900">Delete Subject</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">Are you sure you want to delete this subject? This action cannot be undone.</p>
                <p id="subjectToDelete" class="text-sm font-semibold mt-2"></p>
            </div>
            <form id="deleteSubjectForm" method="POST">
                <input type="hidden" name="action" value="delete_subject">
                <input type="hidden" id="deleteSubjectId" name="subject_id" value="">
                <div class="items-center px-4 py-3">
                    <button type="submit" class="px-4 py-2 bg-red-500 text-white text-base font-medium rounded-md w-1/3 shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300 mr-2">
                        Delete
                    </button>
                    <button type="button" onclick="closeDeleteSubjectModal()" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md w-1/3 shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add this new modal for changing class teacher (place it before the closing body tag) -->
<div id="changeClassTeacherModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 transform transition-all">
            <div class="text-center">
                <h3 class="text-lg font-semibold text-gray-900 mb-4" id="modalClassTitle">Change Class Teacher</h3>
                <form id="changeClassTeacherForm" method="POST">
                    <input type="hidden" name="action" value="assign_teacher">
                    <input type="hidden" name="is_class_teacher" value="1">
                    <input type="hidden" id="modalClassId" name="class_id" value="">
                    
                    <div class="mb-4">
                        <label for="modalTeacherId" class="block text-left text-sm font-medium text-gray-700 mb-2">Select New Class Teacher</label>
                        <select id="modalTeacherId" name="teacher_id" class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-blue-300 focus:border-blue-300">
                            <?php 
                            $teachers->data_seek(0);
                            while ($teacher = $teachers->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $teacher['user_id']; ?>">
                                    <?php echo htmlspecialchars($teacher['firstname'] . ' ' . $teacher['lastname']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeChangeClassTeacherModal()" 
                                class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- First, add this HTML for the delete confirmation dialog (add it before the closing body tag) -->
<div id="deleteTeacherDialog" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4">
        <div class="text-center">
            <i class="fas fa-user-minus text-red-500 text-4xl mb-4"></i>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Remove Teacher Assignment</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to remove this teacher from the subject?</p>
            
            <div class="flex justify-center space-x-4">
                <button type="button" onclick="closeDeleteTeacherDialog()" 
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors">
                    Cancel
                </button>
                <form id="deleteTeacherForm" method="POST" class="inline">
                    <input type="hidden" name="action" value="delete_assignment">
                    <input type="hidden" id="deleteAssignmentId" name="assignment_id" value="">
                    <button type="submit" 
                            class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-colors">
                        Remove Teacher
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

    <script>
        function openEditModal(assignmentId, teacherId, isClassTeacher) {
            document.getElementById('assignmentId').value = assignmentId;
            document.getElementById('teacherSelect').value = teacherId;
            document.getElementById('editClassTeacher').checked = isClassTeacher;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeEditModal();
            }
        });

        // Confirm before deleting assignments
        document.querySelectorAll('form[action="delete_assignment"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this assignment? This will remove the teacher from all subjects of this class.')) {
                    e.preventDefault();
                }
            });
        });
        function openEditSubjectModal(subjectId, subjectName, subjectCode, classId) {
    document.getElementById('editSubjectId').value = subjectId;
    document.getElementById('editSubjectName').value = subjectName;
    document.getElementById('editSubjectCode').value = subjectCode;
    document.getElementById('editSubjectClass').value = classId;
    document.getElementById('editSubjectModal').classList.remove('hidden');
}

function closeEditSubjectModal() {
    document.getElementById('editSubjectModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('editSubjectModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeEditSubjectModal();
    }
});

// Add these new functions to your existing JavaScript
function confirmDeleteSubject(subjectId, subjectName) {
    document.getElementById('deleteSubjectId').value = subjectId;
    document.getElementById('subjectToDelete').textContent = `Subject: ${subjectName}`;
    document.getElementById('deleteSubjectModal').classList.remove('hidden');
}

function closeDeleteSubjectModal() {
    document.getElementById('deleteSubjectModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('deleteSubjectModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeDeleteSubjectModal();
    }
});

// Add escape key listener to close modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('deleteSubjectModal').classList.contains('hidden')) {
        closeDeleteSubjectModal();
    }
});

// Add these to your existing JavaScript
function openChangeClassTeacherModal(classId, className) {
    document.getElementById('modalClassId').value = classId;
    document.getElementById('modalClassTitle').textContent = `Change Class Teacher - ${className}`;
    document.getElementById('changeClassTeacherModal').classList.remove('hidden');
}

function closeChangeClassTeacherModal() {
    document.getElementById('changeClassTeacherModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('changeClassTeacherModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeChangeClassTeacherModal();
    }
});

// Add escape key listener
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('changeClassTeacherModal').classList.contains('hidden')) {
        closeChangeClassTeacherModal();
    }
});

// Add these new functions to your existing JavaScript
function confirmDeleteTeacher(assignmentId) {
    document.getElementById('deleteAssignmentId').value = assignmentId;
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
    </script>
</body>
</html>


                