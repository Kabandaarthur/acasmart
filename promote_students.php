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

// Get admin's school_id
$admin_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Get all classes for the school
$stmt = $conn->prepare("SELECT id, name FROM classes WHERE school_id = ? ORDER BY name ASC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all streams for the school
$stmt = $conn->prepare("SELECT DISTINCT stream FROM students WHERE school_id = ? AND stream IS NOT NULL ORDER BY stream ASC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$streams_result = $stmt->get_result();
$streams = $streams_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle promotion submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {
    $selected_students = isset($_POST['selected_students']) ? $_POST['selected_students'] : [];
    $new_term_id = $_POST['new_term_id'];
    $class_filter = isset($_POST['class_filter']) ? $_POST['class_filter'] : '';
    $stream_filter = isset($_POST['stream_filter']) ? $_POST['stream_filter'] : '';
    $promotion_date = date('Y-m-d H:i:s');
    
    $promoted_count = 0;
    $errors = [];
    
    // Validate term
    $stmt = $conn->prepare("SELECT id, year FROM terms WHERE id = ? AND school_id = ? AND is_current = 1");
    $stmt->bind_param("ii", $new_term_id, $school_id);
    $stmt->execute();
    $term_result = $stmt->get_result();
    $new_term = $term_result->fetch_assoc();
    $stmt->close();

    if (!$new_term) {
        $_SESSION['error_message'] = "Invalid or inactive term selected.";
        header("Location: promote_students.php");
        exit();
    }

    // Build the filter conditions
    $filter_conditions = ["s.school_id = ?"]; 
    $filter_params = [$school_id];
    $param_types = "i";

    if ($class_filter) {
        $filter_conditions[] = "s.class_id = ?";
        $filter_params[] = $class_filter;
        $param_types .= "i";
    }

    if ($stream_filter) {
        $filter_conditions[] = "s.stream = ?";
        $filter_params[] = $stream_filter;
        $param_types .= "s";
    }

    foreach ($selected_students as $student_id) {
        // Get student's current information
        $stmt = $conn->prepare("SELECT s.*, c.id as current_class_id, c.name as current_class_name 
                               FROM students s 
                               JOIN classes c ON s.class_id = c.id 
                               WHERE s.id = ? AND s.school_id = ?");
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$student) {
            $errors[] = "Student ID $student_id not found or not assigned to this school.";
            continue;
        }

        // Get next class for promotion
        $stmt = $conn->prepare("SELECT id FROM classes WHERE school_id = ? AND id > ? ORDER BY id ASC LIMIT 1");
        $stmt->bind_param("ii", $school_id, $student['current_class_id']);
        $stmt->execute();
        $next_class = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$next_class) {
            $conn->begin_transaction();
            try {
                // Delete student as no next class exists
                $stmt = $conn->prepare("DELETE FROM students WHERE id = ? AND school_id = ?");
                $stmt->bind_param("ii", $student_id, $school_id);
                $stmt->execute();

                $conn->commit();
                $errors[] = "Student {$student['firstname']} {$student['lastname']} has been deleted as no next class was found.";
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Failed to delete {$student['firstname']} {$student['lastname']}: " . $e->getMessage();
            }
            continue;
        }

        // Begin promotion transaction
        $conn->begin_transaction();

        try {
            // Update student record
            $stmt = $conn->prepare("UPDATE students SET 
                                  class_id = ?, 
                                  current_term_id = ?, 
                                  last_promoted_term_id = ?,
                                  updated_at = ? 
                                  WHERE id = ?");
            $stmt->bind_param("iiisi", 
                $next_class['id'], 
                $new_term_id, 
                $new_term_id, 
                $promotion_date,
                $student_id
            );
            $stmt->execute();

            // Insert promotion record
            $stmt = $conn->prepare("INSERT INTO student_enrollments (
                                    student_id, 
                                    class_id, 
                                    term_id, 
                                    school_id,
                                    enrollment_type,
                                    previous_class_id,
                                    promotion_date,
                                    created_at
                                  ) VALUES (?, ?, ?, ?, 'PROMOTION', ?, ?, ?)");
            $stmt->bind_param("iiiiiss", 
                $student_id, 
                $next_class['id'], 
                $new_term_id, 
                $school_id,
                $student['current_class_id'],
                $promotion_date,
                $promotion_date
            );
            $stmt->execute();

            $conn->commit();
            $promoted_count++;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Failed to promote {$student['firstname']} {$student['lastname']}: " . $e->getMessage();
        }
    }

    if ($promoted_count > 0) {
        $_SESSION['success_message'] = "$promoted_count students successfully promoted.";
    }
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }

    header("Location: promote_students.php");
    exit();
}

// Get all active terms
$stmt = $conn->prepare("SELECT t.*, 
                       (SELECT name FROM terms WHERE id = t.previous_term_id) as previous_term_name 
                       FROM terms t 
                       WHERE t.school_id = ? AND t.is_current = 1 
                       ORDER BY t.year DESC, t.id DESC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get students eligible for promotion
$base_query = "SELECT s.*, 
               c.name as class_name,
               t.name as current_term_name,
               pt.name as last_promoted_term_name
               FROM students s 
               JOIN classes c ON s.class_id = c.id 
               LEFT JOIN terms t ON s.current_term_id = t.id
               LEFT JOIN terms pt ON s.last_promoted_term_id = pt.id
               WHERE s.school_id = ? ";

$filter_conditions = [];
$filter_params = [$school_id];
$param_types = "i";

// Apply any active filters from the session
if (isset($_SESSION['class_filter']) && $_SESSION['class_filter']) {
    $filter_conditions[] = "s.class_id = ?";
    $filter_params[] = $_SESSION['class_filter'];
    $param_types .= "i";
}

if (isset($_SESSION['stream_filter']) && $_SESSION['stream_filter']) {
    $filter_conditions[] = "s.stream = ?";
    $filter_params[] = $_SESSION['stream_filter'];
    $param_types .= "s";
}

if (!empty($filter_conditions)) {
    $base_query .= " AND " . implode(" AND ", $filter_conditions);
}

$base_query .= " ORDER BY c.id ASC, s.lastname ASC, s.firstname ASC";

$stmt = $conn->prepare($base_query);
$stmt->bind_param($param_types, ...$filter_params);
$stmt->execute();
$students_result = $stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Promotion Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .header-section {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .card {
            border: none;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 2px solid #eee;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .student-row:hover {
            background-color: #f8f9fa;
            transition: all 0.3s ease;
        }
        .btn-promote {
            background-color: #1e3c72;
            color: white;
        }
        .btn-promote:hover {
            background-color: #2a5298;
            color: white;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="loading-overlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <div class="header-section">
        <div class="container">
            <h1><i class="fas fa-user-graduate me-2"></i>Student Promotion Management</h1>
            <a href="school_admin_dashboard.php" class="btn btn-light">Back to Dashboard</a>
        </div>
    </div>

    <div class="container">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="card filter-section">
            <div class="card-body">
                <form id="filterForm" class="row g-3">
                    <div class="col-md-4">
                        <label for="class_filter" class="form-label">Select Class</label>
                        <select class="form-select" id="class_filter" name="class_filter">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['id']); ?>"
                                    <?php echo (isset($_SESSION['class_filter']) && $_SESSION['class_filter'] == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="stream_filter" class="form-label">Select Stream</label>
                        <select class="form-select" id="stream_filter" name="stream_filter">
                            <option value="">All Streams</option>
                            <?php foreach ($streams as $stream): ?>
                                <option value="<?php echo htmlspecialchars($stream['stream']); ?>"
                                    <?php echo (isset($_SESSION['stream_filter']) && $_SESSION['stream_filter'] == $stream['stream']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($stream['stream']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block w-100">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">Student List</h5>
                    </div>
                    <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="select-all">
                            <label class="form-check-label" for="select-all">Select All</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="promotionForm">
                    <!-- Hidden fields for filters -->
                    <input type="hidden" name="class_filter" id="hidden_class_filter">
                    <input type="hidden" name="stream_filter" id="hidden_stream_filter">
                    
                    <div class="mb-4">
                        <label for="new_term_id" class="form-label">Select Term to Promote To:</label>
                        <select name="new_term_id" id="new_term_id" required class="form-select">
                            <option value="">Select Term</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo htmlspecialchars($term['id']); ?>">
                                    <?php echo htmlspecialchars($term['name'] . ' - ' . $term['year']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th width="50"></th>
                                    <th>Name</th>
                                    <th>Current Class</th>
                                    <th>Stream</th>
                                    <th>Current Term</th>
                                    <th>Last Promoted Term</th>
                                </tr>
                            </thead>
                            <tbody id="studentsTableBody">
                                <?php foreach ($students as $student): ?>
                                    <tr class="student-row">
                                        <td>
                                            <input type="checkbox" class="form-check-input student-checkbox" 
                                                   name="selected_students[]" 
                                                   value="<?php echo htmlspecialchars($student['id']); ?>">
                                        </td>
                                        <td><?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?></td>
                                        <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['stream']); ?></td>
                                        <td><?php echo htmlspecialchars($student['current_term_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['last_promoted_term_name'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" name="promote_students" class="btn btn-promote btn-lg" id="promoteButton" disabled>
                            <i class="fas fa-arrow-up me-2"></i>Promote Selected Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Handle select all checkbox
            $('#select-all').change(function() {
                $('.student-checkbox').prop('checked', $(this).prop('checked'));
                updatePromoteButton();
            });

            // Handle individual checkboxes
            $(document).on('change', '.student-checkbox', function() {
                updatePromoteButton();
                
                // Update select all checkbox
                var allChecked = $('.student-checkbox:checked').length === $('.student-checkbox').length;
                $('#select-all').prop('checked', allChecked);
            });

            // Update promote button state
            function updatePromoteButton() {
                var checkedCount = $('.student-checkbox:checked').length;
                $('#promoteButton').prop('disabled', checkedCount === 0);
                
                // Update button text with count
                if (checkedCount > 0) {
                    $('#promoteButton').html(`<i class="fas fa-arrow-up me-2"></i>Promote ${checkedCount} Student${checkedCount === 1 ? '' : 's'}`);
                } else {
                    $('#promoteButton').html('<i class="fas fa-arrow-up me-2"></i>Promote Selected Students');
                }
            }

            // Handle filter form submission
            $('#filterForm').submit(function(e) {
                e.preventDefault();
                
                // Show loading overlay
                $('.loading-overlay').css('display', 'flex');

                // Store filter values in hidden fields
                $('#hidden_class_filter').val($('#class_filter').val());
                $('#hidden_stream_filter').val($('#stream_filter').val());

                $.ajax({
                    url: 'get_filtered_students.php',
                    data: {
                        class_id: $('#class_filter').val(),
                        stream: $('#stream_filter').val(),
                        school_id: <?php echo $school_id; ?>
                    },
                    method: 'POST',
                    success: function(response) {
                        $('#studentsTableBody').html(response);
                        updatePromoteButton();
                        // Reset select all checkbox
                        $('#select-all').prop('checked', false);
                    },
                    error: function() {
                        alert('Error filtering students. Please try again.');
                    },
                    complete: function() {
                        $('.loading-overlay').hide();
                    }
                });
            });

            // Form submission validation
            $('#promotionForm').submit(function(e) {
                if ($('.student-checkbox:checked').length === 0) {
                    e.preventDefault();
                    alert('Please select at least one student to promote.');
                    return false;
                }
                
                if (!$('#new_term_id').val()) {
                    e.preventDefault();
                    alert('Please select a term to promote to.');
                    return false;
                }
                
                return confirm('Are you sure you want to promote the selected students? This action cannot be undone.');
            });

            // Handle class filter change
            $('#class_filter').change(function() {
                var classId = $(this).val();
                if (classId) {
                    $('.loading-overlay').css('display', 'flex');
                    $.ajax({
                        url: 'get_streams.php',
                        data: { 
                            class_id: classId,
                            school_id: <?php echo $school_id; ?>
                        },
                        method: 'POST',
                        success: function(response) {
                            $('#stream_filter').html(response);
                        },
                        complete: function() {
                            $('.loading-overlay').hide();
                        }
                    });
                }
            });
        });
    </script>
</body>
</html