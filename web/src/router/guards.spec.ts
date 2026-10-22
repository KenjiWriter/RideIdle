import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import { storeToken } from '@/auth/token'

import { installGuards } from './guards'
import { routes } from './routes'

function freshRouter() {
  setActivePinia(createPinia())

  return installGuards(createRouter({ history: createMemoryHistory(), routes }))
}

describe('route guards', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('sends a visitor away from the garage', async () => {
    const router = freshRouter()

    await router.push('/garage')

    expect(router.currentRoute.value.name).toBe('landing')
  })

  it('lets a visitor open the sign-up screen', async () => {
    const router = freshRouter()

    await router.push('/create')

    expect(router.currentRoute.value.name).toBe('create')
  })

  it('sends a signed-in driver from the sign-up screen to the garage', async () => {
    storeToken('device-token')
    const router = freshRouter()

    await router.push('/create')

    expect(router.currentRoute.value.name).toBe('garage')
  })

  it('lets a signed-in driver open the garage and the landing page', async () => {
    storeToken('device-token')
    const router = freshRouter()

    await router.push('/garage')
    expect(router.currentRoute.value.name).toBe('garage')

    await router.push('/')
    expect(router.currentRoute.value.name).toBe('landing')
  })
})
