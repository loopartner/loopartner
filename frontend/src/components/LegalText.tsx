import type { ReactNode } from 'react';

type Block =
  | { kind: 'h2'; text: string; id: string }
  | { kind: 'h3'; text: string }
  | { kind: 'p'; text: string }
  | { kind: 'ul'; items: string[] };

function slugify(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 60);
}

/**
 * Découpe un texte légal en blocs : « ## » titre, « ### » sous-titre, « - » élément de liste,
 * ligne vide entre deux paragraphes (docs/legal/README.md). Aucun HTML n'est interprété.
 */
export function parseLegalText(body: string): Block[] {
  const blocks: Block[] = [];
  const used = new Set<string>();
  for (const chunk of body.replace(/\r\n/g, '\n').split(/\n{2,}/)) {
    const lines = chunk.split('\n').map((line) => line.trim()).filter(Boolean);
    if (lines.length === 0) continue;
    let paragraph: string[] = [];
    let list: string[] = [];
    const flush = () => {
      if (paragraph.length) blocks.push({ kind: 'p', text: paragraph.join(' ') });
      if (list.length) blocks.push({ kind: 'ul', items: list });
      paragraph = [];
      list = [];
    };
    for (const line of lines) {
      if (line.startsWith('### ')) {
        flush();
        blocks.push({ kind: 'h3', text: line.slice(4) });
      } else if (line.startsWith('## ')) {
        flush();
        const text = line.slice(3);
        let id = slugify(text) || 'section';
        while (used.has(id)) id = `${id}-2`;
        used.add(id);
        blocks.push({ kind: 'h2', text, id });
      } else if (line.startsWith('- ')) {
        if (paragraph.length) {
          blocks.push({ kind: 'p', text: paragraph.join(' ') });
          paragraph = [];
        }
        list.push(line.slice(2));
      } else if (list.length) {
        list[list.length - 1] += ` ${line}`;
      } else {
        paragraph.push(line);
      }
    }
    flush();
  }
  return blocks;
}

/** Met en évidence les informations encore à compléter, « [À COMPLÉTER : …] ». */
function withPlaceholders(text: string): ReactNode {
  const parts = text.split(/(\[À COMPLÉTER[^\]]*\])/g);
  if (parts.length === 1) return text;
  return parts.map((part, index) =>
    part.startsWith('[À COMPLÉTER') ? (
      <mark key={index} className="legal-placeholder">
        {part}
      </mark>
    ) : (
      part
    ),
  );
}

export function LegalText({ body, toc = true }: { body: string; toc?: boolean }) {
  const blocks = parseLegalText(body);
  const headings = blocks.filter((block): block is Extract<Block, { kind: 'h2' }> => block.kind === 'h2');

  return (
    <div className="legal-text">
      {toc && headings.length > 3 ? (
        <nav className="legal-toc" aria-label="Sommaire">
          <p className="legal-toc__title">Sommaire</p>
          <ol>
            {headings.map((heading) => (
              <li key={heading.id}>
                <a href={`#${heading.id}`}>{heading.text}</a>
              </li>
            ))}
          </ol>
        </nav>
      ) : null}
      <div className="prose">
        {blocks.map((block, index) => {
          switch (block.kind) {
            case 'h2':
              return (
                <h2 key={index} id={block.id}>
                  {block.text}
                </h2>
              );
            case 'h3':
              return <h3 key={index}>{block.text}</h3>;
            case 'ul':
              return (
                <ul key={index}>
                  {block.items.map((item, itemIndex) => (
                    <li key={itemIndex}>{withPlaceholders(item)}</li>
                  ))}
                </ul>
              );
            default:
              return <p key={index}>{withPlaceholders(block.text)}</p>;
          }
        })}
      </div>
    </div>
  );
}
