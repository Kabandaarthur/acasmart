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

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bursar') {
    header('Location: index.php');
    exit();
}

// Get current term for the school
$school_id = $_SESSION['school_id'];
$current_term_query = "SELECT id, name, year 
                      FROM terms 
                      WHERE school_id = ? 
                      AND is_current = 1
                      LIMIT 1";
$stmt = $conn->prepare($current_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$current_term = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current_term) {
    die("No active term found. Please set up the current term first.");
}

// Get all terms for viewing expenses
$terms_query = "SELECT id, name, year 
               FROM terms 
               WHERE school_id = ? 
               ORDER BY year DESC, id DESC";
$stmt = $conn->prepare($terms_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = "Expense Management";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo isset($page_title) ? $page_title . ' - School Management System' : 'School Management System'; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

    <!-- Google Fonts - Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }
        
        .font-poppins {
            font-family: 'Poppins', sans-serif;
        }
        
        .sb-nav-fixed {
            padding-top: 56px;
        }
        
        .sb-nav-fixed #layoutSidenav #layoutSidenav_nav {
            width: 225px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 56px;
        }
        
        .sb-nav-fixed #layoutSidenav #layoutSidenav_content {
            padding-left: 225px;
            top: 56px;
        }
        
        .sb-topnav {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1030;
            background: linear-gradient(90deg, #1e40af 0%, #3b82f6 100%);
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        #layoutSidenav {
            display: flex;
        }
        
        #layoutSidenav_nav {
            flex-basis: 225px;
            flex-shrink: 0;
            transition: transform .15s ease-in-out;
            z-index: 1038;
            transform: translateX(0);
        }
        
        .sb-sidenav-dark {
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
            color: rgba(255, 255, 255, 0.7);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .sb-sidenav-dark .sb-sidenav-menu .nav-link {
            color: rgba(255, 255, 255, 0.7);
            border-radius: 0.25rem;
            margin: 0.25rem 0.5rem;
            padding: 0.75rem 1rem;
            transition: all 0.3s;
        }
        
        .sb-sidenav-dark .sb-sidenav-menu .nav-link:hover {
            color: #fff;
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
        }
        
        .sb-sidenav-dark .sb-sidenav-menu .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
            font-weight: 500;
        }
        
        .sb-sidenav-menu {
            padding-top: 1rem;
        }
        
        /* Card Styles */
        .card {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            transform: translateY(-3px);
        }
        
        .hover-card {
            transition: all 0.3s ease;
        }
        
        .hover-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
        }
        
        /* Button Styles */
        .btn {
            border-radius: 0.4rem;
            font-weight: 500;
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        }
        
        @media (max-width: 768px) {
            .sb-nav-fixed #layoutSidenav #layoutSidenav_nav {
                transform: translateX(-225px);
            }
            .sb-nav-fixed #layoutSidenav #layoutSidenav_content {
                padding-left: 0;
            }
            .sb-sidenav-toggled #layoutSidenav #layoutSidenav_nav {
                transform: translateX(0);
            }
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
        
        /* Enhanced Table Styles */
        .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            font-family: 'Poppins', sans-serif;
        }

        .table thead th {
            background: linear-gradient(90deg, #f8f9fa 0%, #e9ecef 100%);
            border-top: none;
            border-bottom: 2px solid #e0e0e0;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            color: #495057;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            color: #212529;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .table tbody tr {
            transition: all 0.2s ease;
        }

        .table tbody tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
            transform: scale(1.005);
        }

        .table .amount-column {
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            min-width: 120px;
            text-align: right;
            color: #1e40af;
        }

        .table .date-column {
            min-width: 100px;
            white-space: nowrap;
            color: #6c757d;
        }

        .table .category-column {
            min-width: 120px;
            font-weight: 500;
        }

        .table .description-column {
            min-width: 200px;
            color: #495057;
        }

        .table .payment-method-column {
            min-width: 120px;
            color: #6c757d;
        }

        .table .recipient-column {
            min-width: 150px;
            font-weight: 500;
        }

        .table .action-column {
            width: 120px;
            white-space: nowrap;
        }
        
        .table .action-column .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
            margin-right: 0.25rem;
        }
        
        .table .action-column .btn-edit {
            color: #fff;
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        
        .table .action-column .btn-delete {
            color: #fff;
            background-color: #ef4444;
            border-color: #ef4444;
        }
        
        .table-responsive {
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            background-color: white;
            padding: 1rem;
            margin-bottom: 2rem;
        }
        
        /* Badge Styles */
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 0.25rem;
        }
        
        .badge-pending {
            background-color: #f59e0b;
            color: white;
        }
        
        .badge-approved {
            background-color: #10b981;
            color: white;
        }
        
        .badge-rejected {
            background-color: #ef4444;
            color: white;
        }

        .table .action-column .btn {
            padding: 0.375rem 0.75rem;
            margin: 0 2px;
        }

        /* Make table container scrollable horizontally */
        .table-responsive {
            overflow-x: auto;
            margin: 0 -1rem;  /* Negative margin to counter card padding */
            padding: 0 1rem;  /* Add padding back to maintain spacing */
            -webkit-overflow-scrolling: touch;
        }

        /* Ensure minimum width for better readability */
        #expensesTable {
            min-width: 1000px;  /* Minimum width to prevent cramping */
        }

        /* Style the payment method badge */
        .payment-method {
            text-transform: capitalize;
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
            border-radius: 4px;
            background-color: #e9ecef;
            display: inline-block;
        }

        /* Add some spacing between action buttons */
        .action-column .btn {
            margin: 0 0.2rem;
        }

        .action-column .btn i {
            margin-right: 0;
        }

        /* Improve card layout */
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1rem;
            background-color: #fff;
            border-bottom: 1px solid rgba(0, 0, 0, 0.125);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* Add Expense Button Styles */
        .add-expense-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(45deg, #2196F3, #1976D2);
            color: white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .add-expense-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.3);
        }

        .add-expense-btn i {
            transition: transform 0.3s ease;
        }

        .add-expense-btn.active i {
            transform: rotate(45deg);
        }

        /* Add Expense Form Modal Styles */
        .expense-form-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 999;
        }

        .expense-form-modal .card {
            width: 100%;
            max-width: 500px;
            margin: 1rem;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body class="sb-nav-fixed">   
    <div id="layoutSidenav">
        <div id="layoutSidenav_content">
            <main>
                <div class="container-fluid px-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <h1 class="mt-4 text-primary font-poppins font-semibold">
                            <i class="fas fa-file-invoice-dollar me-2"></i>Expense Management
                        </h1>
                    </div>
                    
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-4 bg-light p-2 rounded shadow-sm">
                            <li class="breadcrumb-item"><a href="bursar_dashboard.php" class="text-decoration-none"><i class="fas fa-home me-1"></i>Dashboard</a></li>
                            <li class="breadcrumb-item active">Expense Management</li>
                        </ol>
                    </nav>
                    
                    <!-- Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-xl-3 col-md-6">
                            <div class="card bg-primary text-white mb-4 shadow-sm hover-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="mb-0 font-poppins" id="total-expenses">UGX 0</h3>
                                            <div class="small">Total Expenses</div>
                                        </div>
                                        <div class="fa-3x text-white-50">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer d-flex align-items-center justify-content-between bg-primary border-top-0">
                                    <a class="small text-white stretched-link" href="#">View Details</a>
                                    <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-3 col-md-6">
                            <div class="card bg-success text-white mb-4 shadow-sm hover-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h3 class="mb-0 font-poppins" id="month-expenses">UGX 0</h3>
                                            <div class="small" id="current-month-label">This Month</div>
                                        </div>
                                        <div class="fa-3x text-white-50">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer d-flex align-items-center justify-content-between bg-success border-top-0">
                                    <a class="small text-white stretched-link" href="#" id="viewMonthlyExpenses">View Details</a>
                                    <div class="small text-white"><i class="fas fa-angle-right"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Term Selection -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <label for="termSelect" class="me-3 mb-0">Select Term:</label>
                                        <select class="form-select" id="termSelect">
                                            <?php foreach ($terms as $term): ?>
                                                <option value="<?php echo $term['id']; ?>">
                                                    <?php echo $term['name'] . ' ' . $term['year']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Success Alert -->
                    <div id="successAlert" class="alert alert-success alert-dismissible fade" role="alert" style="display: none;">
                        <i class="fas fa-check-circle me-2"></i>
                        <span id="successMessage"></span>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>

                    <div class="row">
                        <!-- Left Column -->
                        <div class="col-lg-4">
                            <!-- Category Summary Card -->
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <i class="fas fa-chart-pie"></i>
                                    Expense Categories Summary
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover category-summary-table mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Category</th>
                                                    <th class="text-end">Total Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody id="categorySummary">
                                                <!-- Will be populated dynamically -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column -->
                        <div class="col-lg-8">
                            <!-- Expense List -->
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                                    <div>
                                        <i class="fas fa-table"></i>
                                        Recent Expenses
                                    </div>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-light btn-sm" id="downloadPDF">
                                            <i class="fas fa-file-pdf me-1"></i>Download PDF
                                        </button>
                                        <button type="button" class="btn btn-light btn-sm" id="exportCSV">
                                            <i class="fas fa-file-csv me-1"></i>Export CSV
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table id="expensesTable" class="table table-hover" style="min-width: 1000px;">
                                            <thead>
                                                <tr>
                                                    <th style="min-width: 100px; white-space: nowrap;">Date</th>
                                                    <th style="min-width: 120px;">Category</th>
                                                    <th style="min-width: 200px;">Description</th>
                                                    <th style="min-width: 150px; text-align: right;">Amount (UGX)</th>
                                                    <th style="min-width: 120px;">Payment Method</th>
                                                    <th style="min-width: 150px;">Recipient</th>
                                                    <th style="width: 100px; white-space: nowrap;" class="text-center">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <!-- Data will be loaded dynamically -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
            <footer class="py-4 bg-light mt-auto">
                <div class="container-fluid px-4">
                    <div class="d-flex align-items-center justify-content-between small">
                        <div class="text-muted">Copyright &copy; School Management System <?php echo date('Y'); ?></div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Edit Expense Modal -->
    <div class="modal fade" id="editExpenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editExpenseForm">
                        <input type="hidden" id="edit_expense_id">
                        <div class="mb-3">
                            <label for="edit_term_id" class="form-label">Term</label>
                            <select class="form-select" id="edit_term_id" name="term_id" required>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>">
                                        <?php echo $term['name'] . ' ' . $term['year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Expense Category</label>
                            <input type="text" class="form-control" id="edit_category" name="category" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_amount" class="form-label">Amount</label>
                            <input type="number" class="form-control" id="edit_amount" name="amount" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_expense_date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="edit_expense_date" name="expense_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="edit_payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="edit_payment_method" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_recipient_name" class="form-label">Recipient Name</label>
                            <input type="text" class="form-control" id="edit_recipient_name" name="recipient_name" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saveExpenseChanges">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <div class="expense-form-modal" id="expenseFormModal">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-plus"></i>
                Record New Expense
                <button type="button" class="btn-close btn-close-white float-end" id="closeExpenseForm"></button>
            </div>
            <div class="card-body">
                <form id="expenseForm" method="post">
                    <!-- Hidden current term ID -->
                    <input type="hidden" name="term_id" value="<?php echo $current_term['id']; ?>">
                    
                    <!-- Current Term Info Display -->
                    <div class="alert alert-info mb-3">
                        Recording expense for: <strong><?php echo htmlspecialchars($current_term['name'] . ' ' . $current_term['year']); ?></strong>
                    </div>

                    <div class="mb-3">
                        <label for="category" class="form-label">Expense Category</label>
                        <input type="text" class="form-control" id="category" name="category" required placeholder="Enter expense category">
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount (UGX)</label>
                        <div class="input-group">
                            <span class="input-group-text">UGX</span>
                            <input type="number" class="form-control text-end" id="amount" name="amount" step="1" min="0" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="expense_date" class="form-label">Date</label>
                        <input type="date" class="form-control" id="expense_date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="">Select Payment Method</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="recipient_name" class="form-label">Recipient Name</label>
                        <input type="text" class="form-control" id="recipient_name" name="recipient_name" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-2"></i>Record Expense
                    </button>
                </form>
            </div>
        </div>
    </div>

    <button class="add-expense-btn" id="addExpenseBtn" title="Add New Expense">
        <i class="fas fa-plus"></i>
    </button>

    <!-- Add new Monthly Expenses Modal -->
    <div class="modal fade" id="monthlyExpensesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-calendar-alt me-2"></i>Monthly Expenses</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="monthYearSelect" class="form-label">Select Month:</label>
                            <input type="month" class="form-control" id="monthYearSelect">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="alert alert-success mb-0 w-100 py-2">
                                <strong>Total:</strong> <span id="selectedMonthTotal">UGX 0</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table id="monthlyExpensesTable" class="table table-hover" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                    <th>Payment Method</th>
                                    <th>Recipient</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Will be populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" id="downloadMonthlyExpensesPDF">
                        <i class="fas fa-file-pdf me-2"></i>Download PDF
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        let currentTermId = $('#termSelect').val();
        let currentTermForAdd = <?php echo $current_term['id']; ?>;
        let monthlyExpensesTable;

        // Handle term selection change
        $('#termSelect').on('change', function() {
            currentTermId = $(this).val();
            refreshData();
        });

        // Function to show success message
        function showSuccessMessage(message) {
            $('#successMessage').text(message);
            $('#successAlert').addClass('show').fadeIn(100).delay(3000).fadeOut(1000, function() {
                $(this).removeClass('show');
            });
        }

        // Initialize DataTable with improved column definitions
        const expensesTable = $('#expensesTable').DataTable({
            order: [[0, 'desc']],
            processing: true,
            serverSide: false,
            pageLength: 10,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rtip',
            scrollX: true,
            autoWidth: false,
            ajax: {
                url: 'ajax/get_expenses.php',
                data: function(d) {
                    d.term_id = currentTermId;
                },
                dataSrc: 'data'
            },
            columns: [
                { 
                    data: 'expense_date',
                    width: '100px'
                },
                { 
                    data: 'category',
                    width: '120px'
                },
                { 
                    data: 'description',
                    width: '200px'
                },
                { 
                    data: 'amount',
                    width: '180px',
                    className: 'text-end fw-bold',
                    render: function(data) {
                        // Format as whole number with thousands separator
                        const amount = Math.round(parseFloat(data));
                        return `<div style="font-family: monospace; font-size: 1.1em; white-space: nowrap;">
                            UGX ${amount.toLocaleString('en-US', {
                                minimumFractionDigits: 0,
                                maximumFractionDigits: 0,
                                useGrouping: true
                            })}
                        </div>`;
                    }
                },
                { 
                    data: 'payment_method',
                    width: '120px',
                    render: function(data) {
                        return `<span class="badge bg-light text-dark">${data.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</span>`;
                    }
                },
                { 
                    data: 'recipient_name',
                    width: '150px'
                },
                {
                    data: 'expense_id',
                    width: '100px',
                    orderable: false,
                    className: 'text-center',
                    render: function(data) {
                        return `
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-primary edit-expense" data-id="${data}" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                                <button class="btn btn-danger delete-expense" data-id="${data}" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                            </div>
                        `;
                    }
                }
            ],
            // Add custom styling for the amount column
            createdRow: function(row, data, dataIndex) {
                // Add monospace font to amount column for better number alignment
                $(row).find('td:eq(3)').css({
                    'font-family': 'monospace',
                    'font-size': '1.1em',
                    'white-space': 'nowrap'
                });
            },
            language: {
                search: "",
                searchPlaceholder: "Search expenses...",
                lengthMenu: "_MENU_ records per page",
                info: "Showing _START_ to _END_ of _TOTAL_ expenses",
                infoEmpty: "No expenses found",
                infoFiltered: "(filtered from _MAX_ total expenses)"
            }
        });

        // Load Dashboard Stats
        function loadDashboardStats() {
            $.get('ajax/get_expense_stats.php', { term_id: currentTermId }, function(stats) {
                $('#total-expenses').text(stats.total_expenses);
                $('#total-categories').text(stats.total_categories);
                $('#avg-expense').text(stats.average_expense);
                
                // Update the month expense card
                $('#month-expenses').text(stats.month_expenses);
                $('#current-month-label').text(stats.current_month + ' (' + stats.month_count + ' expenses)');
            });

            // Load category summary for selected term
            $.get('ajax/get_category_summary.php', { term_id: currentTermId }, function(response) {
                if (response.success) {
                    const summaryHtml = response.data.map(item => `
                        <tr>
                            <td>${item.category}</td>
                            <td class="text-end">UGX ${Math.round(parseFloat(item.total_amount)).toLocaleString('en-US', {
                                minimumFractionDigits: 0,
                                maximumFractionDigits: 0,
                                useGrouping: true
                            })}</td>
                        </tr>
                    `).join('');
                    $('#categorySummary').html(summaryHtml);
                }
            });
        }

        // Initialize
        loadDashboardStats();

        // Refresh stats and summary after actions
        function refreshData() {
            expensesTable.ajax.reload();
            loadDashboardStats();
        }

        // Add Expense Button Functionality
        const addExpenseBtn = $('#addExpenseBtn');
        const expenseFormModal = $('#expenseFormModal');
        const closeExpenseForm = $('#closeExpenseForm');

        addExpenseBtn.on('click', function() {
            expenseFormModal.fadeIn(300).css('display', 'flex');
            $(this).addClass('active');
        });

        function closeModal() {
            expenseFormModal.fadeOut(300);
            addExpenseBtn.removeClass('active');
            $('#expenseForm')[0].reset();
        }

        closeExpenseForm.on('click', closeModal);

        // Close modal when clicking outside
        expenseFormModal.on('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Handle form submission
        $('#expenseForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();
            
            $.ajax({
                url: 'ajax/add_expense.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        refreshData();
                        closeModal();
                        showSuccessMessage('Expense recorded successfully! Amount: ' + response.amount);
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    alert('Error submitting form. Check console for details.');
                }
            });
        });

        // Handle Edit Expense
        $('#expensesTable').on('click', '.edit-expense', function() {
            const expenseId = $(this).data('id');
            
            $.get('ajax/get_expense.php', { expense_id: expenseId }, function(expense) {
                $('#edit_expense_id').val(expense.expense_id);
                $('#edit_term_id').val(expense.term_id);
                $('#edit_category').val(expense.category);
                $('#edit_amount').val(expense.amount);
                $('#edit_expense_date').val(expense.expense_date);
                $('#edit_description').val(expense.description);
                $('#edit_payment_method').val(expense.payment_method);
                $('#edit_recipient_name').val(expense.recipient_name);
                
                $('#editExpenseModal').modal('show');
            });
        });

        // Handle Save Changes
        $('#saveExpenseChanges').on('click', function() {
            const submitBtn = $(this);
            submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Saving...');

            const formData = $('#editExpenseForm').serialize() + '&expense_id=' + $('#edit_expense_id').val();
            
            $.ajax({
                url: 'ajax/update_expense.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#editExpenseModal').modal('hide');
                        refreshData();
                        showSuccessMessage('Expense updated successfully! New amount: ' + response.amount);
                    } else {
                        alert('Error: ' + (response.message || 'Failed to update expense'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Update Error:', status, error);
                    console.log('Response Text:', xhr.responseText);
                    alert('Error updating expense. Check console for details.');
                },
                complete: function() {
                    submitBtn.prop('disabled', false).html('Save Changes');
                }
            });
        });

        // Handle Delete Expense
        $('#expensesTable').on('click', '.delete-expense', function() {
            if (confirm('Are you sure you want to delete this expense?')) {
                const expenseId = $(this).data('id');
                
                $.post('ajax/delete_expense.php', { expense_id: expenseId }, function(response) {
                    if (response.success) {
                        refreshData();
                        showSuccessMessage('Expense deleted successfully!');
                    } else {
                        alert('Error: ' + response.message);
                    }
                });
            }
        });

        // Handle PDF Download
        $('#downloadPDF').on('click', function() {
            const termId = $('#termSelect').val();
            if (!termId) {
                alert('Please select a term first');
                return;
            }
            window.location.href = `download_expenses.php?term_id=${termId}`;
        });

        // Handle CSV Export
        $('#exportCSV').on('click', function() {
            const termId = $('#termSelect').val();
            if (!termId) {
                alert('Please select a term first');
                return;
            }
            window.location.href = `export_expenses.php?term_id=${termId}`;
        });

        // Handle Sidebar Toggle
        $('#sidebarToggle').on('click', function(e) {
            e.preventDefault();
            $('body').toggleClass('sb-sidenav-toggled');
        });

        // Initialize Monthly Expenses Table
        function initMonthlyExpensesTable() {
            if (monthlyExpensesTable) {
                monthlyExpensesTable.destroy();
            }
            
            monthlyExpensesTable = $('#monthlyExpensesTable').DataTable({
                order: [[0, 'desc']],
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                columns: [
                    { data: 'expense_date' },
                    { data: 'category' },
                    { data: 'description' },
                    { 
                        data: 'amount',
                        className: 'text-end fw-bold',
                        render: function(data) {
                            const amount = Math.round(parseFloat(data));
                            return `UGX ${amount.toLocaleString('en-US')}`;
                        }
                    },
                    { 
                        data: 'payment_method',
                        render: function(data) {
                            return `<span class="badge bg-light text-dark">
                                ${data.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                            </span>`;
                        }
                    },
                    { data: 'recipient_name' }
                ],
                language: {
                    emptyTable: "No expenses found for the selected month"
                }
            });
        }
        
        // Handle View Monthly Expenses
        $('#viewMonthlyExpenses').on('click', function(e) {
            e.preventDefault();
            
            // Set default value to current month
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            $('#monthYearSelect').val(`${year}-${month}`);
            
            // Initialize the table if not already initialized
            if (!monthlyExpensesTable) {
                initMonthlyExpensesTable();
            }
            
            // Load data for current month
            loadMonthlyExpenses(`${year}-${month}`);
            
            // Show modal
            $('#monthlyExpensesModal').modal('show');
        });
        
        // Handle month selection change
        $('#monthYearSelect').on('change', function() {
            const selectedMonth = $(this).val();
            loadMonthlyExpenses(selectedMonth);
        });
        
        // Load expenses for selected month
        function loadMonthlyExpenses(monthYear) {
            if (!monthYear) return;
            
            $.ajax({
                url: 'ajax/get_expenses.php',
                data: {
                    term_id: currentTermId,
                    month_year: monthYear
                },
                success: function(response) {
                    monthlyExpensesTable.clear();
                    
                    if (response.data && response.data.length > 0) {
                        monthlyExpensesTable.rows.add(response.data).draw();
                        
                        // Calculate total for the month
                        const total = response.data.reduce(
                            (sum, expense) => sum + parseFloat(expense.amount), 0
                        );
                        
                        $('#selectedMonthTotal').text(
                            `UGX ${Math.round(total).toLocaleString('en-US')}`
                        );
                    } else {
                        $('#selectedMonthTotal').text('UGX 0');
                        monthlyExpensesTable.draw();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error loading monthly expenses:', error);
                    alert('Failed to load monthly expenses. Please try again.');
                }
            });
        }

        // Handle download of monthly expenses
        $('#downloadMonthlyExpensesPDF').on('click', function() {
            const selectedMonth = $('#monthYearSelect').val();
            if (!selectedMonth) {
                alert('Please select a month first');
                return;
            }
            window.location.href = `download_expenses.php?term_id=${currentTermId}&month_year=${selectedMonth}`;
        });
    });
    </script>
</body>
</html>