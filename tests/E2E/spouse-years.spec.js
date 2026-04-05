// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, createTree, addMember, goToEditMember, SEL } = require('./helpers');

let treeId;

test.describe.serial('Spouse Year Editing', () => {
  test('setup: create tree with spouse pair', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Spouse Year Tree');
    await addMember(page, treeId, 'Partner A');
    await addMember(page, treeId, 'Partner B', {
      type: 'spouse',
      person: 'Partner A',
      yearMarried: 1985,
    });
  });

  test('inline edit button reveals spouse year form', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Partner A');

    // Click the edit button on the spouse relationship
    await page.locator('.rel-edit-btn').first().click();
    // The inline form should now be visible
    await expect(page.locator('.rel-spouse-form').first()).toBeVisible();
  });

  test('save updated spouse years', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Partner A');

    await page.locator('.rel-edit-btn').first().click();
    const form = page.locator('.rel-spouse-form').first();
    await form.locator('input[name="year_married"]').fill('1986');
    await form.locator('input[name="year_separated"]').fill('2000');
    await form.locator('button[type="submit"]').click();

    await expect(page.locator(SEL.SUCCESS)).toBeVisible();
    // The read view should show updated years
    await expect(page.locator('.rel-years-read').first()).toContainText('1986');
    await expect(page.locator('.rel-years-read').first()).toContainText('2000');
  });

  test('cancel inline edit hides form', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Partner A');

    await page.locator('.rel-edit-btn').first().click();
    await expect(page.locator('.rel-spouse-form').first()).toBeVisible();
    // Click cancel
    await page.locator('.rel-cancel-btn').first().click();
    await expect(page.locator('.rel-spouse-form').first()).toBeHidden();
  });
});
