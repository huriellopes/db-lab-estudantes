/**
 * Animações interativas do guia (app/Views/partials/guide/anim-*.twig): o aluno avança o
 * "processo" de uma consulta ou de uma normalização passo a passo (◀ ▶, ou ⏵ pra rodar
 * sozinho), no próprio ritmo. Os dados são os mesmos dos exemplos da página, então o que a
 * animação mostra bate com o resultado do console.
 *
 * Cada componente guarda só `step`; tudo o que aparece na tela é derivado dele (getters),
 * então voltar um passo é tão simples quanto avançar.
 */

// Mesmos dados do "Prepare os dados de exemplo" do guia de SQL ANSI.
const ALUNOS = [
  { id: 1, nome: 'Ana Souza', cidade: 'Recife' },
  { id: 2, nome: 'Bruno Lima', cidade: 'Olinda' },
  { id: 3, nome: 'Carla Dias', cidade: 'Recife' },
  { id: 4, nome: 'Diego Rocha', cidade: null },
  { id: 5, nome: 'Elisa Melo', cidade: 'Caruaru' },
];

const MATRICULAS = [
  { id: 1, aluno_id: 1, curso_id: 1 },
  { id: 2, aluno_id: 1, curso_id: 2 },
  { id: 3, aluno_id: 2, curso_id: 1 },
  { id: 4, aluno_id: 3, curso_id: 3 },
  { id: 5, aluno_id: 5, curso_id: 1 },
  { id: 6, aluno_id: 5, curso_id: 4 },
];

/**
 * Condições do WHERE. `test` devolve true, false ou null (desconhecido): é a lógica de três
 * valores do SQL. Qualquer comparação com NULL dá "desconhecido", e o WHERE só deixa passar
 * o que deu true. É por isso que o Diego some até no `<>`.
 */
const WHERE_CONDITIONS = {
  igual: {
    sql: "cidade = 'Recife'",
    test: (a) => (a.cidade === null ? null : a.cidade === 'Recife'),
  },
  diferente: {
    sql: "cidade <> 'Recife'",
    test: (a) => (a.cidade === null ? null : a.cidade !== 'Recife'),
  },
  nulo: {
    sql: 'cidade IS NULL',
    test: (a) => a.cidade === null,
  },
};

/**
 * Junta os pedaços de um componente preservando os getters. `{...a, ...b}` copiaria o VALOR
 * de cada getter no momento da cópia, e `atEnd`/`playing` deixariam de acompanhar o `step`.
 */
function compose(...parts) {
  const component = {};
  for (const part of parts) {
    Object.defineProperties(component, Object.getOwnPropertyDescriptors(part));
  }
  return component;
}

/** Controles comuns: passo atual, avançar/voltar, rodar sozinho. */
function stepper(total) {
  return {
    step: 0,
    timer: null,

    get total() {
      return total;
    },
    get atEnd() {
      return this.step >= this.total;
    },
    next() {
      if (!this.atEnd) this.step++;
      if (this.atEnd) this.pause();
    },
    prev() {
      this.pause();
      if (this.step > 0) this.step--;
    },
    restart() {
      this.pause();
      this.step = 0;
    },
    play() {
      if (this.atEnd) this.step = 0;
      this.pause();
      this.timer = setInterval(() => this.next(), 1600);
    },
    pause() {
      clearInterval(this.timer);
      this.timer = null;
    },
    get playing() {
      return this.timer !== null;
    },
    destroy() {
      this.pause();
    },
  };
}

/** Exportado pros testes (node --test) e usado pelos componentes. */
export function whereRows(conditionKey) {
  const { test } = WHERE_CONDITIONS[conditionKey];
  return ALUNOS.map((a) => ({ ...a, result: test(a) }));
}

/**
 * Resultado do JOIN depois de processar os `step` primeiros alunos. LEFT JOIN mantém quem
 * não tem par, com NULL do lado da matrícula; INNER JOIN deixa de fora.
 */
export function joinResult(mode, step) {
  return ALUNOS.slice(0, step).flatMap((a) => {
    const pares = MATRICULAS.filter((m) => m.aluno_id === a.id);
    if (pares.length === 0) {
      return mode === 'left' ? [{ key: `${a.id}-null`, nome: a.nome, curso_id: null }] : [];
    }
    return pares.map((m) => ({ key: `${a.id}-${m.id}`, nome: a.nome, curso_id: m.curso_id }));
  });
}

/*
 * Passos da normalização, com a mesma lojinha do guia de Formas Normais. Em cada tabela,
 * `warn` marca as colunas com o problema que o próximo passo resolve e `key` marca a chave.
 */
export const NORMALIZATION_STEPS = [
  {
    label: 'Planilhão',
    title: 'Tudo numa tabela só',
    text: 'A coluna produtos guarda uma lista ("Caderno, Caneta") e os dados da Ana se repetem. Perguntar "quantas canetas vendemos?" vira procurar texto dentro de texto.',
    tables: [
      {
        name: 'venda_planilha',
        columns: ['venda_id', 'data', 'cliente', 'cidade', 'produtos'],
        key: ['venda_id'],
        warn: ['produtos'],
        rows: [
          [1, '01/09', 'Ana Souza', 'Recife', 'Caderno, Caneta'],
          [2, '02/09', 'Ana Souza', 'Recife', 'Mochila'],
          [3, '02/09', 'Bruno Lima', 'Olinda', 'Caneta'],
        ],
      },
    ],
  },
  {
    label: '1FN',
    title: '1FN: um valor por célula',
    text: 'Cada produto vendido vira uma linha, e a chave passa a ser (venda_id, produto). Agora dá pra contar canetas, mas o preço da caneta se repete em toda venda que tem caneta: ele depende só do produto, que é metade da chave.',
    tables: [
      {
        name: 'venda_item',
        columns: ['venda_id', 'produto', 'data', 'cliente', 'cidade', 'preco'],
        key: ['venda_id', 'produto'],
        warn: ['preco'],
        rows: [
          [1, 'Caderno', '01/09', 'Ana Souza', 'Recife', '20,00'],
          [1, 'Caneta', '01/09', 'Ana Souza', 'Recife', '5,00'],
          [2, 'Mochila', '02/09', 'Ana Souza', 'Recife', '120,00'],
          [3, 'Caneta', '02/09', 'Bruno Lima', 'Olinda', '5,00'],
        ],
      },
    ],
  },
  {
    label: '2FN',
    title: '2FN: depender da chave inteira',
    text: 'O que depende só do produto (o preço) vai pra tabela produto, e o que depende só da venda (data, cliente) vai pra tabela venda. Sobrou um problema: a cidade depende do cliente, não da venda, e a da Ana ainda aparece duas vezes.',
    tables: [
      {
        name: 'venda',
        columns: ['venda_id', 'data', 'cliente', 'cidade'],
        key: ['venda_id'],
        warn: ['cidade'],
        rows: [
          [1, '01/09', 'Ana Souza', 'Recife'],
          [2, '02/09', 'Ana Souza', 'Recife'],
          [3, '02/09', 'Bruno Lima', 'Olinda'],
        ],
      },
      {
        name: 'venda_item',
        columns: ['venda_id', 'produto'],
        key: ['venda_id', 'produto'],
        warn: [],
        rows: [
          [1, 'Caderno'],
          [1, 'Caneta'],
          [2, 'Mochila'],
          [3, 'Caneta'],
        ],
      },
      {
        name: 'produto',
        columns: ['produto', 'preco'],
        key: ['produto'],
        warn: [],
        rows: [
          ['Caderno', '20,00'],
          ['Caneta', '5,00'],
          ['Mochila', '120,00'],
        ],
      },
    ],
  },
  {
    label: '3FN',
    title: '3FN: só a chave, nada além da chave',
    text: 'O cliente ganha tabela própria e a venda guarda só o cliente_id. Se a Ana se mudar, é UMA linha alterada: não tem mais como o banco dizer que ela mora em duas cidades.',
    tables: [
      {
        name: 'cliente',
        columns: ['id', 'nome', 'cidade'],
        key: ['id'],
        warn: [],
        rows: [
          [1, 'Ana Souza', 'Recife'],
          [2, 'Bruno Lima', 'Olinda'],
        ],
      },
      {
        name: 'venda',
        columns: ['venda_id', 'data', 'cliente_id'],
        key: ['venda_id'],
        warn: [],
        rows: [
          [1, '01/09', 1],
          [2, '02/09', 1],
          [3, '02/09', 2],
        ],
      },
      {
        name: 'venda_item',
        columns: ['venda_id', 'produto'],
        key: ['venda_id', 'produto'],
        warn: [],
        rows: [
          [1, 'Caderno'],
          [1, 'Caneta'],
          [2, 'Mochila'],
          [3, 'Caneta'],
        ],
      },
      {
        name: 'produto',
        columns: ['produto', 'preco'],
        key: ['produto'],
        warn: [],
        rows: [
          ['Caderno', '20,00'],
          ['Caneta', '5,00'],
          ['Mochila', '120,00'],
        ],
      },
    ],
  },
];

export function registerGuideAnimations(Alpine) {
  /** WHERE linha por linha: cada aluno é testado contra a condição escolhida. */
  Alpine.data('whereAnimation', () =>
    compose(stepper(ALUNOS.length), {
      condition: 'igual',
      conditions: WHERE_CONDITIONS,

      setCondition(key) {
        this.condition = key;
        this.restart();
      },
      get rows() {
        return whereRows(this.condition);
      },
      get current() {
        return this.step > 0 ? this.rows[this.step - 1] : null;
      },
      rowState(index) {
        if (index >= this.step) return 'pending';
        return this.rows[index].result === true ? 'pass' : 'fail';
      },
      get result() {
        return this.rows.slice(0, this.step).filter((r) => r.result === true);
      },
      get narration() {
        if (!this.current) {
          return 'O banco vai ler a tabela aluno de cima a baixo e testar a condição do WHERE em cada linha. Clique em ▶ Próximo.';
        }
        const a = this.current;
        const valor = a.cidade === null ? 'NULL' : `'${a.cidade}'`;
        const veredito =
          a.result === true
            ? 'deu VERDADEIRO, a linha entra no resultado.'
            : a.result === false
              ? 'deu FALSO, a linha fica de fora.'
              : 'deu DESCONHECIDO (comparar com NULL nunca é verdadeiro nem falso), e o WHERE só aceita verdadeiro: a linha fica de fora.';
        const fim = this.atEnd ? ` Fim: ${this.result.length} linha(s) no resultado.` : '';
        return `${a.nome}: cidade é ${valor}. ${this.conditions[this.condition].sql} ${veredito}${fim}`;
      },
    }),
  );

  /** JOIN: pra cada aluno, procura as matrículas com aluno_id igual e monta as linhas. */
  Alpine.data('joinAnimation', () =>
    compose(stepper(ALUNOS.length), {
      mode: 'inner',
      alunos: ALUNOS,
      matriculas: MATRICULAS,

      setMode(mode) {
        this.mode = mode;
        this.restart();
      },
      get current() {
        return this.step > 0 ? ALUNOS[this.step - 1] : null;
      },
      alunoState(index) {
        if (index === this.step - 1) return 'current';
        return index < this.step - 1 ? 'done' : 'pending';
      },
      matriculaState(m) {
        if (!this.current) return 'pending';
        return m.aluno_id === this.current.id ? 'match' : 'pending';
      },
      get result() {
        return joinResult(this.mode, this.step);
      },
      isNewRow(row) {
        return this.current !== null && row.key.startsWith(`${this.current.id}-`);
      },
      get narration() {
        if (!this.current) {
          return 'O banco pega cada aluno e procura, na tabela matricula, as linhas com aluno_id igual ao id dele (é a regra do ON). Clique em ▶ Próximo.';
        }
        const a = this.current;
        const pares = MATRICULAS.filter((m) => m.aluno_id === a.id);
        if (pares.length > 0) {
          const ids = pares.map((m) => m.id).join(' e ');
          return `${a.nome} (id ${a.id}): achou ${pares.length} matrícula(s) com aluno_id = ${a.id} (matrícula ${ids}). Cada par vira uma linha do resultado.`;
        }
        return this.mode === 'left'
          ? `${a.nome} (id ${a.id}): nenhuma matrícula com aluno_id = ${a.id}. No LEFT JOIN ele entra mesmo assim, com NULL no lugar dos dados da matrícula.`
          : `${a.nome} (id ${a.id}): nenhuma matrícula com aluno_id = ${a.id}. No INNER JOIN, sem par, ele fica de fora do resultado.`;
      },
    }),
  );

  /** Normalização: a "planilha" vira tabelas, uma forma normal por passo. */
  Alpine.data('normalizationSteps', () =>
    compose(stepper(NORMALIZATION_STEPS.length - 1), {
      steps: NORMALIZATION_STEPS,

      get current() {
        return NORMALIZATION_STEPS[this.step];
      },
      go(index) {
        this.pause();
        this.step = index;
      },
    }),
  );
}
