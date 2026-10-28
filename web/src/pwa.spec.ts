import { afterEach, describe, expect, it, vi } from 'vitest'

import { registerServiceWorker } from './pwa'

function withWorkers(register: () => Promise<unknown>) {
  Object.defineProperty(navigator, 'serviceWorker', { value: { register }, configurable: true })
}

/** The functions that are put on the loading of the page, taken instead of being attached (a listener of one test must not answer the load of the next). */
function loadListeners(): (() => void)[] {
  const listeners: (() => void)[] = []

  vi.spyOn(window, 'addEventListener').mockImplementation(((type: string, listener: () => void) => {
    if (type === 'load') {
      listeners.push(listener)
    }
  }) as typeof window.addEventListener)

  return listeners
}

describe('registerServiceWorker', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.restoreAllMocks()
    Reflect.deleteProperty(navigator, 'serviceWorker')
  })

  it('registers the worker of the site when the page has loaded, in the build that is served', () => {
    vi.stubEnv('PROD', true)
    const register = vi.fn(async () => undefined)
    withWorkers(register)
    const listeners = loadListeners()

    registerServiceWorker()

    expect(listeners).toHaveLength(1)
    expect(register).not.toHaveBeenCalled()

    listeners[0]()

    expect(register).toHaveBeenCalledExactlyOnceWith('/sw.js')
  })

  it('does nothing in the development server, where a worker would hide the changes', () => {
    vi.stubEnv('PROD', false)
    withWorkers(vi.fn(async () => undefined))
    const listeners = loadListeners()

    registerServiceWorker()

    expect(listeners).toHaveLength(0)
  })

  it('does nothing in a browser that has no workers', () => {
    vi.stubEnv('PROD', true)
    const listeners = loadListeners()

    registerServiceWorker()

    expect(listeners).toHaveLength(0)
  })

  it('is not troubled when the worker cannot be registered: the app works without it', async () => {
    vi.stubEnv('PROD', true)
    const register = vi.fn(() => Promise.reject(new Error('refused')))
    withWorkers(register)
    const listeners = loadListeners()

    registerServiceWorker()
    listeners[0]()
    await Promise.resolve()

    expect(register).toHaveBeenCalledOnce()
  })
})
