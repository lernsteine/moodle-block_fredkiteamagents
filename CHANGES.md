# Changes

## 1.2.0 (2026-11-15)

- Moodle Marketplace: no links to purchase pages outside Moodle Marketplace. When the package of a site does not include
  Team Agents, the block and the setup page point site administrators to the activation page of FredKI The Agent
  (licence key) and to the plugin's page in Moodle Marketplace; teachers are asked to contact the administrator.
- README: costs (free for one course, Team Agents packages as paid options in Moodle Marketplace).
- Moodle 5.0 to 5.3 supported (tested with Moodle 4.5 and 5.3). Moodle 5.3+: agent accounts are created with
  `\core\user::create_user()`; the management page sets its page URL.

## 1.1.2 (2026-11-14)

- New page "Set up FredKI Team Agents" (site administration, linked from the plugin settings and from the block):
  a checklist with the activation of FredKI, the package with Team Agents and the first steps in a course.
- When the site is not connected yet, the block offers administrators a button to the setup.

## 1.1.1 (2026-11-13)

- Requires the course format FredKI learning space (`format_fredki`). In a course with another format the block
  tells trainers that Team Agents work in the FredKI learning space and links to the course settings.

## 1.1.0 (2026-11-13)

- Team Agents is its own FredKI package (trial, free package for one course, or a Team Agents plan). When the booked
  package covers FredKI The Agent only, the licence server refuses Team Agents; the block shows a notice with a link
  to upgrade, and the notice disappears as soon as Team Agents work again.

## 1.0.0 (2026-11-12) – first stable release

- AI agents as course participants with their own Moodle account and knowledge folder in the course.
- Learning companion: private conversations in the learning space and in the Moodle messages (with the earlier
  turns of the conversation), question of the day and answers to open questions.
- Expert round with the templates Expert, Critic, Optimist, Practitioner, Moderator and Supervisor; templates are
  managed centrally in FredKI.
- Agents and knowledge bases are filed in FredKI per site and per course; the course page does not wait for
  FredKI.
- Daily limit per agent (unlimited by default), pause and resume.
- Code style according to the Moodle coding style; minified JavaScript build.
