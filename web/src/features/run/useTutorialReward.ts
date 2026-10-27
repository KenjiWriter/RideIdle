import { watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useFlash } from '@/features/run/useFlash'
import { useFormat } from '@/i18n/useFormat'
import { useMapStore } from '@/stores/map'
import { useTutorialStore } from '@/stores/tutorial'

/**
 * What the mentor paid for a step is said once, for a while, and then it is gone: the cash and the experience are in the HUD. `onCarXp` runs when the experience
 * went to the car, which a screen that shows the bar of the car has to ask for again, as the run has not moved.
 */
export function useTutorialReward(onCarXp: () => void = () => undefined) {
  const { t } = useI18n()
  const { money, number } = useFormat()
  const tutorial = useTutorialStore()
  const map = useMapStore()
  const { message: rewardMessage, show } = useFlash()

  /** The name of a city by its slug: the map knows it when it has been asked for, and the slug will do when it has not. */
  function cityName(slug: string): string {
    return map.map?.cities.find((city) => city.slug === slug)?.name ?? slug.charAt(0).toUpperCase() + slug.slice(1)
  }

  watch(
    () => tutorial.reward,
    (paid) => {
      if (paid !== null) {
        show(
          [
            paid.cashCents > 0 ? t('tutorial.reward', { cash: money(paid.cashCents, 2) }) : null,
            paid.carXp > 0 ? t('tutorial.rewardCarXp', { xp: number(paid.carXp) }) : null,
            paid.xp > 0 ? t('tutorial.rewardXp', { xp: number(paid.xp) }) : null,
            paid.parts.length > 0 ? t('tutorial.rewardPart') : null,
            ...paid.garages.map((slug) => t('tutorial.rewardGarage', { city: cityName(slug) })),
          ]
            .filter(Boolean)
            .join(' '),
        )
        tutorial.acknowledgeReward()

        if (paid.carXp > 0) {
          onCarXp()
        }
      }
    },
  )

  return { rewardMessage }
}
