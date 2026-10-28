import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { i18n, initLocale } from './i18n'
import { loadVocabulary } from './i18n/vocabulary'
import { registerServiceWorker } from './pwa'
import { router } from './router'
import './style.css'

async function bootstrap(): Promise<void> {
  // Język trzeba ustalić przed pierwszym renderem, żeby interfejs nie mignął po angielsku.
  await initLocale()
  // Słowa paczki też przed pierwszym renderem, żeby nazwy nie mignęły takie, jakie ma aplikacja.
  await loadVocabulary()

  createApp(App).use(createPinia()).use(router).use(i18n).mount('#app')
}

void bootstrap()
registerServiceWorker()
