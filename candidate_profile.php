<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_login();

function update_candidate_completeness($conn, $user_id) {
    $profile_data = db_fetch_one($conn, "SELECT resume_url, skills, bio FROM candidates WHERE user_id = ?", 'i', [$user_id]);
    $completeness = 0;
    if (!empty($profile_data['resume_url'])) $completeness += 40;
    $skill_list = array_filter(array_map('trim', explode(',', $profile_data['skills'] ?? '')));
    $skill_count = count($skill_list);
    $completeness += min(30, $skill_count * 6);
    if (!empty(trim($profile_data['bio'] ?? ''))) $completeness += 20;
    $completeness = min(100, $completeness);
    db_execute($conn, "UPDATE candidates SET completeness = ? WHERE user_id = ?", 'ii', [$completeness, $user_id]);
}

function extract_text_from_docx(string $filePath): string {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $content = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($content !== false) {
                $text = preg_replace('/<[^>]+>/', ' ', $content);
                return html_entity_decode($text, ENT_QUOTES | ENT_XML1);
            }
        }
    }

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'docx_extract_' . bin2hex(random_bytes(6));
    if (!mkdir($tempDir, 0755, true)) {
        return '';
    }

    $powershell = trim(shell_exec('where powershell 2>NUL'));
    if ($powershell) {
        $command = sprintf(
            '%s -NoProfile -Command "try { Expand-Archive -LiteralPath %s -DestinationPath %s -Force } catch { exit 1 }"',
            escapeshellarg($powershell),
            escapeshellarg($filePath),
            escapeshellarg($tempDir)
        );
        shell_exec($command);
    }

    if (!is_dir($tempDir . DIRECTORY_SEPARATOR . 'word')) {
        $tar = trim(shell_exec('where tar 2>NUL'));
        if ($tar) {
            shell_exec(sprintf('"%s" -xf %s -C %s 2>NUL', $tar, escapeshellarg($filePath), escapeshellarg($tempDir)));
        } else {
            $unzip = trim(shell_exec('where unzip 2>NUL'));
            if ($unzip) {
                shell_exec(sprintf('"%s" %s -d %s 2>NUL', $unzip, escapeshellarg($filePath), escapeshellarg($tempDir)));
            }
        }
    }

    $docPath = $tempDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
    $xml = is_readable($docPath) ? file_get_contents($docPath) : '';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($tempDir);

    if ($xml === false || $xml === '') {
        return '';
    }

    $text = preg_replace('/<[^>]+>/', ' ', $xml);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1);
}

function extract_text_from_pdf(string $filePath): string {
    $text = '';
    $pdftotext = trim(shell_exec('where pdftotext 2>NUL'));
    if ($pdftotext) {
        $escaped = escapeshellarg($filePath);
        $text = shell_exec("\"$pdftotext\" -layout -enc UTF-8 $escaped - 2>NUL");
        if (empty(trim($text))) {
            $text = shell_exec("\"$pdftotext\" -nopgbrk -enc UTF-8 $escaped - 2>NUL");
        }
        if (empty(trim($text))) {
            $text = shell_exec("\"$pdftotext\" $escaped - 2>NUL");
        }
        if ($text) {
            $text = trim($text);
            if (stripos($text, 'error') !== false) {
                $text = '';
            }
        }
    }
    if (empty(trim($text))) {
        $text = extract_text_from_pdf_basic($filePath);
    }
    return $text ?: '';
}

function extract_text_from_pdf_basic(string $filePath): string {
    $content = file_get_contents($filePath);
    if ($content === false) {
        return '';
    }

    $texts = [];
    if (preg_match_all('#\((?:[^()\\\\]|\\\\.)*\)(?=\s*Tj)#', $content, $matches)) {
        foreach ($matches[0] as $match) {
            $texts[] = pdf_decode_string($match);
        }
    }
    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $matches)) {
        foreach ($matches[1] as $match) {
            if (preg_match_all('#\((?:[^()\\\\]|\\\\.)*\)#', $match, $subMatches)) {
                foreach ($subMatches[0] as $subMatch) {
                    $texts[] = pdf_decode_string($subMatch);
                }
            }
        }
    }

    if (empty($texts)) {
        return '';
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', $texts)));
}

function pdf_decode_string(string $pdfString): string {
    $pdfString = substr($pdfString, 1, -1);
    return preg_replace_callback('/\\\\(\\\\|n|r|t|b|f|\(|\)|[0-7]{1,3}|x[0-9A-Fa-f]{2})/', function ($matches) {
        $code = $matches[1];
        switch ($code) {
            case '\\': return '\\';
            case 'n': return "\n";
            case 'r': return "\r";
            case 't': return "\t";
            case 'b': return "\x08";
            case 'f': return "\x0C";
            case '(' : return '(';
            case ')' : return ')';
        }
        if ($code[0] === 'x') {
            return chr(hexdec(substr($code, 1)));
        }
        return chr(octdec($code));
    }, $pdfString);
}

function extract_text_from_doc(string $filePath): string {
    $text = '';
    $antiword = trim(shell_exec('where antiword 2>NUL'));
    if ($antiword) {
        $escaped = escapeshellarg($filePath);
        $text = shell_exec("\"$antiword\" $escaped 2>NUL");
    }
    return $text ?: '';
}

function get_resume_text(string $resumeUrl): string {
    $path = __DIR__ . '/' . ltrim($resumeUrl, '/');
    if (!is_file($path)) {
        return '';
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'docx') {
        return extract_text_from_docx($path);
    }
    if ($extension === 'doc') {
        return extract_text_from_doc($path);
    }
    if ($extension === 'pdf') {
        return extract_text_from_pdf($path);
    }
    return file_get_contents($path) ?: '';
}

function detect_skills_from_resume_text(string $text): string {
    $keywords = [
        'php', 'mysql', 'sql', 'javascript', 'html', 'css', 'laravel', 'symfony', 'react', 'vue', 'node',
        'express', 'git', 'docker', 'aws', 'python', 'java', 'c#', 'c++', 'devops', 'linux', 'project management',
        'agile', 'communication', 'team leadership', 'data analysis', 'machine learning', 'wordpress', 'bootstrap'
    ];
    $normalized = strtolower($text);
    $found = [];
    foreach ($keywords as $keyword) {
        if (strpos($normalized, strtolower($keyword)) !== false) {
            $found[] = ucwords($keyword);
        }
    }
    $found = array_unique($found, SORT_STRING);
    return implode(', ', $found);
}

$user_id = current_user_id();
$role = current_role();

$user_data = db_fetch_one($conn, "SELECT * FROM users WHERE id = ?", 'i', [$user_id]);

if ($role === 'candidate') {
    $profile_data = db_fetch_one($conn, "SELECT * FROM candidates WHERE user_id = ?", 'i', [$user_id]);
    if ($profile_data && empty(trim($profile_data['skills'])) && !empty($profile_data['resume_url'])) {
        $resume_text = get_resume_text($profile_data['resume_url']);
        if (!empty(trim($resume_text))) {
            $auto_skills = detect_skills_from_resume_text($resume_text);
            if (!empty($auto_skills)) {
                $profile_data['skills'] = $auto_skills;
                db_execute($conn, "UPDATE candidates SET skills = ? WHERE user_id = ?", 'si', [$auto_skills, $user_id]);
                update_candidate_completeness($conn, $user_id);
            }
        }
    }
} elseif ($role === 'recruiter') {
    $profile_data = db_fetch_one($conn, "SELECT * FROM recruiters WHERE user_id = ?", 'i', [$user_id]);
} else {
    $profile_data = null;
}
$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        $msg = "<div class='alert alert-danger'>Invalid request. Please try again.</div>";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $skills = trim($_POST['skills'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "<div class='alert alert-danger'>Invalid email format.</div>";
        } else {
            $check_user = db_fetch_one($conn, "SELECT id FROM users WHERE username = ? AND id != ?", 'si', [$username, $user_id]);
            $check_email = db_fetch_one($conn, "SELECT id FROM users WHERE email = ? AND id != ?", 'si', [$email, $user_id]);

            if ($check_user) {
                $msg = "<div class='alert alert-danger'>Username already taken.</div>";
            } elseif ($check_email) {
                $msg = "<div class='alert alert-danger'>Email already in use.</div>";
            } else {
                db_execute($conn, "UPDATE users SET username = ?, email = ? WHERE id = ?", 'ssi', [$username, $email, $user_id]);

                if ($role === 'candidate') {
                    if ($profile_data) {
                        db_execute($conn, "UPDATE candidates SET skills = ?, bio = ? WHERE user_id = ?", 'ssi', [$skills, $bio, $user_id]);
                    } else {
                        db_execute($conn, "INSERT INTO candidates (user_id, skills, bio) VALUES (?, ?, ?)", 'iss', [$user_id, $skills, $bio]);
                    }
                    update_candidate_completeness($conn, $user_id);
                } elseif ($role === 'recruiter') {
                    if ($profile_data) {
                        db_execute($conn, "UPDATE recruiters SET company_name = ? WHERE user_id = ?", 'si', [$company_name, $user_id]);
                    } else {
                        db_execute($conn, "INSERT INTO recruiters (user_id, company_name) VALUES (?, ?)", 'is', [$user_id, $company_name]);
                    }
                }

                $_SESSION['username'] = $username;
                $msg = "<div class='alert alert-success'>Profile updated successfully.</div>";
            }
        }
    }

    $user_data = db_fetch_one($conn, "SELECT * FROM users WHERE id = ?", 'i', [$user_id]);
    if ($role === 'candidate') {
        $profile_data = db_fetch_one($conn, "SELECT * FROM candidates WHERE user_id = ?", 'i', [$user_id]);
    } elseif ($role === 'recruiter') {
        $profile_data = db_fetch_one($conn, "SELECT * FROM recruiters WHERE user_id = ?", 'i', [$user_id]);
    } else {
        $profile_data = null;
    }
}
$sidebar_links = [];
if ($role === 'candidate') {
    $sidebar_links = [
        ['href' => 'candidate_dashboard.php', 'label' => 'Dashboard']
    ];
} elseif ($role === 'recruiter') {
    $sidebar_links = [
        ['href' => 'php/recruiter_dashboard.php', 'label' => 'Dashboard']
    ];
}
if (current_role() === 'admin') {
    $sidebar_links[] = ['href' => 'admin_dashboard.php', 'label' => 'Admin View'];
}
$sidebar_sections = [
    ['title' => 'Account', 'links' => $sidebar_links]
];
$topbar_actions = [];
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Profile - PathFinder</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-shell">
        <?php render_sidebar('PathFinder', $sidebar_sections, [], $topbar_actions); ?>
        <div class="app-main">

            <div class="container">
                <?php if ($msg) echo $msg; ?>
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2 class="section-title">Account settings</h2>
                            <p class="section-subtitle">Update profile details and keep information current.</p>
                        </div>
                    </div>
                    <div class="dashboard-grid">
                        <div class="dashboard-stack">
                            <div class="card">
                                <h3 style="margin-top: 0;">Account Overview</h3>
                                <p class="muted">Your core identity details.</p>
                                <p><strong>Username:</strong> <?php echo htmlspecialchars($user_data['username']); ?></p>
                                <p><strong>Email Address:</strong> <?php echo htmlspecialchars($user_data['email']); ?></p>

                                <?php if ($role == 'candidate'): ?>
                                    <p><strong>Profile Completeness:</strong> <?php echo $profile_data['completeness']; ?>%</p>
                                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 10px;">
                                        <h4 style="margin: 0 0 10px 0; font-size: 14px;">Completeness Breakdown</h4>
                                        <ul style="list-style: none; padding: 0; margin: 0;">
                                            <li style="margin-bottom: 8px;">
                                                <strong>Resume:</strong> 
                                                <?php if (!empty($profile_data['resume_url'])): ?>
                                                    <span style="color: #28a745;">✓ Uploaded (+40 points)</span>
                                                <?php else: ?>
                                                    <span style="color: #dc3545;">✗ Not uploaded (0 points)</span> - 
                                                    <a href="candidate_dashboard.php" style="color: #007bff;">Upload your resume</a> to get started
                                                <?php endif; ?>
                                            </li>
                                            <li style="margin-bottom: 8px;">
                                                <strong>Skills:</strong> 
                                                <?php $skill_list = array_filter(array_map('trim', explode(',', $profile_data['skills'] ?? ''))); $skill_count = count($skill_list); $skill_points = min(30, $skill_count * 6); ?>
                                                <span style="color: <?php echo $skill_points > 0 ? '#28a745' : '#dc3545'; ?>">
                                                    <?php echo $skill_count; ?> skills detected (+<?php echo $skill_points; ?> points)
                                                </span>
                                                <?php if ($skill_count < 5): ?> - Add more skills below to increase your score<?php endif; ?>
                                            </li>
                                            <li>
                                                <strong>Bio:</strong> 
                                                <?php if (!empty(trim($profile_data['bio'] ?? ''))): ?>
                                                    <span style="color: #28a745;">✓ Filled (+20 points)</span>
                                                <?php else: ?>
                                                    <span style="color: #dc3545;">✗ Not filled (0 points)</span> - 
                                                    <a href="#edit-profile" style="color: #007bff;">Add a bio</a> to tell recruiters about yourself
                                                <?php endif; ?>
                                            </li>
                                        </ul>
                                        <p style="margin: 10px 0 0 0; font-size: 12px; color: #6c757d;">
                                            <strong>To reach 100%:</strong> Upload resume (+40), list at least 5 skills (+30), and fill your bio (+20).
                                        </p>
                                    </div>
                                    <?php
                                        $resume_status = $profile_data['resume_url'] ? 'Uploaded' : 'Missing';
                                        if ($profile_data['resume_url'] && empty(trim($profile_data['skills']))) {
                                            $resume_status .= ' (text extraction may still be pending; add skills manually for better matching)';
                                        }
                                    ?>
                                    <p><strong>Resume Status:</strong> <?php echo htmlspecialchars($resume_status); ?></p>
                                <p><strong>Bio:</strong> <?php echo htmlspecialchars($profile_data['bio'] ?? 'Not added yet'); ?></p>
                                <?php elseif ($role == 'recruiter'): ?>
                                    <p><strong>Company Name:</strong> <?php echo htmlspecialchars($profile_data['company_name'] ?? 'Not set'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="dashboard-stack">
                            <div class="card" id="edit-profile">
                                <h3 style="margin-top: 0;">Edit Profile</h3>
                                <p class="muted">Update your account details below.</p>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <label class="field">
                                        <span>Username</span>
                                        <input type="text" name="username" required minlength="3" value="<?php echo htmlspecialchars($user_data['username']); ?>">
                                    </label>
                                    <label class="field">
                                        <span>Email Address</span>
                                        <input type="email" name="email" required value="<?php echo htmlspecialchars($user_data['email']); ?>">
                                    </label>
                                    <?php if ($role == 'candidate'): ?>
                                        <label class="field">
                                            <span>Bio</span>
                                            <textarea name="bio" rows="4" placeholder="Tell recruiters about your experience and goals."><?php echo htmlspecialchars($profile_data['bio'] ?? ''); ?></textarea>
                                        </label>
                                        <label class="field">
                                            <span>Skills (comma separated)</span>
                                            <textarea name="skills" rows="3" placeholder="e.g. PHP, Laravel, SQL"><?php echo htmlspecialchars($profile_data['skills'] ?? ''); ?></textarea>
                                        </label>
                                    <?php elseif ($role == 'recruiter'): ?>
                                        <label class="field">
                                            <span>Company Name</span>
                                            <input type="text" name="company_name" required value="<?php echo htmlspecialchars($profile_data['company_name'] ?? ''); ?>">
                                        </label>
                                    <?php endif; ?>
                                    <button type="submit" class="btn">Save Changes</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>