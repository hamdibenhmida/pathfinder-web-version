<?php
/**
 * Bootstrap file - Initialize application environment and load core dependencies
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database connection
require_once __DIR__ . '/../db.php';
// Load database helper functions
require_once __DIR__ . '/db_helpers.php';

// Ensure interview option schema exists to support recruiter interview proposals
if (!db_execute($conn, "CREATE TABLE IF NOT EXISTS interview_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    recruiter_id INT NOT NULL,
    option_time DATETIME NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)")) {
    // If schema creation fails, continue gracefully; missing table will surface only when used.
}

// Load authentication functions
require_once __DIR__ . '/auth.php';
// Load CSRF protection functions
require_once __DIR__ . '/csrf.php';
// Load template rendering helpers
require_once __DIR__ . '/template_helpers.php';
