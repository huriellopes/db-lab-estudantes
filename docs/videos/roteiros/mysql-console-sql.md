# Usando o console SQL

- **Arquivo:** `mysql-console-sql` (`.mp4`, `.webp`, `.vtt`)
- **Duração alvo:** 1:30 · 112 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/mysql`
- **Objetivo:** O aluno roda comandos no console, entende "Executar tudo" x "Executar seleção" e salva uma consulta.
- **Antes de gravar:** Schema "escola" criado. Console vazio. Tenha à mão o bloco "Dados de exemplo — loja" do guia de MySQL.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:00–0:10 | Card "Console SQL" do painel. | O console SQL roda comandos direto no seu schema, sem instalar nada no computador. |
| 0:10–0:22 | Escolha "escola" no seletor Schema. | Primeiro, escolha em qual schema os comandos vão rodar. |
| 0:22–0:40 | Cole o bloco de dados de exemplo do guia (CREATE TABLE + INSERT). Clique em "Executar tudo". | Vou colar os dados de exemplo do guia. Vários comandos separados por ponto e vírgula rodam em sequência. Executar tudo. |
| 0:40–0:56 | Digite SELECT * FROM cliente; numa linha nova. Selecione só essa linha: o botão vira "Executar seleção". Ctrl+Enter. | Agora uma consulta. Se eu selecionar só um trecho, o botão vira Executar seleção e roda só ele. Ctrl mais Enter também funciona. |
| 0:56–1:10 | Mostre a tabela de resultado. Erre de propósito: SELECT * FROM clientes; e mostre a mensagem de erro. | O resultado aparece em tabela. E se eu errar o nome da tabela, o console mostra o erro do MySQL: leia com calma, ele diz o que faltou. |
| 1:10–1:30 | Clique em "Salvar comando", digite "todos os clientes" e clique em "Salvar". Mostre em "Consultas salvas" e clique pra recarregar. | Consultas que você usa sempre podem ser salvas. Elas ficam em Consultas salvas, a um clique de distância. |

> A legenda `legendas/mysql-console-sql.vtt` sai da coluna Narração com estes tempos. Se a gravação ficar mais curta ou mais longa, ajuste os tempos no editor (ou peça pra regenerar).
