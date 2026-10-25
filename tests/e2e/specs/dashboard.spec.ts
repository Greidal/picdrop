import { expect, test, type Page } from '@playwright/test';
import { admin, createEvent, env, extractLink, login, unique, waitForMail } from '../helpers';

const section = (page: Page, title: string) => page.locator('details.section').filter({ has: page.locator('summary', { hasText: title }) });
const row = (page: Page, name: string) => page.locator('.event-row').filter({ has: page.getByRole('heading', { name, exact: true }) });

/** Registers and verifies a second organizer and returns a logged-in page. */
async function secondUser(browser: import('@playwright/test').Browser, request: import('@playwright/test').APIRequestContext) {
  const email = `orga-${unique()}@picdrop.test`;
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto('/register.php');
  await page.getByPlaceholder('E-Mail Adresse').fill(email);
  await page.getByPlaceholder('Anzeigename').fill(`o${unique()}`.slice(0, 20));
  await page.getByPlaceholder(/Passwort/).fill('orga-password-1');
  await page.getByPlaceholder('Registrierungs-Code').fill(env.REGISTRATION_CODE);
  await page.getByRole('button', { name: 'Registrieren' }).click();
  await page.goto(extractLink(await waitForMail(request, email, /bestätige/), 'verify.php'));
  await login(page, email, 'orga-password-1');
  return { page, email, context };
}

test.describe('dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, admin.email, admin.password);
  });

  test('events are grouped into own, shared and other users\' events', async ({ page, browser, request }) => {
    const own = `Eigenes ${unique()}`;
    await createEvent(page, own);

    const orga = await secondUser(browser, request);
    const shared = `Geteilt ${unique()}`;
    const other = `Fremd ${unique()}`;
    const sharedUuid = await createEvent(orga.page, shared);
    await createEvent(orga.page, other);
    await orga.page.goto(`/manage_event.php?event=${sharedUuid}`);
    await orga.page.getByPlaceholder('gast@beispiel.de').fill(admin.email);
    await orga.page.getByRole('button', { name: 'Einladen' }).click();
    await expect(orga.page.locator('.msg.success')).toContainText('Zugriff gewährt');

    await page.goto('/admin.php');
    await expect(section(page, 'Von mir erstellt').locator('h2', { hasText: own })).toBeAttached();
    await expect(section(page, 'Mir freigegeben').locator('h2', { hasText: shared })).toBeAttached();
    await expect(section(page, 'Von anderen Nutzern').locator('h2', { hasText: other })).toBeAttached();

    // the second organizer only sees their own events and has no "other users" section
    await orga.page.goto('/admin.php');
    await expect(section(orga.page, 'Von anderen Nutzern')).toHaveCount(0);
    await expect(orga.page.getByRole('heading', { name: own })).toHaveCount(0);
    await orga.context.close();
  });

  test('sections can be collapsed and the search filters events', async ({ page }) => {
    const tag = unique();
    await createEvent(page, `Suche Alpha ${tag}`);
    await createEvent(page, `Suche Beta ${tag}`);

    await page.getByPlaceholder('Events suchen…').fill(`alpha ${tag}`);
    await expect(row(page, `Suche Alpha ${tag}`)).toBeVisible();
    await expect(row(page, `Suche Beta ${tag}`)).toBeHidden();

    await page.getByPlaceholder('Events suchen…').fill('gibt es sicher nicht');
    await expect(page.getByText('Keine Events gefunden.')).toBeVisible();

    await page.getByPlaceholder('Events suchen…').fill('');
    await section(page, 'Von mir erstellt').locator('summary').click();
    await expect(row(page, `Suche Beta ${tag}`)).toBeHidden();
    await page.reload();
    await expect(row(page, `Suche Beta ${tag}`)).toBeHidden(); // remembered
    await section(page, 'Von mir erstellt').locator('summary').click();
    await expect(row(page, `Suche Beta ${tag}`)).toBeVisible();
  });

  test('own events can be archived and restored', async ({ page }) => {
    const name = `Archiv ${unique()}`;
    await createEvent(page, name);

    await row(page, name).getByRole('button', { name: 'Archivieren' }).click();
    await expect(page.locator('.msg.success')).toHaveText('Event archiviert.');
    await expect(section(page, 'Archiviert').locator('h2', { hasText: name })).toBeAttached();
    await expect(section(page, 'Von mir erstellt').locator('h2', { hasText: name })).toHaveCount(0);

    await section(page, 'Archiviert').locator('summary').click();
    await row(page, name).getByRole('button', { name: 'Wiederherstellen' }).click();
    await expect(section(page, 'Von mir erstellt').locator('h2', { hasText: name })).toBeAttached();
  });

  test('logged-in organizers land on the dashboard when opening the domain', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/admin\.php$/);
  });

  test('event links are compact icons with labels', async ({ page }) => {
    const name = `Icons ${unique()}`;
    const uuid = await createEvent(page, name);
    const r = row(page, name);
    for (const [label, href] of [['App', 'index.php'], ['Slideshow', 'slideshow.php'], ['Ranking', 'leaderboard.php'], ['Galerie', 'gallery.php']]) {
      await expect(r.getByRole('link', { name: label })).toHaveAttribute('href', `${href}?event=${uuid}`);
    }
    await expect(r.getByRole('link', { name: 'ZIP-Download' })).toHaveAttribute('href', `download_zip.php?event=${uuid}&export_db=1`);
  });
});
