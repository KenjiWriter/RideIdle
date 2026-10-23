import type { Run } from '@/api/types'

/** A segment of the weather lasts 22.5 minutes, as on the server. */
const SEGMENT_MS = 1_350_000

/** The parts of a run that tell what is happening on the track now: dry weather and a lap of 75 s that has just started. */
export function liveFields(nowMs: number): Pick<Run, 'weather' | 'current_lap'> {
  return {
    weather: { now: 'dry', ends_ms: (Math.floor(nowMs / SEGMENT_MS) + 1) * SEGMENT_MS, next: 'dry' },
    current_lap: { start_ms: nowMs, finish_ms: nowMs + 75_000, ms: 75_000, weather: 'dry' },
  }
}
