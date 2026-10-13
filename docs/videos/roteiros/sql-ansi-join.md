# JOIN na prática

- **Arquivo:** `sql-ansi-join` (`.mp4`, `.webp`, `.vtt`)
- **Duração:** 2:25 · 306 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/sql-ansi`
- **Objetivo:** O aluno entende para que serve o JOIN, escreve um INNER JOIN e vê a diferença para o LEFT JOIN.
- **Antes de gravar:** Mesmos dados do vídeo de SELECT/WHERE. Guia de SQL ANSI aberto no nível Intermediário, em outra aba.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:03–0:23 | Rode: SELECT * FROM matricula; | Olhe a tabela matrícula. Ela só tem números: o id do aluno, o id do curso, a data e a nota. Isso é de propósito: guardar só o id evita repetir o nome do aluno em todo lugar. Mas aí fica a pergunta: quem é o aluno número 1? |
| 0:23–0:41 | Digite, linha por linha: SELECT a.nome, m.curso_id FROM matricula m INNER JOIN aluno a ON a.id = m.aluno_id; | Pra responder, a gente junta as tabelas com o JOIN. Na primeira linha, escolho as colunas: o nome do aluno e o id do curso. Depois, digo que parto da tabela matrícula, e junto com a tabela aluno. |
| 0:41–0:59 | Destaque o ON e os apelidos m e a. | O ON é a parte mais importante: ele diz como as linhas se encaixam. Aqui, o id do aluno tem que ser igual ao aluno id da matrícula. E esse m e esse a são apelidos das tabelas, pra não escrever o nome inteiro toda vez. |
| 0:59–1:12 | Execute e mostre o resultado com nomes. | Executando... pronto! Cada matrícula agora aparece com o nome do aluno. A Ana aparece duas vezes, porque ela está em dois cursos. |
| 1:12–1:33 | Abra a animação "Veja o JOIN juntando as tabelas" (nível Intermediário). Avance com ▶ Próximo até o Diego. | Pra entender o que acontece por dentro, o guia tem uma animação. Pra cada aluno, o banco procura as matrículas com o mesmo id e junta as linhas. Vou avançar até o Diego. Ele não tem nenhuma matrícula, então, no INNER JOIN, ele fica de fora. |
| 1:33–1:49 | Troque para LEFT JOIN na animação e avance até o Diego (linha NULL). | Agora troque pra LEFT JOIN. Nele, todos os alunos da tabela da esquerda aparecem, mesmo quem não tem par. Olha o Diego: ele entrou, e as colunas da matrícula vieram como NULL. |
| 1:49–2:04 | No console: SELECT a.nome FROM aluno a LEFT JOIN matricula m ON m.aluno_id = a.id WHERE m.id IS NULL; | Isso abre um truque muito usado: LEFT JOIN mais IS NULL encontra quem não tem par. Aqui, os alunos sem nenhuma matrícula. E o resultado é exatamente o Diego. |
| 2:04–2:25 | Volte ao guia e mostre o exemplo com dois JOINs (aluno, matrícula e curso). | No guia tem o exemplo completo, juntando três tabelas com dois JOINs: aluno, matrícula e curso. Resumindo: o INNER JOIN traz só quem tem par, e o LEFT JOIN traz todo mundo da tabela da esquerda. Teste no console e brinque com a animação! |

> A legenda `legendas/sql-ansi-join.vtt` sai da coluna Narração com os tempos medidos na gravação gerada (`gerar-videos/`, que escreve `tempos.json`).
