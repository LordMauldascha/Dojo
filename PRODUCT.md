# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Users create and maintain LLM skills and testcases, compare models and providers, and review run versions. Roles: admins (see and change everything, manage users, groups and provider API keys) and normal users (only what belongs to projects they can access through a group or direct assignment). Whether individual roles (development, PM, consulting) use it differently is not decided.

## Product Purpose

Dojo is a web platform to create, evaluate, test and optimize LLM skills. People work in projects. A project has members (users and groups) and owns the skills and testcases its members work with. A run combines skills, testcases and LLM configurations (provider, model, optional effort) of one project and is versioned on every start. Success: a team can see which version of a skill performs how, on which model, against which testcases.

Current state: calling the LLMs and comparing their output is not built yet; starting a run only creates an immutable version.

## Positioning

Skills (SKILL.md texts with draft/final versions and restore) are the core object, evaluated against testcases across several models and providers. A generic eval tool built around prompts or datasets does not make the versioned skill the unit of work.

## Operating Context

Server-rendered Symfony app (Twig, Webpack Encore). Runs locally in Docker (app on http://localhost:8000). The whole interface is in English, dates are shown as `DD.MM.YYYY HH:MM` in Europe/Vienna. Work is table-driven: all overviews use server-side search, sorting and pagination (page size 10/20/50/100, state in the URL). Forms are submitted once per page view. Skills and testcases are never hard-deleted by users, so history and run comparisons stay intact.

## Capabilities and Constraints

- Entities: user, group, project, skill, testcase, run, run version, provider API key. Access derives from project membership and is enforced server-side (voters).
- Prompts are meant to be sent to LLM providers later: no personal data in skills, testcases, descriptions or any free text. Use synthetic data (GDPR).
- Provider API keys are stored encrypted and, with the LLM judge model, managed by admins on the LLM Configuration page.
- Providers, models and effort levels are defined in code, not in the interface.
- Not built yet: calling the LLMs, comparing their outputs.

## Brand Commitments

Binding color palette, set by the user:

- Brown `#603e1e`: brand base color (used for selected surfaces).
- Main background `#2c2015`: a darker brown, set by the user because `#603e1e` was too light as a background.
- Pale Gold `#f1d9a8`: secondary color.
- Glowing Gold `#ffc34d`: highlights.
- Text in a warm off-white, not pure white.
- Style: reminiscent of a traditional dojo (dark wood, paper-gold, straight joinery), user-confirmed. It may be somewhat playful (user-confirmed): the current navigation item sits on a wooden breaking board like the ones broken in karate. The interface keeps standard controls and layout; the theme enters through palette, heading type, square edges, the seal and the board.

This palette replaced the earlier navy, blue and red palette. Red is no brand color and appears only for errors and destructive actions.

The earlier project rule "no icons and no colors anywhere" applies to tables only, with three exceptions: the sort arrow (text color), the accent color on link hover and the version status dot (green final, yellow draft). Elsewhere in the interface, color and icons are allowed.

## Evidence on Hand

Feature documentation in `docs/Features.md` and `docs/features/`, project plans in `Plans/`. No logo, brand guide, user research, testimonials or usage data on hand. Do not fabricate any.

## Product Principles

1. The skill is the unit of work: versions, history and comparison come before generic eval features.
2. Runs are evidence: a started run is an immutable snapshot, never edited afterwards.
3. Access follows the project: what someone sees and may do derives from project membership, never from the interface alone.
4. No personal data in prompts or free text: synthetic data only.
5. Tables stay plain and scannable: no icons or colors inside them, except the sort arrow, the link hover accent and the version status dot.
