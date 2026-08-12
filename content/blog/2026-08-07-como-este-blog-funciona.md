---
title: Como este blog funciona
slug: como-este-blog-funciona
date: 2026-08-07
lang: pt
excerpt: O blog da Bocchi não tem banco de dados, não roda PHP para servir um post e não aceita upload no servidor de produção. Este primeiro texto explica por que, e como escrever nele.
tags:
  - Engenharia
  - Segurança
  - Meta
cover_alt: ''
draft: true
updated: ''
---

Um blog é, historicamente, o pedaço mais atacado de um site institucional. Ele
concentra as três coisas que um invasor procura: autenticação, upload de
arquivo e um banco de dados que renderiza conteúdo. A maioria dos incidentes em
sites de empresa começa exatamente aí — não na landing page.

Como a Bocchi vive de encontrar esse tipo de falha em cliente, seria no mínimo
constrangedor abrir a mesma porta na nossa própria casa. Então o blog foi
montado de um jeito diferente.

## O que roda em produção

Nada. Ou quase.

Cada post que você lê é um arquivo `.html` gerado antes do deploy e servido
como estático. Não há consulta a banco, não há sessão, não há código executando
para montar esta página. O servidor entrega bytes de disco.

O que existe de dinâmico no domínio inteiro são dois arquivos PHP: o
processador do formulário de contato e o intermediário de login do painel — e
esse segundo só é alcançável em `/admin/`, que não é indexado.

## Onde o conteúdo mora

Os posts vivem como Markdown no repositório Git, em `content/blog/`. Publicar é
um commit. Isso traz três propriedades que um CMS tradicional não dá de graça:

- **Histórico real.** Cada alteração de texto tem autor, data e diff.
- **Revisão antes do ar.** Um post pode nascer em branch e passar por pull
  request como qualquer mudança de código.
- **Backup implícito.** O conteúdo está em toda cópia do repositório.

> Se o servidor pegar fogo, o blog inteiro é restaurado com um `git clone` e um
> build. Não há dump de banco para recuperar.

## Escrevendo um post

O frontmatter no topo do arquivo controla os metadados:

```yaml
---
title: Título do artigo
slug: titulo-do-artigo
date: 2026-08-07
lang: pt
excerpt: Uma ou duas frases que aparecem na listagem e nos previews de rede social.
tags:
  - Pentest
  - Active Directory
cover: assets/img/blog/exemplo.webp
draft: false
---
```

O build valida isso na hora. Data fora do formato, slug duplicado ou `cover`
apontando para um arquivo que não existe fazem a geração falhar com uma
mensagem dizendo qual arquivo e qual campo — em vez de publicar uma página
quebrada.

### Blocos de código

Cercas de código com a linguagem declarada saem destacadas. O destaque é
aplicado **no build**, não no navegador:

```python
import hashlib

def rate_key(ip: str) -> str:
    """IPv6 conta por /64: contar por /128 tornaria o limite inútil."""
    if ":" in ip:
        blocos = ip.split(":")[:4]
        return "v6:" + ":".join(blocos)
    return "v4:" + ip

print(hashlib.sha256(rate_key("2001:db8:abcd:1234::1").encode()).hexdigest()[:16])
```

Isso importa por dois motivos. O primeiro é performance: nenhum kilobyte de
highlighter viaja até você. O segundo é a política de segurança de conteúdo do
site, que permite apenas `script-src 'self'` — um highlighter de CDN seria
bloqueado, e afrouxar a regra para acomodá-lo seria trocar segurança por
conveniência.

Código curto no meio da frase, como `git rebase --onto`, também funciona.

### Imagens e vídeo

Imagens entram com a sintaxe normal do Markdown e ganham `loading="lazy"`
automaticamente. Vídeo curto pode ser servido do próprio domínio:

```html
<video controls preload="metadata" poster="/assets/img/blog/poster.webp">
  <source src="/assets/video/demo.webm" type="video/webm" />
</video>
```

Para vídeo longo, o caminho é embed de plataforma — o que exige liberar
`frame-src` na política de segurança, uma decisão consciente e documentada, não
um efeito colateral.

## O painel

Nem todo post nasce no editor de código. Em `/admin/` existe um painel com
login pelo GitHub, editor visual, upload de imagem e inserção de bloco de
código. A diferença é onde ele grava: o painel não escreve num banco, ele
commita Markdown no repositório. O resultado é idêntico ao de quem editou o
arquivo à mão.

|  | CMS tradicional | Este blog |
| --- | --- | --- |
| Conteúdo | Linhas em banco | Arquivos no Git |
| Publicar | `UPDATE` na tabela | Commit e build |
| Servir um post | PHP + consulta SQL | Arquivo estático |
| Superfície de ataque | Login, upload, SQL | Estática na leitura |
| Recuperação | Restaurar dump | `git clone` |

Nenhuma dessas escolhas é exótica. Elas só partem de uma pergunta diferente da
usual: em vez de "como adiciono um blog ao site?", a pergunta foi "quanto do
site eu preciso colocar em risco para ter um blog?".

A resposta, no fim, foi: quase nada.
