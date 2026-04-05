// @ts-check
const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { loginAsAdmin, createTree, addMember, goToEditMember, ensureTestImage, SEL } = require('./helpers');

let treeId;

test.describe.serial('Photo Upload', () => {
  test('setup: create tree and member', async ({ page }) => {
    await loginAsAdmin(page);
    treeId = await createTree(page, 'Photo Test Tree');
    await addMember(page, treeId, 'Photo Person');
  });

  test('upload photo on add member', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/add-member/`);
    await page.locator('#name').fill('With Photo');

    const testImagePath = path.resolve(__dirname, 'test-photo.png');
    ensureTestImage(testImagePath);

    await page.locator('#photo-input').setInputFiles(testImagePath);
    // Submit the form — photo will be uploaded server-side
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);
  });

  test('photo displays on tree view', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/`);
    // At least one person card should have an actual photo (img with src containing uploads or photo)
    const photoImg = page.locator('img.person-photo');
    await expect(photoImg.first()).toBeVisible();
  });

  test('photo preview updates when selecting a new file', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'With Photo');

    // Existing photo should show server-side image
    const previewImg = page.locator('#photo-preview-img');
    await expect(previewImg).toBeVisible();
    const origSrc = await previewImg.getAttribute('src');

    // Select a new file — JS should update preview to a data: URL without submitting
    const testImagePath = path.resolve(__dirname, 'test-photo.png');
    await page.locator('#photo-input').setInputFiles(testImagePath);
    await expect(previewImg).toHaveAttribute('src', /^data:image\//);
    const newSrc = await previewImg.getAttribute('src');
    expect(newSrc).not.toBe(origSrc);
  });

  test('remove photo from edit page', async ({ page }) => {
    await loginAsAdmin(page);
    await goToEditMember(page, treeId, 'With Photo');
    // Check "Remove photo"
    await page.locator('input[name="remove_photo"]').check();
    await page.locator('button[name="after"][value="stay"]').click();
    await expect(page.locator(SEL.SUCCESS)).toBeVisible();
    // Photo section should now show "No photo" state
    await expect(page.locator('.photo-emoji')).toBeVisible();
  });

  test('non-image file is rejected', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`/tree/${treeId}/add-member/`);
    await page.locator('#name').fill('Bad Upload');

    const fakePath = path.resolve(__dirname, 'test-fake.txt');
    if (!fs.existsSync(fakePath)) {
      fs.writeFileSync(fakePath, 'This is not an image');
    }

    // The file input has accept="image/*" but we bypass it via setInputFiles
    await page.locator('#photo-input').setInputFiles(fakePath);
    await page.locator('button[name="after"][value="back"]').click();
    // Server should reject the upload and show an error
    await expect(page.locator(SEL.ERROR)).toBeVisible();
  });

  test('replace existing photo with new one', async ({ page }) => {
    await loginAsAdmin(page);
    // First add a member with a photo
    await page.goto(`/tree/${treeId}/add-member/`);
    await page.locator('#name').fill('Replace Photo');

    const testImagePath = path.resolve(__dirname, 'test-photo.png');
    ensureTestImage(testImagePath);
    await page.locator('#photo-input').setInputFiles(testImagePath);
    await page.locator('button[name="after"][value="back"]').click();
    await expect(page).toHaveURL(`/tree/${treeId}/`);

    // Now edit the member and replace the photo
    await goToEditMember(page, treeId, 'Replace Photo');

    const newImagePath = path.resolve(__dirname, 'test-photo-2.png');
    ensureTestImage(newImagePath, 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==');

    await page.locator('#photo-input').setInputFiles(newImagePath);
    await page.locator('button[name="after"][value="stay"]').click();
    await expect(page.locator(SEL.SUCCESS)).toBeVisible();
    // Photo preview should still show an image (the new one)
    await expect(page.locator('#photo-preview-img')).toBeVisible();
  });
});
