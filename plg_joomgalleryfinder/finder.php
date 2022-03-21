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
use  Joomla\CMS\Factory;
use  Joomla\CMS\Session\Session;
use  Joomla\CMS\Uri\Uri;
use  Joomla\CMS\Router\Router;

/**
 * Smart Search JoomGallery Plugin.
 *
 * @since  2.5
 */
class PlgJoomgalleryFinder extends JPlugin
{
	/**
   * Name of this search engine
   *
   * @var   string
   */
  protected $title = 'SmartSearch';

	/**
	 * The type of content that the smart search adapter indexes.
	 *
	 * @var    string
	 */
	protected $type_title = 'Image (JoomGallery)';

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

	/**
	 * Smart Search integration method.
	 * Method is called after a search string is submitted using the search form.
	 *
	 * @param   string  $searchstring  The search string entered in the search field
	 * @param   array   $aliases       Possible aliases for the SQL statements
	 * @param   string  $context       Context. Which search engine to be used
	 *
	 * @return  array   lsit with SQL statements: array('images.select','images.leftjoin','images.where','images.where.or')
	 *
	 * @since   3.6.0
	 */
	public function onJoomSearch($searchstring, $aliases, $context = '')
	{
		if(in_array($context, array('com_joomgallery.'.$this->title)))
		{
			$db = Factory::getDbo();
			$query = $db
					->getQuery(true)
					->select('id')
					->from($db->quoteName('#__finder_taxonomy'))
					->where($db->quoteName('title') . " = " . $db->quote($this->type_title));

			$db->setQuery($query);
			$taxonomy = $db->loadResult();

			if(is_null($taxonomy) || $taxonomy <= 0)
			{
				Factory::getApplication()->enqueueMessage('Taxonomy not found. Make sure JoomFinderPlugin is installed, enabled and the first index was run.', 'error');

				return array();
			}

			// perform search using com_finder
			[$httpcode, $sxml] = $this->getSearchResult(intval($taxonomy), $searchstring);
			if($httpcode != 200)
			{
				Factory::getApplication()->enqueueMessage('Something went wrong when fetching search results.', 'error');

				return array();
			}
			$xml = new SimpleXMLElement($sxml);

			// create array of id's
			$ids = array();
			foreach ($xml->channel->item as $key => $image)
			{
				$id = $this->getIDfromUrl((string) $image->link);

				if ($id !== false)
				{
					array_push($ids,$id);
				}
			}

			if(count($ids) >= 1)
			{
				$where = '(';
				foreach ($ids as $key => $id)
				{
					if($key !== 0)
					{
						$where .= ', ';
					}

					$where .= strval($id);
				}
				$where .= ')';

				return array('searchengines' => array($this->title), 'images.where' => $aliases['images'].'.id IN '.$where);
			}
			else
			{
				return array('searchengines' => array($this->title), 'images.where' => $aliases['images'].'.id = 0');
			}
		}
	}

	/**
	 * Add Smart Search to JoomGallery configuration.
	 * Method is called when set up the config manager view.
	 *
	 * @return  string  The name of the search engine
	 *
	 * @since   3.6.0
	 */
	public function onJoomSearchEngineGetName()
	{
		return $this->title;
	}

	/**
	 * Request XML of Smart Search results.
	 *
	 * @param   integer  $taxonomy       ID of the Image (JoomGallery) taxonomy of com_finder
	 * @param   string   $searchstring   The search string
	 *
	 * @return  array    ('code','response')
	 *
	 * @since   3.6.0
	 */
	protected function getSearchResult($taxonomy, $searchstring)
	{
		$taxonomy = intval($taxonomy);

		$post_data = array(
				Session::getFormToken() => '1',
				'return' => Session::getFormToken(),
				'q' => strval($searchstring),
				't[]' => strval($taxonomy),
        'limit' => '1000'
		);
		$session = Factory::getSession();
		$url     = Uri::root().'index.php?option=com_finder&view=search&format=feed';

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		curl_setopt($ch, CURLOPT_USERAGENT, 'JoomlaDirect');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Cookie: '.$session->getName().'='.$session->getId()));
		$response = curl_exec($ch);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

    return array($httpcode,$response);
	}

	/**
	 * Extract id out of an joomla url
	 *
	 * @param   string    $url   The url string
	 * @return  integer   id on success, false otherwise
	 *
	 * @since   3.6.0
	 */
	protected function getIDfromUrl($url)
	{
		// Create the JUri object 
    $uri    = Uri::getInstance($url);

    // Parse the given url
    $router = Router::getInstance('site');
    $vars   = $router->parse($uri);

		return intval($vars['id']);
	}
}
