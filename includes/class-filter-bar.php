<?php
namespace TEC_Addons;
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Filter_Bar {
    public static function init(){
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
        add_shortcode('tec_addons_filter',[__CLASS__,'shortcode']);
        if (get_option('tec_addons_enable_filter_autoinject','yes')==='yes'){
            add_filter('tribe_events_before_html',[__CLASS__,'inject_before_view'],10,2);
            add_filter('the_content',[__CLASS__,'inject_before_shortcode_in_content'],9);
        }
        add_action('parse_query',[__CLASS__,'apply_tax_filter_parse']);
        add_action('pre_get_posts',[__CLASS__,'apply_tax_filter']);
        add_filter('tribe_events_pre_get_posts',[__CLASS__,'apply_tax_filter']);
        add_filter('tribe_events_query_args',[__CLASS__,'apply_tax_to_args']);
    }
    public static function assets(){
        wp_register_style('tec-addons-filter',TEC_ADDONS_URL . 'assets/css/filter.css',[],TEC_ADDONS_VERSION);
        wp_register_script('tec-addons-filter',TEC_ADDONS_URL . 'assets/js/filter.js',['jquery'],TEC_ADDONS_VERSION,true);
        if(get_option('tec_addons_enable_filter_autoinject','yes')==='yes'){ wp_enqueue_style('tec-addons-filter'); wp_enqueue_script('tec-addons-filter'); }
    }
    protected static function future_count_for_term($term_id){
        $now=current_time('mysql');
        $q=new \WP_Query([
            'post_type'=>'tribe_events','post_status'=>'publish','fields'=>'ids','no_found_rows'=>true,'posts_per_page'=>-1,
            'tax_query'=>[['taxonomy'=>'tribe_events_cat','field'=>'term_id','terms'=>[$term_id]]],
            'meta_query'=>[['key'=>'_EventStartDate','value'=>$now,'compare'=>'>=','type'=>'DATETIME']]
        ]);
        return is_array($q->posts)?count($q->posts):0;
    }
    protected static function render_filter_html($atts=[]){
        $atts=shortcode_atts(['title'=>get_option('tec_addons_filter_title',__('Bitte auswählen:','tec-add-ons')),'show_counts'=>get_option('tec_addons_filter_show_counts','yes')],$atts,'tec_addons_filter');
        $bg=get_option('tec_addons_filter_bg','transparent'); $p_slug=get_option('tec_addons_pinned_category_slug','eigene-veranstaltungen'); $p_name=get_option('tec_addons_pinned_category_name','eigene Veranstaltungen');
        $terms=get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false]); if(is_wp_error($terms)||empty($terms)){ return '<div class="tec-filter-bar"><em>'.esc_html__('Keine Kategorien vorhanden.','tec-add-ons').'</em></div>'; }
        $selected=isset($_GET['tec_cat'])? array_filter(array_map('sanitize_title', explode(',', wp_unslash($_GET['tec_cat'])))) : [];
        $idx=null; foreach($terms as $i=>$t){ if (strcasecmp($t->name,$p_name)===0 || $t->slug===$p_slug){ $idx=$i; break; } } if($idx!==null){ $p=$terms[$idx]; unset($terms[$idx]); array_unshift($terms,$p); }
        ob_start(); ?>
        <aside class="tec-filter-bar" aria-label="<?php echo esc_attr__('Event-Filter','tec-add-ons'); ?>" style="background: <?php echo esc_attr($bg); ?>">
            <div class="tec-filter-header"><h3><?php echo esc_html($atts['title']); ?></h3>
                <div class="tec-filter-actions tec-filter-actions--top"><button type="button" class="tec-filter-apply"><?php echo esc_html__('Anwenden','tec-add-ons'); ?></button><button type="button" class="tec-filter-clear"><?php echo esc_html__('Zurücksetzen','tec-add-ons'); ?></button></div>
            </div>
            <form class="tec-filter-form" action="" method="get">
                <input type="hidden" name="tec_cat" class="tec-cat-hidden" value="<?php echo esc_attr(implode(',',$selected)); ?>" />
                <div class="tec-filter-options">
                    <?php $rendered=false; foreach($terms as $term): $count=($atts['show_counts']==='yes')? self::future_count_for_term($term->term_id):null; $is_p=(strcasecmp($term->name,$p_name)===0||$term->slug===$p_slug); if($atts['show_counts']==='yes' && $count===0 && !$is_p){ continue; } $checked=in_array($term->slug,$selected,true); if(empty($selected)&&$is_p){ $checked=true; } $rendered=true; ?>
                    <label class="tec-filter-option"><input name="tec_cat_box" type="checkbox" class="tec-filter-checkbox" value="<?php echo esc_attr($term->slug); ?>" <?php checked($checked); ?> /><span class="tec-filter-name"><?php echo esc_html($term->name); ?></span><?php if($count!==null): ?><span class="tec-filter-count">(<?php echo intval($count); ?>)</span><?php endif; ?></label>
                    <?php endforeach; if(!$rendered): ?><div class="tec-filter-empty"><?php echo esc_html__('Aktuell keine Kategorien mit zukünftigen Veranstaltungen.','tec-add-ons'); ?></div><?php endif; ?>
                </div>
                <div class="tec-filter-actions tec-filter-actions--bottom"><button type="button" class="tec-filter-apply"><?php echo esc_html__('Anwenden','tec-add-ons'); ?></button><button type="button" class="tec-filter-clear"><?php echo esc_html__('Zurücksetzen','tec-add-ons'); ?></button></div>
            </form>
        </aside>
        <?php return ob_get_clean();
    }
    public static function inject_before_view($before, $view = null){
        if ( is_singular('tribe_events') ) { return $before; }
        wp_enqueue_style('tec-addons-filter'); wp_enqueue_script('tec-addons-filter');
        return self::render_filter_html().$before;
    }
    public static function inject_before_shortcode_in_content($content){
        if(false!==stripos($content,'[tribe_events')){ wp_enqueue_style('tec-addons-filter'); wp_enqueue_script('tec-addons-filter'); return self::render_filter_html().$content; } return $content;
    }
    public static function shortcode($atts,$content=''){ return self::render_filter_html($atts); }
    public static function apply_tax_filter_parse($query){
        if(is_admin()||!$query instanceof \WP_Query) return;
        $pt=$query->get('post_type'); $is_events=((is_array($pt)&&in_array('tribe_events',$pt,true))||$pt==='tribe_events');
        if(!$is_events && function_exists('tribe_is_event_query') && !tribe_is_event_query()) return;
        $raw=isset($_GET['tec_cat'])? wp_unslash($_GET['tec_cat']) : ''; if(empty($raw)) return;
        $slugs=array_filter(array_map('sanitize_title', explode(',', $raw))); if(empty($slugs)) return;
        $tax=(array)$query->get('tax_query'); $tax[]=['taxonomy'=>'tribe_events_cat','field'=>'slug','terms'=>$slugs,'operator'=>'IN']; $query->set('tax_query',$tax);
    }
    public static function apply_tax_filter($query){
        if(is_admin()||!$query instanceof \WP_Query) return;
        if(function_exists('tribe_is_event_query') && !tribe_is_event_query()) return;
        $raw=isset($_GET['tec_cat'])? wp_unslash($_GET['tec_cat']) : ''; if(empty($raw)) return;
        $slugs=array_filter(array_map('sanitize_title', explode(',', $raw))); if(empty($slugs)) return;
        $tax=(array)$query->get('tax_query'); $tax[]=['taxonomy'=>'tribe_events_cat','field'=>'slug','terms'=>$slugs,'operator'=>'IN']; $query->set('tax_query',$tax);
    }
    public static function apply_tax_to_args($args){
        $raw=isset($_GET['tec_cat'])? wp_unslash($_GET['tec_cat']) : ''; if(empty($raw)) return $args;
        $slugs=array_filter(array_map('sanitize_title', explode(',', $raw))); if(empty($slugs)) return $args;
        if(!isset($args['tax_query'])) $args['tax_query']=[]; $args['tax_query'][]=['taxonomy'=>'tribe_events_cat','field'=>'slug','terms'=>$slugs,'operator'=>'IN']; return $args;
    }
}