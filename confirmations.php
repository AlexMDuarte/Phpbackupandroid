<?php
declare(strict_types=1);

const RESULTS_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'test-results' . DIRECTORY_SEPARATOR . 'manual-results.json';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Apenas POST é permitido.']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['device'], $payload['results'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dados de teste inválidos.']);
    exit;
}

if (isset($payload['results']['name'])) {
    $payload['results'] = [$payload['results']];
}
if (!is_array($payload['results'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A lista de resultados é inválida.']);
    exit;
}

$directory = dirname(RESULTS_FILE);
if (!is_dir($directory)) {
    mkdir($directory, 0775, true);
}

$record = [
    'received_at' => date(DATE_ATOM),
    'device' => substr((string) $payload['device'], 0, 120),
    'model' => substr((string) ($payload['model'] ?? ''), 0, 120),
    'results' => array_map(static fn ($result): array => [
        'name' => substr((string) ($result['name'] ?? ''), 0, 80),
        'status' => in_array(($result['status'] ?? ''), ['pass', 'fail'], true) ? $result['status'] : 'fail',
        'note' => substr((string) ($result['note'] ?? ''), 0, 300),
    ], $payload['results']),
];

$history = [];
if (is_file(RESULTS_FILE)) {
    $history = json_decode((string) file_get_contents(RESULTS_FILE), true) ?: [];
}
$history[] = $record;
file_put_contents(RESULTS_FILE, json_encode(array_slice($history, -50), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['ok' => true, 'received_at' => $record['received_at']]);
