 <?php
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
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

// Fetch all schools
$query = "SELECT * FROM schools ORDER BY school_name ASC";
$result = $conn->query($query);

// Include the update form handling code
if (isset($_GET['edit_school'])) {
    $school_id = $_GET['edit_school'];
    
    // Fetch school details
    $stmt = $conn->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result_edit = $stmt->get_result();
    $school = $result_edit->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            body {
                font-size: 13px;
            }
        }

        .navbar {
            background-color: #007bff;
            padding: 0.5rem 1rem;
        }

        .navbar-brand, .nav-link {
            color: white !important;
        }

        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
        }

        .stats-card {
            transition: transform 0.3s;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .school-badge {
            max-width: 40px;
            height: auto;
        }

        .modal-header {
            background-color: #007bff;
            color: white;
        }

        .btn-action {
            margin: 2px;
            padding: 0.25rem 0.5rem;
        }

        /* Mobile-specific styles */
        @media (max-width: 768px) {
            .container {
                padding: 0.5rem;
            }

            .card-body {
                padding: 0.75rem;
            }

            .table {
                font-size: 0.85rem;
            }

            .btn-action {
                padding: 0.2rem 0.4rem;
                font-size: 0.8rem;
            }

            .school-badge {
                max-width: 30px;
            }

            /* Stack buttons vertically on mobile */
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.25rem;
            }

            /* Adjust stats cards for mobile */
            .stats-card .card-body {
                text-align: center;
                padding: 1rem 0.5rem;
            }

            .stats-card h2 {
                font-size: 1.5rem;
            }

            .stats-card h5 {
                font-size: 1rem;
            }
        }

        /* DataTables responsive styling */
        .dtr-details {
            width: 100%;
        }

        .dtr-title {
            font-weight: bold;
            min-width: 100px;
        }

        /* Improved table responsiveness */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Custom scrollbar for better mobile experience */
        .table-responsive::-webkit-scrollbar {
            height: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
    </style>
</head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-HJL2CJ5RYR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-HJL2CJ5RYR');
</script>
<body>
     <!-- Navbar with improved mobile menu -->
     <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">
               
                <span class="d-none d-sm-inline">SMS</span> Super Admin Dashboard
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="add_school.php">
                            <i class="fas fa-plus me-1"></i>
                            <span class="d-none d-sm-inline">Add New School</span>
                            <span class="d-inline d-sm-none">Add School</span>
                        </a>
                    </li>
                     <li class="nav-item">
                        <a class="nav-link" href="school_admin.php">
                              <i class="fas fa-plus me-1"></i>
                            <span>Add School Admin</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-sign-out-alt me-1"></i>
                            <span>Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
       <div class="container">
        <!-- Stats Cards -->
        <div class="row g-2 mb-4">
            <div class="col-12 col-md-4">
                <div class="card stats-card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-school me-2"></i>Total Schools</h5>
                        <h2><?php echo $result->num_rows; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-check-circle me-2"></i>Active Schools</h5>
                        <h2><?php 
                            $active_query = "SELECT COUNT(*) as count FROM schools WHERE status = 'active'";
                            $active_result = $conn->query($active_query);
                            $active_count = $active_result->fetch_assoc()['count'];
                            echo $active_count;
                        ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-pause-circle me-2"></i>Inactive Schools</h5>
                        <h2><?php 
                            $inactive_query = "SELECT COUNT(*) as count FROM schools WHERE status = 'inactive'";
                            $inactive_result = $conn->query($inactive_query);
                            $inactive_count = $inactive_result->fetch_assoc()['count'];
                            echo $inactive_count;
                        ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schools List -->
        <div class="card">
            <div class="card-header bg-white">
                <h3 class="card-title mb-0"><i class="fas fa-list me-2"></i>Registered Schools</h3>
            </div>
            <div class="card-body p-0 p-sm-3">
                <?php if (isset($_SESSION['notification'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['notification']; unset($_SESSION['notification']); ?></div>
                <?php endif; ?>
                
                <div class="table-responsive">
                    <table id="schoolsTable" class="table table-striped w-100">
                        <thead>
                            <tr>
                                <th>Badge</th>
                                <th>School Name</th>
                                <th>Reg. Number</th>
                                <th>Location</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?php if ($row['badge']): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($row['badge']); ?>" 
                                             class="school-badge" alt="School Badge">
                                    <?php else: ?>
                                        <i class="fas fa-school text-secondary"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['school_name']); ?></strong>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars($row['motto']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($row['registration_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['location']); ?></td>
                                <td>
                                    <small>
                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($row['email']); ?><br>
                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($row['phone']); ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $row['status'] == 'active' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit_school=<?php echo $row['id']; ?>" 
                                           class="btn btn-primary btn-sm btn-action">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="view_school.php?id=<?php echo $row['id']; ?>" 
                                           class="btn btn-info btn-sm btn-action">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-danger btn-sm btn-action" 
                                                onclick="confirmDelete(<?php echo $row['id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <!-- Update School Modal -->
    <?php if (isset($school)): ?>
    <div class="modal fade" id="updateSchoolModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update School Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="update_school.php" enctype="multipart/form-data">
                        <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                        
                        <div class="mb-3">
                            <label for="school_name" class="form-label">School Name</label>
                            <input type="text" class="form-control" id="school_name" name="school_name" 
                                value="<?php echo htmlspecialchars($school['school_name']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="motto" class="form-label">School Motto</label>
                            <input type="text" class="form-control" id="motto" name="motto" 
                                value="<?php echo htmlspecialchars($school['motto']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">School Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                value="<?php echo htmlspecialchars($school['email']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label">School Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                value="<?php echo htmlspecialchars($school['phone']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="badge" class="form-label">School Badge (Image)</label>
                            <?php if ($school['badge']): ?>
                                <div class="mb-2">
                                    <img src="uploads/<?php echo htmlspecialchars($school['badge']); ?>" 
                                        class="img-thumbnail" style="max-width: 150px;">
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="badge" name="badge" accept="image/*">
                            <small class="text-muted">Leave empty to keep current badge</small>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Update School Details</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this school? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDelete" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/responsive.bootstrap5.min.js"></script>
    
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable with responsive features
            $('#schoolsTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[1, "asc"]],
                columnDefs: [
                    { responsivePriority: 1, targets: [1, -1] }, // School name and actions always visible
                    { responsivePriority: 2, targets: [5] },     // Status column next priority
                    { responsivePriority: 3, targets: [0] },     // Badge next
                    { responsivePriority: 10000, targets: '_all' } // All other columns lowest priority
                ],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search schools..."
                }
            });

            // Show update modal if it exists
            <?php if (isset($school)): ?>
            var updateModal = new bootstrap.Modal(document.getElementById('updateSchoolModal'));
            updateModal.show();
            <?php endif; ?>
        });

        // Delete confirmation function remains the same
        function confirmDelete(schoolId) {
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            document.getElementById('confirmDelete').href = 'delete_school.php?id=' + schoolId;
            modal.show();
        }
    </script>
</body>
</html>