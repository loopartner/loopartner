import type { ArticleBlock, Segment } from '@/lib/types';

/**
 * Corps d'un article : document JSON à blocs renvoyé par l'API (jamais de HTML). Chaque lien
 * reprend tel quel le `rel` fourni par l'API (nofollow ugc pour les auteurs extérieurs).
 */

function Segments({ segments }: { segments: Segment[] }) {
  return (
    <>
      {segments.map((segment, index) => {
        let node: React.ReactNode = segment.text;
        if (segment.marks.includes('italic')) node = <em>{node}</em>;
        if (segment.marks.includes('bold')) node = <strong>{node}</strong>;
        if (segment.link) {
          node = (
            <a href={segment.link.href} rel={segment.link.rel} target="_blank">
              {node}
            </a>
          );
        }
        return <span key={index}>{node}</span>;
      })}
    </>
  );
}

export function ArticleBody({ blocks }: { blocks: ArticleBlock[] }) {
  return (
    <div className="prose">
      {blocks.map((block, index) => {
        switch (block.type) {
          case 'paragraph':
            return (
              <p key={index}>
                <Segments segments={block.content} />
              </p>
            );
          case 'heading':
            return block.level === 2 ? (
              <h2 key={index}>
                <Segments segments={block.content} />
              </h2>
            ) : (
              <h3 key={index}>
                <Segments segments={block.content} />
              </h3>
            );
          case 'list': {
            const items = block.items.map((item, itemIndex) => (
              <li key={itemIndex}>
                <Segments segments={item} />
              </li>
            ));
            return block.style === 'ordered' ? <ol key={index}>{items}</ol> : <ul key={index}>{items}</ul>;
          }
          case 'quote':
            return (
              <blockquote key={index}>
                <Segments segments={block.content} />
                {block.attribution ? <footer>— {block.attribution}</footer> : null}
              </blockquote>
            );
          case 'image':
            return (
              <figure key={index}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={block.url} alt={block.alt ?? ''} width={block.width} height={block.height} loading="lazy" style={{ borderRadius: 20, height: 'auto' }} />
                {block.caption ? <figcaption>{block.caption}</figcaption> : null}
              </figure>
            );
          case 'divider':
            return <hr key={index} />;
          default:
            return null;
        }
      })}
    </div>
  );
}
