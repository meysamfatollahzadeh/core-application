import { loginApi, logoutCookie, noStore, readProtectedCookie, writeProtectedCookie } from '../../utils/login'
export default defineEventHandler(async event => {
  noStore(event)
  const pending = readProtectedCookie<{ handoff: string; binding: string; state: string }>(event, logoutCookie)
  if (!pending) return sendRedirect(event, '/?logout_error=remote_unavailable', 303)
  try {
    const form = await loginApi<{ endpoint: string; fields: Record<string, string> }>(event, '/logout/continue', { method: 'POST', body: { handoff: pending.handoff, browser_binding: pending.binding } })
    if (new URL(form.endpoint).origin !== useRuntimeConfig(event).appOrigin) throw new Error('origin')
    writeProtectedCookie(event, logoutCookie, { state: pending.state }, 120)
    setHeader(event, 'Content-Type', 'text/html; charset=utf-8')
    setHeader(event, 'Content-Security-Policy', "default-src 'none'; style-src 'self'; font-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
    const escape = (value: string) => value.replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]!)
    // A normal form works with JavaScript disabled and keeps the ID token out of URL history.
    return `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>خروج از حساب سازمانی</title><link rel="stylesheet" href="/login-handoff.css"><body><h1>خروج از حساب سازمانی</h1><p>دسترسی شما به سامانه بسته شد. برای پایان ورود در سرویس هویت، ادامه دهید.</p><form id="logout-form" method="post" action="${escape(form.endpoint)}">${Object.entries(form.fields).map(([name, value]) => `<input type="hidden" name="${escape(name)}" value="${escape(value)}">`).join('')}<button type="submit">تکمیل خروج از حساب سازمانی</button></form><script src="/login-handoff.js" defer></script></body></html>`
  } catch { return sendRedirect(event, '/?logout_error=remote_unavailable', 303) }
})
