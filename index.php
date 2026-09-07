<?php
declare(strict_types=1);

// A cópia pode demorar mais do que o limite padrão de 30 segundos do PHP.
set_time_limit(0);
ini_set('max_execution_time', '0');

const BACKUP_ROOT = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
const BACKUP_FOLDERS = [
    'DCIM' => '/sdcard/DCIM',
    'Imagens' => '/sdcard/Pictures',
    'Videos' => '/sdcard/Movies',
    'Musica' => '/sdcard/Music',
    'Documentos' => '/sdcard/Documents',
    'Transferencias' => '/sdcard/Download',
];
const BACKUP_DATA = [
    'Contactos' => ['file' => 'contactos.vcf', 'uri' => 'content://com.android.contacts/data', 'projection' => 'display_name:data1:mimetype'],
    'Mensagens' => ['file' => 'sms-backup.xml', 'uri' => 'content://sms', 'projection' => 'address:date:body:type'],
];
const SMS_HELPER_APK = __DIR__ . DIRECTORY_SEPARATOR . 'sms-helper' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'outputs' . DIRECTORY_SEPARATOR . 'apk' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'app-debug.apk';
const SMS_HELPER_PACKAGE = 'pt.alexmduarte.smsbackup';
const IOS_BACKUP_TOOL = 'idevicebackup2';
const IOS_DEVICE_TOOL = 'idevice_id';

function adbBinary(): string
{
    $configured = getenv('ADB_PATH');
    return $configured !== false && $configured !== '' ? $configured : 'adb';
}

function runAdb(array $arguments): array
{
    $command = escapeshellcmd(adbBinary());
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return ['output' => $output, 'exitCode' => $exitCode];
}

function runIos(array $arguments): array
{
    $command = escapeshellcmd(IOS_BACKUP_TOOL);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return ['output' => $output, 'exitCode' => $exitCode];
}

function connectedIosDevices(): array
{
    $command = escapeshellcmd(IOS_DEVICE_TOOL) . ' -l';
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $result = ['output' => $output, 'exitCode' => $exitCode];
    return $result['exitCode'] === 0 ? array_values(array_filter(array_map('trim', $result['output']))) : [];
}

function iosBackupSource(string $directory): ?string
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $candidate) {
        if (is_file($candidate . DIRECTORY_SEPARATOR . 'Info.plist')) {
            return basename($candidate);
        }
    }

    return null;
}

function isIosMediaBackup(string $name): bool
{
    return is_dir(BACKUP_ROOT . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'iPhone');
}

function runSqlite(string $database, string $query): array
{
    $command = 'sqlite3 ' . escapeshellarg($database) . ' ' . escapeshellarg($query);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return ['output' => $output, 'exitCode' => $exitCode];
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

function extractIosMedia(string $source, string $destination): int
{
    $sourceUdid = iosBackupSource($source);
    if ($sourceUdid === null) {
        return 0;
    }
    $deviceBackup = $source . DIRECTORY_SEPARATOR . $sourceUdid;
    $manifest = $deviceBackup . DIRECTORY_SEPARATOR . 'Manifest.db';
    if (!is_file($manifest)) {
        return 0;
    }

    $result = runSqlite($manifest, "select relativePath, fileID from Files where domain='CameraRollDomain' and relativePath <> '';");
    $copied = 0;
    $extensions = ['jpg', 'jpeg', 'png', 'heic', 'heif', 'gif', 'mov', 'mp4', 'm4v', 'avi'];
    foreach ($result['output'] as $row) {
        $parts = explode('|', $row, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$relativePath, $fileId] = $parts;
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $extensions, true) || !preg_match('/^[a-f0-9]{40}$/', $fileId)) {
            continue;
        }
        $sourceFile = $deviceBackup . DIRECTORY_SEPARATOR . substr($fileId, 0, 2) . DIRECTORY_SEPARATOR . $fileId;
        $targetFile = $destination . DIRECTORY_SEPARATOR . 'FotosVideos' . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($sourceFile)) {
            continue;
        }
        $targetDirectory = dirname($targetFile);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }
        if (copy($sourceFile, $targetFile)) {
            $copied++;
        }
    }

    return $copied;
}

function connectedDevices(): array
{
    $result = runAdb(['devices']);
    $devices = [];

    foreach ($result['output'] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'List of devices')) {
            continue;
        }

        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 2 && $parts[1] === 'device') {
            $devices[] = $parts[0];
        }
    }

    return $devices;
}

function humanSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    $size = (float) $bytes;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    return number_format($size, $unit === 0 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
}

function backupFolders(): array
{
    $folders = $_POST['folders'] ?? array_keys(BACKUP_FOLDERS);
    return array_values(array_intersect(array_keys(BACKUP_FOLDERS), is_array($folders) ? $folders : []));
}

function backupItems(): array
{
    $items = $_POST['items'] ?? array_merge(array_keys(BACKUP_FOLDERS), array_keys(BACKUP_DATA));
    return array_values(array_intersect(array_merge(array_keys(BACKUP_FOLDERS), array_keys(BACKUP_DATA)), is_array($items) ? $items : []));
}

function requestedBackupName(): string
{
    $name = trim((string) ($_POST['backup_name'] ?? ''));
    return preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]{0,79}$/u', $name) === 1 ? $name : '';
}

function vcardEscape(string $value): string
{
    return str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '', ''], trim($value));
}

function contactsToVcard(array $lines): string
{
    $vcard = [];
    foreach ($lines as $line) {
        $line = trim($line);
        $line = preg_replace('/^Row:\s*\d+\s+/', '', $line);
        if (preg_match('/display_name=(.*?),\s*data1=(.*?),\s*mimetype=([^,\s]+)$/', $line, $matches) !== 1 || $matches[3] !== 'vnd.android.cursor.item/phone_v2') {
            continue;
        }

        $name = vcardEscape($matches[1]);
        $phone = vcardEscape($matches[2]);
        if ($name === '' || $phone === '' || strtoupper($phone) === 'NULL') {
            continue;
        }
        $vcard[] = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:$name\r\nTEL;TYPE=CELL:$phone\r\nEND:VCARD";
    }

    return $vcard === [] ? '' : implode("\r\n", $vcard) . "\r\n";
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function messagesToXml(array $lines): string
{
    $messages = [];
    $raw = implode("\n", $lines);
    preg_match_all('/Row:\s*\d+\s+address=(.*?),\s*date=(\d+),\s*body=(.*?),\s*type=(\d+)/s', $raw, $rows, PREG_SET_ORDER);
    foreach ($rows as $matches) {

        $messages[] = sprintf(
            '  <sms address="%s" date="%s" type="%s" body="%s" read="1" />',
            xmlEscape($matches[1]),
            $matches[2],
            $matches[4],
            xmlEscape($matches[3])
        );
    }

    return sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\" ?>\r\n<smses count=\"%d\">\r\n%s\r\n</smses>\r\n", count($messages), implode("\r\n", $messages));
}

function remoteContentsPath(string $path): string
{
    return rtrim($path, '/') . '/.';
}

function latestBackups(?string $type = null): array
{
    if (!is_dir(BACKUP_ROOT)) {
        return [];
    }

    $backups = [];
    foreach (glob(BACKUP_ROOT . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $path) {
        $isIos = isIosMediaBackup(basename($path));
        if (($type === 'ios' && !$isIos) || ($type === 'android' && $isIos)) {
            continue;
        }
        $metadataPath = $path . DIRECTORY_SEPARATOR . 'backup.json';
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
        $size = is_array($metadata) && isset($metadata['size']) ? humanSize((int) $metadata['size']) : 'Tamanho pendente';
        $backups[] = ['name' => basename($path), 'size' => $size, 'timestamp' => filemtime($path) ?: 0];
    }

    usort($backups, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    return array_slice($backups, 0, 5);
}

$devices = connectedDevices();
$iosDevices = connectedIosDevices();
$message = null;
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    if ($devices === []) {
        $message = 'Nenhum equipamento autorizado foi encontrado. Ligue a Depuracao USB e aceite a chave RSA no telemovel.';
        $messageType = 'error';
    } elseif (requestedBackupName() === '') {
        $message = 'Indique um nome válido para o backup (até 80 caracteres, sem barras).';
        $messageType = 'error';
    } elseif (backupItems() === []) {
        $message = 'Escolha pelo menos uma categoria para copiar.';
        $messageType = 'error';
    } else {
        $folder = requestedBackupName();
        $destination = BACKUP_ROOT . DIRECTORY_SEPARATOR . $folder;
        if (is_dir($destination)) {
            $message = 'Já existe um backup com esse nome. Escolha outro nome.';
            $messageType = 'error';
            $devices = connectedDevices();
            $folder = '';
        }

        $copied = [];
        $failed = [];
        if ($folder !== '') {
            mkdir($destination, 0775, true);

        foreach (backupItems() as $label) {
            if (isset(BACKUP_DATA[$label])) {
                $data = BACKUP_DATA[$label];
                $query = ['-s', $devices[0], 'shell', 'content', 'query', '--uri', $data['uri'], '--projection', $data['projection']];
                if (isset($data['where'])) {
                    $query = array_merge($query, ['--where', $data['where']]);
                }
                $result = runAdb($query);
                $content = $label === 'Contactos' ? contactsToVcard($result['output']) : messagesToXml($result['output']);
                $hasData = $label === 'Contactos' ? $content !== '' : preg_match('/count="[1-9]\d*"/', $content) === 1;
                if ($result['exitCode'] === 0 && $hasData && file_put_contents($destination . DIRECTORY_SEPARATOR . $data['file'], $content) !== false) {
                    $copied[] = $label;
                    continue;
                }
                $failed[] = $label;
                continue;
            }

            $localTarget = $destination . DIRECTORY_SEPARATOR . $label;
            mkdir($localTarget, 0775, true);
            $result = runAdb(['-s', $devices[0], 'pull', remoteContentsPath(BACKUP_FOLDERS[$label]), $localTarget]);
            if ($result['exitCode'] === 0) {
                $copied[] = $label;
            } else {
                $failed[] = $label;
            }
        }

        $backupSize = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $backupSize += $file->getSize();
            }
        }

        file_put_contents($destination . DIRECTORY_SEPARATOR . 'backup.json', json_encode([
            'created_at' => date(DATE_ATOM),
            'device' => $devices[0],
            'folders' => $copied,
            'size' => $backupSize,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($folder !== '' && $copied !== []) {
            $message = 'Backup concluido: ' . implode(', ', $copied) . ($failed !== [] ? '. Nao foi possivel copiar: ' . implode(', ', $failed) . '.' : '.');
            $messageType = $failed === [] ? 'success' : 'warning';
        } elseif ($folder !== '') {
            $message = 'Nao foi possivel copiar as categorias selecionadas. Confirme a autorizacao ADB.';
            $messageType = 'error';
        }
        }
    }

    $devices = connectedDevices();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ios_backup') {
    $backupName = requestedBackupName();
    if ($iosDevices === []) {
        $message = 'Nenhum iPhone autorizado foi encontrado. Desbloqueie-o e aceite a mensagem Confiar neste computador.';
        $messageType = 'error';
    } elseif ($backupName === '') {
        $message = 'Indique um nome válido para o backup do iPhone.';
        $messageType = 'error';
    } else {
        $destination = BACKUP_ROOT . DIRECTORY_SEPARATOR . $backupName . DIRECTORY_SEPARATOR . 'iPhone';
        if (is_dir($destination)) {
            $message = 'Já existe um backup de iPhone com esse nome. Escolha outro nome.';
            $messageType = 'error';
        } else {
            $temporary = BACKUP_ROOT . DIRECTORY_SEPARATOR . $backupName . DIRECTORY_SEPARATOR . '.iphone-backup-temp';
            mkdir($temporary, 0775, true);
            $result = runIos(['-u', $iosDevices[0], 'backup', '--full', $temporary]);
            $mediaCount = $result['exitCode'] === 0 ? extractIosMedia($temporary, dirname($destination)) : 0;
            removeDirectory($temporary);
            if ($result['exitCode'] === 0 && $mediaCount > 0) {
                $message = 'Backup do iPhone concluído: ' . $mediaCount . ' fotos/vídeos guardados em ' . $backupName . '.';
                $messageType = 'success';
            } elseif ($result['exitCode'] === 0) {
                removeDirectory(dirname($destination));
                $message = 'O backup do iPhone terminou, mas não foram encontradas fotos ou vídeos.';
                $messageType = 'warning';
            } else {
                removeDirectory(dirname($destination));
                $message = 'Não foi possível criar o backup do iPhone: ' . implode(' ', array_slice($result['output'], -2));
                $messageType = 'error';
            }
        }
    }
    $iosDevices = connectedIosDevices();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $backupName = basename((string) ($_POST['backup_name'] ?? ''));
    $backupPath = BACKUP_ROOT . DIRECTORY_SEPARATOR . $backupName;
    $restoreItems = $_POST['restore_items'] ?? [];
    $restoreItems = array_values(array_intersect(array_merge(array_keys(BACKUP_FOLDERS), ['Contactos', 'Mensagens']), is_array($restoreItems) ? $restoreItems : []));

    if (isIosMediaBackup($backupName)) {
        $message = 'Este é um backup de fotos e vídeos do iPhone. Copie a pasta FotosVideos para o computador e sincronize-a com o iPhone através do Finder/iTunes ou da aplicação Fotos do Windows.';
        $messageType = 'warning';
    } elseif ($devices === []) {
        $message = 'Nenhum equipamento autorizado foi encontrado.';
        $messageType = 'error';
    } elseif (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.-]{0,79}$/u', $backupName) || !is_dir($backupPath)) {
        $message = 'Escolha um backup válido para restaurar.';
        $messageType = 'error';
    } elseif ($restoreItems === []) {
        $message = 'Escolha pelo menos uma pasta para restaurar.';
        $messageType = 'error';
    } else {
        $restored = [];
        $failed = [];
        foreach ($restoreItems as $label) {
            if ($label === 'Contactos') {
                $source = $backupPath . DIRECTORY_SEPARATOR . BACKUP_DATA['Contactos']['file'];
                if (!is_file($source)) {
                    $failed[] = $label;
                    continue;
                }
                $result = runAdb(['-s', $devices[0], 'push', $source, '/sdcard/Download/contactos.vcf']);
                if ($result['exitCode'] === 0) {
                    $restored[] = 'Contactos para Download';
                } else {
                    $failed[] = $label;
                }
                continue;
            }
            if ($label === 'Mensagens') {
                $source = $backupPath . DIRECTORY_SEPARATOR . BACKUP_DATA['Mensagens']['file'];
                if (!is_file($source)) {
                    $failed[] = $label;
                    continue;
                }
                if (!is_file(SMS_HELPER_APK)) {
                    $failed[] = 'Mensagens (APK auxiliar não encontrada)';
                    continue;
                }
                $install = runAdb(['-s', $devices[0], 'install', '-r', SMS_HELPER_APK]);
                $push = $install['exitCode'] === 0 ? runAdb(['-s', $devices[0], 'push', $source, '/sdcard/Download/sms-backup.xml']) : ['exitCode' => 1, 'output' => []];
                if ($install['exitCode'] === 0 && $push['exitCode'] === 0) {
                    runAdb(['-s', $devices[0], 'shell', 'monkey', '-p', SMS_HELPER_PACKAGE, '1']);
                    $restored[] = 'APK SMS instalada e XML em Download';
                } else {
                    $failed[] = $label;
                }
                continue;
            }
            $source = $backupPath . DIRECTORY_SEPARATOR . $label;
            if (!is_dir($source)) {
                $failed[] = $label;
                continue;
            }
            $legacyNestedSource = $source . DIRECTORY_SEPARATOR . $label;
            if (is_dir($legacyNestedSource)) {
                $source = $legacyNestedSource;
            }
            $result = runAdb(['-s', $devices[0], 'push', remoteContentsPath($source), remoteContentsPath(BACKUP_FOLDERS[$label])]);
            if ($result['exitCode'] === 0) {
                $restored[] = $label;
            } else {
                $failed[] = $label;
            }
        }
        $message = $restored !== [] ? 'Restauro concluído: ' . implode(', ', $restored) . ($failed !== [] ? '. Falhou: ' . implode(', ', $failed) . '.' : '.') : 'Não foi possível restaurar as pastas selecionadas.';
        $messageType = $restored !== [] && $failed === [] ? 'success' : ($restored !== [] ? 'warning' : 'error');
    }
    $devices = connectedDevices();
}

$hasAdb = runAdb(['version'])['exitCode'] === 0;
$hasIosTool = runIos(['--help'])['exitCode'] === 0;
$androidBackups = latestBackups('android');
$iosBackups = latestBackups('ios');
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Âncora | Backup Android</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <a class="brand" href="."><span class="brand-mark">A</span><span>Âncora</span></a>
            <span class="local-badge"><span class="dot"></span> Execução local</span>
        </header>

        <section class="intro">
            <p class="eyebrow">Arquivo pessoal · Android</p>
            <h1>O que importa,<br><em>guardado.</em></h1>
            <p class="lede">Faça uma cópia local das fotografias, vídeos e documentos do seu telemóvel através de uma ligação USB segura.</p>
        </section>

        <?php if ($message !== null): ?>
            <div class="notice <?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="status-panel <?= $devices !== [] ? 'ready' : '' ?>">
            <div class="status-icon">↯</div>
            <div class="status-copy">
                <span class="label">Estado da ligação</span>
                <strong><?= $devices !== [] ? 'Telemóvel pronto' : 'A aguardar telemóvel' ?></strong>
                <small><?= $devices !== [] ? 'Dispositivo autorizado e disponível para backup.' : 'Ligue o telemóvel por USB e aceite a autorização no ecrã.' ?></small>
            </div>
            <div class="status-side">
                <span class="status-pill <?= $devices !== [] ? 'online' : 'offline' ?>"><i></i><?= $devices !== [] ? 'Ligado' : 'Desligado' ?></span>
                <?php if ($devices !== []): ?><small><?= htmlspecialchars($devices[0], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
            </div>
        </section>

        <section class="ios-panel <?= $iosDevices !== [] ? 'ready' : '' ?>">
            <div class="ios-heading"><div><span class="section-number">iOS</span><h2>Backup de iPhone</h2></div><span class="status-pill <?= $iosDevices !== [] ? 'online' : 'offline' ?>"><i></i><?= $iosDevices !== [] ? 'Ligado' : 'Não detetado' ?></span></div>
            <p>Copie apenas fotos e vídeos do iPhone. Desbloqueie-o e aceite <strong>Confiar neste computador</strong>. Nenhum restauro iPhone é executado nesta aplicação.</p>
            <?php if ($iosDevices !== []): ?><small class="device-id"> <?= htmlspecialchars($iosDevices[0], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
            <form method="post" class="ios-actions process-form" data-process="ios-backup">
                <input type="hidden" name="action" value="ios_backup">
                <input class="ios-name" type="text" name="backup_name" maxlength="80" placeholder="Nome do backup do iPhone" required>
                <button class="primary-button" type="submit"><span>Criar backup completo</span><b>→</b></button>
            </form>
        </section>

        <?php if (!$hasAdb): ?>
            <div class="setup-warning"><strong>ADB não encontrado.</strong> Instale o Android SDK Platform-Tools e adicione a pasta `platform-tools` ao PATH do sistema.</div>
        <?php endif; ?>

        <div class="process-status" id="process-status" aria-live="polite" hidden>
            <div class="process-top"><strong id="process-title">A preparar operação</strong><span id="process-percent">0%</span></div>
            <div class="progress-track"><span id="progress-bar"></span></div>
            <small id="process-step">A iniciar...</small>
        </div>

            <form method="post" class="backup-form process-form" data-process="backup">
            <input type="hidden" name="action" value="backup">
            <div class="section-heading"><div><span class="section-number">01</span><h2>Escolha o que guardar</h2></div><button type="button" class="text-button" id="toggle-all">Desmarcar tudo</button></div>
            <label class="backup-name-field">Nome do backup<input type="text" name="backup_name" maxlength="80" placeholder="Ex.: Telemovel antes de reparar" required></label>
            <div class="folder-grid">
                    <?php foreach (array_merge(BACKUP_FOLDERS, BACKUP_DATA) as $label => $remote): ?>
                        <?php $pathLabel = is_array($remote) ? ($label === 'Contactos' ? 'Ficheiro VCF' : 'Exportação protegida') : $remote; ?>
                        <label class="folder-card"><input type="checkbox" name="items[]" value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>" checked><span class="checkmark">✓</span><span class="folder-icon"><?= ['DCIM' => '◉', 'Imagens' => '▧', 'Videos' => '▶', 'Musica' => '♫', 'Documentos' => '≡', 'Transferencias' => '↓', 'Contactos' => '♧', 'Mensagens' => '▤'][$label] ?></span><span class="folder-name"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><small><?= htmlspecialchars($pathLabel, ENT_QUOTES, 'UTF-8') ?></small></label>
                <?php endforeach; ?>
            </div>
            <div class="action-row"><button class="primary-button" type="submit"><span>Iniciar backup</span><b>→</b></button><span class="action-note">Os dados ficam apenas nesta pasta<br><strong><?= htmlspecialchars(basename(BACKUP_ROOT), ENT_QUOTES, 'UTF-8') ?>/</strong></span></div>
        </form>

        <section class="history"><div class="section-heading"><div><span class="section-number">02A</span><h2>Backups Android</h2></div><span class="count-label"><?= count($androidBackups) ?> guardados</span></div>
            <?php if ($androidBackups === []): ?><div class="empty-state">Ainda não existem backups Android nesta máquina.</div><?php else: ?><div class="backup-list"><?php foreach ($androidBackups as $backup): ?><div class="backup-item"><span class="archive-icon">⌁</span><span><strong><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($backup['size'], ENT_QUOTES, 'UTF-8') ?></small></span><span class="archive-status">Android</span></div><?php endforeach; ?></div><?php endif; ?>
        </section>
        <section class="history ios-history"><div class="section-heading"><div><span class="section-number">02B</span><h2>Backups iPhone</h2></div><span class="count-label"><?= count($iosBackups) ?> guardados</span></div>
            <?php if ($iosBackups === []): ?><div class="empty-state">Ainda não existem backups iPhone nesta máquina.</div><?php else: ?><div class="backup-list"><?php foreach ($iosBackups as $backup): ?><div class="backup-item"><span class="archive-icon">⌁</span><span><strong><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($backup['size'], ENT_QUOTES, 'UTF-8') ?></small></span><span class="archive-status">Fotos e vídeos</span></div><?php endforeach; ?></div><?php endif; ?>
        </section>
            <?php if ($androidBackups !== []): ?>
                <form method="post" class="restore-form process-form" data-process="restore">
                    <input type="hidden" name="action" value="restore">
                    <div class="section-heading"><div><span class="section-number">03</span><h2>Restaurar para o telemóvel</h2></div></div>
                    <?php if ($androidBackups !== []): ?><div class="restore-controls"><label>Backup<select name="backup_name" required><?php foreach ($androidBackups as $backup): ?><option value="<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($backup['size'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><label>Pastas e ficheiros a restaurar<select name="restore_items[]" multiple required><?php foreach (BACKUP_FOLDERS as $label => $remote): ?><option value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?><option value="Contactos">Contactos (VCF para Download)</option><option value="Mensagens">SMS (XML para Download)</option></select></label></div>
                    <div class="action-row"><button class="primary-button restore-button" type="submit"><span>Restaurar selecionados</span><b>↗</b></button><span class="action-note">VCF e XML são colocados em<br><strong>Download/</strong></span></div>
                    <?php else: ?><div class="empty-state">Não existem backups Android para restaurar. Os backups iPhone são apenas fotos e vídeos para sincronização manual.</div><?php endif; ?>
                </form>
            <?php endif; ?>
        <footer><span>Âncora v1.0</span><span>Ligação direta · Sem cloud</span></footer>
    </main>
    <script>
        const toggle = document.querySelector('#toggle-all');
        const boxes = [...document.querySelectorAll('input[name="items[]"]')];
        toggle.addEventListener('click', () => {
            const shouldCheck = boxes.some((box) => !box.checked);
            boxes.forEach((box) => { box.checked = shouldCheck; });
            toggle.textContent = shouldCheck ? 'Desmarcar tudo' : 'Selecionar tudo';
        });

        document.querySelectorAll('.process-form').forEach((form) => {
            form.addEventListener('submit', () => {
                const panel = document.querySelector('#process-status');
                const bar = document.querySelector('#progress-bar');
                const percent = document.querySelector('#process-percent');
                const title = document.querySelector('#process-title');
                const step = document.querySelector('#process-step');
                const restore = form.dataset.process === 'restore';
                const stages = restore
                    ? ['A preparar ficheiros...', 'A enviar dados para o telemóvel...', 'A confirmar transferência...']
                    : ['A preparar backup...', 'A copiar dados do telemóvel...', 'A guardar o registo...'];
                let progress = 8;
                let stage = 0;
                panel.hidden = false;
                title.textContent = restore ? 'Restauro em curso' : 'Backup em curso';
                step.textContent = stages[stage];
                bar.style.width = `${progress}%`;
                percent.textContent = `${progress}%`;
                const timer = setInterval(() => {
                    progress = Math.min(progress + 4, 92);
                    if (progress > 35 && stage === 0) stage = 1;
                    if (progress > 72 && stage === 1) stage = 2;
                    bar.style.width = `${progress}%`;
                    percent.textContent = `${progress}%`;
                    step.textContent = stages[stage];
                }, 450);
                window.addEventListener('pageshow', () => clearInterval(timer), { once: true });
            });
        });
    </script>
</body>
</html>
