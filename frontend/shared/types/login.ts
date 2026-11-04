export type LoginIdentity = {
  user: { id: number; name: string }
  source: { slug: string; title: string }
  member: { id: number; uuid: string }
}
export type LoginState = {
  status: 'authenticated' | 'anonymous' | 'unavailable'
  identity: LoginIdentity | null
  csrf: string
  canLogout: boolean
}
export type UserSourceSummary = { slug: string; title: string }
