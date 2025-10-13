 <?php
function getGrade($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) {
            return $grade;
        }
    }
    return 'N/A';
}

function getRemarks($percentage, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($percentage >= $scale['min_score'] && $percentage <= $scale['max_score']) {
            return $scale['remarks'];
        }
    }
    return 'N/A';
}
function getOverallComment($overall_grade, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($grade == $overall_grade) {
            return $scale['remarks'];
        }
    }
    return 'N/A';
}
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Properly decode and sanitize input parameters
$school_id = $_SESSION['school_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$exam_type = isset($_GET['exam_type']) ? $conn->real_escape_string($_GET['exam_type']) : '';
$student_name = isset($_GET['student']) ? trim(urldecode($_GET['student'])) : '';

// Log received parameters for debugging
error_log("Received parameters - Class ID: $class_id, Exam Type: $exam_type, Student Name: $student_name");

if (!$class_id || !$exam_type || !$student_name) {
    error_log("Invalid parameters received");
    die('Invalid parameters');
}


// First, get the current term
$term_query = "SELECT id, name, year, start_date, end_date 
               FROM terms 
               WHERE school_id = ? AND is_current = 1 
               LIMIT 1";
$stmt = $conn->prepare($term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$current_term = $term_result->fetch_assoc();

if (!$current_term) {
    die('No current term found');
}

// Get school details
$school_query = "SELECT s.school_name, s.motto, s.email, s.phone, s.badge, t.name, t.year 
                 FROM schools s
                 LEFT JOIN terms t ON s.id = t.school_id AND t.is_current = 1
                 WHERE s.id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school_details = $school_result->fetch_assoc();

function findStudent($conn, $student_name, $class_id, $school_id) {
    // Normalize the name
    $student_name = trim($student_name);

    // Generate multiple name variations
    $name_variations = [
        $student_name, // Original
        str_replace(',', ', ', $student_name), // Ensure consistent comma spacing
        implode(' ', array_reverse(explode(', ', $student_name))), // Reversed name
        str_replace(',', ' ', $student_name), // No comma
        str_replace(', ', ' ', $student_name) // Simple space
    ];

    // Logging for debugging
    error_log("Student Search Details:");
    error_log("Original Name: " . $student_name);
    error_log("Class ID: " . $class_id);
    error_log("School ID: " . $school_id);
    error_log("Name Variations: " . print_r($name_variations, true));

    // Flexible student query with multiple name matching
    $student_query = "SELECT students.*, classes.name as class_name 
                      FROM students 
                      JOIN classes ON students.class_id = classes.id 
                      WHERE (
                          REPLACE(CONCAT(TRIM(lastname), ', ', TRIM(firstname)), ' ', '') = REPLACE(?, ' ', '') OR
                          REPLACE(CONCAT(TRIM(firstname), ' ', TRIM(lastname)), ' ', '') = REPLACE(?, ' ', '') OR
                          REPLACE(CONCAT(TRIM(lastname), ' ', TRIM(firstname)), ' ', '') = REPLACE(?, ' ', '')
                      )
                      AND students.class_id = ? 
                      AND students.school_id = ?";

    // Prepare and execute statement
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("sssii", 
        $name_variations[0],
        $name_variations[2],
        $name_variations[1],
        $class_id, 
        $school_id
    );

    if (!$stmt->execute()) {
        error_log("Student Query Failed: " . $stmt->error);
        return null;
    }

    $result = $stmt->get_result();
    $student = $result->fetch_assoc();

    // Log found student or not found
    if ($student) {
        error_log("Student Found: " . $student['firstname'] . ' ' . $student['lastname']);
    } else {
        error_log("No Student Found for Name: " . $student_name);
        
        // Additional debugging: List all students in the class
        $all_students_query = "SELECT firstname, lastname FROM students 
                                WHERE class_id = ? AND school_id = ?";
        $all_stmt = $conn->prepare($all_students_query);
        $all_stmt->bind_param("ii", $class_id, $school_id);
        $all_stmt->execute();
        $all_result = $all_stmt->get_result();
        
        error_log("Students in Class:");
        while ($student_row = $all_result->fetch_assoc()) {
            error_log("- " . $student_row['firstname'] . ' ' . $student_row['lastname']);
        }
    }

    return $student;
}

// Replace your existing student lookup with this function
$student_details = findStudent($conn, $student_name, $class_id, $school_id);

if (!$student_details) {
    die('Student not found. Please check the name and class details.');
}

$exam_types_query = "SELECT DISTINCT exam_type 
                    FROM exams 
                    WHERE school_id = ? AND term_id = ?";
$stmt = $conn->prepare($exam_types_query);
$stmt->bind_param("ii", $school_id, $current_term['id']);
$stmt->execute();
$exam_types_result = $stmt->get_result();
$exam_types = [];
while ($row = $exam_types_result->fetch_assoc()) {
    $exam_types[] = $row['exam_type'];
}


// Get student's exam results
$results_query = "
    SELECT 
        s.subject_name,
        e.exam_type,
        er.score,
        e.max_score,
        u.firstname AS teacher_firstname,
        u.lastname AS teacher_lastname
    FROM exam_results er
    JOIN exams e ON er.exam_id = e.exam_id
    JOIN subjects s ON er.subject_id = s.subject_id
    LEFT JOIN teacher_subjects ts ON s.subject_id = ts.subject_id
    LEFT JOIN users u ON ts.user_id = u.user_id AND u.role = 'teacher'
    WHERE er.student_id = ? 
    AND e.school_id = ?
    AND e.term_id = ?
    ORDER BY s.subject_name, e.exam_type";
    
$stmt = $conn->prepare($results_query);
$stmt->bind_param("iii", $student_details['id'], $school_id, $current_term['id']);
$stmt->execute();
$results = $stmt->get_result();

// Process results
$exam_results = [];
$subject_totals = [];
$exam_type_max_scores = [];

while ($row = $results->fetch_assoc()) {
    $subject = $row['subject_name'];
    $exam_type = $row['exam_type'];
    
    if (!isset($exam_results[$subject])) {
        $exam_results[$subject] = [
            'teacher' => $row['teacher_firstname'] . ' ' . $row['teacher_lastname'],
            'scores' => []
        ];
    }
    
    $exam_results[$subject]['scores'][$exam_type] = [
        'score' => $row['score'],
        'max_score' => $row['max_score']
    ];
    
    if (!isset($subject_totals[$subject])) {
        $subject_totals[$subject] = ['score' => 0, 'max_score' => 0];
    }
    $subject_totals[$subject]['score'] += $row['score'] ?? 0;
    $subject_totals[$subject]['max_score'] += $row['max_score'];
    
    if (!isset($exam_type_max_scores[$exam_type])) {
        $exam_type_max_scores[$exam_type] = $row['max_score'];
    }
}

// Fetch grading scale based on the student's score
$grade_query = "SELECT * FROM grading_scales 
                WHERE min_score <= ? AND max_score >= ? 
                AND school_id = ? LIMIT 1";

// Prepare the statement
if ($grade_stmt = $conn->prepare($grade_query)) {
    // Bind parameters (types: 'i' for integer)
    $grade_stmt->bind_param('iii', $student_score, $student_score, $school_id);

    // Execute the query
    $grade_stmt->execute();

    // Get the result
    $result = $grade_stmt->get_result();

    // Fetch the grade as an associative array
    $grade = $result->fetch_assoc();

    // Check if a grade was found
    if ($grade) {
        echo "Grade fetched successfully: ";
        print_r($grade);
    } else {
        
    }

    // Close the statement
    $grade_stmt->close();
} else {
    // Handle preparation error
    echo "Query preparation failed: " . $conn->error;
}

// If grade is found, fetch related comments
if ($grade) {
    // Query to fetch comments
    $comment_query = "SELECT * FROM comment_templates 
                      WHERE min_score <= ? AND max_score >= ? 
                      AND school_id = ? LIMIT 2"; // Assuming Class and Head Teacher types

    // Prepare the statement
    if ($comment_stmt = $conn->prepare($comment_query)) {
        // Bind parameters (types: 'i' for integer)
        $comment_stmt->bind_param('iii', $student_score, $student_score, $school_id);

        // Execute the query
        $comment_stmt->execute();

        // Get the result
        $result = $comment_stmt->get_result();

        // Fetch all comments as an associative array
        $comments = $result->fetch_all(MYSQLI_ASSOC);

        // Check if comments are found
        if ($comments) {
            echo "Comments fetched successfully: ";
            print_r($comments);
        } else {
            echo "No matching comments found.";
        }

        // Close the statement
        $comment_stmt->close();
    } else {
        // Handle preparation error
        echo "Comment query preparation failed: " . $conn->error;
    }
}


// Default comments in case no match found
$class_teacher_comment = $comments[0]['comment'] ?? 'No comment available.';
$head_teacher_comment = $comments[1]['comment'] ?? 'No comment available.';


$total_subject_percentages = 0;
$subject_count = 0;

foreach ($exam_results as $subject => $data) {
    $subject_total_percentage = 0;
    $exam_type_count = 0;

    foreach ($exam_types as $exam_type) {
        // Check if score exists for this exam type
        if (isset($data['scores'][$exam_type]['score']) && 
            isset($data['scores'][$exam_type]['max_score'])) {
            $score = $data['scores'][$exam_type]['score'];
            $max_score = $data['scores'][$exam_type]['max_score'];

            // Calculate percentage for this exam type
            $exam_type_percentage = ($max_score > 0) ? 
                (($score / $max_score) * 100) : 0;

            $subject_total_percentage += $exam_type_percentage;
            $exam_type_count++;
        }
    }

    // Calculate average percentage for the subject
    $subject_average_percentage = ($exam_type_count > 0) ? 
        ($subject_total_percentage / $exam_type_count) : 0;

    $total_subject_percentages += $subject_average_percentage;
    $subject_count++;
}

// Calculate overall average percentage
$average_percentage = ($subject_count > 0) ? 
    round($total_subject_percentages / $subject_count) : 0;


// Initialize the grading scale
$grading_scale = [];

// Fetch grading scales from the database
$grading_scale_query = "SELECT grade, min_score, max_score, remarks FROM grading_scales WHERE school_id = ?";
if ($stmt = $conn->prepare($grading_scale_query)) {
    $stmt->bind_param('i', $school_id); // Bind the school_id
    $stmt->execute();
    $result = $stmt->get_result();

    // Populate the grading_scale array
    while ($row = $result->fetch_assoc()) {
        $grading_scale[$row['grade']] = [
            'min_score' => $row['min_score'],
            'max_score' => $row['max_score'],
            'remarks' => $row['remarks']
        ];
    }
// Get class teacher and head teacher comments from database
function getCommentsByPercentage($conn, $percentage, $school_id) {
    // Prepare query to find comments matching the percentage
    $comment_query = "SELECT type, comment 
                      FROM comment_templates 
                      WHERE ? BETWEEN min_score AND max_score 
                      AND school_id = ?
                      ORDER BY CASE 
                          WHEN type = 'class_teacher' THEN 1 
                          WHEN type = 'head_teacher' THEN 2 
                          ELSE 3 
                      END";

    $stmt = $conn->prepare($comment_query);
    $stmt->bind_param("di", $percentage, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Initialize comments
    $comments = [
        'class_teacher' => 'No specific comment available.',
        'head_teacher' => 'No specific comment available.'
    ];

    // Populate comments
    while ($row = $result->fetch_assoc()) {
        if ($row['type'] == 'class_teacher') {
            $comments['class_teacher'] = $row['comment'];
        } elseif ($row['type'] == 'head_teacher') {
            $comments['head_teacher'] = $row['comment'];
        }
    }

    return $comments;
}

    $stmt->close();
}

// Check if grading scale is populated
if (empty($grading_scale)) {
    echo "No grading scale found for the specified school.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Report Card</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #34495e;
            color: white;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        .school-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 10px;
            display: block;
        }
        .school-name {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .school-motto {
            font-style: italic;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .contact-info {
            font-size: 12px;
            margin-bottom: 5px;
        }
        .report-title {
            font-size: 16px;
            font-weight: bold;
            margin-top: 10px;
        }
        .student-info {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 15px;
            margin: 20px 0;
            padding: 5px;
            border: 1px solid #ddd;
            background-color: #f8f9fa;
        }
        .student-details {
            line-height: 1.8;
        }
        .student-image {
            width: 120px;
            height: 150px;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #fff;
        }
        .marks-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .marks-table th {
            background-color: #34495e;
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 14px;
        }
        .marks-table td {
            padding: 8px;
            text-align: center;
            border: 1px solid #ddd;
            font-size: 14px;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
            padding: 15px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
        }
        .summary-item {
            display: in-line;
            justify-content: space-between;
            padding: 5px 0;
        }
        .comments-section {
            margin: 20px 0;
            padding: 12px;
            border: 1px solid #ddd;
        }
        .comment-box {
            margin: 10px 0;
        }
        .comment-box h4 {
            margin: 5px 0;
            color: #34495e;
        }
        .signature-line {
            margin: 15px 0;
            border-top: 1px solid #ddd;
            padding-top: 5px;
        }
        .grading-system {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ddd;
            background-color: #f8f9fa;
        }
        .grade-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .grade-table th, .grade-table td {
            padding: 10px;
            text-align: center;
            border: 1px solid #ddd;
            font-size: 12px;
        }
        .grade-table th {
            background-color: #34495e;
            color: white;
        }
        .download-button {
            display: block;
            width: 200px;
            margin: 20px auto;
            padding: 10px 20px;
            background-color: #34495e;
            color: white;
            text-decoration: none;
            text-align: center;
            border-radius: 5px;
        }
        .download-button:hover {
            background-color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="school-name"><?php echo htmlspecialchars($school_details['school_name']); ?></div>
            <div class="school-motto">Motto: <?php echo htmlspecialchars($school_details['motto']); ?></div>
            <div class="contact-info">Email: <?php echo htmlspecialchars($school_details['email']); ?></div>
            <div class="contact-info">Phone: <?php echo htmlspecialchars($school_details['phone']); ?></div>
            <div class="report-title">End of Term Report : <?php echo htmlspecialchars($school_details['name']); ?> - <?php echo htmlspecialchars($school_details['year']); ?> </div>
        </div>

        <div class="student-info">
            <div class="student-details">
                <p><strong>Name:</strong> <?php echo htmlspecialchars($student_details['firstname'] . ' ' . $student_details['lastname']); ?></p>
                <p><strong>Class:</strong> <?php echo htmlspecialchars($student_details['class_name']); ?></p>
                <p><strong>Stream:</strong> <?php echo htmlspecialchars($student_details['stream']); ?></p>
            </div>
            <div class="student-image">
                <?php if (!empty($student_details['image']) && file_exists('uploads/' . basename($student_details['image']))): ?>
                    <img src="<?php echo 'uploads/' . basename($student_details['image']); ?>" alt="Student Photo" style="max-width: 100%; max-height: 100%;">
                <?php else: ?>
                    No Image
                <?php endif; ?>
            </div>
        </div>

        <table class="marks-table">
            <tr>
                <th>Subject</th>
                <?php foreach ($exam_types as $exam_type): ?>
                    <th>Activity</th>
                <?php endforeach; ?>
                <th>Final</th>
                <th>Out of 100</th>
                <th>Grade</th>
                <th>Remarks</th>
            </tr>
            <?php 
            $total_final_score = 0;
            $subject_count = 0;
            foreach ($exam_results as $subject => $data): 
                $score_sum = 0;
                $score_count = 0;
                $max_possible_sum = 0;
            ?>
            <tr>
                <td><?php echo htmlspecialchars($subject); ?></td>
                <?php foreach ($exam_types as $exam_type): 
                    $score = $data['scores'][$exam_type]['score'] ?? '-';
                    $max_possible = $data['scores'][$exam_type]['max_score'] ?? 0;
                    if (is_numeric($score)) {
                        // Convert score to percentage based on its specific max_score
                        $score_percentage = ($max_possible > 0) ? ($score / $max_possible) * 100 : 0;
                        
                        $score_sum += $score_percentage;
                        $score_count++;
                    }
                ?>
                    <td><?php echo $score; ?></td>

                <?php endforeach; 
                    $subject_average = ($score_count > 0) ? round(($score_sum / $score_count) / 33.33, 1) : 0;
                    $subject_percentage = ($score_count > 0) ? round($score_sum / $score_count) : 0;
                    $total_final_score += $subject_average;
                    $subject_count++;
                    $grade = getGrade($subject_percentage, $grading_scale);
                    $remarks = getRemarks($subject_percentage, $grading_scale);
                    
$comments = getCommentsByPercentage($conn, $average_percentage, $school_id);
                ?>

                <td><?php echo number_format($subject_average, 2); ?></td>
                <td><?php echo $subject_percentage; ?></td>
                <td><?php echo $grade; ?></td>
                <td><?php echo $remarks; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="summary">
            <div class="summary-item">
                <strong>Average Percentage:</strong>
                <span><?php echo (int)round($average_percentage); ?>%</span>
            </div>
            <div class="summary-item">
                <strong>Overall Grade:</strong>
                <span><?php echo getGrade($average_percentage, $grading_scale); ?></span>
            </div>
            <div class="summary-item">
                <strong>Overall Comment:</strong>
                <span><?php echo getOverallComment(getGrade($average_percentage, $grading_scale), $grading_scale); ?></span>
            </div>
        </div>

      <div class="comments-section">
    <div class="comment-box">
        <h4>Class Teacher's Comment: <?php echo htmlspecialchars($comments['class_teacher']); ?></h4>
        <strong>Class Teacher Signature:</strong> _____________________
    </div>
    <div class="comment-box">
        <h4>Head Teacher's Comment: <?php echo htmlspecialchars($comments['head_teacher']); ?></h4>
        <strong>Head Teacher Signature:</strong> _____________________
    </div>
</div>

        <div class="grading-system">
                    <h4>Points Weight:</h4>
            <table class="grade-table">
                <tr>
                    <th>3.00 - 2.41</th>
                    <th>2.40 - 1.51</th>
                    <th>1.50 - 0.90</th>
                </tr>
                <tr>
                    <td>Outstanding</td>
                    <td>Satisfactory</td>
                    <td>Moderate</td>
                </tr>
            </table>
        </div>

        <a href="download_report_card.php?class_id=<?php echo $class_id; ?>&exam_type=<?php echo urlencode($exam_type); ?>&student=<?php echo urlencode($student_name); ?>" class="download-button">Download PDF</a>
    </div>
</body>
</html