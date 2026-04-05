// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAsAdmin, createTree, addMember } = require('./helpers');

let treeId;

test.describe.serial('Tree Visualization', () => {
  test('setup: create tree with family members', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Visualization Tree');
    await addMember(page, treeId, 'Grandparent');
    await addMember(page, treeId, 'Parent', { type: 'child', person: 'Grandparent' });
    await addMember(page, treeId, 'Spouse', { type: 'spouse', person: 'Parent', yearMarried: 1990 });
    await addMember(page, treeId, 'Child', { type: 'child', person: 'Parent' });
    await addMember(page, treeId, 'Cousin', { type: 'cousin', person: 'Grandparent' });
  });

  test('tree renders person cards', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    await expect(page.locator('#tree-container')).toBeVisible();
    await expect(page.locator('.person-card')).toHaveCount(5);
  });

  test('tree renders SVG connectors', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Wait for tree.js to draw connectors
    await expect(page.locator('svg')).toBeVisible();
    // Should have lines (parent-child) and/or paths
    const lineCount = await page.locator('svg line, svg path').count();
    expect(lineCount).toBeGreaterThan(0);
  });

  test('person cards show edit links', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    const editLinks = page.locator('.person-card a:has-text("Edit")');
    await expect(editLinks.first()).toBeVisible();
  });

  test('clean view hides edit controls', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Click clean view
    await page.locator('#clean-view-btn').click();
    // Edit links inside person cards should be hidden
    await expect(page.locator('.person-card .edit-link').first()).toBeHidden();
    // Click again to toggle back
    await page.locator('#clean-view-btn').click();
    await expect(page.locator('.person-card .edit-link').first()).toBeVisible();
  });

  test('spouse connector shows marriage years', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Marriage year should appear in the spouse connector area
    await expect(page.locator('.marriage-years')).toContainText('1990');
  });

  test('cousin connection renders with dashed line', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Cousin lines use stroke-dasharray
    const dashedLine = page.locator('svg [stroke-dasharray]');
    await expect(dashedLine.first()).toBeVisible();
  });

  test('collapse branch hides children', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Should have at least one toggle button
    const toggle = page.locator('.branch-toggle').first();
    await expect(toggle).toBeVisible();
    // Click to collapse — button text changes to "+"
    await toggle.click();
    await expect(toggle).toHaveText('+');
    // The children row should have the collapsed class
    await expect(page.locator('.tree-children.collapsed').first()).toBeAttached();
    // Click again to expand
    await toggle.click();
    await expect(toggle).toHaveText('−');
  });
});

test.describe.serial('Tree Visualization - Extended', () => {
  let extTreeId;

  test('setup: create tree with multiple spouses and CJK names', async ({ page }) => {
    await loginAsAdmin(page);
    extTreeId = await createTree(page, 'Extended Viz Tree');
    await addMember(page, extTreeId, '王大明');
    await addMember(page, extTreeId, 'Spouse One', { type: 'spouse', person: '王大明', yearMarried: 1980 });
    await addMember(page, extTreeId, 'Spouse Two', { type: 'spouse', person: '王大明', yearMarried: 2000 });
  });

  test('multiple spouses render on tree', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${extTreeId}/`);
    // All three people should appear
    await expect(page.locator('.person-card')).toHaveCount(3);
    // Both marriage years should be visible
    await expect(page.locator('.marriage-years').first()).toBeVisible();
    const yearTexts = await page.locator('.marriage-years').allTextContents();
    const allText = yearTexts.join(' ');
    expect(allText).toContain('1980');
    expect(allText).toContain('2000');
  });

  test('CJK name renders with zh-Hant wrapper', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${extTreeId}/`);
    // The CJK name should be wrapped in a div with lang="zh-Hant"
    const cjkDiv = page.locator('.person-name [lang="zh-Hant"]');
    await expect(cjkDiv.first()).toBeVisible();
    await expect(cjkDiv.first()).toContainText('王大明');
  });
});
