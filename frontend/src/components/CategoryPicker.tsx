'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import type { Category } from '@/lib/types';

/** Choix de 1 à `max` catégories actives (catégories et sous-catégories de GET /categories). */
export function CategoryPicker({ value, onChange, max, error, label = 'Catégories' }: { value: string[]; onChange: (slugs: string[]) => void; max: number; error?: string; label?: string }) {
  const [tree, setTree] = useState<Category[] | null>(null);

  useEffect(() => {
    let active = true;
    api<{ data: Category[] }>('categories')
      .then((response) => active && setTree(response.data))
      .catch(() => active && setTree([]));
    return () => {
      active = false;
    };
  }, []);

  const toggle = (slug: string) => {
    if (value.includes(slug)) onChange(value.filter((item) => item !== slug));
    else if (value.length < max) onChange([...value, slug]);
  };

  return (
    <fieldset className="field" style={{ border: 0, padding: 0, margin: 0 }}>
      <legend className="field__label" style={{ marginBottom: 8 }}>
        {label} <small style={{ color: 'var(--muted)', fontWeight: 400 }}>({value.length} / {max})</small>
      </legend>
      {tree === null ? (
        <div className="skeleton" style={{ height: 80 }} />
      ) : (
        <div className="form-stack" style={{ gap: 12 }}>
          {/* Catégories sans sous-catégorie : une seule ligne ; les autres : une ligne par famille. */}
          <div className="chips">
            {tree
              .filter((parent) => (parent.children ?? []).length === 0)
              .map((parent) => (
                <Chip key={parent.slug} category={parent} selected={value.includes(parent.slug)} disabled={!value.includes(parent.slug) && value.length >= max} onToggle={toggle} />
              ))}
          </div>
          {tree
            .filter((parent) => (parent.children ?? []).length > 0)
            .map((parent) => (
              <div key={parent.slug} className="chips">
                <Chip category={parent} selected={value.includes(parent.slug)} disabled={!value.includes(parent.slug) && value.length >= max} onToggle={toggle} strong />
                {(parent.children ?? []).map((child) => (
                  <Chip key={child.slug} category={child} selected={value.includes(child.slug)} disabled={!value.includes(child.slug) && value.length >= max} onToggle={toggle} />
                ))}
              </div>
            ))}
        </div>
      )}
      {error ? <span className="field__error">{error}</span> : null}
    </fieldset>
  );
}

function Chip({ category, selected, disabled, onToggle, strong = false }: { category: Category; selected: boolean; disabled: boolean; onToggle: (slug: string) => void; strong?: boolean }) {
  return (
    <button
      type="button"
      className="chip"
      aria-pressed={selected}
      disabled={disabled}
      onClick={() => onToggle(category.slug)}
      style={strong ? { fontWeight: 700 } : undefined}
    >
      {category.name}
    </button>
  );
}
