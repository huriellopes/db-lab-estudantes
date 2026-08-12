import Alpine from 'alpinejs';
import axios from 'axios';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios = axios;

/**
 * Componente genérico para formulários que agem via Axios em vez de recarregar a
 * página inteira (exclusão de schema/aluno/usuário, troca de papel, etc.).
 *
 * O <form> continua um <form method="post" action="..."> normal: se o JS falhar ao
 * carregar, ele funciona do jeito clássico (o backend detecta a ausência do header
 * X-Requested-With e responde com redirect + flash em vez de JSON).
 *
 * Opções:
 *  - confirmMessage: se definido, pede confirmação nativa antes de enviar.
 *  - removeRow: remove o elemento [data-row] mais próximo em caso de sucesso.
 *  - onSuccess(response): callback extra em caso de sucesso.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('ajaxForm', (options = {}) => ({
        loading: false,
        errorMessage: null,

        submit(event) {
            const { confirmMessage = null, removeRow = false, onSuccess = null } = options;

            if (confirmMessage && !window.confirm(confirmMessage)) {
                return;
            }

            const form = event.target.tagName === 'FORM' ? event.target : event.target.closest('form');
            if (!form) {
                return;
            }

            this.loading = true;
            this.errorMessage = null;

            axios
                .post(form.action, new FormData(form))
                .then((response) => {
                    if (removeRow) {
                        this.$root.closest('[data-row]')?.remove();
                    }
                    if (typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                })
                .catch((error) => {
                    this.errorMessage = error.response?.data?.message ?? 'Não foi possível concluir a ação.';
                    window.alert(this.errorMessage);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
    }));

    Alpine.data('flashMessage', () => ({
        visible: true,
        init() {
            setTimeout(() => {
                this.visible = false;
            }, 5000);
        },
    }));
});

window.Alpine = Alpine;
Alpine.start();
