/*
 * Genera los iconos PNG de la PWA a partir de assets/pwa/icon-master.svg.
 *
 * Uso:
 *   node tools/generate-icons.mjs
 *
 * Requiere "sharp". Si no está instalado localmente, ejecútalo con npx:
 *   npx -y -p sharp node tools/generate-icons.mjs
 */
import { readFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const sharp = (await import('sharp')).default;

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const svgPath = resolve(root, 'assets/pwa/icon-master.svg');
const outDir = resolve(root, 'public/img');
mkdirSync(outDir, { recursive: true });

const svg = readFileSync(svgPath);

const targets = [
    { file: 'icon-192.png', size: 192, opaque: false },
    { file: 'icon-512.png', size: 512, opaque: false },
    // apple-touch-icon: sin transparencia (iOS no soporta alfa).
    { file: 'apple-touch-icon.png', size: 180, opaque: true },
];

for (const { file, size, opaque } of targets) {
    let img = sharp(svg, { density: 384 }).resize(size, size);
    if (opaque) {
        img = img.flatten({ background: '#0E1117' });
    }
    // palette: true mantiene los PNG pequeños (Hugging Face rechaza binarios
    // grandes en el push del Space). Para reducir aún más: pngquant.
    await img.png({ palette: true, compressionLevel: 9 }).toFile(resolve(outDir, file));
    console.log(`✓ ${file} (${size}x${size})`);
}

console.log('Iconos generados en public/img/');
