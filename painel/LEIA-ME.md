# Painel Social — agendamento e métricas do Instagram

Aplicação PHP hospedada em `painel.ffernando.com` (Hostinger, conta `u346961474`).
Publica no Instagram pela API com Login do Instagram (`graph.instagram.com`), em várias
contas, no horário marcado.

## Como funciona

1. **Painel** (`index.php`) — fila de publicação, com status por post e por conta.
2. **Editor** (`post-edit.php`) — legenda, primeiro comentário, upload de mídia e horário.
   Imagem é convertida para JPEG (exigência da API); vídeo entra como veio.
3. **Motor** (`cli/worker.php`) — chamado pelo cron a cada 5 minutos. Máquina de estados
   idempotente: cria containers, espera o processamento do vídeo, publica e comenta.
4. **Token** (`cli/refresh.php`) — renova sozinho antes de vencer. Token nunca mais expira
   por esquecimento.
5. **Métricas** (`cli/insights.php` + `metricas.php`) — retrato diário do perfil e
   desempenho por publicação, das duas contas lado a lado.

## Onde ficam os segredos

Em `lib/config.local.php`, gerado por `setup.php` na instalação e **fora do Git**.
O repositório não contém token, senha nem credencial de banco.

## Limites que vêm da API (não do painel)

- Não existe agendamento nativo — o agendamento é deste app.
- Não é possível apagar nem editar publicação pela API: correção = apagar no app e
  republicar com slug novo (`-v2`).
- 100 publicações por conta a cada 24 h; carrossel com até 10 itens.
- Imagem só JPEG; vídeo precisa de no mínimo 3 s, H.264 e faixa de áudio.
- O container de criação expira em 24 h.

## Operação

- Cron do worker: a cada 5 minutos.
- Cron de métricas: 3× por dia. Cron de renovação de token: diário.
- Toda execução relevante fica registrada em `log.php`.
