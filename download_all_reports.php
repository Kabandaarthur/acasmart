 <?php
session_start();
require('fpdf.php');

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

// Helper function to calculate overall score out of 20
function calculateOverallScore($scores, $max_scores) {
    if (empty($scores) || empty($max_scores)) return null;
    
    $total_score = 0;
    $total_max = 0;
    
    foreach ($scores as $index => $score) {
        if ($score !== null && isset($max_scores[$index]) && $max_scores[$index] > 0) {
            $total_score += $score;
            $total_max += $max_scores[$index];
        }
    }
    
    if ($total_max == 0) return null;
    return ($total_score / $total_max) * 20; // Convert to score out of 20
}

// Process exam results from database
function processExamResults($results) {
    $exam_results = [];
    while ($row = $results->fetch_assoc()) {
        $subject = $row['subject_name'];
        $exam_type = $row['exam_type'];
        $category = $row['category'];
        
        if (!isset($exam_results[$subject])) {
            $exam_results[$subject] = [
                'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']),
                'scores' => []
            ];
        }
        
        if (!isset($exam_results[$subject]['scores'][$exam_type])) {
            $exam_results[$subject]['scores'][$exam_type] = [];
        }
        
        $exam_results[$subject]['scores'][$exam_type][$category] = [
            'score' => $row['score'],
            'max_score' => $row['max_score']
        ];
    }
    
    return $exam_results;
}


// Utility Functions
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
function getOverallComment($overall_grade, $grading_scale) {
    foreach ($grading_scale as $grade => $scale) {
        if ($grade == $overall_grade) {
            return $scale['remarks'];
        }
    }
    return 'N/A';
}

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

// PDF Class Definition
class PDF extends FPDF {
    protected $school_details;

    function __construct($school_details) {
        parent::__construct();
        $this->school_details = $school_details;
    }

    function Header() {
        // Remove background color fill
        // $this->SetFillColor(211, 211, 211);
        // $this->Rect(0, 0, 210, 40, 'F');

        // Add badge if it exists - Increased size from 25x25 to 35x35
        $badge_width = 35;
        $badge_height = 35;
        if (!empty($this->school_details['badge']) && file_exists('uploads/' . basename($this->school_details['badge']))) {
            $this->Image('uploads/' . basename($this->school_details['badge']), 10, 2, $badge_width, $badge_height);
        }

        // School name and details
        $this->SetY(8);
        $this->SetTextColor(0, 0, 0);
        // Changed font from Arial to system default sans-serif
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 6, $this->school_details['school_name'], 0, 1, 'C');

        $this->SetFont('Arial', 'I', 9);
        $this->Cell(0, 5, 'Motto: ' . $this->school_details['motto'], 0, 1, 'C');

        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 5, $this->school_details['email'], 0, 1, 'C');
        $this->Cell(0, 5, $this->school_details['location'] . ' | Phone: ' . $this->school_details['phone'], 0, 1, 'C');

        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 6, 'End of Term Report - ' . $this->school_details['name'] . ' (' . $this->school_details['year'] . ')', 0, 1, 'C');

        // Add bold line after school details
        $this->SetLineWidth(0.5); // Make the line thicker
        $this->Ln(3); // Add some space before the line
        $this->Line(10, $this->GetY(), 200, $this->GetY()); // Draw line across the page
        
        $this->Ln(8); // Reduced from 15 to 8 to bring student details up
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetTextColor(0, 0, 0); // Changed from color to black
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, $this->school_details['email'], 0, 0, 'C');
    }

    function AddStudentInfo($student_details) {
        $this->SetY($this->GetY() - 2); // Changed from +5 to -2 to move details up
    
        $this->SetTextColor(0, 0, 0); // Changed from color to black
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'Student Information', 0, 1);
    
        $this->SetFont('Arial', '', 10);
        $line_height = 5;
        $label_width = 20;
        $left_col_width = 120;
        $right_col_width = 60;
    
        $x_start = $this->GetX();
        $y_start = $this->GetY();
    
        // Student details
        $left_details = [
            'Name' => $student_details['firstname'] . ' ' . $student_details['lastname'],
            'Class' => $student_details['name'],
            'Stream' => $student_details['stream']
        ];
    
        $right_details = [
            'Gender' => $student_details['gender'] ?? '',
            // 'LIN No.' => $student_details['lin_number'] ?? '',
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
                // Adjusted dimensions for the image
                $this->Image($img_path, $this->GetPageWidth() - 40, $y_start - 10, 25, 25);
            } else {
                $this->AddImagePlaceholder('');
            }
        } else {
            $this->AddImagePlaceholder('No Image');
        }
    
        $max_y = max($current_y, $y_start + 15);
        $this->SetXY($x_start, $max_y + 3);
    }
    
    private function AddImagePlaceholder($text) {
        // Only draw placeholder if text is not 'No Image'
        if ($text !== 'No Image') {
            // Adjusted dimensions for the placeholder
            $image_width = 15; 
            $image_height = 15; 
            $this->Rect($this->GetPageWidth() - $image_width - 10, $this->GetY(), $image_width, $image_height);
            $this->SetXY($this->GetPageWidth() - $image_width - 10, $this->GetY() + $image_height / 2 - 5);
            $this->Cell($image_width, 10, $text, 0, 0, 'C');
        }
    }
    function AddResultsTable($exam_categories, $exam_results, $grading_scale, $is_few_subjects = false) {
        $this->SetFillColor(220, 220, 220);
        $this->SetTextColor(0, 0, 0);

        // Compute dynamic widths to fit all columns on the page
        $left_margin = 10;
        $right_margin = 10;
        $table_width = $this->GetPageWidth() - ($left_margin + $right_margin);
        $this->SetX($left_margin);

        // Determine activity presence
        $has_activity_categories = false;
        $activity_categories = [];
        foreach ($exam_categories as $category => $exam_types) {
            if (!empty($exam_types) && $exam_types[0] === 'activity') {
                $has_activity_categories = true;
                $activity_categories[] = $category;
            }
        }

        $num_dynamic_cols = count($exam_categories) + ($has_activity_categories ? 1 : 0); // categories + Act/20 if any
        if ($num_dynamic_cols <= 0) { $num_dynamic_cols = 1; }

        // Baseline fixed widths (minimums)
        $min_subject_w = 36;
        $min_total_w = 16;
        $min_grade_w = 16;
        $min_remarks_w = 24;
        $min_teacher_w = 18;
        $min_score_w = 12;

        $fixed_total = $min_subject_w + $min_total_w + $min_grade_w + $min_remarks_w + $min_teacher_w;
        $remaining_for_scores = $table_width - $fixed_total;

        // Fallback in case of very tight space
        if ($remaining_for_scores < $num_dynamic_cols * $min_score_w) {
            // Reduce subject and remarks slightly and use a smaller font to fit
            $shrink = min(8, (int)ceil((($num_dynamic_cols * $min_score_w) - $remaining_for_scores) / 2));
            $min_subject_w = max(30, $min_subject_w - (int)ceil($shrink / 2));
            $min_remarks_w = max(18, $min_remarks_w - (int)floor($shrink / 2));
            $fixed_total = $min_subject_w + $min_total_w + $min_grade_w + $min_remarks_w + $min_teacher_w;
            $remaining_for_scores = $table_width - $fixed_total;
            $this->SetFont('Arial', 'B', 8); // slightly smaller header font
        } else {
            $this->SetFont('Arial', 'B', 9); // default readable header font
        }

        $score_col_width = max($min_score_w, floor($remaining_for_scores / $num_dynamic_cols));
        // Cap score column width for aesthetics
        $score_col_width = min($score_col_width, 22);

        // Adopt computed widths
        $header_col_width = $min_subject_w;
        $teacher_col_width = $min_teacher_w;
        $grade_col_width = $min_grade_w;
        $remarks_col_width = $min_remarks_w;
        $row_height = 8; // Increased from 7 to 8 for better spacing

        // Headers
        $header_height = 9; // Increased from 8.5 to 9 for better spacing
        $this->Cell($header_col_width, $header_height, 'SUBJECT', 1, 0, 'C', true);
        
        // Category headers with max scores
        $last_activity_category = null;
        $activity_categories_processed = 0;
        $total_activity_categories = 0;
        
        // Count total activity categories first
        foreach ($exam_categories as $category => $exam_types) {
            if (!empty($exam_types) && $exam_types[0] === 'activity') {
                $total_activity_categories++;
                $last_activity_category = $category;
            }
        }
        
        foreach ($exam_categories as $category => $exam_types) {
            // Get max score for this category from first subject that has it
            $max_score = null;
            foreach ($exam_results as $subject => $data) {
                if (isset($data['categories'][$category]['max_score'])) {
                    $max_score = $data['categories'][$category]['max_score'];
                    break;
                }
            }
            
            // Display category with max score in brackets
            $header_text = $category;
            if ($max_score !== null) {
                // Use first 3-4 characters of category + max score for compactness
                if (strlen($category) > 5) {
                    $header_text = substr($category, 0, 4) . "\n(" . $max_score . ")";
                } else {
                    $header_text = $category . "\n(" . $max_score . ")";
                }
            }
            
            $this->Cell($score_col_width, $header_height, $header_text, 1, 0, 'C', true);
            
            // Track activity categories processed
            if (!empty($exam_types) && $exam_types[0] === 'activity') {
                $activity_categories_processed++;
            }
            
            // Add Score/20 header after ALL activity categories are processed
            if ($has_activity_categories && $activity_categories_processed === $total_activity_categories && $category === $last_activity_category) {
                $this->Cell($score_col_width, $header_height, 'Act/20', 1, 0, 'C', true);
            }
        }
        
        // Additional columns
        $this->Cell($min_total_w, $header_height, 'Total', 1, 0, 'C', true);
        $this->Cell($grade_col_width, $header_height, 'GRADE', 1, 0, 'C', true);
        $this->Cell($remarks_col_width, $header_height, 'REMARKS', 1, 0, 'C', true);
        $this->Cell($teacher_col_width, $header_height, 'TEACHER', 1, 1, 'C', true);

        // Content
        $this->SetTextColor(0, 0, 0);
        // Body font: slightly smaller if many columns
        $this->SetFont('Arial', '', ($score_col_width <= $min_score_w ? 7.5 : 8.5));
        $total_subject_marks = 0;
        $total_max_marks = 0;
        $subject_count = 0;

        foreach ($exam_results as $subject => $data) {
            $this->Cell($header_col_width, $row_height, $subject, 1, 0, 'L');
            
            // Initialize teacher_initials to a default value
            $teacher_initials = 'N/A'; 

            // Track activity scores and exam scores separately
            $activity_total = 0;
            $activity_max = 0;
            $activity_count = 0;
            $exam_categories_total = 0;
            $exam_categories_max = 0;
            $has_any_score = false;

            // First pass: Count available categories and their max scores
            $expected_activity_categories = 0;
            $expected_exam_categories = 0;
            foreach ($exam_categories as $category => $exam_types) {
                if (!empty($exam_types)) {
                    if ($exam_types[0] === 'activity') {
                        $expected_activity_categories++;
                    } else {
                        $expected_exam_categories++;
                    }
                }
            }

            // Display scores by category
            $last_activity_category = null;
            $activity_categories_processed = 0;
            $total_activity_categories = 0;
            
            // Count total activity categories first
            foreach ($exam_categories as $category => $exam_types) {
                if (!empty($exam_types) && $exam_types[0] === 'activity') {
                    $total_activity_categories++;
                    $last_activity_category = $category;
                }
            }
            
            foreach ($exam_categories as $category => $exam_types) {
                $max_score = 0;
                if (isset($data['categories'][$category])) {
                    $score = $data['categories'][$category]['score'];
                    $max_score = $data['categories'][$category]['max_score'];
                    
                    if ($score !== null) {
                        $has_any_score = true;
                        // Format score based on exam type
                        if (!empty($exam_types) && $exam_types[0] === 'exam') {
                            // For exam type, round to whole number
                            $formatted_score = number_format((float)$score, 0, '.', '');
                        } else {
                            // For other types (like activity), keep one decimal point
                            $formatted_score = number_format((float)$score, 1, '.', '');
                        }
                        $this->Cell($score_col_width, $row_height, $formatted_score, 1, 0, 'C');
                        
                        // Separate tracking for activity and exam categories
                        if (!empty($exam_types) && $exam_types[0] === 'activity') {
                            $activity_total += $score;
                            $activity_max += $max_score;
                            $activity_count++;
                        } else {
                            $exam_categories_total += $score;
                            $exam_categories_max += $max_score;
                        }
                    } else {
                        // Missing score but category exists
                        $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                        if (!empty($exam_types) && $exam_types[0] === 'activity') {
                            $activity_max += $max_score;
                            $activity_count++;
                        } else {
                            $exam_categories_max += $max_score;
                        }
                    }
                } else {
                    // Category doesn't exist for this subject
                    $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                }
                
                // Track activity categories processed
                if (!empty($exam_types) && $exam_types[0] === 'activity') {
                    $activity_categories_processed++;
                }
                
                // Add Score/20 after ALL activity categories are processed
                if ($has_activity_categories && $activity_categories_processed === $total_activity_categories && $category === $last_activity_category) {
                    // Only calculate activity score if there are any activities
                    if ($activity_count > 0) {
                        if ($activity_total > 0) {
                            $score_out_of_20 = ($activity_max > 0) ? 
                                round(($activity_total / $activity_max) * 20) : 0;
                            $this->Cell($score_col_width, $row_height, (string)$score_out_of_20, 1, 0, 'C');
                        } else {
                            $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                        }
                    } else {
                        $this->Cell($score_col_width, $row_height, '-', 1, 0, 'C');
                    }
                }
            }

            // Calculate average only if the subject has any scores
            if ($has_any_score) {
                // Calculate total and round to integer
                $total = ($has_activity_categories && $activity_count > 0 ? 
                    round(($activity_total / $activity_max) * 20) : 0) + 
                    ($exam_categories_max > 0 ? 
                        round(($exam_categories_total / $exam_categories_max) * 
                        ($has_activity_categories ? 80 : 100)) : 0);
                
                // Calculate max possible total for percentage
                $max_possible_total = 100; // Always out of 100
                
                $subject_percentage = $total;

                if ($max_possible_total > 0) {
                    $total_subject_marks += $total;
                    $total_max_marks += $max_possible_total;
                    $subject_count++;
                }

                $grade = getGrade($subject_percentage, $grading_scale);
                $remarks = getRemarks($subject_percentage, $grading_scale);
                $teacher_initials = getTeacherInitials($data['teacher']);
                
                // Display total as integer
                if ($total > 0) {
                    $this->Cell($min_total_w, $row_height, (string)$total, 1, 0, 'C');
                } else {
                    $this->Cell($min_total_w, $row_height, '-', 1, 0, 'C');
                }
                $this->Cell($grade_col_width, $row_height, $grade, 1, 0, 'C');
                $this->Cell($remarks_col_width, $row_height, $remarks, 1, 0, 'C');
                $this->Cell($teacher_col_width, $row_height, $teacher_initials, 1, 1, 'C');
            } else {
                // No scores at all for this subject
                $this->Cell($min_total_w, $row_height, '-', 1, 0, 'C');
                $this->Cell($grade_col_width, $row_height, '-', 1, 0, 'C');
                $this->Cell($remarks_col_width, $row_height, '-', 1, 0, 'C');
                $this->Cell($teacher_col_width, $row_height, $teacher_initials, 1, 1, 'C');
            }
        }

        // Add extra spacing for students with fewer subjects to fill the page better
        if ($subject_count < 13) {
            // Calculate extra space based on how few subjects there are
            $extra_space = (13 - $subject_count) * 2; // Increased from 1.5 to 2
            $this->Ln($extra_space);
        } else {
            // Add some space even for many subjects
            $this->Ln(4); // Increased from 3 to 4
        }

        // Calculate overall average percentage
        $average_percentage = ($total_max_marks > 0 && $subject_count > 0) ? 
            ($total_subject_marks / $subject_count) : 0;
        
        return round($average_percentage, 1);
    }
}

// Main processing code
$school_id = $_SESSION['school_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if (!$class_id) {
    die('Invalid class ID');
}

// Get current term
$current_term_query = "SELECT id FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$term_result = $stmt->get_result();
$term_row = $term_result->fetch_assoc();
$current_term_id = $term_row['id'];

// Get school details
$school_query = "SELECT s.school_name, s.motto, s.email, s.location, s.phone, s.badge, 
                        t.name, t.year, t.next_term_start_date
                 FROM schools s
                 LEFT JOIN terms t ON s.id = t.school_id AND t.id = ?
                 WHERE s.id = ?";
$stmt = $conn->prepare($school_query);
$stmt->bind_param("ii", $current_term_id, $school_id);
$stmt->execute();
$school_result = $stmt->get_result();
$school_details = $school_result->fetch_assoc();

// Get next class information
$next_class_query = "SELECT c2.name as next_class_name 
                     FROM classes c1 
                     JOIN classes c2 ON c1.id + 1 = c2.id 
                     WHERE c1.id = ? AND c1.school_id = ?";
$stmt = $conn->prepare($next_class_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$next_class_result = $stmt->get_result();
$next_class = $next_class_result->fetch_assoc();

// Get grading scale
$grading_scale_query = "SELECT grade, min_score, max_score, remarks 
                        FROM grading_scales 
                        WHERE school_id = ? 
                        ORDER BY min_score DESC";
$stmt = $conn->prepare($grading_scale_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$grading_scale_result = $stmt->get_result();
$grading_scale = [];
while ($row = $grading_scale_result->fetch_assoc()) {
    $grading_scale[$row['grade']] = [
        'min_score' => $row['min_score'],
        'max_score' => $row['max_score'],
        'remarks' => $row['remarks']
    ];
}

// Modified query to get exam categories and types
$exam_categories_query = "SELECT DISTINCT category, exam_type 
                         FROM exams 
                         WHERE school_id = ? 
                         AND term_id = ?
                         ORDER BY category, exam_type";
$stmt = $conn->prepare($exam_categories_query);
$stmt->bind_param("ii", $school_id, $current_term_id);
$stmt->execute();
$exam_categories_result = $stmt->get_result();

// Organize exam types by category
$exam_categories = [];
$all_exam_types = [];
while ($row = $exam_categories_result->fetch_assoc()) {
    if (!isset($exam_categories[$row['category']])) {
        $exam_categories[$row['category']] = [];
    }
    $exam_categories[$row['category']][] = $row['exam_type'];
    $all_exam_types[] = $row['exam_type'];
}

// Get all students in the class
$students_query = "SELECT id, firstname, lastname 
                  FROM students 
                  WHERE class_id = ? 
                  AND school_id = ? 
                  ORDER BY lastname, firstname";
$stmt = $conn->prepare($students_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$students_result = $stmt->get_result();

// Get class name before processing students
$class_name_query = "SELECT name FROM classes WHERE id = ? AND school_id = ?";
$stmt = $conn->prepare($class_name_query);
$stmt->bind_param("ii", $class_id, $school_id);
$stmt->execute();
$class_result = $stmt->get_result();
$class_row = $class_result->fetch_assoc();
$class_name = str_replace(' ', '_', $class_row['name']); // Replace spaces with underscores

// Create temporary directory
$temp_dir = 'temp_reports_' . time();
if (!mkdir($temp_dir, 0777, true)) {
    die('Failed to create temporary directory');
}

// Process each student
$success_count = 0;
$error_count = 0;
$error_log = [];

while ($student = $students_result->fetch_assoc()) {
    try {
        $student_name = $student['lastname'] . ', ' . $student['firstname'];
        $student_details = findStudent($conn, $student_name, $class_id, $school_id);
        
        if (!$student_details) {
            throw new Exception("Could not find detailed information for student: " . $student_name);
        }

       // Modified query to get exam results with all categories
       $results_query = "
       SELECT 
           s.subject_name,
           e.exam_type,
           e.category,
           er.score,
           e.max_score,
           u.firstname AS teacher_firstname,
           u.lastname AS teacher_lastname
       FROM student_subjects ss
       JOIN subjects s ON ss.subject_id = s.subject_id
       CROSS JOIN (
           SELECT DISTINCT category, exam_type, exam_id, max_score
           FROM exams 
           WHERE school_id = ? 
           AND term_id = ?
       ) e
       INNER JOIN exam_subjects es ON es.exam_id = e.exam_id AND es.subject_id = s.subject_id
       LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
           AND er.student_id = ? 
           AND er.subject_id = s.subject_id
           AND er.school_id = ?
       LEFT JOIN (
           SELECT 
               subject_id, 
               user_id
           FROM teacher_subjects
           WHERE is_class_teacher = 0
           AND class_id = ?
       ) ts ON s.subject_id = ts.subject_id
       LEFT JOIN (
           SELECT 
               subject_id, 
               user_id
           FROM teacher_subjects
           WHERE is_class_teacher = 1
           AND class_id = ?
       ) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id
       LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id 
           AND u.role = 'teacher'
       WHERE s.class_id = ?
       AND ss.student_id = ?  -- Add filter for specific student
       ORDER BY s.subject_name, e.category, e.exam_type";
       
       $stmt = $conn->prepare($results_query);
       $stmt->bind_param("iiiiiiii", 
           $school_id,
           $current_term_id,
           $student_details['id'],
           $school_id,
           $class_id,
           $class_id,
           $class_id,
           $student_details['id']  // Add student_id parameter
       );
$stmt->execute();
$results = $stmt->get_result();

// Process results
$exam_results = [];
$subject_count = 0; // Initialize subject count
while ($row = $results->fetch_assoc()) {
    $subject = $row['subject_name'];
    $category = $row['category'];
    $exam_type = $row['exam_type'];
    
    if (!isset($exam_results[$subject])) {
        $exam_results[$subject] = [
            'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']),
            'categories' => []
        ];
        $subject_count++; // Increment subject count for each unique subject
    }
    
    if (!isset($exam_results[$subject]['categories'][$category])) {
        $exam_results[$subject]['categories'][$category] = [
            'score' => null,
            'max_score' => $row['max_score']
        ];
    }
    
    // Only update score if there is a result
    if ($row['score'] !== null) {
        $exam_results[$subject]['categories'][$category]['score'] = $row['score'];
    }
}

                                                                       // Calculate position using the same logic as AddResultsTable
           // First, we need to calculate all students' averages using the same method
           $all_students_query = "SELECT id, firstname, lastname FROM students WHERE class_id = ? AND school_id = ? ORDER BY lastname, firstname";
           $stmt = $conn->prepare($all_students_query);
           $stmt->bind_param("ii", $class_id, $school_id);
           $stmt->execute();
           $all_students_result = $stmt->get_result();
           
           $student_averages = [];
           
           while ($student_row = $all_students_result->fetch_assoc()) {
               // Get exam results for this student using the same query as above
               $student_results_query = "
               SELECT 
                   s.subject_name,
                   e.exam_type,
                   e.category,
                   er.score,
                   e.max_score,
                   u.firstname AS teacher_firstname,
                   u.lastname AS teacher_lastname
               FROM student_subjects ss
               JOIN subjects s ON ss.subject_id = s.subject_id
               CROSS JOIN (
                   SELECT DISTINCT category, exam_type, exam_id, max_score
                   FROM exams 
                   WHERE school_id = ? 
                   AND term_id = ?
               ) e
               INNER JOIN exam_subjects es ON es.exam_id = e.exam_id AND es.subject_id = s.subject_id
               LEFT JOIN exam_results er ON er.exam_id = e.exam_id 
                   AND er.student_id = ? 
                   AND er.subject_id = s.subject_id
                   AND er.school_id = ?
               LEFT JOIN (
                   SELECT 
                       subject_id, 
                       user_id
                   FROM teacher_subjects
                   WHERE is_class_teacher = 0
                   AND class_id = ?
               ) ts ON s.subject_id = ts.subject_id
               LEFT JOIN (
                   SELECT 
                       subject_id, 
                       user_id
                   FROM teacher_subjects
                   WHERE is_class_teacher = 1
                   AND class_id = ?
               ) ts_class_teacher ON s.subject_id = ts_class_teacher.subject_id
               LEFT JOIN users u ON COALESCE(ts.user_id, ts_class_teacher.user_id) = u.user_id 
                   AND u.role = 'teacher'
               WHERE s.class_id = ?
               AND ss.student_id = ?
               ORDER BY s.subject_name, e.category, e.exam_type";
               
               $stmt = $conn->prepare($student_results_query);
               $stmt->bind_param("iiiiiiii", 
                   $school_id,
                   $current_term_id,
                   $student_row['id'],
                   $school_id,
                   $class_id,
                   $class_id,
                   $class_id,
                   $student_row['id']
               );
               $stmt->execute();
               $student_results = $stmt->get_result();
               
               // Process results using the same logic as AddResultsTable
               $student_exam_results = [];
               $total_subject_marks = 0;
               $total_max_marks = 0;
               $subject_count = 0;
               
               while ($row = $student_results->fetch_assoc()) {
                   $subject = $row['subject_name'];
                   $category = $row['category'];
                   $exam_type = $row['exam_type'];
                   
                   if (!isset($student_exam_results[$subject])) {
                       $student_exam_results[$subject] = [
                           'teacher' => trim($row['teacher_firstname'] . ' ' . $row['teacher_lastname']),
                           'categories' => []
                       ];
                       $subject_count++;
                   }
                   
                   if (!isset($student_exam_results[$subject]['categories'][$category])) {
                       $student_exam_results[$subject]['categories'][$category] = [
                           'score' => null,
                           'max_score' => $row['max_score']
                       ];
                   }
                   
                   if ($row['score'] !== null) {
                       $student_exam_results[$subject]['categories'][$category]['score'] = $row['score'];
                   }
               }
               
               // Calculate average using the same logic as AddResultsTable
               foreach ($student_exam_results as $subject => $data) {
                   $activity_total = 0;
                   $activity_max = 0;
                   $activity_count = 0;
                   $exam_categories_total = 0;
                   $exam_categories_max = 0;
                   $has_any_score = false;
                   
                   // Check for activity categories
                   $has_activity_categories = false;
                   foreach ($exam_categories as $category => $exam_types) {
                       if (!empty($exam_types) && $exam_types[0] === 'activity') {
                           $has_activity_categories = true;
                           break;
                       }
                   }
                   
                   foreach ($exam_categories as $category => $exam_types) {
                       if (isset($data['categories'][$category])) {
                           $score = $data['categories'][$category]['score'];
                           $max_score = $data['categories'][$category]['max_score'];
                           
                           if ($score !== null) {
                               $has_any_score = true;
                               if (!empty($exam_types) && $exam_types[0] === 'activity') {
                                   $activity_total += $score;
                                   $activity_max += $max_score;
                                   $activity_count++;
                               } else {
                                   $exam_categories_total += $score;
                                   $exam_categories_max += $max_score;
                               }
                           } else {
                               if (!empty($exam_types) && $exam_types[0] === 'activity') {
                                   $activity_max += $max_score;
                                   $activity_count++;
                               } else {
                                   $exam_categories_max += $max_score;
                               }
                           }
                       }
                   }
                   
                   if ($has_any_score) {
                       $total = ($has_activity_categories && $activity_count > 0 ? 
                           round(($activity_total / $activity_max) * 20) : 0) + 
                           ($exam_categories_max > 0 ? 
                               round(($exam_categories_total / $exam_categories_max) * 
                               ($has_activity_categories ? 80 : 100)) : 0);
                       
                       $total_subject_marks += $total;
                       $total_max_marks += 100;
                   }
               }
               
               $average_percentage = ($total_max_marks > 0 && $subject_count > 0) ? 
                   ($total_subject_marks / $subject_count) : 0;
               
               $student_averages[] = [
                   'id' => $student_row['id'],
                   'firstname' => $student_row['firstname'],
                   'lastname' => $student_row['lastname'],
                   'average' => round($average_percentage, 1)
               ];
           }
           
           // Sort by average in descending order and find position
           usort($student_averages, function($a, $b) {
               return $b['average'] <=> $a['average'];
           });
           
           // Handle ties properly - students with same average get same position
           $student_position = 'N/A';
           $total_students = count($student_averages);
           
           for ($i = 0; $i < count($student_averages); $i++) {
               // Check if this student is the one we're looking for
               if ($student_averages[$i]['id'] == $student_details['id']) {
                   // Find the actual position considering ties
                   $current_average = $student_averages[$i]['average'];
                   $actual_position = $i + 1;
                   
                   // Look backwards to find the first student with the same average
                   for ($j = $i - 1; $j >= 0; $j--) {
                       if ($student_averages[$j]['average'] == $current_average) {
                           $actual_position = $j + 1;
                       } else {
                           break;
                       }
                   }
                   
                   $student_position = $actual_position;
                   break;
               }
           }

        // Generate PDF
        $pdf = new PDF($school_details);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        // Adjust layout based on subject count
        $is_few_subjects = ($subject_count <= 13); // Changed from 20 to 13 to match actual data range (10-14 subjects)
        if ($is_few_subjects) {
            // Adjustments for students with fewer subjects
            $pdf->SetFontSize(11); // Slightly larger base font size
        } else {
            $pdf->SetFontSize(11); // Keep the same font size for consistency
        }

        $pdf->AddStudentInfo($student_details);
        $pdf->Ln(2); // Reduced from 5 to 2 to bring the results table closer to student details

                                   // Add exam results table and get average percentage
          // Use the same layout for all students regardless of subject count for the results table
          $is_compact_layout = false; // Set to false to use the same layout for all students' result tables
          $average_percentage = $pdf->AddResultsTable($exam_categories, $exam_results, $grading_scale, $is_compact_layout);
          // Debug: Print the average percentage
          error_log("Student Average Percentage: " . $average_percentage);

        // Modify the comments query to use BETWEEN operator and add more debugging
 $comments_query = "SELECT 
 id,
 type, 
 comment,
 min_score,
 max_score 
 FROM class_comment_templates 
 WHERE school_id = ? 
 AND (class_id = ? OR class_id IS NULL)
 AND ? BETWEEN min_score AND max_score
 ORDER BY class_id DESC, type";  // Prioritize specific class comments over default

 error_log("Student: " . $student_name . " Average: " . $average_percentage);

$stmt = $conn->prepare($comments_query);
if (!$stmt) {
throw new Exception("Failed to prepare comments query: " . $conn->error);
}

$stmt->bind_param("iid", 
$school_id,
$class_id,
$average_percentage
);

if (!$stmt->execute()) {
throw new Exception("Failed to execute comments query: " . $stmt->error);
}

$comments_result = $stmt->get_result();

// Enhanced debugging
error_log("Number of comment rows found: " . $comments_result->num_rows);

// Fallback comments in case none are found
$class_teacher_comment = 'No comments available for this performance level';
$head_teacher_comment = 'No comments available for this performance level';

// Debug all rows in the table for this class
$debug_query = "SELECT id, type, min_score, max_score FROM class_comment_templates 
            WHERE school_id = ? AND (class_id = ? OR class_id IS NULL)";
$debug_stmt = $conn->prepare($debug_query);
$debug_stmt->bind_param("ii", $school_id, $class_id);
$debug_stmt->execute();
$debug_result = $debug_stmt->get_result();
error_log("All available templates for this class:");
while ($debug_row = $debug_result->fetch_assoc()) {
error_log("ID: {$debug_row['id']}, Type: {$debug_row['type']}, 
           Range: {$debug_row['min_score']} - {$debug_row['max_score']}");
}

// Process returned comments
while ($row = $comments_result->fetch_assoc()) {
error_log("Found comment: ID={$row['id']}, Type={$row['type']}, 
          Range={$row['min_score']}-{$row['max_score']}, 
          Comment=" . substr($row['comment'], 0, 30) . "...");

if ($row['type'] == 'class_teacher') {
    $class_teacher_comment = $row['comment'];
    error_log("Setting class teacher comment from ID: {$row['id']}");
} elseif ($row['type'] == 'head_teacher') {
    $head_teacher_comment = $row['comment'];
    error_log("Setting head teacher comment from ID: {$row['id']}");
}
}

        // Add position information
        $pdf->Ln(1); // Reduced from 5 to 1
        $pdf->SetFont('Arial', 'B', 10);
        
        $pdf->SetFont('Arial', '', 10);

        // Use consistent styling for all students regardless of subject count
        // First row with Overall Percentage and Grade
        $pdf->SetTextColor(0, 0, 0); // Ensure black text
        $pdf->Cell(45, 6, 'Overall Percentage:', 0, 0, 'R'); // Increased height from 5.5 to 6
        $pdf->Cell(25, 6, sprintf("%.1f%%", $average_percentage), 0, 0); // Increased height
        $pdf->Cell(30, 6, 'Overall Grade:', 0, 0, 'R'); // Increased height
        $overall_grade = getGrade($average_percentage, $grading_scale);
        $pdf->Cell(18, 6, $overall_grade, 0, 0); // Increased height
        $pdf->Cell(45, 6, 'Overall Comment:', 0, 0, 'R'); // Increased height
        $overall_comment = getOverallComment($overall_grade, $grading_scale);
        $pdf->MultiCell(0, 6, $overall_comment, 0, 'L'); // Increased line height

 // Add position information in a small table format
        $pdf->Ln(2); // Increased from 1 to 2
        
        // Create a small table for position information
        $table_width = 80; // Increased from 70 to 80
        $table_height = 7; // Increased from 6 to 7
        $x_start = $pdf->GetX();
        $y_start = $pdf->GetY();
        
        // Draw table border
        $pdf->Rect($x_start, $y_start, $table_width, $table_height);
        
        // Add label and value on the same line
        $pdf->SetXY($x_start, $y_start);
        $pdf->SetFillColor(240, 240, 240); // Light gray background
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(35, $table_height, 'Position in Class:', 1, 0, 'C', true);
        
        $pdf->SetFillColor(255, 255, 255); // White background for value
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(35, $table_height, $student_position . ' out of ' . $total_students, 1, 1, 'C', true);

        // Add extra spacing after overall percentage for students with fewer subjects
        if ($subject_count < 13) {
            $pdf->Ln(3); // Extra space
        } else {
            $pdf->Ln(1);
        }

        // Add comments and signature lines with consistent sizes
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(47, 5, 'Class Teacher\'s Comment:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $class_teacher_comment, 0, 'L');

        // Add spacing after class teacher's comment
        if ($subject_count < 13) {
            $pdf->Ln(2); // Extra space
        }

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(47, 5, 'Class Teacher Signature:', 0, 0, 'L');
        $pdf->Cell(100, 5, '____________________', 0, 1, 'L');
        
        // Add spacing after signature
        if ($subject_count < 13) {
            $pdf->Ln(4); // Extra space
        } else {
            $pdf->Ln(1);
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(47, 5, 'Head Teacher\'s Comment:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, $head_teacher_comment, 0, 'L');

        // Add spacing after head teacher's comment
        if ($subject_count < 13) {
            $pdf->Ln(2); // Extra space
        } else {
            $pdf->Ln(1);
        }

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(47, 5, 'Head Teacher Signature:', 0, 0, 'L');
        $pdf->Cell(100, 5, '____________________', 0, 1, 'L');
        
        // Add spacing after signature
        if ($subject_count < 13) {
            $pdf->Ln(5); // Extra space
        } else {
            $pdf->Ln(3);
        }

if ($school_details['name'] === 'Third Term' && $next_class) {
   
    // Table content
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(95, 5, 'Promoted To:', 1, 0, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(95, 5, $next_class['next_class_name'], 1, 1, 'L');
    
    $pdf->Ln(1); // Reduced from 3 to 1
}

// Add Next Term Start Date if available
if (!empty($school_details['next_term_start_date'])) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(40, 4, 'Next Term Begins:', 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(0, 0, 0); // Black text
    
    // Format the date
    $formatted_date = date('F j, Y', strtotime($school_details['next_term_start_date']));
    
    // Get current position
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    
    // Get the width of the date text
    $date_width = $pdf->GetStringWidth($formatted_date);
    
    // Output the date without border
    $pdf->Cell(0, 4, $formatted_date, 0, 1, 'L');
    
    // Draw the underline just for the date text width
    $pdf->Line($x, $y + 4, $x + $date_width, $y + 4);
    
    $pdf->Ln(3); // Space after next term date
}

// Use consistent grading system table for all students
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(0, 5, 'Grading System:', 0, 1, 'L');

// Set fill color and headers for Grading System
$pdf->SetFillColor(230, 230, 230);
$pdf->SetX(10);

// Headers for Grading System (Grades and Score Ranges)
foreach ($grading_scale as $grade => $scale) {
    $pdf->Cell(22, 6, $grade, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Arial', '', 9);
$pdf->SetX(10);

foreach ($grading_scale as $scale) {
    $pdf->Cell(22, 5, $scale['max_score'] . ' - ' . $scale['min_score'], 1, 0, 'C');
}

// Adjust spacing before points weight table based on subject count
if ($subject_count > 13) {
    $pdf->Ln(1); // Minimal spacing for many subjects to save space for ultra compact table
} else {
    $pdf->Ln(6); // Reduced spacing for fewer subjects to save space
}

// Section Title - Only show for students with fewer subjects
if ($subject_count <= 13) {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(80, 5, 'DESCRIPTION:', 0, 1, 'L');
    $pdf->Ln(1); // Reduced spacing to save space
}

// Performance Level Descriptions - Different versions for space optimization
if ($subject_count > 13) {
    // Omit performance levels entirely to keep to one page
    // No output here
    $spacing = 0;
} else {
    // Full descriptions for students with fewer subjects
    $descriptions = [
        '1. Exceptional' => 'Demonstrates extra ordinary level of competency by applying innovatively and creatively the acquired knowledge',
        '2. Outstanding' => 'Demonstrates high level of competency by applying the acquired knowledge and skills in real life situation',
        '3. Satisfactory' => 'Demonstrates adequate of competency by applying the acquired knowledge and skills in real life situation',
        '4. Basic' => 'Demonstrates minimum level of competency by applying the acquired knowledge and skills in real life situation',
        '5. Elementary' => 'Demonstrates below the basic level of competency by applying the acquired knowledge in real life situation'
    ];
    
    // Normal font and spacing for fewer subjects
    $pdf->SetFont('Arial', '', 9);
    $rowHeight = 6;
    $spacing = 0.75;
    
    // Print all descriptions starting from the left
    foreach ($descriptions as $level => $description) {
        $pdf->SetX(15);  // Set to left margin
        
        // Set bold font for level numbers
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(32, $rowHeight, $level . ':', 0, 0);
        
        // Set regular font for descriptions
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(155, $rowHeight, $description, 0, 1);
        
        // Small space between each description
        $pdf->Ln($spacing);
    }
}

// Adjust final spacing based on subject count
if ($subject_count > 13) {
    $pdf->Ln(0.5); // Minimal spacing for many subjects with table format
} else {
    $pdf->Ln(2); // Reduced spacing for fewer subjects
}

        // Save PDF
        $filename = $temp_dir . '/report_card_' . 
                   str_replace(' ', '_', $student['firstname'] . '_' . $student['lastname']) . 
                   '.pdf';
        $pdf->Output('F', $filename);
        
        $success_count++;
        
    } catch (Exception $e) {
        $error_count++;
        $error_log[] = "Error processing {$student_name}: " . $e->getMessage();
        continue;
    }
}

// Start output buffering at beginning of script (add this near the top)
ob_start();

// After PDF generation, create the ZIP archive
$zip = new ZipArchive();
$zipName = 'class_reports_' . $class_name . '_' . date('Y-m-d_H-i-s') . '.zip';

// Log file info before creating ZIP
error_log("Starting ZIP creation for $zipName");
error_log("Temporary directory $temp_dir contains " . count(glob($temp_dir . '/*')) . " files");

// Clean output buffer before sending headers
while (ob_get_level()) {
    ob_end_clean();
}

// Create the ZIP file with OVERWRITE flag
if ($zip->open($zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    // Add all PDFs to ZIP
    $files = glob($temp_dir . '/*');
    $file_count = 0;
    
    foreach ($files as $file) {
        if (file_exists($file) && is_file($file) && filesize($file) > 0) {
            // Add file to archive with just the basename as the archive path
            if ($zip->addFile($file, basename($file))) {
                $file_count++;
                error_log("Added to ZIP: " . basename($file) . " (" . filesize($file) . " bytes)");
            } else {
                error_log("Failed to add file to ZIP: " . basename($file));
            }
        } else {
            error_log("Invalid file for ZIP: " . $file . " - Exists: " . (file_exists($file) ? "Yes" : "No") . 
                      ", Is file: " . (is_file($file) ? "Yes" : "No") . 
                      ", Size: " . (file_exists($file) ? filesize($file) : "N/A"));
        }
    }
    
    // Close the ZIP file properly
    $result = $zip->close();
    if (!$result) {
        error_log("Failed to close ZIP file. Status: " . $zip->getStatusString());
        die("Failed to create ZIP archive properly");
    }
    
    error_log("ZIP file created successfully with $file_count files. ZIP file size: " . filesize($zipName) . " bytes");
    
    // Verify the ZIP file exists and has content
    if (file_exists($zipName) && filesize($zipName) > 0) {
        // Clean up temporary files AFTER the ZIP is successfully created
        foreach ($files as $file) {
            if (file_exists($file) && is_file($file)) {
                if (unlink($file)) {
                    error_log("Removed temp file: " . basename($file));
                } else {
                    error_log("Failed to remove temp file: " . basename($file));
                }
            }
        }
        
        if (is_dir($temp_dir) && count(glob($temp_dir . "/*")) === 0) {
            if (rmdir($temp_dir)) {
                error_log("Removed temp directory: " . $temp_dir);
            } else {
                error_log("Failed to remove temp directory: " . $temp_dir);
            }
        } else {
            error_log("Temp directory not empty or doesn't exist: " . $temp_dir);
        }
        
        // Register a shutdown function to ensure the ZIP is deleted even if script terminates unexpectedly
        register_shutdown_function(function() use ($zipName) {
            if (file_exists($zipName)) {
                @unlink($zipName);
                error_log("ZIP file deleted on shutdown: " . $zipName);
            }
        });
        
        // Set proper headers for download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipName) . '"');
        header('Content-Length: ' . filesize($zipName));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Send file in chunks to handle large files better
        $file = fopen($zipName, 'rb');
        while (!feof($file)) {
            echo fread($file, 8192);
            flush();
        }
        fclose($file);
        
        // Try to delete the ZIP file after sending
        if (file_exists($zipName)) {
            @unlink($zipName);
            error_log("ZIP file deleted after sending: " . $zipName);
        }
        
        // Log success
        error_log("Report generation complete. Successful: $success_count, Failed: $error_count");
        if ($error_count > 0) {
            error_log("Errors encountered:\n" . implode("\n", $error_log));
        }
    } else {
        error_log("ZIP file not found or empty after creation: " . $zipName);
        die("Error: ZIP file could not be created properly");
    }
} else {
    error_log("Failed to open ZIP file for writing: " . $zipName);
    die("Failed to create ZIP archive");
}

// Close database connection
$conn->close();
exit(); // Ensure no further output
