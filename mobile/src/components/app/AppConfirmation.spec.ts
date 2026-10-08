// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, disposePinia } from 'pinia'
import { nextTick } from 'vue'
import AppConfirmation from './AppConfirmation.vue'
import { useNotificationStore } from '@/stores/notifications'

describe('AppConfirmation', () => {
  it('confirms and cancels through buttons, close, backdrop, Escape and unmount', async () => {
    const pinia = createPinia()
    const wrapper = mount(AppConfirmation, { global: { plugins: [pinia] } })
    const store = useNotificationStore(pinia)
    try {
      const result = store.confirm({
        title: 'Ștergere',
        message: 'Ștergi produsul?',
        confirmText: 'Șterge',
        cancelText: 'Păstrează',
      })
      await nextTick()
      expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Ștergi produsul?')
      const buttons = document.querySelectorAll<HTMLButtonElement>('.modal-footer button')
      expect(buttons[0]?.textContent).toContain('Păstrează')
      expect(buttons[1]?.textContent).toContain('Șterge')
      buttons[1]!.click()
      await expect(result).resolves.toBe(true)
      await nextTick()
      for (const dismiss of [
        () => document.querySelector<HTMLButtonElement>('.modal-footer button')!.click(),
        () => document.querySelector<HTMLButtonElement>('.modal-close')!.click(),
        () => document.querySelector<HTMLElement>('.modal-overlay')!.click(),
        () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })),
      ]) {
        const pending = store.confirm({ title: 'Confirmare', message: 'Continui?' })
        await nextTick()
        dismiss()
        await expect(pending).resolves.toBe(false)
        await nextTick()
        expect(document.querySelector('[role="dialog"]')).toBeNull()
      }
      const pending = store.confirm({ title: 'Confirmare', message: 'Continui?' })
      await nextTick()
      wrapper.unmount()
      await expect(pending).resolves.toBe(false)
    } finally {
      wrapper.unmount()
      disposePinia(pinia)
      document.body.innerHTML = ''
    }
  })
})
