# Desenhando um DER no lab de modelagem

- **Arquivo:** `modelagem-er-der-no-lab` (`.mp4`, `.webp`, `.vtt`)
- **Duração:** 3:18 · 417 palavras de narração (ritmo calmo, com pausas pra tela respirar)
- **Tópico do guia:** `/guia/modelagem-er`
- **Objetivo:** O aluno sai sabendo criar entidades, entender a chave primária, ligar um relacionamento N:N e gerar o SQL das tabelas.
- **Antes de gravar:** Logado como aluno, com o laboratório de modelagem (/laboratorio/modelagem) aberto e nenhum diagrama na tela. Zoom do navegador em 125% para o texto ficar legível.

| Tempo | Na tela (o que gravar) | Narração |
|---|---|---|
| 0:03–0:24 | Laboratório de modelagem com o quadro vazio. | Antes de sair criando tabelas, vale a pena desenhar o banco. Esse desenho se chama DER, o diagrama entidade-relacionamento. Ele mostra o que o sistema precisa guardar e como essas coisas se ligam. Vamos montar o exemplo de uma escola, com alunos e cursos. |
| 0:24–0:39 | Digite "Escola" no campo "Nome do diagrama". | Primeiro, dê um nome pro diagrama, aqui nesse campo ao lado do botão Salvar. Vou chamar de Escola. Com um nome, dá pra salvar e abrir o desenho de novo outro dia. |
| 0:39–0:55 | Clique em "Nova entidade" e renomeie a caixa para aluno. | Agora clique em Nova entidade. Uma entidade é algo importante que o sistema guarda, e no banco ela vai virar uma tabela. A nossa primeira entidade é aluno: é só clicar no nome da caixa e digitar. |
| 0:55–1:10 | Destaque o atributo id com o selo PK. | Repare que a caixa já vem com um atributo chamado id, marcado como PK, a chave primária. A chave primária identifica cada aluno sem nunca repetir, como o número de uma matrícula. |
| 1:10–1:29 | Clique em "+ atributo" duas vezes: nome e email. | Agora os atributos, que são as informações de cada aluno. Clique em mais atributo e escreva nome. Mais um: e-mail. Do lado, fica o tipo do dado. Os dois são texto, então o VARCHAR que já vem marcado serve. |
| 1:29–1:51 | Crie a entidade curso com nome e carga_horaria (tipo INT). Arraste a caixa para a direita. | A segunda entidade é curso, do mesmo jeito: Nova entidade, o nome curso, e os atributos nome e carga horária. Carga horária é um número, então troco o tipo pra INT. E arrasto a caixa pro lado, pra sobrar espaço pra linha que vem agora. |
| 1:51–2:11 | Clique em "+ Relacionamento", depois em aluno e em curso. Escreva "matricula" no rótulo. | Agora vamos ligar as duas. Clique em Relacionamento, depois na entidade de origem, aluno, e na de destino, curso. Apareceu uma linha entre elas. No meio da linha, escrevo o nome do relacionamento: matrícula. |
| 2:11–2:34 | Clique no "1" da ponta de aluno até virar N (N e N). | Nas pontas da linha fica a cardinalidade: quantos de um lado se ligam com quantos do outro. Pense assim: um aluno pode fazer vários cursos, e um curso tem vários alunos. Então os dois lados são N. Clico no 1, e ele vira N. Isso é um relacionamento N pra N. |
| 2:34–3:01 | Clique em "Salvar" e depois em "Gerar SQL". Destaque a tabela associativa no final. | Clique em Salvar pra guardar o desenho, e depois em Gerar SQL. O laboratório escreve o CREATE TABLE de cada entidade, já com a chave primária. E olha o final: o N pra N virou uma tabela nova, a tabela associativa, com duas chaves estrangeiras, uma apontando pra aluno e outra pra curso. É assim que o banco guarda quem está matriculado em qual curso. |
| 3:01–3:18 | Clique em "Abrir no console SQL →". Mostre o SQL já no console. | Pra criar essas tabelas de verdade, clique em Abrir no console SQL. O código vai direto pro console, pronto pra rodar. Só falta um schema pra guardar tudo, e é isso que a gente faz no próximo vídeo. |

> A legenda `legendas/modelagem-er-der-no-lab.vtt` sai da coluna Narração com os tempos medidos na gravação gerada (`gerar-videos/`, que escreve `tempos.json`).
