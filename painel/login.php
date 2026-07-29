<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/view.php';

exigir_configurado();
sessao_iniciar();

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (tentar_login((string) ($_POST['senha'] ?? ''))) {
        $destino = (string) ($_GET['r'] ?? 'index.php');
        if (!preg_match('#^[a-z0-9\-_.]+\.php(\?.*)?$#i', $destino)) {
            $destino = 'index.php';
        }
        redir($destino);
    }
    $erro = 'Senha incorreta.';
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · Painel Social</title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body class="tela-login">
<form method="post" class="cartao-login">
  <h1>Painel<span>Social</span></h1>
  <p class="sub">Agendamento e métricas do Instagram</p>
  <?php if ($erro): ?><div class="aviso erro"><?= h($erro) ?></div><?php endif; ?>
  <label>Senha
    <input type="password" name="senha" autocomplete="current-password" autofocus required>
  </label>
  <button type="submit">Entrar</button>
</form>
</body>
</html>
