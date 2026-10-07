# Spec — Admin observável: métricas, saúde, auditoria, backups e logs

## Contexto
`/admin` hoje mostra só 4 contadores (`App\Support\AdminStats`: alunos, professores, admins, schemas). Não há auditoria, os erros vão só pro stderr (`error_log` em `public/index.php`, visível apenas via `docker logs`) e não existe backup pela aplicação. Objetivo: dar ao admin visibilidade e ferramentas operacionais, e mostrar a todos os usuários se o lab (app, MySQL, phpMyAdmin) está de pé.

Padrões a seguir: Actions invocáveis (`App\Core\Action`, `Auth::requireAdmin()`), views Twig em `app/Views/admin/`, migrations em `database/migrations/`, rotas em `public/index.php`, testes Pest unitários em `tests/Unit/`. Sem novas dependências no composer.

Spec viva: `docs/specs/admin-observabilidade.md` (copia deste plano, com checklist por sprint).

---

## Sprint 1 — Saúde do sistema (todos os usuários) + métricas do /admin
1. `app/Services/HealthCheck.php` → retorna `HealthStatus[]` (readonly: nome, ok, latênciaMs, detalhe):
   - **App**: versão PHP, uso de disco de `storage/`, sessão OK.
   - **MySQL**: `SELECT 1` via `App\Core\Database` + `Uptime`/`Threads_connected` (`SHOW GLOBAL STATUS`).
   - **phpMyAdmin**: GET HTTP curto (timeout 2s) em `PMA_INTERNAL_URL` (default `http://phpmyadmin`, mesma rede docker). Nada de expor host/porta na UI (respeitar commit bad6040 — só "online/offline").
   - Cache de 30s em `storage/cache/health.json` para não martelar a cada page view.
2. Card "Status do lab" em `app/Views/dashboard/*` (via `ShowDashboardAction`) — bolinhas verde/vermelho, sem detalhes técnicos para não-admin.
3. Métricas melhores no `/admin` — estender `AdminStats` (ou `AdminMetrics` novo) com:
   - usuários ativos/inativos/na lixeira, logins últimos 7/30 dias (`users.last_login_at`), cadastros por semana;
   - schemas por dono + tamanho total/top 5 maiores (`information_schema.TABLES`), consultas salvas, diagramas ER;
   - saúde detalhada (latência, conexões MySQL) só para admin.
   - Queries novas em `App\Models\User`/`SchemaRecord` seguindo `countByRole`.

## Sprint 2 — Auditoria global
1. Migration `create_audit_logs_table`: `id, user_id NULL, action VARCHAR(64), target_type, target_id, meta JSON, ip, created_at` + índices `(created_at)`, `(user_id)`, `(action)`.
2. `App\Services\AuditLog::record(string $action, ?string $targetType, ?int $targetId, array $meta = [])` — usa `Auth` + `App\Support\ClientIp`. Nunca grava senhas/SQL completo (só hash/trecho truncado).
3. Instrumentar: login ok/falha, logout, reset de senha, ações `Admin/*` (criar/editar/papel/status/senha/excluir/restaurar usuário, excluir schema), ações `Student/*` do professor, criação/exclusão de schema, backup criado/baixado/excluído.
4. `GET /admin/auditoria` (`IndexAuditLogsAction`) — filtros por usuário, ação, período; paginação com o `Paginator` existente.
5. Retenção: comando no `App\Core\Console` (`audit:prune --days=180`).

## Sprint 3 — Logs de erro e backups
**Logs**
1. `App\Support\ErrorLogger`: além do `error_log` atual, grava JSON-lines em `storage/logs/app-YYYY-MM-DD.log` (nível, mensagem, arquivo:linha, rota, user_id, trace truncado). Ligar no `set_exception_handler` de `public/index.php` + `set_error_handler` para warnings, e em `Controller` genérico de erro.
2. `GET /admin/logs` (`ShowAdminLogsAction`): lista arquivos, últimas N entradas, filtro por nível, agrupamento por mensagem (contagem = "problemas recorrentes"). Rotação: mantém 14 dias.

**Backups**
1. `App\Services/BackupService`: dump nativo em PHP via PDO (`SHOW CREATE TABLE` + `INSERT` em lotes, gzip) — sem instalar `mysqldump` na imagem. Escopo: banco da aplicação ou um schema de aluno específico.
2. Arquivos em `storage/backups/` (novo volume nos dois compose; dir criado no `Dockerfile` com dono `www-data`). Retenção: últimos 10.
3. Rotas admin: `GET /admin/backups`, `POST /admin/backups` (gerar), `GET /admin/backups/{nome}` (download, nome validado por regex — sem path traversal), `POST /admin/backups/{nome}/excluir`. Tudo auditado. Restauração fica fora (feita via phpMyAdmin).

## Sprint 4 — README e navegação
- Links na navbar do admin (Auditoria, Logs, Backups).
- README: seções "Painel admin" (métricas, auditoria, logs, backups, retenção, volume `storage/backups`) e "Status do lab"; nova env `PMA_INTERNAL_URL`.

## Arquivos críticos
`public/index.php` (rotas + handler), `app/Actions/Admin/ShowAdminDashboardAction.php`, `app/Support/AdminStats.php`, `app/Views/admin/index.twig`, `app/Actions/Dashboard/ShowDashboardAction.php`, `app/Models/User.php`, `app/Models/SchemaRecord.php`, `Dockerfile`, `docker-compose*.yml`, `README.md`.

## Verificação
- Por sprint: `vendor/bin/pest` (testes novos: `HealthStatus`, `AuditLog` sanitização de meta, `ErrorLogger` formato/rotação, validação de nome de backup) + `composer` lint/padrão já usado no pre-push.
- Manual com `docker compose up`: derrubar `phpmyadmin` (`docker compose stop phpmyadmin`) e ver o card ficar vermelho; gerar/baixar/excluir backup e conferir em `/admin/auditoria`; forçar exceção e ver em `/admin/logs`; aluno não acessa `/admin/*` (403/redirect).

---

## Status
- [x] Sprint 1 — saúde (`App\Services\HealthCheck`, card em `/dashboard`) + métricas (`App\Models\AdminMetrics`)
- [x] Sprint 2 — auditoria (`audit_logs`, `App\Models\AuditLog`, `/admin/auditoria`, `audit:prune`)
- [x] Sprint 3 — logs (`App\Support\ErrorLogger`, `/admin/logs`) e backups (`App\Services\BackupService`, `/admin/backups`)
- [x] Sprint 4 — navegação e README

Fora do escopo (próximos passos possíveis): restauração de backup pela UI, backup agendado (cron), envio de backup para storage externo, alerta por e-mail quando um serviço cair.
