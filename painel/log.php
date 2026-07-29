<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/view.php';

exigir_configurado();
exigir_login();

$linhas = q_all('SELECT * FROM log ORDER BY id DESC LIMIT 200');

layout_topo('Log', 'log.php');
?>
<div class="cabecalho"><div><h1>Log de execução</h1><p class="sub">O que o motor fez — publicações, renovação de token, coleta de métricas e falhas.</p></div></div>
<table class="tabela">
  <thead><tr><th>Quando</th><th>Nível</th><th>Contexto</th><th>Mensagem</th></tr></thead>
  <tbody>
  <?php foreach ($linhas as $l): ?>
    <tr class="nivel-<?= h($l['nivel']) ?>">
      <td class="quando"><?= fmt_dt($l['criado_em']) ?></td>
      <td><?= h($l['nivel']) ?></td>
      <td><?= h($l['contexto']) ?></td>
      <td><?= h($l['mensagem']) ?><?php if ($l['extra']): ?><br><small><?= h((string) $l['extra']) ?></small><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$linhas): ?><tr><td colspan="4" class="vazio">Sem registros.</td></tr><?php endif; ?>
  </tbody>
</table>
<?php layout_rodape();
