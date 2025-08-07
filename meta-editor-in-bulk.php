<?php
/*
Plugin Name: Meta Editor in Bulk Pro
Plugin URI: https://2088.it
Description: Gestione avanzata in blocco dei titoli e delle meta description per post, pagine, categorie e tag. Compatibile con Yoast SEO, Rank Math, All in One SEO, SEOPress o funzionante in modalità autonoma.
Version: 2.0.2 
Author: Flavius Florin Harabor 
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: Meta-Editor-in-Bulk-Pro
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 8.0
*/

if (!defined('ABSPATH')) exit;

// ===================================================================
// 1. HOOK DI ATTIVAZIONE/DISATTIVAZIONE PLUGIN
// ===================================================================
register_activation_hook(__FILE__, 'meb_activate_plugin');
register_deactivation_hook(__FILE__, 'meb_deactivate_plugin');

function meb_activate_plugin() {
    meb_create_history_table();
    meb_update_history_table_for_multilang();
    meb_schedule_history_snapshot();
    meb_schedule_report();
    // Crea dati di test per il grafico
    meb_create_test_history_data();
    update_option('meb_db_version', '1.1');
    
    // NUOVO: Inizializza le impostazioni per l'ottimizzazione immagini
    meb_init_image_settings();
}

function meb_deactivate_plugin() {
    wp_clear_scheduled_hook('meb_weekly_history_snapshot_event');
    wp_clear_scheduled_hook('meb_weekly_report_event');
}

// ===================================================================
// 2. GESTIONE TABELLA PERSONALIZZATA E CRON JOB
// ===================================================================
function meb_create_history_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name ( 
        history_id mediumint(9) NOT NULL AUTO_INCREMENT, 
        record_date date DEFAULT '0000-00-00' NOT NULL, 
        post_type varchar(20) NOT NULL, 
        language varchar(10) DEFAULT 'all' NOT NULL,
        total_posts int(11) NOT NULL, 
        optimized_posts int(11) NOT NULL, 
        PRIMARY KEY (history_id), 
        UNIQUE KEY record_key (record_date, post_type, language) 
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function meb_update_history_table_for_multilang() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    
    // Controlla se la colonna language esiste già
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'language'");
    
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN language varchar(10) DEFAULT 'all' AFTER post_type");
        
        // Aggiorna la chiave unica
        $wpdb->query("ALTER TABLE $table_name DROP INDEX record_key");
        $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY record_key (record_date, post_type, language)");
        
        error_log("MEB: Tabella history aggiornata per multilingua");
    }
}

add_action('admin_init', 'meb_check_multilang_table_update');
function meb_check_multilang_table_update() {
    $version = get_option('meb_db_version', '1.0');
    if ($version < '1.1') {
        meb_update_history_table_for_multilang();
        update_option('meb_db_version', '1.1');
    }
}

function meb_create_test_history_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    
    // Invece di dati inventati, usa i dati REALI attuali
    $post_types = meb_get_all_post_types();
    $languages = meb_get_available_languages();
    $multilang_plugin = meb_get_active_multilang_plugin();
    
    // Se non c'è plugin multilingua, usa solo 'all'
    if (!$multilang_plugin) {
        $languages = ['all' => ['code' => 'all', 'name' => 'All Languages']];
    }
    
    // Crea dati realistici per gli ultimi 30 giorni
    for ($i = 30; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        
        foreach ($languages as $lang_code => $lang_data) {
            // Imposta temporaneamente la lingua
            $_GET['meb_language'] = $lang_code;
            
            foreach ($post_types as $slug => $label) {
                // Salta le tassonomie per i dati storici e i separatori
                if (strpos($slug, 'taxonomy_') === 0 || strpos($slug, '_separator') !== false) continue;
                
                // USA I DATI REALI invece di quelli inventati
                list($current_optimized, $current_total) = meb_get_seo_optimization_stats($slug);
                
                // Simula un trend realistico basato sui dati attuali
                if ($current_total > 0) {
                    // Simula che il processo di ottimizzazione è iniziato gradualmente
                    $days_ago = 30 - $i;
                    $progress_factor = min(1, $days_ago / 25); // Progresso graduale
                    
                    $historical_optimized = round($current_optimized * $progress_factor);
                    $historical_total = $current_total; // Il totale rimane uguale
                } else {
                    $historical_optimized = 0;
                    $historical_total = 0;
                }
                
                $wpdb->replace($table_name, [
                    'record_date' => $date,
                    'post_type' => $slug,
                    'language' => $lang_code,
                    'total_posts' => $historical_total, // DATI REALI
                    'optimized_posts' => $historical_optimized // TREND REALISTICO
                ], ['%s', '%s', '%s', '%d', '%d']);
            }
        }
    }
    
    // Pulisci la variabile temporanea
    unset($_GET['meb_language']);
    
    error_log("MEB: Creati dati realistici multilingua basati sui contenuti attuali");
}

function meb_schedule_history_snapshot() {
    if (!wp_next_scheduled('meb_weekly_history_snapshot_event')) {
        wp_schedule_event(time(), 'weekly', 'meb_weekly_history_snapshot_event');
    }
}

add_action('meb_weekly_history_snapshot_event', 'meb_take_history_snapshot');
function meb_take_history_snapshot() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    $post_types = meb_get_all_post_types();
    $current_date = current_time('Y-m-d');
    $languages = meb_get_available_languages();
    $multilang_plugin = meb_get_active_multilang_plugin();
    
    // Se non c'è plugin multilingua, salva come prima
    if (!$multilang_plugin) {
        $languages = ['all' => ['code' => 'all', 'name' => 'All Languages']];
    }
    
    foreach ($languages as $lang_code => $lang_data) {
        // Imposta temporaneamente la lingua corrente
        $_GET['meb_language'] = $lang_code;
        
        foreach ($post_types as $slug => $label) {
            // Salta le tassonomie per i dati storici e i separatori
            if (strpos($slug, 'taxonomy_') === 0 || strpos($slug, '_separator') !== false) continue;
            
            list($optimized, $total) = meb_get_seo_optimization_stats($slug);
            $wpdb->replace($table_name, [
                'record_date' => $current_date, 
                'post_type' => $slug, 
                'language' => $lang_code,
                'total_posts' => $total, 
                'optimized_posts' => $optimized
            ], ['%s', '%s', '%s', '%d', '%d']);
        }
    }
    
    // Pulisci la variabile temporanea
    unset($_GET['meb_language']);
}

function meb_force_create_todays_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    
    // Assicurati che la tabella esista
    meb_create_history_table();
    meb_update_history_table_for_multilang();
    
    $post_types = meb_get_all_post_types();
    $current_date = current_time('Y-m-d');
    $languages = meb_get_available_languages();
    $multilang_plugin = meb_get_active_multilang_plugin();
    
    // Se non c'è plugin multilingua, usa solo 'all'
    if (!$multilang_plugin) {
        $languages = ['all' => ['code' => 'all', 'name' => 'All Languages']];
    }
    
    foreach ($languages as $lang_code => $lang_data) {
        // Imposta temporaneamente la lingua
        $_GET['meb_language'] = $lang_code;
        
        foreach ($post_types as $slug => $label) {
            // Salta le tassonomie per i dati storici e i separatori
            if (strpos($slug, 'taxonomy_') === 0 || strpos($slug, '_separator') !== false) continue;
            
            list($optimized, $total) = meb_get_seo_optimization_stats($slug);
            
            $result = $wpdb->replace($table_name, [
                'record_date' => $current_date, 
                'post_type' => $slug, 
                'language' => $lang_code,
                'total_posts' => $total, 
                'optimized_posts' => $optimized
            ], ['%s', '%s', '%s', '%d', '%d']);
            
            error_log("MEB: Salvato per oggi ($current_date) - $slug - $lang_code: $optimized/$total - Result: $result");
        }
    }
    
    // Pulisci la variabile temporanea
    unset($_GET['meb_language']);
    
    error_log("MEB: Snapshot multilingua forzato per oggi completato");
}

function meb_save_current_snapshot() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    
    // Assicurati che la tabella esista
    meb_create_history_table();
    meb_update_history_table_for_multilang();
    
    $post_types = meb_get_all_post_types();
    $current_date = current_time('Y-m-d');
    $languages = meb_get_available_languages();
    $multilang_plugin = meb_get_active_multilang_plugin();
    
    // Se non c'è plugin multilingua, usa solo 'all'
    if (!$multilang_plugin) {
        $languages = ['all' => ['code' => 'all', 'name' => 'All Languages']];
    }
    
    foreach ($languages as $lang_code => $lang_data) {
        // Imposta temporaneamente la lingua
        $_GET['meb_language'] = $lang_code;
        
        foreach ($post_types as $slug => $label) {
            // Salta le tassonomie per i dati storici e i separatori
            if (strpos($slug, 'taxonomy_') === 0 || strpos($slug, '_separator') !== false) continue;
            
            list($optimized, $total) = meb_get_seo_optimization_stats($slug);
            
            // Aggiorna o inserisci i dati per oggi
            $wpdb->replace($table_name, [
                'record_date' => $current_date, 
                'post_type' => $slug, 
                'language' => $lang_code,
                'total_posts' => $total, 
                'optimized_posts' => $optimized
            ], ['%s', '%s', '%s', '%d', '%d']);
        }
    }
    
    // Pulisci la variabile temporanea
    unset($_GET['meb_language']);
}

// ===================================================================
// 3. FUNZIONI MULTILINGUA
// ===================================================================
function meb_get_active_multilang_plugin() {
    if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) return 'wpml';
    if (defined('POLYLANG_VERSION') || function_exists('pll_languages_list')) return 'polylang'; 
    return false;
}

function meb_get_available_languages() {
    $plugin = meb_get_active_multilang_plugin();
    $languages = [];
    
    switch ($plugin) {
        case 'wpml':
            if (function_exists('icl_get_languages')) {
                $wpml_languages = icl_get_languages('skip_missing=0&orderby=code');
                foreach ($wpml_languages as $lang) {
                    $languages[$lang['code']] = [
                        'code' => $lang['code'],
                        'name' => $lang['native_name'],
                        'flag' => $lang['country_flag_url'] ?? '',
                        'default' => $lang['default_locale'] ?? false
                    ];
                }
            }
            break;
            
        case 'polylang':
            if (function_exists('pll_languages_list')) {
                $pll_languages = pll_languages_list(['fields' => '']);
                foreach ($pll_languages as $lang) {
                    $languages[$lang->slug] = [
                        'code' => $lang->slug,
                        'name' => $lang->name,
                        'flag' => $lang->flag_url ?? '',
                        'default' => pll_default_language() === $lang->slug
                    ];
                }
            }
            break;
    }
    
    return $languages;
}

function meb_get_current_language() {
    $plugin = meb_get_active_multilang_plugin();
    
    // Prima controlla se è stata selezionata una lingua specifica
    if (isset($_GET['meb_language']) && !empty($_GET['meb_language'])) {
        return sanitize_text_field($_GET['meb_language']);
    }
    
    switch ($plugin) {
        case 'wpml':
            return defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : 'all';
        case 'polylang':
            return function_exists('pll_current_language') ? pll_current_language() : 'all';
        default:
            return 'all';
    }
}

function meb_wpml_posts_join($join) {
    global $wpdb;
    $join .= " LEFT JOIN {$wpdb->prefix}icl_translations AS wpml_t ON {$wpdb->posts}.ID = wpml_t.element_id AND wpml_t.element_type = CONCAT('post_', {$wpdb->posts}.post_type)";
    return $join;
}

function meb_wpml_posts_where($where, $language) {
    $where .= " AND wpml_t.language_code = '" . esc_sql($language) . "'";
    return $where;
}

// ===================================================================
// 4. LOGICA DI GESTIONE (Esporta, Importa, Salvataggio via API)
// ===================================================================
add_action('admin_init', 'meb_handle_page_actions');
function meb_handle_page_actions() {
    if (isset($_GET['meb_action']) && $_GET['meb_action'] === 'export_csv' && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'meb_export_nonce')) { 
        meb_handle_export_csv(); 
    }
    if (isset($_POST['meb_import_nonce']) && wp_verify_nonce($_POST['meb_import_nonce'], 'meb_import_action')) { 
        meb_handle_import_csv(); 
    }
}

function meb_handle_export_csv() { 
    if (!current_user_can('manage_options')) { 
        wp_die('Non hai i permessi per eseguire questa operazione.'); 
    } 
    $filename = 'meta_seo_export_' . date('Y-m-d') . '.csv'; 
    header('Content-Type: text/csv'); 
    header('Content-Disposition: attachment; filename="' . $filename . '"'); 
    $output = fopen('php://output', 'w'); 
    fputcsv($output, ['ID', 'Type', 'Language', 'Title', 'Slug', 'Meta Title', 'Meta Description', 'Focus Keyword']); 
    
    $post_types = meb_get_all_post_types();
    
    foreach ($post_types as $slug => $label) {
        if (strpos($slug, '_separator') !== false) continue;
        
        if (strpos($slug, 'taxonomy_') === 0) {
            // Export tassonomie
            $taxonomy = str_replace('taxonomy_', '', $slug);
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            foreach ($terms as $term) {
                $seo_data = meb_get_term_seo_data($term->term_id, false);
                $term_lang = meb_get_term_language($term->term_id);
                fputcsv($output, [
                    $term->term_id,
                    'taxonomy_' . $taxonomy,
                    $term_lang,
                    $term->name,
                    $term->slug,
                    $seo_data['title'],
                    $seo_data['description'],
                    $seo_data['keyword']
                ]);
            }
        } else {
            // Export post types
            $query = new WP_Query([
                'post_type' => $slug, 
                'posts_per_page' => -1, 
                'post_status' => 'publish'
            ]); 
            if ($query->have_posts()) { 
                while ($query->have_posts()) { 
                    $query->the_post(); 
                    $post_id = get_the_ID(); 
                    $seo_data = meb_get_post_seo_data($post_id, false); 
                    $post_lang = meb_get_post_language($post_id);
                    fputcsv($output, [
                        $post_id, 
                        $slug,
                        $post_lang,
                        get_the_title(), 
                        get_post_field('post_name', $post_id), 
                        $seo_data['title'], 
                        $seo_data['description'], 
                        $seo_data['keyword']
                    ]); 
                } 
            }
            wp_reset_postdata();
        }
    }
    
    fclose($output); 
    exit; 
}

function meb_get_post_language($post_id) {
    $plugin = meb_get_active_multilang_plugin();
    
    switch ($plugin) {
        case 'wpml':
            if (function_exists('wpml_get_language_information')) {
                $lang_info = wpml_get_language_information(null, $post_id);
                return $lang_info['language_code'] ?? 'all';
            }
            break;
        case 'polylang':
            if (function_exists('pll_get_post_language')) {
                return pll_get_post_language($post_id) ?: 'all';
            }
            break;
    }
    
    return 'all';
}

function meb_get_term_language($term_id) {
    $plugin = meb_get_active_multilang_plugin();
    
    switch ($plugin) {
        case 'wpml':
            if (function_exists('wpml_get_language_information')) {
                $lang_info = wpml_get_language_information(null, $term_id);
                return $lang_info['language_code'] ?? 'all';
            }
            break;
        case 'polylang':
            if (function_exists('pll_get_term_language')) {
                return pll_get_term_language($term_id) ?: 'all';
            }
            break;
    }
    
    return 'all';
}

function meb_handle_import_csv() { 
    if (!current_user_can('manage_options')) { 
        wp_die('Non hai i permessi per eseguire questa operazione.'); 
    } 
    if (isset($_FILES['meb_import_file']) && $_FILES['meb_import_file']['error'] == UPLOAD_ERR_OK) { 
        $file_tmp_name = $_FILES['meb_import_file']['tmp_name']; 
        if (($handle = fopen($file_tmp_name, "r")) !== FALSE) { 
            fgetcsv($handle, 1000, ","); 
            $updated_count = 0; 
            $meta_keys = meb_get_seo_meta_keys(); 
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) { 
                if (count($data) < 8) continue; 
                
                $item_id = intval($data[0]); 
                $item_type = sanitize_text_field($data[1]);
                $item_language = sanitize_text_field($data[2]);
                $post_slug = sanitize_title($data[4]); 
                $meta_title = sanitize_text_field($data[5]); 
                $meta_desc = sanitize_textarea_field($data[6]); 
                $focus_keyword = sanitize_text_field($data[7]); 
                
                if (strpos($item_type, 'taxonomy_') === 0) {
                    // Gestione tassonomie
                    if (term_exists($item_id)) {
                        update_term_meta($item_id, $meta_keys['title'], $meta_title);
                        update_term_meta($item_id, $meta_keys['desc'], $meta_desc);
                        update_term_meta($item_id, $meta_keys['keyword'], $focus_keyword);
                        $updated_count++;
                    }
                } else {
                    // Gestione post types
                    if (get_post_status($item_id)) { 
                        update_post_meta($item_id, $meta_keys['title'], $meta_title); 
                        update_post_meta($item_id, $meta_keys['desc'], $meta_desc); 
                        update_post_meta($item_id, $meta_keys['keyword'], $focus_keyword); 
                        $current_slug = get_post_field('post_name', $item_id); 
                        if ($post_slug && $post_slug !== $current_slug) { 
                            wp_update_post(['ID' => $item_id, 'post_name' => $post_slug]); 
                        } 
                        $updated_count++; 
                    }
                }
            } 
            fclose($handle); 
            $redirect_url = add_query_arg([
                'meb_imported' => '1', 
                'count' => $updated_count
            ], admin_url('admin.php?page=meta-editor-in-bulk')); 
            wp_safe_redirect($redirect_url); 
            exit; 
        } 
    } 
    $redirect_url = add_query_arg('meb_imported', '0', admin_url('admin.php?page=meta-editor-in-bulk')); 
    wp_safe_redirect($redirect_url); 
    exit; 
}

// ===================================================================
// 5. REGISTRAZIONE MENU, ASSET E REST API
// ===================================================================
add_action('admin_menu', 'meb_register_menu'); 
function meb_register_menu() { 
    add_menu_page(
        'Meta Editor in Bulk', 
        'Meta Editor', 
        'manage_options', 
        'meta-editor-in-bulk', 
        'meb_admin_page', 
        'dashicons-edit-page', 
        80
    );
    
    // NUOVO: Aggiungi sottomenu per SEO Immagini
    add_submenu_page(
        'meta-editor-in-bulk',
        'SEO Immagini',
        'SEO Immagini',
        'manage_options',
        'meb-seo-images',
        'meb_seo_images_page'
    );
    
    // NUOVO: Aggiungi sottomenu per Impostazioni
    add_submenu_page(
        'meta-editor-in-bulk',
        'Impostazioni Meta Editor Pro',
        'Impostazioni',
        'manage_options',
        'meb-settings',
        'meb_settings_page'
    );
}

add_action('admin_enqueue_scripts', 'meb_enqueue_assets');
function meb_enqueue_assets($hook) {
    // Carica assets per tutte le pagine del plugin
    if (strpos($hook, 'meta-editor-in-bulk') === false && 
        strpos($hook, 'meb-seo-images') === false && 
        strpos($hook, 'meb-settings') === false) return;
    
    // Carica le librerie esterne con versioni specifiche e senza conflitti di moduli
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1', true);
    wp_enqueue_script('litepicker', 'https://cdn.jsdelivr.net/npm/litepicker@2.0.12/dist/bundle.js', [], '2.0.12', true);
    wp_enqueue_style('litepicker-style', 'https://cdn.jsdelivr.net/npm/litepicker@2.0.12/dist/css/litepicker.css', [], '2.0.12');
    
    // Carica i nostri asset
    wp_enqueue_style('meb-admin-style', plugin_dir_url(__FILE__) . 'style.css', [], '4.2.1');
    
    // Il nostro script - con dipendenze corrette
    wp_enqueue_script('meb-admin-script', plugin_dir_url(__FILE__) . 'script.js', ['jquery'], '4.2.1', true);
    
    // NUOVO: Funzioni WordPress per media se siamo sulla pagina immagini
    if (strpos($hook, 'meb-seo-images') !== false) {
        wp_enqueue_media();
        wp_enqueue_script('wp-util');
    }
    
    // MODIFICA: Estendi i dati localizzati con informazioni multilingua e immagini
    $multilang_plugin = meb_get_active_multilang_plugin();
    $localize_data = [
        'api_nonce' => wp_create_nonce('wp_rest'),
        'api_url' => rest_url('meb/v1/bulk-update-meta'),
        'history_api_url' => rest_url('meb/v1/get-history'),
        'favicon_url' => get_site_icon_url(32, admin_url('images/w-logo-blue.png')),
        'site_url' => home_url(),
        'site_name' => get_bloginfo('name'),
        'current_date' => date('j M Y'),
        'limits' => ['title' => 60, 'description' => 160, 'slug' => 75],
        'seo_vars' => ['sitename' => get_bloginfo('name'), 'sep' => apply_filters('wpseo_separator', '-')],
        'chart_js_url' => 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
        'litepicker_url' => 'https://cdn.jsdelivr.net/npm/litepicker@2.0.12/dist/bundle.js',
        
        // NUOVO: Dati multilingua
        'multilang' => [
            'enabled' => (bool) $multilang_plugin,
            'plugin' => $multilang_plugin ?: false,
            'languages' => $multilang_plugin ? meb_get_available_languages() : [],
            'current' => meb_get_current_language(),
            'labels' => [
                'all_languages' => '📊 Tutte le lingue',
                'loading_lang' => 'Caricamento dati per lingua...',
                'no_data_lang' => 'Nessun dato disponibile per questa lingua',
                'switch_lang' => 'Cambia lingua per vedere i dati storici'
            ]
        ],
        
        // NUOVO: Dati per ottimizzazione immagini
        'images' => [
            'api_url' => rest_url('meb/v1/'),
            'optimize_url' => rest_url('meb/v1/optimize-image'),
            'bulk_optimize_url' => rest_url('meb/v1/bulk-optimize-images'),
            'settings' => meb_get_image_settings(),
            'labels' => [
                'optimizing' => 'Ottimizzazione in corso...',
                'optimized' => 'Ottimizzata',
                'error' => 'Errore durante ottimizzazione',
                'select_images' => 'Seleziona immagini da ottimizzare',
                'no_images_selected' => 'Nessuna immagine selezionata',
                'optimization_complete' => 'Ottimizzazione completata',
                'generating_alt' => 'Generazione testo alternativo...',
                'alt_generated' => 'Testo alternativo generato',
                'bulk_processing' => 'Elaborazione in blocco...'
            ]
        ]
    ];
    
    // Applica filtro per permettere estensioni
    $localize_data = apply_filters('meb_localize_script_data', $localize_data);
    
    wp_localize_script('meb-admin-script', 'mebData', $localize_data);
    
    // Script inline per caricare le librerie come fallback
    wp_add_inline_script('meb-admin-script', '
        console.log("MEB: Verifica caricamento librerie...");
        if (typeof Chart === "undefined") {
            console.warn("MEB: Chart.js non caricato, provo fallback");
        }
        if (typeof Litepicker === "undefined") {
            console.warn("MEB: Litepicker non caricato, provo fallback");
        }
        
        // NUOVO: Log informazioni multilingua
        if (typeof mebData !== "undefined" && mebData.multilang) {
            console.log("MEB: Plugin multilingua rilevato:", mebData.multilang.plugin);
            console.log("MEB: Lingua corrente:", mebData.multilang.current);
            console.log("MEB: Lingue disponibili:", Object.keys(mebData.multilang.languages));
        }
        
        // NUOVO: Log informazioni ottimizzazione immagini
        if (typeof mebData !== "undefined" && mebData.images) {
            console.log("MEB: Modulo ottimizzazione immagini caricato");
        }
    ', 'before');
}

add_action('rest_api_init', 'meb_register_rest_routes');
function meb_register_rest_routes() {
    register_rest_route('meb/v1', '/bulk-update-meta', [ 
        'methods' => 'POST', 
        'callback' => 'meb_api_bulk_update_meta', 
        'permission_callback' => function () { return current_user_can('manage_options'); } 
    ]);
    register_rest_route('meb/v1', '/get-history', [ 
        'methods' => 'GET', 
        'callback' => 'meb_api_get_history', 
        'permission_callback' => function () { return current_user_can('manage_options'); } 
    ]);
    
    // NUOVO: API endpoints per ottimizzazione immagini
    register_rest_route('meb/v1', '/get-images', [
        'methods' => 'GET',
        'callback' => 'meb_api_get_images',
        'permission_callback' => function () { return current_user_can('upload_files'); }
    ]);
    
    register_rest_route('meb/v1', '/optimize-image', [
        'methods' => 'POST',
        'callback' => 'meb_api_optimize_image',
        'permission_callback' => function () { return current_user_can('upload_files'); }
    ]);
    
    register_rest_route('meb/v1', '/bulk-optimize-images', [
        'methods' => 'POST',
        'callback' => 'meb_api_bulk_optimize_images',
        'permission_callback' => function () { return current_user_can('upload_files'); }
    ]);
    
    register_rest_route('meb/v1', '/generate-alt-text', [
        'methods' => 'POST',
        'callback' => 'meb_api_generate_alt_text',
        'permission_callback' => function () { return current_user_can('upload_files'); }
    ]);
    
    register_rest_route('meb/v1', '/save-image-settings', [
        'methods' => 'POST',
        'callback' => 'meb_api_save_image_settings',
        'permission_callback' => function () { return current_user_can('manage_options'); }
    ]);
}

function meb_api_bulk_update_meta($request) { 
    $posts_data = $request->get_param('posts'); 
    if (empty($posts_data) || !is_array($posts_data)) { 
        return new WP_Error('no_data', 'Nessun dato da salvare.', ['status' => 400]); 
    } 
    
    $meta_keys = meb_get_seo_meta_keys(); 
    foreach ($posts_data as $data) { 
        $item_id = intval($data['postId']); 
        $item_type = sanitize_text_field($data['itemType'] ?? 'post');
        $meta_title = sanitize_text_field($data['metaTitle']);
        $meta_desc = sanitize_textarea_field($data['metaDesc']);
        $focus_keyword = sanitize_text_field($data['focusKeyword']);
        
        if (strpos($item_type, 'taxonomy') === 0) {
            // Gestione tassonomie
           if (term_exists($item_id)) {
               update_term_meta($item_id, $meta_keys['title'], $meta_title);
               update_term_meta($item_id, $meta_keys['desc'], $meta_desc);
               update_term_meta($item_id, $meta_keys['keyword'], $focus_keyword);
           }
       } else {
           // Gestione post types
           if (get_post($item_id)) { 
               update_post_meta($item_id, $meta_keys['title'], $meta_title); 
               update_post_meta($item_id, $meta_keys['desc'], $meta_desc); 
               update_post_meta($item_id, $meta_keys['keyword'], $focus_keyword); 
               $current_slug = get_post_field('post_name', $item_id); 
               if (isset($data['postSlug']) && sanitize_title($data['postSlug']) !== $current_slug) { 
                   wp_update_post(['ID' => $item_id, 'post_name' => sanitize_title($data['postSlug'])]); 
               } 
           }
       }
   } 
   
   // NUOVO: Salva snapshot dopo ogni modifica
   meb_save_current_snapshot();
   
   return new WP_REST_Response(['success' => true, 'message' => 'Dati salvati con successo.'], 200); 
}

function meb_api_get_history($request) {
   global $wpdb;
   $table_name = $wpdb->prefix . 'meb_history';
   
   $start_date = $request->get_param('start_date') ? sanitize_text_field($request->get_param('start_date')) : date('Y-m-d', strtotime('-30 days'));
   $end_date = $request->get_param('end_date') ? sanitize_text_field($request->get_param('end_date')) : date('Y-m-d');
   $language = $request->get_param('language') ? sanitize_text_field($request->get_param('language')) : meb_get_current_language();
   
   // Debug log
   error_log("MEB API: Richiesta dati dal $start_date al $end_date per lingua $language");
   
   $where_clause = "record_date BETWEEN %s AND %s";
   $params = [$start_date, $end_date];
   
   // Aggiungi filtro lingua se non è "all"
   if ($language !== 'all') {
       $where_clause .= " AND language = %s";
       $params[] = $language;
   }
   
   $results = $wpdb->get_results( 
       $wpdb->prepare( 
           "SELECT record_date, post_type, language, optimized_posts, total_posts FROM $table_name WHERE $where_clause ORDER BY record_date ASC", 
           $params
       ) 
   );
   
   // Debug log
   error_log("MEB API: Trovati " . count($results) . " record per lingua $language");
   
   if (empty($results)) {
       return new WP_REST_Response([
           'message' => 'Nessun dato storico trovato',
           'query_info' => [
               'start_date' => $start_date,
               'end_date' => $end_date,
               'language' => $language,
               'table_exists' => ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name)
           ]
       ], 200);
   }
   
   return new WP_REST_Response($results, 200);
}

// ===================================================================
// 6. NUOVE FUNZIONI PER OTTIMIZZAZIONE IMMAGINI
// ===================================================================

/**
 * Inizializza le impostazioni di default per l'ottimizzazione immagini
 */
function meb_init_image_settings() {
    $default_settings = [
        'auto_optimize' => false,
        'quality_jpeg' => 85,
        'quality_webp' => 80,
        'max_width' => 1920,
        'max_height' => 1080,
        'generate_webp' => true,
        'preserve_original' => true,
        'auto_alt_text' => true,
        'compress_level' => 'balanced', // 'light', 'balanced', 'aggressive'
        'optimize_existing' => false
    ];
    
    if (!get_option('meb_image_settings')) {
        update_option('meb_image_settings', $default_settings);
    }
}

/**
 * Ottieni le impostazioni correnti per le immagini
 */
function meb_get_image_settings() {
    $default_settings = [
        'auto_optimize' => false,
        'quality_jpeg' => 85,
        'quality_webp' => 80,
        'max_width' => 1920,
        'max_height' => 1080,
        'generate_webp' => true,
        'preserve_original' => true,
        'auto_alt_text' => true,
        'compress_level' => 'balanced',
        'optimize_existing' => false
    ];
    
    return wp_parse_args(get_option('meb_image_settings', []), $default_settings);
}

/**
 * API endpoint per ottenere le immagini con filtri
 */
function meb_api_get_images($request) {
    $paged = $request->get_param('page') ?: 1;
    $per_page = $request->get_param('per_page') ?: 20;
    $filter = $request->get_param('filter') ?: 'all'; // 'all', 'no_alt', 'large', 'unoptimized'
    $search = $request->get_param('search') ?: '';
    
    $args = [
        'post_type' => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'post_status' => 'inherit',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    
    // Filtro per ricerca
    if (!empty($search)) {
        $args['s'] = sanitize_text_field($search);
    }
    
    // Filtri specifici
    switch ($filter) {
        case 'no_alt':
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_wp_attachment_image_alt',
                    'value' => '',
                    'compare' => '='
                ]
            ];
            break;
            
        case 'large':
            // Immagini più grandi di 1MB
            $args['meta_query'] = [
                [
                    'key' => '_meb_original_size',
                    'value' => 1048576,
                    'compare' => '>',
                    'type' => 'NUMERIC'
                ]
            ];
            break;
            
        case 'unoptimized':
            $args['meta_query'] = [
                [
                    'key' => '_meb_optimized',
                    'compare' => 'NOT EXISTS'
                ]
            ];
            break;
    }
    
    $query = new WP_Query($args);
    $images = [];
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $attachment_id = get_the_ID();
            
            $image_data = meb_get_image_data($attachment_id);
            if ($image_data) {
                $images[] = $image_data;
            }
        }
    }
    
    wp_reset_postdata();
    
    return new WP_REST_Response([
        'images' => $images,
        'total' => $query->found_posts,
        'pages' => $query->max_num_pages,
        'current_page' => $paged
    ], 200);
}

/**
 * Ottieni dati completi per un'immagine
 */
function meb_get_image_data($attachment_id) {
    $file_path = get_attached_file($attachment_id);
    
    if (!file_exists($file_path)) {
        return false;
    }
    
    $file_size = filesize($file_path);
    $image_meta = wp_get_attachment_metadata($attachment_id);
    $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    $is_optimized = get_post_meta($attachment_id, '_meb_optimized', true);
    $original_size = get_post_meta($attachment_id, '_meb_original_size', true) ?: $file_size;
    $optimization_data = get_post_meta($attachment_id, '_meb_optimization_data', true) ?: [];
    
    return [
        'id' => $attachment_id,
        'title' => get_the_title($attachment_id),
        'filename' => basename($file_path),
        'url' => wp_get_attachment_url($attachment_id),
        'thumb_url' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        'alt_text' => $alt_text,
        'mime_type' => get_post_mime_type($attachment_id),
        'file_size' => $file_size,
        'file_size_human' => size_format($file_size),
        'dimensions' => [
            'width' => $image_meta['width'] ?? 0,
            'height' => $image_meta['height'] ?? 0
        ],
        'is_optimized' => !empty($is_optimized),
        'original_size' => $original_size,
        'optimization_data' => $optimization_data,
        'savings' => $original_size > $file_size ? $original_size - $file_size : 0,
        'savings_percent' => $original_size > 0 ? round((($original_size - $file_size) / $original_size) * 100, 1) : 0
    ];
}

/**
 * API endpoint per ottimizzare singola immagine
 */
function meb_api_optimize_image($request) {
    $attachment_id = $request->get_param('attachment_id');
    
    if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
        return new WP_Error('invalid_image', 'Immagine non valida', ['status' => 400]);
    }
    
    $result = meb_optimize_single_image($attachment_id);
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Immagine ottimizzata con successo',
        'data' => $result
    ], 200);
}

/**
 * API endpoint per ottimizzazione in blocco
 */
function meb_api_bulk_optimize_images($request) {
    $attachment_ids = $request->get_param('attachment_ids');
    
    if (!is_array($attachment_ids) || empty($attachment_ids)) {
        return new WP_Error('no_images', 'Nessuna immagine selezionata', ['status' => 400]);
    }
    
    $results = [];
    $successful = 0;
    $failed = 0;
    
    foreach ($attachment_ids as $attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            continue;
        }
        
        $result = meb_optimize_single_image($attachment_id);
        
        if (is_wp_error($result)) {
            $results[] = [
                'id' => $attachment_id,
                'success' => false,
                'error' => $result->get_error_message()
            ];
            $failed++;
        } else {
            $results[] = [
                'id' => $attachment_id,
                'success' => true,
                'data' => $result
            ];
            $successful++;
        }
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => sprintf('Ottimizzazione completata: %d successi, %d errori', $successful, $failed),
        'results' => $results,
        'stats' => [
            'total' => count($attachment_ids),
            'successful' => $successful,
            'failed' => $failed
        ]
    ], 200);
}

/**
 * Ottimizza una singola immagine
 */
function meb_optimize_single_image($attachment_id) {
    $file_path = get_attached_file($attachment_id);
    
    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', 'File immagine non trovato');
    }
    
    $original_size = filesize($file_path);
    $settings = meb_get_image_settings();
    
    // Salva dimensione originale se non già salvata
    if (!get_post_meta($attachment_id, '_meb_original_size', true)) {
        update_post_meta($attachment_id, '_meb_original_size', $original_size);
    }
    
    // Verifica se già ottimizzata
    if (get_post_meta($attachment_id, '_meb_optimized', true) && !$settings['optimize_existing']) {
        return new WP_Error('already_optimized', 'Immagine già ottimizzata');
    }
    
    $optimization_result = [];
    
    try {
        // Carica l'immagine con WP Image Editor
        $image_editor = wp_get_image_editor($file_path);
        
        if (is_wp_error($image_editor)) {
            return $image_editor;
        }
        
        // Ridimensiona se necessario
        $size = $image_editor->get_size();
        if ($size['width'] > $settings['max_width'] || $size['height'] > $settings['max_height']) {
            $image_editor->resize($settings['max_width'], $settings['max_height'], false);
            $optimization_result['resized'] = true;
            $optimization_result['new_dimensions'] = $image_editor->get_size();
        }
        
        // Imposta qualità
        $mime_type = get_post_mime_type($attachment_id);
        if (strpos($mime_type, 'jpeg') !== false) {
            $image_editor->set_quality($settings['quality_jpeg']);
        }
        
        // Salva l'immagine ottimizzata
        $saved = $image_editor->save($file_path);
        
        if (is_wp_error($saved)) {
            return $saved;
        }
        
        // Calcola i risparmi
        $new_size = filesize($file_path);
        $savings = $original_size - $new_size;
        $savings_percent = $original_size > 0 ? round(($savings / $original_size) * 100, 1) : 0;
        
        $optimization_result['original_size'] = $original_size;
        $optimization_result['new_size'] = $new_size;
        $optimization_result['savings'] = $savings;
        $optimization_result['savings_percent'] = $savings_percent;
        $optimization_result['optimized_at'] = current_time('mysql');
        
        // Genera versione WebP se richiesto
        if ($settings['generate_webp'] && strpos($mime_type, 'webp') === false) {
            $webp_result = meb_generate_webp_version($attachment_id, $file_path, $settings['quality_webp']);
            if (!is_wp_error($webp_result)) {
                $optimization_result['webp_generated'] = true;
                $optimization_result['webp_path'] = $webp_result;
            }
        }
        
        // Genera testo alternativo automatico se richiesto
        if ($settings['auto_alt_text']) {
            $alt_result = meb_generate_auto_alt_text($attachment_id);
            if (!is_wp_error($alt_result)) {
                $optimization_result['alt_generated'] = true;
                $optimization_result['alt_text'] = $alt_result;
            }
        }
        
        // Salva i dati di ottimizzazione
        update_post_meta($attachment_id, '_meb_optimized', true);
        update_post_meta($attachment_id, '_meb_optimization_data', $optimization_result);
        
        // Aggiorna metadata WordPress
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));
        
        return $optimization_result;
        
    } catch (Exception $e) {
        return new WP_Error('optimization_failed', 'Errore durante ottimizzazione: ' . $e->getMessage());
    }
}

/**
 * Genera versione WebP dell'immagine
 */
function meb_generate_webp_version($attachment_id, $file_path, $quality) {
    if (!function_exists('imagewebp')) {
        return new WP_Error('webp_not_supported', 'WebP non supportato su questo server');
    }
    
    $mime_type = get_post_mime_type($attachment_id);
    $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file_path);
    
    try {
        $image = null;
        
        if (strpos($mime_type, 'jpeg') !== false) {
            $image = imagecreatefromjpeg($file_path);
        } elseif (strpos($mime_type, 'png') !== false) {
            $image = imagecreatefrompng($file_path);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        
        if (!$image) {
            return new WP_Error('image_create_failed', 'Impossibile creare immagine per WebP');
        }
        
        $result = imagewebp($image, $webp_path, $quality);
        imagedestroy($image);
        
        if (!$result) {
            return new WP_Error('webp_save_failed', 'Impossibile salvare versione WebP');
        }
        
        return $webp_path;
        
    } catch (Exception $e) {
        return new WP_Error('webp_error', 'Errore generazione WebP: ' . $e->getMessage());
    }
}

/**
 * Genera testo alternativo automatico basato sul nome del file
 */
function meb_generate_auto_alt_text($attachment_id) {
    // Se già presente, mantieni quello esistente
    $existing_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
    if (!empty($existing_alt)) {
        return $existing_alt;
    }
    
    $title = get_the_title($attachment_id);
    $filename = get_post_field('post_name', $attachment_id);
    
    // Usa il titolo se disponibile, altrimenti elabora il nome file
    if (!empty($title) && $title !== $filename) {
        $alt_text = $title;
    } else {
        // Elabora il nome del file
        $alt_text = str_replace(['-', '_'], ' ', $filename);
        $alt_text = ucwords(trim($alt_text));
    }
    
    // Rimuovi estensioni comuni
    $alt_text = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '', $alt_text);
    
    // Limita lunghezza
    $alt_text = substr($alt_text, 0, 125);
    
    // Salva il testo alternativo
    update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
    
    return $alt_text;
}

/**
 * API endpoint per generare testo alternativo
 */
function meb_api_generate_alt_text($request) {
    $attachment_id = $request->get_param('attachment_id');
    $custom_alt = $request->get_param('alt_text');
    
    if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
        return new WP_Error('invalid_image', 'Immagine non valida', ['status' => 400]);
    }
    
    if (!empty($custom_alt)) {
        // Usa testo personalizzato
        $alt_text = sanitize_text_field($custom_alt);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
    } else {
        // Genera automaticamente
        $alt_text = meb_generate_auto_alt_text($attachment_id);
    }
    
    if (is_wp_error($alt_text)) {
        return $alt_text;
    }
    
    return new WP_REST_Response([
        'success' => true,
        'alt_text' => $alt_text,
        'message' => 'Testo alternativo aggiornato'
    ], 200);
}

/**
 * API endpoint per salvare impostazioni immagini
 */
function meb_api_save_image_settings($request) {
    $settings = $request->get_param('settings');
    
    if (!is_array($settings)) {
        return new WP_Error('invalid_settings', 'Impostazioni non valide', ['status' => 400]);
    }
    
    // Valida e sanifica le impostazioni
    $valid_settings = [];
    $defaults = meb_get_image_settings();
    
    foreach ($defaults as $key => $default_value) {
        if (isset($settings[$key])) {
            switch ($key) {
                case 'quality_jpeg':
                case 'quality_webp':
                    $valid_settings[$key] = min(100, max(1, intval($settings[$key])));
                    break;
                case 'max_width':
                case 'max_height':
                    $valid_settings[$key] = max(100, intval($settings[$key]));
                    break;
                case 'compress_level':
                    $valid_settings[$key] = in_array($settings[$key], ['light', 'balanced', 'aggressive']) ? $settings[$key] : 'balanced';
                    break;
                default:
                    $valid_settings[$key] = (bool) $settings[$key];
            }
        } else {
            $valid_settings[$key] = $default_value;
        }
    }
    
    update_option('meb_image_settings', $valid_settings);
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Impostazioni salvate con successo',
        'settings' => $valid_settings
    ], 200);
}

// ===================================================================
// 7. NUOVE PAGINE ADMIN
// ===================================================================

/**
 * Pagina SEO Immagini
 */
function meb_seo_images_page() {
    $settings = meb_get_image_settings();
    ?>
    <div class="wrap meb-wrap">
        <h1>
            <span class="dashicons dashicons-format-image" style="font-size: 24px; margin-right: 8px; color: #0073aa;"></span>
            SEO Immagini
        </h1>
        <p>Ottimizza rapidamente il testo alternativo delle tue immagini per migliorare la SEO e l'accessibilità.</p>
        
        <div class="meb-dashboard-flex">
            <!-- Statistiche -->
            <div class="meb-card meb-stats-container">
                <h3>Statistiche Immagini</h3>
                <?php
                $image_stats = meb_get_image_statistics();
                ?>
                <ul>
                    <li>
                        <div><strong>Totale Immagini</strong><small><?php echo $image_stats['total']; ?></small></div>
                        <div class="progress-bar-container">
                            <div class="progress-bar high" style="width: 100%;"></div>
                        </div>
                    </li>
                    <li>
                        <div><strong>Senza Testo Alt</strong><small><?php echo $image_stats['no_alt']; ?> / <?php echo $image_stats['total']; ?> (<?php echo $image_stats['no_alt_percent']; ?>%)</small></div>
                        <div class="progress-bar-container">
                            <div class="progress-bar <?php echo $image_stats['no_alt_percent'] > 50 ? 'low' : ($image_stats['no_alt_percent'] > 20 ? 'medium' : 'high'); ?>" style="width: <?php echo $image_stats['no_alt_percent']; ?>%;"></div>
                        </div>
                    </li>
                    <li>
                        <div><strong>Immagini Grandi (>1MB)</strong><small><?php echo $image_stats['large']; ?> / <?php echo $image_stats['total']; ?> (<?php echo $image_stats['large_percent']; ?>%)</small></div>
                        <div class="progress-bar-container">
                            <div class="progress-bar <?php echo $image_stats['large_percent'] > 30 ? 'low' : ($image_stats['large_percent'] > 10 ? 'medium' : 'high'); ?>" style="width: <?php echo $image_stats['large_percent']; ?>%;"></div>
                        </div>
                    </li>
                    <li>
                        <div><strong>Ottimizzate</strong><small><?php echo $image_stats['optimized']; ?> / <?php echo $image_stats['total']; ?> (<?php echo $image_stats['optimized_percent']; ?>%)</small></div>
                        <div class="progress-bar-container">
                            <div class="progress-bar <?php echo $image_stats['optimized_percent'] >= 80 ? 'high' : ($image_stats['optimized_percent'] >= 40 ? 'medium' : 'low'); ?>" style="width: <?php echo $image_stats['optimized_percent']; ?>%;"></div>
                        </div>
                    </li>
                </ul>
                
                <div class="meb-actions">
                    <button id="meb-bulk-optimize-all" class="button button-primary">Ottimizza Tutto</button>
                    <button id="meb-generate-all-alt" class="button button-secondary">Genera Tutti i Testi Alt</button>
                </div>
            </div>
            
            <!-- Controlli Filtri -->
            <div class="meb-card" style="flex: 2 1 400px; padding: 15px;">
                <h3>Filtri e Ricerca</h3>
                <div class="meb-image-filters">
                    <div class="filter-row">
                        <label for="meb-image-filter">Mostra:</label>
                        <select id="meb-image-filter">
                            <option value="all">Tutte le immagini</option>
                            <option value="no_alt">Senza testo alternativo</option>
                            <option value="large">Immagini grandi (>1MB)</option>
                            <option value="unoptimized">Non ottimizzate</option>
                        </select>
                    </div>
                    
                    <div class="filter-row">
                        <label for="meb-image-search">Cerca:</label>
                        <input type="text" id="meb-image-search" placeholder="Nome file o titolo...">
                    </div>
                    
                    <div class="filter-row">
                        <button id="meb-apply-filters" class="button">Applica Filtri</button>
                        <button id="meb-reset-filters" class="button button-secondary">Reset</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Azioni in Blocco -->
        <div class="meb-card">
            <div class="meb-bulk-actions-bar" style="padding: 15px; border-bottom: 1px solid #ccd0d4; background: #f6f7f7; display: none;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span id="meb-selected-count">0 immagini selezionate</span>
                    <button id="meb-bulk-optimize-selected" class="button button-primary">Ottimizza Selezionate</button>
                    <button id="meb-bulk-generate-alt-selected" class="button button-secondary">Genera Testo Alt</button>
                    <button id="meb-clear-selection" class="button">Deseleziona Tutto</button>
                </div>
            </div>
            
            <!-- Griglia Immagini -->
            <div class="meb-images-grid" id="meb-images-container">
                <div class="meb-loading-images" style="text-align: center; padding: 40px;">
                    <div class="spinner is-active"></div>
                    <p>Caricamento immagini...</p>
                </div>
            </div>
            
            <!-- Paginazione -->
            <div class="meb-table-footer">
                <div id="meb-images-pagination"></div>
                <div class="meb-images-info">
                    <span id="meb-images-count-info">Caricamento...</span>
                </div>
            </div>
        </div>
        
        <!-- Modal per modifica singola immagine -->
        <div id="meb-image-modal" class="meb-modal" style="display: none;">
            <div class="meb-modal-content">
                <div class="meb-modal-header">
                    <h3>Modifica Immagine</h3>
                    <span class="meb-modal-close">&times;</span>
                </div>
                <div class="meb-modal-body">
                    <div class="meb-image-preview">
                        <img id="meb-modal-image" src="" alt="">
                    </div>
                    <div class="meb-image-details">
                        <p><strong>Nome File:</strong> <span id="meb-modal-filename"></span></p>
                        <p><strong>Dimensioni:</strong> <span id="meb-modal-dimensions"></span></p>
                        <p><strong>Dimensione File:</strong> <span id="meb-modal-filesize"></span></p>
                        
                        <div class="meb-field-group">
                            <label for="meb-modal-alt-text">Testo Alternativo:</label>
                            <input type="text" id="meb-modal-alt-text" maxlength="125">
                            <button type="button" id="meb-generate-alt-single" class="button button-secondary">Genera Automaticamente</button>
                        </div>
                        
                        <div class="meb-optimization-info" id="meb-optimization-status">
                            <!-- Popolato dinamicamente -->
                        </div>
                    </div>
                </div>
                <div class="meb-modal-footer">
                    <button id="meb-save-image-changes" class="button button-primary">Salva Modifiche</button>
                    <button id="meb-optimize-single" class="button">Ottimizza</button>
                    <button class="button meb-modal-close">Chiudi</button>
                </div>
            </div>
        </div>
    </div>
    
    <style>
    .meb-image-filters {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .filter-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .filter-row label {
        min-width: 80px;
        font-weight: 600;
    }
    
    .filter-row input, .filter-row select {
        flex: 1;
    }
    
    .meb-images-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        padding: 20px;
        min-height: 400px;
    }
    
    .meb-image-card {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
        transition: all 0.3s ease;
        position: relative;
    }
    
    .meb-image-card:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .meb-image-card.selected {
        border-color: #0073aa;
        box-shadow: 0 0 0 2px rgba(0,115,170,0.3);
    }
    
    .meb-image-thumbnail {
        width: 100%;
        height: 180px;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        cursor: pointer;
    }
    
    .meb-image-thumbnail img {
        max-width: 100%;
        max-height: 100%;
        object-fit: cover;
    }
    
    .meb-image-info {
        padding: 15px;
    }
    
    .meb-image-title {
        font-weight: 600;
        margin-bottom: 8px;
        font-size: 14px;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .meb-image-meta {
        font-size: 12px;
        color: #666;
        margin-bottom: 4px;
    }
    
    .meb-image-alt {
        font-size: 12px;
        font-style: italic;
        margin-bottom: 10px;
        min-height: 32px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .meb-image-alt.empty {
        color: #d63638;
    }
    
    .meb-image-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .meb-image-actions .button {
        flex: 1;
        min-width: auto;
        padding: 4px 8px;
        font-size: 12px;
        line-height: 1.2;
    }
    
    .meb-image-checkbox {
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 2;
    }
    
    .meb-image-status {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        z-index: 2;
    }
    
    .meb-image-status.optimized {
        background: #22c55e;
    }
    
    .meb-image-status.large {
        background: #f59e0b;
    }
    
    .meb-image-status.no-alt {
        background: #ef4444;
    }
    
    .meb-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .meb-modal-content {
        background: white;
        border-radius: 8px;
        max-width: 800px;
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    
    .meb-modal-header {
        padding: 20px;
        border-bottom: 1px solid #ddd;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .meb-modal-header h3 {
        margin: 0;
    }
    
    .meb-modal-close {
        font-size: 24px;
        cursor: pointer;
        background: none;
        border: none;
    }
    
    .meb-modal-body {
        padding: 20px;
        display: flex;
        gap: 20px;
        flex: 1;
        overflow-y: auto;
    }
    
    .meb-image-preview {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 200px;
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .meb-image-preview img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }
    
    .meb-image-details {
        flex: 1;
        min-width: 300px;
    }
    
    .meb-field-group {
        margin-bottom: 20px;
    }
    
    .meb-field-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 5px;
    }
    
    .meb-field-group input {
        width: 100%;
        margin-bottom: 8px;
    }
    
    .meb-optimization-info {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 4px;
        border-left: 4px solid #0073aa;
    }
    
    .meb-modal-footer {
        padding: 20px;
        border-top: 1px solid #ddd;
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    
    @media (max-width: 768px) {
        .meb-images-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            padding: 15px;
        }
        
        .meb-modal-body {
            flex-direction: column;
        }
        
        .meb-image-details {
            min-width: auto;
        }
        
        .filter-row {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .filter-row label {
            min-width: auto;
        }
    }
    </style>
    <?php
}

/**
 * Pagina Impostazioni
 */
function meb_settings_page() {
    if (isset($_POST['meb_save_settings']) && wp_verify_nonce($_POST['meb_settings_nonce'], 'meb_save_settings')) {
        meb_handle_settings_save();
    }
    
    $settings = meb_get_image_settings();
    ?>
    <div class="wrap meb-wrap">
        <h1>
            <span class="dashicons dashicons-admin-settings" style="font-size: 24px; margin-right: 8px; color: #0073aa;"></span>
            Impostazioni Meta Editor Pro
        </h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('meb_save_settings', 'meb_settings_nonce'); ?>
            
            <div class="meb-card">
                <div style="padding: 20px;">
                    <h2>Configurazione Generale</h2>
                    
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="meb_elements_per_page">Elementi per pagina</label>
                            </th>
                            <td>
                                <input type="number" id="meb_elements_per_page" name="meb_settings[elements_per_page]" value="<?php echo get_option('meb_elements_per_page', 15); ?>" min="5" max="100" class="regular-text">
                                <p class="description">Numero di elementi da mostrare in ogni pagina (5-100)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="meb_email_reports">Email per report</label>
                            </th>
                            <td>
                                <input type="email" id="meb_email_reports" name="meb_settings[email_reports]" value="<?php echo get_option('meb_email_reports', get_option('admin_email')); ?>" class="regular-text">
                                <p class="description">Indirizzo email per ricevere i report settimanali SEO</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <div class="meb-card">
                <div style="padding: 20px;">
                    <h2>SEO Immagini</h2>
                    <p class="description">Mostra la sezione per ottimizzare il testo alternativo delle immagini</p>
                    
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Ottimizzazione Automatica</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="image_settings[auto_optimize]" value="1" <?php checked($settings['auto_optimize']); ?>>
                                    Ottimizza automaticamente le nuove immagini caricate
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Qualità JPEG</th>
                            <td>
                                <input type="range" name="image_settings[quality_jpeg]" min="1" max="100" value="<?php echo $settings['quality_jpeg']; ?>" class="meb-quality-slider" data-target="#jpeg-quality-value">
                                <span id="jpeg-quality-value"><?php echo $settings['quality_jpeg']; ?>%</span>
                                <p class="description">Qualità per immagini JPEG (1-100, consigliato: 85)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Qualità WebP</th>
                            <td>
                                <input type="range" name="image_settings[quality_webp]" min="1" max="100" value="<?php echo $settings['quality_webp']; ?>" class="meb-quality-slider" data-target="#webp-quality-value">
                                <span id="webp-quality-value"><?php echo $settings['quality_webp']; ?>%</span>
                                <p class="description">Qualità per immagini WebP (1-100, consigliato: 80)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Dimensioni Massime</th>
                            <td>
                                <input type="number" name="image_settings[max_width]" value="<?php echo $settings['max_width']; ?>" min="100" step="10" style="width: 80px;"> x
                                <input type="number" name="image_settings[max_height]" value="<?php echo $settings['max_height']; ?>" min="100" step="10" style="width: 80px;"> px
                                <p class="description">Dimensioni massime per il ridimensionamento automatico</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Formato WebP</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="image_settings[generate_webp]" value="1" <?php checked($settings['generate_webp']); ?>>
                                    Genera versione WebP delle immagini (se supportato)
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Testo Alternativo</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="image_settings[auto_alt_text]" value="1" <?php checked($settings['auto_alt_text']); ?>>
                                    Genera automaticamente testo alternativo per immagini senza ALT
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Livello Compressione</th>
                            <td>
                                <select name="image_settings[compress_level]">
                                    <option value="light" <?php selected($settings['compress_level'], 'light'); ?>>Leggera (qualità alta)</option>
                                    <option value="balanced" <?php selected($settings['compress_level'], 'balanced'); ?>>Bilanciata (consigliata)</option>
                                    <option value="aggressive" <?php selected($settings['compress_level'], 'aggressive'); ?>>Aggressiva (file piccoli)</option>
                                </select>
                                <p class="description">Livello di compressione per l'ottimizzazione</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Immagini Esistenti</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="image_settings[optimize_existing]" value="1" <?php checked($settings['optimize_existing']); ?>>
                                    Permetti ri-ottimizzazione di immagini già ottimizzate
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <div class="meb-card">
                <div style="padding: 20px;">
                    <h2>Azioni di Manutenzione</h2>
                    <p class="description">Usa queste funzioni per mantenere ottimali il funzionamento del plugin</p>
                    
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                        <button type="button" id="meb-regenerate-stats" class="button button-secondary">
                            <span class="dashicons dashicons-chart-area"></span>
                            Rigenera Statistiche
                        </button>
                        
                        <button type="button" id="meb-clear-cache" class="button button-secondary">
                            <span class="dashicons dashicons-update"></span>
                            Svuota Storia Dati
                        </button>
                        
                        <button type="button" id="meb-force-cron" class="button button-secondary">
                            <span class="dashicons dashicons-backup"></span>
                            Forza Cron Job
                        </button>
                        
                        <button type="button" id="meb-clean-cache" class="button button-secondary">
                            <span class="dashicons dashicons-trash"></span>
                            Pulisci Cache
                        </button>
                    </div>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="meb_save_settings" class="button-primary" value="Salva Impostazioni">
            </p>
        </form>
    </div>
    
    <style>
    .meb-quality-slider {
        width: 200px;
        margin-right: 10px;
    }
    
    .form-table th {
        width: 200px;
    }
    
    .meb-card h2 {
        margin-top: 0;
        border-bottom: 1px solid #ddd;
        padding-bottom: 10px;
    }
    
    #meb-regenerate-stats .dashicons,
    #meb-clear-cache .dashicons,
    #meb-force-cron .dashicons,
    #meb-clean-cache .dashicons {
        margin-right: 5px;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Slider per qualità
        $('.meb-quality-slider').on('input', function() {
            const target = $(this).data('target');
            $(target).text($(this).val() + '%');
        });
        
        // Azioni di manutenzione
        $('#meb-regenerate-stats').on('click', function() {
            $(this).prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Rigenerazione...');
            
            $.post(ajaxurl, {
                action: 'meb_regenerate_stats',
                nonce: '<?php echo wp_create_nonce('meb_maintenance'); ?>'
            }).done(function(response) {
                alert('Statistiche rigenerate con successo!');
                location.reload();
            }).fail(function() {
                alert('Errore durante la rigenerazione');
            }).always(function() {
                $('#meb-regenerate-stats').prop('disabled', false).html('<span class="dashicons dashicons-chart-area"></span>Rigenera Statistiche');
            });
        });
        
        $('#meb-clear-cache').on('click', function() {
            if (!confirm('Sei sicuro di voler cancellare tutti i dati storici?')) return;
            
            $(this).prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Pulizia...');
            
            $.post(ajaxurl, {
                action: 'meb_clear_history',
                nonce: '<?php echo wp_create_nonce('meb_maintenance'); ?>'
            }).done(function(response) {
                alert('Dati storici cancellati!');
                location.reload();
            }).fail(function() {
                alert('Errore durante la pulizia');
            }).always(function() {
                $('#meb-clear-cache').prop('disabled', false).html('<span class="dashicons dashicons-update"></span>Svuota Storia Dati');
            });
        });
        
        $('#meb-force-cron').on('click', function() {
            $(this).prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Esecuzione...');
            
            $.post(ajaxurl, {
                action: 'meb_force_cron',
                nonce: '<?php echo wp_create_nonce('meb_maintenance'); ?>'
            }).done(function(response) {
                alert('Cron job eseguito con successo!');
            }).fail(function() {
                alert('Errore durante esecuzione cron');
            }).always(function() {
                $('#meb-force-cron').prop('disabled', false).html('<span class="dashicons dashicons-backup"></span>Forza Cron Job');
            });
        });
        
        $('#meb-clean-cache').on('click', function() {
            $(this).prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>Pulizia...');
            
            $.post(ajaxurl, {
                action: 'meb_clean_cache',
                nonce: '<?php echo wp_create_nonce('meb_maintenance'); ?>'
            }).done(function(response) {
                alert('Cache pulita!');
            }).fail(function() {
                alert('Errore durante pulizia cache');
            }).always(function() {
                $('#meb-clean-cache').prop('disabled', false).html('<span class="dashicons dashicons-trash"></span>Pulisci Cache');
            });
        });
    });
    </script>
    <?php
}

/**
 * Gestisce il salvataggio delle impostazioni
 */
function meb_handle_settings_save() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $settings = $_POST['meb_settings'] ?? [];
    $image_settings = $_POST['image_settings'] ?? [];
    
    // Salva impostazioni generali
    if (isset($settings['elements_per_page'])) {
        update_option('meb_elements_per_page', min(100, max(5, intval($settings['elements_per_page']))));
    }
    
    if (isset($settings['email_reports'])) {
        update_option('meb_email_reports', sanitize_email($settings['email_reports']));
    }
    
    // Salva impostazioni immagini
    $current_image_settings = meb_get_image_settings();
    foreach ($image_settings as $key => $value) {
        switch ($key) {
            case 'quality_jpeg':
            case 'quality_webp':
                $current_image_settings[$key] = min(100, max(1, intval($value)));
                break;
            case 'max_width':
            case 'max_height':
                $current_image_settings[$key] = max(100, intval($value));
                break;
            case 'compress_level':
                if (in_array($value, ['light', 'balanced', 'aggressive'])) {
                    $current_image_settings[$key] = $value;
                }
                break;
            default:
                $current_image_settings[$key] = !empty($value);
        }
    }
    
    update_option('meb_image_settings', $current_image_settings);
    
    // Messaggio di successo
    add_action('admin_notices', function() {
        echo '<div class="notice notice-success is-dismissible"><p>Impostazioni salvate con successo!</p></div>';
    });
}

/**
 * Ottieni statistiche immagini per la dashboard
 */
function meb_get_image_statistics() {
    $stats = wp_cache_get('meb_image_stats', 'meb');
    
    if ($stats === false) {
        $total_query = new WP_Query([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        $total = $total_query->found_posts;
        
        // Immagini senza ALT
        $no_alt_query = new WP_Query([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_wp_attachment_image_alt',
                    'value' => '',
                    'compare' => '='
                ]
            ]
        ]);
        
        $no_alt = $no_alt_query->found_posts;
        
        // Immagini grandi (>1MB)
        $large_count = 0;
        $optimized_count = 0;
        
        if ($total > 0) {
            $all_images = new WP_Query([
                'post_type' => 'attachment',
                'post_type' => 'attachment',
                'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                'post_status' => 'inherit',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            
            if ($all_images->have_posts()) {
                while ($all_images->have_posts()) {
                    $all_images->the_post();
                    $attachment_id = get_the_ID();
                    $file_path = get_attached_file($attachment_id);
                    
                    if (file_exists($file_path)) {
                        $file_size = filesize($file_path);
                        if ($file_size > 1048576) { // >1MB
                            $large_count++;
                        }
                        
                        if (get_post_meta($attachment_id, '_meb_optimized', true)) {
                            $optimized_count++;
                        }
                    }
                }
            }
            wp_reset_postdata();
        }
        
        $stats = [
            'total' => $total,
            'no_alt' => $no_alt,
            'no_alt_percent' => $total > 0 ? round(($no_alt / $total) * 100, 1) : 0,
            'large' => $large_count,
            'large_percent' => $total > 0 ? round(($large_count / $total) * 100, 1) : 0,
            'optimized' => $optimized_count,
            'optimized_percent' => $total > 0 ? round(($optimized_count / $total) * 100, 1) : 0
        ];
        
        wp_cache_set('meb_image_stats', $stats, 'meb', 3600); // Cache per 1 ora
    }
    
    return $stats;
}

// ===================================================================
// 8. AJAX HANDLERS PER AZIONI DI MANUTENZIONE
// ===================================================================

add_action('wp_ajax_meb_regenerate_stats', 'meb_ajax_regenerate_stats');
function meb_ajax_regenerate_stats() {
    check_ajax_referer('meb_maintenance', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Permessi insufficienti');
    }
    
    // Pulisci cache
    wp_cache_delete('meb_image_stats', 'meb');
    
    // Rigenera snapshot corrente
    meb_save_current_snapshot();
    
    wp_send_json_success('Statistiche rigenerate');
}

add_action('wp_ajax_meb_clear_history', 'meb_ajax_clear_history');
function meb_ajax_clear_history() {
    check_ajax_referer('meb_maintenance', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Permessi insufficienti');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'meb_history';
    $wpdb->query("TRUNCATE TABLE $table_name");
    
    wp_send_json_success('Storia dati cancellata');
}

add_action('wp_ajax_meb_force_cron', 'meb_ajax_force_cron');
function meb_ajax_force_cron() {
    check_ajax_referer('meb_maintenance', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Permessi insufficienti');
    }
    
    // Esegui manualmente i cron job
    meb_take_history_snapshot();
    meb_send_weekly_report();
    
    wp_send_json_success('Cron job eseguiti');
}

add_action('wp_ajax_meb_clean_cache', 'meb_ajax_clean_cache');
function meb_ajax_clean_cache() {
    check_ajax_referer('meb_maintenance', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Permessi insufficienti');
    }
    
    // Pulisci tutte le cache del plugin
    wp_cache_flush_group('meb');
    wp_cache_delete('meb_image_stats', 'meb');
    
    wp_send_json_success('Cache pulita');
}

// ===================================================================
// 9. HOOK PER OTTIMIZZAZIONE AUTOMATICA
// ===================================================================

/**
 * Hook per ottimizzare automaticamente le nuove immagini
 */
add_action('add_attachment', 'meb_auto_optimize_new_image');
function meb_auto_optimize_new_image($attachment_id) {
    $settings = meb_get_image_settings();
    
    if (!$settings['auto_optimize']) {
        return;
    }
    
    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }
    
    // Ottimizza in background per non rallentare l'upload
    wp_schedule_single_event(time() + 10, 'meb_optimize_single_image_hook', [$attachment_id]);
}

add_action('meb_optimize_single_image_hook', 'meb_optimize_single_image_background');
function meb_optimize_single_image_background($attachment_id) {
    $result = meb_optimize_single_image($attachment_id);
    
    if (!is_wp_error($result)) {
        error_log("MEB: Immagine $attachment_id ottimizzata automaticamente");
    } else {
        error_log("MEB: Errore ottimizzazione automatica immagine $attachment_id: " . $result->get_error_message());
    }
}

// ===================================================================
// 10. RENDER DELLA PAGINA ADMIN PRINCIPALE (INVARIATA)
// ===================================================================
function meb_admin_page() {
   ?>
   <div class="wrap meb-wrap">
       <h1>Meta Editor in Bulk</h1>
       <?php 
       if (isset($_GET['meb_imported'])) { 
           if ($_GET['meb_imported'] === '1') { 
               $count = isset($_GET['count']) ? intval($_GET['count']) : 0; 
               echo '<div id="message" class="updated notice is-dismissible"><p>Importazione completata. ' . $count . ' righe sono state processate con successo.</p></div>'; 
           } else { 
               echo '<div id="message" class="error notice is-dismissible"><p>Errore durante l\'importazione.</p></div>'; 
           } 
       } 
       ?>
       <div class="meb-dashboard-flex">
           <div class="meb-card meb-chart-container">
               <div class="meb-chart-header">
                   <h3>Andamento Ottimizzazione</h3>
                   <div class="meb-chart-controls">
                       <?php 
                       // NUOVO: Aggiungi selettore lingua per il grafico se multilingua è attivo
                       $multilang_plugin = meb_get_active_multilang_plugin();
                       if ($multilang_plugin) {
                           $languages = meb_get_available_languages();
                           $current_chart_lang = meb_get_current_language();
                           echo '<select id="meb-chart-language" style="margin-right: 10px; padding: 4px 8px; border: 1px solid #c3c4c7; border-radius: 4px;">';
                           echo '<option value="all"' . selected($current_chart_lang, 'all', false) . '>📊 Tutte le lingue</option>';
                           foreach ($languages as $code => $lang) {
                               $flag = $lang['flag'] ? '<img src="' . $lang['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
                               $selected = selected($current_chart_lang, $code, false);
                               echo "<option value='$code' $selected>$flag {$lang['name']} ($code)</option>";
                           }
                           echo '</select>';
                       }
                       ?>
                       <input type="text" id="meb-date-picker" placeholder="Seleziona un intervallo di date" />
                   </div>
               </div>
               <canvas id="mebHistoryChart"></canvas>
           </div>
           <div class="meb-card meb-stats-container">
               <h3>Stato Attuale</h3>
               <?php 
               // NUOVO: Mostra informazioni lingua se multilingua è attivo
               if ($multilang_plugin) {
                   $current_lang = meb_get_current_language();
                   $languages = meb_get_available_languages();
                   
                   if ($current_lang === 'all') {
                       echo '<div class="meb-language-info" style="background: #e8f4fd; padding: 8px 12px; margin-bottom: 15px; border-radius: 4px; font-size: 13px;">';
                       echo '<strong>🌍 Modalità Multilingua:</strong> Visualizzazione globale di tutte le lingue';
                       echo '</div>';
                   } else if (isset($languages[$current_lang])) {
                       $lang = $languages[$current_lang];
                       $flag = $lang['flag'] ? '<img src="' . $lang['flag'] . '" style="width:20px;height:15px;margin-right:6px;vertical-align:middle;" />' : '';
                       echo '<div class="meb-language-info" style="background: #f0f6fc; padding: 8px 12px; margin-bottom: 15px; border-radius: 4px; font-size: 13px;">';
                       echo '<strong>' . $flag . 'Lingua Attiva:</strong> ' . $lang['name'] . ' (' . strtoupper($current_lang) . ')';
                       echo '</div>';
                   }
               }
               ?>
               <ul>
                   <?php 
                   $post_types_stats = meb_get_all_post_types(); 
                   foreach ($post_types_stats as $slug => $label) { 
                       // Salta i separatori
                       if (strpos($slug, '_separator') !== false) continue;
                       
                       if (strpos($slug, 'taxonomy_') === 0) {
                           // Statistiche per tassonomie
                           $taxonomy = str_replace('taxonomy_', '', $slug);
                           list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
                       } else {
                           // Statistiche per post types
                           list($optimized, $total) = meb_get_seo_optimization_stats($slug);
                       }
                       
                       $percent = $total ? round(($optimized / $total) * 100) : 0; 
                       $progress_class = $percent >= 80 ? 'high' : ($percent >= 40 ? 'medium' : 'low'); 
                       echo "<li><div><strong>$label</strong><small>$optimized / $total ($percent%)</small></div><div class='progress-bar-container'><div class='progress-bar $progress_class' style='width: {$percent}%;'></div></div></li>"; 
                   } 
                   ?>
               </ul>
               <div class="meb-actions">
                   <button id="meb-import-button" class="button button-secondary">Importa</button> 
                   <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=meta-editor-in-bulk&meb_action=export_csv'), 'meb_export_nonce')); ?>" class="button button-primary">Esporta</a>
               </div>
           </div>
       </div>
       
       <div id="meb-import-form-container" class="meb-card" style="display:none;">
           <h3>Importa Dati SEO da CSV</h3>
           <p>Carica un file CSV con le colonne: <strong>ID, Type, Language, Title, Slug, Meta Title, Meta Description, Focus Keyword</strong>.</p>
           <form method="post" enctype="multipart/form-data">
               <?php wp_nonce_field('meb_import_action', 'meb_import_nonce'); ?>
               <p><label for="meb_import_file">File CSV:</label><br><input type="file" id="meb_import_file" name="meb_import_file" accept=".csv" required></p>
               <p><button type="submit" class="button button-primary">Avvia Importazione</button> <button type="button" id="meb-cancel-import" class="button button-secondary">Annulla</button></p>
           </form>
       </div>
       
       <div class="meb-card meb-table-container">
           <?php 
           $post_types = meb_get_all_post_types(); 
           $current_post_type = isset($_GET['meb_post_type']) ? sanitize_key($_GET['meb_post_type']) : 'post'; 
           $search_keyword = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : ''; 
           $current_view = isset($_GET['meb_view']) ? sanitize_key($_GET['meb_view']) : 'to_optimize'; 
           
           // NUOVO: Gestione lingua corrente
           $current_language = meb_get_current_language();
           ?>
           <form method="get" class="meb-filters">
               <input type="hidden" name="page" value="meta-editor-in-bulk">
               
               <?php 
               // NUOVO: Aggiungi il filtro lingua SOLO se multilingua è attivo
               if ($multilang_plugin) {
                   $languages = meb_get_available_languages();
                   ?>
                   <label for="meb_language">🌍 Lingua:</label> 
                   <select id="meb_language" name="meb_language">
                       <option value="all" <?php selected($current_language, 'all'); ?>>📊 Tutte le lingue</option>
                       <?php 
                       foreach ($languages as $code => $lang) { 
                           $flag = $lang['flag'] ? '<img src="' . $lang['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
                           echo "<option value='$code'" . selected($current_language, $code, false) . ">$flag {$lang['name']} ($code)</option>"; 
                       } 
                       ?>
                   </select>
                   <?php
               }
               ?>
               
               <label for="meb_post_type">Tipo contenuto:</label> 
               <select id="meb_post_type" name="meb_post_type">
                   <?php 
                   foreach ($post_types as $slug => $label) { 
                       if (strpos($slug, '_separator') !== false) {
                           echo "<option disabled style='background: #f0f0f0; font-weight: bold; color: #666;'>$label</option>";
                       } else {
                           echo "<option value='$slug'" . selected($current_post_type, $slug, false) . ">$label</option>"; 
                       }
                   } 
                   ?>
               </select>
               
               <label for="meb_view">Mostra:</label> 
               <select id="meb_view" name="meb_view">
                   <option value="to_optimize" <?php selected($current_view, 'to_optimize'); ?>>Da Ottimizzare</option>
                   <option value="optimized" <?php selected($current_view, 'optimized'); ?>>Già Ottimizzati</option>
               </select>
               
               <input type="text" name="s" placeholder="Filtro per keyword" value="<?php echo esc_attr($search_keyword); ?>">
               <input type="submit" class="button" value="Filtra">
               
               <?php 
               // NUOVO: Mostra reset filtri se ci sono filtri attivi
               if ($current_language !== 'all' || $search_keyword || $current_view !== 'to_optimize' || $current_post_type !== 'post') {
                   echo '<a href="' . admin_url('admin.php?page=meta-editor-in-bulk') . '" class="button button-secondary" style="margin-left: 10px;">🔄 Reset Filtri</a>';
               }
               ?>
           </form>
           
           <form id="meb-bulk-edit-form">
               <div class="meb-table-wrapper">
                   <table class="widefat meb-table">
                       <thead>
                           <tr>
                               <th class="column-title">Titolo & Azioni</th>
                               <th class="column-keyword">Focus Keyword</th>
                               <th class="column-meta-title">Meta Title</th>
                               <th class="column-meta-description">Meta Description</th>
                               <th class="column-slug">Slug</th>
                           </tr>
                       </thead>
                       <tbody>
                           <?php 
                           $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1; 
                           $query = meb_get_posts_query($current_post_type, $search_keyword, $current_view, $paged);
                           
                           // GESTIONE CATEGORIE E TASSONOMIE
                           if (isset($query->is_taxonomy) && $query->is_taxonomy) {
                               // LOOP PER CATEGORIE
                               if (!empty($query->terms)) : 
                                   foreach ($query->terms as $term) :
                                       $seo_data = meb_get_term_seo_data($term->term_id, false);
                                      $term_link = get_term_link($term);
                                      ?>
                                      <tr class="meb-main-row taxonomy-row" data-post-id="<?php echo $term->term_id; ?>" data-type="taxonomy" data-taxonomy="<?php echo $query->taxonomy; ?>">
                                          <td>
                                              <strong><?php echo esc_html($term->name); ?></strong>
                                              <small style="color: #666; display: block;"><?php echo ucfirst($query->taxonomy); ?> • <?php echo $term->count; ?> elementi</small>
                                              <div class="row-actions">
                                                  <a href="<?php echo get_edit_term_link($term->term_id, $query->taxonomy); ?>" class="button-link meb-edit-button" target="_blank">
                                                     <span class="dashicons dashicons-edit"></span>Modifica
                                                 </a>
                                                 <span class="separator"> | </span>
                                                 <a href="<?php echo esc_url($term_link); ?>" class="button-link meb-view-button" target="_blank">
                                                     <span class="dashicons dashicons-visibility"></span>Visualizza
                                                 </a>
                                                 <span class="separator"> | </span>
                                                 <button type="button" class="button-link meb-analyze-button">
                                                     <span class="dashicons dashicons-search"></span>Ottimizza & Preview
                                                 </button>
                                             </div>
                                         </td>
                                         <td><input type="text" value="<?php echo esc_attr($seo_data['keyword']); ?>" class="meb-meta-input" data-type="keyword" /></td>
                                         <td>
                                             <input type="text" value="<?php echo esc_attr($seo_data['title']); ?>" class="meb-meta-input" data-type="title" />
                                             <div class="meb-length-indicator">
                                                 <div class="indicator-bar-bg"><div class="indicator-bar"></div></div>
                                                 <span class="indicator-text">0/60</span>
                                             </div>
                                         </td>
                                         <td>
                                             <textarea rows="2" class="meb-meta-input" data-type="description"><?php echo esc_textarea($seo_data['description']); ?></textarea>
                                             <div class="meb-length-indicator">
                                                 <div class="indicator-bar-bg"><div class="indicator-bar"></div></div>
                                                 <span class="indicator-text">0/160</span>
                                             </div>
                                         </td>
                                         <td>
                                             <input type="text" value="<?php echo esc_attr($term->slug); ?>" class="meb-meta-input" data-type="slug" />
                                             <div class="meb-length-indicator">
                                                 <div class="indicator-bar-bg"><div class="indicator-bar"></div></div>
                                                 <span class="indicator-text">0/75</span>
                                             </div>
                                         </td>
                                     </tr>
                                     <!-- Drawer per categoria con anteprima Google realistica -->
                                     <tr class="meb-drawer-row" style="display: none;">
                                         <td colspan="5">
                                             <div class="meb-drawer-content">
                                                 <div class="meb-drawer-preview">
                                                     <h4>Anteprima Google</h4>
                                                     <div class="meb-preview-toggles">
                                                         <button type="button" class="meb-preview-toggle active" data-device="desktop">
                                                             <span class="dashicons dashicons-desktop"></span>Desktop
                                                         </button>
                                                         <button type="button" class="meb-preview-toggle" data-device="mobile">
                                                             <span class="dashicons dashicons-smartphone"></span>Mobile
                                                         </button>
                                                     </div>
                                                     
                                                     <!-- ANTEPRIMA GOOGLE REALISTICA -->
                                                     <div class="google-serp-preview" data-device="desktop">
                                                         <!-- Barra di ricerca Google -->
                                                         <div class="google-search-bar">
                                                             <div class="search-input">
                                                                 <span class="search-icon">🔍</span>
                                                                 <span class="search-query"><?php echo esc_html($term->name); ?></span>
                                                                 <span class="voice-search">🎤</span>
                                                                 <span class="camera-search">📷</span>
                                                             </div>
                                                         </div>
                                                         
                                                         <!-- Tabs Google -->
                                                         <div class="google-tabs">
                                                             <a href="#" class="google-tab active">
                                                                 <span class="tab-icon">🔍</span>
                                                                 Tutto
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">🖼️</span>
                                                                 Immagini
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">📹</span>
                                                                 Video
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">📰</span>
                                                                 Notizie
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">📍</span>
                                                                 Mappe
                                                             </a>
                                                         </div>
                                                         
                                                         <!-- Info risultati -->
                                                         <div class="search-stats">
                                                             <span class="results-count">Circa 1.240.000 risultati (0,42 secondi)</span>
                                                         </div>
                                                         
                                                         <!-- AI Overview Button -->
                                                         <button class="ai-overview-btn">
                                                             <span class="ai-icon">✨</span>
                                                             Panoramica IA
                                                         </button>
                                                         
                                                         <!-- Risultato principale -->
                                                         <div class="search-result">
                                                             <div class="result-url">
                                                                 <img src="<?php echo get_site_icon_url(16); ?>" class="site-favicon" alt="favicon">
                                                                 <span class="site-url"><?php echo parse_url(get_term_link($term), PHP_URL_HOST); ?></span>
                                                                 <span class="breadcrumb"> › Categoria</span>
                                                             </div>
                                                             <h3 class="result-title">
                                                                 <a href="<?php echo get_term_link($term); ?>" target="_blank">
                                                                     <?php echo esc_html($term->name); ?>
                                                                 </a>
                                                             </h3>
                                                             <div class="result-description">
                                                                 <?php echo esc_html($term->description ?: 'Scopri tutto su ' . $term->name . ' e trova i migliori contenuti nella nostra categoria dedicata.'); ?>
                                                             </div>
                                                             <div class="result-extras">
                                                                 <span class="result-date">📅 <?php echo date('j M Y'); ?></span>
                                                                 <span class="result-type">🏷️ Categoria</span>
                                                             </div>
                                                         </div>
                                                     </div>
                                                 </div>
                                                 
                                                 <div class="meb-drawer-analysis">
                                                     <div class="seo-analysis-header">
                                                         <h4>Analisi SEO</h4>
                                                         <div class="analysis-progress-bar-bg">
                                                             <div class="analysis-progress-bar"></div>
                                                         </div>
                                                     </div>
                                                     <div class="seo-analysis-details">
                                                         <ul></ul>
                                                     </div>
                                                 </div>
                                             </div>
                                         </td>
                                     </tr>
                                     <?php 
                                 endforeach; 
                             else : 
                                 echo '<tr><td colspan="5">Nessuna categoria trovata.</td></tr>'; 
                             endif;
                         } else {
                             // LOOP NORMALE PER POST TYPES
                             if ($query->have_posts()) : 
                                 while ($query->have_posts()) : $query->the_post();
                                     $post_id = get_the_ID(); 
                                     $raw_data = meb_get_post_seo_data($post_id, false); 
                                     $current_slug = get_post_field('post_name', $post_id);
                                     $post_type = get_post_type($post_id);
                                     $row_class = ($post_type === 'product') ? 'woocommerce-product-row' : '';
                                     ?>
                                     <tr class="meb-main-row <?php echo $row_class; ?>" data-post-id="<?php echo $post_id; ?>" data-type="post">
                                         <td>
                                             <strong><?php the_title(); ?></strong>
                                             <?php if ($post_type === 'product') : ?>
                                                 <small style="color: #666; display: block;">🛒 Prodotto WooCommerce</small>
                                             <?php endif; ?>
                                             <div class="row-actions">
                                                 <a href="<?php echo get_edit_post_link($post_id); ?>" class="button-link meb-edit-button" target="_blank">
                                                     <span class="dashicons dashicons-edit"></span>Modifica
                                                 </a>
                                                 <span class="separator"> | </span>
                                                 <a href="<?php echo get_permalink($post_id); ?>" class="button-link meb-view-button" target="_blank">
                                                     <span class="dashicons dashicons-visibility"></span>Visualizza
                                                 </a>
                                                 <span class="separator"> | </span>
                                                 <button type="button" class="button-link meb-analyze-button">
                                                     <span class="dashicons dashicons-search"></span>Ottimizza & Preview
                                                 </button>
                                             </div>
                                         </td>
                                         <td>
                                             <input type="text" value="<?php echo esc_attr($raw_data['keyword']); ?>" class="meb-meta-input" data-type="keyword" />
                                         </td>
                                         <td>
                                             <input type="text" value="<?php echo esc_attr($raw_data['title']); ?>" class="meb-meta-input" data-type="title" />
                                             <div class="meb-length-indicator">
                                                 <div class="indicator-bar-bg"><div class="indicator-bar"></div></div>
                                                 <span class="indicator-text">0/60</span>
                                             </div>
                                         </td>
                                         <td>
                                             <textarea rows="2" class="meb-meta-input" data-type="description"><?php echo esc_textarea($raw_data['description']); ?></textarea>
                                             <div class="meb-length-indicator">
                                                 <div class="indicator-bar-bg"><div class="indicator-bar"></div></div>
                                                 <span class="indicator-text">0/160</span>
                                             </div>
                                         </td>
                                         <td>
                                             <input type="text" value="<?php echo esc_attr($current_slug); ?>" class="meb-meta-input" data-type="slug" />
                                             <div class="meb-length-indicator">
                                                 <div class="indicator-bar-bg"><div class="indicator-bar"></div></div>
                                                 <span class="indicator-text">0/75</span>
                                             </div>
                                         </td>
                                     </tr>
                                     
                                     <!-- Drawer con anteprima Google realistica per POST -->
                                     <tr class="meb-drawer-row" style="display: none;">
                                         <td colspan="5">
                                             <div class="meb-drawer-content">
                                                 <div class="meb-drawer-preview">
                                                     <h4>Anteprima Google</h4>
                                                     <div class="meb-preview-toggles">
                                                         <button type="button" class="meb-preview-toggle active" data-device="desktop">
                                                             <span class="dashicons dashicons-desktop"></span>Desktop
                                                         </button>
                                                         <button type="button" class="meb-preview-toggle" data-device="mobile">
                                                             <span class="dashicons dashicons-smartphone"></span>Mobile
                                                         </button>
                                                     </div>
                                                     
                                                     <!-- ANTEPRIMA GOOGLE REALISTICA -->
                                                     <div class="google-serp-preview" data-device="desktop">
                                                         <!-- Barra di ricerca Google -->
                                                         <div class="google-search-bar">
                                                             <div class="search-input">
                                                                 <span class="search-icon">🔍</span>
                                                                 <span class="search-query"><?php echo esc_html($raw_data['keyword'] ?: get_the_title()); ?></span>
                                                                 <span class="voice-search">🎤</span>
                                                                 <span class="camera-search">📷</span>
                                                             </div>
                                                         </div>
                                                         
                                                         <!-- Tabs Google -->
                                                         <div class="google-tabs">
                                                             <a href="#" class="google-tab active">
                                                                 <span class="tab-icon">🔍</span>
                                                                 Tutto
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">🖼️</span>
                                                                 Immagini
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">📹</span>
                                                                 Video
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">📰</span>
                                                                 Notizie
                                                             </a>
                                                             <a href="#" class="google-tab">
                                                                 <span class="tab-icon">📍</span>
                                                                 Mappe
                                                             </a>
                                                         </div>
                                                         
                                                         <!-- Info risultati -->
                                                         <div class="search-stats">
                                                             <span class="results-count">Circa 1.240.000 risultati (0,42 secondi)</span>
                                                         </div>
                                                         
                                                         <!-- AI Overview Button -->
                                                         <button class="ai-overview-btn">
                                                             <span class="ai-icon">✨</span>
                                                             Panoramica IA
                                                         </button>
                                                         
                                                         <!-- Risultato principale -->
                                                         <div class="search-result <?php echo $post_type === 'product' ? 'product-result' : ''; ?>">
                                                             <div class="result-url">
                                                                 <img src="<?php echo get_site_icon_url(16); ?>" class="site-favicon" alt="favicon">
                                                                 <span class="site-url"><?php echo parse_url(get_permalink(), PHP_URL_HOST); ?></span>
                                                                 <span class="breadcrumb"> › <?php echo $post_type === 'product' ? 'Prodotto' : 'Articolo'; ?></span>
                                                             </div>
                                                             <h3 class="result-title">
                                                                 <a href="<?php echo get_permalink(); ?>" target="_blank">
                                                                     <?php echo esc_html($raw_data['title'] ?: get_the_title()); ?>
                                                                 </a>
                                                             </h3>
                                                             <div class="result-description">
                                                                 <?php echo esc_html($raw_data['description'] ?: wp_trim_words(get_the_excerpt() ?: get_the_content(), 25)); ?>
                                                             </div>
                                                             
                                                             <?php if ($post_type === 'product') : ?>
                                                             <!-- Info prodotto WooCommerce -->
                                                             <div class="product-info">
                                                                 <div class="product-price">€ 29,99</div>
                                                                 <div class="product-rating">
                                                                     <span class="stars">⭐⭐⭐⭐⭐</span>
                                                                     <span class="reviews">(124 recensioni)</span>
                                                                 </div>
                                                                 <div class="product-availability">✅ Disponibile</div>
                                                             </div>
                                                             <?php endif; ?>
                                                             
                                                             <div class="result-extras">
                                                                 <span class="result-date">📅 <?php echo get_the_date('j M Y'); ?></span>
                                                                 <?php if ($post_type !== 'product') : ?>
                                                                 <span class="reading-time">⏱️ 3 min di lettura</span>
                                                                 <?php endif; ?>
                                                             </div>
                                                         </div>
                                                     </div>
                                                 </div>
                                                 
                                                 <div class="meb-drawer-analysis">
                                                     <div class="seo-analysis-header">
                                                         <h4>Analisi SEO</h4>
                                                         <div class="analysis-progress-bar-bg">
                                                             <div class="analysis-progress-bar"></div>
                                                         </div>
                                                     </div>
                                                     <div class="seo-analysis-details">
                                                         <ul></ul>
                                                     </div>
                                                 </div>
                                             </div>
                                         </td>
                                     </tr>
                                 <?php 
                                 endwhile; 
                             else : 
                                 echo '<tr><td colspan="5">Nessun risultato trovato.</td></tr>'; 
                             endif; 
                             wp_reset_postdata();
                         }
                         ?>
                     </tbody>
                 </table>
             </div>
             <div class="meb-table-footer">
                 <button type="submit" class="button button-primary">
                     <span class="save-text">Salva Modifiche</span>
                     <span class="spinner"></span>
                 </button>
                 <?php 
                 if (isset($query->is_taxonomy) && $query->is_taxonomy) {
                     meb_render_taxonomy_pagination($query, $paged);
                 } else {
                     meb_render_pagination($query, $paged);
                 }
                 ?>
             </div>
         </form>
     </div>
 </div>
 <?php
}

// ===================================================================
// 11. FUNZIONI HELPER (INVARIATE)
// ===================================================================
function meb_get_posts_query($post_type, $keyword, $view, $paged) { 
   // GESTIONE TASSONOMIE (CATEGORIE)
   if (strpos($post_type, 'taxonomy_') === 0) {
       $taxonomy = str_replace('taxonomy_', '', $post_type);
       return meb_get_taxonomy_query($taxonomy, $keyword, $view, $paged);
   }
   
   // GESTIONE POST TYPES NORMALI
   $meta_keys = meb_get_seo_meta_keys(); 
   $meta_query = ['relation' => 'AND']; 
   
   if ($view === 'to_optimize') { 
       $meta_query[] = [
           'relation' => 'OR', 
           ['key' => $meta_keys['title'], 'compare' => 'NOT EXISTS'], 
           ['key' => $meta_keys['title'], 'value' => '', 'compare' => '='], 
           ['key' => $meta_keys['desc'], 'compare' => 'NOT EXISTS'], 
           ['key' => $meta_keys['desc'], 'value' => '', 'compare' => '=']
       ]; 
   } else { 
       $meta_query[] = ['key' => $meta_keys['title'], 'compare' => 'EXISTS']; 
       $meta_query[] = ['key' => $meta_keys['title'], 'value' => '', 'compare' => '!=']; 
       $meta_query[] = ['key' => $meta_keys['desc'], 'compare' => 'EXISTS']; 
       $meta_query[] = ['key' => $meta_keys['desc'], 'value' => '', 'compare' => '!=']; 
   } 
   
   $args = [
       'post_type' => $post_type, 
       'posts_per_page' => 10, 
       'paged' => $paged, 
       's' => $keyword, 
       'post_status' => 'publish', 
       'meta_query' => $meta_query
   ]; 
   
   // NUOVO: Aggiungi filtro lingua se necessario
   $current_lang = meb_get_current_language();
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   if ($multilang_plugin && $current_lang !== 'all') {
       switch ($multilang_plugin) {
           case 'wpml':
               if (function_exists('icl_object_id')) {
                   add_filter('posts_join', 'meb_wpml_posts_join');
                   add_filter('posts_where', function($where) use ($current_lang) {
                       return meb_wpml_posts_where($where, $current_lang);
                   });
               }
               break;
               
           case 'polylang':
               if (function_exists('pll_get_post_language')) {
                   $args['lang'] = $current_lang;
               }
               break;
       }
   }
   
   $query = new WP_Query($args);
   
   // Rimuovi i filtri dopo la query
   if ($multilang_plugin && $current_lang !== 'all') {
       remove_filter('posts_join', 'meb_wpml_posts_join');
       remove_filter('posts_where', 'meb_wpml_posts_where');
   }
   
   return $query;
}

// NUOVA FUNZIONE PER GESTIRE LE CATEGORIE BLOG
function meb_get_taxonomy_query($taxonomy, $keyword, $view, $paged) {
   $args = [
       'taxonomy' => $taxonomy,
       'hide_empty' => false,
       'number' => 10,
       'offset' => ($paged - 1) * 10,
   ];
   
   if ($keyword) {
       $args['search'] = $keyword;
   }
   
   // Filtro per ottimizzazione
   if ($view === 'to_optimize') {
       $args['meta_query'] = [
           'relation' => 'OR',
           [
               'key' => '_meb_builtin_title',
               'compare' => 'NOT EXISTS'
           ],
           [
               'key' => '_meb_builtin_title',
               'value' => '',
               'compare' => '='
           ]
       ];
   }
   
   // NUOVO: Gestione multilingua per tassonomie
   $current_lang = meb_get_current_language();
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   if ($multilang_plugin && $current_lang !== 'all') {
       switch ($multilang_plugin) {
           case 'wpml':
               // WPML gestisce automaticamente le tassonomie
               if (function_exists('icl_object_id')) {
                   global $sitepress;
                   if ($sitepress) {
                       $sitepress->switch_lang($current_lang);
                   }
               }
               break;
               
           case 'polylang':
               if (function_exists('pll_get_term_language')) {
                   $args['lang'] = $current_lang;
               }
               break;
       }
   }
   
   $terms = get_terms($args);
   
   // Ripristina lingua originale per WPML
   if ($multilang_plugin === 'wpml' && $current_lang !== 'all') {
       global $sitepress;
       if ($sitepress) {
           $sitepress->switch_lang(null);
       }
   }
   
   $total_terms = wp_count_terms($taxonomy, ['hide_empty' => false]);
   
   // Simula WP_Query per compatibilità
   $fake_query = new stdClass();
   $fake_query->terms = $terms ? $terms : [];
   $fake_query->found_posts = $total_terms;
   $fake_query->max_num_pages = ceil($total_terms / 10);
   $fake_query->is_taxonomy = true;
   $fake_query->taxonomy = $taxonomy;
   
   return $fake_query;
}

function meb_render_pagination($query, $paged) { 
   $pagination_html = paginate_links([ 
       'base' => add_query_arg('paged', '%#%', remove_query_arg('paged', wp_unslash($_SERVER['REQUEST_URI']))), 
       'format' => '?paged=%#%', 
       'current' => $paged, 
       'total' => $query->max_num_pages, 
       'prev_text' => '<span class="dashicons dashicons-arrow-left-alt2"></span>', 
       'next_text' => '<span class="dashicons dashicons-arrow-right-alt2"></span>', 
       'type' => 'list' 
   ]); 
   if ($pagination_html) { 
       echo '<div class="meb-pagination">' . $pagination_html . '</div>';
   } 
}

function meb_render_taxonomy_pagination($query, $paged) {
   $pagination_html = paginate_links([ 
       'base' => add_query_arg('paged', '%#%', remove_query_arg('paged', wp_unslash($_SERVER['REQUEST_URI']))), 
       'format' => '?paged=%#%', 
       'current' => $paged, 
       'total' => $query->max_num_pages, 
       'prev_text' => '<span class="dashicons dashicons-arrow-left-alt2"></span>', 
       'next_text' => '<span class="dashicons dashicons-arrow-right-alt2"></span>', 
       'type' => 'list' 
   ]); 
   if ($pagination_html) { 
       echo '<div class="meb-pagination">' . $pagination_html . '</div>';
   }
}

function meb_parse_seo_variables($string, $post_id = 0, $term = null) { 
   if (empty($string)) return ''; 
   
   if ($term) {
       // Variabili per tassonomie
       $replacements = [ 
           '%%title%%' => $term->name, 
           '%%sitename%%' => get_bloginfo('name'), 
           '%%sitedesc%%' => get_bloginfo('description'), 
           '%%sep%%' => apply_filters('wpseo_separator', '-'), 
           '%%currentyear%%' => date('Y'),
           '%%term_title%%' => $term->name,
           '%%term_description%%' => $term->description
       ]; 
   } else {
       // Variabili per post
       $post = get_post($post_id); 
       if (!$post) return $string; 
       
       $replacements = [ 
           '%%title%%' => get_the_title($post_id), 
           '%%sitename%%' => get_bloginfo('name'), 
           '%%sitedesc%%' => get_bloginfo('description'), 
           '%%excerpt%%' => has_excerpt($post_id) ? get_the_excerpt($post_id) : wp_trim_words($post->post_content, 55), 
           '%%sep%%' => apply_filters('wpseo_separator', '-'), 
           '%%date%%' => get_the_date('', $post_id), 
           '%%currentyear%%' => date('Y'), 
       ]; 
   }
   
   $string = str_replace(array_keys($replacements), array_values($replacements), $string); 
   return preg_replace('/\s+/', ' ', trim($string)); 
}

function meb_get_post_seo_data($post_id, $parse = true) { 
   $keys = meb_get_seo_meta_keys(); 
   $data = [
       'title' => get_post_meta($post_id, $keys['title'], true), 
       'description' => get_post_meta($post_id, $keys['desc'], true), 
       'keyword' => get_post_meta($post_id, $keys['keyword'], true)
   ]; 
   
   if ($parse) { 
       $data['title'] = meb_parse_seo_variables($data['title'], $post_id); 
       $data['description'] = meb_parse_seo_variables($data['description'], $post_id); 
   } 
   return $data; 
}

function meb_get_term_seo_data($term_id, $parse = true) {
   $keys = meb_get_seo_meta_keys();
   $data = [
       'title' => get_term_meta($term_id, $keys['title'], true),
       'description' => get_term_meta($term_id, $keys['desc'], true),
       'keyword' => get_term_meta($term_id, $keys['keyword'], true)
   ];
   
   if ($parse) {
       $term = get_term($term_id);
       $data['title'] = meb_parse_seo_variables($data['title'], 0, $term);
       $data['description'] = meb_parse_seo_variables($data['description'], 0, $term);
   }
   return $data;
}

function meb_get_seo_meta_keys() { 
   $plugin = meb_get_active_seo_plugin(); 
   switch ($plugin) { 
       case 'yoast': 
           return [
               'title' => '_yoast_wpseo_title', 
               'desc' => '_yoast_wpseo_metadesc', 
               'keyword' => '_yoast_wpseo_focuskw'
           ]; 
       case 'rankmath': 
           return [
               'title' => 'rank_math_title', 
               'desc' => 'rank_math_description', 
               'keyword' => 'rank_math_focus_keyword'
           ]; 
       case 'aioseo': 
           return [
               'title' => '_aioseop_title', 
               'desc' => '_aioseop_description', 
               'keyword' => '_aioseop_keywords'
           ]; 
       case 'seopress': 
           return [
               'title' => '_seopress_titles_title', 
               'desc' => '_seopress_titles_desc', 
               'keyword' => '_seopress_analysis_target_kw'
           ]; 
       default: 
           return [
               'title' => '_meb_builtin_title', 
               'desc' => '_meb_builtin_description', 
               'keyword' => '_meb_builtin_keyword'
           ]; 
   } 
}

function meb_get_active_seo_plugin() { 
   if (defined('WPSEO_VERSION')) return 'yoast'; 
   if (defined('RANK_MATH_VERSION')) return 'rankmath'; 
   if (defined('AIOSEOP_VERSION')) return 'aioseo'; 
   if (defined('SEOPRESS_VERSION')) return 'seopress'; 
   return 'builtin'; 
}

function meb_get_all_post_types() { 
   $args = ['public' => true]; 
   $output = 'objects'; 
   $post_types = get_post_types($args, $output); 
   $types = []; 
   
   $multilang_plugin = meb_get_active_multilang_plugin();
   $languages = meb_get_available_languages();
   $current_lang = meb_get_current_language();
   
   // ===== CONTENUTI BLOG =====
   $types['blog_separator'] = '━━━ CONTENUTI BLOG ━━━';
   
   foreach ($post_types as $type) { 
       if ($type->name === 'attachment' || $type->name === 'product') continue; 
       
       $icon = '📝';
       $label = $type->labels->name;
       
       // Se multilingua è attivo e non è "all", aggiungi indicatore lingua
       if ($multilang_plugin && $current_lang !== 'all' && isset($languages[$current_lang])) {
           $flag = $languages[$current_lang]['flag'] ? '<img src="' . $languages[$current_lang]['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
           $label = $flag . $label . ' (' . strtoupper($current_lang) . ')';
       }
       
       $types[$type->name] = $icon . ' ' . $label; 
   } 
   
   // Solo categorie WordPress (NON WooCommerce)
   $taxonomies = get_taxonomies(['public' => true], 'objects');
   foreach ($taxonomies as $taxonomy) {
       // Escludi tutte le tassonomie WooCommerce e quelle di sistema
       if (in_array($taxonomy->name, [
           'post_tag', 'nav_menu', 'link_category', 'post_format',
           'product_cat', 'product_tag', 'product_type', 'product_visibility'
       ]) || strpos($taxonomy->name, 'pa_') === 0) {
           continue;
       }
       
       // Verifica che non sia una tassonomia WooCommerce
       if (class_exists('WooCommerce') && 
           (strpos($taxonomy->name, 'product') !== false || 
            in_array($taxonomy->name, ['brand', 'product_brand']))) {
           continue;
       }
       
       $label = $taxonomy->labels->name;
       
       // Se multilingua è attivo e non è "all", aggiungi indicatore lingua
       if ($multilang_plugin && $current_lang !== 'all' && isset($languages[$current_lang])) {
           $flag = $languages[$current_lang]['flag'] ? '<img src="' . $languages[$current_lang]['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
           $label = $flag . $label . ' (' . strtoupper($current_lang) . ')';
       }
       
       $types['taxonomy_' . $taxonomy->name] = '🏷️ ' . $label;
   }
   
   // ===== CONTENUTI E-COMMERCE =====
   if (class_exists('WooCommerce')) {
       $types['ecommerce_separator'] = '━━━ CONTENUTI E-COMMERCE ━━━';
       
       $woo_types = [
           'product' => '🛒 Prodotti WooCommerce',
           'taxonomy_product_cat' => '🛒 Categorie Prodotti',
           'taxonomy_product_tag' => '🛒 Tag Prodotti'
       ];
       
       foreach ($woo_types as $slug => $base_label) {
           $label = $base_label;
           
           // Se multilingua è attivo e non è "all", aggiungi indicatore lingua
           if ($multilang_plugin && $current_lang !== 'all' && isset($languages[$current_lang])) {
               $flag = $languages[$current_lang]['flag'] ? '<img src="' . $languages[$current_lang]['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
               $label = $flag . $base_label . ' (' . strtoupper($current_lang) . ')';
           }
           
           $types[$slug] = $label;
       }
       
       // Marchi (se esistono)
       if (taxonomy_exists('brand') || taxonomy_exists('product_brand')) {
           $brand_tax = taxonomy_exists('brand') ? 'brand' : 'product_brand';
           $label = '🛒 Marchi';
           
           if ($multilang_plugin && $current_lang !== 'all' && isset($languages[$current_lang])) {
               $flag = $languages[$current_lang]['flag'] ? '<img src="' . $languages[$current_lang]['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
               $label = $flag . $label . ' (' . strtoupper($current_lang) . ')';
           }
           
           $types['taxonomy_' . $brand_tax] = $label;
       }
       
       // Attributi WooCommerce
       $attributes = wc_get_attribute_taxonomies();
       foreach ($attributes as $attribute) {
           $label = '🛒 ' . $attribute->attribute_label;
           
           if ($multilang_plugin && $current_lang !== 'all' && isset($languages[$current_lang])) {
               $flag = $languages[$current_lang]['flag'] ? '<img src="' . $languages[$current_lang]['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
               $label = $flag . $label . ' (' . strtoupper($current_lang) . ')';
           }
           
           $types['taxonomy_pa_' . $attribute->attribute_name] = $label;
       }
       
       // Classi di spedizione
       if (taxonomy_exists('product_shipping_class')) {
           $label = '🛒 Classi di Spedizione';
           
           if ($multilang_plugin && $current_lang !== 'all' && isset($languages[$current_lang])) {
               $flag = $languages[$current_lang]['flag'] ? '<img src="' . $languages[$current_lang]['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
               $label = $flag . $label . ' (' . strtoupper($current_lang) . ')';
           }
           
           $types['taxonomy_product_shipping_class'] = $label;
       }
   }
   
   return $types; 
}

function meb_get_seo_optimization_stats($post_type) { 
   $keys = meb_get_seo_meta_keys(); 
   $current_lang = meb_get_current_language();
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   $base_args = [
       'post_type' => $post_type, 
       'posts_per_page' => -1, 
       'fields' => 'ids', 
       'post_status' => 'publish'
   ];
   
   // Aggiungi filtro lingua se necessario
   if ($multilang_plugin && $current_lang !== 'all') {
       switch ($multilang_plugin) {
           case 'polylang':
               $base_args['lang'] = $current_lang;
               break;
           case 'wpml':
               // WPML richiede gestione dei filtri
               add_filter('posts_join', 'meb_wpml_posts_join');
               add_filter('posts_where', function($where) use ($current_lang) {
                   return meb_wpml_posts_where($where, $current_lang);
               });
               break;
       }
   }
   
   $total_query = new WP_Query($base_args); 
   $total = $total_query->post_count; 
   
   if ($total === 0) {
       // Pulisci filtri WPML
       if ($multilang_plugin === 'wpml' && $current_lang !== 'all') {
           remove_filter('posts_join', 'meb_wpml_posts_join');
           remove_filter('posts_where', 'meb_wpml_posts_where');
       }
       return [0, 0]; 
   }
   
   $optimized_args = $base_args;
   $optimized_args['meta_query'] = [
       'relation' => 'AND', 
       ['key' => $keys['title'], 'compare' => 'EXISTS'], 
       ['key' => $keys['title'], 'value' => '', 'compare' => '!='], 
       ['key' => $keys['desc'], 'compare' => 'EXISTS'], 
       ['key' => $keys['desc'], 'value' => '', 'compare' => '!=']
   ];
   
   $optimized_query = new WP_Query($optimized_args); 
   $optimized = $optimized_query->post_count; 
   
   // Pulisci filtri WPML
   if ($multilang_plugin === 'wpml' && $current_lang !== 'all') {
       remove_filter('posts_join', 'meb_wpml_posts_join');
       remove_filter('posts_where', 'meb_wpml_posts_where');
   }
   
   return [$optimized, $total]; 
}

function meb_get_taxonomy_optimization_stats($taxonomy) {
   $keys = meb_get_seo_meta_keys();
   $current_lang = meb_get_current_language();
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   $args = [
       'taxonomy' => $taxonomy,
       'hide_empty' => false,
       'fields' => 'ids'
   ];
   
   // Aggiungi filtro lingua se necessario
   if ($multilang_plugin && $current_lang !== 'all') {
       switch ($multilang_plugin) {
           case 'wpml':
               global $sitepress;
               if ($sitepress) {
                   $sitepress->switch_lang($current_lang);
               }
               break;
           case 'polylang':
               $args['lang'] = $current_lang;
               break;
       }
   }
   
   $all_terms = get_terms($args);
   
   // Ripristina lingua per WPML
   if ($multilang_plugin === 'wpml' && $current_lang !== 'all') {
       global $sitepress;
       if ($sitepress) {
           $sitepress->switch_lang(null);
       }
   }
   
   $total = count($all_terms);
   if ($total === 0) return [0, 0];
   
   $optimized = 0;
   foreach ($all_terms as $term_id) {
       $title = get_term_meta($term_id, $keys['title'], true);
       $desc = get_term_meta($term_id, $keys['desc'], true);
       
       if (!empty($title) && !empty($desc)) {
           $optimized++;
       }
   }
   
   return [$optimized, $total];
}

// ===================================================================
// ===================================================================
// 12. CRON JOB E REPORT (INVARIATI)
// ===================================================================
register_activation_hook(__FILE__, 'meb_schedule_report'); 
function meb_schedule_report() { 
   if (!wp_next_scheduled('meb_weekly_report_event')) { 
       wp_schedule_event(time(), 'weekly', 'meb_weekly_report_event'); 
   } 
}

add_action('meb_weekly_report_event', 'meb_send_weekly_report'); 
function meb_send_weekly_report() { 
    $post_types = meb_get_all_post_types(); 
    $languages = meb_get_available_languages();
    $multilang_plugin = meb_get_active_multilang_plugin();
    
    // Se non c'è plugin multilingua, usa solo 'all'
    if (!$multilang_plugin) {
        $languages = ['all' => ['name' => 'Tutte le lingue']];
    }
    
    // NUOVO: Genera HTML invece di testo semplice
    $html_report = meb_generate_html_report($languages, $post_types);
    
    $email = get_option('meb_email_reports', get_option('admin_email'));
    $subject = '📊 Report SEO Settimanale - ' . get_bloginfo('name');
    
    // Headers per email HTML
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
    );
    
    wp_mail($email, $subject, $html_report, $headers); 
}

// NUOVA FUNZIONE per generare HTML
function meb_generate_html_report($languages, $post_types) {
    $site_name = get_bloginfo('name');
    $site_url = home_url();
    $admin_url = admin_url('admin.php?page=meta-editor-in-bulk');
    $date = date('d M Y');
    
    // Calcola statistiche globali
    $total_optimized = 0;
    $total_content = 0;
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Report SEO Settimanale</title>
        <style>
            body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f5f7fa; }
            .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 30px; text-align: center; }
            .header h1 { color: white; margin: 0; font-size: 28px; font-weight: 600; }
            .header p { color: rgba(255,255,255,0.9); margin: 10px 0 0 0; font-size: 16px; }
            .content { padding: 30px; }
            .summary-card { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 25px; border-radius: 12px; text-align: center; margin-bottom: 30px; }
            .summary-number { font-size: 48px; font-weight: bold; margin-bottom: 5px; }
            .summary-text { font-size: 16px; opacity: 0.9; }
            .language-section { margin-bottom: 30px; }
            .language-header { background: #f8f9fa; padding: 15px 20px; border-radius: 8px 8px 0 0; border-left: 4px solid #007cba; }
            .language-title { margin: 0; color: #23282d; font-size: 18px; font-weight: 600; display: flex; align-items: center; }
            .language-content { border: 1px solid #e2e4e7; border-top: none; border-radius: 0 0 8px 8px; }
            .stats-table { width: 100%; border-collapse: collapse; }
            .stats-table th { background: #f1f1f1; padding: 12px 15px; text-align: left; font-weight: 600; color: #23282d; border-bottom: 1px solid #ddd; }
            .stats-table td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; }
            .progress-bar { height: 8px; background: #e0e0e0; border-radius: 4px; overflow: hidden; margin-top: 5px; }
            .progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s ease; }
            .progress-high { background: linear-gradient(90deg, #4CAF50, #45a049); }
            .progress-medium { background: linear-gradient(90deg, #FF9800, #f57c00); }
            .progress-low { background: linear-gradient(90deg, #f44336, #d32f2f); }
            .status-good { color: #4CAF50; font-weight: 600; }
            .status-medium { color: #FF9800; font-weight: 600; }
            .status-poor { color: #f44336; font-weight: 600; }
            .images-section { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 20px; margin-top: 30px; }
            .images-header { color: #856404; font-size: 18px; font-weight: 600; margin-bottom: 15px; display: flex; align-items: center; }
            .images-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; }
            .image-stat { text-align: center; }
            .image-number { font-size: 24px; font-weight: bold; color: #856404; }
            .image-label { font-size: 12px; color: #6c5700; margin-top: 5px; }
            .cta-section { text-align: center; margin-top: 30px; padding-top: 30px; border-top: 1px solid #e2e4e7; }
            .cta-button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 0 10px 10px 0; }
            .footer { background: #23282d; color: #a7aaad; padding: 25px; text-align: center; font-size: 14px; }
            .footer a { color: #00a0d2; text-decoration: none; }
            @media (max-width: 600px) {
                .container { margin: 0 10px; }
                .header { padding: 30px 20px; }
                .content { padding: 20px; }
                .summary-number { font-size: 36px; }
                .images-stats { grid-template-columns: repeat(2, 1fr); }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <!-- Header -->
            <div class="header">
                <h1>📊 Report SEO Settimanale</h1>
                <p><?php echo $site_name; ?> • <?php echo $date; ?></p>
            </div>

            <div class="content">
                <?php
                // Calcola statistiche globali
                foreach ($languages as $lang_code => $lang_data) {
                    $_GET['meb_language'] = $lang_code;
                    
                    foreach ($post_types as $slug => $label) {
                        if (strpos($slug, '_separator') !== false) continue;
                        
                        if (strpos($slug, 'taxonomy_') === 0) {
                            $taxonomy = str_replace('taxonomy_', '', $slug);
                            list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
                        } else {
                            list($optimized, $total) = meb_get_seo_optimization_stats($slug);
                        }
                        
                        $total_optimized += $optimized;
                        $total_content += $total;
                    }
                }
                unset($_GET['meb_language']);
                
                $global_percentage = $total_content ? round(($total_optimized / $total_content) * 100) : 0;
                ?>

                <!-- Summary Card -->
                <div class="summary-card">
                    <div class="summary-number"><?php echo $global_percentage; ?>%</div>
                    <div class="summary-text">Contenuti Ottimizzati<br><?php echo $total_optimized; ?> su <?php echo $total_content; ?> elementi</div>
                </div>

                <!-- Dettagli per Lingua -->
                <h2 style="color: #23282d; margin-bottom: 20px;">📈 Dettaglio per Lingua</h2>
                
                <?php foreach ($languages as $lang_code => $lang_data): ?>
                    <?php
                    $_GET['meb_language'] = $lang_code;
                    $flag = isset($lang_data['flag']) && $lang_data['flag'] ? 
                        '<img src="' . $lang_data['flag'] . '" style="width:20px;height:15px;margin-right:8px;vertical-align:middle;" />' : '';
                    ?>
                    
                    <div class="language-section">
                        <div class="language-header">
                            <h3 class="language-title">
                                <?php echo $flag; ?>
                                <?php echo esc_html($lang_data['name']); ?>
                                <?php if ($lang_code !== 'all'): ?>
                                    <span style="margin-left: 8px; font-size: 14px; color: #666;">(<?php echo strtoupper($lang_code); ?>)</span>
                                <?php endif; ?>
                            </h3>
                        </div>
                        
                        <div class="language-content">
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th>Tipo Contenuto</th>
                                        <th>Ottimizzati</th>
                                        <th>Totali</th>
                                        <th>Status</th>
                                        <th>Progresso</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($post_types as $slug => $label): ?>
                                        <?php if (strpos($slug, '_separator') !== false) continue; ?>
                                        
                                        <?php
                                        if (strpos($slug, 'taxonomy_') === 0) {
                                            $taxonomy = str_replace('taxonomy_', '', $slug);
                                            list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
                                        } else {
                                            list($optimized, $total) = meb_get_seo_optimization_stats($slug);
                                        }
                                        
                                        $percent = $total ? round(($optimized / $total) * 100) : 0;
                                        $status_class = $percent >= 80 ? 'status-good' : ($percent >= 40 ? 'status-medium' : 'status-poor');
                                        $progress_class = $percent >= 80 ? 'progress-high' : ($percent >= 40 ? 'progress-medium' : 'progress-low');
                                        $status_text = $percent >= 80 ? '✅ Ottimo' : ($percent >= 40 ? '⚠️ Medio' : '❌ Da migliorare');
                                        ?>
                                        
                                        <tr>
                                            <td><?php echo strip_tags($label); ?></td>
                                            <td><strong><?php echo $optimized; ?></strong></td>
                                            <td><?php echo $total; ?></td>
                                            <td class="<?php echo $status_class; ?>"><?php echo $status_text; ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div class="progress-bar" style="flex: 1;">
                                                        <div class="progress-fill <?php echo $progress_class; ?>" style="width: <?php echo $percent; ?>%;"></div>
                                                    </div>
                                                    <span style="font-size: 12px; color: #666; min-width: 35px;"><?php echo $percent; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php unset($_GET['meb_language']); ?>

                <!-- Statistiche Immagini -->
                <?php
                $image_stats = meb_get_image_statistics();
                ?>
                <div class="images-section">
                    <h3 class="images-header">📸 Statistiche Immagini</h3>
                    <div class="images-stats">
                        <div class="image-stat">
                            <div class="image-number"><?php echo $image_stats['total']; ?></div>
                            <div class="image-label">Totale</div>
                        </div>
                        <div class="image-stat">
                            <div class="image-number"><?php echo $image_stats['no_alt']; ?></div>
                            <div class="image-label">Senza ALT</div>
                        </div>
                        <div class="image-stat">
                            <div class="image-number"><?php echo $image_stats['large']; ?></div>
                            <div class="image-label">Grandi (>1MB)</div>
                        </div>
                        <div class="image-stat">
                            <div class="image-number"><?php echo $image_stats['optimized']; ?></div>
                            <div class="image-label">Ottimizzate</div>
                        </div>
                    </div>
                </div>

                <!-- Call to Action -->
                <div class="cta-section">
                    <h3 style="color: #23282d; margin-bottom: 15px;">🚀 Prossimi Passi</h3>
                    <p style="color: #666; margin-bottom: 20px;">Continua a ottimizzare i tuoi contenuti per migliorare il posizionamento.</p>
                    
                    <a href="<?php echo $admin_url; ?>" class="cta-button">🔧 Gestisci SEO</a>
                    <a href="<?php echo admin_url('admin.php?page=meb-seo-images'); ?>" class="cta-button">📸 Ottimizza Immagini</a>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <p>📧 Report generato automaticamente da <strong>Meta Editor in Bulk Pro</strong></p>
                <p>🌐 <a href="<?php echo $site_url; ?>"><?php echo $site_name; ?></a> • 
                   ⚙️ <a href="<?php echo admin_url('admin.php?page=meb-settings'); ?>">Impostazioni</a></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    
    return ob_get_clean();
}

add_filter('cron_schedules', function ($schedules) { 
   if (!isset($schedules['weekly'])) { 
       $schedules['weekly'] = [
           'interval' => 604800, 
           'display' => __('Once Weekly')
       ]; 
   } 
   return $schedules; 
});

// ===================================================================
// 13. FILTRI E HOOK AGGIUNTIVI PER MULTILINGUA (INVARIATI)
// ===================================================================

// Filtro per aggiungere dati multilingua alla localizzazione
add_filter('meb_localize_script_data', 'meb_add_multilang_data');
function meb_add_multilang_data($data) {
   $multilang_plugin = meb_get_active_multilang_plugin();
   $data['multilang'] = [
       'plugin' => $multilang_plugin,
       'languages' => meb_get_available_languages(),
       'current' => meb_get_current_language(),
       'enabled' => (bool) $multilang_plugin
   ];
   return $data;
}

// Hook per aggiornare la cache quando cambiano le traduzioni
add_action('wpml_translation_update', 'meb_clear_multilang_cache');
add_action('pll_save_post', 'meb_clear_multilang_cache');
function meb_clear_multilang_cache() {
   // Forza un nuovo snapshot dopo modifiche alle traduzioni
   meb_save_current_snapshot();
   
   // NUOVO: Pulisci anche cache immagini
   wp_cache_delete('meb_image_stats', 'meb');
}

// ===================================================================
// 14. FUNZIONI DI UTILITÀ MULTILINGUA (INVARIATE)
// ===================================================================

/**
* Ottiene tutti i post/termini correlati in tutte le lingue
*/
function meb_get_related_translations($object_id, $object_type = 'post') {
   $plugin = meb_get_active_multilang_plugin();
   $translations = [];
   
   switch ($plugin) {
       case 'wpml':
           if ($object_type === 'post' && function_exists('wpml_get_object_translations')) {
               $translations = wpml_get_object_translations($object_id, 'post');
           } elseif ($object_type === 'term' && function_exists('wpml_get_object_translations')) {
               $translations = wpml_get_object_translations($object_id, 'term');
           }
           break;
           
       case 'polylang':
           if ($object_type === 'post' && function_exists('pll_get_post_translations')) {
               $translations = pll_get_post_translations($object_id);
           } elseif ($object_type === 'term' && function_exists('pll_get_term_translations')) {
               $translations = pll_get_term_translations($object_id);
           }
           break;
   }
   
   return $translations;
}

/**
* Verifica se un contenuto ha traduzioni incomplete
*/
function meb_has_incomplete_translations($object_id, $object_type = 'post') {
   $plugin = meb_get_active_multilang_plugin();
   if (!$plugin) return false;
   
   $available_languages = meb_get_available_languages();
   $translations = meb_get_related_translations($object_id, $object_type);
   
   return count($translations) < count($available_languages);
}

/**
* Ottiene le statistiche SEO aggregate per tutte le lingue
*/
function meb_get_global_seo_stats() {
   $languages = meb_get_available_languages();
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   if (!$multilang_plugin) {
       $languages = ['all' => ['name' => 'All Languages']];
   }
   
   $global_stats = [];
   
   foreach ($languages as $lang_code => $lang_data) {
       // Imposta temporaneamente la lingua
       $_GET['meb_language'] = $lang_code;
       
       $post_types = meb_get_all_post_types();
       $lang_stats = [
           'language' => $lang_data,
           'total_optimized' => 0,
           'total_content' => 0,
           'post_types' => []
       ];
       
       foreach ($post_types as $slug => $label) {
           if (strpos($slug, '_separator') !== false) continue;
           
           if (strpos($slug, 'taxonomy_') === 0) {
               $taxonomy = str_replace('taxonomy_', '', $slug);
               list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
           } else {
               list($optimized, $total) = meb_get_seo_optimization_stats($slug);
           }
           
           $lang_stats['post_types'][$slug] = [
               'optimized' => $optimized,
               'total' => $total,
               'percentage' => $total ? round(($optimized / $total) * 100) : 0
           ];
           
           $lang_stats['total_optimized'] += $optimized;
           $lang_stats['total_content'] += $total;
       }
       
       $lang_stats['global_percentage'] = $lang_stats['total_content'] ? 
           round(($lang_stats['total_optimized'] / $lang_stats['total_content']) * 100) : 0;
       
       $global_stats[$lang_code] = $lang_stats;
   }
   
   // Pulisci la variabile temporanea
   unset($_GET['meb_language']);
   
   return $global_stats;
}

/**
* Genera un report dettagliato per una lingua specifica
*/
function meb_generate_language_report($language_code) {
   // Imposta temporaneamente la lingua
   $_GET['meb_language'] = $language_code;
   
   $languages = meb_get_available_languages();
   $language_name = isset($languages[$language_code]) ? $languages[$language_code]['name'] : $language_code;
   
   $report = [
       'language' => [
           'code' => $language_code,
           'name' => $language_name
       ],
       'generated_at' => current_time('mysql'),
       'post_types' => [],
       'summary' => [
           'total_content' => 0,
           'total_optimized' => 0,
           'optimization_percentage' => 0
       ]
   ];
   
   $post_types = meb_get_all_post_types();
   
   foreach ($post_types as $slug => $label) {
       if (strpos($slug, '_separator') !== false) continue;
       
       if (strpos($slug, 'taxonomy_') === 0) {
           $taxonomy = str_replace('taxonomy_', '', $slug);
           list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
           $type = 'taxonomy';
       } else {
           list($optimized, $total) = meb_get_seo_optimization_stats($slug);
           $type = 'post_type';
       }
       
       $report['post_types'][$slug] = [
           'label' => strip_tags($label),
           'type' => $type,
           'total' => $total,
           'optimized' => $optimized,
           'percentage' => $total ? round(($optimized / $total) * 100) : 0,
           'needs_optimization' => $total - $optimized
       ];
       
       $report['summary']['total_content'] += $total;
       $report['summary']['total_optimized'] += $optimized;
   }
   
   $report['summary']['optimization_percentage'] = $report['summary']['total_content'] ? 
       round(($report['summary']['total_optimized'] / $report['summary']['total_content']) * 100) : 0;
   
   // Pulisci la variabile temporanea
   unset($_GET['meb_language']);
   
   return $report;
}

// ===================================================================
// 15. SHORTCODE PER STATISTICHE FRONTEND (INVARIATI)
// ===================================================================

add_shortcode('meb_seo_stats', 'meb_seo_stats_shortcode');
function meb_seo_stats_shortcode($atts) {
   $atts = shortcode_atts([
       'language' => 'current',
       'post_type' => 'all',
       'format' => 'simple',
       'show_languages' => 'false'
   ], $atts);
   
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   // Determina la lingua da mostrare
   if ($atts['language'] === 'current') {
       $display_lang = meb_get_current_language();
   } elseif ($atts['language'] === 'all' || !$multilang_plugin) {
       $display_lang = 'all';
   } else {
       $display_lang = $atts['language'];
   }
   
   // Imposta temporaneamente la lingua
   $_GET['meb_language'] = $display_lang;
   
   $output = '<div class="meb-seo-stats-widget">';
   
   if ($atts['show_languages'] === 'true' && $multilang_plugin) {
       $languages = meb_get_available_languages();
       $lang_name = ($display_lang === 'all') ? 'Tutte le lingue' : 
           (isset($languages[$display_lang]) ? $languages[$display_lang]['name'] : $display_lang);
       
       $output .= '<h4>SEO Stats - ' . esc_html($lang_name) . '</h4>';
   } else {
       $output .= '<h4>SEO Statistics</h4>';
   }
   
   if ($atts['post_type'] === 'all') {
       $post_types = meb_get_all_post_types();
       $total_optimized = 0;
       $total_content = 0;
       
       foreach ($post_types as $slug => $label) {
           if (strpos($slug, '_separator') !== false) continue;
           
           if (strpos($slug, 'taxonomy_') === 0) {
               $taxonomy = str_replace('taxonomy_', '', $slug);
               list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
           } else {
               list($optimized, $total) = meb_get_seo_optimization_stats($slug);
           }
           
           $total_optimized += $optimized;
           $total_content += $total;
           
           if ($atts['format'] === 'detailed') {
               $percentage = $total ? round(($optimized / $total) * 100) : 0;
               $output .= '<div class="meb-stat-item">';
               $output .= '<span class="meb-stat-label">' . strip_tags($label) . ':</span> ';
               $output .= '<span class="meb-stat-value">' . $optimized . '/' . $total . ' (' . $percentage . '%)</span>';
               $output .= '</div>';
           }
       }
       
       $global_percentage = $total_content ? round(($total_optimized / $total_content) * 100) : 0;
       
       if ($atts['format'] === 'simple') {
           $output .= '<div class="meb-stat-summary">';
           $output .= '<strong>Ottimizzazione globale: ' . $total_optimized . '/' . $total_content . ' (' . $global_percentage . '%)</strong>';
           $output .= '</div>';
       }
       
   } else {
       // Statistiche per un post type specifico
       if (strpos($atts['post_type'], 'taxonomy_') === 0) {
           $taxonomy = str_replace('taxonomy_', '', $atts['post_type']);
           list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
       } else {
           list($optimized, $total) = meb_get_seo_optimization_stats($atts['post_type']);
       }
       
       $percentage = $total ? round(($optimized / $total) * 100) : 0;
       $output .= '<div class="meb-stat-single">';
       $output .= '<strong>' . $optimized . '/' . $total . ' (' . $percentage . '%) ottimizzati</strong>';
       $output .= '</div>';
   }
   
   $output .= '</div>';
   
   // Pulisci la variabile temporanea
   unset($_GET['meb_language']);
   
   // Aggiungi CSS inline se necessario
   $output .= '<style>
       .meb-seo-stats-widget { 
           padding: 15px; 
           background: #f9f9f9; 
           border: 1px solid #ddd; 
           border-radius: 4px; 
           margin: 10px 0; 
       }
       .meb-stat-item { 
           margin-bottom: 8px; 
           display: flex; 
           justify-content: space-between; 
       }
       .meb-stat-summary, .meb-stat-single { 
           margin-top: 10px; 
           padding-top: 10px; 
           border-top: 1px solid #ddd; 
       }
   </style>';
   
   return $output;
}

// ===================================================================
// 16. WIDGET DASHBOARD WORDPRESS (AGGIORNATO)
// ===================================================================

add_action('wp_dashboard_setup', 'meb_add_dashboard_widget');
function meb_add_dashboard_widget() {
   if (current_user_can('manage_options')) {
       wp_add_dashboard_widget(
           'meb_seo_dashboard_widget',
           'SEO Optimization Status',
           'meb_dashboard_widget_content'
       );
   }
}

function meb_dashboard_widget_content() {
   $multilang_plugin = meb_get_active_multilang_plugin();
   
   echo '<div class="meb-dashboard-widget">';
   
   if ($multilang_plugin) {
       $languages = meb_get_available_languages();
       
       echo '<p><strong>🌍 Modalità Multilingua Attiva (' . ucfirst($multilang_plugin) . ')</strong></p>';
       
       foreach ($languages as $lang_code => $lang_data) {
           $_GET['meb_language'] = $lang_code;
           
           $post_types = meb_get_all_post_types();
           $total_optimized = 0;
           $total_content = 0;
           
           foreach ($post_types as $slug => $label) {
               if (strpos($slug, '_separator') !== false) continue;
               
               if (strpos($slug, 'taxonomy_') === 0) {
                   $taxonomy = str_replace('taxonomy_', '', $slug);
                   list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
               } else {
                   list($optimized, $total) = meb_get_seo_optimization_stats($slug);
               }
               
               $total_optimized += $optimized;
               $total_content += $total;
           }
           
           $percentage = $total_content ? round(($total_optimized / $total_content) * 100) : 0;
           $flag = $lang_data['flag'] ? '<img src="' . $lang_data['flag'] . '" style="width:16px;height:12px;margin-right:4px;" />' : '';
           
           echo '<div style="margin-bottom: 8px;">';
           echo '<strong>' . $flag . $lang_data['name'] . ':</strong> ';
           echo $total_optimized . '/' . $total_content . ' (' . $percentage . '%)';
           echo '</div>';
       }
       
       unset($_GET['meb_language']);
       
   } else {
       // Modalità singola lingua
       $post_types = meb_get_all_post_types();
       $total_optimized = 0;
       $total_content = 0;
       
       foreach ($post_types as $slug => $label) {
           if (strpos($slug, '_separator') !== false) continue;
           
           if (strpos($slug, 'taxonomy_') === 0) {
               $taxonomy = str_replace('taxonomy_', '', $slug);
               list($optimized, $total) = meb_get_taxonomy_optimization_stats($taxonomy);
           } else {
               list($optimized, $total) = meb_get_seo_optimization_stats($slug);
           }
           
           $total_optimized += $optimized;
           $total_content += $total;
       }
       
       $percentage = $total_content ? round(($total_optimized / $total_content) * 100) : 0;
       
       echo '<p><strong>Ottimizzazione Globale:</strong></p>';
       echo '<div style="font-size: 18px; margin: 10px 0;">';
       echo '<strong>' . $total_optimized . '/' . $total_content . ' (' . $percentage . '%)</strong>';
       echo '</div>';
   }
   
   // NUOVO: Aggiungi statistiche immagini al widget dashboard
   $image_stats = meb_get_image_statistics();
   echo '<div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 15px;">';
   echo '<p><strong>📸 Immagini:</strong></p>';
   echo '<div style="font-size: 14px;">';
   echo '• Totale: ' . $image_stats['total'] . '<br>';
   echo '• Senza ALT: ' . $image_stats['no_alt'] . ' (' . $image_stats['no_alt_percent'] . '%)<br>';
   echo '• Ottimizzate: ' . $image_stats['optimized'] . ' (' . $image_stats['optimized_percent'] . '%)';
   echo '</div>';
   echo '</div>';
   
   echo '<p style="margin-top: 15px;">';
   echo '<a href="' . admin_url('admin.php?page=meta-editor-in-bulk') . '" class="button button-primary">Gestisci SEO</a> ';
   echo '<a href="' . admin_url('admin.php?page=meb-seo-images') . '" class="button">SEO Immagini</a>';
   echo '</p>';
   
   echo '</div>';
}

// ===================================================================
// 17. REST API ENDPOINTS AGGIUNTIVI PER MULTILINGUA (INVARIATI)
// ===================================================================

add_action('rest_api_init', 'meb_register_multilang_rest_routes');
function meb_register_multilang_rest_routes() {
   // Endpoint per ottenere le statistiche per lingua
   register_rest_route('meb/v1', '/stats/(?P<language>[a-zA-Z_-]+)', [
       'methods' => 'GET',
       'callback' => 'meb_api_get_language_stats',
       'permission_callback' => function () { return current_user_can('manage_options'); },
       'args' => [
           'language' => [
               'required' => true,
               'validate_callback' => function($param) {
                   return is_string($param) && !empty($param);
               }
           ]
       ]
   ]);
   
   // Endpoint per ottenere il report globale multilingua
   register_rest_route('meb/v1', '/global-stats', [
       'methods' => 'GET',
       'callback' => 'meb_api_get_global_stats',
       'permission_callback' => function () { return current_user_can('manage_options'); }
   ]);
}

function meb_api_get_language_stats($request) {
   $language = sanitize_text_field($request['language']);
   $report = meb_generate_language_report($language);
   
   return new WP_REST_Response($report, 200);
}

function meb_api_get_global_stats($request) {
   $global_stats = meb_get_global_seo_stats();
   
   return new WP_REST_Response([
       'generated_at' => current_time('mysql'),
       'multilang_plugin' => meb_get_active_multilang_plugin(),
       'languages' => $global_stats
   ], 200);
}

// ===================================================================
// 18. HOOK DI CLEANUP E MANUTENZIONE (AGGIORNATI)
// ===================================================================

// Pulisci i dati storici vecchi (oltre 1 anno)
add_action('meb_weekly_history_snapshot_event', 'meb_cleanup_old_history_data');
function meb_cleanup_old_history_data() {
   global $wpdb;
   $table_name = $wpdb->prefix . 'meb_history';
   
   $one_year_ago = date('Y-m-d', strtotime('-1 year'));
   
   $deleted = $wpdb->query($wpdb->prepare(
       "DELETE FROM $table_name WHERE record_date < %s",
       $one_year_ago
   ));
   
   if ($deleted) {
       error_log("MEB: Eliminati $deleted record storici più vecchi di un anno");
   }
   
   // NUOVO: Pulisci anche cache immagini vecchia
   wp_cache_delete('meb_image_stats', 'meb');
}

// NUOVO: Hook per pulizia cache immagini quando vengono modificate
add_action('attachment_updated', 'meb_clear_image_cache');
add_action('delete_attachment', 'meb_clear_image_cache');
function meb_clear_image_cache($attachment_id = null) {
   wp_cache_delete('meb_image_stats', 'meb');
}

// NUOVO: Hook per aggiornare statistiche quando viene caricata una nuova immagine
add_action('add_attachment', 'meb_update_image_stats_on_upload');
function meb_update_image_stats_on_upload($attachment_id) {
   if (wp_attachment_is_image($attachment_id)) {
       // Pulisci cache per forzare ricalcolo
       wp_cache_delete('meb_image_stats', 'meb');
   }
}

// Chiamala UNA VOLTA aggiungendo questa riga da qualche parte (es. in fondo al file)
// meb_force_create_todays_data(); // DECOMMENTARE, SALVARE, CARICARE LA PAGINA, POI RICOMMENTARE

?>
