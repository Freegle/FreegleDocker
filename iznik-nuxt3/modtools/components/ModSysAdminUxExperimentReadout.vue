<template>
  <div class="ux-exp-readout small">
    <template v-if="lines">
      <p v-if="running" class="mb-1">
        <span class="verdict badge" :class="verdictClass">{{ verdict }}</span>
      </p>
      <p class="started mb-1">{{ lines.started }}</p>
      <p v-if="lines.finished" class="finished mb-1">{{ lines.finished }}</p>
      <p v-if="lines.chance" class="chance mb-1">{{ lines.chance }}</p>
      <div v-if="lines.others.length" class="others mb-1">
        <div class="others-label fw-semibold">Other funnels watched</div>
        <ul class="list-unstyled mb-0">
          <li
            v-for="(line, i) in lines.others"
            :key="i"
            :class="{ worried: worried(i) }"
          >
            {{ line }}
          </li>
        </ul>
      </div>
      <p v-if="lines.share" class="share mb-0">{{ lines.share }}</p>
    </template>
    <p v-else-if="running" class="none text-muted mb-0">{{ NO_READ_YET }}</p>
    <p v-else class="none text-muted mb-0">
      {{ experiment.decision || 'No figures were kept.' }}
    </p>
  </div>
</template>

<script setup>
// The numbers of one experiment in plain words: where the comparison stands, how many started and
// finished with and without the change, the chance the change is better, how the other things
// watched are doing, and a warning when the share of starts is not what was served. The figures come
// from the experiment's `result`, for a running experiment and a finished one alike. A `result` with
// no `arms` is no read yet, which is what an experiment shows just after a start or a restart.
import { computed } from 'vue'
import {
  NO_READ_YET,
  readLines,
  readOf,
  verdictOf,
} from '~/modtools/composables/useUxExperimentRead'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
})

const running = computed(() => props.experiment.status === 'running')
const read = computed(() => readOf(props.experiment))
const lines = computed(() => readLines(read.value))
const verdict = computed(() => verdictOf(read.value))

// Green when ahead, red when behind, and plain grey otherwise.
const verdictClass = computed(
  () =>
    ({
      'Change is ahead': 'bg-success',
      'Change is behind': 'bg-danger',
    })[verdict.value] || 'bg-secondary'
)

// A line for a guardrail that may be worse stands out.
const worried = (i) => read.value.guardrails[i].pWorse >= 0.5
</script>

<style scoped lang="scss">
.worried {
  color: #664d03;
  font-weight: 600;
}

.share {
  padding: 0.35rem 0.5rem;
  border-left: 3px solid #ffc107;
  background: #fff8e1;
  color: #4d3b02;
}
</style>
