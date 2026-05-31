<?php
/**
 * Template helper functions for rendering common UI components
 */

// Render the sidebar navigation with brand, sections, and footer links
function render_sidebar($brand, array $sections, array $footer_links = [], array $actions = [])
{
    $display_name = current_username();
    $initial = strtoupper(substr($display_name, 0, 1));

    echo '<aside class="side-nav">';
    echo '<div class="side-brand">' . htmlspecialchars($brand) . '</div>';

    foreach ($sections as $section) {
        $title = $section['title'] ?? '';
        $links = $section['links'] ?? [];
        echo '<div class="side-section">';
        if ($title !== '') {
            echo '<div class="side-title">' . htmlspecialchars($title) . '</div>';
        }
        foreach ($links as $link) {
            $href = htmlspecialchars($link['href']);
            $label = htmlspecialchars($link['label']);
            $class = !empty($link['active']) ? 'side-link active' : 'side-link';
            echo "<a href=\"{$href}\" class=\"{$class}\">{$label}</a>";
        }
        echo '</div>';
    }

    // Render topbar actions in the header
    echo '<div class="nav-actions">';
    foreach ($actions as $action) {
        $type = $action['type'] ?? 'link';
        $href = htmlspecialchars($action['href'] ?? '#');
        $label = htmlspecialchars($action['label'] ?? '');
        $class = htmlspecialchars($action['class'] ?? 'btn');

        if ($type === 'icon' && ($action['icon'] ?? '') === 'bell') {
            echo '<a href="' . $href . '" class="icon-btn" aria-label="Notifications">';
            echo '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
            echo '<path d="M12 3a6 6 0 0 0-6 6v3.59l-1.7 1.7a1 1 0 0 0 .7 1.71h14a1 1 0 0 0 .7-1.71L18 12.59V9a6 6 0 0 0-6-6zm0 18a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 21z"></path>';
            echo '</svg>';
            if (($action['badge'] ?? 0) > 0) {
                echo '<span class="notification-badge">' . $action['badge'] . '</span>';
            }
            echo '</a>';
        } elseif ($type === 'button') {
            echo '<a href="' . $href . '" class="' . $class . '">' . $label . '</a>';
        } else {
            echo '<a href="' . $href . '" class="topbar-link">' . $label . '</a>';
        }
    }
    echo '</div>';

    if (!empty($footer_links)) {
        echo '<div class="side-footer">';
        foreach ($footer_links as $link) {
            $href = htmlspecialchars($link['href']);
            $label = htmlspecialchars($link['label']);
            echo "<a href=\"{$href}\" class=\"side-link\">{$label}</a>";
        }
        echo '</div>';
    }

    // Render profile menu at the end of header
    echo '<details class="profile-menu">';
    echo '<summary class="profile-bubble" aria-label="Open profile menu" data-username="' . htmlspecialchars($display_name) . '">';
    echo htmlspecialchars($initial);
    echo '</summary>';
    echo '<div class="profile-dropdown">';
    echo '<a href="candidate_profile.php">Profile</a>';
    echo '<a href="logout.php" class="logout-link">Logout</a>';
    echo '</div>';
    echo '</details>';

    echo '</aside>';
}

// Render the top navigation bar with title, subtitle, actions, and profile menu
function render_topbar($title, $subtitle = '', array $actions = [])
{
    $display_name = current_username();
    $initial = strtoupper(substr($display_name, 0, 1));

    echo '<header class="topbar">';
    echo '<div class="topbar-text">';
    echo '<div class="topbar-title">' . htmlspecialchars($title) . '</div>';
    if ($subtitle !== '') {
        echo '<div class="topbar-subtitle">' . htmlspecialchars($subtitle) . '</div>';
    }
    echo '</div>';

    echo '<div class="topbar-actions">';
    foreach ($actions as $action) {
        $type = $action['type'] ?? 'link';
        $href = htmlspecialchars($action['href'] ?? '#');
        $label = htmlspecialchars($action['label'] ?? '');
        $class = htmlspecialchars($action['class'] ?? 'btn');

        if ($type === 'icon' && ($action['icon'] ?? '') === 'bell') {
            // Render notification bell icon
            echo '<a href="' . $href . '" class="icon-btn" aria-label="Notifications">';
            echo '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">';
            echo '<path d="M12 3a6 6 0 0 0-6 6v3.59l-1.7 1.7a1 1 0 0 0 .7 1.71h14a1 1 0 0 0 .7-1.71L18 12.59V9a6 6 0 0 0-6-6zm0 18a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 21z"></path>';
            echo '</svg>';
            if (($action['badge'] ?? 0) > 0) {
                echo '<span class="notification-badge">' . $action['badge'] . '</span>';
            }
            echo '</a>';
        } elseif ($type === 'button') {
            echo '<a href="' . $href . '" class="' . $class . '">' . $label . '</a>';
        } else {
            echo '<a href="' . $href . '" class="topbar-link">' . $label . '</a>';
        }
    }

    // Render profile dropdown menu
    echo '<details class="profile-menu">';
    echo '<summary class="profile-bubble" aria-label="Open profile menu" data-username="' . htmlspecialchars($display_name) . '">';
    echo htmlspecialchars($initial);
    echo '</summary>';
    echo '<div class="profile-dropdown">';
    echo '<a href="candidate_profile.php">Profile</a>';
    echo '<a href="logout.php" class="logout-link">Logout</a>';
    echo '</div>';
    echo '</details>';
    echo '</div>';
    echo '</header>';
}
