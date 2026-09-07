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

function latestBackups(): array
{
    if (!is_dir(BACKUP_ROOT)) {
        return [];
    }

    $backups = [];
    foreach (glob(BACKUP_ROOT . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $path) {
        $metadataPath = $path . DIRECTORY_SEPARATOR . 'backup.json';
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
        $size = is_array($metadata) && isset($metadata['size']) ? humanSize((int) $metadata['size']) : 'Tamanho pendente';
        $backups[] = ['name' => basename($path), 'size' => $size, 'timestamp' => filemtime($path) ?: 0];
    }

    usort($backups, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
    return array_slice($backups, 0, 5);
}

$devices = connectedDevices();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $backupName = basename((string) ($_POST['backup_name'] ?? ''));
    $backupPath = BACKUP_ROOT . DIRECTORY_SEPARATOR . $backupName;
    $restoreItems = $_POST['restore_items'] ?? [];
    $restoreItems = array_values(array_intersect(array_merge(array_keys(BACKUP_FOLDERS), ['Contactos', 'Mensagens']), is_array($restoreItems) ? $restoreItems : []));

    if ($devices === []) {
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
$backups = latestBackups();
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

        <section class="history"><div class="section-heading"><div><span class="section-number">02</span><h2>Backups recentes</h2></div><span class="count-label"><?= count($backups) ?> guardados</span></div>
                <?php if ($backups === []): ?><div class="empty-state">Ainda não existem backups nesta máquina.</div><?php else: ?><div class="backup-list"><?php foreach ($backups as $backup): ?><div class="backup-item"><span class="archive-icon">⌁</span><span><strong><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($backup['size'], ENT_QUOTES, 'UTF-8') ?></small></span><span class="archive-status">Disponível</span></div><?php endforeach; ?></div><?php endif; ?>
        </section>
            <?php if ($backups !== []): ?>
                <form method="post" class="restore-form process-form" data-process="restore">
                    <input type="hidden" name="action" value="restore">
                    <div class="section-heading"><div><span class="section-number">03</span><h2>Restaurar para o telemóvel</h2></div></div>
                    <div class="restore-controls"><label>Backup<select name="backup_name" required><?php foreach ($backups as $backup): ?><option value="<?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($backup['name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($backup['size'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><label>Pastas e ficheiros a restaurar<select name="restore_items[]" multiple required><?php foreach (BACKUP_FOLDERS as $label => $remote): ?><option value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?><option value="Contactos">Contactos (VCF para Download)</option><option value="Mensagens">SMS (XML para Download)</option></select></label></div>
                    <div class="action-row"><button class="primary-button restore-button" type="submit"><span>Restaurar selecionados</span><b>↗</b></button><span class="action-note">VCF e XML são colocados em<br><strong>Download/</strong></span></div>
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
