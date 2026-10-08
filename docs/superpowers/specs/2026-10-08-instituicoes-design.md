# Instituições (etapa 2 de 3)

Data: 2026-10-08 · Status: aprovada (o usuário autorizou escrever e implementar)

## Contexto

Pedido: "gerência de escola/instituição que somente o admin pode gerenciar, e pode vincular os
professores e alunos à instituição". A etapa 3 (turmas dentro da instituição) vem depois.

Hoje todo professor vê e gerencia todos os alunos (`/professor/alunos`, `StudentAction::findStudentOrFail`
só confere que o alvo é aluno). O admin usa as mesmas telas (`Auth::requireProfessorOrAdmin`).

## Decisões

| Tema | Decisão |
|---|---|
| Vínculo | Professor pode estar em várias instituições; aluno em no máximo uma. Admin não é membro (vê tudo). |
| Escopo do professor | Só vê e gerencia alunos das instituições em que está vinculado. Aluno sem instituição só aparece para o admin. Alvo fora do escopo responde "Aluno não encontrado" (não revela que existe). |
| Cadastro do aluno | Campo opcional "Código da instituição" no `/register`. Código válido → já entra vinculado; código inválido → cadastro recusado com mensagem clara; vazio → sem instituição. |
| Quem gerencia | Só o admin: criar, renomear, excluir instituição; gerar/desativar código; adicionar/remover professores e alunos. |
| Exclusões | Pelo `Archiver` da etapa 1: excluir instituição arquiva junto os vínculos (os usuários ficam); remover um vínculo arquiva o vínculo; excluir usuário arquiva os vínculos dele. Nome e código de instituição arquivada ficam reservados. |
| Troca de papel | Aluno → professor: o vínculo muda de papel. Professor → aluno com mais de um vínculo: recusado ("remova os vínculos extras antes"). Promover a admin: os vínculos vão para o arquivo. |

## Modelo de dados

```sql
CREATE TABLE institutions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL UNIQUE,
  invite_code VARCHAR(9)   NULL UNIQUE,      -- 'XXXX-XXXX'; NULL = cadastro por código desativado
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE institution_members (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  institution_id  INT NOT NULL,
  user_id         INT NOT NULL,
  role            ENUM('professor','aluno') NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Só preenchida para aluno: o UNIQUE garante "aluno em no máximo uma instituição" no próprio
  -- MySQL (NULLs não colidem), sem depender de checagem em PHP sujeita a corrida.
  student_user_id INT AS (IF(role = 'aluno', user_id, NULL)) STORED,
  UNIQUE KEY uq_member (institution_id, user_id),
  UNIQUE KEY uq_student_one_institution (student_user_id),
  CONSTRAINT fk_member_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## Componentes

- `App\Support\InviteCode` (pura): gera (`XXXX-XXXX`, alfabeto sem 0/O/1/I) e normaliza (maiúsculas,
  sem espaços, hífen opcional) o código digitado.
- `App\Support\InstitutionScope` (pura): dado o usuário logado (papel) e as instituições dele,
  decide se pode ver um aluno (admin: sempre; professor: aluno em instituição em comum).
- `App\Models\Institution` e `App\Models\InstitutionMember`: CRUD/consultas.
- `App\Services\InstitutionManager`: criar, renomear, regenerar/desativar código, vincular (valida
  papel do usuário e a regra do aluno), ajustar vínculos na troca de papel. Remoções via `Archiver`.
- Arquivo (etapa 1): `ArchiveGraph` ganha `institution` (filhos: `institution_member`) e
  `institution_member`; `user` passa a levar `institution_member`. `ArchiveRestoreChecks` confere a
  instituição do vínculo (se não vier no lote) e que um aluno restaurado não está em outra
  instituição. `Archiver::insertRow` ignora colunas geradas. `DeletedModel::isReserved` ganha
  `institution_name` e `invite_code`.

## Telas

- `/admin/instituicoes`: lista (nome, código, nº de professores e alunos), criar; aviso com o
  número de alunos sem instituição (link para `/professor/alunos?instituicao=sem`).
- `/admin/instituicoes/{id}`: renomear, gerar/desativar código, listas de professores e alunos com
  "remover", formulário "adicionar pessoa" (e-mail ou username; o papel vem da conta), excluir instituição.
- `/professor/alunos`: coluna "Instituição" e filtro (professor: as dele; admin: todas + "sem instituição").
- `/register`: campo opcional "Código da instituição".
- Menu Admin: "Instituições". Tudo sem reload (hx-boost + `ajaxForm`).

## Auditoria

`institution.created`, `institution.renamed`, `institution.code_regenerated`, `institution.code_disabled`,
`institution.member_added`, `institution.member_removed`, `institution.deleted` (os dois últimos via
`Archiver`, com `batch`), e `auth.registered` passa a registrar a instituição quando houver código.

## Testes

- Unitários: `InviteCode` (formato, normalização), `InstitutionScope`, novas checagens de
  `ArchiveRestoreChecks`, `ArchiveGraph` com os novos modelos.
- Integração: aluno em duas instituições → recusado pelo MySQL; professor só enxerga alunos das
  instituições dele; cadastro com código; arquivar instituição com vínculos e restaurar; troca de papel.
- Navegador: fluxo admin completo e professor vendo só os alunos dele.

## Fora do escopo

Turmas (etapa 3); professor criar instituição ou vincular alunos; aluno trocar de instituição sozinho.
