<?php
namespace TEC_Addons\Community;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Sources', false)) { return; }

/**
 * Community-Quellen: Taxonomie event_source, Default-Quelle,
 * Lookups (Quellen/Kategorien/Orte/Veranstalter) und Admin-Aktionen.
 */
class Sources {

    public static function register_taxonomies(){
        if ( ! taxonomy_exists('event_source') ){
            register_taxonomy('event_source', ['tribe_events'], [
                'label' => __('Quellen','tec-add-ons'),
                'public' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'hierarchical' => false,
                'rewrite' => false,
                'show_in_rest' => true,
            ]);
        }
        // Default-Quelle sicherstellen
        self::ensure_default_source_exists();
    }

    public static function ensure_default_source_exists(){
        if ( ! taxonomy_exists('event_source') ) return;
        $term = term_exists(\TEC_Addons\Community::DEFAULT_SOURCE_NAME, 'event_source');
        if ( ! $term ){
            wp_insert_term(\TEC_Addons\Community::DEFAULT_SOURCE_NAME, 'event_source');
        }
    }

    /* ==== Admin Actions: Quellen hinzufügen / syncen ==== */
    public static function admin_add_source(){
        if ( ! current_user_can('manage_options') ) wp_die('no');
        check_admin_referer('tec_addons_comm_add_source','_tec_comm_src_nonce');
        $name = sanitize_text_field($_POST['new_source_name'] ?? '');
        if($name){
            self::ensure_default_source_exists();
            if ( ! term_exists($name,'event_source') ){
                wp_insert_term($name,'event_source');
            }
        }
        wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
    }
    public static function admin_sync_sources(){
        if ( ! current_user_can('manage_options') ) wp_die('no');
        check_admin_referer('tec_addons_comm_sync_sources','_tec_comm_sync_nonce');
        self::ensure_default_source_exists();
        $csv = get_option('tec_addons_sources_custom','');
        $list = array_filter(array_map('trim', explode(',',$csv)));
        foreach($list as $name){
            if ( $name && ! term_exists($name,'event_source') ){
                wp_insert_term($name,'event_source');
            }
        }
        wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
    }

    /* ==== Lookups ==== */
    public static function get_all_sources(){
        self::ensure_default_source_exists();
        $terms = get_terms(['taxonomy'=>'event_source','hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
        return is_array($terms) ? $terms : [];
    }
    public static function get_all_categories(){
        $terms = get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false]);
        return is_array($terms) ? $terms : [];
    }
    public static function get_all_venues(){
        $q = new \WP_Query(['post_type'=>'tribe_venue','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
        return $q->posts;
    }
    public static function get_all_organizers(){
        $q = new \WP_Query(['post_type'=>'tribe_organizer','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
        return $q->posts;
    }
    public static function find_organizer_by_name($name){
        $post = get_page_by_title($name, OBJECT, 'tribe_organizer');
        return $post ? $post->ID : 0;
    }
}
