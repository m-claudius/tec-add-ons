<?php
namespace TEC_Addons\Mailing;

if ( ! defined('ABSPATH') ) { exit; }

if (class_exists(__NAMESPACE__.'\\Schedule', false)) { return; }

class Schedule {

    /* ================= Optionen/Flags ================= */
    public static function weekly_enabled(): bool  { return get_option('tec_addons_weekly_enabled','yes')  === 'yes'; }
    public static function monthly_enabled(): bool { return get_option('tec_addons_monthly_enabled','yes') === 'yes'; }

    /* ================= Zeit / Helper ================= */
    public static function tz(): \DateTimeZone {
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
            if ($tz instanceof \DateTimeZone) return $tz;
        }
        $tz_string = (string) get_option('timezone_string');
        if ($tz_string !== '') { try { return new \DateTimeZone($tz_string); } catch (\Throwable $e) {} }
        return new \DateTimeZone('UTC');
    }
    public static function now(): \DateTimeImmutable { return new \DateTimeImmutable('now', self::tz()); }
    public static function fmt(\DateTimeInterface $dt): string { return $dt->format('Y-m-d H:i:s'); }

    public static function next_saturday_1am(?\DateTimeImmutable $ref = null): \DateTimeImmutable {
        $ref = $ref ?: self::now();
        // nächster Samstag 01:00 (immer in der Zukunft)
        $cand = $ref->setTime(1,0);
        while ((int)$cand->format('N') !== 6 || $cand <= $ref) { $cand = $cand->modify('+1 day'); }
        return $cand;
    }
    public static function last_saturday_of_month_1am(?\DateTimeImmutable $ref = null): \DateTimeImmutable {
        $ref = $ref ?: self::now();
        $firstNext = (new \DateTimeImmutable($ref->format('Y-m-01').' 01:00:00', self::tz()))->modify('first day of next month');
        $last = $firstNext->modify('-1 day');
        while ((int)$last->format('N') !== 6) { $last = $last->modify('-1 day'); }
        return $last;
    }
    public static function next_weekly_timestamp(): int  { return self::next_saturday_1am()->getTimestamp(); }
    public static function next_monthly_timestamp(): int {
        $now = self::now(); $ref = self::last_saturday_of_month_1am($now);
        if ($ref <= $now) $ref = self::last_saturday_of_month_1am($now->modify('first day of next month'));
        return $ref->getTimestamp();
    }

    // Mail-Zeiträume (bestehendes Verhalten)
    public static function window_weekly_live(?\DateTimeImmutable $ref = null): array {
        $anchor = self::next_saturday_1am($ref);
        $start  = $anchor->setTime(0,0,0);
        $end    = $start->modify('+9 days')->setTime(23,59,59);
        return [ self::fmt($start), self::fmt($end) ];
    }
    public static function window_monthly_live(?\DateTimeImmutable $ref = null): array {
        $anchor = self::last_saturday_of_month_1am($ref ?: self::now());
        $start  = $anchor->setTime(0,0,0);
        $end    = $start->modify('+42 days')->setTime(23,59,59);
        return [ self::fmt($start), self::fmt($end) ];
    }
    public static function window_weekly_test(): array  { return self::window_weekly_live( self::next_saturday_1am() ); }
    public static function window_monthly_test(): array { return self::window_monthly_live( self::last_saturday_of_month_1am() ); }

    /* ================= Locking & Run-Key (Duplikatschutz) ================= */
    public static function acquire_lock(string $key, int $ttl = 7200): bool {
        $lock = 'tec_addons_lock_'.$key;
        if ( get_transient($lock) ) return false;
        set_transient($lock, 1, $ttl);
        return true;
    }
    public static function release_lock(string $key): void { delete_transient('tec_addons_lock_'.$key); }

    public static function run_key_for(string $type): string {
        if ($type === 'weekly') {
            $ref = self::next_saturday_1am()->setTime(1,0,0);
            return 'weekly-'.$ref->format('Ymd-His');
        }
        $ref = self::last_saturday_of_month_1am()->setTime(1,0,0);
        return 'monthly-'.$ref->format('Ymd-His');
    }

    /* ================= Planung ================= */
    public static function maybe_schedule_cron(): void {
        if ( self::weekly_enabled()  && ! wp_next_scheduled('tec_addons_cron_weekly') ) {
            wp_schedule_single_event( self::next_weekly_timestamp(), 'tec_addons_cron_weekly' );
        }
        if ( self::monthly_enabled() && ! wp_next_scheduled('tec_addons_cron_monthly') ) {
            wp_schedule_single_event( self::next_monthly_timestamp(), 'tec_addons_cron_monthly' );
        }
    }
    public static function clear_cron(): void {
        $ts = wp_next_scheduled('tec_addons_cron_weekly');  if ($ts) wp_unschedule_event($ts, 'tec_addons_cron_weekly');
        $ts = wp_next_scheduled('tec_addons_cron_monthly'); if ($ts) wp_unschedule_event($ts, 'tec_addons_cron_monthly');
    }
}
