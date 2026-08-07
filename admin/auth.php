<?php
/**
 * Bocchi Company — intermediário de OAuth do painel do blog.
 *
 * Este arquivo NÃO autentica ninguém. Quem verifica a identidade é o GitHub;
 * aqui só acontece a troca do código de autorização pelo token e a checagem
 * de que o usuário está na allowlist. Não há senha, sessão, nem banco.
 *
 * O token resultante fica no navegador (o CMS o usa para commitar via API do
 * GitHub) e nunca é gravado no servidor.
 *
 * Duas rotas no mesmo arquivo:
 *   GET /admin/auth.php                  -> inicia: redireciona para o GitHub
 *   GET /admin/auth.php?code=..&state=.. -> retorno: troca o código pelo token
 *
 * Configuração: bocchi-oauth.php UM NÍVEL ACIMA do public_html.
 * Modelo em bocchi-oauth.example.php.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

const ORIGIN       = 'https://bocchi.company';
const COOKIE_STATE = 'bocchi_oauth_state';
const GH_AUTHORIZE = 'https://github.com/login/oauth/authorize';
const GH_TOKEN     = 'https://github.com/login/oauth/access_token';
const GH_USER      = 'https://api.github.com/user';

// ------------------------------------------------------------------ saída

/**
 * Devolve a página que conversa com a janela que abriu este popup.
 * O token vai num data-attribute e quem faz o postMessage é o
 * /admin/callback.js — script externo, para que a CSP possa continuar
 * `script-src 'self'` sem nonce nem hash.
 */
function finish(?string $token, ?string $error): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    $attrs = $token !== null
        ? ' data-token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"'
        : ' data-error="' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') . '"';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
       . '<meta name="robots" content="noindex, nofollow"><title>Autenticando…</title>'
       . '<style>body{background:#0A0E1C;color:#B7C0D2;font:15px/1.6 system-ui,sans-serif;'
       . 'display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px}</style>'
       . '</head><body data-provider="github"' . $attrs . '>'
       . '<p>' . ($token !== null ? 'Autenticado. Pode fechar esta janela.'
                                  : 'Falha na autenticação: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8')) . '</p>'
       . '<script src="/admin/callback.js"></script></body></html>';
    exit;
}

function bail(string $logMsg, string $userMsg): never
{
    error_log('[admin/auth] ' . $logMsg);
    finish(null, $userMsg);
}

/** Remove do texto qualquer coisa com cara de token, antes de ir para o log. */
function redact(string $s): string
{
    $s = preg_replace('/(("?)access_token\2\s*[:=]\s*"?)[^"&,\s}]+/i', '$1[redigido]', $s);
    return preg_replace('/\bgh[pousr]_[A-Za-z0-9]{10,}/', '[redigido]', $s);
}

// ------------------------------------------------------------ configuração

function load_config(): array
{
    foreach ([dirname(__DIR__, 2) . '/bocchi-oauth.php', dirname(__DIR__) . '/bocchi-oauth.php'] as $path) {
        if (is_file($path)) {
            $cfg = include $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
    }
    return [];
}

$cfg = load_config();
foreach (['client_id', 'client_secret'] as $k) {
    if (empty($cfg[$k])) {
        bail('config ausente ou incompleta: falta "' . $k . '"', 'painel não configurado no servidor');
    }
}
$allowed = array_map('strtolower', (array) ($cfg['allowed_logins'] ?? []));
if (!$allowed) {
    bail('allowed_logins vazio — recusando por segurança', 'painel não configurado no servidor');
}

// ------------------------------------------------------------------- HTTP

/** POST/GET simples com timeout. Usa cURL quando existe; senão, streams. */
function http_request(string $url, ?array $post, array $headers): ?string
{
    $headers[] = 'User-Agent: bocchi-company-admin';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) { error_log('[admin/auth] curl: ' . $err); return null; }
        return (string) $body;
    }
    $ctx = stream_context_create(['http' => [
        'method'        => $post !== null ? 'POST' : 'GET',
        'header'        => implode("\r\n", $headers),
        'content'       => $post !== null ? http_build_query($post) : null,
        'timeout'       => 15,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

// --------------------------------------------------------------- rota: ida

$code  = isset($_GET['code'])  ? (string) $_GET['code']  : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';

if ($code === '') {
    // O GitHub pode voltar com erro (usuário negou o acesso, por exemplo).
    if (isset($_GET['error'])) {
        bail('github recusou: ' . substr((string) $_GET['error'], 0, 100), 'acesso negado no GitHub');
    }

    $nonce = bin2hex(random_bytes(16));
    setcookie(COOKIE_STATE, $nonce, [
        'expires'  => time() + 600,
        'path'     => '/admin/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',   // precisa sobreviver ao retorno vindo do github.com
    ]);

    header('Location: ' . GH_AUTHORIZE . '?' . http_build_query([
        'client_id'    => $cfg['client_id'],
        'redirect_uri' => ORIGIN . '/admin/auth.php',
        'scope'        => 'repo',
        'state'        => $nonce,
        'allow_signup' => 'false',
    ]), true, 302);
    exit;
}

// ------------------------------------------------------------ rota: volta

$expected = isset($_COOKIE[COOKIE_STATE]) ? (string) $_COOKIE[COOKIE_STATE] : '';
// Consome o state imediatamente: um código só pode ser trocado uma vez.
setcookie(COOKIE_STATE, '', ['expires' => time() - 3600, 'path' => '/admin/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);

if ($expected === '' || $state === '' || !hash_equals($expected, $state)) {
    bail('state inválido (CSRF ou cookie expirado)', 'sessão de login inválida, tente de novo');
}

$raw = http_request(GH_TOKEN, [
    'client_id'     => $cfg['client_id'],
    'client_secret' => $cfg['client_secret'],
    'code'          => $code,
    'redirect_uri'  => ORIGIN . '/admin/auth.php',
], ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);

if ($raw === null) {
    bail('falha de rede na troca do código', 'não consegui falar com o GitHub');
}
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['access_token'])) {
    // A resposta entra no log para dar o que diagnosticar, mas qualquer coisa
    // que se pareça com credencial é removida antes: log não é lugar de token.
    bail('troca do código sem access_token: ' . redact(substr($raw, 0, 300)), 'o GitHub não devolveu um token');
}
$token = (string) $data['access_token'];

// O escopo "repo" já limita o estrago a quem tem acesso de escrita ao
// repositório, mas checar o login explicitamente evita que qualquer conta do
// GitHub consiga sequer carregar o painel.
$userRaw = http_request(GH_USER, null, ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $token]);
$user = is_string($userRaw) ? json_decode($userRaw, true) : null;
if (!is_array($user) || empty($user['login'])) {
    bail('não consegui identificar o usuário do token', 'não consegui confirmar sua identidade');
}
if (!in_array(strtolower((string) $user['login']), $allowed, true)) {
    bail('login fora da allowlist: ' . substr((string) $user['login'], 0, 60), 'esta conta do GitHub não tem acesso ao painel');
}

finish($token, null);
