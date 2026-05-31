<?php
// Include bootstrap to initialize session and load dependencies
require_once __DIR__ . '/includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PathFinder - Login</title>
    <!-- Link to main stylesheet -->
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="auth-page">
    <!-- Hero section with branding and features -->
    <section class="auth-hero">
        <h1>PathFinder</h1>
        <p>Curate your next move with focused job matching, skills tracking, and recruiter insights.</p>
        <div class="card" style="max-width: 420px;">
            <h3 style="margin-top: 0;">What you can do</h3>
            <p class="muted" style="margin-bottom: 12px;">Apply with confidence and keep everything in one place.</p>
            <div style="display: grid; gap: 8px;">
                <span>AI match signals for each role</span>
                <span>Interview scheduling + notifications</span>
                <span>Instant resume analysis</span>
            </div>
        </div>
    </section>
    <!-- Login form section -->
    <section class="auth-shell">
        <div class="card auth-card">
            <h2>Sign in</h2>
            <p class="muted">Welcome back to your dashboard.</p>
            <!-- Login form with CSRF protection -->
            <form action="login_process.php" method="POST">
                <?php echo csrf_field(); ?>
                <label class="field">
                    <span>Email Address</span>
                    <input type="email" name="email" placeholder="name@email.com" required>
                </label>
                <label class="field">
                    <span>Password</span>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </label>
                <button type="submit" class="btn btn-full">Sign in</button>
            </form>
            <!-- Link to registration page -->
            <div class="auth-footer">
                New to PathFinder? <a href="register.php" class="link">Create an account</a>
            </div>
        </div>
    </section>
</body>
</html>

