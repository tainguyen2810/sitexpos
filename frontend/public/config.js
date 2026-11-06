// Runtime configuration (docs/DOCKER.md). The Docker entrypoint overwrites this
// file with the deployment's URLs; classic builds keep the empty default and use
// VITE_API_BASE_URL / VITE_CUSTOMER_APP_URL baked in at build time.
window.__SITEX_CONFIG__ = window.__SITEX_CONFIG__ || {};
