"""Passo 3: junta quadros + falas no .mp4 final, gera o pôster e os tempos das legendas.

    python3 docs/videos/gerar-videos/montar.py <arquivo>

Lê saida/<arquivo>/gravacao.json (gravar.mjs) e cena-NN.{wav,json} (narrar.py). Escreve
saida/<arquivo>/<arquivo>.{mp4,webp} e atualiza docs/videos/tempos.json; depois é só rodar
gerar-roteiros.py para os .md e .vtt saírem com os tempos reais.
"""
import importlib.util, json, os, re, subprocess, sys

sys.dont_write_bytecode = True  # importa ../gerar-roteiros.py sem deixar __pycache__ em docs/
AQUI = os.path.dirname(os.path.abspath(__file__))
VIDEOS = os.path.dirname(AQUI)
FPS = 30
CRF = int(os.environ.get('CRF', '20'))
LUFS = -16


def ff(*args, **kw):
    return subprocess.run(['ffmpeg', '-hide_banner', '-v', 'error', '-y', *args], check=True, **kw)


def montar(arquivo):
    pasta = os.path.join(VIDEOS, 'saida', arquivo)
    g = json.load(open(os.path.join(pasta, 'gravacao.json')))
    total = g['duracao']

    # Quadros chegam só quando a tela muda: para cada instante k/FPS do vídeo, entra o último
    # quadro recebido até ali (tempo exato, sem o desvio que durações variáveis acumulam).
    quadros = g['quadros']
    seq, j = [], 0
    for k in range(int(total * FPS)):
        while j + 1 < len(quadros) and quadros[j + 1][1] <= k / FPS:
            j += 1
        seq.append(quadros[j][0])

    # Fala de cada cena no instante em que a cena começou na gravação.
    falas, filtros = [], []
    for n, c in enumerate(g['cenas'], 1):
        falas += ['-i', os.path.join(pasta, f'cena-{n:02d}.wav')]
        filtros.append(f"[{n}:a]adelay={round(c['audio'] * 1000)}:all=1[a{n}]")
    k = len(g['cenas'])
    mix = ';'.join(filtros) + ';' + ''.join(f'[a{n}]' for n in range(1, k + 1)) + \
        f'amix=inputs={k}:normalize=0:dropout_transition=0,apad,atrim=0:{total:.3f},' + \
        'highpass=f=70,lowpass=f=15000,equalizer=f=6500:t=q:w=1.2:g=-2'

    # loudnorm em dois passos: mede, depois corrige com os valores medidos (fica linear e exato).
    medir = subprocess.run(['ffmpeg', '-hide_banner', '-nostats', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', *falas,
                            '-filter_complex', mix + f',loudnorm=I={LUFS}:TP=-1.5:LRA=11:print_format=json[out]',
                            '-map', '[out]', '-f', 'null', '-'], capture_output=True, text=True, check=True)
    m = json.loads(re.findall(r'\{[^{}]+\}', medir.stderr)[-1])
    norm = (f",loudnorm=I={LUFS}:TP=-1.5:LRA=11:measured_I={m['input_i']}:measured_TP={m['input_tp']}"
            f":measured_LRA={m['input_lra']}:measured_thresh={m['input_thresh']}:offset={m['target_offset']}:linear=true,"
            'aresample=48000')

    mp4 = os.path.join(pasta, f'{arquivo}.mp4')
    proc = subprocess.Popen(
        ['ffmpeg', '-hide_banner', '-v', 'error', '-y', '-f', 'image2pipe', '-framerate', str(FPS), '-c:v', 'mjpeg', '-i', '-', *falas,
         '-filter_complex', f"[0:v]scale=1920:1080:flags=lanczos,fade=t=out:st={total - 0.8:.3f}:d=0.8,format=yuv420p[v];" + mix + norm + '[a]',
         '-map', '[v]', '-map', '[a]', '-t', f'{total:.3f}',
         '-c:v', 'libx264', '-preset', 'slow', '-crf', str(CRF), '-tune', 'stillimage', '-profile:v', 'high',
         '-g', str(FPS * 4), '-c:a', 'aac', '-b:a', '128k', '-ac', '1', '-movflags', '+faststart', mp4],
        stdin=subprocess.PIPE)
    anterior, dados = None, b''
    for nome in seq:
        if nome != anterior:
            dados, anterior = open(os.path.join(pasta, 'quadros', nome), 'rb').read(), nome
        proc.stdin.write(dados)
    proc.stdin.close()
    if proc.wait():
        sys.exit(f'ffmpeg falhou montando {mp4}')
    ff('-ss', '1.2', '-i', mp4, '-frames:v', '1', '-vf', 'scale=1280:-2', '-quality', '85', os.path.join(pasta, f'{arquivo}.webp'))

    # Tempos reais pra fonte única (gerar-roteiros.py lê tempos.json).
    spec = importlib.util.spec_from_file_location('roteiros', os.path.join(VIDEOS, 'gerar-roteiros.py'))
    rot = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(rot)
    legendas = []
    for n, c in enumerate(g['cenas'], 1):
        fala = json.load(open(os.path.join(pasta, f'cena-{n:02d}.json')))
        pos = 0
        for bloco in rot.chunks(fala['fala']):
            q = len(bloco.split())
            legendas.append([c['audio'] + fala['ini'][pos], c['audio'] + fala['fim'][pos + q - 1] + 0.25, bloco])
            pos += q
    for a, b in zip(legendas, legendas[1:]):  # sem sobreposição; um bloco curto fica até o próximo, até 1,2 s a mais
        a[1] = min(max(a[1], a[0] + 1.2), b[0])
    legendas = [[round(a, 3), round(b, 3), t] for a, b, t in legendas]
    tp = os.path.join(VIDEOS, 'tempos.json')
    tempos = json.load(open(tp)) if os.path.exists(tp) else {}
    tempos[arquivo] = {'cenas': [[round(c['ini'], 2), round(c['fim'], 2)] for c in g['cenas']], 'legendas': legendas}
    json.dump(dict(sorted(tempos.items())), open(tp, 'w'), ensure_ascii=False, indent=1)
    print(f'{mp4}: {os.path.getsize(mp4) / 1e6:.1f} MB, {total:.1f}s')


if __name__ == '__main__':
    for a in sys.argv[1:]:
        montar(a)
