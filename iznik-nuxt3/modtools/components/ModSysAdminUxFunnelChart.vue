<template>
  <div class="funnel-chart">
    <h6 v-if="title" class="mb-1">{{ title }}</h6>
    <svg
      :viewBox="`0 0 ${width} ${height}`"
      :width="width"
      :height="height"
      role="img"
      :aria-label="`Funnel: ${ariaText}`"
      class="d-block mw-100"
    >
      <g v-for="(s, i) in steps" :key="s.step" :transform="`translate(0, ${i * rowH})`">
        <!-- Previous period, as an outline behind the bar. -->
        <rect
          v-if="prev[s.step]"
          :x="labelW"
          :y="pad"
          :width="Math.max(1, prev[s.step] * barW)"
          :height="barH"
          class="bar-prev"
        />
        <rect
          :x="labelW"
          :y="pad"
          :width="Math.max(2, s.reach * barW)"
          :height="barH"
          :class="['bar', { worst: s.step === worstStep, active: s.step === selected }]"
          role="button"
          tabindex="0"
          @click="emit('select', s.step)"
          @keydown.enter="emit('select', s.step)"
        />
        <!-- The loss at this step, drawn in red at the end of the bar. -->
        <rect
          v-if="s.abandoned"
          :x="labelW + Math.max(2, s.reach * barW) - lossWidth(s)"
          :y="pad"
          :width="lossWidth(s)"
          :height="barH"
          class="bar-loss"
        />
        <text :x="labelW - 8" :y="pad + barH / 2" class="label" text-anchor="end" dominant-baseline="middle">
          {{ s.label }}
        </text>
        <text
          :x="labelW + Math.max(2, s.reach * barW) + 8"
          :y="pad + barH / 2"
          class="value"
          dominant-baseline="middle"
        >
          {{ s.entered.toLocaleString() }} reached<template v-if="s.abandoned">, {{ s.abandoned.toLocaleString() }} left here ({{ Math.round(s.lossRate * 100) }}%)</template>
        </text>
      </g>
    </svg>
  </div>
</template>

<script setup>
// One funnel as bars: width is the share of people who reached the step, the red end of each
// bar is the share of its visits that ended there, the grey outline is the previous period.
// Inline SVG, no library: it must read the same in a screenshot as on the page.
import { computed } from 'vue'

const props = defineProps({
  title: { type: String, default: '' },
  // From useUxFunnel funnelsFor(): [{ step, label, entered, visits, abandoned, reach, lossRate }]
  steps: { type: Array, required: true },
  // step -> reach in the previous period, for the outline.
  previousReach: { type: Object, default: () => ({}) },
  selected: { type: String, default: '' },
})

const emit = defineEmits(['select'])

const labelW = 190
const barW = 320
const valueW = 260
const rowH = 34
const barH = 22
const pad = 6
const width = labelW + barW + valueW
const height = computed(() => props.steps.length * rowH)
const prev = computed(() => props.previousReach || {})

const worstStep = computed(() => {
  let worst = null
  for (const s of props.steps) {
    if (s.visits >= 20 && s.abandoned && (!worst || s.lossRate > worst.lossRate)) {
      worst = s
    }
  }
  return worst?.step || ''
})

function lossWidth(s) {
  return Math.min(Math.max(2, s.reach * barW), Math.max(2, s.lossRate * s.reach * barW))
}

const ariaText = computed(() =>
  props.steps
    .map((s) => `${s.label} ${s.entered} reached, ${s.abandoned} left`)
    .join('; ')
)
</script>

<style scoped lang="scss">
.bar {
  fill: #61ae24;
  cursor: pointer;

  &.worst {
    fill: #c98a1e;
  }

  &.active {
    stroke: #212529;
    stroke-width: 2;
  }
}

.bar-prev {
  fill: none;
  stroke: #adb5bd;
  stroke-dasharray: 3 2;
}

.bar-loss {
  fill: #dc3545;
  pointer-events: none;
}

.label {
  font-size: 12px;
  fill: #212529;
}

.value {
  font-size: 11px;
  fill: #495057;
}
</style>
