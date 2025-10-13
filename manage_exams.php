 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
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

// Create exam_subjects table if it doesn't exist
$create_exam_subjects_table = "
CREATE TABLE IF NOT EXISTS exam_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    subject_id INT NOT NULL,
    class_id INT NOT NULL,
    school_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    UNIQUE KEY unique_exam_subject_class (exam_id, subject_id, class_id)
)";

if (!$conn->query($create_exam_subjects_table)) {
    // Table creation failed, but continue - might already exist
}

define('EXAM_TYPE_ACTIVITY', 'activity');
define('EXAM_TYPE_FINAL', 'exam');
define('DEFAULT_ACTIVITY_MAX_SCORE', 3);  
define('DEFAULT_EXAM_MAX_SCORE', 80); 

// Get the school_id for the logged-in admin
$admin_id = $_SESSION['user_id'];
$school_query = "SELECT school_id FROM users WHERE user_id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin_data = $result->fetch_assoc();
$school_id = $admin_data['school_id'];
$stmt->close();

// Modified exam configuration handling
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_exam_config'])) {
        // Simplified configuration - only storing max scores
        $activity_max_score = $_POST['activity_max_score'];
        $exam_max_score = $_POST['exam_max_score'];
        
        // Update or insert exam configuration
        $sql = "INSERT INTO exam_config (school_id, activity_max_score, exam_max_score) 
               VALUES (?, ?, ?) 
               ON DUPLICATE KEY UPDATE 
               activity_max_score = ?,
               exam_max_score = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiii", 
            $school_id, $activity_max_score, $exam_max_score,
            $activity_max_score, $exam_max_score
        );
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success'] = "Exam configuration updated successfully.";
    }
    
    if (isset($_POST['add_activity'])) {
        
        // Fetch current configuration
        $config_query = "SELECT activity_max_score FROM exam_config WHERE school_id = ?";
        $stmt = $conn->prepare($config_query);
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $config_result = $stmt->get_result();
        $config = $config_result->fetch_assoc();
        $stmt->close();

        $max_score = $config ? $config['activity_max_score'] : DEFAULT_ACTIVITY_MAX_SCORE;
        
        // Handle adding a new activity
        $term_id = $_POST['term_id'];
        $activity_name = $_POST['activity_name'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $exam_type = EXAM_TYPE_ACTIVITY;
        $selected_subjects = isset($_POST['selected_subjects']) ? $_POST['selected_subjects'] : [];
    
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert the exam
            $sql = "INSERT INTO exams (school_id, term_id, exam_type, category, max_score, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissii", $school_id, $term_id, $exam_type, $activity_name, $max_score, $is_active);
            $stmt->execute();
            $exam_id = $conn->insert_id;
            $stmt->close();
            
            // Insert exam-subject assignments
            if (!empty($selected_subjects)) {
                $assign_sql = "INSERT INTO exam_subjects (exam_id, subject_id, class_id, school_id) VALUES (?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE exam_id = exam_id";
                $assign_stmt = $conn->prepare($assign_sql);
                
                foreach ($selected_subjects as $assignment) {
                    list($subject_id, $class_id) = explode('_', $assignment);
                    $assign_stmt->bind_param("iiii", $exam_id, $subject_id, $class_id, $school_id);
                    $assign_stmt->execute();
                }
                $assign_stmt->close();
            }
            
            $conn->commit();
            $_SESSION['success'] = "Activity added successfully with " . count($selected_subjects) . " subject assignments.";
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error adding activity: " . $e->getMessage();
        }
        
        // Redirect after processing (success or failure)
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } 
    elseif (isset($_POST['add_exam'])) {
        // Fetch current configuration
        $config_query = "SELECT exam_max_score FROM exam_config WHERE school_id = ?";
        $stmt = $conn->prepare($config_query);
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $config_result = $stmt->get_result();
        $config = $config_result->fetch_assoc();
        $stmt->close();

        $max_score = $config ? $config['exam_max_score'] : DEFAULT_EXAM_MAX_SCORE;
        
        // Handle adding a final exam
        $term_id = $_POST['term_id'];
        $exam_name = $_POST['exam_name'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $exam_type = EXAM_TYPE_FINAL;
        $selected_subjects = isset($_POST['selected_subjects']) ? $_POST['selected_subjects'] : [];
    
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert the exam
        $sql = "INSERT INTO exams (school_id, term_id, exam_type, category, max_score, is_active) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iissii", $school_id, $term_id, $exam_type, $exam_name, $max_score, $is_active);
        $stmt->execute();
            $exam_id = $conn->insert_id;
        $stmt->close();
        
            // Insert exam-subject assignments
            if (!empty($selected_subjects)) {
                $assign_sql = "INSERT INTO exam_subjects (exam_id, subject_id, class_id, school_id) VALUES (?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE exam_id = exam_id";
                $assign_stmt = $conn->prepare($assign_sql);
                
                foreach ($selected_subjects as $assignment) {
                    list($subject_id, $class_id) = explode('_', $assignment);
                    $assign_stmt->bind_param("iiii", $exam_id, $subject_id, $class_id, $school_id);
                    $assign_stmt->execute();
                }
                $assign_stmt->close();
            }
            
            $conn->commit();
            $_SESSION['success'] = "Final exam added successfully with " . count($selected_subjects) . " subject assignments.";
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error adding exam: " . $e->getMessage();
        }
        
        // Redirect after processing (success or failure)
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } 
    elseif (isset($_POST['toggle_status'])) {
        $id = $_POST['id'];
        $is_active = $_POST['is_active'] == 1 ? 0 : 1;

        // Update status
        $sql = "UPDATE exams SET is_active = ? WHERE exam_id = ? AND school_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $is_active, $id, $school_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success'] = "Exam status updated successfully.";
    } elseif (isset($_POST['delete_exam'])) {
        $id = $_POST['id'];

        // Start transaction to ensure both deletions succeed or fail together
        $conn->begin_transaction();
        
        try {
            // First, count how many subject assignments will be deleted (for user feedback)
            $count_subjects_sql = "SELECT COUNT(*) as count FROM exam_subjects WHERE exam_id = ? AND school_id = ?";
            $stmt_count = $conn->prepare($count_subjects_sql);
            $stmt_count->bind_param("ii", $id, $school_id);
            $stmt_count->execute();
            $count_result = $stmt_count->get_result();
            $subject_count = $count_result->fetch_assoc()['count'];
            $stmt_count->close();

            // Delete all related exam_subjects records first
            $delete_subjects_sql = "DELETE FROM exam_subjects WHERE exam_id = ? AND school_id = ?";
            $stmt_subjects = $conn->prepare($delete_subjects_sql);
            $stmt_subjects->bind_param("ii", $id, $school_id);
            $stmt_subjects->execute();
            $stmt_subjects->close();

            // Then, delete the exam itself (this will also trigger CASCADE if foreign keys are properly set)
            $delete_exam_sql = "DELETE FROM exams WHERE exam_id = ? AND school_id = ?";
            $stmt_exam = $conn->prepare($delete_exam_sql);
            $stmt_exam->bind_param("ii", $id, $school_id);
            $stmt_exam->execute();
            $affected_exam = $stmt_exam->affected_rows;
            $stmt_exam->close();

            // Check if exam was actually deleted
            if ($affected_exam > 0) {
                $conn->commit();
                $_SESSION['success'] = "Exam and its " . $subject_count . " subject assignments deleted successfully.";
            } else {
                $conn->rollback();
                $_SESSION['error'] = "Exam not found or you don't have permission to delete it.";
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Error deleting exam: " . $e->getMessage();
        }
    }
    elseif (isset($_POST['update_exam_subjects'])) {
        // Add missing subjects to an existing exam without duplicating
        $exam_id = intval($_POST['exam_id']);
        $selected_subjects = isset($_POST['selected_subjects']) ? $_POST['selected_subjects'] : [];

        if ($exam_id > 0 && !empty($selected_subjects)) {
            $conn->begin_transaction();
            try {
                $assign_sql = "INSERT INTO exam_subjects (exam_id, subject_id, class_id, school_id) VALUES (?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE exam_id = exam_id";
                $assign_stmt = $conn->prepare($assign_sql);
                foreach ($selected_subjects as $assignment) {
                    list($subject_id, $class_id) = explode('_', $assignment);
                    $subject_id = intval($subject_id);
                    $class_id = intval($class_id);
                    $assign_stmt->bind_param("iiii", $exam_id, $subject_id, $class_id, $school_id);
                    $assign_stmt->execute();
                }
                $assign_stmt->close();
                $conn->commit();
                $_SESSION['success'] = "Subjects updated for the selected exam/activity.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Failed to update subjects: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select at least one subject to add.";
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?edit_exam_id=" . $exam_id);
        exit();
    }
    elseif (isset($_POST['remove_exam_subjects'])) {
        $exam_id = intval($_POST['exam_id']);
        $remove_subjects = isset($_POST['remove_subjects']) ? $_POST['remove_subjects'] : [];

        if ($exam_id > 0 && !empty($remove_subjects)) {
            $conn->begin_transaction();
            try {
                $delete_sql = "DELETE FROM exam_subjects WHERE exam_id = ? AND subject_id = ? AND class_id = ? AND school_id = ?";
                $delete_stmt = $conn->prepare($delete_sql);
                foreach ($remove_subjects as $assignment) {
                    list($subject_id, $class_id) = explode('_', $assignment);
                    $subject_id = intval($subject_id);
                    $class_id = intval($class_id);
                    $delete_stmt->bind_param("iiii", $exam_id, $subject_id, $class_id, $school_id);
                    $delete_stmt->execute();
                }
                $delete_stmt->close();
                $conn->commit();
                $_SESSION['success'] = "Selected subjects were removed from the exam/activity.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Failed to remove subjects: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please select at least one subject to remove.";
        }

        header("Location: " . $_SERVER['PHP_SELF'] . "?edit_exam_id=" . $exam_id);
        exit();
    }
}

$config_query = "SELECT activity_max_score, exam_max_score 
                FROM exam_config WHERE school_id = ?";
$stmt = $conn->prepare($config_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$config_result = $stmt->get_result();
$config = $config_result->fetch_assoc();
$stmt->close();

if (!$config) {
    $config = [
        'activity_max_score' => DEFAULT_ACTIVITY_MAX_SCORE,
        'exam_max_score' => DEFAULT_EXAM_MAX_SCORE
    ];
}

// Fetch existing terms for the school
$terms_query = "SELECT id, name, year FROM terms WHERE school_id = ? ORDER BY year DESC, start_date DESC";
$stmt = $conn->prepare($terms_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$stmt->close();

// Fetch current term for this school (to filter Manage list)
$current_term = null;
$current_term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term_res = $stmt->get_result();
$current_term = $current_term_res->fetch_assoc();
$stmt->close();

// Fetch school name
$school_name_query = "SELECT school_name FROM schools WHERE id = ?";
$stmt = $conn->prepare($school_name_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school_data = $school_result->fetch_assoc();
$school_name = $school_data['school_name'];
$stmt->close();

// Fetch subjects and classes for assignment
$subjects_query = "SELECT s.subject_id, s.subject_name, s.subject_code, s.class_id, c.name as class_name 
                   FROM subjects s 
                   JOIN classes c ON s.class_id = c.id 
                   WHERE s.school_id = ? 
                   ORDER BY c.name, s.subject_name";
$stmt = $conn->prepare($subjects_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$stmt->close();

// Fetch classes for class selection cards
$classes_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY id";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Exams - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <h1 class="mb-4">Manage Exams</h1>
    
    <a href="school_admin_dashboard.php" class="btn btn-secondary mb-4">
        <i class="fas fa-tachometer-alt"></i> Back to Dashboard
    </a>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['success'];
            unset($_SESSION['success']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['error'];
            unset($_SESSION['error']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php $is_editing = isset($_GET['edit_exam_id']) && intval($_GET['edit_exam_id']) > 0; ?>

    <?php if (!$is_editing): ?>

    <!-- Header Cards -->
    <div class="row g-3 mb-3" id="sectionCards">
        <div class="col-6 col-md-3">
            <div class="card border section-card h-100" data-target="#tab-config">
                <div class="card-body d-flex align-items-center gap-2">
                    <i class="fas fa-sliders-h text-primary"></i>
                    <div class="fw-semibold">Configuration</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border section-card h-100" data-target="#tab-activity">
                <div class="card-body d-flex align-items-center gap-2">
                    <i class="fas fa-plus-circle text-success"></i>
                    <div class="fw-semibold">Add Activity</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border section-card h-100" data-target="#tab-exam">
                <div class="card-body d-flex align-items-center gap-2">
                    <i class="fas fa-clipboard-check text-warning"></i>
                    <div class="fw-semibold">Add Final Exam</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border section-card h-100 active" data-target="#tab-manage">
                <div class="card-body d-flex align-items-center gap-2">
                    <i class="fas fa-list text-info"></i>
                    <div class="fw-semibold">Manage</div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-content">
        <!-- Exam Configuration Form -->
        <div class="tab-pane fade" id="tab-config" role="tabpanel" aria-labelledby="tab-config-tab">
            <div class="card mb-4 shadow-lg">
            <div class="card-header bg-gradient bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-cog me-2"></i>Exam Configuration
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="activity_max_score" class="form-label">Activity Max Score:</label>
                        <input type="number" class="form-control" id="activity_max_score" name="activity_max_score" 
                               value="<?php echo $config['activity_max_score']; ?>" required min="1">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="exam_max_score" class="form-label">Final Exam Max Score:</label>
                        <input type="number" class="form-control" id="exam_max_score" name="exam_max_score" 
                               value="<?php echo $config['exam_max_score']; ?>" required min="1">
                    </div>
                </div>
            </div>
            <button type="submit" name="add_exam_config" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Configuration
            </button>
                </form>
            </div>
        </div>
        </div>

        <!-- Add Activity of Integration -->
        <div class="tab-pane fade" id="tab-activity" role="tabpanel" aria-labelledby="tab-activity-tab">
            <div class="card mb-4 shadow-lg">
                <div class="card-header bg-gradient bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-plus-circle me-2"></i>Add Activity of Integration
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label">Term:</label>
                    <?php if ($current_term): ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_term['name'] . ' (' . $current_term['year'] . ')'); ?>" readonly>
                        <input type="hidden" name="term_id" value="<?php echo (int)$current_term['id']; ?>">
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">No current term set. Please set an active term first.</div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label for="activity_name" class="form-label">Activity Name (e.g., A1, A2):</label>
                    <input type="text" class="form-control" id="activity_name" name="activity_name" required>
                </div>
                
                <!-- Subject and Class Assignment -->
                <div class="mb-4">
                    <label class="form-label fw-bold d-block mb-2"><i class="fas fa-graduation-cap me-2"></i>Assign to Subjects and Classes:</label>

                    <!-- Class selection cards -->
                    <div class="row g-2 mb-3" id="activity_class_cards">
                        <?php $classes_result->data_seek(0); while ($cls = $classes_result->fetch_assoc()): ?>
                            <div class="col-6 col-md-3">
                                <div class="card border class-card h-100" data-class-id="<?php echo (int)$cls['id']; ?>">
                                    <div class="card-body py-3 text-center">
                                        <i class="fas fa-school text-primary mb-2"></i>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($cls['name']); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <!-- Back to classes button -->
                    <div class="mb-3 d-none" id="activity_back_wrapper">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="activity_back_btn"><i class="fas fa-arrow-left me-1"></i> Back to classes</button>
                    </div>

                    <!-- Subjects list filtered by selected class -->
                    <div class="card border-0 shadow-sm" style="max-height: 400px; overflow-y: auto;">
                        <div class="card-body">
                            <div id="activity_subjects_container" class="row g-2">
                                <div class="col-12 text-muted">Select a class to view its subjects.</div>
                            </div>
                        </div>
                    </div>
                    <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Select a class, then pick subjects.</small>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
                <button type="submit" name="add_activity" class="btn btn-success" id="add_activity_btn" <?php echo $current_term ? '' : 'disabled'; ?>>
                    <i class="fas fa-save me-2"></i>Add Activity
                </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Add Final Exam -->
        <div class="tab-pane fade" id="tab-exam" role="tabpanel" aria-labelledby="tab-exam-tab">
            <div class="card mb-4 shadow-lg">
                <div class="card-header bg-gradient bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="fas fa-plus-circle me-2"></i>Add Final Exam
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Term:</label>
                <?php if ($current_term): ?>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_term['name'] . ' (' . $current_term['year'] . ')'); ?>" readonly>
                    <input type="hidden" name="term_id" value="<?php echo (int)$current_term['id']; ?>">
                <?php else: ?>
                    <div class="alert alert-warning mb-0">No current term set. Please set an active term first.</div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="exam_name" class="form-label">Custom Exam Name:</label>
                <input type="text" class="form-control" id="exam_name" name="exam_name" required>
            </div>
            
            <!-- Subject and Class Assignment -->
            <div class="mb-4">
                <label class="form-label fw-bold d-block mb-2"><i class="fas fa-graduation-cap me-2"></i>Assign to Subjects and Classes:</label>

                <!-- Class selection cards -->
                <div class="row g-2 mb-3" id="exam_class_cards">
                    <?php $classes_result->data_seek(0); while ($cls = $classes_result->fetch_assoc()): ?>
                        <div class="col-6 col-md-3">
                            <div class="card border class-card h-100" data-class-id="<?php echo (int)$cls['id']; ?>">
                                <div class="card-body py-3 text-center">
                                    <i class="fas fa-school text-primary mb-2"></i>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($cls['name']); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>

                <!-- Back to classes button -->
                <div class="mb-3 d-none" id="exam_back_wrapper">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="exam_back_btn"><i class="fas fa-arrow-left me-1"></i> Back to classes</button>
                </div>

                <!-- Subjects list filtered by selected class -->
                <div class="card border-0 shadow-sm" style="max-height: 400px; overflow-y: auto;">
                    <div class="card-body">
                        <div id="exam_subjects_container" class="row g-2">
                            <div class="col-12 text-muted">Select a class to view its subjects.</div>
                        </div>
                    </div>
                </div>
                <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Select a class, then pick subjects.</small>
            </div>
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="is_active_exam" name="is_active" checked>
                <label class="form-check-label" for="is_active_exam">Active</label>
            </div>
            <button type="submit" name="add_exam" class="btn btn-warning" id="add_exam_btn" <?php echo $current_term ? '' : 'disabled'; ?> >
                <i class="fas fa-save me-2"></i>Add Final Exam
            </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- List of Activities and Exams -->
        <div class="tab-pane fade show active" id="tab-manage" role="tabpanel" aria-labelledby="tab-manage-tab">
            <div class="card shadow-lg">
                <div class="card-header bg-gradient bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>Activities and Exams
                    </h5>
                </div>
                <div class="card-body">
        <?php
        // Prepare and execute the query with subject assignments
        $exams_query = "SELECT e.*, t.name as term_name, t.year as term_year,
                               GROUP_CONCAT(
                                   DISTINCT CONCAT(s.subject_name, ' (', c.name, ')') 
                                   ORDER BY c.name, s.subject_name 
                                   SEPARATOR ', '
                               ) as assigned_subjects
                       FROM exams e 
                       JOIN terms t ON e.term_id = t.id 
                       LEFT JOIN exam_subjects es ON e.exam_id = es.exam_id
                       LEFT JOIN subjects s ON es.subject_id = s.subject_id
                       LEFT JOIN classes c ON es.class_id = c.id
                       WHERE e.school_id = ? AND e.term_id = ?
                       GROUP BY e.exam_id, e.school_id, e.term_id, e.exam_type, e.category, e.max_score, e.is_active, t.name, t.year
                       ORDER BY t.year DESC, t.start_date DESC, e.exam_type ASC";
        
        $stmt = $conn->prepare($exams_query);
        $current_term_id = $current_term ? (int)$current_term['id'] : 0;
        $stmt->bind_param("ii", $school_id, $current_term_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Term</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Max Score</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold">
                                        <?php echo htmlspecialchars($row['term_name'] . ' (' . $row['term_year'] . ')'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['exam_type'] === EXAM_TYPE_ACTIVITY): ?>
                                        <span class="badge bg-primary">Activity</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Final Exam</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['category'] ?? 'Final Exam'); ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo htmlspecialchars($row['max_score']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['is_active']): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="fas fa-times-circle me-1"></i> Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-end gap-2">
                                        <form method="POST" action="" class="d-inline">
                                            <input type="hidden" name="id" value="<?php echo $row['exam_id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $row['is_active']; ?>">
                                            <button type="submit" 
                                                    name="toggle_status" 
                                                    class="btn btn-sm <?php echo $row['is_active'] ? 'btn-warning' : 'btn-success'; ?>"
                                                    title="<?php echo $row['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="fas <?php echo $row['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                                            </button>
                                        </form>
                                        
                                        <a href="?edit_exam_id=<?php echo $row['exam_id']; ?>" class="btn btn-sm btn-primary" title="Edit Subjects">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <form method="POST" 
                                              action="" 
                                              class="d-inline" 
                                              onsubmit="return confirmDeleteExam('<?php echo $row['exam_type'] === EXAM_TYPE_ACTIVITY ? 'activity' : 'exam'; ?>', '<?php echo htmlspecialchars($row['category']); ?>');">
                                            <input type="hidden" name="id" value="<?php echo $row['exam_id']; ?>">
                                            <button type="submit" 
                                                    name="delete_exam" 
                                                    class="btn btn-sm btn-danger"
                                                    title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>
                <?php if ($current_term): ?>
                    No activities or exams have been added for the current term (<?php echo htmlspecialchars($current_term['name'] . ' ' . $current_term['year']); ?>).
                <?php else: ?>
                    No current term set. Please set an active term first.
                <?php endif; ?>
            </div>
        <?php endif; 
        
        $stmt->close();
        ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['edit_exam_id'])): $edit_exam_id = intval($_GET['edit_exam_id']); ?>
<div class="card mb-4 shadow-lg">
    <div class="card-header bg-gradient bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Subjects for Exam/Activity #<?php echo $edit_exam_id; ?></h5>
    </div>
    <div class="card-body">
        <?php
        // Fetch already assigned subject-class pairs for this exam
        $assigned = [];
        $assigned_query = "SELECT subject_id, class_id FROM exam_subjects WHERE exam_id = ? AND school_id = ?";
        $stmt = $conn->prepare($assigned_query);
        $stmt->bind_param("ii", $edit_exam_id, $school_id);
        $stmt->execute();
        $assigned_result = $stmt->get_result();
        while ($r = $assigned_result->fetch_assoc()) {
            $assigned[$r['subject_id'] . '_' . $r['class_id']] = true;
        }
        $stmt->close();

        // Fetch exam info
        $exam_info = null;
        $exam_info_query = "SELECT e.exam_id, e.exam_type, e.category, e.max_score, t.name AS term_name, t.year
                             FROM exams e JOIN terms t ON e.term_id = t.id
                             WHERE e.exam_id = ? AND e.school_id = ?";
        $stmt = $conn->prepare($exam_info_query);
        $stmt->bind_param("ii", $edit_exam_id, $school_id);
        $stmt->execute();
        $exam_info_res = $stmt->get_result();
        $exam_info = $exam_info_res->fetch_assoc();
        $stmt->close();
        ?>
        <?php if ($exam_info): ?>
            <div class="alert alert-light d-flex align-items-center" role="alert">
                <i class="fas fa-info-circle me-2 text-primary"></i>
                <div>
                    Editing subjects for
                    <strong><?php echo $exam_info['exam_type'] === EXAM_TYPE_ACTIVITY ? 'Activity' : 'Final Exam'; ?></strong>:
                    <span class="fw-semibold">"<?php echo htmlspecialchars($exam_info['category'] ?? 'Final Exam'); ?>"</span>
                    — Term: <?php echo htmlspecialchars($exam_info['term_name'] . ' (' . $exam_info['year'] . ')'); ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
        // Fetch classes for edit panels
        $classes_for_edit = [];
        $cls_q = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY id";
        $stmt = $conn->prepare($cls_q);
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $cls_res = $stmt->get_result();
        while ($r = $cls_res->fetch_assoc()) { $classes_for_edit[] = $r; }
        $stmt->close();
        ?>
        <form method="POST" action="" id="edit_exam_form">
            <input type="hidden" name="exam_id" value="<?php echo $edit_exam_id; ?>">
            <div class="row">
                <div class="col-md-12">
                    <h6 class="fw-bold mb-2"><i class="fas fa-sliders-h me-2"></i>Manage Subjects</h6>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="input-group input-group-sm" style="max-width: 300px;">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" placeholder="Search subjects" oninput="filterUnifiedList(this.value)">
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="toggle_select_all_unified" onchange="setAllUnified(this.checked)">
                                <label class="form-check-label" for="toggle_select_all_unified">Select all</label>
                            </div>
                            <span class="badge bg-primary">Add: <span id="unified_add_count">0</span></span>
                            <span class="badge bg-danger">Remove: <span id="unified_remove_count">0</span></span>
                        </div>
                    </div>
                    <div class="row g-2 mb-3" id="edit_unified_class_cards">
                        <?php foreach ($classes_for_edit as $cls): ?>
                            <div class="col-6 col-md-4">
                                <div class="card border class-card h-100" data-class-id="<?php echo (int)$cls['id']; ?>">
                                    <div class="card-body py-3 text-center">
                                        <i class="fas fa-school text-primary mb-2"></i>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($cls['name']); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3 d-none" id="edit_unified_back_wrapper">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="edit_unified_back_btn"><i class="fas fa-arrow-left me-1"></i> Back to classes</button>
                    </div>
                    <div class="card border-0 shadow-sm" style="max-height: 420px; overflow-y: auto;">
                        <div class="card-body" id="edit_unified_list">
                            <div class="text-muted">Select a class to view its subjects.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" id="btn_apply_additions">
                    <i class="fas fa-plus-circle me-2"></i>Apply Additions
                </button>
                <button type="button" class="btn btn-danger" id="btn_apply_removals">
                    <i class="fas fa-minus-circle me-2"></i>Apply Removals
                </button>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">Done</a>
            </div>
        </form>
    </div>
    </div>
<?php endif; ?>
</div>

<!-- Custom Assignment Confirmation Modal -->
<div class="modal fade" id="assignmentModal" tabindex="-1" aria-labelledby="assignmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="assignmentModalLabel">
                    <i class="fas fa-clipboard-check me-2"></i>
                    <span id="modalTitle">Assignment Summary</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-primary">
                                    <i class="fas fa-graduation-cap me-2"></i>Assigned Classes
                                </h6>
                                <div id="assignedClasses" class="mb-3">
                                    <!-- Classes will be populated here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <h6 class="card-title text-info">
                                    <i class="fas fa-chart-pie me-2"></i>Coverage Statistics
                                </h6>
                                <div class="d-flex align-items-center mb-2">
                                    <div class="progress flex-grow-1 me-3" style="height: 20px;">
                                        <div id="coverageProgress" class="progress-bar" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <span id="coverageText" class="fw-bold">0/0</span>
                                </div>
                                <small class="text-muted" id="coverageMessage">Ready to proceed</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info mt-3" id="modalMessage">
                    <!-- Dynamic message will be shown here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-arrow-left me-1"></i> Go Back
                </button>
                <button type="button" class="btn btn-success" id="confirmSubmit">
                    <i class="fas fa-check me-1"></i> Confirm & Save
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Section header card switching
document.addEventListener('DOMContentLoaded', function() {
    try {
        // Track selected subject-class pairs across class navigation
        window.selectedAssignments = {
            activity: new Set(),
            exam: new Set()
        };

        const cards = document.querySelectorAll('.section-card');
        const panes = document.querySelectorAll('.tab-pane');
        function showPane(targetSelector){
            const target = document.querySelector(targetSelector);
            if (!target) return;
            panes.forEach(function(p){
                p.classList.remove('show','active');
                p.style.display = 'none';
                // Also clear inline style later to let Bootstrap control
                requestAnimationFrame(function(){ p.style.removeProperty('display'); });
            });
            // Explicitly show the target pane then release to CSS
            target.style.display = 'block';
            target.classList.add('show','active');
            requestAnimationFrame(function(){ target.style.removeProperty('display'); });
            cards.forEach(function(c){ c.classList.remove('active'); });
            var activeCard = null;
            Array.from(cards).some(function(c){
                if (c.getAttribute('data-target') === targetSelector) { activeCard = c; return true; }
                return false;
            });
            if (activeCard){ activeCard.classList.add('active'); }
        }
        cards.forEach(function(card){
            card.addEventListener('click', function(){
                const target = this.getAttribute('data-target');
                showPane(target);
            });
            card.addEventListener('mouseenter', function(){ this.classList.add('hovered'); });
            card.addEventListener('mouseleave', function(){ this.classList.remove('hovered'); });
        });
        // Ensure default visible tab (Manage) if present; otherwise first pane
        if (document.querySelector('#tab-manage')) {
            showPane('#tab-manage');
        } else if (panes.length > 0) {
            showPane('#' + panes[0].id);
        }
        // Allow hash navigation
        if (location.hash && document.querySelector(location.hash)) {
            showPane(location.hash);
        }
    } catch (e) {
        // fail-safe: do nothing; avoid breaking the page
    }
});

// Precompute subjects by class for activity/exam tabs
<?php $subjects_result->data_seek(0); $subjects_by_class = []; while ($s = $subjects_result->fetch_assoc()) { $subjects_by_class[$s['class_id']][] = $s; } ?>
window.SUBJECTS_BY_CLASS = <?php echo json_encode($subjects_by_class); ?>;

// Edit panel helpers: filtering, select-all, and counters
function filterEditLists(term) {
    const q = String(term || '').toLowerCase();
    // Left: add list
    const addContainer = document.getElementById('edit_add_list');
    if (addContainer) {
        const addItems = addContainer.querySelectorAll('.form-check');
        addItems.forEach(item => {
            const label = item.querySelector('.form-check-label');
            const text = label ? label.textContent.toLowerCase() : '';
            const show = !q || text.includes(q);
            const col = item.closest('.col-md-12');
            if (col) col.style.display = show ? '' : 'none';
        });
        // Hide class blocks with no visible subjects
        addContainer.querySelectorAll('.border-bottom').forEach(block => {
            const visible = block.querySelectorAll('.col-md-12:not([style*="display: none"])').length > 0;
            block.style.display = visible ? '' : 'none';
        });
    }
    // Right: remove list
    const removeContainer = document.getElementById('edit_remove_list');
    if (removeContainer) {
        removeContainer.querySelectorAll('.form-check').forEach(item => {
            const label = item.querySelector('.form-check-label');
            const text = label ? label.textContent.toLowerCase() : '';
            const col = item.closest('.col-12');
            const show = !q || text.includes(q);
            if (col) col.style.display = show ? '' : 'none';
        });
    }
}

function setAllEditAdd(checked) {
    const addContainer = document.getElementById('edit_add_list');
    if (!addContainer) return;
    addContainer.querySelectorAll('input[name="selected_subjects[]"]').forEach(cb => {
        if (!cb.disabled && cb.closest('.col-md-12') && cb.closest('.col-md-12').style.display !== 'none') {
            cb.checked = !!checked;
        }
    });
    updateEditCounts();
}

function setAllEditRemove(checked) {
    const removeContainer = document.getElementById('edit_remove_list');
    if (!removeContainer) return;
    removeContainer.querySelectorAll('input[name="remove_subjects[]"]').forEach(cb => {
        if (cb.closest('.col-12') && cb.closest('.col-12').style.display !== 'none') {
            cb.checked = !!checked;
        }
    });
    updateEditCounts();
}

function updateEditCounts() {
    const addContainer = document.getElementById('edit_add_list');
    const addCountEl = document.getElementById('edit_add_count');
    if (addContainer && addCountEl) {
        const c = Array.from(addContainer.querySelectorAll('input[name="selected_subjects[]"]'))
            .filter(cb => cb.checked).length;
        addCountEl.textContent = c;
    }
    const removeContainer = document.getElementById('edit_remove_list');
    const removeCountEl = document.getElementById('edit_remove_count');
    if (removeContainer && removeCountEl) {
        const c = Array.from(removeContainer.querySelectorAll('input[name="remove_subjects[]"]'))
            .filter(cb => cb.checked).length;
        removeCountEl.textContent = c;
    }
}

document.addEventListener('DOMContentLoaded', function(){
    // Keep counters in sync in edit panel
    document.querySelectorAll('#edit_add_list input[name="selected_subjects[]"], #edit_remove_list input[name="remove_subjects[]"]').forEach(cb => {
        cb.addEventListener('change', updateEditCounts);
    });
    updateEditCounts();
});

// --- Dynamic rendering for edit panels ---
<?php
$is_edit_mode = isset($_GET['edit_exam_id']) && intval($_GET['edit_exam_id']) > 0;
if ($is_edit_mode) {
    // Build subjects by class for edit add
    $subjects_map = [];
    $subjects_query_edit = "SELECT s.subject_id, s.subject_name, s.subject_code, s.class_id FROM subjects s WHERE s.school_id = ? ORDER BY s.class_id, s.subject_name";
    $stmt = $conn->prepare($subjects_query_edit);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $sub_res = $stmt->get_result();
    while ($row = $sub_res->fetch_assoc()) {
        $cid = (int)$row['class_id'];
        if (!isset($subjects_map[$cid])) { $subjects_map[$cid] = []; }
        $subjects_map[$cid][] = $row;
    }
    $stmt->close();

    // Build assigned by class for edit remove
    $assigned_by_class = [];
    $assigned_list_query2 = "SELECT es.subject_id, es.class_id, s.subject_name, c.name AS class_name
                             FROM exam_subjects es 
                             JOIN subjects s ON es.subject_id = s.subject_id
                             JOIN classes c ON es.class_id = c.id
                             WHERE es.exam_id = ? AND es.school_id = ?
                             ORDER BY c.name, s.subject_name";
    $stmt = $conn->prepare($assigned_list_query2);
    $stmt->bind_param("ii", $edit_exam_id, $school_id);
    $stmt->execute();
    $res2 = $stmt->get_result();
    while ($as = $res2->fetch_assoc()) {
        $cid = (int)$as['class_id'];
        if (!isset($assigned_by_class[$cid])) { $assigned_by_class[$cid] = []; }
        $assigned_by_class[$cid][] = $as;
    }
    $stmt->close();

    $assigned_keys = array_keys($assigned ?? []);
} else {
    $subjects_map = new stdClass();
    $assigned_by_class = new stdClass();
    $assigned_keys = [];
}
?>
window.EDIT_SUBJECTS_BY_CLASS = <?php echo json_encode($subjects_map); ?>;
window.EDIT_ASSIGNED_SET = new Set(<?php echo json_encode($assigned_keys); ?>);
window.EDIT_ASSIGNED_BY_CLASS = <?php echo json_encode($assigned_by_class); ?>;

function renderEditAddSubjects(classId) {
    const container = document.getElementById('edit_add_list');
    if (!container) return;
    container.innerHTML = '';
    const list = (window.EDIT_SUBJECTS_BY_CLASS && window.EDIT_SUBJECTS_BY_CLASS[String(classId)]) || [];
    if (list.length === 0) {
        container.innerHTML = '<div class="text-muted">No subjects found for this class.</div>';
        return;
    }
    const row = document.createElement('div');
    row.className = 'row g-2';
    list.forEach(subj => {
        const val = `${subj.subject_id}_${subj.class_id}`;
        const disabled = window.EDIT_ASSIGNED_SET && window.EDIT_ASSIGNED_SET.has(val);
        const col = document.createElement('div');
        col.className = 'col-md-12';
        col.innerHTML = '<div class="form-check p-2 border rounded hover-shadow">'
            + `<input class="form-check-input" type="checkbox" name="selected_subjects[]" id="edit_add_subject_${val}" value="${val}" ${disabled ? 'disabled' : ''}>`
            + `<label class="form-check-label w-100" for="edit_add_subject_${val}">`
            + `<div class="fw-semibold">${subj.subject_name}</div>`
            + `<small class="text-muted">${subj.subject_code || ''}</small>`
            + (disabled ? ' <span class="badge bg-success ms-2">Already added</span>' : '')
            + '</label></div>';
        row.appendChild(col);
    });
    container.appendChild(row);
    container.querySelectorAll('input[name="selected_subjects[]"]').forEach(cb => cb.addEventListener('change', updateEditCounts));
    updateEditCounts();
}

function renderEditRemoveSubjects(classId) {
    const container = document.getElementById('edit_remove_list');
    if (!container) return;
    container.innerHTML = '';
    const list = (window.EDIT_ASSIGNED_BY_CLASS && window.EDIT_ASSIGNED_BY_CLASS[String(classId)]) || [];
    if (list.length === 0) {
        container.innerHTML = '<div class="alert alert-info mb-0">No assigned subjects in this class.</div>';
        updateEditCounts();
        return;
    }
    const row = document.createElement('div');
    row.className = 'row g-2';
    list.forEach(as => {
        const val = `${as.subject_id}_${as.class_id}`;
        const col = document.createElement('div');
        col.className = 'col-12';
        col.innerHTML = '<div class="form-check p-2 border rounded">'
            + `<input class="form-check-input" type="checkbox" name="remove_subjects[]" id="remove_subject_${val}" value="${val}">`
            + `<label class="form-check-label w-100" for="remove_subject_${val}">`
            + `<div class="fw-semibold">${as.subject_name}</div>`
            + `<small class="text-muted">Class: ${as.class_name}</small>`
            + '</label></div>';
        row.appendChild(col);
    });
    container.appendChild(row);
    container.querySelectorAll('input[name="remove_subjects[]"]').forEach(cb => cb.addEventListener('change', updateEditCounts));
    updateEditCounts();
}

document.addEventListener('DOMContentLoaded', function(){
    // Edit Add class cards interactions
    document.querySelectorAll('#edit_add_class_cards .class-card').forEach(function(card){
        card.addEventListener('click', function(){
            const wrapper = document.getElementById('edit_add_class_cards');
            wrapper.querySelectorAll('.class-card').forEach(function(c){
                if (c !== card) {
                    const col = c.closest('.col-6') || c.closest('.col-md-4');
                    if (col) col.classList.add('d-none');
                }
            });
            card.classList.add('active');
            const backWrap = document.getElementById('edit_add_back_wrapper');
            if (backWrap) backWrap.classList.remove('d-none');
            const classId = this.getAttribute('data-class-id');
            renderEditAddSubjects(classId);
        });
    });
    const editAddBack = document.getElementById('edit_add_back_btn');
    if (editAddBack) {
        editAddBack.addEventListener('click', function(){
            const backWrap = document.getElementById('edit_add_back_wrapper');
            if (backWrap) backWrap.classList.add('d-none');
            const cont = document.getElementById('edit_add_list');
            if (cont) cont.innerHTML = '<div class="text-muted">Select a class to view its subjects.</div>';
            document.querySelectorAll('#edit_add_class_cards .class-card').forEach(function(c){
                c.classList.remove('active');
                const col = c.closest('.col-6, .col-md-4');
                if (col) col.classList.remove('d-none');
            });
        });
    }

    // Edit Remove class cards interactions
    document.querySelectorAll('#edit_remove_class_cards .class-card').forEach(function(card){
        card.addEventListener('click', function(){
            const wrapper = document.getElementById('edit_remove_class_cards');
            wrapper.querySelectorAll('.class-card').forEach(function(c){
                if (c !== card) {
                    const col = c.closest('.col-6') || c.closest('.col-md-4');
                    if (col) col.classList.add('d-none');
                }
            });
            card.classList.add('active');
            const backWrap = document.getElementById('edit_remove_back_wrapper');
            if (backWrap) backWrap.classList.remove('d-none');
            const classId = this.getAttribute('data-class-id');
            renderEditRemoveSubjects(classId);
        });
    });
    const editRemoveBack = document.getElementById('edit_remove_back_btn');
    if (editRemoveBack) {
        editRemoveBack.addEventListener('click', function(){
            const backWrap = document.getElementById('edit_remove_back_wrapper');
            if (backWrap) backWrap.classList.add('d-none');
            const cont = document.getElementById('edit_remove_list');
            if (cont) cont.innerHTML = '<div class="text-muted">Select a class to view assigned subjects.</div>';
            document.querySelectorAll('#edit_remove_class_cards .class-card').forEach(function(c){
                c.classList.remove('active');
                const col = c.closest('.col-6, .col-md-4');
                if (col) col.classList.remove('d-none');
            });
        });
    }
});

// --- Unified edit panel (single side add/remove) ---
window.UNIFIED = { selectedAdd: new Set(), selectedRemove: new Set(), currentClassId: null };

function renderUnifiedSubjects(classId) {
    const container = document.getElementById('edit_unified_list');
    if (!container) return;
    window.UNIFIED.currentClassId = String(classId);
    container.innerHTML = '';
    const list = (window.EDIT_SUBJECTS_BY_CLASS && window.EDIT_SUBJECTS_BY_CLASS[String(classId)]) || [];
    if (list.length === 0) {
        container.innerHTML = '<div class="text-muted">No subjects found for this class.</div>';
        updateUnifiedCounts();
        return;
    }
    const row = document.createElement('div');
    row.className = 'row g-2';
    list.forEach(subj => {
        const val = `${subj.subject_id}_${subj.class_id}`;
        const isAssigned = window.EDIT_ASSIGNED_SET && window.EDIT_ASSIGNED_SET.has(val);
        const col = document.createElement('div');
        col.className = 'col-md-12';
        const addChecked = window.UNIFIED.selectedAdd.has(val);
        const remChecked = window.UNIFIED.selectedRemove.has(val);
        col.innerHTML = '<div class="p-2 border rounded">'
            + `<div class="d-flex justify-content-between align-items-center">`
            + `<div><div class="fw-semibold">${subj.subject_name}</div>`
            + `<small class="text-muted">${subj.subject_code || ''}</small>`
            + (isAssigned ? ' <span class="badge bg-success ms-2">Assigned</span>' : ' <span class="badge bg-secondary ms-2">Not assigned</span>')
            + `</div>`
            + `<div class="d-flex align-items-center gap-3">`
            + (!isAssigned
                ? `<div class="form-check mb-0"><input class="form-check-input unified-add" type="checkbox" id="un_add_${val}" data-value="${val}" ${addChecked ? 'checked' : ''}><label class="form-check-label" for="un_add_${val}">Add</label></div>`
                : '')
            + (isAssigned
                ? `<div class="form-check mb-0"><input class="form-check-input unified-remove" type="checkbox" id="un_rm_${val}" data-value="${val}" ${remChecked ? 'checked' : ''}><label class="form-check-label" for="un_rm_${val}">Remove</label></div>`
                : '')
            + `</div>`
            + `</div>`
            + '</div>';
        row.appendChild(col);
    });
    container.appendChild(row);
    container.querySelectorAll('.unified-add').forEach(cb => cb.addEventListener('change', function(){
        const v = this.getAttribute('data-value');
        if (this.checked) { window.UNIFIED.selectedAdd.add(v); } else { window.UNIFIED.selectedAdd.delete(v); }
        updateUnifiedCounts();
    }));
    container.querySelectorAll('.unified-remove').forEach(cb => cb.addEventListener('change', function(){
        const v = this.getAttribute('data-value');
        if (this.checked) { window.UNIFIED.selectedRemove.add(v); } else { window.UNIFIED.selectedRemove.delete(v); }
        updateUnifiedCounts();
    }));
    updateUnifiedCounts();
}

function updateUnifiedCounts() {
    const addEl = document.getElementById('unified_add_count');
    const remEl = document.getElementById('unified_remove_count');
    if (addEl) addEl.textContent = window.UNIFIED.selectedAdd.size;
    if (remEl) remEl.textContent = window.UNIFIED.selectedRemove.size;
}

function setAllUnified(checked) {
    const container = document.getElementById('edit_unified_list');
    if (!container) return;
    container.querySelectorAll('.unified-add').forEach(cb => {
        const v = cb.getAttribute('data-value');
        cb.checked = !!checked;
        if (checked) window.UNIFIED.selectedAdd.add(v); else window.UNIFIED.selectedAdd.delete(v);
    });
    container.querySelectorAll('.unified-remove').forEach(cb => {
        const v = cb.getAttribute('data-value');
        cb.checked = !!checked;
        if (checked) window.UNIFIED.selectedRemove.add(v); else window.UNIFIED.selectedRemove.delete(v);
    });
    updateUnifiedCounts();
}

function filterUnifiedList(term) {
    const q = String(term || '').toLowerCase();
    const container = document.getElementById('edit_unified_list');
    if (!container) return;
    container.querySelectorAll('.col-md-12').forEach(col => {
        const text = col.textContent.toLowerCase();
        col.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function(){
    // Class cards interactions (unified)
    document.querySelectorAll('#edit_unified_class_cards .class-card').forEach(function(card){
        card.addEventListener('click', function(){
            const wrapper = document.getElementById('edit_unified_class_cards');
            wrapper.querySelectorAll('.class-card').forEach(function(c){
                if (c !== card) {
                    const col = c.closest('.col-6') || c.closest('.col-md-4');
                    if (col) col.classList.add('d-none');
                }
            });
            card.classList.add('active');
            const backWrap = document.getElementById('edit_unified_back_wrapper');
            if (backWrap) backWrap.classList.remove('d-none');
            const classId = this.getAttribute('data-class-id');
            renderUnifiedSubjects(classId);
        });
    });
    const unifiedBack = document.getElementById('edit_unified_back_btn');
    if (unifiedBack) {
        unifiedBack.addEventListener('click', function(){
            const backWrap = document.getElementById('edit_unified_back_wrapper');
            if (backWrap) backWrap.classList.add('d-none');
            const cont = document.getElementById('edit_unified_list');
            if (cont) cont.innerHTML = '<div class="text-muted">Select a class to view its subjects.</div>';
            document.querySelectorAll('#edit_unified_class_cards .class-card').forEach(function(c){
                c.classList.remove('active');
                const col = c.closest('.col-6, .col-md-4');
                if (col) col.classList.remove('d-none');
            });
        });
    }

    // Apply buttons
    const formEl = document.getElementById('edit_exam_form');
    const addBtn = document.getElementById('btn_apply_additions');
    const rmBtn = document.getElementById('btn_apply_removals');
    function clearUnifiedHidden() {
        if (!formEl) return;
        formEl.querySelectorAll('input.unified-hidden').forEach(el => el.remove());
    }
    if (addBtn && formEl) {
        addBtn.addEventListener('click', function(){
            if (window.UNIFIED.selectedAdd.size === 0) { alert('Select at least one subject to add.'); return; }
            clearUnifiedHidden();
            window.UNIFIED.selectedAdd.forEach(v => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = 'selected_subjects[]'; i.value = v; i.className = 'unified-hidden';
                formEl.appendChild(i);
            });
            const flag = document.createElement('input');
            flag.type = 'hidden'; flag.name = 'update_exam_subjects'; flag.value = '1'; flag.className = 'unified-hidden';
            formEl.appendChild(flag);
            // Ensure exam_id is present
            if (!formEl.querySelector('input[name="exam_id"]')) {
                const ex = document.createElement('input'); ex.type = 'hidden'; ex.name = 'exam_id'; ex.className = 'unified-hidden';
                formEl.appendChild(ex);
            }
            formEl.submit();
        });
    }
    if (rmBtn && formEl) {
        rmBtn.addEventListener('click', function(){
            if (window.UNIFIED.selectedRemove.size === 0) { alert('Select at least one subject to remove.'); return; }
            if (!confirm('Remove selected subjects from this exam/activity?')) return;
            clearUnifiedHidden();
            window.UNIFIED.selectedRemove.forEach(v => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = 'remove_subjects[]'; i.value = v; i.className = 'unified-hidden';
                formEl.appendChild(i);
            });
            const flag = document.createElement('input');
            flag.type = 'hidden'; flag.name = 'remove_exam_subjects'; flag.value = '1'; flag.className = 'unified-hidden';
            formEl.appendChild(flag);
            if (!formEl.querySelector('input[name="exam_id"]')) {
                const ex = document.createElement('input'); ex.type = 'hidden'; ex.name = 'exam_id'; ex.className = 'unified-hidden';
                formEl.appendChild(ex);
            }
            formEl.submit();
        });
    }
});
// Dynamic subject loading by class (Activity and Exam)
function renderSubjects(containerId, classId, formType) {
    const container = document.getElementById(containerId);
    container.innerHTML = '';
    const data = window.SUBJECTS_BY_CLASS || {};
    const list = data[String(classId)] || [];
    if (list.length === 0) {
        container.innerHTML = '<div class="col-12 text-muted">No subjects found for the selected class.</div>';
        return;
    }

    // Add a select-all control for this class
    const header = document.createElement('div');
    header.className = 'col-12 mb-2';
    const selectAllId = formType + '_select_all_class_' + classId;
    header.innerHTML = '<div class="form-check">'
        + '<input class="form-check-input" type="checkbox" id="' + selectAllId + '">'
        + '<label class="form-check-label" for="' + selectAllId + '">Select all subjects in this class</label>'
        + '</div>';
    container.appendChild(header);

    list.forEach(function(subj){
        const col = document.createElement('div');
        col.className = 'col-md-6 col-lg-4';
        const id = formType + '_subject_' + subj.subject_id + '_' + subj.class_id;
        const value = subj.subject_id + '_' + subj.class_id;
        const isChecked = window.selectedAssignments && window.selectedAssignments[formType] && window.selectedAssignments[formType].has(value);
        col.innerHTML = '<div class="form-check p-2 border rounded hover-shadow">'
            + '<input class="form-check-input" type="checkbox" name="selected_subjects[]" value="' + value + '" id="' + id + '" ' + (isChecked ? 'checked' : '') + '>'
            + '<label class="form-check-label w-100" for="' + id + '">' 
            + '<div class="fw-semibold">' + subj.subject_name + '</div>' 
            + '<small class="text-muted">' + (subj.subject_code || '') + '</small>' 
            + '</label></div>';
        container.appendChild(col);

        // Attach change handler to keep selections in memory across navigation
        const checkbox = col.querySelector('input.form-check-input');
        checkbox.addEventListener('change', function(){
            if (!window.selectedAssignments || !window.selectedAssignments[formType]) return;
            if (this.checked) {
                window.selectedAssignments[formType].add(value);
            } else {
                window.selectedAssignments[formType].delete(value);
            }
            updateSelectionCount(formType);
            updateSelectAllState(formType);
            // Update class select-all tristate
            const selectAllEl = document.getElementById(selectAllId);
            if (selectAllEl) {
                const checks = container.querySelectorAll('input.form-check-input[name="selected_subjects[]"]');
                const total = checks.length;
                const checked = Array.from(checks).filter(cb => cb.checked).length;
                selectAllEl.checked = checked === total && total > 0;
                selectAllEl.indeterminate = checked > 0 && checked < total;
            }
        });
    });

    // Wire up select-all behavior now that checkboxes exist
    const selectAllEl = document.getElementById(selectAllId);
    if (selectAllEl) {
        // Initialize state
        const checks = container.querySelectorAll('input.form-check-input[name="selected_subjects[]"]');
        const total = checks.length;
        const checked = Array.from(checks).filter(cb => cb.checked).length;
        selectAllEl.checked = checked === total && total > 0;
        selectAllEl.indeterminate = checked > 0 && checked < total;

        selectAllEl.addEventListener('change', function(){
            const shouldCheck = this.checked;
            const setRef = window.selectedAssignments && window.selectedAssignments[formType];
            checks.forEach(cb => {
                cb.checked = shouldCheck;
                const val = cb.value;
                if (shouldCheck) {
                    if (setRef) setRef.add(val);
                } else {
                    if (setRef) setRef.delete(val);
                }
            });
            this.indeterminate = false;
            updateSelectionCount(formType);
            updateSelectAllState(formType);
        });
    }
}

document.addEventListener('DOMContentLoaded', function(){
    // Activity class cards
    document.querySelectorAll('#activity_class_cards .class-card').forEach(function(card){
        card.addEventListener('click', function(){
            const wrapper = document.getElementById('activity_class_cards');
            wrapper.querySelectorAll('.class-card').forEach(function(c){
                if (c !== card) {
                    const col = c.closest('.col-6') || c.closest('.col-md-3');
                    if (col) col.classList.add('d-none');
                }
            });
            card.classList.add('active');
            const backWrap = document.getElementById('activity_back_wrapper');
            if (backWrap) backWrap.classList.remove('d-none');
            const classId = this.getAttribute('data-class-id');
            renderSubjects('activity_subjects_container', classId, 'activity');
        });
    });
    const actBack = document.getElementById('activity_back_btn');
    if (actBack) {
        actBack.addEventListener('click', function(){
            const backWrap = document.getElementById('activity_back_wrapper');
            if (backWrap) backWrap.classList.add('d-none');
            document.getElementById('activity_subjects_container').innerHTML = '<div class="col-12 text-muted">Select a class to view its subjects.</div>';
            document.querySelectorAll('#activity_class_cards .class-card').forEach(function(c){
                c.classList.remove('active');
                const col = c.closest('.col-6, .col-md-3');
                if (col) col.classList.remove('d-none');
            });
        });
    }

    // Exam class cards
    document.querySelectorAll('#exam_class_cards .class-card').forEach(function(card){
        card.addEventListener('click', function(){
            const wrapper = document.getElementById('exam_class_cards');
            wrapper.querySelectorAll('.class-card').forEach(function(c){
                if (c !== card) {
                    const col = c.closest('.col-6') || c.closest('.col-md-3');
                    if (col) col.classList.add('d-none');
                }
            });
            card.classList.add('active');
            const backWrap = document.getElementById('exam_back_wrapper');
            if (backWrap) backWrap.classList.remove('d-none');
            const classId = this.getAttribute('data-class-id');
            renderSubjects('exam_subjects_container', classId, 'exam');
        });
    });
    const exBack = document.getElementById('exam_back_btn');
    if (exBack) {
        exBack.addEventListener('click', function(){
            const backWrap = document.getElementById('exam_back_wrapper');
            if (backWrap) backWrap.classList.add('d-none');
            document.getElementById('exam_subjects_container').innerHTML = '<div class="col-12 text-muted">Select a class to view its subjects.</div>';
            document.querySelectorAll('#exam_class_cards .class-card').forEach(function(c){
                c.classList.remove('active');
                const col = c.closest('.col-6, .col-md-3');
                if (col) col.classList.remove('d-none');
            });
        });
    }
});
// Validate exam configuration form (guard for missing form/inputs)
const cfgForm = document.getElementById('configForm');
if (cfgForm) {
    cfgForm.addEventListener('submit', function(e) {
        const activityWeightEl = document.getElementById('activity_weight');
        const examWeightEl = document.getElementById('exam_weight');
        if (!activityWeightEl || !examWeightEl) return;
        const activityWeight = parseInt(activityWeightEl.value);
        const examWeight = parseInt(examWeightEl.value);
        if (activityWeight + examWeight !== 100) {
            e.preventDefault();
            alert('The total weight must equal 100%');
        }
    });
}

// Validate activity name format (guard for missing form)
const namedActivityForm = document.querySelector('form[name="add_activity"]');
if (namedActivityForm) {
    namedActivityForm.addEventListener('submit', function(e) {
        const activityNameEl = document.getElementById('activity_name');
        if (!activityNameEl) return;
        const activityName = activityNameEl.value;
        const pattern = /^A[0-9]+$/;
        if (!pattern.test(activityName)) {
            e.preventDefault();
            alert('Activity name must be in the format "A" followed by a number (e.g., A1, A2)');
        }
    });
}

// Global functions for subject selection
function toggleAllSubjects(formType) {
    const selectAllCheckbox = document.getElementById(`select_all_${formType}`);
    const allSubjectCheckboxes = document.querySelectorAll(`input[name="selected_subjects[]"]`);
    
    allSubjectCheckboxes.forEach(checkbox => {
        if (checkbox.id.includes(formType)) {
            checkbox.checked = selectAllCheckbox.checked;
        }
    });
    
    // Update class-level select all checkboxes
    const classCheckboxes = document.querySelectorAll(`input[id^="select_class_${formType}_"]`);
    classCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateSelectionCount(formType);
}

function toggleClassSubjects(formType, classIndex) {
    const classCheckbox = document.getElementById(`select_class_${formType}_${classIndex}`);
    const classDiv = classCheckbox.closest('.border-bottom');
    const subjectCheckboxes = classDiv.querySelectorAll(`input[name="selected_subjects[]"]`);
    
    subjectCheckboxes.forEach(checkbox => {
        if (checkbox.id.includes(formType)) {
            checkbox.checked = classCheckbox.checked;
        }
    });
    
    updateSelectionCount(formType);
    updateSelectAllState(formType);
}

function updateSelectionCount(formType) {
    const allSubjectCheckboxes = document.querySelectorAll(`input[name="selected_subjects[]"]`);
    let checkedCount = 0;
    
    allSubjectCheckboxes.forEach(checkbox => {
        if (checkbox.id.includes(formType) && checkbox.checked) {
            checkedCount++;
        }
    });
    
    const counterEl = document.getElementById(`${formType}_selected_count`);
    if (counterEl) {
        counterEl.textContent = checkedCount;
    }
}

function updateSelectAllState(formType) {
    const allSubjectCheckboxes = document.querySelectorAll(`input[name="selected_subjects[]"]`);
    const selectAllCheckbox = document.getElementById(`select_all_${formType}`);
    
    let totalSubjects = 0;
    let checkedSubjects = 0;
    
    allSubjectCheckboxes.forEach(checkbox => {
        if (checkbox.id.includes(formType)) {
            totalSubjects++;
            if (checkbox.checked) {
                checkedSubjects++;
            }
        }
    });
    
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = checkedSubjects === totalSubjects && totalSubjects > 0;
        selectAllCheckbox.indeterminate = checkedSubjects > 0 && checkedSubjects < totalSubjects;
    }
}

// Add hover effects and better styling
document.addEventListener('DOMContentLoaded', function() {
    // Add hover effects to subject cards
    const subjectCards = document.querySelectorAll('.hover-shadow');
    subjectCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 8px rgba(0,0,0,0.1)';
            this.style.transition = 'all 0.3s ease';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.05)';
        });
    });
    
    // Initialize selection counts
    updateSelectionCount('activity');
    updateSelectionCount('exam');
    
    // Add click handlers for individual checkboxes
    const allCheckboxes = document.querySelectorAll('input[name="selected_subjects[]"]');
    allCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const formType = this.id.includes('activity') ? 'activity' : 'exam';
            updateSelectionCount(formType);
            updateSelectAllState(formType);
        });
    });
});

// Enhanced delete confirmation function
function confirmDeleteExam(examType, examName) {
    const message = `Are you sure you want to delete this ${examType}?\n\n` +
                   `Name: "${examName}"\n\n` +
                   `⚠️ WARNING: This will also delete ALL subject assignments for this ${examType}.\n\n` +
                   `This action cannot be undone.`;
    
    return confirm(message);
}

// Enhanced form validation uses persistent selections
function validateForm(formType) {
    const setRef = window.selectedAssignments && window.selectedAssignments[formType];
    let hasSelected = setRef && setRef.size > 0;
    if (!hasSelected) {
        // Fallback: scan currently present checkboxes for this formType
        const visibleChecks = document.querySelectorAll(`input[name="selected_subjects[]"]`);
        visibleChecks.forEach(cb => {
            if (cb.checked && cb.id.includes(formType)) {
                if (setRef) setRef.add(cb.value);
            }
        });
        hasSelected = setRef && setRef.size > 0;
    }
    if (!hasSelected) {
        alert(`Please select at least one subject for the ${formType}.`);
        return false;
    }
    return true;
}

// Sync persistent selections into hidden inputs so the backend receives all pairs
function syncSelectedHiddenInputs(formElement, formType) {
    if (!formElement) return;
    // Remove previous dynamic inputs
    formElement.querySelectorAll('input.selected-hidden').forEach(el => el.remove());
    
    // Ensure the set exists
    if (!window.selectedAssignments) window.selectedAssignments = { activity: new Set(), exam: new Set() };
    if (!window.selectedAssignments[formType]) window.selectedAssignments[formType] = new Set();
    
    const setRef = window.selectedAssignments[formType];
    
    // As a safety net, if nothing in the set, gather from visible checkboxes
    if (setRef.size === 0) {
        const visibleChecks = document.querySelectorAll(`input[name="selected_subjects[]"]`);
        visibleChecks.forEach(cb => {
            if (cb.checked && cb.id.includes(formType)) {
                setRef.add(cb.value);
            }
        });
    }
    
    // Now inject all selected values as hidden inputs
    setRef.forEach(value => {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'selected_subjects[]';
        hidden.value = value;
        hidden.className = 'selected-hidden';
        formElement.appendChild(hidden);
    });
}

// Add loading state to buttons
function showLoading(buttonId) {
    const button = document.getElementById(buttonId);
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
        button.classList.add('loading');
    }
}

function hideLoading(buttonId, originalText) {
    const button = document.getElementById(buttonId);
    if (button) {
        button.disabled = false;
        button.innerHTML = originalText;
        button.classList.remove('loading');
    }
}

// Search/filter functionality
function filterSubjects(formType, searchTerm) {
    const subjectCards = document.querySelectorAll(`input[name="selected_subjects[]"]`);
    const searchTermLower = searchTerm.toLowerCase();
    
    subjectCards.forEach(checkbox => {
        if (checkbox.id.includes(formType)) {
            const card = checkbox.closest('.col-md-6');
            const label = checkbox.nextElementSibling;
            const subjectName = label.querySelector('.fw-semibold').textContent.toLowerCase();
            const subjectCode = label.querySelector('.text-muted').textContent.toLowerCase();
            
            if (subjectName.includes(searchTermLower) || subjectCode.includes(searchTermLower)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        }
    });
    
    // Also filter class headers
    const classHeaders = document.querySelectorAll('.border-bottom');
    classHeaders.forEach(header => {
        const classDiv = header;
        const visibleSubjects = classDiv.querySelectorAll('.col-md-6[style*="block"], .col-md-6:not([style*="none"])');
        
        if (visibleSubjects.length === 0) {
            classDiv.style.display = 'none';
        } else {
            classDiv.style.display = 'block';
        }
    });
}

// Build a list of class IDs that have at least one subject (coverage target)
<?php $subjects_result->data_seek(0); $class_ids_unique = []; while ($s = $subjects_result->fetch_assoc()) { $class_ids_unique[$s['class_id']] = true; } ?>
window.ALL_CLASS_IDS_WITH_SUBJECTS = <?php echo json_encode(array_map('intval', array_keys($class_ids_unique))); ?>;

// Helpers: compute selected class coverage and confirm full coverage
function getSelectedClassIds(formType) {
    const result = new Set();
    const setRef = window.selectedAssignments && window.selectedAssignments[formType];
    if (!setRef) return result;
    setRef.forEach(v => {
        const parts = String(v).split('_');
        const classId = parts[1];
        if (classId) result.add(String(classId));
    });
    return result;
}

function showAssignmentModal(formType) {
    const allClassIds = (window.ALL_CLASS_IDS_WITH_SUBJECTS || []).map(String);
    const selectedClassIds = Array.from(getSelectedClassIds(formType));
    const total = allClassIds.length;
    const covered = selectedClassIds.filter(id => allClassIds.includes(id)).length;
    
    // Get class names for display
    const classNames = getClassNamesForSelectedIds(selectedClassIds);
    
    // Update modal title
    document.getElementById('modalTitle').textContent = `${formType.toUpperCase()} Assignment Summary`;
    
    // Update assigned classes
    const assignedClassesDiv = document.getElementById('assignedClasses');
    if (classNames.length > 0) {
        assignedClassesDiv.innerHTML = classNames.map(name => 
            `<span class="badge bg-primary me-2 mb-2">${name}</span>`
        ).join('');
    } else {
        assignedClassesDiv.innerHTML = '<span class="text-muted">No classes selected</span>';
    }
    
    // Update coverage statistics
    const coverageProgress = document.getElementById('coverageProgress');
    const coverageText = document.getElementById('coverageText');
    const coverageMessage = document.getElementById('coverageMessage');
    const modalMessage = document.getElementById('modalMessage');
    
    const percentage = total > 0 ? Math.round((covered / total) * 100) : 0;
    coverageProgress.style.width = `${percentage}%`;
    coverageProgress.className = `progress-bar ${percentage === 100 ? 'bg-success' : percentage >= 50 ? 'bg-warning' : 'bg-danger'}`;
    coverageText.textContent = `${covered}/${total}`;
    
    if (total > 0 && covered < total) {
        coverageMessage.textContent = `Incomplete coverage - ${total - covered} classes remaining`;
        modalMessage.innerHTML = `
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Incomplete Assignment:</strong> You have assigned this ${formType} to only ${covered} out of ${total} classes. 
            You can still proceed to save, or go back to complete the assignment.
        `;
        modalMessage.className = 'alert alert-warning mt-3';
    } else {
        coverageMessage.textContent = 'Complete coverage achieved';
        modalMessage.innerHTML = `
            <i class="fas fa-check-circle me-2"></i>
            <strong>Ready to Save:</strong> All ${total} classes have been assigned. Click "Confirm & Save" to proceed.
        `;
        modalMessage.className = 'alert alert-success mt-3';
    }
    
    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('assignmentModal'));
    modal.show();
    
    // Return a promise that resolves when user confirms
    return new Promise((resolve) => {
        document.getElementById('confirmSubmit').onclick = () => {
            modal.hide();
            resolve(true);
        };
        
        document.getElementById('assignmentModal').addEventListener('hidden.bs.modal', () => {
            resolve(false);
        });
    });
}

function getClassNamesForSelectedIds(selectedClassIds) {
    // Get class names from the class cards
    const classNames = [];
    selectedClassIds.forEach(classId => {
        const classCard = document.querySelector(`[data-class-id="${classId}"]`);
        if (classCard) {
            const className = classCard.querySelector('.fw-semibold').textContent;
            classNames.push(className);
        }
    });
    return classNames;
}

// Add form validation to submit buttons
document.addEventListener('DOMContentLoaded', function() {
    // Find forms by their submit button names
    const activityForm = document.querySelector('button[name="add_activity"]').closest('form');
    const examForm = document.querySelector('button[name="add_exam"]').closest('form');
    
    if (activityForm) {
        activityForm.addEventListener('submit', function(e) {
            // Collect currently visible checks as well
            const currentChecks = activityForm.querySelectorAll('#activity_subjects_container input[name="selected_subjects[]"]');
            currentChecks.forEach(cb => {
                const value = cb.value;
                if (cb.checked) {
                    window.selectedAssignments.activity.add(value);
                } else {
                    window.selectedAssignments.activity.delete(value);
                }
            });
            
            // Always sync hidden inputs first, regardless of validation
            syncSelectedHiddenInputs(activityForm, 'activity');
            
            // Ensure the add_activity button name is included in form data
            const addActivityInput = document.createElement('input');
            addActivityInput.type = 'hidden';
            addActivityInput.name = 'add_activity';
            addActivityInput.value = '1';
            activityForm.appendChild(addActivityInput);
            
            if (validateForm('activity')) {
                e.preventDefault(); // Prevent default submit
                showAssignmentModal('activity').then(confirmed => {
                    if (confirmed) {
                        showLoading('add_activity_btn');
                        activityForm.submit();
                    } else {
                        // Take user back to class selection to complete
                        const backWrap = document.getElementById('activity_back_wrapper');
                        if (backWrap && backWrap.classList.contains('d-none')) {
                            const backBtn = document.getElementById('activity_back_btn');
                            if (backBtn) backBtn.click();
                        }
                        const tab = document.querySelector('#tab-activity');
                        if (tab) { tab.classList.add('show','active'); tab.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                    }
                });
            } else {
                e.preventDefault();
                // Take user back to class selection to complete
                const backWrap = document.getElementById('activity_back_wrapper');
                if (backWrap && backWrap.classList.contains('d-none')) {
                    const backBtn = document.getElementById('activity_back_btn');
                    if (backBtn) backBtn.click();
                }
                const tab = document.querySelector('#tab-activity');
                if (tab) { tab.classList.add('show','active'); tab.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
        });
    }
    
    if (examForm) {
        examForm.addEventListener('submit', function(e) {
            const currentChecks = examForm.querySelectorAll('#exam_subjects_container input[name="selected_subjects[]"]');
            currentChecks.forEach(cb => {
                const value = cb.value;
                if (cb.checked) {
                    window.selectedAssignments.exam.add(value);
                } else {
                    window.selectedAssignments.exam.delete(value);
                }
            });
            
            // Always sync hidden inputs first, regardless of validation
            syncSelectedHiddenInputs(examForm, 'exam');
            
            // Ensure the add_exam button name is included in form data
            const addExamInput = document.createElement('input');
            addExamInput.type = 'hidden';
            addExamInput.name = 'add_exam';
            addExamInput.value = '1';
            examForm.appendChild(addExamInput);
            
            if (validateForm('exam')) {
                e.preventDefault(); // Prevent default submit
                showAssignmentModal('exam').then(confirmed => {
                    if (confirmed) {
                        showLoading('add_exam_btn');
                        examForm.submit();
                    } else {
                        const backWrap = document.getElementById('exam_back_wrapper');
                        if (backWrap && backWrap.classList.contains('d-none')) {
                            const backBtn = document.getElementById('exam_back_btn');
                            if (backBtn) backBtn.click();
                        }
                        const tab = document.querySelector('#tab-exam');
                        if (tab) { tab.classList.add('show','active'); tab.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                    }
                });
            } else {
                e.preventDefault();
                const backWrap = document.getElementById('exam_back_wrapper');
                if (backWrap && backWrap.classList.contains('d-none')) {
                    const backBtn = document.getElementById('exam_back_btn');
                    if (backBtn) backBtn.click();
                }
                const tab = document.querySelector('#tab-exam');
                if (tab) { tab.classList.add('show','active'); tab.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            }
        });
    }
});
</script>

<style>
/* Enhanced styling for better UI */
.hover-shadow {
    transition: all 0.3s ease;
    cursor: pointer;
    border: 1px solid #e9ecef;
}

.hover-shadow:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    border-color: #0d6efd;
}

/* Header section cards */
.section-card {
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
    border-color: #e9ecef;
}
.section-card.hovered,
.section-card:hover {
    background-color: #e9f7ef; /* light green hover */
    transform: translateY(-2px);
    box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,0.08) !important;
}
.section-card.active {
    background-color: #d4f3df; /* selected */
    border-color: #81d2a5;
}

/* Class cards */
.class-card {
    cursor: pointer;
    transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}
.class-card:hover { background-color: #e9f7ef; }
.class-card.active { background-color: #d4f3df; border-color: #81d2a5; }

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.form-check-input:indeterminate {
    background-color: #6c757d;
    border-color: #6c757d;
}

.card {
    border-radius: 0.75rem;
    border: none;
}

.shadow-lg {
    box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175) !important;
}

.bg-gradient {
    background: linear-gradient(135deg, var(--bs-primary) 0%, var(--bs-primary-dark) 100%) !important;
}

.bg-gradient.bg-success {
    background: linear-gradient(135deg, #198754 0%, #146c43 100%) !important;
}

.bg-gradient.bg-warning {
    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%) !important;
}

.bg-gradient.bg-info {
    background: linear-gradient(135deg, #0dcaf0 0%, #0aa2c0 100%) !important;
}

.border-primary {
    border-color: #0d6efd !important;
}

.border-warning {
    border-color: #ffc107 !important;
}

.text-primary {
    color: #0d6efd !important;
}

.text-warning {
    color: #ffc107 !important;
}

.bg-light {
    background-color: #f8f9fa !important;
}

.shadow-sm {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}

/* Form enhancements */
.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

.btn {
    border-radius: 0.5rem;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

/* Table enhancements */
.table-hover tbody tr:hover {
    background-color: rgba(13, 110, 253, 0.05);
}

.table th {
    border-top: none;
    font-weight: 600;
    color: #495057;
}

/* Badge enhancements */
.badge {
    font-size: 0.75em;
    padding: 0.5em 0.75em;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
    
    .row.g-2 {
        --bs-gutter-x: 0.5rem;
        --bs-gutter-y: 0.5rem;
    }
}

/* Loading animation */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Success/Error states */
.form-check-input.is-valid {
    border-color: #198754;
}

.form-check-input.is-invalid {
    border-color: #dc3545;
}

/* Custom scrollbar */
.card-body::-webkit-scrollbar {
    width: 6px;
}

.card-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.card-body::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.card-body::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>
</body>
</html>