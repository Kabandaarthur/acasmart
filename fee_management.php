 <?php
session_start();

// Check if user is logged in and is a bursar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }
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
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    die("Connection failed: " . $conn->connect_error);
}

// Handle AJAX requests first, before any HTML output
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    try {
        // Handle delete fee request
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete') {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            
            if (!isset($data['fee_id'])) {
                echo json_encode(['success' => false, 'message' => 'Fee ID is required']);
                exit;
            }
            
            $fee_id = intval($data['fee_id']);
            $school_id = $_SESSION['school_id'];
            
            // Check if there are any payments associated with this fee
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM fee_payments WHERE fee_id = ?");
            $stmt->bind_param("i", $fee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $payment_count = $result->fetch_assoc()['count'];
            $stmt->close();
            
            if ($payment_count > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete fee: There are payments associated with this fee']);
                exit;
            }
            
            // Delete fee
            $stmt = $conn->prepare("DELETE FROM fees WHERE id = ? AND school_id = ?");
            $stmt->bind_param("ii", $fee_id, $school_id);
            $result = $stmt->execute();
            
            if ($result && $stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Fee deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to delete fee. Error: ' . $conn->error . '. Fee ID: ' . $fee_id . ', School ID: ' . $school_id]);
            }
            $stmt->close();
            $conn->close();
            exit;
        }
        
        // Handle update fee request
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update') {
            if (!isset($_POST['fee_id']) || !isset($_POST['fee_name']) || !isset($_POST['amount'])) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            
            $fee_id = intval($_POST['fee_id']);
            $fee_name = "School Fees"; // Always use "School Fees" regardless of form input
            $amount = floatval($_POST['amount']);
            $school_id = $_SESSION['school_id'];
            
            if ($amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid amount']);
                exit;
            }

            // First get the existing fee's class_id to preserve it
            $stmt = $conn->prepare("SELECT class_id FROM fees WHERE id = ? AND school_id = ?");
            $stmt->bind_param("ii", $fee_id, $school_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing_fee = $result->fetch_assoc();
            $stmt->close();

            if (!$existing_fee) {
                echo json_encode(['success' => false, 'message' => 'Fee not found']);
                exit;
            }

            $class_id = $existing_fee['class_id'];
            
            $stmt = $conn->prepare("UPDATE fees SET fee_name = ?, amount = ? WHERE id = ? AND school_id = ?");
            $stmt->bind_param("sdii", $fee_name, $amount, $fee_id, $school_id);
            
            if ($stmt->execute() && ($stmt->affected_rows > 0 || $stmt->errno === 0)) {
                echo json_encode(['success' => true, 'message' => 'Fee updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update fee: ' . $conn->error]);
            }
            $stmt->close();
            $conn->close();
            exit;
        }
        
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        $conn->close();
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        $conn->close();
        exit;
    }
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

// Handle form submission for adding a fee
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_fee']) && !isset($_GET['action'])) {
    $fee_name = "School Fees"; // Always use "School Fees" regardless of form input
    $fee_amount = $_POST['fee_amount'];
    $class_id = $_POST['class_id'];
    $section = $_POST['section'];

    // Insert new fee into the database
    $insert_fee = "INSERT INTO fees (school_id, term_id, class_id, section, fee_name, amount) 
                    VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_fee);
    $stmt->bind_param("iiissd", $school_id, $current_term_id, $class_id, $section, $fee_name, $fee_amount);
    
    if ($stmt->execute()) {
        $success_message = "Fee added successfully.";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $error_message = "Error adding fee: " . $conn->error;
    }
    $stmt->close();
}

// Fetch fee types for the current school and term
$fee_query = "SELECT f.id, f.fee_name, f.amount, f.description, f.section, c.name as class_name, 
                    (SELECT COUNT(*) FROM fee_payments WHERE fee_id = f.id) as payment_count 
              FROM fees f
              LEFT JOIN classes c ON f.class_id = c.id
              WHERE f.school_id = ? AND f.term_id = ?
              ORDER BY f.id DESC";
$stmt = $conn->prepare($fee_query);
$stmt->bind_param("ii", $school_id, $current_term_id);
$stmt->execute();
$fees_result = $stmt->get_result();
$fees = $fees_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all classes for this school
$class_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY name";
$stmt = $conn->prepare($class_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$class_result = $stmt->get_result();
$classes = $class_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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

// Close the database connection at the end of the file, after all HTML output
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Management - <?php echo htmlspecialchars($school_name); ?></title>
    <!-- TailwindCSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <!-- Datepicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
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
        
        /* Mobile Menu */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                height: 100vh;
                top: 0;
                left: 0;
                z-index: 40;
            }
            
            .main-content {
                margin-left: 0;
            }
        }

        /* Smooth scrolling for mobile menu */
        .sidebar {
            -webkit-overflow-scrolling: touch;
            overflow-y: auto;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none; /* IE and Edge */
        }
        
        /* Hide scrollbar for Chrome, Safari and Opera */
        .sidebar::-webkit-scrollbar {
            display: none;
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
                        Fee Management
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
                <!-- AJAX Alerts -->
                <div id="successAlert" class="hidden bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-md fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span class="alert-message"></span>
                        <button onclick="this.closest('div.bg-green-100').classList.add('hidden')" class="ml-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <div id="errorAlert" class="hidden bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-md fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span class="alert-message"></span>
                        <button onclick="this.closest('div.bg-red-100').classList.add('hidden')" class="ml-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- PHP Alerts -->
                <?php if (!empty($success_message)): ?>
                <div id="php-success-alert" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-md fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <span><?php echo htmlspecialchars($success_message); ?></span>
                        <button onclick="this.closest('div.bg-green-100').classList.add('hidden')" class="ml-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                <div id="php-error-alert" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-md fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                        <button onclick="this.closest('div.bg-red-100').classList.add('hidden')" class="ml-auto">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Fee Management Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Fee List -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-lg shadow-md p-6 modern-card">
                            <div class="flex justify-between items-center mb-6">
                                <h2 class="text-lg font-semibold text-gray-800">Fee Types</h2>
                                <div class="flex space-x-2">
                                    <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow-md transition duration-150" onclick="window.print()">
                                        <i class="fas fa-print mr-2"></i>Print
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Fee List Table -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full bg-white">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fee Name</th>
                                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section</th>
                                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php if (empty($fees)): ?>
                                        <tr>
                                            <td colspan="6" class="py-4 px-4 text-center text-sm text-gray-500">No fees have been defined yet</td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($fees as $fee): ?>
                                            <tr class="hover:bg-gray-50 transition duration-150">
                                                <td class="py-3 px-4 text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($fee['fee_name']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-sm text-gray-900">
                                                    UGX <?php echo number_format($fee['amount']); ?>
                                                </td>
                                                <td class="py-3 px-4 text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($fee['class_name'] ?? 'All Classes'); ?>
                                                </td>
                                                <td class="py-3 px-4 text-sm text-gray-500">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $fee['section'] === 'boarding' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'; ?>">
                                                        <?php echo ucfirst(htmlspecialchars($fee['section'])); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4 text-sm">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        Active
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4 text-sm text-gray-500">
                                                    <div class="flex space-x-3">
                                                        <button type="button" 
                                                                onclick="editFee(<?php echo $fee['id']; ?>, '<?php echo addslashes($fee['fee_name']); ?>', <?php echo $fee['amount']; ?>, <?php echo $fee['class_id'] ?? 'null'; ?>, '<?php echo addslashes($fee['class_name'] ?? 'All Classes'); ?>', '<?php echo $fee['section']; ?>')" 
                                                                class="text-blue-600 hover:text-blue-900 transition duration-150 flex items-center" 
                                                                title="Edit Fee">
                                                            <i class="fas fa-edit mr-1"></i> Edit
                                                        </button>
                                                        <button type="button"
                                                                onclick="deleteFee(<?php echo $fee['id']; ?>, '<?php echo addslashes($fee['fee_name']); ?>')" 
                                                                class="text-red-600 hover:text-red-900 transition duration-150 flex items-center"
                                                                title="Delete Fee">
                                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Add Fee Form -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-lg shadow-md p-6 modern-card">
                            <h2 class="text-lg font-semibold text-gray-800 mb-4">Add New Fee</h2>
                            
                            <form action="fee_management.php" method="POST">
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-medium mb-2" for="fee_name">
                                        Fee Name
                                    </label>
                                    <input type="text" name="fee_name" id="fee_name" required value="School Fees" readonly
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 bg-gray-100 cursor-not-allowed">
                                    <p class="mt-1 text-xs text-gray-500">Only "School Fees" is allowed as fee name</p>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-medium mb-2" for="fee_amount">
                                        Amount (UGX)
                                    </label>
                                    <input type="number" name="fee_amount" id="fee_amount" step="1" required min="0"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-medium mb-2" for="class_id">
                                        Class
                                    </label>
                                    <select name="class_id" id="class_id" required
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Select Class</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="mt-1 text-xs text-red-600">* Class selection is mandatory</p>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-medium mb-2" for="section">
                                        Section
                                    </label>
                                    <select name="section" id="section" required
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Select Section</option>
                                        <option value="boarding">Boarding</option>
                                        <option value="day">Day</option>
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">Different fees can be set for boarding and day students</p>
                                </div>
                                

                                
                                <div class="flex justify-end">
                                    <button type="submit" name="add_fee" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-150">
                                        <i class="fas fa-plus mr-2"></i> Add Fee
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Fee Modal -->
                <div id="editFeeModal" class="modal hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Edit Fee</h3>
                            <button class="modal-close text-gray-400 hover:text-gray-500">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <form onsubmit="return updateFee(this)">
                            <input type="hidden" id="edit_fee_id" name="fee_id">
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-medium mb-2" for="edit_fee_name">
                                    Fee Name
                                </label>
                                <input type="text" id="edit_fee_name" name="fee_name" required value="School Fees" readonly
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 bg-gray-100 cursor-not-allowed">
                                <p class="mt-1 text-xs text-gray-500">Only "School Fees" is allowed as fee name</p>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-medium mb-2" for="edit_amount">
                                    Amount (UGX)
                                </label>
                                <input type="number" id="edit_amount" name="amount" required min="0"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-medium mb-2" for="edit_class_name">
                                    Class
                                </label>
                                <input type="text" id="edit_class_name" readonly
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 bg-gray-100 cursor-not-allowed">
                            </div>

                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-medium mb-2" for="edit_section">
                                    Section
                                </label>
                                <input type="text" id="edit_section" readonly
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 bg-gray-100 cursor-not-allowed">
                            </div>
                            

                            
                            <div class="flex justify-end space-x-3">
                                <button type="button" class="modal-close px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition duration-150">
                                    Cancel
                                </button>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-150">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Delete Fee Modal -->
                <div id="deleteFeeModal" class="modal hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
                    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Delete Fee</h3>
                            <button class="modal-close text-gray-400 hover:text-gray-500">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <div class="mb-4">
                            <p class="text-gray-500">Are you sure you want to delete the fee "<span id="delete_fee_name" class="font-medium"></span>"?</p>
                            <p class="text-gray-500 text-sm mt-2">This action cannot be undone.</p>
                        </div>
                        
                        <input type="hidden" id="delete_fee_id">
                        
                        <div class="flex justify-end space-x-3">
                            <button class="modal-close px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition duration-150">
                                Cancel
                            </button>
                            <button type="button" onclick="confirmDelete(event)" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition duration-150">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript -->
    <script>
        // Fee management functions
        function editFee(feeId, feeName, amount, classId, className, section) {
            document.getElementById('edit_fee_id').value = feeId;
            document.getElementById('edit_fee_name').value = "School Fees"; // Always set to "School Fees"
            document.getElementById('edit_amount').value = amount;
            document.getElementById('edit_class_name').value = className || 'All Classes';
            document.getElementById('edit_section').value = section ? section.charAt(0).toUpperCase() + section.slice(1) : '';
            
            document.getElementById('editFeeModal').classList.remove('hidden');
        }

        function updateFee(form) {
            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton.textContent;
            submitButton.textContent = 'Updating...';
            submitButton.disabled = true;

            fetch('fee_management.php?action=update', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Server response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    document.getElementById('editFeeModal').classList.add('hidden');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert('error', data.message || 'Failed to update fee');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', error.message || 'An error occurred while updating the fee');
            })
            .finally(() => {
                submitButton.textContent = originalText;
                submitButton.disabled = false;
            });
            
            return false;
        }

        function deleteFee(feeId, feeName) {
            document.getElementById('delete_fee_id').value = feeId;
            document.getElementById('delete_fee_name').textContent = feeName;
            document.getElementById('deleteFeeModal').classList.remove('hidden');
        }

        function confirmDelete(event) {
            event.preventDefault();
            const feeId = document.getElementById('delete_fee_id').value;
            const deleteButton = document.querySelector('#deleteFeeModal button.bg-red-600');
            const originalText = deleteButton.textContent;
            deleteButton.textContent = 'Deleting...';
            deleteButton.disabled = true;

            fetch('fee_management.php?action=delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ fee_id: feeId })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Server response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    document.getElementById('deleteFeeModal').classList.add('hidden');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showAlert('error', data.message || 'Failed to delete fee');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', error.message || 'An error occurred while deleting the fee');
            })
            .finally(() => {
                deleteButton.textContent = originalText;
                deleteButton.disabled = false;
            });
        }

        function showAlert(type, message) {
            // Hide any existing alerts first
            document.querySelectorAll('.alert').forEach(alert => {
                alert.classList.add('hidden');
            });
            
            const alertElement = document.getElementById(type === 'success' ? 'successAlert' : 'errorAlert');
            if (alertElement) {
                alertElement.querySelector('.alert-message').textContent = message;
                alertElement.classList.remove('hidden');
                
                // Auto-hide after 5 seconds
                setTimeout(() => {
                    alertElement.classList.add('hidden');
                }, 5000);
            }
        }

        // Initialize when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize flatpickr if needed
            if (typeof flatpickr !== 'undefined') {
                flatpickr(".datepicker", {
                    dateFormat: "Y-m-d",
                    minDate: "today"
                });
            }

            // Modal close handlers
            document.querySelectorAll('.modal-close').forEach(button => {
                button.addEventListener('click', function() {
                    this.closest('.modal').classList.add('hidden');
                });
            });

            // Click outside modal to close
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.add('hidden');
                    }
                });
            });
        });
    </script>
</body>
</html>