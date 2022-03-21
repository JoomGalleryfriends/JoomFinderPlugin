<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  JoomGallery.JGFinder
 *
 * @copyright   Copyright (C) 2005 - 2022 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use  Joomla\CMS\Plugin\PluginHelper;
use  Joomla\CMS\Factory;

/**
 * Smart Search JoomGallery Plugin.
 *
 * @since  2.5
 */
class PlgSystemJGFinder extends JPlugin
{
	public function onAfterRoute() 
  { 
    $app    = Factory::getApplication();

    $option = $app->input->getCmd('option');
    $view   = $app->input->getCmd('view');
    $format = $app->input->get('format', 'html', 'cmd');
    $limit  = $app->input->get('limit', '', 'cmd');
    
    if($app->isClient('site') && $option == 'com_finder' && $view == 'search' && $format == 'feed' && $limit != '')
    {
      $app->set('feed_limit', $limit);
    }
  }
}
