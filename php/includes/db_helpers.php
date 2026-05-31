<?php
/**
 * Database helper functions for prepared statements and queries
 */

// Bind parameters to a prepared statement dynamically
function db_bind_params($stmt, $types, $params)
{
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [];
    $refs[] = $types;
    foreach ($params as $index => $value) {
        $refs[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

// Execute a prepared query and return the statement object
function db_query($conn, $sql, $types = '', $params = [])
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    db_bind_params($stmt, $types, $params);

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    return $stmt;
}

// Fetch a single row from the database
function db_fetch_one($conn, $sql, $types = '', $params = [])
{
    $stmt = db_query($conn, $sql, $types, $params);
    if (!$stmt) {
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row;
}

// Fetch all rows from the database as an array
function db_fetch_all($conn, $sql, $types = '', $params = [])
{
    $stmt = db_query($conn, $sql, $types, $params);
    if (!$stmt) {
        return [];
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

// Execute a query that doesn't return data (INSERT, UPDATE, DELETE)
function db_execute($conn, $sql, $types = '', $params = [])
{
    $stmt = db_query($conn, $sql, $types, $params);
    if (!$stmt) {
        return false;
    }

    $stmt->close();
    return true;
}
