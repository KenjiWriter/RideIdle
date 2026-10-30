import { useI18n } from 'vue-i18n'

import type { Mission, MissionItem, MissionReward } from '@/api/types'
import { useFormat } from '@/i18n/useFormat'

/** The goals that are an amount of money, counted in cents. */
const MONEY_OBJECTIVES = ['cash_earned', 'fuel_spent']

/** The words of a mission: what it asks, how far the driver is, and what it pays. All of them follow the language of the interface. */
export function useMissionText() {
  const { t, te } = useI18n()
  const { money, number } = useFormat()

  function isMoney(mission: Mission): boolean {
    return MONEY_OBJECTIVES.includes(mission.objective)
  }

  /** What the mission asks: its own title for a main mission, else the goal and the number ("Drive 24 laps"). */
  function title(mission: Mission): string {
    if (mission.title) {
      return mission.title
    }

    const key = `missions.objective.${mission.objective}`

    if (!te(key)) {
      return mission.objective
    }

    return isMoney(mission) ? t(key, { amount: money(mission.target) }) : t(key, { n: number(mission.target) }, mission.target)
  }

  /** The sentence that says more of a main mission (nothing for a mission of a set). */
  function text(mission: Mission): string | null {
    return mission.text ? mission.text : null
  }

  /** "12 / 24", in dollars for money. */
  function progress(mission: Mission): string {
    return isMoney(mission) ? `${money(mission.progress)} / ${money(mission.target)}` : `${number(mission.progress)} / ${number(mission.target)}`
  }

  /** How far the driver is, in percent of the goal. */
  function percent(mission: Mission): number {
    return mission.target <= 0 ? 0 : Math.min(100, Math.floor((mission.progress / mission.target) * 100))
  }

  function item(entry: MissionItem): string {
    return entry.type === 'crate' ? t(`missions.item.crate.${entry.kind}`) : t('missions.item.part', { rarity: t(`rarity.${entry.rarity}`) })
  }

  /** What a mission pays, as short lines for chips: cash, XP, XP of the car and the items. */
  function rewards(reward: MissionReward): string[] {
    return [
      reward.cash_cents > 0 ? money(reward.cash_cents) : null,
      reward.xp > 0 ? t('missions.reward.xp', { xp: number(reward.xp) }) : null,
      reward.car_xp > 0 ? t('missions.reward.carXp', { xp: number(reward.car_xp) }) : null,
      ...reward.items.map(item),
    ].filter((line): line is string => line !== null)
  }

  return { title, text, progress, percent, rewards, item }
}
