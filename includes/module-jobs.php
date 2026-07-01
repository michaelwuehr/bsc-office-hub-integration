<?php
/**
 * BSC Office Hub Integration – Modul: Offene Stellen / Jobs.
 *
 * Shortcode [bsc_hub_jobs] zeigt die im Office Hub gepflegten offenen Stellen
 * (GET /api/v1/shop/jobs) als Stellenanzeigen mit Einleitung, „Das wartet auf dich",
 * „Das bringst du mit", „Das bieten wir dir", Bewerbung/Kontakt sowie Initiativbewerbung
 * und einem AGG-/Gleichbehandlungs-Hinweis. Datenpflege erfolgt komplett im Hub.
 *
 * Beispiel: eine WP-Seite (z. B. /jobs/) anlegen und nur den Shortcode einfügen:
 *   [bsc_hub_jobs]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function bschi_jobs_bullets( $text ): string {
    $items = '';
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
        $l = trim( ltrim( trim( $line ), "-•*\t " ) );
        if ( $l !== '' ) {
            $items .= '<li>' . esc_html( $l ) . '</li>';
        }
    }
    return $items ? '<ul>' . $items . '</ul>' : '';
}

function bschi_jobs_styles(): string {
    static $done = false;
    if ( $done ) {
        return '';
    }
    $done = true;
    return '<style>
    .bschi-jobs{max-width:820px;margin:0 auto}
    .bschi-jobs__intro{font-size:1.05rem;line-height:1.6;color:#3a3228;margin:0 0 1.4em}
    .bschi-jobs__empty{padding:18px;border:1px solid #e0d8c8;border-radius:12px;background:#f8f5f0;color:#5a5045}
    .bschi-job{border:1px solid #e0d8c8;border-radius:16px;padding:22px 24px;margin:0 0 18px;background:#fff;box-shadow:0 3px 14px rgba(0,0,0,.05)}
    .bschi-job__title{color:#4b5a42;font-size:1.5rem;margin:0 0 .25em;line-height:1.15}
    .bschi-job__meta{color:#b56b43;font-weight:700;letter-spacing:.3px;font-size:.95rem;margin-bottom:1em}
    .bschi-job__intro{line-height:1.6;color:#3a3228;margin:0 0 1em}
    .bschi-job h4{color:#4b5a42;font-size:1.05rem;margin:1.1em 0 .4em}
    .bschi-job ul{margin:.2em 0 .6em;padding-left:1.3em}
    .bschi-job li{margin:.3em 0;line-height:1.5}
    .bschi-job__salary{margin:1em 0 0}
    .bschi-job__contact{margin-top:1.3em;border-top:2px solid #c9a84c;padding-top:1em;line-height:1.6}
    .bschi-jobs__init{border:1px dashed #c9a84c;border-radius:14px;padding:18px 22px;margin:8px 0 18px;background:#faf7f0}
    .bschi-jobs__init h3{color:#4b5a42;margin:0 0 .3em}
    .bschi-jobs__agg{margin-top:1.6em;color:#7a7060;font-size:.82rem;line-height:1.5}
    /* Kachel-/Grid-Ansicht (per Plugin-Settings umschaltbar) */
    .bschi-jobs--grid{max-width:1180px}
    .bschi-jobs--grid .bschi-jobs__list{display:grid;gap:18px;align-items:start;grid-template-columns:repeat(var(--bschi-cols,3),minmax(0,1fr))}
    .bschi-jobs--grid .bschi-job{margin:0;display:flex;flex-direction:column}
    @media(max-width:960px){.bschi-jobs--grid .bschi-jobs__list{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:640px){.bschi-jobs--grid .bschi-jobs__list{grid-template-columns:1fr}}
    /* Kompakte Kacheln: Kurzintro gekürzt, Details im Aufklapper */
    .bschi-jobs--compact .bschi-job__intro{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:.6em}
    .bschi-job__more{margin-top:auto;padding-top:.7em}
    .bschi-job__more>summary{cursor:pointer;color:#b56b43;font-weight:700;letter-spacing:.2px;list-style:none;display:inline-block}
    .bschi-job__more>summary::-webkit-details-marker{display:none}
    .bschi-job__more>summary::after{content:" \25B8"}
    .bschi-job__more[open]>summary::after{content:" \25BE"}
    .bschi-job__more[open]>summary{margin-bottom:.6em}
    </style>';
}

add_shortcode( 'bsc_hub_jobs', function ( $atts ): string {
    bschi_report_link( 'jobs' );
    $data = bschi_hub_get( '/api/v1/shop/jobs', 300 );
    $meta = ( is_array( $data ) && isset( $data['meta'] ) ) ? $data['meta'] : [];
    $jobs = ( is_array( $data ) && isset( $data['jobs'] ) ) ? $data['jobs'] : [];

    // Darstellung: Liste (untereinander), Kacheln (vollständig) oder Kacheln (kompakt, mit Aufklapper)
    // – aus Plugin-Settings, pro Shortcode übersteuerbar.
    $s   = bschi_get_settings();
    $a   = shortcode_atts( [ 'layout' => '', 'columns' => '' ], is_array( $atts ) ? $atts : [] );
    $layout  = in_array( $a['layout'], [ 'list', 'grid', 'compact' ], true ) ? $a['layout'] : ( $s['jobs_layout'] ?? 'list' );
    $cols    = (int) ( $a['columns'] !== '' ? $a['columns'] : ( $s['jobs_columns'] ?? 3 ) );
    $cols    = max( 2, min( 4, $cols ) );
    $grid    = in_array( $layout, [ 'grid', 'compact' ], true );
    $compact = ( $layout === 'compact' );

    ob_start();
    echo bschi_jobs_styles();
    $wrap_cls = 'bschi-jobs' . ( $grid ? ' bschi-jobs--grid' : '' ) . ( $compact ? ' bschi-jobs--compact' : '' );
    ?>
    <div class="<?php echo esc_attr( $wrap_cls ); ?>"<?php echo $grid ? ' style="--bschi-cols:' . (int) $cols . '"' : ''; ?>>
        <?php if ( ! empty( $meta['jobs_page_intro'] ) ) : ?>
            <p class="bschi-jobs__intro"><?php echo esc_html( $meta['jobs_page_intro'] ); ?></p>
        <?php endif; ?>

        <?php if ( empty( $jobs ) ) : ?>
            <div class="bschi-jobs__empty">
                Aktuell sind keine Stellen ausgeschrieben.
                <?php if ( ! empty( $meta['jobs_init_text'] ) ) : ?><br><?php echo esc_html( $meta['jobs_init_text'] ); ?><?php endif; ?>
            </div>
        <?php else : ?>
            <div class="bschi-jobs__list">
            <?php foreach ( $jobs as $j ) :
                $meta_line = implode( ' · ', array_filter( [
                    $j['employment_type'] ?? '', $j['location'] ?? '', $j['start_text'] ?? '',
                ] ) );
                $contact = ! empty( $j['contact'] ) ? $j['contact'] : ( $meta['jobs_contact'] ?? '' );
                ?>
                <article class="bschi-job">
                    <h3 class="bschi-job__title"><?php echo esc_html( $j['title'] ); ?></h3>
                    <?php if ( $meta_line ) : ?><div class="bschi-job__meta"><?php echo esc_html( $meta_line ); ?></div><?php endif; ?>
                    <?php if ( ! empty( $j['intro'] ) ) : ?><p class="bschi-job__intro"><?php echo esc_html( $j['intro'] ); ?></p><?php endif; ?>
                    <?php if ( $compact ) : ?><details class="bschi-job__more"><summary>Mehr erfahren</summary><?php endif; ?>
                    <?php if ( ! empty( $j['tasks'] ) ) : ?><h4>Das wartet auf dich</h4><?php echo bschi_jobs_bullets( $j['tasks'] ); ?><?php endif; ?>
                    <?php if ( ! empty( $j['profile'] ) ) : ?><h4>Das bringst du mit</h4><?php echo bschi_jobs_bullets( $j['profile'] ); ?><?php endif; ?>
                    <?php if ( ! empty( $j['offer'] ) ) : ?><h4>Das bieten wir dir</h4><?php echo bschi_jobs_bullets( $j['offer'] ); ?><?php endif; ?>
                    <?php if ( ! empty( $j['salary_text'] ) ) : ?><p class="bschi-job__salary"><strong>Vergütung:</strong> <?php echo esc_html( $j['salary_text'] ); ?></p><?php endif; ?>
                    <?php if ( $contact ) : ?><div class="bschi-job__contact"><strong>Deine Bewerbung</strong><br><?php echo nl2br( esc_html( $contact ) ); ?></div><?php endif; ?>
                    <?php if ( $compact ) : ?></details><?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $meta['jobs_init_text'] ) ) : ?>
                <div class="bschi-jobs__init">
                    <h3>Initiativbewerbung</h3>
                    <p><?php echo esc_html( $meta['jobs_init_text'] ); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( ! empty( $meta['jobs_agg_footer'] ) ) : ?>
            <p class="bschi-jobs__agg"><?php echo esc_html( $meta['jobs_agg_footer'] ); ?></p>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
} );
