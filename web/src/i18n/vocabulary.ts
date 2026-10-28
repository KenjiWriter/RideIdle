import { api } from '@/api/client'

import { setVocabulary, type Vocabulary } from './index'

interface VocabularyResponse {
  number: string
  messages: Vocabulary
}

/**
 * Pobiera słownik paczki, która jest żywa, i nakłada go na teksty aplikacji. Czeka najwyżej chwilę, a gdy się nie uda (sieć, serwer), nic się nie dzieje: gra mówi
 * wtedy tekstami aplikacji, a słownik jest następnym razem.
 */
export async function loadVocabulary(timeoutMs = 1500): Promise<void> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const response = await api<VocabularyResponse>('/v1/meta/vocabulary', { signal: controller.signal })

    setVocabulary(response.messages ?? {})
  } catch {
    // Bez słownika gra działa tak samo, tylko po swojemu.
  } finally {
    clearTimeout(timer)
  }
}
