<?php
/*
Plugin Name: Shortcode Logic
Description: Extends plugin logic with some shortcode logic
Author: Sebastian Gärtner
Author URI: https://github.com/ibes
Version: 0.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

/* thanks to plugin logic for lots of inspirations */

// Security check
if ( ! class_exists('WP') ) {
  header('Status: 403 Forbidden');
  header('HTTP/1.1 403 Forbidden');
  exit();
}

define( 'SCLO_DBTABLE',  $GLOBALS['wpdb']->base_prefix . 'shortcode_logic' );

// on register
register_activation_hook( __FILE__, 'shortcode_logic_on_activation' );
register_deactivation_hook( __FILE__, 'shortcode_logic_on_deactiviation' );
register_uninstall_hook( __FILE__, 'shortcode_logic_on_uninstall' );

function shortcode_logic_on_activation () {
  shortcode_logic_create_databse_table();
  // to do: regenerate shortcode rules file after reactivation
  // show message if neigher plugin organizer nor plugin logic are active
}

// admin page
add_action('admin_menu', 'shortcode_logic_setup_menu');

function shortcode_logic_setup_menu(){
        add_plugins_page( 'Shortcode Logic', 'Shortcode Logic', 'activate_plugins', 'shortcode_logic', 'shortcode_logic_page' );
}

// register setting
add_action( 'admin_init', 'shortcode_logic_register_setting' );

function shortcode_logic_register_setting() {
  // plugins to watch
  register_setting( 'shortcode_logic_options', 'shortcode_logic_watched_shortcodes' );
}


function shortcode_logic_page() {

    echo '<div class="wrap">';
    echo '<h2>Shortcode Logic</h2>';

    // if plugin logic and plugin organizer are not activated, show message
    if ( ! ( is_plugin_active('plugin-logic/plugin-logic.php') || is_plugin_active('plugin-organizer/plugin-organizer.php') ) ) {
      echo '<div class="error"><p>' . __('Weder Plugin Logic noch Plugin Organizer wurden erkannt. <br>Evtl. hat Shortcode Logic somit keinen weiteren Effekt. Bitte stell sicher, dass eines der beiden Plugins aktiviert ist.') . '</p></div>';
    }

    global $shortcode_tags;

    $output = '';

    $watched_shortcodes = get_option('shortcode_logic_watched_shortcodes');

    foreach ($shortcode_tags as $shortcode_tag => $callback) {

        $path = '';

        if ( gettype($callback) == 'string' AND strpos($callback, "::") == false) {
          $reflector = new ReflectionFunction($callback);

        } elseif ( gettype($callback) == 'string' ) {

          $callback = explode('::' ,$callback);
          $class = $callback[0];
          $reflector = new ReflectionClass($class);

        } elseif ( gettype($callback) == 'array' ) {

            $class = $callback[0];
            if ( gettype($callback[0]) != 'string') {
                $class = get_class($callback[0]);
            }

            $reflector = new ReflectionClass($class);

        }

        $path = $reflector->getFileName();
        $plugin_data = shortcode_logic_plugin_name_from_path( $path );
        if ( $plugin_data != false ) {
          $checked = isset( $watched_shortcodes[$shortcode_tag] ) ? 'checked' : '';
          $output .=  '<li><label><input type="checkbox" name="shortcode_logic_watched_shortcodes[' . $shortcode_tag . ']" value="' . $shortcode_tag . '" ' . $checked . '><strong>[' . $shortcode_tag . ']</strong>' . ' ' . $plugin_data['name'] . '</label></li>';
        }
    }

    if ( '' != $output ) {

      echo '<form method="post" action="options.php">';
      settings_fields( 'shortcode_logic_options' );
      do_settings_sections( 'shortcode_logic_options' );
      echo '<ul>' . $output . '</ul>';
      submit_button();
      echo '</form>';

    } else {
      echo __('No selectable shortcodes found');
    }

    echo '<hr>';
    echo '<form method="post" enctype="multipart/form-data">';
    submit_button('Regeldatei regenerieren', 'secondary', 'shortcode_logic_regenerate_all');
    echo '</form>';

    if (isset($_POST['shortcode_logic_regenerate_all'])) {
      $regenerate = shortcode_logic_regenerate_detected_shortcodes();
      $write_rules = shortcode_logic_write_rule_file();

      if ($regenerate) {
        echo '<div class="notice notice-success"><p>' . __('Shortcode logic Regel regeneriert') . '</p></div>';
      }
    }

}

function shortcode_logic_plugin_name_from_path( $path ) {

    // get plugin directory name
    $replace = '/.*\/plugins\/([a-zA-Z0-9-]*)\/.*/i';

    $plugin_directory_name = preg_replace($replace, '$1', $path);

    // preg_replace returns NULL on error or original string if no match
    // break if no plugin directory name found
    if ( !isset( $plugin_directory_name ) || ( $plugin_directory_name == $path ) ) {
      return false;
    }

    // get all registered plugins
    $plugins = get_plugins();

    // try to find plugin directory in registered plugins
    foreach ( $plugins as $p_path => $p_info ) {

        $p_dir = explode('/', $p_path);
        if ( $p_dir[0] == $plugin_directory_name ) {
             $plugin_info = $p_info;
             $plugin_path = $p_path;
        }
    }
    // return array of name and path
    $plugin_data = array( 'name' => $plugin_info['Name'], 'path' => $plugin_path );

    return $plugin_data;
}

function shortcode_logic_shortcode_to_plugin_path( $shortcode ) {

  // get all registered shortcodes
  global $shortcode_tags;

  if ( !isset($shortcode) || !isset($shortcode_tags[$shortcode]) ) {
    return false;
  }

  // get transient
  if ( false !== ( $plugin_path = get_transient( 'shortcode-logic-path-for-' . $shortcode  ) ) ) {
    return $plugin_path;
  }

  // get callback function of selected shortcode
  // callback could be function, string of Class::method, or array of ([0] => Class, [1] => method)
  $callback = $shortcode_tags[$shortcode];

  if ( gettype($callback) == 'string' AND strpos($callback, "::") == false) {
    $reflector = new ReflectionFunction($callback);

  } elseif ( gettype($callback) == 'string' ) {

    $callback = explode('::' ,$callback);
    $class = $callback[0];
    $reflector = new ReflectionClass($class);

  } elseif ( gettype($callback) == 'array' ) {

      $class = $callback[0];
      if ( gettype($callback[0]) != 'string') {
          $class = get_class($callback[0]);
      }

      $reflector = new ReflectionClass($class);

  }

  // get path of callback function (or plugin class)
  $path = '';
  $path = $reflector->getFileName();

  // get plugin name from path
  $plugin_data = shortcode_logic_plugin_name_from_path( $path );

  // set transient
  set_transient( 'shortcode-logic-path-for-' . $shortcode , $plugin_data['path']);

  return $plugin_data != false ? $plugin_data['path'] : false;

}


// check for shortcodes on save posts
add_action( 'save_post', 'shortcode_logic_shortcode_detector', 10, 3 );

function shortcode_logic_shortcode_detector( $post_id, $post, $update ) {

    if ( 'post' != $post->post_type || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision( $post_id ) ) {
        return;
    }

    $changed = shortcode_logic_dectect_shortcode($post->ID, $post->post_content);

    // if found shortcodes
    if ( $changed ) {
      shortcode_logic_write_rule_file();
    }

    //set_transient("shortcode_logic_admin_message", array($return, $found_shortcodes, $db_query, 'hey') , 940);

}


/*
function shortcode_logic_admin_notices() {
  $user_id = get_current_user_id();
  if ( $tagnames = get_transient( "shortcode_logic_admin_message" ) ) { ?>
      <div class="error">
          <p>
          <pre>
          <?php echo print_r($tagnames, true); ?>
          </pre>
          </p>
      </div><?php

      delete_transient("shortcode_logic_admin_message");
  }
}
*/

// add_action( 'admin_notices', 'shortcode_logic_admin_notices' );



function shortcode_logic_create_databse_table() {
  global $wpdb;

  $charset_collate = '';
  if ( ! empty( $wpdb->charset ) )
    $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
  if ( ! empty( $wpdb->collate ) )
    $charset_collate .= " COLLATE $wpdb->collate";

  $blog_id_col = ( is_multisite() ) ? "blog_id bigint(20) NOT NULL, " : '';

  $wpdb->query(
    "CREATE TABLE IF NOT EXISTS ". SCLO_DBTABLE ." (
      $blog_id_col
      post_id int NOT NULL PRIMARY KEY,
      rule longtext NOT NULL,
      permalink longtext NOT NULL,
      checksum longtext NOT NULL
    ) $charset_collate;"
  );

}

/**
 *
 * return: (bool) true if something changed
 */
function shortcode_logic_write_rule_to_db($post_id, $shortcodes) {

  // Write updates to database
  global $wpdb;

  $db_row = $wpdb->get_results( "SELECT post_id, checksum, permalink FROM " . SCLO_DBTABLE . " WHERE post_id = '$post_id'" );

  // delete rule from database
  if ( empty($shortcodes) && $db_row != NULL ) {
    $response = $wpdb->delete( SCLO_DBTABLE, array( 'post_id' => $post_id ) );
    return true;
  }
  if ( empty($shortcodes) ) {
    return false;
  }

  // use checksum to make it faster
  $new_checksum = crc32(implode($shortcodes));
  $new_permalink = get_permalink($post_id);

  // shortcodes or permalink have changed
  // update existing entry

  if ( $db_row != NULL && ( $db_row[0]->checksum == $new_checksum && $db_row[0]->permalink == $new_permalink ) ) {
    // nothing changed

    return false;

  } elseif ( $db_row != NULL ) {
      // update existing entry

      $rule = shortcode_logic_generate_rule($post_id, $shortcodes);

      $wpdb->update( SCLO_DBTABLE, array(
                    'post_id' => $post_id ,
                    'rule' => $rule,
                    'permalink' => $new_permalink,
                    'checksum' => $new_checksum
                    ), array('post_id' => $post_id)
                );

      return true;

    } else {
        // create new entry

        $rule = shortcode_logic_generate_rule($post_id, $shortcodes);

        global $wpdb;
        $wpdb->insert( SCLO_DBTABLE, array(
                      'post_id' => $post_id,
                      'rule' => $rule,
                      'permalink' => $new_permalink,
                      'checksum' => $new_checksum
                      )
                  );

        return true;

    }

    return true;
  }

function shortcode_logic_generate_rule($post_id, $shortcodes) {

  // this creates something like this
  //  $rules['http://example.com/some/url/fraqments/'] = array( 'one-plugin/one-plugin.php','another-plugin/another-plugin.php','yet-another-ultimative-wp-plugin/yet-another-ultimative-wp-plugin.php', );

  $plugins = array();
  foreach ($shortcodes as $shortcode) {
    $plugins[] = shortcode_logic_shortcode_to_plugin_path($shortcode);
  }

  array_unique($plugins);

  $plugin_paths = implode ( "', '" , $plugins );
  $plugin_paths = "'" . $plugin_paths . "'";

  $permalink = get_permalink($post_id);
  $permalink = str_replace( home_url(), '', $permalink );

  $rule = "\t\t\$rules['" . $permalink . "'] = array( " . $plugin_paths . " );";

  return $rule;
}

function shortcode_logic_write_rule_file() {

      $rule_file = WPMU_PLUGIN_DIR . '/' . 'shortcode-logic-rules.php';

      $rules = shortcode_logic_generate_rules_file_content();

      if ( $rules != '') {

        // Check directory permissions and write the WPMU_PLUGIN_DIR directory if not exists,
        if ( !file_exists( WPMU_PLUGIN_DIR ) ) {
          if ( is_writable( WP_CONTENT_DIR ) ) {
            mkdir(WPMU_PLUGIN_DIR, 0755);
          } else {
            $error_in1 = substr(WP_CONTENT_DIR, strlen( ABSPATH ));
            $error_in2 = substr(WPMU_PLUGIN_DIR, strlen( ABSPATH ));
            $write_error = '';
            $write_error .= '<div class="update-nag"><p> Your <code>'. $error_in1 .'</code> directory isn&#8217;t <a href="http://codex.wordpress.org/Changing_File_Permissions">writable</a>.<br>';
            $write_error .= 'These are the rules you should write in an PHP-File, create the '. $error_in2 .' directory and place it in the <code>'. $error_in2 .'</code> directory. ';
            $write_error .= 'Click in the field and press <kbd>CTRL + a</kbd> to select all. </p>';
            $write_error .= '<textarea  readonly="readonly" name="rules_txt" rows="7" style="width:100%; padding:11px 15px;">' . $rules . '</textarea></div>';
            $write_error .= '<br><br>';

            $err_arr[] = $write_error;
            $err_arr[] = $rules;
            return $err_arr;
          }
        }

        // Check directory permissions and write the plugin-logic-rules.php file
        if ( file_exists( WPMU_PLUGIN_DIR ) ) {
          if ( is_writable( WPMU_PLUGIN_DIR ) ) {
            file_put_contents( $rule_file, $rules );
          } else {
            $error_in = substr(WPMU_PLUGIN_DIR, strlen( ABSPATH ));
            $write_error = '';
            $write_error .= '<div class="update-nag"><p> Your <code>'. $error_in .'</code> directory isn&#8217;t <a href="http://codex.wordpress.org/Changing_File_Permissions">writable</a>.<br>';
            $write_error .= 'These are the rules you should write in an PHP-File and place it in the <code>'. $error_in .'</code> directory. ';
            $write_error .= 'Click in the field and press <kbd>CTRL + a</kbd> to select all. </p>';
            $write_error .= '<textarea  readonly="readonly" name="rules_txt" rows="7" style="width:100%; padding:11px 15px;">' . $rules . '</textarea></div>';
            $write_error .= '<br><br>';

            $err_arr[] = $write_error;
            $err_arr[] = $rules;
            return $err_arr;
          }
        }

      } elseif ( file_exists( $rule_file ) )  {
        if ( !unlink( $rule_file ) )  {
          wp_die( wp_sprintf(
                'Error cannot delete the old rule file: <code>' . substr($rule_file, strlen( ABSPATH )) .'</code>'.
                ' The directory isn&#8217;t writable.'
              )
          );
        }
      }

      return false;
    }



    /***
     * Creates the plugin activation/deactivation rules for singlesite installations
     * @since 1.0.4
     */
    function shortcode_logic_generate_rules_file_content() {
      $rule_file_content = '';

      global $wpdb;
      $results = $wpdb->get_results( "SELECT * FROM " . SCLO_DBTABLE );

      $rules = "\t\t\$rules = array();\n";

      foreach ($results as $result) {
        $rules .= $result->rule . "\n" ;
      }

      if ( !empty($rules) ) {

        // Structur from the beginning and the end of the rule file
        $first_part = "<?php \n";
        $first_part .= "/***\n";
        $first_part .= " * Contains the rules for the activation and deactivation of the plugins \n";
        $first_part .= " *\n";
        $first_part .= " * @package     Shortcode Logic\n";
        $first_part .= " * @author      ib4s\n";
        $first_part .= " * \n";
        $first_part .= " * @change      1.0.4\n";
        $first_part .= " * @since       1.0.0\n";
        $first_part .= " */\n\n";
        $first_part .= "if ( !is_admin() ) {\n\n";

        $first_part .= "\tfunction shortcode_logic_rules( \$plugins ) { \n";
        $first_part .= "\t\t// from https://example.com/hello/world?how_are=you&great  \n";
        $first_part .= "\t\t// to /hello/world  \n";
        $first_part .= "\t\t\$url = strtok( \$_SERVER['REQUEST_URI'], '?' );  \n";
        $first_part .= "\n\n";


        $last_part  = "\n\n";
        $last_part .= "\t\tif ( isset( \$rules[\$url]) ) { \n";
        $last_part .= "\t\t\t// add plugin to activated plugin list \n";
        $last_part .= "\t\t\t\$plugins = array_unique( array_merge( \$plugins, \$rules[\$url] ) ); \n";
        $last_part .= "\t\t} \n";

        $last_part .= "\n\n";

        $last_part .= "\t\treturn \$plugins; \n";
        $last_part .= "\t}";

        $last_part .= "\n\n";

        $last_part .= "\tadd_filter( 'option_active_plugins', 'shortcode_logic_rules', 20 );\n";
        $last_part .= "}\n";


        $rule_file_content = $first_part . $rules . $last_part;
      }

      return $rule_file_content;
    }

    function shortcode_logic_regenerate_detected_shortcodes() {

        global $wpdb;
        // get all posts
        $posts = $wpdb->get_results(
          "SELECT ID, post_content FROM " . $GLOBALS['wpdb']->base_prefix . "posts WHERE post_type = 'post' AND post_status = 'publish'"
        );

        // delete all existing entries
        $wpdb->query(
          "TRUNCATE TABLE " . SCLO_DBTABLE
        );


        $output = array();


        foreach ( $posts as $post )
        {
         shortcode_logic_dectect_shortcode($post->ID, $post->post_content);
        }

      return true;
    }

/**
 * Shortcode_logic_dectect_shortcode
 * Detect shortcodes in content and write rule to database, if there is one
 *
 * input: post_id and post_content
 * return: (bool) true if there where changes
 */
function shortcode_logic_dectect_shortcode($post_id, $post_content) {

  // get plugins that should be watched
  // to do - if not set, don't do stuff
  $watched_shortcodes = get_option('shortcode_logic_watched_shortcodes');
  if ( empty($watched_shortcodes) ) {
    return;
  }

  // get active shortcodes
  global $shortcode_tags;

  if ( empty($shortcode_tags) || !is_array($shortcode_tags) ) {
    return false;
  } else {

    $found_shortcodes = array();

    // Find all registered tag names in $content.
    preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $post_content, $matches );
    // get active shortcodes that are watched and used in the content
    $found_shortcodes = array_intersect( array_keys( $shortcode_tags ), $matches[1], array_keys( $watched_shortcodes ) );
    // sort plugins
    if (is_array( $found_shortcodes) ) {
      sort($found_shortcodes);
    } else {
      $found_shortcodes = array();
    }

    // write or update database entry for this post
    // if no plugins registered - delete entry for this post
    shortcode_logic_write_rule_to_db($post_id, $found_shortcodes);

    $shortcodes_count = count($found_shortcodes);

    return $shortcodes_count;
  }

}



/***
 * Actions if user deactivate Shortcode Logic:
 * Delete the rule file in the WPMU_PLUGIN_DIR and if the directory is empty also delete them
 *
 */
function shortcode_logic_on_deactiviation() {
  $rule_file = WPMU_PLUGIN_DIR . '/' . 'shortcode-logic-rules.php';
  if ( file_exists($rule_file) ) {
    if ( !unlink($rule_file) ) {
      trigger_error("Cannot delete the old rule file: <code>" .
        substr($rule_file, strlen( ABSPATH )) ."</code>", E_USER_ERROR);
    }
  }

  if ( file_exists( WPMU_PLUGIN_DIR ) )  {
    if ( count(scandir(WPMU_PLUGIN_DIR) <= 2) ) rmdir( WPMU_PLUGIN_DIR );
  }
}

/***
 * Actions if user uninstall Shortcode Logic:
 * Delete database entries
 * Delete setting
 * Delete transient
 * Delete rules file
 *
 */
function shortcode_logic_on_uninstall() {

  shortcode_logic_on_deactiviation();

  delete_option( 'shortcode_logic_watched_plugins' );

  // Drop a custom db table
  global $wpdb;
  $wpdb->query( "DROP TABLE IF EXISTS " . SCLO_DBTABLE );

  // delete transients
  $wpdb->query("DELETE FROM `{$wpdb->prefix}options` WHERE `option_name` LIKE ('_transient_shortcode-logic%')");

}
