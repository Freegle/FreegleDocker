// Shared stubs for the SysAdmin UX panel tests.
export const uxStubs = {
  NoticeMessage: {
    template: '<div class="notice-message" :class="variant"><slot /></div>',
    props: ['variant'],
  },
  'b-spinner': { template: '<span class="spinner" />' },
  'b-badge': { template: '<span class="badge"><slot /></span>' },
  'b-button': {
    template: '<button class="btn" @click="$emit(\'click\')"><slot /></button>',
  },
  'b-table': {
    template:
      '<table class="table"><tr v-for="(item, i) in items" :key="i"><td v-for="f in fields" :key="f.key">{{ item[f.key] }}</td></tr></table>',
    props: ['items', 'fields'],
  },
  GChart: {
    template: '<div class="gchart" :data-type="type" />',
    props: ['type', 'data', 'options'],
  },
  ModEmailDateFilter: {
    template: '<div class="date-filter" />',
    props: ['loading', 'fetchLabel', 'defaultPreset'],
  },
}
