import Link from 'next/link';
import { Icon } from './Icon';

/** Pagination des listes : pages voisines, première et dernière, flèche suivante. */
export function Pagination({ current, last, href }: { current: number; last: number; href: (page: number) => string }) {
  if (last <= 1) return null;

  const pages = new Set<number>([1, last, current - 1, current, current + 1]);
  const visible = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);

  return (
    <nav className="pagination" aria-label="Pagination">
      {current > 1 ? (
        <Link href={href(current - 1)} aria-label="Page précédente">
          <Icon name="arrowLeft" size={16} />
        </Link>
      ) : null}
      {visible.map((page, index) => (
        <span key={page} style={{ display: 'contents' }}>
          {index > 0 && page - visible[index - 1] > 1 ? <span aria-hidden="true">…</span> : null}
          <Link href={href(page)} aria-current={page === current ? 'page' : undefined}>
            {page}
          </Link>
        </span>
      ))}
      {current < last ? (
        <Link href={href(current + 1)} aria-label="Page suivante">
          <Icon name="arrowRight" size={16} />
        </Link>
      ) : null}
    </nav>
  );
}
