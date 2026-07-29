<?php
declare(strict_types=1);

/**
 * Instalação de uso único.
 *
 * Grava lib/config.local.php (fora do git) com as credenciais do banco, o hash da
 * senha do painel e a chave das rotinas de cron. Assim que o arquivo existe, este
 * endpoint se fecha permanentemente e só volta a aceitar escrita com a chave já gravada.
 */

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$jaConfigurado = painel_configurado();
$chaveOk       = $jaConfigurado && hash_equals((string) cfg('worker_key'), (string) ($_POST['k'] ?? $_GET['k'] ?? ''));

if ($jaConfigurado && !$chaveOk) {
    http_response_code(410);
    exit("Instalacao ja concluida.\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Pronto para instalar. Envie POST com: db_host, db_name, db_user, db_pass, senha, url_publica\n";
    exit;
}

$obrig = ['db_name', 'db_user', 'db_pass', 'senha'];
foreach ($obrig as $campo) {
    if (($_POST[$campo] ?? '') === '') {
        http_response_code(400);
        exit("faltou: {$campo}\n");
    }
}

$senha = (string) $_POST['senha'];
if (strlen($senha) < 10) {
    http_response_code(400);
    exit("senha curta demais\n");
}

$config = [
    'db_host'      => (string) ($_POST['db_host'] ?? 'localhost'),
    'db_name'      => (string) $_POST['db_name'],
    'db_user'      => (string) $_POST['db_user'],
    'db_pass'      => (string) $_POST['db_pass'],
    'senha_hash'   => password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]),
    'worker_key'   => $jaConfigurado ? (string) cfg('worker_key') : bin2hex(random_bytes(24)),
    'url_publica'  => rtrim((string) ($_POST['url_publica'] ?? 'https://painel.ffernando.com'), '/'),
    'instalado_em' => date('c'),
];

// testa a conexão antes de gravar
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
    new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    http_response_code(400);
    exit('banco recusou a conexao: ' . $e->getMessage() . "\n");
}

$php = "<?php\n// Gerado pela instalacao em " . date('c') . ". NAO versionar.\nreturn " . var_export($config, true) . ";\n";

if (!is_dir(PAINEL_LIB) || file_put_contents(PAINEL_CONFIG_FILE, $php, LOCK_EX) === false) {
    http_response_code(500);
    exit("nao consegui gravar lib/config.local.php\n");
}
@chmod(PAINEL_CONFIG_FILE, 0600);

echo "instalado=ok\n";
echo 'worker_key=' . $config['worker_key'] . "\n";
