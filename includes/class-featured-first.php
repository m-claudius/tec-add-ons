<?php
namespace TEC_Addons;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Featured_First {
    public static function init(){
        if ( get_option('tec_addons_enable_featured_first','yes')!=='yes') return;
        add_filter('posts_clauses',[__CLASS__,'sql_order_featured_first'],100,2);
        add_filter('tribe_events_query_posts_clauses',[__CLASS__,'sql_order_featured_first'],100,2);
        add_filter('the_posts',[__CLASS__,'reorder_featured_first'],100,2);
    }
    protected static function is_events_query($query){
        if (function_exists('tribe_is_event_query') && tribe_is_event_query()) return true;
        $pt=$query->get('post_type'); if(empty($pt)) return false;
        if (is_array($pt)) return in_array('tribe_events',$pt,true);
        return $pt==='tribe_events';
    }
    public static function sql_order_featured_first($clauses,$query){
        if (is_admin()) return $clauses; if (!self::is_events_query($query)) return $clauses;
        global $wpdb; $join=$wpdb->prepare(" LEFT JOIN {$wpdb->postmeta} AS tec_addons_featured ON ({$wpdb->posts}.ID = tec_addons_featured.post_id AND tec_addons_featured.meta_key = %s) ", '_tribe_featured');
        if (strpos($clauses['join'],'tec_addons_featured')===false){ $clauses['join'].=$join; }
        $case=" CASE WHEN tec_addons_featured.meta_value = '1' THEN 0 ELSE 1 END ASC ";
        $clauses['orderby']=$case.( !empty($clauses['orderby']) ? ', '.$clauses['orderby'] : '' );
        return $clauses;
    }
    public static function reorder_featured_first($posts,$query){
        if (is_admin()||empty($posts)) return $posts; if (!self::is_events_query($query)) return $posts;
        $f=[];$n=[]; foreach($posts as $p){ if (get_post_type($p)!=='tribe_events'){ $n[]=$p; continue; } $is=get_post_meta($p->ID,'_tribe_featured',true); if((string)$is==='1') $f[]=$p; else $n[]=$p; } return array_merge($f,$n);
    }
}