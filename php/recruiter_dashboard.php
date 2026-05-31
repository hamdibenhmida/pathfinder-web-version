<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['recruiter', 'admin']);

$recruiter_id = current_user_id();
$post_job_error = null;
$show_post_job_modal = isset($_GET['open_post_job']);
$show_notifications_modal = isset($_GET['show_notifications']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_job_submit'])) {
    $show_post_job_modal = true;
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $post_job_error = 'Invalid request. Please try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');

        if ($title === '' || $description === '' || $requirements === '') {
            $post_job_error = 'All fields are required.';
        } else {
            $ok = db_execute(
                $conn,
                "INSERT INTO job_postings (recruiter_id, title, description, requirements, status) VALUES (?, ?, ?, ?, 'open')",
                'isss',
                [$recruiter_id, $title, $description, $requirements]
            );

            if ($ok) {
                header('Location: recruiter_dashboard.php?msg=Job Posted Successfully');
                exit();
            }
            $post_job_error = 'Unable to post this job right now.';
        }
    }
}

// Get all jobs and count how many people applied to each
$sql = "SELECT j.*, (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as total_apps 
    FROM job_postings j 
    WHERE j.recruiter_id = ? 
    ORDER BY j.id DESC";
$my_jobs = db_fetch_all($conn, $sql, 'i', [$recruiter_id]);
$stat_jobs_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM job_postings WHERE recruiter_id = ?", 'i', [$recruiter_id]);
$stat_open_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM job_postings WHERE recruiter_id = ? AND status = 'open'", 'i', [$recruiter_id]);
$stat_apps_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM applications a JOIN job_postings j ON a.job_id = j.id WHERE j.recruiter_id = ?", 'i', [$recruiter_id]);
$stat_jobs = $stat_jobs_row['count'] ?? 0;
$stat_open = $stat_open_row['count'] ?? 0;
$stat_apps = $stat_apps_row['count'] ?? 0;
$interviews = db_fetch_all(
    $conn,
    "SELECT i.scheduled_at, i.notes, a.match_score, u.username, u.email, j.title FROM interviews i JOIN applications a ON i.application_id = a.id JOIN users u ON a.candidate_id = u.id JOIN job_postings j ON a.job_id = j.id WHERE i.recruiter_id = ? ORDER BY i.scheduled_at DESC",
    'i',
    [$recruiter_id]
);
$notifications = db_fetch_all(
    $conn,
    "SELECT message, created_at FROM notifications WHERE user_id = ? AND unread = 1 ORDER BY created_at DESC",
    'i',
    [$recruiter_id]
);
$sidebar_links = [
    ['href' => 'recruiter_dashboard.php', 'label' => 'Dashboard', 'active' => true]
];
if (current_role() === 'admin') {
    $sidebar_links[] = ['href' => 'admin_dashboard.php', 'label' => 'Admin View'];
}
$sidebar_sections = [
    ['title' => 'Recruiter', 'links' => $sidebar_links]
];
$topbar_actions = [
    ['type' => 'icon', 'icon' => 'bell', 'href' => 'recruiter_dashboard.php?show_notifications=1', 'badge' => count($notifications)],
    ['type' => 'button', 'label' => 'Post new role', 'href' => 'recruiter_dashboard.php?open_post_job=1', 'class' => 'btn']
];

$show_notifications_modal = isset($_GET['show_notifications']);

// Mark notifications as read when modal is opened
if ($show_notifications_modal && !empty($notifications)) {
    db_execute($conn, "UPDATE notifications SET unread = 0 WHERE user_id = ? AND unread = 1", 'i', [$recruiter_id]);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Recruiter Dashboard - PathFinder</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="app-shell">
        <?php render_sidebar('PathFinder', $sidebar_sections, [], $topbar_actions); ?>
        <div class="app-main">
            <div class="container">

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($_GET['msg']); ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Overview</h2>
                    <p class="section-subtitle">Track hiring velocity and pipeline health.</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Job Posts</div>
                    <div class="stat-number"><?php echo intval($stat_jobs); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Open Roles</div>
                    <div class="stat-number"><?php echo intval($stat_open); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Applications</div>
                    <div class="stat-number"><?php echo intval($stat_apps); ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Active listings</h2>
                    <p class="section-subtitle">Keep candidate review moving quickly.</p>
                </div>
            </div>
            <div class="dashboard-grid">
                <div class="dashboard-stack">
                    <?php if (!empty($my_jobs)): ?>
                        <?php foreach ($my_jobs as $job): ?>
                            <div class="card card-accent">
                                <div class="list-card">
                                    <div>
                                        <h3 class="list-title">
                                            <?php echo htmlspecialchars($job['title']); ?>
                                            <?php if($job['total_apps'] > 0): ?>
                                                <span class="badge badge-orange" style="margin-left: 10px;"><?php echo $job['total_apps']; ?> New Apps</span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="list-meta">Status: <strong><?php echo strtoupper($job['status']); ?></strong></p>
                                    </div>
                                    <div class="list-actions">
                                        <a href="view_job_applications.php?job_id=<?php echo $job['id']; ?>" class="btn">Review Candidates</a>
                                        <?php if($job['status'] == 'open'): ?>
                                            <form method="POST" action="close_job.php">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$job['id']); ?>">
                                                <button type="submit" class="btn btn-danger" onclick="return confirm('Stop accepting applications for this job?')">Close Post</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="card empty-state">
                            <p>You haven't posted any jobs yet.</p>
                            <a href="recruiter_dashboard.php?open_post_job=1" class="btn" style="margin-top: 12px;">Create your first job posting</a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="dashboard-stack">
                    <div class="card">
                        <h3 style="margin-top: 0;">Pipeline Snapshot</h3>
                        <p class="muted" style="margin-bottom: 12px;">Roles live: <strong><?php echo intval($stat_open); ?></strong></p>
                        <p class="muted" style="margin-bottom: 12px;">Applications received: <strong><?php echo intval($stat_apps); ?></strong></p>
                        <p class="muted" style="margin-bottom: 0;">Jobs closed: <strong><?php echo intval($stat_jobs) - intval($stat_open); ?></strong></p>
                    </div>

                    <div class="card">
                        <h3 style="margin-top: 0;">Quick links</h3>
                        <div style="display: grid; gap: 10px;">
                            <a href="recruiter_dashboard.php?open_post_job=1" class="topbar-link">Post a new role</a>
                            <a href="#scheduled-interviews" class="topbar-link">Review scheduled interviews</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section" id="scheduled-interviews">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Scheduled interviews</h2>
                    <p class="section-subtitle">Manage upcoming interviews and candidate details.</p>
                </div>
            </div>
            <div class="card table-card">
                <?php if (empty($interviews)): ?>
                    <div class="empty-state">
                        <p>No interviews scheduled yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <tr>
                                <th>Candidate</th>
                                <th>Email</th>
                                <th>Job</th>
                                <th>Match</th>
                                <th>Scheduled</th>
                                <th>Notes</th>
                            </tr>
                            <?php foreach ($interviews as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                <td><span class="badge badge-orange"><?php echo intval($row['match_score']); ?>%</span></td>
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
    </div>

    <div class="overlay" id="postJobModal" style="<?php echo $show_post_job_modal ? 'display: flex;' : 'display: none;'; ?>">
        <div class="overlay-card" style="max-width: 760px;" onclick="event.stopPropagation();">
            <h2 style="margin-top: 0;">Post a new opportunity</h2>
            <p class="muted">Create a listing that attracts the right candidates.</p>

            <?php if ($post_job_error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($post_job_error); ?></div>
            <?php endif; ?>

            <form method="POST" action="recruiter_dashboard.php?open_post_job=1">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="post_job_submit" value="1">
                <label class="field">
                    <span>Job Title</span>
                    <input type="text" name="title" placeholder="e.g. PHP Developer" required>
                </label>
                <label class="field">
                    <span>Job Description</span>
                    <textarea name="description" placeholder="Describe the role, responsibilities, and impact." rows="6" required></textarea>
                </label>
                <label class="field">
                    <span>Key Skills</span>
                    <textarea name="requirements" placeholder="Comma separated skills (auto-filled from description)" rows="3" required></textarea>
                </label>
                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn">Publish job posting</button>
                    <a href="recruiter_dashboard.php" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            var modal = document.getElementById('postJobModal');
            if (!modal) {
                return;
            }
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    window.location.href = 'recruiter_dashboard.php';
                }
            });

            // Auto-fill skills from description
            function detectSkillsFromText(text) {
                const keywords = [
                    'php', 'mysql', 'sql', 'javascript', 'html', 'css', 'laravel', 'symfony', 'react', 'vue', 'node',
                    'express', 'git', 'docker', 'aws', 'python', 'java', 'c#', 'c++', 'devops', 'linux', 'project management',
                    'agile', 'communication', 'team leadership', 'data analysis', 'machine learning', 'wordpress', 'bootstrap',
                    'typescript', 'angular', 'mongodb', 'postgresql', 'redis', 'kubernetes', 'jenkins', 'ci/cd', 'rest api',
                    'graphql', 'microservices', 'scrum', 'kanban', 'testing', 'unit testing', 'integration testing',
                    'selenium', 'jira', 'confluence', 'slack', 'zoom', 'microsoft office', 'excel', 'powerpoint'
                ];
                const normalized = text.toLowerCase();
                const found = [];
                keywords.forEach(keyword => {
                    if (normalized.includes(keyword.toLowerCase())) {
                        found.push(keyword.charAt(0).toUpperCase() + keyword.slice(1));
                    }
                });
                return [...new Set(found)].join(', ');
            }

            const descriptionTextarea = modal.querySelector('textarea[name="description"]');
            const requirementsTextarea = modal.querySelector('textarea[name="requirements"]');

            if (descriptionTextarea && requirementsTextarea) {
                descriptionTextarea.addEventListener('input', function() {
                    const descriptionText = this.value;
                    if (descriptionText.trim()) {
                        const detectedSkills = detectSkillsFromText(descriptionText);
                        if (detectedSkills && !requirementsTextarea.value.trim()) {
                            requirementsTextarea.value = detectedSkills;
                        }
                    }
                });
            }
        })();
    </script>

    <div class="overlay" id="notificationsModal" style="display: <?php echo $show_notifications_modal ? 'flex' : 'none'; ?>; flex-direction: column;">
        <div class="overlay-card" style="max-width: 800px; max-height: 90vh; overflow-y: auto; width: 100%;" role="dialog">
            <div style="padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h2 style="margin: 0 0 4px 0;">Notifications</h2>
                        <p class="muted" style="margin: 0;">Updates about interviews and candidate activity.</p>
                    </div>
                    <button type="button" class="btn btn-ghost" id="closeNotificationsModal" style="border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; padding: 0;">✕</button>
                </div>

                <div class="card table-card">
                    <div class="table-header">
                        <div>
                            <h3 class="table-title">Notifications</h3>
                            <p class="table-meta">Latest alerts sent to your recruiter account.</p>
                        </div>
                    </div>
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <p>No notifications yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <tr>
                                    <th>Message</th>
                                    <th>Received</th>
                                </tr>
                                <?php foreach ($notifications as $note): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($note['message']); ?></td>
                                    <td><?php echo htmlspecialchars($note['created_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        var notificationsModal = document.getElementById('notificationsModal');
        var closeNotificationsBtn = document.getElementById('closeNotificationsModal');
        if (closeNotificationsBtn) {
            closeNotificationsBtn.addEventListener('click', function () {
                window.location.href = 'recruiter_dashboard.php';
            });
        }
        if (notificationsModal) {
            notificationsModal.addEventListener('click', function (event) {
                if (event.target === notificationsModal) {
                    window.location.href = 'recruiter_dashboard.php';
                }
            });
        }
    </script>
</body>
</html>

