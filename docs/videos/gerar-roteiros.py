"""Fonte única dos roteiros das vídeo-aulas do guia: gera roteiros/*.md e legendas/*.vtt.

Edite as cenas aqui (tempo inicial, tempo final, o que gravar, narração) e rode:
    python3 docs/videos/gerar-roteiros.py
Assim a legenda nunca fica diferente do roteiro. Ver docs/videos/README.md.
"""
import os, sys, textwrap
out = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))

V = [
 dict(arquivo='modelagem-er-der-no-lab', titulo='Desenhando um DER no lab de modelagem', topico='/guia/modelagem-er',
  objetivo='O aluno sai sabendo criar entidades, marcar a chave primária, ligar um relacionamento N:N e gerar o SQL das tabelas.',
  preparo='Logado como aluno, com o laboratório de modelagem (/laboratorio/modelagem) aberto e nenhum diagrama na tela. Zoom do navegador em 125% para o texto ficar legível em 720p.',
  cenas=[
   (0,8,'Tela do laboratório de modelagem vazia.','Antes de criar tabelas, a gente desenha o banco. Vamos montar um diagrama de alunos e cursos aqui no laboratório de modelagem.'),
   (8,20,'Clique em "Novo diagrama", digite "Escola" no nome.','Clique em Novo diagrama e dê um nome pra ele. Eu vou chamar de Escola.'),
   (20,38,'"Nova entidade" → renomeie para aluno. Clique em "+ atributo" três vezes: id, nome, email.','Cada coisa importante do sistema vira uma entidade. A primeira é aluno. Agora os atributos: o que eu quero guardar sobre cada aluno? Id, nome e e-mail.'),
   (38,50,'Clique no ícone de chave ao lado de id ("Marcar como chave primária").','O id identifica cada aluno sem repetir. Por isso ele é a chave primária: é só clicar na chave.'),
   (50,64,'Crie a entidade curso com id (chave), nome e carga_horaria. Arraste as caixas lado a lado.','Mesma coisa pro curso: id como chave, nome e carga horária. Posso arrastar as caixas pra organizar.'),
   (64,84,'Clique em "Relacionamento", depois em aluno e em curso. Escreva "matricula". Clique nas cardinalidades até ficar N e N.','Agora o relacionamento. Clique em Relacionamento, depois na origem, aluno, e no destino, curso. Um aluno faz vários cursos e um curso tem vários alunos: então é N pra N.'),
   (84,104,'Clique em "Gerar SQL". Destaque com o cursor a tabela associativa no final.','Clicando em Gerar SQL, o laboratório escreve o CREATE TABLE de cada entidade. Repare no final: o N pra N virou uma tabela nova, com as duas chaves estrangeiras.'),
   (104,120,'Clique em "Abrir no console SQL →". Mostre o SQL já colado no console.','Com Abrir no console SQL, esse código vai direto pro console, pronto pra criar as tabelas no seu schema. No próximo vídeo, a gente cria esse schema.'),
  ]),
 dict(arquivo='mysql-primeiro-schema', titulo='Criando seu primeiro schema', topico='/guia/mysql',
  objetivo='O aluno sabe o que é um schema, cria o primeiro pelo painel e encontra ele na lista.',
  preparo='Conta de aluno sem nenhum schema, no /dashboard. Esconda o e-mail pessoal se aparecer no topo.',
  cenas=[
   (0,10,'Painel (/dashboard) com "Você ainda não criou nenhum schema."','Pra guardar tabelas você precisa de um schema. Pense nele como uma pasta onde ficam as tabelas de um projeto.'),
   (10,26,'Cursor no card "Criar novo schema". Destaque o prefixo antes do campo.','No painel, use o card Criar novo schema. Esse começo do nome é o seu prefixo: ele evita conflito com schemas de outras pessoas do laboratório.'),
   (26,42,'Digite "escola" no campo "Nome do schema". Tente digitar com espaço e mostre a mensagem.','Escolha um nome curto, só com letras, números e sublinhado. Espaço não vale. Vou usar escola.'),
   (42,56,'Clique em "Criar schema". O schema aparece em "Meus schemas".','Clique em Criar schema. Pronto: ele já aparece em Meus schemas.'),
   (56,74,'Mostre o seletor "Schema" do Console SQL com o schema novo selecionado.','E já pode ser escolhido no console SQL, logo abaixo. É lá que você vai criar as tabelas e consultar os dados.'),
   (74,90,'Volte ao guia de MySQL, mostre o bloco "Prepare os dados de exemplo" e o botão "▶ Testar no console".','Os exemplos do guia têm o botão Testar no console. Ele leva o código direto pro console, pronto pra rodar no schema que você acabou de criar.'),
  ]),
 dict(arquivo='mysql-console-sql', titulo='Usando o console SQL', topico='/guia/mysql',
  objetivo='O aluno roda comandos no console, entende "Executar tudo" x "Executar seleção" e salva uma consulta.',
  preparo='Schema "escola" criado. Console vazio. Tenha à mão o bloco "Dados de exemplo — loja" do guia de MySQL.',
  cenas=[
   (0,10,'Card "Console SQL" do painel.','O console SQL roda comandos direto no seu schema, sem instalar nada no computador.'),
   (10,22,'Escolha "escola" no seletor Schema.','Primeiro, escolha em qual schema os comandos vão rodar.'),
   (22,40,'Cole o bloco de dados de exemplo do guia (CREATE TABLE + INSERT). Clique em "Executar tudo".','Vou colar os dados de exemplo do guia. Vários comandos separados por ponto e vírgula rodam em sequência. Executar tudo.'),
   (40,56,'Digite SELECT * FROM cliente; numa linha nova. Selecione só essa linha: o botão vira "Executar seleção". Ctrl+Enter.','Agora uma consulta. Se eu selecionar só um trecho, o botão vira Executar seleção e roda só ele. Ctrl mais Enter também funciona.'),
   (56,70,'Mostre a tabela de resultado. Erre de propósito: SELECT * FROM clientes; e mostre a mensagem de erro.','O resultado aparece em tabela. E se eu errar o nome da tabela, o console mostra o erro do MySQL: leia com calma, ele diz o que faltou.'),
   (70,90,'Clique em "Salvar comando", digite "todos os clientes" e clique em "Salvar". Mostre em "Consultas salvas" e clique pra recarregar.','Consultas que você usa sempre podem ser salvas. Elas ficam em Consultas salvas, a um clique de distância.'),
  ]),
 dict(arquivo='sql-ansi-select-where', titulo='SELECT e WHERE em 2 minutos', topico='/guia/sql-ansi',
  objetivo='O aluno escreve SELECT com colunas e WHERE, e entende por que NULL não se compara com =.',
  preparo='Dados de exemplo do guia de SQL ANSI (curso, aluno, matricula) já criados no schema. Console aberto.',
  cenas=[
   (0,10,'Console com o schema escolhido.','O SELECT é o comando que você mais vai usar: ele pergunta coisas pro banco.'),
   (10,24,'Rode: SELECT * FROM aluno;','O asterisco quer dizer todas as colunas. Aqui estão os cinco alunos dos exemplos do guia.'),
   (24,38,'Rode: SELECT nome, cidade FROM aluno;','Normalmente você escolhe só as colunas que importam. Fica mais fácil de ler e mais rápido.'),
   (38,56,"Rode: SELECT nome, cidade FROM aluno WHERE cidade = 'Recife';",'O WHERE filtra as linhas. O banco testa a condição em cada uma e só devolve as que deram verdadeiro: a Ana e a Carla.'),
   (56,76,"Rode: ... WHERE cidade <> 'Recife'; Aponte que o Diego não apareceu.",'E quem não é de Recife? Bruno e Elisa. Mas cadê o Diego? A cidade dele está vazia, é NULL. Comparar NULL com qualquer coisa dá desconhecido, e o WHERE descarta.'),
   (76,92,'Rode: SELECT nome FROM aluno WHERE cidade IS NULL;','Pra achar valores vazios, use IS NULL. Nunca igual NULL.'),
   (92,120,'Abra /guia/sql-ansi, seção "Filtrando com WHERE". Mostre a animação "Veja o WHERE trabalhando" com a condição <> passo a passo.','No guia tem uma animação que mostra esse teste linha por linha. Troque a condição e veja o que acontece com o Diego. Depois, pratique os exercícios da página.'),
  ]),
 dict(arquivo='sql-ansi-join', titulo='JOIN na prática', topico='/guia/sql-ansi',
  objetivo='O aluno entende para que serve o JOIN, escreve um INNER JOIN e vê a diferença para o LEFT JOIN.',
  preparo='Mesmos dados do vídeo de SELECT/WHERE. Guia de SQL ANSI aberto no nível Intermediário, em outra aba.',
  cenas=[
   (0,14,'Rode: SELECT * FROM matricula;','A tabela matrícula só tem números: o id do aluno e o id do curso. Quem é o aluno 1? Pra saber, a gente junta as tabelas.'),
   (14,36,'Digite e rode, linha por linha: SELECT a.nome, m.curso_id FROM matricula m INNER JOIN aluno a ON a.id = m.aluno_id;','O JOIN junta as tabelas e o ON diz como elas se encaixam: o id do aluno é igual ao aluno id da matrícula. O m e o a são apelidos, pra não escrever o nome inteiro.'),
   (36,52,'Mostre o resultado com nomes.','Pronto: cada matrícula agora aparece com o nome do aluno.'),
   (52,70,'Abra a animação "Veja o JOIN juntando as tabelas" no guia (nível Intermediário). Avance com ▶ Próximo até o Diego.','Veja o que o banco faz por dentro: pra cada aluno, ele procura as matrículas com o mesmo id. O Diego não tem nenhuma matrícula. No INNER JOIN, ele fica de fora.'),
   (70,90,'Troque para LEFT JOIN na animação e avance até o Diego (linha NULL em amarelo).','Com LEFT JOIN, todos os alunos da tabela da esquerda aparecem. Quem não tem par vem com NULL.'),
   (90,108,'No console: SELECT a.nome FROM aluno a LEFT JOIN matricula m ON m.aluno_id = a.id WHERE m.id IS NULL;','Esse truque é clássico: LEFT JOIN mais IS NULL encontra quem não tem. Aqui, os alunos sem matrícula.'),
   (108,120,'Volte ao guia e mostre o exemplo com dois JOINs (aluno e curso).','No guia tem o exemplo completo, juntando aluno, matrícula e curso. Teste no console e brinque com a animação.'),
  ]),
]

def ts(s, vtt=True):
    m, s = divmod(s, 60)
    return f'{m:02d}:{s:02d}.000' if vtt else f'{m}:{s:02d}'

def chunks(text, maxlen=84):
    """Quebra a narração em blocos de legenda (até 2 linhas de ~42 caracteres)."""
    words, out, cur = text.split(), [], ''
    for w in words:
        if len(cur) + len(w) + 1 > maxlen and cur:
            out.append(cur); cur = w
        else:
            cur = f'{cur} {w}'.strip()
    if cur: out.append(cur)
    return out

os.makedirs(f'{out}/roteiros', exist_ok=True); os.makedirs(f'{out}/legendas', exist_ok=True)
for v in V:
    total = v['cenas'][-1][1]
    palavras = sum(len(c[3].split()) for c in v['cenas'])
    md = [f"# {v['titulo']}", '',
          f"- **Arquivo:** `{v['arquivo']}` (`.mp4`, `.webp`, `.vtt`)",
          f"- **Duração alvo:** {ts(total, False)} · {palavras} palavras de narração (ritmo calmo, com pausas pra tela respirar)",
          f"- **Tópico do guia:** `{v['topico']}`",
          f"- **Objetivo:** {v['objetivo']}",
          f"- **Antes de gravar:** {v['preparo']}", '',
          '| Tempo | Na tela (o que gravar) | Narração |', '|---|---|---|']
    vtt = ['WEBVTT', '']
    for (a, b, tela, fala) in v['cenas']:
        md.append(f"| {ts(a, False)}–{ts(b, False)} | {tela.replace('|', '\\|')} | {fala} |")
        partes = chunks(fala)
        dur = (b - a) / len(partes)
        for i, p in enumerate(partes):
            ini, fim = a + dur * i, a + dur * (i + 1)
            f = lambda x: f'{int(x // 60):02d}:{x % 60:06.3f}'
            linhas = textwrap.wrap(p, 42)
            vtt += [f'{f(ini)} --> {f(fim)}', *linhas, '']
    md += ['', '> A legenda `legendas/' + v['arquivo'] + '.vtt` sai da coluna Narração com estes tempos. Se a gravação ficar mais curta ou mais longa, ajuste os tempos no editor (ou peça pra regenerar).', '']
    open(f"{out}/roteiros/{v['arquivo']}.md", 'w').write('\n'.join(md))
    open(f"{out}/legendas/{v['arquivo']}.vtt", 'w').write('\n'.join(vtt))
    print(v['arquivo'], ts(total, False), palavras)
