// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, createTree, SEL } = require('./helpers');

test.describe('Admin Dashboard', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page); });

  test('change admin password', async ({ page }) => {
    const newPw = 'newadminpass123';
    await page.locator('#new_admin_password').fill(newPw);
    await page.locator('#confirm_admin_password').fill(newPw);
    await page.locator('button:has-text("Save Password")').click();
    await expect(page.locator(SEL.SUCCESS)).toBeVisible();

    // Sign out and log in with the new password
    await page.locator('button:has-text("Sign Out")').click();
    await page.locator('#password').fill(newPw);
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    await expect(page).toHaveURL(/\/$|\/tree\/\d+\/$/);

    // Reset it back for other tests
    await page.locator('#new_admin_password').fill('testpassword123');
    await page.locator('#confirm_admin_password').fill('testpassword123');
    await page.locator('button:has-text("Save Password")').click();
  });

  test('admin password mismatch shows error', async ({ page }) => {
    await page.locator('#new_admin_password').fill('password123');
    await page.locator('#confirm_admin_password').fill('differentpw123');
    await page.locator('button:has-text("Save Password")').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('admin password too short shows error', async ({ page }) => {
    // Bypass HTML5 minlength so the form actually submits to server validation
    await page.locator('#new_admin_password').evaluate(el => el.removeAttribute('minlength'));
    await page.locator('#confirm_admin_password').evaluate(el => el.removeAttribute('minlength'));
    await page.locator('#new_admin_password').fill('short');
    await page.locator('#confirm_admin_password').fill('short');
    await page.locator('button:has-text("Save Password")').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });
});
