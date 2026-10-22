const STORAGE_KEY = 'ride.run.seen'

/** The last state of a run that the driver has actually seen. */
export interface SeenRun {
  runId: number
  version: number
}

/**
 * Remembers which version of the run the driver saw last, so the next visit can ask the server for everything
 * since then (since_version) and show what happened while they were away. Every access is guarded because private
 * mode or blocked site data can make storage throw; without it the summary of an absence is just not shown.
 */
export function readSeen(): SeenRun | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    const seen = raw ? (JSON.parse(raw) as Partial<SeenRun>) : null

    return seen && Number.isInteger(seen.runId) && Number.isInteger(seen.version) && (seen.version as number) >= 0
      ? (seen as SeenRun)
      : null
  } catch {
    return null
  }
}

export function storeSeen(seen: SeenRun): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(seen))
  } catch {
    // The summary of the next absence cannot be shown, nothing else is lost.
  }
}

export function clearSeen(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to clear.
  }
}
