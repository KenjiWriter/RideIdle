import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it, vi } from 'vitest'

// The service worker is a plain script (public/sw.js), so it is run here as the browser runs it: with a `self`, a `caches` and a `fetch` that these tests hold.

const source = readFileSync(resolve(__dirname, '../public/sw.js'), 'utf-8')
const ORIGIN = 'https://raceidle.test'

interface Asked {
  method: string
  url: string
  mode: string
}

type Key = Asked | { url: string } | string

/** A cache keys a request by its address, and a path is the address on this site. */
const addressOf = (key: Key): string => (typeof key === 'string' ? (key.startsWith('/') ? `${ORIGIN}${key}` : key) : key.url)

/** A cache of the browser, by the address of the request. */
function fakeCache() {
  const entries = new Map<string, Response>()

  return {
    entries,
    match: async (request: Key) => entries.get(addressOf(request))?.clone(),
    put: async (request: Key, response: Response) => void entries.set(addressOf(request), response),
    keys: async () => [...entries.keys()].map((url) => ({ url })),
    delete: async (request: Key) => entries.delete(addressOf(request)),
  }
}

function fakeCaches() {
  const stores = new Map<string, ReturnType<typeof fakeCache>>()
  const open = async (name: string) => {
    if (!stores.has(name)) {
      stores.set(name, fakeCache())
    }

    return stores.get(name)!
  }

  return {
    stores,
    open,
    keys: async () => [...stores.keys()],
    delete: async (name: string) => stores.delete(name),
    match: async (request: Key, options?: { cacheName?: string }) => (options?.cacheName ? stores.get(options.cacheName)?.match(request) : undefined),
  }
}

type Listener = (event: { request?: Asked; waitUntil?: (work: Promise<unknown>) => void; respondWith?: (answer: Promise<Response>) => void }) => void

function boot(network: (request: Asked | string) => Promise<Response>) {
  const listeners: Record<string, Listener> = {}
  const self = {
    location: { origin: ORIGIN },
    addEventListener: (type: string, listener: Listener) => void (listeners[type] = listener),
    skipWaiting: vi.fn(async () => undefined),
    clients: { claim: vi.fn(async () => undefined) },
  }
  const caches = fakeCaches()
  const fetchMock = vi.fn(network)

  new Function('self', 'caches', 'fetch', 'URL', source)(self, caches, fetchMock, URL)

  /** What the worker answers to a request: nothing when it leaves the request alone. */
  async function ask(path: string, options: Partial<Asked> = {}): Promise<Response | undefined> {
    const request: Asked = { method: 'GET', url: `${path.startsWith('http') ? '' : ORIGIN}${path}`, mode: 'no-cors', ...options }
    let answer: Promise<Response> | undefined

    listeners.fetch({ request, respondWith: (given) => void (answer = given) })

    return answer
  }

  return { listeners, self, caches, fetchMock, ask }
}

const html = () => new Response('<!doctype html><title>RideIdle</title>', { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } })
const script = (status = 200) => new Response('console.log(1)', { status, headers: { 'Content-Type': 'text/javascript' } })

const shellPage = () =>
  new Response('<!doctype html><link rel="stylesheet" href="/assets/index-b1.css"><script type="module" src="/assets/index-a1.js"></script><link rel="modulepreload" href="/assets/index-a1.js">', {
    status: 200,
    headers: { 'Content-Type': 'text/html' },
  })

/** Installs and starts the worker, the way the browser does, and waits for what it holds the browser up with. */
async function install(worker: ReturnType<typeof boot>): Promise<void> {
  const work: Promise<unknown>[] = []
  worker.listeners.install({ waitUntil: (given) => void work.push(given) })
  await Promise.all(work)
}

describe('the service worker', () => {
  it('fetches the shell and every file of the build when it is installed, so the app starts offline on any screen after the first visit', async () => {
    const list = { 'src/main.ts': { file: 'assets/index-a1.js', css: ['assets/index-b1.css'], assets: ['assets/barlow-c1.woff2'] }, 'src/views/GarageView.vue': { file: 'assets/GarageView-d1.js' } }
    const worker = boot(async (request) => (request === '/index.html' ? shellPage() : request === '/assets-manifest.json' ? new Response(JSON.stringify(list), { status: 200 }) : script()))

    await install(worker)

    expect([...worker.caches.stores.get('shell-v1')!.entries.keys()]).toEqual([`${ORIGIN}/index.html`])
    expect([...worker.caches.stores.get('files-v1')!.entries.keys()].sort()).toEqual(
      ['index-a1.js', 'index-b1.css', 'barlow-c1.woff2', 'GarageView-d1.js'].map((name) => `${ORIGIN}/assets/${name}`).sort(),
    )
    expect(worker.self.skipWaiting).toHaveBeenCalledOnce()
  })

  it('keeps at least the files that the shell names when the list of the build is not there', async () => {
    const worker = boot(async (request) => (request === '/index.html' ? shellPage() : request === '/assets-manifest.json' ? new Response('nope', { status: 404 }) : script()))

    await install(worker)

    expect([...worker.caches.stores.get('files-v1')!.entries.keys()].sort()).toEqual([`${ORIGIN}/assets/index-a1.js`, `${ORIGIN}/assets/index-b1.css`])
  })

  it('is installed all the same when the network fails at that moment, or the shell is not there', async () => {
    const failing = boot(async () => Promise.reject(new TypeError('Failed to fetch')))
    await install(failing)
    expect(failing.self.skipWaiting).toHaveBeenCalledOnce()
    expect(failing.caches.stores.size).toBe(0)

    const missing = boot(async () => new Response('nope', { status: 404 }))
    await install(missing)
    expect(missing.self.skipWaiting).toHaveBeenCalledOnce()
    expect(missing.caches.stores.size).toBe(0)
  })

  it('takes over at once when it is installed, and keeps only its own two caches when it starts', async () => {
    const worker = boot(async () => html())
    await worker.caches.open('shell-v1')
    await worker.caches.open('files-v1')
    await worker.caches.open('an-older-worker')

    await install(worker)

    const activating: Promise<unknown>[] = []
    worker.listeners.activate({ waitUntil: (work) => void activating.push(work) })
    await Promise.all(activating)

    expect(worker.self.skipWaiting).toHaveBeenCalledOnce()
    expect(worker.self.clients.claim).toHaveBeenCalledOnce()
    expect([...worker.caches.stores.keys()].sort()).toEqual(['files-v1', 'shell-v1'])
  })

  it('leaves alone what is not for it: the API, other sites, and anything that is not a GET', async () => {
    const worker = boot(async () => html())

    expect(await worker.ask('/api/v1/garage')).toBeUndefined()
    expect(await worker.ask('/api/v1/garage', { mode: 'navigate' })).toBeUndefined()
    expect(await worker.ask('https://elsewhere.test/assets/app.js')).toBeUndefined()
    expect(await worker.ask('/assets/app.js', { method: 'POST' })).toBeUndefined()
    expect(await worker.ask('/manifest.webmanifest')).toBeUndefined()
    expect(await worker.ask('/sw.js')).toBeUndefined()
    expect(worker.fetchMock).not.toHaveBeenCalled()
    expect(worker.caches.stores.size).toBe(0)
  })

  describe('a page', () => {
    it('comes from the network, and the last one that came is kept as the shell of the app', async () => {
      const worker = boot(async () => html())

      const response = await (await worker.ask('/garage', { mode: 'navigate' }))!

      expect(response.status).toBe(200)
      expect(await response.text()).toContain('RideIdle')
      expect([...worker.caches.stores.get('shell-v1')!.entries.keys()]).toEqual([`${ORIGIN}/index.html`])
    })

    it('is the kept shell when the network fails, whichever address of the app was asked', async () => {
      const worker = boot(async () => html())
      await (await worker.ask('/garage', { mode: 'navigate' }))!.text()

      worker.fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
      const offline = await (await worker.ask('/workshop', { mode: 'navigate' }))!

      expect(await offline.text()).toContain('RideIdle')
    })

    it('fails as the network does when there is nothing kept, and keeps no answer that is not a page', async () => {
      const worker = boot(async () => new Response('{"message":"x"}', { status: 200, headers: { 'Content-Type': 'application/json' } }))

      await (await worker.ask('/garage', { mode: 'navigate' }))!.text()
      expect(worker.caches.stores.get('shell-v1')?.entries.size ?? 0).toBe(0)

      worker.fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
      await expect(worker.ask('/garage', { mode: 'navigate' }).then((answer) => answer!)).rejects.toThrow('Failed to fetch')
    })

    it('does not keep an error as the shell', async () => {
      const worker = boot(async () => new Response('<html>nope</html>', { status: 502, headers: { 'Content-Type': 'text/html' } }))

      const response = await (await worker.ask('/garage', { mode: 'navigate' }))!

      expect(response.status).toBe(502)
      expect(worker.caches.stores.get('shell-v1')?.entries.size ?? 0).toBe(0)
    })
  })

  describe('a file of the build', () => {
    it('is fetched once and then comes from the cache', async () => {
      const worker = boot(async () => script())

      await (await worker.ask('/assets/app-1a2b3c.js'))!.text()
      const again = await (await worker.ask('/assets/app-1a2b3c.js'))!

      expect(await again.text()).toBe('console.log(1)')
      expect(worker.fetchMock).toHaveBeenCalledOnce()
      expect([...worker.caches.stores.get('files-v1')!.entries.keys()]).toEqual([`${ORIGIN}/assets/app-1a2b3c.js`])
    })

    it('is the same for the icons, and is not kept when it is not there', async () => {
      const worker = boot(async () => script(404))

      expect((await (await worker.ask('/assets/gone.js'))!).status).toBe(404)
      expect(worker.caches.stores.get('files-v1')?.entries.size ?? 0).toBe(0)

      worker.fetchMock.mockResolvedValue(new Response('png', { status: 200 }))
      await (await worker.ask('/icons/icon-192.png'))!.text()

      expect([...worker.caches.stores.get('files-v1')!.entries.keys()]).toEqual([`${ORIGIN}/icons/icon-192.png`])
    })

    it('is kept up to 150 files, the oldest going first', async () => {
      const worker = boot(async () => script())
      const files = await worker.caches.open('files-v1')

      for (let i = 0; i < 150; i++) {
        await files.put(`${ORIGIN}/assets/old-${i}.js`, script())
      }

      await (await worker.ask('/assets/new.js'))!.text()

      expect(files.entries.size).toBe(150)
      expect(files.entries.has(`${ORIGIN}/assets/old-0.js`)).toBe(false)
      expect(files.entries.has(`${ORIGIN}/assets/old-1.js`)).toBe(true)
      expect(files.entries.has(`${ORIGIN}/assets/new.js`)).toBe(true)
    })
  })
})
