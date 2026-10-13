# SELECT e WHERE em 2 minutos

- **Arquivo:** `sql-ansi-select-where` (`.mp4`, `.webp`, `.vtt`)
- **Duração alvo:** 2:00 · 142 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/sql-ansi`
- **Objetivo:** O aluno escreve SELECT com colunas e WHERE, e entende por que NULL não se compara com =.
- **Antes de gravar:** Dados de exemplo do guia de SQL ANSI (curso, aluno, matricula) já criados no schema. Console aberto.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:00–0:10 | Console com o schema escolhido. | O SELECT é o comando que você mais vai usar: ele pergunta coisas pro banco. |
| 0:10–0:24 | Rode: SELECT * FROM aluno; | O asterisco quer dizer todas as colunas. Aqui estão os cinco alunos dos exemplos do guia. |
| 0:24–0:38 | Rode: SELECT nome, cidade FROM aluno; | Normalmente você escolhe só as colunas que importam. Fica mais fácil de ler e mais rápido. |
| 0:38–0:56 | Rode: SELECT nome, cidade FROM aluno WHERE cidade = 'Recife'; | O WHERE filtra as linhas. O banco testa a condição em cada uma e só devolve as que deram verdadeiro: a Ana e a Carla. |
| 0:56–1:16 | Rode: ... WHERE cidade <> 'Recife'; Aponte que o Diego não apareceu. | E quem não é de Recife? Bruno e Elisa. Mas cadê o Diego? A cidade dele está vazia, é NULL. Comparar NULL com qualquer coisa dá desconhecido, e o WHERE descarta. |
| 1:16–1:32 | Rode: SELECT nome FROM aluno WHERE cidade IS NULL; | Pra achar valores vazios, use IS NULL. Nunca igual NULL. |
| 1:32–2:00 | Abra /guia/sql-ansi, seção "Filtrando com WHERE". Mostre a animação "Veja o WHERE trabalhando" com a condição <> passo a passo. | No guia tem uma animação que mostra esse teste linha por linha. Troque a condição e veja o que acontece com o Diego. Depois, pratique os exercícios da página. |

> A legenda `legendas/sql-ansi-select-where.vtt` sai da coluna Narração com estes tempos. Se a gravação ficar mais curta ou mais longa, ajuste os tempos no editor (ou peça pra regenerar).
