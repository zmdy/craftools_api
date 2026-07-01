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
                $_SESSION['reveal_upload_link'] = uploadLinkFullUrl($result['raw_token']);
                header('Location: index.php?page=upload_links');
                exit;
            }
            if ($action === 'reopen') {
                $id = (int) ($_POST['id'] ?? 0);
                uploadLinkReopen($id);
                auditLog($adminId, 'update', 'upload_links', (string) $id);
                flashRedirect('success', 'Link reaberto para o cliente enviar novamente.', 'index.php?page=upload_links');
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
                if ($id > 0) {
                    phraseUpdate($id, $_POST);
                    auditLog($adminId, 'update', 'phrases', (string) $id);
                    flashRedirect('success', 'Frase atualizada.', 'index.php?page=phrases');
                }
                $newId = phraseCreate($_POST);
                auditLog($adminId, 'create', 'phrases', (string) $newId);
                flashRedirect('success', 'Frase criada.', 'index.php?page=phrases');
            }
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                phraseDelete($id);
                auditLog($adminId, 'delete', 'phrases', (string) $id);
                flashRedirect('success', 'Frase removida.', 'index.php?page=phrases');
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
