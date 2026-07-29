<?php
declare(strict_types=1);

function layout_topo(string $titulo, string $ativo = ''): void
{
    $itens = [
        'index.php'    => 'Fila',
        'metricas.php' => 'Métricas',
        'contas.php'   => 'Contas',
        'log.php'      => 'Log',
    ];
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titulo) ?> · Painel Social</title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
<header class="topo">
  <a class="marca" href="index.php">Painel<span>Social</span></a>
  <nav>
    <?php foreach ($itens as $url => $rot): ?>
      <a href="<?= h($url) ?>"<?= $ativo === $url ? ' class="on"' : '' ?>><?= h($rot) ?></a>
    <?php endforeach; ?>
  </nav>
  <a class="sair" href="logout.php">Sair</a>
</header>
<main>
<?php
}

function layout_rodape(): void
{
    ?>
</main>
<footer class="rodape">Painel Social · <?= date('d/m/Y H:i') ?> (Brasília)</footer>
</body>
</html>
<?php
}

function flash(string $tipo, string $msg): void
{
    sessao_iniciar();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $msg];
}

function flash_render(): void
{
    sessao_iniciar();
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="aviso ' . h($f['tipo']) . '">' . h($f['msg']) . '</div>';
    }
    $_SESSION['flash'] = [];
}

function badge_status(string $status): string
{
    return '<span class="badge s-' . h($status) . '">' . h(STATUS_LABEL[$status] ?? $status) . '</span>';
}
