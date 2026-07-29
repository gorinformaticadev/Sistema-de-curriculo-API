<?php
$content = file_get_contents('admin.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (stripos($line, 'function canAccessAction') !== false) {
        echo "Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
        for ($j = 1; $j <= 20; $j++) {
            echo "Line " . ($i+1+$j) . ": " . htmlspecialchars(substr($lines[$i+$j], 0, 150)) . "\n";
        }
    }
}
