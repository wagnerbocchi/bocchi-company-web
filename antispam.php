<?php
/**
 * Bocchi Company — camada anti-abuso do formulário de contato.
 * Reúne o que o send.php usa para separar gente de robô:
 *
 *   1. rate limit por cliente e global (contadores em disco);
 *   2. token de envio assinado, emitido pelo token.php e de uso único;
 *   3. pontuação de conteúdo (heurísticas de spam clássico).
 *
 * Apenas define funções — seguro para incluir. Sem dependências externas.
 *
 * Só é incluído por send.php e token.php; o .htaccess também bloqueia o
 * acesso direto por URL.
 */

if (!defined('BOCCHI_SEND')) { http_response_code(404); exit; }

if (!function_exists('bocchi_rate_dir')) {

    // ===================== ESTADO EM DISCO =====================

    /** Diretório de estado do rate limit (criado com permissão restrita). */
    function bocchi_rate_dir() {
        $dir = sys_get_temp_dir() . '/bocchi_rl';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        return $dir;
    }

    /**
     * Segredo persistente do módulo.
     * Nomeia os arquivos de contador e assina os tokens. Sem ele o nome do
     * contador seria sha256(IP) puro — como o espaço IPv4 é enumerável,
     * qualquer um que consiga listar o diretório confirmaria quais IPs
     * contataram o site. É gerado uma vez e fica só no disco (nunca no
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

    // ===================== RATE LIMIT =====================

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

    // ===================== TOKEN DE ENVIO =====================
    //
    // O grosso do spam de formulário não abre a página: o robô dá POST
    // direto no endpoint, com todos os campos preenchidos e o honeypot
    // vazio — exatamente o que passou por aqui. O token corta essa classe
    // inteira sem pedir captcha a ninguém: só é emitido pelo token.php, que
    // o JS da página busca no carregamento.
    //
    // Não vincula o token ao IP de propósito. Em rede móvel o endereço muda
    // entre abrir a página e enviar, e isso rejeitaria gente de verdade sem
    // atrapalhar um robô — que simplesmente pediria um token novo.

    /**
     * Chave HMAC dos tokens, derivada do mesmo segredo do rate limit.
     * Derivar em vez de reusar mantém os propósitos separados: quem descobrir
     * o nome de um arquivo de contador não ganha nada para forjar token.
     */
    function bocchi_token_key() {
        return hash_hmac('sha256', 'form-token-v1', bocchi_rate_salt(), true);
    }

    /** Emite um token novo. Retorna '' se não houver fonte de aleatoriedade. */
    function bocchi_token_issue() {
        try {
            $nonce = bin2hex(random_bytes(12));
        } catch (Exception $e) {
            error_log('token: sem CSPRNG disponível — ' . $e->getMessage());
            return '';
        }
        $ts = time();
        return 'v1.' . $ts . '.' . $nonce . '.' . hash_hmac('sha256', $ts . '.' . $nonce, bocchi_token_key());
    }

    /**
     * Confere assinatura, idade e ineditismo do token.
     * A ordem importa: só gasta o nonce depois que a assinatura confere, senão
     * qualquer um invalidaria tokens alheios chutando nonces.
     */
    function bocchi_token_check($token, $maxAge) {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 4 || $parts[0] !== 'v1') { return false; }
        list($_v, $ts, $nonce, $sig) = $parts;

        if (!ctype_digit($ts) || strlen($nonce) !== 24 || !ctype_xdigit($nonce)) { return false; }

        $expected = hash_hmac('sha256', $ts . '.' . $nonce, bocchi_token_key());
        if (!hash_equals($expected, (string) $sig)) { return false; }

        // Tolerância negativa pequena: o relógio é o mesmo em quem emite e em
        // quem confere, mas um ajuste de NTP no meio não deve derrubar envio.
        $age = time() - (int) $ts;
        if ($age < -5 || $age > $maxAge) { return false; }

        return bocchi_nonce_claim($nonce, $maxAge);
    }

    /**
     * Marca o nonce como usado; false se já tinha sido.
     * É o que impede que um token capturado uma vez alimente uma rajada de
     * envios. O modo "x" do fopen é atômico no filesystem: com dois processos
     * apresentando o mesmo token, só um cria o arquivo.
     */
    function bocchi_nonce_claim($nonce, $ttl) {
        bocchi_nonce_gc($ttl);
        $file = bocchi_rate_dir() . '/n_' . hash_hmac('sha256', 'nonce:' . $nonce, bocchi_rate_salt());
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            // Distinguir "já usado" de "disco indisponível": no segundo caso
            // falha aberta, pelo mesmo motivo do rate limit — formulário
            // quebrado custa mais que um token reaproveitado.
            return !file_exists($file);
        }
        fclose($fh);
        @chmod($file, 0600);
        return true;
    }

    /**
     * Limpa nonces vencidos. Roda por amostragem (1 em 50 envios) porque
     * varrer o diretório a cada requisição custaria mais que o próprio envio.
     */
    function bocchi_nonce_gc($ttl) {
        if (mt_rand(1, 50) !== 1) { return; }
        $dir = bocchi_rate_dir();
        $dh = @opendir($dir);
        if (!$dh) { return; }
        $cut = time() - $ttl;
        while (($entry = readdir($dh)) !== false) {
            if (strpos($entry, 'n_') !== 0) { continue; }
            $path = $dir . '/' . $entry;
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cut) { @unlink($path); }
        }
        closedir($dh);
    }

    // ===================== PONTUAÇÃO DE CONTEÚDO =====================

    /** Vogais, acentuadas inclusive. "y" conta como vogal (nomes e termos em inglês). */
    function bocchi_vowels() {
        return 'aeiouyàáâãäåèéêëìíîïòóôõöùúûüýÿ';
    }

    /**
     * Palavra sem forma de palavra — "fkmdkdwdwkdwjj", teclado amassado.
     * Dois sinais independentes: proporção de vogais baixa demais e sequência
     * longa de consoantes. Nenhum dos dois acontece em português ou inglês.
     *
     * Só olha palavras de 6+ letras: abaixo disso siglas técnicas legítimas
     * (sqlmap, nmap, XSS) cairiam junto. Mesmo assim uma palavra marcada
     * sozinha não condena a mensagem — quem decide é bocchi_spam_score().
     *
     * Só julga alfabeto latino. Em cirílico ou em japonês a conta de vogais
     * não quer dizer nada, e sem esse corte toda mensagem em outro alfabeto
     * seria marcada "ilegível" por acidente — o que é um sinal legítimo, mas
     * é outro (veja "fora do alfabeto latino" em bocchi_spam_score).
     */
    function bocchi_gibberish($word) {
        $w = mb_strtolower((string) $word, 'UTF-8');
        $len = mb_strlen($w, 'UTF-8');
        if ($len < 6 || !preg_match('/^\p{Latin}+$/u', $w)) { return false; }

        $v = bocchi_vowels();
        $vowels = preg_match_all('/[' . $v . ']/u', $w);
        if (!is_int($vowels)) { $vowels = 0; }
        if ($vowels / $len < 0.25) { return true; }

        return (bool) preg_match('/[^' . $v . ']{5,}/u', $w);
    }

    /**
     * O domínio do e-mail existe no DNS?
     * Endereço sem MX nem A é lead que nunca poderia ser respondido.
     *
     * Antes de acreditar em um "não existe", confere um domínio que existe com
     * certeza. Sem essa prova, um resolver quebrado no host responderia "não
     * existe" para TODO mundo — e o sinal, que deveria pegar spam, passaria a
     * pontuar cliente de verdade. Na dúvida, falha aberta.
     */
    function bocchi_email_domain_resolves($email) {
        $at = strrpos((string) $email, '@');
        if ($at === false || !function_exists('checkdnsrr')) { return true; }
        $domain = substr($email, $at + 1);
        if ($domain === '' || $domain === false) { return true; }

        if (@checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA')) {
            return true;
        }
        return !@checkdnsrr('gmail.com', 'MX');   // resolver mudo: ninguém é julgado
    }

    /**
     * Pontua o conteúdo do envio. Quanto maior, mais parece spam.
     *
     * Pontuação em vez de regra única de propósito: cada sinal isolado tem
     * falso positivo plausível (um cliente pode colar um link, escrever em
     * russo, citar o domínio do site), então nenhum sozinho chega ao limite.
     * É a soma que condena.
     *
     * $fields: name, email, company, topic, message, topics (opções válidas).
     * Retorna array('score' => int, 'why' => array de motivos).
     */
    function bocchi_spam_score($fields) {
        $name    = isset($fields['name']) ? $fields['name'] : '';
        $email   = isset($fields['email']) ? $fields['email'] : '';
        $company = isset($fields['company']) ? $fields['company'] : '';
        $topic   = isset($fields['topic']) ? $fields['topic'] : '';
        $message = isset($fields['message']) ? $fields['message'] : '';
        $topics  = isset($fields['topics']) && is_array($fields['topics']) ? $fields['topics'] : array();

        $score = 0;
        $why   = array();
        $add = function ($points, $reason) use (&$score, &$why) {
            $score += $points;
            $why[] = $reason;
        };

        // --- Links. O alvo do spam de formulário quase sempre é plantar um. ---
        if (preg_match('~\[\s*(?:url|link)\b|<\s*a\s[^>]*href~i', $message)) {
            $add(5, 'markup de link na mensagem');
        } else {
            $urls = preg_match_all('~(?:https?://|\bwww\.)~i', $message);
            if ($urls >= 2)      { $add(4, $urls . ' urls na mensagem'); }
            elseif ($urls === 1) { $add(2, 'url na mensagem'); }
        }
        // Ninguém digita link no campo de nome ou de empresa.
        if (preg_match('~https?://|\bwww\.~i', $name . ' ' . $company)) {
            $add(4, 'url no nome/empresa');
        }

        // --- Texto sem forma de palavra (o caso "Egjnjmfnefjwdifj fkmdkdw..."). ---
        preg_match_all('/\p{Latin}{6,}/u', $message, $m);
        $words = $m[0];
        $total = count($words);
        $gib   = 0;
        foreach ($words as $w) {
            if (bocchi_gibberish($w)) { $gib++; }
        }
        if ($total >= 2 && $gib >= 2 && $gib / $total >= 0.5) {
            $add(4, "mensagem ilegível ({$gib}/{$total} palavras)");
        } elseif ($gib >= 3) {
            $add(3, "{$gib} palavras ilegíveis");
        } elseif ($total > 0 && $total <= 2 && $gib === $total) {
            $add(3, 'mensagem curta e ilegível');
        }
        if (bocchi_gibberish($name)) { $add(2, 'nome sem forma de nome'); }

        // --- Escrita fora do par de idiomas que o site atende. O site existe
        //     em português e em inglês; pedido de proposta escrito em outro
        //     alfabeto é, na prática, sempre agência estrangeira vendendo algo.
        //     Compara com o texto em latino em vez de cortar no primeiro
        //     caractere: citar uma palavra em japonês não condena a mensagem. ---
        $latin = (int) preg_match_all('/\p{Latin}/u', $message);
        $other = (int) preg_match_all('/\p{L}/u', $message) - $latin;
        if ($other > $latin) { $add(4, 'mensagem fora do alfabeto latino'); }

        // --- Frases de spam clássico. Só expressões inteiras: palavra solta
        //     como "crypto" ou "phishing" é vocabulário normal aqui. ---
        $phrases = array(
            'backlink', 'link building', 'guest post', 'seo service', 'seo expert',
            'rank higher', 'first page of google', 'increase your traffic',
            'buy followers', 'payday loan', 'binary option', 'make money fast',
            'casino', 'viagra', 'cialis', 'escort service',
        );
        $hits = array();
        foreach ($phrases as $p) {
            if (stripos($message, $p) !== false) { $hits[] = $p; }
        }
        if ($hits) {
            $add(min(4, 2 * count($hits)), 'frase de spam: ' . implode(', ', array_slice($hits, 0, 3)));
        }

        // --- O robô ecoa o domínio alvo de volta na mensagem. Sozinho não
        //     condena: um cliente pode escrever "vi no bocchi.company". ---
        if (stripos($message, 'bocchi.company') !== false) {
            $add(1, 'ecoa o domínio do site');
        }

        // --- Interesse fora do <select>. Só um POST montado à mão chega assim. ---
        if ($topic !== '' && $topics && !in_array($topic, $topics, true)) {
            $add(3, 'interesse fora das opções do formulário');
        }

        // --- Bloco único sem espaço nenhum: colagem automática, não texto. ---
        if (mb_strlen($message, 'UTF-8') > 40 && !preg_match('/\s/u', $message)) {
            $add(3, 'mensagem sem espaços');
        }

        // --- Endereço para o qual seria impossível responder. ---
        if (!bocchi_email_domain_resolves($email)) {
            $add(3, 'domínio do e-mail sem MX/A');
        }

        return array('score' => $score, 'why' => $why);
    }
}
