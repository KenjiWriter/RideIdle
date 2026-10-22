import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { i18n } from '@/i18n'
import { installGuards } from '@/router/guards'
import { routes } from '@/router/routes'

/**
 * Mounts a view with the real i18n, a fresh Pinia and a router built from the
 * application routes (in-memory history, same guards), at the given path.
 */
export async function mountView(component: Component, path = '/') {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = installGuards(createRouter({ history: createMemoryHistory(), routes }))
  await router.push(path)
  await router.isReady()

  const wrapper = mount(component, { global: { plugins: [pinia, router, i18n] } })

  return { wrapper, router, pinia }
}
