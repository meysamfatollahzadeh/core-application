import { appendResponseHeader, getHeader, setResponseStatus } from 'h3'
import type { AccessState } from '#shared/types/access'

export default defineNuxtPlugin({
  name: 'access', dependsOn: ['login'],
  async setup() {
    const event = useRequestEvent()!
    const state = useState<AccessState>('access', () => ({ status: 'anonymous', global: [], companies: {}, visible_companies: [] }))
    if (useLogin().state.value.status !== 'authenticated') return
    try {
      const result = await event.$fetch<{ data: Omit<AccessState, 'status'> }>('/gateway/manage/access/me', {
        headers: { cookie: getHeader(event, 'cookie') ?? '' },
        onResponse({ response }) { for (const cookie of response.headers.getSetCookie()) appendResponseHeader(event, 'set-cookie', cookie) },
      })
      state.value = { ...result.data, status: 'ready' }
    } catch {
      state.value = { status: 'unavailable', global: [], companies: {}, visible_companies: [] }
      if (event.path.startsWith('/admin')) setResponseStatus(event, 503)
    }
  },
})
