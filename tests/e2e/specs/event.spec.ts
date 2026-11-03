import { expect, test } from '@playwright/test';
import { admin, createEvent, csrfToken, env, extractLink, login, unique, waitForMail } from '../helpers';

test.describe('event management', () => {
  test.beforeEach(async ({ page }) => {
    // Any JS dialog here would mean injected script ran.
    page.on('dialog', (dialog) => {
      throw new Error(`unexpected dialog: ${dialog.message()}`);
    });
    await login(page, admin.email, admin.password);
  });

  test('event names are rendered as text, not HTML', async ({ page }) => {
    const name = `<img src=x onerror=alert(1)> & 'Party' ${unique()}`;
    await createEvent(page, name);
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
    await expect(page.locator('img[src="x"]')).toHaveCount(0);
  });

  test('share link uses APP_URL without double slash, QR code is generated locally', async ({ page }) => {
    const uuid = await createEvent(page, `Share ${unique()}`);
    await page.goto(`/manage_event.php?event=${uuid}`);

    await expect(page.locator('#shareLink')).toHaveValue(`${env.APP_URL}/?event=${uuid}`);
    const qr = page.getByAltText('QR-Code zum Event');
    await expect(qr).toHaveAttribute('src', /^data:image\/svg\+xml;base64,/);
  });

  test('event can be renamed', async ({ page }) => {
    const uuid = await createEvent(page, `Rename ${unique()}`);
    const newName = `Umbenannt ${unique()}`;
    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.getByRole('button', { name: 'Namen bearbeiten' }).click();
    await page.locator('#eventName').fill(newName);
    await page.getByRole('button', { name: 'Umbenennen' }).click();
    await expect(page.locator('.msg.success')).toHaveText('Event erfolgreich umbenannt!');
    await expect(page.getByRole('heading', { level: 1 })).toContainText(newName);
  });

  test('invited people can register without the registration code and see the event', async ({ page, request, browser }) => {
    const eventName = `Invite ${unique()}`;
    const uuid = await createEvent(page, eventName);
    const email = `invitee-${unique()}@picdrop.test`;

    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.getByPlaceholder('gast@beispiel.de').fill(email);
    await page.getByRole('button', { name: 'Einladen' }).click();
    await expect(page.locator('.msg.success')).toContainText('Einladungs-Link');
    await expect(page.getByText(`${email} (Wartet auf Registrierung...)`)).toBeVisible();

    const inviteLink = extractLink(await waitForMail(request, email, /Einladung zu/), 'register.php');

    // the invitee uses a fresh browser session
    const context = await browser.newContext();
    const guest = await context.newPage();
    await guest.goto(inviteLink);
    await expect(guest.getByText('Du wurdest eingeladen!')).toBeVisible();
    await expect(guest.getByPlaceholder('Registrierungs-Code')).toHaveCount(0);
    await guest.getByPlaceholder('E-Mail Adresse').fill(email);
    await guest.getByPlaceholder('Anzeigename').fill(`i${unique()}`.slice(0, 20));
    await guest.getByPlaceholder(/Passwort/).fill('invitee-password-1');
    await guest.getByRole('button', { name: 'Registrieren' }).click();
    await expect(guest.locator('.msg.success')).toContainText('Account erstellt');

    await guest.goto(extractLink(await waitForMail(request, email, /bestätige/), 'verify.php'));
    await login(guest, email, 'invitee-password-1');
    await expect(guest.getByRole('heading', { name: eventName, exact: true })).toBeVisible();
    await expect(guest.getByText('ADMIN', { exact: true })).toHaveCount(0);
    await context.close();
  });

  test('users cannot open events they were not invited to', async ({ page, browser, request }) => {
    const uuid = await createEvent(page, `Private ${unique()}`);

    const email = `outsider-${unique()}@picdrop.test`;
    const context = await browser.newContext();
    const other = await context.newPage();
    await other.goto('/register.php');
    await other.getByPlaceholder('E-Mail Adresse').fill(email);
    await other.getByPlaceholder('Anzeigename').fill(`o${unique()}`.slice(0, 20));
    await other.getByPlaceholder(/Passwort/).fill('outsider-password-1');
    await other.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
    await other.getByRole('button', { name: 'Registrieren' }).click();
    await other.goto(extractLink(await waitForMail(request, email, /bestätige/), 'verify.php'));
    await login(other, email, 'outsider-password-1');

    for (const path of ['manage_event.php', 'gallery.php', 'download_zip.php']) {
      const res = await other.goto(`/${path}?event=${uuid}`);
      expect(res?.status(), path).toBe(403);
    }
    await context.close();
  });
});

test.describe('event settings', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, admin.email, admin.password);
  });

  test('QR code can be downloaded as PNG and SVG', async ({ page }) => {
    const uuid = await createEvent(page, `QR ${unique()}`);
    await page.goto(`/manage_event.php?event=${uuid}`);

    for (const [format, magic] of [['PNG', '\x89PNG'], ['SVG', '<?xml']]) {
      const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: format }).click()]);
      expect(download.suggestedFilename()).toMatch(new RegExp(`^QR_QR_.+\\.${format.toLowerCase()}$`));
      const body = (await import('node:fs')).readFileSync((await download.path())!, 'latin1');
      expect(body.startsWith(magic)).toBe(true);
    }
  });

  test('drink menu: add, edit and delete drinks with amount and alcohol content', async ({ page }) => {
    const uuid = await createEvent(page, `Bar ${unique()}`);
    await page.goto(`/manage_event.php?event=${uuid}`);

    await page.locator('#drink-template').selectOption('Aperol Spritz');
    await expect(page.getByLabel('Name des neuen Getränks')).toHaveValue('Aperol Spritz');
    await page.locator('#add-drink').getByRole('button').click();
    await expect(page.locator('.msg.success')).toHaveText('Getränk hinzugefügt!');

    const drinkRow = page.locator('form.drink-row').filter({ has: page.locator('input[value="Aperol Spritz"]') });
    await expect(drinkRow.getByLabel('Standardmenge in ml')).toHaveValue('250');
    await expect(drinkRow.getByLabel('Alkoholgehalt in Vol-%')).toHaveValue('8');
    await drinkRow.getByLabel('Alkoholgehalt in Vol-%').fill('11');
    await drinkRow.getByRole('button', { name: 'Änderungen übernehmen' }).click();
    await expect(page.locator('.msg.success')).toContainText('gespeichert');
    await expect(drinkRow.getByLabel('Alkoholgehalt in Vol-%')).toHaveValue('11');

    page.once('dialog', (dialog) => dialog.accept());
    await drinkRow.getByRole('button', { name: 'Getränk löschen' }).click();
    await expect(page.locator('.msg.success')).toHaveText('Getränk gelöscht.');
    await expect(page.locator('form.drink-row:not(.add)')).toHaveCount(0);
  });

  test('"Mein Pegel" has its own switch, check-ins keep working without it', async ({ page, request }) => {
    const uuid = await createEvent(page, `Pegel ${unique()}`);
    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.locator('#drink-template').selectOption('Helles');
    await page.locator('#add-drink').getByRole('button').click();
    const drinkId = await page.locator('form.drink-row:not(.add)').getByRole('button', { name: 'Getränk löschen' }).getAttribute('value');
    await page.goto(`/index.php?event=${uuid}`);
    await expect(page.getByRole('link', { name: '📈 Mein Pegel' })).toBeVisible();

    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.getByText('„Mein Pegel“ für Gäste').click();
    await page.getByRole('button', { name: 'Speichern' }).click();
    await expect(page.locator('.msg.success')).toContainText('gespeichert');

    await page.goto(`/index.php?event=${uuid}`);
    await expect(page.getByRole('link', { name: '🍺 Getränk einchecken' })).toBeVisible();
    await expect(page.getByRole('link', { name: '📈 Mein Pegel' })).toHaveCount(0);
    await page.goto(`/pegel.php?event=${uuid}`);
    await expect(page).toHaveURL(/index\.php/);

    const device = `dev_nopegel_${unique()}`;
    expect((await request.post(`/drink_api.php?action=state&event=${uuid}`, { multipart: { device_uuid: device } })).status()).toBe(403);
    const checkin = await request.post(`/drink_api.php?action=checkin&event=${uuid}`, { multipart: { device_uuid: device, name: 'Sepp', drink_id: drinkId! } });
    expect(checkin.status()).toBe(200);
    const board = await (await page.request.get(`/get_leaderboard_data.php?event=${uuid}`)).json();
    expect(board.users[0]).toMatchObject({ uploader_name: 'Sepp', bac: null });
  });

  test('deleting a drink keeps its check-ins and points', async ({ page, request }) => {
    const uuid = await createEvent(page, `Soft ${unique()}`);
    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.locator('#drink-template').selectOption('Helles');
    await page.locator('#add-drink').getByRole('button').click();
    const drinkId = await page.locator('form.drink-row:not(.add)').getByRole('button', { name: 'Getränk löschen' }).getAttribute('value');

    const checkin = { device_uuid: `dev_soft_${unique()}`, name: 'Sepp', drink_id: drinkId! };
    expect((await request.post(`/drink_api.php?action=checkin&event=${uuid}`, { multipart: checkin })).status()).toBe(200);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('form.drink-row:not(.add)').getByRole('button', { name: 'Getränk löschen' }).click();
    await expect(page.locator('.msg.success')).toHaveText('Getränk gelöscht.');

    const board = await (await page.request.get(`/get_leaderboard_data.php?event=${uuid}`)).json();
    expect(board.users[0]).toMatchObject({ uploader_name: 'Sepp', count: 1 });
    expect(board.drinks[0]).toMatchObject({ name: 'Helles' });

    // gone from the menu: no new check-ins
    expect((await request.post(`/drink_api.php?action=checkin&event=${uuid}`, { multipart: checkin })).status()).toBe(400);
    await page.goto(`/index.php?event=${uuid}#drink`);
    await expect(page.locator('.drink-card')).toHaveCount(0);
  });

  test('the creator can remove people again', async ({ page, request, browser }) => {
    const uuid = await createEvent(page, `Team ${unique()}`);
    const email = `member-${unique()}@picdrop.test`;
    const context = await browser.newContext();
    const other = await context.newPage();
    await other.goto('/register.php');
    await other.getByPlaceholder('E-Mail Adresse').fill(email);
    const username = `m${unique()}`.slice(0, 20);
    await other.getByPlaceholder('Anzeigename').fill(username);
    await other.getByPlaceholder(/Passwort/).fill('member-password-1');
    await other.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
    await other.getByRole('button', { name: 'Registrieren' }).click();
    await other.goto(extractLink(await waitForMail(request, email, /bestätige/), 'verify.php'));
    await login(other, email, 'member-password-1');

    await page.goto(`/manage_event.php?event=${uuid}`);
    await page.getByPlaceholder('gast@beispiel.de').fill(email);
    await page.getByRole('button', { name: 'Einladen' }).click();
    expect((await other.goto(`/manage_event.php?event=${uuid}`))?.status()).toBe(200);
    // members can't remove anyone or delete the event
    await expect(other.getByRole('button', { name: /entfernen$/ })).toHaveCount(0);
    await expect(other.getByText('Danger Zone')).toHaveCount(0);
    const token = await csrfToken(other);
    const del = await other.request.post(`/manage_event.php?event=${uuid}`, { form: { csrf_token: token, delete_event_final: 'yes' } });
    expect(del.status()).toBe(403);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: `${username} entfernen` }).click();
    await expect(page.locator('.msg.success')).toHaveText('Zugriff entfernt.');
    expect((await other.goto(`/manage_event.php?event=${uuid}`))?.status()).toBe(403);
    await context.close();
  });
});
