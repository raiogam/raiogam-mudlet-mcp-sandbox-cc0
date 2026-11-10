<?php
declare(strict_types=1);

// Router for php -S. Production must set its document root to public/.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($path, "\0") || str_contains($path, '..') || preg_match('~(?:^|/)\.~', $path)) {
    http_response_code(404); exit('Não encontrado.');
}
if ($path === '/api.php') { require __DIR__ . '/api.php'; return; }
if ($path === '/' || $path === '/index.php') { require __DIR__ . '/index.php'; return; }
$file = realpath(__DIR__ . $path);
if ($file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with(strtolower($file), '.php')) { return false; }
http_response_code(404);
echo 'Não encontrado.';
