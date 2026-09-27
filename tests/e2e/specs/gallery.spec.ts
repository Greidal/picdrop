import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { admin, createEvent, fixture, login, unique } from '../helpers';

// Latitude-seconds denominator 0x5EC7E7ED in gps.jpg (little endian), see fixtures/generate.php.
const GPS_MARKER = Buffer.from([0xed, 0xe7, 0xc7, 0x5e]);

/** Uploads a photo the way a guest does (multipart POST to the public upload page). */
async function guestUpload(request: APIRequestContext, uuid: string, file: string, uploader = 'E2E Gast') {
  const res = await request.post(`/index.php?event=${uuid}`, {
    multipart: {
      uploader,
      device_uuid: `dev_e2e_${unique()}`,
      image: { name: file, mimeType: 'image/jpeg', buffer: readFileSync(fixture(file)) },
    },
    maxRedirects: 0,
  });
  expect(res.status(), 'upload redirects on success').toBe(302);
}

/** URL of the original file behind the n-th gallery item (via the lightbox download link). */
async function originalUrl(page: Page, index = 0): Promise<string> {
  await page.locator('.gallery-item').nth(index).click();
  const href = await page.locator('#lb-download').getAttribute('href');
  await page.locator('.lb-close').click();
  return href!;
}

test.describe('gallery', () => {
  let uuid: string;

  test.beforeEach(async ({ page }) => {
    await login(page, admin.email, admin.password);
    uuid = await createEvent(page, `Galerie ${unique()}`);
  });

  test('grid shows thumbnails, lightbox shows display size and links the original', async ({ page, request }) => {
    await guestUpload(request, uuid, 'photo.jpg', 'Anna');
    await page.goto(`/gallery.php?event=${uuid}`);

    const thumb = page.locator('.gallery-item img').first();
    await expect(thumb).toHaveAttribute('src', /\.variants\/thumb\/.+\.webp$/);
    const thumbRes = await page.request.get(await thumb.getAttribute('src') as string);
    expect(thumbRes.headers()['content-type']).toBe('image/webp');
    expect((await thumbRes.body()).length).toBeLessThan(30_000);
    expect(thumbRes.headers()['cache-control']).toContain('immutable');

    await page.locator('.gallery-item').first().click();
    await expect(page.locator('#lightbox')).toHaveClass(/active/);
    await expect(page.locator('#lb-image')).toHaveAttribute('src', /\.variants\/display\/.+\.webp$/);
    await expect(page.locator('#lb-download')).toHaveAttribute('href', /^uploads\/[0-9a-f-]{36}\/[^/]+\.jpg$/);
    await expect(page.locator('#lb-text')).toHaveText('Anna');
    await page.keyboard.press('Escape');
    await expect(page.locator('#lightbox')).not.toHaveClass(/active/);
  });

  test('photos can be deleted', async ({ page, request }) => {
    await guestUpload(request, uuid, 'photo.jpg');
    await page.goto(`/gallery.php?event=${uuid}`);
    const original = await originalUrl(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('.gallery-item .btn-delete').first().click();
    await expect(page.locator('.gallery-item')).toHaveCount(0);

    await page.reload();
    await expect(page.getByText('Noch keine Fotos vorhanden.')).toBeVisible();
    expect((await page.request.get(original)).status()).toBe(404);
  });

  test('ZIP export contains the photos and the CSV', async ({ page, request }) => {
    await guestUpload(request, uuid, 'photo.jpg', '=HYPERLINK("http://evil")');
    await page.goto('/admin.php');
    const card = page.locator('.card').filter({ has: page.locator(`a[href="gallery.php?event=${uuid}"]`) });

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      card.getByRole('link', { name: /ZIP-Download/ }).click(),
    ]);
    const zip = readFileSync((await download.path())!);
    expect(zip.includes(Buffer.from('datenbank_export.csv'))).toBe(true);
    expect(zip.includes(Buffer.from('.jpg'))).toBe(true);
    expect(zip.includes(Buffer.from('.variants'))).toBe(false);
  });

  test('GPS data is kept by default and removed once the event setting is enabled', async ({ page, request }) => {
    await guestUpload(request, uuid, 'gps.jpg');
    await page.goto(`/gallery.php?event=${uuid}`);
    const first = await originalUrl(page);
    expect((await (await page.request.get(first)).body()).includes(GPS_MARKER), 'default: GPS kept').toBe(true);

    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.getByText('Standortdaten (GPS) aus Fotos entfernen').click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.locator('.msg.success')).toContainText('Standortdaten aus 1 vorhandenen Fotos entfernt');

    const cleaned = await (await page.request.get(first)).body();
    expect(cleaned.includes(GPS_MARKER), 'existing photo cleaned').toBe(false);
    expect(cleaned.subarray(0, 2).equals(Buffer.from([0xff, 0xd8])), 'still a JPEG').toBe(true);

    await guestUpload(request, uuid, 'gps.jpg');
    await page.goto(`/gallery.php?event=${uuid}`);
    await expect(page.locator('.gallery-item')).toHaveCount(2);
    for (const i of [0, 1]) {
      const body = await (await page.request.get(await originalUrl(page, i))).body();
      expect(body.includes(GPS_MARKER), `photo ${i} without GPS`).toBe(false);
    }
  });

  test('uploads are not executed as PHP', async ({ request }) => {
    const res = await request.post(`/index.php?event=${uuid}`, {
      multipart: {
        device_uuid: 'dev_attacker',
        image: { name: 'shell.php', mimeType: 'image/jpeg', buffer: readFileSync(fixture('disguised.jpg')) },
      },
    });
    expect(await res.text()).toContain('kein gültiges Bild');
  });
});
