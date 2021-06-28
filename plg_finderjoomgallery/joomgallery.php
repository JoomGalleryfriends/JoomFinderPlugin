<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.JoomGallery
 *
 * @copyright   Copyright (C) 2005 - 2021 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;

JLoader::register('FinderIndexerAdapter', JPATH_ADMINISTRATOR . '/components/com_finder/helpers/indexer/adapter.php');

/**
 * Smart Search adapter for com_joomgallery.
 *
 * @since  2.5
 */
class PlgFinderJoomgallery extends FinderIndexerAdapter
{
	/**
	 * The plugin identifier.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $context = 'joomgallery';

	/**
	 * The extension name.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $extension = 'com_joomgallery';

	/**
	 * The sublayout to use when rendering the results.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $layout = 'joomgallery';

	/**
	 * The type of content that the adapter indexes.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $type_title = 'Image (JoomGallery)';

	/**
	 * The table name.
	 *
	 * @var    string
	 * @since  2.5
	 */
	protected $table = '#__joomgallery';

	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  3.1
	 */
	protected $autoloadLanguage = true;

	/**
	 * Method to update the item link information when the item category is
	 * changed. This is fired when the item category is published or unpublished
	 * from the list view.
	 * Event is triggered at the same time as the onCategoryChangeState event
	 *
	 * @param   string   $extension  The extension whose category has been updated.
	 * @param   array    $pks        A list of primary key ids of the content that has changed state.
	 * @param   integer  $value      The value of the state that the content has been changed to.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onFinderCategoryChangeState($extension, $pks, $value)
	{
		// Make sure we're handling joomgallery categories.
		if ($extension === 'com_joomgallery')
		{
			$this->categoryStateChange($pks, $value);
		}
	}

	/**
	 * Method to remove the link information for items that have been deleted.
	 * Event is triggered at the same time as the onContentAfterDelete event
	 *
	 * @param   string  $context  The context of the action being performed.
	 * @param   JTable  $table    A JTable object containing the record to be deleted
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function onFinderAfterDelete($context, $table)
	{
		if ($context === 'com_joomgallery.image')
		{
			$id = $table->id;
		}
		elseif ($context === 'com_finder.index')
		{
			$id = $table->link_id;
		}
		else
		{
			return true;
		}

		// Remove item from the index.
		return $this->remove($id);
	}

	/**
	 * Smart Search after save content method.
	 * Reindexes the link information for an article that has been saved.
	 * It also makes adjustments if the access level of an item or the
	 * category to which it belongs has changed.
	 * Event is triggered at the same time as the onContentAfterSave event
	 *
	 * @param   string   $context  The context of the content passed to the plugin.
	 * @param   JTable   $row      A JTable object.
	 * @param   boolean  $isNew    True if the content has just been created.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function onFinderAfterSave($context, $row, $isNew)
	{
		// We only want to handle joomgallery images here.
		if ($context === 'com_joomgallery.image' || $context === 'com_joomgallery.image.quick' || $context === 'com_joomgallery.image.batch')
		{
			// Check if the access levels are different.
			if (!$isNew && $this->old_access != $row->access)
			{
				// Process the change.
				$this->itemAccessChange($row);
			}

			// Check if published, hidden or approved changed
			if (!$isNew && ($this->old_published != $row->published || $this->old_hidden != $row->hidden || $this->old_approved != $row->approved))
			{
				$value = array('task'=>'FinderChangeState', 'state'=> $row->published, 'hidden'=> $row->hidden, 'approved'=>$row->approved);

				// Process the change.
				$this->itemStateChange(array($row->id), $value, false);
			}

			// Reindex the item.
			$this->reindex($row->id);
		}

		// Check for access changes in the category.
		if(in_array($context, array('com_joomgallery.category')))
		{
			// Check if the access levels are different.
			if (!$isNew && $this->old_cataccess != $row->access)
			{
				$this->categoryAccessChange($row);
			}
		}

		return true;
	}

	/**
	 * Smart Search before content save method.
	 * This event is fired before the data is actually saved.
	 * Event is triggered at the same time as the onContentBeforeSave event
	 *
	 * @param   string   $context  The context of the content passed to the plugin.
	 * @param   JTable   $row      A JTable object.
	 * @param   boolean  $isNew    If the content is just about to be created.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	public function onFinderBeforeSave($context, $row, $isNew)
	{
		// We only want to handle joomgallery images here.
		if ($context === 'com_joomgallery.image' || $context === 'com_joomgallery.image.quick' || $context === 'com_joomgallery.image.batch')
		{
			// Query the database for the old access level if the item isn't new.
			if (!$isNew)
			{
				$this->checkItemState($row);
			}
		}

		// Check for access levels from the category.
		if(in_array($context, array('com_joomgallery.category')))
		{
			// Query the database for the old access level if the item isn't new.
			if (!$isNew)
			{
				$this->checkCategoryAccess($row);
			}
		}

		return true;
	}

	/**
	 * Method to update the link information for items that have been changed
	 * from outside the edit screen. This is fired when the item is published,
	 * unpublished, archived, or unarchived from the list view.
	 * Event is triggered at the same time as the onContentChangeState event
	 *
	 * @param   string   $context  The context for the content passed to the plugin.
	 * @param   array    $pks      The joomgallery image object that has changed states.
	 * @param   array    $value    ['publish']: The value of the state that is changed to. ['task']: The state that is chenged
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	public function onFinderChangeState($context, $pks, $value)
	{
		// We only want to handle joomgallery images that get chnged in the publishing state here.
		if ($context === 'com_joomgallery.image' && ($value['task'] === 'publish' || $value['task'] === 'approve'))
		{
			$this->itemStateChange($pks, $value);
		}

		// Handle when the plugin is disabled.
		if ($context === 'com_plugins.plugin' && $value === 0)
		{
			$this->pluginDisable($pks);
		}
	}

	/**
	 * Method to index an item. The item must be a FinderIndexerResult object.
	 *
	 * @param   FinderIndexerResult  $item    The item to index as a FinderIndexerResult object.
	 * @param   string               $format  The item format.  Not used.
	 *
	 * @return  void
	 *
	 * @since   2.5
	 * @throws  Exception on database error.
	 */
	protected function index(FinderIndexerResult $item, $format = 'html')
	{
		$item->setLanguage();

		// Check if the extension is enabled.
		if (ComponentHelper::isEnabled($this->extension) === false)
		{
			return;
		}

		$item->context = 'com_joomgallery';

		// Get the language
		$item->language = '*';

		// Get the dates
		$item->publish_start_date = $item->imgdate;
		unset($item->imgdate);
		$item->publish_end_date = '0000-00-00 00:00:00';

		// Trigger the onContentPrepare event.
		//$item->summary = FinderIndexerHelper::prepareContent($item->summary, $item->params, $item);
		//$item->body    = FinderIndexerHelper::prepareContent($item->body, $item->params, $item);

		// Build the necessary route and path information.
		$item->url = $this->getUrl($item->id, $this->extension, $this->layout);
		$item->route = $this->getUrl($item->id, $this->extension, 'detail');
		$item->path = FinderIndexerHelper::getContentPath($item->route);

		// Add the metadata processing instructions.
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'metakey');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'metadesc');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'owner');
		$item->addInstruction(FinderIndexer::META_CONTEXT, 'author');

		// Translate the state. Articles should only be published if the category is published.
		$item->state = $this->translateState(array('state'=>$item->state, 'hidden'=>$item->hidden, 'approved'=>$item->approved), $item->cat_state);

		// Add the type taxonomy data.
		$item->addTaxonomy('Type', 'Image (JoomGallery)');

		// Add the author taxonomy data.
		if (!empty($item->author))
		{
			$item->addTaxonomy('Author', $item->author);
		}

		// Add the author taxonomy data.
		if (!empty($item->owner))
		{
			$item->addTaxonomy('Owner', $item->owner);
		}

		// Add the category taxonomy data.
		$item->addTaxonomy('Category', $item->category, $item->cat_state, $item->cat_access);

		// Add the language taxonomy data.
		//$item->addTaxonomy('Language', $item->language);

		// Get content extras.
		FinderIndexerHelper::getContentExtras($item);

		//dump($item, 'indexed joomgallery item');

		// Index the item.
		$this->indexer->index($item);
	}

	/**
	 * Method to setup the indexer to be run.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   2.5
	 */
	protected function setup()
	{
		// Load dependent classes.
		JLoader::register('JoomRouting', JPATH_SITE . '/components/com_joomgallery/helpers/routing.php');

		return true;
	}

	/**
	 * Method to get the SQL query used to retrieve a list of all content items to index.
	 *
	 * @param   mixed  $query  A JDatabaseQuery object or null.
	 *
	 * @return  JDatabaseQuery  A database object.
	 *
	 * @since   2.5
	 */
	protected function getListQuery($query = null)
	{
		$db = JFactory::getDbo();

		// Check if we can use the supplied SQL query.
		$query = $query instanceof JDatabaseQuery ? $query : $db->getQuery(true)
			->select('a.id, a.imgtitle AS title, a.alias, a.imgauthor AS author, a.imgtext AS summary')
			->select('a.published AS state, a.catid, a.imgdate')
			->select('a.hidden, a.featured, a.checked_out, a.approved, a.params')
			->select('a.metakey, a.metadesc, a.access, a.ordering')
			->select('c.name AS category, c.published AS cat_state, c.access AS cat_access')
			->select('u.name AS owner')
			->from('#__joomgallery AS a')
			->join('LEFT', '#__joomgallery_catg AS c ON c.cid = a.catid')
			->join('LEFT', '#__users AS u ON u.id = a.owner');

		// echo $query->dump();
		// echo "\n\n";

		return $query;
	}

	/**
	 * Method to get a SQL query to load the published and access states for
	 * an article and category.
	 *
	 * @return  JDatabaseQuery  A database object.
	 *
	 * @since   2.5
	 */
	protected function getStateQuery()
	{
		$query = $this->db->getQuery(true);

		// Item ID
		$query->select('a.id');

		// Item states
		$query->select('a.hidden AS hidden, a.approved AS approved');

		// Item and category published state
		$query->select('a.published AS state, c.published AS cat_state');

		// Item and category access levels
		$query->select('a.access, c.access AS cat_access')
			->from($this->table . ' AS a')
			->join('LEFT', '#__joomgallery_catg AS c ON c.cid = a.catid');

		return $query;
	}

	/**
	 * Method to check the existing access level for categories
	 *
	 * @param   JTable  $row  A JTable object
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	protected function checkCategoryAccess($row)
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('access'))
			->from($this->db->quoteName('#__joomgallery_catg'))
			->where($this->db->quoteName('id') . ' = ' . (int) $row->id);
		$this->db->setQuery($query);

		// Store the access level to determine if it changes
		$this->old_cataccess = $this->db->loadResult();
	}

	/**
	 * Method to check the existing states for an item
	 *
	 * @param   JTable  $row  A JTable object
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	protected function checkItemState($row)
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('published'))
			->select($this->db->quoteName('hidden'))
			->select($this->db->quoteName('approved'))
			->from($this->db->quoteName('#__joomgallery'))
			->where($this->db->quoteName('id') . ' = ' . (int) $row->id);
		$this->db->setQuery($query);
		$states = $this->db->loadObject();

		// Store the states to determine if it changes
		$this->old_access = $states->access;
		$this->old_published = $states->published;
		$this->old_approved = $states->approved;
		$this->old_hidden = $states->hidden;
	}

	/**
	 * Method to translate the native content states into states that the indexer can use.
	 *
	 * $item = array(state, hidden, approved)
	 * state     (1: published / 0: upublished)
	 * hidden    (0: visible / 1: hidden)
	 * approved  (1: approved / -1: not approved)
	 * category  (1: published / 0: upublished)
	 *
	 * @param   array    $item        The item state.
	 * @param   integer  $category    The native category state. [optional]
	 *
	 * @return  integer  The translated indexer state.
	 *
	 * @since   2.5
	 */
	protected function translateState($item, $category = null)
	{
		if ($item['state'] == 0 || $item['hidden'] != 0 || $item['approved'] != 1 || ($category != null && $category != 1))
		{
			// if one of these states are not set the item to be visible in frontend return 0
			return 0;
		}

		// Translate the state
		switch ($item['state'])
		{
			// Published and archived items only should return a published state
			case 1;
			case 2:
				return 1;

			// All other states should return an unpublished state
			default:
			case 0:
				return 0;
		}
	}

	/**
	 * Method to update index data on published state changes
	 *
	 * @param   array    $pks       A list of primary key ids of the content that has changed state.
	 * @param   array    $value     ['publish']: The value of the state that is changed to. ['task']: The state that is chenged
	 * @param   bool     $reindex   ture, if item should be reindexed [optional]
	 *
	 * @return  void
	 *
	 * @since   2.5
	 */
	protected function itemStateChange($pks, $value, $reindex=true)
	{
		/*
		 * The item's published state is tied to the category
		 * published state so we need to look up all published states
		 * before we change anything.
		 */
		foreach ($pks as $pk)
		{
			$query = clone $this->getStateQuery();
			$query->where('a.id = ' . (int) $pk);

			// Get the published states.
			$this->db->setQuery($query);
			$item = $this->db->loadObject();

			switch ($value['task'])
			{
				case 'FinderChangeState':
					$approved = $value['approved'];
					$hidden   = $value['hidden'];
					$state    = $value['state'];
					break;

				case 'approve':
					$approved = $value['publish'];
					$hidden   = $item->hidden;
					$state    = $item->state;
					break;

				case 'publish':
				default:
					$approved = $item->approved;
					$hidden   = $item->hidden;
					$state    = $value['publish'];
					break;
			}

			// Translate the state.
			$temp = $this->translateState(array('state'=>$state, 'hidden'=>$hidden, 'approved'=>$approved), $item->cat_state);

			// Update the item.
			$this->change($pk, 'state', $temp);

			if($reindex)
			{
				// Reindex the item
				$this->reindex($pk);
			}
		}
	}
}
