import { Capacitor } from '@capacitor/core'

export function configureNativeViewport(): void {
  // Android manages its own WebView insets; leave its viewport policy unchanged.
  if (Capacitor.getPlatform() !== 'ios') return

  const viewport = document.querySelector<HTMLMetaElement>('meta[name="viewport"]')
  if (!viewport) return

  const options = viewport.content
    .split(',')
    .map((option) => option.trim())
    .filter((option) => option && !/^viewport-fit\s*=/i.test(option))
  viewport.content = [...options, 'viewport-fit=cover'].join(', ')
}
