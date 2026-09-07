<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

set_time_limit(0);
ini_set('max_execution_time', '0');

function testAdb(array $arguments): array
{
    $command = 'adb';
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return ['output' => $output, 'exitCode' => $exitCode];
}

const MANUAL_TESTS_APK = __DIR__ . DIRECTORY_SEPARATOR . 'manual-tests' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'outputs' . DIRECTORY_SEPARATOR . 'apk' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'app-debug.apk';
const MANUAL_TESTS_PACKAGE = 'pt.alexmduarte.manualtests';

function testDevices(): array
{
    $result = testAdb(['devices']);
    $devices = [];
    foreach ($result['output'] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 2 && $parts[1] === 'device') {
            $devices[] = $parts[0];
        }
    }
    return $devices;
}

function testOutput(array $result): string
{
    return implode("\n", $result['output']);
}

function testResult(string $name, string $icon, string $status, string $detail, bool $manual = false): array
{
    return compact('name', 'icon', 'status', 'detail', 'manual');
}

function runDeviceTests(string $device): array
{
    $results = [];
    $battery = testOutput(testAdb(['-s', $device, 'shell', 'dumpsys', 'battery']));
    preg_match('/level:\s*(\d+)/', $battery, $level);
    preg_match('/status:\s*(\d+)/', $battery, $batteryStatus);
    $results[] = testResult('Bateria', '◒', isset($level[1]) ? 'pass' : 'fail', isset($level[1]) ? 'Nível ' . $level[1] . '% · Estado ' . ($batteryStatus[1] ?? 'desconhecido') : 'Não foi possível ler o estado da bateria.');

    $touch = testOutput(testAdb(['-s', $device, 'shell', 'getevent', '-pl']));
    $hasTouch = stripos($touch, 'ABS_MT_POSITION') !== false || stripos($touch, 'touchscreen') !== false;
    $results[] = testResult('Touchscreen', '⌁', $hasTouch ? 'manual' : 'fail', $hasTouch ? 'Controlador detetado · toque no ecrã para confirmar.' : 'Controlador touchscreen não detetado.', true);

    $camera = testOutput(testAdb(['-s', $device, 'shell', 'dumpsys', 'media.camera']));
    preg_match_all('/(?:Camera ID|Device status|cameraId)/i', $camera, $cameraMatches);
    $results[] = testResult('Câmaras', '◉', count($cameraMatches[0]) > 0 ? 'manual' : 'fail', count($cameraMatches[0]) > 0 ? 'Hardware detetado · abra a câmara para confirmar imagem.' : 'Não foi possível detetar câmaras.', true);

    $flash = testAdb(['-s', $device, 'shell', 'cmd', 'flashlight', 'get-state']);
    $results[] = testResult('Flash', '✦', $flash['exitCode'] === 0 ? 'manual' : 'manual', $flash['exitCode'] === 0 ? 'Controlo disponível · confirme o flash.' : 'Requer confirmação visual no flash da câmara.', true);

    $features = testOutput(testAdb(['-s', $device, 'shell', 'pm', 'list', 'features']));
    $hasMic = stripos($features, 'microphone') !== false || stripos($features, 'audio') !== false;
    $results[] = testResult('Microfone', '∿', $hasMic ? 'manual' : 'fail', $hasMic ? 'Entrada de áudio detetada · grave uma amostra para confirmar.' : 'Microfone não detetado.', true);
    $results[] = testResult('Coluna alta voz', '◖', stripos($features, 'audio') !== false ? 'manual' : 'fail', stripos($features, 'audio') !== false ? 'Saída de áudio disponível · reproduza um som para confirmar.' : 'Saída de áudio não detetada.', true);
    $results[] = testResult('Auscultador', '◗', stripos($features, 'audio') !== false ? 'manual' : 'fail', stripos($features, 'audio') !== false ? 'Saída de áudio disponível · confirme pelo auscultador.' : 'Saída de áudio não detetada.', true);

    $vibrator = testAdb(['-s', $device, 'shell', 'cmd', 'vibrator_manager', 'get-capabilities']);
    $results[] = testResult('Vibrador', '≈', $vibrator['exitCode'] === 0 ? 'manual' : 'manual', 'Componente testável · confirme a vibração no equipamento.', true);

    $wifi = testOutput(testAdb(['-s', $device, 'shell', 'cmd', 'wifi', 'status']));
    $results[] = testResult('Wi-Fi', '⌁', $wifi !== '' ? 'pass' : 'fail', $wifi !== '' ? 'Serviço Wi-Fi respondeu.' : 'Não foi possível consultar o Wi-Fi.');

    $bluetooth = testOutput(testAdb(['-s', $device, 'shell', 'settings', 'get', 'global', 'bluetooth_on']));
    $results[] = testResult('Bluetooth', '⌁', in_array(trim($bluetooth), ['0', '1'], true) ? 'pass' : 'fail', in_array(trim($bluetooth), ['0', '1'], true) ? 'Serviço disponível · estado ' . (trim($bluetooth) === '1' ? 'ligado' : 'desligado') . '.' : 'Não foi possível consultar o Bluetooth.');

    $gps = testOutput(testAdb(['-s', $device, 'shell', 'settings', 'get', 'secure', 'location_mode']));
    $results[] = testResult('GPS', '⊙', trim($gps) !== '' ? 'pass' : 'fail', trim($gps) !== '' ? 'Serviço de localização respondeu · modo ' . trim($gps) . '.' : 'Não foi possível consultar a localização.', true);

    return $results;
}

$devices = testDevices();
$selected = (string) ($_POST['device'] ?? ($devices[0] ?? ''));
$results = [];
$latestManual = [];
if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'test-results' . DIRECTORY_SEPARATOR . 'manual-results.json')) {
    $history = json_decode((string) file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'test-results' . DIRECTORY_SEPARATOR . 'manual-results.json'), true);
    $latestManual = is_array($history) && $history !== [] ? $history[count($history) - 1] : [];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run-tests' && in_array($selected, $devices, true)) {
    if (!is_file(MANUAL_TESTS_APK)) {
        $results = [testResult('APK de testes', '!', 'fail', 'A APK auxiliar não foi compilada em manual-tests/app/build/outputs/apk/debug/app-debug.apk.')];
    } else {
        $install = testAdb(['-s', $selected, 'install', '-r', MANUAL_TESTS_APK]);
        if ($install['exitCode'] !== 0) {
            $results = [testResult('APK de testes', '!', 'fail', 'Não foi possível instalar a APK: ' . testOutput($install))];
        } else {
            testAdb(['-s', $selected, 'reverse', 'tcp:8080', 'tcp:8080']);
            testAdb(['-s', $selected, 'shell', 'monkey', '-p', MANUAL_TESTS_PACKAGE, '1']);
            $results = runDeviceTests($selected);
        }
    }
    $nameResult = testAdb(['-s', $selected, 'shell', 'getprop', 'ro.product.model']);
    $deviceName = trim(testOutput($nameResult));
} else {
    $deviceName = '';
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Âncora | Testes do equipamento</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="shell">
    <header class="topbar"><a class="brand" href="index.php"><span class="brand-mark">A</span><span>Âncora</span></a><nav class="main-nav"><a href="index.php">Backup</a><a class="active" href="tests.php">Testes</a></nav><span class="local-badge"><span class="dot"></span> Execução local</span></header>
    <section class="intro"><p class="eyebrow">Diagnóstico · Android</p><h1>Conhecer<br><em>o equipamento.</em></h1><p class="lede">Selecione um dispositivo autorizado e execute uma verificação rápida aos principais componentes.</p></section>
    <section class="test-device-panel <?= $devices !== [] ? 'ready' : '' ?>">
        <div><span class="label">Equipamento selecionado</span><strong><?= $selected !== '' ? htmlspecialchars($deviceName !== '' ? $deviceName : $selected, ENT_QUOTES, 'UTF-8') : 'Nenhum equipamento' ?></strong><small><?= $selected !== '' ? htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') : 'Ligue e autorize um Android por USB.' ?></small></div>
        <form method="post"><input type="hidden" name="action" value="run-tests"><select name="device" required <?= $devices === [] ? 'disabled' : '' ?>><?php foreach ($devices as $device): ?><option value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>" <?= $selected === $device ? 'selected' : '' ?>><?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="primary-button" type="submit"><span>Testar tudo</span><b>→</b></button></form>
    </section>
    <?php if ($devices === []): ?><div class="setup-warning"><strong>Nenhum equipamento autorizado.</strong> Ative a Depuração USB, ligue o Android e aceite a chave RSA.</div><?php endif; ?>
    <?php if ($results !== []): ?><div class="test-summary"><strong><?= count(array_filter($results, static fn (array $result): bool => $result['status'] === 'pass')) ?> automáticos aprovados</strong><span><?= count(array_filter($results, static fn (array $result): bool => $result['status'] === 'manual')) ?> aguardam confirmação manual</span></div><?php endif; ?>
    <section class="test-grid">
        <?php foreach ($results as $result): ?><article class="test-card <?= htmlspecialchars($result['status'], ENT_QUOTES, 'UTF-8') ?>"><span class="test-icon"><?= $result['icon'] ?></span><div><strong><?= htmlspecialchars($result['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($result['detail'], ENT_QUOTES, 'UTF-8') ?></small></div><span class="test-status"><?= $result['status'] === 'pass' ? 'OK' : ($result['status'] === 'manual' ? 'Confirmar' : 'Falhou') ?></span></article><?php endforeach; ?>
    </section>
    <?php if ($results === []): ?><div class="empty-state test-empty">Os resultados dos testes aparecem aqui depois de executar a verificação.</div><?php endif; ?>
    <?php if ($latestManual !== []): ?><section class="manual-report"><div class="section-heading"><div><span class="section-number">Último envio</span><h2>Confirmações manuais</h2></div><span class="count-label"><?= htmlspecialchars((string) ($latestManual['received_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div><p><strong><?= htmlspecialchars((string) ($latestManual['model'] ?? 'Equipamento'), ENT_QUOTES, 'UTF-8') ?></strong> · <?= htmlspecialchars((string) ($latestManual['device'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p><div class="manual-report-grid"><?php foreach (($latestManual['results'] ?? []) as $result): ?><span class="report-<?= htmlspecialchars((string) $result['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $result['name'], ENT_QUOTES, 'UTF-8') ?>: <?= $result['status'] === 'pass' ? 'OK' : 'Falhou' ?></span><?php endforeach; ?></div></section><?php endif; ?>
    <footer><span>Âncora · Diagnóstico</span><span>Ligação ADB local</span></footer>
</main>
</body>
</html>
