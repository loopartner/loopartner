'use client';

import { useEffect, useMemo, useState } from 'react';
import { Icon } from '@/components/Icon';
import { api } from '@/lib/api';
import type { Category } from '@/lib/types';

const normalize = (text: string) =>
  text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');

/**
 * Choix de 1 à `max` catégories actives (catégories et sous-catégories de GET /categories),
 * rangées par famille dépliable, avec une recherche. Les domaines choisis restent visibles en
 * haut et se retirent d'un clic.
 */
export function CategoryPicker({ value, onChange, max, error, label = 'Catégories' }: { value: string[]; onChange: (slugs: string[]) => void; max: number; error?: string; label?: string }) {
  const [tree, setTree] = useState<Category[] | null>(null);
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState<string[]>([]);

  useEffect(() => {
    let active = true;
    api<{ data: Category[] }>('categories')
      .then((response) => active && setTree(response.data))
      .catch(() => active && setTree([]));
    return () => {
      active = false;
    };
  }, []);

  const flat = useMemo(() => (tree ?? []).flatMap((parent) => [parent, ...(parent.children ?? [])]), [tree]);
  const query = normalize(search.trim());

  const toggle = (slug: string) => {
    if (value.includes(slug)) onChange(value.filter((item) => item !== slug));
    else if (value.length < max) onChange([...value, slug]);
  };
  const full = value.length >= max;

  return (
    <fieldset className="field category-picker" style={{ border: 0, padding: 0, margin: 0 }}>
      <legend className="field__label" style={{ marginBottom: 8 }}>
        {label} <small style={{ color: 'var(--muted)', fontWeight: 400 }}>({value.length} / {max})</small>
      </legend>
      {tree === null ? (
        <div className="skeleton" style={{ height: 80 }} />
      ) : (
        <div className="form-stack" style={{ gap: 12 }}>
          {value.length > 0 ? (
            <div className="chips" aria-label="Domaines choisis">
              {value.map((slug) => (
                <button key={slug} type="button" className="chip" aria-pressed="true" onClick={() => toggle(slug)} aria-label={`Retirer ${flat.find((item) => item.slug === slug)?.name ?? slug}`}>
                  {flat.find((item) => item.slug === slug)?.name ?? slug}
                  <Icon name="close" size={12} />
                </button>
              ))}
            </div>
          ) : (
            <p className="field__hint" style={{ margin: 0 }}>
              Choisissez vos métiers dans les familles ci-dessous, ou recherchez-les. Choisir une famille entière couvre toutes ses spécialités.
            </p>
          )}
          <label className="input-with-icon">
            <span className="visually-hidden">Rechercher un métier</span>
            <Icon name="search" size={16} />
            <input
              className="input"
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Rechercher un métier : développeur, illustration, comptable…"
              maxLength={60}
            />
          </label>
          <div className="category-picker__families">
            {tree.map((parent) => {
              const children = parent.children ?? [];
              const parentMatches = !query || normalize(parent.name).includes(query);
              const shownChildren = parentMatches ? children : children.filter((child) => normalize(child.name).includes(query));
              if (!parentMatches && shownChildren.length === 0) return null;
              const selectedCount = [parent, ...children].filter((item) => value.includes(item.slug)).length;
              if (children.length === 0) return null;
              const expanded = Boolean(query) || open.includes(parent.slug);
              return (
                <div key={parent.slug} className="category-picker__family">
                  <button
                    type="button"
                    className="category-picker__toggle"
                    aria-expanded={expanded}
                    onClick={() => setOpen(expanded ? open.filter((slug) => slug !== parent.slug) : [...open, parent.slug])}
                  >
                    <span>{parent.name}</span>
                    {selectedCount > 0 ? <span className="category-picker__count">{selectedCount}</span> : null}
                    <Icon name="chevronDown" size={16} className={expanded ? 'category-picker__chevron--open' : undefined} />
                  </button>
                  {expanded ? (
                    <div className="chips category-picker__chips">
                      <PickerChip category={{ ...parent, name: `Tout « ${parent.name} »` }} slug={parent.slug} selected={value.includes(parent.slug)} disabled={full} onToggle={toggle} />
                      {shownChildren.map((child) => (
                        <PickerChip key={child.slug} category={child} selected={value.includes(child.slug)} disabled={full} onToggle={toggle} />
                      ))}
                    </div>
                  ) : null}
                </div>
              );
            })}
            {/* Catégories sans spécialité (assurances, traduction, autres) : une seule ligne. */}
            {tree.some((parent) => (parent.children ?? []).length === 0 && (!query || normalize(parent.name).includes(query))) ? (
              <div className="category-picker__family category-picker__family--single">
                <div className="chips">
                  {tree
                    .filter((parent) => (parent.children ?? []).length === 0 && (!query || normalize(parent.name).includes(query)))
                    .map((parent) => (
                      <PickerChip key={parent.slug} category={parent} selected={value.includes(parent.slug)} disabled={full} onToggle={toggle} />
                    ))}
                </div>
              </div>
            ) : null}
          </div>
          {query && !flat.some((item) => normalize(item.name).includes(query)) ? (
            <p className="field__hint" style={{ margin: 0 }}>
              Aucun métier ne correspond. Choisissez la famille la plus proche, ou « Autres ».
            </p>
          ) : null}
        </div>
      )}
      {error ? <span className="field__error">{error}</span> : null}
    </fieldset>
  );
}

function PickerChip({ category, slug = category.slug, selected, disabled, onToggle }: { category: Category; slug?: string; selected: boolean; disabled: boolean; onToggle: (slug: string) => void }) {
  return (
    <button type="button" className="chip" aria-pressed={selected} disabled={!selected && disabled} onClick={() => onToggle(slug)}>
      {category.name}
    </button>
  );
}
