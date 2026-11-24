/**
 * Mode démonstration : images envoyées par « POST /media », réduites dans le navigateur et
 * gardées en data URL dans le localStorage pour survivre au rechargement de la page.
 */

const KEY = 'loopartner:demo-media';
const MAX_SIDE = 1400;

function all(): Record<string, string> {
  try {
    return JSON.parse(window.localStorage.getItem(KEY) ?? '{}') as Record<string, string>;
  } catch {
    return {};
  }
}

export function demoMediaUrl(uuid: unknown): string | null {
  if (typeof uuid !== 'string' || typeof window === 'undefined') return null;
  return all()[uuid] ?? null;
}

async function shrink(file: Blob): Promise<string> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL('image/jpeg', 0.82);
}

export async function storeDemoMedia(file: Blob): Promise<{ uuid: string; url: string }> {
  const uuid = crypto.randomUUID();
  let url: string;
  try {
    url = await shrink(file);
  } catch {
    url = URL.createObjectURL(file);
  }
  try {
    window.localStorage.setItem(KEY, JSON.stringify({ ...all(), [uuid]: url }));
  } catch {
    // Stockage plein : l'image reste visible jusqu'au rechargement.
  }
  return { uuid, url };
}
