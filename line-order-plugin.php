<?php
/**
 * Plugin Name: LINE発注
 * Plugin URI:  https://example.com
 * Description: 商品情報を管理し、LIFFアプリ経由でLINEへ発注内容を送信するプラグイン
 * Version:     2.1.0
 * Author:      Custom Plugin
 * License:     GPL2
 * Text Domain: line-order
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LO_VERSION', '2.1.0' );
define( 'LO_URL',     plugin_dir_url( __FILE__ ) );

/* ============================================================
   カスタム投稿タイプ（v1.0 そのまま）
============================================================ */
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
    lo_create_orders_table();
    flush_rewrite_rules();
} );

/* ============================================================
   発注履歴テーブル（v1.0 そのまま）
============================================================ */
function lo_create_orders_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'lo_orders';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id      BIGINT UNSIGNED NOT NULL,
        product_name VARCHAR(255)   NOT NULL DEFAULT '',
        group_label  VARCHAR(255)   NOT NULL DEFAULT '',
        model_label  VARCHAR(255)   NOT NULL DEFAULT '',
        model_number VARCHAR(255)   NOT NULL DEFAULT '',
        img_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
        img_url      TEXT           NOT NULL DEFAULT '',
        line_status  VARCHAR(20)    NOT NULL DEFAULT 'pending',
        order_status VARCHAR(20)    NOT NULL DEFAULT 'new',
        line_message LONGTEXT       NOT NULL DEFAULT '',
        created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_post_id      (post_id),
        KEY idx_order_status (order_status),
        KEY idx_created_at   (created_at)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'lo_db_version', '1.1' );
}

add_action( 'plugins_loaded', function() {
    global $wpdb;
    if ( get_option('lo_db_version') !== '1.1' ) {
        lo_create_orders_table();
        $table = $wpdb->prefix . 'lo_orders';
        $cols  = $wpdb->get_col( "DESC {$table}", 0 );
        if ( !in_array('img_id',  $cols) ) $wpdb->query("ALTER TABLE {$table} ADD img_id  BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER model_number");
        if ( !in_array('img_url', $cols) ) $wpdb->query("ALTER TABLE {$table} ADD img_url TEXT           NOT NULL DEFAULT '' AFTER img_id");
    }
} );

/* ============================================================
   管理メニュー（v1.0 そのまま）
============================================================ */
add_action( 'admin_menu', 'lo_add_orders_page' );
function lo_add_orders_page() {
    add_submenu_page(
        'edit.php?post_type=lo_product',
        '発注履歴', '発注履歴', 'edit_posts',
        'lo-orders', 'lo_orders_page_html'
    );
}

add_action( 'admin_menu', 'lo_add_settings_page' );
function lo_add_settings_page() {
    add_submenu_page(
        'edit.php?post_type=lo_product',
        'LINE設定', 'LINE設定', 'manage_options',
        'lo-settings', 'lo_settings_html'
    );
}

/* ============================================================
   設定登録
   lo_liff_id     … LIFF App ID（フロントの送信に使用）★新規
   lo_line_token  … Messaging API トークン（管理画面からの再送信用）
   lo_line_to     … 再送信先 userId / groupId
============================================================ */
add_action( 'admin_init', function() {
    register_setting( 'lo_settings', 'lo_liff_id' );
    register_setting( 'lo_settings', 'lo_line_token' );
    register_setting( 'lo_settings', 'lo_line_to' );
} );

/* ============================================================
   LINE設定ページ（LIFF中心 + リッチメニュー手順）
============================================================ */
function lo_settings_html() { ?>
<div class="wrap">
    <h1>LINE 設定</h1>
    <?php if ( isset($_GET['settings-updated']) ) : ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;color:#155724;border-radius:6px;padding:10px 14px;margin-bottom:14px;">✓ 設定を保存しました。</div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields('lo_settings'); ?>

        <!-- ========== LIFF 設定 ========== -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;max-width:700px;margin-bottom:20px;">
            <h2 style="margin:0 0 4px;font-size:16px;display:flex;align-items:center;gap:8px;">
                <span style="background:#06C755;color:#fff;border-radius:6px;padding:2px 12px;font-size:13px;font-weight:700;">LIFF</span>
                LIFF 設定（発注フォームの連携）
            </h2>
            <p style="font-size:13px;color:#666;margin:6px 0 16px;">
                ユーザーが LINE アプリ内のリッチメニューから発注ページを開き、ラジオボタンで型番を選んで送信ボタンを押すと、
                そのトーク画面（個人・グループ・公式アカウントどこでも）に商品画像＋発注テキストが送信されます。
            </p>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">LIFF App ID <span style="color:#dc3545;">*</span></label>
                <input type="text" name="lo_liff_id"
                       value="<?php echo esc_attr( get_option('lo_liff_id') ); ?>"
                       style="width:100%;max-width:480px;" class="regular-text"
                       placeholder="1234567890-AbCdEfGh"/>
                <p class="description">LINE Developers → チャンネル → LIFF タブ → LIFF ID をコピーして貼り付けます。</p>
            </div>

            <!-- LIFF 作成手順 -->
            <details style="margin-bottom:14px;">
                <summary style="cursor:pointer;font-weight:700;font-size:13px;color:#2271b1;padding:8px 0;">
                    📋 LIFF アプリの作成手順（クリックして展開）
                </summary>
                <div style="background:#f0f8ff;border:1px solid #b3d4f5;border-radius:6px;padding:14px 16px;font-size:13px;line-height:1.9;margin-top:8px;">
                    <strong>① LINE Developers でチャンネルを用意する</strong><br>
                    1. <a href="https://developers.line.biz/console/" target="_blank" style="color:#06C755;">LINE Developers コンソール</a> にログイン<br>
                    2. プロバイダーを選択（なければ「作成」）<br>
                    3. 「チャンネル作成」→ <strong>Messaging API</strong> を選択して作成<br><br>

                    <strong>② LIFF アプリを追加する</strong><br>
                    4. 作成したチャンネルを開き「LIFF」タブをクリック<br>
                    5. 「追加」ボタン → 以下を入力：<br>
                    &emsp;・<strong>LIFFアプリ名</strong>：任意（例：発注フォーム）<br>
                    &emsp;・<strong>サイズ</strong>：<code>Full</code>（推奨）<br>
                    &emsp;・<strong>エンドポイントURL</strong>：発注ページの URL<br>
                    &emsp;&emsp;例：<code>https://example.com/order-page/</code><br>
                    &emsp;・<strong>Scope</strong>：<code>chat_message.write</code> に ✓<br>
                    &emsp;&emsp;（sendMessages でトーク送信するために必須）<br>
                    &emsp;・<strong>ボットリンク機能</strong>：On（推奨）<br>
                    6. 「追加」後に発行された <strong>LIFF ID</strong> をコピー<br>
                    &emsp;例：<code>1234567890-AbCdEfGh</code><br><br>

                    <strong>③ LIFF URL について</strong><br>
                    7. LIFF URL は <code>https://liff.line.me/<strong>{LIFF_ID}</strong></code> の形式です<br>
                    8. この URL をリッチメニューに設定すれば完成です（下の手順参照）
                </div>
            </details>

            <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:10px 14px;font-size:12px;color:#795548;">
                ⚠️ <strong>動作条件：</strong>
                トークから開いた場合は <code>liff.sendMessages()</code> で直接送信。
                LINEアプリ内だがトーク外から開いた場合は <code>shareTargetPicker()</code> で送信先を選択。
                通常ブラウザからは送信できません（LIFF URLへの案内を表示します）。
            </div>
        </div>

        <!-- ========== リッチメニュー設定手順 ========== -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;max-width:700px;margin-bottom:20px;">
            <h2 style="margin:0 0 4px;font-size:16px;display:flex;align-items:center;gap:8px;">
                <span style="background:#FF6B00;color:#fff;border-radius:6px;padding:2px 12px;font-size:13px;font-weight:700;">MENU</span>
                リッチメニューに発注ページを設定する手順
            </h2>
            <p style="font-size:13px;color:#666;margin:6px 0 16px;">
                LINE 公式アカウントのリッチメニューに LIFF URL を設定することで、
                ユーザーがトーク画面下のメニューボタンをタップするだけで発注フォームを開けるようになります。
            </p>

            <div style="font-size:13px;line-height:1.9;">

                <div style="background:#f9f9f9;border-left:4px solid #06C755;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:12px;">
                    <strong>方法 A：LINE Official Account Manager（管理画面）で設定</strong><br>
                    ※ 小規模・無料プランにおすすめ
                    <ol style="margin:8px 0 0;padding-left:20px;">
                        <li><a href="https://manager.line.biz/" target="_blank" style="color:#06C755;">LINE Official Account Manager</a> にログイン</li>
                        <li>対象アカウントを選択 → 左メニュー「チャットUI」→「リッチメニュー」</li>
                        <li>「作成」ボタン → タイトル・表示期間を入力</li>
                        <li>テンプレートを選択してメニューボタンを配置</li>
                        <li>各ボタンの「アクション」を「リンク」に設定</li>
                        <li>URLに <code>https://liff.line.me/<strong><?php echo esc_html(get_option('lo_liff_id') ?: 'YOUR_LIFF_ID'); ?></strong></code> を入力</li>
                        <li>ラベル（例：「発注する」）を入力して「保存」→「公開」</li>
                    </ol>
                </div>

                <div style="background:#f9f9f9;border-left:4px solid #2271b1;border-radius:0 6px 6px 0;padding:12px 16px;margin-bottom:12px;">
                    <strong>方法 B：Messaging API（プログラム）で設定</strong><br>
                    ※ 複数商品ページを切り替えたい場合や自動化したい場合
                    <ol style="margin:8px 0 0;padding-left:20px;">
                        <li>LINE Developers → Messaging API → チャネルアクセストークン（長期）を取得</li>
                        <li>以下の API を呼び出してリッチメニューを作成：</li>
                    </ol>
                    <pre style="background:#1e1e2e;color:#cdd6f4;border-radius:6px;padding:12px;font-size:12px;overflow-x:auto;margin:8px 0;line-height:1.6;">POST https://api.line.me/v2/bot/richmenu
Authorization: Bearer {チャネルアクセストークン}
Content-Type: application/json

{
  "size": { "width": 2500, "height": 843 },
  "selected": true,
  "name": "発注メニュー",
  "chatBarText": "発注する",
  "areas": [
    {
      "bounds": { "x":0, "y":0, "width":2500, "height":843 },
      "action": {
        "type": "uri",
        "label": "発注する",
        "uri": "https://liff.line.me/<?php echo esc_html(get_option('lo_liff_id') ?: 'YOUR_LIFF_ID'); ?>"
      }
    }
  ]
}</pre>
                    <ol start="3" style="margin:0;padding-left:20px;">
                        <li>レスポンスの <code>richMenuId</code> を使ってデフォルトメニューに設定：<br>
                            <code>POST /v2/bot/user/all/richmenu/{richMenuId}</code></li>
                        <li>メニュー画像をアップロード：<br>
                            <code>POST https://api-data.line.me/v2/bot/richmenu/{richMenuId}/content</code></li>
                    </ol>
                </div>

                <div style="background:#fff0f0;border:1px solid #f5c6cb;border-radius:6px;padding:10px 14px;font-size:12px;color:#721c24;">
                    💡 <strong>複数商品ページがある場合：</strong>
                    LIFF アプリを商品ページごとに作成するか、1つの LIFF URL に商品IDをパラメータとして付与することもできます。
                    例：<code>https://liff.line.me/{LIFF_ID}?pid=123</code>（ページ側でクエリパラメータを読み取る実装が必要）
                </div>
            </div>
        </div>

        <!-- ========== Messaging API（再送信用） ========== -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;max-width:700px;margin-bottom:20px;">
            <h2 style="margin:0 0 4px;font-size:16px;display:flex;align-items:center;gap:8px;">
                <span style="background:#777;color:#fff;border-radius:6px;padding:2px 12px;font-size:13px;font-weight:700;">API</span>
                Messaging API 設定（管理画面からの再送信用・任意）
            </h2>
            <p style="font-size:13px;color:#666;margin:6px 0 16px;">
                発注履歴ページの「再送信」ボタンで使用します。LIFF 経由の送信が主な方法のためこちらは任意です。
            </p>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">チャネルアクセストークン（長期）</label>
                <input type="text" name="lo_line_token"
                       value="<?php echo esc_attr( get_option('lo_line_token') ); ?>"
                       class="regular-text" style="width:100%;max-width:480px;"
                       placeholder="LINE チャネルアクセストークンを貼り付け"/>
            </div>
            <div style="margin-bottom:0;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">再送信先 ID（userId または groupId）</label>
                <input type="text" name="lo_line_to"
                       value="<?php echo esc_attr( get_option('lo_line_to') ); ?>"
                       class="regular-text" style="width:100%;max-width:480px;"
                       placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"/>
                <p class="description">
                    <strong>個人LINE：</strong>LINE Developers → ボット基本設定 → Your user ID<br>
                    <strong>グループ：</strong>Webhook で受信した groupId
                </p>
            </div>
        </div>

        <?php submit_button('設定を保存'); ?>
    </form>
</div>
<?php }

/* ============================================================
   Messaging API 送信（管理画面からの再送信のみで使用）
============================================================ */
function lo_send_line( $message, $img_url = '' ) {
    $token = get_option('lo_line_token','');
    $to    = get_option('lo_line_to','');
    if ( empty($token) ) return array('success'=>false,'message'=>'LINEトークンが未設定です。');
    if ( empty($to) )    return array('success'=>false,'message'=>'送信先が未設定です。');

    $messages = array();
    if ( !empty($img_url) ) {
        $https_url  = preg_replace('/^http:/', 'https:', $img_url);
        $messages[] = array(
            'type'               => 'image',
            'originalContentUrl' => $https_url,
            'previewImageUrl'    => $https_url,
        );
    }
    $messages[] = array( 'type' => 'text', 'text' => $message );

    $res = wp_remote_post( 'https://api.line.me/v2/bot/message/push', array(
        'timeout' => 15,
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ),
        'body' => wp_json_encode( array( 'to' => $to, 'messages' => $messages ) ),
    ) );
    if ( is_wp_error($res) ) return array('success'=>false,'message'=>$res->get_error_message());
    $code = (int) wp_remote_retrieve_response_code($res);
    if ( $code === 200 ) return array('success'=>true,'message'=>'送信成功');
    return array('success'=>false,'message'=>'LINE APIエラー('.$code.'): '.wp_remote_retrieve_body($res));
}

/* ============================================================
   AJAX: 注文送信
   ★変更点: LINE push 廃止 → DB保存(pending) + メッセージデータ返却
   　フロントエンドの LIFF SDK が sendMessages() する
============================================================ */
add_action('wp_ajax_lo_submit',        'lo_ajax_submit');
add_action('wp_ajax_nopriv_lo_submit', 'lo_ajax_submit');
function lo_ajax_submit() {
    check_ajax_referer('lo_front','nonce');
    global $wpdb;

    $pid = absint( $_POST['post_id'] ?? 0 );
    if ( !$pid || get_post_type($pid) !== 'lo_product' ) wp_send_json_error('無効なリクエストです。');

    $selected_model = sanitize_text_field( $_POST['lo_selected_model'] ?? '' );
    if ( empty($selected_model) ) wp_send_json_error('型番を選択してください。');

    $product_name = get_the_title($pid);
    $group_label  = '';
    $model_label  = '';
    $groups = get_post_meta($pid,'_lo_groups',true);
    if ( is_array($groups) ) {
        foreach ( $groups as $gi => $g ) {
            foreach ( ($g['options'] ?? array()) as $o ) {
                if ( ($o['model'] ?? '') === $selected_model ) {
                    $group_label = !empty($g['label']) ? $g['label'] : 'グループ'.($gi+1);
                    $model_label = $o['label'] ?? '';
                    break 2;
                }
            }
        }
    }

    $img_id  = (int) get_post_meta($pid,'_lo_img_id',true);
    $img_url = $img_id ? (string) wp_get_attachment_url($img_id) : '';

    $now    = wp_date('Y/m/d H:i', null, new DateTimeZone('Asia/Tokyo'));
    $detail = ( $group_label ? $group_label . '：' : '' )
              . $selected_model
              . ( $model_label ? '（'.$model_label.'）' : '' );
    $message = implode("\n", array(
        '【LINE発注】',
        '商品名：'   . $product_name,
        '選択型番：  ' . $detail,
        '',
        '送信日時：' . $now,
    ));

    /* DB に pending で保存（LIFF 送信完了後に lo_liff_sent で更新） */
    $wpdb->insert( $wpdb->prefix.'lo_orders', array(
        'post_id'      => $pid,
        'product_name' => $product_name,
        'group_label'  => $group_label,
        'model_label'  => $model_label,
        'model_number' => $selected_model,
        'img_id'       => $img_id,
        'img_url'      => $img_url,
        'line_status'  => 'pending',
        'order_status' => 'new',
        'line_message' => $message,
    ), array('%d','%s','%s','%s','%s','%d','%s','%s','%s','%s') );
    $order_id = (int) $wpdb->insert_id;

    $img_https = $img_url ? preg_replace('/^http:/', 'https:', $img_url) : '';

    /* フロントの LIFF sendMessages 用にデータを返す */
    wp_send_json_success( array(
        'order_id'  => $order_id,
        'message'   => $message,
        'img_url'   => $img_https,
        'has_image' => !empty($img_https),
    ) );
}

/* ============================================================
   AJAX: LIFF 送信完了後に line_status を更新
============================================================ */
add_action('wp_ajax_lo_liff_sent',        'lo_ajax_liff_sent');
add_action('wp_ajax_nopriv_lo_liff_sent', 'lo_ajax_liff_sent');
function lo_ajax_liff_sent() {
    check_ajax_referer('lo_front','nonce');
    global $wpdb;
    $oid    = absint( $_POST['order_id'] ?? 0 );
    $result = sanitize_text_field( $_POST['result'] ?? '' );
    $status = ( $result === 'sent' ) ? 'sent_liff' : 'failed';
    if ($oid) {
        $wpdb->update(
            $wpdb->prefix.'lo_orders',
            array('line_status' => $status),
            array('id' => $oid),
            array('%s'), array('%d')
        );
    }
    wp_send_json_success();
}

/* ============================================================
   管理画面 AJAX ハンドラ
============================================================ */
add_action('wp_ajax_lo_update_order_status', 'lo_ajax_update_order_status');
function lo_ajax_update_order_status() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $oid    = absint( $_POST['order_id'] ?? 0 );
    $status = sanitize_text_field( $_POST['status'] ?? '' );
    if ( !$oid || !in_array($status, array('new','processing','done','cancelled')) ) wp_send_json_error('不正なリクエストです。');
    $wpdb->update( $wpdb->prefix.'lo_orders', array('order_status'=>$status), array('id'=>$oid), array('%s'), array('%d') );
    wp_send_json_success();
}

add_action('wp_ajax_lo_resend_line', 'lo_ajax_resend_line');
function lo_ajax_resend_line() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $oid   = absint( $_POST['order_id'] ?? 0 );
    $table = $wpdb->prefix . 'lo_orders';
    $order = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $oid) );
    if (!$order) wp_send_json_error('発注が見つかりません。');
    $r = lo_send_line( $order->line_message, $order->img_url );
    $wpdb->update( $table, array('line_status'=>$r['success']?'sent':'failed'), array('id'=>$oid), array('%s'), array('%d') );
    if ($r['success']) wp_send_json_success('LINE再送信しました。');
    else               wp_send_json_error('再送信失敗: '.$r['message']);
}

add_action('wp_ajax_lo_delete_order', 'lo_ajax_delete_order');
function lo_ajax_delete_order() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $oid = absint( $_POST['order_id'] ?? 0 );
    $wpdb->delete( $wpdb->prefix.'lo_orders', array('id'=>$oid), array('%d') );
    wp_send_json_success();
}

/* ============================================================
   管理画面スクリプト（v1.0 そのまま）
============================================================ */
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

/* ============================================================
   管理画面インライン CSS（v1.0 そのまま）
============================================================ */
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

/* ============================================================
   メタボックス（v1.0 そのまま）
============================================================ */
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
    if (!is_array($groups)||empty($groups)) $groups = array(array('label'=>'','detail'=>'','options'=>array(array('label'=>'','model'=>'')))); ?>
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
    $lbl  = $tpl ? '' : esc_attr($g['label']??'');
    $det  = $tpl ? '' : esc_textarea($g['detail']??'');
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
    $t  = get_post_meta($post->ID,'_lo_btn_text',true) ?: 'ご注文はこちら';
    $bg = get_post_meta($post->ID,'_lo_btn_bg',  true) ?: '#00B900';
    $cl = get_post_meta($post->ID,'_lo_btn_col', true) ?: '#ffffff'; ?>
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

/* ============================================================
   保存処理（v1.0 そのまま）
============================================================ */
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

/* ============================================================
   ショートコード ボックス／一覧（v1.0 そのまま）
============================================================ */
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
                <strong>使い方：</strong>ショートコードをコピー → 固定ページに貼り付けて更新 → そのページのURLをLIFFエンドポイントURLに設定
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

/* ============================================================
   発注履歴ページ（v1.0 ベース + sent_liff バッジ追加）
============================================================ */
function lo_orders_page_html() {
    global $wpdb;
    $table = $wpdb->prefix . 'lo_orders';

    $order_statuses = array(
        'new'        => array('label'=>'新規',      'color'=>'#2271b1'),
        'processing' => array('label'=>'処理中',    'color'=>'#f0b429'),
        'done'       => array('label'=>'完了',      'color'=>'#00B900'),
        'cancelled'  => array('label'=>'キャンセル','color'=>'#dc3545'),
    );
    $line_statuses = array(
        'sent_liff' => array('label'=>'LIFF送信済', 'color'=>'#06C755'),
        'sent'      => array('label'=>'API送信済',  'color'=>'#00B900'),
        'failed'    => array('label'=>'失敗',        'color'=>'#dc3545'),
        'pending'   => array('label'=>'未送信',      'color'=>'#999'),
    );

    $filter_status = isset($_GET['lo_status']) ? sanitize_text_field($_GET['lo_status']) : '';
    $filter_pid    = absint($_GET['lo_pid'] ?? 0);
    $page          = max(1, absint($_GET['paged'] ?? 1));
    $per_page      = 20;
    $offset        = ($page - 1) * $per_page;

    $where = 'WHERE 1=1';
    $params = array();
    if ($filter_status) { $where .= ' AND order_status=%s'; $params[] = $filter_status; }
    if ($filter_pid)    { $where .= ' AND post_id=%d';      $params[] = $filter_pid; }

    if ($params) {
        $count  = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", ...$params) );
        $orders = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge($params, array($per_page, $offset))) );
    } else {
        $count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $orders = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset) );
    }
    $total_pages = max(1, ceil($count / $per_page));
    $products    = get_posts(array('post_type'=>'lo_product','post_status'=>array('publish','draft'),'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC'));
    $admin_nonce = wp_create_nonce('lo_admin');
    $base_url    = admin_url('edit.php?post_type=lo_product&page=lo-orders');
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span class="dashicons dashicons-list-view" style="font-size:28px;width:28px;height:28px;color:#2271b1;"></span>
            発注履歴
            <span style="font-size:14px;font-weight:400;color:#666;margin-left:8px;">全 <?php echo $count; ?> 件</span>
        </h1>

        <div style="display:flex;gap:10px;align-items:center;margin:16px 0;flex-wrap:wrap;background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;">
            <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">
                <input type="hidden" name="post_type" value="lo_product"/>
                <input type="hidden" name="page" value="lo-orders"/>
                <label style="font-size:13px;font-weight:600;">ステータス：</label>
                <select name="lo_status" style="font-size:13px;padding:4px 8px;border-radius:4px;border:1px solid #ccc;">
                    <option value="">すべて</option>
                    <?php foreach ($order_statuses as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php selected($filter_status,$k); ?>><?php echo $v['label']; ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="font-size:13px;font-weight:600;margin-left:8px;">商品：</label>
                <select name="lo_pid" style="font-size:13px;padding:4px 8px;border-radius:4px;border:1px solid #ccc;">
                    <option value="">すべて</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?php echo $p->ID; ?>" <?php selected($filter_pid,$p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">絞り込み</button>
                <a href="<?php echo $base_url; ?>" class="button">リセット</a>
            </form>
        </div>

        <?php if (empty($orders)): ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:40px;text-align:center;color:#888;">
            <p>発注データがありません。</p>
        </div>
        <?php else: ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <span style="font-size:13px;color:#666;"><?php echo (($page-1)*$per_page+1); ?>〜<?php echo min($page*$per_page,$count); ?> 件 / 全 <?php echo $count; ?> 件</span>
            <?php if ($total_pages > 1): ?>
            <div style="display:flex;gap:4px;">
                <?php for($p_=1;$p_<=$total_pages;$p_++): ?>
                <a href="<?php echo $base_url.'&paged='.$p_.($filter_status?'&lo_status='.$filter_status:'').($filter_pid?'&lo_pid='.$filter_pid:''); ?>"
                   style="padding:4px 10px;border:1px solid <?php echo $p_==$page?'#2271b1':'#ccc'; ?>;border-radius:4px;font-size:13px;color:<?php echo $p_==$page?'#fff':'#333'; ?>;background:<?php echo $p_==$page?'#2271b1':'#fff'; ?>;text-decoration:none;"><?php echo $p_; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden;">
            <table class="wp-list-table widefat fixed" style="border:none;">
                <thead>
                    <tr style="background:#f9f9f9;">
                        <th style="width:50px;padding:12px;">ID</th>
                        <th style="width:70px;padding:12px;">画像</th>
                        <th style="padding:12px;">商品名 / 型番</th>
                        <th style="width:100px;padding:12px;">受注状態</th>
                        <th style="width:110px;padding:12px;">LINE送信</th>
                        <th style="padding:12px;width:160px;">受信日時</th>
                        <th style="width:220px;padding:12px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o):
                    $ost   = $order_statuses[$o->order_status] ?? array('label'=>$o->order_status,'color'=>'#888');
                    $lst   = $line_statuses[$o->line_status]   ?? array('label'=>$o->line_status,'color'=>'#888');
                    $thumb = $o->img_url ? wp_get_attachment_image((int)$o->img_id, array(60,60)) : '';
                ?>
                <tr id="lo-row-<?php echo $o->id; ?>" style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:12px;color:#999;font-size:12px;"><?php echo $o->id; ?></td>
                    <td style="padding:8px 12px;">
                        <?php if ($thumb): ?>
                        <div style="width:56px;height:56px;border-radius:6px;overflow:hidden;border:1px solid #e0e0e0;display:flex;align-items:center;justify-content:center;background:#f5f5f5;"><?php echo $thumb; ?></div>
                        <?php else: ?>
                        <div style="width:56px;height:56px;border-radius:6px;border:1px dashed #ccc;display:flex;align-items:center;justify-content:center;background:#fafafa;"><span class="dashicons dashicons-format-image" style="color:#ccc;font-size:22px;width:22px;height:22px;"></span></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px;">
                        <div style="font-weight:700;font-size:14px;color:#222;"><?php echo esc_html($o->product_name); ?></div>
                        <div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <?php if ($o->group_label): ?><span style="font-size:11px;color:#888;"><?php echo esc_html($o->group_label); ?></span><span style="color:#ccc;">›</span><?php endif; ?>
                            <code style="background:#f0f6ff;border:1px solid #c8dff8;border-radius:4px;padding:2px 8px;font-size:13px;font-weight:700;color:#0073aa;"><?php echo esc_html($o->model_number); ?></code>
                            <?php if ($o->model_label): ?><span style="font-size:12px;color:#555;"><?php echo esc_html($o->model_label); ?></span><?php endif; ?>
                        </div>
                    </td>
                    <td style="padding:12px;">
                        <select class="lo-status-sel" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>"
                                style="font-size:12px;padding:3px 6px;border-radius:4px;border:2px solid <?php echo $ost['color']; ?>;color:<?php echo $ost['color']; ?>;font-weight:700;background:#fff;max-width:100%;">
                            <?php foreach ($order_statuses as $k=>$v): ?>
                            <option value="<?php echo $k; ?>" <?php selected($o->order_status,$k); ?>><?php echo $v['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:12px;">
                        <span class="lo-line-badge-<?php echo $o->id; ?>" style="display:inline-block;background:<?php echo $lst['color']; ?>22;color:<?php echo $lst['color']; ?>;border:1px solid <?php echo $lst['color']; ?>55;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700;">
                            <?php echo $lst['label']; ?>
                        </span>
                    </td>
                    <td style="padding:12px;font-size:12px;color:#555;"><?php echo esc_html($o->created_at); ?></td>
                    <td style="padding:12px;">
                        <div style="display:flex;flex-wrap:wrap;gap:4px;">
                            <button type="button" class="button button-small lo-detail-btn" data-id="<?php echo $o->id; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;"><span class="dashicons dashicons-visibility" style="font-size:13px;width:13px;height:13px;"></span>詳細</button>
                            <button type="button" class="button button-small lo-resend-btn" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;color:#00B900;border-color:#00B900;"><span class="dashicons dashicons-update" style="font-size:13px;width:13px;height:13px;"></span>再送信</button>
                            <button type="button" class="button button-small lo-delete-btn" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;color:#dc3545;border-color:#dc3545;"><span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;"></span>削除</button>
                        </div>
                    </td>
                </tr>
                <tr id="lo-detail-<?php echo $o->id; ?>" style="display:none;background:#f9fafb;">
                    <td colspan="7" style="padding:16px 20px;">
                        <div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">
                            <?php if ($o->img_url): ?>
                            <div style="flex-shrink:0;">
                                <div style="font-weight:700;color:#555;font-size:12px;margin-bottom:6px;">🖼 商品画像</div>
                                <div style="width:120px;height:120px;border-radius:8px;overflow:hidden;border:1px solid #e0e0e0;display:flex;align-items:center;justify-content:center;background:#f5f5f5;">
                                    <img src="<?php echo esc_url($o->img_url); ?>" alt="" style="max-width:100%;max-height:100%;object-fit:contain;display:block;"/>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div style="flex:1;min-width:260px;">
                                <div style="font-weight:700;color:#333;margin-bottom:10px;font-size:13px;">📋 LINEメッセージ内容</div>
                                <pre style="background:#f5f5f5;border:1px solid #ddd;border-radius:6px;padding:12px;font-size:13px;line-height:1.7;white-space:pre-wrap;color:#333;margin:0;"><?php echo esc_html($o->line_message); ?></pre>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <style>
    .lo-status-sel option[value="new"]        { color:#2271b1; }
    .lo-status-sel option[value="processing"] { color:#f0b429; }
    .lo-status-sel option[value="done"]       { color:#00B900; }
    .lo-status-sel option[value="cancelled"]  { color:#dc3545; }
    </style>
    <script>
    (function($){
        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        $(document).on('change', '.lo-status-sel', function(){
            var $s=$(this), oid=$s.data('id'), nonce=$s.data('nonce'), st=$s.val();
            var colors={new:'#2271b1',processing:'#f0b429',done:'#00B900',cancelled:'#dc3545'};
            $.post(ajaxUrl,{action:'lo_update_order_status',order_id:oid,status:st,nonce:nonce})
             .done(function(r){ if(r.success) $s.css({borderColor:colors[st]||'#ccc',color:colors[st]||'#333'}); });
        });
        $(document).on('click','.lo-detail-btn',function(){
            var id=$(this).data('id');
            $('#lo-detail-'+id).toggle();
            $(this).find('.dashicons').toggleClass('dashicons-visibility dashicons-hidden');
        });
        $(document).on('click','.lo-resend-btn',function(){
            var $b=$(this),oid=$b.data('id'),nonce=$b.data('nonce');
            $b.prop('disabled',true).text('送信中...');
            $.post(ajaxUrl,{action:'lo_resend_line',order_id:oid,nonce:nonce})
             .done(function(r){
                 var $bg=$('.lo-line-badge-'+oid);
                 if(r.success){$bg.text('API送信済').css({color:'#00B900',borderColor:'#00B90055',background:'#00B90022'});alert('LINE再送信しました。');}
                 else{$bg.text('失敗').css({color:'#dc3545',borderColor:'#dc354555',background:'#dc354522'});alert('失敗: '+r.data);}
             })
             .always(function(){$b.prop('disabled',false).html('<span class="dashicons dashicons-update" style="font-size:13px;width:13px;height:13px;"></span>再送信');});
        });
        $(document).on('click','.lo-delete-btn',function(){
            if(!confirm('この発注を削除しますか？'))return;
            var $b=$(this),oid=$b.data('id'),nonce=$b.data('nonce');
            $.post(ajaxUrl,{action:'lo_delete_order',order_id:oid,nonce:nonce})
             .done(function(r){ if(r.success){$('#lo-row-'+oid+',#lo-detail-'+oid).fadeOut(300,function(){$(this).remove();}); }});
        });
    })(jQuery);
    </script>
    <?php
}

/* ============================================================
   管理画面コピー JS（v1.0 そのまま）
============================================================ */
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
    $(document).on('click', '.lo-sc-copy-btn',   function(e){ e.preventDefault(); loCopyText($(this).data('copy'), $(this)); });
    $(document).on('click', '.lo-list-copy-btn', function(e){ e.preventDefault(); loCopyText($(this).data('copy'), $(this)); });
    $(document).on('click', '.lo-page-copy-btn', function(e){ e.preventDefault(); loCopyText($(this).data('copy'), $(this)); });
})(jQuery);
</script>
<style>
.lo-sc-copy-btn.lo-copied,.lo-list-copy-btn.lo-copied,.lo-page-copy-btn.lo-copied{background:#00B900!important;border-color:#00B900!important;color:#fff!important;}
</style>
<?php }

/* ============================================================
   管理画面インライン JS（v1.0 そのまま）
============================================================ */
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

/* ============================================================
   フロントエンド スクリプト登録
   ★変更: LIFF SDK を head に追加 + liff_id を JS に渡す
============================================================ */
/* フック登録はすべてトップレベルで行う（wp_enqueue_scripts の内側では wp_footer が機能しない場合がある） */
add_action('wp_enqueue_scripts', 'lo_front_scripts');
add_action('wp_head',            'lo_front_css');
add_action('wp_head',            'lo_liff_sdk_tag');
add_action('wp_head',            'lo_front_vars');
add_action('wp_head',            'lo_front_js');

function lo_front_scripts() {
    wp_enqueue_script('jquery');
}

/* loFront 変数を直接 <script> で出力（wp_localize_script 非依存） */
function lo_front_vars() { ?>
<script>
var loFront = {
    ajax_url: <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>,
    nonce:    <?php echo wp_json_encode( wp_create_nonce('lo_front') ); ?>,
    liff_id:  <?php echo wp_json_encode( (string) get_option('lo_liff_id','') ); ?>
};
</script>
<?php }

function lo_liff_sdk_tag() {
    if ( !get_option('lo_liff_id','') ) return;
    echo '<script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>' . "\n";
}

/* ============================================================
   フロントエンド CSS（v1.0 そのまま）
============================================================ */
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
.lo-rmsg.ok{color:#00B900}.lo-rmsg.ng{color:#dc3545}.lo-rmsg.ng-persist{color:#dc3545}
.lo-err{color:#dc3545;background:#fff3f3;border:1px solid #f5c6cb;border-radius:6px;padding:12px 16px;font-size:14px}
@media(max-width:820px){.lo-grid{grid-template-columns:1fr;gap:0}.lo-col-l{position:static;margin-bottom:24px}.lo-sbtn{min-width:220px;padding:15px 40px;font-size:16px}}
@media(max-width:480px){.lo-wrap{padding:16px 12px 32px}.lo-ci{padding:10px 12px}.lo-sbtn{width:90%;min-width:unset;padding:14px 24px;font-size:15px}}
</style>
<?php }

/* ============================================================
   フロントエンド JS
   ★変更: loSubmit を LIFF sendMessages ベースに変更
   　loSelectCard / loCopyModel / loMarkCopied は v1.0 そのまま
============================================================ */
function lo_front_js() { ?>
<script>
/* ================================================================
   ユーティリティ
================================================================ */

/* LINEアプリ内ブラウザかどうかを User-Agent で判定
   ※ liff.init() を呼ぶ前に確認することでリダイレクトを防ぐ */
function loIsLineApp() {
    return navigator.userAgent.indexOf('Line/') !== -1;
}

/* メッセージ表示（.lo-rmsg に表示） */
function loShowMsg($el, cls, msg) {
    $el.removeClass('ok ng').addClass(cls).html(msg);
    if (cls !== 'ng-persist') {
        setTimeout(function(){ $el.html('').removeClass('ok ng ng-persist'); }, 9000);
    }
}

/* ================================================================
   PCブラウザ用：LIFF URL 案内モーダルを表示
   ─ ボタン押下時に liff.line.me URL + QR コードをページ内に表示する
================================================================ */
/* ================================================================
   PCブラウザ用：注文内容プレビュー + QR / LIFF URL 案内モーダル
   orderData = { img_url, has_image, message, model_label, model_number }
================================================================ */
function loShowLiffGuide(orderData) {
    var liffId  = (typeof loFront !== 'undefined') ? loFront.liff_id : '';
    var liffUrl = 'https://liff.line.me/' + liffId;
    orderData   = orderData || {};

    jQuery('#lo-liff-modal').remove();

    /* QRコードはライブラリで動的生成 */

    /* 注文内容プレビュー HTML */
    var previewHtml = '';
    if (orderData.has_image && orderData.img_url) {
        previewHtml +=
            '<div style="margin-bottom:10px;">'
            + '<img src="' + orderData.img_url + '" alt="" style="'
            +     'max-width:100%;max-height:140px;object-fit:contain;'
            +     'border-radius:8px;border:1px solid #eee;"/>'
            + '</div>';
    }
    if (orderData.message) {
        /* 発注テキストを行ごとに整形して表示 */
        var lines = orderData.message.split(String.fromCharCode(10)).map(function(l){ return l.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); });
        previewHtml +=
            '<div style="'
            +   'background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;'
            +   'padding:10px 12px;margin-bottom:14px;text-align:left;">'
            + '<p style="font-size:11px;color:#888;margin:0 0 6px;font-weight:bold;">📋 発注内容</p>'
            + '<div style="font-size:12px;color:#333;line-height:1.8;white-space:pre-wrap;">'
            +   lines.join(String.fromCharCode(10))
            + '</div>'
            + '</div>';
    }

    var html =
        '<div id="lo-liff-modal" style="'
        +   'position:fixed;top:0;left:0;width:100%;height:100%;'
        +   'background:rgba(0,0,0,.6);z-index:99999;'
        +   'display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">'
        +   '<div style="'
        +       'background:#fff;border-radius:16px;padding:24px 20px;max-width:420px;width:100%;'
        +       'text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.3);position:relative;'
        +       'max-height:90vh;overflow-y:auto;">'
        +     '<button id="lo-liff-modal-close" style="'
        +         'position:absolute;top:12px;right:14px;background:none;border:none;'
        +         'font-size:22px;cursor:pointer;color:#999;line-height:1;z-index:1;">✕</button>'

        /* ヘッダー */
        +     '<div style="font-size:13px;font-weight:bold;color:#00B900;margin-bottom:14px;'
        +         'background:#f0fff0;border:1px solid #b2dfb2;border-radius:8px;padding:8px 12px;">'
        +       '✅ 発注内容を確認してください'
        +     '</div>'

        /* 注文プレビュー */
        +     previewHtml

        /* QRコード案内 */
        +     '<div style="border-top:1px solid #eee;padding-top:14px;">'
        +       '<p style="font-size:13px;color:#333;margin:0 0 10px;font-weight:bold;">📱 LINEアプリで送信する</p>'
        +       '<p style="font-size:12px;color:#666;margin:0 0 12px;line-height:1.6;">'
        +         'スマートフォンのLINEでQRを読み取るか、<br>URLをコピーしてLINEで開いてください。'
        +       '</p>'
        +       '<div id="lo-qr-canvas" style="width:160px;height:160px;margin:0 auto 12px;border:1px solid #eee;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#fff;"><span style="font-size:11px;color:#aaa;">生成中...</span></div>'
        +       '<div style="background:#f5f5f5;border-radius:8px;padding:8px 10px;">'
        +         '<div style="display:flex;align-items:center;gap:6px;">'
        +           '<code style="font-size:11px;color:#333;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left;">'
        +             liffUrl
        +           '</code>'
        +           '<button id="lo-liff-url-copy" style="'
        +               'flex-shrink:0;background:#06C755;color:#fff;border:none;border-radius:6px;'
        +               'padding:5px 12px;font-size:12px;cursor:pointer;white-space:nowrap;font-weight:bold;">コピー</button>'
        +         '</div>'
        +       '</div>'
        +     '</div>'
        +   '</div>'
        + '</div>';

    jQuery('body').append(html);

    /* QRコード生成（qrcode.js ライブラリを動的ロードして生成） */
    (function(){
        var container = document.getElementById('lo-qr-canvas');
        if (!container) return;
        function generateQR() {
            container.innerHTML = '';
            new QRCode(container, {
                text:         liffUrl,
                width:        156,
                height:       156,
                colorDark:    '#000000',
                colorLight:   '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }
        if (typeof QRCode !== 'undefined') {
            generateQR();
        } else {
            var s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
            s.onload = generateQR;
            document.head.appendChild(s);
        }
    })();

    jQuery('#lo-liff-modal-close, #lo-liff-modal').on('click', function(e){
        if (e.target === this) jQuery('#lo-liff-modal').fadeOut(200, function(){ jQuery(this).remove(); });
    });
    jQuery('#lo-liff-modal > div').on('click', function(e){ e.stopPropagation(); });

    jQuery('#lo-liff-url-copy').on('click', function(){
        var $b = jQuery(this);
        var ta = document.createElement('textarea');
        ta.value = liffUrl;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch(e) {}
        document.body.removeChild(ta);
        $b.text('✓ コピー済').css('background','#555');
        setTimeout(function(){ $b.text('コピー').css('background','#06C755'); }, 2000);
    });
}

/* ================================================================
   LIFF 初期化（LINEアプリ内でのみ実行）
================================================================ */
var _loLiffReady   = false;
var _loLiffFailed  = false;
var _loLiffIniting = false;

function loInitLiff(callback) {
    var liffId = (typeof loFront !== 'undefined') ? loFront.liff_id : '';

    /* ── LIFF ID 未設定 ── */
    if (!liffId) {
        callback(false, 'liff_id_missing');
        return;
    }

    /* ── LINEアプリ外（PC・スマホブラウザ）── liff.init() を呼ばずに即返す */
    if (!loIsLineApp()) {
        callback(false, 'not_line_app');
        return;
    }

    /* ── SDK 未ロード ── */
    if (typeof liff === 'undefined') {
        callback(false, 'sdk_missing');
        return;
    }

    if (_loLiffReady)  { callback(true); return; }
    if (_loLiffFailed) { callback(false, 'init_failed'); return; }
    if (_loLiffIniting) {
        var t = setInterval(function(){
            if (_loLiffReady)  { clearInterval(t); callback(true); }
            if (_loLiffFailed) { clearInterval(t); callback(false, 'init_failed'); }
        }, 100);
        return;
    }

    _loLiffIniting = true;
    liff.init({ liffId: liffId })
        .then(function()  { _loLiffReady = true;  _loLiffIniting = false; callback(true); })
        .catch(function(e){ _loLiffFailed = true; _loLiffIniting = false;
            console.error('liff.init error:', e);
            callback(false, 'init_error:' + (e.message || e));
        });
}

/* ================================================================
   ラジオ選択 → カードハイライト ＋ コピーボタン表示（v1.0 そのまま）
================================================================ */
function loSelectCard(radio) {
    var wrap = radio.closest('.lo-wrap');
    var all  = wrap.querySelectorAll('input.lo-radio');
    for (var i = 0; i < all.length; i++) {
        var ci  = all[i].closest('.lo-ci');
        ci.style.borderColor = '';
        ci.style.boxShadow   = '';
        ci.style.background  = '';
        var btn = ci.querySelector('.lo-cpbtn');
        if (btn) { btn.style.display = 'none'; btn.textContent = '⧉ コピー'; btn.style.background = ''; btn.style.color = ''; }
    }
    var selCi = radio.closest('.lo-ci');
    selCi.style.borderColor = '#00B900';
    selCi.style.boxShadow   = '3px 3px 0 0 #66cc66';
    selCi.style.background  = '#f0fff0';
    var selBtn = selCi.querySelector('.lo-cpbtn');
    if (selBtn) selBtn.style.display = 'inline-block';
}

/* ================================================================
   コピーボタン → 型番をクリップボードへ（v1.0 そのまま）
================================================================ */
function loCopyModel(btn) {
    var mnum = btn.closest('.lo-ci-bottom').querySelector('.lo-mnum');
    if (!mnum) return;
    var text = mnum.textContent.trim();
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;padding:0;border:none;font-size:16px;opacity:0;';
    document.body.appendChild(ta);
    ta.focus(); ta.select(); ta.setSelectionRange(0, ta.value.length);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
    if (!ok && navigator.clipboard) { navigator.clipboard.writeText(text).then(function(){ loMarkCopied(btn); }); return; }
    if (ok) loMarkCopied(btn);
}
function loMarkCopied(btn) {
    btn.textContent = '✓ コピー済み'; btn.style.background = '#00B900'; btn.style.color = '#fff';
}

/* ================================================================
   送信ボタン — メインフロー
   LINEアプリ外  → QR / LIFF URL 案内モーダルを表示
   LINEアプリ内  → AJAX → liff.sendMessages() → DB更新
================================================================ */
function loSubmit(pid) {
    var $b      = jQuery('[data-pid="'+pid+'"].lo-sbtn');
    var $w      = jQuery('#lo-w-'+pid);
    var $r      = jQuery('#lo-r-'+pid);
    var checked = $w.find('input.lo-radio:checked')[0];
    if (!checked) { loShowMsg($r, 'ng', '型番を選択してください。'); return; }

    var origText = jQuery.trim($b.text());
    $b.prop('disabled', true).text('処理中...');
    $r.removeClass('ok ng ng-persist').html('');

    loInitLiff(function(liffOk, errCode) {

        /* ── LINEアプリ外 → AJAX で発注データ取得 → 注文内容プレビュー付きモーダル表示 ── */
        if (errCode === 'not_line_app') {
            jQuery.post(loFront.ajax_url, {
                action:            'lo_submit',
                nonce:             loFront.nonce,
                post_id:           pid,
                lo_selected_model: checked.value
            })
            .done(function(res) {
                $b.prop('disabled', false).text(origText);
                if (res.success) {
                    loShowLiffGuide(res.data);
                } else {
                    loShowMsg($r, 'ng', res.data || '発注データの取得に失敗しました。');
                }
            })
            .fail(function() {
                $b.prop('disabled', false).text(origText);
                loShowMsg($r, 'ng', '通信エラーが発生しました。');
            });
            return;
        }

        /* ── LIFF ID未設定 ── */
        if (errCode === 'liff_id_missing') {
            loShowMsg($r, 'ng',
                '<span style="display:block;padding:12px 14px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;font-size:13px;line-height:1.7;color:#856404;">'
                + '⚠️ <strong>LIFF IDが設定されていません。</strong><br>'
                + 'WordPress管理画面 → LINE発注 → LINE設定 で LIFF ID を登録してください。'
                + '</span>');
            $b.prop('disabled', false).text(origText);
            return;
        }

        /* ── SDK未ロード ── */
        if (errCode === 'sdk_missing') {
            loShowMsg($r, 'ng', 'LIFF SDKの読み込みに失敗しました。ページを再読み込みしてください。');
            $b.prop('disabled', false).text(origText);
            return;
        }

        /* ── init エラー ── */
        if (!liffOk) {
            loShowMsg($r, 'ng', 'LIFF初期化エラーが発生しました。ページを再読み込みして再度お試しください。');
            $b.prop('disabled', false).text(origText);
            return;
        }

        /* ── LINEアプリ内：AJAX → LINE送信 ── */
        jQuery.post(loFront.ajax_url, {
            action:            'lo_submit',
            nonce:             loFront.nonce,
            post_id:           pid,
            lo_selected_model: checked.value
        })
        .done(function(res) {
            if (!res.success) {
                loShowMsg($r, 'ng', res.data || '送信に失敗しました。');
                $b.prop('disabled', false).text(origText);
                return;
            }
            var d           = res.data;
            var orderId     = d.order_id;
            var lineMessages = [];
            if (d.has_image && d.img_url) {
                lineMessages.push({ type:'image', originalContentUrl:d.img_url, previewImageUrl:d.img_url });
            }
            lineMessages.push({ type:'text', text:d.message });

            loSendViaLiff(lineMessages, function(sent) {
                jQuery.post(loFront.ajax_url, {
                    action:'lo_liff_sent', nonce:loFront.nonce,
                    order_id:orderId, result:sent?'sent':'failed'
                });
                if (sent) {
                    loShowMsg($r, 'ok', 'ご注文を受け付けました。LINEへ送信しました。');
                } else {
                    loShowMsg($r, 'ng', '発注を保存しましたが、LINE送信に失敗しました。管理画面から再送信できます。');
                }
                $b.prop('disabled', false).text(origText);
            });
        })
        .fail(function() {
            loShowMsg($r, 'ng', '通信エラーが発生しました。');
            $b.prop('disabled', false).text(origText);
        });
    });
}

/* ================================================================
   LIFF 送信ロジック（LINEアプリ内専用）
   ・トークから開いた   → sendMessages（そのトークに送信）
   ・LINEメニュー等から → shareTargetPicker（送信先を選択）
================================================================ */
function loSendViaLiff(messages, callback) {
    if (liff.isInClient()) {
        liff.sendMessages(messages)
            .then(function()  { callback(true);  })
            .catch(function(e){ console.error('sendMessages error:', e); callback(false); });
        return;
    }
    if (liff.isApiAvailable('shareTargetPicker')) {
        liff.shareTargetPicker(messages, { isMultiple:false })
            .then(function(res){ callback(!!(res && res.status === 'success')); })
            .catch(function(e){ console.error('shareTargetPicker error:', e); callback(false); });
        return;
    }
    /* フォールバック：上記どちらも使えない場合 */
    loShowLiffGuide();
    callback(false);
}
</script>
<?php }

/* ============================================================
   ショートコード [line_order id="X"]（v1.0 そのまま）
============================================================ */
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
