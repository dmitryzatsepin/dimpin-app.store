<?php 
header('Content-Type: text/plain');
echo "��������!\n";
echo "Current user: " . exec('whoami') . "\n";
echo "SCRIPT_FILENAME: " . $_SERVER['SCRIPT_FILENAME'] . "\n";
?>