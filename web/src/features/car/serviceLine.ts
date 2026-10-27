import { useI18n } from 'vue-i18n'

import type { GarageCar, ServiceState } from '@/api/types'
import { useFormat } from '@/i18n/useFormat'

/** How long the car drives on average between two breakdowns, in words: the chance after a lap, the laps of an hour and no more. */
function everyText(hours: number): string {
  return hours < 1 ? `${Math.max(1, Math.round(hours * 60))} min` : `${Math.round(hours)} h`
}

/** What the garage says about the upkeep of a car: how far its service is (or how late), and how often it breaks down. */
export function useServiceLine() {
  const { t } = useI18n()
  const { number } = useFormat()

  /** "Service in 3 420 km", or how late it is, and what the lateness takes off the performance. */
  function serviceLine(service: ServiceState): string {
    if (service.due_in_km >= 0) {
      return t('workshop.serviceIn', { km: number(service.due_in_km) })
    }

    return service.penalty_percent > 0
      ? t('workshop.serviceLatePenalty', { km: number(service.overdue_km), percent: service.penalty_percent })
      : t('workshop.serviceLate', { km: number(service.overdue_km) })
  }

  /** "A breakdown about every 21 h", or null when the car does not break down or the lap is not known. */
  function breakdownLine(car: Pick<GarageCar, 'breakdown' | 'lap_ms'>): string | null {
    const lapMs = car.lap_ms?.dry ?? Object.values(car.lap_ms ?? {})[0]

    if (!lapMs || car.breakdown.chance_ppm <= 0) {
      return null
    }

    const perHour = (car.breakdown.chance_ppm / 1_000_000) * (3_600_000 / lapMs)

    return t('workshop.breakdownEvery', { time: everyText(1 / perHour) })
  }

  return { serviceLine, breakdownLine }
}
