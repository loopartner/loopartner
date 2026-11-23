import { initials } from '@/lib/format';

/** Photo (logo du prestataire) ou initiales de l'interlocuteur. */
export function ChatAvatar({ name, logo, size = 'md' }: { name: string; logo: string | null | undefined; size?: 'sm' | 'md' | 'lg' }) {
  const className = `avatar chat-avatar chat-avatar--${size}`;
  return logo ? (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={logo} alt="" className={className} />
  ) : (
    <span className={className} aria-hidden="true">
      {initials(name)}
    </span>
  );
}
