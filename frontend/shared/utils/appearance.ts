import type { ThemeMode, ResolvedTheme } from '../types/appearance'
export function normalizeTheme(value: unknown): ThemeMode {
  return value === 'light' || value === 'dark' ? value : 'auto'
}
export function resolveTheme(mode: ThemeMode, device: unknown): ResolvedTheme {
  return mode === 'auto' ? (device === 'dark' ? 'dark' : 'light') : mode
}
