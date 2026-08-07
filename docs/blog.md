# Blog — como funciona e como operar

O blog é estático. Cada post é um arquivo Markdown em `content/blog/`; um build
converte para HTML e o servidor entrega bytes de disco. Não há banco de dados,
não há PHP servindo post e não há upload no servidor de produção.

---

## Publicar um post

Há dois caminhos, e os dois terminam no mesmo lugar: um commit de Markdown.

### Pelo painel (`/admin/`)

1. Acesse `https://bocchi.company/admin/`
2. **Sign In with GitHub** — o login é do GitHub, não um usuário deste site
3. Escreva, suba imagens, salve
4. Ao publicar, o painel commita no `main`; o GitHub Actions gera o HTML

### Pelo repositório

Crie `content/blog/AAAA-MM-DD-slug.md`, commite, pronto.

---

## Frontmatter

```yaml
---
title: Título do artigo          # obrigatório
slug: titulo-do-artigo           # obrigatório na prática (vira a URL)
date: 2026-08-07                 # obrigatório, AAAA-MM-DD
lang: pt                         # pt (padrão) ou en
excerpt: Uma ou duas frases.     # listagem, preview social e RSS
tags: [Pentest, Active Directory]
cover: assets/img/blog/capa.webp
cover_alt: Descrição da imagem para leitor de tela
updated: 2026-09-01              # só se revisar depois de publicado
draft: false                     # true = não publica
---
```

O build **falha** — em vez de publicar página quebrada — se: faltar `title` ou
`date`, a data não for `AAAA-MM-DD`, dois posts do mesmo idioma tiverem o mesmo
slug, ou `cover` apontar para arquivo inexistente. A mensagem diz qual arquivo e
qual campo.

## Escrevendo

**Bloco de código** — cerca tripla com a linguagem:

````markdown
```python
def exemplo():
    return "destacado no build, não no navegador"
```
````

O destaque é aplicado na geração. Nenhum highlighter é enviado ao navegador —
isso mantém o `script-src 'self'` do site e economiza banda.

**Imagem** — `![alt](/assets/img/blog/arquivo.webp)`. O `loading="lazy"` é
adicionado automaticamente.

**Vídeo curto**, servido do próprio domínio:

```html
<video controls preload="metadata" poster="/assets/img/blog/poster.webp">
  <source src="/assets/video/demo.webm" type="video/webm" />
</video>
```

**Vídeo de plataforma** (YouTube/Vimeo) exige liberar `frame-src` na CSP do
site, no `.htaccess`. É uma decisão consciente: hoje está fechado. Se decidir
abrir, envolva o iframe em `<div class="embed">` para manter a proporção.

**Índice do artigo** aparece sozinho quando o post tem 3 ou mais `##`.

---

## Como o HTML chega no ar

```
content/blog/*.md
      │
      │  push no main
      ▼
GitHub Actions (.github/workflows/blog.yml)
      │  npm ci && npm run build:blog
      ▼
blog/*.html, en/blog/*.html, feed.xml, sitemap.xml
      │  commitados de volta no repositório
      ▼
deploy (qualquer método) → servidor
```

O HTML gerado é **commitado no repositório**. Isso é deliberado: o repositório
continua servível como está, então funciona com deploy automático, `git pull` no
servidor ou FTP — nada precisa buildar na hospedagem.

> **Não edite `blog/*.html` à mão.** O próximo build sobrescreve. A fonte da
> verdade é `content/blog/`.

Para gerar localmente: `npm install && npm run build:blog`.
Para só validar sem escrever: `npm run check:blog`.

---

## Configurar o painel (uma vez)

1. GitHub → *Settings* → *Developer settings* → *OAuth Apps* → *New OAuth App*

   | Campo | Valor |
   | --- | --- |
   | Application name | Bocchi Company — Painel |
   | Homepage URL | `https://bocchi.company` |
   | Authorization callback URL | `https://bocchi.company/admin/auth.php` |

2. Gere um *client secret*.

3. Copie `bocchi-oauth.example.php` para `bocchi-oauth.php`, preencha, e coloque
   **um nível acima do `public_html`** — fora da pasta pública. Mesmo lugar e
   mesma lógica do `bocchi-smtp.php` do formulário de contato.

4. Confira `allowed_logins`. Sem essa lista o `auth.php` recusa todo mundo, de
   propósito.

---

## Segurança — o que existe e por quê

| Decisão | Motivo |
| --- | --- |
| Post servido como arquivo estático | Sem banco e sem código na leitura: não há SQLi nem RCE em caminho que o visitante alcança |
| Conteúdo em Git | Histórico com autor e diff; restauração por `git clone`, sem dump para recuperar |
| Destaque de sintaxe no build | Preserva `script-src 'self'`; nenhum JS de terceiro no site público |
| Login pelo GitHub | Não armazenamos senha, não há sessão nem tabela de usuários para vazar |
| `client_secret` fora do webroot | Nenhuma URL alcança o arquivo, mesmo se o PHP parar de executar |
| `state` aleatório em cookie `HttpOnly`, uso único | CSRF no retorno do OAuth |
| Allowlist de contas | O escopo `repo` já limita o estrago, mas isso impede que qualquer conta do GitHub carregue o painel |
| `postMessage` com origem verificada | A implementação de referência responde a `e.origin` sem checar; assim, uma página maliciosa que abrisse o popup receberia o token |
| Token só no navegador | Nunca é gravado no servidor; o que vai para o log é redigido |
| CSP própria em `/admin/` | O relaxamento necessário ao painel não vale para o site |
| `noindex` em `/admin/` | O painel não entra em buscador |
| `same-origin-allow-popups` em `/admin/` | O site roda com `Cross-Origin-Opener-Policy: same-origin`, que corta o `window.opener` do popup ao voltar do GitHub — e sem ele o token nunca chega. Esta é a relaxada mínima que faz o fluxo funcionar, e vale só para `/admin/` |

### O que está fora do padrão do site, e por quê

O `/admin/` carrega fonte de ícones do jsDelivr e um arquivo de versão do
unpkg. É a única parte do domínio que fala com terceiro — o site público não
tem nenhum, e as fontes dele são self-hosted justamente por isso.

A concessão vale só para `/admin/`: uma pessoa, atrás de login do GitHub, em
rota não indexada. Eliminar também essa dependência exigiria reescrever URLs
dentro do bundle do CMS e versionar alguns megabytes de fonte de ícones a cada
atualização — custo desproporcional ao risco. Se um dia isso mudar, o ponto de
alteração é `admin/.htaccess`.

---

## Atualizar o CMS

O bundle está versionado em `admin/vendor/sveltia-cms.js` porque a CSP não
permite script de CDN. Para atualizar:

```bash
npm pack @sveltia/cms@<versão>
tar xzf sveltia-cms-*.tgz
cp package/dist/sveltia-cms.js admin/vendor/sveltia-cms.js
cp package/LICENSE.txt admin/vendor/LICENSE.txt
```

Depois abra `/admin/` e confira o console: **nenhuma violação de CSP**. Se
aparecer alguma, a nova versão passou a buscar algo novo — decida se libera em
`admin/.htaccess` ou se fica na versão anterior. Não afrouxe `script-src`.
