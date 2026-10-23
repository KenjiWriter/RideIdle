import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { setLocale } from '@/i18n'
import { respond, serveApi } from '@/test/fetchServer'
import { mountView } from '@/test/mountView'

import LinkAccountDialog from './LinkAccountDialog.vue'

const fetchMock = vi.fn()

let host: HTMLElement

/** The dialog is put in the document, because focus moves only there. */
async function show() {
  serveApi(fetchMock, { 'GET /api/v1/auth/providers': () => respond(200, { data: ['google', 'apple'] }) })
  const mounted = await mountView(LinkAccountDialog, '/', { attachTo: host })
  await flushPromises()

  return mounted
}

describe('LinkAccountDialog', () => {
  beforeEach(async () => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    await setLocale('en')
    host = document.createElement('div')
    document.body.appendChild(host)
  })

  afterEach(() => {
    host.remove()
    vi.unstubAllGlobals()
  })

  it('is a dialog with a title, as a screen reader finds it', async () => {
    const { wrapper } = await show()
    const dialog = wrapper.get('[role="dialog"]')

    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(wrapper.get(`#${dialog.attributes('aria-labelledby')}`).text()).toBe('Save your garage')
    expect(wrapper.text()).toContain('A guest account without one can be lost.')
  })

  it('offers the ways to link an account', async () => {
    const { wrapper } = await show()

    expect(wrapper.findAll('[data-test^="provider-"]').map((button) => button.text())).toEqual(['Continue with Google', 'Continue with Apple'])
    expect(wrapper.find('[data-test="email"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="terms"]').exists()).toBe(true)
  })

  it('takes the focus when it opens and gives it back when it closes', async () => {
    const opener = document.createElement('button')
    document.body.appendChild(opener)
    opener.focus()

    const { wrapper } = await show()

    expect(document.activeElement).toBe(wrapper.get('[role="dialog"]').element)

    wrapper.unmount()

    expect(document.activeElement).toBe(opener)
    opener.remove()
  })

  it.each([
    ['Esc', async (wrapper: Awaited<ReturnType<typeof show>>['wrapper']) => wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' })],
    ['a click outside', async (wrapper: Awaited<ReturnType<typeof show>>['wrapper']) => wrapper.get('[data-test="backdrop"]').trigger('click')],
    ['the Later button', async (wrapper: Awaited<ReturnType<typeof show>>['wrapper']) => wrapper.get('[data-test="later"]').trigger('click')],
  ])('lets the driver wait with %s', async (_name, act) => {
    const { wrapper } = await show()

    await act(wrapper)

    expect(wrapper.emitted('later')).toHaveLength(1)
    expect(wrapper.emitted('recovery')).toBeUndefined()
  })

  it('does not close when the driver clicks inside it', async () => {
    const { wrapper } = await show()

    await wrapper.get('[role="dialog"]').trigger('click')
    await wrapper.get('[data-test="email"]').trigger('click')

    expect(wrapper.emitted('later')).toBeUndefined()
  })

  it('lets the driver say they prefer the recovery code', async () => {
    const { wrapper } = await show()

    await wrapper.get('[data-test="recovery"]').trigger('click')

    expect(wrapper.emitted('recovery')).toHaveLength(1)
    expect(wrapper.emitted('later')).toBeUndefined()
  })

  it('keeps Tab inside: from the last control to the first, and back with Shift', async () => {
    const { wrapper } = await show()
    const dialog = wrapper.get('[role="dialog"]')
    const controls = [...dialog.element.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled])')]
    const first = controls[0]!
    const last = controls[controls.length - 1]!

    last.focus()
    await dialog.trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(first)

    first.focus()
    await dialog.trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(last)
  })

  it('speaks the language of the driver', async () => {
    await setLocale('pl')
    const { wrapper } = await show()

    expect(wrapper.get('h2').text()).toBe('Zapisz swój garaż')
    expect(wrapper.get('[data-test="later"]').text()).toBe('Później')

    await setLocale('en')
  })
})
