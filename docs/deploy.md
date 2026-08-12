# Deploy

O site é estático. Publicar é copiar arquivos para dentro do `public_html` —
automaticamente pelo GitHub Actions, ou à mão pelo File Manager quando for
preciso.

## O layout do servidor

```
/                             ← raiz da conta (u323617778)
├── public_html/              ← o site vive AQUI dentro
├── bocchi-smtp.php           ← credenciais do formulário de contato
├── bocchi-oauth.php          ← credenciais do painel do blog  (você cria)
└── DO_NOT_UPLOAD_HERE
```

Os dois `bocchi-*.php` ficam **fora** do `public_html` de propósito: nenhuma URL
os alcança, mesmo que um dia o PHP pare de executar e o servidor passe a
entregar `.php` como texto. É por isso que eles não entram no pacote de deploy —
e por isso o `bocchi-oauth.php` vai ao lado do `bocchi-smtp.php` que já está lá,
não dentro do site.

---

## Publicar automaticamente (padrão)

Todo push na `main` — inclusive o que o painel do blog faz quando você salva um
post — dispara o workflow *Blog*: ele regenera o HTML, empacota, e o job
`publicar` envia o pacote por **FTPS** para o `public_html`. Não há passo
manual: o post fica no ar em um ou dois minutos.

### Configurar (uma vez)

**1. Crie uma conta de FTP dedicada** no hPanel (*Arquivos → Contas FTP*),
apontada para `public_html` e **só** para ela.

> Não use a conta de FTP principal. Ela alcança a raiz da conta, onde moram o
> `bocchi-smtp.php`, o `bocchi-oauth.php` e o `.bocchi-state`. Uma conta
> limitada ao `public_html` faz com que o pior caso de um vazamento da
> credencial seja o conteúdo público do site — que já é público — em vez das
> credenciais de e-mail e do painel.

**2. Cadastre os secrets** em *Settings → Secrets and variables → Actions →
Secrets*:

| Secret | Valor |
| --- | --- |
| `FTP_HOST` | ver "Qual host usar" abaixo — a escolha tem consequência |
| `FTP_USER` | o usuário da conta dedicada (ex.: `u323617778.github`) |
| `FTP_PASSWORD` | a senha dessa conta |

Sem os três, o job **não falha**: ele avisa e sai, e o `deploy.zip` continua
disponível como artefato para upload manual.

#### Qual host usar

O hPanel sugere `ftp.<seu-domínio>`, e é justamente o que **não** convém aqui.
O DNS do domínio é servido pela Cloudflare, então esse nome só resolveria se
você criasse o registro lá — e criar significa publicar um `A` apontando para o
IP de origem, sem proxy (FTP não passa por proxy HTTP). Isso entrega o IP real
do servidor: qualquer um passa a alcançar a origem direto, contornando a
Cloudflare para o site inteiro. Um subdomínio de FTP é a forma clássica de
vazar a origem sem perceber.

Em ordem de preferência:

1. **O hostname do servidor da Hostinger** (algo como `srvNNNN.hstgr.io`, no
   hPanel em *Plano de hospedagem*). É o único que costuma casar com o
   certificado do FTP, então dá para manter a verificação ligada, e não liga o
   seu domínio a IP nenhum publicamente.
2. **O IP direto** (`ftp://212.85.6.57` no painel → use só `212.85.6.57`). Fica
   dentro de um secret, então não é publicado. Em compensação **nenhum
   certificado casa com um IP**: o deploy vai falhar na verificação, e aí é
   preciso criar a variável `FTP_VERIFY_CERT=false` — uma decisão consciente,
   que mantém o canal cifrado mas abre mão de saber com quem se está falando.
3. **`ftp.<domínio>` na Cloudflare** — não faça, pelo motivo acima.

**3. Opcional**, em *Variables* (não são secrets, são só configuração):

| Variável | Padrão | Para quê |
| --- | --- | --- |
| `FTP_REMOTE_DIR` | `/` | onde publicar, se a conta não for presa ao `public_html` |
| `FTP_VERIFY_CERT` | `true` | `false` só se o `FTP_HOST` for um IP |
| `FTP_DELETE_STALE` | `false` | `true` apaga no servidor o que sumiu do repositório |

O padrão de `FTP_REMOTE_DIR` é `/` porque a conta dedicada é **presa** ao
diretório configurado: ao logar, ela já está dentro do `public_html` e o
enxerga como raiz. Se a conta que você usar não for presa, o job aborta
explicando, e aí basta pôr o caminho completo
(`/home/uXXXXXXX/domains/bocchi.company/public_html`) nessa variável.

`FTP_DELETE_STALE` vem desligado porque só você sabe se há algo dentro do
`public_html` que não vem deste repositório (um `.well-known/`, um arquivo
solto). Com ele desligado, um post despublicado continua acessível pela URL
antiga até você apagar o arquivo à mão. Antes de ligar, confira o conteúdo do
`public_html` pelo File Manager.

### O que o job garante

- **FTPS obrigatório** (`ftp:ssl-force`), com o canal de dados também cifrado
  (`ssl-protect-data`) — sem isso só o login sobe protegido e o conteúdo dos
  arquivos vai em claro — e certificado verificado.
- **Nunca roda em pull request**, e nunca fora da `main`. Um PR de fork não
  recebe secrets e não alcança o servidor.
- **Não cancela no meio.** O job tem grupo de concorrência próprio com
  `cancel-in-progress: false`: dois pushes seguidos enfileiram, em vez de
  abortar um upload pela metade.
- **Recusa destino suspeito, por conteúdo e não por nome.** Antes de enviar, o
  job lista o diretório de destino: se achar `bocchi-smtp.php`,
  `bocchi-oauth.php`, `.bocchi-state`, `domains` ou `public_html` ali dentro,
  ele está na raiz da conta e aborta; se não achar `index.html`, não é a raiz do
  site e também aborta. Conferir pelo nome do caminho não funcionaria: a conta
  presa enxerga o `public_html` como `/`, e um `/` de conta não presa é
  exatamente o lugar perigoso — mesmo nome, significados opostos.
- **Confere o pacote antes de subir** (tem `.htaccess`, tem `index.html`, não
  tem `bocchi-*.php` nem `.env`/`.key`/`.pem`).
- **Confere o site depois de subir**: `/`, `/blog/` e `/token.php` precisam
  responder 200, e `/antispam.php` precisa responder 403 — se o `.htaccess` não
  subiu, o job fica vermelho em vez de fingir sucesso.

### Se o deploy automático falhar

O `deploy.zip` continua sendo publicado como artefato em toda execução. Caia
para o procedimento manual abaixo e investigue depois.

---

## Publicar à mão (alternativa)

### 1. Pegue o `deploy.zip`

**Pelo GitHub** (não precisa de nada instalado):
*Actions* → o run mais recente do workflow **Blog** → seção *Artifacts* →
baixar **deploy**.

**Ou localmente**, se tiver Node:

```bash
npm install
npm run build:blog
npm run package        # gera dist/ e deploy.zip
```

### 2. Suba e extraia

No File Manager, entre em `public_html`, use **Upload** para enviar o
`deploy.zip` e depois **Extract** ali mesmo. Apague o zip em seguida.

> **Suba o `.zip`, não a pasta.** Arrastar pasta pelo navegador costuma deixar
> os arquivos que começam com ponto para trás — e o `.htaccess` é o que faz as
> URLs limpas, os headers de segurança e o bloqueio dos arquivos de backend
> funcionarem. Sem ele o site sobe parecendo normal e silenciosamente sem nada
> disso. O empacotador **aborta** se o `.htaccess` não entrar no zip.

### 3. Confira

| Endereço | Esperado |
| --- | --- |
| `bocchi.company/sobre` | 200, sem `.html` na URL |
| `bocchi.company/sobre.html` | 301 para `/sobre` |
| `bocchi.company/blog/` | listagem do blog |
| `bocchi.company/smtp.php` | 403 |
| `bocchi.company/antispam.php` | 403 |
| `bocchi.company/token.php` | JSON com `"ok":true` |
| `bocchi.company/naoexiste` | página 404 do site |

Se `/sobre` der 404 mas `/sobre.html` funcionar, o `.htaccess` não subiu ou o
`mod_rewrite` está desligado.

---

## Anti-spam do formulário de contato

Quatro barreiras, do mais barato para o mais caro (código e motivos em
`antispam.php`):

| Barreira | O que corta |
| --- | --- |
| Honeypot (`website`) | Robô que preenche todo campo que encontra |
| Mesma origem | POST disparado de fora do site |
| **Token de envio** | POST direto no `send.php`, sem abrir a página — é assim que chega quase todo spam de formulário |
| Pontuação do conteúdo | O que sobrou: texto ilegível, link plantado, alfabeto que o site não atende, e-mail sem MX |

Mais o rate limit que já existia: 5 envios/hora por cliente, 60/hora no site.

O token é emitido pelo `token.php`, que o JavaScript da página busca no
carregamento — por isso `token.php` e `antispam.php` **precisam** subir junto
com o `send.php`. O empacotador aborta se algum faltar.

**Efeito colateral aceito:** sem JavaScript o formulário deixa de enviar. As
duas páginas de contato avisam disso em `<noscript>` e oferecem o e-mail direto.

**Calibrar.** Mensagem que passa mas pontua leva no rodapé do e-mail uma linha
`Anti-spam: score N de 4`. Se aparecer um cliente de verdade com score alto,
suba o `$SPAM_LIMIT` no `send.php`; se passar spam, desça. O que foi descartado
vai para o log de erro do PHP (`[contato] descartado (score N: motivos)`), com o
motivo — dá para conferir sem adivinhar.

---

## Publicar só um post novo

Um post não muda o site inteiro. Depois que o Actions rodar, só quatro caminhos
mudam:

```
blog/          en/blog/          feed.xml          sitemap.xml
```

Dá para subir só esses em vez do pacote completo. Se tiver imagem nova no post,
some também `assets/img/blog/`.

---

## O que NÃO vai para o servidor

O `npm run package` monta o pacote por allowlist — o que não estiver
explicitamente listado fica de fora. Hoje isso exclui:

| Fora | Por quê |
| --- | --- |
| `content/` | Markdown fonte dos posts; o servidor entrega o HTML já gerado |
| `tools/` | O gerador roda no CI, não no servidor |
| `node_modules/` | Dependências de build; nada disso é servido |
| `docs/` | Documentação do repositório |
| `package*.json`, `.github/` | Build e CI |
| `bocchi-*.example.php` | Modelos de credencial; os reais ficam acima do `public_html` |

Além do allowlist há uma segunda barreira: qualquer caminho que case com
`bocchi-smtp*.php`, `bocchi-oauth*.php`, `.env`, `.key`/`.pem` ou `smtp-diag*`
**aborta o empacotamento**, venha de onde vier. Se um dia um arquivo de
credencial for parar dentro de `assets/` por engano, o `npm run package` falha
em vez de publicá-lo.

---

## Configurar o painel do blog (uma vez)

Sem isso o blog funciona, mas `/admin/` responde *"painel não configurado no
servidor"*.

1. GitHub → *Settings* → *Developer settings* → *OAuth Apps* → *New OAuth App*

   | Campo | Valor |
   | --- | --- |
   | Application name | Bocchi Company — Painel |
   | Homepage URL | `https://bocchi.company` |
   | Authorization callback URL | `https://bocchi.company/admin/auth.php` |

2. Gere um *client secret*.

3. Copie `bocchi-oauth.example.php` para `bocchi-oauth.php`, preencha, e suba
   para a **raiz da conta** — ao lado do `bocchi-smtp.php`, **não** dentro do
   `public_html`.

4. Abra `bocchi.company/admin/` e faça login com o GitHub.

Detalhes das decisões de segurança do painel: [`blog.md`](blog.md).

---

## Se algo der errado

| Sintoma | Causa provável |
| --- | --- |
| `/sobre` dá 404, `/sobre.html` funciona | `.htaccess` não subiu, ou `mod_rewrite` desligado |
| Página sem CSS, layout quebrado | `assets/` incompleto — extraia o zip de novo |
| `/admin/auth.php` mostra código PHP | PHP não está executando em subdiretório: é configuração da hospedagem |
| `/admin/` diz "painel não configurado" | Falta o `bocchi-oauth.php` na raiz da conta |
| `/admin/` diz "autenticação cancelada" após o popup | O `admin/.htaccess` não subiu. Sem ele o `/admin/` herda o `Cross-Origin-Opener-Policy: same-origin` do site, que corta o `window.opener` do popup e o token nunca volta |
| `/admin/` diz "esta conta do GitHub não tem acesso" | Seu usuário não está em `allowed_logins` no `bocchi-oauth.php` |
| Tela do GitHub diz "redirect_uri mismatch" | O callback do OAuth App não é exatamente `https://bocchi.company/admin/auth.php` |
| Site parece desatualizado após o upload | Cache do navegador. O `.htaccess` manda `no-cache` no HTML, mas quem tem a versão antiga em cache pode precisar de um reload forçado uma vez |
| Formulário de contato não envia | `bocchi-smtp.php` fora do lugar ou app password revogada |
| Formulário diz "não foi possível validar o envio" | `token.php` ou `antispam.php` não subiram, ou o JavaScript da página está bloqueado |
