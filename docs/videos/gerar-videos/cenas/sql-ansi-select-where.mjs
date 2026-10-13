// Vídeo 4 — SELECT e WHERE. Uma função por cena, na ordem de gerar-roteiros.py.
import { SQL, prepararEscola, escrever, executar, destacarResultado } from './_comum.mjs';

const anim = 'figure[x-data="whereAnimation"]';

async function consulta(h, sql, antesDeRodar) {
  await escrever(h, sql);
  if (antesDeRodar) await h.quandoDisser(antesDeRodar);
  await executar(h);
}

export default {
  preparo: prepararEscola,
  cenas: [
    async (h) => {
      await h.quandoDisser('Ele serve');
      await h.mover(SQL, { dx: 0.3 });
      await h.destacar(SQL, 3000);
      await h.quandoDisser('Vamos praticar');
      await h.destacar('#sql_schema', 2500);
    },
    async (h) => {
      await h.quandoDisser('SELECT asterisco');
      await consulta(h, 'SELECT * FROM aluno;', 'O asterisco');
      await h.quandoDisser('Aqui estão');
      await destacarResultado(h, 4500);
    },
    async (h) => {
      await h.rolar(SQL);
      await h.quandoDisser('no lugar do asterisco');
      await consulta(h, 'SELECT nome, cidade FROM aluno;');
      await h.quandoDisser('O resultado fica');
      await destacarResultado(h, 3500);
    },
    async (h) => {
      await h.rolar(SQL);
      await h.quandoDisser('É pra isso');
      await consulta(h, "SELECT nome, cidade FROM aluno\nWHERE cidade = 'Recife';", 'O banco testa');
      await h.quandoDisser('Aqui, a Ana');
      await destacarResultado(h, 3000);
      await h.quandoDisser('aspas simples');
      await h.rolar(SQL);
      await h.destacar(SQL, 2500);
    },
    async (h) => {
      await h.quandoDisser('quem não é de Recife');
      await consulta(h, "SELECT nome, cidade FROM aluno\nWHERE cidade <> 'Recife';", 'Vieram');
      await destacarResultado(h, 3000);
      await h.quandoDisser('cadê o Diego');
      await destacarResultado(h, 3000);
    },
    async (h) => {
      await h.ir('/guia/sql-ansi');
      await h.rolar(anim, 'start');
      await h.page.evaluate(() => scrollBy(0, -70));
      await h.clicar(`${anim} button:text-is("cidade <> 'Recife'")`);
      for (let i = 0; i < 4; i++) {
        await h.clicar(`${anim} button:has-text("▶ Próximo")`);
        await h.sleep(500);
      }
      await h.quandoDisser('A cidade do Diego');
      await h.destacar(`${anim} tr:has-text("Diego")`, 4000);
      await h.quandoDisser('Comparar NULL');
      await h.mover(`${anim} p[aria-live]`, { dx: 0.3 });
      await h.destacar(`${anim} p[aria-live]`, 5000);
      await h.quandoDisser('E o WHERE');
      await h.destacar(`${anim} tr:has-text("Diego")`, 3000);
    },
    async (h) => {
      await h.ir('/dashboard');
      await h.rolar(SQL);
      await h.quandoDisser('use IS NULL');
      await consulta(h, 'SELECT nome FROM aluno\nWHERE cidade IS NULL;', 'Agora sim');
      await destacarResultado(h, 3500);
    },
    async (h) => {
      await h.ir('/guia/sql-ansi');
      await h.quandoDisser('No guia');
      await h.rolar(anim, 'start');
      await h.page.evaluate(() => scrollBy(0, -70));
      await h.destacar(anim, 3000);
      await h.quandoDisser('vários exercícios');
      await h.rolar('h2:has-text("✍️ Exercícios") >> visible=true', 'start');
      await h.page.evaluate(() => scrollBy(0, -70));
      await h.quandoDisser('Experimente');
      await h.mover('h2:has-text("✍️ Exercícios") >> visible=true', { dx: 0.2 });
    },
  ],
};
