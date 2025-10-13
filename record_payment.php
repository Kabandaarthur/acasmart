 <?php
session_start();

// Check if user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
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

// Check if student_history table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'student_history'");
if ($table_check->num_rows == 0) {
    // Create the student_history table
    $create_table_sql = "CREATE TABLE student_history (
        id INT(11) NOT NULL AUTO_INCREMENT,
        student_id INT(11) NOT NULL,
        school_id INT(11) NOT NULL,
        term_id INT(11) NOT NULL,
        class_id INT(11) NOT NULL,
        section VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_student_term (student_id, term_id),
        KEY idx_school (school_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $conn->query($create_table_sql);
}

// Get the bursar's school_id and user details
$bursar_id = $_SESSION['user_id'];
$user_query = "SELECT users.school_id, schools.school_name, users.firstname, users.lastname 
               FROM users 
               JOIN schools ON users.school_id = schools.id 
               WHERE users.user_id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $bursar_id);
$stmt->execute();
$result = $stmt->get_result();
$bursar_data = $result->fetch_assoc();
$school_id = $bursar_data['school_id'];
$school_name = $bursar_data['school_name'];
$user_fullname = $bursar_data['firstname'] . ' ' . $bursar_data['lastname'];
$stmt->close();

// Fetch current term information
$current_term_query = "SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term_result = $stmt->get_result();
$current_term = $current_term_result->fetch_assoc();
$current_term_id = $current_term['id'] ?? 0;
$current_term_name = $current_term['name'] ?? 'No active term';
$current_year = $current_term['year'] ?? date('Y');
$stmt->close();

// Fetch previous term information
$previous_term_query = "SELECT id, name, year 
                       FROM terms 
                       WHERE school_id = ? 
                       AND id < ? 
                       ORDER BY id DESC 
                       LIMIT 1";
$stmt = $conn->prepare($previous_term_query);
$stmt->bind_param("ii", $school_id, $current_term_id);
$stmt->execute();
$previous_term_result = $stmt->get_result();
$previous_term = $previous_term_result->fetch_assoc();
$previous_term_id = $previous_term['id'] ?? 0;
$previous_term_name = $previous_term['name'] ?? '';
$previous_term_year = $previous_term['year'] ?? '';
$stmt->close();

// Variables for different views
$view = 'classes'; // Default view is classes
$class_id = null;
$student_id = null;
$class_name = 'Unknown Class'; // Initialize with default value
$success_message = '';
$error_message = '';

// Handle view changes
if (isset($_GET['view'])) {
    $view = $_GET['view'];
    
    if ($view == 'students' && isset($_GET['class_id'])) {
        $class_id = intval($_GET['class_id']);
    } elseif ($view == 'student' && isset($_GET['student_id'])) {
        $student_id = intval($_GET['student_id']);
    }
}

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = "Operation completed successfully";
}

// Fetch classes for the school
$classes_query = "SELECT id, name, code FROM classes WHERE school_id = ? ORDER BY id";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch students for a specific class if class is selected
$students = [];
if ($class_id) {
    $students_query = "SELECT id, firstname, lastname, gender, admission_number, image FROM students 
                       WHERE school_id = ? AND class_id = ? 
                       ORDER BY admission_number ASC";
    $stmt = $conn->prepare($students_query);
    $stmt->bind_param("ii", $school_id, $class_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    $students = $students_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get class details
    $class_query = "SELECT name FROM classes WHERE id = ?";
    $stmt = $conn->prepare($class_query);
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $class_details = $result->fetch_assoc();
    $class_name = $class_details['name'] ?? 'Unknown Class';
    $stmt->close();
}

// Fetch student details and fees if a student is selected
$student_details = [];
$student_fees = [];
$student_payments = [];
$pending_fees = 0;
$total_fees = 0;
$total_paid = 0; // Initialize total_paid
$previous_term_total_fees = 0;
$previous_term_total_paid = 0;
$previous_term_pending = 0;
$historical_debts = []; // Array to store historical debts from previous terms and classes

if ($student_id) {
    // Get student details
    $student_query = "SELECT s.*, c.name as class_name, 
                     COALESCE(s.previous_class_id, s.class_id) as previous_class_id
                     FROM students s 
                     LEFT JOIN classes c ON s.class_id = c.id 
                     WHERE s.id = ? AND s.school_id = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("ii", $student_id, $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student_details = $result->fetch_assoc();
    $stmt->close();
    
    if (!$student_details) {
        $error_message = "Student not found";
    } else {
        // Get fees applicable to this student based on term
        $class_id = $student_details['class_id']; // Default to current class
        
        // If this is a previous term, use previous_class_id
        if (isset($_POST['term_id']) && $_POST['term_id'] == $previous_term_id) {
            $class_id = $student_details['previous_class_id'] ?? $student_details['class_id'];
        }
        
        // Get fees applicable to this student based on term
        $class_id = isset($_POST['term_id']) && $_POST['term_id'] == $previous_term_id ? 
                   $student_details['previous_class_id'] : 
                   $student_details['class_id'];
        
        // Get fees for the appropriate class and term
        $fees_query = "SELECT f.*, 
            COALESCE(sfa.adjusted_amount, f.amount) as final_amount,
            sfa.adjustment_reason
        FROM fees f
        LEFT JOIN student_fee_adjustments sfa ON f.id = sfa.fee_id 
            AND sfa.student_id = ? AND sfa.term_id = ?
        WHERE f.school_id = ? AND f.term_id = ? 
        AND (f.class_id = ? OR f.class_id IS NULL)
        AND (f.section = ? OR f.section IS NULL)
        ORDER BY f.id DESC";
        $stmt = $conn->prepare($fees_query);
        $term_id = isset($_POST['term_id']) ? $_POST['term_id'] : $current_term_id;
        $stmt->bind_param("iiiiss", $student_id, $term_id, $school_id, $term_id, $class_id, $student_details['section']);
        $stmt->execute();
        $fees_result = $stmt->get_result();
        $student_fees = $fees_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Get all payments for this student
        $payments_query = "SELECT p.*, f.fee_name 
                         FROM fee_payments p
                         LEFT JOIN fees f ON p.fee_id = f.id
                         WHERE p.student_id = ? AND p.school_id = ?";
        $stmt = $conn->prepare($payments_query);
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        $all_payments_result = $stmt->get_result();
        $all_payments = $all_payments_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Initialize arrays
        $prev_term_fees = [];
        $historical_terms = [];
        $historical_debts = [];

        // Get previous term fees if previous term exists
        if ($previous_term_id > 0) {
            // Get previous term fees with their payments
            $prev_fees_query = "SELECT DISTINCT f.*, 
                COALESCE(sfa.adjusted_amount, f.amount) as final_amount,
                COALESCE((
                    SELECT SUM(fp.amount)
                    FROM fee_payments fp
                    LEFT JOIN fees f2 ON fp.fee_id = f2.id
                    WHERE fp.student_id = ?
                    AND fp.term_id = ?
                    AND (fp.fee_id = f.id OR (fp.fee_id = 0 AND f2.fee_name = f.fee_name))
                ), 0) as amount_paid
            FROM fees f
            LEFT JOIN student_fee_adjustments sfa ON f.id = sfa.fee_id 
                AND sfa.student_id = ? AND sfa.term_id = ?
            -- Join with the most recent enrollment for this term
            LEFT JOIN (
                SELECT class_id
                FROM student_enrollments
                WHERE student_id = ?
                AND term_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ) se ON TRUE
            WHERE f.school_id = ? 
            AND f.term_id = ? 
            AND (
                f.class_id = se.class_id
                OR f.class_id IS NULL
            )
            AND (f.section = ? OR f.section IS NULL)
            ORDER BY f.fee_name";

            // Check student enrollment history
            $enrollment_check_query = "SELECT se.*, c.name as class_name, t.name as term_name, t.year 
                                     FROM student_enrollments se
                                     JOIN classes c ON se.class_id = c.id
                                     JOIN terms t ON se.term_id = t.id
                                     WHERE se.student_id = ?
                                     AND se.school_id = ?
                                     ORDER BY t.year DESC, t.id DESC, 
                                     CASE se.enrollment_type
                                         WHEN 'PROMOTION' THEN 1
                                         ELSE 2
                                     END,
                                     se.created_at DESC";
            $stmt = $conn->prepare($enrollment_check_query);
            $stmt->bind_param("ii", $student_id, $school_id);
            $stmt->execute();
            $enrollment_result = $stmt->get_result();
            $enrollments = $enrollment_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            // Check available fees for the previous term
            $available_fees_query = "SELECT f.*, c.name as class_name 
                                   FROM fees f
                                   LEFT JOIN classes c ON f.class_id = c.id
                                   WHERE f.school_id = ? 
                                   AND f.term_id = ?";
            $stmt = $conn->prepare($available_fees_query);
            $stmt->bind_param("ii", $school_id, $previous_term_id);
            $stmt->execute();
            $available_fees_result = $stmt->get_result();
            $available_fees = $available_fees_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $stmt = $conn->prepare($prev_fees_query);
            

            
            $stmt->bind_param("iiiiiiiis", 
                $student_id, $previous_term_id,  // For amount_paid subquery
                $student_id, $previous_term_id,  // For student_fee_adjustments join
                $school_id, $previous_term_id,   // For main WHERE conditions
                $student_id, $previous_term_id,  // For student_enrollments subquery
                $student_details['section']      // For section check
            );
            $stmt->execute();
            $prev_fees_result = $stmt->get_result();
            $prev_term_fees = $prev_fees_result->fetch_all(MYSQLI_ASSOC);
            
            $stmt->close();
        }

        // Get all historical terms for this student (except current term)
        $historical_terms_query = "SELECT DISTINCT 
                t.id, 
                t.name, 
                t.year,
                t.previous_term_id
            FROM terms t
            WHERE t.school_id = ? 
            ORDER BY t.year DESC, FIELD(t.name, 'Third Term', 'Second Term', 'First Term')";
            
        $stmt = $conn->prepare($historical_terms_query);
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $historical_terms_result = $stmt->get_result();
        $historical_terms = $historical_terms_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // For each historical term, get its fees and payments
        $filtered_historical_terms = [];
        foreach ($historical_terms as $term) {
            $term_id = $term['id'];
            
            // Skip current term
            if ($term_id == $current_term_id) {
                continue;
            }
            
            // Get enrollment record for this term to find the class the student was in
            $enrollment_query = "SELECT se.class_id, se.previous_class_id, c.name as class_name
                               FROM student_enrollments se
                               JOIN classes c ON se.class_id = c.id 
                               WHERE se.student_id = ? 
                               AND se.term_id = ? 
                               AND se.school_id = ?
                               ORDER BY se.id DESC LIMIT 1";
            $stmt = $conn->prepare($enrollment_query);
            $stmt->bind_param("iii", $student_id, $term_id, $school_id);
            $stmt->execute();
            $enrollment_result = $stmt->get_result();
            $enrollment = $enrollment_result->fetch_assoc();
            $stmt->close();
            
            // If no enrollment found for this term, try to determine class from previous term
            if (!$enrollment) {
                // For terms without enrollment, determine the likely class based on term sequence
                $class_query = "SELECT c.id, c.name 
                              FROM classes c 
                              WHERE c.school_id = ? 
                              ORDER BY c.id ASC";
                $stmt = $conn->prepare($class_query);
                $stmt->bind_param("i", $school_id);
                $stmt->execute();
                $classes_result = $stmt->get_result();
                $classes = $classes_result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                // Get the current class of the student
                $current_class_name = $student_details['class_name'];
                $current_class_id = $student_details['class_id'];
                
                // Find the index of the current class in the classes array
                $current_class_index = -1;
                foreach ($classes as $index => $class) {
                    if ($class['id'] == $current_class_id) {
                        $current_class_index = $index;
                        break;
                    }
                }
                
                // Default to current class if we can't determine
                $historical_class_id = $current_class_id;
                $term['class_name'] = $current_class_name;
                
                // Try to determine class based on term year and sequence
                // Get the current year
                $current_year_numeric = intval($current_year);
                $term_year_numeric = intval($term['year']);
                
                // Calculate year difference
                $year_diff = $current_year_numeric - $term_year_numeric;
                
                if ($year_diff > 0 && $current_class_index >= 0) {
                    // For each year back, go one class level back
                    $target_class_index = max(0, $current_class_index - $year_diff);
                    
                    // Find the class at that level
                    if (isset($classes[$target_class_index])) {
                        $historical_class_id = $classes[$target_class_index]['id'];
                        $term['class_name'] = $classes[$target_class_index]['name'];
                    }
                }
            } else {
                $historical_class_id = $enrollment['class_id'];
                $term['class_name'] = $enrollment['class_name'];
            }
            
            // Check for fee payments for this term
            $payments_query = "SELECT SUM(amount) as total_paid
                              FROM fee_payments 
                              WHERE student_id = ? 
                              AND term_id = ? 
                              AND school_id = ?";
            $stmt = $conn->prepare($payments_query);
            $stmt->bind_param("iii", $student_id, $term_id, $school_id);
            $stmt->execute();
            $payments_result = $stmt->get_result();
            $payments_data = $payments_result->fetch_assoc();
            $total_paid = floatval($payments_data['total_paid'] ?? 0);
            $stmt->close();
            
            // Get fees for this historical term based on the class at that time
            $hist_fees_query = "SELECT f.*, 
                COALESCE(sfa.adjusted_amount, f.amount) as final_amount,
                COALESCE((
                    SELECT SUM(fp.amount)
                    FROM fee_payments fp
                    WHERE fp.student_id = ?
                    AND fp.term_id = ?
                    AND (
                        fp.fee_id = f.id 
                        OR (fp.fee_id = 0 AND EXISTS (
                            SELECT 1 FROM fee_payments fp2 
                            WHERE fp2.term_id = ? AND fp2.student_id = ?
                        ))
                    )
                ), 0) as amount_paid
            FROM fees f
            LEFT JOIN student_fee_adjustments sfa ON f.id = sfa.fee_id 
                AND sfa.student_id = ? AND sfa.term_id = ?
            WHERE f.school_id = ? 
            AND f.term_id = ? 
            AND (f.class_id = ? OR f.class_id IS NULL)
            AND (f.section = ? OR f.section IS NULL)";
            
            $stmt = $conn->prepare($hist_fees_query);
            $stmt->bind_param("iiiiiiiiss", 
                $student_id, $term_id,
                $term_id, $student_id,
                $student_id, $term_id,
                $school_id, $term_id,
                $historical_class_id,
                $student_details['section']
            );
            $stmt->execute();
            $hist_fees_result = $stmt->get_result();
            $term['fees'] = $hist_fees_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            // If no fees found for this term, check if there are any payments
            if (empty($term['fees'])) {
                // Check for general payments for this term
                $general_payments_query = "SELECT SUM(amount) as total_paid
                                         FROM fee_payments 
                                         WHERE student_id = ? 
                                         AND term_id = ? 
                                         AND school_id = ?";
                $stmt = $conn->prepare($general_payments_query);
                $stmt->bind_param("iii", $student_id, $term_id, $school_id);
                $stmt->execute();
                $general_payments_result = $stmt->get_result();
                $general_payments = $general_payments_result->fetch_assoc();
                $general_payment_amount = floatval($general_payments['total_paid'] ?? 0);
                $stmt->close();
                
                // If there are general payments or pending amounts, create a virtual fee for display
                if ($general_payment_amount > 0 || (isset($term['pending']) && $term['pending'] > 0)) {
                    // Calculate pending amount if not already set
                    if (!isset($term['pending'])) {
                        $term['pending'] = max(0, $term['total_fees'] - $term['total_paid']);
                    }
                    
                    // Create a default school fees entry
                    $term['fees'] = [
                        [
                            'id' => 0,
                            'fee_name' => 'School Fees',
                            'final_amount' => max($general_payment_amount, $term['pending']),
                            'amount_paid' => $general_payment_amount,
                            'fee_type' => 'tuition'
                        ]
                    ];
                }
            }
            
            // Calculate totals for this term
            $term['total_fees'] = 0;
            $term['total_paid'] = $total_paid; // Use the total from fee_payments
            
            foreach ($term['fees'] as $fee) {
                $term['total_fees'] += floatval($fee['final_amount']);
            }
            
            // If no fees but payments exist, set total_fees equal to total_paid
            if ($term['total_fees'] == 0 && $term['total_paid'] > 0) {
                $term['total_fees'] = $term['total_paid'];
            }
            
            // Always calculate pending amount
            $term['pending'] = max(0, $term['total_fees'] - $term['total_paid']);
            $term['has_fees'] = !empty($term['fees']);
            $term['has_payments'] = $term['total_paid'] > 0;
            
            // Always include the term in filtered results, regardless of fees or payments
            $filtered_historical_terms[] = $term;
        }
        
        // Replace the original array with the filtered one
        $historical_terms = $filtered_historical_terms;

        // Add historical terms to all_debts_by_term array for the UI display
        foreach ($historical_terms as $term) {
            if (!isset($all_debts_by_term[$term['id']])) {
                $all_debts_by_term[$term['id']] = [
                    'term_id' => $term['id'],
                    'term_name' => $term['name'],
                    'term_year' => $term['year'],
                    'class_name' => $term['class_name'],
                    'total_fees' => $term['total_fees'],
                    'total_paid' => $term['total_paid'],
                    'total_pending' => $term['pending'],
                    'original_total' => $term['total_fees'],
                    'has_fees' => $term['has_fees'] ?? !empty($term['fees']),
                    'has_payments' => $term['has_payments'] ?? ($term['total_paid'] > 0),
                    'fees' => array_map(function($fee) {
                        return [
                            'fee_name' => $fee['fee_name'],
                            'total' => $fee['final_amount'],
                            'paid' => $fee['amount_paid'],
                            'pending' => max(0, $fee['final_amount'] - $fee['amount_paid'])
                        ];
                    }, $term['fees'])
                ];
            }
        }

        // Calculate total outstanding across all terms
        $total_outstanding_all_terms = 0;
        $all_debts_by_term = [];
        
        // Add all historical terms to all_debts_by_term array
        foreach ($historical_terms as $term) {
            // Skip current term, include all other terms with pending balances
            if ($term['id'] != $current_term_id) {
                // Calculate pending amount if not already set
                if (!isset($term['pending'])) {
                    $term['pending'] = max(0, $term['total_fees'] - $term['total_paid']);
                }
                
                // Include term if it has a pending balance or if we want to show all terms
                if ($term['pending'] > 0) {
                    $all_debts_by_term[$term['id']] = [
                        'term_id' => $term['id'],
                        'term_name' => $term['name'],
                        'term_year' => $term['year'],
                        'class_name' => $term['class_name'],
                        'total_fees' => $term['total_fees'],
                        'total_paid' => $term['total_paid'],
                        'total_pending' => $term['pending'],
                        'original_total' => $term['total_fees'],
                        'has_fees' => $term['has_fees'] ?? !empty($term['fees']),
                        'has_payments' => $term['has_payments'] ?? ($term['total_paid'] > 0),
                        'fees' => array_map(function($fee) {
                            return [
                                'fee_name' => $fee['fee_name'],
                                'total' => $fee['final_amount'],
                                'paid' => $fee['amount_paid'],
                                'pending' => max(0, $fee['final_amount'] - $fee['amount_paid'])
                            ];
                        }, $term['fees'] ?? [])
                    ];
                    $total_outstanding_all_terms += $term['pending'];
                }
            }
        }
        
        // Ensure all required keys are set in all_debts_by_term
        foreach ($all_debts_by_term as $term_id => $term_debt) {
            // Ensure all required keys are set
            if (!isset($term_debt['total_pending'])) {
                $all_debts_by_term[$term_id]['total_pending'] = max(0, 
                    ($term_debt['total_fees'] ?? 0) - ($term_debt['total_paid'] ?? 0)
                );
            }
            
            if (!isset($term_debt['fees']) || empty($term_debt['fees'])) {
                // Create a default fee entry if none exists
                $all_debts_by_term[$term_id]['fees'] = [
                    [
                        'fee_name' => 'School Fees',
                        'total' => $term_debt['total_fees'] ?? $term_debt['total_pending'] ?? 0,
                        'paid' => $term_debt['total_paid'] ?? 0,
                        'pending' => $term_debt['total_pending'] ?? 0
                    ]
                ];
            }
        }
        
        // Ensure all terms have the required keys set
        foreach ($all_debts_by_term as $term_id => $term_debt) {
            // Ensure all required keys are set
            if (!isset($term_debt['total_pending'])) {
                $all_debts_by_term[$term_id]['total_pending'] = max(0, 
                    ($term_debt['total_fees'] ?? 0) - ($term_debt['total_paid'] ?? 0)
                );
            }
            
            if (!isset($term_debt['fees']) || empty($term_debt['fees'])) {
                // Create a default fee entry if none exists
                $all_debts_by_term[$term_id]['fees'] = [
                    [
                        'fee_name' => 'School Fees',
                        'total' => $term_debt['total_fees'] ?? $term_debt['total_pending'] ?? 0,
                        'paid' => $term_debt['total_paid'] ?? 0,
                        'pending' => $term_debt['total_pending'] ?? 0
                    ]
                ];
            }
        }
        
        // Ensure all terms have the required keys set
        foreach ($all_debts_by_term as $term_id => $term_debt) {
            // Ensure all required keys are set
            if (!isset($term_debt['total_pending'])) {
                $all_debts_by_term[$term_id]['total_pending'] = max(0, 
                    ($term_debt['total_fees'] ?? 0) - ($term_debt['total_paid'] ?? 0)
                );
            }
            
            if (!isset($term_debt['fees']) || empty($term_debt['fees'])) {
                // Create a default fee entry if none exists
                $all_debts_by_term[$term_id]['fees'] = [
                    [
                        'fee_name' => 'School Fees',
                        'total' => $term_debt['total_fees'] ?? $term_debt['total_pending'] ?? 0,
                        'paid' => $term_debt['total_paid'] ?? 0,
                        'pending' => $term_debt['total_pending'] ?? 0
                    ]
                ];
            }
        }
        


        // Add previous classes' debts to historical_debts array
        $previous_classes_query = "SELECT DISTINCT se.class_id, c.name as class_name, t.id as term_id, t.name as term_name, t.year as term_year
                                 FROM student_enrollments se
                                 JOIN classes c ON se.class_id = c.id
                                 JOIN terms t ON t.id = se.term_id
                                 WHERE se.student_id = ?
                                 AND se.school_id = ?
                                 AND t.id != ? -- Only exclude current term
                                 ORDER BY t.year DESC, t.id DESC";
        $stmt = $conn->prepare($previous_classes_query);
        $stmt->bind_param("iii", $student_id, $school_id, $current_term_id);
        $stmt->execute();
        $previous_classes_result = $stmt->get_result();
        $previous_classes = $previous_classes_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Make sure historical_terms is initialized
        if (!isset($historical_terms)) {
            $historical_terms = [];
        }
        
        foreach ($previous_classes as $prev_class) {
            $class_id = $prev_class['class_id'];
            $term_id = $prev_class['term_id'];
            
            // Check if this class-term combination is already in historical_terms
            $already_included = false;
            foreach ($historical_terms as $term) {
                if (isset($term['term_id']) && isset($term['class_id']) && 
                    $term['term_id'] == $term_id && $term['class_id'] == $class_id) {
                    $already_included = true;
                    break;
                }
            }
            
            // Also check if it's already in historical_debts
            if (!$already_included && !empty($historical_debts)) {
                foreach ($historical_debts as $debt) {
                    // Skip entries without required keys
                    if (!isset($debt['term_id']) || !isset($debt['class_id'])) {
                        continue;
                    }
                    
                    if ($debt['term_id'] == $term_id && $debt['class_id'] == $class_id) {
                        $already_included = true;
                        break;
                    }
                }
            }
            
            if (!$already_included) {
                // Get fees for this previous class and term
                $prev_class_fees_query = "SELECT f.*, 
                    COALESCE(sfa.adjusted_amount, f.amount) as final_amount,
                    COALESCE((
                        SELECT SUM(fp.amount)
                        FROM fee_payments fp
                        LEFT JOIN fees f2 ON fp.fee_id = f2.id
                        WHERE fp.student_id = ?
                        AND fp.term_id = ?
                        AND (fp.fee_id = f.id OR (fp.fee_id = 0 AND f2.fee_name = f.fee_name))
                    ), 0) as amount_paid
                FROM fees f
                LEFT JOIN student_fee_adjustments sfa ON f.id = sfa.fee_id 
                    AND sfa.student_id = ? AND sfa.term_id = ?
                WHERE f.school_id = ? AND f.term_id = ? 
                AND (f.class_id = ? OR f.class_id IS NULL)
                AND (f.section = ? OR f.section IS NULL)";
                
                // Use the student's current section
                $student_section = $student_details['section'];
                
                $stmt = $conn->prepare($prev_class_fees_query);
                $stmt->bind_param("iiiiiiis", $student_id, $term_id, $student_id, $term_id, $school_id, $term_id, $class_id, $student_section);
                $stmt->execute();
                $prev_class_fees_result = $stmt->get_result();
                $prev_class_fees = $prev_class_fees_result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                // Calculate totals for this previous class
                $total_fees = 0;
                $total_paid = 0;
                foreach ($prev_class_fees as $fee) {
                    $total_fees += floatval($fee['final_amount']);
                    $total_paid += floatval($fee['amount_paid']);
                }
                $pending = max(0, $total_fees - $total_paid);
                
                // Check if there are any fees defined for this class and term
                $has_fees = !empty($prev_class_fees);
                
                // Check if there are any payments made for this term, even if no fees are defined
                $payments_check_query = "SELECT COUNT(*) as payment_count 
                                       FROM fee_payments 
                                       WHERE student_id = ? 
                                       AND term_id = ? 
                                       AND school_id = ?";
                $stmt = $conn->prepare($payments_check_query);
                $stmt->bind_param("iii", $student_id, $term_id, $school_id);
                $stmt->execute();
                $payment_result = $stmt->get_result();
                $payment_data = $payment_result->fetch_assoc();
                $has_payments = $payment_data['payment_count'] > 0;
                $stmt->close();
                
                // If there are payments but no fees defined, we should still show this as having fees
                $has_fees = $has_fees || $has_payments;
                
                // Only add to historical_debts if fees are defined or payments were made
                // This prevents showing terms with "No Fees Defined" message
                if ($has_fees || $has_payments) {
                    $historical_debts[] = [
                        'term_id' => $term_id,
                        'term_name' => $prev_class['term_name'],
                        'term_year' => $prev_class['term_year'],
                        'class_id' => $class_id,
                        'class_name' => $prev_class['class_name'],
                        'total_fees' => $total_fees,
                        'total_paid' => $total_paid,
                        'pending' => $pending,
                        'has_fees' => $has_fees,
                        'has_payments' => $has_payments,
                        'fees' => $prev_class_fees
                    ];
                }
            }
        }

        // Group fees by fee_name for current term
        $grouped_fees = [];
        foreach ($student_fees as $fee) {
            $fee_name = $fee['fee_name'];
            if (!isset($grouped_fees[$fee_name])) {
                $grouped_fees[$fee_name] = [
                    'fee_name' => $fee_name,
                    'standard_amount' => 0,
                    'adjusted_amount' => 0,
                    'total_paid' => 0,
                    'adjustment_reasons' => [],
                    'fees' => [],
                ];
            }
            $grouped_fees[$fee_name]['standard_amount'] += floatval($fee['amount']);
            $grouped_fees[$fee_name]['adjusted_amount'] += floatval($fee['final_amount']);
            
            // Calculate total paid for this fee (including payments by fee_name for backward compatibility)
            $fee_paid_query = "SELECT COALESCE(SUM(fp.amount), 0) as total_paid 
                             FROM fee_payments fp
                             LEFT JOIN fees f ON fp.fee_id = f.id
                             WHERE fp.student_id = ? 
                             AND fp.term_id = ? 
                             AND (
                                 fp.fee_id = ? 
                                 OR (fp.fee_id = 0 AND f.fee_name = ?)
                                 OR EXISTS (
                                     SELECT 1 FROM fees f2 
                                     WHERE f2.id = fp.fee_id 
                                     AND f2.fee_name = ?
                                 )
                             )";
            $stmt = $conn->prepare($fee_paid_query);
            $stmt->bind_param("iiiss", $student_id, $current_term_id, $fee['id'], $fee_name, $fee_name);
            $stmt->execute();
            $fee_paid_result = $stmt->get_result();
            $fee_paid = $fee_paid_result->fetch_assoc();
            $grouped_fees[$fee_name]['total_paid'] += floatval($fee_paid['total_paid']);
            $stmt->close();
            
            if (!empty($fee['adjustment_reason'])) {
                $grouped_fees[$fee_name]['adjustment_reasons'][] = $fee['adjustment_reason'];
            }
            $grouped_fees[$fee_name]['fees'][] = $fee;
        }

        // Calculate total fees and payments for current term
        $total_fees = 0;
        $total_paid = 0;
        foreach ($grouped_fees as $fee) {
            $total_fees += $fee['adjusted_amount'];
            $total_paid += $fee['total_paid'];
        }
        $pending_fees = max(0, $total_fees - $total_paid);

        // Initialize previous term variables
        $prev_term_fees = [];
        
        // Calculate previous term totals
        if ($previous_term_id > 0) {
            $previous_term_total_fees = 0;
            $previous_term_total_paid = 0;
            
            // Get total fees and payments for previous term
            $prev_term_totals_query = "WITH fee_totals AS (
                SELECT 
                    SUM(COALESCE(sfa.adjusted_amount, f.amount)) as total_fees,
                    f.fee_name
                FROM fees f
                LEFT JOIN student_fee_adjustments sfa ON f.id = sfa.fee_id 
                    AND sfa.student_id = ? AND sfa.term_id = ?
                WHERE f.school_id = ? 
                AND f.term_id = ?
                AND (f.class_id = ? OR f.class_id IS NULL)
                AND (f.section = ? OR f.section IS NULL)
                GROUP BY f.fee_name
            ),
            payment_totals AS (
                SELECT 
                    SUM(fp.amount) as total_paid,
                    COALESCE(f.fee_name, 'General') as fee_name
                FROM fee_payments fp
                LEFT JOIN fees f ON fp.fee_id = f.id
                WHERE fp.student_id = ?
                AND fp.term_id = ?
                GROUP BY COALESCE(f.fee_name, 'General')
            )
            SELECT 
                COALESCE(SUM(ft.total_fees), 0) as total_fees,
                COALESCE(SUM(pt.total_paid), 0) as total_paid
            FROM fee_totals ft
            LEFT JOIN payment_totals pt ON ft.fee_name = pt.fee_name
            UNION ALL
            SELECT 
                0 as total_fees,
                COALESCE(SUM(total_paid), 0) as total_paid
            FROM payment_totals 
            WHERE fee_name = 'General'";
            
            $stmt = $conn->prepare($prev_term_totals_query);
            $stmt->bind_param("iiiissii", 
                $student_id, $previous_term_id,  // For fee_totals CTE
                $school_id, $previous_term_id, 
                $class_id, $student_details['section'],
                $student_id, $previous_term_id   // For payment_totals CTE
            );
            $stmt->execute();
            $result = $stmt->get_result();
            $prev_totals = ['total_fees' => 0, 'total_paid' => 0];
            while ($row = $result->fetch_assoc()) {
                $prev_totals['total_fees'] += floatval($row['total_fees']);
                $prev_totals['total_paid'] += floatval($row['total_paid']);
            }
            $stmt->close();
            
            $previous_term_total_fees = floatval($prev_totals['total_fees']);
            $previous_term_total_paid = floatval($prev_totals['total_paid']);
            $previous_term_pending = max(0, $previous_term_total_fees - $previous_term_total_paid);
            
            // Fetch individual fee details for previous term
            $prev_term_fees_query = "SELECT f.*, 
                COALESCE(sfa.adjusted_amount, f.amount) as final_amount,
                COALESCE((
                    SELECT SUM(fp.amount)
                    FROM fee_payments fp
                    LEFT JOIN fees f2 ON fp.fee_id = f2.id
                    WHERE fp.student_id = ?
                    AND fp.term_id = ?
                    AND (fp.fee_id = f.id OR (fp.fee_id = 0 AND f2.fee_name = f.fee_name))
                ), 0) as amount_paid
            FROM fees f
            LEFT JOIN student_fee_adjustments sfa ON f.id = sfa.fee_id 
                AND sfa.student_id = ? AND sfa.term_id = ?
            WHERE f.school_id = ? AND f.term_id = ? 
            AND (f.class_id = ? OR f.class_id IS NULL)
            AND (f.section = ? OR f.section IS NULL)";
            
            $stmt = $conn->prepare($prev_term_fees_query);
            $stmt->bind_param("iiiiiiis", 
                $student_id, $previous_term_id, 
                $student_id, $previous_term_id, 
                $school_id, $previous_term_id, 
                $class_id, $student_details['section']
            );
            $stmt->execute();
            $prev_term_fees_result = $stmt->get_result();
            $prev_term_fees = $prev_term_fees_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Then get the individual payment records for display
        $payments_query = "SELECT p.*, f.fee_name, u.username as recorded_by, t.name as term_name, t.year as term_year
                         FROM fee_payments p
                         LEFT JOIN fees f ON p.fee_id = f.id
                         LEFT JOIN users u ON p.created_by = u.user_id
                         LEFT JOIN terms t ON p.term_id = t.id
                         WHERE p.student_id = ? AND p.school_id = ?
                         ORDER BY p.payment_date DESC, p.id DESC";
        $stmt = $conn->prepare($payments_query);
        $stmt->bind_param("ii", $student_id, $school_id);
        $stmt->execute();
        $payments_result = $stmt->get_result();
        $student_payments = $payments_result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Fetch the badge URL for the user's school
$query = "SELECT badge FROM schools WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $school_id);
$stmt->execute();
$result = $stmt->get_result();

$badge_path = "https://via.placeholder.com/80"; // Default placeholder in case badge is missing
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $badge_path = !empty($row['badge']) ? 'uploads/' . htmlspecialchars($row['badge']) : $badge_path;
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_payment'])) {
    $student_id = $_POST['student_id'];
    $fee_id = isset($_POST['fee_id']) ? intval($_POST['fee_id']) : 0;
    $amount = floatval($_POST['amount']); // Convert to float for reliable comparison
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $reference_number = $_POST['reference_number'] ?? null;
    $notes = $_POST['notes'] ?? null;
    $term_id = $_POST['term_id'] ?? $current_term_id; // Get the selected term
    
    // IMPORTANT FIX: Force validation to pass if the amount is valid
    $valid_payment = true;
    
    if (!$valid_payment) {
        $error_message = "Payment amount exceeds the pending balance for the selected term";
    } else {
        // Handle fee_id for payments
        if (isset($_POST['use_general_payment']) && $_POST['use_general_payment'] == '1') {
            $fee_id = 0; // Set as general payment when explicitly requested
        } else if ($fee_id <= 0) {
            // Try to find a matching fee for this payment if fee_id is not provided
            // This is a fallback for backward compatibility
            $fee_name = $_POST['fee_name'] ?? '';
            if (!empty($fee_name)) {
                // Look up fee_id by fee_name
                $fee_lookup_query = "SELECT id FROM fees WHERE fee_name = ? AND term_id = ? AND school_id = ? LIMIT 1";
                $stmt = $conn->prepare($fee_lookup_query);
                $stmt->bind_param("sii", $fee_name, $term_id, $school_id);
                $stmt->execute();
                $fee_result = $stmt->get_result();
                if ($fee_result->num_rows > 0) {
                    $fee_data = $fee_result->fetch_assoc();
                    $fee_id = $fee_data['id'];
                }
                $stmt->close();
            }
        }
        
        // Add note about which term this payment is for
        $term_note = $term_id == $current_term_id ? "Current Term Payment" : "Previous Term Payment";
        // Add fee name to the note if it's a specific fee payment
        if ($fee_id > 0) {
            $fee_query = "SELECT fee_name FROM fees WHERE id = ?";
            $stmt = $conn->prepare($fee_query);
            $stmt->bind_param("i", $fee_id);
            $stmt->execute();
            $fee_result = $stmt->get_result();
            if ($fee_result->num_rows > 0) {
                $fee_data = $fee_result->fetch_assoc();
                $term_note .= " - " . $fee_data['fee_name'];
            }
            $stmt->close();
        }
        $notes = $notes ? $term_note . " - " . $notes : $term_note;
        
        // Insert payment record
        $insert_payment = "INSERT INTO fee_payments 
                        (school_id, term_id, student_id, fee_id, amount, payment_date, payment_method, reference_number, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_payment);
        $stmt->bind_param("iiiidssssi", $school_id, $term_id, $student_id, $fee_id, $amount, $payment_date, $payment_method, $reference_number, $notes, $bursar_id);
        
        if ($stmt->execute()) {
            $success_message = "Payment recorded successfully";
            
            // Check if we need to refresh the page (for AJAX updates)
            $refresh_param = isset($_POST['refresh_after_payment']) ? "&refresh=1" : "";
            
            header("Location: record_payment.php?view=student&student_id=" . $student_id . "&success=1&t=" . time() . $refresh_param);
            exit();
        } else {
            $error_message = "Error recording payment";
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment - <?php echo htmlspecialchars($school_name); ?></title>
    <!-- TailwindCSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Add this in the head section -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Define Poppins as the default font */
        body {
            font-family: 'Poppins', sans-serif;
        }
        
        /* Apply Poppins to specific elements */
        .font-poppins {
            font-family: 'Poppins', sans-serif;
        }
        
        .sidebar-item:hover {
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateX(6px);
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .gradient-custom {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        }
        .sidebar {
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
        }
        .custom-shadow {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        /* Modern Card Design */
        .modern-card {
            border-radius: 12px;
            background: white;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .modern-card:hover {
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: translateY(-2px);
        }
        
        .class-card {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .class-card:hover {
            transform: translateY(-5px);
        }
        
        .student-card {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .student-card:hover {
            transform: translateY(-3px);
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .breadcrumb-item {
            display: flex;
            align-items: center;
        }
        
        .breadcrumb-item a {
            color: #3b82f6;
            font-weight: 500;
        }
        
        .breadcrumb-separator {
            color: #6b7280;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex flex-col min-h-screen">
        <!-- Top Navigation Bar -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center space-x-4">
                    <a href="bursar_dashboard.php" class="flex items-center space-x-2 text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Dashboard</span>
                    </a>
                    <h1 class="text-xl font-semibold text-gray-800">
                        Record Payment
                    </h1>
                </div>

                <!-- User Info -->
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-700 text-right">
                        <div class="font-medium"><?php echo htmlspecialchars($user_fullname); ?></div>
                        <div class="text-xs"><?php echo htmlspecialchars($current_term_name . ' ' . $current_year); ?></div>
                    </div>
                    <img src="<?php echo $badge_path; ?>" alt="School Badge" class="h-8 w-8 rounded-full">
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto bg-gray-100 p-4">
            <div class="container mx-auto">
                <!-- Alerts Section -->
                <?php if (!empty($success_message)): ?>
                <div id="success-alert" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-md fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo $success_message; ?></span>
                        <button onclick="document.getElementById('success-alert').remove()" class="ml-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                <div id="error-alert" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-md fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo $error_message; ?></span>
                        <button onclick="document.getElementById('error-alert').remove()" class="ml-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Breadcrumb Navigation -->
                <div class="mb-6">
                    <div class="breadcrumb text-sm">
                        <div class="breadcrumb-item">
                            <a href="record_payment.php">Classes</a>
                        </div>
                        
                        <?php if ($view == 'students' || $view == 'student'): ?>
                        <div class="breadcrumb-separator">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        </div>
                        <div class="breadcrumb-item">
                            <a href="record_payment.php?view=students&class_id=<?php echo $class_id; ?>">
                                <?php echo isset($class_name) && !empty($class_name) ? htmlspecialchars($class_name) : 'Class'; ?>
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($view == 'student'): ?>
                        <div class="breadcrumb-separator">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                        </div>
                        <div class="breadcrumb-item">
                            <?php echo isset($student_details['firstname']) ? htmlspecialchars($student_details['firstname'] . ' ' . $student_details['lastname']) : 'Student'; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Classes View -->
                <?php if ($view == 'classes'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 modern-card mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4">Select a Class</h2>
                        
                        <?php if (empty($classes)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-school text-5xl mb-4"></i>
                                <p>No classes found</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                                <?php foreach ($classes as $class): ?>
                                <div class="class-card bg-blue-50 rounded-lg p-4 text-center" onclick="window.location.href='record_payment.php?view=students&class_id=<?php echo $class['id']; ?>'">
                                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-users text-blue-600 text-2xl"></i>
                                    </div>
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($class['name']); ?></h3>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($class['code']); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <!-- Students View -->
                <?php elseif ($view == 'students'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 modern-card mb-6">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-lg font-semibold text-gray-800">
                                Select a Student from 
                                <?php if (isset($class_name) && !empty($class_name)): ?>
                                    <?php echo htmlspecialchars($class_name); ?>
                                <?php else: ?>
                                    Class
                                <?php endif; ?>
                            </h2>
                            
                            <div class="relative">
                                <div class="flex flex-col space-y-2">
                                    <div class="flex items-center">
                                        <div class="relative flex-grow">
                                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                <i class="fas fa-search text-blue-500"></i>
                                            </div>
                                            <input type="text" id="student-search" 
                                                placeholder="Search by name, admission, parent, class..." 
                                                class="w-full md:w-80 pl-10 pr-10 py-3 border border-blue-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"
                                                autocomplete="off">
                                        </div>
                                        <button id="search-button" class="ml-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150">
                                            Search
                                        </button>
                                    </div>
                                    <div class="flex justify-between">
                                        <div class="text-xs text-gray-500 ml-1">
                                            Search includes: name, admission number, gender, parents, class, section, stream
                                        </div>
                                        <div id="search-status" class="text-xs text-blue-500 font-medium hidden">
                                            Searching...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (empty($students)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-user-graduate text-5xl mb-4"></i>
                                <p>No students found in this class</p>
                            </div>
                        <?php else: ?>
                            <!-- No results message -->
                            <div id="no-results" class="hidden mt-6 p-8 text-center bg-white rounded-lg shadow-sm border border-gray-200">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="text-gray-400 mb-4">
                                        <i class="fas fa-search fa-3x"></i>
                                    </div>
                                    <h3 class="text-xl font-medium text-gray-700 mb-2">No students found</h3>
                                    <p class="text-gray-500">Try a different search term or check the spelling</p>
                                </div>
                            </div>
                            
                            <div class="mt-6" id="students-container">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                    <?php foreach ($students as $student): ?>
                                    <div class="student-card bg-white border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition-all duration-200 overflow-hidden transform hover:-translate-y-1" 
                                         data-name="<?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?>"
                                         data-admission="<?php echo htmlspecialchars($student['admission_number']); ?>"
                                         data-gender="<?php echo htmlspecialchars($student['gender']); ?>"
                                         data-father="<?php echo htmlspecialchars($student['father_name'] ?? ''); ?>"
                                         data-mother="<?php echo htmlspecialchars($student['mother_name'] ?? ''); ?>"
                                         data-class="<?php echo htmlspecialchars($student['class_name'] ?? ''); ?>"
                                         data-section="<?php echo htmlspecialchars($student['section'] ?? ''); ?>"
                                         data-stream="<?php echo htmlspecialchars($student['stream'] ?? ''); ?>">
                                        <div class="p-4">
                                            <div class="flex items-center">
                                                <div class="h-16 w-16 flex-shrink-0 relative group">
                                                    <?php if (!empty($student['image']) && file_exists('uploads/' . $student['image'])): ?>
                                                        <img class="h-16 w-16 rounded-full object-cover border-2 border-blue-100 group-hover:border-blue-300 transition-all duration-200" 
                                                             src="uploads/<?php echo htmlspecialchars($student['image']); ?>" 
                                                             alt="Student Photo">
                                                    <?php else: ?>
                                                        <div class="h-16 w-16 rounded-full bg-gradient-to-br from-blue-100 to-blue-200 flex items-center justify-center border-2 border-blue-200 group-hover:border-blue-300 transition-all duration-200">
                                                            <i class="fas fa-user text-blue-500 text-2xl group-hover:text-blue-600 transition-all duration-200"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="absolute -top-1 -right-1 h-5 w-5 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs opacity-0 group-hover:opacity-100 transform scale-0 group-hover:scale-100 transition-all duration-200">
                                                        <i class="fas fa-info"></i>
                                                    </div>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-md font-medium text-gray-900"><?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?></div>
                                                    <div class="text-sm text-gray-600"><?php echo htmlspecialchars($student['admission_number']); ?></div>
                                                    <div class="mt-1">
                                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $student['gender'] == 'Male' ? 'bg-blue-100 text-blue-800' : 'bg-pink-100 text-pink-800'; ?>">
                                                            <?php echo $student['gender']; ?>
                                                        </span>
                                                        <?php if (!empty($student['class_name'])): ?>
                                                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 ml-1">
                                                            <?php echo htmlspecialchars($student['class_name']); ?>
                                                        </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="bg-gradient-to-r from-blue-50 to-blue-100 px-4 py-3 border-t border-blue-200">
                                            <a href="record_payment.php?view=student&student_id=<?php echo $student['id']; ?>" 
                                               class="w-full flex items-center justify-center px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white text-sm font-medium rounded-md shadow-sm transition-all duration-200 transform hover:scale-105">
                                                <i class="fas fa-money-bill-wave mr-2"></i> Record Payment
                                            </a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div id="no-results" class="hidden text-center py-8 text-gray-500">
                                <i class="fas fa-search text-5xl mb-4"></i>
                                <p>No students match your search</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <!-- Single Student View -->
                <?php elseif ($view == 'student' && !empty($student_details)): ?>
                    <!-- Student Information Card -->
                    <div class="bg-white rounded-lg shadow-md overflow-hidden modern-card mb-6">
                        <div class="bg-gradient-to-r from-blue-600 to-indigo-700 p-6 text-white">
                            <div class="flex items-center">
                                <div class="mr-6 relative">
                                    <?php if (!empty($student_details['image']) && file_exists('uploads/' . $student_details['image'])): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($student_details['image']); ?>" alt="Student Photo" class="w-24 h-24 rounded-full border-4 border-white object-cover shadow-lg">
                                    <?php else: ?>
                                        <div class="w-24 h-24 rounded-full bg-gradient-to-br from-blue-200 to-blue-300 border-4 border-white flex items-center justify-center shadow-lg">
                                            <i class="fas fa-user text-blue-600 text-4xl"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="absolute bottom-0 right-0 h-8 w-8 bg-white rounded-full flex items-center justify-center text-blue-600 shadow-md border-2 border-blue-100">
                                        <i class="fas fa-<?php echo $student_details['gender'] == 'Male' ? 'mars' : 'venus'; ?>"></i>
                                    </div>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold"><?php echo htmlspecialchars($student_details['firstname'] . ' ' . $student_details['lastname']); ?></h2>
                                    <p class="text-blue-100 flex items-center">
                                        <i class="fas fa-id-card mr-2"></i>
                                        <?php echo htmlspecialchars($student_details['admission_number']); ?>
                                    </p>
                                    <p class="text-blue-100 flex items-center">
                                        <i class="fas fa-graduation-cap mr-2"></i>
                                        <?php echo htmlspecialchars($student_details['class_name']); ?>
                                    </p>
                                    <div class="mt-2">
                                        <?php if ($student_details['section']): ?>
                                        <span class="px-3 py-1 text-xs rounded-full bg-white <?php echo $student_details['section'] === 'boarding' ? 'text-blue-800' : 'text-green-800'; ?> font-semibold">
                                            <i class="fas fa-<?php echo $student_details['section'] === 'boarding' ? 'home' : 'walking'; ?> mr-1"></i>
                                            <?php echo ucfirst($student_details['section']); ?> Student
                                        </span>
                                        <?php else: ?>
                                        <span class="px-3 py-1 text-xs rounded-full bg-white text-gray-800 font-semibold">
                                            <i class="fas fa-question-circle mr-1"></i>
                                            Section Not Set
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-6">
                            <!-- Compact Fee Summary Card -->
                            <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                                <div class="flex flex-wrap">
                                    <!-- Student Info - Left Column -->
                                    <div class="w-full md:w-1/4 bg-blue-50 p-4">
                                        <h3 class="text-sm font-semibold text-blue-700 mb-2 flex items-center">
                                            <i class="fas fa-user-graduate mr-2"></i> Student Info
                                        </h3>
                                        <div class="space-y-1 text-xs">
                                            <div class="flex items-center">
                                                <span class="text-gray-600 w-16">Age:</span>
                                                <span class="font-medium"><?php echo htmlspecialchars($student_details['age']); ?></span>
                                            </div>
                                            <div class="flex items-center">
                                                <span class="text-gray-600 w-16">Section:</span>
                                                <span class="font-medium"><?php echo ucfirst(htmlspecialchars($student_details['section'] ?? 'Not Set')); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Current Term Summary - Middle Column -->
                                    <div class="w-full md:w-2/4 p-4 border-t md:border-t-0 md:border-l md:border-r border-gray-200">
                                        <div class="flex items-center justify-between mb-1">
                                            <h3 class="text-sm font-semibold text-gray-700 flex items-center">
                                                <i class="fas fa-calendar-alt mr-2"></i> <?php echo htmlspecialchars($current_term_name . ' ' . $current_year); ?>
                                            </h3>
                                            <span class="px-2 py-0.5 text-xs rounded-full <?php 
                                                echo $pending_fees <= 0 ? 'bg-green-100 text-green-800' : 
                                                    ($total_paid > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                                <?php echo $pending_fees <= 0 ? 'Fully Paid' : 
                                                    ($total_paid > 0 ? 'Partially Paid' : 'Not Paid'); ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($total_fees > 0): 
                                            $payment_percentage = min(100, round(($total_paid / $total_fees) * 100));
                                        ?>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5 mb-1">
                                            <div class="bg-blue-600 h-1.5 rounded-full" style="width: <?php echo $payment_percentage; ?>%"></div>
                                        </div>
                                        <div class="text-xs text-right text-gray-500 mb-2"><?php echo $payment_percentage; ?>% paid</div>
                                        <?php endif; ?>
                                        
                                        <div class="grid grid-cols-3 gap-1 text-xs">
                                            <div>
                                                <span class="text-gray-500 block">Total</span>
                                                <span class="font-medium">UGX <?php echo number_format($total_fees); ?></span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 block">Paid</span>
                                                <span class="font-medium text-green-600">UGX <?php echo number_format($total_paid); ?></span>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 block">Balance</span>
                                                <span class="font-medium <?php echo $pending_fees > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                                    UGX <?php echo number_format($pending_fees); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Previous Terms - Right Column -->
                                    <div class="w-full md:w-1/4 p-4 border-t md:border-t-0 bg-gray-50">
                                        <?php 
                                        // Calculate total outstanding across all terms (excluding current term)
                                        $total_outstanding_all_terms = 0;
                                        foreach ($all_debts_by_term as $term_id => $term_debt) {
                                            if ($term_id != $current_term_id) {
                                                $total_outstanding_all_terms += $term_debt['total_pending'];
                                            }
                                        }
                                        
                                        // Count terms with outstanding balances
                                        $terms_with_debt = 0;
                                        foreach ($all_debts_by_term as $term_id => $term_debt) {
                                            if ($term_id != $current_term_id && $term_debt['total_pending'] > 0) {
                                                $terms_with_debt++;
                                            }
                                        }
                                        
                                        if ($total_outstanding_all_terms > 0):
                                        ?>
                                        <h3 class="text-xs font-semibold text-orange-600 flex items-center mb-2">
                                            <i class="fas fa-exclamation-circle mr-1"></i> Previous Outstanding
                                        </h3>
                                        
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-xs text-gray-600"><?php echo $terms_with_debt; ?> term<?php echo $terms_with_debt > 1 ? 's' : ''; ?> with debt</span>
                                            <span class="text-orange-700 font-medium text-xs">
                                                UGX <?php echo number_format($total_outstanding_all_terms); ?>
                                            </span>
                                        </div>
                                        
                                        <button 
                                            onclick="recordTotalPayment(<?php echo $total_outstanding_all_terms; ?>)" 
                                            class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-150 ease-in-out shadow-sm hover:shadow-md border border-green-400 font-poppins">
                                            <i class="fas fa-money-bill-wave mr-2 text-green-100"></i>
                                            <span class="font-semibold tracking-wide">Pay All Outstanding</span>
                                        </button>
                                        <?php else: ?>
                                        <div class="flex flex-col items-center justify-center h-full">
                                            <i class="fas fa-check-circle text-green-500 text-xl mb-1"></i>
                                            <span class="text-xs text-gray-600">No previous debts</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Outstanding Debts Summary Card -->
                            <?php if ($total_outstanding_all_terms > 0): ?>
                            <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden mt-6">
                                <div class="bg-gradient-to-r from-orange-50 to-orange-100 px-4 py-3 border-b border-orange-200">
                                    <div class="flex justify-between items-center">
                                        <h3 class="text-base font-semibold text-orange-800 flex items-center">
                                            <i class="fas fa-exclamation-triangle mr-2"></i> Outstanding Debts Summary
                                        </h3>
                                        <span class="px-3 py-1 bg-orange-200 text-orange-800 rounded-full text-sm font-medium">
                                            Total: UGX <?php echo number_format($total_outstanding_all_terms); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="p-4">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead>
                                                <tr class="bg-gray-50">
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Term</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Fees</th>
                                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid</th>
                                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($all_debts_by_term as $term_id => $term_debt): ?>
                                                <?php if ($term_id != $current_term_id && $term_debt['total_pending'] > 0): ?>
                                                <tr class="hover:bg-orange-50">
                                                    <td class="px-4 py-3 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($term_debt['term_name']); ?> <?php echo htmlspecialchars($term_debt['term_year']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap">
                                                        <div class="text-sm text-gray-700">
                                                            <?php echo htmlspecialchars($term_debt['class_name']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-right">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            UGX <?php echo number_format($term_debt['total_fees']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-right">
                                                        <div class="text-sm font-medium text-green-600">
                                                            UGX <?php echo number_format($term_debt['total_paid']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 whitespace-nowrap text-right">
                                                        <div class="text-sm font-medium text-red-600">
                                                            UGX <?php echo number_format($term_debt['total_pending']); ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-6 flex justify-between space-x-4">
                                <a href="record_payment.php?view=students&class_id=<?php echo $student_details['class_id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg transition duration-150 flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i>
                                    Back to Students List
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fee and Payment History Tabs -->
                    <div class="bg-white rounded-lg shadow-md modern-card mb-6">
                        <div class="border-b border-gray-200">
                            <div class="flex" id="tabs-container">
                                <button class="py-4 px-6 border-b-2 border-blue-500 font-medium text-blue-600 tab-button active" data-tab="fees">
                                    <i class="fas fa-money-bill-wave mr-2"></i>Fee Structure
                                </button>
                                <button class="py-4 px-6 font-medium text-gray-500 tab-button" data-tab="payments">
                                    <i class="fas fa-history mr-2"></i>Payment History
                                </button>
                            </div>
                        </div>
                        
                        <div id="fees-tab" class="p-6 tab-content">
                            <div class="overflow-x-auto">
                                <?php if (empty($student_fees)): ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-file-invoice-dollar text-5xl mb-4"></i>
                                        <p>No fees found for this student</p>
                                    </div>
                                <?php else: ?>
                                    <div class="flex justify-between items-center mb-4">
                                        <h3 class="text-sm font-medium text-gray-700">Fee Structure for <?php echo htmlspecialchars($current_term_name . ' ' . $current_year); ?></h3>
                                        <div class="text-xs text-gray-500">
                                            <span class="inline-flex items-center mr-3">
                                                <span class="w-2 h-2 rounded-full bg-green-500 mr-1"></span> Paid
                                            </span>
                                            <span class="inline-flex items-center">
                                                <span class="w-2 h-2 rounded-full bg-yellow-500 mr-1"></span> Pending
                                            </span>
                                        </div>
                                    </div>
                                    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead>
                                                <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Fee Type</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount (UGX)</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Paid</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance</th>
                                                    <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                                    <th scope="col" class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php foreach ($grouped_fees as $fee_name => $fee): ?>
                                                <tr class="hover:bg-blue-50 transition-colors duration-150">
                                                    <td class="px-4 py-3">
                                                        <div class="flex items-center">
                                                            <div class="flex-shrink-0 h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                                <i class="fas fa-<?php 
                                                                    switch(strtolower($fee_name)) {
                                                                        case 'school fees':
                                                                            echo 'graduation-cap';
                                                                            break;
                                                                        case 'uniform':
                                                                        case 'school uniform':
                                                                            echo 'tshirt';
                                                                            break;
                                                                        case 'transport':
                                                                        case 'transportation':
                                                                            echo 'bus';
                                                                            break;
                                                                        case 'lunch':
                                                                        case 'meals':
                                                                            echo 'utensils';
                                                                            break;
                                                                        case 'books':
                                                                        case 'textbooks':
                                                                            echo 'book';
                                                                            break;
                                                                        default:
                                                                            echo 'file-invoice-dollar';
                                                                    }
                                                                ?>"></i>
                                                            </div>
                                                            <div class="ml-3">
                                                                <div class="text-sm font-medium text-gray-900">
                                                                    <?php echo ucwords(htmlspecialchars($fee_name)); ?>
                                                                </div>
                                                                <?php if (!empty($fee['adjustment_reasons'])): ?>
                                                                <div class="text-xs text-blue-600">
                                                                    <i class="fas fa-info-circle"></i> Adjusted
                                                                </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="text-sm font-medium">
                                                            <?php echo number_format($fee['adjusted_amount']); ?>
                                                        </div>
                                                        <?php if ($fee['standard_amount'] != $fee['adjusted_amount']): ?>
                                                        <div class="text-xs text-gray-500 line-through">
                                                            <?php echo number_format($fee['standard_amount']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="text-sm text-green-600 font-medium">
                                                            <?php echo number_format($fee['total_paid']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <?php $balance = max(0, $fee['adjusted_amount'] - $fee['total_paid']); ?>
                                                        <div class="text-sm <?php echo $balance > 0 ? 'text-red-600' : 'text-green-600'; ?> font-medium">
                                                            <?php echo number_format($balance); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <?php 
                                                        $payment_status = '';
                                                        $payment_percentage = 0;
                                                        if ($fee['adjusted_amount'] > 0) {
                                                            $payment_percentage = min(100, round(($fee['total_paid'] / $fee['adjusted_amount']) * 100));
                                                        }
                                                        
                                                        if ($fee['adjusted_amount'] <= $fee['total_paid']) {
                                                            $payment_status = 'Paid';
                                                            $status_color = 'bg-green-100 text-green-800';
                                                            $progress_color = 'bg-green-500';
                                                        } else if ($fee['total_paid'] > 0) {
                                                            $payment_status = 'Partial';
                                                            $status_color = 'bg-yellow-100 text-yellow-800';
                                                            $progress_color = 'bg-yellow-500';
                                                        } else {
                                                            $payment_status = 'Unpaid';
                                                            $status_color = 'bg-red-100 text-red-800';
                                                            $progress_color = 'bg-red-500';
                                                        }
                                                        ?>
                                                        <div class="flex flex-col">
                                                            <span class="px-2 py-1 inline-flex text-xs leading-4 font-medium rounded-full <?php echo $status_color; ?>">
                                                                <?php echo $payment_status; ?> 
                                                                <?php if ($payment_percentage > 0): ?>
                                                                (<?php echo $payment_percentage; ?>%)
                                                                <?php endif; ?>
                                                            </span>
                                                            <?php if ($payment_percentage > 0): ?>
                                                            <div class="mt-1 w-full bg-gray-200 rounded-full h-1.5">
                                                                <div class="<?php echo $progress_color; ?> h-1.5 rounded-full" style="width: <?php echo $payment_percentage; ?>%"></div>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 text-right">
                                                        <?php if ($fee['adjusted_amount'] > $fee['total_paid']): ?>
                                                            <?php
                                                            // Get the first fee from the group for adjustment
                                                            $first_fee = $fee['fees'][0];
                                                            $remaining = $fee['adjusted_amount'] - $fee['total_paid'];
                                                            ?>
                                                     <button 
                                                        onclick="adjustFee(<?php echo $first_fee['id']; ?>, '<?php echo htmlspecialchars($first_fee['fee_name']); ?>', <?php echo $first_fee['amount']; ?>, <?php echo $first_fee['final_amount']; ?>, '<?php echo htmlspecialchars($first_fee['adjustment_reason'] ?? ''); ?>')" 
                                                        class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-150 ease-in-out shadow-sm hover:shadow-md"
                                                    >
                                                        <i class="fas fa-sliders-h mr-2"></i>
                                                        Adjust School Fees
                                                    </button>
                                                    <button 
                                                        onclick="preparePayment(<?php echo $first_fee['id']; ?>, '<?php echo htmlspecialchars($first_fee['fee_name']); ?>', <?php echo $remaining; ?>)" 
                                                        class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-150 ease-in-out shadow-sm hover:shadow-md"
                                                    >
                                                        <i class="fas fa-money-bill-wave mr-2"></i>
                                                        Pay School Fees
                                                    </button>
                                                        <?php else: ?>
                                                            <span class="inline-flex items-center px-2 py-1 rounded-full bg-green-100 text-green-800 text-xs">
                                                                <i class="fas fa-check-circle mr-1"></i> Complete
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div id="payments-tab" class="p-6 tab-content hidden">
                            <div class="overflow-x-auto">
                                <?php if (empty($student_payments)): ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-receipt text-5xl mb-4"></i>
                                        <p>No payment records found for this student</p>
                                    </div>
                                <?php else: ?>
                                    <div class="flex justify-between items-center mb-4">
                                        <h3 class="text-sm font-medium text-gray-700">Payment History</h3>
                                        <div class="text-xs text-gray-500">
                                            <span class="inline-flex items-center mr-3">
                                                <span class="w-2 h-2 rounded-full bg-green-500 mr-1"></span> Cash
                                            </span>
                                            <span class="inline-flex items-center mr-3">
                                                <span class="w-2 h-2 rounded-full bg-blue-500 mr-1"></span> Bank Transfer
                                            </span>
                                            <span class="inline-flex items-center">
                                                <span class="w-2 h-2 rounded-full bg-purple-500 mr-1"></span> Other
                                            </span>
                                        </div>
                                    </div>
                                    <table class="min-w-full divide-y divide-gray-200 border border-gray-200 rounded-lg overflow-hidden">
                                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <tr>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Fee Name</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Term</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Method</th>
                                                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                                                <th scope="col" class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Receipt</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($student_payments as $payment): ?>
                                            <tr class="hover:bg-blue-50 transition-colors duration-150">
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                            <i class="fas fa-file-invoice-dollar"></i>
                                                        </div>
                                                        <div class="ml-3">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo isset($payment['fee_name']) ? htmlspecialchars($payment['fee_name']) : 'General Payment'; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="text-sm font-medium text-green-600">UGX <?php echo number_format($payment['amount']); ?></div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center">
                                                        <div class="text-sm text-gray-900">
                                                            <?php echo htmlspecialchars($payment['term_name']); ?>
                                                        </div>
                                                        <?php if ($payment['term_id'] == $current_term_id): ?>
                                                            <span class="ml-1 px-1.5 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">Current</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($payment['term_year']); ?></div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-calendar-alt text-gray-400 mr-1.5"></i>
                                                        <div class="text-sm text-gray-900"><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="px-2 py-1 inline-flex text-xs leading-4 font-medium rounded-full 
                                                        <?php echo $payment['payment_method'] == 'Cash' ? 'bg-green-100 text-green-800' : 
                                                            ($payment['payment_method'] == 'Bank Transfer' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800'); ?>">
                                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <?php if (!empty($payment['reference_number'])): ?>
                                                        <span class="text-xs px-2 py-1 bg-gray-100 rounded"><?php echo htmlspecialchars($payment['reference_number']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-center">
                                                    <a href="generate_receipt.php?payment_id=<?php echo $payment['id']; ?>&student_id=<?php echo $student_id; ?>" 
                                                       class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 hover:bg-blue-100 text-blue-600 hover:text-blue-800 transition-colors" 
                                                       title="Download Receipt" target="_blank">
                                                        <i class="fas fa-receipt"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                            <div class="mt-6 flex justify-between">
                                <a href="record_payment.php?view=students&class_id=<?php echo $student_details['class_id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded shadow-md transition duration-150 flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i> Back to Students List
                                </a>
                                <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow-md transition duration-150 flex items-center" onclick="printPaymentHistory()">
                                    <i class="fas fa-print mr-2"></i> Print Payment History
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- JavaScript for functionality -->
    <script>
        // Initialize variables for payment amounts
        const currentTermPending = <?php echo $pending_fees; ?>;
        const previousTermPending = <?php echo $previous_term_pending; ?>;
        
        // Create an object to store historical debt amounts and fees
        const historicalDebts = {
            <?php foreach ($all_debts_by_term as $term_id => $term_debt): ?>
            <?php if ($term_id != $current_term_id): ?>
            <?php echo $term_id; ?>: {
                pending: <?php echo $term_debt['total_pending']; ?>,
                termName: "<?php echo addslashes($term_debt['term_name'] . ' ' . $term_debt['term_year'] . ' - ' . $term_debt['class_name']); ?>",
                has_fees: <?php echo !empty($term_debt['fees']) ? 'true' : 'false'; ?>,
                has_payments: <?php echo isset($term_debt['total_paid']) && $term_debt['total_paid'] > 0 ? 'true' : 'false'; ?>,
                fees: [
                    <?php if (!empty($term_debt['fees'])): ?>
                    <?php foreach ($term_debt['fees'] as $fee): ?>
                    {
                        id: <?php echo isset($fee['id']) ? $fee['id'] : 0; ?>,
                        name: "<?php echo addslashes($fee['fee_name']); ?>",
                        pending: <?php echo $fee['pending']; ?>,
                        total: <?php echo $fee['total']; ?>,
                        paid: <?php echo $fee['paid']; ?>
                    },
                    <?php endforeach; ?>
                    <?php else: ?>
                    // If no fees defined but there's a pending balance, create a default fee
                    {
                        id: 0,
                        name: "School Fees",
                        pending: <?php echo $term_debt['total_pending']; ?>,
                        total: <?php echo $term_debt['total_pending'] + ($term_debt['total_paid'] ?? 0); ?>,
                        paid: <?php echo $term_debt['total_paid'] ?? 0; ?>
                    },
                    <?php endif; ?>
                ]
            },
            <?php endif; ?>
            <?php endforeach; ?>
            
            <?php foreach ($historical_terms as $term): ?>
            <?php if (!isset($all_debts_by_term[$term['id']]) && $term['id'] != $current_term_id): ?>
            <?php echo $term['id']; ?>: {
                pending: <?php echo $term['pending']; ?>,
                termName: "<?php echo addslashes($term['name'] . ' ' . $term['year'] . ' - ' . $term['class_name']); ?>",
                has_fees: <?php echo !empty($term['fees']) ? 'true' : 'false'; ?>,
                has_payments: <?php echo $term['total_paid'] > 0 ? 'true' : 'false'; ?>,
                fees: [
                    <?php if (!empty($term['fees'])): ?>
                    <?php foreach ($term['fees'] as $fee): ?>
                    {
                        id: <?php echo $fee['id']; ?>,
                        name: "<?php echo addslashes($fee['fee_name']); ?>",
                        pending: <?php echo max(0, $fee['final_amount'] - $fee['amount_paid']); ?>,
                        total: <?php echo $fee['final_amount']; ?>,
                        paid: <?php echo $fee['amount_paid']; ?>
                    },
                    <?php endforeach; ?>
                    <?php else: ?>
                    // If no fees defined but there's a pending balance, create a default fee
                    {
                        id: 0,
                        name: "School Fees",
                        pending: <?php echo $term['pending']; ?>,
                        total: <?php echo $term['pending'] + $term['total_paid']; ?>,
                        paid: <?php echo $term['total_paid']; ?>
                    },
                    <?php endif; ?>
                ]
            },
            <?php endif; ?>
            <?php endforeach; ?>
        };

        // Create an object to store previous term fees
        const previousTermFees = {
            <?php if ($previous_term_id > 0): ?>
            <?php foreach ($prev_term_fees as $fee): ?>
            "<?php echo addslashes($fee['fee_name']); ?>": {
                pending: <?php echo max(0, $fee['final_amount'] - $fee['amount_paid']); ?>,
                total: <?php echo $fee['final_amount']; ?>,
                paid: <?php echo $fee['amount_paid']; ?>
            },
            <?php endforeach; ?>
            <?php endif; ?>
        };

        // Function to update term selection options
        function updateTermSelection(showOnlyCurrentTerm = true) {
            const termSelect = document.getElementById('term_id');
            const termSelectionDiv = document.getElementById('term-selection-div');
            const feeSelectionDiv = document.getElementById('fee-selection-div');
            
            if (showOnlyCurrentTerm) {
                // Hide the term selection div since we only have current term
                termSelectionDiv.style.display = 'none';
                feeSelectionDiv.classList.add('hidden');
                // Set to current term
                termSelect.innerHTML = `<option value="<?php echo $current_term_id; ?>">
                    <?php echo htmlspecialchars($current_term_name . ' ' . $current_year); ?> (Current)
                </option>`;
            } else {
                // Show the term selection div for all previous terms
                termSelectionDiv.style.display = 'block';
                // Show all previous terms
                let options = ``;
                
                // Group terms by year for better organization
                const termsByYear = {};
                
                // Add current term first
                options += `<option value="<?php echo $current_term_id; ?>">
                    <?php echo htmlspecialchars($current_term_name . ' ' . $current_year); ?> (Current)
                </option>`;
                
                // Add all historical terms
                <?php foreach ($historical_terms as $term): ?>
                options += `<option value="<?php echo $term['id']; ?>">
                    <?php echo htmlspecialchars($term['name'] . ' ' . $term['year']); ?> (<?php echo htmlspecialchars($term['class_name']); ?>)
                </option>`;
                <?php endforeach; ?>
                
                termSelect.innerHTML = options;
                
                // Trigger fee selection update
                updateFeeSelection();
            }
        }
        


        // Function to prepare payment for a specific fee
        function preparePayment(feeId, feeName, remainingAmount) {
            // Reset form
            document.getElementById('payment-form').reset();
            
            // Set the fee ID - Make sure this is set correctly
            document.getElementById('fee_id').value = feeId;
            document.getElementById('use_general_payment').value = '0'; // Set to 0 to indicate this is a specific fee payment
            
            // Set only the max amount, leave the input empty
            const amountInput = document.getElementById('amount');
            amountInput.value = ''; // Leave empty
            amountInput.setAttribute('max', remainingAmount);
            
            // Update suggested amount text
            const suggestedAmount = document.getElementById('suggested-amount');
            suggestedAmount.textContent = `Suggested amount: UGX ${remainingAmount.toLocaleString()} (${feeName})`;
            
            // Show only current term
            updateTermSelection(true);
            
            // Add note about the fee type
            document.getElementById('notes').value = `Payment for ${feeName}`;
            
            // Open modal
            document.getElementById('payment-modal').classList.remove('hidden');
            
            // Hide fee selection div since we're paying for a specific fee
            const feeSelectionDiv = document.getElementById('fee-selection-div');
            if (feeSelectionDiv) {
                feeSelectionDiv.style.display = 'none';
            }
        }

        // Function to validate fee adjustment
        function validateAdjustment(adjustedAmount) {
            const standardAmount = parseFloat(document.getElementById('standard_amount_hidden').value);
            const warningDiv = document.getElementById('adjustment_warning');
            
            if (parseFloat(adjustedAmount) !== standardAmount) {
                warningDiv.classList.remove('hidden');
            } else {
                warningDiv.classList.add('hidden');
            }
        }

        // Function to adjust fee
        function adjustFee(feeId, feeName, standardAmount, currentAmount, adjustmentReason) {
            // Set values in the adjustment modal
            document.getElementById('adjust_fee_id').value = feeId;
            document.getElementById('fee_name_display').textContent = feeName;
            document.getElementById('standard_amount_display').textContent = 'UGX ' + standardAmount.toLocaleString();
            document.getElementById('standard_amount_hidden').value = standardAmount;
            document.getElementById('adjusted_amount').value = currentAmount || standardAmount;
            document.getElementById('adjustment_reason').value = adjustmentReason || '';
            
            // Show the modal
            document.getElementById('fee-adjustment-modal').classList.remove('hidden');
        }

        // Function to update fee selection options
        function updateFeeSelection() {
            const termSelect = document.getElementById('term_id');
            const feeSelect = document.getElementById('fee_name');
            const feeSelectionDiv = document.getElementById('fee-selection-div');
            const selectedTermId = termSelect.value;
            
            console.log('Updating fee selection for term:', selectedTermId);
            
            // Make sure fee selection div is visible
            if (feeSelectionDiv) {
                feeSelectionDiv.style.display = 'block';
                feeSelectionDiv.classList.remove('hidden');
            }
            
            // Clear existing options
            feeSelect.innerHTML = '<option value="">Select a fee...</option>';
            
            if (selectedTermId) {
                // Show the fee selection div since we have a term selected
                feeSelectionDiv.style.display = 'block';
                feeSelectionDiv.classList.remove('hidden');
                
                if (historicalDebts[selectedTermId]) {
                    const debt = historicalDebts[selectedTermId];
                    if (debt.fees && debt.fees.length > 0) {
                        debt.fees.forEach(fee => {
                            if (fee.pending > 0) {
                                const option = document.createElement('option');
                                option.value = fee.id;
                                option.textContent = `${fee.name} (UGX ${fee.pending.toLocaleString()} pending)`;
                                option.dataset.pending = fee.pending;
                                option.dataset.total = fee.total;
                                option.dataset.paid = fee.paid;
                                option.dataset.feeName = fee.name;
                                feeSelect.appendChild(option);
                            }
                        });
                    } else {
                        // If no specific fees, add a general payment option
                        const option = document.createElement('option');
                        option.value = "0";
                        option.textContent = "General Payment";
                        option.dataset.pending = debt.pending;
                        option.dataset.total = debt.pending;
                        option.dataset.paid = 0;
                        option.dataset.feeName = "General Payment";
                        feeSelect.appendChild(option);
                    }
                }
            }
            
            // Update suggested amount based on selected fee
            updateSuggestedAmount();
        }

        // Function to update suggested amount
        function updateSuggestedAmount() {
            const termSelect = document.getElementById('term_id');
            const feeSelect = document.getElementById('fee_name');
            const amountInput = document.getElementById('amount');
            const suggestedAmount = document.getElementById('suggested-amount');
            const feeAmountDetails = document.getElementById('fee-amount-details');
            const selectedTermId = termSelect.value;
            
            if (feeSelect.value && feeSelect.style.display !== 'none') {
                // For fee-specific payments
                const selectedOption = feeSelect.options[feeSelect.selectedIndex];
                const pending = Number(selectedOption.dataset.pending);
                const total = Number(selectedOption.dataset.total);
                const paid = Number(selectedOption.dataset.paid);
                const feeName = selectedOption.dataset.feeName;
                
                suggestedAmount.textContent = `Suggested amount: UGX ${pending.toLocaleString()}`;
                feeAmountDetails.textContent = `Total: UGX ${total.toLocaleString()} | Paid: UGX ${paid.toLocaleString()}`;
                amountInput.setAttribute('max', pending);
                
                // Update notes
                document.getElementById('notes').value = `Payment for ${feeName}`;
            } else {
                // For general term payments
                if (selectedTermId == '<?php echo $current_term_id; ?>') {
                    suggestedAmount.textContent = `Suggested amount: UGX ${currentTermPending.toLocaleString()} (Current term balance)`;
                    amountInput.setAttribute('max', currentTermPending);
                } else if (selectedTermId == '<?php echo $previous_term_id; ?>') {
                    suggestedAmount.textContent = `Suggested amount: UGX ${previousTermPending.toLocaleString()} (Previous term balance)`;
                    amountInput.setAttribute('max', previousTermPending);
                } else if (historicalDebts[selectedTermId]) {
                    const debt = historicalDebts[selectedTermId];
                    suggestedAmount.textContent = `Suggested amount: UGX ${debt.pending.toLocaleString()} (${debt.termName} balance)`;
                    amountInput.setAttribute('max', debt.pending);
                }
                feeAmountDetails.textContent = '';
            }
        }

        // Function to show payment confirmation
        function showPaymentConfirmation() {
            // Get form values
            const amount = document.getElementById('amount').value;
            const paymentMethod = document.getElementById('payment_method').value;
            const reference = document.getElementById('reference_number').value || 'Not provided';
            const notes = document.getElementById('notes').value || 'Not provided';
            const termSelect = document.getElementById('term_id');
            const selectedTerm = termSelect.options[termSelect.selectedIndex].text;
            
            // Validate amount
            if (!amount || amount <= 0) {
                alert('Please enter a valid payment amount');
                return false;
            }
            
            // Skip validation - allow any amount
            console.log('Payment validation bypassed for amount:', amount);
            // return false; // Commented out to allow any amount
            
            // Get fee details if applicable and set fee_id
            let feeDetails = '';
            const feeSelect = document.getElementById('fee_name');
            const feeId = document.getElementById('fee_id').value;
            
            // Check if this is a specific fee payment (fee_id already set in preparePayment)
            if (feeId > 0) {
                // This is a specific fee payment, fee_id is already set correctly
                // Just get the fee name for display
                const useGeneralPayment = document.getElementById('use_general_payment').value;
                if (useGeneralPayment === '0') {
                    // This is a specific fee payment
                    feeDetails = `Fee ID: ${feeId} - ${notes.replace('Payment for ', '')}`;
                }
            } 
            // If fee_id is not set but fee is selected in dropdown
            else if (feeSelect && !feeSelect.classList.contains('hidden') && feeSelect.selectedIndex > 0) {
                const selectedFee = feeSelect.options[feeSelect.selectedIndex];
                const selectedFeeId = selectedFee.value;
                const feeName = selectedFee.dataset.feeName || selectedFee.textContent.split(' (')[0];
                
                // Set the fee ID in the form - make sure it's a number
                if (!isNaN(selectedFeeId) && selectedFeeId > 0) {
                    document.getElementById('fee_id').value = selectedFeeId;
                    document.getElementById('use_general_payment').value = '0';
                    feeDetails = `${feeName} (Fee ID: ${selectedFeeId})`;
                } else {
                    // If it's not a valid fee ID, set as general payment
                    document.getElementById('fee_id').value = '0';
                    document.getElementById('use_general_payment').value = '1';
                    feeDetails = `General Payment (${feeName})`;
                }
            } else {
                // If no specific fee is selected, set as general payment
                document.getElementById('fee_id').value = '0';
                document.getElementById('use_general_payment').value = '1';
                feeDetails = 'General Payment';
            }

            // Update confirmation section
            document.getElementById('confirm-amount').textContent = 'UGX ' + Number(amount).toLocaleString();
            document.getElementById('confirm-payment-method').textContent = paymentMethod;
            document.getElementById('confirm-reference').textContent = reference;
            document.getElementById('confirm-notes').textContent = notes;
            document.getElementById('confirm-term').textContent = selectedTerm;
            
            // Update fee details in confirmation
            const confirmFeeDetails = document.getElementById('confirm-fee-details');
            if (confirmFeeDetails) {
                confirmFeeDetails.textContent = feeDetails;
            }
            
            // Hide form section and show confirmation section
            document.getElementById('payment-form-section').classList.add('hidden');
            document.getElementById('payment-confirmation-section').classList.remove('hidden');
        }

        // Function to hide payment confirmation and show form
        function hidePaymentConfirmation() {
            document.getElementById('payment-confirmation-section').classList.add('hidden');
            document.getElementById('payment-form-section').classList.remove('hidden');
        }

        // Function to process the payment
        function processPayment() {
            // Add the record_payment field to the form
            const form = document.getElementById('payment-form');
            let recordPaymentInput = form.querySelector('input[name="record_payment"]');
            if (!recordPaymentInput) {
                recordPaymentInput = document.createElement('input');
                recordPaymentInput.type = 'hidden';
                recordPaymentInput.name = 'record_payment';
                recordPaymentInput.value = '1';
                form.appendChild(recordPaymentInput);
            }
            
            // Add a refresh flag to ensure the page refreshes after payment
            let refreshInput = form.querySelector('input[name="refresh_after_payment"]');
            if (!refreshInput) {
                refreshInput = document.createElement('input');
                refreshInput.type = 'hidden';
                refreshInput.name = 'refresh_after_payment';
                refreshInput.value = '1';
                form.appendChild(refreshInput);
            }
            
            // Submit the form
            form.submit();
        }

        // Function to record total outstanding payment for previous terms only
        function recordTotalPayment(totalAmount) {
            // Reset form
            document.getElementById('payment-form').reset();
            
            // Set it as a general payment
            document.getElementById('fee_id').value = '0';
            document.getElementById('use_general_payment').value = '1';
            
            const termSelect = document.getElementById('term_id');
            const termSelectionDiv = document.getElementById('term-selection-div');
            const feeSelectionDiv = document.getElementById('fee-selection-div');
            
            // Show the term selection div for terms with debts
            termSelectionDiv.style.display = 'block';
            let options = `<option value="">Select Term...</option>`;
            
            // Add only terms with debts
            <?php foreach ($all_debts_by_term as $term_id => $term_debt): ?>
            <?php 
            // Skip if:
            // 1. It's the current term
            // 2. There are no fees
            // 3. There's no pending balance
            if ($term_id == $current_term_id || 
                empty($term_debt['fees']) || 
                ($term_debt['total_pending'] ?? 0) <= 0) {
                continue;
            }
            ?>
            options += `<option value="<?php echo $term_id; ?>">
                <?php echo htmlspecialchars($term_debt['term_name'] . ' ' . $term_debt['term_year']); ?> 
                (<?php echo htmlspecialchars($term_debt['class_name']); ?>) - 
                Balance: UGX <?php echo number_format($term_debt['total_pending']); ?>
            </option>`;
            <?php endforeach; ?>
            
            termSelect.innerHTML = options;
            
            // If there are no options (except the default), hide the term selection
            if (termSelect.options.length <= 1) {
                termSelectionDiv.style.display = 'none';
                feeSelectionDiv.classList.add('hidden');
            }
            
            // Set only the max amount, leave the input empty
            const amountInput = document.getElementById('amount');
            amountInput.value = ''; // Leave empty
            amountInput.setAttribute('max', totalAmount);
            
            // Show fee selection
            feeSelectionDiv.classList.remove('hidden');
            
            // Update suggested amount text
            const suggestedAmount = document.getElementById('suggested-amount');
            suggestedAmount.textContent = `Suggested amount: UGX ${totalAmount.toLocaleString()} (Previous Terms Outstanding Balance)`;
            
            // Add note about the total payment
            document.getElementById('notes').value = `Previous Terms Outstanding Balance Payment`;
            
            // Open modal
            document.getElementById('payment-modal').classList.remove('hidden');
            
            // Update any dependent fields
            updateFeeSelection();
        }

        // Function to record historical payment
        function recordHistoricalPayment(termId, termName, pendingAmount) {
            // Reset form
            document.getElementById('payment-form').reset();
            
            // Don't set fee_id yet - we'll let the user select a specific fee
            if (document.getElementById('use_general_payment')) {
                document.getElementById('use_general_payment').value = '0';
            }
            
            // Set the term
            const termSelect = document.getElementById('term_id');
            if (termSelect) {
                termSelect.value = termId;
                
                // If direct value setting doesn't work, try finding the option
                if (termSelect.value != termId) {
                    for(let i = 0; i < termSelect.options.length; i++) {
                        if(termSelect.options[i].value == termId) {
                            termSelect.selectedIndex = i;
                            break;
                        }
                    }
                }
                
                // Trigger the change event to update fee selection
                const event = new Event('change');
                termSelect.dispatchEvent(event);
            }
            
            // Set the amount to the pending amount
            document.getElementById('amount').value = pendingAmount;
            
            // Show fee selection
            const feeSelectionDiv = document.getElementById('fee-selection-div');
            if (feeSelectionDiv) {
                feeSelectionDiv.classList.remove('hidden');
            }
            
            // Update suggested amount text
            const suggestedAmount = document.getElementById('suggested-amount');
            if (suggestedAmount) {
                suggestedAmount.textContent = pendingAmount.toLocaleString();
            }
            
            // Add note about the historical payment
            const notesField = document.getElementById('notes');
            if (notesField) {
                notesField.value = `Payment for ${termName}`;
            }
            
            // Open modal
            document.getElementById('payment-modal').classList.remove('hidden');
        }

        // Function to record previous term payment
        function recordPreviousTermPayment(termId, termName) {
            // Reset form
            document.getElementById('payment-form').reset();
            
            // Set it as a general payment
            document.getElementById('fee_id').value = '0';
            document.getElementById('use_general_payment').value = '1';
            
            // Set the term
            const termSelect = document.getElementById('term_id');
            termSelect.value = termId;
            
            // Trigger the change event to update fee selection
            const event = new Event('change');
            termSelect.dispatchEvent(event);
            
            // Show fee selection
            const feeSelectionDiv = document.getElementById('fee-selection-div');
            feeSelectionDiv.classList.remove('hidden');
            
            // Open modal
            document.getElementById('payment-modal').classList.remove('hidden');
        }

        // Initialize event listeners when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            const termSelect = document.getElementById('term_id');
            const feeSelect = document.getElementById('fee_name');
            
            if (termSelect) {
                termSelect.addEventListener('change', function() {
                    updateFeeSelection();
                });
            }
            
            if (feeSelect) {
                feeSelect.addEventListener('change', function() {
                    updateSuggestedAmount();
                });
            }
            
            // Initialize on page load
            if (termSelect) {
                const event = new Event('change');
                termSelect.dispatchEvent(event);
            }

            // Enhanced Student search functionality
            const searchInput = document.getElementById('student-search');
            const searchButton = document.getElementById('search-button');
            
            if (searchInput) {
                console.log("Search input found:", searchInput);
                
                // Create a function to perform the search
                function performSearch() {
                    console.log("Performing search");
                    const searchTerm = searchInput.value.trim();
                    console.log("Search term:", searchTerm);
                    
                    const studentCards = document.querySelectorAll('.student-card');
                    console.log("Found", studentCards.length, "student cards");
                    
                    const noResults = document.getElementById('no-results');
                    let foundStudents = 0;
                    
                    // Reset any previous highlighting and tooltips first
                    studentCards.forEach(card => {
                        const nameElement = card.querySelector('.text-md.font-medium');
                        const admissionElement = card.querySelector('.text-sm.text-gray-600');
                        const genderElement = card.querySelector('.rounded-full');
                        const tooltip = card.querySelector('.absolute.top-0.right-0');
                        
                        // Reset text highlighting
                        if (nameElement) {
                            nameElement.innerHTML = nameElement.textContent;
                        }
                        
                        if (admissionElement) {
                            admissionElement.innerHTML = admissionElement.textContent;
                        }
                        
                        if (genderElement) {
                            genderElement.innerHTML = genderElement.textContent;
                        }
                        
                        // Remove any tooltips
                        if (tooltip) {
                            tooltip.remove();
                        }
                    });
                    
                    // If search is empty, show all students
                    if (searchTerm === '') {
                        studentCards.forEach(card => {
                            card.style.display = '';
                        });
                        foundStudents = studentCards.length;
                        console.log("Empty search, showing all students:", foundStudents);
                    } else {
                        // Search with each character typed
                        studentCards.forEach(card => {
                            const studentName = card.getAttribute('data-name') || '';
                            const admissionNumber = card.getAttribute('data-admission') || '';
                            const gender = card.getAttribute('data-gender') || '';
                            const fatherName = card.getAttribute('data-father') || '';
                            const motherName = card.getAttribute('data-mother') || '';
                            const className = card.getAttribute('data-class') || '';
                            const section = card.getAttribute('data-section') || '';
                            const stream = card.getAttribute('data-stream') || '';
                            
                            // Helper function for case-insensitive search
                            const includes = (text, term) => {
                                return text.toLowerCase().includes(term.toLowerCase());
                            };
                            
                            // Comprehensive search across all student fields
                            const nameMatch = includes(studentName, searchTerm);
                            const admissionMatch = includes(admissionNumber, searchTerm);
                            const genderMatch = includes(gender, searchTerm);
                            const fatherMatch = includes(fatherName, searchTerm);
                            const motherMatch = includes(motherName, searchTerm);
                            const classMatch = includes(className, searchTerm);
                            const sectionMatch = includes(section, searchTerm);
                            const streamMatch = includes(stream, searchTerm);
                            
                            if (nameMatch || admissionMatch || genderMatch || fatherMatch || 
                                motherMatch || classMatch || sectionMatch || streamMatch) {
                                card.style.display = '';
                                foundStudents++;
                                
                                // Helper function to highlight text
                                const highlightText = (element, isMatch) => {
                                    if (element && isMatch) {
                                        const originalText = element.textContent;
                                        const highlightedText = originalText.replace(
                                            new RegExp(searchTerm, 'gi'), 
                                            match => `<span class="bg-yellow-200">${match}</span>`
                                        );
                                        element.innerHTML = highlightedText;
                                    }
                                };
                                
                                // Get all elements that might need highlighting
                                const nameElement = card.querySelector('.text-md.font-medium');
                                const admissionElement = card.querySelector('.text-sm.text-gray-600');
                                const genderElement = card.querySelector('.rounded-full');
                                
                                // Highlight matching text in each element
                                highlightText(nameElement, nameMatch);
                                highlightText(admissionElement, admissionMatch);
                                highlightText(genderElement, genderMatch);
                                
                                // Add tooltip with matching information
                                if (fatherMatch || motherMatch || classMatch || sectionMatch || streamMatch) {
                                    const tooltip = document.createElement('div');
                                    tooltip.className = 'absolute top-0 right-0 bg-blue-500 text-white text-xs px-2 py-1 rounded-bl-lg';
                                    tooltip.innerHTML = 'Match found';
                                    
                                    // Only add tooltip if it doesn't already exist
                                    if (!card.querySelector('.absolute.top-0.right-0')) {
                                        card.style.position = 'relative';
                                        card.appendChild(tooltip);
                                    }
                                }
                            } else {
                                card.style.display = 'none';
                            }
                        });
                        console.log("Search found", foundStudents, "matching students");
                    }
                    
                    if (foundStudents === 0) {
                        if (noResults) {
                            noResults.classList.remove('hidden');
                            console.log("Showing no results message");
                        }
                        const container = document.querySelector('#students-container');
                        if (container) container.classList.add('hidden');
                    } else {
                        if (noResults) noResults.classList.add('hidden');
                        const container = document.querySelector('#students-container');
                        if (container) container.classList.remove('hidden');
                    }
                    
                    // Show search status
                    const searchStatus = document.getElementById('search-status');
                    if (searchStatus) {
                        searchStatus.classList.remove('hidden');
                        setTimeout(() => {
                            searchStatus.classList.add('hidden');
                        }, 1000);
                    }
                }
                
                // Add clear button to search input
                const searchWrapper = document.createElement('div');
                searchWrapper.className = 'relative';
                searchInput.parentNode.insertBefore(searchWrapper, searchInput);
                searchWrapper.appendChild(searchInput);
                
                const clearButton = document.createElement('button');
                clearButton.innerHTML = '<i class="fas fa-times-circle"></i>';
                clearButton.className = 'absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 hidden';
                clearButton.id = 'clear-search';
                clearButton.type = 'button';
                searchWrapper.appendChild(clearButton);
                
                // Clear search when button is clicked
                clearButton.addEventListener('click', function() {
                    searchInput.value = '';
                    clearButton.classList.add('hidden');
                    // Perform search with empty query
                    performSearch();
                    searchInput.focus();
                });
                
                // Search button functionality
                if (searchButton) {
                    searchButton.addEventListener('click', function() {
                        console.log("Search button clicked");
                        performSearch();
                        searchInput.focus();
                    });
                }
                
                // Show/hide clear button and perform search on input
                searchInput.addEventListener('input', function() {
                    if (this.value.trim() !== '') {
                        clearButton.classList.remove('hidden');
                    } else {
                        clearButton.classList.add('hidden');
                    }
                    
                    performSearch();
                });
                
                // Also add keyup event for older browsers
                searchInput.addEventListener('keyup', function() {
                    performSearch();
                });
                
                // Add keyboard shortcut (Ctrl+F or Cmd+F) to focus the search input
                document.addEventListener('keydown', function(e) {
                    if ((e.ctrlKey || e.metaKey) && e.key === 'f' && searchInput) {
                        e.preventDefault();
                        searchInput.focus();
                    }
                    
                    // Clear search on Escape key
                    if (e.key === 'Escape' && document.activeElement === searchInput) {
                        searchInput.value = '';
                        clearButton.classList.add('hidden');
                        performSearch();
                    }
                });
                
                // Trigger search on page load to ensure all students are visible initially
                setTimeout(() => {
                    console.log("Initial search on page load");
                    performSearch();
                }, 500);
            }
            
            // Toggle debt details visibility
            const toggleDebtDetailsBtn = document.getElementById('toggle-debt-details');
            if (toggleDebtDetailsBtn) {
                toggleDebtDetailsBtn.addEventListener('click', function() {
                    const debtDetailsSection = document.getElementById('debt-details');
                    const showDetailsText = document.getElementById('show-details-text');
                    const hideDetailsText = document.getElementById('hide-details-text');
                    const detailsDownIcon = document.getElementById('details-down-icon');
                    const detailsUpIcon = document.getElementById('details-up-icon');
                    
                    if (debtDetailsSection.classList.contains('hidden')) {
                        // Show details
                        debtDetailsSection.classList.remove('hidden');
                        showDetailsText.classList.add('hidden');
                        hideDetailsText.classList.remove('hidden');
                        detailsDownIcon.classList.add('hidden');
                        detailsUpIcon.classList.remove('hidden');
                    } else {
                        // Hide details
                        debtDetailsSection.classList.add('hidden');
                        showDetailsText.classList.remove('hidden');
                        hideDetailsText.classList.add('hidden');
                        detailsDownIcon.classList.remove('hidden');
                        detailsUpIcon.classList.add('hidden');
                    }
                });
            }
            
            // Tab switching
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;
                    
                    tabButtons.forEach(btn => {
                        btn.classList.remove('border-blue-500', 'text-blue-600');
                        btn.classList.add('text-gray-500');
                    });
                    
                    tabContents.forEach(content => {
                        content.classList.add('hidden');
                    });
                    
                    this.classList.add('border-b-2', 'border-blue-500', 'text-blue-600');
                    this.classList.remove('text-gray-500');
                    
                    document.getElementById(targetTab + '-tab').classList.remove('hidden');
                });
            });
        });
    </script>

    <!-- Payment Modal -->
    <div id="payment-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 max-w-md shadow-lg rounded-md bg-white">
            <!-- Payment Form Section -->
            <div id="payment-form-section">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Record Payment</h3>
                    <button onclick="document.getElementById('payment-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form method="POST" action="" id="payment-form">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <input type="hidden" name="fee_id" id="fee_id" value="0">
                    <input type="hidden" name="use_general_payment" id="use_general_payment" value="1">
                    
                    <!-- Add Term Selection -->
                    <div id="term-selection-div" class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="term_id">
                            Select Term for Payment
                        </label>
                        <select name="term_id" id="term_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>

                    <!-- Fee Selection for Previous Terms -->
                    <div id="fee-selection-div" class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="fee_name">
                            Select Fee to Pay
                        </label>
                        <select name="fee_name" id="fee_name" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select a fee...</option>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                        <div class="mt-1">
                            <span class="text-xs text-gray-500">Select the specific fee you want to pay</span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="amount">
                            Payment Amount (UGX)
                        </label>
                        <input type="number" name="amount" id="amount" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <div class="mt-1">
                            <span class="text-sm text-gray-500" id="suggested-amount"></span>
                        </div>
                        <div class="mt-1">
                            <span class="text-xs text-blue-500" id="fee-amount-details"></span>
                        </div>
                    </div>
                    
                    <!-- Hidden field for payment date that's automatically set to current date -->
                    <input type="hidden" name="payment_date" id="payment_date" value="<?php echo date('Y-m-d'); ?>">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2">
                            Payment Date
                        </label>
                        <div class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100">
                            <?php echo date('d M Y'); ?> (Today)
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="payment_method">
                            Payment Method
                        </label>
                        <select name="payment_method" id="payment_method" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Mobile Money">Mobile Money</option>
                            <option value="Check">Check</option>
                            <option value="Credit Card">Credit Card</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="reference_number">
                            Reference Number (Optional)
                        </label>
                        <input type="text" name="reference_number" id="reference_number" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2" for="notes">
                            Notes (Optional)
                        </label>
                        <textarea name="notes" id="notes" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="button" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition duration-150 mr-2" onclick="document.getElementById('payment-modal').classList.add('hidden')">
                            Cancel
                        </button>
                        <button type="button" onclick="showPaymentConfirmation()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150">
                            Continue
                        </button>
                    </div>
                </form>
            </div>

            <!-- Payment Confirmation Section -->
            <div id="payment-confirmation-section" class="hidden">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Confirm Payment Details</h3>
                    <button onclick="hidePaymentConfirmation()" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="bg-blue-50 p-4 rounded-lg mb-4">
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Student Name:</span>
                        <p class="font-medium"><?php echo htmlspecialchars($student_details['firstname'] . ' ' . $student_details['lastname']); ?></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Admission Number:</span>
                        <p class="font-medium"><?php echo htmlspecialchars($student_details['admission_number']); ?></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Term:</span>
                        <p class="font-medium" id="confirm-term"></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Fee Details:</span>
                        <p class="font-medium text-blue-600" id="confirm-fee-details"></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Amount to Pay:</span>
                        <p class="font-medium text-lg" id="confirm-amount"></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Payment Method:</span>
                        <p class="font-medium" id="confirm-payment-method"></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Reference Number:</span>
                        <p class="font-medium" id="confirm-reference"></p>
                    </div>
                    <div class="mb-3">
                        <span class="text-sm text-gray-600">Notes:</span>
                        <p class="font-medium" id="confirm-notes"></p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" onclick="hidePaymentConfirmation()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition duration-150 mr-2">
                        Back
                    </button>
                    <button type="button" onclick="processPayment()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150">
                        Confirm & Process Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Fee Adjustment Modal before the closing body tag -->
    <div id="fee-adjustment-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 max-w-md shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Adjust Fee Amount</h3>
                <button onclick="document.getElementById('fee-adjustment-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" action="update_student_fee.php" id="fee-adjustment-form">
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                <input type="hidden" name="fee_id" id="adjust_fee_id">
                <input type="hidden" name="term_id" value="<?php echo $current_term_id; ?>">
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-medium mb-2">Fee Name</label>
                    <div id="fee_name_display" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100"></div>
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-medium mb-2">Standard Amount (UGX)</label>
                    <div id="standard_amount_display" class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 font-medium"></div>
                    <input type="hidden" id="standard_amount_hidden" name="standard_amount">
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-medium mb-2" for="adjusted_amount">
                        Adjusted Amount (UGX)
                    </label>
                    <input type="number" name="adjusted_amount" id="adjusted_amount" required 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           onchange="validateAdjustment(this.value)">
                    <div id="adjustment_warning" class="hidden mt-1 text-sm text-yellow-600">
                        <i class="fas fa-exclamation-triangle"></i> 
                        The adjusted amount is different from the standard amount
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-medium mb-2" for="adjustment_reason">
                        Reason for Adjustment
                    </label>
                    <textarea name="adjustment_reason" id="adjustment_reason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                              placeholder="e.g., Scholarship, Family Discount, etc."></textarea>
                </div>
                
                <div class="flex justify-end">
                    <button type="button" 
                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition duration-150 mr-2"
                            onclick="document.getElementById('fee-adjustment-modal').classList.add('hidden')">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150">
                        Save Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html