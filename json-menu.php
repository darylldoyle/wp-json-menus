<?php
/*
 * Plugin Name: WordPress JSON Menu
 * Version: 1.0.0
 * Plugin URI: http://enshrined.co.uk
 * Description: Adds your menus to the WP-JSON V2 API
 * Author: enshrined
 * Author URI: http://enshrined.co.uk
 * Requires at least: 3.9
 * Tested up to: 4.3
 *
 * Text Domain: wordpress-json-menu
 * Domain Path: /lang/
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Json_Menu')) {

    /**
     * Class Json_Menu
     */
    class Json_Menu
    {

        /**
         * The custom namespace for this plugin
         */
        const REST_NAMESPACE = 'menus/v1';

        /**
         * Base URL of this plugins extension
         *
         * @var string
         */
        protected $base_url;

        /**
         * Json_Menu constructor.
         */
        public function __construct()
        {
            $this->base_url = sprintf('%s/%s/%s', get_site_url(), rest_get_url_prefix(), self::REST_NAMESPACE);
            $this->register_routes();
        }

        /**
         * Register our menu routes
         */
        public function register_routes()
        {

            /**
             * Get all menu locations
             */
            register_rest_route(self::REST_NAMESPACE, '/locations', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_all_menu_locations'),
            ));

            /**
             * Get single menu by location
             */
            register_rest_route(self::REST_NAMESPACE, '/locations/(?P<location>[a-zA-Z0-9_-]+)', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_single_menu_location'),
            ));

            /**
             * Get all menus
             */
            register_rest_route(self::REST_NAMESPACE, '/menus', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_all_menus'),
            ));

            /**
             * Get single menu
             */
            register_rest_route(self::REST_NAMESPACE, '/menus/(?P<id>\d+)', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_single_menu'),
            ));
        }

        /**
         * Get all menus
         *
         * @return WP_REST_Response
         */
        public function get_all_menus()
        {
            $json_url = $this->base_url . '/menus';
            $wp_menus = wp_get_nav_menus();
            $i = 0;
            $json_menus = array();
            foreach ($wp_menus as $wp_menu) :
                $menu = (array)$wp_menu;
                $json_menus[$i] = $menu;
                $json_menus[$i]['ID'] = $menu['term_id'];
                $json_menus[$i]['name'] = $menu['name'];
                $json_menus[$i]['slug'] = $menu['slug'];
                $json_menus[$i]['description'] = $menu['description'];
                $json_menus[$i]['count'] = $menu['count'];
                $json_menus[$i]['_links']['collection'] = $json_url;
                $json_menus[$i]['_links']['self'] = sprintf('%s/%s', $json_url, $menu['term_id']);
                $i++;
            endforeach;

            return new WP_REST_Response($json_menus);
        }

        /**
         * Get a single menu by id
         *
         * @param WP_REST_Request $request
         * @return WP_Error|WP_REST_Response
         */
        public function get_single_menu(WP_REST_Request $request)
        {
            $id = $request->get_param('id');

            if (empty($id)) {
                return new WP_Error(404, __('No menu exists with that id', 'wordpress-json-menu'), false);
            }

            $json_url = $this->base_url . '/menus';
            $wp_menu_object = wp_get_nav_menu_object($id);
            $wp_menu_items = wp_get_nav_menu_items($id);

            $json_menu = array();
            if ($wp_menu_object) :
                $menu = (array)$wp_menu_object;
                $json_menu['ID'] = abs($menu['term_id']);
                $json_menu['name'] = $menu['name'];
                $json_menu['slug'] = $menu['slug'];
                $json_menu['description'] = $menu['description'];
                $json_menu['count'] = abs($menu['count']);

                $json_menu_items = array();
                foreach ($wp_menu_items as $item_object)
                    $json_menu_items[] = $this->format_menu_item($item_object);

                $json_menu['items'] = $json_menu_items;
                $json_menu['_links']['collection'] = $json_url;
                $json_menu['_links']['self'] = sprintf('%s/%s', $json_url, $id);
            endif;

            return new WP_REST_Response($json_menu);
        }

        /**
         * Return all registered menu locations
         *
         * @return WP_REST_Response
         */
        public function get_all_menu_locations()
        {
            $json_url = $this->base_url . '/locations/';
            $locations = get_nav_menu_locations();
            $registered_menus = get_registered_nav_menus();

            $json_menus = array();
            if ($locations && $registered_menus) :
                foreach ($registered_menus as $slug => $label) :
                    $json_menus[$slug]['ID'] = $locations[$slug];
                    $json_menus[$slug]['label'] = $label;
                    $json_menus[$slug]['_links']['collection'] = $json_url;
                    $json_menus[$slug]['_links']['self'] = $json_url . $slug;
                endforeach;
            endif;

            return new WP_REST_Response($json_menus);
        }

        /**
         * Get a menu by location
         *
         * @param WP_REST_Request $request
         * @return array|WP_REST_Response
         */
        public function get_single_menu_location(WP_REST_Request $request)
        {
            $location = $request->get_param('location');

            $json_url = $this->base_url . '/locations';
            $locations = get_nav_menu_locations();
            if (!isset($locations[$location]))
                return array();

            $wp_menu = wp_get_nav_menu_object($locations[$location]);
            $menu_items = wp_get_nav_menu_items($wp_menu->term_id);
            $sorted_menu_items = $top_level_menu_items = $menu_items_with_children = array();

            foreach ((array)$menu_items as $menu_item)
                $sorted_menu_items[$menu_item->menu_order] = $menu_item;

            foreach ($sorted_menu_items as $menu_item)
                if ($menu_item->menu_item_parent != 0)
                    $menu_items_with_children[$menu_item->menu_item_parent] = true;
                else
                    $top_level_menu_items[] = $menu_item;

            $menu = (array)$wp_menu;

            while ($sorted_menu_items) :
                $i = 0;
                foreach ($top_level_menu_items as $top_item) :
                    $menu['items'][$i] = $this->format_menu_item($top_item, false);
                    if (isset($menu_items_with_children[$top_item->ID]))
                        $menu['items'][$i]['children'] = $this->get_nav_menu_item_children($top_item->ID, $menu_items, false);
                    else
                        $menu['items'][$i]['children'] = array();
                    $i++;
                endforeach;
                break;
            endwhile;

            $menu['_links']['collection'] = $json_url;
            $menu['_links']['self'] = sprintf('%s/%s', $json_url, $location);

            return new WP_REST_Response($menu);
        }

        /**
         * Format a menu item for JSON API consumption
         *
         * @param   object|array $menu_item the menu item
         * @param   bool $children get menu item children (default false)
         * @param   array $menu the menu the item belongs to (used when $children is set to true)
         *
         * @return  array   a formatted menu item for JSON
         */
        public function format_menu_item($menu_item, $children = false, $menu = array())
        {
            $item = (array)$menu_item;

            $menu_item = array(
                'ID' => abs($item['ID']),
                'order' => (int)$item['menu_order'],
                'parent' => abs($item['menu_item_parent']),
                'title' => $item['title'],
                'url' => $item['url'],
                'attr' => $item['attr_title'],
                'target' => $item['target'],
                'classes' => implode(' ', $item['classes']),
                'description' => $item['description'],
                'object_id' => abs($item['object_id']),
                'object' => $item['object'],
                'type' => $item['type'],
                'type_label' => $item['type_label'],
            );
            if ($children === true && !empty($menu))
                $menu_item['children'] = $this->get_nav_menu_item_children($item['ID'], $menu);
            return apply_filters('json_menus_format_menu_item', $menu_item);
        }

        /**
         * Returns all child nav_menu_items under a specific parent
         *
         * @param   int $parent_id the parent nav_menu_item ID
         * @param   array $nav_menu_items navigation menu items
         * @param   bool $depth gives all children or direct children only
         *
         * @return  array   returns filtered array of nav_menu_items
         */
        public function get_nav_menu_item_children($parent_id, $nav_menu_items, $depth = true)
        {
            $nav_menu_item_list = array();
            foreach ((array)$nav_menu_items as $nav_menu_item) :
                if ($nav_menu_item->menu_item_parent == $parent_id) :
                    $nav_menu_item_list[] = $this->format_menu_item($nav_menu_item, true, $nav_menu_items);
                    if ($depth) {
                        if ($children = $this->get_nav_menu_item_children($nav_menu_item->ID, $nav_menu_items))
                            $nav_menu_item_list = array_merge($nav_menu_item_list, $children);
                    }
                endif;
            endforeach;
            return $nav_menu_item_list;
        }
    }
}

if (!function_exists('init_menus')) :
    /**
     * Init JSON REST API Menu routes
     */
    function init_menus()
    {
        $json_menu = new Json_Menu();
    }

    add_action('rest_api_init', 'init_menus');
endif;
