"""Fonte única dos roteiros das vídeo-aulas do guia: gera roteiros/*.md e legendas/*.vtt.

Edite as cenas aqui (tempo inicial, tempo final, o que gravar, narração) e rode:
    python3 docs/videos/gerar-roteiros.py
Assim a legenda nunca fica diferente do roteiro. Ver docs/videos/README.md.

Se existir `tempos.json` (escrito por gerar-videos/ depois de gravar), os tempos reais de cada
cena e de cada bloco de legenda vêm dele; sem ele, valem os tempos planejados abaixo.
"""
import json, os, re, sys, textwrap

V = [
 dict(arquivo='modelagem-er-der-no-lab', titulo='Desenhando um DER no lab de modelagem', topico='/guia/modelagem-er',
  objetivo='O aluno sai sabendo criar entidades, entender a chave primária, ligar um relacionamento N:N e gerar o SQL das tabelas.',
  preparo='Logado como aluno, com o laboratório de modelagem (/laboratorio/modelagem) aberto e nenhum diagrama na tela. Zoom do navegador em 125% para o texto ficar legível.',
  cenas=[
   (0,21,'Laboratório de modelagem com o quadro vazio.','Antes de sair criando tabelas, vale a pena desenhar o banco. Esse desenho se chama DER, o diagrama entidade-relacionamento. Ele mostra o que o sistema precisa guardar e como essas coisas se ligam. Vamos montar o exemplo de uma escola, com alunos e cursos.'),
   (21,37,'Digite "Escola" no campo "Nome do diagrama".','Primeiro, dê um nome pro diagrama, aqui nesse campo ao lado do botão Salvar. Vou chamar de Escola. Com um nome, dá pra salvar e abrir o desenho de novo outro dia.'),
   (37,55,'Clique em "Nova entidade" e renomeie a caixa para aluno.','Agora clique em Nova entidade. Uma entidade é algo importante que o sistema guarda, e no banco ela vai virar uma tabela. A nossa primeira entidade é aluno: é só clicar no nome da caixa e digitar.'),
   (55,71,'Destaque o atributo id com o selo PK.','Repare que a caixa já vem com um atributo chamado id, marcado como PK, a chave primária. A chave primária identifica cada aluno sem nunca repetir, como o número de uma matrícula.'),
   (71,90,'Clique em "+ atributo" duas vezes: nome e email.','Agora os atributos, que são as informações de cada aluno. Clique em mais atributo e escreva nome. Mais um: e-mail. Do lado, fica o tipo do dado. Os dois são texto, então o VARCHAR que já vem marcado serve.'),
   (90,106,'Crie a entidade curso com nome e carga_horaria (tipo INT). Arraste a caixa para a direita.','A segunda entidade é curso, do mesmo jeito: Nova entidade, o nome curso, e os atributos nome e carga horária. Carga horária é um número, então troco o tipo pra INT. E arrasto a caixa pro lado, pra sobrar espaço pra linha que vem agora.'),
   (106,123,'Clique em "+ Relacionamento", depois em aluno e em curso. Escreva "matricula" no rótulo.','Agora vamos ligar as duas. Clique em Relacionamento, depois na entidade de origem, aluno, e na de destino, curso. Apareceu uma linha entre elas. No meio da linha, escrevo o nome do relacionamento: matrícula.'),
   (123,147,'Clique no "1" da ponta de aluno até virar N (N e N).','Nas pontas da linha fica a cardinalidade: quantos de um lado se ligam com quantos do outro. Pense assim: um aluno pode fazer vários cursos, e um curso tem vários alunos. Então os dois lados são N. Clico no 1, e ele vira N. Isso é um relacionamento N pra N.'),
   (147,177,'Clique em "Salvar" e depois em "Gerar SQL". Destaque a tabela associativa no final.','Clique em Salvar pra guardar o desenho, e depois em Gerar SQL. O laboratório escreve o CREATE TABLE de cada entidade, já com a chave primária. E olha o final: o N pra N virou uma tabela nova, a tabela associativa, com duas chaves estrangeiras, uma apontando pra aluno e outra pra curso. É assim que o banco guarda quem está matriculado em qual curso.'),
   (177,196,'Clique em "Abrir no console SQL →". Mostre o SQL já no console.','Pra criar essas tabelas de verdade, clique em Abrir no console SQL. O código vai direto pro console, pronto pra rodar. Só falta um schema pra guardar tudo, e é isso que a gente faz no próximo vídeo.'),
  ]),
 dict(arquivo='mysql-primeiro-schema', titulo='Criando seu primeiro schema', topico='/guia/mysql',
  objetivo='O aluno sabe o que é um schema, entende o prefixo, cria o primeiro pelo painel e encontra ele na lista e no console.',
  preparo='Conta de aluno sem nenhum schema, no /dashboard. Esconda o e-mail pessoal se aparecer no topo.',
  cenas=[
   (0,20,'Painel (/dashboard) com "Você ainda não criou nenhum schema."','Antes de criar qualquer tabela, você precisa de um schema. Pense nele como uma pasta: dentro dela ficam as tabelas de um projeto. Aqui no painel dá pra ver que ainda não tem nenhum: a lista Meus schemas está vazia.'),
   (20,46,'Cursor no card "Criar novo schema". Destaque o prefixo antes do campo.','Pra criar o primeiro, use o card Criar novo schema. Repare nesse começo do nome, que já vem preenchido. Esse é o seu prefixo, feito a partir do seu usuário. Como o laboratório é usado pela turma toda, o prefixo garante que o seu schema nunca vai ter o mesmo nome que o de outra pessoa.'),
   (46,65,'Digite "minha escola", destaque a dica "Sem espaços", apague e digite "escola".','Agora escolha um nome curto, que lembre o projeto. Use só letras, números e o sublinhado. Espaço não pode, como avisa a dica logo abaixo do campo. Então, no lugar de minha escola, separado, vou usar só escola.'),
   (65,82,'Clique em "Criar schema". Mostre o aviso de sucesso e o schema em "Meus schemas".','Clique em Criar schema. Pronto! Apareceu o aviso de sucesso, e o schema já está na lista Meus schemas, com o nome completo: o seu prefixo, dois sublinhados, e o nome que você escolheu.'),
   (82,101,'Role até o Console SQL e destaque o seletor "Schema" com o schema novo.','Ele também já aparece aqui embaixo, escolhido no campo Schema do console SQL. O console é onde você vai escrever os comandos: criar tabelas, colocar dados e fazer consultas. A gente vê o console com calma no próximo vídeo.'),
   (101,122,'Abra /guia/mysql, mostre "Dados de exemplo — loja" e clique em "▶ Testar no console".','Uma dica pra estudar: no guia de MySQL, os exemplos têm o botão Testar no console. Clicando nele, o código vai direto pro console, pronto pra rodar no schema que você acabou de criar. Assim você aprende fazendo, sem copiar e colar nada.'),
   (122,138,'Console com o exemplo carregado e o schema escolhido.','Resumindo: o schema é a pasta das suas tabelas, o nome dele leva o seu prefixo, e é nele que os comandos do console vão rodar. Agora é só começar!'),
  ]),
 dict(arquivo='mysql-console-sql', titulo='Usando o console SQL', topico='/guia/mysql',
  objetivo='O aluno roda comandos no console, entende "Executar tudo" x "Executar seleção", lê erros e salva uma consulta.',
  preparo='Schema "escola" criado. Console vazio. Tenha à mão o bloco "Dados de exemplo — loja" do guia de MySQL.',
  cenas=[
   (0,17,'Card "Console SQL" do painel.','O console SQL é o lugar onde você conversa com o banco de dados. Você escreve um comando, manda executar, e o MySQL responde na hora. Tudo pelo navegador, sem instalar nada no computador.'),
   (17,32,'Escolha "escola" no seletor Schema.','O primeiro passo é escolher em qual schema os comandos vão rodar. É aqui, no campo Schema. Vou usar o escola, que a gente criou no vídeo anterior.'),
   (32,60,'Cole o bloco de dados de exemplo do guia (CREATE TABLE + INSERT). Clique em "Executar tudo".','Agora vou colar os dados de exemplo do guia de MySQL. São vários comandos: o CREATE TABLE cria as tabelas, e o INSERT coloca as linhas dentro delas. Cada comando termina com ponto e vírgula, e é assim que o console sabe onde um acaba e o outro começa. Clique em Executar tudo, e eles rodam um depois do outro.'),
   (60,82,'Digite SELECT * FROM cliente; numa linha nova. Selecione só essa linha: o botão vira "Executar seleção". Ctrl+Enter.','Agora uma consulta. Numa linha nova, escrevo SELECT asterisco FROM cliente. Se eu selecionar só essa linha, olha o botão: ele muda pra Executar seleção. Assim, só o trecho selecionado roda, e o resto fica guardado. Dá pra usar o atalho também: Ctrl mais Enter.'),
   (82,93,'Mostre a tabela de resultado.','O resultado aparece numa tabela, logo abaixo, com uma linha pra cada cliente e uma coluna pra cada campo.'),
   (93,114,'Erre de propósito: SELECT * FROM clientes; e mostre a mensagem de erro. Corrija para cliente e rode de novo.','E se eu errar? Vou escrever clientes, no plural, e essa tabela não existe. O console mostra a mensagem de erro do próprio MySQL. Não se assuste com o erro: leia com calma, porque ele quase sempre diz exatamente o que está errado. Corrijo o nome, e tudo volta a funcionar.'),
   (114,133,'Clique em "Salvar comando", digite "todos os clientes" e clique em "Salvar". Mostre em "Consultas salvas" e clique pra recarregar.','Por último: a consulta que você usa sempre pode ser salva. Clique em Salvar comando, dê um nome, e confirme em Salvar. Ela fica na lista Consultas salvas, e um clique carrega o comando de volta no console.'),
   (133,146,'Console com o resultado na tela.','Resumindo: escolha o schema, escreva o comando, execute tudo ou só a seleção, e leia o resultado, ou o erro, com atenção. Agora é praticar!'),
  ]),
 dict(arquivo='sql-ansi-select-where', titulo='SELECT e WHERE: filtrando dados', topico='/guia/sql-ansi',
  objetivo='O aluno escreve SELECT com colunas e WHERE, e entende por que NULL não se compara com =.',
  preparo='Dados de exemplo do guia de SQL ANSI (curso, aluno, matricula) já criados no schema. Console aberto.',
  cenas=[
   (0,21,'Console com o schema escolhido.','O SELECT é o comando que você mais vai usar no SQL. Ele serve pra fazer perguntas pro banco, e a resposta sempre volta como uma tabela. Vamos praticar com os dados de exemplo do guia de SQL ANSI: alunos, cursos e matrículas.'),
   (21,39,'Rode: SELECT * FROM aluno;','A pergunta mais simples é: me mostre tudo da tabela aluno. SELECT asterisco FROM aluno. O asterisco quer dizer todas as colunas. Aqui estão os cinco alunos, com nome, e-mail, cidade e data de nascimento.'),
   (39,57,'Rode: SELECT nome, cidade FROM aluno;','Na maioria das vezes, você não precisa de todas as colunas. Então, no lugar do asterisco, escreva só as que interessam, separadas por vírgula: nome e cidade. O resultado fica mais limpo e mais fácil de ler.'),
   (57,81,"Rode: SELECT nome, cidade FROM aluno WHERE cidade = 'Recife';",'E se eu quiser só os alunos de Recife? É pra isso que serve o WHERE: ele filtra as linhas. O banco testa a condição em cada linha e só devolve as que deram verdadeiro. Aqui, a Ana e a Carla. Repare que o texto Recife vai entre aspas simples.'),
   (81,100,"Rode: ... WHERE cidade <> 'Recife'; Aponte que o Diego não apareceu.",'Agora o contrário: quem não é de Recife. No SQL, o sinal de diferente é o menor e o maior juntos. Vieram o Bruno e a Elisa. Mas espera aí: cadê o Diego? Ele também não é de Recife.'),
   (100,124,'Abra /guia/sql-ansi, seção "Filtrando com WHERE". Na animação, escolha a condição <> e avance até o Diego.','Essa animação do guia mostra o teste linha por linha. A cidade do Diego está vazia: é NULL. E NULL não é um valor, é a falta de valor. Comparar NULL com qualquer coisa não dá verdadeiro nem falso: dá desconhecido. E o WHERE só deixa passar o que é verdadeiro.'),
   (124,139,'No console, rode: SELECT nome FROM aluno WHERE cidade IS NULL;','Então, pra encontrar valores vazios, use IS NULL. Nunca igual NULL. Agora sim: o Diego aparece. E pro contrário, quem tem a cidade preenchida, existe o IS NOT NULL.'),
   (139,158,'Volte ao guia e mostre os exercícios da página.','Resumindo: o SELECT escolhe as colunas, o FROM diz de qual tabela, e o WHERE filtra as linhas. No guia tem essa animação e vários exercícios. Experimente trocar a condição e ver o que acontece com o Diego!'),
  ]),
 dict(arquivo='sql-ansi-join', titulo='JOIN na prática', topico='/guia/sql-ansi',
  objetivo='O aluno entende para que serve o JOIN, escreve um INNER JOIN e vê a diferença para o LEFT JOIN.',
  preparo='Mesmos dados do vídeo de SELECT/WHERE. Guia de SQL ANSI aberto no nível Intermediário, em outra aba.',
  cenas=[
   (0,23,'Rode: SELECT * FROM matricula;','Olhe a tabela matrícula. Ela só tem números: o id do aluno, o id do curso, a data e a nota. Isso é de propósito: guardar só o id evita repetir o nome do aluno em todo lugar. Mas aí fica a pergunta: quem é o aluno número 1?'),
   (23,42,'Digite, linha por linha: SELECT a.nome, m.curso_id FROM matricula m INNER JOIN aluno a ON a.id = m.aluno_id;','Pra responder, a gente junta as tabelas com o JOIN. Na primeira linha, escolho as colunas: o nome do aluno e o id do curso. Depois, digo que parto da tabela matrícula, e junto com a tabela aluno.'),
   (42,64,'Destaque o ON e os apelidos m e a.','O ON é a parte mais importante: ele diz como as linhas se encaixam. Aqui, o id do aluno tem que ser igual ao aluno id da matrícula. E esse m e esse a são apelidos das tabelas, pra não escrever o nome inteiro toda vez.'),
   (64,76,'Execute e mostre o resultado com nomes.','Executando... pronto! Cada matrícula agora aparece com o nome do aluno. A Ana aparece duas vezes, porque ela está em dois cursos.'),
   (76,98,'Abra a animação "Veja o JOIN juntando as tabelas" (nível Intermediário). Avance com ▶ Próximo até o Diego.','Pra entender o que acontece por dentro, o guia tem uma animação. Pra cada aluno, o banco procura as matrículas com o mesmo id e junta as linhas. Vou avançar até o Diego. Ele não tem nenhuma matrícula, então, no INNER JOIN, ele fica de fora.'),
   (98,114,'Troque para LEFT JOIN na animação e avance até o Diego (linha NULL).','Agora troque pra LEFT JOIN. Nele, todos os alunos da tabela da esquerda aparecem, mesmo quem não tem par. Olha o Diego: ele entrou, e as colunas da matrícula vieram como NULL.'),
   (114,129,'No console: SELECT a.nome FROM aluno a LEFT JOIN matricula m ON m.aluno_id = a.id WHERE m.id IS NULL;','Isso abre um truque muito usado: LEFT JOIN mais IS NULL encontra quem não tem par. Aqui, os alunos sem nenhuma matrícula. E o resultado é exatamente o Diego.'),
   (129,150,'Volte ao guia e mostre o exemplo com dois JOINs (aluno, matrícula e curso).','No guia tem o exemplo completo, juntando três tabelas com dois JOINs: aluno, matrícula e curso. Resumindo: o INNER JOIN traz só quem tem par, e o LEFT JOIN traz todo mundo da tabela da esquerda. Teste no console e brinque com a animação!'),
  ]),
]

def ts(s, vtt=True):
    m, s = divmod(round(s), 60)
    return f'{m:02d}:{s:02d}.000' if vtt else f'{m}:{s:02d}'

def vtt_ts(x):
    return f'{int(x // 60):02d}:{x % 60:06.3f}'

def linhas(texto, largura=42):
    """Um bloco de legenda em até 2 linhas, com a quebra no meio pra ficarem parecidas."""
    if len(texto) <= largura:
        return [texto]
    w = textwrap.wrap(texto, max(len(texto) // 2 + 1, len(max(texto.split(), key=len))))
    return w if len(w) <= 2 else textwrap.wrap(texto, largura)

def chunks(text, maxlen=84):
    """Quebra a narração em blocos de legenda (até 2 linhas de ~42 caracteres). Corta de
    preferência em ponto, dois-pontos ou vírgula, e divide o que sobrar em partes parecidas,
    pra nunca deixar uma palavra sozinha num bloco."""
    pedacos = [p for p in re.split(r'(?<=[.:?!,])\s+', text.strip()) if p]
    blocos, cur = [], ''
    for p in pedacos:
        junto = f'{cur} {p}'.strip()
        if cur and (len(junto) > maxlen or len(linhas(junto)) > 2):
            blocos.append(cur); cur = p
        else:
            cur = junto
    if cur: blocos.append(cur)
    out = []
    for b in blocos:
        partes = 1
        while True:
            ws = b.split()
            alvo = len(b) / partes
            res, atual = [], ''
            for w in ws:
                if atual and len(atual) + 1 + len(w) > alvo + 6 and len(res) < partes - 1:
                    res.append(atual); atual = w
                else:
                    atual = f'{atual} {w}'.strip()
            res.append(atual)
            if all(len(r) <= maxlen and len(linhas(r)) <= 2 for r in res):
                break
            partes += 1
        out += res
    return out

def main(out):
    tempos_path = os.path.join(out, 'tempos.json')
    tempos = json.load(open(tempos_path)) if os.path.exists(tempos_path) else {}
    os.makedirs(f'{out}/roteiros', exist_ok=True); os.makedirs(f'{out}/legendas', exist_ok=True)
    for v in V:
        real = tempos.get(v['arquivo'])
        cenas = v['cenas']
        if real and len(real['cenas']) == len(cenas):
            cenas = [(a, b, tela, fala) for (a, b), (_, _, tela, fala) in zip(real['cenas'], cenas)]
        else:
            real = None
        total = cenas[-1][1]
        palavras = sum(len(c[3].split()) for c in cenas)
        duracao = 'Duração' if real else 'Duração alvo'
        md = [f"# {v['titulo']}", '',
              f"- **Arquivo:** `{v['arquivo']}` (`.mp4`, `.webp`, `.vtt`)",
              f"- **{duracao}:** {ts(total, False)} · {palavras} palavras de narração (ritmo calmo, com pausas pra tela respirar)",
              f"- **Tópico do guia:** `{v['topico']}`",
              f"- **Objetivo:** {v['objetivo']}",
              f"- **Antes de gravar:** {v['preparo']}", '',
              '| Tempo | Na tela (o que gravar) | Narração |', '|---|---|---|']
        vtt = ['WEBVTT', '']
        for (a, b, tela, fala) in cenas:
            md.append(f"| {ts(a, False)}–{ts(b, False)} | {tela.replace('|', '\\|')} | {fala} |")
            if real:
                continue
            partes = chunks(fala)
            dur = (b - a) / len(partes)
            for i, p in enumerate(partes):
                vtt += [f'{vtt_ts(a + dur * i)} --> {vtt_ts(a + dur * (i + 1))}', *linhas(p), '']
        if real:
            for ini, fim, texto in real['legendas']:
                vtt += [f'{vtt_ts(ini)} --> {vtt_ts(fim)}', *linhas(texto), '']
            nota = ' com os tempos medidos na gravação gerada (`gerar-videos/`, que escreve `tempos.json`).'
        else:
            nota = ' com estes tempos. Se a gravação ficar mais curta ou mais longa, ajuste os tempos no editor (ou peça pra regenerar).'
        md += ['', '> A legenda `legendas/' + v['arquivo'] + '.vtt` sai da coluna Narração' + nota, '']
        open(f"{out}/roteiros/{v['arquivo']}.md", 'w').write('\n'.join(md))
        open(f"{out}/legendas/{v['arquivo']}.vtt", 'w').write('\n'.join(vtt))
        print(v['arquivo'], ts(total, False), palavras)

if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__)))
