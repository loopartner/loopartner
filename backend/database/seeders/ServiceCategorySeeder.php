<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Catégories de services de référence, dans tous les environnements (comme les rôles).
 *
 * Le seeder est idempotent : il crée les catégories manquantes et ne modifie jamais une
 * catégorie existante, pour ne pas écraser les changements faits dans le back-office.
 */
class ServiceCategorySeeder extends Seeder
{
    /**
     * slug => [fr, en, sous-catégories (slug => [fr, en])], dans l'ordre d'affichage.
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, array{0: string, 1: string}>}>
     */
    public const CATEGORIES = [
        'juridique' => ['Juridique', 'Legal', [
            'creation-statuts' => ['Création et statuts', 'Company formation'],
            'contrats-cgv' => ['Contrats et CGV', 'Contracts & terms of sale'],
            'propriete-intellectuelle' => ['Propriété intellectuelle', 'Intellectual property'],
        ]],
        'comptabilite-fiscalite' => ['Comptabilité et fiscalité', 'Accounting & tax', [
            'tenue-comptable' => ['Tenue comptable', 'Bookkeeping'],
            'conseil-fiscal' => ['Conseil fiscal', 'Tax advice'],
            'paie' => ['Paie', 'Payroll'],
        ]],
        'financement' => ['Financement', 'Funding', [
            'business-plan' => ['Business plan et prévisionnel', 'Business plan & forecasts'],
            'levee-de-fonds' => ['Levée de fonds', 'Fundraising'],
            'aides-subventions' => ['Aides et subventions', 'Grants & public funding'],
        ]],
        'marketing-communication' => ['Marketing et communication', 'Marketing & communication', [
            'strategie-marketing' => ['Stratégie marketing', 'Marketing strategy'],
            'reseaux-sociaux' => ['Réseaux sociaux', 'Social media'],
            'referencement-seo' => ['Référencement (SEO)', 'SEO'],
            'relations-presse' => ['Relations presse', 'Press relations'],
            'photo-video' => ['Photo et vidéo', 'Photo & video'],
            'publicite-en-ligne' => ['Publicité en ligne', 'Online advertising'],
            'influence' => ['Marketing d\'influence', 'Influencer marketing'],
        ]],
        'design' => ['Design', 'Design', [
            'identite-visuelle' => ['Identité visuelle et logo', 'Brand identity & logo'],
            'design-ui-ux' => ['Design UI/UX', 'UI/UX design'],
            'supports-imprimes' => ['Supports imprimés', 'Print design'],
            'packaging' => ['Packaging', 'Packaging design'],
            'motion-design-3d' => ['Motion design et 3D', 'Motion design & 3D'],
        ]],
        'developpement-web' => ['Développement web', 'Web development', [
            'site-vitrine' => ['Site vitrine', 'Showcase website'],
            'e-commerce' => ['E-commerce', 'E-commerce'],
            'applications-web-mobiles' => ['Applications web et mobiles', 'Web & mobile apps'],
            'logiciel-sur-mesure' => ['Logiciel sur mesure', 'Custom software'],
            'automatisation-no-code' => ['Automatisation et no-code', 'Automation & no-code'],
            'data-ia' => ['Data et intelligence artificielle', 'Data & AI'],
            'cybersecurite' => ['Cybersécurité', 'Cybersecurity'],
            'maintenance-hebergement' => ['Maintenance et hébergement', 'Maintenance & hosting'],
        ]],
        // Ajoutée le 2026-11-25 (décision 84) : position fixée dans INSERTED_POSITIONS.
        'arts-contenus' => ['Arts et contenus', 'Arts & content', [
            'illustration' => ['Illustration', 'Illustration'],
            'photographie' => ['Photographie', 'Photography'],
            'video-montage' => ['Vidéo et montage', 'Video & editing'],
            'musique-sound-design' => ['Musique et sound design', 'Music & sound design'],
            'redaction-copywriting' => ['Rédaction et copywriting', 'Writing & copywriting'],
            'voix-off' => ['Voix off', 'Voice-over'],
        ]],
        'conseil-strategie' => ['Conseil en stratégie', 'Strategy consulting', [
            'etude-de-marche' => ['Étude de marché', 'Market research'],
            'modele-economique' => ['Modèle économique', 'Business model'],
            'coaching-entrepreneur' => ['Accompagnement du dirigeant', 'Founder coaching'],
        ]],
        'ressources-humaines' => ['Ressources humaines', 'Human resources', [
            'recrutement' => ['Recrutement', 'Recruitment'],
            'droit-du-travail' => ['Droit du travail', 'Employment law'],
            'formation' => ['Formation', 'Training'],
        ]],
        'assurances' => ['Assurances', 'Insurance', []],
        'traduction-localisation' => ['Traduction et localisation', 'Translation & localization', []],
        // Toujours en dernier : besoin qui n'entre dans aucune autre catégorie (décision 80).
        'autres' => ['Autres', 'Other', []],
    ];

    /**
     * Catégories parentes ajoutées après la première mise en production : position fixe, entre
     * deux catégories existantes, pour ne pas décaler les positions déjà enregistrées en base.
     * Les sous-catégories ajoutées le sont toujours en fin de liste de leur parent.
     *
     * @var array<string, int>
     */
    public const INSERTED_POSITIONS = [
        'arts-contenus' => 65,
    ];

    public function run(): void
    {
        $position = 0;

        foreach (self::CATEGORIES as $slug => [$fr, $en, $children]) {
            $parent = ServiceCategory::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => ['fr' => $fr, 'en' => $en], 'position' => self::INSERTED_POSITIONS[$slug] ?? ($position += 10)],
            );

            $childPosition = 0;

            foreach ($children as $childSlug => [$childFr, $childEn]) {
                ServiceCategory::query()->firstOrCreate(
                    ['slug' => $childSlug],
                    [
                        'parent_id' => $parent->id,
                        'name' => ['fr' => $childFr, 'en' => $childEn],
                        'position' => ($childPosition += 10),
                    ],
                );
            }
        }
    }
}
