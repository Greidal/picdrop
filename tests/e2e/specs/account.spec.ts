import { expect, test } from '@playwright/test';
import { admin, env, extractLink, login, mailpitUrl, unique, waitForMail } from '../helpers';

test.describe('login', () => {
  test('wrong password shows a generic error', async ({ page }) => {
    await page.goto('/login.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(admin.email);
    await page.getByPlaceholder('Passwort').fill('definitely-wrong');
    await page.getByRole('button', { name: 'Einloggen' }).click();
    await expect(page.locator('.msg.error')).toHaveText('Ungültige Zugangsdaten.');
  });

  test('admin can log in and out', async ({ page }) => {
    await login(page, admin.email, admin.password);
    await expect(page.getByText('ADMIN', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Logout' }).click();
    await expect(page).toHaveURL(/login\.php/);
    await page.goto('/admin.php');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('account is locked after too many failed attempts', async ({ page }) => {
    const email = `brute-${unique()}@picdrop.test`;
    for (let i = 0; i < 5; i++) {
      await page.goto('/login.php');
      await page.getByPlaceholder('E-Mail Adresse').fill(email);
      await page.getByPlaceholder('Passwort').fill(`wrong-${i}`);
      await page.getByRole('button', { name: 'Einloggen' }).click();
      await expect(page.locator('.msg.error')).toHaveText('Ungültige Zugangsdaten.');
    }
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByPlaceholder('Passwort').fill('wrong-again');
    await page.getByRole('button', { name: 'Einloggen' }).click();
    await expect(page.locator('.msg.error')).toContainText('Zu viele Fehlversuche');
  });
});

test.describe('registration', () => {
  test('requires the registration code', async ({ page }) => {
    await page.goto('/register.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(`nocode-${unique()}@picdrop.test`);
    await page.getByPlaceholder('Anzeigename').fill(`nc${unique()}`.slice(0, 20));
    await page.getByPlaceholder(/Passwort/).fill('long-enough-password');
    await page.getByPlaceholder('Registrierungs-Code').fill('wrong-code');
    await page.getByRole('button', { name: 'Registrieren' }).click();
    await expect(page.locator('.msg.error')).toHaveText('Falscher Registrierungs-Code!');
  });

  test('does not leak the registration code via ?invite=', async ({ request }) => {
    const html = await (await request.get('/register.php?invite=x')).text();
    expect(html).not.toContain(env.REGISTRATION_CODE);
  });

  test('sign up, verify by mail, log in', async ({ page, request }) => {
    const email = `new-${unique()}@picdrop.test`;
    const password = 'my-new-password-123';

    await page.goto('/register.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByPlaceholder('Anzeigename').fill(`u${unique()}`.slice(0, 20));
    await page.getByPlaceholder(/Passwort/).fill(password);
    await page.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
    await page.getByRole('button', { name: 'Registrieren' }).click();
    await expect(page.locator('.msg.success')).toContainText('Account erstellt');

    // not verified yet
    await page.goto('/login.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByPlaceholder('Passwort').fill(password);
    await page.getByRole('button', { name: 'Einloggen' }).click();
    await expect(page.locator('.msg.error')).toContainText('bestätige erst');
    await expect(page.getByRole('link', { name: 'Bestätigungsmail erneut senden' })).toBeVisible();

    const mail = await waitForMail(request, email, /bestätige/);
    const link = extractLink(mail, 'verify.php');
    expect(link).toMatch(new RegExp(`^${env.APP_URL}/verify\\.php`)); // APP_URL, no double slash
    await page.goto(link);
    await expect(page.locator('.msg.success')).toContainText('erfolgreich aktiviert');

    // links are single-use
    await page.goto(link);
    await expect(page.locator('.msg.error')).toBeVisible();

    await login(page, email, password);
  });

  test('verification mail can be requested again', async ({ page, request }) => {
    const email = `resend-${unique()}@picdrop.test`;
    await page.goto('/register.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByPlaceholder('Anzeigename').fill(`r${unique()}`.slice(0, 20));
    await page.getByPlaceholder(/Passwort/).fill('long-enough-password');
    await page.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
    await page.getByRole('button', { name: 'Registrieren' }).click();
    const firstLink = extractLink(await waitForMail(request, email, /bestätige/), 'verify.php');

    await page.goto('/resend_verification.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByRole('button', { name: 'Link erneut senden' }).click();
    await expect(page.locator('.msg.success')).toContainText('Falls ein noch nicht bestätigter Account');

    await expect
      .poll(async () => extractLink(await waitForMail(request, email, /bestätige/), 'verify.php'))
      .not.toBe(firstLink);
    const newLink = extractLink(await waitForMail(request, email, /bestätige/), 'verify.php');

    await page.goto(firstLink); // old link was replaced
    await expect(page.locator('.msg.error')).toBeVisible();
    await page.goto(newLink);
    await expect(page.locator('.msg.success')).toContainText('erfolgreich aktiviert');
  });
});

test.describe('password reset', () => {
  test('unknown addresses get the same answer', async ({ page }) => {
    await page.goto('/forgot_password.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(`nobody-${unique()}@picdrop.test`);
    await page.getByRole('button', { name: 'Link anfordern' }).click();
    await expect(page.locator('.msg.success')).toContainText('Falls ein Account mit dieser Adresse existiert');
  });

  test('reset by mail, old password stops working, link is single-use', async ({ page, request }) => {
    // own user, so the admin password stays untouched for the other tests
    const email = `reset-${unique()}@picdrop.test`;
    await page.goto('/register.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByPlaceholder('Anzeigename').fill(`p${unique()}`.slice(0, 20));
    await page.getByPlaceholder(/Passwort/).fill('old-password-1234');
    await page.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
    await page.getByRole('button', { name: 'Registrieren' }).click();
    await expect(page.locator('.msg.success')).toBeVisible();

    await page.goto('/login.php');
    await page.getByRole('link', { name: 'Passwort vergessen?' }).click();
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByRole('button', { name: 'Link anfordern' }).click();
    await expect(page.locator('.msg.success')).toBeVisible();

    const link = extractLink(await waitForMail(request, email, /Passwort zurücksetzen/), 'reset_password.php');
    await page.goto(link);
    await page.getByPlaceholder(/Neues Passwort/).fill('new-password-5678');
    await page.getByPlaceholder('Passwort wiederholen').fill('does-not-match');
    await page.getByRole('button', { name: 'Passwort speichern' }).click();
    await expect(page.locator('.msg.error')).toHaveText('Die Passwörter stimmen nicht überein.');

    await page.getByPlaceholder(/Neues Passwort/).fill('new-password-5678');
    await page.getByPlaceholder('Passwort wiederholen').fill('new-password-5678');
    await page.getByRole('button', { name: 'Passwort speichern' }).click();
    await expect(page.locator('.msg.success')).toContainText('Passwort wurde geändert');

    await page.goto(link);
    await expect(page.locator('.msg.error')).toContainText('ungültig, abgelaufen oder wurde schon verwendet');

    // the reset link also verified the (previously unverified) account
    await login(page, email, 'new-password-5678');
  });

  test('account mails are throttled per address', async ({ page, request }) => {
    const email = `throttle-${unique()}@picdrop.test`;
    await page.goto('/register.php');
    await page.getByPlaceholder('E-Mail Adresse').fill(email);
    await page.getByPlaceholder('Anzeigename').fill(`t${unique()}`.slice(0, 20));
    await page.getByPlaceholder(/Passwort/).fill('long-enough-password');
    await page.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
    await page.getByRole('button', { name: 'Registrieren' }).click();
    await waitForMail(request, email, /bestätige/);

    for (let i = 0; i < 5; i++) {
      await page.goto('/forgot_password.php');
      await page.getByPlaceholder('E-Mail Adresse').fill(email);
      await page.getByRole('button', { name: 'Link anfordern' }).click();
      await expect(page.locator('.msg.success')).toBeVisible(); // same answer, even when throttled
    }

    // 3 account mails per hour: the verification mail + 2 reset mails; the other 3 requests are dropped.
    await waitForMail(request, email, /Passwort zurücksetzen/);
    const res = await request.get(`${mailpitUrl}/api/v1/search`, { params: { query: `to:"${email}"` } });
    expect(((await res.json()) as { messages_count: number }).messages_count).toBe(3);
  });
});
