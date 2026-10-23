/** The numbers the texts hold, from the server: the minimum age and the days a guest account is kept. */
export interface LegalNumbers {
  minAge: number
  guestDays: number
}

const PLACEHOLDER = /\{([a-zA-Z]+)\}/g

/** The names a text may use. */
export const LEGAL_PLACEHOLDERS: ReadonlySet<string> = new Set<keyof LegalNumbers>(['minAge', 'guestDays'])

/**
 * Puts the numbers into a paragraph. It is not vue-i18n: these are long documents, and a message compiler reads
 * characters such as @ and | as syntax, which a legal text should be free to contain. A name that is not known is
 * left as it is, so a typo shows on the page and in the test instead of vanishing.
 */
export function fillLegal(text: string, numbers: LegalNumbers): string {
  return text.replace(PLACEHOLDER, (whole, name: string) => (Object.hasOwn(numbers, name) ? String(numbers[name as keyof LegalNumbers]) : whole))
}

/** The placeholders a text uses, each once, in the order they appear. */
export function placeholdersOf(text: string): string[] {
  return [...new Set([...text.matchAll(PLACEHOLDER)].map((match) => match[1] ?? ''))]
}
