<script setup lang="ts">
import type { ServiceConfig } from '~/types/service'

defineProps<{
  services: ServiceConfig[]
  title?: string
}>()
</script>

<template>
  <div>
    <h2 v-if="title" class="h5 mb-3">{{ title }}</h2>
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
      <div v-for="service in services" :key="service.id" class="col">
        <ServiceCard :service="service" />
      </div>
    </div>
    <div v-if="services.length === 0" class="text-muted text-center py-4">
      No services in this category
    </div>
  </div>
</template>
