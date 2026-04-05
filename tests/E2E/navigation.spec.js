// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, createTree, addMember, goToEditMember } = require('./helpers');

let treeId;

test.describe.serial('Language Toggle', () => {
  test('setup: create a tree', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Lang Test Tree');
    await addMember(page, treeId, 'Test Person');
  });

  test('toggle to Chinese changes UI text', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Click the language toggle button (shows Chinese flag or text)
    await page.locator('button:has-text("中文")').click();
    // UI should now show Chinese text
    await expect(page.locator('body')).toContainText('新增成員'); // "+ Add Member" in Chinese
  });

  test('toggle back to English', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // If currently Chinese, toggle to English
    const toggleBtn = page.locator('button.btn-nav:has-text("English"), button.btn-nav:has-text("中文")');
    const btnText = await toggleBtn.textContent();
    if (btnText.includes('English')) {
      await toggleBtn.click();
    }
    await expect(page.locator('body')).toContainText('Add Member');
  });
});

test.describe.serial('Navigation', () => {
  test('setup: create a tree with a member', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Nav Test Tree');
    await addMember(page, treeId, 'Nav Person');
  });

  test('breadcrumbs navigate correctly', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Nav Person');
    // Breadcrumb should contain tree title
    await expect(page.locator('.breadcrumb-link')).toContainText('Nav Test Tree');
    // Click breadcrumb to go back to tree
    await page.locator('.breadcrumb-link').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
  });

  test('site title link goes to admin dashboard', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    await page.locator('.site-title').click();
    await expect(page).toHaveURL('/');
  });

  test('admin link shows in nav when admin-authed', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    await expect(page.locator('a.btn-nav:has-text("Admin")')).toBeVisible();
  });

  test('nonexistent tree shows not found', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/tree/99999/');
    await expect(page.locator('body')).toContainText('not found');
  });
});
