<?php

require_once( __DIR__. '/services/triggers-service/triggers-service.class.php' );
require_once( __DIR__. '/services/page-visits-service/page-visits-service.class.php' );
require_once( __DIR__. '/services/analytics-service/analytics-service.class.php' );
require_once( __DIR__. '/services/ajax-triggers-service/ajax-triggers-service.class.php' );
require_once(IFSO_PLUGIN_BASE_DIR . 'services/plugin-settings-service/plugin-settings-service.class.php');

use IfSo\PublicFace\Helpers\CookieConsent;
use IfSo\PublicFace\Services\TriggersService;
use IfSo\PublicFace\Services\PageVisitsService;
use IfSo\PublicFace\Services\AjaxTriggersService;
use IfSo\Services\PluginSettingsService;

/**
 * The public-facing functionality of the plugin.
 *
 * @link       http://if-so.com
 * @since      1.0.0
 * @package    IfSo
 * @subpackage IfSo/public
 * @author     Matan Green
 * @author     Nick Martianov
 */
class If_So_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/*
	 *	Create shortcode
	 */
    public function add_if_so_shortcode( $atts ) {
        $ret = null;
        $render_via_ajax_option_value = PluginSettingsService\PluginSettingsService::get_instance()->renderTriggersViaAjax->get();
        $load_later_param = isset($atts['ajax']) ? $atts['ajax'] : '';
        if(!is_admin() && ($render_via_ajax_option_value || $load_later_param === 'yes') && $load_later_param !== 'no')
            $ret =  AjaxTriggersService\AjaxTriggersService::get_instance()->handle($atts);
        else
            $ret =  apply_filters('ifso_shortcode_content',TriggersService\TriggersService::get_instance()->handle($atts),$atts);

        return $ret;
    }

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
        $loader_type = PluginSettingsService\PluginSettingsService::get_instance()->ajaxLoaderAnimationType->get();
        $loader_type = is_numeric($loader_type) ? (int) $loader_type : $loader_type;    //compat
        if(true || ($loader_type && ($loader_type>0 || (is_string($loader_type) && $loader_type!=='none')))){     //AJAX LOADER ANIMATION CSS - don't load if it's selected this way
            wp_register_style('if-so-public-dummy',false);
            wp_enqueue_style('if-so-public-dummy');
            wp_add_inline_style('if-so-public-dummy',$this->ajax_loader_css());
        }

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

        /**
         * This method is hooked into wordpress in the main if-so class(class-if-so.php) via if-so loader
         * Enqueues public js files as well as providing the required global JS variables
         */
        $old_inline_eneueue = !function_exists('wp_add_inline_script');
		$ajax_nonce = wp_create_nonce( "ifso-nonce" );
		$ajax_url = admin_url('admin-ajax.php');
        $isAnalyticsOn = (IfSo\PublicFace\Services\AnalyticsService\AnalyticsService::get_instance()->isOn) ? 'true' : 'false';
        $isPagesVisitedOn = (int) !PluginSettingsService\PluginSettingsService::get_instance()->removePageVisitsCookie->get();
        $isVisitCountEnabled =  (int) PluginSettingsService\PluginSettingsService::get_instance()->enableVisitCount->get();
        $attrs_for_ajax = json_encode(AjaxTriggersService\AjaxTriggersService::get_instance()->get_atts_for_ajax());
        $vars_script = <<<SCR
    var nonce = "{$ajax_nonce}";//compat
    var ifso_nonce = "{$ajax_nonce}";
    var ajaxurl = "{$ajax_url}";
    var ifso_page_url = window.location.href;
    var isAnalyticsOn = {$isAnalyticsOn};
    var isPageVisitedOn = {$isPagesVisitedOn};
    var isVisitCountEnabled = {$isVisitCountEnabled};
    var referrer_for_pageload = document.referrer;
    var ifso_attrs_for_ajax = {$attrs_for_ajax};
SCR;
        if($old_inline_eneueue) echo "<script>{$vars_script}</script>";
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/if-so-public.js', array( 'jquery' ), $this->version, false );
		if(!$old_inline_eneueue) wp_add_inline_script($this->plugin_name,$vars_script,'before');
	}

	public function wp_ajax_ifso_visit_handler(){
        check_ajax_referer( 'ifso-nonce', 'nonce' );

        $visit_count = (int) $_POST['ifso_count_visit'];
        $page_visited = (int) $_POST['isfo_save_page_visit'];

        if($visit_count){
            $this->update_visit_count(true);
        }
        if($page_visited){
            $page_url = $_POST['page_url'];
            PageVisitsService\PageVisitsService::get_instance()->save_page($page_url);
        }
        wp_die(); // indicate end of stream
    }

	public function start_sesh(){
        if(!wp_doing_cron()){
            if(PluginSettingsService\PluginSettingsService::get_instance()->disableSessions->get()) return false;
            if((!is_admin() || (is_admin() && defined('DOING_AJAX') && DOING_AJAX )) && !isset($_SESSION)){    //Prevent using session_start on admin pages to fix theme/plugin editor
                if(PluginSettingsService\PluginSettingsService::get_instance()->preventNocacheHeaders->get())
                    session_cache_limiter('');	//Prevent no-cache headers being sent when using session

                if(!PluginSettingsService\PluginSettingsService::get_instance()->disableSessions->get())
                    session_start(['read_and_close'=>true]);
            }
        }
    }

    public function set_ifso_group_cookie(){
        if(isset($_REQUEST['ifsoGroup']) && !empty($_REQUEST['ifsoGroup'])){
            $grp = $_REQUEST['ifsoGroup'];
            CookieConsent::get_instance()->set_cookie('ifsoGroup',$grp,time()+60*60*24*365*3,'/'); //Set a cookie to identify a member of a group(3 years)
            $_COOKIE['ifsoGroup'] = $grp;
        }
    }

    public function update_visit_count($ajax=false){
	    if(is_admin() && !$ajax) return false;
	    if(!PluginSettingsService\PluginSettingsService::get_instance()->enableVisitCount->get()) return false;
        $cookie_name = 'ifso_visit_counts';
        $expiration = apply_filters('ifso_visit_count_expiration',time() + (86400 * 30 * 12));  // 86400 = 1 day

        // TODO move to another service
        $is_new_user = !isset( $_COOKIE[$cookie_name] ) || $_COOKIE[$cookie_name] === '';

        $num_of_visits = ($ajax) ? 1 : 0;
        if ( !$is_new_user ) {
            if ( isset( $_COOKIE[$cookie_name] ) )
                $num_of_visits = $_COOKIE[$cookie_name]; // TODO move to another service

            $num_of_visits = $num_of_visits + 1;
        }

        CookieConsent::get_instance()->set_cookie($cookie_name, $num_of_visits, $expiration, "/");
    }

    public function ajax_loader_css($color = '#000'){
        $css = <<<CSS
        .lds-dual-ring {
          display: inline-block;
          width: 16px;
          height: 16px;
        }
        .lds-dual-ring:after {
          content: " ";
          display: block;
          width: 16px;
          height: 16px;
          margin: 0px;
          border-radius: 50%;
          border: 3px solid {$color};
          border-color: {$color} transparent {$color} transparent;
          animation: lds-dual-ring 1.2s linear infinite;
        }
        @keyframes lds-dual-ring {
          0% {
            transform: rotate(0deg);
          }
          100% {
            transform: rotate(360deg);
          }
        }
        /*loader 2*/
        .ifso-logo-loader {
            font-size: 20px;
            width: 64px;
            font-family: sans-serif;
            position: relative;
            height: auto;
            font-weight: 800;
        }
        .ifso-logo-loader:before {
            content: '';
            position: absolute;
            left: 30%;
            top: 36%;
            width: 14px;
            height: 22px;
            clip-path: polygon(100% 50%, 0 0, 0 100%);
            background: #fd5b56;
            animation: spinAndMoveArrow 2s infinite;
            height: 9px;
            width: 7px;
        }
        .ifso-logo-loader:after {
            content: "If So";
            word-spacing: 12px;
        }
        @keyframes spinAndMoveArrow {
                40% {
                    transform: rotate(360deg);
                }
    
                60% {
                    transform: translateX(-5px);
                }
    
                80% {
                    transform: translateX(5px);
                }
    
                100% {
                    transform: translateX(0);
                }
        }
        /*Loader 3 - default content*/
        .ifso-default-content-loader{
            display:inline-block;
        }
        
CSS;
        return $css;
    }

    public function exclude_triggers_from_sitemap($value, $post_type){
        if ( $post_type == 'ifso_triggers' )
            return true;
    }

    public function builders_shortcodes_ajax_compat(){
	    //Divi modules compat with ajax loading
        add_filter('et_builder_load_actions',function($actions){
            $actions[] = 'render_ifso_shortcodes';
            return $actions;
        });
    }

    public function render_ifso_shortcode_by_name($content,$atts){
        if(empty($content) && !empty($atts['name'])){
            $pid = null;
            $triggerName = $atts['name'];
            $args = array(
                'post_type'=> 'ifso_triggers',
                'name' => $triggerName,
                'posts_per_page'=> -1,
                'suppress_filters'=> 'true'
            );
            $ret = new WP_Query($args);
            if ( $ret->have_posts() ){
                while( $ret->have_posts() ){
                    $ret->the_post();
                    $pid= get_the_ID();
                    break; break;
                }
            }
            wp_reset_postdata();
            unset($atts['name']);
            return ifso($pid,$atts,true);
        }
        return $content;
    }

    public function render_shortcodes_in_titles_and_menus(){
        $shortcodes_in_title_checkbox = PluginSettingsService\PluginSettingsService::get_instance()->allowShortcodesInTitle->get();
        if($shortcodes_in_title_checkbox){
            add_filter( 'document_title_parts', function($atts){$atts['title'] = do_shortcode($atts['title']) ;return $atts; } );  //Apply to meta title
            add_filter( 'the_title', 'do_shortcode' );     //Apply to title.
            add_filter('wp_nav_menu_items', 'do_shortcode');    //apply to menu items
            add_filter("woocommerce_page_title",'do_shortcode');    //WC page/category titles
            add_filter('woocommerce_get_breadcrumb',function($crumbs){  //WC breadcrumbs
                if(!is_array($crumbs)) return $crumbs;
                foreach($crumbs as $crumbKey=>$crumb){
                    if(!is_array($crumb) || empty($crumb[0])) continue;
                    $crumbs[$crumbKey][0] = do_shortcode($crumb[0]);
                }
                return $crumbs;
            });
        }

        add_filter('wpseo_title','do_shortcode'); 	//Allow shorctodes in YOAST SEO titles
        add_filter('wpseo_metadesc','do_shortcode'); //Allow shortcodes in YOAST SEO meta description
        add_filter('aioseop_title','do_shortcode'); 	//Allow shorctodes in AIO SEO titles
        add_filter('rank_math/frontend/title','do_shortcode');  //Allow shorctodes in Rank Math titles
        add_filter('rank_math/frontend/description','do_shortcode');    //Allow shorctodes in Rank Math descriptions

    }

}
