import vuetify from 'vite-plugin-vuetify'

export default defineNuxtConfig({
  compatibilityDate: '2026-11-02',
  devtools: { enabled: false },
  runtimeConfig: {
    authCookieKey: '',
    backendUrl: 'http://backend:8000',
    appOrigin: 'http://localhost:8081',
  },
  css: ['vuetify/styles', '~/assets/styles/main.css'],
  build: { transpile: ['vuetify'] },
  vite: {
    plugins: [vuetify({ autoImport: true })],
    server: { ws: { clientPort: 8081 } },
  },
  app: {
    head: {
      htmlAttrs: { lang: 'fa', dir: 'rtl' },
      title: 'سامانه یکپارچه سازمان',
      meta: [{ name: 'description', content: 'سامانه یکپارچه سازمان' }],
    },
  },
})
