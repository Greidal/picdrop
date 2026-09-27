import { expect, test } from '@playwright/test';
import { admin, createEvent, fixture, login, unique } from '../helpers';

let uuid: string;
let eventName: string;

test.beforeAll(async ({ browser }) => {
  const page = await browser.newPage();
  await login(page, admin.email, admin.password);
  eventName = `Handy-Party ${unique()}`;
  uuid = await createEvent(page, eventName);
  await page.close();
});

test.describe('guest upload page (phone)', () => {
  test('guest uploads a photo from the gallery picker', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    await expect(page.getByRole('heading', { name: eventName })).toBeVisible();

    await page.locator('#uploader-name').fill('Lena <3');
    await page.locator('#inp-gal').setInputFiles(fixture('photo.jpg')); // auto-submits
    await expect(page.locator('.msg.success')).toContainText('Bild ist auf der Leinwand');
    await expect(page.locator('#uploader-name')).toHaveValue('Lena <3'); // remembered on the device
  });

  test('non-images are rejected with a friendly message', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    await page.locator('#inp-gal').setInputFiles(fixture('disguised.jpg'));
    await expect(page.locator('.msg.error')).toContainText('kein gültiges Bild');
  });

  test('guest can send an emoji reaction', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    const response = page.waitForResponse((r) => r.url().includes('reaction_api.php?action=send'));
    await page.getByRole('button', { name: '🔥' }).click();
    expect(await (await response).json()).toEqual({ status: 'ok' });
  });

  test('unknown events show an error instead of an upload form', async ({ page }) => {
    const res = await page.goto('/index.php?event=00000000-0000-4000-8000-000000000000');
    expect(res?.status()).toBe(404);
    await expect(page.locator('#inp-gal')).toHaveCount(0);
  });
});
