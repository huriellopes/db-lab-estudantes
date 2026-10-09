# Estudo: vídeo-aulas curtas no /guia

Status: **estudo** — nada implementado ainda.

## Ponto de partida

- O guia é estático: `App\Support\GuideTopics::ALL` + um template por slug em `app/Views/guide/`.
- A CSP do nginx (`docker/nginx.conf`) é `default-src 'self'`, sem `frame-src` nem `media-src`
  próprios. Isso permite `<video>` servido pelo próprio servidor, mas **bloqueia hoje o iframe
  do YouTube**.

## Opções

| Opção | Prós | Contras |
|---|---|---|
| **A. Vídeo próprio (MP4/WebM)** servido pelo nginx | Sem terceiros nem rastreamento (LGPD), funciona com a CSP atual, controle total | Banda e disco do servidor (~5–15 MB por vídeo de 1–2 min em 720p); precisa comprimir (ffmpeg); range requests já vêm por padrão no nginx |
| **B. YouTube não-listado** via `youtube-nocookie.com` | Zero banda própria, player adaptativo, legenda automática | Abrir `frame-src https://www.youtube-nocookie.com` na CSP, dependência externa, recomendações e marca no fim, cookies ao dar play |
| **C. Gravação de terminal (asciinema) ou WebM curto sem áudio** | Muito leve, ótimo para "digite isto no console" | Não serve para explicação conceitual (MER, formas normais) |

## Recomendação: A, em formato "pílula"

- Vídeos de **60–120 s**, sem áudio ou com narração curta, sempre com **legenda `.vtt`** para
  acessibilidade e para quem assiste sem som.
- Um vídeo por tópico, começando pelos práticos: MySQL, SQL ANSI, console e lab ER.
- **Produção:** OBS para gravar a tela, depois
  `ffmpeg -i in.mkv -vf scale=-2:720 -c:v libx264 -crf 28 -preset slow -c:a aac -b:a 96k -movflags +faststart out.mp4`.
  O pôster sai de um frame (`ffmpeg -ss 3 -i out.mp4 -frames:v 1 poster.webp`) e a legenda é
  escrita a partir do roteiro.
- **Hospedagem:** fora do git, num volume (ex.: `storage/guide-videos`), servido pelo nginx em
  `/videos/` com `Cache-Control` longo. Se a banda virar problema, migrar para B mudando só o
  macro e a CSP.

## Implementação futura (pequena)

1. Campo opcional em `GuideTopics::ALL`:
   `'video' => ['src' => ..., 'poster' => ..., 'captions' => ..., 'duration' => '1:45']`.
2. Macro Twig `guide_video(topic)` com `<video controls preload="none" playsinline poster=...>`
   + `<track kind="captions" srclang="pt-BR" default>`.
3. Bloco "▶ Ver em 2 min" no topo de cada tópico e um selo 🎬 nos cards do `/guia`.
4. `location /videos/` no `docker/nginx.conf` + volume no compose (dev e prod).
5. Teste em `tests/Unit/GuideTemplatesTest.php`: todo `video.src` declarado aponta para um
   arquivo com extensão `.mp4` ou `.webm` e tem legenda.

## Primeira leva sugerida (5 vídeos)

1. Criando seu primeiro schema
2. Usando o console SQL
3. SELECT/WHERE em 2 minutos
4. JOIN na prática
5. Desenhando um DER no lab de modelagem
