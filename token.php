<?php
/**
 * Bocchi Company — emissor do token de envio do formulário de contato.
 *
 * O JS da página de contato pede um token no carregamento e o manda junto
 * com o POST. Quem dá POST direto no send.php não passa por aqui e é
 * recusado — que é como a maior parte do spam de formulário chega.
 *
 * Responde sempre JSON. Não guarda nada: o token é auto-contido (assinado),
 * e o send.php é quem o gasta.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
// Um token em cache seria entregue repetido a vários visitantes — e o segundo
// a enviar veria o nonce já gasto.
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

define('BOCCHI_SEND', true);
require_once __DIR__ . '/antispam.php';

$token = bocchi_token_issue();
if ($token === '') {
    http_response_code(503);
    echo json_encode(array('ok' => false));
    exit;
}

echo json_encode(array('ok' => true, 'token' => $token));
