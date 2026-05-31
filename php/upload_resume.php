<?php
require_once __DIR__ . '/includes/bootstrap.php';

require_role(['candidate', 'admin']);

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
    if (empty(trim($text))) {
        $text = extract_text_from_pdf_strings($filePath);
    }
    if (empty(trim($text))) {
        $text = extract_text_from_pdf_with_ocr($filePath);
    }
    return $text ?: '';
}

function extract_text_from_pdf_strings(string $filePath): string {
    $content = file_get_contents($filePath);
    if ($content === false) {
        return '';
    }

    preg_match_all('/[A-Za-z0-9][A-Za-z0-9\.\,\-\+\/& ]{4,}/', $content, $matches);
    $strings = array_filter(array_map('trim', $matches[0]));
    if (empty($strings)) {
        return '';
    }

    return trim(preg_replace('/\s+/', ' ', implode(' ', array_slice($strings, 0, 50))));
}

function extract_text_from_pdf_with_ocr(string $filePath): string {
    $tesseract = trim(shell_exec('where tesseract 2>NUL'));
    if (!$tesseract) {
        return '';
    }

    $magick = trim(shell_exec('where magick 2>NUL')) ?: trim(shell_exec('where convert 2>NUL'));
    $pdftoppm = trim(shell_exec('where pdftoppm 2>NUL'));
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'resume_ocr_' . bin2hex(random_bytes(6));
    if (!mkdir($tmpDir, 0755, true)) {
        return '';
    }

    $images = [];
    if ($magick) {
        shell_exec(sprintf('"%s" -density 200 %s %s 2>NUL', $magick, escapeshellarg($filePath), escapeshellarg($tmpDir . DIRECTORY_SEPARATOR . 'page.png')));
        $images = glob($tmpDir . DIRECTORY_SEPARATOR . 'page*.png');
    }
    if (empty($images) && $pdftoppm) {
        shell_exec(sprintf('"%s" -png -r 200 %s %s 2>NUL', $pdftoppm, escapeshellarg($filePath), escapeshellarg($tmpDir . DIRECTORY_SEPARATOR . 'page')));
        $images = glob($tmpDir . DIRECTORY_SEPARATOR . 'page*.png');
    }

    $text = '';
    foreach ($images as $image) {
        $ocr = shell_exec(sprintf('"%s" %s stdout -l eng 2>NUL', $tesseract, escapeshellarg($image)));
        if ($ocr) {
            $text .= ' ' . trim($ocr);
        }
    }

    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') as $file) {
        @unlink($file);
    }
    @rmdir($tmpDir);

    return trim(preg_replace('/\s+/', ' ', $text));
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

function detect_skills_from_text(string $text, string $apiKey): array {
    if (empty(trim($text))) {
        return ['General Profile', 0];
    }
    
    $prompt = "Extract the key technical skills and competencies from this resume text. List them as a comma-separated list, only the skills, no other text:\n\n" . $text;
    
    $aiResponse = callGeminiAPI($prompt, $apiKey);
    if ($aiResponse) {
        $skills = array_map('trim', explode(',', $aiResponse));
        $skills = array_filter($skills, function($skill) {
            return !empty($skill) && strlen($skill) > 1;
        });
        $skills = array_unique($skills);
        if (!empty($skills)) {
            return [implode(', ', $skills), count($skills)];
        }
    }

    $found = fallback_skill_keywords($text);
    if (!empty($found)) {
        return [implode(', ', $found), count($found)];
    }

    $patternSkills = extract_skills_from_resume_text($text);
    if (!empty($patternSkills)) {
        return [implode(', ', $patternSkills), count($patternSkills)];
    }

    return ['General Profile', 0];
}

function fallback_skill_keywords(string $text): array {
    $keywords = [
        'php', 'mysql', 'sql', 'javascript', 'html', 'css', 'laravel', 'symfony', 'react', 'vue', 'node',
        'express', 'git', 'docker', 'kubernetes', 'aws', 'azure', 'gcp', 'python', 'java', 'c#', 'c++',
        'devops', 'linux', 'project management', 'agile', 'scrum', 'communication', 'bootstrap', 'wordpress',
        'rest', 'graphql', 'api', 'tensorflow', 'pandas', 'react native', 'flutter', 'django'
    ];

    $found = [];
    $normalized = strtolower($text);
    foreach ($keywords as $keyword) {
        if (strpos($normalized, strtolower($keyword)) !== false) {
            $found[] = ucwords($keyword);
        }
    }
    return array_unique($found, SORT_STRING);
}

function extract_skills_from_resume_text(string $text): array {
    $patterns = [
        '/(?:skills|technical skills|competencies|expertise)\s*[:\-]?\s*([^\n\r]+)/i',
        '/(?:skills|technical skills|competencies|expertise)\s*[\r\n]+([^\n\r]+)/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $line = trim($matches[1]);
            $line = preg_replace('/[^A-Za-z0-9\.,+#\-/& ]+/', ' ', $line);
            $parts = preg_split('/[;,\n\r]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_map('trim', $parts);
            $parts = array_filter($parts, function($part) {
                return strlen($part) > 1;
            });
            if (!empty($parts)) {
                return array_unique($parts, SORT_STRING);
            }
        }
    }

    $lines = array_slice(preg_split('/\r?\n/', $text), 0, 20);
    foreach ($lines as $line) {
        if (substr_count($line, ',') >= 2) {
            $line = preg_replace('/[^A-Za-z0-9\.,+#\-/& ]+/', ' ', $line);
            $parts = preg_split('/[;,]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            $parts = array_map('trim', $parts);
            $parts = array_filter($parts, function($part) {
                return strlen($part) > 1 && preg_match('/[A-Za-z]/', $part);
            });
            if (count($parts) >= 3) {
                return array_unique($parts, SORT_STRING);
            }
        }
    }

    return [];
}

function calculate_completeness(string $text, int $skillCount): int {
    $score = 30;
    if (trim($text) !== '') {
        $score += 40;
    }
    $score += min(30, $skillCount * 6);
    return min(100, max(0, $score));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate($_POST['csrf_token'] ?? '')) {
    header('Location: candidate_dashboard.php');
    exit();
}

$user_id = current_user_id();
$target_dir = __DIR__ . '/assets/uploads/';

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0755, true);
}

$file = $_FILES['resume'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    header('Location: candidate_dashboard.php?msg=Upload failed');
    exit();
}

$max_size = 5 * 1024 * 1024;
if ($file['size'] > $max_size) {
    header('Location: candidate_dashboard.php?msg=File too large');
    exit();
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$filenameExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowed = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/zip' => 'docx',
    'application/octet-stream' => 'docx'
];

if (!isset($allowed[$mime])) {
    if ($filenameExt === 'docx') {
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    } elseif ($filenameExt === 'doc') {
        $mime = 'application/msword';
    } elseif ($filenameExt === 'pdf') {
        $mime = 'application/pdf';
    }
}

if (!isset($allowed[$mime])) {
    header('Location: candidate_dashboard.php?msg=Invalid file type');
    exit();
}

$extension = $allowed[$mime];
$safe_name = bin2hex(random_bytes(8)) . '.' . $extension;
$target_file = $target_dir . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $target_file)) {
    header('Location: candidate_dashboard.php?msg=Upload failed');
    exit();
}

// --- START ANALYSIS LOGIC ---
$text = '';
if ($extension === 'docx') {
    $text = extract_text_from_docx($target_file);
} elseif ($extension === 'doc') {
    $text = extract_text_from_doc($target_file);
} elseif ($extension === 'pdf') {
    $text = extract_text_from_pdf($target_file);
}

if (!$text) {
    $text = '';
}

$apiKey = 'AIzaSyDSc-W_N9TajNhu8EndlMGIcY_YRazP9nc';
list($detected_skills, $skill_count) = detect_skills_from_text($text, $apiKey);
$relative_path = 'assets/uploads/' . $safe_name;
$completeness = calculate_completeness($text, $skill_count);

$candidate = db_fetch_one($conn, "SELECT bio FROM candidates WHERE user_id = ?", 'i', [$user_id]);
if (!empty(trim($candidate['bio'] ?? ''))) $completeness += 20;
$completeness = min(100, $completeness);

$ok = db_execute(
    $conn,
    "UPDATE candidates SET resume_url = ?, skills = ?, completeness = ? WHERE user_id = ?",
    'ssii',
    [$relative_path, $detected_skills, $completeness, $user_id]
);

if ($ok) {
    header('Location: candidate_dashboard.php?msg=Resume uploaded');
    exit();
}

header('Location: candidate_dashboard.php?msg=Upload failed');
exit();
?>

