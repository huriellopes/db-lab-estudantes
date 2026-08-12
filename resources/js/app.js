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
  Alpine.data('sqlConsole', (initialSchema = '', initialSchemas = []) => ({
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
