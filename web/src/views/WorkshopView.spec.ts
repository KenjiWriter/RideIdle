import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { NOW, aCar, aGarage, aJob, aPart } from '@/test/garageFixtures'
import { mountView } from '@/test/mountView'

import WorkshopView from './WorkshopView.vue'

const fetchMock = vi.fn()
const serve = (routes: Record<string, () => Response | Promise<Response>>) => serveApi(fetchMock, routes)
const noRun = () => respond(404, { message: 'No run', code: 'no_run' })

const tires = aPart({ id: 21, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, stats: { grip: 100, handling: 98, braking: 100 } })
const exhaust = aPart({ id: 11, mounted: true, car_id: 5 })

describe('WorkshopView', () => {
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

  it('shows the car, its statistics with what the parts change, its slots and the inventory', async () => {
    serve({
      'GET /api/v1/garage': () =>
        respond(
          200,
          aGarage({
            car: aCar({ stats: { accel: 98.94, top_speed: 176.17, grip: 90, handling: 88, braking: 90 } }, { exhaust }),
            inventory: [tires],
          }),
        ),
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Workshop')
    expect(wrapper.get('[data-test="mechanic"]').text()).toBe('Mechanic of level 1: jobs take 2% less time.')
    expect(wrapper.get('[data-test="car"] h2').text()).toBe('Rdzak GT-4')
    expect(wrapper.get('[data-test="car-level"]').text()).toBe('Level 3')

    expect(wrapper.get('[data-test="stat-accel"] dt').text()).toBe('Acceleration')
    expect(wrapper.get('[data-test="stat-accel"] dd').text()).toBe('98.9 +1.9')
    expect(wrapper.get('[data-test="delta-top_speed"]').text()).toBe('+1.2')
    expect(wrapper.find('[data-test="delta-grip"]').exists()).toBe(false)

    expect(wrapper.get('[data-test="slot-exhaust"]').text()).toContain('Sport exhaust')
    expect(wrapper.get('[data-test="slot-tires"]').text()).toContain('Factory')
    expect(wrapper.get('[data-test="slot-engine"]').text()).toContain('R4 1.8')

    expect(wrapper.get('[data-test="part-21"]').text()).toContain('Street tires T2')
    expect(wrapper.findAll('[data-test="part-21"] [data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 100', 'Handling 98', 'Braking 100'])
  })

  it('puts a part on the car and shows the job', async () => {
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ inventory: [tires] })),
      'POST /api/v1/garage/jobs': () =>
        respond(
          201,
          aGarage({
            inventory: [tires],
            workshop: { queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ id: 3, part: tires, finish_ms: NOW + 235_200 })] },
            car: aCar({ run: { id: 7, status: 'stopped', stop_reason: 'workshop' } }),
          }),
        ),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="queue-empty"]').text()).toBe('Nothing is waiting for the mechanic.')

    await wrapper.get('[data-test="put-on-21"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'mount', part: 21 })
    expect(wrapper.get('[data-test="queue"] h2').text()).toBe('Queue 1 of 4')
    expect(wrapper.get('[data-test="job-3"]').text()).toContain('Putting on: Street tires T2')
    expect(wrapper.get('[data-test="job-3-state"]').text()).toBe('In progress: 3 min 55 s left')
    expect(wrapper.get('[data-test="standing"]').text()).toBe('The car stands in the workshop and earns nothing until the last job is done.')
    // The part that waits for the mechanic cannot be queued twice.
    expect(wrapper.get('[data-test="put-on-21"]').attributes('disabled')).toBeDefined()
  })

  it('takes a part off the car', async () => {
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust }) })),
      'POST /api/v1/garage/jobs': () => respond(201, aGarage({ car: aCar({}, { exhaust }), workshop: { queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ kind: 'unmount', part: exhaust })] } })),
      'GET /api/v1/runs/current': noRun,
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()
    await wrapper.get('[data-test="take-off-11"]').trigger('click')
    await flushPromises()

    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'unmount', part: 11 })
    expect(wrapper.get('[data-test="job-1"]').text()).toContain('Taking off: Sport exhaust')
    expect(wrapper.get('[data-test="take-off-11"]').attributes('disabled')).toBeDefined()
  })

  it('counts down with the clock of the server, and shows the jobs that wait for the mechanic', async () => {
    serve({
      'GET /api/v1/garage': () =>
        respond(
          200,
          aGarage({
            workshop: {
              queue_max: 4,
              mechanic: { level: 1, time_cut_ppm: 20_000 },
              jobs: [aJob({ id: 1, finish_ms: NOW + 65_000 }), aJob({ id: 2, status: 'queued', part: tires, start_ms: NOW + 65_000, finish_ms: NOW + 300_200 })],
            },
          }),
        ),
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="job-1-state"]').text()).toBe('In progress: 1 min 05 s left')
    expect(wrapper.get('[data-test="job-2-state"]').text()).toBe('Waiting')

    await vi.advanceTimersByTimeAsync(5_000)

    expect(wrapper.get('[data-test="job-1-state"]').text()).toBe('In progress: 1 min 00 s left')
  })

  it('asks the server again when a job is due, and shows what became of it', async () => {
    let asked = 0
    serve({
      'GET /api/v1/garage': () => {
        asked += 1

        return respond(
          200,
          asked === 1
            ? aGarage({ workshop: { queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ finish_ms: NOW + 4_000 })] } })
            : aGarage({ car: aCar({}, { exhaust }) }, NOW + 10_000),
        )
      },
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.find('[data-test="job-1"]').exists()).toBe(true)

    await vi.advanceTimersByTimeAsync(6_000)
    await flushPromises()

    expect(asked).toBe(2)
    expect(wrapper.find('[data-test="job-1"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="slot-exhaust"]').text()).toContain('Sport exhaust')
  })

  it('does not ask over and over when the server still says a job is not done', async () => {
    let asked = 0
    serve({
      'GET /api/v1/garage': () => {
        asked += 1

        return respond(200, aGarage({ workshop: { queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ finish_ms: NOW - 1_000 })] } }))
      },
    })

    await mountView(WorkshopView, '/workshop')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(9_000)

    // At most once every few seconds, not once a second.
    expect(asked).toBeLessThanOrEqual(4)
  })

  it('shows what the server says when it refuses a job', async () => {
    serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ inventory: [tires] })),
      'POST /api/v1/garage/jobs': () => respond(422, { message: 'Requires car level 3 (you have 2).', code: 'car_level_required', details: { required: 3, current: 2 } }),
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()
    await wrapper.get('[data-test="put-on-21"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="error"]').text()).toBe('Requires car level 3 (you have 2).')
  })

  it('says so when there is no car, and when there is nothing in the inventory', async () => {
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: null })) })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="no-car"]').text()).toBe('You have no car yet.')

    fetchMock.mockReset()
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage()) })

    const second = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(second.wrapper.get('[data-test="inventory-empty"]').text()).toBe('No spare parts. Buy some in the shop.')
  })

  it('offers a retry when the workshop cannot be loaded', async () => {
    let attempt = 0
    serve({ 'GET /api/v1/garage': () => (++attempt === 1 ? respond(500, { message: 'Server error' }) : respond(200, aGarage())) })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="load-error"]').text()).toContain('The workshop could not be loaded.')

    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="car"]').exists()).toBe(true)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust }), inventory: [tires] })) })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Warsztat')
    expect(wrapper.get('[data-test="mechanic"]').text()).toBe('Mechanik poziomu 1: zlecenia trwają o 2% krócej.')
    expect(wrapper.get('[data-test="slot-tires"]').text()).toContain('Fabryczne')
    expect(wrapper.get('[data-test="put-on-21"]').text()).toBe('Załóż')
  })
})
