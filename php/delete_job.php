<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? '')) {
    header('Location: admin_dashboard.php');
    exit();
}

$job_id = intval($_POST['id'] ?? 0);
if ($job_id > 0) {
    db_execute($conn, "DELETE FROM applications WHERE job_id = ?", 'i', [$job_id]);
    db_execute($conn, "DELETE FROM job_postings WHERE id = ?", 'i', [$job_id]);
}

header('Location: admin_dashboard.php?msg=Job posting successfully deleted.');
exit();

