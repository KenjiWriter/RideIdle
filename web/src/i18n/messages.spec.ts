import { describe, expect, it } from 'vitest'

import { SUPPORTED_LOCALES } from './locales'

type Tree = { [key: string]: string | Tree }

const files = import.meta.glob<Tree>('./locales/*.json', { eager: true, import: 'default' })

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  return Object.entries(tree).reduce<Record<string, string>>((flat, [key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key

    return typeof value === 'string' ? { ...flat, [path]: value } : { ...flat, ...flatten(value, path) }
  }, {})
}

function placeholders(text: string): string[] {
  return [...text.matchAll(/\{[a-zA-Z_]+\}/g)].map((m) => m[0]).sort()
}

const messages = Object.fromEntries(
  Object.entries(files).map(([path, tree]) => [path.replace('./locales/', '').replace('.json', ''), flatten(tree)]),
)

describe('translation files', () => {
  it('exist for every supported language and nothing else', () => {
    expect(Object.keys(messages).sort()).toEqual([...SUPPORTED_LOCALES].sort())
  })

  it.each([...SUPPORTED_LOCALES])('%s has the same keys as English', (locale) => {
    expect(Object.keys(messages[locale] ?? {}).sort()).toEqual(Object.keys(messages.en ?? {}).sort())
  })

  it.each([...SUPPORTED_LOCALES])('%s has no empty strings and keeps the placeholders', (locale) => {
    for (const [key, english] of Object.entries(messages.en ?? {})) {
      const translated = messages[locale]?.[key] ?? ''

      expect(translated.trim(), `${locale}: ${key} is empty`).not.toBe('')
      expect(placeholders(translated), `${locale}: placeholders differ in ${key}`).toEqual(placeholders(english))
    }
  })
})
