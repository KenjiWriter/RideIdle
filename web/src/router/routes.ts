import type { RouteRecordRaw } from 'vue-router'

/**
 * The routes live apart from the router instance so tests can build a router
 * with in-memory history from the same list.
 */
export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'landing', component: () => import('@/views/LandingView.vue') },
]
