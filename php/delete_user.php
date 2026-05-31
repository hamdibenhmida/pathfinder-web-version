<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? '')) {
    header('Location: admin_dashboard.php');
    exit();
}

$id = intval($_POST['id'] ?? 0);
if ($id > 0) {
    db_execute($conn, "DELETE FROM users WHERE id = ?", 'i', [$id]);
}

header('Location: admin_dashboard.php?msg=User deleted successfully');
exit();

