import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import { useFlash } from '@/features/run/useFlash'
import { useLeaveOnSignOut } from '@/features/session/leaveOnSignOut'
import { endSessionIfUnauthorized } from '@/features/session/unauthorized'
import { useMarketStore } from '@/stores/market'

/**
 * What the screens of the market have in common: asking the server for the market, the garage the cars go to, and the way a purchase is made
 * (one at a time, the words of the server when it refuses, and the market asked for again when it did).
 */
export function useMarketScreen() {
  const { t, te } = useI18n()
  const store = useMarketStore()
  const { message: done, show: showDone, clear: clearDone } = useFlash()

  useLeaveOnSignOut()

  const loadError = ref(false)
  const busy = ref(false)
  const error = ref<string | null>(null)
  let lastAsk = 0

  const cash = computed(() => store.cash?.cash_cents ?? 0)
  const garage = computed(() => store.market?.garage ?? null)
  const city = computed(() => store.market?.city.name ?? '')
  /** Whether a car that is bought has a place to stand: the garage of the city is the only one it can go to. */
  const hasRoom = computed(() => !!garage.value && garage.value.owned && garage.value.free > 0)

  /** The class of a car in the language of the driver, or as the server calls it when there is no word for it. */
  const className = (slug: string): string => (te(`fleet.class.${slug}`) ? t(`fleet.class.${slug}`) : slug)

  /** When the market was last asked for, so a screen can keep from asking again and again. */
  const askedAgoMs = (): number => Date.now() - lastAsk

  async function load(): Promise<void> {
    lastAsk = Date.now()

    try {
      await store.load()
      loadError.value = false
    } catch (failure) {
      if (!endSessionIfUnauthorized(failure)) {
        loadError.value = !store.loaded
      }
    }
  }

  /** Makes one purchase (or inspection): the text it answers with is said for a while, and what the server refuses is shown in its own words. */
  async function attempt(action: () => Promise<string | null>): Promise<void> {
    if (busy.value) {
      return
    }

    busy.value = true
    error.value = null
    clearDone()

    try {
      const said = await action()

      if (said !== null) {
        showDone(said)
      }
    } catch (failure) {
      if (endSessionIfUnauthorized(failure)) {
        return
      }

      // The server says what is wrong in the language of the driver: the cash, the level, a full garage, a car that is gone.
      error.value = failure instanceof ApiError && failure.status === 429 ? t('common.tooManyRequests') : failure instanceof ApiError && failure.status === 422 ? failure.message : t('common.error')

      if (failure instanceof ApiError && failure.status === 422) {
        void load()
      }
    } finally {
      busy.value = false
    }
  }

  return { store, t, done, loadError, busy, error, cash, garage, city, hasRoom, className, askedAgoMs, load, attempt }
}
