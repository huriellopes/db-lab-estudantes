import Alpine from 'alpinejs';
import axios from 'axios';
import htmx from 'htmx.org';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Navegação suave, sem recarregar a página: hx-boost="true" nos layouts (ver
// layouts/app.twig e layouts/guest.twig) faz o htmx interceptar clique em link e submit
// de form comuns e trocar só o <body> via AJAX, com histórico do navegador funcionando
// (voltar/avançar) e sem o flash branco de uma navegação normal. Continua funcionando
// sem JS: sem o htmx carregado, os mesmos <a>/<form> navegam do jeito clássico — é só
// enhancement, igual o resto da app (ver comentário do ajaxForm mais abaixo).
htmx.config.globalViewTransitions = true; // cross-fade suave (View Transitions API do navegador; sem suporte, cai pra troca instantânea)
window.htmx = htmx;

// Barra de progresso fininha no topo durante a navegação — feedback visual de que algo
// está carregando, sem travar a tela (troca só acontece quando a resposta chega).
document.addEventListener('htmx:beforeRequest', (e) => {
  if (e.detail.boosted) document.documentElement.classList.add('htmx-navigating');
});
document.addEventListener('htmx:afterRequest', (e) => {
  if (e.detail.boosted) document.documentElement.classList.remove('htmx-navigating');
});

// Token CSRF da sessão (ver csrf_token() no Twig, renderizado numa <meta> no <head> dos
// dois layouts) — mandado em toda requisição Axios, verificado central em public/index.php.
const csrfMeta = document.querySelector('meta[name="csrf-token"]');
if (csrfMeta) {
  axios.defaults.headers.common['X-CSRF-Token'] = csrfMeta.content;
}

// O hx-boost troca só o <body>: a <meta csrf-token> do <head> ficava com o token da primeira
// página. Como o login gera um token novo (session_regenerate_id, ver App\Core\Auth), a
// primeira ação via Axios depois de logar sempre levava 419 + uma ida extra a /csrf-token.
// Aqui o token é lido da própria resposta da navegação, antes de trocar a tela.
document.addEventListener('htmx:beforeSwap', (e) => {
  const html = e.detail.serverResponse;
  const match = typeof html === 'string' && html.match(/<meta name="csrf-token" content="([^"]+)"/);
  if (!match) return;
  axios.defaults.headers.common['X-CSRF-Token'] = match[1];
  document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', match[1]);
});

// Por padrão o htmx DESCARTA respostas 4xx/5xx — clicar num link que dava 403/404/500 ou
// enviar um form com o lab em manutenção (503) simplesmente "não fazia nada". Mostra a
// página de erro do servidor como qualquer outra (só pra navegação boosted; requisições
// parciais, como a busca das tabelas, continuam ignorando erro pra não quebrar o layout).
htmx.config.responseHandling = [
  { code: '204', swap: false },
  { code: '[23]..', swap: true },
  { code: '[45]..', swap: true, error: true },
  { code: '...', swap: false },
];
document.addEventListener('htmx:beforeSwap', (e) => {
  if (e.detail.isError && !e.detail.boosted) e.detail.shouldSwap = false;
});

/**
 * Navega pra uma URL do mesmo jeito que um clique num link boosted (troca só o <body>,
 * empurra o histórico, barra de progresso) — pra quem precisa navegar via JS sem recarregar
 * a página inteira (`window.location.href = ...` recarregava CSS/JS e reiniciava o Alpine).
 * Sem htmx (JS falhou), cai pra navegação normal.
 */
window.navigate = (url) => {
  if (!window.htmx) {
    window.location.href = url;
    return;
  }
  const link = document.createElement('a');
  link.href = url;
  link.setAttribute('hx-boost', 'true');
  link.hidden = true;
  document.body.appendChild(link);
  htmx.process(link);
  link.click();
  link.remove();
};

/** Recarrega a página atual sem reload completo (mesmo caminho do window.navigate). */
window.refreshPage = () => window.navigate(window.location.pathname + window.location.search);

// Sessão "expirada" quase sempre é só o token CSRF ficando velho (aba aberta tempo demais,
// ou sessão renovada em outra aba) — não a pessoa ter sido deslogada de verdade. Em vez de
// empurrar isso pra cada componente (ou, pior, mandar recarregar a página e perder o que
// tava sendo digitado), um interceptor central busca um token novo em GET /csrf-token e
// repete a MESMA requisição, uma vez só. Só sobra erro pra quem chamou se rodar de novo e
// falhar de novo — aí sim a sessão caiu mesmo (ver App\Core\Auth::requireLogin, que nesse
// caso devolve 401 com uma mensagem clara em vez de redirecionar).
axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    const config = error.config;
    const status = error.response?.status;

    if (status === 419 && config && !config._retriedAfterCsrfRefresh) {
      config._retriedAfterCsrfRefresh = true;

      try {
        const { data } = await axios.get('/csrf-token');
        if (data?.token) {
          axios.defaults.headers.common['X-CSRF-Token'] = data.token;
          // config.headers é uma instância de AxiosHeaders (não um objeto comum) — usa o
          // método .set() dela em vez de atribuição por colchete, senão o header novo não
          // necessariamente vai junto na hora de serializar a requisição repetida.
          if (typeof config.headers?.set === 'function') {
            config.headers.set('X-CSRF-Token', data.token);
          } else {
            config.headers = { ...config.headers, 'X-CSRF-Token': data.token };
          }
          return axios(config);
        }
      } catch (_) {
        // Sem sorte buscando um token novo — cai no reject abaixo com o erro 419 original.
      }
    }

    return Promise.reject(error);
  },
);

window.axios = axios;

document.addEventListener('alpine:init', () => {
  /**
   * Toaster global — qualquer parte da app chama Alpine.store('toasts').push(type, message).
   * O container que efetivamente desenha os toasts vive em partials/toaster.twig
   * (incluído uma vez nos layouts), então essa store é o único ponto de entrada.
   */
  Alpine.store('toasts', {
    items: [],

    push(type, message) {
      const id = `t${Date.now()}-${Math.random().toString(36).slice(2)}`;
      this.items.push({ id, type, message });
      setTimeout(() => this.remove(id), 5000);
    },

    remove(id) {
      this.items = this.items.filter((toast) => toast.id !== id);
    },
  });

  /**
   * Modal de confirmação global — substitui window.confirm() nativo. Uso:
   *   const ok = await window.confirmAction({ title, message, confirmLabel, danger });
   * Só uma instância do modal existe na página (ver partials/confirm-modal.twig).
   */
  Alpine.store('confirmModal', {
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    danger: true,
    _resolve: null,

    request({ title = 'Confirmar ação', message = '', confirmLabel = 'Confirmar', danger = true }) {
      this.title = title;
      this.message = message;
      this.confirmLabel = confirmLabel;
      this.danger = danger;
      this.open = true;

      return new Promise((resolve) => {
        this._resolve = resolve;
      });
    },

    confirm() {
      this.open = false;
      this._resolve?.(true);
    },

    cancel() {
      this.open = false;
      this._resolve?.(false);
    },
  });

  /**
   * Componente genérico para formulários que agem via Axios em vez de recarregar a
   * página inteira (exclusão de schema/aluno/usuário, troca de papel/senha, etc.).
   *
   * O <form> continua um <form method="post" action="..."> normal: se o JS falhar ao
   * carregar, ele funciona do jeito clássico (o backend detecta a ausência do header
   * X-Requested-With e responde com redirect + flash em vez de JSON).
   *
   * Opções:
   *  - confirmMessage: string, ou função (event) => string. Se definida, abre o modal
   *    de confirmação antes de enviar.
   *  - confirmTitle / confirmLabel / danger: customizam o modal de confirmação.
   *  - removeRow: remove o elemento [data-row] mais próximo em caso de sucesso.
   *  - refresh: recarrega a página atual em caso de sucesso, sem reload completo (ver
   *    window.refreshPage) — pra quando a ação muda números/listas da tela inteira.
   *  - onSuccess(response): callback extra em caso de sucesso.
   */
  Alpine.data('ajaxForm', (options = {}) => ({
    loading: false,

    async submit(event) {
      const {
        confirmMessage = null,
        confirmTitle = 'Confirmar ação',
        confirmLabel = 'Confirmar',
        danger = true,
        removeRow = false,
        refresh = false,
        onSuccess = null,
      } = options;

      if (confirmMessage) {
        const message =
          typeof confirmMessage === 'function' ? confirmMessage(event) : confirmMessage;
        const confirmed = await window.confirmAction({
          title: confirmTitle,
          message,
          confirmLabel,
          danger,
        });
        if (!confirmed) {
          return;
        }
      }

      const form = event.target.tagName === 'FORM' ? event.target : event.target.closest('form');
      if (!form) {
        return;
      }

      this.loading = true;

      try {
        const response = await axios.post(form.action, new FormData(form));
        if (removeRow) {
          this.$root.closest('[data-row]')?.remove();
        }
        if (response.data?.message) {
          Alpine.store('toasts').push('success', response.data.message);
        }
        if (typeof onSuccess === 'function') {
          onSuccess(response);
        }
        if (refresh) {
          window.refreshPage();
        }
      } catch (error) {
        const message = error.response?.data?.message ?? 'Não foi possível concluir a ação.';
        Alpine.store('toasts').push('error', message);
      } finally {
        this.loading = false;
      }
    },
  }));

  /**
   * Console SQL do dashboard (aluno/professor): roda os comandos digitados contra o
   * próprio schema, via POST /dashboard/sql. Resultado tem forma variável (linhas de
   * SELECT com colunas dinâmicas, ou só "X linha(s) afetada(s)" pra INSERT/UPDATE/DDL),
   * por isso é um componente dedicado em vez do ajaxForm genérico.
   */
  Alpine.data(
    'sqlConsole',
    (initialSchema = '', initialSchemas = [], initialSavedQueries = []) => ({
      sql: '',
      schema: initialSchema,
      // Lista do <select>: começa com o que o servidor já sabia, e é atualizada a cada
      // resposta do console (ver run() abaixo) — cobre o caso de rodar CREATE/DROP DATABASE
      // sem nenhum schema selecionado (o backend sincroniza e devolve a lista atual).
      schemas: initialSchemas,
      loading: false,
      results: [],
      summary: null,
      hasSelection: false,

      // Biblioteca pessoal de consultas salvas (ver App\Controllers\SavedQueryController) —
      // cada item é { id, title, schema, sql }.
      savedQueries: initialSavedQueries,
      saveTitle: '',
      showSaveForm: false,
      savingQuery: false,

      // A senha MySQL fica em cache (criptografada) na sessão, só pra abrir a conexão do
      // console sem pedir de novo a cada comando — ver App\Core\Auth::mysqlPassword(). Sem
      // esse cache (sessão aberta antes da feature existir, ou reaberta sozinha pelo
      // "lembrar de mim", que de propósito não cacheia senha), run() volta com
      // needsMysqlPassword: true e a gente pede a senha aqui mesmo, sem precisar de um
      // logout/login completo.
      needsMysqlPassword: false,
      confirmPasswordValue: '',
      confirmingPassword: false,

      // Chave do rascunho no localStorage deste navegador — nada aqui viaja pro servidor,
      // é só uma rede de segurança local (ver persistDraft()) pra nunca perder o que a
      // pessoa digitou só porque a sessão expirou ou a aba foi recarregada sem querer.
      draftKey: 'db-lab:sql-console-draft',

      /** Chamado pelo Alpine assim que o componente monta: recupera um rascunho pendente
       *  (se sobrou algum) e passa a salvar automaticamente a cada mudança daqui pra frente. */
      init() {
        const draft = this.loadDraft();
        if (draft && this.sql === '') {
          this.sql = typeof draft.sql === 'string' ? draft.sql : '';
          if (draft.schema && this.schemas.includes(draft.schema)) {
            this.schema = draft.schema;
          }
          if (this.sql !== '') {
            Alpine.store('toasts').push(
              'success',
              'Recuperamos o comando que você tinha digitado.',
            );
          }
        }

        this.$watch('sql', () => this.persistDraft());
        this.$watch('schema', () => this.persistDraft());
      },

      persistDraft() {
        try {
          if (this.sql.trim() === '') {
            localStorage.removeItem(this.draftKey);
            return;
          }
          localStorage.setItem(
            this.draftKey,
            JSON.stringify({ sql: this.sql, schema: this.schema }),
          );
        } catch (_) {
          // localStorage indisponível (aba anônima, quota cheia...) — só não persiste, sem quebrar o console.
        }
      },

      loadDraft() {
        try {
          const raw = localStorage.getItem(this.draftKey);
          return raw ? JSON.parse(raw) : null;
        } catch (_) {
          return null;
        }
      },

      /** Chamado em @select/@mouseup/@keyup/@blur do textarea — só atualiza um boolean pra
       *  refletir na UI (rótulo do botão, dica abaixo do campo); a leitura de verdade da
       *  seleção acontece de novo em run(), na hora de montar o payload. */
      trackSelection() {
        const textarea = this.$refs.sqlInput;
        this.hasSelection = !!textarea && textarea.selectionStart !== textarea.selectionEnd;
      },

      /** Texto selecionado no textarea, ou o conteúdo inteiro se nada estiver selecionado. */
      scriptToRun() {
        const textarea = this.$refs.sqlInput;
        if (textarea && textarea.selectionStart !== textarea.selectionEnd) {
          return textarea.value.substring(textarea.selectionStart, textarea.selectionEnd);
        }
        return this.sql;
      },

      async run() {
        const sql = this.scriptToRun().trim();
        if (!sql) {
          Alpine.store('toasts').push('error', 'Digite algum comando SQL.');
          return;
        }

        this.loading = true;
        this.summary = null;
        this.needsMysqlPassword = false;

        try {
          const response = await axios.post(
            '/dashboard/sql',
            new URLSearchParams({ sql, schema: this.schema }),
          );
          this.results = response.data.results ?? [];
          this.schemas = response.data.schemas ?? this.schemas;
          this.summary = { ok: true, message: response.data.message };
          Alpine.store('toasts').push('success', response.data.message);
        } catch (error) {
          this.results = error.response?.data?.results ?? [];
          this.schemas = error.response?.data?.schemas ?? this.schemas;
          this.needsMysqlPassword = !!error.response?.data?.needsMysqlPassword;
          const message = error.response?.data?.message ?? 'Não foi possível executar o comando.';
          this.summary = { ok: false, message };
          Alpine.store('toasts').push('error', message);
        } finally {
          this.loading = false;
        }
      },

      /** Confirma a senha da conta pra recachear a senha MySQL na sessão (ver run() acima)
       *  e, se der certo, já tenta rodar o comando de novo sozinho — a pessoa não perde o
       *  fluxo, só confirma a senha e segue. */
      async confirmMysqlPassword() {
        const password = this.confirmPasswordValue;
        if (!password) {
          Alpine.store('toasts').push('error', 'Digite sua senha.');
          return;
        }

        this.confirmingPassword = true;

        try {
          const response = await axios.post(
            '/dashboard/sql/confirmar-senha',
            new URLSearchParams({ password }),
          );
          this.confirmPasswordValue = '';
          this.needsMysqlPassword = false;
          Alpine.store('toasts').push('success', response.data.message);
          await this.run();
        } catch (error) {
          const message = error.response?.data?.message ?? 'Não foi possível confirmar a senha.';
          Alpine.store('toasts').push('error', message);
        } finally {
          this.confirmingPassword = false;
        }
      },

      /** Fecha o modal de confirmação de senha sem rodar nada — o comando digitado
       *  continua no textarea (e no rascunho salvo), só não roda até confirmar de novo. */
      cancelMysqlPassword() {
        this.needsMysqlPassword = false;
        this.confirmPasswordValue = '';
      },

      clear() {
        this.sql = '';
        this.results = [];
        this.summary = null;
        this.hasSelection = false;
      },

      /** Abre/fecha o campo de nome pra salvar o comando atual (ou o trecho selecionado). */
      toggleSaveForm() {
        if (!this.showSaveForm && this.scriptToRun().trim() === '') {
          Alpine.store('toasts').push('error', 'Digite algum comando SQL antes de salvar.');
          return;
        }
        this.showSaveForm = !this.showSaveForm;
      },

      async saveQuery() {
        const title = this.saveTitle.trim();
        const sql = this.scriptToRun().trim();

        if (!title) {
          Alpine.store('toasts').push('error', 'Dê um nome pra essa consulta.');
          return;
        }
        if (!sql) {
          Alpine.store('toasts').push('error', 'Digite algum comando SQL antes de salvar.');
          return;
        }

        this.savingQuery = true;

        try {
          const response = await axios.post(
            '/consultas-salvas',
            new URLSearchParams({ title, sql, schema: this.schema }),
          );
          this.savedQueries = response.data.savedQueries ?? this.savedQueries;
          this.saveTitle = '';
          this.showSaveForm = false;
          Alpine.store('toasts').push('success', response.data.message);
        } catch (error) {
          const message = error.response?.data?.message ?? 'Não foi possível salvar a consulta.';
          Alpine.store('toasts').push('error', message);
        } finally {
          this.savingQuery = false;
        }
      },

      /** Joga a consulta salva de volta no textarea — não roda sozinha, só carrega. */
      loadQuery(query) {
        this.sql = query.sql;
        if (query.schema && this.schemas.includes(query.schema)) {
          this.schema = query.schema;
        }
        this.results = [];
        this.summary = null;
        Alpine.store('toasts').push(
          'success',
          `Consulta "${query.title}" carregada — revise antes de executar.`,
        );
      },

      async deleteQuery(query) {
        const confirmed = await window.confirmAction({
          title: 'Excluir consulta salva',
          message: `Excluir "${query.title}"? Essa ação não pode ser desfeita.`,
          confirmLabel: 'Excluir',
        });
        if (!confirmed) {
          return;
        }

        try {
          const response = await axios.post(
            '/consultas-salvas/excluir',
            new URLSearchParams({ id: query.id }),
          );
          this.savedQueries =
            response.data.savedQueries ?? this.savedQueries.filter((q) => q.id !== query.id);
          Alpine.store('toasts').push('success', response.data.message);
        } catch (error) {
          const message = error.response?.data?.message ?? 'Não foi possível excluir a consulta.';
          Alpine.store('toasts').push('error', message);
        }
      },
    }),
  );

  /**
   * Laboratório de modelagem (MER/DER): editor visual de entidades/atributos/relacionamentos
   * — ver /guia/modelagem-er pra teoria e app/Controllers/ErDiagramController.php pro CRUD.
   * Sem biblioteca de diagrama nenhuma: entidade é um <div> posicionado por x/y (arrastado via
   * Pointer Events), relacionamento é uma <line> de SVG entre os centros de duas entidades,
   * recalculada sozinha porque tudo é reativo (mover a entidade já move a linha).
   */
  Alpine.data('erLab', (initialDiagrams = []) => ({
    entities: [],
    relationships: [],

    // Sempre a mesma largura pra toda entidade — evita ter que ler getBoundingClientRect()
    // do DOM (que não é reativo) só pra saber onde uma linha de relacionamento deve terminar.
    ENTITY_WIDTH: 240,
    attributeTypes: [
      'VARCHAR(50)',
      'VARCHAR(100)',
      'VARCHAR(255)',
      'TEXT',
      'INT',
      'BIGINT',
      'DECIMAL(10,2)',
      'FLOAT',
      'DATE',
      'DATETIME',
      'TIMESTAMP',
      'BOOLEAN',
    ],

    diagrams: initialDiagrams,
    currentDiagramId: null,
    title: '',
    loadingDiagram: false,
    saving: false,

    draggingId: null,
    dragOffsetX: 0,
    dragOffsetY: 0,

    // Modo "conectar": primeiro clique escolhe a entidade de origem (connectFromId), segundo
    // clique numa entidade diferente cria o relacionamento entre as duas.
    connecting: false,
    connectFromId: null,

    showSqlModal: false,
    generatedSql: '',

    uid(prefix) {
      return `${prefix}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
    },

    entityById(id) {
      return this.entities.find((e) => e.id === id);
    },

    /** Molde de atributo novo — os campos além de name/type/pk só valem pra quem não é PK
     *  (ver template no Twig: o painel de "mais opções" nem aparece na linha da PK). */
    blankAttribute(overrides = {}) {
      return {
        id: this.uid('a'),
        name: '',
        type: 'VARCHAR(100)',
        pk: false,
        notNull: false,
        unique: false,
        default: '',
        // id da entidade referenciada, se esse atributo for uma chave estrangeira — '' quando não é.
        fkEntityId: '',
        // "mais opções" (not null/único/padrão/FK) começa fechado, expande sob demanda.
        expanded: false,
        ...overrides,
      };
    },

    // --- Entidades ---

    addEntity() {
      const index = this.entities.length;
      this.entities.push({
        id: this.uid('e'),
        name: `entidade_${index + 1}`,
        x: 40 + (index % 3) * 260,
        y: 40 + Math.floor(index / 3) * 240,
        attributes: [this.blankAttribute({ name: 'id', type: 'INT', pk: true })],
      });
    },

    removeEntity(id) {
      this.entities = this.entities.filter((e) => e.id !== id);
      // Um relacionamento sem uma das pontas não faz sentido nenhum — some junto.
      this.relationships = this.relationships.filter((r) => r.fromId !== id && r.toId !== id);
      // E nenhum atributo de outra entidade pode continuar apontando essa como FK.
      this.entities.forEach((entity) => {
        entity.attributes.forEach((attr) => {
          if (attr.fkEntityId === id) {
            attr.fkEntityId = '';
          }
        });
      });
      if (this.connectFromId === id) {
        this.connecting = false;
        this.connectFromId = null;
      }
    },

    addAttribute(entity) {
      entity.attributes.push(this.blankAttribute());
    },

    /** Remove um atributo; se era a PK e sobrou pelo menos um outro, promove o primeiro que
     *  sobrou — uma entidade com atributos nunca fica sem chave primária nenhuma. */
    removeAttribute(entity, attrId) {
      const wasPk = entity.attributes.find((a) => a.id === attrId)?.pk === true;
      entity.attributes = entity.attributes.filter((a) => a.id !== attrId);
      if (wasPk && entity.attributes.length > 0 && !entity.attributes.some((a) => a.pk)) {
        entity.attributes[0].pk = true;
      }
    },

    /** Só uma PK por entidade — marcar uma desmarca as outras (comportamento de rádio). */
    setPrimaryKey(entity, attrId) {
      entity.attributes.forEach((a) => {
        a.pk = a.id === attrId;
      });
    },

    /** Altura aproximada da caixa (cabeçalho + uma linha por atributo + rodapé, mais um
     *  extra pra cada painel de "mais opções" aberto) — junto com ENTITY_WIDTH, é o
     *  suficiente pra calcular onde uma linha de relacionamento deve começar/terminar sem
     *  precisar medir o DOM de verdade. */
    entityHeight(entity) {
      const expandedCount = entity.attributes.filter((a) => a.expanded && !a.pk).length;
      return 52 + entity.attributes.length * 32 + expandedCount * 88 + 44;
    },

    // --- Arrastar entidade ---

    /** Ponto do clique/toque relativo ao canvas, já considerando o quanto ele estiver rolado. */
    canvasPoint(event) {
      const canvas = this.$refs.canvas;
      const rect = canvas.getBoundingClientRect();
      return {
        x: event.clientX - rect.left + canvas.scrollLeft,
        y: event.clientY - rect.top + canvas.scrollTop,
      };
    },

    startDrag(entity, event) {
      if (this.connecting) {
        this.handleConnectClick(entity.id);
        return;
      }
      // Clique num campo/botão de dentro da caixa (nome, atributo, tipo, PK, excluir) não
      // deve arrastar — só editar/clicar normalmente. Sobra o resto da caixa (cabeçalho, a
      // alcinha ⠿, as bordas) como área de arrastar.
      if (['INPUT', 'SELECT', 'BUTTON', 'TEXTAREA'].includes(event.target.tagName)) {
        return;
      }
      const point = this.canvasPoint(event);
      this.draggingId = entity.id;
      this.dragOffsetX = point.x - entity.x;
      this.dragOffsetY = point.y - entity.y;
    },

    onPointerMove(event) {
      if (!this.draggingId) {
        return;
      }
      const entity = this.entityById(this.draggingId);
      if (!entity) {
        return;
      }
      const point = this.canvasPoint(event);
      entity.x = Math.max(0, point.x - this.dragOffsetX);
      entity.y = Math.max(0, point.y - this.dragOffsetY);
    },

    onPointerUp() {
      this.draggingId = null;
    },

    // --- Relacionamentos ---

    toggleConnectMode() {
      this.connecting = !this.connecting;
      this.connectFromId = null;
    },

    handleConnectClick(entityId) {
      if (this.connectFromId === null) {
        this.connectFromId = entityId;
        return;
      }
      if (this.connectFromId === entityId) {
        this.connectFromId = null;
        return;
      }
      this.relationships.push({
        id: this.uid('r'),
        fromId: this.connectFromId,
        toId: entityId,
        cardinalityFrom: '1',
        cardinalityTo: 'N',
        label: '',
      });
      this.connecting = false;
      this.connectFromId = null;
    },

    removeRelationship(id) {
      this.relationships = this.relationships.filter((r) => r.id !== id);
    },

    toggleCardinality(rel, side) {
      const key = side === 'from' ? 'cardinalityFrom' : 'cardinalityTo';
      rel[key] = rel[key] === '1' ? 'N' : '1';
    },

    // --- Geometria (usada direto nos :x1/:y1/:x2/:y2 do SVG e no posicionamento dos rótulos) ---

    entityCenter(id) {
      const entity = this.entityById(id);
      if (!entity) {
        return { x: 0, y: 0 };
      }
      return { x: entity.x + this.ENTITY_WIDTH / 2, y: entity.y + this.entityHeight(entity) / 2 };
    },

    /** Ponto na linha do relacionamento, a uma fração `t` (0 = origem, 1 = destino) — usado
     *  pra plantar os badges de cardinalidade perto de cada ponta e o rótulo no meio. */
    relPointAt(rel, t) {
      const from = this.entityCenter(rel.fromId);
      const to = this.entityCenter(rel.toId);
      return { x: from.x + (to.x - from.x) * t, y: from.y + (to.y - from.y) * t };
    },

    /** Estilo inline de uma <div> fina "deitada" e girada pra parecer uma linha entre os
     *  centros das duas entidades — a técnica clássica de linha via CSS puro (largura =
     *  distância entre os pontos, rotação = ângulo entre eles, origem no canto da div). */
    lineStyle(rel) {
      const from = this.entityCenter(rel.fromId);
      const to = this.entityCenter(rel.toId);
      const dx = to.x - from.x;
      const dy = to.y - from.y;
      const length = Math.sqrt(dx * dx + dy * dy);
      const angle = (Math.atan2(dy, dx) * 180) / Math.PI;
      return `left:${from.x}px; top:${from.y}px; width:${length}px; transform: rotate(${angle}deg);`;
    },

    // --- Salvar/carregar/excluir diagramas ---

    serialize() {
      return JSON.stringify({
        entities: this.entities.map((e) => ({
          id: e.id,
          name: e.name,
          x: e.x,
          y: e.y,
          attributes: e.attributes,
        })),
        relationships: this.relationships,
      });
    },

    async saveDiagram() {
      const title = this.title.trim();
      if (!title) {
        Alpine.store('toasts').push('error', 'Dê um nome pro diagrama antes de salvar.');
        return;
      }
      if (this.entities.length === 0) {
        Alpine.store('toasts').push('error', 'Adicione ao menos uma entidade antes de salvar.');
        return;
      }

      this.saving = true;

      try {
        const url = this.currentDiagramId
          ? `/laboratorio/modelagem/${this.currentDiagramId}`
          : '/laboratorio/modelagem';
        const response = await axios.post(
          url,
          new URLSearchParams({ title, data: this.serialize() }),
        );
        this.currentDiagramId = response.data.id ?? this.currentDiagramId;
        this.diagrams = response.data.diagrams ?? this.diagrams;
        Alpine.store('toasts').push('success', response.data.message);
      } catch (error) {
        const message = error.response?.data?.message ?? 'Não foi possível salvar o diagrama.';
        Alpine.store('toasts').push('error', message);
      } finally {
        this.saving = false;
      }
    },

    async loadDiagram(diagram) {
      this.loadingDiagram = true;

      try {
        const response = await axios.get(`/laboratorio/modelagem/${diagram.id}`);
        const loaded = response.data.diagram?.data ?? { entities: [], relationships: [] };
        this.entities = loaded.entities ?? [];
        this.relationships = loaded.relationships ?? [];
        // Diagrama salvo antes de existir not null/único/padrão/FK por atributo — completa
        // com os valores padrão pra não carregar `undefined` nos campos.
        this.entities.forEach((entity) => {
          entity.attributes.forEach((attr) => {
            attr.notNull ??= false;
            attr.unique ??= false;
            attr.default ??= '';
            attr.fkEntityId ??= '';
            attr.expanded ??= false;
          });
        });
        this.currentDiagramId = response.data.diagram?.id ?? diagram.id;
        this.title = response.data.diagram?.title ?? diagram.title;
        this.connecting = false;
        this.connectFromId = null;
        Alpine.store('toasts').push('success', `Diagrama "${this.title}" carregado.`);
      } catch (error) {
        const message = error.response?.data?.message ?? 'Não foi possível carregar o diagrama.';
        Alpine.store('toasts').push('error', message);
      } finally {
        this.loadingDiagram = false;
      }
    },

    async deleteDiagram(diagram) {
      const confirmed = await window.confirmAction({
        title: 'Excluir diagrama',
        message: `Excluir "${diagram.title}"? Essa ação não pode ser desfeita.`,
        confirmLabel: 'Excluir',
      });
      if (!confirmed) {
        return;
      }

      try {
        const response = await axios.post(`/laboratorio/modelagem/${diagram.id}/excluir`);
        this.diagrams = response.data.diagrams ?? this.diagrams.filter((d) => d.id !== diagram.id);
        if (this.currentDiagramId === diagram.id) {
          this.newDiagram();
        }
        Alpine.store('toasts').push('success', response.data.message);
      } catch (error) {
        const message = error.response?.data?.message ?? 'Não foi possível excluir o diagrama.';
        Alpine.store('toasts').push('error', message);
      }
    },

    newDiagram() {
      this.entities = [];
      this.relationships = [];
      this.currentDiagramId = null;
      this.title = '';
      this.connecting = false;
      this.connectFromId = null;
    },

    // --- Gerar SQL a partir do diagrama ---

    openSqlPreview() {
      if (this.entities.length === 0) {
        Alpine.store('toasts').push('error', 'Adicione ao menos uma entidade primeiro.');
        return;
      }
      this.generatedSql = this.generateSql();
      this.showSqlModal = true;
    },

    /**
     * Traduz o diagrama pra DDL, no mesmo espírito do guia /guia/modelagem-er: cada entidade
     * vira CREATE TABLE, 1:N/1:1 viram FOREIGN KEY (em ALTER TABLE, separado — assim a ordem
     * das entidades no diagrama nunca importa, mesmo com referências cruzadas) e N:N vira uma
     * tabela associativa nova com as duas FKs.
     */
    generateSql() {
      const sanitize = (name) =>
        (name || '')
          .trim()
          .toLowerCase()
          .replace(/[^a-z0-9_]+/g, '_')
          .replace(/^_+|_+$/g, '') || 'tabela';

      const usedNames = new Set();
      const tables = this.entities.map((entity) => {
        let name = sanitize(entity.name);
        let candidate = name;
        let suffix = 2;
        while (usedNames.has(candidate)) {
          candidate = `${name}_${suffix++}`;
        }
        usedNames.add(candidate);

        const columns = entity.attributes.map((attr) => ({
          name: sanitize(attr.name) || 'coluna',
          type: attr.type || 'VARCHAR(100)',
          pk: attr.pk === true,
          // Os quatro só valem pra quem não é PK (a PK já é NOT NULL/única por natureza, e
          // não faz sentido ela mesma ser uma FK nesta ferramenta) — ver blankAttribute().
          notNull: attr.pk !== true && attr.notNull === true,
          unique: attr.pk !== true && attr.unique === true,
          default: attr.pk !== true ? (attr.default || '').trim() : '',
          fkEntityId: attr.pk !== true ? attr.fkEntityId || '' : '',
        }));
        const pk = columns.find((c) => c.pk) ?? null;

        return { id: entity.id, name: candidate, columns, pk };
      });
      const tableById = Object.fromEntries(tables.map((t) => [t.id, t]));

      /** DEFAULT como o valor foi digitado, entre aspas só quando não é número nem uma
       *  palavra-chave SQL comum (CURRENT_TIMESTAMP, TRUE, FALSE, NULL) — assim
       *  `nota DEFAULT 0` e `status DEFAULT 'ativo'` saem certos sem a pessoa precisar
       *  saber a regra de aspas do SQL. */
      const formatDefault = (value) => {
        const keywords = ['CURRENT_TIMESTAMP', 'TRUE', 'FALSE', 'NULL'];
        if (/^-?\d+(\.\d+)?$/.test(value) || keywords.includes(value.toUpperCase())) {
          return value.toUpperCase() === value ? value : value.toUpperCase();
        }
        return `'${value.replace(/'/g, "''")}'`;
      };

      const creates = tables.map((t) => {
        const cols =
          t.columns.length > 0
            ? t.columns.map((c) => {
                if (c.pk) {
                  return `    ${c.name} INT PRIMARY KEY AUTO_INCREMENT`;
                }
                let line = `    ${c.name} ${c.type}`;
                if (c.notNull) {
                  line += ' NOT NULL';
                }
                if (c.unique) {
                  line += ' UNIQUE';
                }
                if (c.default) {
                  line += ` DEFAULT ${formatDefault(c.default)}`;
                }
                return line;
              })
            : ['    id INT PRIMARY KEY AUTO_INCREMENT'];
        return `CREATE TABLE ${t.name} (\n${cols.join(',\n')}\n);`;
      });

      const junctionTables = [];
      const foreignKeys = [];

      // FK marcada direto num atributo (independente de relacionamento desenhado) — a
      // coluna já existe (veio do CREATE TABLE acima), só falta a constraint.
      for (const t of tables) {
        for (const c of t.columns) {
          const refTable = c.fkEntityId ? tableById[c.fkEntityId] : null;
          if (refTable && refTable.pk) {
            foreignKeys.push(
              `ALTER TABLE ${t.name} ADD FOREIGN KEY (${c.name}) REFERENCES ${refTable.name}(${refTable.pk.name});`,
            );
          }
        }
      }

      for (const rel of this.relationships) {
        const from = tableById[rel.fromId];
        const to = tableById[rel.toId];
        if (!from || !to || !from.pk || !to.pk) {
          continue;
        }

        const manyFrom = rel.cardinalityFrom === 'N';
        const manyTo = rel.cardinalityTo === 'N';

        if (manyFrom && manyTo) {
          const junctionName = `${from.name}_${to.name}`;
          junctionTables.push(
            `CREATE TABLE ${junctionName} (\n` +
              `    ${from.name}_id INT NOT NULL,\n` +
              `    ${to.name}_id INT NOT NULL,\n` +
              `    PRIMARY KEY (${from.name}_id, ${to.name}_id),\n` +
              `    FOREIGN KEY (${from.name}_id) REFERENCES ${from.name}(${from.pk.name}),\n` +
              `    FOREIGN KEY (${to.name}_id) REFERENCES ${to.name}(${to.pk.name})\n` +
              `);`,
          );
          continue;
        }

        // 1:1 -> FK do lado "from", com UNIQUE (relação vira só uma linha correspondente do
        // outro lado). 1:N/N:1 -> FK sempre do lado "N" (o "muitos"), apontando pro lado "1".
        const oneToOne = !manyFrom && !manyTo;
        const fkTable = oneToOne || !manyTo ? from : to;
        const refTable = fkTable === from ? to : from;
        const fkColumn = `${refTable.name}_id`;

        foreignKeys.push(
          `ALTER TABLE ${fkTable.name} ADD COLUMN ${fkColumn} INT${oneToOne ? ' UNIQUE' : ''};`,
        );
        foreignKeys.push(
          `ALTER TABLE ${fkTable.name} ADD FOREIGN KEY (${fkColumn}) REFERENCES ${refTable.name}(${refTable.pk.name});`,
        );
      }

      const parts = ['-- Tabelas', ...creates];
      if (junctionTables.length > 0) {
        parts.push('', '-- Tabelas associativas (relacionamentos N:N)', ...junctionTables);
      }
      if (foreignKeys.length > 0) {
        parts.push(
          '',
          '-- Chaves estrangeiras (atributos FK e relacionamentos 1:1/1:N)',
          ...foreignKeys,
        );
      }

      return parts.join('\n\n');
    },

    copySql() {
      navigator.clipboard
        .writeText(this.generatedSql)
        .then(() =>
          Alpine.store('toasts').push('success', 'SQL copiado para a área de transferência.'),
        )
        .catch(() =>
          Alpine.store('toasts').push(
            'error',
            'Não foi possível copiar. Selecione o texto manualmente.',
          ),
        );
    },

    /** Manda o SQL gerado pro console do dashboard, reaproveitando o mesmo rascunho local que
     *  o console já recupera sozinho ao carregar (ver `sqlConsole` acima) — sem precisar de
     *  nenhuma rota nova só pra "entregar" esse texto de uma página pra outra. */
    openInConsole() {
      try {
        localStorage.setItem(
          'db-lab:sql-console-draft',
          JSON.stringify({ sql: this.generatedSql, schema: '' }),
        );
      } catch (_) {
        // Sem localStorage, só não pré-preenche — a pessoa cola o SQL copiado manualmente.
      }
      window.navigate('/dashboard');
    },
  }));

  /**
   * Abas de nível do guia (Iniciante / Intermediário / Avançado — ver app/Views/guide/*).
   * Lembra o último nível escolhido neste navegador (localStorage, só conveniência — sem ele
   * começa sempre em "iniciante") e aceita link direto pra um nível via #iniciante,
   * #intermediario ou #avancado na URL.
   */
  /**
   * `tail -f` no navegador (GET /admin/logs/ao-vivo): pergunta a cada INTERVAL_MS o que entrou
   * depois do último cursor em GET /admin/logs/ao-vivo/feed (ver App\Services\LogTail).
   * Polling curto em vez de stream de propósito — uma conexão aberta prenderia um worker do
   * PHP-FPM por aba. Com a aba em segundo plano desacelera (HIDDEN_INTERVAL_MS) em vez de
   * parar, e para de vez ao sair da página (destroy(), chamado pelo Alpine quando o hx-boost
   * troca o <body>).
   */
  Alpine.data('liveLog', (initialSource = 'app') => ({
    INTERVAL_MS: 2000,
    HIDDEN_INTERVAL_MS: 10000,
    MAX_LINES: 1000,

    source: initialSource,
    lines: [],
    cursor: '',
    paused: false,
    follow: true,
    level: '',
    search: '',
    status: 'conectando',
    error: null,
    lastUpdate: null,
    timer: null,
    nextId: 0,
    // Muda a cada troca de fonte: resposta de uma requisição antiga (da fonte anterior)
    // que chegue atrasada é descartada em vez de misturar linhas de duas fontes.
    generation: 0,

    init() {
      this.onVisibility = () => {
        if (!document.hidden && !this.paused) this.poll();
      };
      document.addEventListener('visibilitychange', this.onVisibility);
      this.poll();
    },

    destroy() {
      clearTimeout(this.timer);
      document.removeEventListener('visibilitychange', this.onVisibility);
    },

    get visibleLines() {
      const q = this.search.trim().toLowerCase();
      return this.lines.filter(
        (l) =>
          (this.level === '' || l.level === this.level) &&
          (q === '' || `${l.message}\n${l.detail}`.toLowerCase().includes(q)),
      );
    },

    switchSource(source) {
      if (source === this.source) return;
      this.source = source;
      this.lines = [];
      this.cursor = '';
      this.level = '';
      this.generation++;
      const url = new URL(window.location.href);
      url.searchParams.set('fonte', source);
      history.replaceState(history.state, '', url);
      this.poll();
    },

    togglePause() {
      this.paused = !this.paused;
      if (!this.paused) this.poll();
      else clearTimeout(this.timer);
    },

    clear() {
      this.lines = [];
    },

    schedule() {
      clearTimeout(this.timer);
      if (!this.paused) {
        this.timer = setTimeout(
          () => this.poll(),
          document.hidden ? this.HIDDEN_INTERVAL_MS : this.INTERVAL_MS,
        );
      }
    },

    async poll() {
      clearTimeout(this.timer);
      if (this.paused) return;

      const generation = this.generation;
      try {
        const { data } = await axios.get('/admin/logs/ao-vivo/feed', {
          params: { fonte: this.source, cursor: this.cursor },
        });
        if (generation !== this.generation) return;

        this.cursor = data.cursor ?? this.cursor;
        this.error = data.error ?? null;
        this.status = this.error
          ? 'erro'
          : document.hidden
            ? 'ao vivo (a cada 10s, aba oculta)'
            : 'ao vivo';
        this.lastUpdate = new Date();
        if (data.entries?.length) this.append(data.entries);
      } catch (error) {
        if (generation !== this.generation) return;
        this.status = 'erro';
        this.error =
          error.response?.data?.message ??
          'Sem resposta do servidor — tentando de novo em instantes.';
        // Sessão caiu / sem permissão: não adianta insistir.
        if ([401, 403].includes(error.response?.status)) {
          this.paused = true;
          return;
        }
      }
      this.schedule();
    },

    append(entries) {
      const box = this.$refs.box;
      const atBottom = box ? box.scrollHeight - box.scrollTop - box.clientHeight < 40 : true;

      for (const e of entries) this.lines.push({ id: this.nextId++, open: false, ...e });
      if (this.lines.length > this.MAX_LINES)
        this.lines.splice(0, this.lines.length - this.MAX_LINES);

      // Só rola sozinho se "seguir" estiver ligado E a pessoa já estava no fim — quem subiu
      // pra ler uma linha antiga não é arrancado de lá a cada 2s.
      if (this.follow && atBottom) this.$nextTick(() => this.scrollToBottom());
    },

    scrollToBottom() {
      const box = this.$refs.box;
      if (box) box.scrollTop = box.scrollHeight;
    },

    formatTime(value) {
      const date = new Date(String(value).replace(' ', 'T'));
      return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
          });
    },

    levelClass(level) {
      return (
        {
          critical: 'text-rose-400 font-semibold',
          error: 'text-rose-400',
          warning: 'text-amber-300',
          notice: 'text-sky-300',
        }[level] ?? 'text-slate-400'
      );
    },
  }));

  Alpine.data('guideLevels', () => ({
    levels: ['iniciante', 'intermediario', 'avancado'],
    level: 'iniciante',
    storageKey: 'db-lab:guia-nivel',

    init() {
      const fromHash = window.location.hash.replace('#', '');
      if (this.levels.includes(fromHash)) {
        this.level = fromHash;
        return;
      }
      try {
        const saved = localStorage.getItem(this.storageKey);
        if (this.levels.includes(saved)) {
          this.level = saved;
        }
      } catch (_) {
        // localStorage indisponível (aba anônima etc.) — fica no padrão.
      }
    },

    choose(level) {
      this.level = level;
      try {
        localStorage.setItem(this.storageKey, level);
      } catch (_) {
        // idem
      }
      history.replaceState(null, '', '#' + level);
    },
  }));

  /**
   * Bloco de código do guia (ver app/Views/guide/_code.twig): "Copiar" e, nos exemplos de
   * MySQL, "Testar no console" — que reaproveita o mesmo rascunho local que o console SQL do
   * painel já recupera sozinho ao carregar (mesmo caminho do `openInConsole` do laboratório).
   * O texto vem do próprio <code> (x-ref), não de um atributo, pra não duplicar o exemplo.
   */
  Alpine.data('codeExample', () => ({
    copy() {
      navigator.clipboard
        .writeText(this.$refs.code.innerText.trim())
        .then(() => Alpine.store('toasts').push('success', 'Exemplo copiado.'))
        .catch(() =>
          Alpine.store('toasts').push(
            'error',
            'Não foi possível copiar. Selecione o texto manualmente.',
          ),
        );
    },

    openInConsole() {
      try {
        localStorage.setItem(
          'db-lab:sql-console-draft',
          JSON.stringify({ sql: this.$refs.code.innerText.trim(), schema: '' }),
        );
      } catch (_) {
        // Sem localStorage, só não pré-preenche — a pessoa usa o "Copiar".
      }
      window.navigate('/dashboard');
    },
  }));

  /**
   * Autocomplete de "vincular pessoa" (instituição e turma). Busca em `url?q=` só quem ainda pode
   * ser vinculado (o servidor filtra) e só libera o envio depois que uma sugestão é escolhida —
   * evita vincular a pessoa errada por um e-mail digitado com erro. O valor enviado (hidden
   * `identificador`) é o e-mail da sugestão escolhida. Fica dentro do <form> do ajaxForm.
   */
  Alpine.data('userPicker', (url) => ({
    query: '',
    results: [],
    selected: null,
    open: false,
    active: -1,
    searching: false,
    timer: null,
    seq: 0,

    onInput() {
      this.selected = null;
      clearTimeout(this.timer);
      if (this.query.trim().length < 2) {
        this.results = [];
        this.open = false;
        return;
      }
      this.timer = setTimeout(() => this.search(), 250);
    },

    async search() {
      // Respostas fora de ordem (digitação rápida): só vale a da última busca.
      const seq = ++this.seq;
      this.searching = true;
      try {
        const { data } = await axios.get(url, { params: { q: this.query.trim() } });
        if (seq !== this.seq) return;
        this.results = data.results ?? [];
        this.active = this.results.length ? 0 : -1;
        this.open = true;
      } catch (_) {
        if (seq === this.seq) this.results = [];
      } finally {
        if (seq === this.seq) this.searching = false;
      }
    },

    choose(result) {
      this.selected = result;
      this.query = `${result.name} <${result.email}>`;
      this.open = false;
    },

    onKeydown(event) {
      if (!this.open || this.results.length === 0) return;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        this.active = Math.min(this.active + 1, this.results.length - 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        this.active = Math.max(this.active - 1, 0);
      } else if (event.key === 'Enter' && this.active >= 0) {
        event.preventDefault();
        this.choose(this.results[this.active]);
      } else if (event.key === 'Escape') {
        this.open = false;
      }
    },

    roleLabel(role) {
      return role === 'professor' ? 'Professor' : 'Aluno';
    },
  }));

  /**
   * Lista longa renderizada no servidor (membros de instituição/turma): mostra `perPage` por vez,
   * com busca por nome/e-mail e paginação, sem nova requisição. Cada item tem `data-paged-row`
   * e `data-search` (texto pesquisável) e usa `x-show="shows($el)"`. Controles em
   * partials/paged-list-controls.twig (só aparecem quando a lista passa de uma página).
   */
  Alpine.data('pagedList', (perPage = 8) => ({
    q: '',
    page: 1,
    perPage,
    items: [],

    init() {
      this.items = [...this.$el.querySelectorAll('[data-paged-row]')].map((el) => ({
        el,
        text: (el.dataset.search || el.textContent).toLowerCase(),
      }));
      this.$watch('q', () => (this.page = 1));
    },

    get filtered() {
      const q = this.q.trim().toLowerCase();
      return q === '' ? this.items : this.items.filter((i) => i.text.includes(q));
    },

    get pages() {
      return Math.max(1, Math.ceil(this.filtered.length / this.perPage));
    },

    get paginated() {
      return this.items.length > this.perPage;
    },

    shows(el) {
      const index = this.filtered.findIndex((i) => i.el === el);
      if (index === -1) return false;
      const page = Math.min(this.page, this.pages);
      return index >= (page - 1) * this.perPage && index < page * this.perPage;
    },
  }));

  /** Botão de copiar texto (credenciais, comando de conexão do SGBD...) com feedback via toast. */
  Alpine.data('copyable', (text) => ({
    copy() {
      navigator.clipboard
        .writeText(text)
        .then(() => Alpine.store('toasts').push('success', 'Copiado para a área de transferência.'))
        .catch(() =>
          Alpine.store('toasts').push(
            'error',
            'Não foi possível copiar. Selecione o texto manualmente.',
          ),
        );
    },
  }));
});

window.Alpine = Alpine;
Alpine.start();

// Promise-based, fora do escopo do Alpine.store para poder ser chamada de qualquer
// x-data (inclusive antes do Alpine terminar de montar, já que só resolve no clique).
window.confirmAction = (options) => Alpine.store('confirmModal').request(options);
