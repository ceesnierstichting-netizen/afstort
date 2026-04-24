<?php
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);
non_existing_function(); // Forceren van een fout
?>
