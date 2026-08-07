<?php
/**
 * MODELO de credenciais do OAuth do painel do blog (/admin/).
 *
 * COMO USAR
 *   1. No GitHub: Settings > Developer settings > OAuth Apps > New OAuth App
 *        Application name .... Bocchi Company — Painel
 *        Homepage URL ........ https://bocchi.company
 *        Authorization callback URL
 *                             https://bocchi.company/admin/auth.php
 *      Gere um client secret e anote os dois valores.
 *
 *   2. Copie este arquivo para "bocchi-oauth.php" e preencha.
 *
 *   3. Coloque o "bocchi-oauth.php" UM NÍVEL ACIMA do public_html — fora da
 *      pasta pública, para que nenhuma URL o alcance. Ex.: se o site está em
 *      /home/USER/public_html, deixe o arquivo em /home/USER/bocchi-oauth.php.
 *      O admin/auth.php procura lá primeiro.
 *
 * SEGURANÇA
 *   - "bocchi-oauth.php" está no .gitignore — nunca vai para o GitHub.
 *   - O client secret NÃO pode ficar no repositório nem em qualquer arquivo
 *     servível: com ele, um terceiro consegue completar o fluxo de OAuth em
 *     nome do painel.
 *   - allowed_logins é a última barreira: sem ela, qualquer conta do GitHub
 *     conseguiria carregar o painel (mesmo sem poder commitar). Deixar essa
 *     lista vazia faz o auth.php recusar todo mundo, de propósito.
 *   - Se o secret vazar, revogue no GitHub e gere outro. O token que o
 *     painel usa vive só no navegador e expira sozinho.
 */
return array(
    'client_id'     => 'COLE_O_CLIENT_ID_AQUI',
    'client_secret' => 'COLE_O_CLIENT_SECRET_AQUI',

    // Contas do GitHub autorizadas a usar o painel.
    'allowed_logins' => array(
        'wagnerbocchi',
    ),
);
