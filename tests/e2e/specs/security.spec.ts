import { expect, test } from '@playwright/test';

test.describe('HTTP hardening', () => {
  test('security headers are set and the server version is hidden', async ({ request }) => {
    const res = await request.get('/login.php');
    expect(res.status()).toBe(200);
    const headers = res.headers();
    expect(headers['server']).toBe('Apache');
    expect(headers['x-powered-by']).toBeUndefined();
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['x-frame-options']).toBe('SAMEORIGIN');
    expect(headers['referrer-policy']).toBe('same-origin');
    expect(headers['set-cookie']).toMatch(/HttpOnly/i);
    expect(headers['set-cookie']).toMatch(/SameSite=Lax/i);
  });

  test('internal files are not reachable', async ({ request }) => {
    expect((await request.get('/lib/config.php')).status()).toBe(403);
    expect((await request.get('/lib/migrate.php')).status()).toBe(403);
    expect((await request.get('/info.php')).status()).toBe(404);
    expect((await request.get('/composer.json')).status()).toBe(404);
    expect((await request.get('/vendor/autoload.php')).status()).toBe(404);
    expect((await request.get('/uploads/.tmp/')).status()).toBe(403);
  });

  test('health check reports ok', async ({ request }) => {
    const res = await request.get('/healthz.php');
    expect(res.status()).toBe(200);
    expect(await res.text()).toBe('ok');
  });

  test('state-changing requests without CSRF token are rejected', async ({ request }) => {
    const res = await request.post('/login.php', { form: { email: 'x@example.com', password: 'x' } });
    expect(res.status()).toBe(403);
  });

  test('pages behind login redirect to the login page', async ({ page }) => {
    for (const path of ['/admin.php', '/gallery.php?event=00000000-0000-4000-8000-000000000000']) {
      await page.goto(path);
      await expect(page).toHaveURL(/login\.php/);
    }
  });

  test('invalid event ids are rejected', async ({ request }) => {
    const res = await request.get("/index.php?event=1' OR '1'='1");
    expect(res.status()).toBe(404);
  });
});
