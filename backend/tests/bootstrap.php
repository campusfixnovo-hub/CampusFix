<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
require $projectRoot.'/vendor/autoload.php';

$testingEnvironmentFile = $projectRoot.'/.env.testing';
if (! is_file($testingEnvironmentFile)) {
    fwrite(STDERR, "Testes interrompidos: configure o arquivo local ignorado .env.testing.\n");
    exit(1);
}

try {
    $environment = Dotenv::createMutable($projectRoot, '.env.testing')->load();
} catch (Throwable) {
    fwrite(STDERR, "Testes interrompidos: não foi possível carregar .env.testing.\n");
    exit(1);
}

$requiredValues = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => 'campusfix_test',
    'DB_USERNAME' => 'campusfix_test_app',
];

foreach ($requiredValues as $key => $expectedValue) {
    if (($environment[$key] ?? null) !== $expectedValue) {
        fwrite(STDERR, "Testes interrompidos: {$key} precisa apontar para o ambiente isolado campusfix_test.\n");
        exit(1);
    }
}

if (($environment['DB_URL'] ?? '') !== '') {
    fwrite(STDERR, "Testes interrompidos: DB_URL deve ficar vazio para validar a conexão explícita de campusfix_test.\n");
    exit(1);
}

$host = $environment['DB_HOST'] ?? '';
$port = $environment['DB_PORT'] ?? '';
$username = $environment['DB_USERNAME'] ?? '';
$password = $environment['DB_PASSWORD'] ?? '';

if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || $port === '' || $password === '') {
    fwrite(STDERR, "Testes interrompidos: .env.testing precisa definir uma conexão MySQL local completa.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname=campusfix_test;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false],
    );
    $actualDatabase = $pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable) {
    fwrite(STDERR, "Testes interrompidos: não foi possível validar a conexão efetiva com campusfix_test.\n");
    exit(1);
}

if ($actualDatabase !== 'campusfix_test') {
    fwrite(STDERR, "Testes interrompidos: o banco efetivamente conectado não é campusfix_test.\n");
    exit(1);
}

$pdo = null;
