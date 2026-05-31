<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['admin', 'recruiter']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? '')) {
    header('Location: login.php');
    exit();
}

$job_id = intval($_POST['id'] ?? 0);
if ($job_id > 0) {
    db_execute($conn, "UPDATE job_postings SET status = 'closed' WHERE id = ?", 'i', [$job_id]);
}

if (current_role() === 'admin') {
    header('Location: admin_dashboard.php?msg=Job posting closed');
} else {
    header('Location: recruiter_dashboard.php?msg=Job posting closed');
}
exit();

