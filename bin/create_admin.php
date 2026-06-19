<?php
/**
 * bin/create_admin.php — cria/atualiza uma conta de administrador do painel.
 *
 * Não existe senha padrão hardcoded (o projeto legado usava
 * MANAGER_PASSWORD=admin123 fixo). Cada conta tem hash de senha próprio
 * (Argon2id, com fallback para bcrypt em builds de PHP sem Argon2id).
 *
 * Uso interativo (recomendado — a senha não fica no histórico do shell):
 *     php bin/create_admin.php
 *
 * Uso não interativo (ex.: scripts de deploy):
 *     php bin/create_admin.php --name="Fulano" --email=fulano@exemplo.com --password="..." [--role=admin|editor]
 *
 * Se já existir uma conta com o e-mail informado, a senha (e o nome/role)
 * são atualizados em vez de criar um registro duplicado.
 */

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

function caArg(string $name): ?string {
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return substr($arg, strlen('--' . $name . '='));
        }
    }
    return null;
}

function caPrompt(string $label): string {
    echo $label;
    $value = trim((string) fgets(STDIN));
    return $value;
}

function caPromptPassword(string $label): string {
    echo $label;
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Sem suporte a "no echo" no cmd.exe puro — a senha aparece na tela.
        $value = trim((string) fgets(STDIN));
    } else {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
    return $value;
}

$name = caArg('name');
$email = caArg('email');
$password = caArg('password');
$role = caArg('role') ?: 'admin';

if ($name === null) {
    $name = caPrompt('Nome do administrador: ');
}
if ($email === null) {
    $email = caPrompt('E-mail (usado para login): ');
}
if ($password === null) {
    $password = caPromptPassword('Senha (mín. 10 caracteres, não aparece na tela): ');
    $confirm = caPromptPassword('Confirme a senha: ');
    if ($password !== $confirm) {
        fwrite(STDERR, "Erro: as senhas não coincidem.\n");
        exit(1);
    }
}

$email = strtolower(trim($email));
$name = trim($name);

if ($name === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Erro: nome, e-mail e senha são obrigatórios.\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Erro: e-mail inválido.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Erro: a senha deve ter ao menos 10 caracteres.\n");
    exit(1);
}
if (!in_array($role, ['admin', 'editor'], true)) {
    fwrite(STDERR, "Erro: role deve ser 'admin' ou 'editor'.\n");
    exit(1);
}

$hash = passwordHashNew($password);
$existing = adminFindByEmail($email);
$pdo = db();

if ($existing) {
    $pdo->prepare('UPDATE admin_users SET name = ?, password_hash = ?, role = ?, active = 1, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')
        ->execute([$name, $hash, $role, nowSql(), $existing['id']]);
    echo "Conta administrativa atualizada: {$email} (role: {$role}).\n";
} else {
    $pdo->prepare('INSERT INTO admin_users (uuid, name, email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
        ->execute([uuidv4(), $name, $email, $hash, $role, nowSql(), nowSql()]);
    echo "Conta administrativa criada: {$email} (role: {$role}).\n";
}

echo "Acesse o painel em /index.php?page=login com este e-mail e a senha definida.\n";
