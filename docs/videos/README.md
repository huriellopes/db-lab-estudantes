# Vídeo-aulas do guia

Primeira leva de 5 vídeo-aulas (2 a 3 min) do `/guia`, na ordem da trilha. O catálogo fica em
`App\Support\GuideVideos::ALL`, e cada vídeo aparece no guia sozinho assim que o `.mp4` e o
`.vtt` estiverem no volume `dblab-guide-videos`.

| # | Vídeo | Roteiro | Legenda |
|---|---|---|---|
| 1 | Desenhando um DER no lab de modelagem | [roteiro](roteiros/modelagem-er-der-no-lab.md) | [vtt](legendas/modelagem-er-der-no-lab.vtt) |
| 2 | Criando seu primeiro schema | [roteiro](roteiros/mysql-primeiro-schema.md) | [vtt](legendas/mysql-primeiro-schema.vtt) |
| 3 | Usando o console SQL | [roteiro](roteiros/mysql-console-sql.md) | [vtt](legendas/mysql-console-sql.vtt) |
| 4 | SELECT e WHERE: filtrando dados | [roteiro](roteiros/sql-ansi-select-where.md) | [vtt](legendas/sql-ansi-select-where.vtt) |
| 5 | JOIN na prática | [roteiro](roteiros/sql-ansi-join.md) | [vtt](legendas/sql-ansi-join.vtt) |

Os roteiros e as legendas saem de uma fonte só, `gerar-roteiros.py`. Mudou uma fala ou um tempo?
Edite lá e rode `python3 docs/videos/gerar-roteiros.py`. Não edite os `.md`/`.vtt` à mão.

## Geração automática (recomendado)

Os vídeos saem prontos de `gerar-videos/`, sem gravar tela nem voz à mão: narração com voz neural
pt-BR (edge-tts, voz `pt-BR-ThalitaMultilingualNeural`) e o próprio app guiado pelo Playwright,
com cursor, digitação e destaques sincronizados com cada frase da fala.

```bash
docker compose up -d                                  # APP_ENV=local, com o DevUsersSeeder
docs/videos/gerar-videos/gerar.sh                     # os 5 vídeos (ou passe os nomes-base)
PUBLICAR=1 docs/videos/gerar-videos/gerar.sh          # + copia .mp4/.webp/.vtt pro volume de dev
```

Precisa de `node`, `uv`, `ffmpeg` e `docker`. Cada vídeo passa por quatro etapas:

1. **`narrar.py`**: sintetiza a fala de cada cena (`saida/<arquivo>/cena-NN.wav`) e guarda o tempo
   de cada palavra. Só refaz o áudio da cena cuja fala mudou. O texto da narração vai pro serviço
   de voz da Microsoft. Pronúncias corrigidas (ex.: schema → "esquema") ficam em `PRONUNCIA`, e
   valem só pro áudio: a legenda mostra o texto original.
2. **`preparar.sh`**: zera a conta `aluno@dblab.local` (schemas, consultas salvas, diagramas).
   Ele se recusa a rodar se o `APP_ENV` do container não for `local`.
3. **`gravar.mjs`**: abre o app num Chromium headless 1024x576 renderizado em 1920x1080 (texto do
   tamanho de um zoom de 125%) e roda as ações de `cenas/<arquivo>.mjs`. Cada cena dura o que for
   maior, a fala ou as ações, e `h.quandoDisser('trecho')` espera a narração chegar naquelas
   palavras antes de clicar ou destacar. Os quadros vêm do screencast do Chrome.
4. **`montar.py`**: monta o vídeo em 30 fps constantes, posiciona a fala de cada cena no instante
   em que ela começou na gravação, normaliza o áudio (loudnorm, -16 LUFS) e escreve o `.mp4`
   (H.264 1080p, `+faststart`), o pôster `.webp` (a vinheta de título) e `tempos.json`. Depois,
   `gerar-roteiros.py` usa esses tempos reais nos `.md` e nas `.vtt`.

Mudou uma fala? Edite `gerar-roteiros.py` e, se a ação na tela mudar também, o arquivo da cena em
`cenas/`. Depois rode `gerar.sh <arquivo>`. A saída fica em `docs/videos/saida/` (fora do git).
Se uma ação falhar, a tela do momento do erro fica em `saida/<arquivo>/erro.png`.

## Gravação manual (alternativa)

1. **Ambiente:** `docker compose up -d` com os usuários de desenvolvimento (`APP_ENV=local`,
   conta `aluno@dblab.local`). Assim nenhum dado real aparece na tela.
2. **OBS:** canvas 1280x720, só a janela do navegador, zoom da página em 125%, cursor
   destacado. Microfone com redução de ruído; grave a narração lendo a coluna Narração.
3. **Ritmo:** pause meio segundo antes de cada clique. É melhor sobrar tempo do que correr; a
   legenda acompanha os tempos do roteiro, e dá pra ajustá-los no gerador depois.
4. **Exportar e subir:** comandos na seção "Vídeo-aulas do guia" do `README.md` principal
   (ffmpeg para MP4 720p + pôster `.webp`, depois `docker cp` para o volume).

## Checklist de cada vídeo

- [ ] Nenhum e-mail, senha ou dado pessoal visível
- [ ] Áudio sem chiado, volume parecido entre os vídeos
- [ ] `.mp4` com `+faststart`, até ~20 MB
- [ ] `.vtt` revisada contra o áudio final
- [ ] Testado em `/guia/videos` e no tópico (play, seek, legenda)
