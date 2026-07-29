<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/ig.php';

exigir_configurado();
exigir_login();

/* ---------- ações ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $id   = (int) ($_POST['id'] ?? 0);
    $acao = (string) ($_POST['acao'] ?? '');
    $post = $id ? q_one('SELECT * FROM posts WHERE id=?', [$id]) : null;

    if ($post) {
        switch ($acao) {
            case 'agendar':
                if (!$post['agendar_para']) {
                    flash('erro', 'Defina a data e a hora antes de agendar.');
                    break;
                }
                q("UPDATE posts SET status='agendado', tentativas=0, ultimo_erro=NULL, atualizado_em=? WHERE id=?", [agora(), $id]);
                flash('ok', 'Post agendado para ' . fmt_dt($post['agendar_para']) . '.');
                break;
            case 'pausar':
                q("UPDATE posts SET status='aprovado', atualizado_em=? WHERE id=?", [agora(), $id]);
                flash('ok', 'Agendamento pausado — o post não sai até você agendar de novo.');
                break;
            case 'cancelar':
                q("UPDATE posts SET status='cancelado', atualizado_em=? WHERE id=?", [agora(), $id]);
                flash('ok', 'Post cancelado.');
                break;
            case 'publicar_agora':
                q("UPDATE posts SET status='agendado', agendar_para=?, tentativas=0, ultimo_erro=NULL, atualizado_em=? WHERE id=?", [agora(), agora(), $id]);
                flash('ok', 'Marcado para publicar no próximo ciclo (até 5 minutos).');
                break;
            case 'reenfileirar':
                q("UPDATE posts SET status='agendado', tentativas=0, ultimo_erro=NULL, container_id=NULL, filhos_ids=NULL, atualizado_em=? WHERE id=?", [agora(), $id]);
                flash('ok', 'Post recolocado na fila.');
                break;
        }
    }
    redir('index.php');
}

/* ---------- dados ---------- */
$contas     = q_all('SELECT * FROM contas ORDER BY id');
$contaFiltro = (int) ($_GET['conta'] ?? 0);
$where      = $contaFiltro ? 'WHERE p.conta_id = ' . $contaFiltro : '';

$fila = q_all(
    "SELECT p.*, c.nome AS conta_nome, c.slug AS conta_slug, c.cor,
            (SELECT COUNT(*) FROM midias m WHERE m.post_id = p.id) AS n_midias
       FROM posts p JOIN contas c ON c.id = p.conta_id
       {$where}
      ORDER BY (p.status='publicado') ASC, COALESCE(p.agendar_para, p.criado_em) ASC
      LIMIT 200"
);

$resumo = [];
foreach ($fila as $p) {
    $resumo[$p['status']] = ($resumo[$p['status']] ?? 0) + 1;
}
$proximo = null;
foreach ($fila as $p) {
    if ($p['status'] === 'agendado' && $p['agendar_para'] >= agora()) {
        $proximo = $p;
        break;
    }
}

layout_topo('Fila', 'index.php');
flash_render();
?>
<div class="cabecalho">
  <div>
    <h1>Fila de publicação</h1>
    <p class="sub">
      <?= (int) ($resumo['agendado'] ?? 0) ?> agendados ·
      <?= (int) ($resumo['rascunho'] ?? 0) + (int) ($resumo['aprovado'] ?? 0) ?> em preparo ·
      <?= (int) ($resumo['publicado'] ?? 0) ?> publicados
      <?php if (!empty($resumo['erro'])): ?> · <strong class="txt-erro"><?= (int) $resumo['erro'] ?> com erro</strong><?php endif; ?>
    </p>
  </div>
  <div class="acoes-topo">
    <a class="botao" href="post-edit.php">+ Novo post</a>
  </div>
</div>

<?php if (!$contas): ?>
  <div class="aviso erro">Nenhuma conta cadastrada ainda. Comece em <a href="contas.php">Contas</a>.</div>
<?php endif; ?>

<?php if ($proximo): ?>
  <div class="destaque">
    Próxima publicação: <strong><?= h($proximo['titulo']) ?></strong> —
    <?= fmt_dt($proximo['agendar_para']) ?> em <?= h($proximo['conta_nome']) ?>
  </div>
<?php endif; ?>

<div class="filtros">
  <a href="index.php" class="<?= $contaFiltro === 0 ? 'on' : '' ?>">Todas as contas</a>
  <?php foreach ($contas as $c): ?>
    <a href="index.php?conta=<?= (int) $c['id'] ?>" class="<?= $contaFiltro === (int) $c['id'] ? 'on' : '' ?>"><?= h($c['nome']) ?></a>
  <?php endforeach; ?>
</div>

<table class="tabela">
  <thead>
    <tr><th>Quando</th><th>Post</th><th>Conta</th><th>Tipo</th><th>Status</th><th class="dir">Ações</th></tr>
  </thead>
  <tbody>
  <?php foreach ($fila as $p): ?>
    <tr class="linha s-<?= h($p['status']) ?>">
      <td class="quando">
        <?= fmt_dt($p['agendar_para']) ?>
        <?php if ($p['publicado_em']): ?><br><small>publicado <?= fmt_dt($p['publicado_em']) ?></small><?php endif; ?>
      </td>
      <td>
        <a href="post-edit.php?id=<?= (int) $p['id'] ?>"><strong><?= h($p['titulo']) ?></strong></a>
        <br><small><?= (int) $p['n_midias'] ?> mídia(s)<?= $p['permalink'] ? ' · <a href="' . h($p['permalink']) . '" target="_blank" rel="noopener">ver no Instagram</a>' : '' ?></small>
        <?php if ($p['ultimo_erro']): ?><br><small class="txt-erro"><?= h(mb_substr((string) $p['ultimo_erro'], 0, 160)) ?></small><?php endif; ?>
      </td>
      <td><span class="ponto" style="background:<?= h($p['cor'] ?: '#1f6f5c') ?>"></span><?= h($p['conta_nome']) ?></td>
      <td><?= h(TIPO_LABEL[$p['tipo']] ?? $p['tipo']) ?></td>
      <td><?= badge_status((string) $p['status']) ?></td>
      <td class="dir acoes">
        <form method="post" class="inline">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <?php if (in_array($p['status'], ['rascunho', 'aprovado'], true)): ?>
            <button name="acao" value="agendar" class="mini">Agendar</button>
            <button name="acao" value="publicar_agora" class="mini alt" onclick="return confirm('Publicar agora, no próximo ciclo?')">Publicar agora</button>
          <?php elseif ($p['status'] === 'agendado'): ?>
            <button name="acao" value="pausar" class="mini">Pausar</button>
          <?php elseif ($p['status'] === 'erro'): ?>
            <button name="acao" value="reenfileirar" class="mini">Tentar de novo</button>
          <?php endif; ?>
          <?php if ($p['status'] !== 'publicado'): ?>
            <button name="acao" value="cancelar" class="mini fraco" onclick="return confirm('Cancelar este post?')">Cancelar</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$fila): ?>
    <tr><td colspan="6" class="vazio">Nada na fila ainda. Crie o primeiro post.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php layout_rodape();
