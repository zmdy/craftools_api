<?php
/**
 * index.php — front controller do painel administrativo.
 * Único ponto de entrada para todas as telas internas (?page=...).
 */

require_once __DIR__ . '/../src/bootstrap.php';

// Se ainda não existe nenhuma conta de administrador ativa, o sistema não
// foi instalado: qualquer URL do painel (raiz, login, etc.) direciona
// automaticamente para o assistente de instalação. Some sozinho depois que
// a primeira conta é criada.
if (adminCountActive() === 0) {
    header('Location: install.php');
    exit;
}

$validPages = ['login', 'logout', 'dashboard', 'users', 'tokens', 'grid_sizes', 'album_templates', 'assets', 'phrases', 'phrases_csv_import', 'bulk_import'];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}

if ($page === 'logout') {
    adminLogout();
    header('Location: index.php?page=login');
    exit;
}

if ($page === 'login') {
    if (isAdminLoggedIn()) {
        header('Location: index.php?page=dashboard');
        exit;
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            $error = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            [$ok, $loginError] = adminAttemptLogin((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($ok) {
                header('Location: index.php?page=dashboard');
                exit;
            }
            $error = $loginError;
        }
    }
    applySecurityHeaders();
    require __DIR__ . '/views/login.php';
    exit;
}

// A partir daqui, todas as páginas exigem login de admin.
requireAdminLogin();
applySecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/actions.php';
}

$admin = currentAdmin();

require __DIR__ . '/views/_header.php';
require __DIR__ . '/views/' . $page . '.php';
require __DIR__ . '/views/_footer.php';
