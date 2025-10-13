 <?php
session_start();

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$school_id = $_SESSION['school_id'];
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Generate full range of comment templates
    if (isset($_POST['generate_full_range'])) {
        $comment_type = trim($_POST['comment_type']);
        $is_class_specific = isset($_POST['is_class_specific']) && $_POST['is_class_specific'] == '1';
        $class_id = $is_class_specific ? intval($_POST['class_id']) : null;
        
        // Define the ranges and comments
        $ranges = [
            ['min' => 0, 'max' => 40, 'comment' => 'Demonstrates below the basic level of competency by applying the acquired knowledge and skills in real life situation'],
            ['min' => 40, 'max' => 60, 'comment' => 'Demonstrates minimum level of competency by applying the acquired knowledge and skills in real life situation'],
            ['min' => 60, 'max' => 70, 'comment' => 'Demonstrates adequate of competency by applying the acquired knowledge and skills in real life situation'],
            ['min' => 70, 'max' => 80, 'comment' => 'Demonstrates high level of competency by applying the acquired knowledge and skills in real life situation'],
            ['min' => 80, 'max' => 100, 'comment' => 'Demonstrates extraodinary level of competency by applying innovatively and creatively the acquired knowledge and skills']
        ];
        
        // First, delete existing templates for this configuration to avoid conflicts
        try {
            if ($is_class_specific) {
                $delete_stmt = $conn->prepare("DELETE FROM class_comment_templates WHERE school_id = ? AND type = ? AND class_id = ?");
                $delete_stmt->bind_param("isi", $school_id, $comment_type, $class_id);
            } else {
                $delete_stmt = $conn->prepare("DELETE FROM class_comment_templates WHERE school_id = ? AND type = ? AND class_id IS NULL");
                $delete_stmt->bind_param("is", $school_id, $comment_type);
            }
            $delete_stmt->execute();
            
            // Insert new ranges
            $success_count = 0;
            foreach ($ranges as $range) {
                $insert_stmt = $conn->prepare("INSERT INTO class_comment_templates 
                    (school_id, class_id, type, min_score, max_score, comment) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                
                if ($is_class_specific) {
                    $insert_stmt->bind_param("iisdds", $school_id, $class_id, $comment_type, $range['min'], $range['max'], $range['comment']);
                } else {
                    $null_class_id = null;
                    $insert_stmt->bind_param("iisdds", $school_id, $null_class_id, $comment_type, $range['min'], $range['max'], $range['comment']);
                }
                
                if ($insert_stmt->execute()) {
                    $success_count++;
                }
            }
            
            if ($success_count == count($ranges)) {
                $message = "Successfully generated " . $success_count . " comment templates covering the full score range (0-100).";
            } else {
                $error = "Only " . $success_count . " out of " . count($ranges) . " templates were created.";
            }
            
        } catch (Exception $e) {
            $error = "Error generating comment templates: " . $e->getMessage();
        }
    }
    
    // Handle other existing form submissions (from the original code)
    if (isset($_POST['add_grade'])) {
        $grade = trim($_POST['grade']);
        $min_score = intval($_POST['min_score']);
        $max_score = intval($_POST['max_score']);
        $remarks = trim($_POST['remarks']);
        
        // Basic validation
        if ($min_score >= $max_score) {
            $error = "Error: Minimum score must be less than maximum score";
        } 
        // Validate score ranges
        else if ($min_score < 0 || $max_score > 100) {
            $error = "Error: Scores must be between 0 and 100";
        }
        else {
            // Enhanced overlap checking query
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM grading_scales 
                WHERE school_id = ? AND 
                (
                    (? >= min_score AND ? < max_score) OR  /* New min_score falls within existing range */
                    (? > min_score AND ? <= max_score) OR  /* New max_score falls within existing range */
                    (? <= min_score AND ? >= max_score)    /* New range completely contains an existing range */
                )");
            
            $stmt->bind_param("iiiiiii", 
                $school_id, 
                $min_score, $min_score,  // For first condition
                $max_score, $max_score,  // For second condition
                $min_score, $max_score   // For third condition
            );
            
            $stmt->execute();
            $result = $stmt->get_result();
            $overlap = $result->fetch_assoc()['count'];
            
            if ($overlap > 0) {
                $error = "Error: This grade range overlaps with an existing grade range. Please check the ranges and try again.";
            } else {
                $stmt = $conn->prepare("INSERT INTO grading_scales (school_id, grade, min_score, max_score, remarks) 
                                       VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("isids", $school_id, $grade, $min_score, $max_score, $remarks);
                
                if ($stmt->execute()) {
                    $message = "Grade scale added successfully";
                } else {
                    $error = "Error adding grade scale: " . $conn->error;
                }
            }
        }
    }
    
    if (isset($_POST['add_comment'])) {
        $type = $_POST['comment_type'];
        $min_score = floatval($_POST['comment_min_score']);
        $max_score = floatval($_POST['comment_max_score']);
        $comment = trim($_POST['comment_text']);
        $is_class_specific = isset($_POST['is_class_specific']) && $_POST['is_class_specific'] == '1';
        $class_id = $is_class_specific ? intval($_POST['class_id']) : null;
        
        if ($min_score >= $max_score) {
            $error = "Error: Minimum score must be less than maximum score";
        } else if ($min_score < 0 || $max_score > 101) {
            $error = "Error: Scores must be between 0 and 101";
        } else if ($is_class_specific && empty($class_id)) {
            $error = "Error: Please select a class for class-specific comment";
        } else {
            try {
                // Check for overlapping score ranges with proper boundary handling
                $overlap_sql = "SELECT COUNT(*) as count FROM class_comment_templates 
                    WHERE school_id = ? AND type = ? AND 
                    " . ($is_class_specific ? "class_id = ?" : "class_id IS NULL") . " AND 
                    (
                        (? >= min_score AND ? < max_score) OR  /* New min_score falls within existing range */
                        (? > min_score AND ? <= max_score) OR  /* New max_score falls within existing range */
                        (? <= min_score AND ? >= max_score)    /* New range completely contains an existing range */
                    )";

                if ($is_class_specific) {
                    $stmt = $conn->prepare($overlap_sql);
                    $stmt->bind_param("isidddddd", 
                        $school_id, 
                        $type, 
                        $class_id, 
                        $min_score, $min_score,  // For first condition
                        $max_score, $max_score,  // For second condition
                        $min_score, $max_score   // For third condition
                    );
                } else {
                    $stmt = $conn->prepare($overlap_sql);
                    $stmt->bind_param("isdddddd", 
                        $school_id, 
                        $type, 
                        $min_score, $min_score,  // For first condition
                        $max_score, $max_score,  // For second condition
                        $min_score, $max_score   // For third condition
                    );
                }
                
                $stmt->execute();
                $overlap = $stmt->get_result()->fetch_assoc()['count'];
                
                if ($overlap > 0) {
                    $error = "Error: This score range overlaps with an existing template";
                } else {
                    // Insert into class_comment_templates (using NULL for class_id for default templates)
                    $stmt = $conn->prepare("INSERT INTO class_comment_templates 
                        (school_id, class_id, type, min_score, max_score, comment) 
                        VALUES (?, ?, ?, ?, ?, ?)");
                    if ($is_class_specific) {
                        $stmt->bind_param("iisdds", $school_id, $class_id, $type, $min_score, $max_score, $comment);
                    } else {
                        $null_class_id = null;
                        $stmt->bind_param("iisdds", $school_id, $null_class_id, $type, $min_score, $max_score, $comment);
                    }
                    
                    if ($stmt->execute()) {
                        $message = "Comment template added successfully";
                    } else {
                        throw new Exception($stmt->error);
                    }
                }
            } catch (Exception $e) {
                $error = "Error adding comment template: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_grade'])) {
        $grade_id = intval($_POST['grade_id']);
        $stmt = $conn->prepare("DELETE FROM grading_scales WHERE id = ? AND school_id = ?");
        $stmt->bind_param("ii", $grade_id, $school_id);
        
        if ($stmt->execute()) {
            $message = "Grade scale deleted successfully";
        } else {
            $error = "Error deleting grade scale";
        }
    }
    
    if (isset($_POST['delete_comment'])) {
        $comment_id = intval($_POST['comment_id']);
        try {
            $stmt = $conn->prepare("DELETE FROM class_comment_templates WHERE id = ? AND school_id = ?");
            $stmt->bind_param("ii", $comment_id, $school_id);
            
            if ($stmt->execute()) {
                $message = "Comment template deleted successfully";
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            $error = "Error deleting comment template: " . $e->getMessage();
        }
    }
}

// Fetch existing grade scales
$grade_scales = [];
$stmt = $conn->prepare("SELECT * FROM grading_scales WHERE school_id = ? ORDER BY max_score DESC");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $grade_scales[] = $row;
}

// Fetch available classes for the school
$classes = [];
$stmt = $conn->prepare("SELECT id, name FROM classes WHERE school_id = ? ORDER BY name");
$stmt->bind_param("i", $school_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}

// Fetch all comment templates (both default and class-specific)
$comment_templates = [];
$templates_sql = "SELECT 
    cct.id,
    CASE WHEN cct.class_id IS NULL THEN 'default' ELSE 'class_specific' END as template_type,
    cct.school_id,
    cct.class_id,
    c.name as class_name,
    cct.type,
    cct.min_score,
    cct.max_score,
    cct.comment,
    cct.created_at,
    cct.updated_at
    FROM class_comment_templates cct
    LEFT JOIN classes c ON cct.class_id = c.id
    WHERE cct.school_id = ?
    ORDER BY cct.class_id IS NULL DESC, c.name, cct.type, cct.max_score DESC";

try {
    $stmt = $conn->prepare($templates_sql);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $comment_templates[] = $row;
    }
} catch (Exception $e) {
    $error = "Error fetching comment templates: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grading System Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4bb543;
            --danger-color: #dc3545;
            --warning-color: #fca311;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.5rem;
            font-size: 1.25rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .form-control {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.25);
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border: none;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            border: none;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border: none;
        }
        
        .table {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.5px;
        }
        
        .table td, .table th {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        .action-icons {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }
        
        .icon-button {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .icon-button:hover {
            transform: scale(1.1);
        }
        
        .info-icon {
            color: var(--warning-color);
            margin-right: 0.5rem;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .badge {
            padding: 0.5em 0.75em;
            font-weight: 500;
            font-size: 0.875rem;
        }
        
        .text-muted {
            color: #6c757d !important;
            font-style: italic;
        }
        
        .full-range-generator {
            background-color: #f0f8ff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="mt-4">
            <a href="school_admin_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Grade Scales Management -->
        <div class="card mb-4 mt-4">
            <div class="card-header">
                <div class="section-title">
                    <i class="fas fa-graduation-cap"></i>
                    Grade Scales Management
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle info-icon"></i>
                    Note: Grade ranges use inclusive minimum (>=) and exclusive maximum (<) boundaries.
                    For example, 50-60 means scores from 50 up to but not including 60.
                </div>
                
                <form method="post" class="mb-4 row g-3">
                    <div class="col-md-2">
                        <input type="text" name="grade" class="form-control" placeholder="Grade (e.g., A+)" required>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="min_score" class="form-control" placeholder="Min Score (>=)" required min="0" step="1">
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="max_score" class="form-control" placeholder="Max Score (<)" required min="0" step="1">
                    </div>
                    <div class="col-md-4">
                        <select name="remarks" class="form-control" required>
                            <option value="">Select Remarks</option>
                            <option value="Exceptional">Exceptional</option>
                            <option value="Outstanding">Outstanding</option>
                            <option value="Satisfactory">Satisfactory</option>
                            <option value="Basic">Basic</option>
                            <option value="Elementary">Elementary</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add_grade" class="btn btn-primary w-100">
                            <i class="fas fa-plus me-2"></i>Add Grade Scale
                        </button>
                    </div>
                </form>
                
                <!-- Grade Scales Table -->
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><i class="fas fa-star me-2"></i>Grade</th>
                            <th><i class="fas fa-chart-bar me-2"></i>Range</th>
                            <th><i class="fas fa-comment me-2"></i>Remarks</th>
                            <th><i class="fas fa-cog me-2"></i>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grade_scales as $scale): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($scale['grade']); ?></td>
                                <td><?php echo $scale['min_score']; ?> - <?php echo $scale['max_score']; ?></td>
                                <td><?php echo htmlspecialchars($scale['remarks']); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="grade_id" value="<?php echo $scale['id']; ?>">
                                        <button type="submit" name="delete_grade" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Comment Templates Management -->
        <div class="card">
            <div class="card-header">
                <div class="section-title">
                    <i class="fas fa-comments"></i>
                    Comment Templates Management
                </div>
            </div>
            <div class="card-body">               
                <!-- Manual Comment Template Form -->
                <h5 class="mt-4 mb-3"><i class="fas fa-edit me-2"></i>Add Single Comment Template</h5>
                <form method="post" class="mb-4 row g-3">
                    <div class="col-md-2">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="is_class_specific" name="is_class_specific" value="1">
                            <label class="form-check-label" for="is_class_specific">
                                Class Specific
                            </label>
                        </div>
                        <select name="class_id" id="class_select" class="form-control" disabled>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="comment_type" class="form-control" required>
                            <option value="class_teacher">Class Teacher</option>
                            <option value="head_teacher">Head Teacher</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="comment_min_score" class="form-control" placeholder="Min Score (>=)" required min="0" step="1">
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="comment_max_score" class="form-control" placeholder="Max Score (<)" required min="0" step="1">
                    </div>
                    <div class="col-md-4">
                        <textarea name="comment_text" class="form-control" placeholder="Comment Template" required></textarea>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="add_comment" class="btn btn-primary w-100">
                            <i class="fas fa-plus me-2"></i>Add Comment
                        </button>
                    </div>
                </form>
                
                <!-- Comment Templates Table -->
                <h5 class="mt-4 mb-3"><i class="fas fa-list me-2"></i>Existing Comment Templates</h5>
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><i class="fas fa-layer-group me-2"></i>Type</th>
                            <th><i class="fas fa-graduation-cap me-2"></i>Class</th>
                            <th><i class="fas fa-user-tie me-2"></i>Comment Type</th>
                            <th><i class="fas fa-chart-line me-2"></i>Score Range</th>
                            <th><i class="fas fa-comment-dots me-2"></i>Comment</th>
                            <th><i class="fas fa-cog me-2"></i>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comment_templates as $template): ?>
                            <tr>
                                <td>
                                    <?php 
                                    echo $template['template_type'] === 'default' 
                                        ? '<span class="badge bg-primary">Default</span>' 
                                        : '<span class="badge bg-info">Class Specific</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php if ($template['class_name']): ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($template['class_name']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">All Classes</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo str_replace('_', ' ', ucfirst($template['type'])); ?></td>
                                <td><?php echo number_format($template['min_score'], 2); ?> - <?php echo number_format($template['max_score'], 2); ?></td>
                                <td><?php echo htmlspecialchars($template['comment']); ?></td>
                                <td>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="comment_id" value="<?php echo $template['id']; ?>">
                                        <input type="hidden" name="template_type" value="<?php echo $template['template_type']; ?>">
                                        <button type="submit" name="delete_comment" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
    // Script for manual comment template form
    document.getElementById('is_class_specific').addEventListener('change', function() {
        const classSelect = document.getElementById('class_select');
        classSelect.disabled = !this.checked;
        if (!this.checked) {
            classSelect.value = '';
        }
    });
    
    // Script for generator form
    document.getElementById('generate_is_class_specific').addEventListener('change', function() {
        const classSelect = document.getElementById('generate_class_select');
        classSelect.disabled = !this.checked;
        if (!this.checked) {
            classSelect.value = '';
        }
    });
    </script>
</body>
</html