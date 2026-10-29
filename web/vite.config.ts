import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [vue(), tailwindcss()],
    build: {
      // The list of the files of the build, which the service worker fetches when it is installed so that the app starts without the network (public/sw.js).
      manifest: 'assets-manifest.json',
    },
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      proxy: {
        // The API of the development machine. Another project may already use port 8000:
        // set DEV_API_TARGET (for example in web/.env.local) to point at your php artisan serve.
        // The host of the page is kept (no changeOrigin): the API tells the games apart by it, so ships.localhost:5173 is another game than localhost:5173.
        '/api': { target: env.DEV_API_TARGET || 'http://127.0.0.1:8000', changeOrigin: false },
      },
    },
    test: {
      environment: 'jsdom',
      include: ['src/**/*.spec.ts'],
    },
  }
})
