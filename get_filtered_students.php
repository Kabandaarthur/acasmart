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

$school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
$class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$stream = isset($_POST['stream']) ? $_POST['stream'] : '';

// Build query conditions
$conditions = ["s.school_id = ?"];
$params = [$school_id];
$types = "i";

if ($class_id) {
    $conditions[] = "s.class_id = ?";
    $params[] = $class_id;
    $types .= "i";
}

if ($stream) {
    $conditions[] = "s.stream = ?";
    $params[] = $stream;
    $types .= "s";
}

$query = "SELECT s.*, 
          c.name as class_name,
          t.name as current_term_name,
          pt.name as last_promoted_term_name
          FROM students s 
          JOIN classes c ON s.class_id = c.id 
          LEFT JOIN terms t ON s.current_term_id = t.id
          LEFT JOIN terms pt ON s.last_promoted_term_id = pt.id
          WHERE " . implode(" AND ", $conditions) . "
          ORDER BY c.id ASC, s.lastname ASC, s.firstname ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($student = $result->fetch_assoc()) {
    echo '<tr class="student-row">';
    echo '<td><input type="checkbox" class="form-check-input student-checkbox" name="selected_students[]" value="' . htmlspecialchars($student['id']) . '"></td>';
    echo '<td>' . htmlspecialchars($student['firstname'] . ' ' . $student['lastname']) . '</td>';
    echo '<td>' . htmlspecialchars($student['class_name']) . '</td>';
    echo '<td>' . htmlspecialchars($student['stream']) . '</td>';
    echo '<td>' . htmlspecialchars($student['current_term_name']) . '</td>';
    echo '<td>' . htmlspecialchars($student['last_promoted_term_name'] ?? '') . '</td>';
    echo '</tr>';
}

$stmt->close();
$conn->close();
