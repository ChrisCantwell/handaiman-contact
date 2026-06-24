=== HandAIMan Contact ===
Contributors: HandAIMan, ChatGPT
Tags: contact, form, messages, shortcode
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 0.2.0
License: GPLv2 or later

A lightweight branded contact form for TheHandAIMan. It saves messages locally, sends optional email notifications, and uses quiet anti-spam defenses without CAPTCHA or third-party services.

== Shortcodes ==

[handaiman_contact]
[ha_contact]

Examples:
[handaiman_contact topic="repair"]
[handaiman_contact topic="podcast" heading="Send TheHandAIMan a Note"]
[handaiman_contact topic="support" show_topic="no"]
[handaiman_contact collapsed="yes"]
[handaiman_contact collapsed="yes" open="yes"]
[handaiman_contact collapsed="yes" summary="Contact TheHandAIMan"]

== Features ==

* Local WordPress admin message inbox
* Email notifications using wp_mail
* Topic dropdown configured in admin
* Quote-permission checkbox
* Collapsed shortcode mode
* Optional auto-append to posts and podcast episodes
* Auto-opens collapsed form after success/error redirect
* Honeypot field
* Minimum submit time check
* IP-hash rate limit
* Link-count spam check
* Blocked terms and blocked email domains
* Spam storage can be enabled or disabled

== Notes ==

Manual shortcodes are expanded by default. Auto-appended contact forms can be rendered collapsed from the settings page. This plugin does not depend on external services. Email delivery depends on the WordPress site's mail configuration.
