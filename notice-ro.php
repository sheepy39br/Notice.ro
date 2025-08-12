<?php
/**
 * Plugin Name: notice.ro
 * Description: Trimite SMS-uri automat la schimbarea statusului unei comenzi WooCommerce folosind template-uri Notice.ro.
 * Version:     3.3
 * Author:      Notice
 * Text Domain: notice-sms-connector
 */
if (!defined('ABSPATH')) { exit; }
global $wpdb;
$SAWP_LOG_TABLE = $wpdb->prefix . 'sawp_sms_logs';

// Înregistrează statusul "Plată în așteptare" în WooCommerce
add_action('init', 'sawp_register_payment_pending_status');
function sawp_register_payment_pending_status() {
    register_post_status('wc-payment-pending', array(
        'label'                     => _x('Plată în așteptare', 'Order status', 'notice-sms-connector'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Plată în așteptare (%s)', 'Plată în așteptare (%s)', 'notice-sms-connector')
    ));
}

// Adaugă statusul la lista de statusuri disponibile
add_filter('wc_order_statuses', 'sawp_add_payment_pending_to_list');
function sawp_add_payment_pending_to_list($order_statuses) {
    $order_statuses['wc-payment-pending'] = _x('Plată în așteptare', 'Order status', 'notice-sms-connector');
    return $order_statuses;
}

/* ================== ACTIVARE ================== */
register_activation_hook(__FILE__, 'sawp_activate');
function sawp_activate() {
    // Resetează toate datele vechi la activare
    sawp_reset_all_data();
    
    // Creează tabela de log-uri
    sawp_create_log_table();
    
    // Setează opțiunea pentru a reseta transient-urile la prima rulare
    update_option('sawp_just_activated', true);
}
function sawp_reset_all_data() {
    // Șterge opțiunile
    delete_option('sawp_opts');
    
    // Șterge toate transient-urile
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    // Șterge tabela de log-uri dacă există
    global $wpdb;
    $table_name = $wpdb->prefix . 'sawp_sms_logs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}
function sawp_create_log_table() {
    global $wpdb, $SAWP_LOG_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$SAWP_LOG_TABLE} (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        date_sent DATETIME NOT NULL,
        order_id VARCHAR(50) NULL,
        phone VARCHAR(20) NULL,
        template_id INT(11) NULL,
        template_name VARCHAR(255) NULL,
        status_code INT(11) NULL,
        response LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY order_id (order_id),
        KEY date_sent (date_sent)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
/* ================== UTIL / TABELĂ ================== */
function sawp_table_exists() {
    global $wpdb, $SAWP_LOG_TABLE;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $SAWP_LOG_TABLE));
    return ($found === $SAWP_LOG_TABLE);
}
function sawp_maybe_create_table() { 
    if (!sawp_table_exists()) { 
        sawp_create_log_table(); 
    } 
}
function sawp_column_exists( $table, $column ) {
    global $wpdb;
    $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
    return ( $col === $column );
}
function sawp_maybe_upgrade_table() {
    global $wpdb, $SAWP_LOG_TABLE;
    sawp_maybe_create_table();
    if ( ! sawp_column_exists( $SAWP_LOG_TABLE, 'template_name' ) ) {
        $wpdb->query( "ALTER TABLE {$SAWP_LOG_TABLE} ADD COLUMN template_name VARCHAR(255) NULL AFTER template_id" ); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
/* ==== Helpers template-uri ==== */
function sawp_get_template_map() {
    $map = [];
    $tpls = get_transient('sawp_tpl_list');
    if ($tpls === false || !is_array($tpls)) {
        $opts = get_option('sawp_opts', []);
        $tk = trim($opts['token'] ?? '');
        if ($tk) {
            $res = wp_remote_get('https://api.notice.ro/api/v1/templates', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $tk,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json'
                ],
                'timeout' => 15
            ]);
            if (!is_wp_error($res) && 200 === (int) wp_remote_retrieve_response_code($res)) {
                $body = json_decode(wp_remote_retrieve_body($res), true);
                
                // Debug log
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('SAWP Templates Response: ' . print_r($body, true));
                }
                
                // Gestionare mai bună a structurii răspunsului
                if (isset($body['data']) && is_array($body['data'])) {
                    $tpls = $body['data'];
                } elseif (isset($body['templates']) && is_array($body['templates'])) {
                    $tpls = $body['templates'];
                } elseif (is_array($body)) {
                    $tpls = $body;
                } else {
                    $tpls = [];
                }
                set_transient('sawp_tpl_list', $tpls, 5 * MINUTE_IN_SECONDS);
            }
        }
    }
    if (is_array($tpls)) {
        foreach ($tpls as $tpl) {
            if (isset($tpl['id'])) {
                $map[(int)$tpl['id']] = $tpl['name'] ?? ($tpl['text'] ?? 'Template necunoscut');
            }
        }
    }
    return $map;
}
function sawp_resolve_template_name($template_id, $stored_name = '') {
    $template_id = (int) $template_id;
    if (!empty($stored_name)) { return $stored_name; }
    $map = sawp_get_template_map();
    if (isset($map[$template_id]) && $map[$template_id] !== '') {
        return $map[$template_id];
    }
    return $template_id ? '['.$template_id.']' : '—';
}
/* ================== DEZINSTALARE ================== */
register_uninstall_hook(__FILE__, 'sawp_uninstall');
function sawp_uninstall() {
    global $wpdb;
    delete_option('sawp_opts');
    $table = $wpdb->prefix . 'sawp_sms_logs';
    $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL
    foreach ($_COOKIE as $name => $value) {
        if (strpos($name, 'sawp_') === 0) {
            setcookie($name, '', time() - 3600, defined('COOKIEPATH') ? COOKIEPATH : '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '');
        }
    }
}
/* ================== INIT ================== */
add_action('plugins_loaded', 'sawp_init');
function sawp_init() {
    // Resetează transient-urile la activare
    if (get_option('sawp_just_activated')) {
        delete_transient('sawp_tpl_list');
        delete_transient('sawp_user_units');
        delete_transient('sawp_received_sms');
        delete_option('sawp_just_activated');
    }
    
    sawp_maybe_create_table();
    sawp_maybe_upgrade_table();
    if (!class_exists('WooCommerce')) { 
        add_action('admin_notices', 'sawp_notice_wc_missing'); 
        return; 
    }
    add_action('admin_menu', 'sawp_admin_menu');
    add_action('admin_init', 'sawp_register_settings');
    add_action('admin_enqueue_scripts', 'sawp_enqueue_assets');
    add_action('woocommerce_order_status_changed', 'sawp_send_sms', 10, 4);
    add_action('admin_post_sawp_contact', 'sawp_handle_contact');
    add_action('admin_post_nopriv_sawp_contact', 'sawp_handle_contact');
    add_action('admin_post_sawp_refresh_templates', 'sawp_handle_refresh_templates');
    add_action('admin_post_sawp_export_logs', 'sawp_handle_export_logs');
    add_action('admin_post_sawp_fetch_received', 'sawp_handle_fetch_received');
    
    // Adaugă handler pentru resetare
    add_action('wp_ajax_sawp_reset_plugin', 'sawp_reset_plugin_handler');
    add_action('wp_ajax_sawp_force_refresh', 'sawp_force_refresh_handler');
    add_action('wp_ajax_sawp_reset_token_data', 'sawp_reset_token_data_handler');
    
    // Adaugă verificare la schimbarea token-ului
    add_action('update_option_sawp_opts', 'sawp_check_token_change', 10, 2);
}
function sawp_notice_wc_missing() {
    echo '<div class="notice notice-error"><p><strong>notice.ro</strong> necesită WooCommerce activ.</p></div>';
}
/* ================== MENIU ADMIN ================== */
function sawp_admin_menu() {
    add_menu_page('notice.ro','notice.ro','manage_options','sawp-settings','sawp_render_settings_page','dashicons-email-alt2',56);
    add_submenu_page('sawp-settings','Confirmări comenzi','Confirmări comenzi','manage_options','sawp-confirmari','sawp_render_confirmations_page');
    add_submenu_page('sawp-settings','SMS-uri primite','SMS-uri primite','manage_options','sawp-received','sawp_render_received_page');
}
/* ================== SETĂRI ================== */
function sawp_register_settings() {
    register_setting('sawp_group', 'sawp_opts', 'sawp_sanitize_options');
    add_settings_section('sawp_main', '', '__return_false', 'sawp-settings');
    add_settings_field('sawp_token','<span class="dashicons dashicons-admin-network"></span> Token API','sawp_field_token','sawp-settings','sawp_main');
    add_settings_field('sawp_test','<span class="dashicons dashicons-admin-site-alt3"></span> Test conexiune','sawp_field_test','sawp-settings','sawp_main');
    
    // Statusuri fără „Ciornă”
    $statuses_raw = wc_get_order_statuses();
    $statuses = [];
    foreach ($statuses_raw as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        $skip_slugs = ['pending','draft','ciorna','cart-abandoned','abandoned','payment-pending'];
        $skip_by_label = in_array(mb_strtolower(trim($label)), ['ciornă','ciorna'], true);
        if (in_array($slug, $skip_slugs, true) || $skip_by_label) { continue; }
        if (isset($statuses[$key])) { continue; }
        $statuses[$key] = $label;
    }
    
    foreach ($statuses as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        add_settings_field(
            "sawp_status_{$slug}",
            '<span class="dashicons dashicons-yes"></span> ' . sprintf('Trimite SMS la %s', esc_html($label)),
            'sawp_field_status_row',
            'sawp-settings',
            'sawp_main',
            ['slug' => $slug, 'label' => $label, 'is_pending' => false]
        );
    }
    
    // „Ciornă” (pending)
    $slug = 'pending';
    add_settings_field(
        "sawp_status_{$slug}",
        '<span class="dashicons dashicons-yes"></span> Trimite SMS la Ciornă',
        'sawp_field_status_row',
        'sawp-settings',
        'sawp_main',
        ['slug' => $slug, 'label' => 'Ciornă', 'is_pending' => true]
    );
    
    // „Plată în așteptare” (payment-pending)
    $slug = 'payment-pending';
    add_settings_field(
        "sawp_status_{$slug}",
        '<span class="dashicons dashicons-yes"></span> Trimite SMS la Plată în așteptare',
        'sawp_field_status_row',
        'sawp-settings',
        'sawp_main',
        ['slug' => $slug, 'label' => 'Plată în așteptare', 'is_payment_pending' => true]
    );
}
// Funcție de sanitizare a opțiunilor
function sawp_sanitize_options($input) {
    $output = [];
    
    // Sanitizează token-ul
    if (isset($input['token'])) {
        $output['token'] = sanitize_text_field($input['token']);
    }
    
    // Sanitizează setările pentru fiecare status
    $statuses_raw = wc_get_order_statuses();
    $statuses = [];
    foreach ($statuses_raw as $key => $label) {
        $slug = str_replace('wc-', '', $key);
        $skip_slugs = ['draft','ciorna','cart-abandoned','abandoned'];
        $skip_by_label = in_array(mb_strtolower(trim($label)), ['ciornă','ciorna'], true);
        if (!in_array($slug, $skip_slugs, true) && !$skip_by_label) {
            $statuses[] = $slug;
        }
    }
    $statuses[] = 'pending'; // Adăugăm pending separat
    $statuses[] = 'payment-pending'; // Adăugăm payment-pending separat
    
    foreach ($statuses as $slug) {
        // Enable checkbox
        if (isset($input["enable_{$slug}"])) {
            $output["enable_{$slug}"] = (bool) $input["enable_{$slug}"];
        } else {
            $output["enable_{$slug}"] = false;
        }
        
        // Template ID
        if (isset($input["tpl_{$slug}"])) {
            $output["tpl_{$slug}"] = absint($input["tpl_{$slug}"]);
        } else {
            $output["tpl_{$slug}"] = 0;
        }
    }
    
    return $output;
}
function sawp_field_status_row( $args ) {
    $slug       = isset($args['slug']) ? (string) $args['slug'] : '';
    $is_pending = ! empty( $args['is_pending'] );
    $is_payment_pending = ! empty( $args['is_payment_pending'] );
    $opts    = get_option( 'sawp_opts', [] );
    $enabled = ! empty( $opts[ "enable_{$slug}" ] );
    $sel     = isset( $opts[ "tpl_{$slug}" ] ) ? (string) $opts[ "tpl_{$slug}" ] : '';
    
    // Template-urile
    $tpls = get_transient( 'sawp_tpl_list' );
    if ( false === $tpls ) {
        $tk = trim( $opts['token'] ?? '' );
        if ( $tk !== '' ) {
            $res = wp_remote_get(
                'https://api.notice.ro/api/v1/templates',
                [ 'headers' => [ 'Authorization' => 'Bearer ' . $tk ] ]
            );
            if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
                $body = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( is_array( $body ) ) {
                    $tpls = isset( $body['data']) && is_array( $body['data']) ? $body['data'] : $body;
                    set_transient( 'sawp_tpl_list', $tpls, 5 * MINUTE_IN_SECONDS );
                }
            }
        }
    }
    if ( ! is_array( $tpls ) ) { $tpls = []; }
    
    // Textul de preview
    $preview_text = '';
    if ( $sel !== '' ) {
        foreach ( $tpls as $tpl ) {
            $tpl_id = isset( $tpl['id'] ) ? (string) $tpl['id'] : '';
            $name = isset( $tpl['name'] ) ? (string) $tpl['name'] : '';
            $text = isset( $tpl['text'] ) ? (string) $tpl['text'] : '';
            if ( $tpl_id === $sel ) {
                $preview_text = $text;
                break;
            }
        }
    }
    
    // Structura HTML completă pentru fiecare rând
    ?>
    <div class="sawp-field-container">
        <div class="sawp-toggle-row">
            <label class="sawp-switch">
                <input type="checkbox" class="sawp-switch-input" data-slug="<?php echo esc_attr($slug); ?>" name="sawp_opts[enable_<?php echo esc_attr($slug); ?>]" <?php checked($enabled, true); ?>>
                <span class="sawp-slider"></span>
            </label>
            <?php if ($is_pending): ?>
                <span class="sawp-pending-note">(Abandon coș)</span>
            <?php endif; ?>
            <?php if ($is_payment_pending): ?>
                <span class="sawp-pending-note">(Plată neconfirmată)</span>
            <?php endif; ?>
        </div>
        
        <div class="sawp-template-container" id="tpl-container-<?php echo esc_attr($slug); ?>" style="<?php echo $enabled ? '' : 'display: none;'; ?>">
            <select name="sawp_opts[tpl_<?php echo esc_attr($slug); ?>]" class="sawp-template-select" data-slug="<?php echo esc_attr($slug); ?>">
                <option value=""><?php esc_html_e('Alege template-ul', 'notice-sms-connector'); ?></option>
                <?php foreach ($tpls as $tpl): ?>
                    <?php 
                    $id = isset($tpl['id']) ? (string) $tpl['id'] : '';
                    $name = isset($tpl['name']) ? (string) $tpl['name'] : '';
                    $text = isset($tpl['text']) ? (string) $tpl['text'] : '';
                    ?>
                    <option value="<?php echo esc_attr($id); ?>" data-text="<?php echo esc_attr($text); ?>" <?php selected($id, $sel); ?>>
                        [<?php echo esc_html($id); ?>] <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <div class="sawp-preview" id="preview-<?php echo esc_attr($slug); ?>" style="<?php echo empty($preview_text) ? 'display: none;' : ''; ?>">
                <?php echo esc_html($preview_text); ?>
            </div>
        </div>
    </div>
    <?php
}
/* ================== HANDLERE ADMIN ================== */
function sawp_handle_refresh_templates() {
    if (!current_user_can('manage_options')) { wp_die('Permisiune refuzată.'); }
    if (!isset($_GET['sawp_refresh_tpl_nonce']) || !wp_verify_nonce($_GET['sawp_refresh_tpl_nonce'], 'sawp_refresh_tpl')) { wp_die('Nonce invalid.'); }
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    wp_safe_redirect(add_query_arg(['page' => 'sawp-settings', 'tpl_refreshed' => '1'], admin_url('admin.php'))); exit;
}
function sawp_handle_export_logs() {
    if (!current_user_can('manage_options')) { wp_die('Permisiune refuzată.'); }
    if (!isset($_GET['sawp_export_logs_nonce']) || !wp_verify_nonce($_GET['sawp_export_logs_nonce'], 'sawp_export_logs')) { wp_die('Nonce invalid.'); }
    global $wpdb, $SAWP_LOG_TABLE;
    $logs = $wpdb->get_results("SELECT * FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC"); // phpcs:ignore WordPress.DB.PreparedSQL
    if (!$logs) { wp_die('Nu există log-uri.'); }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    $filename = 'sms_logs_' . gmdate('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Data trimitere', 'Order ID', 'Telefon', 'Template ID', 'Nume Template', 'Status Code', 'Răspuns API']);
    foreach ($logs as $row) {
        fputcsv(
            $output,
            [
                $row->id,
                $row->date_sent,
                $row->order_id,
                $row->phone,
                $row->template_id,
                sawp_resolve_template_name($row->template_id, $row->template_name),
                $row->status_code,
                $row->response,
            ]
        );
    }
    fclose($output);
    exit;
}
function sawp_reset_plugin_handler() {
    check_ajax_referer('sawp_reset_plugin', 'nonce');
    
    // Șterge opțiunile
    delete_option('sawp_opts');
    
    // Șterge toate transient-urile
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    // Șterge tabela de log-uri
    global $wpdb;
    $table_name = $wpdb->prefix . 'sawp_sms_logs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
    wp_send_json_success();
}
function sawp_force_refresh_handler() {
    check_ajax_referer('sawp_force_refresh', 'nonce');
    
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    wp_die();
}
function sawp_reset_token_data_handler() {
    check_ajax_referer('sawp_reset_token', 'nonce');
    
    delete_transient('sawp_tpl_list');
    delete_transient('sawp_user_units');
    delete_transient('sawp_received_sms');
    delete_transient('sawp_received_last_update');
    
    wp_die();
}
function sawp_check_token_change($old_value, $new_value) {
    // Verifică dacă token-ul s-a schimbat
    if (isset($old_value['token']) && isset($new_value['token']) && 
        $old_value['token'] !== $new_value['token']) {
        // Resetează toate datele legate de token
        delete_transient('sawp_tpl_list');
        delete_transient('sawp_user_units');
        delete_transient('sawp_received_sms');
        delete_transient('sawp_received_last_update');
    }
}
/* ================== PAGINA PRINCIPALĂ (SETĂRI) ================== */
function sawp_render_settings_page() {
    sawp_maybe_create_table();
    $opts = get_option('sawp_opts', []);
    $ok_contact = isset($_GET['sms_contact']) && 'success' === ($_GET['sms_contact']);
    $ok_refresh = isset($_GET['tpl_refreshed']) && '1' === ($_GET['tpl_refreshed']);
    global $wpdb, $SAWP_LOG_TABLE;
    
    // Corectare: Folosim date_i18n pentru a obține data curentă în fusul orar al site-ului
    $today = date_i18n('Y-m-d');
    
    // Corectare: Folosim funcția DATE din MySQL pentru a extrage doar data
    $count_today = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$SAWP_LOG_TABLE} WHERE DATE(date_sent) = %s",
        $today
    ));
    
    $token = trim($opts['token'] ?? '');
    $user_units = get_transient('sawp_user_units');
    
    // Fetch user units if not in cache
    if (false === $user_units && $token) {
        $response = wp_remote_get('https://api.notice.ro/api/v1/user/units', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            ],
            'timeout' => 15
        ]);
        
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($body)) {
                $user_units = $body;
                set_transient('sawp_user_units', $user_units, 5 * MINUTE_IN_SECONDS);
                
                // Debug log pentru a vedea structura răspunsului
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('SAWP User Units Response: ' . print_r($body, true));
                }
            }
        }
    }
    
    // Calculăm creditele rămase
    $total_credits = 0;
    $remaining_credits = 0;
    $subscription_name = '';
    
    if (!empty($user_units)) {
        // Verificăm structura răspunsului
        if (isset($user_units['credits'])) {
            if (is_array($user_units['credits'])) {
                // Dacă credits este un array (posibil structură nouă)
                $total_credits = $user_units['credits']['total'] ?? 0;
                $remaining_credits = $user_units['credits']['remaining'] ?? $user_units['credits']['remaining_credits'] ?? 0;
            } else {
                // Dacă credits este un număr (structura veche)
                $total_credits = $user_units['credits'];
                // Încercăm să găsim creditele rămase în alte câmpuri
                $remaining_credits = $user_units['remaining_credits'] ?? $user_units['remaining'] ?? $user_units['credits_remaining'] ?? 0;
            }
        }
        
        // Preluăm informațiile despre abonament - căutăm în mai multe locuri posibile
        if (isset($user_units['subscription'])) {
            $subscription_name = $user_units['subscription']['name'] ?? 
                               $user_units['subscription']['plan_name'] ?? 
                               $user_units['subscription']['type'] ?? 
                               $user_units['plan'] ?? 
                               $user_units['plan_name'] ?? '';
        } else {
            // Căutăm direct în rădăcină
            $subscription_name = $user_units['plan_name'] ?? 
                               $user_units['subscription_type'] ?? 
                               $user_units['account_type'] ?? '';
        }
        
        // Dacă nu avem credite rămase, le calculăm pe baza SMS-urilor trimise
        if ($total_credits > 0 && $remaining_credits == 0) {
            $total_sent = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$SAWP_LOG_TABLE} WHERE status_code = 200");
            $remaining_credits = max(0, $total_credits - $total_sent);
        }
    }
    ?>
    <div class="wrap">
        <h1 class="sawp-main-title">
            <span class="sawp-title-icon">📱</span>
            notice.ro
        </h1>
        <div class="sawp-title-line"></div>
        
        <?php if (!sawp_table_exists()) : ?>
            <div class="notice notice-error"><p>Atenție: tabela de log lipsește. Am încercat să o creăm automat.</p></div>
        <?php endif; ?>
        <?php if ($ok_contact) : ?><div class="notice notice-success is-dismissible"><p>Mesaj trimis!</p></div><?php endif; ?>
        <?php if ($ok_refresh) : ?><div class="notice notice-success is-dismissible"><p>Template‑urile au fost reîmprospătate cu succes.</p></div><?php endif; ?>
        
        <div class="sawp-dashboard">
            <div class="sawp-main-panel">
                <div class="sawp-panel-header">
                    <h2>Setări SMS</h2>
                    <div class="sawp-header-actions">
                        <button type="button" class="sawp-btn sawp-btn-primary" id="sawp-force-refresh">
                            <span class="dashicons dashicons-update-alt"></span> Reîmprospătează forțat
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($user_units)) : ?>
                    <div class="sawp-account-card">
                        <div class="sawp-card-header">
                            <div class="sawp-logo-container">
                                <img src="https://i.imgur.com/eyaTYdm.png" alt="Notice" class="sawp-logo">
                            </div>
                            <div class="sawp-social-icons">
                                <a href="https://www.facebook.com/profile.php?id=61576955305181" target="_blank" class="sawp-social-icon sawp-facebook">
                                    <svg class="e-font-icon-svg e-fab-facebook" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="M504 256C504 119 393 8 256 8S8 119 8 256c0 123.78 90.69 226.38 209.25 245V327.69h-63V256h63v-54.64c0-62.15 37-96.48 93.67-96.48 27.14 0 55.52 4.84 55.52 4.84v61h-31.28c-30.8 0-40.41 19.12-40.41 38.73V256h68.78l-11 71.69h-57.78V501C413.31 482.38 504 379.78 504 256z"></path></svg>
                                </a>
                                <a href="https://www.instagram.com/notice.ro/" target="_blank" class="sawp-social-icon sawp-instagram">
                                    <svg class="e-font-icon-svg e-fab-instagram" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path d="M224.1 141c-63.6 0-114.9 51.3-114.9 114.9s51.3 114.9 114.9 114.9S339 319.5 339 255.9 287.7 141 224.1 141zm0 189.6c-41.1 0-74.7-33.5-74.7-74.7s33.5-74.7 74.7-74.7 74.7 33.5 74.7 74.7-33.6 74.7-74.7 74.7zm146.4-194.3c0 14.9-12 26.8-26.8 26.8-14.9 0-26.8-12-26.8-26.8s12-26.8 26.8-26.8 26.8 12 26.8 26.8zm76.1 27.2c-1.7-35.9-9.9-67.7-36.2-93.9-26.2-26.2-58-34.4-93.9-36.2-37-2.1-147.9-2.1-184.9 0-35.8 1.7-67.6 9.9-93.9 36.1s-34.4 58-36.2 93.9c-2.1 37-2.1 147.9 0 184.9 1.7 35.9 9.9 67.7 36.2 93.9s58 34.4 93.9 36.2c37 2.1 147.9 2.1 184.9 0 35.9-1.7 67.7-9.9 93.9-36.2 26.2-26.2 34.4-58 36.2-93.9 2.1-37 2.1-147.8 0-184.8zM398.8 388c-7.8 19.6-22.9 34.7-42.6 42.6-29.5 11.7-99.5 9-132.1 9s-102.7 2.6-132.1-9c-19.6-7.8-34.7-22.9-42.6-42.6-11.7-29.5-9-99.5-9-132.1s-2.6-102.7 9-132.1c7.8-19.6 22.9-34.7 42.6-42.6 29.5-11.7 99.5-9 132.1-9s102.7-2.6 132.1 9c19.6 7.8 34.7 22.9 42.6 42.6 11.7 29.5 9 99.5 9 132.1s2.7 102.7-9 132.1z"></path></svg>
                                </a>
                                <a href="https://www.tiktok.com/@notice.ro" target="_blank" class="sawp-social-icon sawp-tiktok">
                                    <svg class="e-font-icon-svg e-fab-tiktok" viewBox="0 0 448 512" xmlns="http://www.w3.org/2000/svg"><path d="M448,209.91a210.06,210.06,0,0,1-122.77-39.25V349.38A162.55,162.55,0,1,1,185,188.31V278.2a74.62,74.62,0,1,0,52.23,71.18V0l88,0a121.18,121.18,0,0,0,1.86,22.17h0A122.18,122.18,0,0,0,381,102.39a121.43,121.43,0,0,0,67,20.14Z"></path></svg>
                                </a>
                                <a href="https://www.youtube.com/@notice.romania" target="_blank" class="sawp-social-icon sawp-youtube">
                                    <svg class="e-font-icon-svg e-fab-youtube" viewBox="0 0 576 512" xmlns="http://www.w3.org/2000/svg"><path d="M549.655 124.083c-6.281-23.65-24.787-42.276-48.284-48.597C458.781 64 288 64 288 64S117.22 64 74.629 75.486c-23.497 6.322-42.003 24.947-48.284 48.597-11.412 42.867-11.412 132.305-11.412 132.305s0 89.438 11.412 132.305c6.281 23.65 24.787 41.5 48.284 47.821C117.22 448 288 448 288 448s170.78 0 213.371-11.486c23.497-6.321 42.003-24.171 48.284-47.821 11.412-42.867 11.412-132.305 11.412-132.305s0-89.438-11.412-132.305zm-317.51 213.508V175.185l142.739 81.205-142.739 81.201z"></path></svg>
                                </a>
                            </div>
                            <?php if ($subscription_name) : ?>
                                <div class="sawp-status-badge <?php echo strtolower($subscription_name) === 'trial' ? 'sawp-trial' : 'sawp-active'; ?>">
                                    <?php echo esc_html($subscription_name); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="sawp-card-content">
                            <div class="sawp-credits-info">
                                <div class="sawp-credits-label">Credite rămase</div>
                                <div class="sawp-credits-value">
                                    <span class="sawp-credits-remaining"><?php echo esc_html($remaining_credits); ?></span>
                                    <span class="sawp-credits-separator">/</span>
                                    <span class="sawp-credits-total"><?php echo esc_html($total_credits); ?></span>
                                </div>
                                 <div class="sawp-credits-bar">
                                    <div class="sawp-credits-progress" style="width: <?php echo esc_attr(($total_credits > 0) ? ($remaining_credits / $total_credits * 100) : 0); ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="options.php" class="sawp-settings-form" id="sawp-settings-form">
                    <?php settings_fields('sawp_group'); do_settings_sections('sawp-settings'); ?>
                    <div class="sawp-form-actions">
                        <button type="submit" class="sawp-btn sawp-btn-primary sawp-btn-large" id="sawp-save-settings">
                            <span class="dashicons dashicons-saved"></span> Salvează modificările
                        </button>
                    </div>
                </form>
                
                <!-- Card de resetare -->
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>Resetare Plugin</h3>
                    </div>
                    <div class="sawp-card-content">
                        <p>Resetează complet toate setările și datele pluginului. </p>
                        <button type="button" class="sawp-btn sawp-btn-error" id="sawp-reset-plugin">
                            <span class="dashicons dashicons-trash"></span> Resetare completă
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="sawp-sidebar">
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>Contact Support</h3>
                    </div>
                    <div class="sawp-card-content">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('sms_contact','sms_contact_nonce'); ?>
                            <input type="hidden" name="action" value="sawp_contact">
                            <div class="sawp-form-group">
                                <label for="sms_name">Nume</label>
                                <input id="sms_name" name="sms_name" class="sawp-input" required>
                            </div>
                            <div class="sawp-form-group">
                                <label for="sms_email">Email</label>
                                <input id="sms_email" name="sms_email" type="email" class="sawp-input" required>
                            </div>
                            <div class="sawp-form-group">
                                <label for="sms_message">Mesaj</label>
                                <textarea id="sms_message" name="sms_message" class="sawp-textarea" rows="5" required></textarea>
                            </div>
                            <button type="submit" class="sawp-btn sawp-btn-primary sawp-btn-block">
                                Trimite mesaj
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>Informații companie</h3>
                    </div>
                    <div class="sawp-card-content">
                        <div class="sawp-company-info">
                            <div class="sawp-company-name">BUSINESS INCEPTION SRL</div>
                            <div class="sawp-company-details">
                                <div><strong>Cod fiscal:</strong> 41455280</div>
                                <div><strong>Nr. Reg. Com.:</strong> J40/9917/2019</div>
                                <div><strong>Email:</strong> <a href="mailto:contact@notice.ro">contact@notice.ro</a></div>
                                <div><strong>Suport:</strong> <a href="tel:+40772203628">+40 772 203 628</a></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>SMS-uri Trimise</h3>
                        <div class="sawp-header-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_refresh_templates'),'sawp_refresh_tpl','sawp_refresh_tpl_nonce')); ?>" class="sawp-btn sawp-btn-primary sawp-btn-sm">
                                <span class="dashicons dashicons-update-alt"></span>
                            </a>
                        </div>
                    </div>
                    <div class="sawp-card-content">
                        <div class="sawp-stats-grid">
                            <div class="sawp-stat-item">
                                <div class="sawp-stat-value"><?php echo intval($count_today); ?></div>
                                <div class="sawp-stat-label">SMS-uri azi</div>
                            </div>
                            <div class="sawp-stat-item">
                                <?php 
                                $total_sms = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$SAWP_LOG_TABLE}");
                                ?>
                                <div class="sawp-stat-value"><?php echo $total_sms; ?></div>
                                <div class="sawp-stat-label">SMS-uri totale</div>
                            </div>
                        </div>
                        
                        <div class="sawp-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_export_logs'),'sawp_export_logs','sawp_export_logs_nonce')); ?>" class="sawp-btn sawp-btn-secondary">
                                <span class="dashicons dashicons-download"></span> Exportă log-uri
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-confirmari')); ?>" class="sawp-btn sawp-btn-secondary">
                                <span class="dashicons dashicons-list-view"></span> Toate comenzile
                            </a>
                        </div>
                        
                        <h4>Ultimele SMS-uri</h4>
                        <div class="sawp-recent-sms">
                            <?php
                            $recent = $wpdb->get_results("SELECT order_id, phone, template_id, template_name, status_code, date_sent FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC LIMIT 4"); // phpcs:ignore WordPress.DB.PreparedSQL
                            if ($recent) {
                                foreach ($recent as $r) {
                                    $ok   = (intval($r->status_code) === 200) ? 'Da' : 'Nu';
                                    $t_nm = sawp_resolve_template_name($r->template_id, $r->template_name);
                                    echo '<div class="sawp-sms-item">';
                                    echo '<div class="sawp-sms-status ' . ($ok === 'Da' ? 'sawp-success' : 'sawp-error') . '">' . esc_html($ok) . '</div>';
                                    echo '<div class="sawp-sms-details">';
                                    echo '<div class="sawp-sms-order">#' . esc_html($r->order_id) . '</div>';
                                    echo '<div class="sawp-sms-phone">' . esc_html($r->phone) . '</div>';
                                    echo '<div class="sawp-sms-template">' . esc_html($t_nm) . '</div>';
                                    echo '<div class="sawp-sms-date">' . esc_html(date('H:i', strtotime($r->date_sent))) . '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                            } else { 
                                echo '<div class="sawp-no-data">Nu există SMS-uri</div>'; 
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="sawp-card">
                    <div class="sawp-card-header">
                        <h3>SMS-uri primite</h3>
                        <div class="sawp-header-actions">
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_fetch_received'),'sawp_fetch_received','sawp_fetch_received_nonce')); ?>" class="sawp-btn sawp-btn-primary sawp-btn-sm">
                                <span class="dashicons dashicons-update-alt"></span>
                            </a>
                        </div>
                    </div>
                    <div class="sawp-card-content">
                        <?php
                        $received = get_transient('sawp_received_sms');
                        $received_count = 0;
                        
                        if (is_array($received)) {
                            $received_count = count($received);
                        }
                        ?>
                        <div class="sawp-stats-grid">
                            <div class="sawp-stat-item">
                                <div class="sawp-stat-value"><?php echo esc_html($received_count); ?></div>
                                <div class="sawp-stat-label">SMS-uri primite</div>
                            </div>
                            <div class="sawp-stat-item">
                                <?php 
                                $unread_count = 0;
                                if (is_array($received)) {
                                    foreach ($received as $sms) {
                                        if (empty($sms['read_at'])) {
                                            $unread_count++;
                                        }
                                    }
                                }
                                ?>
                                <div class="sawp-stat-value"><?php echo esc_html($unread_count); ?></div>
                                <div class="sawp-stat-label">Necitite</div>
                            </div>
                        </div>
                        
                        <div class="sawp-actions">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-received')); ?>" class="sawp-btn sawp-btn-secondary">
                                <span class="dashicons dashicons-email-alt"></span> Vezi toate
                            </a>
                        </div>
                        
                        <?php if ($received_count > 0) : ?>
                            <h4>Ultimele SMS-uri primite</h4>
                            <div class="sawp-received-sms">
                                <?php
                                $recent_received = array_slice($received, 0, 3);
                                foreach ($recent_received as $sms) :
                                    $from_raw = sawp_extract_phone_raw($sms);
                                    $message = sawp_extract_message($sms);
                                    ?>
                                    <div class="sawp-sms-item">
                                        <div class="sawp-sms-status sawp-info">
                                            <span class="dashicons dashicons-email-alt"></span>
                                        </div>
                                        <div class="sawp-sms-details">
                                            <div class="sawp-sms-phone"><?php echo esc_html($from_raw); ?></div>
                                            <div class="sawp-sms-message"><?php echo esc_html(substr($message, 0, 30)) . (strlen($message) > 30 ? '...' : ''); ?></div>
                                            <div class="sawp-sms-date"><?php echo esc_html(sawp_extract_datetime_display($sms)); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <a href="https://api.whatsapp.com/send/?phone=+40772203628&text=Sunt+pe+Notice+si+am+nevoie+de+ajutor+cu%3A%20&type=phone_number&app_absent=0"
           class="sawp-whatsapp-float" target="_blank" rel="noopener noreferrer">
            <span class="dashicons dashicons-whatsapp"></span>
        </a>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Funcție pentru afișare/ascundere template-uri
        function toggleTemplateContainer() {
            $('.sawp-switch-input').each(function() {
                var slug = $(this).data('slug');
                var isChecked = $(this).is(':checked');
                var container = $('#tpl-container-' + slug);
                
                if (isChecked) {
                    container.show();
                } else {
                    container.hide();
                }
            });
        }
        
        // Inițializare la încărcare pagină
        toggleTemplateContainer();
        
        // Eveniment la schimbarea switch-ului
        $(document).on('change', '.sawp-switch-input', function() {
            toggleTemplateContainer();
        });
        
        // Actualizează previzualizarea la schimbarea template-ului
        $(document).on('change', '.sawp-template-select', function() {
            var slug = $(this).data('slug');
            var selectedOption = $(this).find('option:selected');
            var previewText = selectedOption.data('text') || '';
            var preview = $('#preview-' + slug);
            
            if (previewText) {
                preview.text(previewText).show();
            } else {
                preview.hide();
            }
        });
        
        // Resetare plugin
        $('#sawp-reset-plugin').on('click', function() {
            if (confirm('Sigur doriți să resetați complet pluginul? Această acțiune este ireversibilă!')) {
                $(this).prop('disabled', true).text('Se resetează...');
                
                $.post(ajaxurl, {
                    action: 'sawp_reset_plugin',
                    nonce: '<?php echo wp_create_nonce('sawp_reset_plugin'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Pluginul a fost resetat cu succes!');
                        location.reload();
                    } else {
                        alert('A apărut o eroare la resetare.');
                    }
                });
            }
        });
        
        // Reîmprospătare forțată
        $('#sawp-force-refresh').on('click', function() {
            $(this).prop('disabled', true).find('.dashicons').addClass('spin');
            
            $.post(ajaxurl, {
                action: 'sawp_force_refresh',
                nonce: '<?php echo wp_create_nonce('sawp_force_refresh'); ?>'
            }, function(response) {
                location.reload();
            });
        });
        
        // Salvare setări cu feedback
        $('#sawp-settings-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $('#sawp-save-settings');
            
            $submitBtn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Se salvează...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: $form.serialize() + '&action=sawp_save_settings&nonce=<?php echo wp_create_nonce('sawp_save_settings'); ?>',
                success: function(response) {
                    if (response.success) {
                        $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Salvat!');
                        setTimeout(function() {
                            $submitBtn.html('<span class="dashicons dashicons-saved"></span> Salvează modificările');
                        }, 2000);
                    } else {
                        alert('A apărut o eroare la salvare: ' + response.data);
                        $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Salvează modificările');
                    }
                },
                error: function() {
                    alert('A apărut o eroare la salvare.');
                    $submitBtn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Salvează modificările');
                }
            });
        });
    });
    </script>
    <?php
}
// Handler pentru salvarea setărilor via AJAX
add_action('wp_ajax_sawp_save_settings', 'sawp_save_settings_handler');
function sawp_save_settings_handler() {
    check_ajax_referer('sawp_save_settings', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Nu aveți permisiunea necesară.');
    }
    
    // Procesează și salvează opțiunile
    if (isset($_POST['sawp_opts'])) {
        $sanitized_opts = sawp_sanitize_options($_POST['sawp_opts']);
        update_option('sawp_opts', $sanitized_opts);
        
        // Resetează transient-urile legate de template-uri
        delete_transient('sawp_tpl_list');
        delete_transient('sawp_user_units');
        
        wp_send_json_success();
    }
    
    wp_send_json_error('Nu s-au primit datele pentru salvare.');
}
/* ================== CONFIRMĂRI COMENZI ================== */
function sawp_render_confirmations_page() {
    sawp_maybe_create_table();
    global $wpdb, $SAWP_LOG_TABLE;
    $logs = $wpdb->get_results( "SELECT order_id, template_id, template_name, status_code, date_sent FROM {$SAWP_LOG_TABLE} ORDER BY date_sent DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL
    ?>
    <div class="wrap">
        <div class="sawp-page-header">
            <div class="sawp-page-title-container">
                <h1 class="sawp-page-title">
                    <span class="dashicons dashicons-list-view"></span>
                    Confirmări comenzi
                </h1>
            </div>
            <div class="sawp-page-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-settings')); ?>" class="sawp-btn sawp-btn-secondary">
                   <span class="dashicons dashicons-arrow-left-alt"></span>  Înapoi la setări
                </a>
            </div>
        </div>
        <div class="sawp-title-line"></div>
        
        <style>
        /* Stiluri specifice pentru pagina de confirmări comenzi */
        .sawp-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .sawp-page-title-container {
            flex: 1;
        }
        
        .sawp-page-actions {
            display: flex;
            gap: 12px;
        }
        
        .sawp-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            border: 1px solid #e5e7eb;
            margin-bottom: 24px;
        }
        
        .sawp-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f9fafb;
        }
        
        .sawp-card-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .sawp-card-content {
            padding: 20px;
        }
        
        .sawp-table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .sawp-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
        }
        
        .sawp-table th,
        .sawp-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sawp-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        .sawp-table tr:hover {
            background: #f3f4f6;
        }
        
        .sawp-table tr:last-child td {
            border-bottom: none;
        }
        
        .sawp-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-btn-primary {
            background: #6366f1;
            color: white;
            border: 1px solid #4f46e5;
        }
        
        .sawp-btn-primary:hover {
            background: #4f46e5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .sawp-btn-secondary {
            background: white;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }
        
        .sawp-btn-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .sawp-badge.sawp-success {
            background: #10b981;
            color: white;
        }
        
        .sawp-badge.sawp-error {
            background: #ef4444;
            color: white;
        }
        
        .sawp-link {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }
        
        .sawp-link:hover {
            text-decoration: underline;
        }
        
        .sawp-no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .sawp-header-actions {
            display: flex;
            gap: 8px;
        }
        
        .sawp-title-line {
            height: 3px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 2px;
            margin-bottom: 24px;
        }
        </style>
        
        <div class="sawp-card">
            <div class="sawp-card-header">
                <h3>Istoric SMS-uri trimise</h3>
                <div class="sawp-header-actions">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_export_logs'),'sawp_export_logs','sawp_export_logs_nonce')); ?>" class="sawp-btn sawp-btn-primary">
                        <span class="dashicons dashicons-download"></span> Exportă log-uri
                    </a>
                </div>
            </div>
            <div class="sawp-card-content">
                <div class="sawp-table-container">
                    <table class="sawp-table">
                        <thead>
                            <tr>
                                <th>Nr. Comenzii</th>
                                <th>Template</th>
                                <th>Trimis SMS</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ( $logs ) {
                                foreach ( $logs as $row ) {
                                    $sent = ( intval( $row->status_code ) === 200 ) ? 'Da' : 'Nu';
                                    $t_nm = sawp_resolve_template_name($row->template_id, $row->template_name);
                                    
                                    echo '<tr>';
                                    echo '<td>#' . esc_html( $row->order_id ) . '</td>';
                                    echo '<td>' . esc_html($t_nm) . '</td>';
                                    echo '<td><span class="sawp-badge ' . ($sent === 'Da' ? 'sawp-success' : 'sawp-error') . '">' . esc_html( $sent ) . '</span></td>';
                                    echo '<td>' . esc_html( date_i18n('d M Y H:i', strtotime($row->date_sent)) ) . '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="4" class="sawp-no-data">Nu există confirmări.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php
}
/* ================== SMS‑URI PRIMITE ================== */
function sawp_render_received_page() {
    $received = get_transient('sawp_received_sms');
    $last_update = get_transient('sawp_received_last_update');
    ?>
    <div class="wrap">
        <div class="sawp-page-header">
            <div class="sawp-page-title-container">
                <h1 class="sawp-page-title">
                    <span class="dashicons dashicons-email-alt"></span>
                    SMS-uri primite
                </h1>
            </div>
            <div class="sawp-page-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sawp-settings')); ?>" class="sawp-btn sawp-btn-secondary">
                    <span class="dashicons dashicons-arrow-left-alt"></span> Înapoi la setări
                </a>
            </div>
        </div>
        <div class="sawp-title-line"></div>
        
        <style>
        /* Stiluri specifice pentru pagina de SMS-uri primite */
        .sawp-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .sawp-page-title-container {
            flex: 1;
        }
        
        .sawp-page-actions {
            display: flex;
            gap: 12px;
        }
        
        .sawp-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            border: 1px solid #e5e7eb;
            margin-bottom: 24px;
        }
        
        .sawp-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f9fafb;
        }
        
        .sawp-card-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .sawp-card-content {
            padding: 20px;
        }
        
        .sawp-table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
            margin-top: 16px;
        }
        
        .sawp-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            font-size: 14px;
        }
        
        .sawp-table th,
        .sawp-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sawp-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        .sawp-table tr:hover {
            background: #f3f4f6;
        }
        
        .sawp-table tr:last-child td {
            border-bottom: none;
        }
        
        .sawp-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-btn-primary {
            background: #6366f1;
            color: white;
            border: 1px solid #4f46e5;
        }
        
        .sawp-btn-primary:hover {
            background: #4f46e5;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .sawp-btn-secondary {
            background: white;
            color: #4b5563;
            border: 1px solid #d1d5db;
        }
        
        .sawp-btn-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .sawp-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .sawp-badge.sawp-success {
            background: #10b981;
            color: white;
        }
        
        .sawp-badge.sawp-error {
            background: #ef4444;
            color: white;
        }
        
        .sawp-link {
            color: #6366f1;
            text-decoration: none;
            font-weight: 500;
        }
        
        .sawp-link:hover {
            text-decoration: underline;
        }
        
        .sawp-no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .sawp-header-actions {
            display: flex;
            gap: 8px;
        }
        
        .sawp-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .sawp-stat-item {
            text-align: center;
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .sawp-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #6366f1;
        }
        
        .sawp-stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .sawp-last-update {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 16px;
            text-align: right;
        }
        
        .sawp-title-line {
            height: 3px;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            border-radius: 2px;
            margin-bottom: 24px;
        }
        </style>
        
        <div class="sawp-card">
            <div class="sawp-card-header">
                <h3>SMS-uri primite</h3>
                <div class="sawp-header-actions">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=sawp_fetch_received'),'sawp_fetch_received','sawp_fetch_received_nonce')); ?>" class="sawp-btn sawp-btn-primary">
                        <span class="dashicons dashicons-update-alt"></span> Reîmprospătează
                    </a>
                </div>
            </div>
            <div class="sawp-card-content">
                <?php if ($last_update) : ?>
                    <div class="sawp-last-update">Ultima actualizare: <?php echo esc_html(date_i18n('d M Y H:i:s', $last_update)); ?></div>
                <?php endif; ?>
                
                <?php if (is_wp_error($received)) : ?>
                    <div class="notice notice-error"><p>Eroare la preluarea SMS-urilor: <?php echo esc_html($received->get_error_message()); ?></p></div>
                <?php elseif (empty($received)) : ?>
                    <div class="sawp-no-data">Nu există SMS-uri primite.</div>
                <?php else : ?>
                    <div class="sawp-stats-grid">
                        <div class="sawp-stat-item">
                            <div class="sawp-stat-value"><?php echo count($received); ?></div>
                            <div class="sawp-stat-label">SMS-uri primite</div>
                        </div>
                        <div class="sawp-stat-item">
                            <?php 
                            $unread_count = 0;
                            foreach ($received as $sms) {
                                if (empty($sms['read_at'])) {
                                    $unread_count++;
                                }
                            }
                            ?>
                            <div class="sawp-stat-value"><?php echo esc_html($unread_count); ?></div>
                            <div class="sawp-stat-label">Necitite</div>
                        </div>
                    </div>
                    
                    <div class="sawp-table-container">
                        <table class="sawp-table">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Telefon</th>
                                    <th>Mesaj</th>
                                    <th>Comandă</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($received as $sms) :
                                    $created_disp = sawp_extract_datetime_display($sms);
                                    $from_raw     = sawp_extract_phone_raw($sms);
                                    $message      = sawp_extract_message($sms);
                                    $order_id     = $from_raw ? sawp_find_order_by_phone($from_raw) : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo $created_disp ? esc_html($created_disp) : '—'; ?></td>
                                        <td><?php echo $from_raw ? esc_html($from_raw) : '—'; ?></td>
                                        <td><?php echo $message !== '' ? esc_html($message) : '—'; ?></td>
                                        <td>
                                            <?php
                                            if ($order_id) {
                                                $order_url = admin_url("post.php?post={$order_id}&action=edit");
                                                echo '<a href="'.esc_url($order_url).'" class="sawp-link">#'.esc_html($order_id).'</a>';
                                            } else { echo '—'; }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
/* ================== FETCH SMS‑URI PRIMITE ================== */
function sawp_handle_fetch_received() {
    if (!current_user_can('manage_options')) { wp_die('Permisiune refuzată.'); }
    if (!isset($_GET['sawp_fetch_received_nonce']) || !wp_verify_nonce($_GET['sawp_fetch_received_nonce'], 'sawp_fetch_received')) {
        wp_die('Nonce invalid.');
    }
    
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    if (!$token) { 
        wp_safe_redirect(add_query_arg(['page' => 'sawp-received', 'error' => '1'], admin_url('admin.php'))); 
        exit; 
    }
    
    $response = wp_remote_get('https://api.notice.ro/api/v1/sms-in', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        set_transient('sawp_received_sms', $response, 15 * MINUTE_IN_SECONDS);
    } else {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $data = [];
        
        // Handle response structure according to API documentation
        if (is_array($body)) {
            if (isset($body['data']) && is_array($body['data'])) { 
                $data = $body['data']; 
            } elseif (isset($body[0])) { 
                $data = $body; 
            }
        }
        
        set_transient('sawp_received_sms', $data, 15 * MINUTE_IN_SECONDS);
        set_transient('sawp_received_last_update', time(), 15 * MINUTE_IN_SECONDS);
    }
    
    wp_safe_redirect(admin_url('admin.php?page=sawp-received')); 
    exit;
}
/* ================== CÂMPURI SETĂRI SIMPLE ================== */
function sawp_field_token() {
    $opts = get_option('sawp_opts', []);
    $token = $opts['token'] ?? '';
    
    echo '<div style="display: flex; gap: 10px; align-items: center;">';
    printf(
        '<input type="text" name="sawp_opts[token]" value="%s" class="sawp-input" placeholder="Introduceți token-ul API">',
        esc_attr($token)
    );
    
    if (!empty($token)) {
        echo '<button type="button" class="button button-secondary" id="sawp-reset-token">Resetează token</button>';
    }
    echo '</div>';
    
    // Adaugă script pentru resetare
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#sawp-reset-token').on('click', function() {
            if (confirm('Sigur doriți să resetați token-ul? Aceasta va șterge toate datele temporare.')) {
                $('input[name="sawp_opts[token]"]').val('');
                $.post(ajaxurl, {
                    action: 'sawp_reset_token_data',
                    nonce: '<?php echo wp_create_nonce('sawp_reset_token'); ?>'
                }, function() {
                    location.reload();
                });
            }
        });
    });
    </script>
    <?php
}
function sawp_field_test() {
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    
    if (!$token) { 
        echo '<span class="sawp-test-error">Token nu este setat.</span>'; 
        return; 
    }
    
    $res = wp_remote_post('https://api.notice.ro/api/v1/sms-out', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => wp_json_encode([
            'number' => '0700000000',
            'template_id' => 0,
            'variables' => ['order_id' => 'TEST']
        ]),
        'timeout' => 15,
    ]);
    
    if (is_wp_error($res)) {
        echo '<span class="sawp-test-error">Eroare: ' . esc_html($res->get_error_message()) . '</span>';
    } else {
        $status_code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        
        if (200 === (int) $status_code) {
            echo '<span class="sawp-test-success">Conexiune OK</span>';
        } else {
            echo '<span class="sawp-test-error">Eroare (' . esc_html($status_code) . '): ' . esc_html($body) . '</span>';
        }
    }
}
/* ================== CSS/JS ================== */
function sawp_enqueue_assets($hook) {
    $allowed = ['toplevel_page_sawp-settings','sawp_page_sawp-confirmari','sawp_page_sawp-received'];
    if ( ! in_array($hook, $allowed, true) ) { return; }
    
    // Înregistrăm un fișier CSS specific pentru plugin
    wp_register_style('sawp-admin-styles', false);
    wp_enqueue_style('sawp-admin-styles');
    
    wp_enqueue_style('common');
    wp_enqueue_style('dashicons');
    wp_enqueue_script('jquery');
    
    $css = <<<CSS
/* Variabile CSS */
:root {
    --sawp-primary: #6366f1;
    --sawp-primary-dark: #4f46e5;
    --sawp-secondary: #8b5cf6;
    --sawp-success: #10b981;
    --sawp-error: #ef4444;
    --sawp-warning: #f59e0b;
    --sawp-info: #3b82f6;
    --sawp-gray-50: #f9fafb;
    --sawp-gray-100: #f3f4f6;
    --sawp-gray-200: #e5e7eb;
    --sawp-gray-300: #d1d5db;
    --sawp-gray-400: #9ca3af;
    --sawp-gray-500: #6b7280;
    --sawp-gray-600: #4b5563;
    --sawp-gray-700: #374151;
    --sawp-gray-800: #1f2937;
    --sawp-gray-900: #111827;
    --sawp-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --sawp-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --sawp-border-radius: 8px;
    --sawp-border-color: #e5e7eb;
}
/* Reset și stiluri de bază */
.sawp-dashboard {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
    margin-top: 24px;
}
.sawp-main-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 28px;
    font-weight: 700;
    color: var(--sawp-gray-800);
    margin-bottom: 16px;
}
.sawp-title-line {
    height: 3px;
    background: linear-gradient(90deg, var(--sawp-primary), var(--sawp-secondary));
    border-radius: 2px;
    margin-bottom: 24px;
}
.sawp-page-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 24px;
    font-weight: 700;
    color: var(--sawp-gray-800);
    margin-bottom: 16px;
}
.sawp-page-title .dashicons {
    font-size: 28px;
    color: var(--sawp-primary);
}
.sawp-title-icon {
    font-size: 32px;
}
/* Card-uri */
.sawp-card {
    background: white;
    border-radius: var(--sawp-border-radius);
    box-shadow: var(--sawp-shadow);
    overflow: hidden;
    margin-bottom: 24px;
    border: 1px solid var(--sawp-border-color);
}
.sawp-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--sawp-border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: var(--sawp-gray-50);
}
.sawp-card-header h2,
.sawp-card-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--sawp-gray-800);
}
.sawp-card-content {
    padding: 20px;
}
/* Butoane */
.sawp-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--sawp-border-radius);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.sawp-btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}
.sawp-btn-primary {
    background: var(--sawp-primary);
    color: white;
    border: 1px solid var(--sawp-primary-dark);
}
.sawp-btn-primary:hover {
    background: var(--sawp-primary-dark);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.sawp-btn-secondary {
    background: white;
    color: var(--sawp-gray-700);
    border: 1px solid var(--sawp-border-color);
}
.sawp-btn-secondary:hover {
    background: var(--sawp-gray-50);
    border-color: var(--sawp-gray-300);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}
.sawp-btn-error {
    background: var(--sawp-error);
    color: white;
    border: 1px solid #dc2626;
}
.sawp-btn-error:hover {
    background: #dc2626;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.sawp-btn-large {
    padding: 12px 24px;
    font-size: 16px;
}
.sawp-btn-block {
    display: flex;
    width: 100%;
    justify-content: center;
}
/* Formulare */
.sawp-input,
.sawp-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    font-size: 14px;
    transition: border-color 0.2s ease;
    background-color: white;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
}
.sawp-input:focus,
.sawp-textarea:focus {
    outline: none;
    border-color: var(--sawp-primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
.sawp-textarea {
    resize: vertical;
    min-height: 100px;
}
.sawp-form-group {
    margin-bottom: 16px;
}
.sawp-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    color: var(--sawp-gray-700);
}
.sawp-form-actions {
    margin-top: 24px;
    margin-bottom:20px;
    padding-top: 24px;
    border-top: 1px solid var(--sawp-border-color);
}
/* Panou principal */
.sawp-main-panel {
    background: white;
    border-radius: var(--sawp-border-radius);
    box-shadow: var(--sawp-shadow);
    padding: 24px;
    border: 1px solid var(--sawp-border-color);
}
.sawp-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--sawp-border-color);
}
.sawp-panel-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    color: var(--sawp-gray-800);
}
/* Card cont */
.sawp-account-card {
    background: linear-gradient(135deg, var(--sawp-primary) 0%, var(--sawp-secondary) 100%);
    color: white;
    margin-bottom: 24px;
    border: none;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}
.sawp-account-card .sawp-card-header {
    border-bottom-color: rgba(255, 255, 255, 0.2);
    background-color: transparent;
}
.sawp-account-card .sawp-card-header h3 {
    color: white;
}
.sawp-logo-container {
    display: flex;
    align-items: center;
}
.sawp-logo {
    height: 60px;
    width: auto;
}
.sawp-social-icons {
    display: flex;
    gap: 12px;
}
.sawp-social-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    color: white;
    transition: all 0.2s ease;
}
.sawp-social-icon:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
}
.sawp-social-icon svg {
    width: 16px;
    height: 16px;
    fill: currentColor;
}
.sawp-status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}
.sawp-status-badge.sawp-trial {
    background: rgba(255, 255, 255, 0.2);
}
.sawp-status-badge.sawp-active {
    background: var(--sawp-success);
}
.sawp-credits-info {
    margin-bottom: 16px;
}
.sawp-credits-label {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 8px;
}
.sawp-credits-value {
    font-size: 32px;
    font-weight: 700;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.sawp-credits-remaining {
    font-size: 48px;
}
.sawp-credits-separator {
    font-size: 24px;
    opacity: 0.7;
}
.sawp-credits-total {
    font-size: 24px;
    opacity: 0.7;
}
.sawp-credits-bar {
    height: 8px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    margin-top: 12px;
    overflow: hidden;
    position: relative;
}
.sawp-credits-progress {
    height: 100%;
    background: white;
    border-radius: 4px;
    transition: width 0.5s ease;
}
.sawp-credits-indicator {
    position: absolute;
    top: -10px;
    transform: translateX(-50%);
    transition: left 0.5s ease;
}
.sawp-indicator-pin {
    width: 0;
    height: 0;
    border-left: 8px solid transparent;
    border-right: 8px solid transparent;
    border-bottom: 12px solid white;
    margin: 0 auto 4px;
}
.sawp-indicator-phone {
    width: 20px;
    height: 20px;
    background: var(--sawp-primary);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.sawp-indicator-phone::before {
    content: "📱";
    font-size: 12px;
}
.sawp-expiry-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    opacity: 0.9;
}
/* Sidebar */
.sawp-sidebar .sawp-card {
    margin-bottom: 20px;
}
.sawp-company-info {
    display: grid;
    gap: 8px;
}
.sawp-company-name {
    font-weight: 600;
    color: var(--sawp-gray-800);
}
.sawp-company-details {
    font-size: 14px;
    color: var(--sawp-gray-600);
}
.sawp-company-details a {
    color: var(--sawp-primary);
    text-decoration: none;
}
.sawp-company-details a:hover {
    text-decoration: underline;
}
/* Statistici */
.sawp-stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}
.sawp-stat-item {
    text-align: center;
    padding: 16px;
    background: var(--sawp-gray-50);
    border-radius: var(--sawp-border-radius);
    border: 1px solid var(--sawp-border-color);
}
.sawp-stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--sawp-primary);
}
.sawp-stat-label {
    font-size: 12px;
    color: var(--sawp-gray-600);
    margin-top: 4px;
}
.sawp-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 20px;
}
/* SMS-uri recente */
.sawp-recent-sms,
.sawp-received-sms {
    display: grid;
    gap: 8px;
}
.sawp-sms-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    background: var(--sawp-gray-50);
    border-radius: var(--sawp-border-radius);
    border: 1px solid var(--sawp-border-color);
}
.sawp-sms-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}
.sawp-sms-status.sawp-success {
    background: var(--sawp-success);
    color: white;
}
.sawp-sms-status.sawp-error {
    background: var(--sawp-error);
    color: white;
}
.sawp-sms-status.sawp-info {
    background: var(--sawp-info);
    color: white;
}
.sawp-sms-details {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    font-size: 12px;
}
.sawp-sms-order {
    font-weight: 600;
}
.sawp-sms-phone {
    color: var(--sawp-gray-600);
}
.sawp-sms-template,
.sawp-sms-message {
    color: var(--sawp-gray-500);
}
.sawp-sms-date {
    color: var(--sawp-gray-400);
}
/* Tabele - Stiluri îmbunătățite */
.sawp-table-container {
    background: white;
    border-radius: var(--sawp-border-radius);
    box-shadow: var(--sawp-shadow);
    border: 1px solid var(--sawp-border-color);
    overflow: hidden;
    margin-top: 16px;
}
.sawp-table-responsive {
    overflow-x: auto;
}
.sawp-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}
.sawp-table th,
.sawp-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid var(--sawp-border-color);
}
.sawp-table th {
    background: var(--sawp-gray-50);
    font-weight: 600;
    color: var(--sawp-gray-700);
    border-bottom: 2px solid var(--sawp-border-color);
}
.sawp-table tr:hover {
    background: var(--sawp-gray-50);
}
.sawp-table tr:last-child td {
    border-bottom: none;
}
.sawp-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}
.sawp-badge.sawp-success {
    background: var(--sawp-success);
    color: white;
}
.sawp-badge.sawp-error {
    background: var(--sawp-error);
    color: white;
}
/* Linkuri */
.sawp-link {
    color: var(--sawp-primary);
    text-decoration: none;
    font-weight: 500;
}
.sawp-link:hover {
    text-decoration: underline;
}
/* No data */
.sawp-no-data {
    text-align: center;
    padding: 40px;
    color: var(--sawp-gray-500);
    background: var(--sawp-gray-50);
    border-radius: var(--sawp-border-radius);
    border: 1px solid var(--sawp-border-color);
}
/* WhatsApp float */
.sawp-whatsapp-float {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 56px;
    height: 56px;
    background: #25D366;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    text-decoration: none;
    z-index: 1000;
    transition: transform 0.2s ease;
}
.sawp-whatsapp-float:hover {
    transform: scale(1.1);
}
.sawp-whatsapp-float .dashicons {
    color: white;
    font-size: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Switch */
.sawp-field-container {
    margin-bottom: 20px;
    padding: 16px;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    background: var(--sawp-gray-50);
}
.sawp-toggle-row {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}
.sawp-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 24px;
    margin-right: 12px;
}
.sawp-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.sawp-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--sawp-gray-300);
    transition: .4s;
    border-radius: 24px;
}
.sawp-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
input:checked + .sawp-slider {
    background-color: var(--sawp-primary);
}
input:checked + .sawp-slider:before {
    transform: translateX(24px);
}
.sawp-pending-note {
    font-style: italic;
    color: var(--sawp-gray-600);
}
/* Template container */
.sawp-template-container {
    background: white;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    padding: 12px;
}
.sawp-template-select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    margin-bottom: 12px;
    font-size: 14px;
    background-color: white;
}
.sawp-preview {
    padding: 12px;
    background: var(--sawp-gray-50);
    border: 1px solid var(--sawp-border-color);
    border-radius: var(--sawp-border-radius);
    font-size: 13px;
    line-height: 1.5;
}
/* Test conexiune */
.sawp-test-error {
    color: var(--sawp-error);
    font-weight: 500;
}
.sawp-test-success {
    color: var(--sawp-success);
    font-weight: 600;
}
/* Last update */
.sawp-last-update {
    font-size: 12px;
    color: var(--sawp-gray-500);
    margin-bottom: 16px;
    text-align: right;
}
/* Header actions */
.sawp-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
/* Form settings */
.sawp-settings-form .form-table th {
    padding: 16px 16px 16px 0;
    width: 200px;
    vertical-align: top;
}
.sawp-settings-form .form-table td {
    padding: 16px 0;
}
/* Buton reîmprospătare mic */
.sawp-btn-sm .dashicons {
    font-size: 16px;
    margin: 0;
}
/* Animatie spin */
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
/* Responsive adjustments */
@media screen and (max-width: 782px) {
    .sawp-dashboard {
        grid-template-columns: 1fr;
    }
    
    .sawp-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .sawp-actions {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .sawp-sms-details {
        grid-template-columns: 1fr;
    }
}
CSS;
    
    wp_add_inline_style('sawp-admin-styles', $css);
}
/* ================== TRIMITERE SMS ================== */
function sawp_send_sms($order_id, $old_status, $new_status, $order) {
    $opts = get_option('sawp_opts', []);
    $token = trim($opts['token'] ?? '');
    
    // Verifică dacă este statusul "payment-pending"
    if ($new_status === 'payment-pending') {
        $en = !empty($opts["enable_payment-pending"]);
        $tpl = intval($opts["tpl_payment-pending"] ?? 0);
    } else {
        // Pentru toate celelalte statusuri
        $tpl = intval($opts["tpl_{$new_status}"] ?? 0);
        $en = !empty($opts["enable_{$new_status}"]);
    }
    
    if (!$token || !$tpl || !$en) { 
        return; 
    }
    
    $phone = preg_replace('/\D+/', '', $order->get_billing_phone());
    if (!$phone) { 
        return; 
    }
    
    // Get template name
    $template_name = 'Necunoscut';
    $tpls = get_transient('sawp_tpl_list');
    if (is_array($tpls)) {
        foreach ($tpls as $t) { 
            if (isset($t['id']) && (int)$t['id'] === $tpl) { 
                $template_name = $t['name'] ?? 'Necunoscut'; 
                break; 
            } 
        }
    }
    
    $payload = [
        'number' => $phone,
        'template_id' => $tpl,
        'variables' => [
            'order_id' => $order->get_order_number(),
            'total'    => $order->get_total(),
            'awb'      => $order->get_meta('awb_cargus') ?: $order->get_meta('_tracking_number'),
            'nume'     => $order->get_billing_first_name(),
        ],
    ];
    
    $resp = wp_remote_post(
        'https://api.notice.ro/api/v1/sms-out',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]
    );
    
    $status_code = 0;
    $body = '';
    if (is_wp_error($resp)) {
        $status_code = 0;
        $body = $resp->get_error_message();
    } else {
        $status_code = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
    }
    
    // Asigură existența tabelei înainte de log
    sawp_maybe_create_table();
    global $wpdb, $SAWP_LOG_TABLE;
    $wpdb->insert(
        $SAWP_LOG_TABLE,
        [
            'date_sent'     => current_time('mysql'),
            'order_id'      => $order->get_order_number(),
            'phone'         => $phone,
            'template_id'   => $tpl,
            'template_name' => $template_name,
            'status_code'   => $status_code,
            'response'      => $body,
        ],
        ['%s', '%s', '%s', '%d', '%s', '%d', '%s']
    );
}
/* ================== CONTACT ================== */
function sawp_handle_contact() {
    if (!isset($_POST['sms_contact_nonce']) || !wp_verify_nonce($_POST['sms_contact_nonce'],'sms_contact')) { wp_die('Nonce invalid'); }
    $n = sanitize_text_field($_POST['sms_name'] ?? '');
    $e = sanitize_email($_POST['sms_email'] ?? '');
    $m = sanitize_textarea_field($_POST['sms_message'] ?? '');
    wp_mail('support@notice.ro','Contact plugin '.$n,$m,['From: '.$n.' <'.$e.'>','Content-Type: text/plain; charset=UTF-8']);
    wp_safe_redirect(add_query_arg('sms_contact','success', wp_get_referer() ?: admin_url())); exit;
}
/* ================== HELPERI EXTRAGERE DATE API (SMS IN) ================== */
function sawp_first_non_empty(array $arr, array $keys) { 
    foreach ($keys as $k) { 
        if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null) { 
            return $arr[$k]; 
        } 
    } 
    return ''; 
}
function sawp_extract_datetime_display(array $sms) {
    // Prioritize documented fields first
    $candidates = ['received_at', 'createdAt', 'created_at', 'timestamp', 'created', 'date'];
    $raw = sawp_first_non_empty($sms, $candidates);
    
    // Handle nested attributes if needed
    if (!$raw && isset($sms['attributes']['received_at'])) { 
        $raw = $sms['attributes']['received_at']; 
    }
    
    $ts = $raw ? strtotime($raw) : false;
    return $ts ? date('d M Y H:i', $ts) : '';
}
function sawp_extract_phone_raw(array $sms) {
    // Prioritize documented fields
    $candidates = ['from', 'sender', 'phone', 'number', 'msisdn', 'src', 'originator', 'mobile', 'phonenumber'];
    $raw = sawp_first_non_empty($sms, $candidates);
    
    // Handle nested contact if needed
    if (!$raw && isset($sms['contact']['phone'])) { 
        $raw = $sms['contact']['phone']; 
    }
    
    return is_string($raw) ? $raw : (is_numeric($raw) ? (string)$raw : '');
}
function sawp_extract_message(array $sms) {
    // Prioritize documented fields
    $candidates = ['message', 'body', 'text', 'content', 'msg', 'sms'];
    $raw = sawp_first_non_empty($sms, $candidates);
    
    // Handle nested payload if needed
    if (!$raw && isset($sms['payload']['message'])) { 
        $raw = $sms['payload']['message']; 
    }
    
    return is_string($raw) ? $raw : '';
}
/* ================== GĂSIRE COMANDĂ DUPĂ TELEFON ================== */
function sawp_find_order_by_phone($phone) {
    global $wpdb;
    $clean = preg_replace('/\D+/', '', (string)$phone);
    if ($clean === '') { return 0; }
    
    // Try different phone number formats
    $patterns = [
        '%' . $clean,                    // Exact match
        '%40' . substr($clean, 1),       // International format (407xxxx)
        '%' . substr($clean, 1)          // Without country code (7xxxx)
    ];
    
    foreach ($patterns as $pattern) {
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_billing_phone' AND meta_value LIKE %s
             ORDER BY post_id DESC LIMIT 1",
            $pattern
        ));
        
        if ($order_id) {
            return intval($order_id);
        }
    }
    
    return 0;
}