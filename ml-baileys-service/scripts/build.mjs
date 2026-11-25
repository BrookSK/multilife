// Gera dist/server.cjs: o serviço inteiro (com o Baileys e as dependências)
// num arquivo só. Assim o servidor não precisa de "npm install": basta o
// arquivo do repositório. Rodar aqui (máquina de desenvolvimento) antes do commit:
//   npm run build
import { build } from 'esbuild';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

await build({
  entryPoints: [path.join(root, 'src/index.js')],
  outfile: path.join(root, 'dist/server.cjs'),
  bundle: true,
  platform: 'node',
  target: 'node20',
  format: 'cjs',
  minify: true,
  keepNames: true,
  legalComments: 'none',
  logLevel: 'warning',
  // config.js usa __dirname no pacote; import.meta só vale rodando src/ direto
  logOverride: { 'empty-import-meta': 'silent' },
  // Opcionais: o código já funciona sem eles (try/catch)
  external: ['sharp', 'jimp', 'bufferutil', 'utf-8-validate', 'audio-decode', 'qrcode-terminal', 'pino-pretty', 'supports-color'],
  define: { 'process.env.ML_BAILEYS_BUNDLE': '"1"' },
  banner: { js: '/* ml-baileys-service: gerado por scripts/build.mjs — não editar */' },
});
console.log('dist/server.cjs gerado');
