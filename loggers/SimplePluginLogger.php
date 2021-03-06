<?php

/*


# logga installs/updates av plugins som är silent

// Innan update körs en av dessa
$current = get_site_option( 'active_sitewide_plugins', array() );
$current = get_option( 'active_plugins', array() );

// efter update körs en av dessa
update_site_option( 'active_sitewide_plugins', $current );
update_option('active_plugins', $current);

// så: jämför om arrayen har ändrats, och om den har det = ny plugin aktiverats




# extra stuff
vid aktivering/installation av plugin: spara resultat från
get_plugin_files($plugin)
så vid ev intrång/skadlig kod uppladdad så kan man analysera lite

*/


/**
 * Logs plugins installs and updates
 */
class SimplePluginLogger extends SimpleLogger
{

	// The logger slug. Defaulting to the class name is nice and logical I think
	public $slug = __CLASS__;

	/**
	 * Get array with information about this logger
	 * 
	 * @return array
	 */
	function getInfo() {

		$arr_info = array(			
			"name" => "Plugin Logger",
			"description" => "Logs plugin installs, uninstalls and updates",
			"capability" => "activate_plugins", // install_plugins, activate_plugins, 
			"messages" => array(

				'plugin_activated' => _x(
					'Activated plugin "{plugin_name}"', 
					'Plugin was non-silently activated by a user',
					'simple-history'
				),

				'plugin_deactivated' => _x(
					'Deactivated plugin "{plugin_name}"', 
					'Plugin was non-silently deactivated by a user',
					'simple-history'
				),

				'plugin_installed' => _x(
					'Installed plugin "{plugin_name}"', 
					'Plugin was installed',
					'simple-history'
				),

				'plugin_installed_failed' => _x(
					'Failed to install plugin "{plugin_name}"', 
					'Plugin failed to install',
					'simple-history'
				),

				'plugin_updated' => _x(
					'Updated plugin "{plugin_name}" to version {plugin_version} from {plugin_prev_version}', 
					'Plugin was updated',
					'simple-history'
				),

				'plugin_update_failed' => _x(
					'Updated plugin "{plugin_name}"', 
					'Plugin update failed',
					'simple-history'
				),

				'plugin_file_edited' => _x(
					'Edited plugin file "{plugin_edited_file}"', 
					'Plugin file edited',
					'simple-history'
				),

				'plugin_deleted' => _x(
					'Deleted plugin "{plugin_name}"', 
					'Plugin files was deleted',
					'simple-history'
				),

				// bulk versions
				'plugin_bulk_updated' => _x(
					'Updated plugin "{plugin_name}" to {plugin_version} from {plugin_prev_version}', 
					'Plugin was updated in bulk',
					'simple-history'
				),
			), // messages
			"labels" => array(
				"search" => array(
					"label" => _x("Plugins", "Plugin logger: search", "simple-history"),
					"options" => array(
						_x("Activated plugins", "Plugin logger: search", "simple-history") => array(
							'plugin_activated'
						),
						_x("Deactivated plugins", "Plugin logger: search", "simple-history") => array(
							'plugin_deactivated'
						),
						_x("Installed plugins", "Plugin logger: search", "simple-history") => array(
							'plugin_installed'
						),
						_x("Failed plugin installs", "Plugin logger: search", "simple-history") => array(
							'plugin_installed_failed'
						),
						_x("Updated plugins", "Plugin logger: search", "simple-history") => array(
							'plugin_updated',
							'plugin_bulk_updated'
						),
						_x("Failed plugin updates", "Plugin logger: search", "simple-history") => array(
							'plugin_update_failed'
						),
						_x("Edited plugin files", "Plugin logger: search", "simple-history") => array(
							'plugin_file_edited'
						),
						_x("Deleted plugins", "Plugin logger: search", "simple-history") => array(
							'plugin_deleted'
						),
					)
				) // search array
			) // labels
		);
		
		return $arr_info;

	}

	public function loaded() {

		#sf_d(get_plugins(), 'get_plugins()');

		//do_action( 'current_screen', $current_screen );
		// The first hook where current screen is available
		//add_action( 'current_screen', array( $this, "save_versions_before_update" ) );

		/**
		 * At least the plugin bulk upgrades fires this action before upgrade
		 * We use it to fetch the current version of all plugins, before they are upgraded
		 */
		add_filter( 'upgrader_pre_install', array( $this, "save_versions_before_update"), 10, 2);

		// Clear our transient after an update is done
		add_action( 'delete_site_transient_update_plugins', array( $this, "remove_saved_versions" ) );

		// Fires after a plugin has been activated.
		// If a plugin is silently activated (such as during an update),
		// this hook does not fire.
		add_action( 'activated_plugin', array( $this, "on_activated_plugin" ), 10, 2 );
		
		// Fires after a plugin is deactivated.
		// If a plugin is silently deactivated (such as during an update),
		// this hook does not fire.
		add_action( 'deactivated_plugin', array( $this, "on_deactivated_plugin" ), 10, 2 );

		// Fires after the upgrades has done it's thing
		// Check hook extra for upgrader initiator
		//add_action( 'upgrader_post_install', array( $this, "on_upgrader_post_install" ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, "on_upgrader_process_complete" ), 10, 2 );

		// Dirty check for things that we can't catch using filters or actions
		add_action( 'admin_init', array( $this, "check_filterless_things" ) );

		// Detect files removed
		add_action( 'setted_transient', array( $this, 'on_setted_transient_for_remove_files' ), 10, 2 );

		/*
		do_action( 'automatic_updates_complete', $this->update_results );
		 * Fires after all automatic updates have run.
		 *
		 * @since 3.8.0
		 *
		 * @param array $update_results The results of all attempted updates.
		*/

	}

	function save_versions_before_update($bool, $hook_extra) {

		$plugins = get_plugins();

		update_option( $this->slug . "_plugin_info_before_update", SimpleHistory::json_encode( $plugins ) );

		return $bool;

	}

	/**
	 * Detect plugin being deleted
	 * When WP is done deleting a plugin it sets a transient called plugins_delete_result:
	 * set_transient('plugins_delete_result_' . $user_ID, $delete_result);
	 *
	 * We detect when that transient is set and then we have all info needed to log the plugin delete
	 *	 
	 */
	public function on_setted_transient_for_remove_files($transient, $value) {

		if ( ! $user_id = get_current_user_id() ) {
			return;
		}

		$transient_name = '_transient_plugins_delete_result_' . $user_id;
		if ( $transient_name !== $transient ) {
			return;
		}

		// We found the transient we were looking for
		if ( 
				isset( $_POST["action"] )
				&& "delete-selected" == $_POST["action"]
				&& isset( $_POST["checked"] )
				&& is_array( $_POST["checked"] )
				) {

			/*
		    [checked] => Array
		        (
		            [0] => the-events-calendar/the-events-calendar.php
		        )
		    */

			$plugins_deleted = $_POST["checked"];
			$plugins_before_update = json_decode( get_option( $this->slug . "_plugin_info_before_update", false ), true );

			foreach ($plugins_deleted as $plugin) {
				
				$context = array(
					"plugin" => $plugin
				);

				if ( is_array( $plugins_before_update ) && isset( $plugins_before_update[ $plugin ] ) ) {
					$context["plugin_name"] = $plugins_before_update[ $plugin ]["Name"];
					$context["plugin_title"] = $plugins_before_update[ $plugin ]["Title"];
					$context["plugin_description"] = $plugins_before_update[ $plugin ]["Description"];
					$context["plugin_author"] = $plugins_before_update[ $plugin ]["Author"];
					$context["plugin_version"] = $plugins_before_update[ $plugin ]["Version"];
					$context["plugin_url"] = $plugins_before_update[ $plugin ]["PluginURI"];
				}

				$this->infoMessage(
					"plugin_deleted",
					$context
				);

			}

		}
		
		$this->remove_saved_versions();

	}

	/**
	 * Save all plugin information before a plugin is updated or removed.
	 * This way we can know both the old (pre updated/removed) and the current version of the plugin
	 */
	/*public function save_versions_before_update() {
		
		$current_screen = get_current_screen();
		$request_uri = $_SERVER["SCRIPT_NAME"];

		// Only add option on pages where needed
		$do_store = false;

		if ( 
				SimpleHistory::ends_with( $request_uri, "/wp-admin/update.php" )
				&& isset( $current_screen->base ) 
				&& "update" == $current_screen->base 
			) {
			
			// Plugin update screen
			$do_store = true;

		} else if ( 
				SimpleHistory::ends_with( $request_uri, "/wp-admin/plugins.php" )
				&& isset( $current_screen->base ) 
				&& "plugins" == $current_screen->base
				&& ( isset( $_POST["action"] ) && "delete-selected" == $_POST["action"] )
			) {
			
			// Plugin delete screen, during delete
			$do_store = true;

		}

		if ( $do_store ) {
			update_option( $this->slug . "_plugin_info_before_update", SimpleHistory::json_encode( get_plugins() ) );
		}

	}
	*/

	/**
	  * when plugin updates are done wp_clean_plugins_cache() is called,
	  * which in it's turn run:
	  * delete_site_transient( 'update_plugins' );
	  * do_action( 'delete_site_transient_' . $transient, $transient );
	  * delete_site_transient_update_plugins
	  */
	public function remove_saved_versions() {
		
		delete_option( $this->slug . "_plugin_info_before_update" );

	}

	function check_filterless_things() {

		// Var is string with length 113: /wp-admin/plugin-editor.php?file=my-plugin%2Fviews%2Fplugin-file.php
		$referer = wp_get_referer();
		
		// contains key "path" with value like "/wp-admin/plugin-editor.php"
		$referer_info = parse_url($referer);

		if ( "/wp-admin/plugin-editor.php" === $referer_info["path"] ) {

			// We are in plugin editor
			// Check for plugin edit saved		
			if ( isset( $_POST["newcontent"] ) && isset( $_POST["action"] ) && "update" == $_POST["action"] && isset( $_POST["file"] ) && ! empty( $_POST["file"] ) ) {

				// A file was edited
				$file = $_POST["file"];

				// $plugins = get_plugins();
				// http://codex.wordpress.org/Function_Reference/wp_text_diff
				
				// Generate a diff of changes
				if ( ! class_exists( 'WP_Text_Diff_Renderer_Table' ) ) {
					require( ABSPATH . WPINC . '/wp-diff.php' );
				}

				$original_file_contents = file_get_contents( WP_PLUGIN_DIR . "/" . $file );
				$new_file_contents = wp_unslash( $_POST["newcontent"] );

				$left_lines  = explode("\n", $original_file_contents);
				$right_lines = explode("\n", $new_file_contents);
				$text_diff = new Text_Diff($left_lines, $right_lines);

				$num_added_lines = $text_diff->countAddedLines();
				$num_removed_lines = $text_diff->countDeletedLines();

				// Generate a diff in classic diff format
				$renderer  = new Text_Diff_Renderer();
				$diff = $renderer->render($text_diff);

				$this->infoMessage(
					'plugin_file_edited',
					array(
						"plugin_edited_file" => $file,
						"plugin_edit_diff" => $diff,
						"plugin_edit_num_added_lines" => $num_added_lines,
						"plugin_edit_num_removed_lines" => $num_removed_lines,
					)
				);

				$did_log = true;

			}

		}


	}

	/**
	 * Called when plugins is updated or installed
	 */
	function on_upgrader_process_complete( $plugin_upgrader_instance, $arr_data ) {

		// Can't use get_plugins() here to get version of plugins updated from
		// Tested that, and it will get the new version (and that's the correct answer I guess. but too bad for us..)
		// $plugs = get_plugins();
		// $context["_debug_get_plugins"] = SimpleHistory::json_encode( $plugs );
		/*

		Try with these instead:
		$current = get_site_transient( 'update_plugins' );
		add_filter('upgrader_clear_destination', array($this, 'delete_old_plugin'), 10, 4);

		*/

		/*	

		# WordPress core update
		
		$arr_data:
		Array
		(
		    [action] => update
		    [type] => core
		)

		
		# Plugin install
		
		$arr_data:
		Array
		(
		    [type] => plugin
		    [action] => install
		)


		# Plugin update
		
		$arr_data:
		Array
		(
		    [type] => plugin
		    [action] => install
		)

		# Bulk actions

		array(
			'action' => 'update',
			'type' => 'plugin',
			'bulk' => true,
			'plugins' => $plugins,
		)

		*/

		// To keep track of if something was logged, so wen can output debug info only
		// only if we did not log anything
		$did_log = false;

		if ( isset( $arr_data["type"] ) && "plugin" == $arr_data["type"] ) {

			// Single plugin install
			if ( isset( $arr_data["action"] ) && "install" == $arr_data["action"] && ! $plugin_upgrader_instance->bulk ) {

				// Upgrader contains current info
				$context = array(
					"plugin_name" => $plugin_upgrader_instance->skin->api->name,
					"plugin_slug" => $plugin_upgrader_instance->skin->api->slug,
					"plugin_version" => $plugin_upgrader_instance->skin->api->version,
					"plugin_author" => $plugin_upgrader_instance->skin->api->author,
					"plugin_last_updated" => $plugin_upgrader_instance->skin->api->last_updated,
					"plugin_requires" => $plugin_upgrader_instance->skin->api->requires,
					"plugin_tested" => $plugin_upgrader_instance->skin->api->tested,
					"plugin_rating" => $plugin_upgrader_instance->skin->api->rating,
					"plugin_num_ratings" => $plugin_upgrader_instance->skin->api->num_ratings,
					"plugin_downloaded" => $plugin_upgrader_instance->skin->api->downloaded,
					"plugin_added" => $plugin_upgrader_instance->skin->api->added,
					"plugin_source_files" => $this->simpleHistory->json_encode( $plugin_upgrader_instance->result["source_files"] ),
					//"upgrader_skin_api" => $this->simpleHistory->json_encode( $plugin_upgrader_instance->skin->api )
				);

				if ( is_a( $plugin_upgrader_instance->skin->result, "WP_Error" ) ) {

					// Add errors
					// Errors is in original wp admin language
					$context["error_messages"] = $this->simpleHistory->json_encode( $plugin_upgrader_instance->skin->result->errors );
					$context["error_data"] = $this->simpleHistory->json_encode( $plugin_upgrader_instance->skin->result->error_data );

					$this->infoMessage(
						'plugin_installed_failed',
						$context
					);

					$did_log = true;
					
				} else {

					// Plugin was successfully installed
					// Try to grab more info from the readme
					// Would be nice to grab a screenshot, but that is difficult since they often are stored remotely
					$plugin_destination = isset( $plugin_upgrader_instance->result["destination"] ) ? $plugin_upgrader_instance->result["destination"] : null;
					if ($plugin_destination) {

						$plugin_info = $plugin_upgrader_instance->plugin_info();
						$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_info );
						$context["plugin_description"] = $plugin_data["Description"];
						$context["plugin_url"] = $plugin_data["PluginURI"];

					}

					$this->infoMessage(
						'plugin_installed',
						$context
					);

					$did_log = true;

				}

			} // install single

			// Single plugin update
			if ( isset( $arr_data["action"] ) && "update" == $arr_data["action"] && ! $plugin_upgrader_instance->bulk ) {

				// No plugin info in instance, so get it ourself
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $arr_data["plugin"] );

				// autoptimize/autoptimize.php
				$plugin_slug = dirname( $arr_data["plugin"] );

				$context = array(
					"plugin_slug" => $plugin_slug,
					"request" => $this->simpleHistory->json_encode( $_REQUEST ),
					"plugin_name" => $plugin_data["Name"],
					"plugin_title" => $plugin_data["Title"],
					"plugin_description" => $plugin_data["Description"],
					"plugin_author" => $plugin_data["Author"],
					"plugin_version" => $plugin_data["Version"],
					"plugin_url" => $plugin_data["PluginURI"],
					"plugin_source_files" => $this->simpleHistory->json_encode( $plugin_upgrader_instance->result["source_files"] )
				);

				// update status for plugins are in response
				// plugin folder + index file = key
				// use transient to get url and package
				$update_plugins = get_site_transient( 'update_plugins' );
				if ( $update_plugins && isset( $update_plugins->response[ $arr_data["plugin"] ] ) ) {
					
					/*
					$update_plugins[plugin_path/slug]:
					{
						"id": "8986",
						"slug": "autoptimize",
						"plugin": "autoptimize/autoptimize.php",
						"new_version": "1.9.1",
						"url": "https://wordpress.org/plugins/autoptimize/",
						"package": "https://downloads.wordpress.org/plugin/autoptimize.1.9.1.zip"
					}
					*/
					// for debug purposes the update_plugins key can be added
					// $context["update_plugins"] = $this->simpleHistory->json_encode( $update_plugins );

					$plugin_update_info = $update_plugins->response[ $arr_data["plugin"] ];

					// autoptimize/autoptimize.php
					if ( isset( $plugin_update_info->plugin ) ) {
						$context["plugin_update_info_plugin"] = $plugin_update_info->plugin;
					}

					// https://downloads.wordpress.org/plugin/autoptimize.1.9.1.zip
					if ( isset( $plugin_update_info->package ) ) {
						$context["plugin_update_info_package"] = $plugin_update_info->package;
					}

				}

				// To get old version we use our option
				$plugins_before_update = json_decode( get_option( $this->slug . "_plugin_info_before_update", false ), true );
				if ( is_array( $plugins_before_update ) && isset( $plugins_before_update[ $arr_data["plugin"] ] ) ) {

					$context["plugin_prev_version"] = $plugins_before_update[ $arr_data["plugin"] ]["Version"];

				}

				if ( is_a( $plugin_upgrader_instance->skin->result, "WP_Error" ) ) {

					// Add errors
					// Errors is in original wp admin language
					$context["error_messages"] = json_encode( $plugin_upgrader_instance->skin->result->errors );
					$context["error_data"] = json_encode( $plugin_upgrader_instance->skin->result->error_data );

					$this->infoMessage(
						'plugin_update_failed',
						$context
					);

					$did_log = true;
					
				} else {

					$this->infoMessage(
						'plugin_updated',
						$context
					);

					#echo "on_upgrader_process_complete";
					#sf_d( $plugin_upgrader_instance, '$plugin_upgrader_instance' );
					#sf_d( $arr_data, '$arr_data' );

					$did_log = true;

				}

			} // update single
		

			/**
			 * For bulk updates $arr_data looks like:
			 * Array
			 * (
			 *     [action] => update
			 *     [type] => plugin
			 *     [bulk] => 1
			 *     [plugins] => Array
			 *         (
			 *             [0] => plugin-folder-1/plugin-index.php
			 *             [1] => my-plugin-folder/my-plugin.php
			 *         )
			 * )
			 */
			if ( isset( $arr_data["bulk"] ) && $arr_data["bulk"] && isset( $arr_data["action"] ) && "update" == $arr_data["action"] ) {

				$plugins_updated = isset( $arr_data["plugins"] ) ? (array) $arr_data["plugins"] : array();

				foreach ($plugins_updated as $plugin_name) {

					$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_name );

					$plugin_slug = dirname( $plugin_name );
			
					$context = array(
						"plugin_slug" => $plugin_slug,
						"plugin_name" => $plugin_data["Name"],
						"plugin_title" => $plugin_data["Title"],
						"plugin_description" => $plugin_data["Description"],
						"plugin_author" => $plugin_data["Author"],
						"plugin_version" => $plugin_data["Version"],
						"plugin_url" => $plugin_data["PluginURI"]
					);

					// get url and package
					$update_plugins = get_site_transient( 'update_plugins' );
					if ( $update_plugins && isset( $update_plugins->response[ $plugin_name ] ) ) {
						
						/*
						$update_plugins[plugin_path/slug]:
						{
							"id": "8986",
							"slug": "autoptimize",
							"plugin": "autoptimize/autoptimize.php",
							"new_version": "1.9.1",
							"url": "https://wordpress.org/plugins/autoptimize/",
							"package": "https://downloads.wordpress.org/plugin/autoptimize.1.9.1.zip"
						}
						*/

						$plugin_update_info = $update_plugins->response[ $plugin_name ];

						// autoptimize/autoptimize.php
						if ( isset( $plugin_update_info->plugin ) ) {
							$context["plugin_update_info_plugin"] = $plugin_update_info->plugin;
						}

						// https://downloads.wordpress.org/plugin/autoptimize.1.9.1.zip
						if ( isset( $plugin_update_info->package ) ) {
							$context["plugin_update_info_package"] = $plugin_update_info->package;
						}

					}

					// To get old version we use our option
					// @TODO: this does not always work, why?
					$plugins_before_update = json_decode( get_option( $this->slug . "_plugin_info_before_update", false ), true );
					if ( is_array( $plugins_before_update ) && isset( $plugins_before_update[ $plugin_name ] ) ) {

						$context["plugin_prev_version"] = $plugins_before_update[ $plugin_name ]["Version"];

					}

					$this->infoMessage(
						'plugin_bulk_updated',
						$context
					);

				}

			} // bulk update

		
		} // if plugin

		if ( ! $did_log ) {
			#echo "on_upgrader_process_complete";
			#sf_d( $plugin_upgrader_instance, '$plugin_upgrader_instance' );
			#sf_d( $arr_data, '$arr_data' );
			#exit;
		}

	}

	/*
	 * Called from filter 'upgrader_post_install'. 
	 *
	 * Used to log bulk plugin installs and updates
	 *
	 * Filter docs:
	 *
	 * Filter the install response after the installation has finished.
	 *
	 * @param bool  $response   Install response.
	 * @param array $hook_extra Extra arguments passed to hooked filters.
	 * @param array $result     Installation result data.
	 */
	public function on_upgrader_post_install( $response, $hook_extra, $result ) {
		
		#echo "on_upgrader_post_install";
		/*
		
		# Plugin update:
		$hook_extra
		Array
		(
		    [plugin] => plugin-folder/plugin-name.php
		    [type] => plugin
		    [action] => update
		)

		# Plugin install, i.e. download/install, but not activation:
		$hook_extra:
		Array
		(
		    [type] => plugin
		    [action] => install
		)

		*/

		if ( isset( $hook_extra["action"] ) && $hook_extra["action"] == "install" && isset( $hook_extra["type"] ) && $hook_extra["type"] == "plugin" ) {

			// It's a plugin install
			#error_log("plugin install");
			

		} else if ( isset( $hook_extra["action"] ) && $hook_extra["action"] == "update" && isset( $hook_extra["type"] ) && $hook_extra["type"] == "plugin" ) {
			
			// It's a plugin upgrade
			#echo "plugin update!";
			//error_log("plugin update");

		} else {

			//error_log("other");

		}

		#sf_d($response, '$response');
		#sf_d($hook_extra, '$hook_extra');
		#sf_d($result, '$result');
		#exit;

		return $response;

	}

	/*

		 * Filter the list of action links available following bulk plugin updates.
		 *
		 * @since 3.0.0
		 *
		 * @param array $update_actions Array of plugin action links.
		 * @param array $plugin_info    Array of information for the last-updated plugin.

		$update_actions = apply_filters( 'update_bulk_plugins_complete_actions', $update_actions, $this->plugin_info );

	*/

	/*


		*
		 * Fires when the bulk upgrader process is complete.
		 *
		 * @since 3.6.0
		 *
		 * @param Plugin_Upgrader $this Plugin_Upgrader instance. In other contexts, $this, might
		 *                              be a Theme_Upgrader or Core_Upgrade instance.
		 * @param array           $data {
		 *     Array of bulk item update data.
		 *
		 *     @type string $action   Type of action. Default 'update'.
		 *     @type string $type     Type of update process. Accepts 'plugin', 'theme', or 'core'.
		 *     @type bool   $bulk     Whether the update process is a bulk update. Default true.
		 *     @type array  $packages Array of plugin, theme, or core packages to update.
		 * }
		 *
		do_action( 'upgrader_process_complete', $this, array(
			'action' => 'update',
			'type' => 'plugin',
			'bulk' => true,
			'plugins' => $plugins,
		) );


	do_action( 'upgrader_process_complete', $this, array( 'action' => 'update', 'type' => 'core' ) );
	*/

	/**
	 * Plugin is activated
	 * plugin_name is like admin-menu-tree-page-view/index.php
	 */
	function on_activated_plugin($plugin_name, $network_wide) {

		/*
		Plugin data returned array contains the following:
		'Name' - Name of the plugin, must be unique.
		'Title' - Title of the plugin and the link to the plugin's web site.
		'Description' - Description of what the plugin does and/or notes from the author.
		'Author' - The author's name
		'AuthorURI' - The authors web site address.
		'Version' - The plugin version number.
		'PluginURI' - Plugin web site address.
		'TextDomain' - Plugin's text domain for localization.
		'DomainPath' - Plugin's relative directory path to .mo files.
		'Network' - Boolean. Whether the plugin can only be activated network wide.
		*/
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_name );
		
		$plugin_slug = dirname( $plugin_name );

		$context = array(
			"plugin_name" => $plugin_data["Name"],
			"plugin_slug" => $plugin_slug,
			"plugin_title" => $plugin_data["Title"],
			"plugin_description" => $plugin_data["Description"],
			"plugin_author" => $plugin_data["Author"],
			"plugin_version" => $plugin_data["Version"],
			"plugin_url" => $plugin_data["PluginURI"],
		);

		$this->infoMessage( 'plugin_activated', $context );
		
	}

	/**
	 * Plugin is deactivated
	 * plugin_name is like admin-menu-tree-page-view/index.php
	 */
	function on_deactivated_plugin($plugin_name) {

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_name );
		$plugin_slug = dirname( $plugin_name );
		
		$context = array(
			"plugin_name" => $plugin_data["Name"],
			"plugin_slug" => $plugin_slug,
			"plugin_title" => $plugin_data["Title"],
			"plugin_description" => $plugin_data["Description"],
			"plugin_author" => $plugin_data["Author"],
			"plugin_version" => $plugin_data["Version"],
			"plugin_url" => $plugin_data["PluginURI"],
		);

		$this->infoMessage( 'plugin_deactivated', $context );

	}


	/**
	 * Get output for detailed log section
	 */
	function getLogRowDetailsOutput($row) {

		$context = $row->context;
		$message_key = $context["_message_key"];
		$output = "";

		// When a plugin is installed we show a bit more information
		// We do it only on install because we don't want to clutter to log,
		// and when something is installed the description is most useul for other 
		// admins on the site
		if ( "plugin_installed" === $message_key ) {
	
			if ( isset($context["plugin_description"]) ) {

				// Description includes a link to author, remove that, i.e. all text after and including <cite>
				$plugin_description = $context["plugin_description"];
				$cite_pos = mb_strpos($plugin_description, "<cite>");
				if ($cite_pos) {
					$plugin_description = mb_strcut( $plugin_description, 0, $cite_pos );
				}

				// Keys to show
				$arr_plugin_keys = array(
					"plugin_version" => _x("Version", "plugin logger - detailed output version", "simple-history"),
					"plugin_description" => "Description",
					"plugin_author" => _x("Author", "plugin logger - detailed output author", "simple-history"),
					"plugin_url" => _x("URL", "plugin logger - detailed output url", "simple-history"),
					"plugin_requires" => _x("Requires", "plugin logger - detailed output author", "simple-history"),
					"plugin_tested" => _x("Compatible up to", "plugin logger - detailed output compatible", "simple-history"),
					"plugin_downloaded" => _x("Downloads", "plugin logger - detailed output downloaded", "simple-history"),
					// also available: plugin_rating, plugin_num_ratings
				);

				$arr_plugin_keys = apply_filters("simple_history/plugin_logger/row_details_plugin_info_keys", $arr_plugin_keys);

				// Start output of plugin meta data table
				$output .= "<table class='SimpleHistoryLogitem__keyValueTable'>";

				foreach ( $arr_plugin_keys as $key => $desc ) {
					
					switch ($key) {

						case "plugin_downloaded":
							$desc_output = esc_attr( number_format_i18n( (int) $context[ $key ] ) );
							break;

						// author is already formatted
						case "plugin_author":
							$desc_output = $context[ $key ];
							break;

						// URL needs a link
						case "plugin_url":
							$desc_output = sprintf('<a href="%1$s">%1$s</a>', esc_attr( $context["plugin_url"] ));
							break;			

						case "plugin_description":
							$desc_output = $plugin_description;
							break;

						default;
							$desc_output = esc_html( $context[ $key ] );
							break;
					}

					$output .= sprintf(
						'
						<tr>
							<td>%1$s</td>
							<td>%2$s</td>
						</tr>
						',
						esc_html($desc),
						$desc_output
					);

				}

				$plugin_slug = ! empty($context["plugin_slug"]) ? $context["plugin_slug"] : "";
				if ( $plugin_slug ) {
				
					$output .= sprintf(
						'
						<tr>
							<td></td>
							<td><a title="%2$s" class="thickbox" href="%1$s">%2$s</a></td>
						</tr>
						',
						admin_url( "plugin-install.php?tab=plugin-information&amp;plugin={$plugin_slug}&amp;section=&amp;TB_iframe=true&amp;width=640&amp;height=550" ),
						esc_html_x("View plugin info", "plugin logger: plugin info thickbox title view all info", "simple-history")
					);

				}

				$output .= "</table>";

			}

		} elseif ( "plugin_bulk_updated" === $message_key || "plugin_updated" === $message_key || "plugin_activated" === $message_key || "plugin_deactivated" === $message_key ) {

			$plugin_slug = !empty($context["plugin_slug"]) ? $context["plugin_slug"] : "";

			if ($plugin_slug) {
	
				$link_title = esc_html_x("View plugin info", "plugin logger: plugin info thickbox title", "simple-history");
				$url = admin_url( "plugin-install.php?tab=plugin-information&amp;plugin={$plugin_slug}&amp;section=&amp;TB_iframe=true&amp;width=640&amp;height=550" );
				
				if ( "plugin_updated" == $message_key || "plugin_bulk_updated" == $message_key ) {
					$link_title = esc_html_x("View changelog", "plugin logger: plugin info thickbox title", "simple-history");
					$url = admin_url( "plugin-install.php?tab=plugin-information&amp;plugin={$plugin_slug}&amp;section=changelog&amp;TB_iframe=true&amp;width=772&amp;height=550" );
				}
				
				$output .= sprintf(
					'<p><a title="%2$s" class="thickbox" href="%1$s">%2$s</a></p>',
					$url,
					$link_title	
				);

			}

		} // if plugin_updated

		return $output;

	}


}
