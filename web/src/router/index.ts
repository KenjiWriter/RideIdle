import { createRouter, createWebHistory } from 'vue-router'

import { installGuards } from './guards'
import { routes } from './routes'

export const router = installGuards(
  createRouter({
    history: createWebHistory(),
    routes,
  }),
)
