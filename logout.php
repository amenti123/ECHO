<?php
require_once __DIR__ . '/_preflight.php';
session_destroy();
header('Location: login.php');
exit;
