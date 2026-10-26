import { createRouter, createWebHistory } from 'vue-router'

import { installGuards } from './guards'
import { routes } from './routes'

export const router = installGuards(
  createRouter({
    history: createWebHistory(),
    routes,
    // A screen opens at its top (the browser keeps the place only when the driver goes back), so what a step of the tutorial points at is in sight.
    scrollBehavior: (_to, _from, saved) => saved ?? { top: 0 },
  }),
)
