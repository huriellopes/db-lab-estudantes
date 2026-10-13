# Vídeo-aulas do guia

Primeira leva de 5 "pílulas" (1 a 2 min) do `/guia`, na ordem da trilha. O catálogo fica em
`App\Support\GuideVideos::ALL`, e cada vídeo aparece no guia sozinho assim que o `.mp4` e o
`.vtt` estiverem no volume `dblab-guide-videos`.

| # | Vídeo | Roteiro | Legenda |
|---|---|---|---|
| 1 | Desenhando um DER no lab de modelagem | [roteiro](roteiros/modelagem-er-der-no-lab.md) | [vtt](legendas/modelagem-er-der-no-lab.vtt) |
| 2 | Criando seu primeiro schema | [roteiro](roteiros/mysql-primeiro-schema.md) | [vtt](legendas/mysql-primeiro-schema.vtt) |
| 3 | Usando o console SQL | [roteiro](roteiros/mysql-console-sql.md) | [vtt](legendas/mysql-console-sql.vtt) |
| 4 | SELECT e WHERE em 2 minutos | [roteiro](roteiros/sql-ansi-select-where.md) | [vtt](legendas/sql-ansi-select-where.vtt) |
| 5 | JOIN na prática | [roteiro](roteiros/sql-ansi-join.md) | [vtt](legendas/sql-ansi-join.vtt) |

Os roteiros e as legendas saem de uma fonte só, `gerar-roteiros.py`. Mudou uma fala ou um tempo?
Edite lá e rode `python3 docs/videos/gerar-roteiros.py`. Não edite os `.md`/`.vtt` à mão.

## Gravação

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
- [ ] `.mp4` com `+faststart`, entre 5 e 15 MB
- [ ] `.vtt` revisada contra o áudio final
- [ ] Testado em `/guia/videos` e no tópico (play, seek, legenda)
