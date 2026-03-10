<?php
/**
 * Plugin Name: LINE発注
 * Plugin URI:  https://example.com
 * Description: 商品情報を管理し、LINE公式アカウントへ発注内容を送信するプラグイン
 * Version:     1.0.0
 * Author:      Custom Plugin
 * License:     GPL2
 * Text Domain: line-order
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LO_VERSION', '1.0.0' );
define( 'LO_URL',     plugin_dir_url( __FILE__ ) );

add_action( 'init', 'lo_register_post_type' );
function lo_register_post_type() {
    register_post_type( 'lo_product', array(
        'labels' => array(
            'name'               => 'LINE発注',
            'singular_name'      => '商品',
            'add_new'            => '新規投稿',
            'add_new_item'       => '新規商品を追加',
            'edit_item'          => '商品を編集',
            'all_items'          => '投稿一覧',
            'menu_name'          => 'LINE発注',
            'not_found'          => '商品が見つかりません',
            'not_found_in_trash' => 'ゴミ箱に商品はありません',
        ),
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => true,
        'menu_position'   => 25,
        'menu_icon'       => 'dashicons-store',
        'supports'        => array( 'title' ),
        'capability_type' => 'post',
        'show_in_rest'    => false,
    ) );
}

add_action( 'init', 'lo_register_taxonomy' );
function lo_register_taxonomy() {
    register_taxonomy( 'lo_category', array( 'lo_product' ), array(
        'labels' => array(
            'name'          => 'カテゴリ',
            'singular_name' => 'カテゴリ',
            'add_new_item'  => '新規カテゴリを追加',
            'edit_item'     => 'カテゴリを編集',
            'menu_name'     => 'カテゴリ',
        ),
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => false,
        'rewrite'           => false,
    ) );
}

register_activation_hook( __FILE__, function() {
    lo_register_post_type();
    lo_register_taxonomy();
    flush_rewrite_rules();
} );

add_action( 'admin_menu', 'lo_add_settings_page' );
function lo_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=lo_product',
        'LINE設定', 'LINE設定', 'manage_options',
        'lo-settings', 'lo_settings_html'
    );
}
add_action( 'admin_init', function() {
    register_setting( 'lo_settings', 'lo_line_token' );
    register_setting( 'lo_settings', 'lo_line_to' );
} );
function lo_settings_html() { ?>
<div class="wrap">
    <h1>LINE API設定</h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'lo_settings' ); ?>
        <table class="form-table">
            <tr>
                <th>チャネルアクセストークン</th>
                <td><input type="text" name="lo_line_token"
                    value="<?php echo esc_attr( get_option('lo_line_token') ); ?>"
                    class="regular-text" placeholder="LINE チャネルアクセストークン"/></td>
            </tr>
            <tr>
                <th>送信先 (userId / groupId)</th>
                <td><input type="text" name="lo_line_to"
                    value="<?php echo esc_attr( get_option('lo_line_to') ); ?>"
                    class="regular-text" placeholder="U1234567890abcdef..."/></td>
            </tr>
        </table>
        <?php submit_button('設定を保存'); ?>
    </form>
</div>
<?php }

function lo_send_line( $message ) {
    $token = get_option('lo_line_token','');
    $to    = get_option('lo_line_to','');
    if ( empty($token) ) return array('success'=>false,'message'=>'LINEトークンが未設定です。');
    if ( empty($to) )    return array('success'=>false,'message'=>'送信先が未設定です。');
    $res = wp_remote_post( 'https://api.line.me/v2/bot/message/push', array(
        'timeout' => 15,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ),
        'body' => wp_json_encode( array(
            'to'       => $to,
            'messages' => array( array('type'=>'text','text'=>$message) ),
        ) ),
    ) );
    if ( is_wp_error($res) ) return array('success'=>false,'message'=>$res->get_error_message());
    $code = wp_remote_retrieve_response_code($res);
    if ( $code === 200 ) return array('success'=>true,'message'=>'送信成功');
    return array('success'=>false,'message'=>'LINE APIエラー('.$code.'): '.wp_remote_retrieve_body($res));
}

add_action('wp_ajax_lo_submit',        'lo_ajax_submit');
add_action('wp_ajax_nopriv_lo_submit', 'lo_ajax_submit');
function lo_ajax_submit() {
    check_ajax_referer('lo_front','nonce');
    $pid = absint( $_POST['post_id'] ?? 0 );
    if ( !$pid || get_post_type($pid) !== 'lo_product' ) wp_send_json_error('無効なリクエストです。');
    /* 選択された型番を1件受け取る */
    $selected_model = isset($_POST['lo_selected_model']) ? sanitize_text_field($_POST['lo_selected_model']) : '';
    if ( empty($selected_model) ) wp_send_json_error('型番を選択してください。');

    $lines  = array('【LINE発注】', '商品名：'.get_the_title($pid), '選択型番：');
    $groups = get_post_meta($pid,'_lo_groups',true);
    /* グループを検索して一致するラベルを見つける */
    if ( is_array($groups) ) {
        $found = false;
        foreach ( $groups as $gi => $g ) {
            foreach ( ($g['options']??array()) as $o ) {
                if ( ($o['model']??'') === $selected_model ) {
                    $lbl     = !empty($g['label']) ? $g['label'] : 'グループ'.($gi+1);
                    $ol      = !empty($o['label'])  ? '（'.$o['label'].'）' : '';
                    $lines[] = '  ・'.$lbl.'：'.$selected_model.$ol;
                    $found   = true;
                    break 2;
                }
            }
        }
        if (!$found) $lines[] = '  ・'.$selected_model;
    }
    $lines[] = '';
    $lines[] = '送信日時：'.wp_date('Y/m/d H:i',null,new DateTimeZone('Asia/Tokyo'));
    $r = lo_send_line( implode("\n",$lines) );
    if ($r['success']) wp_send_json_success('ご注文を受け付けました。LINEへ送信しました。');
    else               wp_send_json_error('送信失敗: '.$r['message']);
}

add_action('admin_enqueue_scripts','lo_admin_scripts');
function lo_admin_scripts($hook) {
    global $post_type;
    $is_lo_post = ($post_type === 'lo_product');
    $is_lo_page = (isset($_GET['page']) && strpos($_GET['page'],'lo-') === 0);
    if (!$is_lo_post && !$is_lo_page) return;
    add_action('admin_head',   'lo_admin_inline_css');
    add_action('admin_footer', 'lo_admin_copy_js');
    if ($is_lo_post) {
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        add_action('admin_footer', 'lo_admin_inline_js');
    }
}
function lo_admin_inline_css() { ?>
<style>
.lo-box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px 20px;margin-bottom:6px}
.lo-frow{margin-bottom:14px}
.lo-frow>label{display:block;font-weight:600;color:#333;margin-bottom:5px;font-size:13px}
.lo-img-box{display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap}
.lo-img-prev{flex:0 0 210px;min-height:140px;border:2px dashed #ccc;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f9f9f9}
.lo-img-prev img{max-width:100%;max-height:200px;object-fit:contain}
.lo-img-ph{color:#999;font-size:13px;text-align:center;padding:20px}
.lo-img-ph .dashicons{font-size:40px;width:40px;height:40px;display:block;margin:0 auto 6px}
.lo-img-ctrl{flex:1;min-width:220px}
.lo-sz-row{display:flex;gap:12px;align-items:center;margin-top:12px;flex-wrap:wrap}
.lo-sz-row label{font-size:13px;color:#444}
.lo-sz{width:80px!important;margin-left:6px}
.lo-tb{display:flex;flex-wrap:wrap;gap:4px;background:#f0f0f0;border:1px solid #ccc;border-bottom:none;border-radius:6px 6px 0 0;padding:6px 8px;align-items:center}
.lo-tb-btn{background:#fff;border:1px solid #ccc;border-radius:4px;padding:3px 10px;cursor:pointer;font-size:13px;line-height:1.5;transition:background .15s}
.lo-tb-btn:hover{background:#e0e0e0;border-color:#999}
.lo-sep{width:1px;height:22px;background:#ccc;margin:0 4px}
.lo-cl-label{display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer}
.lo-cl-label input[type=color]{width:32px;height:28px;border:1px solid #ccc;border-radius:4px;padding:2px;cursor:pointer}
.lo-editor{min-height:140px;border:1px solid #ccc;border-radius:0 0 6px 6px;padding:12px 14px;font-size:14px;line-height:1.7;outline:none;background:#fff;color:#333}
.lo-editor:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.lo-editor:empty::before{content:attr(data-ph);color:#aaa;pointer-events:none}
.lo-editor ul,.lo-editor ol{padding-left:22px;margin:6px 0}
.lo-gb{background:#fafafa;border:1px solid #ddd;border-radius:6px;padding:14px 16px;margin-bottom:14px}
.lo-gh{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;border-bottom:1px solid #e5e5e5;padding-bottom:8px}
.lo-gt{font-weight:700;font-size:14px;color:#444}
.lo-opts{border:1px solid #e0e0e0;border-radius:4px;background:#fff;padding:8px;margin-bottom:8px}
.lo-orow{display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid #f0f0f0}
.lo-orow:last-child{border-bottom:none}
.lo-olbl{flex:2}
.lo-omdl{flex:1;font-family:monospace}
.lo-ocnt{font-size:12px;margin-left:8px;color:#888}
.lo-btn-prev{display:inline-block;padding:14px 48px;border-radius:60px;font-size:15px;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.15);letter-spacing:.04em}
.lo-bp{background:#2271b1;color:#fff;border:none;border-radius:5px;padding:7px 14px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:4px;transition:background .2s}
.lo-bp:hover{background:#135e96}
.lo-bs{background:#f0f0f0;color:#333;border:1px solid #ccc;border-radius:5px;padding:7px 14px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:4px}
.lo-bs:hover{background:#e0e0e0}
.lo-bss{background:#f0f0f0;color:#333;border:1px solid #ccc;border-radius:4px;padding:4px 10px;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:3px}
.lo-bss:hover{background:#e0e0e0}
.lo-bd{background:#dc3545;color:#fff;border:none;border-radius:5px;padding:7px 14px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:4px}
.lo-bd:hover{background:#b02a37}
.lo-bds{background:none;color:#dc3545;border:1px solid #dc3545;border-radius:4px;padding:3px 8px;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:3px}
.lo-bds:hover{background:#dc3545;color:#fff}
</style>
<?php }

add_action('add_meta_boxes','lo_add_boxes');
function lo_add_boxes() {
    add_meta_box('lo_img',  '① 商品画像',           'lo_box_img',  'lo_product','normal','high');
    add_meta_box('lo_info', '② 商品情報',           'lo_box_info', 'lo_product','normal','high');
    add_meta_box('lo_mdl',  '③ 型番／ラジオボタン', 'lo_box_mdl',  'lo_product','normal','high');
    add_meta_box('lo_btn',  '④ 送信ボタン設定',     'lo_box_btn',  'lo_product','side','default');
    add_meta_box('lo_sc',   '⑤ ショートコード',     'lo_box_sc',   'lo_product','side','high');
}

function lo_box_img($post) {
    $id  = get_post_meta($post->ID,'_lo_img_id',true);
    $w   = get_post_meta($post->ID,'_lo_img_w',true);
    $h   = get_post_meta($post->ID,'_lo_img_h',true);
    $url = $id ? wp_get_attachment_url($id) : '';
    wp_nonce_field('lo_save','lo_nonce'); ?>
    <div class="lo-box lo-img-box">
        <div class="lo-img-prev">
            <img id="lo_iprev" src="<?php echo esc_url($url); ?>" style="max-width:200px;max-height:200px;object-fit:contain;<?php echo $url?'':'display:none;'; ?>"/>
            <div id="lo_iph" class="lo-img-ph" style="<?php echo $url?'display:none;':''; ?>">
                <span class="dashicons dashicons-format-image"></span><br>画像なし
            </div>
        </div>
        <div class="lo-img-ctrl">
            <input type="hidden" id="lo_iid" name="lo_img_id" value="<?php echo esc_attr($id); ?>"/>
            <button type="button" id="lo_iup" class="lo-bs"><span class="dashicons dashicons-upload"></span> アップロード</button>
            <button type="button" id="lo_irm" class="lo-bd" style="<?php echo $url?'':'display:none;'; ?>"><span class="dashicons dashicons-no"></span> 削除</button>
            <p class="description">JPG/PNG/GIF/WebP/SVG など全形式対応</p>
            <div class="lo-sz-row">
                <label>横幅(px) <input type="number" name="lo_img_w" value="<?php echo esc_attr($w); ?>" min="0" max="2000" placeholder="自動" class="lo-sz"/></label>
                <label>縦幅(px) <input type="number" name="lo_img_h" value="<?php echo esc_attr($h); ?>" min="0" max="2000" placeholder="自動" class="lo-sz"/></label>
            </div>
        </div>
    </div>
    <?php
}

function lo_box_info($post) {
    $info = get_post_meta($post->ID,'_lo_info',true); ?>
    <div class="lo-box">
        <div class="lo-tb" id="lo_tb">
            <button type="button" class="lo-tb-btn" data-cmd="bold"><b>B</b> 太字</button>
            <span class="lo-sep"></span>
            <button type="button" class="lo-tb-btn" data-cmd="insertUnorderedList">● 順序なし</button>
            <button type="button" class="lo-tb-btn" data-cmd="insertOrderedList">1. 順序あり</button>
            <span class="lo-sep"></span>
            <label class="lo-cl-label">文字色 <input type="color" id="lo_tcolor" value="#333333"/></label>
            <button type="button" class="lo-tb-btn" id="lo_appcol">色を適用</button>
            <span class="lo-sep"></span>
            <button type="button" class="lo-tb-btn" data-cmd="removeFormat">書式クリア</button>
        </div>
        <div id="lo_editor" class="lo-editor" contenteditable="true"
             data-ph="商品の説明を入力してください"><?php echo wp_kses_post($info); ?></div>
        <input type="hidden" name="lo_info" id="lo_info_h"/>
    </div>
    <?php
}

function lo_box_mdl($post) {
    $groups = get_post_meta($post->ID,'_lo_groups',true);
    if (!is_array($groups)||empty($groups)) $groups = array(array('label'=>'','detail'=>'','options'=>array(array('label'=>'','model'=>''))));
    ?>
    <div class="lo-box">
        <p class="description" style="margin-bottom:12px;">グループごとにラベル・型番詳細・ラジオボタン（最大20個）を登録できます。</p>
        <div id="lo_gcon">
        <?php foreach ($groups as $gi => $g) lo_render_group($gi,$g); ?>
        </div>
        <button type="button" id="lo_gadd" class="lo-bp" style="margin-top:14px;"><span class="dashicons dashicons-plus-alt"></span> グループを追加</button>
        <script type="text/html" id="lo_gtpl"><?php lo_render_group('GIDX',array('label'=>'','detail'=>'','options'=>array(array('label'=>'','model'=>''))),true); ?></script>
    </div>
    <?php
}

function lo_render_group($gi,$g,$tpl=false) {
    $lbl = $tpl ? '' : esc_attr($g['label']??'');
    $det = $tpl ? '' : esc_textarea($g['detail']??'');
    $opts = (!$tpl&&!empty($g['options'])) ? $g['options'] : array(array('label'=>'','model'=>'')); ?>
    <div class="lo-gb" data-gi="<?php echo $gi; ?>">
        <div class="lo-gh">
            <span class="lo-gt">グループ <?php echo $tpl?'':((int)$gi+1); ?></span>
            <button type="button" class="lo-rm-grp lo-bds"><span class="dashicons dashicons-trash"></span> 削除</button>
        </div>
        <div class="lo-frow">
            <label>グループラベル</label>
            <input type="text" name="lo_groups[<?php echo $gi; ?>][label]" value="<?php echo $lbl; ?>"
                   placeholder="例：ガートル掛付・エアータイヤ チェックネイビー" class="widefat"/>
        </div>
        <div class="lo-frow">
            <label>型番詳細説明</label>
            <textarea name="lo_groups[<?php echo $gi; ?>][detail]" rows="3" class="widefat"
                      placeholder="例：スチール製スタンダードタイプ。重さ15.2kg..."><?php echo $det; ?></textarea>
        </div>
        <div class="lo-frow">
            <label>ラジオボタン選択肢（最大20個）</label>
            <div class="lo-opts" data-gi="<?php echo $gi; ?>">
                <?php foreach ($opts as $oi => $o): ?>
                <div class="lo-orow">
                    <span class="dashicons dashicons-menu" style="color:#bbb;cursor:grab;margin-top:4px;"></span>
                    <input type="text" name="lo_groups[<?php echo $gi; ?>][options][<?php echo (int)$oi; ?>][label]"
                           value="<?php echo esc_attr($o['label']??''); ?>" placeholder="ラベル（例：自走式車いす リーズ）" class="lo-olbl"/>
                    <input type="text" name="lo_groups[<?php echo $gi; ?>][options][<?php echo (int)$oi; ?>][model]"
                           value="<?php echo esc_attr($o['model']??''); ?>" placeholder="型番（例：MW-22ST-CNV）" class="lo-omdl"/>
                    <button type="button" class="lo-rm-opt lo-bds"><span class="dashicons dashicons-no-alt"></span></button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="lo-opt-add lo-bss" data-gi="<?php echo $gi; ?>"><span class="dashicons dashicons-plus"></span> 選択肢を追加</button>
            <span class="lo-ocnt description"><?php echo count($opts); ?>/20</span>
        </div>
    </div>
    <?php
}

function lo_box_btn($post) {
    $t  = get_post_meta($post->ID,'_lo_btn_text', true) ?: 'ご注文はこちら';
    $bg = get_post_meta($post->ID,'_lo_btn_bg',   true) ?: '#00B900';
    $cl = get_post_meta($post->ID,'_lo_btn_col',  true) ?: '#ffffff'; ?>
    <div class="lo-box">
        <div class="lo-frow">
            <label>ボタンテキスト</label>
            <input type="text" name="lo_btn_text" value="<?php echo esc_attr($t); ?>" class="widefat"/>
        </div>
        <div class="lo-frow">
            <label>背景色</label>
            <input type="text" class="lo-cp" name="lo_btn_bg"  value="<?php echo esc_attr($bg); ?>" data-default-color="<?php echo esc_attr($bg); ?>"/>
        </div>
        <div class="lo-frow">
            <label>文字色</label>
            <input type="text" class="lo-cp" name="lo_btn_col" value="<?php echo esc_attr($cl); ?>" data-default-color="<?php echo esc_attr($cl); ?>"/>
        </div>
        <div class="lo-frow">
            <label>プレビュー</label>
            <div style="text-align:center;padding:10px 0;">
                <span id="lo_bprev" class="lo-btn-prev" style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($cl); ?>;">
                    <?php echo esc_html($t); ?>
                </span>
            </div>
        </div>
    </div>
    <?php
}

add_action('save_post_lo_product','lo_save');
function lo_save($pid) {
    if (!isset($_POST['lo_nonce'])) return;
    if (!wp_verify_nonce($_POST['lo_nonce'],'lo_save')) return;
    if (defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post',$pid)) return;
    if (isset($_POST['lo_img_id'])) update_post_meta($pid,'_lo_img_id',absint($_POST['lo_img_id']));
    if (isset($_POST['lo_img_w']))  update_post_meta($pid,'_lo_img_w', absint($_POST['lo_img_w']));
    if (isset($_POST['lo_img_h']))  update_post_meta($pid,'_lo_img_h', absint($_POST['lo_img_h']));
    if (isset($_POST['lo_info'])) {
        $al = wp_kses_allowed_html('post');
        $al['span']['style'] = true;
        update_post_meta($pid,'_lo_info', wp_kses(stripslashes($_POST['lo_info']),$al));
    }
    if (isset($_POST['lo_groups'])&&is_array($_POST['lo_groups'])) {
        $clean = array();
        foreach ($_POST['lo_groups'] as $g) {
            $cg = array('label'=>sanitize_text_field($g['label']??''),'detail'=>sanitize_textarea_field($g['detail']??''),'options'=>array());
            if (!empty($g['options'])&&is_array($g['options'])) {
                $c=0;
                foreach ($g['options'] as $o) {
                    if ($c>=20) break;
                    $cg['options'][] = array('label'=>sanitize_text_field($o['label']??''),'model'=>sanitize_text_field($o['model']??''));
                    $c++;
                }
            }
            $clean[] = $cg;
        }
        update_post_meta($pid,'_lo_groups',$clean);
    }
    if (isset($_POST['lo_btn_text'])) update_post_meta($pid,'_lo_btn_text', sanitize_text_field($_POST['lo_btn_text']));
    if (isset($_POST['lo_btn_bg']))   update_post_meta($pid,'_lo_btn_bg',   sanitize_hex_color($_POST['lo_btn_bg'])  ?: '#00B900');
    if (isset($_POST['lo_btn_col']))  update_post_meta($pid,'_lo_btn_col',  sanitize_hex_color($_POST['lo_btn_col']) ?: '#ffffff');
}

function lo_box_sc($post) {
    $is_new = ($post->post_status === 'auto-draft' || $post->ID == 0); ?>
    <div class="lo-box lo-sc-box">
        <?php if ($is_new): ?>
            <div class="lo-sc-notice">
                <span class="dashicons dashicons-info" style="color:#f0b429;font-size:18px;vertical-align:middle;"></span>
                <span style="font-size:13px;color:#666;vertical-align:middle;margin-left:4px;">一度保存すると<br>ショートコードが生成されます</span>
            </div>
        <?php else: ?>
            <p style="font-size:12px;color:#555;margin:0 0 8px;">固定ページに以下をコピーして貼り付けてください：</p>
            <div class="lo-sc-display">
                <code id="lo_sc_code">[line_order id="<?php echo $post->ID; ?>"]</code>
                <button type="button" class="lo-sc-copy-btn" data-copy='[line_order id="<?php echo $post->ID; ?>"]'>
                    <span class="dashicons dashicons-clipboard"></span> コピー
                </button>
            </div>
            <p style="font-size:11px;color:#999;margin:8px 0 0;">投稿ID: <strong><?php echo $post->ID; ?></strong></p>
        <?php endif; ?>
    </div>
    <?php
}

add_filter('manage_lo_product_posts_columns', 'lo_add_sc_column');
function lo_add_sc_column($cols) {
    $new = array();
    foreach ($cols as $k => $v) {
        $new[$k] = $v;
        if ($k === 'title') $new['lo_shortcode'] = 'ショートコード';
    }
    return $new;
}

add_action('manage_lo_product_posts_custom_column', 'lo_render_sc_column', 10, 2);
function lo_render_sc_column($col, $pid) {
    if ($col !== 'lo_shortcode') return;
    $sc = '[line_order id="' . $pid . '"]';
    echo '<div style="display:flex;align-items:center;gap:6px;">';
    echo '<code style="background:#f0f0f0;padding:3px 7px;border-radius:4px;font-size:12px;color:#333;border:1px solid #ddd;">' . esc_html($sc) . '</code>';
    echo '<button type="button" class="lo-list-copy-btn button button-small" data-copy="' . esc_attr($sc) . '" style="padding:2px 8px;font-size:11px;">コピー</button>';
    echo '</div>';
}

add_action('admin_menu', 'lo_add_sc_page');
function lo_add_sc_page() {
    add_submenu_page( 'edit.php?post_type=lo_product', 'ショートコード一覧', 'ショートコード一覧', 'edit_posts', 'lo-shortcodes', 'lo_sc_page_html' );
}

function lo_sc_page_html() {
    $products = get_posts(array('post_type'=>'lo_product','post_status'=>array('publish','draft','private'),'posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC'));
    $sl = array('publish'=>array('label'=>'公開中','color'=>'#00B900'),'draft'=>array('label'=>'下書き','color'=>'#f0b429'),'private'=>array('label'=>'非公開','color'=>'#999')); ?>
    <div class="wrap">
        <h1>ショートコード一覧</h1>
        <?php if (empty($products)): ?>
            <p>商品がまだ登録されていません。<a href="<?php echo admin_url('post-new.php?post_type=lo_product'); ?>" class="button button-primary">新規商品を追加</a></p>
        <?php else: ?>
            <div style="background:#e8f4fd;border:1px solid #b3d9f5;border-radius:8px;padding:14px 18px;margin-bottom:24px;font-size:13px;">
                <strong>使い方：</strong>ショートコードをコピー → 固定ページに貼り付けて更新
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th style="width:40px;">#</th><th>商品名</th><th style="width:80px;">状態</th><th>ショートコード</th><th style="width:120px;">操作</th></tr></thead>
                <tbody>
                <?php foreach ($products as $i => $p):
                    $sc = '[line_order id="'.$p->ID.'"]';
                    $st = $p->post_status;
                    $stl = $sl[$st] ?? array('label'=>$st,'color'=>'#888'); ?>
                <tr>
                    <td><?php echo $i+1; ?></td>
                    <td><a href="<?php echo get_edit_post_link($p->ID); ?>" style="font-weight:600;"><?php echo esc_html($p->post_title ?: '（タイトル未設定）'); ?></a><br><span style="font-size:11px;color:#999;">ID: <?php echo $p->ID; ?></span></td>
                    <td><span style="background:<?php echo $stl['color']; ?>22;color:<?php echo $stl['color']; ?>;border:1px solid <?php echo $stl['color']; ?>55;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600;"><?php echo $stl['label']; ?></span></td>
                    <td><code style="background:#f5f5f5;border:1px solid #e0e0e0;border-radius:4px;padding:4px 8px;font-size:13px;"><?php echo esc_html($sc); ?></code></td>
                    <td>
                        <button type="button" class="lo-page-copy-btn button button-primary" data-copy="<?php echo esc_attr($sc); ?>" style="font-size:12px;">コピー</button>
                        <a href="<?php echo get_edit_post_link($p->ID); ?>" class="button" style="font-size:12px;margin-top:4px;">編集</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <style>
    .lo-sc-display{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:6px;padding:10px 12px;}
    .lo-sc-display code{font-size:13px;color:#333;font-family:monospace;background:none;padding:0;border:none;flex:1;min-width:0;word-break:break-all;}
    .lo-sc-copy-btn{background:#2271b1;color:#fff;border:1px solid #2271b1;border-radius:5px;padding:5px 12px;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap;transition:background .2s;}
    .lo-sc-copy-btn:hover{background:#135e96;border-color:#135e96;}
    .lo-sc-notice{text-align:center;padding:10px 0;}
    </style>
    <?php
}

function lo_admin_copy_js() { ?>
<script>
(function($){
    function loCopyText(text, $btn) {
        var origHtml = $btn.html();
        var doneHtml = '<span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:2px;"></span>コピー済み';
        function showDone() {
            $btn.addClass('lo-copied').html(doneHtml);
            setTimeout(function(){ $btn.removeClass('lo-copied').html(origHtml); }, 2000);
        }
        try {
            var el = document.createElement('textarea');
            el.value = text;
            el.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:none;font-size:16px;opacity:0;';
            document.body.appendChild(el);
            el.focus(); el.select(); el.setSelectionRange(0, el.value.length);
            var ok = document.execCommand('copy');
            document.body.removeChild(el);
            if (ok) { showDone(); return; }
        } catch(e) {}
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(showDone).catch(function(){ alert('コピーできませんでした:\n' + text); });
        } else { alert('コピーできませんでした:\n' + text); }
    }
    $(document).on('click', '.lo-sc-copy-btn',  function(e){ e.preventDefault(); loCopyText($(this).data('copy'), $(this)); });
    $(document).on('click', '.lo-list-copy-btn', function(e){ e.preventDefault(); loCopyText($(this).data('copy'), $(this)); });
    $(document).on('click', '.lo-page-copy-btn', function(e){ e.preventDefault(); loCopyText($(this).data('copy'), $(this)); });
})(jQuery);
</script>
<style>
.lo-sc-copy-btn.lo-copied,.lo-list-copy-btn.lo-copied,.lo-page-copy-btn.lo-copied{background:#00B900!important;border-color:#00B900!important;color:#fff!important;}
</style>
<?php }

function lo_admin_inline_js() { global $post_type; if ($post_type!=='lo_product') return; ?>
<script>
jQuery(function($){
    var mf;
    $('#lo_iup').on('click',function(){
        if(mf){mf.open();return;}
        mf=wp.media({title:'画像を選択',button:{text:'この画像を使用'},multiple:false});
        mf.on('select',function(){
            var a=mf.state().get('selection').first().toJSON();
            $('#lo_iid').val(a.id);
            var u=a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url;
            $('#lo_iprev').attr('src',u).show(); $('#lo_iph').hide(); $('#lo_irm').show();
        });
        mf.open();
    });
    $('#lo_irm').on('click',function(){
        $('#lo_iid').val(''); $('#lo_iprev').attr('src','').hide(); $('#lo_iph').show(); $(this).hide();
    });
    var $ed=$('#lo_editor');
    $('#lo_tb .lo-tb-btn[data-cmd]').on('click',function(){$ed.focus();document.execCommand($(this).data('cmd'),false,null);});
    $('#lo_appcol').on('click',function(){$ed.focus();document.execCommand('foreColor',false,$('#lo_tcolor').val());});
    $('form#post').on('submit',function(){$('#lo_info_h').val($ed.html());});
    $('.lo-cp').wpColorPicker({change:function(){up();},clear:function(){up();}});
    $('[name=lo_btn_text]').on('input',up);
    function up(){
        var bg=$('[name=lo_btn_bg]').val()||'#00B900';
        var cl=$('[name=lo_btn_col]').val()||'#fff';
        var tx=$('[name=lo_btn_text]').val()||'ご注文はこちら';
        $('#lo_bprev').css({background:bg,color:cl}).text(tx);
    }
    var $con=$('#lo_gcon');
    var tpl=$('#lo_gtpl').html();
    var idx=Date.now();
    $('#lo_gadd').on('click',function(){
        idx++; $con.append(tpl.replace(/GIDX/g,idx)); ri();
    });
    $con.on('click','.lo-rm-grp',function(){
        if($con.find('.lo-gb').length<=1){alert('最低1グループ必要です。');return;}
        $(this).closest('.lo-gb').remove(); ri();
    });
    function ri(){$con.find('.lo-gb').each(function(i){$(this).find('.lo-gt').text('グループ '+(i+1));});}
    $con.on('click','.lo-opt-add',function(){
        var $l=$(this).siblings('.lo-opts');
        if($l.find('.lo-orow').length>=20){alert('最大20個まで追加できます。');return;}
        var gi=$(this).data('gi'), oi=$l.find('.lo-orow').length;
        $l.append('<div class="lo-orow"><span class="dashicons dashicons-menu" style="color:#bbb;cursor:grab;margin-top:4px;"></span><input type="text" name="lo_groups['+gi+'][options]['+oi+'][label]" value="" placeholder="ラベル（例：自走式車いす リーズ）" class="lo-olbl"/><input type="text" name="lo_groups['+gi+'][options]['+oi+'][model]" value="" placeholder="型番（例：MW-22ST-CNV）" class="lo-omdl"/><button type="button" class="lo-rm-opt lo-bds"><span class="dashicons dashicons-no-alt"></span></button></div>');
        $(this).siblings('.lo-ocnt').text($l.find('.lo-orow').length+'/20');
    });
    $con.on('click','.lo-rm-opt',function(){
        var $l=$(this).closest('.lo-opts');
        if($l.find('.lo-orow').length<=1){alert('最低1つ必要です。');return;}
        $(this).closest('.lo-orow').remove();
        var gi=$l.data('gi');
        $l.find('.lo-orow').each(function(oi){
            $(this).find('.lo-olbl').attr('name','lo_groups['+gi+'][options]['+oi+'][label]');
            $(this).find('.lo-omdl').attr('name','lo_groups['+gi+'][options]['+oi+'][model]');
        });
        $l.siblings('.lo-ocnt').text($l.find('.lo-orow').length+'/20');
    });
});
</script>
<?php }

add_action('wp_enqueue_scripts','lo_front_scripts');
function lo_front_scripts() {
    add_action('wp_head',  'lo_front_css');
    add_action('wp_footer','lo_front_js');
    wp_localize_script('jquery','loFront',array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('lo_front'),
    ));
}

function lo_front_css() { ?>
<style>
.lo-wrap{max-width:1100px;margin:0 auto;padding:24px 16px 40px;font-family:'Hiragino Kaku Gothic ProN','Meiryo','Yu Gothic',sans-serif;color:#333;box-sizing:border-box}
.lo-wrap *{box-sizing:border-box}
.lo-grid{display:grid;grid-template-columns:1fr 1fr;gap:32px 40px;align-items:start}
.lo-col-l{position:sticky;top:20px}
.lo-img-w{background:#f5f5f5;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:16px;margin-bottom:20px;min-height:260px;overflow:hidden}
.lo-pimg{max-width:100%;height:auto;object-fit:contain;display:block}
.lo-ptitle{font-size:18px;font-weight:700;color:#222;margin:0 0 12px;line-height:1.4;padding:0;border:none;background:none;box-shadow:none;letter-spacing:normal;text-align:left;display:block}
.lo-pinfo{font-size:14px;line-height:1.85;color:#444}
.lo-pinfo ul,.lo-pinfo ol{padding-left:20px;margin:6px 0}
.lo-col-r{display:flex;flex-direction:column;gap:4px}
.lo-glbl{font-size:13px;color:#555;margin:14px 0 4px;font-weight:500;line-height:1.5}
.lo-glbl:first-child{margin-top:0}
.lo-card-wrap{margin-bottom:10px}
.lo-ci{background:#fff;border:1.5px solid #e0e0e0;border-radius:10px;box-shadow:3px 3px 0 0 #d0d0d0;padding:14px 16px;transition:border-color .2s,box-shadow .2s,background .15s}
.lo-ci-top{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.lo-radio{flex-shrink:0;width:20px;height:20px;accent-color:#00B900;cursor:pointer}
.lo-ctitle{font-size:15px;font-weight:700;color:#222;margin:0;cursor:pointer}
.lo-ci-bottom{display:flex;align-items:center;gap:8px;margin-left:32px;min-height:30px}
.lo-mlbl{font-size:12px;color:#666;flex-shrink:0}
.lo-mnum{color:#0073aa;font-family:'Consolas','Courier New',monospace;font-size:17px;font-weight:700;letter-spacing:.03em}
.lo-cpbtn{flex-shrink:0;background:#fff;border:2px solid #00B900;border-radius:20px;padding:4px 14px;font-size:12px;font-weight:700;color:#00B900;cursor:pointer;white-space:nowrap;box-shadow:0 3px 10px rgba(0,185,0,.2);line-height:1.6}
.lo-cpbtn:hover{background:#00B900;color:#fff}
.lo-cdet{margin-top:8px;font-size:12px;color:#666;line-height:1.6;background:#f9f9f9;border-radius:4px;padding:6px 8px;margin-left:32px}
.lo-srow{display:flex;justify-content:center;margin-top:36px}
.lo-sbtn{display:inline-flex;align-items:center;justify-content:center;min-width:260px;padding:17px 56px;border-radius:60px;border:none;cursor:pointer;font-size:17px;font-weight:700;letter-spacing:.05em;transition:opacity .2s,transform .15s,box-shadow .2s;box-shadow:0 5px 18px rgba(0,0,0,.18);outline:none}
.lo-sbtn:hover{opacity:.88;transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.22)}
.lo-sbtn:active{transform:translateY(0)}
.lo-sbtn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.lo-rmsg{text-align:center;margin-top:16px;font-size:15px;font-weight:600;min-height:1.6em}
.lo-rmsg.ok{color:#00B900}.lo-rmsg.ng{color:#dc3545}
.lo-err{color:#dc3545;background:#fff3f3;border:1px solid #f5c6cb;border-radius:6px;padding:12px 16px;font-size:14px}
@media(max-width:820px){.lo-grid{grid-template-columns:1fr;gap:0}.lo-col-l{position:static;margin-bottom:24px}.lo-sbtn{min-width:220px;padding:15px 40px;font-size:16px}}
@media(max-width:480px){.lo-wrap{padding:16px 12px 32px}.lo-ci{padding:10px 12px}.lo-sbtn{width:90%;min-width:unset;padding:14px 24px;font-size:15px}}
</style>
<?php }

function lo_front_js() { ?>
<script>
/* ラジオ選択 → 同グループリセット → 選択カードをハイライト＋コピーボタン表示 */
function loSelectCard(radio) {
    var wrap = radio.closest('.lo-wrap');
    /* wrap内の全ラジオをリセット（グループをまたいで1択） */
    var all = wrap.querySelectorAll('input.lo-radio');
    for (var i = 0; i < all.length; i++) {
        var ci = all[i].closest('.lo-ci');
        ci.style.borderColor = '';
        ci.style.boxShadow   = '';
        ci.style.background  = '';
        var btn = ci.querySelector('.lo-cpbtn');
        if (btn) {
            btn.style.display = 'none';
            btn.textContent   = '⧉ コピー';
            btn.style.background = '';
            btn.style.color      = '';
        }
    }
    /* 選択カードをハイライト */
    var selCi = radio.closest('.lo-ci');
    selCi.style.borderColor = '#00B900';
    selCi.style.boxShadow   = '3px 3px 0 0 #66cc66';
    selCi.style.background  = '#f0fff0';
    /* コピーボタンを表示 */
    var selBtn = selCi.querySelector('.lo-cpbtn');
    if (selBtn) {
        selBtn.style.display = 'inline-block';
    }
}

/* コピーボタン → 型番をクリップボードへ */
function loCopyModel(btn) {
    var mnum = btn.closest('.lo-ci-bottom').querySelector('.lo-mnum');
    if (!mnum) return;
    var text = mnum.textContent.trim();

    /* textarea を使ったコピー（HTTP/HTTPS両対応）*/
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.top      = '0';
    ta.style.left     = '0';
    ta.style.width    = '1px';
    ta.style.height   = '1px';
    ta.style.padding  = '0';
    ta.style.border   = 'none';
    ta.style.fontSize = '16px';
    ta.style.opacity  = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, ta.value.length);

    var ok = false;
    try { ok = document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);

    if (!ok && navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function(){ loMarkCopied(btn); });
        return;
    }
    if (ok) loMarkCopied(btn);
}

function loMarkCopied(btn) {
    btn.textContent      = '✓ コピー済み';
    btn.style.background = '#00B900';
    btn.style.color      = '#fff';
}

/* 送信ボタン */
function loSubmit(pid) {
    var $b = jQuery('[data-pid="'+pid+'"].lo-sbtn');
    var $w = jQuery('#lo-w-'+pid);
    var $r = jQuery('#lo-r-'+pid);
    var checked = $w.find('input.lo-radio:checked')[0];
    if (!checked) { loShowMsg($r, 'ng', '型番を選択してください。'); return; }
    var ot = jQuery.trim($b.text());
    $b.prop('disabled', true).text('送信中...');
    $r.removeClass('ok ng').text('');
    var d = { action:'lo_submit', nonce:loFront.nonce, post_id:pid, lo_selected_model:checked.value };
    jQuery.post(loFront.ajax_url, d)
        .done(function(res){
            if (res.success) loShowMsg($r, 'ok', res.data||'送信しました。');
            else             loShowMsg($r, 'ng', res.data||'送信に失敗しました。');
        })
        .fail(function(){ loShowMsg($r, 'ng', '通信エラーが発生しました。'); })
        .always(function(){ $b.prop('disabled', false).text(ot); });
}
function loShowMsg($el, cls, msg){
    $el.removeClass('ok ng').addClass(cls).text(msg);
    setTimeout(function(){ $el.text('').removeClass('ok ng'); }, 6000);
}
</script>
<?php }

add_shortcode('line_order','lo_shortcode');
function lo_shortcode($atts) {
    $atts = shortcode_atts(array('id'=>0),$atts,'line_order');
    $pid  = absint($atts['id']);
    if (!$pid) return '<p class="lo-err">商品IDが指定されていません。</p>';
    $post = get_post($pid);
    if (!$post||$post->post_type!=='lo_product') return '<p class="lo-err">商品が見つかりません。</p>';

    $img_id = get_post_meta($pid,'_lo_img_id',true);
    $img_w  = get_post_meta($pid,'_lo_img_w', true);
    $img_h  = get_post_meta($pid,'_lo_img_h', true);
    $info   = get_post_meta($pid,'_lo_info',  true);
    $groups = get_post_meta($pid,'_lo_groups',true);
    $bt     = get_post_meta($pid,'_lo_btn_text',true) ?: 'ご注文はこちら';
    $bg     = get_post_meta($pid,'_lo_btn_bg',  true) ?: '#00B900';
    $cl     = get_post_meta($pid,'_lo_btn_col', true) ?: '#ffffff';
    $ist = '';
    if ($img_w) $ist .= 'width:'.(int)$img_w.'px;';
    if ($img_h) $ist .= 'height:'.(int)$img_h.'px;';

    ob_start(); ?>
    <div class="lo-wrap" id="lo-w-<?php echo $pid; ?>">
        <div class="lo-grid">
            <div class="lo-col-l">
                <?php if ($img_id): ?>
                <div class="lo-img-w">
                    <img src="<?php echo esc_url(wp_get_attachment_url($img_id)); ?>"
                         alt="<?php echo esc_attr(get_the_title($pid)); ?>"
                         class="lo-pimg" style="<?php echo esc_attr($ist); ?>" loading="lazy"/>
                </div>
                <?php endif; ?>
                <div class="lo-ptitle"><?php echo esc_html(get_the_title($pid)); ?></div>
                <?php if ($info): ?><div class="lo-pinfo"><?php echo wp_kses_post($info); ?></div><?php endif; ?>
            </div>
            <div class="lo-col-r">
                <?php if (is_array($groups)&&!empty($groups)):
                    foreach ($groups as $gi => $g):
                        $gl = $g['label']??'';
                        $gd = $g['detail']??'';
                        $go = $g['options']??array();
                        if ($gl): ?><p class="lo-glbl"><?php echo esc_html($gl); ?></p><?php endif;
                        foreach ($go as $oi => $o):
                            $ol = $o['label']??'';
                            $om = $o['model']??'';
                            if (empty($om)) continue;
                            $rid = 'lo_r_'.$pid.'_'.$gi.'_'.$oi;
                        ?>
                        <div class="lo-card-wrap">
                            <div class="lo-ci">
                                <div class="lo-ci-top">
                                    <input type="radio" class="lo-radio"
                                           id="<?php echo $rid; ?>"
                                           name="lo_selected_<?php echo $pid; ?>"
                                           value="<?php echo esc_attr($om); ?>"
                                           onclick="loSelectCard(this)"/>
                                    <?php if ($ol): ?>
                                    <label for="<?php echo $rid; ?>" class="lo-ctitle"><?php echo esc_html($ol); ?></label>
                                    <?php endif; ?>
                                </div>
                                <div class="lo-ci-bottom">
                                    <span class="lo-mlbl">型番：</span>
                                    <span class="lo-mnum"><?php echo esc_html($om); ?></span>
                                    <button type="button" class="lo-cpbtn"
                                            style="display:none"
                                            onclick="loCopyModel(this)">⧉ コピー</button>
                                </div>
                                <?php if ($gd): ?>
                                <div class="lo-cdet"><?php echo nl2br(esc_html($gd)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach;
                    endforeach;
                endif; ?>
            </div>
        </div>
        <div class="lo-srow">
            <button type="button" class="lo-sbtn"
                    onclick="loSubmit(<?php echo $pid; ?>)"
                    style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($cl); ?>;"
                    data-pid="<?php echo $pid; ?>">
                <?php echo esc_html($bt); ?>
            </button>
        </div>
        <div class="lo-rmsg" id="lo-r-<?php echo $pid; ?>" role="alert" aria-live="polite"></div>
    </div>
    <?php return ob_get_clean();
}