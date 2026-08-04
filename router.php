<?php
// EQWOW: router for PHP's built-in web server (no Apache needed).
// Usage: php -S 0.0.0.0:8080 -t E:\Development\aowow E:\Development\aowow\router.php
// Serves existing files directly, routes everything else through index.php.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);

if ($path !== '/' && file_exists($file) && !is_dir($file))
    return false;                                           // let the built-in server deliver static files

chdir(__DIR__);                                             // aowow uses cwd-relative includes
require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
