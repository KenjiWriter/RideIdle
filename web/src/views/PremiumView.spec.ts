import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { PremiumView } from '@/api/types'
import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'
import { passOffer, premiumView, refillOffer, vipOffer } from '@/test/premiumFixtures'

import PremiumView_ from './PremiumView.vue'

const fetchMock = vi.fn()

/** The shop answers with this view; the calls it gets are returned. */
function serve(view: () => PremiumView, routes: Record<string, () => Response | Promise<Response>> = {}) {
  return serveApi(fetchMock, { 'GET /api/v1/premium': () => respond(200, { data: view() }), ...routes })
}

async function open() {
  const mounted = await mountView(PremiumView_, '/premium')
  await flushPromises()

  return mounted
}

describe('PremiumView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the balance and the three extras, with the stock and what each refill costs in a pack', async () => {
    serve(() => premiumView({ balance: 31, autorefill: refillOffer({ stock: 12 }) }))

    const { wrapper } = await open()

    expect(wrapper.get('h1').text()).toBe('Premium')
    expect(wrapper.get('[data-test="balance-line"]').text()).toBe('You have 31 RideCoin')
    expect(wrapper.get('[data-test="refill-stock"]').text()).toBe('In stock: 12 of 100')
    expect(wrapper.get('[data-test="pack-refill-1"]').text()).toContain('1 refill')
    expect(wrapper.get('[data-test="pack-refill-1"] [data-test="pack-each"]').text()).toBe('2 RideCoin each')
    expect(wrapper.get('[data-test="pack-refill-5"] [data-test="pack-each"]').text()).toBe('1.8 RideCoin each')
    expect(wrapper.get('[data-test="pack-refill-20"]').text()).toContain('20 refills')
    expect(wrapper.find('[data-test="vip"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="pass"]').exists()).toBe(true)
  })

  it('is reached from the coins in the bar at the top', async () => {
    serve(() => premiumView())

    const { wrapper } = await open()

    expect(wrapper.get('[data-test="coins-chip"]').attributes('href')).toBe('/premium')
  })

  describe('the auto-refill', () => {
    it('asks before it takes the coins, then buys the pack once with one request id and says what was added', async () => {
      let view = premiumView({ balance: 31 })
      const calls = serve(() => view, {
        'POST /api/v1/premium/autorefill': () => {
          view = premiumView({ balance: 22, autorefill: refillOffer({ stock: 5 }) })

          return respond(201, { data: view, repeated: false, spent_coins: 9, bought_charges: 5 })
        },
      })

      const { wrapper } = await open()
      await wrapper.get('[data-test="buy-refill-5"]').trigger('click')

      expect(wrapper.get('[data-test="buy-refill-5-sure"]').text()).toContain('Pay 9 RideCoin? You have 31.')
      expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)

      await wrapper.get('[data-test="buy-refill-5-confirm"]').trigger('click')
      await flushPromises()

      const posts = calls.filter((call) => call.method === 'POST')

      expect(posts).toHaveLength(1)
      expect(posts[0].body).toMatchObject({ pack: 'refill-5' })
      expect(wrapper.get('[data-test="done"]').text()).toBe('Added 5 refills for 9 RideCoin.')
      expect(wrapper.get('[data-test="refill-stock"]').text()).toBe('In stock: 5 of 100')
      expect(wrapper.get('[data-test="balance-line"]').text()).toBe('You have 22 RideCoin')
    })

    it('does not take coins when the driver says no', async () => {
      const calls = serve(() => premiumView())

      const { wrapper } = await open()
      await wrapper.get('[data-test="buy-refill-1"]').trigger('click')
      await wrapper.get('[data-test="buy-refill-1-keep"]').trigger('click')

      expect(calls.filter((call) => call.method === 'POST')).toHaveLength(0)
      expect(wrapper.find('[data-test="buy-refill-1-sure"]').exists()).toBe(false)
    })

    it('says a driver has too few coins instead of offering the purchase, and does not offer a pack that the stock cannot take', async () => {
      serve(() => premiumView({ balance: 1, autorefill: refillOffer({ stock: 90 }) }))

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="buy-refill-1"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="buy-refill-1-poor"]').text()).toBe('Not enough RideCoin: you have 1.')
      expect(wrapper.find('[data-test="buy-refill-20"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="pack-refill-20-full"]').text()).toBe('Your stock cannot take this pack: the most you can keep is 100.')
      expect(wrapper.find('[data-test="buy-refill-5"]').exists()).toBe(true)
    })

    it('says what the server refuses in the language of the driver, and reads the shop again', async () => {
      const calls = serve(() => premiumView({ balance: 50 }), {
        'POST /api/v1/premium/autorefill': () => respond(422, { message: 'Not enough premium coins: you have 1, you need 9.', code: 'insufficient_premium' }),
      })

      const { wrapper } = await open()
      await wrapper.get('[data-test="buy-refill-5"]').trigger('click')
      await wrapper.get('[data-test="buy-refill-5-confirm"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough premium coins: you have 1, you need 9.')
      expect(calls.filter((call) => call.method === 'GET' && call.url.endsWith('/v1/premium'))).toHaveLength(2)
    })

    it('switches the wish to have the tank filled off and on', async () => {
      let preference = true
      const calls = serve(() => premiumView({ autorefill: refillOffer({ stock: 3, preference }) }), {
        'PATCH /api/v1/premium/autorefill': () => {
          preference = !preference

          return respond(200, { data: premiumView({ autorefill: refillOffer({ stock: 3, preference }) }) })
        },
      })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="refill-state"]').text()).toBe('On: the tank is filled when the fuel runs out.')

      await wrapper.get('[data-test="refill-switch"]').setValue(false)
      await flushPromises()

      expect(calls.find((call) => call.method === 'PATCH')?.body).toEqual({ enabled: false })
      expect(wrapper.get('[data-test="refill-state"]').text()).toBe('Off: the car stops when the fuel runs out.')
    })

    it('says that the refills are free while the pass is on', async () => {
      const now = Date.now()
      serve(() => premiumView({ now_ms: now, infinity: passOffer({ active: true, until_ms: now + 86_400_000, remaining_ms: 86_400_000 }) }))

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="refill-pass"]').text()).toBe('The pass is on: the refills are free.')
    })

    it('does not say the refills are free while the driver has the wish switched off, because then nothing is filled', async () => {
      const now = Date.now()
      serve(() => premiumView({ now_ms: now, autorefill: refillOffer({ preference: false }), infinity: passOffer({ active: true, until_ms: now + 86_400_000, remaining_ms: 86_400_000 }) }))

      const { wrapper } = await open()

      expect(wrapper.find('[data-test="refill-pass"]').exists()).toBe(false)
    })
  })

  describe('the VIP account', () => {
    it('is bought for the coins of the rules, and the time it lasts is shown', async () => {
      const now = Date.now()
      let view = premiumView({ now_ms: now, balance: 200 })
      const calls = serve(() => view, {
        'POST /api/v1/premium/vip': () => {
          view = premiumView({ now_ms: now, balance: 50, vip: vipOffer({ active: true, until_ms: now + 30 * 86_400_000 + 600_000, remaining_ms: 30 * 86_400_000 + 600_000 }) })

          return respond(201, { data: view, repeated: false, spent_coins: 150 })
        },
      })

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="vip-text"]').text()).toBe('While it lasts every lap pays 20% more cash and 20% more XP, on top of what the track pays.')
      expect(wrapper.get('[data-test="vip-status"]').text()).toBe('Not on')
      expect(wrapper.get('[data-test="buy-vip"]').text()).toBe('Get VIP for 30 days · 150 RideCoin')

      await wrapper.get('[data-test="buy-vip"]').trigger('click')
      await wrapper.get('[data-test="buy-vip-confirm"]').trigger('click')
      await flushPromises()

      expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
      expect(wrapper.get('[data-test="done"]').text()).toBe('VIP lasts 30 days longer.')
      expect(wrapper.get('[data-test="vip-status"]').text()).toBe('On: 30 d 0 h left')
      // Buying again extends it.
      expect(wrapper.get('[data-test="buy-vip"]').text()).toBe('Extend by 30 days · 150 RideCoin')
    })

    it('says the game switched it off and keeps what the driver has, without a way to buy more', async () => {
      const now = Date.now()
      serve(() => premiumView({ now_ms: now, vip: vipOffer({ enabled: false, until_ms: now + 3 * 86_400_000 + 600_000 }) }))

      const { wrapper } = await open()

      expect(wrapper.find('[data-test="buy-vip"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="vip-off-game"]').text()).toBe('The game has switched this off for now. What you have is kept.')
      expect(wrapper.get('[data-test="vip-status"]').text()).toBe('Not on')
    })
  })

  describe('the pass of the endless refill', () => {
    it('shows its price in real money and why it cannot be bought yet, with no button', async () => {
      serve(() => premiumView())

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="pass-price"]').text()).toMatch(/PLN\s?19\.99 for 30 days/)
      expect(wrapper.get('[data-test="pass-unavailable"]').text()).toContain('cannot be bought in the game yet')
      expect(wrapper.find('[data-test="buy-pass"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="pass-status"]').text()).toBe('Not on')
    })

    it('shows how long it still lasts when the operator gave it', async () => {
      const now = Date.now()
      serve(() => premiumView({ now_ms: now, infinity: passOffer({ active: true, until_ms: now + 2 * 3_600_000 + 600_000, remaining_ms: 2 * 3_600_000 + 600_000 }) }))

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="pass-status"]').text()).toBe('On: 2 h 10 min left')
    })

    it('has the price in the currency and the language of the shop', async () => {
      await setLocale('pl')
      serve(() => premiumView())

      const { wrapper } = await open()

      expect(wrapper.get('h1').text()).toBe('Premium')
      expect(wrapper.get('[data-test="pass-price"]').text()).toMatch(/19,99\s?(zł|PLN)\s+za 30 dni/)
      expect(wrapper.get('[data-test="vip-status"]').text()).toBe('Nieaktywne')
      expect(wrapper.get('[data-test="pack-refill-5"]').text()).toContain('5 tankowań')
    })
  })

  describe('a game that sells less', () => {
    it('shows only what is sold, and says so when it sells nothing', async () => {
      serve(() => premiumView({ vip: null, infinity: null, autorefill: refillOffer({ enabled: false }) }))

      const { wrapper } = await open()

      expect(wrapper.find('[data-test="vip"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="pass"]').exists()).toBe(false)
      // An extra that is off and that the driver has none of is not worth a card.
      expect(wrapper.find('[data-test="autorefill"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="nothing"]').text()).toBe('This game sells no extras.')
    })

    it('keeps the stock of a driver in a game that switched the auto-refill off, and offers no packs', async () => {
      serve(() => premiumView({ autorefill: refillOffer({ enabled: false, stock: 7 }) }))

      const { wrapper } = await open()

      expect(wrapper.get('[data-test="refill-stock"]').text()).toBe('In stock: 7 of 100')
      expect(wrapper.find('[data-test="refill-off-game"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="refill-switch"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="pack-refill-1"]').exists()).toBe(false)
    })
  })

  it('says it could not be loaded, and tries again', async () => {
    let attempt = 0
    serveApi(fetchMock, { 'GET /api/v1/premium': () => (attempt++ === 0 ? respond(500, { message: 'down' }) : respond(200, { data: premiumView() })) })

    const { wrapper } = await mountView(PremiumView_, '/premium')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The premium shop could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="autorefill"]').exists()).toBe(true)
  })
})
