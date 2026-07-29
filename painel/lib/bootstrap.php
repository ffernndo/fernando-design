<?php
declare(strict_types=1);

/**
 * Painel Social — bootstrap
 * Carrega config, conexão, sessão e helpers. Nenhum segredo mora neste arquivo:
 * tudo sensível fica em lib/config.local.php, que NÃO é versionado.
 */

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

define('PAINEL_ROOT', dirname(__DIR__));
define('PAINEL_LIB', __DIR__);
define('PAINEL_MIDIA_DIR', PAINEL_ROOT . '/midia');
define('PAINEL_CONFIG_FILE', PAINEL_LIB . '/config.local.php');

$GLOBALS['PAINEL_CFG'] = is_file(PAINEL_CONFIG_FILE) ? (require PAINEL_CONFIG_FILE) : [];

function cfg(string $chave, $padrao = null)
{
    return $GLOBALS['PAINEL_CFG'][$chave] ?? $padrao;
}

function painel_configurado(): bool
{
    return (bool) cfg('db_name') && (bool) cfg('senha_hash');
}

function exigir_configurado(): void
{
    if (!painel_configurado()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Painel ainda nao configurado.\n");
    }
}

/** Conexão PDO única. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    exigir_configurado();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        cfg('db_host', 'localhost'),
        cfg('db_name')
    );
    $pdo = new PDO($dsn, (string) cfg('db_user'), (string) cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

/** Atalhos de query. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function q_val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

/** Log persistente — usado pelo worker e pelas telas. */
function painel_log(string $nivel, string $contexto, string $mensagem, array $extra = []): void
{
    try {
        q(
            'INSERT INTO log (criado_em, nivel, contexto, mensagem, extra) VALUES (?,?,?,?,?)',
            [agora(), $nivel, mb_substr($contexto, 0, 60), mb_substr($mensagem, 0, 2000), $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null]
        );
    } catch (Throwable $e) {
        error_log('[painel] falha ao gravar log: ' . $e->getMessage());
    }
}

function agora(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $texto): string
{
    $t = iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto;
    $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $t) ?? '');
    $t = trim($t, '-');
    return $t !== '' ? substr($t, 0, 60) : 'post';
}

function redir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function base_url(): string
{
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = $_SERVER['HTTP_HOST'] ?? cfg('host_publico', 'painel.ffernando.com');
    return ($https ? 'https://' : 'http://') . $host;
}

/** URL pública da mídia — é o que a API do Instagram consome. */
function midia_url(string $caminhoRelativo): string
{
    $base = rtrim((string) cfg('url_publica', 'https://painel.ffernando.com'), '/');
    return $base . '/midia/' . ltrim($caminhoRelativo, '/');
}

function fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function fmt_num($n): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    return number_format((float) $n, 0, ',', '.');
}

const STATUS_LABEL = [
    'rascunho'   => 'Rascunho',
    'aprovado'   => 'Aprovado',
    'agendado'   => 'Agendado',
    'processando' => 'Processando',
    'publicado'  => 'Publicado',
    'erro'       => 'Erro',
    'cancelado'  => 'Cancelado',
];

const TIPO_LABEL = [
    'IMAGE'    => 'Imagem única',
    'CAROUSEL' => 'Carrossel',
    'REELS'    => 'Reels',
    'STORIES'  => 'Stories',
];
