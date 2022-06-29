<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.JoomGallery
 *
 * @copyright   Copyright (C) 2005 - 2022 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Table\Table;

JLoader::register('FinderIndexerAdapter', JPATH_ADMINISTRATOR . '/components/com_finder/helpers/indexer/adapter.php');

/**
 * Smart Search adapter for com_joomgallery.
 *
 * @since  3.0.0
 */
class PlgFinderJoomgallery extends FinderIndexerAdapter
{
	/**
	 * The plugin identifier.
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	protected $context = 'joomgallery';

	/**
	 * The extension name.
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	protected $extension = 'com_joomgallery';

	/**
	 * The sublayout to use when rendering the results.
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	protected $layout = 'joomgallery';

	/**
	 * The type of content that the adapter indexes.
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	protected $type_title = 'Image (JoomGallery)';

	/**
	 * The table name.
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	protected $table = '#__joomgallery';

	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  3.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Item type that is currently performed
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	protected $item_type = 'com_joomgallery.image';

	/**
	 * Temporary item state.
	 *
	 * @var    array
	 * @since  3.0.0
	 */
	protected $tmp_state = array('state'=>null,'access'=>null);

	/**
	 * Temporary storage.
	 *
	 * @var    mixed
	 * @since  3.0.0
	 */
	protected $tmp = null;

	/**
	 * Method to remove the link information for items that have been deleted.
	 * Event is triggered at the same time as the onContentAfterDelete event
	 *
	 * @param   string  $context  The context of the action being performed.
	 * @param   JTable  $table    A JTable object containing the record to be deleted
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   3.0.0
	 * @throws  Exception on database error.
	 */
	public function onFinderAfterDelete($context, $table)
	{
		if ($context === 'com_joomgallery.image')
		{
			//get image id
			$ids = array($table->id);
		}
		elseif ($context === 'com_joomgallery.category')
		{
			// get image ids from category
			$query = clone $this->getStateQuery();
			$query->where('c.cid = ' . (int) $table->cid);
			$this->db->setQuery($query);
			$items = $this->db->loadObjectList();

			$ids = array();
			foreach ($items as $item)
			{
				array_push($ids, $item->id);
			}
		}
		elseif ($context === 'com_finder.index')
		{
			// get item id
			$ids = array($table->link_id);
		}
		else
		{
			return true;
		}

		foreach ($ids as $id)
		{
			// Remove item from the index.
			return $this->remove($id);
		}
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
	 * @since   3.0.0
	 * @throws  Exception on database error.
	 */
	public function onFinderAfterSave($context, $row, $isNew)
	{
		// We only want to handle joomgallery images here.
		if ($context === 'com_joomgallery.image' || $context === 'com_joomgallery.image.quick' || $context === 'com_joomgallery.image.batch')
		{
			// Save the item type
			$this->item_type = 'com_joomgallery.image';

			// Check if the access levels are different.
			if (!$isNew && $this->old_access != $row->access)
			{
				// Process the change.
				$this->itemAccessChange($row);
			}

			// Check if published or hidden or approved changed
			if (!$isNew && ($this->old_published != $row->published || $this->old_hidden != $row->hidden || $this->old_approved != $row->approved))
			{
				if($this->old_approved != $row->approved)
				{
					// approved has changed
					if($row->approved != 1)
					{
						// not approved anymore
						$value = 3;
					}
					else
					{
						// approved now
						$value = 4;
					}
				}
				else
				{
					if($row->published == 0 || $row->hidden != 0)
					{
						// threat the image as if its state has changed to unpublished
						$value = 0;
					}
					else
					{
						// threat the image as if its state has changed to published
						$value = 1;
					}
				}

				// Process the change.
				$this->itemStateChange(array($row->id), $value, false);
			}

			// Reindex the item.
			$this->reindex($row->id);
		}

		// We only want to handle joomgallery categories here.
		if($context === 'com_joomgallery.category')
		{
			// Save the item type
			$this->item_type = 'com_joomgallery.category';

			// Check if the access levels are different.
			if (!$isNew && $this->old_cataccess != $row->access)
			{
				$this->categoryAccessChange($row);
			}

			// Check if published, hidden, in_hidden or hidden from search changed
			if (!$isNew && ($this->old_catpublished != $row->published || $this->old_cathidden != $row->hidden || $this->old_catinhidden != $row->in_hidden || $this->old_catexclude != $row->exclude_search))
			{
				if($row->published == 0 || $row->hidden != 0 || $row->in_hidden != 0 || $row->exclude_search != 0)
				{
					// threat the category as if its state has changed to unpublished
					$value = 0;
				}
				else
				{
					// threat the category as if its state has changed to published
					$value = 1;
				}

				// Process the change.
				$this->categoryStateChange(array($row->cid), $value, false);
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
	 * @since   3.0.0
	 * @throws  Exception on database error.
	 */
	public function onFinderBeforeSave($context, $row, $isNew)
	{
		// We only want to handle joomgallery images here.
		if ($context === 'com_joomgallery.image' || $context === 'com_joomgallery.image.quick' || $context === 'com_joomgallery.image.batch')
		{
			// Save the item type
			$this->item_type = 'com_joomgallery.image';

			// Query the database for the old access level if the item isn't new.
			if (!$isNew)
			{
				$this->checkItemState($row);
			}
		}

		// Check for access levels from the category.
		if(in_array($context, array('com_joomgallery.category')))
		{
			// Save the item type
			$this->item_type = 'com_joomgallery.category';

			// Query the database for the old access level if the item isn't new.
			if (!$isNew)
			{
				$this->checkCategoryState($row);
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
	 * @param   integer  $value    0: unpublished / 1: published / 2: archived / 3: not approved / 4: approved
	 * 														 5: not featured / 6: featured
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	public function onFinderChangeState($context, $pks, $value)
	{
		$value = intval($value);

		// We only want to handle joomgallery images that get changed in the publishing state.
		if ($context === 'com_joomgallery.image' && $value >= 0)
		{
			// Save the item type
			$this->item_type = 'com_joomgallery.image';

			$this->itemStateChange($pks, $value);
		}

		// Handle when the plugin is disabled.
		if ($context === 'com_plugins.plugin' && $value === 0)
		{
			$this->pluginDisable($pks);
		}
	}

	/**
	 * Method to update the item link information when the item category is
	 * changed. This is fired when the item category is published or unpublished
	 * from the list view.
	 * Event is triggered at the same time as the onCategoryChangeState event
	 *
	 * @param   string   $extension  The extension whose category has been updated.
	 * @param   array    $pks        A list of primary key ids of the content that has changed state.
	 * @param   integer  $value      0: unpublished / 1: published / 2: archived / 3: not approved / 4: approved
	 * 														   5: not featured / 6: featured
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	public function onFinderCategoryChangeState($extension, $pks, $value)
	{
		$value = intval($value);

		// We only want to handle joomgallery categories that get changed in the publishing state.
		if ($extension === 'com_joomgallery.category' && $value >= 0)
		{
			// Save the item type
			$this->item_type = 'com_joomgallery.category';

			$this->categoryStateChange($pks, $value);
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
	 * @since   3.0.0
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

		// Check tmp state
		if(!is_null($this->tmp_state['state']))
		{
			$item->state = $this->tmp_state['state'];
		}

    // Change category access due to parent categories
    $item->cat_access = $this->getParentCatAccess($item->catid);

		// Check tmp access
		if(!is_null($this->tmp_state['access']))
		{
			$item->access = $this->tmp_state['access'];
		}

    // Translate access
    $item->access = max($item->access, $item->cat_access);

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

		// Translate the state.
		$this->tmp   = $item;
    $this->tmp   = $this->getParentCatStates($this->tmp);
		$item->state = $this->translateState($item->state, $item->cat_state);
		$this->tmp   = null;

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
	 * @since   3.0.0
	 */
	protected function setup()
	{
    // Define JoomGallery constants
    require_once(JPATH_ADMINISTRATOR . '/components/com_joomgallery/includes/defines.php');

    // Include search path
    Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_joomgallery/tables');

		// Load dependent classes.
		JLoader::register('JoomRouting', JPATH_SITE . '/components/com_joomgallery/helpers/routing.php');
    JLoader::register('JoomHelper',  JPATH_ADMINISTRATOR . '/components/com_joomgallery/helpers/helper.php');
    JLoader::register('JoomAmbit',   JPATH_ADMINISTRATOR . '/components/com_joomgallery/helpers/ambit.php');
    JLoader::register('JoomConfig',  JPATH_ADMINISTRATOR . '/components/com_joomgallery/helpers/config.php');

		return true;
	}

	/**
	 * Method to get the SQL query used to retrieve a list of all content items to index.
	 *
	 * @param   mixed  $query  A JDatabaseQuery object or null.
	 *
	 * @return  JDatabaseQuery  A database object.
	 *
	 * @since   3.0.0
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
			->select('c.name AS category, c.published AS cat_state')
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
	 * @since   3.0.0
	 */
	protected function getStateQuery()
	{
		$query = $this->db->getQuery(true);

		// Item ID
		$query->select('a.id');

		// Item and category published state
		$query->select('a.published AS state, c.published AS cat_state');

		// Additional item states
		$query->select('a.hidden AS hidden, a.approved AS approved');

		// Additional category states
		$query->select('c.hidden AS cat_hidden, c.in_hidden AS cat_inhidden, c.exclude_search AS cat_exclude');

		// Item and category access levels
		$query->select('a.access, c.access AS cat_access')
			->from($this->table . ' AS a')
			->join('LEFT', '#__joomgallery_catg AS c ON c.cid = a.catid');

		return $query;
	}

	/**
	 * Method to check the existing states for categories
	 *
	 * @param   JTable  $row  A JTable object
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	protected function checkCategoryState($row)
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('published'))
			->select($this->db->quoteName('hidden'))
			->select($this->db->quoteName('in_hidden'))
			->select($this->db->quoteName('exclude_search'))
			->select($this->db->quoteName('access'))
			->from($this->db->quoteName('#__joomgallery_catg'))
			->where($this->db->quoteName('cid') . ' = ' . (int) $row->cid);
		$this->db->setQuery($query);
		$states = $this->db->loadObject();

		// Store the states to determine if it changes
		$this->old_cataccess    = $states->access;
		$this->old_catpublished = $states->published;
		$this->old_cathidden    = $states->hidden;
		$this->old_catinhidden  = $states->in_hidden;
		$this->old_catexclude   = $states->exclude_search;

	}

	/**
	 * Method to check the existing states for an item
	 *
	 * @param   JTable  $row  A JTable object
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	protected function checkItemState($row)
	{
		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('published'))
			->select($this->db->quoteName('hidden'))
			->select($this->db->quoteName('approved'))
			->select($this->db->quoteName('access'))
			->from($this->db->quoteName('#__joomgallery'))
			->where($this->db->quoteName('id') . ' = ' . (int) $row->id);
		$this->db->setQuery($query);
		$states = $this->db->loadObject();

		// Store the states to determine if it changes
		$this->old_access    = $states->access;
		$this->old_published = $states->published;
		$this->old_approved  = $states->approved;
		$this->old_hidden    = $states->hidden;
	}

	/**
	 * Method to translate the native content states into states that the
	 * indexer can use.
	 *
	 * @param   array    $value     The new item state. (0:umpublished,1:published,2:archived,3:not_approved,4:approved,5:not_featured,6:featured)
	 * @param   integer  $category  The category state. [not used in this plugin]
	 *
	 * @return  integer  The translated indexer state.
	 *
	 * @since   3.0.0
	 */
	protected function translateState($value, $category = null)
	{
		// states before change
		$published      = $this->tmp->state;
		$approved       = $this->tmp->approved;
		$hidden         = $this->tmp->hidden;
		$cat_state      = (isset($this->tmp->cat_state)) ? $this->tmp->cat_state : 1;
		$cat_hidden     = (isset($this->tmp->cat_hidden)) ? $this->tmp->cat_hidden : 0;
		$cat_inhidden   = (isset($this->tmp->cat_inhidden)) ? $this->tmp->cat_inhidden : 0;
		$cat_exclude    = (isset($this->tmp->cat_exclude)) ? $this->tmp->cat_exclude : 0;
    $cat_inexcluded = (isset($this->tmp->cat_inexcluded)) ? $this->tmp->cat_inexcluded : 0;

		if($this->item_type == 'com_joomgallery.image')
		{
			switch($value)
			{
				case 0:
					$published = 0;
					break;
				case 1:
					// break intensionally omitted
				case 2:
					$published = 1;
					break;
				case 3:
					$approved = 0;
					break;
				case 4:
					$approved = 1;
					break;
				default:
					break;
			}
		}

		if($this->item_type == 'com_joomgallery.category')
		{
			switch($value)
			{
				case 0:
					$cat_state = 0;
					break;
				case 1:
					// break intensionally omitted
				case 2:
					$cat_state = 1;
					break;
				case 3:
					break;
				case 4:
					break;
				default:
					break;
			}
		}

		if($published != 1 || $approved != 1 || $hidden != 0 || $cat_state != 1 || $cat_hidden != 0 || $cat_inhidden != 0 || $cat_exclude != 0 || $cat_inexcluded != 0)
		{
			// if one of these states are not set the item to be visible in frontend return 0
			return 0;
		}
		else
		{
			return 1;
		}
	}

	/**
	 * Method to update index data on published state changes
	 *
	 * @param   array    $pks       A list of primary key ids of the content that has changed state.
	 * @param   integer  $value     The new item state. (0:umpublished,1:published,2:archived,3:not_approved,4:approved,5:not_featured,6:featured)
	 * @param   bool     $reindex   ture, if item should be reindexed [optional]
	 *
	 * @return  void
	 *
	 * @since   3.0.0
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

			// Translate the state.
			$this->tmp = $item;
			$indexer_state = $this->translateState($value, $item->cat_state);
			$this->tmp = null;

			// Update the item.
			$this->change($pk, 'state', $indexer_state);
			$this->tmp_state['state'] = $indexer_state;

			if($reindex)
			{
				// Reindex the item
				$this->reindex($pk);
			}

			// reset the tmp values
			$this->tmp_state['state'] = null;
		}
	}

	/**
	 * Method to update index data on category access level changes
	 *
	 * @param   array    $pks       A list of primary key ids of the content that has changed state.
	 * @param   integer  $value     The new item state. (0:umpublished,1:published,2:archived,3:not_approved,4:approved,5:not_featured,6:featured)
	 * @param   bool     $reindex   ture, if item should be reindexed [optional]
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	protected function categoryStateChange($pks, $value, $reindex=true)
	{
		/*
		 * The item's published state is tied to the category
		 * published state so we need to look up all published states
		 * before we change anything.
		 */
		foreach ($pks as $pk)
		{
      // create where array out of all subcategories
      $subcats     = JoomHelper::getAllSubCategories($pk, true, true);
      $where_array = array();
      foreach ($subcats as $catid)
      {
        array_push($where_array, 'c.cid = ' . (int) $catid);
      }

			$query = clone $this->getStateQuery();
			$query->where($where_array, 'OR');

			// Get the published states.
			$this->db->setQuery($query);
			$items = $this->db->loadObjectList();

			// Adjust the state for each item within the category.
			foreach ($items as $item)
			{
				// Translate the state.
				$this->tmp = $item;
				$indexer_state = $this->translateState($value, $item->cat_state);
				$this->tmp = null;

				// Update the item.
				$this->change($item->id, 'state', $indexer_state);
				$this->tmp_state['state'] = $indexer_state;

				if($reindex)
				{
					// Reindex the item
					$this->reindex($item->id);
				}

				// reset the tmp values
				$this->tmp_state['state'] = null;
			}
		}
	}

	/**
	 * Method to update index data on access level changes
	 *
	 * @param   JTable  $row  A JTable object
	 * @param   bool    $reindex   ture, if item should be reindexed [optional]
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	protected function itemAccessChange($row, $reindex=true)
	{
		$query = clone $this->getStateQuery();
		$query->where('a.id = ' . (int) $row->id);

		// Get the access level.
		$this->db->setQuery($query);
		$item = $this->db->loadObject();

		// Set the access level.
		$temp = max($row->access, $item->cat_access);

		// Update the item.
		$this->change((int) $row->id, 'access', $temp);
		$this->tmp_state['access'] = $temp;

		if($reindex)
		{
			// Reindex the item
			$this->reindex($row->id);
		}

		// reset the tmp values
		$this->tmp_state['access'] = null;
	}

	/**
	 * Method to update index data on category access level changes
	 *
	 * @param   JTable  $row  A JTable object
	 * @param   bool    $reindex   ture, if item should be reindexed [optional]
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	protected function categoryAccessChange($row, $reindex=true)
	{
    // create where array out of all subcategories
    $subcats     = JoomHelper::getAllSubCategories($row->cid, true, true);
    $where_array = array();
    foreach ($subcats as $catid)
    {
      array_push($where_array, 'c.cid = ' . (int) $catid);
    }

    $query = clone $this->getStateQuery();
    $query->where($where_array, 'OR');

		// Get the access level.
		$this->db->setQuery($query);
		$items = $this->db->loadObjectList();

		// Adjust the access level for each item within the category.
		foreach ($items as $item)
		{
			// Set the access level.
			$temp = max($item->access, $row->access);

			// Update the item.
			$this->change((int) $item->id, 'access', $temp);
			$this->tmp_state['access'] = $temp;

			if($reindex)
			{
				// Reindex the item
				$this->reindex($item->id);
			}

			// reset the tmp values
			$this->tmp_state['access'] = null;
		}
	}

  /**
	 * Method to update item object with states from parent categories
	 *
	 * @param   JTable  $item  A JTable object
	 *
	 * @return  JTable  extended item object
	 *
	 * @since   3.0.0
	 */
  protected function getParentCatStates($item)
  {
    // get parent cats
    $parent_cats = JoomHelper::getAllParentCategories($item->catid, true);

    $where_array = array();
    foreach ($parent_cats as $cat)
    {
      array_push($where_array, 'cid = ' . $cat->cid);
    }

    // get all states of the parent cats
    $query = $this->db->getQuery(true);
    $query->select($this->db->quoteName(array('published', 'hidden','exclude_search')));
    $query->from($this->db->quoteName('#__joomgallery_catg'));
    $query->where($where_array, 'OR');
    $this->db->setQuery($query);
    $results = $this->db->loadObjectList();

    // add parent states to item object
    foreach ($results as $res)
    {
      if($res->hidden != 0)
      {
        // one of the parent categories is hidden
        $item->cat_inhidden = 1;
      }

      if($res->exclude_search != 0)
      {
        // one of the parent categories is excluded from search
        $item->cat_inexcluded = 1;
      }

      if($res->published < 1)
      {
        // one of the parent categories has a publish state which is not 1 or 2
        $item->cat_state = 0;
      }
    }

    return $item;
  }

  /**
	 * Method to update item object with access from parent categories
	 *
	 * @param   integer  $catid  ID of the child category
	 *
	 * @return  integer  max access value of any parent category
	 *
	 * @since   3.0.0
	 */
  protected function getParentCatAccess($catid)
  {
    // get parent cats
    $parent_cats = JoomHelper::getAllParentCategories($catid, true);

    $where_array = array();
    foreach($parent_cats as $cat)
    {
      array_push($where_array, 'cid = ' . $cat->cid);
    }

    // get all states of the parent cats
    $query = $this->db->getQuery(true);
    $query->select($this->db->quoteName(array('access')));
    $query->from($this->db->quoteName('#__joomgallery_catg'));
    $query->where($where_array, 'OR');
    $this->db->setQuery($query);
    $results = $this->db->loadObjectList();

    // add parent states to item object
    $cat_access = 1;
    foreach ($results as $res)
    {
      $cat_access = max($cat_access, $res->access);
    }

    return $cat_access;
  }
}
