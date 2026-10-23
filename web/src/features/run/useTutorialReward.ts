import { watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useFlash } from '@/features/run/useFlash'
import { useFormat } from '@/i18n/useFormat'
import { useTutorialStore } from '@/stores/tutorial'

/**
 * What the mentor paid for a step is said once, for a while, and then it is gone: the cash is in the HUD. `onCarXp` runs when the experience
 * went to the car, which a screen that shows the bar of the car has to ask for again, as the run has not moved.
 */
export function useTutorialReward(onCarXp: () => void = () => undefined) {
  const { t } = useI18n()
  const { money, number } = useFormat()
  const tutorial = useTutorialStore()
  const { message: rewardMessage, show } = useFlash()

  watch(
    () => tutorial.reward,
    (paid) => {
      if (paid !== null) {
        show(
          [paid.cashCents > 0 ? t('tutorial.reward', { cash: money(paid.cashCents, 2) }) : null, paid.carXp > 0 ? t('tutorial.rewardCarXp', { xp: number(paid.carXp) }) : null]
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
