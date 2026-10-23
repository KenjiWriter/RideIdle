import type { RouteRecordRaw } from 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    /** Needs a signed-in driver: others are sent to the landing page. */
    requiresAuth?: boolean
    /** For visitors only: a signed-in driver is sent to the garage. */
    guestOnly?: boolean
    /** Draws its own top bar with the name of the game, as the canvas does: the header of the application is left out. */
    bare?: boolean
  }
}

/**
 * The routes live apart from the router instance so tests can build a router
 * with in-memory history from the same list.
 */
export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'landing', component: () => import('@/views/LandingView.vue'), meta: { bare: true } },
  { path: '/create', name: 'create', component: () => import('@/views/CreateDriverView.vue'), meta: { guestOnly: true, bare: true } },
  { path: '/recover', name: 'recover', component: () => import('@/views/RecoverView.vue'), meta: { guestOnly: true } },
  // The link of an e-mail is opened in any browser, by a driver or not: it is open to everybody.
  { path: '/account/confirm', name: 'account-confirm', component: () => import('@/views/ConfirmEmailView.vue') },
  // Where the API sends the browser back from Google, Facebook or Apple. A driver linking an account and a visitor
  // signing in both come here, so it is open to everybody.
  { path: '/auth/callback', name: 'auth-callback', component: () => import('@/views/AuthCallbackView.vue') },
  // The terms and the privacy policy are read before there is an account, and from the window that asks for consent.
  { path: '/terms', name: 'terms', component: () => import('@/views/LegalView.vue'), props: { document: 'terms' } },
  { path: '/privacy', name: 'privacy', component: () => import('@/views/LegalView.vue'), props: { document: 'privacy' } },
  // The story between the name and the choice of a wreck: only for a driver who has just been made.
  { path: '/story', name: 'story', component: () => import('@/views/StoryView.vue'), meta: { requiresAuth: true, bare: true } },
  { path: '/choose', name: 'choose', component: () => import('@/views/ChooseCarView.vue'), meta: { requiresAuth: true, bare: true } },
  { path: '/map', name: 'map', component: () => import('@/views/MapView.vue'), meta: { requiresAuth: true } },
  { path: '/race', name: 'race', component: () => import('@/views/RaceView.vue'), meta: { requiresAuth: true } },
  { path: '/garage', name: 'garage', component: () => import('@/views/GarageView.vue'), meta: { requiresAuth: true } },
  // The fleet is a part of the garage: the navigation keeps "Garage" lit, and the tabs of the garage lead here and back.
  { path: '/garage/fleet', name: 'fleet', component: () => import('@/views/FleetView.vue'), meta: { requiresAuth: true } },
  { path: '/workshop', name: 'workshop', component: () => import('@/views/WorkshopView.vue'), meta: { requiresAuth: true } },
  { path: '/shop', name: 'shop', component: () => import('@/views/ShopView.vue'), meta: { requiresAuth: true } },
]
