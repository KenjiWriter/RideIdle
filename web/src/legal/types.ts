/** One part of a document: a heading and its paragraphs. The id is the anchor of the contents list. */
export interface LegalSection {
  id: string
  heading: string
  paragraphs: string[]
}

export interface LegalDocument {
  title: string
  intro: string
  sections: LegalSection[]
}

/**
 * The terms and the privacy policy in one language. Every language has the same sections, in the same order, with
 * the same number of paragraphs, so that a reviewer can read them side by side and a test can check it.
 *
 * A paragraph may hold `{minAge}` and `{guestDays}`, the numbers the server says (see `fillLegal`). Who the operator
 * is and where to write to are not in the text: the page shows them at the top, from the settings of the server.
 */
export interface LegalTexts {
  terms: LegalDocument
  privacy: LegalDocument
}

export type LegalDocumentName = keyof LegalTexts
