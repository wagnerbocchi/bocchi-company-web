#!/usr/bin/env node
/**
 * Gerador do blog da Bocchi Company.
 *
 * Lê os posts em Markdown de content/blog/ e escreve HTML estático em
 * blog/ (pt) e en/blog/ (en), mais feed.xml e as entradas do sitemap.
 *
 * Duas decisões que valem explicação:
 *
 * 1. O destaque de sintaxe acontece AQUI, não no navegador. O site roda com
 *    `script-src 'self'` e sem JavaScript de terceiro; mandar um highlighter
 *    para o cliente furaria isso e ainda custaria banda. O build emite as
 *    classes do highlight.js e o assets/css/blog.css as pinta.
 *
 * 2. O cabeçalho e o rodapé são EXTRAÍDOS de uma página existente do site em
 *    vez de duplicados em template. Se a navegação mudar, o blog acompanha
 *    sozinho — não há duas fontes de verdade para o mesmo menu.
 *
 * Uso:
 *   node tools/build-blog.mjs           gera os arquivos
 *   node tools/build-blog.mjs --check   só valida, não escreve (usado no CI)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import matter from 'gray-matter';
import MarkdownIt from 'markdown-it';
import hljs from 'highlight.js';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');
const SITE = 'https://bocchi.company';

const LANGS = {
  pt: {
    dir: 'blog',
    base: '/blog/',
    shellFrom: 'sobre.html',
    htmlLang: 'pt-BR',
    ogLocale: 'pt_BR',
    title: 'Blog — Bocchi Company',
    description: 'Artigos técnicos da Bocchi Company sobre segurança ofensiva, detecção e engenharia de software.',
    eyebrow: '04 / Blog',
    heading: ['Notas de campo', 'de quem opera.'],
    sub: 'O que aprendemos atacando, detectando e construindo — escrito para quem vai colocar a mão no teclado depois.',
    empty: 'Ainda não há posts publicados. Em breve.',
    backToList: '← Todos os artigos',
    readingTime: (n) => `${n} min de leitura`,
    publishedOn: 'Publicado em',
    updatedOn: 'Atualizado em',
    tocTitle: 'Neste artigo',
    skip: 'Pular para o conteúdo',
    navLabel: 'Blog',
  },
  en: {
    dir: 'en/blog',
    base: '/en/blog/',
    shellFrom: 'en/about.html',
    htmlLang: 'en',
    ogLocale: 'en_US',
    title: 'Blog — Bocchi Company',
    description: 'Technical writing from Bocchi Company on offensive security, detection engineering, and software.',
    eyebrow: '04 / Blog',
    heading: ['Field notes', 'from operators.'],
    sub: 'What we learn attacking, detecting, and building — written for the person who has to touch the keyboard next.',
    empty: 'No posts published yet. Soon.',
    backToList: '← All articles',
    readingTime: (n) => `${n} min read`,
    publishedOn: 'Published on',
    updatedOn: 'Updated on',
    tocTitle: 'In this article',
    skip: 'Skip to content',
    navLabel: 'Blog',
  },
};

// ---------------------------------------------------------------- utilidades

const errors = [];
const fail = (msg) => errors.push(msg);

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** Slug seguro para virar nome de arquivo e caminho de URL. */
function slugify(s) {
  return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
}

function formatDate(iso, lang) {
  const d = new Date(iso + (iso.length === 10 ? 'T12:00:00Z' : ''));
  return new Intl.DateTimeFormat(lang === 'pt' ? 'pt-BR' : 'en-US',
    { day: '2-digit', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(d);
}

// ------------------------------------------------------- shell do site (nav)

/**
 * Extrai <header class="nav"> e <footer class="footer"> de uma página real e
 * reescreve os caminhos relativos de asset para absolutos — o blog vive em
 * /blog/, um nível abaixo, então "assets/..." apontaria para /blog/assets/...
 */
function extractShell(lang) {
  const cfg = LANGS[lang];
  const file = path.join(ROOT, cfg.shellFrom);
  if (!fs.existsSync(file)) { fail(`shell: ${cfg.shellFrom} não existe`); return null; }
  const src = fs.readFileSync(file, 'utf8');

  const grab = (open, close, label) => {
    const i = src.indexOf(open);
    const j = src.indexOf(close, i);
    if (i < 0 || j < 0) { fail(`shell: não achei ${label} em ${cfg.shellFrom}`); return ''; }
    return src.slice(i, j + close.length);
  };

  const absolutize = (html) => html
    .replace(/(href|src)="\.\.\/assets\//g, '$1="/assets/')
    .replace(/(href|src)="assets\//g, '$1="/assets/');

  let nav = absolutize(grab('<header class="nav"', '</header>', 'o <header class="nav">'));
  const footer = absolutize(grab('<footer class="footer"', '</footer>', 'o <footer>'));

  // Nenhum item do menu principal é a página atual: limpa os marcadores de
  // "página ativa" herdados da página usada como molde.
  nav = nav.replace(/\s*class="is-active"/g, '')
           .replace(/\s*aria-current="page"/g, '');

  return { nav, footer };
}

// --------------------------------------------------------------- markdown

const md = new MarkdownIt({
  html: true,          // o autor é o dono do site; embeds de vídeo precisam disso
  linkify: true,
  typographer: false,
  breaks: false,
  highlight(code, lang) {
    const cls = 'hljs language-' + (lang || 'text');
    if (lang && hljs.getLanguage(lang)) {
      try {
        const out = hljs.highlight(code, { language: lang, ignoreIllegals: true }).value;
        return `<pre class="code"><code class="${cls}">${out}</code></pre>`;
      } catch { /* cai no texto puro abaixo */ }
    }
    return `<pre class="code"><code class="${cls}">${esc(code)}</code></pre>`;
  },
});

/**
 * Um link é externo quando a ORIGEM difere da do site.
 *
 * Comparar por prefixo de string não serve: "https://bocchi.company" é
 * prefixo de "https://bocchi.company.evil.com", que é outro site e passaria
 * por interno. Parsear a URL e comparar a origem elimina a classe inteira
 * de erro — inclusive variações com porta, credenciais ou caminho.
 */
const SITE_ORIGIN = new URL(SITE).origin;
function isExternalLink(href) {
  if (!/^https?:\/\//i.test(href)) return false;   // relativo, âncora, mailto:
  try {
    return new URL(href).origin !== SITE_ORIGIN;
  } catch {
    return true;   // não parseou: tratar como externo é o lado seguro
  }
}

// Links externos abrem em nova aba com rel seguro; internos ficam como estão.
const defaultLinkOpen = md.renderer.rules.link_open
  || ((tokens, idx, opts, _env, self) => self.renderToken(tokens, idx, opts));
md.renderer.rules.link_open = (tokens, idx, opts, env, self) => {
  if (isExternalLink(tokens[idx].attrGet('href') || '')) {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
  }
  return defaultLinkOpen(tokens, idx, opts, env, self);
};

// Imagens do corpo: lazy + dimensões vazias evitam layout shift quando
// informadas no Markdown como ![alt](src "title =LxA")
md.renderer.rules.image = (tokens, idx, opts, _env, self) => {
  const t = tokens[idx];
  t.attrSet('loading', 'lazy');
  t.attrSet('decoding', 'async');
  return self.renderToken(tokens, idx, opts);
};

// Cabeçalhos ganham id (âncora) para o índice do artigo.
md.renderer.rules.heading_open = (tokens, idx, opts, env, self) => {
  const t = tokens[idx];
  if (t.tag === 'h2' || t.tag === 'h3') {
    const text = tokens[idx + 1]?.content || '';
    const id = slugify(text);
    if (id) {
      t.attrSet('id', id);
      (env.toc ||= []).push({ level: t.tag, id, text });
    }
  }
  return self.renderToken(tokens, idx, opts);
};

// ------------------------------------------------------------------- posts

function readPosts() {
  const dir = path.join(ROOT, 'content', 'blog');
  if (!fs.existsSync(dir)) return [];
  const posts = [];
  const seen = new Map();

  for (const name of fs.readdirSync(dir).sort()) {
    if (!name.endsWith('.md')) continue;
    const full = path.join(dir, name);
    let fm;
    try { fm = matter(fs.readFileSync(full, 'utf8')); }
    catch (e) { fail(`${name}: frontmatter inválido — ${e.message}`); continue; }

    const d = fm.data || {};
    const where = `content/blog/${name}`;

    if (!d.title) { fail(`${where}: falta "title"`); continue; }
    if (!d.date)  { fail(`${where}: falta "date"`); continue; }
    const date = String(d.date instanceof Date ? d.date.toISOString().slice(0, 10) : d.date).slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { fail(`${where}: "date" deve ser AAAA-MM-DD, veio "${d.date}"`); continue; }

    // "updated" sai cru em <meta content=...> e no <lastmod> do sitemap, então
    // é validado com o mesmo rigor de "date". Sem isso, dez caracteres de
    // frontmatter fecham a tag e injetam markup na página gerada.
    const updated = d.updated
      ? String(d.updated instanceof Date ? d.updated.toISOString().slice(0, 10) : d.updated).slice(0, 10)
      : '';
    if (updated && !/^\d{4}-\d{2}-\d{2}$/.test(updated)) {
      fail(`${where}: "updated" deve ser AAAA-MM-DD, veio "${d.updated}"`); continue;
    }

    const lang = d.lang === 'en' ? 'en' : 'pt';
    const slug = slugify(d.slug || name.replace(/^\d{4}-\d{2}-\d{2}-/, '').replace(/\.md$/, ''));
    if (!slug) { fail(`${where}: não consegui derivar um slug`); continue; }

    const key = `${lang}/${slug}`;
    if (seen.has(key)) { fail(`${where}: slug "${slug}" (${lang}) já usado por ${seen.get(key)}`); continue; }
    seen.set(key, where);

    if (d.cover) {
      const rel = String(d.cover).replace(/^\//, '');
      if (!fs.existsSync(path.join(ROOT, rel))) fail(`${where}: cover "${d.cover}" não existe`);
    }

    posts.push({
      file: where, lang, slug, date,
      title: String(d.title),
      excerpt: d.excerpt ? String(d.excerpt) : '',
      cover: d.cover ? '/' + String(d.cover).replace(/^\//, '') : '',
      coverAlt: d.cover_alt ? String(d.cover_alt) : '',
      tags: Array.isArray(d.tags) ? d.tags.map(String) : [],
      updated,
      draft: d.draft === true,
      translationOf: d.translation_of ? slugify(String(d.translation_of)) : '',
      body: fm.content,
    });
  }
  // mais recente primeiro; empate resolvido pelo slug para o build ser determinístico
  posts.sort((a, b) => (b.date.localeCompare(a.date)) || a.slug.localeCompare(b.slug));
  return posts;
}

function renderBody(post) {
  const env = {};
  const html = md.render(post.body, env);
  const words = post.body.replace(/```[\s\S]*?```/g, ' ').split(/\s+/).filter(Boolean).length;
  return { html, toc: env.toc || [], minutes: Math.max(1, Math.round(words / 220)) };
}

// ---------------------------------------------------------------- templates

function head({ lang, title, description, url, image, type = 'article', published, modified, extraCss = true }) {
  const cfg = LANGS[lang];
  const alt = lang === 'pt' ? '/en/blog/' : '/blog/';
  return `<!DOCTYPE html>
<html lang="${cfg.htmlLang}">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="theme-color" content="#0A0E1C" />
<title>${esc(title)}</title>
<meta name="description" content="${esc(description)}" />
<meta name="author" content="Bocchi Company" />

<meta property="og:title" content="${esc(title)}" />
<meta property="og:description" content="${esc(description)}" />
<meta property="og:type" content="${type}" />
<meta property="og:image" content="${esc(image)}" />
<meta property="og:site_name" content="Bocchi Company" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:image" content="${esc(image)}" />
<meta property="og:locale" content="${cfg.ogLocale}" />
<meta property="og:url" content="${esc(url)}" />
${published ? `<meta property="article:published_time" content="${esc(published)}" />` : ''}
${modified ? `<meta property="article:modified_time" content="${esc(modified)}" />` : ''}

<link rel="icon" type="image/svg+xml" href="/assets/logos/mark-color.svg" />
<link rel="icon" type="image/png" sizes="32x32" href="/assets/logos/favicon-32.png" />
<link rel="icon" type="image/png" sizes="16x16" href="/assets/logos/favicon-16.png" />
<link rel="apple-touch-icon" sizes="180x180" href="/assets/logos/apple-touch-icon.png" />

<link rel="canonical" href="${esc(url)}" />
<link rel="alternate" type="application/rss+xml" title="Bocchi Company — Blog" href="/feed.xml" />
<link rel="alternate" hreflang="pt-BR" href="${SITE}${lang === 'pt' ? '/blog/' : alt}" />
<link rel="alternate" hreflang="en" href="${SITE}${lang === 'en' ? '/en/blog/' : alt}" />

<link rel="preload" href="/assets/fonts/hankengrotesk-latin.woff2" as="font" type="font/woff2" crossorigin />
<link rel="preload" href="/assets/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin />
<link rel="stylesheet" href="/assets/css/fonts.css?v=1" />
<link rel="stylesheet" href="/assets/css/style.css?v=7" />${extraCss ? `
<link rel="stylesheet" href="/assets/css/blog.css?v=1" />` : ''}
</head>
<body>
<a class="skip-link" href="#top">${cfg.skip}</a>
`;
}

function tail() {
  return `
<script src="/assets/js/main.js?v=8" defer></script>
</body>
</html>
`;
}

function postCard(post, cfg) {
  const href = `${cfg.base}${post.slug}`;
  const tags = post.tags.slice(0, 3)
    .map((t) => `<li>${esc(t)}</li>`).join('');
  return `      <article class="post-card reveal">
        ${post.cover ? `<a class="post-card__media" href="${href}" tabindex="-1" aria-hidden="true">
          <img src="${esc(post.cover)}" alt="" loading="lazy" decoding="async" />
        </a>` : ''}
        <div class="post-card__body">
          <div class="post-card__meta">
            <time datetime="${post.date}">${formatDate(post.date, post.lang)}</time>
            <span aria-hidden="true">·</span>
            <span>${cfg.readingTime(post.rendered.minutes)}</span>
          </div>
          <h2 class="post-card__title"><a href="${href}">${esc(post.title)}</a></h2>
          ${post.excerpt ? `<p class="post-card__excerpt">${esc(post.excerpt)}</p>` : ''}
          ${tags ? `<ul class="post-card__tags">${tags}</ul>` : ''}
        </div>
      </article>`;
}

function renderIndex(lang, posts, shell) {
  const cfg = LANGS[lang];
  const url = `${SITE}${cfg.base}`;
  const list = posts.length
    ? posts.map((p) => postCard(p, cfg)).join('\n')
    : `      <div class="project-soon reveal"><strong>${esc(cfg.empty)}</strong></div>`;

  return head({ lang, title: cfg.title, description: cfg.description, url, image: `${SITE}/assets/img/og-cover.png`, type: 'website' })
    + shell.nav + `

<main id="top">

<section class="page-hero">
  <div class="page-hero__bg" aria-hidden="true"></div>
  <div class="page-hero__grid" aria-hidden="true"></div>
  <div class="container page-hero__inner">
    <div class="hero__eyebrow reveal">
      <span class="hero__eyebrow-rule" aria-hidden="true"></span>
      <span>${esc(cfg.eyebrow)}</span>
    </div>
    <h1 class="page-hero__title reveal" data-reveal-delay="60">${esc(cfg.heading[0])} <span class="ital">${esc(cfg.heading[1])}</span></h1>
    <p class="page-hero__sub reveal" data-reveal-delay="120">${esc(cfg.sub)}</p>
  </div>
</section>

<section class="section" id="posts">
  <div class="container">
    <div class="post-list">
${list}
    </div>
  </div>
</section>

</main>
` + shell.footer + tail();
}

function renderPost(post, shell, siblings) {
  const cfg = LANGS[post.lang];
  const url = `${SITE}${cfg.base}${post.slug}`;
  const { html, toc, minutes } = post.rendered;
  const desc = post.excerpt || `${post.title} — Bocchi Company`;

  const ld = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: post.title,
    datePublished: post.date,
    ...(post.updated ? { dateModified: post.updated } : {}),
    author: { '@type': 'Organization', name: 'Bocchi Company', url: `${SITE}/` },
    publisher: { '@id': `${SITE}/#org` },
    mainEntityOfPage: url,
    ...(post.cover ? { image: SITE + post.cover } : {}),
    inLanguage: cfg.htmlLang,
  };

  const tocHtml = toc.filter((t) => t.level === 'h2').length >= 3
    ? `      <nav class="post-toc reveal" aria-label="${esc(cfg.tocTitle)}">
        <h2 class="post-toc__title">${esc(cfg.tocTitle)}</h2>
        <ol>${toc.filter((t) => t.level === 'h2')
          .map((t) => `<li><a href="#${t.id}">${esc(t.text)}</a></li>`).join('')}</ol>
      </nav>\n` : '';

  const prev = siblings.prev
    ? `<a class="post-nav__item" href="${cfg.base}${siblings.prev.slug}"><span>←</span> ${esc(siblings.prev.title)}</a>` : '';
  const next = siblings.next
    ? `<a class="post-nav__item post-nav__item--next" href="${cfg.base}${siblings.next.slug}">${esc(siblings.next.title)} <span>→</span></a>` : '';

  return head({ lang: post.lang, title: `${post.title} — Bocchi Company`, description: desc, url,
                image: post.cover ? SITE + post.cover : `${SITE}/assets/img/og-cover.png`,
                published: post.date, modified: post.updated || post.date })
    + `<script type="application/ld+json">${JSON.stringify(ld)}</script>\n`
    + shell.nav + `

<main id="top">

<article class="post">
  <header class="post__head">
    <div class="container post__head-inner">
      <a class="post__back reveal" href="${cfg.base}">${esc(cfg.backToList)}</a>
      <h1 class="post__title reveal" data-reveal-delay="60">${esc(post.title)}</h1>
      <div class="post__meta reveal" data-reveal-delay="120">
        <time datetime="${post.date}">${esc(cfg.publishedOn)} ${formatDate(post.date, post.lang)}</time>
        <span aria-hidden="true">·</span>
        <span>${esc(cfg.readingTime(minutes))}</span>
        ${post.updated ? `<span aria-hidden="true">·</span><span>${esc(cfg.updatedOn)} ${formatDate(post.updated, post.lang)}</span>` : ''}
      </div>
      ${post.tags.length ? `<ul class="post__tags reveal" data-reveal-delay="160">${post.tags.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>` : ''}
    </div>
  </header>

  ${post.cover ? `<div class="container"><figure class="post__cover reveal">
    <img src="${esc(post.cover)}" alt="${esc(post.coverAlt)}" loading="eager" fetchpriority="high" decoding="async" />
  </figure></div>` : ''}

  <div class="container post__layout">
${tocHtml}    <div class="post__body reveal">
${html}
    </div>
  </div>

  ${(prev || next) ? `<div class="container"><nav class="post-nav" aria-label="${esc(cfg.navLabel)}">${prev}${next}</nav></div>` : ''}
</article>

</main>
` + shell.footer + tail();
}

function renderFeed(posts) {
  const items = posts.slice(0, 20).map((p) => {
    const cfg = LANGS[p.lang];
    const url = `${SITE}${cfg.base}${p.slug}`;
    return `  <item>
    <title>${esc(p.title)}</title>
    <link>${url}</link>
    <guid isPermaLink="true">${url}</guid>
    <pubDate>${new Date(p.date + 'T12:00:00Z').toUTCString()}</pubDate>
    ${p.excerpt ? `<description>${esc(p.excerpt)}</description>` : ''}
  </item>`;
  }).join('\n');

  return `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>Bocchi Company — Blog</title>
  <link>${SITE}/blog/</link>
  <description>${esc(LANGS.pt.description)}</description>
  <language>pt-BR</language>
  <atom:link href="${SITE}/feed.xml" rel="self" type="application/rss+xml" />
${items}
</channel>
</rss>
`;
}

/** Reescreve o bloco do blog no sitemap.xml, preservando o resto do arquivo. */
function updateSitemap(posts, today) {
  const file = path.join(ROOT, 'sitemap.xml');
  if (!fs.existsSync(file)) { fail('sitemap.xml não existe'); return null; }
  const src = fs.readFileSync(file, 'utf8');
  const START = '  <!-- blog:start -->';
  const END = '  <!-- blog:end -->';

  const entries = [];
  for (const lang of ['pt', 'en']) {
    const cfg = LANGS[lang];
    const langPosts = posts.filter((p) => p.lang === lang);
    if (!langPosts.length) continue;
    entries.push(`  <url>\n    <loc>${SITE}${cfg.base}</loc>\n    <lastmod>${langPosts[0].updated || langPosts[0].date}</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>0.7</priority>\n  </url>`);
    for (const p of langPosts) {
      entries.push(`  <url>\n    <loc>${SITE}${cfg.base}${p.slug}</loc>\n    <lastmod>${p.updated || p.date}</lastmod>\n    <changefreq>yearly</changefreq>\n    <priority>0.6</priority>\n  </url>`);
    }
  }
  const block = `${START}\n${entries.join('\n')}${entries.length ? '\n' : ''}${END}`;

  if (src.includes(START) && src.includes(END)) {
    return src.replace(new RegExp(`${START}[\\s\\S]*?${END}`), block);
  }
  return src.replace('</urlset>', `${block}\n</urlset>`);
}

// --------------------------------------------------------------------- main

/**
 * Interpolação quebrada em template literal não dá erro — ela imprime
 * "undefined" na página e só aparece quando alguém olha. Como o build é
 * automático, ninguém olha. Então o próprio build recusa a saída.
 */
function assertNoPlaceholders(rel, content) {
  const stripped = content.replace(/<pre class="code">[\s\S]*?<\/pre>/g, '');
  for (const bad of ['undefined', 'NaN', '[object Object]']) {
    if (stripped.includes(bad)) {
      const at = stripped.indexOf(bad);
      fail(`${rel}: gerou "${bad}" no HTML — perto de: ${JSON.stringify(stripped.slice(Math.max(0, at - 70), at + 40))}`);
    }
  }
}

function write(rel, content) {
  const full = path.join(ROOT, rel);
  if (rel.endsWith('.html')) assertNoPlaceholders(rel, content);
  if (CHECK_ONLY) return;
  fs.mkdirSync(path.dirname(full), { recursive: true });
  const prev = fs.existsSync(full) ? fs.readFileSync(full, 'utf8') : null;
  if (prev !== content) fs.writeFileSync(full, content);
}

/**
 * Autoteste da classificação de link externo. Roda junto com o build, custa
 * microssegundos e trava a geração se alguém trocar a comparação por origem
 * de volta por um startsWith — que é justamente o bug que o CodeQL pegou:
 * "https://bocchi.company" é prefixo de "https://bocchi.company.evil.com".
 */
function selfTestLinks() {
  const casos = [
    ['https://bocchi.company/sobre', false],
    ['https://bocchi.company', false],
    ['https://bocchi.company.evil.com/phish', true],
    ['https://bocchi.companyX.com/', true],
    ['https://bocchi.company:8443/x', true],
    ['https://evil.com/?u=https://bocchi.company', true],
    ['https://sigmaward.com', true],
    ['/blog/post', false],
    ['#secao', false],
    ['mailto:contato@bocchi.company', false],
  ];
  for (const [href, esperado] of casos) {
    if (isExternalLink(href) !== esperado) {
      fail(`autoteste de link: "${href}" deveria ser ${esperado ? 'externo' : 'interno'}`);
    }
  }
}

function main() {
  selfTestLinks();
  const posts = readPosts();
  const published = posts.filter((p) => !p.draft);
  for (const p of published) p.rendered = renderBody(p);

  // Um post EN referenciando um post PT inexistente é erro de digitação.
  const bySlug = new Set(published.map((p) => `${p.lang}/${p.slug}`));
  for (const p of published) {
    if (!p.translationOf) continue;
    const other = p.lang === 'pt' ? 'en' : 'pt';
    if (!bySlug.has(`${other}/${p.translationOf}`)) {
      fail(`${p.file}: translation_of aponta para "${p.translationOf}", que não existe em ${other}`);
    }
  }

  if (errors.length) {
    console.error('\nBuild do blog falhou:\n' + errors.map((e) => '  - ' + e).join('\n') + '\n');
    process.exit(1);
  }

  const today = new Date().toISOString().slice(0, 10);
  let written = 0;

  for (const lang of ['pt', 'en']) {
    const shell = extractShell(lang);
    if (!shell) continue;
    const cfg = LANGS[lang];
    const langPosts = published.filter((p) => p.lang === lang);

    write(path.join(cfg.dir, 'index.html'), renderIndex(lang, langPosts, shell));
    written++;

    langPosts.forEach((post, i) => {
      write(path.join(cfg.dir, `${post.slug}.html`), renderPost(post, shell, {
        prev: langPosts[i + 1] || null,   // mais antigo
        next: langPosts[i - 1] || null,   // mais novo
      }));
      written++;
    });

    // Remove HTML de posts que sumiram do content/ (renomeados ou despublicados).
    const dir = path.join(ROOT, cfg.dir);
    if (!CHECK_ONLY && fs.existsSync(dir)) {
      const keep = new Set(['index.html', ...langPosts.map((p) => `${p.slug}.html`)]);
      for (const f of fs.readdirSync(dir)) {
        if (f.endsWith('.html') && !keep.has(f)) fs.unlinkSync(path.join(dir, f));
      }
    }
  }

  write('feed.xml', renderFeed(published));
  const sm = updateSitemap(published, today);
  if (sm) write('sitemap.xml', sm);

  if (errors.length) {
    console.error('\nBuild do blog falhou:\n' + errors.map((e) => '  - ' + e).join('\n') + '\n');
    process.exit(1);
  }

  const drafts = posts.length - published.length;
  console.log(CHECK_ONLY
    ? `Blog OK: ${published.length} post(s) válido(s)${drafts ? `, ${drafts} rascunho(s) ignorado(s)` : ''}.`
    : `Blog gerado: ${written} página(s), ${published.length} post(s)${drafts ? `, ${drafts} rascunho(s) ignorado(s)` : ''}, feed.xml e sitemap.xml atualizados.`);
}

main();
