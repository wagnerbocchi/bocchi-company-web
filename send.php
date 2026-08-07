<?php
/**
 * Bocchi Company — contact form handler
 * Recebe o POST de contato.html / en/contact.html, valida e envia por e-mail.
 * Sem dependências externas (usa a função mail() do PHP).
 *
 * Responde JSON quando chamado via fetch/AJAX; redireciona de volta para a
 * página com #form-ok / #form-err quando enviado sem JavaScript.
 *
 * Requisitos: PHP 7.4+ com mbstring (padrão na Hostinger).
 */

// Produção: nunca exibir erros na tela (evita vazar qualquer dado); só logar.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ============================ CONFIG ============================
$TO_EMAIL    = 'contato@bocchi.company';   // para onde as mensagens vão
$FROM_EMAIL  = 'contato@bocchi.company';   // remetente do fallback mail() (use um endereço @bocchi.company)
$FROM_NAME   = 'Site Bocchi Company';
$MIN_MESSAGE = 10;                         // tamanho mínimo da mensagem
// Entrega caindo em spam? Veja "USAR SMTP" no rodapé deste arquivo.
// ===============================================================

$RETURN_PAGES = array('pt' => 'contato.html', 'en' => 'en/contact.html');

$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

function respond($ok, $lang, $isAjax, $pages, $httpCode, $msg) {
    if ($isAjax) {
        http_response_code($ok ? 200 : $httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => $ok, 'error' => $ok ? null : $msg), JSON_UNESCAPED_UNICODE);
    } else {
        $page = isset($pages[$lang]) ? $pages[$lang] : $pages['pt'];
        header('Location: /' . $page . ($ok ? '#form-ok' : '#form-err'), true, 303);
    }
    exit;
}

$lang = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'pt';
$t = $lang === 'en'
    ? array('method' => 'Method not allowed.', 'fields' => 'Please check the highlighted fields.', 'fail' => 'We could not send your message. Please email us directly.')
    : array('method' => 'Método não permitido.', 'fields' => 'Revise os campos destacados.', 'fail' => 'Não conseguimos enviar. Tente o e-mail direto.');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 405, $t['method']);
}

// Honeypot anti-spam: o campo "website" precisa ficar vazio.
if (!empty($_POST['website'])) {
    respond(true, $lang, $isAjax, $RETURN_PAGES, 200, ''); // finge sucesso e descarta
}

// Remove quebras de linha — proteção contra header injection.
function oneline($v) {
    return trim(str_replace(array("\r", "\n", "\0"), ' ', (string) $v));
}

// Diretório de estado do rate limit (criado com permissão restrita).
function bocchi_rate_dir() {
    $dir = sys_get_temp_dir() . '/bocchi_rl';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    return $dir;
}

/**
 * Salt persistente para o nome dos arquivos de contador.
 * Sem ele o nome seria sha256(IP) puro — como o espaço IPv4 é enumerável,
 * qualquer um que consiga listar o diretório confirmaria quais IPs
 * contataram o site. O salt é gerado uma vez e fica só no disco (nunca no
 * repositório). Se não der para persistir, cai para um valor por processo:
 * o limite continua funcionando, só não sobrevive entre requisições.
 */
function bocchi_rate_salt() {
    static $salt = null;
    if ($salt !== null) { return $salt; }

    // Fallback determinístico, usado só se o filesystem não colaborar. Não é
    // secreto, mas nesse cenário os contadores também não persistem — o que
    // importa é que todos os processos cheguem ao MESMO valor, senão cada um
    // contaria em um arquivo diferente e o limite deixaria de valer.
    $fallback = hash('sha256', 'bocchi-rl|' . __FILE__);
    $path = bocchi_rate_dir() . '/.salt';

    // "c+" cria sem truncar; o flock serializa a criação. Sem isso, requisições
    // simultâneas em um diretório novo geram salts diferentes entre si.
    $fh = @fopen($path, 'c+');
    if (!$fh) { return $salt = $fallback; }
    if (!@flock($fh, LOCK_EX)) { fclose($fh); return $salt = $fallback; }

    $existing = stream_get_contents($fh);
    if (is_string($existing) && strlen($existing) >= 32) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return $salt = $existing;
    }

    try {
        $salt = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $salt = $fallback;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $salt);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0600);
    return $salt;
}

/**
 * Chave de agrupamento do rate limit.
 * IPv4 conta por endereço. IPv6 conta por /64: um único cliente costuma ter
 * um /64 inteiro (2^64 endereços), então contar por /128 tornaria o limite
 * inútil — bastaria trocar de endereço a cada envio.
 */
function bocchi_rate_key($ip) {
    $bin = @inet_pton((string) $ip);
    if ($bin === false) { return 'raw:' . $ip; }
    if (strlen($bin) === 16) {
        // IPv4 mapeado em IPv6 (::ffff:a.b.c.d) conta como IPv4.
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return 'v4:' . inet_ntop(substr($bin, 12));
        }
        return 'v6:' . bin2hex(substr($bin, 0, 8)); // prefixo /64
    }
    return 'v4:' . $ip;
}

/**
 * Limite de N eventos por janela de tempo para uma chave.
 * Leitura e escrita acontecem sob o MESMO flock — sem isso, requisições
 * simultâneas leem o mesmo estado e todas passam.
 *
 * Falha aberta (retorna true) se o filesystem não colaborar: um formulário
 * de contato quebrado custa mais que um limite não aplicado.
 */
function bocchi_rate_ok($key, $max, $window) {
    $file = bocchi_rate_dir() . '/' . hash_hmac('sha256', $key, bocchi_rate_salt());
    $fh = @fopen($file, 'c+');
    if (!$fh) { return true; }
    if (!@flock($fh, LOCK_EX)) { fclose($fh); return true; }

    $now  = time();
    $hits = array();
    $raw  = stream_get_contents($fh);
    $data = json_decode((string) $raw, true);
    if (is_array($data)) {
        foreach ($data as $t) {
            if (is_int($t) && $t > $now - $window) { $hits[] = $t; }
        }
    }

    $ok = count($hits) < $max;
    if ($ok) {
        $hits[] = $now;
        if (count($hits) > $max) { $hits = array_slice($hits, -$max); }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        fflush($fh);
    }

    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($file, 0600);
    return $ok;
}

$name    = oneline(isset($_POST['name']) ? $_POST['name'] : '');
$email   = oneline(isset($_POST['email']) ? $_POST['email'] : '');
$company = oneline(isset($_POST['company']) ? $_POST['company'] : '');
$topic   = oneline(isset($_POST['topic']) ? $_POST['topic'] : '');
$message = trim((string) (isset($_POST['message']) ? $_POST['message'] : ''));

$valid = true;
if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $valid = false;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $valid = false;
if (mb_strlen($company) > 150) $valid = false;
if (mb_strlen($topic) > 80) $valid = false;
if (mb_strlen($message) < $MIN_MESSAGE || mb_strlen($message) > 4000) $valid = false;
if (!$valid) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 422, $t['fields']);
}

// Anti-abuso em duas camadas:
//   1. por cliente  — 5 envios/hora (IPv4 por endereço, IPv6 por /64);
//   2. global       — 60 envios/hora no site inteiro, teto contra spam
//                     distribuído, que a camada por IP não pega.
// REMOTE_ADDR de propósito: X-Forwarded-For é controlado pelo cliente e
// tornaria o limite trivial de burlar.
$clientIp   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
$rateTooMuch = $lang === 'en'
    ? 'Too many messages from your network. Please try again later.'
    : 'Muitas mensagens da sua rede. Tente novamente mais tarde.';

if (!bocchi_rate_ok(bocchi_rate_key($clientIp), 5, 3600)) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 429, $rateTooMuch);
}
if (!bocchi_rate_ok('global', 60, 3600)) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 429, $rateTooMuch);
}

$topicLabel = $topic !== '' ? $topic : ($lang === 'en' ? 'Not specified' : 'Não especificado');

$bodyLines = array(
    ($lang === 'en' ? 'New contact from the website' : 'Novo contato pelo site') . " ({$lang})",
    str_repeat('-', 40),
    'Nome / Name:    ' . $name,
    'E-mail:         ' . $email,
    'Empresa / Co.:  ' . ($company !== '' ? $company : '—'),
    'Interesse:      ' . $topicLabel,
    '',
    ($lang === 'en' ? 'Message:' : 'Mensagem:'),
    $message,
    '',
    str_repeat('-', 40),
    'IP: ' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?') . '  ·  ' . date('c'),
);
$body = implode("\n", $bodyLines);
$subject = '[Site] ' . ($lang === 'en' ? 'New contact' : 'Novo contato') . ' — ' . $topicLabel;
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

define('BOCCHI_SEND', true);
require_once __DIR__ . '/smtp.php';
$smtp = bocchi_load_smtp_config(__DIR__);

if (is_array($smtp) && !empty($smtp['host'])) {
    // Envio autenticado via SMTP (recomendado para domínio no Google Workspace).
    $sent = bocchi_smtp_send($smtp, $TO_EMAIL, $subject, $body, $email, $name);
} else {
    // Fallback: mail() nativo (pode cair em spam sem SPF/DKIM alinhados).
    $headers = array(
        'From: ' . $FROM_NAME . ' <' . $FROM_EMAIL . '>',
        'Reply-To: ' . $email,           // já validado — seguro
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP',
    );
    $sent = @mail($TO_EMAIL, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $FROM_EMAIL);
}

if (!$sent) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 500, $t['fail']);
}
respond(true, $lang, $isAjax, $RETURN_PAGES, 200, '');

/*
 * ===== ENVIO POR SMTP (Google Workspace) =====
 * O envio autenticado é feito por smtp.php (sem libs externas) quando existe o
 * arquivo de credenciais. Para ativar:
 *   1. Copie bocchi-smtp.example.php para bocchi-smtp.php e preencha a app password.
 *   2. Coloque bocchi-smtp.php UM NÍVEL ACIMA do public_html (fora da web).
 * Sem esse arquivo, cai no mail() nativo acima.
 */
