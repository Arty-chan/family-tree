// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, loginWithTreePassword, createTree, addMember } = require('./helpers');

test.describe.serial('Access Control', () => {
  let treeId;
  const treePw = 'treepass1234';

  test('setup: create a tree with password and member', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'ACL Test Tree', treePw);
    await addMember(page, treeId, 'ACL Person');
  });

  test('tree viewer cannot access admin dashboard', async ({ page }) => {
    // Log in with tree password only
    await loginWithTreePassword(page, treePw);
    // Should be redirected to the tree, not the dashboard
    await expect(page).toHaveURL(new RegExp(`/tree/${treeId}/`));

    // Try to access admin dashboard directly
    await page.goto('/');
    // Should be redirected to login (not authorized as admin)
    await expect(page).toHaveURL(/\/login\//);
  });

  test('tree viewer cannot access a different tree', async ({ page }) => {
    // Create a second tree as admin
    await loginAsAdmin(page);
    const otherTreeId = await createTree(page, 'Other ACL Tree', 'otherpass1234');
    // Sign out
    await page.locator('button:has-text("Sign Out")').click();

    // Log in with first tree's password
    await loginWithTreePassword(page, treePw);
    await expect(page).toHaveURL(new RegExp(`/tree/${treeId}/`));

    // Try to access the other tree directly
    await page.goto(`/tree/${otherTreeId}/`);
    // Should be redirected to login (no access to other tree)
    await expect(page).toHaveURL(/\/login\//);
  });

  test('tree viewer cannot edit tree title', async ({ page }) => {
    // Log in with tree password only
    await loginWithTreePassword(page, treePw);

    // Try to access tree edit page (requires admin auth)
    await page.goto(`/tree/${treeId}/edit/`);
    // Should be redirected to login
    await expect(page).toHaveURL(/\/login\//);
  });
});
