// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const { loginAsAdmin, createTree, goToAddMember, goToEditMember, addMember, SEL, acceptDialogAndClick } = require('./helpers');

let treeId;

test.describe.serial('Member CRUD', () => {
  test('setup: create a tree', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Member Test Tree');
  });

  test('empty tree shows "no members" message', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    await expect(page.locator('body')).toContainText('No members yet');
  });

  test('add a member with just a name', async ({ page }) => {
    await loginAsAdmin(page);
    await addMember(page, treeId, 'Alice');
    await expect(page.locator('#tree-container')).toContainText('Alice');
  });

  test('name is required', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    // Leave name empty, submit
    await page.locator('button[name="after"][value="back"]').click();
    // HTML5 validation or server error — should stay on form
    await expect(page).toHaveURL(/add-member/);
  });

  test('add member with birth and death years', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Bob');
    await page.locator('#birth_year').fill('1950');
    await page.locator('#death_year').fill('2020');
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
    await expect(page.locator('#tree-container')).toContainText('Bob');
  });

  test('death year before birth year shows error', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Bad Dates');
    await page.locator('#birth_year').fill('2000');
    await page.locator('#death_year').fill('1990');
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('future year is silently cleaned by server', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Future Person');
    await page.locator('#birth_year').fill('3000');
    // Remove max attribute so the form submits past HTML5 validation
    await page.locator('#birth_year').evaluate(el => el.removeAttribute('max'));
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
    // Edit the member and verify birth_year was cleaned to empty
    await page.locator('.person-card:has-text("Future Person") .edit-link').first().click();
    await expect(page.locator('#birth_year')).toHaveValue('');
  });

  test('zero year is silently cleaned by server', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Zero Year Person');
    await page.locator('#birth_year').fill('0');
    // Remove min attribute so the form submits past HTML5 validation
    await page.locator('#birth_year').evaluate(el => el.removeAttribute('min'));
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
    await page.locator('.person-card:has-text("Zero Year Person") .edit-link').first().click();
    await expect(page.locator('#birth_year')).toHaveValue('');
  });

  test('add member as child of existing member', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Charlie');
    await page.locator('#rel_type').selectOption('child');
    await page.locator('#rel_person_id').selectOption({ label: 'Alice' });
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
  });

  test('add member as spouse of existing member shows spouse fields', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Dave');
    await page.locator('#rel_type').selectOption('spouse');
    // Spouse fields should now be visible
    await expect(page.locator('#spouse-fields')).toBeVisible();
    await page.locator('#rel_person_id').selectOption({ label: 'Alice' });
    await page.locator('#year_married').fill('1975');
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
  });

  test('rel_type toggle shows and hides spouse fields', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    // Reset rel_type to empty to establish a known starting state
    await page.locator('#rel_type').selectOption('');
    await expect(page.locator('#rel-person-wrap')).toBeHidden();
    await expect(page.locator('#spouse-fields')).toBeHidden();
    // Select 'child' → person wrap visible, spouse fields still hidden
    await page.locator('#rel_type').selectOption('child');
    await expect(page.locator('#rel-person-wrap')).toBeVisible();
    await expect(page.locator('#spouse-fields')).toBeHidden();
    // Switch to 'spouse' → both visible
    await page.locator('#rel_type').selectOption('spouse');
    await expect(page.locator('#rel-person-wrap')).toBeVisible();
    await expect(page.locator('#spouse-fields')).toBeVisible();
    // Switch back to empty → both hidden
    await page.locator('#rel_type').selectOption('');
    await expect(page.locator('#rel-person-wrap')).toBeHidden();
    await expect(page.locator('#spouse-fields')).toBeHidden();
  });

  test('add member as cousin', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Eve');
    await page.locator('#rel_type').selectOption('cousin');
    await page.locator('#rel_person_id').selectOption({ label: 'Alice' });
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
  });

  test('"Add Another" stays on the add form', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Frank');
    await page.locator('button[name="after"][value="another"]').click();
    // Should stay on the add member page with cleared form
    await expect(page).toHaveURL(/add-member/);
    await expect(page.locator('#name')).toHaveValue('');
  });

  test('edit member name', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Alice');
    await expect(page).toHaveURL(/\/member\/\d+\/edit\//);
    await page.locator('#name').fill('Alice Renamed');
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
    await expect(page.locator('#tree-container')).toContainText('Alice Renamed');
  });

  test('"Save & Keep Editing" stays on edit page', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Bob');
    const editUrl = page.url();
    await page.locator('#birth_year').fill('1951');
    await page.locator('button[name="after"][value="stay"]').click();
    await expect(page).toHaveURL(editUrl);
    await expect(page.locator(SEL.SUCCESS)).toBeVisible();
    await expect(page.locator('#birth_year')).toHaveValue('1951');
  });

  test('add relationship from edit page', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Bob');
    // Add Bob as parent of Charlie
    const addRelForm = page.locator('form:has(input[value="add_rel"])');
    await addRelForm.locator('#rel_type').selectOption('parent');
    await addRelForm.locator('#rel_person_id').selectOption({ label: 'Charlie' });
    await addRelForm.locator('button[type="submit"]').click();
    await expect(page.locator(SEL.SUCCESS)).toBeVisible();
  });

  test('duplicate relationship shows error', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Bob');
    // Try to add the same relationship again
    const addRelForm = page.locator('form:has(input[value="add_rel"])');
    await addRelForm.locator('#rel_type').selectOption('parent');
    await addRelForm.locator('#rel_person_id').selectOption({ label: 'Charlie' });
    await addRelForm.locator('button[type="submit"]').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('delete relationship from edit page', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Bob');
    // Accept confirmation dialog and click Remove
    await acceptDialogAndClick(page, '.rel-list button:has-text("Remove")');
    await expect(page).toHaveURL(/\/member\/\d+\/edit\//);
  });

  test('delete member via edit page', async ({ page }) => {
    await loginAsAdmin(page);
    // Add a throw-away member
    await addMember(page, treeId, 'Doomed Member');
    await goToEditMember(page, treeId, 'Doomed Member');
    await acceptDialogAndClick(page, 'button:has-text("Delete this member")');
    await expect(page).toHaveURL(`/tree/${treeId}/`);
    await expect(page.locator('#tree-container')).not.toContainText('Doomed Member');
  });

  test('approximate year checkboxes persist', async ({ page }) => {
    await loginAsAdmin(page);
    await goToAddMember(page, treeId);
    await page.locator('#name').fill('Approx Person');
    await page.locator('#birth_year').fill('1900');
    await page.locator('[name="birth_year_approx"]').check();
    await page.locator('#death_year').fill('1970');
    await page.locator('[name="death_year_approx"]').check();
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);

    // Edit the member and verify checkboxes are still checked
    await page.locator('.person-card:has-text("Approx Person") .edit-link').first().click();
    await expect(page.locator('[name="birth_year_approx"]')).toBeChecked();
    await expect(page.locator('[name="death_year_approx"]')).toBeChecked();
  });

  test('approximate years display with ? in tree view', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // Approx Person should show (?) next to their years
    const card = page.locator('.person-card:has-text("Approx Person")');
    await expect(card.locator('.person-years')).toContainText('(?)');
  });

  test('empty relationship submission shows error', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'Alice Renamed');
    // Submit add-relationship form without selecting a person
    const addRelForm = page.locator('form:has(input[value="add_rel"])');
    await addRelForm.locator('#rel_person_id').selectOption('');
    await addRelForm.locator('button[type="submit"]').click();
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('max 2 parents enforced on add member', async ({ page }) => {
    await loginAsAdmin(page);
    // Create independent members so this test does not depend on earlier state
    await addMember(page, treeId, 'TwoParentChild');
    await addMember(page, treeId, 'FirstParent', { type: 'parent', person: 'TwoParentChild' });
    await addMember(page, treeId, 'SecondParent', { type: 'parent', person: 'TwoParentChild' });

    // TwoParentChild now has 2 parents. Try to add a 3rd via the edit page
    // (edit page shows the error inline, unlike add-member which redirects).
    await goToEditMember(page, treeId, 'TwoParentChild');

    const addRelForm = page.locator('form:has(input[value="add_rel"])');
    // 'child' = current person is child of selected → triggers 2-parent check on current
    await addRelForm.locator('#rel_type').selectOption('child');
    await addRelForm.locator('#rel_person_id').selectOption({ label: 'Alice Renamed' });
    await addRelForm.locator('button[type="submit"]').click();

    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });
});
