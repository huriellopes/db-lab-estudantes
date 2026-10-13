# JOIN na prática

- **Arquivo:** `sql-ansi-join` (`.mp4`, `.webp`, `.vtt`)
- **Duração alvo:** 2:00 · 161 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/sql-ansi`
- **Objetivo:** O aluno entende para que serve o JOIN, escreve um INNER JOIN e vê a diferença para o LEFT JOIN.
- **Antes de gravar:** Mesmos dados do vídeo de SELECT/WHERE. Guia de SQL ANSI aberto no nível Intermediário, em outra aba.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:00–0:14 | Rode: SELECT * FROM matricula; | A tabela matrícula só tem números: o id do aluno e o id do curso. Quem é o aluno 1? Pra saber, a gente junta as tabelas. |
| 0:14–0:36 | Digite e rode, linha por linha: SELECT a.nome, m.curso_id FROM matricula m INNER JOIN aluno a ON a.id = m.aluno_id; | O JOIN junta as tabelas e o ON diz como elas se encaixam: o id do aluno é igual ao aluno id da matrícula. O m e o a são apelidos, pra não escrever o nome inteiro. |
| 0:36–0:52 | Mostre o resultado com nomes. | Pronto: cada matrícula agora aparece com o nome do aluno. |
| 0:52–1:10 | Abra a animação "Veja o JOIN juntando as tabelas" no guia (nível Intermediário). Avance com ▶ Próximo até o Diego. | Veja o que o banco faz por dentro: pra cada aluno, ele procura as matrículas com o mesmo id. O Diego não tem nenhuma matrícula. No INNER JOIN, ele fica de fora. |
| 1:10–1:30 | Troque para LEFT JOIN na animação e avance até o Diego (linha NULL em amarelo). | Com LEFT JOIN, todos os alunos da tabela da esquerda aparecem. Quem não tem par vem com NULL. |
| 1:30–1:48 | No console: SELECT a.nome FROM aluno a LEFT JOIN matricula m ON m.aluno_id = a.id WHERE m.id IS NULL; | Esse truque é clássico: LEFT JOIN mais IS NULL encontra quem não tem. Aqui, os alunos sem matrícula. |
| 1:48–2:00 | Volte ao guia e mostre o exemplo com dois JOINs (aluno e curso). | No guia tem o exemplo completo, juntando aluno, matrícula e curso. Teste no console e brinque com a animação. |

> A legenda `legendas/sql-ansi-join.vtt` sai da coluna Narração com estes tempos. Se a gravação ficar mais curta ou mais longa, ajuste os tempos no editor (ou peça pra regenerar).
