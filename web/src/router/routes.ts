import type { RouteRecordRaw } from 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    /** Needs a signed-in driver: others are sent to the landing page. */
    requiresAuth?: boolean
    /** For visitors only: a signed-in driver is sent to the garage. */
    guestOnly?: boolean
  }
}

/**
 * The routes live apart from the router instance so tests can build a router
 * with in-memory history from the same list.
 */
export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'landing', component: () => import('@/views/LandingView.vue') },
  { path: '/create', name: 'create', component: () => import('@/views/CreateDriverView.vue'), meta: { guestOnly: true } },
  { path: '/garage', name: 'garage', component: () => import('@/views/GarageView.vue'), meta: { requiresAuth: true } },
]
