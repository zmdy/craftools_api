<?php
/**
 * actions.php — processa todos os POSTs do painel (criação/edição/exclusão).
 * Incluído por index.php ANTES de qualquer saída, para permitir redirect.
 * Toda ação aqui exige sessão de admin válida (garantida em index.php) e
 * token CSRF válido.
 */

requireCsrf();

$adminId = (int) ($_SESSION['admin_id'] ?? 0);

function flashRedirect(string $type, string $msg, string $location): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . $location);
    exit;
}

$action = (string) ($_POST['_action'] ?? '');

try {
    if ($action === 'sync_github') {
        $result = syncSystemFromGithub();
        auditLog($adminId, 'sync_github', 'system', 'GitHub main branch update');
        $backTo = 'index.php?page=' . (in_array($page, ['login', 'logout'], true) ? 'dashboard' : $page);
        flashRedirect('success', $result['message'], $backTo);
    }

    switch ($page) {

        // ---------------------------------------------------------------- users
        case 'users':
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $data = $_POST;
                if (empty($data['email']) || empty($data['name'])) {
                    flashRedirect('error', 'Nome e e-mail são obrigatórios.', 'index.php?page=users');
                }
                if ($id > 0) {
                    appUserUpdate($id, $data);
                    auditLog($adminId, 'update', 'app_users', (string) $id);
                    flashRedirect('success', 'Usuário atualizado.', 'index.php?page=users');
                }
                $existing = appUserFindByEmail($data['email']);
                if ($existing) {
                    flashRedirect('error', 'Já existe um usuário com este e-mail.', 'index.php?page=users');
                }
                $newId = appUserCreate($data);
                auditLog($adminId, 'create', 'app_users', (string) $newId);
                flashRedirect('success', 'Usuário criado.', 'index.php?page=users');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                appUserDelete($id);
                auditLog($adminId, 'delete', 'app_users', (string) $id);
                flashRedirect('success', 'Usuário removido.', 'index.php?page=users');
            }
            break;

        // --------------------------------------------------------------- tokens
        case 'tokens':
            if ($action === 'create') {
                $userId = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;
                $result = apiTokenCreate($userId, trim((string) ($_POST['label'] ?? '')), (string) ($_POST['tier'] ?? 'free'), $_POST['expires_at'] !== '' ? $_POST['expires_at'] : null);
                auditLog($adminId, 'create', 'api_tokens', (string) $result['id']);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Token criado com sucesso. Copie agora — ele não será exibido novamente.'];
                $_SESSION['reveal_token'] = $result['raw_token'];
                header('Location: index.php?page=tokens');
                exit;
            }
            if ($action === 'toggle') {
                $id = (int) ($_POST['id'] ?? 0);
                apiTokenToggle($id, !empty($_POST['active']));
                auditLog($adminId, 'update', 'api_tokens', (string) $id);
                flashRedirect('success', 'Token atualizado.', 'index.php?page=tokens');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                apiTokenDelete($id);
                auditLog($adminId, 'delete', 'api_tokens', (string) $id);
                flashRedirect('success', 'Token removido.', 'index.php?page=tokens');
            }
            break;

        // ----------------------------------------------------------- grid_sizes
        case 'grid_sizes':
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($_POST['name']) || empty($_POST['tier'])) {
                    flashRedirect('error', 'Nome e tier são obrigatórios.', 'index.php?page=grid_sizes');
                }
                if ($id > 0) {
                    gridSizeUpdate($id, $_POST);
                    auditLog($adminId, 'update', 'grid_sizes', (string) $id);
                    flashRedirect('success', 'Tamanho de grid atualizado.', 'index.php?page=grid_sizes');
                }
                $newId = gridSizeCreate($_POST);
                auditLog($adminId, 'create', 'grid_sizes', (string) $newId);
                flashRedirect('success', 'Tamanho de grid criado.', 'index.php?page=grid_sizes');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                gridSizeDelete($id);
                auditLog($adminId, 'delete', 'grid_sizes', (string) $id);
                flashRedirect('success', 'Tamanho de grid removido.', 'index.php?page=grid_sizes');
            }
            break;

        // ------------------------------------------------------- album_templates
        case 'album_templates':
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($_POST['name']) || empty($_POST['tier'])) {
                    flashRedirect('error', 'Nome e tier são obrigatórios.', 'index.php?page=album_templates');
                }
                if (!empty($_POST['layout_json'])) {
                    json_decode($_POST['layout_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        flashRedirect('error', 'JSON do layout é inválido.', 'index.php?page=album_templates');
                    }
                }
                if ($id > 0) {
                    albumTemplateUpdate($id, $_POST);
                    auditLog($adminId, 'update', 'album_templates', (string) $id);
                    flashRedirect('success', 'Template atualizado.', 'index.php?page=album_templates');
                }
                $newId = albumTemplateCreate($_POST);
                auditLog($adminId, 'create', 'album_templates', (string) $newId);
                flashRedirect('success', 'Template criado.', 'index.php?page=album_templates');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                albumTemplateDelete($id);
                auditLog($adminId, 'delete', 'album_templates', (string) $id);
                flashRedirect('success', 'Template removido.', 'index.php?page=album_templates');
            }
            break;

        // ------------------------------------------------------- upload_links
        case 'upload_links':
            if ($action === 'create') {
                $clientName = trim((string) ($_POST['client_name'] ?? ''));
                $gridSizeId = !empty($_POST['grid_size_id']) ? (int) $_POST['grid_size_id'] : null;
                $notes = trim((string) ($_POST['notes'] ?? ''));
                $photoCount = intInput($_POST, 'photo_count', 0, 0, 500);

                if ($clientName === '') {
                    flashRedirect('error', 'O nome do cliente é obrigatório.', 'index.php?page=upload_links');
                }
                if ($gridSizeId === null || !gridSizeFind($gridSizeId)) {
                    flashRedirect('error', 'Selecione um kit (tamanho de grid) válido.', 'index.php?page=upload_links');
                }

                $result = uploadLinkCreate($clientName, $gridSizeId, $photoCount, $notes, $adminId);
                auditLog($adminId, 'create', 'upload_links', (string) $result['id']);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Link criado com sucesso. Copie agora e envie para o cliente.'];
                // Só o token bruto é guardado na sessão (flash, uma leitura só) -- a
                // view monta a URL completa em JS (admin.js: buildUploadLink()).
                $_SESSION['reveal_upload_link'] = $result['raw_token'];
                header('Location: index.php?page=upload_links');
                exit;
            }
            if ($action === 'reopen') {
                $id = (int) ($_POST['id'] ?? 0);
                uploadLinkReopen($id);
                auditLog($adminId, 'update', 'upload_links', (string) $id);
                flashRedirect('success', 'Link reaberto para o cliente enviar novamente.', 'index.php?page=upload_links');
            }
            if ($action === 'regenerate') {
                $id = (int) ($_POST['id'] ?? 0);
                $result = uploadLinkRegenerateToken($id);
                auditLog($adminId, 'update', 'upload_links', (string) $id, 'token regenerated');
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Novo link gerado. O link anterior parou de funcionar.'];
                $_SESSION['reveal_upload_link'] = $result['raw_token'];
                // Volta para a mesma tela de detalhe quando o botão foi clicado
                // a partir dela (redirect_view), em vez de sempre cair na lista.
                $redirectView = (string) ($_POST['redirect_view'] ?? '');
                $location = $redirectView !== ''
                    ? 'index.php?page=upload_links&view=' . urlencode($redirectView)
                    : 'index.php?page=upload_links';
                header('Location: ' . $location);
                exit;
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                uploadLinkDelete($id);
                auditLog($adminId, 'delete', 'upload_links', (string) $id);
                flashRedirect('success', 'Link e fotos enviadas removidos.', 'index.php?page=upload_links');
            }
            break;

        // -------------------------------------------------------------- phrases
        case 'phrases':
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($_POST['phrase']) || empty($_POST['tier'])) {
                    flashRedirect('error', 'O texto da frase e o tier são obrigatórios.', 'index.php?page=phrases');
                }
                // Coleção: campo livre (escolhe uma existente ou digita uma nova).
                // Vazio remove o vínculo de coleção da frase.
                $collectionName = trim((string) ($_POST['collection'] ?? ''));
                $collectionId   = $collectionName !== '' ? phraseCollectionFindOrCreateByName($collectionName) : null;

                if ($id > 0) {
                    phraseUpdate($id, $_POST);
                    phraseSetCollection($id, $collectionId);
                    auditLog($adminId, 'update', 'phrases', (string) $id);
                    flashRedirect('success', 'Frase atualizada.', 'index.php?page=phrases');
                }
                $newId = phraseCreate($_POST);
                phraseSetCollection($newId, $collectionId);
                auditLog($adminId, 'create', 'phrases', (string) $newId);
                flashRedirect('success', 'Frase criada.', 'index.php?page=phrases');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                phraseDelete($id);
                auditLog($adminId, 'delete', 'phrases', (string) $id);
                flashRedirect('success', 'Frase removida.', 'index.php?page=phrases');
            }
            // Modificação em massa: só os campos com a caixa "Alterar" marcada
            // entram em $changes -- os demais permanecem intocados nas frases
            // selecionadas (ver phraseBulkUpdate() em repo.php).
            if ($action === 'bulk_update') {
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $changes = [];
                if (!empty($_POST['apply_tier']) && !empty($_POST['tier'])) {
                    $changes['tier'] = (string) $_POST['tier'];
                }
                if (!empty($_POST['apply_language']) && !empty($_POST['language'])) {
                    $changes['language'] = (string) $_POST['language'];
                }
                if (!empty($_POST['apply_category'])) {
                    $changes['category'] = (string) ($_POST['category'] ?? '');
                }
                if (!empty($_POST['apply_collection'])) {
                    $changes['collection'] = trim((string) ($_POST['collection'] ?? ''));
                }
                if (!$ids || !$changes) {
                    flashRedirect('error', 'Selecione ao menos uma frase e um campo para alterar em massa.', 'index.php?page=phrases');
                }
                $count = phraseBulkUpdate($ids, $changes);
                auditLog($adminId, 'bulk_update', 'phrases', implode(',', $ids));
                flashRedirect('success', $count . ' frase(s) atualizada(s) em massa.', 'index.php?page=phrases');
            }
            break;

        // ----------------------------------------------------- phrase_collections
        case 'phrase_collections':
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    flashRedirect('error', 'O nome da coleção é obrigatório.', 'index.php?page=phrase_collections');
                }
                $description = (string) ($_POST['description'] ?? '');
                $active = !empty($_POST['active']);
                if ($id > 0) {
                    phraseCollectionUpdate($id, $name, $description, $active);
                    auditLog($adminId, 'update', 'phrase_collections', (string) $id);
                    flashRedirect('success', 'Coleção atualizada.', 'index.php?page=phrase_collections');
                }
                $newId = phraseCollectionCreate($name, $description);
                auditLog($adminId, 'create', 'phrase_collections', (string) $newId);
                flashRedirect('success', 'Coleção criada.', 'index.php?page=phrase_collections');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                phraseCollectionDelete($id);
                auditLog($adminId, 'delete', 'phrase_collections', (string) $id);
                flashRedirect('success', 'Coleção removida (as frases não foram excluídas).', 'index.php?page=phrase_collections');
            }
            break;

        // --------------------------------------------------------------- assets
        case 'assets':
            $backTo = !empty($_POST['collection_id']) ? 'index.php?page=assets&collection=' . (int) $_POST['collection_id'] : 'index.php?page=assets';

            if ($action === 'collection_save') {
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($_POST['type']) || empty($_POST['tier'])) {
                    flashRedirect('error', 'Tipo e tier são obrigatórios.', 'index.php?page=assets');
                }
                if ($id > 0) {
                    assetCollectionUpdate($id, $_POST);
                    auditLog($adminId, 'update', 'asset_collections', (string) $id);
                    flashRedirect('success', 'Coleção atualizada.', 'index.php?page=assets');
                }
                $newId = assetCollectionCreate($_POST);
                auditLog($adminId, 'create', 'asset_collections', (string) $newId);
                flashRedirect('success', 'Coleção criada.', 'index.php?page=assets');
            }

            if ($action === 'collection_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $col = assetCollectionFind($id);
                if ($col) {
                    $dir = CRAFTOOLS_API_ROOT . '/public/v1/assets/' . $col['uuid'];
                    removeDirRecursive($dir);
                }
                assetCollectionDelete($id);
                auditLog($adminId, 'delete', 'asset_collections', (string) $id);
                flashRedirect('success', 'Coleção e imagens removidas.', 'index.php?page=assets');
            }

            // A importação em massa (tela "bulk_import") não passa mais por aqui:
            // ela usa public/bulk_import_ajax.php, em lotes pequenos via AJAX, o
            // que permite mostrar uma barra de progresso real em vez de uma única
            // requisição síncrona. Note também que esta ação nunca era executada
            // de qualquer forma — $page vale 'bulk_import' nesse POST, e não havia
            // "case 'bulk_import':" neste switch, só "case 'assets':".

            if ($action === 'image_upload') {
                $collectionId = (int) ($_POST['collection_id'] ?? 0);
                $col = assetCollectionFind($collectionId);
                if (!$col) {
                    flashRedirect('error', 'Coleção inválida.', 'index.php?page=assets');
                }
                if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                    flashRedirect('error', 'Selecione um arquivo de imagem.', $backTo);
                }
                $imgUuid = uuidv4();
                $destPath = CRAFTOOLS_API_ROOT . '/public/v1/assets/' . $col['uuid'] . '/' . $imgUuid . '.webp';
                $meta = handleImageUpload($_FILES['image'], $destPath);
                $newId = assetImageCreate([
                    'collection_id' => $collectionId,
                    'original_name' => $_FILES['image']['name'],
                    'file_path' => 'v1/assets/' . $col['uuid'] . '/' . $imgUuid . '.webp',
                    'width' => $meta['width'],
                    'height' => $meta['height'],
                    'size_bytes' => $meta['size_bytes'],
                    'comment' => $_POST['comment'] ?? '',
                    'tier' => $_POST['tier'] ?? $col['tier'],
                ]);
                // Usa o mesmo uuid gerado para nome de arquivo e registro, mantendo consistência.
                db()->prepare('UPDATE asset_images SET uuid = ? WHERE id = ?')->execute([$imgUuid, $newId]);
                auditLog($adminId, 'create', 'asset_images', (string) $newId);
                flashRedirect('success', 'Imagem enviada e convertida para WebP.', $backTo);
            }

            if ($action === 'image_update') {
                $id = (int) ($_POST['id'] ?? 0);
                assetImageUpdate($id, $_POST);
                auditLog($adminId, 'update', 'asset_images', (string) $id);
                flashRedirect('success', 'Imagem atualizada.', $backTo);
            }

            if ($action === 'image_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $img = assetImageFind($id);
                if ($img && !empty($img['file_path'])) {
                    $full = CRAFTOOLS_API_ROOT . '/public/' . $img['file_path'];
                    assertPathInsideBase(dirname($full), CRAFTOOLS_API_ROOT . '/public/v1/assets');
                    @unlink($full);
                }
                assetImageDelete($id);
                auditLog($adminId, 'delete', 'asset_images', (string) $id);
                flashRedirect('success', 'Imagem removida.', $backTo);
            }
            break;

        // --------------------------------------------------------------- shapes
        case 'shapes':
            $backTo = !empty($_POST['collection_id']) ? 'index.php?page=shapes&collection=' . (int) $_POST['collection_id'] : 'index.php?page=shapes';

            if ($action === 'collection_save') {
                $id = (int) ($_POST['id'] ?? 0);
                if (empty($_POST['tier'])) {
                    flashRedirect('error', 'Tier é obrigatório.', 'index.php?page=shapes');
                }
                if ($id > 0) {
                    shapeCollectionUpdate($id, $_POST);
                    auditLog($adminId, 'update', 'shape_collections', (string) $id);
                    flashRedirect('success', 'Coleção atualizada.', 'index.php?page=shapes');
                }
                $newId = shapeCollectionCreate($_POST);
                auditLog($adminId, 'create', 'shape_collections', (string) $newId);
                flashRedirect('success', 'Coleção criada.', 'index.php?page=shapes');
            }

            if ($action === 'collection_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $col = shapeCollectionFind($id);
                if ($col) {
                    $dir = CRAFTOOLS_API_ROOT . '/public/v1/shapes/' . $col['uuid'];
                    removeDirRecursive($dir);
                }
                shapeCollectionDelete($id);
                auditLog($adminId, 'delete', 'shape_collections', (string) $id);
                flashRedirect('success', 'Coleção e shapes removidos.', 'index.php?page=shapes');
            }

            if ($action === 'shape_upload') {
                $collectionId = (int) ($_POST['collection_id'] ?? 0);
                $col = shapeCollectionFind($collectionId);
                if (!$col) {
                    flashRedirect('error', 'Coleção inválida.', 'index.php?page=shapes');
                }
                if (empty($_FILES['shape']) || $_FILES['shape']['error'] === UPLOAD_ERR_NO_FILE) {
                    flashRedirect('error', 'Selecione um arquivo SVG.', $backTo);
                }
                try {
                    $shapeUuid = uuidv4();
                    $destPath = CRAFTOOLS_API_ROOT . '/public/v1/shapes/' . $col['uuid'] . '/' . $shapeUuid . '.svg';
                    $meta = handleSvgUpload($_FILES['shape'], $destPath);
                    $newId = shapeAssetCreate([
                        'collection_id' => $collectionId,
                        'original_name' => $_FILES['shape']['name'],
                        'file_path' => 'v1/shapes/' . $col['uuid'] . '/' . $shapeUuid . '.svg',
                        'size_bytes' => $meta['size_bytes'],
                        'comment' => $_POST['comment'] ?? '',
                        'tier' => $_POST['tier'] ?? $col['tier'],
                    ]);
                    // Usa o mesmo uuid gerado para nome de arquivo e registro, mantendo consistência.
                    db()->prepare('UPDATE shape_assets SET uuid = ? WHERE id = ?')->execute([$shapeUuid, $newId]);
                    auditLog($adminId, 'create', 'shape_assets', (string) $newId);
                    flashRedirect('success', 'Shape enviado e sanitizado.', $backTo);
                } catch (RuntimeException $ex) {
                    flashRedirect('error', $ex->getMessage(), $backTo);
                }
            }

            if ($action === 'shape_update') {
                $id = (int) ($_POST['id'] ?? 0);
                shapeAssetUpdate($id, $_POST);
                auditLog($adminId, 'update', 'shape_assets', (string) $id);
                flashRedirect('success', 'Shape atualizado.', $backTo);
            }

            if ($action === 'shape_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $shape = shapeAssetFind($id);
                if ($shape && !empty($shape['file_path'])) {
                    $full = CRAFTOOLS_API_ROOT . '/public/' . $shape['file_path'];
                    assertPathInsideBase(dirname($full), CRAFTOOLS_API_ROOT . '/public/v1/shapes');
                    @unlink($full);
                }
                shapeAssetDelete($id);
                auditLog($adminId, 'delete', 'shape_assets', (string) $id);
                flashRedirect('success', 'Shape removido.', $backTo);
            }

            // Importação em lote a partir de assets/original/shapes/{pack}/*.svg —
            // síncrona (diferente do importador de imagens em bulk_import_ajax.php,
            // que processa em lotes via AJAX): shapes SVG são arquivos de texto
            // pequenos (dezenas de KB no total por pack), então uma única
            // requisição é rápida o bastante para não precisar de barra de
            // progresso/lotes.
            if ($action === 'shapes_bulk_import') {
                $baseDir = CRAFTOOLS_API_ROOT . '/assets/original/shapes';
                if (!is_dir($baseDir)) {
                    flashRedirect('error', 'Pasta assets/original/shapes/ não encontrada no servidor.', 'index.php?page=shapes');
                }

                $imported = 0;
                $skipped = 0;
                $errors = [];

                foreach (new DirectoryIterator($baseDir) as $dirInfo) {
                    if ($dirInfo->isDot() || !$dirInfo->isDir()) {
                        continue;
                    }
                    $packName = $dirInfo->getFilename();
                    $originalPath = 'assets/original/shapes/' . $packName;

                    $col = shapeCollectionFindByOriginalPath($originalPath);
                    if (!$col) {
                        $colId = shapeCollectionCreate([
                            'name' => $packName,
                            'comment' => $packName,
                            'original_path' => $originalPath,
                            'tier' => 'free',
                            'sort_order' => 0,
                            'active' => 1,
                        ]);
                        auditLog($adminId, 'create', 'shape_collections', (string) $colId);
                        $col = shapeCollectionFind($colId);
                    }

                    // Reimportar não deve duplicar: pula qualquer arquivo cujo
                    // original_name já exista nesta coleção.
                    $already = array_column(shapeAssetsByCollection($col['id']), null, 'original_name');

                    foreach (new DirectoryIterator($dirInfo->getPathname()) as $fileInfo) {
                        if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                            continue;
                        }
                        if (strtolower($fileInfo->getExtension()) !== 'svg') {
                            continue;
                        }
                        $fileName = $fileInfo->getFilename();
                        if (isset($already[$fileName])) {
                            $skipped++;
                            continue;
                        }

                        try {
                            $raw = file_get_contents($fileInfo->getPathname());
                            if ($raw === false) {
                                throw new RuntimeException('Falha ao ler o arquivo.');
                            }
                            $shapeUuid = uuidv4();
                            $destPath = CRAFTOOLS_API_ROOT . '/public/v1/shapes/' . $col['uuid'] . '/' . $shapeUuid . '.svg';
                            $meta = writeSanitizedSvg($raw, $destPath);
                            $newId = shapeAssetCreate([
                                'collection_id' => (int) $col['id'],
                                'original_name' => $fileName,
                                'file_path' => 'v1/shapes/' . $col['uuid'] . '/' . $shapeUuid . '.svg',
                                'size_bytes' => $meta['size_bytes'],
                                'comment' => '',
                                'tier' => 'free',
                            ]);
                            db()->prepare('UPDATE shape_assets SET uuid = ? WHERE id = ?')->execute([$shapeUuid, $newId]);
                            auditLog($adminId, 'create', 'shape_assets', (string) $newId);
                            $imported++;
                        } catch (Throwable $ex) {
                            $errors[] = $packName . '/' . $fileName . ': ' . $ex->getMessage();
                        }
                    }
                }

                $msg = "Importação concluída: {$imported} shape(s) importado(s), {$skipped} já existiam.";
                if ($errors) {
                    $msg .= ' Erros: ' . implode(' | ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' …' : '');
                    flashRedirect('error', $msg, 'index.php?page=shapes');
                }
                flashRedirect('success', $msg, 'index.php?page=shapes');
            }
            break;

        // --------------------------------------------------------- fonts
        case 'fonts':
            if ($action === 'font_family_save') {
                $id = (int) ($_POST['id'] ?? 0);
                $d = [
                    'name' => $_POST['name'] ?? '',
                    'category' => $_POST['category'] ?? 'sans',
                    'tier' => $_POST['tier'] ?? 'free',
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                    'active' => !empty($_POST['active']),
                ];
                if ($id > 0) {
                    fontFamilyUpdate($id, $d);
                    auditLog($adminId, 'update', 'font_families', (string) $id);
                    flashRedirect('success', 'Família de fontes atualizada.', 'index.php?page=fonts');
                } else {
                    $newId = fontFamilyCreate($d);
                    auditLog($adminId, 'create', 'font_families', (string) $newId);
                    flashRedirect('success', 'Família de fontes criada.', 'index.php?page=fonts');
                }
            }

            if ($action === 'font_family_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $files = fontFilesByFamily($id);
                foreach ($files as $f) {
                    fontFileDelete($f['id']);
                }
                fontFamilyDelete($id);
                auditLog($adminId, 'delete', 'font_families', (string) $id);
                flashRedirect('success', 'Família de fontes e seus arquivos removidos.', 'index.php?page=fonts');
            }

            if ($action === 'font_file_upload') {
                $familyId = (int) ($_POST['family_id'] ?? 0);
                $family = fontFamilyFind($familyId);
                if (!$family) {
                    flashRedirect('error', 'Família de fonte inválida.', 'index.php?page=fonts');
                }
                if (empty($_FILES['font_file']) || $_FILES['font_file']['error'] === UPLOAD_ERR_NO_FILE) {
                    flashRedirect('error', 'Selecione um arquivo de fonte (.ttf, .otf, .woff ou .woff2).', $backTo);
                }
                try {
                    $ext = strtolower(pathinfo($_FILES['font_file']['name'], PATHINFO_EXTENSION));
                    $fileUuid = uuidv4();
                    $relPath = 'v1/fonts/' . $family['uuid'] . '/' . $fileUuid . '.' . $ext;
                    $destPath = CRAFTOOLS_API_ROOT . '/public/' . $relPath;

                    require_once CRAFTOOLS_API_ROOT . '/src/fonts.php';
                    $meta = handleFontUpload($_FILES['font_file'], $destPath);

                    $newId = fontFileCreate([
                        'family_id' => $familyId,
                        'weight' => (int) ($_POST['weight'] ?? 400),
                        'style' => $_POST['style'] ?? 'normal',
                        'format' => $meta['format'],
                        'file_path' => $relPath,
                        'size_bytes' => $meta['size_bytes'],
                    ]);
                    db()->prepare('UPDATE font_files SET uuid = ? WHERE id = ?')->execute([$fileUuid, $newId]);

                    auditLog($adminId, 'create', 'font_files', (string) $newId);
                    flashRedirect('success', 'Arquivo de fonte enviado com sucesso.', $backTo);
                } catch (RuntimeException $ex) {
                    flashRedirect('error', $ex->getMessage(), $backTo);
                }
            }

            if ($action === 'font_file_delete') {
                $id = (int) ($_POST['id'] ?? 0);
                fontFileDelete($id);
                auditLog($adminId, 'delete', 'font_files', (string) $id);
                flashRedirect('success', 'Arquivo de fonte removido.', $backTo);
            }
            break;

        // --------------------------------------------------------- calendar_dates
        case 'calendar_dates':
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $category = (string) ($_POST['category'] ?? '');
                $month = (int) ($_POST['month'] ?? 0);
                $day = (int) ($_POST['day'] ?? 0);
                $title = trim((string) ($_POST['title'] ?? ''));

                if (!in_array($category, CALENDAR_ENTRY_CATEGORIES, true)) {
                    flashRedirect('error', 'Categoria inválida.', 'index.php?page=calendar_dates');
                }
                if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                    flashRedirect('error', 'Mês/dia inválidos.', 'index.php?page=calendar_dates');
                }
                if ($title === '') {
                    flashRedirect('error', 'O título é obrigatório.', 'index.php?page=calendar_dates');
                }
                if ($category === 'event' && trim((string) ($_POST['year'] ?? '')) === '') {
                    flashRedirect('error', 'Eventos históricos exigem o ano.', 'index.php?page=calendar_dates');
                }

                if ($id > 0) {
                    calendarEntryUpdate($id, $_POST);
                    auditLog($adminId, 'update', 'calendar_entries', (string) $id);
                    flashRedirect('success', 'Registro atualizado.', 'index.php?page=calendar_dates');
                }
                $newId = calendarEntryCreate($_POST);
                auditLog($adminId, 'create', 'calendar_entries', (string) $newId);
                flashRedirect('success', 'Registro criado.', 'index.php?page=calendar_dates');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                calendarEntryDelete($id);
                auditLog($adminId, 'delete', 'calendar_entries', (string) $id);
                flashRedirect('success', 'Registro removido.', 'index.php?page=calendar_dates');
            }
            break;
    }
} catch (RuntimeException $ex) {
    flashRedirect('error', $ex->getMessage(), 'index.php?page=' . $page);
}

/** Remove recursivamente uma pasta de assets (usado ao excluir uma coleção). */
function removeDirRecursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? removeDirRecursive($path) : @unlink($path);
    }
    @rmdir($dir);
}
