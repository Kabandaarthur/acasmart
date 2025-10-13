<?php
session_start();

// Simple admin check (match existing project convention)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Database connection (lightweight copy of existing pattern)
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'sms';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// helper: render a simple error page
function displayError($message) {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body style="font-family:Arial,Helvetica,sans-serif;padding:24px;">';
    echo '<h2 style="color:#c0392b;">Error</h2>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="javascript:history.back()">Go back</a></p>';
    echo '</body></html>';
    exit();
}

// Parameters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$download = isset($_GET['download']) ? true : false;

// Path to sample PDF in assets
$samplePdfPath = __DIR__ . '/assets/Report-Sample-S1.pdf';
if (!file_exists($samplePdfPath)) {
    displayError('Sample template not found on server.');
}

// If download requested, send the file with a helpful filename (use student name if available)
if ($download) {
    $studentName = 'report_card_sample';
    if ($student_id) {
        $stmt = $conn->prepare("SELECT firstname, lastname FROM students WHERE id = ? AND school_id = ? LIMIT 1");
        if ($stmt) {
            $school_id = $_SESSION['school_id'] ?? 0;
            $stmt->bind_param('ii', $student_id, $school_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $studentName = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($row['firstname'] . '_' . $row['lastname']));
            }
            $stmt->close();
        }
    }

    // send headers and file
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $studentName . '.pdf"');
    header('Content-Length: ' . filesize($samplePdfPath));
    readfile($samplePdfPath);
    $conn->close();
    exit();
}

// Otherwise render a simple preview HTML page and download button
$studentDisplay = '';
if ($student_id) {
    $stmt = $conn->prepare("SELECT firstname, lastname, admission_number FROM students WHERE id = ? AND school_id = ? LIMIT 1");
    if ($stmt) {
        $school_id = $_SESSION['school_id'] ?? 0;
        $stmt->bind_param('ii', $student_id, $school_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $studentDisplay = htmlspecialchars($row['firstname'] . ' ' . $row['lastname']);
        }
        $stmt->close();
    }
}

// Basic preview page (embeds the sample PDF)
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Report Template Preview</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background:#f4f6f8; color:#222; margin:0; padding:18px; }
        .card { max-width:1000px; margin:20px auto; background:#fff; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.08); overflow:hidden; }
        .card-header { padding:16px 20px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center; }
        .card-body { padding:12px; }
        .pdf-preview { width:100%; height:820px; border:0; }
        .btn { display:inline-block; background:#2d6cdf; color:#fff; padding:10px 14px; border-radius:6px; text-decoration:none; }
        .meta { color:#666; font-size:14px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <div>
                <h3 style="margin:0;font-size:18px;">Sample Report Template Preview</h3>
                <div class="meta">This preview shows the static sample report stored in <code>assets/Report-Sample-S1.pdf</code></div>
            </div>
            <div style="text-align:right">
                <?php if ($studentDisplay): ?>
                    <div style="font-size:14px;margin-bottom:6px;">Student: <strong><?php echo $studentDisplay; ?></strong></div>
                <?php endif; ?>
                <a href="?student_id=<?php echo $student_id; ?>&class_id=<?php echo $class_id; ?>&download=1" class="btn">Download Sample PDF</a>
            </div>
        </div>
        <div class="card-body">
            <embed src="assets/Report-Sample-S1.pdf" type="application/pdf" class="pdf-preview">
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
exit();
?>
