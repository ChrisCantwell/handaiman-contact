# HandAIMan Contact

AI-assisted WordPress contact form and local message inbox plugin for TheHandAIMan.

Built by **TheHandAIMan with ChatGPT** as part of the broader HandAIMan / HandAIStack WordPress tooling project.

## Current stable version

`0.2.1`

## Features

- Branded contact form shortcode
- Local WordPress admin message inbox
- Email notifications through `wp_mail`
- Topic selector and quote-permission checkbox
- Quiet anti-spam protections
- Collapsed panel mode
- Optional auto-append to posts and podcast episodes
- HandAIStack admin menu integration when HandAIStack Core is active

## Shortcodes

- `[handaiman_contact]`
- `[ha_contact]`

## Installation

1. Download the plugin ZIP from a release, or package this repository as a WordPress plugin folder.
2. Upload it in WordPress under **Plugins → Add New → Upload Plugin**.
3. Activate the plugin.
4. Configure notification and form settings from the WordPress admin menu.

When **HandAIStack Core** is active, this plugin's settings appear under the HandAIStack admin menu. Without Core, the plugin keeps standalone fallback admin behavior.

## Privacy note

This plugin stores submitted messages locally in WordPress and can send notification emails through the site's configured mail system. Site owners are responsible for their own privacy policy, retention practices, and legal compliance.

## AI Attribution

This plugin was created through a human-directed, AI-assisted workflow. TheHandAIMan defined the requirements, tested the code on a live WordPress site, made product/design decisions, and approved releases. Primary code generation for this baseline was done by ChatGPT.

See [AI_ATTRIBUTION.md](AI_ATTRIBUTION.md) for the full attribution statement.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
