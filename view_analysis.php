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

// Get admin details
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

// Fetch current term
$current_term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term_result = $stmt->get_result();
$current_term = $current_term_result->fetch_assoc();
$active_term_id = $current_term['id'];
$stmt->close();

// Fetch classes
$classes_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY name";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get selected class ID from the request
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;

// Initialize class performance data
$class_performance = [];
if ($selected_class_id) {
    // Get all students in the class with their exam results
    $student_results_query = "
        WITH ExamScores AS (
            SELECT 
                er.student_id,
                s.subject_name,
                e.exam_type,
                e.category,
                er.score,
                e.max_score,
                e.term_id
            FROM student_subjects ss
            JOIN subjects s ON ss.subject_id = s.subject_id
            JOIN exam_results er ON er.subject_id = s.subject_id 
                AND er.student_id = ss.student_id
                AND er.school_id = ?
                AND er.term_id = ?
            JOIN exams e ON er.exam_id = e.exam_id
            WHERE s.class_id = ?
        ),
        ActivityScores AS (
            SELECT 
                student_id,
                subject_name,
                ROUND(
                    SUM(CASE 
                        WHEN score IS NOT NULL THEN (score / NULLIF(max_score, 0))
                        ELSE 0 
                    END) / COUNT(DISTINCT category) * 20,
                    1
                ) as activity_score
            FROM ExamScores
            WHERE exam_type = 'activity'
            GROUP BY student_id, subject_name
        ),
        ExamScoresCalc AS (
            SELECT 
                student_id,
                subject_name,
                ROUND(
                    SUM(CASE 
                        WHEN score IS NOT NULL THEN (score / NULLIF(max_score, 0))
                        ELSE 0 
                    END) / COUNT(DISTINCT category) * 80,
                    1
                ) as exam_score
            FROM ExamScores
            WHERE exam_type = 'exam'
            GROUP BY student_id, subject_name
        ),
        SubjectTotalScores AS (
            SELECT 
                COALESCE(a.student_id, e.student_id) as student_id,
                COALESCE(a.subject_name, e.subject_name) as subject_name,
                COALESCE(a.activity_score, 0) + COALESCE(e.exam_score, 0) as total_score
            FROM ActivityScores a
            LEFT JOIN ExamScoresCalc e ON a.student_id = e.student_id 
                AND a.subject_name = e.subject_name
            UNION ALL
            SELECT 
                e.student_id,
                e.subject_name,
                e.exam_score as total_score
            FROM ExamScoresCalc e
            LEFT JOIN ActivityScores a ON e.student_id = a.student_id 
                AND e.subject_name = a.subject_name
            WHERE a.student_id IS NULL
        ),
        StudentOverallScores AS (
            SELECT 
                s.id,
                s.firstname,
                s.lastname,
                s.admission_number,
                ROUND(AVG(st.total_score), 1) as average_score
            FROM students s
            LEFT JOIN SubjectTotalScores st ON s.id = st.student_id
            WHERE s.class_id = ?
            GROUP BY s.id, s.firstname, s.lastname, s.admission_number
            HAVING average_score IS NOT NULL
        )
        SELECT 
            *,
            DENSE_RANK() OVER (ORDER BY average_score DESC) as rank_position
        FROM StudentOverallScores
        ORDER BY average_score DESC";

    $stmt = $conn->prepare($student_results_query);
    $stmt->bind_param("iiii", $school_id, $active_term_id, $selected_class_id, $selected_class_id);
    $stmt->execute();
    $student_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Remove the top 5 students limitation
    // $top_students = array_slice($student_results, 0, 5);
    $top_students = $student_results; // Include all students

    // Get best performing subjects with updated calculation
    $best_subjects_query = "
        WITH RawScores AS (
            SELECT 
                s.subject_name,
                er.student_id,
                e.exam_type,
                e.category,
                er.score,
                e.max_score
            FROM subjects s
            JOIN exam_results er ON s.subject_id = er.subject_id
                AND er.school_id = ?
                AND er.term_id = ?
            JOIN exams e ON er.exam_id = e.exam_id
            WHERE s.class_id = ?
        ),
        ActivitySubjectScores AS (
            SELECT 
                subject_name,
                student_id,
                ROUND(
                    SUM(CASE 
                        WHEN score IS NOT NULL THEN (score / NULLIF(max_score, 0))
                        ELSE 0 
                    END) / COUNT(DISTINCT category) * 20,
                    1
                ) as activity_score
            FROM RawScores
            WHERE exam_type = 'activity'
            GROUP BY subject_name, student_id
        ),
        ExamSubjectScores AS (
            SELECT 
                subject_name,
                student_id,
                ROUND(
                    SUM(CASE 
                        WHEN score IS NOT NULL THEN (score / NULLIF(max_score, 0))
                        ELSE 0 
                    END) / COUNT(DISTINCT category) * 80,
                    1
                ) as exam_score
            FROM RawScores
            WHERE exam_type = 'exam'
            GROUP BY subject_name, student_id
        ),
        SubjectTotalScores AS (
            SELECT 
                COALESCE(a.subject_name, e.subject_name) as subject_name,
                COALESCE(a.student_id, e.student_id) as student_id,
                COALESCE(a.activity_score, 0) + COALESCE(e.exam_score, 0) as total_score
            FROM ActivitySubjectScores a
            LEFT JOIN ExamSubjectScores e ON a.subject_name = e.subject_name 
                AND a.student_id = e.student_id
            UNION ALL
            SELECT 
                e.subject_name,
                e.student_id,
                e.exam_score as total_score
            FROM ExamSubjectScores e
            LEFT JOIN ActivitySubjectScores a ON e.subject_name = a.subject_name 
                AND e.student_id = a.student_id
            WHERE a.student_id IS NULL
        )
        SELECT 
            subject_name,
            COUNT(DISTINCT student_id) as student_count,
            ROUND(AVG(total_score), 1) as average_score
        FROM SubjectTotalScores
        GROUP BY subject_name
        HAVING student_count > 0
        ORDER BY average_score DESC";

    $stmt = $conn->prepare($best_subjects_query);
    $stmt->bind_param("iii", $school_id, $active_term_id, $selected_class_id);
    $stmt->execute();
    $best_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Store class performance data
    $class_name_query = "SELECT name FROM classes WHERE id = ?";
    $stmt = $conn->prepare($class_name_query);
    $stmt->bind_param("i", $selected_class_id);
    $stmt->execute();
    $class_name_result = $stmt->get_result()->fetch_assoc();
    
    $class_performance[$selected_class_id] = [
        'class_id' => $selected_class_id,
        'class_name' => $class_name_result['name'],
        'top_students' => $top_students,
        'best_subjects' => $best_subjects
    ];
}

// Sort classes by ID
ksort($class_performance);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Performance Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4f46e5;
            --secondary-color: #0ea5e9;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --background-color: #f8fafc;
            --card-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        body {
            background-color: var(--background-color);
            font-family: 'Inter', sans-serif;
            padding-top: 2rem;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 3rem 0;
            margin-bottom: 3rem;
            border-radius: 0 0 2rem 2rem;
            box-shadow: var(--card-shadow);
        }

        .back-button {
            position: fixed;
            top: 2rem;
            left: 2rem;
            z-index: 1000;
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 1rem;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 8px -1px rgb(0 0 0 / 0.15);
        }

        .class-section {
            background: white;
            border-radius: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .class-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px -1px rgb(0 0 0 / 0.2);
        }

        .class-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .class-header h3 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .performance-card {
            height: 100%;
            background: white;
            border-radius: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
        }

        .card-header {
            padding: 1.25rem;
            border: none;
            font-weight: 600;
        }

        .card-header.students-header {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .card-header.subjects-header {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .card-header.stats-header {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
        }

        .list-group-item {
            padding: 1rem 1.5rem;
            margin: 0.5rem;
            border-radius: 0.75rem !important;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .list-group-item:hover {
            background: #f1f5f9;
            transform: translateX(5px);
        }

        .rank-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            font-weight: 600;
        }

        .score-badge {
            background: #e0e7ff;
            color: var(--primary-color);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-weight: 500;
            margin-left: auto;
        }

        .student-name {
            font-weight: 500;
            color: #1f2937;
        }

        .subject-score {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress-bar-container {
            flex-grow: 1;
            height: 0.5rem;
            background: #e2e8f0;
            border-radius: 1rem;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(to right, var(--secondary-color), var(--success-color));
            border-radius: 1rem;
            transition: width 1s ease-in-out;
        }

        .stats-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: var(--card-shadow);
        }

        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stats-label {
            color: #64748b;
            font-size: 0.875rem;
        }

        .chart-container {
            position: relative;
            margin: auto;
            height: 300px;
        }

        .performance-indicator {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .performance-indicator.excellent {
            background: #dcfce7;
            color: #059669;
        }

        .performance-indicator.good {
            background: #dbeafe;
            color: #2563eb;
        }

        .performance-indicator.average {
            background: #fef3c7;
            color: #d97706;
        }

        .performance-indicator.poor {
            background: #fee2e2;
            color: #dc2626;
        }

        .nav-tabs {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }

        .nav-tabs .nav-link {
            border: none;
            color: #64748b;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background: none;
        }

        .nav-tabs .nav-link:hover {
            color: var(--primary-color);
            border-color: transparent;
        }

        @media (max-width: 768px) {
            .back-button {
                position: static;
                margin: 1rem;
                display: inline-block;
            }

            .page-header {
                border-radius: 0;
                padding: 2rem 0;
            }
        }
    </style>
</head>
<body>
    <a href="school_admin_dashboard.php" class="back-button">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <header class="page-header">
        <div class="container text-center">
            <h2 class="display-4 mb-3">Class Performance Analysis</h2>
            <p class="lead">
                <?php echo htmlspecialchars($current_term['name'] . ' - ' . $current_term['year']); ?>
            </p>
        </div>
    </header>

    <div class="container">
        <form method="GET" class="mb-4">
            <div class="form-group">
                <label for="class_id">Select Class:</label>
                <select name="class_id" id="class_id" class="form-control" required>
                    <option value="">-- Select a Class --</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo ($selected_class_id == $class['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary mt-3">View Performance</button>
        </form>

        <?php if ($selected_class_id && empty($class_performance[$selected_class_id]['top_students'])): ?>
            <div class="alert alert-info text-center p-5 rounded-3">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h4 class="alert-heading">No Data Available</h4>
                <p class="mb-0">No performance data available for the selected class.</p>
            </div>
        <?php elseif ($selected_class_id): ?>
            <?php foreach ($class_performance as $class_data): ?>
                <div class="class-section">
                    <div class="class-header">
                        <h3>
                            <i class="fas fa-graduation-cap"></i>
                            <?php echo htmlspecialchars($class_data['class_name']); ?>
                        </h3>
                    </div>

                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs" id="classTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="overview-tab" data-bs-toggle="tab" href="#overview" role="tab">
                                <i class="fas fa-chart-pie me-2"></i>Overview
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="students-tab" data-bs-toggle="tab" href="#students" role="tab">
                                <i class="fas fa-user-graduate me-2"></i>Students
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="subjects-tab" data-bs-toggle="tab" href="#subjects" role="tab">
                                <i class="fas fa-book me-2"></i>Subjects
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content p-4">
                        <!-- Overview Tab -->
                        <div class="tab-pane fade show active" id="overview" role="tabpanel">
                            <div class="row">
                                <!-- Class Statistics -->
                                <div class="col-md-4">
                                    <div class="stats-card">
                                        <div class="stats-value">
                                            <?php 
                                            $total_students = count($class_data['top_students']);
                                            echo $total_students;
                                            ?>
                                        </div>
                                        <div class="stats-label">Total Students</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="stats-card">
                                        <div class="stats-value">
                                            <?php 
                                            $avg_score = array_sum(array_column($class_data['top_students'], 'average_score')) / $total_students;
                                            echo number_format($avg_score, 1);
                                            ?>%
                                        </div>
                                        <div class="stats-label">Class Average</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="stats-card">
                                        <div class="stats-value">
                                            <?php 
                                            $passing_students = count(array_filter($class_data['top_students'], function($student) {
                                                return $student['average_score'] >= 50;
                                            }));
                                            echo $passing_students;
                                            ?>
                                        </div>
                                        <div class="stats-label">Passing Students</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Performance Chart -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="performance-card">
                                        <div class="card-header stats-header text-white">
                                            <i class="fas fa-chart-line me-2"></i>
                                            Class Performance Distribution
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="performanceChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Students Tab -->
                        <div class="tab-pane fade" id="students" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="performance-card h-100">
                                        <div class="card-header students-header text-white">
                                            <i class="fas fa-star me-2"></i>
                                            Student Performance Rankings
                                        </div>
                                        <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                                            <?php if (empty($class_data['top_students'])): ?>
                                                <div class="text-center text-muted p-4">
                                                    <i class="fas fa-user-graduate fa-3x mb-3"></i>
                                                    <p>No student data available</p>
                                                </div>
                                            <?php else: ?>
                                                <ul class="list-group list-group-flush">
                                                    <?php foreach ($class_data['top_students'] as $student): ?>
                                                        <li class="list-group-item">
                                                            <span class="rank-number"><?php echo $student['rank_position']; ?></span>
                                                            <span class="student-name">
                                                                <?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?>
                                                                <small class="text-muted d-block"><?php echo htmlspecialchars($student['admission_number']); ?></small>
                                                            </span>
                                                            <span class="score-badge">
                                                                <?php echo $student['average_score']; ?>%
                                                            </span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Student Performance Chart -->
                                <div class="col-md-6 mb-4">
                                    <div class="performance-card h-100">
                                        <div class="card-header students-header text-white">
                                            <i class="fas fa-chart-bar me-2"></i>
                                            Student Performance Distribution
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="studentDistributionChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Subjects Tab -->
                        <div class="tab-pane fade" id="subjects" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="performance-card h-100">
                                        <div class="card-header subjects-header text-white">
                                            <i class="fas fa-book me-2"></i>
                                            Best Performing Subjects
                                        </div>
                                        <div class="card-body">
                                            <?php if (empty($class_data['best_subjects'])): ?>
                                                <div class="text-center text-muted p-4">
                                                    <i class="fas fa-book-open fa-3x mb-3"></i>
                                                    <p>No subject data available</p>
                                                </div>
                                            <?php else: ?>
                                                <ul class="list-group list-group-flush">
                                                    <?php foreach ($class_data['best_subjects'] as $index => $subject): ?>
                                                        <li class="list-group-item">
                                                            <span class="rank-number"><?php echo $index + 1; ?></span>
                                                            <span class="student-name">
                                                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                                                            </span>
                                                            <span class="score-badge">
                                                                <?php echo $subject['average_score']; ?>%
                                                            </span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Subject Performance Chart -->
                                <div class="col-md-6 mb-4">
                                    <div class="performance-card h-100">
                                        <div class="card-header subjects-header text-white">
                                            <i class="fas fa-chart-bar me-2"></i>
                                            Subject Performance Comparison
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container" style="height: 400px;">
                                                <canvas id="subjectComparisonChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Subject Performance Pie Chart -->
                                <div class="col-md-6 mb-4">
                                    <div class="performance-card h-100">
                                        <div class="card-header subjects-header text-white">
                                            <i class="fas fa-chart-pie me-2"></i>
                                            Subject Performance Distribution
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container" style="height: 400px;">
                                                <canvas id="subjectPieChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects
            document.querySelectorAll('.class-section').forEach(section => {
                section.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                section.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            <?php if ($selected_class_id && !empty($class_performance[$selected_class_id]['top_students'])): ?>
            // Performance Distribution Chart
            const performanceCtx = document.getElementById('performanceChart').getContext('2d');
            new Chart(performanceCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($class_data['top_students'], 'firstname')); ?>,
                    datasets: [{
                        label: 'Student Performance',
                        data: <?php echo json_encode(array_column($class_data['top_students'], 'average_score')); ?>,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });

            // Student Distribution Chart
            const studentDistCtx = document.getElementById('studentDistributionChart').getContext('2d');
            const scores = <?php echo json_encode(array_column($class_data['top_students'], 'average_score')); ?>;
            const ranges = {
                '90-100': 0,
                '80-89': 0,
                '70-79': 0,
                '60-69': 0,
                '50-59': 0,
                '0-49': 0
            };

            scores.forEach(score => {
                if (score >= 90) ranges['90-100']++;
                else if (score >= 80) ranges['80-89']++;
                else if (score >= 70) ranges['70-79']++;
                else if (score >= 60) ranges['60-69']++;
                else if (score >= 50) ranges['50-59']++;
                else ranges['0-49']++;
            });

            new Chart(studentDistCtx, {
                type: 'bar',
                data: {
                    labels: Object.keys(ranges),
                    datasets: [{
                        label: 'Number of Students',
                        data: Object.values(ranges),
                        backgroundColor: [
                            '#059669',  // Dark Green - Excellent (90-100)
                            '#10b981',  // Green - Very Good (80-89)
                            '#3b82f6',  // Blue - Good (70-79)
                            '#f59e0b',  // Orange - Average (60-69)
                            '#f97316',  // Dark Orange - Below Average (50-59)
                            '#ef4444'   // Red - Poor (0-49)
                        ],
                        borderColor: [
                            '#047857',  // Darker Green
                            '#059669',  // Dark Green
                            '#2563eb',  // Dark Blue
                            '#d97706',  // Dark Orange
                            '#ea580c',  // Darker Orange
                            '#dc2626'   // Dark Red
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.raw} students`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            },
                            title: {
                                display: true,
                                text: 'Number of Students'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Score Range'
                            }
                        }
                    }
                }
            });

            // Subject Comparison Bar Chart
            const subjectCtx = document.getElementById('subjectComparisonChart').getContext('2d');
            new Chart(subjectCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($class_data['best_subjects'], 'subject_name')); ?>,
                    datasets: [{
                        label: 'Subject Performance',
                        data: <?php echo json_encode(array_column($class_data['best_subjects'], 'average_score')); ?>,
                        backgroundColor: function(context) {
                            const colors = [
                                'rgba(79, 70, 229, 0.8)',   // Indigo
                                'rgba(16, 185, 129, 0.8)',  // Green
                                'rgba(245, 158, 11, 0.8)',  // Yellow
                                'rgba(239, 68, 68, 0.8)',   // Red
                                'rgba(14, 165, 233, 0.8)',  // Blue
                                'rgba(139, 92, 246, 0.8)',  // Purple
                                'rgba(249, 115, 22, 0.8)',  // Orange
                                'rgba(236, 72, 153, 0.8)',  // Pink
                                'rgba(20, 184, 166, 0.8)',  // Teal
                                'rgba(168, 85, 247, 0.8)'   // Violet
                            ];
                            return colors[context.dataIndex % colors.length];
                        },
                        borderColor: function(context) {
                            const colors = [
                                'rgb(79, 70, 229)',
                                'rgb(16, 185, 129)',
                                'rgb(245, 158, 11)',
                                'rgb(239, 68, 68)',
                                'rgb(14, 165, 233)',
                                'rgb(139, 92, 246)',
                                'rgb(249, 115, 22)',
                                'rgb(236, 72, 153)',
                                'rgb(20, 184, 166)',
                                'rgb(168, 85, 247)'
                            ];
                            return colors[context.dataIndex % colors.length];
                        },
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Score: ${context.raw}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Average Score (%)'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });

            // Subject Performance Pie Chart
            const subjectPieCtx = document.getElementById('subjectPieChart').getContext('2d');
            const subjectData = <?php echo json_encode(array_column($class_data['best_subjects'], 'average_score')); ?>;
            const subjectLabels = <?php echo json_encode(array_column($class_data['best_subjects'], 'subject_name')); ?>;
            
            new Chart(subjectPieCtx, {
                type: 'pie',
                data: {
                    labels: subjectLabels,
                    datasets: [{
                        data: subjectData,
                        backgroundColor: function(context) {
                            const colors = [
                                'rgba(79, 70, 229, 0.8)',   // Indigo
                                'rgba(16, 185, 129, 0.8)',  // Green
                                'rgba(245, 158, 11, 0.8)',  // Yellow
                                'rgba(239, 68, 68, 0.8)',   // Red
                                'rgba(14, 165, 233, 0.8)',  // Blue
                                'rgba(139, 92, 246, 0.8)',  // Purple
                                'rgba(249, 115, 22, 0.8)',  // Orange
                                'rgba(236, 72, 153, 0.8)',  // Pink
                                'rgba(20, 184, 166, 0.8)',  // Teal
                                'rgba(168, 85, 247, 0.8)'   // Violet
                            ];
                            return colors[context.dataIndex % colors.length];
                        },
                        borderColor: function(context) {
                            const colors = [
                                'rgb(79, 70, 229)',
                                'rgb(16, 185, 129)',
                                'rgb(245, 158, 11)',
                                'rgb(239, 68, 68)',
                                'rgb(14, 165, 233)',
                                'rgb(139, 92, 246)',
                                'rgb(249, 115, 22)',
                                'rgb(236, 72, 153)',
                                'rgb(20, 184, 166)',
                                'rgb(168, 85, 247)'
                            ];
                            return colors[context.dataIndex % colors.length];
                        },
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                font: {
                                    size: 11
                                },
                                boxWidth: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value}% (${percentage}% of total)`;
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html