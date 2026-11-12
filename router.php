<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/' && !str_starts_with($path, '/api/') && is_file(__DIR__ . '/public' . $path)) {
    return false;
}
require __DIR__ . '/index.php';
