import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Run, StarterCar } from '@/api/types'
import { readToken, storeToken } from '@/auth/token'
import { readSeen } from '@/features/run/seen'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'
import { liveFields } from '@/test/runFixtures'

import ChooseCarView from './ChooseCarView.vue'

function car(overrides: Partial<StarterCar>): StarterCar {
  return {
    slug: 'rdzak-gt-4',
    name: 'Rdzak GT-4',
    description: 'City streets and low running costs.',
    class: 'hatchback',
    drivetrain: 'fwd',
    engine: { layout: 'R4', displacement_l: 1.8 },
    power_kw: 97,
    mass_kg: 1000,
    top_speed_kmh: 175,
    cargo_kg: 120,
    tank_l: 45,
    lap: { weather: 'dry', ms: 75_000, cash_cents: 1_550, xp: 25, fuel_ml: 1_500 },
    ...overrides,
  }
}

const starters = {
  data: [
    car({}),
    car({ slug: 'kuna-4x4', name: 'Kuna 4x4', description: 'Off-road and cargo.', drivetrain: 'awd', engine: { layout: 'R4', displacement_l: 2 }, power_kw: 97, mass_kg: 1480, tank_l: 70, cargo_kg: 520, lap: { weather: 'dry', ms: 82_313, cash_cents: 1_550, xp: 25, fuel_ml: 1_500 } }),
    car({ slug: 'bizon-m-2', name: 'Bizon M-2', description: 'Plenty of power.', drivetrain: 'rwd', engine: { layout: 'V6', displacement_l: 3.2 }, power_kw: 202, mass_kg: 1480, tank_l: 60, lap: { weather: 'dry', ms: 67_015, cash_cents: 1_550, xp: 25, fuel_ml: 2_109 } }),
  ],
  track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
}

const run: Run = {
  id: 7,
  car: { slug: 'rdzak-gt-4', name: 'Rdzak GT-4' },
  track: { slug: 'zlomowo-street', name: 'Złomowo Street Sprint' },
  status: 'running',
  stop_reason: null,
  laps_done: 0,
  fuel_ml: 45_000,
  tank_ml: 45_000,
  cursor_ms: 1_792_692_000_000,
  state_version: 0,
  prices: { fuel_cents_per_liter: 200, repair_cents: 3_000 },
  lap: { ms: { dry: 75_000 }, cash_cents: { dry: 1_550 }, xp: { dry: 25 }, fuel_ml: 1_500, car_xp: 10, night_ppm: 1_000_000 },
  server_time_ms: 1_792_692_000_000,
  ...liveFields(1_792_692_000_000),
}

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'You have no car on the track yet.', code: 'no_run' })

describe('ChooseCarView', () => {
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

  it('shows the wrecks, the cheapest one recommended', async () => {
    serve({ 'GET /api/v1/runs/current': noRun, 'GET /api/v1/starter-cars': () => respond(200, starters) })

    const { wrapper } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    expect(wrapper.get('[data-test="step"]').text()).toBe('Step 2 of 2')
    expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('100')
    expect(wrapper.get('[data-test="title"]').text()).toBe('Choose your wreck')
    expect(wrapper.get('[data-test="track"]').text()).toBe('You start on Złomowo Street Sprint.')

    const cards = wrapper.findAll('article')
    expect(cards.map((card) => card.get('h2').text())).toEqual(['Rdzak GT-4', 'Kuna 4x4', 'Bizon M-2'])
    // The recommended one is the highlighted card, and only that one.
    expect(cards.map((card) => card.classes().includes('card-hi'))).toEqual([true, false, false])
    expect(cards.map((card) => card.attributes('data-recommended'))).toEqual(['true', 'false', 'false'])
  })

  it('shows a wreck as the canvas does: the picture, the drivetrain, the engine and what it is good for', async () => {
    serve({ 'GET /api/v1/runs/current': noRun, 'GET /api/v1/starter-cars': () => respond(200, starters) })

    const { wrapper } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    const rdzak = wrapper.get('[data-test="car-rdzak-gt-4"]')
    expect(rdzak.get('[data-test="picture"]').attributes('alt')).toBe('Rdzak GT-4')
    expect(rdzak.get('[data-test="drivetrain"]').text()).toBe('FWD')
    expect(rdzak.get('[data-test="drivetrain"]').attributes('title')).toBe('Front-wheel drive')
    expect(rdzak.findAll('[data-test="spec"] .chip').map((chip) => chip.text())).toEqual(['R4 1.8', '97 kW'])
    expect(rdzak.get('[data-test="description"]').text()).toBe('City streets and low running costs.')

    const bizon = wrapper.get('[data-test="car-bizon-m-2"]')
    expect(bizon.get('[data-test="drivetrain"]').text()).toBe('RWD')
    expect(bizon.findAll('[data-test="spec"] .chip').map((chip) => chip.text())).toEqual(['V6 3.2', '202 kW'])

    // A displacement is written with its decimal, whole or not.
    const kuna = wrapper.get('[data-test="car-kuna-4x4"]')
    expect(kuna.findAll('[data-test="spec"] .chip').map((chip) => chip.text())).toEqual(['R4 2.0', '97 kW'])
  })

  it('names the button after the wreck for a screen reader, while it shows only "Choose"', async () => {
    serve({ 'GET /api/v1/runs/current': noRun, 'GET /api/v1/starter-cars': () => respond(200, starters) })

    const { wrapper } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    const button = wrapper.get('[data-test="car-kuna-4x4"] [data-test="choose"]')
    expect(button.text()).toBe('Choose')
    expect(button.attributes('aria-label')).toBe('Choose Kuna 4x4')
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/runs/current': noRun, 'GET /api/v1/starter-cars': () => respond(200, starters) })

    const { wrapper } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    expect(wrapper.get('[data-test="step"]').text()).toBe('Krok 2 z 2')
    expect(wrapper.findAll('[data-test="car-rdzak-gt-4"] [data-test="spec"] .chip').map((chip) => chip.text())).toEqual(['R4 1,8', '97 kW'])
    expect(wrapper.get('[data-test="car-rdzak-gt-4"] [data-test="drivetrain"]').attributes('title')).toBe('Napęd przedni')
    expect(wrapper.get('[data-test="car-rdzak-gt-4"] [data-test="choose"]').text()).toBe('Wybierz')
    expect(wrapper.get('[data-test="car-rdzak-gt-4"] [data-test="choose"]').attributes('aria-label')).toBe('Wybierz: Rdzak GT-4')
  })

  it('starts the chosen wreck on the starting track and opens the garage', async () => {
    const calls = serve({
      'GET /api/v1/runs/current': noRun,
      'GET /api/v1/starter-cars': () => respond(200, starters),
      'POST /api/v1/runs': () => respond(201, { data: run, wallet: { cash_cents: 0, xp: 0 } }),
    })

    const { wrapper, router } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    await wrapper.get('[data-test="car-kuna-4x4"] [data-test="choose"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ car: 'kuna-4x4', track: 'zlomowo-street' })
    expect(readSeen()).toEqual({ runId: 7, version: 0 })
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
  })

  it('does not start a second wreck while one is being started', async () => {
    let finish: (response: Response) => void = () => undefined
    const calls = serve({
      'GET /api/v1/runs/current': noRun,
      'GET /api/v1/starter-cars': () => respond(200, starters),
      'POST /api/v1/runs': () => new Promise<Response>((resolve) => (finish = resolve)),
    })

    const { wrapper, router } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    await wrapper.get('[data-test="car-kuna-4x4"] [data-test="choose"]').trigger('click')
    await wrapper.get('[data-test="car-bizon-m-2"] [data-test="choose"]').trigger('click')

    expect(wrapper.get('[data-test="car-kuna-4x4"] [data-test="choose"]').text()).toBe('Starting…')
    expect(wrapper.findAll('[data-test="choose"]').every((button) => button.attributes('disabled') !== undefined)).toBe(true)
    expect(calls.filter((call) => call.method === 'POST')).toHaveLength(1)

    finish(respond(201, { data: run, wallet: { cash_cents: 0, xp: 0 } }))
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
  })

  it('sends a driver who already has a run straight to the garage', async () => {
    const calls = serve({ 'GET /api/v1/runs/current': () => respond(200, { data: run, wallet: { cash_cents: 100, xp: 1 }, settlements: [], summary: { laps: 0, cash_cents: 0, xp: 0, from_ms: null, to_ms: null, stop_reason: null } }) })

    const { wrapper, router } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
    expect(wrapper.findAll('article')).toHaveLength(0)
    // The wrecks are not even asked for.
    expect(calls.map((call) => call.url.split('?')[0])).toEqual(['/api/v1/runs/current'])
  })

  it('opens the garage when the run was started elsewhere meanwhile', async () => {
    let startedElsewhere = false
    serve({
      'GET /api/v1/runs/current': () => (startedElsewhere ? respond(200, { data: run, wallet: { cash_cents: 0, xp: 0 } }) : noRun()),
      'GET /api/v1/starter-cars': () => respond(200, starters),
      'POST /api/v1/runs': () => {
        startedElsewhere = true

        return respond(409, { message: 'You already have a car on the track.', code: 'run_exists', run_id: 7 })
      },
    })

    const { wrapper, router } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    await wrapper.get('[data-test="car-rdzak-gt-4"] [data-test="choose"]').trigger('click')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('garage'))
    expect(wrapper.find('[data-test="error"]').text()).toBe('')
  })

  it('says so when the wrecks cannot be loaded, and loads them again on request', async () => {
    serve({ 'GET /api/v1/runs/current': noRun, 'GET /api/v1/starter-cars': () => respond(500, { message: 'Server error' }) })

    const { wrapper } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The wrecks could not be loaded.')
    expect(wrapper.findAll('article')).toHaveLength(0)

    serve({ 'GET /api/v1/runs/current': noRun, 'GET /api/v1/starter-cars': () => respond(200, starters) })
    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('article')).toHaveLength(3)
    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
  })

  it('shows an error and stays when the start fails', async () => {
    serve({
      'GET /api/v1/runs/current': noRun,
      'GET /api/v1/starter-cars': () => respond(200, starters),
      'POST /api/v1/runs': () => respond(500, { message: 'Server error' }),
    })

    const { wrapper, router } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    await wrapper.get('[data-test="car-rdzak-gt-4"] [data-test="choose"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Something went wrong. Try again.')
    expect(router.currentRoute.value.name).toBe('choose')
    expect(wrapper.get('[data-test="car-rdzak-gt-4"] [data-test="choose"]').attributes('disabled')).toBeUndefined()
  })

  it('ends the session and returns to the start when the server does not know the token', async () => {
    serve({ 'GET /api/v1/runs/current': () => respond(401, { message: 'Unauthenticated.' }) })

    const { router } = await mountView(ChooseCarView, '/choose')
    await flushPromises()

    expect(readToken()).toBeNull()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('landing'))
  })
})
