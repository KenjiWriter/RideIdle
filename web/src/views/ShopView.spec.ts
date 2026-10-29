import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { aCar, aCrates, aGarage, aMountedPart, anOpenedCrate, aPart, anOffer } from '@/test/garageFixtures'
import { DEFAULT_MODEL, setModel } from '@/model'
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

  it('shows the picture that the pack gives a part, and nothing for a part that has none', async () => {
    serve({
      'GET /api/v1/shop': () =>
        respond(200, {
          data: { crates: aCrates(), parts: [anOffer({ image_url: '/api/v1/media/aaaaaaaa.webp' }), anOffer({ slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires' })], car: { id: 5, level: 3 } },
          wallet: { cash_cents: 90_000, xp: 0 },
        }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    const picture = wrapper.get('[data-test="offer-sport-exhaust-t2"] [data-test="part-picture"]')
    expect(picture.attributes('src')).toBe('/api/v1/media/aaaaaaaa.webp')
    expect(picture.attributes('alt')).toBe('Sport exhaust')
    expect(wrapper.find('[data-test="offer-street-tires-t2"] [data-test="part-picture"]').exists()).toBe(false)
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

    it('shows the affixes the part was drawn with, and the unique effect of a legendary one', async () => {
      const affixes = [
        { key: 'grip', name: 'Grip', effect: 'stat', stat: 'grip', surfaces: null, value_ppm: 35_000, percent: 3.5 },
        { key: 'wear', name: 'Durability', effect: 'wear', stat: null, surfaces: null, value_ppm: 120_000, percent: 12 },
      ] as const
      const unique = { name: 'Adaptive compound', special: 'surface_loss', percent: 50, mods: null } as const
      serve({
        'GET /api/v1/shop': () => shop(500_000),
        'POST /api/v1/shop/crates/slot/open': () =>
          respond(201, { data: { parts: [], car: { id: 5, level: 3 }, crates: aCrates() }, wallet: { cash_cents: 440_500, xp: 0 }, repeated: false, opened: anOpenedCrate({ rarity: 'legendary', rarity_rank: 5, affixes: [...affixes], unique }) }),
        'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
      })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      await wrapper.get('[data-test="crate-slot-tires"]').trigger('click')
      await wrapper.get('[data-test="crate-slot"] [data-test="crate-open"]').trigger('click')
      await flushPromises()

      const found = wrapper.get('[data-test="found"]')

      expect(found.findAll('[data-test="part-affixes"] li').map((line) => line.text())).toEqual(['✦ Grip +3.5%', '✦ Durability: wear −12%'])
      expect(found.get('[data-test="part-unique"]').text()).toContain('Adaptive compound')
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

  describe('the engine group', () => {
    const grande = anOffer({
      slug: 'turbo-grande-t6',
      name: 'Turbo Grande',
      slot: 'boost',
      tier: 6,
      price_cents: 2_100_000,
      stats: { power_mult: 1.4, lag: 6, fuel_mult: 1.2, engine_load: 13 },
      requires: { min_cylinders: 6 },
      required_car_level: 15,
      mountable: false,
      blocked_by: { reason: 'cylinders', required: 6, current: 4 },
    })
    const intercooler = anOffer({
      slug: 'intercooler-factory-t2',
      name: 'Factory intercooler',
      slot: 'cooling',
      tier: 2,
      price_cents: 120_000,
      stats: { boost_gain: 0.01, engine_load: -2, mass_kg: 3 },
      requires: { booster: true },
      required_car_level: 3,
      mountable: false,
      blocked_by: { reason: 'booster' },
    })
    const roots = anOffer({ slug: 'roots-mini-t2', name: 'Roots mini', slot: 'boost', tier: 2, price_cents: 360_000, stats: { power_mult: 1.12, lag: 0, fuel_mult: 1.1, engine_load: 4 }, requires: null, required_car_level: 3, mountable: true, blocked_by: null })
    const tires = anOffer({ slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', price_cents: 57_000, stats: { grip: 100, handling: 98, braking: 100 }, required_car_level: 3, mountable: true })
    const parts = [tires, roots, grande, intercooler]
    const engineShop = (level = 15) => respond(200, { data: { crates: aCrates(), parts, car: { id: 5, level } }, wallet: { cash_cents: 10_000_000, xp: 0 } })

    it('lists the parts under their slot, in the order of the garage', async () => {
      serve({ 'GET /api/v1/shop': () => engineShop() })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(wrapper.findAll('[data-test="group-title"]').map((title) => title.text())).toEqual(['Tires', 'Forced induction', 'Cooling'])
      expect(wrapper.findAll('[data-test="group-boost"] [data-test^="offer-"]').map((offer) => offer.attributes('data-test'))).toEqual(['offer-roots-mini-t2', 'offer-turbo-grande-t6'])
    })

    it('narrows the list to one slot, and back to all of them', async () => {
      serve({ 'GET /api/v1/shop': () => engineShop() })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()
      await wrapper.get('[data-test="filter-cooling"]').trigger('click')

      expect(wrapper.findAll('[data-test^="offer-"]').map((offer) => offer.attributes('data-test'))).toEqual(['offer-intercooler-factory-t2'])
      expect(wrapper.get('[data-test="filter-cooling"]').attributes('aria-checked')).toBe('true')

      await wrapper.get('[data-test="filter-all"]').trigger('click')

      expect(wrapper.findAll('[data-test^="offer-"]')).toHaveLength(4)
    })

    it('has no filter and no titles when the shop has parts of one slot only', async () => {
      serve({ 'GET /api/v1/shop': () => respond(200, { data: { crates: aCrates(), parts: [tires], car: { id: 5, level: 3 } }, wallet: { cash_cents: 90_000, xp: 0 } }) })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(wrapper.find('[data-test="part-filter"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="group-title"]').exists()).toBe(false)
    })

    it('says what the car lacks for a part besides its level, and still lets the driver buy it', async () => {
      serve({ 'GET /api/v1/shop': () => engineShop(15) })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(wrapper.get('[data-test="offer-turbo-grande-t6"] [data-test="blocked"]').text()).toBe('This car has 4 cylinders, the part needs 6')
      expect(wrapper.get('[data-test="offer-intercooler-factory-t2"] [data-test="blocked"]').text()).toBe('Put a booster on the car first')
      expect(wrapper.find('[data-test="offer-roots-mini-t2"] [data-test="blocked"]').exists()).toBe(false)
      // The level of the car (15) is what the Grande asks, so the level is not what is said to be missing.
      expect(wrapper.find('[data-test="offer-turbo-grande-t6"] [data-test="needs-level"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="buy-turbo-grande-t6"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.get('[data-test="offer-turbo-grande-t6"] [data-test="part-requires"]').text()).toBe('Needs an engine with at least 6 cylinders')
    })

    it('groups the engines under their own slot and says what the bay of the body does not take', async () => {
      const fits = anOffer({ slug: 'engine-r4-2-5-t5', name: 'R4 2.5 engine', slot: 'engine', tier: 5, price_cents: 560_504, stats: { power_kw: 162.5, engine_mass_kg: 117.5 }, engine: { layout: 'R4', displacement_l: 2.5 }, required_car_level: 12, mountable: true, blocked_by: null })
      const wrongLayout = anOffer({
        slug: 'engine-v8-4-0-t5',
        name: 'V8 4.0 engine',
        slot: 'engine',
        tier: 5,
        price_cents: 1_494_678,
        stats: { power_kw: 296.4, engine_mass_kg: 278 },
        engine: { layout: 'V8', displacement_l: 4 },
        required_car_level: 12,
        mountable: false,
        blocked_by: { reason: 'bay_layout', layout: 'V8', allowed: ['R4', 'V6'] },
      })
      const tooBig = anOffer({
        slug: 'engine-v6-3-2-t5',
        name: 'V6 3.2 engine',
        slot: 'engine',
        tier: 5,
        price_cents: 876_878,
        stats: { power_kw: 228.8, engine_mass_kg: 171 },
        engine: { layout: 'V6', displacement_l: 3.2 },
        required_car_level: 12,
        mountable: false,
        blocked_by: { reason: 'bay_size', displacement_l: 3.2, max_displacement_l: 3 },
      })
      serve({ 'GET /api/v1/shop': () => respond(200, { data: { crates: aCrates(), parts: [tires, fits, wrongLayout, tooBig], car: { id: 5, level: 12 } }, wallet: { cash_cents: 10_000_000, xp: 0 } }) })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(wrapper.findAll('[data-test="group-title"]').map((title) => title.text())).toEqual(['Tires', 'Engine'])
      expect(wrapper.findAll('[data-test="offer-engine-r4-2-5-t5"] [data-test="part-stats"] li').map((line) => line.text())).toEqual(['R4 2.5', 'Power 163 kW', 'Engine mass 117.5 kg'])
      expect(wrapper.find('[data-test="offer-engine-r4-2-5-t5"] [data-test="blocked"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="offer-engine-v8-4-0-t5"] [data-test="blocked"]').text()).toBe('The engine bay of this car does not take a V8 engine (it takes R4, V6)')
      expect(wrapper.get('[data-test="offer-engine-v6-3-2-t5"] [data-test="blocked"]').text()).toBe('Engine too big: 3.2 l, the bay holds up to 3 l')
      // Like the other reasons, it does not stop the driver from buying the part.
      expect(wrapper.get('[data-test="buy-engine-v8-4-0-t5"]').attributes('disabled')).toBeUndefined()
    })

    it('keeps the layout of the screen with all the 71 parts of a car of the highest level, and narrows them to the 31 engines', async () => {
      const engines = Array.from({ length: 31 }, (_, index) =>
        anOffer({ slug: `engine-r4-1-0-t${index}`, name: `Engine ${index}`, slot: 'engine', tier: 1 + (index % 10), price_cents: 67_500, stats: { power_kw: 50, engine_mass_kg: 80 }, engine: { layout: 'R4', displacement_l: 1 }, required_car_level: 3, mountable: true, blocked_by: null }),
      )
      const others = Array.from({ length: 40 }, (_, index) => anOffer({ slug: `part-${index}`, name: `Part ${index}`, slot: ['tires', 'suspension', 'brakes', 'exhaust'][index % 4]!, tier: 1, price_cents: 10_000, stats: { grip: 90 }, required_car_level: 3, mountable: true }))
      serve({ 'GET /api/v1/shop': () => respond(200, { data: { crates: aCrates(), parts: [...others, ...engines], car: { id: 5, level: 30 } }, wallet: { cash_cents: 10_000_000, xp: 0 } }) })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(wrapper.findAll('[data-test^="offer-"]')).toHaveLength(71)

      await wrapper.get('[data-test="filter-engine"]').trigger('click')

      expect(wrapper.findAll('[data-test^="offer-"]')).toHaveLength(31)
      expect(wrapper.findAll('[data-test="group-title"]').map((title) => title.text())).toEqual(['Engine'])
    })

    it('says both when the level and the engine are what the car lacks', async () => {
      serve({ 'GET /api/v1/shop': () => engineShop(3) })

      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      const offer = wrapper.get('[data-test="offer-turbo-grande-t6"]')

      expect(offer.get('[data-test="needs-level"]').text()).toContain('Needs car level 15')
      expect(offer.get('[data-test="blocked"]').text()).toBe('This car has 4 cylinders, the part needs 6')
    })
  })
})

describe('ShopView in a game with another model', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
    setModel({
      slots: [
        { key: 'sails', role: null, hidden: false },
        { key: 'hull', role: 'tires', hidden: false },
      ],
      stats: ['grip'],
    })
  })

  afterEach(() => {
    setModel()
    vi.unstubAllGlobals()
  })

  it('lists the parts under the slots of the model, in its order, and leaves out a slot that the game does not have', async () => {
    serve({
      'GET /api/v1/shop': () =>
        respond(200, {
          data: {
            crates: aCrates(),
            parts: [
              anOffer({ slug: 'plain-hull-t1', name: 'Plain hull', slot: 'hull', tier: 1 }),
              anOffer({ slug: 'old-exhaust-t1', name: 'Old exhaust', slot: 'exhaust', tier: 1 }),
              anOffer({ slug: 'square-sail-t1', name: 'Square sail', slot: 'sails', tier: 1 }),
            ],
            car: { id: 5, level: 3 },
          },
          wallet: { cash_cents: 90_000, xp: 0 },
        }),
    })

    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    expect(wrapper.findAll('section[data-test^="group-"]').map((group) => group.attributes('data-test'))).toEqual(['group-sails', 'group-hull'])
    expect(wrapper.find('[data-test="offer-old-exhaust-t1"]').exists()).toBe(false)
  })
})

describe('ShopView on a stage', () => {
  const FITTED_TIRES = aMountedPart({ slot: 'tires', slug: 'street-tires-t1', name: 'Street tires T1', tier: 1, stats: { grip: 90, handling: 88, braking: 90 } })
  const FITTED_BOOST = aMountedPart({ slot: 'boost', slug: 'turbo-mini-t3', name: 'Turbo mini', tier: 3, stats: { power_mult: 1.38, lag: 4, fuel_mult: 1.28, engine_load: 14 } })

  const parts = [
    anOffer({ slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', price_cents: 57_000, stats: { grip: 100, handling: 98, braking: 100 }, required_car_level: 3, mountable: true }),
    anOffer({ slug: 'sport-suspension-t1', name: 'Sport suspension', slot: 'suspension', tier: 1, price_cents: 75_000, stats: { handling: 100, clearance: 25 }, required_car_level: 0, mountable: true }),
    anOffer({ slug: 'turbo-duo-t4', name: 'Turbo duo', slot: 'boost', tier: 4, price_cents: 120_000, stats: { power_mult: 1.45, lag: 3, fuel_mult: 1.3, engine_load: 14 }, required_car_level: 3, mountable: true }),
  ]

  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    setModel()
    vi.unstubAllGlobals()
  })

  /** The shop of a driver whose garage can be read: the car on the stage, with the parts that are fitted. */
  async function openWith(car = aCar({}, { tires: FITTED_TIRES, boost: FITTED_BOOST }), offers = parts) {
    const calls = serve({
      'GET /api/v1/shop': () => respond(200, { data: { crates: aCrates(), parts: offers, car: { id: 5, level: 3 } }, wallet: { cash_cents: 900_000, xp: 0 } }),
      'GET /api/v1/garage': () => respond(200, aGarage({ car })),
      'GET /api/v1/runs/current': () => respond(404, { message: 'No run', code: 'no_run' }),
    })
    const { wrapper } = await mountView(ShopView, '/shop')
    await flushPromises()

    return { wrapper, calls }
  }

  const groups = (wrapper: Awaited<ReturnType<typeof openWith>>['wrapper']) => wrapper.findAll('section[data-test^="group-"]').map((group) => group.attributes('data-test'))

  describe('the stage', () => {
    it('puts the car of the garage on a stage, with a spot for each of its parts, and says what the spots are for', async () => {
      const { wrapper } = await openWith()

      expect(wrapper.get('[data-test="stage"] .stage-name').text()).toBe('Rdzak GT-4')
      expect(wrapper.get('[data-test="stage-level"]').text()).toBe('Car LV 3')
      expect(wrapper.findAll('[data-test="spots"] .spot')).toHaveLength(9)
      expect(wrapper.get('[data-test="shop-hint"]').text()).toBe('Tap a part on the car to see what is for sale for it.')
      // Nothing is narrowed down, and every slot with a part for sale is listed.
      expect(groups(wrapper)).toEqual(['group-tires', 'group-suspension', 'group-boost'])
    })

    it('asks for the garage when the shop opens, and shows no stage when it cannot be read: the shop works without it', async () => {
      const calls = serve({
        'GET /api/v1/shop': () => respond(200, { data: { crates: aCrates(), parts, car: { id: 5, level: 3 } }, wallet: { cash_cents: 900_000, xp: 0 } }),
        'GET /api/v1/garage': () => respond(500, { message: 'Server error' }),
      })
      const { wrapper } = await mountView(ShopView, '/shop')
      await flushPromises()

      expect(calls.some((call) => call.url.endsWith('/v1/garage'))).toBe(true)
      expect(wrapper.find('[data-test="stage"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="shop-hint"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
      expect(groups(wrapper)).toEqual(['group-tires', 'group-suspension', 'group-boost'])
      // With no stage the row of chips is the way to narrow the list down, with a chip for every slot.
      expect(wrapper.findAll('[data-test="part-filter"] [role="radio"]').map((chip) => chip.attributes('data-test'))).toEqual(['filter-all', 'filter-tires', 'filter-suspension', 'filter-boost'])
    })

    it('narrows the list of parts to the slot that is tapped on the car, and says what is on the car now and how many parts are for sale for it', async () => {
      const { wrapper } = await openWith()

      await wrapper.get('[data-test="spot-tires"]').trigger('click')

      expect(groups(wrapper)).toEqual(['group-tires'])
      expect(wrapper.get('[data-test="spot-tires"]').classes()).toContain('spot-on')
      expect(wrapper.find('[data-test="shop-hint"]').exists()).toBe(false)

      const card = wrapper.get('[data-test="slot-detail"]')
      expect(card.attributes('data-slot')).toBe('tires')
      expect(card.get('[data-test="slot-part"]').text()).toBe('Street tires T1')
      expect(card.get('[data-test="shop-slot-note"]').text()).toBe('For sale for this slot: 1')
      // The card has no way to the workshop of its own: the shop leads there when a part is bought.
      expect(card.find('[data-test="slot-workshop"]').exists()).toBe(false)
    })

    it('shows all the parts again when the same spot is tapped, when the card is closed, and when all the parts are chosen', async () => {
      const { wrapper } = await openWith()

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      expect(groups(wrapper)).toHaveLength(3)
      expect(wrapper.find('[data-test="slot-detail"]').exists()).toBe(false)

      await wrapper.get('[data-test="spot-boost"]').trigger('click')
      await wrapper.get('[data-test="slot-close"]').trigger('click')
      expect(groups(wrapper)).toHaveLength(3)

      await wrapper.get('[data-test="spot-boost"]').trigger('click')
      await wrapper.get('[data-test="filter-all"]').trigger('click')
      expect(groups(wrapper)).toHaveLength(3)
      expect(wrapper.find('[data-test="slot-detail"]').exists()).toBe(false)
    })

    it('shows another slot in place of the one that was looked at', async () => {
      const { wrapper } = await openWith()

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      await wrapper.get('[data-test="spot-suspension"]').trigger('click')

      expect(groups(wrapper)).toEqual(['group-suspension'])
      expect(wrapper.get('[data-test="slot-detail"]').attributes('data-slot')).toBe('suspension')
      // Nothing is fitted on that slot: the card says it is the factory part.
      expect(wrapper.get('[data-test="slot-part"]').text()).toBe('Factory')
    })

    it('brings the list of parts into view when a spot is tapped, and not when it is tapped to put the slot away', async () => {
      const scrolled = vi.fn()
      HTMLElement.prototype.scrollIntoView = scrolled

      try {
        const { wrapper } = await openWith()

        await wrapper.get('[data-test="spot-tires"]').trigger('click')
        await flushPromises()
        expect(scrolled).toHaveBeenCalledTimes(1)

        await wrapper.get('[data-test="spot-tires"]').trigger('click')
        await flushPromises()
        expect(scrolled).toHaveBeenCalledTimes(1)
      } finally {
        // jsdom has no scrollIntoView: the screen goes on without it.
        // @ts-expect-error put back as it was
        delete HTMLElement.prototype.scrollIntoView
      }
    })

    it('says that nothing is for sale for a slot that has no parts in the shop', async () => {
      const { wrapper } = await openWith()

      await wrapper.get('[data-test="spot-tank"]').trigger('click')

      expect(groups(wrapper)).toEqual([])
      expect(wrapper.get('[data-test="shop-slot-note"]').text()).toBe('Nothing is for sale for this slot right now.')
      // The row of chips has the chip of all the parts to go back with.
      expect(wrapper.find('[data-test="filter-all"]').exists()).toBe(true)
    })

    it('leaves the chips to the slots that have no place on the picture, and shows the chip of a slot that is chosen on the car', async () => {
      // A slot of another game has no place on the picture: its parts are told apart by a chip.
      setModel({ ...DEFAULT_MODEL, slots: [...DEFAULT_MODEL.slots, { key: 'wings', role: null, hidden: false }] })

      const car = aCar({ slots: [...aCar().slots, { slot: 'wings', role: null, part: null, built_in: false }] })
      const { wrapper } = await openWith(car, [...parts.slice(0, 2), anOffer({ slug: 'big-wing-t1', name: 'Big wing', slot: 'wings', tier: 1 })])

      // The row is there for the one slot that has no spot, and the slots that have one are not in it.
      expect(wrapper.findAll('[data-test="part-filter"] [role="radio"]').map((chip) => chip.attributes('data-test'))).toEqual(['filter-all', 'filter-wings'])

      await wrapper.get('[data-test="filter-wings"]').trigger('click')
      expect(groups(wrapper)).toEqual(['group-wings'])
      // A chip that is on stays on when it is tapped again: the chip of all the parts turns it off.
      await wrapper.get('[data-test="filter-wings"]').trigger('click')
      expect(groups(wrapper)).toEqual(['group-wings'])

      await wrapper.get('[data-test="spot-tires"]').trigger('click')
      expect(wrapper.findAll('[data-test="part-filter"] [role="radio"]').map((chip) => chip.attributes('data-test'))).toEqual(['filter-all', 'filter-tires', 'filter-wings'])
      expect(wrapper.get('[data-test="filter-tires"]').attributes('aria-checked')).toBe('true')
    })

    it('has no spots, and a chip for every slot, for a car that has no picture', async () => {
      const car = aCar({ vehicle: { ...aCar().vehicle, slug: 'zubr-v8', name: 'Zubr V8', image_url: null } })
      const { wrapper } = await openWith(car)

      expect(wrapper.get('[data-test="stage"] .stage-name').text()).toBe('Zubr V8')
      expect(wrapper.find('[data-test="spots"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="shop-hint"]').exists()).toBe(false)
      expect(wrapper.findAll('[data-test="part-filter"] [role="radio"]')).toHaveLength(4)

      await wrapper.get('[data-test="filter-boost"]').trigger('click')

      expect(groups(wrapper)).toEqual(['group-boost'])
      expect(wrapper.get('[data-test="slot-detail"]').attributes('data-slot')).toBe('boost')
    })

    it('says in the language of the driver what the spots are for', async () => {
      await setLocale('pl')
      const { wrapper } = await openWith()

      expect(wrapper.get('[data-test="shop-hint"]').text()).toBe('Dotknij części na aucie, żeby zobaczyć, co jest na nią do kupienia.')

      await wrapper.get('[data-test="spot-tires"]').trigger('click')

      expect(wrapper.get('[data-test="shop-slot-note"]').text()).toBe('Na sprzedaż do tego slotu: 1')
    })
  })

  describe('the part on the car, set against the parts for sale', () => {
    it('shows by how much a part for sale is better or worse than the one that is fitted, statistic by statistic', async () => {
      const { wrapper } = await openWith()

      const compare = wrapper.get('[data-test="offer-street-tires-t2"] [data-test="part-compare"]')

      expect(compare.get('[data-test="compare-title"]').text()).toBe('Against the fitted one: Street tires T1')
      expect(compare.get('[data-test="compare-grip"]').text()).toContain('Grip +10.0')
      expect(compare.get('[data-test="compare-handling"]').text()).toContain('Handling +10.0')
      expect(compare.get('[data-test="compare-braking"]').text()).toContain('Braking +10.0')
      expect(compare.get('[data-test="compare-grip"]').classes()).toContain('cmp-up')
    })

    it('counts a lower number as better where less is better (lag, fuel, load, mass), and a higher one as worse there', async () => {
      const { wrapper } = await openWith()

      const compare = wrapper.get('[data-test="offer-turbo-duo-t4"] [data-test="part-compare"]')

      // The power is higher: better. The lag is lower: better, whatever the arrow says. The fuel factor is higher: worse. The load is the same: not listed.
      expect(compare.get('[data-test="compare-power_mult"]').text()).toContain('Power +7.0%')
      expect(compare.get('[data-test="compare-power_mult"]').classes()).toContain('cmp-up')
      expect(compare.get('[data-test="compare-lag"]').text()).toContain('▼')
      expect(compare.get('[data-test="compare-lag"]').text()).toContain('Lag −1.0')
      expect(compare.get('[data-test="compare-lag"]').classes()).toContain('cmp-up')
      expect(compare.get('[data-test="compare-fuel_mult"]').text()).toContain('Fuel +0.02')
      expect(compare.get('[data-test="compare-fuel_mult"]').classes()).toContain('cmp-down')
      expect(compare.find('[data-test="compare-engine_load"]').exists()).toBe(false)
    })

    it('tells a reader whether a change is better or worse, as the colour does for the eye', async () => {
      const { wrapper } = await openWith()

      expect(wrapper.get('[data-test="offer-turbo-duo-t4"] [data-test="compare-lag"] .sr-only').text()).toBe('(better)')
      expect(wrapper.get('[data-test="offer-turbo-duo-t4"] [data-test="compare-fuel_mult"] .sr-only').text()).toBe('(worse)')
    })

    it('has nothing to set a part against when the slot holds the factory part, or the garage is not known', async () => {
      const { wrapper } = await openWith()

      // Nothing is fitted on the suspension.
      expect(wrapper.find('[data-test="offer-sport-suspension-t1"] [data-test="part-compare"]').exists()).toBe(false)

      const none = await openWith(aCar())
      expect(none.wrapper.findAll('[data-test="part-compare"]')).toHaveLength(0)
    })

    it('says that a part has the same statistics as the one that is fitted', async () => {
      const same = anOffer({ slug: 'street-tires-t1', name: 'Street tires T1', slot: 'tires', tier: 1, stats: { grip: 90, handling: 88, braking: 90 } })
      const { wrapper } = await openWith(undefined, [same])

      expect(wrapper.get('[data-test="offer-street-tires-t1"] [data-test="compare-same"]').text()).toBe('The same statistics as the fitted part.')
    })

    it('compares nothing across statistics that the two parts do not both have', async () => {
      // The suspension for sale has the clearance and the fitted one has only the handling: only the handling is set against.
      const car = aCar({}, { suspension: aMountedPart({ slot: 'suspension', slug: 'old-springs', name: 'Old springs', tier: 1, stats: { handling: 90 } }) })
      const { wrapper } = await openWith(car)

      const compare = wrapper.get('[data-test="offer-sport-suspension-t1"] [data-test="part-compare"]')
      expect(compare.get('[data-test="compare-handling"]').text()).toContain('Handling +10.0')
      expect(compare.find('[data-test="compare-clearance"]').exists()).toBe(false)
    })

    it('writes the statistics in the language of the driver', async () => {
      await setLocale('pl')
      const { wrapper } = await openWith()

      expect(wrapper.get('[data-test="offer-street-tires-t2"] [data-test="compare-title"]').text()).toBe('Wobec założonej: Street tires T1')
      expect(wrapper.get('[data-test="offer-street-tires-t2"] [data-test="compare-grip"]').text()).toContain('Przyczepność +10,0')
    })
  })

  describe('the strip of crates', () => {
    it('has the kinds of crate side by side in a strip, each with its own button, and the guarantees under the strip', async () => {
      const { wrapper } = await openWith()

      const rail = wrapper.get('[data-test="crate-rail"]')

      expect(rail.classes()).toContain('rail')
      expect(rail.findAll('article').map((card) => card.attributes('data-test'))).toEqual(['crate-slot', 'crate-mixed', 'crate-premium'])
      // Every card has its button: the premium crate is not open at this level, and says so on its own.
      expect(rail.findAll('[data-test="crate-open"], [data-test="crate-locked"]')).toHaveLength(3)
      expect(rail.get('[data-test="crate-premium"] [data-test="crate-locked"]').attributes('disabled')).toBeDefined()
      // The slots of a crate that has a choice are in its card, and the guarantees are not in the strip.
      expect(rail.findAll('[data-test="crate-slots"]').length).toBeGreaterThan(0)
      expect(rail.find('[data-test="guarantee-standard"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="crates"] > [data-test="guarantee-standard"]').exists()).toBe(true)
    })

    it('keeps the anchor of the tutorial on the button of the first crate that can be opened', async () => {
      const { wrapper } = await openWith()

      const anchors = wrapper.findAll('[data-tutorial="crate"]')

      expect(anchors).toHaveLength(1)
      expect(anchors[0]!.attributes('data-test')).toBe('crate-open')
      expect(wrapper.get('[data-test="crate-slot"]').find('[data-tutorial="crate"]').exists()).toBe(true)
    })
  })
})
