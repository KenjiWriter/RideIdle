import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { aFleet, aFleetCar, aFleetGarage } from '@/test/fleetFixtures'
import { NOW } from '@/test/garageFixtures'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import FleetView from './FleetView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

describe('FleetView', () => {
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

  it('lists the cars in the garage of their city, the one the driver plays first, and the free places', async () => {
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Fleet')
    expect(wrapper.get('[data-test="summary"]').text()).toBe('Cars: 2 · Garages: 2')
    expect(wrapper.get('[data-test="garage-zlomowo"] [data-test="garage-title"]').text()).toBe('Złomowo · 1 / 3 places')

    const first = wrapper.get('[data-test="garage-zlomowo"] [data-test="car-5"]')
    expect(first.get('[data-test="car-name"]').text()).toBe('Rdzak GT-4')
    expect(first.get('[data-test="car-selected"]').text()).toBe('Selected')
    expect(first.get('[data-test="car-status"]').text()).toBe('Racing')
    expect(first.get('[data-test="car-where"]').text()).toBe('Złomowo · Złomowo Street Sprint')
    expect(first.get('[data-test="car-fuel"]').text()).toBe('31 / 45 l')
    expect(first.get('[data-test="car-laps"]').text()).toBe('Laps: 12')
    // The car the driver plays has no button to play it.
    expect(first.find('[data-test="play"]').exists()).toBe(false)

    const second = wrapper.get('[data-test="garage-kruszyn"] [data-test="car-6"]')
    expect(second.get('[data-test="car-status"]').text()).toBe('Out of fuel')
    expect(second.find('[data-test="car-selected"]').exists()).toBe(false)
    expect(second.get('[data-test="play"]').text()).toBe('Play this car')

    expect(wrapper.get('[data-test="garage-zlomowo"] [data-test="free-places"]').text()).toBe('Free places: 2')
  })

  it('has the tabs of the garage, with the fleet lit, and keeps the garage lit in the navigation', async () => {
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('[data-test="tab-fleet"]').classes()).toContain('on')
    expect(wrapper.get('[data-test="tab-garage"]').classes()).not.toContain('on')
    // The fleet is a part of the garage, so the garage stays lit in the navigation of the game.
    expect(wrapper.get('[data-test="nav-garage"]').classes()).toContain('on')
  })

  it('shows a car on the road in the garage it is going to, with the time left', async () => {
    const onTheRoad = aFleetCar({
      id: 6,
      selected: false,
      vehicle: { slug: 'bizon-m-2', name: 'Bizon M-2' },
      city: { slug: 'zlomowo', name: 'Złomowo' },
      run: { id: 8, status: 'stopped', stop_reason: 'travel', laps_done: 3, fuel_ml: 40_000, tank_ml: 60_000, track: 'Złomowo Street Sprint' },
      travel: { to: 'kruszyn', to_name: 'Kruszyn', arrive_ms: NOW + 300_000 },
    })
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet({ cars: [aFleetCar(), onTheRoad] })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    // The place is kept at the destination, so the car is listed there.
    const card = wrapper.get('[data-test="garage-kruszyn"] [data-test="car-6"]')
    expect(card.get('[data-test="car-where"]').text()).toBe('On the road: Złomowo → Kruszyn, arrives in 5 min 00 s')
    expect(card.get('[data-test="car-status"]').text()).toBe('On the road')
    expect(wrapper.find('[data-test="garage-zlomowo"] [data-test="car-6"]').exists()).toBe(false)
  })

  it('asks the server again when a journey is due', async () => {
    let asked = 0
    const onTheRoad = aFleetCar({ id: 6, selected: false, travel: { to: 'kruszyn', to_name: 'Kruszyn', arrive_ms: NOW + 4_000 }, run: { id: 8, status: 'stopped', stop_reason: 'travel', laps_done: 0, fuel_ml: 1, tank_ml: 2, track: 'x' } })
    serve({
      'GET /api/v1/cars': () => respond(200, ++asked === 1 ? aFleet({ cars: [aFleetCar(), onTheRoad] }) : aFleet({}, 600_000, NOW + 10_000)),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(6_000)
    await flushPromises()

    expect(asked).toBe(2)
    expect(wrapper.find('[data-test="car-6"] [data-test="car-where"]').text()).toBe('Kruszyn · Kruszyn Quarry')
  })

  it('lets the driver play another car, and says so', async () => {
    const calls = serve({
      'GET /api/v1/cars': () => respond(200, aFleet()),
      'POST /api/v1/cars/6/select': () =>
        respond(200, aFleet({ selected: 6, cars: aFleet().data.cars.map((car) => ({ ...car, selected: car.id === 6 })) })),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()
    await wrapper.get('[data-test="car-6"] [data-test="play"]').trigger('click')
    await flushPromises()

    expect(calls.some((call) => call.method === 'POST' && call.url.endsWith('/cars/6/select'))).toBe(true)
    expect(wrapper.get('[data-test="done"]').text()).toBe('You play Bizon M-2 now.')
    expect(wrapper.find('[data-test="car-6"] [data-test="car-selected"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="car-5"] [data-test="car-selected"]').exists()).toBe(false)
    // The screens that show the car of the driver are asked for it again.
    expect(calls.filter((call) => call.url.endsWith('/v1/garage')).length).toBe(1)
    expect(calls.filter((call) => call.url.endsWith('/v1/map')).length).toBe(1)
  })

  it('buys a garage in a city that is open, for the price it shows', async () => {
    const open = aFleet().data.garages.map((garage) => (garage.city === 'autodrom' ? { ...garage, open: true } : garage))
    const calls = serve({
      'GET /api/v1/cars': () => respond(200, aFleet({ garages: open })),
      'POST /api/v1/garages': () =>
        respond(201, aFleet({ garages: open.map((garage) => (garage.city === 'autodrom' ? aFleetGarage({ city: 'autodrom', name: 'Autodrome', used: 0, free: 3 }) : garage)) }, 100_000)),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('[data-test="garage-offer-autodrom"] [data-test="buy-garage"]').text()).toBe('Buy · $5,000')

    await wrapper.get('[data-test="garage-offer-autodrom"] [data-test="buy-garage"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST' && call.url.endsWith('/v1/garages'))?.body).toEqual({ city: 'autodrom' })
    expect(wrapper.get('[data-test="done"]').text()).toBe('You bought a garage in Autodrome.')
    expect(wrapper.find('[data-test="garage-autodrom"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="garages-to-buy"]').exists()).toBe(false)
  })

  it('says what a closed city asks, and does not let the driver buy there', async () => {
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    const offer = wrapper.get('[data-test="garage-offer-autodrom"]')
    expect(offer.get('[data-test="offer-note"]').text()).toBe('Requires player level 12.')
    expect(offer.get('[data-test="buy-garage"]').attributes('disabled')).toBeDefined()
  })

  it('buys a wreck from the yard into the garage that was picked, with one request id for the intention', async () => {
    const calls = serve({
      'GET /api/v1/cars': () => respond(200, aFleet()),
      'POST /api/v1/cars': () => respond(201, { ...aFleet({}, 461_000), bought: 9, repeated: false }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    // The first garage with room is the target until the driver picks another.
    expect((wrapper.get('[data-test="target"]').element as HTMLSelectElement).value).toBe('zlomowo')

    await wrapper.get('[data-test="target"]').setValue('kruszyn')
    await wrapper.get('[data-test="wreck-bizon-m-2"] [data-test="buy-wreck"]').trigger('click')
    await flushPromises()

    const body = calls.find((call) => call.method === 'POST' && call.url.endsWith('/v1/cars'))?.body as Record<string, string>
    expect(body).toMatchObject({ vehicle: 'bizon-m-2', city: 'kruszyn' })
    expect(body.request_id).toEqual(expect.any(String))
    expect(wrapper.get('[data-test="done"]').text()).toBe('Bizon M-2 is in the garage in Kruszyn.')
    expect(wrapper.get('[data-test="wreck-bizon-m-2"] [data-test="buy-wreck"]').text()).toBe('Buy · $1,390')
  })

  it('names the class of a wreck, and the price of each', async () => {
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('[data-test="wreck-rdzak-gt-4"]').text()).toContain('Hatchback')
    expect(wrapper.get('[data-test="wreck-bizon-m-2"]').text()).toContain('Pickup')
    expect(wrapper.get('[data-test="wreck-rdzak-gt-4"] [data-test="buy-wreck"]').text()).toBe('Buy · $1,140')
  })

  it('does not offer a wreck when no garage has a free place, and when the driver cannot pay', async () => {
    const full = aFleet().data.garages.map((garage) => (garage.owned ? { ...garage, used: garage.capacity, free: 0 } : garage))
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet({ garages: full })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('[data-test="no-room"]').text()).toBe('No garage has a free place. Buy a garage in another city first.')
    expect(wrapper.find('[data-test="target"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="wreck-rdzak-gt-4"] [data-test="buy-wreck"]').attributes('disabled')).toBeDefined()
  })

  it('does not let the driver buy a wreck that costs more than the cash', async () => {
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet({}, 120_000)), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('[data-test="wreck-rdzak-gt-4"] [data-test="buy-wreck"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="wreck-bizon-m-2"] [data-test="buy-wreck"]').attributes('disabled')).toBeDefined()
  })

  it('shows what the server says when it refuses, and asks for the fleet again', async () => {
    let asked = 0
    serve({
      'GET /api/v1/cars': () => respond(200, ++asked && aFleet()),
      'POST /api/v1/cars': () => respond(422, { message: 'The garage in Złomowo is full.', code: 'garage_full' }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()
    await wrapper.get('[data-test="wreck-rdzak-gt-4"] [data-test="buy-wreck"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('The garage in Złomowo is full.')
    expect(asked).toBe(2)
  })

  it('sends a driver without a car to choose one', async () => {
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet({ selected: null, cars: [] })), 'GET /api/v1/runs/current': noRun })

    const { router } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('choose'))
  })

  it('offers a retry when the fleet cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/cars': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, aFleet())), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The fleet could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="garage-zlomowo"]').exists()).toBe(true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/cars': () => respond(200, aFleet()), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(FleetView, '/garage/fleet')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Flota')
    expect(wrapper.get('[data-test="garage-zlomowo"] [data-test="garage-title"]').text()).toBe('Złomowo · 1 / 3 miejsc')
    expect(wrapper.get('[data-test="car-6"] [data-test="play"]').text()).toBe('Graj tym autem')
    expect(wrapper.get('[data-test="wreck-bizon-m-2"] [data-test="buy-wreck"]').text()).toMatch(/^Kup · /)
  })
})
