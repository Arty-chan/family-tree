// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, createTree, addMember, goToEditMember, acceptDialogAndClick } = require('./helpers');

test.describe.serial('Security', () => {
  let treeId;
  let memberId;

  test('setup: create tree with a member', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Security Test Tree');
    await addMember(page, treeId, 'Sec Person');
    // Get the member's ID
    await goToEditMember(page, treeId, 'Sec Person');
    memberId = page.url().match(/\/member\/(\d+)\/edit\//)[1];
  });

  test('CSRF token missing returns 403', async ({ page }) => {
    await loginAsAdmin(page);
    // Attempt a POST to the tree edit endpoint without a CSRF token
    const response = await page.request.post(`/tree/${treeId}/edit/`, {
      form: {
        action: 'update',
        tree_id: String(treeId),
        title: 'Hacked Title',
      },
    });
    expect(response.status()).toBe(403);
  });

  test('CSRF token invalid returns 403', async ({ page }) => {
    await loginAsAdmin(page);
    const response = await page.request.post(`/tree/${treeId}/edit/`, {
      form: {
        action: 'update',
        tree_id: String(treeId),
        title: 'Hacked Title',
        _csrf: 'invalid-token-value',
      },
    });
    expect(response.status()).toBe(403);
  });

  test('GET to delete_member redirects to home', async ({ page }) => {
    await loginAsAdmin(page);
    // delete_member only accepts POST; GET should redirect to /
    await page.goto('/member/delete/');
    await expect(page).toHaveURL(/:\d+\/$/);
  });

  test('GET to delete_relationship redirects to home', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/relationship/delete/');
    await expect(page).toHaveURL(/:\d+\/$/);
  });

  test('redirect_to with external URL is sanitized', async ({ page }) => {
    await loginAsAdmin(page);
    // Add a relationship so we can delete it
    await addMember(page, treeId, 'RelTarget', { type: 'child', person: 'Sec Person' });
    await goToEditMember(page, treeId, 'Sec Person');

    // Find the first relationship delete form and alter its redirect_to
    const deleteForm = page.locator('.rel-list form[data-confirm]').first();
    await deleteForm.locator('input[name="redirect_to"]').evaluate(
      el => el.value = 'https://evil.example.com/'
    );

    // Accept confirmation and submit
    await acceptDialogAndClick(page, '.rel-list form[data-confirm]:first-of-type button[type="submit"]');
    // Should redirect to home, not external URL
    await expect(page).toHaveURL(/:\d+\/$/);
  });
});
