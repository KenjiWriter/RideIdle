/**
 * Puts the service worker (public/sw.js) to work: it makes the app installable and lets it start without the network. Only in the build that is served (the
 * development server has no use for it, and a worker that keeps files would hide the changes), and only where the browser has workers; a failure is not the
 * driver's business, the app works without it.
 */
export function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
    return
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => undefined)
  })
}
