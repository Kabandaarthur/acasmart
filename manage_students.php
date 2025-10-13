 <?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Assuming the user's school_id is stored in the session
$user_school_id = $_SESSION['school_id'];

// Database connection
$db_host = 'localhost';     
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = '';

// Fetch current term
$current_term_query = $conn->prepare("SELECT id, name, year FROM terms WHERE school_id = ? AND is_current = 1");
$current_term_query->bind_param("i", $user_school_id);
$current_term_query->execute();
$current_term_result = $current_term_query->get_result();
$current_term = $current_term_result->fetch_assoc();
$current_term_query->close();

// Fetch students for a specific class and current term
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$students = null;

// Search functionality
$search_query = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Fetch all classes for the user's school
$classes = $conn->prepare("SELECT id, name FROM classes WHERE school_id = ? ORDER BY id ASC");
$classes->bind_param("i", $user_school_id);
$classes->execute();
$classes_result = $classes->get_result();

// Function to generate admission number
function generateAdmissionNumber($conn, $school_id) {
    // Get the latest admission number for this school
    $query = $conn->prepare("SELECT admission_number FROM students WHERE school_id = ? ORDER BY id DESC LIMIT 1");
    $query->bind_param("i", $school_id);
    $query->execute();
    $result = $query->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastNumber = $row['admission_number'];
        
        // Extract the numerical part
        $matches = [];
        if (preg_match('/ADM-(\d+)/', $lastNumber, $matches)) {
            $number = intval($matches[1]) + 1;
            return 'ADM-' . sprintf('%04d', $number); 
        }
    }
    
    // If no students exist or pattern doesn't match, start with 001
    return 'ADM-0001';
}

// Function to generate school abbreviation from school name
function generateSchoolAbbreviation($school_name) {
    $words = explode(' ', trim(strtolower($school_name)));
    $abbreviation = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            // Remove any special characters from the word
            $word = preg_replace('/[^a-zA-Z0-9]/', '', $word);
            if (!empty($word)) {
                $abbreviation .= $word[0];
            }
        }
    }
    return $abbreviation;
}

// Function to generate student email address
function generateStudentEmail($conn, $firstname, $lastname, $school_id) {
    // Guard against null values
    $firstname = (string)$firstname;
    $lastname = (string)$lastname;
    // Get school name
    $school_query = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_query->bind_param("i", $school_id);
    $school_query->execute();
    $school_result = $school_query->get_result();
    $school_data = $school_result->fetch_assoc();
    $school_query->close();
    
    if (!$school_data) {
        return null;
    }
    
    $school_name = $school_data['school_name'];
    $school_short = generateSchoolAbbreviation($school_name);
    
    // Clean and format names
    $firstname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstname));
    $lastname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastname));
    
    // Generate base email
    $base_email = $firstname . "." . $lastname . "@students." . $school_short . ".ac.ug";
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT id FROM students WHERE student_email = ?");
    $stmt->bind_param("s", $base_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If email exists, add a number
    if ($result->num_rows > 0) {
        $counter = 1;
        do {
            $new_email = $firstname . "." . $lastname . $counter . "@students." . $school_short . ".ac.ug";
            $stmt->bind_param("s", $new_email);
            $stmt->execute();
            $result = $stmt->get_result();
            $counter++;
        } while ($result->num_rows > 0);
        return $new_email;
    }
    
    return $base_email;
}

// Function to generate student username
function generateStudentUsername($conn, $firstname, $lastname, $school_id) {
    // Guard against null values
    $firstname = (string)$firstname;
    $lastname = (string)$lastname;
    // Get school name
    $school_query = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
    $school_query->bind_param("i", $school_id);
    $school_query->execute();
    $school_result = $school_query->get_result();
    $school_data = $school_result->fetch_assoc();
    $school_query->close();
    
    if (!$school_data) {
        return null;
    }
    
    $school_name = $school_data['school_name'];
    $school_short = generateSchoolAbbreviation($school_name);
    
    // Clean and format names
    $firstname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstname));
    $lastname = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $lastname));
    
    // Generate base username
    $base_username = $firstname . "." . $lastname . "@" . $school_short;
    
    // Check if username exists
    $stmt = $conn->prepare("SELECT id FROM students WHERE student_username = ?");
    $stmt->bind_param("s", $base_username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // If username exists, add a number
    if ($result->num_rows > 0) {
        $counter = 1;
        do {
            $new_username = $firstname . "." . $lastname . $counter . "@" . $school_short;
            $stmt->bind_param("s", $new_username);
            $stmt->execute();
            $result = $stmt->get_result();
            $counter++;
        } while ($result->num_rows > 0);
        return $new_username;
    }
    
    return $base_username;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_student'])) {
        // Generate admission number
        $admission_number = generateAdmissionNumber($conn, $user_school_id);
        
        // Convert all text inputs to uppercase
        $firstname = strtoupper($conn->real_escape_string($_POST['firstname']));
        $lastname = strtoupper($conn->real_escape_string($_POST['lastname']));
        $gender = strtoupper($conn->real_escape_string($_POST['gender']));
        $age = intval($_POST['age']);
        $stream = strtoupper($conn->real_escape_string($_POST['stream']));
        $class_id = intval($_POST['class_id']);
        $lin_number = strtoupper($conn->real_escape_string($_POST['lin_number']));
        $father_name = strtoupper($conn->real_escape_string($_POST['father_name'] ?? ''));
        $father_contact = strtoupper($conn->real_escape_string($_POST['father_contact'] ?? ''));
        $mother_name = strtoupper($conn->real_escape_string($_POST['mother_name'] ?? ''));
        $mother_contact = strtoupper($conn->real_escape_string($_POST['mother_contact'] ?? ''));
        $home_of_residence = strtoupper($conn->real_escape_string($_POST['home_of_residence'] ?? ''));
        $home_email = $conn->real_escape_string($_POST['home_email'] ?? '');

        // Generate student email and username
        $student_email = generateStudentEmail($conn, $firstname, $lastname, $user_school_id);
        $student_username = generateStudentUsername($conn, $firstname, $lastname, $user_school_id);
        
        // Check if email and username were generated successfully
        if (!$student_email || !$student_username) {
            $message = "Error: Could not generate student email or username. Please check if school information is properly configured.";
            goto skip_upload;
        }
        
        // Generate default password (admission number)
        $student_password = password_hash($admission_number, PASSWORD_DEFAULT);

        // Set default values for missing variables
        $last_promoted_term_id = null;
        $enrollment_date = date('Y-m-d');
        
        // Check if current term exists
        if (!$current_term) {
            $message = "Error: No active term found. Please set an active term before adding students.";
            goto skip_upload;
        }

        // Handle image upload
        $image_path = '';
if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
    $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
    $filename = $_FILES['image']['name'];
    $filetype = $_FILES['image']['type'];
    $filesize = $_FILES['image']['size'];

    // Verify file extension
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!array_key_exists($ext, $allowed)) {
        $message = "Error: Please select a valid file format (JPG, JPEG, GIF, PNG).";
        goto skip_upload;
    }

    // Create uploads directory if it doesn't exist
    $upload_dir = 'uploads/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Generate unique filename
    $new_filename = uniqid('student_') . '.' . $ext;
    $upload_path = $upload_dir . $new_filename;

    // Compress and resize image if it's a jpeg or png
    if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
        $max_dimension = 600; // Reduced maximum width or height
        $quality = 60; // Reduced JPEG quality for smaller file size

        // Get image info
        list($width, $height, $type) = getimagesize($_FILES['image']['tmp_name']);
        
        // Calculate new dimensions while maintaining aspect ratio
        if ($width > $height) {
            if ($width > $max_dimension) {
                $new_width = $max_dimension;
                $new_height = floor($height * ($max_dimension / $width));
            } else {
                $new_width = $width;
                $new_height = $height;
            }
        } else {
            if ($height > $max_dimension) {
                $new_height = $max_dimension;
                $new_width = floor($width * ($max_dimension / $height));
            } else {
                $new_width = $width;
                $new_height = $height;
            }
        }

        // Create new image with correct orientation
        $temp_image = imagecreatetruecolor($new_width, $new_height);
        
        // Handle transparency for PNG
        if ($ext == 'png') {
            imagealphablending($temp_image, false);
            imagesavealpha($temp_image, true);
            $source = imagecreatefrompng($_FILES['image']['tmp_name']);
        } else {
            $source = imagecreatefromjpeg($_FILES['image']['tmp_name']);
            
            // Fix image orientation if JPEG
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($_FILES['image']['tmp_name']);
                if ($exif && isset($exif['Orientation'])) {
                    $orientation = $exif['Orientation'];
                    switch($orientation) {
                        case 3:
                            $source = imagerotate($source, 180, 0);
                            break;
                        case 6:
                            $source = imagerotate($source, -90, 0);
                            list($width, $height) = array($height, $width);
                            break;
                        case 8:
                            $source = imagerotate($source, 90, 0);
                            list($width, $height) = array($height, $width);
                            break;
                    }
                }
            }
        }

        // Resize image
        imagecopyresampled($temp_image, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        // Save image with appropriate compression
        if ($ext == 'png') {
            // PNG compression level 6 is a good balance between size and quality
            imagepng($temp_image, $upload_path, 6);
        } else {
            imagejpeg($temp_image, $upload_path, $quality);
        }

        // Free up memory
        imagedestroy($temp_image);
        imagedestroy($source);
        
        $image_path = $upload_path;
    } else {
        // For GIF files, compress if possible
        if ($ext == 'gif' && $filesize > 500000) { // If GIF is larger than 500KB
            $message = "Error: GIF file is too large. Please use a smaller GIF file.";
            goto skip_upload;
        }
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
            $image_path = $upload_path;
        } else {
            $message = "Error: Failed to upload image. Please try again.";
            goto skip_upload;
        }
    }
} else {
    // Image upload is optional, so no error message needed
    $image_path = '';
}

skip_upload:
if (!empty($message)) {
    // Error occurred during upload
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>
            <span class='block sm:inline'>$message</span>
          </div>";
}
    // Continue with student registration if no upload error
        // First, fix the SQL query to match all parameters
$sql = "INSERT INTO students (
    class_id, firstname, lastname, gender, age, stream, image, 
    lin_number, father_name, father_contact, mother_name, mother_contact,
    home_of_residence, home_email, school_id, current_term_id, 
    last_promoted_term_id, enrollment_date, created_at, updated_at,
    admission_number, student_email, student_username, student_password
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

// Ensure all variables are properly set
$current_term_id = $current_term['id'];
$last_promoted_term_id = $last_promoted_term_id ?? 0;

// Bind parameters to the prepared statement
$stmt->bind_param("isssissssssssiiissssss", 
    $class_id,
    $firstname,
    $lastname,
    $gender,
    $age,
    $stream,
    $image_path,
    $lin_number,
    $father_name,
    $father_contact,
    $mother_name,
    $mother_contact,
    $home_of_residence,
    $home_email,
    $user_school_id,
    $current_term_id,
    $last_promoted_term_id,
    $enrollment_date,
    $admission_number,
    $student_email,
    $student_username,
    $student_password
);
 
        if ($stmt->execute()) {
            $student_id = $stmt->insert_id;
            // Add entry to student_enrollments table
            $enroll_sql = "INSERT INTO student_enrollments (student_id, class_id, term_id, school_id, created_at) VALUES (?, ?, ?, ?, NOW())";
            $enroll_stmt = $conn->prepare($enroll_sql);
            $enroll_stmt->bind_param("iiii", $student_id, $class_id, $current_term['id'], $user_school_id);
            $enroll_stmt->execute();
            $enroll_stmt->close();
            $message = "Student added successfully with admission number: $admission_number<br>Student Email: $student_email<br>Login Username: $student_username<br>Default Password: $admission_number";
        } else {
            $message = "Error adding student: " . $conn->error;
        }
        $stmt->close();

    } elseif (isset($_POST['update_student'])) {
        // Update student logic with uppercase conversion
        $id = (int)$_POST['id'];
        $firstname = strtoupper($conn->real_escape_string($_POST['firstname']));
        $lastname = strtoupper($conn->real_escape_string($_POST['lastname']));
        $gender = strtoupper($conn->real_escape_string($_POST['gender']));
        $age = $conn->real_escape_string($_POST['age']);
        $stream = strtoupper($conn->real_escape_string($_POST['stream']));
        $class_id = $conn->real_escape_string($_POST['class_id']);
        $lin_number = strtoupper($conn->real_escape_string($_POST['lin_number']));
        
        // Handle image update
        $image_path = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
            $filename = $_FILES['image']['name'];
            $filetype = $_FILES['image']['type'];
            $filesize = $_FILES['image']['size'];
        
            // Verify file extension
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (!array_key_exists($ext, $allowed)) die("Error: Please select a valid file format.");
        
            // Verify file size - 5MB maximum
            $maxsize = 5 * 1024 * 1024;
            if ($filesize > $maxsize) die("Error: File size is larger than the allowed limit.");
        
            // Verify MIME type of the file
            if (in_array($filetype, $allowed)) {
                if (file_exists("uploads/" . $filename)) {
                    echo $filename . " already exists.";
                } else {
                    if (move_uploaded_file($_FILES['image']['tmp_name'], "uploads/" . $filename)) {
                        $image_path = "uploads/" . $filename;
                    } else {
                        echo "Error uploading file.";
                    }
                }
            } else {
                echo "Error: There was a problem uploading your file. Please try again.";
            }
        }
        
        $sql = "UPDATE students SET firstname = ?, lastname = ?, gender = ?, age = ?, stream = ?, class_id = ?, lin_number = ?";
        $params = array($firstname, $lastname, $gender, $age, $stream, $class_id, $lin_number);
        $types = "sssisis";
        
        if ($image_path) {
            $sql .= ", image = ?";
            $params[] = $image_path;
            $types .= "s";
        }
        
        $sql .= " WHERE id = ? AND school_id = ?";
        $params[] = $id;
        $params[] = $user_school_id;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $message = "Student updated successfully.";
        } else {
            $message = "Error updating student: " . $conn->error;
        }
        $stmt->close();
    }

    // Handle CSV Upload
    if (isset($_POST['upload_csv'])) {
        if (!$selected_class_id) {
            $message = "Error: Please select a class before uploading students.";
        } elseif (isset($_FILES['students_csv']) && $_FILES['students_csv']['error'] == 0) {
            $csvFile = $_FILES['students_csv']['tmp_name'];
            
            // Check if the file is actually a CSV
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $csvFile);
            finfo_close($fileInfo);
            
            if (!in_array($mimeType, ['text/csv', 'text/plain', 'application/vnd.ms-excel'])) {
                $message = "Error: The uploaded file is not a valid CSV file. Detected type: " . $mimeType;
                goto skip_upload;
            }
            
            $handle = fopen($csvFile, 'r');
            if ($handle === false) {
                $message = "Error: Unable to open CSV file.";
                goto skip_upload;
            }
            
            // Try to detect the CSV encoding and convert to UTF-8 if necessary
            $content = file_get_contents($csvFile);
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content, 'UTF-8, ISO-8859-1'));
                file_put_contents($csvFile, $content);
                fclose($handle);
                $handle = fopen($csvFile, 'r');
            }
            
            if ($handle !== false) {
                // Read and validate header row
                $header = fgetcsv($handle);
                
                // Clean up headers
                $header = array_map(function($h) {
                    return trim(strtolower($h));
                }, $header);
                
                $requiredHeaders = ['firstname', 'lastname', 'gender', 'age', 'stream', 'lin_number', 
                                  'father_name', 'father_contact', 'mother_name', 'mother_contact', 
                                  'home_of_residence', 'home_email'];
                
                // Validate headers
                $missingHeaders = array_diff($requiredHeaders, $header);
                
                if (!empty($missingHeaders)) {
                    $message = "Error: Missing required columns: " . implode(', ', $missingHeaders);
                } else {
                    // Process CSV rows
                    $rowCount = 0;
                    $successCount = 0;
                    $errorRows = [];
                    $errors = [];
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        while (($row = fgetcsv($handle)) !== false) {
                            $rowCount++;
                            
                            // Map CSV columns to data array
                            $data = array_combine($header, $row);
                            
                            // Check if the required fields exist in the data array
                            $missingFields = [];
                            foreach(['firstname', 'lastname', 'gender', 'stream'] as $field) {
                                if (!isset($data[$field]) || trim($data[$field]) === '') {
                                    $missingFields[] = $field;
                                }
                            }

                            if (!empty($missingFields)) {
                                $errorRows[] = "Row {$rowCount}: Missing required fields (" . implode(', ', $missingFields) . "). Data received: " . json_encode($data);
                                continue;
                            }
                            
                            // Validate and sanitize data
                            // Capitalize names using ucwords and then convert to upper case
                            $firstname = strtoupper(ucwords(strtolower($conn->real_escape_string(trim($data['firstname'])))));
                            $lastname = strtoupper(ucwords(strtolower($conn->real_escape_string(trim($data['lastname'])))));
                            $gender = strtoupper($conn->real_escape_string(trim($data['gender'])));
                            $age = !empty(trim($data['age'])) ? intval(trim($data['age'])) : 0;
                            $stream = strtoupper($conn->real_escape_string(trim($data['stream'])));
                            $lin_number = strtoupper($conn->real_escape_string(trim($data['lin_number'])));
                            $father_name = strtoupper(ucwords(strtolower($conn->real_escape_string(trim($data['father_name'])))));
                            $father_contact = strtoupper($conn->real_escape_string(trim($data['father_contact'])));
                            $mother_name = strtoupper(ucwords(strtolower($conn->real_escape_string(trim($data['mother_name'])))));
                            $mother_contact = strtoupper($conn->real_escape_string(trim($data['mother_contact'])));
                            $home_of_residence = strtoupper($conn->real_escape_string(trim($data['home_of_residence'])));
                            $home_email = strtolower($conn->real_escape_string(trim($data['home_email'])));
                            
                            // Generate unique admission number
                            $admission_number = generateAdmissionNumber($conn, $user_school_id);
                            
                            // Generate student email and username using sanitized names
                            $student_email = generateStudentEmail($conn, $firstname, $lastname, $user_school_id);
                            $student_username = generateStudentUsername($conn, $firstname, $lastname, $user_school_id);
                            
                            // Check if email and username were generated successfully
                            if (!$student_email || !$student_username) {
                                $errorRows[] = "Row {$rowCount}: Could not generate student email or username. Please check if school information is properly configured.";
                                continue;
                            }
                            
                            // Generate default password (admission number)
                            $student_password = password_hash($admission_number, PASSWORD_DEFAULT);
                            
                            // Set default values for missing variables
                            $last_promoted_term_id = null;
                            $enrollment_date = date('Y-m-d');
                            
                            // Check if current term exists
                            if (!$current_term) {
                                $errorRows[] = "Row {$rowCount}: No active term found. Please set an active term before adding students.";
                                continue;
                            }
                            
                            // Validate gender
                            if (!in_array($gender, ['MALE', 'FEMALE'])) {
                                $errorRows[] = "Row {$rowCount}: Invalid gender value (must be MALE or FEMALE)";
                                continue;
                            }
                            
                            // Validate age only if it's provided
                            if (!is_null($age) && ($age < 1 || $age > 100)) {
                                $errorRows[] = "Row {$rowCount}: Invalid age value (must be between 1 and 100)";
                                continue;
                            }
                            
                            // Insert student with auto-generated admission number
                            $sql = "INSERT INTO students (
                                class_id, firstname, lastname, gender, age, stream, lin_number,
                                father_name, father_contact, mother_name, mother_contact,
                                home_of_residence, home_email, school_id, current_term_id, last_promoted_term_id, enrollment_date,
                                created_at, updated_at, admission_number, student_email, student_username, student_password
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?)";
                            
                            $stmt = $conn->prepare($sql);
                            
                            // Debug: Check if all variables are properly defined for CSV
                            if (!isset($selected_class_id) || !isset($firstname) || !isset($lastname) || !isset($gender) || 
                                !isset($age) || !isset($stream) || !isset($lin_number) || 
                                !isset($father_name) || !isset($father_contact) || !isset($mother_name) || 
                                !isset($mother_contact) || !isset($home_of_residence) || !isset($home_email) || 
                                !isset($user_school_id) || !isset($current_term['id']) || 
                                !isset($enrollment_date) || !isset($admission_number) || !isset($student_email) || 
                                !isset($student_username) || !isset($student_password)) {
                                $errorRows[] = "Row {$rowCount}: Some required variables are not properly defined.";
                                continue;
                            }

                            // Prepare parameters array for CSV upload
                            $csv_params = array(
                                $selected_class_id, $firstname, $lastname, $gender, $age, $stream,
                                $lin_number, $father_name, $father_contact, $mother_name, $mother_contact,
                                $home_of_residence, $home_email, $user_school_id, $current_term['id'], $last_promoted_term_id, $enrollment_date,
                                $admission_number, $student_email, $student_username, $student_password
                            );
                            
                            // Debug: Print the CSV parameters array

                            
                            $stmt->bind_param("isssissssssssiiisssss", ...$csv_params);
                            
                            if ($stmt->execute()) {
                                $student_id = $stmt->insert_id;
                                
                                // Add entry to student_enrollments table
                                $enroll_sql = "INSERT INTO student_enrollments (student_id, class_id, term_id, school_id, created_at) VALUES (?, ?, ?, ?, NOW())";
                                $enroll_stmt = $conn->prepare($enroll_sql);
                                $enroll_stmt->bind_param("iiii", $student_id, $selected_class_id, $current_term['id'], $user_school_id);
                                $enroll_stmt->execute();
                                $enroll_stmt->close();
                                
                                $successCount++;
                            } else {
                                $errorRows[] = "Row {$rowCount}: Database error";
                            }
                            $stmt->close();
                        }
                        
                        // If there were no errors, commit the transaction
                        if (empty($errorRows)) {
                            $conn->commit();
                            $message = "Successfully uploaded {$successCount} students. Admission numbers, emails, and usernames were automatically generated.";
                        } else {
                            // If there were errors, rollback and show error message
                            $conn->rollback();
                            $message = "Upload completed with errors. Successfully processed: {$successCount}, Errors: " . count($errorRows);
                            $message .= "<br>Error details:<br>" . implode("<br>", $errorRows);
                        }
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "An error occurred during upload: " . $e->getMessage();
                    }
                }
                fclose($handle);
            } else {
                $message = "Error: Unable to read CSV file.";
            }
        } else {
            $message = "Error: Please upload a valid CSV file.";
        }
    }
}

// Delete student
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $sql = "DELETE FROM students WHERE id = ? AND school_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $user_school_id);

    if ($stmt->execute()) {
        $message = "Student deleted successfully.";
    } else {
        $message = "Error deleting student: " . $conn->error;
    }
    $stmt->close();
}

// Fetch students for a specific class with search
if ($selected_class_id && $current_term) {
    $students_query = $conn->prepare("SELECT s.id, s.firstname, s.lastname, s.gender, s.age, 
        s.stream, s.lin_number, s.image, c.name AS class_name, s.created_at, s.class_id,
        s.father_name, s.father_contact, s.mother_name, s.mother_contact, 
        s.home_of_residence, s.home_email, s.admission_number, s.student_email, s.student_username 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id 
        WHERE s.school_id = ? AND s.class_id = ?
        AND (s.firstname LIKE ? OR s.lastname LIKE ? OR s.stream LIKE ? OR s.admission_number LIKE ?)
        ORDER BY s.admission_number ASC");
        
    $search_param = "%$search_query%";
    $students_query->bind_param("iissss", $user_school_id, $selected_class_id, $search_param, $search_param, $search_param, $search_param);
    $students_query->execute();
    $students = $students_query->get_result();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - School Exam Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: #f3f4f6;
        }

        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.2);
        }

        .table-header {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .student-row:hover {
            background: #f8fafc;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="page-header">
        <div class="container mx-auto px-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <i class="fas fa-user-graduate text-white text-3xl"></i>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Student Management</h1>
                        <p class="text-blue-100 text-sm">Manage your school's students efficiently</p>
                    </div>
                </div>
                <a href="school_admin_dashboard.php" class="flex items-center px-4 py-2 bg-white text-blue-600 rounded-lg hover:bg-blue-50 transition-colors duration-300">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto mt-8 px-4">
        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Current Term Information -->
        <div class="card p-6 mb-8">
            <div class="flex items-center space-x-4 mb-4">
                <i class="fas fa-calendar-alt text-2xl text-blue-500"></i>
                <h2 class="text-xl font-semibold">Current Term Information</h2>
            </div>
            <?php if ($current_term): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600">Term</p>
                        <p class="text-lg font-medium"><?php echo htmlspecialchars($current_term['name']); ?></p>
                    </div>
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600">Academic Year</p>
                        <p class="text-lg font-medium"><?php echo htmlspecialchars($current_term['year']); ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                No active term. Please register a term in the admin dashboard.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Class Selection -->
        <div class="card p-6 mb-8">
            <div class="flex items-center space-x-4 mb-6">
                <i class="fas fa-chalkboard text-2xl text-blue-500"></i>
                <h2 class="text-xl font-semibold">Select Class</h2>
            </div>
            <form action="" method="GET" class="flex flex-wrap items-center gap-4">
                <select name="class_id" class="flex-grow px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Select a Class</option>
                    <?php while ($class = $classes_result->fetch_assoc()): ?>
                        <option value="<?php echo $class['id']; ?>" 
                                <?php echo ($selected_class_id == $class['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit" class="btn-primary px-6 py-2 text-white rounded-lg flex items-center">
                    <i class="fas fa-search mr-2"></i>
                    View Students
                </button>
            </form>
        </div>

        <?php if ($selected_class_id): ?>
            <!-- Search Bar -->
            <div class="card p-6 mb-8">
                <div class="flex items-center space-x-4 mb-6">
                    <i class="fas fa-search text-2xl text-blue-500"></i>
                    <h2 class="text-xl font-semibold">Search Students</h2>
                </div>
                <form action="" method="GET" class="flex flex-wrap items-center gap-4">
                    <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                    <input type="text" name="search" 
                           placeholder="Search by name, stream or admission number" 
                           value="<?php echo htmlspecialchars($search_query); ?>" 
                           class="flex-grow px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <button type="submit" class="btn-primary px-6 py-2 text-white rounded-lg">
                        <i class="fas fa-search mr-2"></i>
                        Search
                    </button>
                </form>
            </div>

            <!-- Action Buttons -->
            <div class="mb-8 flex flex-wrap gap-4 items-center">
                <button onclick="toggleAddStudentForm()" id="addStudentBtn" 
                        class="btn-primary px-6 py-3 text-white rounded-lg flex items-center shadow-lg hover:shadow-xl transition-all duration-300">
                    <i class="fas fa-user-plus mr-2"></i>
                    <span id="btnText">Add New Student</span>
                </button>
                
                <a href="update_existing_students.php" 
                   class="bg-green-500 text-white px-6 py-3 rounded-lg hover:bg-green-600 transition-all duration-300 flex items-center shadow-lg hover:shadow-xl">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Update Existing Students
                </a>
                
                <a href="download_class_list.php?class_id=<?php echo $selected_class_id; ?>" 
                   class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-all duration-300 flex items-center shadow-lg hover:shadow-xl">
                    <i class="fas fa-download mr-2"></i>
                    Download Student List
                </a>

                <a href="#" onclick="toggleBulkUpload(); return false;" 
                   class="bg-purple-500 text-white px-6 py-3 rounded-lg hover:bg-purple-600 transition-all duration-300 flex items-center shadow-lg hover:shadow-xl">
                    <i class="fas fa-file-upload mr-2"></i>
                    Bulk Upload (CSV)
                </a>
            </div>

            <!-- Bulk Upload Students via CSV (toggle) -->
            <div id="bulkUploadCard" class="card p-6 mb-8 hidden">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-4">
                        <i class="fas fa-file-csv text-2xl text-blue-500"></i>
                        <h2 class="text-xl font-semibold">Bulk Upload Students</h2>
                    </div>
                    <a href="download_csv_template.php?class_id=<?php echo $selected_class_id; ?>" class="text-blue-600 hover:text-blue-800 flex items-center">
                        <i class="fas fa-download mr-2"></i>
                        Download CSV Template
                    </a>
                </div>
                
                <form action="" method="POST" enctype="multipart/form-data" id="csvUploadForm" class="space-y-4">
                    <div class="flex flex-col space-y-2">
                        <label for="students_csv" class="text-sm font-medium text-gray-700">Choose CSV File</label>
                        <input type="file" name="students_csv" id="students_csv" accept=".csv" required 
                               class="border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-sm text-gray-500">File must be in CSV format with the required columns</p>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <button type="submit" name="upload_csv" class="bg-green-500 text-white px-6 py-2 rounded-lg hover:bg-green-600 transition-colors duration-300 flex items-center">
                            <i class="fas fa-file-upload mr-2"></i>
                            Upload Students
                        </button>
                        <div id="uploadProgress" class="hidden">
                            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-500"></div>
                            <span class="ml-2 text-sm text-gray-600">Uploading...</span>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Add Student Form -->
<div id="addStudentForm" class="bg-white p-4 sm:p-6 rounded-lg shadow-md mb-8 hidden transform transition-all duration-300">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Add New Student</h2>
        <button onclick="toggleAddStudentForm()" class="text-gray-500 hover:text-gray-700 transition-colors duration-300">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <form action="" method="POST" enctype="multipart/form-data" class="max-w-full overflow-x-hidden">
        <!-- Student Information Section -->
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-3 pb-2 border-b">Student Information</h3>
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label for="admission_number" class="block mb-1 text-sm font-medium">Admission Number (Auto-generated)</label>
                    <input type="text" id="admission_number" name="admission_number" readonly class="w-full px-3 py-2 border rounded bg-gray-100 focus:outline-none text-sm">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="firstname" class="block mb-1 text-sm font-medium">Firstname</label>
                        <input type="text" id="firstname" name="firstname" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label for="lastname" class="block mb-1 text-sm font-medium">Lastname</label>
                        <input type="text" id="lastname" name="lastname" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="gender" class="block mb-1 text-sm font-medium">Gender</label>
                        <select id="gender" name="gender" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div>
                        <label for="age" class="block mb-1 text-sm font-medium">Age</label>
                        <input type="number" id="age" name="age" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="stream" class="block mb-1 text-sm font-medium">Stream</label>
                        <input type="text" id="stream" name="stream" required class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label for="lin_number" class="block mb-1 text-sm font-medium">LIN Number</label>
                        <input type="text" id="lin_number" name="lin_number" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                </div>
                <div>
                    <label for="image" class="block mb-1 text-sm font-medium">Student Image</label>
                    <input type="file" id="image" name="image" accept="image/*" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                </div>
            </div>
        </div>

        <!-- Parent Information Section -->
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-3 pb-2 border-b">Parent Information (Optional)</h3>
            <div class="grid grid-cols-1 gap-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="father_name" class="block mb-1 text-sm font-medium">Father's Name</label>
                        <input type="text" id="father_name" name="father_name" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label for="father_contact" class="block mb-1 text-sm font-medium">Father's Contact</label>
                        <input type="text" id="father_contact" name="father_contact" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="mother_name" class="block mb-1 text-sm font-medium">Mother's Name</label>
                        <input type="text" id="mother_name" name="mother_name" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label for="mother_contact" class="block mb-1 text-sm font-medium">Mother's Contact</label>
                        <input type="text" id="mother_contact" name="mother_contact" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="home_of_residence" class="block mb-1 text-sm font-medium">Home of Residence</label>
                        <input type="text" id="home_of_residence" name="home_of_residence" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div>
                        <label for="home_email" class="block mb-1 text-sm font-medium">Email Address</label>
                        <input type="email" id="home_email" name="home_email" class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    </div>
                </div>
            </div>
        </div>

        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
        
        <div class="col-span-full">
            <button type="submit" name="add_student" class="w-full sm:w-auto mt-4 bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition-colors duration-300">Add Student</button>
        </div>
    </form>
</div>
  <!-- Delete Confirmation Modal -->
  <div id="deleteConfirmationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                    <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900">Delete Student</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500">Are you sure you want to delete this student? This action cannot be undone.</p>
                </div>
                <div class="items-center px-4 py-3">
                    <button id="deleteConfirmButton" class="px-4 py-2 bg-red-500 text-white text-base font-medium rounded-md w-1/3 shadow-sm hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-300 mr-2">
                        Delete
                    </button>
                    <button onclick="closeDeleteModal()" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md w-1/3 shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
        <!-- Students List -->
        <div class="card p-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center space-x-4">
                    <i class="fas fa-users text-2xl text-blue-500"></i>
                    <h2 class="text-xl font-semibold">Students List</h2>
                </div>
                <?php if ($search_query): ?>
                    <div class="bg-blue-50 px-4 py-2 rounded-lg">
                        <p class="text-sm text-blue-600">
                            Search results for: "<?php echo htmlspecialchars($search_query); ?>"
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Add a statistics summary -->
            <?php if ($students && $students->num_rows > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-600">Total Students</p>
                        <p class="text-2xl font-semibold text-blue-600"><?php echo $students->num_rows; ?></p>
                    </div>
                    <!-- Add more statistics as needed -->
                </div>
            <?php endif; ?>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="py-2 px-4 border-b text-left">Image</th>
                            <th class="py-2 px-4 border-b text-left">Adm No</th>
                            <th class="py-2 px-4 border-b text-left">Name</th>
                            <th class="py-2 px-4 border-b text-left">Stream</th>
                            <th class="py-2 px-4 border-b text-left">LIN Number</th>
                            <th class="py-2 px-4 border-b text-left">Student Email</th>
                            <th class="py-2 px-4 border-b text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($students && $students->num_rows > 0): ?>
                            <?php while ($student = $students->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 px-4 border-b">
                                        <?php if ($student['image']): ?>
                                            <img src="<?php echo htmlspecialchars($student['image']); ?>" alt="Student Image" class="w-16 h-16 object-cover rounded-full">
                                        <?php else: ?>
                                            <div class="w-16 h-16 bg-gray-200 rounded-full flex items-center justify-center">
                                                <span class="text-gray-500">No Image</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-4 border-b font-medium"><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?></td>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($student['stream']); ?></td>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($student['lin_number']); ?></td>
                                    <td class="py-2 px-4 border-b"><?php echo htmlspecialchars($student['student_email'] ?? 'Not set'); ?></td>
                                    <td class="py-2 px-4 border-b">
                                        <button onclick="openDetailsModal(<?php echo htmlspecialchars(json_encode($student)); ?>)" class="bg-blue-500 text-white px-2 py-1 rounded hover:bg-blue-600 mr-2 transition-colors duration-300">View Details</button>
                                        <button onclick="openUpdateModal(<?php echo htmlspecialchars(json_encode($student)); ?>)" class="bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-600 mr-2 transition-colors duration-300">Update</button>
                                       <button onclick="deleteStudent(<?php echo $student['id']; ?>, <?php echo $selected_class_id; ?>)" class="bg-red-500 text-white px-2 py-1 rounded hover:bg-red-600 transition-colors duration-300">Delete</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="py-4 px-4 border-b text-center">
                                    <?php if ($search_query): ?>
                                        No students found matching your search.
                                    <?php else: ?>
                                        No students found in this class for the current term.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($selected_class_id && !$current_term): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline">No active term. Please register a term in the admin dashboard before managing students.</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Update Student Modal -->
    <div id="updateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Update Student</h3>
            <form id="updateForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="update_id" name="id">
                <div class="mb-4">
                    <label for="update_firstname" class="block mb-2">FirstName</label>
                    <input type="text" id="update_firstname" name="firstname" required class="w-full px-3 py-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="update_lastname" class="block mb-2">Last Name</label>
                    <input type="text" id="update_lastname" name="lastname" required class="w-full px-3 py-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="update_gender" class="block mb-2">Gender</label>
                    <select id="update_gender" name="gender" required class="w-full px-3 py-2 border rounded">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="update_age" class="block mb-2">Age</label>
                    <input type="number" id="update_age" name="age" required class="w-full px-3 py-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="update_stream" class="block mb-2">Stream</label>
                    <input type="text" id="update_stream" name="stream" required class="w-full px-3 py-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="update_lin_number" class="block mb-2">LIN Number</label>
                    <input type="text" id="update_lin_number" name="lin_number" required class="w-full px-3 py-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="update_image" class="block mb-2">Student Image</label>
                    <input type="file" id="update_image" name="image" accept="image/*" class="w-full px-3 py-2 border rounded">
                </div>
                <input type="hidden" id="update_class_id" name="class_id">
                <div class="mt-4 flex justify-between">
                    <button type="submit" name="update_student" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition-colors duration-300">Update Student</button>
                    <button type="button" onclick="closeUpdateModal()" class="bg-gray-300 text-gray-800 px-4 py-2 rounded hover:bg-gray-400 transition-colors duration-300">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Details Modal -->
  <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-[40rem] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium leading-6 text-gray-900">Student Details</h3>
            <div class="flex items-center">
                <button onclick="printStudentDetails()" class="mr-2 text-blue-500 hover:text-blue-700 transition-colors duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                </button>
                <button onclick="closeDetailsModal()" class="text-gray-500 hover:text-gray-700 transition-colors duration-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <div id="studentDetailsContent" class="space-y-6">
            <!-- Student Information Section -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="text-md font-semibold mb-4 text-blue-600">Student Information</h4>
                <div class="flex justify-center mb-4">
                    <div id="studentDetailsImage" class="w-32 h-32 rounded-full object-cover border-4 border-blue-500"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <strong class="text-gray-600">First Name:</strong>
                        <p id="detailFirstName" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Last Name:</strong>
                        <p id="detailLastName" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Gender:</strong>
                        <p id="detailGender" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Age:</strong>
                        <p id="detailAge" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Stream:</strong>
                        <p id="detailStream" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">LIN Number:</strong>
                        <p id="detailLinNumber" class="mt-1"></p>
                    </div>
                    <div class="col-span-2">
                        <strong class="text-gray-600">Registered:</strong>
                        <p id="detailCreatedAt" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Student Email:</strong>
                        <p id="detailStudentEmail" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Login Username:</strong>
                        <p id="detailStudentUsername" class="mt-1"></p>
                    </div>
                </div>
            </div>

            <!-- Parent Information Section -->
            <div class="bg-gray-50 p-4 rounded-lg">
                <h4 class="text-md font-semibold mb-4 text-blue-600">Parent Information</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <strong class="text-gray-600">Father's Name:</strong>
                        <p id="detailFatherName" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Father's Contact:</strong>
                        <p id="detailFatherContact" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Mother's Name:</strong>
                        <p id="detailMotherName" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Mother's Contact:</strong>
                        <p id="detailMotherContact" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Home of Residence:</strong>
                        <p id="detailHomeResidence" class="mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-600">Email Address:</strong>
                        <p id="detailHomeEmail" class="mt-1"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <script>
        // Function to toggle the Add Student form
        function toggleAddStudentForm() {
            const form = document.getElementById('addStudentForm');
            const btn = document.getElementById('addStudentBtn');
            const btnText = document.getElementById('btnText');
            
            if (form.classList.contains('hidden')) {
                // Show form
                form.classList.remove('hidden');
                form.classList.add('scale-100');
                btnText.textContent = '- Cancel';
                btn.classList.replace('bg-blue-500', 'bg-red-500');
                btn.classList.replace('hover:bg-blue-600', 'hover:bg-red-600');
            } else {
                // Hide form
                form.classList.add('hidden');
                form.classList.remove('scale-100');
                btnText.textContent = '+ Add New Student';
                btn.classList.replace('bg-red-500', 'bg-blue-500');
                btn.classList.replace('hover:bg-red-600', 'hover:bg-blue-600');
                
                // Reset form
                document.querySelector('form').reset();
            }
        }
// Function to open the Details Modal
function openDetailsModal(student) {
    // Existing student information
    document.getElementById('detailFirstName').textContent = student.firstname;
    document.getElementById('detailLastName').textContent = student.lastname;
    document.getElementById('detailGender').textContent = student.gender;
    document.getElementById('detailAge').textContent = student.age;
    document.getElementById('detailStream').textContent = student.stream;
    document.getElementById('detailLinNumber').textContent = student.lin_number;
    document.getElementById('detailCreatedAt').textContent = student.created_at;

    // Add login credentials
    document.getElementById('detailStudentEmail').textContent = student.student_email || 'Not set';
    document.getElementById('detailStudentUsername').textContent = student.student_username || 'Not set';

    // Add parent information
    document.getElementById('detailFatherName').textContent = student.father_name;
    document.getElementById('detailFatherContact').textContent = student.father_contact;
    document.getElementById('detailMotherName').textContent = student.mother_name;
    document.getElementById('detailMotherContact').textContent = student.mother_contact;
    document.getElementById('detailHomeResidence').textContent = student.home_of_residence;
    document.getElementById('detailHomeEmail').textContent = student.home_email;

    // Set student image
    const imageContainer = document.getElementById('studentDetailsImage');
    if (student.image) {
        imageContainer.innerHTML = `<img src="${student.image}" alt="Student Image" class="w-full h-full rounded-full object-cover">`;
    } else {
        imageContainer.innerHTML = '<div class="w-full h-full bg-gray-200 rounded-full flex items-center justify-center text-gray-500">No Image</div>';
    }
    
    // Show modal with animation
    const modal = document.getElementById('detailsModal');
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.querySelector('.relative').classList.add('transform', 'translate-y-0', 'opacity-100');
    }, 100);
}

// Function to close the Details Modal
function closeDetailsModal() {
    const modal = document.getElementById('detailsModal');
    const modalContent = modal.querySelector('.relative');
    
    // Hide modal with animation
    modalContent.classList.remove('transform', 'translate-y-0', 'opacity-100');
    modalContent.classList.add('transform', '-translate-y-4', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        modalContent.classList.remove('transform', '-translate-y-4', 'opacity-0');
    }, 300);
}

// Close details modal when clicking outside
document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDetailsModal();
    }
});

// Add escape key listener to close details modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('detailsModal').classList.contains('hidden')) {
        closeDetailsModal();
    }
});
        // Function to open the Update Modal
        function openUpdateModal(student) {
            document.getElementById('update_id').value = student.id;
            document.getElementById('update_firstname').value = student.firstname;
            document.getElementById('update_lastname').value = student.lastname;
            document.getElementById('update_gender').value = student.gender;
            document.getElementById('update_age').value = student.age;
            document.getElementById('update_stream').value = student.stream;
            document.getElementById('update_class_id').value = student.class_id;
            document.getElementById('update_lin_number').value = student.lin_number;
            
            // Show modal with animation
            const modal = document.getElementById('updateModal');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.querySelector('.relative').classList.add('transform', 'translate-y-0', 'opacity-100');
            }, 100);
        }

        // Function to close the Update Modal
        function closeUpdateModal() {
            const modal = document.getElementById('updateModal');
            const modalContent = modal.querySelector('.relative');
            
            // Hide modal with animation
            modalContent.classList.remove('transform', 'translate-y-0', 'opacity-100');
            modalContent.classList.add('transform', '-translate-y-4', 'opacity-0');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                modalContent.classList.remove('transform', '-translate-y-4', 'opacity-0');
            }, 300);
        }

        // Close modal when clicking outside
        document.getElementById('updateModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeUpdateModal();
            }
        });

        // Add escape key listener to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('updateModal').classList.contains('hidden')) {
                closeUpdateModal();
            }
        });

        // Prevent form submission if required fields are empty
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const requiredFields = form.querySelectorAll('[required]');
                let isValid = true;
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        isValid = false;
                        field.classList.add('border-red-500');
                    } else {
                        field.classList.remove('border-red-500');
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
        });

        // Add input validation for age
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('input', function(e) {
                if (this.value < 0) this.value = 0;
                if (this.value > 100) this.value = 100;
            });
        });

        function printStudentDetails() {
    const printWindow = window.open('', '', 'width=600,height=800');
    
    // Get all the details (student and parent)
    const details = {
        firstName: document.getElementById('detailFirstName').textContent,
        lastName: document.getElementById('detailLastName').textContent,
        gender: document.getElementById('detailGender').textContent,
        age: document.getElementById('detailAge').textContent,
               stream: document.getElementById('detailStream').textContent,
        linNumber: document.getElementById('detailLinNumber').textContent,
        createdAt: document.getElementById('detailCreatedAt').textContent,
        studentEmail: document.getElementById('detailStudentEmail').textContent,
        studentUsername: document.getElementById('detailStudentUsername').textContent,
        fatherName: document.getElementById('detailFatherName').textContent,
        fatherContact: document.getElementById('detailFatherContact').textContent,
        motherName: document.getElementById('detailMotherName').textContent,
        motherContact: document.getElementById('detailMotherContact').textContent,
        homeResidence: document.getElementById('detailHomeResidence').textContent,
        homeEmail: document.getElementById('detailHomeEmail').textContent
    };
    
    const imageHtml = document.getElementById('studentDetailsImage').innerHTML;

    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Student Details</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 10px;
                    font-size: 12px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 50px;  /* Increased margin bottom */
                    padding-bottom: 20px; /* Added padding bottom */
                    border-bottom: 2px solid #e5e7eb; /* Added border for visual separation */
                }
                .student-image {
                    width: 120px;
                    height: 120px;
                    border-radius: 50%;
                    object-fit: cover;
                    margin: 0 auto;
                    display: block;
                }
                .section {
                    margin-top: 30px;  /* Increased top margin */
                    margin-bottom: 15px;
                    padding: 15px;     /* Increased padding */
                    border: 1px solid #ddd;
                    background-color: #fafafa; /* Added subtle background */
                }
                .section-title {
                    color: #2563eb;
                    font-size: 14px;
                    margin-bottom: 12px;  /* Increased margin */
                    border-bottom: 1px solid #e5e7eb;
                    padding-bottom: 5px;  /* Added padding */
                }
                .details-grid {
                    display: grid;
                    grid-template-columns: repeat(3, 1fr);
                    gap: 12px;          /* Increased gap */
                }
                .detail-item {
                    margin-bottom: 6px;  /* Increased margin */
                }
                .detail-item strong {
                    display: inline-block;
                    color: #555;
                    margin-right: 4px;
                }
                @media print {
                    body { margin: 0; padding: 5px; }
                    .section { break-inside: avoid; }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1 style="font-size: 16px; margin: 0 0 20px 0;">Student Details</h1>
                <div class="student-image">
                    ${imageHtml}
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">Student Information</h2>
                <div class="details-grid">
                    <div class="detail-item"><strong>Name:</strong> ${details.firstName} ${details.lastName}</div>
                    <div class="detail-item"><strong>Gender:</strong> ${details.gender}</div>
                    <div class="detail-item"><strong>Age:</strong> ${details.age}</div>
                    <div class="detail-item"><strong>Stream:</strong> ${details.stream}</div>
                    <div class="detail-item"><strong>LIN:</strong> ${details.linNumber}</div>
                    <div class="detail-item"><strong>Registered:</strong> ${details.createdAt}</div>
                    <div class="detail-item"><strong>Student Email:</strong> ${details.studentEmail}</div>
                    <div class="detail-item"><strong>Login Username:</strong> ${details.studentUsername}</div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title">Parent Information</h2>
                <div class="details-grid">
                    <div class="detail-item"><strong>Father:</strong> ${details.fatherName}</div>
                    <div class="detail-item"><strong>Father Contact:</strong> ${details.fatherContact}</div>
                    <div class="detail-item"><strong>Mother:</strong> ${details.motherName}</div>
                    <div class="detail-item"><strong>Mother Contact:</strong> ${details.motherContact}</div>
                    <div class="detail-item"><strong>Residence:</strong> ${details.homeResidence}</div>
                    <div class="detail-item"><strong>Email:</strong> ${details.homeEmail}</div>
                </div>
            </div>

            <script>
                window.onload = function() {
                    window.print();
                    window.close();
                }
            <\/script>
        </body>
        </html>
    `;

    printWindow.document.write(printContent);
    printWindow.document.close();
}

function deleteStudent(studentId, classId) {
            const modal = document.getElementById('deleteConfirmationModal');
            const confirmButton = document.getElementById('deleteConfirmButton');
            modal.classList.remove('hidden');
            modal.classList.add('fade-in');

            confirmButton.onclick = function() {
                const deleteUrl = `?delete=${studentId}&class_id=${classId}`;
                
                // Perform the deletion using fetch
                fetch(deleteUrl)
                    .then(response => {
                        if (response.ok) {
                            // Close the modal
                            closeDeleteModal();
                            showNotification('Student deleted successfully', 'success');
                            
                            // Refresh the page after a short delay
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            throw new Error('Failed to delete student');
                        }
                    })
                    .catch(error => {
                        showNotification('Error deleting student', 'error');
                    });
            };
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteConfirmationModal');
            modal.classList.add('hidden');
            modal.classList.remove('fade-in');
        }

        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in-out flex items-center ${
                type === 'success' ? 'bg-green-500' : 'bg-red-500'
            } text-white`;
            
            // Add icon based on type
            const icon = type === 'success' ? 
                '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>' :
                '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
            
            notification.innerHTML = icon + message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Close modal when clicking outside
        document.getElementById('deleteConfirmationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        // Add escape key listener to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('deleteConfirmationModal').classList.contains('hidden')) {
                closeDeleteModal();
            }
        });

        // Add necessary CSS animations
        const style = document.createElement('style');
        style.textContent = `
            .fade-in {
                animation: fadeIn 0.3s ease-in-out;
            }
            
            .animate-fade-in-out {
                animation: fadeInOut 3s ease-in-out;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            @keyframes fadeInOut {
                0% { opacity: 0; transform: translateY(-20px); }
                10% { opacity: 1; transform: translateY(0); }
                90% { opacity: 1; transform: translateY(0); }
                100% { opacity: 0; transform: translateY(-20px); }
            }
        `;
        document.head.appendChild(style);
        function fetchAdmissionNumber() {
    // Use AJAX to get the next admission number from the server
    fetch('get_next_admission.php')
        .then(response => response.text())
        .then(data => {
            document.getElementById('admission_number').value = data;
        })
        .catch(error => console.error('Error fetching admission number:', error));
}

function toggleBulkUpload() {
    const card = document.getElementById('bulkUploadCard');
    if (card.classList.contains('hidden')) {
        card.classList.remove('hidden');
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        card.classList.add('hidden');
    }
}

function toggleAddStudentForm() {
    const form = document.getElementById('addStudentForm');
    const btn = document.getElementById('addStudentBtn');
    const btnText = document.getElementById('btnText');
    
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        btnText.textContent = '- Hide Form';
        fetchAdmissionNumber(); // Get the next admission number when form is opened
    } else {
        form.classList.add('hidden');
        btnText.textContent = '+ Add New Student';
    }
}

function handleDownload(hasSelectedClass) {
    if (!hasSelectedClass) {
        showNotification('Please select a class and view students first', 'error');
        return;
    }
    
    // Proceed with download
    window.location.href = 'download_class_list.php?class_id=<?php echo $selected_class_id; ?>';
}

// CSV Upload Handling
document.getElementById('csvUploadForm').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('students_csv');
    const uploadProgress = document.getElementById('uploadProgress');
    
    // Validate file
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        
        // Check file type
        if (!file.name.endsWith('.csv')) {
            e.preventDefault();
            showNotification('Please upload a CSV file', 'error');
            return;
        }
        
        // Check file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            e.preventDefault();
            showNotification('File size should be less than 5MB', 'error');
            return;
        }
        
        // Show upload progress
        uploadProgress.classList.remove('hidden');
    }
});

// Function to validate CSV file
function validateCSVFile(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const text = e.target.result;
            const lines = text.split('\n');
            if (lines.length < 2) {
                reject('CSV file must contain at least a header row and one data row');
            } else {
                resolve();
            }
        };
        reader.readAsText(file);
    });
}

// Enhanced CSV upload with validation
document.getElementById('csvUploadForm').addEventListener('submit', async function(e) {
    const fileInput = document.getElementById('students_csv');
    const uploadProgress = document.getElementById('uploadProgress');
    
    // Validate file
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        
        // Check file type
        if (!file.name.endsWith('.csv')) {
            e.preventDefault();
            showNotification('Please upload a CSV file', 'error');
            return;
        }
        
        // Check file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            e.preventDefault();
            showNotification('File size should be less than 5MB', 'error');
            return;
        }
        
        // Show upload progress
        uploadProgress.classList.remove('hidden');
        
        // Validate CSV content
        try {
            await validateCSVFile(file);
            showNotification('CSV file is valid', 'success');
        } catch (error) {
            e.preventDefault();
            showNotification(error, 'error');
            this.value = ''; // Clear the file input
        }
    }
});
    </script>
    </body>
    </html