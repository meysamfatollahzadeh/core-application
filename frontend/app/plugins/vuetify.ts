import { createVuetify } from 'vuetify'
import { fa } from 'vuetify/locale'
import { aliases, mdi } from 'vuetify/iconsets/mdi-svg'

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.use(createVuetify({
    ssr: true,
    locale: { locale: 'fa', messages: { fa }, rtl: { fa: true } },
    icons: { defaultSet: 'mdi', aliases, sets: { mdi } },
    defaults: {
      VBtn: { elevation: 0, rounded: 'lg' },
      VCard: { elevation: 0, rounded: 'lg' },
    },
    theme: {
      defaultTheme: 'light',
      themes: {
        light: { colors: { primary: '#24577A', secondary: '#526A7B', background: '#F3F6F8', surface: '#FFFFFF', 'on-background': '#253746', 'on-surface': '#253746', success: '#2F6F55' } },
        dark: { dark: true, colors: { primary: '#9AC8E8', secondary: '#B6C6D2', background: '#18232D', surface: '#22313E', 'on-background': '#E4EBF0', 'on-surface': '#E4EBF0', success: '#9CCDB4' } },
      },
    },
  }))
})
