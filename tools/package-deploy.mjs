#!/usr/bin/env node
/**
 * Empacota o que vai para dentro do public_html da Hostinger.
 *
 * O repositório contém coisas que NÃO são o site: o Markdown fonte dos posts,
 * o gerador, as dependências do npm, a documentação, o workflow do CI. Subir
 * tudo por File Manager desperdiça tempo e inode, e coloca no servidor
 * arquivos que não têm por que estar lá.
 *
 * A lista abaixo é um ALLOWLIST, não um denylist: qualquer coisa nova na raiz
 * do repositório fica de fora até ser incluída aqui de propósito. Assim, um
 * arquivo de credencial criado no futuro não vai junto por descuido.
 *
 * Uso:
 *   node tools/package-deploy.mjs          gera dist/ e deploy.zip
 *   node tools/package-deploy.mjs --no-zip só a pasta dist/
 */

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DIST = path.join(ROOT, 'dist');
const ZIP = path.join(ROOT, 'deploy.zip');
const NO_ZIP = process.argv.includes('--no-zip');

/** Diretórios que vão inteiros para o public_html. */
const DIRS = ['assets', 'en', 'blog', 'admin'];

/** Arquivos avulsos da raiz. Padrões glob simples só de sufixo/nome exato. */
const FILES = ['.htaccess', 'robots.txt', 'sitemap.xml', 'feed.xml', 'send.php', 'smtp.php'];
const FILE_PATTERNS = [/^[a-z0-9-]+\.html$/i];   // index.html, sobre.html, 404.html…

/**
 * Nada que case com isto pode entrar no pacote, venha de onde vier.
 * É a última barreira: se um dia alguém adicionar assets/bocchi-oauth.php
 * por engano, o empacotamento falha em vez de publicar a credencial.
 */
const NUNCA = [
  /(^|\/)bocchi-(smtp|oauth)[^/]*\.php$/i,   // credenciais reais e os modelos
  /(^|\/)\.git(\/|$)/,
  /(^|\/)node_modules(\/|$)/,
  /(^|\/)\.env/i,
  /\.(key|pem|p12|pfx)$/i,
  /(^|\/)smtp-diag/i,
];

const problemas = [];

function walk(dir, base = '') {
  const out = [];
  for (const entry of fs.readdirSync(path.join(dir, base), { withFileTypes: true })) {
    const rel = base ? `${base}/${entry.name}` : entry.name;
    if (entry.isDirectory()) out.push(...walk(dir, rel));
    else out.push(rel);
  }
  return out;
}

function copiar(relOrigem, relDestino) {
  for (const re of NUNCA) {
    if (re.test(relOrigem)) {
      problemas.push(`recusado: ${relOrigem} casa com um padrão que nunca pode ser publicado`);
      return 0;
    }
  }
  const src = path.join(ROOT, relOrigem);
  const dst = path.join(DIST, relDestino);
  fs.mkdirSync(path.dirname(dst), { recursive: true });
  fs.copyFileSync(src, dst);
  return 1;
}

// ------------------------------------------------------------------ montar

fs.rmSync(DIST, { recursive: true, force: true });
fs.mkdirSync(DIST, { recursive: true });

let n = 0;

for (const nome of fs.readdirSync(ROOT)) {
  const full = path.join(ROOT, nome);
  if (fs.statSync(full).isDirectory()) continue;
  if (FILES.includes(nome) || FILE_PATTERNS.some((re) => re.test(nome))) {
    n += copiar(nome, nome);
  }
}

for (const dir of DIRS) {
  const full = path.join(ROOT, dir);
  if (!fs.existsSync(full)) { problemas.push(`faltando: o diretório ${dir}/ não existe`); continue; }
  for (const rel of walk(ROOT, dir)) n += copiar(rel, rel);
}

// ------------------------------------------------------------- conferências

// O .htaccess é o que faz as URLs limpas, os headers de segurança e o bloqueio
// dos arquivos de backend funcionarem. Sem ele o site sobe "funcionando" e
// silenciosamente sem nada disso — o pior tipo de falha.
for (const obrigatorio of ['.htaccess', 'index.html', 'admin/.htaccess', 'assets/css/style.css']) {
  if (!fs.existsSync(path.join(DIST, obrigatorio))) problemas.push(`faltando no pacote: ${obrigatorio}`);
}

if (!fs.existsSync(path.join(DIST, 'blog', 'index.html'))) {
  problemas.push('faltando no pacote: blog/index.html — rode "npm run build:blog" antes');
}

if (problemas.length) {
  console.error('\nEmpacotamento falhou:\n' + problemas.map((p) => '  - ' + p).join('\n') + '\n');
  process.exit(1);
}

// ---------------------------------------------------------------------- zip

let tamanho = 0;
for (const rel of walk(DIST)) tamanho += fs.statSync(path.join(DIST, rel)).size;

if (!NO_ZIP) {
  fs.rmSync(ZIP, { force: true });
  // -r recursivo, -q silencioso, -X sem metadados de sistema.
  // O "." como alvo garante que os dotfiles (.htaccess!) entrem no zip.
  execFileSync('zip', ['-rqX', ZIP, '.'], { cwd: DIST });
  const dentro = execFileSync('unzip', ['-Z1', ZIP], { encoding: 'utf8' }).trim().split('\n');
  if (!dentro.includes('.htaccess')) {
    console.error('\nO .htaccess não entrou no zip. Abortando — subir sem ele quebra as URLs limpas e os headers.\n');
    process.exit(1);
  }
  console.log(`deploy.zip: ${dentro.length} entradas, ${(fs.statSync(ZIP).size / 1024 / 1024).toFixed(1)} MB`);
}

console.log(`dist/: ${n} arquivo(s), ${(tamanho / 1024 / 1024).toFixed(1)} MB`);
console.log('\nO conteúdo de dist/ (ou do deploy.zip) vai DENTRO do public_html.');
console.log('O bocchi-smtp.php e o bocchi-oauth.php ficam UM NÍVEL ACIMA — não entram aqui, de propósito.');
