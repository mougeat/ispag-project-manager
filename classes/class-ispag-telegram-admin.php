<?php
defined('ABSPATH') || exit;

class ISPAG_Telegram_Admin {

    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_ispag_add_subscriber', [self::class, 'handle_add_subscriber']);
        add_action('admin_post_ispag_delete_subscriber', [self::class, 'handle_delete_subscriber']);
    }

    public static function add_menu() {
        add_menu_page(
            'Abonnés Telegram',
            'Telegram',
            'manage_options',
            'ispag-telegram',
            [self::class, 'render_admin_page'],
            'dashicons-format-chat',
            25
        );
    }

    public static function render_admin_page() {
        global $wpdb;

        $table = $wpdb->prefix . 'achats_telegram_subscribers';
        $subscribers = $wpdb->get_results("SELECT * FROM $table");

        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        ?>
        <div class="wrap">
            <h1>Abonnés Telegram</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="ispag_add_subscriber">
                <?php wp_nonce_field('ispag_add_subscriber'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="chat_id">Chat ID</label></th>
                        <td><input name="chat_id" type="text" required class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="user_id">Utilisateur</label></th>
                        <td>
                            <input list="user_list" name="user_id" class="regular-text" required />
                            <datalist id="user_list">
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>">
                                        <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </datalist>
                            <p class="description">Saisir ou choisir un utilisateur existant.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Ajouter l\'abonné'); ?>
            </form>

            <hr>

            <h2>Liste des abonnés</h2>
            <table class="widefat striped">
                <thead>
                    <tr><th>Nom</th><th>Chat ID</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subscribers as $sub) : ?>
                        <tr>
                            <td><?php echo esc_html($sub->name); ?></td>
                            <td><?php echo esc_html($sub->chat_id); ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Supprimer cet abonné ?');">
                                    <input type="hidden" name="action" value="ispag_delete_subscriber">
                                    <input type="hidden" name="chat_id" value="<?php echo esc_attr($sub->chat_id); ?>">
                                    <?php wp_nonce_field('ispag_delete_subscriber'); ?>
                                    <button type="submit" class="button button-small">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }


    public static function handle_add_subscriber() {
        if (!current_user_can('manage_options') || !check_admin_referer('ispag_add_subscriber')) {
            wp_die('Accès refusé');
        }

        global $wpdb;
        $chat_id = sanitize_text_field($_POST['chat_id']);
        $name = sanitize_text_field($_POST['name']);
        $table = $wpdb->prefix . 'achats_telegram_subscribers';

        $wpdb->insert($table, ['chat_id' => $chat_id, 'name' => $name]);

        wp_redirect(admin_url('admin.php?page=ispag-telegram&added=1'));
        exit;
    }

    public static function handle_delete_subscriber() {
        if (!current_user_can('manage_options') || !check_admin_referer('ispag_delete_subscriber')) {
            wp_die('Accès refusé');
        }

        global $wpdb;
        $chat_id = sanitize_text_field($_POST['chat_id']);
        $table = $wpdb->prefix . 'achats_telegram_subscribers';

        $wpdb->delete($table, ['chat_id' => $chat_id]);

        wp_redirect(admin_url('admin.php?page=ispag-telegram&deleted=1'));
        exit;
    }
}
