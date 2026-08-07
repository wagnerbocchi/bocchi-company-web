/**
 * Entrega o resultado do OAuth para a janela que abriu este popup.
 *
 * Está num arquivo separado de propósito: o auth.php poderia embutir isso
 * inline, mas aí a CSP precisaria de nonce ou 'unsafe-inline'. Como script
 * externo, `script-src 'self'` basta.
 *
 * O handshake é o mesmo que o Decap/Sveltia esperam:
 *   popup  -> "authorizing:github"
 *   opener -> qualquer mensagem (só serve para revelar a origem)
 *   popup  -> "authorization:github:success:{...}"
 *
 * Diferença em relação à implementação de referência: só respondemos se a
 * origem da mensagem for exatamente a do site. Sem essa checagem, qualquer
 * página que abrisse este popup e mandasse um postMessage receberia o token.
 */
(function () {
  'use strict';

  var ORIGIN = 'https://bocchi.company';
  var body = document.body;
  var provider = body.getAttribute('data-provider') || 'github';
  var token = body.getAttribute('data-token');
  var error = body.getAttribute('data-error');

  if (!window.opener) return;

  var payload = token
    ? 'authorization:' + provider + ':success:' + JSON.stringify({ token: token, provider: provider })
    : 'authorization:' + provider + ':error:' + JSON.stringify({ message: error || 'unknown' });

  function onMessage(e) {
    // Em desenvolvimento local o site não roda em bocchi.company; aceitar a
    // própria origem cobre esse caso sem abrir para terceiros.
    if (e.origin !== ORIGIN && e.origin !== window.location.origin) return;
    window.removeEventListener('message', onMessage, false);
    window.opener.postMessage(payload, e.origin);
    if (token) setTimeout(function () { window.close(); }, 300);
  }

  window.addEventListener('message', onMessage, false);
  // Ainda não sabemos a origem do opener; esta primeira mensagem não carrega
  // segredo nenhum, só provoca a resposta que a revela.
  window.opener.postMessage('authorizing:' + provider, '*');
})();
