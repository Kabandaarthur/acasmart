 <?php
session_start();

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
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


$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Fetch student's information
$student_query = "SELECT s.*, c.name as class_name, t.name as current_term_name, t.year as current_year
                  FROM students s
                  LEFT JOIN classes c ON s.class_id = c.id
                  LEFT JOIN terms t ON s.current_term_id = t.id
                  WHERE s.id = ? AND s.school_id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("ii", $student_id, $school_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

if (!$student) {
    die("Student information not found.");
}

$student_name = $student['firstname'] . ' ' . $student['lastname'];
$class_name = $student['class_name'];
$current_term = $student['current_term_name'];
$current_year = $student['current_year'];

// Fetch current term information
$term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$current_term_data = $term_result->fetch_assoc();
$current_term_id = $current_term_data['id'];

// Build exam categories map (category => [exam_type]) like bulk reports
$exam_categories = [];
$has_activity_categories = false;
$exam_categories_query = "SELECT DISTINCT category, exam_type FROM exams WHERE school_id = ? AND term_id = ? ORDER BY category, exam_type";
$stmt = $conn->prepare($exam_categories_query);
$stmt->bind_param("ii", $school_id, $current_term_id);
$stmt->execute();
$exam_categories_result = $stmt->get_result();
while ($row = $exam_categories_result->fetch_assoc()) {
    if (!isset($exam_categories[$row['category']])) {
        $exam_categories[$row['category']] = [];
    }
    $exam_categories[$row['category']][] = $row['exam_type'];
    if ($row['exam_type'] === 'activity') {
        $has_activity_categories = true;
    }
}

// Fetch student's subjects
$subjects_query = "SELECT DISTINCT s.subject_name, s.subject_code
                   FROM subjects s
                   JOIN student_subjects ss ON s.subject_id = ss.subject_id
                   WHERE ss.student_id = ? AND s.school_id = ?
                   ORDER BY s.subject_name";
$stmt = $conn->prepare($subjects_query);
$stmt->bind_param("ii", $student_id, $school_id);
$stmt->execute();
$subjects_result = $stmt->get_result();
$subjects = [];
while ($subject = $subjects_result->fetch_assoc()) {
    $subjects[] = $subject;
}

// Fetch recent exam results
$results_query = "SELECT 
                    s.subject_name,
                    e.exam_type,
                    e.category,
                    er.score,
                    e.max_score,
                    ROUND((er.score / e.max_score) * 100, 1) as percentage
                  FROM exam_results er
                  JOIN exams e ON er.exam_id = e.exam_id
                  JOIN subjects s ON er.subject_id = s.subject_id
                  WHERE er.student_id = ? 
                  AND e.term_id = ?
                  AND er.school_id = ?
                  ORDER BY e.exam_type, s.subject_name";
$stmt = $conn->prepare($results_query);
$stmt->bind_param("iii", $student_id, $current_term_id, $school_id);
$stmt->execute();
$results_result = $stmt->get_result();
$exam_results = [];
while ($result = $results_result->fetch_assoc()) {
    $exam_results[] = $result;
}

// Placeholder; will compute overall percentage using weighted logic below
$overall_percentage = 0;

// Build per-subject aggregates for best/worst subjects using activity (20) + exam (80)
$subject_breakdown = [];
foreach ($exam_results as $result) {
    $subject = $result['subject_name'];
    if (!isset($subject_breakdown[$subject])) {
        $subject_breakdown[$subject] = [
            'activity_total' => 0.0,
            'activity_max' => 0.0,
            'exam_total' => 0.0,
            'exam_max' => 0.0,
            'has_activity' => false,
            'has_exam' => false,
        ];
    }
    if ($result['score'] !== null && $result['max_score'] > 0) {
        if ($result['exam_type'] === 'activity') {
            $subject_breakdown[$subject]['activity_total'] += (float)$result['score'];
            $subject_breakdown[$subject]['activity_max'] += (float)$result['max_score'];
            $subject_breakdown[$subject]['has_activity'] = true;
        } else if ($result['exam_type'] === 'exam') {
            $subject_breakdown[$subject]['exam_total'] += (float)$result['score'];
            $subject_breakdown[$subject]['exam_max'] += (float)$result['max_score'];
            $subject_breakdown[$subject]['has_exam'] = true;
        }
    }
}

$subject_summaries = [];
foreach ($subject_breakdown as $subject => $bd) {
    $activity_component = 0;
    if ($has_activity_categories && $bd['has_activity'] && $bd['activity_max'] > 0) {
        $activity_component = round(($bd['activity_total'] / $bd['activity_max']) * 20);
    }
    $exam_component = 0;
    if ($bd['has_exam'] && $bd['exam_max'] > 0) {
        $exam_component = round(($bd['exam_total'] / $bd['exam_max']) * ($has_activity_categories ? 80 : 100));
    }
    $total_percentage = $activity_component + $exam_component; // out of 100
    $subject_summaries[] = [
        'subject' => $subject,
        'percentage' => (float)$total_percentage
    ];
}

// Compute overall percentage using the same logic as reports (average of per-subject totals)
$total_subject_marks = 0;
$subject_count_for_avg = 0;
foreach ($subject_breakdown as $subject => $bd) {
    $has_any_score = ($bd['activity_total'] > 0 || $bd['exam_total'] > 0);
    if (!$has_any_score && (!$bd['has_activity'] && !$bd['has_exam'])) {
        continue;
    }
    $activity_component = 0;
    if ($has_activity_categories && $bd['has_activity'] && $bd['activity_max'] > 0) {
        $activity_component = round(($bd['activity_total'] / $bd['activity_max']) * 20);
    }
    $exam_component = 0;
    if ($bd['has_exam'] && $bd['exam_max'] > 0) {
        $exam_component = round(($bd['exam_total'] / $bd['exam_max']) * ($has_activity_categories ? 80 : 100));
    }
    $total = $activity_component + $exam_component;
    if ($total > 0 || $bd['has_exam'] || $bd['has_activity']) {
        $total_subject_marks += $total;
        $subject_count_for_avg++;
    }
}
$overall_percentage = ($subject_count_for_avg > 0) ? round(($total_subject_marks / $subject_count_for_avg), 1) : 0;

// Sort for best and worst subjects
usort($subject_summaries, function($a, $b) { return $b['percentage'] <=> $a['percentage']; });
$best_subjects = array_slice($subject_summaries, 0, 5);
$worst_subjects = array_slice(array_reverse($subject_summaries), 0, 5);

// Ensure class_id is available before computing position
$class_id = $student['class_id'];

// Compute class position using the same logic as bulk report (per-subject totals with weighting, averaged)
$total_students = 0;
$student_position = 'N/A';
if ($class_id) {
    // Get all students in class
    $all_students_query = "SELECT id FROM students WHERE class_id = ? AND school_id = ? ORDER BY lastname, firstname";
    $stmt = $conn->prepare($all_students_query);
    $stmt->bind_param("ii", $class_id, $school_id);
    $stmt->execute();
    $all_students_result = $stmt->get_result();

    $student_averages = [];

    while ($student_row = $all_students_result->fetch_assoc()) {
        $sid = (int)$student_row['id'];
        // Fetch this student's result rows using same structure as bulk
        $student_results_query = "
            SELECT 
                s.subject_name,
                e.exam_type,
                e.category,
                er.score,
                e.max_score
            FROM student_subjects ss
            JOIN subjects s ON ss.subject_id = s.subject_id
            CROSS JOIN (
                SELECT DISTINCT category, exam_type, exam_id, max_score
                FROM exams 
                WHERE school_id = ? 
                AND term_id = ?
            ) e
            LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
                AND er.student_id = ? 
                AND er.subject_id = s.subject_id
                AND er.school_id = ?
            WHERE s.class_id = ?
            AND ss.student_id = ?
            ORDER BY s.subject_name, e.category, e.exam_type";

        $stmt2 = $conn->prepare($student_results_query);
        $stmt2->bind_param("iiiiii", 
            $school_id,
            $current_term_id,
            $sid,
            $school_id,
            $class_id,
            $sid
        );
        $stmt2->execute();
        $student_results = $stmt2->get_result();

        // Build per-subject categories
        $student_exam_results = [];
        $subject_count_calc = 0;
        while ($row = $student_results->fetch_assoc()) {
            $subject = $row['subject_name'];
            $category = $row['category'];
            if (!isset($student_exam_results[$subject])) {
                $student_exam_results[$subject] = [ 'categories' => [] ];
                $subject_count_calc++;
            }
            if (!isset($student_exam_results[$subject]['categories'][$category])) {
                $student_exam_results[$subject]['categories'][$category] = [ 'score' => null, 'max_score' => $row['max_score'] ];
            }
            if ($row['score'] !== null) {
                $student_exam_results[$subject]['categories'][$category]['score'] = $row['score'];
            }
        }

        // Compute weighted totals per subject and average across subjects
        $total_subject_marks_calc = 0;
        foreach ($student_exam_results as $subject => $data) {
            $activity_total = 0; $activity_max = 0; $activity_count = 0;
            $exam_categories_total = 0; $exam_categories_max = 0; $has_any_score = false;
            foreach ($data['categories'] as $cat => $vals) {
                $score = $vals['score'];
                $maxs = $vals['max_score'];
                if ($score !== null) {
                    $has_any_score = true;
                    // Determine type from global exam categories
                    if (isset($exam_categories[$cat]) && !empty($exam_categories[$cat]) && $exam_categories[$cat][0] === 'activity') {
                        $activity_total += $score; $activity_max += $maxs; $activity_count++;
                    } else {
                        $exam_categories_total += $score; $exam_categories_max += $maxs;
                    }
                } else {
                    if (isset($exam_categories[$cat]) && !empty($exam_categories[$cat]) && $exam_categories[$cat][0] === 'activity') {
                        $activity_max += $maxs; $activity_count++;
                    } else {
                        $exam_categories_max += $maxs;
                    }
                }
            }
            if ($has_any_score) {
                $total = ($has_activity_categories && $activity_count > 0 ? round(($activity_total / $activity_max) * 20) : 0) +
                         ($exam_categories_max > 0 ? round(($exam_categories_total / $exam_categories_max) * ($has_activity_categories ? 80 : 100)) : 0);
                $total_subject_marks_calc += $total;
            }
        }

        $avg_percentage = ($subject_count_calc > 0) ? ($total_subject_marks_calc / $subject_count_calc) : 0;
        $student_averages[] = [ 'student_id' => $sid, 'avg' => round($avg_percentage, 1) ];
    }

    // Determine total students and position with ties
    $total_students = count($student_averages);
    usort($student_averages, function($a, $b) { return $b['avg'] <=> $a['avg']; });
    for ($i = 0; $i < count($student_averages); $i++) {
        if ($student_averages[$i]['student_id'] === $student_id) {
            $current_average = $student_averages[$i]['avg'];
            $actual_position = $i + 1;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($student_averages[$j]['avg'] == $current_average) {
                    $actual_position = $j + 1;
                } else {
                    break;
                }
            }
            $student_position = $actual_position;
            break;
        }
    }
}

// Next term start date (if available)
$next_term_start_date = null;
if (!empty($current_term_id)) {
    $nt_stmt = $conn->prepare("SELECT next_term_start_date FROM terms WHERE id = ? AND school_id = ?");
    $nt_stmt->bind_param("ii", $current_term_id, $school_id);
    $nt_stmt->execute();
    $nt_res = $nt_stmt->get_result();
    $nt_row = $nt_res->fetch_assoc();
    if ($nt_row && !empty($nt_row['next_term_start_date'])) {
        $next_term_start_date = $nt_row['next_term_start_date'];
    }
}

// Prepare overall summary for frontend
$overall_summary = [
    'student_name' => $student_name,
    'class_name' => $class_name,
    'stream' => $student['stream'],
    'year' => $current_year,
    'position' => $student_position,
    'total_students' => $total_students,
    'overall_percentage' => $overall_percentage,
    'best_subjects' => $best_subjects,
    'worst_subjects' => $worst_subjects,
    'next_term_start_date' => $next_term_start_date
];

// Fetch payment history for the student
$payments_query = "SELECT id, amount, payment_date, payment_method, receipt_number FROM fee_payments WHERE student_id = ? AND school_id = ? ORDER BY payment_date DESC";
$stmt = $conn->prepare($payments_query);
$stmt->bind_param("ii", $student_id, $school_id);
$stmt->execute();
$payments_result = $stmt->get_result();
$payments = [];
while ($payment = $payments_result->fetch_assoc()) {
    $payments[] = $payment;
}

// Calculate current term debt for the student
$section = $student['section'];

// Get total fee for this class, section, term, and school
$fee_query = "SELECT SUM(amount) as total_fee FROM fees WHERE class_id = ? AND section = ? AND term_id = ? AND school_id = ?";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("isii", $class_id, $section, $current_term_id, $school_id);
$stmt->execute();
$fee_result = $stmt->get_result();
$fee_row = $fee_result->fetch_assoc();
$total_fee = isset($fee_row['total_fee']) ? $fee_row['total_fee'] : 0;

// Get total paid by the student for this term and school
$paid_query = "SELECT SUM(amount) as total_paid FROM fee_payments WHERE student_id = ? AND term_id = ? AND school_id = ?";
$stmt = $conn->prepare($paid_query);
$stmt->bind_param("iii", $student_id, $current_term_id, $school_id);
$stmt->execute();
$paid_result = $stmt->get_result();
$paid_row = $paid_result->fetch_assoc();
$total_paid = isset($paid_row['total_paid']) ? $paid_row['total_paid'] : 0;

$current_debt = $total_fee - $total_paid;

// Get school information
$school_query = "SELECT school_name, motto, badge FROM schools WHERE id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school = $school_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - <?php echo htmlspecialchars($school['school_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: #f3f4f6;
        }
        .dashboard-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            padding: 1rem 0;
            margin-bottom: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        @media (min-width: 768px) {
            .dashboard-header {
                padding: 2rem 0;
                margin-bottom: 2rem;
            }
        }
        .card {
            background: white;
            border-radius: 0.75rem;
            box-shadow: 0 2px 4px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px -1px rgba(0, 0, 0, 0.15);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .grade-a { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .grade-b { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .grade-c { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .grade-d { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        
        /* Mobile-specific styles */
        @media (max-width: 767px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            .card {
                border-radius: 0.5rem;
                margin-bottom: 1rem;
            }
            .performance-view {
                margin-top: 1rem;
            }
            .breadcrumb-item {
                font-size: 0.875rem;
            }
            .exam-type-card, .category-card {
                min-height: 120px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .exam-type-card i, .category-card i {
                font-size: 2rem !important;
            }
            .exam-type-card h5, .category-card h5 {
                font-size: 1rem;
                margin-bottom: 0.5rem;
            }
            .exam-type-card p, .category-card p {
                font-size: 0.75rem;
            }
            .back-btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
            .performance-summary-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .student-info-card {
                flex-direction: column;
                text-align: center;
                padding: 1rem;
            }
            .student-info-card img, .student-info-card .w-16 {
                margin-bottom: 1rem;
                margin-right: 0;
            }
            .academic-info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .personal-info-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .payment-table {
                font-size: 0.75rem;
            }
            .payment-table th, .payment-table td {
                padding: 0.5rem 0.25rem;
            }
            .tab-link {
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
                margin: 0 0.25rem;
            }
            .dashboard-header .flex {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            .dashboard-header .flex > div:first-child {
                flex-direction: column;
                gap: 1rem;
            }
            .dashboard-header .flex > div:last-child {
                flex-direction: column;
                gap: 0.5rem;
            }
            .dashboard-header h1 {
                font-size: 1.5rem;
            }
            .dashboard-header p {
                font-size: 0.875rem;
            }
            .logout-btn {
                width: 100%;
                text-align: center;
            }
        }
        
        /* Tablet styles */
        @media (min-width: 768px) and (max-width: 1023px) {
            .performance-summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .academic-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .personal-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        /* Touch-friendly interactions */
        .exam-type-card, .category-card {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .exam-type-card:active, .category-card:active {
            transform: scale(0.98);
        }
        .back-btn:active {
            transform: scale(0.95);
        }
        
        /* Improved table responsiveness */
        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .responsive-table {
            min-width: 600px;
        }
        @media (max-width: 767px) {
            .responsive-table {
                min-width: 400px;
            }
        }
        
        /* Mobile menu styles */
        #mobile-menu {
            transition: all 0.3s ease;
            transform-origin: top;
        }
        #mobile-menu.hidden {
            transform: scaleY(0);
            opacity: 0;
        }
        #mobile-menu:not(.hidden) {
            transform: scaleY(1);
            opacity: 1;
        }
        .tab-link-mobile {
            transition: all 0.2s ease;
        }
        .tab-link-mobile:hover {
            background-color: #f3f4f6;
        }
        .tab-link-mobile:active {
            transform: scale(0.98);
        }
        #mobile-menu-btn {
            transition: all 0.2s ease;
        }
        #mobile-menu-btn:active {
            transform: scale(0.95);
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation Bar -->
    <nav class="dashboard-header">
        <div class="container mx-auto px-4 md:px-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2 md:space-x-4">
                    <?php if ($school['badge']): ?>
                        <img src="uploads/<?php echo htmlspecialchars($school['badge']); ?>" alt="School Badge" class="w-12 h-12 md:w-16 md:h-16 rounded-full">
                    <?php else: ?>
                        <i class="fas fa-school text-white text-2xl md:text-4xl"></i>
                    <?php endif; ?>
                    <div>
                        <h1 class="text-lg md:text-2xl font-bold text-white"><?php echo htmlspecialchars($school['school_name']); ?></h1>
                        <p class="text-blue-100 text-xs md:text-sm"><?php echo htmlspecialchars($school['motto']); ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-2 md:space-x-4">
                    <div class="text-right text-white hidden sm:block">
                        <p class="font-semibold text-sm md:text-base"><?php echo htmlspecialchars($student_name); ?></p>
                        <p class="text-xs md:text-sm text-blue-100"><?php echo htmlspecialchars($class_name); ?></p>
                    </div>
                    <a href="logout.php" class="bg-white text-blue-600 px-3 py-2 md:px-4 md:py-2 rounded-lg hover:bg-blue-50 transition-colors duration-300 text-sm md:text-base logout-btn">
                        <i class="fas fa-sign-out-alt mr-1 md:mr-2"></i>Logout
                    </a>
                </div>
            </div>
            <!-- Tab Navigation -->
            <div class="mt-4 md:mt-6">
                <!-- Mobile Menu Button -->
                <div class="md:hidden flex justify-center mb-4">
                    <button id="mobile-menu-btn" class="bg-white text-blue-600 px-4 py-2 rounded-lg hover:bg-blue-50 transition-colors duration-300 flex items-center space-x-2">
                        <i class="fas fa-bars text-lg"></i>
                        <span class="text-sm font-semibold">Menu</span>
                    </button>
                </div>
                
                <!-- Mobile Menu Dropdown -->
                <div id="mobile-menu" class="md:hidden hidden bg-white rounded-lg shadow-lg mb-4">
                    <div class="py-2">
                        <button class="tab-link-mobile w-full text-left px-4 py-3 text-gray-700 font-semibold hover:bg-blue-50 focus:outline-none text-sm border-b border-gray-100" data-tab="profile">
                            <i class="fas fa-user mr-3"></i>Profile
                        </button>
                        <button class="tab-link-mobile w-full text-left px-4 py-3 text-gray-700 font-semibold hover:bg-blue-50 focus:outline-none text-sm border-b border-gray-100" data-tab="fees">
                            <i class="fas fa-credit-card mr-3"></i>Fees & Payments
                        </button>
                        <button class="tab-link-mobile w-full text-left px-4 py-3 text-gray-700 font-semibold hover:bg-blue-50 focus:outline-none text-sm" data-tab="performance">
                            <i class="fas fa-chart-line mr-3"></i>Academic Performance
                        </button>
                    </div>
                </div>
                
                <!-- Desktop Tab Navigation -->
                <div class="hidden md:flex flex-wrap justify-center gap-2 md:gap-6">
                    <button class="tab-link text-white font-semibold px-3 py-2 md:px-4 md:py-2 rounded-lg bg-blue-700 focus:outline-none text-sm md:text-base" data-tab="profile">Profile</button>
                    <button class="tab-link text-white font-semibold px-3 py-2 md:px-4 md:py-2 rounded-lg hover:bg-blue-600 focus:outline-none text-sm md:text-base" data-tab="fees">Fees & Payments</button>
                    <button class="tab-link text-white font-semibold px-3 py-2 md:px-4 md:py-2 rounded-lg hover:bg-blue-600 focus:outline-none text-sm md:text-base" data-tab="performance">Academic Performance</button>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4">
        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4" role="alert">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span class="text-sm md:text-base"><?php echo htmlspecialchars($_GET['success']); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4" role="alert">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span class="text-sm md:text-base"><?php echo htmlspecialchars($_GET['error']); ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Profile Tab -->
        <div id="tab-profile" class="tab-content">
            <!-- Student Information -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-6 md:mb-8 performance-summary-grid">
                <div class="card p-4 md:p-6">
                    <div class="flex items-center space-x-3 md:space-x-4 student-info-card">
                        <?php if ($student['image']): ?>
                            <img src="<?php echo htmlspecialchars($student['image']); ?>" alt="Student Photo" class="w-12 h-12 md:w-16 md:h-16 rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-12 h-12 md:w-16 md:h-16 bg-gray-200 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-gray-400 text-xl md:text-2xl"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h3 class="text-base md:text-lg font-semibold"><?php echo htmlspecialchars($student_name); ?></h3>
                            <p class="text-sm md:text-base text-gray-600"><?php echo htmlspecialchars($student['admission_number']); ?></p>
                            <p class="text-xs md:text-sm text-gray-500"><?php echo htmlspecialchars($student['student_email']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card p-4 md:p-6">
                    <h3 class="text-base md:text-lg font-semibold mb-3 md:mb-4">Academic Information</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-600 text-sm md:text-base">Class:</span>
                            <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($class_name); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 text-sm md:text-base">Stream:</span>
                            <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($student['stream']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 text-sm md:text-base">Current Term:</span>
                            <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($current_term); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600 text-sm md:text-base">Academic Year:</span>
                            <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($current_year); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Personal Information -->
            <div class="card p-4 md:p-6 mt-6 md:mt-8 max-w-md mx-auto">
                <h3 class="text-lg md:text-xl font-semibold mb-4 md:mb-6">Personal Information</h3>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600 text-sm md:text-base">Gender:</span>
                        <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($student['gender']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600 text-sm md:text-base">Stream:</span>
                        <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($student['stream']); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600 text-sm md:text-base">LIN Number:</span>
                        <span class="font-medium text-sm md:text-base"><?php echo htmlspecialchars($student['lin_number']); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Password Update Section -->
            <div class="card p-4 md:p-6 mt-6 md:mt-8 max-w-md mx-auto">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg md:text-xl font-semibold">Update Password</h3>
                    <button id="toggle-password-form" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm md:text-base transition-colors duration-300">
                        <i class="fas fa-key mr-2"></i>Change Password
                    </button>
                </div>
                
                <!-- Password Update Form (Hidden by default) -->
                <div id="password-form-container" class="hidden">
                    <form id="password-update-form" method="post" action="update_student_password.php">
                        <div class="mb-4">
                            <label class="block text-gray-700 mb-2 text-sm md:text-base" for="new_password">New Password</label>
                            <input type="password" name="new_password" id="new_password" class="w-full px-3 py-2 border rounded-lg text-sm md:text-base" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-gray-700 mb-2 text-sm md:text-base" for="confirm_password">Confirm Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="w-full px-3 py-2 border rounded-lg text-sm md:text-base" required>
                        </div>
                        <div class="flex space-x-3">
                            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 text-sm md:text-base flex-1">
                                <i class="fas fa-save mr-2"></i>Update Password
                            </button>
                            <button type="button" id="cancel-password" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 text-sm md:text-base">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Fees & Payments Tab -->
        <div id="tab-fees" class="tab-content hidden">
            <div class="card p-4 md:p-6 mt-6 md:mt-8">
                <h3 class="text-lg md:text-xl font-semibold mb-4 md:mb-6">Fees & Payments</h3>
                <div class="mb-4 md:mb-6">
                    <h4 class="text-base md:text-lg font-semibold mb-2">Current Term Debt</h4>
                    <?php if ($current_debt > 0): ?>
                        <div class="bg-red-100 text-red-700 p-3 md:p-4 rounded-lg mb-2">
                            <span class="font-bold text-sm md:text-base">Outstanding Debt:</span> 
                            <span class="text-sm md:text-base"><?php echo number_format($current_debt, 2); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="bg-green-100 text-green-700 p-3 md:p-4 rounded-lg mb-2">
                            <span class="font-bold text-sm md:text-base">No outstanding debt for the current term.</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h4 class="text-base md:text-lg font-semibold mb-2">Payment History</h4>
                    <?php if (!empty($payments)): ?>
                        <div class="table-container">
                            <table class="responsive-table min-w-full bg-white rounded-lg payment-table">
                                <thead>
                                    <tr>
                                        <th class="py-2 px-2 md:px-4 border-b text-left text-xs md:text-sm font-medium text-gray-700">Date</th>
                                        <th class="py-2 px-2 md:px-4 border-b text-left text-xs md:text-sm font-medium text-gray-700">Amount</th>
                                        <th class="py-2 px-2 md:px-4 border-b text-left text-xs md:text-sm font-medium text-gray-700">Method</th>
                                        <th class="py-2 px-2 md:px-4 border-b text-left text-xs md:text-sm font-medium text-gray-700">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td class="py-2 px-2 md:px-4 border-b text-xs md:text-sm"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td class="py-2 px-2 md:px-4 border-b text-xs md:text-sm"><?php echo number_format($payment['amount'], 2); ?></td>
                                            <td class="py-2 px-2 md:px-4 border-b text-xs md:text-sm"><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                            <td class="py-2 px-2 md:px-4 border-b">
                                                <a href="generate_receipt.php?receipt_number=<?php echo urlencode($payment['receipt_number']); ?>" target="_blank" class="text-blue-600 hover:underline text-xs md:text-sm">Download</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500 text-sm md:text-base">No payments found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Academic Performance Tab -->
        <div id="tab-performance" class="tab-content hidden">
            <div class="card p-4 md:p-6 mt-6 md:mt-8">
                <h3 class="text-lg md:text-xl font-semibold mb-4 md:mb-6">Academic Performance</h3>
                
                <!-- Navigation Breadcrumb -->
                <div id="performance-breadcrumb" class="mb-4 md:mb-6">
                    <nav class="flex" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3">
                            <li class="inline-flex items-center">
                                <button class="breadcrumb-item inline-flex items-center text-xs md:text-sm font-medium text-gray-700 hover:text-blue-600" data-level="term">
                                    <i class="fas fa-calendar-alt mr-1 md:mr-2"></i>
                                    <?php echo htmlspecialchars($current_term . ' ' . $current_year); ?>
                                </button>
                            </li>
                        </ol>
                    </nav>
                </div>

                <!-- Overall Performance Summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 mb-6 md:mb-8 performance-summary-grid">
                    <div class="card stat-card p-4 md:p-6">
                        <div class="text-center">
                            <div class="text-2xl md:text-3xl font-bold mb-2"><?php echo $overall_percentage; ?>%</div>
                            <p class="text-blue-100 text-sm md:text-base">Overall Average</p>
                        </div>
                    </div>
                    <div class="card p-4 md:p-6">
                        <div class="text-center">
                            <div class="text-2xl md:text-3xl font-bold mb-2 text-blue-600"><?php echo count($subjects); ?></div>
                            <p class="text-gray-600 text-sm md:text-base">Subjects</p>
                        </div>
                    </div>
                </div>

                <!-- Content Areas -->
                <div id="performance-content">
                    <!-- Exam Types View (Default) -->
                    <div id="exam-types-view" class="performance-view">
                        <h4 class="text-base md:text-lg font-semibold mb-4">Exam Types</h4>
                        <?php 
                        $exam_types = [];
                        foreach ($exam_results as $result) {
                            $exam_type = $result['exam_type'];
                            if (!in_array($exam_type, $exam_types)) {
                                $exam_types[] = $exam_type;
                            }
                        }
                        
                        if (!empty($exam_types)): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                                <!-- Overall Summary Card -->
                                <div id="overall-summary-card" class="card p-4 md:p-6 cursor-pointer hover:shadow-lg transition-shadow exam-type-card">
                                    <div class="text-center">
                                        <i class="fas fa-chart-pie text-2xl md:text-3xl text-green-600 mb-2 md:mb-3"></i>
                                        <h5 class="font-semibold text-gray-900 mb-1 md:mb-2 text-sm md:text-base">Overall Summary</h5>
                                        <p class="text-xs md:text-sm text-gray-500">Click to view overall performance</p>
                                    </div>
                                </div>
                                <?php foreach ($exam_types as $exam_type): ?>
                                    <div class="card p-4 md:p-6 cursor-pointer hover:shadow-lg transition-shadow exam-type-card" data-exam-type="<?php echo htmlspecialchars($exam_type); ?>">
                                        <div class="text-center">
                                            <i class="fas fa-file-alt text-2xl md:text-3xl text-blue-600 mb-2 md:mb-3"></i>
                                            <h5 class="font-semibold text-gray-900 mb-1 md:mb-2 text-sm md:text-base"><?php echo htmlspecialchars($exam_type); ?></h5>
                                            <p class="text-xs md:text-sm text-gray-500">Click to view categories</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-chart-line text-gray-400 text-3xl md:text-4xl mb-3 md:mb-4"></i>
                                <p class="text-gray-500 text-base md:text-lg">No exam results available for the current term.</p>
                                <p class="text-gray-400 text-xs md:text-sm">Results will appear here once they are uploaded by your teachers.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Categories View -->
                    <div id="categories-view" class="performance-view hidden">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-base md:text-lg font-semibold">Categories</h4>
                            <button class="back-btn text-blue-600 hover:text-blue-800 text-sm md:text-base" data-back-to="exam-types">
                                <i class="fas fa-arrow-left mr-1 md:mr-2"></i>Back to Exam Types
                            </button>
                        </div>
                        <div id="categories-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                            <!-- Categories will be populated by JavaScript -->
                        </div>
                    </div>

                    <!-- Subjects View -->
                    <div id="subjects-view" class="performance-view hidden">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-base md:text-lg font-semibold">Subject Results</h4>
                            <button class="back-btn text-blue-600 hover:text-blue-800 text-sm md:text-base" data-back-to="categories">
                                <i class="fas fa-arrow-left mr-1 md:mr-2"></i>Back to Categories
                            </button>
                        </div>
                        <div id="subjects-results">
                            <!-- Subject results will be populated by JavaScript -->
                        </div>
                    </div>

                    <!-- Overall Summary View -->
                    <div id="overall-summary-view" class="performance-view hidden">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="text-base md:text-lg font-semibold">Overall Academic Summary</h4>
                            <button class="back-btn text-blue-600 hover:text-blue-800 text-sm md:text-base" data-back-to="exam-types">
                                <i class="fas fa-arrow-left mr-1 md:mr-2"></i>Back to Exam Types
                            </button>
                        </div>
                        <div id="overall-summary-content" class="space-y-4 md:space-y-6"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Store exam results data for JavaScript
            const examResultsData = <?php echo json_encode($exam_results); ?>;
            const overallSummaryData = <?php echo json_encode($overall_summary); ?>;
        </script>
    </div>

    <script>
        // Tab switching logic
        document.addEventListener('DOMContentLoaded', function() {
            const tabLinks = document.querySelectorAll('.tab-link, .tab-link-mobile');
            const tabContents = document.querySelectorAll('.tab-content');
            const mobileMenu = document.getElementById('mobile-menu');
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            
            // Mobile menu toggle
            mobileMenuBtn.addEventListener('click', function() {
                mobileMenu.classList.toggle('hidden');
                // Change icon based on menu state
                const icon = this.querySelector('i');
                if (mobileMenu.classList.contains('hidden')) {
                    icon.className = 'fas fa-bars text-lg';
                } else {
                    icon.className = 'fas fa-times text-lg';
                }
            });
            
            // Close mobile menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!mobileMenuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
                    mobileMenu.classList.add('hidden');
                    const icon = mobileMenuBtn.querySelector('i');
                    icon.className = 'fas fa-bars text-lg';
                }
            });
            
            // Tab switching for both desktop and mobile
            tabLinks.forEach(link => {
                link.addEventListener('click', function() {
                    const tab = this.getAttribute('data-tab');
                    
                    // Update desktop tab links
                    document.querySelectorAll('.tab-link').forEach(l => {
                        l.classList.remove('bg-blue-700');
                        if (l.getAttribute('data-tab') === tab) {
                            l.classList.add('bg-blue-700');
                        }
                    });
                    
                    // Update mobile tab links
                    document.querySelectorAll('.tab-link-mobile').forEach(l => {
                        l.classList.remove('bg-blue-100', 'text-blue-700');
                        if (l.getAttribute('data-tab') === tab) {
                            l.classList.add('bg-blue-100', 'text-blue-700');
                        }
                    });
                    
                    // Show/hide tab contents
                    tabContents.forEach(content => {
                        if (content.id === 'tab-' + tab) {
                            content.classList.remove('hidden');
                        } else {
                            content.classList.add('hidden');
                        }
                    });
                    
                    // Close mobile menu after selection
                    mobileMenu.classList.add('hidden');
                    const icon = mobileMenuBtn.querySelector('i');
                    icon.className = 'fas fa-bars text-lg';
                });
            });

            // Academic Performance Navigation Logic
            let currentExamType = '';
            let currentCategory = '';
            let showingOverall = false;
            let showingReport = false;

            // Function to get grade and color class
            function getGradeInfo(percentage) {
                if (percentage >= 80) return { grade: 'A', class: 'grade-a' };
                if (percentage >= 70) return { grade: 'B', class: 'grade-b' };
                if (percentage >= 60) return { grade: 'C', class: 'grade-c' };
                if (percentage >= 50) return { grade: 'D', class: 'grade-d' };
                return { grade: 'E', class: 'grade-d' };
            }

            // Function to show view
            function showView(viewId) {
                document.querySelectorAll('.performance-view').forEach(view => {
                    view.classList.add('hidden');
                });
                document.getElementById(viewId).classList.remove('hidden');
            }

            // Function to update breadcrumb
            function updateBreadcrumb() {
                const breadcrumb = document.getElementById('performance-breadcrumb');
                let html = `
                    <nav class="flex" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3">
                            <li class="inline-flex items-center">
                                <button class="breadcrumb-item inline-flex items-center text-sm font-medium text-gray-700 hover:text-blue-600" data-level="term">
                                    <i class="fas fa-calendar-alt mr-2"></i>
                                    <?php echo htmlspecialchars($current_term . ' ' . $current_year); ?>
                                </button>
                            </li>`;

                if (currentExamType) {
                    html += `
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                                <button class="breadcrumb-item text-sm font-medium text-gray-700 hover:text-blue-600" data-level="exam-type">
                                    ${currentExamType}
                                </button>
                            </div>
                        </li>`;
                }

                if (currentCategory) {
                    html += `
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                                <span class="text-sm font-medium text-gray-500">${currentCategory}</span>
                            </div>
                        </li>`;
                }

                if (showingOverall) {
                    html += `
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                                <span class="text-sm font-medium text-gray-500">Overall Summary</span>
                            </div>
                        </li>`;
                }
                if (showingReport) {
                    html += `
                        <li>
                            <div class="flex items-center">
                                <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                                <span class="text-sm font-medium text-gray-500">Report</span>
                            </div>
                        </li>`;
                }

                html += `
                        </ol>
                    </nav>`;
                breadcrumb.innerHTML = html;

                // Add event listeners to breadcrumb items
                document.querySelectorAll('.breadcrumb-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const level = this.getAttribute('data-level');
                        if (level === 'term') {
                            showView('exam-types-view');
                            currentExamType = '';
                            currentCategory = '';
                            showingOverall = false;
                            showingReport = false;
                            updateBreadcrumb();
                        } else if (level === 'exam-type') {
                            showView('categories-view');
                            currentCategory = '';
                            showingOverall = false;
                            showingReport = false;
                            updateBreadcrumb();
                            showCategories(currentExamType);
                        }
                    });
                });
            }

            // Function to show categories for selected exam type
            function showCategories(examType) {
                const categories = [...new Set(examResultsData
                    .filter(result => result.exam_type === examType)
                    .map(result => result.category))];

                const categoriesGrid = document.getElementById('categories-grid');
                categoriesGrid.innerHTML = '';

                categories.forEach(category => {
                    const categoryCard = document.createElement('div');
                    categoryCard.className = 'card p-4 md:p-6 cursor-pointer hover:shadow-lg transition-shadow category-card';
                    categoryCard.setAttribute('data-category', category);
                    categoryCard.innerHTML = `
                        <div class="text-center">
                            <i class="fas fa-tags text-2xl md:text-3xl text-green-600 mb-2 md:mb-3"></i>
                            <h5 class="font-semibold text-gray-900 mb-1 md:mb-2 text-sm md:text-base">${category}</h5>
                            <p class="text-xs md:text-sm text-gray-500">Click to view subjects</p>
                        </div>
                    `;
                    categoriesGrid.appendChild(categoryCard);

                    categoryCard.addEventListener('click', function() {
                        currentCategory = category;
                        showView('subjects-view');
                        updateBreadcrumb();
                        showSubjects(examType, category);
                    });
                });
            }

            // Function to show subjects for selected exam type and category
            function showSubjects(examType, category) {
                const subjects = examResultsData.filter(result => 
                    result.exam_type === examType && result.category === category
                );

                const subjectsResults = document.getElementById('subjects-results');
                subjectsResults.innerHTML = '';

                if (subjects.length > 0) {
                    const table = document.createElement('div');
                    table.className = 'table-container';
                    table.innerHTML = `
                        <table class="responsive-table min-w-full bg-white rounded-lg shadow">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="py-2 md:py-3 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Subject</th>
                                    <th class="py-2 md:py-3 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Score</th>
                                    <th class="py-2 md:py-3 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Percentage</th>
                                    <th class="py-2 md:py-3 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Grade</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                ${subjects.map(result => {
                                    const gradeInfo = getGradeInfo(result.percentage);
                                    return `
                                        <tr class="hover:bg-gray-50">
                                            <td class="py-2 md:py-3 px-2 md:px-4 text-xs md:text-sm text-gray-900">${result.subject_name}</td>
                                            <td class="py-2 md:py-3 px-2 md:px-4 text-xs md:text-sm text-gray-900">${result.score}/${result.max_score}</td>
                                            <td class="py-2 md:py-3 px-2 md:px-4 text-xs md:text-sm text-gray-900">${result.percentage}%</td>
                                            <td class="py-2 md:py-3 px-2 md:px-4">
                                                <span class="inline-flex items-center px-2 py-0.5 md:px-2.5 md:py-0.5 rounded-full text-xs font-medium text-white ${gradeInfo.class}">
                                                    ${gradeInfo.grade}
                                                </span>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    `;
                    subjectsResults.appendChild(table);
                } else {
                    subjectsResults.innerHTML = `
                        <div class="text-center py-6 md:py-8">
                            <i class="fas fa-exclamation-triangle text-gray-400 text-3xl md:text-4xl mb-3 md:mb-4"></i>
                            <p class="text-gray-500 text-base md:text-lg">No results found for this category.</p>
                        </div>
                    `;
                }
            }

            // Event listeners for exam type cards
            document.querySelectorAll('.exam-type-card').forEach(card => {
                card.addEventListener('click', function() {
                    const et = this.getAttribute('data-exam-type');
                    if (et) {
                        currentExamType = et;
                        showingOverall = false;
                        showView('categories-view');
                        updateBreadcrumb();
                        showCategories(currentExamType);
                    }
                });
            });

            // Overall Summary card listener
            const overallCard = document.getElementById('overall-summary-card');
            if (overallCard) {
                overallCard.addEventListener('click', function() {
                    currentExamType = '';
                    currentCategory = '';
                    showingOverall = true;
                    showingReport = false;
                    showView('overall-summary-view');
                    renderOverallSummary();
                    updateBreadcrumb();
                });
            }

            // View Report card listener
            const viewReportCard = document.getElementById('view-report-card');
            if (viewReportCard) {
                viewReportCard.addEventListener('click', function() {
                    currentExamType = '';
                    currentCategory = '';
                    showingOverall = false;
                    showingReport = true;
                    showView('report-view');
                    updateBreadcrumb();
                });
            }

            // Event listeners for back buttons
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('back-btn')) {
                    const backTo = e.target.getAttribute('data-back-to');
                    if (backTo === 'exam-types') {
                        showView('exam-types-view');
                        currentExamType = '';
                        currentCategory = '';
                        showingOverall = false;
                        showingReport = false;
                        updateBreadcrumb();
                    } else if (backTo === 'categories') {
                        showView('categories-view');
                        currentCategory = '';
                        showingOverall = false;
                        showingReport = false;
                        updateBreadcrumb();
                        showCategories(currentExamType);
                    }
                }
            });

            // Render Overall Summary content
            function renderOverallSummary() {
                const container = document.getElementById('overall-summary-content');
                if (!container || !overallSummaryData) return;

                const fmt = new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 });
                const fmt2 = new Intl.NumberFormat(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                const positionText = overallSummaryData.position !== 'N/A'
                    ? `${overallSummaryData.position} out of ${overallSummaryData.total_students}`
                    : 'Not Available';
                const nextTerm = overallSummaryData.next_term_start_date
                    ? new Date(overallSummaryData.next_term_start_date).toLocaleDateString()
                    : 'Not set';

                const subjectRow = (s) => `
                    <tr>
                        <td class="py-2 px-2 md:px-4 text-xs md:text-sm">${s.subject}</td>
                        <td class="py-2 px-2 md:px-4 text-xs md:text-sm">${fmt2.format(s.percentage)}%</td>
                    </tr>`;

                container.innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6 info-grid">
                        <div class="card p-4 md:p-6">
                            <div class="space-y-2">
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Name:</span><span class="font-medium text-sm md:text-base">${overallSummaryData.student_name}</span></div>
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Class:</span><span class="font-medium text-sm md:text-base">${overallSummaryData.class_name}</span></div>
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Stream:</span><span class="font-medium text-sm md:text-base">${overallSummaryData.stream ?? ''}</span></div>
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Year:</span><span class="font-medium text-sm md:text-base">${overallSummaryData.year}</span></div>
                            </div>
                        </div>
                        <div class="card p-4 md:p-6">
                            <div class="space-y-2">
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Overall Percentage:</span><span class="font-bold text-blue-700 text-sm md:text-base">${fmt2.format(overallSummaryData.overall_percentage)}%</span></div>
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Class Position:</span><span class="font-medium text-sm md:text-base">${positionText}</span></div>
                                <div class="flex justify-between"><span class="text-gray-600 text-sm md:text-base">Next Term Starts:</span><span class="font-medium text-sm md:text-base">${nextTerm}</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                        <div class="card p-4 md:p-6">
                            <h5 class="font-semibold mb-3 md:mb-4 text-sm md:text-base">Best 5 Subjects</h5>
                            <div class="table-container">
                                <table class="responsive-table min-w-full bg-white rounded-lg">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="py-2 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Subject</th>
                                            <th class="py-2 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Percentage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${overallSummaryData.best_subjects.map(subjectRow).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card p-4 md:p-6">
                            <h5 class="font-semibold mb-3 md:mb-4 text-sm md:text-base">Weakest 5 Subjects</h5>
                            <div class="table-container">
                                <table class="responsive-table min-w-full bg-white rounded-lg">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="py-2 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Subject</th>
                                            <th class="py-2 px-2 md:px-4 text-left text-xs md:text-sm font-medium text-gray-700">Percentage</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${overallSummaryData.worst_subjects.map(subjectRow).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 md:mt-6">
                        <a href="download_report_card.php?student_id=<?php echo (int)$student_id; ?>&class_id=<?php echo (int)$class_id; ?>" target="_blank"
                           class="block w-full md:w-auto mx-auto text-center bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold px-6 py-3 md:px-8 md:py-4 rounded-xl shadow-lg transform transition-all duration-200 hover:-translate-y-0.5">
                            <i class="fas fa-file-download mr-2"></i>
                            Download My Report Card
                        </a>
                    </div>
                `;
            }
            
            // Auto-hide success/error messages after 5 seconds
            const messages = document.querySelectorAll('.bg-green-100, .bg-red-100');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.transition = 'opacity 0.5s ease';
                    message.style.opacity = '0';
                    setTimeout(() => {
                        message.remove();
                    }, 500);
                }, 5000);
            });
            
            // Password form toggle functionality
            const togglePasswordBtn = document.getElementById('toggle-password-form');
            const passwordFormContainer = document.getElementById('password-form-container');
            const cancelPasswordBtn = document.getElementById('cancel-password');
            
            if (togglePasswordBtn && passwordFormContainer) {
                togglePasswordBtn.addEventListener('click', function() {
                    passwordFormContainer.classList.toggle('hidden');
                    if (passwordFormContainer.classList.contains('hidden')) {
                        this.innerHTML = '<i class="fas fa-key mr-2"></i>Change Password';
                        this.classList.remove('bg-red-600', 'hover:bg-red-700');
                        this.classList.add('bg-blue-600', 'hover:bg-blue-700');
                    } else {
                        this.innerHTML = '<i class="fas fa-times mr-2"></i>Hide Form';
                        this.classList.remove('bg-blue-600', 'hover:bg-blue-700');
                        this.classList.add('bg-red-600', 'hover:bg-red-700');
                    }
                });
            }
            
            if (cancelPasswordBtn && passwordFormContainer) {
                cancelPasswordBtn.addEventListener('click', function() {
                    passwordFormContainer.classList.add('hidden');
                    togglePasswordBtn.innerHTML = '<i class="fas fa-key mr-2"></i>Change Password';
                    togglePasswordBtn.classList.remove('bg-red-600', 'hover:bg-red-700');
                    togglePasswordBtn.classList.add('bg-blue-600', 'hover:bg-blue-700');
                    
                    // Clear form fields
                    document.getElementById('new_password').value = '';
                    document.getElementById('confirm_password').value = '';
                });
            }
        });
    </script>
</body>
</html>