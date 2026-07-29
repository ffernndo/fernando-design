<?php
declare(strict_types=1);

/**
 * Coleta de métricas — roda algumas vezes por dia.
 * Guarda o retrato do perfil por dia e as métricas de cada publicação recente.
 * A API é a fonte de verdade do que está no ar: publicações feitas fora do painel
 * também entram aqui.
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/ig.php';

header('Content-Type: text/plain; charset=utf-8');
exigir_chave_cli();
@set_time_limit(0);

$contas = q_all("SELECT * FROM contas WHERE ativo = 1 AND token IS NOT NULL AND token <> ''");
if (!$contas) {
    exit("nenhuma conta ativa\n");
}

foreach ($contas as $c) {
    $token = (string) $c['token'];
    $slug  = (string) $c['slug'];

    // 1) retrato do perfil no dia
    try {
        $perfil = ig_metricas_conta($token);
        q(
            'INSERT INTO metricas_conta (conta_id, dia, seguidores, publicacoes, alcance, visitas_perfil, contas_engajadas, interacoes, coletado_em)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE seguidores=VALUES(seguidores), publicacoes=VALUES(publicacoes), alcance=VALUES(alcance),
               visitas_perfil=VALUES(visitas_perfil), contas_engajadas=VALUES(contas_engajadas), interacoes=VALUES(interacoes), coletado_em=VALUES(coletado_em)',
            [
                $c['id'], date('Y-m-d'),
                $perfil['followers_count'] ?? null,
                $perfil['media_count'] ?? null,
                $perfil['reach'] ?? null,
                $perfil['profile_views'] ?? null,
                $perfil['accounts_engaged'] ?? null,
                $perfil['total_interactions'] ?? null,
                agora(),
            ]
        );
        echo "{$slug}: perfil ok (seguidores " . ($perfil['followers_count'] ?? '?') . ")\n";
    } catch (Throwable $e) {
        painel_log('erro', 'insights', "{$slug} perfil: " . $e->getMessage());
        echo "{$slug}: perfil ERRO " . $e->getMessage() . "\n";
    }

    // 2) publicações recentes
    try {
        $midias = ig_listar_midias($token, 25);
        foreach ($midias as $m) {
            $mediaId = (string) $m['id'];
            $ts      = isset($m['timestamp']) ? date('Y-m-d H:i:s', strtotime($m['timestamp'])) : null;
            $met     = [];
            try {
                $met = ig_metricas_midia($mediaId, (string) ($m['media_type'] ?? ''), $token);
            } catch (Throwable $e) {
                painel_log('aviso', 'insights', "midia {$mediaId}: " . $e->getMessage());
            }
            $postId = q_val('SELECT id FROM posts WHERE ig_media_id = ?', [$mediaId]);

            q(
                'INSERT INTO metricas_post (post_id, conta_id, ig_media_id, media_type, permalink, legenda_curta, publicado_em,
                    alcance, curtidas, comentarios, salvos, compartilhamentos, visualizacoes, coletado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE post_id=VALUES(post_id), media_type=VALUES(media_type), permalink=VALUES(permalink),
                   legenda_curta=VALUES(legenda_curta), publicado_em=VALUES(publicado_em), alcance=VALUES(alcance),
                   curtidas=VALUES(curtidas), comentarios=VALUES(comentarios), salvos=VALUES(salvos),
                   compartilhamentos=VALUES(compartilhamentos), visualizacoes=VALUES(visualizacoes), coletado_em=VALUES(coletado_em)',
                [
                    $postId ?: null, $c['id'], $mediaId, $m['media_type'] ?? null, $m['permalink'] ?? null,
                    mb_substr(trim((string) ($m['caption'] ?? '')), 0, 200), $ts,
                    $met['reach'] ?? null, $met['likes'] ?? null, $met['comments'] ?? null,
                    $met['saved'] ?? null, $met['shares'] ?? null, $met['views'] ?? null,
                    agora(),
                ]
            );
            usleep(400000);
        }
        echo "{$slug}: " . count($midias) . " publicações atualizadas\n";
    } catch (Throwable $e) {
        painel_log('erro', 'insights', "{$slug} midias: " . $e->getMessage());
        echo "{$slug}: midias ERRO " . $e->getMessage() . "\n";
    }
}
