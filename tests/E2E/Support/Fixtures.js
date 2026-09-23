/**
 * The Playwright `test` and `expect` for the browser tests. Every test
 * automatically fails when:
 *
 * - the page throws an uncaught JavaScript error or logs a console error, for
 *   example a script that failed to load. In compatibility runs, errors the
 *   third-party plugin also causes without Cloudflare (BROWSER_ERROR_BASELINE)
 *   are listed in the report instead;
 * - the plugin made a Cloudflare API call the mock has no response for.
 */

const base = require('@playwright/test');
const { apiCalls, clearApiLog } = require('./Environment');
const { baselineErrors, collectErrors } = require('./BrowserErrors');

const baseline = baselineErrors();

const test = base.test.extend({
	javascriptErrors: [
		async ({ page }, use, testInfo) => {
			const errors = collectErrors(page);

			await use(errors);

			const known = errors.filter((error) => baseline.has(error));
			for (const error of known) {
				testInfo.annotations.push({ type: 'Also happens without Cloudflare', description: error });
			}
			base.expect(
				errors.filter((error) => !baseline.has(error)),
				'JavaScript errors on the page'
			).toEqual([]);
		},
		{ auto: true },
	],

	unmockedApiCalls: [
		async ({}, use) => {
			clearApiLog();

			await use();

			const unmocked = apiCalls()
				.filter((call) => !call.mocked)
				.map((call) => `${call.method} ${call.path}`);
			base.expect(unmocked, 'Cloudflare API calls without a mocked response').toEqual([]);
		},
		{ auto: true },
	],
});

module.exports = { test, expect: base.expect };
