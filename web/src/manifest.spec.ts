import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

import { DEFAULT_GAME } from '@/game'

// What a browser needs to offer the app for the home screen: the page points at the manifest of the game (the server makes it from the game of the host, see
// GET /api/v1/meta/manifest), and the icons that the manifest of the car game names are there, each of the size it says.

const publicDir = resolve(__dirname, '../public')
const page = readFileSync(resolve(__dirname, '../index.html'), 'utf-8')

/** The icons that the manifest of the car game has, and what each is (the server names them in its manifest: public/icons is where they are). */
const DEFAULT_ICONS = [
  { src: 'icons/icon-192.png', sizes: '192x192' },
  { src: 'icons/icon-512.png', sizes: '512x512' },
  { src: 'icons/icon-maskable-512.png', sizes: '512x512' },
]

/** The size of a PNG, from its header. */
function sizeOf(path: string): string {
  const bytes = readFileSync(path)

  expect(bytes.subarray(1, 4).toString('latin1')).toBe('PNG')

  return `${bytes.readUInt32BE(16)}x${bytes.readUInt32BE(20)}`
}

describe('the manifest of the app', () => {
  it('is the server’s: the page points at the endpoint of the game of its host, and there is no manifest file of the app', () => {
    expect(page).toContain('<link rel="manifest" href="/api/v1/meta/manifest" />')
    expect(page).not.toContain('manifest.webmanifest')
    expect(existsSync(resolve(publicDir, 'manifest.webmanifest'))).toBe(false)
  })

  it('has the colour of the car game on the bar of the browser until a game says its own', () => {
    expect(page).toContain(`<meta name="theme-color" content="${DEFAULT_GAME.theme_color}" />`)
  })

  it('has the icons of the car game that the manifest names, each a file of the size it says', () => {
    for (const icon of DEFAULT_ICONS) {
      const path = resolve(publicDir, icon.src)

      expect(existsSync(path), icon.src).toBe(true)
      expect(sizeOf(path), icon.src).toBe(icon.sizes)
    }
  })

  it('has the icons of the tab and of the home screen of an iPhone, which a game with an icon of its own puts its picture in the place of', () => {
    expect(page).toContain('rel="apple-touch-icon" href="/icons/apple-touch-icon.png"')
    expect(sizeOf(resolve(publicDir, 'icons/apple-touch-icon.png'))).toBe('180x180')
    expect(sizeOf(resolve(publicDir, 'icons/favicon-48.png'))).toBe('48x48')
  })
})
