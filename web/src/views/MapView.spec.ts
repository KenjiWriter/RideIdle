import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { MapCity, MapResponse, Run, RunResponse } from '@/api/types'
import { storeToken } from '@/auth/token'
import { setGame } from '@/game'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { setModel } from '@/model'
import { mountView } from '@/test/mountView'
import { aShipsGame } from '@/test/unitsFixtures'
import { liveFields } from '@/test/runFixtures'
import { aTutorial } from '@/test/tutorialFixtures'

import MapView from './MapView.vue'

const NOW = 1_792_692_000_000
const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

function aRunResponse(cashCents = 1_000_000): RunResponse {
  const run: Run = {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
    status: 'running',
    stop_reason: null,
    laps_done: 0,
    fuel_ml: 45_000,
    tank_ml: 45_000,
    cursor_ms: NOW,
    state_version: 0,
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: NOW,
    ...liveFields(NOW),
  }

  return { data: run, wallet: { cash_cents: cashCents, xp: 0 }, settlements: [] }
}

function aCity(overrides: Partial<MapCity> & Pick<MapCity, 'slug' | 'name' | 'x' | 'y'>): MapCity {
  return {
    home: false,
    here: false,
    level_required: 3,
    open: true,
    garage: { owned: true, capacity: 3, used: 0, free: 3, price_cents: null },
    track: { slug: `${overrides.slug}-track`, name: `${overrides.name} Track`, kind: 'standard', surface: 'gravel', lit: false, base_lap_ms: 95_000, demand: { accel: 5, grip: 25, handling: 25, braking: 10, clearance: 35 } },
    tracks: [],
    fit_percent: 52,
    route: { km: 38, ms: 1_563_429, fuel_ml: 2_280 },
    ...overrides,
  }
}

function aMap(overrides: Partial<MapResponse['data']> = {}, nowMs = NOW): MapResponse {
  return {
    data: {
      here: 'zlomowo',
      level: 3,
      cities: [
        aCity({ slug: 'zlomowo', name: 'Złomowo', x: 78, y: 290, home: true, here: true, level_required: 1, garage: { owned: true, capacity: 3, used: 1, free: 2, price_cents: null }, track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint', kind: 'standard', surface: 'asphalt', lit: true, base_lap_ms: 75_000, demand: { accel: 20, top_speed: 15, grip: 25, handling: 25, braking: 15 } }, fit_percent: 100, route: null }),
        aCity({ slug: 'kruszyn', name: 'Kruszyn', x: 196, y: 185 }),
        aCity({ slug: 'autodrom', name: 'Autodrome', x: 90, y: 110, level_required: 12, open: false, garage: { owned: false, capacity: 3, used: 0, free: 0, price_cents: 500_000 }, route: { km: 68, ms: 2_800_000, fuel_ml: 4_080 } }),
      ],
      roads: [
        { from: 'zlomowo', to: 'kruszyn', km: 38 },
        { from: 'kruszyn', to: 'autodrom', km: 30 },
      ],
      travel: null,
      ...overrides,
    },
    now_ms: nowMs,
  }
}

describe('MapView', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval', 'setTimeout', 'clearTimeout'] })
    vi.setSystemTime(new Date(NOW))
    await setLocale('en')
    storeToken('device-token')
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('draws the cities and the roads between them, and opens the sheet of the city the car is in', async () => {
    serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Map')
    expect(wrapper.findAll('[data-test="road"]')).toHaveLength(2)
    expect(wrapper.get('[data-test="city-kruszyn"]').attributes('aria-label')).toBe('Kruszyn, player level 3')
    expect(wrapper.get('[data-test="city-zlomowo"]').attributes('aria-label')).toBe('Złomowo, player level 1. Your car is here')
    expect(wrapper.get('[data-test="sheet-name"]').text()).toBe('Złomowo')
    expect(wrapper.get('[data-test="sheet-tag"]').text()).toBe('Your car is here')
    // The car is in this city already: there is no button to go there.
    expect(wrapper.find('[data-test="go"]').exists()).toBe(false)
  })

  it('shows what the track of a city asks for, how the car fits it and what the way costs', async () => {
    serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

    expect(wrapper.get('[data-test="sheet-name"]').text()).toBe('Kruszyn')
    expect(wrapper.get('[data-test="sheet-track"]').text()).toBe('Kruszyn Track')
    expect(wrapper.get('[data-test="sheet-tag"]').text()).toBe('Destination')
    expect(wrapper.findAll('[data-test="demands"] > div').map((row) => row.text())).toEqual(['Acceleration5', 'Grip25', 'Handling25', 'Braking10', 'Off-road35'])
    // The bars are scaled to the statistic the track asks most.
    expect(wrapper.get('[data-test="demand-clearance"] .bar i').attributes('style')).toContain('width: 100%')
    expect(wrapper.get('[data-test="demand-braking"] .bar i').attributes('style')).toContain('width: 29%')
    expect(wrapper.get('[data-test="fit"]').text()).toContain('52% · Change the tires or the car')
    expect(wrapper.get('[data-test="route"]').text()).toBe('38 km · 26 min 03 s · 2.3 l of fuel')
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeUndefined()
  })

  it('shows how full the garage of the city is, and that there is none where the driver has none', async () => {
    const cities = aMap().data.cities.map((city) => (city.slug === 'kruszyn' ? { ...city, garage: { owned: false, capacity: 3, used: 0, free: 0, price_cents: 500_000 } } : city))
    serve({ 'GET /api/v1/map': () => respond(200, aMap({ cities })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.get('[data-test="garage"]').text()).toBe('Garage: 1 / 3 places')
    expect(wrapper.find('[data-test="buy-garage"]').exists()).toBe(false)

    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

    expect(wrapper.get('[data-test="garage"]').text()).toContain('You have no garage here.')
    expect(wrapper.get('[data-test="buy-garage"]').text()).toBe('Buy a garage · $5,000')
  })

  it('does not let the car go where there is no garage or no free place, and says why', async () => {
    const cities = aMap().data.cities.map((city) =>
      city.slug === 'kruszyn' ? { ...city, garage: { owned: false, capacity: 3, used: 0, free: 0, price_cents: 500_000 } } : city.slug === 'autodrom' ? { ...city, open: true, garage: { owned: true, capacity: 3, used: 3, free: 0, price_cents: null } } : city,
    )
    serve({ 'GET /api/v1/map': () => respond(200, aMap({ cities })), 'GET /api/v1/runs/current': () => respond(200, aRunResponse()) })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

    expect(wrapper.get('[data-test="reason"]').text()).toBe('You have no garage in Kruszyn. Buy one there first, then the car can go.')
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="city-autodrom"]').trigger('click')

    expect(wrapper.get('[data-test="reason"]').text()).toBe('The garage in Autodrome is full.')
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeDefined()
  })

  it('buys the garage of a city from its sheet, and then the car may go there', async () => {
    let bought = false
    const kruszyn = (garage: MapCity['garage']) => aMap().data.cities.map((city) => (city.slug === 'kruszyn' ? { ...city, garage } : city))
    const calls = serve({
      'GET /api/v1/map': () => respond(200, aMap({ cities: kruszyn(bought ? { owned: true, capacity: 3, used: 0, free: 3, price_cents: null } : { owned: false, capacity: 3, used: 0, free: 0, price_cents: 500_000 }) })),
      'POST /api/v1/garages': () => {
        bought = true

        return respond(201, { data: { selected: 5, cars: [], garages: [], yard: [] }, wallet: { cash_cents: 500_000, xp: 0 }, now_ms: NOW })
      },
      'GET /api/v1/runs/current': () => respond(200, aRunResponse()),
    })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')
    await wrapper.get('[data-test="buy-garage"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ city: 'kruszyn' })
    expect(wrapper.get('[data-test="started"]').text()).toBe('You bought a garage in Kruszyn.')
    expect(wrapper.get('[data-test="garage"]').text()).toBe('Garage: 0 / 3 places')
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeUndefined()
  })

  it('does not offer a garage the driver cannot pay for', async () => {
    const cities = aMap().data.cities.map((city) => (city.slug === 'kruszyn' ? { ...city, garage: { owned: false, capacity: 3, used: 0, free: 0, price_cents: 500_000 } } : city))
    serve({ 'GET /api/v1/map': () => respond(200, aMap({ cities })), 'GET /api/v1/runs/current': () => respond(200, aRunResponse(120_000)) })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

    expect(wrapper.get('[data-test="buy-garage"]').attributes('disabled')).toBeDefined()
  })

  it('says why a city is closed, with the level it takes, and does not let the car go', async () => {
    serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-autodrom"]').trigger('click')

    expect(wrapper.get('[data-test="reason"]').text()).toBe('Requires player level 12 (you are level 3). You get XP by driving.')
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeDefined()
  })

  it('points the tutorial at the button that sends the car, and asks it again when the journey has begun', async () => {
    const calls = serve({
      'GET /api/v1/map': () => respond(200, aMap()),
      'POST /api/v1/travel': () => respond(201, aMap({ travel: { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_563_429 } })),
      'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
      'GET /api/v1/runs/current': noRun,
    })
    const asked = () => calls.filter((call) => call.method === 'GET' && call.url === '/api/v1/tutorial').length

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

    expect(wrapper.get('[data-tutorial="travel"]').attributes('data-test')).toBe('go')
    expect(asked()).toBe(1)

    await wrapper.get('[data-test="go"]').trigger('click')
    await flushPromises()

    // A journey that has begun is what a step of the tutorial waits for, and the server moves it only when it is asked.
    expect(asked()).toBe(2)
  })

  it('sends the car, shows the journey and says so', async () => {
    const calls = serve({
      'GET /api/v1/map': () => respond(200, aMap()),
      'POST /api/v1/travel': () => respond(201, aMap({ travel: { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_563_429 } })),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')
    await wrapper.get('[data-test="go"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ to: 'kruszyn' })
    expect(wrapper.get('[data-test="started"]').text()).toBe('The car is on its way to Kruszyn.')
    expect(wrapper.get('[data-test="travel-banner"]').text()).toContain('On the road: Złomowo → Kruszyn')
    expect(wrapper.get('[data-test="travel-eta"]').text()).toBe('in 26 min 03 s')
    // The car cannot be sent again while it is on the road.
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeDefined()
  })

  it('counts the journey down with the clock of the server, and moves the car on the road', async () => {
    const journey = { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_000_000 }
    // The server answers with its clock at the moment of the answer, as the real one does.
    serve({ 'GET /api/v1/map': () => respond(200, aMap({ travel: journey }, Date.now())) })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.get('[data-test="travel-eta"]').text()).toBe('in 16 min 40 s')
    expect(wrapper.get('[data-test="travel-bar"]').attributes('aria-valuenow')).toBe('0')

    await vi.advanceTimersByTimeAsync(500_000)

    expect(wrapper.get('[data-test="travel-eta"]').text()).toBe('in 8 min 20 s')
    expect(wrapper.get('[data-test="travel-bar"]').attributes('aria-valuenow')).toBe('50')
    // Half way along the straight line from Złomowo (78, 290) to Kruszyn (196, 185): 137 of 390, and (237.5 - 60) of 300.
    expect(wrapper.get('[data-test="car-marker"]').attributes('style')).toContain('left: 35.128')
  })

  describe('a boosted journey', () => {
    const boostedMap = (overrides: Partial<MapResponse['data']> = {}, nowMs = NOW) => {
      const map = aMap(overrides, nowMs)
      const kruszyn = map.data.cities.find((city) => city.slug === 'kruszyn')

      if (kruszyn?.route) {
        kruszyn.route = { ...kruszyn.route, boost: { ms: 1_202_637, fuel_ml: 3_192, police_chance_ppm: 150_000 } }
      }

      return map
    }

    it('offers a faster way with what it costs and risks, and sends the car boosted', async () => {
      const calls = serve({
        'GET /api/v1/map': () => respond(200, boostedMap()),
        'POST /api/v1/travel': () => respond(201, boostedMap({ travel: { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_202_637, boosted: true, police: null } })),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(MapView, '/map')
      await flushPromises()
      await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

      expect(wrapper.get('[data-test="go-boost"]').text()).toBe('Drive faster')
      expect(wrapper.get('[data-test="boost-note"]').text().replace(/\s/g, ' ')).toBe('Faster: 20 min 02 s instead of 26 min 03 s, 3.2 l of fuel instead of 2.3 l. The police may stop you (15%).')

      await wrapper.get('[data-test="go-boost"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ to: 'kruszyn', boost: true })
      expect(wrapper.get('[data-test="travel-boosted"]').text()).toBe('Boosted')
    })

    it('does not offer it when the game has no boost, and the plain way sends no boost', async () => {
      const calls = serve({
        'GET /api/v1/map': () => respond(200, aMap()),
        'POST /api/v1/travel': () => respond(201, aMap({ travel: { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_563_429 } })),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(MapView, '/map')
      await flushPromises()
      await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

      expect(wrapper.find('[data-test="go-boost"]').exists()).toBe(false)

      await wrapper.get('[data-test="go"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ to: 'kruszyn' })
    })

    it('shows the stop of the police with the choices, and what came of the one that was made', async () => {
      const stopped = {
        from: 'zlomowo',
        to: 'kruszyn',
        distance_km: 38,
        start_ms: NOW,
        arrive_ms: NOW + 1_202_637,
        boosted: true,
        police: {
          at_ms: NOW,
          decide_by_ms: NOW + 60_000,
          fine_cents: 1_250,
          status: 'pending' as const,
          choice: null,
          outcome: null,
          paid_cents: null,
          options: { pay: { cents: 1_250, stop_ms: 120_000 }, bribe: { cents: 1_875, risk_ppm: 250_000, fail_cents: 2_500, fail_stop_ms: 300_000 }, lawyer: { coins: 5 } },
        },
      }
      const calls = serve({
        'GET /api/v1/map': () => respond(200, { ...boostedMap({ travel: stopped }, Date.now()), premium_coins: 10 }),
        'POST /api/v1/travel/police': () =>
          respond(200, {
            ...boostedMap({ travel: { ...stopped, arrive_ms: NOW + 1_322_637, police: { ...stopped.police, status: 'resolved' as const, choice: 'pay', outcome: 'paid' as const, paid_cents: 1_250, options: null } } }, Date.now()),
            premium_coins: 10,
            repeated: false,
            police: { choice: 'pay', outcome: 'paid', paid_cents: 1_250 },
          }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(MapView, '/map')
      await flushPromises()

      expect(wrapper.get('[data-test="police-fine"]').text()).toBe('Fine: $12.50.')

      await wrapper.get('[data-test="police-pay"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.url === '/api/v1/travel/police')?.body).toEqual({ choice: 'pay' })
      expect(wrapper.find('[data-test="police"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="police-outcome"]').text()).toContain('Fine paid: $12.50.')
    })

    it('shows what the server says when the choice comes too late, and asks for the map again', async () => {
      const stopped = {
        from: 'zlomowo',
        to: 'kruszyn',
        distance_km: 38,
        start_ms: NOW,
        arrive_ms: NOW + 1_202_637,
        boosted: true,
        police: {
          at_ms: NOW,
          decide_by_ms: NOW + 60_000,
          fine_cents: 1_250,
          status: 'pending' as const,
          choice: null,
          outcome: null,
          paid_cents: null,
          options: { pay: { cents: 1_250, stop_ms: 120_000 }, bribe: { cents: 1_875, risk_ppm: 250_000, fail_cents: 2_500, fail_stop_ms: 300_000 }, lawyer: null },
        },
      }
      const calls = serve({
        'GET /api/v1/map': () => respond(200, boostedMap({ travel: stopped }, Date.now())),
        'POST /api/v1/travel/police': () => respond(422, { message: 'The police have been dealt with already.', code: 'police_resolved' }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(MapView, '/map')
      await flushPromises()
      await wrapper.get('[data-test="police-bribe"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('The police have been dealt with already.')
      expect(calls.filter((call) => call.method === 'GET' && call.url === '/api/v1/map').length).toBeGreaterThan(1)
    })
  })

  it('offers to arrive at once for premium coins: says the price, asks, and then the car is there', async () => {
    const journey = { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_563_429, speedup: { coins: 6, remaining_ms: 1_563_429 } }
    const calls = serve({
      'GET /api/v1/map': () => respond(200, { ...aMap({ travel: journey }, Date.now()), premium_coins: 10 }),
      'POST /api/v1/travel/finish': () => respond(200, { ...aMap({ here: 'kruszyn', travel: null }, Date.now()), premium_coins: 4, repeated: false, spent_coins: 6 }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.get('[data-test="finish-travel"]').text()).toBe('Arrive now · 6 RideCoin')

    await wrapper.get('[data-test="finish-travel"]').trigger('click')

    expect(wrapper.get('[data-test="finish-travel-sure"]').text()).toContain('Pay 6 RideCoin? You have 10.')

    await wrapper.get('[data-test="finish-travel-confirm"]').trigger('click')
    await flushPromises()

    const finish = calls.find((call) => call.method === 'POST' && call.url === '/api/v1/travel/finish')

    expect(finish?.body).toEqual({ request_id: expect.any(String) })
    expect(wrapper.find('[data-test="travel-banner"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="started"]').text()).toBe('Done: 6 RideCoin spent.')
  })

  it('does not offer to arrive at once to a driver who has too few coins, and not when the game sells nothing', async () => {
    const journey = { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW, arrive_ms: NOW + 1_563_429, speedup: { coins: 6, remaining_ms: 1_563_429 } }
    serve({ 'GET /api/v1/map': () => respond(200, { ...aMap({ travel: journey }, Date.now()), premium_coins: 5 }), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.get('[data-test="finish-travel"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="finish-travel-poor"]').text()).toBe('Not enough RideCoin: you have 5.')

    serve({ 'GET /api/v1/map': () => respond(200, aMap({ travel: { ...journey, speedup: null } }, Date.now())), 'GET /api/v1/runs/current': noRun })

    const other = await mountView(MapView, '/map')
    await flushPromises()

    expect(other.wrapper.find('[data-test="finish-travel"]').exists()).toBe(false)
  })

  it('asks the server again when the journey is due, and shows the car in the new city', async () => {
    let asked = 0
    serve({
      'GET /api/v1/map': () => {
        asked += 1

        return respond(
          200,
          asked === 1
            ? aMap({ travel: { from: 'zlomowo', to: 'kruszyn', distance_km: 38, start_ms: NOW - 10_000, arrive_ms: NOW + 4_000 } })
            : aMap({ here: 'kruszyn', cities: aMap().data.cities.map((city) => ({ ...city, here: city.slug === 'kruszyn' })), travel: null }, NOW + 10_000),
        )
      },
    })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(6_000)
    await flushPromises()

    expect(asked).toBe(2)
    expect(wrapper.find('[data-test="travel-banner"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="city-kruszyn"]').attributes('aria-label')).toContain('Your car is here')
  })

  it('shows what the server says when it refuses, and asks for the map again', async () => {
    let asked = 0
    serve({
      'GET /api/v1/map': () => respond(200, ++asked && aMap()),
      'POST /api/v1/travel': () => respond(422, { message: 'Not enough fuel for the road: it takes 2.3 l and the tank has 1.0 l.', code: 'travel_no_fuel' }),
    })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-kruszyn"]').trigger('click')
    await wrapper.get('[data-test="go"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough fuel for the road: it takes 2.3 l and the tank has 1.0 l.')
    expect(asked).toBe(2)
  })

  it('offers a retry when the map cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/map': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, aMap())) })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The map could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="map"]').exists()).toBe(true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-autodrom"]').trigger('click')

    expect(wrapper.get('h1').text()).toBe('Mapa')
    expect(wrapper.get('[data-test="reason"]').text()).toBe('Wymaga poziomu gracza 12 (masz 3). Zdobywasz XP, jeżdżąc.')
    expect(wrapper.get('[data-test="go"]').text()).toBe('Jedź tutaj')
  })

  describe('in a game that counts in nautical miles', () => {
    afterEach(() => {
      setGame()
    })

    it('says the way to a city in the unit of travel of the game, and keeps the fuel in litres', async () => {
      setGame(aShipsGame())
      serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(MapView, '/map')
      await flushPromises()
      await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

      // 38 km are 20.5 nm.
      expect(wrapper.get('[data-test="route"]').text()).toBe('20.5 nm · 26 min 03 s · 2.3 l of fuel')
    })

    it('says it the way the language does: a no-break space before the unit in French', async () => {
      setGame(aShipsGame())
      await setLocale('fr')
      serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(MapView, '/map')
      await flushPromises()
      await wrapper.get('[data-test="city-kruszyn"]').trigger('click')

      expect(wrapper.get('[data-test="route"]').text()).toBe('20,5 nm · 26 min 03 s · 2,3 l de carburant')
    })
  })
})

describe('MapView in a game with another model', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    storeToken('device-token')
    setModel({ slots: [{ key: 'hull', role: 'tires', hidden: false }], stats: ['braking', 'grip'] })
  })

  afterEach(() => {
    setModel()
    vi.unstubAllGlobals()
  })

  it('shows what the track asks only of the statistics that the model lists, in its order', async () => {
    serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()

    expect(wrapper.findAll('[data-test="demands"] > div').map((row) => row.text())).toEqual(['Braking15', 'Grip25'])
  })
})
