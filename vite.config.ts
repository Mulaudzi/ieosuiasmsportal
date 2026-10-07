/// <reference types="vitest" />
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";
import { readFileSync } from "node:fs";

// https://vitejs.dev/config/
export default defineConfig(() => ({
  server: {
    host: "::",
    port: 8080,
    // Handle SPA routing in dev mode - always serve index.html for non-asset routes
    historyApiFallback: true,
  },
  preview: {
    port: 8080,
  },
  plugins: [react(), {
    name: "receipt-branding",
    generateBundle() {
      // Stable server-rendered receipt asset; identical bytes to the supplied PNG.
      this.emitFile({
        type: "asset",
        fileName: "branding/ieosuia-sms-portal-light.png",
        source: readFileSync(path.resolve(__dirname, "src/assets/ieosuia-sms-portal-light.png")),
      });
      this.emitFile({
        type: "asset",
        fileName: "branding/ieosuia-sms-portal-navy.png",
        source: readFileSync(path.resolve(__dirname, "src/assets/ieosuia-sms-portal-navy.png")),
      });
    },
  }],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  // Ensure proper base path
  base: "/",
  build: { sourcemap: false },
  // Vitest configuration
  test: {
    globals: true,
    environment: "jsdom",
    setupFiles: ["./src/tests/setup.ts"],
    include: ["src/**/*.{test,spec}.{js,mjs,cjs,ts,mts,cts,jsx,tsx}"],
    exclude: ["node_modules", "dist"],
    coverage: {
      reporter: ["text", "json", "html"],
      exclude: ["node_modules/", "src/tests/"],
    },
  },
}));
