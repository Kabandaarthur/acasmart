 <?php
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$school_id = $_SESSION['school_id'];
$archive_dir = 'archives';
$message = '';

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get all terms for the school
$terms_query = "SELECT DISTINCT name, year FROM terms WHERE school_id = ? ORDER BY year DESC, name";
$stmt = $conn->prepare($terms_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$terms_result = $stmt->get_result();
$terms = [];
while ($row = $terms_result->fetch_assoc()) {
    $terms[] = $row;
}

// Get selected term filters
$selected_term = isset($_GET['term']) ? $_GET['term'] : '';
$selected_year = isset($_GET['year']) ? $_GET['year'] : '';

// Function to extract term and year from filename
function extractTermInfo($filename) {
    $parts = explode('_', $filename);
    $year = '';
    $term = '';
    foreach ($parts as $i => $part) {
        if ($part == 'Term' && isset($parts[$i-1])) {
            $term = $parts[$i-1] . ' Term';
        }
        if (is_numeric($part) && strlen($part) == 4) {
            $year = $part;
        }
    }
    return ['term' => $term, 'year' => $year];
}

// Get selected filters
$selected_class = isset($_GET['class']) ? $_GET['class'] : 'all';
$selected_subject = isset($_GET['subject']) ? $_GET['subject'] : 'all';
$selected_exam_type = isset($_GET['exam_type']) ? $_GET['exam_type'] : 'all';
$selected_category = isset($_GET['category']) ? $_GET['category'] : 'all';

// Handle file download with filtering
if (isset($_GET['download'])) {
    $filename = $_GET['download'];
    $class = isset($_GET['class']) ? $_GET['class'] : 'all';
    $subject = isset($_GET['subject']) ? $_GET['subject'] : 'all';
    $exam_type = isset($_GET['exam_type']) ? $_GET['exam_type'] : 'all';
    $category = isset($_GET['category']) ? $_GET['category'] : 'all';
    $filepath = $archive_dir . '/' . $filename;
    
    if (file_exists($filepath) && strpos($filename, "results_{$school_id}_") === 0) {
        $all_data = parseArchiveData($filepath);
        
        // Apply filters
        $filtered_data = array_filter($all_data, function($row) use ($class, $subject, $exam_type, $category) {
            $class_match = $class === 'all' || $row['Class'] === $class;
            $subject_match = $subject === 'all' || $row['Subject'] === $subject;
            $exam_type_match = $exam_type === 'all' || $row['Exam Type'] === $exam_type;
            $category_match = $category === 'all' || $row['Category'] === $category;
            return $class_match && $subject_match && $exam_type_match && $category_match;
        });
        
        if (!empty($filtered_data)) {
            $temp_file = tempnam(sys_get_temp_dir(), 'archive_');
            $handle = fopen($temp_file, 'w');
            
            // Extract term info from filename
            $term_info = extractTermInfo($filename);
            
            // Write filter information at the top of the CSV
            fputcsv($handle, ['Report Details']);
            fputcsv($handle, ['Academic Year:', $term_info['year']]);
            fputcsv($handle, ['Term:', $term_info['term']]);
            fputcsv($handle, ['Generated On:', date('Y-m-d H:i:s')]);
            fputcsv($handle, ['']);  // Empty line for spacing
            
            // Write filter criteria
            fputcsv($handle, ['Applied Filters']);
            fputcsv($handle, ['Class:', $class === 'all' ? 'All Classes' : $class]);
            fputcsv($handle, ['Subject:', $subject === 'all' ? 'All Subjects' : $subject]);
            fputcsv($handle, ['Exam Type:', $exam_type === 'all' ? 'All Exam Types' : $exam_type]);
            fputcsv($handle, ['Category:', $category === 'all' ? 'All Categories' : $category]);
            fputcsv($handle, ['']);  // Empty line for spacing
            fputcsv($handle, ['']);  // Empty line for spacing
            
            // Write summary statistics
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Total Records:', count($filtered_data)]);
            if ($class === 'all') {
                $unique_classes = array_unique(array_column($filtered_data, 'Class'));
                fputcsv($handle, ['Classes Included:', implode(', ', $unique_classes)]);
            }
            if ($subject === 'all') {
                $unique_subjects = array_unique(array_column($filtered_data, 'Subject'));
                fputcsv($handle, ['Subjects Included:', implode(', ', $unique_subjects)]);
            }
            fputcsv($handle, ['']);  // Empty line for spacing
            fputcsv($handle, ['']);  // Empty line for spacing
            
            // Write data header
            fputcsv($handle, ['Results Data']);
            
            // Get headers and filter them based on selection
            $headers = array_keys(reset($filtered_data));
            $required_columns = ['Student Name', 'Admission Number'];
            if ($class === 'all') $required_columns[] = 'Class';
            if ($subject === 'all') $required_columns[] = 'Subject';
            if ($exam_type === 'all') $required_columns[] = 'Exam Type';
            if ($category === 'all') $required_columns[] = 'Category';
            $required_columns = array_merge($required_columns, ['Score', 'Max Score', 'Percentage', 'Grade']);
            
            // Filter headers to only include required columns
            $filtered_headers = array_filter($headers, function($header) use ($required_columns) {
                return in_array($header, $required_columns);
            });
            
            fputcsv($handle, $filtered_headers);
            
            // Write data with only the required columns
            foreach ($filtered_data as $row) {
                $filtered_row = array_intersect_key($row, array_flip($filtered_headers));
                fputcsv($handle, $filtered_row);
            }
            
            fclose($handle);
            
            // Build the new filename with filters
            $new_filename_parts = [];
            $new_filename_parts[] = 'results';
            if ($term_info['year']) $new_filename_parts[] = $term_info['year'];
            if ($term_info['term']) $new_filename_parts[] = str_replace(' ', '_', $term_info['term']);
            if ($class !== 'all') $new_filename_parts[] = str_replace(' ', '_', $class);
            if ($subject !== 'all') $new_filename_parts[] = str_replace(' ', '_', $subject);
            if ($exam_type !== 'all') $new_filename_parts[] = str_replace(' ', '_', $exam_type);
            if ($category !== 'all') $new_filename_parts[] = str_replace(' ', '_', $category);
            
            $download_filename = implode('_', $new_filename_parts) . '.csv';
            
            header('Content-Type: application/csv');
            header('Content-Disposition: attachment; filename="' . $download_filename . '"');
            readfile($temp_file);
            unlink($temp_file);
            exit();
        }
    }
}

// Get list of archive files for this school with term filtering
$archive_files = [];
$unique_years = [];
if (is_dir($archive_dir)) {
    $files = scandir($archive_dir);
    foreach ($files as $file) {
        if (strpos($file, "results_{$school_id}_") === 0) {
            $term_info = extractTermInfo($file);
            
            // Apply term and year filters
            if ((!$selected_term || strpos($file, $selected_term) !== false) &&
                (!$selected_year || strpos($file, $selected_year) !== false)) {
                $archive_files[] = [
                    'filename' => $file,
                    'term' => $term_info['term'],
                    'year' => $term_info['year']
                ];
            }
            
            if ($term_info['year']) {
                $unique_years[$term_info['year']] = true;
            }
        }
    }
    
    // Sort archives by year and term
    usort($archive_files, function($a, $b) {
        if ($a['year'] != $b['year']) {
            return $b['year'] <=> $a['year'];
        }
        return strcmp($b['term'], $a['term']);
    });
}
$unique_years = array_keys($unique_years);
rsort($unique_years);

// Get all classes for the school
$classes_query = "SELECT id, name FROM classes WHERE school_id = ? ORDER BY id";
$stmt = $conn->prepare($classes_query);
$stmt->bind_param("i", $school_id);
$stmt->execute();
$classes_result = $stmt->get_result();
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[$row['id']] = $row['name'];
}

// Handle file preview with class filtering
$selected_file = isset($_GET['preview']) ? $_GET['preview'] : null;

function parseArchiveData($filepath) {
    $data = [];
    if (($handle = fopen($filepath, "r")) !== FALSE) {
        $headers = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            $data[] = array_combine($headers, $row);
        }
        fclose($handle);
    }
    return $data;
}

// Get preview data if file is selected
$preview_data = [];
$class_data = [];
$subjects = [];
$exam_types = [];
$categories = [];
$exam_categories = []; // New array to store exam type -> categories mapping
$available_classes = []; // New array to store all available classes

if ($selected_file) {
    $filepath = $archive_dir . '/' . $selected_file;
    if (file_exists($filepath)) {
        $all_data = parseArchiveData($filepath);
        
        // Collect unique subjects, exam types, categories, and classes
        foreach ($all_data as $row) {
            // Store unique subjects (using subject name as key to prevent duplicates)
            $subjects[$row['Subject']] = $row['Subject'];
            
            // Store exam types
            $exam_types[$row['Exam Type']] = $row['Exam Type'];
            
            // Store categories by exam type
            if (!isset($exam_categories[$row['Exam Type']])) {
                $exam_categories[$row['Exam Type']] = [];
            }
            $exam_categories[$row['Exam Type']][$row['Category']] = $row['Category'];
            
            // Store unique classes
            $available_classes[$row['Class']] = $row['Class'];
        }
        
        // Convert to arrays and sort
        $subjects = array_values($subjects);
        $exam_types = array_values($exam_types);
        $available_classes = array_values($available_classes);
        sort($subjects);
        sort($exam_types);
        sort($available_classes);
        
        // Sort categories for each exam type
        foreach ($exam_categories as &$cats) {
            $cats = array_values($cats);
            sort($cats);
        }
        
        // Get available categories based on selected exam type
        if ($selected_exam_type !== 'all' && isset($exam_categories[$selected_exam_type])) {
            $categories = $exam_categories[$selected_exam_type];
        } else {
            // If no exam type is selected or invalid exam type, collect all unique categories
            $all_categories = [];
            foreach ($exam_categories as $exam_cats) {
                $all_categories = array_merge($all_categories, $exam_cats);
            }
            $categories = array_unique($all_categories);
            sort($categories);
        }
        
        // Filter and organize data
        foreach ($all_data as $row) {
            $class_name = $row['Class'];
            $subject_name = $row['Subject'];
            $exam_type_name = $row['Exam Type'];
            $category_name = $row['Category'];
            
            $show_row = true;
            if ($selected_class !== 'all' && $selected_class !== $class_name) $show_row = false;
            if ($selected_subject !== 'all' && $selected_subject !== $subject_name) $show_row = false;
            if ($selected_exam_type !== 'all' && $selected_exam_type !== $exam_type_name) $show_row = false;
            if ($selected_category !== 'all' && $selected_category !== $category_name) $show_row = false;
            
            if ($show_row) {
                $class_data[$class_name][] = $row;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Archived Results</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .gradient-custom {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        }
        .archive-tabs > div {
            cursor: pointer;
            padding: 10px 20px;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .archive-tabs > div:hover {
            background-color: #f3f4f6;
            border-bottom-color: #93c5fd;
        }
        .archive-tabs > div.active {
            border-bottom-color: #3b82f6;
            color: #3b82f6;
            background-color: #eff6ff;
        }
        .preview-table {
            font-size: 0.9rem;
        }
        .archive-card {
            transition: all 0.3s ease;
        }
        .archive-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .stats-card {
            background: linear-gradient(135deg, #ffffff 0%, #f3f4f6 100%);
        }
        .custom-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            width: 100%;
            font-size: 0.875rem;
            line-height: 1.25rem;
            color: #374151;
            background-color: #ffffff;
            cursor: pointer;
        }
        .custom-select:focus {
            outline: 2px solid transparent;
            outline-offset: 2px;
            border-color: #3b82f6;
            ring: 1px solid #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .filter-group {
            position: relative;
            margin-bottom: 1rem;
        }
        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
        }
        .filter-group i {
            position: absolute;
            left: 1rem;
            top: 2.5rem;
            color: #6b7280;
            pointer-events: none;
        }
        .filter-group select {
            padding-left: 2.5rem;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="gradient-custom text-white shadow-lg">
            <div class="container mx-auto px-4 py-6">
                <div class="flex justify-between items-center">
                    <h1 class="text-3xl font-bold">
                        <i class="fas fa-archive mr-2"></i>Archived Results
                    </h1>
                    <a href="school_admin_dashboard.php" class="bg-white text-blue-800 px-4 py-2 rounded-lg hover:bg-blue-50 transition duration-150">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </header>

        <div class="container mx-auto px-4 py-8">
            <!-- Term Filter Section -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-6">Filter Archives</h2>
                <form class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Academic Year Filter -->
                        <div class="filter-group">
                            <label for="year">
                                <i class="fas fa-calendar-alt mr-2 text-blue-500"></i>Academic Year
                            </label>
                            <select name="year" id="year" class="custom-select">
                                <option value="">All Years</option>
                                <?php foreach ($unique_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Term Filter -->
                        <div class="filter-group">
                            <label for="term">
                                <i class="fas fa-clock mr-2 text-blue-500"></i>Term
                            </label>
                            <select name="term" id="term" class="custom-select">
                                <option value="">All Terms</option>
                                <option value="First" <?php echo $selected_term == 'First' ? 'selected' : ''; ?>>First Term</option>
                                <option value="Second" <?php echo $selected_term == 'Second' ? 'selected' : ''; ?>>Second Term</option>
                                <option value="Third" <?php echo $selected_term == 'Third' ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center justify-end space-x-4 pt-6 border-t mt-6">
                        <button type="submit" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                            <i class="fas fa-filter mr-2"></i>Apply Filters
                        </button>
                        <?php if ($selected_term || $selected_year || $selected_subject !== 'all' || $selected_category !== 'all'): ?>
                            <a href="?" class="inline-flex items-center px-6 py-3 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors">
                                <i class="fas fa-times mr-2"></i>Clear All
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Archive Files Grid -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">
                    Available Archives
                    <?php if ($selected_term || $selected_year): ?>
                        <span class="text-sm font-normal text-gray-500 ml-2">
                            (Filtered: <?php 
                                $filters = [];
                                if ($selected_year) $filters[] = $selected_year;
                                if ($selected_term) $filters[] = $selected_term . ' Term';
                                echo implode(' - ', $filters);
                            ?>)
                        </span>
                    <?php endif; ?>
                </h2>

                <?php if (empty($archive_files)): ?>
                    <div class="text-center text-gray-500 py-8">
                        <i class="fas fa-info-circle text-4xl mb-4"></i>
                        <p>No archived results found<?php echo ($selected_term || $selected_year) ? ' for the selected filters' : ''; ?>.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($archive_files as $archive): ?>
                            <div class="archive-card bg-white border rounded-lg overflow-hidden">
                                <div class="p-4 border-b bg-gray-50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <i class="fas fa-file-csv text-blue-500 text-xl mr-3"></i>
                                            <div>
                                                <span class="font-medium text-gray-700"><?php echo htmlspecialchars($archive['term']); ?></span>
                                                <span class="text-sm text-gray-500 block"><?php echo htmlspecialchars($archive['year']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="p-4 flex justify-end space-x-3">
                                    <a href="?preview=<?php echo urlencode($archive['filename']); ?>" 
                                       class="inline-flex items-center px-3 py-2 border border-blue-500 text-blue-500 rounded-md hover:bg-blue-50 transition-colors">
                                        <i class="fas fa-eye mr-2"></i> View
                                    </a>
                                    <a href="?download=<?php echo urlencode($archive['filename']); ?>" 
                                       class="inline-flex items-center px-3 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition-colors">
                                        <i class="fas fa-download mr-2"></i> Download All
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($selected_file && !empty($class_data)): ?>
                <!-- Results Section -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <div class="mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 mb-6">
                            Results from: <?php echo htmlspecialchars($selected_file); ?>
                        </h2>
                        
                        <!-- Filter Controls -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                            <!-- Subject Filter -->
                            <div class="filter-group">
                                <label for="result_subject">
                                    <i class="fas fa-book mr-2 text-blue-500"></i>Filter by Subject
                                </label>
                                <select id="result_subject" 
                                        onchange="window.location.href='?preview=<?php echo urlencode($selected_file); ?>&class=<?php echo urlencode($selected_class); ?>&subject=' + this.value + '&exam_type=<?php echo urlencode($selected_exam_type); ?>&category=<?php echo urlencode($selected_category); ?>'"
                                        class="custom-select">
                                    <option value="all">All Subjects</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo htmlspecialchars($subject); ?>" 
                                                <?php echo $selected_subject === $subject ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Exam Type Filter -->
                            <div class="filter-group">
                                <label for="result_exam_type">
                                    <i class="fas fa-file-alt mr-2 text-blue-500"></i>Filter by Exam Type
                                </label>
                                <select id="result_exam_type" 
                                        onchange="window.location.href='?preview=<?php echo urlencode($selected_file); ?>&class=<?php echo urlencode($selected_class); ?>&subject=<?php echo urlencode($selected_subject); ?>&exam_type=' + this.value + '&category=all'"
                                        class="custom-select">
                                    <option value="all">All Exam Types</option>
                                    <?php foreach ($exam_types as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>" 
                                                <?php echo $selected_exam_type === $type ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Category Filter -->
                            <div class="filter-group">
                                <label for="result_category">
                                    <i class="fas fa-layer-group mr-2 text-blue-500"></i>Filter by Category
                                </label>
                                <select id="result_category" 
                                        onchange="window.location.href='?preview=<?php echo urlencode($selected_file); ?>&class=<?php echo urlencode($selected_class); ?>&subject=<?php echo urlencode($selected_subject); ?>&exam_type=<?php echo urlencode($selected_exam_type); ?>&category=' + this.value"
                                        class="custom-select">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>" 
                                                <?php echo $selected_category === $category ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Class Filter Tabs -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-3">
                                <i class="fas fa-users mr-2 text-blue-500"></i>Filter by Class
                            </label>
                            <div class="archive-tabs flex flex-wrap gap-2 border-b pb-2">
                                <div class="<?php echo $selected_class === 'all' ? 'active' : ''; ?> rounded-t-lg"
                                     onclick="window.location.href='?preview=<?php echo urlencode($selected_file); ?>&class=all&subject=<?php echo urlencode($selected_subject); ?>&exam_type=<?php echo urlencode($selected_exam_type); ?>&category=<?php echo urlencode($selected_category); ?>'">
                                    All Classes
                                </div>
                                <?php foreach ($available_classes as $class_name): ?>
                                    <div class="<?php echo $selected_class === $class_name ? 'active' : ''; ?> rounded-t-lg"
                                         onclick="window.location.href='?preview=<?php echo urlencode($selected_file); ?>&class=<?php echo urlencode($class_name); ?>&subject=<?php echo urlencode($selected_subject); ?>&exam_type=<?php echo urlencode($selected_exam_type); ?>&category=<?php echo urlencode($selected_category); ?>'">
                                        <?php echo htmlspecialchars($class_name); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Download Options -->
                        <div class="flex items-center justify-end space-x-4 pt-4 border-t mb-6">
                            <a href="?download=<?php echo urlencode($selected_file); ?>&class=<?php echo urlencode($selected_class); ?>&subject=<?php echo urlencode($selected_subject); ?>&exam_type=<?php echo urlencode($selected_exam_type); ?>&category=<?php echo urlencode($selected_category); ?>" 
                               class="inline-flex items-center px-6 py-2.5 bg-green-500 text-white font-medium rounded-lg hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors">
                                <i class="fas fa-download mr-2"></i> Download Filtered Results
                            </a>
                            
                            <?php if ($selected_subject !== 'all' || $selected_exam_type !== 'all' || $selected_class !== 'all' || $selected_category !== 'all'): ?>
                                <a href="?preview=<?php echo urlencode($selected_file); ?>" 
                                   class="inline-flex items-center px-6 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors">
                                    <i class="fas fa-times mr-2"></i> Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>

                        <!-- Results Tables by Class -->
                        <?php foreach ($class_data as $class_name => $students): ?>
                            <div class="mb-8 bg-white rounded-lg border">
                                <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
                                    <h3 class="text-lg font-semibold text-gray-800">
                                        <i class="fas fa-users mr-2 text-blue-500"></i>
                                        <?php echo htmlspecialchars($class_name); ?>
                                        <span class="text-sm text-gray-500 ml-2">(<?php echo count($students); ?> records)</span>
                                    </h3>
                                    <a href="?download=<?php echo urlencode($selected_file); ?>&class=<?php echo urlencode($class_name); ?>" 
                                       class="inline-flex items-center px-3 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 transition-colors">
                                        <i class="fas fa-download mr-2"></i> Download Class Results
                                    </a>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="preview-table w-full">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <?php foreach (array_keys($students[0]) as $header): ?>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border-b">
                                                        <?php echo htmlspecialchars($header); ?>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($students as $row): ?>
                                                <tr class="hover:bg-gray-50 transition-colors">
                                                    <?php foreach ($row as $value): ?>
                                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                                            <?php echo htmlspecialchars($value); ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function updateCategoryOptions() {
        const examType = document.getElementById('result_exam_type').value;
        const categorySelect = document.getElementById('result_category');
        const examCategories = <?php echo json_encode($exam_categories); ?>;
        
        // Clear existing options
        categorySelect.innerHTML = '<option value="all">All Categories</option>';
        
        // If an exam type is selected, populate with its categories
        if (examType !== 'all' && examCategories[examType]) {
            examCategories[examType].forEach(category => {
                const option = new Option(category, category);
                option.selected = category === '<?php echo $selected_category; ?>';
                categorySelect.add(option);
            });
        } else {
            // If no exam type selected, show all unique categories
            const allCategories = [...new Set(Object.values(examCategories).flat())].sort();
            allCategories.forEach(category => {
                const option = new Option(category, category);
                option.selected = category === '<?php echo $selected_category; ?>';
                categorySelect.add(option);
            });
        }
    }
    </script>
</body>
</html>