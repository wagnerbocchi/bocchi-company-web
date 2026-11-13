<?php
/** Verificação do desafio de contato; a chave secreta mora fora do public_html. */
if (!defined('BOCCHI_SEND')) { http_response_code(404); exit; }

function bocchi_turnstile_verify($token, $ip) {
    if (!is_string($token) || $token === '' || strlen($token) > 2048) { return false; }

    $path = dirname(__DIR__) . '/bocchi-turnstile.php';
    clearstatcache(true, $path);
    $mode = @fileperms($path);
    if (is_link($path) || !is_file($path) || !is_readable($path)
        || @fileowner($path) !== getmyuid()
        || $mode === false || ($mode & 0077) !== 0) {
        error_log('[contato] configuração do Turnstile indisponível ou insegura');
        return null;
    }
    $config = require $path;
    $secret = is_array($config) && isset($config['secret']) ? $config['secret'] : null;
    if (!is_string($secret) || $secret === '') { return null; }

    $body = http_build_query(array('secret' => $secret, 'response' => $token, 'remoteip' => $ip));
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(array(
            'http' => array('method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body, 'timeout' => 5, 'ignore_errors' => true),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }
    }
    if ($response === false || $status !== 200) { return null; }
    $result = json_decode($response, true);
    if (!is_array($result) || !isset($result['success'])) { return null; }
    return $result['success'] === true
        && isset($result['hostname'], $result['action'])
        && $result['hostname'] === 'bocchi.company'
        && $result['action'] === 'contact';
}
