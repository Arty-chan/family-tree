// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, ADMIN_PW, SEL } = require('./helpers');

test.describe('Authentication', () => {
  test('unauthenticated user is redirected to login', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveURL(/\/login\//);
  });

  test('wrong password shows error', async ({ page }) => {
    await page.goto('/login/');
    await page.locator('#password').fill('wrongpassword');
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('admin login grants access to dashboard', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Family Trees' })).toBeVisible();
  });

  test('sign out returns to login page', async ({ page }) => {
    await loginAsAdmin(page);
    await page.locator('button:has-text("Sign Out")').click();
    await expect(page).toHaveURL(/\/login\//);
  });

  test('tree password unlocks specific tree', async ({ page, context }) => {
    // First create a tree with a password as admin
    await loginAsAdmin(page);
    const treeTitle = 'Auth Test Tree';
    const treePw = 'treepass123';
    await page.locator('#tree_title').fill(treeTitle);
    await page.locator('#new_tree_password').fill(treePw);
    await page.locator('button:has-text("Create Tree")').click();
    await expect(page).toHaveURL(/\/$|\/tree\/\d+\/$/);

    // Sign out
    await page.locator('button:has-text("Sign Out")').click();

    // Log in with the tree password
    await page.locator('#password').fill(treePw);
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();

    // Should see the tree (redirects to the tree or shows session banner)
    await expect(page.locator('body')).toContainText(treeTitle);
  });

  test('login redirects to ?next= after auth', async ({ page }) => {
    // Ensure the target tree exists so ?next= is valid.
    await loginAsAdmin(page);
    await page.locator('#tree_title').fill('Next Redirect Tree');
    await page.locator('button:has-text("Create Tree")').click();
    const m = page.url().match(/\/tree\/(\d+)\//);
    const treeId = m ? m[1] : '1';

    // Sign out first to ensure clean state.
    await page.locator('button:has-text("Sign Out")').click();

    // Try to access a tree page directly
    await page.goto(`/tree/${treeId}/`);
    // Should redirect to login with ?next=
    await expect(page).toHaveURL(/\/login\/\?next=/);
    // Login as admin
    await page.locator('#password').fill(ADMIN_PW);
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    // Should redirect back to the tree page
    await expect(page).toHaveURL(new RegExp(`/tree/${treeId}/`));
  });

  test('honeypot field blocks bots', async ({ page }) => {
    await page.goto('/login/');
    await page.locator('#password').fill(ADMIN_PW);
    // Set hidden honeypot via JS to avoid viewport limitations in Firefox.
    await page.evaluate(() => {
      const hp = document.querySelector('#website');
      if (hp) hp.checked = true;
    });
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    // Should show an error (or redirect back to login), not grant access
    await expect(page).toHaveURL(/\/login\//);
  });

  test('rate limiting locks out after 5 failed attempts', async ({ page }) => {
    await page.goto('/login/');
    for (let i = 0; i < 5; i++) {
      await page.locator('#password').fill('wrongpassword');
      await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
      await expect(page).toHaveURL(/\/login\//);
    }
    // 6th attempt should show rate-limit message
    await page.locator('#password').fill('wrongpassword');
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    await expect(page.locator(SEL.ERROR)).toContainText(/try again|重試/);
  });

  test('safe_next rejects external URLs', async ({ page }) => {
    // Try to inject an external URL via ?next=
    await page.goto('/login/?next=https://evil.example.com');
    await page.locator('#password').fill(ADMIN_PW);
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    // Should redirect to home, not to the external URL
    await expect(page).toHaveURL(/:\d+\/$/);
  });
});
