<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['recruiter', 'admin']);

$job_id = intval($_GET['job_id'] ?? 0);
$recruiter_id = current_user_id();
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $msg = "<div class='alert alert-danger'>Invalid request.</div>";
    } else {
        $action = $_POST['action'] ?? '';
        $application_id = intval($_POST['application_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if ($application_id > 0 && in_array($action, ['accept', 'reject'], true)) {
            $app_row = db_fetch_one($conn, "SELECT candidate_id FROM applications WHERE id = ?", 'i', [$application_id]);
            if ($app_row) {
                if ($action === 'accept') {
                    $slots = [];
                    for ($i = 1; $i <= 3; $i++) {
                        $slot = trim($_POST['slot_time_' . $i] ?? '');
                        if ($slot !== '') {
                            $slots[] = $slot;
                        }
                    }

                    if (empty($slots)) {
                        $msg = "<div class='alert alert-danger'>Please propose at least one interview time.</div>";
                    } else {
                        db_execute($conn, "UPDATE applications SET status = 'accepted' WHERE id = ?", 'i', [$application_id]);
                        db_execute($conn, "DELETE FROM interview_options WHERE application_id = ?", 'i', [$application_id]);
                        foreach ($slots as $slot) {
                            db_execute($conn, "INSERT INTO interview_options (application_id, recruiter_id, option_time, notes) VALUES (?, ?, ?, ?)", 'iiss', [$application_id, $recruiter_id, $slot, $message]);
                        }

                        $notify = 'Your application has been accepted.';
                        if ($message !== '') {
                            $notify .= ' Message: ' . $message;
                        }
                        $notify .= ' Please choose one of the proposed interview times.';
                        db_execute($conn, "INSERT INTO notifications (user_id, message, unread) VALUES (?, ?, 1)", 'is', [$app_row['candidate_id'], $notify]);
                        $msg = "<div class='alert alert-success'>Candidate accepted and interview slots proposed.</div>";
                    }
                }

                if ($action === 'reject') {
                    db_execute($conn, "UPDATE applications SET status = 'rejected' WHERE id = ?", 'i', [$application_id]);
                    db_execute($conn, "DELETE FROM interview_options WHERE application_id = ?", 'i', [$application_id]);

                    $notify = 'Your application has been rejected.';
                    if ($message !== '') {
                        $notify .= ' Reason: ' . $message;
                    }
                    db_execute($conn, "INSERT INTO notifications (user_id, message, unread) VALUES (?, ?, 1)", 'is', [$app_row['candidate_id'], $notify]);
                    $msg = "<div class='alert alert-success'>Candidate rejected.</div>";
                }
            }
        }
    }
}

$apps = db_fetch_all(
    $conn,
    "SELECT a.id as application_id, a.match_score, a.status, a.apply_date, u.username, u.email, c.skills, c.resume_url, 
        (SELECT COUNT(*) FROM interview_options io WHERE io.application_id = a.id) AS slots_count,
        (SELECT i.scheduled_at FROM interviews i WHERE i.application_id = a.id LIMIT 1) AS scheduled_at
     FROM applications a
     JOIN users u ON a.candidate_id = u.id
     JOIN candidates c ON u.id = c.user_id
     WHERE a.job_id = ?",
    'i',
    [$job_id]
);
$role = current_role();
$sidebar_links = [
    ['href' => 'recruiter_dashboard.php', 'label' => 'Dashboard']
];
if ($role === 'admin') {
    $sidebar_links[] = ['href' => 'admin_dashboard.php', 'label' => 'Admin View'];
}
$sidebar_sections = [
    ['title' => 'Recruiter', 'links' => $sidebar_links]
];
$topbar_actions = [
    ['type' => 'button', 'href' => 'recruiter_dashboard.php', 'label' => 'Back to dashboard', 'class' => 'btn btn-ghost']
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Applications - PathFinder</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="app-shell">
        <?php render_sidebar('PathFinder', $sidebar_sections, [], $topbar_actions); ?>
        <div class="app-main">

            <div class="container">
                <?php if ($msg) echo $msg; ?>
                <div class="card table-card">
                    <div class="table-header">
                        <div>
                            <h2 class="table-title">Applications for Job #<?php echo htmlspecialchars($job_id); ?></h2>
                            <p class="table-meta">Shortlist candidates and schedule interviews.</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <tr>
                                <th>Candidate Name</th>
                                <th>Email</th>
                                <th>Analyzed Skills</th>
                                <th>Match</th>
                                <th>Status</th>
                                <th>Resume</th>
                                <th>Actions</th>
                            </tr>
                            <?php foreach ($apps as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['skills']); ?></td>
                                <td><span class="badge badge-orange"><?php echo intval($row['match_score']); ?>%</span></td>
                                <td>
                                    <?php if ($row['status'] === 'interview_scheduled'): ?>
                                        <span class="badge badge-blue">Interview confirmed</span>
                                    <?php elseif ($row['status'] === 'accepted'): ?>
                                        <span class="badge badge-blue">Accepted</span>
                                    <?php elseif ($row['status'] === 'rejected'): ?>
                                        <span class="badge badge-red">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge badge-orange">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><a href="<?php echo htmlspecialchars($row['resume_url']); ?>" target="_blank" class="btn btn-sm">Download CV</a></td>
                                <td>
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <div style="display: grid; gap: 8px;">
                                            <form method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="accept">
                                                <input type="hidden" name="application_id" value="<?php echo htmlspecialchars((string)$row['application_id']); ?>">
                                                <input type="text" name="message" placeholder="Optional message" style="margin-bottom: 6px;">
                                                <input type="datetime-local" name="slot_time_1" style="margin-bottom: 6px;">
                                                <input type="datetime-local" name="slot_time_2" style="margin-bottom: 6px;">
                                                <input type="datetime-local" name="slot_time_3" style="margin-bottom: 6px;">
                                                <button type="submit" class="btn btn-sm btn-success">Accept & propose slots</button>
                                            </form>
                                            <form method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="application_id" value="<?php echo htmlspecialchars((string)$row['application_id']); ?>">
                                                <input type="text" name="message" placeholder="Reject reason" style="margin-bottom: 6px;">
                                                <button type="submit" class="btn btn-sm btn-danger">Reject candidate</button>
                                            </form>
                                        </div>
                                    <?php elseif ($row['status'] === 'accepted'): ?>
                                        <div>
                                            <div>Slots proposed: <?php echo intval($row['slots_count']); ?></div>
                                            <?php if (!empty($row['scheduled_at'])): ?>
                                                <div>Confirmed: <?php echo htmlspecialchars($row['scheduled_at']); ?></div>
                                            <?php else: ?>
                                                <div>Waiting for candidate selection</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($row['status'] === 'interview_scheduled'): ?>
                                        <div>Confirmed: <?php echo htmlspecialchars($row['scheduled_at']); ?></div>
                                    <?php else: ?>
                                        <div>Candidate declined or pending.</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

