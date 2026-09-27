import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { admin, createEvent, fixture, login, unique } from '../helpers';

const photo = (name: string) => ({ name, mimeType: 'image/jpeg', buffer: readFileSync(fixture('photo.jpg')) });

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
    await page.locator('#inp-gal').setInputFiles(fixture('photo.jpg')); // uploads right away
    await expect(page.locator('#upload-status')).toHaveText('Bild ist auf der Leinwand! 🥳');
    await expect(page.locator('#upload-status')).toHaveClass(/success/);
    await expect(page.locator('#uploader-name')).toHaveValue('Lena <3'); // remembered on the device
  });

  test('several photos can be selected and are uploaded one by one', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    await page.locator('#uploader-name').fill('Multi');
    await page.locator('#inp-gal').setInputFiles([photo('a.jpg'), photo('b.jpg'), photo('c.jpg')]);

    await expect(page.locator('#upload-status')).toHaveText('3 Bilder sind auf der Leinwand! 🥳');
    await expect(page.locator('#upload-bar')).toHaveAttribute('style', /width: 100%/);
    await expect(page.locator('#upload-retry')).toBeHidden();
  });

  test('failed photos are listed and can be retried, the others are uploaded', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    await page.locator('#inp-gal').setInputFiles([
      photo('ok-1.jpg'),
      { name: 'kaputt.jpg', mimeType: 'image/jpeg', buffer: readFileSync(fixture('disguised.jpg')) },
      photo('ok-2.jpg'),
    ]);

    await expect(page.locator('#upload-status')).toHaveText('2 von 3 Bildern hochgeladen, 1 fehlgeschlagen.');
    await expect(page.locator('#upload-status')).toHaveClass(/error/);
    await expect(page.locator('#upload-errors li')).toHaveText([/kaputt\.jpg: .*kein gültiges Bild/]);

    await page.getByRole('button', { name: /Fehlgeschlagene erneut hochladen/ }).click();
    await expect(page.locator('#upload-status')).toHaveText('0 von 1 Bildern hochgeladen, 1 fehlgeschlagen.');
    await expect(page.locator('#upload-errors li')).toHaveCount(1);
  });

  test('at most 30 photos are uploaded per selection', async ({ page }) => {
    test.slow();
    await page.goto(`/index.php?event=${uuid}`);
    const files = Array.from({ length: 32 }, (_, i) => photo(`bulk-${i}.jpg`));
    await page.locator('#inp-gal').setInputFiles(files);

    await expect(page.locator('#upload-status')).toHaveText(
      '30 Bilder sind auf der Leinwand! 🥳 2 weitere wurden nicht hochgeladen (max. 30 pro Auswahl).',
      { timeout: 90_000 },
    );
  });

  test('camera upload still works as a single photo', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    await page.locator('#inp-cam').setInputFiles(fixture('photo.jpg')); // classic form post
    await expect(page.locator('.container > .msg.success')).toContainText('Bild ist auf der Leinwand');
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
