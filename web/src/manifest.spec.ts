import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

// What a browser needs to offer the app for the home screen: a manifest with the icons it names, and the page that points to it.

const publicDir = resolve(__dirname, '../public')
const manifest = JSON.parse(readFileSync(resolve(publicDir, 'manifest.webmanifest'), 'utf-8')) as {
  name: string
  short_name: string
  start_url: string
  scope: string
  display: string
  background_color: string
  theme_color: string
  icons: { src: string; sizes: string; type: string; purpose: string }[]
}
const page = readFileSync(resolve(__dirname, '../index.html'), 'utf-8')

/** The size of a PNG, from its header. */
function sizeOf(path: string): string {
  const bytes = readFileSync(path)

  expect(bytes.subarray(1, 4).toString('latin1')).toBe('PNG')

  return `${bytes.readUInt32BE(16)}x${bytes.readUInt32BE(20)}`
}

describe('the manifest of the app', () => {
  it('says what the installed app is: it opens the garage, full screen, in the colours of the app', () => {
    expect(manifest).toMatchObject({ name: 'RideIdle', short_name: 'RideIdle', start_url: '/garage', scope: '/', display: 'standalone', background_color: '#0e1013', theme_color: '#0e1013' })
    // The bar of the browser has the colour of the app too.
    expect(page).toContain(`<meta name="theme-color" content="${manifest.theme_color}" />`)
  })

  it('has the icons that a browser asks for, each a file of the size it says', () => {
    const purposes = manifest.icons.map((icon) => `${icon.sizes} ${icon.purpose}`)

    expect(purposes).toEqual(expect.arrayContaining(['192x192 any', '512x512 any', '512x512 maskable']))

    for (const icon of manifest.icons) {
      const path = resolve(publicDir, icon.src.slice(1))

      expect(icon.type).toBe('image/png')
      expect(existsSync(path), icon.src).toBe(true)
      expect(sizeOf(path), icon.src).toBe(icon.sizes)
    }
  })

  it('is linked from the page, together with the icons of the tab and of the home screen of an iPhone', () => {
    expect(page).toContain('<link rel="manifest" href="/manifest.webmanifest" />')
    expect(page).toContain('rel="apple-touch-icon" href="/icons/apple-touch-icon.png"')
    expect(sizeOf(resolve(publicDir, 'icons/apple-touch-icon.png'))).toBe('180x180')
    expect(sizeOf(resolve(publicDir, 'icons/favicon-48.png'))).toBe('48x48')
  })
})
