<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/ig.php';

exigir_configurado();
exigir_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $acao = (string) ($_POST['acao'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    if ($acao === 'salvar') {
        $nome  = trim((string) ($_POST['nome'] ?? ''));
        $slug  = slugify((string) ($_POST['slug'] ?? $nome));
        $cor   = (string) ($_POST['cor'] ?? '#1f6f5c');
        $obs   = trim((string) ($_POST['observacao'] ?? ''));
        $token = trim((string) ($_POST['token'] ?? ''));

        if ($nome === '') {
            flash('erro', 'Nome é obrigatório.');
            redir('contas.php');
        }

        if ($id) {
            q('UPDATE contas SET nome=?, slug=?, cor=?, observacao=? WHERE id=?', [$nome, $slug, $cor, $obs, $id]);
        } else {
            q('INSERT INTO contas (slug, nome, cor, observacao, ativo, criado_em) VALUES (?,?,?,?,0,?)', [$slug, $nome, $cor, $obs, agora()]);
            $id = (int) db()->lastInsertId();
        }

        if ($token !== '') {
            try {
                $me = ig_me($token);
                q(
                    'UPDATE contas SET token=?, usuario_ig=?, ig_user_id=?, ativo=1, token_renovado_em=?, token_expira_em=? WHERE id=?',
                    [$token, $me['username'] ?? null, (string) ($me['user_id'] ?? $me['id'] ?? ''), agora(), date('Y-m-d H:i:s', time() + 60 * 86400), $id]
                );
                flash('ok', 'Token validado: @' . ($me['username'] ?? '?') . ' está conectada.');
            } catch (Throwable $e) {
                flash('erro', 'Token recusado pela API: ' . $e->getMessage());
            }
        }
        flash('ok', 'Conta salva.');
        redir('contas.php');
    }

    if ($acao === 'renovar' && $id) {
        $c = q_one('SELECT * FROM contas WHERE id=?', [$id]);
        try {
            $r = ig_refresh_token((string) $c['token']);
            $seg = (int) ($r['expires_in'] ?? 5184000);
            q('UPDATE contas SET token=?, token_renovado_em=?, token_expira_em=? WHERE id=?', [$r['access_token'], agora(), date('Y-m-d H:i:s', time() + $seg), $id]);
            flash('ok', 'Token renovado até ' . date('d/m/Y', time() + $seg) . '.');
        } catch (Throwable $e) {
            flash('erro', 'Falha ao renovar: ' . $e->getMessage());
        }
        redir('contas.php');
    }

    if ($acao === 'desativar' && $id) {
        q('UPDATE contas SET ativo=0 WHERE id=?', [$id]);
        flash('ok', 'Conta desativada — nada será publicado nela.');
        redir('contas.php');
    }
}

$contas = q_all('SELECT * FROM contas ORDER BY id');
$editar = (int) ($_GET['editar'] ?? 0);
$emEdicao = $editar ? q_one('SELECT * FROM contas WHERE id=?', [$editar]) : null;

layout_topo('Contas', 'contas.php');
flash_render();
?>
<div class="cabecalho">
  <div><h1>Contas conectadas</h1><p class="sub">Cada conta publica com o próprio token. Sem token válido, a conta fica inativa e não recebe agendamento.</p></div>
</div>

<div class="cartoes">
<?php foreach ($contas as $c):
    $dias = $c['token_expira_em'] ? (int) floor((strtotime((string) $c['token_expira_em']) - time()) / 86400) : null;
    $limite = null;
    if ($c['ativo'] && $c['token']) {
        try {
            $l = ig_limite_publicacao((string) $c['token']);
            $limite = $l['data'][0]['quota_usage'] ?? null;
        } catch (Throwable $e) {
            $limite = null;
        }
    }
    ?>
  <div class="cartao">
    <div class="cartao-topo">
      <span class="ponto" style="background:<?= h($c['cor'] ?: '#1f6f5c') ?>"></span>
      <strong><?= h($c['nome']) ?></strong>
      <?= $c['ativo'] ? '<span class="badge s-publicado">ativa</span>' : '<span class="badge s-erro">inativa</span>' ?>
    </div>
    <dl>
      <dt>Perfil</dt><dd><?= $c['usuario_ig'] ? '@' . h($c['usuario_ig']) : '—' ?></dd>
      <dt>Token</dt><dd><?= $c['token'] ? 'gravado · ' . ($dias !== null ? $dias . ' dias restantes' : 'validade desconhecida') : 'ausente' ?></dd>
      <dt>Publicações hoje</dt><dd><?= $limite !== null ? (int) $limite . ' / 100' : '—' ?></dd>
      <?php if ($c['observacao']): ?><dt>Nota</dt><dd><?= h($c['observacao']) ?></dd><?php endif; ?>
    </dl>
    <div class="acoes">
      <a class="mini" href="contas.php?editar=<?= (int) $c['id'] ?>">Editar</a>
      <?php if ($c['token']): ?>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <button class="mini" name="acao" value="renovar">Renovar token</button>
        <button class="mini fraco" name="acao" value="desativar" onclick="return confirm('Desativar esta conta?')">Desativar</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<h2><?= $emEdicao ? 'Editar ' . h($emEdicao['nome']) : 'Adicionar conta' ?></h2>
<form method="post" class="form-post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int) ($emEdicao['id'] ?? 0) ?>">
  <div class="grade2">
    <label>Nome<input type="text" name="nome" value="<?= h($emEdicao['nome'] ?? '') ?>" required></label>
    <label>Identificador<input type="text" name="slug" value="<?= h($emEdicao['slug'] ?? '') ?>" placeholder="ffernando"></label>
  </div>
  <div class="grade2">
    <label>Cor<input type="color" name="cor" value="<?= h($emEdicao['cor'] ?? '#1f6f5c') ?>"></label>
    <label>Nota interna<input type="text" name="observacao" value="<?= h($emEdicao['observacao'] ?? '') ?>"></label>
  </div>
  <label>Token de acesso (cole para gravar ou substituir)
    <input type="password" name="token" autocomplete="off" placeholder="IGAAO…">
    <small class="dica">O token é validado na hora contra a API. Depois de gravado, a renovação passa a ser automática.</small>
  </label>
  <div class="barra-acoes"><button name="acao" value="salvar">Salvar conta</button></div>
</form>
<?php layout_rodape();
