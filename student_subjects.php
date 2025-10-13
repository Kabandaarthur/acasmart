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

// Get the admin's school_id
$admin_id = $_SESSION['user_id'];
$school_query = "SELECT school_id FROM users WHERE user_id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin_data = $result->fetch_assoc();
$school_id = $admin_data['school_id'];
$stmt->close();

// Get current term
$term_query = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt = $conn->prepare($term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$current_term = $term_result->fetch_assoc();
$current_term_id = $current_term ? $current_term['id'] : null;
$stmt->close();

// Handle AJAX form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_students'])) {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $subject_id = $_POST['subject_id'];
        $student_ids = isset($_POST['student_ids']) ? $_POST['student_ids'] : [];
        
        // Start transaction
        $conn->begin_transaction();
        
        // Get currently active students (including legacy assignments without status)
        $current_query = "SELECT student_id FROM student_subjects 
                         WHERE subject_id = ? 
                         AND (status = 'active' OR status IS NULL OR status = '')";
        $stmt = $conn->prepare($current_query);
        $stmt->bind_param("i", $subject_id);
        $stmt->execute();
        $current_result = $stmt->get_result();
        $current_students = $current_result->fetch_all(MYSQLI_ASSOC);
        $current_student_ids = array_column($current_students, 'student_id');
        $stmt->close();
        
        // Mark removed students as inactive
        $students_to_remove = array_diff($current_student_ids, $student_ids);
        if (!empty($students_to_remove)) {
            $update_query = "UPDATE student_subjects 
                           SET status = 'removed', 
                               removal_date = CURRENT_TIMESTAMP 
                           WHERE subject_id = ? 
                           AND student_id = ? 
                           AND (status = 'active' OR status IS NULL OR status = '')";
            $stmt = $conn->prepare($update_query);
            foreach ($students_to_remove as $student_id) {
                $stmt->bind_param("ii", $subject_id, $student_id);
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Handle students to add or reactivate
        $students_to_add = array_diff($student_ids, $current_student_ids);
        if (!empty($students_to_add)) {
            // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both new and existing assignments
            $insert_query = "INSERT INTO student_subjects 
                           (student_id, subject_id, term_id, status, assignment_date) 
                           VALUES (?, ?, ?, 'active', CURRENT_TIMESTAMP)
                           ON DUPLICATE KEY UPDATE 
                           status = 'active', 
                           assignment_date = CURRENT_TIMESTAMP,
                           removal_date = NULL";
            $stmt = $conn->prepare($insert_query);
            foreach ($students_to_add as $student_id) {
                $stmt->bind_param("iii", $student_id, $subject_id, $current_term_id);
                $stmt->execute();
            }
            $stmt->close();
        }
        
        $conn->commit();
        $response['success'] = true;
        $response['message'] = "Students assigned successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = "Error: " . $e->getMessage();
    }
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Fetch all classes for this school
$classes_query = "SELECT id, name FROM classes WHERE school_id = ?";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all subjects for this school
$subjects_query = "SELECT s.subject_id, s.subject_name, s.class_id, c.name AS class_name
                   FROM subjects s
                   JOIN classes c ON s.class_id = c.id
                   WHERE s.school_id = ?";
$stmt = $conn->prepare($subjects_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = $subjects_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Student Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
        }

        body {
            background-color: #f8f9fc;
            font-family: 'Nunito', sans-serif;
        }

        .container {
            background-color: #ffffff;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 1.5rem;
            margin-top: 2rem;
            margin-bottom: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
            border-bottom: 1px solid #e3e6f0;
            padding-bottom: 1rem;
        }

        .page-header h1 {
            color: var(--primary-color);
            font-size: 1.75rem;
            font-weight: 700;
        }

        .form-label {
            color: var(--secondary-color);
            font-weight: 600;
        }

        .form-select, .form-control {
            border-radius: 0.35rem;
            border: 1px solid #d1d3e2;
            padding: 0.375rem 0.75rem;
            font-size: 1rem;
        }

        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }

        .btn {
            border-radius: 0.35rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-info {
            background-color: var(--info-color);
            border-color: var(--info-color);
            color: #fff;
        }

        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .students-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e3e6f0;
            border-radius: 0.35rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .student-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
            transition: background-color 0.2s ease;
        }

        .student-item:last-child {
            border-bottom: none;
        }

        .student-item:hover {
            background-color: #f8f9fc;
        }

        .student-item .form-check-label {
            width: 100%;
            cursor: pointer;
        }

        .badge {
            font-size: 0.75rem;
            transition: all 0.3s ease;
        }

        .badge.bg-success {
            background-color: var(--success-color) !important;
        }

        .badge.bg-secondary {
            background-color: var(--secondary-color) !important;
        }

        .alert {
            position: fixed;
            top: 20px;
            right: 20px;
            min-width: 300px;
            z-index: 1050;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        .modal-content {
            border-radius: 0.35rem;
            border: none;
        }

        .modal-header {
            background-color: var(--primary-color);
            color: #ffffff;
            border-top-left-radius: 0.35rem;
            border-top-right-radius: 0.35rem;
        }

        .modal-title {
            font-weight: 600;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            background-color: #f8f9fc;
            color: var(--secondary-color);
            font-weight: 600;
        }

        .back-button {
            color: var(--secondary-color);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-button:hover {
            color: var(--primary-color);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .loading-spinner {
            display: none;
            margin: 2rem auto;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <a href="school_admin_dashboard.php" class="back-button">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <h1 class="text-center mt-3">Subject Student Management</h1>
        </div>

        <div id="alertMessage" class="alert" role="alert"></div>

        <form id="assignStudentsForm" method="POST">
            <div class="mb-3">
                <label for="class_id" class="form-label">Select Class</label>
                <select name="class_id" id="class_id" class="form-select" required>
                    <option value="">Choose a class...</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>">
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="subject_id" class="form-label">Select Subject</label>
                <select name="subject_id" id="subject_id" class="form-select" required disabled>
                    <option value="">Choose a subject...</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['subject_id']; ?>" data-class="<?php echo $subject['class_id']; ?>">
                            <?php echo htmlspecialchars($subject['subject_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Manage Students</label>
                <div class="action-buttons">
                    <button type="button" id="selectAll" class="btn btn-secondary" disabled>
                        <i class="bi bi-check-all"></i> Select All
                    </button>
                    <button type="button" id="deselectAll" class="btn btn-secondary" disabled>
                        <i class="bi bi-x-lg"></i> Deselect All
                    </button>
                    <button type="button" id="viewAssigned" class="btn btn-info">
                        <i class="bi bi-eye"></i> View Assigned Students
                    </button>
                    <button type="button" id="downloadList" class="btn btn-success" disabled>
                        <i class="bi bi-download"></i> Download Student List
                    </button>
                    <button type="button" id="downloadRecordSheet" class="btn btn-warning" disabled>
                        <i class="bi bi-file-earmark-text"></i> Download Record Sheet
                    </button>
                </div>
                <div class="students-container mt-3" id="studentsContainer">
                    <!-- Students will be loaded here dynamically -->
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" name="assign_students" class="btn btn-primary">
                    <i class="bi bi-save"></i> Save Assignments
                </button>
            </div>
        </form>

        <div id="loadingSpinner" class="loading-spinner">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>

    <!-- Modal for viewing assigned students -->
    <div class="modal fade" id="assignedStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-people"></i> Assigned Students
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="assignedStudentsList">
                        <!-- Assigned students will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmRemoveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle"></i> Confirm Removal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to remove <span id="studentNameToRemove"></span> from this subject?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRemove">
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            function showAlert(message, type) {
                const alert = $('#alertMessage');
                alert.removeClass().addClass(`alert alert-${type}`);
                alert.html(message);
                alert.fadeIn();
                setTimeout(() => alert.fadeOut(), 3000);
            }

            function showLoading() {
                $('#loadingSpinner').show();
            }

            function hideLoading() {
                $('#loadingSpinner').hide();
            }

            $('#class_id').change(function() {
                var classId = $(this).val();
                if (classId) {
                    $('#subject_id option').each(function() {
                        if ($(this).data('class') == classId || $(this).val() == "") {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                    $('#subject_id').prop('disabled', false);
                    
                    showLoading();
                    $.ajax({
                        url: 'get_students.php',
                        type: 'POST',
                        data: {
                            class_id: classId,
                            subject_id: $('#subject_id').val() || null
                        },
                        success: function(response) {
                            $('#studentsContainer').html(response);
                            $('#selectAll, #deselectAll').prop('disabled', false);
                        },
                        complete: function() {
                            hideLoading();
                        }
                    });
                } else {
                    $('#subject_id').prop('disabled', true);
                    $('#studentsContainer').empty();
                    $('#selectAll, #deselectAll').prop('disabled', true);
                }
            });

            // Add event listener for subject change to reload students with correct pre-checked status
            $('#subject_id').change(function() {
                var classId = $('#class_id').val();
                var subjectId = $(this).val();
                if (classId && subjectId) {
                    showLoading();
                    $.ajax({
                        url: 'get_students.php',
                        type: 'POST',
                        data: {
                            class_id: classId,
                            subject_id: subjectId
                        },
                        success: function(response) {
                            $('#studentsContainer').html(response);
                            $('#selectAll, #deselectAll').prop('disabled', false);
                        },
                        complete: function() {
                            hideLoading();
                        }
                    });
                }
            });

            $('#selectAll').click(function() {
                $('.student-checkbox:visible').prop('checked', true);
            });

            $('#deselectAll').click(function() {
                $('.student-checkbox:visible').prop('checked', false);
            });

            $('#viewAssigned').click(function() {
                const subjectId = $('#subject_id').val();
                const classId = $('#class_id').val();
                if (!subjectId || !classId) {
                    showAlert('Please select both class and subject first', 'warning');
                    return;
                }
                
                showLoading();
                $.ajax({
                   url: 'get_assigned_students.php',
                    type: 'POST',
                    data: { subject_id: subjectId, class_id: classId },
                    success: function(response) {
                        $('#assignedStudentsList').html(response);
                        $('#assignedStudentsModal').modal('show');
                    },
                    error: function() {
                        showAlert('Error loading assigned students', 'danger');
                    },
                    complete: function() {
                        hideLoading();
                    }
                });
            });
            $('#downloadList').click(function() {
    const subjectId = $('#subject_id').val();
    const classId = $('#class_id').val();
    if (!subjectId || !classId) {
        showAlert('Please select both class and subject first', 'warning');
        return;
    }
    window.location.href = `download_students.php?subject_id=${subjectId}&class_id=${classId}`;
});

$('#downloadRecordSheet').click(function() {
    const subjectId = $('#subject_id').val();
    const classId = $('#class_id').val();
    if (!subjectId || !classId) {
        showAlert('Please select both class and subject first', 'warning');
        return;
    }
    window.location.href = `download_class_record_sheet.php?class_id=${classId}&subject_id=${subjectId}`;
});

// Enable/disable download buttons when subject is selected
$('#subject_id').change(function() {
    const isEnabled = $(this).val() && $('#class_id').val();
    $('#downloadList, #downloadRecordSheet').prop('disabled', !isEnabled);
});

$('#class_id').change(function() {
    const isEnabled = $(this).val() && $('#subject_id').val();
    $('#downloadList, #downloadRecordSheet').prop('disabled', !isEnabled);
});

            // Replace the remove-student click handler with this:
            $(document).on('click', '.remove-student', function() {
                const studentId = $(this).data('student-id');
                const subjectId = $('#subject_id').val();
                const studentName = $(this).closest('tr').find('td:first').text();
                
                // Store the data for use in confirmation
                $('#confirmRemove').data('student-id', studentId);
                $('#confirmRemove').data('subject-id', subjectId);
                $('#studentNameToRemove').text(studentName);
                
                // Show the confirmation modal
                $('#confirmRemoveModal').modal('show');
            });

            // Add confirmation handler
            $('#confirmRemove').on('click', function() {
                const studentId = $(this).data('student-id');
                const subjectId = $(this).data('subject-id');
                
                showLoading();
                $.ajax({
                    url: 'remove_student.php',
                    type: 'POST',
                    data: {
                        student_id: studentId,
                        subject_id: subjectId
                    },
                    success: function(response) {
                        if (response.success) {
                            showAlert('Student removed successfully', 'success');
                            // Refresh the student list to show updated status badges
                            const classId = $('#class_id').val();
                            const subjectId = $('#subject_id').val();
                            if (classId && subjectId) {
                                // Show a brief loading state
                                $('#studentsContainer').html('<div class="text-center p-3"><div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Updating...</span></div> <span class="ms-2">Updating status...</span></div>');
                                
                                $.ajax({
                                    url: 'get_students.php',
                                    type: 'POST',
                                    data: {
                                        class_id: classId,
                                        subject_id: subjectId
                                    },
                                    success: function(studentResponse) {
                                        $('#studentsContainer').html(studentResponse);
                                    }
                                });
                            }
                            // Also refresh the assigned students list
                            $('#viewAssigned').click();
                        } else {
                            showAlert(response.message || 'Error removing student', 'danger');
                        }
                    },
                    error: function() {
                        showAlert('Error removing student', 'danger');
                    },
                    complete: function() {
                        hideLoading();
                        $('#confirmRemoveModal').modal('hide');
                    }
                });
            });

            // Handle form submission
            $('#assignStudentsForm').on('submit', function(e) {
                e.preventDefault();
                
                const subjectId = $('#subject_id').val();
                if (!subjectId) {
                    showAlert('Please select a subject', 'warning');
                    return;
                }

                // Get selected students
                var studentIds = [];
                $('.student-checkbox:checked').each(function() {
                    studentIds.push($(this).val());
                });

                showLoading();
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        assign_students: true,
                        subject_id: subjectId,
                        student_ids: studentIds
                    },
                    success: function(response) {
                        if (response.success) {
                            showAlert(response.message, 'success');
                            // Refresh the student list to show updated status badges
                            const classId = $('#class_id').val();
                            const subjectId = $('#subject_id').val();
                            if (classId && subjectId) {
                                // Show a brief loading state
                                $('#studentsContainer').html('<div class="text-center p-3"><div class="spinner-border spinner-border-sm text-primary" role="status"><span class="visually-hidden">Updating...</span></div> <span class="ms-2">Updating status...</span></div>');
                                
                                $.ajax({
                                    url: 'get_students.php',
                                    type: 'POST',
                                    data: {
                                        class_id: classId,
                                        subject_id: subjectId
                                    },
                                    success: function(studentResponse) {
                                        $('#studentsContainer').html(studentResponse);
                                    }
                                });
                            }
                            // Also refresh the assigned students view
                            $('#viewAssigned').click();
                        } else {
                            showAlert(response.message || 'An error occurred', 'danger');
                        }
                    },
                    error: function() {
                        showAlert('An error occurred while processing your request', 'danger');
                    },
                    complete: function() {
                        hideLoading();
                    }
                });
            });

            // Filter students when typing in search box
            $(document).on('input', '#studentSearch', function() {
                const searchTerm = $(this).val().toLowerCase();
                $('.student-item').each(function() {
                    const studentName = $(this).text().toLowerCase();
                    $(this).toggle(studentName.includes(searchTerm));
                });
            });

            // Optional: Add keyboard shortcuts
            $(document).keydown(function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'a':
                            if ($('#selectAll').prop('disabled') === false) {
                                e.preventDefault();
                                $('#selectAll').click();
                            }
                            break;
                        case 'd':
                            if ($('#deselectAll').prop('disabled') === false) {
                                e.preventDefault();
                                $('#deselectAll').click();
                            }
                            break;
                    }
                }
            });
        });
    </script>
</body>
</html