 <?php
// download_students_pdf.php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Include FPDF library
require_once('fpdf.php');

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';


$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get school information function
function getSchoolInfo($conn, $school_id) {
    $query = "SELECT school_name, email, phone, badge, location FROM schools WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

if (isset($_GET['subject_id']) && isset($_GET['class_id'])) {
    $subject_id = $_GET['subject_id'];
    $class_id = $_GET['class_id'];
    
    // Get subject details
    $subject_query = "SELECT s.subject_id, s.school_id, s.subject_name, s.subject_code,
                            c.name as class_name 
                     FROM subjects s 
                     JOIN classes c ON s.class_id = c.id 
                     WHERE s.subject_id = ?";
    $stmt = $conn->prepare($subject_query);
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $subject_result = $stmt->get_result();
    $subject_data = $subject_result->fetch_assoc();
    $stmt->close();

    // Get school information
    $school_info = getSchoolInfo($conn, $subject_data['school_id']);

    // Get current term information
    $term_query = "SELECT name FROM terms WHERE school_id = ? AND is_current = 1";
    $stmt = $conn->prepare($term_query);
    $stmt->bind_param("i", $subject_data['school_id']);
    $stmt->execute();
    $term_result = $stmt->get_result();
    $term_data = $term_result->fetch_assoc();
    $stmt->close();

    // Get students enrolled in the subject for the specific class, sorted alphabetically by lastname and firstname
    $query = "SELECT 
                s.id,
                s.firstname,
                s.lastname,
                s.admission_number
              FROM students s 
              JOIN student_subjects ss ON s.id = ss.student_id 
              WHERE ss.subject_id = ? AND s.class_id = ? AND ss.status = 'active'
              ORDER BY s.admission_number ASC";  // Ensures alphabetical order
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $subject_id, $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Create new PDF document using FPDF
    class PDF extends FPDF {
        protected $schoolInfo;
        protected $tableHeaders = [
            ['width' => 10, 'text' => 'No.'],
            ['width' => 30, 'text' => 'Adm No.'],
            ['width' => 45, 'text' => 'Student Name'],
            ['width' => 60, 'text' => 'Signature'],
            ['width' => 40, 'text' => 'Marks']
        ];
        protected $subjectInfo;
        protected $headerHeight;

        public function setSchoolInfo($info) {
            $this->schoolInfo = $info;
        }
        
        public function setSubjectInfo($info) {
            $this->subjectInfo = $info;
        }

        public function Header() {
            // Store initial Y position
            $startY = $this->GetY();
            
            // Remove colored background
            $this->SetDrawColor(0);  // Black color for borders

            // Badge handling
            if (!empty($this->schoolInfo['badge'])) {
                $badge_path = 'uploads/' . $this->schoolInfo['badge'];
                if (file_exists($badge_path) && getimagesize($badge_path)) {
                    list($width, $height) = getimagesize($badge_path);
                    $aspect_ratio = $width / $height;
                    
                    $max_height = 27;
                    $max_width = 27;
                    
                    if ($aspect_ratio > 1) {
                        $new_width = $max_width;
                        $new_height = $max_width / $aspect_ratio;
                    } else {
                        $new_height = $max_height;
                        $new_width = $max_height * $aspect_ratio;
                    }
                    
                    $y_position = 5 + ($max_height - $new_height) / 2;
                    $this->Image($badge_path, 15, $y_position, $new_width, $new_height, '', '', '', false, 300);
                }
            }
        
            // School Name
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(0);  // Black text
            $this->Cell(0, 8, strtoupper($this->schoolInfo['school_name']), 0, 1, 'C');
            
            // School Location
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0);  // Black text
            $this->Cell(0, 4, $this->schoolInfo['location'], 0, 1, 'C');
            
            // School Contact Information
            $this->Cell(0, 5, 'Tel: ' . $this->schoolInfo['phone'], 0, 1, 'C');
            $this->Cell(0, 5, 'Email: ' . $this->schoolInfo['email'], 0, 1, 'C');
            
            // Add a line to separate header section
            $this->Line(10, $this->GetY(), 200, $this->GetY());
            
            // Add space
            $this->Ln(10);
            
            // Add subject information if available
            if ($this->subjectInfo) {
                $this->SetFont('Arial', 'B', 10);
                $this->SetTextColor(0);  // Black text
                $this->Cell(0, 5, 'Subject: ' . $this->subjectInfo['subject_name'], 0, 1, 'L');
                $this->Cell(0, 5, 'Class: ' . $this->subjectInfo['class_name'], 0, 1, 'L');
                
                // Only show date on first page
                if ($this->PageNo() == 1) {
                    $this->Cell(0, 5, 'Date Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'L');
                }
                
                $this->Ln(5);
            }
            
            $this->DrawTableHeader();
            
            // Store the header height for future reference
            $this->headerHeight = $this->GetY() - $startY;
        }

        protected function DrawTableHeader() {
            // Draw the table headers
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);  // Light gray for headers
            $this->SetTextColor(0);  // Black text
            
            // Print table headers
            foreach ($this->tableHeaders as $index => $header) {
                $align = ($index == 2) ? 'L' : 'C';
                $this->Cell($header['width'], 8, $header['text'], 1, ($index == count($this->tableHeaders) - 1) ? 1 : 0, 'C', 1);
            }
        }

        public function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', 'I', 7);
            $this->Cell(0, 6, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }
    }

    // Create new PDF document
    $pdf = new PDF();
    $pdf->setSchoolInfo($school_info);
    $pdf->setSubjectInfo($subject_data);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // Set document information
    $pdf->SetTitle($subject_data['subject_name'] . ' Student List');

    // Content
    $pdf->SetFont('Arial', '', 8);
    $counter = 1;
    
    while ($row = $result->fetch_assoc()) {
        $pdf->Cell(10, 7, $counter, 1, 0, 'C'); // Reduced height from 10 to 7
        $pdf->Cell(30, 7, $row['admission_number'], 1, 0, 'C');
        $pdf->Cell(45, 7, $row['firstname'] . ' ' . $row['lastname'], 1, 0, 'L');
        $pdf->Cell(60, 7, '', 1, 0, 'C');
        $pdf->Cell(40, 7, '', 1, 1, 'C');
        
        $counter++;
    }

    // Close database connection
    $stmt->close();
    $conn->close();

    // Create a unique filename with subject name, class name, term, date and time
    $current_date = date('Y-m-d');
    $current_time = date('H-i-s');
    $term = str_replace(' ', '_', $term_data['name']);
    $filename = strtolower(str_replace(' ', '_', $subject_data['subject_name'])) . '_' . 
               strtolower(str_replace(' ', '_', $subject_data['class_name'])) . '_' . 
               $term . '_' . $current_date . '_' . $current_time . '.pdf';
    // Remove any special characters that might cause issues in filenames
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    
    // Output PDF with the new filename
    $pdf->Output($filename, 'D');
    exit();
}
