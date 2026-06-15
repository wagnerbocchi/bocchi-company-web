<?php
/**
 * MODELO de credenciais SMTP do formulário de contato.
 *
 * COMO USAR
 *   1. Copie este arquivo para "bocchi-smtp.php".
 *   2. Preencha a app password do Google.
 *   3. Coloque o "bocchi-smtp.php" UM NÍVEL ACIMA do public_html
 *      (fora da pasta pública) — assim nenhuma URL consegue alcançá-lo.
 *      Ex.: se o site está em /home/USER/public_html, deixe o arquivo em
 *      /home/USER/bocchi-smtp.php. O send.php procura lá primeiro.
 *
 * SEGURANÇA
 *   - "bocchi-smtp.php" está no .gitignore — nunca vai para o GitHub.
 *   - Use uma app password do Google (Conta Google > Segurança > Senhas de app);
 *     ela é revogável a qualquer momento, sem afetar a senha principal.
 *   - O usuário de autenticação é a CONTA REAL (wagner@); o remetente pode ser
 *     o alias (contato@), desde que esteja como "Enviar e-mail como" no Gmail.
 */
return array(
    // RECOMENDADO (Workspace): SMTP relay — o remetente no-reply@ NÃO é "você",
    // então o e-mail cai na CAIXA DE ENTRADA (não em "Todos os e-mails").
    // Exige ativar o "Serviço de retransmissão SMTP" no Admin console com
    // "Exigir autenticação SMTP".
    'host'       => 'smtp-relay.gmail.com',
    'port'       => 587,                        // STARTTLS
    'username'   => 'wagner@bocchi.company',    // conta REAL do Workspace (autenticação)
    'password'   => 'COLE_A_APP_PASSWORD_AQUI', // app password — 16 letras, sem espaços
    'from_email' => 'no-reply@bocchi.company',  // remetente (não precisa ser caixa real)
    'from_name'  => 'Site Bocchi Company',
);

// Alternativa (SMTP comum, smtp.gmail.com:465/587): só envie de um endereço
// que esteja em "Enviar e-mail como" no Gmail. Atenção: enviar de contato@
// PARA contato@ (mesma conta) pode cair em "Todos os e-mails", não na Entrada.
