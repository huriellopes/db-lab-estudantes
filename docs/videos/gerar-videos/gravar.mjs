// Passo 2: grava a tela de um vídeo guiando o app de verdade (Playwright, headless).
//
//   node docs/videos/gerar-videos/gravar.mjs <arquivo>
//
// Cada cena de cenas/<arquivo>.mjs roda junto com a fala dela (saida/<arquivo>/cena-NN.json,
// escrita por narrar.py): a cena dura o que for maior, a fala ou as ações. Os quadros vêm do
// screencast do Chrome (JPEG de alta qualidade, 1920x1080) e vão para saida/<arquivo>/quadros/,
// com os tempos em saida/<arquivo>/gravacao.json, que montar.py transforma no .mp4.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const APP = process.env.APP_URL ?? 'http://localhost:8092';
const LOGIN = { usuario: 'aluno@dblab.local', senha: 'password123' }; // DevUsersSeeder (só APP_ENV=local)
const ANTES = 0.6; // respiro antes da fala de cada cena (s)
const DEPOIS = 2.0; // respiro depois da fala, pra tela "assentar" antes da próxima cena (s)

const arquivo = process.argv[2];
if (!arquivo) {
  console.error('uso: node gravar.mjs <arquivo>');
  process.exit(1);
}
const AQUI = path.dirname(new URL(import.meta.url).pathname);
const pasta = path.join(AQUI, '..', 'saida', arquivo);
const roteiro = (await import(path.join(AQUI, 'cenas', `${arquivo}.mjs`))).default;
const info = JSON.parse(fs.readFileSync(path.join(pasta, 'info.json'), 'utf8'));
const falas = roteiro.cenas.map((_, i) =>
  JSON.parse(fs.readFileSync(path.join(pasta, `cena-${String(i + 1).padStart(2, '0')}.json`), 'utf8')),
);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const agora = () => Date.now() / 1000;

// Cursor desenhado na página (o headless não mostra o do sistema): segue o mouse do
// Playwright, pulsa no clique e sobrevive a navegações porque entra como init script.
const CURSOR = `
(() => {
  const css = \`
    #__cur { position: fixed; z-index: 2147483647; left: 0; top: 0; width: 22px; height: 22px;
      pointer-events: none; transform: translate(-3px, -2px); transition: opacity .2s;
      filter: drop-shadow(0 1px 2px rgba(0,0,0,.35)); }
    .__pulso { position: fixed; z-index: 2147483646; width: 34px; height: 34px; margin: -17px 0 0 -17px;
      border-radius: 50%; pointer-events: none; background: rgba(99,102,241,.35);
      animation: __pulso .5s ease-out forwards; }
    @keyframes __pulso { from { transform: scale(.3); opacity: 1 } to { transform: scale(1.6); opacity: 0 } }
    .__realce { position: fixed; z-index: 2147483645; pointer-events: none; border-radius: 10px;
      box-shadow: 0 0 0 3px rgba(245,158,11,.95), 0 0 0 9px rgba(245,158,11,.25);
      transition: opacity .35s; opacity: 0; }
    html { scrollbar-width: none; } ::-webkit-scrollbar { display: none; }
  \`;
  const pronto = () => {
    if (document.getElementById('__cur')) return;
    const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);
    const c = document.createElement('div'); c.id = '__cur';
    c.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22"><path d="M3 2l7.5 19 2.6-7.9L21 10.5z" fill="#111827" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    const pos = JSON.parse(sessionStorage.getItem('__cur') || '[512,288]');
    c.style.left = pos[0] + 'px'; c.style.top = pos[1] + 'px';
    document.body.appendChild(c);
  };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', pronto) : pronto();
  addEventListener('mousemove', (e) => {
    const c = document.getElementById('__cur'); if (!c) return;
    c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px';
    sessionStorage.setItem('__cur', JSON.stringify([e.clientX, e.clientY]));
  }, true);
  addEventListener('mousedown', (e) => {
    const p = document.createElement('div'); p.className = '__pulso';
    p.style.left = e.clientX + 'px'; p.style.top = e.clientY + 'px';
    document.body.appendChild(p); setTimeout(() => p.remove(), 600);
  }, true);
})();
`;

// 1024x576 CSS renderizado a 1,875x = 1920x1080: o texto sai do tamanho de um zoom de 125%,
// legível até no celular.
const W = 1024, H = 576, ESCALA = 1920 / W;
const browser = await chromium.launch();
const context = await browser.newContext({
  viewport: { width: W, height: H },
  deviceScaleFactor: ESCALA,
  locale: 'pt-BR',
  colorScheme: 'light',
  reducedMotion: 'no-preference',
});
await context.addInitScript(CURSOR);
const page = await context.newPage();

// Mouse "humano": anda com aceleração e desaceleração em vez de pular.
let mouse = { x: W / 2, y: H / 2 };
async function moverPara(x, y, ms = 650) {
  const passos = Math.max(8, Math.round(ms / 16));
  const de = { ...mouse };
  for (let i = 1; i <= passos; i++) {
    const t = i / passos;
    const e = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2;
    await page.mouse.move(de.x + (x - de.x) * e, de.y + (y - de.y) * e);
    await sleep(ms / passos);
  }
  mouse = { x, y };
}

async function alvo(loc) {
  const l = typeof loc === 'string' ? page.locator(loc).first() : loc;
  await l.waitFor({ state: 'visible' });
  return l;
}

// Rola suavemente até o elemento ficar confortável na tela (só se precisar).
async function mostrar(loc, bloco = 'center') {
  const l = await alvo(loc);
  const precisa = await l.evaluate((el, bloco) => {
    const r = el.getBoundingClientRect();
    const fora = r.top < 80 || r.bottom > innerHeight - 40;
    if (fora) el.scrollIntoView({ behavior: 'smooth', block: bloco });
    return fora;
  }, bloco);
  if (precisa) await sleep(900);
  return l;
}

let cenaInicio = 0;
let falaAtual = null;

const h = {
  page,
  APP,
  sleep,
  async ir(url) {
    await page.goto(APP + url);
    await page.waitForLoadState('networkidle');
    await page.mouse.move(mouse.x, mouse.y);
  },
  // Move o mouse até um ponto exato (ex.: arrastar com o botão apertado).
  moverAte: (x, y, ms) => moverPara(x, y, ms),
  // Clica em algo que navega e espera a página nova carregar, com o cursor no mesmo lugar.
  async clicarENavegar(loc, url = '**', opts = {}) {
    await Promise.all([page.waitForURL(url), h.clicar(loc, opts)]);
    await page.waitForLoadState('networkidle');
    await page.mouse.move(mouse.x, mouse.y);
  },
  async mover(loc, { ms = 850, dx = 0.5, dy = 0.5 } = {}) {
    const l = await mostrar(loc);
    const b = await l.boundingBox();
    await moverPara(b.x + b.width * dx, b.y + b.height * dy, ms);
    return l;
  },
  async clicar(loc, opts = {}) {
    const l = await h.mover(loc, opts);
    await sleep(180);
    await page.mouse.down();
    await sleep(70);
    await page.mouse.up();
    await sleep(250);
    return l;
  },
  async digitar(loc, texto, { atraso = 70 } = {}) {
    if (loc) await h.clicar(loc);
    for (const ch of texto) {
      if (ch === '\n') await page.keyboard.press('Enter');
      else await page.keyboard.type(ch);
      await sleep(atraso + Math.random() * atraso * 0.6 + (' ,;'.includes(ch) ? atraso : 0));
    }
  },
  async rolar(loc, bloco = 'center') {
    const l = await alvo(loc);
    await l.evaluate((el, bloco) => el.scrollIntoView({ behavior: 'smooth', block: bloco }), bloco);
    await sleep(900);
  },
  // Anel âmbar em volta do elemento citado na fala, por `ms` milissegundos.
  async destacar(loc, ms = 2200) {
    const l = await mostrar(loc);
    await l.evaluate((el, ms) => {
      const r = el.getBoundingClientRect();
      const d = document.createElement('div');
      d.className = '__realce';
      // Absoluto na página (não fixo na tela): se a página rolar, o anel vai junto com o elemento.
      Object.assign(d.style, { position: 'absolute', left: r.left + scrollX - 6 + 'px', top: r.top + scrollY - 6 + 'px', width: r.width + 12 + 'px', height: r.height + 12 + 'px' });
      document.body.appendChild(d);
      requestAnimationFrame(() => (d.style.opacity = '1'));
      setTimeout(() => { d.style.opacity = '0'; setTimeout(() => d.remove(), 400); }, ms);
    }, ms);
  },
  // Espera até a narração chegar em `trecho` (texto exato da fala da cena atual).
  async quandoDisser(trecho) {
    const palavras = falaAtual.fala.split(/\s+/);
    const alvoP = trecho.split(/\s+/);
    const norm = (w) => w.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');
    const i = palavras.findIndex((_, k) => alvoP.every((w, j) => norm(palavras[k + j] ?? '') === norm(w)));
    if (i < 0) throw new Error(`"${trecho}" não está na fala: ${falaAtual.fala}`);
    const t = cenaInicio + ANTES + falaAtual.ini[i] - agora();
    if (t > 0) await sleep(t * 1000);
  },
};

// Login e preparo ficam fora da gravação.
await page.goto(APP + '/login');
await page.fill('#identifier', LOGIN.usuario);
await page.fill('input[type=password]', LOGIN.senha);
await Promise.all([page.waitForURL('**/dashboard'), page.click('button[type=submit]')]);
if (roteiro.preparo) await roteiro.preparo(h);
await page.waitForLoadState('networkidle');
await page.mouse.move(mouse.x, mouse.y);
await sleep(500);

// Gravação.
const dirQuadros = path.join(pasta, 'quadros');
fs.rmSync(dirQuadros, { recursive: true, force: true });
fs.mkdirSync(dirQuadros, { recursive: true });
const quadros = [];
const cdp = await context.newCDPSession(page);
cdp.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
  const nome = `${String(quadros.length).padStart(5, '0')}.jpg`;
  fs.writeFileSync(path.join(dirQuadros, nome), Buffer.from(data, 'base64'));
  quadros.push([nome, metadata.timestamp]);
  await cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {});
});
await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 95, maxWidth: 1920, maxHeight: 1080, everyNthFrame: 1 });
await sleep(400);
const inicio = agora();
const cenas = [];

// Vinheta: título por cima da primeira tela, que some quando a primeira fala começa.
await page.evaluate(({ titulo, numero, total }) => {
  const d = document.createElement('div');
  d.id = '__vinheta';
  d.innerHTML = `<div style="font:600 14px/1.2 system-ui,sans-serif;letter-spacing:.12em;text-transform:uppercase;color:#a5b4fc">DB Lab Estudantes · vídeo ${numero} de ${total}</div>
    <div style="font:700 40px/1.15 system-ui,sans-serif;color:#fff;margin-top:14px;max-width:820px">${titulo}</div>`;
  Object.assign(d.style, { position: 'fixed', inset: 0, zIndex: 2147483647, display: 'flex', flexDirection: 'column',
    alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: '40px',
    background: 'linear-gradient(135deg,#1e1b4b 0%,#4338ca 100%)', transition: 'opacity .6s ease' });
  document.body.appendChild(d);
}, info);
await sleep(2500);
await page.evaluate(() => { const d = document.getElementById('__vinheta'); d.style.opacity = '0'; setTimeout(() => d.remove(), 700); });
await sleep(300);

for (const [i, cena] of roteiro.cenas.entries()) {
  falaAtual = falas[i];
  cenaInicio = agora();
  const minimo = sleep((ANTES + falaAtual.duracao + DEPOIS) * 1000);
  await Promise.all([cena(h), minimo]).catch(async (e) => {
    await page.screenshot({ path: path.join(pasta, 'erro.png') });
    console.error(`cena ${i + 1} falhou (tela em saida/${arquivo}/erro.png): ${e.message}`);
    process.exit(1);
  });
  cenas.push({ ini: cenaInicio - inicio, fim: agora() - inicio, audio: cenaInicio - inicio + ANTES });
  console.log(`  cena ${i + 1}: ${(agora() - cenaInicio).toFixed(1)}s (fala ${falaAtual.duracao.toFixed(1)}s)`);
}
await sleep(1500);
const fim = agora();
await cdp.send('Page.stopScreencast');
await sleep(300);
await browser.close();

fs.writeFileSync(
  path.join(pasta, 'gravacao.json'),
  JSON.stringify({ inicio, duracao: fim - inicio, cenas, quadros: quadros.map(([n, t]) => [n, t - inicio]) }, null, 1),
);
console.log(`${arquivo}: ${(fim - inicio).toFixed(1)}s, ${quadros.length} quadros`);
