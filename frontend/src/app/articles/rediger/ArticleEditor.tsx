'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { MediaUpload } from '@/components/MediaUpload';
import { api, ApiError } from '@/lib/api';
import { textToBlocks, wordCount } from '@/lib/richtext';
import { useSession } from '@/lib/session';
import type { Category } from '@/lib/types';

/**
 * Rédaction d'un article (maquette « rédiger un article ») : brouillon (`POST /me/articles`)
 * puis envoi pour validation (`POST /me/articles/{uuid}/submit`). Le corps est saisi avec une
 * syntaxe simple, convertie en blocs ; le serveur n'accepte aucun HTML.
 */
export function ArticleEditor() {
  const { user, loading, hasRole } = useSession();
  const [categories, setCategories] = useState<Category[]>([]);
  const [uuid, setUuid] = useState<string | null>(null);
  const [title, setTitle] = useState('');
  const [category, setCategory] = useState('');
  const [excerpt, setExcerpt] = useState('');
  const [body, setBody] = useState('');
  const [authorName, setAuthorName] = useState('');
  const [cover, setCover] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const bodyRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    api<{ data: Category[] }>('categories')
      .then((response) => setCategories(response.data.flatMap((item) => [item, ...(item.children ?? [])])))
      .catch(() => setCategories([]));
  }, []);

  if (loading) return <div className="skeleton" style={{ height: 400 }} />;

  if (!user || !hasRole('prestataire', 'entrepreneur', 'redacteur', 'admin')) {
    return (
      <div className="card empty">
        <p>Les articles sont rédigés par les prestataires, les entrepreneurs et la rédaction.</p>
        {!user ? (
          <Link href="/connexion?next=/articles/rediger" className="btn btn--lime">
            Se connecter
          </Link>
        ) : null}
      </div>
    );
  }

  const isEntrepreneur = hasRole('entrepreneur') && !hasRole('redacteur', 'admin');
  const words = wordCount(body);

  function wrap(before: string, after = before, placeholder = 'texte') {
    const element = bodyRef.current;
    if (!element) return;
    const start = element.selectionStart;
    const end = element.selectionEnd;
    const selected = body.slice(start, end) || placeholder;
    const next = `${body.slice(0, start)}${before}${selected}${after}${body.slice(end)}`;
    setBody(next);
    requestAnimationFrame(() => {
      element.focus();
      element.setSelectionRange(start + before.length, start + before.length + selected.length);
    });
  }

  function prefixLine(prefix: string) {
    const element = bodyRef.current;
    if (!element) return;
    const start = element.selectionStart;
    const lineStart = body.lastIndexOf('\n', start - 1) + 1;
    setBody(`${body.slice(0, lineStart)}${prefix}${body.slice(lineStart)}`);
    requestAnimationFrame(() => element.focus());
  }

  function payload() {
    return {
      title: title.trim(),
      language: 'fr',
      excerpt: excerpt.trim() || null,
      body: body.trim() ? textToBlocks(body) : null,
      categories: category ? [category] : [],
      ...(isEntrepreneur ? { author_name: authorName.trim() || null } : {}),
      ...(cover ? { cover } : {}),
    };
  }

  function showErrors(exception: unknown) {
    if (exception instanceof ApiError) {
      const fields = Object.fromEntries(Object.entries(exception.errors).map(([key, messages]) => [key.split('.')[0], messages[0]]));
      setErrors(fields);
      setError(
        exception.code === 'premium_required'
          ? 'Vous avez utilisé vos articles gratuits. Passez au Premium pour publier sans limite.'
          : Object.keys(fields).length > 0
            ? 'Certains champs sont à corriger.'
            : exception.message,
      );
    } else {
      setError("L'enregistrement a échoué.");
    }
  }

  async function save(): Promise<string | null> {
    setErrors({});
    setError(null);
    setNotice(null);
    const response = uuid
      ? await api<{ data: { uuid: string } }>(`me/articles/${uuid}`, { method: 'PATCH', body: payload() })
      : await api<{ data: { uuid: string } }>('me/articles', { method: 'POST', body: payload() });
    setUuid(response.data.uuid);
    return response.data.uuid;
  }

  async function saveDraft() {
    setBusy(true);
    try {
      await save();
      setNotice('Brouillon enregistré.');
    } catch (exception) {
      showErrors(exception);
    } finally {
      setBusy(false);
    }
  }

  async function submit() {
    setBusy(true);
    try {
      const id = await save();
      await api(`me/articles/${id}/submit`, { method: 'POST' });
      setNotice(
        hasRole('redacteur', 'admin')
          ? "L'article est publié."
          : "Votre article est envoyé à l'équipe. Vous recevrez un email après sa relecture.",
      );
    } catch (exception) {
      showErrors(exception);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="card form-stack" style={{ maxWidth: 760, margin: '0 auto', padding: 48, gap: 28 }}>
      {user.article_quota && !user.article_quota.premium && user.article_quota.remaining !== null ? (
        <p className="alert alert--info">
          Articles gratuits restants : {user.article_quota.remaining} sur {user.article_quota.limit}.
        </p>
      ) : null}
      <TextField label="Titre de l'article" placeholder="Ex : Les 5 erreurs à éviter dans vos statuts…" value={title} onChange={(event) => setTitle(event.target.value)} maxLength={160} counter error={errors.title} />
      <div className="field">
        <label className="field__label" htmlFor="article-category">
          Catégorie
        </label>
        <select id="article-category" className="select" value={category} onChange={(event) => setCategory(event.target.value)} aria-invalid={errors.categories ? true : undefined}>
          <option value="">Sélectionnez une catégorie</option>
          {categories.map((item) => (
            <option key={item.slug} value={item.slug}>
              {item.name}
            </option>
          ))}
        </select>
        {errors.categories ? <span className="field__error">{errors.categories}</span> : null}
      </div>
      <TextAreaField label="Résumé" rows={3} placeholder="Courte introduction pour accrocher vos lecteurs…" value={excerpt} onChange={(event) => setExcerpt(event.target.value)} maxLength={300} counter error={errors.excerpt} hint="50 caractères au moins." />
      {isEntrepreneur ? (
        <TextField label="Signature" placeholder="Votre nom tel qu'il apparaîtra sous l'article" value={authorName} onChange={(event) => setAuthorName(event.target.value)} maxLength={80} error={errors.author_name} />
      ) : null}
      <div className="field">
        <div className="field__label-row">
          <label className="field__label" htmlFor="article-body">
            Contenu de l&apos;article
          </label>
          <span className="field__counter">{words} mots (200 au moins)</span>
        </div>
        <div className="editor">
          <div className="editor__toolbar" role="toolbar" aria-label="Mise en forme">
            <button type="button" onClick={() => wrap('**')} aria-label="Gras">
              <Icon name="bold" size={16} />
            </button>
            <button type="button" onClick={() => wrap('*')} aria-label="Italique">
              <Icon name="italic" size={16} />
            </button>
            <span className="editor__sep" />
            <button type="button" onClick={() => prefixLine('## ')}>
              H1
            </button>
            <button type="button" onClick={() => prefixLine('### ')}>
              H2
            </button>
            <span className="editor__sep" />
            <button type="button" onClick={() => prefixLine('- ')} aria-label="Liste à puces">
              <Icon name="list" size={16} />
            </button>
            <button type="button" onClick={() => wrap('[', '](https://)', 'texte du lien')} aria-label="Lien">
              <Icon name="link" size={16} />
            </button>
          </div>
          <textarea
            id="article-body"
            ref={bodyRef}
            className="textarea"
            placeholder={'Commencez à écrire ici…\n\n## Un intertitre\nUn paragraphe avec du **gras** et un [lien](https://exemple.fr).\n- un point\n- un autre point'}
            value={body}
            onChange={(event) => setBody(event.target.value)}
            aria-invalid={errors.body ? true : undefined}
          />
        </div>
        {errors.body ? <span className="field__error">{errors.body}</span> : null}
      </div>
      <MediaUpload purpose="article_cover" label="Image de couverture (facultatif, 1200 × 630 px au moins)" onUploaded={setCover} />
      <p className="note">
        <span className="note__icon">
          <Icon name="lock" size={16} />
        </span>
        Votre article sera relu par notre équipe avant publication pour s&apos;assurer qu&apos;il respecte notre charte éditoriale.
        Les liens des auteurs extérieurs sont marqués « nofollow ».
      </p>
      <p className="disclaimer">Les articles sont informatifs et ne constituent pas un conseil juridique personnalisé.</p>
      <FormError message={error} />
      {notice ? <p className="alert alert--success">{notice}</p> : null}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <button type="button" className="btn btn--outline btn--lg" disabled={busy || title.trim().length < 10} onClick={saveDraft}>
          Enregistrer le brouillon
        </button>
        <button type="button" className="btn btn--lime btn--lg" disabled={busy || title.trim().length < 10} onClick={submit}>
          Envoyer pour validation
        </button>
      </div>
    </div>
  );
}
