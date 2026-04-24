<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('AFSTORT2FASESSID');
    session_start();
}

?>
