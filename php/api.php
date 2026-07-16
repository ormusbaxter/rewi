<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_JSON_BYTES = 10 * 1024 * 1024;
$catalogDirectory = dirname(__DIR__);

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['error' => $message], $status);
}

function catalogName(mixed $value): string
{
    if (!is_string($value)) {
        fail('Ein Dateiname ist erforderlich.');
    }

    $name = trim($value);
    $hasForbiddenCharacter = strpbrk($name, "\\/:*?\"<>|") !== false;
    $hasControlCharacter = preg_match('/[\x00-\x1F\x7F]/u', $name) === 1;
    $hasJsonExtension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'json';

    if (
        $name === ''
        || $name === '.'
        || $name === '..'
        || strlen($name) > 255
        || $hasForbiddenCharacter
        || $hasControlCharacter
        || !$hasJsonExtension
    ) {
        fail('Ungültiger JSON-Dateiname.');
    }
    return $name;
}

function catalogPath(string $directory, string $name): string
{
    return $directory . DIRECTORY_SEPARATOR . catalogName($name);
}

$action = $_GET['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        $files = [];
        foreach (new DirectoryIterator($catalogDirectory) as $entry) {
            if ($entry->isFile() && !$entry->isLink() && strtolower($entry->getExtension()) === 'json') {
                $files[] = $entry->getFilename();
            }
        }
        natcasesort($files);
        respond(['files' => array_values($files)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'load') {
        $name = catalogName($_GET['file'] ?? null);
        $path = catalogPath($catalogDirectory, $name);
        if (!is_file($path) || is_link($path)) {
            fail('Die JSON-Datei wurde nicht gefunden.', 404);
        }
        if (filesize($path) > MAX_JSON_BYTES) {
            fail('Die JSON-Datei ist zu groß.', 413);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            fail('Die JSON-Datei konnte nicht gelesen werden.', 500);
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        respond(['file' => $name, 'data' => $decoded]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > MAX_JSON_BYTES) {
            fail('Die Anfrage ist zu groß.', 413);
        }
        $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $name = catalogName($request['file'] ?? null);
        if (!array_key_exists('data', $request) || !is_array($request['data'])) {
            fail('Es wurden keine gültigen Katalogdaten übermittelt.');
        }
        $json = json_encode(
            $request['data'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        $path = catalogPath($catalogDirectory, $name);
        if (is_link($path)) {
            fail('Symbolische Links sind als Katalogdateien nicht erlaubt.');
        }
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            fail('Die JSON-Datei konnte nicht gespeichert werden.', 500);
        }
        respond(['saved' => true, 'file' => $name]);
    }

    fail('Unbekannte API-Aktion.', 404);
} catch (JsonException $error) {
    fail('Ungültiges JSON: ' . $error->getMessage());
} catch (Throwable $error) {
    fail('Serverfehler beim Verarbeiten des Fragenkatalogs.', 500);
}
