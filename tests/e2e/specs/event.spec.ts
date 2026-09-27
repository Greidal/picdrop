import { expect, test } from '@playwright/test';
import { admin, createEvent, env, extractLink, login, unique, waitForMail } from '../helpers';

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
