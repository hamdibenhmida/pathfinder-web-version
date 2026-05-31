<?php
/**
 * CSRF (Cross-Site Request Forgery) protection functions
 */

// Generate or retrieve a CSRF token for the current session
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

// Generate HTML hidden input field containing the CSRF token
function csrf_field()
{
    $token = htmlspecialchars(csrf_token());
    return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
}

// Validate a submitted CSRF token against the session token
function csrf_validate($token)
{
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}
