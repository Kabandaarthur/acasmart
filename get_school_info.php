 <?php
header('Content-Type: application/json');

// Database connection
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]));
}

// Get school domain from query parameter
$domain = isset($_GET['domain']) ? $_GET['domain'] : '';

if (empty($domain)) {
    echo json_encode([
        'success' => false,
        'error' => 'No domain provided'
    ]);
    exit;
}

// Query to get school information based on the domain
$stmt = $conn->prepare("
    SELECT id, school_name, badge, motto 
    FROM schools 
    WHERE LOWER(REPLACE(school_name, ' ', '')) = ?
");

$stmt->bind_param("s", $domain);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $school = $result->fetch_assoc();
    
    // Construct the full path to the badge image
    $badge_path = !empty($school['badge']) ? 'uploads/' . $school['badge'] : 'assets/images/default_school_badge.png';
    
    echo json_encode([
        'success' => true,
        'school_name' => $school['school_name'],
        'school_badge' => $badge_path,
        'school_motto' => $school['motto']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'School not found'
    ]);
}

$stmt->close();
$conn->close();