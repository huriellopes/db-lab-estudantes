// Vídeo 2 — Criando seu primeiro schema. Uma função por cena, na ordem de gerar-roteiros.py.
const criar = 'form[action="/schemas"]';
const meus = 'h2:text-is("Meus schemas")';
const console_ = 'textarea[x-ref="sqlInput"]';

export default {
  // Conta zerada por preparar.sh: painel no estado "Você ainda não criou nenhum schema."
  async preparo(h) {
    await h.ir('/dashboard');
    await h.page.evaluate(() => document.querySelector('form[action="/schemas"]').closest('.card').scrollIntoView({ block: 'center' }));
  },
  cenas: [
    async (h) => {
      await h.quandoDisser('Pense nele');
      await h.mover(`${meus} >> xpath=..`, { ms: 1000 });
      await h.quandoDisser('Aqui no painel');
      await h.destacar(`${meus} >> xpath=..`, 2500);
      await h.quandoDisser('está vazia');
      await h.destacar('text=Você ainda não criou nenhum schema.', 2200);
    },
    async (h) => {
      await h.quandoDisser('Criar novo schema');
      await h.mover('h2:text-is("Criar novo schema")');
      await h.destacar(`${criar} >> xpath=..`, 2400);
      await h.quandoDisser('Repare nesse começo');
      await h.mover(`${criar} span.whitespace-nowrap`);
      await h.destacar(`${criar} span.whitespace-nowrap`, 5500);
      await h.quandoDisser('o prefixo garante');
      await h.destacar(`${criar} span.whitespace-nowrap`, 4000);
    },
    async (h) => {
      await h.quandoDisser('escolha um nome curto');
      await h.digitar('#label', 'minha escola');
      await h.quandoDisser('Espaço não pode');
      await h.mover('text=Sem espaços.');
      await h.destacar('text=Sem espaços.', 2600);
      await h.quandoDisser('vou usar só escola');
      await h.page.locator('#label').evaluate((el) => el.select());
      await h.sleep(400);
      await h.page.keyboard.press('Backspace');
      await h.digitar(null, 'escola');
    },
    async (h) => {
      await h.quandoDisser('Clique em Criar schema');
      await h.clicarENavegar(`${criar} button[type=submit]`, '**/dashboard');
      await h.rolar('#my-schemas-results');
      await h.quandoDisser('na lista Meus schemas');
      await h.mover('text=devaluno__escola', { dx: 0.3 });
      await h.destacar('text=devaluno__escola', 2200);
      await h.quandoDisser('o seu prefixo');
      await h.destacar('text=devaluno__escola', 4500);
    },
    async (h) => {
      await h.rolar('#sql_schema');
      await h.mover('#sql_schema', { dx: 0.3 });
      await h.destacar('#sql_schema', 2600);
      await h.quandoDisser('O console é onde');
      await h.mover(console_, { dx: 0.4 });
      await h.destacar(console_, 4000);
    },
    async (h) => {
      await h.ir('/guia/mysql');
      await h.rolar('figure:has-text("Dados de exemplo — loja")');
      const botao = 'figure:has-text("Dados de exemplo — loja") >> text=▶ Testar no console';
      await h.quandoDisser('Testar no console');
      await h.mover(botao);
      await h.destacar(botao, 2200);
      await h.quandoDisser('Clicando nele');
      await h.clicarENavegar(botao, '**/dashboard');
      await h.rolar(console_);
      await h.quandoDisser('que você acabou de criar');
      await h.destacar('#sql_schema', 2200);
    },
    async (h) => {
      await h.quandoDisser('é nele que');
      await h.mover(console_, { dx: 0.5 });
      await h.destacar(console_, 2600);
    },
  ],
};
