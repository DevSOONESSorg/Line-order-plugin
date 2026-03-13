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
 *
 * このファイルを line-order.php としてフォルダ line-order/ に配置してください。
 * 例: wp-content/plugins/line-order/line-order.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;


if ( ! defined('LO_VERSION') )    define( 'LO_VERSION', '2.1.0' );
if ( ! defined('LO_URL') )        define( 'LO_URL', plugin_dir_url( __FILE__ ) );

/* カテゴリ編集ページを開いた際に「LINE発注」メニューをアクティブ表示にする */
add_filter( 'parent_file', function( $parent_file ) {
    global $current_screen;
    if ( isset($current_screen) && $current_screen->taxonomy === 'lo_category' ) {
        return 'edit.php?post_type=lo_product';
    }
    return $parent_file;
} );

add_filter( 'submenu_file', function( $submenu_file ) {
    global $current_screen;
    if ( isset($current_screen) && $current_screen->taxonomy === 'lo_category' ) {
        return 'edit-tags.php?taxonomy=lo_category&post_type=lo_product';
    }
    return $submenu_file;
} );

/* 二重読み込み防止：このプラグインが既にロード済みなら全hookをスキップ */
if ( defined('LO_LOADED') ) return;
define( 'LO_LOADED', true );

/* ============================================================
   カスタム投稿タイプ（v1.0 そのまま）
============================================================ */
/* 管理画面初回表示時にリライトルールを自動更新 */
add_action( 'admin_init', function() {
    if ( get_option('lo_flush_rewrite') ) {
        flush_rewrite_rules();
        delete_option('lo_flush_rewrite');
    }
} );

add_action( 'init', 'lo_register_post_type' );
function lo_register_post_type() {
    register_post_type( 'lo_product', array(
        'labels' => array(
            'name'               => 'LINE発注',
            'singular_name'      => '商品',
            'add_new'            => '新規追加',
            'add_new_item'       => '新規商品を追加',
            'edit_item'          => '商品を編集',
            'all_items'          => '投稿一覧',
            'menu_name'          => 'LINE発注',
            'not_found'          => '商品が見つかりません',
            'not_found_in_trash' => 'ゴミ箱に商品はありません',
        ),
        'public'              => true,           // trueにしてshow_*が確実に機能するように
        'publicly_queryable'  => true,            // カテゴリアーカイブ用に有効化
        'exclude_from_search' => true,            // 検索結果に出さない
        'show_in_nav_menus'   => false,           // ナビメニューには出さない
        'show_ui'             => true,
        'show_in_menu'        => false,   // 自前の add_menu_page で管理
        'show_in_admin_bar'   => true,
        'menu_position'       => 25,
        'menu_icon'           => 'dashicons-store',
        'supports'            => array( 'title' ),
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'show_in_rest'        => false,
        'rewrite'             => false,
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
        'public'            => true,
        'publicly_queryable'=> true,
        'query_var'         => true,
        'rewrite'           => array( 'slug' => 'lo-category', 'with_front' => false ),
    ) );
}

register_activation_hook( __FILE__, function() {
    lo_register_post_type();
    lo_register_taxonomy();
    lo_create_orders_table();
    update_option( 'lo_flush_rewrite', 1 );
    update_option( 'lo_activated_notice', 1 );
    flush_rewrite_rules();
} );

/* 有効化直後に管理画面でリロードを促す通知を表示 */
add_action( 'admin_notices', function() {
    if ( ! get_option('lo_activated_notice') ) return;
    delete_option('lo_activated_notice');
    echo '<div class="notice notice-success is-dismissible"><p>'
        . '<strong>LINE発注プラグインを有効化しました。</strong> '
        . 'メニューが表示されない場合は <a href="' . esc_url( admin_url() ) . '">管理画面のトップ</a> に移動してください。'
        . '</p></div>';
} );

register_deactivation_hook( __FILE__, function() {
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
        sent_at      DATETIME                NULL DEFAULT NULL,
        updated_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_post_id      (post_id),
        KEY idx_order_status (order_status),
        KEY idx_created_at   (created_at)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'lo_db_version', '1.2' );
}

add_action( 'plugins_loaded', function() {
    global $wpdb;
    /* DBテーブルが存在しない場合は常に作成（ファイル名変更後の再有効化にも対応） */
    lo_create_orders_table();
    $table = $wpdb->prefix . 'lo_orders';
    if ( $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table ) {
        $cols = $wpdb->get_col( "DESC {$table}", 0 );
        if ( !in_array('img_id',  $cols) ) $wpdb->query("ALTER TABLE {$table} ADD img_id  BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER model_number");
        if ( !in_array('img_url', $cols) ) $wpdb->query("ALTER TABLE {$table} ADD img_url TEXT           NOT NULL DEFAULT '' AFTER img_id");
    }
} );

/* ============================================================
   管理メニュー（v1.0 そのまま）
============================================================ */
/* ============================================================
   管理メニュー：add_menu_page で直接登録（show_in_menu に依存しない）
============================================================ */
add_action( 'admin_menu', 'lo_build_admin_menu', 1 ); // priority=1 で最優先登録
function lo_build_admin_menu() {

    /* トップメニュー「LINE発注」を強制的に追加 */
    add_menu_page(
        'LINE発注',                         // ページタイトル
        'LINE発注',                         // メニューラベル
        'edit_posts',                       // 権限
        'edit.php?post_type=lo_product',    // スラッグ（投稿一覧ページ）
        '',                                 // コールバック不要（URLリダイレクト）
        'dashicons-store',                  // アイコン
        25                                  // 位置
    );

    /* サブメニュー：投稿一覧（トップと同じURLにする） */
    add_submenu_page(
        'edit.php?post_type=lo_product',
        '投稿一覧', '投稿一覧', 'edit_posts',
        'edit.php?post_type=lo_product'
    );

    /* サブメニュー：新規追加 */
    add_submenu_page(
        'edit.php?post_type=lo_product',
        '新規追加', '新規追加', 'edit_posts',
        'post-new.php?post_type=lo_product'
    );

    /* サブメニュー：カテゴリ */
    add_submenu_page(
        'edit.php?post_type=lo_product',
        'カテゴリ', 'カテゴリ', 'manage_categories',
        'edit-tags.php?taxonomy=lo_category&post_type=lo_product'
    );

    /* サブメニュー：発注履歴 */
    add_submenu_page(
        'edit.php?post_type=lo_product',
        '発注履歴', '発注履歴', 'edit_posts',
        'lo-orders', 'lo_orders_page_html'
    );
}

/* lo_add_orders_page は互換性のため残す（呼び出し元があれば使われる） */
function lo_add_orders_page() {
    // lo_build_admin_menu に統合済み
}

/* sent_at カラムが未追加の場合は自動で ALTER TABLE する */
add_action('admin_init', 'lo_maybe_migrate_db');
function lo_maybe_migrate_db() {
    global $wpdb;
    $table = $wpdb->prefix . 'lo_orders';
    if ( $wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table ) return;
    $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'sent_at'");
    if ( empty($col) ) {
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `sent_at` DATETIME NULL DEFAULT NULL AFTER `created_at`");
    }
    $col2 = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'is_trashed'");
    if ( empty($col2) ) {
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `is_trashed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sent_at`");
    }
}

add_action( 'admin_menu', 'lo_add_settings_page', 10 );
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
    register_setting( 'lo_settings', 'lo_line_talk_url' );
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
                LINEアプリ内から開いた場合は自動送信。
                PCブラウザから開いた場合は発注内容をクリップボードにコピーし、指定のLINEトーク画面を開きます。
            </div>
        </div>

        <!-- ========== LINEトークURL設定 ========== -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;max-width:700px;margin-bottom:20px;">
            <h2 style="margin:0 0 4px;font-size:16px;display:flex;align-items:center;gap:8px;">
                <span style="background:#06C755;color:#fff;border-radius:6px;padding:2px 12px;font-size:13px;font-weight:700;">LINE</span>
                PCブラウザ用 LINEトークURL設定
            </h2>
            <p style="font-size:13px;color:#666;margin:6px 0 16px;">
                PCブラウザで送信ボタンが押されたとき、発注内容をクリップボードにコピーして開くLINEトーク画面のURLを設定します。
            </p>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:5px;">LINEトークURL</label>
                <input type="text" name="lo_line_talk_url"
                       value="<?php echo esc_attr( get_option('lo_line_talk_url','https://line.me/R/ti/p/@227juwnw') ); ?>"
                       style="width:100%;max-width:480px;" class="regular-text"
                       placeholder="https://line.me/R/ti/p/@xxxxx"/>
                <p class="description">
                    LINEアカウントのURLを入力してください。<br>
                    例：<code>https://line.me/R/ti/p/@227juwnw</code>（公式アカウントの場合）<br>
                    例：<code>https://line.me/ti/g/xxxxxx</code>（グループの場合）
                </p>
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
function lo_send_line( $message, $img_url = '', $to_override = '' ) {
    $token = get_option('lo_line_token','');
    $to    = ! empty($to_override) ? $to_override : get_option('lo_line_to','');
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

    /* LINEアプリ内からのリクエスト → Messaging API で即送信 */
    $send_via_api = ! empty( $_POST['line_app'] );
    $line_sent    = false;
    if ( $send_via_api ) {
        $line_uid = sanitize_text_field( $_POST['line_uid'] ?? '' );
        /* POST にない場合は cookie からフォールバック */
        if ( empty($line_uid) && ! empty($_COOKIE['lo_line_uid']) ) {
            $line_uid = sanitize_text_field( $_COOKIE['lo_line_uid'] );
        }
        $result = lo_send_line( $message, $img_https, $line_uid );
        if ( $result['success'] ) {
            $line_sent = true;
            $wpdb->update(
                $wpdb->prefix . 'lo_orders',
                array( 'line_status' => 'sent' ),
                array( 'id' => $order_id ),
                array( '%s' ),
                array( '%d' )
            );
        }
    }

    wp_send_json_success( array(
        'order_id'   => $order_id,
        'message'    => $message,
        'img_url'    => $img_https,
        'has_image'  => !empty($img_https),
        'line_sent'  => $line_sent,
        'line_error' => ( $send_via_api && ! $line_sent ) ? ( $result['message'] ?? '' ) : '',
    ) );
}

/* ============================================================
   AJAX: order_id から発注データを取得（QRコード自動送信用）
============================================================ */
add_action('wp_ajax_lo_get_order',        'lo_ajax_get_order');
add_action('wp_ajax_nopriv_lo_get_order', 'lo_ajax_get_order');
function lo_ajax_get_order() {
    check_ajax_referer('lo_front','nonce');
    global $wpdb;
    $oid   = absint( $_POST['order_id'] ?? 0 );
    if ( !$oid ) wp_send_json_error('無効なリクエストです。');
    $table = $wpdb->prefix . 'lo_orders';
    $order = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $oid) );
    if ( !$order ) wp_send_json_error('発注データが見つかりません。');
    $img_https = $order->img_url ? preg_replace('/^http:/', 'https:', $order->img_url) : '';
    wp_send_json_success( array(
        'order_id'  => (int) $order->id,
        'message'   => $order->line_message,
        'img_url'   => $img_https,
        'has_image' => !empty($img_https),
        'status'    => $order->line_status,
    ) );
}

/* ============================================================
   AJAX: LIFF 送信完了後に line_status を更新
============================================================ */
add_action('wp_ajax_lo_liff_sent',        'lo_ajax_liff_sent');
add_action('wp_ajax_nopriv_lo_liff_sent', 'lo_ajax_liff_sent');
function lo_ajax_liff_sent() {
    /*
     * $die=false: キャッシュプラグインで nonce が古くなっても wp_die() しない。
     * sendBeacon はレスポンスを見ないため、403 で死ぬと DB が更新されずに
     * 「未送信」のままになる。verify に失敗しても処理を継続し、
     * 下記の order 存在チェックで安全性を担保する。
     */
    check_ajax_referer( 'lo_front', 'nonce', false );

    global $wpdb;
    $oid    = absint( $_POST['order_id'] ?? 0 );
    $result = sanitize_text_field( $_POST['result'] ?? '' );
    if ( ! $oid ) { wp_send_json_error( 'invalid_id' ); return; }

    $table = $wpdb->prefix . 'lo_orders';

    /* 発注 ID が実際に存在するかを確認（セキュリティ担保） */
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id=%d LIMIT 1", $oid ) );
    if ( ! $exists ) { wp_send_json_error( 'not_found' ); return; }

    $status = ( $result === 'sent' ) ? 'sent_liff' : 'failed';

    /* line_status を更新 */
    $wpdb->update( $table, array( 'line_status' => $status ), array( 'id' => $oid ) );

    /* sent_at を更新 */
    if ( $status === 'sent_liff' ) {
        $wpdb->update( $table, array( 'sent_at' => current_time( 'mysql' ) ), array( 'id' => $oid ) );
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
    $upd = array('line_status' => $r['success'] ? 'sent' : 'failed');
    if ($r['success']) $upd['sent_at'] = current_time('mysql');
    $wpdb->update( $table, $upd, array('id'=>$oid) );
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

/* 一括ゴミ箱移動 */
add_action('wp_ajax_lo_bulk_trash', 'lo_ajax_bulk_trash');
function lo_ajax_bulk_trash() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : array();
    if ( empty($ids) ) wp_send_json_error('対象が選択されていません。');
    $table = $wpdb->prefix . 'lo_orders';
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query( $wpdb->prepare("UPDATE {$table} SET is_trashed=1 WHERE id IN ({$placeholders})", ...$ids) );
    wp_send_json_success( array('count' => count($ids)) );
}

/* ゴミ箱から戻す */
add_action('wp_ajax_lo_bulk_restore', 'lo_ajax_bulk_restore');
function lo_ajax_bulk_restore() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : array();
    if ( empty($ids) ) wp_send_json_error('対象が選択されていません。');
    $table = $wpdb->prefix . 'lo_orders';
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query( $wpdb->prepare("UPDATE {$table} SET is_trashed=0 WHERE id IN ({$placeholders})", ...$ids) );
    wp_send_json_success( array('count' => count($ids)) );
}

/* 一括完全削除 */
add_action('wp_ajax_lo_bulk_delete', 'lo_ajax_bulk_delete');
function lo_ajax_bulk_delete() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : array();
    if ( empty($ids) ) wp_send_json_error('削除対象が選択されていません。');
    $table = $wpdb->prefix . 'lo_orders';
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query( $wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids) );
    wp_send_json_success( array('deleted' => count($ids)) );
}

/* ゴミ箱を空にする（ゴミ箱内を全削除） */
add_action('wp_ajax_lo_empty_trash', 'lo_ajax_empty_trash');
function lo_ajax_empty_trash() {
    check_ajax_referer('lo_admin','nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('権限がありません。');
    global $wpdb;
    $table = $wpdb->prefix . 'lo_orders';
    $deleted = $wpdb->query("DELETE FROM {$table} WHERE is_trashed=1");
    wp_send_json_success( array('deleted' => $deleted) );
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
/* フィルタープルダウンのチェックマーク非表示（Chrome v119+対応） */
.lo-filter-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-color: #fff;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23555' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    padding-right: 28px !important;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 13px;
    padding-top: 4px;
    padding-bottom: 4px;
    padding-left: 8px;
    cursor: pointer;
}
.lo-filter-select:focus { outline: 1px solid #2271b1; border-color: #2271b1; }
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
            <div style="background:#e8f4fd;border:1px solid #b3d9f5;border-radius:8px;padding:14px 18px;margin-bottom:16px;font-size:13px;">
                <strong>使い方：</strong>ショートコードをコピー → 固定ページに貼り付けて更新 → そのページのURLをLIFFエンドポイントURLに設定
            </div>
            <div style="background:#f0fff0;border:1px solid #b2dfb2;border-radius:8px;padding:14px 18px;margin-bottom:24px;font-size:13px;">
                <strong>📂 カテゴリ一覧ショートコード：</strong>
                固定ページに <code>[line_order_list]</code> を貼り付けると全カテゴリの商品一覧が表示されます。<br>
                特定カテゴリのみ表示する場合は <code>[line_order_list category="カテゴリスラッグ"]</code> を使用してください。
            </div>
            <table style="width:100%;border-collapse:collapse;">
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
    $filter_pid    = isset($_GET['lo_pid']) && $_GET['lo_pid'] !== '' ? absint($_GET['lo_pid']) : '';
    $view_trash    = isset($_GET['lo_view']) && $_GET['lo_view'] === 'trash';
    $page          = max(1, absint($_GET['paged'] ?? 1));
    $per_page      = 20;
    $offset        = ($page - 1) * $per_page;

    $where = 'WHERE 1=1';
    $params = array();
    /* ゴミ箱タブのときはis_trashed=1、通常はis_trashed=0 */
    $where .= $view_trash ? ' AND is_trashed=1' : ' AND (is_trashed=0 OR is_trashed IS NULL)';
    if ($filter_status) { $where .= ' AND order_status=%s'; $params[] = $filter_status; }
    if ($filter_pid !== '')    { $where .= ' AND post_id=%d'; $params[] = absint($filter_pid); }
    /* ゴミ箱件数（タブ表示用） */
    $trash_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_trashed=1");

    if ($params) {
        $count  = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table} {$where}", ...$params) );
        $orders = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge($params, array($per_page, $offset))) );
    } else {
        /* $where（is_trashedフィルター）を必ず適用する */
        $count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
        $orders = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset) );
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

        <!-- ゴミ箱タブ -->
        <div style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid #ddd;">
            <a href="<?php echo esc_url($base_url); ?>"
               style="padding:8px 18px;font-size:13px;font-weight:600;text-decoration:none;border-radius:6px 6px 0 0;border:1px solid <?php echo !$view_trash?'#2271b1':'#ddd'; ?>;border-bottom:<?php echo !$view_trash?'2px solid #fff':'1px solid #ddd'; ?>;color:<?php echo !$view_trash?'#2271b1':'#555'; ?>;background:<?php echo !$view_trash?'#fff':'#f9f9f9'; ?>;margin-bottom:-2px;">
                発注一覧
            </a>
            <a href="<?php echo esc_url($base_url.'&lo_view=trash'); ?>"
               style="padding:8px 18px;font-size:13px;font-weight:600;text-decoration:none;border-radius:6px 6px 0 0;border:1px solid <?php echo $view_trash?'#dc3545':'#ddd'; ?>;border-bottom:<?php echo $view_trash?'2px solid #fff':'1px solid #ddd'; ?>;color:<?php echo $view_trash?'#dc3545':'#555'; ?>;background:<?php echo $view_trash?'#fff':'#f9f9f9'; ?>;margin-bottom:-2px;display:flex;align-items:center;gap:6px;margin-left:4px;">
                <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                ゴミ箱
                <?php if ($trash_count > 0): ?>
                <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;"><?php echo $trash_count; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div style="display:flex;gap:10px;align-items:center;margin:16px 0;flex-wrap:wrap;background:#fff;border:1px solid #ddd;border-radius:0 8px 8px 8px;padding:12px 16px;">
            <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;">
                <input type="hidden" name="post_type" value="lo_product"/>
                <input type="hidden" name="page" value="lo-orders"/>
                <label style="font-size:13px;font-weight:600;">ステータス：</label>
                <select name="lo_status" class="lo-filter-select">
                    <option value="" <?php selected($filter_status,""); ?>>すべて</option>
                    <?php foreach ($order_statuses as $k=>$v): ?>
                    <option value="<?php echo $k; ?>" <?php selected((string)$filter_status,(string)$k); ?>><?php echo $v['label']; ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="font-size:13px;font-weight:600;margin-left:8px;">商品：</label>
                <select name="lo_pid" class="lo-filter-select">
                    <option value="" <?php selected((string)$filter_pid,""); ?>>すべて</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?php echo $p->ID; ?>" <?php selected((string)$filter_pid,(string)$p->ID); ?>><?php echo esc_html($p->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">絞り込み</button>
            </form>
        </div>

        <?php if (empty($orders)): ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:40px;text-align:center;color:#888;">
            <p>発注データがありません。</p>
        </div>
        <?php else: ?>
        <!-- 一括操作バー（チェック時に表示） -->
        <div id="lo-bulk-bar" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 16px;margin-bottom:12px;align-items:center;gap:10px;flex-wrap:wrap;">
            <span id="lo-bulk-count" style="font-size:13px;font-weight:600;color:#856404;">0件 選択中</span>
            <?php if (!$view_trash): ?>
            <button type="button" id="lo-bulk-trash-btn" class="button" style="background:#555;color:#fff;border-color:#555;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> ゴミ箱へ移動
            </button>
            <?php else: ?>
            <button type="button" id="lo-bulk-restore-btn" class="button" style="background:#2271b1;color:#fff;border-color:#2271b1;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                <span class="dashicons dashicons-undo" style="font-size:14px;width:14px;height:14px;"></span> 元に戻す
            </button>
            <button type="button" id="lo-bulk-delete-btn" class="button" style="background:#dc3545;color:#fff;border-color:#dc3545;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> 完全に削除
            </button>
            <?php endif; ?>
            <button type="button" id="lo-bulk-cancel" class="button" style="font-size:12px;">キャンセル</button>
        </div>
        <?php if ($view_trash && $trash_count > 0): ?>
        <div style="margin-bottom:10px;text-align:right;">
            <button type="button" id="lo-empty-trash-btn" class="button" style="color:#dc3545;border-color:#dc3545;display:inline-flex;align-items:center;gap:4px;font-size:12px;">
                <span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;"></span> ゴミ箱を空にする
            </button>
        </div>
        <?php endif; ?>

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
            <table style="width:100%;border-collapse:collapse;border:none;">
                <thead>
                    <tr style="background:#f9f9f9;">
                        <th style="width:36px;padding:12px;text-align:center;">
                            <input type="checkbox" id="lo-check-all" title="すべて選択" style="width:16px;height:16px;cursor:pointer;"/>
                        </th>
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
                    <td style="padding:12px;text-align:center;">
                        <input type="checkbox" class="lo-row-check" value="<?php echo $o->id; ?>" <?php echo $view_trash ? 'checked="checked"' : ''; ?> style="width:16px;height:16px;cursor:pointer;accent-color:#dc3545;"/>
                    </td>
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
                    <td style="padding:12px;font-size:12px;color:#555;"><?php
                        $raw_dt = !empty($o->sent_at) ? $o->sent_at : $o->created_at;
                        try {
                            $dt = new DateTime($raw_dt, new DateTimeZone('UTC'));
                            $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
                            echo esc_html($dt->format('Y-m-d H:i:s'));
                        } catch(Exception $e) {
                            echo esc_html($raw_dt);
                        }
                    ?></td>
                    <td style="padding:12px;">
                        <div style="display:flex;flex-wrap:wrap;gap:4px;">
                        <?php if (!$view_trash): ?>
                            <button type="button" class="button button-small lo-detail-btn" data-id="<?php echo $o->id; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;"><span class="dashicons dashicons-visibility" style="font-size:13px;width:13px;height:13px;"></span>詳細</button>
                            <button type="button" class="button button-small lo-resend-qr-btn" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;color:#06C755;border-color:#06C755;"><span class="dashicons dashicons-camera" style="font-size:13px;width:13px;height:13px;"></span>QR再送信</button>
                            <button type="button" class="button button-small lo-trash-single-btn" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;color:#dc3545;border-color:#dc3545;"><span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;"></span>ゴミ箱へ</button>
                        <?php else: ?>
                            <button type="button" class="button button-small lo-restore-single-btn" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;color:#2271b1;border-color:#2271b1;"><span class="dashicons dashicons-undo" style="font-size:13px;width:13px;height:13px;"></span>元に戻す</button>
                            <button type="button" class="button button-small lo-delete-btn" data-id="<?php echo $o->id; ?>" data-nonce="<?php echo $admin_nonce; ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;color:#dc3545;border-color:#dc3545;"><span class="dashicons dashicons-trash" style="font-size:13px;width:13px;height:13px;"></span>完全削除</button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if (!$view_trash): ?>
                <tr id="lo-detail-<?php echo $o->id; ?>" style="display:none;background:#f9fafb;">
                    <td colspan="8" style="padding:16px 20px;">
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
                <?php endif; ?>
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
        /* --- DOM構築後に実行保証 --- */
        $(function(){
            var ajaxUrl    = '<?php echo admin_url('admin-ajax.php'); ?>';
            var adminNonce = '<?php echo wp_create_nonce('lo_admin'); ?>';
            var isTrash    = <?php echo $view_trash ? 'true' : 'false'; ?>;

            /* ============================================================
               受注ステータス変更
            ============================================================ */
            $(document).on('change', '.lo-status-sel', function(){
                var $s=$(this), oid=$s.data('id'), nonce=$s.data('nonce'), st=$s.val();
                var colors={new:'#2271b1',processing:'#f0b429',done:'#00B900',cancelled:'#dc3545'};
                $.post(ajaxUrl,{action:'lo_update_order_status',order_id:oid,status:st,nonce:nonce})
                 .done(function(r){ if(r.success) $s.css({borderColor:colors[st]||'#ccc',color:colors[st]||'#333'}); });
            });

            /* 詳細ボタン */
            $(document).on('click','.lo-detail-btn',function(){
                var id=$(this).data('id');
                $('#lo-detail-'+id).toggle();
                $(this).find('.dashicons').toggleClass('dashicons-visibility dashicons-hidden');
            });

            /* QR再送信ボタン → QRコードモーダルを表示 */
            $(document).on('click','.lo-resend-qr-btn',function(e){
                e.preventDefault();
                e.stopPropagation();
                var oid = $(this).data('id');
                var liffId = '<?php echo esc_js( get_option('lo_liff_id','') ); ?>';
                console.log('[LINE発注] QR再送信クリック orderId=' + oid + ' liffId=' + liffId);
                if (!liffId) {
                    /* モーダルで明確に通知 */
                    $('<div id="lo-liffid-warn" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:99999;display:flex;align-items:center;justify-content:center;">'
                      + '<div style="background:#fff;border-radius:12px;padding:28px 24px;max-width:380px;width:90%;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.3);">'
                      + '<div style="font-size:36px;margin-bottom:12px;">⚠️</div>'
                      + '<p style="font-size:15px;font-weight:bold;color:#dc3545;margin:0 0 10px;">LIFF IDが設定されていません</p>'
                      + '<p style="font-size:13px;color:#555;margin:0 0 18px;">LINE発注 → LINE設定 から LIFF App ID を登録してください。</p>'
                      + '<a href="<?php echo esc_js( admin_url('edit.php?post_type=lo_product&page=lo-settings') ); ?>" class="button button-primary">LINE設定を開く</a>'
                      + ' <button type="button" class="button" onclick="jQuery(\'#lo-liffid-warn\').remove()">閉じる</button>'
                      + '</div></div>').appendTo('body');
                    return;
                }
                var liffUrl = 'https://liff.line.me/' + liffId + '?order_id=' + oid;
                loShowAdminResendQR(liffUrl, oid);
            });

            /* QR再送信モーダル表示 */
            function loShowAdminResendQR(liffUrl, orderId) {
                $('#lo-resend-qr-modal').remove();
                var html =
                    '<div id="lo-resend-qr-modal" style="'
                    +   'position:fixed;top:0;left:0;width:100%;height:100%;'
                    +   'background:rgba(0,0,0,.6);z-index:99999;'
                    +   'display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">'
                    +   '<div style="background:#fff;border-radius:12px;padding:28px 24px;max-width:360px;width:100%;'
                    +       'text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.3);position:relative;">'
                    +     '<button id="lo-resend-qr-close" style="position:absolute;top:10px;right:14px;background:none;'
                    +         'border:none;font-size:22px;cursor:pointer;color:#999;">✕</button>'
                    +     '<p style="font-size:16px;font-weight:bold;color:#222;margin:0 0 6px;">QRコードで再送信</p>'
                    +     '<p style="font-size:12px;color:#888;margin:0 0 16px;">スマホのLINEでこのQRを読み取ると発注が再送信されます</p>'
                    +     '<div id="lo-resend-qr-canvas" style="width:200px;height:200px;margin:0 auto 16px;'
                    +         'border:1px solid #eee;border-radius:8px;display:flex;align-items:center;'
                    +         'justify-content:center;background:#fff;">'
                    +         '<span style="font-size:11px;color:#aaa;">生成中...</span>'
                    +     '</div>'
                    +     '<p style="font-size:11px;color:#888;margin:0 0 10px;">発注ID: ' + orderId + '</p>'
                    +     '<div style="background:#f5f5f5;border-radius:6px;padding:8px 10px;">'
                    +       '<div style="display:flex;align-items:center;gap:6px;">'
                    +         '<code style="font-size:10px;color:#555;flex:1;overflow:hidden;text-overflow:ellipsis;'
                    +             'white-space:nowrap;text-align:left;">' + liffUrl + '</code>'
                    +         '<button id="lo-resend-url-copy" style="flex-shrink:0;background:#06C755;color:#fff;'
                    +             'border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;">コピー</button>'
                    +       '</div>'
                    +     '</div>'
                    +   '</div>'
                    + '</div>';
                $('body').append(html);
                /* QR生成 */
                (function(){
                    var c = document.getElementById('lo-resend-qr-canvas');
                    if (!c) return;
                    function genQR(){
                        c.innerHTML = '';
                        new QRCode(c, { text:liffUrl, width:176, height:176,
                            colorDark:'#000000', colorLight:'#ffffff',
                            correctLevel: QRCode.CorrectLevel.M });
                    }
                    if (typeof QRCode !== 'undefined') { genQR(); }
                    else {
                        var s = document.createElement('script');
                        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
                        s.onload = genQR;
                        document.head.appendChild(s);
                    }
                })();
                /* URLコピー */
                $('#lo-resend-url-copy').on('click', function(){
                    var $b=$(this), ta=document.createElement('textarea');
                    ta.value=liffUrl; ta.style.cssText='position:fixed;opacity:0;';
                    document.body.appendChild(ta); ta.select();
                    try{ document.execCommand('copy'); }catch(e){}
                    document.body.removeChild(ta);
                    $b.text('✓ コピー済').css('background','#555');
                    setTimeout(function(){ $b.text('コピー').css('background','#06C755'); },2000);
                });
                /* 閉じる */
                $('#lo-resend-qr-close, #lo-resend-qr-modal').on('click', function(e){
                    if(e.target===this) $('#lo-resend-qr-modal').fadeOut(200,function(){$(this).remove();});
                });
                $('#lo-resend-qr-modal > div').on('click',function(e){e.stopPropagation();});
            }

            /* 単体ゴミ箱へ移動（通常画面の「ゴミ箱へ」ボタン） */
            $(document).on('click','.lo-trash-single-btn',function(){
                if(!confirm('この発注をゴミ箱に移動しますか？'))return;
                var $b=$(this), oid=$b.data('id');
                $b.prop('disabled',true);
                $.post(ajaxUrl,{action:'lo_bulk_trash',nonce:adminNonce,ids:[oid]})
                 .done(function(r){
                     if(r.success){ $('#lo-row-'+oid+',#lo-detail-'+oid).fadeOut(300,function(){$(this).remove();}); }
                     else{ alert('失敗: '+(r.data||'')); $b.prop('disabled',false); }
                 })
                 .fail(function(){ alert('通信エラー'); $b.prop('disabled',false); });
            });

            /* 単体元に戻す（ゴミ箱画面の「元に戻す」ボタン） */
            $(document).on('click','.lo-restore-single-btn',function(){
                var $b=$(this), oid=$b.data('id');
                $b.prop('disabled',true);
                $.post(ajaxUrl,{action:'lo_bulk_restore',nonce:adminNonce,ids:[oid]})
                 .done(function(r){
                     if(r.success){ $('#lo-row-'+oid).fadeOut(300,function(){$(this).remove();}); }
                     else{ alert('失敗: '+(r.data||'')); $b.prop('disabled',false); }
                 })
                 .fail(function(){ alert('通信エラー'); $b.prop('disabled',false); });
            });

            /* 単体完全削除（ゴミ箱画面の「完全削除」ボタン） */
            $(document).on('click','.lo-delete-btn',function(){
                if(!confirm('この発注を完全に削除します。\n取り消せません。よろしいですか？'))return;
                var $b=$(this), oid=$b.data('id');
                $b.prop('disabled',true);
                $.post(ajaxUrl,{action:'lo_delete_order',order_id:oid,nonce:$b.data('nonce')})
                 .done(function(r){
                     if(r.success){ $('#lo-row-'+oid+',#lo-detail-'+oid).fadeOut(300,function(){$(this).remove();}); }
                     else{ alert('失敗: '+(r.data||'')); $b.prop('disabled',false); }
                 })
                 .fail(function(){ alert('通信エラー'); $b.prop('disabled',false); });
            });

            /* ============================================================
               一括操作
            ============================================================ */

            /* バー更新 */
            function loUpdateBulkBar() {
                var n     = $('.lo-row-check:checked').length;
                var total = $('.lo-row-check').length;
                $('#lo-bulk-count').text(n + '件 選択中');
                $('#lo-bulk-bar').css('display', n > 0 ? 'flex' : 'none');
                if      (n === 0)     $('#lo-check-all').prop({checked:false, indeterminate:false});
                else if (n === total) $('#lo-check-all').prop({checked:true,  indeterminate:false});
                else                  $('#lo-check-all').prop({checked:false, indeterminate:true});
            }

            /* 行ハイライト */
            function loHighlight(cb) {
                var chk = $(cb).prop('checked');
                $(cb).closest('tr').css({
                    background: chk ? '#fff5f5' : '',
                    outline:    chk ? '2px solid #dc3545' : ''
                });
            }

            /* ページ読込時：ゴミ箱タブはデフォルト全チェック＆ハイライト */
            if (isTrash) {
                $('.lo-row-check').each(function(){ loHighlight(this); });
                loUpdateBulkBar();
                $('#lo-check-all').prop('checked', true);
            }

            /* 全選択ヘッダーチェック */
            $('#lo-check-all').on('change', function(){
                var chk = $(this).prop('checked');
                $('.lo-row-check').prop('checked', chk).each(function(){ loHighlight(this); });
                loUpdateBulkBar();
            });

            /* 行チェック */
            $(document).on('change', '.lo-row-check', function(){
                loHighlight(this);
                loUpdateBulkBar();
            });

            /* キャンセル */
            $('#lo-bulk-cancel').on('click', function(){
                $('.lo-row-check').prop('checked', false).each(function(){ loHighlight(this); });
                $('#lo-check-all').prop({checked:false, indeterminate:false});
                $('#lo-bulk-bar').hide();
            });

            /* 一括ゴミ箱へ移動（通常画面） */
            $('#lo-bulk-trash-btn').on('click', function(){
                var ids = $('.lo-row-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) return;
                if (!confirm(ids.length + '件をゴミ箱に移動します。')) return;
                var $btn = $(this).prop('disabled', true).text('移動中...');
                $.post(ajaxUrl, {action:'lo_bulk_trash', nonce:adminNonce, ids:ids})
                .done(function(r){
                    if (r.success) {
                        $.each(ids, function(_, id){ $('#lo-row-'+id+',#lo-detail-'+id).fadeOut(250,function(){$(this).remove();}); });
                        $('#lo-bulk-bar').hide();
                        $('#lo-check-all').prop({checked:false, indeterminate:false});
                    } else { alert('失敗: ' + (r.data||'')); }
                })
                .fail(function(){ alert('通信エラーが発生しました。'); })
                .always(function(){ $btn.prop('disabled',false).html('<span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> ゴミ箱へ移動'); });
            });

            /* 一括元に戻す（ゴミ箱画面） */
            $('#lo-bulk-restore-btn').on('click', function(){
                var ids = $('.lo-row-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) return;
                var $btn = $(this).prop('disabled', true).text('処理中...');
                $.post(ajaxUrl, {action:'lo_bulk_restore', nonce:adminNonce, ids:ids})
                .done(function(r){
                    if (r.success) {
                        $.each(ids, function(_, id){ $('#lo-row-'+id).fadeOut(250,function(){$(this).remove();}); });
                        $('#lo-bulk-bar').hide();
                        $('#lo-check-all').prop({checked:false, indeterminate:false});
                    } else { alert('失敗: ' + (r.data||'')); }
                })
                .fail(function(){ alert('通信エラーが発生しました。'); })
                .always(function(){ $btn.prop('disabled',false).html('<span class="dashicons dashicons-undo" style="font-size:14px;width:14px;height:14px;"></span> 元に戻す'); });
            });

            /* 一括完全削除（ゴミ箱画面） */
            $('#lo-bulk-delete-btn').on('click', function(){
                var ids = $('.lo-row-check:checked').map(function(){ return $(this).val(); }).get();
                if (!ids.length) return;
                if (!confirm(ids.length + '件を完全に削除します。\nこの操作は取り消せません。')) return;
                var $btn = $(this).prop('disabled', true).text('削除中...');
                $.post(ajaxUrl, {action:'lo_bulk_delete', nonce:adminNonce, ids:ids})
                .done(function(r){
                    if (r.success) {
                        $.each(ids, function(_, id){ $('#lo-row-'+id).fadeOut(250,function(){$(this).remove();}); });
                        $('#lo-bulk-bar').hide();
                        $('#lo-check-all').prop({checked:false, indeterminate:false});
                    } else { alert('削除失敗: ' + (r.data||'')); }
                })
                .fail(function(){ alert('通信エラーが発生しました。'); })
                .always(function(){ $btn.prop('disabled',false).html('<span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span> 完全に削除'); });
            });

            /* ゴミ箱を空にする */
            $('#lo-empty-trash-btn').on('click', function(){
                if (!confirm('ゴミ箱内のすべての発注を完全に削除します。\nこの操作は取り消せません。')) return;
                var $btn = $(this).prop('disabled', true).text('削除中...');
                $.post(ajaxUrl, {action:'lo_empty_trash', nonce:adminNonce})
                .done(function(r){
                    if (r.success) { location.reload(); }
                    else { alert('失敗: ' + (r.data||'')); }
                })
                .fail(function(){ alert('通信エラーが発生しました。'); })
                .always(function(){ $btn.prop('disabled', false); });
            });

        }); /* end $(function) */
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
add_action('wp_footer',          'lo_front_js', 99);

function lo_front_scripts() {
    wp_enqueue_script('jquery');
}

/* loFront 変数を直接 <script> で出力（wp_localize_script 非依存） */
function lo_front_vars() { ?>
<script>
var loFront = {
    ajax_url:       <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>,
    nonce:          <?php echo wp_json_encode( wp_create_nonce('lo_front') ); ?>,
    liff_id:        <?php echo wp_json_encode( (string) get_option('lo_liff_id','') ); ?>,
    line_talk_url:  <?php echo wp_json_encode( (string) get_option('lo_line_talk_url','https://line.me/R/ti/p/@227juwnw') ); ?>
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
    /* 大文字小文字を区別しない（デバイスにより "line/" と出る場合もある） */
    return navigator.userAgent.toLowerCase().indexOf('line/') !== -1;
}

/* メッセージ表示（.lo-rmsg に表示） */
function loShowMsg($el, cls, msg) {
    $el.removeClass('ok ng').addClass(cls).html(msg);
    if (cls !== 'ng-persist') {
        setTimeout(function(){ $el.html('').removeClass('ok ng ng-persist'); }, 9000);
    }
}

/* ================================================================
   PCブラウザ用：注文内容プレビュー + QR（order_id埋め込み）モーダル
   QRコードのURL = https://liff.line.me/{LIFF_ID}?order_id=XXX
   スマホのLINEで読み取ると自動送信される
================================================================ */
function loShowLiffGuide(orderData) {
    orderData = orderData || {};
    var liffId   = (typeof loFront !== 'undefined') ? loFront.liff_id : '';
    var orderId  = orderData.order_id || '';
    /* order_id をQRコードのURLに埋め込む */
    var liffUrl  = 'https://liff.line.me/' + liffId + (orderId ? '?order_id=' + orderId : '');

    jQuery('#lo-liff-modal').remove();

    var html =
        '<div id="lo-liff-modal" style="'
        +   'position:fixed;top:0;left:0;width:100%;height:100%;'
        +   'background:rgba(0,0,0,.6);z-index:99999;'
        +   'display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">'
        +   '<div style="'
        +       'background:#fff;border-radius:16px;padding:28px 24px;max-width:340px;width:100%;'
        +       'text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.3);position:relative;">'
        +     '<button id="lo-liff-modal-close" style="'
        +         'position:absolute;top:12px;right:14px;background:none;border:none;'
        +         'font-size:22px;cursor:pointer;color:#999;line-height:1;z-index:1;">✕</button>'

        +     '<p style="font-size:17px;font-weight:bold;color:#222;margin:0 0 18px;">'
        +       'QRコードをLINEで読み取る'
        +     '</p>'

        +     '<div id="lo-qr-canvas" style="width:200px;height:200px;margin:0 auto 18px;'
        +         'border:1px solid #eee;border-radius:8px;overflow:hidden;background:#fff;'
        +         'display:flex;align-items:center;justify-content:center;">'
        +         '<span style="font-size:11px;color:#aaa;">生成中...</span>'
        +     '</div>'

        +     '<p style="font-size:14px;color:#444;margin:0 0 18px;line-height:1.7;">'
        +       'LINEからQRコードを読み取り<br>注文を行う'
        +     '</p>'

        +     '<div style="background:#f5f5f5;border-radius:8px;padding:8px 10px;">'
        +       '<p style="font-size:10px;color:#999;margin:0 0 4px;text-align:left;">LIFF URL（LINEのトークに貼り付けて開く）</p>'
        +       '<div style="display:flex;align-items:center;gap:6px;">'
        +         '<code id="lo-liff-url-disp" style="font-size:10px;color:#555;flex:1;overflow:hidden;'
        +             'text-overflow:ellipsis;white-space:nowrap;text-align:left;">' + liffUrl + '</code>'
        +         '<button id="lo-liff-url-copy" style="'
        +             'flex-shrink:0;background:#06C755;color:#fff;border:none;border-radius:6px;'
        +             'padding:5px 10px;font-size:11px;cursor:pointer;white-space:nowrap;font-weight:bold;">コピー</button>'
        +       '</div>'
        +     '</div>'
        +   '</div>'
        + '</div>';

    jQuery('body').append(html);

    /* QRコード生成 */
    (function(){
        var container = document.getElementById('lo-qr-canvas');
        if (!container) return;
        function generateQR() {
            container.innerHTML = '';
            new QRCode(container, {
                text:         liffUrl,
                width:        176,
                height:       176,
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

    /* URLコピーボタン */
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

    /* 閉じる */
    jQuery('#lo-liff-modal-close, #lo-liff-modal').on('click', function(e){
        if (e.target === this) jQuery('#lo-liff-modal').fadeOut(200, function(){ jQuery(this).remove(); });
    });
    jQuery('#lo-liff-modal > div').on('click', function(e){ e.stopPropagation(); });
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

    /* ── LINEアプリ内：AJAX で保存 + Messaging API 送信 → LINEブラウザを閉じる ── */
    if (loIsLineApp()) {

        /* ユーザーID取得 → 送信を実行する内部関数 */
        function loDoLineSubmit(uid) {
            jQuery.post(loFront.ajax_url, {
                action:            'lo_submit',
                nonce:             loFront.nonce,
                post_id:           pid,
                lo_selected_model: checked.value,
                line_app:          '1',
                line_uid:          uid
            })
            .done(function(res) {
                if (!res.success) {
                    loShowMsg($r, 'ng', res.data || '送信に失敗しました。');
                    $b.prop('disabled', false).text(origText);
                    return;
                }
                if (res.data.line_sent) {
                    loShowMsg($r, 'ok', 'ご注文を受け付けました。LINEへ送信しました。');
                    setTimeout(function(){
                        try { liff.closeWindow(); } catch(e) {}
                        window.close();
                    }, 1500);
                } else {
                    loShowMsg($r, 'ng', 'LINE送信に失敗しました。' + (res.data.line_error || ''));
                    $b.prop('disabled', false).text(origText);
                }
            })
            .fail(function() {
                loShowMsg($r, 'ng', '通信エラーが発生しました。');
                $b.prop('disabled', false).text(origText);
            });
        }

        /* localStorage / cookie からユーザーIDを取得 */
        var lineUid = '';
        try { lineUid = localStorage.getItem('lo_line_uid') || ''; } catch(e) {}
        if (!lineUid) {
            var cm = document.cookie.match('(?:^|; )lo_line_uid=([^;]*)');
            if (cm) lineUid = decodeURIComponent(cm[1]);
        }
        if (lineUid) {
            loDoLineSubmit(lineUid);
        } else {
            /* なければこの場で liff.init → getProfile を試みる */
            var liffId = (typeof loFront !== 'undefined') ? loFront.liff_id : '';
            if (!liffId || typeof liff === 'undefined') {
                /* LIFF使用不可 → lo_line_to フォールバック（uid空で送信） */
                loDoLineSubmit('');
            } else {
                $b.text('LINE認証中...');
                liff.init({ liffId: liffId }).then(function() {
                    if (liff.isLoggedIn()) {
                        return liff.getProfile().then(function(profile) {
                            try { localStorage.setItem('lo_line_uid', profile.userId); } catch(e) {}
                            document.cookie = 'lo_line_uid=' + encodeURIComponent(profile.userId) + ';path=/;max-age=86400;SameSite=Lax';
                            loDoLineSubmit(profile.userId);
                        });
                    } else {
                        /* ログインしていない → lo_line_to フォールバック */
                        loDoLineSubmit('');
                    }
                }).catch(function() {
                    /* liff.init 失敗 → lo_line_to フォールバック */
                    loDoLineSubmit('');
                });
            }
        }
        return;
    }

    /* ── LINEアプリ外 → AJAX で発注データ取得 → 注文内容プレビュー付きモーダル表示 ── */
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
    loShowLiffGuide();
    callback(false);
}

/* ================================================================
   QRコード自動送信：ページ読み込み時に order_id パラメータを検出
   URL例: https://liff.line.me/{LIFF_ID}?order_id=123

   【重要】LIFF v2 の動作：
   QRを読み取った直後、LIFFは以下のようにURLを書き換える：
     endpoint.com/?liff.state=%2F%3Forder_id%3D123
   この時点では order_id は liff.state の中にあり、直接取れない。
   liff.init() を呼んだ後、LIFFが liff.state を展開して
     endpoint.com/?order_id=123
   に再リダイレクトする。
   → 正しい順序：loIsLineApp() チェック → liff.init() → order_id 取得
================================================================ */
jQuery(function(){

    /* LINEアプリ外では実行しない */
    if (!loIsLineApp()) return;

    var liffId = (typeof loFront !== 'undefined') ? loFront.liff_id : '';
    if (!liffId || typeof liff === 'undefined') return;

    /* ── LINEユーザーID保存ヘルパー（localStorage + cookie 二重保存） ── */
    function loSaveLineUid(uid) {
        try { localStorage.setItem('lo_line_uid', uid); } catch(e) {}
        document.cookie = 'lo_line_uid=' + encodeURIComponent(uid) + ';path=/;max-age=86400;SameSite=Lax';
    }
    function loGetLineUid() {
        var uid = '';
        try { uid = localStorage.getItem('lo_line_uid') || ''; } catch(e) {}
        if (!uid) {
            var m = document.cookie.match('(?:^|; )lo_line_uid=([^;]*)');
            if (m) uid = decodeURIComponent(m[1]);
        }
        return uid;
    }

    /* ── LINEアプリ内なら liff.init → getProfile → 保存 ──
       保存完了までページ内リンクを無効化し、遷移前に確実にユーザーIDを取得する */
    var _loUidReady = !!loGetLineUid();
    if (!_loUidReady) {
        jQuery('body').on('click.lo-wait', 'a', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
        });
        liff.init({ liffId: liffId }).then(function() {
            if (liff.isLoggedIn()) {
                return liff.getProfile().then(function(profile) {
                    loSaveLineUid(profile.userId);
                });
            } else {
                liff.login();
            }
        }).catch(function(e) {
            console.warn('liff.init (uid save) error:', e);
        }).then(function() {
            _loUidReady = true;
            jQuery('body').off('click.lo-wait', 'a');
        });
    }

    /* ── order_id の事前チェック ──
       直接URLにある場合（liff.init後の再リダイレクト後）はすぐ取れる。
       liff.state に入っている場合（初回アクセス時）もここで検出して
       無駄なページ描画を防ぐ。 */
    function loExtractOrderId() {
        var p = new URLSearchParams(window.location.search);
        var oid = p.get('order_id');
        if (oid) return oid;
        /* liff.state をデコードして order_id を探す */
        var st = p.get('liff.state');
        if (st) {
            try {
                /* liff.state の値は "/?order_id=123" のような形式 */
                var decoded = decodeURIComponent(st).replace(/^\/?\?/, '');
                var sp = new URLSearchParams(decoded);
                oid = sp.get('order_id');
                if (oid) return oid;
            } catch(e2) {}
        }
        return null;
    }

    var preCheckId = loExtractOrderId();
    if (!preCheckId) return; /* order_id がどこにもなければ通常表示のまま */

    /* 自動送信オーバーレイを表示（liff.init の前に見せる） */
    var overlay =
        '<div id="lo-auto-send-overlay" style="'
        +   'position:fixed;top:0;left:0;width:100%;height:100%;'
        +   'background:rgba(255,255,255,.95);z-index:99999;'
        +   'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;">'
        +   '<div style="font-size:40px;">📤</div>'
        +   '<p id="lo-auto-send-msg" style="font-size:16px;font-weight:bold;color:#333;margin:0;">発注内容を送信しています...</p>'
        +   '<div style="width:40px;height:40px;border:4px solid #eee;border-top:4px solid #06C755;'
        +       'border-radius:50%;animation:loSpin 1s linear infinite;"></div>'
        +   '<style>@keyframes loSpin{to{transform:rotate(360deg)}}</style>'
        + '</div>';
    jQuery('body').append(overlay);

    /* ================================================================
       sentBeacon ヘルパー
       ─ jQuery.post を「主」として await（DB更新完了を待ってから成功画面）
       ─ sendBeacon を「副」として同時発射（liff.closeWindow() 後でも届く）
       ─ jQuery.post が失敗しても Deferred は必ず resolve → loAutoSendSuccess が動く
    ================================================================ */
    function loSentBeacon(orderId, result) {
        var data = {
            action:   'lo_liff_sent',
            nonce:    loFront.nonce,
            order_id: String(orderId),
            result:   result
        };

        /* sendBeacon をバックアップとして即発射
           liff.closeWindow() 後もリクエストを届けられる */
        if (navigator && navigator.sendBeacon) {
            var fd = new FormData();
            for (var k in data) fd.append(k, data[k]);
            navigator.sendBeacon(loFront.ajax_url, fd);
        }

        /* jQuery.post を await して DB 更新完了を確認してから .then() へ進む。
           成功・失敗どちらでも必ず resolve（.always）して画面を止めない */
        var dfd = jQuery.Deferred();
        jQuery.post(loFront.ajax_url, data).always(function() {
            dfd.resolve();
        });
        return dfd.promise();
    }

    /* ================================================================
       liff.init() タイムアウト（15秒）
       LIFF認証がハングした場合に'failed'でDB更新してエラー表示する
    ================================================================ */
    var _liffInitDone = false;
    var _liffInitTimer = setTimeout(function() {
        if (_liffInitDone) return;
        _liffInitDone = true;
        console.error('liff.init timeout');
        loSentBeacon(preCheckId, 'failed');
        loAutoSendError('LIFF初期化がタイムアウトしました（15秒）。\nLINEアプリを再起動して、もう一度QRを読み取ってください。', preCheckId);
    }, 15000);

    /* liff.init() を先に呼ぶ
       → LIFFが liff.state を展開し、URLを ?order_id=123 に書き換える
       → その後 window.location.search から order_id を正確に取得できる */
    liff.init({ liffId: liffId })
    .then(function() {
        clearTimeout(_liffInitTimer);
        _liffInitDone = true;
        /* init 完了後、URLが書き換わっているので再取得 */
        var params2 = new URLSearchParams(window.location.search);
        var orderId = params2.get('order_id') || preCheckId;

        /* DB から発注データを取得 */
        return jQuery.post(loFront.ajax_url, {
            action:   'lo_get_order',
            nonce:    loFront.nonce,
            order_id: orderId
        }).then(function(res) {
            if (!res.success) throw new Error(res.data || '発注データが見つかりません。');
            var d = res.data;

            /* 既に送信済みの場合はスキップ */
            if (d.status === 'sent_liff' || d.status === 'sent') {
                jQuery('#lo-auto-send-overlay').html(
                    '<div style="font-size:40px;">✅</div>'
                    + '<p style="font-size:15px;font-weight:bold;color:#00B900;margin:0;">この発注は送信済みです。</p>'
                );
                setTimeout(function(){ liff.closeWindow(); }, 2500);
                return;
            }

            /* LINEメッセージを組み立て */
            var msgs = [];
            if (d.has_image && d.img_url) {
                msgs.push({ type:'image', originalContentUrl:d.img_url, previewImageUrl:d.img_url });
            }
            msgs.push({ type:'text', text:d.message });

            jQuery('#lo-auto-send-msg').text('LINEへ送信しています...');

            /* sendMessages を試みる（トーク画面から開いた場合に有効） */
            return liff.sendMessages(msgs)
            .then(function(){
                /* ★重要: sendBeaconでDB更新→完了後に成功画面（WebView終了前に確実に届ける） */
                return loSentBeacon(orderId, 'sent');
            })
            .then(function(){
                loAutoSendSuccess();
            })
            .catch(function(sendErr){
                /* sendMessages 失敗（トーク外から開いた場合）
                   → shareTargetPicker で送信先を選択させる */
                console.warn('sendMessages failed:', sendErr);
                if (liff.isApiAvailable('shareTargetPicker')) {
                    jQuery('#lo-auto-send-msg').text('送信先のトークを選んでください...');
                    liff.shareTargetPicker(msgs, { isMultiple: false })
                    .then(function(pickerRes) {
                        var sent = !!(pickerRes && pickerRes.status === 'success');
                        loSentBeacon(orderId, sent ? 'sent' : 'failed');
                        if (sent) {
                            loAutoSendSuccess();
                        } else {
                            loAutoSendError('送信がキャンセルされました。', orderId);
                        }
                    })
                    .catch(function(pickerErr) {
                        console.error('shareTargetPicker failed:', pickerErr);
                        loSentBeacon(orderId, 'failed');
                        loAutoSendError('送信に失敗しました。\n' + (pickerErr.message || pickerErr), orderId);
                    });
                } else {
                    /* shareTargetPicker も使えない場合 */
                    loSentBeacon(orderId, 'failed');
                    loAutoSendError('この画面からは送信できません。\nLINEのトーク画面から再度お試しください。\n(' + (sendErr.message || sendErr) + ')', orderId);
                }
            });
        });
    })
    .catch(function(e) {
        clearTimeout(_liffInitTimer);
        _liffInitDone = true;
        console.error('auto send error:', e);
        /* liff.init のリダイレクト処理は catch に入らないため
           ここに来るのは本当のエラー（認証失敗・ネットワーク等） */
        var params3 = new URLSearchParams(window.location.search);
        var orderId2 = params3.get('order_id') || preCheckId;
        if (orderId2) {
            loSentBeacon(orderId2, 'failed');
        }
        loAutoSendError((e.message || String(e)), orderId2);
    });

    /* 成功表示 */
    function loAutoSendSuccess() {
        jQuery('#lo-auto-send-overlay').html(
            '<div style="font-size:48px;">✅</div>'
            + '<p style="font-size:17px;font-weight:bold;color:#00B900;margin:0;">発注を送信しました！</p>'
            + '<p style="font-size:13px;color:#888;margin:4px 0 0;">このウィンドウは自動的に閉じます</p>'
        );
        setTimeout(function(){ try{ liff.closeWindow(); }catch(ex){} }, 2500);
    }

    /* エラー表示（エラー内容を画面に見せる） */
    function loAutoSendError(msg, orderId3) {
        jQuery('#lo-auto-send-overlay').html(
            '<div style="font-size:36px;">❌</div>'
            + '<p style="font-size:15px;font-weight:bold;color:#dc3545;margin:0;">送信に失敗しました</p>'
            + '<p style="font-size:12px;color:#666;background:#f5f5f5;border-radius:6px;padding:8px 12px;margin:8px 0 0;'
            +    'max-width:90%;white-space:pre-wrap;word-break:break-all;text-align:left;">'
            +    (msg || '不明なエラー')
            + '</p>'
            + (orderId3 ? '<p style="font-size:11px;color:#aaa;margin:4px 0 0;">発注ID: ' + orderId3 + '</p>' : '')
            + '<button onclick="document.getElementById(\'lo-auto-send-overlay\').style.display=\'none\';" '
            +     'style="margin-top:12px;padding:8px 24px;background:#555;color:#fff;border:none;border-radius:6px;font-size:13px;cursor:pointer;">閉じる</button>'
        );
    }
});
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


/* ============================================================
   カテゴリ商品取得
   get_objects_in_term() を使うことで term_relationships テーブルを
   WordPress 標準関数経由で確実に参照する。
   SQL 直接実行より信頼性が高い。
============================================================ */
function lo_get_products_by_term( $term_id ) {
    $term_id = (int) $term_id;
    if ( $term_id <= 0 ) return array();

    /* 対象 term_id + 子カテゴリを収集 */
    $term_ids = array( $term_id );
    $children = get_term_children( $term_id, 'lo_category' );
    if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
        foreach ( $children as $cid ) {
            $term_ids[] = (int) $cid;
        }
    }

    /* get_objects_in_term() で各タームに属するオブジェクトIDを取得 */
    $post_ids = array();
    foreach ( $term_ids as $tid ) {
        $ids = get_objects_in_term( $tid, 'lo_category' );
        if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
            foreach ( $ids as $id ) {
                $post_ids[] = (int) $id;
            }
        }
    }
    $post_ids = array_unique( $post_ids );
    if ( empty( $post_ids ) ) return array();

    /* post__in で絞り込み（post_type / post_status / 並び順を確実に指定） */
    $args = array(
        'post_type'        => 'lo_product',
        'post_status'      => 'publish',
        'post__in'         => $post_ids,
        'orderby'          => 'title',
        'order'            => 'ASC',
        'posts_per_page'   => -1,
        'suppress_filters' => false,
        'no_found_rows'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );
    $q = new WP_Query( $args );
    $posts = $q->posts;
    wp_reset_postdata();
    return $posts ? $posts : array();
}


/* ============================================================
   カテゴリアーカイブ：pre_get_posts でメインクエリを修正
   + template_redirect で自前出力（テーマ依存なし）
============================================================ */
add_action( 'pre_get_posts', 'lo_fix_category_main_query' );
function lo_fix_category_main_query( $query ) {
    if ( is_admin() )                      return;
    if ( ! $query->is_main_query() )       return;
    if ( ! $query->is_tax( 'lo_category' ) ) return;
    $query->set( 'post_type',      'lo_product' );
    $query->set( 'post_status',    'publish' );
    $query->set( 'posts_per_page', -1 );
    $query->set( 'orderby',        'title' );
    $query->set( 'order',          'ASC' );
}

add_action( 'template_redirect', 'lo_category_redirect', 1 );
function lo_category_redirect() {
    if ( ! is_tax( 'lo_category' ) ) return;
    $term = get_queried_object();
    if ( ! $term || ! isset( $term->term_id ) ) return;

    /* ページタイトルをテーマの title タグに反映 */
    add_filter( 'pre_get_document_title', function() use ( $term ) {
        return esc_html( $term->name ) . ' &#8211; ' . esc_html( get_bloginfo( 'name' ) );
    } );

    /* テーマのヘッダー（ナビ・CSS・管理バーなど）をそのまま使用 */
    get_header();

    /* コンテンツ本体 */
    echo lo_render_category_archive( $term );

    /* テーマのフッター（JS・ウィジェットなど）をそのまま使用 */
    get_footer();

    exit;
}

/* ============================================================
   カテゴリアーカイブ HTML 生成
   ショートコード・アーカイブURL 両方から呼ばれる共通関数
============================================================ */
function lo_render_category_archive( $term = null ) {
    if ( ! $term ) $term = get_queried_object();
    if ( ! $term || ! isset( $term->term_id ) ) return '';

    /* 直接SQL で全商品取得 */
    $posts = lo_get_products_by_term( (int) $term->term_id );

    ob_start(); ?>
    <div class="lo-cat-page">
        <style>
        .lo-cat-page{max-width:1100px;margin:0 auto;padding:24px 16px 48px;font-family:'Hiragino Kaku Gothic ProN','Meiryo','Yu Gothic',sans-serif;}
        .lo-cat-page *{box-sizing:border-box;}
        .lo-cat-title{font-size:24px;font-weight:700;color:#222;margin:0 0 6px;}
        .lo-cat-desc{font-size:14px;color:#666;margin:0 0 28px;}
        .lo-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px;margin-bottom:32px;}
        .lo-cat-card{background:#fff;border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);transition:box-shadow .2s,transform .2s;}
        .lo-cat-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.12);transform:translateY(-2px);}
        .lo-cat-card-img{background:#f5f5f5;display:flex;align-items:center;justify-content:center;height:180px;overflow:hidden;}
        .lo-cat-card-img img{max-width:100%;max-height:100%;object-fit:contain;display:block;}
        .lo-cat-card-img .lo-no-img{color:#ccc;font-size:13px;}
        .lo-cat-card-body{padding:14px 16px 16px;}
        .lo-cat-card-name{font-size:15px;font-weight:700;color:#222;margin:0 0 8px;line-height:1.4;}
        .lo-cat-card-info{font-size:12px;color:#666;line-height:1.6;margin:0 0 12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
        .lo-cat-card-btn{display:block;text-align:center;background:#00B900;color:#fff;border-radius:40px;padding:10px 16px;font-size:14px;font-weight:700;text-decoration:none;transition:opacity .2s;}
        .lo-cat-card-btn:hover{opacity:.85;color:#fff;text-decoration:none;}
        .lo-cat-empty{padding:48px 0;text-align:center;color:#999;font-size:15px;}
        .lo-cat-cats{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;}
        .lo-cat-cat-link{background:#f0f0f0;color:#555;border:1px solid #ddd;border-radius:20px;padding:4px 14px;font-size:13px;text-decoration:none;transition:background .2s;}
        .lo-cat-cat-link:hover,.lo-cat-cat-link.current{background:#00B900;color:#fff;border-color:#00B900;}
        @media(max-width:600px){.lo-cat-grid{grid-template-columns:1fr 1fr;gap:12px;}}
        @media(max-width:400px){.lo-cat-grid{grid-template-columns:1fr;}}
        </style>

        <h1 class="lo-cat-title"><?php echo esc_html( $term->name ); ?></h1>
        <?php if ( $term->description ): ?>
        <p class="lo-cat-desc"><?php echo esc_html( $term->description ); ?></p>
        <?php endif; ?>

        <!-- 全カテゴリナビ -->
        <?php
        $all_cats = get_terms( array( 'taxonomy' => 'lo_category', 'hide_empty' => false ) );
        if ( ! is_wp_error( $all_cats ) && ! empty( $all_cats ) ) : ?>
        <div class="lo-cat-cats">
            <?php foreach ( $all_cats as $cat ) :
                $is_current = ( (int)$cat->term_id === (int)$term->term_id );
            ?>
            <a href="<?php echo esc_url( get_term_link( $cat ) ); ?>"
               class="lo-cat-cat-link<?php echo $is_current ? ' current' : ''; ?>">
                <?php echo esc_html( $cat->name ); ?>
                <span style="font-size:11px;color:inherit;opacity:.7;">(<?php echo (int)$cat->count; ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ( empty( $posts ) ) : ?>
        <div class="lo-cat-empty">このカテゴリにはまだ商品がありません。</div>
        <?php else : ?>
        <div class="lo-cat-grid">
            <?php foreach ( $posts as $p ) :
                $img_id     = (int) get_post_meta( $p->ID, '_lo_img_id',   true );
                $img_url    = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
                $info_raw   = get_post_meta( $p->ID, '_lo_info',     true );
                $info_plain = $info_raw ? wp_strip_all_tags( $info_raw ) : '';
                $btn_bg     = get_post_meta( $p->ID, '_lo_btn_bg',   true ) ?: '#00B900';
                $btn_cl     = get_post_meta( $p->ID, '_lo_btn_col',  true ) ?: '#ffffff';
                $btn_tx     = get_post_meta( $p->ID, '_lo_btn_text', true ) ?: 'ご注文はこちら';
                $order_page = lo_find_shortcode_page( $p->ID );
            ?>
            <div class="lo-cat-card">
                <div class="lo-cat-card-img">
                    <?php if ( $img_url ) : ?>
                        <img src="<?php echo esc_url( $img_url ); ?>"
                             alt="<?php echo esc_attr( $p->post_title ); ?>" loading="lazy"/>
                    <?php else : ?>
                        <span class="lo-no-img">画像なし</span>
                    <?php endif; ?>
                </div>
                <div class="lo-cat-card-body">
                    <p class="lo-cat-card-name"><?php echo esc_html( $p->post_title ); ?></p>
                    <?php if ( $info_plain ) : ?>
                    <p class="lo-cat-card-info"><?php echo esc_html( mb_strimwidth( $info_plain, 0, 80, '…' ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( $order_page ) : ?>
                    <a href="<?php echo esc_url( $order_page ); ?>" class="lo-cat-card-btn"
                       style="background:<?php echo esc_attr( $btn_bg ); ?>;color:<?php echo esc_attr( $btn_cl ); ?>;">
                        <?php echo esc_html( $btn_tx ); ?>
                    </a>
                    <?php else : ?>
                    <span class="lo-cat-card-btn" style="background:#aaa;cursor:default;">発注ページ未設定</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
   ショートコードが埋め込まれた固定ページのURLを返す（キャッシュ付き）
============================================================ */
function lo_find_shortcode_page( $post_id ) {
    static $cache = array();
    if ( isset( $cache[ $post_id ] ) ) return $cache[ $post_id ];
    global $wpdb;
    $pattern = '%[line_order id="' . (int) $post_id . '"]%';
    $pid = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_content LIKE %s
           AND post_status = 'publish'
           AND post_type IN ('page','post')
         LIMIT 1",
        $pattern
    ) );
    $cache[ $post_id ] = $pid ? get_permalink( (int) $pid ) : '';
    return $cache[ $post_id ];
}

/* ============================================================
   ショートコード [line_order_list]
   [line_order_list]                   全カテゴリの商品一覧
   [line_order_list category="slug"]   指定カテゴリのみ
   [line_order_list category_id="3"]   term_id 指定
============================================================ */
add_shortcode( 'line_order_list', 'lo_list_shortcode' );
function lo_list_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'category'    => '',
        'category_id' => 0,
    ), $atts, 'line_order_list' );

    /* 特定カテゴリが指定されている場合 */
    $term = null;
    if ( (int) $atts['category_id'] ) {
        $term = get_term( (int) $atts['category_id'], 'lo_category' );
    } elseif ( $atts['category'] ) {
        $term = get_term_by( 'slug', $atts['category'], 'lo_category' );
    }

    if ( $term && ! is_wp_error( $term ) ) {
        return lo_render_category_archive( $term );
    }

    /* 全カテゴリを表示 */
    $cats = get_terms( array(
        'taxonomy'   => 'lo_category',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ) );

    if ( is_wp_error( $cats ) || empty( $cats ) ) {
        /* カテゴリなし → 全商品を直接SQLで取得 */
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'lo_product'
               AND post_status = 'publish'
             ORDER BY post_title ASC"
        );
        if ( empty( $ids ) ) return '<p>商品がまだ登録されていません。</p>';
        $posts = array();
        foreach ( $ids as $id ) {
            $p = get_post( (int) $id );
            if ( $p ) $posts[] = $p;
        }
        return lo_render_products_grid( '全商品', '', $posts );
    }

    /* 複数カテゴリをまとめて出力 */
    ob_start();
    foreach ( $cats as $cat ) {
        echo lo_render_category_archive( $cat );
    }
    return ob_get_clean();
}

/* ============================================================
   商品グリッド描画（タイトル・説明・投稿配列を直接受け取る版）
============================================================ */
function lo_render_products_grid( $title, $description, $posts ) {
    if ( empty( $posts ) ) return '<div style="padding:40px;text-align:center;color:#999;">このカテゴリには商品がありません。</div>';
    ob_start(); ?>
    <div class="lo-cat-page">
        <h2 style="font-size:22px;font-weight:700;color:#222;margin:0 0 6px;"><?php echo esc_html( $title ); ?></h2>
        <?php if ( $description ) : ?>
        <p style="font-size:14px;color:#666;margin:0 0 20px;"><?php echo esc_html( $description ); ?></p>
        <?php endif; ?>
        <div class="lo-cat-grid">
            <?php foreach ( $posts as $p ) :
                $img_id     = (int) get_post_meta( $p->ID, '_lo_img_id',   true );
                $img_url    = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
                $info_raw   = get_post_meta( $p->ID, '_lo_info',     true );
                $info_plain = $info_raw ? wp_strip_all_tags( $info_raw ) : '';
                $btn_bg     = get_post_meta( $p->ID, '_lo_btn_bg',   true ) ?: '#00B900';
                $btn_cl     = get_post_meta( $p->ID, '_lo_btn_col',  true ) ?: '#ffffff';
                $btn_tx     = get_post_meta( $p->ID, '_lo_btn_text', true ) ?: 'ご注文はこちら';
                $order_page = lo_find_shortcode_page( $p->ID );
            ?>
            <div class="lo-cat-card">
                <div class="lo-cat-card-img">
                    <?php if ( $img_url ) : ?>
                        <img src="<?php echo esc_url( $img_url ); ?>"
                             alt="<?php echo esc_attr( $p->post_title ); ?>" loading="lazy"/>
                    <?php else : ?>
                        <span class="lo-no-img">画像なし</span>
                    <?php endif; ?>
                </div>
                <div class="lo-cat-card-body">
                    <p class="lo-cat-card-name"><?php echo esc_html( $p->post_title ); ?></p>
                    <?php if ( $info_plain ) : ?>
                    <p class="lo-cat-card-info"><?php echo esc_html( mb_strimwidth( $info_plain, 0, 80, '…' ) ); ?></p>
                    <?php endif; ?>
                    <?php if ( $order_page ) : ?>
                    <a href="<?php echo esc_url( $order_page ); ?>" class="lo-cat-card-btn"
                       style="background:<?php echo esc_attr( $btn_bg ); ?>;color:<?php echo esc_attr( $btn_cl ); ?>;">
                        <?php echo esc_html( $btn_tx ); ?>
                    </a>
                    <?php else : ?>
                    <span class="lo-cat-card-btn" style="background:#aaa;cursor:default;">発注ページ未設定</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
