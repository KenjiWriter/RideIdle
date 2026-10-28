/*
 * The service worker of RideIdle. It makes the app installable and lets it start when the network is not there; it does nothing else.
 *
 * - When it is installed it fetches the shell and every file of the build (the list is assets-manifest.json, which the build writes), so the app starts without
 *   the network after the first visit already, on any screen (the page that installs it was loaded before the worker could keep anything); where the network
 *   fails at that moment, it works as it does without them.
 * - Pages (navigations): the network first, so a new release is seen at once; the last page that came is kept as the shell of the app, and is what opens
 *   when the network fails. Every address of the app is the same index.html (the router decides what it shows), so one copy is enough.
 * - Files of the build (/assets/, named by a hash, so a name is always the same bytes) and the icons: from the cache when they are there, else from the
 *   network and kept. The cache is trimmed to the newest files, and never emptied on an update: a page that is still open may ask for a file of the release
 *   it came with.
 * - The API (/api/) and everything that is not a GET of this site is not touched: nothing a driver does or sees (the token, the cash, the garage) is ever
 *   written to a cache here.
 */
const SHELL = 'shell-v1'
const FILES = 'files-v1'
const SHELL_URL = '/index.html'
const LIST_URL = '/assets-manifest.json'
const MAX_FILES = 150

self.addEventListener('install', (event) => {
  event.waitUntil(
    precache()
      .catch(() => undefined)
      .then(() => self.skipWaiting()),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) => Promise.all(names.filter((name) => name !== SHELL && name !== FILES).map((name) => caches.delete(name))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const request = event.request

  if (request.method !== 'GET') {
    return
  }

  const url = new URL(request.url)

  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return
  }

  if (request.mode === 'navigate') {
    event.respondWith(page(request))
  } else if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(file(request))
  }
})

/** The shell, and the files of the build: the ones the shell names, and every one that the list of the build has (the screens, the languages, the fonts, the art). */
async function precache() {
  const response = await fetch(SHELL_URL, { cache: 'reload' })

  if (!response.ok) {
    return
  }

  const names = new Set((await response.clone().text()).match(/\/assets\/[^"'\s>]+/g) ?? [])

  for (const name of await listed()) {
    names.add(name)
  }

  const shell = await caches.open(SHELL)
  const files = await caches.open(FILES)

  await shell.put(SHELL_URL, response)
  await Promise.all(
    [...names].map(async (name) => {
      const file = await fetch(name)

      if (file.status === 200) {
        await files.put(name, file)
      }
    }),
  )
  await trim(files)
}

/** The files that the list of the build names, as paths of the site; none when the list is not there. */
async function listed() {
  try {
    const response = await fetch(LIST_URL, { cache: 'reload' })
    const chunks = response.ok ? Object.values(await response.json()) : []

    return chunks.flatMap((chunk) => [chunk.file, ...(chunk.css ?? []), ...(chunk.assets ?? [])]).map((path) => `/${path}`)
  } catch {
    return []
  }
}

async function page(request) {
  try {
    const response = await fetch(request)
    const type = response.headers.get('Content-Type') ?? ''

    if (response.ok && type.includes('text/html')) {
      const shell = await caches.open(SHELL)
      await shell.put(SHELL_URL, response.clone())
    }

    return response
  } catch (failure) {
    const kept = await caches.match(SHELL_URL, { cacheName: SHELL, ignoreVary: true })

    if (kept) {
      return kept
    }

    throw failure
  }
}

async function file(request) {
  const cache = await caches.open(FILES)
  // A name is the checksum of the bytes, so what the server says about how the answer varies (the Origin of the request, from a server with CORS) is of no use here.
  const kept = await cache.match(request, { ignoreVary: true })

  if (kept) {
    return kept
  }

  const response = await fetch(request)

  if (response.status === 200) {
    await cache.put(request, response.clone())
    await trim(cache)
  }

  return response
}

/** The oldest files go when there are more than the cache is to hold (the keys come in the order they were put in). */
async function trim(cache) {
  const keys = await cache.keys()

  await Promise.all(keys.slice(0, Math.max(0, keys.length - MAX_FILES)).map((key) => cache.delete(key)))
}
