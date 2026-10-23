import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'

import { FLASH_MS, useFlash } from './useFlash'

/** Runs the composable inside a component, because it cleans up when the component goes away. */
function withFlash(visibleMs?: number) {
  let flash!: ReturnType<typeof useFlash>

  const wrapper = mount(
    defineComponent({
      setup() {
        flash = useFlash(visibleMs)

        return () => null
      },
    }),
  )

  return { flash, wrapper }
}

describe('useFlash', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows a message and lets it go after a while', () => {
    const { flash } = withFlash()

    flash.show('Added 30 l')
    expect(flash.message.value).toBe('Added 30 l')

    vi.advanceTimersByTime(FLASH_MS - 1)
    expect(flash.message.value).toBe('Added 30 l')

    vi.advanceTimersByTime(1)
    expect(flash.message.value).toBeNull()
  })

  it('starts the time again when a new message comes', () => {
    const { flash } = withFlash(1_000)

    flash.show('first')
    vi.advanceTimersByTime(800)
    flash.show('second')
    vi.advanceTimersByTime(800)

    expect(flash.message.value).toBe('second')

    vi.advanceTimersByTime(200)
    expect(flash.message.value).toBeNull()
  })

  it('can be cleared at once', () => {
    const { flash } = withFlash()

    flash.show('Added 30 l')
    flash.clear()

    expect(flash.message.value).toBeNull()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('leaves no timer behind when the screen is closed', () => {
    const { flash, wrapper } = withFlash()

    flash.show('Added 30 l')
    wrapper.unmount()

    expect(vi.getTimerCount()).toBe(0)
  })
})
