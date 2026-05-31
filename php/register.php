<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $msg = "<div class='alert alert-danger'>Invalid request. Please try again.</div>";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if (!in_array($role, ['candidate', 'recruiter'], true)) {
            $msg = "<div class='alert alert-danger'>Invalid role selection.</div>";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "<div class='alert alert-danger'>Invalid email format.</div>";
        } else {
            $existing_user = db_fetch_one($conn, "SELECT id FROM users WHERE username = ?", 's', [$username]);
            if ($existing_user) {
                $msg = "<div class='alert alert-danger'>Username already taken.</div>";
            } else {
                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                $ok = db_execute($conn, "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)", 'ssss', [$username, $email, $hashed_pass, $role]);

                if ($ok) {
                    $last_id_row = db_fetch_one($conn, "SELECT LAST_INSERT_ID() as id");
                    $last_id = $last_id_row['id'] ?? null;
                    if ($role === 'candidate') {
                        db_execute($conn, "INSERT INTO candidates (user_id) VALUES (?)", 'i', [$last_id]);
                    } elseif ($role === 'recruiter') {
                        db_execute($conn, "INSERT INTO recruiters (user_id, company_name) VALUES (?, ?)", 'is', [$last_id, 'New Company']);
                    }
                    $msg = "<div class='alert alert-success'>Registration successful! <a href='login.php'>Login here</a></div>";
                } else {
                    $msg = "<div class='alert alert-danger'>Unable to register right now.</div>";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>PathFinder - Register</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="auth-page">
    <section class="auth-hero">
        <h1>Build your Path</h1>
        <p>Join as a candidate or recruiter and start shaping your next chapter with precision.</p>
        <div class="card" style="max-width: 420px;">
            <h3 style="margin-top: 0;">Why PathFinder</h3>
            <p class="muted" style="margin-bottom: 12px;">A calm, curated platform for intentional hiring.</p>
            <div style="display: grid; gap: 8px;">
                <span>Personalized role matching</span>
                <span>Interview planning at a glance</span>
                <span>Skills insights that stay current</span>
            </div>
        </div>
    </section>
    <section class="auth-shell">
        <div class="card auth-card">
            <h2>Create account</h2>
            <p class="muted">Join as a candidate or recruiter in minutes.</p>
            <?php if(isset($msg)) echo $msg; ?>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <label class="field">
                    <span>Username</span>
                    <input type="text" name="username" placeholder="Your name" required minlength="3">
                </label>
                <label class="field">
                    <span>Email Address</span>
                    <input type="email" name="email" placeholder="name@email.com" required>
                </label>
                <label class="field">
                    <span>Password</span>
                    <input type="password" name="password" placeholder="Create a password" required minlength="6">
                </label>
                <label class="field">
                    <span>Role</span>
                    <select name="role" required>
                        <option value="candidate">I am a Candidate</option>
                        <option value="recruiter">I am a Recruiter</option>
                    </select>
                </label>
                <button type="submit" class="btn btn-success btn-full">Create account</button>
            </form>
            <div class="auth-footer">
                Already have an account? <a href="login.php" class="link">Back to login</a>
            </div>
        </div>
    </section>
</body>
</html>

