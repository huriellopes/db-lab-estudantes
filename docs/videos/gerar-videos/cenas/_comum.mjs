// Peças comuns das cenas que usam o console SQL do painel.
export const SQL = 'textarea[x-ref="sqlInput"]';
export const EXECUTAR = '[x-data^="sqlConsole"] button.btn-primary:has-text("Executar")';
export const RESUMO = '[x-text="summary.message"]';
export const RESULTADO = '[x-data^="sqlConsole"] table';

// --- Preparo (fora da gravação: sem cursor animado nem pausas) ---

export async function criarSchema(h, nome = 'escola') {
  await h.ir('/dashboard');
  await h.page.fill('#label', nome);
  await Promise.all([h.page.waitForURL('**/dashboard'), h.page.click('form[action="/schemas"] button[type=submit]')]);
  await h.page.waitForLoadState('networkidle');
}

/** Texto de um bloco de código do guia, pelo título da figura (ex.: "Dados de exemplo — loja"). */
export async function codigoDoGuia(h, url, titulo) {
  await h.ir(url);
  return h.page.locator(`figure:has-text("${titulo}") code`).first().innerText();
}

/** Roda um script no console de uma vez e deixa o console limpo. */
export async function rodarEmSilencio(h, sql) {
  await h.ir('/dashboard');
  await h.page.fill(SQL, sql);
  await h.page.click(EXECUTAR);
  await esperarExecucao(h);
  await h.page.click('[x-data^="sqlConsole"] button:has-text("Limpar")');
  await h.page.evaluate(() => localStorage.clear());
}

/** Schema "escola" com os dados de exemplo do guia de SQL ANSI (curso, aluno, matricula). */
export async function prepararEscola(h) {
  await criarSchema(h);
  await rodarEmSilencio(h, await codigoDoGuia(h, '/guia/sql-ansi', 'Dados de exemplo — escola'));
  await h.ir('/dashboard');
  await h.page.locator('h2:text-is("Console SQL")').evaluate((el) => el.scrollIntoView({ block: 'start' }));
  await h.page.evaluate(() => scrollBy(0, -16));
}

export async function esperarExecucao(h) {
  await h.sleep(250);
  await h.page.locator('text=Executando…').waitFor({ state: 'hidden' });
  await h.sleep(300);
}

// --- Durante a gravação ---

/** Apaga o que tem no console e digita `sql`, como uma pessoa. */
export async function escrever(h, sql, opts) {
  await h.clicar(SQL, { dx: 0.3, dy: 0.3 });
  await h.page.keyboard.press('Control+A');
  await h.page.keyboard.press('Backspace');
  await h.digitar(null, sql, opts);
}

export async function executar(h) {
  await h.clicar(EXECUTAR);
  await esperarExecucao(h);
}

/** Destaca a tabela do último resultado (rola até ela se precisar). */
export async function destacarResultado(h, ms = 3000) {
  await h.destacar(h.page.locator(RESULTADO).last(), ms);
}
