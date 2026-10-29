import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { loadGame } from './game/load'
import { i18n, initLocale } from './i18n'
import { loadVocabulary } from './i18n/vocabulary'
import { loadModel } from './model/load'
import { registerServiceWorker } from './pwa'
import { router } from './router'
import './style.css'

async function bootstrap(): Promise<void> {
  // Trzy pytania o grę, do której należy host strony (ona sama, jej słowa i jej model), idą naraz, więc start czeka najwyżej tyle, ile najwolniejsze z nich. Żadne nie
  // rzuca: gdy nie odpowiedzą, aplikacja jest grą o samochodach, jaką była.
  const game = loadGame()
  const rest = Promise.all([loadVocabulary(), loadModel()])

  // Język trzeba ustalić przed pierwszym renderem, żeby interfejs nie mignął po angielsku; gdy żaden język przeglądarki nie pasuje, jest nim język gry.
  await initLocale((await game).locale)
  // Słowa paczki i jej model (sloty i statystyki) też przed pierwszym renderem, żeby nazwy ani listy nie mignęły takie, jakie ma gra o samochodach.
  await rest

  createApp(App).use(createPinia()).use(router).use(i18n).mount('#app')
}

void bootstrap()
registerServiceWorker()
