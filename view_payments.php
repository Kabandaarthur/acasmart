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

// Initialize variables for filtering
$school_id = $_SESSION['school_id'];
$selected_term = isset($_GET['term_id']) && !empty($_GET['term_id']) ? $_GET['term_id'] : '';
$selected_class = isset($_GET['class_id']) && !empty($_GET['class_id']) ? $_GET['class_id'] : '';

// Get all terms for the school
$terms_query = "SELECT * FROM terms WHERE school_id = ? ORDER BY year DESC, name DESC";
$stmt = $conn->prepare($terms_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all classes for the school
$classes_query = "SELECT * FROM classes WHERE school_id = ? ORDER BY name";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = $classes_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build the WHERE clause based on filters
$where_conditions = "WHERE s.school_id = ? AND t.school_id = s.school_id";
$params = [$school_id];  // Start with school_id
$types = 'i';  // Start with integer type for school_id

if ($selected_term) {
    $where_conditions .= " AND t.id = ?";
    $params[] = $selected_term;
    $types .= 'i';
}

if ($selected_class) {
    $where_conditions .= " AND s.class_id = ?";
    $params[] = $selected_class;
    $types .= 'i';
}

// Main query to get all students and their payments
$query = "
    WITH StudentTerms AS (
        -- Get all terms for each student with their enrollment history
        SELECT 
            s.id as student_id,
            s.firstname,
            s.lastname,
            s.admission_number,
            s.school_id,
            s.section,
            t.id as term_id,
            t.name as term_name,
            t.year as term_year,
            t.is_current,
            CASE 
                WHEN t.is_current = 1 THEN s.class_id  -- For current term, use current class
                ELSE COALESCE(
                    (SELECT se.class_id 
                     FROM student_enrollments se 
                     WHERE se.student_id = s.id 
                     AND se.term_id = t.id 
                     AND se.school_id = s.school_id
                     ORDER BY se.created_at DESC 
                     LIMIT 1),
                    (SELECT se2.class_id
                     FROM student_enrollments se2
                     WHERE se2.student_id = s.id
                     AND se2.school_id = s.school_id
                     AND se2.term_id <= t.id
                     ORDER BY se2.term_id DESC, se2.created_at DESC
                     LIMIT 1),
                    s.class_id
                )
            END as effective_class_id
        FROM students s
        CROSS JOIN terms t
        $where_conditions
    ),
    TermFees AS (
        -- Calculate fees and payments for each term
        SELECT 
            st.*,
            c.name as class_name,
            COALESCE(
                (
                    SELECT 
                        CASE 
                            WHEN sfa.adjusted_amount IS NOT NULL THEN sfa.adjusted_amount
                            ELSE f.amount 
                        END
                    FROM fees f
                    LEFT JOIN student_fee_adjustments sfa ON sfa.student_id = st.student_id 
                        AND sfa.term_id = f.term_id 
                        AND sfa.fee_id = f.id
                    WHERE f.term_id = st.term_id
                    AND f.class_id = st.effective_class_id
                    AND f.section = st.section
                    AND f.school_id = st.school_id
                    AND f.status = 'active'
                    LIMIT 1
                ),
                0
            ) as expected_amount,
            COALESCE(
                (SELECT SUM(fp.amount)
                 FROM fee_payments fp
                 WHERE fp.student_id = st.student_id
                 AND fp.term_id = st.term_id
                 AND fp.school_id = st.school_id),
                0
            ) as paid_amount
        FROM StudentTerms st
        LEFT JOIN classes c ON c.id = st.effective_class_id
    )
    SELECT 
        tf.student_id,
        CONCAT(tf.firstname, ' ', tf.lastname) as student_name,
        tf.admission_number,
        tf.section,
        tf.class_name,
        tf.term_name,
        tf.term_year,
        tf.term_id,
        tf.effective_class_id as class_id,
        tf.expected_amount,
        tf.paid_amount,
        GREATEST(COALESCE(tf.expected_amount, 0) - COALESCE(tf.paid_amount, 0), 0) as balance,
        tf.is_current
    FROM TermFees tf
    WHERE tf.expected_amount > 0 OR tf.paid_amount > 0 OR tf.is_current = 1
    ORDER BY tf.admission_number ASC, tf.term_year DESC, tf.term_name DESC
";

// Prepare and execute the statement
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// After executing the query and before displaying results
if ($result) {
    $payments = $result->fetch_all(MYSQLI_ASSOC);
    
    // Debug current term fees
    foreach ($payments as $payment) {
        if ($payment['is_current'] == 1 && $payment['expected_amount'] == 0) {
            // Log the fee lookup parameters
            error_log("Debug - Current Term Fee Lookup:");
            error_log("Student ID: " . $payment['student_id']);
            error_log("Term ID: " . $payment['term_id']);
            error_log("Class ID: " . $payment['class_id']);
            error_log("Section: " . $payment['section']);
            
            // Check if fee exists
            $debug_query = "SELECT * FROM fees WHERE 
                term_id = ? AND 
                class_id = ? AND 
                section = ? AND 
                school_id = ? AND 
                status = 'active'";
            $debug_stmt = $conn->prepare($debug_query);
            $debug_stmt->bind_param("iisi", 
                $payment['term_id'], 
                $payment['class_id'], 
                $payment['section'], 
                $school_id
            );
            $debug_stmt->execute();
            $debug_result = $debug_stmt->get_result();
            
            if ($debug_result->num_rows == 0) {
                error_log("No fee found for these parameters");
            } else {
                $fee_record = $debug_result->fetch_assoc();
                error_log("Fee found: " . $fee_record['amount']);
            }
            $debug_stmt->close();
        }
    }
}

// Calculate totals
$total_expected = 0;
$total_paid = 0;
$total_balance = 0;

foreach ($payments as $payment) {
    $total_expected += $payment['expected_amount'];
    $total_paid += $payment['paid_amount'];
    $total_balance += $payment['balance'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Payments</title>
    <!-- Include Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Include DataTables CSS -->
    <link href="https://cdn.datatables.net/1.10.24/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Include DataTables Buttons CSS -->
    <link href="https://cdn.datatables.net/buttons/2.0.1/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <!-- Google Fonts - Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        .outstanding-balance {
            color: red !important;
            font-weight: 600 !important;
        }
        .amount-paid {
            color: green !important;
            font-weight: 600 !important;
        }
        .table {
            font-size: 0.9rem;
        }
        .table th {
            font-weight: 600;
        }
        .alert {
            font-size: 0.95rem;
        }
        h2 {
            font-weight: 600;
        }
        .form-label {
            font-weight: 500;
        }
        .btn {
            font-weight: 500;
        }
        .dataTables_wrapper {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Payment Records</h2>
            <div>
                <?php if (isset($selected_class) && isset($selected_term)): ?>
                    <a href="download_cleared_payments.php?class_id=<?php echo $selected_class; ?>&term_id=<?php echo $selected_term; ?>" class="btn btn-success me-2">
                        Download Cleared List
                    </a>
                    <a href="download_pending_payments.php?class_id=<?php echo $selected_class; ?>&term_id=<?php echo $selected_term; ?>" class="btn btn-warning me-2">
                        Download Pending List
                    </a>
                <?php endif; ?>
                <a href="bursar_dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Filter Form -->
        <form method="get" class="mb-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="term_id" class="form-label">Term</label>
                    <select name="term_id" id="term_id" class="form-select">
                        <option value="">All Terms</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo $term['id']; ?>" <?php echo ($selected_term == $term['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($term['name'] . ' ' . $term['year']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="class_id" class="form-label">Class</label>
                    <select name="class_id" id="class_id" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo ($selected_class == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="view_payments.php" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>

        <!-- Total Payments -->
        <div class="alert alert-info">
            <div class="row">
                <div class="col-md-4">
                    <strong>Total Expected:</strong> UGX <?php echo number_format($total_expected, 0); ?>
                </div>
                <div class="col-md-4">
                    <strong>Total Paid:</strong> <span class="amount-paid">UGX <?php echo number_format($total_paid, 0); ?></span>
                </div>
                <div class="col-md-4">
                    <strong>Outstanding Balance:</strong> <span class="outstanding-balance">UGX <?php echo number_format($total_balance, 0); ?></span>
                </div>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="table-responsive">
            <table id="payments-table" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>Admission Number</th>
                        <th>Student Name</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Term</th>
                        <th>Expected Amount</th>
                        <th>Total Amount Paid</th>
                        <th>Outstanding Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['admission_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($payment['student_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($payment['class_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($payment['section'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(($payment['term_name'] ?? '') . ' ' . ($payment['term_year'] ?? '')); ?></td>
                            <td><?php echo number_format($payment['expected_amount'] ?? 0, 0); ?></td>
                            <td class="amount-paid"><?php echo number_format($payment['paid_amount'] ?? 0, 0); ?></td>
                            <td class="outstanding-balance"><?php echo number_format($payment['balance'] ?? 0, 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" style="text-align: right;">Totals:</th>
                        <th><?php echo number_format($total_expected, 0); ?></th>
                        <th class="amount-paid"><?php echo number_format($total_paid, 0); ?></th>
                        <th class="outstanding-balance"><?php echo number_format($total_balance, 0); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- JavaScript Section -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.24/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.0.1/js/buttons.html5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#payments-table').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    'excel'
                ],
                order: [[0, 'asc'], [4, 'desc']],
                pageLength: 50,
                createdRow: function(row, data, dataIndex) {
                    // Apply the outstanding-balance class to the balance column (index 7)
                    $('td:eq(7)', row).addClass('outstanding-balance');
                    // Apply the amount-paid class to the paid amount column (index 6)
                    $('td:eq(6)', row).addClass('amount-paid');
                }
            });
        });
    </script>
</body>
</html