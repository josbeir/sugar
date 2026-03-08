import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [tailwindcss()],
  build: {
    outDir: 'public/',
    manifest: true,
    emptyOutDir: false,
    rollupOptions: {
      input: [
        'assets/css/docs.css',
        'assets/js/docs.js'
      ],
    },
  },
});
