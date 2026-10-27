import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { NOW, aCar, aGarage, aJob, aMountedPart, aPart, anUpgradeStep } from '@/test/garageFixtures'
import { mountView } from '@/test/mountView'
import { aTutorial } from '@/test/tutorialFixtures'

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
            car: aCar({ stats: { accel: 98.94, top_speed: 176.17, grip: 90, handling: 88, braking: 90, clearance: 20 } }, { exhaust }),
            inventory: [tires],
          }),
        ),
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Workshop')
    expect(wrapper.get('[data-test="city"]').text()).toBe('Workshop in Złomowo')
    expect(wrapper.get('[data-test="mechanic"]').text()).toBe('Mechanic of level 1: jobs take 2% less time.')
    expect(wrapper.get('[data-test="car"] h2').text()).toBe('Rdzak GT-4')
    expect(wrapper.get('[data-test="car-level"]').text()).toBe('Level 3')

    expect(wrapper.get('[data-test="stat-accel"] > span:first-child').text()).toBe('Acceleration')
    expect(wrapper.get('[data-test="stat-accel"] > span:last-child').text()).toBe('98.9 +1.9')
    expect(wrapper.get('[data-test="delta-top_speed"]').text()).toBe('+1.2')
    // The sixth statistic: how well the car can leave the road. A hatchback has little of it.
    expect(wrapper.get('[data-test="stat-clearance"] > span:first-child').text()).toBe('Off-road')
    expect(wrapper.get('[data-test="stat-clearance"] > span:last-child').text()).toBe('20')
    expect(wrapper.find('[data-test="delta-grip"]').exists()).toBe(false)

    expect(wrapper.get('[data-test="slot-exhaust"]').text()).toContain('Sport exhaust')
    expect(wrapper.get('[data-test="slot-tires"]').text()).toContain('Factory')
    expect(wrapper.get('[data-test="slot-engine"]').text()).toContain('R4 1.8')

    expect(wrapper.get('[data-test="part-21"]').text()).toContain('Street tires T2')
    expect(wrapper.findAll('[data-test="part-21"] [data-test="part-stats"] li').map((line) => line.text())).toEqual(['Grip 100', 'Handling 98', 'Braking 100'])
  })

  it('points the tutorial at the tires that wait in the inventory, not at the first part, and asks it again when a job is queued', async () => {
    const suspension = aPart({ id: 30, slug: 'sport-suspension-t1', name: 'Sport suspension', slot: 'suspension', tier: 1 })
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ inventory: [suspension, tires] })),
      'POST /api/v1/garage/jobs': () => respond(201, aGarage({ inventory: [suspension, tires] })),
      'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
      'GET /api/v1/runs/current': noRun,
    })
    const asked = () => calls.filter((call) => call.method === 'GET' && call.url === '/api/v1/tutorial').length

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.findAll('[data-tutorial="mount"]')).toHaveLength(1)
    expect(wrapper.get('[data-tutorial="mount"]').attributes('data-test')).toBe('put-on-21')
    // The shell asked for the tutorial when the screen opened.
    expect(asked()).toBe(1)

    await wrapper.get('[data-test="put-on-21"]').trigger('click')
    await flushPromises()

    // A job in the queue is what a step of the tutorial waits for, and the server moves it only when it is asked.
    expect(asked()).toBe(2)
  })

  it('puts a part on the car and shows the job', async () => {
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ inventory: [tires] })),
      'POST /api/v1/garage/jobs': () =>
        respond(
          201,
          aGarage({
            inventory: [tires],
            workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ id: 3, part: tires, finish_ms: NOW + 235_200 })] },
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
    expect(wrapper.get('[data-test="job-3-state"]').text()).toContain('In progress: 3 min 55 s left')
    expect(wrapper.get('[data-test="job-bar"]').attributes('aria-valuenow')).toBe('20')
    expect(wrapper.get('[data-test="standing"]').text()).toBe('The car stands in the workshop and earns nothing until the last job is done.')
    // The part that waits for the mechanic cannot be queued twice.
    expect(wrapper.get('[data-test="put-on-21"]').attributes('disabled')).toBeDefined()
  })

  it('takes a part off the car', async () => {
    const calls = serve({
      'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust }) })),
      'POST /api/v1/garage/jobs': () => respond(201, aGarage({ car: aCar({}, { exhaust }), workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ kind: 'unmount', part: exhaust })] } })),
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
              city: { slug: 'zlomowo', name: 'Złomowo' },
              queue_max: 4,
              mechanic: { level: 1, time_cut_ppm: 20_000 },
              jobs: [aJob({ id: 1, finish_ms: NOW + 65_000 }), aJob({ id: 2, status: 'queued', part: tires, start_ms: NOW + 65_000, finish_ms: NOW + 300_200 })],
            },
          }),
        ),
    })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="job-1-state"]').text()).toContain('In progress: 1 min 05 s left')
    expect(wrapper.get('[data-test="job-2-state"]').text()).toBe('Waiting')

    await vi.advanceTimersByTimeAsync(5_000)

    expect(wrapper.get('[data-test="job-1-state"]').text()).toContain('In progress: 1 min 00 s left')
  })

  it('asks the server again when a job is due, and shows what became of it', async () => {
    let asked = 0
    serve({
      'GET /api/v1/garage': () => {
        asked += 1

        return respond(
          200,
          asked === 1
            ? aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ finish_ms: NOW + 4_000 })] } })
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

        return respond(200, aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [aJob({ finish_ms: NOW - 1_000 })] } }))
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

  describe('cancelling a job', () => {
    const factoryTires = aPart({ id: 31, slug: 'street-tires-t1', name: 'Street tires T1', slot: 'tires', tier: 1, mounted: true, car_id: 5 })
    const queueOf = (jobs: ReturnType<typeof aJob>[]) => aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs } })
    const takeOff = aJob({ id: 1, kind: 'unmount', part: factoryTires, finish_ms: NOW + 60_000 })
    const putOn = aJob({ id: 2, status: 'queued', part: tires, start_ms: NOW + 60_000, finish_ms: NOW + 295_200 })
    const exhaustJob = aJob({ id: 3, status: 'queued', part: aPart({ id: 12, name: 'Sport exhaust' }), start_ms: NOW + 295_200, finish_ms: NOW + 530_400 })

    it('cancels a job that waits at once, because nothing is lost with it', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([takeOff, putOn, exhaustJob])),
        'DELETE /api/v1/garage/jobs/3': () => respond(200, { ...queueOf([takeOff, putOn]), cancelled: [3] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-3"]').trigger('click')
      await flushPromises()

      expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/garage/jobs/3')).toBe(true)
      expect(wrapper.find('[data-test="job-3"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="queue"] h2').text()).toBe('Queue 2 of 4')
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled.')
    })

    it('asks first about the job the mechanic is at, because its work is lost', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([aJob({ id: 1, part: tires })])),
        'DELETE /api/v1/garage/jobs/1': () => respond(200, { ...queueOf([]), cancelled: [1] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-1"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-1"]').text()).toContain('Cancel this job?')
      expect(wrapper.get('[data-test="cancel-sure-1"]').text()).toContain('The work already done is lost, and the part stays where it was.')
      expect(calls.some((call) => call.method === 'DELETE')).toBe(false)

      // Keeping it closes the question and sends nothing.
      await wrapper.get('[data-test="cancel-keep-1"]').trigger('click')

      expect(wrapper.find('[data-test="cancel-sure-1"]').exists()).toBe(false)
      expect(calls.some((call) => call.method === 'DELETE')).toBe(false)

      await wrapper.get('[data-test="cancel-1"]').trigger('click')
      await wrapper.get('[data-test="cancel-confirm-1"]').trigger('click')
      await flushPromises()

      expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(1)
      expect(wrapper.get('[data-test="queue-empty"]').text()).toBe('Nothing is waiting for the mechanic.')
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled.')
    })

    it('says that the putting on of the new part goes with the taking off of the old one, and tells it afterwards', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, queueOf([takeOff, putOn])),
        'DELETE /api/v1/garage/jobs/1': () => respond(200, { ...queueOf([]), cancelled: [1, 2] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-1"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-1"]').text()).toContain('Putting on Street tires T2 is cancelled with it.')

      await wrapper.get('[data-test="cancel-confirm-1"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled, and putting on Street tires T2 with it.')
      expect(wrapper.find('[data-test="job-2"]').exists()).toBe(false)
    })

    it('cancels the putting on of the new part without asking, and leaves the taking off', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([takeOff, putOn])),
        'DELETE /api/v1/garage/jobs/2': () => respond(200, { ...queueOf([takeOff]), cancelled: [2] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-2"]').trigger('click')
      await flushPromises()

      expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/garage/jobs/2')).toBe(true)
      expect(wrapper.find('[data-test="cancel-sure-2"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="job-1"]').text()).toContain('Taking off: Street tires T1')
    })

    it('shows what the server says when the job is gone, and asks for the queue again', async () => {
      let asked = 0
      serve({
        'GET /api/v1/garage': () => respond(200, ++asked === 1 ? queueOf([takeOff, putOn]) : queueOf([])),
        'DELETE /api/v1/garage/jobs/2': () => respond(422, { message: 'There is no such job.', code: 'job_not_found' }),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-2"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('There is no such job.')
      expect(wrapper.find('[data-test="cancelled"]').exists()).toBe(false)
      // The queue that was shown was stale: it is the server's now.
      expect(asked).toBe(2)
      expect(wrapper.find('[data-test="job-2"]').exists()).toBe(false)
    })

    it('speaks the language of the driver', async () => {
      await setLocale('pl')
      serve({ 'GET /api/v1/garage': () => respond(200, queueOf([aJob({ id: 1, part: tires })])) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="cancel-1"]').text()).toBe('Anuluj')

      await wrapper.get('[data-test="cancel-1"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-1"]').text()).toContain('Anulować to zlecenie?')
      expect(wrapper.get('[data-test="cancel-confirm-1"]').text()).toBe('Tak, anuluj')
    })
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
    expect(wrapper.get('[data-test="city"]').text()).toBe('Warsztat w mieście Złomowo')
    expect(wrapper.get('[data-test="mechanic"]').text()).toBe('Mechanik poziomu 1: zlecenia trwają o 2% krócej.')
    expect(wrapper.get('[data-test="slot-tires"]').text()).toContain('Fabryczne')
    expect(wrapper.get('[data-test="put-on-21"]').text()).toBe('Załóż')
  })

  describe('upgrading a part', () => {
    const mountedTires = (overrides: Parameters<typeof aMountedPart>[0] = {}) =>
      aMountedPart({ id: 21, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, stats: { grip: 100, handling: 98, braking: 100 }, ...overrides })
    const queueOf = (jobs: ReturnType<typeof aJob>[], car = aCar({ run: { id: 7, status: 'stopped', stop_reason: 'workshop' } }, { tires: mountedTires() })) =>
      aGarage({ car, workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs } })
    const upgradeJob = (overrides: Parameters<typeof aJob>[0] = {}) =>
      aJob({ id: 4, kind: 'upgrade', cost_cents: 19_950, upgrade_to: 1, part: mountedTires(), finish_ms: NOW + 294_000, ...overrides })

    it('offers the next step of a part on the car with its price and time, and queues it when asked', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: mountedTires() }) })),
        'POST /api/v1/garage/jobs': () => respond(201, queueOf([upgradeJob()])),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="upgrade-21"]').text()).toBe('Upgrade to +1')
      expect(wrapper.get('[data-test="upgrade-21"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.get('[data-test="upgrade-info-21"]').text()).toBe('$199.50 · 4 min 54 s')
      // A part in the inventory is not upgraded: it has to be on the car first.
      expect(wrapper.find('[data-test="upgrade-mark"]').exists()).toBe(false)

      await wrapper.get('[data-test="upgrade-21"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'upgrade', part: 21 })
      expect(wrapper.get('[data-test="job-4"]').text()).toContain('Upgrading to +1: Street tires T2')
      expect(wrapper.get('[data-test="job-4-state"]').text()).toContain('In progress: 4 min 54 s left')
      // The part that is in the workshop cannot be queued again, or taken off.
      expect(wrapper.get('[data-test="upgrade-21"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="take-off-21"]').attributes('disabled')).toBeDefined()
    })

    it('shows the level of an upgraded part on its tile and in the inventory, and says nothing for a part that is not upgraded', async () => {
      const spare = aPart({ id: 22, slug: 'street-tires-t2', name: 'Spare tires', slot: 'tires', tier: 2, upgrade: 2, upgrade_max: 3, stats: { grip: 106 } })
      const brakes = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', upgrade: 0 })
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: mountedTires({ upgrade: 1 }), brakes }), inventory: [spare] })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="slot-tires"] [data-test="upgrade-mark"]').text()).toBe('+1')
      expect(wrapper.get('[data-test="slot-tires"] [data-test="upgrade-mark"]').attributes('aria-label')).toBe('Upgrade +1')
      expect(wrapper.get('[data-test="part-22"] [data-test="upgrade-mark"]').text()).toBe('+2')
      expect(wrapper.find('[data-test="slot-brakes"] [data-test="upgrade-mark"]').exists()).toBe(false)
    })

    it('says why a step is locked and offers no button that works: the level of the car, or a part that is as far as it goes', async () => {
      const tires = mountedTires({ upgrade: 2, upgrade_next: anUpgradeStep({ to: 3, cost_cents: 41_946, duration_ms: 576_240, locked: { reason: 'car_level', required: 4, current: 3 } }) })
      const brakes = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', upgrade: 3, upgrade_next: anUpgradeStep({ to: null, cost_cents: null, duration_ms: null, locked: { reason: 'max' } }) })
      const exhaust = aMountedPart({ id: 11, upgrade_next: anUpgradeStep({ to: null, cost_cents: null, duration_ms: null, locked: { reason: 'unavailable' } }) })
      const calls = serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires, brakes, exhaust }) })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      // The level 3 of the car allows +2: the step to +3 waits for the level 4, and the button is there but cannot be pressed.
      expect(wrapper.get('[data-test="upgrade-21"]').text()).toBe('Upgrade to +3')
      expect(wrapper.get('[data-test="upgrade-21"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="upgrade-info-21"]').text()).toBe('Needs car level 4 (you have 3)')

      expect(wrapper.find('[data-test="upgrade-12"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="upgrade-info-12"]').text()).toBe('Fully upgraded')

      // A part with nothing to upgrade by says nothing and offers nothing.
      expect(wrapper.find('[data-test="upgrade-11"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="upgrade-info-11"]').exists()).toBe(false)

      await wrapper.get('[data-test="upgrade-21"]').trigger('click')
      await flushPromises()

      expect(calls.some((call) => call.method === 'POST')).toBe(false)
    })

    it('shows what the server says when it refuses an upgrade', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: mountedTires() }) })),
        'POST /api/v1/garage/jobs': () => respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="upgrade-21"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough cash.')
      expect(wrapper.find('[data-test="job-4"]').exists()).toBe(false)
    })

    it('asks first about an upgrade that the mechanic is at, and says that its price is lost with the work', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([upgradeJob()])),
        'DELETE /api/v1/garage/jobs/4': () => respond(200, { ...queueOf([], aCar({}, { tires: mountedTires() })), cancelled: [4] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-4"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-4"]').text()).toContain('The work already done and the $199.50 paid for the upgrade are lost, and the part stays as it was.')
      expect(calls.some((call) => call.method === 'DELETE')).toBe(false)

      await wrapper.get('[data-test="cancel-confirm-4"]').trigger('click')
      await flushPromises()

      expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(1)
      // Nothing is given back for it.
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled.')
    })

    it('cancels an upgrade that waits at once, and says that its price is back', async () => {
      const brakesJob = aJob({ id: 3, kind: 'upgrade', cost_cents: 12_000, upgrade_to: 1, part: aMountedPart({ id: 12, name: 'Disc brakes', slot: 'brakes' }), finish_ms: NOW + 100_000 })
      const waiting = upgradeJob({ status: 'queued', start_ms: NOW + 100_000, finish_ms: NOW + 394_000 })
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([brakesJob, waiting])),
        'DELETE /api/v1/garage/jobs/4': () => respond(200, { ...queueOf([brakesJob]), cancelled: [4] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="job-4"]').text()).toContain('Upgrading to +1: Street tires T2')

      await wrapper.get('[data-test="cancel-4"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="cancel-sure-4"]').exists()).toBe(false)
      expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/garage/jobs/4')).toBe(true)
      expect(wrapper.find('[data-test="job-4"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled. $199.50 is back in your wallet.')
    })

    it('speaks the language of the driver', async () => {
      await setLocale('pl')
      const tires = mountedTires({ upgrade: 2, upgrade_next: anUpgradeStep({ to: 3, locked: { reason: 'car_level', required: 4, current: 3 } }) })
      const brakes = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', upgrade: 3, upgrade_next: anUpgradeStep({ to: null, cost_cents: null, duration_ms: null, locked: { reason: 'max' } }) })
      const exhaust = aMountedPart({ id: 11 })
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires, brakes, exhaust }), workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, jobs: [upgradeJob({ part: exhaust, status: 'queued' })] } })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="upgrade-21"]').text()).toBe('Ulepsz do +3')
      expect(wrapper.get('[data-test="upgrade-info-21"]').text()).toBe('Wymaga poziomu auta 4 (masz 3)')
      expect(wrapper.get('[data-test="upgrade-info-12"]').text()).toBe('Pełne ulepszenie')
      expect(wrapper.get('[data-test="upgrade-info-11"]').text()).toBe('199,50 $ · 4 min 54 s')
      expect(wrapper.get('[data-test="job-4"]').text()).toContain('Ulepszanie do +1: Sport exhaust')
    })
  })

  it('names the rarity of the parts in the inventory, and on the tiles of the parts that are rarer than common', async () => {
    const epicTires = aPart({ id: 31, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, stats: { grip: 138 }, rarity: 'epic', rarity_rank: 4 })
    const rareExhaust = aPart({ id: 11, mounted: true, car_id: 5, rarity: 'rare', rarity_rank: 3 })
    const commonBrakes = aPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', mounted: true, car_id: 5 })
    serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust: rareExhaust, brakes: commonBrakes }), inventory: [epicTires, tires] })) })

    const { wrapper } = await mountView(WorkshopView, '/workshop')
    await flushPromises()

    expect(wrapper.get('[data-test="part-31"] [data-test="rarity"]').text()).toBe('Epic')
    expect(wrapper.findAll('[data-test="part-31"] .pips b.on')).toHaveLength(4)
    expect(wrapper.get('[data-test="part-21"] [data-test="rarity"]').text()).toBe('Common')

    // A rare part on the car shows its rarity and has a border in its color, and a common one has neither.
    expect(wrapper.get('[data-test="slot-exhaust"]').classes()).toContain('r3')
    expect(wrapper.get('[data-test="slot-exhaust"] [data-test="rarity"]').text()).toBe('Rare')
    expect(wrapper.get('[data-test="slot-brakes"]').classes().filter((name) => /^r\d$/.test(name))).toEqual([])
    expect(wrapper.find('[data-test="slot-brakes"] [data-test="rarity"]').exists()).toBe(false)
  })
})
