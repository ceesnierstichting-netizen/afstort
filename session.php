<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('AFSTORTSESSID');
    session_start();
}

?>
