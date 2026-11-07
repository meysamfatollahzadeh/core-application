export type AccessState = {
  status: 'ready' | 'anonymous' | 'unavailable'
  global: string[]
  companies: Record<string, string[]>
  visible_companies: { id: number; title: string }[]
}
