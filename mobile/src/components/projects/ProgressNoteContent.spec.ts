// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { expect, it } from 'vitest'
import ProgressNoteContent from './ProgressNoteContent.vue'

it('renders basic Markdown as safe text without modifying saved content', async () => {
  const content =
    '**Titlu**\nContinuare\n\nAlt paragraf\n\n- Primul\n- **Al doilea**\n\n1. Unu\n2. Doi\n\n<img src=x onerror="alert(1)"><script>alert(1)</script>'
  const wrapper = mount(ProgressNoteContent, { props: { content } })
  expect(wrapper.findAll('strong').map((node) => node.text())).toEqual(['Titlu', 'Al doilea'])
  expect(wrapper.findAll('p')).toHaveLength(3)
  expect(wrapper.findAll('ul > li')).toHaveLength(2)
  expect(wrapper.findAll('ol > li')).toHaveLength(2)
  expect(wrapper.find('img').exists()).toBe(false)
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">')
  expect(wrapper.props('content')).toBe(content)
  await wrapper.setProps({ content: '**' })
  expect(wrapper.text()).toBe('**')
  wrapper.unmount()
})
