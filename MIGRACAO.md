# Branch migracao-ffernando-com — merge SÓ quando o domínio resolver

Pré-condições para o merge (ver claude/megaplano-agosto-2026.md, passos 1–3):
1. ffernando.com resgatado, DNS apontado, SSL ativo na Hostinger
2. Deploy via Git do hPanel configurado neste repo
3. `curl -I https://ffernando.com` respondendo 200

O que este branch contém:
- canonical, og:url, og:image, twitter, sitemap.xml, robots.txt e JSON-LD → https://ffernando.com
- Links para outros repos do Pages (webapps, fisiocamillasodre, portfolio v1) INTOCADOS — continuam lá
- assets/ig/ intocado — host de mídia do Instagram segue no Pages

Depois do merge, ainda falta (fora deste branch):
- Página de redirect no Pages (meta-refresh + canonical) — substituir index após Hostinger no ar
- Trocar URL de privacidade no painel da Meta
- Bio do Instagram → ffernando.com
- Pixel + verificação de domínio (passos 8–9 do megaplano)
