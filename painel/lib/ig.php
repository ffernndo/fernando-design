<?php
declare(strict_types=1);

/**
 * Cliente da API do Instagram (API com Login do Instagram).
 * Host correto: graph.instagram.com — tokens IGAAO… NÃO funcionam em graph.facebook.com.
 */

const IG_BASE = 'https://graph.instagram.com/v23.0';

class IgErro extends RuntimeException
{
    public array $detalhe;
    public function __construct(string $msg, array $detalhe = [])
    {
        parent::__construct($msg);
        $this->detalhe = $detalhe;
    }
}

function ig_request(string $metodo, string $caminho, array $params, ?string $token, int $timeout = 45): array
{
    $url = str_starts_with($caminho, 'http') ? $caminho : IG_BASE . '/' . ltrim($caminho, '/');
    $ch  = curl_init();
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if ($metodo === 'GET') {
        if ($params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errc = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new IgErro('Falha de rede ao chamar a API: ' . $errc);
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        throw new IgErro('Resposta inválida da API (HTTP ' . $http . ')', ['body' => substr((string) $body, 0, 500)]);
    }
    if (isset($json['error'])) {
        $e = $json['error'];
        throw new IgErro(
            sprintf('API: %s (code %s/%s)', $e['message'] ?? 'erro', $e['code'] ?? '?', $e['error_subcode'] ?? '-'),
            $e
        );
    }
    if ($http >= 400) {
        throw new IgErro('HTTP ' . $http . ' na API', ['body' => $json]);
    }
    return $json;
}

function ig_get(string $caminho, array $params, string $token): array
{
    return ig_request('GET', $caminho, $params, $token);
}

function ig_post(string $caminho, array $params, string $token): array
{
    return ig_request('POST', $caminho, $params, $token);
}

/** Identidade da conta — usado para validar token. */
function ig_me(string $token): array
{
    return ig_get('me', ['fields' => 'user_id,username,account_type,media_count,followers_count'], $token);
}

/** Renova o token de longa duração (válido enquanto tiver mais de 24h de vida). */
function ig_refresh_token(string $token): array
{
    return ig_request('GET', 'https://graph.instagram.com/refresh_access_token', [
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $token,
    ], null);
}

function ig_container_status(string $containerId, string $token): array
{
    return ig_get($containerId, ['fields' => 'status_code,status'], $token);
}

/** Cria container de item de carrossel (imagem ou vídeo). */
function ig_criar_filho(array $midia, string $token): string
{
    $p = ['is_carousel_item' => 'true'];
    if ($midia['tipo'] === 'video') {
        $p['video_url']  = $midia['url'];
        $p['media_type'] = 'VIDEO'; // VIDEO dentro de carrossel; REELS é para vídeo autônomo
    } else {
        $p['image_url'] = $midia['url'];
    }
    $r = ig_post('me/media', $p, $token);
    if (empty($r['id'])) {
        throw new IgErro('A API não devolveu id do container filho', $r);
    }
    return (string) $r['id'];
}

/** Cria o container principal conforme o tipo do post. */
function ig_criar_container(string $tipo, array $midias, string $legenda, array $filhos, string $token): string
{
    switch ($tipo) {
        case 'CAROUSEL':
            $p = [
                'media_type' => 'CAROUSEL',
                'children'   => implode(',', $filhos),
                'caption'    => $legenda,
            ];
            break;
        case 'REELS':
            $p = [
                'media_type' => 'REELS',
                'video_url'  => $midias[0]['url'],
                'caption'    => $legenda,
            ];
            break;
        case 'STORIES':
            $p = ['media_type' => 'STORIES'];
            if (($midias[0]['tipo'] ?? 'image') === 'video') {
                $p['video_url'] = $midias[0]['url'];
            } else {
                $p['image_url'] = $midias[0]['url'];
            }
            break;
        case 'IMAGE':
        default:
            $p = [
                'image_url' => $midias[0]['url'],
                'caption'   => $legenda,
            ];
            break;
    }
    $r = ig_post('me/media', $p, $token);
    if (empty($r['id'])) {
        throw new IgErro('A API não devolveu id do container', $r);
    }
    return (string) $r['id'];
}

function ig_publicar(string $containerId, string $token): array
{
    $r = ig_post('me/media_publish', ['creation_id' => $containerId], $token);
    if (empty($r['id'])) {
        throw new IgErro('A API não devolveu id da publicação', $r);
    }
    return $r;
}

function ig_permalink(string $mediaId, string $token): ?string
{
    try {
        $r = ig_get($mediaId, ['fields' => 'permalink'], $token);
        return $r['permalink'] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

function ig_comentar(string $mediaId, string $texto, string $token): void
{
    ig_post($mediaId . '/comments', ['message' => $texto], $token);
}

function ig_limite_publicacao(string $token): array
{
    return ig_get('me/content_publishing_limit', ['fields' => 'config,quota_usage'], $token);
}

/** Métricas de uma publicação. Métricas variam por tipo de mídia. */
function ig_metricas_midia(string $mediaId, string $mediaType, string $token): array
{
    $metricas = match ($mediaType) {
        'VIDEO', 'REELS' => 'reach,likes,comments,saved,shares,views',
        'CAROUSEL_ALBUM', 'CAROUSEL' => 'reach,likes,comments,saved,shares,views',
        'STORY'          => 'reach,replies,navigation',
        default          => 'reach,likes,comments,saved,shares,views',
    };
    try {
        $r = ig_get($mediaId . '/insights', ['metric' => $metricas], $token);
    } catch (IgErro $e) {
        // métrica indisponível para o tipo — tenta o conjunto mínimo
        $r = ig_get($mediaId . '/insights', ['metric' => 'reach'], $token);
    }
    $out = [];
    foreach ($r['data'] ?? [] as $item) {
        $valor = $item['values'][0]['value'] ?? ($item['total_value']['value'] ?? null);
        $out[$item['name']] = is_numeric($valor) ? (int) $valor : null;
    }
    return $out;
}

/** Métricas do perfil no dia. */
function ig_metricas_conta(string $token): array
{
    $out = [];
    try {
        $r = ig_get('me/insights', [
            'metric'      => 'reach,profile_views,accounts_engaged,total_interactions',
            'period'      => 'day',
            'metric_type' => 'total_value',
        ], $token);
        foreach ($r['data'] ?? [] as $item) {
            $out[$item['name']] = $item['total_value']['value'] ?? ($item['values'][0]['value'] ?? null);
        }
    } catch (Throwable $e) {
        $out['_erro'] = $e->getMessage();
    }
    try {
        $me = ig_me($token);
        $out['followers_count'] = $me['followers_count'] ?? null;
        $out['media_count']     = $me['media_count'] ?? null;
    } catch (Throwable $e) {
        // silencioso
    }
    return $out;
}

/** Lista as publicações da conta — a API é a fonte de verdade do que está no ar. */
function ig_listar_midias(string $token, int $limite = 25): array
{
    $r = ig_get('me/media', [
        'fields' => 'id,media_type,media_url,permalink,timestamp,caption,thumbnail_url',
        'limit'  => $limite,
    ], $token);
    return $r['data'] ?? [];
}

/** Confere se a URL da mídia responde 200 antes de mandar para a API. */
function url_publica_ok(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $http === 200;
}
