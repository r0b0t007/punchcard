import path from 'node:path';
import { test as base } from '@playwright/test';
import { signIn } from './helpers';

export { expect } from '@playwright/test';

/**
 * Café Hafa's owner (owner@cafe.demo.test), signed in once per worker and
 * per project (Playwright's "authenticate once" pattern), for the specs that
 * work as the owner: they start signed in (CHW-369). Signing in through the
 * form in every test ran the suite into Fortify's limit of five sign-ins a
 * minute per account, and a 429 failed whichever test came next.
 *
 * The session is the worker's, one server-side session for every test it
 * runs: what a test leaves in it, the next one finds. So never for a test
 * that signs out or ends the session, nor one that changes what the session
 * holds (switches business, confirms the password, opens a business): those
 * sign in themselves (signIn).
 */
export const test = base.extend<object, { ownerSession: string }>({
    storageState: ({ ownerSession }, use) => use(ownerSession),
    ownerSession: [
        async ({ browser }, use, workerInfo) => {
            const file = path.join(
                workerInfo.project.outputDir,
                `.auth/owner-${workerInfo.project.name}-${workerInfo.parallelIndex}.json`,
            );
            // A page made from the browser has none of the project's options: give it the base URL.
            const page = await browser.newPage({
                baseURL: workerInfo.project.use.baseURL,
                storageState: { cookies: [], origins: [] },
            });
            await signIn(page, 'owner@cafe.demo.test');
            await page.context().storageState({ path: file });
            await page.close();

            await use(file);
        },
        { scope: 'worker' },
    ],
});
