// Vídeo 1 — Desenhando um DER no lab de modelagem. Uma função por cena, na ordem de gerar-roteiros.py.
const caixa = (h, i) => h.page.locator('[x-ref="canvas"] div.border-2').nth(i);
const nomeEntidade = (h, i) => caixa(h, i).locator('input[x-model="entity.name"]');
const atributo = (h, i, j) => caixa(h, i).locator('input[x-model="attr.name"]').nth(j);
const tipo = (h, i, j) => caixa(h, i).locator('select[x-model="attr.type"]').nth(j);
const maisAtributo = (h, i) => caixa(h, i).locator('button:has-text("+ atributo")');

async function renomear(h, loc, texto) {
  await h.clicar(loc);
  await loc.evaluate((el) => el.select());
  await h.sleep(250);
  await h.digitar(null, texto);
}

async function novoAtributo(h, i, j, nome) {
  await h.clicar(maisAtributo(h, i));
  await h.digitar(atributo(h, i, j), nome);
}

export default {
  async preparo(h) {
    await h.ir('/laboratorio/modelagem');
    await h.page.locator('button:has-text("Nova entidade")').evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await h.page.evaluate(() => scrollBy(0, -100));
  },
  cenas: [
    async (h) => {
      await h.quandoDisser('Esse desenho');
      await h.mover('text=Quadro vazio', { ms: 1100 });
      await h.quandoDisser('Ele mostra');
      await h.destacar('[x-ref="canvas"]', 3500);
      await h.quandoDisser('Vamos montar');
      await h.mover('button:has-text("Nova entidade")');
      await h.destacar('button:has-text("Nova entidade") >> xpath=..', 3000);
    },
    async (h) => {
      await h.quandoDisser('aqui nesse campo');
      await h.digitar('input[placeholder="Nome do diagrama"]', 'Escola');
      await h.quandoDisser('Com um nome');
      await h.destacar('button:has-text("Salvar")', 2400);
    },
    async (h) => {
      await h.quandoDisser('clique em Nova entidade');
      await h.clicar('button:has-text("Nova entidade")');
      await h.quandoDisser('vai virar uma tabela');
      await h.destacar(caixa(h, 0), 2400);
      await h.quandoDisser('é só clicar');
      await renomear(h, nomeEntidade(h, 0), 'aluno');
    },
    async (h) => {
      await h.quandoDisser('um atributo chamado id');
      await h.mover(caixa(h, 0).locator('button:text-is("PK")'));
      await h.destacar(caixa(h, 0).locator('button:text-is("PK")'), 2600);
      await h.quandoDisser('A chave primária');
      await h.destacar(atributo(h, 0, 0), 3500);
    },
    async (h) => {
      await h.quandoDisser('Clique em mais atributo');
      await novoAtributo(h, 0, 1, 'nome');
      await h.quandoDisser('Mais um');
      await novoAtributo(h, 0, 2, 'email');
      await h.quandoDisser('Do lado');
      await h.mover(tipo(h, 0, 1));
      await h.destacar(tipo(h, 0, 1), 2000);
      await h.destacar(tipo(h, 0, 2), 2000);
    },
    async (h) => {
      await h.quandoDisser('Nova entidade');
      await h.clicar('button:has-text("Nova entidade")');
      await renomear(h, nomeEntidade(h, 1), 'curso');
      await novoAtributo(h, 1, 1, 'nome');
      await novoAtributo(h, 1, 2, 'carga_horaria');
      await h.quandoDisser('troco o tipo');
      await h.clicar(tipo(h, 1, 2));
      await h.page.keyboard.press('Escape');
      await tipo(h, 1, 2).selectOption('INT');
      await h.destacar(tipo(h, 1, 2), 1800);
      await h.quandoDisser('arrasto a caixa');
      // Arrasta pela alcinha ⠿ até a direita do quadro.
      const alca = caixa(h, 1).locator('span[title="Arrastar pra reposicionar"]');
      await h.mover(alca);
      const b = await alca.boundingBox();
      await h.page.mouse.down();
      await h.moverAte(b.x + b.width / 2 + 300, b.y + b.height / 2 + 30, 1300);
      await h.page.mouse.up();
    },
    async (h) => {
      await h.quandoDisser('Clique em Relacionamento');
      await h.clicar('button:has-text("+ Relacionamento")');
      await h.quandoDisser('aluno, e');
      await h.clicar(caixa(h, 0).locator('div.border-b'), { dx: 0.15 });
      await h.quandoDisser('curso. Apareceu');
      await h.clicar(caixa(h, 1).locator('div.border-b'), { dx: 0.15 });
      await h.quandoDisser('escrevo o nome');
      await h.digitar('input[x-model="rel.label"]', 'matricula');
    },
    async (h) => {
      const origem = '[title^="Cardinalidade do lado da origem"]';
      const destino = '[title^="Cardinalidade do lado do destino"]';
      await h.quandoDisser('Nas pontas');
      await h.destacar(origem, 2000);
      await h.destacar(destino, 2000);
      await h.quandoDisser('Clico no 1');
      await h.clicar(origem);
      await h.quandoDisser('Isso é um relacionamento');
      await h.destacar(origem, 2200);
      await h.destacar(destino, 2200);
    },
    async (h) => {
      await h.quandoDisser('Clique em Salvar');
      await h.clicar('button:has-text("Salvar")');
      await h.quandoDisser('depois em Gerar SQL');
      await h.clicar('button:has-text("Gerar SQL")');
      const sql = 'pre[x-text="generatedSql"]';
      await h.quandoDisser('O laboratório escreve');
      await h.destacar(sql, 3000);
      await h.quandoDisser('olha o final');
      await h.page.locator(sql).evaluate((el) => el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' }));
      await h.sleep(900);
      await h.quandoDisser('a tabela associativa');
      await h.destacar(sql, 6000);
    },
    async (h) => {
      await h.quandoDisser('clique em Abrir no console SQL');
      await h.clicarENavegar('button:has-text("Abrir no console SQL")', '**/dashboard');
      await h.rolar('textarea[x-ref="sqlInput"]');
      await h.quandoDisser('pronto pra rodar');
      await h.destacar('textarea[x-ref="sqlInput"]', 3000);
    },
  ],
};
