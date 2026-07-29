<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/ig.php';

exigir_configurado();
exigir_login();

$id     = (int) ($_GET['id'] ?? 0);
$contas = q_all('SELECT * FROM contas ORDER BY id');
$post   = $id ? q_one('SELECT * FROM posts WHERE id=?', [$id]) : null;

if ($id && !$post) {
    http_response_code(404);
    exit('Post não encontrado.');
}

/* ------------------------------------------------------------------ ações */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $acao = (string) ($_POST['acao'] ?? 'salvar');

    if ($acao === 'excluir_midia') {
        $mid = (int) ($_POST['midia_id'] ?? 0);
        $m   = q_one('SELECT * FROM midias WHERE id=? AND post_id=?', [$mid, $id]);
        if ($m) {
            @unlink(PAINEL_MIDIA_DIR . '/' . $m['arquivo']);
            q('DELETE FROM midias WHERE id=?', [$mid]);
            reordenar_midias($id);
            flash('ok', 'Mídia removida.');
        }
        redir('post-edit.php?id=' . $id);
    }

    if ($acao === 'mover_midia') {
        $mid = (int) ($_POST['midia_id'] ?? 0);
        $dir = ($_POST['dir'] ?? 'cima') === 'cima' ? -1 : 1;
        mover_midia($id, $mid, $dir);
        redir('post-edit.php?id=' . $id);
    }

    /* ---- salvar o post ---- */
    $contaId = (int) ($_POST['conta_id'] ?? 0);
    $titulo  = trim((string) ($_POST['titulo'] ?? ''));
    $tipo    = (string) ($_POST['tipo'] ?? 'IMAGE');
    $legenda = (string) ($_POST['legenda'] ?? '');
    $pc      = (string) ($_POST['primeiro_comentario'] ?? '');
    $data    = trim((string) ($_POST['agendar_para'] ?? ''));
    $agendar = $data !== '' ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $data))) : null;

    if ($titulo === '' || !$contaId) {
        flash('erro', 'Título e conta são obrigatórios.');
    } else {
        if (!$post) {
            q(
                'INSERT INTO posts (conta_id, titulo, slug, tipo, legenda, primeiro_comentario, agendar_para, status, criado_em, atualizado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$contaId, $titulo, slugify($titulo) . '-' . date('md-Hi'), $tipo, $legenda, $pc, $agendar, 'rascunho', agora(), agora()]
            );
            $id   = (int) db()->lastInsertId();
            $post = q_one('SELECT * FROM posts WHERE id=?', [$id]);
            flash('ok', 'Post criado.');
        } else {
            $bloqueado = in_array($post['status'], ['publicado'], true);
            if ($bloqueado) {
                flash('erro', 'Post já publicado — a API do Instagram não permite editar nem apagar. Crie uma versão nova.');
            } else {
                q(
                    'UPDATE posts SET conta_id=?, titulo=?, tipo=?, legenda=?, primeiro_comentario=?, agendar_para=?, atualizado_em=? WHERE id=?',
                    [$contaId, $titulo, $tipo, $legenda, $pc, $agendar, agora(), $id]
                );
                flash('ok', 'Alterações salvas.');
            }
        }

        // upload de mídia
        if ($post && !empty($_FILES['arquivos']['name'][0])) {
            $conta = q_one('SELECT * FROM contas WHERE id=?', [$contaId]);
            $res   = receber_uploads($id, (string) $conta['slug'], (string) $post['slug']);
            foreach ($res['erros'] as $e) {
                flash('erro', $e);
            }
            if ($res['ok']) {
                flash('ok', $res['ok'] . ' arquivo(s) enviado(s).');
            }
        }

        if (($_POST['e_agendar'] ?? '') === '1' && $agendar) {
            q("UPDATE posts SET status='agendado', tentativas=0, ultimo_erro=NULL, atualizado_em=? WHERE id=? AND status<>'publicado'", [agora(), $id]);
            flash('ok', 'Agendado para ' . fmt_dt($agendar) . '.');
        }
    }
    redir('post-edit.php?id=' . $id);
}

/* -------------------------------------------------------------- funções */
function reordenar_midias(int $postId): void
{
    $n = 1;
    foreach (q_all('SELECT id FROM midias WHERE post_id=? ORDER BY ordem ASC', [$postId]) as $m) {
        q('UPDATE midias SET ordem=? WHERE id=?', [$n++, $m['id']]);
    }
}

function mover_midia(int $postId, int $midiaId, int $dir): void
{
    $lista = q_all('SELECT id FROM midias WHERE post_id=? ORDER BY ordem ASC', [$postId]);
    $ids   = array_column($lista, 'id');
    $pos   = array_search($midiaId, $ids, false);
    if ($pos === false) {
        return;
    }
    $nova = $pos + $dir;
    if ($nova < 0 || $nova >= count($ids)) {
        return;
    }
    [$ids[$pos], $ids[$nova]] = [$ids[$nova], $ids[$pos]];
    foreach ($ids as $i => $mid) {
        q('UPDATE midias SET ordem=? WHERE id=?', [$i + 1, $mid]);
    }
}

/**
 * Recebe arquivos, normaliza imagem para JPEG (exigência da API) e grava.
 * Vídeo é aceito como veio: o preparo (3 s mínimo, faixa de áudio, H.264) é feito antes.
 */
function receber_uploads(int $postId, string $contaSlug, string $postSlug): array
{
    $erros = [];
    $ok    = 0;
    $dirRel = $contaSlug . '/' . $postSlug;
    $dirAbs = PAINEL_MIDIA_DIR . '/' . $dirRel;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true)) {
        return ['ok' => 0, 'erros' => ['não consegui criar a pasta de mídia']];
    }

    $ordem = (int) (q_val('SELECT COALESCE(MAX(ordem),0) FROM midias WHERE post_id=?', [$postId]) ?? 0);
    $total = count($_FILES['arquivos']['name']);

    for ($i = 0; $i < $total; $i++) {
        if ((int) $_FILES['arquivos']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp  = $_FILES['arquivos']['tmp_name'][$i];
        $nome = (string) $_FILES['arquivos']['name'][$i];
        $mime = (string) (mime_content_type($tmp) ?: '');
        $ordem++;

        if (str_starts_with($mime, 'image/')) {
            $destinoRel = $dirRel . '/' . sprintf('%02d', $ordem) . '.jpg';
            $r = normalizar_imagem($tmp, PAINEL_MIDIA_DIR . '/' . $destinoRel);
            if (!$r) {
                $erros[] = "não consegui converter {$nome} para JPEG";
                $ordem--;
                continue;
            }
            q(
                'INSERT INTO midias (post_id, ordem, tipo, arquivo, largura, altura, bytes, criado_em) VALUES (?,?,?,?,?,?,?,?)',
                [$postId, $ordem, 'image', $destinoRel, $r['w'], $r['h'], $r['bytes'], agora()]
            );
            $ok++;
        } elseif (str_starts_with($mime, 'video/')) {
            $destinoRel = $dirRel . '/' . sprintf('%02d', $ordem) . '.mp4';
            if (!move_uploaded_file($tmp, PAINEL_MIDIA_DIR . '/' . $destinoRel)) {
                $erros[] = "falha ao salvar {$nome}";
                $ordem--;
                continue;
            }
            @chmod(PAINEL_MIDIA_DIR . '/' . $destinoRel, 0644);
            q(
                'INSERT INTO midias (post_id, ordem, tipo, arquivo, bytes, criado_em) VALUES (?,?,?,?,?,?)',
                [$postId, $ordem, 'video', $destinoRel, filesize(PAINEL_MIDIA_DIR . '/' . $destinoRel), agora()]
            );
            $ok++;
        } else {
            $erros[] = "tipo não aceito: {$nome} ({$mime})";
            $ordem--;
        }
    }
    return ['ok' => $ok, 'erros' => $erros];
}

function normalizar_imagem(string $origem, string $destino): ?array
{
    if (!extension_loaded('gd')) {
        return move_uploaded_file($origem, $destino) ? ['w' => null, 'h' => null, 'bytes' => filesize($destino)] : null;
    }
    $info = @getimagesize($origem);
    if (!$info) {
        return null;
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($origem),
        IMAGETYPE_PNG  => @imagecreatefrompng($origem),
        IMAGETYPE_WEBP => @imagecreatefromwebp($origem),
        default        => null,
    };
    if (!$img) {
        return null;
    }
    [$w, $h] = [imagesx($img), imagesy($img)];

    // limite prático do Instagram: 1440 px de largura
    if ($w > 1440) {
        $nh  = (int) round($h * 1440 / $w);
        $novo = imagecreatetruecolor(1440, $nh);
        imagefill($novo, 0, 0, imagecolorallocate($novo, 255, 255, 255));
        imagecopyresampled($novo, $img, 0, 0, 0, 0, 1440, $nh, $w, $h);
        imagedestroy($img);
        $img = $novo;
        [$w, $h] = [1440, $nh];
    } else {
        // achata transparência do PNG sobre branco (a API só aceita JPEG)
        $novo = imagecreatetruecolor($w, $h);
        imagefill($novo, 0, 0, imagecolorallocate($novo, 255, 255, 255));
        imagecopy($novo, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $novo;
    }

    imageinterlace($img, false);
    $ok = imagejpeg($img, $destino, 92);
    imagedestroy($img);
    if (!$ok) {
        return null;
    }
    @chmod($destino, 0644);
    return ['w' => $w, 'h' => $h, 'bytes' => filesize($destino)];
}

/* --------------------------------------------------------------- render */
$midias = $id ? q_all('SELECT * FROM midias WHERE post_id=? ORDER BY ordem ASC', [$id]) : [];
$bloqueado = $post && $post['status'] === 'publicado';

layout_topo($post ? 'Editar post' : 'Novo post', 'index.php');
flash_render();
?>
<div class="cabecalho">
  <div>
    <h1><?= $post ? h($post['titulo']) : 'Novo post' ?></h1>
    <p class="sub">
      <?= $post ? badge_status((string) $post['status']) : 'Rascunho novo' ?>
      <?php if ($post && $post['permalink']): ?> · <a href="<?= h($post['permalink']) ?>" target="_blank" rel="noopener">ver no Instagram</a><?php endif; ?>
    </p>
  </div>
  <div class="acoes-topo"><a class="botao fraco" href="index.php">← Voltar para a fila</a></div>
</div>

<?php if ($bloqueado): ?>
  <div class="aviso">Post publicado. A API do Instagram não edita nem apaga publicação — para corrigir, apague no app e crie uma versão nova (slug <code>-v2</code>).</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form-post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

  <div class="grade2">
    <label>Conta
      <select name="conta_id" <?= $bloqueado ? 'disabled' : '' ?> required>
        <?php foreach ($contas as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $post && (int) $post['conta_id'] === (int) $c['id'] ? 'selected' : '' ?> <?= $c['ativo'] ? '' : 'disabled' ?>>
            <?= h($c['nome']) ?><?= $c['ativo'] ? '' : ' (sem token)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Tipo
      <select name="tipo" <?= $bloqueado ? 'disabled' : '' ?>>
        <?php foreach (TIPO_LABEL as $v => $rot): ?>
          <option value="<?= h($v) ?>" <?= $post && $post['tipo'] === $v ? 'selected' : '' ?>><?= h($rot) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <label>Título interno (não vai para o Instagram)
    <input type="text" name="titulo" value="<?= h($post['titulo'] ?? '') ?>" maxlength="160" required <?= $bloqueado ? 'disabled' : '' ?>>
  </label>

  <label>Legenda
    <textarea name="legenda" rows="9" <?= $bloqueado ? 'disabled' : '' ?>><?= h($post['legenda'] ?? '') ?></textarea>
    <small class="dica">Quebra de linha e emoji vão como estão. Limite do Instagram: 2.200 caracteres.</small>
  </label>

  <label>Primeiro comentário (hashtags)
    <textarea name="primeiro_comentario" rows="3" <?= $bloqueado ? 'disabled' : '' ?>><?= h($post['primeiro_comentario'] ?? '') ?></textarea>
  </label>

  <label>Publicar em
    <input type="datetime-local" name="agendar_para"
           value="<?= $post && $post['agendar_para'] ? date('Y-m-d\TH:i', strtotime((string) $post['agendar_para'])) : '' ?>" <?= $bloqueado ? 'disabled' : '' ?>>
    <small class="dica">Horário de Brasília. O motor roda a cada 5 minutos — a publicação sai no primeiro ciclo depois da hora marcada.</small>
  </label>

  <?php if (!$bloqueado): ?>
  <label>Enviar mídia
    <input type="file" name="arquivos[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4">
    <small class="dica">Imagem é convertida para JPEG automaticamente. Vídeo precisa vir pronto: H.264, mínimo 3 s, com faixa de áudio.</small>
  </label>
  <?php endif; ?>

  <div class="barra-acoes">
    <button type="submit" name="acao" value="salvar" <?= $bloqueado ? 'disabled' : '' ?>>Salvar</button>
    <button type="submit" name="e_agendar" value="1" class="alt" <?= $bloqueado ? 'disabled' : '' ?>>Salvar e agendar</button>
  </div>
</form>

<?php if ($midias): ?>
<h2>Mídia (<?= count($midias) ?>)</h2>
<div class="galeria">
  <?php foreach ($midias as $m): ?>
    <div class="item">
      <div class="thumb">
        <?php if ($m['tipo'] === 'video'): ?>
          <video src="<?= h(midia_url((string) $m['arquivo'])) ?>" muted controls preload="metadata"></video>
        <?php else: ?>
          <img src="<?= h(midia_url((string) $m['arquivo'])) ?>" alt="">
        <?php endif; ?>
      </div>
      <div class="meta">
        <strong>#<?= (int) $m['ordem'] ?></strong> · <?= h($m['tipo']) ?>
        <?= $m['largura'] ? ' · ' . (int) $m['largura'] . '×' . (int) $m['altura'] : '' ?>
        · <?= fmt_num(round(((int) $m['bytes']) / 1024)) ?> KB
      </div>
      <?php if (!$bloqueado): ?>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="midia_id" value="<?= (int) $m['id'] ?>">
        <button class="mini" name="acao" value="mover_midia" formnovalidate>↑</button>
        <button class="mini" name="acao" value="mover_midia" formnovalidate onclick="this.form.dir.value='baixo'">↓</button>
        <input type="hidden" name="dir" value="cima">
        <button class="mini fraco" name="acao" value="excluir_midia" formnovalidate onclick="return confirm('Remover esta mídia?')">Remover</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($post && $post['ultimo_erro']): ?>
  <div class="aviso erro"><strong>Último erro:</strong> <?= h((string) $post['ultimo_erro']) ?> (tentativas: <?= (int) $post['tentativas'] ?>)</div>
<?php endif; ?>

<?php layout_rodape();
