 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$school_id = $_SESSION['school_id'];

// Fetch all classes for the school
$classes_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY id ASC";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();

// First, get the active term
$active_term_query = "SELECT id, name FROM terms WHERE school_id = ? AND is_current = 1 LIMIT 1";
$stmt = $conn->prepare($active_term_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$active_term_result = $stmt->get_result();
$active_term = $active_term_result->fetch_assoc();

if (!$active_term) {
    die("No active term found. Please set an active term first.");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reports - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', Arial, sans-serif;
            line-height: 1.6;
            background-color: #f8f9fa;
            color: #333;
        }
        .category-container {
            margin-top: 20px;
            display: none;
        }
        .category-button {
            padding: 8px 12px;
            margin: 5px;
            background-color: #6c757d;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .category-button.selected {
            background-color: #28a745;
        }

        .container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 30px;
            margin-top: 30px;
            margin-bottom: 30px;
        }
        h1, h2, h3 {
            color: #007bff;
            margin-bottom: 20px;
        }
        .selection-container {
            background-color: #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .class-list, .exam-type-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        .class-button, .exam-type-button {
            padding: 10px 15px;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        .class-button:hover, .exam-type-button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .class-button.selected, .exam-type-button.selected {
            background-color: #28a745;
        }
        .exam-type-container {
            margin-top: 20px;
            display: none;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            max-width: 100%;
            margin-bottom: 1rem;
            background-color: transparent;
        }
        .table th,
        .table td {
            padding: 0.75rem;
            vertical-align: top;
            border-top: 1px solid #dee2e6;
        }
        .table thead th {
            vertical-align: bottom;
            border-bottom: 2px solid #dee2e6;
            background-color: #f8f9fa;
        }
        .table tbody + tbody {
            border-top: 2px solid #dee2e6;
        }
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.05);
        }
        .download-links {
            margin: 20px 0;
        }
        .download-link {
            margin-right: 15px;
            text-decoration: none;
            padding: 8px 15px;
            background-color: #28a745;
            color: white;
            border-radius: 5px;
            transition: all 0.3s ease;
            display: inline-block;
            margin-bottom: 10px;
        }
        .download-link:hover {
            background-color: #218838;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .individual-report-btn {
            margin-top: 10px;
            background-color: #17a2b8;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .individual-report-btn:hover {
            background-color: #138496;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        @media (max-width: 768px) {
            .class-list, .exam-type-list {
                flex-direction: column;
            }
            .class-button, .exam-type-button {
                width: 100%;
                margin-bottom: 10px;
            }
            .container {
                padding: 15px;
            }
        }
        .table-container {
            margin-top: 2rem;
            overflow-x: auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background-color: #fff;
        }
        .table th,
        .table td {
            padding: 1rem;
            text-align: left;
            vertical-align: middle;
            border: none;
        }
        .table thead th {
            background-color: #007bff;
            color: #fff;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .table thead th:first-child {
            border-top-left-radius: 8px;
        }
        .table thead th:last-child {
            border-top-right-radius: 8px;
        }
        .table tbody tr:nth-of-type(even) {
            background-color: #f8f9fa;
        }
        .table tbody tr:hover {
            background-color: #e9ecef;
        }
        .table tbody td {
            border-bottom: 1px solid #dee2e6;
        }
        .student-name {
            font-weight: 600;
            color: #495057;
        }
        .score-cell {
            text-align: center;
        }
        .total-cell, .average-cell {
            font-weight: 600;
            color: #28a745;
        }
        .alert-info {
            background-color: #e1f5fe;
            border-color: #b3e5fc;
            color: #0288d1;
            margin-bottom: 20px;
        }
        .btn-secondary {
            background-color: #6c757d;
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            transition: all 0.3s ease;
            text-decoration: none;
            border: none;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">View Reports</h1>
        <a href="school_admin_dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>
    </div>
    
    <div class="selection-container">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>Current Term: <?php echo htmlspecialchars($active_term['name']); ?>
        </div>
        
        <h2>Select Class</h2>
        <div class="class-list">
            <?php while ($class = $classes_result->fetch_assoc()): ?>
                <button class="class-button" onclick="selectClass(<?php echo $class['id']; ?>, '<?php echo htmlspecialchars($class['name']); ?>', this)">
                    <i class="fas fa-chalkboard-teacher me-2"></i><?php echo htmlspecialchars($class['name']); ?>
                </button>
            <?php endwhile; ?>
        </div>
        
        <div id="exam-type-container" class="exam-type-container">
            <h3>Select Exam Type</h3>
            <div class="exam-type-list" id="exam-type-list">
                <!-- Exam types will be populated dynamically -->
            </div>
        </div>
        
        <div id="category-container" class="category-container">
            <h3>Select Category</h3>
            <div class="category-list" id="category-list">
                <!-- Categories will be populated dynamically -->
            </div>
        </div>
    </div>
    
    <div id="report-container"></div>
</div>

<script>
let selectedClassId, selectedClassName, selectedExamType, selectedCategory;

function selectClass(classId, className, button) {
    selectedClassId = classId;
    selectedClassName = className;
    
    // Update UI
    document.querySelectorAll('.class-button').forEach(btn => btn.classList.remove('selected'));
    button.classList.add('selected');
    
    // Reset other selections
    selectedExamType = null;
    selectedCategory = null;
    document.getElementById('category-container').style.display = 'none';
    document.getElementById('report-container').innerHTML = '';
    
    // Fetch exam types for this class
    fetch(`get_exam_types.php?class_id=${classId}&term_id=<?php echo $active_term['id']; ?>`)
        .then(response => response.json())
        .then(data => {
            const examTypeList = document.getElementById('exam-type-list');
            examTypeList.innerHTML = '';
            
            data.forEach(examType => {
                const button = document.createElement('button');
                button.className = 'exam-type-button';
                button.innerHTML = `<i class="fas fa-file-alt me-2"></i>${examType}`;
                button.onclick = () => selectExamType(examType, button);
                examTypeList.appendChild(button);
            });
            
            document.getElementById('exam-type-container').style.display = 'block';
        })
        .catch(error => {
            console.error('Error fetching exam types:', error);
        });
}

function selectExamType(examType, button) {
    selectedExamType = examType;
    
    // Update UI
    document.querySelectorAll('.exam-type-button').forEach(btn => btn.classList.remove('selected'));
    button.classList.add('selected');
    
    // Reset category selection
    selectedCategory = null;
    document.getElementById('report-container').innerHTML = '';
    
    // Fetch categories for this exam type
    fetch(`get_categories.php?class_id=${selectedClassId}&exam_type=${encodeURIComponent(examType)}&term_id=<?php echo $active_term['id']; ?>`)
        .then(response => response.json())
        .then(data => {
            const categoryList = document.getElementById('category-list');
            categoryList.innerHTML = '';
            
            data.forEach(category => {
                const button = document.createElement('button');
                button.className = 'category-button';
                button.innerHTML = `<i class="fas fa-tag me-2"></i>${category}`;
                button.onclick = () => selectCategory(category, button);
                categoryList.appendChild(button);
            });
            
            document.getElementById('category-container').style.display = 'block';
        })
        .catch(error => {
            console.error('Error fetching categories:', error);
        });
}

function selectCategory(category, button) {
    selectedCategory = category;
    
    // Update UI
    document.querySelectorAll('.category-button').forEach(btn => btn.classList.remove('selected'));
    button.classList.add('selected');
    
    // Fetch and display report
    viewClassReport();
}

function viewClassReport() {
    fetch(`get_class_report.php?class_id=${selectedClassId}&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>`)
        .then(response => response.json())
        .then(data => {
            const reportContainer = document.getElementById('report-container');
            let reportHtml = `
                <h2 class="mt-4">Class Report: ${selectedClassName}</h2>
                <h3>${selectedExamType} - ${selectedCategory}</h3>
                
                <div class="download-links">
                    <!--
                    <a href="download_report.php?class_id=${selectedClassId}&format=csv&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>" class="download-link">
                        <i class="fas fa-file-csv me-2"></i>Download CSV
                    </a>
                    -->
                    <a href="download_report.php?class_id=${selectedClassId}&format=pdf&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>" class="download-link">
                        <i class="fas fa-file-pdf me-2"></i>Download scores report
                    </a>
                    <!--
                    <a href="download_grades.php?class_id=${selectedClassId}&format=pdf&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>&report_type=grades" class="download-link">
                        <i class="fas fa-file-pdf me-2"></i>Download Grades PDF
                    </a>
                    -->
                    <a href="download_all_reports.php?class_id=${selectedClassId}&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>" class="download-link">
                        <i class="fas fa-download me-2"></i>Download All Report Cards template 1
                    </a>
                    <a href="download_all_reports_summary.php?class_id=${selectedClassId}&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>" class="download-link">
                        <i class="fas fa-download me-2"></i>Download All Report Cards template 2
                    </a>
                    <a href="download_all_reports_format3.php?class_id=${selectedClassId}" class="download-link">
                        <i class="fas fa-download me-2"></i>Download All Report Cards template 3
                    </a>
                    <!--
                    <a href="download_all_grades.php?class_id=${selectedClassId}&exam_type=${encodeURIComponent(selectedExamType)}&category=${encodeURIComponent(selectedCategory)}&term_id=<?php echo $active_term['id']; ?>" class="download-link">
                        <i class="fas fa-download me-2"></i>Download Report Grades
                    </a>
                    -->
                </div>
                
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                ${data.subjects.map(subject => `<th>${subject}</th>`).join('')}
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            // Loop through each student and their marks
            for (const [student, scores] of Object.entries(data.report)) {
                const studentName = student.replace(/,/g, ' ').trim();
                reportHtml += `
                    <tr>
                        <td class="student-name">${studentName}</td>
                        ${data.subjects.map(subject => {
                            const score = scores[subject] || '-';
                            if (score === '-') return '<td class="score-cell">-</td>';
                            
                            // Format score based on exam type
                            const formattedScore = selectedExamType.toLowerCase() === 'exam' 
                                ? Math.round(parseFloat(score))
                                : parseFloat(score).toFixed(1);
                            
                            return `<td class="score-cell">${formattedScore}</td>`;
                        }).join('')}
                        <td>
                            <button class="individual-report-btn" 
                                    onclick="downloadStudentReport('${encodeURIComponent(studentName)}', ${selectedClassId})"
                                    data-student-name="${studentName}"
                                    data-class-id="${selectedClassId}">
                                <i class="fas fa-download me-2"></i>Download Report Card
                            </button>
                        </td>
                    </tr>
                `;
            }
            
            reportHtml += `
                        </tbody>
                    </table>
                </div>
            `;
            
            reportContainer.innerHTML = reportHtml;
        })
        .catch(error => {
            console.error('Error fetching class report:', error);
            document.getElementById('report-container').innerHTML = '<p class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error loading report. Please try again.</p>';
        });
}

// Error message display function
function showErrorMessage(message, duration = 5000) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger position-fixed top-0 start-50 translate-middle-x mt-3';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.maxWidth = '80%';
    alertDiv.innerHTML = `
        <i class="fas fa-exclamation-triangle me-2"></i>
        <pre style="margin: 0; white-space: pre-wrap;">${message}</pre>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" 
                style="position: absolute; right: 10px; top: 10px;"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Add click handler to close button
    alertDiv.querySelector('.btn-close').addEventListener('click', () => {
        alertDiv.remove();
    });
    
    // Auto-remove after duration
    if (duration > 0) {
        setTimeout(() => {
            if (document.body.contains(alertDiv)) {
                alertDiv.remove();
            }
        }, duration);
    }
}

// Update the downloadStudentReport function
function downloadStudentReport(studentName, classId) {
    // Show loading indicator
    const button = event.target.closest('button');
    const originalContent = button.innerHTML;
    button.innerHTML = `
        <div class="d-flex align-items-center">
            <div class="spinner-border spinner-border-sm text-light me-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <span>Processing...</span>
        </div>`;
    button.disabled = true;

    // First get the student ID
    fetch(`get_student_id.php?student_name=${encodeURIComponent(studentName)}&class_id=${classId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            if (!data.student_id) {
                throw new Error('Student ID not found');
            }

            // Now create the download URL with the student ID
            const downloadUrl = `download_report_card.php?` + new URLSearchParams({
                student_id: data.student_id,
                class_id: classId,
                exam_type: selectedExamType,
                category: selectedCategory,
                term_id: <?php echo $active_term['id']; ?>
            }).toString();

            // Create a temporary link and click it
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorMessage(`Failed to download report card: ${error.message}`);
        })
        .finally(() => {
            // Reset button state
            button.innerHTML = originalContent;
            button.disabled = false;
        });
}
</script>
</body>
</html>