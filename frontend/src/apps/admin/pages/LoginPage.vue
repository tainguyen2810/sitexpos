<script setup lang="ts">
import LoginPage from '@/shared/auth/LoginPage.vue'
</script>

<template>
  <LoginPage :subtitle="$t('auth.adminLoginSubtitle')" home="/" />
</template>
