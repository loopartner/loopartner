// Régénère src/content/legal.ts à partir des projets de textes de docs/legal (voir
// docs/legal/README.md). Avec --json <version> <AAAA-MM-JJ>, écrit aussi les fichiers attendus
// par `php artisan legal:publish` pour les CGU et la politique (textes anglais obligatoires dans
// docs/legal/en). Usage, depuis frontend/ : node scripts/build-legal.mjs [--json <version> <date>]
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const sources = {
  terms: 'cgu.md',
  privacy: 'politique-de-confidentialite.md',
  sales: 'cgv-premium.md',
  cookies: 'cookies.md',
  notice: 'mentions-legales.md',
};

function parse(path) {
  const raw = readFileSync(path, 'utf8').replace(/\r\n/g, '\n');
  const match = raw.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
  if (!match) throw new Error(`${path} : en-tête manquant`);
  const header = Object.fromEntries(
    match[1].split('\n').map((line) => {
      const index = line.indexOf(':');
      return [line.slice(0, index).trim(), line.slice(index + 1).trim()];
    }),
  );
  const body = match[2].trim();
  if (/<\/?[a-zA-Z!][^>]*>/.test(body)) throw new Error(`${path} : le texte ne doit contenir aucune balise HTML`);
  return { type: header.type, title: header.title, version: header.version, body };
}

const texts = {};
for (const [type, file] of Object.entries(sources)) {
  const text = parse(join(root, 'docs', 'legal', file));
  if (text.type !== type) throw new Error(`${file} : type « ${text.type} » au lieu de « ${type} »`);
  texts[type] = text;
}

const output = `// Fichier généré par scripts/build-legal.mjs à partir de docs/legal : ne pas le modifier à la main.
// Projets de textes à faire valider par un juriste (docs/legal/README.md).

export type LegalTextType = ${Object.keys(sources).map((type) => `'${type}'`).join(' | ')};

export interface LegalText {
  type: LegalTextType;
  title: string;
  version: string;
  body: string;
}

/** Vrai tant que les textes n'ont pas été validés : un bandeau l'annonce sur chaque page. */
export const LEGAL_TEXTS_ARE_DRAFTS = true;

export const LEGAL_TEXTS: Record<LegalTextType, LegalText> = ${JSON.stringify(texts, null, 2)};
`;
writeFileSync(join(root, 'frontend', 'src', 'content', 'legal.ts'), output);
console.log('src/content/legal.ts régénéré.');

const jsonIndex = process.argv.indexOf('--json');
if (jsonIndex !== -1) {
  const [version, date] = process.argv.slice(jsonIndex + 1, jsonIndex + 3);
  if (!/^[A-Za-z0-9._-]{1,32}$/.test(version ?? '') || !/^\d{4}-\d{2}-\d{2}$/.test(date ?? '')) {
    throw new Error('Usage : --json <version> <AAAA-MM-JJ>');
  }
  const target = join(root, 'backend', 'database', 'data', 'legal');
  mkdirSync(target, { recursive: true });
  for (const type of ['terms', 'privacy']) {
    const english = join(root, 'docs', 'legal', 'en', sources[type]);
    if (!existsSync(english)) throw new Error(`Traduction anglaise manquante : docs/legal/en/${sources[type]}`);
    const fr = texts[type];
    const en = parse(english);
    const document = {
      type,
      version,
      effective_at: `${date}T00:00:00Z`,
      title: { fr: fr.title, en: en.title },
      body: { fr: fr.body, en: en.body },
    };
    const file = join(target, `${type}-${version}.json`);
    writeFileSync(file, `${JSON.stringify(document, null, 2)}\n`);
    console.log(`${file} écrit.`);
  }
}
