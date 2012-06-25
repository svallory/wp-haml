<?php
/*
Plugin Name: WP-HamlPHP
Plugin URI: http://github.com/hamlphp/wp-hamlphp
Description: Allows you to write Wordpress themes using HAML
Author: Saulo Vallory
Version: 1.0
Author URI: http://saulovallory.com

   This plugin allows you to write your Wordpress theme templates using HAML instead of a mish-mash of HTML and PHP.
   
   It overrides Wordpress's template loader and uses <a href="http://wphaml.sourceforge.net/">wphaml</a> to parse the HAML
   and emit the results.
   
   See the README in the plugin directory for more information.
   
*/
/*
 * Template handling
 */
require_once dirname(__FILE__) . '/hamlphp/src/HamlPHP/HamlPHP.php';
require_once dirname(__FILE__) . '/hamlphp/src/HamlPHP/Storage/FileStorage.php';
require_once dirname(__FILE__) . "/shortcut-functions.php";

/*
 * Setup and teardown
 */
register_activation_hook(__FILE__, array(
	'WPHamlPHP' , 'activate'
));
register_deactivation_hook(__FILE__, array(
	'WPHamlPHP' , 'deactivate'
));

/*
 * Intercepts template includes using our new filter and looks for a HAML alternative.
 */
add_filter('template_include', array(
	'WPHamlPHP' , 'templateInclude'
));

add_action('template_redirect', array(
	'WPHamlPHP' , 'templateRedirect'
));
	
/*
 * Config
 */
define('WPHAMLPHP_CACHE_DIR', WP_CONTENT_DIR . '/haml-cache/');

class FilePathIdGen //implements IdGeneratorInterface
{
	public function generate($filepath)
	{
		$template_dir = str_replace(array(':','/','\\'), '_', trim(get_theme_root(), '/\\'));
		$filepath = str_replace(array(':','/','\\'), '_', ltrim($filepath, '/\\'));
		
		$id = trim(str_replace($template_dir, '', $filepath), '_');
		
		return $id;
	}	
}

class WPHamlPHP
{
	private static $layout = false;
	
	private static $named_content = array();

	private static $template_hierarchy = null;
	
	/**
	 * @var FileStorage
	 */
	private static $storage;
	
	public static function activate()
	{
		if (! file_exists(WPHAMLPHP_CACHE_DIR) && ! mkdir(WPHAMLPHP_CACHE_DIR))
		{
			add_action('admin_notices', array(
				'WPHamlPHP' , 'cacheDirWarning'
			));
		}
	}

	public static function deactivate()
	{}

	public static function cacheDirWarning()
	{
		echo "<div class='updated fade'><p>To use WP-HamlPHP you need to create a <em>haml-cache</em> dir at " . WP_CONTENT_DIR . " and make it writeable</p></div>";
	}
	
	public static function getStorage()
	{
		if(!isset(self::$storage))
			self::$storage = new FileStorage(WPHAMLPHP_CACHE_DIR);
			
		return self::$storage;
	}
		
	public static function getTemplateModule( $module ) {
	
	  $template_hierarchy = self::getTemplateHierarchy();
	
	  if ( empty( $template_hierarchy ) )
	    return '';
	
	  $templates = array();
	  
	  foreach( $template_hierarchy as $template ) {
	    $templates[] = $module . '/' . $template;
	  }
	  
	  $templates[] = "$module.haml";
	  $templates[] = "$module.php";
	
	  $located = locate_template($templates, false, false);
	  
		if ( $template = apply_filters( 'template_include', $template ) )
			include( $template );
	}

	public static function templateRedirect()
	{
		// Process feeds and trackbacks even if not using themes.
		if ( is_robots() ) :
			do_action('do_robots');
			return;
		elseif ( is_feed() ) :
			do_feed();
			return;
		elseif ( is_trackback() ) :
			include( ABSPATH . 'wp-trackback.php' );
			return;
		endif;

		if ( defined('WP_USE_THEMES') && WP_USE_THEMES ) :
			$template = false;
			$templates = self::getTemplateHierarchy();
			
			$template = locate_template($templates, false, false);
			
			if ( $template = apply_filters( 'template_include', $template ) )
				include( $template );
			exit;
		endif;
	}
	
	public static function templateInclude($template_file)
	{
		// Globalise the Wordpress environment
		global $posts, $post, $wp_did_header, $wp_did_template_redirect, $wp_query, $wp_rewrite, $wpdb, $wp_version, $wp, $id, $comment, $user_ID;
	  
		if('.haml' == substr($template_file, -5))
		{			
			// Execute the template and save its output
			self::$named_content[''] = self::getParsedResult($template_file);
			
			if (self::$layout)
			{
				// Execute the layout and display everything
				$layout_file = get_template_directory() . '/layouts/' . self::$layout;
				
				if (file_exists($layout_file))
					echo self::getParsedResult($layout_file);
				else
					echo self::getParsedResult(get_template_directory() . '/' . self::$layout);
			}
			
			echo self::$named_content[''];
			return null;
		}
		
		return $template_file;
	}

	public static function getParsedResult($template)
	{
		$parser = new HamlPHP(self::getStorage());
		//$parser->setIdGenerator(new FilePathIdGen());
		
		$content = $parser->parseFile($template, array());
		
		return $content;
	}

	public static function addNamedContent($name, $content)
	{
		self::$named_content[$name] = $content;
	}
	
	public static function getContentFor($name)
	{
		if (isset(self::$named_content[$name]))
			return self::$named_content[$name];
		
		return null;
	}

	public static function setLayout($layout_name)
	{
		self::$layout = $layout_name;
	}

	private static function getTemplateHierarchy()
	{
		if (defined('WP_USE_THEMES') && WP_USE_THEMES)
		{
			if(isset(self::$template_hierarchy))
				return self::$template_hierarchy;
			
			$templates = array();
			
			if (is_404())
			{
				// see get_404_template()
				$templates[] = '404.php';
			}
			
			if (is_search())
			{
				// see get_search_template()
				$templates[] = 'search.php';
			}
			
			if (is_tax())
			{
				// see get_taxonomy_template()
				$term = get_queried_object();
				$taxonomy = $term->taxonomy;
				
				$templates[] = "taxonomy-$taxonomy-{$term->slug}.php";
				$templates[] = "taxonomy-$taxonomy.php";
				$templates[] = "taxonomy.php";
			}
			
			if (is_front_page())
			{
				// see get_front_page_template()
				$templates[] = 'front-page.php';
			}
			
			if (is_home())
			{
				// see get_home_template()
				$templates[] = 'home.php';
				$templates[] = 'index.php';
			}
			
			if (is_attachment())
			{
				// see get_attachment_template()
				global $posts;
				$type = explode('/', $posts[0]->post_mime_type);
				$templates[] = "{$type[0]}.php";
				$templates[] = "{$type[1]}.php";
				$templates[] = "{$type[0]}_{$type[1]}.php";
				$templates[] = 'attachment.php';
			}
			
			if (is_single())
			{
				// see get_single_template()
				$object = get_queried_object();
				$templates[] = "single-{$object->post_type}.php";
				$templates[] = 'single.php';
			}
			
			if (is_page())
			{
				// see get_page_template()
				$id = get_queried_object_id();
				$template = get_post_meta($id, '_wp_page_template', true);
				$pagename = get_query_var('pagename');
				
				if (! $pagename && $id > 0)
				{
					// If a static page is set as the front page, $pagename will not be set. Retrieve it from the queried object
					$post = get_queried_object();
					$pagename = $post->post_name;
				}
				
				if ('default' == $template)
					$template = '';
				
				if (! empty($template) && ! validate_file($template))
					$templates[] = $template;
				if ($pagename)
					$templates[] = "page-$pagename.php";
				if ($id)
					$templates[] = "page-$id.php";
				$templates[] = "page.php";
			}
			
			if (is_category())
			{
				// see get_category_template()
				$category = get_queried_object();
				
				$templates[] = "category-{$category->slug}.php";
				$templates[] = "category-{$category->term_id}.php";
				$templates[] = "category.php";
			}
			
			if (is_tag())
			{
				// see get_tag_template()
				$tag = get_queried_object();
				
				$templates[] = "tag-{$tag->slug}.php";
				$templates[] = "tag-{$ctag->term_id}.php";
				$templates[] = "tag.php";
			}
			
			if (is_author())
			{
				// see get_author_template()
				$author = get_queried_object();
				
				$templates[] = "author-{$author->user_nicename}.php";
				$templates[] = "author-{$author->ID}.php";
				$templates[] = 'author.php';
			}
			
			if (is_date())
			{
				// see get_date_template()
				$templates[] = 'date.php';
			}
			
			if (is_archive())
			{
				// see get_archive_template()
				$post_type = get_query_var('post_type');
				
				if ($post_type)
					$templates[] = "archive-{$post_type}.php";
				$templates[] = 'archive.php';
			}
			
			if (is_comments_popup())
			{
				// see get_comments_popup_template()
				$templates[] = 'comments-popup.php';
			}
			
			if (is_paged())
			{
				// see get_paged_template()
				$templates[] = 'paged.php';
			}
			
			$templates[] = 'index.php';
				
			$haml = array();
			foreach ($templates as $template)
			{
				$haml[] = str_replace('.php', '.haml', $template);
				$haml[] = $template;
			}
			
			self::$template_hierarchy = $haml;
			return self::$template_hierarchy;
		}
		
		return null;
	}
}