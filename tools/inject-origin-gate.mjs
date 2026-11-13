import { readFileSync, writeFileSync } from 'node:fs';

const path = process.argv[2];
const secret = process.env.ORIGIN_GATE_SECRET;

if (!path || !/^[0-9a-f]{64}$/.test(secret ?? '')) {
  throw new Error('ORIGIN_GATE_SECRET ausente ou inválido; publicação interrompida');
}

const source = readFileSync(path, 'utf8');
const anchor = '  RewriteEngine On\n';
if (source.split(anchor).length !== 2 || source.includes('X-Bocchi-Origin')) {
  throw new Error('.htaccess inesperado; publicação interrompida');
}

const guard = [
  anchor,
  '  # Apenas requisições encaminhadas pela Cloudflare alcançam a origem.\n',
  `  RewriteCond %{HTTP:X-Bocchi-Origin} !^${secret}$\n`,
  '  RewriteRule ^ - [F]\n',
];
writeFileSync(path, source.replace(anchor, guard.join('')));
console.log('Guarda de origem injetada no pacote de upload');
