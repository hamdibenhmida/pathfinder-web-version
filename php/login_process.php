<?php
// Include bootstrap for database and session setup
require_once __DIR__ . '/includes/bootstrap.php';

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token for security
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        echo "Invalid request. <a href='login.php'>Try again</a>";
        exit();
    }

    // Get and sanitize form inputs
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Fetch user from database by email
    $user = db_fetch_one($conn, "SELECT * FROM users WHERE email = ?", 's', [$email]);

    if ($user) {
        // Verify password against stored hash
        if (password_verify($password, $user['password'])) {
            // Set session variables for authenticated user
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];

            // Redirect to appropriate dashboard based on role
            if ($user['role'] === 'admin') {
                header('Location: admin_dashboard.php');
            } elseif ($user['role'] === 'candidate') {
                header('Location: candidate_dashboard.php');
            } else {
                header('Location: recruiter_dashboard.php');
            }
            exit();
        }
    }

    // Invalid credentials - show error and retry link
    echo "Invalid credentials. <a href='login.php'>Try again</a>";
}
?>

