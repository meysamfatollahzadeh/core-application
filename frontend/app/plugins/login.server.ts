import { appendResponseHeader, getHeader, setHeader, setResponseStatus } from 'h3'
import type { LoginState } from '#shared/types/login'

export default defineNuxtPlugin({ name: 'login', order: 10, async setup() {
  const event = useRequestEvent()!
  const state = useState<LoginState>('login')
  const result = await event.$fetch<LoginState>('/gateway/login/me', {
    headers: { cookie: getHeader(event, 'cookie') ?? '' },
    onResponse({ response }) {
      for (const cookie of response.headers.getSetCookie()) appendResponseHeader(event, 'set-cookie', cookie)
    },
  })
  setHeader(event, 'Cache-Control', 'no-store, private')
  setHeader(event, 'Referrer-Policy', 'no-referrer')
  state.value = result
  if (state.value.status === 'unavailable') setResponseStatus(event, 503)
} })
