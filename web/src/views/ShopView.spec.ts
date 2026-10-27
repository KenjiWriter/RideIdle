import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { aCrates, anOpenedCrate, aPart, anOffer } from '@/test/garageFixtures'
import { mountView } from '@/test/mountView'
import { aTutorial } from '@/test/tutorialFixtures'

import ShopView from './ShopView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)

const shop = (cashCents = 90_000, level = 3, crates = aCrates()) =>
  respond(200, {
    data: {
      crates,
      parts: [
        anOffer(),
        anOffer({ slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', price_cents: 57_000, stats: { grip: 100, handling: 98, braking: 100 }, required_car_level: 3, mountable: level >= 3 }),
        anOffer({ slug: 'sport-suspension-t1', name: 'Sport suspension', slot: 'suspension', tier: 1, price_cents: 75_000, stats: { handling: 100, clearance: 25 }, required_car_level: 0, mountable: true }),
      ],
      car: { id: 5, level },
    },
    wallet: { cash_cents: cashCents, xp: 0 },
  })

describe('ShopView', () => {
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

  it('lists the parts with the slot, the tier, what they do and the price', async () => {
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Shop')
    expect(wrapper.get('[data-test="cash-chip"]').text()).toBe('$900.00')

    const exhaust = wrapper.get('[data-test="offer-sport-exhaust-t2"]')
    expect(exhaust.get('h2').text()).toBe('Sport exhaust')
    expect(exhaust.text()).toContain('Exhaust')
    expect(exhaust.text()).toContain('T2')
    expect(exhaust.get('[data-test="part-stats"]').text()).toBe('Power +2%')
    expect(exhaust.get('[data-test="buy-sport-exhaust-t2"]').text()).toBe('Buy for $475.00')

    expect(wrapper.findAll('[data-test="offer-street-tires-t2"] [data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 100', 'Handling 98', 'Braking 100'])
  })

  it('buys a part, says where it is and offers the way to the workshop', async () => {
    const calls = serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => respond(201, { data: aPart(), purchase: { cost_cents: 47_500, repeated: false }, wallet: { cash_cents: 42_500, xp: 0 } }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper, router } = await mountView(ShopView, '/shop')
    await flushPromises()

    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ part: 'sport-exhaust-t2', request_id: expect.any(String) })
    expect(wrapper.get('[data-test="done"]').text()).toContain('Added to your inventory: Sport exhaust. Put it on in the workshop.')
    expect(wrapper.get('[data-test="cash-chip"]').text()).toBe('$425.00')

    await wrapper.get('[data-test="to-workshop"]').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('workshop'))
  })

  it('does not let a part be bought without the cash for it, and says so', async () => {
    serve({ 'GET /api/v1/shop': () => shop(50_000) })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="buy-sport-exhaust-t2"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="buy-street-tires-t2"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="offer-street-tires-t2"] [data-test="no-cash"]').text()).toBe('Not enough cash.')
  })

  it('says that the car is too low a level to mount a part, but still sells it', async () => {
    serve({ 'GET /api/v1/shop': () => shop(90_000, 2) })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="offer-street-tires-t2"] [data-test="needs-level"]').text()).toBe('Needs car level 3 Your car is level 2. You get XP by driving this car.')
    expect(wrapper.find('[data-test="offer-sport-suspension-t1"] [data-test="needs-level"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="buy-street-tires-t2"]').attributes('disabled')).toBeUndefined()
  })

  it('shows what the server says when it refuses, in the language of the driver', async () => {
    serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()
    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough cash.')
    expect(wrapper.find('[data-test="done"]').exists()).toBe(false)
  })

  it('says it when the same purchase was already made', async () => {
    serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => respond(200, { data: null, purchase: { cost_cents: 0, repeated: true }, wallet: { cash_cents: 42_500, xp: 0 } }),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()
    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="done"]').text()).toContain('That purchase was already made.')
  })

  it('buys one part at a time', async () => {
    let finish: (response: Response) => void = () => undefined
    const calls = serve({
      'GET /api/v1/shop': () => shop(),
      'POST /api/v1/shop/purchases': () => new Promise<Response>((resolve) => (finish = resolve)) as unknown as Response,
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await wrapper.get('[data-test="buy-sport-exhaust-t2"]').trigger('click')
    await wrapper.get('[data-test="buy-sport-suspension-t1"]').trigger('click')

    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)
    expect(wrapper.get('[data-test="buy-sport-exhaust-t2"]').text()).toBe('Buying…')

    finish(respond(201, { data: aPart(), purchase: { cost_cents: 47_500, repeated: false }, wallet: { cash_cents: 42_500, xp: 0 } }))
    await flushPromises()
  })

  it('offers a retry when the shop cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/shop': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : shop()) })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The shop could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-test^="offer-"]')).toHaveLength(3)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Sklep')
    expect(wrapper.get('[data-test="offer-sport-exhaust-t2"] [data-test="part-stats"]').text()).toBe('Moc +2%')
    expect(wrapper.get('[data-test="offer-sport-exhaust-t2"]').text()).toContain('Wydech')
  })

  it('has the navigation of the game, with the shop marked', async () => {
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="game-nav"]').attributes('aria-label')).toBe('Game navigation')
    expect(wrapper.get('[data-test="nav-shop"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="nav-workshop"]').classes()).not.toContain('on')
  })

  it('has the tabs of the city, with the shop lit, that lead to the exchange and the dealer', async () => {
    serve({ 'GET /api/v1/shop': () => shop() })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.get('[data-test="tab-shop"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="tab-exchange"]').classes()).not.toContain('on')
    expect(wrapper.get('[data-test="tab-exchange"]').attributes('href')).toBe('/exchange')
    expect(wrapper.get('[data-test="tab-dealer"]').attributes('href')).toBe('/dealer')
  })

  describe('the crates', () => {
    const open = async (cashCents = 500_000, crates = aCrates()) => {
      const calls = serve({
        'GET /api/v1/shop': () => shop(cashCents, 3, crates),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })
      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      return { wrapper, calls }
    }

    it('lists the kinds of crate with the tier, the price and the odds shown before the purchase', async () => {
      const { wrapper } = await open()

      expect(wrapper.get('[data-test="crates"] h2').text()).toBe('Crates')

      const slot = wrapper.get('[data-test="crate-slot"]')
      expect(slot.get('[data-test="crate-name"]').text()).toBe('Slot crate')
      expect(slot.get('[data-test="crate-tier"]').text()).toBe('T2')
      expect(slot.get('[data-test="crate-open"]').text()).toBe('Buy · $595.00')
      expect(slot.findAll('[data-test="crate-odds"] span').map((odd) => odd.text())).toEqual(['Common 62%', 'Uncommon 25%', 'Rare 10%', 'Epic 2.7%', 'Legendary 0.3%'])
      expect(slot.findAll('[data-test="crate-bar"] i')).toHaveLength(5)
      expect(slot.findAll('[data-test="crate-bar"] i').map((segment) => segment.attributes('style'))).toEqual([
        expect.stringContaining('flex-grow: 62'),
        expect.stringContaining('flex-grow: 25'),
        expect.stringContaining('flex-grow: 10'),
        expect.stringContaining('flex-grow: 2.7'),
        expect.stringContaining('flex-grow: 0.3'),
      ])

      // A mixed crate has one price, and no slot to choose.
      const mixed = wrapper.get('[data-test="crate-mixed"]')
      expect(mixed.get('[data-test="crate-open"]').text()).toBe('Buy · $1,043.00')
      expect(mixed.find('[data-test="crate-slots"]').exists()).toBe(false)
      expect(mixed.find('[data-test="crate-tier"]').exists()).toBe(false)
    })

    it('says what level a crate asks for, and does not show odds that cannot be used yet', async () => {
      const { wrapper } = await open()

      const premium = wrapper.get('[data-test="crate-premium"]')

      expect(premium.get('[data-test="crate-locked"]').text()).toBe('Requires player level 6')
      expect(premium.get('[data-test="crate-locked"]').attributes('disabled')).toBeDefined()
      expect(premium.find('[data-test="crate-open"]').exists()).toBe(false)
      expect(premium.find('[data-test="crate-odds"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="guarantee-premium"]').exists()).toBe(false)
    })

    it('shows how far the guarantees are, as a count and a bar', async () => {
      const { wrapper } = await open()

      const guarantee = wrapper.get('[data-test="guarantee-standard"]')

      expect(guarantee.get('[data-test="guarantee-text"]').text()).toBe('Guarantee (Rare or better): 4 to go')
      expect(guarantee.get('[data-test="guarantee-count"]').text()).toBe('6 / 10')
      expect(guarantee.get('.bar i').attributes('style')).toContain('width: 60%')
      expect(guarantee.get('[data-test="legendary-text"]').text()).toBe('Guarantee (Legendary): 130 to go')
    })

    it('lets the driver choose the slot, and the price and the tier are the ones of that slot', async () => {
      const { wrapper } = await open()

      expect(wrapper.get('[data-test="crate-slot-tires"]').attributes('aria-checked')).toBe('true')

      await wrapper.get('[data-test="crate-slot-suspension"]').trigger('click')

      expect(wrapper.get('[data-test="crate-slot-suspension"]').attributes('aria-checked')).toBe('true')
      expect(wrapper.get('[data-test="crate-slot-tires"]').attributes('aria-checked')).toBe('false')
      expect(wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').text()).toBe('Buy · $950.00')
    })

    it('says that the first crate is free while the tutorial gives it, and points the tutorial at the first crate that can be opened', async () => {
      const base = aCrates()
      const crates = {
        ...base,
        free_first: true,
        kinds: base.kinds.map((kind) => ({ ...kind, price_cents: kind.price_cents === null ? null : 0, slots: kind.slots.map((slot) => ({ ...slot, price_cents: slot.price_cents === null ? null : 0 })) })),
      }
      serve({ 'GET /api/v1/shop': () => shop(0, 3, crates), 'GET /api/v1/tutorial': () => respond(200, aTutorial(null)), 'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }) })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').text()).toBe('Free')
      expect(wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.findAll('[data-tutorial="crate"]')).toHaveLength(1)
      expect(wrapper.get('[data-tutorial="crate"]').element.closest('[data-test="crate-slot"]')).not.toBeNull()
    })

    it('asks the tutorial again when a crate was opened, because a step waits for it', async () => {
      const calls = serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/slot/open': () => respond(201, { data: { parts: [], car: { id: 5, level: 3 }, crates: aCrates() }, wallet: { cash_cents: 440_500, xp: 0 }, repeated: false, opened: anOpenedCrate() }),
        'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })
      const asked = () => calls.filter((call) => call.method === 'GET' && call.url === '/api/v1/tutorial').length

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      expect(asked()).toBe(1)

      await wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      expect(asked()).toBe(2)
    })

    it('opens a crate with one request id and the slot that was chosen, and shows what was in it', async () => {
      const after = { ...aCrates(), groups: { ...aCrates().groups, standard: { ...aCrates().groups.standard, until: 3, legendary_until: 129 } } }
      const calls = serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/slot/open': () => respond(201, { data: { parts: [], car: { id: 5, level: 3 }, crates: after }, wallet: { cash_cents: 440_500, xp: 0 }, repeated: false, opened: anOpenedCrate() }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      expect(wrapper.find('[data-test="found"]').exists()).toBe(false)

      await wrapper.get('[data-test="crate-slot-suspension"]').trigger('click')
      await wrapper.get('[data-test="crate-slot-tires"]').trigger('click')
      await wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      const body = calls.find((call) => call.method === 'POST')?.body as Record<string, string>
      expect(body).toMatchObject({ slot: 'tires' })
      expect(body.request_id).toEqual(expect.any(String))

      const found = wrapper.get('[data-test="found"]')
      expect(found.attributes('data-rarity')).toBe('epic')
      expect(found.get('[data-test="found-name"]').text()).toBe('Street tires')
      expect(found.get('[data-test="rarity"]').text()).toBe('Epic')
      expect(found.findAll('.pips b.on')).toHaveLength(4)
      expect(found.findAll('[data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 138', 'Handling 135.2', 'Braking 138'])
      expect(found.text()).toContain('T2')
      expect(found.get('[data-test="found-to-workshop"]').text()).toBe('To the workshop')
      expect(found.find('[data-test="found-needs-level"]').exists()).toBe(false)
      // The guarantee moved, and so did the cash.
      expect(wrapper.get('[data-test="guarantee-count"]').text()).toBe('7 / 10')
      expect(wrapper.get('[data-test="cash-chip"]').text()).toBe('$4,405.00')
    })

    it('says what level the car needs when the part cannot be mounted yet', async () => {
      serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/slot/open': () =>
          respond(201, { data: { parts: [], car: { id: 5, level: 3 }, crates: aCrates() }, wallet: { cash_cents: 440_500, xp: 0 }, repeated: false, opened: anOpenedCrate({ tier: 3, required_car_level: 6, mountable: false }) }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      await wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="found-needs-level"]').text()).toBe('Needs car level 6 Your car is level 3.')
    })

    it('opens a mixed crate without a slot', async () => {
      const calls = serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/mixed/open': () => respond(201, { data: { parts: [], car: { id: 5, level: 3 }, crates: aCrates() }, wallet: { cash_cents: 395_700, xp: 0 }, repeated: false, opened: anOpenedCrate({ rarity: 'common', rarity_rank: 1 }) }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      await wrapper.get('[data-test="crate-mixed"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      const body = calls.find((call) => call.method === 'POST')?.body as Record<string, string>

      expect(body).not.toHaveProperty('slot')
      expect(wrapper.get('[data-test="found"] [data-test="rarity"]').text()).toBe('Common')
      expect(wrapper.findAll('[data-test="found"] .pips b.on')).toHaveLength(1)
    })

    it('does not let the driver open a crate that costs more than the cash', async () => {
      // $500 is not enough for the tires ($595), the suspension ($950) or a mixed crate ($1,043).
      const { wrapper } = await open(50_000)

      expect(wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="crate-mixed"] [data-test="crate-open"]').attributes('disabled')).toBeDefined()

      await wrapper.get('[data-test="crate-slot-suspension"]').trigger('click')

      expect(wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').attributes('disabled')).toBeDefined()

      // With $700 the tires can be bought and the suspension cannot.
      const { wrapper: richer } = await open(70_000)

      expect(richer.get('[data-test="crate-slot"] [data-test="crate-open"]').attributes('disabled')).toBeUndefined()

      await richer.get('[data-test="crate-slot-suspension"]').trigger('click')

      expect(richer.get('[data-test="crate-slot"] [data-test="crate-open"]').attributes('disabled')).toBeDefined()
    })

    it('shows what the server says when it refuses a crate', async () => {
      serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/slot/open': () => respond(422, { message: 'Requires player level 6 (you are level 3). You get XP by driving.', code: 'crate_level_required' }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      await wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Requires player level 6 (you are level 3). You get XP by driving.')
      expect(wrapper.find('[data-test="found"]').exists()).toBe(false)
    })

    it('shows nothing new for a crate that was opened before', async () => {
      serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/slot/open': () => respond(200, { data: { parts: [], car: { id: 5, level: 3 }, crates: aCrates() }, wallet: { cash_cents: 440_500, xp: 0 }, repeated: true, opened: null }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      await wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="found"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="error"]').exists()).toBe(false)
    })

    it('speaks the language of the driver, with the decimal comma of Polish', async () => {
      await setLocale('pl')
      const { wrapper } = await open()

      expect(wrapper.get('[data-test="crates"] h2').text()).toBe('Skrzynki')
      expect(wrapper.get('[data-test="crate-slot"] [data-test="crate-name"]').text()).toBe('Skrzynka slotowa')
      expect(wrapper.get('[data-test="crate-slot"] [data-test="odds-epic"]').text()).toBe('Epicka 2,7%')
      expect(wrapper.get('[data-test="crate-premium"] [data-test="crate-locked"]').text()).toBe('Wymaga poziomu gracza 6')
      expect(wrapper.get('[data-test="guarantee-text"]').text()).toBe('Gwarancja (Rzadka lub lepsza): jeszcze 4')
    })
  })
})
