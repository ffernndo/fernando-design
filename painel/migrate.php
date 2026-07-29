<?php
declare(strict_types=1);

/** Cria e atualiza o schema. Idempotente — pode rodar quantas vezes quiser. */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/auth.php';

header('Content-Type: text/plain; charset=utf-8');
exigir_chave_cli();

$sql = [];

$sql[] = "CREATE TABLE IF NOT EXISTS contas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  usuario_ig VARCHAR(80) DEFAULT NULL,
  ig_user_id VARCHAR(40) DEFAULT NULL,
  token TEXT DEFAULT NULL,
  token_renovado_em DATETIME DEFAULT NULL,
  token_expira_em DATETIME DEFAULT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 0,
  cor VARCHAR(9) DEFAULT '#1f6f5c',
  observacao VARCHAR(255) DEFAULT NULL,
  criado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conta_id INT NOT NULL,
  titulo VARCHAR(160) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  tipo VARCHAR(12) NOT NULL DEFAULT 'IMAGE',
  legenda MEDIUMTEXT DEFAULT NULL,
  primeiro_comentario TEXT DEFAULT NULL,
  agendar_para DATETIME DEFAULT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'rascunho',
  tentativas INT NOT NULL DEFAULT 0,
  ultimo_erro TEXT DEFAULT NULL,
  container_id VARCHAR(64) DEFAULT NULL,
  filhos_ids TEXT DEFAULT NULL,
  ig_media_id VARCHAR(40) DEFAULT NULL,
  permalink VARCHAR(255) DEFAULT NULL,
  publicado_em DATETIME DEFAULT NULL,
  criado_em DATETIME NOT NULL,
  atualizado_em DATETIME NOT NULL,
  INDEX idx_fila (status, agendar_para),
  INDEX idx_conta (conta_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS midias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT NOT NULL,
  ordem INT NOT NULL DEFAULT 1,
  tipo VARCHAR(8) NOT NULL DEFAULT 'image',
  arquivo VARCHAR(255) NOT NULL,
  largura INT DEFAULT NULL,
  altura INT DEFAULT NULL,
  bytes INT DEFAULT NULL,
  criado_em DATETIME NOT NULL,
  INDEX idx_post (post_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS metricas_post (
  id INT AUTO_INCREMENT PRIMARY KEY,
  post_id INT DEFAULT NULL,
  conta_id INT NOT NULL,
  ig_media_id VARCHAR(40) NOT NULL,
  media_type VARCHAR(20) DEFAULT NULL,
  permalink VARCHAR(255) DEFAULT NULL,
  legenda_curta VARCHAR(255) DEFAULT NULL,
  publicado_em DATETIME DEFAULT NULL,
  alcance INT DEFAULT NULL,
  curtidas INT DEFAULT NULL,
  comentarios INT DEFAULT NULL,
  salvos INT DEFAULT NULL,
  compartilhamentos INT DEFAULT NULL,
  visualizacoes INT DEFAULT NULL,
  coletado_em DATETIME NOT NULL,
  UNIQUE KEY uniq_midia (ig_media_id),
  INDEX idx_conta (conta_id, publicado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS metricas_conta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conta_id INT NOT NULL,
  dia DATE NOT NULL,
  seguidores INT DEFAULT NULL,
  publicacoes INT DEFAULT NULL,
  alcance INT DEFAULT NULL,
  visitas_perfil INT DEFAULT NULL,
  contas_engajadas INT DEFAULT NULL,
  interacoes INT DEFAULT NULL,
  coletado_em DATETIME NOT NULL,
  UNIQUE KEY uniq_dia (conta_id, dia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql[] = "CREATE TABLE IF NOT EXISTS log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  criado_em DATETIME NOT NULL,
  nivel VARCHAR(10) NOT NULL,
  contexto VARCHAR(60) NOT NULL,
  mensagem TEXT NOT NULL,
  extra TEXT DEFAULT NULL,
  INDEX idx_data (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

foreach ($sql as $s) {
    db()->exec($s);
    echo "ok: " . substr(trim(explode("\n", $s)[0]), 0, 60) . "\n";
}

// diretório de mídia
if (!is_dir(PAINEL_MIDIA_DIR)) {
    @mkdir(PAINEL_MIDIA_DIR, 0755, true);
}
echo 'midia_dir=' . (is_dir(PAINEL_MIDIA_DIR) && is_writable(PAINEL_MIDIA_DIR) ? 'ok' : 'FALHA') . "\n";
echo 'gd=' . (extension_loaded('gd') ? 'ok' : 'ausente') . "\n";
echo 'contas=' . (int) q_val('SELECT COUNT(*) FROM contas') . "\n";
echo "migrate=ok\n";
