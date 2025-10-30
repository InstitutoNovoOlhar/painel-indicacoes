<?php
/**
 * Plugin Name: Youve – Indicacoes Dashboard (Supabase)
 * Description: Shortcode [indicacoes_dashboard] que mostra total e convertidas a partir da tabela "indicacao" no Supabase via REST.
 * Version: 2.0.1
 * Author: Youve
 */

if ( ! defined('ABSPATH') ) { exit; }

/**
 * CONFIGURAÇÃO VIA wp-config.php:
 *
 * define('SUPABASE_URL', 'https://olouqlmxoehsqpbyxfmd.supabase.co');
 * define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9....');
 *
 * Estrutura da tabela Supabase:
 *   tabela: indicacao        (ou ajuste INDIC_TABLE_NAME)
 *   colunas:
 *      id_indicado (PK numérica)
 *      indicado
 *      etapa
 *      telefone
 *      indicador_user
 *      data_criacao
 *      ultima_att
 *
 * Ajuste se os nomes forem diferentes:
 *   define('INDIC_TABLE_NAME', 'indicacao');
 *   define('INDIC_COL_PK', 'id_indicado');         // nova: PK/qualquer coluna existente
 *   define('INDIC_COL_INDICADOR', 'indicador_user');
 *   define('INDIC_COL_STATUS', 'etapa');
 *   define('INDIC_USER_MAP', 'login'); // login | email | id
 */

/** Helpers de config */
function youve_indic_cfg($name, $fallback) {
    return defined($name) ? constant($name) : $fallback;
}

/** Resolve qual campo do WP usar (login por padrão) */
function youve_indic_resolve_user($wp_user) {
    $map = strtolower( youve_indic_cfg('INDIC_USER_MAP', 'login') );
    if ($map === 'email') return (string) $wp_user->user_email;
    if ($map === 'id')    return (string) intval($wp_user->ID);
    return (string) $wp_user->user_login; // default
}

/** Monta headers para Supabase REST */
function youve_supabase_headers() {
    // tenta service_key primeiro, se não tiver cai pra anon
    $service = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : '';
    $anon    = defined('SUPABASE_ANON_KEY')     ? SUPABASE_ANON_KEY     : '';

    $key_to_use = $service ?: $anon; // prioridade pra service_role

    return array(
        'apikey'        => $key_to_use,
        'Authorization' => 'Bearer ' . $key_to_use,
        'Prefer'        => 'count=exact',
        'Accept'        => 'application/json',
    );
}

/**
 * Conta linhas de uma tabela no Supabase aplicando filtros PostgREST.
 *
 * Estratégia:
 *  - Fazemos GET com Range: 0-0 e um select de QUALQUER COLUNA EXISTENTE (pk_column)
 *  - Lemos o cabeçalho Content-Range (ex: "0-0/123") para obter o total
 *
 * @param string $pk_column  Uma coluna que exista na tabela (ex.: id_indicado)
 * @param array  $filters    Ex: ['indicador_user' => 'ilike.Raquelpaula01', 'or' => '(etapa.ilike.GANHO,...)']
 * @param string &$debug_url_ref Recebe a URL usada (para debug opcional)
 * @return int|WP_Error
 */
function youve_supabase_count($pk_column, $filters, &$debug_url_ref = null) {
    $base = rtrim( youve_indic_cfg('SUPABASE_URL', ''), '/' );
    if (!$base) return new WP_Error('supabase_cfg', 'SUPABASE_URL não configurada.');

    $table = youve_indic_cfg('INDIC_TABLE_NAME', 'indicacao');

    // Monta querystring: select=<pk_column>&<filtros...>
    $args = array_merge(array('select' => $pk_column), $filters);
    $url  = add_query_arg($args, $base . '/rest/v1/' . rawurlencode($table));

    $res = wp_remote_get($url, array(
        'headers' => array_merge( youve_supabase_headers(), array('Range' => '0-0') ),
        'timeout' => 12,
    ));

    if ($debug_url_ref !== null) {
        $debug_url_ref = $url;
    }

    if (is_wp_error($res)) {
        return $res;
    }

    $code  = wp_remote_retrieve_response_code($res);
    $range = wp_remote_retrieve_header($res, 'content-range'); // "0-0/123"

    // 200 ou 206 são as respostas esperadas
    if ($code !== 200 && $code !== 206) {
        $body = wp_remote_retrieve_body($res);
        return new WP_Error(
            'supabase_http',
            'HTTP ' . $code . ' — ' . $body
        );
    }

    // Se não veio Content-Range, interpretamos como 0
    if (!$range || !is_string($range) || strpos($range, '/') === false) {
        return 0;
    }

    $parts = explode('/', $range);
    $total = intval(end($parts));
    return $total;
}

/**
 * Shortcode principal [indicacoes_dashboard]
 *
 * Atributos:
 *  - user="algum_login"   (força um usuário específico, bom pra teste)
 *  - debug="1"            (mostra as URLs e filtros, só admin vê)
 *  - cache="60"           (TTL do cache em segundos, 0 = sem cache)
 */
function youve_indicacoes_dashboard_shortcode($atts) {
    if ( ! is_user_logged_in() ) {
        return '<div class="youve-indic-dash youve-indic-dash--notice">Por favor, faça login para ver suas indicações.</div>';
    }

    $atts = shortcode_atts(array(
        'user'  => '',
        'debug' => '0',
        'cache' => '60',
    ), $atts, 'indicacoes_dashboard');

    $debug      = ($atts['debug'] === '1');
    $cache_ttl  = max(0, intval($atts['cache']));
    $wp_user    = wp_get_current_user();
    $who        = youve_indic_resolve_user($wp_user);
    if (!empty($atts['user'])) {
        $who = (string) $atts['user'];
    }

    // nomes de tabela/colunas
    $table        = youve_indic_cfg('INDIC_TABLE_NAME', 'indicacao');
    $col_pk       = youve_indic_cfg('INDIC_COL_PK', 'id_indicado');          // <-- chave primária/qualquer coluna existente
    $col_ind      = youve_indic_cfg('INDIC_COL_INDICADOR', 'indicador_user');
    $col_status   = youve_indic_cfg('INDIC_COL_STATUS', 'etapa');

    // Cache key
    $cache_key = 'youve_indic_supabase_' . md5($table.'|'.$col_ind.'|'.$col_status.'|'.$who);
    if ($cache_ttl > 0) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }

    // Filtro base: indicador_user ilike.<who>
    // "ilike" no PostgREST = case-insensitive LIKE; aqui usamos igual exato sem wildcard.
    // Se quiser "começa com" ou "contém", seria ilike.<who>% etc.
    $filters_total = array(
        $col_ind => 'ilike.' . $who,
    );

    // Filtro convertidos: user + etapa ∈ {ganho, convertido, ...} ou etapa = 1
    $won_list = array('GANHO','CONVERTIDO','CONVERTIDA','FECHADO','FECHADA','SUCESSO','APROVADO','APROVADA');
    $or_parts = array_map(function($s){
        return 'etapa.ilike.' . $s;
    }, $won_list);
    $or_parts[] = 'etapa.eq.1';

    $filters_conv = array(
        $col_ind => 'ilike.' . $who,
        'or'     => '(' . implode(',', $or_parts) . ')',
    );

    // chama Supabase para contar
    $debug_url_total = null;
    $debug_url_conv  = null;

    $total = youve_supabase_count($col_pk, $filters_total, $debug_url_total);
    if (is_wp_error($total)) {
        return '<div class="youve-indic-dash youve-indic-dash--error">Erro Supabase (total): ' . esc_html($total->get_error_message()) . '</div>';
    }

    $conv  = youve_supabase_count($col_pk, $filters_conv, $debug_url_conv);
    if (is_wp_error($conv)) {
        return '<div class="youve-indic-dash youve-indic-dash--error">Erro Supabase (convertidos): ' . esc_html($conv->get_error_message()) . '</div>';
    }

    $taxa = $total > 0 ? round(($conv / $total) * 100, 1) : 0.0;

    ob_start(); ?>
    <div class="youve-indic-dash">
        <div class="youve-indic-card">
            <div class="youve-indic-metric">
                <div class="youve-indic-label">Minhas indicações</div>
                <div class="youve-indic-value"><?php echo esc_html($total); ?></div>
            </div>
            <div class="youve-indic-metric">
                <div class="youve-indic-label">Convertidas</div>
                <div class="youve-indic-value"><?php echo esc_html($conv); ?></div>
            </div>
            <div class="youve-indic-metric">
                <div class="youve-indic-label">Taxa de conversão</div>
                <div class="youve-indic-value"><?php echo esc_html($taxa); ?>%</div>
            </div>
        </div>

        <?php if ( current_user_can('manage_options') && $debug ): ?>
        <pre style="background:#f7f7f7;padding:10px;border-radius:8px;margin-top:12px;white-space:pre-wrap;word-break:break-word;">
DEBUG (admin)
- user comparado: "<?php echo esc_html($who); ?>"

- tabela: <?php echo esc_html($table); ?>

- pk usada p/ contagem: <?php echo esc_html($col_pk); ?>

- col indicador: <?php echo esc_html($col_ind); ?>

- col status: <?php echo esc_html($col_status); ?>


- URL total:
<?php echo esc_html($debug_url_total); ?>


- URL convertidos:
<?php echo esc_html($debug_url_conv); ?>

        </pre>
        <?php endif; ?>
    </div>
    <style>
    .youve-indic-dash{max-width:780px;margin:16px 0}
    .youve-indic-card{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;background:#fff;border:1px solid #eee;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .youve-indic-metric{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:10px 12px;border-radius:10px;background:#fafafa}
    .youve-indic-label{font-size:12px;letter-spacing:.3px;color:#666;text-transform:uppercase}
    .youve-indic-value{font-size:28px;font-weight:700;line-height:1}
    .youve-indic-dash--notice,.youve-indic-dash--error{padding:12px 14px;background:#fff3cd;border:1px solid #ffeeba;border-radius:10px}
    @media (max-width:640px){
        .youve-indic-card{grid-template-columns:1fr}
        .youve-indic-value{font-size:24px}
    }
    </style>
    <?php
    $html = ob_get_clean();

    if ($cache_ttl > 0) {
        set_transient($cache_key, $html, $cache_ttl);
    }

    return $html;
}
add_shortcode('indicacoes_dashboard', 'youve_indicacoes_dashboard_shortcode');

/**
 * Shortcode auxiliar só pra debug rápido (quem está logado, etc)
 * Exemplo de uso: [indicacoes_whoami]
 */
add_shortcode('indicacoes_whoami', function(){
    if ( ! is_user_logged_in() ) {
        return '<pre>Sem login WP.</pre>';
    }
    $u = wp_get_current_user();
    ob_start(); ?>
    <pre style="background:#f7f7f7;padding:10px;border-radius:8px;white-space:pre-wrap;word-break:break-word;">
WP WHOAMI
- user_login: <?php echo esc_html($u->user_login); ?>

- user_email: <?php echo esc_html($u->user_email); ?>

- user_id: <?php echo intval($u->ID); ?>

    </pre>
    <?php
    return ob_get_clean();
});
