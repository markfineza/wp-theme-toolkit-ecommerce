// NEXORA market artwork — original vector illustrations (CC0, created for this project).
// Each art function returns { defs, body } drawn in a 1000x1000 space centred on (500, 500),
// resting on a "floor" around y = 790. BASES lists the market surface drawn under each item.

export function shade(hex, amt) {
	const n = parseInt(hex.slice(1), 16);
	let r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
	const t = amt < 0 ? 0 : 255, p = Math.abs(amt);
	r = Math.round((t - r) * p + r); g = Math.round((t - g) * p + g); b = Math.round((t - b) * p + b);
	return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

let uid = 0;
const id = (p) => `${p}${++uid}`;

function art(build) {
	return (c) => {
		const defs = [];
		const G = (col, l = 0.3, d = -0.3, dir = 'diag') => {
			const g = id('g');
			const xy = dir === 'h' ? 'x1="0" y1="0" x2="1" y2="0"' : dir === 'v' ? 'x1="0" y1="0" x2="0" y2="1"' : 'x1="0" y1="0" x2="1" y2="1"';
			defs.push(`<linearGradient id="${g}" ${xy}><stop offset="0" stop-color="${shade(col, l)}"/><stop offset=".55" stop-color="${col}"/><stop offset="1" stop-color="${shade(col, d)}"/></linearGradient>`);
			return `url(#${g})`;
		};
		const R = (col, l = 0.45, d = -0.25) => {
			const g = id('r');
			defs.push(`<radialGradient id="${g}" cx=".35" cy=".3" r=".75"><stop offset="0" stop-color="${shade(col, l)}"/><stop offset=".6" stop-color="${col}"/><stop offset="1" stop-color="${shade(col, d)}"/></radialGradient>`);
			return `url(#${g})`;
		};
		const D = (s) => defs.push(s);
		return { defs: defs.join(''), body: build(c, G, R, D), get defsOut() { return defs.join(''); } };
	};
}

// Wrap so defs are captured after body is built.
function make(build) {
	const f = art(build);
	return (c) => { const r = f(c); return { defs: r.defsOut, body: r.body }; };
}

const hl = (d, o = 0.4, w = 8) => `<path d="${d}" fill="none" stroke="#fff" stroke-opacity="${o}" stroke-width="${w}" stroke-linecap="round"/>`;
const label = (x, y, w, h, fill, text, size = 56, color = '#fff') =>
	`<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="22" fill="${fill}"/><text x="${x + w / 2}" y="${y + h / 2 + size * 0.36}" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-weight="800" font-size="${size}" letter-spacing="4" fill="${color}">${text}</text>`;

export const BASES = {
	ricesack: 'pallet', beanspouch: 'pallet', sugarbag: 'pallet', saltpack: 'pallet', oilbottle: 'pallet', soybottle: 'pallet', vinegarbottle: 'pallet',
	cabbage: 'bilao', tomato: 'bilao', eggplant: 'bilao', onion: 'bilao', garlic: 'bilao', ampalaya: 'bilao', leafy: 'bilao', carrots: 'bilao',
	banana: 'bilao', mango: 'bilao', calamansi: 'bilao', driedfish: 'bilao', driedsquid: 'bilao',
	porkbelly: 'ice', meatcut: 'ice', groundmeat: 'none', chicken: 'ice', fillet: 'none', eggtray: 'none',
	fish: 'ice', fishsmall: 'ice', shrimp: 'ice', squid: 'ice',
};

export const ART = {
	ricesack: make((c, G) => `
		<path d="M300 320 C 280 260, 330 235, 370 245 L 630 245 C 670 235, 720 260, 700 320 L 735 755 C 742 805, 705 825, 660 825 L 340 825 C 295 825, 258 805, 265 755 Z" fill="${G('#E9D8B4', 0.25, -0.2)}"/>
		<path d="M300 320 C 280 260, 330 235, 370 245 L 630 245 C 670 235, 720 260, 700 320 L 735 755 C 742 805, 705 825, 660 825 L 340 825 C 295 825, 258 805, 265 755 Z" fill="none" stroke="#B89B6A" stroke-width="5" stroke-dasharray="2 10"/>
		<path d="M370 245 C 420 205, 580 205, 630 245" fill="#DCC69B"/>
		<path d="M420 225 C 460 160, 540 160, 580 225" fill="none" stroke="#8A6D3B" stroke-width="12" stroke-linecap="round"/>
		${label(330, 430, 340, 230, G(c, 0.25, -0.25), 'RICE', 72)}
		<rect x="380" y="610" width="240" height="10" rx="5" fill="#fff" fill-opacity=".55"/>
		<path d="M330 700 L 670 700" stroke="${shade(c, -0.2)}" stroke-width="10"/>
		${hl('M310 340 C 300 450, 300 600, 300 720', 0.35, 12)}`),

	beanspouch: make((c, G, R, D) => {
		const p = id('p');
		D(`<pattern id="${p}" width="34" height="30" patternUnits="userSpaceOnUse"><ellipse cx="9" cy="8" rx="8" ry="6" fill="${c}"/><ellipse cx="26" cy="22" rx="8" ry="6" fill="${shade(c, -0.2)}"/></pattern>`);
		return `
		<path d="M330 260 L 670 260 L 700 780 C 700 810, 680 825, 650 825 L 350 825 C 320 825, 300 810, 300 780 Z" fill="#F7F3E8"/>
		<path d="M332 420 L 668 420 L 698 780 C 698 808, 680 822, 650 822 L 350 822 C 320 822, 302 808, 302 780 Z" fill="url(#${p})"/>
		<rect x="320" y="230" width="360" height="50" rx="10" fill="${G(c, 0.2, -0.3, 'v')}"/>
		${label(370, 310, 260, 90, shade(c, -0.25), 'MONGGO', 40)}
		${hl('M340 300 L 320 760', 0.6, 10)}`;
	}),

	cabbage: make((c, G, R) => `
		<circle cx="500" cy="560" r="230" fill="${R(c, 0.35, -0.3)}"/>
		<path d="M290 540 C 300 380, 420 300, 520 320 C 420 360, 360 440, 350 560" fill="${shade(c, 0.25)}"/>
		<path d="M720 560 C 720 420, 620 330, 520 320 C 610 380, 650 470, 650 600" fill="${shade(c, 0.15)}"/>
		<path d="M500 760 C 470 640, 470 480, 520 330 M500 760 C 560 650, 620 560, 700 520 M500 760 C 430 660, 360 600, 300 590" fill="none" stroke="${shade(c, 0.55)}" stroke-width="10" stroke-linecap="round"/>
		<circle cx="520" cy="440" r="70" fill="${shade(c, 0.45)}" fill-opacity=".6"/>`),

	tomato: make((c, G, R) => {
		const one = (x, y, r) => `<circle cx="${x}" cy="${y}" r="${r}" fill="${R(c, 0.4, -0.3)}"/><path d="M${x - 35} ${y - r + 18} l 35 18 l 35 -18 l -12 30 l -23 -8 l -23 8 z" fill="#15803D"/><ellipse cx="${x - r * 0.4}" cy="${y - r * 0.35}" rx="${r * 0.22}" ry="${r * 0.12}" fill="#fff" fill-opacity=".45" transform="rotate(-30 ${x - r * 0.4} ${y - r * 0.35})"/>`;
		return one(380, 600, 150) + one(640, 610, 140) + one(510, 460, 135);
	}),

	eggplant: make((c, G) => {
		const one = (rot, dx) => `<g transform="translate(${dx} 0) rotate(${rot} 500 520)"><path d="M420 330 C 350 360, 330 520, 380 680 C 420 790, 560 800, 590 690 C 620 560, 560 420, 520 340 Z" fill="${G(c, 0.3, -0.35)}"/><path d="M420 330 C 450 290, 510 290, 530 330 L 500 370 Z" fill="#3F6212"/><rect x="465" y="250" width="22" height="70" rx="10" fill="#4D7C0F"/>${hl('M410 420 C 390 500, 395 600, 420 680', 0.4, 10)}</g>`;
		return one(-14, -90) + one(12, 90);
	}),

	onion: make((c, G, R) => {
		const one = (x, y, s) => `<g transform="translate(${x} ${y}) scale(${s})"><path d="M0 -150 C 30 -100, 140 -60, 140 40 C 140 130, 70 170, 0 170 C -70 170, -140 130, -140 40 C -140 -60, -30 -100, 0 -150 Z" fill="${R(c, 0.35, -0.3)}"/><path d="M0 -150 C -40 -60, -60 60, 0 170 M0 -150 C 40 -60, 60 60, 0 170" fill="none" stroke="#fff" stroke-opacity=".25" stroke-width="6"/><path d="M-20 168 l -15 22 M0 170 l 0 25 M20 168 l 15 22" stroke="#D6C7B0" stroke-width="5"/><path d="M0 -150 l -6 -30 l 12 0 z" fill="#C4A57A"/></g>`;
		return one(370, 600, 0.95) + one(630, 600, 0.9) + one(500, 470, 0.85);
	}),

	garlic: make((c, G, R) => {
		const one = (x, y, s) => `<g transform="translate(${x} ${y}) scale(${s})"><path d="M0 -150 C 30 -90, 160 -60, 160 50 C 160 140, 80 175, 0 175 C -80 175, -160 140, -160 50 C -160 -60, -30 -90, 0 -150 Z" fill="${R('#F5F1EA', 0.2, -0.12)}"/><path d="M0 -140 C -50 -40, -70 80, -40 170 M0 -140 C 50 -40, 70 80, 40 170 M0 -140 C -100 -20, -130 80, -110 140 M0 -140 C 100 -20, 130 80, 110 140" fill="none" stroke="#C8B9C9" stroke-width="5"/><path d="M-10 -150 l 0 -40 l 20 0 l 0 40 z" fill="#D6C9B4"/></g>`;
		return one(400, 560, 1) + one(620, 610, 0.85);
	}),

	ampalaya: make((c, G) => {
		const one = (rot, dx) => {
			let bumps = '';
			for (let i = 0; i < 9; i++) bumps += `<ellipse cx="${470 + (i % 2) * 40}" cy="${330 + i * 50}" rx="18" ry="12" fill="${shade(c, 0.35)}" fill-opacity=".7"/>`;
			return `<g transform="translate(${dx} 0) rotate(${rot} 500 520)"><path d="M490 260 C 420 300, 410 500, 430 680 C 445 790, 560 790, 570 680 C 590 500, 580 300, 510 260 Z" fill="${G(c, 0.25, -0.35)}"/>${bumps}<rect x="490" y="220" width="18" height="50" rx="8" fill="#365314"/></g>`;
		};
		return one(-12, -100) + one(10, 100);
	}),

	leafy: make((c, G) => {
		let leaves = '';
		[-40, -20, 0, 20, 40].forEach((r, i) => {
			leaves += `<g transform="rotate(${r} 500 760)"><path d="M500 760 C 430 600, 400 420, 500 230 C 600 420, 570 600, 500 760 Z" fill="${G(i % 2 ? c : shade(c, -0.15), 0.25, -0.3)}"/><path d="M500 740 L 500 280" stroke="#F0FDF4" stroke-width="16" stroke-linecap="round"/></g>`;
		});
		return leaves + `<rect x="455" y="650" width="90" height="34" rx="10" fill="#A16207"/>`;
	}),

	carrots: make((c, G) => {
		const one = (rot, dx) => `<g transform="translate(${dx} 0) rotate(${rot} 500 500)"><path d="M440 330 L 560 330 C 560 450, 520 650, 500 790 C 480 650, 440 450, 440 330 Z" fill="${G(c, 0.25, -0.3, 'h')}"/>${[400, 470, 540, 610].map((y) => `<path d="M${455 + (y - 330) / 9} ${y} l 30 0" stroke="${shade(c, -0.3)}" stroke-width="5" stroke-linecap="round"/>`).join('')}<path d="M500 330 C 470 260, 430 230, 410 200 M500 330 C 500 250, 500 210, 500 180 M500 330 C 530 260, 570 230, 590 200" stroke="#16A34A" stroke-width="16" stroke-linecap="round" fill="none"/></g>`;
		return one(-20, -110) + one(0, 0) + one(20, 110);
	}),

	banana: make((c, G) => {
		let f = '';
		[[-30, 0], [-12, 30], [6, 55], [24, 75]].forEach(([r, dy]) => {
			f += `<g transform="rotate(${r} 360 420)"><path d="M360 420 C 470 400, 640 470, 700 640 C 640 560, 480 500, 360 460 Z" fill="${G(c, 0.3, -0.25)}" transform="translate(0 ${dy})"/></g>`;
		});
		return `<rect x="300" y="380" width="80" height="60" rx="14" fill="#65A30D"/><rect x="250" y="390" width="70" height="30" rx="10" fill="#4D7C0F"/>${f}`;
	}),

	mango: make((c, G, R) => {
		const one = (x, y, rot) => `<g transform="translate(${x} ${y}) rotate(${rot})"><path d="M-40 -170 C 90 -180, 170 -60, 150 60 C 130 170, 20 200, -60 160 C -140 120, -150 30, -110 -40 C -80 -90, -110 -160, -40 -170 Z" fill="${R(c, 0.4, -0.25)}"/><ellipse cx="10" cy="-90" rx="55" ry="30" fill="#fff" fill-opacity=".35" transform="rotate(-25)"/><path d="M-40 -170 l -10 -25" stroke="#4D7C0F" stroke-width="10" stroke-linecap="round"/></g>`;
		return one(400, 560, -20) + one(620, 580, 25);
	}),

	calamansi: make((c, G, R) => {
		const pos = [[380, 640], [470, 660], [560, 650], [650, 630], [420, 560], [520, 570], [610, 560], [470, 480], [570, 490]];
		let s = '';
		pos.forEach(([x, y]) => { s += `<circle cx="${x}" cy="${y}" r="56" fill="${R(c, 0.35, -0.3)}"/><circle cx="${x - 16}" cy="${y - 18}" r="12" fill="#fff" fill-opacity=".4"/>`; });
		s += `<circle cx="690" cy="520" r="62" fill="#ECFCCB"/><circle cx="690" cy="520" r="52" fill="#FDBA74"/>${[0, 60, 120, 180, 240, 300].map((a) => `<path d="M690 520 L ${690 + 50 * Math.cos(a * Math.PI / 180)} ${520 + 50 * Math.sin(a * Math.PI / 180)}" stroke="#FFF7ED" stroke-width="5"/>`).join('')}`;
		return s;
	}),

	porkbelly: make((c, G) => `
		<g transform="rotate(-8 500 560)">
		<path d="M230 420 L 760 420 L 800 520 L 270 520 Z" fill="#F3D9C3"/>
		<rect x="270" y="520" width="530" height="40" fill="#C2410C" fill-opacity=".85"/>
		<rect x="270" y="560" width="530" height="40" fill="#FFF1E6"/>
		<rect x="270" y="600" width="530" height="55" fill="${G('#E26D6D', 0.15, -0.2, 'h')}"/>
		<rect x="270" y="655" width="530" height="30" fill="#FFF1E6"/>
		<rect x="270" y="685" width="530" height="60" fill="${G('#D9534F', 0.15, -0.25, 'h')}"/>
		<path d="M760 420 L 800 520 L 800 745 L 760 640 Z" fill="#E7B9A5"/>
		${hl('M290 470 L 700 450', 0.5, 8)}</g>`),

	meatcut: make((c, G) => `
		<path d="M260 560 C 240 420, 380 330, 520 340 C 680 350, 780 440, 760 570 C 740 700, 590 760, 450 740 C 320 720, 275 660, 260 560 Z" fill="#FFF1E6"/>
		<path d="M290 560 C 280 440, 390 370, 520 375 C 660 382, 735 460, 720 570 C 705 680, 585 725, 455 710 C 340 695, 300 645, 290 560 Z" fill="${G(c, 0.2, -0.3)}"/>
		<path d="M360 470 C 420 500, 470 480, 520 520 C 570 560, 620 540, 680 560 M380 600 C 440 620, 500 600, 560 640 M450 420 C 480 450, 520 440, 560 470" fill="none" stroke="#FFE4E6" stroke-opacity=".8" stroke-width="7" stroke-linecap="round"/>`),

	groundmeat: make((c, G, R, D) => {
		const p = id('m');
		D(`<pattern id="${p}" width="22" height="18" patternUnits="userSpaceOnUse"><path d="M2 9 q 5 -7 10 0 t 10 0" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="3"/></pattern>`);
		return `<rect x="220" y="560" width="560" height="220" rx="40" fill="#F8FAFC"/><rect x="240" y="575" width="520" height="190" rx="30" fill="#E2E8F0"/>
		<path d="M270 640 C 300 470, 420 420, 520 430 C 640 440, 720 500, 730 640 C 700 690, 320 700, 270 640 Z" fill="${R(c, 0.2, -0.3)}"/>
		<path d="M270 640 C 300 470, 420 420, 520 430 C 640 440, 720 500, 730 640 C 700 690, 320 700, 270 640 Z" fill="url(#${p})"/>
		<rect x="230" y="560" width="540" height="40" rx="18" fill="#fff" fill-opacity=".5"/>`;
	}),

	chicken: make((c, G, R) => `
		<ellipse cx="510" cy="560" rx="230" ry="170" fill="${R('#F8D9B6', 0.3, -0.18)}"/>
		<path d="M300 560 C 230 520, 210 600, 250 640 C 280 670, 330 650, 340 620" fill="${R('#F3CFA8', 0.25, -0.2)}"/>
		<path d="M700 560 C 790 520, 810 600, 770 650 C 740 680, 690 660, 680 620" fill="${R('#F3CFA8', 0.25, -0.2)}"/>
		<circle cx="245" cy="655" r="22" fill="#FDF2E9"/><circle cx="775" cy="665" r="22" fill="#FDF2E9"/>
		<path d="M380 470 C 450 420, 580 420, 640 470" fill="none" stroke="#fff" stroke-opacity=".5" stroke-width="10" stroke-linecap="round"/>
		<path d="M510 400 L 510 700" stroke="#E7BE96" stroke-width="5" stroke-opacity=".7"/>`),

	fillet: make((c, G, R) => `
		<rect x="200" y="560" width="600" height="220" rx="40" fill="#F8FAFC"/><rect x="220" y="575" width="560" height="190" rx="30" fill="#E2E8F0"/>
		<path d="M260 650 C 270 520, 380 470, 470 500 C 520 520, 500 640, 440 690 C 370 740, 270 720, 260 650 Z" fill="${R(c, 0.25, -0.2)}"/>
		<path d="M520 640 C 540 510, 650 470, 730 510 C 780 540, 750 650, 690 690 C 620 730, 510 710, 520 640 Z" fill="${R(c, 0.25, -0.2)}"/>
		${hl('M300 600 C 330 550, 380 530, 430 530', 0.5, 8)}${hl('M560 590 C 590 545, 640 530, 690 535', 0.5, 8)}`),

	eggtray: make((c, G, R) => {
		let eggs = '';
		for (let r = 0; r < 3; r++) for (let i = 0; i < 5; i++) {
			const x = 300 + i * 100 + (r % 2) * 10, y = 450 + r * 110;
			eggs += `<circle cx="${x}" cy="${y + 25}" r="46" fill="#B9AE9A"/><ellipse cx="${x}" cy="${y}" rx="40" ry="50" fill="${R(c === '#FBBF24' ? '#E9C8A0' : c, 0.35, -0.2)}"/><ellipse cx="${x - 12}" cy="${y - 20}" rx="10" ry="14" fill="#fff" fill-opacity=".5"/>`;
		}
		return `<path d="M220 420 L 780 420 L 820 780 L 180 780 Z" fill="${G('#CFC6B4', 0.2, -0.2, 'v')}"/>${eggs}`;
	}),

	fish: make((c, G) => `
		<path d="M200 520 C 300 380, 560 360, 730 470 L 820 380 C 830 470, 830 570, 820 660 L 730 570 C 560 680, 300 660, 200 520 Z" fill="${G(c, 0.35, -0.35, 'v')}"/>
		<path d="M220 520 C 360 540, 560 540, 730 520" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="6"/>
		${[0, 1, 2, 3, 4, 5].map((i) => `<path d="M${380 + i * 55} 450 q 25 40 0 80" fill="none" stroke="${shade(c, -0.3)}" stroke-opacity=".5" stroke-width="5"/>`).join('')}
		<path d="M480 420 C 520 360, 590 360, 620 430 Z" fill="${shade(c, -0.25)}"/>
		<circle cx="270" cy="500" r="18" fill="#F8FAFC"/><circle cx="270" cy="500" r="9" fill="#0F172A"/>
		<path d="M320 470 C 340 500, 340 550, 320 580" fill="none" stroke="${shade(c, -0.35)}" stroke-width="6"/>`),

	fishsmall: make((c, G) => {
		const one = (x, y, rot) => `<g transform="translate(${x} ${y}) rotate(${rot}) scale(.55)"><path d="M-300 0 C -200 -140, 60 -160, 230 -50 L 320 -140 C 330 -50, 330 50, 320 140 L 230 50 C 60 160, -200 140, -300 0 Z" fill="${G(c, 0.35, -0.4, 'v')}"/><circle cx="-230" cy="-20" r="20" fill="#F8FAFC"/><circle cx="-230" cy="-20" r="10" fill="#0F172A"/><path d="M-280 0 C -100 20, 60 20, 230 0" stroke="#FDE047" stroke-width="10" fill="none" stroke-opacity=".7"/></g>`;
		return one(500, 450, -8) + one(470, 580, 6) + one(540, 700, -4);
	}),

	shrimp: make((c, G) => {
		const one = (x, y, rot) => `<g transform="translate(${x} ${y}) rotate(${rot})"><path d="M-120 -40 C -60 -140, 100 -130, 130 -10 C 150 70, 80 130, 10 110 C 60 80, 80 20, 50 -30 C 20 -70, -60 -70, -90 -20 Z" fill="${G(c, 0.3, -0.25)}"/>${[0, 1, 2, 3].map((i) => `<path d="M${-60 + i * 45} ${-85 + Math.abs(i - 1.5) * 18} q 20 30 10 60" fill="none" stroke="${shade(c, -0.3)}" stroke-width="6"/>`).join('')}<path d="M-120 -40 C -170 -90, -210 -120, -260 -130 M-110 -30 C -170 -60, -220 -60, -270 -50" stroke="${shade(c, -0.25)}" stroke-width="5" fill="none"/><circle cx="-95" cy="-55" r="8" fill="#1F2937"/><path d="M10 110 l -30 40 l 50 -10 z" fill="${shade(c, -0.2)}"/></g>`;
		return one(460, 470, -10) + one(600, 600, 15) + one(400, 650, 5);
	}),

	squid: make((c, G) => `
		<g transform="rotate(-25 500 500)">
		<path d="M500 200 C 590 260, 600 420, 590 560 L 410 560 C 400 420, 410 260, 500 200 Z" fill="${G(c, 0.3, -0.3, 'h')}"/>
		<path d="M500 210 L 640 330 L 590 360 Z M500 210 L 360 330 L 410 360 Z" fill="${shade(c, -0.1)}"/>
		${[0, 1, 2, 3, 4, 5].map((i) => `<path d="M${420 + i * 32} 560 C ${410 + i * 34} 650, ${440 + i * 30} 720, ${420 + i * 34} 800" fill="none" stroke="${shade(c, -0.15)}" stroke-width="16" stroke-linecap="round"/>`).join('')}
		<circle cx="455" cy="530" r="16" fill="#1F2937"/><circle cx="545" cy="530" r="16" fill="#1F2937"/>
		${[0, 1, 2, 3, 4].map((i) => `<circle cx="${470 + (i % 2) * 50}" cy="${300 + i * 45}" r="9" fill="#C084FC" fill-opacity=".6"/>`).join('')}</g>`),

	sugarbag: make((c, G) => `
		<path d="M330 280 L 670 280 L 700 790 C 700 812, 685 825, 660 825 L 340 825 C 315 825, 300 812, 300 790 Z" fill="${G('#FFFFFF', 0.1, -0.12, 'h')}"/>
		<path d="M330 280 L 360 240 L 640 240 L 670 280 Z" fill="#E5E7EB"/>
		${label(345, 420, 310, 150, '#2563EB', 'SUGAR', 56)}
		<rect x="380" y="610" width="240" height="12" rx="6" fill="#CBD5E1"/><rect x="420" y="640" width="160" height="10" rx="5" fill="#CBD5E1"/>
		${hl('M340 320 L 320 760', 0.7, 10)}`),

	saltpack: make((c, G) => `
		<rect x="350" y="300" width="300" height="500" rx="20" fill="${G(c, 0.25, -0.3, 'h')}"/>
		<path d="M350 330 L 500 260 L 650 330 Z" fill="${shade(c, -0.25)}"/>
		${label(380, 440, 240, 130, '#FFFFFF', 'SALT', 54, c)}
		<rect x="390" y="620" width="220" height="12" rx="6" fill="#fff" fill-opacity=".5"/>
		${hl('M375 340 L 375 760', 0.35, 10)}`),

	oilbottle: make((c, G) => `
		<rect x="455" y="180" width="90" height="60" rx="14" fill="#16A34A"/>
		<path d="M470 240 L 530 240 L 540 300 C 640 330, 660 400, 660 470 L 660 760 C 660 805, 630 825, 590 825 L 410 825 C 370 825, 340 805, 340 760 L 340 470 C 340 400, 360 330, 460 300 Z" fill="${G(c, 0.35, -0.2, 'h')}" fill-opacity=".92"/>
		${label(370, 520, 260, 150, '#14532D', 'OIL', 56)}
		${hl('M370 420 L 370 780', 0.5, 12)}`),

	soybottle: make((c, G) => `
		<rect x="465" y="170" width="70" height="60" rx="12" fill="#DC2626"/>
		<path d="M475 230 L 525 230 L 530 330 C 600 360, 620 420, 620 480 L 620 780 C 620 810, 600 825, 570 825 L 430 825 C 400 825, 380 810, 380 780 L 380 480 C 380 420, 400 360, 470 330 Z" fill="${G(c, 0.25, -0.2, 'h')}"/>
		${label(400, 540, 200, 150, '#FEF3C7', 'SOY', 46, '#7C2D12')}
		${hl('M405 470 L 405 780', 0.3, 10)}`),

	vinegarbottle: make((c, G) => `
		<rect x="465" y="170" width="70" height="60" rx="12" fill="#1D4ED8"/>
		<path d="M475 230 L 525 230 L 530 330 C 600 360, 620 420, 620 480 L 620 780 C 620 810, 600 825, 570 825 L 430 825 C 400 825, 380 810, 380 780 L 380 480 C 380 420, 400 360, 470 330 Z" fill="${G('#FEF9C3', 0.3, -0.12, 'h')}" fill-opacity=".85"/>
		<path d="M382 470 L 618 470 L 618 780 C 618 808, 600 822, 570 822 L 430 822 C 400 822, 382 808, 382 780 Z" fill="${c}" fill-opacity=".7"/>
		${label(400, 540, 200, 150, '#1D4ED8', 'SUKA', 44)}
		${hl('M405 470 L 405 780', 0.6, 10)}`),

	driedfish: make((c, G) => {
		const one = (x, y, rot) => `<g transform="translate(${x} ${y}) rotate(${rot}) scale(.62)"><path d="M-300 0 C -200 -120, 60 -130, 230 -40 L 320 -120 C 330 -40, 330 40, 320 120 L 230 40 C 60 130, -200 120, -300 0 Z" fill="${G(c, 0.3, -0.3)}"/><path d="M-280 0 L 230 0" stroke="${shade(c, -0.35)}" stroke-width="8"/><circle cx="-230" cy="-15" r="14" fill="#F5F5F4"/>${[0, 1, 2, 3, 4].map((i) => `<path d="M${-150 + i * 70} -60 l 30 60 l -30 60" fill="none" stroke="${shade(c, -0.3)}" stroke-width="5"/>`).join('')}</g>`;
		return one(500, 460, -18) + one(480, 560, -10) + one(520, 660, -2);
	}),

	driedsquid: make((c, G) => `
		<g transform="rotate(-15 500 520)">
		<path d="M500 230 C 640 260, 680 420, 650 560 L 350 560 C 320 420, 360 260, 500 230 Z" fill="${G(c, 0.25, -0.25)}"/>
		${[0, 1, 2, 3, 4, 5, 6].map((i) => `<path d="M${370 + i * 42} 560 L ${360 + i * 44} 800" stroke="${shade(c, -0.15)}" stroke-width="18" stroke-linecap="round"/>`).join('')}
		<path d="M420 330 C 470 360, 530 360, 580 330" fill="none" stroke="${shade(c, -0.25)}" stroke-width="6"/>
		${hl('M380 420 C 380 360, 410 300, 450 280', 0.4, 8)}</g>`),
};

// Approximate bottom edge (in the 1000-unit art space) of each illustration, used to seat it on its base.
export const FLOOR = {
	ricesack: 825, beanspouch: 825, sugarbag: 825, saltpack: 800, oilbottle: 825, soybottle: 825, vinegarbottle: 825,
	cabbage: 790, tomato: 750, eggplant: 790, onion: 765, garlic: 785, ampalaya: 780, leafy: 760, carrots: 790,
	banana: 690, mango: 740, calamansi: 716, driedfish: 760, driedsquid: 800,
	porkbelly: 750, meatcut: 745, chicken: 730, fish: 650, fishsmall: 760, shrimp: 770, squid: 790,
	groundmeat: 780, fillet: 780, eggtray: 780,
};
