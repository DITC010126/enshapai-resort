<?php
require __DIR__ . '/app.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();   // [SEC] full session teardown
}
redirect('index.php');
