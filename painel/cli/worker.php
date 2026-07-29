<?php
declare(strict_types=1);

/**
 * Worker de publicação — chamado pelo cron a cada 5 minutos.
 *
 * Máquina de estados idempotente: cada execução avança o post até onde der e
 * grava o progresso (containers criados), para que a execução seguinte retome
 * sem refazer nada. Vídeo processa fora do nosso controle, daí o desenho.
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/ig.php';

header('Content-Type: text/plain; charset=utf-8');
exigir_chave_cli();
@set_time_limit(0);
ignore_user_abort(true);

const TOLERANCIA_ATRASO_H = 24;   // não publica post esquecido há mais de 1 dia
const MAX_TENTATIVAS      = 5;
const POLL_MAX_S          = 70;   // tempo máximo de espera por execução

$lock = fopen(sys_get_temp_dir() . '/painel_worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("ja rodando\n");
}

$agora = agora();
$saida = [];

$pendentes = q_all(
    "SELECT p.*, c.token, c.slug AS conta_slug, c.nome AS conta_nome, c.ativo AS conta_ativa
       FROM posts p JOIN contas c ON c.id = p.conta_id
      WHERE p.status IN ('agendado','processando')
        AND p.agendar_para <= ?
        AND p.tentativas < ?
      ORDER BY p.agendar_para ASC
      LIMIT 5",
    [$agora, MAX_TENTATIVAS]
);

if (!$pendentes) {
    echo "nada a publicar em {$agora}\n";
    exit;
}

foreach ($pendentes as $post) {
    $id = (int) $post['id'];
    try {
        if (!$post['conta_ativa'] || !$post['token']) {
            throw new RuntimeException('conta inativa ou sem token');
        }
        $atraso = (time() - strtotime((string) $post['agendar_para'])) / 3600;
        if ($atraso > TOLERANCIA_ATRASO_H) {
            marcar_erro($id, sprintf('atrasado %d h — publicação abortada por segurança', (int) $atraso), true);
            $saida[] = "post {$id}: abortado por atraso";
            continue;
        }

        $token  = (string) $post['token'];
        $midias = q_all('SELECT * FROM midias WHERE post_id = ? ORDER BY ordem ASC', [$id]);
        if (!$midias) {
            throw new RuntimeException('post sem mídia');
        }
        foreach ($midias as &$m) {
            $m['url'] = midia_url($m['arquivo']);
        }
        unset($m);

        // 1) a mídia precisa estar pública ANTES de qualquer chamada
        foreach ($midias as $m) {
            if (!url_publica_ok($m['url'])) {
                throw new RuntimeException('mídia não responde 200: ' . $m['url']);
            }
        }

        q("UPDATE posts SET status='processando', atualizado_em=? WHERE id=?", [agora(), $id]);

        $legenda = trim((string) $post['legenda']);
        $filhos  = $post['filhos_ids'] ? (array) json_decode((string) $post['filhos_ids'], true) : [];

        // 2) carrossel: cria os filhos que ainda faltam
        if ($post['tipo'] === 'CAROUSEL') {
            foreach ($midias as $i => $m) {
                if (!empty($filhos[$i])) {
                    continue;
                }
                $filhos[$i] = ig_criar_filho($m, $token);
                q('UPDATE posts SET filhos_ids=?, atualizado_em=? WHERE id=?', [json_encode($filhos), agora(), $id]);
                usleep(800000);
            }
            // filho de vídeo precisa terminar o processamento antes do container pai
            foreach ($midias as $i => $m) {
                if ($m['tipo'] !== 'video') {
                    continue;
                }
                if (!esperar_finished((string) $filhos[$i], $token, 45)) {
                    $saida[] = "post {$id}: vídeo do slide " . ($i + 1) . " ainda processando";
                    continue 2; // volta na próxima rodada do cron
                }
            }
        }

        // 3) container principal
        $containerId = (string) ($post['container_id'] ?? '');
        if ($containerId === '') {
            $containerId = ig_criar_container((string) $post['tipo'], $midias, $legenda, array_values($filhos), $token);
            q('UPDATE posts SET container_id=?, atualizado_em=? WHERE id=?', [$containerId, agora(), $id]);
        }

        // 4) espera ficar pronto
        if (!esperar_finished($containerId, $token, POLL_MAX_S)) {
            $saida[] = "post {$id}: container ainda processando, retoma no próximo ciclo";
            continue;
        }

        // 5) publica
        $r        = ig_publicar($containerId, $token);
        $mediaId  = (string) $r['id'];
        $permalink = ig_permalink($mediaId, $token);

        q(
            "UPDATE posts SET status='publicado', ig_media_id=?, permalink=?, publicado_em=?, ultimo_erro=NULL, atualizado_em=? WHERE id=?",
            [$mediaId, $permalink, agora(), agora(), $id]
        );
        painel_log('info', 'worker', "publicado post {$id} ({$post['conta_slug']})", ['media_id' => $mediaId, 'permalink' => $permalink]);
        $saida[] = "post {$id}: PUBLICADO {$permalink}";

        // 6) primeiro comentário (hashtags)
        $pc = trim((string) $post['primeiro_comentario']);
        if ($pc !== '') {
            try {
                sleep(3);
                ig_comentar($mediaId, $pc, $token);
                $saida[] = "post {$id}: primeiro comentário publicado";
            } catch (Throwable $e) {
                painel_log('aviso', 'worker', "post {$id}: falhou o primeiro comentário: " . $e->getMessage());
            }
        }

        sleep(2);
    } catch (Throwable $e) {
        marcar_erro($id, $e->getMessage());
        $saida[] = "post {$id}: ERRO " . $e->getMessage();
    }
}

echo implode("\n", $saida) . "\n";

function esperar_finished(string $containerId, string $token, int $limiteSegundos): bool
{
    $fim = time() + $limiteSegundos;
    do {
        $st = ig_container_status($containerId, $token);
        $code = $st['status_code'] ?? '';
        if ($code === 'FINISHED') {
            return true;
        }
        if ($code === 'ERROR' || $code === 'EXPIRED') {
            throw new RuntimeException('container ' . $code . ': ' . ($st['status'] ?? ''));
        }
        sleep(5);
    } while (time() < $fim);
    return false;
}

function marcar_erro(int $id, string $msg, bool $fatal = false): void
{
    $post = q_one('SELECT tentativas FROM posts WHERE id=?', [$id]);
    $t    = (int) ($post['tentativas'] ?? 0) + 1;
    $novoStatus = ($fatal || $t >= MAX_TENTATIVAS) ? 'erro' : 'agendado';
    q(
        'UPDATE posts SET tentativas=?, ultimo_erro=?, status=?, container_id=NULL, atualizado_em=? WHERE id=?',
        [$t, mb_substr($msg, 0, 1000), $novoStatus, agora(), $id]
    );
    painel_log('erro', 'worker', "post {$id}: {$msg}", ['tentativa' => $t, 'status' => $novoStatus]);
}
