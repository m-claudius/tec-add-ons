<?php
namespace TEC_Addons\Community;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Mapping', false)) { return; }

/**
 * Community-Mapping: Tabelle tec_addons_submitters,
 * Zuordnung WP-User ↔ Standard-Quelle inkl. Whitelist.
 */
class Mapping {

    public static function maybe_install_tables(){
        global $wpdb; $table = $wpdb->prefix.'tec_addons_submitters';
        $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
        if ( $exists === $table ) return;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL UNIQUE,
            source_term_id BIGINT UNSIGNED NULL,
            whitelist TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY source_idx (source_term_id)
        ) {$charset};";
        dbDelta($sql);
    }

    public static function admin_save_mapping(){
        if ( ! current_user_can('manage_options') ) wp_die('no');
        check_admin_referer('tec_addons_comm_save','_tec_comm_nonce');
        Sources::ensure_default_source_exists();
        $user_id = absint($_POST['user_id'] ?? 0);
        $term_id = absint($_POST['source_term_id'] ?? 0);
        $whitelist = ! empty($_POST['whitelist']) ? 1 : 0;
        if ( ! $user_id ) wp_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-community') );
        global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
        self::maybe_install_tables();
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d",$user_id) );
        $data = [
            'user_id'=>$user_id,
            'source_term_id'=>$term_id ?: null,
            'whitelist'=>$whitelist,
            'updated_at'=>current_time('mysql'),
        ];
        if ( $row ) { $wpdb->update($table,$data,['user_id'=>$user_id]); }
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($table,$data); }
        wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
    }
    public static function admin_delete_mapping(){
        if ( ! current_user_can('manage_options') ) wp_die('no');
        $user_id = absint($_GET['user_id'] ?? 0);
        check_admin_referer('tec_addons_comm_delete_'.$user_id);
        if ( $user_id ){
            global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
            $wpdb->delete($table,['user_id'=>$user_id]);
        }
        wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
    }

    public static function get_all_mappings(){
        global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
        self::maybe_install_tables();
        $out=[];
        $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC");
        if($rows){
            foreach($rows as $r){
                $u = get_user_by('id',$r->user_id);
                $term = $r->source_term_id ? get_term($r->source_term_id,'event_source') : null;
                if(!$u) continue;
                $out[]=[
                    'user_id'=>$r->user_id,
                    'display_name'=>$u->display_name,
                    'user_email'=>$u->user_email,
                    'source_term'=>$term && !is_wp_error($term) ? $term : null,
                    'whitelist'=> (int)$r->whitelist,
                ];
            }
        }
        return $out;
    }
    public static function get_source_for_user($user_id){
        // Nutzerzuordnung oder Default-Quelle
        global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d",$user_id) );
        if($row){
            return [ $row->source_term_id ? get_term( (int)$row->source_term_id, 'event_source') : null, !!$row->whitelist ];
        }
        // Fallback: Default-Quelle
        Sources::ensure_default_source_exists();
        $term = get_term_by('name', \TEC_Addons\Community::DEFAULT_SOURCE_NAME, 'event_source');
        return [$term ?: null, false];
    }
}
