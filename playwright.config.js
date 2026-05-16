const { defineConfig } = require("@playwright/test");

module.exports = defineConfig({
  testDir: "./tests",
  retries: 0,
  timeout: 30_000,
  use: {
    baseURL: "http://127.0.0.1:4173"
  },
  webServer: {
    command: "npx http-server . -p 4173 -c-1 --silent",
    port: 4173,
    reuseExistingServer: true,
    timeout: 30_000
  }
});
