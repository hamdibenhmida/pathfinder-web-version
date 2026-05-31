<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['admin']);

// ==========================================
// 1. FETCH SYSTEM STATISTICS
// ==========================================
$stat_users_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM users WHERE role != 'admin'");
$stat_jobs_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM job_postings");
$stat_apps_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM applications");
$stat_users = $stat_users_row['count'] ?? 0;
$stat_jobs = $stat_jobs_row['count'] ?? 0;
$stat_apps = $stat_apps_row['count'] ?? 0;

// ==========================================
// 2. FETCH USERS (With Search)
// ==========================================
$search_query = "";
$search_value = '';
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_value = trim($_GET['search']);
    $search_query = " AND (username LIKE ? OR email LIKE ?)";
}
$users_sql = "SELECT id, username, email, role FROM users WHERE role != 'admin' $search_query ORDER BY id DESC";
$users_params = [];
$users_types = '';
if ($search_value !== '') {
    $like = "%{$search_value}%";
    $users_params = [$like, $like];
    $users_types = 'ss';
}
$users_list = db_fetch_all($conn, $users_sql, $users_types, $users_params);

// ==========================================
// 3. FETCH ALL JOB POSTINGS
// ==========================================
$jobs_list = db_fetch_all(
    $conn,
    "SELECT j.id, j.title, j.status, u.username as recruiter_name, (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as app_count FROM job_postings j JOIN users u ON j.recruiter_id = u.id ORDER BY j.id DESC"
);

$sidebar_sections = [
    [
        'title' => 'Admin',
        'links' => [
            ['href' => 'admin_dashboard.php', 'label' => 'Overview', 'active' => true],
            ['href' => 'candidate_dashboard.php', 'label' => 'Candidate View'],
            ['href' => 'recruiter_dashboard.php', 'label' => 'Recruiter View']
        ]
    ]
];
$topbar_actions = [];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Master Admin - PathFinder</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="app-shell">
        <?php render_sidebar('PathFinder', $sidebar_sections, [], $topbar_actions); ?>
        <div class="app-main">
            <div class="container" >
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($_GET['msg']); ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-header">
                <div>
                    <h2 class="section-title">System overview</h2>
                    <p class="section-subtitle">Live totals across the platform.</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-number"><?php echo $stat_users; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Job Postings</div>
                    <div class="stat-number"><?php echo $stat_jobs; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Applications</div>
                    <div class="stat-number"><?php echo $stat_apps; ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="card card-warm table-card">
                <div class="table-header">
                    <div>
                        <h2 class="table-title">Job Postings Oversight</h2>
                        <p class="table-meta">Monitor, close, or remove any active job posting on the platform.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>Job ID</th>
                            <th>Job Title</th>
                            <th>Posted By</th>
                            <th>Applications</th>
                            <th>Status</th>
                            <th>Admin Actions</th>
                        </tr>
                        <?php foreach ($jobs_list as $j): ?>
                        <tr>
                            <td>#<?php echo $j['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($j['title']); ?></strong></td>
                            <td><?php echo htmlspecialchars($j['recruiter_name']); ?></td>
                            <td><span class="badge badge-blue"><?php echo $j['app_count']; ?> Apps</span></td>
                            <td>
                                <?php if($j['status'] == 'open'): ?>
                                    <span class="badge" style="background: #d1fae5; color: #065f46;">OPEN</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #f3f4f6; color: #6b7280;">CLOSED</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap: 5px;">
                                    <?php if($j['status'] == 'open'): ?>
                                        <form method="POST" action="close_job.php">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$j['id']); ?>">
                                            <button type="submit" class="btn btn-warm btn-sm" onclick="return confirm('Force close this job?')">Force Close</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="delete_job.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$j['id']); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('WARNING: Delete this job entirely?')">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="card card-accent table-card">
                <div class="table-header">
                    <div>
                        <h2 class="table-title">User Management</h2>
                        <p class="table-meta">Find, edit, or remove platform users.</p>
                    </div>
                    <div class="table-actions">
                        <form method="GET">
                            <input type="text" name="search" placeholder="Search name or email..." value="<?php echo htmlspecialchars($search_value); ?>">
                            <button type="submit" class="btn">Search</button>
                        </form>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <tr>
                            <th>User ID</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Email Address</th>
                            <th>Admin Action</th>
                        </tr>
                        <?php foreach ($users_list as $u): ?>
                        <tr>
                            <td>#<?php echo $u['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                            <td>
                                <span class="badge <?php echo ($u['role'] == 'recruiter') ? 'badge-orange' : 'badge-blue'; ?>">
                                    <?php echo strtoupper($u['role']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td>
                                <div style="display:flex; gap: 8px;">
                                    <a href="admin_edit_user.php?id=<?php echo $u['id']; ?>" class="btn btn-sm">Edit User</a>
                                    <form method="POST" action="delete_user.php">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$u['id']); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user? This cannot be undone.')">Remove User</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

            </div>
        </div>
    </div>
</body>
</html>

