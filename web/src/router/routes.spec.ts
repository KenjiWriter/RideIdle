import { describe, expect, it } from 'vitest'

import { routes } from './routes'

const named = (name: string) => routes.find((route) => route.name === name)

describe('the routes', () => {
  it('mark the screens of the game, whose top bar is the HUD and which need a driver, and no other', () => {
    expect(routes.filter((route) => route.meta?.game).map((route) => route.name)).toEqual(['map', 'race', 'garage', 'fleet', 'condition', 'workshop', 'shop', 'exchange', 'dealer', 'storage'])

    for (const route of routes.filter((candidate) => candidate.meta?.game)) {
      expect(route.meta?.requiresAuth, String(route.name)).toBe(true)
      // A screen draws its own top bar or has the HUD for it, never both.
      expect(route.meta?.bare, String(route.name)).toBeUndefined()
    }
  })

  it('leave the header of the application to the screens that have no top bar of their own', () => {
    for (const name of ['landing', 'create', 'story', 'choose']) {
      expect(named(name)?.meta?.bare, name).toBe(true)
      expect(named(name)?.meta?.game, name).toBeUndefined()
    }

    for (const name of ['recover', 'terms', 'privacy', 'account-confirm', 'auth-callback']) {
      expect(named(name)?.meta?.bare, name).toBeUndefined()
      expect(named(name)?.meta?.game, name).toBeUndefined()
    }
  })
})
