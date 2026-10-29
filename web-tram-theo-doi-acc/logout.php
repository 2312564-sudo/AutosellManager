<?php
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
qq_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
