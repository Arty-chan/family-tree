/**
 * Shared helpers for E2E tests.
 *
 * Provides login, DB cleanup, and factory functions so individual
 * test files stay focused on the flows they exercise.
 */

const { expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const DB_PATH    = path.resolve(__dirname, '../../family_tree.db');
const UPLOAD_DIR = path.resolve(__dirname, '../../uploads');
const ADMIN_PW   = 'testpassword123';

/** Reusable selectors. */
const SEL = {
  SUCCESS: '.alert-msg--success',
  ERROR:   '.alert-msg--error',
};

/** Accept the next browser confirm dialog, then click a button. */
async function acceptDialogAndClick(page, selector) {
  page.once('dialog', dialog => dialog.accept());
  await page.locator(selector).first().click();
}

/** Remove the test database and uploads so each suite starts fresh. */
function resetApp() {
  if (fs.existsSync(DB_PATH))    fs.unlinkSync(DB_PATH);
  // WAL/SHM files that SQLite may leave behind
  for (const ext of ['-wal', '-shm']) {
    const p = DB_PATH + ext;
    if (fs.existsSync(p)) fs.unlinkSync(p);
  }
  if (fs.existsSync(UPLOAD_DIR)) {
    fs.rmSync(UPLOAD_DIR, { recursive: true, force: true });
  }
}

/** Sign in as admin. */
async function loginAsAdmin(page) {
  await page.goto('/login/');
  await page.locator('#password').fill(ADMIN_PW);
  await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
  try {
    await expect(page).toHaveURL(/:\d+\/$|\/tree\/\d+\/$/, { timeout: 5000 });
  } catch {
    // Retry once — the first request to the PHP server may race with DB init
    await page.goto('/login/');
    await page.locator('#password').fill(ADMIN_PW);
    await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
    await expect(page).toHaveURL(/:\d+\/$|\/tree\/\d+\/$/);  
  }
}

/** Create a tree from the admin dashboard and return its ID. */
async function createTree(page, title, password) {
  await page.goto('/');  // ensure we're on the dashboard
  await page.locator('#tree_title').fill(title);
  if (password) {
    await page.locator('#new_tree_password').fill(password);
  }
  await page.locator('button:has-text("Create Tree")').click();
  await expect(page).toHaveURL(/:\d+\/$|\/tree\/\d+\/$/);

  // App may redirect to admin dashboard or directly to the new tree.
  const current = page.url();
  const direct = current.match(/\/tree\/(\d+)\//);
  if (direct) {
    return direct[1];
  }

  const link = page.locator(`a:has-text("${title}")`).first();
  const href = await link.getAttribute('href');
  return href.match(/\/tree\/(\d+)\//)[1];
}

/** Sign in with a tree-level password. */
async function loginWithTreePassword(page, password) {
  await page.goto('/login/');
  await page.locator('#password').fill(password);
  await page.locator('form:has(#password) button.btn-full[type="submit"]').click();
}

/** Navigate to member add form for the given tree. */
async function goToAddMember(page, treeId) {
  await page.goto(`/tree/${treeId}/`);
  await page.locator('a:has-text("Add Member")').click();
  await expect(page).toHaveURL(`/tree/${treeId}/add-member/`);
}

/** Navigate to a member's edit page via their tree-view card. */
async function goToEditMember(page, treeId, memberName) {
  await page.goto(`/tree/${treeId}/`);
  await page.locator(`.person-card:has-text("${memberName}") .edit-link`).first().click();
}

/** Create a minimal 1×1 PNG test image at the given path (no-op if exists). */
function ensureTestImage(filePath, base64) {
  if (!fs.existsSync(filePath)) {
    fs.writeFileSync(filePath, Buffer.from(
      base64 || 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
      'base64'
    ));
  }
}

/** Add a member with just a name, optionally with a relationship. */
async function addMember(page, treeId, name, rel) {
  await goToAddMember(page, treeId);
  await page.locator('#name').fill(name);
  if (rel) {
    await page.locator('#rel_type').selectOption(rel.type);
    await page.locator('#rel_person_id').selectOption({ label: rel.person });
    if (rel.type === 'spouse' && rel.yearMarried) {
      await page.locator('#year_married').fill(String(rel.yearMarried));
    }
  }
  await page.locator('button[name="after"][value="back"]').click();
  await expect(page).toHaveURL(`/tree/${treeId}/`);
}

module.exports = {
  ADMIN_PW,
  SEL,
  resetApp,
  acceptDialogAndClick,
  loginAsAdmin,
  loginWithTreePassword,
  createTree,
  goToAddMember,
  goToEditMember,
  addMember,
  ensureTestImage,
};
