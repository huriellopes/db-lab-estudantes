// Vídeo 3 — Usando o console SQL. Uma função por cena, na ordem de gerar-roteiros.py.
import { SQL, EXECUTAR, RESUMO, criarSchema, codigoDoGuia, escrever, executar, esperarExecucao, destacarResultado } from './_comum.mjs';

const cartao = 'h2:text-is("Console SQL") >> xpath=..';
let loja = '';

export default {
  async preparo(h) {
    await criarSchema(h);
    loja = await codigoDoGuia(h, '/guia/mysql', 'Dados de exemplo — loja');
    await h.ir('/dashboard');
    await h.page.evaluate(() => localStorage.clear());
    await h.ir('/dashboard');
    await h.page.locator('h2:text-is("Console SQL")').evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await h.page.evaluate(() => scrollBy(0, -16));
  },
  cenas: [
    async (h) => {
      await h.destacar(cartao, 3000);
      await h.quandoDisser('Você escreve');
      await h.mover(SQL, { dx: 0.3 });
      await h.destacar(SQL, 2000);
      await h.quandoDisser('manda executar');
      await h.mover(EXECUTAR);
      await h.destacar(EXECUTAR, 1800);
    },
    async (h) => {
      await h.quandoDisser('no campo Schema');
      await h.clicar('#sql_schema', { dx: 0.3 });
      await h.page.keyboard.press('Escape');
      await h.page.selectOption('#sql_schema', { label: 'devaluno__escola' });
      await h.destacar('#sql_schema', 3500);
    },
    async (h) => {
      await h.quandoDisser('vou colar');
      await h.clicar(SQL, { dx: 0.3, dy: 0.3 });
      await h.page.fill(SQL, loja);
      await h.page.locator(SQL).evaluate((el) => (el.scrollTop = 0));
      await h.quandoDisser('o CREATE TABLE');
      await h.destacar(SQL, 3500);
      await h.quandoDisser('ponto e vírgula');
      await h.page.locator(SQL).evaluate((el) => el.scrollTo({ top: 260, behavior: 'smooth' }));
      await h.quandoDisser('Clique em Executar tudo');
      await executar(h);
      await h.mover(RESUMO, { dx: 0.2 });
      await h.destacar(RESUMO, 3000);
    },
    async (h) => {
      await h.quandoDisser('Numa linha nova');
      await h.clicar(SQL, { dx: 0.6, dy: 0.8 });
      await h.page.keyboard.press('Control+End');
      await h.digitar(null, '\n\nSELECT * FROM cliente;');
      await h.quandoDisser('Se eu selecionar');
      await h.page.locator(SQL).evaluate((el) => {
        const ini = el.value.lastIndexOf('SELECT');
        el.focus();
        el.setSelectionRange(ini, el.value.length);
        el.dispatchEvent(new Event('select'));
        el.scrollTop = el.scrollHeight;
      });
      await h.quandoDisser('olha o botão');
      await h.mover(EXECUTAR);
      await h.destacar(EXECUTAR, 3500);
      await h.quandoDisser('Ctrl mais Enter');
      await h.page.locator(SQL).focus();
      await h.page.keyboard.press('Control+Enter');
      await esperarExecucao(h);
    },
    async (h) => {
      await h.quandoDisser('O resultado aparece');
      await h.rolar(h.page.locator('[x-data^="sqlConsole"] table').last());
      await destacarResultado(h, 4000);
    },
    async (h) => {
      await h.rolar(SQL);
      await h.quandoDisser('Vou escrever clientes');
      await escrever(h, 'SELECT * FROM clientes;');
      await executar(h);
      await h.quandoDisser('O console mostra');
      await h.mover(RESUMO, { dx: 0.3 });
      await h.destacar(RESUMO, 4000);
      await h.quandoDisser('Corrijo o nome');
      await h.clicar(SQL, { dx: 0.3, dy: 0.2 });
      await h.page.keyboard.press('End');
      await h.page.keyboard.press('ArrowLeft');
      await h.page.keyboard.press('Backspace');
      await executar(h);
      await destacarResultado(h, 2000);
    },
    async (h) => {
      await h.rolar(SQL);
      await h.quandoDisser('Clique em Salvar comando');
      await h.clicar('button:has-text("Salvar comando")');
      await h.quandoDisser('dê um nome');
      await h.digitar('input[x-model="saveTitle"]', 'todos os clientes');
      await h.quandoDisser('confirme em Salvar');
      await h.clicar('button:has(span:text-is("Salvar"))');
      await h.quandoDisser('Ela fica na lista');
      await h.destacar('h3:text-is("Consultas salvas") >> xpath=..', 3000);
      await h.quandoDisser('um clique carrega');
      await h.page.click('[x-data^="sqlConsole"] button:has-text("Limpar")');
      await h.clicar('button:has(span:text-is("todos os clientes"))');
      await h.sleep(500);
      await h.destacar(SQL, 2500);
    },
    async (h) => {
      await h.quandoDisser('escolha o schema');
      await h.destacar('#sql_schema', 2000);
      await h.quandoDisser('escreva o comando');
      await h.destacar(SQL, 2000);
      await h.quandoDisser('execute tudo');
      await h.destacar(EXECUTAR, 2500);
    },
  ],
};
