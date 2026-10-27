import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { storeToken } from '@/auth/token'
import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { NOTHING_TO_REPAIR, NOW, aCar, aGarage, aGear, aJob, aMergeOffer, aMergeResult, aMountedPart, aPart, aRepairStep, aService, anUpgradeStep } from '@/test/garageFixtures'
import { mountView } from '@/test/mountView'
import { useRunStore } from '@/stores/run'
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
            workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [aJob({ id: 3, part: tires, finish_ms: NOW + 235_200 })] },
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
      'POST /api/v1/garage/jobs': () => respond(201, aGarage({ car: aCar({}, { exhaust }), workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [aJob({ kind: 'unmount', part: exhaust })] } })),
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
              gear: aGear(),
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
            ? aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [aJob({ finish_ms: NOW + 4_000 })] } })
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

        return respond(200, aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [aJob({ finish_ms: NOW - 1_000 })] } }))
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
    const queueOf = (jobs: ReturnType<typeof aJob>[]) => aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs } })
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
      aGarage({ car, workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs } })
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
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires, brakes, exhaust }), workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [upgradeJob({ part: exhaust, status: 'queued' })] } })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="upgrade-21"]').text()).toBe('Ulepsz do +3')
      expect(wrapper.get('[data-test="upgrade-info-21"]').text()).toBe('Wymaga poziomu auta 4 (masz 3)')
      expect(wrapper.get('[data-test="upgrade-info-12"]').text()).toBe('Pełne ulepszenie')
      expect(wrapper.get('[data-test="upgrade-info-11"]').text()).toBe('199,50 $ · 4 min 54 s')
      expect(wrapper.get('[data-test="job-4"]').text()).toContain('Ulepszanie do +1: Sport exhaust')
    })
  })

  describe('merging parts', () => {
    // Two street tires of tier 2 that go together, a rare pair that does not go with them, and an exhaust with no partner at all.
    const base = (overrides: Parameters<typeof aPart>[0] = {}) =>
      aPart({ id: 21, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, merge: aMergeOffer({ with: [22] }), ...overrides })
    const spare = aPart({ id: 22, slug: 'street-tires-t2', name: 'Spare tires', slot: 'tires', tier: 2, upgrade: 2, merge: aMergeOffer({ with: [21] }) })
    const rarePair = aPart({ id: 23, slug: 'street-tires-t2', name: 'Rare tires', slot: 'tires', tier: 2, rarity: 'rare', rarity_rank: 3, merge: aMergeOffer({ to: 'epic', with: [] }) })
    const lonely = aPart({ id: 24, name: 'Lonely exhaust', merge: aMergeOffer({ with: [] }) })
    const sheet = (inventory: ReturnType<typeof aPart>[], extra: Partial<ReturnType<typeof aGarage>['data']> = {}) => aGarage({ inventory, ...extra })
    const mergeJob = (overrides: Parameters<typeof aJob>[0] = {}) =>
      aJob({ id: 6, kind: 'merge', cost_cents: 5_700, part: base(), merge_with: spare, merge_to: 'uncommon', status: 'queued', start_ms: NOW, finish_ms: NOW + 470_400, ...overrides })
    const withJobs = (jobs: ReturnType<typeof aJob>[], inventory: ReturnType<typeof aPart>[] = [base({ merge: null }), { ...spare, merge: null }]) =>
      sheet(inventory, { workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs } })

    it('offers the merge only on the parts that can be merged, and lists only the partners the server named', async () => {
      // A legendary part, or one that waits in a job, has no offer: it has no button either.
      const legendary = aPart({ id: 25, name: 'Legendary exhaust', rarity: 'legendary', rarity_rank: 5, merge: null })
      serve({ 'GET /api/v1/garage': () => respond(200, sheet([base(), spare, rarePair, lonely, legendary])) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="merge-open-21"]').text()).toBe('Merge')
      expect(wrapper.get('[data-test="merge-open-21"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.find('[data-test="merge-open-25"]').exists()).toBe(false)
      // The chooser is closed until it is asked for.
      expect(wrapper.find('[data-test="merge-21"]').exists()).toBe(false)

      await wrapper.get('[data-test="merge-open-21"]').trigger('click')

      // The spare tires are the only partner: not the rare ones (another rarity), and not the exhaust (another slot).
      expect(wrapper.findAll('[data-test^="merge-partner-"]').map((button) => button.attributes('data-test'))).toEqual(['merge-partner-22'])
      expect(wrapper.get('[data-test="merge-partner-22"]').text()).toContain('Spare tires')
      expect(wrapper.get('[data-test="merge-partner-22"] [data-test="upgrade-mark"]').text()).toBe('+2')
      expect(wrapper.find('[data-test="merge-hint"]').exists()).toBe(false)
      // Nothing is said about the attempt before a partner is chosen, and nothing can be confirmed.
      expect(wrapper.find('[data-test="merge-summary"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="merge-confirm-21"]').attributes('disabled')).toBeDefined()
    })

    it('says that a second part is needed when there is nothing to merge a part with, and offers no confirmation', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, sheet([lonely])) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="merge-open-24"]').trigger('click')

      expect(wrapper.get('[data-test="merge-hint"]').text()).toBe('You need a second part of the same slot, tier and rarity: Exhaust, T2, Common.')
      expect(wrapper.find('[data-test="merge-partner-22"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="merge-confirm-24"]').exists()).toBe(false)

      await wrapper.get('[data-test="merge-close-24"]').trigger('click')

      expect(wrapper.find('[data-test="merge-24"]').exists()).toBe(false)
    })

    it('shows what the attempt does once a partner is chosen, and queues it when it is confirmed', async () => {
      const offer = aMergeOffer({ to: 'uncommon', chance_ppm: 460_000, pity_ppm: 60_000, cost_cents: 5_700, duration_ms: 470_400, scrap_cents: 14_250, with: [22] })
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, sheet([base({ merge: offer }), spare])),
        'POST /api/v1/garage/jobs': () => respond(201, withJobs([mergeJob()])),
        'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="merge-open-21"]').trigger('click')
      await wrapper.get('[data-test="merge-partner-22"]').trigger('click')

      expect(wrapper.findAll('[data-test="merge-target"] [data-test="rarity"]').map((mark) => mark.text())).toEqual(['Common', 'Uncommon'])
      expect(wrapper.get('[data-test="merge-target"]').text()).toContain('→')
      expect(wrapper.get('[data-test="merge-chance"]').text()).toBe('Chance: 46% (40% + 6 points of protection from bad luck)')
      expect(wrapper.get('[data-test="merge-cost"]').text()).toBe('An attempt costs $57.00 and takes 7 min 50 s.')
      expect(wrapper.get('[data-test="merge-keeps"]').text()).toBe('The model and the upgrade of Street tires T2 stay; Spare tires is used up.')
      expect(wrapper.get('[data-test="merge-failure"]').text()).toBe('If it fails, both parts are lost and you get $142.50 in scrap.')
      expect(calls.some((call) => call.method === 'POST')).toBe(false)

      await wrapper.get('[data-test="merge-confirm-21"]').trigger('click')
      await flushPromises()

      // The first part is the one that stays, and the second is the one that is used up.
      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'merge', part: 21, with: 22 })
      // The server moves the tutorial when it is asked, and a job in the queue is what a step waits for.
      expect(calls.some((call) => call.url === '/api/v1/tutorial')).toBe(true)
      expect(wrapper.get('[data-test="job-6"]').text()).toContain('Merging: Street tires T2 + Spare tires → Uncommon')
      expect(wrapper.get('[data-test="job-6-state"]').text()).toBe('Waiting')
      // The chooser closes, and the two parts that wait in the job cannot be put on or merged again.
      expect(wrapper.find('[data-test="merge-21"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="put-on-21"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="put-on-22"]').attributes('disabled')).toBeDefined()
    })

    it('says only the chance when the protection from bad luck has not built up yet', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, sheet([base({ merge: aMergeOffer({ chance_ppm: 810_000, pity_ppm: 0, with: [22] }) }), spare])),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="merge-open-21"]').trigger('click')
      await wrapper.get('[data-test="merge-partner-22"]').trigger('click')

      expect(wrapper.get('[data-test="merge-chance"]').text()).toBe('Chance: 81%')
      expect(calls.some((call) => call.method === 'POST')).toBe(false)
    })

    it('shows what the server says when it refuses a merge, and keeps the chooser open', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, sheet([base(), spare])),
        'POST /api/v1/garage/jobs': () => respond(422, { message: 'These parts do not go together.', code: 'merge_parts_differ' }),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="merge-open-21"]').trigger('click')
      await wrapper.get('[data-test="merge-partner-22"]').trigger('click')
      await wrapper.get('[data-test="merge-confirm-21"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('These parts do not go together.')
      expect(wrapper.find('[data-test="job-6"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="merge-21"]').exists()).toBe(true)
    })

    it('asks first about a merge that the mechanic is at, and says that its price is lost while the parts stay', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, withJobs([mergeJob({ status: 'working', start_ms: NOW - 60_000 })])),
        'DELETE /api/v1/garage/jobs/6': () => respond(200, { ...sheet([base(), spare]), cancelled: [6] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      // The car does not stand for a merge: the parts are not on it.
      expect(wrapper.find('[data-test="standing"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="job-6"]').text()).toContain('Merging: Street tires T2 + Spare tires → Uncommon')

      await wrapper.get('[data-test="cancel-6"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-6"]').text()).toContain('The work already done and the $57.00 paid for the attempt are lost, and both parts stay in the inventory.')
      expect(calls.some((call) => call.method === 'DELETE')).toBe(false)

      await wrapper.get('[data-test="cancel-confirm-6"]').trigger('click')
      await flushPromises()

      expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(1)
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled.')
    })

    it('cancels a merge that waits at once, and says that its price is back', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, withJobs([mergeJob()])),
        'DELETE /api/v1/garage/jobs/6': () => respond(200, { ...sheet([base(), spare]), cancelled: [6] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-6"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="cancel-sure-6"]').exists()).toBe(false)
      expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/garage/jobs/6')).toBe(true)
      expect(wrapper.find('[data-test="job-6"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled. $57.00 is back in your wallet.')
    })

    it('shows the last results: what a success made, and what a failure lost and gave back', async () => {
      serve({
        'GET /api/v1/garage': () =>
          respond(
            200,
            sheet([], {
              merges: [
                aMergeResult({ id: 9, success: false, to: 'rare', name: 'Rally suspension', slot: 'suspension', tier: 2, chance_ppm: 630_000, scrap_cents: 14_250 }),
                aMergeResult({ id: 8, success: true, to: 'uncommon', name: 'Street tires T2', slot: 'tires', tier: 2, chance_ppm: 810_000 }),
              ],
            }),
          ),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="merges"] h2').text()).toBe('Last merges')

      // The newest first, as the server sends them.
      expect(wrapper.findAll('[data-test^="merge-result-"]:not([data-test="merge-result-scrap"])').map((card) => card.attributes('data-test'))).toEqual(['merge-result-9', 'merge-result-8'])

      const failure = wrapper.get('[data-test="merge-result-9"]')
      expect(failure.attributes('data-success')).toBe('false')
      expect(failure.text()).toContain('Failed: Rally suspension')
      expect(failure.get('[data-test="rarity"]').text()).toBe('Rare')
      expect(failure.text()).toContain('Suspension · Chance: 63%')
      expect(failure.get('[data-test="merge-result-scrap"]').text()).toBe('Both parts are lost, scrap: $142.50.')

      const success = wrapper.get('[data-test="merge-result-8"]')
      expect(success.attributes('data-success')).toBe('true')
      expect(success.text()).toContain('Success: Street tires T2')
      expect(success.get('[data-test="rarity"]').text()).toBe('Uncommon')
      expect(success.find('[data-test="merge-result-scrap"]').exists()).toBe(false)
    })

    it('shows no card of results before there is one', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, sheet([base(), spare])) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.find('[data-test="merges"]').exists()).toBe(false)
    })

    it('speaks the language of the driver', async () => {
      await setLocale('pl')
      const offer = aMergeOffer({ chance_ppm: 460_000, pity_ppm: 60_000, with: [22] })
      serve({
        'GET /api/v1/garage': () =>
          respond(200, withJobs([mergeJob({ id: 7, part: base({ id: 25, name: 'Opony A', merge: null }), merge_with: { ...spare, id: 26, name: 'Opony B' } })], [base({ merge: offer }), spare])),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="merge-open-21"]').text()).toBe('Połącz')
      await wrapper.get('[data-test="merge-open-21"]').trigger('click')
      await wrapper.get('[data-test="merge-partner-22"]').trigger('click')

      // The money and the time are set with the spaces of the language, which do not break a line.
      const plain = (data: string) => wrapper.get(`[data-test="${data}"]`).text().replace(/ | /g, ' ')

      expect(plain('merge-chance')).toBe('Szansa: 46% (40% + 6 pp ochrony przed pechem)')
      expect(plain('merge-cost')).toBe('Próba kosztuje 57,00 $ i trwa 7 min 50 s.')
      expect(plain('merge-failure')).toBe('Przy porażce obie części przepadają, a Ty dostajesz 142,50 $ złomu.')
      expect(wrapper.get('[data-test="job-7"]').text()).toContain('Łączenie: Opony A + Opony B → Niezwykła')
    })
  })

  describe('the technical condition: mileage, service and repair', () => {
    const wornTires = (overrides: Parameters<typeof aMountedPart>[0] = {}) =>
      aMountedPart({ id: 21, slug: 'street-tires-t2', name: 'Street tires T2', slot: 'tires', tier: 2, stats: { grip: 92, handling: 90, braking: 92 }, condition_percent: 50, repair_next: aRepairStep(), ...overrides })
    const queueOf = (jobs: ReturnType<typeof aJob>[], car = aCar({ run: { id: 7, status: 'stopped', stop_reason: 'workshop' } }, { tires: wornTires() })) =>
      aGarage({ car, workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs } })
    const repairJob = (overrides: Parameters<typeof aJob>[0] = {}) => aJob({ id: 6, kind: 'repair', cost_cents: 14_250, part: wornTires(), finish_ms: NOW + 117_600, ...overrides })
    const serviceJob = (overrides: Parameters<typeof aJob>[0] = {}) => aJob({ id: 5, kind: 'service', cost_cents: 4_560, part: null, finish_ms: NOW + 882_000, ...overrides })

    it('shows the odometer, how far the service is with what it costs, and how often the car breaks down', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage()) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="mileage"]').text()).toBe('Mileage: 260,123 km')
      expect(wrapper.get('[data-test="service-line"]').text()).toBe('Service in 3,420 km')
      expect(wrapper.get('[data-test="service-line"]').classes()).not.toContain('text-danger')
      expect(wrapper.get('[data-test="service-info"]').text()).toBe('$45.60 · 14 min 42 s')
      // One breakdown in a thousand laps, and 48 laps of 75 s in an hour: about every 21 hours.
      expect(wrapper.get('[data-test="breakdown-line"]').text()).toBe('A breakdown about every 21 h on average')
      expect(wrapper.get('[data-test="service"]').text()).toBe('Service')
      expect(wrapper.get('[data-test="service"]').attributes('disabled')).toBeUndefined()
    })

    it('says in minutes how often a car breaks down that does it every few laps, and says nothing for one that never does', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ breakdown: { chance_ppm: 40_000 } }) })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      // 4% of a lap: 1.9 breakdowns an hour.
      expect(wrapper.get('[data-test="breakdown-line"]').text()).toBe('A breakdown about every 31 min on average')

      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ breakdown: { chance_ppm: 0 } }) })) })
      const never = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(never.wrapper.find('[data-test="breakdown-line"]').exists()).toBe(false)
    })

    it('says how late the service is, and what the lateness takes off the performance, in red', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ service: aService({ since_km: 5_800, due_in_km: -800, overdue_km: 800, penalty_percent: 4 }) }) })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="service-line"]').text()).toBe('Service overdue by 800 km: −4% performance')
      expect(wrapper.get('[data-test="service-line"]').classes()).toContain('text-danger')

      // Less than a step late: it is overdue, and costs nothing yet.
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({ service: aService({ since_km: 5_300, due_in_km: -300, overdue_km: 300, penalty_percent: 0 }) }) })) })
      const soft = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(soft.wrapper.get('[data-test="service-line"]').text()).toBe('Service overdue by 300 km')
    })

    it('queues a service with no part, shows the job, and does not offer a second one while it waits', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, aGarage()),
        'POST /api/v1/garage/jobs': () => respond(201, queueOf([serviceJob()], aCar({ run: { id: 7, status: 'stopped', stop_reason: 'workshop' } }))),
        'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      await wrapper.get('[data-test="service"]').trigger('click')
      await flushPromises()

      // The service is a job on the car: it has no part, and the request says so.
      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'service' })
      expect(wrapper.get('[data-test="job-5"]').text()).toContain('Servicing the car')
      expect(wrapper.get('[data-test="job-5-state"]').text()).toContain('In progress: 14 min 42 s left')
      expect(wrapper.get('[data-test="service"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="service-info"]').text()).toBe('A service is in the queue.')
      expect(wrapper.find('[data-test="standing"]').exists()).toBe(true)
    })

    it('shows what the server says when a service or a repair is refused', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: wornTires() }) })),
        'POST /api/v1/garage/jobs': () => respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="service"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="error"]').text()).toBe('Not enough cash.')
      expect(wrapper.find('[data-test="job-5"]').exists()).toBe(false)
    })

    it('shows the condition of a worn part on its tile and in the inventory, in red when it is unserviceable, and nothing for a part as good as new', async () => {
      const brakes = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', condition_percent: 12 })
      const exhaust = aMountedPart({ id: 11 })
      const spare = aPart({ id: 22, slug: 'street-tires-t2', name: 'Spare tires', slot: 'tires', tier: 2, condition_percent: 80, repair_next: aRepairStep() })
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: wornTires(), brakes, exhaust }), inventory: [spare, tires] })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="slot-tires"] [data-test="part-condition-value"]').text()).toBe('Condition: 50%')
      expect(wrapper.find('[data-test="slot-tires"] [data-test="part-unserviceable"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="slot-tires"] [role="progressbar"]').attributes('aria-valuenow')).toBe('50')
      expect(wrapper.get('[data-test="slot-tires"] .bar > i').attributes('style')).toContain('width: 50%')

      // Under a fifth of its life a part is unserviceable.
      expect(wrapper.get('[data-test="slot-brakes"] [data-test="part-unserviceable"]').text()).toBe('Unserviceable')
      expect(wrapper.get('[data-test="slot-brakes"] .bar').classes()).toContain('bar-bad')

      expect(wrapper.find('[data-test="slot-exhaust"] [data-test="part-condition"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="part-22"] [data-test="part-condition-value"]').text()).toBe('Condition: 80%')
      expect(wrapper.find('[data-test="part-21"] [data-test="part-condition"]').exists()).toBe(false)
    })

    it('offers the repair of a worn part on the car with its price and time, and queues it when asked', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { tires: wornTires() }) })),
        'POST /api/v1/garage/jobs': () => respond(201, queueOf([repairJob()])),
        'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="repair-21"]').text()).toBe('Repair')
      expect(wrapper.get('[data-test="repair-info-21"]').text()).toBe('$142.50 · 1 min 57 s')

      await wrapper.get('[data-test="repair-21"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'repair', part: 21 })
      expect(wrapper.get('[data-test="job-6"]').text()).toContain('Repairing: Street tires T2')
      // The part that is in the workshop cannot be repaired again, or taken off.
      expect(wrapper.get('[data-test="repair-21"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="take-off-21"]').attributes('disabled')).toBeDefined()
    })

    it('offers the repair of a worn part of the inventory too, and nothing for a part that is not worn', async () => {
      const spare = aPart({ id: 22, slug: 'street-tires-t2', name: 'Spare tires', slot: 'tires', tier: 2, condition_percent: 80, repair_next: aRepairStep({ cost_cents: 5_700, duration_ms: 47_040 }) })
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ inventory: [spare, { ...tires, repair_next: NOTHING_TO_REPAIR }] })),
        'POST /api/v1/garage/jobs': () => respond(201, aGarage({ inventory: [spare, tires] })),
        'GET /api/v1/tutorial': () => respond(200, aTutorial(null)),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="repair-info-22"]').text()).toBe('$57.00 · 0 min 47 s')
      expect(wrapper.find('[data-test="repair-21"]').exists()).toBe(false)

      await wrapper.get('[data-test="repair-22"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ kind: 'repair', part: 22 })
    })

    it('says that a part that is worn out has to be replaced, and offers no repair for it', async () => {
      const gone = { cost_cents: null, duration_ms: null, locked: { reason: 'worn_out' as const } }
      const brakes = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', condition_percent: 0, repair_next: gone })
      const spare = aPart({ id: 22, slug: 'street-tires-t2', name: 'Spare tires', slot: 'tires', tier: 2, condition_percent: 0, repair_next: gone })
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { brakes }), inventory: [spare] })) })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="repair-info-12"]').text()).toBe('Worn out: it has to be replaced')
      expect(wrapper.find('[data-test="repair-12"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="repair-info-22"]').text()).toBe('Worn out: it has to be replaced')
      expect(wrapper.find('[data-test="repair-22"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="slot-brakes"] [data-test="part-unserviceable"]').exists()).toBe(true)
    })

    it('asks first about a repair that the mechanic is at, and says that its price is lost with the work', async () => {
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([repairJob()])),
        'DELETE /api/v1/garage/jobs/6': () => respond(200, { ...queueOf([]), cancelled: [6] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-6"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-6"]').text()).toContain('The work already done and the $142.50 paid for the repair are lost, and the part stays as it was.')
      expect(calls.some((call) => call.method === 'DELETE')).toBe(false)

      await wrapper.get('[data-test="cancel-confirm-6"]').trigger('click')
      await flushPromises()

      expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(1)
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled.')
    })

    it('asks first about a service that the mechanic is at, which has no part, and says that its price is lost', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, queueOf([serviceJob()], aCar({ run: { id: 7, status: 'stopped', stop_reason: 'workshop' } }))),
        'DELETE /api/v1/garage/jobs/5': () => respond(200, { ...queueOf([], aCar()), cancelled: [5] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="job-5"]').text()).toContain('Servicing the car')

      await wrapper.get('[data-test="cancel-5"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-5"]').text()).toContain('The work already done and the $45.60 paid for the service are lost.')

      await wrapper.get('[data-test="cancel-confirm-5"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled.')
      expect(wrapper.find('[data-test="job-5"]').exists()).toBe(false)
    })

    it('cancels a service and a repair that wait at once, and says that the price is back', async () => {
      const working = repairJob({ id: 3, cost_cents: 9_000, part: aMountedPart({ id: 12, name: 'Disc brakes', slot: 'brakes', condition_percent: 40 }), finish_ms: NOW + 100_000 })
      const waiting = serviceJob({ status: 'queued', start_ms: NOW + 100_000, finish_ms: NOW + 982_000 })
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, queueOf([working, waiting])),
        'DELETE /api/v1/garage/jobs/5': () => respond(200, { ...queueOf([working]), cancelled: [5] }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="job-5"]').text()).toContain('Servicing the car')

      await wrapper.get('[data-test="cancel-5"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="cancel-sure-5"]').exists()).toBe(false)
      expect(calls.some((call) => call.method === 'DELETE' && call.url === '/api/v1/garage/jobs/5')).toBe(true)
      expect(wrapper.get('[data-test="cancelled"]').text()).toBe('Job cancelled. $45.60 is back in your wallet.')
    })

    it('speaks the language of the driver', async () => {
      await setLocale('pl')
      const brakes = aMountedPart({ id: 12, slug: 'disc-brakes-t2', name: 'Disc brakes', slot: 'brakes', condition_percent: 0, repair_next: { cost_cents: null, duration_ms: null, locked: { reason: 'worn_out' } } })
      serve({
        'GET /api/v1/garage': () =>
          respond(200, queueOf([serviceJob({ status: 'queued' })], aCar({ service: aService({ since_km: 5_800, due_in_km: -800, overdue_km: 800, penalty_percent: 4 }) }, { tires: wornTires(), brakes }))),
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      // The thousands are separated with a space that does not break a line.
      expect(wrapper.get('[data-test="mileage"]').text().replace(/[  ]/g, ' ')).toBe('Przebieg: 260 123 km')
      expect(wrapper.get('[data-test="service-line"]').text()).toBe('Serwis spóźniony o 800 km: −4% osiągów')
      expect(wrapper.get('[data-test="service"]').text()).toBe('Serwis')
      expect(wrapper.get('[data-test="service-info"]').text()).toBe('Serwis czeka w kolejce.')
      expect(wrapper.get('[data-test="breakdown-line"]').text()).toBe('Awaria średnio co ok. 21 h')
      expect(wrapper.get('[data-test="repair-21"]').text()).toBe('Napraw')
      expect(wrapper.get('[data-test="slot-tires"] [data-test="part-condition-value"]').text()).toBe('Stan: 50%')
      expect(wrapper.get('[data-test="repair-info-12"]').text()).toBe('Zużyta do końca: trzeba wymienić')
      expect(wrapper.get('[data-test="slot-brakes"] [data-test="part-unserviceable"]').text()).toBe('Niesprawna')
      expect(wrapper.get('[data-test="job-5"]').text()).toContain('Serwis auta')
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

  describe('the equipment of the workshop', () => {
    const withGear = (gear = aGear(), jobs: ReturnType<typeof aJob>[] = []) =>
      aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: gear.queue_max, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear, jobs } })
    const cashOf = (cents: number) => () => useRunStore().$patch({ wallet: { cash_cents: cents, xp: 0 } })

    it('is folded away, with what the workshop can do at once, until the driver opens it', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, withGear(aGear({}, 2))), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="gear-summary"]').text()).toBe('Working at once: 2 · queue of 6 jobs')
      expect(wrapper.find('[data-test="gear-body"]').exists()).toBe(false)

      await wrapper.get('[data-test="gear-toggle"]').trigger('click')

      expect(wrapper.find('[data-test="gear-body"]').exists()).toBe(true)
      expect(wrapper.get('[data-test="gear-toggle"]').attributes('aria-expanded')).toBe('true')
    })

    it('shows every item at its level with what it does, what it works on, the fee, and the next level with its price', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, withGear(aGear({ lift: 1 }))), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop', { prepare: cashOf(1_000_000) })
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')

      expect(wrapper.get('[data-test="gear-tire_machine-level"]').text()).toBe('No machine (a vulcanizer outside)')
      expect(wrapper.get('[data-test="gear-tire_machine-effect"]').text()).toBe('Usual time of jobs')
      expect(wrapper.get('[data-test="gear-tire_machine"]').text()).toContain('Works on: Tires')
      expect(wrapper.get('[data-test="gear-tire_machine-fee"]').text()).toBe('Without a machine a vulcanizer outside asks $30.00 for every job on tires')
      expect(wrapper.get('[data-test="gear-tire_machine-next"]').text()).toBe('Next: Manual tire machine · time ×0.50')
      expect(wrapper.get('[data-test="buy-tire_machine"]').text()).toBe('Buy · $400.00')

      // The lift is at its first level: the jobs on the suspension, the brakes and the exhaust take 0.68 of the time.
      expect(wrapper.get('[data-test="gear-lift-level"]').text()).toBe('Hand hydraulic lift')
      expect(wrapper.get('[data-test="gear-lift-effect"]').text()).toBe('Time of jobs ×0.68')
      expect(wrapper.get('[data-test="gear-lift"]').text()).toContain('Works on: Suspension, Brakes, Exhaust')
      expect(wrapper.find('[data-test="gear-lift-fee"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="gear-lift"] .pips').attributes('aria-label')).toBe('Level 1 of 3')
      expect(wrapper.findAll('[data-test="gear-lift"] .pips b.on')).toHaveLength(1)
      expect(wrapper.get('[data-test="buy-lift"]').text()).toBe('Buy · $1,200.00')

      // The toolkit works on every job.
      expect(wrapper.get('[data-test="gear-toolkit"]').text()).toContain('Works on every job, merging and servicing too')
      expect(wrapper.get('[data-test="buy-toolkit"]').text()).toBe('Buy · $600.00')
    })

    it('shows the bays and the mechanics, and says what a mechanic or a bay alone is worth', async () => {
      const gear = { ...aGear(), mechanics: { count: 2, max: 4, next_price_cents: 500_000 } }
      serve({ 'GET /api/v1/garage': () => respond(200, withGear(gear)), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop', { prepare: cashOf(10_000_000) })
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')

      expect(wrapper.get('[data-test="gear-bays-count"]').text()).toBe('1 of 4 · cars worked on at once')
      expect(wrapper.get('[data-test="buy-bays"]').text()).toBe('Buy · $2,000.00')
      expect(wrapper.get('[data-test="gear-mechanics-count"]').text()).toBe('2 of 4 · each needs a bay to work')
      expect(wrapper.get('[data-test="buy-mechanics"]').text()).toBe('Buy · $5,000.00')
      expect(wrapper.get('[data-test="gear-hint"]').text()).toBe('A mechanic without a bay does not work: buy a bay.')
    })

    it('buys the next level, says what was bought, and shows what the server sent back', async () => {
      let bought = false
      const calls = serve({
        'GET /api/v1/garage': () => respond(200, withGear(aGear({ tire_machine: bought ? 1 : 0 }))),
        'POST /api/v1/garage/equipment': () => {
          bought = true

          return respond(201, { ...withGear(aGear({ tire_machine: 1 })), repeated: false })
        },
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop', { prepare: cashOf(100_000) })
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')
      await wrapper.get('[data-test="buy-tire_machine"]').trigger('click')
      await flushPromises()

      expect(calls.find((call) => call.method === 'POST')?.body).toEqual({ item: 'tire_machine', request_id: expect.any(String) })
      expect(wrapper.get('[data-test="bought"]').text()).toBe('Bought: Manual tire machine.')
      expect(wrapper.get('[data-test="gear-tire_machine-level"]').text()).toBe('Manual tire machine')
      expect(wrapper.find('[data-test="gear-tire_machine-fee"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="gear-tire_machine-next"]').text()).toBe('Next: Automatic machine with a balancer · time ×0.35')
    })

    it('disables a purchase the cash does not cover, and says what it needs: the cash comes with the answer of the garage', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, { ...withGear(), wallet: { cash_cents: 39_999, xp: 0 } }), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')

      expect(wrapper.get('[data-test="buy-tire_machine"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="gear-tire_machine-short"]').text()).toBe('Not enough cash: needs $400.00')
      // What the cash covers is not held back: the first level of the lift is $350, and the toolkit's is $600.
      expect(wrapper.get('[data-test="buy-lift"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.get('[data-test="buy-toolkit"]').attributes('disabled')).toBeDefined()
    })

    it('counts the larger of the cash of the run and the cash of the garage, so that an old figure never shuts a purchase the driver can make', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, { ...withGear(), wallet: { cash_cents: 39_999, xp: 0 } }), 'GET /api/v1/runs/current': noRun })

      // The car has been earning since the garage was asked: the wallet of the run is the live one.
      const { wrapper } = await mountView(WorkshopView, '/workshop', { prepare: cashOf(500_000) })
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')

      expect(wrapper.get('[data-test="buy-tire_machine"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.find('[data-test="gear-tire_machine-short"]').exists()).toBe(false)
    })

    it('shows the answer of the server in the card when a purchase is refused', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, withGear()),
        'POST /api/v1/garage/equipment': () => respond(422, { message: 'Not enough cash.', code: 'insufficient_funds' }),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop', { prepare: cashOf(10_000_000) })
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')
      await wrapper.get('[data-test="buy-bays"]').trigger('click')
      await flushPromises()

      expect(wrapper.get('[data-test="gear-error"]').text()).toBe('Not enough cash.')
      expect(wrapper.find('[data-test="bought"]').exists()).toBe(false)
    })

    it('says an item is at its highest level instead of offering a purchase', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, withGear(aGear({ hoist: 2 }))), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop', { prepare: cashOf(10_000_000) })
      await flushPromises()
      await wrapper.get('[data-test="gear-toggle"]').trigger('click')

      expect(wrapper.get('[data-test="gear-hoist-max"]').text()).toBe('Highest level')
      expect(wrapper.find('[data-test="buy-hoist"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="gear-hoist-effect"]').text()).toBe('Time of jobs ×0.27')
    })
  })

  describe('several mechanics at work', () => {
    it('lists every job a mechanic is at, each with its own bar and its own cancel, and says how many work', async () => {
      const first = aJob({ id: 1, finish_ms: NOW + 65_000 })
      const second = aJob({ id: 2, kind: 'merge', part: tires, merge_with: tires, merge_to: 'uncommon', start_ms: NOW - 30_000, finish_ms: NOW + 400_000 })
      const queued = aJob({ id: 3, status: 'queued', part: tires, start_ms: NOW + 65_000, finish_ms: NOW + 300_200 })
      serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 6, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear({}, 2), jobs: [first, second, queued] } })),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="at-work"]').text()).toBe('Working now: 2 of 2')
      expect(wrapper.findAll('[data-test="job-bar"]')).toHaveLength(2)
      expect(wrapper.find('[data-test="working-1"] [data-test="cancel-1"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="working-2"] [data-test="cancel-2"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="mechanic-idle"]').exists()).toBe(false)
      // The one that waits is in the queue, not in the card of the mechanics.
      expect(wrapper.find('[data-test="working-3"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="queue"]').text()).toContain('Queue 3 of 6')
    })

    it('says nothing about how many work when there is one mechanic, and that he is free when no job is at work', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage()), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.find('[data-test="at-work"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="mechanic-idle"]').text()).toBe('The mechanic is free.')
    })

    it('says the fee of the vulcanizer is lost with a mounting of tires that is cancelled while it is done', async () => {
      const job = aJob({ id: 4, cost_cents: 3_000, part: tires })
      serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ workshop: { city: { slug: 'zlomowo', name: 'Złomowo' }, queue_max: 4, mechanic: { level: 1, time_cut_ppm: 20_000 }, gear: aGear(), jobs: [job] } })),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()
      await wrapper.get('[data-test="cancel-4"]').trigger('click')

      expect(wrapper.get('[data-test="cancel-sure-4"]').text()).toContain('$30.00 paid at the vulcanizer are lost')
    })
  })

  describe('what putting on and taking off cost and take', () => {
    it('shows the fee of the vulcanizer and the time at the button of a part that goes on, and only the time where there is no fee', async () => {
      const exhaustStock = aPart({ id: 22, slug: 'sport-exhaust-t2', name: 'Sport exhaust', slot: 'exhaust', tier: 2 })
      serve({
        'GET /api/v1/garage': () =>
          respond(200, aGarage({ car: aCar(), inventory: [{ ...tires, mount_next: { cost_cents: 3_000, duration_ms: 235_200 } }, { ...exhaustStock, mount_next: { cost_cents: 0, duration_ms: 235_200 } }] })),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="put-on-info-21"]').text()).toBe('Vulcanizer $30.00 · 3 min 55 s')
      expect(wrapper.get('[data-test="put-on-info-22"]').text()).toBe('Time: 3 min 55 s')
    })

    it('shows the same at the button of a part that comes off', async () => {
      serve({
        'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust: aMountedPart({ ...exhaust, unmount_next: { cost_cents: 3_000, duration_ms: 70_560 } }) }) })),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="take-off-info-11"]').text()).toBe('Vulcanizer $30.00 · 1 min 10 s')
    })

    it('shuts the button of a part the car cannot take and says why where the price was, and keeps the button of one it can', async () => {
      const grande = aPart({ id: 31, slug: 'turbo-grande-t6', name: 'Turbo Grande', slot: 'boost', tier: 6, stats: { power_mult: 1.4, lag: 6 }, requires: { min_cylinders: 6 } })
      const intercooler = aPart({ id: 32, slug: 'intercooler-factory-t2', name: 'Factory intercooler', slot: 'cooling', tier: 2, stats: { boost_gain: 0.01 }, requires: { booster: true } })
      const roots = aPart({ id: 33, slug: 'roots-mini-t2', name: 'Roots mini', slot: 'boost', tier: 2, stats: { power_mult: 1.12 } })
      serve({
        'GET /api/v1/garage': () =>
          respond(
            200,
            aGarage({
              car: aCar(),
              inventory: [
                { ...grande, mount_next: { cost_cents: 0, duration_ms: 480_000, locked: { reason: 'cylinders', required: 6, current: 4 } } },
                { ...intercooler, mount_next: { cost_cents: 0, duration_ms: 235_200, locked: { reason: 'booster' } } },
                { ...roots, mount_next: { cost_cents: 0, duration_ms: 235_200, locked: null } },
              ],
            }),
          ),
        'GET /api/v1/runs/current': noRun,
      })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.get('[data-test="put-on-31"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="put-on-lock-31"]').text()).toBe('This car has 4 cylinders, the part needs 6')
      expect(wrapper.find('[data-test="put-on-info-31"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="put-on-32"]').attributes('disabled')).toBeDefined()
      expect(wrapper.get('[data-test="put-on-lock-32"]').text()).toBe('Put a booster on the car first')
      expect(wrapper.get('[data-test="put-on-33"]').attributes('disabled')).toBeUndefined()
      expect(wrapper.find('[data-test="put-on-lock-33"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="put-on-info-33"]').text()).toBe('Time: 3 min 55 s')
      expect(wrapper.get('[data-test="part-31"] [data-test="part-requires"]').text()).toBe('Needs an engine with at least 6 cylinders')
    })

    it('says nothing when the server gave no quote', async () => {
      serve({ 'GET /api/v1/garage': () => respond(200, aGarage({ car: aCar({}, { exhaust }), inventory: [tires] })), 'GET /api/v1/runs/current': noRun })

      const { wrapper } = await mountView(WorkshopView, '/workshop')
      await flushPromises()

      expect(wrapper.find('[data-test="put-on-info-21"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="take-off-info-11"]').exists()).toBe(false)
    })
  })
})
