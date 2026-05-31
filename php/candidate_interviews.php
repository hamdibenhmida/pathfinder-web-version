<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['candidate', 'admin']);

$user_id = current_user_id();
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $msg = "<div class='alert alert-danger'>Invalid request.</div>";
    } else {
        $application_id = intval($_POST['application_id'] ?? 0);
        $option_id = intval($_POST['option_id'] ?? 0);

        if ($application_id > 0 && $option_id > 0) {
            $option = db_fetch_one(
                $conn,
                "SELECT io.option_time, io.notes, io.recruiter_id FROM interview_options io JOIN applications a ON a.id = io.application_id WHERE io.id = ? AND a.id = ? AND a.candidate_id = ? AND a.status = 'accepted'",
                'iii',
                [$option_id, $application_id, $user_id]
            );

            if ($option) {
                db_execute(
                    $conn,
                    "INSERT INTO interviews (application_id, recruiter_id, scheduled_at, notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE scheduled_at = VALUES(scheduled_at), notes = VALUES(notes)",
                    'iiss',
                    [$application_id, $option['recruiter_id'], $option['option_time'], $option['notes']]
                );
                db_execute($conn, "UPDATE applications SET status = 'interview_scheduled' WHERE id = ?", 'i', [$application_id]);
                db_execute(
                    $conn,
                    "INSERT INTO notifications (user_id, message, unread) VALUES (?, ?, 1)",
                    'is',
                    [$user_id, 'You selected an interview time. Your recruiter will be notified.']
                );
                db_execute(
                    $conn,
                    "INSERT INTO notifications (user_id, message, unread) VALUES (?, ?, 1)",
                    'is',
                    [$option['recruiter_id'], 'A candidate selected an interview time for their application.']
                );
                $msg = "<div class='alert alert-success'>Interview time confirmed. Check your dashboard for details.</div>";
            } else {
                $msg = "<div class='alert alert-danger'>That interview slot is no longer available.</div>";
            }
        }
    }
}

$pending_rows = db_fetch_all(
    $conn,
    "SELECT a.id AS application_id, j.title AS job_title, j.id AS job_id, u.username AS recruiter_name, io.id AS option_id, io.option_time, io.notes
     FROM applications a
     JOIN job_postings j ON a.job_id = j.id
     JOIN users u ON j.recruiter_id = u.id
     JOIN interview_options io ON io.application_id = a.id
     WHERE a.candidate_id = ? AND a.status = 'accepted'
     ORDER BY a.apply_date DESC, io.option_time ASC",
    'i',
    [$user_id]
);

$confirmed_interviews = db_fetch_all(
    $conn,
    "SELECT j.title AS job_title, u.username AS recruiter_name, i.scheduled_at, i.notes
     FROM interviews i
     JOIN applications a ON i.application_id = a.id
     JOIN job_postings j ON a.job_id = j.id
     JOIN users u ON i.recruiter_id = u.id
     WHERE a.candidate_id = ?
     ORDER BY i.scheduled_at DESC",
    'i',
    [$user_id]
);

$pending = [];
foreach ($pending_rows as $row) {
    $appId = $row['application_id'];
    if (!isset($pending[$appId])) {
        $pending[$appId] = [
            'job_title' => $row['job_title'],
            'recruiter_name' => $row['recruiter_name'],
            'options' => []
        ];
    }
    $pending[$appId]['options'][] = [
        'option_id' => $row['option_id'],
        'option_time' => $row['option_time'],
        'notes' => $row['notes']
    ];
}

$sidebar_links = [
    ['href' => 'candidate_dashboard.php', 'label' => 'Dashboard'],
    ['href' => 'candidate_interviews.php', 'label' => 'Interviews', 'active' => true]
];
if (current_role() === 'admin') {
    $sidebar_links[] = ['href' => 'admin_dashboard.php', 'label' => 'Admin View'];
}
$sidebar_sections = [
    ['title' => 'Candidate', 'links' => $sidebar_links]
];
$topbar_actions = [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Interviews - PathFinder</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="app-shell">
        <?php render_sidebar('PathFinder', $sidebar_sections, [], $topbar_actions); ?>
        <div class="app-main">

            <div class="container">
                <?php if ($msg) echo $msg; ?>

                <div class="card card-accent" style="margin-bottom: 20px;">
                    <h3 style="margin-top: 0;">Interview progress</h3>
                    <p class="muted">If your recruiter accepted your application, choose one of the proposed interview times below to lock in your meeting.</p>
                </div>

                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2 class="section-title">Pending interview choices</h2>
                            <p class="section-subtitle">Select one proposed slot to confirm an interview with the recruiter.</p>
                        </div>
                    </div>

                    <?php if (empty($pending)): ?>
                        <div class="card empty-state">
                            <p>No interview times are waiting for your response right now.</p>
                            <p class="muted">If you were accepted, please check your notifications or return to your dashboard for the latest update.</p>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;">
                                <a href="candidate_dashboard.php?show_notifications=1" class="btn btn-ghost">View notifications</a>
                                <a href="candidate_dashboard.php" class="btn">Back to dashboard</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending as $application_id => $entry): ?>
                            <div class="card">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 12px;">
                                    <div>
                                        <h3><?php echo htmlspecialchars($entry['job_title']); ?></h3>
                                        <p class="muted">Recruiter: <?php echo htmlspecialchars($entry['recruiter_name']); ?></p>
                                    </div>
                                    <span class="badge badge-blue">Select one</span>
                                </div>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="application_id" value="<?php echo htmlspecialchars((string)$application_id); ?>">
                                    <div class="radio-group" style="display: grid; gap: 12px; margin-bottom: 18px;">
                                        <?php foreach ($entry['options'] as $option): ?>
                                            <label class="card-option" style="display: block; padding: 16px; border: 1px solid #e1e5eb; border-radius: 12px; background: #fff; cursor: pointer;">
                                                <input type="radio" name="option_id" value="<?php echo htmlspecialchars((string)$option['option_id']); ?>" required style="margin-right: 12px; vertical-align: middle;">
                                                <span style="vertical-align: middle;"><strong><?php echo htmlspecialchars($option['option_time']); ?></strong>
                                                <?php if (!empty($option['notes'])): ?>
                                                    <div class="muted" style="margin-top: 8px; padding-left: 26px;"><?php echo htmlspecialchars($option['notes']); ?></div>
                                                <?php endif; ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" class="btn btn-success">Confirm selected time</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2 class="section-title">Confirmed interviews</h2>
                            <p class="section-subtitle">Your scheduled interviews will appear here.</p>
                        </div>
                    </div>

                    <?php if (empty($confirmed_interviews)): ?>
                        <div class="card empty-state">
                            <p>No confirmed interviews yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <tr>
                                    <th>Job</th>
                                    <th>Recruiter</th>
                                    <th>Scheduled</th>
                                    <th>Notes</th>
                                </tr>
                                <?php foreach ($confirmed_interviews as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                        <td><?php echo htmlspecialchars($row['recruiter_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['scheduled_at']); ?></td>
                                        <td><?php echo htmlspecialchars($row['notes']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
