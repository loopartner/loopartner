/**
 * Brouillon du parcours « Publier un projet », gardé dans le navigateur (localStorage) le temps
 * de s'inscrire, de confirmer son email (souvent dans un autre onglet) et de se connecter.
 * Effacé à la publication, et ignoré après 7 jours.
 */

export type BudgetValue =
  "lt300" | "300-800" | "800-2000" | "gt2000" | "unknown";

export interface Draft {
  title: string;
  category: string | null;
  description: string;
  budget: BudgetValue | null;
  country: string;
  remote: boolean;
  /** Parcours terminé par un visiteur sans compte : à reprendre à la dernière étape. */
  ready?: boolean;
}

export const EMPTY_DRAFT: Draft = {
  title: "",
  category: null,
  description: "",
  budget: null,
  country: "FR",
  remote: false,
};

const DRAFT_KEY = "loopartner:project-draft";
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

export function readDraft(): Draft | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(DRAFT_KEY);
    if (!raw) return null;
    const { savedAt, draft } = JSON.parse(raw) as {
      savedAt?: number;
      draft?: Partial<Draft>;
    };
    if (
      typeof savedAt !== "number" ||
      Date.now() - savedAt > MAX_AGE_MS ||
      !draft
    ) {
      window.localStorage.removeItem(DRAFT_KEY);
      return null;
    }
    return { ...EMPTY_DRAFT, ...draft };
  } catch {
    return null;
  }
}

export function saveDraft(draft: Draft) {
  try {
    window.localStorage.setItem(
      DRAFT_KEY,
      JSON.stringify({ savedAt: Date.now(), draft }),
    );
  } catch {
    // Stockage indisponible (navigation privée stricte) : le brouillon reste en mémoire.
  }
}

export function clearDraft() {
  try {
    window.localStorage.removeItem(DRAFT_KEY);
  } catch {
    // Rien à faire.
  }
}

/** Un projet attend d'être publié après la connexion. */
export function hasPendingDraft(): boolean {
  return readDraft()?.ready === true;
}
