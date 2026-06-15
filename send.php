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

// ============================ CONFIG ============================
$TO_EMAIL    = 'contato@bocchi.company';   // para onde as mensagens vão
$FROM_EMAIL  = 'no-reply@bocchi.company';  // remetente: use um endereço @bocchi.company (passa SPF/DKIM do domínio)
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

$name    = oneline(isset($_POST['name']) ? $_POST['name'] : '');
$email   = oneline(isset($_POST['email']) ? $_POST['email'] : '');
$company = oneline(isset($_POST['company']) ? $_POST['company'] : '');
$topic   = oneline(isset($_POST['topic']) ? $_POST['topic'] : '');
$message = trim((string) (isset($_POST['message']) ? $_POST['message'] : ''));

$valid = true;
if (mb_strlen($name) < 2) $valid = false;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $valid = false;
if (mb_strlen($message) < $MIN_MESSAGE) $valid = false;
if (!$valid) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 422, $t['fields']);
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

$headers = array(
    'From: ' . $FROM_NAME . ' <' . $FROM_EMAIL . '>',
    'Reply-To: ' . $email,           // já validado — seguro
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP',
);

$sent = @mail($TO_EMAIL, $encodedSubject, $body, implode("\r\n", $headers), '-f' . $FROM_EMAIL);

if (!$sent) {
    respond(false, $lang, $isAjax, $RETURN_PAGES, 500, $t['fail']);
}
respond(true, $lang, $isAjax, $RETURN_PAGES, 200, '');

/*
 * ===== USAR SMTP (entrega mais confiável) =====
 * O mail() funciona na maioria das hospedagens, mas pode cair em spam sem
 * SPF/DKIM bem configurados. Para enviar pela sua caixa SMTP da Hostinger:
 *   1. Baixe o PHPMailer (github.com/PHPMailer/PHPMailer) para /vendor/phpmailer.
 *   2. Troque o trecho do mail() por:
 *
 *   require __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
 *   require __DIR__ . '/vendor/phpmailer/src/SMTP.php';
 *   require __DIR__ . '/vendor/phpmailer/src/Exception.php';
 *   $m = new PHPMailer\PHPMailer\PHPMailer(true);
 *   $m->isSMTP();
 *   $m->Host = 'smtp.hostinger.com'; $m->SMTPAuth = true;
 *   $m->Username = 'no-reply@bocchi.company'; $m->Password = 'SUA_SENHA';
 *   $m->SMTPSecure = 'ssl'; $m->Port = 465; $m->CharSet = 'UTF-8';
 *   $m->setFrom($FROM_EMAIL, $FROM_NAME); $m->addAddress($TO_EMAIL);
 *   $m->addReplyTo($email, $name); $m->Subject = $subject; $m->Body = $body;
 *   $sent = $m->send();
 */
