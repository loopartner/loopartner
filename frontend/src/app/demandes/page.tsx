import { redirect } from 'next/navigation';

/** Les demandes sont désormais dans la messagerie (propositions à accepter sur place). */
export default function RequestsPage() {
  redirect('/messages');
}
