import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { i18n, initLocale } from './i18n'
import { loadVocabulary } from './i18n/vocabulary'
import { loadModel } from './model/load'
import { registerServiceWorker } from './pwa'
import { router } from './router'
import './style.css'

async function bootstrap(): Promise<void> {
  // Język trzeba ustalić przed pierwszym renderem, żeby interfejs nie mignął po angielsku.
  await initLocale()
  // Słowa paczki i jej model (sloty i statystyki) też przed pierwszym renderem, żeby nazwy ani listy nie mignęły takie, jakie ma gra o samochodach. Oba pytania idą naraz,
  // więc start czeka najwyżej tyle, ile jedno z nich.
  await Promise.all([loadVocabulary(), loadModel()])

  createApp(App).use(createPinia()).use(router).use(i18n).mount('#app')
}

void bootstrap()
registerServiceWorker()
