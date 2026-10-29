import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'

import type { VerdictKind } from '@/features/parts/compare'
import { i18n, setLocale } from '@/i18n'

import PartVerdict from './PartVerdict.vue'

const show = (kind: VerdictKind) => mount(PartVerdict, { props: { verdict: { kind, net: 0, improved: 0, worsened: 0 } }, global: { plugins: [i18n] } })

describe('PartVerdict', () => {
  beforeEach(async () => {
    await setLocale('en')
  })

  it.each([
    ['better', '▲ Worth fitting'],
    ['worse', '▼ Worse than the fitted one'],
    ['mixed', '⇅ Depends: some better, some worse'],
    ['same', '= Same as the fitted one'],
  ] as const)('says "%s" in a word with its arrow, in the color of the verdict', (kind, text) => {
    const wrapper = show(kind)

    expect(wrapper.get(`[data-test="verdict-${kind}"]`).text()).toBe(text)
    expect(wrapper.get('p').classes()).toContain(`verdict-${kind}`)
  })

  it('hides the arrow from a reader, who has the words', () => {
    expect(show('better').get('span').attributes('aria-hidden')).toBe('true')
  })

  it('speaks the language of the driver in all five', async () => {
    const texts = new Set<string>()

    for (const locale of ['pl', 'en', 'fr', 'de', 'pt-BR'] as const) {
      await setLocale(locale)

      for (const kind of ['better', 'worse', 'mixed', 'same'] as const) {
        expect(i18n.global.te(`shop.compare.verdict.${kind}`), `${locale} ${kind}`).toBe(true)
        texts.add(`${locale}:${i18n.global.t(`shop.compare.verdict.${kind}`)}`)
      }
    }

    expect(texts.size).toBe(20)
    await setLocale('pl')
    expect(show('better').text()).toContain('Warto założyć')
  })
})
