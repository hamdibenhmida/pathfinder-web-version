<?php
/**
 * Authentication helper functions for managing user sessions and access control
 */

// Get the current user's ID from session
function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

// Get the current user's role from session
function current_role()
{
    return $_SESSION['role'] ?? null;
}

// Get the current user's username from session, default to 'User'
function current_username()
{
    return $_SESSION['username'] ?? 'User';
}

// Require user to be logged in, redirect to login if not
function require_login()
{
    if (!current_user_id()) {
        header('Location: login.php');
        exit();
    }
}

// Require user to have one of the specified roles, redirect to login if not
function require_role(array $roles)
{
    if (!current_user_id() || !in_array(current_role(), $roles, true)) {
        header('Location: login.php');
        exit();
    }
}
