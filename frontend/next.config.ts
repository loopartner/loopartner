import type { NextConfig } from 'next';

const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  images: {
    // Images servies par l'API (médias : disque public en local, Cloudflare R2 en production).
    remotePatterns: [new URL(`${apiUrl.replace(/\/+$/, '')}/**`)],
    unoptimized: true,
  },
  /*
   * Les liens envoyés par email par l'API pointent vers FRONTEND_URL/login, /forgot-password et
   * /reset-password : on les redirige vers les pages françaises (la requête est conservée).
   */
  async redirects() {
    return [
      { source: '/login', destination: '/connexion', permanent: false },
      { source: '/forgot-password', destination: '/mot-de-passe-oublie', permanent: false },
      { source: '/reset-password', destination: '/nouveau-mot-de-passe', permanent: false },
    ];
  },
  async headers() {
    return [
      {
        source: '/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          { key: 'X-Frame-Options', value: 'DENY' },
          { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
        ],
      },
    ];
  },
};

export default nextConfig;
