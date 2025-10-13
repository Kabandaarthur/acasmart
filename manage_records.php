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

// Get current term
$current_term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get all classes for the school (modified query to sort by ID)
$classes_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY id ASC";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all exam types for the current term with category and max_score
$exams_query = "SELECT exam_id, exam_type, category, max_score 
                FROM exams 
                WHERE school_id = ? AND term_id = ? AND is_active = 1
                ORDER BY created_at DESC";
$stmt = $conn->prepare($exams_query);
$stmt->bind_param("ii", $school_id, $current_term['id']);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Function to get all subjects with their marks status
function getSubjectMarksStatus($conn, $school_id, $class_id, $exam_id, $term_id) {
    // Get subjects that are assigned to this specific exam for this class
    $subjects_query = "SELECT 
        s.subject_id, 
        s.subject_name, 
        s.subject_code,
        s.created_at,
        COUNT(DISTINCT er.student_id) as students_count,
        MAX(er.upload_date) as last_upload,
        AVG(er.score) as average_marks,
        MAX(er.score) as highest_marks,
        MIN(er.score) as lowest_marks,
        CASE 
            WHEN er.exam_id IS NOT NULL THEN 1 
            ELSE 0 
        END as has_marks
    FROM subjects s
    JOIN exam_subjects es ON s.subject_id = es.subject_id 
        AND es.exam_id = ?
        AND es.class_id = ?
    LEFT JOIN exam_results er ON s.subject_id = er.subject_id 
        AND er.exam_id = ? 
        AND er.term_id = ?
    LEFT JOIN students st ON er.student_id = st.id 
        AND st.class_id = ?
    WHERE s.school_id = ? 
    AND s.class_id = ?
    GROUP BY s.subject_id, s.subject_name, s.subject_code, s.created_at
    ORDER BY s.subject_name";

    $stmt = $conn->prepare($subjects_query);
    $stmt->bind_param("iiiiiii", $exam_id, $class_id, $exam_id, $term_id, $class_id, $school_id, $class_id);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Separate subjects into uploaded and missing
    $uploaded = array_filter($subjects, function($subject) {
        return $subject['has_marks'] == 1;
    });

    $missing = array_filter($subjects, function($subject) {
        return $subject['has_marks'] == 0;
    });

    return [
        'uploaded' => array_values($uploaded),
        'missing' => array_values($missing)
    ];
}

// Get selected class and exam from POST
$selected_class = isset($_POST['class_id']) ? $_POST['class_id'] : null;
$selected_exam = isset($_POST['exam_id']) ? $_POST['exam_id'] : null;

// Get the marks status if both class and exam are selected
$marks_status = null;
if ($selected_class && $selected_exam && $current_term) {
    $marks_status = getSubjectMarksStatus($conn, $school_id, $selected_class, $selected_exam, $current_term['id']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Records - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .gradient-custom {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        }
        .card-hover {
            transition: all 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="gradient-custom text-white shadow-lg mb-6 no-print">
            <div class="container mx-auto px-4 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold">Subject Results Check</h1>
                        <p class="text-blue-100">
                            <?php if ($current_term): ?>
                                <?php echo htmlspecialchars($current_term['name'] . ' ' . $current_term['year']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="flex space-x-4">
                        <a href="school_admin_dashboard.php" 
                           class="bg-white text-blue-800 px-4 py-2 rounded-lg hover:bg-blue-50 transition duration-150">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <button onclick="window.print()" 
                                class="bg-white text-blue-800 px-4 py-2 rounded-lg hover:bg-blue-50 transition duration-150">
                            <i class="fas fa-print mr-2"></i>Print Report
                            </button>
                        </div>
                        </div>
                    </div>
                </div>

        <div class="container mx-auto px-4">
                <!-- Selection Form -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-6 no-print">
                <h2 class="text-xl font-bold text-gray-700 mb-4">Select Class and Exam Type</h2>
                <p class="text-sm text-gray-600 mb-4">This will show only subjects that are assigned to the selected exam type and category for the chosen class.</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                        <select name="class_id" class="w-full rounded-lg border-2 border-blue-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 py-3 text-base bg-blue-50 hover:bg-blue-100 transition-colors duration-200">
                                    <option value="">Choose class...</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                                <?php echo ($selected_class == $class['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Exam</label>
                        <select name="exam_id" class="w-full rounded-lg border-2 border-blue-200 shadow-sm focus:border-blue-500 focus:ring-blue-500 py-3 text-base bg-blue-50 hover:bg-blue-100 transition-colors duration-200">
                                    <option value="">Choose exam...</option>
                                    <?php foreach ($exams as $exam): ?>
                                        <option value="<?php echo $exam['exam_id']; ?>"
                                                <?php echo ($selected_exam == $exam['exam_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($exam['exam_type'] . ' - ' . $exam['category'] . ' (Max: ' . $exam['max_score'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-150">
                            View Results
                        </button>
                            </div>
                        </form>
            </div>

            <?php if ($marks_status): ?>
                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-500 bg-opacity-10">
                                <i class="fas fa-book text-2xl text-blue-500"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-500">Total Subjects</p>
                                <h3 class="text-2xl font-bold text-gray-700">
                                    <?php echo count($marks_status['uploaded']) + count($marks_status['missing']); ?>
                                </h3>
                            </div>
                    </div>
                </div>

                    <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-500 bg-opacity-10">
                                <i class="fas fa-check text-2xl text-green-500"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-500">Uploaded</p>
                                <h3 class="text-2xl font-bold text-green-600">
                                    <?php echo count($marks_status['uploaded']); ?>
                                </h3>
                            </div>
                        </div>
                    </div>

                    <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-500 bg-opacity-10">
                                <i class="fas fa-clock text-2xl text-red-500"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-500">Pending</p>
                                <h3 class="text-2xl font-bold text-red-600">
                                    <?php echo count($marks_status['missing']); ?>
                                </h3>
                            </div>
                        </div>
                    </div>

                    <div class="card-hover bg-white rounded-xl shadow-lg p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-500 bg-opacity-10">
                                <i class="fas fa-chart-pie text-2xl text-purple-500"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm text-gray-500">Completion Rate</p>
                                <h3 class="text-2xl font-bold text-purple-600">
                                    <?php 
                                    $total = count($marks_status['uploaded']) + count($marks_status['missing']);
                                    $completion = $total > 0 ? (count($marks_status['uploaded']) / $total) * 100 : 0;
                                    echo number_format($completion, 1) . '%';
                                    ?>
                                </h3>
                            </div>
                            </div>
                        </div>
                    </div>

                <!-- Results Tables -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Uploaded Marks -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-green-600 text-white px-6 py-4">
                            <h3 class="text-lg font-semibold">Uploaded Marks</h3>
                            </div>
                        <div class="p-6">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                        <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Students</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Upload</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider no-print">Actions</th>
                                            </tr>
                                        </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($marks_status['uploaded'] as $subject): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($subject['subject_code']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($subject['students_count']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('d/m/Y', strtotime($subject['last_upload'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium no-print">
                                                <div class="flex justify-end space-x-2">
                                                    <a href="download_subject_results.php?class_id=<?php echo $selected_class; ?>&exam_id=<?php echo $selected_exam; ?>&subject_id=<?php echo $subject['subject_id']; ?>&format=pdf"
                                                       class="text-blue-600 hover:text-blue-900 font-medium px-3 py-1 rounded-md hover:bg-blue-50">
                                                        Download
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($marks_status['uploaded'])): ?>
                                        <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No subjects with uploaded marks
                                        </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Missing Marks -->
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="bg-red-600 text-white px-6 py-4">
                            <h3 class="text-lg font-semibold">Missing Marks</h3>
                            </div>
                        <div class="p-6">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                        <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Added Date</th>
                                            </tr>
                                        </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($marks_status['missing'] as $subject): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($subject['subject_code']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('d/m/Y', strtotime($subject['created_at'])); ?>
                                            </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($marks_status['missing'])): ?>
                                            <tr>
                                            <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">
                                                All subjects have uploaded marks
                                            </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const classSelect = document.querySelector('select[name="class_id"]');
            const examSelect = document.querySelector('select[name="exam_id"]');
            
            function checkAndSubmit() {
                if (classSelect.value && examSelect.value) {
                    classSelect.form.submit();
                }
            }

            classSelect.addEventListener('change', checkAndSubmit);
            examSelect.addEventListener('change', checkAndSubmit);
        });
    </script>
</body>
</html