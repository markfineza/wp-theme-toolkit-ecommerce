#!/usr/bin/env node
// Generates the NEXORA market demo image set from original vector artwork.
//
// Usage: node tools/image-generator/generate.mjs [--only=slug-fragment]
// Requires Google Chrome (headless screenshot) and macOS `sips` (PNG → JPEG).
// Output: nexora-commerce-toolkit/demo/images/*.jpg
import { readFileSync, writeFileSync, mkdirSync, rmSync, existsSync, readdirSync, unlinkSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ART, BASES, FLOOR, shade } from './art.mjs';

const run = promisify(execFile);
const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const catalog = JSON.parse(readFileSync(join(root, 'nexora-commerce-toolkit/demo/data/products.json'), 'utf8'));
const outDir = join(root, 'nexora-commerce-toolkit/demo/images');
const tmpDir = join(here, 'build');
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const only = (process.argv.find((a) => a.startsWith('--only=')) || '').slice(7);

const cats = Object.fromEntries(catalog.categories.map((c) => [c.slug, c]));

function place(name, color, x, y, s, rot = 0) {
	const a = ART[name](color);
	return { defs: a.defs, body: `<g transform="translate(${x} ${y}) rotate(${rot}) scale(${s}) translate(-500 -500)">${a.body}</g>` };
}

function svg(w, h, defs, body) {
	return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}"><defs>${defs}</defs>${body}</svg>`;
}

// Market surface under an item, drawn in the 1000×1000 product space.
function base(type) {
	switch (type) {
		case 'bilao': {
			let weave = '';
			for (let i = -6; i <= 6; i++) weave += `<path d="M${500 + i * 48} 690 l 60 140" stroke="#B88A45" stroke-opacity=".45" stroke-width="5"/><path d="M${500 + i * 48} 690 l -60 140" stroke="#B88A45" stroke-opacity=".3" stroke-width="5"/>`;
			return `<ellipse cx="500" cy="775" rx="380" ry="100" fill="#C9974E"/><g clip-path="url(#bilaoClip)"><ellipse cx="500" cy="765" rx="350" ry="82" fill="#E3BC79"/>${weave}</g>`;
		}
		case 'ice': {
			let cubes = '';
			for (let i = 0; i < 12; i++) cubes += `<rect x="${190 + i * 52}" y="${700 + (i % 3) * 14}" width="46" height="40" rx="10" fill="#F0F9FF" fill-opacity=".85" transform="rotate(${(i * 23) % 30 - 15} ${213 + i * 52} ${720 + (i % 3) * 14})"/>`;
			return `<rect x="150" y="700" width="700" height="130" rx="44" fill="#BAE6FD"/><rect x="170" y="690" width="660" height="70" rx="34" fill="#E0F2FE"/>${cubes}`;
		}
		case 'pallet':
			return `<rect x="180" y="815" width="640" height="26" rx="6" fill="#B07A3F"/><rect x="180" y="845" width="640" height="18" rx="6" fill="#8A5A2B"/><rect x="220" y="841" width="60" height="30" fill="#7A4E24"/><rect x="470" y="841" width="60" height="30" fill="#7A4E24"/><rect x="720" y="841" width="60" height="30" fill="#7A4E24"/>`;
		default:
			return '';
	}
}
const bilaoClip = '<clipPath id="bilaoClip"><ellipse cx="500" cy="765" rx="350" ry="82"/></clipPath>';

// Centre y that puts an illustration's bottom edge on the base surface.
const SURFACE = { pallet: 815, ice: 740, bilao: 775, none: 830 };
function seat(artName, s, dy = 0) {
	const b = BASES[artName] || 'none';
	return SURFACE[b] + dy - ((FLOOR[artName] || 800) - 500) * s;
}

// Light studio scene used for primary product images.
function studio(artName, color, tint, accent) {
	const b = BASES[artName] || 'none';
	const s = b === 'none' ? 0.86 : 0.8;
	const p = place(artName, color, 500, seat(artName, s), s);
	const defs = `${p.defs}${bilaoClip}
		<radialGradient id="bg" cx=".5" cy=".38" r=".8"><stop offset="0" stop-color="#FFFFFF"/><stop offset=".6" stop-color="${tint}"/><stop offset="1" stop-color="${shade(tint, -0.07)}"/></radialGradient>
		<radialGradient id="sh" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#101323" stop-opacity=".22"/><stop offset="1" stop-color="#101323" stop-opacity="0"/></radialGradient>`;
	return svg(1000, 1000, defs, `
		<rect width="1000" height="1000" fill="url(#bg)"/>
		<circle cx="500" cy="470" r="360" fill="none" stroke="${accent}" stroke-opacity=".18" stroke-width="2"/>
		<circle cx="500" cy="470" r="300" fill="#fff" fill-opacity=".45"/>
		<ellipse cx="500" cy="850" rx="340" ry="34" fill="url(#sh)"/>
		${base(b)}
		${p.body}`);
}

// Background "trading floor" chart: candlesticks + trend line.
function chart(w, h, color, opacity = 0.18) {
	let candles = '';
	let y = h * 0.62;
	const n = 26;
	for (let i = 0; i < n; i++) {
		const x = (w / n) * i + 18;
		const d = Math.sin(i * 1.7) * 28 + (i % 4 === 0 ? -34 : 12);
		const up = d < 0;
		const top = Math.min(y, y + d), hgt = Math.abs(d) + 10;
		candles += `<line x1="${x}" y1="${top - 18}" x2="${x}" y2="${top + hgt + 18}" stroke="${up ? '#22C55E' : '#EF4444'}" stroke-opacity="${opacity}" stroke-width="2"/><rect x="${x - 7}" y="${top}" width="14" height="${hgt}" fill="${up ? '#22C55E' : '#EF4444'}" fill-opacity="${opacity}"/>`;
		y = Math.max(h * 0.3, Math.min(h * 0.8, y + d * 0.6 - 4));
	}
	let line = '';
	for (let i = 0; i <= 40; i++) line += `${i === 0 ? 'M' : 'L'}${(w / 40) * i} ${h * 0.5 - Math.sin(i / 4) * h * 0.08 - i * h * 0.006}`;
	return `${candles}<path d="${line}" fill="none" stroke="${color}" stroke-opacity="${opacity * 2.2}" stroke-width="3"/>`;
}

// Dark "night market" scene used for gallery detail images.
function night(artName, color, accent) {
	const b = BASES[artName] || 'none';
	const p = place(artName, color, 540, seat(artName, 0.95, -10), 0.95, -3);
	const defs = `${p.defs}${bilaoClip}
		<radialGradient id="glow" cx=".55" cy=".45" r=".6"><stop offset="0" stop-color="${accent}" stop-opacity=".5"/><stop offset="1" stop-color="#0B1220" stop-opacity="0"/></radialGradient>
		<pattern id="grid" width="50" height="50" patternUnits="userSpaceOnUse"><path d="M50 0H0V50" fill="none" stroke="#fff" stroke-opacity=".05"/></pattern>`;
	return svg(1000, 1000, defs, `
		<rect width="1000" height="1000" fill="#0B1220"/><rect width="1000" height="1000" fill="url(#grid)"/>
		${chart(1000, 1000, '#22C55E', 0.16)}
		<rect width="1000" height="1000" fill="url(#glow)"/>
		<g transform="translate(40 -10)">${base(b)}</g>
		${p.body}`);
}

function category(c) {
	const b = BASES[c.art] || 'none';
	const col = (catalog.products.find((x) => x.art === c.art) || {}).color || c.color;
	// Base is drawn at scale .72 around (400,400); seat the item on that scaled surface.
	const p = place(c.art, col, 400, 400 + (seat(c.art, 1) - 500) * 0.72, 0.72);
	const defs = `${p.defs}${bilaoClip}<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${shade(c.tint, 0.4)}"/><stop offset="1" stop-color="${c.tint}"/></linearGradient>`;
	return svg(800, 800, defs, `<rect width="800" height="800" fill="url(#bg)"/>
		<circle cx="610" cy="170" r="210" fill="${c.color}" fill-opacity=".12"/>
		<circle cx="400" cy="420" r="280" fill="#fff" fill-opacity=".5"/>
		<g transform="translate(400 400) scale(.72) translate(-500 -500)">${base(b)}</g>${p.body}`);
}

function composition(w, h, items, { bg = '#0B1220', glowA = '#16A34A', glowB = '#F59E0B', light = false } = {}) {
	const placed = items.map(([a, col, x, y, s, r]) => place(a, col, x, y, s, r));
	const defs = placed.map((p) => p.defs).join('') + bilaoClip + `
		<radialGradient id="ga" cx=".72" cy=".35" r=".55"><stop offset="0" stop-color="${glowA}" stop-opacity="${light ? 0.3 : 0.55}"/><stop offset="1" stop-color="${glowA}" stop-opacity="0"/></radialGradient>
		<radialGradient id="gb" cx=".35" cy=".85" r=".5"><stop offset="0" stop-color="${glowB}" stop-opacity="${light ? 0.25 : 0.35}"/><stop offset="1" stop-color="${glowB}" stop-opacity="0"/></radialGradient>
		<pattern id="grid" width="64" height="64" patternUnits="userSpaceOnUse"><path d="M64 0H0V64" fill="none" stroke="${light ? '#0B1220' : '#fff'}" stroke-opacity=".05"/></pattern>`;
	return svg(w, h, defs, `<rect width="${w}" height="${h}" fill="${bg}"/><rect width="${w}" height="${h}" fill="url(#grid)"/>
		${chart(w, h, light ? '#15803D' : '#22C55E', light ? 0.1 : 0.16)}
		<rect width="${w}" height="${h}" fill="url(#ga)"/><rect width="${w}" height="${h}" fill="url(#gb)"/>
		${placed.map((p) => p.body).join('')}`);
}

const jobs = [];
for (const prod of catalog.products) {
	const c = cats[prod.category];
	jobs.push([`${prod.slug}.jpg`, studio(prod.art, prod.color, c.tint, c.color), 1000, 1000]);
	jobs.push([`${prod.slug}-detail.jpg`, night(prod.art, prod.color, c.color), 1000, 1000]);
}
for (const c of catalog.categories) jobs.push([`category-${c.slug}.jpg`, category(c), 800, 800]);

jobs.push(['hero-main.jpg', composition(1600, 1200, [
	['ricesack', '#D97706', 560, 560, 0.62, -4],
	['fish', '#94A3B8', 1060, 760, 0.62, -6],
	['cabbage', '#65A30D', 1300, 470, 0.42, 4],
	['tomato', '#DC2626', 820, 380, 0.36, 0],
	['eggtray', '#FBBF24', 1330, 930, 0.34, 6],
]), 1600, 1200]);
jobs.push(['campaign-banner.jpg', composition(1800, 900, [
	['ricesack', '#B91C1C', 1050, 460, 0.6, -6],
	['ricesack', '#D97706', 1320, 470, 0.6, 4],
	['sugarbag', '#E5E7EB', 1560, 520, 0.45, 6],
	['beanspouch', '#4D7C0F', 840, 620, 0.32, -8],
], { glowA: '#F59E0B', glowB: '#16A34A' }), 1800, 900]);
jobs.push(['cta-banner.jpg', composition(1800, 900, [
	['mango', '#F59E0B', 1150, 470, 0.5, -6],
	['leafy', '#22C55E', 1420, 430, 0.5, 4],
	['calamansi', '#65A30D', 1290, 680, 0.35, 0],
], { bg: '#F0FDF4', light: true, glowA: '#16A34A', glowB: '#F59E0B' }), 1800, 900]);
const posts = [
	['post-fresh-fish.jpg', [['fish', '#94A3B8', 850, 540, 0.75, -6], ['fishsmall', '#3B82F6', 1300, 560, 0.5, 4]], '#0369A1', '#22C55E'],
	['post-rice-varieties.jpg', [['ricesack', '#D97706', 700, 520, 0.55, -4], ['ricesack', '#15803D', 1000, 530, 0.55, 3], ['ricesack', '#B91C1C', 1300, 520, 0.55, -2]], '#F59E0B', '#16A34A'],
	['post-seasonal-vegetables.jpg', [['cabbage', '#65A30D', 750, 540, 0.55, 0], ['carrots', '#EA580C', 1050, 520, 0.5, 4], ['eggplant', '#6B21A8', 1340, 560, 0.5, -6]], '#16A34A', '#F59E0B'],
	['post-bulk-buying.jpg', [['ricesack', '#B91C1C', 800, 520, 0.6, -4], ['oilbottle', '#FACC15', 1130, 540, 0.48, 4], ['eggtray', '#FBBF24', 1400, 620, 0.4, 0]], '#F59E0B', '#22C55E'],
	['post-price-board.jpg', [['onion', '#9F1239', 900, 560, 0.55, 0], ['garlic', '#E7E5E4', 1250, 580, 0.5, 6]], '#22C55E', '#EF4444'],
];
for (const [file, items, a, b] of posts) jobs.push([file, composition(1600, 1000, items, { glowA: a, glowB: b }), 1600, 1000]);

const selected = only ? jobs.filter((j) => j[0].includes(only)) : jobs;
mkdirSync(tmpDir, { recursive: true });
mkdirSync(outDir, { recursive: true });
if (!only) {
	// Remove images that are no longer part of the catalog.
	const keep = new Set(jobs.map((j) => j[0]));
	for (const f of readdirSync(outDir)) if (f.endsWith('.jpg') && !keep.has(f)) unlinkSync(join(outDir, f));
}

async function render([file, markup, w, h]) {
	const base_ = file.replace(/\.jpg$/, '');
	const svgPath = join(tmpDir, `${base_}.svg`);
	const pngPath = join(tmpDir, `${base_}.png`);
	writeFileSync(svgPath, markup);
	for (let attempt = 0; attempt < 3; attempt++) {
		try {
			await run(CHROME, ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1', `--user-data-dir=${join(tmpDir, 'profile-' + base_)}`, `--window-size=${w},${h}`, `--screenshot=${pngPath}`, `file://${svgPath}`], { timeout: 60000 });
			break;
		} catch (e) {
			if (attempt === 2) throw e;
		}
	}
	rmSync(join(tmpDir, 'profile-' + base_), { recursive: true, force: true });
	await run('sips', ['-s', 'format', 'jpeg', '-s', 'formatOptions', '84', pngPath, '--out', join(outDir, file)]);
	if (!existsSync(join(outDir, file))) throw new Error(`Failed: ${file}`);
}

const queue = [...selected];
let done = 0;
await Promise.all(Array.from({ length: 6 }, async () => {
	while (queue.length) {
		const job = queue.shift();
		await render(job);
		done++;
		if (done % 10 === 0) console.log(`${done}/${selected.length}`);
	}
}));
console.log(`Rendered ${selected.length} images to ${outDir}`);
