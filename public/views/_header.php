<?php
/** @var array $admin */
/** @var string $page */
$navItems = [
    ['key' => 'dashboard', 'icon' => 'space_dashboard', 'label' => 'Painel'],
    ['key' => 'users', 'icon' => 'group', 'label' => 'Usuários'],
    ['key' => 'tokens', 'icon' => 'key', 'label' => 'Tokens de API'],
    ['key' => 'grid_sizes', 'icon' => 'grid_view', 'label' => 'Tamanhos de Grid'],
    ['key' => 'album_templates', 'icon' => 'auto_stories', 'label' => 'Templates de Álbum'],
    ['key' => 'assets', 'icon' => 'image', 'label' => 'Overlays & Fundos'],
    ['key' => 'phrases', 'icon' => 'format_quote', 'label' => 'Banco de Frases'],
];
$pageTitles = [
    'dashboard' => ['Painel', 'Visão geral do sistema'],
    'users' => ['Usuários', 'Clientes cadastrados no CraftTools+'],
    'tokens' => ['Tokens de API', 'Chaves de acesso à API pública'],
    'grid_sizes' => ['Tamanhos de Grid', 'Catálogo de grids do editor de álbuns'],
    'album_templates' => ['Templates de Álbum', 'Modelos de capa e diagramação'],
    'assets' => ['Overlays & Fundos', 'Coleções de imagens de overlay e background'],
    'phrases' => ['Banco de Frases', 'Frases motivacionais por autor/categoria/idioma'],
];
$title = $pageTitles[$page][0] ?? 'CraftTools API';
$subtitle = $pageTitles[$page][1] ?? '';
?><!DOCTYPE html>
<html lang="pt-br" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> · CraftTools API</title>
<meta name="theme-color" content="#f97316">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Mono:wght@400;500&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,400&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidenav-panel" id="sidenav-panel">
        <div class="sidenav-head">
            <span class="material-symbols-outlined">photo_library</span>
            <div>
                <strong>CraftTools API</strong>
                <small>Painel administrativo</small>
            </div>
        </div>
        <ul class="sidenav-nav">
            <?php foreach ($navItems as $item): ?>
            <li><a href="index.php?page=<?= e($item['key']) ?>" class="<?= $page === $item['key'] ? 'active' : '' ?>">
                <span class="material-symbols-outlined"><?= e($item['icon']) ?></span>
                <?= e($item['label']) ?>
            </a></li>
            <?php endforeach; ?>
        </ul>
        <div class="sidenav-foot">
            <div class="text-muted" style="font-size:12px;margin-bottom:8px;">
                <?= e($admin['name'] ?? '') ?><br>
                <span style="font-size:11px;"><?= e($admin['email'] ?? '') ?></span>
            </div>
            <a href="index.php?page=logout" class="btn btn-outline btn-sm" style="width:100%;">
                <span class="material-symbols-outlined">logout</span> Sair
            </a>
        </div>
    </aside>

    <div style="flex:1; min-width:0; display:flex; flex-direction:column;">
        <header class="header-area">
            <div class="d-flex gap-2">
                <button class="box-icon" id="sidenav-toggle" type="button" aria-label="Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <div>
                    <h1><?= e($title) ?></h1>
                    <?php if ($subtitle): ?><div class="header-sub"><?= e($subtitle) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="header-content">
                <button class="box-icon" id="theme-toggle" type="button" aria-label="Alternar tema">
                    <span class="material-symbols-outlined" id="theme-toggle-icon">dark_mode</span>
                </button>
            </div>
        </header>
        <main class="main-content">
            <?php
            if (!empty($_SESSION['flash'])) {
                $flash = $_SESSION['flash'];
                unset($_SESSION['flash']);
                $cls = $flash['type'] === 'error' ? 'flash-error' : 'flash-success';
                $icon = $flash['type'] === 'error' ? 'error' : 'check_circle';
                echo '<div class="flash ' . $cls . '" data-autohide><span class="material-symbols-outlined">' . $icon . '</span>' . e($flash['msg']) . '</div>';
            }
            ?>
