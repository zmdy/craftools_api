<?php
/**
 * src/updater.php — Módulo de atualização/sincronização automática do CraftTools API via GitHub.
 */

const GITHUB_ZIP_URL = 'https://github.com/zmdy/craftools_api/archive/refs/heads/main.zip';

/**
 * Baixa o ZIP mais recente do repositório do GitHub e substitui os arquivos de código-fonte
 * do sistema, preservando arquivos locais (.env, banco de dados e uploads do usuário).
 *
 * @return array{success:bool, message:string}
 */
function syncSystemFromGithub(): array {
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('A extensão PHP ZipArchive não está instalada ou habilitada neste servidor.');
    }

    $tempZipDir = CRAFTOOLS_API_STORAGE . '/tmp';
    if (!is_dir($tempZipDir) && !mkdir($tempZipDir, 0775, true) && !is_dir($tempZipDir)) {
        throw new RuntimeException('Não foi possível criar a pasta temporária de atualização.');
    }

    $zipPath = $tempZipDir . '/update_' . time() . '_' . bin2hex(random_bytes(4)) . '.zip';

    // 1. Baixar o arquivo ZIP do GitHub
    $zipData = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, GITHUB_ZIP_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CraftTools-API-Updater/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $zipData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $zipData === false || strlen($zipData) < 1000) {
            $zipData = null;
        }
    }

    if ($zipData === null) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: CraftTools-API-Updater/1.0\r\n",
                'follow_location' => 1,
                'timeout' => 120,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $zipData = @file_get_contents(GITHUB_ZIP_URL, false, $context);
    }

    if ($zipData === false || $zipData === null || strlen($zipData) < 1000) {
        throw new RuntimeException('Falha ao baixar o arquivo ZIP de atualização do GitHub. Verifique a conexão com a internet.');
    }

    if (file_put_contents($zipPath, $zipData) === false) {
        throw new RuntimeException('Falha ao gravar o arquivo de atualização no disco.');
    }

    // 2. Extrair o arquivo ZIP em uma pasta temporária
    $extractDir = $tempZipDir . '/extract_' . time() . '_' . bin2hex(random_bytes(4));
    @mkdir($extractDir, 0775, true);

    $zip = new ZipArchive();
    $openRes = $zip->open($zipPath);
    if ($openRes !== true) {
        @unlink($zipPath);
        throw new RuntimeException('Arquivo ZIP baixado é inválido ou corrompido (código ' . (int) $openRes . ').');
    }

    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        @unlink($zipPath);
        removeDirRecursiveHelper($extractDir);
        throw new RuntimeException('Falha ao extrair o conteúdo do arquivo ZIP.');
    }
    $zip->close();
    @unlink($zipPath);

    // 3. Localizar a pasta raiz dentro do ZIP (geralmente craftools_api-main)
    $subDirs = array_values(array_filter(scandir($extractDir), function ($item) use ($extractDir) {
        return $item !== '.' && $item !== '..' && is_dir($extractDir . '/' . $item);
    }));

    $sourceDir = $extractDir;
    if (count($subDirs) === 1) {
        $sourceDir = $extractDir . '/' . $subDirs[0];
    }

    // Lista de arquivos/pastas preservados que NUNCA devem ser sobrescritos
    $excludeList = ['.env', '.env.production', '.env.development', '.git'];

    // 4. Copiar/Substituir arquivos de código-fonte
    try {
        copyCodeSourceRecursive($sourceDir, CRAFTOOLS_API_ROOT, $excludeList);
    } finally {
        removeDirRecursiveHelper($extractDir);
    }

    return [
        'success' => true,
        'message' => 'Código-fonte atualizado com sucesso a partir do repositório GitHub (branch main)!',
    ];
}

/**
 * Copia os arquivos de $source para $target, respeitando arquivos preservados.
 */
function copyCodeSourceRecursive(string $source, string $target, array $excludeList): void {
    if (!is_dir($target)) {
        @mkdir($target, 0775, true);
    }

    $dir = opendir($source);
    if (!$dir) {
        return;
    }

    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcPath = $source . '/' . $file;
        $dstPath = $target . '/' . $file;

        // Se estiver na lista de ignorados/preservados
        if (in_array($file, $excludeList, true)) {
            if (file_exists($dstPath)) {
                continue; // Preserva arquivo local (.env, etc.)
            }
        }

        if (is_dir($srcPath)) {
            copyCodeSourceRecursive($srcPath, $dstPath, $excludeList);
        } else {
            @copy($srcPath, $dstPath);
            @chmod($dstPath, 0644);
        }
    }

    closedir($dir);
}

/**
 * Auxiliar para remover pasta recursivamente.
 */
function removeDirRecursiveHelper(string $dir): void {
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
        is_dir($path) ? removeDirRecursiveHelper($path) : @unlink($path);
    }
    @rmdir($dir);
}
