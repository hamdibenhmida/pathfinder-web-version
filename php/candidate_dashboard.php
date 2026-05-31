<?php
// Candidate dashboard - Main page for candidates to view stats, upload resume, and manage applications
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['candidate', 'admin']);

// === Job Matching & Resume Text Extraction Functions ===
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

function normalize_terms(string $text): array {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]+/', ' ', $text);
    $terms = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stopwords = ['and','or','the','a','an','to','for','with','of','in','on','by','is','as','are','at','that','this','from','your','you'];
    $terms = array_filter($terms, function($term) use ($stopwords) {
        return strlen($term) > 2 && !in_array($term, $stopwords, true);
    });
    return array_values(array_unique($terms, SORT_STRING));
}

function extract_requirement_terms(string $text): array {
    $text = strtolower($text);
    $text = str_replace(["\r", "\n", "\t", '•', '–', '—', '·'], ' ', $text);
    $parts = preg_split('/[;,\/]+/', $text);
    $stopwords = ['experience','knowledge','skills','ability','abilities','strong','basic','good','solid','working','professional','required','related','similar','years','use','using','working'];
    $terms = [];

    foreach ($parts as $part) {
        $part = trim(preg_replace('/[^a-z0-9\s]+/', ' ', $part));
        if ($part === '') {
            continue;
        }
        $subparts = preg_split('/\s+and\s+|\s+or\s+|\s+with\s+/', $part);
        foreach ($subparts as $subpart) {
            $subpart = trim($subpart);
            if ($subpart === '' || strlen($subpart) < 3) {
                continue;
            }
            if (in_array($subpart, $stopwords, true)) {
                continue;
            }
            $terms[] = preg_replace('/\s+/', ' ', $subpart);
        }
    }

    $terms = array_filter($terms, function($term) {
        return strlen($term) > 2;
    });

    return array_values(array_unique($terms, SORT_STRING));
}

function extract_job_terms(string $text): array {
    return normalize_terms($text);
}

function count_term_matches(array $sourceTerms, array $targetTerms): int {
    return count(array_intersect($sourceTerms, $targetTerms));
}

function calculate_term_fallback_score(string $resumeText, string $jobTitle, string $jobDescription, string $jobRequirements): array {
    $resumeTerms = normalize_terms($resumeText);
    $titleTerms = extract_job_terms($jobTitle);
    $descriptionTerms = extract_job_terms($jobDescription);
    $requirementTerms = extract_requirement_terms($jobRequirements);

    $matchedTitle = count_term_matches($titleTerms, $resumeTerms);
    $matchedDescription = count_term_matches($descriptionTerms, $resumeTerms);
    $matchedRequirements = count_term_matches($requirementTerms, $resumeTerms);

    $titleTotal = max(1, count($titleTerms));
    $descriptionTotal = max(1, count($descriptionTerms));
    $requirementsTotal = max(1, count($requirementTerms));

    $titleScore = round(($matchedTitle / $titleTotal) * 15);
    $descriptionScore = round(($matchedDescription / $descriptionTotal) * 25);
    $requirementsScore = round(($matchedRequirements / $requirementsTotal) * 50);

    $score = max(20, min(100, 20 + $titleScore + $descriptionScore + $requirementsScore));

    $reason = sprintf(
        'Requirements: %d/%d terms, description: %d/%d terms, title: %d/%d terms matched.',
        $matchedRequirements,
        $requirementsTotal,
        $matchedDescription,
        $descriptionTotal,
        $matchedTitle,
        $titleTotal
    );

    if (count($requirementTerms) === 0) {
        $reason = sprintf(
            'Matched %d/%d title terms and %d/%d description terms. No structured requirement terms were extracted from the job posting.',
            $matchedTitle,
            $titleTotal,
            $matchedDescription,
            $descriptionTotal
        );
    }

    return [$score, $reason];
}

function callGeminiAPI($prompt, $apiKey) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $result = json_decode($response, true);
    return $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

function calculate_job_match(string $resumeText, string $jobTitle, string $jobDescription, string $jobRequirements, string $apiKey, string $resumeSource = 'resume'): array {
    if (empty(trim($resumeText))) {
        return [10, 'Default low score because there is no resume text to analyze.'];
    }
    
    $jobText = $jobTitle . ' ' . $jobDescription . ' ' . $jobRequirements;
    $prompt = "Given the resume text and job posting below, return a JSON object with two keys: score and reason. " .
              "Score must be an integer 0-100 and reason must be a short explanation of why the match is that percentage. " .
              "Do not include any additional text.\n\nResume:\n" . $resumeText . "\n\nJob:\n" . $jobText;
    
    $aiResponse = callGeminiAPI($prompt, $apiKey);
    if ($aiResponse) {
        $clean = trim($aiResponse);
        $json = json_decode($clean, true);
        if (is_array($json) && isset($json['score']) && isset($json['reason'])) {
            $score = max(0, min(100, intval($json['score'])));
            $reason = trim($json['reason']);
            if ($resumeSource === 'skills') {
                $reason = 'Matched from candidate skills because resume text could not be parsed. ' . $reason;
            }
            return [$score, $reason !== '' ? $reason : 'AI could not generate a reason, but the score is based on resume and job similarity.'];
        }
        // fallback parsing if output is not strict JSON
        if (preg_match('/(\d{1,3})/', $clean, $matches)) {
            $score = max(0, min(100, intval($matches[1])));
            $reason = trim(str_replace($matches[0], '', $clean));
            $reason = trim($reason, '\"{}[]: ,');
            if ($reason === '') {
                $reason = 'AI returned a numeric score without a reason; fallback explanation used.';
            }
            if ($resumeSource === 'skills') {
                $reason = 'Matched from candidate skills because resume text could not be parsed. ' . $reason;
            }
            return [$score, $reason];
        }
    }
    
    // Fallback to a broader match across title, description, and requirements.
    list($score, $reason) = calculate_term_fallback_score($resumeText, $jobTitle, $jobDescription, $jobRequirements);
    if ($resumeSource === 'skills') {
        $reason = 'Matched from candidate skills because resume text could not be parsed. ' . $reason;
    }
    return [$score, $reason];
}

$user_id = current_user_id();
$username = current_username();

// Handle resume re-upload request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reupload') {
    if (!csrf_validate($_POST['csrf_token'] ?? '')) {
        header('Location: candidate_dashboard.php');
        exit();
    }

    db_execute($conn, "UPDATE candidates SET resume_url = NULL WHERE user_id = ?", 'i', [$user_id]);
    header('Location: candidate_dashboard.php');
    exit();
}

// Fetch candidate profile data
$candidate = db_fetch_one($conn, "SELECT * FROM candidates WHERE user_id = ?", 'i', [$user_id]);
if (!$candidate) {
    $candidate = ['resume_url' => null, 'skills' => '', 'completeness' => 0];
}
$has_uploaded = !empty($candidate['resume_url']);

// Load job search data for Find Jobs modal
$cand_data = db_fetch_one($conn, "SELECT skills, resume_url FROM candidates WHERE user_id = ?", 'i', [$user_id]);
$resume_url = $cand_data['resume_url'] ?? '';
$raw_resume_text = get_resume_text($resume_url);
$stored_skills = trim($cand_data['skills'] ?? '');

if (empty(trim($raw_resume_text)) && !empty($stored_skills)) {
    $resume_text = $stored_skills;
    $resume_source = 'skills';
} else {
    $resume_text = $raw_resume_text;
    $resume_source = 'resume';
}

$user_skills = strtolower($stored_skills);
$jobs = db_fetch_all($conn, "SELECT j.*, u.username as recruiter_name FROM job_postings j JOIN users u ON j.recruiter_id = u.id WHERE j.status = 'open'");
$apply_score = isset($_GET['score']) ? intval($_GET['score']) : null;
$apply_success = isset($_GET['applied']);
$apply_error = $_GET['apply_error'] ?? null;
$apply_message = null;
if ($apply_error === 'profile') {
    $apply_message = 'Complete your profile (80%+ and resume) before applying.';
} elseif ($apply_error === 'submit') {
    $apply_message = 'We could not submit your application. Please try again.';
}
$show_find_jobs_modal = isset($_GET['show_find_jobs']);
$show_notifications_modal = isset($_GET['show_notifications']);

// Fetch notifications
$notifications = db_fetch_all(
    $conn,
    "SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC",
    'i',
    [$user_id]
);

// Get application and job statistics
$stats_apps_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM applications WHERE candidate_id = ?", 'i', [$user_id]);
$accepted_apps_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM applications WHERE candidate_id = ? AND status = 'accepted'", 'i', [$user_id]);
$stats_jobs_row = db_fetch_one($conn, "SELECT COUNT(*) as count FROM job_postings WHERE status = 'open'");
$stats_apps = $stats_apps_row['count'] ?? 0;
$accepted_apps = $accepted_apps_row['count'] ?? 0;
$stats_jobs = $stats_jobs_row['count'] ?? 0;
$skill_list = array_filter(array_map('trim', explode(',', $candidate['skills'])));
$skills_count = count($skill_list);

$notifications = db_fetch_all($conn, "SELECT message, created_at FROM notifications WHERE user_id = ? AND unread = 1 ORDER BY created_at DESC", 'i', [$user_id]);
$notification_count = count($notifications);

$sidebar_links = [
    ['href' => 'candidate_dashboard.php', 'label' => 'Dashboard', 'active' => true],
];
if (current_role() === 'admin') {
    $sidebar_links[] = ['href' => 'admin_dashboard.php', 'label' => 'Admin View'];
}
$sidebar_sections = [
    ['title' => 'Candidate', 'links' => $sidebar_links]
];
$topbar_actions = [
    ['type' => 'icon', 'icon' => 'bell', 'href' => 'candidate_dashboard.php?show_notifications=1', 'badge' => $notification_count],
    ['type' => 'button', 'label' => 'Find Jobs', 'href' => 'candidate_dashboard.php?show_find_jobs=1', 'class' => 'btn']
];

$show_notifications_modal = isset($_GET['show_notifications']);

// Mark notifications as read when modal is opened
if ($show_notifications_modal && !empty($notifications)) {
    db_execute($conn, "UPDATE notifications SET unread = 0 WHERE user_id = ? AND unread = 1", 'i', [$user_id]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Candidate Dashboard</title>
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
                    <p class="section-subtitle">Your current application momentum.</p>
                </div>
            </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Profile Completeness</div>
                    <div class="stat-number"><?php echo intval($candidate['completeness']); ?>%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Applications</div>
                    <div class="stat-number"><?php echo intval($stats_apps); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Open Roles</div>
                    <div class="stat-number"><?php echo intval($stats_jobs); ?></div>
                </div>
            </div>
            <?php if ($accepted_apps > 0): ?>
                <div class="alert-card card card-accent" style="margin-top: 20px;">
                    <h3 style="margin-top: 0;">Good news — interview slots are ready!</h3>
                    <p class="muted">Your recruiter has accepted your application and proposed <?php echo intval($accepted_apps); ?> slot<?php echo $accepted_apps === 1 ? '' : 's'; ?>.</p>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;">
                        <a href="candidate_interviews.php" class="btn">Pick a time</a>
                        <a href="candidate_dashboard.php?show_notifications=1" class="btn btn-ghost">View notifications</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notifications Modal -->
        <div class="overlay" id="notificationsModal" style="display: <?php echo $show_notifications_modal ? 'flex' : 'none'; ?>; flex-direction: column;">
            <div class="modal-content" style="max-width: 500px; width: 90%; max-height: 70vh; overflow-y: auto;">
                <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0 0 4px 0;">Notifications</h2>
                    <button type="button" class="btn btn-ghost" id="closeNotificationsModal" style="border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; padding: 0;">✕</button>
                </div>
                <div class="modal-body">
                    <?php if (empty($notifications)): ?>
                        <p>No notifications yet.</p>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Message</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($notifications as $note): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($note['message']); ?></td>
                                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($note['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="section">
            <h3 style="margin-bottom: 10px;">Profile Completeness Breakdown</h3>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 8px;">
                        <strong>Resume:</strong> 
                        <?php if (!empty($candidate['resume_url'])): ?>
                            <span style="color: #28a745;">✓ Uploaded (+40 points)</span>
                        <?php else: ?>
                            <span style="color: #dc3545;">✗ Not uploaded (0 points)</span> - 
                            <a href="#profile-toolkit" style="color: #007bff;">Upload your resume</a>
                        <?php endif; ?>
                    </li>
                    <li style="margin-bottom: 8px;">
                        <strong>Skills:</strong> 
                        <?php $skill_count = count($skill_list); $skill_points = min(30, $skill_count * 6); ?>
                        <span style="color: <?php echo $skill_points > 0 ? '#28a745' : '#dc3545'; ?>">
                            <?php echo $skill_count; ?> skills detected (+<?php echo $skill_points; ?> points)
                        </span>
                        <?php if ($skill_count < 5): ?> - <a href="candidate_profile.php" style="color: #007bff;">Add more skills</a><?php endif; ?>
                    </li>
                    <li>
                        <strong>Bio:</strong> 
                        <?php if (!empty(trim($candidate['bio'] ?? ''))): ?>
                            <span style="color: #28a745;">✓ Filled (+20 points)</span>
                        <?php else: ?>
                            <span style="color: #dc3545;">✗ Not filled (0 points)</span> - 
                            <a href="candidate_profile.php" style="color: #007bff;">Add a bio</a>
                        <?php endif; ?>
                    </li>
                </ul>
                <p style="margin: 10px 0 0 0; font-size: 12px; color: #6c757d;">
                    <strong>To reach 100%:</strong> Upload resume (+40), list at least 5 skills (+30), and fill your bio (+20).
                </p>
            </div>
        </div>

        <div class="section" id="profile-toolkit">
            <div class="section-header">
                <div>
                    <h2 class="section-title">Profile toolkit</h2>
                    <p class="section-subtitle">Keep your resume and skills current.</p>
                </div>
            </div>
            <div class="dashboard-grid">
                <div class="dashboard-stack">
                    <?php if ($has_uploaded): ?>
                        <div class="card card-accent">
                            <h3 style="margin-top: 0;">Skills inventory</h3>
                            <p class="muted">Based on your latest resume upload.</p>
                            <div style="margin-bottom: 20px;">
                                <?php 
                                    foreach($skill_list as $skill) {
                                        if($skill != "") {
                                            echo "<span class='badge badge-blue' style='margin-right: 5px; margin-bottom: 5px;'>" . htmlspecialchars($skill) . "</span>";
                                        }
                                    }
                                    if ($skills_count === 0) {
                                        echo "<span class='badge badge-orange'>No skills detected yet</span>";
                                    }
                                ?>
                            </div>
                            <div>
                                <form method="POST" style="display: inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reupload">
                                    <button type="submit" class="link" style="background: none; border: none; padding: 0; cursor: pointer;">Update or re-upload resume</button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card card-danger">
                            <h3 style="margin-top: 0;">Resume required</h3>
                            <p class="muted">Upload your CV to unlock job applications and AI skill matching.</p>
                            <form action="upload_resume.php" method="POST" enctype="multipart/form-data">
                                <?php echo csrf_field(); ?>
                                <input type="file" name="resume" accept=".pdf,.doc,.docx" required style="border: 2px dashed var(--border); padding: 20px;">
                                <button type="submit" name="submit" class="btn">Upload resume</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="dashboard-stack">
                    <div class="card">
                        <h3 style="margin-top: 0;">Profile Snapshot</h3>
                        <p class="muted" style="margin-bottom: 12px;">Skills detected: <strong><?php echo intval($skills_count); ?></strong></p>
                        <p class="muted" style="margin-bottom: 12px;">Resume status: <strong><?php echo $has_uploaded ? 'Uploaded' : 'Missing'; ?></strong></p>
                        <p class="muted" style="margin-bottom: 0;">Applications sent: <strong><?php echo intval($stats_apps); ?></strong></p>
                    </div>

                    <div class="card">
                        <h3 style="margin-top: 0;">Next steps</h3>
                        <div style="display: grid; gap: 10px;">
                            <a href="candidate_profile.php" class="topbar-link">Review profile details</a>
                            <a href="candidate_dashboard.php?show_notifications=1" class="topbar-link">Check notifications</a>
                            <a href="candidate_interviews.php" class="topbar-link">Choose interview time</a>
                            <form method="POST" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="reupload">
                                <button type="submit" class="topbar-link" style="background: none; border: none; padding: 0; text-align: left; cursor: pointer;">Refresh resume analysis</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>
    </div>

    <!-- Find Jobs Modal -->
    <?php if ($apply_success || $apply_message): ?>
        <div class="overlay" id="applyOverlay">
            <div class="overlay-card" role="dialog" aria-live="polite">
                <?php if ($apply_success): ?>
                    <h2 style="margin-top: 0;">Application submitted</h2>
                    <p class="muted">The recruiter has been notified of your <?php echo htmlspecialchars((string)$apply_score); ?>% match score.</p>
                <?php else: ?>
                    <h2 style="margin-top: 0;">Application not submitted</h2>
                    <p class="muted"><?php echo htmlspecialchars($apply_message); ?></p>
                <?php endif; ?>
                <div class="overlay-actions">
                    <a href="candidate_dashboard.php" class="btn">Back to dashboard</a>
                    <a href="candidate_dashboard.php?show_find_jobs=1" class="btn btn-ghost">Close</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="overlay" id="findJobsModal" style="display: <?php echo $show_find_jobs_modal ? 'flex' : 'none'; ?>; flex-direction: column;">
        <div class="overlay-card" style="max-width: 900px; max-height: 90vh; overflow-y: auto; width: 100%;" role="dialog">
            <div style="padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h2 style="margin: 0 0 4px 0;">Find Jobs</h2>
                        <p class="muted" style="margin: 0;">Curated matches based on your resume skills.</p>
                    </div>
                    <button type="button" class="btn btn-ghost" id="closeJobsModal" style="border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; padding: 0;">✕</button>
                </div>

                <div class="section">
                    <div class="section-header">
                        <div>
                            <h3 class="section-title">Recommended roles</h3>
                            <p class="section-subtitle">Matches are ranked by resume relevance.</p>
                        </div>
                    </div>

                    <?php if (empty($jobs)): ?>
                        <div class="card empty-state">
                            <p>No open roles are available right now.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($jobs as $row): 
                            $apiKey = 'AIzaSyDSc-W_N9TajNhu8EndlMGIcY_YRazP9nc';
                            list($match_score, $match_reason) = calculate_job_match($resume_text, $row['title'], $row['description'], $row['requirements'], $apiKey, $resume_source);
                        ?>
                            <div class="card">
                                <div class="list-card">
                                    <div>
                                        <h3 class="list-title"><?php echo htmlspecialchars($row['title']); ?></h3>
                                        <p class="list-meta">Posted by <?php echo htmlspecialchars($row['recruiter_name']); ?></p>
                                        <span class="badge badge-orange">AI Match: <?php echo $match_score; ?>%</span>
                                    </div>
                                    <div class="list-actions">
                                        <button type="button" class="btn btn-ghost view-details" 
                                                data-title="<?php echo htmlspecialchars($row['title']); ?>" 
                                                data-description="<?php echo htmlspecialchars($row['description']); ?>" 
                                                data-requirements="<?php echo htmlspecialchars($row['requirements']); ?>" 
                                                data-recruiter="<?php echo htmlspecialchars($row['recruiter_name']); ?>" 
                                                data-match="<?php echo $match_score; ?>"
                                                data-reason="<?php echo htmlspecialchars($match_reason); ?>">View Details</button>
                                        <form action="apply_process.php" method="POST" style="display: inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="job_id" value="<?php echo htmlspecialchars((string)$row['id']); ?>">
                                            <input type="hidden" name="score" value="<?php echo htmlspecialchars((string)$match_score); ?>">
                                            <button type="submit" class="btn btn-success">Apply Now</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="overlay" id="jobDetailsModal" style="display: none;">
        <div class="overlay-card" style="max-width: 600px; max-height: 80vh; overflow-y: auto;" role="dialog" aria-labelledby="jobTitle">
            <h2 id="jobTitle"></h2>
            <p class="muted" id="jobRecruiter"></p>
            <span class="badge badge-orange" id="jobMatch"></span>
            <div style="margin-top: 16px;">
                <h3>Description</h3>
                <p id="jobDescription"></p>
            </div>
            <div style="margin-top: 16px;">
                <h3>Key Skills</h3>
                <p id="jobRequirements"></p>
            </div>
            <div style="margin-top: 16px;">
                <h3>Why this match?</h3>
                <p id="jobReason" style="white-space: pre-wrap;"></p>
            </div>
            <div class="overlay-actions" style="margin-top: 24px;">
                <button type="button" class="btn" id="closeDetailsModal">Close</button>
            </div>
        </div>
    </div>

    <!-- Notifications Modal -->
    <div class="overlay" id="notificationsModal" style="display: <?php echo $show_notifications_modal ? 'flex' : 'none'; ?>; flex-direction: column;">
        <div class="overlay-card" style="max-width: 800px; max-height: 90vh; overflow-y: auto; width: 100%;" role="dialog">
            <div style="padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <div>
                        <h2 style="margin: 0 0 4px 0;">Notifications</h2>
                        <p class="muted" style="margin: 0;">Updates about applications and interviews.</p>
                    </div>
                    <button type="button" class="btn btn-ghost" id="closeNotificationsModal" style="border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; padding: 0;">✕</button>
                </div>

                <?php if ($accepted_apps > 0): ?>
                    <div class="alert-card card card-accent" style="margin-bottom: 20px;">
                        <h3 style="margin-top: 0;">Interview time selection available</h3>
                        <p class="muted">Your recruiter has proposed interview times for <?php echo intval($accepted_apps); ?> accepted application<?php echo $accepted_apps === 1 ? '' : 's'; ?>.</p>
                        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;">
                            <a href="candidate_interviews.php" class="btn">Choose interview time</a>
                            <a href="candidate_dashboard.php" class="btn btn-ghost">Back to dashboard</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card table-card">
                    <div class="table-header">
                        <div>
                            <h3 class="table-title">Notifications</h3>
                            <p class="table-meta">Latest updates on your applications and interviews.</p>
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

    <?php if ($apply_success || $apply_message): ?>
    <script>
        const overlay = document.getElementById('applyOverlay');
        if (overlay) {
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) {
                    window.location.href = 'candidate_dashboard.php?show_find_jobs=1';
                }
            });
        }
    </script>
    <?php endif; ?>

    <script>
        const findJobsModal = document.getElementById('findJobsModal');
        const closeJobsBtn = document.getElementById('closeJobsModal');
        const detailsModal = document.getElementById('jobDetailsModal');
        const closeDetailsBtn = document.getElementById('closeDetailsModal');
        const viewDetailsBtns = document.querySelectorAll('.view-details');
        const notificationsModal = document.getElementById('notificationsModal');
        const closeNotificationsBtn = document.getElementById('closeNotificationsModal');

        // Close Find Jobs modal
        closeJobsBtn.addEventListener('click', () => {
            window.location.href = 'candidate_dashboard.php';
        });

        findJobsModal.addEventListener('click', (event) => {
            if (event.target === findJobsModal) {
                window.location.href = 'candidate_dashboard.php';
            }
        });

        // Close Notifications modal
        closeNotificationsBtn.addEventListener('click', () => {
            window.location.href = 'candidate_dashboard.php';
        });

        notificationsModal.addEventListener('click', (event) => {
            if (event.target === notificationsModal) {
                window.location.href = 'candidate_dashboard.php';
            }
        });

        // Show job details
        viewDetailsBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('jobTitle').textContent = btn.dataset.title;
                document.getElementById('jobRecruiter').textContent = 'Posted by ' + btn.dataset.recruiter;
                document.getElementById('jobMatch').textContent = 'AI Match: ' + btn.dataset.match + '%';
                document.getElementById('jobDescription').textContent = btn.dataset.description;
                document.getElementById('jobRequirements').textContent = btn.dataset.requirements;
                document.getElementById('jobReason').textContent = btn.dataset.reason || 'No explanation available.';
                detailsModal.style.display = 'flex';
            });
        });

        closeDetailsBtn.addEventListener('click', () => {
            detailsModal.style.display = 'none';
        });

        detailsModal.addEventListener('click', (event) => {
            if (event.target === detailsModal) {
                detailsModal.style.display = 'none';
            }
        });
    </script>
</body>
</html>

