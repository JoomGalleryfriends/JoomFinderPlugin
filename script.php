<?php
/**
 * @version    3.0.0
 * @package    Pkg_JoomFinderPlugin
 * @author     Manuel Häusler <tech.spuur@quickline.com>
 * @copyright  2022 Manuel Häusler
 * @license    GNU General Public License Version 2 oder später; siehe LICENSE.txt
 */

// No direct access to this file
defined('_JEXEC') or die('Restricted access');

use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Filesystem\File;
use \Joomla\CMS\MVC\Model\BaseDatabaseModel;
use \Joomla\CMS\Version;

/**
 * Install method
 * is called by the installer of Joomla!
 *
 * @return  void
 * @since   3.0.0
 */
class pkg_joomFinderPluginInstallerScript
{
  /**
   * Release code of the currently installed version
   *
   * @var string
   */
  private $act_code = '';

  /**
   * Release code of the new version to be installed
   *
   * @var string
   */
  private $new_code = '';

  /**
   * This method is called after the package is installed.
   *
   * @param  \stdClass $parent - Parent object calling this method.
   *
   * @return void
   */
  public function install($parent)
  {
    $act_version = explode('.',$this->act_code);
    $new_version = explode('.',$this->new_code);
    $method      = 'install';

    $install_message = $this->getInstallerMSG($act_version, $new_version, $method);

  	?>
    <br />
    <div class="hero-unit">
      <h3><?php echo Text::sprintf('PKG_JOOMFINDERPLUGIN_INSTALL_TXT', $parent->get('manifest')->version);?></h3>
      <br />
      <div class="alert alert-warning">
        <?php if ($install_message != '') : ?>
          <div><?php echo $install_message;?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
  }

  /**
   * This method is called after the package is uninstalled.
   *
   * @param  \stdClass $parent - Parent object calling this method.
   *
   * @return void
   */
  public function uninstall($parent)
  {

  }

  /**
   * This method is called after a component is updated.
   *
   * @param  \stdClass $parent - Parent object calling object.
   *
   * @return void
   */
  public function update($parent)
  {
    $act_version = explode('.',$this->act_code);
    $new_version = explode('.',$this->new_code);
    $method      = 'update';

    $update_message = $this->getInstallerMSG($act_version, $new_version, $method);

    ?>
    <br />
    <div class="hero-unit">
      <h3><?php echo Text::sprintf('PKG_JOOMFINDERPLUGIN_UPDATE_TXT', $parent->get('manifest')->version);?></h3>
      <br />
      <div class="alert alert-warning">
        <?php if ($update_message != '') : ?>
          <div><?php echo $update_message;?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
  }

  /**
   * Runs just before any installation action is performed on the component.
   * Verifications and pre-requisites should run in this function.
   *
   * @param  string    $type   - Type of PreFlight action. Possible values are:
   *                           - * install
   *                           - * update
   *                           - * discover_install
   * @param  \stdClass $parent - Parent object calling object.
   *
   * @return void
   */
  public function preflight($type, $parent)
  {
    if ($type == 'update')
    {
      // save version information (actual version, new version) to object
      //-----------------------------------------------------------------
      $path1 = JPATH_PLUGINS.DIRECTORY_SEPARATOR."joomgallery".DIRECTORY_SEPARATOR."finder".DIRECTORY_SEPARATOR."finder.xml";
      $path2 = JPATH_PLUGINS.DIRECTORY_SEPARATOR."finder".DIRECTORY_SEPARATOR."joomgallery".DIRECTORY_SEPARATOR."joomgallery.xml";
      $path3 = JPATH_PLUGINS.DIRECTORY_SEPARATOR."system".DIRECTORY_SEPARATOR."jgfinder".DIRECTORY_SEPARATOR."jgfinder.xml";
      if(File::exists($path1) && File::exists($path2) && File::exists($path3))
      {
        $xml1 = simplexml_load_file($path1);
        $xml2 = simplexml_load_file($path2);
        $xml3 = simplexml_load_file($path3);

        $v1 = (string )$xml1->version;
        $v2 = (string )$xml2->version;
        $v3 = (string )$xml3->version;

        if($v1 != $v2 || $v1 != $v3)
        {
          Factory::getApplication()->enqueueMessage('Plugins dont have the same version number!','error');
        }

        $this->act_code    = $xml1->version;
        $this->new_code    = $parent->get('manifest')->version;
      }
      else
      {
        Factory::getApplication()->enqueueMessage('At least one plugin not available!','error');
      }
    }
  }

  /**
   * Runs right after any installation action is performed on the component.
   *
   * @param  string    $type   - Type of PostFlight action. Possible values are:
   *                           - * install
   *                           - * update
   *                           - * discover_install
   * @param  \stdClass $parent - Parent object calling object.
   *
   * @return void
   */
  function postflight($type, $parent)
  {
    if($parent->get('manifest')->autoactivate == true)
    {
      // check, if plugins are installed and enabled
      $this->enablePlugin('plg_joomgallery_finder','finder','joomgallery');
      $this->enablePlugin('plg_finder_joomgallery','joomgallery','finder');
      $this->enablePlugin('plg_system_jgfinder','jgfinder','system');
    }
  }

  /**
   * Enables a specific plugin.
   *
   * @param  string  $name     Plugin name (according to database)
   * @param  string  $element  Plugin element (according to database)
   * @param  string  $folder   Plugin folder (according to database)
   *
   * @return void
   */
  private function enablePlugin($name,$element,$folder)
  {
    $db = Factory::getDbo();
    $query = $db->getQuery(true)
                ->select(array('extension_id','enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('name').' = '.$db->quote($name))
                ->where($db->quoteName('type').' = '.$db->quote('plugin'))
                ->where($db->quoteName('element').' = '.$db->quote($element))
                ->where($db->quoteName('folder').' = '.$db->quote($folder));
    // Prepare the query
    $db->setQuery($query);
    // Load the row.
    $plugin = $db->loadAssocList();

    // enable plugin if it is installed
    if (!empty($plugin))
    {
      $query = $db->getQuery(true);

      // Fields to update.
      $fields = array($db->quoteName('enabled') . ' = 1');

      // Conditions for which records should be updated.
      $conditions = array(
          $db->quoteName('name').' = '.$db->quote($name),
          $db->quoteName('type').' = '.$db->quote('plugin'),
          $db->quoteName('element').' = '.$db->quote($element),
          $db->quoteName('folder').' = '.$db->quote($folder)
      );

      $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
      $db->setQuery($query);
      $db->execute();
    }
  }

  /**
   * Uninstalls a specific plugin.
   *
   * @param  string  $name     Plugin name (according to database)
   * @param  string  $element  Plugin element (according to database)
   * @param  string  $folder   Plugin folder (according to database)
   *
   * @return void
   */
  private function uninstallPlugin($name,$element,$folder)
  {
    $db = Factory::getDbo();
    $query = $db->getQuery(true)
                ->select('extension_id')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('name').' = '.$db->quote($name))
                ->where($db->quoteName('type').' = '.$db->quote('plugin'))
                ->where($db->quoteName('element').' = '.$db->quote($element))
                ->where($db->quoteName('folder').' = '.$db->quote($folder));
    // Prepare the query
    $db->setQuery($query);
    // Load the row.
    $plugin_id = $db->loadResult();

    // deinstall plugin if it is installed
    if (!empty($plugin_id))
    {
      jimport('joomla.application.component.model');
      BaseDatabaseModel::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_installer/models');
      JLoader::register('InstallerModelManage', JPATH_ADMINISTRATOR . '/components/com_installer/models/manage.php');
      JLoader::register('InstallerModel', JPATH_ADMINISTRATOR . '/components/com_installer/models/extension.php');
      $model = BaseDatabaseModel::getInstance('Manage', 'InstallerModel', array());

      $model->remove(array($plugin_id));
    }
  }

  /**
   * Generates post installer messages.
   *
   * @param  array   $act_version     Array with the currently installled version code
   * @param  array   $new_version     Array with the version code the package will be updated to
   * @param  string  $methode         install, uninstall, update
   *
   * @return string html string of the message
   */
  private function getInstallerMSG($act_version, $new_version, $methode)
  {
    $msg = '';

    // joomla version compatibility check
    $jversion  = new Version;
    $joomla_version = $jversion->getLongVersion();

    if ($joomla_version === '3.9.27')
    {
      $msg .= '<h4 class="alert-heading">'.Text::_('PKG_JOOMFINDERPLUGIN_UPDATE_MESSAGE_TITLE_JOOMLA_BUG').'</h3>';
      $msg .= '<div class="alert-massage" style="font-size: small;">'.Text::sprintf('PKG_JOOMFINDERPLUGIN_UPDATE_MESSAGE_JOOMLA_BUG', $joomla_version).'</div>';
      $msg .= '<br />';
    }

    // install of pre release version
    if (($new_version[0] == 0) && ($methode == 'update' || $methode == 'install'))
    {
      $msg .= '<h4 class="alert-heading">'.Text::_('PKG_JOOMFINDERPLUGIN_UPDATE_MESSAGE_TITLE_BETA').'</h3>';
      $msg .= '<div class="alert-massage" style="font-size: small;">'.Text::_('PKG_JOOMFINDERPLUGIN_UPDATE_MESSAGE_BETA').'</div>';
    }

    return $msg;
  }
}
