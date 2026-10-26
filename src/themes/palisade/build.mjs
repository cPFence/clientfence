// Builds assets/build/ for Palisade, the ClientFence client area theme:
//   css/palisade.css       Bootstrap + theme (left to right)
//   css/palisade.rtl.css   the same, mirrored for Arabic
//   js/palisade.js         client script (+ lazily loaded chunks)
//   html/partials/asset_version.html.twig  short hash of the build, appended as ?v= to asset URLs
//   symbol/icons-sprite.svg  Tabler icons listed in icon-manifest.json (read by FOSSBilling's svg_sprite())
import { createHash } from 'node:crypto';
import { mkdir, rm, readFile, readdir, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';
import * as sass from 'sass';
import rtlcss from 'rtlcss';

const root = dirname(fileURLToPath(import.meta.url));
const src = join(root, 'assets/src');
const out = join(root, 'assets/build');

await rm(out, { recursive: true, force: true });
await mkdir(join(src, '.build'), { recursive: true });

const compiled = sass.compile(join(src, 'scss/palisade.scss'), {
  loadPaths: [join(root, 'node_modules')],
  quietDeps: true,
  silenceDeprecations: ['import', 'global-builtin', 'color-functions'],
});
await writeFile(join(src, '.build/palisade.css'), compiled.css);

await esbuild.build({
  entryPoints: { palisade: join(src, 'entry.css'), iti: join(src, 'iti.css') },
  bundle: true,
  minify: true,
  outdir: join(out, 'css'),
  assetNames: '[name]-[hash]',
  loader: { '.woff2': 'file', '.woff': 'empty', '.webp': 'file', '.png': 'file', '.svg': 'file' },
  logLevel: 'warning',
});

// intl-tel-input goes first in both files, unmirrored (it handles RTL itself).
const iti = await readFile(join(out, 'css/iti.css'), 'utf8');
const ltr = await readFile(join(out, 'css/palisade.css'), 'utf8');
await writeFile(join(out, 'css/palisade.css'), iti + ltr);
await writeFile(join(out, 'css/palisade.rtl.css'), iti + rtlcss.process(ltr));
await rm(join(out, 'css/iti.css'));

await esbuild.build({
  entryPoints: { palisade: join(src, 'js/palisade.js') },
  bundle: true,
  minify: true,
  format: 'esm',
  splitting: true,
  outdir: join(out, 'js'),
  chunkNames: 'chunks/[name]-[hash]',
  target: ['es2020'],
  drop: ['debugger'],
  logLevel: 'warning',
});

// An entry is a name, or { name, dynamic } for an icon the icon check cannot see in the templates.
const icons = JSON.parse(await readFile(join(root, 'icon-manifest.json'), 'utf8')).icons.map((icon) => (typeof icon === 'string' ? icon : icon.name));
const symbols = await Promise.all(icons.map(async (name) => {
  const svg = await readFile(join(root, 'node_modules/@tabler/icons/icons/outline', `${name}.svg`), 'utf8');
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').replace(/\s*\n\s*/g, '');
  return `<symbol id="${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
}));
await mkdir(join(out, 'symbol'), { recursive: true });
await writeFile(join(out, 'symbol/icons-sprite.svg'),
  `<svg xmlns="http://www.w3.org/2000/svg">${symbols.join('')}</svg>\n`);

await rm(join(src, '.build'), { recursive: true, force: true });

// Cache-busting version: a hash over everything in assets/build.
const hash = createHash('sha256');
for (const dir of [out]) {
  const files = (await readdir(dir, { recursive: true, withFileTypes: true }))
    .filter((entry) => entry.isFile())
    .map((entry) => join(entry.parentPath, entry.name))
    .sort();
  for (const file of files) {
    hash.update(file.slice(root.length)).update(await readFile(file));
  }
}
await writeFile(join(root, 'html/partials/asset_version.html.twig'), hash.digest('hex').slice(0, 10));
console.log(`Built assets/build (palisade.css, palisade.rtl.css, palisade.js, ${icons.length} icons)`);
