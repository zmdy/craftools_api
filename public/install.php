<?php
/**
 * public/install.php
 *
 * Instalador web do CraftTools API — assistente em 3 etapas para configurar
 * o sistema sem precisar de acesso SSH/CLI ao servidor:
 *   1) verifica os requisitos do servidor (versão do PHP, extensões, storage/);
 *   2) gera o .env com as configurações básicas (fuso horário, CORS, rate limit);
 *   3) cria a primeira conta de administrador do painel.
 *
 * Uma vez que exista pelo menos uma conta de administrador ATIVA, este script
 * se bloqueia automaticamente (mostra só uma mensagem de "já instalado") —
 * mas, por segurança, o recomendado é excluí-lo do servidor depois do uso.
 *
 * Alternativa via linha de comando (sem usar este arquivo):
 *     cp .env.example .env   (e ajuste os valores manualmente)
 *     php bin/create_admin.php
 */

function ie($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$craftoolsApiRoot = dirname(__DIR__);
$craftoolsApiStorage = $craftoolsApiRoot . '/storage';

// -----------------------------------------------------------------------
// Etapa 0 — checagem de requisitos SEM depender de bootstrap.php: o próprio
// bootstrap tenta criar storage/logs e abrir/criar o banco SQLite, então
// precisamos confirmar que o básico funciona antes de arriscar essa carga
// (evita uma tela de erro fatal feia caso falte uma extensão ou permissão).
// -----------------------------------------------------------------------
$requirementChecks = [
    ['label' => 'PHP 7.2 ou superior', 'ok' => version_compare(PHP_VERSION, '7.2.0', '>='), 'detail' => 'Detectado: ' . PHP_VERSION],
    ['label' => 'Extensão pdo_sqlite', 'ok' => extension_loaded('pdo_sqlite'), 'detail' => ''],
    ['label' => 'Extensão gd', 'ok' => extension_loaded('gd'), 'detail' => ''],
    ['label' => 'Extensão fileinfo', 'ok' => extension_loaded('fileinfo'), 'detail' => ''],
    ['label' => 'Extensão mbstring', 'ok' => extension_loaded('mbstring'), 'detail' => ''],
    ['label' => 'Extensão json', 'ok' => extension_loaded('json'), 'detail' => ''],
    ['label' => 'Pasta storage/ existe e é gravável', 'ok' => is_dir($craftoolsApiStorage) && is_writable($craftoolsApiStorage), 'detail' => $craftoolsApiStorage],
];
$requirementsOk = true;
foreach ($requirementChecks as $check) {
    if (!$check['ok']) {
        $requirementsOk = false;
    }
}

if (!$requirementsOk) {
    // Cabeçalhos básicos mesmo sem security.php carregado ainda.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

$step = (string) ($_GET['step'] ?? 'requirements');
if (!in_array($step, ['requirements', 'environment', 'admin', 'done'], true)) {
    $step = 'requirements';
}
if (!$requirementsOk) {
    $step = 'requirements';
}

$error = '';
$alreadyInstalled = false;
$envValues = [
    'APP_DEBUG' => '0',
    'APP_TIMEZONE' => 'America/Sao_Paulo',
    'API_ALLOWED_ORIGIN' => '*',
    'API_RATE_LIMIT_PER_IP' => '120',
    'API_RATE_LIMIT_WINDOW_SECONDS' => '60',
];

if ($requirementsOk) {
    require_once __DIR__ . '/../src/bootstrap.php';
    applySecurityHeaders();

    // Trava de segurança: existindo uma conta de admin ativa, a instalação
    // já foi concluída — não há mais nada para este script fazer.
    $alreadyInstalled = adminCountActive() > 0;
    if ($alreadyInstalled) {
        $step = 'done';
    }

    foreach ($envValues as $key => $default) {
        $envValues[$key] = (string) env($key, $default);
    }

    if (!$alreadyInstalled && $step === 'environment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            $error = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            $posted = [
                'APP_DEBUG' => !empty($_POST['app_debug']) ? '1' : '0',
                'APP_TIMEZONE' => trim((string) ($_POST['app_timezone'] ?? '')),
                'API_ALLOWED_ORIGIN' => trim((string) ($_POST['api_allowed_origin'] ?? '')),
                'API_RATE_LIMIT_PER_IP' => trim((string) ($_POST['api_rate_limit_per_ip'] ?? '')),
                'API_RATE_LIMIT_WINDOW_SECONDS' => trim((string) ($_POST['api_rate_limit_window_seconds'] ?? '')),
            ];
            $envValues = array_merge($envValues, $posted);

            if (!in_array($posted['APP_TIMEZONE'], DateTimeZone::listIdentifiers(), true)) {
                $error = 'Fuso horário inválido.';
            } elseif ($posted['API_ALLOWED_ORIGIN'] === '' || preg_match('/[\r\n]/', $posted['API_ALLOWED_ORIGIN'])) {
                $error = 'Origem permitida (CORS) inválida.';
            } elseif (!is_numeric($posted['API_RATE_LIMIT_PER_IP']) || (int) $posted['API_RATE_LIMIT_PER_IP'] < 1) {
                $error = 'Limite de requisições por IP deve ser um número inteiro maior que zero.';
            } elseif (!is_numeric($posted['API_RATE_LIMIT_WINDOW_SECONDS']) || (int) $posted['API_RATE_LIMIT_WINDOW_SECONDS'] < 1) {
                $error = 'Janela do limite de requisições deve ser um número inteiro maior que zero (em segundos).';
            } else {
                $envLines = [
                    '# Gerado pelo instalador (install.php) em ' . gmdate('Y-m-d H:i:s') . ' UTC',
                    '# Pode ser editado manualmente a qualquer momento; o instalador não',
                    '# sobrescreve este arquivo de novo depois que uma conta de admin existir.',
                    '',
                    '# Exibe erros na tela (apenas em desenvolvimento local). 0 em produção.',
                    'APP_DEBUG=' . $posted['APP_DEBUG'],
                    '',
                    '# Fuso horário usado em timestamps gerados pela aplicação.',
                    'APP_TIMEZONE=' . $posted['APP_TIMEZONE'],
                    '',
                    '# Origem permitida para CORS nas APIs públicas (/api e /v1).',
                    'API_ALLOWED_ORIGIN=' . $posted['API_ALLOWED_ORIGIN'],
                    '',
                    '# Limites do rate limiter das APIs públicas.',
                    'API_RATE_LIMIT_PER_IP=' . (int) $posted['API_RATE_LIMIT_PER_IP'],
                    'API_RATE_LIMIT_WINDOW_SECONDS=' . (int) $posted['API_RATE_LIMIT_WINDOW_SECONDS'],
                    '',
                ];
                $written = @file_put_contents($craftoolsApiRoot . '/.env', implode("\n", $envLines));
                if ($written === false) {
                    $error = 'Não foi possível gravar o arquivo .env. Verifique as permissões da pasta raiz do projeto.';
                } else {
                    @chmod($craftoolsApiRoot . '/.env', 0640);
                    header('Location: install.php?step=admin');
                    exit;
                }
            }
        }
    }

    if (!$alreadyInstalled && $step === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf()) {
            $error = 'Sessão expirada. Atualize a página e tente novamente.';
        } else {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['password_confirm'] ?? '');

            if ($name === '' || $email === '' || $password === '') {
                $error = 'Nome, e-mail e senha são obrigatórios.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'E-mail inválido.';
            } elseif (strlen($password) < 10) {
                $error = 'A senha deve ter ao menos 10 caracteres.';
            } elseif ($password !== $confirm) {
                $error = 'As senhas não coincidem.';
            } else {
                $hash = passwordHashNew($password);
                db()->prepare('INSERT INTO admin_users (uuid, name, email, password_hash, role, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                    ->execute([uuidv4(), $name, $email, $hash, 'admin', nowSql(), nowSql()]);
                auditLog(null, 'install', 'admin_users', $email, 'Conta criada pelo instalador web (install.php).');
                header('Location: install.php?step=done');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instalação · CraftTools API</title>
<meta name="theme-color" content="#f97316">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Mono:wght@400;500&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
<style>
  .install-shell { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .install-card { width: 100%; max-width: 560px; }
  .install-head { background-image: linear-gradient(135deg, #f97316 0%, #ea580c 100%); color: #fff; padding: 26px 28px; text-align: center; border-radius: 18px 18px 0 0; }
  .install-head .material-symbols-outlined { font-size: 32px; }
  .install-steps { display: flex; gap: 6px; justify-content: center; margin-top: 14px; }
  .install-steps span { width: 26px; height: 4px; border-radius: 99px; background: rgba(255,255,255,.35); }
  .install-steps span.active { background: #fff; }
  .install-body { background: var(--bg-shell); border: 1px solid var(--border); border-top: none; border-radius: 0 0 18px 18px; padding: 26px 28px; box-shadow: var(--shadow-card); }
  .req-list { list-style: none; margin: 0 0 18px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .req-list li { display: flex; align-items: flex-start; gap: 10px; font-size: 13.5px; }
  .req-list .material-symbols-outlined { font-size: 18px; margin-top: 1px; }
  .req-ok { color: var(--success); }
  .req-fail { color: var(--danger); }
  .req-detail { color: var(--text-muted); font-size: 11.5px; }
  .install-warning { background: rgba(245,158,11,.12); color: #b45309; border-radius: 10px; padding: 10px 14px; font-size: 12.5px; margin-bottom: 18px; display: flex; gap: 8px; align-items: flex-start; }
  [data-theme="dark"] .install-warning { color: #fbbf24; }
</style>
</head>
<body>
<div class="install-shell">
  <div class="install-card">
    <div class="install-head">
      <span class="material-symbols-outlined">rocket_launch</span>
      <h1 style="font-size:18px;margin-top:8px;">Instalação do CraftTools API</h1>
      <?php if (in_array($step, ['requirements', 'environment', 'admin'], true)): ?>
      <div class="install-steps">
        <span class="<?= $step === 'requirements' ? 'active' : '' ?>"></span>
        <span class="<?= $step === 'environment' ? 'active' : '' ?>"></span>
        <span class="<?= $step === 'admin' ? 'active' : '' ?>"></span>
      </div>
      <?php endif; ?>
    </div>
    <div class="install-body">

      <?php if (!empty($error)): ?>
        <div class="flash flash-error"><span class="material-symbols-outlined">error</span><?= ie($error) ?></div>
      <?php endif; ?>

      <?php if ($step === 'requirements'): ?>
        <?php if (!$requirementsOk): ?>
          <div class="install-warning">
            <span class="material-symbols-outlined">warning</span>
            <div>Alguns requisitos do servidor não foram atendidos. Ajuste o ambiente e clique em "Verificar novamente".</div>
          </div>
        <?php endif; ?>
        <ul class="req-list">
          <?php foreach ($requirementChecks as $check): ?>
            <li>
              <span class="material-symbols-outlined <?= $check['ok'] ? 'req-ok' : 'req-fail' ?>"><?= $check['ok'] ? 'check_circle' : 'cancel' ?></span>
              <div>
                <?= ie($check['label']) ?>
                <?php if ($check['detail'] !== ''): ?><div class="req-detail"><?= ie($check['detail']) ?></div><?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($requirementsOk): ?>
          <a href="install.php?step=environment" class="btn btn-primary" style="width:100%;">
            <span class="material-symbols-outlined">arrow_forward</span> Continuar
          </a>
        <?php else: ?>
          <a href="install.php?step=requirements" class="btn btn-outline" style="width:100%;">
            <span class="material-symbols-outlined">refresh</span> Verificar novamente
          </a>
        <?php endif; ?>

      <?php elseif ($step === 'environment'): ?>
        <div class="install-warning">
          <span class="material-symbols-outlined">info</span>
          <div>Esta página fica acessível a quem souber a URL até que a primeira conta de administrador seja criada. Conclua a instalação agora e depois exclua <code>install.php</code> do servidor.</div>
        </div>
        <form method="post" action="install.php?step=environment">
          <?= csrfField() ?>
          <div class="field">
            <label for="app_timezone">Fuso horário</label>
            <input type="text" id="app_timezone" name="app_timezone" value="<?= ie($envValues['APP_TIMEZONE']) ?>" required>
            <div class="help-text">Identificador IANA, ex: America/Sao_Paulo</div>
          </div>
          <div class="field">
            <label for="api_allowed_origin">Origem permitida (CORS) das APIs públicas</label>
            <input type="text" id="api_allowed_origin" name="api_allowed_origin" value="<?= ie($envValues['API_ALLOWED_ORIGIN']) ?>" required>
            <div class="help-text">Domínio do seu PWA em produção (ex: https://app.seudominio.com) ou "*" para permitir qualquer origem.</div>
          </div>
          <div class="field-row">
            <div class="field">
              <label for="api_rate_limit_per_ip">Requisições por IP</label>
              <input type="number" min="1" id="api_rate_limit_per_ip" name="api_rate_limit_per_ip" value="<?= ie($envValues['API_RATE_LIMIT_PER_IP']) ?>" required>
            </div>
            <div class="field">
              <label for="api_rate_limit_window_seconds">Janela (segundos)</label>
              <input type="number" min="1" id="api_rate_limit_window_seconds" name="api_rate_limit_window_seconds" value="<?= ie($envValues['API_RATE_LIMIT_WINDOW_SECONDS']) ?>" required>
            </div>
          </div>
          <div class="checkbox-row field">
            <input type="checkbox" id="app_debug" name="app_debug" <?= $envValues['APP_DEBUG'] === '1' ? 'checked' : '' ?>>
            <label class="mb-0" for="app_debug">Exibir erros na tela (apenas para depuração local — mantenha desligado em produção)</label>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;">
            <span class="material-symbols-outlined">arrow_forward</span> Salvar e continuar
          </button>
        </form>

      <?php elseif ($step === 'admin'): ?>
        <div class="install-warning">
          <span class="material-symbols-outlined">info</span>
          <div>Esta é a primeira (e principal) conta de administrador do painel. Contas adicionais podem ser criadas depois com <code>php bin/create_admin.php</code>.</div>
        </div>
        <form method="post" action="install.php?step=admin">
          <?= csrfField() ?>
          <div class="field">
            <label for="name">Nome</label>
            <input type="text" id="name" name="name" required autofocus value="<?= ie($_POST['name'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autocomplete="username" value="<?= ie($_POST['email'] ?? '') ?>">
          </div>
          <div class="field-row">
            <div class="field">
              <label for="password">Senha</label>
              <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="field">
              <label for="password_confirm">Confirme a senha</label>
              <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
          </div>
          <div class="help-text" style="margin-bottom:14px;">Mínimo de 10 caracteres.</div>
          <button type="submit" class="btn btn-primary" style="width:100%;">
            <span class="material-symbols-outlined">person_add</span> Criar conta de administrador
          </button>
        </form>

      <?php else /* done */: ?>
        <div class="flash flash-success">
          <span class="material-symbols-outlined">check_circle</span>
          <?= $alreadyInstalled ? 'O CraftTools API já está instalado.' : 'Instalação concluída com sucesso!' ?>
        </div>
        <p style="font-size:13.5px;color:var(--text-secondary);line-height:1.6;">
          Sua conta de administrador está pronta. Por segurança, <strong>exclua o arquivo <code>install.php</code> do servidor agora</strong> —
          ele não tem mais nenhuma ação a executar e não deve ficar acessível publicamente.
        </p>
        <a href="index.php?page=login" class="btn btn-primary" style="width:100%;">
          <span class="material-symbols-outlined">login</span> Ir para o login
        </a>
      <?php endif; ?>

    </div>
  </div>
</div>
</body>
</html>
