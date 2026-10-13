"""Passo 1: narração de cada cena com voz neural (edge-tts) + tempo de cada palavra.

    uv run --with edge-tts python -I docs/videos/gerar-videos/narrar.py [arquivo ...]

Lê as cenas de ../gerar-roteiros.py (a fonte única) e grava, em docs/videos/saida/<arquivo>/:
  cena-NN.wav   fala da cena (48 kHz mono)
  cena-NN.json  duração da fala e início/fim de cada palavra (relativos ao começo do .wav)
Só sintetiza de novo quando a fala, a voz ou o ritmo mudam (cache por hash).
"""
import asyncio, difflib, hashlib, importlib.util, json, os, re, subprocess, sys

import edge_tts

sys.dont_write_bytecode = True  # importa ../gerar-roteiros.py sem deixar __pycache__ em docs/

VOZ = 'pt-BR-ThalitaMultilingualNeural'
RITMO = '-12%'

# Só o que a voz lê mal. Vale apenas para o áudio: a legenda mostra o texto original.
PRONUNCIA = {
    'schema': 'esquema',
    'schemas': 'esquemas',
    'DER': 'dê-ê-érre',
    'Ctrl': 'Control',
}

AQUI = os.path.dirname(os.path.abspath(__file__))
VIDEOS = os.path.dirname(AQUI)
SAIDA = os.path.join(VIDEOS, 'saida')


def carregar_roteiros():
    spec = importlib.util.spec_from_file_location('roteiros', os.path.join(VIDEOS, 'gerar-roteiros.py'))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def norm(palavra):
    return re.sub(r'[^\w]', '', palavra.lower())


def para_voz(fala):
    """Texto que vai pro TTS e, para cada palavra dele, o índice da palavra original."""
    tokens, origem = [], []
    for i, w in enumerate(fala.split()):
        core = w.strip('.,:;!?()"')
        troca = PRONUNCIA.get(core)
        partes = w.replace(core, troca).split() if troca else [w]
        tokens += partes
        origem += [i] * len(partes)
    return ' '.join(tokens), origem


async def sintetizar(texto, mp3):
    palavras = []
    com = edge_tts.Communicate(texto, VOZ, rate=RITMO, boundary='WordBoundary')
    with open(mp3, 'wb') as f:
        async for ch in com.stream():
            if ch['type'] == 'audio':
                f.write(ch['data'])
            elif ch['type'] == 'WordBoundary':
                ini = ch['offset'] / 1e7
                palavras.append({'texto': ch['text'], 'ini': ini, 'fim': ini + ch['duration'] / 1e7})
    return palavras


def tempos_originais(fala, texto_voz, origem, palavras):
    """Início/fim de cada palavra ORIGINAL, alinhando o que o TTS devolveu com o texto da voz."""
    voz = [norm(w) for w in texto_voz.split()]
    tts = [norm(p['texto']) for p in palavras]
    n = len(fala.split())
    ini, fim = [None] * n, [None] * n
    for bloco in difflib.SequenceMatcher(a=voz, b=tts, autojunk=False).get_matching_blocks():
        for k in range(bloco.size):
            o, p = origem[bloco.a + k], palavras[bloco.b + k]
            ini[o] = p['ini'] if ini[o] is None else ini[o]
            fim[o] = p['fim']
    # Palavra sem par (raro): herda o tempo da vizinha anterior.
    ultimo = 0.0
    for i in range(n):
        if ini[i] is None:
            ini[i] = fim[i] = ultimo
        ultimo = fim[i]
    return ini, fim


def duracao(arquivo):
    r = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', arquivo],
                       capture_output=True, text=True, check=True)
    return float(r.stdout)


async def narrar_video(v):
    pasta = os.path.join(SAIDA, v['arquivo'])
    os.makedirs(pasta, exist_ok=True)
    for n, (_, _, _, fala) in enumerate(v['cenas'], 1):
        base = os.path.join(pasta, f'cena-{n:02d}')
        texto_voz, origem = para_voz(fala)
        chave = hashlib.sha256(f'{VOZ}|{RITMO}|{texto_voz}|{fala}'.encode()).hexdigest()
        if os.path.exists(base + '.json') and json.load(open(base + '.json')).get('hash') == chave:
            continue
        palavras = await sintetizar(texto_voz, base + '.mp3')
        subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', base + '.mp3', '-ar', '48000', '-ac', '1', base + '.wav'], check=True)
        os.remove(base + '.mp3')
        ini, fim = tempos_originais(fala, texto_voz, origem, palavras)
        json.dump({'hash': chave, 'fala': fala, 'duracao': duracao(base + '.wav'), 'ini': ini, 'fim': fim},
                  open(base + '.json', 'w'), ensure_ascii=False, indent=1)
        print(f"  {v['arquivo']} cena {n}: {duracao(base + '.wav'):.1f}s")


async def main(alvos):
    rot = carregar_roteiros()
    for n, v in enumerate(rot.V, 1):
        if not alvos or v['arquivo'] in alvos:
            await narrar_video(v)
            json.dump({'titulo': v['titulo'], 'numero': n, 'total': len(rot.V)},
                      open(os.path.join(SAIDA, v['arquivo'], 'info.json'), 'w'), ensure_ascii=False)


if __name__ == '__main__':
    asyncio.run(main(sys.argv[1:]))
