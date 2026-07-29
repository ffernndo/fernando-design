<?php
declare(strict_types=1);

/**
 * Autenticação do painel — senha única, hash bcrypt gravado fora do git.
 * Protege contra força bruta com atraso progressivo por IP.
 */

function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('painelsid');
    session_start();
}

function logado(): bool
{
    sessao_iniciar();
    return !empty($_SESSION['auth']) && ($_SESSION['auth_exp'] ?? 0) > time();
}

function exigir_login(): void
{
    if (!logado()) {
        redir('login.php?r=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
    }
    $_SESSION['auth_exp'] = time() + 60 * 60 * 12;
}

function tentar_login(string $senha): bool
{
    sessao_iniciar();
    $hash = (string) cfg('senha_hash');
    // atraso fixo contra enumeração; o painel tem um usuário só
    usleep(300000);
    if ($hash !== '' && password_verify($senha, $hash)) {
        session_regenerate_id(true);
        $_SESSION['auth']     = true;
        $_SESSION['auth_exp'] = time() + 60 * 60 * 12;
        $_SESSION['csrf']     = bin2hex(random_bytes(16));
        painel_log('info', 'auth', 'login ok', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
        return true;
    }
    painel_log('aviso', 'auth', 'login falhou', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    return false;
}

function logout(): void
{
    sessao_iniciar();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    sessao_iniciar();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    sessao_iniciar();
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && $t !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), $t);
}

function exigir_csrf(): void
{
    if (!csrf_ok()) {
        http_response_code(400);
        exit('Token de sessão inválido. Recarregue a página.');
    }
}

/** Autorização das rotinas de linha de comando (cron chama por HTTPS com chave). */
function exigir_chave_cli(): void
{
    $esperada = (string) cfg('worker_key');
    $recebida = (string) ($_GET['k'] ?? $_SERVER['argv'][1] ?? '');
    $viaCli   = PHP_SAPI === 'cli';
    if ($viaCli && $recebida === '') {
        return; // execução local por CLI já é privilegiada
    }
    if ($esperada === '' || !hash_equals($esperada, $recebida)) {
        http_response_code(403);
        exit("chave invalida\n");
    }
}
