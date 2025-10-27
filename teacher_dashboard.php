 <?php
session_start();

// Detect AJAX actions (GET or POST) to return JSON errors instead of redirects
$isAjaxRequest = isset($_GET['action']) || isset($_POST['action']);

// Check if the user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    if ($isAjaxRequest) {
        // For AJAX calls, return a JSON error so the front-end can handle it
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized or session expired. Please login.']);
        exit();
    } else {
        header("Location: index.php");
        exit();
    }
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Fetch teacher's information along with the school name.
$query_teacher = "SELECT CONCAT(u.firstname, ' ', u.lastname) AS teacher_name, s.school_name 
                  FROM users u
                  JOIN schools s ON u.school_id = s.id 
                  WHERE u.user_id = ?";
$stmt_teacher = $conn->prepare($query_teacher);
$stmt_teacher->bind_param("i", $teacher_id);
$stmt_teacher->execute();
$result_teacher = $stmt_teacher->get_result();
$teacher_info = $result_teacher->fetch_assoc();

$teacher_name = $teacher_info['teacher_name'];
$school_name = $teacher_info['school_name'];

// Fetch distinct classes for the teacher
$query = "SELECT DISTINCT c.id as class_id, c.name as class_name
           FROM teacher_subjects ts
           JOIN classes c ON ts.class_id = c.id
           WHERE ts.user_id = ? AND c.school_id = ?
           ORDER BY c.id";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $teacher_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
$classes = $result->fetch_all(MYSQLI_ASSOC);

// Build teacher's assignments (classes and subjects) for profile modal
$assignments_query = "SELECT c.name AS class_name, s.subject_name
                      FROM teacher_subjects ts
                      JOIN classes c ON ts.class_id = c.id
                      JOIN subjects s ON ts.subject_id = s.subject_id
                      WHERE ts.user_id = ? AND c.school_id = ?
                      ORDER BY c.name, s.subject_name";
$stmt_assignments = $conn->prepare($assignments_query);
$stmt_assignments->bind_param("ii", $teacher_id, $school_id);
$stmt_assignments->execute();
$assignments_result = $stmt_assignments->get_result();
$teacher_assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);

// Group subjects by class for easy display
$assignments_by_class = [];
foreach ($teacher_assignments as $ta) {
    $classKey = $ta['class_name'];
    if (!isset($assignments_by_class[$classKey])) {
        $assignments_by_class[$classKey] = [];
    }
    $assignments_by_class[$classKey][] = $ta['subject_name'];
}

// Fetch the current term and year
$query_current_term = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt_current_term = $conn->prepare($query_current_term);
$stmt_current_term->bind_param("i", $school_id);
$stmt_current_term->execute();
$result_current_term = $stmt_current_term->get_result();
$current_term = $result_current_term->fetch_assoc();

if (!$current_term) {
    die("No active term found. Please contact the administrator.");
}

$current_term_id = $current_term['id'];
$current_term_name = $current_term['name'];
$current_school_year = $current_term['year'];

// Modify the exam types query to filter by the current term
$query_exam_types = "SELECT DISTINCT exam_type 
                     FROM exams 
                     WHERE school_id = ? AND term_id = ?";
$stmt_exam_types = $conn->prepare($query_exam_types);
$stmt_exam_types->bind_param("ii", $school_id, $current_term_id);
$stmt_exam_types->execute();
$result_exam_types = $stmt_exam_types->get_result();
$exam_types = $result_exam_types->fetch_all(MYSQLI_ASSOC);

// Get the active term
$query_active_term = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt_active_term = $conn->prepare($query_active_term);
$stmt_active_term->bind_param("i", $school_id);
$stmt_active_term->execute();
$result_active_term = $stmt_active_term->get_result();
$active_term = $result_active_term->fetch_assoc();

if (!$active_term) {
    die("No active term found. Please contact the administrator.");
}

$active_term_id = $active_term['id'];


// Handle AJAX requests
if (isset($_GET['action'])) {
    // Set content type to JSON for all AJAX responses
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
        case 'get_subjects':
            $class_id = $_GET['class_id'];
            $exam_type = $_GET['exam_type'] ?? '';
            $category = $_GET['category'] ?? '';
            
            // If exam_type and category are provided, try to filter subjects by exam type and category
            if ($exam_type && $category) {
                // First try the complex query with exam_subjects
                $query = "SELECT DISTINCT s.subject_id, s.subject_name
                          FROM teacher_subjects ts
                          JOIN subjects s ON ts.subject_id = s.subject_id
                          JOIN exam_subjects es ON s.subject_id = es.subject_id AND ts.class_id = es.class_id
                          JOIN exams e ON es.exam_id = e.exam_id
                          WHERE ts.user_id = ? 
                          AND ts.class_id = ? 
                          AND s.school_id = ?
                          AND e.exam_type = ?
                          AND e.category = ?
                          AND e.term_id = ?
                          AND e.is_active = 1";
                $stmt = $conn->prepare($query);
                if ($stmt) {
                    // types: teacher_id (i), class_id (i), school_id (i), exam_type (s), category (s), current_term_id (i)
                    $stmt->bind_param("iiissi", $teacher_id, $class_id, $school_id, $exam_type, $category, $current_term_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $subjects = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                } else {
                    // If prepared statement fails, return empty result
                    $subjects = [];
                }
            } else {
                // Original query for backward compatibility
                $query = "SELECT s.subject_id, s.subject_name
                          FROM teacher_subjects ts
                          JOIN subjects s ON ts.subject_id = s.subject_id
                          WHERE ts.user_id = ? AND ts.class_id = ? AND s.school_id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("iii", $teacher_id, $class_id, $school_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $subjects = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            echo json_encode($subjects);
            exit;

        case 'generate_template':
            $class_id = $_GET['class_id'];
            $subject_id = $_GET['subject_id'];
            $exam_type = $_GET['exam_type'];
            $max_score = $_GET['max_score'];
            $category = isset($_GET['category']) ? urldecode($_GET['category']) : '';
            $topic = isset($_GET['topic']) ? urldecode($_GET['topic']) : '';
            $teacher_max_score = $_GET['teacher_max_score'] ?? '';

            // Get class and subject names
            $class_query = "SELECT name FROM classes WHERE id = ? AND school_id = ?";
            $stmt_class = $conn->prepare($class_query);
            $stmt_class->bind_param("ii", $class_id, $school_id);
            $stmt_class->execute();
            $class_result = $stmt_class->get_result();
            $class_name = $class_result->fetch_assoc()['name'];

            $subject_query = "SELECT subject_name FROM subjects WHERE subject_id = ? AND school_id = ?";
            $stmt_subject = $conn->prepare($subject_query);
            $stmt_subject->bind_param("ii", $subject_id, $school_id);
            $stmt_subject->execute();
            $subject_result = $stmt_subject->get_result();
            $subject_name = $subject_result->fetch_assoc()['subject_name'];

            // Fetch students who are taking this subject with the same ordering
            $query = "SELECT s.id, CONCAT(s.firstname, ' ', s.lastname) AS name 
                      FROM students s
                      JOIN student_subjects ss ON s.id = ss.student_id
                      WHERE s.class_id = ? 
                      AND s.school_id = ?
                      AND ss.subject_id = ?
                      ORDER BY CAST(REGEXP_REPLACE(s.admission_number, '[^0-9]', '') AS UNSIGNED) ASC, s.admission_number ASC";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iii", $class_id, $school_id, $subject_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $students = $result->fetch_all(MYSQLI_ASSOC);

            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $subject_name . '_marks_template.csv"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Create output stream
            $output = fopen('php://output', 'w');
            
            // Note: BOM removed to prevent validation issues
            // fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Add metadata rows
            fputcsv($output, ['Class:', $class_name], ',', '"');
            fputcsv($output, ['Subject:', $subject_name], ',', '"');
            fputcsv($output, ['Exam Type:', $exam_type], ',', '"');
            fputcsv($output, ['Category:', $category], ',', '"');
            fputcsv($output, ['Topic:', $topic], ',', '"');
            fputcsv($output, ['Admin Max Score:', $max_score], ',', '"');
            fputcsv($output, ['Teacher Marking Range:', '0 - ' . $teacher_max_score], ',', '"');
            fputcsv($output, ['Note:', 'Your scores will be automatically normalized to match the admin\'s max score'], ',', '"');
            fputcsv($output, [], ',', '"'); // Empty row for separation
            
            // Add student data header
            fputcsv($output, ['Student ID', 'Student Name', 'Score (Enter score between 0-' . $teacher_max_score . ')'], ',', '"');
            
            // Add student data
            foreach ($students as $student) {
                fputcsv($output, [
                    $student['id'],
                    $student['name'],
                    ''
                ], ',', '"');
            }
            
            fclose($output);
            exit;

        case 'get_categories':
            $exam_type = $_GET['exam_type'];
            $query = "SELECT DISTINCT category 
                     FROM exams 
                     WHERE exam_type = ? AND school_id = ? AND term_id = ?
                     ORDER BY category";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sii", $exam_type, $school_id, $current_term_id);
            $stmt->execute();
            $result = $stmt->get_result();
            echo json_encode($result->fetch_all(MYSQLI_ASSOC));
            exit;
        case 'get_exam_results':
            $class_id = $_GET['class_id'];
            $subject_id = $_GET['subject_id'];
            $exam_type = $_GET['exam_type'];
            $category = $_GET['category'];
    
            // First, get the exam details if it exists
            $exam_query = "SELECT exam_id, max_score 
                           FROM exams 
                           WHERE exam_type = ? 
                           AND category = ? 
                           AND school_id = ? 
                           AND term_id = ?
                           AND is_active = 1";
            $stmt_exam = $conn->prepare($exam_query);
            $stmt_exam->bind_param("ssii", $exam_type, $category, $school_id, $current_term_id);
            $stmt_exam->execute();
            $exam_result = $stmt_exam->get_result();
            $exam_data = $exam_result->fetch_assoc();
            
            $exam_id = $exam_data ? $exam_data['exam_id'] : null;
            $max_score = $exam_data ? $exam_data['max_score'] : null;

            // Modified query to get all assigned students and their scores for specific exam type and category
            $query = "SELECT 
                s.id,
                CONCAT(s.firstname, ' ', s.lastname) AS student_name,
                er.score,
                COALESCE(e.max_score, ?) as max_score,
                COALESCE(e.exam_id, ?) as exam_id,
                CASE 
                    WHEN er.score IS NULL AND er.exam_id IS NOT NULL THEN 'absent'
                    WHEN er.score IS NULL THEN 'not_submitted'
                    ELSE 'present'
                END as status
            FROM students s
            INNER JOIN student_subjects ss ON s.id = ss.student_id 
            LEFT JOIN (
                SELECT er.* 
                FROM exam_results er
                JOIN exams e ON er.exam_id = e.exam_id
                WHERE er.subject_id = ? 
                AND e.exam_type = ?
                AND e.category = ?
                AND e.term_id = ?
                AND e.school_id = ?
                AND e.is_active = 1
            ) er ON s.id = er.student_id
            LEFT JOIN exams e ON er.exam_id = e.exam_id
            WHERE s.class_id = ? 
            AND s.school_id = ?
            AND ss.subject_id = ?
            ORDER BY CAST(s.admission_number AS UNSIGNED) ASC";  // Order by admission number numerically

            $stmt = $conn->prepare($query);
            $stmt->bind_param("iiissiiiis", 
                $max_score,
                $exam_id,
                $subject_id,
                $exam_type,
                $category,
                $current_term_id,
                $school_id,
                $class_id,
                $school_id,
                $subject_id
            );
            $stmt->execute();
            $result = $stmt->get_result();
            
            $results = [];
            while ($row = $result->fetch_assoc()) {
                $results[] = [
                    'id' => $row['id'],
                    'student_name' => $row['student_name'],
                    'score' => $row['score'],
                    'max_score' => $row['max_score'],
                    'status' => $row['status']
                ];
            }
            
            echo json_encode($results);
            exit;
       case 'get_students':
                    $class_id = $_GET['class_id'];
                    $subject_id = $_GET['subject_id'];
                    $query = "SELECT s.id, CONCAT(s.firstname, ' ', s.lastname) AS name 
                              FROM students s
                              JOIN student_subjects ss ON s.id = ss.student_id
                              WHERE s.class_id = ? AND ss.subject_id = ? AND s.school_id = ?
                              ORDER BY CAST(REGEXP_REPLACE(s.admission_number, '[^0-9]', '') AS UNSIGNED) ASC, s.admission_number ASC";  // Modified ordering
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("iii", $class_id, $subject_id, $school_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
                    exit;
        case 'check_existing_scores':
            $class_id = $_GET['class_id'];
            $subject_id = $_GET['subject_id'];
            $exam_type = $_GET['exam_type'];
            $category = $_GET['category'];
            
            $query = "SELECT COUNT(*) as count
                      FROM exam_results er
                      JOIN exams e ON er.exam_id = e.exam_id
                      WHERE e.exam_type = ? 
                      AND e.category = ?
                      AND er.subject_id = ?
                      AND e.school_id = ?
                      AND e.term_id = ?";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssiii", $exam_type, $category, $subject_id, $school_id, $current_term_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            
            echo json_encode(['hasExistingScores' => $count > 0]);
            exit;
            
        case 'get_progress_data':
            $class_id = $_GET['class_id'];
            $subject_id = $_GET['subject_id'];
            $filter_exam_type = isset($_GET['exam_type']) ? $_GET['exam_type'] : '';
            $filter_category = isset($_GET['category']) ? $_GET['category'] : '';
            
            // Get total students assigned to this subject in this class
            $students_query = "SELECT COUNT(DISTINCT ss.student_id) as total 
                              FROM student_subjects ss
                              JOIN students s ON ss.student_id = s.id
                              WHERE s.class_id = ? 
                              AND ss.subject_id = ? 
                              AND s.school_id = ?";
            $stmt_students = $conn->prepare($students_query);
            $stmt_students->bind_param("iii", $class_id, $subject_id, $school_id);
            $stmt_students->execute();
            $students_result = $stmt_students->get_result();
            $total_students = $students_result->fetch_assoc()['total'];
            
            // Get exam types and categories for the current term (optionally filtered)
            if ($filter_exam_type && $filter_category) {
                $exam_types_query = "SELECT DISTINCT e.exam_type, e.category
                                    FROM exams e 
                                    WHERE e.school_id = ? 
                                    AND e.term_id = ? 
                                    AND e.is_active = 1
                                    AND e.exam_type = ?
                                    AND e.category = ?
                                    ORDER BY e.exam_type, e.category";
                $stmt_exam_types = $conn->prepare($exam_types_query);
                $stmt_exam_types->bind_param("iiss", $school_id, $current_term_id, $filter_exam_type, $filter_category);
            } else {
                $exam_types_query = "SELECT DISTINCT e.exam_type, e.category
                                    FROM exams e 
                                    WHERE e.school_id = ? 
                                    AND e.term_id = ? 
                                    AND e.is_active = 1
                                    ORDER BY e.exam_type, e.category";
                $stmt_exam_types = $conn->prepare($exam_types_query);
                $stmt_exam_types->bind_param("ii", $school_id, $current_term_id);
            }
            $stmt_exam_types->execute();
            $exam_types_result = $stmt_exam_types->get_result();
            $exam_categories = [];

            while ($row = $exam_types_result->fetch_assoc()) {
                $exam_categories[] = [
                    'exam_type' => $row['exam_type'],
                    'category' => $row['category']
                ];
            }
            
            // Calculate progress for each exam type and category
            $progress_data = [];
            $completed_assessments = 0;
            $total_assessments = count($exam_categories) * ($total_students > 0 ? $total_students : 1);
            
            foreach ($exam_categories as $ec) {
                // Get number of students with scores for this exam type and category
                $scores_query = "SELECT COUNT(DISTINCT er.student_id) as completed
                               FROM exam_results er
                               JOIN exams e ON er.exam_id = e.exam_id
                               JOIN students s ON er.student_id = s.id
                               WHERE e.exam_type = ?
                               AND e.category = ? 
                               AND er.subject_id = ?
                               AND s.class_id = ?
                               AND er.school_id = ?
                               AND e.term_id = ?";
                $stmt_scores = $conn->prepare($scores_query);
                $stmt_scores->bind_param("ssiiii", $ec['exam_type'], $ec['category'], $subject_id, $class_id, $school_id, $current_term_id);
                $stmt_scores->execute();
                $scores_result = $stmt_scores->get_result();
                $completed = $scores_result->fetch_assoc()['completed'];
                
                $percentage = $total_students > 0 ? round(($completed / $total_students) * 100) : 0;
                $completed_assessments += $completed;
                
                $progress_data[] = [
                    'exam_type' => $ec['exam_type'],
                    'category' => $ec['category'],
                    'total' => $total_students,
                    'completed' => $completed,
                    'percentage' => $percentage
                ];
            }
            
            // Calculate overall progress stats
            $pending_assessments = $total_assessments - $completed_assessments;
            $overall_percentage = $total_assessments > 0 ? round(($completed_assessments / $total_assessments) * 100) : 0;
            
            $response = [
                'total_students' => $total_students,
                'completed_assessments' => $completed_assessments,
                'pending_assessments' => $pending_assessments,
                'completion_rate' => $overall_percentage,
                'progress_data' => $progress_data
            ];
            
            echo json_encode($response);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}


// Handle the CSV file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_marks'])) {
    $class_id = $_POST['class_id'];
    $subject_id = $_POST['subject_id'];
    $exam_type = $_POST['exam_type'];
    $category = $_POST['category'];  // Add category from POST
    $topic = $_POST['topic'] ?? '';  // Add topic from POST
    $max_score = floatval($_POST['max_score']);
    $teacher_max_score = floatval($_POST['teacher_max_score']);
    
    // Debug: Log the topic value and all POST data
    error_log("Debug CSV Upload - Topic value: '" . $topic . "'");
    error_log("Debug CSV Upload - All POST data: " . print_r($_POST, true));
    
    // If topic is empty, try to get it from the form field directly
    if (empty($topic)) {
        $topic = isset($_POST['topic']) ? $_POST['topic'] : '';
        error_log("Debug CSV Upload - Topic after fallback: '" . $topic . "'");
    }

    // Handle file upload
    if (isset($_FILES['marks_file']) && $_FILES['marks_file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['marks_file']['tmp_name'];
        $fileContent = file_get_contents($file);

        // Get class and subject names for validation
        $stmt = $conn->prepare("SELECT c.name, s.subject_name 
                              FROM classes c, subjects s 
                              WHERE c.id = ? AND s.subject_id = ?");
        $stmt->bind_param("ii", $class_id, $subject_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $class_name = $row['name'];
        $subject_name = $row['subject_name'];

        // Validate file metadata
        $lines = explode("\n", $fileContent);
        $errors = array();
        $mismatches = array();

        if (count($lines) < 5) {
            $errors[] = "Invalid file format. Please use the correct template.";
        } else {
            // Remove BOM from the entire content first (in case user uploads file with BOM)
            $fileContent = preg_replace('/^\xEF\xBB\xBF/', '', $fileContent);
            $lines = explode("\n", $fileContent);

            // Function to clean CSV values
            function cleanCsvValue($value) {
                // Remove BOM, labels, commas, and trim whitespace
                $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
                
                // Debug: Log the original value
                error_log("Debug cleanCsvValue - Original: '" . $value . "'");
                
                // Remove labels using more reliable string replacement
                $labels = ['Class:', 'Subject:', 'Exam Type:', 'Category:', 'Topic:', 'Admin Max Score:'];
                foreach ($labels as $label) {
                    if (strpos($value, $label) === 0) {
                        $value = substr($value, strlen($label));
                        error_log("Debug cleanCsvValue - Removed label '$label', result: '" . $value . "'");
                        break;
                    }
                }
                
                $value = str_replace(',', '', $value);
                $result = trim($value);
                error_log("Debug cleanCsvValue - Final result: '" . $result . "'");
                return $result;
            }

            // Clean the file values - try parsing CSV properly first
            $csv_line_0 = str_getcsv($lines[0]);
            $csv_line_1 = str_getcsv($lines[1]);
            $csv_line_2 = str_getcsv($lines[2]);
            $csv_line_3 = str_getcsv($lines[3]);
            $csv_line_4 = str_getcsv($lines[4]);
            $csv_line_5 = str_getcsv($lines[5]);
            
            $file_class = cleanCsvValue($csv_line_0[1] ?? $lines[0]);
            $file_subject = cleanCsvValue($csv_line_1[1] ?? $lines[1]);
            $file_exam_type = cleanCsvValue($csv_line_2[1] ?? $lines[2]);
            $file_category = cleanCsvValue($csv_line_3[1] ?? $lines[3]);
            $file_topic = cleanCsvValue($csv_line_4[1] ?? $lines[4]);
            $file_max_score = cleanCsvValue($csv_line_5[1] ?? $lines[5]);
            
            // Debug: Log the values to see what's happening
            error_log("Debug - Original line 0: " . $lines[0]);
            error_log("Debug - CSV parsed line 0: " . print_r($csv_line_0, true));
            error_log("Debug - Cleaned file_class: '" . $file_class . "'");
            error_log("Debug - Expected class_name: '" . $class_name . "'");

            // Clean the expected values
            $clean_class_name = trim($class_name);
            $clean_subject_name = trim($subject_name);
            $clean_exam_type = trim($exam_type);
            $clean_category = trim($category);
            $clean_topic = trim($topic);
            $clean_max_score = trim($max_score);

            if ($file_class !== $clean_class_name) {
                $mismatches[] = "Class: Expected '$clean_class_name' but found '$file_class'";
            }
            if ($file_subject !== $clean_subject_name) {
                $mismatches[] = "Subject: Expected '$clean_subject_name' but found '$file_subject'";
            }
            if ($file_exam_type !== $clean_exam_type) {
                $mismatches[] = "Exam Type: Expected '$clean_exam_type' but found '$file_exam_type'";
            }
            if ($file_category !== $clean_category) {
                $mismatches[] = "Category: Expected '$clean_category' but found '$file_category'";
            }
            if ($file_topic !== $clean_topic) {
                $mismatches[] = "Topic: Expected '$clean_topic' but found '$file_topic'";
            }
            if ($file_max_score != $clean_max_score) {
                $mismatches[] = "Max Score: Expected '$clean_max_score' but found '$file_max_score'";
            }
        }

        if (!empty($mismatches)) {
            $error_message = "<div style='text-align: left; margin: 20px;'>";
            $error_message .= "<h3 style='color: #dc3545; margin-bottom: 15px;'>File Validation Failed</h3>";
            $error_message .= "<p style='margin-bottom: 10px;'>The uploaded file does not match the selected options. Please check the following mismatches:</p>";
            $error_message .= "<ul style='list-style-type: none; padding-left: 0;'>";
            foreach ($mismatches as $mismatch) {
                $error_message .= "<li style='margin-bottom: 8px; padding: 8px; background-color: #f8d7da; border-left: 4px solid #dc3545;'>";
                $error_message .= "<i class='fas fa-exclamation-circle' style='margin-right: 8px; color: #dc3545;'></i>";
                $error_message .= $mismatch;
                $error_message .= "</li>";
            }
            $error_message .= "</ul>";
            $error_message .= "<p style='margin-top: 15px;'>Please download a new template with the correct options and try again.</p>";
            $error_message .= "</div>";
            
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit;
        }

        if (!empty($errors)) {
            $error_message = "<div style='text-align: left; margin: 20px;'>";
            $error_message .= "<h3 style='color: #dc3545; margin-bottom: 15px;'>File Format Error</h3>";
            $error_message .= "<p style='margin-bottom: 10px;'>" . implode("<br>", $errors) . "</p>";
            $error_message .= "<p style='margin-top: 15px;'>Please download a new template and try again.</p>";
            $error_message .= "</div>";
            
            echo json_encode(['success' => false, 'message' => $error_message]);
            exit;
        }

        // Check if an exam entry already exists for this term
        $check_exam_query = "SELECT exam_id FROM exams 
                            WHERE exam_type = ? 
                            AND category = ? 
                            AND school_id = ? 
                            AND term_id = ?";
        $stmt_check_exam = $conn->prepare($check_exam_query);
        $stmt_check_exam->bind_param("ssii", $exam_type, $category, $school_id, $active_term_id);
        $stmt_check_exam->execute();
        $result_check_exam = $stmt_check_exam->get_result();

        if ($result_check_exam->num_rows > 0) {
            // Update existing exam
            $exam_row = $result_check_exam->fetch_assoc();
            $exam_id = $exam_row['exam_id'];
            $update_exam_query = "UPDATE exams SET max_score = ? WHERE exam_id = ?";
            $stmt_update_exam = $conn->prepare($update_exam_query);
            $stmt_update_exam->bind_param("di", $max_score, $exam_id);
            $stmt_update_exam->execute();
        } else {
            // Create a new exam entry
            $insert_exam_query = "INSERT INTO exams (name, exam_type, category, school_id, max_score, term_id) 
                                VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_insert_exam = $conn->prepare($insert_exam_query);
            $exam_name = $exam_type . " - " . $category . " - " . date("Y-m-d");
            $stmt_insert_exam->bind_param("sssidi", $exam_name, $exam_type, $category, $school_id, $max_score, $active_term_id);
            $stmt_insert_exam->execute();
            $exam_id = $stmt_insert_exam->insert_id;
        }

        // Prepare the delete query to remove previous exam results
        $delete_query = "DELETE FROM exam_results WHERE exam_id = ? AND subject_id = ? AND school_id = ?";
        $stmt_delete = $conn->prepare($delete_query);
        $stmt_delete->bind_param("iii", $exam_id, $subject_id, $school_id);
        $stmt_delete->execute();

        // Insert new marks after deleting previous ones
        $insert_query = "INSERT INTO exam_results (exam_id, school_id, student_id, subject_id, score, topic, upload_date, term_id) 
                         VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)";
        $stmt_insert = $conn->prepare($insert_query);

        $success = true;
        if (($handle = fopen($file, "r")) !== FALSE) {
            // Skip the header rows (metadata) - now 9 rows including topic
            for ($i = 0; $i < 9; $i++) {
                fgetcsv($handle, 1000, ",");
            }

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) >= 3) {  // Ensure we have at least 3 columns
                    $student_id = $data[0];  // First column is student ID
                    $raw_score = floatval($data[2]);  // Third column is the score

                    // Normalize the score
                    if ($teacher_max_score > 0) {
                        $normalized_score = ($raw_score / $teacher_max_score) * $max_score;
                        $normalized_score = min($normalized_score, $max_score); // Ensure it doesn't exceed max_score
                        
                        // Debug: Log the values being inserted
                        error_log("Debug CSV Insert - Topic: '" . $topic . "', Student ID: " . $student_id . ", Score: " . $normalized_score);
                        
                        $stmt_insert->bind_param("iiiidsi", $exam_id, $school_id, $student_id, $subject_id, $normalized_score, $topic, $active_term_id);
                        if (!$stmt_insert->execute()) {
                            $success = false;
                            error_log("Debug CSV Insert - Database error: " . $stmt_insert->error);
                            break;
                        }
                    }
                }
            }
            fclose($handle);
        }

        if ($success) {
            $message = '<div class="success-message" id="successMessage">Marks uploaded and normalized successfully!</div>';
            echo "<script>
                setTimeout(function() {
                    const successMessage = document.getElementById('successMessage');
                    if (successMessage) {
                        successMessage.style.opacity = '0';
                        setTimeout(function() {
                            successMessage.style.display = 'none';
                        }, 500);
                    }
                }, 10000);
            </script>";
        } else {
            $message = '<div class="error-message">Failed to upload marks!</div>';
        }
    } else {
        $message = '<div class="error-message">Error uploading file!</div>';
    }
}


// Handle direct mark input
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['enter_marks'])) {
    $class_id = $_POST['class_id'];
    $subject_id = $_POST['subject_id'];
    $exam_type = $_POST['exam_type'];
    $category = $_POST['category'];
    $topic = $_POST['topic'] ?? '';
    $max_score = floatval($_POST['max_score']);

    // Check if an exam entry already exists for this term
    $check_exam_query = "SELECT exam_id FROM exams 
                        WHERE exam_type = ? 
                        AND category = ?  
                        AND school_id = ? 
                        AND term_id = ?";
    $stmt_check_exam = $conn->prepare($check_exam_query);
    $stmt_check_exam->bind_param("ssii", $exam_type, $category, $school_id, $active_term_id);
    $stmt_check_exam->execute();
    $result_check_exam = $stmt_check_exam->get_result();

    if ($result_check_exam->num_rows > 0) {
        // Update existing exam
        $exam_row = $result_check_exam->fetch_assoc();
        $exam_id = $exam_row['exam_id'];
        $update_exam_query = "UPDATE exams SET max_score = ? WHERE exam_id = ?";
        $stmt_update_exam = $conn->prepare($update_exam_query);
        $stmt_update_exam->bind_param("di", $max_score, $exam_id);
        $stmt_update_exam->execute();
    } else {
        // Create a new exam entry
        $insert_exam_query = "INSERT INTO exams (name, exam_type, category, school_id, max_score, term_id) 
                            VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_insert_exam = $conn->prepare($insert_exam_query);
        $exam_name = $exam_type . " - " . $category . " - " . date("Y-m-d");
        $stmt_insert_exam->bind_param("sssidi", $exam_name, $exam_type, $category, $school_id, $max_score, $active_term_id);
        $stmt_insert_exam->execute();
        $exam_id = $stmt_insert_exam->insert_id;
    }

    // Prepare the delete query to remove previous exam results for this exam and subject
    $delete_query = "DELETE FROM exam_results WHERE exam_id = ? AND subject_id = ? AND school_id = ?";
    $stmt_delete = $conn->prepare($delete_query);
    $stmt_delete->bind_param("iii", $exam_id, $subject_id, $school_id);
    $stmt_delete->execute();

    // Insert new marks after deleting previous ones
    $insert_query = "INSERT INTO exam_results (exam_id, school_id, student_id, subject_id, score, topic, upload_date, term_id) 
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)";
    $stmt_insert = $conn->prepare($insert_query);

    $success = true;
    foreach ($_POST['students'] as $student_id => $score) {
        $score = floatval($score);
        if ($score <= $max_score) {
            $stmt_insert->bind_param("iiiidsi", $exam_id, $school_id, $student_id, $subject_id, $score, $topic, $active_term_id);
            if (!$stmt_insert->execute()) {
                $success = false;
                break;
            }
        }
    }

    if ($success) {
        $message = '<div class="success-message">Marks entered successfully!</div>';
    } else {
        $message = '<div class="error-message">Failed to enter marks!</div>';
    }
}

$query_max_scores = "SELECT DISTINCT max_score 
                     FROM exams 
                     WHERE school_id = ? AND term_id = ?
                     ORDER BY max_score";
$stmt_max_scores = $conn->prepare($query_max_scores);
$stmt_max_scores->bind_param("ii", $school_id, $current_term_id);
$stmt_max_scores->execute();
$result_max_scores = $stmt_max_scores->get_result();
$max_scores = $result_max_scores->fetch_all(MYSQLI_ASSOC);

// If no max scores found, provide a default
if (empty($max_scores)) {
    $max_scores = [
        ['max_score' => 3],
        ['max_score' => 5],
        ['max_score' => 10]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard</title>
    <!-- Add Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --success-color: #2ecc71;
            --background-color: #f5f6fa;
            --text-color: #2c3e50;
            --border-color: #dcdde1;
            --card-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--background-color);
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            box-sizing: border-box;
        }

        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 0;
            box-shadow: var(--card-shadow);
        }

        .header-content {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .teacher-info {
            display: grid;
            gap: 6px;
            justify-items: end;
        }

        .hero-badges {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .badge {
            background-color: rgba(255, 255, 255, 0.15);
            color: #fff;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .hero-actions {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        /* Unified action buttons in header */
        .hero-actions .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            height: 44px;
            min-width: 140px;
            border-radius: 8px;
            font-size: 0.95rem;
            text-decoration: none;
            box-sizing: border-box;
            transition: background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
        }
        .hero-actions .action-btn i { font-size: 18px; }
        .hero-actions .action-btn--ghost {
            background: transparent;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .hero-actions .action-btn--ghost:hover { border-color: rgba(255,255,255,0.5); transform: translateY(-1px); }
        .hero-actions .action-btn--primary {
            background-color: var(--accent-color);
            color: #fff;
            border: none;
        }
        .hero-actions .action-btn--primary:hover { background-color: #c0392b; transform: translateY(-1px); }

        .teacher-info i {
            font-size: 24px;
            margin-right: 10px;
        }

        h1, h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        h1 {
            margin: 0;
        }

        /* Make the header title white and larger for visibility */
        header h1 { color: #ffffff; font-size: clamp(1.1rem, 3.2vw, 2.8rem); }
        header h1 i { color: #ffffff; }

        /* Enhanced header title styling */
        .app-title { display: inline-flex; align-items: center; gap: 12px; color: #fff; }
        .app-title .title-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.15);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15) inset, 0 2px 8px rgba(0,0,0,0.08);
        }
        .app-title .title-icon i { font-size: 22px; color: #fff; }
        .app-title .title-text {
            font-size: clamp(1.8rem, 2.8vw, 2.4rem);
            font-weight: 800;
            letter-spacing: 0.3px;
            text-shadow: 0 2px 6px rgba(0,0,0,0.18);
        }

        .dashboard-section {
            background-color: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
            padding: 30px;
            transition: transform 0.3s ease;
        }

        .dashboard-section:hover {
            transform: translateY(-5px);
        }

        .dashboard-section h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary-color);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        form {
            display: grid;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        select, input[type="number"], input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            height: 44px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            line-height: 22px;
            box-sizing: border-box;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        select:focus, input[type="number"]:focus, input[type="text"]:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
        }

        /* Topic input field styling */
        .topic-wrapper {
            margin-top: 6px;
        }

        .topic-field {
            position: relative;
            display: flex;
            align-items: center;
        }

        .topic-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary-color);
            font-size: 1rem;
            pointer-events: none;
            opacity: 0.95;
        }

        .topic-input {
            width: 100%;
            padding: 11px 12px 11px 40px;
            height: 44px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            background-color: #f8f9fa;
            font-size: 0.95rem;
            color: #495057;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.15s ease;
        }

        .topic-input::placeholder {
            color: #9aa3ad;
            font-style: italic;
        }

        .topic-input:focus {
            background-color: white;
            border-color: var(--secondary-color);
            color: var(--text-color);
            box-shadow: 0 6px 18px rgba(13,71,161,0.08);
            outline: none;
            font-style: normal;
        }

        .topic-help {
            display: block;
            margin-top: 6px;
            color: #6c757d;
            font-size: 0.875rem;
            font-style: italic;
        }

        .topic-count {
            margin-left: 10px;
            font-size: 0.82rem;
            color: #6c757d;
            white-space: nowrap;
        }

        button {
            background-color: var(--secondary-color);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        button:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }

        button:disabled {
            background-color: #95a5a6;
            cursor: not-allowed;
            transform: none;
        }

        /* Success (light green) button style */
        .btn-success {
            background-color: #34d399; /* light green */
            color: #ffffff;
            border: none;
        }
        .btn-success:hover {
            background-color: #10b981; /* a bit darker green on hover */
        }

        /* Ensure consistent size for Load Students, Submit Marks, and View Results buttons */
        #loadStudentsBtn, #submitMarksBtn, #viewResultsBtn, #loadProgressBtn, #generateTemplateBtn, #uploadMarksBtn {
            height: 44px;
            min-width: 160px;
            padding: 10px 16px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-sizing: border-box;
        }

        .success-message, .error-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            margin: 20px 0;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            transition: opacity 0.5s ease;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .student-list {
            margin-top: 30px;
            overflow-x: auto;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
        }

        .student-list table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
        }

        .student-list th, .student-list td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .student-list th {
            background-color: var(--primary-color);
            color: white;
            font-weight: bold;
        }

        .student-list tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .student-list tr:hover {
            background-color: #e9ecef;
        }

        .file-upload {
            margin-top: 20px;
            position: relative;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background-color: var(--secondary-color);
            color: white;
            cursor: pointer;
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }

        .file-upload label:hover {
            background-color: #2980b9;
        }

        .file-name {
            margin-top: 10px;
            font-style: italic;
            color: #666;
        }

        .edit-score-input {
            width: 80px;
            text-align: center;
            padding: 8px;
            border: 2px solid var(--border-color);
            border-radius: 4px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-buttons button {
            padding: 8px 16px;
            font-size: 14px;
            border-radius: 4px;
        }

        .action-buttons button.save-score {
            background-color: var(--success-color);
        }

        .action-buttons button.delete-score {
            background-color: var(--accent-color);
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--card-shadow);
        }

        .notification.success {
            background-color: var(--success-color);
        }

        .notification.error {
            background-color: var(--accent-color);
        }

        .search-bar {
            margin-bottom: 20px;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            box-sizing: border-box;
        }

        .search-bar i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }

        .sign-out-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background-color: var(--accent-color);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 10px;
            transition: background-color 0.3s ease;
        }

        .sign-out-btn:hover {
            background-color: #c0392b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .dashboard-section {
                padding: 20px;
            }

            button {
                width: 100%;
            }

            .header-content { grid-template-columns: 1fr; }
            .teacher-info { justify-items: center; text-align: center; }
            .hero-actions { width: 100%; gap: 8px; }
            .hero-actions .action-btn { flex: 1; min-width: unset; width: 100%; height: 44px; }

            h1 {
                font-size: 1.5rem;
            }

            .student-list th, .student-list td {
                padding: 8px 10px;
            }

            .edit-score-input {
                width: 60px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons button {
                width: 100%;
                margin-bottom: 5px;
            }
        }

        .dashboard-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .option-card {
            position: relative;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            border-radius: 14px;
            padding: 1.6rem;
            text-align: center;
            box-shadow: 0 6px 18px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            border: 1px solid #eef2f7;
        }

        .option-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .option-card .icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(52,152,219,0.12);
            margin: 0 auto 1rem;
        }
        .option-card .icon-wrap i {
            font-size: 1.6rem;
            color: var(--secondary-color);
        }

        .option-card h3 {
            color: var(--primary-color);
            margin: 0 0 0.25rem;
        }

        .option-card p {
            color: #667085;
            font-size: 0.9rem;
            margin: 0 0 0.75rem;
        }

        .option-card .cta {
            color: var(--secondary-color);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .option-card .cta i { font-size: 0.9rem; }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .back-button {
            background-color: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s ease;
        }

        .back-button:hover {
            background-color: #34495e;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 1rem;
        }

        .tab-button {
            background: none;
            border: none;
            padding: 1rem 2rem;
            cursor: pointer;
            font-size: 1rem;
            color: var(--text-color);
            border-radius: 8px 8px 0 0;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tab-button:hover {
            background-color: var(--background-color);
        }

        .tab-button.active {
            background-color: var(--secondary-color);
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
            transform: scale(0.7);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .modal.active {
            display: flex;
        }

        .modal.active .modal-content {
            transform: scale(1);
            opacity: 1;
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
        }

        .warning-icon {
            font-size: 2rem;
            color: #f1c40f;
        }

        .modal-body {
            text-align: center;
        }

        .modal-body p {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            color: var(--text-color);
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        .btn-primary, .btn-secondary {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #7f8c8d;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                padding: 1.5rem;
            }

            .modal-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }

            .btn-primary, .btn-secondary {
                width: 100%;
                justify-content: center;
            }
        }

        .edit-score-input, .raw-score, .normalized-score {
            width: 120px;
            height: 44px;
            padding: 8px 10px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            text-align: center;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .edit-score-input:focus, .raw-score:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 5px rgba(52, 152, 219, 0.3);
        }

        .normalized-score {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            color: #495057;
        }

        /* Validation styling for score inputs */
        .raw-score.valid, .edit-score-input.valid {
            border-color: #28a745 !important;
            background-color: #d4edda !important;
            box-shadow: 0 0 5px rgba(40, 167, 69, 0.3);
        }

        .raw-score.invalid, .edit-score-input.invalid {
            border-color: #dc3545 !important;
            background-color: #f8d7da !important;
            box-shadow: 0 0 5px rgba(220, 53, 69, 0.3);
        }

        td {
            min-width: 120px; /* Ensure consistent cell width */
            padding: 10px;
            vertical-align: middle;
        }

        /* Progress Section Styles */
        .progress-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .progress-filters .filter-group {
            display: flex;
            flex-direction: column;
        }

        .progress-summary {
            margin-top: 30px;
        }

        .term-info {
            background: linear-gradient(135deg, var(--primary-color), #34495e);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .term-header {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .term-header i {
            font-size: 2.5rem;
            opacity: 0.9;
        }

        .term-header h3 {
            margin: 0;
            color: white;
            font-size: 1.5rem;
        }

        .term-header p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 1.1rem;
        }

        #overall-progress-circle {
            position: relative;
            margin: 10px 15px;
        }

        .progress-circle-container {
            position: relative;
            width: 120px;
            height: 120px;
        }

        .progress-ring {
            transform: rotate(-90deg);
        }

        .progress-ring-circle-bg {
            fill: transparent;
            stroke: rgba(255, 255, 255, 0.2);
            stroke-width: 8;
        }

        .progress-ring-circle {
            fill: transparent;
            stroke: white;
            stroke-width: 8;
            stroke-dasharray: 314;
            stroke-dashoffset: 314;
            transition: none; /* Remove transition */
        }

        .progress-circle-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            width: 100%;
        }

        .progress-circle-text span {
            display: block;
        }

        #completion-percentage {
            font-size: 1.8rem;
            font-weight: bold;
        }

        .progress-label {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .progress-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .progress-card {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            align-items: center;
            gap: 15px;
            overflow: hidden;
            position: relative;
        }

        .progress-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--secondary-color);
        }

        .progress-card.total-students::before {
            background-color: #3498db;
        }

        .progress-card.completed-assessments::before {
            background-color: #2ecc71;
        }

        .progress-card.pending-assessments::before {
            background-color: #f39c12;
        }

        .progress-card.completion-rate::before {
            background-color: #9b59b6;
        }

        .progress-card:hover {
            transform: none;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(52, 152, 219, 0.1);
        }

        .progress-card.total-students .card-icon {
            background-color: rgba(52, 152, 219, 0.1);
        }

        .progress-card.completed-assessments .card-icon {
            background-color: rgba(46, 204, 113, 0.1);
        }

        .progress-card.pending-assessments .card-icon {
            background-color: rgba(243, 156, 18, 0.1);
        }

        .progress-card.completion-rate .card-icon {
            background-color: rgba(155, 89, 182, 0.1);
        }

        .progress-card i {
            font-size: 2rem;
        }

        .progress-card.total-students i {
            color: #3498db;
        }

        .progress-card.completed-assessments i {
            color: #2ecc71;
        }

        .progress-card.pending-assessments i {
            color: #f39c12;
        }

        .progress-card.completion-rate i {
            color: #9b59b6;
        }

        .card-content {
            flex: 1;
        }

        .progress-card h4 {
            margin: 0 0 5px;
            color: var(--text-color);
            font-size: 1rem;
            font-weight: 600;
        }

        .progress-card p {
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
            color: var(--text-color);
        }

        .detailed-progress-header {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary-color);
            font-size: 1.2rem;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
        }

        .detailed-progress {
            margin-top: 30px;
        }

        .detailed-view-toggle {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 15px;
        }

        .toggle-btn {
            background-color: transparent;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 5px 10px;
            font-size: 0.9rem;
            color: var(--text-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }

        .toggle-btn:hover {
            background-color: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
            transform: none;
        }

        .toggle-btn i {
            font-size: 0.9rem;
        }

        .exam-progress-item {
            background-color: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .exam-progress-item:hover {
            transform: none;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .exam-progress-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background-color: var(--secondary-color);
        }

        .exam-progress-item.collapsed .progress-bar-container,
        .exam-progress-item.collapsed .progress-details {
            display: none;
        }

        .exam-progress-item .collapse-toggle {
            position: absolute;
            top: 20px;
            right: 15px;
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
            opacity: 0.7;
            transition: all 0.2s ease;
        }

        .exam-progress-item .collapse-toggle:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        .exam-progress-item.collapsed .collapse-toggle i {
            transform: rotate(180deg);
        }

        .exam-progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .exam-progress-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: bold;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .exam-progress-title i {
            font-size: 1.2rem;
            color: var(--secondary-color);
        }

        .exam-progress-percentage {
            background-color: var(--secondary-color);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
            box-shadow: 0 3px 6px rgba(52, 152, 219, 0.3);
        }

        .progress-bar-container {
            height: 12px;
            background-color: #f5f5f5;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 15px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--secondary-color) 0%, #2980b9 100%);
            border-radius: 6px;
            transition: none; /* Remove transition */
            position: relative;
            overflow: hidden;
        }

        .progress-details {
            display: flex;
            justify-content: space-between;
            color: #666;
            font-size: 0.9rem;
        }

        .no-data-message {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: white;
            border-radius: 12px;
            padding: 50px 30px;
            box-shadow: var(--card-shadow);
            text-align: center;
        }

        .no-data-message i {
            font-size: 3.5rem;
            color: #95a5a6;
            margin-bottom: 25px;
            opacity: 0.7;
        }

        .no-data-message p {
            color: #7f8c8d;
            font-size: 1.3rem;
            margin-bottom: 30px;
            max-width: 400px;
        }

        .no-data-message button {
            padding: 12px 25px;
            font-size: 1rem;
        }

        #try-another-class-btn {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #try-another-class-btn:hover {
            background-color: #2980b9;
            transform: none;
            box-shadow: 0 3px 8px rgba(52, 152, 219, 0.3);
        }

        @media (max-width: 768px) {
            .term-info {
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding: 20px 15px;
            }

            .term-header {
                margin-bottom: 20px;
                flex-direction: column;
                text-align: center;
            }

            .progress-overview {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .progress-card {
                flex-direction: column;
                text-align: center;
                padding: 15px;
            }

            .progress-card::before {
                width: 100%;
                height: 5px;
                top: 0;
                left: 0;
            }

            .card-icon {
                margin-bottom: 10px;
            }

            .exam-type-stats {
                flex-direction: column;
                gap: 15px;
            }

            .stat-item {
                margin-bottom: 10px;
            }

            .exam-progress-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .exam-progress-percentage {
                align-self: flex-start;
            }

            .exam-types-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
                margin-bottom: 12px;
            }
        }

        /* Added: Small device optimizations */
        @media (max-width: 576px) {
            .container {
                padding: 10px;
            }

            .dashboard-section {
                padding: 15px;
                margin-bottom: 15px;
            }

            .section-header {
                margin-bottom: 15px;
            }

            .section-header h2 {
                font-size: 1.3rem;
            }

            .progress-filters {
                grid-template-columns: 1fr;
                gap: 10px;
                margin-bottom: 15px;
            }

            .progress-filters select, 
            .progress-filters button {
                height: 40px;
                padding: 8px 12px;
                font-size: 14px;
            }

            .term-info {
                padding: 15px 10px;
                margin-bottom: 15px;
            }

            .term-header i {
                font-size: 1.8rem;
            }

            .term-header h3 {
                font-size: 1.2rem;
            }

            .term-header p {
                font-size: 0.9rem;
            }

            #overall-progress-circle {
                transform: scale(0.8);
                margin: 0;
            }

            .progress-overview {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-bottom: 15px;
            }

            .progress-card {
                padding: 10px;
            }

            .card-icon {
                width: 40px;
                height: 40px;
            }

            .progress-card i {
                font-size: 1.2rem;
            }

            .progress-card h4 {
                font-size: 0.8rem;
            }

            .progress-card p {
                font-size: 1.4rem;
            }

            .detailed-progress-header {
                font-size: 1.1rem;
                margin-bottom: 10px;
                padding-bottom: 5px;
            }

            .detailed-view-toggle {
                justify-content: space-between;
                width: 100%;
                margin-bottom: 10px;
            }

            .toggle-btn {
                padding: 5px 8px;
                font-size: 0.8rem;
                flex: 1;
                justify-content: center;
            }

            .exam-types-grid {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-bottom: 10px;
            }

            .exam-type-card {
                padding: 10px;
            }

            .exam-type-title {
                margin-bottom: 8px;
            }

            .exam-type-title i {
                font-size: 1rem;
            }

            .exam-type-title h4 {
                font-size: 0.9rem;
            }

            .stat-value {
                font-size: 1rem;
            }

            .exam-progress-item {
                padding: 15px;
                margin-bottom: 10px;
            }

            .exam-progress-title {
                font-size: 0.9rem;
                padding-right: 20px; /* Make space for collapse toggle */
            }

            .collapse-toggle {
                top: 15px;
                right: 10px;
            }

            .progress-details {
                font-size: 0.8rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .no-data-message {
                padding: 30px 15px;
            }

            .no-data-message i {
                font-size: 2.5rem;
                margin-bottom: 15px;
            }

            .no-data-message p {
                font-size: 1rem;
                margin-bottom: 20px;
            }

            .no-data-message button {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <div>
        <h1> TEACHER'S DASHBOARD</h1>
        </div>
        <div class="hero-actions">
            <a href="teacher_messaging.php" class="action-btn action-btn--ghost" title="Open Chat" style="position: relative;">
                <i class="fas fa-comments"></i>
                <span>Chat</span>
                <span id="chatUnreadBadge" style="display:none; position:absolute; top:-6px; right:-6px; background:#e02424; color:#fff; border-radius:999px; font-size:12px; line-height:18px; min-width:18px; height:18px; padding:0 5px; text-align:center;">0</span>
            </a>
            <a href="profile.php" class="action-btn action-btn--ghost" title="View Profile" style="position: relative;">
                <i class="fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
            <a href="index.php" class="action-btn action-btn--primary"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
            <div id="notification" class="notification" style="display: none;"></div>
        </div>
    </div>
</header>

    <div class="container">
        <?php if (isset($message)) echo $message; ?>

        <div class="dashboard-options" id="mainOptions">
            <div class="option-card" onclick="showSection('uploadSection')">
                <div class="icon-wrap"><i class="fas fa-upload"></i></div>
                <h3>Upload Marks</h3>
                <p>Enter directly or upload student marks for exams and assessments</p>
                <span class="cta">Get Started <i class="fas fa-arrow-right"></i></span>
            </div>
            <div class="option-card" onclick="showSection('viewSection')">
                <div class="icon-wrap"><i class="fas fa-chart-bar"></i></div>
                <h3>View Results</h3>
                <p>View and manage existing exam results</p>
                <span class="cta">Open Results <i class="fas fa-arrow-right"></i></span>
            </div>
            <div class="option-card" onclick="showSection('progressSection')">
                <div class="icon-wrap"><i class="fas fa-tasks"></i></div>
                <h3>Assess Progress</h3>
                <p>Track your progress in entering marks for different exam types</p>
                <span class="cta">Track Now <i class="fas fa-arrow-right"></i></span>
            </div>
        </div>

        <div class="dashboard-section" id="uploadSection" style="display: none;">
            <div class="section-header">
                <button class="back-button" onclick="showMainOptions()"><i class="fas fa-arrow-left"></i> Back</button>
            </div>
            <div class="tabs">
                <button class="tab-button active" onclick="switchTab('directEntry')">
                    <i class="fas fa-keyboard"></i> Enter Marks
                </button>
                <button class="tab-button" onclick="switchTab('csvUpload')">
                    <i class="fas fa-file-csv"></i> Upload via excel sheet
                </button>
            </div>

            <div id="directEntry" class="tab-content active">
                <form method="post" id="enterMarksForm">
                    <input type="hidden" name="class_id" value="" id="directClassId">
                    <input type="hidden" name="subject_id" value="" id="directSubjectId">
                    <input type="hidden" name="exam_type" value="" id="directExamType">
                    <input type="hidden" name="category" value="" id="directCategory">
                    <input type="hidden" name="max_score" value="" id="directMaxScore">

                    <div>
                        <label><i class="fas fa-users"></i> Select Class:</label>
                        <select id="direct_class_id" name="class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-file-alt"></i> Select Exam Type:</label>
                        <select id="direct_exam_type" name="exam_type" required>
                            <option value="">Select Exam Type</option>
                            <?php foreach ($exam_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['exam_type']); ?>"><?php echo htmlspecialchars($type['exam_type']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-tags"></i> Select Category:</label>
                        <select id="direct_category" name="category" required disabled>
                            <option value="">Select Category</option>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-book"></i> Select Subject:</label>
                        <select id="direct_subject_id" name="subject_id" required disabled>
                            <option value="">Select Subject</option>
                        </select>
                    </div>

                    <div class="topic-wrapper">
                        <label><i class="fas fa-lightbulb"></i> Topic (for activities):</label>
                        <div class="topic-field">
                            <input type="text" id="direct_topic" name="topic" placeholder="e.g., Photosynthesis, Algebra Basics, Creative Writing" maxlength="255" class="topic-input">
                            <div class="topic-count" id="direct_topic_count">0/255</div>
                        </div>
                        <small class="topic-help">Enter a descriptive topic for this activity (optional)</small>
                    </div>

                    <div>
                        <label><i class="fas fa-star"></i> Admin Max Score:</label>
                        <select id="direct_max_score" name="max_score" required>
                            <option value="">Select Max Score</option>
                            <?php foreach ($max_scores as $score): ?>
                                <option value="<?php echo $score['max_score']; ?>"><?php echo $score['max_score']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-pencil-alt"></i> Teacher's Marking Range (Max Score):</label>
                        <input type="number" id="direct_teacher_max_score" name="teacher_max_score" min="1" step="1" required>
                    </div>

                    <button type="button" id="loadStudentsBtn" disabled> Load Students</button>
                    <div id="studentMarksSection" class="student-list" style="display: none;"></div>
                    <button type="submit" name="enter_marks" id="submitMarksBtn" disabled><i class="fas fa-save"></i> Submit Marks</button>
                </form>
            </div>

            <div id="csvUpload" class="tab-content">
                <form method="post" enctype="multipart/form-data" id="marksForm">
                    <div>
                        <label><i class="fas fa-users"></i> Select Class:</label>
                        <select id="class_id" name="class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['class_id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-file-alt"></i> Select Exam Type:</label>
                        <select id="exam_type" name="exam_type" required>
                            <option value="">Select Exam Type</option>
                            <?php foreach ($exam_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type['exam_type']); ?>"><?php echo htmlspecialchars($type['exam_type']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-tags"></i> Select Category:</label>
                        <select id="category" name="category" required disabled>
                            <option value="">Select Category</option>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-book"></i> Select Subject:</label>
                        <select id="subject_id" name="subject_id" required disabled></select>
                    </div>

                    <div class="topic-wrapper">
                        <label><i class="fas fa-lightbulb"></i> Topic (for activities):</label>
                        <div class="topic-field">
                            <i class="fas fa-lightbulb topic-icon" aria-hidden="true"></i>
                            <input type="text" id="topic" name="topic" placeholder="e.g., Photosynthesis, Algebra Basics, Creative Writing" maxlength="255" class="topic-input">
                            <div class="topic-count" id="topic_count">0/255</div>
                        </div>
                        <small class="topic-help">Enter a descriptive topic for this activity (optional)</small>
                    </div>

                    <div>
                        <label><i class="fas fa-star"></i> Admin Max Score:</label>
                        <select id="max_score" name="max_score" required>
                            <option value="">Select Max Score</option>
                            <?php foreach ($max_scores as $score): ?>
                                <option value="<?php echo $score['max_score']; ?>"><?php echo $score['max_score']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label><i class="fas fa-pencil-alt"></i> Teacher's Marking Range (Max Score):</label>
                        <input type="number" id="teacher_max_score" name="teacher_max_score" min="1" step="1" required>
                    </div>

                    <button type="button" id="generateTemplateBtn" disabled><i class="fas fa-file-download"></i> Generate CSV Template</button>

                    <div class="file-upload">
                        <label for="marks_file"><i class="fas fa-upload"></i> Choose CSV File</label>
                        <input type="file" id="marks_file" name="marks_file" accept=".csv" required>
                        <div class="file-name" id="file-name"></div>
                    </div>

                    <button type="submit" name="upload_marks" id="uploadMarksBtn" disabled><i class="fas fa-cloud-upload-alt"></i> Upload Marks</button>
                </form>
            </div>
        </div>

        <div class="dashboard-section" id="viewSection" style="display: none;">
            <div class="section-header">
                <button class="back-button" onclick="showMainOptions()"><i class="fas fa-arrow-left"></i> Back</button>
            </div>
            <div>
                <label><i class="fas fa-users"></i> Select Class:</label>
                <select id="view_class_id" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['class_id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><i class="fas fa-file-alt"></i> Select Exam Type:</label>
                <select id="view_exam_type" required>
                    <option value="">Select Exam Type</option>
                    <?php foreach ($exam_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type['exam_type']); ?>"><?php echo htmlspecialchars($type['exam_type']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><i class="fas fa-tags"></i> Select Category:</label>
                <select id="view_category" required disabled>
                    <option value="">Select Category</option>
                </select>
            </div>
            <div>
                <label><i class="fas fa-book"></i> Select Subject:</label>
                <select id="view_subject_id" required disabled></select>
            </div>
            <button type="button" id="viewResultsBtn" disabled><i class="fas fa-eye"></i> View Results</button>
            <div class="search-bar" style="display: none;">
                <input type="text" id="studentSearch" placeholder="Search for a student...">
                <i class="fas fa-search"></i>
            </div>
            <div id="examResultsSection" class="student-list" style="display: none;"></div>
        </div>

        <div class="dashboard-section" id="progressSection" style="display: none;">
            <div class="section-header">
                <button class="back-button" onclick="showMainOptions()"><i class="fas fa-arrow-left"></i> Back</button>
                <h2><i class="fas fa-tasks"></i> Track Your Progress</h2>
            </div>
            <div class="progress-filters">
                <div class="filter-group">
                    <label><i class="fas fa-users"></i> Select Class:</label>
                    <select id="progress_class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['class_id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-file-alt"></i> Select Exam Type:</label>
                    <select id="progress_exam_type" required>
                        <option value="">Select Exam Type</option>
                        <?php foreach ($exam_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['exam_type']); ?>"><?php echo htmlspecialchars($type['exam_type']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-tags"></i> Select Category:</label>
                    <select id="progress_category" required disabled>
                        <option value="">Select Category</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-book"></i> Select Subject:</label>
                    <select id="progress_subject_id" required disabled>
                        <option value="">Select Subject</option>
                    </select>
                </div>
                <button type="button" id="loadProgressBtn" disabled><i class="fas fa-sync-alt"></i> Load Progress</button>
            </div>
            <div class="progress-summary" id="progress-summary" style="display: none;">
                <div class="term-info">
                    <div class="term-header">
                        <i class="fas fa-calendar-alt"></i>
                        <div>
                            <h3>Current Term Analytics</h3>
                            <p id="current-term"><?php echo htmlspecialchars($current_term_name); ?> - <?php echo htmlspecialchars($current_school_year); ?></p>
                        </div>
                    </div>
                    <div id="overall-progress-circle">
                        <div class="progress-circle-container">
                            <svg class="progress-ring" width="120" height="120">
                                <circle class="progress-ring-circle-bg" cx="60" cy="60" r="50"></circle>
                                <circle class="progress-ring-circle" cx="60" cy="60" r="50" id="progressCircle"></circle>
                            </svg>
                            <div class="progress-circle-text">
                                <span id="completion-percentage">0%</span>
                                <span class="progress-label">Complete</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="progress-overview">
                    <div class="progress-card total-students">
                        <div class="card-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="card-content">
                            <h4>Total Students</h4>
                            <p id="total-students-count">0</p>
                        </div>
                    </div>
                    <div class="progress-card completed-assessments">
                        <div class="card-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="card-content">
                            <h4>Completed</h4>
                            <p id="completed-assessments">0</p>
                        </div>
                    </div>
                    <div class="progress-card pending-assessments">
                        <div class="card-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="card-content">
                            <h4>Pending</h4>
                            <p id="pending-assessments">0</p>
                        </div>
                    </div>
                    <div class="progress-card completion-rate">
                        <div class="card-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="card-content">
                            <h4>Completion Rate</h4>
                            <p id="completion-rate">0%</p>
                        </div>
                    </div>
                </div>

                <div class="detailed-progress">
                    <h3 class="detailed-progress-header"><i class="fas fa-chart-bar"></i> Detailed Progress</h3>
                    <div class="detailed-view-toggle">
                        <button id="expand-all-btn" class="toggle-btn">
                            <i class="fas fa-expand-alt"></i> Expand All
                        </button>
                        <button id="collapse-all-btn" class="toggle-btn">
                            <i class="fas fa-compress-alt"></i> Collapse All
                        </button>
                    </div>
                    <div class="exam-type-progress" id="exam-type-progress">
                        <!-- Detailed progress bars for each exam type will be added here -->
                    </div>
                </div>
            </div>
            <div id="no-progress-data" class="no-data-message" style="display: none;">
                <i class="fas fa-info-circle"></i>
                <p>No assessment data available for the selected class and subject.</p>
                <button id="try-another-class-btn" class="btn-primary">
                    <i class="fas fa-search"></i> Try Another Class/Subject
                </button>
            </div>
        </div>
    </div>


   <div id="existingMarksModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <i class="fas fa-exclamation-triangle warning-icon"></i>
            <h3>Existing Marks Found</h3>
        </div>
        <div class="modal-body">
            <p>There are existing marks for this subject. Would you like to update them?</p>
            <div class="modal-buttons">
                <button id="confirmOverwrite" class="btn-primary">
                    <i class="fas fa-check"></i> Yes, Update
                </button>
                <button id="cancelOverwrite" class="btn-secondary">
                    <i class="fas fa-times"></i> No, Cancel
                </button>
            </div>
        </div>
    </div>
</div>

   <script>
    const enterMarksForm = document.getElementById('enterMarksForm');
const studentMarksSection = document.getElementById('studentMarksSection');
const submitMarksBtn = document.getElementById('submitMarksBtn');
        const classSelect = document.getElementById('class_id');
        const subjectSelect = document.getElementById('subject_id');
        const generateTemplateBtn = document.getElementById('generateTemplateBtn');
        const loadStudentsBtn = document.getElementById('loadStudentsBtn');
        const maxScoreSelect = document.getElementById('max_score');
        const fileInput = document.getElementById('marks_file');
        const fileName = document.getElementById('file-name');
        const uploadMarksBtn = document.getElementById('uploadMarksBtn');
        const categorySelect = document.getElementById('category');
        const viewCategorySelect = document.getElementById('view_category');
        const viewClassSelect = document.getElementById('view_class_id');
        const viewSubjectSelect = document.getElementById('view_subject_id');
        const viewExamTypeSelect = document.getElementById('view_exam_type');
        const viewResultsBtn = document.getElementById('viewResultsBtn');

        function updateCategories(examType, categoryElement) {
    if (examType) {
        fetch(`?action=get_categories&exam_type=${examType}`)
            .then(response => response.json())
            .then(categories => {
                categoryElement.innerHTML = '<option value="">Select Category</option>';
                categories.forEach(category => {
                    const option = document.createElement('option');
                    option.value = category.category;
                    option.textContent = category.category;
                    categoryElement.appendChild(option);
                });
                categoryElement.disabled = false;
            });
    } else {
        categoryElement.innerHTML = '<option value="">Select Category</option>';
        categoryElement.disabled = true;
    }
}

// This event listener is now handled below in the consolidated version

document.getElementById('view_exam_type').addEventListener('change', function() {
    updateCategories(this.value, viewCategorySelect);
    viewResultsBtn.disabled = true;
});

        function updateSubjects(classId, examType, category, subjectSelectElement) {
            // Support flexible call signatures:
            // 1) updateSubjects(classId, examType, category, subjectSelectElement)
            // 2) updateSubjects(classId, subjectSelectElement)
            // 3) updateSubjects(classId, examType, subjectSelectElement)
            // Normalize when callers pass the select element in different positions.
            if (!subjectSelectElement) {
                if (examType && (typeof examType === 'object' || typeof examType === 'function') && examType.nodeType) {
                    subjectSelectElement = examType;
                    examType = '';
                    category = '';
                } else if (category && (typeof category === 'object' || typeof category === 'function') && category.nodeType) {
                    subjectSelectElement = category;
                    category = '';
                }
            }

            if (!subjectSelectElement) {
                console.warn('updateSubjects called without a subjectSelectElement; skipping update.');
                return;
            }

            if (classId) {
                console.log('Loading subjects for:', {classId, examType, category});
                // Show loading placeholder and disable while fetching
                try {
                    subjectSelectElement.disabled = true;
                    subjectSelectElement.innerHTML = '<option value="">Loading subjects...</option>';
                } catch (e) {
                    console.warn('Could not set loading state on subject select', e);
                }

                fetch(`?action=get_subjects&class_id=${classId}&exam_type=${encodeURIComponent(examType || '')}&category=${encodeURIComponent(category || '')}`)
                    .then(response => {
                        // Read as text first so we can detect non-JSON responses (HTML/debug output)
                        return response.text().then(text => ({ ok: response.ok, status: response.status, headers: response.headers, text }));
                    })
                    .then(({ ok, status, headers, text }) => {
                        if (!ok) {
                            console.error('Network response was not ok', status, text);
                            throw new Error('Network response was not ok');
                        }

                        // Quick heuristic: if response starts with '<' it's probably HTML
                        const trimmed = text.trim();
                        if (trimmed.startsWith('<')) {
                            console.error('Server returned HTML when JSON expected:', trimmed.substring(0, 300));
                            throw new Error('Server returned HTML instead of JSON');
                        }

                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (parseErr) {
                            console.error('Failed to parse JSON from server response:', text.substring(0, 1000));
                            throw new Error('Invalid JSON response from server');
                        }

                        console.log('Received data:', data);

                        // If server returned an authorization/session error, redirect to login
                        if (data && data.error) {
                            const errLower = String(data.error).toLowerCase();
                            if (errLower.includes('unauthorized') || errLower.includes('session') || errLower.includes('login')) {
                                // Redirect to login so teacher can re-authenticate
                                window.location.href = 'index.php';
                                // Stop further processing
                                throw new Error('Redirecting to login');
                            }
                            throw new Error(data.error);
                        }
                        return data;
                    })
                    .then(data => {
                        const subjects = Array.isArray(data) ? data : [];
                        if (subjects.length === 0) {
                            // Check if we have exam type and category to provide more specific message
                            const examType = document.getElementById('exam_type') ? document.getElementById('exam_type').value : '';
                            const category = document.getElementById('category') ? document.getElementById('category').value : '';
                            const directExamType = document.getElementById('direct_exam_type') ? document.getElementById('direct_exam_type').value : '';
                            const directCategory = document.getElementById('direct_category') ? document.getElementById('direct_category').value : '';
                            const viewExamType = document.getElementById('view_exam_type') ? document.getElementById('view_exam_type').value : '';
                            const viewCategory = document.getElementById('view_category') ? document.getElementById('view_category').value : '';
                            const progressExamType = document.getElementById('progress_exam_type') ? document.getElementById('progress_exam_type').value : '';
                            const progressCategory = document.getElementById('progress_category') ? document.getElementById('progress_category').value : '';
                            
                            // Determine which form we're in and get the appropriate values
                            let currentExamType = '';
                            let currentCategory = '';
                            
                            if (subjectSelectElement.id === 'subject_id' && examType && category) {
                                currentExamType = examType;
                                currentCategory = category;
                            } else if (subjectSelectElement.id === 'direct_subject_id' && directExamType && directCategory) {
                                currentExamType = directExamType;
                                currentCategory = directCategory;
                            } else if (subjectSelectElement.id === 'view_subject_id' && viewExamType && viewCategory) {
                                currentExamType = viewExamType;
                                currentCategory = viewCategory;
                            } else if (subjectSelectElement.id === 'progress_subject_id' && progressExamType && progressCategory) {
                                currentExamType = progressExamType;
                                currentCategory = progressCategory;
                            }
                            
                            if (currentExamType && currentCategory) {
                                subjectSelectElement.innerHTML = `<option value="">No subjects assigned to ${currentExamType} - ${currentCategory}</option>`;
                            } else {
                                subjectSelectElement.innerHTML = '<option value="">No subjects found</option>';
                            }
                            subjectSelectElement.disabled = true;
                            console.log('No subjects returned from server');
                            return;
                        }

                        subjectSelectElement.innerHTML = '<option value="">Select Subject</option>';
                        subjects.forEach(subject => {
                            const option = document.createElement('option');
                            option.value = subject.subject_id;
                            option.textContent = subject.subject_name;
                            subjectSelectElement.appendChild(option);
                        });
                        subjectSelectElement.disabled = false;
                        console.log('Subject dropdown enabled with', subjects.length, 'subjects');
                        // If only one subject is available, auto-select it and trigger change so dependent flows (like progress) enable
                        if (subjects.length === 1) {
                            try {
                                subjectSelectElement.value = subjects[0].subject_id;
                                subjectSelectElement.dispatchEvent(new Event('change'));
                                if (typeof checkProgressForm === 'function') {
                                    checkProgressForm();
                                }
                            } catch (e) {
                                console.warn('Auto-select subject failed', e);
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading subjects:', error);
                        try {
                            subjectSelectElement.innerHTML = '<option value="">Error loading subjects</option>';
                            subjectSelectElement.disabled = true;
                        } catch (e) {
                            console.warn('Unable to update subject select after error', e);
                        }
                    });
            } else {
                try {
                    subjectSelectElement.innerHTML = '<option value="">Select Subject</option>';
                    subjectSelectElement.disabled = true;
                } catch (e) {
                    console.warn('updateSubjects called without sufficient parameters and no subject select available', e);
                }
            }
        }

        // Update event listeners for new form flow
        classSelect.addEventListener('change', function () {
            // Reset subject dropdown when class changes
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            subjectSelect.disabled = true;
            generateTemplateBtn.disabled = true;
        });

        viewClassSelect.addEventListener('change', function () {
            updateSubjects(this.value, '', '', viewSubjectSelect);
        });

        maxScoreSelect.addEventListener('change', function () {
            generateTemplateBtn.disabled = !this.value;
            loadStudentsBtn.disabled = !this.value;
        });

        fileInput.addEventListener('change', function () {
            if (this.files.length > 0) {
                fileName.textContent = this.files[0].name;
                uploadMarksBtn.disabled = false;
            } else {
                fileName.textContent = '';
                uploadMarksBtn.disabled = true;
            }
        });

        generateTemplateBtn.addEventListener('click', function () {
            const classId = classSelect.value;
            const subjectId = subjectSelect.value;
            const examType = document.getElementById('exam_type').value;
            const category = document.getElementById('category').value;
            const topic = document.getElementById('topic').value;
            const maxScore = maxScoreSelect.value;
            const teacherMaxScore = document.getElementById('teacher_max_score').value;

            // Validate all required fields
            if (!classId || !subjectId || !examType || !category || !maxScore || !teacherMaxScore) {
                alert("Please select all required fields: class, subject, exam type, category, max score, and teacher's marking range.");
                return;
            }

            // Check if category is selected
            if (category === "") {
                alert("Please select a category before generating the template.");
                return;
            }

            // Validate teacher's max score
            if (teacherMaxScore <= 0) {
                alert("Teacher's marking range must be greater than 0");
                return;
            }

            // Encode the category and topic to handle special characters
            const encodedCategory = encodeURIComponent(category);
            const encodedTopic = encodeURIComponent(topic);
            window.location.href = `?action=generate_template&class_id=${classId}&subject_id=${subjectId}&exam_type=${examType}&category=${encodedCategory}&topic=${encodedTopic}&max_score=${maxScore}&teacher_max_score=${teacherMaxScore}`;
        });

        // Update the category selection when exam type changes
        document.getElementById('exam_type').addEventListener('change', function() {
            const examType = this.value;
            const categorySelect = document.getElementById('category');
            
            // Reset subject dropdown when exam type changes
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            subjectSelect.disabled = true;
            
            if (examType) {
                fetch(`?action=get_categories&exam_type=${examType}`)
                    .then(response => response.json())
                    .then(categories => {
                        categorySelect.innerHTML = '<option value="">Select Category</option>';
                        categories.forEach(category => {
                            const option = document.createElement('option');
                            option.value = category.category;
                            option.textContent = category.category;
                            categorySelect.appendChild(option);
                        });
                        categorySelect.disabled = false;
                        // Enable/disable generate template button based on selection
                        document.getElementById('generateTemplateBtn').disabled = !categorySelect.value;
                    });
            } else {
                categorySelect.innerHTML = '<option value="">Select Category</option>';
                categorySelect.disabled = true;
                document.getElementById('generateTemplateBtn').disabled = true;
            }
        });

        // Add event listener for category selection - now loads subjects
        document.getElementById('category').addEventListener('change', function() {
            const classId = document.getElementById('class_id').value;
            const examType = document.getElementById('exam_type').value;
            const category = this.value;
            
            if (classId && examType && category) {
                updateSubjects(classId, examType, category, subjectSelect);
            } else {
                // Reset subject dropdown if not all fields are selected
                subjectSelect.innerHTML = '<option value="">Select Subject</option>';
                subjectSelect.disabled = true;
            }
            
            const generateTemplateBtn = document.getElementById('generateTemplateBtn');
            generateTemplateBtn.disabled = !this.value;
        });

        loadStudentsBtn.addEventListener('click', function() {
    const classId = document.getElementById('direct_class_id').value;
    const subjectId = document.getElementById('direct_subject_id').value;
    const examType = document.getElementById('direct_exam_type').value;
    const category = document.getElementById('direct_category').value;
    const maxScore = document.getElementById('direct_max_score').value;
    const teacherMaxScore = document.getElementById('direct_teacher_max_score').value;

    if (!teacherMaxScore || teacherMaxScore <= 0) {
        alert("Please enter a valid marking range greater than 0");
        return;
    }

    if (classId && subjectId && examType && category && maxScore) {
        // First check for existing scores
        fetch(`?action=check_existing_scores&class_id=${classId}&subject_id=${subjectId}&exam_type=${examType}&category=${category}`)
            .then(response => response.json())
            .then(data => {
                if (data.hasExistingScores) {
                    showExistingMarksModal(() => {
                        loadStudents(classId, subjectId, examType, category, maxScore, teacherMaxScore);
                    });
                } else {
                    loadStudents(classId, subjectId, examType, category, maxScore, teacherMaxScore);
                }
            });
    } else {
        alert("Please select all required fields (class, subject, exam type, category, and max score).");
    }
});

        viewResultsBtn.addEventListener('click', function () {
            const classId = viewClassSelect.value;
    const subjectId = viewSubjectSelect.value;
    const examType = viewExamTypeSelect.value;
    const category = viewCategorySelect.value;

            if (classId && subjectId && examType && category) {
                fetch(`?action=get_exam_results&class_id=${classId}&subject_id=${subjectId}&exam_type=${examType}&category=${category}`)
                    .then(response => response.json())
                    .then(results => {
                        const examResultsSection = document.getElementById('examResultsSection');
                        const searchBar = document.querySelector('.search-bar');
                        examResultsSection.innerHTML = '';

                        if (results.length > 0) {
                            // Add a header showing the exam type and category
                            const header = document.createElement('div');
                            header.className = 'results-header';
                            header.innerHTML = `
                                <h3 class="text-xl font-bold mb-4">
                                    ${examType} - ${category}
                                </h3>
                            `;
                            examResultsSection.appendChild(header);

                            const table = document.createElement('table');
                            table.innerHTML = `
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Score</th>
                                        <th>Max Score</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${results.map(result => `
                                        <tr>
                                            <td class="student-name">${result.student_name}</td>
                                            <td>
                                                <input type="number" 
                                                       class="edit-score-input" 
                                                       value="${result.score !== null ? result.score : ''}" 
                                                       min="0" 
                                                       max="${result.max_score}" 
                                                       step="${examType.toLowerCase() === 'exam' ? '1' : '0.1'}" 
                                                       data-student-id="${result.id}"
                                                       ${result.status === 'absent' ? 'disabled' : ''}>
                                            </td>
                                            <td>${result.max_score}</td>
                                            <td>
                                                <select class="exam-status" data-student-id="${result.id}">
                                                    <option value="present" ${result.status === 'present' ? 'selected' : ''}>Present</option>
                                                    <option value="absent" ${result.status === 'absent' ? 'selected' : ''}>Absent</option>
                                                    <option value="not_submitted" ${result.status === 'not_submitted' ? 'selected' : ''}>Not Submitted</option>
                                                </select>
                                            </td>
                                            <td class="action-buttons">
                                                <button class="save-score" data-student-id="${result.id}">
                                                    <i class="fas fa-save"></i> Save
                                                </button>
                                                <button class="delete-score" data-student-id="${result.id}"
                                                        ${result.status === 'not_submitted' ? 'disabled' : ''}>
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            `;

                            examResultsSection.appendChild(table);
                            searchBar.style.display = 'block';

                            // Add event listeners for save and delete buttons
                            table.addEventListener('click', function(e) {
                                const target = e.target.closest('button');
                                if (!target || target.disabled) return;

                                const studentId = target.dataset.studentId;
                                const row = target.closest('tr');
                                const scoreInput = row.querySelector('.edit-score-input');
                                const statusSelect = row.querySelector('.exam-status');
                                const maxScoreCell = row.querySelector('td:nth-child(3)'); // Max score is in 3rd column

                                if (target.classList.contains('save-score')) {
                                    const newScore = scoreInput.value;
                                    const status = statusSelect.value;
                                    const maxScore = parseFloat(maxScoreCell.textContent);
                                    
                                    if (status === 'present' && !newScore) {
                                        showNotification('Please enter a score for present students', false);
                                        return;
                                    }

                                    // Validate score doesn't exceed max score
                                    if (status === 'present' && newScore) {
                                        const scoreValue = parseFloat(newScore);
                                        if (isNaN(scoreValue)) {
                                            showNotification('Please enter a valid numeric score', false);
                                            return;
                                        }
                                        if (scoreValue < 0) {
                                            showNotification('Score cannot be negative', false);
                                            return;
                                        }
                                        if (scoreValue > maxScore) {
                                            showNotification(`Score cannot exceed the maximum score of ${maxScore}`, false);
                                            return;
                                        }
                                    }

                                    // Apply decimal place rules based on exam type
                                    let finalScore = newScore;
                                    if (status === 'present') {
                                        if (examType.toLowerCase() === 'exam') {
                                            finalScore = Math.round(parseFloat(newScore));
                                        } else if (examType.toLowerCase() === 'activity') {
                                            finalScore = parseFloat(parseFloat(newScore).toFixed(1));
                                        }
                                    }

                                    updateScore(studentId, status === 'present' ? finalScore : null, classId, subjectId, examType, category);
                                } else if (target.classList.contains('delete-score')) {
                                    if (confirm('Are you sure you want to delete this score?')) {
                                        deleteScore(studentId, classId, subjectId, examType, category);
                                    }
                                }
                            });

                            // Add event listener for status change
                            table.addEventListener('change', function(e) {
                                if (e.target.classList.contains('exam-status')) {
                                    const row = e.target.closest('tr');
                                    const scoreInput = row.querySelector('.edit-score-input');
                                    const deleteBtn = row.querySelector('.delete-score');
                                    
                                    if (e.target.value === 'absent') {
                                        scoreInput.value = '';
                                        scoreInput.disabled = true;
                                        deleteBtn.disabled = false;
                                    } else if (e.target.value === 'not_submitted') {
                                        scoreInput.value = '';
                                        scoreInput.disabled = true;
                                        deleteBtn.disabled = true;
                                    } else {
                                        scoreInput.disabled = false;
                                        deleteBtn.disabled = false;
                                    }
                                }
                            });

                            // Add real-time validation for score inputs
                            table.addEventListener('input', function(e) {
                                if (e.target.classList.contains('edit-score-input')) {
                                    const row = e.target.closest('tr');
                                    const scoreInput = e.target;
                                    const maxScoreCell = row.querySelector('td:nth-child(3)');
                                    const maxScore = parseFloat(maxScoreCell.textContent);
                                    const scoreValue = parseFloat(scoreInput.value);
                                    
                                    // Clear any previous validation classes
                                    scoreInput.classList.remove('valid', 'invalid');
                                    
                                    // Validate score in real-time
                                    if (scoreInput.value.trim() !== '') {
                                        if (isNaN(scoreValue)) {
                                            // Invalid input (not a number) - show red
                                            scoreInput.classList.add('invalid');
                                        } else if (scoreValue < 0) {
                                            // Score is negative - show red
                                            scoreInput.classList.add('invalid');
                                        } else if (scoreValue > maxScore) {
                                            // Score exceeds max score - show red
                                            scoreInput.classList.add('invalid');
                                        } else {
                                            // Score is valid - show green
                                            scoreInput.classList.add('valid');
                                        }
                                    }
                                }
                            });

                            // Add search functionality
                            const searchInput = document.getElementById('studentSearch');
                            searchInput.addEventListener('input', function() {
                                const searchTerm = this.value.toLowerCase();
                                const rows = table.tBodies[0].rows;
                                
                                for (let row of rows) {
                                    const studentName = row.querySelector('.student-name').textContent.toLowerCase();
                                    row.style.display = studentName.includes(searchTerm) ? '' : 'none';
                                }
                            });
                        } else {
                            examResultsSection.innerHTML = '<p>No students found assigned to this subject.</p>';
                            searchBar.style.display = 'none';
                        }
                        examResultsSection.style.display = 'block';
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Error loading exam results', false);
                    });
            } else {
                alert("Please select all required fields.");
            }
        });
    function showNotification(message, isSuccess) {
    const notification = document.getElementById('notification');
    notification.textContent = message;
    notification.className = `notification ${isSuccess ? 'success' : 'error'}`;
    notification.style.display = 'block';
    
    // Add icon to notification
    const icon = document.createElement('i');
    icon.className = `fas ${isSuccess ? 'fa-check-circle' : 'fa-exclamation-circle'}`;
    notification.insertBefore(icon, notification.firstChild);

    // Animate notification
    notification.style.opacity = '1';
    notification.style.transform = 'translateY(0)';

    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px)';
        setTimeout(() => {
            notification.style.display = 'none';
            notification.style.transform = 'translateY(20px)';
        }, 300);
    }, 3000);
}

function updateScore(studentId, newScore, classId, subjectId, examType, category) {
    fetch('update_score.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            studentId: studentId,
            newScore: newScore,
            classId: classId,
            subjectId: subjectId,
            examType: examType,
            category: category
        }),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message || 'Score updated successfully', true);
        } else {
            showNotification(data.message || 'Failed to update score', false);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred while updating the score', false);
    });
}

function deleteScore(studentId, classId, subjectId, examType, category) {
    fetch('delete_score.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            studentId: studentId,
            classId: classId,
            subjectId: subjectId,
            examType: examType,
            category: category
        }),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Score deleted successfully', true);
            // Refresh the current view
            loadStudents(classId, subjectId, examType, category);
        } else {
            showNotification(data.message || 'Failed to delete score', false);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred while deleting the score', false);
    });
}

function showExistingMarksModal(onConfirm) {
    const modal = document.getElementById('existingMarksModal');
    const confirmBtn = document.getElementById('confirmOverwrite');
    const cancelBtn = document.getElementById('cancelOverwrite');

    // Show modal with animation
    modal.classList.add('active');
    
    // Handle confirm button
    confirmBtn.onclick = () => {
        modal.classList.remove('active');
        onConfirm();
    };

    // Handle cancel button
    cancelBtn.onclick = () => {
        modal.classList.remove('active');
    };

    // Close modal if clicked outside
    modal.onclick = (e) => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    };
}

function loadStudents(classId, subjectId, examType, category, maxScore, teacherMaxScore) {
    // Update hidden form fields
    document.getElementById('directClassId').value = classId;
    document.getElementById('directSubjectId').value = subjectId;
    document.getElementById('directExamType').value = examType;
    document.getElementById('directCategory').value = category;
    document.getElementById('directMaxScore').value = maxScore;

    // Fetch and display students
    fetch(`?action=get_students&class_id=${classId}&subject_id=${subjectId}`)
        .then(response => response.json())
        .then(students => {
            const studentMarksSection = document.getElementById('studentMarksSection');
            studentMarksSection.innerHTML = `
                <table>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Raw Score (0-${teacherMaxScore})</th>
                            <th>Normalized Score (Max: ${maxScore})</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${students.map(student => `
                            <tr>
                                <td>${student.name}</td>
                                <td>
                                    <input type="number" 
                                           name="raw_scores[${student.id}]" 
                                           min="0" 
                                           max="${teacherMaxScore}" 
                                           step="0.1" 
                                           class="raw-score" 
                                           oninput="updateNormalizedScore(this, ${teacherMaxScore}, ${maxScore})">
                                </td>
                                <td>
                                    <input type="number" 
                                           name="students[${student.id}]" 
                                           class="normalized-score" 
                                           readonly>
                                </td>
                                <td>
                                    <select name="status[${student.id}]" class="exam-status" onchange="handleStatusChange(this)">
                                        <option value="present">Present</option>
                                        <option value="absent">Missed</option>
                                    </select>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            
            if (students.length > 0) {
                studentMarksSection.style.display = 'block';
                document.getElementById('submitMarksBtn').disabled = false;
                // Highlight action buttons in light green for visibility
                try {
                    document.getElementById('loadStudentsBtn').classList.add('btn-success');
                    document.getElementById('submitMarksBtn').classList.add('btn-success');
                } catch (e) {}
    } else {
                studentMarksSection.innerHTML = '<p>No students found for this subject in this class.</p>';
                document.getElementById('submitMarksBtn').disabled = true;
                try {
                    document.getElementById('loadStudentsBtn').classList.remove('btn-success');
                    document.getElementById('submitMarksBtn').classList.remove('btn-success');
                } catch (e) {}
            }
        })
        .catch(error => {
            console.error('Error:', error);
            studentMarksSection.innerHTML = '<p>Error loading students. Please try again.</p>';
            document.getElementById('submitMarksBtn').disabled = true;
            try {
                document.getElementById('loadStudentsBtn').classList.remove('btn-success');
                document.getElementById('submitMarksBtn').classList.remove('btn-success');
            } catch (e) {}
        });
}

function handleStatusChange(selectElement) {
    const row = selectElement.closest('tr');
    const rawScoreInput = row.querySelector('.raw-score');
    const normalizedScoreInput = row.querySelector('.normalized-score');
    
    if (selectElement.value === 'absent') {
        rawScoreInput.value = '';
        normalizedScoreInput.value = '';
        rawScoreInput.disabled = true;
    } else {
        rawScoreInput.disabled = false;
    }
}

document.getElementById('enterMarksForm').addEventListener('submit', function(e) {
    e.preventDefault();
    let isValid = true;
    const formData = new FormData();
    
    // Get the max scores for normalization
    const teacherMaxScore = parseFloat(document.getElementById('direct_teacher_max_score').value);
    const adminMaxScore = parseFloat(document.getElementById('direct_max_score').value);
    const examType = document.getElementById('direct_exam_type').value.toLowerCase();
    
    formData.append('class_id', document.getElementById('directClassId').value);
    formData.append('subject_id', document.getElementById('directSubjectId').value);
    formData.append('exam_type', document.getElementById('directExamType').value);
    formData.append('category', document.getElementById('directCategory').value);
    formData.append('topic', document.getElementById('direct_topic').value);
    formData.append('max_score', adminMaxScore);
    
    // Get all student rows
    const rows = document.querySelectorAll('#studentMarksSection tbody tr');
    
    rows.forEach(row => {
        const rawScoreInput = row.querySelector('.raw-score');
        const studentId = rawScoreInput.name.match(/\d+/)[0];
        const rawScore = parseFloat(rawScoreInput.value);
        const status = row.querySelector('.exam-status') ? row.querySelector('.exam-status').value : 'present';
        
        if (status === 'present') {
            if (!rawScore && rawScore !== 0) {
                alert('Please enter a score for present students or mark them as absent');
                isValid = false;
                return;
            }
            if (rawScore > teacherMaxScore) {
                alert('Score cannot be greater than your specified marking range');
                isValid = false;
                return;
            }
            
            // Calculate normalized score with appropriate decimal places
            let normalizedScore = (rawScore / teacherMaxScore) * adminMaxScore;
            normalizedScore = Math.min(normalizedScore, adminMaxScore); // Ensure it doesn't exceed max score
            
            // Apply different decimal places based on exam type
            if (examType === 'exam') {
                normalizedScore = Math.round(normalizedScore); // No decimal places for exam
            } else if (examType === 'activity') {
                normalizedScore = parseFloat(normalizedScore.toFixed(1)); // 1 decimal place for activity
            } else {
                normalizedScore = parseFloat(normalizedScore.toFixed(1)); // Default to 1 decimal place
            }
            
            // Add the normalized score to formData
            formData.append(`students[${studentId}]`, normalizedScore);
        }
        
        if (status) {
            formData.append(`status[${studentId}]`, status);
        }
    });
    
    if (isValid) {
        fetch('update_score.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text().then(text => ({ ok: response.ok, text })))
        .then(({ ok, text }) => {
            const trimmed = text.trim();
            // If server returned HTML (Xdebug/stacktrace), log and show it
            if (trimmed.startsWith('<')) {
                console.error('Server returned HTML when JSON expected:', trimmed.substring(0, 2000));
                showNotification('Server error while submitting marks (see console for details)', false);
                return;
            }

            let data;
            try {
                data = JSON.parse(text);
            } catch (err) {
                console.error('Failed to parse JSON response from update_score.php:', text.substring(0, 2000));
                showNotification('Invalid server response while submitting marks (see console)', false);
                return;
            }

            if (data.success) {
                showNotification('Marks submitted successfully', true);
                setTimeout(() => showMainOptions(), 1500);
            } else {
                showNotification(data.message || 'Failed to submit marks', false);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred while submitting marks', false);
        });
    }
});

// Add new function to clear only scores while keeping form fields
function clearScores() {
    const rawScoreInputs = document.querySelectorAll('.raw-score');
    const normalizedScoreInputs = document.querySelectorAll('.normalized-score');
    const statusSelects = document.querySelectorAll('.exam-status');
    
    rawScoreInputs.forEach(input => input.value = '');
    normalizedScoreInputs.forEach(input => input.value = '');
    statusSelects.forEach(select => select.value = 'present');
}

function showSection(sectionId) {
    // Hide main options
    document.getElementById('mainOptions').style.display = 'none';
    
    // Hide all sections
    document.querySelectorAll('.dashboard-section').forEach(section => {
        section.style.display = 'none';
    });
    
    // Show selected section
    document.getElementById(sectionId).style.display = 'block';
}

function showMainOptions() {
    // Show main options
    document.getElementById('mainOptions').style.display = 'grid';
    
    // Hide all sections
    document.querySelectorAll('.dashboard-section').forEach(section => {
        section.style.display = 'none';
    });

    // Reset all form fields to their initial state
    const forms = ['enterMarksForm', 'marksForm'];
    forms.forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            form.reset();
        }
    });

    // Reset and disable subject dropdowns
    const subjectSelects = ['direct_subject_id', 'subject_id', 'view_subject_id', 'progress_subject_id'];
    subjectSelects.forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.innerHTML = '<option value="">Select Subject</option>';
            select.disabled = true;
            select.value = '';
        }
    });

    // Reset and disable category dropdowns
    const categorySelects = ['direct_category', 'category', 'view_category'];
    categorySelects.forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.innerHTML = '<option value="">Select Category</option>';
            select.disabled = true;
            select.value = '';
        }
    });

    // Reset class selects
    const classSelects = ['direct_class_id', 'class_id', 'view_class_id', 'progress_class_id'];
    classSelects.forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.value = '';
        }
    });

    // Reset exam type selects
    const examTypeSelects = ['direct_exam_type', 'exam_type', 'view_exam_type'];
    examTypeSelects.forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.value = '';
        }
    });

    // Reset max score selects
    const maxScoreSelects = ['direct_max_score', 'max_score'];
    maxScoreSelects.forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.value = '';
        }
    });

    // Reset teacher max score inputs
    const teacherMaxScoreInputs = ['direct_teacher_max_score', 'teacher_max_score'];
    teacherMaxScoreInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.value = '';
        }
    });

    // Reset topic inputs and counters
    const topicInputs = ['direct_topic', 'topic'];
    topicInputs.forEach(id => {
        const el = document.getElementById(id);
        const countEl = document.getElementById(id + '_count');
        if (el) el.value = '';
        if (countEl) countEl.textContent = `0/${el ? el.maxLength : 255}`;
    });


// Character counter for topic inputs
function updateTopicCount(id) {
    const el = document.getElementById(id);
    const counter = document.getElementById(id + '_count');
    if (!el || !counter) return;
    const len = el.value.length;
    counter.textContent = `${len}/${el.maxLength}`;
    if (len >= el.maxLength) {
        counter.style.color = '#d93025';
    } else {
        counter.style.color = '#6c757d';
    }
}

['direct_topic', 'topic'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', () => updateTopicCount(id));
    // initialize
    updateTopicCount(id);
});
    // Topic inputs already reset above; no duplicate reset here.

    // Clear student marks section
    const studentMarksSection = document.getElementById('studentMarksSection');
    if (studentMarksSection) {
        studentMarksSection.style.display = 'none';
        studentMarksSection.innerHTML = '';
    }

    // Clear exam results section
    const examResultsSection = document.getElementById('examResultsSection');
    if (examResultsSection) {
        examResultsSection.style.display = 'none';
        examResultsSection.innerHTML = '';
    }
    
    // Reset progress section
    document.getElementById('progress-summary').style.display = 'none';
    document.getElementById('no-progress-data').style.display = 'none';
    document.getElementById('exam-type-progress').innerHTML = '';
    document.getElementById('total-students-count').textContent = '0';
    document.getElementById('completed-assessments').textContent = '0';
    document.getElementById('pending-assessments').textContent = '0';
    document.getElementById('completion-rate').textContent = '0%';

    // Hide search bar in view section
    const searchBar = document.querySelector('.search-bar');
    if (searchBar) {
        searchBar.style.display = 'none';
        const searchInput = searchBar.querySelector('input');
        if (searchInput) searchInput.value = '';
    }

    // Reset file upload elements
    const fileName = document.getElementById('file-name');
    if (fileName) fileName.textContent = '';
    const fileInput = document.getElementById('marks_file');
    if (fileInput) fileInput.value = '';

    // Disable all buttons
    document.getElementById('loadStudentsBtn').disabled = true;
    document.getElementById('submitMarksBtn').disabled = true;
    document.getElementById('generateTemplateBtn').disabled = true;
    document.getElementById('uploadMarksBtn').disabled = true;
    document.getElementById('viewResultsBtn').disabled = true;
    document.getElementById('loadProgressBtn').disabled = true;

    // Remove success highlighting on action buttons when resetting
    try {
        document.getElementById('loadStudentsBtn').classList.remove('btn-success');
        document.getElementById('submitMarksBtn').classList.remove('btn-success');
    } catch (e) {}

    // Reset to first tab in upload section
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    tabButtons.forEach(button => button.classList.remove('active'));
    tabContents.forEach(content => content.classList.remove('active'));
    if (tabButtons[0]) tabButtons[0].classList.add('active');
    if (tabContents[0]) tabContents[0].classList.add('active');
}

function switchTab(tabId) {
    // Update tab buttons
    document.querySelectorAll('.tab-button').forEach(button => button.classList.remove('active'));
    event.currentTarget.classList.add('active');

    // Update tab contents
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
}

// Add event listeners for direct entry form
document.getElementById('direct_class_id').addEventListener('change', function() {
    // Reset subject dropdown when class changes
    const subjectSelect = document.getElementById('direct_subject_id');
    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
    subjectSelect.disabled = true;
    // If exam type and category are already selected, try loading subjects immediately
    const examType = document.getElementById('direct_exam_type').value;
    const category = document.getElementById('direct_category').value;
    const classId = this.value;
    if (classId && examType && category) {
        updateSubjects(classId, examType, category, subjectSelect);
    }

    checkDirectEntryFields();
});

// If user clicks/focuses the subject select, try loading subjects when prerequisites exist
document.getElementById('direct_subject_id').addEventListener('focus', function() {
    const subjectSelect = this;
    // Only attempt to load if currently disabled or empty
    if (!subjectSelect.disabled && subjectSelect.options.length > 1) return;

    const classId = document.getElementById('direct_class_id').value;
    const examType = document.getElementById('direct_exam_type').value;
    const category = document.getElementById('direct_category').value;
    if (classId && examType && category) {
        updateSubjects(classId, examType, category, subjectSelect);
    }
});

document.getElementById('direct_exam_type').addEventListener('change', function() {
    updateCategories(this.value, document.getElementById('direct_category'));
    // Reset subject dropdown when exam type changes
    const subjectSelect = document.getElementById('direct_subject_id');
    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
    subjectSelect.disabled = true;
    checkDirectEntryFields();
});

// Add event listener for direct category selection - now loads subjects
document.getElementById('direct_category').addEventListener('change', function() {
    const classId = document.getElementById('direct_class_id').value;
    const examType = document.getElementById('direct_exam_type').value;
    const category = this.value;
    const subjectSelect = document.getElementById('direct_subject_id');
    
    if (classId && examType && category) {
        updateSubjects(classId, examType, category, subjectSelect);
    } else {
        // Reset subject dropdown if not all fields are selected
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectSelect.disabled = true;
    }
    
    checkDirectEntryFields();
});

// Update the checkFormFields function for direct entry
function checkDirectEntryFields() {
    const allFieldsFilled = 
        document.getElementById('direct_class_id').value && 
        document.getElementById('direct_subject_id').value && 
        document.getElementById('direct_exam_type').value && 
        document.getElementById('direct_category').value && 
        document.getElementById('direct_max_score').value &&
        document.getElementById('direct_teacher_max_score').value > 0;

    document.getElementById('loadStudentsBtn').disabled = !allFieldsFilled;
}

// Add event listeners for all direct entry form fields
['direct_class_id', 'direct_subject_id', 'direct_exam_type', 
 'direct_category', 'direct_max_score', 'direct_teacher_max_score'].forEach(elementId => {
    document.getElementById(elementId).addEventListener('change', checkDirectEntryFields);
});

// Add validation for teacher max score
document.getElementById('direct_teacher_max_score').addEventListener('change', function() {
    const teacherMaxScore = parseFloat(this.value);
    if (teacherMaxScore <= 0) {
        alert('Teacher marking range must be greater than 0');
        this.value = '';
        checkDirectEntryFields();
    }
});

// Add event listeners for view section (Class → Exam Type → Category → Subject)
document.getElementById('view_class_id').addEventListener('change', function() {
    // Reset category and subject — user should pick exam type then category
    const viewCategory = document.getElementById('view_category');
    const viewSubject = document.getElementById('view_subject_id');
    viewCategory.innerHTML = '<option value="">Select Category</option>';
    viewCategory.disabled = true;
    viewSubject.innerHTML = '<option value="">Select Subject</option>';
    viewSubject.disabled = true;
    checkViewResultsForm();
});

document.getElementById('view_exam_type').addEventListener('change', function() {
    updateCategories(this.value, document.getElementById('view_category'));
    checkViewResultsForm();
});

document.getElementById('view_category').addEventListener('change', function() {
    const classId = document.getElementById('view_class_id').value;
    const examType = document.getElementById('view_exam_type').value;
    const category = this.value;
    const subjectSelect = document.getElementById('view_subject_id');
    if (classId && examType && category) {
        updateSubjects(classId, examType, category, subjectSelect);
    } else {
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectSelect.disabled = true;
    }
    checkViewResultsForm();
});

document.getElementById('view_subject_id').addEventListener('change', checkViewResultsForm);

// Function to check if view results form is complete
function checkViewResultsForm() {
    const allFieldsFilled = 
        document.getElementById('view_class_id').value && 
        document.getElementById('view_subject_id').value && 
        document.getElementById('view_exam_type').value && 
        document.getElementById('view_category').value;

    var vrb = document.getElementById('viewResultsBtn');
    vrb.disabled = !allFieldsFilled;
    try {
        if (allFieldsFilled) vrb.classList.add('btn-success');
        else vrb.classList.remove('btn-success');
    } catch (e) {}
}

function updateNormalizedScore(input, teacherMaxScore, adminMaxScore) {
    const rawScore = parseFloat(input.value);
    const row = input.closest('tr');
    const normalizedInput = row.querySelector('.normalized-score');
    const examType = document.getElementById('direct_exam_type').value.toLowerCase();
    
    // Clear any previous validation classes
    input.classList.remove('valid', 'invalid');
    
    if (!isNaN(rawScore) && teacherMaxScore > 0) {
        // Validate score against teacher's marking range
        if (rawScore < 0) {
            // Score is negative - show red
            input.classList.add('invalid');
            normalizedInput.value = '';
            return;
        } else if (rawScore > teacherMaxScore) {
            // Score exceeds teacher's marking range - show red
            input.classList.add('invalid');
            normalizedInput.value = '';
            return;
        } else {
            // Score is valid - show green
            input.classList.add('valid');
        }
        
        let normalizedScore = (rawScore / teacherMaxScore) * adminMaxScore;
        normalizedScore = Math.min(normalizedScore, adminMaxScore); // Ensure it doesn't exceed max score
        
        // Apply different decimal places based on exam type
        if (examType === 'exam') {
            normalizedScore = Math.round(normalizedScore); // No decimal places for exam
        } else if (examType === 'activity') {
            normalizedScore = parseFloat(normalizedScore.toFixed(1)); // 1 decimal place for activity
        } else {
            normalizedScore = parseFloat(normalizedScore.toFixed(1)); // Default to 1 decimal place
        }
        
        // Add the normalized score to formData
        normalizedInput.value = normalizedScore;
    } else if (input.value.trim() !== '') {
        // Invalid input (not a number) - show red
        input.classList.add('invalid');
        normalizedInput.value = '';
    } else {
        // Empty input - clear styling
        normalizedInput.value = '';
    }
}

// Progress Section JavaScript
// Progress Section: Class → Exam Type → Category → Subject
document.getElementById('progress_class_id').addEventListener('change', function() {
    const progressCategory = document.getElementById('progress_category');
    const progressSubject = document.getElementById('progress_subject_id');
    progressCategory.innerHTML = '<option value="">Select Category</option>';
    progressCategory.disabled = true;
    progressSubject.innerHTML = '<option value="">Select Subject</option>';
    progressSubject.disabled = true;
    checkProgressForm();
});

document.getElementById('progress_exam_type').addEventListener('change', function() {
    updateCategories(this.value, document.getElementById('progress_category'));
    checkProgressForm();
});

document.getElementById('progress_category').addEventListener('change', function() {
    const classId = document.getElementById('progress_class_id').value;
    const examType = document.getElementById('progress_exam_type').value;
    const category = this.value;
    const subjectSelect = document.getElementById('progress_subject_id');
    if (classId && examType && category) {
        updateSubjects(classId, examType, category, subjectSelect);
    } else {
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectSelect.disabled = true;
    }
    checkProgressForm();
});

function checkProgressForm() {
    const classId = document.getElementById('progress_class_id').value;
    const examType = document.getElementById('progress_exam_type') ? document.getElementById('progress_exam_type').value : '';
    const category = document.getElementById('progress_category') ? document.getElementById('progress_category').value : '';
    const subjectId = document.getElementById('progress_subject_id').value;
    // Require class, exam type, category and subject (new order)
    document.getElementById('loadProgressBtn').disabled = !(classId && examType && category && subjectId);
}

document.getElementById('progress_subject_id').addEventListener('change', checkProgressForm);

document.getElementById('loadProgressBtn').addEventListener('click', function() {
    const classId = document.getElementById('progress_class_id').value;
    const examType = document.getElementById('progress_exam_type').value;
    const category = document.getElementById('progress_category').value;
    const subjectId = document.getElementById('progress_subject_id').value;
    
    if (classId && examType && category && subjectId) {
        console.log('Loading progress for', { classId, examType, category, subjectId });
        // Show loading state
        document.getElementById('loadProgressBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        document.getElementById('loadProgressBtn').disabled = true;
        
        fetch(`?action=get_progress_data&class_id=${encodeURIComponent(classId)}&subject_id=${encodeURIComponent(subjectId)}&exam_type=${encodeURIComponent(examType)}&category=${encodeURIComponent(category)}`)
            .then(response => response.json())
            .then(data => {
                console.log('Progress response:', data);
                // Reset button
                document.getElementById('loadProgressBtn').innerHTML = '<i class="fas fa-sync-alt"></i> Load Progress';
                document.getElementById('loadProgressBtn').disabled = false;
                
                // If we have data and progress to show
                if (data.total_students > 0 && data.progress_data.length > 0) {
                    // Update summary cards
                    document.getElementById('total-students-count').textContent = data.total_students;
                    document.getElementById('completed-assessments').textContent = data.completed_assessments;
                    document.getElementById('pending-assessments').textContent = data.pending_assessments;
                    document.getElementById('completion-rate').textContent = data.completion_rate + '%';
                    
                    // Update circular progress
                    const progressCircle = document.getElementById('progressCircle');
                    const circumference = 2 * Math.PI * 50;
                    progressCircle.style.strokeDasharray = `${circumference} ${circumference}`;
                    const offset = circumference - (data.completion_rate / 100) * circumference;
                    // Set value immediately without animation
                    progressCircle.style.strokeDashoffset = offset;
                    document.getElementById('completion-percentage').textContent = data.completion_rate + '%';
                    
                    // Create detailed progress bars
                    function showFilteredProgress(filterExamType, progressData) {
                        const examTypeProgressContainer = document.getElementById('exam-type-progress');
                        examTypeProgressContainer.innerHTML = '';
                        
                        // Show all progress data since filtering by exam type is removed
                        progressData.forEach(category => {
                            const progressItem = document.createElement('div');
                            progressItem.className = 'exam-progress-item collapsed'; // Start collapsed on mobile
                            
                            const progressHeader = document.createElement('div');
                            progressHeader.className = 'exam-progress-header';
                            
                            const progressTitle = document.createElement('div');
                            progressTitle.className = 'exam-progress-title';
                            progressTitle.innerHTML = `<i class="fas fa-tag"></i> ${category.exam_type} - ${category.category}`;
                            
                            const progressPercentage = document.createElement('div');
                            progressPercentage.className = 'exam-progress-percentage';
                            progressPercentage.textContent = `${category.percentage}%`;
                            
                            const collapseToggle = document.createElement('button');
                            collapseToggle.className = 'collapse-toggle';
                            collapseToggle.innerHTML = '<i class="fas fa-chevron-down"></i>';
                            collapseToggle.addEventListener('click', function() {
                                progressItem.classList.toggle('collapsed');
                                this.querySelector('i').classList.toggle('fa-chevron-down');
                                this.querySelector('i').classList.toggle('fa-chevron-up');
                            });
                            
                            progressHeader.appendChild(progressTitle);
                            progressHeader.appendChild(progressPercentage);
                            
                            const progressBarContainer = document.createElement('div');
                            progressBarContainer.className = 'progress-bar-container';
                            
                            const progressBar = document.createElement('div');
                            progressBar.className = 'progress-bar';
                            // Set width immediately without animation
                            progressBar.style.width = `${category.percentage}%`;
                            
                            const progressDetails = document.createElement('div');
                            progressDetails.className = 'progress-details';
                            progressDetails.innerHTML = `
                                <span>${category.completed} out of ${category.total} students</span>
                                <span>${category.percentage}% Complete</span>
                            `;
                            
                            progressBarContainer.appendChild(progressBar);
                            progressItem.appendChild(collapseToggle);
                            progressItem.appendChild(progressHeader);
                            progressItem.appendChild(progressBarContainer);
                            progressItem.appendChild(progressDetails);
                            
                            examTypeProgressContainer.appendChild(progressItem);
                        });
                    }
                    
                    // Show all progress data initially
                    showFilteredProgress(null, data.progress_data);
                    
                    // Add expand/collapse all functionality
                    document.getElementById('expand-all-btn').addEventListener('click', function() {
                        document.querySelectorAll('.exam-progress-item').forEach(item => {
                            item.classList.remove('collapsed');
                            const icon = item.querySelector('.collapse-toggle i');
                            if (icon) {
                                icon.className = 'fas fa-chevron-up';
                            }
                        });
                    });
                    
                    document.getElementById('collapse-all-btn').addEventListener('click', function() {
                        document.querySelectorAll('.exam-progress-item').forEach(item => {
                            item.classList.add('collapsed');
                            const icon = item.querySelector('.collapse-toggle i');
                            if (icon) {
                                icon.className = 'fas fa-chevron-down';
                            }
                        });
                    });
                    
                    // Auto-expand on desktop, keep collapsed on mobile
                    if (window.innerWidth > 576) {
                        document.getElementById('expand-all-btn').click();
                    }
                    
                    // Show the progress summary
                    document.getElementById('progress-summary').style.display = 'block';
                    document.getElementById('no-progress-data').style.display = 'none';
                } else {
                    // Show no data message with specifics
                    document.getElementById('progress-summary').style.display = 'none';
                    const noDataEl = document.getElementById('no-progress-data');
                    const msgP = noDataEl.querySelector('p');
                    const specificMsg = `No assessment data found for ${examType} - ${category} in the selected class and subject.`;
                    msgP.textContent = specificMsg;
                    noDataEl.style.display = 'flex';
                    console.warn(specificMsg);
                    // Also notify the user via toast and bring the message into view
                    try {
                        showNotification(specificMsg, false);
                    } catch (e) {
                        console.warn('showNotification not available', e);
                    }
                    try {
                        noDataEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } catch (e) {
                        // ignore
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('loadProgressBtn').innerHTML = '<i class="fas fa-sync-alt"></i> Load Progress';
                document.getElementById('loadProgressBtn').disabled = false;
                showNotification('Error loading progress data', false);
            });
    }
});

// Handle "Try Another Class/Subject" button
document.getElementById('try-another-class-btn').addEventListener('click', function() {
    document.getElementById('progress_class_id').focus();
    document.getElementById('no-progress-data').style.display = 'none';
});
    </script>
    <script>
    (function() {
        // Unread message badge polling for Chat button only
        function updateUnreadBadges(){
            fetch('ajax/get_unread_count.php', { cache: 'no-store' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    var n = 0;
                    if (d) {
                        if (typeof d.unread !== 'undefined') { n = parseInt(d.unread, 10) || 0; }
                        else if (typeof d.unread_count !== 'undefined') { n = parseInt(d.unread_count, 10) || 0; }
                    }
                    var badge = document.getElementById('chatUnreadBadge');
                    if (!badge) return;
                    if (n > 0) {
                        badge.style.display = 'inline-block';
                        badge.textContent = n > 99 ? '99+' : String(n);
                    } else {
                        badge.style.display = 'none';
                    }
                })
                .catch(function(){ /* silent */ });
        }
        updateUnreadBadges();
        setInterval(updateUnreadBadges, 7000);
    })();
    </script>
</body>
</html>