PRAGMA foreign_keys=ON;

/* ===== Felhasználók (admin) ===== */
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  pass_hash TEXT NOT NULL,
  role TEXT DEFAULT 'admin',
  created_at TEXT DEFAULT (datetime('now'))
);

/* ===== Kategóriák / Alkategóriák ===== */
CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  description TEXT,
  is_new INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_categories_name ON categories(name);

CREATE TABLE IF NOT EXISTS subcategories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  slug TEXT NOT NULL,
  is_new INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  UNIQUE(category_id, slug)
);
CREATE INDEX IF NOT EXISTS idx_subcategories_cat ON subcategories(category_id);

/* ===== Katalógus elemek ===== */
CREATE TABLE IF NOT EXISTS catalogs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
  subcategory_id INTEGER REFERENCES subcategories(id) ON DELETE SET NULL,
  title TEXT NOT NULL,
  short_text TEXT,
  body_html TEXT,
  slug TEXT NOT NULL,
  is_new INTEGER DEFAULT 0,
  sort INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  UNIQUE(subcategory_id, slug)
);
CREATE INDEX IF NOT EXISTS idx_catalogs_cat ON catalogs(category_id);
CREATE INDEX IF NOT EXISTS idx_catalogs_sub ON catalogs(subcategory_id);
CREATE INDEX IF NOT EXISTS idx_catalogs_title ON catalogs(title);

/* ===== PDF-ek és képgaléria ===== */
CREATE TABLE IF NOT EXISTS catalog_pdfs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  catalog_id INTEGER NOT NULL REFERENCES catalogs(id) ON DELETE CASCADE,
  display_name TEXT NOT NULL,
  file_path TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pdfs_catalog ON catalog_pdfs(catalog_id);

CREATE TABLE IF NOT EXISTS catalog_media (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  catalog_id INTEGER NOT NULL REFERENCES catalogs(id) ON DELETE CASCADE,
  file_path TEXT NOT NULL,
  alt TEXT
);
CREATE INDEX IF NOT EXISTS idx_media_catalog ON catalog_media(catalog_id);

/* ====== MINTAADAT (opcionális, hogy rögtön legyen kijelzés) ====== */
INSERT OR IGNORE INTO categories(name, slug, description, is_new)
VALUES ('Hidraulika', 'hidraulika', 'Hidraulikus elemek és kiegészítők.', 1);

INSERT OR IGNORE INTO subcategories(category_id, name, slug, is_new)
VALUES (1, 'Munkahengerek', 'munkahengerek', 1),
       (1, 'Szelepek',      'szelepek',      0);

INSERT OR IGNORE INTO catalogs(
  category_id, subcategory_id, title, short_text, body_html, slug, is_new, sort, created_at
) VALUES
(1, 1,
 'Letölthető elem',
 'Rövid összefoglaló szöveg a termékről.',
 '<p>Részletes leírás <strong>HTML-ben</strong>, felsorolásokkal.</p><ul><li>Pont 1</li><li>Pont 2</li></ul>',
 'letoltheto-elem', 1, 10, datetime('now', '-5 days')
);

INSERT OR IGNORE INTO catalog_pdfs(catalog_id, display_name, file_path)
VALUES (1, 'Katalógus PDF', 'pdf/katalogus-minta.pdf');

INSERT OR IGNORE INTO catalog_media(catalog_id, file_path, alt)
VALUES (1, 'images/galeria/minta-01.jpg', 'Termékkép 1'),
       (1, 'images/galeria/minta-02.jpg', 'Termékkép 2');

/* ====== Admin minta felhasználó (jelszó: admin123 – KÉSŐBB CSERÉLD!) ====== */
/* Ezt csak egyszer szabad futtatni! Bcrypt hash PHP-ból kerül be majd.
   Ideiglenesen üresen hagyjuk; az admin létrehozását a login modul intézi. */
