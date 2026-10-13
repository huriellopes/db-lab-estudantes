# Gerar as 5 vídeo-aulas do guia (narração TTS + tela automatizada)

## Context
`docs/videos/roteiros/` tem 5 roteiros (1:30–2:00 cada) feitos para gravação manual no OBS com a voz do autor. O pedido é gerar os vídeos prontos: só voz, sem rosto, ensinando o passo a passo, em linguagem natural e com alta qualidade. Decisões já tomadas: **voz sintética realista** usando **Edge TTS neural pt-BR**, voz `pt-BR-ThalitaMultilingualNeural` (escolhida ouvindo amostras; pronuncia SQL em inglês corretamente; grátis, sem chave; o texto da narração vai para o serviço da Microsoft). Resultado esperado: `.mp4` + `.webp` + `.vtt` de cada vídeo no volume de dev `dblab_guide_videos`, funcionando em `/guia/videos`.

Ambiente: o app roda em Docker local (`dblab_app`), com o usuário de dev `aluno@dblab.local` (`database/seeders/DevUsersSeeder.php`). Já temos ffmpeg (libx264/aac), Node 24 e o Chromium do Playwright em cache (`~/.cache/ms-playwright/chromium-1248`). Não há Xvfb nem TTS instalados.

## Abordagem: o áudio manda, a tela acompanha
1. **Narração primeiro.** Para cada cena, gero o áudio com `edge-tts` (voz Thalita Multilingual, ritmo ~-5%) e guardo os tempos de cada palavra (WordBoundary). A cena dura o maior entre (fala + respiros) e o tempo que as ações de tela levam; o gravador registra o início real de cada cena e a montagem posiciona o áudio e a legenda nesses instantes.
2. **Pronúncia.** Um mapa aplicado só ao texto que vai para o TTS (a legenda continua com o texto original): `SELECT`→"selécti", `WHERE`→"uér", `JOIN`→"djóin", `NULL`→"nâl", `IS NULL`, `LEFT`, `INNER`, `Ctrl`, `DER`→"dê ê érre", `N:N`→"ene pra ene", etc. Ajusto depois de ouvir as primeiras amostras.
3. **Tela com Playwright (headless, sem Xvfb).** Um script Node por vídeo, com uma função de ação por cena, guiando o app real em `http://localhost` (login como aluno). Viewport 1280x720 com `deviceScaleFactor: 1.5`, o que gera **1920x1080 nítido** com texto grande. Para o aluno acompanhar:
   - um cursor falso injetado no DOM, que se move suavemente (easing) até o alvo e mostra uma pulsação no clique;
   - digitação visível com `keyboard.type` e intervalo de ~60 ms;
   - um destaque (anel/realce) no elemento citado na narração ("Destaque o prefixo…");
   - depois das ações, cada cena espera até completar a duração do áudio dela.
4. **Captura de alta qualidade:** CDP `Page.startScreencast` (JPEG q=95, com timestamps) em vez do `recordVideo` (VP8 de baixo bitrate). O ffmpeg monta os frames em 30 fps constantes, a partir dos timestamps.
5. **Mixagem e exportação (ffmpeg):** áudio das cenas concatenado com os silêncios, `loudnorm` em -16 LUFS e leve redução de agudos ásperos. Vídeo `libx264 -crf 22 -preset slow -tune stillimage`, 1080p, `aac 128k`, `+faststart`; o alvo é ≤15 MB (se passar, subo o CRF). Pôster `.webp` tirado do frame em 3 s. Sem música de fundo.
6. **Legenda exata:** a `.vtt` sai dos WordBoundaries (blocos de até 2×42 caracteres, como no `chunks()` atual), então bate com o áudio ao centésimo.

## Fonte única preservada
- `docs/videos/gerar-roteiros.py`: mover a geração para `if __name__ == '__main__':`, para poder importar `V`; se existir `docs/videos/tempos.json` (gerado pelo pipeline com os tempos reais das cenas), usar esses tempos nos `.md` e `.vtt`. A narração continua sendo editada só ali.
- Revisão leve da narração (só onde soar estranho em voz sintética, ex.: "Executar tudo." solto), sem mudar o conteúdo didático. Mostro o diff antes de gerar.

## Arquivos novos
- `docs/videos/gerar-videos/` (pipeline):
  - `narrar.py`: TTS por cena + `tempos.json` + `.vtt` com tempos por palavra (`uv run --with edge-tts`)
  - `gravar.mjs`: runner Playwright (cursor, digitação, destaque, screencast, espera por cena)
  - `cenas/<arquivo>.mjs`: as ações de cada um dos 5 vídeos (seletores lidos dos templates Twig em `resources/`)
  - `montar.sh`: ffmpeg (frames→vídeo, áudio, loudnorm, mp4, webp)
  - `preparar.sh`: estado inicial de cada vídeo (apagar os schemas do aluno de dev, criar `escola` e os dados de exemplo para os vídeos 3–5), via `docker exec dblab_mysql`
  - `gerar.sh [arquivo|all]`: orquestra tudo e grava em `docs/videos/saida/` (gitignored)
- `.gitignore`: `docs/videos/saida/` e frames temporários
- `docs/videos/README.md`: seção "Geração automática", com o fluxo manual no OBS mantido como alternativa
- Primeiro passo da implementação: spec em `docs/superpowers/specs/2026-10-13-videos-guia-tts-design.md` (fluxo do brainstorming)

## Ordem de execução
1. Spec + refatoração do `gerar-roteiros.py` + `narrar.py`; gerar o áudio do vídeo 2 (mais curto) e **pedir para você ouvir e aprovar a voz e a pronúncia** antes de seguir.
2. Runner + cenas do vídeo 2 → primeiro MP4 completo para revisão.
3. Os outros 4 vídeos, em ordem (1, 3, 4, 5).
4. `docker cp` para o volume de dev e teste em `/guia/videos`. **Upload para produção só com sua confirmação explícita.**
5. Commit na branch nova `feat/videos-guia-gerados` (sem Co-Authored-By do Claude).

## Verificação
- `ffprobe`: 1920x1080, 30 fps, h264+aac, `faststart`, duração ≈ soma das cenas, tamanho ≤15 MB.
- `ffmpeg -af ebur128`: loudness entre -17 e -15 LUFS em todos os vídeos.
- Assistir a cada MP4 com a `.vtt` (mpv) e conferir a sincronia de fala, ação e legenda; extrair frames de cada cena e olhar o conteúdo (sem e-mail ou dado pessoal visível).
- No navegador (Playwright/Chrome), abrir `/guia/videos` e o tópico: play, seek e legenda.
- Rodar `python3 docs/videos/gerar-roteiros.py` duas vezes: a saída é idêntica (determinística) e os `.md` mostram os tempos reais.
