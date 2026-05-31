<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['candidate', 'admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? '')) {
    header('Location: candidate_dashboard.php?show_find_jobs=1&apply_error=submit');
    exit();
}

$user_id = current_user_id();
$job_id = intval($_POST['job_id'] ?? 0);
$score = intval($_POST['score'] ?? 0);

// 1. Mandatory Check: Profile Completeness (Constraint from UML)
$check = db_fetch_one($conn, "SELECT completeness FROM candidates WHERE user_id = ?", 'i', [$user_id]);

if (!$check || $check['completeness'] < 80) {
    header('Location: candidate_dashboard.php?show_find_jobs=1&apply_error=profile');
    exit();
}

// 2. Create the Application (The "Order")
$ok = db_execute($conn, "INSERT INTO applications (job_id, candidate_id, match_score, status) VALUES (?, ?, ?, 'pending')", 'iii', [$job_id, $user_id, $score]);
if ($ok) {
    // Notify the recruiter about the new application
    $job_row = db_fetch_one($conn, "SELECT recruiter_id, title FROM job_postings WHERE id = ?", 'i', [$job_id]);
    if ($job_row) {
        $candidate_row = db_fetch_one($conn, "SELECT username FROM users WHERE id = ?", 'i', [$user_id]);
        if ($candidate_row) {
            $notify_message = 'New application received for "' . $job_row['title'] . '" from ' . $candidate_row['username'] . ' (Match: ' . $score . '%)';
            db_execute($conn, "INSERT INTO notifications (user_id, message, unread) VALUES (?, ?, 1)", 'is', [$job_row['recruiter_id'], $notify_message]);
        }
    }
    header('Location: candidate_dashboard.php?show_find_jobs=1&applied=1&score=' . $score);
    exit();
}

header('Location: candidate_dashboard.php?show_find_jobs=1&apply_error=submit');
exit();

