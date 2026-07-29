<?php
declare(strict_types=1);

/**
 * Renovação automática dos tokens — roda diariamente.
 * O token de longa duração vale 60 dias e pode ser renovado a qualquer momento
 * depois de 24h de vida. Renovando com folga, ele nunca vence.
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/ig.php';

header('Content-Type: text/plain; charset=utf-8');
exigir_chave_cli();

const RENOVAR_COM_DIAS_RESTANTES = 25;

$contas = q_all("SELECT * FROM contas WHERE ativo = 1 AND token IS NOT NULL AND token <> ''");
if (!$contas) {
    exit("nenhuma conta ativa\n");
}

foreach ($contas as $c) {
    $slug = $c['slug'];
    try {
        $me = ig_me((string) $c['token']);
        $diasRestantes = $c['token_expira_em'] ? (strtotime((string) $c['token_expira_em']) - time()) / 86400 : 0;

        if ($diasRestantes > RENOVAR_COM_DIAS_RESTANTES) {
            echo "{$slug}: token ok ({$me['username']}), " . (int) $diasRestantes . " dias restantes\n";
            continue;
        }

        $r = ig_refresh_token((string) $c['token']);
        $novo = (string) ($r['access_token'] ?? '');
        $seg  = (int) ($r['expires_in'] ?? 5184000);
        if ($novo === '') {
            throw new RuntimeException('a API não devolveu access_token');
        }
        q(
            'UPDATE contas SET token=?, token_renovado_em=?, token_expira_em=? WHERE id=?',
            [$novo, agora(), date('Y-m-d H:i:s', time() + $seg), $c['id']]
        );
        painel_log('info', 'refresh', "token renovado: {$slug}", ['expira_em' => date('c', time() + $seg)]);
        echo "{$slug}: token RENOVADO, vence " . date('d/m/Y', time() + $seg) . "\n";
    } catch (Throwable $e) {
        painel_log('erro', 'refresh', "{$slug}: " . $e->getMessage());
        echo "{$slug}: ERRO " . $e->getMessage() . "\n";
    }
}
