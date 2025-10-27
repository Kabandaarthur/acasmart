<?php
session_start();

// Protect route: only logged-in teachers
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
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

$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['school_id'];

// Teacher and school info
$query_teacher = "SELECT CONCAT(u.firstname, ' ', u.lastname) AS teacher_name, u.username, s.school_name 
                  FROM users u JOIN schools s ON u.school_id = s.id WHERE u.user_id = ?";
$stmt_teacher = $conn->prepare($query_teacher);
$stmt_teacher->bind_param("i", $teacher_id);
$stmt_teacher->execute();
$result_teacher = $stmt_teacher->get_result();
$teacher_info = $result_teacher->fetch_assoc();
$teacher_name = $teacher_info ? $teacher_info['teacher_name'] : 'Teacher';
$teacher_username = $teacher_info ? ($teacher_info['username'] ?? '') : '';
$school_name = $teacher_info ? $teacher_info['school_name'] : '';

// Assignments
$assignments_query = "SELECT c.name AS class_name, s.subject_name
                      FROM teacher_subjects ts
                      JOIN classes c ON ts.class_id = c.id
                      JOIN subjects s ON ts.subject_id = s.subject_id
                      WHERE ts.user_id = ? AND c.school_id = ?
                      ORDER BY c.name, s.subject_name";
$stmt_assignments = $conn->prepare($assignments_query);
$stmt_assignments->bind_param("ii", $teacher_id, $school_id);
$stmt_assignments->execute();
$assignments_result = $stmt_assignments->get_result();
$teacher_assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);

$assignments_by_class = [];
foreach ($teacher_assignments as $ta) {
    $assignments_by_class[$ta['class_name']][] = $ta['subject_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen">
    <header class="bg-gradient-to-r from-slate-800 to-blue-700 text-white shadow">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-white/15 flex items-center justify-center shadow-inner">
                    <i class="fas fa-user text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight">Teacher Profile</h1>
                    <p class="text-white/80 text-sm">Account overview and your assignments</p>
                </div>
            </div>
            <a href="teacher_dashboard.php" class="inline-flex items-center gap-2 px-4 h-11 rounded-lg border border-white/30 hover:border-white/60 transition text-white">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm hover:shadow-md transition">
                <div class="flex items-center gap-2 text-slate-500 mb-1 text-sm">
                    <i class="fas fa-id-card"></i>
                    <span class="uppercase tracking-wide">Name</span>
                </div>
                <div class="font-semibold text-slate-900 text-lg"><?php echo htmlspecialchars($teacher_name); ?></div>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm hover:shadow-md transition">
                <div class="flex items-center gap-2 text-slate-500 mb-1 text-sm">
                    <i class="fas fa-school"></i>
                    <span class="uppercase tracking-wide">School</span>
                </div>
                <div class="font-semibold text-slate-900 text-lg"><?php echo htmlspecialchars($school_name); ?></div>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm hover:shadow-md transition">
                <div class="flex items-center gap-2 text-slate-500 mb-1 text-sm">
                    <i class="fas fa-user-shield"></i>
                    <span class="uppercase tracking-wide">Role</span>
                </div>
                <div class="font-semibold text-slate-900 text-lg"><?php echo htmlspecialchars($_SESSION['role']); ?></div>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-5 shadow-sm hover:shadow-md transition">
                <div class="flex items-center gap-2 text-slate-500 mb-1 text-sm">
                    <i class="fas fa-user"></i>
                    <span class="uppercase tracking-wide">Username</span>
                </div>
                <div class="font-semibold text-slate-900 text-lg"><?php echo htmlspecialchars($teacher_username); ?></div>
            </div>
        </div>

        <section class="bg-white rounded-xl border border-slate-100 shadow-sm">
            <div class="px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-list text-blue-600"></i>
                    <h2 class="text-lg font-semibold text-slate-900">Assigned Classes & Subjects</h2>
                </div>
                <span class="text-xs px-2 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-100">
                    <?php echo count($assignments_by_class); ?> classes
                </span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!empty($assignments_by_class)) { ?>
                    <?php foreach ($assignments_by_class as $className => $subjectsList): ?>
                        <details class="group">
                            <summary class="flex items-center justify-between px-5 py-3 bg-gradient-to-r from-blue-50 to-indigo-50 cursor-pointer">
                                <span class="flex items-center gap-3">
                                    <span class="inline-flex items-center justify-center w-9 h-9 rounded-md bg-white text-blue-600 shadow-sm">
                                        <i class="fas fa-chalkboard"></i>
                                    </span>
                                    <span class="font-medium text-slate-900"><?php echo htmlspecialchars($className); ?></span>
                                </span>
                                <span class="flex items-center gap-3">
                                    <span class="text-xs bg-white/80 text-indigo-700 px-2 py-1 rounded-full border border-indigo-200"><?php echo count($subjectsList); ?> subjects</span>
                                    <i class="fas fa-chevron-down text-indigo-700 transition-transform group-open:rotate-180"></i>
                                </span>
                            </summary>
                            <div class="px-10 py-4 bg-white">
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($subjectsList as $subject): ?>
                                        <span class="px-2 py-1 rounded-full text-sm bg-indigo-50 text-indigo-700 border border-indigo-100"><?php echo htmlspecialchars($subject); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php } else { ?>
                    <div class="p-6 text-slate-500">No class or subject assignments found.</div>
                <?php } ?>
            </div>
        </section>

        <section class="mt-8 grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-lock text-blue-600"></i>
                        <h3 class="text-lg font-semibold text-slate-900">Security</h3>
                    </div>
                    <button id="togglePasswordForm" class="inline-flex items-center gap-2 px-4 h-11 rounded-lg border border-slate-300 hover:bg-slate-100 text-slate-700">
                        <i class="fas fa-key"></i>
                        <span>Change Password</span>
                    </button>
                </div>
                <div id="passwordFormContainer" class="hidden">
                    <form method="post" action="update_teacher_password.php" class="space-y-4">
                        <div>
                            <label class="block text-sm text-slate-600 mb-1">Current Password</label>
                            <input type="password" name="current_password" required class="w-full h-11 px-3 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm text-slate-600 mb-1">New Password</label>
                                <input type="password" name="new_password" required minlength="6" class="w-full h-11 px-3 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="block text-sm text-slate-600 mb-1">Confirm New Password</label>
                                <input type="password" name="confirm_password" required minlength="6" class="w-full h-11 px-3 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                            </div>
                        </div>
                        <button type="submit" class="inline-flex items-center gap-2 px-5 h-11 rounded-lg bg-blue-600 text-white hover:bg-blue-700">
                            <i class="fas fa-save"></i>
                            <span>Save Password</span>
                        </button>
                    </form>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6">
                <div class="flex items-center gap-2 mb-4">
                    <i class="fas fa-info-circle text-blue-600"></i>
                    <h3 class="text-lg font-semibold text-slate-900">Account Tips</h3>
                </div>
                <ul class="list-disc pl-5 text-slate-700 space-y-2">
                    <li>Use a strong password with at least 6 characters.</li>
                    <li>Don’t share your credentials with anyone.</li>
                    <li>Update your password regularly to keep your account secure.</li>
                </ul>
            </div>
        </section>

        

        <!-- Chat moved to chat.php -->
        <script>
        (function(){
            var btn = document.getElementById('togglePasswordForm');
            var container = document.getElementById('passwordFormContainer');
            if (btn && container) {
                btn.addEventListener('click', function(){
                    if (container.classList.contains('hidden')) {
                        container.classList.remove('hidden');
                        btn.innerHTML = '<i class="fas fa-times"></i><span>Close</span>';
                    } else {
                        container.classList.add('hidden');
                        btn.innerHTML = '<i class="fas fa-key"></i><span>Change Password</span>';
                    }
                });
            }
        })();
        </script>
    </main>
</body>
</html>
