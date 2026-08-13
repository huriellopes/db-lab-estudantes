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
          const message = error.response?.data?.message ?? 'Não foi possível executar o comando.';
          this.summary = { ok: false, message };
          Alpine.store('toasts').push('error', message);
        } finally {
          this.loading = false;
        }
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
