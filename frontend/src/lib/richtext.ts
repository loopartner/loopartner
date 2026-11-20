/**
 * Conversion d'un texte saisi avec une syntaxe simple vers le document à blocs de l'API Blog
 * (`{"version": 1, "blocks": [...]}`). Le serveur revalide tout : ceci ne fait que préparer.
 *
 * Syntaxe (insérée par la barre d'outils de l'éditeur) :
 * - `## Titre` (niveau 2), `### Sous-titre` (niveau 3), sans lien ;
 * - `- élément` (liste à puces), `1. élément` (liste numérotée) ;
 * - `> citation` ;
 * - `**gras**`, `*italique*`, `[texte](https://exemple.fr)` ;
 * - une ligne vide sépare les paragraphes.
 */

export interface DraftSegment {
  text: string;
  marks?: Array<'bold' | 'italic'>;
  link?: string;
}

export type DraftBlock =
  | { type: 'paragraph'; content: DraftSegment[] }
  | { type: 'heading'; level: 2 | 3; content: DraftSegment[] }
  | { type: 'list'; style: 'bullet' | 'ordered'; items: DraftSegment[][] }
  | { type: 'quote'; content: DraftSegment[] };

const INLINE = /(\*\*([^*]+)\*\*|\*([^*]+)\*|\[([^\]]+)\]\((https:\/\/[^\s)]+)\))/g;

export function parseInline(text: string, allowLinks = true): DraftSegment[] {
  const segments: DraftSegment[] = [];
  let last = 0;
  for (const match of text.matchAll(INLINE)) {
    const index = match.index ?? 0;
    if (index > last) segments.push({ text: text.slice(last, index) });
    if (match[2] !== undefined) segments.push({ text: match[2], marks: ['bold'] });
    else if (match[3] !== undefined) segments.push({ text: match[3], marks: ['italic'] });
    else if (match[4] !== undefined) segments.push(allowLinks ? { text: match[4], link: match[5] } : { text: match[4] });
    last = index + match[0].length;
  }
  if (last < text.length) segments.push({ text: text.slice(last) });
  return segments.filter((segment) => segment.text !== '');
}

export function textToBlocks(source: string): { version: 1; blocks: DraftBlock[] } {
  const blocks: DraftBlock[] = [];
  const lines = source.replace(/\r\n?/g, '\n').split('\n');
  let paragraph: string[] = [];
  let list: { style: 'bullet' | 'ordered'; items: string[] } | null = null;

  const flushParagraph = () => {
    if (paragraph.length > 0) {
      const content = parseInline(paragraph.join('\n'));
      if (content.length > 0) blocks.push({ type: 'paragraph', content });
      paragraph = [];
    }
  };
  const flushList = () => {
    if (list !== null) {
      blocks.push({ type: 'list', style: list.style, items: list.items.map((item) => parseInline(item)) });
      list = null;
    }
  };

  for (const raw of lines) {
    const line = raw.trimEnd();
    const heading = /^(#{2,3})\s+(.+)$/.exec(line);
    const bullet = /^[-•]\s+(.+)$/.exec(line);
    const ordered = /^\d+[.)]\s+(.+)$/.exec(line);
    const quote = /^>\s?(.+)$/.exec(line);

    if (line.trim() === '') {
      flushParagraph();
      flushList();
    } else if (heading) {
      flushParagraph();
      flushList();
      blocks.push({ type: 'heading', level: heading[1].length === 2 ? 2 : 3, content: parseInline(heading[2], false) });
    } else if (bullet || ordered) {
      flushParagraph();
      const style = bullet ? 'bullet' : 'ordered';
      if (list === null || list.style !== style) {
        flushList();
        list = { style, items: [] };
      }
      list.items.push((bullet ?? ordered)![1]);
    } else if (quote) {
      flushParagraph();
      flushList();
      blocks.push({ type: 'quote', content: parseInline(quote[1]) });
    } else {
      flushList();
      paragraph.push(line);
    }
  }
  flushParagraph();
  flushList();

  return { version: 1, blocks };
}

/** Nombre de mots du texte (indication pour le minimum de 200 mots à la soumission). */
export function wordCount(source: string): number {
  return source
    .replace(/[#>*\-[\]()]/g, ' ')
    .split(/\s+/)
    .filter((word) => /\p{L}|\p{N}/u.test(word)).length;
}
