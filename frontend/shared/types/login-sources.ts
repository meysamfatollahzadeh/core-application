export type LoginSourceSummary = { slug: string; title: string; is_active: boolean; members_count: number }
export type LoginSourceConfiguration = {
  issuer: string
  internal_issuer: string
  client_id: string
  callback_url: string
  post_logout_redirect_uri: string
  import_client_id?: string
  admin_base_url?: string
  allowed_algorithms: string[]
  timeout: number
  page_size: number
}
export type ManagedLoginSource = LoginSourceSummary & {
  data: LoginSourceConfiguration
  client_secret_configured: boolean
  import_client_secret_configured: boolean
}
export type LoginSourcePage = { data: LoginSourceSummary[]; meta: { total: number; last_page: number } }
