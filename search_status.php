<?php
$content = file_get_contents('admin.php');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (stripos($line, 'updateCurriculoStatus') !== false || stripos($line, "['pendente_novo'") !== false || stripos($line, 'pendente_novo') !== false) {
        if ($i < 1000) { // backend part
            echo "Line " . ($i+1) . ": " . htmlspecialchars(substr($line, 0, 150)) . "\n";
        }
    }
}
