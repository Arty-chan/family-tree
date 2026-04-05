// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, createTree, addMember, SEL, acceptDialogAndClick } = require('./helpers');

test.describe('Tree Management', () => {
  test.beforeEach(async ({ page }) => { await loginAsAdmin(page); });

  test('create a tree without password', async ({ page }) => {
    await page.locator('#tree_title').fill('No Password Tree');
    await page.locator('button:has-text("Create Tree")').click();
    await expect(page).toHaveURL(/\/$|\/tree\/\d+\/$/);  
    await expect(page.locator('body')).toContainText('No Password Tree');
  });

  test('create a tree with password', async ({ page }) => {
    await page.locator('#tree_title').fill('Secured Tree');
    await page.locator('#new_tree_password').fill('secure12345');
    await page.locator('button:has-text("Create Tree")').click();
    await expect(page).toHaveURL(/\/$|\/tree\/\d+\/$/);
    await expect(page.locator('body')).toContainText('Secured Tree');
  });

  test('empty tree title shows error', async ({ page }) => {
    // Bypass HTML5 required attribute so the form actually submits
    await page.locator('#tree_title').evaluate(el => el.removeAttribute('required'));
    await page.locator('button:has-text("Create Tree")').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('tree title too-short password shows error', async ({ page }) => {
    await page.locator('#tree_title').fill('Short PW Tree');
    // Bypass HTML5 minlength so the form actually submits
    await page.locator('#new_tree_password').evaluate(el => el.removeAttribute('minlength'));
    await page.locator('#new_tree_password').fill('short');
    await page.locator('button:has-text("Create Tree")').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('view tree page shows title', async ({ page }) => {
    const id = await createTree(page, 'Viewable Tree');
    await page.goto(`/tree/${id}/`);
    await expect(page.locator('body')).toContainText('Viewable Tree');
  });

  test('edit tree title', async ({ page }) => {
    const id = await createTree(page, 'Original Title');
    await page.goto(`/tree/${id}/edit/`);
    await page.locator('#title').fill('Updated Title');
    await page.locator('button:has-text("Save")').click();
    await expect(page).toHaveURL(`/tree/${id}/`);
    await expect(page.locator('body')).toContainText('Updated Title');
  });

  test('delete tree removes it from dashboard', async ({ page }) => {
    const id = await createTree(page, 'Doomed Tree');

    await page.goto(`/tree/${id}/edit/`);

    // Accept the confirmation dialog
    await acceptDialogAndClick(page, 'button:has-text("Delete this tree")');

    await expect(page).toHaveURL('/');
    await expect(page.locator('body')).not.toContainText('Doomed Tree');
  });

  test('set tree password from admin dashboard', async ({ page }) => {
    const id = await createTree(page, 'PW Update Tree');

    // Navigate back to admin dashboard
    await page.goto('/');

    // Find the inline form that contains this tree's hidden id input
    const form = page.locator(`form:has(input[name="tree_id"][value="${id}"])`);
    await form.locator('input[name="tree_password"]').fill('newpass12345');
    await form.locator('button:has-text("Save")').click();
    await expect(page.locator(SEL.SUCCESS)).toBeVisible();
  });

  test('delete tree cascades members and photos', async ({ page }) => {
    const id = await createTree(page, 'Cascade Tree');
    await addMember(page, id, 'Cascade Person');
    // Verify the member exists
    await page.goto(`/tree/${id}/`);
    await expect(page.locator('#tree-container')).toContainText('Cascade Person');

    // Delete the tree
    await page.goto(`/tree/${id}/edit/`);
    await acceptDialogAndClick(page, 'button:has-text("Delete this tree")');
    await expect(page).toHaveURL('/');

    // The tree page should now 404
    await page.goto(`/tree/${id}/`);
    await expect(page.locator('body')).toContainText('not found');
  });
});
