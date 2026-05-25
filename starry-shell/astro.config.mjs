// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import node from '@astrojs/node';

export default defineConfig({
  adapter: node({ mode: 'middleware' }),
  vite: {
    plugins: [tailwindcss()],
  },
});
