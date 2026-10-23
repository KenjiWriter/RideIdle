import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readToken } from '@/auth/token'
import { i18n } from '@/i18n'
import { mountView } from '@/test/mountView'

import CreateDriverView from './CreateDriverView.vue'

const profile = { id: 1, name: 'Turbo', locale: 'en', is_guest: true, has_recovery_code: false }

function respond(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

async function open() {
  const mounted = await mountView(CreateDriverView, '/create')

  async function submit(name: string) {
    await mounted.wrapper.get('[data-test="name"]').setValue(name)
    await mounted.wrapper.get('form').trigger('submit')
    await flushPromises()
  }

  return { ...mounted, submit, error: () => mounted.wrapper.get('[data-test="error"]').text() }
}

describe('CreateDriverView', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    localStorage.clear()
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    i18n.global.locale.value = 'en'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says that creating a driver means accepting the terms and the privacy policy, with a link to each', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('[data-test="consent"]').text()).toBe('By creating a driver you accept the terms of service and the privacy policy.')
    expect(wrapper.get('[data-test="consent"] [data-test="terms-link"]').attributes('href')).toBe('/terms')
    expect(wrapper.get('[data-test="consent"] [data-test="privacy-link"]').attributes('href')).toBe('/privacy')
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })

  it('does not scold before the first submit', async () => {
    const { wrapper, error } = await open()

    await wrapper.get('[data-test="name"]').setValue('a')

    expect(error()).toBe('')
  })

  it.each([
    ['', 'Enter a name.'],
    ['ab', 'The name needs 3–16 characters.'],
    ['ab cd', 'Use only letters, digits, _ and -.'],
  ])('explains the problem with %j and sends nothing', async (name, message) => {
    const { submit, error } = await open()

    await submit(name)

    expect(error()).toBe(message)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('creates the driver and goes on to the choice of a wreck', async () => {
    fetchMock.mockResolvedValue(respond(201, { data: profile, token: 'device-token' }))
    const { submit, router } = await open()

    await submit('  Turbo ')

    const body = JSON.parse((fetchMock.mock.calls[0] as [string, RequestInit])[1].body as string)
    expect(body).toEqual({ name: 'Turbo', locale: 'en' })
    expect(readToken()).toBe('device-token')
    // The next view is loaded lazily, so wait for the navigation to finish.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('choose'))
  })

  it('shows the message of the server when the name is taken and stays on the page', async () => {
    fetchMock.mockResolvedValue(
      respond(422, { message: 'The name has already been taken.', errors: { name: ['The name has already been taken.'] } }),
    )
    const { submit, error, router } = await open()

    await submit('Turbo')

    expect(error()).toBe('The name has already been taken.')
    expect(router.currentRoute.value.name).toBe('create')
    expect(readToken()).toBeNull()
  })

  it('clears the server message when the driver edits the name', async () => {
    fetchMock.mockResolvedValue(respond(422, { message: 'taken', errors: { name: ['taken'] } }))
    const { submit, wrapper, error } = await open()

    await submit('Turbo')
    expect(error()).toBe('taken')

    await wrapper.get('[data-test="name"]').setValue('Turbo2')

    expect(error()).toBe('')
  })

  it('tells the driver to wait after too many attempts', async () => {
    fetchMock.mockResolvedValue(respond(429, { message: 'Too Many Attempts.' }))
    const { submit, error } = await open()

    await submit('Turbo')

    expect(error()).toBe('Too many attempts. Try again later.')
  })

  it('shows a general message when the server cannot be reached', async () => {
    fetchMock.mockRejectedValue(new TypeError('offline'))
    const { submit, error } = await open()

    await submit('Turbo')

    expect(error()).toBe('Something went wrong. Try again.')
  })

  it('disables the button while the request is running', async () => {
    let finish: (response: Response) => void = () => undefined
    fetchMock.mockReturnValue(new Promise<Response>((resolve) => (finish = resolve)))
    const { wrapper } = await open()

    await wrapper.get('[data-test="name"]').setValue('Turbo')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.get('[data-test="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="submit"]').text()).toBe('Creating…')

    await wrapper.get('form').trigger('submit')
    expect(fetchMock).toHaveBeenCalledTimes(1)

    finish(respond(201, { data: profile, token: 't' }))
    await flushPromises()
  })

  it('links back to the landing page', async () => {
    const { wrapper } = await open()

    expect(wrapper.get('[data-test="back"]').attributes('href')).toBe('/')
  })
})
