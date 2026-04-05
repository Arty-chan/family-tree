/**
 * Playwright global setup — runs once BEFORE the web server starts.
 * Deletes the SQLite DB and uploads so each test run starts with a fresh schema.
 */
const fs = require('fs');
const path = require('path');

const DB_PATH    = path.resolve(__dirname, '../../family_tree.db');
const UPLOAD_DIR = path.resolve(__dirname, '../../uploads');

module.exports = async function globalSetup() {
  for (const file of [DB_PATH, DB_PATH + '-wal', DB_PATH + '-shm']) {
    if (fs.existsSync(file)) fs.unlinkSync(file);
  }
  if (fs.existsSync(UPLOAD_DIR)) {
    fs.rmSync(UPLOAD_DIR, { recursive: true, force: true });
  }
};
