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
  await page.goto(`/manage_event.php?event=${uuid}`);
  await page.locator('#drink-template').selectOption('Helles');
  await page.locator('#add-drink').getByRole('button').click();
  await expect(page.locator('.msg.success')).toHaveText('Getränk hinzugefügt!');
  await page.getByLabel('Name des neuen Teams').fill('Die Durstigen');
  await page.getByRole('button', { name: 'Team anlegen' }).click();
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

test.describe('drink mode (phone)', () => {
  test('drink check-in and "Mein Pegel" are buttons at the bottom of the guest page', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}`);
    await expect(page.locator('.guest-tabs')).toHaveCount(0);
    await expect(page.getByRole('link', { name: '📈 Mein Pegel' })).toHaveAttribute('href', `pegel.php?event=${uuid}`);

    await page.getByRole('link', { name: '🍺 Getränk einchecken' }).click();
    await expect(page.getByRole('heading', { name: 'Was trinkst du?' })).toBeVisible();
    await page.getByRole('link', { name: '🔙 Zurück' }).click();
    await expect(page.getByRole('heading', { name: eventName })).toBeVisible();

    await page.getByRole('link', { name: '📈 Mein Pegel' }).click();
    await expect(page).toHaveURL(/pegel\.php/);
    await page.getByRole('link', { name: '🔙 Zurück' }).click();
    await expect(page.getByRole('heading', { name: eventName })).toBeVisible();
  });

  test('guest checks in a drink for themselves and as a round for a friend', async ({ page, request }) => {
    // a friend who is already at the party
    const friend = `dev_friend_${unique()}`;
    await request.post(`/drink_api.php?action=profile&event=${uuid}`, { multipart: { device_uuid: friend, name: 'Freundin' } });

    await page.goto(`/index.php?event=${uuid}#drink`);
    await page.locator('#uploader-name').fill('Sepp');
    await page.getByRole('button', { name: 'Helles einchecken' }).click();
    await expect(page.locator('#ci-title')).toHaveText('Helles');
    await expect(page.locator('#ci-ml-out')).toHaveText('500 ml');

    await page.getByRole('button', { name: /Maß/ }).click();
    await expect(page.locator('#ci-ml-out')).toHaveText('1 l');
    await page.locator('#ci-people').getByRole('button', { name: 'Freundin' }).click();
    await page.getByRole('button', { name: '✅ Einchecken' }).click();
    await expect(page.locator('#guest-toast')).toHaveText('Prost! 🍻 Runde für dich und 1 weitere eingecheckt.');

    const state = await request.post(`/drink_api.php?action=state&event=${uuid}`, { multipart: { device_uuid: friend } });
    const body = await state.json();
    expect(body.history).toHaveLength(1);
    expect(body.history[0]).toMatchObject({ drink: 'Helles', volume_ml: 1000, from: 'Sepp' });
  });

  test('"Mein Pegel" shows the estimate, history, badges and lets the guest join a team', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}#drink`);
    await page.locator('#uploader-name').fill('Pegel-Paul');
    await page.getByRole('button', { name: 'Helles einchecken' }).click();
    await page.getByRole('button', { name: '✅ Einchecken' }).click();
    await expect(page.locator('#guest-toast')).toContainText('Prost!');

    await page.getByRole('link', { name: '📈 Mein Pegel' }).click();
    await expect(page.locator('#bac-sub')).toContainText('Höchstwert');
    await expect(page.locator('#t-sober')).toContainText('Uhr');
    await expect(page.locator('#history li')).toHaveCount(1);
    await expect(page.locator('#history li').first()).toContainText('Helles');
    await expect(page.locator('#chart path')).not.toHaveCount(0);
    await expect(page.locator('.badge.earned').filter({ hasText: 'Erste Runde' })).toBeVisible();

    await page.locator('#p-team').selectOption({ label: 'Die Durstigen (0)' });
    await expect(page.locator('#guest-toast')).toHaveText('Willkommen im Team! 🤝');
    await page.getByRole('tab', { name: 'Teams' }).click();
    await expect(page.locator('#board .rank-row.me')).toContainText('Die Durstigen');

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Eintrag löschen' }).click();
    await expect(page.locator('#history')).toContainText('Hier erscheinen deine Getränke.');
  });

  test('the evening can be exported as a story image', async ({ page }) => {
    await page.goto(`/index.php?event=${uuid}#drink`);
    await page.locator('#uploader-name').fill('Story-Steffi');
    await page.getByRole('button', { name: 'Helles einchecken' }).click();
    await page.getByRole('button', { name: '✅ Einchecken' }).click();
    await expect(page.locator('#guest-toast')).toContainText('Prost!');

    await page.goto(`/story.php?event=${uuid}`);
    await expect.poll(() => page.locator('#story').evaluate((c: HTMLCanvasElement) => c.toDataURL().length)).toBeGreaterThan(50_000);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: '⬇️ Bild speichern' }).click()]);
    expect(download.suggestedFilename()).toBe('mein-abend.png');
  });

  test('check-ins need a name and a known drink', async ({ request }) => {
    const res = await request.post(`/drink_api.php?action=checkin&event=${uuid}`, { multipart: { device_uuid: 'dev_noname_1', drink_id: '1' } });
    expect(res.status()).toBe(400);
    const bad = await request.post(`/drink_api.php?action=checkin&event=${uuid}`, { multipart: { device_uuid: 'dev_noname_1', name: 'X', drink_id: '999999' } });
    expect(bad.status()).toBe(400);
    expect((await request.get(`/drink_api.php?action=state&event=${uuid}`)).status()).toBe(405);
  });
});
