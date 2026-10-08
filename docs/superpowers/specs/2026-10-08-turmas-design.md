# Turmas (etapa 3 de 3)

Data: 2026-10-08 · Status: aprovada (o usuário autorizou implementar após o desenho)

## Contexto

Pedido: "gerência de turma, um professor/admin pode cadastrar as turmas, e vincular os alunos às
turmas; basicamente uma instituição tem professores, turmas e alunos". Depende da etapa 2
(`institutions`, `institution_members`) e usa o arquivo de excluídos da etapa 1.

## Decisões

| Tema | Decisão |
|---|---|
| Pertence a | Uma instituição. Nome único dentro da instituição. |
| Quem cria | Professor vinculado à instituição, ou admin. Quem cria (se professor) vira responsável. |
| Quem edita | Professores **responsáveis** pela turma e o admin: renomear, vincular/remover alunos e responsáveis, excluir. |
| Quem vê | Todo professor da instituição vê as turmas dela (só leitura se não for responsável). Admin vê todas. |
| Quem entra | Alunos da mesma instituição (aluno pode estar em várias turmas). Responsável precisa ser professor da instituição. |
| Aluno | Vê "Minhas turmas" no dashboard (nome da turma, instituição, professores). Nada mais muda. |
| Exclusões | Pelo `Archiver`: excluir turma leva os vínculos; excluir instituição leva turmas e vínculos das turmas (o `Archiver` passa a ser recursivo); excluir usuário leva os vínculos de turma dele. |
| Consistência | Remover alguém da instituição arquiva também os vínculos dele nas turmas dela. Trocar o papel (professor ↔ aluno, ou promover a admin) arquiva os vínculos de turma da pessoa. |

## Modelo de dados

```sql
CREATE TABLE classes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  institution_id INT NOT NULL,
  name           VARCHAR(120) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_class_name (institution_id, name),
  CONSTRAINT fk_class_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
);

CREATE TABLE class_members (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  class_id   INT NOT NULL,
  user_id    INT NOT NULL,
  role       ENUM('professor','aluno') NOT NULL,   -- professor = responsável pela turma
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_class_member (class_id, user_id),
  CONSTRAINT fk_class_member_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_class_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

## Componentes

- `App\Support\ClassAccess` (pura): `canView(Role, viewerInstitutionIds, classInstitutionId)`,
  `canEdit(Role, viewerId, responsibleIds)`.
- `App\Models\SchoolClass` (+ entidade) e `App\Models\ClassMember`: consultas. ("Class" é palavra
  reservada em PHP.)
- `App\Services\ClassManager`: criar, renomear, vincular/remover membro (valida instituição e
  papel), excluir (via `Archiver`), arquivar vínculos de um usuário numa instituição ou em todas.
- Arquivo: `ArchiveGraph` ganha `class` (filho `class_member`) e `class_member`; `institution`
  passa a ter filho `class`; `user` ganha filho `class_member`. `Archiver::archive` coleta filhos
  recursivamente. `ArchiveRestoreChecks` confere nome da turma na instituição, existência da
  turma/instituição fora do lote e o vínculo do usuário com a instituição.
- `InstitutionManager::removeMember` e `syncRoleChange` passam a arquivar os vínculos de turma.

## Telas

- `/turmas` (professor e admin): turmas visíveis agrupadas por instituição; criar turma (professor
  escolhe entre as instituições dele; admin entre todas).
- `/turmas/{id}`: renomear, alunos e responsáveis com "remover", "adicionar" (e-mail ou username;
  o papel vem da conta), excluir. Para quem não é responsável: só leitura.
- `/admin/instituicoes/{id}`: lista as turmas da instituição com link.
- Dashboard do aluno: "Minhas turmas".
- Menu: "Turmas" para professor e admin.

## Auditoria

`class.created`, `class.renamed`, `class.member_added`, `class.member_removed`, `class.deleted`
(os dois últimos via `Archiver`, com `batch`).

## Testes

- Unitários: `ClassAccess`, `ArchiveGraph` (novos modelos e hierarquia), checagens novas de restauração.
- Integração: aluno de outra instituição recusado; responsável precisa ser professor da instituição;
  arquivar instituição leva turmas e vínculos e restaura tudo; remover aluno da instituição tira das
  turmas; troca de papel arquiva vínculos de turma; professor não-responsável não edita.
- Navegador: professor cria turma, vincula aluno; aluno vê "Minhas turmas".

## Fora do escopo

Código de turma; atividades/notas por turma; schemas compartilhados por turma.
