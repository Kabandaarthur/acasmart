<?php
// TEMP EARLY LOG: capture cookies/headers to help debug missing session
@session_start();
$earlyLogDir = __DIR__ . '/logs';
if (!is_dir($earlyLogDir)) @mkdir($earlyLogDir, 0755, true);
@file_put_contents($earlyLogDir . '/update_score_early.log', date('c') . "\n" . substr(print_r([
    'cookies' => $_COOKIE,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
], true), 0, 2000) . "\n\n", FILE_APPEND);

// Ensure we don't emit HTML error pages (Xdebug) for AJAX endpoints; return JSON instead
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server exception: ' . $e->getMessage()]);
    exit();
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal server error: ' . ($err['message'] ?? 'unknown')]);
        // Also log the error
        $logDir = __DIR__ . '/logs';
        @file_put_contents($logDir . '/update_score_fatal.log', date('c') . "\n" . print_r($err, true) . "\n\n", FILE_APPEND);
        exit();
    }
});

// Check if the user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// First, ensure the score column is DECIMAL(10,1) for one decimal place (silently ignore errors)
$alter_table_query = "ALTER TABLE exam_results MODIFY COLUMN score DECIMAL(10,1)";
@ $conn->query($alter_table_query);

// Get the current term
$school_id = $_SESSION['school_id'];
$query_current_term = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt_current_term = $conn->prepare($query_current_term);
$stmt_current_term->bind_param("i", $school_id);
$stmt_current_term->execute();
$result_current_term = $stmt_current_term->get_result();
$current_term = $result_current_term->fetch_assoc();

if (!$current_term) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No active term found']);
    exit();
}

$current_term_id = $current_term['id'];

// Read input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) $data = $_POST;

header('Content-Type: application/json');

// TEMP LOG: write incoming request for debugging (remove in production)
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents($logDir . '/update_score_requests.log', date('c') . "\n" . substr(print_r([
    'rawInput' => $rawInput,
    'post' => $_POST,
    'files' => $_FILES,
    'session' => isset($_SESSION) ? $_SESSION : null
], true), 0, 4000) . "\n\n", FILE_APPEND);

// Handle single score update
if (isset($data['studentId'])) {
    $student_id = $data['studentId'];
    $new_score = isset($data['newScore']) && $data['newScore'] !== null ? number_format((float)$data['newScore'], 1, '.', '') : null;
    $class_id = $data['classId'];
    $subject_id = $data['subjectId'];
    $exam_type = $data['examType'];
    $category = $data['category'];

    // ... find exam
    $check_exam_query = "SELECT exam_id, max_score FROM exams WHERE exam_type = ? AND category = ? AND school_id = ? AND term_id = ?";
    $stmt_check_exam = $conn->prepare($check_exam_query);
    $stmt_check_exam->bind_param("ssii", $exam_type, $category, $school_id, $current_term_id);
    $stmt_check_exam->execute();
    $result_check_exam = $stmt_check_exam->get_result();

    if ($result_check_exam->num_rows > 0) {
        $exam_row = $result_check_exam->fetch_assoc();
        $exam_id = $exam_row['exam_id'];
        $max_score = (float)$exam_row['max_score'];

        // Validate score doesn't exceed maximum score
        if ($new_score !== null) {
            $score_value = (float)$new_score;
            if ($score_value < 0) {
                echo json_encode(['success' => false, 'message' => 'Score cannot be negative']);
                exit();
            }
            if ($score_value > $max_score) {
                echo json_encode(['success' => false, 'message' => "Score cannot exceed the maximum score of {$max_score}"]);
                exit();
            }
        }

        $topic = isset($data['topic']) ? trim($data['topic']) : null;

        // If no topic was provided, try to reuse an existing topic for this exam+subject
        if (($topic === null || $topic === '') && isset($subject_id) && isset($exam_id)) {
            $query_existing_topic = "SELECT topic FROM exam_results WHERE exam_id = ? AND subject_id = ? AND topic IS NOT NULL LIMIT 1";
            $stmt_existing_topic = $conn->prepare($query_existing_topic);
            if ($stmt_existing_topic) {
                $stmt_existing_topic->bind_param("ii", $exam_id, $subject_id);
                $stmt_existing_topic->execute();
                $res_topic = $stmt_existing_topic->get_result();
                if ($res_topic && $row_topic = $res_topic->fetch_assoc()) {
                    $topic = $row_topic['topic'];
                }
                $stmt_existing_topic->close();
            }
        }

        $check_exists_query = "SELECT result_id FROM exam_results WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND school_id = ? AND term_id = ?";
        $stmt_check = $conn->prepare($check_exists_query);
        $stmt_check->bind_param("iiiii", $exam_id, $student_id, $subject_id, $school_id, $current_term_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            // Update existing result. Only overwrite topic when one was provided.
            $status = $new_score !== null ? 'present' : 'absent';
            if ($topic !== null && $topic !== '') {
                $update_query = "UPDATE exam_results SET score = ?, status = ?, topic = ?, upload_date = NOW() WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND school_id = ? AND term_id = ?";
                $stmt_update = $conn->prepare($update_query);
                $stmt_update->bind_param("dssiiiii", $new_score, $status, $topic, $exam_id, $student_id, $subject_id, $school_id, $current_term_id);
            } else {
                $update_query = "UPDATE exam_results SET score = ?, status = ?, upload_date = NOW() WHERE exam_id = ? AND student_id = ? AND subject_id = ? AND school_id = ? AND term_id = ?";
                $stmt_update = $conn->prepare($update_query);
                $stmt_update->bind_param("dsiiiii", $new_score, $status, $exam_id, $student_id, $subject_id, $school_id, $current_term_id);
            }
        } else {
            // Insert new result with topic (topic may be null)
            $insert_query = "INSERT INTO exam_results (exam_id, school_id, student_id, subject_id, score, topic, upload_date, term_id, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)";
            $stmt_update = $conn->prepare($insert_query);
            $status = $new_score !== null ? 'present' : 'absent';
            $stmt_update->bind_param("iiiidsis", $exam_id, $school_id, $student_id, $subject_id, $new_score, $topic, $current_term_id, $status);
        }

        if ($stmt_update->execute()) {
            echo json_encode(['success' => true, 'message' => 'Score updated successfully']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update score: ' . $stmt_update->error]);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Exam not found']);
        exit();
    }
}

// Handle bulk score updates
if (isset($data['students']) && is_array($data['students'])) {
    $exam_type = $data['exam_type'];
    $category = $data['category'];
    $max_score = floatval($data['max_score']);

    $check_exam_query = "SELECT exam_id FROM exams WHERE exam_type = ? AND category = ? AND school_id = ? AND term_id = ?";
    $stmt_check_exam = $conn->prepare($check_exam_query);
    $stmt_check_exam->bind_param("ssii", $exam_type, $category, $school_id, $current_term_id);
    $stmt_check_exam->execute();
    $result_check_exam = $stmt_check_exam->get_result();

    if ($result_check_exam->num_rows > 0) {
        $exam_row = $result_check_exam->fetch_assoc();
        $exam_id = $exam_row['exam_id'];
    } else {
        $insert_exam_query = "INSERT INTO exams (name, exam_type, category, school_id, max_score, term_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt_insert_exam = $conn->prepare($insert_exam_query);
        $exam_name = $exam_type . " - " . $category . " - " . date("Y-m-d");
        $stmt_insert_exam->bind_param("sssidi", $exam_name, $exam_type, $category, $school_id, $max_score, $current_term_id);
        $stmt_insert_exam->execute();
        $exam_id = $stmt_insert_exam->insert_id;
    }

    $conn->begin_transaction();

    try {
        $subject_id = $data['subject_id'];
        $topic = isset($data['topic']) ? trim($data['topic']) : null;

        // If topic not provided, attempt to reuse any existing topic for this exam+subject before we delete rows
        if (($topic === null || $topic === '') && isset($exam_id) && isset($subject_id)) {
            $query_existing_topic = "SELECT topic FROM exam_results WHERE exam_id = ? AND subject_id = ? AND topic IS NOT NULL LIMIT 1";
            $stmt_existing_topic = $conn->prepare($query_existing_topic);
            if ($stmt_existing_topic) {
                $stmt_existing_topic->bind_param("ii", $exam_id, $subject_id);
                $stmt_existing_topic->execute();
                $res_topic = $stmt_existing_topic->get_result();
                if ($res_topic && $row_topic = $res_topic->fetch_assoc()) {
                    $topic = $row_topic['topic'];
                }
                $stmt_existing_topic->close();
            }
        }

        $delete_query = "DELETE FROM exam_results WHERE exam_id = ? AND subject_id = ? AND school_id = ? AND term_id = ?";
        $stmt_delete = $conn->prepare($delete_query);
        $stmt_delete->bind_param("iiii", $exam_id, $subject_id, $school_id, $current_term_id);
        $stmt_delete->execute();

        $insert_query = "INSERT INTO exam_results (exam_id, school_id, student_id, subject_id, score, topic, upload_date, term_id, status) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)";
        $stmt_insert = $conn->prepare($insert_query);

        foreach ($data['students'] as $student_id => $score) {
            $status = isset($data['status'][$student_id]) ? $data['status'][$student_id] : 'present';
            if ($status === 'present') {
                $score = number_format((float)$score, 1, '.', '');
                
                // Validate score doesn't exceed maximum score
                $score_value = (float)$score;
                if ($score_value < 0) {
                    throw new Exception("Score cannot be negative for student ID: {$student_id}");
                }
                if ($score_value > $max_score) {
                    throw new Exception("Score cannot exceed the maximum score of {$max_score} for student ID: {$student_id}");
                }
            } else {
                $score = null;
            }

            $stmt_insert->bind_param("iiiidsis", $exam_id, $school_id, $student_id, $subject_id, $score, $topic, $current_term_id, $status);
            $stmt_insert->execute();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Scores updated successfully']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error updating scores: ' . $e->getMessage()]);
    }
}

$conn->close();

?>