# Deploy — upload manual pelo File Manager da Hostinger

O site é estático. Publicar é copiar arquivos para dentro do `public_html`.

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

## Publicar

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
| `bocchi.company/naoexiste` | página 404 do site |

Se `/sobre` der 404 mas `/sobre.html` funcionar, o `.htaccess` não subiu ou o
`mod_rewrite` está desligado.

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
| Site parece desatualizado após o upload | Cache do navegador. O `.htaccess` manda `no-cache` no HTML, mas quem tem a versão antiga em cache pode precisar de um reload forçado uma vez |
| Formulário de contato não envia | `bocchi-smtp.php` fora do lugar ou app password revogada |
