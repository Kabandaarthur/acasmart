 <?php
session_start();

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
$message = '';

// Get A-Level classes
$classes_query = $conn->prepare("
    SELECT id, name FROM classes 
    WHERE school_id = ? AND (name LIKE '%SENIOR FIVE%' OR name LIKE '%SENIOR SIX%')
    ORDER BY name
");
$classes_query->bind_param("i", $user_school_id);
$classes_query->execute();
$classes_result = $classes_query->get_result();

// Get terms
$terms_query = $conn->prepare("SELECT id, name, year FROM terms WHERE school_id = ? ORDER BY year DESC, name");
$terms_query->bind_param("i", $user_school_id);
$terms_query->execute();
$terms_result = $terms_query->get_result();

// Get exam types
$exam_types_query = $conn->prepare("SELECT DISTINCT exam_type FROM exams WHERE school_id = ? ORDER BY exam_type");
$exam_types_query->bind_param("i", $user_school_id);
$exam_types_query->execute();
$exam_types_result = $exam_types_query->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download A-Level Reports - School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f3f4f6; }
        .page-header { background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); padding: 2rem 0; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .card { background: white; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); transition: transform 0.2s ease; }
        .card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="page-header">
        <div class="container mx-auto px-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <i class="fas fa-graduation-cap text-white text-3xl"></i>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Download A-Level Reports</h1>
                        <p class="text-blue-100 text-sm">Generate and download reports for Senior Five and Senior Six students</p>
                    </div>
                </div>
                <a href="school_admin_dashboard.php" class="flex items-center px-4 py-2 bg-white text-blue-600 rounded-lg hover:bg-blue-50 transition-colors duration-300">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4">
        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <!-- A-Level Report Generator -->
        <div class="card p-6">
            <div class="flex items-center space-x-4 mb-6">
                <i class="fas fa-file-alt text-2xl text-blue-500"></i>
                <h2 class="text-xl font-semibold">Generate A-Level Reports</h2>
            </div>

            <form method="POST" action="generate_alevel_reports.php" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="class_id" class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                        <select name="class_id" id="class_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Choose A-Level Class</option>
                            <?php while ($class = $classes_result->fetch_assoc()): ?>
                                <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="exam_type" class="block text-sm font-medium text-gray-700 mb-2">Exam Type</label>
                        <select name="exam_type" id="exam_type" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Exam Type</option>
                            <?php while ($exam_type = $exam_types_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($exam_type['exam_type']); ?>"><?php echo htmlspecialchars($exam_type['exam_type']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div>
                        <label for="term_id" class="block text-sm font-medium text-gray-700 mb-2">Term</label>
                        <select name="term_id" id="term_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Term</option>
                            <?php while ($term = $terms_result->fetch_assoc()): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name'] . ' ' . $term['year']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-blue-700">
                                <strong>Note:</strong> This will generate A-Level report cards for all students in the selected class. 
                                The reports will include A1, A2, ACT, E.O.T scores with automatic calculation of paper grades and subject grades.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-center">
                    <button type="submit" name="generate_alevel_reports" class="bg-blue-500 text-white px-8 py-3 rounded-lg hover:bg-blue-600 transition-colors duration-300 flex items-center">
                        <i class="fas fa-download mr-2"></i>
                        Generate A-Level Reports
                    </button>
                </div>
            </form>
        </div>

        <!-- Instructions -->
        <div class="card p-6 mt-8">
            <div class="flex items-center space-x-4 mb-6">
                <i class="fas fa-info-circle text-2xl text-blue-500"></i>
                <h2 class="text-xl font-semibold">A-Level Report Features</h2>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div class="flex items-start space-x-3">
                        <div class="bg-blue-100 p-2 rounded-full">
                            <i class="fas fa-calculator text-blue-600"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold">Automatic Calculations</h4>
                            <p class="text-gray-600">ACT scores calculated from A1 and A2 assessments. Total scores and grades computed automatically.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-3">
                        <div class="bg-green-100 p-2 rounded-full">
                            <i class="fas fa-chart-line text-green-600"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold">Paper & Subject Grades</h4>
                            <p class="text-gray-600">Automatic calculation of paper grades (D1-F9) and subject grades (A-O) based on total scores.</p>
                        </div>
                    </div>
                </div>
                
                <div class="space-y-4">
                    <div class="flex items-start space-x-3">
                        <div class="bg-purple-100 p-2 rounded-full">
                            <i class="fas fa-users text-purple-600"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold">Combination Support</h4>
                            <p class="text-gray-600">Displays student combinations and streams specific to A-Level structure.</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-3">
                        <div class="bg-orange-100 p-2 rounded-full">
                            <i class="fas fa-file-archive text-orange-600"></i>
                        </div>
                        <div>
                            <h4 class="font-semibold">Bulk Download</h4>
                            <p class="text-gray-600">Download all A-Level reports as a ZIP file for easy distribution and printing.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>