// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ProjectFormView from './ProjectFormView.vue'
import ProjectDetailView from './ProjectDetailView.vue'
import { projectsApi } from '@/services/projectsApi'
import { projectNoteConfigurationApi } from '@/services/projectNoteConfigurationApi'
import { ApiError } from '@/services/api'
import type { Project } from '@/types/projects'

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: { id: 1 } }) }))
vi.mock('@/stores/notifications', () => ({
  useNotificationStore: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/services/projectsApi', () => ({
  projectsApi: {
    get: vi.fn(),
    categories: vi.fn(),
    summary: vi.fn(),
    update: vi.fn(),
    create: vi.fn(),
  },
}))
vi.mock('@/services/projectNoteConfigurationApi', () => ({
  projectNoteConfigurationApi: {
    fields: vi.fn(),
    createField: vi.fn(),
    updateField: vi.fn(),
    noteTypes: vi.fn(),
    createNoteType: vi.fn(),
    noteTypeFields: vi.fn(),
  },
}))
const project: Project = {
  id: 12,
  name: 'Health',
  description: null,
  category_id: 1,
  category: { id: 1, name: 'Health', slug: 'health', sort_order: 0 },
  icon: 'folder',
  progress_type: 'completion',
  default_execution_type: 'one_time',
  status: 'active',
  created_by: 1,
  last_activity_at: '',
  note_template: 'Legacy {date}',
}
const hosts: ReturnType<typeof mount>[] = []
async function page(url = '/projects/12/edit') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/projects/create', name: 'project-create', component: ProjectFormView },
      { path: '/projects/:id/edit', name: 'project-edit', component: ProjectFormView },
      { path: '/projects/:id', name: 'project-detail', component: ProjectDetailView },
      { path: '/projects', name: 'projects', component: { template: '<p>Projects</p>' } },
    ],
  })
  await router.push(url)
  const host = mount(RouterView, {
    attachTo: document.body,
    global: {
      plugins: [router],
      stubs: { ProjectTasksTab: true, ProjectProgressTab: true, ProjectChartsTab: true },
    },
  })
  hosts.push(host)
  await flushPromises()
  return { router, host, view: host.findComponent(ProjectFormView) }
}
function tab(view: ReturnType<typeof mount>, label: string) {
  return view.findAll('[role="tab"]').find((button) => button.text() === label)!
}
function activePanel(view: ReturnType<typeof mount>) {
  return view.findAll('[role="tabpanel"]').find((panel) => panel.isVisible())!
}
beforeEach(() => {
  vi.resetAllMocks()
  vi.mocked(projectNoteConfigurationApi.noteTypeFields).mockResolvedValue({ data: [] })
  vi.mocked(projectsApi.get).mockResolvedValue({ data: project })
  vi.mocked(projectsApi.categories).mockResolvedValue({ data: [project.category] })
  vi.mocked(projectNoteConfigurationApi.fields).mockResolvedValue({ data: [] })
  vi.mocked(projectNoteConfigurationApi.noteTypes).mockResolvedValue({ data: [] })
  vi.mocked(projectsApi.update).mockResolvedValue({ data: project })
  vi.mocked(projectsApi.create).mockResolvedValue({ data: project })
  vi.mocked(projectsApi.summary).mockResolvedValue({
    data: {
      timezone: 'UTC',
      completion: { total_tasks: 0, completed_tasks: 0, remaining_tasks: 0, archived_tasks: 0 },
      daily: {
        date: '2026-10-30',
        scheduled_occurrences: 0,
        completed_scheduled_occurrences: 0,
        completion_percentage: null,
        evaluation_average: null,
        evaluation_count: 0,
      },
    },
  })
})
afterEach(() => {
  hosts.splice(0).forEach((host) => host.unmount())
})

it('keeps Create as only the General form even with an edit-tab query', async () => {
  const { view, router } = await page('/projects/create?tab=settings')
  expect(view.find('[role="tablist"]').exists()).toBe(false)
  expect(view.find('form').isVisible()).toBe(true)
  expect(view.find('.custom-fields-library').exists()).toBe(false)
  expect(view.find('.project-edit-charts').exists()).toBe(false)
  expect(projectsApi.get).not.toHaveBeenCalled()
  expect(projectNoteConfigurationApi.fields).not.toHaveBeenCalled()
  await view.get('input[type="text"]').setValue('New project')
  await view.get('select').setValue('1')
  await view.get('form').trigger('submit')
  await flushPromises()
  expect(projectsApi.create).toHaveBeenCalledWith(
    expect.objectContaining({ name: 'New project', category_id: 1 }),
  )
  expect(router.currentRoute.value.name).toBe('project-detail')
})

it('shows three accessible Edit tabs with General selected by default', async () => {
  const { view } = await page()
  expect(view.findAll('[role="tab"]').map((button) => button.text())).toEqual([
    'General',
    'Settings',
    'Charts',
  ])
  expect(tab(view, 'General').attributes('aria-selected')).toBe('true')
  expect(tab(view, 'General').attributes('tabindex')).toBe('0')
  expect(tab(view, 'Settings').attributes('tabindex')).toBe('-1')
  expect(activePanel(view).text()).toContain('Setări generale')
  expect(view.find('form').isVisible()).toBe(true)
  expect(projectNoteConfigurationApi.fields).not.toHaveBeenCalled()
})

it('opens Settings directly and retains the existing read-only library outside General', async () => {
  const { view } = await page('/projects/12/edit?tab=settings')
  expect(tab(view, 'Settings').attributes('aria-selected')).toBe('true')
  expect(view.find('form').isVisible()).toBe(false)
  expect(activePanel(view).get('.custom-fields-library').isVisible()).toBe(true)
  expect(view.get('.custom-fields-library').element.closest('form')).toBeNull()
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledExactlyOnceWith(12)
})

it('opens Charts directly with informational configuration copy and no analytics requests', async () => {
  const { view } = await page('/projects/12/edit?tab=charts')
  expect(activePanel(view).text()).toContain('Configurare grafice')
  expect(activePanel(view).text()).toContain('În curând')
  expect(activePanel(view).find('button').exists()).toBe(false)
  expect(activePanel(view).find('svg').exists()).toBe(false)
  expect(projectsApi.summary).not.toHaveBeenCalled()
  expect(projectNoteConfigurationApi.fields).not.toHaveBeenCalled()
})

it('restores the active tab from the URL when the page is remounted', async () => {
  const first = await page('/projects/12/edit?tab=settings')
  const url = first.router.currentRoute.value.fullPath
  first.host.unmount()
  hosts.splice(hosts.indexOf(first.host), 1)
  const second = await page(url)
  expect(tab(second.view, 'Settings').attributes('aria-selected')).toBe('true')
  expect(activePanel(second.view).text()).toContain('Câmpuri personalizate')
})

it('preserves unsaved General values and the library across tab changes and history navigation', async () => {
  const { view, router } = await page('/projects/12/edit?source=projects#settings-top')
  const input = view.get('input[type="text"]')
  const [description, template] = view.findAll('textarea')
  await input.setValue('Unsaved title')
  await description!.setValue('Unsaved description')
  await template!.setValue('Unsaved legacy template')
  await tab(view, 'Settings').trigger('click')
  await flushPromises()
  expect(router.currentRoute.value.query).toEqual({ source: 'projects', tab: 'settings' })
  expect(router.currentRoute.value.hash).toBe('#settings-top')
  await tab(view, 'Charts').trigger('click')
  await flushPromises()
  await tab(view, 'Settings').trigger('click')
  await flushPromises()
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(1)
  await tab(view, 'General').trigger('click')
  await flushPromises()
  expect((input.element as HTMLInputElement).value).toBe('Unsaved title')
  expect((description!.element as HTMLTextAreaElement).value).toBe('Unsaved description')
  expect((template!.element as HTMLTextAreaElement).value).toBe('Unsaved legacy template')
  expect(router.currentRoute.value.query).toEqual({ source: 'projects' })
  expect(projectsApi.get).toHaveBeenCalledTimes(1)
  expect(projectsApi.categories).toHaveBeenCalledTimes(1)
  expect(projectsApi.update).not.toHaveBeenCalled()
  expect(projectsApi.create).not.toHaveBeenCalled()
  router.back()
  await flushPromises()
  expect(tab(view, 'Settings').attributes('aria-selected')).toBe('true')
  expect(projectsApi.get).toHaveBeenCalledTimes(1)
})

it('saves General properties including the legacy template using the existing update flow', async () => {
  const { view, router } = await page()
  await view.get('input[type="text"]').setValue('  Updated title  ')
  await view.findAll('textarea')[1]!.setValue('Legacy {tasks}')
  await view.get('form').trigger('submit')
  await flushPromises()
  expect(projectsApi.update).toHaveBeenCalledExactlyOnceWith(12, {
    name: 'Updated title',
    description: null,
    note_template: 'Legacy {tasks}',
    category_id: 1,
    icon: 'folder',
    progress_type: 'completion',
    default_execution_type: 'one_time',
  })
  expect(router.currentRoute.value.name).toBe('project-detail')
})

it('retains General validation errors across tabs', async () => {
  vi.mocked(projectsApi.update).mockRejectedValue(
    new ApiError('Invalid', 422, { name: ['Invalid name'] }),
  )
  const { view } = await page()
  await view.get('form').trigger('submit')
  await flushPromises()
  await tab(view, 'Settings').trigger('click')
  await flushPromises()
  await tab(view, 'General').trigger('click')
  await flushPromises()
  expect(activePanel(view).text()).toContain('Invalid name')
})

it('supports arrow and Home/End keys with roving focus and linked panels', async () => {
  const { view } = await page()
  await tab(view, 'General').trigger('keydown', { key: 'ArrowRight' })
  await flushPromises()
  expect(document.activeElement).toBe(tab(view, 'Settings').element)
  expect(activePanel(view).attributes('aria-labelledby')).toBe(
    tab(view, 'Settings').attributes('id'),
  )
  await tab(view, 'Settings').trigger('keydown', { key: 'End' })
  await flushPromises()
  expect(document.activeElement).toBe(tab(view, 'Charts').element)
  await tab(view, 'Charts').trigger('keydown', { key: 'Home' })
  await flushPromises()
  expect(document.activeElement).toBe(tab(view, 'General').element)
  await tab(view, 'General').trigger('keydown', { key: 'ArrowLeft' })
  await flushPromises()
  expect(tab(view, 'Charts').attributes('aria-selected')).toBe('true')
})

it.each([
  { label: 'collaborator', created_by: 2, status: 'active' as const },
  { label: 'inactive owner', created_by: 1, status: 'archived' as const },
])(
  'preserves read-only behavior and permitted Settings access for $label',
  async ({ created_by, status }) => {
    vi.mocked(projectsApi.get).mockResolvedValue({ data: { ...project, created_by, status } })
    const { view } = await page()
    expect(activePanel(view).text()).toContain('Doar pentru citire')
    expect(view.find('form').exists()).toBe(false)
    await tab(view, 'Settings').trigger('click')
    await flushPromises()
    expect(activePanel(view).get('.custom-fields-library').isVisible()).toBe(true)
    expect(activePanel(view).find('input').exists()).toBe(false)
    expect(activePanel(view).find('button').exists()).toBe(false)
    expect(projectsApi.update).not.toHaveBeenCalled()
  },
)

it('keeps unauthorized projects inaccessible and invalid tab queries on General', async () => {
  const allowed = await page('/projects/12/edit?tab=unknown')
  expect(tab(allowed.view, 'General').attributes('aria-selected')).toBe('true')
  vi.mocked(projectsApi.get).mockRejectedValue(new ApiError('Forbidden', 403))
  const denied = await page('/projects/13/edit?tab=settings')
  expect(denied.view.find('[role="alert"]').exists()).toBe(true)
  expect(denied.view.find('[role="tablist"]').exists()).toBe(false)
  expect(denied.view.find('.custom-fields-library').exists()).toBe(false)
  expect(projectNoteConfigurationApi.fields).not.toHaveBeenCalled()
})

it('reloads general data for a different project ID while preserving the selected tab', async () => {
  const { view, router } = await page('/projects/12/edit?tab=settings')
  vi.mocked(projectsApi.get).mockResolvedValue({
    data: { ...project, id: 24, name: 'Other project' },
  })
  await router.push('/projects/24/edit?tab=settings')
  await flushPromises()
  expect(projectsApi.get).toHaveBeenLastCalledWith(24)
  expect(projectNoteConfigurationApi.fields).toHaveBeenLastCalledWith(24)
  expect((view.get('input[type="text"]').element as HTMLInputElement).value).toBe('Other project')
})

it('keeps the existing Project Detail visualization tab and settings entry unchanged', async () => {
  vi.mocked(projectsApi.get).mockResolvedValue({ data: { ...project, created_by: 2 } })
  const { host, router } = await page('/projects/12')
  const detail = host.findComponent(ProjectDetailView)
  expect(detail.findAll('a').some((link) => link.text() === 'Setări proiect')).toBe(true)
  expect(detail.text()).not.toContain('Arhivează')
  expect(detail.text()).not.toContain('Finalizează')
  await tab(detail, 'Charts').trigger('click')
  await flushPromises()
  expect(detail.findComponent({ name: 'ProjectChartsTab' }).exists()).toBe(true)
  expect(router.currentRoute.value.query).toEqual({})
})

it('creates a field inside Settings and refreshes only the library while preserving the Settings URL', async () => {
  const created = {
    id: 7,
    project_id: 12,
    name: 'Energy',
    key: 'energy',
    type: 'number' as const,
    description: null,
    unit: null,
    min: null,
    max: null,
    step: null,
    aggregation: 'average' as const,
    first_value_at: null,
    archived_at: null,
    created_at: null,
    updated_at: null,
  }
  vi.mocked(projectNoteConfigurationApi.createField).mockResolvedValue({ data: created })
  vi.mocked(projectNoteConfigurationApi.fields)
    .mockResolvedValueOnce({ data: [] })
    .mockResolvedValueOnce({ data: [created] })
  const { view, router } = await page('/projects/12/edit?tab=settings')
  await view.get('[aria-label="Adaugă câmp"]').trigger('click')
  await flushPromises()
  const name = document.querySelector<HTMLInputElement>('.field-editor [data-field="name"] input')!
  name.value = 'Energy'
  name.dispatchEvent(new Event('input', { bubbles: true }))
  document
    .querySelector<HTMLFormElement>('.field-editor form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await flushPromises()
  expect(document.querySelector('.field-editor')).toBeNull()
  expect(router.currentRoute.value.query.tab).toBe('settings')
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(2)
  expect(projectsApi.get).toHaveBeenCalledTimes(1)
  expect(projectsApi.update).not.toHaveBeenCalled()
  expect(activePanel(view).text()).toContain('Energy')
})

it('creates a Note Type above Custom Fields without changing the Settings URL or General draft', async () => {
  const created = {
    id: 8,
    project_id: 12,
    name: 'Treatment',
    description: null,
    template: 'Context:\nNotes:\n',
    is_default: true,
    archived_at: null,
    created_at: null,
    updated_at: null,
  }
  vi.mocked(projectNoteConfigurationApi.createNoteType).mockResolvedValue({ data: created })
  const { view, router } = await page()
  const name = view.get('input[type="text"]')
  const template = view.findAll('textarea')[1]!
  await name.setValue('Unsaved project title')
  await template.setValue('Unsaved legacy template')
  await tab(view, 'Settings').trigger('click')
  await flushPromises()
  expect(
    activePanel(view)
      .findAll('h2')
      .map((h) => h.text()),
  ).toEqual(['Tipuri de note', 'Câmpuri personalizate'])
  await view.get('[aria-label="Adaugă tip de notă"]').trigger('click')
  await flushPromises()
  for (const [field, value] of [
    ['name', 'Treatment'],
    ['template', created.template],
  ]) {
    const el = document.querySelector<HTMLInputElement>(
      `.note-type-editor [data-field="${field}"] input, .note-type-editor [data-field="${field}"] textarea`,
    )!
    el.value = value!
    el.dispatchEvent(new Event('input', { bubbles: true }))
  }
  await flushPromises()
  vi.mocked(projectNoteConfigurationApi.noteTypes).mockResolvedValue({ data: [created] })
  document
    .querySelector('.note-type-editor form')!
    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  await flushPromises()
  expect(document.querySelector('.note-type-editor')).toBeNull()
  expect(router.currentRoute.value.query.tab).toBe('settings')
  expect(projectNoteConfigurationApi.createNoteType).toHaveBeenCalledExactlyOnceWith(12, {
    name: 'Treatment',
    description: null,
    template: created.template,
  })
  expect(projectNoteConfigurationApi.noteTypes).toHaveBeenCalledTimes(2)
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(1)
  expect(projectsApi.get).toHaveBeenCalledTimes(1)
  expect(projectsApi.update).not.toHaveBeenCalled()
  await tab(view, 'General').trigger('click')
  await flushPromises()
  expect((name.element as HTMLInputElement).value).toBe('Unsaved project title')
  expect((template.element as HTMLTextAreaElement).value).toBe('Unsaved legacy template')
  expect(project.note_template).toBe('Legacy {date}')
})

it('refreshes Custom Fields after a definition is created from a Note Type without resetting either draft', async () => {
  const type = {
    id: 7,
    project_id: 12,
    name: 'General',
    description: null,
    template: 'Type template',
    is_default: true,
    archived_at: null,
    created_at: null,
    updated_at: null,
  }
  vi.mocked(projectNoteConfigurationApi.noteTypes).mockResolvedValue({ data: [type] })
  const { view, router } = await page()
  const name = view.get('input[type="text"]')
  const legacyTemplate = view.findAll('textarea')[1]!
  await name.setValue('Project draft')
  await legacyTemplate.setValue('Legacy draft')
  await tab(view, 'Settings').trigger('click')
  await flushPromises()
  await view.get('[aria-label="Editează tipul General"]').trigger('click')
  await flushPromises()
  const editor = view.findComponent({ name: 'ProjectNoteTypeEditor' })
  const typeName = document.querySelector<HTMLInputElement>(
    '.note-type-editor [data-field="name"] input',
  )!
  typeName.value = 'Note Type draft'
  typeName.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
  editor.vm.$emit('definitionsChanged')
  await flushPromises()
  expect(projectNoteConfigurationApi.fields).toHaveBeenCalledTimes(2)
  expect(typeName.value).toBe('Note Type draft')
  expect((name.element as HTMLInputElement).value).toBe('Project draft')
  expect((legacyTemplate.element as HTMLTextAreaElement).value).toBe('Legacy draft')
  expect(router.currentRoute.value.query.tab).toBe('settings')
  expect(projectsApi.get).toHaveBeenCalledTimes(1)
  expect(projectsApi.update).not.toHaveBeenCalled()
})
