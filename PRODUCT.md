# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- Primary, inferred from the repository and landing copy: event organizers in Gabon who need to publish, sell, collect, and operate without a technical team.
- Secondary: access-control agents using the companion mobile application, plus participants, donors, and voters following shared public links.

## Product Purpose

Pass241 gives an organizer a direct link for an event, fundraiser, or paid vote, accepts local mobile-money payments, issues tickets, and records the resulting financial activity. Success means an organizer can publish and start collecting quickly, while participants can pay and receive what they bought without creating an account.

## Positioning

The organizer brings the audience and Pass241 supplies the transaction and operations layer. There is no public event catalogue between the organizer and their participants. Access control continues offline on agents' phones and synchronizes when the network returns.

## Operating Context

- Public links are mainly shared through WhatsApp, social media, and printed materials.
- Payments use Airtel Money and Moov Money, in FCFA.
- Organizers operate event sales, fundraising, paid votes, invitations, refunds, exports, and settlements from the web application.
- Agents scan tickets at one or several entrances with intermittent connectivity.

## Capabilities and Constraints

- Multi-tenant isolation must fail closed; organization-scoped data must never leak across tenants.
- Financial records, confirmed donations, votes, and scan history are integrity-sensitive and must remain auditable.
- Identity verification is required when an organizer receives funds, not when they first create an account.
- Public payment and ticket flows must work without a participant account.
- Product claims, customer names, testimonials, contact details, prices, and performance figures must not be invented.
- The web application is Laravel 13 with Livewire; the access-control companion is an Expo application.

## Brand Commitments

- Name: Pass241.
- Existing logo mark and wordmark remain unchanged.
- French is the primary product language.
- The user requested a modern, chic, professional landing page.
- The established azure-blue identity remains recognizable; the redesign may recalibrate its use but not replace the brand.
- The existing Liquid Glass material language is a binding part of the redesign and must be refined rather than removed.
- The incumbent landing-page composition is liked and must be improved through targeted evolution, not replaced.
- Mobile-first behavior is a binding application-wide constraint; desktop enhancements must follow a complete small-screen experience.
- Visible product copy does not use emoji.

## Evidence on Hand

- Existing logo and application icons: `backend/public/brand/pass241-mark.png`, `backend/public/brand/pass241-mark.webp`, and favicon assets.
- Existing product demonstration: the real reusable ticket component in `backend/resources/views/components/glass-ticket.blade.php`.
- Existing event illustrations: `backend/public/brand/landing/`.
- No verified customer logos, testimonials, press references, or production metrics are present; future marketing work must not fabricate them.

## Product Principles

1. Make the next action obvious even on a small phone.
2. Use local payment and connectivity realities as product strengths, not footnotes.
3. Show the actual mechanism instead of repeating generic marketing claims.
4. Keep financial and tenant boundaries visible in both implementation and copy.
5. Prefer calm operational confidence over hype or gamification.

## Accessibility & Inclusion

The web experience must remain keyboard-operable, responsive, readable in light and dark modes, and respectful of reduced-motion preferences. Public journeys must work on modest mobile devices and unstable networks.
