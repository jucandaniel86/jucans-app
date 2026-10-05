// @vitest-environment happy-dom

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ getPlatform: vi.fn() }))
vi.mock('@capacitor/core', () => ({ Capacitor: mocks }))

import { configureNativeViewport } from '@/utils/nativeViewport'

describe('native viewport safe-area policy', () => {
  beforeEach(() => {
    document.head.innerHTML =
      '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    mocks.getPlatform.mockReturnValue('ios')
  })

  it('enables iOS safe-area values before the shell renders and remains idempotent', () => {
    configureNativeViewport()
    configureNativeViewport()
    expect(document.querySelector<HTMLMetaElement>('meta')!.content).toBe(
      'width=device-width, initial-scale=1.0, viewport-fit=cover',
    )
  })

  it.each(['android', 'web'])('preserves the existing %s viewport', (platform) => {
    mocks.getPlatform.mockReturnValue(platform)
    configureNativeViewport()
    expect(document.querySelector<HTMLMetaElement>('meta')!.content).toBe(
      'width=device-width, initial-scale=1.0',
    )
  })

  it('replaces an existing viewport-fit setting without dropping other options', () => {
    document.querySelector<HTMLMetaElement>('meta')!.content =
      'width=device-width, viewport-fit=contain, initial-scale=1.0'
    configureNativeViewport()
    expect(document.querySelector<HTMLMetaElement>('meta')!.content).toBe(
      'width=device-width, initial-scale=1.0, viewport-fit=cover',
    )
  })

  it('does not throw if the viewport meta is absent', () => {
    document.head.innerHTML = ''
    expect(configureNativeViewport).not.toThrow()
  })
})
