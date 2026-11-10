<?php
declare(strict_types=1);

use Mudlet\Services;
use Mudlet\Store;

require_once dirname(__DIR__) . '/src/bootstrap.php';

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '28800');
session_name('mudlet_observatory');
session_set_cookie_params(['httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(getenv('APP_URL') ?: '', 'https://'), 'samesite' => 'Strict', 'path' => '/']);
try {
    new Store();
    $sessionPath = (getenv('APP_STORAGE_DIR') ?: dirname(__DIR__) . '/storage') . '/sessions';
    if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) { throw new RuntimeException('Diretório de sessões indisponível.'); }
    session_save_path($sessionPath);
    if (!session_start()) { throw new RuntimeException('Não foi possível iniciar a sessão.'); }
} catch (Throwable $error) {
    error_log('Mudlet Observatory session: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"Falha ao iniciar sessão. Verifique as permissões do armazenamento."}';
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function respond(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];
    $password = getenv('APP_PASSWORD') ?: '';
    $peer = $_SERVER['REMOTE_ADDR'] ?? '';
    $host = strtolower(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: '');
    $devLocal = getenv('APP_DEV_LOCAL') === '1' && in_array($peer, ['127.0.0.1','::1'], true) && in_array($host, ['localhost','127.0.0.1','[::1]','::1'], true);
    $sessionAuthenticated = !empty($_SESSION['authenticated']) && $password !== ''
        && hash_equals(hash('sha256', $password), (string)($_SESSION['password_fingerprint'] ?? ''))
        && time() - (int)($_SESSION['authenticated_at'] ?? 0) < 28800;
    $authenticated = $sessionAuthenticated || ($devLocal && $password === '');
    $action = (string)($_GET['action'] ?? 'bootstrap');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action === 'auth/session' && $method === 'GET') {
        respond(['authenticated' => $authenticated, 'configured' => $password !== '' || $devLocal, 'dev_local' => $devLocal, 'csrf' => $csrf]);
    }
    if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); respond(['error' => 'Método não permitido.'], 405); }
    if ($method === 'POST') {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $expected = getenv('APP_URL') ?: ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''));
        if (($origin !== null && rtrim($origin, '/') !== rtrim($expected, '/')) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            respond(['error' => 'Origem da requisição não permitida.'], 403);
        }
        if (strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]) !== 'application/json') { respond(['error' => 'Envie application/json.'], 415); }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) { respond(['error' => 'Requisição excede 1 MiB.'], 413); }
        $raw = file_get_contents('php://input', false, null, 0, 1048577);
        if ($raw === false || strlen($raw) > 1048576) { respond(['error' => 'Requisição excede 1 MiB.'], 413); }
        $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['csrf'] ?? null) || !hash_equals($csrf, $input['csrf'])) { respond(['error' => 'Token CSRF inválido. Atualize a página.'], 403); }
        if ($action === 'login') {
            if ($password === '') { respond(['error' => 'Configure APP_PASSWORD no servidor antes do acesso externo.'], 503); }
            $attempt = is_string($input['password'] ?? null) ? $input['password'] : '';
            $directory = getenv('APP_STORAGE_DIR') ?: dirname(__DIR__) . '/storage';
            new Store();
            $rateFile = $directory . '/login-rate.json';
            $lock = fopen($directory . '/login-rate.lock', 'c+b');
            if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('Falha no controle de autenticação.'); }
            try {
                $rate = is_file($rateFile) ? json_decode(file_get_contents($rateFile), true) : [];
                $rate = is_array($rate) ? $rate : [];
                $now = time();
                $rate = array_filter($rate, fn(array $entry): bool => ($entry['until'] ?? 0) > $now);
                $key = hash('sha256', $peer);
                $entry = $rate[$key] ?? ['count' => 0, 'until' => $now + 900];
                if ($entry['count'] >= 10) { header('Retry-After: ' . max(1, $entry['until'] - $now)); respond(['error' => 'Muitas tentativas. Tente novamente em 15 minutos.'], 429); }
                $valid = hash_equals($password, $attempt);
                if ($valid) { unset($rate[$key]); } else { $entry['count']++; $rate[$key] = $entry; }
                if (file_put_contents($rateFile, json_encode($rate, JSON_THROW_ON_ERROR)) === false) { throw new RuntimeException('Falha no controle de autenticação.'); }
                chmod($rateFile, 0600);
            } finally { flock($lock, LOCK_UN); fclose($lock); }
            if (!$valid) { respond(['error' => 'Senha inválida.'], 401); }
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['authenticated_at'] = time();
            $_SESSION['password_fingerprint'] = hash('sha256', $password);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            respond(['authenticated' => true, 'csrf' => $_SESSION['csrf']]);
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            respond(['authenticated' => false, 'csrf' => $_SESSION['csrf']]);
        }
    }
    if (!$authenticated) {
        respond(['error' => $password === '' ? 'Configure APP_PASSWORD, ou use APP_DEV_LOCAL=1 somente no computador local.' : 'Autenticação necessária.', 'configured' => $password !== '', 'csrf' => $csrf], $password === '' ? 503 : 401);
    }
    $store = new Store();
    if ($method === 'GET') {
        if ($action === 'bootstrap') { respond(['csrf' => $csrf] + $store->all()); }
        if ($action === 'export') {
            header('Content-Disposition: attachment; filename="mudlet-observatory-' . gmdate('Y-m-d') . '.json"');
            respond($store->all());
        }
        respond(['error' => 'Ação GET desconhecida.'], 404);
    }
    respond((new Services($store))->write($action, $input));
} catch (InvalidArgumentException | JsonException $error) {
    respond(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Mudlet Observatory: ' . $error->getMessage());
    respond(['error' => 'Falha interna. Consulte o log PHP e as permissões de armazenamento.'], 500);
}
