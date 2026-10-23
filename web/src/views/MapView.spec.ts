import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { MapCity, MapResponse } from '@/api/types'
import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import MapView from './MapView.vue'

const NOW = 1_792_692_000_000
const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

function aCity(overrides: Partial<MapCity> & Pick<MapCity, 'slug' | 'name' | 'x' | 'y'>): MapCity {
  return {
    home: false,
    here: false,
    level_required: 3,
    open: true,
    track: { slug: `${overrides.slug}-track`, name: `${overrides.name} Track`, surface: 'gravel', lit: false, base_lap_ms: 95_000, demand: { accel: 5, grip: 25, handling: 25, braking: 10, clearance: 35 } },
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
        aCity({ slug: 'zlomowo', name: 'Złomowo', x: 78, y: 290, home: true, here: true, level_required: 1, track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint', surface: 'asphalt', lit: true, base_lap_ms: 75_000, demand: { accel: 20, top_speed: 15, grip: 25, handling: 25, braking: 15 } }, fit_percent: 100, route: null }),
        aCity({ slug: 'kruszyn', name: 'Kruszyn', x: 196, y: 185 }),
        aCity({ slug: 'autodrom', name: 'Autodrome', x: 90, y: 110, level_required: 12, open: false, route: { km: 68, ms: 2_800_000, fuel_ml: 4_080 } }),
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
    expect(wrapper.get('[data-test="city-zlomowo"]').attributes('aria-label')).toBe('Złomowo, player level 1. You are here')
    expect(wrapper.get('[data-test="sheet-name"]').text()).toBe('Złomowo')
    expect(wrapper.get('[data-test="sheet-tag"]').text()).toBe('You are here')
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

  it('says why a city is closed, with the level it takes, and does not let the car go', async () => {
    serve({ 'GET /api/v1/map': () => respond(200, aMap()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(MapView, '/map')
    await flushPromises()
    await wrapper.get('[data-test="city-autodrom"]').trigger('click')

    expect(wrapper.get('[data-test="reason"]').text()).toBe('Requires player level 12 (you are level 3). You get XP by driving.')
    expect(wrapper.get('[data-test="go"]').attributes('disabled')).toBeDefined()
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
    expect(wrapper.get('[data-test="city-kruszyn"]').attributes('aria-label')).toContain('You are here')
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
})
