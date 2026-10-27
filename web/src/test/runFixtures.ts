import type { Run } from '@/api/types'

/** A segment of the weather lasts 22.5 minutes, as on the server. */
const SEGMENT_MS = 1_350_000

/** The parts of a run that tell what is happening on the track now: dry weather and a lap of 75 s that has just started. */
export function liveFields(nowMs: number): Pick<Run, 'weather' | 'current_lap' | 'clock' | 'travel' | 'field'> {
  const segment = Math.floor(nowMs / SEGMENT_MS)

  return {
    travel: null,
    field: null,
    clock: {
      day_ms: 10_800_000,
      phases: [
        { key: 'dawn', from_hour: 5 },
        { key: 'day', from_hour: 8 },
        { key: 'dusk', from_hour: 18 },
        { key: 'night', from_hour: 22 },
      ],
    },
    weather: {
      now: 'dry',
      ends_ms: (segment + 1) * SEGMENT_MS,
      next: 'dry',
      forecast: [0, 1, 2, 3, 4].map((ahead) => ({ weather: 'dry', starts_ms: (segment + ahead) * SEGMENT_MS, ends_ms: (segment + ahead + 1) * SEGMENT_MS, surface: 'asphalt_dry' as const })),
    },
    current_lap: { start_ms: nowMs, finish_ms: nowMs + 75_000, ms: 75_000, weather: 'dry' },
  }
}
