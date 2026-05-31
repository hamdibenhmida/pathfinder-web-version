<?php
function current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

function current_role()
{
    return $_SESSION['role'] ?? null;
}

function current_username()
{
    return $_SESSION['username'] ?? 'User';
}

function require_login()
{
    if (!current_user_id()) {
        header('Location: login.php');
        exit();
    }
}

function require_role(array $roles)
{
    if (!current_user_id() || !in_array(current_role(), $roles, true)) {
        header('Location: login.php');
        exit();
    }
}
