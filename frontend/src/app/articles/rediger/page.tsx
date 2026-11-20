import type { Metadata } from 'next';
import { PageShell } from '@/components/PageShell';
import { ArticleEditor } from './ArticleEditor';

export const metadata: Metadata = { title: 'Rédiger un article' };

export default function WriteArticlePage() {
  return (
    <PageShell title="Rédiger un article" subtitle="Partagez votre expertise. Votre article est relu par l'équipe avant publication.">
      <ArticleEditor />
    </PageShell>
  );
}
