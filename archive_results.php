 <?php
session_start();

// Check if user is admin
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

$school_id = $_SESSION['school_id'];
$message = '';
$show_confirmation = false;
$selected_term = null;

// Function to archive results to a CSV file
function archiveResults($conn, $term_id, $school_id) {
    // Create archives directory if it doesn't exist
    $archive_dir = 'archives';
    if (!file_exists($archive_dir)) {
        mkdir($archive_dir, 0777, true);
    }

    // Get term information
    $term_query = "SELECT name, year FROM terms WHERE id = ? AND school_id = ?";
    $stmt = $conn->prepare($term_query);
    $stmt->bind_param("ii", $term_id, $school_id);
    $stmt->execute();
    $term_result = $stmt->get_result();
    $term_info = $term_result->fetch_assoc();

    // Generate archive filename
    $filename = $archive_dir . "/results_" . $school_id . "_" . $term_info['name'] . "_" . $term_info['year'] . "_" . date('Y-m-d') . ".csv";

    // Get all results for the term
    $query = "SELECT 
        s.firstname, 
        s.lastname,
        c.name as class_name,
        sub.subject_name,
        e.exam_type,
        e.category,
        er.score,
        e.max_score,
        er.upload_date
    FROM exam_results er
    JOIN students s ON er.student_id = s.id
    JOIN subjects sub ON er.subject_id = sub.subject_id
    JOIN classes c ON s.class_id = c.id
    JOIN exams e ON er.exam_id = e.exam_id
    WHERE er.term_id = ? AND er.school_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $term_id, $school_id);
    $stmt->execute();
    $results = $stmt->get_result();

    // Open CSV file for writing
    $file = fopen($filename, 'w');
    
    // Write headers
    fputcsv($file, ['Student Name', 'Class', 'Subject', 'Exam Type', 'Category', 'Score', 'Max Score', 'Upload Date']);

    // Write data
    while ($row = $results->fetch_assoc()) {
        fputcsv($file, [
            $row['firstname'] . ' ' . $row['lastname'],
            $row['class_name'],
            $row['subject_name'],
            $row['exam_type'],
            $row['category'],
            $row['score'],
            $row['max_score'],
            $row['upload_date']
        ]);
    }

    fclose($file);
    return $filename;
}

// Function to delete old results
function deleteOldResults($conn, $term_id, $school_id) {
    // Delete exam results
    $delete_results = "DELETE FROM exam_results WHERE term_id = ? AND school_id = ?";
    $stmt = $conn->prepare($delete_results);
    $stmt->bind_param("ii", $term_id, $school_id);
    return $stmt->execute();
}

// Handle form submission for confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['select_term'])) {
        $term_id = $_POST['term_id'];
        if (!empty($term_id)) {
            $show_confirmation = true;
            $selected_term = $term_id;
        }
    } elseif (isset($_POST['confirm_archive'])) {
        $term_id = $_POST['term_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Archive results first
            $archive_file = archiveResults($conn, $term_id, $school_id);
            
            if ($archive_file) {
                // Delete old results only if archiving was successful
                if (deleteOldResults($conn, $term_id, $school_id)) {
                    $conn->commit();
                    $message = 'success';
                } else {
                    throw new Exception("Failed to delete old results");
                }
            } else {
                throw new Exception("Failed to archive results");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'error';
        }
    }
}

// Get all completed terms for this school
$terms_query = "SELECT t.id, t.name, t.year, 
                COUNT(er.exam_id) as result_count 
                FROM terms t 
                LEFT JOIN exam_results er ON t.id = er.term_id 
                WHERE t.school_id = ? AND t.is_current = 0 
                GROUP BY t.id 
                ORDER BY t.year DESC, t.id DESC";
$stmt = $conn->prepare($terms_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive Exam Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .gradient-custom {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        }
        .notification {
            position: fixed;
            top: 1rem;
            right: 1rem;
            padding: 1rem;
            border-radius: 0.5rem;
            color: white;
            transform: translateX(150%);
            transition: transform 0.3s ease-in-out;
            z-index: 50;
        }
        .notification.show {
            transform: translateX(0);
        }
        .term-card {
            transition: all 0.3s ease;
        }
        .term-card:hover {
            transform: translateY(-2px);
        }
        .modal {
            transition: opacity 0.3s ease-in-out;
        }
        @keyframes slideIn {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-content {
            animation: slideIn 0.3s ease-out forwards;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Success Notification -->
    <div id="successNotification" class="notification bg-green-500 shadow-lg <?php echo $message === 'success' ? 'show' : ''; ?>">
        <div class="flex items-center">
            <i class="fas fa-check-circle text-2xl mr-3"></i>
            <div>
                <h3 class="font-bold">Success!</h3>
                <p class="text-sm">Results have been archived successfully.</p>
            </div>
        </div>
    </div>

    <!-- Error Notification -->
    <div id="errorNotification" class="notification bg-red-500 shadow-lg <?php echo $message === 'error' ? 'show' : ''; ?>">
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle text-2xl mr-3"></i>
            <div>
                <h3 class="font-bold">Error!</h3>
                <p class="text-sm">Failed to archive results. Please try again.</p>
            </div>
        </div>
    </div>

    <!-- Header -->
    <header class="gradient-custom text-white shadow-lg">
        <div class="container mx-auto px-4 py-6">
            <div class="flex justify-between items-center">
                <h1 class="text-3xl font-bold">
                    <i class="fas fa-archive mr-2"></i>Archive Results
                </h1>
                <a href="school_admin_dashboard.php" class="bg-white text-blue-800 px-4 py-2 rounded-lg hover:bg-blue-50 transition duration-150">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8">
        <!-- Warning Card -->
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8 rounded-lg">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-yellow-400 text-xl"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">
                        Important Notice
                    </h3>
                    <div class="mt-2 text-sm text-yellow-700">
                        <p>Archiving will move results to a CSV file and remove them from the database. This action cannot be undone.</p>
                        <p class="mt-1">Please ensure you:</p>
                        <ul class="list-disc list-inside mt-1">
                            <li>Have completed all grading for the term</li>
                            <li>Have downloaded any necessary reports</li>
                            <li>Are ready to move these results to long-term storage</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Terms Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($terms as $term): ?>
                <div class="term-card bg-white rounded-lg shadow-sm border hover:shadow-md">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">
                                    <?php echo htmlspecialchars($term['name']); ?>
                                </h3>
                                <p class="text-sm text-gray-500">
                                    Academic Year: <?php echo htmlspecialchars($term['year']); ?>
                                </p>
                            </div>
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                <?php echo $term['result_count']; ?> Results
                            </span>
                        </div>
                        
                        <form method="post" class="mt-4">
                            <input type="hidden" name="term_id" value="<?php echo $term['id']; ?>">
                            <button type="submit" name="select_term" 
                                    class="w-full bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition duration-150 flex items-center justify-center">
                                <i class="fas fa-archive mr-2"></i>
                                Archive Term
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($terms)): ?>
            <div class="text-center py-12">
                <i class="fas fa-folder-open text-gray-400 text-5xl mb-4"></i>
                <h3 class="text-xl font-medium text-gray-600">No Completed Terms</h3>
                <p class="text-gray-500 mt-2">There are no completed terms available for archiving.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Confirmation Modal -->
    <?php if ($show_confirmation): ?>
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 modal">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 modal-content">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">
                        Confirm Archive
                    </h3>
                    <p class="text-gray-600 mb-6">
                        Are you sure you want to archive this term's results? This action cannot be undone.
                    </p>
                    <div class="flex justify-end space-x-4">
                        <form method="post" class="flex space-x-4">
                            <input type="hidden" name="term_id" value="<?php echo $selected_term; ?>">
                            <button type="submit" name="cancel" 
                                    class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" name="confirm_archive" 
                                    class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition-colors">
                                Confirm Archive
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Auto-hide notifications after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach(notification => {
                if (notification.classList.contains('show')) {
                    setTimeout(() => {
                        notification.classList.remove('show');
                    }, 5000);
                }
            });
        });
    </script>
</body>
</html>