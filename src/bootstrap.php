<?php
/**
 * bootstrap.php
 *
 * Ponto de entrada comum a todo o sistema (painel admin + APIs públicas).
 * Define caminhos, lê o .env, configura erros/sessão com segurança e carrega
 * os demais módulos. Todo arquivo público (public/index.php, public/api/...,
 * public/v1/...) deve começar com:
 *
 *     require_once __DIR__ . '/../src/bootstrap.php';
 */

define('CRAFTOOLS_API_ROOT', dirname(__DIR__));
define('CRAFTOOLS_API_STORAGE', CRAFTOOLS_API_ROOT . '/storage');
define('CRAFTOOLS_API_DB_PATH', CRAFTOOLS_API_STORAGE . '/craftools_api.sqlite');

if (!is_dir(CRAFTOOLS_API_STORAGE . '/logs')) {
    @mkdir(CRAFTOOLS_API_STORAGE . '/logs', 0775, true);
}

// ---------------------------------------------------------------------------
// .env (parser minimalista, sem dependências externas)
// ---------------------------------------------------------------------------
function env($key, $default = null) {
    static $loaded = null;
    if ($loaded === null) {
        $loaded = [];
        $path = CRAFTOOLS_API_ROOT . '/.env';
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                $loaded[trim($parts[0])] = trim($parts[1], " \t\"'");
            }
        }
    }
    if (array_key_exists($key, $loaded)) {
        return $loaded[$key];
    }
    $envVal = getenv($key);
    return $envVal !== false ? $envVal : $default;
}

date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo'));

$craftoolsApiDebug = env('APP_DEBUG', '0') === '1';
ini_set('display_errors', $craftoolsApiDebug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', CRAFTOOLS_API_STORAGE . '/logs/php-error.log');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Sessão segura (somente quando há um cliente HTTP real, não em CLI/bin/*)
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $craftoolsApiHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', $craftoolsApiHttps ? '1' : '0');
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.use_trans_sid', '0');

    session_name('craftools_api_sess');
    session_start();

    $craftoolsApiNow = time();

    // Timeout por inatividade: 2h
    if (isset($_SESSION['_last_seen']) && ($craftoolsApiNow - $_SESSION['_last_seen']) > 7200) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }

    // Timeout absoluto: 8h, mesmo com atividade contínua
    if (!isset($_SESSION['_started_at'])) {
        $_SESSION['_started_at'] = $craftoolsApiNow;
    } elseif (($craftoolsApiNow - $_SESSION['_started_at']) > 28800) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['_started_at'] = $craftoolsApiNow;
    }

    $_SESSION['_last_seen'] = $craftoolsApiNow;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/images.php';

// Garante que o banco/schema existe desde a primeira requisição.
db();
