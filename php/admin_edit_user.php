<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['admin']);

$user_id = isset($_GET['id']) ? intval($_GET['id']) : null;
if (!$user_id) {
    header("Location: admin_dashboard.php?msg=Missing user ID");
    exit();
}

$user_data = db_fetch_one($conn, "SELECT id, username, email, role FROM users WHERE id = ?", 'i', [$user_id]);

if (!$user_data) {
    $msg = "User not found.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $msg = "<div class='alert alert-danger'>Invalid request.</div>";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "<div class='alert alert-danger'>Invalid email format.</div>";
        } else {
            $check_user = db_fetch_one($conn, "SELECT id FROM users WHERE username = ? AND id != ?", 'si', [$username, $user_id]);
            $check_email = db_fetch_one($conn, "SELECT id FROM users WHERE email = ? AND id != ?", 'si', [$email, $user_id]);

            if ($check_user) {
                $msg = "<div class='alert alert-danger'>Username already taken.</div>";
            } elseif ($check_email) {
                $msg = "<div class='alert alert-danger'>Email already in use.</div>";
            } else {
                if (!empty($password)) {
                    $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                    db_execute($conn, "UPDATE users SET username = ?, email = ?, role = ?, password = ? WHERE id = ?", 'ssssi', [$username, $email, $role, $hashed_pass, $user_id]);
                } else {
                    db_execute($conn, "UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?", 'sssi', [$username, $email, $role, $user_id]);
                }

                if ($role === 'candidate') {
                    db_execute($conn, "INSERT IGNORE INTO candidates (user_id) VALUES (?)", 'i', [$user_id]);
                } elseif ($role === 'recruiter') {
                    db_execute($conn, "INSERT IGNORE INTO recruiters (user_id, company_name) VALUES (?, ?)", 'is', [$user_id, 'New Company']);
                }

                header("Location: admin_dashboard.php?msg=User updated successfully");
                exit();
            }
        }
    }

    $user_data = ['id' => $user_id, 'username' => $username, 'email' => $email, 'role' => $role];
}
$sidebar_links = [
    ['href' => 'admin_dashboard.php', 'label' => 'Dashboard', 'active' => true]
];
$sidebar_sections = [
    ['title' => 'Admin', 'links' => $sidebar_links]
];
$topbar_actions = [
    ['type' => 'button', 'href' => 'admin_dashboard.php', 'label' => 'Back to dashboard', 'class' => 'btn btn-ghost']
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit User - PathFinder</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="app-shell">
        <?php render_sidebar('PathFinder', $sidebar_sections, [], $topbar_actions); ?>
        <div class="app-main">

            <div class="container" style="max-width: 640px;">
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2 class="section-title">User details</h2>
                            <p class="section-subtitle">Update account credentials and access level.</p>
                        </div>
                    </div>
                    <div class="card card-accent">
                        <h2 style="margin-top: 0;">Edit User</h2>
                        <p class="muted">Update account details and role assignment.</p>

                        <?php if (isset($msg)) echo $msg; ?>

                        <?php if ($user_data): ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <label class="field">
                                    <span>Username</span>
                                    <input type="text" name="username" required minlength="3" value="<?php echo htmlspecialchars($user_data['username']); ?>">
                                </label>
                                <label class="field">
                                    <span>Email Address</span>
                                    <input type="email" name="email" required value="<?php echo htmlspecialchars($user_data['email']); ?>">
                                </label>
                                <label class="field">
                                    <span>Role</span>
                                    <select name="role" required>
                                        <option value="candidate" <?php echo ($user_data['role'] == 'candidate') ? 'selected' : ''; ?>>Candidate</option>
                                        <option value="recruiter" <?php echo ($user_data['role'] == 'recruiter') ? 'selected' : ''; ?>>Recruiter</option>
                                        <option value="admin" <?php echo ($user_data['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Reset Password (optional)</span>
                                    <input type="password" name="password" placeholder="Leave blank to keep current password">
                                </label>
                                <div style="display: flex; gap: 12px;">
                                    <button type="submit" class="btn">Save Changes</button>
                                    <a href="admin_dashboard.php" class="btn btn-ghost">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

