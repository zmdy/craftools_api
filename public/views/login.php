<?php
/** @var string $error */
?><!DOCTYPE html>
<html lang="pt-br" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar · CraftTools API</title>
<meta name="theme-color" content="#f97316">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Mono:wght@400;500&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="login-shell">
    <div class="login-card">
        <div class="login-head">
            <span class="material-symbols-outlined">photo_library</span>
            <h1 style="font-size:18px;margin-top:8px;">CraftTools API</h1>
            <div style="font-size:12.5px;opacity:.9;">Painel administrativo</div>
        </div>
        <div class="login-body">
            <?php if (!empty($error)): ?>
                <div class="flash flash-error"><span class="material-symbols-outlined">error</span><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="index.php?page=login">
                <?= csrfField() ?>
                <div class="field">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required autofocus autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:6px;">
                    <span class="material-symbols-outlined">login</span> Entrar
                </button>
            </form>
            <div class="help-text" style="margin-top:14px;text-align:center;">
                Acesso restrito à equipe CraftTools. Conta criada via <code>bin/create_admin.php</code>.
            </div>
        </div>
    </div>
</div>
</body>
</html>
