import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type { Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { i18n } from '@/i18n'
import { installGuards } from '@/router/guards'
import { routes } from '@/router/routes'

interface MountOptions {
  /** The props of a component that is not a screen. */
  props?: Record<string, unknown>
  /** Where to put the component, when the test needs a real focus: jsdom moves focus only inside the document. */
  attachTo?: HTMLElement
  /** Runs after Pinia is there and before the component is mounted: for state the component reads when it is set up. */
  prepare?: () => void
}

/**
 * Mounts a view with the real i18n, a fresh Pinia and a router built from the
 * application routes (in-memory history, same guards), at the given path.
 */
export async function mountView(component: Component, path = '/', options: MountOptions = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)

  const router = installGuards(createRouter({ history: createMemoryHistory(), routes }))
  await router.push(path)
  await router.isReady()

  options.prepare?.()

  const wrapper = mount(component, { props: options.props, attachTo: options.attachTo, global: { plugins: [pinia, router, i18n] } })

  return { wrapper, router, pinia }
}
