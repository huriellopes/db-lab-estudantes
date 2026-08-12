import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [tailwindcss()],
  // Sem publicDir: o próprio public/ já é o docroot do PHP (index.php, .htaccess) —
  // não queremos que o Vite copie esse conteúdo para dentro de public/build.
  publicDir: false,
  build: {
    manifest: true,
    outDir: 'public/build',
    rollupOptions: {
      input: ['resources/css/app.css', 'resources/js/app.js'],
    },
  },
});
