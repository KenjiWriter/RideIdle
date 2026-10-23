import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

import { setLocale } from '@/i18n'
import { mountView } from '@/test/mountView'

import LegalLinks from './LegalLinks.vue'

/** The component with the sentence it is given, as a screen would. */
function sentence(keypath: string) {
  return defineComponent({ render: () => h(LegalLinks, { keypath }) })
}

describe('LegalLinks', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  afterEach(async () => {
    await setLocale('en')
  })

  it('names the terms and the privacy policy in a sentence, each with a link', async () => {
    const { wrapper } = await mountView(sentence('account.terms'))

    expect(wrapper.text()).toBe('I accept the terms of service and the privacy policy.')
    expect(wrapper.get('[data-test="terms-link"]').attributes('href')).toBe('/terms')
    expect(wrapper.get('[data-test="privacy-link"]').attributes('href')).toBe('/privacy')
  })

  it('opens them in another tab, so that the window that asks for consent is still there when the reader comes back', async () => {
    const { wrapper } = await mountView(sentence('account.terms'))

    for (const link of ['terms-link', 'privacy-link']) {
      expect(wrapper.get(`[data-test="${link}"]`).attributes('target')).toBe('_blank')
      expect(wrapper.get(`[data-test="${link}"]`).attributes('rel')).toContain('noopener')
    }
  })

  it('works with the sentence of another screen', async () => {
    const { wrapper } = await mountView(sentence('create.consent'))

    expect(wrapper.text()).toBe('By creating a driver you accept the terms of service and the privacy policy.')
  })

  it.each([
    ['pl', 'Akceptuję regulamin i politykę prywatności.'],
    ['fr', 'J’accepte les conditions d’utilisation et la politique de confidentialité.'],
    ['de', 'Ich akzeptiere die Nutzungsbedingungen und die Datenschutzerklärung.'],
    ['pt-BR', 'Aceito os termos de uso e a política de privacidade.'],
  ] as const)('puts the names where %s wants them, in the form the sentence needs', async (locale, text) => {
    await setLocale(locale)
    const { wrapper } = await mountView(sentence('account.terms'))

    expect(wrapper.text()).toBe(text)
    expect(wrapper.get('[data-test="terms-link"]').attributes('href')).toBe('/terms')
  })
})
