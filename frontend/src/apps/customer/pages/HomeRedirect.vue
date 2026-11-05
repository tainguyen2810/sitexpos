<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import LoadingState from '@/shared/ui/LoadingState.vue'
import { homeTarget } from '../router'

const router = useRouter()
onMounted(() => void router.replace(homeTarget()))
</script>

<template>
  <LoadingState />
</template>
