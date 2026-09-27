import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));

/** Values from e2e.env (single source of truth for the test stack). */
export const env: Record<string, string> = Object.fromEntries(
  readFileSync(path.join(here, 'e2e.env'), 'utf8')
    .split('\n')
    .filter((line) => line.includes('=') && !line.startsWith('#'))
    .map((line) => [line.slice(0, line.indexOf('=')), line.slice(line.indexOf('=') + 1)]),
);

export const admin = { email: env.ADMIN_EMAIL, password: env.ADMIN_PASSWORD };
export const fixture = (name: string) => path.join(here, 'fixtures', name);
export const mailpitUrl = process.env.MAILPIT_URL ?? 'http://localhost:18025';

/** Unique suffix so tests don't collide with data from earlier runs. */
export const unique = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

export async function login(page: Page, email: string, password: string): Promise<void> {
  await page.goto('/login.php');
  await page.getByPlaceholder('E-Mail Adresse').fill(email);
  await page.getByPlaceholder('Passwort').fill(password);
  await page.getByRole('button', { name: 'Einloggen' }).click();
  await expect(page).toHaveURL(/admin\.php/);
}

/** Creates an event from the dashboard and returns its UUID. */
export async function createEvent(page: Page, name: string): Promise<string> {
  await page.goto('/admin.php');
  await page.getByPlaceholder(/Name \(z\.B\./).fill(name);
  await page.getByRole('button', { name: 'Erstellen' }).click();
  await expect(page.locator('.msg.success')).toContainText('erfolgreich erstellt');

  const card = page.locator('.card').filter({ has: page.getByRole('heading', { name, exact: true }) });
  const href = await card.getByRole('link', { name: /Verwalten/ }).getAttribute('href');
  const uuid = new URL(href!, 'http://x').searchParams.get('event');
  expect(uuid).toMatch(/^[0-9a-f-]{36}$/);
  return uuid!;
}

/** CSRF token of the current page's first form. */
export async function csrfToken(page: Page): Promise<string> {
  return (await page.locator('input[name="csrf_token"]').first().getAttribute('value'))!;
}

type MailSummary = { ID: string; Subject: string; To: { Address: string }[] };

/** Waits for the newest mail to `to` whose subject matches, and returns its plain-text body. */
export async function waitForMail(request: APIRequestContext, to: string, subject: RegExp): Promise<string> {
  let found: MailSummary | undefined;
  await expect
    .poll(
      async () => {
        const res = await request.get(`${mailpitUrl}/api/v1/search`, { params: { query: `to:"${to}"` } });
        const { messages } = (await res.json()) as { messages: MailSummary[] };
        found = messages.find((m) => subject.test(m.Subject));
        return !!found;
      },
      { message: `mail to ${to} matching ${subject}`, timeout: 15_000 },
    )
    .toBe(true);

  const res = await request.get(`${mailpitUrl}/api/v1/message/${found!.ID}`);
  return ((await res.json()) as { Text: string }).Text;
}

export function extractLink(text: string, page: string): string {
  const match = text.match(new RegExp(`https?://\\S+/${page.replace('.', '\\.')}\\?\\S+`));
  expect(match, `link to ${page} in mail`).not.toBeNull();
  return match![0];
}
