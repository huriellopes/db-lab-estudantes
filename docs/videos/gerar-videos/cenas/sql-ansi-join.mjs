// Vídeo 5 — JOIN na prática. Uma função por cena, na ordem de gerar-roteiros.py.
import { SQL, prepararEscola, escrever, executar, destacarResultado } from './_comum.mjs';

const anim = 'figure[x-data="joinAnimation"]';

async function abrirAnimacao(h) {
  await h.ir('/guia/sql-ansi');
  await h.clicar('button[role=tab]:has-text("Intermediário")');
  await h.rolar(anim, 'start');
  await h.page.evaluate(() => scrollBy(0, -70));
}

async function avancarAteDiego(h) {
  for (let i = 0; i < 4; i++) {
    await h.clicar(`${anim} button:has-text("▶ Próximo")`);
    await h.sleep(450);
  }
}

export default {
  preparo: prepararEscola,
  cenas: [
    async (h) => {
      await escrever(h, 'SELECT * FROM matricula;');
      await executar(h);
      await h.quandoDisser('Ela só tem números');
      await destacarResultado(h, 4000);
      await h.quandoDisser('quem é o aluno número 1');
      await destacarResultado(h, 3000);
    },
    async (h) => {
      await h.rolar(SQL);
      await h.quandoDisser('escolho as colunas');
      await escrever(h, 'SELECT a.nome, m.curso_id');
      await h.quandoDisser('parto da tabela matrícula');
      await h.digitar(null, '\nFROM matricula m');
      await h.quandoDisser('junto com a tabela aluno');
      await h.digitar(null, '\nINNER JOIN aluno a');
    },
    async (h) => {
      await h.quandoDisser('ele diz como');
      await h.digitar(null, '\n  ON a.id = m.aluno_id;');
      await h.destacar(SQL, 3000);
      await h.quandoDisser('esse m e esse a');
      await h.destacar(SQL, 3500);
    },
    async (h) => {
      await executar(h);
      await h.quandoDisser('Cada matrícula');
      await destacarResultado(h, 3500);
      await h.quandoDisser('A Ana aparece');
      await destacarResultado(h, 3000);
    },
    async (h) => {
      await abrirAnimacao(h);
      await h.quandoDisser('Pra cada aluno');
      await h.destacar(anim, 2500);
      await h.quandoDisser('Vou avançar');
      await avancarAteDiego(h);
      await h.quandoDisser('Ele não tem');
      await h.destacar(`${anim} tr:has-text("Diego")`, 4000);
    },
    async (h) => {
      await h.quandoDisser('troque pra LEFT JOIN');
      await h.clicar(`${anim} button:text-is("LEFT JOIN")`);
      await h.quandoDisser('todos os alunos');
      await avancarAteDiego(h);
      await h.quandoDisser('Olha o Diego');
      await h.destacar(`${anim} tr:has-text("Diego") >> nth=-1`, 4500);
    },
    async (h) => {
      await h.ir('/dashboard');
      await h.rolar(SQL);
      await escrever(h, 'SELECT a.nome\nFROM aluno a\nLEFT JOIN matricula m ON m.aluno_id = a.id\nWHERE m.id IS NULL;', { atraso: 40 });
      await h.quandoDisser('Aqui, os alunos');
      await executar(h);
      await destacarResultado(h, 2500);
      await h.quandoDisser('E o resultado');
      await destacarResultado(h, 3500);
    },
    async (h) => {
      await h.ir('/guia/sql-ansi');
      await h.clicar('button[role=tab]:has-text("Intermediário")');
      const exemplo = 'figure:has-text("INNER JOIN curso c")';
      await h.rolar(exemplo);
      await h.quandoDisser('juntando três tabelas');
      await h.destacar(exemplo, 4000);
      await h.quandoDisser('Teste no console');
      await h.mover(`${exemplo} >> text=▶ Testar no console`);
      await h.destacar(`${exemplo} >> text=▶ Testar no console`, 2500);
    },
  ],
};
