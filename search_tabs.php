<?php
$content = file_get_contents('admin.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (stripos($line, 'id="access-tab"') !== false) {
        echo "Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
    }
    if (stripos($line, '<!-- Modais -->') !== false || stripos($line, '<div id="addUserModal"') !== false) {
        echo "Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
    }
}
