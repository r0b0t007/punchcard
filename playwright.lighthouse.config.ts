import { defineConfig } from '@playwright/test';
import base from './playwright.config';

/**
 * Lighthouse on C2, C5 and B3 (tests/lighthouse, CHW-35): `npm run
 * lighthouse`, and the lighthouse workflow. The e2e config's server, one
 * browser of its own (the spec launches it, for Lighthouse to drive), one
 * test at a time.
 */
export default defineConfig({
    ...base,
    testDir: './tests/lighthouse',
    fullyParallel: false,
    workers: 1,
    // Each screen is one Lighthouse run, slow on a dev server.
    timeout: 300_000,
    reporter: process.env.CI
        ? [
              ['github'],
              ['html', { outputFolder: 'lighthouse-report', open: 'never' }],
          ]
        : [
              ['list'],
              ['html', { outputFolder: 'lighthouse-report', open: 'never' }],
          ],
    projects: [{ name: 'lighthouse' }],
});
