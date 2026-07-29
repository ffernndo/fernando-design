<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/view.php';

exigir_configurado();
exigir_login();

$contas = q_all('SELECT * FROM contas ORDER BY id');
$contaFiltro = (int) ($_GET['conta'] ?? 0);
$condConta = $contaFiltro ? ' AND conta_id = ' . $contaFiltro : '';

/* série do perfil — últimos 30 dias */
$serie = q_all(
    "SELECT dia, conta_id, seguidores, alcance, visitas_perfil, contas_engajadas, interacoes
       FROM metricas_conta
      WHERE dia >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) {$condConta}
      ORDER BY dia ASC"
);

$hoje = q_all(
    "SELECT mc.*, c.nome, c.cor FROM metricas_conta mc JOIN contas c ON c.id = mc.conta_id
      WHERE mc.dia = (SELECT MAX(dia) FROM metricas_conta WHERE conta_id = mc.conta_id) {$condConta}
      ORDER BY c.id"
);

$posts = q_all(
    "SELECT mp.*, c.nome AS conta_nome, c.cor
       FROM metricas_post mp JOIN contas c ON c.id = mp.conta_id
      WHERE 1=1 " . ($contaFiltro ? ' AND mp.conta_id = ' . $contaFiltro : '') . "
      ORDER BY mp.publicado_em DESC
      LIMIT 60"
);

$totais = [
    'alcance'   => array_sum(array_column($posts, 'alcance')),
    'curtidas'  => array_sum(array_column($posts, 'curtidas')),
    'salvos'    => array_sum(array_column($posts, 'salvos')),
    'compart'   => array_sum(array_column($posts, 'compartilhamentos')),
];
$mediaAlcance = $posts ? $totais['alcance'] / max(1, count(array_filter(array_column($posts, 'alcance')))) : 0;

/* dados do gráfico */
$porConta = [];
foreach ($serie as $s) {
    $porConta[(int) $s['conta_id']][] = ['d' => $s['dia'], 'a' => (int) $s['alcance'], 's' => (int) $s['seguidores']];
}
$nomes = [];
foreach ($contas as $c) {
    $nomes[(int) $c['id']] = ['nome' => $c['nome'], 'cor' => $c['cor'] ?: '#1f6f5c'];
}

layout_topo('Métricas', 'metricas.php');
flash_render();
?>
<div class="cabecalho">
  <div><h1>Métricas</h1><p class="sub">Coletadas direto da API. A API é a fonte de verdade — publicações feitas fora do painel também aparecem aqui.</p></div>
</div>

<div class="filtros">
  <a href="metricas.php" class="<?= $contaFiltro === 0 ? 'on' : '' ?>">Todas as contas</a>
  <?php foreach ($contas as $c): ?>
    <a href="metricas.php?conta=<?= (int) $c['id'] ?>" class="<?= $contaFiltro === (int) $c['id'] ? 'on' : '' ?>"><?= h($c['nome']) ?></a>
  <?php endforeach; ?>
</div>

<div class="kpis">
  <?php foreach ($hoje as $r): ?>
    <div class="kpi">
      <span class="rot"><?= h($r['nome']) ?> · <?= fmt_dt((string) $r['dia']) ?></span>
      <strong><?= fmt_num($r['seguidores']) ?></strong>
      <span class="sub">seguidores</span>
      <div class="mini-linha">
        alcance no dia <b><?= fmt_num($r['alcance']) ?></b> ·
        visitas <b><?= fmt_num($r['visitas_perfil']) ?></b> ·
        engajadas <b><?= fmt_num($r['contas_engajadas']) ?></b>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="kpi">
    <span class="rot">Últimos <?= count($posts) ?> posts</span>
    <strong><?= fmt_num(round($mediaAlcance)) ?></strong>
    <span class="sub">alcance médio por post</span>
    <div class="mini-linha">
      curtidas <b><?= fmt_num($totais['curtidas']) ?></b> ·
      salvos <b><?= fmt_num($totais['salvos']) ?></b> ·
      compartilhamentos <b><?= fmt_num($totais['compart']) ?></b>
    </div>
  </div>
</div>

<h2>Alcance diário do perfil</h2>
<canvas id="g-alcance" height="120"></canvas>

<h2>Desempenho por publicação</h2>
<table class="tabela">
  <thead>
    <tr><th>Quando</th><th>Publicação</th><th>Conta</th><th class="dir">Alcance</th><th class="dir">Curtidas</th><th class="dir">Salvos</th><th class="dir">Compart.</th><th class="dir">Views</th></tr>
  </thead>
  <tbody>
  <?php foreach ($posts as $p): ?>
    <tr>
      <td class="quando"><?= fmt_dt($p['publicado_em']) ?></td>
      <td>
        <?php if ($p['permalink']): ?><a href="<?= h($p['permalink']) ?>" target="_blank" rel="noopener"><?= h(mb_substr((string) $p['legenda_curta'], 0, 70)) ?: 'sem legenda' ?></a>
        <?php else: ?><?= h(mb_substr((string) $p['legenda_curta'], 0, 70)) ?><?php endif; ?>
        <br><small><?= h((string) $p['media_type']) ?></small>
      </td>
      <td><span class="ponto" style="background:<?= h($p['cor']) ?>"></span><?= h($p['conta_nome']) ?></td>
      <td class="dir"><?= fmt_num($p['alcance']) ?></td>
      <td class="dir"><?= fmt_num($p['curtidas']) ?></td>
      <td class="dir"><?= fmt_num($p['salvos']) ?></td>
      <td class="dir"><?= fmt_num($p['compartilhamentos']) ?></td>
      <td class="dir"><?= fmt_num($p['visualizacoes']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$posts): ?>
    <tr><td colspan="8" class="vazio">Sem métricas ainda — a coleta roda algumas vezes por dia.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const series = <?= json_encode($porConta, JSON_UNESCAPED_UNICODE) ?>;
const nomes  = <?= json_encode($nomes, JSON_UNESCAPED_UNICODE) ?>;
const labels = [...new Set(Object.values(series).flat().map(p => p.d))].sort();
const ds = Object.entries(series).map(([id, pts]) => ({
  label: (nomes[id] || {}).nome || ('conta ' + id),
  data: labels.map(d => (pts.find(p => p.d === d) || {}).a ?? null),
  borderColor: (nomes[id] || {}).cor || '#1f6f5c',
  backgroundColor: 'transparent',
  tension: .3, spanGaps: true, borderWidth: 2, pointRadius: 2
}));
if (labels.length && document.getElementById('g-alcance')) {
  new Chart(document.getElementById('g-alcance'), {
    type: 'line',
    data: { labels: labels.map(d => d.split('-').reverse().slice(0,2).join('/')), datasets: ds },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: ds.length > 1, position: 'bottom' } },
      scales: { y: { beginAtZero: true, grid: { color: '#eee' } }, x: { grid: { display: false } } }
    }
  });
}
</script>
<?php layout_rodape();
