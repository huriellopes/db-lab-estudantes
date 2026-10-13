# Desenhando um DER no lab de modelagem

- **Arquivo:** `modelagem-er-der-no-lab` (`.mp4`, `.webp`, `.vtt`)
- **Duração alvo:** 2:00 · 186 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/modelagem-er`
- **Objetivo:** O aluno sai sabendo criar entidades, marcar a chave primária, ligar um relacionamento N:N e gerar o SQL das tabelas.
- **Antes de gravar:** Logado como aluno, com o laboratório de modelagem (/laboratorio/modelagem) aberto e nenhum diagrama na tela. Zoom do navegador em 125% para o texto ficar legível em 720p.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:00–0:08 | Tela do laboratório de modelagem vazia. | Antes de criar tabelas, a gente desenha o banco. Vamos montar um diagrama de alunos e cursos aqui no laboratório de modelagem. |
| 0:08–0:20 | Clique em "Novo diagrama", digite "Escola" no nome. | Clique em Novo diagrama e dê um nome pra ele. Eu vou chamar de Escola. |
| 0:20–0:38 | "Nova entidade" → renomeie para aluno. Clique em "+ atributo" três vezes: id, nome, email. | Cada coisa importante do sistema vira uma entidade. A primeira é aluno. Agora os atributos: o que eu quero guardar sobre cada aluno? Id, nome e e-mail. |
| 0:38–0:50 | Clique no ícone de chave ao lado de id ("Marcar como chave primária"). | O id identifica cada aluno sem repetir. Por isso ele é a chave primária: é só clicar na chave. |
| 0:50–1:04 | Crie a entidade curso com id (chave), nome e carga_horaria. Arraste as caixas lado a lado. | Mesma coisa pro curso: id como chave, nome e carga horária. Posso arrastar as caixas pra organizar. |
| 1:04–1:24 | Clique em "Relacionamento", depois em aluno e em curso. Escreva "matricula". Clique nas cardinalidades até ficar N e N. | Agora o relacionamento. Clique em Relacionamento, depois na origem, aluno, e no destino, curso. Um aluno faz vários cursos e um curso tem vários alunos: então é N pra N. |
| 1:24–1:44 | Clique em "Gerar SQL". Destaque com o cursor a tabela associativa no final. | Clicando em Gerar SQL, o laboratório escreve o CREATE TABLE de cada entidade. Repare no final: o N pra N virou uma tabela nova, com as duas chaves estrangeiras. |
| 1:44–2:00 | Clique em "Abrir no console SQL →". Mostre o SQL já colado no console. | Com Abrir no console SQL, esse código vai direto pro console, pronto pra criar as tabelas no seu schema. No próximo vídeo, a gente cria esse schema. |

> A legenda `legendas/modelagem-er-der-no-lab.vtt` sai da coluna Narração com estes tempos. Se a gravação ficar mais curta ou mais longa, ajuste os tempos no editor (ou peça pra regenerar).
