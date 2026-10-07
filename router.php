<?php // php -S 0.0.0.0:$PORT router.php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($p, '/assets/') === 0 && strpos($p, '..') === false && is_file(__DIR__ . $p)) return false;
require __DIR__ . '/api/index.php';
