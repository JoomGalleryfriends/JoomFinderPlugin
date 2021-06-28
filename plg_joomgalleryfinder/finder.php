<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  JoomGallery.Finder
 *
 * @copyright   Copyright (C) 2005 - 2021 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use  Joomla\CMS\Plugin\PluginHelper;

/**
 * Smart Search JoomGallery Plugin.
 *
 * @since  2.5
 */
class PlgJoomgalleryFinder extends JPlugin
{
	/**
	 * Smart Search after upload method.
	 * Content is passed by reference, but after the save, so no changes will be saved.
	 * Method is called right after the image is uploaded.
	 *
	 * @param   object  $image  A JTableContent object
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onJoomAfterUpload($image)
	{
		$dispatcher = JEventDispatcher::getInstance();
		PluginHelper::importPlugin('finder');

		$context = 'com_joomgallery.image';
		$isNew   = true;

		// Trigger the onFinderAfterSave event.
		$dispatcher->trigger('onFinderAfterSave', array($context, $image, $isNew));
	}
}
