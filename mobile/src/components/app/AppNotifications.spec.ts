// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, disposePinia } from 'pinia'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import { describe, expect, it } from 'vitest'
import AppNotifications from './AppNotifications.vue'
import { useNotificationStore } from '@/stores/notifications'

describe('global notifications', () => {
  it('persists across route changes, announces feedback and allows dismissal', async () => {
    const pinia = createPinia()
    const router = createRouter({ history: createMemoryHistory(), routes: [
      { path: '/first', component: { template: '<p>Prima pagină</p>' } },
      { path: '/second', component: { template: '<p>A doua pagină</p>' } },
    ] })
    await router.push('/first')
    const shell = defineComponent({ components: { RouterView, AppNotifications }, template: '<RouterView /><AppNotifications />' })
    const wrapper = mount(shell, { global: { plugins: [pinia, router] } })
    const store = useNotificationStore(pinia)
    store.success('Rețeta a fost salvată.', 0)
    store.error('Nu am putut salva.', 0)
    await flushPromises()
    expect(document.querySelector('[role="status"]')?.textContent).toContain('Rețeta a fost salvată.')
    expect(document.querySelector('[role="alert"]')?.textContent).toContain('Nu am putut salva.')
    await router.push('/second')
    await flushPromises()
    expect(wrapper.text()).toContain('A doua pagină')
    expect(document.querySelectorAll('.app-notification')).toHaveLength(2)
    document.querySelector<HTMLButtonElement>('.app-notification button')!.click()
    await flushPromises()
    expect(store.notifications).toHaveLength(1)
    wrapper.unmount()
    disposePinia(pinia)
  })

  it('keeps feedback inside the active modal top layer and returns it to the shell on close', async () => {
    const pinia = createPinia()
    const wrapper = mount(AppNotifications, { global: { plugins: [pinia] } })
    const dialog = document.createElement('dialog')
    document.body.append(dialog)
    dialog.showModal()
    useNotificationStore(pinia).error('Încearcă din nou.', 0)
    await flushPromises()
    expect(dialog.querySelector('.app-notification')?.textContent).toContain('Încearcă din nou.')
    dialog.close()
    await flushPromises()
    expect(dialog.querySelector('.app-notification')).toBeNull()
    expect(document.querySelector('.app-notification')?.textContent).toContain('Încearcă din nou.')
    wrapper.unmount()
    dialog.remove()
    disposePinia(pinia)
  })
})
