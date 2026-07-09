<script setup>
import { computed } from 'vue'
import { statusClass, statusLabel } from './format'

const props = defineProps({
    status: { type: String, default: '' },
})

const cls = computed(() => statusClass(props.status))
const label = computed(() => statusLabel(props.status))
</script>

<template>
    <span class="badge" :class="cls">{{ label }}</span>
</template>
