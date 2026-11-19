import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        passWithNoTests: true,
        include: ['assets/js/tests/**/*.{test,spec}.ts'],
    },
});
