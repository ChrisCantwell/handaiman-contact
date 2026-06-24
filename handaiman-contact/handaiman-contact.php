<?php
/**
 * Plugin Name: HandAIMan Contact
 * Description: Lightweight branded contact form with local message storage, email notifications, and quiet anti-spam. Use [handaiman_contact].
 * Version: 0.2.1
 * Author: HandAIMan / ChatGPT
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

class HandAIMan_Contact_Plugin {
    const VERSION = '0.2.1';
    const OPTION_KEY = 'ha_contact_options';
    const NONCE_ACTION = 'ha_contact_submit';
    const MENU_SLUG = 'handaiman-contact';

    public static function init() {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));

        add_action('init', array(__CLASS__, 'handle_submission'), 5);
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_ha_contact_update_status', array(__CLASS__, 'admin_update_status'));

        add_shortcode('handaiman_contact', array(__CLASS__, 'shortcode'));
        add_shortcode('ha_contact', array(__CLASS__, 'shortcode'));

        add_filter('the_content', array(__CLASS__, 'maybe_auto_append'), 30);
    }

    public static function activate() {
        self::create_table();
        self::options();
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ha_contact_messages';
    }

    private static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $table = self::table_name();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'new',
            spam_reason varchar(255) DEFAULT '',
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            topic varchar(80) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            message longtext NOT NULL,
            quote_ok tinyint(1) NOT NULL DEFAULT 0,
            source_url text,
            source_post_id bigint(20) unsigned DEFAULT NULL,
            ip_hash varchar(64) DEFAULT '',
            user_agent text,
            PRIMARY KEY  (id),
            KEY status_created (status, created_at),
            KEY email (email),
            KEY topic (topic)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function defaults() {
        return array(
            'form_heading' => 'Contact TheHandAIMan',
            'form_intro' => 'Questions, repair stories, episode ideas, useful warnings, and mildly deranged encouragement may be submitted below.',
            'success_message' => 'Message received. Thanks for reaching out.',
            'submit_label' => 'Send message',
            'notification_email' => get_option('admin_email'),
            'email_subject_prefix' => '[TheHandAIMan Contact]',
            'store_spam' => 1,
            'notify_spam' => 0,
            'min_seconds' => 4,
            'rate_limit_minutes' => 2,
            'max_links' => 4,
            'require_email' => 1,
            'quote_checkbox_enabled' => 1,
            'quote_checkbox_label' => 'You may quote this message publicly, but do not publish my email address.',
            'topics' => "general|General message\nrepair|Repair question\npodcast|Podcast / media inquiry\nsupport|Support problem\nwebsite|Website bug report\nother|Other",
            'blocked_terms' => '',
            'blocked_email_domains' => '',
            'privacy_note' => 'Your message is saved in the site admin area and may also be sent by email notification.',
            'collapsed_summary' => 'Contact TheHandAIMan',
            'auto_append_posts' => 0,
            'auto_append_podcast' => 0,
            'auto_append_collapsed' => 1,
            'auto_append_open' => 0,
        );
    }

    private static function options() {
        $defaults = self::defaults();
        $saved = get_option(self::OPTION_KEY, array());
        if (!is_array($saved)) { $saved = array(); }
        $merged = array_merge($defaults, $saved);
        if ($merged !== $saved) {
            update_option(self::OPTION_KEY, $merged);
        }
        return $merged;
    }

    public static function register_settings() {
        register_setting('ha_contact_settings', self::OPTION_KEY, array(__CLASS__, 'sanitize_options'));
    }

    public static function sanitize_options($input) {
        $defaults = self::defaults();
        $out = array();

        $text_fields = array('form_heading', 'submit_label', 'notification_email', 'email_subject_prefix', 'quote_checkbox_label', 'collapsed_summary');
        foreach ($text_fields as $field) {
            $out[$field] = isset($input[$field]) ? sanitize_text_field(wp_unslash($input[$field])) : $defaults[$field];
        }

        $textarea_fields = array('form_intro', 'success_message', 'topics', 'blocked_terms', 'blocked_email_domains', 'privacy_note');
        foreach ($textarea_fields as $field) {
            $out[$field] = isset($input[$field]) ? sanitize_textarea_field(wp_unslash($input[$field])) : $defaults[$field];
        }

        $out['notification_email'] = sanitize_email($out['notification_email']);
        if (!$out['notification_email']) { $out['notification_email'] = get_option('admin_email'); }

        $out['store_spam'] = empty($input['store_spam']) ? 0 : 1;
        $out['notify_spam'] = empty($input['notify_spam']) ? 0 : 1;
        $out['require_email'] = empty($input['require_email']) ? 0 : 1;
        $out['quote_checkbox_enabled'] = empty($input['quote_checkbox_enabled']) ? 0 : 1;
        $out['auto_append_posts'] = empty($input['auto_append_posts']) ? 0 : 1;
        $out['auto_append_podcast'] = empty($input['auto_append_podcast']) ? 0 : 1;
        $out['auto_append_collapsed'] = empty($input['auto_append_collapsed']) ? 0 : 1;
        $out['auto_append_open'] = empty($input['auto_append_open']) ? 0 : 1;

        $out['min_seconds'] = isset($input['min_seconds']) ? max(0, intval($input['min_seconds'])) : intval($defaults['min_seconds']);
        $out['rate_limit_minutes'] = isset($input['rate_limit_minutes']) ? max(0, intval($input['rate_limit_minutes'])) : intval($defaults['rate_limit_minutes']);
        $out['max_links'] = isset($input['max_links']) ? max(0, intval($input['max_links'])) : intval($defaults['max_links']);

        return $out;
    }

    public static function admin_menu() {
        if (function_exists('handaistack_parent_slug')) {
            add_submenu_page(
                handaistack_parent_slug(),
                'HandAIMan Contact',
                'Contact',
                'manage_options',
                'handaiman-contact',
                array(__CLASS__, 'admin_messages_page')
            );
        } else {
            add_menu_page(
                'HandAIMan Contact',
                'HandAIMan Contact',
                'manage_options',
                'handaiman-contact',
                array(__CLASS__, 'admin_messages_page'),
                'dashicons-admin-generic',
                58
            );
        }
    }

    private static function parse_topics($raw) {
        $topics = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if (strpos($line, '|') !== false) {
                list($key, $label) = array_map('trim', explode('|', $line, 2));
            } else {
                $label = $line;
                $key = sanitize_title($line);
            }
            $key = sanitize_key($key);
            $label = sanitize_text_field($label);
            if ($key && $label) { $topics[$key] = $label; }
        }
        if (!$topics) { $topics['general'] = 'General message'; }
        return $topics;
    }

    public static function admin_settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $opts = self::options();
        ?>
        <div class="wrap">
            <h1>HandAIMan Contact Settings</h1>
            <p>Use <code>[handaiman_contact]</code> or <code>[ha_contact]</code> on any page or post.</p>
            <form method="post" action="options.php">
                <?php settings_fields('ha_contact_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ha_contact_form_heading">Form heading</label></th>
                        <td><input id="ha_contact_form_heading" name="<?php echo esc_attr(self::OPTION_KEY); ?>[form_heading]" type="text" class="regular-text" value="<?php echo esc_attr($opts['form_heading']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_form_intro">Intro text</label></th>
                        <td><textarea id="ha_contact_form_intro" name="<?php echo esc_attr(self::OPTION_KEY); ?>[form_intro]" rows="3" class="large-text"><?php echo esc_textarea($opts['form_intro']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_success_message">Success message</label></th>
                        <td><textarea id="ha_contact_success_message" name="<?php echo esc_attr(self::OPTION_KEY); ?>[success_message]" rows="2" class="large-text"><?php echo esc_textarea($opts['success_message']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_submit_label">Submit button label</label></th>
                        <td><input id="ha_contact_submit_label" name="<?php echo esc_attr(self::OPTION_KEY); ?>[submit_label]" type="text" class="regular-text" value="<?php echo esc_attr($opts['submit_label']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_notification_email">Notification email</label></th>
                        <td><input id="ha_contact_notification_email" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notification_email]" type="email" class="regular-text" value="<?php echo esc_attr($opts['notification_email']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_email_subject_prefix">Email subject prefix</label></th>
                        <td><input id="ha_contact_email_subject_prefix" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_subject_prefix]" type="text" class="regular-text" value="<?php echo esc_attr($opts['email_subject_prefix']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_topics">Topics</label></th>
                        <td>
                            <textarea id="ha_contact_topics" name="<?php echo esc_attr(self::OPTION_KEY); ?>[topics]" rows="8" class="large-text code"><?php echo esc_textarea($opts['topics']); ?></textarea>
                            <p class="description">One per line. Format: <code>key|Public label</code>. Example: <code>repair|Repair question</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Quote permission checkbox</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[quote_checkbox_enabled]" value="1" <?php checked($opts['quote_checkbox_enabled'], 1); ?>> Show quote-permission checkbox</label><br>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[quote_checkbox_label]" type="text" class="large-text" value="<?php echo esc_attr($opts['quote_checkbox_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_privacy_note">Privacy note</label></th>
                        <td><textarea id="ha_contact_privacy_note" name="<?php echo esc_attr(self::OPTION_KEY); ?>[privacy_note]" rows="2" class="large-text"><?php echo esc_textarea($opts['privacy_note']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_collapsed_summary">Collapsed summary</label></th>
                        <td>
                            <input id="ha_contact_collapsed_summary" name="<?php echo esc_attr(self::OPTION_KEY); ?>[collapsed_summary]" type="text" class="regular-text" value="<?php echo esc_attr($opts['collapsed_summary']); ?>">
                            <p class="description">Text shown when the contact form is collapsed, such as <code>Contact TheHandAIMan</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-append</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_append_posts]" value="1" <?php checked($opts['auto_append_posts'], 1); ?>> Auto-append contact form to blog posts</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_append_podcast]" value="1" <?php checked($opts['auto_append_podcast'], 1); ?>> Auto-append contact form to podcast episodes</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_append_collapsed]" value="1" <?php checked($opts['auto_append_collapsed'], 1); ?>> Render auto-appended contact form collapsed by default</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_append_open]" value="1" <?php checked($opts['auto_append_open'], 1); ?>> Open auto-appended contact form by default</label>
                            <p class="description">Manual <code>[handaiman_contact]</code> shortcodes stay expanded unless you use <code>collapsed="yes"</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email field</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_email]" value="1" <?php checked($opts['require_email'], 1); ?>> Require email address</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Anti-spam</th>
                        <td>
                            <label>Minimum seconds before submit: <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[min_seconds]" type="number" min="0" value="<?php echo esc_attr($opts['min_seconds']); ?>" style="width:90px;"></label><br>
                            <label>Rate limit minutes per IP: <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[rate_limit_minutes]" type="number" min="0" value="<?php echo esc_attr($opts['rate_limit_minutes']); ?>" style="width:90px;"></label><br>
                            <label>Maximum links before spam: <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_links]" type="number" min="0" value="<?php echo esc_attr($opts['max_links']); ?>" style="width:90px;"></label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[store_spam]" value="1" <?php checked($opts['store_spam'], 1); ?>> Save spam-flagged submissions locally</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[notify_spam]" value="1" <?php checked($opts['notify_spam'], 1); ?>> Email notifications for spam-flagged submissions</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_blocked_terms">Blocked terms</label></th>
                        <td>
                            <textarea id="ha_contact_blocked_terms" name="<?php echo esc_attr(self::OPTION_KEY); ?>[blocked_terms]" rows="5" class="large-text code"><?php echo esc_textarea($opts['blocked_terms']); ?></textarea>
                            <p class="description">One term per line. Case-insensitive. Matched against name, email, subject, and message.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ha_contact_blocked_email_domains">Blocked email domains</label></th>
                        <td>
                            <textarea id="ha_contact_blocked_email_domains" name="<?php echo esc_attr(self::OPTION_KEY); ?>[blocked_email_domains]" rows="4" class="large-text code"><?php echo esc_textarea($opts['blocked_email_domains']); ?></textarea>
                            <p class="description">One domain per line, such as <code>example.com</code>.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Contact Settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function admin_messages_page() {
        if (!current_user_can('manage_options')) { return; }
        self::create_table();

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        $id = isset($_GET['message_id']) ? absint($_GET['message_id']) : 0;
        if ($action === 'view' && $id) {
            self::admin_view_message($id);
            return;
        }

        self::admin_list_messages();
    }

    private static function status_labels() {
        return array(
            'new' => 'New',
            'read' => 'Read',
            'replied' => 'Replied',
            'content' => 'Content idea',
            'spam' => 'Spam',
            'trash' => 'Trash',
        );
    }

    private static function admin_list_messages() {
        global $wpdb;
        $table = self::table_name();
        $status_labels = self::status_labels();
        $current_status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 25;
        $offset = ($paged - 1) * $per_page;

        $where = '1=1';
        $params = array();
        if ($current_status && isset($status_labels[$current_status])) {
            $where .= ' AND status = %s';
            $params[] = $current_status;
        } else {
            $where .= " AND status <> 'trash'";
        }

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);

        $query_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($query_sql, $query_params), ARRAY_A);

        $counts = array();
        foreach (array_keys($status_labels) as $st) {
            $counts[$st] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $st));
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">HandAIMan Contact Messages</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=handaiman-contact-settings')); ?>" class="page-title-action">Settings</a>
            <hr class="wp-header-end">

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="<?php echo $current_status === '' ? 'current' : ''; ?>">Active</a> | </li>
                <?php $i = 0; foreach ($status_labels as $st => $label): $i++; ?>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&status=' . $st)); ?>" class="<?php echo $current_status === $st ? 'current' : ''; ?>"><?php echo esc_html($label); ?> <span class="count">(<?php echo intval($counts[$st]); ?>)</span></a><?php echo $i < count($status_labels) ? ' | ' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Topic</th>
                        <th>Subject</th>
                        <th>Source</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8">No messages found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html(mysql2date('M j, Y g:i a', $row['created_at'])); ?></td>
                                <td><?php echo esc_html(isset($status_labels[$row['status']]) ? $status_labels[$row['status']] : $row['status']); ?></td>
                                <td><?php echo esc_html($row['name']); ?></td>
                                <td><?php echo $row['email'] ? '<a href="mailto:' . esc_attr($row['email']) . '">' . esc_html($row['email']) . '</a>' : '&mdash;'; ?></td>
                                <td><?php echo esc_html($row['topic']); ?></td>
                                <td><strong><?php echo esc_html($row['subject']); ?></strong></td>
                                <td><?php echo $row['source_url'] ? '<a href="' . esc_url($row['source_url']) . '" target="_blank" rel="noopener noreferrer">Source</a>' : '&mdash;'; ?></td>
                                <td><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&action=view&message_id=' . absint($row['id']))); ?>">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php
            $total_pages = max(1, (int) ceil($total / $per_page));
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $paged,
                ));
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    private static function admin_view_message($id) {
        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!$row) {
            echo '<div class="wrap"><h1>Message not found</h1></div>';
            return;
        }

        if ($row['status'] === 'new') {
            $wpdb->update($table, array('status' => 'read', 'updated_at' => current_time('mysql')), array('id' => $id), array('%s', '%s'), array('%d'));
            $row['status'] = 'read';
        }

        $status_labels = self::status_labels();
        ?>
        <div class="wrap">
            <h1>Contact Message #<?php echo absint($row['id']); ?></h1>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>">&larr; Back to messages</a></p>

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin:12px 0;">
                <?php foreach ($status_labels as $st => $label): ?>
                    <?php if ($st === $row['status']) { continue; } ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ha_contact_update_status&message_id=' . absint($row['id']) . '&status=' . $st), 'ha_contact_update_status_' . absint($row['id']))); ?>"><?php echo esc_html('Mark ' . $label); ?></a>
                <?php endforeach; ?>
            </div>

            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th style="width:180px;">Date</th><td><?php echo esc_html(mysql2date('M j, Y g:i a', $row['created_at'])); ?></td></tr>
                    <tr><th>Status</th><td><?php echo esc_html(isset($status_labels[$row['status']]) ? $status_labels[$row['status']] : $row['status']); ?></td></tr>
                    <?php if ($row['spam_reason']): ?><tr><th>Spam reason</th><td><?php echo esc_html($row['spam_reason']); ?></td></tr><?php endif; ?>
                    <tr><th>Name</th><td><?php echo esc_html($row['name']); ?></td></tr>
                    <tr><th>Email</th><td><?php echo $row['email'] ? '<a href="mailto:' . esc_attr($row['email']) . '?subject=' . rawurlencode('Re: ' . $row['subject']) . '">' . esc_html($row['email']) . '</a>' : '&mdash;'; ?></td></tr>
                    <tr><th>Topic</th><td><?php echo esc_html($row['topic']); ?></td></tr>
                    <tr><th>Subject</th><td><?php echo esc_html($row['subject']); ?></td></tr>
                    <tr><th>Quote permission</th><td><?php echo $row['quote_ok'] ? 'Yes' : 'No'; ?></td></tr>
                    <tr><th>Source</th><td><?php echo $row['source_url'] ? '<a href="' . esc_url($row['source_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($row['source_url']) . '</a>' : '&mdash;'; ?></td></tr>
                    <tr><th>Message</th><td><div style="white-space:pre-wrap; font-size:15px; line-height:1.5; background:#fff; padding:12px; border:1px solid #dcdcde; border-radius:4px;"><?php echo esc_html($row['message']); ?></div></td></tr>
                    <tr><th>User agent</th><td><code><?php echo esc_html($row['user_agent']); ?></code></td></tr>
                    <tr><th>IP hash</th><td><code><?php echo esc_html($row['ip_hash']); ?></code></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function admin_update_status() {
        if (!current_user_can('manage_options')) { wp_die('Permission denied.'); }
        $id = isset($_GET['message_id']) ? absint($_GET['message_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        if (!$id || !isset(self::status_labels()[$status])) { wp_die('Invalid request.'); }
        check_admin_referer('ha_contact_update_status_' . $id);

        global $wpdb;
        $wpdb->update(
            self::table_name(),
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&action=view&message_id=' . $id));
        exit;
    }

    public static function maybe_auto_append($content) {
        if (is_admin() || is_feed() || !is_singular()) { return $content; }
        if (has_shortcode($content, 'handaiman_contact') || has_shortcode($content, 'ha_contact')) { return $content; }

        $post_type = get_post_type();
        $opts = self::options();
        $enabled = false;
        if ($post_type === 'post' && !empty($opts['auto_append_posts'])) { $enabled = true; }
        if ($post_type === 'podcast' && !empty($opts['auto_append_podcast'])) { $enabled = true; }
        if (!$enabled) { return $content; }

        $collapsed = !empty($opts['auto_append_collapsed']) ? 'yes' : 'no';
        $open = !empty($opts['auto_append_open']) ? 'yes' : 'no';
        $summary = isset($opts['collapsed_summary']) ? $opts['collapsed_summary'] : 'Contact TheHandAIMan';

        return $content . "\n\n" . self::shortcode(array(
            'collapsed' => $collapsed,
            'open' => $open,
            'summary' => $summary,
        ));
    }

    private static function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return esc_url_raw($scheme . $host . $uri);
    }

    private static function source_url_for_redirect() {
        $referer = wp_get_referer();
        if ($referer) { return remove_query_arg(array('ha_contact_sent', 'ha_contact_error'), $referer); }
        return remove_query_arg(array('ha_contact_sent', 'ha_contact_error'), self::current_url());
    }

    private static function visitor_ip_hash() {
        $ip = '';
        foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
                $parts = explode(',', $raw);
                $ip = trim($parts[0]);
                break;
            }
        }
        if ($ip === '') { $ip = 'unknown'; }
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    private static function get_block_list($raw) {
        $items = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        foreach ($lines as $line) {
            $line = trim(strtolower($line));
            if ($line !== '') { $items[] = $line; }
        }
        return $items;
    }

    private static function classify_spam($data, $opts, $ip_hash) {
        if (!empty($data['honeypot'])) { return 'honeypot'; }

        $rendered_at = isset($data['rendered_at']) ? intval($data['rendered_at']) : 0;
        if (!empty($opts['min_seconds']) && $rendered_at > 0 && (time() - $rendered_at) < intval($opts['min_seconds'])) {
            return 'submitted too quickly';
        }

        $max_links = intval($opts['max_links']);
        if ($max_links >= 0) {
            $link_count = preg_match_all('/https?:\/\/|www\./i', $data['message'] . ' ' . $data['subject'], $matches);
            if ($link_count > $max_links) { return 'too many links'; }
        }

        $terms = self::get_block_list($opts['blocked_terms']);
        if ($terms) {
            $haystack = strtolower($data['name'] . ' ' . $data['email'] . ' ' . $data['subject'] . ' ' . $data['message']);
            foreach ($terms as $term) {
                if ($term !== '' && strpos($haystack, $term) !== false) { return 'blocked term: ' . $term; }
            }
        }

        $domains = self::get_block_list($opts['blocked_email_domains']);
        if ($domains && !empty($data['email']) && strpos($data['email'], '@') !== false) {
            $domain = strtolower(substr(strrchr($data['email'], '@'), 1));
            foreach ($domains as $blocked) {
                $blocked = ltrim($blocked, '@');
                if ($domain === $blocked || substr($domain, -strlen('.' . $blocked)) === '.' . $blocked) {
                    return 'blocked email domain: ' . $blocked;
                }
            }
        }

        $rate_minutes = intval($opts['rate_limit_minutes']);
        if ($rate_minutes > 0) {
            $key = 'ha_contact_rate_' . $ip_hash;
            if (get_transient($key)) {
                return 'rate limited';
            }
        }

        return '';
    }

    public static function handle_submission() {
        if (empty($_POST['ha_contact_submit'])) { return; }

        $opts = self::options();
        $source = self::source_url_for_redirect();
        $error_url = add_query_arg('ha_contact_error', '1', $source) . '#ha-contact';
        $success_url = add_query_arg('ha_contact_sent', '1', $source) . '#ha-contact';

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION)) {
            wp_safe_redirect($error_url);
            exit;
        }

        $topics = self::parse_topics($opts['topics']);
        $topic = isset($_POST['ha_contact_topic']) ? sanitize_key(wp_unslash($_POST['ha_contact_topic'])) : 'general';
        if (!isset($topics[$topic])) { $topic = 'general'; }

        $data = array(
            'name' => isset($_POST['ha_contact_name']) ? sanitize_text_field(wp_unslash($_POST['ha_contact_name'])) : '',
            'email' => isset($_POST['ha_contact_email']) ? sanitize_email(wp_unslash($_POST['ha_contact_email'])) : '',
            'topic' => $topic,
            'subject' => isset($_POST['ha_contact_subject']) ? sanitize_text_field(wp_unslash($_POST['ha_contact_subject'])) : '',
            'message' => isset($_POST['ha_contact_message']) ? sanitize_textarea_field(wp_unslash($_POST['ha_contact_message'])) : '',
            'quote_ok' => empty($_POST['ha_contact_quote_ok']) ? 0 : 1,
            'honeypot' => isset($_POST['ha_contact_website']) ? trim(sanitize_text_field(wp_unslash($_POST['ha_contact_website']))) : '',
            'rendered_at' => isset($_POST['ha_contact_rendered_at']) ? intval($_POST['ha_contact_rendered_at']) : 0,
        );

        $valid = true;
        if ($data['name'] === '' || $data['subject'] === '' || $data['message'] === '') { $valid = false; }
        if (!empty($opts['require_email']) && !is_email($data['email'])) { $valid = false; }
        if (!$valid) {
            wp_safe_redirect($error_url);
            exit;
        }

        $ip_hash = self::visitor_ip_hash();
        $spam_reason = self::classify_spam($data, $opts, $ip_hash);
        $status = $spam_reason ? 'spam' : 'new';

        if ($status === 'spam' && empty($opts['store_spam'])) {
            wp_safe_redirect($success_url);
            exit;
        }

        global $wpdb;
        self::create_table();
        $table = self::table_name();
        $inserted = $wpdb->insert(
            $table,
            array(
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'status' => $status,
                'spam_reason' => $spam_reason,
                'name' => $data['name'],
                'email' => $data['email'],
                'topic' => $topics[$topic],
                'subject' => $data['subject'],
                'message' => $data['message'],
                'quote_ok' => $data['quote_ok'],
                'source_url' => $source,
                'source_post_id' => isset($_POST['ha_contact_source_post_id']) ? absint($_POST['ha_contact_source_post_id']) : 0,
                'ip_hash' => $ip_hash,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            ),
            array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s','%s')
        );

        if ($inserted) {
            if (intval($opts['rate_limit_minutes']) > 0) {
                set_transient('ha_contact_rate_' . $ip_hash, 1, intval($opts['rate_limit_minutes']) * MINUTE_IN_SECONDS);
            }

            $message_id = (int) $wpdb->insert_id;
            if ($status !== 'spam' || !empty($opts['notify_spam'])) {
                self::send_notification($message_id, $data, $topics[$topic], $status, $spam_reason, $opts, $source);
            }
        }

        wp_safe_redirect($success_url);
        exit;
    }

    private static function send_notification($message_id, $data, $topic_label, $status, $spam_reason, $opts, $source) {
        $to = $opts['notification_email'];
        if (!$to || !is_email($to)) { return; }

        $prefix = $opts['email_subject_prefix'] ? $opts['email_subject_prefix'] : '[Contact]';
        $subject = $prefix . ' ' . $data['subject'];
        if ($status === 'spam') { $subject = '[Spam] ' . $subject; }

        $admin_url = admin_url('admin.php?page=' . self::MENU_SLUG . '&action=view&message_id=' . absint($message_id));
        $body = "New HandAIMan contact message\n\n";
        $body .= "Status: " . $status . "\n";
        if ($spam_reason) { $body .= "Spam reason: " . $spam_reason . "\n"; }
        $body .= "Name: " . $data['name'] . "\n";
        $body .= "Email: " . ($data['email'] ? $data['email'] : '(none)') . "\n";
        $body .= "Topic: " . $topic_label . "\n";
        $body .= "Subject: " . $data['subject'] . "\n";
        $body .= "Quote permission: " . ($data['quote_ok'] ? 'Yes' : 'No') . "\n";
        $body .= "Source: " . $source . "\n";
        $body .= "Admin view: " . $admin_url . "\n\n";
        $body .= "Message:\n" . $data['message'] . "\n";

        $headers = array();
        if ($data['email'] && is_email($data['email'])) {
            $headers[] = 'Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>';
        }

        wp_mail($to, $subject, $body, $headers);
    }

    public static function shortcode($atts = array(), $content = null) {
        $opts = self::options();
        $atts = shortcode_atts(array(
            'topic' => '',
            'heading' => '',
            'intro' => '',
            'show_topic' => 'yes',
            'collapsed' => 'no',
            'open' => 'no',
            'summary' => '',
        ), $atts, 'handaiman_contact');

        $topics = self::parse_topics($opts['topics']);
        $selected_topic = sanitize_key($atts['topic']);
        if (!$selected_topic || !isset($topics[$selected_topic])) { $selected_topic = 'general'; }

        $sent = !empty($_GET['ha_contact_sent']);
        $error = !empty($_GET['ha_contact_error']);
        $form_id = 'ha-contact-' . wp_rand(1000, 9999);
        $heading = $atts['heading'] !== '' ? sanitize_text_field($atts['heading']) : $opts['form_heading'];
        $intro = $atts['intro'] !== '' ? sanitize_text_field($atts['intro']) : $opts['form_intro'];
        $source_post_id = get_the_ID() ? get_the_ID() : 0;

        $collapsed = strtolower((string) $atts['collapsed']) === 'yes';
        $open = strtolower((string) $atts['open']) === 'yes';
        if ($sent || $error) { $open = true; }
        $summary = $atts['summary'] !== '' ? sanitize_text_field($atts['summary']) : $opts['collapsed_summary'];

        ob_start();
        ?>
        <div id="ha-contact" class="ha-contact-box" style="border:1px solid #dcdcde; padding:16px; border-radius:8px; max-width:760px; margin:1.5em 0; background:#fff;">
            <?php if ($heading): ?><h3 style="margin-top:0;"><?php echo esc_html($heading); ?></h3><?php endif; ?>
            <?php if ($intro): ?><p><?php echo esc_html($intro); ?></p><?php endif; ?>

            <?php if ($sent): ?>
                <div class="ha-contact-success" style="border-left:4px solid #00a32a; background:#f0fff4; padding:10px 12px; margin:12px 0;">
                    <?php echo esc_html($opts['success_message']); ?>
                </div>
            <?php elseif ($error): ?>
                <div class="ha-contact-error" style="border-left:4px solid #d63638; background:#fff2f2; padding:10px 12px; margin:12px 0;">
                    Please check the required fields and try again.
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(self::current_url()); ?>#ha-contact" class="ha-contact-form">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="ha_contact_submit" value="1">
                <input type="hidden" name="ha_contact_rendered_at" value="<?php echo esc_attr(time()); ?>">
                <input type="hidden" name="ha_contact_source_post_id" value="<?php echo esc_attr($source_post_id); ?>">
                <div style="position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden;" aria-hidden="true">
                    <label for="<?php echo esc_attr($form_id); ?>-website">Website</label>
                    <input id="<?php echo esc_attr($form_id); ?>-website" type="text" name="ha_contact_website" value="" tabindex="-1" autocomplete="off">
                </div>

                <p>
                    <label for="<?php echo esc_attr($form_id); ?>-name"><strong>Name</strong></label><br>
                    <input id="<?php echo esc_attr($form_id); ?>-name" name="ha_contact_name" type="text" required style="width:100%; padding:8px;" autocomplete="name">
                </p>

                <p>
                    <label for="<?php echo esc_attr($form_id); ?>-email"><strong>Email<?php echo !empty($opts['require_email']) ? '' : ' (optional)'; ?></strong></label><br>
                    <input id="<?php echo esc_attr($form_id); ?>-email" name="ha_contact_email" type="email" <?php echo !empty($opts['require_email']) ? 'required' : ''; ?> style="width:100%; padding:8px;" autocomplete="email">
                </p>

                <?php if (strtolower($atts['show_topic']) !== 'no'): ?>
                    <p>
                        <label for="<?php echo esc_attr($form_id); ?>-topic"><strong>Topic</strong></label><br>
                        <select id="<?php echo esc_attr($form_id); ?>-topic" name="ha_contact_topic" style="width:100%; padding:8px;">
                            <?php foreach ($topics as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_topic, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php else: ?>
                    <input type="hidden" name="ha_contact_topic" value="<?php echo esc_attr($selected_topic); ?>">
                <?php endif; ?>

                <p>
                    <label for="<?php echo esc_attr($form_id); ?>-subject"><strong>Subject</strong></label><br>
                    <input id="<?php echo esc_attr($form_id); ?>-subject" name="ha_contact_subject" type="text" required style="width:100%; padding:8px;">
                </p>

                <p>
                    <label for="<?php echo esc_attr($form_id); ?>-message"><strong>Message</strong></label><br>
                    <textarea id="<?php echo esc_attr($form_id); ?>-message" name="ha_contact_message" rows="8" required style="width:100%; padding:8px;"></textarea>
                </p>

                <?php if (!empty($opts['quote_checkbox_enabled'])): ?>
                    <p>
                        <label><input type="checkbox" name="ha_contact_quote_ok" value="1"> <?php echo esc_html($opts['quote_checkbox_label']); ?></label>
                    </p>
                <?php endif; ?>

                <?php if (!empty($opts['privacy_note'])): ?>
                    <p style="font-size:0.9em; opacity:0.8;"><?php echo esc_html($opts['privacy_note']); ?></p>
                <?php endif; ?>

                <p><button type="submit" class="button wp-element-button"><?php echo esc_html($opts['submit_label']); ?></button></p>
            </form>
        </div>
        <?php
        $form_html = ob_get_clean();

        if (!$collapsed) {
            return $form_html;
        }

        $form_html = str_replace(
            'border:1px solid #dcdcde; padding:16px; border-radius:8px; max-width:760px; margin:1.5em 0; background:#fff;',
            'padding:16px; margin:0; background:#fff;',
            $form_html
        );

        ob_start();
        ?>
        <details class="ha-contact-collapsible" <?php echo $open ? 'open' : ''; ?> style="border:1px solid #dcdcde; border-radius:8px; max-width:760px; margin:1.5em 0; background:#fff;">
            <summary style="cursor:pointer; padding:14px 16px; font-weight:600;"><?php echo esc_html($summary); ?></summary>
            <div style="border-top:1px solid #dcdcde;">
                <?php echo $form_html; ?>
            </div>
        </details>
        <?php
        return ob_get_clean();
    }
}

HandAIMan_Contact_Plugin::init();
