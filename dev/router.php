<?php
// Router for PHP's built-in server: serve real files, send everything else to WordPress (pretty permalinks).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = getcwd() . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}
if (is_dir($file) && file_exists(rtrim($file, '/') . '/index.php')) {
    require rtrim($file, '/') . '/index.php';
    return true;
}
require getcwd() . '/index.php';
