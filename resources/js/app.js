import Alpine from 'alpinejs';
import axios from 'axios';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
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

  /** Botão de copiar texto (credenciais, comando de túnel SSH...) com feedback via toast. */
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
