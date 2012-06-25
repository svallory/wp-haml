<?php

/**
 * Create haml alternatives for the get_* functions
 */
function use_layout($name)
{
	WPHamlPHP::setLayout($name);
}

function render_partial($name, $return = false)
{
	$template_dir = get_template_directory(); 
	$files = array(
		$template_dir . "/partials/_$name.haml",
		$template_dir . "/partials/$name.haml",
		$template_dir . "/$name.haml"
	);
	
	$found = false;
	foreach($files as $partial_file) {
		if(file_exists($partial_file)) {
			$found = true;
			break;
		}
	}
	
	if(!$found)
		throw new Exception("This partial could not be found: <em>$name</em>");
	
	// Execute the template and save its output
	$output = WPHamlPHP::getParsedResult($partial_file);
	
	if ($return)
		return $output;
	
	echo $output;
}

function yield($name = null)
{
	echo WPHamlPHP::getContentFor($name);
}

function content_for($name, $content)
{
	WPHamlPHP::addNamedContent($name, $content);
}

/* Vers‹o original. Precisa ser adaptada */
function get_template_module( $module ) {
	WPHamlPHP::getTemplateModule($module);
}