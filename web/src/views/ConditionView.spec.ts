import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { NOTHING_TO_REPAIR, NOW, aCar, aGarage, aGear, aJob, aMountedPart, aRepairStep, aService } from '@/test/garageFixtures'
import { setModel } from '@/model'
import { mountView } from '@/test/mountView'
import { aTutorial } from '@/test/tutorialFixtures'

import ConditionView from './ConditionView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

/** Street tires at 74% of their life: they give 0.6 + 0.4 x 0.74 = 90% of what they should, and a repair puts them right. */
const tires = aMountedPart({ id: 21, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', condition_percent: 74, condition_loss_percent: 10, repair_next: aRepairStep({ cost_cents: 7_410, duration_ms: 90_000 }) })
/** Brakes at 34%: the board's example, 26% less braking. */
const brakes = aMountedPart({ id: 22, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', condition_percent: 34, condition_loss_percent: 26, repair_next: aRepairStep({ cost_cents: 23_000, duration_ms: 720_000 }) })
const exhaust = aMountedPart({ id: 23, slug: 'sport-exhaust-t2', name: 'Sport exhaust', slot: 'exhaust', condition_percent: 100, repair_next: NOTHING_TO_REPAIR })

const wornCar = (overrides: Parameters<typeof aCar>[0] = {}) =>
  aCar({ mileage_km: 187_420, parts_condition_percent: 69, service: aService({ since_km: 2_420, due_in_km: 2_580 }), ...overrides }, { tires, brakes, exhaust })

describe('ConditionView', () => {
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

  it('shows the mileage with the condition of the whole car, and how far the service is', async () => {
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Technical condition')
    expect(wrapper.get('[data-test="subtitle"]').text()).toBe('Rdzak GT-4 · Złomowo')
    expect(wrapper.get('[data-test="mileage"]').text()).toBe('187,420 km')
    expect(wrapper.get('[data-test="overall"]').text()).toBe('Overall condition 69%')
    expect(wrapper.get('[data-test="service-km"]').text()).toBe('2,580 km')
    expect(wrapper.get('[data-test="service-card"] [role="progressbar"]').attributes('aria-valuenow')).toBe('52')
    expect(wrapper.get('[data-test="service-line"]').text()).toBe('Service in 2,580 km')
  })

  it('lists the slots in the order of the board with the condition of each part, marks the ones that need a repair and says what the worst gives less', async () => {
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    const order = wrapper.findAll('[data-test^="row-"]').map((row) => row.attributes('data-test'))
    expect(order).toEqual(['row-tires', 'row-suspension', 'row-brakes', 'row-exhaust', 'row-engine', 'row-boost', 'row-gearbox', 'row-cooling', 'row-tank'])

    expect(wrapper.get('[data-test="percent-tires"]').text()).toBe('74%')
    expect(wrapper.find('[data-test="needs-repair-tires"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="percent-brakes"]').text()).toBe('34% !')
    expect(wrapper.get('[data-test="row-brakes"] .bar').classes()).toContain('bar-bad')
    expect(wrapper.get('[data-test="row-tires"] .bar').classes()).not.toContain('bar-bad')
    expect(wrapper.get('[data-test="percent-exhaust"]').text()).toBe('100%')

    // A slot with no part says so, and the engine of the body does not wear.
    expect(wrapper.get('[data-test="percent-boost"]').text()).toBe('none')
    expect(wrapper.get('[data-test="percent-engine"]').text()).toBe('no wear')

    expect(wrapper.get('[data-test="effect"]').text()).toBe('Brakes 34%: gives 26% less')
    expect(wrapper.get('[data-test="legend"]').text()).toContain('! = needs a repair')
  })

  it('says nothing of an effect while no part is worn enough, and offers nothing to repair', async () => {
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust }) })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.find('[data-test="effect"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="repair"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="repair-info"]').text()).toBe('Nothing to repair')
  })

  it('offers to repair the most worn part with its price and time, and to service the car', async () => {
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.get('[data-test="repair"]').text()).toBe('Repair: Brakes')
    expect(wrapper.get('[data-test="repair"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="repair-info"]').text()).toBe('$230.00 · 12 min 00 s')
    expect(wrapper.get('[data-test="service"]').text()).toBe('Service')
    expect(wrapper.get('[data-test="service-info"]').text()).toBe('$45.60 · 14 min 42 s')
  })

  it('says a part that is worn out has to be replaced, and does not offer to repair it', async () => {
    const wornOut = aMountedPart({ ...brakes, condition_percent: 0, condition_loss_percent: 40, repair_next: { cost_cents: null, duration_ms: null, locked: { reason: 'worn_out' } } })
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ parts_condition_percent: 0 }, { brakes: wornOut }) })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.get('[data-test="repair"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="repair-info"]').text()).toBe('Worn out: it has to be replaced')
  })

  it('queues the repair of the most worn part that can be put right, and tells where the job waits', async () => {
    const job = aJob({ id: 6, kind: 'repair', cost_cents: 23_000, part: brakes, finish_ms: NOW + 720_000 })
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })),
      'POST /api/v1/garage/jobs': () =>
        respond(201, aGarage({ car: wornCar({ run: { id: 7, status: 'stopped', stop_reason: 'workshop' } }), workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [job] } })),
      'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()
    await wrapper.get('[data-test="repair"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'repair', part: 22 })
    expect(wrapper.get('[data-test="queued"]').text()).toContain('The job is waiting in the workshop.')
    expect(wrapper.get('[data-test="to-workshop"]').attributes('href')).toBe('/workshop')

    // The part waits in a job now, so the next most worn one is offered, and a second repair of the brakes is not.
    expect(wrapper.get('[data-test="repair"]').text()).toBe('Repair: Tires')
  })

  it('queues a service with no part, and does not offer a second one while it waits', async () => {
    const job = aJob({ id: 5, kind: 'service', cost_cents: 4_560, part: null, finish_ms: NOW + 882_000 })
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })),
      'POST /api/v1/garage/jobs': () => respond(201, aGarage({ car: wornCar(), workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [job] } })),
      'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()
    await wrapper.get('[data-test="service"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'service' })
    expect(wrapper.get('[data-test="service"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="service-info"]').text()).toBe('A service is in the queue.')
  })

  it('shows what the server says when the work is refused', async () => {
    serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })),
      'POST /api/v1/garage/jobs': () => respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()
    await wrapper.get('[data-test="repair"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough cash.')
    expect(wrapper.find('[data-test="queued"]').exists()).toBe(false)
  })

  it('shows a service that is overdue in red, with what it takes off the performance', async () => {
    const late = wornCar({ service: aService({ since_km: 5_800, due_in_km: -800, overdue_km: 800, penalty_percent: 4 }) })
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: late })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.get('[data-test="service-km"]').classes()).toContain('text-danger')
    expect(wrapper.get('[data-test="service-line"]').text()).toBe('Service overdue by 800 km: −4% performance')
    expect(wrapper.get('[data-test="service-card"] .bar').classes()).toContain('bar-bad')
  })

  it('leads back to the garage, and keeps the garage lit in the navigation', async () => {
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar() })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.get('[data-test="back"]').attributes('href')).toBe('/garage')
  })
})

describe('ConditionView in a game with another model', () => {
  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    setModel({
      slots: [
        { key: 'reactor', role: 'engine', hidden: false },
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

  it('lists the slots of the car by what they are to the game: the parts that wear out first, the slots without a role, then the engine group', async () => {
    const slots = [
      { slot: 'reactor', role: 'engine', part: null, built_in: true },
      { slot: 'sails', role: null, part: null, built_in: false },
      { slot: 'hull', role: 'tires', part: null, built_in: false },
    ]
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: wornCar({ slots }) })), 'GET /api/v1/runs/current': noRun })

    const { wrapper } = await mountView(ConditionView, '/garage/condition')
    await flushPromises()

    expect(wrapper.findAll('[data-test^="row-"]').map((row) => row.attributes('data-test'))).toEqual(['row-hull', 'row-sails', 'row-reactor'])
  })
})
