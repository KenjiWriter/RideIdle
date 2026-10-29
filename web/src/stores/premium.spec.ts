import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { passOffer, premiumView, refillOffer, vipOffer } from '@/test/premiumFixtures'

import { usePremiumStore } from './premium'

const fetchMock = vi.fn()

describe('the premium store', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setActivePinia(createPinia())
    storeToken('device-token')
    await setLocale('en')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('reads the balance and the extras, and notes when', async () => {
    serveApi(fetchMock, { 'GET /api/v1/premium': () => respond(200, { data: premiumView({ balance: 31 }) }) })
    const store = usePremiumStore()

    await store.load()

    expect(store.balance).toBe(31)
    expect(store.autorefill?.packs).toHaveLength(3)
    expect(store.vip?.coins).toBe(150)
    expect(store.seenAt).toBeGreaterThan(0)
  })

  it('makes one call for two screens that ask together, and asks once in a session', async () => {
    const calls = serveApi(fetchMock, { 'GET /api/v1/premium': () => respond(200, { data: premiumView() }) })
    const store = usePremiumStore()

    await Promise.all([store.ensure(), store.ensure(), store.load()])
    await store.ensure()

    expect(calls).toHaveLength(1)
  })

  it('works out how much of an account is left from the clock of the server, not of the device', async () => {
    const server = Date.now() + 7 * 86_400_000
    serveApi(fetchMock, { 'GET /api/v1/premium': () => respond(200, { data: premiumView({ now_ms: server, vip: vipOffer({ active: true, until_ms: server + 3_600_000, remaining_ms: 3_600_000 }) }) }) })
    const store = usePremiumStore()

    await store.load()

    expect(store.remainingMs(store.vip?.until_ms)).toBeGreaterThan(3_590_000)
    expect(store.remainingMs(store.vip?.until_ms)).toBeLessThanOrEqual(3_600_000)
    expect(store.vipActiveAt(server + 3_599_999)).toBe(true)
    expect(store.vipActiveAt(server + 3_600_000)).toBe(false)
    expect(store.remainingMs(null)).toBe(0)
  })

  it('does not count an extra that the game has switched off as on', async () => {
    const server = Date.now()
    serveApi(fetchMock, {
      'GET /api/v1/premium': () =>
        respond(200, {
          data: premiumView({ now_ms: server, vip: vipOffer({ enabled: false, until_ms: server + 1_000_000 }), infinity: passOffer({ enabled: false, until_ms: server + 1_000_000 }) }),
        }),
    })
    const store = usePremiumStore()

    await store.load()

    expect(store.vipActiveAt(server)).toBe(false)
    expect(store.infinityActiveAt(server)).toBe(false)
    expect(store.vipActive).toBe(false)
  })

  it('says the tank is filled for the driver only when the game does it, they want it, and there is a refill or the pass to pay', async () => {
    const server = Date.now()
    const answers = [
      premiumView({ now_ms: server, autorefill: refillOffer({ stock: 0 }) }),
      premiumView({ now_ms: server, autorefill: refillOffer({ stock: 3 }) }),
      premiumView({ now_ms: server, autorefill: refillOffer({ stock: 3, preference: false }) }),
      premiumView({ now_ms: server, autorefill: refillOffer({ stock: 0 }), infinity: passOffer({ until_ms: server + 600_000 }) }),
      premiumView({ now_ms: server, autorefill: refillOffer({ stock: 3, enabled: false }) }),
    ]
    let asked = 0
    serveApi(fetchMock, { 'GET /api/v1/premium': () => respond(200, { data: answers[asked++] }) })
    const store = usePremiumStore()
    const states: boolean[] = []

    for (let i = 0; i < answers.length; i++) {
      await store.load()
      states.push(store.autoRefillOn)
    }

    expect(states).toEqual([false, true, false, true, false])
  })

  describe('buying', () => {
    it('buys a pack with a request id, and keeps what the answer says', async () => {
      const calls = serveApi(fetchMock, {
        'POST /api/v1/premium/autorefill': () => respond(201, { data: premiumView({ balance: 3, autorefill: refillOffer({ stock: 5 }) }), repeated: false, spent_coins: 9, bought_charges: 5 }),
      })
      const store = usePremiumStore()

      const bought = await store.buyRefills('refill-5')

      expect(bought).toEqual({ coins: 9, charges: 5, repeated: false })
      expect(calls[0].body).toMatchObject({ pack: 'refill-5' })
      expect((calls[0].body as { request_id: string }).request_id).toBeTruthy()
      expect(store.balance).toBe(3)
      expect(store.autorefill?.stock).toBe(5)
    })

    it('sends the same request id again when the answer was lost, so that it charges once, and a new one for the next purchase', async () => {
      let attempt = 0
      const calls = serveApi(fetchMock, {
        'POST /api/v1/premium/autorefill': () => (attempt++ === 0 ? respond(500, { message: 'lost' }) : respond(200, { data: premiumView(), repeated: true, spent_coins: 0, bought_charges: 0 })),
      })
      const store = usePremiumStore()

      await expect(store.buyRefills('refill-1')).rejects.toThrow()
      const again = await store.buyRefills('refill-1')
      await store.buyRefills('refill-1')

      const ids = calls.map((call) => (call.body as { request_id: string }).request_id)

      expect(again.repeated).toBe(true)
      expect(ids[1]).toBe(ids[0])
      expect(ids[2]).not.toBe(ids[0])
    })

    it('buys the VIP account, and sets the wish of the driver', async () => {
      const calls = serveApi(fetchMock, {
        'POST /api/v1/premium/vip': () => respond(201, { data: premiumView({ balance: 5, vip: vipOffer({ active: true, until_ms: Date.now() + 86_400_000 }) }), repeated: false, spent_coins: 150 }),
        'PATCH /api/v1/premium/autorefill': () => respond(200, { data: premiumView({ autorefill: refillOffer({ preference: false }) }) }),
      })
      const store = usePremiumStore()

      expect((await store.buyVip()).coins).toBe(150)
      expect(store.vipActive).toBe(true)

      await store.setAutoRefill(false)

      expect(calls[1]).toMatchObject({ method: 'PATCH', body: { enabled: false } })
      expect(store.autorefill?.preference).toBe(false)
    })
  })
})
