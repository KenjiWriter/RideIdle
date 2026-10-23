import type { PluralizationRule } from 'vue-i18n'

/** Zero is singular: 0 tour, 0 volta. */
const zeroIsSingular: PluralizationRule = (choice, choicesLength) => {
  if (choicesLength < 2) {
    return 0
  }

  return choice === 0 || choice === 1 ? 0 : 1
}

/**
 * Which of the forms of a message "one | few | many" fits a number. vue-i18n knows only the English rule
 * (one and the rest), so the languages that count differently say so here.
 *
 * Polish has three forms: 1 okrążenie, 2–4 okrążenia (but not 12–14, and 22–24 count again), and okrążeń for the rest.
 * French and Brazilian Portuguese call 0 singular too.
 */
export const pluralRules: Record<string, PluralizationRule> = {
  pl: (choice, choicesLength) => {
    if (choicesLength < 3) {
      return choice === 1 ? 0 : 1
    }

    if (choice === 1) {
      return 0
    }

    const lastDigit = choice % 10
    const lastTwoDigits = choice % 100

    return lastDigit >= 2 && lastDigit <= 4 && (lastTwoDigits < 12 || lastTwoDigits > 14) ? 1 : 2
  },

  fr: zeroIsSingular,

  'pt-BR': zeroIsSingular,
}
