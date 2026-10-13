# SELECT e WHERE: filtrando dados

- **Arquivo:** `sql-ansi-select-where` (`.mp4`, `.webp`, `.vtt`)
- **Duração:** 2:35 · 322 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/sql-ansi`
- **Objetivo:** O aluno escreve SELECT com colunas e WHERE, e entende por que NULL não se compara com =.
- **Antes de gravar:** Dados de exemplo do guia de SQL ANSI (curso, aluno, matricula) já criados no schema. Console aberto.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:03–0:22 | Console com o schema escolhido. | O SELECT é o comando que você mais vai usar no SQL. Ele serve pra fazer perguntas pro banco, e a resposta sempre volta como uma tabela. Vamos praticar com os dados de exemplo do guia de SQL ANSI: alunos, cursos e matrículas. |
| 0:22–0:41 | Rode: SELECT * FROM aluno; | A pergunta mais simples é: me mostre tudo da tabela aluno. SELECT asterisco FROM aluno. O asterisco quer dizer todas as colunas. Aqui estão os cinco alunos, com nome, e-mail, cidade e data de nascimento. |
| 0:41–1:00 | Rode: SELECT nome, cidade FROM aluno; | Na maioria das vezes, você não precisa de todas as colunas. Então, no lugar do asterisco, escreva só as que interessam, separadas por vírgula: nome e cidade. O resultado fica mais limpo e mais fácil de ler. |
| 1:00–1:21 | Rode: SELECT nome, cidade FROM aluno WHERE cidade = 'Recife'; | E se eu quiser só os alunos de Recife? É pra isso que serve o WHERE: ele filtra as linhas. O banco testa a condição em cada linha e só devolve as que deram verdadeiro. Aqui, a Ana e a Carla. Repare que o texto Recife vai entre aspas simples. |
| 1:21–1:39 | Rode: ... WHERE cidade <> 'Recife'; Aponte que o Diego não apareceu. | Agora o contrário: quem não é de Recife. No SQL, o sinal de diferente é o menor e o maior juntos. Vieram o Bruno e a Elisa. Mas espera aí: cadê o Diego? Ele também não é de Recife. |
| 1:39–2:01 | Abra /guia/sql-ansi, seção "Filtrando com WHERE". Na animação, escolha a condição <> e avance até o Diego. | Essa animação do guia mostra o teste linha por linha. A cidade do Diego está vazia: é NULL. E NULL não é um valor, é a falta de valor. Comparar NULL com qualquer coisa não dá verdadeiro nem falso: dá desconhecido. E o WHERE só deixa passar o que é verdadeiro. |
| 2:01–2:18 | No console, rode: SELECT nome FROM aluno WHERE cidade IS NULL; | Então, pra encontrar valores vazios, use IS NULL. Nunca igual NULL. Agora sim: o Diego aparece. E pro contrário, quem tem a cidade preenchida, existe o IS NOT NULL. |
| 2:18–2:35 | Volte ao guia e mostre os exercícios da página. | Resumindo: o SELECT escolhe as colunas, o FROM diz de qual tabela, e o WHERE filtra as linhas. No guia tem essa animação e vários exercícios. Experimente trocar a condição e ver o que acontece com o Diego! |

> A legenda `legendas/sql-ansi-select-where.vtt` sai da coluna Narração com os tempos medidos na gravação gerada (`gerar-videos/`, que escreve `tempos.json`).
