<?php
/**
 * Plugin Name: Youve – Indicacoes Dashboard (Supabase v3.3)
 * Description: Dashboard completo com contagem por id_indicado + distribuição por etapa + visão "Se todos fecharem"
 * Version: 3.3.0
 * Author: Youve
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* === CONFIGURAÇÃO OBRIGATÓRIA (wp-config.php) ===
define('SUPABASE_URL', 'https://olouqlmxoehsqpbyxfmd.supabase.co');
define('SUPABASE_ANON_KEY', 'SUA_CHAVE_ANON_AQUI');
define('INDIC_TABLE_NAME', 'indicacao');
define('INDIC_COL_PK', 'id_indicado');        // COLUNA PK
define('INDIC_COL_INDICADOR', 'indicador_user');
define('INDIC_COL_STATUS', 'etapa');
define('INDIC_USER_MAP', 'login'); // ou 'email'
*/

function youve_indic_cfg( $name, $fallback ) {
    return defined( $name ) ? constant( $name ) : $fallback;
}

function youve_indic_resolve_user( $wp_user ) {
    $map = strtolower( youve_indic_cfg( 'INDIC_USER_MAP', 'login' ) );
    return $map === 'email' ? $wp_user->user_email : ($map === 'id' ? (string)$wp_user->ID : $wp_user->user_login);
}

function youve_supabase_headers( $extra = [] ) {
    $key = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : SUPABASE_ANON_KEY;
    return array_merge( [
        'apikey' => $key,
        'Authorization' => 'Bearer ' . $key,
        'Accept' => 'application/json',
    ], $extra );
}

/* === CONTAGEM CORRETA USANDO id_indicado === */
function youve_supabase_count( $filters, &$debug_url_ref = null ) {
    $base = rtrim( youve_indic_cfg( 'SUPABASE_URL', '' ), '/' );
    $table = youve_indic_cfg( 'INDIC_TABLE_NAME', 'indicacao' );
    $pk_col = youve_indic_cfg( 'INDIC_COL_PK', 'id_indicado' ); // USANDO id_indicado

    $url = add_query_arg( array_merge( [ 'select' => $pk_col ], $filters ), $base . '/rest/v1/' . rawurlencode( $table ) );

    $res = wp_remote_get( $url, [
        'headers' => youve_supabase_headers( [ 'Range' => '0-0', 'Prefer' => 'count=exact' ] ),
        'timeout' => 15,
    ] );

    if ( $debug_url_ref !== null ) $debug_url_ref = $url;
    if ( is_wp_error( $res ) ) return $res;

    $range = wp_remote_retrieve_header( $res, 'content-range' );
    if ( ! $range || ! preg_match( '#/(\d+)$#', $range, $m ) ) return 0;
    return intval( $m[1] );
}

/* === DISTRIBUIÇÃO POR ETAPA (sem group by) === */
function youve_supabase_groupby_stage( $col_ind, $who, &$debug_url_ref = null ) {
    $base = rtrim( youve_indic_cfg( 'SUPABASE_URL', '' ), '/' );
    if ( ! $base ) return new WP_Error( 'supabase_cfg', 'SUPABASE_URL não configurada.' );

    $table = youve_indic_cfg( 'INDIC_TABLE_NAME', 'indicacao' );
    $col_status = youve_indic_cfg( 'INDIC_COL_STATUS', 'etapa' );
    $pk_col = youve_indic_cfg( 'INDIC_COL_PK', 'id_indicado' );

    // 1. Busca todas as etapas (com id_indicado para garantir contagem)
    $args = [
        'select' => $pk_col . ',' . $col_status,
        $col_ind => 'ilike.' . $who,
        'limit'  => 1000,
    ];
    $url = add_query_arg( $args, $base . '/rest/v1/' . rawurlencode( $table ) );

    $res = wp_remote_get( $url, [ 'headers' => youve_supabase_headers(), 'timeout' => 15 ] );
    if ( is_wp_error( $res ) ) return $res;
    if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
        return new WP_Error( 'supabase_http', 'Erro ao buscar dados: ' . wp_remote_retrieve_body( $res ) );
    }

    $data = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( ! is_array( $data ) ) return new WP_Error( 'json', 'Dados inválidos' );

    $counts = [];
    foreach ( $data as $row ) {
        $etapa = !empty($row[$col_status]) ? $row[$col_status] : 'Sem etapa';
        $counts[$etapa] = ($counts[$etapa] ?? 0) + 1;
    }

    $out = [];
    foreach ( $counts as $etapa => $count ) {
        $out[] = [
            'etapa' => ucfirst( strtolower( $etapa ) ),
            'count' => $count,
        ];
    }

    usort( $out, fn($a, $b) => $b['count'] <=> $a['count'] );
    $debug_url_ref = "Busca direta com id_indicado + contagem local";
    return $out;
}

function youve_format_currency_br( $valor ) {
    return 'R$ ' . number_format( $valor, 0, '', '.' );
}

function youve_indicacoes_dashboard_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '<div class="youve-indic-dash youve-indic-dash--notice">Faça login.</div>';

    $atts = shortcode_atts( [ 'user' => '', 'debug' => '0', 'cache' => '60' ], $atts );
    $debug = $atts['debug'] === '1' && current_user_can( 'manage_options' );
    $who = !empty($atts['user']) ? $atts['user'] : youve_indic_resolve_user( wp_get_current_user() );

    $col_ind = youve_indic_cfg( 'INDIC_COL_INDICADOR', 'indicador_user' );
    $col_status = youve_indic_cfg( 'INDIC_COL_STATUS', 'etapa' );

    $cache_key = 'youve_indic_dash_' . md5( $who );
    if ( $atts['cache'] > 0 && false !== ( $cached = get_transient( $cache_key ) ) ) return $cached;

    // TOTAL
    $total = youve_supabase_count( [ $col_ind => 'ilike.' . $who ], $debug_url_total );

    // CONVERTIDAS
    $won_terms = ['GANHO','CONVERTIDO','CONVERTIDA','FECHADO','FECHADA','SUCESSO','APROVADO','APROVADA'];
    $or = array_map( fn($s) => $col_status . '.ilike.' . $s, $won_terms );
    $or[] = $col_status . '.eq.1';
    $conv = youve_supabase_count( [ $col_ind => 'ilike.' . $who, 'or' => '(' . implode( ',', $or ) . ')' ], $debug_url_conv );

    $taxa = $total > 0 ? round( ($conv / $total) * 100, 1 ) : 0;
    $saldo = youve_format_currency_br( $conv * 500 );

    // NOVO: potencial se todos fecharem (R$ 500 × total de indicações)
    $potencial = youve_format_currency_br( $total * 500 );

    // ETAPAS
    $stage_rows = youve_supabase_groupby_stage( $col_ind, $who, $debug_url_stage );
    if ( is_wp_error( $stage_rows ) ) {
        $error_msg = $stage_rows->get_error_message();
        $stage_rows = [ [ 'etapa' => 'Erro', 'count' => 0 ] ];
    } else {
        $error_msg = '';
    }

    ob_start(); ?>
    <div class="youve-indic-dash">
        <div class="youve-indic-card">
            <div class="youve-indic-metric">
                <div class="youve-indic-label">Minhas indicações</div>
                <div class="youve-indic-value"><?php echo intval($total); ?></div>
            </div>
            <div class="youve-indic-metric">
                <div class="youve-indic-label">Convertidas</div>
                <div class="youve-indic-value"><?php echo intval($conv); ?></div>
            </div>
            <div class="youve-indic-metric youve-indic-metric--saldo">
                <div class="youve-indic-label">Saldo estimado</div>
                <div class="youve-indic-value youve-indic-value-money"><?php echo $saldo; ?></div>
                <div class="youve-indic-hint">R$500 por indicação <strong>Ganho</strong></div>
            </div>

            <!-- NOVO CARD: Se todos fecharem -->
            <div class="youve-indic-metric youve-indic-metric--pot">
                <div class="youve-indic-label">Se todos fecharem</div>
                <div class="youve-indic-value youve-indic-value-money"><?php echo $potencial; ?></div>
                <div class="youve-indic-hint">R$500 × total de indicações</div>
            </div>
        </div>

        <div class="youve-indic-stages-wrap">
            <div class="youve-indic-stages-head">
                <div class="youve-indic-stages-title">Distribuição por etapa</div>
                <div class="youve-indic-stages-sub">Como estão suas indicações no CRM</div>
            </div>
            <table class="youve-indic-stages-table">
                <thead><tr><th>Etapa</th><th>Quantidade</th></tr></thead>
                <tbody>
                <?php foreach ($stage_rows as $r): ?>
                    <tr>
                        <td class="youve-indic-stage-name <?php echo strtolower($r['etapa']) === 'ganho' ? 'youve-indic-stage-ganho' : ''; ?>">
                            <?php echo esc_html($r['etapa']); ?>
                        </td>
                        <td class="youve-indic-stage-count"><?php echo $r['count']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $debug ): ?>
        <pre style="background:#f7f7f7;padding:12px;border-radius:8px;margin-top:16px;font-size:12px;">
DEBUG (admin)
- user: "<?php echo esc_html($who); ?>"
- total: <?php echo $total; ?> (URL: <?php echo esc_url($debug_url_total); ?>)
- convertidas: <?php echo $conv; ?> (URL: <?php echo esc_url($debug_url_conv); ?>)
- etapas: <?php echo esc_html($debug_url_stage); ?>
- potencial (todos fecham): <?php echo esc_html($potencial); ?>

<?php if ( !empty($error_msg) ) echo "ERRO: " . esc_html($error_msg); ?>
        </pre>
        <?php endif; ?>
    </div>

    <style>
    .youve-indic-dash { max-width: 780px; margin: 20px 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .youve-indic-card { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; background: #fff; border: 1px solid #eee; border-radius: 16px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,.04); }
    .youve-indic-metric { display: flex; flex-direction: column; gap: 6px; padding: 12px; border-radius: 12px; background: #fafafa; }
    .youve-indic-label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: .5px; }
    .youve-indic-value { font-size: 28px; font-weight: 700; color: #222; }
    .youve-indic-value-money { font-size: 24px; white-space: nowrap; }
    .youve-indic-metric--saldo { background: #f5fff5; border: 1px solid #d5f5d5; }
    .youve-indic-metric--pot { background: #f5f8ff; border: 1px solid #d6e4ff; } /* NOVO estilo do card de potencial */
    .youve-indic-hint { font-size: 11px; color: #888; }
    .youve-indic-stages-wrap { margin-top: 24px; background: #fff; border: 1px solid #eee; border-radius: 16px; overflow: hidden; }
    .youve-indic-stages-head { padding: 16px 20px; border-bottom: 1px solid #eee; }
    .youve-indic-stages-title { font-weight: 600; font-size: 16px; color: #222; }
    .youve-indic-stages-sub { font-size: 13px; color: #666; margin-top: 4px; }
    .youve-indic-stages-table { width: 100%; border-collapse: collapse; }
    .youve-indic-stages-table th { background: #fafafa; padding: 12px 16px; text-align: left; font-size: 12px; color: #555; text-transform: uppercase; }
    .youve-indic-stages-table td { padding: 14px 16px; border-bottom: 1px solid #f6f6f6; }
    .youve-indic-stage-ganho { color: #0d6e0d; font-weight: 700; }
    /*.youve-indic-stage-ganho::before { content: "Checkmark "; }/* Exemplo de ícone antes da etapa ganho */
    @media (max-width: 640px) {
        .youve-indic-card { grid-template-columns: 1fr 1fr; }
        .youve-indic-metric--saldo { grid-column: 1 / span 2; }
        .youve-indic-metric--pot { grid-column: 1 / span 2; } /* ocupar a linha toda no mobile */
    }
    </style>
    <?php
    $html = ob_get_clean();
    if ( $atts['cache'] > 0 ) set_transient( $cache_key, $html, intval($atts['cache']) );
    return $html;
}
add_shortcode( 'indicacoes_dashboard', 'youve_indicacoes_dashboard_shortcode' );
