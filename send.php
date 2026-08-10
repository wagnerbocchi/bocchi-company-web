<?php
/**
 * Bocchi Company — contact form handler
 * Recebe o POST de contato.html / en/contact.html, valida e envia por e-mail.
 * Sem dependências externas (usa a função mail() do PHP).
 *
 * Responde JSON quando chamado via fetch/AJAX; redireciona de volta para a
 * página com #form-ok / #form-err quando enviado sem JavaScript.
 *
 * Barreiras contra spam, na ordem em que rodam (detalhes em antispam.php):
 *   honeypot → mesma origem → assinatura do token → validação dos campos →
 *   limite por cliente → gasto do nonce → pontuação do conteúdo → teto global.
 * A ordem não é estética: tudo que grava em disco ou custa rede fica DEPOIS do
 * limite por cliente, e o teto global fica depois do filtro de conteúdo para
 * que spam descartado não consuma a cota de quem tem algo a dizer.
 * O envio sem JavaScript deixa de funcionar por causa do token: a página de
 * contato avisa disso em <noscript> e oferece o e-mail direto.
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
$TOKEN_MAX   = 43200;                      // validade do token de envio (12 h)
$SPAM_LIMIT  = 4;                          // score a partir do qual a mensagem é descartada
// Opções do <select name="topic"> nas duas línguas. Um valor fora desta lista
// não veio do formulário — mantenha em sincronia com contato.html / en/contact.html.
$TOPICS = array(
    'Pentest & Red Teaming',
    'Campanhas de Phishing', 'Phishing Campaigns',
    'Desenvolvimento de Software', 'Software Development',
    'Outro', 'Other',
);
// Entrega caindo em spam? Veja "USAR SMTP" no rodapé deste arquivo.
// ===============================================================

$RETURN_PAGES = array('pt' => 'contato', 'en' => 'en/contact');

$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

// $retry: sinaliza ao JS que vale pedir um token novo e tentar de novo uma vez
// (acontece com a página aberta há muito tempo, com o token já vencido).
function respond($ok, $lang, $isAjax, $pages, $httpCode, $msg, $retry = false) {
    if ($isAjax) {
        http_response_code($ok ? 200 : $httpCode);
        header('Content-Type: application/json; charset=utf-8');
        $out = array('ok' => $ok, 'error' => $ok ? null : $msg);
        if ($retry) { $out['retry'] = true; }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } else {
        $page = isset($pages[$lang]) ? $pages[$lang] : $pages['pt'];
        header('Location: /' . $page . ($ok ? '#form-ok' : '#form-err'), true, 303);
    }
    exit;
}

$lang = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'pt';
$t = $lang === 'en'
    ? array(
        'method' => 'Method not allowed.',
        'fields' => 'Please check the highlighted fields.',
        'fail'   => 'We could not send your message. Please email us directly.',
        'token'  => 'We could not validate this submission. Reload the page and try again — the form needs JavaScript — or email contato@bocchi.company directly.',
      )
    : array(
        'method' => 'Método não permitido.',
        'fields' => 'Revise os campos destacados.',
        'fail'   => 'Não conseguimos enviar. Tente o e-mail direto.',
        'token'  => 'Não foi possível validar o envio. Recarregue a página e tente de novo — o formulário precisa de JavaScript — ou escreva direto para contato@bocchi.company.',
      );

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 405, $t['method']);
}

define('BOCCHI_SEND', true);
require_once __DIR__ . '/antispam.php';

// Honeypot anti-spam: o campo "website" precisa ficar vazio.
if (!empty($_POST['website'])) {
    respond(true, $lang, $isAjax, $RETURN_PAGES, 200, ''); // finge sucesso e descarta
}

/**
 * O POST veio de uma página deste site?
 * O navegador manda Origin no fetch e Referer no envio sem JavaScript. Compara
 * com o Host da própria requisição — os dois vêm do navegador, então em um
 * envio legítimo eles sempre batem. Sem nenhum dos dois cabeçalhos não dá para
 * julgar, e aí passa: quem decide o caso é o token logo abaixo.
 */
function bocchi_same_site() {
    $source = '';
    if (!empty($_SERVER['HTTP_ORIGIN']))       { $source = $_SERVER['HTTP_ORIGIN']; }
    elseif (!empty($_SERVER['HTTP_REFERER']))  { $source = $_SERVER['HTTP_REFERER']; }
    if ($source === '' || empty($_SERVER['HTTP_HOST'])) { return true; }

    $strip = function ($host) {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) $host));
        return preg_replace('/^www\./', '', $host);   // www.bocchi.company e bocchi.company são o mesmo site
    };
    $from = parse_url($source, PHP_URL_HOST);
    if (!is_string($from) || $from === '') { return false; }
    return $strip($from) === $strip($_SERVER['HTTP_HOST']);
}

if (!bocchi_same_site()) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 403, $t['token']);
}

// Token de envio: emitido pelo token.php, que o JS da página busca no
// carregamento. Assinado, com validade e de uso único — um POST direto no
// endpoint (como chega quase todo spam de formulário) não tem como ter um.
// Vencido: o JS pede outro e repete o envio uma vez (daí o $retry).
//
// Aqui só a assinatura e a validade, que são cálculo puro. Gastar o nonce
// grava um arquivo, e isso fica DEPOIS do rate limit — senão qualquer anônimo
// enche o disco de nonces a dois requests por arquivo, sem nunca passar da
// validação dos campos.
$nonce = bocchi_token_verify(isset($_POST['t']) ? $_POST['t'] : '', $TOKEN_MAX);
if ($nonce === false) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 403, $t['token'], true);
}

// Remove quebras de linha — proteção contra header injection.
function oneline($v) {
    return trim(str_replace(array("\r", "\n", "\0"), ' ', (string) $v));
}

$name    = oneline(isset($_POST['name']) ? $_POST['name'] : '');
$email   = oneline(isset($_POST['email']) ? $_POST['email'] : '');
$company = oneline(isset($_POST['company']) ? $_POST['company'] : '');
$topic   = oneline(isset($_POST['topic']) ? $_POST['topic'] : '');
$message = trim((string) (isset($_POST['message']) ? $_POST['message'] : ''));

$valid = true;
if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $valid = false;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $valid = false;
// O filter_var aceita formas exóticas mas válidas pela RFC, como
// "a<b>"@dominio.tld — local-part entre aspas. Esse endereço vira
// Reply-To: <"a<b>"@dominio.tld>, e o ">" de dentro fecha o angle-addr antes
// da hora, dando ao remetente controle sobre o resto do cabeçalho. Nenhum
// cliente real tem esses caracteres no e-mail; recusar a classe inteira sai
// mais barato que tentar escapá-la.
if (preg_match('/["<>,;:\\\\\s]/', $email)) $valid = false;
if (mb_strlen($company) > 150) $valid = false;
if (mb_strlen($topic) > 80) $valid = false;
if (mb_strlen($message) < $MIN_MESSAGE || mb_strlen($message) > 4000) $valid = false;
if (!$valid) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 422, $t['fields']);
}

// Limite por cliente: 5 envios/hora (IPv4 por endereço, IPv6 por /64).
// REMOTE_ADDR de propósito: X-Forwarded-For é controlado pelo cliente e
// tornaria o limite trivial de burlar. (A hospedagem restaura o IP real do
// visitante aqui — se um dia isso mudar, este limite passa a contar o edge da
// CDN e vira um balde só para todo mundo. Confira o "IP:" do rodapé do e-mail.)
$clientIp   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
$rateTooMuch = $lang === 'en'
    ? 'Too many messages from your network. Please try again later.'
    : 'Muitas mensagens da sua rede. Tente novamente mais tarde.';

if (!bocchi_rate_ok(bocchi_rate_key($clientIp), 5, 3600)) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 429, $rateTooMuch);
}

// Só agora o nonce é gasto: a partir daqui cada tentativa custa uma das 5
// vagas horárias do cliente, então a gravação em disco tem teto.
if (!bocchi_nonce_claim($nonce, $TOKEN_MAX)) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 403, $t['token'], true);
}

// Última camada: o conteúdo. Pega o que passou pelas outras — robô que roda
// JavaScript de verdade, ou envio manual. Responde SUCESSO e joga fora, igual
// ao honeypot: dizer "recusado" ensina o spammer a ajustar o texto e tentar de
// novo. Fica no log de erro do servidor, com o motivo, para conferência.
//
// Exige um sinal FORTE além do score: descartar em silêncio a mensagem de um
// cliente é o pior erro que esta camada pode cometer, e sem essa condição
// bastava a soma de três coincidências fracas para isso acontecer.
$spam = bocchi_spam_score(array(
    'name' => $name, 'email' => $email, 'company' => $company,
    'topic' => $topic, 'message' => $message, 'topics' => $TOPICS,
));
if ($spam['score'] >= $SPAM_LIMIT && $spam['strong']) {
    error_log(sprintf(
        '[contato] descartado (score %d: %s) ip=%s email=%s msg=%s',
        $spam['score'], implode('; ', $spam['why']), $clientIp, $email,
        mb_substr($message, 0, 160)
    ));
    respond(true, $lang, $isAjax, $RETURN_PAGES, 200, '');
}

// Teto global do site: 60/hora. Fica DEPOIS do filtro de conteúdo de
// propósito — spam descartado não deve consumir a cota, senão um robô manda
// 60 mensagens ilegíveis por hora e derruba o formulário para todo mundo,
// que é justamente o estrago que este limite deveria evitar.
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
// Quase-spam: a mensagem passou, mas pontuou. Aparecer no e-mail é o que
// permite calibrar o $SPAM_LIMIT com casos reais em vez de no chute.
if ($spam['score'] > 0) {
    $bodyLines[] = 'Anti-spam: score ' . $spam['score'] . ' de ' . $SPAM_LIMIT
        . ($spam['strong'] ? ' + sinal forte' : ', só sinais fracos')
        . ' (' . implode('; ', $spam['why']) . ')';
}
$body = implode("\n", $bodyLines);
$subject = '[Site] ' . ($lang === 'en' ? 'New contact' : 'Novo contato') . ' — ' . $topicLabel;
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

require_once __DIR__ . '/smtp.php';   // BOCCHI_SEND já definido no topo
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
