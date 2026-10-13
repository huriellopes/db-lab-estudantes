# Usando o console SQL

- **Arquivo:** `mysql-console-sql` (`.mp4`, `.webp`, `.vtt`)
- **Duração:** 2:26 · 300 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/mysql`
- **Objetivo:** O aluno roda comandos no console, entende "Executar tudo" x "Executar seleção", lê erros e salva uma consulta.
- **Antes de gravar:** Schema "escola" criado. Console vazio. Tenha à mão o bloco "Dados de exemplo — loja" do guia de MySQL.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:03–0:20 | Card "Console SQL" do painel. | O console SQL é o lugar onde você conversa com o banco de dados. Você escreve um comando, manda executar, e o MySQL responde na hora. Tudo pelo navegador, sem instalar nada no computador. |
| 0:20–0:33 | Escolha "escola" no seletor Schema. | O primeiro passo é escolher em qual schema os comandos vão rodar. É aqui, no campo Schema. Vou usar o escola, que a gente criou no vídeo anterior. |
| 0:33–0:58 | Cole o bloco de dados de exemplo do guia (CREATE TABLE + INSERT). Clique em "Executar tudo". | Agora vou colar os dados de exemplo do guia de MySQL. São vários comandos: o CREATE TABLE cria as tabelas, e o INSERT coloca as linhas dentro delas. Cada comando termina com ponto e vírgula, e é assim que o console sabe onde um acaba e o outro começa. Clique em Executar tudo, e eles rodam um depois do outro. |
| 0:58–1:22 | Digite SELECT * FROM cliente; numa linha nova. Selecione só essa linha: o botão vira "Executar seleção". Ctrl+Enter. | Agora uma consulta. Numa linha nova, escrevo SELECT asterisco FROM cliente. Se eu selecionar só essa linha, olha o botão: ele muda pra Executar seleção. Assim, só o trecho selecionado roda, e o resto fica guardado. Dá pra usar o atalho também: Ctrl mais Enter. |
| 1:22–1:32 | Mostre a tabela de resultado. | O resultado aparece numa tabela, logo abaixo, com uma linha pra cada cliente e uma coluna pra cada campo. |
| 1:32–1:55 | Erre de propósito: SELECT * FROM clientes; e mostre a mensagem de erro. Corrija para cliente e rode de novo. | E se eu errar? Vou escrever clientes, no plural, e essa tabela não existe. O console mostra a mensagem de erro do próprio MySQL. Não se assuste com o erro: leia com calma, porque ele quase sempre diz exatamente o que está errado. Corrijo o nome, e tudo volta a funcionar. |
| 1:55–2:12 | Clique em "Salvar comando", digite "todos os clientes" e clique em "Salvar". Mostre em "Consultas salvas" e clique pra recarregar. | Por último: a consulta que você usa sempre pode ser salva. Clique em Salvar comando, dê um nome, e confirme em Salvar. Ela fica na lista Consultas salvas, e um clique carrega o comando de volta no console. |
| 2:12–2:26 | Console com o resultado na tela. | Resumindo: escolha o schema, escreva o comando, execute tudo ou só a seleção, e leia o resultado, ou o erro, com atenção. Agora é praticar! |

> A legenda `legendas/mysql-console-sql.vtt` sai da coluna Narração com os tempos medidos na gravação gerada (`gerar-videos/`, que escreve `tempos.json`).
