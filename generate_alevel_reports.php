 <?php
session_start();
require('fpdf.php');

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
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

$user_school_id = $_SESSION['school_id'];

// Function to calculate paper grade based on total score
function calculatePaperGrade($total) {
    // Ensure input is numeric
    $total = intval($total);
    
    if ($total >= 80) return 'D1';
    elseif ($total >= 75) return 'D2';
    elseif ($total >= 66) return 'C3';
    elseif ($total >= 60) return 'C4';
    elseif ($total >= 55) return 'C5';
    elseif ($total >= 50) return 'C6';
    elseif ($total >= 45) return 'P7';
    elseif ($total >= 35) return 'P8';
    else return 'F9';
}

// Function to calculate subject grade based on paper grade
function calculateSubjectGrade($paperGrade, $subject, $totalScore = 0) {
    // Clean the subject name to handle any extra spaces or case issues
    $cleanSubject = trim(strtoupper($subject));
    
    // ICT and GENERAL PAPER have special grading
    if ($cleanSubject === 'ICT' || $cleanSubject === 'GENERAL PAPER') {
        if ($totalScore >= 50) {
            return 'O';
        } else {
            return 'F';
        }
    }
    
    // For all other subjects, use paper grade
    switch ($paperGrade) {
        case 'D1':
        case 'D2':
            return 'A';
        case 'C3':
            return 'B';
        case 'C4':
        case 'C5':
            return 'C';
        case 'C6':
            return 'D';
        case 'P7':
            return 'E';
        case 'P8':
            return 'O';
        case 'F9':
            return 'F';
        default:
            return '';
    }
}

// Test the calculateSubjectGrade function
$test_result = calculateSubjectGrade('D2', 'GENERAL PAPER', 78);
error_log("TEST: GENERAL PAPER with 78 score should return O, got: " . $test_result);

// Function to calculate ACT score from A1 and A2
function calculateACT($a1, $a2, $a1_max = 3, $a2_max = 3) {
    // Ensure inputs are numeric
    $a1 = floatval($a1);
    $a2 = floatval($a2);
    $a1_max = floatval($a1_max);
    $a2_max = floatval($a2_max);
    
    if ($a1 > 0 && $a2 > 0) {
        // Both A1 and A2 are present - calculate average and convert to out of 20
        $a1_percentage = ($a1 / $a1_max) * 20;
        $a2_percentage = ($a2 / $a2_max) * 20;
        $average = ($a1_percentage + $a2_percentage) / 2;
        return round($average);
    } elseif ($a1 > 0) {
        // Only A1 is present - convert directly to out of 20
        return round(($a1 / $a1_max) * 20);
    } elseif ($a2 > 0) {
        // Only A2 is present - convert directly to out of 20
        return round(($a2 / $a2_max) * 20);
    }
    return 0;
}

// Function to calculate points based on subject grade
function calculatePoints($subjectGrade) {
    switch ($subjectGrade) {
        case 'A':
            return 6;
        case 'B':
            return 5;
        case 'C':
            return 4;
        case 'D':
            return 3;
        case 'E':
            return 2;
        case 'O':
            return 1;
        case 'F':
            return 0;
        default:
            return 0;
    }
}

// Function to get class teacher's comment based on points
function getClassTeacherComment($points) {
    if ($points >= 18) {
        return "Outstanding performance! Keep up this excellent work and academic discipline. Your consistent hard work and understanding across all subjects is remarkable.";
    } elseif ($points >= 15) {
        return "Very good performance! Your commitment to learning is commendable. Maintain this impressive effort and aim even higher.";
    } elseif ($points >= 12) {
        return "Good performance shown in most subjects. Continue with regular study and class participation to improve further.";
    } elseif ($points >= 9) {
        return "Fair performance with room for improvement. Focus on developing consistent study habits and seek help when needed.";
    } elseif ($points >= 6) {
        return "Performance needs attention. Create a regular study routine and attend extra support sessions to improve your grades.";
    } elseif ($points >= 4) {
        return "Significant improvement needed. Let's work together on a structured study plan with regular consultations to boost your performance.";
    } else {
        return "Critical attention required. Immediate intensive academic support and guidance needed. Parents will be contacted to discuss intervention strategies.";
    }
}

// Function to get head teacher's comment based on points
function getHeadTeacherComment($points) {
    if ($points >= 18) {
        return "Exemplary academic achievement! You set a high standard for your peers. The school is proud of your dedication and excellent results.";
    } elseif ($points >= 15) {
        return "Very impressive results! Your consistent effort and positive attitude towards learning have paid off well. Keep challenging yourself.";
    } elseif ($points >= 12) {
        return "Good progress demonstrated. Maintain your focus and push yourself to achieve even better results next term.";
    } elseif ($points >= 9) {
        return "Average performance noted. Utilize available school support systems and develop better study habits to improve your grades.";
    } elseif ($points >= 6) {
        return "Below average performance. A meeting with parents will be scheduled to discuss improvement strategies and support measures.";
    } elseif ($points >= 4) {
        return "Urgent improvement required. Parents will be contacted to implement an intensive academic support program.";
    } else {
        return "Immediate intervention required. An emergency meeting with parents and academic board will be scheduled to address critical performance concerns.";
    }
}

// Function to get teacher initials
function getTeacherInitials($fullname) {
    $names = explode(' ', trim($fullname));
    $initials = '';
    foreach ($names as $name) {
        if (!empty($name)) {
            $initials .= strtoupper(substr($name, 0, 1));
        }
    }
    return $initials;
}

// Function to find student details
function findStudent($conn, $student_name, $class_id, $school_id) {
    // Normalize the name
    $student_name = trim($student_name);
    
    // Generate name variations
    $name_variations = [
        $student_name,
        str_replace(',', ', ', $student_name),
        implode(' ', array_reverse(explode(', ', $student_name))),
        str_replace(',', ' ', $student_name)
    ];

    $student_query = "SELECT s.id, s.firstname, s.lastname, c.name, s.stream, s.image, 
                             s.gender, s.lin_number, s.admission_number
                      FROM students s
                      JOIN classes c ON s.class_id = c.id
                      WHERE (
                          REPLACE(CONCAT(TRIM(s.lastname), ', ', TRIM(s.firstname)), ' ', '') = REPLACE(?, ' ', '') OR
                          REPLACE(CONCAT(TRIM(s.firstname), ' ', TRIM(s.lastname)), ' ', '') = REPLACE(?, ' ', '')
                      )
                      AND s.class_id = ?
                      AND s.school_id = ?";

    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("ssii", 
        $name_variations[0],
        $name_variations[2],
        $class_id, 
        $school_id
    );
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// PDF Class Definition for A-Level Reports
class ALevelPDF extends FPDF {
    protected $school_details;

    function __construct($school_details) {
        parent::__construct();
        $this->school_details = $school_details;
    }

    function Header() {
        // Add badge if it exists
        $badge_width = 35;
        $badge_height = 35;
        if (!empty($this->school_details['badge']) && file_exists('uploads/' . basename($this->school_details['badge']))) {
            $this->Image('uploads/' . basename($this->school_details['badge']), 10, 2, $badge_width, $badge_height);
        }

        // School name and details
        $this->SetY(8);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 6, $this->school_details['school_name'], 0, 1, 'C');

        $this->SetFont('Arial', 'I', 9);
        $this->Cell(0, 5, 'Motto: ' . $this->school_details['motto'], 0, 1, 'C');

        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, $this->school_details['email'], 0, 1, 'C');
        $this->Cell(0, 5, $this->school_details['location'] . ' | Phone: ' . $this->school_details['phone'], 0, 1, 'C');

        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 6, 'A-LEVEL REPORT CARD - ' . $this->school_details['name'] . ' (' . $this->school_details['year'] . ')', 0, 1, 'C');

        // Add bold line after school details
        $this->SetLineWidth(0.5);
        $this->Ln(3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        
        $this->Ln(8);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, $this->school_details['email'], 0, 0, 'C');
    }

    function AddStudentInfo($student_details) {
        $this->SetY($this->GetY() - 2);
    
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'Student Information', 0, 1);
    
        $this->SetFont('Arial', '', 10);
        $line_height = 5;
        $label_width = 25;
        $left_col_width = 120;
        $right_col_width = 60;
    
        $x_start = $this->GetX();
        $y_start = $this->GetY();
    
        // Student details
        $left_details = [
            'Name' => $student_details['firstname'] . ' ' . $student_details['lastname'],
            'Class' => $student_details['class_name'],
            'Gender' => $student_details['gender']
        ];
    
        $right_details = [
            'Combination' => $student_details['stream'] ?? '',
            'Adm No.' => $student_details['admission_number'] ?? ''
        ];
    
        // Print left column
        $current_y = $y_start;
        foreach ($left_details as $label => $value) {
            $this->SetXY($x_start, $current_y);
            $this->Cell($label_width, $line_height, $label . ':', 0, 0);
            $this->Cell($left_col_width - $label_width, $line_height, $value, 0, 0);
            $current_y += $line_height;
        }
    
        // Print right column
        $current_y = $y_start;
        $right_x = $x_start + $left_col_width - 20;
        foreach ($right_details as $label => $value) {
            $this->SetXY($right_x, $current_y);
            $this->Cell($label_width, $line_height, $label . ':', 0, 0);
            $this->Cell($right_col_width - $label_width, $line_height, $value, 0, 0);
            $current_y += $line_height;
        }
    
        // Add student image
        if (!empty($student_details['image'])) {
            $img_path = 'uploads/' . basename($student_details['image']);
            if (file_exists($img_path)) {
                $this->Image($img_path, $this->GetPageWidth() - 40, $y_start - 10, 25, 25);
            }
        }
    
        $max_y = max($current_y, $y_start + 15);
        $this->SetXY($x_start, $max_y + 3);
    }

    function AddALevelResultsTable($exam_categories, $exam_results, $subjects) {
        $this->SetFillColor(240, 240, 240);
        $this->SetTextColor(0, 0, 0);
        
        $this->SetFont('Arial', 'B', 8);  // Increased header font size
        $header_height = 10;
        $row_height = 7;
        
        // Column widths - adjusted for better space utilization
        $subject_width = 40;  // Slightly reduced
        $score_width = 12;    // For A1 and A2
        $act_width = 15;      // Increased for ACT(20)
        $eot_width = 18;      // Increased for E.O.T(80)
        $total_score_width = 18;  // For total score
        $grade_width = 25;    // For grade columns
        $teacher_width = 12;  // For teacher initials
        
        // Fixed columns: Subject, A1, A2, ACT, E.O.T, TOTAL, PAPER GRADE, SUBJECT GRADE, TR
        $total_columns = 9;
        $total_width = $subject_width + ($score_width * 2) + $act_width + $eot_width + $total_score_width + ($grade_width * 2) + $teacher_width;
        $start_x = ($this->GetPageWidth() - $total_width) / 2;
        $this->SetX($start_x);
        
        // Headers with better spacing
        $this->Cell($subject_width, $header_height, 'SUBJECT', 1, 0, 'C', true);
        $this->Cell($score_width, $header_height, 'A1', 1, 0, 'C', true);
        $this->Cell($score_width, $header_height, 'A2', 1, 0, 'C', true);
        $this->Cell($act_width, $header_height, 'ACT(20)', 1, 0, 'C', true);
        $this->Cell($eot_width, $header_height, 'E.O.T(80)', 1, 0, 'C', true);
        $this->Cell($total_score_width, $header_height, 'TOTAL(100)', 1, 0, 'C', true);
        $this->Cell($grade_width, $header_height, 'PAPER GRADE', 1, 0, 'C', true);
        $this->Cell($grade_width, $header_height, 'SUBJECT GRADE', 1, 0, 'C', true);
        $this->Cell($teacher_width, $header_height, 'TR', 1, 1, 'C', true);

        // Content
        $this->SetFont('Arial', '', 8);  // Increased base font size
        $total_points = 0;
        $valid_subjects = 0;

        foreach ($subjects as $subject) {
            $this->SetX($start_x);
            $data = $exam_results[$subject] ?? ['categories' => []];
            
            // Make subject names bold and larger
            $this->SetFont('Arial', 'B', 9);  // Increased subject name font size
            $this->Cell($subject_width, $row_height, $subject, 1, 0, 'L');
            $this->SetFont('Arial', '', 8);  // Back to base font size
            
            // Get individual scores from the new structure
            $a1_score = '';
            $a2_score = '';
            $eot_score = '';
            
            foreach ($data['categories'] as $category_key => $category_data) {
                if ($category_data['score'] !== null) {
                    $category = $category_data['category'];
                    $exam_type = $category_data['exam_type'];
                    
                    if ($exam_type === 'activity') {
                        if (strtoupper($category) === 'A1') {
                            $a1_score = $category_data['score'];
                        } elseif (strtoupper($category) === 'A2') {
                            $a2_score = $category_data['score'];
                        }
                    } elseif ($exam_type === 'exam') {
                        $eot_score = $category_data['score'];
                    }
                }
            }
            
            // Display individual scores
            $this->Cell($score_width, $row_height, $a1_score ?: '', 1, 0, 'C');
            $this->Cell($score_width, $row_height, $a2_score ?: '', 1, 0, 'C');
            $this->Cell($act_width, $row_height, $data['act_score'] ?: '', 1, 0, 'C');
            $this->Cell($eot_width, $row_height, $eot_score ?: '', 1, 0, 'C');
            
            // Display calculated values
            $this->Cell($total_score_width, $row_height, $data['total_score'] ?: '', 1, 0, 'C');
            $this->Cell($grade_width, $row_height, $data['paper_grade'] ?: '', 1, 0, 'C');
            $this->Cell($grade_width, $row_height, $data['subject_grade'] ?: '', 1, 0, 'C');
            // Only show teacher initials if there's a subject grade
            $teacher_display = (!empty($data['subject_grade']) && $data['subject_grade'] !== '') ? ($data['teacher_initials'] ?: '') : '';
            $this->Cell($teacher_width, $row_height, $teacher_display, 1, 1, 'C');
            
            // Calculate points based on subject grade
            if (!empty($data['subject_grade']) && $data['subject_grade'] !== '') {
                $subject_points = calculatePoints($data['subject_grade']);
                $total_points += $subject_points;
                $valid_subjects++;
            }
        }

        $this->Ln(4);  // Added more space before total points
        
        // Total points row - black and white design, more compact
        $this->SetFont('Arial', 'B', 9);
        $this->SetX($start_x);
        $this->Cell($total_width, 6, 'TOTAL POINTS: ' . $total_points, 1, 1, 'C', false);
        $this->Ln(4);  // Added more space after total points
        
        return $total_points;
    }

    function AddGradingScales() {
        $this->Ln(0);
        
        $page_width = $this->GetPageWidth();
        $margin = 10;
        $working_width = $page_width - (2 * $margin);
        
        // Paper Grading Table
        $grade_width = 20;  // Slightly reduced width
        $grades = ['D1', 'D2', 'C3', 'C4', 'C5', 'C6', 'P7', 'P8', 'F9'];
        $ranges = ['80-100', '75-79', '66-74', '60-65', '55-59', '50-54', '45-49', '35-44', '0-34'];
        $paper_total_width = $grade_width * count($grades);
        $start_x = ($page_width - $paper_total_width) / 2;
        
        // Paper Grading Table Header
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(220, 220, 220);
        $this->SetTextColor(0, 0, 0);
        $this->SetX($start_x);
        $this->Cell($paper_total_width, 4, 'PAPER GRADING SCALE', 1, 1, 'C', true);
        
        // Paper grades row
        $this->SetX($start_x);
        foreach ($grades as $grade) {
            $this->Cell($grade_width, 4, $grade, 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Paper ranges row
        $this->SetFont('Arial', '', 7);
        $this->SetX($start_x);
        foreach ($ranges as $range) {
            $this->Cell($grade_width, 4, $range, 1, 0, 'C', false);
        }
        $this->Ln();
        
        // Space between tables
        $this->Ln(4);
        
        // Subject Grading Table
        $subject_grade_width = 22;  // Slightly reduced width
        $subject_grades = ['A', 'B', 'C', 'D', 'E', 'O', 'F'];
        $subject_ranges = ['D1-D2', 'C3', 'C4-C5', 'C6', 'P7', 'P8', 'F9'];
        $subject_total_width = $subject_grade_width * count($subject_grades);
        $start_x = ($page_width - $subject_total_width) / 2;
        
        // Subject Grading Table Header
        $this->SetFont('Arial', 'B', 8);
        $this->SetX($start_x);
        $this->Cell($subject_total_width, 4, 'SUBJECT GRADING SCALE', 1, 1, 'C', true);
        
        // Subject grades row
        $this->SetX($start_x);
        foreach ($subject_grades as $grade) {
            $this->Cell($subject_grade_width, 4, $grade, 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Subject ranges row
        $this->SetFont('Arial', '', 7);
        $this->SetX($start_x);
        foreach ($subject_ranges as $range) {
            $this->Cell($subject_grade_width, 4, $range, 1, 0, 'C', false);
        }
        
        $this->Ln(1);
    }

    function AddComments($comments) {
        $this->Ln(2);
        
        // Class Teacher's Comment
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(45, 4, 'Class Teacher\'s Comment:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->MultiCell(0, 4, $comments['class_teacher'] ?: 'No comment available', 0, 'L');
        $this->Ln(1);  // Space before signature
        $this->Cell(35, 4, 'Signature:', 0, 0);
        $this->Cell(60, 4, '____________________', 0, 1);
        
        $this->Ln(2);  // Space between comments
        
        // Head Teacher's Comment
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(45, 4, 'Head Teacher\'s Comment:', 0, 0);
        $this->SetFont('Arial', '', 8);
        $this->MultiCell(0, 4, $comments['head_teacher'] ?: 'No comment available', 0, 'L');
        $this->Ln(1);  // Space before signature
        $this->Cell(35, 4, 'Signature:', 0, 0);
        $this->Cell(60, 4, '____________________', 0, 1);
        
        $this->Ln(8);  // Increased spacing before next term date
        
        // Next Term Start Date
        if (!empty($this->school_details['next_term_start_date'])) {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 5, 'Next Term Starts On: ' . date('l, j F Y', strtotime($this->school_details['next_term_start_date'])), 0, 1, 'C');
            $this->Ln(4);  // Added extra space after the next term date
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_alevel_reports'])) {
    $class_id = intval($_POST['class_id']);
    $exam_type = $_POST['exam_type'];
    $term_id = intval($_POST['term_id']);
    
    // Get school information
    $school_query = $conn->prepare("
        SELECT s.school_name, s.motto, s.email, s.location, s.phone, s.badge, 
               t.name, t.year, t.next_term_start_date
        FROM schools s
        LEFT JOIN terms t ON s.id = t.school_id AND t.id = ?
        WHERE s.id = ?
    ");
    $school_query->bind_param("ii", $term_id, $user_school_id);
    $school_query->execute();
    $school_result = $school_query->get_result();
    $school_data = $school_result->fetch_assoc();
    
    if (!$school_data) {
        header("Location: download_alevel_reports.php?error=School information not found");
        exit();
    }
    
    // Get all A-Level students in the selected class
    $students_query = $conn->prepare("
        SELECT s.id, s.firstname, s.lastname, s.gender, s.stream, s.admission_number, s.image, c.name as class_name
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.school_id = ? AND s.class_id = ? AND (c.name LIKE '%SENIOR FIVE%' OR c.name LIKE '%SENIOR SIX%')
        ORDER BY s.firstname, s.lastname
    ");
    $students_query->bind_param("ii", $user_school_id, $class_id);
    $students_query->execute();
    $students_result = $students_query->get_result();
    
    if ($students_result->num_rows == 0) {
        header("Location: download_alevel_reports.php?error=No A-Level students found in the selected class");
        exit();
    }
    
    // Get A-Level subjects for this class
    $subjects_query = $conn->prepare("
        SELECT DISTINCT s.subject_name 
        FROM subjects s 
        WHERE s.class_id = ? AND s.school_id = ?
        ORDER BY s.subject_name
    ");
    $subjects_query->bind_param("ii", $class_id, $user_school_id);
    $subjects_query->execute();
    $subjects_result = $subjects_query->get_result();
    
    $subjects = [];
    $special_subjects = ['ICT', 'GENERAL PAPER'];
    $regular_subjects = [];
    $found_special_subjects = [];
    
    while ($subject = $subjects_result->fetch_assoc()) {
        $subject_name = $subject['subject_name'];
        if (in_array($subject_name, $special_subjects)) {
            // Track which special subjects we found to avoid duplicates
            if (!in_array($subject_name, $found_special_subjects)) {
                $found_special_subjects[] = $subject_name;
            }
        } else {
            $regular_subjects[] = $subject_name;
        }
    }
    
    // Sort regular subjects alphabetically
    sort($regular_subjects);
    
    // Add only the special subjects that were actually found in the database
    $subjects = array_merge($regular_subjects, $found_special_subjects);
    
    // Create temporary directory for reports
    $temp_dir = 'temp_reports_' . uniqid();
    if (!file_exists($temp_dir)) {
        mkdir($temp_dir, 0777, true);
    }
    
    $success_count = 0;
    $error_count = 0;
    $error_log = [];
    
    while ($student = $students_result->fetch_assoc()) {
        try {
            // Get student's exam results for both activity and exam types
            $results_query = $conn->prepare("
                SELECT 
                    s.subject_name,
                    e.category,
                    e.exam_type,
                    er.score,
                    e.max_score
                FROM student_subjects ss
                JOIN subjects s ON ss.subject_id = s.subject_id
                CROSS JOIN (
                    SELECT DISTINCT category, exam_type, exam_id, max_score
                    FROM exams 
                    WHERE school_id = ? 
                    AND term_id = ?
                    AND exam_type IN ('activity', 'exam')
                ) e
                LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
                    AND er.student_id = ? 
                    AND er.subject_id = s.subject_id
                    AND er.school_id = ?
                WHERE s.class_id = ? AND ss.student_id = ? AND s.school_id = ?
                ORDER BY s.subject_name, e.category, e.exam_type
            ");
            $results_query->bind_param("iiiiiii", 
                $user_school_id,    // school_id in CROSS JOIN
                $term_id,           // term_id in CROSS JOIN
                $student['id'],     // er.student_id in LEFT JOIN
                $user_school_id,    // er.school_id in LEFT JOIN
                $class_id,          // s.class_id in WHERE
                $student['id'],     // ss.student_id in WHERE
                $user_school_id     // s.school_id in WHERE
            );
            $results_query->execute();
            $results = $results_query->get_result();
            
            // Initialize exam_results array with all subjects for this student
            $exam_results = [];
            foreach ($subjects as $subject) {
                $exam_results[$subject] = [
                    'categories' => [],
                    'teacher_initials' => '' // Initialize teacher initials
                ];
            }
            
            // Process results if we have any
            if ($results->num_rows > 0) {
                while ($result = $results->fetch_assoc()) {
                    $subject = $result['subject_name'];
                    $category = $result['category'] ?? '';
                    $exam_type = $result['exam_type'] ?? '';
                    
                    // Create a unique key for category + exam_type combination
                    $category_key = $category . '_' . $exam_type;
                    
                    if (!isset($exam_results[$subject]['categories'][$category_key])) {
                        $exam_results[$subject]['categories'][$category_key] = [
                            'score' => null,
                            'max_score' => $result['max_score'],
                            'category' => $category,
                            'exam_type' => $exam_type
                        ];
                    }
                    
                    // Only update score if there is a result
                    if ($result['score'] !== null) {
                        $exam_results[$subject]['categories'][$category_key]['score'] = $result['score'];
                    }
                }
            }
            
            // Get teacher information for subjects
            $teacher_query = $conn->prepare("
                SELECT s.subject_name, u.firstname, u.lastname
                FROM subjects s
                LEFT JOIN teacher_subjects ts ON s.subject_id = ts.subject_id AND ts.class_id = s.class_id
                LEFT JOIN users u ON ts.user_id = u.user_id AND u.role = 'teacher'
                WHERE s.class_id = ? AND s.school_id = ?
                ORDER BY s.subject_name
            ");
            $teacher_query->bind_param("ii", $class_id, $user_school_id);
            $teacher_query->execute();
            $teacher_result = $teacher_query->get_result();
            
            // Store teacher initials for each subject
            while ($teacher_row = $teacher_result->fetch_assoc()) {
                $subject_name = $teacher_row['subject_name'];
                if (isset($exam_results[$subject_name])) {
                    $teacher_name = $teacher_row['firstname'] . ' ' . $teacher_row['lastname'];
                    $exam_results[$subject_name]['teacher_initials'] = getTeacherInitials($teacher_name);
                }
            }
            
            // Get exam categories for both activity and exam types
            $exam_categories_query = $conn->prepare("
                SELECT DISTINCT category, exam_type 
                FROM exams 
                WHERE school_id = ? 
                AND term_id = ?
                AND exam_type IN ('activity', 'exam')
                ORDER BY category, exam_type
            ");
            $exam_categories_query->bind_param("ii", $user_school_id, $term_id);
            $exam_categories_query->execute();
            $exam_categories_result = $exam_categories_query->get_result();
            
            // Organize exam types by category
            $exam_categories = [];
            while ($row = $exam_categories_result->fetch_assoc()) {
                if (!isset($exam_categories[$row['category']])) {
                    $exam_categories[$row['category']] = [];
                }
                $exam_categories[$row['category']][] = $row['exam_type'];
            }
            
            // Calculate totals and grades for each subject
            foreach ($exam_results as $subject => &$data) {
                    $eot_score = 0;
                    $eot_max = 0;
                    $a1_score = 0;
                    $a2_score = 0;
                    $a1_max = 3; // Default max score
                    $a2_max = 3; // Default max score
                    
                    foreach ($data['categories'] as $category_key => $category_data) {
                    if ($category_data['score'] !== null) {
                        $score = floatval($category_data['score']);
                        $max_score = floatval($category_data['max_score']);
                            $category = $category_data['category'];
                            $exam_type = $category_data['exam_type'];
                            
                            // Handle activity categories (A1, A2)
                            if ($exam_type === 'activity') {
                                if (strtoupper($category) === 'A1') {
                                    $a1_score = $score;
                                    $a1_max = $max_score;
                                } elseif (strtoupper($category) === 'A2') {
                                    $a2_score = $score;
                                    $a2_max = $max_score;
                                }
                            }
                            // Handle exam categories (E.O.T)
                            elseif ($exam_type === 'exam') {
                                $eot_score += $score;
                                $eot_max += $max_score;
                            }
                        }
                    }
                    
                    // Calculate ACT score from A1 and A2 with their respective max scores
                    $act_score = calculateACT($a1_score, $a2_score, $a1_max, $a2_max);
                
                // Only calculate and store values if there are actual scores
                if ($a1_score > 0 || $a2_score > 0 || $eot_score > 0) {
                // Calculate total score (ACT + EOT)
                    $final_total = $act_score + $eot_score;
                    $final_max = 20 + $eot_max; // 20 for ACT + EOT max
                
                // Store calculated values
                    $data['act_score'] = $act_score > 0 ? $act_score : '';
                    $data['eot_score'] = $eot_score > 0 ? $eot_score : '';
                    $data['total_score'] = $final_total > 0 ? $final_total : '';
                $data['total_max'] = $final_max;
                    $data['paper_grade'] = $final_total > 0 ? calculatePaperGrade($final_total) : '';
                    // Debug: Log the actual subject name being processed
                    if ($subject === 'GENERAL PAPER') {
                        error_log("DEBUG: Processing GENERAL PAPER with total score: $final_total");
                    }
                    $data['subject_grade'] = calculateSubjectGrade($data['paper_grade'], $subject, $final_total);
                    if ($subject === 'GENERAL PAPER') {
                        error_log("DEBUG: GENERAL PAPER subject grade calculated as: " . $data['subject_grade']);
                    }
                } else {
                    // No scores available, leave all fields empty
                    $data['act_score'] = '';
                    $data['eot_score'] = '';
                    $data['total_score'] = '';
                    $data['total_max'] = 0;
                    $data['paper_grade'] = '';
                    // For ICT and GENERAL PAPER, still calculate subject grade based on 0 score
                    $data['subject_grade'] = calculateSubjectGrade('', $subject, 0);
                }
            }
            
            // Generate PDF
            $pdf = new ALevelPDF($school_data);
            $pdf->AliasNbPages();
            $pdf->AddPage();
            
            $pdf->AddStudentInfo($student);
            $pdf->Ln(2);
            
            // Get the total points from the results table
            $total_points = $pdf->AddALevelResultsTable($exam_categories, $exam_results, $subjects);
            
            // Generate appropriate comments based on total points
            $comments = [
                'class_teacher' => getClassTeacherComment($total_points),
                'head_teacher' => getHeadTeacherComment($total_points)
            ];
            
            $pdf->AddComments($comments);
            $pdf->AddGradingScales();
            
            // Save PDF
            $filename = $temp_dir . '/alevel_report_' . 
                       str_replace(' ', '_', $student['firstname'] . '_' . $student['lastname']) . 
                       '.pdf';
            $pdf->Output('F', $filename);
            
            $success_count++;
            
        } catch (Exception $e) {
            $error_count++;
            $error_log[] = "Error processing " . $student['firstname'] . " " . $student['lastname'] . ": " . $e->getMessage();
        }
    }
    
    // Create ZIP file using PclZip
    require_once('pclzip/pclzip.lib.php');
    
    $zipName = 'alevel_reports_' . date('Y-m-d_H-i-s') . '.zip';
    $archive = new PclZip($zipName);
    
    $files = glob($temp_dir . '/*.pdf');
    $file_count = 0;
    
    $files_to_add = array();
    foreach ($files as $file) {
        if (file_exists($file) && is_file($file) && filesize($file) > 0) {
            $files_to_add[] = $file;
            $file_count++;
        }
    }
    
    if ($file_count > 0) {
        $result = $archive->create($files_to_add, 
                                 PCLZIP_OPT_REMOVE_PATH, $temp_dir,
                                 PCLZIP_OPT_ADD_PATH, '');
        
        if ($result == 0) {
            header("Location: download_alevel_reports.php?error=Failed to create ZIP file");
            exit();
        }
        
        // Clean up temporary files
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($temp_dir) && count(glob($temp_dir . "/*")) === 0) {
            rmdir($temp_dir);
        }
        
        // Download the ZIP file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipName));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($zipName);
        unlink($zipName);
        exit();
        
    } else {
        header("Location: download_alevel_reports.php?error=No reports were generated");
        exit();
    }
}

// If not POST request, redirect back to the form
header("Location: download_alevel_reports.php");
exit();
?>