import { defineStore } from 'pinia'
import { ref } from 'vue'

import { ApiError, api } from '@/api/client'
import type { RefuelResult, Run, RunResponse, RunSummary, StarterCar, StarterCarsResponse, Wallet } from '@/api/types'
import { newRequestId } from '@/features/run/requestId'
import { clearSeen, readSeen, storeSeen } from '@/features/run/seen'

/** An absence shorter than this is not worth a summary: the driver was only reloading the page. */
export const AWAY_MIN_MS = 2 * 60 * 1000

/** How long a request id is reused for the same action. Later, pressing the button again is a new intention. */
export const REQUEST_ID_LIFETIME_MS = 60 * 1000

/** The run of the driver. The server settles it whenever it is asked, so the store only has to ask. */
export const useRunStore = defineStore('run', () => {
  const run = ref<Run | null>(null)
  const wallet = ref<Wallet | null>(null)
  /** The server was asked at least once. Until then `run` being null does not mean the driver has none. */
  const loaded = ref(false)
  /** What happened while the driver was away. It stays until the driver dismisses it, even over a reload. */
  const away = ref<RunSummary | null>(null)

  const starters = ref<StarterCar[]>([])
  const startTrack = ref<{ slug: string; name: string } | null>(null)

  function apply(response: RunResponse): void {
    run.value = response.data
    wallet.value = response.wallet
    loaded.value = true
  }

  function remember(current: Run): void {
    storeSeen({ runId: current.id, version: current.state_version })
  }

  /** The request id of the intention that is on its way: the same one for the same action, until it is answered. */
  let pending: { key: string; id: string; at: number } | null = null

  function pendingRequest(key: string): string {
    if (pending === null || pending.key !== key || Date.now() - pending.at > REQUEST_ID_LIFETIME_MS) {
      pending = { key, id: newRequestId(), at: Date.now() }
    }

    return pending.id
  }

  function noRun(error: unknown): boolean {
    return error instanceof ApiError && error.status === 404 && error.code === 'no_run'
  }

  /** The wrecks to choose from and the track a run starts on. */
  async function loadStarters(): Promise<void> {
    const response = await api<StarterCarsResponse>('/v1/starter-cars')

    starters.value = response.data
    startTrack.value = response.track
  }

  /** Starts the run with the chosen wreck. A driver who already has a run gets a 409 (code run_exists). */
  async function start(carSlug: string): Promise<void> {
    if (!startTrack.value) {
      throw new Error('The starting track is not known: load the starter cars first.')
    }

    const response = await api<RunResponse>('/v1/runs', {
      method: 'POST',
      body: JSON.stringify({ car: carSlug, track: startTrack.value.slug }),
    })

    apply(response)
    away.value = null
    remember(response.data)
  }

  /**
   * Opens the run screen: asks for the run with everything that happened since the version the driver saw last.
   * A long absence becomes `away`, which waits to be dismissed; otherwise what is on screen now counts as seen.
   */
  async function load(): Promise<void> {
    const seen = readSeen()

    try {
      const response = await api<RunResponse>(`/v1/runs/current${seen ? `?since_version=${seen.version}` : ''}`)

      apply(response)

      const summary = response.summary
      const absent =
        seen?.runId === response.data.id &&
        summary !== undefined &&
        summary.laps > 0 &&
        summary.from_ms !== null &&
        response.data.server_time_ms - summary.from_ms >= AWAY_MIN_MS

      if (absent) {
        away.value = summary
      } else {
        away.value = null
        remember(response.data)
      }
    } catch (error) {
      if (noRun(error)) {
        run.value = null
        wallet.value = null
        loaded.value = true

        return
      }

      throw error
    }
  }

  /** Brings the run up to date while the screen is open. The pending summary of an absence is left alone. */
  async function refresh(): Promise<void> {
    try {
      apply(await api<RunResponse>('/v1/runs/current'))

      if (away.value === null && run.value) {
        remember(run.value)
      }
    } catch (error) {
      if (!noRun(error)) {
        throw error
      }
    }
  }

  /**
   * Buys fuel: `liters` of it, or a full tank when null. Every intention gets a request id that stays the same
   * while the answer is missing, so pressing the button again after a lost answer cannot charge twice. After a
   * minute the old id is dropped, because then the driver means a new refuel.
   */
  async function refuel(liters: number | null): Promise<RefuelResult> {
    const current = run.value

    if (!current) {
      throw new Error('There is no run to refuel.')
    }

    const key = `refuel:${current.id}:${liters ?? 'full'}`
    const request = pendingRequest(key)

    const response = await api<RunResponse>(`/v1/runs/${current.id}/refuel`, {
      method: 'POST',
      body: JSON.stringify({ request_id: request, ...(liters !== null ? { liters } : {}) }),
    })

    pending = null
    apply(response)

    if (away.value === null) {
      remember(response.data)
    }

    return response.refuel as RefuelResult
  }

  /** The driver has read the summary: from now on everything up to the present counts as seen. */
  function dismissAway(): void {
    away.value = null

    if (run.value) {
      remember(run.value)
    }
  }

  /** Forgets everything, for example when the driver signs out. */
  function reset(): void {
    run.value = null
    wallet.value = null
    loaded.value = false
    away.value = null
    starters.value = []
    startTrack.value = null
    pending = null
    clearSeen()
  }

  return { run, wallet, loaded, away, starters, startTrack, loadStarters, start, load, refresh, refuel, dismissAway, reset }
})
