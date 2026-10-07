import { Capacitor } from '@capacitor/core'
import { shoppingApi } from '@/services/shoppingApi'

const fileName = 'lista-cumparaturi.txt'

export async function exportShoppingListText(listId: number): Promise<'exported' | 'empty'> {
  const response = await shoppingApi.exportText(listId)
  if (response.status === 204 || response.text.trim() === '') return 'empty'

  if (Capacitor.isNativePlatform()) {
    const [{ Filesystem, Directory, Encoding }, { Share }] = await Promise.all([
      import('@capacitor/filesystem'),
      import('@capacitor/share'),
    ])
    const saved = await Filesystem.writeFile({
      path: fileName,
      data: response.text,
      directory: Directory.Cache,
      encoding: Encoding.UTF8,
    })
    await Share.share({
      title: 'Lista de cumpărături',
      text: 'Lista de cumpărături',
      url: saved.uri,
      dialogTitle: 'Exportă lista',
    })
    return 'exported'
  }

  const blob = new Blob([response.text], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  document.body.append(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)

  return 'exported'
}
