// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShoppingListQuickAdd from '@/components/shopping/ShoppingListQuickAdd.vue'
import { useActiveShoppingListStore } from '@/stores/activeShoppingList'
import { useNotificationStore } from '@/stores/notifications'
import type { ActiveShoppingList } from '@/types/shopping'

describe('shopping list quick add', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useActiveShoppingListStore().list = {
      id: 1,
      status: 'open',
      items: [],
    } as unknown as ActiveShoppingList
  })

  it('opens inline, submits a trimmed name and Romanian decimal quantity, then clears and collapses', async () => {
    const store = useActiveShoppingListStore()
    const add = vi.spyOn(store, 'addManualItem').mockResolvedValue()
    const wrapper = mount(ShoppingListQuickAdd)
    expect(wrapper.find('form').exists()).toBe(false)
    await wrapper.get('button').trigger('click')
    expect(wrapper.get('input[name="quantity"]').attributes('inputmode')).toBe('decimal')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    await wrapper.get('input[name="name"]').setValue('  Dero  ')
    await wrapper.get('input[name="quantity"]').setValue('1,5')
    await wrapper.get('input[name="unit"]').setValue(' buc ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(add).toHaveBeenCalledWith({ name: 'Dero', quantity: 1.5, unit: 'buc' })
    expect(wrapper.find('form').exists()).toBe(false)
    await wrapper.get('button').trigger('click')
    expect(
      wrapper.findAll('input').map((input) => (input.element as HTMLInputElement).value),
    ).toEqual(['', '', ''])
  })

  it('allows a name alone and keeps fields with global feedback on failure', async () => {
    const store = useActiveShoppingListStore()
    const add = vi
      .spyOn(store, 'addManualItem')
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValue()
    const wrapper = mount(ShoppingListQuickAdd)
    await wrapper.get('button').trigger('click')
    await wrapper.get('input[name="name"]').setValue('Șervețele')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(useNotificationStore().notifications.at(-1)?.message).toContain('Nu am putut adăuga')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect((wrapper.get('input[name="name"]').element as HTMLInputElement).value).toBe('Șervețele')
    expect(add).toHaveBeenCalledWith({ name: 'Șervețele', quantity: null, unit: null })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('rejects whitespace names and nonpositive or invalid amounts', async () => {
    const store = useActiveShoppingListStore()
    const add = vi.spyOn(store, 'addManualItem').mockResolvedValue()
    const wrapper = mount(ShoppingListQuickAdd)
    await wrapper.get('button').trigger('click')
    await wrapper.get('input[name="name"]').setValue('   ')
    await wrapper.get('form').trigger('submit')
    expect(add).not.toHaveBeenCalled()
    await wrapper.get('input[name="name"]').setValue('Dero')
    for (const amount of ['0', '-1', 'abc']) {
      await wrapper.get('input[name="quantity"]').setValue(amount)
      await wrapper.get('form').trigger('submit')
      expect(wrapper.get('[role="alert"]').text()).toContain('mai mare decât zero')
    }
    expect(add).not.toHaveBeenCalled()
  })

  it('disables submission and blocks repeat submits while the shared store is saving', async () => {
    const store = useActiveShoppingListStore()
    const add = vi.spyOn(store, 'addManualItem').mockImplementation(async () => {
      store.addingItem = true
    })
    const wrapper = mount(ShoppingListQuickAdd)
    await wrapper.get('button').trigger('click')
    await wrapper.get('input[name="name"]').setValue('Dero')
    store.addingItem = true
    await flushPromises()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    await wrapper.get('form').trigger('submit')
    expect(add).not.toHaveBeenCalled()
  })
})
