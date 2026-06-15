<?php
/**
 * Bocchi Company — enviador SMTP mínimo, sem dependências externas.
 * Fala SMTP por socket: SSL (porta 465) ou STARTTLS (porta 587) + AUTH LOGIN.
 * Usado por send.php quando há credenciais configuradas (bocchi-smtp.php).
 *
 * Apenas define funções — seguro para incluir.
 */

if (!function_exists('bocchi_load_smtp_config')) {

    /**
     * Procura o arquivo de credenciais. Prioriza UM NÍVEL ACIMA da pasta
     * pública (fora do alcance de qualquer URL); cai para o mesmo diretório
     * (que está no .gitignore) como conveniência.
     */
    function bocchi_load_smtp_config($baseDir)
    {
        $candidates = array(
            dirname($baseDir) . '/bocchi-smtp.php',   // recomendado: acima do public_html
            $baseDir . '/bocchi-smtp.php',            // fallback (gitignored)
        );
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $cfg = include $path;
                if (is_array($cfg)) {
                    return $cfg;
                }
            }
        }
        return null;
    }

    /** Codifica nome de exibição para cabeçalho (RFC 2047 / quoting). */
    function bocchi_smtp_header_name($s)
    {
        if (preg_match('/[^\x20-\x7E]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        if (preg_match('/[",:;<>@()\[\]\\\\]/', $s)) {
            return '"' . str_replace('"', '\\"', $s) . '"';
        }
        return $s;
    }

    /**
     * Envia um e-mail de texto puro via SMTP autenticado.
     * Retorna true em sucesso, false em qualquer falha (logada via error_log).
     */
    function bocchi_smtp_send($cfg, $to, $subject, $body, $replyEmail, $replyName)
    {
        $host = $cfg['host'];
        $port = (int) $cfg['port'];
        $user = $cfg['username'];
        $pass = $cfg['password'];
        $fromEmail = $cfg['from_email'];
        $fromName  = isset($cfg['from_name']) ? $cfg['from_name'] : '';

        $transport = ($port === 465) ? 'ssl://' : 'tcp://';
        $ctx = stream_context_create(array('ssl' => array(
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
        )));
        $fp = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) {
            error_log('SMTP connect failed: ' . $errstr);
            return false;
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break; // última linha da resposta SMTP
                }
            }
            return $data;
        };
        $code = function ($resp) { return (int) substr($resp, 0, 3); };
        $say  = function ($cmd) use ($fp) { fwrite($fp, $cmd . "\r\n"); };
        $abort = function () use ($fp) { @fwrite($fp, "QUIT\r\n"); fclose($fp); return false; };

        if ($code($read()) !== 220) return $abort();

        $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
        if ($ehlo === '') $ehlo = 'localhost';

        $say('EHLO ' . $ehlo);
        if ($code($read()) !== 250) return $abort();

        if ($port === 587) {
            $say('STARTTLS');
            if ($code($read()) !== 220) return $abort();
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) return $abort();
            $say('EHLO ' . $ehlo);
            if ($code($read()) !== 250) return $abort();
        }

        $say('AUTH LOGIN');
        if ($code($read()) !== 334) return $abort();
        $say(base64_encode($user));
        if ($code($read()) !== 334) return $abort();
        $say(base64_encode($pass));
        if ($code($read()) !== 235) { error_log('SMTP auth failed'); return $abort(); }

        $say('MAIL FROM:<' . $fromEmail . '>');
        if ($code($read()) !== 250) return $abort();
        $say('RCPT TO:<' . $to . '>');
        $rc = $code($read());
        if ($rc !== 250 && $rc !== 251) return $abort();
        $say('DATA');
        if ($code($read()) !== 354) return $abort();

        $headers = array();
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . bocchi_smtp_header_name($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'To: <' . $to . '>';
        if (filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
            $rt = ($replyName !== '' ? bocchi_smtp_header_name($replyName) . ' ' : '') . '<' . $replyEmail . '>';
            $headers[] = 'Reply-To: ' . $rt;
        }
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        // Corpo em base64: 7-bit, à prova de 8BITMIME e de dot-stuffing
        // (o alfabeto base64 nunca produz linha começando com ".").
        $encodedBody = rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;

        fwrite($fp, $message . "\r\n.\r\n");
        if ($code($read()) !== 250) return $abort();

        $say('QUIT');
        @fclose($fp);
        return true;
    }
}
