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
        light: { colors: { primary: '#24577A', secondary: '#526A7B', background: '#F3F6F8', surface: '#FFFFFF', 'on-background': '#253746', 'on-surface': '#253746', success: '#087F83', info: '#2563A6', warning: '#9A5B08', navigation: '#183A52', 'on-navigation': '#E7F0F7' } },
        dark: { dark: true, colors: { primary: '#9AC8E8', secondary: '#B6C6D2', background: '#18232D', surface: '#22313E', 'on-background': '#E4EBF0', 'on-surface': '#E4EBF0', success: '#82D5C9', info: '#9AC8FF', warning: '#F1C477', navigation: '#111E2A', 'on-navigation': '#E7F0F7' } },
      },
    },
  }))
})
