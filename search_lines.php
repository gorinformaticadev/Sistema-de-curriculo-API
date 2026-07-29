<?php
$content = file_get_contents('admin.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (stripos($line, '<button class="tab" onclick="showTab(\'access\')">') !== false) {
        echo "Access Tab Button - Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
    }
    if (stripos($line, 'id="addUserModal"') !== false) {
        echo "addUserModal - Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
    }
    if (stripos($line, '<!-- Modais -->') !== false) {
        echo "Modais comment - Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
    }
    if (stripos($line, '</script>') !== false && $i > 3000) {
        echo "Closing script - Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
    }
}
