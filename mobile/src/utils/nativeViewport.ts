import { Capacitor } from '@capacitor/core'

export function configureNativeViewport(): void {
  // Android manages its own WebView insets; leave its viewport policy unchanged.
  if (Capacitor.getPlatform() !== 'ios') return

  const root = document.documentElement
  root.classList.add('native-ios')
  // WKWebView can retain :focus-visible after tap, autofocus or focus restoration.
  // Keep the focus itself; expose rings again as soon as a keyboard is used.
  root.dataset.inputModality = 'touch'
  document.addEventListener(
    'pointerdown',
    (event) => {
      root.dataset.inputModality = event.pointerType === 'mouse' ? 'mouse' : 'touch'
    },
    { capture: true, passive: true },
  )
  document.addEventListener(
    'keydown',
    (event) => {
      if (!['Shift', 'Control', 'Alt', 'Meta'].includes(event.key))
        root.dataset.inputModality = 'keyboard'
    },
    true,
  )

  const viewport = document.querySelector<HTMLMetaElement>('meta[name="viewport"]')
  if (!viewport) return

  const options = viewport.content
    .split(',')
    .map((option) => option.trim())
    .filter((option) => option && !/^viewport-fit\s*=/i.test(option))
  viewport.content = [...options, 'viewport-fit=cover'].join(', ')
}
