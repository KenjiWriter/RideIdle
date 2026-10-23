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
  { path: '/recover', name: 'recover', component: () => import('@/views/RecoverView.vue'), meta: { guestOnly: true } },
  // The link of an e-mail is opened in any browser, by a driver or not: it is open to everybody.
  { path: '/account/confirm', name: 'account-confirm', component: () => import('@/views/ConfirmEmailView.vue') },
  // Where the API sends the browser back from Google, Facebook or Apple. A driver linking an account and a visitor
  // signing in both come here, so it is open to everybody.
  { path: '/auth/callback', name: 'auth-callback', component: () => import('@/views/AuthCallbackView.vue') },
  { path: '/choose', name: 'choose', component: () => import('@/views/ChooseCarView.vue'), meta: { requiresAuth: true } },
  { path: '/garage', name: 'garage', component: () => import('@/views/GarageView.vue'), meta: { requiresAuth: true } },
]
