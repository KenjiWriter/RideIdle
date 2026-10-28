import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { MapCity, MapResponse, MapTrack, MapTrackCar, Run } from '@/api/types'
import { storeToken } from '@/auth/token'
import { i18n, setLocale } from '@/i18n'
import { useRunStore } from '@/stores/run'
import { respond, serveApi } from '@/test/fetchServer'
import { liveFields } from '@/test/runFixtures'

import TrackPicker from './TrackPicker.vue'

const NOW = 1_792_692_000_000

function aRun(overrides: Partial<Run> = {}): Run {
  return {
    id: 7,
    car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
    track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint', kind: 'standard' },
    status: 'running',
    stop_reason: null,
    laps_done: 10,
    fuel_ml: 30_000,
    tank_ml: 45_000,
    cursor_ms: NOW,
    state_version: 2,
    prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
    lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 20 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
    server_time_ms: NOW,
    ...liveFields(NOW),
    ...overrides,
  }
}

function forTheCar(overrides: Partial<MapTrackCar> = {}): MapTrackCar {
  return {
    lap_ms: 75_000,
    cash_cents: 1_940,
    xp: 20,
    fuel_ml: 1_511,
    tank_ml: 45_000,
    fits_tank: true,
    laps_per_tank: 29,
    tank_ms: 2_175_000,
    gross_cents_per_hour: 93_120,
    fuel_cents_per_hour: 14_500,
    net_cents_per_hour: 78_620,
    xp_per_hour: 960,
    fit_percent: 100,
    position: 2,
    field_size: 8,
    ...overrides,
  }
}

function aTrack(overrides: Partial<MapTrack> & Pick<MapTrack, 'slug' | 'name'>): MapTrack {
  return { kind: 'standard', surface: 'asphalt', lit: true, level_required: 1, open: true, current: false, base_lap_ms: 75_000, demand: { accel: 20 }, car: forTheCar(), ...overrides }
}

const TRACKS: MapTrack[] = [
  aTrack({ slug: 'zlomowo-street', name: 'Sprint uliczny Złomowo', current: true }),
  aTrack({ slug: 'zlomowo-yard-dash', name: 'Sprint po złomowisku', kind: 'sprint', base_lap_ms: 12_000, car: forTheCar({ lap_ms: 11_000, cash_cents: 264, fuel_ml: 145, laps_per_tank: 310, tank_ms: 3_410_000, net_cents_per_hour: 69_000 }) }),
  aTrack({ slug: 'zlomowo-ring-road', name: 'Obwodnica Złomowo', kind: 'endurance', level_required: 4, open: false, base_lap_ms: 300_000, car: forTheCar({ lap_ms: 275_000, cash_cents: 8_448, fuel_ml: 9_672, laps_per_tank: 4, tank_ms: 1_100_000, net_cents_per_hour: 85_000 }) }),
]

function aMap(tracks: MapTrack[] = TRACKS): MapResponse {
  const city: MapCity = {
    slug: 'zlomowo',
    name: 'Złomowo',
    x: 78,
    y: 290,
    home: true,
    here: true,
    level_required: 1,
    open: true,
    garage: { owned: true, capacity: 3, used: 1, free: 2, price_cents: null },
    track: { slug: 'zlomowo-street', name: 'Sprint uliczny Złomowo', kind: 'standard', surface: 'asphalt', lit: true, base_lap_ms: 75_000, demand: { accel: 20 } },
    tracks,
    fit_percent: 100,
    route: null,
  }

  return { data: { here: 'zlomowo', level: 1, cities: [city], roads: [], travel: null }, now_ms: NOW }
}

const fetchMock = vi.fn()

/** Mounts the picker with a run in the store, on a map that answers with the given tracks. */
async function show(run: Run = aRun(), tracks: MapTrack[] = TRACKS, routes: Parameters<typeof serveApi>[1] = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const calls = serveApi(fetchMock, { 'GET /api/v1/map': () => respond(200, aMap(tracks)), ...routes })
  const store = useRunStore()
  store.run = run
  store.wallet = { cash_cents: 100_000, xp: 0 }

  const wrapper = mount(TrackPicker, { global: { plugins: [pinia, i18n] } })
  await flushPromises()

  return { wrapper, store, calls }
}

describe('TrackPicker', () => {
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

  it('lists the tracks of the city with their kind, and marks the one the car drives', async () => {
    const { wrapper } = await show()

    expect(wrapper.findAll('[data-test^="track-name-"]').map((name) => name.text())).toEqual(['Sprint uliczny Złomowo', 'Sprint po złomowisku', 'Obwodnica Złomowo'])
    expect(wrapper.get('[data-test="track-kind-zlomowo-yard-dash"]').text()).toBe('Sprint')
    expect(wrapper.get('[data-test="track-kind-zlomowo-street"]').text()).toBe('Standard')
    expect(wrapper.get('[data-test="track-kind-zlomowo-ring-road"]').text()).toBe('Endurance')
    expect(wrapper.get('[data-test="track-current-zlomowo-street"]').text()).toBe('Now')
    expect(wrapper.find('[data-test="track-current-zlomowo-yard-dash"]').exists()).toBe(false)
    // The track the car drives has no button: it is the choice already made.
    expect(wrapper.find('[data-test="track-zlomowo-street"] [data-test="choose"]').exists()).toBe(false)
  })

  it('says what a lap is for the car: the lap, what it pays, the fuel, how long a tank lasts and what an hour earns after the fuel', async () => {
    const { wrapper } = await show()
    const sprint = wrapper.get('[data-test="track-numbers-zlomowo-yard-dash"]')

    expect(sprint.get('[data-test="lap"]').text()).toBe('0:11.0')
    expect(sprint.get('[data-test="pays"]').text()).toBe('$2.64')
    expect(sprint.get('[data-test="fuel"]').text()).toBe('0.1 l')
    expect(sprint.get('[data-test="laps"]').text()).toBe('310')
    expect(sprint.get('[data-test="net"]').text()).toBe('$690')
    expect(sprint.get('[data-test="position"]').text()).toBe('2 of 8')

    // The endurance track burns a tank in minutes: fewer laps, and a tank that lasts a shorter time.
    const endurance = wrapper.get('[data-test="track-numbers-zlomowo-ring-road"]')

    expect(endurance.get('[data-test="laps"]').text()).toBe('4')
    expect(endurance.get('[data-test="fuel"]').text()).toBe('9.7 l')
  })

  it('does not offer a track that the level does not open, and says from which level it opens', async () => {
    const { wrapper } = await show()
    const ring = wrapper.get('[data-test="track-zlomowo-ring-road"]')

    expect(ring.get('[data-test="locked"]').text()).toBe('From level 4')
    expect(ring.get('[data-test="choose"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="track-zlomowo-yard-dash"] [data-test="choose"]').attributes('disabled')).toBeUndefined()
  })

  it('does not offer a track that the tank is too small for', async () => {
    const tracks = TRACKS.map((track) => (track.slug === 'zlomowo-ring-road' ? { ...track, open: true, car: forTheCar({ fits_tank: false, laps_per_tank: 0, fuel_ml: 60_000 }) } : track))
    const { wrapper } = await show(aRun(), tracks)
    const ring = wrapper.get('[data-test="track-zlomowo-ring-road"]')

    expect(ring.get('[data-test="too-small"]').text()).toBe('Tank too small for one lap')
    expect(ring.get('[data-test="choose"]').attributes('disabled')).toBeDefined()
  })

  it('puts the car on the track that was chosen, asks the map again, and says so', async () => {
    const changed = aRun({ track: { slug: 'zlomowo-yard-dash', name: 'Sprint po złomowisku', kind: 'sprint' }, state_version: 3 })
    const afterwards = TRACKS.map((track) => ({ ...track, current: track.slug === 'zlomowo-yard-dash' }))
    let asked = 0
    const { wrapper, store, calls } = await show(aRun(), TRACKS, {
      'PATCH /api/v1/runs/7': () => respond(200, { data: changed, wallet: { cash_cents: 100_000, xp: 0 } }),
      'GET /api/v1/map': () => respond(200, aMap(asked++ === 0 ? TRACKS : afterwards)),
    })

    await wrapper.get('[data-test="track-zlomowo-yard-dash"] [data-test="choose"]').trigger('click')
    await flushPromises()

    expect(calls.filter((call) => call.method === 'PATCH')).toEqual([{ url: '/api/v1/runs/7', method: 'PATCH', body: { track: 'zlomowo-yard-dash' } }])
    expect(store.run?.track.slug).toBe('zlomowo-yard-dash')
    expect(wrapper.get('[data-test="track-done"]').text()).toBe('From now on the car drives Sprint po złomowisku.')
    expect(wrapper.find('[data-test="track-current-zlomowo-yard-dash"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="track-current-zlomowo-street"]').exists()).toBe(false)
  })

  it('says what the server says when it refuses', async () => {
    const { wrapper } = await show(aRun(), TRACKS, {
      'PATCH /api/v1/runs/7': () => respond(422, { message: 'Requires player level 4 (you are level 1). You get XP by driving.', code: 'track_locked' }),
    })

    await wrapper.get('[data-test="track-zlomowo-yard-dash"] [data-test="choose"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="track-error"]').text()).toBe('Requires player level 4 (you are level 1). You get XP by driving.')
  })

  it('shows nothing for a city that has one track, and nothing for a car on the road', async () => {
    const one = await show(aRun(), [TRACKS[0] as MapTrack])

    expect(one.wrapper.find('[data-test="track-picker"]').exists()).toBe(false)

    const road = await show(aRun({ stop_reason: 'travel' }))

    expect(road.wrapper.find('[data-test="track-picker"]').exists()).toBe(false)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = await show()

    expect(wrapper.get('[data-test="track-kind-zlomowo-ring-road"]').text()).toBe('Dystans')
    expect(wrapper.get('[data-test="track-zlomowo-ring-road"] [data-test="locked"]').text()).toBe('Od poziomu 4')
  })
})
