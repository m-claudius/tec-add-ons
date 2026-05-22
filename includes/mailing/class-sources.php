<?php
namespace TEC_Addons\Mailing;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Sources', false)) { return; }

class Sources {

    public static function get_active_subscribers(string $frequency): array {
        global $wpdb;
        $table=$wpdb->prefix.'tec_addons_subscribers';
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} WHERE status=%s AND frequency=%s", 'active', $frequency) );
        return is_array($rows) ? $rows : [];
    }

    public static function get_quelle_parent_id(): int {
        if (!taxonomy_exists('tribe_events_cat')) return 0;
        $t = get_term_by('name','Quelle','tribe_events_cat');
        return ($t && !is_wp_error($t)) ? (int)$t->term_id : 0;
    }

    public static function map_sources_to_term_ids(array $sources): array {
        $sources = array_values(array_filter(array_map('trim',$sources)));
        $out = ['event_source'=>[], 'tribe_quelle'=>[]];
        if (empty($sources)) return $out;

        if (taxonomy_exists('event_source')) {
            $terms = get_terms(['taxonomy'=>'event_source','hide_empty'=>false,'fields'=>'ids','name__in'=>$sources]);
            if (!is_wp_error($terms) && !empty($terms)) $out['event_source'] = array_map('intval',$terms);
        }
        if (taxonomy_exists('tribe_events_cat')) {
            $pid = self::get_quelle_parent_id();
            if ($pid) {
                $kids = get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false,'fields'=>'ids','name__in'=>$sources,'parent'=>$pid]);
                if (!is_wp_error($kids) && !empty($kids)) $out['tribe_quelle'] = array_map('intval',$kids);
            }
        }
        return $out;
    }

    public static function fetch_events(string $start_mysql, string $end_mysql, array $filter): array {
        $tax_query = ['relation'=>'AND'];
        if (!empty($filter['event_source'])) {
            $tax_query[] = [
                'taxonomy' => 'event_source',
                'field'    => 'term_id',
                'terms'    => $filter['event_source'],
                'include_children' => true,
                'operator' => 'IN',
            ];
        }
        if (!empty($filter['tribe_quelle'])) {
            $tax_query[] = [
                'taxonomy' => 'tribe_events_cat',
                'field'    => 'term_id',
                'terms'    => $filter['tribe_quelle'],
                'include_children' => true,
                'operator' => 'IN',
            ];
        }

        $args = [
            'post_type'      => 'tribe_events',
            'post_status'    => 'publish',
            'orderby'        => 'meta_value',
            'meta_key'       => '_EventStartDate',
            'order'          => 'ASC',
            'posts_per_page' => 200,
            'meta_query'     => [
                [
                    'key'     => '_EventStartDate',
                    'value'   => [$start_mysql, $end_mysql],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ]
            ],
        ];
        if (count($tax_query) > 1) $args['tax_query'] = $tax_query;

        $q = new \WP_Query($args);
        $ids = [];
        if ($q->have_posts()){
            while ($q->have_posts()){ $q->the_post(); $ids[] = get_the_ID(); }
            wp_reset_postdata();
        }
        return $ids;
    }

    public static function event_source_names(int $post_id): array {
        // 1) event_source
        if (taxonomy_exists('event_source')) {
            $terms = get_the_terms($post_id, 'event_source');
            if (!is_wp_error($terms) && $terms) return array_values(array_map(fn($t)=>$t->name,$terms));
        }
        // 2) tribe_events_cat unter "Quelle"
        if (taxonomy_exists('tribe_events_cat')) {
            $terms = get_the_terms($post_id, 'tribe_events_cat');
            if (!is_wp_error($terms) && $terms) {
                $out=[];
                foreach($terms as $t){
                    $p = $t->parent ? get_term($t->parent,'tribe_events_cat') : null;
                    if ($p && !is_wp_error($p) && $p->name==='Quelle') $out[]=$t->name;
                }
                if ($out) return $out;
            }
        }
        return [];
    }

    public static function is_own_event(int $post_id): bool {
        // heuristisch: keine Quelle gesetzt -> eigenes Ereignis
        return empty(self::event_source_names($post_id));
    }

    public static function own_source_name(): string {
        return (string) get_option('blogname','');
    }
}
